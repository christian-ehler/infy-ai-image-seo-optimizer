<?php
/**
 * Gutenberg Block Editor Adapter - WordPress Core Block Editor Support
 *
 * This adapter handles image URL updates within WordPress's Gutenberg block editor
 * content. It uses WordPress core functions (parse_blocks/serialize_blocks) to
 * safely process block-based content while preserving block structure and attributes.
 *
 * The adapter supports:
 * - All core image-related blocks (image, gallery, cover, media-text, etc.)
 * - Reusable blocks (wp_block post type)
 * - Classic editor content (backward compatibility)
 * - Nested blocks and inner blocks
 * - Block attributes and innerHTML
 *
 * Processing Strategy:
 * 1. Query posts with block content (<!-- wp: markers)
 * 2. Parse blocks using WordPress parse_blocks()
 * 3. Recursively traverse block tree
 * 4. Update URLs in block attributes and innerHTML
 * 5. Serialize back using serialize_blocks()
 * 6. Validate structure integrity
 *
 * @package   IPAIS\Core\PageBuilders
 * @since 1.0.0
 * @author    InfyPress
 * @license   GPL-2.0-or-later
 */

namespace IPAIS\Core\PageBuilders;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gutenberg Block Editor Adapter Class
 *
 * Specialized adapter for handling WordPress Gutenberg (Block Editor) content.
 * Extends the base adapter with block-specific processing logic.
 *
 * This adapter is always active (WordPress 5.0+) and processes:
 * - Block attributes (url, src, images arrays)
 * - Block innerHTML (rendered HTML content)
 * - Block innerContent (array of content pieces)
 * - Nested blocks (recursive processing)
 * - Reusable blocks (wp_block post type)
 * - Classic content (non-block posts)
 *
 * Supported Core Blocks:
 * - core/image - Single images
 * - core/gallery - Image galleries
 * - core/cover - Cover/hero images
 * - core/media-text - Media + text layout
 * - core/file - File downloads
 * - core/video - Video embeds
 * - core/site-logo - Site logo block
 *
 * Usage:
 * The adapter is automatically registered by INFYP_PageBuilder_Manager and
 * doesn't require manual initialization.
 *
 * @package   IPAIS\Core\PageBuilders
 * @since 1.0.0
 *
 * @see       INFYP_PageBuilder_Base_Adapter For inherited utilities
 * @see       parse_blocks() WordPress core function for parsing blocks
 * @see       serialize_blocks() WordPress core function for serializing blocks
 */
class INFYP_Gutenberg_Adapter extends INFYP_PageBuilder_Base_Adapter {

    /**
     * Core image-related blocks in Gutenberg
     *
     * List of WordPress core blocks that may contain image URLs requiring
     * updates. Used to apply block-specific processing logic.
     *
     * @since 1.0.0
     * @var   array<string>
     */
    const IMAGE_BLOCKS = [
        'core/image',
        'core/media-text',
        'core/cover',
        'core/gallery',
        'core/file',
        'core/video',
        'core/site-logo'
    ];

    /**
     * Check if Gutenberg is active.
     *
     * @return bool True if Gutenberg is active, false otherwise.
     */
    public function is_active() {
        // Gutenberg is always available in WordPress 5.0+
        return function_exists('parse_blocks') && function_exists('serialize_blocks');
    }

    /**
     * Get the display name of this page builder.
     *
     * @return string The human-readable name of the page builder.
     */
    public function get_name() {
        return 'Gutenberg Block Editor';
    }

