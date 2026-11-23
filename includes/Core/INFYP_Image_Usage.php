<?php
/**
 * Image usage detection across posts, pages, and page builders.
 *
 * Finds every location where a given attachment is referenced, including
 * post content (Gutenberg blocks, classic editor), shortcode attributes,
 * featured images, page builder meta, and post-parent relationships.
 *
 * @package    IPAIS
 * @subpackage Core
 * @since 1.0.0
 * @author     InfyPress
 * @license    GPL-2.0-or-later
 */

namespace IPAIS\Core;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class INFYP_Image_Usage
 *
 * Responsible for finding where an image is used across the site.
 *
 * @since 1.0.0
 */
class INFYP_Image_Usage {

    /**
     * Finds all posts, pages, etc., where an image is used.
     *
     * @param int $image_id The ID of the image attachment.
     * @return array An array of usage data.
     */
    public static function find_usage($image_id) {
        global $wpdb;

        $image_url = wp_get_attachment_url($image_id);
        if (!$image_url) {
            return [];
        }

        // Try to get cached usage data first
        $cache_key = 'infyp_image_usage_' . $image_id;
        $cached_data = wp_cache_get($cache_key, 'infyp');
        
        if ($cached_data !== false) {
            return $cached_data;
        }

        $post_ids = [];

        // Get various URL formats for this image
        $search_patterns = [];
        
        // Full URL
        $search_patterns[] = $image_url;
        
        // Just the filename (for older content)
        $search_patterns[] = basename($image_url);
        
        // Get attachment metadata for different sizes
        $metadata = wp_get_attachment_metadata($image_id);
        if ($metadata && isset($metadata['file'])) {
            $upload_dir = wp_upload_dir();
            $base_url = $upload_dir['baseurl'];
            
            // Add the base file
            $search_patterns[] = $metadata['file'];
            
            // Add different sizes
            if (isset($metadata['sizes']) && is_array($metadata['sizes'])) {
                $file_dir = dirname($metadata['file']);
                foreach ($metadata['sizes'] as $size => $size_data) {
                    if (isset($size_data['file'])) {
                        // Add both the filename and potential full URL
                        $search_patterns[] = $size_data['file'];
                        $search_patterns[] = $base_url . '/' . $file_dir . '/' . $size_data['file'];
                    }
                }
            }
        }
        
        // Also search for the image ID in Gutenberg blocks (wp:image blocks store the ID)
        $search_patterns[] = '"id":' . $image_id;
        $search_patterns[] = 'wp:image {"id":' . $image_id;
        
        // Remove duplicates
        $search_patterns = array_unique($search_patterns);
        
        // Find in post content using all patterns
        // OPTIMIZATION: Limit results per pattern to prevent runaway queries on large sites
        $content_posts = [];
        $max_results_per_pattern = 100; // Stop searching after finding 100 posts per pattern

        foreach ($search_patterns as $pattern) {
            // Skip very short patterns that would match too many posts
            if (strlen($pattern) < 5) {
                continue;
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Cached after retrieval
            $found_posts = $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_status IN ('publish', 'draft', 'private') AND post_type NOT IN ('attachment', 'revision') AND post_content LIKE %s LIMIT %d",
                '%' . $wpdb->esc_like($pattern) . '%',
                $max_results_per_pattern
            ));
            $content_posts = array_merge($content_posts, $found_posts);

            // Early exit if we found enough posts
            if (count(array_unique($content_posts)) >= 50) {
                break;
            }
        }
        
        // Remove duplicates from content posts
        $content_posts = array_unique($content_posts);
        $post_ids = array_merge($post_ids, $content_posts);

        // Find in shortcode attributes by attachment ID (WPBakery, Uncode, Divi, etc.)
        // Shortcode builders store image references as IDs, not URLs:
        //   [vc_single_image image="123"]  [vc_gallery ids="123,456"]
        //   [uncode_index media="123"]     [et_pb_image image_id="123"]
        $shortcode_posts = [];
        $id_string = (string) $image_id;

        $id_patterns = [
            'image="' . $id_string . '"',       // [vc_single_image image="123"]
            'media="' . $id_string . '"',        // Uncode media attribute
            'img_id="' . $id_string . '"',       // Some themes use img_id
            'images="' . $id_string . '"',       // Single image in images attr
            'default_id="' . $id_string . '"',   // Uncode default_id
            'image_id="' . $id_string . '"',     // Divi image_id attribute
            'ids="' . $id_string . '"',          // Only ID in gallery
            'ids="' . $id_string . ',',          // First in comma list
            ',' . $id_string . ',',              // Middle of comma list
            ',' . $id_string . '"',              // Last in comma list
        ];

