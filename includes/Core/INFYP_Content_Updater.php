<?php
/**
 * Content Updater Class
 *
 * Handles comprehensive search and replace operations for image URLs across WordPress content,
 * including post content, meta data, page builders (Elementor, etc.), and featured images.
 * Production-optimized with batch processing and caching for maximum performance.
 *
 * This class is responsible for updating all references to an image when its filename or URL changes,
 * ensuring consistency across the entire WordPress site including page builder content.
 *
 * @package    IPAIS
 * @subpackage Core
 * @since 1.0.0
 * @author     InfyPress Team
 * @license    GPL-2.0-or-later
 */

namespace IPAIS\Core;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Content Updater Class
 *
 * Manages the update of image URLs throughout WordPress when an image's filename or location changes.
 * This includes updating references in:
 * - Post content (regular content and Gutenberg blocks)
 * - Elementor page builder data
 * - Featured images
 * - All image size variations
 *
 * The class implements smart caching, batch processing, and recursive data structure handling
 * to efficiently update deeply nested page builder content.
 *
 * **Core Functionality:**
 * - Post content URL replacement with multiple format handling (plain, encoded, escaped)
 * - Elementor data processing with JSON encoding/decoding
 * - Featured image tracking and cache management
 * - Page builder cache clearing (Elementor CSS, etc.)
 * - WordPress object cache integration
 *
 * **Usage Example:**
 * ```php
 * $old_url = 'https://example.com/wp-content/uploads/old-filename.jpg';
 * $new_url = 'https://example.com/wp-content/uploads/new-filename.jpg';
 *
 * $updater = new INFYP_Content_Updater( $old_url, $new_url );
 * $updated_post_ids = $updater->run_update();
 *
 * // Returns array of post IDs that were updated
 * // [123, 456, 789]
 * ```
 *
 * @since 1.0.0
 */
class INFYP_Content_Updater {

    /**
     * The old image URL to be replaced.
     *
     * Contains the full URL of the image before the filename change or migration.
     * Used as the search term in all URL replacement operations.
     *
     * @since 1.0.0
     * @var   string
     */
    private $old_url;

    /**
     * The new image URL to substitute.
     *
     * Contains the full URL of the image after the filename change or migration.
     * Used as the replacement value in all URL replacement operations.
     *
     * @since 1.0.0
     * @var   string
     */
    private $new_url;

    /**
     * The old URL stem (URL without file extension).
     *
     * Used for stem-based replacement to catch all image size variants
     * (e.g., -300x200, -1024x768, -scaled) in a single operation.
     *
     * @since 1.0.0
     * @var   string
     */
    private $old_stem;

    /**
     * The new URL stem (URL without file extension).
     *
     * @since 1.0.0
     * @var   string
     */
    private $new_stem;

    /**
     * Collection of post IDs that have been updated.
     *
     * Stores unique post IDs for all posts modified during the update process.
     * Used to clear caches and track which content was affected by the URL change.
     *
     * @since 1.0.0
     * @var   array
     */
    private $updated_post_ids = [];

    /**
     * Page builder meta keys mapped to their respective builders.
     *
     * Defines which post meta keys belong to which page builders.
     * Currently supports Elementor builder data and drafts.
     *
     * @since 1.0.0
     * @var   array Associative array of meta_key => builder_name pairs.
     */
    private $page_builder_meta_keys = [
        '_elementor_data' => 'elementor',
        '_elementor_draft' => 'elementor',
    ];

    /**
     * Cache for processed data during the update operation.
     *
     * Temporary storage for data that may need to be reused within
     * a single update operation, improving performance.
     *
     * @since 1.0.0
     * @var   array
     */
    private $cache = [];

