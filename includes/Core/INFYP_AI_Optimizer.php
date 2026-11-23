<?php
/**
 * AI-Powered Image Optimization Core Engine
 *
 * This file contains the INFYP_AI_Optimizer class which serves as the central
 * orchestrator for all AI-powered image metadata generation. It handles communication
 * with multiple AI providers (OpenAI, Google Gemini, Anthropic Claude), constructs
 * optimized prompts, processes responses, and manages retry logic.
 *
 * The class supports:
 * - Multi-provider AI integration (OpenAI, Google Gemini, Anthropic Claude)
 * - Sequential field optimization (keywords, filename, alt text, title)
 * - Single-field optimization for targeted updates
 * - Keyword-based context sharing across fields
 * - WooCommerce product context integration (via extension hooks)
 * - Custom prompt addons for business-specific optimization
 * - Retry logic with exponential backoff
 * - Comprehensive error handling and logging
 *
 * @package    AI_Image_SEO
 * @subpackage Core
 * @since 1.0.0
 * @author     InfyPress
 * @license    GPL-2.0+
 */

namespace IPAIS\Core;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AI Optimizer Class - Core Image Optimization Engine
 *
 * This class serves as the primary interface for AI-powered image optimization.
 * It orchestrates the entire optimization workflow from prompt generation through
 * API communication to response processing.
 *
 * Architecture Pattern:
 * This class uses a static method pattern for optimization operations, making it
 * easy to call from anywhere in the plugin without instantiation. It integrates
 * with several other core components:
 * - INFYP_API_Key_Manager: For secure API key retrieval
 * - INFYP_API_Retry_Handler: For robust retry logic
 * - INFYP_Logger: For comprehensive operation logging
 * - INFYP_API_Usage_Tracker: For monitoring API consumption
 *
 * Optimization Workflow:
 * 1. Keywords Generation: Analyze image and generate relevant keywords
 * 2. Field Optimization: Use keywords to optimize filename, alt text, title
 * 3. Extended Fields: Additional fields via extension hooks
 * 4. Result Processing: Validate and format AI responses
 * 5. Error Handling: Retry failed requests with backoff strategy
 *
 * Supported AI Models:
 * - The models registered in INFYP_Model_Config_Registry (OpenAI, Google,
 *   Anthropic, plus any model an extension registers).
 *
 * Usage Example:
 * ```php
 * // Generate all SEO fields for an image
 * $result = INFYP_AI_Optimizer::generate_seo_data( $image_id );
 * if ( ! is_wp_error( $result ) ) {
 *     $filename = $result['filename'];
 *     $alt_text = $result['alt_text'];
 *     $title = $result['title'];
 *     $keywords = $result['keywords'];
 * }
 *
 * // Optimize a single field
 * $result = INFYP_AI_Optimizer::optimize_single_field( $image_id, 'alt_text', $keywords );
 * ```
 *
 * Hooks Fired:
 * - `infyp_before_api_call`: Before making any API request
 * - `infyp_after_api_call`: After receiving API response
 * - `infyp_prompt_addon`: Filter for custom prompt modifications
 * - `infyp_optimize_extended_field`: Extension field optimization
 * - `infyp_after_ai_optimization`: Allows extensions to add fields after AI optimization
 *
 * @package    AI_Image_SEO
 * @subpackage Core
 * @since 1.0.0
 *
 * @see INFYP_API_Key_Manager For API key management
 * @see INFYP_API_Retry_Handler For retry logic
 * @see INFYP_Logger For logging operations
 */
class INFYP_AI_Optimizer {

    /**
     * Record optimization completion in post metadata
     *
     * Marks an image as optimized by setting post meta flags with timestamp.
     * This is used to track which images have been processed and when, helping
     * avoid duplicate processing and enabling optimization history tracking.
     *
     * @since 1.0.0
     *
     * @param int $image_id The WordPress attachment ID of the optimized image.
     *
     * @return void
     *
     * @see update_post_meta() WordPress function to update post metadata
     */
    public static function record_optimization($image_id) {
        update_post_meta($image_id, '_infyp_has_been_optimized', true);
        update_post_meta($image_id, '_infyp_last_optimized', time());
    }