        foreach ($id_patterns as $pattern) {
            if (strlen($pattern) < 5) {
                continue;
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Cached after retrieval
            $found = $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_status IN ('publish', 'draft', 'private') AND post_type NOT IN ('attachment', 'revision') AND post_content LIKE %s LIMIT %d",
                '%' . $wpdb->esc_like($pattern) . '%',
                $max_results_per_pattern
            ));
            $shortcode_posts = array_merge($shortcode_posts, $found);

            if (count(array_unique($shortcode_posts)) >= 50) {
                break;
            }
        }

        $shortcode_posts = array_unique($shortcode_posts);
        $post_ids = array_merge($post_ids, $shortcode_posts);

        // Check post_parent relationship (image attached to a post/page during upload)
        $parent_post_id = (int) get_post_field('post_parent', $image_id);
        if ($parent_post_id > 0) {
            $parent = get_post($parent_post_id);
            if ($parent && in_array($parent->post_status, ['publish', 'draft', 'private'], true)) {
                $post_ids[] = $parent_post_id;
            }
        }

        // Find in post meta (for page builders) and detect which builder
        $content_updater = new INFYP_Content_Updater($image_url, ''); // We only need the find method
        $meta_posts = $content_updater->find_usage_in_meta($image_url);
        $post_ids = array_merge($post_ids, $meta_posts);
        
        // Store which page builder is used for each post
        $page_builder_info = self::detect_page_builders($meta_posts, $image_url, $image_id);

        // Find as featured image
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Cached after retrieval
        $featured_image_posts = $wpdb->get_col($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %d",
            $image_id
        ));
        $post_ids = array_merge($post_ids, $featured_image_posts);

        // Allow extensions to find additional post IDs where this image is used
        // (e.g. WooCommerce product galleries)
        $extra_post_ids = apply_filters( 'infyp_image_usage_post_ids', [], $image_id, $image_url );
        $post_ids = array_merge( $post_ids, $extra_post_ids );

        // Allow extensions to add taxonomy-based usage (e.g. WooCommerce product categories)
        $category_usage = apply_filters( 'infyp_image_usage_taxonomy', [], $image_id, $image_url, $metadata );

        $unique_post_ids = array_unique($post_ids);

        $usage_data = [];
        
        // Add category usage first
        $usage_data = array_merge($usage_data, $category_usage);
        
        // Then add post usage
        foreach ($unique_post_ids as $post_id) {
            $post = get_post($post_id);
            if ($post) {
                $post_type = get_post_type($post);
                $post_type_object = get_post_type_object($post_type);
                
                // Safely get the post type label with fallback
                $type_label = $post_type; // Default to post type slug
                if ($post_type_object && isset($post_type_object->labels) && isset($post_type_object->labels->singular_name)) {
                    $type_label = $post_type_object->labels->singular_name;
                }
                
                // Determine how the image is used
                $usage_types = [];

                // Check if it's a featured image
                if (in_array($post_id, $featured_image_posts)) {
                    $usage_types[] = __('Featured Image', 'infy-ai-image-seo-optimizer');
                }
                
                // Check if it's in content (URL match)
                if (in_array($post_id, $content_posts)) {
                    // Check if it's Gutenberg blocks
                    $post_content = get_post_field('post_content', $post_id);
                    if (strpos($post_content, '<!-- wp:') !== false) {
                        $usage_types[] = __('Gutenberg', 'infy-ai-image-seo-optimizer');
                    } else {
                        $usage_types[] = __('In Content', 'infy-ai-image-seo-optimizer');
                    }
                }

                // Check if found via shortcode ID patterns (WPBakery, Uncode, etc.)
                if (in_array($post_id, $shortcode_posts) && !in_array($post_id, $content_posts)) {
                    $post_content = get_post_field('post_content', $post_id);
                    if (strpos($post_content, '[vc_') !== false || strpos($post_content, '[/vc_') !== false) {
                        $usage_types[] = __('WPBakery', 'infy-ai-image-seo-optimizer');
                    } elseif (strpos($post_content, '[uncode_') !== false) {
                        $usage_types[] = __('Uncode', 'infy-ai-image-seo-optimizer');
                    } elseif (strpos($post_content, '[et_pb_') !== false) {
                        $usage_types[] = __('Divi Builder', 'infy-ai-image-seo-optimizer');
                    } else {
                        $usage_types[] = __('In Shortcode', 'infy-ai-image-seo-optimizer');
                    }
                }

                // Check if it's in meta (page builders)
                if (in_array($post_id, $meta_posts)) {
                    // Check which page builder
                    if (isset($page_builder_info[$post_id])) {
                        $usage_types[] = $page_builder_info[$post_id];
                    } else {
                        $usage_types[] = __('Page Builder', 'infy-ai-image-seo-optimizer');
                    }
                }

                // Check if found only via post_parent attachment relationship
                if ($post_id === $parent_post_id && !in_array($post_id, $content_posts)
                    && !in_array($post_id, $shortcode_posts) && !in_array($post_id, $meta_posts)
                    && !in_array($post_id, $featured_image_posts)) {
                    $usage_types[] = __('Attached', 'infy-ai-image-seo-optimizer');
                }

                // Allow extensions to modify usage type labels
                // (e.g. rename "Featured Image" to "Product Featured Image", add "Product Gallery")
                $usage_types = apply_filters( 'infyp_image_usage_types', $usage_types, $post_id, $post_type, $image_id );

                // If no specific usage type found, mark as "Referenced"
                if (empty($usage_types)) {
                    $usage_types[] = __('Referenced', 'infy-ai-image-seo-optimizer');
                }
                
                $usage_data[] = [
                    'id' => $post->ID,
                    'title' => $post->post_title ?: __('(No Title)', 'infy-ai-image-seo-optimizer'),
                    'type' => $post_type, // Return post type slug
                    'type_label' => $type_label,
                    'edit_link' => get_edit_post_link($post->ID, 'raw'),
                    'permalink' => get_permalink($post->ID),
                    'status' => $post->post_status,
                    'usage_types' => $usage_types, // How the image is used
                ];
            }
        }

        // Cache the results for 5 minutes
        wp_cache_set($cache_key, $usage_data, 'infyp', 300);
        
        return $usage_data;
    }
    
    /**
     * Detect which page builder is being used for each post.
     *
     * Only checks builders that have adapters in Core/PageBuilders/.
     * Gutenberg, Visual Composer, and Uncode are detected via post_content
     * and shortcode patterns in find_usage(), so they are not checked here.
     *
     * Use the {@see 'infyp_detect_page_builder'} filter to add detection
     * for additional page builders from extensions or custom code.
     *
     * @param array  $post_ids  Array of post IDs to check.
     * @param string $image_url The image URL.
     * @param int    $image_id  The image ID.
     * @return array Associative array of post_id => builder_name.
     */
    private static function detect_page_builders($post_ids, $image_url, $image_id) {
        $builder_info = [];

        foreach ($post_ids as $post_id) {
            // Check for Elementor
            if (defined('ELEMENTOR_VERSION')) {
                $elementor_data = get_post_meta($post_id, '_elementor_data', true);
                if (!empty($elementor_data) && (strpos($elementor_data, $image_url) !== false || strpos($elementor_data, '"id":' . $image_id) !== false)) {
                    $builder_info[$post_id] = __('Elementor', 'infy-ai-image-seo-optimizer');
                    continue;
                }
            }

            // Check for WPBakery Page Builder (Visual Composer)
            if (defined('WPB_VC_VERSION')) {
                $vc_data = get_post_meta($post_id, '_wpb_vc_js_status', true);
                if ($vc_data === 'true') {
                    $post_content = get_post_field('post_content', $post_id);
                    if (strpos($post_content, $image_url) !== false || strpos($post_content, 'image="' . $image_id) !== false) {
                        $builder_info[$post_id] = __('WPBakery', 'infy-ai-image-seo-optimizer');
                        continue;
                    }
                }
            }

            /**
             * Filter the detected page builder name for a specific post.
             *
             * Extensions adding page builder support can hook here to identify
             * their builder when the image is found in post meta.
             *
             * @since 1.0.0
             * @param string $builder_name  Current builder name (empty if not yet detected).
             * @param int    $post_id       The post ID being checked.
             * @param string $image_url     The image URL being searched for.
             * @param int    $image_id      The image attachment ID.
             */
            $detected = apply_filters( 'infyp_detect_page_builder', '', $post_id, $image_url, $image_id );
            if ( ! empty( $detected ) ) {
                $builder_info[ $post_id ] = $detected;
                continue;
            }

            // Default to generic "Page Builder" if we can't detect specific one
            // but we know it's in meta
            $builder_info[$post_id] = __('Page Builder', 'infy-ai-image-seo-optimizer');
        }

        return $builder_info;
    }
    
    /**
     * Clear cached usage data for an image
     *
     * @param int $image_id The ID of the image attachment
     */
    public static function clear_usage_cache($image_id) {
        $cache_key = 'infyp_image_usage_' . $image_id;
        wp_cache_delete($cache_key, 'infyp');
    }
    
    /**
     * Clear all usage cache
     */
    public static function clear_all_usage_cache() {
        // Clear the entire cache group
        if (function_exists('wp_cache_flush_group')) {
            wp_cache_flush_group('infyp');
        }
    }
}
