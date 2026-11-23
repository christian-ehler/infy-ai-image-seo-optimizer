<?php
/**
 * Plugin Name:       Infy SEO - Image SEO Optimizer
 * Plugin URI:        https://infypress.com/plugins/image-seo-optimizer/
 * Description:       Optimize your WordPress images with AI-generated filenames, alt texts and titles. Enhance your SEO and accessibility effortlessly.
 * Version:           1.0.9
 * Author:            Infy
 * Author URI:        https://infypress.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       infy-ai-image-seo-optimizer
 * Domain Path:       /languages
 * Requires at least: 6.1
 * Requires PHP:      8.0
 *
 * AI Image SEO Main Plugin File
 *
 * This is the main entry point for the AI Image SEO WordPress plugin. It handles
 * plugin initialization, dependency management, core component loading, activation/
 * deactivation hooks, and system requirements checking.
 *
 * The plugin uses a modular architecture with namespaced classes organized into
 * Core, Admin, Ajax, and Database components. It initializes in a specific order
 * to ensure security, caching, and error handling are established before other
 * components are loaded.
 *
 * Key Features:
 * - AI-powered image optimization (filename, alt text, title)
 * - Multi-provider AI support (OpenAI, Google Gemini, Anthropic Claude)
 * - Advanced caching with object cache support
 * - Comprehensive security with rate limiting and input validation
 * - Bulk optimization capabilities
 * - Page builder compatibility
 * - Detailed logging and error handling
 *
 * @package    AI_Image_SEO
 * @since 1.0.0
 * @author     InfyPress
 * @license    GPL-2.0+
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin constants
define( 'INFYP_VERSION', '1.0.9' );
define( 'INFYP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'INFYP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'INFYP_PLUGIN_FILE', __FILE__ );

// Performance constants
define( 'INFYP_CACHE_ENABLED', true );
define( 'INFYP_DEBUG_ENABLED', defined('WP_DEBUG') && WP_DEBUG );

// Default AI model — used as fallback when no model is configured.
// Change this single value to update the default across the entire plugin.
define( 'INFYP_DEFAULT_MODEL', 'gemini-3.1-flash-lite-preview' );

// Newsletter authentication — uses the plugin slug as the identifier.
// The server validates the slug against registered plugins.
define( 'INFYP_NEWSLETTER_PLUGIN_SLUG', 'infy-ai-image-seo-optimizer' );

/**
 * Note: Translation loading is handled automatically by WordPress 4.6+
 * when the plugin follows the correct structure:
 * - Text Domain header is set in the plugin file
 * - Translation files are in /languages/ directory
 * - Files follow the naming convention: {textdomain}-{locale}.mo
 * 
 * For compatibility with older WordPress versions or custom setups,
 * we can optionally load translations manually, but it's not required
 * for modern WordPress installations.
 */

/**
 * Require the Composer autoloader.
 */
require_once INFYP_PLUGIN_DIR . 'vendor/autoload.php';

/**
 * Public API — global accessor and helper functions for extensions.
 *
 * Loaded immediately so infyp() and infyp_*() functions are available
 * as soon as this plugin file is parsed. Subsystem properties on infyp()
 * are populated later in infyp_run_ai_image_seo() after singletons init.
 *
 * @since 1.1.0
 */
require_once INFYP_PLUGIN_DIR . 'includes/Core/INFYP_Plugin_API.php';

/**
 * Initialize and run the AI Image SEO plugin
 *
 * This is the main initialization function that bootstraps the entire plugin.
 * Components are initialized in a specific order to ensure dependencies are
 * met and critical systems (security, caching, error handling) are established
 * before other components load.
 *
 * Initialization Order:
 * 1. Security Manager - Establishes security measures and rate limiting
 * 2. Cache Manager - Sets up caching infrastructure
 * 3. Error Handler - Configures error logging and handling
 * 4. Core Components - Script/Modal managers
 * 5. API Managers - Key manager and usage tracker
 * 6. Admin Components - Settings pages and interfaces
 * 7. AJAX Handlers - Request handlers for admin operations
 * 8. Filename Updater - Page builder compatibility
 *
 * The function includes comprehensive error handling to catch initialization
 * failures and display appropriate admin notices.
 *
 * @since 1.0.0
 *
 * @throws \Exception If any critical component fails to initialize.
 *
 * @return void
 *
 * @see INFYP_Security_Manager For security initialization
 * @see INFYP_Cache_Manager For caching system setup
 * @see INFYP_Error_Handler For error handling configuration
 */
