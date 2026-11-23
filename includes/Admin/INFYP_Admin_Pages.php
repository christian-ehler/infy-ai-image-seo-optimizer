<?php
/**
 * Admin Pages Manager - WordPress Admin Menu and Page Registration
 *
 * This class manages the creation and registration of all WordPress admin pages
 * for the AI Image SEO plugin. It handles:
 *
 * - Main top-level menu page
 * - Submenu pages (Images, Settings, History, API Usage, Debug)
 * - Admin scripts and styles enqueuing
 * - List table initialization
 * - Screen options registration
 * - Help tabs configuration
 *
 * Menu Structure:
 * - AI Image SEO (main)
 *   - Images (list table with per-image optimization)
 *   - Settings (API keys, optimization options, prompts)
 *   - History (changelog table)
 *   - API Usage (usage tracking and statistics)
 *   - Debug (debug information, only visible when WP_DEBUG enabled)
 *
 * Capabilities Required:
 * - Main pages: 'manage_options'
 * - Individual operations may require 'upload_files' for media operations
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

use \IPAIS\Tables\INFYP_Image_List_Table;
use \IPAIS\Tables\INFYP_Changelog_List_Table;
use \IPAIS\Tables\INFYP_Redirect_List_Table;

/**
 * Admin Pages Management Class
 *
 * Central coordinator for all WordPress admin interface pages related to
 * the AI Image SEO plugin. Registers menu items, handles page rendering,
 * enqueues assets, and manages list tables.
 *
 * Page Registration Flow:
 * 1. WordPress calls 'admin_menu' action
 * 2. add_admin_menu() registers all pages
 * 3. WordPress calls 'load-{page_hook}' for current page
 * 4. on_load_*_page() initializes page-specific resources
 * 5. WordPress calls 'admin_enqueue_scripts'
 * 6. enqueue_page_specific_scripts() loads page assets
 * 7. WordPress renders page content
 * 8. render_*_page() outputs HTML
 *
 * Hooks Used:
 * - `admin_menu`: Register menu pages
 * - `admin_enqueue_scripts`: Enqueue scripts and styles
 * - `load-{page_hook}`: Initialize page-specific resources
 * - `set_screen_option`: Handle screen option saves
 *
 * @package   IPAIS\Admin
 * @since 1.0.0
 *
 * @see       add_menu_page() WordPress function for top-level menus
 * @see       add_submenu_page() WordPress function for submenus
 */
class INFYP_Admin_Pages {

    /**
     * Main images list table instance
     *
     * Displays all images with SEO scores and per-image optimization actions.
     *
     * @since 1.0.0
     * @var   INFYP_Image_List_Table|null
     */
    private $image_list_table;

    /**
     * Changelog list table instance
     *
     * Displays history of all AI-generated SEO data changes.
     *
     * @since 1.0.0
     * @var   INFYP_Changelog_List_Table|null
     */
    private $changelog_list_table;

    /**
     * Redirect list table instance
     *
     * Displays 301 redirect mappings for renamed images.
     *
     * @since 1.1.0
     * @var   INFYP_Redirect_List_Table|null
     */
    private $redirect_list_table;

    /**
     * Main plugin page screen ID
     *
     * WordPress-generated screen ID for the main Images page.
     * Format: 'toplevel_page_{menu_slug}'
     *
     * @since 1.0.0
     * @var   string
     */
    private $main_page_screen_id = 'toplevel_page_infy-ai-image-seo-optimizer';

    /**
     * Settings page screen ID
     *
     * WordPress-generated screen ID for the Settings submenu page.
     * Set during menu registration.
     *
     * @since 1.0.0
     * @var   string
     */
    private $settings_page_screen_id = '';

    /**
     * Retrieves the list of supported languages for AI optimization.
     *
     * Delegates to INFYP_Language_Manager which maintains a static list
     * of WordPress-compatible locale codes and display names.
     *
     * @since 1.0.0
     * @return array Associative array of locale codes => display names.
     */
    private function get_supported_languages() {
        return \IPAIS\Core\INFYP_Language_Manager::get_available_languages();
    }

    /**
     * Constructor. Initializes WordPress hooks for admin functionality.
     *
     * @since 1.0.0
     */
    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        // Late priority so the "Upgrade to PRO" CTA is appended after any Pro
        // plugin's License page and after the Debug page.
        add_action( 'admin_menu', array( $this, 'add_pro_upgrade_menu_item' ), 999 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_page_specific_scripts' ) );
    }