    /**
     * Constructor - Initializes the content updater with URL mapping.
     *
     * Sets up the updater with the old and new URLs that will be used
     * for all search and replace operations. No validation is performed
     * in the constructor; validation occurs during the update process.
     *
     * @since 1.0.0
     *
     * @param string $old_url The original URL of the image to be replaced.
     * @param string $new_url The new URL of the image to use as replacement.
     */
    public function __construct($old_url, $new_url) {
        $upload_dir = wp_upload_dir();
        $base_url   = $upload_dir['baseurl'];

        // Validate URLs belong to this site's uploads directory (empty new_url allowed for read-only usage)
        if ( ! empty( $old_url ) && strpos( $old_url, $base_url ) !== 0 ) {
            throw new \InvalidArgumentException( 'Old URL does not belong to this site\'s uploads directory.' );
        }
        if ( ! empty( $new_url ) && strpos( $new_url, $base_url ) !== 0 ) {
            throw new \InvalidArgumentException( 'New URL does not belong to this site\'s uploads directory.' );
        }

        $this->old_url = $old_url;
        $this->new_url = $new_url;
        $this->old_stem = preg_replace( '/\.[^.]+$/', '', $old_url );
        $this->new_stem = preg_replace( '/\.[^.]+$/', '', $new_url );
    }

    /**
     * Executes the complete content update process.
     *
     * Performs a comprehensive update of all image URL references across the site:
     * 1. Updates standard post content (including Gutenberg blocks)
     * 2. Updates Elementor page builder data
     * 3. Updates featured image references
     * 4. Clears all relevant caches
     *
     * The method processes posts in batches to prevent memory issues and
     * handles multiple URL formats (plain, encoded, escaped JSON).
     *
     * **Process Flow:**
     * - Clear existing state
     * - Update post_content field in posts table
     * - Update Elementor meta data
     * - Update featured image references
     * - Clear WordPress and page builder caches
     * - Return unique list of affected post IDs
     *
     * @since 1.0.0
     *
     * @return array Array of unique post IDs that were modified during the update.
     *               Returns empty array if no posts were updated.
     *
     * @uses update_post_content()        Updates main post content.
     * @uses update_elementor_content()   Updates Elementor builder data.
     * @uses update_featured_images()     Updates featured image references.
     * @uses clear_caches()               Clears all relevant caches.
     */
    public function run_update() {
        if ( empty( $this->old_url ) || empty( $this->new_url ) ) {
            return;
        }

        // Clear any existing data
        $this->updated_post_ids = [];
        $this->cache = [];

        // Update post content (includes Gutenberg blocks)
        $this->update_post_content();
        
        // Update Elementor data specifically
        $this->update_elementor_content();
        
        // Update featured images
        $this->update_featured_images();

        // Clear caches for updated posts
        $this->clear_caches();

        return array_unique($this->updated_post_ids);
    }