function infyp_run_ai_image_seo() {
    try {
        // Initialize security first
        \IPAIS\Core\INFYP_Security_Manager::get_instance();
        
        // Initialize caching system
        \IPAIS\Core\INFYP_Cache_Manager::get_instance();
        
        // Initialize error handler
        \IPAIS\Core\INFYP_Error_Handler::get_instance();
        
        // Core components
        new \IPAIS\Core\INFYP_Script_Manager();
        new \IPAIS\Core\INFYP_Modal_Manager();

        // Initialize API Key Manager
        \IPAIS\Core\INFYP_API_Key_Manager::get_instance();

        // WordPress core AI Client integration (WP 7.0+)
        \IPAIS\Core\AI\INFYP_WP_AI_Support::register_hooks();

        // Initialize API Usage Tracker
        \IPAIS\Core\INFYP_API_Usage_Tracker::get_instance();
        
        // Admin components
        new \IPAIS\Admin\INFYP_Admin_Pages();
        new \IPAIS\Admin\INFYP_Settings();
        new \IPAIS\Admin\INFYP_Admin_Notices_Manager();

        // Setup Wizard - shows until completed or skipped
        \IPAIS\Admin\INFYP_Setup_Wizard::get_instance();
        
        // Initialize debug page (registers AJAX handlers)
        if (defined('WP_DEBUG') && WP_DEBUG) {
            new \IPAIS\Admin\INFYP_Debug_Page();
        }
        
        // AJAX handlers
        new \IPAIS\Ajax\INFYP_Ajax_Handlers();

        // REST API (namespace infyp/v1). Rate-limit mapping is registered
        // immediately (the filter is evaluated per check); routes register
        // on rest_api_init as WordPress requires.
        \IPAIS\Rest\INFYP_REST_Controller::register_rate_limits();
        \IPAIS\Database\INFYP_REST_Log::get_instance()->maybe_install();
        add_action( 'rest_api_init', function () {
            ( new \IPAIS\Rest\INFYP_REST_Images_Controller() )->register_routes();
            ( new \IPAIS\Rest\INFYP_REST_Settings_Controller() )->register_routes();
            ( new \IPAIS\Rest\INFYP_REST_Scoring_Controller() )->register_routes();

            /**
             * Fires after the plugin registers its own REST routes.
             *
             * Extensions register additional routes on the same namespace
             * here. Only the namespace STRING is passed — never a controller
             * instance — so extensions couple to the published contract, not
             * to internals.
             *
             * @since 1.0.6
             *
             * @param string $rest_namespace The plugin REST namespace ('infyp/v1').
             */
            do_action( 'infyp_rest_routes', \IPAIS\Rest\INFYP_REST_Controller::REST_NAMESPACE );
        } );

        // Initialize the filename updater for page builder compatibility
        new \IPAIS\Core\INFYP_Filename_Updater();

        // Initialize 301 redirect manager (capture + serve redirects for renamed images)
        \IPAIS\Core\INFYP_Redirect_Manager::get_instance();

        // Redirect settings UI on the Settings page
        new \IPAIS\Admin\INFYP_Redirect_Settings();

        // Redirect expiry cron (registers hook; scheduling done on activation)
        \IPAIS\Cron\INFYP_Redirect_Expiry::get_instance();

        // Redirect delivery health check (probe + warning UX)
        \IPAIS\Core\INFYP_Redirect_Health::get_instance();

        // Initialize background stats calculator (registers cron hook)
        \IPAIS\Cron\INFYP_Stats_Calculator::get_instance();

        // Search Console: AJAX handlers and background sync cron
        new \IPAIS\Ajax\INFYP_Search_Console_Ajax();
        \IPAIS\Cron\INFYP_Search_Console_Sync::get_instance();

        // GSC Mock Data seeder (dev/testing only — registers AJAX handlers)
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            new \IPAIS\Dev\INFYP_GSC_Mock_Data();
        }
        
        // Warm up cache
        add_action('admin_init', function() {
            if (is_admin() && current_user_can('manage_options')) {
                \IPAIS\Core\INFYP_Cache_Manager::get_instance()->warm_cache();
            }
        });

        // Initialize the global accessor with references to all singletons.
        // This must happen after all singletons above are created.
        infyp()->init();

        /**
         * Fires after the plugin is fully loaded and all components initialized.
         *
         * @since 1.0.0
         */
        do_action('infyp_loaded');

    } catch (\Exception $e) {
        // Show admin notice for critical errors
        add_action('admin_notices', function() use ($e) {
            if (current_user_can('manage_options')) {
                echo '<div class="notice notice-error"><p>';
                echo esc_html__('Image SEO Optimizer encountered an initialization error. Please check your error logs.', 'infy-ai-image-seo-optimizer');
                if (INFYP_DEBUG_ENABLED) {
                    echo ' ' . esc_html($e->getMessage());
                }
                echo '</p></div>';
            }
        });
    }
}