    /**
     * Get base64-encoded image data with transient caching.
     *
     * @since 1.0.0
     * @param int $image_id The attachment ID.
     * @return string|\WP_Error Base64-encoded image data or WP_Error on failure.
     */
    public static function get_cached_base64( $image_id ) {
        return \IPAIS\Core\AI\INFYP_AI_Provider_Base::get_cached_base64( $image_id );
    }

    /**
     * Clear cached base64 data for an image.
     *
     * @since 1.0.0
     * @param int $image_id The attachment ID.
     */
    public static function clear_cached_base64( $image_id ) {
        \IPAIS\Core\AI\INFYP_AI_Provider_Base::clear_cached_base64( $image_id );
    }

    /**
     * Generate complete SEO metadata for an image using AI
     *
     * This is the primary method for full image optimization. It generates all SEO
     * fields (keywords, filename, alt text, title) using a sequential approach where
     * each field is optimized independently through separate API calls. This approach
     * provides better reliability and allows for granular error handling.
     *
     * Optimization Process:
     * 1. **Keywords Generation**: Analyzes the image to extract relevant keywords
     *    (unless existing keywords are provided)
     * 2. **Filename Optimization**: Generates SEO-friendly filename using keywords
     * 3. **Alt Text Optimization**: Creates descriptive alt text with keywords
     * 4. **Title Optimization**: Generates compelling title incorporating keywords
     *
     * Each step includes comprehensive logging and error handling. If any step fails,
     * the method returns a WP_Error with detailed context about the failure point.
     *
     * The method integrates with:
     * - Prompt settings (language, custom addons)
     * - AI model selection (configured in optimization settings)
     * - API key management (secure key retrieval and validation)
     * - Usage tracking (monitors API consumption)
     *
     * @since 1.0.0
     *
     * @param int        $image_id          The WordPress attachment ID to optimize.
     * @param array|null $existing_keywords Optional. Pre-generated keywords to use instead
     *                                      of generating new ones. Useful for batch operations
     *                                      or when keywords are already available.
     *                                      Default null (generates keywords).
     * @param string     $custom_prompt     Optional. Per-image prompt override. Replaces the
     *                                      global prompt addon and any context filters entirely
     *                                      for this request. Default empty string.
     *
     * @return array|WP_Error {
     *     Array of optimized SEO fields on success, WP_Error on failure.
     *
     *     @type array  $keywords  Array of relevant keywords extracted from the image.
     *     @type string $filename  SEO-optimized filename suggestion (without extension).
     *     @type string $alt_text  Descriptive alt text for accessibility and SEO.
     *     @type string $title     Compelling title for the image.
     * }
     *
     * @throws None Errors are returned as WP_Error objects, not thrown.
     *
     * @see self::generate_keywords_only() For keywords generation
     * @see self::optimize_single_field() For individual field optimization
     * @see INFYP_Logger::log() For operation logging
     */
    public static function generate_seo_data($image_id, $existing_keywords = null, $custom_prompt = '') {
        $result = [];

        // Log start of sequential optimization
        INFYP_Logger::log('Starting sequential optimization for image ID: ' . $image_id, 'INFO');

        // Step 1: Generate keywords first if not provided
        if (empty($existing_keywords)) {
            INFYP_Logger::log('Step 1/4: Generating keywords', 'INFO');

            $keyword_result = self::generate_keywords_only($image_id, $custom_prompt);

            if (is_wp_error($keyword_result)) {
                INFYP_Logger::log('Keywords generation failed: ' . $keyword_result->get_error_message(), 'ERROR');
                return $keyword_result;
            }

            $existing_keywords = $keyword_result['keywords'] ?? [];
            $result['keywords'] = $existing_keywords;

            INFYP_Logger::log('Keywords generated: ' . implode(', ', $existing_keywords), 'INFO');
        } else {
            $result['keywords'] = $existing_keywords;
            INFYP_Logger::log('Using existing keywords: ' . implode(', ', $existing_keywords), 'INFO');
        }

        // Step 2-4: Optimize each field independently using single-field optimization
        $fields = ['filename', 'alt_text', 'title'];
        $step = empty($existing_keywords) ? 2 : 1;

        foreach ($fields as $field) {
            INFYP_Logger::log("Step $step/4: Optimizing $field", 'INFO');

            $field_result = self::optimize_single_field($image_id, $field, $existing_keywords, $custom_prompt);

            if (is_wp_error($field_result)) {
                $error_message = $field_result->get_error_message();

                INFYP_Logger::log("Failed to optimize $field: $error_message", 'ERROR');

                // Return error with field context
                return new \WP_Error(
                    'field_optimization_failed',
                    sprintf(
                        'Failed to optimize %s: %s',
                        $field,
                        $error_message
                    )
                );
            }

            // Extract the field value from the result
            $result[$field] = $field_result[$field] ?? '';

            $value_preview = strlen($result[$field]) > 50
                ? substr($result[$field], 0, 50) . '...'
                : $result[$field];
            INFYP_Logger::log("$field optimized: $value_preview", 'INFO');

            $step++;
        }

        INFYP_Logger::log('Sequential optimization completed successfully', 'INFO');

        return $result;
    }
    
