<?php
/**
 * Settings AJAX Handlers - Settings/Configuration AJAX Operations
 *
 * Domain-specific AJAX handler extracted from INFYP_Ajax_Handlers.
 * Handles all settings and configuration-related AJAX operations including:
 *
 * - AI prompt settings (language, prompt addon, AI model, bulk concurrency)
 * - API key management with validation and encryption
 * - Post type filter settings
 * - SEO score weight configuration
 * - Scoring parameter management
 * - Unified SEO config (weights + params)
 *
 * Security Features:
 * - Nonce verification for all requests
 * - Capability checks (manage_options)
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

use \IPAIS\Core\INFYP_Security_Manager;
use \IPAIS\Core\INFYP_Cache_Manager;
use \IPAIS\Core\INFYP_Error_Handler;
use \IPAIS\Core\INFYP_SEO_Score_Calculator;

/**
 * Settings AJAX Handlers Class
 *
 * Handles all settings/configuration-related AJAX operations for the plugin.
 * Registers action hooks, validates requests, and delegates to appropriate
 * handlers with consistent security and error handling.
 *
 * Registered AJAX Actions (wp_ajax_*):
 * - infyp_save_prompt_settings: Save AI prompts
 * - infyp_save_api_keys: Save API credentials
 * - infyp_save_post_type_settings: Update post type config
 * - infyp_save_seo_weights: Save SEO scoring weights
 * - infyp_reset_seo_weights: Reset to defaults
 * - infyp_save_seo_config: Save SEO configuration
 * - infyp_save_scoring_params: Save scoring parameters
 * - infyp_reset_scoring_params: Reset scoring defaults
 *
 * @package   IPAIS\Ajax
 * @since 1.0.0
 *
 * @see       INFYP_Security_Manager For nonce and capability checks
 * @see       INFYP_Error_Handler For error logging
 */
