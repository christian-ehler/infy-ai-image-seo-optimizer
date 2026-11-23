<?php
/**
 * Image AJAX Handlers - Domain-specific AJAX handler for image operations
 *
 * This class handles all image-related AJAX operations extracted from the main
 * INFYP_Ajax_Handlers class. It provides handlers for:
 *
 * - Image metadata updates (title, alt text)
 * - Image usage detection
 * - Image data retrieval
 * - Filename updates/renaming
 * - Fresh image URL retrieval
 * - Bulk image ID fetching
 *
 * Security Features:
 * - Nonce verification for all requests
 * - Capability checks (upload_files)
 * - Input sanitization and validation
 * - Error handling and logging
 *
 * @package   IPAIS\Ajax
 * @since 1.0.0
 * @author    InfyPress
 * @license   GPL-2.0-or-later
 */

namespace IPAIS\Ajax;

// Exit if accessed directly (placed after namespace per PHP requirement).
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use \IPAIS\Core\INFYP_File_Renamer;
use \IPAIS\Database\INFYP_Changelog;
use \IPAIS\Core\INFYP_Image_Usage;
use \IPAIS\Core\INFYP_Security_Manager;
use \IPAIS\Core\INFYP_Cache_Manager;
use \IPAIS\Core\INFYP_Error_Handler;
use \IPAIS\Core\INFYP_SEO_Score_Calculator;

/**
 * Image AJAX Handlers Class
 *
 * Handles all image-related AJAX operations including metadata updates,
 * usage detection, filename renaming, and bulk image operations.
 *
 * Registered AJAX Actions (wp_ajax_*):
 * - infyp_update_image_data: Update title/alt text
 * - infyp_update_filename: Rename image file
 * - infyp_get_image_usage: Find image usage in content
 * - infyp_get_image_data: Retrieve image metadata
 * - infyp_get_fresh_image_url: Bypass cached URLs
 * - infyp_get_all_image_ids: Get all attachment IDs
 *
 * @package   IPAIS\Ajax
 * @since 1.0.0
 *
 * @see       INFYP_Security_Manager For nonce and capability checks
 * @see       INFYP_Error_Handler For error logging
 */
class INFYP_Image_Ajax {

    /**
     * Security manager instance for validation
     *
     * @since 1.0.0
     * @var   INFYP_Security_Manager
     */
    private $security;

    /**
     * Cache manager instance for cache operations
     *
     * @since 1.0.0
     * @var   INFYP_Cache_Manager
     */
    private $cache;

    /**
     * Error handler instance for logging
     *
     * @since 1.0.0
     * @var   INFYP_Error_Handler
     */
    private $error_handler;

    /**
     * Constructor.
     *
     * Initializes security, cache, and error handler dependencies.
     *
     * @since 1.0.0
     */
    public function __construct() {
        $this->security = INFYP_Security_Manager::get_instance();
        $this->cache = INFYP_Cache_Manager::get_instance();
        $this->error_handler = INFYP_Error_Handler::get_instance();
    }

    /**
     * Register all image-related AJAX action handlers.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function register() {
        $handlers = [
            'infyp_update_image_data' => 'ajax_update_image_data_handler',
            'infyp_update_filename' => 'ajax_update_filename_handler',
            'infyp_get_image_usage' => 'ajax_get_image_usage_handler',
            'infyp_get_image_data' => 'ajax_get_image_data_handler',
            'infyp_get_fresh_image_url' => 'ajax_get_fresh_image_url_handler',
            'infyp_get_all_image_ids' => 'ajax_get_all_image_ids_handler',
        ];

        foreach ($handlers as $action => $method) {
            add_action('wp_ajax_' . $action, [$this, $method]);
        }
    }

    /**
     * Validate AJAX request parameters
     *
     * @param array $required_params Required parameters
     * @return bool|void Returns true on success, sends JSON error and exits on failure
     */
    private function validate_parameters($required_params = []) {
        // Check required parameters
        foreach ($required_params as $param) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce already verified in calling method
            if (!isset($_POST[$param])) {
                wp_send_json_error([
                    'message' => sprintf(
                        /* translators: %s: Parameter name */
                        esc_html__('Missing required parameter: %s', 'infy-ai-image-seo-optimizer'),
                        $param
                    )
                ]);
            }
        }

