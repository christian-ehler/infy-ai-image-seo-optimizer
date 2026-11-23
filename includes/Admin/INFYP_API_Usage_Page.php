<?php
/**
 * API Usage Analytics Page - Usage Tracking and Statistics
 *
 * This class manages the API Usage admin page which displays comprehensive
 * analytics and statistics about AI API calls including:
 *
 * - Summary cards (total calls, success rate, average tokens, total cost)
 * - Usage by AI provider (OpenAI, Gemini, Anthropic)
 * - Daily usage charts (Chart.js visualization)
 * - Detailed usage log (WP_List_Table)
 * - Filtering and date range selection
 * - Export capabilities
 *
 * The page provides insights into:
 * - API consumption patterns
 * - Cost tracking
 * - Error rates and troubleshooting
 * - Provider performance comparison
 * - Token usage optimization opportunities
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

use IPAIS\Core\INFYP_API_Usage_Tracker;
use IPAIS\Tables\INFYP_API_Usage_List_Table;

/**
 * API Usage Analytics Page Class
 *
 * Renders and manages the API Usage admin page with statistics,
 * charts, and detailed usage logs.
 *
 * Page Components:
 * 1. Summary Cards:
 *    - Total API Calls (last 30 days)
 *    - Success Rate percentage
 *    - Average Tokens per call
 *    - Total Cost (calculated)
 *
 * 2. Usage by Provider:
 *    - Pie chart showing distribution
 *    - Per-provider statistics
 *
 * 3. Daily Usage Chart:
 *    - Line chart (Chart.js)
 *    - 30-day trend visualization
 *
 * 4. Detailed Usage Log:
 *    - WP_List_Table with pagination
 *    - Sortable columns
 *    - Per-page options
 *
 * Dependencies:
 * - Chart.js library for visualizations
 * - INFYP_API_Usage_Tracker for data
 * - INFYP_API_Usage_List_Table for log display
 *
 * @package   IPAIS\Admin
 * @since 1.0.0
 *
 * @see       INFYP_API_Usage_Tracker For usage data retrieval
 * @see       INFYP_API_Usage_List_Table For log table
 */
class INFYP_API_Usage_Page {

    /**
     * Singleton instance.
     *
     * @since 1.0.0
     * @var   self|null
     */
    private static $instance = null;

    /**
     * List table instance for the usage log.
     *
     * @since 1.0.0
     * @var   INFYP_API_Usage_List_Table|null
     */
    private $list_table;

    /**
     * Returns the singleton instance.
     *
     * @since 1.0.0
     * @return self
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor. Private to enforce singleton pattern.
     *
     * @since 1.0.0
     */
    private function __construct() {
        // Intentionally empty — hooks are registered via on_load_page().
    }

    /**
     * Initializes page resources when the API Usage page is loaded.
     *
     * Called from INFYP_Admin_Pages::on_load_api_usage_page() via the
     * load-{$page_hook} action. Creates the list table, registers screen
     * options, and enqueues page-specific assets.
     *
     * @since 1.0.0
     */
    public function on_load_page() {
        $this->list_table = new INFYP_API_Usage_List_Table();

        add_screen_option( 'per_page', [
            'label'   => __( 'API Calls per page', 'infy-ai-image-seo-optimizer' ),
            'default' => 20,
            'option'  => 'api_usage_per_page',
        ] );

        // Enqueue assets here (load-{$hook} fires only on this page,
        // so no strpos check needed — more efficient than admin_enqueue_scripts).
        wp_enqueue_script( 'infyp-chart-js', INFYP_PLUGIN_URL . 'assets/js/vendor/chart.min.js', [], INFYP_VERSION, true );
        wp_enqueue_style( 'infyp-api-usage', INFYP_PLUGIN_URL . 'assets/css/plugin/api-usage.css', [], INFYP_VERSION );
    }
    
    /**
     * Prepares data and renders the API Usage page.
     *
     * Fetches 30-day usage stats, provider breakdown, and daily usage data.
     * Enqueues the chart JS with daily data passed via wp_localize_script,
     * then delegates to the view template for HTML output.
     *
     * @since 1.0.0
     * @see   includes/views/api-usage-page.php View template.
     */
    public function render_page() {
        // Ensure list table is available (safety net if on_load_page didn't fire).
        if ( ! $this->list_table ) {
            $this->list_table = new INFYP_API_Usage_List_Table();
        }

        $tracker     = INFYP_API_Usage_Tracker::get_instance();
        $stats       = $tracker->get_usage_stats( 30 );
        $by_provider = $tracker->get_usage_by_provider( 30 );
        $daily_usage = $tracker->get_daily_usage( 30 );

        // Enqueue the chart script and pass daily usage data via localization.
        wp_enqueue_script(
            'infyp-api-usage-chart',
            INFYP_PLUGIN_URL . 'assets/js/dist/api-usage-chart.js',
            array( 'jquery', 'infyp-chart-js' ),
            INFYP_VERSION,
            true
        );

        /**
         * Filters the provider brand colors used in the daily usage chart.
         *
         * Each provider key maps to an array with 'border' (line color)
         * and 'bg' (fill color) values. Unknown providers default to gray.
         *
         * @since 1.0.0
         * @param array $colors Associative array of provider slug => color config.
         */
        $provider_colors = apply_filters('infyp_usage_chart_provider_colors', [
            'google'    => ['border' => '#4285F4', 'bg' => 'rgba(66, 133, 244, 0.1)'],
            'openai'    => ['border' => '#10A37F', 'bg' => 'rgba(16, 163, 127, 0.1)'],
            'anthropic' => ['border' => '#D97356', 'bg' => 'rgba(217, 115, 86, 0.1)'],
        ]);

        // Provider display names for chart labels and the view template.
        $provider_names = \IPAIS\Tables\INFYP_API_Usage_List_Table::get_provider_display_names();

        wp_localize_script( 'infyp-api-usage-chart', 'infypApiUsage', array(
            'daily_data'      => $daily_usage,
            'provider_colors' => $provider_colors,
            'provider_names'  => $provider_names,
        ) );

        // Make list table and provider names available to the view template.
        $list_table = $this->list_table;

        require INFYP_PLUGIN_DIR . 'includes/views/api-usage-page.php';
    }
}