class INFYP_Settings_Ajax {

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
     * Register all settings-related AJAX action handlers.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function register() {
        $handlers = [
            'infyp_save_prompt_settings' => 'ajax_save_prompt_settings_handler',
            'infyp_save_api_keys' => 'ajax_save_api_keys_handler',
            'infyp_test_api_key' => 'ajax_test_api_key_handler',
            'infyp_save_connection_method' => 'ajax_save_connection_method_handler',
            'infyp_save_post_type_settings' => 'ajax_save_post_type_settings_handler',
            'infyp_save_seo_weights' => 'ajax_save_seo_weights_handler',
            'infyp_reset_seo_weights' => 'ajax_reset_seo_weights_handler',
            'infyp_save_seo_config' => 'ajax_save_seo_config_handler',
            'infyp_save_scoring_params' => 'ajax_save_scoring_params_handler',
            'infyp_reset_scoring_params' => 'ajax_reset_scoring_params_handler',
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
     * AJAX handler for saving prompt settings.
     *
     * @since 1.0.0
     *
     * @return void Sends JSON response and exits.
     */
    public function ajax_save_prompt_settings_handler() {
        // Verify nonce
        if (!check_ajax_referer('infyp_ajax_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => esc_html__('Security check failed.', 'infy-ai-image-seo-optimizer')]);
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => esc_html__('Insufficient permissions.', 'infy-ai-image-seo-optimizer')]);
        }

        $settings_saved = false;

        try {
            // Handle prompt settings
            if (isset($_POST['infyp_prompt_settings']) && is_array($_POST['infyp_prompt_settings'])) {
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitization happens per field below
                $prompt_data = wp_unslash($_POST['infyp_prompt_settings']);

                // Validate and sanitize
                $language = isset($prompt_data['language']) ? sanitize_text_field($prompt_data['language']) : \IPAIS\Core\INFYP_Language_Manager::get_default_language();
                $prompt_addon = isset($prompt_data['prompt_addon']) ? sanitize_textarea_field($prompt_data['prompt_addon']) : '';

                // Validate language — must be a valid WordPress locale.
                if (!\IPAIS\Core\INFYP_Language_Manager::is_valid_language($language)) {
                    $language = \IPAIS\Core\INFYP_Language_Manager::get_default_language();
                }

                // Limit prompt addon length - use mb_strlen for UTF-8 support
                $max_prompt_length = apply_filters('infyp_max_prompt_length', 1000);
                $prompt_length = function_exists('mb_strlen') ? mb_strlen($prompt_addon, 'UTF-8') : strlen($prompt_addon);
                if ($prompt_length > $max_prompt_length) {
                    $prompt_addon = function_exists('mb_substr') ? mb_substr($prompt_addon, 0, $max_prompt_length, 'UTF-8') : substr($prompt_addon, 0, $max_prompt_length);
                }

                $enable_per_image_prompt = ! empty( $prompt_data['enable_per_image_prompt'] ) ? 1 : 0;

                $sanitized_settings = [
                    'language' => $language,
                    'prompt_addon' => $prompt_addon,
                    'enable_per_image_prompt' => $enable_per_image_prompt,
                ];

                update_option('infyp_prompt_settings', $sanitized_settings);
                $settings_saved = true;
            }

            // Handle AI model selection and optimization settings
            if (isset($_POST['infyp_optimization_settings']) && is_array($_POST['infyp_optimization_settings'])) {
                $optimization_settings = get_option('infyp_optimization_settings', []);

                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized per field below
                $opt_data = wp_unslash($_POST['infyp_optimization_settings']);

                if (isset($opt_data['ai_model']) && is_string($opt_data['ai_model'])) {
                    $model = sanitize_text_field($opt_data['ai_model']);
                    // Retired and legacy ids are accepted; the canonical id is stored.
                    if (in_array($model, $this->get_valid_ai_models(), true)) {
                        $optimization_settings['ai_model'] = \IPAIS\Core\AI\INFYP_Model_Config_Registry::get_canonical_id($model);
                    }
                }

                update_option('infyp_optimization_settings', $optimization_settings);
                $settings_saved = true;
            }

            if (!$settings_saved) {
                throw new \Exception(esc_html__('No settings to save.', 'infy-ai-image-seo-optimizer'));
            }

            // Clear settings cache
            $this->cache->invalidate_settings_cache();

            /**
             * Fires after plugin settings have been updated.
             *
             * @since 1.0.0
             */
            do_action('infyp_settings_updated');

            wp_send_json_success(['message' => esc_html__('Settings saved.', 'infy-ai-image-seo-optimizer')]);

        } catch (\Exception $e) {
            wp_send_json_error(['message' => esc_html($e->getMessage())]);
        }
    }

    /**
     * AJAX handler for saving API keys.
     *
     * @since 1.0.0
     *
     * @return void Sends JSON response and exits.
     */
    public function ajax_save_api_keys_handler() {
        // Verify nonce
        if (!check_ajax_referer('infyp_ajax_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => esc_html__('Security check failed.', 'infy-ai-image-seo-optimizer')]);
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => esc_html__('Insufficient permissions.', 'infy-ai-image-seo-optimizer')]);
        }

        // Validate required parameters
        $this->validate_parameters(['infyp_api_keys']);

		if (!isset($_POST['infyp_api_keys']) || !is_array($_POST['infyp_api_keys'])) {
			wp_send_json_error(['message' => esc_html__('Please enter at least one API key to save.', 'infy-ai-image-seo-optimizer')]);
		}

        // Save-all consistency: persist the connection method when the form includes it.
        if (isset($_POST['infyp_connection_method'])) {
            $requested_method = sanitize_key(wp_unslash($_POST['infyp_connection_method']));
            $method = ('wp_ai' === $requested_method && \IPAIS\Core\AI\INFYP_WP_AI_Support::is_available()) ? 'wp_ai' : 'api_keys';
            update_option('infyp_connection_method', $method);
        }

        try {
            // Use the secure API Key Manager
            $api_key_manager = \IPAIS\Core\INFYP_API_Key_Manager::get_instance();

            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitization happens per key below
            $posted_keys = wp_unslash($_POST['infyp_api_keys']);
            $validation_errors = [];

            // Sanitize and validate each key before saving.
            $sanitized_keys = [];
            /** This filter is documented in INFYP_Settings::sanitize_api_keys() */
            $allowed_providers = apply_filters( 'infyp_allowed_api_key_providers', [ 'openai', 'google', 'anthropic' ] );
            foreach ($allowed_providers as $provider) {
                if (isset($posted_keys[$provider]) && !empty($posted_keys[$provider])) {
                    // Minimal sanitization for API keys - just trim whitespace and remove any HTML
                    // API keys contain various characters that should not be modified
                    $key = trim( $posted_keys[$provider] );
                    $key = wp_strip_all_tags( $key );
                    $sanitized_keys[$provider] = $key;

                    // Basic plausibility only (length/characters) — no format
                    // assumptions: providers change key structures. A key that
                    // fails here is skipped, not the whole save; the real
                    // verification is the live test below.
                    $validation = $api_key_manager->validate_api_key($provider, $key);
                    if (is_wp_error($validation)) {
                        unset($sanitized_keys[$provider]);
                        $validation_errors[] = sprintf(
                            /* translators: 1: Provider name, 2: Error message */
                            esc_html__('%1$s: %2$s', 'infy-ai-image-seo-optimizer'),
                            ucfirst($provider),
                            $validation->get_error_message()
                        );
                    }
                }
            }

            if (empty($sanitized_keys) && !empty($validation_errors)) {
                wp_send_json_error([
                    'message' => esc_html__('No key could be saved:', 'infy-ai-image-seo-optimizer') . ' ' . implode(', ', $validation_errors)
                ]);
            }

            // Save the sanitized keys using the secure manager
            if ($api_key_manager->set_api_keys($sanitized_keys)) {
                // Clear caches
                $this->cache->invalidate_settings_cache();

                /** This action is documented in includes/Ajax/INFYP_Settings_Ajax.php */
                do_action('infyp_settings_updated');

                // Live-verify each saved key so the UI can show real state.
                $tests = [];
                foreach (array_keys($sanitized_keys) as $provider) {
                    $tests[$provider] = $api_key_manager->test_api_key($provider, $sanitized_keys[$provider]);
                }

                $message = esc_html__('API Keys saved and securely encrypted.', 'infy-ai-image-seo-optimizer');
                if (!empty($validation_errors)) {
                    $message .= ' ' . esc_html__('Skipped:', 'infy-ai-image-seo-optimizer') . ' ' . implode(', ', $validation_errors);
                }

                wp_send_json_success([
                    'message' => $message,
                    'tests'   => $tests,
                ]);
            } else {
                throw new \Exception(esc_html__('Failed to save API keys.', 'infy-ai-image-seo-optimizer'));
            }

        } catch (\Exception $e) {
            wp_send_json_error(['message' => esc_html($e->getMessage())]);
        }
    }

