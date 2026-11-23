<?php
/**
 * REST API Page Template
 *
 * Displays programmatic usage of the infyp/v1 REST API: summary counters, a
 * 30-day activity chart, the filterable activity log, and the endpoint
 * reference.
 *
 * Available variables (prepared by INFYP_REST_API_Page::render_page()):
 *   $stats       array  Summary counters from INFYP_REST_Log::get_stats()
 *   $list_table  object Prepared INFYP_REST_Log_List_Table instance
 *   $rest_base   string Base URL of the plugin REST namespace
 *
 * @package   IPAIS\Admin
 * @since 1.0.6
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$infyp_cards = [
    [ 'label' => __( 'Calls (24h)', 'infy-ai-image-seo-optimizer' ), 'value' => $stats['last_24h'], 'detail' => __( 'Last 24 hours', 'infy-ai-image-seo-optimizer' ) ],
    [ 'label' => __( 'Calls (7 days)', 'infy-ai-image-seo-optimizer' ), 'value' => $stats['last_7d'], 'detail' => __( 'Last 7 days', 'infy-ai-image-seo-optimizer' ) ],
    [ 'label' => __( 'Failed (7 days)', 'infy-ai-image-seo-optimizer' ), 'value' => $stats['failed_7d'], 'detail' => __( 'Requests that errored', 'infy-ai-image-seo-optimizer' ) ],
    [ 'label' => __( 'Dry runs (7 days)', 'infy-ai-image-seo-optimizer' ), 'value' => $stats['dry_run_7d'], 'detail' => __( 'Generated, not saved', 'infy-ai-image-seo-optimizer' ) ],
    [ 'label' => __( 'Total logged', 'infy-ai-image-seo-optimizer' ), 'value' => $stats['total'], 'detail' => __( 'Retained 90 days', 'infy-ai-image-seo-optimizer' ) ],
];

$infyp_endpoints = [
    [ 'GET', '/images' ],
    [ 'GET', '/images/{id}' ],
    [ 'POST', '/images/{id}/optimize' ],
    [ 'POST', '/images/{id}/field/{field}' ],
    [ 'GET', '/settings' ],
    [ 'PATCH', '/settings' ],
    [ 'GET', '/settings/scoring' ],
    [ 'PATCH', '/settings/scoring' ],
    [ 'PUT', '/settings/scoring' ],
    [ 'DELETE', '/settings/scoring' ],
];
?>

<div class="wrap infyp-table-page">
    <h1><?php esc_html_e( 'REST API', 'infy-ai-image-seo-optimizer' ); ?></h1>

    <div class="infyp-api-usage-summary">
        <?php foreach ( $infyp_cards as $infyp_card ) : ?>
            <div class="infyp-usage-card">
                <h3><?php echo esc_html( $infyp_card['label'] ); ?></h3>
                <div class="infyp-usage-number"><?php echo esc_html( number_format_i18n( $infyp_card['value'] ) ); ?></div>
                <div class="infyp-usage-detail"><?php echo esc_html( $infyp_card['detail'] ); ?></div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="infyp-usage-chart-container infyp-rest-chart-container">
        <h2><?php esc_html_e( 'Activity (last 30 days)', 'infy-ai-image-seo-optimizer' ); ?></h2>
        <canvas id="infyp-rest-chart"></canvas>
    </div>

    <div class="infyp-rest-activity">
        <h2><?php esc_html_e( 'Activity Log', 'infy-ai-image-seo-optimizer' ); ?></h2>
        <form method="get">
            <input type="hidden" name="page" value="<?php echo esc_attr( \IPAIS\Admin\INFYP_REST_API_Page::PAGE_SLUG ); ?>">
            <div class="infyp-table-scroll">
                <?php $list_table->display(); ?>
            </div>
        </form>
    </div>

    <div class="infyp-rest-endpoints">
        <h2><?php esc_html_e( 'Endpoints', 'infy-ai-image-seo-optimizer' ); ?></h2>
        <p><?php esc_html_e( 'Authenticate with an Application Password (Users → Profile → Application Passwords). All optimization requests made through the REST API appear in the activity log above.', 'infy-ai-image-seo-optimizer' ); ?></p>
        <ul class="infyp-rest-endpoint-list">
            <?php foreach ( $infyp_endpoints as $infyp_endpoint ) : ?>
                <li>
                    <span class="infyp-rest-method infyp-rest-method-<?php echo esc_attr( strtolower( $infyp_endpoint[0] ) ); ?>"><?php echo esc_html( $infyp_endpoint[0] ); ?></span>
                    <code><?php echo esc_html( $rest_base . $infyp_endpoint[1] ); ?></code>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="description"><?php esc_html_e( 'Changing the scoring configuration discards every cached SEO score and queues a background recalculation. Scores read as empty until it completes.', 'infy-ai-image-seo-optimizer' ); ?></p>
    </div>

    <?php
    /**
     * Fires after the core sections of the REST API admin page.
     *
     * Extensions append their own sections here.
     *
     * @since 1.0.6
     */
    do_action( 'infyp_rest_admin_page_sections' );
    ?>
</div>