    /**
     * Enqueues page-specific scripts and styles for plugin admin pages.
     *
     * Each plugin page gets only the assets it needs. This method is called
     * on every admin page load via 'admin_enqueue_scripts', so we check
     * $hook to avoid loading assets on unrelated pages.
     *
     * Asset loading is split between this method and the on_load_*() methods:
     * - This method: pages that need hook-based detection (strpos matching)
     * - on_load_*(): pages that use the load-{$page_hook} action (exact match)
     *
     * @since 1.0.0
     * @param string $hook The current admin page hook suffix (e.g. 'toplevel_page_infy-ai-image-seo-optimizer').
     */
	public function enqueue_page_specific_scripts($hook) {
		// === Main Dashboard Page ===
		// Loads postbox support (for collapsible metaboxes), dashboard styles,
		// and the dashboard.js script which handles card dismissal and field toggling.
		if ($hook === $this->main_page_screen_id) {
			wp_enqueue_script('postbox');

			wp_enqueue_style(
				'infyp-dashboard',
				INFYP_PLUGIN_URL . 'assets/css/plugin/dashboard.css',
				array(),
				INFYP_VERSION
			);

			wp_enqueue_script(
				'infyp-dashboard',
				INFYP_PLUGIN_URL . 'assets/js/dist/dashboard.js',
				array( 'jquery' ),
				INFYP_VERSION,
				true
			);

			wp_localize_script( 'infyp-dashboard', 'infypDashboard', array(
				'ajax_url'                 => admin_url( 'admin-ajax.php' ),
				'dismiss_nonce'            => wp_create_nonce( 'infyp_dismiss_compression_card' ),
				'dismiss_extension_nonce'  => wp_create_nonce( 'infyp_dismiss_extension_banner' ),
			) );
		}

		// === Settings Page (SEO Scoring Weights & Parameters) ===
		if (strpos($hook, 'ai-image-seo-optimizer-settings') !== false) {
			wp_enqueue_style(
				'infyp-settings',
				INFYP_PLUGIN_URL . 'assets/css/plugin/settings.css',
				array(),
				INFYP_VERSION
			);
		}

		// === Search Console Page ===
		// Loads Chart.js for the performance timeline, the search console script,
		// and passes OAuth connection state + i18n strings via wp_localize_script.
		// OAuth callback data (code/error from Google redirect) is also passed here
		// so search-console.js can auto-detect and process it on page load.
		if (strpos($hook, 'ai-image-seo-optimizer-search-console') !== false) {
			wp_enqueue_style(
				'infyp-search-console',
				INFYP_PLUGIN_URL . 'assets/css/plugin/search-console.css',
				array(),
				INFYP_VERSION
			);

			wp_enqueue_script(
				'infyp-chart-js',
				INFYP_PLUGIN_URL . 'assets/js/vendor/chart.min.js',
				array(),
				INFYP_VERSION,
				true
			);

			wp_enqueue_script(
				'infyp-search-console',
				INFYP_PLUGIN_URL . 'assets/js/search-console.js',
				array('jquery', 'infyp-chart-js'),
				INFYP_VERSION,
				true
			);

			$oauth = \IPAIS\Core\INFYP_Google_OAuth_Manager::get_instance();

			// OAuth callback handling: When Google redirects back after authorization,
			// the URL contains either a code (success) or gsc_error (failure).
			// We sanitize and pass these to JS via localization so search-console.js
			// can auto-detect and process them — no inline scripts needed.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading URL params for OAuth callback
			$gsc_callback = isset( $_GET['gsc_callback'] ) ? 1 : 0;
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$gsc_code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$gsc_proxy_code = isset( $_GET['proxy_code'] ) ? sanitize_text_field( wp_unslash( $_GET['proxy_code'] ) ) : '';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$gsc_error = isset( $_GET['gsc_error'] ) ? sanitize_text_field( wp_unslash( $_GET['gsc_error'] ) ) : '';

			$gsc_localize = array(
				'ajax_url'     => admin_url('admin-ajax.php'),
				'nonce'        => wp_create_nonce('infyp_ajax_nonce'),
				'connected'    => $oauth->is_connected(),
				'has_property' => ! empty($oauth->get_property()),
				'is_mock'      => \IPAIS\Dev\INFYP_GSC_Mock_Data::is_mock_connection(),
				'i18n'         => array(
					'loading'            => esc_html__('Loading...', 'infy-ai-image-seo-optimizer'),
					'sync_running'       => esc_html__('Syncing...', 'infy-ai-image-seo-optimizer'),
					'sync_done'          => esc_html__('Sync completed!', 'infy-ai-image-seo-optimizer'),
					'error'              => esc_html__('An error occurred.', 'infy-ai-image-seo-optimizer'),
					'no_data'            => esc_html__('No data available for this period.', 'infy-ai-image-seo-optimizer'),
					'select'             => esc_html__('Select', 'infy-ai-image-seo-optimizer'),
					'connecting'         => esc_html__('Connecting...', 'infy-ai-image-seo-optimizer'),
					'disconnecting'      => esc_html__('Disconnecting...', 'infy-ai-image-seo-optimizer'),
					'connected'          => esc_html__('Connected to Google Search Console', 'infy-ai-image-seo-optimizer'),
					'select_property'    => esc_html__('Select a property to use with this site:', 'infy-ai-image-seo-optimizer'),
					'disconnect'         => esc_html__('Disconnect', 'infy-ai-image-seo-optimizer'),
					'connect'            => esc_html__('Connect', 'infy-ai-image-seo-optimizer'),
					'selecting'          => esc_html__('Selecting...', 'infy-ai-image-seo-optimizer'),
					'confirm_disconnect' => esc_html__('Are you sure you want to disconnect from Google Search Console?', 'infy-ai-image-seo-optimizer'),
					'last_synced'        => esc_html__('Last synced:', 'infy-ai-image-seo-optimizer'),
					'no_properties'      => esc_html__('No properties found.', 'infy-ai-image-seo-optimizer'),
					'no_winners'         => esc_html__('No winning queries in this period.', 'infy-ai-image-seo-optimizer'),
					'no_losers'          => esc_html__('No losing queries in this period.', 'infy-ai-image-seo-optimizer'),
					'impressions'        => esc_html__('Impressions', 'infy-ai-image-seo-optimizer'),
					'clicks'             => esc_html__('Clicks', 'infy-ai-image-seo-optimizer'),
					'avg_position'       => esc_html__('Avg. Position', 'infy-ai-image-seo-optimizer'),
					'positions'          => esc_html__('positions', 'infy-ai-image-seo-optimizer'),
					'pages_tracked'      => esc_html__('Pages Tracked', 'infy-ai-image-seo-optimizer'),
					'optimized_pages'    => esc_html__('optimized pages', 'infy-ai-image-seo-optimizer'),
					'sitemap_url'        => esc_html__('Sitemap URL', 'infy-ai-image-seo-optimizer'),
					'images_submitted'   => esc_html__('Images Submitted', 'infy-ai-image-seo-optimizer'),
					'last_downloaded'    => esc_html__('Last Downloaded', 'infy-ai-image-seo-optimizer'),
					'errors_warnings'    => esc_html__('Errors / Warnings', 'infy-ai-image-seo-optimizer'),
					'search_combined'    => esc_html__('Search queries & pages...', 'infy-ai-image-seo-optimizer'),
					'search_queries'     => esc_html__('Search queries...', 'infy-ai-image-seo-optimizer'),
					'search_pages'       => esc_html__('Search pages...', 'infy-ai-image-seo-optimizer'),
					'pages_for_query'    => esc_html__('Pages ranking for:', 'infy-ai-image-seo-optimizer'),
					'queries_for_page'   => esc_html__('Queries ranking for:', 'infy-ai-image-seo-optimizer'),
					'back'               => esc_html__('Back', 'infy-ai-image-seo-optimizer'),
					'page'               => esc_html__('Page', 'infy-ai-image-seo-optimizer'),
					'query'              => esc_html__('Query', 'infy-ai-image-seo-optimizer'),
					'no_results'         => esc_html__('No results found.', 'infy-ai-image-seo-optimizer'),
					'performance_over_time' => esc_html__('Performance Over Time', 'infy-ai-image-seo-optimizer'),
					'previous_period'      => esc_html__('Previous period', 'infy-ai-image-seo-optimizer'),
					/* translators: %1$s: number of rows shown, %2$s: total number of rows */
					'showing_count'      => esc_html__('Showing %1$s of %2$s', 'infy-ai-image-seo-optimizer'),
					/* translators: %1$s: start row number, %2$s: end row number, %3$s: total number of rows */
					'showing_range'      => esc_html__('Showing %1$s–%2$s of %3$s', 'infy-ai-image-seo-optimizer'),
					'prev'               => esc_html__('Prev', 'infy-ai-image-seo-optimizer'),
					'next'               => esc_html__('Next', 'infy-ai-image-seo-optimizer'),
					'connect_cancelled'  => esc_html__('You cancelled the Google authorization.', 'infy-ai-image-seo-optimizer'),
					'connect_failed'     => esc_html__('Failed to connect to Google Search Console. Please try again.', 'infy-ai-image-seo-optimizer'),
				),
			);

			// Pass OAuth callback data via localization for auto-detection in JS.
			if ( $gsc_callback && ( ! empty( $gsc_code ) || ! empty( $gsc_proxy_code ) ) ) {
				$gsc_localize['oauth_code']       = $gsc_code;
				$gsc_localize['oauth_proxy_code'] = $gsc_proxy_code;
			}

			if ( ! empty( $gsc_error ) ) {
				$gsc_localize['oauth_error'] = $gsc_error;
			}

			wp_localize_script( 'infyp-search-console', 'infypGsc', $gsc_localize );
		}

		// === Redirects Page ===
		// Loads api-usage.css for the shared summary card grid, plus redirects.css
		// for status badges, URL display, and page-builder result indicators.
		if (strpos($hook, 'ai-image-seo-optimizer-redirects') !== false) {
			wp_enqueue_style(
				'infyp-api-usage',
				INFYP_PLUGIN_URL . 'assets/css/plugin/api-usage.css',
				array(),
				INFYP_VERSION
			);
			wp_enqueue_style(
				'infyp-redirects',
				INFYP_PLUGIN_URL . 'assets/css/plugin/redirects.css',
				array( 'infyp-api-usage' ),
				INFYP_VERSION
			);
		}

		// === Account Settings Page ===
		// Handles notification thresholds, data retention, export format.
		// Auto-saves select/input changes with a 2-second debounce (in account-settings.js).
		if (strpos($hook, 'ai-image-seo-optimizer-account-settings') !== false) {
			wp_enqueue_style(
				'infyp-account-settings',
				INFYP_PLUGIN_URL . 'assets/css/plugin/account-settings.css',
				array(),
				INFYP_VERSION
			);
			
			wp_enqueue_script(
				'infyp-account-settings',
				INFYP_PLUGIN_URL . 'assets/js/account-settings.js',
				array('jquery'),
				INFYP_VERSION,
				true
			);
			
			// Localize script for AJAX
			wp_localize_script('infyp-account-settings', 'infyp_account', array(
				'ajax_url' => admin_url('admin-ajax.php'),
				'nonce' => wp_create_nonce('infyp_account_settings_nonce'),
				'strings' => array(
					'saving' => esc_html__('Saving...', 'infy-ai-image-seo-optimizer'),
					'saved' => esc_html__('Settings saved!', 'infy-ai-image-seo-optimizer'),
					'error' => esc_html__('Error saving settings', 'infy-ai-image-seo-optimizer'),
					'exporting' => esc_html__('Exporting...', 'infy-ai-image-seo-optimizer'),
					'export_success' => esc_html__('Data exported successfully!', 'infy-ai-image-seo-optimizer'),
					'export_error' => esc_html__('Error exporting data', 'infy-ai-image-seo-optimizer'),
					'resetting' => esc_html__('Resetting...', 'infy-ai-image-seo-optimizer'),
					'reset_confirm' => esc_html__('Are you sure you want to reset all usage statistics? This action cannot be undone.', 'infy-ai-image-seo-optimizer'),
					'auto_saving' => esc_html__('Auto-saving...', 'infy-ai-image-seo-optimizer'),
				),
				'help' => array(
					'notification_threshold' => esc_html__('Set how many images need to be processed before you receive email notifications', 'infy-ai-image-seo-optimizer'),
					'data_retention' => esc_html__('Choose how long to keep optimization logs and usage data for analytics', 'infy-ai-image-seo-optimizer'),
				)
			));
		}
	}