    /**
     * Generate keywords for an image using AI vision analysis
     *
     * Analyzes an image using AI vision capabilities to extract relevant keywords
     * that describe the image content. These keywords are used as context for
     * subsequent field optimizations (filename, alt text, title).
     *
     * This method is the first step in the optimization workflow and can also be
     * called independently when only keyword extraction is needed. The keywords
     * help maintain consistency across all optimized fields.
     *
     * Process:
     * 1. Retrieves AI model configuration from optimization settings
     * 2. Gets and decrypts appropriate API key for the selected model
     * 3. Processes custom prompt addons and contextual data (via hooks)
     * 4. Constructs keyword-specific prompt with language settings
     * 5. Executes API call with retry logic
     * 6. Parses and returns keyword array
     *
     * @since 1.0.0
     *
     * @param int    $image_id      The WordPress attachment ID to analyze.
     * @param string $custom_prompt Optional. Per-image prompt override. Replaces the
     *                              global prompt addon and any context filters entirely
     *                              for this request. Default empty string.
     *
     * @return array|WP_Error {
     *     Array with keywords on success, WP_Error on failure.
     *
     *     @type array $keywords Array of keyword strings extracted from image analysis.
     * }
     *
     * @throws None Errors are returned as WP_Error objects.
     *
     * @see INFYP_API_Key_Manager::get_api_key() For secure key retrieval
     * @see INFYP_API_Retry_Handler::execute_with_retry() For retry logic
     * @see \IPAIS\Core\AI\INFYP_Prompt_Builder::get_keywords_only_prompt() For prompt generation
     * @see self::execute_keywords_api_call() For API execution
     */
    public static function generate_keywords_only($image_id, $custom_prompt = '') {
        // Try to get model from optimization settings first, then fall back to infyp_ai_model
        $settings = get_option('infyp_optimization_settings', []);
        
        $model = $settings['ai_model'] ?? get_option('infyp_ai_model', INFYP_DEFAULT_MODEL);

        if (\IPAIS\Core\AI\INFYP_WP_AI_Support::is_selected()) {
            // WordPress AI Connectors: credentials are managed by core. No
            // silent fallback to stored API keys — if core support is gone
            // the user gets an explicit, actionable error instead.
            if (!\IPAIS\Core\AI\INFYP_WP_AI_Support::is_available()) {
                return new \WP_Error('wp_ai_unavailable', __('WordPress AI Connectors require WordPress 7.0 or later. Switch the connection method back to plugin API keys.', 'infy-ai-image-seo-optimizer'));
            }
            // phpcs:ignore WordPress.WP.CapitalPDangit -- lowercase provider slug, not prose.
            $provider = 'wordpress';
            $api_key = '';
        } else {
            // Get API key securely
            $api_key_manager = INFYP_API_Key_Manager::get_instance();
            $provider = $api_key_manager->get_provider_for_model($model);

            if (!$provider) {
                return new \WP_Error('invalid_model', 'Could not determine API provider for selected model.');
            }

            // Decrypt key
            $api_key = $api_key_manager->get_api_key($provider);

            if (empty($api_key)) {
                return new \WP_Error('api_key_missing', 'The API key for the selected model is not set.');
            }
        }
        
        $prompt_settings = get_option('infyp_prompt_settings', []);
        // Effective language = saved setting, else the SITE locale — the same
        // default the settings UI displays as selected. (Previously this fell
        // back to hardcoded English while the UI showed the site language as
        // chosen, so fresh installs generated English despite "Deutsch" being
        // shown.) Locale codes are resolved to names (de_DE → German) so the
        // AI prompt reads naturally; unknown values pass through unchanged.
        $language = \IPAIS\Core\INFYP_Language_Manager::get_language_name(
            !empty($prompt_settings['language'])
                ? trim($prompt_settings['language'])
                : \IPAIS\Core\INFYP_Language_Manager::get_default_language()
        );
        $per_image_enabled = ! empty( $prompt_settings['enable_per_image_prompt'] );

        // When per-image prompt is enabled, skip global prompt addon — use only the custom prompt
        $prompt_addon = '';
        if ( ! $per_image_enabled ) {
            // Process global prompt addon
            $prompt_addon_raw = isset($prompt_settings['prompt_addon']) ? $prompt_settings['prompt_addon'] : '';
            if (!empty($prompt_addon_raw)) {
                // Trim content
                $prompt_addon = trim($prompt_addon_raw);

                // Minimal XSS prevention
                $prompt_addon = html_entity_decode($prompt_addon, ENT_QUOTES | ENT_HTML5, 'UTF-8');

                // Remove script tags
                $prompt_addon = preg_replace('/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/mi', '', $prompt_addon);

                // Strip dangerous HTML
                $allowed_tags = '<br><p><strong><em><ul><ol><li>';
                $prompt_addon = strip_tags($prompt_addon, $allowed_tags);

                // Convert HTML to plain text
                $prompt_addon = str_replace(['<br>', '<br/>', '<br />'], "\n", $prompt_addon);
                $prompt_addon = str_replace(['<p>', '</p>'], "\n", $prompt_addon);
                $prompt_addon = str_replace(['<strong>', '</strong>', '<em>', '</em>'], '', $prompt_addon);
                $prompt_addon = str_replace(['<ul>', '</ul>', '<ol>', '</ol>', '<li>', '</li>'], '', $prompt_addon);

                // Clean newlines
                $prompt_addon = preg_replace("/\n{3,}/", "\n\n", $prompt_addon);

                // Trim
                $prompt_addon = trim($prompt_addon);
            }

        }

        /**
         * Filter to add additional context to the AI prompt.
         * Extensions can use this to inject contextual data, custom post type context, etc.
         *
         * @since 1.0.0
         * @param string $prompt_addon Current prompt addon text
         * @param int    $image_id     The image attachment ID being optimized
         * @param string $context_type The optimization context ('keywords' for keyword generation)
         */
        $prompt_addon = apply_filters( 'infyp_image_context', $prompt_addon, $image_id, 'keywords' );

        // Use per-image custom prompt if provided (replaces global addon entirely)
        if ( ! empty( $custom_prompt ) ) {
            $prompt_addon = trim( $custom_prompt );
        }

        // Build keyword-only prompt with addon
        $final_prompt = \IPAIS\Core\AI\INFYP_Prompt_Builder::get_keywords_only_prompt($model, $language, $prompt_addon);
        
        // API callback with retry
        $api_callback = function() use ($model, $api_key, $final_prompt, $image_id, $language) {
            return self::execute_keywords_api_call($model, $api_key, $final_prompt, $image_id, $language);
        };
        
        // Execute API call
        return INFYP_API_Retry_Handler::execute_with_retry($api_callback, $provider, $model, $image_id);
    }
    