    /**
     * Process URL updates for Gutenberg.
     *
     * @param array $url_map Array of old_url => new_url mappings.
     * @return array Array containing 'updated_posts' and 'errors' keys.
     */
    public function process_url_updates($url_map) {
        global $wpdb;

        $this->reset_stats();
        $this->log_info('Starting Gutenberg URL updates', ['url_count' => count($url_map)]);

        // Build optimized query for posts with blocks
        $cache_key = 'infyp_gutenberg_posts_' . md5(serialize($url_map));
        $post_ids = wp_cache_get($cache_key, 'infyp');
        
        if ($post_ids === false) {
            // Get public post types that support editor
            $post_types = get_post_types(['public' => true], 'names');
            $supported_types = [];
            
            foreach ($post_types as $post_type) {
                if (post_type_supports($post_type, 'editor')) {
                    $supported_types[] = $post_type;
                }
            }
            
            if (empty($supported_types)) {
                $this->log_info('No post types support editor');
                return ['updated_posts' => [], 'errors' => []];
            }

            // Find posts containing blocks and URLs
            $url_like_conditions = [];
            foreach ($url_map as $old_url => $new_url) {
                $url_like_conditions[] = "post_content LIKE %s";
            }
            
            // Build the placeholders for post types
            $post_type_placeholders = implode(', ', array_fill(0, count($supported_types), '%s'));
            
            // Build the query with placeholders
            $query_template = "SELECT ID FROM {$wpdb->posts} 
                 WHERE post_content LIKE %s 
                 AND (" . implode(' OR ', $url_like_conditions) . ")
                 AND post_type IN (" . $post_type_placeholders . ")
                 AND post_status IN ('publish', 'draft', 'private', 'future')
                 ORDER BY ID DESC";
            
            // Build query parameters
            $query_params = ['<!-- wp:%'];
            foreach ($url_map as $old_url => $new_url) {
                $query_params[] = '%' . $wpdb->esc_like($old_url) . '%';
            }
            $query_params = array_merge($query_params, $supported_types);
            
            // Prepare the query
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $query_template contains placeholders and is used with prepare()
            $query = $wpdb->prepare($query_template, $query_params);

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is properly prepared above with wpdb->prepare() and cached for performance
            $post_ids = $wpdb->get_col($query);
            wp_cache_set($cache_key, $post_ids, 'infyp', 300);
        }

        if (empty($post_ids)) {
            $this->log_info('No Gutenberg posts found with target URLs');
            $this->finalize_stats();
            return ['updated_posts' => [], 'errors' => []];
        }

        $this->log_info('Found Gutenberg posts', ['count' => count($post_ids)]);

        // Process posts in batches
        $results = $this->process_posts_in_batches(
            $post_ids,
            $url_map,
            [$this, 'process_single_post'],
            30 // Optimized batch size for Gutenberg
        );

        // Process reusable blocks separately
        $reusable_results = $this->process_reusable_blocks($url_map);
        $results['updated_posts'] = array_merge($results['updated_posts'], $reusable_results['updated_posts']);
        $results['errors'] = array_merge($results['errors'], $reusable_results['errors']);

        $this->finalize_stats();
        $this->log_info('Completed Gutenberg URL updates', $this->get_stats());

        return $results;
    }

    /**
     * Process reusable blocks
     *
     * @param array $url_map
     * @return array
     */
    private function process_reusable_blocks($url_map) {
        $results = ['updated_posts' => [], 'errors' => []];
        
        // Get reusable blocks
        $reusable_blocks = get_posts([
            'post_type'      => 'wp_block',
            'posts_per_page' => 500,
            'post_status'    => 'publish',
            'no_found_rows'  => true,
        ]);

        foreach ($reusable_blocks as $block) {
            $result = $this->process_single_post($block->ID, $url_map);
            if ($result['updated']) {
                $results['updated_posts'][] = $block->ID;
            }
            if (!empty($result['errors'])) {
                $results['errors'] = array_merge($results['errors'], $result['errors']);
            }
        }

        return $results;
    }

    /**
     * Process a single post's Gutenberg content.
     *
     * @param int   $post_id The post ID to process.
     * @param array $url_map Array of old_url => new_url mappings.
     * @return array Result with 'updated' and 'errors' keys.
     */
    public function process_single_post($post_id, $url_map) {
        $result = ['updated' => false, 'errors' => []];

        // Skip revisions and autosaves
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return $result;
        }