        return true;
    }

    /**
     * AJAX handler for updating image title or alt text.
     *
     * @since 1.0.0
     *
     * @return void Sends JSON response and exits.
     */
    public function ajax_update_image_data_handler() {
        // Verify nonce
        if (!check_ajax_referer('infyp_ajax_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => esc_html__('Security check failed.', 'infy-ai-image-seo-optimizer')]);
        }

        // Check user capabilities
        if (!current_user_can('upload_files')) {
            wp_send_json_error(['message' => esc_html__('Insufficient permissions.', 'infy-ai-image-seo-optimizer')]);
        }

        // Validate required parameters
        $this->validate_parameters(['image_id', 'field', 'value']);

        $image_id = isset($_POST['image_id']) ? intval($_POST['image_id']) : 0;
        $field = isset($_POST['field']) ? sanitize_key(wp_unslash($_POST['field'])) : '';
        $value = isset($_POST['value']) ? sanitize_text_field(wp_unslash($_POST['value'])) : '';

        // Validate field - extensible via filter for add-ons
        $allowed_fields = apply_filters('infyp_allowed_edit_fields', ['title', 'alt_text']);

        if (!in_array($field, $allowed_fields, true)) {
            wp_send_json_error(['message' => esc_html__('Invalid field specified.', 'infy-ai-image-seo-optimizer')]);
        }

        // Validate image
        if (!$this->security->validate_attachment_id($image_id)) {
            wp_send_json_error(['message' => esc_html__('Invalid image ID.', 'infy-ai-image-seo-optimizer')]);
        }

        $old_value = '';
        $log_field_name = '';

        try {
            switch ($field) {
                case 'title':
                    $post = get_post($image_id);
                    $old_value = $post->post_title;
                    $log_field_name = esc_html__('Title', 'infy-ai-image-seo-optimizer');

                    $result = wp_update_post([
                        'ID' => $image_id,
                        'post_title' => $value
                    ], true);

                    if (is_wp_error($result)) {
                        throw new \Exception($result->get_error_message());
                    }
                    break;

                case 'alt_text':
                    $old_value = get_post_meta($image_id, '_wp_attachment_image_alt', true);
                    $log_field_name = esc_html__('Alt Text', 'infy-ai-image-seo-optimizer');
                    update_post_meta($image_id, '_wp_attachment_image_alt', $value);
                    break;

                default:
                    /**
                     * Filter to handle editing of extension-registered fields.
                     *
                     * Extensions that register additional editable fields via
                     * `infyp_allowed_edit_fields` must also handle saving them here.
                     *
                     * @since 1.0.0
                     * @param bool   $handled  Whether the field was handled. Default false.
                     * @param int    $image_id The image attachment ID.
                     * @param string $field    The field name being edited.
                     * @param string $value    The new field value.
                     */
                    $handled = apply_filters('infyp_handle_edit_field', false, $image_id, $field, $value);
                    if (!$handled) {
                        wp_send_json_error(['message' => esc_html__('Unsupported field.', 'infy-ai-image-seo-optimizer')]);
                    }
                    $old_value = apply_filters('infyp_edit_field_old_value', '', $image_id, $field);
                    $log_field_name = apply_filters('infyp_edit_field_log_name', $field, $field);
                    break;
            }

            // Log the change
            if ($old_value !== $value) {
                INFYP_Changelog::add_entry($image_id, $log_field_name, $old_value, $value);
            }

            // Clear cache
            $this->cache->invalidate_image_cache($image_id);
            clean_post_cache($image_id);

            // Calculate new SEO score
            $seo_score = INFYP_SEO_Score_Calculator::calculate_score($image_id);

            wp_send_json_success([
                'message' => sprintf(
                    /* translators: %s: Field name (Title, Alt Text, etc.) */
                    esc_html__('%s updated successfully.', 'infy-ai-image-seo-optimizer'),
                    $log_field_name
                ),
                'image_id' => $image_id,
                'seo_score' => $seo_score,
                'field' => $field,
                'new_value' => $value
            ]);

        } catch (\Exception $e) {
            wp_send_json_error(['message' => esc_html__('Failed to update image data.', 'infy-ai-image-seo-optimizer')]);
        }
    }

    /**
     * AJAX handler for retrieving image usage across posts and pages.
     *
     * @since 1.0.0
     *
     * @return void Sends JSON response with usage HTML.
     */
    public function ajax_get_image_usage_handler() {
        // Verify nonce
        if (!check_ajax_referer('infyp_ajax_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => esc_html__('Security check failed.', 'infy-ai-image-seo-optimizer')]);
        }

        // Check user capabilities
        if (!current_user_can('upload_files')) {
            wp_send_json_error(['message' => esc_html__('Insufficient permissions.', 'infy-ai-image-seo-optimizer')]);
        }

        // Validate required parameters
        $this->validate_parameters(['image_id']);

        $image_id = isset($_POST['image_id']) ? intval($_POST['image_id']) : 0;
        $refresh = isset($_POST['refresh']) && sanitize_text_field( wp_unslash( $_POST['refresh'] ) ) === 'true';

        if (!$this->security->validate_attachment_id($image_id)) {
            wp_send_json_error(['message' => esc_html__('Invalid image ID.', 'infy-ai-image-seo-optimizer')]);
        }

        try {
            // Always clear cache when opening modal to ensure real-time data
            // This ensures we always see the most current usage information
            $this->cache->delete('image_usage_html_' . $image_id, INFYP_Cache_Manager::CACHE_GROUP_IMAGES);
            wp_cache_delete('infyp_image_usage_' . $image_id, 'infyp');

            // Also clear the usage cache from the Image_Usage class
            if (class_exists('IPAIS\Core\INFYP_Image_Usage')) {
                INFYP_Image_Usage::clear_usage_cache($image_id);
            }

            // Also clear from cache manager
            $this->cache->delete('image_usage_' . $image_id, INFYP_Cache_Manager::CACHE_GROUP_IMAGES);

            // Always get fresh usage data (no caching for live usage modal)
            $usage = INFYP_Image_Usage::find_usage($image_id);

            // Generate HTML
            $html = '<div class="infyp-usage-details">';
            if (empty($usage)) {
                $html .= '<p>' . esc_html__('Not used in any posts or pages.', 'infy-ai-image-seo-optimizer') . '</p>';
            } else {
                $html .= '<ul class="infyp-usage-list">';
                foreach ($usage as $item) {
                    // Build post type badge
                    $type_badge = '<span class="infyp-badge infyp-badge-type">' . esc_html($item['type_label']) . '</span>';

                    // Build usage type badges
                    $usage_badges = '';
                    if (isset($item['usage_types']) && !empty($item['usage_types'])) {
                        foreach ($item['usage_types'] as $usage_type) {
                            $badge_class = 'infyp-badge infyp-badge-usage';

                            // Check for specific usage types and page builders
                            if ($usage_type === __('Featured Image', 'infy-ai-image-seo-optimizer')) {
                                $badge_class = 'infyp-badge infyp-badge-featured';
                            } elseif ($usage_type === __('In Content', 'infy-ai-image-seo-optimizer')) {
                                $badge_class = 'infyp-badge infyp-badge-content';
                            } elseif ($usage_type === __('Gutenberg', 'infy-ai-image-seo-optimizer')) {
                                $badge_class = 'infyp-badge infyp-badge-gutenberg';
                            } elseif ($usage_type === __('Elementor', 'infy-ai-image-seo-optimizer')) {
                                $badge_class = 'infyp-badge infyp-badge-elementor';
                            } elseif ($usage_type === __('Divi Builder', 'infy-ai-image-seo-optimizer')) {
                                $badge_class = 'infyp-badge infyp-badge-divi';
                            } elseif ($usage_type === __('WPBakery', 'infy-ai-image-seo-optimizer')) {
                                $badge_class = 'infyp-badge infyp-badge-wpbakery';
                            } elseif ($usage_type === __('Beaver Builder', 'infy-ai-image-seo-optimizer')) {
                                $badge_class = 'infyp-badge infyp-badge-beaver';
                            } elseif ($usage_type === __('Oxygen Builder', 'infy-ai-image-seo-optimizer')) {
                                $badge_class = 'infyp-badge infyp-badge-oxygen';
                            } elseif ($usage_type === __('Bricks Builder', 'infy-ai-image-seo-optimizer')) {
                                $badge_class = 'infyp-badge infyp-badge-bricks';
                            } elseif ($usage_type === __('Brizy', 'infy-ai-image-seo-optimizer')) {
                                $badge_class = 'infyp-badge infyp-badge-brizy';
                            } elseif ($usage_type === __('Page Builder', 'infy-ai-image-seo-optimizer')) {
                                $badge_class = 'infyp-badge infyp-badge-builder';
                            }

                            $usage_badges .= ' <span class="' . esc_attr($badge_class) . '">' . esc_html($usage_type) . '</span>';
                        }
                    }

                    // Use permalink for published posts, fall back to edit link
                    $link_url = (!empty($item['permalink']) && !is_wp_error($item['permalink'] ?? null)
                        && isset($item['status']) && $item['status'] === 'publish')
                        ? $item['permalink']
                        : $item['edit_link'];

                    $html .= sprintf(
                        '<li><a href="%1$s" target="_blank">%2$s</a> %3$s%4$s</li>',
                        esc_url($link_url),
                        esc_html($item['title']),
                        $type_badge,
                        $usage_badges
                    );
                }
                $html .= '</ul>';
            }
            $html .= '</div>';

            /**
             * Filter the image usage HTML before sending to frontend.
             *
             * @since 1.0.0
             * @param string $html     The usage HTML.
             * @param int    $image_id The attachment ID.
             * @param array  $usage    The raw usage data array.
             */
            $html = apply_filters('infyp_image_usage_html', $html, $image_id, $usage);
            $html = wp_kses_post( $html );

            // Don't cache the HTML - we always want fresh data for live usage
            wp_send_json_success(['html' => $html]);

        } catch (\Exception $e) {
            wp_send_json_error(['message' => esc_html__('Failed to retrieve image usage.', 'infy-ai-image-seo-optimizer')]);
        }
    }

    /**
     * AJAX handler for retrieving image data for SEO score breakdown.
     *
     * Returns alt text, title, filename, and keywords for a given attachment.
     *
     * @since 1.0.0
     *
     * @return void Sends JSON response with image data.
     */
    public function ajax_get_image_data_handler() {
        // Verify nonce
        if (!check_ajax_referer('infyp_ajax_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => esc_html__('Security check failed.', 'infy-ai-image-seo-optimizer')]);
        }

        // Check user capabilities
        if (!current_user_can('upload_files')) {
            wp_send_json_error(['message' => esc_html__('Insufficient permissions.', 'infy-ai-image-seo-optimizer')]);
        }

        // Validate required parameters
        $this->validate_parameters(['image_id']);

        $image_id = isset($_POST['image_id']) ? intval($_POST['image_id']) : 0;

        if (!$this->security->validate_attachment_id($image_id)) {
            wp_send_json_error(['message' => esc_html__('Invalid image ID.', 'infy-ai-image-seo-optimizer')]);
        }

        try {
            // Get image metadata
            $alt_text = get_post_meta($image_id, '_wp_attachment_image_alt', true);
            $attachment = get_post($image_id);

            if (!$attachment) {
                wp_send_json_error(['message' => esc_html__('Image not found.', 'infy-ai-image-seo-optimizer')]);
            }

            $file_path = get_attached_file($image_id);
            $filename = $file_path ? basename($file_path) : '';

            // Get keywords
            $keywords = get_post_meta($image_id, '_infyp_keywords', true);
            if (!is_array($keywords)) {
                $keywords = [];
            }

            $data = [
                'alt_text' => $alt_text,
                'title' => $attachment->post_title,
                'filename' => $filename,
                'keywords' => $keywords
            ];

            /**
             * Filter image data before sending to the frontend.
             *
             * @since 1.0.0
             * @param array $data     The image data array.
             * @param int   $image_id The attachment ID.
             */
            $data = apply_filters('infyp_image_data_response', $data, $image_id);

            wp_send_json_success(['data' => $data]);

        } catch (\Exception $e) {
            $this->error_handler->log_error('Failed to get image data', 'get_image_data', 'error', ['error' => $e->getMessage()]);
            wp_send_json_error(['message' => esc_html__('Failed to get image data.', 'infy-ai-image-seo-optimizer')]);
        }
    }

    /**
     * AJAX handler for updating an image filename.
     *
     * Renames the physical file and updates content references in posts.
     *
     * @since 1.0.0
     *
     * @return void Sends JSON response and exits.
     */
    public function ajax_update_filename_handler() {
        // Verify nonce
        if (!check_ajax_referer('infyp_ajax_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => esc_html__('Security check failed.', 'infy-ai-image-seo-optimizer')]);
        }

        // Check user capabilities
        if (!current_user_can('upload_files')) {
            wp_send_json_error(['message' => esc_html__('Insufficient permissions.', 'infy-ai-image-seo-optimizer')]);
        }

        // Validate required parameters
        $this->validate_parameters(['image_id', 'value']);

        $image_id = isset($_POST['image_id']) ? intval($_POST['image_id']) : 0;
        $new_filename_part = isset($_POST['value']) ? INFYP_File_Renamer::strip_file_extension( sanitize_file_name( wp_unslash( $_POST['value'] ) ) ) : '';

        // Validate
        if (!$this->security->validate_attachment_id($image_id)) {
            wp_send_json_error(['message' => esc_html__('Invalid image ID.', 'infy-ai-image-seo-optimizer')]);
        }

        if (!$this->security->validate_filename($new_filename_part)) {
            wp_send_json_error(['message' => esc_html__('Invalid filename provided.', 'infy-ai-image-seo-optimizer')]);
        }

        try {
            // Delegate the rename (file + content references + changelog + caches)
            // to the shared optimization service.
            $service = \IPAIS\Core\INFYP_Optimization_Service::get_instance();
            $apply   = $service->apply_field($image_id, 'filename', $new_filename_part);

            if (is_wp_error($apply)) {
                throw new \Exception(esc_html($apply->get_error_message()));
            }

            if (!empty($apply['unchanged'])) {
                wp_send_json_success([
                    'message' => esc_html__('Filename is the same, no changes made.', 'infy-ai-image-seo-optimizer'),
                    'new_filename' => $new_filename_part,
                    'image_id' => $image_id
                ]);
            }

            $updated_count = $apply['updated_posts'];

            $message = esc_html__('Filename updated successfully.', 'infy-ai-image-seo-optimizer');
            if ($updated_count > 0) {
                $message .= ' ' . sprintf(
                    /* translators: %d: Number of posts */
                    esc_html__('Updated in %d post(s).', 'infy-ai-image-seo-optimizer'),
                    $updated_count
                );
            }

            // Calculate new SEO score
            $seo_score = INFYP_SEO_Score_Calculator::calculate_score($image_id);

            wp_send_json_success([
                'message' => $message,
                'new_filename' => $new_filename_part,
                'seo_score' => $seo_score,
                'image_id' => $image_id,
                'updated_posts' => $updated_count
            ]);

        } catch (\Exception $e) {
            wp_send_json_error(['message' => esc_html($e->getMessage())]);
        }
    }

    /**
     * AJAX handler for getting fresh image URLs.
     *
     * Returns fresh (non-cached) image URLs and metadata for an attachment.
     * Used after filename changes or other operations that modify the image URL.
     *
     * @since 1.0.0
     * @return void Sends JSON response with fresh image data
     */
    public function ajax_get_fresh_image_url_handler() {
        // Verify nonce
        if (!check_ajax_referer('infyp_ajax_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => esc_html__('Security check failed.', 'infy-ai-image-seo-optimizer')]);
        }

        // Check user capabilities
        if (!current_user_can('upload_files')) {
            wp_send_json_error(['message' => esc_html__('Insufficient permissions.', 'infy-ai-image-seo-optimizer')]);
        }

        // Validate required parameters
        $this->validate_parameters(['image_id']);

        $image_id = isset($_POST['image_id']) ? intval($_POST['image_id']) : 0;

        if (!$this->security->validate_attachment_id($image_id)) {
            wp_send_json_error(['message' => esc_html__('Invalid image ID.', 'infy-ai-image-seo-optimizer')]);
        }

        try {
            // Get fresh attachment data
            $attachment = get_post($image_id);

            if (!$attachment) {
                wp_send_json_error(['message' => esc_html__('Image not found.', 'infy-ai-image-seo-optimizer')]);
            }

            // Get various image URLs
            $thumb_url = wp_get_attachment_thumb_url($image_id);
            $full_url = wp_get_attachment_url($image_id);
            $medium_url = wp_get_attachment_image_url($image_id, 'medium');
            $large_url = wp_get_attachment_image_url($image_id, 'large');

            // Get current filename
            $file_path = get_attached_file($image_id);
            $filename = $file_path ? pathinfo($file_path, PATHINFO_FILENAME) : '';

            // Get other metadata
            $alt_text = get_post_meta($image_id, '_wp_attachment_image_alt', true);
            $keywords = get_post_meta($image_id, '_infyp_keywords', true);

            // Clear any cached data for this image
            $this->cache->invalidate_image_cache($image_id);

            wp_send_json_success([
                'image_id' => $image_id,
                'urls' => [
                    'thumbnail' => $thumb_url,
                    'full' => $full_url,
                    'medium' => $medium_url ?: $full_url,
                    'large' => $large_url ?: $full_url,
                    'display' => $large_url ?: $medium_url ?: $full_url // Best URL for modal display
                ],
                'filename' => $filename,
                'title' => $attachment->post_title,
                'alt_text' => $alt_text,
                'keywords' => $keywords ?: [],
                'message' => esc_html__('Image data refreshed.', 'infy-ai-image-seo-optimizer')
            ]);

        } catch (\Exception $e) {
            $this->error_handler->log_error('Failed to get fresh image URL', 'get_fresh_image_url', 'error', ['error' => $e->getMessage()]);
            wp_send_json_error(['message' => esc_html__('Failed to get image data.', 'infy-ai-image-seo-optimizer')]);
        }
    }

    /**
     * AJAX handler for getting all image attachment IDs for bulk selection.
     *
     * Uses a direct database query for performance with large media libraries.
     * Results are cached for 5 minutes.
     *
     * @since 1.0.0
     *
     * @return void Sends JSON response with array of image IDs.
     */
    public function ajax_get_all_image_ids_handler() {
        // Security checks
        check_ajax_referer('infyp_ajax_nonce', 'nonce');

        if (!current_user_can('upload_files')) {
            wp_send_json_error(['message' => esc_html__('You do not have permission to access images.', 'infy-ai-image-seo-optimizer')]);
            return;
        }

        try {
            // Try to get from cache first
            $cache_key = 'infyp_all_image_ids';
            $cache_group = 'infyp_image_data';
            $image_ids = wp_cache_get($cache_key, $cache_group);

            if (false === $image_ids) {
                global $wpdb;

                // Use direct database query for better performance with large libraries
                // This avoids memory issues with get_posts() when posts_per_page is -1
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Performance optimization for large media libraries
                $image_ids = $wpdb->get_col(
                    $wpdb->prepare(
                        "SELECT ID FROM {$wpdb->posts}
                         WHERE post_type = %s
                         AND post_mime_type LIKE %s
                         AND post_status = %s
                         ORDER BY ID DESC",
                        'attachment',
                        'image/%',
                        'inherit'
                    )
                );

                // Cache for 5 minutes since image library changes frequently
                if (is_array($image_ids)) {
                    wp_cache_set($cache_key, $image_ids, $cache_group, 5 * MINUTE_IN_SECONDS);
                }
            }

            // Validate that we got an array of IDs
            if (!is_array($image_ids)) {
                wp_send_json_error(['message' => esc_html__('Failed to fetch image list.', 'infy-ai-image-seo-optimizer')]);
                return;
            }

            // Sanitize IDs to ensure they're integers
            $clean_ids = array_map('intval', $image_ids);
            $clean_ids = array_filter($clean_ids, function($id) {
                return $id > 0;
            });

            wp_send_json_success($clean_ids);

        } catch (\Exception $e) {
            // Check if error_handler exists before using it
            if (isset($this->error_handler) && method_exists($this->error_handler, 'log_error')) {
                $this->error_handler->log_error('Failed to get all image IDs', 'get_all_image_ids', 'error', ['error' => $e->getMessage()]);
            }
            wp_send_json_error(['message' => esc_html__('Failed to fetch image list.', 'infy-ai-image-seo-optimizer')]);
        }
    }
}
