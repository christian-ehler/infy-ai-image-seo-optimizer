<?php
/**
 * Elementor Page Builder Adapter - Elementor Support
 *
 * This adapter handles image URL updates within Elementor's complex JSON-based
 * data structures stored in post meta. Elementor stores page builder content
 * as nested JSON arrays containing elements, settings, and widget configurations.
 *
 * The adapter processes multiple Elementor meta keys:
 * - _elementor_data: Main page builder content (element tree)
 * - _elementor_page_settings: Page-level settings
 * - _elementor_popup_display_settings: Popup configuration
 *
 * Processing Strategy:
 * 1. Query posts with Elementor meta data
 * 2. Decode JSON with proper unslashing
 * 3. Recursively process element tree
 * 4. Update image objects and URL strings
 * 5. Re-encode and save JSON
 * 6. Delete generated CSS files to force regeneration
 * 7. Clear Elementor and WordPress caches
 *
 * Supported Features:
 * - All Elementor widgets (Image, Gallery, Carousel, etc.)
 * - Elementor Pro widgets (Before/After, Hotspot, etc.)
 * - Background images and overlays
 * - Saved templates and global widgets
 * - Landing pages (e-landing-page post type)
 * - Popup settings
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
 * Elementor Page Builder Adapter Class
 *
 * Specialized adapter for handling Elementor page builder content updates.
 * Processes Elementor's JSON-based data structures with comprehensive support
 * for all Elementor and Elementor Pro widgets.
 *
 * Data Structure:
 * Elementor stores data as JSON-encoded arrays with this structure:
 * - elements[]: Array of element objects
 *   - id: Unique element ID
 *   - elType: Element type (section, column, widget)
 *   - settings{}: Element settings (where URLs live)
 *   - elements[]: Nested child elements
 *
 * Image Data Formats:
 * - String URL: Direct URL string
 * - Image Object: {id: int, url: string, source: string}
 * - Gallery Array: [{id: int, url: string}, ...]
 *
 * Cache Management:
 * Elementor generates CSS files for each page. After URL updates, the adapter:
 * - Deletes physical CSS files from /uploads/elementor/css/
 * - Clears _elementor_css meta
 * - Triggers CSS regeneration via Elementor API
 *
 * @package   IPAIS\Core\PageBuilders
 * @since 1.0.0
 *
 * @see       INFYP_PageBuilder_Base_Adapter For inherited utilities
 * @see       https://developers.elementor.com/ Elementor Developer Documentation
 */
class INFYP_Elementor_Adapter extends INFYP_PageBuilder_Base_Adapter {

    /**
     * Elementor main data meta key
     *
     * Stores the complete element tree as JSON-encoded array.
     *
     * @since 1.0.0
     * @var   string
     */
    const META_KEY = '_elementor_data';

    /**
     * Elementor page settings meta key
     *
     * @since 1.0.0
     * @var   string
     */
    const META_KEY_PAGE_SETTINGS = '_elementor_page_settings';

    /**
     * Elementor popup settings meta key
     *
     * @since 1.0.0
     * @var   string
     */
    const META_KEY_POPUP_SETTINGS = '_elementor_popup_display_settings';

    /**
     * Elementor version meta key
     *
     * @since 1.0.0
     * @var   string
     */
    const META_KEY_VERSION = '_elementor_version';

    /**
     * Elementor CSS meta key
     *
     * Stores generated CSS for the page. Deleted after updates to force regeneration.
     *
     * @since 1.0.0
     * @var   string
     */
    const META_KEY_CSS = '_elementor_css';

    /**
     * Maximum recursion depth for element processing
     *
     * Prevents infinite recursion in deeply nested element structures.
     *
     * @since 1.0.0
     * @var   int
     */
    const MAX_RECURSION_DEPTH = 50;

    /**
     * Current recursion depth tracker
     *
     * Tracks depth during recursive element processing to prevent stack overflow.
     *
     * @since 1.0.0
     * @var   int
     */
    private $current_depth = 0;