        try {
            $post = get_post($post_id);
            if (!$post || empty($post->post_content)) {
                return $result;
            }

            $original_content = $post->post_content;
            
            // Quick check if content needs processing
            $needs_processing = false;
            foreach ($url_map as $old_url => $new_url) {
                if (strpos($original_content, $old_url) !== false) {
                    $needs_processing = true;
                    break;
                }
            }

            if (!$needs_processing) {
                return $result;
            }

            // Check if content has blocks
            if (!has_blocks($original_content)) {
                // Handle classic content
                $classic_result = $this->process_classic_content($original_content, $url_map);
                if ($classic_result['replaced_count'] > 0) {
                    $update_data = [
                        'ID' => $post_id,
                        'post_content' => $classic_result['data']
                    ];

                    $update_success = wp_update_post($update_data, true);
                    
                    if (!is_wp_error($update_success)) {
                        $result['updated'] = true;
                        $this->update_stats('posts_updated');
                        $this->update_stats('urls_replaced', $classic_result['replaced_count']);
                    }
                }
                return $result;
            }

            // Process Gutenberg blocks
            $process_result = $this->process_gutenberg_content($original_content, $url_map);
            
            if ($process_result['replaced_count'] > 0) {
                $update_data = [
                    'ID' => $post_id,
                    'post_content' => $process_result['data']
                ];

                // Disable filters to prevent infinite loops
                remove_filter('content_save_pre', 'wp_filter_post_kses');
                remove_filter('content_filtered_save_pre', 'wp_filter_post_kses');

                try {
                    $update_success = wp_update_post($update_data, true);
                } finally {
                    // Always re-enable filters, even if wp_update_post throws
                    add_filter('content_save_pre', 'wp_filter_post_kses');
                    add_filter('content_filtered_save_pre', 'wp_filter_post_kses');
                }
                
                if (!is_wp_error($update_success)) {
                    $result['updated'] = true;
                    $this->update_stats('posts_updated');
                    $this->update_stats('urls_replaced', $process_result['replaced_count']);
                    
                    $this->log_info('Updated Gutenberg post', [
                        'post_id' => $post_id,
                        'replacements' => $process_result['replaced_count']
                    ]);
                } else {
                    $result['errors'][] = sprintf('Failed to update post %d: %s', $post_id, $update_success->get_error_message());
                }
            }

        } catch (\Exception $e) {
            $error = sprintf('Exception processing Gutenberg post %d: %s', $post_id, $e->getMessage());
            $result['errors'][] = $error;
            $this->log_error($error, ['post_id' => $post_id]);
        }

