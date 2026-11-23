<?php
/**
 * Script and Style Manager for AI Image SEO Plugin
 *
 * This file contains the INFYP_Script_Manager class which handles all
 * script and style enqueuing for the plugin's admin interface. It also
 * manages JavaScript module loading via import maps and provides localized
 * data to the frontend.
 *
 * Features:
 * - CSS framework and component style loading
 * - JavaScript module bundling with ES6 import map support
 * - Localized script data (nonces, i18n strings, configuration)
 * - Initial table data inlining for performance optimization
 * - Media library integration scripts
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
 * Script Manager for Infy AI Image SEO
 *
 * Handles all script and style enqueuing for the plugin's admin interface.
 * Manages JavaScript ES6 module loading via import maps and provides
 * localized data for frontend communication with the WordPress backend.
 *
 * @package    AI_Image_SEO
 * @subpackage Core
 * @since 1.0.0
 */
class INFYP_Script_Manager {

	/**
	 * Constructor. Hooks into WordPress to enqueue scripts.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
		add_action('admin_head', [$this, 'add_import_map']);
		add_filter('script_loader_tag', [$this, 'add_type_to_script'], 10, 3);
	}

	/**
	 * Adds an ES6 import map to the admin head.
	 *
	 * This allows us to use bare specifiers for our JavaScript modules,
	 * enabling clean imports like `import api from 'infyp/api'`.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function add_import_map() {
		$screen = get_current_screen();
		if (!$screen || strpos($screen->id, 'ai-image-seo-optimizer') === false) {
			return;
		}

		// Get the base URL for the JavaScript modules. The version query is
		// the cache-buster — import-map URLs bypass wp_enqueue_script, so
		// without it browsers keep serving stale modules across plugin updates.
		$js_modules_url = plugin_dir_url(__FILE__) . '../../assets/js/modules/';
		$js_modules_ver = '?ver=' . rawurlencode( INFYP_VERSION );

		// Define the import map
		$import_map = [
			'imports' => [
				'infyp/api' => $js_modules_url . 'api.js' . $js_modules_ver,
				'infyp/ui'  => $js_modules_url . 'ui.js' . $js_modules_ver,
				'infyp/image-table' => $js_modules_url . 'image-table.js' . $js_modules_ver,
				'infyp/settings' => $js_modules_url . 'settings.js' . $js_modules_ver,
				// Core modules (Phase 1 modularization)
				'infyp/core/config' => $js_modules_url . 'core/config.js' . $js_modules_ver,
				'infyp/core/scoring-engine' => $js_modules_url . 'core/scoring-engine.js' . $js_modules_ver,
				'infyp/core/event-manager' => $js_modules_url . 'core/event-manager.js' . $js_modules_ver,
				// Table modules (Phase 2 modularization)
				'infyp/table/field-editor' => $js_modules_url . 'table/field-editor.js' . $js_modules_ver,
				'infyp/table/pagination' => $js_modules_url . 'table/pagination.js' . $js_modules_ver,
				'infyp/table/filtering' => $js_modules_url . 'table/filtering.js' . $js_modules_ver,
				'infyp/table/page-filter' => $js_modules_url . 'table/page-filter.js' . $js_modules_ver,
				'infyp/table/seo-sort' => $js_modules_url . 'table/seo-sort.js' . $js_modules_ver,
				'infyp/table/date-sort' => $js_modules_url . 'table/date-sort.js' . $js_modules_ver,
				// Modal modules (Phase 3 modularization)
				'infyp/modals/base-modal' => $js_modules_url . 'modals/base-modal.js' . $js_modules_ver,
				'infyp/modals/usage-modal' => $js_modules_url . 'modals/usage-modal.js' . $js_modules_ver,
				'infyp/modals/alt-text-modal' => $js_modules_url . 'modals/alt-text-modal.js' . $js_modules_ver,
				'infyp/modals/filename-modal' => $js_modules_url . 'modals/filename-modal.js' . $js_modules_ver,
				'infyp/modals/title-modal' => $js_modules_url . 'modals/title-modal.js' . $js_modules_ver,
				'infyp/modals/keywords-modal' => $js_modules_url . 'modals/keywords-modal.js' . $js_modules_ver,
				'infyp/modals/seo-score-modal' => $js_modules_url . 'modals/seo-score-modal.js' . $js_modules_ver,
			],
		];

		// Output the import map using WordPress API.
		wp_print_inline_script_tag( wp_json_encode( $import_map ), [ 'type' => 'importmap' ] );
	}

    /**
     * Enqueues all necessary CSS and JavaScript files for the admin area.
     *
     * Loads framework styles, plugin component styles, and JavaScript modules
     * on plugin admin pages. Also handles media library integration scripts.
     *
     * @since 1.0.0
     * @param string $hook The current admin page hook suffix.
     * @return void
     */
    public function enqueue_admin_scripts($hook) {
        // Enqueue on plugin pages
        if (strpos($hook, 'ai-image-seo-optimizer') !== false) {
            // Framework: Variables (must be first)
            wp_enqueue_style('infyp-variables', INFYP_PLUGIN_URL . 'assets/css/framework/variables.css', [], INFYP_VERSION);

            // Framework: Components
            wp_enqueue_style('infyp-framework', INFYP_PLUGIN_URL . 'assets/css/framework/framework.css', ['infyp-variables'], INFYP_VERSION);

            // Main admin styles (minimal - component styles are modular)
			wp_enqueue_style('infyp-admin-style', INFYP_PLUGIN_URL . 'assets/css/admin-style.css', ['infyp-framework', 'common', 'forms'], INFYP_VERSION);

            // Modular plugin styles (extracted from admin-style.css)
            wp_enqueue_style('infyp-image-table', INFYP_PLUGIN_URL . 'assets/css/plugin/image-table.css', ['infyp-admin-style'], INFYP_VERSION);
            wp_enqueue_style('infyp-image-table-responsive', INFYP_PLUGIN_URL . 'assets/css/plugin/image-table-responsive.css', ['infyp-image-table'], INFYP_VERSION);
            wp_enqueue_style('infyp-search-filters', INFYP_PLUGIN_URL . 'assets/css/plugin/search-filters.css', ['infyp-admin-style'], INFYP_VERSION);
            wp_enqueue_style('infyp-post-type-filters', INFYP_PLUGIN_URL . 'assets/css/plugin/post-type-filters.css', ['infyp-admin-style'], INFYP_VERSION);
            wp_enqueue_style('infyp-keywords', INFYP_PLUGIN_URL . 'assets/css/plugin/keywords.css', ['infyp-admin-style'], INFYP_VERSION);
            wp_enqueue_style('infyp-loading-states', INFYP_PLUGIN_URL . 'assets/css/plugin/loading-states.css', ['infyp-admin-style'], INFYP_VERSION);
            wp_enqueue_style('infyp-seo-score-display', INFYP_PLUGIN_URL . 'assets/css/plugin/seo-score-display.css', ['infyp-admin-style'], INFYP_VERSION);
            wp_enqueue_style('infyp-usage-badges', INFYP_PLUGIN_URL . 'assets/css/plugin/usage-badges.css', ['infyp-admin-style'], INFYP_VERSION);

            // Modal content styles
            wp_enqueue_style('infyp-modals', INFYP_PLUGIN_URL . 'assets/css/modals.css', ['infyp-framework'], INFYP_VERSION);
            wp_enqueue_style('infyp-optimize-icon', INFYP_PLUGIN_URL . 'assets/css/optimize-icon.css', [], INFYP_VERSION);

			// Load API keys page styles
			if (strpos($hook, 'ai-image-seo-optimizer-api-keys') !== false) {
				wp_enqueue_style('infyp-api-keys', INFYP_PLUGIN_URL . 'assets/css/plugin/api-keys.css', ['infyp-variables'], INFYP_VERSION);
			}

            wp_enqueue_script('infyp-admin-script', INFYP_PLUGIN_URL . 'assets/js/admin-script.js', ['jquery'], INFYP_VERSION, true);

            // Hook for addons to enqueue their scripts
            do_action('infyp_enqueue_addon_scripts', $hook);

            // Prepare API key statuses
            $api_keys = get_option('infyp_api_keys', []);
            $api_key_manager = \IPAIS\Core\INFYP_API_Key_Manager::get_instance();
            $key_status = $api_key_manager->get_api_key_status();
            
            // Get SEO weights from settings
            $seo_weights = get_option('infyp_seo_weights', [
                'alt_presence' => 20,
                'alt_quality' => 15,
                'title_presence' => 15,
                'title_quality' => 10,
                'filename_quality' => 15,
                'keyword_density' => 15,
                'uniqueness' => 10
            ]);
            
            // Get scoring parameters from settings
            // Note: Using get_scoring_params to ensure consistency with backend
            $scoring_params = \IPAIS\Core\INFYP_SEO_Score_Calculator::get_scoring_params();

            // Get initial table data for main plugin page (Phase 3: Inline initial data)
            // This eliminates the first AJAX call, improving initial load by 200-400ms
            $initial_table_data = null;
            if ( $hook === 'toplevel_page_infy-ai-image-seo-optimizer' ) {
                $initial_table_data = $this->get_initial_table_data();
            }

            // Get AI model label for status display
            $optimization_settings = get_option( 'infyp_optimization_settings', [] );
            $ai_model_id    = \IPAIS\Core\AI\INFYP_Model_Config_Registry::get_canonical_id( (string) ( $optimization_settings['ai_model'] ?? INFYP_DEFAULT_MODEL ) );
            $registry     = \IPAIS\Core\AI\INFYP_Model_Config_Registry::get_instance();
            $model_labels = $registry->get_model_labels();

            /**
             * Register additional AI model labels for the admin UI.
             *
             * @since 1.0.0
             * @param array $model_labels Model ID => display label.
             */
            $model_labels = apply_filters( 'infyp_model_labels', $model_labels );
            $ai_model_label = $model_labels[ $ai_model_id ] ?? ucwords( str_replace( '-', ' ', $ai_model_id ) );

            $localize_data = [
                'ajax_url'          => admin_url('admin-ajax.php'),
                'admin_url'         => admin_url(),
                'nonce'             => wp_create_nonce('infyp_ajax_nonce'),
                'api_keys_url'      => admin_url('admin.php?page=ai-image-seo-optimizer-api-keys'),
                'is_configured'     => \IPAIS\Admin\INFYP_Settings::is_ai_configured(),
                'connection_method' => \IPAIS\Core\AI\INFYP_WP_AI_Support::get_connection_method(),
                'wp_ai'             => [
                    'available'      => \IPAIS\Core\AI\INFYP_WP_AI_Support::is_available(),
                    'configured'     => \IPAIS\Core\AI\INFYP_WP_AI_Support::is_configured(),
                    'active'         => \IPAIS\Core\AI\INFYP_WP_AI_Support::is_active(),
                    'connectors_url' => \IPAIS\Core\AI\INFYP_WP_AI_Support::connectors_admin_url(),
                    // Which of the plugin's providers have a connected core
                    // connector — drives the model↔connector mismatch notice.
                    'connected_providers' => \IPAIS\Core\AI\INFYP_WP_AI_Support::get_connected_provider_slugs(),
                ],
                'ai_model_label'    => $ai_model_label,
                'api_key_status'    => $key_status,
                'encryption_enabled' => extension_loaded('openssl'),
                'seo_weights'       => $seo_weights,
                'scoring_parameters' => $scoring_params,
                'initial_table_data' => $initial_table_data,
                'enable_per_image_prompt' => (bool) ( get_option( 'infyp_prompt_settings', [] )['enable_per_image_prompt'] ?? false ),
                'i18n'              => apply_filters('infyp_admin_i18n_strings', [
                    // General
                    'saving'                        => __('Saving...', 'infy-ai-image-seo-optimizer'),
                    'wp_ai_no_connector'            => __('No connector configured.', 'infy-ai-image-seo-optimizer'),
                    /* translators: %s: AI provider name (e.g. Anthropic Claude). */
                    'wp_ai_provider_missing'        => __('No %s connector is configured in WordPress. Requests for this model will be answered by another connected provider\'s model instead.', 'infy-ai-image-seo-optimizer'),
                    'manage_connectors'             => __('Manage connectors', 'infy-ai-image-seo-optimizer'),
                    'heads_up'                      => __('Heads-up:', 'infy-ai-image-seo-optimizer'),
                    'index_rebuilt'                 => __('Search index rebuilt successfully.', 'infy-ai-image-seo-optimizer'),
                    'images_indexed'                => __('images indexed.', 'infy-ai-image-seo-optimizer'),
                    'key_verified'                  => __('key verified with the provider', 'infy-ai-image-seo-optimizer'),
                    'key_rejected'                  => __('key rejected by the provider', 'infy-ai-image-seo-optimizer'),
                    'key_unverified'                => __('key saved — provider could not be reached to verify', 'infy-ai-image-seo-optimizer'),
                    'error'                         => __('Error: ', 'infy-ai-image-seo-optimizer'),
                    'unknown_error'                 => __('An unknown error occurred. Please try again.', 'infy-ai-image-seo-optimizer'),
                    'server_error'                  => __('An error occurred on the server. Please try again.', 'infy-ai-image-seo-optimizer'),
                    'dismiss_notice'                => __('Dismiss this notice.', 'infy-ai-image-seo-optimizer'),
                    'show'                          => __('Show', 'infy-ai-image-seo-optimizer'),
                    'hide'                          => __('Hide', 'infy-ai-image-seo-optimizer'),
                    'cancel'                        => __('Cancel', 'infy-ai-image-seo-optimizer'),
                    'cancelling'                    => __('Cancelling...', 'infy-ai-image-seo-optimizer'),
                    'updated'                       => __('Updated', 'infy-ai-image-seo-optimizer'),
                    'updating'                      => __('Updating...', 'infy-ai-image-seo-optimizer'),
                    'update_failed'                 => __('Update failed', 'infy-ai-image-seo-optimizer'),
                    'confirm_update'                => __('Are you sure you want to update this field?', 'infy-ai-image-seo-optimizer'),

                    // Settings
                    'save_api_keys'                 => __('Save API Keys', 'infy-ai-image-seo-optimizer'),
                    'save_all_api_keys'             => __('Save All API Keys', 'infy-ai-image-seo-optimizer'),
                    'save_settings'                 => __('Save Settings', 'infy-ai-image-seo-optimizer'),
                    'save_changes'                  => __('Save Changes', 'infy-ai-image-seo-optimizer'),
                    'settings_saved'                => __('Settings have been saved successfully.', 'infy-ai-image-seo-optimizer'),
					'missing_api_key' 				=> __('Please enter at least one API key to save.', 'infy-ai-image-seo-optimizer'),
                    'enter_api_key'                 => __('Please enter an API key to save.', 'infy-ai-image-seo-optimizer'),
                    'enter_google_api_key'          => __('Please enter Google API key to save.', 'infy-ai-image-seo-optimizer'),
                    'enter_openai_api_key'          => __('Please enter OpenAI API key to save.', 'infy-ai-image-seo-optimizer'),
                    'enter_anthropic_api_key'       => __('Please enter Anthropic API key to save.', 'infy-ai-image-seo-optimizer'),
                    'api_key_saved'                 => __('API key saved successfully.', 'infy-ai-image-seo-optimizer'),
                    'error_saving_settings'         => __('An error occurred while saving the settings.', 'infy-ai-image-seo-optimizer'),
                    'action_required'               => __('Action Required:', 'infy-ai-image-seo-optimizer'),
                    'set_api_key_message'           => __('To use this model, you need to set the corresponding API key in the', 'infy-ai-image-seo-optimizer'),
                    'set_api_key_link'              => __('API Keys settings', 'infy-ai-image-seo-optimizer'),
                    'dismiss_notice'                => __('Dismiss this notice.', 'infy-ai-image-seo-optimizer'),
                    'apiKeyNotice'                  => [
                        'title' => esc_html__('Image SEO Optimizer', 'infy-ai-image-seo-optimizer'),
                        'text'  => esc_html__('To start optimizing, please add your API key on the', 'infy-ai-image-seo-optimizer'),
                        'link'  => esc_html__('settings page', 'infy-ai-image-seo-optimizer'),
                    ],

                    // Optimization status stages
                    'status_preparing'              => __( 'Preparing image...', 'infy-ai-image-seo-optimizer' ),
                    /* translators: %s: AI model name (e.g., "Gemini 3 Flash") */
                    'status_sending'                => __( 'Sending to %s...', 'infy-ai-image-seo-optimizer' ),
                    'status_analyzing'              => __( 'AI is analyzing...', 'infy-ai-image-seo-optimizer' ),
                    'status_generating'             => __( 'Generating metadata...', 'infy-ai-image-seo-optimizer' ),
                    'status_still'                  => __( 'Still processing...', 'infy-ai-image-seo-optimizer' ),

                    // Image Table / Single Optimize
                    'optimizing'                    => __('Optimizing...', 'infy-ai-image-seo-optimizer'),
                    'optimized_with_ai'             => __('Optimized with AI', 'infy-ai-image-seo-optimizer'),
                    'reoptimize'                    => __('Re-optimize', 'infy-ai-image-seo-optimizer'),
                    'custom_prompt_title'           => __('Custom Prompt', 'infy-ai-image-seo-optimizer'),
                    'custom_prompt_description'     => __('Add specific instructions for optimizing this image.', 'infy-ai-image-seo-optimizer'),
                    'custom_prompt_placeholder'     => __('A mountain view in California during sunrise, focus on travel and nature keywords', 'infy-ai-image-seo-optimizer'),
                    'page_context_title'            => __('Page Context', 'infy-ai-image-seo-optimizer'),
                    'page_context_description'      => __('Auto-generate context from the page where this image is used.', 'infy-ai-image-seo-optimizer'),
                    'context_placeholder'           => __('Context will appear here...', 'infy-ai-image-seo-optimizer'),
                    'generate_context'              => __('Generate Context', 'infy-ai-image-seo-optimizer'),
                    'generating_context'            => __('Analyzing page content...', 'infy-ai-image-seo-optimizer'),
                    'context_generated'             => __('Context generated!', 'infy-ai-image-seo-optimizer'),
                    'context_error'                 => __('Could not generate context', 'infy-ai-image-seo-optimizer'),
                    'no_usage_found'                => __('This image is not used on any page yet.', 'infy-ai-image-seo-optimizer'),
                    'select_page_for_context'       => __('This image is used on multiple pages. Select one:', 'infy-ai-image-seo-optimizer'),
                    'skip_custom_prompt'            => __('Skip', 'infy-ai-image-seo-optimizer'),
                    'optimize_with_prompt'          => __('Optimize with AI', 'infy-ai-image-seo-optimizer'),
                    'optimization_successful'       => __('Optimization successful!', 'infy-ai-image-seo-optimizer'),
                    'optimization_failed'           => __('Optimization failed', 'infy-ai-image-seo-optimizer'),
                    'error_updating_image_data'     => __('Error updating image data:', 'infy-ai-image-seo-optimizer'),
                    'error_optimizing_image'        => __('Error optimizing image:', 'infy-ai-image-seo-optimizer'),
                    'error_fetching_image_usage'    => __('Error fetching image usage:', 'infy-ai-image-seo-optimizer'),

                    // Logs
                    'confirm_clear_logs'            => __('Are you sure you want to clear all logs?', 'infy-ai-image-seo-optimizer'),
                    'logs_cleared'                  => __('Logs cleared successfully.', 'infy-ai-image-seo-optimizer'),
                    'error_clearing_logs'           => __('Error clearing logs.', 'infy-ai-image-seo-optimizer'),
                    'error_fetching_logs'           => __('Error fetching logs:', 'infy-ai-image-seo-optimizer'),
                    
                    // Field Validation Feedback
                    'missing'                       => __('Missing', 'infy-ai-image-seo-optimizer'),
                    'optimal'                       => __('Optimal', 'infy-ai-image-seo-optimizer'),
                    'set'                           => __('Optimized', 'infy-ai-image-seo-optimizer'),
                    'too_short'                     => __('Too short', 'infy-ai-image-seo-optimizer'),
                    'too_long'                      => __('Too long', 'infy-ai-image-seo-optimizer'),
                    'slightly_outside'              => __('Slightly outside the target range — still fine.', 'infy-ai-image-seo-optimizer'),
                    'word_count_off'                => __('Word count off', 'infy-ai-image-seo-optimizer'),
                    'generic'                       => __('Generic', 'infy-ai-image-seo-optimizer'),
                    'generic_filename'              => __('Generic filename', 'infy-ai-image-seo-optimizer'),
                    'needs_improvement'             => __('Needs improvement', 'infy-ai-image-seo-optimizer'),
                    'needs_more_words'              => __('Needs more words', 'infy-ai-image-seo-optimizer'),
                    'too_many_words'                => __('Too many words', 'infy-ai-image-seo-optimizer'),
                    'missing_alt_text'              => __('Missing alt text', 'infy-ai-image-seo-optimizer'),
                    'missing_title'                 => __('Missing title', 'infy-ai-image-seo-optimizer'),
                    'lowercase_only'                => __('lowercase only', 'infy-ai-image-seo-optimizer'),
                    'use_hyphens'                   => __('use hyphens', 'infy-ai-image-seo-optimizer'),
                    'no_spaces'                     => __('no spaces', 'infy-ai-image-seo-optimizer'),
                    'edit_filename'                 => __('Edit Filename', 'infy-ai-image-seo-optimizer'),
                    'enter_filename'                => __('Enter SEO-friendly filename...', 'infy-ai-image-seo-optimizer'),
                    'use_lowercase'                 => __('Use lowercase letters only', 'infy-ai-image-seo-optimizer'),
                    'optimal_length'                => __('Optimal', 'infy-ai-image-seo-optimizer'),
                    'include_keywords'              => __('Include relevant keywords', 'infy-ai-image-seo-optimizer'),
                    'avoid_generic'                 => __('Avoid generic names (DSC, IMG, image + numbers)', 'infy-ai-image-seo-optimizer'),
                    
                    // SEO Configuration
                    /* translators: %d: current total weight percentage */
                    'total_weight_error'            => __('Total weight must equal 100% (currently %d%)', 'infy-ai-image-seo-optimizer'),
                    'saving_configuration'          => __('Saving configuration...', 'infy-ai-image-seo-optimizer'),
                    'configuration_saved'           => __('Configuration saved successfully!', 'infy-ai-image-seo-optimizer'),
                    'failed_save_configuration'     => __('Failed to save configuration', 'infy-ai-image-seo-optimizer'),
                    'error_saving_configuration'    => __('An error occurred while saving', 'infy-ai-image-seo-optimizer'),
                    'confirm_reset_weights'         => __('Are you sure you want to reset all weights to default values?', 'infy-ai-image-seo-optimizer'),
                    'reset_to_default'              => __('Reset to default values', 'infy-ai-image-seo-optimizer'),
                    /* translators: %d: percentage to reduce */
                    'reduce_weights_message'        => __('Reduce weights by %s% to reach 100%', 'infy-ai-image-seo-optimizer'),
                    /* translators: %s: percentage to add */
                    'add_weights_message'           => __('Add %s% more to reach 100%', 'infy-ai-image-seo-optimizer'),
                    
                    // Image Table
                    'no_image_id_found'             => __('No image ID found', 'infy-ai-image-seo-optimizer'),
                    'error_no_image_id'             => __('Error: No image ID found', 'infy-ai-image-seo-optimizer'),
                    'loading'                       => __('Loading...', 'infy-ai-image-seo-optimizer'),
                    'no_images'                     => __('No images found.', 'infy-ai-image-seo-optimizer'),
                    'retry'                         => __('Retry', 'infy-ai-image-seo-optimizer'),
                    'load_table'                    => __('Load Image Table', 'infy-ai-image-seo-optimizer'),
                    'table_load_failed'             => __('Table could not load automatically.', 'infy-ai-image-seo-optimizer'),
                    'preparing_library'             => __('Preparing your image library for faster performance...', 'infy-ai-image-seo-optimizer'),
                    
                    // Debug Page
                    'debug_exported_successfully'   => __('Debug information exported successfully!', 'infy-ai-image-seo-optimizer'),
                    /* translators: %1$s: number of successful tests, %2$s: number of failed tests */
                    'api_tests_completed'           => __('API Tests completed: %1$s successful, %2$s failed', 'infy-ai-image-seo-optimizer'),
                    /* translators: %s: error message */
                    'error_testing_apis'            => __('Error testing APIs: %s', 'infy-ai-image-seo-optimizer'),
                    /* translators: %s: action name */
                    'unknown_suggestion_action'     => __('Unknown suggestion action: %s', 'infy-ai-image-seo-optimizer'),
                    'applying'                      => __('Applying...', 'infy-ai-image-seo-optimizer'),
                    'loading_more_logs'             => __('Loading more logs...', 'infy-ai-image-seo-optimizer'),
                    'show_more_logs'                => __('Show More Logs', 'infy-ai-image-seo-optimizer'),
                    'no_more_logs'                  => __('No more logs to show', 'infy-ai-image-seo-optimizer'),
                    'error_loading_logs'            => __('Error loading more logs', 'infy-ai-image-seo-optimizer'),
                    'confirm_clear_cache'           => __('Are you sure you want to clear all cache? This may temporarily slow down the site.', 'infy-ai-image-seo-optimizer'),
                    'confirm_clear_debug_logs'      => __('Are you sure you want to clear all debug logs? This action cannot be undone.', 'infy-ai-image-seo-optimizer'),
                    
                    // Keywords Management
                    'edit_keywords'                 => __('Edit Keywords', 'infy-ai-image-seo-optimizer'),
                    'add_keywords'                  => __('Add Keywords', 'infy-ai-image-seo-optimizer'),
                    'generate_keywords'             => __('Generate with AI', 'infy-ai-image-seo-optimizer'),
                    'generating_keywords'           => __('Generating keywords...', 'infy-ai-image-seo-optimizer'),
                    'keyword_placeholder'           => __('Type keyword and press Enter', 'infy-ai-image-seo-optimizer'),
                    'max_keywords_reached'          => __('Maximum 3 keywords allowed', 'infy-ai-image-seo-optimizer'),
                    'keyword_too_short'             => __('Keyword must be at least 3 characters', 'infy-ai-image-seo-optimizer'),
                    'keyword_too_long'              => __('Keyword must be less than 30 characters', 'infy-ai-image-seo-optimizer'),
                    'keywords_updated'              => __('Keywords updated successfully', 'infy-ai-image-seo-optimizer'),
                    'keywords_generated'            => __('Keywords generated successfully', 'infy-ai-image-seo-optimizer'),
                    'keywords_cleared'              => __('Keywords cleared', 'infy-ai-image-seo-optimizer'),
                    'clear_keywords'                => __('Clear Keywords', 'infy-ai-image-seo-optimizer'),
                    'clear_keywords_confirm'        => __('Are you sure you want to clear all keywords for this image?', 'infy-ai-image-seo-optimizer'),
                    'clear'                         => __('Clear', 'infy-ai-image-seo-optimizer'),
                    'clearing'                      => __('Clearing...', 'infy-ai-image-seo-optimizer'),
                    'error_generating_keywords'     => __('Error generating keywords', 'infy-ai-image-seo-optimizer'),
                    'alt_text_updated'              => __('Alt text updated successfully', 'infy-ai-image-seo-optimizer'),
                    'filename_updated'              => __('Filename updated successfully', 'infy-ai-image-seo-optimizer'),
                    'title_updated'                 => __('Title updated successfully', 'infy-ai-image-seo-optimizer'),
                    'keyword_usage'                 => __('Keyword usage', 'infy-ai-image-seo-optimizer'),
                    'keyword_usage_hint'            => __('Include keywords naturally in your text for better SEO', 'infy-ai-image-seo-optimizer'),
                    'keywords_best_practices'       => __('Best Practices', 'infy-ai-image-seo-optimizer'),
                    'keywords_help_1'               => __('Use 1-3 relevant keywords', 'infy-ai-image-seo-optimizer'),
                    'keywords_help_2'               => __('Each keyword should be 1-3 words', 'infy-ai-image-seo-optimizer'),
                    'keywords_help_3'               => __('Focus on search terms', 'infy-ai-image-seo-optimizer'),
                    'keywords_help_4'               => __('Be specific and relevant', 'infy-ai-image-seo-optimizer'),
                    'keywords_help'                 => __('Separate keywords (maximum 3 phrases) with commas or press Enter', 'infy-ai-image-seo-optimizer'),
                    'click_to_add_keywords'         => __('Click to add keywords manually or generate with AI', 'infy-ai-image-seo-optimizer'),
                    'keywords_tooltip'              => __('Click to add keywords manually or generate with AI', 'infy-ai-image-seo-optimizer'),
                    'no_special_chars'              => __('no special chars', 'infy-ai-image-seo-optimizer'),
                    'fully_optimized'               => __('Optimized', 'infy-ai-image-seo-optimizer'),
                    'check_seo_tooltip'             => __('Click to see what\'s needed for 100% score', 'infy-ai-image-seo-optimizer'),
                    'seo_score_breakdown'           => __('SEO Score Breakdown', 'infy-ai-image-seo-optimizer'),
                    'ai_disclaimer_short'           => __('AI Disclaimer', 'infy-ai-image-seo-optimizer'),
                    'ai_disclaimer'                 => __('AI can make mistakes. Please double-check responses.', 'infy-ai-image-seo-optimizer'),
                    
                    // SEO Status strings
                    'status_optimized'              => __('Optimized', 'infy-ai-image-seo-optimizer'),
                    'status_good'                   => __('Good', 'infy-ai-image-seo-optimizer'),
                    'status_fair'                   => __('Fair', 'infy-ai-image-seo-optimizer'),
                    'status_poor'                   => __('Poor', 'infy-ai-image-seo-optimizer'),

                    // Modal score descriptions
                    'score_excellent'               => __('Excellent! Your alt text is well-optimized for SEO.', 'infy-ai-image-seo-optimizer'),
                    'score_good'                    => __('Good progress! Review the details below to reach 100%.', 'infy-ai-image-seo-optimizer'),
                    'score_needs_work'              => __('Needs improvement. Follow the guidelines below to optimize your alt text.', 'infy-ai-image-seo-optimizer'),

                    // Modal recommendations
                    'alt_text_recommendations'      => __('Alt Text Recommendations', 'infy-ai-image-seo-optimizer'),
                    'describe_image_content'        => __('Describe what\'s actually in the image', 'infy-ai-image-seo-optimizer'),
                    'include_keywords_naturally'    => __('Include relevant keywords naturally', 'infy-ai-image-seo-optimizer'),
                    'dont_start_with_image_of'      => __('Don\'t start with "image of" or "picture of"', 'infy-ai-image-seo-optimizer'),
                    'be_specific_descriptive'       => __('Be specific and descriptive', 'infy-ai-image-seo-optimizer'),
                    'title_recommendations'         => __('Title Recommendations', 'infy-ai-image-seo-optimizer'),
                    'keep_shorter_than_alt'         => __('Keep it shorter than alt text', 'infy-ai-image-seo-optimizer'),
                    'dont_duplicate_alt'            => __('Don\'t duplicate alt text', 'infy-ai-image-seo-optimizer'),
                    'be_descriptive_not_numbers'    => __('Be descriptive, not just numbers', 'infy-ai-image-seo-optimizer'),
                    
                    // Title specific strings
                    'title_presence'                => __('Title Presence', 'infy-ai-image-seo-optimizer'),
                    'title_present'                 => __('Title is present', 'infy-ai-image-seo-optimizer'),
                    'title_missing'                 => __('Add a title for better SEO', 'infy-ai-image-seo-optimizer'),
                    'title_quality'                 => __('Title Quality', 'infy-ai-image-seo-optimizer'),
                    /* translators: %s: Number of characters */
                    'add_more_characters'           => __('Add %s more characters', 'infy-ai-image-seo-optimizer'),
                    /* translators: %s: Number of characters */
                    'remove_characters'             => __('Remove %s characters', 'infy-ai-image-seo-optimizer'),
                    /* translators: %s: Number of words */
                    'add_more_words'                => __('Add %s more words', 'infy-ai-image-seo-optimizer'),
                    /* translators: %s: Number of words */
                    'remove_words'                  => __('Remove %s words', 'infy-ai-image-seo-optimizer'),
                    
                    // Score breakdown strings
                    'alt_text_presence'             => __('Alt Text Presence', 'infy-ai-image-seo-optimizer'),
                    'alt_text_quality'              => __('Alt Text Quality', 'infy-ai-image-seo-optimizer'),
                    'alt_text_quality_missing'      => __('Add alt text first to improve quality score', 'infy-ai-image-seo-optimizer'),
                    'add_alt_text'                  => __('Add alt text', 'infy-ai-image-seo-optimizer'),
                    'missing_alt_text_singular'     => __('Missing Alt Text', 'infy-ai-image-seo-optimizer'),
                    
                    // Feedback messages
                    'generic'                       => __('Generic', 'infy-ai-image-seo-optimizer'),
                    'too_short'                     => __('Too short', 'infy-ai-image-seo-optimizer'),
                    'too_long'                      => __('Too long', 'infy-ai-image-seo-optimizer'),
                    'slightly_outside'              => __('Slightly outside the target range — still fine.', 'infy-ai-image-seo-optimizer'),
                    'good_length'                   => __('Good length', 'infy-ai-image-seo-optimizer'),
                    'optimized'                     => __('Optimized', 'infy-ai-image-seo-optimizer'),
                    'missing'                       => __('Missing', 'infy-ai-image-seo-optimizer'),
                    'invalid_format'                => __('Invalid format', 'infy-ai-image-seo-optimizer'),
                    'valid_format'                  => __('Valid format', 'infy-ai-image-seo-optimizer'),
                    'correct_format'                => __('Correct format', 'infy-ai-image-seo-optimizer'),
                    'format_label'                  => __('Format:', 'infy-ai-image-seo-optimizer'),
                    /* translators: %s: Number of words */
                    'reduce_by_words'               => __('Reduce by %s words', 'infy-ai-image-seo-optimizer'),
                    'use_lowercase_hyphens'         => __('Use lowercase letters and hyphens only', 'infy-ai-image-seo-optimizer'),
                    'chars'                         => __('chars', 'infy-ai-image-seo-optimizer'),
                    'words'                         => __('words', 'infy-ai-image-seo-optimizer'),
                    
                    // Filename modal specific
                    'filename_guidelines'           => __('Filename Guidelines', 'infy-ai-image-seo-optimizer'),
                    'filename_score'                => __('Filename Score', 'infy-ai-image-seo-optimizer'),
                    'filename_quality'              => __('Filename Quality', 'infy-ai-image-seo-optimizer'),
                    
                    // Title modal specific
                    'title_guidelines'              => __('Title Guidelines', 'infy-ai-image-seo-optimizer'),
                    'title_score'                   => __('Title Score', 'infy-ai-image-seo-optimizer'),
                    
                    // Modal common elements
                    'edit_alt_text'                 => __('Edit Alt Text', 'infy-ai-image-seo-optimizer'),
                    'edit_title'                    => __('Edit Title', 'infy-ai-image-seo-optimizer'),
                    'alt_text_score'                => __('Alt Text Score', 'infy-ai-image-seo-optimizer'),
                    'enter_alt_text'                => __('Enter descriptive alt text for this image...', 'infy-ai-image-seo-optimizer'),
                    'enter_title'                   => __('Enter descriptive title...', 'infy-ai-image-seo-optimizer'),
                    
                    // SEO Score modal
                    'current_seo_score'             => __('Current SEO Score', 'infy-ai-image-seo-optimizer'),
                    'overall_seo_score'             => __('Overall SEO Score', 'infy-ai-image-seo-optimizer'),
                    'keyword_score'                 => __('Keyword Usage Score', 'infy-ai-image-seo-optimizer'),
                    'uniqueness_score'              => __('Uniqueness Score', 'infy-ai-image-seo-optimizer'),
                    'keywords_found'                => __('Keywords Found', 'infy-ai-image-seo-optimizer'),
                    'no_keywords'                   => __('No Keywords', 'infy-ai-image-seo-optimizer'),
                    'has_keywords'                  => __('Has Keywords', 'infy-ai-image-seo-optimizer'),
                    'fields_with_keywords'          => __('Fields with Keywords', 'infy-ai-image-seo-optimizer'),
                    'keywords_label'                => __('Keywords', 'infy-ai-image-seo-optimizer'),
                    'alt_text_label'                => __('Alt Text', 'infy-ai-image-seo-optimizer'),
                    'title_label'                   => __('Title', 'infy-ai-image-seo-optimizer'),
                    'filename_label'                => __('Filename', 'infy-ai-image-seo-optimizer'),
                    'optimize_now'                  => __('Optimize with AI', 'infy-ai-image-seo-optimizer'),
                    'quick_fix'                     => __('Quick fix:', 'infy-ai-image-seo-optimizer'),
                    'optimize_with_ai_help'         => __('Click "Optimize with AI" to automatically improve all missing elements', 'infy-ai-image-seo-optimizer'),
                    'perfect_score'                 => __('Perfect SEO Score!', 'infy-ai-image-seo-optimizer'),
                    'no_adjustments_needed'         => __('No adjustments needed. Your image is fully optimized for SEO.', 'infy-ai-image-seo-optimizer'),
                    'seo_score_breakdown'           => __('SEO Score Breakdown', 'infy-ai-image-seo-optimizer'),
                    'to_reach_100'                  => __('To reach 100% SEO score:', 'infy-ai-image-seo-optimizer'),
                    'pts_needed'                    => __('pts needed', 'infy-ai-image-seo-optimizer'),
                    'alt_text_missing'              => __('Add alt text to improve accessibility and SEO', 'infy-ai-image-seo-optimizer'),
                    'alt_text_present'              => __('Alt text is present', 'infy-ai-image-seo-optimizer'),
                    'title_text_presence'           => __('Title Text Presence', 'infy-ai-image-seo-optimizer'),
                    'title_text_quality'            => __('Title Text Quality', 'infy-ai-image-seo-optimizer'),
                    'title_text_present'            => __('Title text is present', 'infy-ai-image-seo-optimizer'),
                    'current_value'                 => __('Current value:', 'infy-ai-image-seo-optimizer'),
                    'characters_label'              => __('Characters:', 'infy-ai-image-seo-optimizer'),
                    'words_label'                   => __('Words:', 'infy-ai-image-seo-optimizer'),
                    'no_keywords_defined'           => __('No keywords defined', 'infy-ai-image-seo-optimizer'),
                    'use_ai_to_generate_keywords'   => __('Use AI optimization to generate keywords', 'infy-ai-image-seo-optimizer'),
                    'all_metadata_unique'           => __('All metadata is unique', 'infy-ai-image-seo-optimizer'),
                    'uniqueness'                    => __('Uniqueness', 'infy-ai-image-seo-optimizer'),
                    'keyword_density'               => __('Keyword Density', 'infy-ai-image-seo-optimizer'),
                    'optimal'                       => __('optimal', 'infy-ai-image-seo-optimizer'),
                    'no_keywords_defined_msg'       => __('No keywords defined. Use AI optimization to generate keywords.', 'infy-ai-image-seo-optimizer'),
                    'action_needed'                 => __('Action needed:', 'infy-ai-image-seo-optimizer'),
                    'add_keywords_to_improve'       => __('Add 2-3 relevant keywords to improve SEO', 'infy-ai-image-seo-optimizer'),
                    
                    // Table headers for SEO score breakdown
                    'criterion'                     => __('Criterion', 'infy-ai-image-seo-optimizer'),
                    'points_needed'                 => __('Points needed', 'infy-ai-image-seo-optimizer'),
                    'total'                         => __('Total', 'infy-ai-image-seo-optimizer'),
                    
                    // Validation messages
                    'perfect'                       => __('Perfect', 'infy-ai-image-seo-optimizer'),
                    'could_be_better'               => __('Could be better', 'infy-ai-image-seo-optimizer'),
                    
                    // Form elements
                    'remove_keyword'                => __('Remove keyword', 'infy-ai-image-seo-optimizer'),
                    'image_preview'                 => __('Image preview', 'infy-ai-image-seo-optimizer'),
                    'characters'                    => __('characters', 'infy-ai-image-seo-optimizer'),
                    'optimize_with_ai'              => __('Optimize with AI', 'infy-ai-image-seo-optimizer'),
                    
                    // Dashboard widget strings
                    'improve_your_seo'              => __('Improve Your Image SEO', 'infy-ai-image-seo-optimizer'),
                    'optimize_images_description'   => __('Optimize your images with AI-generated filenames, alt text and titles to improve search engine rankings and accessibility.', 'infy-ai-image-seo-optimizer'),
                    'total_images'                  => __('Total Images', 'infy-ai-image-seo-optimizer'),
                    'missing_alt_text'              => __('Missing Alt Text', 'infy-ai-image-seo-optimizer'),
                    'missing_title_text'            => __('Missing Title Text', 'infy-ai-image-seo-optimizer'),
                    /* translators: %s: Percentage number */
                    'percent_optimized'             => __('%s%% optimized', 'infy-ai-image-seo-optimizer'),
                    
                    'ai_settings'                   => __('AI Settings', 'infy-ai-image-seo-optimizer'),

                    // Filter strings
                    'max_filters_allowed'           => __('Max 3 filters allowed', 'infy-ai-image-seo-optimizer'),
                    'check_live_usage'              => __('Check Usage', 'infy-ai-image-seo-optimizer'),

                    // Live search strings
                    'no_images_found_search'        => __('No images found', 'infy-ai-image-seo-optimizer'),
                    /* translators: %d: Number of results */
                    'view_all_results'              => __('View all %d results', 'infy-ai-image-seo-optimizer'),
                    /* translators: %d: Number of images */
                    'images_found'                  => __('%d images found', 'infy-ai-image-seo-optimizer'),
                    'image_usage'                   => __('Image Usage', 'infy-ai-image-seo-optimizer'),
                    'where_used'                    => __('Where This Image Is Used', 'infy-ai-image-seo-optimizer'),
                    'usage_types'                   => __('Usage Types', 'infy-ai-image-seo-optimizer'),
                    'featured_image'                => __('Featured Image', 'infy-ai-image-seo-optimizer'),
                    'featured_image_desc'           => __('Used as the main image for a post or page', 'infy-ai-image-seo-optimizer'),
                    'in_content'                    => __('In Content', 'infy-ai-image-seo-optimizer'),
                    'in_content_desc'               => __('Embedded within the post or page content', 'infy-ai-image-seo-optimizer'),
                    'page_builder'                  => __('Page Builder', 'infy-ai-image-seo-optimizer'),
                    'page_builder_desc'             => __('Used in page builder elements (Elementor, etc.)', 'infy-ai-image-seo-optimizer'),
                    'refresh'                       => __('Refresh', 'infy-ai-image-seo-optimizer'),
                    'close'                         => __('Close', 'infy-ai-image-seo-optimizer'),
                ]),
            ];
            $localize_data = apply_filters('infyp_admin_localize_data', $localize_data);
            wp_localize_script('infyp-admin-script', 'infyp_vars', $localize_data);
        }

        // Enqueue on media-related pages to add button to modal
        if (in_array($hook, ['post.php', 'post-new.php', 'upload.php'])) {
            // Enqueue script for attachment edit page
            if ($hook === 'post.php' && get_post_type() === 'attachment') {
                wp_enqueue_script(
                    'infyp-attachment-page-script',
                    \INFYP_PLUGIN_URL . 'assets/js/attachment-page.js',
                    ['jquery'],
                    \INFYP_VERSION,
                    true
                );
                wp_localize_script('infyp-attachment-page-script', 'infyp_vars', array(
                    'nonce' => wp_create_nonce('infyp_ajax_nonce'),
                    'i18n' => [
                        'optimizing' => __('Optimizing...', 'infy-ai-image-seo-optimizer'),
                        'filenameChangedReload' => __('Filename changed. Please reload the page to see all updates.', 'infy-ai-image-seo-optimizer'),
                        'unexpectedError' => __('An unexpected error occurred.', 'infy-ai-image-seo-optimizer'),
                    ]
                ));
            }

            /**
             * Fires on media-related admin pages (post.php, post-new.php, upload.php)
             * so extensions can enqueue their own media library integration scripts.
             *
             * @since 1.0.0
             *
             * @param string $hook The current admin page hook suffix.
             */
            do_action( 'infyp_enqueue_media_modal_scripts', $hook );
        }
    }

