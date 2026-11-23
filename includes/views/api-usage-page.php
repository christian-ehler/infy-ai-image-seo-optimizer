<?php
/**
 * API Usage Analytics Page Template
 *
 * Displays usage statistics, provider breakdown table,
 * daily usage chart, and detailed API call log.
 *
 * Available variables (prepared by INFYP_API_Usage_Page::render_page()):
 *   $stats        object  Aggregated usage stats for the last 30 days
 *   $by_provider  array   Per-provider/model usage breakdown
 *   $list_table   INFYP_API_Usage_List_Table  Prepared list table instance
 *
 * @package   IPAIS\Admin
 * @since 1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Pre-calculate success rate for summary card.
$infyp_success_rate = $stats->total_calls > 0
	? round( ( $stats->successful_calls / $stats->total_calls ) * 100, 1 )
	: 0;
?>

<div class="wrap infyp-table-page">
    <h1><?php esc_html_e( 'API Usage', 'infy-ai-image-seo-optimizer' ); ?><?php echo wp_kses_post( apply_filters( 'infyp_admin_header_badge', '' ) ); ?></h1>

    <!-- Summary Cards -->
    <div class="infyp-api-usage-summary">
        <div class="infyp-usage-card">
            <h3><?php esc_html_e( 'Total API Calls', 'infy-ai-image-seo-optimizer' ); ?></h3>
            <div class="infyp-usage-number"><?php echo esc_html( number_format( $stats->total_calls ?? 0 ) ); ?></div>
            <div class="infyp-usage-detail"><?php esc_html_e( 'Last 30 days', 'infy-ai-image-seo-optimizer' ); ?></div>
        </div>

        <div class="infyp-usage-card">
            <h3><?php esc_html_e( 'Success Rate', 'infy-ai-image-seo-optimizer' ); ?></h3>
            <div class="infyp-usage-number"><?php echo esc_html( $infyp_success_rate ); ?>%</div>
            <div class="infyp-usage-detail">
                <?php
                printf(
                    /* translators: 1: Number of successful calls, 2: Number of failed calls */
                    esc_html__( '%1$d successful, %2$d failed', 'infy-ai-image-seo-optimizer' ),
                    intval( $stats->successful_calls ?? 0 ),
                    intval( $stats->failed_calls ?? 0 )
                );
                ?>
            </div>
        </div>

        <div class="infyp-usage-card">
            <h3><?php esc_html_e( 'Average Response Time', 'infy-ai-image-seo-optimizer' ); ?></h3>
            <div class="infyp-usage-number">
                <?php echo esc_html( number_format( $stats->avg_response_time ?? 0, 2 ) ); ?>s
            </div>
            <div class="infyp-usage-detail"><?php esc_html_e( 'Per request', 'infy-ai-image-seo-optimizer' ); ?></div>
        </div>

        <div class="infyp-usage-card">
            <h3><?php esc_html_e( 'Estimated Cost', 'infy-ai-image-seo-optimizer' ); ?></h3>
            <div class="infyp-usage-number">
                $<?php echo esc_html( number_format( $stats->total_cost ?? 0, 2 ) ); ?>
            </div>
            <div class="infyp-usage-detail"><?php esc_html_e( 'Last 30 days', 'infy-ai-image-seo-optimizer' ); ?></div>
        </div>
    </div>

    <!-- Usage by Provider -->
    <?php if ( ! empty( $by_provider ) ) : ?>
    <div class="infyp-usage-by-provider">
        <h2><?php esc_html_e( 'Usage by Provider', 'infy-ai-image-seo-optimizer' ); ?></h2>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Provider', 'infy-ai-image-seo-optimizer' ); ?></th>
                    <th><?php esc_html_e( 'Model', 'infy-ai-image-seo-optimizer' ); ?></th>
                    <th><?php esc_html_e( 'Calls', 'infy-ai-image-seo-optimizer' ); ?></th>
                    <th><?php esc_html_e( 'Success Rate', 'infy-ai-image-seo-optimizer' ); ?></th>
                    <th><?php esc_html_e( 'Est. Cost', 'infy-ai-image-seo-optimizer' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $by_provider as $infyp_provider_stat ) :
                    $infyp_provider_success = $infyp_provider_stat->total_calls > 0
                        ? round( ( $infyp_provider_stat->successful_calls / $infyp_provider_stat->total_calls ) * 100, 1 )
                        : 0;
                ?>
                <tr>
                    <td data-colname="<?php esc_attr_e( 'Provider', 'infy-ai-image-seo-optimizer' ); ?>"><?php echo esc_html( $provider_names[ $infyp_provider_stat->provider ] ?? ucfirst( $infyp_provider_stat->provider ) ); ?></td>
                    <td data-colname="<?php esc_attr_e( 'Model', 'infy-ai-image-seo-optimizer' ); ?>"><code><?php echo esc_html( $infyp_provider_stat->model ); ?></code></td>
                    <td data-colname="<?php esc_attr_e( 'Calls', 'infy-ai-image-seo-optimizer' ); ?>"><?php echo esc_html( number_format( $infyp_provider_stat->total_calls ) ); ?></td>
                    <td data-colname="<?php esc_attr_e( 'Success Rate', 'infy-ai-image-seo-optimizer' ); ?>"><?php echo esc_html( $infyp_provider_success ); ?>%</td>
                    <td data-colname="<?php esc_attr_e( 'Est. Cost', 'infy-ai-image-seo-optimizer' ); ?>">$<?php echo esc_html( number_format( $infyp_provider_stat->total_cost, 2 ) ); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- Usage Chart -->
    <div class="infyp-usage-chart-container">
        <h2><?php esc_html_e( 'Daily Usage', 'infy-ai-image-seo-optimizer' ); ?></h2>
        <canvas id="infyp-usage-chart"></canvas>
    </div>

    <!-- Detailed Log -->
    <h2><?php esc_html_e( 'API Call Log', 'infy-ai-image-seo-optimizer' ); ?></h2>
    <form method="get">
        <?php
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filter form for list table display
        ?>
        <input type="hidden" name="page" value="<?php echo esc_attr( isset( $_REQUEST['page'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['page'] ) ) : '' ); ?>" />
        <?php
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        $list_table->prepare_items();
        ?>
        <div class="infyp-table-scroll">
            <?php $list_table->display(); ?>
        </div>
    </form>
</div>
