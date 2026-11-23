<?php
/**
 * Setup Wizard - Guided First-Run Configuration
 *
 * @package   IPAIS\Admin
 * @since 1.0.0
 * @author    InfyPress
 * @license   GPL-2.0-or-later
 */

namespace IPAIS\Admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use IPAIS\Core\INFYP_API_Key_Manager;
use IPAIS\Core\INFYP_Language_Manager;

/**
 * Setup Wizard Class
 *
 * Provides a fullscreen, step-by-step setup experience for first-time users
 * to configure API keys and preferences before using the plugin.
 *
 * Wizard Steps:
 * 1. Welcome   — Introduction and feature overview
 * 2. API Keys  — Configure at least one AI provider (OpenAI, Gemini, Anthropic)
 * 3. Preferences — Website type, language, image count, newsletter opt-in
 * 4. Complete  — Success confirmation and redirect to dashboard
 *
 * Manages the fullscreen setup wizard including activation detection,
 * step rendering, AJAX save handlers, and admin notice display.
 *
 * Lifecycle:
 * 1. Singleton instantiated during plugin bootstrap
 * 2. Hooks registered in constructor (admin_menu, admin_init, admin_notices, AJAX)
 * 3. On wizard page request: intercept at admin_init → render fullscreen → exit
 * 4. On AJAX: validate nonce/capability → process step data → respond
 * 5. On non-wizard pages: check if notice should display
 *
 * Hook Integration:
 * - `admin_menu`: Register hidden wizard submenu page (under options.php)
 * - `admin_init` (priority 1): Redirect to wizard on first activation
 * - `admin_init` (priority 5): Intercept wizard page for fullscreen rendering
 * - `admin_notices`: Show setup reminder on non-wizard admin pages
 * - `wp_ajax_infyp_wizard_*`: Five AJAX handlers for wizard interactions
 *
 * Assets:
 * - CSS: framework/variables.css, framework/utilities.css, plugin/setup-wizard.css
 * - JS: setup-wizard.js (handles step navigation, API key validation, form submission)
 * - Fonts: Local plugin fonts (no external requests)
 *
 * @package   IPAIS\Admin
 * @since 1.0.0
 *
 * @see       INFYP_API_Key_Manager For API key storage and validation
 * @see       INFYP_Language_Manager For available language list
 * @see       includes/views/setup-wizard/wizard-page.php Main wizard template
 */
class INFYP_Setup_Wizard {

    /**
     * Singleton instance.
     *
     * @since 1.0.0
     * @var   INFYP_Setup_Wizard|null
     */
    private static $instance = null;

    /**
     * Wizard steps configuration.
     *
     * Lazy-loaded array of step definitions, each containing:
     * - 'name'  (string) Translated display label for the step indicator
     * - 'view'  (string) Template filename in views/setup-wizard/ (without .php)
     *
     * Populated on first call to get_steps() to avoid early translation loading.
     *
     * @since 1.0.0
     * @var   array<string, array{name: string, view: string}>
     */
    private $steps = [];

    /**
     * Returns the singleton instance.
     *
     * @since 1.0.0
     * @return INFYP_Setup_Wizard
     */
    public static function get_instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor. Registers all WordPress hooks for wizard functionality.
     *
     * Hooks are split by timing:
     * - admin_menu: Register the hidden wizard submenu page
     * - admin_init (priority 1): Check for activation redirect transient
     * - admin_init (priority 5): Intercept wizard page for fullscreen rendering
     * - admin_notices: Show setup reminder notice
     * - wp_ajax_*: Five AJAX handlers for wizard interactions
     *
     * @since 1.0.0
     */
    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_wizard_page' ] );
        add_action( 'admin_init', [ $this, 'maybe_redirect_to_wizard' ], 1 );
        add_action( 'admin_init', [ $this, 'maybe_render_fullscreen_wizard' ], 5 );
        add_action( 'admin_notices', [ $this, 'maybe_show_wizard_notice' ] );