    /**
     * Adds type="module" to our script tag to enable ES6 modules.
     *
     * Filters the script tag to add the module type attribute for ES6 support.
     *
     * @since 1.0.0
     * @param string $tag    The script tag HTML.
     * @param string $handle The script handle.
     * @param string $src    The script source URL.
     * @return string Modified script tag with type="module" for our admin script.
     */
    public function add_type_to_script($tag, $handle, $src) {
        if ('infyp-admin-script' === $handle) {
            // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Modifying already enqueued script tag to add type="module"
            $tag = '<script type="module" src="' . esc_url($src) . '" id="' . $handle . '-js"></script>';
        }
        return $tag;
    }

    /**
     * Get initial table data for inline loading (Phase 3 Performance Optimization)
     *
     * Pre-renders the first page of table data server-side to eliminate
     * the initial AJAX call. This improves initial load by 200-400ms.
     *
     * @since 1.0.0
     * @return array|null Initial table data or null if unable to generate
     */
    private function get_initial_table_data() {
        // Avoid loading if table class doesn't exist
        if ( ! class_exists( '\IPAIS\Tables\INFYP_Image_List_Table' ) ) {
            return null;
        }

        try {
            // Create table instance
            $table = new \IPAIS\Tables\INFYP_Image_List_Table();

            // Get per_page from screen options
            $per_page = (int) get_user_option( 'images_per_page' );
            if ( ! $per_page || $per_page < 1 ) {
                $per_page = 20;
            }

            // Read URL filters so inline data matches the JS ajax-table-loader state.
            // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only UI state from URL params
            $orderby          = isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : 'seo_score';
            $order            = isset( $_REQUEST['order'] ) ? sanitize_key( wp_unslash( $_REQUEST['order'] ) ) : 'asc';
            $post_type_filter = isset( $_REQUEST['post_type_filter'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['post_type_filter'] ) ) : null;
            $parent_post      = isset( $_REQUEST['parent_post'] ) ? absint( $_REQUEST['parent_post'] ) : 0;
            // phpcs:enable WordPress.Security.NonceVerification.Recommended

            // Get images for first page — parameters must match parseUrlState() defaults
            // so the inline HTML and the AJAX table loader state are in sync.
            $result = $table->get_images( $per_page, 1, '', $orderby, $order, null, $post_type_filter, $parent_post );
            $images = $result['items'] ?? [];
            $total = $result['total'] ?? 0;

            if ( empty( $images ) ) {
                // Return empty state data
                return [
                    'has_items'       => false,
                    'rows_html'       => '',
                    'pagination_html' => '',
                    'total'           => 0,
                    'total_pages'     => 1,
                    'current_page'    => 1,
                    'per_page'        => $per_page,
                ];
            }

            // Extract image IDs for batch metadata loading
            $image_ids = array_map( function( $image ) {
                return $image->ID;
            }, $images );

            // Prepare items for rendering (this preloads metadata)
            $table->prepare_items_for_ajax( $image_ids );

            // Render table rows
            ob_start();
            foreach ( $images as $image ) {
                $table->single_row( $image );
            }
            $rows_html = ob_get_clean();

            // Calculate pagination
            $total_pages = (int) ceil( $total / $per_page );

            // Generate pagination HTML using AJAX-compatible format
            // Must match the format used by INFYP_Ajax_Handlers::build_pagination_html()
            $pagination_html = $this->build_ajax_pagination_html( 1, $total_pages, $total, $per_page );

            // Get cached filter counts for display
            $cache_manager = INFYP_Cache_Manager::get_instance();
            $filter_counts = $cache_manager->get_cached_filter_counts();

            return [
                'has_items'       => true,
                'rows_html'       => $rows_html,
                'pagination_html' => $pagination_html,
                'total'           => $total,
                'total_pages'     => $total_pages,
                'current_page'    => 1,
                'per_page'        => $per_page,
                'filter_counts'   => $filter_counts,
            ];

        } catch ( \Exception $e ) {
            // Log error and fall back to AJAX loading
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging only when WP_DEBUG is enabled
                error_log( 'INFYP: Error generating initial table data: ' . $e->getMessage() );
            }
            return null;
        }
    }

    /**
     * Build AJAX-compatible pagination HTML
     *
     * Generates pagination with buttons that have .infyp-ajax-page class
     * and data-page attributes for JavaScript handling. Must match the format
     * used by INFYP_Ajax_Handlers::build_pagination_html().
     *
     * @since 1.0.0
     * @param int $current_page Current page number
     * @param int $total_pages Total number of pages
     * @param int $total_items Total number of items
     * @param int $per_page Items per page
     * @return string Pagination HTML
     */
    private function build_ajax_pagination_html( $current_page, $total_pages, $total_items, $per_page ) {
        if ( $total_pages <= 1 ) {
            return '<span class="displaying-num">' . sprintf(
                /* translators: %s: Number of items */
                _n( '%s item', '%s items', $total_items, 'infy-ai-image-seo-optimizer' ),
                number_format_i18n( $total_items )
            ) . '</span>';
        }

        $output = '<span class="displaying-num">' . sprintf(
            /* translators: %s: Number of items */
            _n( '%s item', '%s items', $total_items, 'infy-ai-image-seo-optimizer' ),
            number_format_i18n( $total_items )
        ) . '</span>';

        $output .= '<span class="pagination-links">';

        // First page link
        $output .= '<button type="button" class="first-page button infyp-ajax-page" data-page="1"' .
                   ( $current_page <= 1 ? ' disabled' : '' ) . '>';
        $output .= '<span class="screen-reader-text">' . __( 'First page', 'infy-ai-image-seo-optimizer' ) . '</span>';
        $output .= '<span aria-hidden="true">&laquo;</span></button>';

        // Previous page link
        $output .= '<button type="button" class="prev-page button infyp-ajax-page" data-page="' . esc_attr( max( 1, $current_page - 1 ) ) . '"' .
                   ( $current_page <= 1 ? ' disabled' : '' ) . '>';
        $output .= '<span class="screen-reader-text">' . __( 'Previous page', 'infy-ai-image-seo-optimizer' ) . '</span>';
        $output .= '<span aria-hidden="true">&lsaquo;</span></button>';

        // Current page input
        $output .= '<span class="paging-input">';
        $output .= '<label for="current-page-selector" class="screen-reader-text">' . __( 'Current Page', 'infy-ai-image-seo-optimizer' ) . '</label>';
        $output .= '<input class="current-page infyp-page-input" id="current-page-selector" type="text" name="paged" value="' . esc_attr( $current_page ) . '" size="' . esc_attr( strlen( $total_pages ) ) . '" aria-describedby="table-paging">';
        $output .= '<span class="tablenav-paging-text"> ' . __( 'of', 'infy-ai-image-seo-optimizer' ) . ' <span class="total-pages">' . esc_html( number_format_i18n( $total_pages ) ) . '</span></span>';
        $output .= '</span>';

        // Next page link
        $output .= '<button type="button" class="next-page button infyp-ajax-page" data-page="' . esc_attr( min( $total_pages, $current_page + 1 ) ) . '"' .
                   ( $current_page >= $total_pages ? ' disabled' : '' ) . '>';
        $output .= '<span class="screen-reader-text">' . __( 'Next page', 'infy-ai-image-seo-optimizer' ) . '</span>';
        $output .= '<span aria-hidden="true">&rsaquo;</span></button>';

        // Last page link
        $output .= '<button type="button" class="last-page button infyp-ajax-page" data-page="' . esc_attr( $total_pages ) . '"' .
                   ( $current_page >= $total_pages ? ' disabled' : '' ) . '>';
        $output .= '<span class="screen-reader-text">' . __( 'Last page', 'infy-ai-image-seo-optimizer' ) . '</span>';
        $output .= '<span aria-hidden="true">&raquo;</span></button>';

        $output .= '</span>';

        return $output;
    }
}