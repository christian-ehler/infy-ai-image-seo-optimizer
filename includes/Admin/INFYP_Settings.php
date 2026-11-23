<?php
/**
 * Settings Manager - WordPress Settings API Integration
 *
 * This class handles registration and sanitization of all plugin settings
 * using the WordPress Settings API. It manages three main setting groups:
 *
 * - API Keys Settings: OpenAI, Gemini, Anthropic API keys (encrypted)
 * - Optimization Settings: Batch limits, fallback options
 * - Prompt Settings: Custom AI prompts for different content types
 *
 * Settings are stored as serialized arrays in wp_options table with
 * automatic sanitization via WordPress Settings API.
 *
 * @package   IPAIS\Admin
 * @since 1.0.0
 * @author    InfyPress
 * @license   GPL-2.0-or-later
 */

namespace IPAIS\Admin;

// Security check
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin Settings Manager Class
 *
 * Handles WordPress Settings API registration for plugin configuration.
 * Settings are organized into logical groups and sanitized automatically.
 *
 * Setting Groups:
 * 1. infyp_api_keys - API provider credentials (encrypted storage)
 * 2. infyp_optimization_settings - Optimization behavior configuration
 * 3. infyp_prompt_settings_group - Custom AI prompt templates
 *
 * Sanitization:
 * All settings use wp_parse_args() for sanitization, which ensures:
 * - Arrays are properly structured
 * - Missing keys get default values
 * - Invalid data types are rejected
 *
 * Security:
 * - API keys are encrypted before storage using INFYP_API_Key_Manager
 * - All settings require 'manage_options' capability
 * - Input is sanitized via WordPress Settings API
 *
 * @package   IPAIS\Admin
 * @since 1.0.0
 *
 * @see       register_setting() WordPress Settings API
 * @see       INFYP_API_Key_Manager For API key encryption
 */
class INFYP_Settings {

    /**
     * Constructor - Register WordPress hooks
     *
     * @since 1.0.0
     */
    public function __construct() {
        // Priority 10 on purpose: infyp_maybe_upgrade() (priority 5) writes
        // infyp_optimization_settings before the sanitize callback exists,
        // because that callback keeps known keys only.
        add_action( 'admin_init', array( $this, 'register_plugin_settings' ), 10 );
    }

    /**
     * Register all plugin settings with WordPress Settings API
     *
     * Registers three setting groups:
     * - API Keys (encrypted)
     * - Optimization options
     * - Custom prompts
     *
     * @since 1.0.0
     *
     * @uses register_setting() WordPress Settings API
     */
    public function register_plugin_settings() {
        register_setting('infyp_api_keys_settings_group', 'infyp_api_keys', [
            'type' => 'array',
            'sanitize_callback' => array($this, 'sanitize_api_keys'),
        ]);
        register_setting('infyp_api_keys_settings_group', 'infyp_connection_method', [
            'type' => 'string',
            'default' => 'api_keys',
            'sanitize_callback' => array($this, 'sanitize_connection_method'),
        ]);
        register_setting('infyp_optimization_settings', 'infyp_optimization_settings', [
            'type' => 'array',
            'sanitize_callback' => array($this, 'sanitize_optimization_settings'),
        ]);
        register_setting('infyp_prompt_settings_group', 'infyp_prompt_settings', [
            'type' => 'array',
            'sanitize_callback' => array($this, 'sanitize_prompt_settings'),
        ]);
    }

    /**
     * Sanitize API keys settings
     *
     * @since 1.0.0
     * @param mixed $input Raw input from form submission.
     * @return array Sanitized API keys array.
     */
    public function sanitize_api_keys($input) {
        $sanitized = [];

        /**
         * Filters the list of allowed API key provider slugs.
         *
         * Extensions registering custom AI providers via `infyp_register_ai_providers`
         * should also hook here to allow their API keys to be saved through the settings.
         *
         * @since 1.0.0
         * @param array $providers Provider slug strings (e.g. 'openai', 'google', 'anthropic').
         */
        $allowed_providers = apply_filters( 'infyp_allowed_api_key_providers', [ 'openai', 'google', 'anthropic' ] );

        if (is_array($input)) {
            foreach ($allowed_providers as $provider) {
                if (isset($input[$provider]) && is_string($input[$provider])) {
                    // Minimal sanitization for API keys - just trim whitespace and remove any HTML
                    // API keys contain various characters that should not be modified
                    $key = trim( $input[$provider] );
                    $key = wp_strip_all_tags( $key );
                    $sanitized[$provider] = $key;
                }
            }
        }

        return $sanitized;
    }

    /**
     * Sanitize the connection method setting.
     *
     * The WordPress AI value is only accepted while the core AI Client is
     * actually available, so the option can never enable a path the runtime
     * cannot serve.
     *
     * @since 1.0.6
     * @param mixed $input Raw input from form submission.
     * @return string Either 'api_keys' or 'wp_ai'.
     */
    public function sanitize_connection_method( $input ) {
        if ( 'wp_ai' === $input && \IPAIS\Core\AI\INFYP_WP_AI_Support::is_available() ) {
            return 'wp_ai';
        }

        return 'api_keys';
    }

