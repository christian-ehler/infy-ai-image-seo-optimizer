<?php
/**
 * AI AJAX Handlers - AI Optimization and Keyword Operations
 *
 * Domain-specific AJAX handler extracted from INFYP_Ajax_Handlers.
 * Handles all AI optimization and keyword-related AJAX operations including:
 *
 * AI Operations:
 * - Full AI optimization of images
 * - Single field optimization (filename, alt_text, title)
 * - SEO score testing and breakdown
 *
 * Keyword Operations:
 * - AI keyword generation
 * - Manual keyword updates
 * - Keyword retrieval and clearing
 *
 * Security Features:
 * - Nonce verification for all requests
 * - Capability checks (upload_files, manage_options)
 * - Input sanitization and validation
 * - Memory checks before AI operations
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

use \IPAIS\Core\INFYP_AI_Optimizer;
use \IPAIS\Core\INFYP_Security_Manager;
use \IPAIS\Core\INFYP_Cache_Manager;
use \IPAIS\Core\INFYP_Error_Handler;
use \IPAIS\Core\INFYP_Memory_Manager;
use \IPAIS\Core\INFYP_SEO_Score_Calculator;
use \IPAIS\Core\INFYP_Field_Validator;

/**
 * AI AJAX Handlers Class
 *
 * Handles all AI optimization and keyword-related AJAX operations.
 * Registers action hooks, validates requests, and delegates to appropriate
 * handlers with consistent security and error handling.
 *
 * Registered AJAX Actions (wp_ajax_*):
 * - infyp_optimize_with_ai: AI optimization request
 * - infyp_optimize_single_field: Optimize one field
 * - infyp_generate_keywords: AI keyword generation
 * - infyp_update_keywords: Save image keywords
 * - infyp_get_image_keywords: Get existing keywords
 * - infyp_clear_keywords: Clear keywords from image
 * - infyp_get_seo_score_breakdown: Get detailed scores
 *
 * @package   IPAIS\Ajax
 * @since 1.0.0
 *
 * @see       INFYP_Security_Manager For nonce and capability checks
 * @see       INFYP_Error_Handler For error logging
 */