// Initialize plugin after all plugins are loaded
add_action('plugins_loaded', 'infyp_run_ai_image_seo', 10);


/**
 * Get supported image MIME types
 *
 * Returns the list of image MIME types the plugin can optimize.
 * Filterable so themes/extensions can add support for additional types.
 *
 * @since 1.0.0
 *
 * @return array List of supported MIME types.
 */
function infyp_get_supported_mime_types() {
    static $types = null;
    if ( $types === null ) {
        $types = apply_filters( 'infyp_supported_mime_types', [
            'image/jpeg',
            'image/png',
            'image/webp',
            'image/gif',
        ] );
    }
    return $types;
}

/**
 * Get supported MIME types as an SQL IN clause fragment
 *
 * Returns a string like "'image/jpeg', 'image/png', 'image/webp', 'image/gif'"
 * for use in SQL queries. Values are escaped via esc_sql().
 *
 * @since 1.0.0
 *
 * @return string SQL-safe comma-separated quoted MIME types.
 */
function infyp_get_mime_type_sql() {
    static $sql = null;
    if ( $sql === null ) {
        $types = infyp_get_supported_mime_types();
        $escaped = array_map( function( $type ) {
            return "'" . esc_sql( $type ) . "'";
        }, $types );
        $sql = implode( ', ', $escaped );
    }
    return $sql;
}


/**
 * Plugin activation callback
 *
 * Handles all setup tasks required when the plugin is activated. This includes
 * system requirement checks (PHP/WordPress versions), database table creation,
 * initial option setup, and cache warming.
 *
 * The function performs the following operations:
 * 1. Validates PHP version (minimum 8.0)
 * 2. Validates WordPress version (minimum 5.6)
 * 3. Creates required database tables (changelog, API usage)
 * 4. Runs database optimizations
 * 5. Sets default plugin options
 * 6. Schedules maintenance tasks
 * 7. Clears and warms cache
 *
 * If requirements are not met, the plugin deactivation is triggered with
 * an appropriate error message displayed to the user.
 *
 * @since 1.0.0
 *
 * @global wpdb $wpdb WordPress database abstraction object.
 *
 * @return void Dies with error message if requirements not met.
 *
 * @see infyp_set_default_options() For default option setup
 * @see INFYP_Changelog::on_activation() For changelog table creation
 * @see INFYP_API_Usage_Tracker::create_table() For API tracking table
 */
register_activation_hook( __FILE__, 'infyp_on_activation' );