    /**
     * Updates image URLs in post content fields.
     *
     * Searches the wp_posts table for posts containing the old URL and replaces
     * it with the new URL. Limited to 500 posts per execution to prevent timeouts.
     * Handles Gutenberg blocks and regular content.
     *
     * @since 1.0.0
     * @access private
     *
     * @global wpdb $wpdb WordPress database abstraction object.
     *
     * @return void
     */
    private function update_post_content() {
        global $wpdb;
        
        // Use the URL stem (without extension) to match all image size variants
        $old_stem_path = wp_parse_url($this->old_stem, PHP_URL_PATH);
        if (!$old_stem_path) {
            return;
        }

        // Get posts containing any size variant of the old image URL
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Need real-time data for content updates
        $posts = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_content
             FROM {$wpdb->posts}
             WHERE post_status IN ('publish', 'draft', 'private', 'future')
             AND post_type NOT IN ('attachment', 'revision', 'nav_menu_item')
             AND post_content LIKE %s
             LIMIT 500",
            '%' . $wpdb->esc_like($old_stem_path) . '%'
        ));

        if (empty($posts)) {
            return;
        }

        // Process in batches to avoid memory issues
        foreach ($posts as $post) {
            $new_content = $this->replace_urls_in_content($post->post_content);
            
            if ($new_content !== $post->post_content) {
                // Update the post
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct update needed for performance
                $result = $wpdb->update(
                    $wpdb->posts,
                    ['post_content' => $new_content],
                    ['ID' => $post->ID],
                    ['%s'],
                    ['%d']
                );
                
                if ($result !== false) {
                    $this->updated_post_ids[] = $post->ID;
                }
            }
        }
    }

    /**
     * Updates Elementor page builder content.
     *
     * Processes Elementor-specific meta data (_elementor_data and _elementor_draft)
     * to replace old image URLs. Handles JSON encoding/decoding and clears
     * Elementor's CSS cache for updated posts.
     *
     * @since 1.0.0
     * @access private
     *
     * @global wpdb $wpdb WordPress database abstraction object.
     *
     * @return void
     */
    private function update_elementor_content() {
        global $wpdb;
        
        // Check if Elementor is active
        if (!defined('ELEMENTOR_VERSION')) {
            return;
        }

        $elementor_meta_keys = ['_elementor_data', '_elementor_draft'];
        
        foreach ($elementor_meta_keys as $meta_key) {
            // Get posts with Elementor data containing any size variant
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Need real-time meta data for updates
            $meta_results = $wpdb->get_results($wpdb->prepare(
                "SELECT post_id, meta_value
                 FROM {$wpdb->postmeta}
                 WHERE meta_key = %s
                 AND meta_value LIKE %s
                 LIMIT 200",
                $meta_key,
                '%' . $wpdb->esc_like($this->old_stem) . '%'
            ));

            foreach ($meta_results as $meta) {
                $updated_value = $this->process_elementor_data($meta->meta_value);
                
                if ($updated_value !== $meta->meta_value) {
                    update_post_meta($meta->post_id, $meta_key, $updated_value);
                    $this->updated_post_ids[] = $meta->post_id;
                    
                    // Clear Elementor CSS cache for this post
                    delete_post_meta($meta->post_id, '_elementor_css');
                }
            }
        }
    }

    /**
     * Processes Elementor JSON data for URL replacement.
     *
     * Handles the complexsity of Elementor's JSON-encoded data structure,
     * including slashed data, recursive replacement, and proper re-encoding.
     *
     * @since 1.0.0
     * @access private
     *
     * @param string $data Elementor JSON data (may be slashed).
     * @return string Processed data with URLs replaced.
     */
    private function process_elementor_data($data) {
        if (empty($data)) {
            return $data;
        }

        // Elementor data is JSON, but may be slashed
        $unslashed = wp_unslash($data);
        
        // Try to decode
        $decoded = json_decode($unslashed, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            // Process the decoded data
            $processed = $this->recursive_replace($decoded);
            
            // Re-encode
            $encoded = wp_json_encode($processed, JSON_UNESCAPED_UNICODE);
            
            // Re-slash if original was slashed
            if ($data !== $unslashed) {
                $encoded = wp_slash($encoded);
            }
            
            return $encoded;
        }
        
        // Fallback to simple stem-based string replacement
        return str_replace($this->old_stem, $this->new_stem, $data);
    }

    /**
     * Replaces URLs in content with multiple format handling.
     *
     * Performs URL replacement with support for:
     * - Direct URL replacement
     * - URL-encoded URLs
     * - JSON-escaped URLs (with escaped forward slashes)
     *
     * @since 1.0.0
     * @access private
     *
     * @param string $content Content to process.
     * @return string Content with URLs replaced.
     */
    private function replace_urls_in_content($content) {
        if (empty($content)) {
            return $content;
        }

        // Replace URL stems to catch all size variants (-300x200, -1024x768, -scaled, etc.)
        // e.g., uploads/2024/01/old-name → uploads/2024/01/new-name
        // This matches: old-name.jpg, old-name-300x200.jpg, old-name-scaled.jpg, etc.
        $content = str_replace($this->old_stem, $this->new_stem, $content);

        // Handle encoded URLs (stem-based)
        $content = str_replace(urlencode($this->old_stem), urlencode($this->new_stem), $content);

        // Handle escaped URLs in JSON (stem-based)
        $content = str_replace(
            str_replace('/', '\/', $this->old_stem),
            str_replace('/', '\/', $this->new_stem),
            $content
        );

        return $content;
    }

    /**
     * Updates featured image references.
     *
     * Finds posts using the modified image as a featured image and adds them
     * to the updated posts list for cache clearing.
     *
     * @since 1.0.0
     * @access private
     *
     * @global wpdb $wpdb WordPress database abstraction object.
     *
     * @return void
     */
    private function update_featured_images() {
        global $wpdb;
        
        // Get attachment ID — try new URL first (post-rename state), fall back to old URL
        $attachment_id = attachment_url_to_postid($this->new_url);
        if (!$attachment_id) {
            $attachment_id = attachment_url_to_postid($this->old_url);
        }
        if (!$attachment_id) {
            return;
        }

        // Find posts using this as featured image
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Finding posts with featured image
        $posts_with_thumbnail = $wpdb->get_col($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} 
             WHERE meta_key = '_thumbnail_id' 
             AND meta_value = %d",
            $attachment_id
        ));

        // These posts don't need content updates, but should be marked for cache clearing
        $this->updated_post_ids = array_merge($this->updated_post_ids, $posts_with_thumbnail);
    }

    /**
     * Recursively replaces URLs in complex data structures.
     *
     * Traverses arrays and objects recursively to find and replace URLs
     * in deeply nested page builder data structures.
     *
     * @since 1.0.0
     * @access private
     *
     * @param mixed $data Data to process (string, array, or object).
     * @return mixed Processed data with URLs replaced.
     */
    private function recursive_replace($data) {
        if (is_string($data)) {
            return $this->replace_urls_in_content($data);
        }

        if (is_array($data)) {
            foreach ($data as $key => &$value) {
                $value = $this->recursive_replace($value);
            }
            unset($value);
            return $data;
        }

        if (is_object($data)) {
            $vars = get_object_vars($data);
            foreach ($vars as $key => $value) {
                $data->{$key} = $this->recursive_replace($value);
            }
            return $data;
        }

        return $data;
    }

    /**
     * Clears caches for all updated posts.
     *
     * Clears WordPress post cache and page builder-specific caches (Elementor CSS)
     * to ensure changes are reflected immediately on the frontend.
     *
     * @since 1.0.0
     * @access private
     *
     * @return void
     */
    private function clear_caches() {
        if (empty($this->updated_post_ids)) {
            return;
        }

        // Clear WordPress post cache
        foreach ($this->updated_post_ids as $post_id) {
            clean_post_cache($post_id);
        }

        // Clear page builder caches
        if (class_exists('\Elementor\Plugin')) {
            foreach ($this->updated_post_ids as $post_id) {
                // Elementor cache clearing
                delete_post_meta($post_id, '_elementor_css');
                
                if (class_exists('\Elementor\Core\Files\CSS\Post')) {
                    try {
                        $css_file = new \Elementor\Core\Files\CSS\Post($post_id);
                        $css_file->update();
                    } catch (\Exception $e) {
                        // Continue on error
                    }
                }
            }
        }

        // Clear plugin-specific caches only (avoid flushing entire object cache).
        $infyp_cache = \IPAIS\Core\INFYP_Cache_Manager::get_instance();
        $infyp_cache->clear_all_cache();
    }

    /**
     * Finds URL usage in post meta fields without updating.
     *
     * Scans post meta data (Elementor, featured images) to find where a specific
     * image URL is used. Does not modify any data.
     *
     * @since 1.0.0
     *
     * @global wpdb $wpdb WordPress database abstraction object.
     *
     * @param string $url The image URL to search for.
     * @return array Array of post IDs where the URL is used. Empty array if not found.
     */
    public function find_usage_in_meta($url) {
        global $wpdb;
        
        $url_path = wp_parse_url($url, PHP_URL_PATH);
        if (empty($url_path)) {
            return [];
        }

        $post_ids = [];

        // Check Elementor meta
        if (defined('ELEMENTOR_VERSION')) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Scanning for image usage
            $elementor_posts = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT post_id FROM {$wpdb->postmeta} 
                 WHERE meta_key IN ('_elementor_data', '_elementor_draft')
                 AND meta_value LIKE %s",
                '%' . $wpdb->esc_like($url) . '%'
            ));
            
            $post_ids = array_merge($post_ids, $elementor_posts);
        }

        // Check for featured images
        $attachment_id = attachment_url_to_postid($url);
        if ($attachment_id) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Finding featured image usage
            $featured_posts = $wpdb->get_col($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} 
                 WHERE meta_key = '_thumbnail_id' 
                 AND meta_value = %d",
                $attachment_id
            ));
            
            $post_ids = array_merge($post_ids, $featured_posts);
        }

        return array_unique($post_ids);
    }
}