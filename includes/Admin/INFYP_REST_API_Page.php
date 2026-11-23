<?php
/**
 * REST API Admin Page - Usage tracking for the infyp/v1 REST API
 *
 * Gives administrators visibility into programmatic usage: summary counters,
 * a 30-day activity chart, a filterable activity log, an endpoint reference,
 * and — via the infyp_rest_admin_page_sections action — extension sections.
 *
 * Page setup follows the same flow as the other admin pages: the menu entry is
 * registered by INFYP_Admin_Pages, which wires load-{$page_hook} to
 * on_load_page() for screen options and asset enqueuing.
 *
 * @package   IPAIS\Admin
 * @since 1.0.6
 * @author    InfyPress
 * @license   GPL-2.0-or-later
 */

namespace IPAIS\Admin;

// Exit if accessed directly (placed after namespace per PHP requirement).
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use \IPAIS\Database\INFYP_REST_Log;
use \IPAIS\Tables\INFYP_REST_Log_List_Table;

/**
 * REST API Admin Page Class
 *
 * @package IPAIS\Admin
 * @since 1.0.6
 */
class INFYP_REST_API_Page {

    /**
     * Page slug.
     *
     * @since 1.0.6
     * @var   string
     */
    const PAGE_SLUG = 'ai-image-seo-optimizer-rest-api';

    /**
     * Days covered by the activity chart.
     *
     * @since 1.0.6
     * @var   int
     */
    const CHART_DAYS = 30;

    /**
     * Singleton instance.
     *
     * @since 1.0.6
     * @var   INFYP_REST_API_Page|null
     */
    private static $instance = null;

    /**
     * List table instance, created in on_load_page().
     *
     * @since 1.0.6
     * @var   INFYP_REST_Log_List_Table|null
     */
    private $list_table = null;

    /**
     * Get the singleton instance.
     *
     * @since 1.0.6
     *
     * @return INFYP_REST_API_Page
     */
    public static function get_instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Initialize page resources when the REST API page is loaded.
     *
     * Called from INFYP_Admin_Pages via the load-{$page_hook} action. Creates
     * the list table, registers screen options, and enqueues page assets.
     *
     * @since 1.0.6
     *
     * @return void
     */
    public function on_load_page() {
        INFYP_REST_Log::get_instance()->maybe_install();

        $this->list_table = new INFYP_REST_Log_List_Table();

        add_screen_option( 'per_page', [
            'label'   => __( 'Log entries per page', 'infy-ai-image-seo-optimizer' ),
            'default' => 25,
            'option'  => INFYP_REST_Log_List_Table::PER_PAGE_OPTION,
        ] );

        // Enqueue here (load-{$hook} fires only on this page, so no screen check
        // is needed — same pattern as the API Usage page).
        // The summary cards and chart frame are the same components the API
        // Usage page defines, so that stylesheet is pulled in as a dependency
        // rather than duplicating the rules here.
        wp_enqueue_style( 'infyp-api-usage', INFYP_PLUGIN_URL . 'assets/css/plugin/api-usage.css', [], INFYP_VERSION );
        wp_enqueue_style( 'infyp-rest-api', INFYP_PLUGIN_URL . 'assets/css/plugin/rest-api.css', [ 'infyp-api-usage' ], INFYP_VERSION );
        wp_enqueue_script( 'infyp-chart-js', INFYP_PLUGIN_URL . 'assets/js/vendor/chart.min.js', [], INFYP_VERSION, true );
        wp_enqueue_script( 'infyp-rest-api-chart', INFYP_PLUGIN_URL . 'assets/js/rest-api-chart.js', [ 'infyp-chart-js' ], INFYP_VERSION, true );

        wp_localize_script(
            'infyp-rest-api-chart',
            'infypRestChart',
            [
                'series' => INFYP_REST_Log::get_instance()->get_daily_counts( self::CHART_DAYS ),
                'labels' => [
                    'success' => __( 'Successful', 'infy-ai-image-seo-optimizer' ),
                    'failed'  => __( 'Failed', 'infy-ai-image-seo-optimizer' ),
                    'dryRun'  => __( 'Dry runs', 'infy-ai-image-seo-optimizer' ),
                ],
            ]
        );
    }

    /**
     * Render the page.
     *
     * @since 1.0.6
     *
     * @return void
     */
    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to view this page.', 'infy-ai-image-seo-optimizer' ) );
        }

        $log = INFYP_REST_Log::get_instance();

        // on_load_page() normally builds the table; guard for direct calls.
        if ( null === $this->list_table ) {
            $log->maybe_install();
            $this->list_table = new INFYP_REST_Log_List_Table();
        }

        $this->list_table->prepare_items();

        $stats      = $log->get_stats();
        $list_table = $this->list_table;
        $rest_base  = rest_url( \IPAIS\Rest\INFYP_REST_Controller::REST_NAMESPACE );

        require INFYP_PLUGIN_DIR . 'includes/views/rest-api-page.php';
    }
}