    /**
     * Registers the main menu and all submenu pages for the plugin.
     *
     * Menu structure:
     *   AI Image SEO (Dashboard) — main page with image list table + stats
     *   ├── API Keys             — provider API key management
     *   ├── API Usage            — usage tracking and cost statistics
     *   ├── Settings             — SEO scoring weights and parameters
     *   ├── Search Console       — Google Search Console integration
     *   ├── Changelog            — history of AI-generated changes
     *   └── Debugging            — debug info (only when WP_DEBUG is enabled)
     *
     * Each page registers a load-{$hook} action for initializing page-specific
     * resources (list tables, screen options, assets) before rendering.
     *
     * @since 1.0.0
     */
    public function add_admin_menu() {
        // Allow extensions to change the required capability for all plugin pages.
        $capability = apply_filters('infyp_admin_menu_capability', 'manage_options');
        if ( empty( $capability ) || ! is_string( $capability ) ) {
            $capability = 'manage_options';
        }

        // SVG icon for admin menu — WordPress handles color via CSS on the .wp-menu-image element.
        // phpcs:ignore Generic.Files.LineLength.TooLong
        $menu_icon = 'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 -960 960 960"><path fill="white" d="M206.78-100.78q-44.3 0-75.15-30.85-30.85-30.85-30.85-75.15v-546.44q0-44.3 30.85-75.15 30.85-30.85 75.15-30.85h148.74q26.78 0 40.61 23.22 13.83 23.22 2.44 49.43-7.57 21-11.07 42.79Q384-722 384-700q0 109 75.5 184.5T642-440q21 0 40.5-3t38.5-9l107.3 107.3q14.96 14.96 22.94 34.27 7.98 19.3 7.98 40.39v63.26q0 44.3-30.85 75.15-30.85 30.85-75.15 30.85H206.78ZM834-452l-96-96q-21 14-45 21t-51 7q-74 0-126-52.5T464-700q0-75 52.5-127.5T644-880q75 0 127.5 52.5T824-700q0 27-8 52t-20 46l94 94q11 11 11 28t-11 28q-11 11-28 11t-28-11ZM644-600q42 0 71-29t29-71q0-42-29-71t-71-29q-42 0-71 29t-29 71q0 42 29 71t71 29ZM450-338.09l-57.52-76.69q-11.83-16.52-32.2-16.24-20.37.28-32.19 16.8l-66.92 92.05q-9.69 13.39-2.56 27.78Q265.74-280 282.26-280h395.48q16.52 0 23.65-14.39 7.13-14.39-2.56-27.78L610.57-442.3q-17.79-2-35.22-6.42-17.44-4.41-33.52-11.8L450-338.09Z"/></svg>' );