function infyp_on_activation() {
    // Check PHP version
    if (version_compare(PHP_VERSION, '8.0', '<')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            esc_html__('Image SEO Optimizer requires PHP 8.0 or higher.', 'infy-ai-image-seo-optimizer'),
            esc_html__('Plugin Activation Error', 'infy-ai-image-seo-optimizer'),
            array('response' => 200, 'back_link' => true)
        );
    }
    
    // Check WordPress version
    if (version_compare(get_bloginfo('version'), '6.1', '<')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            esc_html__('Image SEO Optimizer requires WordPress 6.1 or higher.', 'infy-ai-image-seo-optimizer'),
            esc_html__('Plugin Activation Error', 'infy-ai-image-seo-optimizer'),
            array('response' => 200, 'back_link' => true)
        );
    }
    
    // Create database tables
    \IPAIS\Database\INFYP_Changelog::on_activation();
    \IPAIS\Core\INFYP_API_Usage_Tracker::create_table();
    
    // Run database optimizations
    require_once INFYP_PLUGIN_DIR . 'includes/Database/INFYP_Database_Optimizer.php';
    \IPAIS\Database\INFYP_Database_Optimizer::run_all_optimizations();
    
    // Clear any existing caches FIRST (before setting transients)
    wp_cache_flush();

    // Clear language cache to ensure fresh language data
    \IPAIS\Core\INFYP_Language_Manager::clear_cache();

    // Set default options
    infyp_set_default_options();

    // Set a transient to show the activation notice
    // Note: Set AFTER cache flush to ensure it persists
    set_transient('infyp_activation_notice', true, 300);

    // Set wizard redirect transient if wizard not yet completed
    // Use longer expiry (5 minutes) to handle slow page loads on managed hosts
    // Also store in option as fallback for hosts where transients don't persist
    if ( ! get_option( 'infyp_wizard_completed' ) ) {
        set_transient( 'infyp_wizard_redirect', true, 300 );
        // Fallback: store timestamp in option for hosts with aggressive cache clearing
        update_option( 'infyp_wizard_redirect_time', time(), false );
    }

    // Schedule maintenance tasks
    if (!wp_next_scheduled('infyp_daily_maintenance')) {
        wp_schedule_event(time(), 'daily', 'infyp_daily_maintenance');
    }

    // Create Search Console data table
    \IPAIS\Database\INFYP_Search_Console_Table::on_activation();

    // Create redirects table
    \IPAIS\Database\INFYP_Redirect_Table::on_activation();

    // Schedule redirect expiry cron (daily)
    \IPAIS\Cron\INFYP_Redirect_Expiry::schedule_cron();

    // Schedule stats calculator cron (hourly) for dashboard performance
    \IPAIS\Cron\INFYP_Stats_Calculator::schedule_cron();

    // Calculate initial stats in background (non-blocking)
    wp_schedule_single_event(time() + 5, 'infyp_calculate_stats');
}

/**
 * Set default plugin options on activation
 *
 * Initializes the plugin with sensible default settings for post types,
 * optimization parameters, and prompt configurations. Only sets defaults
 * if options don't already exist to avoid overwriting user settings.
 *
 * Default Settings:
 * - Post Types: Default enabled for standard WordPress posts
 * - AI Model: Gemini 3 Flash (Google's fastest frontier model)
 * - Batch Size: 10 images per batch operation
 * - Timeout: 60 seconds per API request
 * - Language: English
 * - Custom Prompt: Empty (uses default prompts)
 *
 * @since 1.0.0
 *
 * @return void
 *
 * @see get_option() WordPress function to retrieve option value
 * @see add_option() WordPress function to add new option
 */
function infyp_set_default_options() {
    // Default post type settings
    if (!get_option('infyp_post_type_settings')) {
        add_option('infyp_post_type_settings', ['default' => '1']);
    }
    
    // Default optimization settings
    if (!get_option('infyp_optimization_settings')) {
        add_option('infyp_optimization_settings', [
            'ai_model' => INFYP_DEFAULT_MODEL,
            'batch_size' => 10,
            'timeout' => 60
        ]);
    }
    
    // Default prompt settings
    if (!get_option('infyp_prompt_settings')) {
        add_option('infyp_prompt_settings', [
            'language' => 'English',
            'prompt_addon' => ''
        ]);
    }
}

/**
 * Plugin deactivation callback
 *
 * Handles cleanup tasks when the plugin is deactivated. This includes
 * clearing scheduled events, flushing caches, and cleaning up temporary data.
 * Does NOT delete user data or settings to allow for clean reactivation.
 *
 * Cleanup Operations:
 * - Unschedules all cron events (daily maintenance)
 * - Clears all plugin caches (object cache, transients)
 * - Removes temporary transients and recovery data
 *
 * Note: User settings, API keys, and optimized image data are preserved
 * to maintain state if the plugin is reactivated.
 *
 * @since 1.0.0
 *
 * @return void
 *
 * @see wp_clear_scheduled_hook() For removing cron jobs
 * @see INFYP_Cache_Manager::clear_all_cache() For cache cleanup
 */
register_deactivation_hook(__FILE__, 'infyp_on_deactivation');