    /**
     * AJAX handler: live-test an API key against its provider.
     *
     * Tests the posted key when given, otherwise the stored key for the
     * provider. Returns the three-state result from
     * INFYP_API_Key_Manager::test_api_key() — 'valid', 'invalid' (with the
     * provider's own message) or 'unverified' (network trouble, never
     * presented as invalid).
     *
     * @since 1.0.6
     *
     * @return void Sends JSON response and exits.
     */
    public function ajax_test_api_key_handler() {
        if (!check_ajax_referer('infyp_ajax_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => esc_html__('Security check failed.', 'infy-ai-image-seo-optimizer')]);
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => esc_html__('Insufficient permissions.', 'infy-ai-image-seo-optimizer')]);
        }

        $provider = isset($_POST['provider']) ? sanitize_key(wp_unslash($_POST['provider'])) : '';

        /** This filter is documented in INFYP_Settings::sanitize_api_keys() */
        $allowed_providers = apply_filters('infyp_allowed_api_key_providers', ['openai', 'google', 'anthropic']);
        if (!in_array($provider, $allowed_providers, true)) {
            wp_send_json_error(['message' => esc_html__('Unknown provider.', 'infy-ai-image-seo-optimizer')]);
        }

        // Optional raw key (pre-save testing); otherwise the stored key is tested.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- API keys must not be altered; trimmed + tag-stripped below.
        $raw_key = isset($_POST['api_key']) ? trim(wp_strip_all_tags(wp_unslash($_POST['api_key']))) : '';

        $result = \IPAIS\Core\INFYP_API_Key_Manager::get_instance()->test_api_key($provider, '' !== $raw_key ? $raw_key : null);