class INFYP_AI_Ajax {

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
     * Register all AI-related AJAX action handlers.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function register() {
        $handlers = [
            'infyp_optimize_with_ai' => 'ajax_optimize_with_ai_handler',
            'infyp_optimize_single_field' => 'ajax_optimize_single_field_handler',
            'infyp_generate_keywords' => 'ajax_generate_keywords_handler',
            'infyp_update_keywords' => 'ajax_update_keywords_handler',
            'infyp_get_image_keywords' => 'ajax_get_image_keywords_handler',
            'infyp_clear_keywords' => 'ajax_clear_keywords_handler',
            'infyp_get_seo_score_breakdown' => 'ajax_get_seo_score_breakdown_handler',
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
     * AJAX handler for AI-powered image optimization.
     *
     * Processes a single image through the AI optimization pipeline,
     * generating SEO-friendly metadata and updating the image accordingly.
     *
     * @since 1.0.0
     * @return void Sends JSON response and exits.
     */
    public function ajax_optimize_with_ai_handler() {
        try {
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
                return;
            }

            if (!current_user_can('edit_post', $image_id)) {
                wp_send_json_error(['message' => esc_html__('You do not have permission to edit this image.', 'infy-ai-image-seo-optimizer')]);
                return;
            }

            // Delegate the generate-and-persist pipeline to the shared service.
            $service = \IPAIS\Core\INFYP_Optimization_Service::get_instance();

            $run = $service->run($image_id, [
                // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
                'custom_prompt'  => isset( $_POST['custom_prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['custom_prompt'] ) ) : '',
                // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
                'clear_keywords' => isset($_POST['clear_keywords']) && $_POST['clear_keywords'] === '1',
            ]);

            if (is_wp_error($run)) {
                throw new \Exception($run->get_error_message());
            }

            $new_data = $run['new_data'];

            // Calculate new SEO score
            $seo_score = INFYP_SEO_Score_Calculator::calculate_score($image_id);

            // Get validation feedback for each field
            $keywords = get_post_meta($image_id, '_infyp_keywords', true);
            $validations = [
                'alt_text' => INFYP_Field_Validator::validate_alt_text($new_data['alt_text'], $keywords),
                'title' => INFYP_Field_Validator::validate_title($new_data['title'], $new_data['alt_text'], $keywords),
                'filename' => INFYP_Field_Validator::validate_filename($new_data['filename'], $keywords)
            ];

            // Get dynamic parameters for character counts
            $params = INFYP_SEO_Score_Calculator::get_scoring_params();

            // Include keywords in the response
            if (!empty($new_data['keywords'])) {
                $response_data = $new_data;
            } else {
                // If keywords weren't in new_data, get them from the database
                $response_data = $new_data;
                if (!empty($keywords)) {
                    $response_data['keywords'] = $keywords;
                }
            }

            wp_send_json_success([
                'newData' => $response_data,
                'message' => esc_html__('Optimization successful.', 'infy-ai-image-seo-optimizer'),
                'seo_score' => $seo_score,
                'validations' => $validations,
                'scoring_params' => $params
            ]);

        } catch (\Throwable $e) {
            wp_send_json_error(['message' => esc_html__('AI optimization failed: ', 'infy-ai-image-seo-optimizer') . esc_html($e->getMessage())]);
        }
    }

    /**
     * AJAX handler for single field AI optimization
     *
     * Optimizes a single field (filename, alt_text, or title) using AI,
     * with support for existing keywords or prompting for keyword generation.
     *
     * @since 1.0.0
     * @return void Sends JSON response and exits.
     */
    public function ajax_optimize_single_field_handler() {
        // Debug: Log that we reached the handler
        // error_log('[Single Field Handler] Started - Field type: ' . ($_POST['field_type'] ?? 'not set'));

        try {
            // Verify nonce
            if (!check_ajax_referer('infyp_ajax_nonce', 'nonce', false)) {
                // error_log('[Single Field Handler] Nonce verification failed');
                wp_send_json_error(['message' => esc_html__('Security check failed.', 'infy-ai-image-seo-optimizer')]);
                return;
            }

            // Check user capabilities
            if (!current_user_can('upload_files')) {
                // error_log('[Single Field Handler] User lacks upload_files capability');
                wp_send_json_error(['message' => esc_html__('Insufficient permissions.', 'infy-ai-image-seo-optimizer')]);
                return;
            }

            // error_log('[Single Field Handler] User capabilities OK');

            // Validate required parameters
            $this->validate_parameters(['image_id', 'field_type']);

            // error_log('[Single Field Handler] Parameters validated');

            $image_id = isset($_POST['image_id']) ? intval($_POST['image_id']) : 0;
            $field_type = isset($_POST['field_type']) ? sanitize_text_field(wp_unslash($_POST['field_type'])) : '';

            // error_log('[Single Field Handler] Image ID: ' . $image_id . ', Field type: ' . $field_type);

            // Validate field type - extensible via filter for add-ons
            $allowed_field_types = apply_filters('infyp_allowed_optimize_field_types', ['filename', 'alt_text', 'title']);
            if (!in_array($field_type, $allowed_field_types, true)) {
                wp_send_json_error(['message' => esc_html__('Invalid field type specified.', 'infy-ai-image-seo-optimizer')]);
                return;
            }

            if (!$this->security->validate_attachment_id($image_id)) {
                wp_send_json_error(['message' => esc_html__('Invalid image ID.', 'infy-ai-image-seo-optimizer')]);
                return;
            }

            if (!current_user_can('edit_post', $image_id)) {
                wp_send_json_error(['message' => esc_html__('You do not have permission to edit this image.', 'infy-ai-image-seo-optimizer')]);
                return;
            }

            // Delegate generate-and-persist to the shared service. The legacy
            // admin flow expects the filename to only be PROPOSED here — the
            // rename itself is confirmed by the user via infyp_update_filename.
            $service = \IPAIS\Core\INFYP_Optimization_Service::get_instance();

            $service_args = [
                'skip_filename_apply' => true,
                // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
                'custom_prompt'       => isset( $_POST['custom_prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['custom_prompt'] ) ) : '',
            ];

            // Check if keywords were passed from frontend
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
            if (!empty($_POST['keywords']) && is_array($_POST['keywords'])) {
                // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above; sanitized via array_map below.
                $service_args['keywords'] = array_map('sanitize_text_field', wp_unslash($_POST['keywords']));
            }

            $field_run = $service->run_field($image_id, $field_type, $service_args);

            if (is_wp_error($field_run)) {
                wp_send_json_error(['message' => esc_html($field_run->get_error_message())]);
                return;
            }

            $new_value         = $field_run['new_value'];
            $current_value     = $field_run['old_value'];
            $existing_keywords = $field_run['keywords_used'];

            // Get scoring parameters and validation for the new value
            $validation = null;
            // error_log('[Single Field Handler] Getting scoring params...');
            $params = INFYP_SEO_Score_Calculator::get_scoring_params();
            // error_log('[Single Field Handler] Scoring params retrieved: ' . print_r($params, true));

            // error_log('[Single Field Handler] About to validate field: ' . $field_type . ' with value: ' . $new_value);

            try {
                if (!class_exists('\IPAIS\Core\INFYP_Field_Validator')) {
                    // error_log('[Single Field Handler] Warning: Field Validator class not found, skipping validation');
                    $validation = null;
                } else {
                    switch ($field_type) {
                        case 'filename':
                            $validation = INFYP_Field_Validator::validate_filename($new_value, $existing_keywords);
                            break;
                        case 'alt_text':
                            // error_log('[Single Field Handler] Calling validate_alt_text...');
                            $validation = INFYP_Field_Validator::validate_alt_text($new_value, $existing_keywords);
                            // error_log('[Single Field Handler] validate_alt_text returned: ' . print_r($validation, true));
                            break;
                        case 'title':
                            $alt_text = get_post_meta($image_id, '_wp_attachment_image_alt', true);
                            $validation = INFYP_Field_Validator::validate_title($new_value, $alt_text, $existing_keywords);
                            break;
                    }
                }
            } catch (\Exception $e) {
                // error_log('[Single Field Handler] Validation error: ' . $e->getMessage());
                $validation = null;
            }

            // Caches were already invalidated by the service.

            // Calculate new SEO score
            // error_log('[Single Field Handler] Calculating SEO score...');
            $seo_score = INFYP_SEO_Score_Calculator::calculate_score($image_id);
            // error_log('[Single Field Handler] SEO score calculated');

            // Prepare scoring params based on field type
            $field_params = [];
            if ($field_type === 'alt_text' && isset($params['alt_text'])) {
                $field_params = $params['alt_text'];
            } elseif ($field_type === 'filename' && isset($params['filename'])) {
                $field_params = $params['filename'];
            } elseif ($field_type === 'title' && isset($params['title'])) {
                $field_params = $params['title'];
            }

            wp_send_json_success([
                'field_type' => $field_type,
                'optimized_value' => $new_value,  // Use optimized_value to match frontend expectation
                'new_value' => $new_value,
                'old_value' => $current_value,
                'validation' => $validation,
                'scoring_params' => $field_params,
                'seo_score' => $seo_score,
                'keywords_used' => $existing_keywords,
                'message' => sprintf(
                    /* translators: %s: field name */
                    esc_html__('%s optimized successfully.', 'infy-ai-image-seo-optimizer'),
                    ucfirst(str_replace('_', ' ', $field_type))
                )
            ]);

        } catch (\Exception $e) {
            // error_log('[Single Field Handler Exception] Field: ' . ($field_type ?? 'unknown') . ', Message: ' . $e->getMessage());
            // error_log('[Single Field Handler Exception Trace] ' . $e->getTraceAsString());
            wp_send_json_error(['message' => esc_html($e->getMessage())]);
        }
    }

    /**
     * Generate keywords using AI via AJAX
     *
     * @since 1.0.0
     */
    public function ajax_generate_keywords_handler() {
        try {
            // Verify nonce
            if (!check_ajax_referer('infyp_ajax_nonce', 'nonce', false)) {
                wp_send_json_error(['message' => esc_html__('Security check failed.', 'infy-ai-image-seo-optimizer')]);
            }

            // Check capabilities
            if (!current_user_can('upload_files')) {
                wp_send_json_error(['message' => esc_html__('You do not have permission to perform this action.', 'infy-ai-image-seo-optimizer')]);
            }

            // Get image ID
            $image_id = isset($_POST['image_id']) ? absint($_POST['image_id']) : 0;

            if (!$image_id) {
                wp_send_json_error(['message' => esc_html__('Invalid image ID.', 'infy-ai-image-seo-optimizer')]);
            }

            // Check if image exists
            if (get_post_type($image_id) !== 'attachment') {
                wp_send_json_error(['message' => esc_html__('Invalid image.', 'infy-ai-image-seo-optimizer')]);
            }

            if (!current_user_can('edit_post', $image_id)) {
                wp_send_json_error(['message' => esc_html__('You do not have permission to edit this image.', 'infy-ai-image-seo-optimizer')]);
            }

            // Get image URL
            $image_url = wp_get_attachment_url($image_id);
            if (!$image_url) {
                wp_send_json_error(['message' => esc_html__('Could not get image URL.', 'infy-ai-image-seo-optimizer')]);
            }

            // Check memory before processing
            if (!INFYP_Memory_Manager::has_sufficient_memory(50)) {
                throw new \Exception( esc_html__('Insufficient memory for keyword generation. Please try again later.', 'infy-ai-image-seo-optimizer') );
            }

            // Read custom prompt if provided
            $custom_prompt = isset( $_POST['custom_prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['custom_prompt'] ) ) : '';

            // Generate keywords only using AI
            $result = INFYP_AI_Optimizer::generate_keywords_only($image_id, $custom_prompt);

            if (is_wp_error($result)) {
                throw new \Exception($result->get_error_message());
            }

            // Extract keywords from result
            $keywords = isset($result['keywords']) ? $result['keywords'] : [];

            /**
             * Filter the maximum number of keywords allowed per image.
             *
             * @since 1.0.0
             * @param int $max_keywords Default maximum keywords. Default 3.
             */
            $max_keywords = apply_filters('infyp_max_keywords', 3);

            // Sanitize and limit keywords
            $sanitized_keywords = [];
            foreach ($keywords as $keyword) {
                $keyword = sanitize_text_field($keyword);
                $keyword = trim($keyword);
                if (!empty($keyword) && count($sanitized_keywords) < $max_keywords) {
                    $sanitized_keywords[] = $keyword;
                }
            }

            /**
             * Filter AI-generated keywords before saving.
             *
             * @since 1.0.0
             * @param array $keywords  The generated keywords.
             * @param int   $image_id  The attachment ID.
             */
            $sanitized_keywords = apply_filters('infyp_generated_keywords', $sanitized_keywords, $image_id);

            // Save keywords
            update_post_meta($image_id, '_infyp_keywords', $sanitized_keywords);
            update_post_meta($image_id, '_infyp_keywords_manual', false);

            // Calculate new SEO score
            $seo_score = INFYP_SEO_Score_Calculator::calculate_score($image_id);

            // Clear caches
            $this->cache->invalidate_image_cache($image_id);
            clean_attachment_cache($image_id);

            wp_send_json_success([
                'keywords' => $sanitized_keywords,
                'seo_score' => $seo_score,
                'message' => esc_html__('Keywords generated successfully.', 'infy-ai-image-seo-optimizer')
            ]);

        } catch (\Exception $e) {
            wp_send_json_error(['message' => esc_html($e->getMessage())]);
        }
    }

    /**
     * Handle keyword update via AJAX
     *
     * @since 1.0.0
     */
    public function ajax_update_keywords_handler() {
        // Verify nonce
        if (!check_ajax_referer('infyp_ajax_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => esc_html__('Security check failed.', 'infy-ai-image-seo-optimizer')]);
        }

        // Check capabilities
        if (!current_user_can('upload_files')) {
            wp_send_json_error(['message' => esc_html__('You do not have permission to perform this action.', 'infy-ai-image-seo-optimizer')]);
        }

        // Get and validate parameters
        $image_id = isset($_POST['image_id']) ? absint($_POST['image_id']) : 0;
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitization happens in the loop below
        $keywords = isset($_POST['keywords']) ? $_POST['keywords'] : [];

        if (!$image_id) {
            wp_send_json_error(['message' => esc_html__('Invalid image ID.', 'infy-ai-image-seo-optimizer')]);
        }

        if (!current_user_can('edit_post', $image_id)) {
            wp_send_json_error(['message' => esc_html__('You do not have permission to edit this image.', 'infy-ai-image-seo-optimizer')]);
        }

        /** This filter is documented in includes/Ajax/INFYP_AI_Ajax.php */
        $max_keywords = apply_filters('infyp_max_keywords', 3);

        // Sanitize and validate keywords
        $sanitized_keywords = [];
        if (is_array($keywords)) {
            foreach ($keywords as $keyword) {
                $keyword = sanitize_text_field(wp_unslash($keyword));
                $keyword = trim($keyword);

                // Validate keyword length (3-30 characters) - use mb_strlen for UTF-8 support
                $keyword_length = function_exists('mb_strlen') ? mb_strlen($keyword, 'UTF-8') : strlen($keyword);
                if ($keyword_length >= 3 && $keyword_length <= 30) {
                    $sanitized_keywords[] = $keyword;
                }

                // Max keywords
                if (count($sanitized_keywords) >= $max_keywords) {
                    break;
                }
            }
        }

        // Update keywords
        update_post_meta($image_id, '_infyp_keywords', $sanitized_keywords);

        // Mark as manually edited if keywords were added, or reset flag if removed
        if (!empty($sanitized_keywords)) {
            update_post_meta($image_id, '_infyp_keywords_manual', true);
        } else {
            // If all keywords removed, reset manual flag so AI can generate new ones
            update_post_meta($image_id, '_infyp_keywords_manual', false);
        }

        // Calculate new SEO score
        $seo_score = INFYP_SEO_Score_Calculator::calculate_score($image_id);

        // Clear caches
        $this->cache->invalidate_image_cache($image_id);
        clean_attachment_cache($image_id);

        wp_send_json_success([
            'keywords' => $sanitized_keywords,
            'seo_score' => $seo_score,
            'message' => esc_html__('Keywords updated successfully.', 'infy-ai-image-seo-optimizer')
        ]);
    }

    /**
     * AJAX handler for getting existing keywords for an image.
     *
     * Returns existing keywords without generating new ones.
     * Used to check if keywords exist before field optimization.
     *
     * @since 1.0.0
     * @return void Sends JSON response and exits.
     */
    public function ajax_get_image_keywords_handler() {
        try {
            // Verify nonce
            if (!check_ajax_referer('infyp_ajax_nonce', 'nonce', false)) {
                wp_send_json_error(['message' => esc_html__('Security check failed.', 'infy-ai-image-seo-optimizer')]);
                return;
            }

            // Check capabilities
            if (!current_user_can('upload_files')) {
                wp_send_json_error(['message' => esc_html__('You do not have permission to perform this action.', 'infy-ai-image-seo-optimizer')]);
                return;
            }

            // Get image ID
            $image_id = isset($_POST['image_id']) ? absint($_POST['image_id']) : 0;

            if (!$image_id) {
                wp_send_json_error(['message' => esc_html__('Invalid image ID.', 'infy-ai-image-seo-optimizer')]);
                return;
            }

            // Check if image exists
            if (get_post_type($image_id) !== 'attachment') {
                wp_send_json_error(['message' => esc_html__('Invalid image.', 'infy-ai-image-seo-optimizer')]);
                return;
            }

            if (!current_user_can('edit_post', $image_id)) {
                wp_send_json_error(['message' => esc_html__('You do not have permission to edit this image.', 'infy-ai-image-seo-optimizer')]);
                return;
            }

            // Get existing keywords
            $keywords = get_post_meta($image_id, '_infyp_keywords', true);

            if (!empty($keywords) && is_array($keywords)) {
                wp_send_json_success([
                    'keywords' => $keywords,
                    'has_keywords' => true
                ]);
            } else {
                wp_send_json_success([
                    'keywords' => null,
                    'has_keywords' => false
                ]);
            }

        } catch (\Exception $e) {
            wp_send_json_error(['message' => esc_html($e->getMessage())]);
        }
    }

    /**
     * AJAX handler for clearing keywords from an image.
     *
     * Removes all keywords and the manual flag, then recalculates SEO score.
     *
     * @since 1.0.0
     * @return void Sends JSON response and exits.
     */
    public function ajax_clear_keywords_handler() {
        try {
            // Verify nonce
            if (!check_ajax_referer('infyp_ajax_nonce', 'nonce', false)) {
                wp_send_json_error(['message' => esc_html__('Security check failed.', 'infy-ai-image-seo-optimizer')]);
                return;
            }

            // Check user capabilities
            if (!current_user_can('upload_files')) {
                wp_send_json_error(['message' => esc_html__('Insufficient permissions.', 'infy-ai-image-seo-optimizer')]);
                return;
            }

            // Get and validate image ID
            $image_id = isset($_POST['image_id']) ? intval($_POST['image_id']) : 0;

            if (!$image_id || !wp_attachment_is_image($image_id)) {
                wp_send_json_error(['message' => esc_html__('Invalid image ID.', 'infy-ai-image-seo-optimizer')]);
                return;
            }

            if (!current_user_can('edit_post', $image_id)) {
                wp_send_json_error(['message' => esc_html__('You do not have permission to edit this image.', 'infy-ai-image-seo-optimizer')]);
                return;
            }

            // Clear keywords meta
            delete_post_meta($image_id, '_infyp_keywords');
            delete_post_meta($image_id, '_infyp_keywords_manual');

            // Calculate new SEO score
            $seo_score = INFYP_SEO_Score_Calculator::calculate_score($image_id);

            // Clear caches
            $this->cache->invalidate_image_cache($image_id);
            clean_attachment_cache($image_id);

            wp_send_json_success([
                'keywords' => [],
                'seo_score' => $seo_score,
                'message' => esc_html__('Keywords cleared successfully.', 'infy-ai-image-seo-optimizer')
            ]);

        } catch (\Exception $e) {
            wp_send_json_error(['message' => esc_html($e->getMessage())]);
        }
    }

    /**
     * AJAX handler for getting a detailed SEO score breakdown for an image.
     *
     * Returns component scores, field values, quality messages, and
     * suggestions for improvement.
     *
     * @since 1.0.0
     *
     * @return void Sends JSON response with detailed score breakdown.
     */
    public function ajax_get_seo_score_breakdown_handler() {
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

        if (!current_user_can('edit_post', $image_id)) {
            wp_send_json_error(['message' => esc_html__('You do not have permission to edit this image.', 'infy-ai-image-seo-optimizer')]);
        }

        try {
            // Get the score data with full breakdown
            $score_data = INFYP_SEO_Score_Calculator::calculate_score($image_id);

            // Get image data for display
            $alt_text = get_post_meta($image_id, '_wp_attachment_image_alt', true);
            $attachment = get_post($image_id);
            $file_path = get_attached_file($image_id);
            $filename = $file_path ? pathinfo($file_path, PATHINFO_FILENAME) : '';
            $keywords = get_post_meta($image_id, '_infyp_keywords', true);

            // Format the breakdown for frontend display
            $formatted_breakdown = [];
            $weights = INFYP_SEO_Score_Calculator::get_weights();
            $params = INFYP_SEO_Score_Calculator::get_scoring_params();

            // Since the SEO calculator combines presence and quality into single scores,
            // we need to split them for display purposes

            // Make sure we have the breakdown data
            if (!isset($score_data['breakdown'])) {
                throw new \Exception( esc_html__( 'Score breakdown data is missing', 'infy-ai-image-seo-optimizer' ) );
            }

            // Alt Text Presence
            $alt_has_text = !empty($alt_text);
            $alt_presence_weight = isset($weights['alt_presence']) ? $weights['alt_presence'] : 20;

            $formatted_breakdown[] = [
                'title' => esc_html__('Alt Text Presence', 'infy-ai-image-seo-optimizer'),
                'weight' => $alt_presence_weight,
                'score' => $alt_has_text ? $alt_presence_weight : 0,
                'status' => $alt_has_text ? 'complete' : 'missing',
                'message' => $alt_has_text ?
                    esc_html__('Alt text is present', 'infy-ai-image-seo-optimizer') :
                    esc_html__('Add alt text to improve accessibility and SEO', 'infy-ai-image-seo-optimizer'),
                'currentValue' => $alt_text ?: '',
                'field' => 'alt_text'
            ];

            // Alt Text Quality
            if ($alt_has_text && isset($weights['alt_quality']) && isset($score_data['breakdown']['alt_text'])) {
                $alt_details = $score_data['breakdown']['alt_text']['details'];
                // Calculate quality score (total alt score minus presence score)
                $alt_total_score = $score_data['breakdown']['alt_text']['score'];
                $quality_score = max(0, $alt_total_score - $alt_presence_weight);

                $formatted_breakdown[] = [
                    'title' => esc_html__('Alt Text Quality', 'infy-ai-image-seo-optimizer'),
                    'weight' => $weights['alt_quality'],
                    'score' => $quality_score,
                    'status' => $quality_score >= $weights['alt_quality'] ? 'complete' : 'partial',
                    'message' => $this->format_quality_message('alt', $alt_details, $params['alt_text']),
                    'currentValue' => $alt_text,
                    'field' => 'alt_text',
                    'details' => $alt_details
                ];
            } elseif (!$alt_has_text && isset($weights['alt_quality'])) {
                // No alt text, so quality score is 0
                $formatted_breakdown[] = [
                    'title' => esc_html__('Alt Text Quality', 'infy-ai-image-seo-optimizer'),
                    'weight' => $weights['alt_quality'],
                    'score' => 0,
                    'status' => 'missing',
                    'message' => esc_html__('Add alt text first to improve quality score', 'infy-ai-image-seo-optimizer'),
                    'currentValue' => '',
                    'field' => 'alt_text'
                ];
            }

            // Title Text Presence
            $title_has_text = !empty($attachment->post_title);
            $title_presence_weight = isset($weights['title_presence']) ? $weights['title_presence'] : 15;

            $formatted_breakdown[] = [
                'title' => esc_html__('Title Text Presence', 'infy-ai-image-seo-optimizer'),
                'weight' => $title_presence_weight,
                'score' => $title_has_text ? $title_presence_weight : 0,
                'status' => $title_has_text ? 'complete' : 'missing',
                'message' => $title_has_text ?
                    esc_html__('Title text is present', 'infy-ai-image-seo-optimizer') :
                    esc_html__('Add title text to improve SEO', 'infy-ai-image-seo-optimizer'),
                'currentValue' => $attachment->post_title ?: '',
                'field' => 'title'
            ];

            // Title Text Quality
            if ($title_has_text && isset($weights['title_quality']) && isset($score_data['breakdown']['title_text'])) {
                $title_details = $score_data['breakdown']['title_text']['details'];
                // Calculate quality score (total title score minus presence score)
                $title_total_score = $score_data['breakdown']['title_text']['score'];
                $quality_score = max(0, $title_total_score - $title_presence_weight);

                $formatted_breakdown[] = [
                    'title' => esc_html__('Title Text Quality', 'infy-ai-image-seo-optimizer'),
                    'weight' => $weights['title_quality'],
                    'score' => $quality_score,
                    'status' => $quality_score >= $weights['title_quality'] ? 'complete' : 'partial',
                    'message' => $this->format_quality_message('title', $title_details, $params['title']),
                    'currentValue' => $attachment->post_title,
                    'field' => 'title',
                    'details' => $title_details
                ];
            } elseif (!$title_has_text && isset($weights['title_quality'])) {
                // No title, so quality score is 0
                $formatted_breakdown[] = [
                    'title' => esc_html__('Title Text Quality', 'infy-ai-image-seo-optimizer'),
                    'weight' => $weights['title_quality'],
                    'score' => 0,
                    'status' => 'missing',
                    'message' => esc_html__('Add a title first to improve quality score', 'infy-ai-image-seo-optimizer'),
                    'currentValue' => '',
                    'field' => 'title'
                ];
            }

            // Filename Quality
            if (isset($score_data['breakdown']['filename'])) {
                $filename_details = $score_data['breakdown']['filename']['details'];
                $filename_score = $score_data['breakdown']['filename']['score'];

                $formatted_breakdown[] = [
                    'title' => esc_html__('Filename Quality', 'infy-ai-image-seo-optimizer'),
                    'weight' => isset($weights['filename_quality']) ? $weights['filename_quality'] : 15,
                    'score' => $filename_score,
                    'status' => $filename_score >= $weights['filename_quality'] ? 'complete' :
                              ($filename_score > 0 ? 'partial' : 'missing'),
                    'message' => $this->format_quality_message('filename', $filename_details, $params['filename']),
                    'currentValue' => $filename ?: '',
                    'field' => 'filename',
                    'details' => $filename_details
                ];
            }

            // Keyword Density
            if (isset($score_data['breakdown']['keyword_density'])) {
                $keyword_details = $score_data['breakdown']['keyword_density']['details'];
                $keyword_score = $score_data['breakdown']['keyword_density']['score'];

                $formatted_breakdown[] = [
                    'title' => esc_html__('Keyword Density', 'infy-ai-image-seo-optimizer'),
                    'weight' => isset($weights['keyword_density']) ? $weights['keyword_density'] : 15,
                    'score' => $keyword_score,
                    'status' => $keyword_score >= $weights['keyword_density'] ? 'complete' :
                              ($keyword_score > 0 ? 'partial' : 'missing'),
                    'message' => $this->format_keyword_message($keyword_details, $keywords),
                    'currentValue' => $keywords ?: [],
                    'field' => 'keywords',
                    'details' => $keyword_details
                ];
            } else {
                // Fallback if keyword density is not in breakdown
                $formatted_breakdown[] = [
                    'title' => esc_html__('Keyword Density', 'infy-ai-image-seo-optimizer'),
                    'weight' => isset($weights['keyword_density']) ? $weights['keyword_density'] : 15,
                    'score' => 0,
                    'status' => 'missing',
                    'message' => esc_html__('No keywords defined. Use AI optimization to generate keywords.', 'infy-ai-image-seo-optimizer'),
                    'currentValue' => [],
                    'field' => 'keywords'
                ];
            }

            // Uniqueness
            if (isset($weights['uniqueness']) && $weights['uniqueness'] > 0 && isset($score_data['breakdown']['uniqueness'])) {
                $uniqueness_score = $score_data['breakdown']['uniqueness']['score'];

                $formatted_breakdown[] = [
                    'title' => esc_html__('Uniqueness', 'infy-ai-image-seo-optimizer'),
                    'weight' => $weights['uniqueness'],
                    'score' => $uniqueness_score,
                    'status' => 'complete',
                    'message' => esc_html__('All metadata is unique', 'infy-ai-image-seo-optimizer'),
                    'currentValue' => '',
                    'field' => 'uniqueness'
                ];
            }

            wp_send_json_success([
                'score_data' => $score_data,
                'breakdown' => $formatted_breakdown,
                'params' => $params,
                'weights' => $weights
            ]);

        } catch (\Exception $e) {
            $this->error_handler->log_error('Failed to get SEO score breakdown', 'get_seo_score_breakdown', 'error', ['error' => $e->getMessage()]);
            wp_send_json_error(['message' => esc_html__('Failed to get SEO score breakdown.', 'infy-ai-image-seo-optimizer')]);
        }
    }

    /**
     * Format a quality assessment message for a given field.
     *
     * Evaluates character count, word count, and SEO-friendliness against
     * scoring parameters and returns human-readable feedback.
     *
     * @since 1.0.0
     *
     * @param string $field   The field type ('alt', 'title', or 'filename').
     * @param array  $details Field analysis details from the score calculator.
     * @param array  $params  Scoring parameters with min/max thresholds.
     * @return string Formatted quality message.
     */
    private function format_quality_message($field, $details, $params) {
        $messages = [];

        if ($field === 'alt' || $field === 'title') {
            if ($details['char_count'] < $params['min_chars']) {
                /* translators: 1: Current character count, 2: Minimum required characters */
                $messages[] = sprintf(esc_html__('Too short (%1$d chars, need %2$d+)', 'infy-ai-image-seo-optimizer'), $details['char_count'], $params['min_chars']);
            } elseif ($details['char_count'] > $params['max_chars']) {
                /* translators: 1: Current character count, 2: Maximum allowed characters */
                $messages[] = sprintf(esc_html__('Too long (%1$d chars, max %2$d)', 'infy-ai-image-seo-optimizer'), $details['char_count'], $params['max_chars']);
            }

            if (isset($details['word_count'])) {
                if ($details['word_count'] < $params['min_words']) {
                    /* translators: 1: Current word count, 2: Minimum required words */
                    $messages[] = sprintf(esc_html__('Need more words (%1$d, need %2$d+)', 'infy-ai-image-seo-optimizer'), $details['word_count'], $params['min_words']);
                } elseif ($details['word_count'] > $params['max_words']) {
                    /* translators: 1: Current word count, 2: Maximum allowed words */
                    $messages[] = sprintf(esc_html__('Too many words (%1$d, max %2$d)', 'infy-ai-image-seo-optimizer'), $details['word_count'], $params['max_words']);
                }
            }
        } elseif ($field === 'filename') {
            if (!$details['is_seo_friendly']) {
                $messages[] = esc_html__('Not SEO-friendly (use lowercase and hyphens)', 'infy-ai-image-seo-optimizer');
            }
            if ($details['is_generic']) {
                $messages[] = esc_html__('Generic filename detected', 'infy-ai-image-seo-optimizer');
            }
            if ($details['char_count'] < $params['min_chars'] || $details['char_count'] > $params['max_chars']) {
                $messages[] = sprintf(
                    /* translators: %d: Current character count */
                    esc_html__('Length not optimal (%d chars)', 'infy-ai-image-seo-optimizer'),
                    $details['char_count']
                );
            }
        }

        if (empty($messages)) {
            return esc_html__('Meets all quality criteria', 'infy-ai-image-seo-optimizer');
        }

        return implode('. ', $messages);
    }

    /**
     * Format a keyword placement message for the SEO score breakdown.
     *
     * Reports how many fields contain the target keywords and which
     * fields are missing keyword coverage.
     *
     * @since 1.0.0
     *
     * @param array $details  Keyword placement details with fields_with_keywords and placement data.
     * @param array $keywords The target keywords for the image.
     * @return string Formatted keyword placement message.
     */
    private function format_keyword_message($details, $keywords) {
        if (empty($keywords)) {
            return esc_html__('No keywords defined. Use AI optimization to generate keywords.', 'infy-ai-image-seo-optimizer');
        }

        $fields_with_keywords = $details['fields_with_keywords'];

        if ($fields_with_keywords === 3) {
            return esc_html__('Keywords found in all fields (alt text, title, filename)', 'infy-ai-image-seo-optimizer');
        } elseif ($fields_with_keywords === 2) {
            $missing = [];
            if (empty($details['placement']['alt'])) $missing[] = esc_html__('alt text', 'infy-ai-image-seo-optimizer');
            if (empty($details['placement']['title'])) $missing[] = esc_html__('title', 'infy-ai-image-seo-optimizer');
            if (empty($details['placement']['filename'])) $missing[] = esc_html__('filename', 'infy-ai-image-seo-optimizer');
            return sprintf(
                /* translators: %s: List of missing fields */
                esc_html__('Keywords found in 2 of 3 fields. Missing in: %s', 'infy-ai-image-seo-optimizer'),
                implode(', ', $missing)
            );
        } elseif ($fields_with_keywords === 1) {
            return esc_html__('Keywords found in only 1 field. Add to more fields for better SEO.', 'infy-ai-image-seo-optimizer');
        } else {
            return esc_html__('Keywords not found in any fields. Add keywords to improve SEO.', 'infy-ai-image-seo-optimizer');
        }
    }
}