function infyp_on_deactivation() {
    // Cleanup scheduled events
    wp_clear_scheduled_hook('infyp_daily_maintenance');
    wp_clear_scheduled_hook('infyp_batch_cache_scores');
    wp_clear_scheduled_hook(\IPAIS\Database\INFYP_Database_Optimizer::CRON_CONTENT_SCAN);

    // Unschedule stats calculator cron
    \IPAIS\Cron\INFYP_Stats_Calculator::unschedule_cron();

    // Unschedule Search Console sync cron
    \IPAIS\Cron\INFYP_Search_Console_Sync::unschedule_cron();

    // Unschedule redirect expiry cron
    \IPAIS\Cron\INFYP_Redirect_Expiry::unschedule_cron();

    // Unschedule redirect health cron
    \IPAIS\Core\INFYP_Redirect_Health::unschedule_cron();

    // Cleanup caches
    if (class_exists('\IPAIS\Core\INFYP_Cache_Manager')) {
        \IPAIS\Core\INFYP_Cache_Manager::get_instance()->clear_all_cache();
    }
}

/**
 * Prevent field feedback HTML from appearing in admin notices
 */
add_action('admin_init', function() {
    // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only check for page identification
    // Only on our plugin pages
    if (!isset($_GET['page']) || strpos(sanitize_text_field(wp_unslash($_GET['page'])), 'infy-ai-image-seo-optimizer') === false) {
        return;
    }
    // phpcs:enable WordPress.Security.NonceVerification.Recommended
});

/**
 * Execute daily maintenance tasks
 *
 * Scheduled cron job that runs daily to perform housekeeping operations.
 * This helps maintain database performance and prevents data bloat.
 *
 * Maintenance Tasks:
 * - Deletes expired transients to free database space
 * - Removes old recovery data (older than 24 hours)
 * - Cleans up old API usage tracking records
 * - Logs completion (debug mode only)
 *
 * This function is hooked to the 'infyp_daily_maintenance' cron event
 * which is scheduled during plugin activation.
 *
 * @since 1.0.0
 *
 * @global wpdb $wpdb WordPress database abstraction object.
 *
 * @return void
 *
 * @see infyp_on_activation() For cron event scheduling
 * @see INFYP_API_Usage_Tracker::cleanup_old_records() For API data cleanup
 */
add_action('infyp_daily_maintenance', 'infyp_run_daily_maintenance');

function infyp_run_daily_maintenance() {
    // Clean up old transients
    global $wpdb;
    
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    // Direct query required for cleanup of expired transients - caching not applicable for DELETE operations.
    // Value and timeout rows go together: a value row left without its
    // timeout row would turn into a transient that never expires.
    $wpdb->query(
        $wpdb->prepare(
            "DELETE a, b FROM {$wpdb->options} a, {$wpdb->options} b
             WHERE a.option_name LIKE %s
             AND a.option_name NOT LIKE %s
             AND b.option_name = CONCAT( '_transient_timeout_', SUBSTRING( a.option_name, 12 ) )
             AND b.option_value < %d",
            $wpdb->esc_like('_transient_infyp_') . '%',
            $wpdb->esc_like('_transient_timeout_') . '%',
            time()
        )
    );

    // Timeout rows whose value row is already gone.
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE %s
             AND option_value < %d",
            $wpdb->esc_like('_transient_timeout_infyp_') . '%',
            time()
        )
    );
    
    // Clean up recovery data - Direct query required for cleanup - caching not applicable for DELETE operations
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} 
             WHERE option_name LIKE %s 
             AND option_value < %s",
            $wpdb->esc_like('_transient_infyp_recovery_') . '%',
            gmdate('Y-m-d H:i:s', strtotime('-24 hours'))
        )
    );
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

    // Markers of image uploads abandoned before they completed.
    \IPAIS\Core\INFYP_Cache_Manager::delete_expired_upload_markers();

    // Clean up API usage records
    $api_tracker = \IPAIS\Core\INFYP_API_Usage_Tracker::get_instance();
    $api_tracker->cleanup_old_records();
    
}

/**
 * Background batch cron: cache SEO scores for images that don't have them
 *
 * Processes up to 200 images per run. If more remain, reschedules itself.
 * Avoids the 500-700 query burst that occurs when ensure_scores_cached()
 * tried to process 100 images synchronously on the first score-sort.
 *
 * @since 1.0.0
 */