    /**
     * Sanitize optimization settings
     *
     * @since 1.0.0
     * @param mixed $input Raw input from form submission.
     * @return array Sanitized optimization settings array.
     */
    public function sanitize_optimization_settings($input) {
        $defaults = [
            'ai_model'        => INFYP_DEFAULT_MODEL,
            'batch_size'      => 5,
            'request_timeout' => 60,
            'provider'        => 'google',
        ];

        $sanitized = [];

        if (is_array($input)) {
            // AI model: a registered id or alias, stored as the canonical id.
            // An unknown id never replaces the saved one (it may belong to an
            // extension that is not loaded right now).
            if (isset($input['ai_model'])) {
                $sanitized['ai_model'] = $this->sanitize_ai_model($input['ai_model']);
            }

            // Sanitize batch size (integer between 1-50)
            if (isset($input['batch_size'])) {
                $sanitized['batch_size'] = min(50, max(1, absint($input['batch_size'])));
            }

            // Sanitize request timeout (integer between 60-120)
            if (isset($input['request_timeout'])) {
                $sanitized['request_timeout'] = min(120, max(60, absint($input['request_timeout'])));
            }

            // Sanitize provider against the filterable allowed list.
            if (isset($input['provider'])) {
                /** This filter is documented in INFYP_Settings::sanitize_api_keys() */
                $allowed_providers = apply_filters( 'infyp_allowed_api_key_providers', [ 'openai', 'google', 'anthropic' ] );
                $sanitized['provider'] = in_array($input['provider'], $allowed_providers, true)
                    ? $input['provider']
                    : $defaults['provider'];
            }
        }

        return wp_parse_args($sanitized, $defaults);
    }

    /**
     * Sanitize the AI model of the optimization settings.
     *
     * Accepts registered model ids and their aliases and returns the
     * canonical id. Anything else keeps the currently saved model, or the
     * default model when none is saved.
     *
     * @since 1.0.9
     *
     * @param mixed $model Submitted model id.
     * @return string Model id to store.
     */
    private function sanitize_ai_model($model) {
        $model    = is_string($model) ? sanitize_text_field($model) : '';
        $registry = \IPAIS\Core\AI\INFYP_Model_Config_Registry::get_instance();

        if ('' !== $model && in_array($model, $registry->get_accepted_model_ids(), true)) {
            return \IPAIS\Core\AI\INFYP_Model_Config_Registry::get_canonical_id($model);
        }

        $saved = get_option('infyp_optimization_settings', []);
        if (is_array($saved) && !empty($saved['ai_model']) && is_string($saved['ai_model'])) {
            return $saved['ai_model'];
        }

        return INFYP_DEFAULT_MODEL;
    }

    /**
     * Sanitize prompt settings
     *
     * @since 1.0.0
     * @param mixed $input Raw input from form submission.
     * @return array Sanitized prompt settings array.
     */
    public function sanitize_prompt_settings($input) {
        $defaults = [
            // Site locale, not hardcoded English — every surface must agree
            // on the same default language (UI, REST, generation).
            'language' => \IPAIS\Core\INFYP_Language_Manager::get_default_language(),
            'prompt_addon' => '',
            'enable_per_image_prompt' => 0,
        ];

        $sanitized = [];

        if (is_array($input)) {
            // Sanitize language (2-5 char language code)
            if (isset($input['language'])) {
                $lang = sanitize_text_field($input['language']);
                $sanitized['language'] = preg_match('/^[a-z]{2,5}(_[A-Z]{2})?$/', $lang) ? $lang : $defaults['language'];
            }

            // Sanitize prompt addon (textarea, allow some formatting)
            if (isset($input['prompt_addon'])) {
                $sanitized['prompt_addon'] = sanitize_textarea_field($input['prompt_addon']);
            }

            // Sanitize per-image prompt toggle
            $sanitized['enable_per_image_prompt'] = ! empty( $input['enable_per_image_prompt'] ) ? 1 : 0;
        }

        return wp_parse_args($sanitized, $defaults);
    }

    /**
     * Check if any API provider keys are configured
     *
     * Utility method to determine if at least one AI provider has
     * an API key configured. Used to conditionally show setup notices
     * and enable/disable functionality.
     *
     * @since 1.0.0
     *
     * @return bool True if at least one API key is set, false otherwise.
     */
    public static function are_api_keys_set() {
        $api_key_manager = \IPAIS\Core\INFYP_API_Key_Manager::get_instance();
        $key_status = $api_key_manager->get_api_key_status();

        // Check if any registered provider has a key configured.
        foreach ( $key_status as $has_key ) {
            if ( ! empty( $has_key ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check whether the plugin can reach an AI service at all.
     *
     * True when either the WordPress AI connection method is ready (WP 7.0+
     * with a configured connector) or at least one plugin API key is set.
     * This — not are_api_keys_set() — is the gate for "is the plugin usable",
     * surfaced to the admin JS as `infyp_vars.is_configured`.
     *
     * @since 1.0.6
     *
     * @return bool True when AI requests can be made.
     */
    public static function is_ai_configured() {
        return \IPAIS\Core\AI\INFYP_WP_AI_Support::is_ready() || self::are_api_keys_set();
    }
}