        // AJAX handlers
        add_action( 'wp_ajax_infyp_wizard_save_step', [ $this, 'ajax_save_step' ] );
        add_action( 'wp_ajax_infyp_wizard_complete', [ $this, 'ajax_complete_wizard' ] );
        add_action( 'wp_ajax_infyp_wizard_skip', [ $this, 'ajax_skip_wizard' ] );
        add_action( 'wp_ajax_infyp_wizard_validate_api_key', [ $this, 'ajax_validate_api_key' ] );
        add_action( 'wp_ajax_infyp_dismiss_wizard_notice', [ $this, 'ajax_dismiss_wizard_notice' ] );
    }

    /**
     * Returns the wizard step definitions, lazy-loaded on first call.
     *
     * Steps are defined here (not in the constructor) because WordPress
     * translation functions (__()) must not be called before the 'init' hook.
     * Since admin_init fires after init, lazy-loading here is safe.
     *
     * @since 1.0.0
     * @access private
     *
     * @return array<string, array{name: string, view: string}> Step definitions keyed by slug.
     */
    private function get_steps() {
        if ( empty( $this->steps ) ) {
            $this->steps = [
                'welcome' => [
                    'name'    => __( 'Welcome', 'infy-ai-image-seo-optimizer' ),
                    'view'    => 'step-welcome',
                ],
                'api-keys' => [
                    'name'    => __( 'API Keys', 'infy-ai-image-seo-optimizer' ),
                    'view'    => 'step-api-keys',
                ],
                'preferences' => [
                    'name'    => __( 'Preferences', 'infy-ai-image-seo-optimizer' ),
                    'view'    => 'step-preferences',
                ],
                'complete' => [
                    'name'    => __( 'Complete', 'infy-ai-image-seo-optimizer' ),
                    'view'    => 'step-complete',
                ],
            ];
        }
        return $this->steps;
    }

    /**
     * Register the wizard page (hidden from menu)
     *
     * The wizard page is registered under 'options.php' to hide it from
     * the admin menu while still being accessible via direct URL.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function register_wizard_page() {
        add_submenu_page(
            'options.php', // Hidden from menu
            __( 'Setup Wizard', 'infy-ai-image-seo-optimizer' ),
            __( 'Setup Wizard', 'infy-ai-image-seo-optimizer' ),
            'manage_options',
            'ai-image-seo-optimizer-wizard',
            [ $this, 'render_wizard_page' ]
        );
    }

    /**
     * Check if wizard should be shown and redirect if needed
     *
     * Handles automatic redirect to the setup wizard on first plugin activation.
     * Uses a transient to track the activation event and prevent redirect loops.
     * Includes fallback to option storage for managed hosts where transients
     * may not persist (WPEngine, Kinsta, etc.).
     *
     * Security considerations:
     * - Only redirects admin users with 'manage_options' capability
     * - Uses wp_safe_redirect() for secure redirects
     * - Skips redirect during AJAX, bulk activations, or if already on wizard page
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function maybe_redirect_to_wizard() {
        // Only on admin
        if ( ! is_admin() || wp_doing_ajax() ) {
            return;
        }

        // Don't redirect if headers already sent
        if ( headers_sent() ) {
            return;
        }

        // Check user capability
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Don't redirect if wizard is completed
        if ( get_option( 'infyp_wizard_completed' ) ) {
            return;
        }

        // Don't redirect if already on wizard page
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( isset( $_GET['page'] ) && $_GET['page'] === 'ai-image-seo-optimizer-wizard' ) {
            return;
        }

        // Don't redirect on bulk activations
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( isset( $_GET['activate-multi'] ) ) {
            return;
        }

        // Check for activation redirect transient
        $should_redirect = false;

        if ( get_transient( 'infyp_wizard_redirect' ) ) {
            delete_transient( 'infyp_wizard_redirect' );
            $should_redirect = true;
        }

        // Fallback: Check option-based redirect for hosts with aggressive cache clearing
        // This handles WPEngine, Kinsta, and other managed hosts where transients
        // stored in object cache may be cleared during activation
        if ( ! $should_redirect ) {
            $redirect_time = get_option( 'infyp_wizard_redirect_time' );
            if ( $redirect_time && ( time() - (int) $redirect_time ) < 300 ) {
                // Within 5 minutes of activation, redirect to wizard
                delete_option( 'infyp_wizard_redirect_time' );
                $should_redirect = true;
            }
        }

        if ( $should_redirect ) {
            wp_safe_redirect( admin_url( 'admin.php?page=ai-image-seo-optimizer-wizard' ) );
            exit;
        }
    }

    /**
     * Render fullscreen wizard page (bypasses WordPress admin wrapper)
     *
     * Intercepts requests to the wizard page and renders a fullscreen
     * template that provides a focused setup experience without the
     * WordPress admin chrome.
     *
     * Security: Checks 'manage_options' capability before rendering.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function maybe_render_fullscreen_wizard() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'ai-image-seo-optimizer-wizard' ) {
            return;
        }

        // Check permissions
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Render the fullscreen wizard and exit
        $this->render_fullscreen_wizard();
        exit;
    }

    /**
     * Renders the fullscreen wizard page.
     *
     * Outputs a complete standalone HTML page (outside the WP admin wrapper)
     * with the wizard interface, including all necessary styles and scripts.
     * The template is responsible for the full HTML document structure.
     *
     * Data passed to the template ($data array):
     * - steps: Full step definitions from get_steps()
     * - current_step: Current step slug (from ?step= parameter, default 'welcome')
     * - current_index: Zero-based integer index of current step
     * - wizard_data: Previously saved wizard preferences from wp_options
     * - has_openai/has_google/has_anthropic: Whether each provider key is configured
     * - ready: Whether the active model can run (see is_ready_to_optimize())
     * - languages: Available language list from INFYP_Language_Manager
     *
     * @since 1.0.0
     * @access private
     *
     * @return void Outputs HTML. Caller must call exit() after this method.
     */
    private function render_fullscreen_wizard() {
        $steps = $this->get_steps();

        // Get current step
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $current_step = isset( $_GET['step'] ) ? sanitize_key( $_GET['step'] ) : 'welcome';

        if ( ! isset( $steps[ $current_step ] ) ) {
            $current_step = 'welcome';
        }

        // Get step index for progress
        $step_keys = array_keys( $steps );
        $current_index = array_search( $current_step, $step_keys, true );

        // Get existing data
        $wizard_data = get_option( 'infyp_wizard_data', [] );
        $api_key_manager = INFYP_API_Key_Manager::get_instance();

        // Prepare data for template
        $data = [
            'steps'         => $steps,
            'current_step'  => $current_step,
            'current_index' => $current_index,
            'wizard_data'   => $wizard_data,
            'has_openai'    => $api_key_manager->has_api_key( 'openai' ),
            'has_google'    => $api_key_manager->has_api_key( 'google' ),
            'has_anthropic' => $api_key_manager->has_api_key( 'anthropic' ),
            'masked_keys'   => $api_key_manager->get_all_api_keys( true ),
            'wp_ai_ready'   => \IPAIS\Core\AI\INFYP_WP_AI_Support::is_available() && \IPAIS\Core\AI\INFYP_WP_AI_Support::is_configured(),
            'connection_method' => \IPAIS\Core\AI\INFYP_WP_AI_Support::get_connection_method(),
            'ready'         => $this->is_ready_to_optimize(),
            'languages'     => INFYP_Language_Manager::get_available_languages(),
        ];

        // Enqueue wizard assets before rendering
        $this->enqueue_wizard_assets_direct();
        $this->isolate_wizard_assets();

        // Load the fullscreen wizard template
        include INFYP_PLUGIN_DIR . 'includes/views/setup-wizard/wizard-page.php';
    }

    /**
     * Enqueues wizard assets directly for the fullscreen wizard page.
     *
     * Called inside render_fullscreen_wizard() rather than via admin_enqueue_scripts
     * because the fullscreen wizard bypasses the normal WordPress admin rendering.
     * Assets must be registered and enqueued before the template calls wp_head().
     *
     * Asset chain (in dependency order):
     * 1. infyp-variables — CSS custom properties (colors, spacing, typography)
     * 2. infyp-utilities — Utility classes (flexbox, spacing, text)
     * 3. infyp-setup-wizard — Wizard-specific styles (steps, cards, forms)
     * 4. infyp-setup-wizard (JS) — Step navigation, AJAX saves, API validation
     *
     * Also cleans up the page by removing emoji scripts/styles and IE conditional
     * comments (deprecated in WP 6.9) to prevent console warnings.
     *
     * @since 1.0.0
     * @access private
     *
     * @return void
     */
    private function enqueue_wizard_assets_direct() {
        // Remove unnecessary WordPress defaults for clean wizard page
        remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
        remove_action( 'wp_print_styles', 'print_emoji_styles' );
        remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
        remove_action( 'admin_print_styles', 'print_emoji_styles' );

        // Remove IE conditional comments from scripts/styles to prevent WP 6.9+ deprecation warnings
        // These are added by themes/plugins for IE compatibility but are deprecated in WP 6.9
        add_action( 'wp_print_styles', [ $this, 'remove_ie_conditionals' ], 1 );
        add_action( 'wp_print_scripts', [ $this, 'remove_ie_conditionals' ], 1 );

        // Register and enqueue CSS variables
        wp_register_style(
            'infyp-variables',
            INFYP_PLUGIN_URL . 'assets/css/framework/variables.css',
            [],
            INFYP_VERSION
        );
        wp_enqueue_style( 'infyp-variables' );

        // Register and enqueue wizard styles
        wp_register_style(
            'infyp-setup-wizard',
            INFYP_PLUGIN_URL . 'assets/css/plugin/setup-wizard.css',
            [ 'infyp-variables' ],
            INFYP_VERSION
        );
        wp_enqueue_style( 'infyp-setup-wizard' );

        // Enqueue scripts
        wp_enqueue_script(
            'infyp-setup-wizard',
            INFYP_PLUGIN_URL . 'assets/js/setup-wizard.js',
            [ 'jquery' ],
            INFYP_VERSION,
            true
        );

        // Localize script
        wp_localize_script( 'infyp-setup-wizard', 'infyp_wizard', [
            'ajax_url'      => admin_url( 'admin-ajax.php' ),
            'nonce'         => wp_create_nonce( 'infyp_wizard_nonce' ),
            'dashboard_url' => admin_url( 'admin.php?page=infy-ai-image-seo-optimizer' ),
            'settings_url'  => admin_url( 'admin.php?page=ai-image-seo-optimizer-settings' ),
            'strings'       => [
                'validating'       => __( 'Validating...', 'infy-ai-image-seo-optimizer' ),
                'valid'            => __( 'Valid', 'infy-ai-image-seo-optimizer' ),
                'invalid'          => __( 'Invalid', 'infy-ai-image-seo-optimizer' ),
                'unverified'       => __( 'Saved — not verified', 'infy-ai-image-seo-optimizer' ),
                'error'            => __( 'Error', 'infy-ai-image-seo-optimizer' ),
                'saving'           => __( 'Saving...', 'infy-ai-image-seo-optimizer' ),
                'skip_title'       => __( 'Skip Setup?', 'infy-ai-image-seo-optimizer' ),
                'skip_confirm'     => __( 'Are you sure you want to skip the setup wizard? You can configure these settings later from the plugin settings page.', 'infy-ai-image-seo-optimizer' ),
                'skip_button'      => __( 'Skip Setup', 'infy-ai-image-seo-optimizer' ),
                'cancel'           => __( 'Cancel', 'infy-ai-image-seo-optimizer' ),
                'api_key_required' => __( 'Please enter at least one API key to continue.', 'infy-ai-image-seo-optimizer' ),
                /* translators: %s: the replies of the providers whose key could not be checked, each as "Provider: reply", e.g. "OpenAI: The request timed out." */
                'unverified_continue' => __( 'Saved, but not checked: the key could not be checked right now. %s Select Continue again to go on. You can test the key later on the API Keys page.', 'infy-ai-image-seo-optimizer' ),
                /* translators: 1: AI provider name, e.g. OpenAI. 2: the provider's reply, e.g. "Incorrect API key provided." */
                'provider_reply'      => __( '%1$s: %2$s', 'infy-ai-image-seo-optimizer' ),
                /* translators: 1: current step number, 2: total number of steps, 3: step name. Announced to screen readers on step change. */
                'step_announcement' => __( 'Step %1$s of %2$s: %3$s', 'infy-ai-image-seo-optimizer' ),
            ],
        ] );
    }

    /**
     * Arm the wizard's style isolation.
     *
     * The fullscreen template calls the front-end wp_head()/wp_footer(), which
     * fires the theme's (and other plugins') front-end enqueues into the
     * wizard document — theme resets and link styles then override wizard
     * styling unpredictably (observed: svg{display:block} breaking icon
     * centering, a:hover repainting CTA labels). An allowlist dequeue at the
     * latest possible priority keeps the document clean regardless of which
     * theme is active, while wp_head()'s non-asset output (title, charset)
     * stays intact.
     *
     * Kept OUTSIDE enqueue_wizard_assets_direct() on purpose: build.py
     * string-transforms that method's enqueue block, which must stay
     * byte-identical.
     *
     * @since 1.0.6
     *
     * @return void
     */
    private function isolate_wizard_assets() {
        add_action( 'wp_enqueue_scripts', [ $this, 'dequeue_foreign_assets' ], PHP_INT_MAX );

        // Customizer "Additional CSS" prints inline into wp_head — theme CSS
        // by another door.
        remove_action( 'wp_head', 'wp_custom_css_cb', 101 );
    }

    /**
     * Dequeue every style/script the wizard does not explicitly need.
     *
     * Runs on the wizard page only (hooked from isolate_wizard_assets()).
     * Allowlists cover both asset modes: development (variables + wizard
     * stylesheet) and the production bundle (infyp-admin).
     *
     * @since 1.0.6
     *
     * @return void
     */
    public function dequeue_foreign_assets() {
        $allowed_styles  = [ 'infyp-variables', 'infyp-setup-wizard', 'infyp-admin' ];
        $allowed_scripts = [ 'jquery', 'jquery-core', 'jquery-migrate', 'infyp-setup-wizard' ];

        foreach ( array_diff( wp_styles()->queue, $allowed_styles ) as $handle ) {
            wp_dequeue_style( $handle );
        }

        foreach ( array_diff( wp_scripts()->queue, $allowed_scripts ) as $handle ) {
            wp_dequeue_script( $handle );
        }
    }

    /**
     * Renders the wizard page within the WordPress admin wrapper.
     *
     * Fallback callback for the admin submenu page registration.
     * In normal operation, maybe_render_fullscreen_wizard() intercepts
     * the request and renders the fullscreen version before this is called.
     * This method exists as a safety net if the fullscreen intercept is bypassed
     * (e.g., by another plugin filtering admin_init).
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function render_wizard_page() {
        $steps = $this->get_steps();

        // Get current step
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $current_step = isset( $_GET['step'] ) ? sanitize_key( $_GET['step'] ) : 'welcome';

        if ( ! isset( $steps[ $current_step ] ) ) {
            $current_step = 'welcome';
        }

        // Get step index for progress
        $step_keys = array_keys( $steps );
        $current_index = array_search( $current_step, $step_keys, true );

        // Get existing data
        $wizard_data = get_option( 'infyp_wizard_data', [] );
        $api_key_manager = INFYP_API_Key_Manager::get_instance();

        // Prepare data for template
        $data = [
            'steps'         => $steps,
            'current_step'  => $current_step,
            'current_index' => $current_index,
            'wizard_data'   => $wizard_data,
            'has_openai'    => $api_key_manager->has_api_key( 'openai' ),
            'has_google'    => $api_key_manager->has_api_key( 'google' ),
            'has_anthropic' => $api_key_manager->has_api_key( 'anthropic' ),
            'masked_keys'   => $api_key_manager->get_all_api_keys( true ),
            'wp_ai_ready'   => \IPAIS\Core\AI\INFYP_WP_AI_Support::is_available() && \IPAIS\Core\AI\INFYP_WP_AI_Support::is_configured(),
            'connection_method' => \IPAIS\Core\AI\INFYP_WP_AI_Support::get_connection_method(),
            'ready'         => $this->is_ready_to_optimize(),
            'languages'     => INFYP_Language_Manager::get_available_languages(),
        ];

        // Load the wizard template
        include INFYP_PLUGIN_DIR . 'includes/views/setup-wizard/wizard-page.php';
    }

    /**
     * AJAX handler: Save step data
     *
     * Processes and saves data for each wizard step. Validates input
     * and delegates to step-specific handlers.
     *
     * Security:
     * - Verifies 'infyp_wizard_nonce' nonce
     * - Requires 'manage_options' capability
     * - Sanitizes all input data
     *
     * @since 1.0.0
     *
     * @return void Sends JSON response and terminates.
     */
    public function ajax_save_step() {
        // Verify nonce
        if ( ! check_ajax_referer( 'infyp_wizard_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => __( 'Security check failed.', 'infy-ai-image-seo-optimizer' ) ] );
        }

        // Check permissions
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'infy-ai-image-seo-optimizer' ) ] );
        }

        $step = isset( $_POST['step'] ) ? sanitize_key( $_POST['step'] ) : '';
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized in individual handler methods
        $data = isset( $_POST['data'] ) && is_array( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : [];

        if ( empty( $step ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid step.', 'infy-ai-image-seo-optimizer' ) ] );
        }

        $response = [ 'message' => __( 'Step saved successfully.', 'infy-ai-image-seo-optimizer' ) ];

        // Process data based on step
        switch ( $step ) {
            case 'api-keys':
                $key_result = $this->save_api_keys( $data );

                // A key the provider rejected blocks the step; no key from
                // this submission was stored.
                if ( ! empty( $key_result['invalid'] ) ) {
                    wp_send_json_error( [
                        'code'    => 'invalid_api_key',
                        'message' => $this->rejected_keys_message( $key_result['invalid'] ),
                        'invalid' => $key_result['invalid'],
                    ] );
                }

                // Saved, but the provider could not be reached to check it.
                if ( ! empty( $key_result['unverified'] ) ) {
                    $response['unverified'] = $key_result['unverified'];
                }
                break;

            case 'preferences':
                $this->save_preferences( $data );
                break;
        }

        wp_send_json_success( $response );
    }

    /**
     * Save API keys from wizard
     *
     * Securely stores API keys for configured AI providers.
     * Keys are sanitized before storage.
     *
     * Every submitted key is checked with the provider first (format check,
     * then the live test of INFYP_API_Key_Manager::test_api_key(), which is
     * cached, so a key already tested on blur usually costs no second
     * request). When a provider rejects a key, no key from this submission is
     * stored; the connection method is stored before the check either way. A
     * key that could not be checked (timeout, no network, unexpected status)
     * is stored and reported as unverified.
     *
     * @since 1.0.0
     * @since 1.0.9 Rejected keys are not stored; returns the check results.
     * @access private
     *
     * @param array $data API key data with provider names as keys.
     * @return array{invalid: array<string, string>, unverified: array<string, string>}
     *               Plain-text messages keyed by provider slug.
     */
    private function save_api_keys( $data ) {
        $result = [
            'invalid'    => [],
            'unverified' => [],
        ];

        // Connection method chosen in the wizard. The WordPress AI value is
        // re-verified server-side; anything else keeps/sets the API-keys
        // default so installs with existing keys start immediately.
        if ( isset( $data['connection_method'] ) ) {
            $infyp_method = sanitize_key( $data['connection_method'] );
            if ( 'wp_ai' === $infyp_method && \IPAIS\Core\AI\INFYP_WP_AI_Support::is_available() ) {
                update_option( 'infyp_connection_method', 'wp_ai' );
                return $result;
            }
            update_option( 'infyp_connection_method', 'api_keys' );
        }

        $api_key_manager = INFYP_API_Key_Manager::get_instance();

        /** This filter is documented in INFYP_Settings::sanitize_api_keys() */
        $providers = apply_filters( 'infyp_allowed_api_key_providers', [ 'openai', 'google', 'anthropic' ] );

        $submitted = [];
        foreach ( (array) $providers as $provider ) {
            if ( is_string( $provider ) && ! empty( $data[ $provider ] ) && is_string( $data[ $provider ] ) ) {
                $submitted[ $provider ] = sanitize_text_field( $data[ $provider ] );
            }
        }

        foreach ( $submitted as $provider => $key ) {
            $format = $api_key_manager->validate_api_key( $provider, $key );
            if ( is_wp_error( $format ) ) {
                $result['invalid'][ $provider ] = $this->plain_text( $format->get_error_message() );
                continue;
            }

            $check = $api_key_manager->test_api_key( $provider, $key );
            $state = isset( $check['state'] ) ? $check['state'] : 'unverified';
            if ( 'invalid' === $state ) {
                $result['invalid'][ $provider ] = $this->plain_text( isset( $check['message'] ) ? $check['message'] : '' );
            } elseif ( 'valid' !== $state ) {
                $result['unverified'][ $provider ] = $this->plain_text( isset( $check['message'] ) ? $check['message'] : '' );
            }
        }

        if ( ! empty( $result['invalid'] ) ) {
            return $result;
        }

        foreach ( $submitted as $provider => $key ) {
            $api_key_manager->set_api_key( $provider, $key );
        }

        $this->align_model_with_keys( $providers );

        return $result;
    }

    /**
     * Message for a key step submission with rejected keys.
     *
     * Names every rejected provider with its reply and says that no key from
     * the submission was saved. Plain text; the wizard script inserts it as
     * text.
     *
     * @since 1.0.9
     * @access private
     *
     * @param array<string, string> $invalid Plain-text replies keyed by provider slug.
     * @return string
     */
    private function rejected_keys_message( array $invalid ) {
        $names   = [];
        $replies = [];
        foreach ( $invalid as $provider => $reply ) {
            $name      = $this->provider_label( (string) $provider );
            $names[]   = $name;
            $replies[] = $this->end_sentence(
                '' === $reply ? $name : sprintf(
                    /* translators: 1: AI provider name, e.g. OpenAI. 2: the provider's reply, e.g. "Incorrect API key provided." */
                    __( '%1$s: %2$s', 'infy-ai-image-seo-optimizer' ),
                    $name,
                    $reply
                )
            );
        }

        return sprintf(
            /* translators: 1: AI provider name, or a list of provider names, e.g. "OpenAI, Anthropic Claude". 2: the replies of these providers, each as "Provider: reply". */
            _n(
                'The %1$s API key was rejected, so no key from this step was saved. %2$s Check the key, or select "Continue without a key" and add a key later on the API Keys page.',
                'The API keys for %1$s were rejected, so no key from this step was saved. %2$s Check the keys, or select "Continue without a key" and add a key later on the API Keys page.',
                count( $names ),
                'infy-ai-image-seo-optimizer'
            ),
            implode( ', ', $names ),
            implode( ' ', $replies )
        );
    }

    /**
     * End a text with sentence punctuation before more text follows it.
     *
     * Provider replies often lack a final full stop. Text that already ends
     * with . ! ? 。 ！ ？ or an ellipsis, also inside closing quotes or
     * brackets, is returned unchanged; otherwise a full stop is appended.
     *
     * @since 1.0.9
     * @access private
     *
     * @param string $text Text.
     * @return string
     */
    private function end_sentence( $text ) {
        $core = (string) preg_replace( '/[\s"\'\)\]\}»”’」』）]+$/u', '', $text );
        if ( '' === $core || preg_match( '/[.!?。！？…]$/u', $core ) ) {
            return $text;
        }

        return $text . '.';
    }

    /**
     * Name of a provider as shown on the key step's cards.
     *
     * @since 1.0.9
     * @access private
     *
     * @param string $provider Provider slug.
     * @return string Provider name, or the slug for a provider the wizard does not list.
     */
    private function provider_label( $provider ) {
        switch ( $provider ) {
            case 'google':
                return __( 'Google Gemini', 'infy-ai-image-seo-optimizer' );
            case 'anthropic':
                return __( 'Anthropic Claude', 'infy-ai-image-seo-optimizer' );
            case 'openai':
                return __( 'OpenAI', 'infy-ai-image-seo-optimizer' );
        }

        return $provider;
    }

    /**
     * Turn a message that may carry HTML entities or tags into plain text.
     *
     * Messages from the key check are partly escaped for HTML and partly the
     * provider's own text; the wizard script inserts them as text. A provider
     * reply can be long, so the text is capped at 300 characters (counted as
     * characters, not bytes) and ends with an ellipsis when it was cut.
     *
     * @since 1.0.9
     * @access private
     *
     * @param string $message Message.
     * @return string Plain text.
     */
    private function plain_text( $message ) {
        $text = trim( html_entity_decode( wp_strip_all_tags( (string) $message ), ENT_QUOTES, 'UTF-8' ) );

        $max = 300;
        if ( mb_strlen( $text, 'UTF-8' ) > $max ) {
            $text = rtrim( mb_substr( $text, 0, $max - 1, 'UTF-8' ) ) . "\u{2026}";
        }

        return $text;
    }

    /**
     * Whether images can be optimized with the current settings
     *
     * True when the WordPress AI connection is selected and ready, or when
     * an API key is stored for the provider of the active model. For a model
     * the registry does not know (for example one of a deactivated
     * extension) any stored key counts.
     *
     * @since 1.0.9
     * @access private
     *
     * @return bool
     */
    private function is_ready_to_optimize() {
        if ( \IPAIS\Core\AI\INFYP_WP_AI_Support::is_selected() ) {
            return \IPAIS\Core\AI\INFYP_WP_AI_Support::is_ready();
        }

        $settings = get_option( 'infyp_optimization_settings', [] );
        $active   = ( is_array( $settings ) && ! empty( $settings['ai_model'] ) && is_string( $settings['ai_model'] ) ) ? $settings['ai_model'] : INFYP_DEFAULT_MODEL;
        $config   = \IPAIS\Core\AI\INFYP_Model_Config_Registry::get_instance()->get_config( $active );

        if ( null !== $config && ! empty( $config['provider'] ) ) {
            return $this->has_stored_api_key( (string) $config['provider'] );
        }

        $keys = get_option( 'infyp_api_keys', [] );

        return is_array( $keys ) && [] !== array_filter( $keys );
    }

    /**
     * Point the active model at a provider that has a key
     *
     * When the provider of the active model has no API key, the active model
     * becomes the default model of the first provider that has one (the
     * default model's provider first, then the order of $providers). Without
     * this a first run with only an OpenAI or Anthropic key would keep the
     * default model and fail for lack of its key.
     *
     * The saved model is left alone when the site uses the WordPress AI
     * connection, when the model is not registered (for example a model of
     * a deactivated extension), and when its provider still has a key (a
     * re-run of the wizard).
     *
     * @since 1.0.9
     * @access private
     *
     * @param array $providers Provider slugs that accept API keys.
     * @return void
     */
    private function align_model_with_keys( $providers ) {
        if ( \IPAIS\Core\AI\INFYP_WP_AI_Support::is_selected() ) {
            return;
        }

        $registry = \IPAIS\Core\AI\INFYP_Model_Config_Registry::get_instance();
        $settings = get_option( 'infyp_optimization_settings', [] );
        $settings = is_array( $settings ) ? $settings : [];
        $active   = ( ! empty( $settings['ai_model'] ) && is_string( $settings['ai_model'] ) ) ? $settings['ai_model'] : INFYP_DEFAULT_MODEL;

        $active_config = $registry->get_config( $active );
        if ( null === $active_config || empty( $active_config['provider'] ) ) {
            return;
        }

        if ( $this->has_stored_api_key( (string) $active_config['provider'] ) ) {
            return;
        }

        $default_config = $registry->get_config( INFYP_DEFAULT_MODEL );
        $candidates     = array_unique( array_merge( ! empty( $default_config['provider'] ) ? [ $default_config['provider'] ] : [], (array) $providers ) );

        foreach ( $candidates as $provider ) {
            if ( ! is_string( $provider ) || ! $this->has_stored_api_key( $provider ) ) {
                continue;
            }

            $model = $registry->get_default_model_for_provider( $provider );
            if ( '' === $model ) {
                continue;
            }

            $settings['ai_model'] = $model;
            update_option( 'infyp_optimization_settings', $settings );
            return;
        }
    }

    /**
     * Whether an API key is stored for a provider, without decrypting it.
     *
     * @since 1.0.9
     * @access private
     *
     * @param string $provider Provider slug.
     * @return bool True when an encrypted key entry exists.
     */
    private function has_stored_api_key( $provider ) {
        $keys = get_option( 'infyp_api_keys', [] );

        return is_array( $keys ) && ! empty( $keys[ $provider ] );
    }

    /**
     * Save user preferences from wizard
     *
     * Stores user preferences including website type, language,
     * image count estimates, and handles newsletter subscription.
     *
     * @since 1.0.0
     * @access private
     *
     * @param array $data Preference data from the wizard form.
     * @return void
     */
    private function save_preferences( $data ) {
        $wizard_data = get_option( 'infyp_wizard_data', [] );

        // Save website type
        if ( ! empty( $data['website_type'] ) ) {
            $wizard_data['website_type'] = sanitize_text_field( $data['website_type'] );
        }

        // Save image count estimate
        if ( ! empty( $data['image_count'] ) ) {
            $wizard_data['image_count'] = sanitize_text_field( $data['image_count'] );
        }

        // Subscribe to newsletter if consent given (uses logged-in user's email)
        if ( ! empty( $data['newsletter_consent'] ) && ! empty( $data['user_email'] ) ) {
            $wizard_data['newsletter_consent'] = true;
            $wizard_data['subscribed_email'] = sanitize_email( $data['user_email'] );

            // Subscribe using the current user's WordPress account info
            $this->subscribe_to_newsletter( $data['user_email'], $data );
        }

        update_option( 'infyp_wizard_data', $wizard_data );

        // Save language preference to main settings
        if ( ! empty( $data['language'] ) ) {
            $prompt_settings = get_option( 'infyp_prompt_settings', [] );
            $prompt_settings['language'] = sanitize_text_field( $data['language'] );
            update_option( 'infyp_prompt_settings', $prompt_settings );
        }
    }

    /**
     * Subscribe user to newsletter via API
     *
     * Uses the logged-in WordPress user's email and name for subscription.
     * This provides an extra layer of security since we know the email
     * belongs to a real WordPress user account.
     *
     * Security measures:
     * - Verifies submitted email matches current WordPress user (case-insensitive)
     * - Uses sanitize_email() on all email inputs
     * - Includes site telemetry only when user has explicitly opted in
     *
     * @since 1.0.0
     * @access private
     *
     * @param string $email User email address (from WordPress account).
     * @param array  $data  All wizard preference data including user_name, user_id.
     * @return void
     */
    private function subscribe_to_newsletter( $email, $data = [] ) {
        // Get current user for additional verification
        $current_user = wp_get_current_user();

        // Sanitize email for comparison
        $email = sanitize_email( trim( $email ) );
        $current_user_email = sanitize_email( trim( $current_user->user_email ) );

        // Security check: verify the email matches the current user (case-insensitive)
        // This prevents users from subscribing other people's emails
        if ( strtolower( $current_user_email ) !== strtolower( $email ) ) {
            return;
        }

        // Collect site telemetry data filtered by user preferences
        $telemetry_prefs = [
            'environment' => ! empty( $data['telemetry_environment'] ),
            'theme'       => ! empty( $data['telemetry_theme'] ),
            'content'     => ! empty( $data['telemetry_content'] ),
            'plugins'     => ! empty( $data['telemetry_plugins'] ),
        ];
        $site_data = $this->collect_site_telemetry( $telemetry_prefs );

        // Prepare metadata with all wizard preferences, WordPress user info, and site telemetry
        $metadata = array_merge(
            [
                // Wizard preferences
                'website_type'   => sanitize_text_field( $data['website_type'] ?? '' ),
                'language'       => sanitize_text_field( $data['language'] ?? '' ),
                'image_count'    => sanitize_text_field( $data['image_count'] ?? '' ),

                // User info
                'wp_user_id'     => $current_user->ID,
                'wp_user_role'   => implode( ', ', $current_user->roles ),

                // Timestamp
                'subscribed_at'  => current_time( 'mysql' ),
            ],
            $site_data
        );

        // Use the WordPress user's display name
        $user_name = $current_user->display_name ?: $current_user->user_login;

        $request_data = [
            'email'         => sanitize_email( $email ),
            'name'          => sanitize_text_field( $user_name ),
            'source'        => 'setup-wizard',
            'source_plugin' => 'infy-ai-image-seo-optimizer',
            'consent'       => true,
            'metadata'      => $metadata,
        ];

        // Newsletter API endpoint - InfyPress central newsletter service
        $api_url = 'https://infypress.com/wp-json/ai-products/v1/newsletter/subscribe';

        // Authenticate with the plugin slug — server validates against registered plugins.
        $plugin_slug = defined( 'INFYP_NEWSLETTER_PLUGIN_SLUG' ) ? INFYP_NEWSLETTER_PLUGIN_SLUG : 'infy-ai-image-seo-optimizer';

        $args = [
            'timeout'  => 15,
            'blocking' => false,
            'headers'  => [
                'Content-Type'       => 'application/json',
                'X-Newsletter-Slug'  => $plugin_slug,
            ],
            'body'     => wp_json_encode( $request_data ),
        ];

        // Disable SSL verification for localhost (development environment)
        if ( strpos( $api_url, 'localhost' ) !== false || strpos( $api_url, '127.0.0.1' ) !== false ) {
            $args['sslverify'] = false;
        }

        // Send subscription request (fire and forget - don't block on response)
        wp_remote_post( $api_url, $args );
    }

    /**
     * Collects site telemetry data for newsletter subscription.
     *
     * Gathers non-personal site information to help improve the plugin.
     * Each telemetry category is independently opt-in via the $prefs parameter,
     * which maps to checkboxes in the wizard preferences step.
     *
     * Data categories:
     * - Always sent: site_url, plugin_version, locale, timezone
     * - Environment ($prefs['environment']): WP/PHP/MySQL versions, server, memory, debug
     * - Theme ($prefs['theme']): Active theme name, version, parent theme
     * - Content ($prefs['content']): Post/page/media/product counts
     * - Plugins ($prefs['plugins']): Active plugin names, SEO/builder detection
     *
     * Privacy: No personal user data is collected. Site URL is included
     * to help with debugging and support requests. Plugin list is capped
     * at 30 active / 50 installed to limit payload size.
     *
     * @since 1.0.0
     * @access private
     *
     * @param array $prefs {
     *     Optional. Category opt-in flags. Default all true.
     *
     *     @type bool $environment Whether to include environment data.
     *     @type bool $theme       Whether to include theme data.
     *     @type bool $content     Whether to include content counts.
     *     @type bool $plugins     Whether to include plugin data.
     * }
     * @return array Associative array of telemetry data.
     */
    private function collect_site_telemetry( $prefs = [] ) {
        global $wpdb;

        $prefs = wp_parse_args( $prefs, [
            'environment' => true,
            'theme'       => true,
            'content'     => true,
            'plugins'     => true,
        ] );

        // Always sent (non-optional)
        $data = [
            'site_url'       => home_url(),
            'plugin_version' => defined( 'INFYP_VERSION' ) ? INFYP_VERSION : '',
            'locale'         => get_locale(),
            'timezone'       => wp_timezone_string(),
        ];

        // Environment data
        if ( $prefs['environment'] ) {
            $server_software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '';

            $data['wp_version']      = get_bloginfo( 'version' );
            $data['php_version']     = phpversion();
            $data['mysql_version']   = $wpdb->db_version();
            $data['server_software'] = $server_software;
            $data['is_multisite']    = is_multisite();
            $data['wp_memory_limit'] = WP_MEMORY_LIMIT;
            $data['wp_debug']        = defined( 'WP_DEBUG' ) && WP_DEBUG;
        }

        // Theme data
        if ( $prefs['theme'] ) {
            $theme = wp_get_theme();

            $data['theme_name']    = $theme->get( 'Name' );
            $data['theme_version'] = $theme->get( 'Version' );
            $data['theme_parent']  = $theme->parent() ? $theme->parent()->get( 'Name' ) : '';
        }

        // Content counts
        if ( $prefs['content'] ) {
            $data['media_count'] = (int) wp_count_posts( 'attachment' )->inherit;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Simple count query, caching not beneficial
            $data['image_count'] = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->posts}
                WHERE post_type = 'attachment'
                AND post_mime_type LIKE 'image/%'"
            );

            $data['post_count'] = (int) wp_count_posts( 'post' )->publish;
            $data['page_count'] = (int) wp_count_posts( 'page' )->publish;

            $data['product_count'] = 0;
            if ( post_type_exists( 'product' ) ) {
                $data['product_count'] = (int) wp_count_posts( 'product' )->publish;
            }
        }

        // Plugin detection
        if ( $prefs['plugins'] ) {
            $data['has_woocommerce'] = class_exists( 'WooCommerce' );
            $data['has_yoast']       = defined( 'WPSEO_VERSION' );
            $data['has_rankmath']    = class_exists( 'RankMath' );
            $data['has_elementor']   = defined( 'ELEMENTOR_VERSION' );

            // Active plugins (names only)
            $active_plugins      = get_option( 'active_plugins', [] );
            $active_plugin_names = [];
            foreach ( $active_plugins as $plugin_path ) {
                $plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_path, false, false );
                if ( ! empty( $plugin_data['Name'] ) ) {
                    $active_plugin_names[] = $plugin_data['Name'];
                }
            }
            $data['active_plugins'] = implode( ', ', array_slice( $active_plugin_names, 0, 30 ) );

            // All installed plugins (including inactive)
            if ( ! function_exists( 'get_plugins' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $all_plugins            = get_plugins();
            $installed_plugin_names = [];
            foreach ( $all_plugins as $plugin_path => $plugin_data ) {
                if ( ! empty( $plugin_data['Name'] ) ) {
                    $installed_plugin_names[] = $plugin_data['Name'];
                }
            }
            $data['installed_plugins'] = implode( ', ', array_slice( $installed_plugin_names, 0, 50 ) );
        }

        return $data;
    }

    /**
     * AJAX handler: Complete wizard
     *
     * Marks the wizard as completed and redirects user to the plugin dashboard.
     * Stores the completion timestamp for future reference.
     *
     * Security:
     * - Verifies 'infyp_wizard_nonce' nonce
     * - Requires 'manage_options' capability
     *
     * @since 1.0.0
     *
     * @return void Sends JSON response with redirect URL and terminates.
     */
    public function ajax_complete_wizard() {
        // Verify nonce
        if ( ! check_ajax_referer( 'infyp_wizard_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => __( 'Security check failed.', 'infy-ai-image-seo-optimizer' ) ] );
        }

        // Check permissions
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'infy-ai-image-seo-optimizer' ) ] );
        }

        // Mark wizard as completed
        update_option( 'infyp_wizard_completed', time() );

        // Clean up redirect-related options/transients
        delete_option( 'infyp_wizard_redirect_time' );
        delete_transient( 'infyp_wizard_redirect' );

        // Keys may have been added outside the wizard while it was open, or
        // the key step was skipped: point the model at a keyed provider here
        // too, then report what the final screen may claim.
        /** This filter is documented in INFYP_Settings::sanitize_api_keys() */
        $this->align_model_with_keys( apply_filters( 'infyp_allowed_api_key_providers', [ 'openai', 'google', 'anthropic' ] ) );

        $ready = $this->is_ready_to_optimize();

        wp_send_json_success( [
            'message'      => __( 'Setup complete!', 'infy-ai-image-seo-optimizer' ),
            'redirect_url' => admin_url( 'admin.php?page=infy-ai-image-seo-optimizer' ),
            'ready'        => $ready,
            'html'         => $this->render_complete_step( $ready ),
        ] );
    }

    /**
     * Render the final wizard screen with the current settings.
     *
     * The page renders every panel when it loads; keys saved during the
     * visit are not in that markup, so the final screen is rendered again
     * when the wizard completes.
     *
     * @since 1.0.9
     * @access private
     *
     * @param bool $ready Whether images can be optimized (is_ready_to_optimize()).
     * @return string Escaped HTML of the step content.
     */
    private function render_complete_step( $ready ) {
        $api_key_manager = INFYP_API_Key_Manager::get_instance();

        $data = [
            'wizard_data'       => get_option( 'infyp_wizard_data', [] ),
            'has_openai'        => $api_key_manager->has_api_key( 'openai' ),
            'has_google'        => $api_key_manager->has_api_key( 'google' ),
            'has_anthropic'     => $api_key_manager->has_api_key( 'anthropic' ),
            'connection_method' => \IPAIS\Core\AI\INFYP_WP_AI_Support::get_connection_method(),
            'ready'             => (bool) $ready,
        ];

        ob_start();
        include INFYP_PLUGIN_DIR . 'includes/views/setup-wizard/steps/step-complete.php';

        return (string) ob_get_clean();
    }

    /**
     * AJAX handler: Skip wizard
     *
     * Allows users to skip the setup wizard and configure settings manually.
     * Marks the wizard as 'skipped' rather than completed.
     *
     * Security:
     * - Verifies 'infyp_wizard_nonce' nonce
     * - Requires 'manage_options' capability
     *
     * @since 1.0.0
     *
     * @return void Sends JSON response with redirect URL and terminates.
     */
    public function ajax_skip_wizard() {
        // Verify nonce
        if ( ! check_ajax_referer( 'infyp_wizard_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => __( 'Security check failed.', 'infy-ai-image-seo-optimizer' ) ] );
        }

        // Check permissions
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'infy-ai-image-seo-optimizer' ) ] );
        }

        // Mark wizard as skipped
        update_option( 'infyp_wizard_completed', 'skipped' );

        // Clean up redirect-related options/transients
        delete_option( 'infyp_wizard_redirect_time' );
        delete_transient( 'infyp_wizard_redirect' );

        wp_send_json_success( [
            'message'      => __( 'Wizard skipped.', 'infy-ai-image-seo-optimizer' ),
            'redirect_url' => admin_url( 'admin.php?page=infy-ai-image-seo-optimizer' ),
        ] );
    }

    /**
     * AJAX handler: Validate API key
     *
     * Tests an API key against its respective AI provider to verify it's valid.
     * Uses the API Key Manager to perform the actual validation.
     *
     * Security:
     * - Verifies 'infyp_wizard_nonce' nonce
     * - Requires 'manage_options' capability
     * - Sanitizes provider name and API key
     *
     * @since 1.0.0
     *
     * @return void Sends JSON success/error response and terminates.
     */
    public function ajax_validate_api_key() {
        // Verify nonce
        if ( ! check_ajax_referer( 'infyp_wizard_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => __( 'Security check failed.', 'infy-ai-image-seo-optimizer' ) ] );
        }

        // Check permissions
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'infy-ai-image-seo-optimizer' ) ] );
        }

        $provider = isset( $_POST['provider'] ) ? sanitize_key( $_POST['provider'] ) : '';
        $api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';

        if ( empty( $provider ) ) {
            wp_send_json_error( [ 'message' => __( 'Provider is required.', 'infy-ai-image-seo-optimizer' ) ] );
        }

        // Live verification against the provider (since 1.0.6): the badge
        // reflects a real authenticated request, not format heuristics —
        // providers change key structures, and only they can say whether a
        // key works. Three states: valid / invalid (provider's own message
        // passed through) / unverified (network trouble — neutral, the key
        // may still be fine).
        $api_key_manager = INFYP_API_Key_Manager::get_instance();
        $result = $api_key_manager->test_api_key( $provider, $api_key );

        if ( 'invalid' === $result['state'] ) {
            wp_send_json_error( [ 'state' => 'invalid', 'message' => $result['message'] ] );
        }

        wp_send_json_success( [ 'state' => $result['state'], 'message' => $result['message'] ] );
    }

    /**
     * Determines whether the setup wizard should be displayed.
     *
     * Static utility method used by other classes (e.g., INFYP_Admin_Pages)
     * to conditionally show wizard-related UI elements. Returns true only
     * when the wizard hasn't been completed (or skipped) AND the current
     * user has the 'manage_options' capability.
     *
     * @since 1.0.0
     *
     * @return bool True if wizard should be shown, false otherwise.
     */
    public static function should_show_wizard() {
        return ! get_option( 'infyp_wizard_completed' ) && current_user_can( 'manage_options' );
    }

    /**
     * Displays a dismissible admin notice prompting wizard completion.
     *
     * Shown on all WordPress admin pages (except the wizard itself) when:
     * - Wizard is not completed or skipped
     * - Notice has not been dismissed
     * - Current user has 'manage_options' capability
     *
     * The dismiss action is handled via an inline jQuery script added to
     * admin_footer, which fires the infyp_dismiss_wizard_notice AJAX action.
     *
     * @since 1.0.0
     *
     * @return void Outputs HTML notice directly.
     */
    public function maybe_show_wizard_notice() {
        // Don't show if wizard is completed
        if ( get_option( 'infyp_wizard_completed' ) ) {
            return;
        }

        // Don't show if notice was dismissed
        if ( get_option( 'infyp_wizard_notice_dismissed' ) ) {
            return;
        }

        // Only show to admins
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Don't show on the wizard page itself
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( isset( $_GET['page'] ) && $_GET['page'] === 'ai-image-seo-optimizer-wizard' ) {
            return;
        }

        $wizard_url = admin_url( 'admin.php?page=ai-image-seo-optimizer-wizard' );
        ?>
        <div class="notice notice-info is-dismissible infyp-wizard-notice">
            <p>
                <strong><?php esc_html_e( 'Image SEO Optimizer', 'infy-ai-image-seo-optimizer' ); ?></strong> &mdash;
                <?php esc_html_e( 'Complete the setup wizard to configure your API keys and start optimizing images.', 'infy-ai-image-seo-optimizer' ); ?>
                <a href="<?php echo esc_url( $wizard_url ); ?>">
                    <?php esc_html_e( 'Start Setup', 'infy-ai-image-seo-optimizer' ); ?>
                </a>
            </p>
        </div>
        <?php
        // Add wizard notice dismiss script to footer
        add_action( 'admin_footer', function() {
            $nonce = wp_create_nonce( 'infyp_dismiss_wizard_notice' );
            $script = 'jQuery(document).ready(function($) {'
                . '$(".infyp-wizard-notice").on("click", ".notice-dismiss", function() {'
                . '$.post(ajaxurl, {'
                . 'action: "infyp_dismiss_wizard_notice",'
                . 'nonce: "' . $nonce . '"'
                . '});'
                . '});'
                . '});';
            wp_print_inline_script_tag( $script );
        } );
        ?>
        <?php
    }

    /**
     * AJAX handler: Dismiss wizard notice
     *
     * Permanently dismisses the setup wizard admin notice for this site.
     * Users can still access the wizard directly via the settings page.
     *
     * Security:
     * - Verifies 'infyp_dismiss_wizard_notice' nonce
     * - Requires 'manage_options' capability
     *
     * @since 1.0.0
     *
     * @return void Sends JSON response and terminates.
     */
    public function ajax_dismiss_wizard_notice() {
        // Verify nonce
        if ( ! check_ajax_referer( 'infyp_dismiss_wizard_notice', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => __( 'Security check failed.', 'infy-ai-image-seo-optimizer' ) ] );
        }

        // Check permissions
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'infy-ai-image-seo-optimizer' ) ] );
        }

        update_option( 'infyp_wizard_notice_dismissed', true );
        wp_send_json_success();
    }

    /**
     * Remove IE conditional comments from registered scripts and styles
     *
     * WordPress 6.9 deprecated the 'conditional' data key for scripts/styles
     * as IE is no longer supported. This method removes any such conditionals
     * added by themes or plugins to prevent deprecation warnings on the wizard page.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function remove_ie_conditionals() {
        global $wp_scripts, $wp_styles;

        // Remove conditional data from scripts
        if ( $wp_scripts instanceof \WP_Scripts ) {
            foreach ( $wp_scripts->registered as $handle => $script ) {
                if ( ! empty( $script->extra['conditional'] ) ) {
                    unset( $wp_scripts->registered[ $handle ]->extra['conditional'] );
                }
            }
        }

        // Remove conditional data from styles
        if ( $wp_styles instanceof \WP_Styles ) {
            foreach ( $wp_styles->registered as $handle => $style ) {
                if ( ! empty( $style->extra['conditional'] ) ) {
                    unset( $wp_styles->registered[ $handle ]->extra['conditional'] );
                }
            }
        }
    }
}