        // Main menu
        $main_hook = add_menu_page(
            esc_html__('Image SEO Optimizer', 'infy-ai-image-seo-optimizer'), // Page title
            esc_html__('Image SEO', 'infy-ai-image-seo-optimizer'), // Menu title (sidebar)
            $capability,
            'infy-ai-image-seo-optimizer',
            array($this, 'create_main_page'),
            $menu_icon,
            null
        );

        // Add explicit first submenu item with "Dashboard" as menu label
        add_submenu_page(
            'infy-ai-image-seo-optimizer',
            esc_html__('Image SEO Optimizer', 'infy-ai-image-seo-optimizer'), // Page title
            esc_html__('Dashboard', 'infy-ai-image-seo-optimizer'), // Menu title shows "Dashboard"
            $capability,
            'infy-ai-image-seo-optimizer',
            array($this, 'create_main_page')
        );
        
        // API Keys
        add_submenu_page(
            'infy-ai-image-seo-optimizer',
            esc_html__('API Keys', 'infy-ai-image-seo-optimizer'),
            esc_html__('API Keys', 'infy-ai-image-seo-optimizer'),
            $capability,
            'ai-image-seo-optimizer-api-keys', 
            array($this, 'create_api_keys_page')
        );
        
        // API Usage - Added directly after API Keys
        $api_usage_hook = add_submenu_page(
            'infy-ai-image-seo-optimizer',
            esc_html__('API Usage', 'infy-ai-image-seo-optimizer'),
            esc_html__('API Usage', 'infy-ai-image-seo-optimizer'),
            $capability,
            'ai-image-seo-optimizer-api-usage',
            array($this, 'create_api_usage_page')
        );

        // REST API - directly after API Usage
        $rest_api_hook = add_submenu_page(
            'infy-ai-image-seo-optimizer',
            esc_html__('REST API', 'infy-ai-image-seo-optimizer'),
            esc_html__('REST API', 'infy-ai-image-seo-optimizer'),
            $capability,
            INFYP_REST_API_Page::PAGE_SLUG,
            array($this, 'create_rest_api_page')
        );

        // Redirects - directly after API Usage
        $redirects_hook = add_submenu_page(
            'infy-ai-image-seo-optimizer',
            esc_html__('Redirects', 'infy-ai-image-seo-optimizer'),
            esc_html__('Redirects', 'infy-ai-image-seo-optimizer'),
            $capability,
            'ai-image-seo-optimizer-redirects',
            array($this, 'create_redirects_page')
        );

        // Settings (formerly SEO Score Settings)
        $settings_hook = add_submenu_page(
            'infy-ai-image-seo-optimizer',
            esc_html__('Settings', 'infy-ai-image-seo-optimizer'),
            esc_html__('Settings', 'infy-ai-image-seo-optimizer'),
            $capability,
            'ai-image-seo-optimizer-settings',
            array($this, 'create_seo_score_page')
        );

        // Search Console
        $search_console_hook = add_submenu_page(
            'infy-ai-image-seo-optimizer',
            esc_html__('Search Console', 'infy-ai-image-seo-optimizer'),
            esc_html__('Search Console', 'infy-ai-image-seo-optimizer'),
            $capability,
            'ai-image-seo-optimizer-search-console',
            array($this, 'create_search_console_page')
        );

        // Changelog
        $changelog_hook = add_submenu_page(
            'infy-ai-image-seo-optimizer',
            esc_html__('Changelog', 'infy-ai-image-seo-optimizer'),
            esc_html__('Changelog', 'infy-ai-image-seo-optimizer'),
            $capability,
            'ai-image-seo-optimizer-changelog',
            array($this, 'create_changelog_page')
        );

