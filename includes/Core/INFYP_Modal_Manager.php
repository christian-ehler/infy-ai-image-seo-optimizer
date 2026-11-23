<?php
/**
 * AJAX-driven modal loading and keyword display.
 *
 * Registers AJAX handlers that render modal HTML for filename editing,
 * alt text editing, title editing, image usage, SEO breakdown, keyword
 * management, and image preview. Each handler verifies nonces and
 * capabilities before returning rendered templates.
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
 * Class INFYP_Modal_Manager
 *
 * Handles modal loading and keyword display functionality.
 *
 * @since 1.0.0
 */
class INFYP_Modal_Manager {

    /**
     * Constructor
     */
    public function __construct() {
        add_action('wp_ajax_infyp_load_filename_modal', [$this, 'load_filename_modal']);
        add_action('wp_ajax_infyp_load_alt_text_modal', [$this, 'load_alt_text_modal']);
        add_action('wp_ajax_infyp_load_title_modal', [$this, 'load_title_modal']);
        add_action('wp_ajax_infyp_load_usage_modal', [$this, 'load_usage_modal']);
        add_action('wp_ajax_infyp_load_seo_breakdown_modal', [$this, 'load_seo_breakdown_modal']);
        add_action('wp_ajax_infyp_load_keywords_modal', [$this, 'load_keywords_modal']);
        add_action('wp_ajax_infyp_load_clear_keywords_modal', [$this, 'load_clear_keywords_modal']);
        add_action('wp_ajax_infyp_load_image_preview_modal', [$this, 'load_image_preview_modal']);
        add_action('wp_ajax_infyp_get_keywords_html', [$this, 'get_keywords_html']);
    }

    /**
     * Load filename modal HTML via AJAX
     */
    public function load_filename_modal() {
        // Verify nonce
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'] ?? '')), 'infyp_ajax_nonce')) {
            wp_send_json_error( [ 'message' => esc_html__( 'Security check failed.', 'infy-ai-image-seo-optimizer' ) ] );
            return;
        }

        if (!current_user_can('upload_files')) {
            wp_send_json_error( [ 'message' => esc_html__( 'Insufficient permissions.', 'infy-ai-image-seo-optimizer' ) ] );
            return;
        }

        $image_id = intval($_POST['image_id'] ?? 0);
        $current_filename = sanitize_text_field(wp_unslash($_POST['current_filename'] ?? ''));
        $image_url = esc_url_raw(wp_unslash($_POST['image_url'] ?? ''));

        if (!$image_id) {
            wp_send_json_error( [ 'message' => esc_html__( 'Invalid image ID.', 'infy-ai-image-seo-optimizer' ) ] );
            return;
        }

        // Start output buffering
        ob_start();

        // Include the modal template
        include INFYP_PLUGIN_DIR . 'includes/views/modals/filename-modal.php';

        $modal_html = ob_get_clean();

        // Get keywords for this image
        $keywords_html = $this->get_keywords_display_html($image_id, $current_filename, true);