    /**
     * Check if Elementor is active.
     *
     * @return bool True if Elementor is active, false otherwise.
     */
    public function is_active() {
        return defined('ELEMENTOR_VERSION') || class_exists('\Elementor\Plugin');
    }

    /**
     * Get the display name of this page builder.
     *
     * @return string The human-readable name of the page builder.
     */
    public function get_name() {
        return 'Elementor';
    }

    /**
     * Process URL updates for Elementor.
     *
     * @param array $url_map Array of old_url => new_url mappings.
     * @return array Array containing 'updated_posts' and 'errors' keys.
     */
    public function process_url_updates($url_map) {
        global $wpdb;

        $this->reset_stats();
        $this->log_info('Starting Elementor URL updates', ['url_count' => count($url_map)]);

        /**
         * Supported Elementor post types:
         * - page, post: Standard WordPress content
         * - elementor_library: Saved templates, global widgets, sections
         * - e-landing-page: Elementor landing pages
         * - Any custom post type with Elementor data
         */
        // Find all posts with Elementor data using optimized query
        $post_ids = wp_cache_get('infyp_elementor_posts', 'infyp');

        if ($post_ids === false) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Cached query for finding Elementor posts
            $post_ids = $wpdb->get_col(
                "SELECT DISTINCT p.ID FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                 WHERE pm.meta_key IN ('_elementor_data', '_elementor_page_settings', '_elementor_popup_display_settings')
                 AND p.post_status IN ('publish', 'draft', 'private', 'pending', 'future')
                 AND p.post_type NOT IN ('revision', 'attachment', 'nav_menu_item', 'customize_changeset')"
            );

            wp_cache_set('infyp_elementor_posts', $post_ids, 'infyp', 300);
        }

        if (empty($post_ids)) {
            $this->log_info('No Elementor posts found');
            $this->finalize_stats();
            return ['updated_posts' => [], 'errors' => []];
        }

        $this->log_info('Found Elementor posts', ['count' => count($post_ids)]);

        // Process posts in batches
        $results = $this->process_posts_in_batches(
            $post_ids,
            $url_map,
            [$this, 'process_single_post'],
            20 // Optimized batch size for Elementor
        );

        $this->finalize_stats();
        $this->log_info('Completed Elementor URL updates', $this->get_stats());