        return $result;
    }

    /**
     * Process classic editor content
     *
     * @param string $content
     * @param array $url_map
     * @return array
     */
    private function process_classic_content($content, $url_map) {
        return $this->safe_url_replace($content, $url_map);
    }

    /**
     * Process Gutenberg content using parse_blocks/serialize_blocks.
     *
     * @param string $content The post content to process.
     * @param array  $url_map Array of old_url => new_url mappings.
     * @return array Result with 'data' and 'replaced_count' keys.
     */
    private function process_gutenberg_content($content, $url_map) {
        $total_replaced = 0;

        try {
            // Parse blocks using WordPress core function
            $blocks = parse_blocks($content);
            
            if (empty($blocks) || !is_array($blocks)) {
                return ['data' => $content, 'replaced_count' => 0];
            }

            // Process blocks recursively
            $process_result = $this->process_blocks($blocks, $url_map);
            $processed_blocks = $process_result['data'];
            $total_replaced = $process_result['replaced_count'];

            // Serialize blocks back to content
            $updated_content = serialize_blocks($processed_blocks);

            return ['data' => $updated_content, 'replaced_count' => $total_replaced];

        } catch (\Exception $e) {
            $this->log_error('Failed to process Gutenberg blocks: ' . $e->getMessage());
            return ['data' => $content, 'replaced_count' => 0];
        }
    }

    /**
     * Process Gutenberg blocks recursively.
     *
     * @param array $blocks  The blocks array.
     * @param array $url_map Array of old_url => new_url mappings.
     * @return array Result with 'data' and 'replaced_count' keys.
     */
    private function process_blocks($blocks, $url_map) {
        $total_replaced = 0;

        if (!is_array($blocks)) {
            return ['data' => $blocks, 'replaced_count' => 0];
        }

        foreach ($blocks as &$block) {
            if (!is_array($block)) {
                continue;
            }

            $block_name = isset($block['blockName']) ? $block['blockName'] : '';

            // Process block attributes
            if (isset($block['attrs']) && is_array($block['attrs'])) {
                $attrs_result = $this->process_block_attributes($block['attrs'], $url_map, $block_name);
                $block['attrs'] = $attrs_result['data'];
                $total_replaced += $attrs_result['replaced_count'];
            }

            // Process innerHTML for blocks that store content there
            if (isset($block['innerHTML']) && is_string($block['innerHTML']) && !empty($block['innerHTML'])) {
                // Only process innerHTML if it contains URLs
                $needs_processing = false;
                foreach ($url_map as $old_url => $new_url) {
                    if (strpos($block['innerHTML'], $old_url) !== false) {
                        $needs_processing = true;
                        break;
                    }
                }

                if ($needs_processing) {
                    $html_result = $this->safe_url_replace($block['innerHTML'], $url_map);
                    $block['innerHTML'] = $html_result['data'];
                    $total_replaced += $html_result['replaced_count'];
                }
            }

            // Process innerContent array
            if (isset($block['innerContent']) && is_array($block['innerContent'])) {
                foreach ($block['innerContent'] as &$content_item) {
                    if (is_string($content_item) && !empty($content_item)) {
                        // Check if processing needed
                        $needs_processing = false;
                        foreach ($url_map as $old_url => $new_url) {
                            if (strpos($content_item, $old_url) !== false) {
                                $needs_processing = true;
                                break;
                            }
                        }

                        if ($needs_processing) {
                            $content_result = $this->safe_url_replace($content_item, $url_map);
                            $content_item = $content_result['data'];
                            $total_replaced += $content_result['replaced_count'];
                        }
                    }
                }
            }

            // Process inner blocks
            if (isset($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                $inner_result = $this->process_blocks($block['innerBlocks'], $url_map);
                $block['innerBlocks'] = $inner_result['data'];
                $total_replaced += $inner_result['replaced_count'];
            }
        }

        return ['data' => $blocks, 'replaced_count' => $total_replaced];
    }

    /**
     * Process block attributes with block-specific handling.
     *
     * @param array  $attrs      The block attributes.
     * @param array  $url_map    Array of old_url => new_url mappings.
     * @param string $block_name The block name for context.
     * @return array Result with 'data' and 'replaced_count' keys.
     */
    private function process_block_attributes($attrs, $url_map, $block_name = '') {
        $total_replaced = 0;

        // Special handling for known image blocks
        if (in_array($block_name, self::IMAGE_BLOCKS)) {
            return $this->process_image_block_attributes($attrs, $url_map, $block_name);
        }

        // General attribute processing
        foreach ($attrs as $key => &$value) {
            if (is_string($value) && !empty($value)) {
                // Check if this attribute might contain URLs
                if ($this->is_url_attribute($key)) {
                    $replace_result = $this->safe_url_replace($value, $url_map);
                    $value = $replace_result['data'];
                    $total_replaced += $replace_result['replaced_count'];
                }
            } elseif (is_array($value)) {
                // Recursively process arrays
                $array_result = $this->process_block_attributes($value, $url_map, $block_name);
                $value = $array_result['data'];
                $total_replaced += $array_result['replaced_count'];
            }
        }

        return ['data' => $attrs, 'replaced_count' => $total_replaced];
    }

    /**
     * Process attributes for image blocks specifically
     *
     * @param array $attrs
     * @param array $url_map
     * @param string $block_name
     * @return array
     */
    private function process_image_block_attributes($attrs, $url_map, $block_name) {
        $total_replaced = 0;

        switch ($block_name) {
            case 'core/image':
            case 'core/cover':
            case 'core/media-text':
                // Process URL attribute
                if (isset($attrs['url'])) {
                    $replace_result = $this->safe_url_replace($attrs['url'], $url_map);
                    $attrs['url'] = $replace_result['data'];
                    $total_replaced += $replace_result['replaced_count'];
                }
                
                // Process sizes
                if (isset($attrs['sizeSlug']) && isset($attrs['id'])) {
                    // Update size URLs if present
                    foreach (['href', 'linkDestination'] as $attr) {
                        if (isset($attrs[$attr])) {
                            $replace_result = $this->safe_url_replace($attrs[$attr], $url_map);
                            $attrs[$attr] = $replace_result['data'];
                            $total_replaced += $replace_result['replaced_count'];
                        }
                    }
                }
                break;

            case 'core/gallery':
                // Process gallery images
                if (isset($attrs['images']) && is_array($attrs['images'])) {
                    foreach ($attrs['images'] as &$image) {
                        if (is_array($image)) {
                            foreach (['url', 'fullUrl', 'link'] as $url_attr) {
                                if (isset($image[$url_attr])) {
                                    $replace_result = $this->safe_url_replace($image[$url_attr], $url_map);
                                    $image[$url_attr] = $replace_result['data'];
                                    $total_replaced += $replace_result['replaced_count'];
                                }
                            }
                        }
                    }
                }
                
                // Process new gallery format (WP 5.9+)
                if (isset($attrs['ids']) && is_array($attrs['ids'])) {
                    // IDs don't need URL replacement, but check for custom links
                    if (isset($attrs['linkTo']) && $attrs['linkTo'] === 'media') {
                        // URLs are in innerHTML for new gallery format
                    }
                }
                break;

            case 'core/video':
            case 'core/file':
                // Process src attribute
                if (isset($attrs['src'])) {
                    $replace_result = $this->safe_url_replace($attrs['src'], $url_map);
                    $attrs['src'] = $replace_result['data'];
                    $total_replaced += $replace_result['replaced_count'];
                }
                break;
        }

        // Process any remaining string attributes that might contain URLs
        foreach ($attrs as $key => &$value) {
            if (is_string($value) && $this->is_url_attribute($key)) {
                $replace_result = $this->safe_url_replace($value, $url_map);
                $value = $replace_result['data'];
                $total_replaced += $replace_result['replaced_count'];
            }
        }

        return ['data' => $attrs, 'replaced_count' => $total_replaced];
    }

    /**
     * Check if an attribute name likely contains URLs
     *
     * @param string $attr_name
     * @return bool
     */
    private function is_url_attribute($attr_name) {
        $url_attributes = [
            'url', 'src', 'href', 'link', 'source',
            'backgroundImage', 'overlayImage', 'mediaUrl',
            'mediaSrc', 'imageUrl', 'videoUrl', 'fileUrl'
        ];

        $attr_lower = strtolower($attr_name);
        
        foreach ($url_attributes as $url_attr) {
            if (strpos($attr_lower, strtolower($url_attr)) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Clear Gutenberg caches.
     *
     * @param array $updated_post_ids Array of post IDs that were updated.
     * @return bool True on success, false on failure.
     */
    public function clear_cache($updated_post_ids = []) {
        try {
            // Clear WordPress post caches
            if (!empty($updated_post_ids)) {
                foreach ($updated_post_ids as $post_id) {
                    clean_post_cache($post_id);
                    
                    // Clear block-related transients
                    delete_transient('has_blocks_' . $post_id);
                    delete_post_meta($post_id, '_wp_parsed_blocks_version');
                }
                
                $this->log_info('Cleared WordPress post cache for Gutenberg posts', ['posts' => count($updated_post_ids)]);
            }

            // Clear our custom cache
            global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Cache cleanup operation, no caching needed
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                    $wpdb->esc_like( '_transient_infyp_gutenberg_posts_' ) . '%'
                )
            );

            // Clear reusable blocks cache
            delete_transient('wp_block_cache');

            return true;

        } catch (\Exception $e) {
            $this->log_error('Failed to clear Gutenberg cache: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Validate Gutenberg data integrity.
     *
     * @param int $post_id The post ID to validate.
     * @return bool True if data is valid, false otherwise.
     */
    public function validate_data($post_id) {
        if (!parent::validate_data($post_id)) {
            return false;
        }

        try {
            $post = get_post($post_id);
            if (!$post || empty($post->post_content)) {
                return true; // Empty content is valid
            }

            // If content has blocks, try to parse them
            if (has_blocks($post->post_content)) {
                $blocks = parse_blocks($post->post_content);
                
                // Check if parsing was successful
                if (!is_array($blocks)) {
                    $this->log_error('Failed to parse Gutenberg blocks', ['post_id' => $post_id]);
                    return false;
                }

                // Check if we can serialize them back
                $serialized = serialize_blocks($blocks);
                if (empty($serialized) && !empty($blocks)) {
                    $this->log_error('Failed to serialize Gutenberg blocks', ['post_id' => $post_id]);
                    return false;
                }
            }

            return true;

        } catch (\Exception $e) {
            $this->log_error('Exception validating Gutenberg data: ' . $e->getMessage(), [
                'post_id' => $post_id
            ]);
            return false;
        }
    }
}