        wp_send_json_success([
            'modal_html' => $modal_html,
            'keywords_html' => $keywords_html,
            'image_id' => $image_id,
            'current_filename' => $current_filename,
            'image_url' => $image_url
        ]);
    }

    /**
     * Load alt text modal HTML via AJAX
     */
    public function load_alt_text_modal() {
        // Verify nonce
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'] ?? '')), 'infyp_ajax_nonce')) {
            wp_send_json_error( [ 'message' => esc_html__( 'Security check failed.', 'infy-ai-image-seo-optimizer' ) ] );
            return;
        }

        if (!current_user_can('upload_files')) {
            wp_send_json_error( [ 'message' => esc_html__( 'Insufficient permissions.', 'infy-ai-image-seo-optimizer' ) ] );
            return;
        }

        $image_id = intval($_POST['image_id'] ?? 0);
        $current_alt_text = sanitize_textarea_field(wp_unslash($_POST['current_alt_text'] ?? ''));
        $image_url = esc_url_raw(wp_unslash($_POST['image_url'] ?? ''));

        if (!$image_id) {
            wp_send_json_error( [ 'message' => esc_html__( 'Invalid image ID.', 'infy-ai-image-seo-optimizer' ) ] );
            return;
        }

        // Start output buffering
        ob_start();

        // Include the modal template
        include INFYP_PLUGIN_DIR . 'includes/views/modals/alt-text-modal.php';

        $modal_html = ob_get_clean();

        // Get keywords for this image
        $keywords_html = $this->get_keywords_display_html($image_id, $current_alt_text, false);

        wp_send_json_success([
            'modal_html' => $modal_html,
            'keywords_html' => $keywords_html,
            'image_id' => $image_id,
            'current_alt_text' => $current_alt_text,
            'image_url' => $image_url
        ]);
    }

    /**
     * Load title modal HTML via AJAX
     */
    public function load_title_modal() {
        // Verify nonce
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'] ?? '')), 'infyp_ajax_nonce')) {
            wp_send_json_error( [ 'message' => esc_html__( 'Security check failed.', 'infy-ai-image-seo-optimizer' ) ] );
            return;
        }

        if (!current_user_can('upload_files')) {
            wp_send_json_error( [ 'message' => esc_html__( 'Insufficient permissions.', 'infy-ai-image-seo-optimizer' ) ] );
            return;
        }

        $image_id = intval($_POST['image_id'] ?? 0);
        $current_title = sanitize_text_field(wp_unslash($_POST['current_title'] ?? ''));
        $image_url = esc_url_raw(wp_unslash($_POST['image_url'] ?? ''));

        if (!$image_id) {
            wp_send_json_error( [ 'message' => esc_html__( 'Invalid image ID.', 'infy-ai-image-seo-optimizer' ) ] );
            return;
        }

        // Start output buffering
        ob_start();

        // Include the modal template
        include INFYP_PLUGIN_DIR . 'includes/views/modals/title-modal.php';

        $modal_html = ob_get_clean();

        // Get keywords for this image
        $keywords_html = $this->get_keywords_display_html($image_id, $current_title, false);

        wp_send_json_success([
            'modal_html' => $modal_html,
            'keywords_html' => $keywords_html,
            'image_id' => $image_id,
            'current_title' => $current_title,
            'image_url' => $image_url
        ]);
    }

    /**
     * Load usage modal HTML via AJAX
     */
    public function load_usage_modal() {
        // Verify nonce
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'] ?? '')), 'infyp_ajax_nonce')) {
            wp_send_json_error( [ 'message' => esc_html__( 'Security check failed.', 'infy-ai-image-seo-optimizer' ) ] );
            return;
        }

        if (!current_user_can('upload_files')) {
            wp_send_json_error( [ 'message' => esc_html__( 'Insufficient permissions.', 'infy-ai-image-seo-optimizer' ) ] );
            return;
        }

        $image_id = intval($_POST['image_id'] ?? 0);
        $usage_html = wp_kses_post(wp_unslash($_POST['usage_html'] ?? ''));
        $image_url = esc_url_raw(wp_unslash($_POST['image_url'] ?? ''));

        if (!$image_id) {
            wp_send_json_error( [ 'message' => esc_html__( 'Invalid image ID.', 'infy-ai-image-seo-optimizer' ) ] );
            return;
        }

        // Start output buffering
        ob_start();

        // Include the modal template
        include INFYP_PLUGIN_DIR . 'includes/views/modals/usage-modal.php';

        $modal_html = ob_get_clean();

        wp_send_json_success([
            'modal_html' => $modal_html,
            'usage_html' => $usage_html,
            'image_id' => $image_id,
            'image_url' => $image_url
        ]);
    }

    /**
     * Load SEO breakdown modal HTML via AJAX
     */
    public function load_seo_breakdown_modal() {
        // Verify nonce
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'] ?? '')), 'infyp_ajax_nonce')) {
            wp_send_json_error( [ 'message' => esc_html__( 'Security check failed.', 'infy-ai-image-seo-optimizer' ) ] );
            return;
        }

        if (!current_user_can('upload_files')) {
            wp_send_json_error( [ 'message' => esc_html__( 'Insufficient permissions.', 'infy-ai-image-seo-optimizer' ) ] );
            return;
        }

        $image_id = intval($_POST['image_id'] ?? 0);

        if (!$image_id) {
            wp_send_json_error( [ 'message' => esc_html__( 'Invalid image ID.', 'infy-ai-image-seo-optimizer' ) ] );
            return;
        }

        // Start output buffering
        ob_start();

        // Include the modal template
        include INFYP_PLUGIN_DIR . 'includes/views/modals/seo-breakdown-modal.php';

        $modal_html = ob_get_clean();

        wp_send_json_success([
            'modal_html' => $modal_html,
            'image_id' => $image_id
        ]);
    }

    /**
     * Load keywords modal HTML via AJAX
     */
    public function load_keywords_modal() {
        // Verify nonce
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'] ?? '')), 'infyp_ajax_nonce')) {
            wp_send_json_error( [ 'message' => esc_html__( 'Security check failed.', 'infy-ai-image-seo-optimizer' ) ] );
            return;
        }

        if (!current_user_can('upload_files')) {
            wp_send_json_error( [ 'message' => esc_html__( 'Insufficient permissions.', 'infy-ai-image-seo-optimizer' ) ] );
            return;
        }

        $image_id = intval($_POST['image_id'] ?? 0);
        $current_keywords = isset($_POST['current_keywords']) ? array_map('sanitize_text_field', wp_unslash($_POST['current_keywords'])) : [];

        if (!$image_id) {
            wp_send_json_error( [ 'message' => esc_html__( 'Invalid image ID.', 'infy-ai-image-seo-optimizer' ) ] );
            return;
        }

        // Start output buffering
        ob_start();

        // Include the modal template
        include INFYP_PLUGIN_DIR . 'includes/views/modals/keywords-modal.php';

        $modal_html = ob_get_clean();

        wp_send_json_success([
            'modal_html' => $modal_html,
            'image_id' => $image_id,
            'current_keywords' => $current_keywords
        ]);
    }

    /**
     * Load clear keywords confirmation modal HTML via AJAX
     */
    public function load_clear_keywords_modal() {
        // Verify nonce
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'] ?? '')), 'infyp_ajax_nonce')) {
            wp_send_json_error( [ 'message' => esc_html__( 'Security check failed.', 'infy-ai-image-seo-optimizer' ) ] );
            return;
        }

        if (!current_user_can('upload_files')) {
            wp_send_json_error( [ 'message' => esc_html__( 'Insufficient permissions.', 'infy-ai-image-seo-optimizer' ) ] );
            return;
        }

        $image_id = intval($_POST['image_id'] ?? 0);

        if (!$image_id) {
            wp_send_json_error( [ 'message' => esc_html__( 'Invalid image ID.', 'infy-ai-image-seo-optimizer' ) ] );
            return;
        }

        // Start output buffering
        ob_start();

        // Include the modal template
        include INFYP_PLUGIN_DIR . 'includes/views/modals/clear-keywords-modal.php';

        $modal_html = ob_get_clean();

        wp_send_json_success([
            'modal_html' => $modal_html,
            'image_id' => $image_id
        ]);
    }

    /**
     * Load image preview modal HTML via AJAX
     */
    public function load_image_preview_modal() {
        // Verify nonce
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'] ?? '')), 'infyp_ajax_nonce')) {
            wp_send_json_error( [ 'message' => esc_html__( 'Security check failed.', 'infy-ai-image-seo-optimizer' ) ] );
            return;
        }

        if (!current_user_can('upload_files')) {
            wp_send_json_error( [ 'message' => esc_html__( 'Insufficient permissions.', 'infy-ai-image-seo-optimizer' ) ] );
            return;
        }

        $image_id = intval($_POST['image_id'] ?? 0);
        $image_url = esc_url_raw(wp_unslash($_POST['image_url'] ?? ''));

        if (!$image_id) {
            wp_send_json_error( [ 'message' => esc_html__( 'Invalid image ID.', 'infy-ai-image-seo-optimizer' ) ] );
            return;
        }

        $edit_link = get_edit_post_link($image_id, 'raw');

        // Start output buffering
        ob_start();

        // Include the modal template
        include INFYP_PLUGIN_DIR . 'includes/views/modals/image-preview-modal.php';

        $modal_html = ob_get_clean();

        wp_send_json_success([
            'modal_html' => $modal_html,
            'image_id' => $image_id,
            'image_url' => $image_url,
            'edit_link' => $edit_link
        ]);
    }

    /**
     * Get keywords HTML via AJAX
     */
    public function get_keywords_html() {
        // Verify nonce
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'] ?? '')), 'infyp_ajax_nonce')) {
            wp_send_json_error( [ 'message' => esc_html__( 'Security check failed.', 'infy-ai-image-seo-optimizer' ) ] );
            return;
        }

        if (!current_user_can('upload_files')) {
            wp_send_json_error( [ 'message' => esc_html__( 'Insufficient permissions.', 'infy-ai-image-seo-optimizer' ) ] );
            return;
        }

        $image_id = intval($_POST['image_id'] ?? 0);
        $current_value = sanitize_text_field(wp_unslash($_POST['current_value'] ?? ''));
        $is_filename = filter_var(wp_unslash($_POST['is_filename'] ?? false), FILTER_VALIDATE_BOOLEAN);

        if (!$image_id) {
            wp_send_json_error( [ 'message' => esc_html__( 'Invalid image ID.', 'infy-ai-image-seo-optimizer' ) ] );
            return;
        }

        $keywords_html = $this->get_keywords_display_html($image_id, $current_value, $is_filename);

        wp_send_json_success([
            'keywords_html' => $keywords_html
        ]);
    }

    /**
     * Generate keywords display HTML
     *
     * @param int $image_id
     * @param string $current_value
     * @param bool $is_filename
     * @return string
     */
    public function get_keywords_display_html($image_id, $current_value, $is_filename = false) {
        // Get keywords from image metadata
        $keywords = get_post_meta($image_id, '_infyp_keywords', true);

        if (!is_array($keywords) || empty($keywords)) {
            return '';
        }

        $keyword_badges = '';
        foreach ($keywords as $keyword) {
            $keyword = sanitize_text_field($keyword);
            $is_used = $this->check_keyword_usage($current_value, $keyword, $is_filename);
            $status_class = $is_used ? 'keyword-used' : 'keyword-unused';
            $icon = $is_used ? 'yes-alt' : 'warning';

            $keyword_badges .= sprintf(
                '<span class="infyp-modal-keyword-badge %s">
                    <span class="dashicons dashicons-%s"></span>
                    %s
                </span>',
                esc_attr($status_class),
                esc_attr($icon),
                esc_html($keyword)
            );
        }

        return sprintf(
            '<div class="infyp-modal-keywords-display">
                <div class="infyp-modal-keywords-header">
                    <span class="infyp-modal-keywords-label">%s</span>
                </div>
                <div class="infyp-modal-keywords-list">
                    %s
                </div>
                <div class="infyp-modal-keywords-hint">
                    %s
                </div>
            </div>',
            esc_html__('Keyword usage', 'infy-ai-image-seo-optimizer'),
            $keyword_badges,
            esc_html__('Include keywords naturally in your text for better SEO', 'infy-ai-image-seo-optimizer')
        );
    }

    /**
     * Check if keyword is used in text
     *
     * @param string $text
     * @param string $keyword
     * @param bool $is_filename
     * @return bool
     */
    private function check_keyword_usage($text, $keyword, $is_filename = false) {
        if (empty($text) || empty($keyword)) {
            return false;
        }

        $text = strtolower($text);
        $keyword = strtolower($keyword);

        if ($is_filename) {
            // For filenames, replace hyphens with spaces for better matching
            $text = str_replace('-', ' ', $text);
        }

        // Check if keyword appears as whole words
        return preg_match('/\b' . preg_quote($keyword, '/') . '\b/', $text) === 1;
    }
}