        // Debug Info (only when WP_DEBUG is enabled)
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $debug_hook = add_submenu_page(
                'infy-ai-image-seo-optimizer',
                esc_html__('Debugging', 'infy-ai-image-seo-optimizer'),
                esc_html__('Debugging', 'infy-ai-image-seo-optimizer'),
                $capability,
                'ai-image-seo-optimizer-debug',
                array($this, 'create_debug_page')
            );
        }

        // Register load-{$hook} callbacks for each page.
        // These fire early (before admin_enqueue_scripts) and are used to
        // initialize list tables, register screen options, and enqueue assets.
        add_action("load-{$main_hook}", array($this, 'on_load_main_page'));
        add_action("load-{$api_usage_hook}", array($this, 'on_load_api_usage_page'));
        add_action("load-{$rest_api_hook}", array($this, 'on_load_rest_api_page'));
        add_action("load-{$settings_hook}", array($this, 'on_load_seo_score_page'));
        add_action("load-{$changelog_hook}", array($this, 'on_load_changelog_page'));
        add_action("load-{$search_console_hook}", array($this, 'on_load_search_console_page'));
        add_action("load-{$redirects_hook}", array($this, 'on_load_redirects_page'));

        if (isset($debug_hook)) {
            add_action("load-{$debug_hook}", array($this, 'on_load_debug_page'));
        }
    }

    /**
     * Appends the external "Upgrade to PRO" link to the very end of the plugin's
     * submenu. Hooked to 'admin_menu' at priority 999 so it runs after all other
     * plugins (including any Pro extension that registers a License page) and
     * after the Debug page, guaranteeing the CTA is always the last entry.
     *
     * add_submenu_page() can't take an external URL, so we inject into the
     * $submenu global. WordPress renders a submenu slug containing '://' as a
     * raw href.
     *
     * When WP_DEBUG is on, the entry is shown unconditionally so developers can
     * still see the CTA even while the Pro extension is active (which normally
     * returns false from 'infyp_show_extension_notices').
     *
     * @since 1.1.0
     */
    public function add_pro_upgrade_menu_item() {

        $capability = apply_filters( 'infyp_admin_menu_capability', 'manage_options' );
        $show       = apply_filters( 'infyp_show_extension_notices', true );

        // In debug mode, always show the CTA so it can be inspected regardless
        // of whether the Pro extension is active.
        if ( defined( 'INFYP_DEBUG_ENABLED' ) && INFYP_DEBUG_ENABLED ) {
            $show = true;
        }

        if ( ! $show ) {
            return;
        }

        global $submenu;

        if ( ! isset( $submenu['infy-ai-image-seo-optimizer'] ) ) {
            return;
        }

        $pro_url = 'https://infypress.com/plugins/image-seo-optimizer/?utm_source=wp&utm_medium=sidebar_menu&utm_campaign=get_pro';

        $submenu['infy-ai-image-seo-optimizer'][] = array(
            '<span class="button button-primary button-small">' . esc_html__( 'Upgrade to PRO', 'infy-ai-image-seo-optimizer' ) . '</span>',
            $capability,
            $pro_url,
        );

        // Open the external link in a new tab.
        add_action( 'admin_footer', array( $this, 'open_pro_menu_in_new_tab' ) );
    }

    /**
     * Forces the "Upgrade to PRO" sidebar link to open in a new tab.
     *
     * @since 1.1.0
     */
    public function open_pro_menu_in_new_tab() {
        ?>
        <script>
        (function () {
            var link = document.querySelector('#adminmenu #toplevel_page_infy-ai-image-seo-optimizer a[href*="infypress.com"]');
            if (link) {
                link.setAttribute('target', '_blank');
                link.setAttribute('rel', 'noopener noreferrer');
            }
        })();
        </script>
        <?php
    }

    /**
     * Initializes resources for the main dashboard/image list page.
     *
     * Sets up the WP_List_Table instance, registers the per-page screen option,
     * and adds collapsible metaboxes for the dashboard stats and AI settings.
     * These metaboxes appear above the image list table on the main plugin page.
     *
     * @since 1.0.0
     */
    public function on_load_main_page() {
        $this->image_list_table = new INFYP_Image_List_Table();
        add_screen_option( 'per_page', ['label' => esc_html__('Images per page', 'infy-ai-image-seo-optimizer'), 'default' => 20, 'option' => 'images_per_page'] );

        // Dashboard stats metabox — shows optimization percentages, score circle,
        // and optional recommendation cards (TinyPNG, API usage).
        // Rendered by: includes/views/metaboxes/image-stats-metabox.php
        add_meta_box(
            'infyp-image-stats-metabox',
            esc_html__('Dashboard', 'infy-ai-image-seo-optimizer'),
            array($this, 'render_image_stats_meta_box'),
            $this->main_page_screen_id,
            'normal',
            'high'   // Renders above AI Settings metabox
        );

        // AI Settings metabox — model selector, language picker, prompt add-on.
        // Rendered by: includes/views/metaboxes/prompt-settings-metabox.php
        add_meta_box(
            'infyp-prompt-settings-metabox',
            esc_html__('AI Settings', 'infy-ai-image-seo-optimizer'),
            array($this, 'render_prompt_settings_meta_box'),
            $this->main_page_screen_id,
            'normal',
            'core'
        );
    }

    /**
     * Initializes the API usage page (screen options, list table setup).
     *
     * Delegates to INFYP_API_Usage_Page which manages its own WP_List_Table.
     *
     * @since 1.0.0
     */
    public function on_load_api_usage_page() {
        INFYP_API_Usage_Page::get_instance()->on_load_page();
    }

    /**
     * Initializes REST API page resources on load-{$page_hook}.
     *
     * @since 1.0.6
     *
     * @return void
     */
    public function on_load_rest_api_page() {
        INFYP_REST_API_Page::get_instance()->on_load_page();
    }

    /**
     * Enqueues assets for the Settings page (SEO scoring weights and parameters).
     *
     * Loads two CSS files (main settings + references/best-practices panel)
     * and the seo-score-page.js script with AJAX config and i18n strings.
     *
     * Note: The 'strings' and 'i18n' sub-arrays overlap intentionally —
     * 'strings' is used by the weights tab, 'i18n' by the scoring parameters tab.
     *
     * @since 1.0.0
     */
    public function on_load_seo_score_page() {
        // Enqueue SEO score page specific assets (uses framework components)
        wp_enqueue_style(
            'infyp-seo-score-page',
            INFYP_PLUGIN_URL . 'assets/css/plugin/seo-score-page.css',
            array(),
            INFYP_VERSION
        );
        
        // Enqueue references and best practices styles
        wp_enqueue_style(
            'infyp-seo-score-references',
            INFYP_PLUGIN_URL . 'assets/css/plugin/seo-score-references.css',
            array('infyp-seo-score-page'),
            INFYP_VERSION
        );
        
        wp_enqueue_script(
            'infyp-seo-score-page',
            INFYP_PLUGIN_URL . 'assets/js/seo-score-page.js',
            array('jquery'),
            INFYP_VERSION,
            true
        );
        
        // Localize script for AJAX
        wp_localize_script('infyp-seo-score-page', 'infyp_seo_score', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('infyp_seo_score_nonce'),
            'strings' => array(
                'saving' => esc_html__('Saving...', 'infy-ai-image-seo-optimizer'),
                'saved' => esc_html__('Settings saved!', 'infy-ai-image-seo-optimizer'),
                'error' => esc_html__('Error saving settings', 'infy-ai-image-seo-optimizer'),
                'reset_confirm' => esc_html__('Are you sure you want to reset weights to default values?', 'infy-ai-image-seo-optimizer'),
                'weights_must_equal_100' => esc_html__('Weights must add up to 100%', 'infy-ai-image-seo-optimizer'),
                'weights_must_equal_75' => esc_html__('Weights must add up to 75%', 'infy-ai-image-seo-optimizer')
            ),
            'i18n' => array(
                'saving' => esc_html__('Saving...', 'infy-ai-image-seo-optimizer'),
                'saved' => esc_html__('Settings saved!', 'infy-ai-image-seo-optimizer'),
                'error' => esc_html__('Error saving settings', 'infy-ai-image-seo-optimizer'),
                'resetting' => esc_html__('Resetting...', 'infy-ai-image-seo-optimizer'),
                'scoring_must_equal_100' => esc_html__('All scoring breakdowns must equal 100%', 'infy-ai-image-seo-optimizer'),
                'reset_params_confirm' => esc_html__('Are you sure you want to reset all parameters to default values?', 'infy-ai-image-seo-optimizer'),
                'optimal' => esc_html__('Optimal', 'infy-ai-image-seo-optimizer'),
                'too_short' => esc_html__('Too short', 'infy-ai-image-seo-optimizer'),
                'too_long' => esc_html__('Too long', 'infy-ai-image-seo-optimizer'),
                'calculating' => esc_html__('Calculating...', 'infy-ai-image-seo-optimizer'),
                'calculate_score' => esc_html__('Calculate Score', 'infy-ai-image-seo-optimizer')
            )
        ));
    }

    /**
     * Prepares data and renders the Image Statistics Dashboard meta box.
     *
     * Fetches pre-computed stats from the background calculator (WP-Cron),
     * determines which recommendation cards to show based on user permissions,
     * plugin state, and dismissal history, then delegates to the view template.
     *
     * @since 1.0.0
     * @see   includes/views/metaboxes/image-stats-metabox.php View template.
     * @see   INFYP_Stats_Calculator::get_stats() Stats data source.
     */
    public function render_image_stats_meta_box() {
        // Stats are pre-computed by WP-Cron to avoid heavy DB queries on page load.
        $stats = \IPAIS\Cron\INFYP_Stats_Calculator::get_stats();

        // Extract stats for template
        $total_images          = $stats['total_images'] ?? 0;
        $optimized_images      = $stats['optimized_images'] ?? 0;
        $optimized_percentage  = $stats['optimized_percentage'] ?? 0;
        $filename_percentage   = $stats['filename_percentage'] ?? 0;
        $alt_percentage        = $stats['alt_percentage'] ?? 0;
        $title_percentage      = $stats['title_percentage'] ?? 0;
        $keywords_percentage   = $stats['keywords_percentage'] ?? 0;
        $overall_percentage    = $stats['overall_percentage'] ?? 0;

        // Helper for badge CSS class
        $get_badge_class = function( $percentage ) {
            return $percentage >= 80 ? 'infyp-badge-good' : 'infyp-badge-warning';
        };

        // PRO upsell card visibility logic:
        // Uses filter hook so any extension can hide upsells:
        // add_filter( 'infyp_show_pro_upsells', '__return_false' );
        $hide_all_banners = get_user_meta( get_current_user_id(), 'infyp_hide_all_banners', true );
        $show_extension_card = false;

        /**
         * Filter whether to show extension notice cards and related menu items.
         * Other plugins or extensions can return false to hide these notices.
         *
         * @since 1.1.0
         * @param bool $show Whether to show extension notices. Default true.
         */
        if ( apply_filters( 'infyp_show_extension_notices', true ) ) {
            if ( current_user_can( 'manage_options' ) && $hide_all_banners !== '1' ) {
                $pro_dismissed = get_user_meta( get_current_user_id(), 'infyp_dismissed_extension_banner', true );
                if ( $pro_dismissed ) {
                    $days_since = ( time() - intval( $pro_dismissed ) ) / DAY_IN_SECONDS;
                    $show_extension_card = $days_since >= 30;
                } else {
                    $show_extension_card = true;
                }
            }
        }

        // Compression card visibility logic:
        // Only shown when extension card is NOT visible.
        // Chain: extension card → (dismiss) → compression card → (dismiss) → API usage card
        $show_compression_card = false;
        $show_compression_hidden = false;

        if ( ! $show_extension_card && current_user_can( 'install_plugins' ) && $hide_all_banners !== '1' ) {
            if ( ! function_exists( 'is_plugin_active' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }

            if ( ! is_plugin_active( 'tiny-compress-images/tiny-compress-images.php' ) &&
                 ! file_exists( WP_PLUGIN_DIR . '/tiny-compress-images/tiny-compress-images.php' ) ) {
                $dismissed = get_user_meta( get_current_user_id(), 'infyp_dismissed_compression_card', true );
                if ( $dismissed ) {
                    $last_dismissed = intval( $dismissed );
                    $days_since_dismissed = ( $last_dismissed > 0 ) ? ( time() - $last_dismissed ) / DAY_IN_SECONDS : 0;
                    if ( $days_since_dismissed >= 30 ) {
                        $show_compression_card = true;
                    }
                } else {
                    $show_compression_card = true;
                }
            }
        } elseif ( $show_extension_card ) {
            // Compression card exists but hidden — will fade in when extension card dismissed
            if ( current_user_can( 'install_plugins' ) && $hide_all_banners !== '1' ) {
                if ( ! function_exists( 'is_plugin_active' ) ) {
                    require_once ABSPATH . 'wp-admin/includes/plugin.php';
                }
                if ( ! is_plugin_active( 'tiny-compress-images/tiny-compress-images.php' ) &&
                     ! file_exists( WP_PLUGIN_DIR . '/tiny-compress-images/tiny-compress-images.php' ) ) {
                    $show_compression_hidden = true;
                }
            }
        }

        $show_api_usage_card   = ! $show_compression_card && ! $show_extension_card && current_user_can( 'manage_options' );
        $show_api_usage_hidden = current_user_can( 'manage_options' ) && ( $show_compression_card || $show_extension_card );

        require INFYP_PLUGIN_DIR . 'includes/views/metaboxes/image-stats-metabox.php';
    }

    /**
     * Prepares data and renders the AI Prompt Settings meta box.
     *
     * Loads the current AI model, language, and prompt add-on settings,
     * handles legacy language format migration, fetches the model registry,
     * and delegates rendering to the view template.
     *
     * @since 1.0.0
     * @see   includes/views/metaboxes/prompt-settings-metabox.php View template.
     * @see   INFYP_Model_Config_Registry::get_dropdown_models() Model data source.
     */
    public function render_prompt_settings_meta_box() {
        // Get saved options
        $prompt_settings       = get_option( 'infyp_prompt_settings', [] );
        $optimization_settings = get_option( 'infyp_optimization_settings', [] );

        // Language: default to WP site language.
        $language = $prompt_settings['language'] ?? \IPAIS\Core\INFYP_Language_Manager::get_default_language();
        if ( empty( $language ) || ! \IPAIS\Core\INFYP_Language_Manager::is_valid_language( $language ) ) {
            $language = \IPAIS\Core\INFYP_Language_Manager::get_default_language();
        }

        $prompt_addon        = $prompt_settings['prompt_addon'] ?? '';
        // Canonical id, so a saved alias shows its model selected.
        $ai_model            = \IPAIS\Core\AI\INFYP_Model_Config_Registry::get_canonical_id( (string) ( $optimization_settings['ai_model'] ?? INFYP_DEFAULT_MODEL ) );
        $enable_per_image    = ! empty( $prompt_settings['enable_per_image_prompt'] );
        $supported_languages = $this->get_supported_languages();

        // Get models from the centralized registry
        $registry  = \IPAIS\Core\AI\INFYP_Model_Config_Registry::get_instance();
        $ai_models = $registry->get_dropdown_models();

        /** @since 1.0.0 Filter to allow extensions to add additional AI model categories. */
        $ai_models = apply_filters( 'infyp_ai_models', $ai_models );

        require INFYP_PLUGIN_DIR . 'includes/views/metaboxes/prompt-settings-metabox.php';
    }

    /**
     * Initializes the changelog list table and screen options.
     *
     * @since 1.0.0
     */
    public function on_load_changelog_page() {
        $this->changelog_list_table = new INFYP_Changelog_List_Table();
        add_screen_option( 'per_page', ['label' => esc_html__('Log Entries per page', 'infy-ai-image-seo-optimizer'), 'default' => 20, 'option' => 'changelog_entries_per_page'] );
    }

    /**
     * Initializes the Redirects page (list table, screen options, bulk actions).
     *
     * @since 1.1.0
     */
    public function on_load_redirects_page() {
        $this->redirect_list_table = new INFYP_Redirect_List_Table();
        add_screen_option( 'per_page', [
            'label'   => esc_html__( 'Redirects per page', 'infy-ai-image-seo-optimizer' ),
            'default' => 20,
            'option'  => 'infyp_redirects_per_page',
        ] );

        // Process bulk actions.
        $this->process_redirect_bulk_actions();
    }

    /**
     * Creates the main admin page.
     *
     * Performance optimization: On initial page load, we skip prepare_items()
     * to avoid blocking the page render. Data is loaded via AJAX instead,
     * which allows the page shell to render immediately with a skeleton loader.
     *
     * For sites with 10,000+ images, this reduces initial page load from
     * 10-20 seconds to under 200ms.
     */
    public function create_main_page() {
        // The screen option and meta box are already set up in on_load_main_page().

        // Performance: Skip prepare_items() on initial load to prevent blocking
        // The AJAX table loader will fetch data after page renders
        // Only prepare items if explicitly requested via filter (for backward compatibility)
        $skip_prepare_items = apply_filters( 'infyp_skip_prepare_items', true );

        if ( ! $skip_prepare_items ) {
            $this->image_list_table->prepare_items();
        }

        // Set action to indicate we're displaying the table
        do_action('infyp_before_display_table');

        require_once \INFYP_PLUGIN_DIR . 'includes/views/main-page.php';

        do_action('infyp_after_display_table');
    }

    /**
     * Renders the API Keys management page.
     *
     * @since 1.0.0
     */
    public function create_api_keys_page() {
        require_once \INFYP_PLUGIN_DIR . 'includes/views/api-keys-page.php';
    }

    /**
     * Renders the API Usage tracking page.
     *
     * @since 1.0.0
     */
    public function create_api_usage_page() {
        INFYP_API_Usage_Page::get_instance()->render_page();
    }

    /**
     * Renders the REST API page (activity log, chart, endpoint reference).
     *
     * @since 1.0.6
     *
     * @return void
     */
    public function create_rest_api_page() {
        INFYP_REST_API_Page::get_instance()->render_page();
    }
    
    /**
     * Renders the Settings page (SEO scoring weights and parameters).
     *
     * @since 1.0.0
     */
    public function create_seo_score_page() {
        require_once \INFYP_PLUGIN_DIR . 'includes/views/seo-score-page.php';
    }

    /**
     * Renders the Changelog page (AI optimization history log).
     *
     * @since 1.0.0
     */
    public function create_changelog_page() {
        $this->changelog_list_table->prepare_items();
        require_once \INFYP_PLUGIN_DIR . 'includes/views/changelog-page.php';
    }

    /**
     * Renders the Redirects monitor page.
     *
     * @since 1.1.0
     */
    public function create_redirects_page() {
        $this->redirect_list_table->prepare_items();

        $redirect_list_table = $this->redirect_list_table;
        $summary = \IPAIS\Database\INFYP_Redirect_Table::get_summary_stats();

        require_once \INFYP_PLUGIN_DIR . 'includes/views/redirects-page.php';
    }

    /**
     * Process bulk actions for the Redirects page.
     *
     * @since 1.1.0
     */
    private function process_redirect_bulk_actions() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Lightweight presence check; no data used before the nonce is verified below.
        if ( ! isset( $_REQUEST['redirect_ids'] ) || ! is_array( $_REQUEST['redirect_ids'] ) ) {
            return;
        }

        check_admin_referer( 'bulk-redirects' );

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $ids    = array_map( 'absint', wp_unslash( $_REQUEST['redirect_ids'] ) );
        $action = isset( $_REQUEST['action'] ) && $_REQUEST['action'] !== '-1'
            ? sanitize_key( wp_unslash( $_REQUEST['action'] ) )
            : ( isset( $_REQUEST['action2'] ) ? sanitize_key( wp_unslash( $_REQUEST['action2'] ) ) : '' );

        switch ( $action ) {
            case 'disable':
                \IPAIS\Database\INFYP_Redirect_Table::bulk_update_status( $ids, 'disabled' );
                break;
            case 'enable':
                \IPAIS\Database\INFYP_Redirect_Table::bulk_update_status( $ids, 'active' );
                break;
            case 'delete':
                \IPAIS\Database\INFYP_Redirect_Table::bulk_delete( $ids );
                break;
        }

        // Redirect to remove action params from URL.
        wp_safe_redirect( remove_query_arg( [ 'action', 'action2', 'redirect_ids', '_wpnonce' ] ) );
        exit;
    }

    /**
     * Runs when the Search Console page is loaded.
     *
     * No-op: assets for this page are enqueued in enqueue_page_specific_scripts()
     * via strpos hook matching, not in the load-{$hook} callback. This method
     * exists so the load action is registered and the hook name is available.
     *
     * @since 1.0.0
     */
    public function on_load_search_console_page() {
        // Intentionally empty — see enqueue_page_specific_scripts().
    }

    /**
     * Renders the Search Console page.
     *
     * @since 1.0.0
     */
    public function create_search_console_page() {
        require_once \INFYP_PLUGIN_DIR . 'includes/views/search-console-page.php';
    }

    /**
     * Renders the Debug Info page (API tests, cache tools, system info).
     *
     * Only registered when WP_DEBUG is enabled.
     *
     * @since 1.0.0
     */
    public function create_debug_page() {
        $debug_page = new \IPAIS\Admin\INFYP_Debug_Page();
        $debug_page->render_page();
    }
    
    /**
     * Enqueues assets for the Debug page.
     *
     * Loads the debug page stylesheet and script with AJAX config.
     * The 'strings' sub-array provides button state labels,
     * the 'i18n' sub-array provides confirmation dialogs and result messages.
     *
     * @since 1.0.0
     */
    public function on_load_debug_page() {
        wp_enqueue_style(
            'infyp-debug-page',
            INFYP_PLUGIN_URL . 'assets/css/plugin/debug-page.css',
            array(),
            INFYP_VERSION
        );
        
        wp_enqueue_script(
            'infyp-debug-page',
            INFYP_PLUGIN_URL . 'assets/js/debug-page.js',
            array('jquery'),
            INFYP_VERSION,
            true
        );
        
        // Localize script for AJAX
        wp_localize_script('infyp-debug-page', 'infyp_debug', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('infyp_debug_nonce'),
            'strings' => array(
                'testing' => esc_html__('Testing...', 'infy-ai-image-seo-optimizer'),
                'clearing' => esc_html__('Clearing...', 'infy-ai-image-seo-optimizer'),
                'refreshing' => esc_html__('Refreshing...', 'infy-ai-image-seo-optimizer'),
                'exporting' => esc_html__('Exporting...', 'infy-ai-image-seo-optimizer'),
                'success' => esc_html__('Success!', 'infy-ai-image-seo-optimizer'),
                'error' => esc_html__('Error occurred', 'infy-ai-image-seo-optimizer'),
            ),
            'i18n' => array(
                /* translators: Warning about clearing cache */
                'confirm_clear_cache' => esc_html__('Are you sure you want to clear all cache? This may temporarily slow down the site.', 'infy-ai-image-seo-optimizer'),
                /* translators: Warning about clearing debug logs */
                'confirm_clear_debug_logs' => esc_html__('Are you sure you want to clear all debug logs? This action cannot be undone.', 'infy-ai-image-seo-optimizer'),
                'debug_exported_successfully' => esc_html__('Debug information exported successfully!', 'infy-ai-image-seo-optimizer'),
                /* translators: %1$s: number of successful tests, %2$s: number of failed tests */
                'api_tests_completed' => esc_html__('API Tests completed: %1$s successful, %2$s failed', 'infy-ai-image-seo-optimizer'),
                /* translators: %s: error message */
                'error_testing_apis' => esc_html__('Error testing APIs: %s', 'infy-ai-image-seo-optimizer'),
                /* translators: %s: action name */
                'unknown_suggestion_action' => esc_html__('Unknown suggestion action: %s', 'infy-ai-image-seo-optimizer'),
                'applying' => esc_html__('Applying...', 'infy-ai-image-seo-optimizer'),
                'loading_more_logs' => esc_html__('Loading more logs...', 'infy-ai-image-seo-optimizer'),
                'show_more_logs' => esc_html__('Show More Logs', 'infy-ai-image-seo-optimizer'),
                'no_more_logs' => esc_html__('No more logs to show', 'infy-ai-image-seo-optimizer'),
                'error_loading_logs' => esc_html__('Error loading more logs', 'infy-ai-image-seo-optimizer'),
            )
        ));
    }

    /**
     * Gets the header badge HTML via filter hook.
     * 
     * @since 1.0.0
     * @return string The badge HTML or empty string if no addon provides one.
     */
    private function get_header_badge() {
        /**
         * Filter to allow extensions to add a badge to admin page titles.
         *
         * @since 1.0.0
         * @param string $badge_html The badge HTML.
         */
        return apply_filters('infyp_admin_header_badge', '');
    }
}