        return $results;
    }

    /**
     * Process a single post's Elementor data.
     *
     * @param int   $post_id The post ID to process.
     * @param array $url_map Array of old_url => new_url mappings.
     * @return array Result with 'updated' and 'errors' keys.
     */
    public function process_single_post($post_id, $url_map) {
        $result = ['updated' => false, 'errors' => []];

        // Skip if not Elementor post
        if (!$this->is_elementor_post($post_id)) {
            return $result;
        }

        try {
            // Set current post ID for enhanced logging
            $this->current_post_id = $post_id;

            // Process all Elementor meta keys
            $meta_keys_to_process = [
                self::META_KEY,
                self::META_KEY_PAGE_SETTINGS,
                self::META_KEY_POPUP_SETTINGS,
            ];

            $total_updated = false;
            $all_errors = [];

            foreach ($meta_keys_to_process as $meta_key) {
                $meta_result = $this->process_meta_key($post_id, $meta_key, $url_map);

                if ($meta_result['updated']) {
                    $total_updated = true;
                }

                $all_errors = array_merge($all_errors, $meta_result['errors']);
            }

            if ($total_updated) {
                $result['updated'] = true;
                $this->update_stats('posts_updated');

                // Mark CSS for regeneration
                delete_post_meta($post_id, self::META_KEY_CSS);

                $this->log_info('Updated Elementor post', $this->get_enhanced_context($post_id, [
                    'meta_keys_updated' => count(array_filter($meta_keys_to_process, function($key) use ($post_id) {
                        return !empty(get_post_meta($post_id, $key, true));
                    }))
                ]));
            }

            $result['errors'] = $all_errors;

        } catch (\Exception $e) {
            $error = sprintf('Exception processing Elementor post %d: %s', $post_id, $e->getMessage());
            $result['errors'][] = $error;
            $this->log_error($error, ['post_id' => $post_id, 'exception' => $e->getMessage()]);
        }

        return $result;
    }

    /**
     * Check if post uses Elementor
     *
     * @param int $post_id
     * @return bool
     */
    private function is_elementor_post($post_id) {
        return get_post_meta($post_id, '_elementor_edit_mode', true) === 'builder';
    }

    /**
     * Process a specific Elementor meta key
     *
     * @param int    $post_id  Post ID
     * @param string $meta_key Meta key to process
     * @param array  $url_map  URL mappings
     * @return array Result with 'updated' and 'errors' keys
     */
    private function process_meta_key($post_id, $meta_key, $url_map) {
        $result = ['updated' => false, 'errors' => []];

        $raw_data = get_post_meta($post_id, $meta_key, true);

        if (empty($raw_data) || !is_string($raw_data)) {
            return $result;
        }

        // Quick check if any URLs exist in the data
        $needs_processing = false;
        foreach ($url_map as $old_url => $new_url) {
            if (strpos($raw_data, $old_url) !== false) {
                $needs_processing = true;
                break;
            }
        }

        if (!$needs_processing) {
            return $result;
        }

        // Elementor stores JSON data, often with backslash escaping
        $unslashed_data = wp_unslash($raw_data);

        // Validate JSON before processing
        if (!$this->is_valid_json($unslashed_data)) {
            $result['errors'][] = sprintf('Invalid JSON data in %s for post %d', $meta_key, $post_id);
            return $result;
        }

        $decoded_data = json_decode($unslashed_data, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $result['errors'][] = sprintf('JSON decode error in %s for post %d: %s', $meta_key, $post_id, json_last_error_msg());
            return $result;
        }

        // Reset recursion depth
        $this->current_depth = 0;

        // Process based on meta key type
        if ($meta_key === self::META_KEY) {
            // _elementor_data - process as element tree
            $process_result = $this->process_elementor_elements($decoded_data, $url_map);
        } elseif ($meta_key === self::META_KEY_POPUP_SETTINGS) {
            // _elementor_popup_display_settings - process as popup settings object
            $process_result = $this->process_popup_settings($decoded_data, $url_map);
        } else {
            // _elementor_page_settings and others - process as settings object
            $process_result = $this->process_elementor_settings($decoded_data, $url_map);
        }

        if ($process_result['replaced_count'] > 0) {
            $updated_json = wp_json_encode($process_result['data'], JSON_UNESCAPED_UNICODE);

            if ($updated_json === false) {
                $result['errors'][] = sprintf('Failed to encode JSON for %s', $meta_key);
                return $result;
            }

            // Update the post meta
            $update_success = update_post_meta($post_id, $meta_key, wp_slash($updated_json));

            if ($update_success !== false) {
                $result['updated'] = true;
                $this->update_stats('urls_replaced', $process_result['replaced_count']);

                $this->log_info('Updated Elementor meta', [
                    'post_id' => $post_id,
                    // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- This is just logging context, not a database query
                    'meta_key' => $meta_key,
                    'replacements' => $process_result['replaced_count']
                ]);
            } else {
                $result['errors'][] = sprintf('Failed to update meta %s for post %d', $meta_key, $post_id);
            }
        }

        return $result;
    }

    /**
     * Process Elementor elements recursively with depth protection.
     *
     * @param array $elements The Elementor elements array.
     * @param array $url_map  Array of old_url => new_url mappings.
     * @return array Result with 'data' and 'replaced_count' keys.
     */
    private function process_elementor_elements($elements, $url_map) {
        $total_replaced = 0;

        if (!is_array($elements)) {
            return ['data' => $elements, 'replaced_count' => 0];
        }

        // Prevent infinite recursion
        $this->current_depth++;
        if ($this->current_depth > self::MAX_RECURSION_DEPTH) {
            $this->log_error('Maximum recursion depth reached');
            return ['data' => $elements, 'replaced_count' => 0];
        }

        foreach ($elements as &$element) {
            if (!is_array($element)) {
                continue;
            }

            // Process element settings
            if (isset($element['settings']) && is_array($element['settings'])) {
                $settings_result = $this->process_elementor_settings($element['settings'], $url_map);
                $element['settings'] = $settings_result['data'];
                $total_replaced += $settings_result['replaced_count'];
            }

            // Process nested elements
            if (isset($element['elements']) && is_array($element['elements'])) {
                $elements_result = $this->process_elementor_elements($element['elements'], $url_map);
                $element['elements'] = $elements_result['data'];
                $total_replaced += $elements_result['replaced_count'];
            }
        }

        $this->current_depth--;
        return ['data' => $elements, 'replaced_count' => $total_replaced];
    }

    /**
     * Process Elementor element settings with optimized handling.
     *
     * @param array $settings The element settings.
     * @param array $url_map  Array of old_url => new_url mappings.
     * @return array Result with 'data' and 'replaced_count' keys.
     */
    private function process_elementor_settings($settings, $url_map) {
        $total_replaced = 0;
        
        // Common image fields in Elementor
        $image_fields = [
            // Core Elementor image fields
            'image',
            'background_image',
            'hover_image',
            'logo_image',
            'before_image',
            'after_image',
            'icon_image',
            'badge_image',

            // Elementor Pro widget fields
            'carousel_image',           // Carousel widget
            'hotspot_image',            // Image Hotspot widget
            'comparison_image',         // Image Comparison widget
            'comparison_before',        // Before/After widget (before)
            'comparison_after',         // Before/After widget (after)
            'portfolio_image',          // Portfolio widget
            'testimonial_image',        // Testimonial widget
            'team_image',               // Team Member widget
            'team_member_image',        // Alternative team field
            'card_image',               // Card widget
            'flip_box_image_front',     // Flip Box (front)
            'flip_box_image_back',      // Flip Box (back)
            'price_table_badge',        // Price Table badge
            'shape_divider_image',      // Shape Divider custom image
            'lottie_url',               // Lottie animations (can be image)
            'media',                    // Generic media field
            'background_video_fallback', // Video fallback image
            'slide_image',              // Slider slides
            'gallery_item',             // Gallery items
        ];

        foreach ($settings as $key => &$value) {
            if (is_string($value) && !empty($value)) {
                // Direct URL replacement
                $replace_result = $this->safe_url_replace($value, $url_map);
                $value = $replace_result['data'];
                $total_replaced += $replace_result['replaced_count'];
                
            } elseif (is_array($value)) {
                // Handle image objects and gallery arrays
                if (in_array($key, $image_fields) || $this->is_elementor_image_object($value)) {
                    $image_result = $this->process_image_object($value, $url_map);
                    $value = $image_result['data'];
                    $total_replaced += $image_result['replaced_count'];
                } elseif ($key === 'gallery' || $key === 'slides') {
                    // Handle galleries and sliders
                    foreach ($value as &$item) {
                        if (is_array($item)) {
                            $item_result = $this->process_image_object($item, $url_map);
                            $item = $item_result['data'];
                            $total_replaced += $item_result['replaced_count'];
                        }
                    }
                } else {
                    // Recursively process other arrays
                    $array_result = $this->recursive_url_replace($value, $url_map);
                    $value = $array_result['data'];
                    $total_replaced += $array_result['replaced_count'];
                }
            }
        }

        return ['data' => $settings, 'replaced_count' => $total_replaced];
    }

    /**
     * Process Elementor popup display settings
     *
     * @param array $settings Popup settings array
     * @param array $url_map  URL mappings
     * @return array Result with 'data' and 'replaced_count'
     */
    private function process_popup_settings($settings, $url_map) {
        $total_replaced = 0;

        if (!is_array($settings)) {
            return ['data' => $settings, 'replaced_count' => 0];
        }

        // Popup-specific image fields
        $popup_image_fields = [
            'trigger_image',           // Image trigger
            'close_button_image',      // Custom close button
            'background_image',        // Popup background
            'overlay_image',           // Overlay image
            'condition_image',         // Display condition image
        ];

        foreach ($settings as $key => &$value) {
            if (is_string($value) && !empty($value)) {
                // Direct URL replacement
                $replace_result = $this->safe_url_replace($value, $url_map);
                $value = $replace_result['data'];
                $total_replaced += $replace_result['replaced_count'];

            } elseif (is_array($value)) {
                // Check if it's an image field
                if (in_array($key, $popup_image_fields, true) || $this->is_elementor_image_object($value)) {
                    $image_result = $this->process_image_object($value, $url_map);
                    $value = $image_result['data'];
                    $total_replaced += $image_result['replaced_count'];
                } else {
                    // Recursive processing for nested arrays
                    $nested_result = $this->process_popup_settings($value, $url_map);
                    $value = $nested_result['data'];
                    $total_replaced += $nested_result['replaced_count'];
                }
            }
        }

        return ['data' => $settings, 'replaced_count' => $total_replaced];
    }

    /**
     * Process Elementor image object
     *
     * @param array $image_object
     * @param array $url_map
     * @return array
     */
    private function process_image_object($image_object, $url_map) {
        $total_replaced = 0;

        if (!is_array($image_object)) {
            return ['data' => $image_object, 'replaced_count' => 0];
        }

        // Process main URL
        if (isset($image_object['url']) && is_string($image_object['url'])) {
            $replace_result = $this->safe_url_replace($image_object['url'], $url_map);
            $image_object['url'] = $replace_result['data'];
            $total_replaced += $replace_result['replaced_count'];
        }

        // Process additional URLs in image object
        $url_fields = ['thumbnail', 'medium', 'large', 'full'];
        foreach ($url_fields as $field) {
            if (isset($image_object[$field]) && is_string($image_object[$field])) {
                $replace_result = $this->safe_url_replace($image_object[$field], $url_map);
                $image_object[$field] = $replace_result['data'];
                $total_replaced += $replace_result['replaced_count'];
            }
        }

        return ['data' => $image_object, 'replaced_count' => $total_replaced];
    }

    /**
     * Check if an array is an Elementor image object.
     *
     * @param mixed $array The array to check.
     * @return bool True if it's an image object, false otherwise.
     */
    private function is_elementor_image_object($array) {
        return is_array($array) && 
               isset($array['id']) && 
               (isset($array['url']) || isset($array['source'])) &&
               is_numeric($array['id']);
    }

    /**
     * Validate if a string is valid JSON.
     *
     * @param string $string The string to validate.
     * @return bool True if valid JSON, false otherwise.
     */
    private function is_valid_json($string) {
        if (!is_string($string) || empty($string)) {
            return false;
        }

        // Quick check for JSON structure
        $first_char = substr($string, 0, 1);
        $last_char = substr($string, -1);
        if (!(($first_char === '[' && $last_char === ']') || 
              ($first_char === '{' && $last_char === '}'))) {
            return false;
        }

        json_decode($string);
        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * Delete physical CSS files for updated posts
     *
     * @param array $post_ids Array of post IDs
     * @return int Number of files deleted
     */
    private function delete_css_files($post_ids) {
        if (empty($post_ids) || !is_array($post_ids)) {
            return 0;
        }

        $deleted_count = 0;
        $upload_dir   = wp_upload_dir();
        $css_base_dir = $upload_dir['basedir'] . '/elementor/css/';

        // Check if directory exists
        if (!is_dir($css_base_dir)) {
            $this->log_info('Elementor CSS directory not found', ['path' => $css_base_dir]);
            return 0;
        }

        // Initialize WordPress filesystem
        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            if ( ! function_exists( 'WP_Filesystem' ) ) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }
            WP_Filesystem();
        }

        foreach ($post_ids as $post_id) {
            // Elementor CSS file naming pattern: post-{ID}.css, post-{ID}-{hash}.css
            $patterns = [
                "post-{$post_id}.css",
                "post-{$post_id}-*.css",
            ];

            foreach ($patterns as $pattern) {
                $files = glob($css_base_dir . $pattern);

                if ($files && is_array($files)) {
                    foreach ($files as $file) {
                        // Check if file exists and is writable using WP_Filesystem
                        if ($wp_filesystem->exists($file) && $wp_filesystem->is_writable($file)) {
                            // Use wp_delete_file for proper WordPress file deletion
                            wp_delete_file($file);
                            if ( ! file_exists($file) ) {
                                $deleted_count++;
                                $this->log_info('Deleted CSS file', [
                                    'post_id' => $post_id,
                                    'file' => basename($file)
                                ]);
                            } else {
                                $this->log_error('Failed to delete CSS file', [
                                    'post_id' => $post_id,
                                    'file' => $file
                                ]);
                            }
                        }
                    }
                }
            }
        }

        return $deleted_count;
    }

    /**
     * Clear Elementor caches.
     *
     * @param array $updated_post_ids Array of post IDs that were updated.
     * @return bool True on success, false on failure.
     */
    public function clear_cache($updated_post_ids = []) {
        if (!$this->is_active()) {
            return false;
        }

        try {
            // DELETE PHYSICAL CSS FILES FIRST
            if (!empty($updated_post_ids)) {
                $deleted_files = $this->delete_css_files($updated_post_ids);
                if ($deleted_files > 0) {
                    $this->log_info('Deleted Elementor CSS files', ['count' => $deleted_files]);
                }
            }

            // Clear Elementor's cache
            if (class_exists('\Elementor\Plugin')) {
                $elementor = \Elementor\Plugin::instance();

                // Clear global cache
                if (isset($elementor->files_manager)) {
                    $elementor->files_manager->clear_cache();
                }

                // Clear post-specific cache and trigger CSS regeneration
                if (!empty($updated_post_ids) && class_exists('\Elementor\Core\Files\CSS\Post')) {
                    foreach ($updated_post_ids as $post_id) {
                        delete_post_meta($post_id, self::META_KEY_CSS);

                        // Trigger CSS regeneration
                        if (method_exists('\Elementor\Core\Files\CSS\Post', 'update')) {
                            $css_file = new \Elementor\Core\Files\CSS\Post($post_id);
                            $css_file->update();
                        }
                    }
                }
            }

            // Clear WordPress cache
            if (!empty($updated_post_ids)) {
                foreach ($updated_post_ids as $post_id) {
                    clean_post_cache($post_id);
                }
            }

            // Clear our cache
            wp_cache_delete('infyp_elementor_posts', 'infyp');

            return true;

        } catch (\Exception $e) {
            $this->log_error('Failed to clear Elementor cache: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Validate Elementor data integrity.
     *
     * @param int $post_id The post ID to validate.
     * @return bool True if data is valid, false otherwise.
     */
    public function validate_data($post_id) {
        if (!parent::validate_data($post_id)) {
            return false;
        }

        try {
            $raw_data = get_post_meta($post_id, self::META_KEY, true);
            
            if (empty($raw_data)) {
                return true; // No data is valid
            }

            if (!is_string($raw_data)) {
                $this->log_error('Elementor data is not a string', ['post_id' => $post_id]);
                return false;
            }

            $unslashed_data = wp_unslash($raw_data);
            
            if (!$this->is_valid_json($unslashed_data)) {
                $this->log_error('Elementor data is not valid JSON', ['post_id' => $post_id]);
                return false;
            }

            return true;

        } catch (\Exception $e) {
            $this->log_error('Exception validating Elementor data: ' . $e->getMessage(), [
                'post_id' => $post_id
            ]);
            return false;
        }
    }
}