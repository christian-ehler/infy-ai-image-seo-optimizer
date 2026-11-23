<?php
/**
 * Search Console page view - Google Search Console Integration Dashboard.
 *
 * Renders one of three connection states:
 *
 * 1. **Not connected** — OAuth connection UI with quick/custom credentials.
 * 2. **Connected, no property** — Property selection list from GSC API.
 * 3. **Connected with property** — Full performance dashboard including:
 *    - Date range selector with per-page rows control
 *    - 4 KPI metric cards (impressions, clicks, CTR, position) with toggle
 *    - Performance timeline chart (Chart.js) with period comparison
 *    - Combined queries & pages table with drill-down, search, sort, pagination
 *    - Winning/losing queries tables with badge-styled change indicators
 *    - Optimization impact section with per-page breakdown (search + pagination)
 *    - Image sitemap status display
 *
 * All dynamic content is populated via AJAX (search-console.js).
 * Server data is localized via wp_localize_script as `infypGsc`.
 *
 * @package    IPAIS
 * @subpackage Views
 * @since 1.0.0
 *
 * @see INFYP_Admin_Pages::render_search_console_page()  Enqueue point.
 * @see INFYP_Search_Console_Ajax                        AJAX handlers.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Get connection state.
$infyp_oauth   = \IPAIS\Core\INFYP_Google_OAuth_Manager::get_instance();
$infyp_connected = $infyp_oauth->is_connected();
$infyp_property  = $infyp_oauth->get_property();

// Check for mock connection (dev/testing).
$infyp_is_mock  = \IPAIS\Dev\INFYP_GSC_Mock_Data::is_mock_connection();
$infyp_is_debug = defined( 'WP_DEBUG' ) && WP_DEBUG;

// Handle OAuth callback code in URL.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce checked in AJAX handler
$infyp_gsc_callback = isset( $_GET['gsc_callback'] ) ? 1 : 0;
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$infyp_gsc_code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$infyp_gsc_proxy_code = isset( $_GET['proxy_code'] ) ? sanitize_text_field( wp_unslash( $_GET['proxy_code'] ) ) : '';
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$infyp_gsc_error = isset( $_GET['gsc_error'] ) ? sanitize_text_field( wp_unslash( $_GET['gsc_error'] ) ) : '';

// Get last sync info.
$infyp_last_sync = get_option( 'infyp_gsc_last_sync', 0 );
$infyp_last_sync_text = $infyp_last_sync
	? human_time_diff( $infyp_last_sync, time() ) . ' ' . __( 'ago', 'infy-ai-image-seo-optimizer' )
	: __( 'Never', 'infy-ai-image-seo-optimizer' );
?>
<div class="wrap infyp-search-console-page" id="infyp-gsc-wrap">
	<h1><?php esc_html_e( 'Search Console', 'infy-ai-image-seo-optimizer' ); ?><?php echo wp_kses_post( apply_filters( 'infyp_admin_header_badge', '' ) ); ?></h1>

	<div id="infyp-gsc-notice" style="display: none;"></div>

	<?php if ( $infyp_is_mock && $infyp_connected ) : ?>
		<!-- Mock Connection Banner -->
		<div class="infyp-gsc-mock-banner">
			<div class="infyp-gsc-mock-banner-info">
				<span class="dashicons dashicons-warning"></span>
				<span><?php esc_html_e( 'Dev Mode: Using mock Search Console data. This is not a real Google connection.', 'infy-ai-image-seo-optimizer' ); ?></span>
			</div>
			<button type="button" id="infyp-gsc-test-disconnect" class="button button-small">
				<span class="dashicons dashicons-trash"></span>
				<?php esc_html_e( 'Remove Mock Data', 'infy-ai-image-seo-optimizer' ); ?>
			</button>
		</div>
	<?php endif; ?>

	<?php if ( $infyp_gsc_callback && ( ! empty( $infyp_gsc_code ) || ! empty( $infyp_gsc_proxy_code ) ) ) : ?>
		<!-- OAuth callback: exchange code for tokens via AJAX -->
		<div class="infyp-gsc-loading" id="infyp-gsc-callback-loading">
			<div class="infyp-card">
				<div class="infyp-card-body" style="text-align: center; padding: 40px;">
					<span class="spinner is-active" style="float: none; margin: 0 10px 0 0;"></span>
					<p><?php esc_html_e( 'Connecting to Google Search Console...', 'infy-ai-image-seo-optimizer' ); ?></p>
				</div>
			</div>
		</div>
	<?php endif; ?>

	<?php if ( ! $infyp_connected ) : ?>
		<!-- ============================================================
		     STATE 1: Not Connected
		     ============================================================ -->
		<div id="infyp-gsc-state-connect" class="infyp-gsc-state">
			<div class="infyp-card">
				<div class="infyp-card-header">
					<h3 class="infyp-card-title"><?php esc_html_e( 'Google Search Console', 'infy-ai-image-seo-optimizer' ); ?></h3>
					<span class="infyp-badge infyp-badge-warning">
						<span class="dashicons dashicons-warning"></span>
						<?php esc_html_e( 'Not Connected', 'infy-ai-image-seo-optimizer' ); ?>
					</span>
				</div>
				<div class="infyp-card-body">
					<div class="infyp-intro-box">
						<span class="dashicons dashicons-info-outline"></span>
						<p><?php esc_html_e( 'Connect your Google Search Console to track how your images perform in Google Image Search. See impressions, clicks, CTR, and ranking positions for pages containing your optimized images.', 'infy-ai-image-seo-optimizer' ); ?></p>
					</div>

					<div class="infyp-grid infyp-grid-2 infyp-gap-md infyp-info-grid">
						<div class="infyp-info-item">
							<div class="infyp-font-semibold infyp-mb-sm"><?php esc_html_e( 'Image Search Performance', 'infy-ai-image-seo-optimizer' ); ?></div>
							<div>
								<span class="infyp-badge infyp-badge-success">
									<span class="dashicons dashicons-visibility"></span>
									<?php esc_html_e( 'Impressions & Clicks', 'infy-ai-image-seo-optimizer' ); ?>
								</span>
								<div class="infyp-text-sm infyp-text-muted infyp-mt-xs">
									<?php esc_html_e( 'Track how often your images appear in search results and how many clicks they receive.', 'infy-ai-image-seo-optimizer' ); ?>
								</div>
							</div>
						</div>

						<div class="infyp-info-item">
							<div class="infyp-font-semibold infyp-mb-sm"><?php esc_html_e( 'Optimization Tracking', 'infy-ai-image-seo-optimizer' ); ?></div>
							<div>
								<span class="infyp-badge infyp-badge-success">
									<span class="dashicons dashicons-chart-line"></span>
									<?php esc_html_e( 'Before & After', 'infy-ai-image-seo-optimizer' ); ?>
								</span>
								<div class="infyp-text-sm infyp-text-muted infyp-mt-xs">
									<?php esc_html_e( 'Measure the impact of your SEO optimizations with before and after comparisons.', 'infy-ai-image-seo-optimizer' ); ?>
								</div>
							</div>
						</div>

						<div class="infyp-info-item">
							<div class="infyp-font-semibold infyp-mb-sm"><?php esc_html_e( 'Search Queries', 'infy-ai-image-seo-optimizer' ); ?></div>
							<div>
								<span class="infyp-badge infyp-badge-success">
									<span class="dashicons dashicons-chart-bar"></span>
									<?php esc_html_e( 'Top Queries', 'infy-ai-image-seo-optimizer' ); ?>
								</span>
								<div class="infyp-text-sm infyp-text-muted infyp-mt-xs">
									<?php esc_html_e( 'Discover which search terms bring visitors to your images and pages.', 'infy-ai-image-seo-optimizer' ); ?>
								</div>
							</div>
						</div>

						<div class="infyp-info-item">
							<div class="infyp-font-semibold infyp-mb-sm"><?php esc_html_e( 'Privacy & Security', 'infy-ai-image-seo-optimizer' ); ?></div>
							<div>
								<span class="infyp-badge infyp-badge-warning">
									<span class="dashicons dashicons-shield"></span>
									<?php esc_html_e( 'Read-Only Access', 'infy-ai-image-seo-optimizer' ); ?>
								</span>
								<div class="infyp-text-sm infyp-text-muted infyp-mt-xs">
									<?php esc_html_e( 'We use read-only access and never store your data on our servers. All data stays in your WordPress database.', 'infy-ai-image-seo-optimizer' ); ?>
								</div>
							</div>
						</div>
					</div>

					<div class="infyp-gsc-connect-actions">
						<button type="button" id="infyp-gsc-quick-connect" class="button button-primary">
							<span class="dashicons dashicons-admin-links"></span>
							<?php esc_html_e( 'Connect with Google', 'infy-ai-image-seo-optimizer' ); ?>
						</button>
					</div>

					<?php if ( $infyp_is_debug ) : ?>
						<!-- Dev: Test Connect with Mock Data -->
						<div class="infyp-gsc-dev-connect">
							<div class="infyp-gsc-dev-connect-inner">
								<button type="button" id="infyp-gsc-test-connect" class="button">
									<span class="dashicons dashicons-database-add"></span>
									<?php esc_html_e( 'Dev: Test Connect with Mock Data', 'infy-ai-image-seo-optimizer' ); ?>
								</button>
								<span class="description">
									<?php esc_html_e( 'Seeds mock Search Console data from your media library images (WP_DEBUG only)', 'infy-ai-image-seo-optimizer' ); ?>
								</span>
							</div>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</div>

	<?php elseif ( $infyp_connected && empty( $infyp_property ) ) : ?>
		<!-- ============================================================
		     STATE 2: Connected, Choose Property
		     ============================================================ -->
		<div id="infyp-gsc-state-property" class="infyp-gsc-state">
			<div class="infyp-card infyp-gsc-property-card">
				<div class="infyp-card-header">
					<h3>
						<span class="dashicons dashicons-yes-alt"></span>
						<?php esc_html_e( 'Connected to Google', 'infy-ai-image-seo-optimizer' ); ?>
					</h3>
				</div>
				<div class="infyp-card-body">
					<p><?php esc_html_e( 'Select a Search Console property to track:', 'infy-ai-image-seo-optimizer' ); ?></p>

					<div id="infyp-gsc-property-list" class="infyp-gsc-property-list">
						<div class="infyp-gsc-loading-properties">
							<span class="spinner is-active" style="float: none;"></span>
							<?php esc_html_e( 'Loading properties...', 'infy-ai-image-seo-optimizer' ); ?>
						</div>
					</div>

					<div class="infyp-gsc-property-actions">
						<button type="button" id="infyp-gsc-disconnect" class="button button-link-delete">
							<?php esc_html_e( 'Disconnect', 'infy-ai-image-seo-optimizer' ); ?>
						</button>
					</div>
				</div>
			</div>
		</div>

	<?php else : ?>
		<!-- ============================================================
		     STATE 3: Connected, Dashboard Active
		     ============================================================ -->
		<div id="infyp-gsc-state-dashboard" class="infyp-gsc-state">

			<!-- Connection Bar -->
			<div class="infyp-gsc-connection-bar">
				<div class="infyp-gsc-connection-info">
					<span class="infyp-gsc-connected-dot"></span>
					<span>
						<?php
						printf(
							/* translators: %s: Search Console property URL */
							esc_html__( 'Property: %s', 'infy-ai-image-seo-optimizer' ),
							'<strong>' . esc_html( $infyp_property ) . '</strong>'
						);
						?>
					</span>
					<span class="infyp-gsc-separator">|</span>
					<span class="infyp-gsc-last-sync">
						<?php
						printf(
							/* translators: %s: human-readable time since last sync */
							esc_html__( 'Last synced: %s', 'infy-ai-image-seo-optimizer' ),
							esc_html( $infyp_last_sync_text )
						);
						?>
					</span>
				</div>
				<div class="infyp-gsc-connection-actions">
					<button type="button" id="infyp-gsc-sync-now" class="button button-secondary button-small">
						<span class="dashicons dashicons-update"></span>
						<?php esc_html_e( 'Sync Now', 'infy-ai-image-seo-optimizer' ); ?>
					</button>
					<button type="button" id="infyp-gsc-disconnect" class="button button-link-delete button-small">
						<?php esc_html_e( 'Disconnect', 'infy-ai-image-seo-optimizer' ); ?>
					</button>
				</div>
			</div>

			<!-- Date Range Selector -->
			<div class="infyp-gsc-date-range">
				<div class="infyp-gsc-range-buttons">
					<button type="button" class="button infyp-gsc-range-btn" data-range="7d"><?php esc_html_e( '7 days', 'infy-ai-image-seo-optimizer' ); ?></button>
					<button type="button" class="button infyp-gsc-range-btn active" data-range="28d"><?php esc_html_e( '28 days', 'infy-ai-image-seo-optimizer' ); ?></button>
					<button type="button" class="button infyp-gsc-range-btn" data-range="3m"><?php esc_html_e( '3 months', 'infy-ai-image-seo-optimizer' ); ?></button>
					<button type="button" class="button infyp-gsc-range-btn" data-range="6m"><?php esc_html_e( '6 months', 'infy-ai-image-seo-optimizer' ); ?></button>
					<button type="button" class="button infyp-gsc-range-btn" data-range="12m"><?php esc_html_e( '12 months', 'infy-ai-image-seo-optimizer' ); ?></button>
				</div>
				<div class="infyp-gsc-per-page-wrap">
					<label for="infyp-gsc-per-page"><?php esc_html_e( 'Rows:', 'infy-ai-image-seo-optimizer' ); ?></label>
					<select id="infyp-gsc-per-page" class="infyp-gsc-per-page-select">
						<option value="10" selected>10</option>
						<option value="25">25</option>
						<option value="50">50</option>
						<option value="100">100</option>
						<option value="250">250</option>
					</select>
				</div>
			</div>

			<!-- Metric Cards -->
			<div class="infyp-gsc-metrics-grid" id="infyp-gsc-metrics">
				<div class="infyp-card infyp-gsc-metric-card active" data-metric="impressions">
					<span class="infyp-gsc-metric-check dashicons dashicons-yes-alt"></span>
					<div class="infyp-card-body">
						<span class="infyp-gsc-metric-dot" style="background:#2271b1;"></span>
						<div class="infyp-gsc-metric-value" id="infyp-gsc-impressions">-</div>
						<div class="infyp-gsc-metric-label"><?php esc_html_e( 'Impressions', 'infy-ai-image-seo-optimizer' ); ?></div>
						<div class="infyp-gsc-metric-delta" id="infyp-gsc-impressions-delta"></div>
					</div>
				</div>
				<div class="infyp-card infyp-gsc-metric-card active" data-metric="clicks">
					<span class="infyp-gsc-metric-check dashicons dashicons-yes-alt"></span>
					<div class="infyp-card-body">
						<span class="infyp-gsc-metric-dot" style="background:#10b981;"></span>
						<div class="infyp-gsc-metric-value" id="infyp-gsc-clicks">-</div>
						<div class="infyp-gsc-metric-label"><?php esc_html_e( 'Clicks', 'infy-ai-image-seo-optimizer' ); ?></div>
						<div class="infyp-gsc-metric-delta" id="infyp-gsc-clicks-delta"></div>
					</div>
				</div>
				<div class="infyp-card infyp-gsc-metric-card active" data-metric="ctr">
					<span class="infyp-gsc-metric-check dashicons dashicons-yes-alt"></span>
					<div class="infyp-card-body">
						<span class="infyp-gsc-metric-dot" style="background:#f59e0b;"></span>
						<div class="infyp-gsc-metric-value" id="infyp-gsc-ctr">-</div>
						<div class="infyp-gsc-metric-label"><?php esc_html_e( 'CTR', 'infy-ai-image-seo-optimizer' ); ?></div>
						<div class="infyp-gsc-metric-delta" id="infyp-gsc-ctr-delta"></div>
					</div>
				</div>
				<div class="infyp-card infyp-gsc-metric-card active" data-metric="position">
					<span class="infyp-gsc-metric-check dashicons dashicons-yes-alt"></span>
					<div class="infyp-card-body">
						<span class="infyp-gsc-metric-dot" style="background:#6366f1;"></span>
						<div class="infyp-gsc-metric-value" id="infyp-gsc-position">-</div>
						<div class="infyp-gsc-metric-label"><?php esc_html_e( 'Avg Position', 'infy-ai-image-seo-optimizer' ); ?></div>
						<div class="infyp-gsc-metric-delta" id="infyp-gsc-position-delta"></div>
					</div>
				</div>
			</div>

			<!-- Performance Timeline Chart -->
			<div class="infyp-card infyp-gsc-chart-card" id="infyp-gsc-chart-card">
				<div class="infyp-card-header">
					<h3><?php esc_html_e( 'Performance Over Time', 'infy-ai-image-seo-optimizer' ); ?></h3>
				</div>
				<div class="infyp-card-body">
					<canvas id="infyp-gsc-timeline-chart"></canvas>
				</div>
			</div>

			<!-- Combined Queries & Pages -->
			<div class="infyp-card infyp-gsc-table-card infyp-gsc-combined-card" id="infyp-gsc-combined-card">
				<div class="infyp-card-header">
					<h3><?php esc_html_e( 'Top Image Search Queries & Pages', 'infy-ai-image-seo-optimizer' ); ?></h3>
					<div class="infyp-gsc-table-search">
						<input type="text" class="infyp-gsc-search-input" data-table="combined"
							placeholder="<?php esc_attr_e( 'Search queries & pages...', 'infy-ai-image-seo-optimizer' ); ?>">
					</div>
				</div>
				<div class="infyp-card-body">
					<div class="infyp-gsc-table-main" id="infyp-gsc-combined-main">
						<table class="widefat striped" id="infyp-gsc-combined-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Query', 'infy-ai-image-seo-optimizer' ); ?></th>
									<th><?php esc_html_e( 'Page', 'infy-ai-image-seo-optimizer' ); ?></th>
									<th class="infyp-gsc-num infyp-gsc-sortable desc" data-table="combined" data-sort="impressions">
										<?php esc_html_e( 'Impressions', 'infy-ai-image-seo-optimizer' ); ?> <span class="infyp-gsc-sort-icon"></span>
									</th>
									<th class="infyp-gsc-num infyp-gsc-sortable" data-table="combined" data-sort="clicks">
										<?php esc_html_e( 'Clicks', 'infy-ai-image-seo-optimizer' ); ?> <span class="infyp-gsc-sort-icon"></span>
									</th>
									<th class="infyp-gsc-num infyp-gsc-sortable" data-table="combined" data-sort="ctr">
										<?php esc_html_e( 'CTR', 'infy-ai-image-seo-optimizer' ); ?> <span class="infyp-gsc-sort-icon"></span>
									</th>
									<th class="infyp-gsc-num infyp-gsc-sortable" data-table="combined" data-sort="position">
										<?php esc_html_e( 'Position', 'infy-ai-image-seo-optimizer' ); ?> <span class="infyp-gsc-sort-icon"></span>
									</th>
								</tr>
							</thead>
							<tbody>
								<tr class="infyp-gsc-loading-row">
									<td colspan="6"><?php esc_html_e( 'Loading data...', 'infy-ai-image-seo-optimizer' ); ?></td>
								</tr>
							</tbody>
						</table>
						<div class="infyp-gsc-pagination" id="infyp-gsc-combined-info"></div>
					</div>
					<div class="infyp-gsc-drill-down" id="infyp-gsc-combined-drill" style="display:none;">
						<div class="infyp-gsc-drill-header">
							<button type="button" class="button button-small infyp-gsc-drill-back" data-table="combined">&larr; <?php esc_html_e( 'Back', 'infy-ai-image-seo-optimizer' ); ?></button>
							<span class="infyp-gsc-drill-title"></span>
						</div>
						<table class="widefat striped">
							<thead>
								<tr>
									<th class="infyp-gsc-drill-dimension-header"><?php esc_html_e( 'Page', 'infy-ai-image-seo-optimizer' ); ?></th>
									<th class="infyp-gsc-num infyp-gsc-sortable desc" data-table="combined-drill" data-sort="impressions">
										<?php esc_html_e( 'Impressions', 'infy-ai-image-seo-optimizer' ); ?> <span class="infyp-gsc-sort-icon"></span>
									</th>
									<th class="infyp-gsc-num infyp-gsc-sortable" data-table="combined-drill" data-sort="clicks">
										<?php esc_html_e( 'Clicks', 'infy-ai-image-seo-optimizer' ); ?> <span class="infyp-gsc-sort-icon"></span>
									</th>
									<th class="infyp-gsc-num infyp-gsc-sortable" data-table="combined-drill" data-sort="ctr">
										<?php esc_html_e( 'CTR', 'infy-ai-image-seo-optimizer' ); ?> <span class="infyp-gsc-sort-icon"></span>
									</th>
									<th class="infyp-gsc-num infyp-gsc-sortable" data-table="combined-drill" data-sort="position">
										<?php esc_html_e( 'Position', 'infy-ai-image-seo-optimizer' ); ?> <span class="infyp-gsc-sort-icon"></span>
									</th>
								</tr>
							</thead>
							<tbody></tbody>
						</table>
						<div class="infyp-gsc-pagination" id="infyp-gsc-combined-drill-info"></div>
					</div>
				</div>
			</div>

			<!-- Winning & Losing Queries -->
			<div class="infyp-gsc-tables-grid" id="infyp-gsc-wl-section" style="display: none;">
				<div class="infyp-card infyp-gsc-table-card" id="infyp-gsc-winners-card">
					<div class="infyp-card-header">
						<h3>
							<span class="dashicons dashicons-arrow-up-alt" style="color: var(--infyp-success);"></span>
							<?php esc_html_e( 'Winning Queries', 'infy-ai-image-seo-optimizer' ); ?>
						</h3>
						<div class="infyp-gsc-table-search">
							<input type="text" class="infyp-gsc-search-input" data-table="winners"
								placeholder="<?php esc_attr_e( 'Search queries...', 'infy-ai-image-seo-optimizer' ); ?>">
						</div>
					</div>
					<div class="infyp-card-body">
						<table class="widefat striped" id="infyp-gsc-winners-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Query', 'infy-ai-image-seo-optimizer' ); ?></th>
									<th class="infyp-gsc-num infyp-gsc-sortable" data-table="winners" data-sort="current_position">
										<?php esc_html_e( 'Position', 'infy-ai-image-seo-optimizer' ); ?> <span class="infyp-gsc-sort-icon"></span>
									</th>
									<th class="infyp-gsc-num infyp-gsc-sortable desc" data-table="winners" data-sort="position_change">
										<?php esc_html_e( 'Change', 'infy-ai-image-seo-optimizer' ); ?> <span class="infyp-gsc-sort-icon"></span>
									</th>
									<th class="infyp-gsc-num infyp-gsc-sortable" data-table="winners" data-sort="current_impressions">
										<?php esc_html_e( 'Impressions', 'infy-ai-image-seo-optimizer' ); ?> <span class="infyp-gsc-sort-icon"></span>
									</th>
								</tr>
							</thead>
							<tbody>
								<tr class="infyp-gsc-loading-row">
									<td colspan="4"><?php esc_html_e( 'Loading data...', 'infy-ai-image-seo-optimizer' ); ?></td>
								</tr>
							</tbody>
						</table>
						<div class="infyp-gsc-pagination" id="infyp-gsc-winners-info"></div>
					</div>
				</div>

				<div class="infyp-card infyp-gsc-table-card" id="infyp-gsc-losers-card">
					<div class="infyp-card-header">
						<h3>
							<span class="dashicons dashicons-arrow-down-alt" style="color: var(--infyp-error);"></span>
							<?php esc_html_e( 'Losing Queries', 'infy-ai-image-seo-optimizer' ); ?>
						</h3>
						<div class="infyp-gsc-table-search">
							<input type="text" class="infyp-gsc-search-input" data-table="losers"
								placeholder="<?php esc_attr_e( 'Search queries...', 'infy-ai-image-seo-optimizer' ); ?>">
						</div>
					</div>
					<div class="infyp-card-body">
						<table class="widefat striped" id="infyp-gsc-losers-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Query', 'infy-ai-image-seo-optimizer' ); ?></th>
									<th class="infyp-gsc-num infyp-gsc-sortable" data-table="losers" data-sort="current_position">
										<?php esc_html_e( 'Position', 'infy-ai-image-seo-optimizer' ); ?> <span class="infyp-gsc-sort-icon"></span>
									</th>
									<th class="infyp-gsc-num infyp-gsc-sortable asc" data-table="losers" data-sort="position_change">
										<?php esc_html_e( 'Change', 'infy-ai-image-seo-optimizer' ); ?> <span class="infyp-gsc-sort-icon"></span>
									</th>
									<th class="infyp-gsc-num infyp-gsc-sortable" data-table="losers" data-sort="current_impressions">
										<?php esc_html_e( 'Impressions', 'infy-ai-image-seo-optimizer' ); ?> <span class="infyp-gsc-sort-icon"></span>
									</th>
								</tr>
							</thead>
							<tbody>
								<tr class="infyp-gsc-loading-row">
									<td colspan="4"><?php esc_html_e( 'Loading data...', 'infy-ai-image-seo-optimizer' ); ?></td>
								</tr>
							</tbody>
						</table>
						<div class="infyp-gsc-pagination" id="infyp-gsc-losers-info"></div>
					</div>
				</div>
			</div>

			<!-- Optimization Impact -->
			<div class="infyp-card infyp-gsc-impact-card" id="infyp-gsc-impact-section" style="display: none;">
				<div class="infyp-card-header">
					<h3>
						<span class="dashicons dashicons-chart-line"></span>
						<?php esc_html_e( 'Optimization Impact', 'infy-ai-image-seo-optimizer' ); ?>
					</h3>
				</div>
				<div class="infyp-card-body">
					<p class="infyp-gsc-impact-intro">
						<?php esc_html_e( 'Since you started optimizing images:', 'infy-ai-image-seo-optimizer' ); ?>
					</p>
					<div class="infyp-gsc-impact-metrics" id="infyp-gsc-impact-metrics">
						<!-- Populated via JS -->
					</div>
					<div id="infyp-gsc-impact-table-wrap" style="display: none;">
						<div class="infyp-gsc-impact-table-header">
							<h4><?php esc_html_e( 'Per-Page Impact', 'infy-ai-image-seo-optimizer' ); ?></h4>
							<div class="infyp-gsc-table-search">
								<input type="text" class="infyp-gsc-search-input" id="infyp-gsc-impact-search"
									placeholder="<?php esc_attr_e( 'Search pages...', 'infy-ai-image-seo-optimizer' ); ?>">
							</div>
						</div>
						<table class="widefat striped" id="infyp-gsc-impact-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Page', 'infy-ai-image-seo-optimizer' ); ?></th>
									<th class="infyp-gsc-num"><?php esc_html_e( 'Optimized', 'infy-ai-image-seo-optimizer' ); ?></th>
									<th class="infyp-gsc-num"><?php esc_html_e( 'Impr. Change', 'infy-ai-image-seo-optimizer' ); ?></th>
									<th class="infyp-gsc-num"><?php esc_html_e( 'Clicks Change', 'infy-ai-image-seo-optimizer' ); ?></th>
									<th class="infyp-gsc-num"><?php esc_html_e( 'Position', 'infy-ai-image-seo-optimizer' ); ?></th>
								</tr>
							</thead>
							<tbody></tbody>
						</table>
						<div class="infyp-gsc-pagination" id="infyp-gsc-impact-info"></div>
					</div>
				</div>
			</div>

			<!-- Image Sitemap Status -->
			<div class="infyp-card infyp-gsc-sitemap-card" id="infyp-gsc-sitemap-section" style="display: none;">
				<div class="infyp-card-header">
					<h3>
						<span class="dashicons dashicons-media-text"></span>
						<?php esc_html_e( 'Image Sitemap Status', 'infy-ai-image-seo-optimizer' ); ?>
					</h3>
				</div>
				<div class="infyp-card-body">
					<div class="infyp-gsc-sitemap-info" id="infyp-gsc-sitemap-info">
						<!-- Populated via JS -->
					</div>
				</div>
			</div>

			<!-- No Data Message (shown when table is empty) -->
			<div id="infyp-gsc-no-data" class="infyp-card" style="display: none;">
				<div class="infyp-card-body" style="text-align: center; padding: 40px;">
					<span class="dashicons dashicons-clock" style="font-size: 48px; width: 48px; height: 48px; color: #94a3b8;"></span>
					<h3><?php esc_html_e( 'Waiting for data...', 'infy-ai-image-seo-optimizer' ); ?></h3>
					<p><?php esc_html_e( 'Search Console data typically takes 2-3 days to become available. The initial sync has been started and data will appear here once it is ready.', 'infy-ai-image-seo-optimizer' ); ?></p>
				</div>
			</div>

		</div>
	<?php endif; ?>
</div>