        wp_send_json_success($result);
    }

    /**
     * AJAX handler for saving the connection method.
     *
     * Dedicated action so switching between plugin API keys and WordPress AI
     * Connectors does not trip the key-presence validation of the API-keys
     * save handler.
     *
     * @since 1.0.6
     *
     * @return void Sends JSON response and exits.
     */
    public function ajax_save_connection_method_handler() {
        // Verify nonce
        if (!check_ajax_referer('infyp_ajax_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => esc_html__('Security check failed.', 'infy-ai-image-seo-optimizer')]);
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => esc_html__('Insufficient permissions.', 'infy-ai-image-seo-optimizer')]);
        }

        $requested = isset($_POST['infyp_connection_method'])
            ? sanitize_key(wp_unslash($_POST['infyp_connection_method']))
            : 'api_keys';

        if ('wp_ai' === $requested && !\IPAIS\Core\AI\INFYP_WP_AI_Support::is_available()) {
            wp_send_json_error(['message' => esc_html__('WordPress AI Connectors require WordPress 7.0 or later.', 'infy-ai-image-seo-optimizer')]);
        }

        $method = ('wp_ai' === $requested) ? 'wp_ai' : 'api_keys';
        update_option('infyp_connection_method', $method);

        // Clear caches
        $this->cache->invalidate_settings_cache();

        /** This action is documented in includes/Ajax/INFYP_Settings_Ajax.php */
        do_action('infyp_settings_updated');

        $message = ('wp_ai' === $method)
            ? esc_html__('Connection method set to WordPress AI Connectors.', 'infy-ai-image-seo-optimizer')
            : esc_html__('Connection method set to plugin API keys.', 'infy-ai-image-seo-optimizer');

        // Connected AI providers so the UI can refresh without a reload.
        $connected = array_values(array_filter(
            \IPAIS\Core\AI\INFYP_WP_AI_Support::get_provider_states(),
            static function ($state) {
                return !empty($state['connected']);
            }
        ));

        wp_send_json_success([
            'message' => $message,
            'connection_method' => $method,
            'wp_ai_configured' => \IPAIS\Core\AI\INFYP_WP_AI_Support::is_configured(),
            'connected_providers' => $connected,
        ]);
    }

    /**
     * AJAX handler for saving post type settings.
     *
     * @since 1.0.0
     *
     * @return void Sends JSON response and exits.
     */
    public function ajax_save_post_type_settings_handler() {
        // Verify nonce
        if (!check_ajax_referer('infyp_ajax_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => esc_html__('Security check failed.', 'infy-ai-image-seo-optimizer')]);
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => esc_html__('Insufficient permissions.', 'infy-ai-image-seo-optimizer')]);
        }

        // Verify settings-specific nonce
        if (!isset($_POST['infyp_post_type_settings_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['infyp_post_type_settings_nonce'])), 'infyp_save_post_type_settings')) {
            wp_send_json_error(['message' => esc_html__('Security check failed.', 'infy-ai-image-seo-optimizer')]);
        }

        try {
            $raw_settings = isset($_POST['infyp_post_type_settings']) && is_array($_POST['infyp_post_type_settings'])
                           ? map_deep( wp_unslash( $_POST['infyp_post_type_settings'] ), 'sanitize_text_field' )
                           : [];

            // Re-sanitize with explicit key sanitization for safety.
            $sanitized_settings = [];
            foreach ( $raw_settings as $key => $value ) {
                $sanitized_settings[ sanitize_key( $key ) ] = sanitize_text_field( $value );
            }

            update_option('infyp_post_type_settings', $sanitized_settings);

            // Clear caches
            $this->cache->invalidate_settings_cache();
            $this->cache->flush_group(INFYP_Cache_Manager::CACHE_GROUP_QUERIES);

            /** This action is documented in includes/Ajax/INFYP_Settings_Ajax.php */
            do_action('infyp_settings_updated');

            wp_send_json_success(['message' => esc_html__('Settings saved successfully.', 'infy-ai-image-seo-optimizer')]);

        } catch (\Exception $e) {
            wp_send_json_error(['message' => esc_html__('Failed to save settings.', 'infy-ai-image-seo-optimizer')]);
        }
    }

    /**
     * AJAX handler for saving SEO score weights.
     *
     * @since 1.0.0
     *
     * @return void Sends JSON response and exits.
     */
    public function ajax_save_seo_weights_handler() {
        try {
            // Check nonce
            if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'infyp_seo_score_nonce')) {
                wp_send_json_error(['message' => esc_html__('Security check failed.', 'infy-ai-image-seo-optimizer')]);
            }

            // Check permissions
            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => esc_html__('Insufficient permissions.', 'infy-ai-image-seo-optimizer')]);
            }

        // Check if data exists
        if (!isset($_POST['data'])) {
            wp_send_json_error(['message' => esc_html__('No data received.', 'infy-ai-image-seo-optimizer')]);
        }

        // Parse form data — do NOT sanitize_text_field before parse_str, as it
        // strips brackets from query strings (e.g., weights[alt_presence]=20).
        // Individual values are sanitized after parsing.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parse_str requires raw string; individual values sanitized after parsing.
        parse_str( wp_unslash( $_POST['data'] ), $form_data );

        // Validate weights
        if (!isset($form_data['weights']) || !is_array($form_data['weights'])) {
            wp_send_json_error(['message' => esc_html__('Invalid weight data.', 'infy-ai-image-seo-optimizer')]);
        }

        // Save weights
        $saved_weights = INFYP_SEO_Score_Calculator::save_weights($form_data['weights']);

        // Clear score cache
        if ($this->cache && method_exists($this->cache, 'flush_group')) {
            $this->cache->flush_group(INFYP_Cache_Manager::CACHE_GROUP_IMAGES);
        }

        wp_send_json_success([
            'message' => esc_html__('Settings saved!', 'infy-ai-image-seo-optimizer'),
            'weights' => $saved_weights
        ]);
        } catch (\Exception $e) {
            wp_send_json_error([
                'message' => sprintf(
                    /* translators: %s: Error message */
                    esc_html__('Error saving settings: %s', 'infy-ai-image-seo-optimizer'),
                    esc_html($e->getMessage())
                )
            ]);
        }
    }

    /**
     * AJAX handler for resetting SEO score weights to defaults.
     *
     * @since 1.0.0
     *
     * @return void Sends JSON response and exits.
     */
    public function ajax_reset_seo_weights_handler() {
        try {
            // Check nonce
            if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'infyp_seo_score_nonce')) {
                wp_send_json_error(['message' => esc_html__('Security check failed.', 'infy-ai-image-seo-optimizer')]);
            }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => esc_html__('Insufficient permissions.', 'infy-ai-image-seo-optimizer')]);
        }

        // Reset weights
        $default_weights = INFYP_SEO_Score_Calculator::reset_weights();

        // Clear score cache
        if ($this->cache && method_exists($this->cache, 'flush_group')) {
            $this->cache->flush_group(INFYP_Cache_Manager::CACHE_GROUP_IMAGES);
        }

        wp_send_json_success([
            'message' => esc_html__('Settings reset to defaults.', 'infy-ai-image-seo-optimizer'),
            'weights' => $default_weights
        ]);
        } catch (\Exception $e) {
            wp_send_json_error([
                'message' => sprintf(
                    /* translators: %s: Error message */
                    esc_html__('Error resetting settings: %s', 'infy-ai-image-seo-optimizer'),
                    esc_html($e->getMessage())
                )
            ]);
        }
    }

    /**
     * AJAX handler for saving unified SEO config (weights + params).
     *
     * Processes and validates combined weight and scoring parameter data,
     * ensuring total weights equal 100%.
     *
     * @since 1.0.0
     *
     * @return void Sends JSON response and exits.
     */
    public function ajax_save_seo_config_handler() {
        try {
            // Check nonce
            if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'infyp_save_seo_config')) {
                wp_send_json_error(['message' => esc_html__('Security check failed.', 'infy-ai-image-seo-optimizer')]);
            }

            // Check permissions
            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => esc_html__('Insufficient permissions.', 'infy-ai-image-seo-optimizer')]);
            }

            // Get weights and parameters from POST data
            $weights = isset($_POST['weights']) ? array_map('intval', wp_unslash($_POST['weights'])) : [];

            // Handle nested parameters structure properly
            $params = [];
            if (isset($_POST['params']) && is_array($_POST['params'])) {
                // Sanitize the params array recursively
                $raw_params = map_deep(wp_unslash($_POST['params']), 'sanitize_text_field');

                // Process each parameter section (alt_text, filename, title)
                foreach (['alt_text', 'filename', 'title'] as $section) {
                    if (isset($raw_params[$section]) && is_array($raw_params[$section])) {
                        $params[$section] = [];
                        foreach ($raw_params[$section] as $key => $value) {
                            $params[$section][$key] = is_numeric($value) ? intval($value) : sanitize_text_field($value);
                        }
                    }
                }
            }

            // Process weights
            $processed_weights = [];

            // Handle total weights and their sub-components
            if (isset($weights['alt_total'])) {
                $alt_total = intval($weights['alt_total']);
                $processed_weights['alt_presence'] = isset($weights['alt_presence']) ? intval($weights['alt_presence']) : 20;
                $processed_weights['alt_quality'] = isset($weights['alt_quality']) ? intval($weights['alt_quality']) : 15;

                // Ensure sub-weights don't exceed total
                if ($processed_weights['alt_presence'] + $processed_weights['alt_quality'] > $alt_total) {
                    $processed_weights['alt_quality'] = $alt_total - $processed_weights['alt_presence'];
                }
            }

            if (isset($weights['title_total'])) {
                $title_total = intval($weights['title_total']);
                $processed_weights['title_presence'] = isset($weights['title_presence']) ? intval($weights['title_presence']) : 15;
                $processed_weights['title_quality'] = isset($weights['title_quality']) ? intval($weights['title_quality']) : 10;

                // Ensure sub-weights don't exceed total
                if ($processed_weights['title_presence'] + $processed_weights['title_quality'] > $title_total) {
                    $processed_weights['title_quality'] = $title_total - $processed_weights['title_presence'];
                }
            }

            // Add other weights
            if (isset($weights['filename_quality'])) {
                $processed_weights['filename_quality'] = intval($weights['filename_quality']);
            }
            if (isset($weights['keyword_density'])) {
                $processed_weights['keyword_density'] = intval($weights['keyword_density']);
            }
            if (isset($weights['uniqueness'])) {
                $processed_weights['uniqueness'] = intval($weights['uniqueness']);
            }

            // Validate total weight equals 100
            $total_weight = ($processed_weights['alt_presence'] ?? 0) +
                          ($processed_weights['alt_quality'] ?? 0) +
                          ($processed_weights['title_presence'] ?? 0) +
                          ($processed_weights['title_quality'] ?? 0) +
                          ($processed_weights['filename_quality'] ?? 0) +
                          ($processed_weights['keyword_density'] ?? 0) +
                          ($processed_weights['uniqueness'] ?? 0);

            if ($total_weight !== 100) {
                wp_send_json_error(['message' => sprintf(
                    /* translators: %d: Current total weight percentage */
                    esc_html__('Total weight must equal 100%% (currently %d%%)', 'infy-ai-image-seo-optimizer'),
                    $total_weight
                )]);
            }

            // Save weights
            if (!empty($processed_weights)) {
                INFYP_SEO_Score_Calculator::save_weights($processed_weights);
            }

            // Save parameters
            if (!empty($params)) {
                // Save parameters to option directly (no dedicated method exists)
                update_option('infyp_scoring_parameters', $params);
            }

            // Clear cache
            if ($this->cache && method_exists($this->cache, 'flush_group')) {
                $this->cache->flush_group(INFYP_Cache_Manager::CACHE_GROUP_IMAGES);
            }

            wp_send_json_success([
                'message' => esc_html__('Configuration saved successfully!', 'infy-ai-image-seo-optimizer'),
                'weights' => $processed_weights,
                'total' => $total_weight
            ]);

        } catch (\Exception $e) {
            wp_send_json_error(['message' => esc_html($e->getMessage())]);
        }
    }

    /**
     * AJAX handler for saving scoring parameters.
     *
     * Validates that scoring sub-percentages for each field add up to 100%.
     *
     * @since 1.0.0
     *
     * @return void Sends JSON response and exits.
     */
    public function ajax_save_scoring_params_handler() {
        try {
            // Check nonce
            if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'infyp_seo_score_nonce')) {
                wp_send_json_error(['message' => esc_html__('Security check failed.', 'infy-ai-image-seo-optimizer')]);
            }

            // Check permissions
            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => esc_html__('Insufficient permissions.', 'infy-ai-image-seo-optimizer')]);
            }

        // Check if data exists
        if (!isset($_POST['data'])) {
            wp_send_json_error(['message' => esc_html__('No data received.', 'infy-ai-image-seo-optimizer')]);
        }

        // Parse form data — sanitize individual values after parsing, not before.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parse_str requires raw string; individual values sanitized after parsing.
        parse_str( wp_unslash( $_POST['data'] ), $form_data );

        // Validate and save parameters
        $params_to_save = [];

        // Alt text parameters
        if (isset($form_data['alt_text_params'])) {
            $alt_params = $form_data['alt_text_params'];
            $params_to_save['alt_text'] = [
                'min_chars' => intval($alt_params['min_chars'] ?? 50),
                'max_chars' => intval($alt_params['max_chars'] ?? 125),
                'min_words' => intval($alt_params['min_words'] ?? 10),
                'max_words' => intval($alt_params['max_words'] ?? 16),
                'tolerance_words' => max(0, min(5, intval($alt_params['tolerance_words'] ?? 1))),
                'tolerance_chars_pct' => max(0, min(50, floatval($alt_params['tolerance_chars_pct'] ?? 15))),
                'base_score' => floatval($alt_params['base_score'] ?? 30),
                'length_score' => floatval($alt_params['length_score'] ?? 30),
                'quality_score' => floatval($alt_params['quality_score'] ?? 20),
                'keyword_score' => floatval($alt_params['keyword_score'] ?? 20)
            ];

            // Validate percentages add up to 100
            $total = $params_to_save['alt_text']['base_score'] +
                    $params_to_save['alt_text']['length_score'] +
                    $params_to_save['alt_text']['quality_score'] +
                    $params_to_save['alt_text']['keyword_score'];

            if (abs($total - 100) > 0.01) {
                wp_send_json_error(['message' => esc_html__('Alt text percentages must add up to 100%.', 'infy-ai-image-seo-optimizer')]);
            }
        }

        // Filename parameters
        if (isset($form_data['filename_params'])) {
            $filename_params = $form_data['filename_params'];
            $params_to_save['filename'] = [
                'min_chars' => intval($filename_params['min_chars'] ?? 40),
                'max_chars' => intval($filename_params['max_chars'] ?? 60),
                'min_words' => intval($filename_params['min_words'] ?? 3),
                'max_words' => intval($filename_params['max_words'] ?? 6),
                'tolerance_words' => max(0, min(5, intval($filename_params['tolerance_words'] ?? 1))),
                'tolerance_chars_pct' => max(0, min(50, floatval($filename_params['tolerance_chars_pct'] ?? 15))),
                'base_score' => floatval($filename_params['base_score'] ?? 30),
                'format_score' => floatval($filename_params['format_score'] ?? 20),
                'length_score' => floatval($filename_params['length_score'] ?? 20),
                'descriptive_score' => floatval($filename_params['descriptive_score'] ?? 15),
                'keyword_score' => floatval($filename_params['keyword_score'] ?? 15)
            ];

            // Validate percentages add up to 100
            $total = $params_to_save['filename']['base_score'] +
                    $params_to_save['filename']['format_score'] +
                    $params_to_save['filename']['length_score'] +
                    $params_to_save['filename']['descriptive_score'] +
                    $params_to_save['filename']['keyword_score'];

            if (abs($total - 100) > 0.01) {
                wp_send_json_error(['message' => esc_html__('Filename percentages must add up to 100%.', 'infy-ai-image-seo-optimizer')]);
            }
        }

        // Title parameters
        if (isset($form_data['title_params'])) {
            $title_params = $form_data['title_params'];
            $params_to_save['title'] = [
                'min_chars' => intval($title_params['min_chars'] ?? 40),
                'max_chars' => intval($title_params['max_chars'] ?? 60),
                'min_words' => intval($title_params['min_words'] ?? 6),
                'max_words' => intval($title_params['max_words'] ?? 10),
                'tolerance_words' => max(0, min(5, intval($title_params['tolerance_words'] ?? 1))),
                'tolerance_chars_pct' => max(0, min(50, floatval($title_params['tolerance_chars_pct'] ?? 15))),
                'base_score' => floatval($title_params['base_score'] ?? 25),
                'length_score' => floatval($title_params['length_score'] ?? 30),
                'relation_score' => floatval($title_params['relation_score'] ?? 20),
                'keyword_score' => floatval($title_params['keyword_score'] ?? 25)
            ];

            // Validate percentages add up to 100
            $total = $params_to_save['title']['base_score'] +
                    $params_to_save['title']['length_score'] +
                    $params_to_save['title']['relation_score'] +
                    $params_to_save['title']['keyword_score'];

            if (abs($total - 100) > 0.01) {
                wp_send_json_error(['message' => esc_html__('Title percentages must add up to 100%.', 'infy-ai-image-seo-optimizer')]);
            }
        }

        // Save to database
        update_option('infyp_scoring_parameters', $params_to_save);

        // Clear caches
        if ($this->cache && method_exists($this->cache, 'flush_group')) {
            $this->cache->flush_group(INFYP_Cache_Manager::CACHE_GROUP_IMAGES);
        }

        wp_send_json_success([
            'message' => esc_html__('Settings saved!', 'infy-ai-image-seo-optimizer'),
            'params' => $params_to_save
        ]);
        } catch (\Exception $e) {
            wp_send_json_error([
                'message' => sprintf(
                    /* translators: %s: Error message */
                    esc_html__('Error saving settings: %s', 'infy-ai-image-seo-optimizer'),
                    esc_html($e->getMessage())
                )
            ]);
        }
    }

    /**
     * AJAX handler for resetting scoring parameters to defaults.
     *
     * Deletes the scoring parameters option and returns default values.
     *
     * @since 1.0.0
     *
     * @return void Sends JSON response and exits.
     */
    public function ajax_reset_scoring_params_handler() {
        try {
            // Check nonce
            if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'infyp_seo_score_nonce')) {
                wp_send_json_error(['message' => esc_html__('Security check failed.', 'infy-ai-image-seo-optimizer')]);
            }

            // Check permissions
            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => esc_html__('Insufficient permissions.', 'infy-ai-image-seo-optimizer')]);
            }

        // Delete the option to reset to defaults
        delete_option('infyp_scoring_parameters');

        // Clear caches
        if ($this->cache && method_exists($this->cache, 'flush_group')) {
            $this->cache->flush_group(INFYP_Cache_Manager::CACHE_GROUP_IMAGES);
        }

        // Get default parameters
        $default_params = [
            'alt_text' => [
                'min_chars' => 50,
                'max_chars' => 125,
                'min_words' => 10,
                'max_words' => 16,
                'tolerance_words' => 1,
                'tolerance_chars_pct' => 15,
                'base_score' => 30,
                'length_score' => 30,
                'quality_score' => 20,
                'keyword_score' => 20
            ],
            'filename' => [
                'min_chars' => 40,
                'max_chars' => 60,
                'min_words' => 3,
                'max_words' => 6,
                'tolerance_words' => 1,
                'tolerance_chars_pct' => 15,
                'base_score' => 30,
                'format_score' => 20,
                'length_score' => 20,
                'descriptive_score' => 15,
                'keyword_score' => 15
            ],
            'title' => [
                'min_chars' => 40,
                'max_chars' => 60,
                'min_words' => 6,
                'max_words' => 10,
                'tolerance_words' => 1,
                'tolerance_chars_pct' => 15,
                'base_score' => 25,
                'length_score' => 30,
                'relation_score' => 20,
                'keyword_score' => 25
            ]
        ];

        wp_send_json_success([
            'message' => esc_html__('Settings reset to defaults.', 'infy-ai-image-seo-optimizer'),
            'params' => $default_params
        ]);
        } catch (\Exception $e) {
            wp_send_json_error([
                'message' => sprintf(
                    /* translators: %s: Error message */
                    esc_html__('Error resetting settings: %s', 'infy-ai-image-seo-optimizer'),
                    esc_html($e->getMessage())
                )
            ]);
        }
    }

    /**
     * Get the list of AI model identifiers accepted as input.
     *
     * Registered model ids plus their aliases (renamed or retired ids).
     *
     * @since 1.0.0
     * @since 1.0.9 Includes aliases.
     *
     * @return array Array of accepted AI model identifier strings.
     */
    private function get_valid_ai_models() {
        $registry = \IPAIS\Core\AI\INFYP_Model_Config_Registry::get_instance();
        return $registry->get_accepted_model_ids();
    }
}