    /**
     * Optimize a single metadata field for an image using AI
     *
     * Generates optimized content for a specific image metadata field (filename,
     * alt_text, or title). Extensions can register additional field types. This method is used both as part
     * of the sequential optimization workflow and for standalone field updates.
     *
     * The method supports keyword-based optimization where previously generated
     * keywords provide context for the field content, ensuring consistency across
     * all metadata fields.
     *
     * Supported Fields:
     * - `filename`: SEO-friendly filename (lowercase, hyphenated, keyword-rich)
     * - `alt_text`: Descriptive alt text for accessibility and SEO (125 char limit)
     * - `title`: Compelling image title incorporating keywords
     *
     * Extensions can register additional field types via the
     * `infyp_extended_field_types` and `infyp_extended_field_prompt` filters.
     *
     * Process:
     * 1. Validates field type (delegates extended fields via filter hook)
     * 2. Retrieves AI model and API key configuration
     * 3. Processes prompt settings (language, custom addons)
     * 4. Adds contextual data if applicable (via hooks)
     * 5. Constructs field-specific prompt with keywords
     * 6. Executes API call with retry logic
     * 7. Returns formatted field value
     *
     * @since 1.0.0
     *
     * @param int        $image_id          The WordPress attachment ID to optimize.
     * @param string     $field_type        The metadata field to optimize. Accepts:
     *                                      'filename', 'alt_text', 'title'.
     *                                      Extensions can add more via `infyp_extended_field_types`.
     * @param array|null $existing_keywords Optional. Pre-generated keywords to provide
     *                                      context for field optimization. Improves consistency
     *                                      across fields. Default null.
     * @param string     $custom_prompt     Optional. Per-image prompt override. Replaces the
     *                                      global prompt addon and any context filters entirely
     *                                      for this request. Default empty string.
     *
     * @return array|WP_Error {
     *     Array with optimized field value on success, WP_Error on failure.
     *
     *     @type string $field_type The optimized value for the specified field.
     * }
     *
     * @throws None Errors are returned as WP_Error objects.
     *
     * @see INFYP_API_Key_Manager::get_api_key() For secure API key retrieval
     * @see \IPAIS\Core\AI\INFYP_Prompt_Builder::get_single_field_prompt() For field-specific prompt generation
     * @see self::execute_single_field_api_call() For API request execution
     * @see INFYP_API_Retry_Handler::execute_with_retry() For retry logic
     */
    public static function optimize_single_field($image_id, $field_type, $existing_keywords = null, $custom_prompt = '') {
        // Allow extensions to handle custom field types
        $extended_field_types = apply_filters('infyp_extended_field_types', []);
        if (in_array($field_type, $extended_field_types)) {
            $extended_result = apply_filters('infyp_optimize_extended_field', null, $image_id, $field_type, $existing_keywords);
            if ($extended_result !== null) {
                return $extended_result;
            }
        }

        // Get settings
        $settings = get_option('infyp_optimization_settings', []);
        $model = $settings['ai_model'] ?? INFYP_DEFAULT_MODEL;

        if (\IPAIS\Core\AI\INFYP_WP_AI_Support::is_selected()) {
            // WordPress AI Connectors: credentials are managed by core. No
            // silent fallback to stored API keys — if core support is gone
            // the user gets an explicit, actionable error instead.
            if (!\IPAIS\Core\AI\INFYP_WP_AI_Support::is_available()) {
                return new \WP_Error('wp_ai_unavailable', __('WordPress AI Connectors require WordPress 7.0 or later. Switch the connection method back to plugin API keys.', 'infy-ai-image-seo-optimizer'));
            }
            // phpcs:ignore WordPress.WP.CapitalPDangit -- lowercase provider slug, not prose.
            $provider = 'wordpress';
            $api_key = '';
        } else {
            // Get API key securely
            $api_key_manager = INFYP_API_Key_Manager::get_instance();
            $provider = $api_key_manager->get_provider_for_model($model);

            if (!$provider) {
                return new \WP_Error('invalid_model', 'Could not determine API provider for selected model.');
            }

            // Decrypt key
            $api_key = $api_key_manager->get_api_key($provider);

            if (empty($api_key)) {
                return new \WP_Error('api_key_missing', 'The API key for the selected model is not set.');
            }
        }

        // Get prompt settings
        $prompt_settings = get_option('infyp_prompt_settings', []);
        $per_image_enabled = ! empty( $prompt_settings['enable_per_image_prompt'] );

        // When per-image prompt is enabled, skip global prompt addon — use only the custom prompt
        $prompt_addon = '';
        if ( ! $per_image_enabled ) {
            // Process global prompt addon
            $prompt_addon_raw = isset($prompt_settings['prompt_addon']) ? $prompt_settings['prompt_addon'] : '';
            if (!empty($prompt_addon_raw)) {
                // Trim content
                $prompt_addon = trim($prompt_addon_raw);

                // Minimal XSS prevention
                $prompt_addon = html_entity_decode($prompt_addon, ENT_QUOTES | ENT_HTML5, 'UTF-8');

                // Remove script tags
                $prompt_addon = preg_replace('/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/mi', '', $prompt_addon);

                // Strip dangerous HTML
                $allowed_tags = '<br><p><strong><em><ul><ol><li>';
                $prompt_addon = strip_tags($prompt_addon, $allowed_tags);

                // Convert HTML to plain text
                $prompt_addon = str_replace(['<br>', '<br/>', '<br />'], "\n", $prompt_addon);
                $prompt_addon = str_replace(['<p>', '</p>'], "\n", $prompt_addon);
                $prompt_addon = str_replace(['<strong>', '</strong>', '<em>', '</em>'], '', $prompt_addon);
                $prompt_addon = str_replace(['<ul>', '</ul>', '<ol>', '</ol>', '<li>', '</li>'], '', $prompt_addon);

                // Clean newlines
                $prompt_addon = preg_replace("/\n{3,}/", "\n\n", $prompt_addon);

                // Trim
                $prompt_addon = trim($prompt_addon);
            }

            // Filter hook for prompt addon customization
            $prompt_addon = apply_filters('infyp_prompt_addon', $prompt_addon, $image_id, $field_type);
        }

        /**
         * Filter to add additional context to the AI prompt.
         * Extensions can use this to inject contextual data, custom post type context, etc.
         *
         * @since 1.0.0
         * @param string $prompt_addon Current prompt addon text
         * @param int    $image_id     The image attachment ID being optimized
         * @param string $context_type The optimization context (field type: 'filename', 'alt_text', 'title', etc.)
         */
        $prompt_addon = apply_filters( 'infyp_image_context', $prompt_addon, $image_id, $field_type );

        // Use per-image custom prompt if provided (replaces global addon entirely)
        if ( ! empty( $custom_prompt ) ) {
            $prompt_addon = trim( $custom_prompt );
        }

        // Language
        // Effective language = saved setting, else the SITE locale — the same
        // default the settings UI displays as selected. (Previously this fell
        // back to hardcoded English while the UI showed the site language as
        // chosen, so fresh installs generated English despite "Deutsch" being
        // shown.) Locale codes are resolved to names (de_DE → German) so the
        // AI prompt reads naturally; unknown values pass through unchanged.
        $language = \IPAIS\Core\INFYP_Language_Manager::get_language_name(
            !empty($prompt_settings['language'])
                ? trim($prompt_settings['language'])
                : \IPAIS\Core\INFYP_Language_Manager::get_default_language()
        );
        
        // Build field-specific prompt
        $final_prompt = \IPAIS\Core\AI\INFYP_Prompt_Builder::get_single_field_prompt($model, $field_type, $language, $prompt_addon, $existing_keywords);
        
        // API callback
        $api_callback = function() use ($model, $api_key, $final_prompt, $image_id, $field_type) {
            return self::execute_single_field_api_call($model, $api_key, $final_prompt, $image_id, $field_type);
        };
        
        // Execute API call
        return INFYP_API_Retry_Handler::execute_with_retry($api_callback, $provider, $model, $image_id);
    }
    
    
    /**
     * Execute single field API call via Provider Manager.
     *
     * @param string $model      The AI model identifier.
     * @param string $api_key    Decrypted provider API key (empty for WordPress AI).
     * @param string $prompt     The fully built field prompt.
     * @param int    $image_id   The attachment ID.
     * @param string $field_type The field being optimized (e.g. 'filename', 'alt_text', 'title').
     * @return array|\WP_Error Parsed response array or WP_Error on failure.
     */
    private static function execute_single_field_api_call($model, $api_key, $prompt, $image_id, $field_type) {
        return \IPAIS\Core\AI\INFYP_AI_Provider_Manager::get_instance()
            ->execute_single_field($model, $api_key, $prompt, $image_id, $field_type);
    }

    /**
     * Execute keywords-only API call
     *
     * @since 1.0.0
     *
     * @param string $model    The AI model identifier.
     * @param string $api_key  Decrypted provider API key (empty for WordPress AI).
     * @param string $prompt   The fully built keywords prompt.
     * @param int    $image_id The attachment ID.
     * @param string $language Output language for generated keywords.
     * @return array|\WP_Error Parsed response array or WP_Error on failure.
     */
    private static function execute_keywords_api_call($model, $api_key, $prompt, $image_id, $language) {
        $result = \IPAIS\Core\AI\INFYP_AI_Provider_Manager::get_instance()
            ->execute_keywords($model, $api_key, $prompt, $image_id);

        if (is_wp_error($result)) {
            return $result;
        }

        // Validate keywords
        if (!isset($result['keywords']) || !is_array($result['keywords']) || count($result['keywords']) < 1) {
            return new \WP_Error('invalid_keywords', 'The AI did not generate valid keywords.');
        }

        return $result;
    }
    
}