/**
 * Background batch cron: index images embedded in post content
 *
 * Walks wp_posts in ID order, extracts every attachment referenced from
 * post_content, and records it in both the usage and post-type lookup tables
 * so the post-type filter sees images placed in the editor — not just featured
 * images and upload-time attachments. Reschedules itself until done.
 *
 * @since 1.0.8
 */
add_action(
    \IPAIS\Database\INFYP_Database_Optimizer::CRON_CONTENT_SCAN,
    array( '\IPAIS\Database\INFYP_Database_Optimizer', 'run_content_scan_batch' )
);

/**
 * Run one-time upgrade routines when the installed version changes
 *
 * Plugin updates do not re-run the activation hook, so schema changes that the
 * filters depend on are applied here instead.
 *
 * @since 1.0.8
 */
// Priority 5: runs before INFYP_Settings registers the sanitize callbacks
// (admin_init, 10), so the model migration's option write keeps all keys.
add_action( 'admin_init', 'infyp_maybe_upgrade', 5 );

function infyp_maybe_upgrade() {
    // Saved model aliases (renamed or retired ids) -> canonical id. Runs on
    // every admin request, independent of the version, so a registry change
    // alone is enough; idempotent once the saved id is canonical.
    \IPAIS\Core\AI\INFYP_Model_Migration::maybe_migrate();

    if ( get_option( 'infyp_installed_version' ) === INFYP_VERSION ) {
        return;
    }

    \IPAIS\Database\INFYP_Database_Optimizer::maybe_invalidate_lookup_schema();

    update_option( 'infyp_installed_version', INFYP_VERSION );
}

add_action( 'infyp_batch_cache_scores', 'infyp_batch_cache_scores_handler' );

function infyp_batch_cache_scores_handler() {
    global $wpdb;

    // Find images without cached SEO scores
    $mime_sql = infyp_get_mime_type_sql();
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Background cron; $mime_sql from esc_sql()
    $uncached_ids = $wpdb->get_col(
        "SELECT p.ID
         FROM {$wpdb->posts} p
         LEFT JOIN {$wpdb->postmeta} pm_score ON (p.ID = pm_score.post_id AND pm_score.meta_key = '_infyp_seo_score')
         WHERE p.post_type = 'attachment'
         AND p.post_mime_type IN ({$mime_sql})
         AND p.post_status = 'inherit'
         AND pm_score.meta_value IS NULL
         LIMIT 200"
    );
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

    if ( empty( $uncached_ids ) ) {
        return;
    }

    foreach ( $uncached_ids as $image_id ) {
        \IPAIS\Core\INFYP_SEO_Score_Calculator::calculate_score( (int) $image_id );
    }

    // If we processed a full batch, there may be more — reschedule
    if ( count( $uncached_ids ) >= 200 ) {
        wp_schedule_single_event( time() + 30, 'infyp_batch_cache_scores' );
    }
}

/**
 * Add custom action links to the plugin row
 *
 * Adds a "Settings" link to the plugin's row in the WordPress admin plugins list.
 * This provides quick access to the plugin's main settings page.
 *
 * @since 1.0.0
 *
 * @param array $links Existing plugin action links.
 *
 * @return array Modified array of action links including the Settings link.
 *
 * @see admin_url() For generating admin URL
 */
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'infyp_add_action_links');

function infyp_add_action_links($links) {
    $optimize_link = '<a href="' . esc_url(admin_url('admin.php?page=infy-ai-image-seo-optimizer')) . '">' .
                     esc_html__('Start Optimizing', 'infy-ai-image-seo-optimizer') . '</a>';

    $settings_link = '<a href="' . esc_url(admin_url('admin.php?page=ai-image-seo-optimizer-settings')) . '">' .
                     esc_html__('Settings', 'infy-ai-image-seo-optimizer') . '</a>';

    $pro_link = '<a href="' . esc_url('https://infypress.com/plugins/image-seo-optimizer/?utm_source=wp&utm_medium=plugin_action_link&utm_campaign=get_pro') . '" target="_blank">' .
                esc_html__('Get PRO', 'infy-ai-image-seo-optimizer') . '</a>';

    array_unshift($links, $optimize_link, $settings_link);

    /** This filter is documented in includes/Admin/INFYP_Admin_Pages.php */
    if ( apply_filters( 'infyp_show_extension_notices', true ) ) {
        $links[] = $pro_link;
    }

    return $links;
}



