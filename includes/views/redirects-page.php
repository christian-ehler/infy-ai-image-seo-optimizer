<?php
/**
 * Redirects Monitor Page Template
 *
 * Displays summary cards, filters, and the redirect list table.
 *
 * Available variables (set by INFYP_Admin_Pages):
 *   $redirect_list_table  INFYP_Redirect_List_Table  Prepared list table instance.
 *   $summary              array                       Summary stats from INFYP_Redirect_Table.
 *
 * @package   IPAIS\Admin
 * @since     1.1.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$infyp_settings = \IPAIS\Core\INFYP_Redirect_Manager::get_settings();
$infyp_enabled  = ! empty( $infyp_settings['enabled'] );
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Image Redirects', 'infy-ai-image-seo-optimizer' ); ?><?php echo wp_kses_post( apply_filters( 'infyp_admin_header_badge', '' ) ); ?></h1>

	<?php if ( ! $infyp_enabled ) : ?>
		<div class="notice notice-warning">
			<p><?php esc_html_e( 'Image redirects are currently disabled. Enable them in Settings to start capturing 301 redirects when images are renamed.', 'infy-ai-image-seo-optimizer' ); ?></p>
		</div>
	<?php endif; ?>

	<?php
	// Redirect delivery health — do the 301s actually reach visitors?
	$infyp_health       = \IPAIS\Core\INFYP_Redirect_Health::get_state();
	$infyp_health_state = $infyp_health['state'];
	?>
	<?php if ( $infyp_enabled ) : ?>
		<div class="infyp-card infyp-health-card" style="margin:16px 0;padding:14px 16px;border-left:4px solid <?php echo esc_attr( 'manual_needed' === $infyp_health_state ? '#d63638' : ( in_array( $infyp_health_state, [ 'php_verified', 'browser_verified' ], true ) ? '#00a32a' : '#dba617' ) ); ?>;background:#fff;">
			<p style="margin:0 0 4px;">
				<strong><?php esc_html_e( 'Redirect delivery check:', 'infy-ai-image-seo-optimizer' ); ?></strong>
				<span id="infyp-health-status" class="infyp-health-badge infyp-health-<?php echo esc_attr( $infyp_health_state ); ?>">
					<?php echo esc_html( \IPAIS\Core\INFYP_Redirect_Health::state_label( $infyp_health_state ) ); ?>
				</span>
			</p>
			<p id="infyp-health-detail" class="description" style="margin:0 0 8px;">
				<?php
				if ( $infyp_health['checked_at'] ) {
					printf(
						/* translators: %s: formatted date/time of the last check */
						esc_html__( 'Last checked: %s', 'infy-ai-image-seo-optimizer' ),
						esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $infyp_health['checked_at'] ) )
					);
				} else {
					esc_html_e( 'The test requests a deliberately missing image URL and verifies your server lets WordPress answer it with a 301.', 'infy-ai-image-seo-optimizer' );
				}
				?>
			</p>
			<?php if ( 'manual_needed' === $infyp_health_state ) : ?>
				<p style="margin:0 0 8px;">
					<?php esc_html_e( 'What this means: your web server (or a CDN in front of it) answers requests for missing image files itself, so the plugin never gets the chance to redirect old image URLs after a rename. Content on your own site keeps working — the plugin rewrites those references — but external links, embeds and image search results pointing at old URLs get an error. Ask your host to route missing files under wp-content/uploads through WordPress, or add server-level redirect rules for renamed images.', 'infy-ai-image-seo-optimizer' ); ?>
				</p>
			<?php endif; ?>
			<?php $infyp_unmatched = \IPAIS\Core\INFYP_Redirect_Manager::get_unmatched_404_count(); ?>
			<?php if ( $infyp_unmatched > 0 ) : ?>
				<p class="description" style="margin:0 0 8px;">
					<?php
					printf(
						/* translators: %d: number of unmatched old-image requests in the last 7 days */
						esc_html( _n(
							'%d request for an old image URL reached WordPress without a matching redirect in the last 7 days.',
							'%d requests for old image URLs reached WordPress without a matching redirect in the last 7 days.',
							$infyp_unmatched,
							'infy-ai-image-seo-optimizer'
						) ),
						(int) $infyp_unmatched
					);
					?>
				</p>
			<?php endif; ?>
			<button type="button" class="button" id="infyp-health-test"><?php esc_html_e( 'Test redirects now', 'infy-ai-image-seo-optimizer' ); ?></button>
		</div>
	<?php endif; ?>

	<!-- Summary Cards -->
	<div class="infyp-api-usage-summary">
		<div class="infyp-usage-card">
			<h3><?php esc_html_e( 'Active Redirects', 'infy-ai-image-seo-optimizer' ); ?></h3>
			<div class="infyp-usage-number"><?php echo esc_html( number_format( $summary['total_active'] ) ); ?></div>
			<div class="infyp-usage-detail"><?php esc_html_e( 'Serving 301 responses', 'infy-ai-image-seo-optimizer' ); ?></div>
		</div>

		<div class="infyp-usage-card">
			<h3><?php esc_html_e( 'Total Hits', 'infy-ai-image-seo-optimizer' ); ?></h3>
			<div class="infyp-usage-number"><?php echo esc_html( number_format( $summary['total_hits'] ) ); ?></div>
			<div class="infyp-usage-detail"><?php esc_html_e( 'All time', 'infy-ai-image-seo-optimizer' ); ?></div>
		</div>

		<div class="infyp-usage-card">
			<h3><?php esc_html_e( 'Unused Redirects', 'infy-ai-image-seo-optimizer' ); ?></h3>
			<div class="infyp-usage-number"><?php echo esc_html( number_format( $summary['zero_hit_count'] ) ); ?></div>
			<div class="infyp-usage-detail"><?php esc_html_e( 'Zero hits since creation', 'infy-ai-image-seo-optimizer' ); ?></div>
		</div>

		<div class="infyp-usage-card">
			<h3><?php esc_html_e( 'Expired / Disabled', 'infy-ai-image-seo-optimizer' ); ?></h3>
			<div class="infyp-usage-number"><?php echo esc_html( number_format( $summary['total_expired'] + $summary['total_disabled'] ) ); ?></div>
			<div class="infyp-usage-detail"><?php esc_html_e( 'Not currently serving', 'infy-ai-image-seo-optimizer' ); ?></div>
		</div>
	</div>

	<?php
	/**
	 * Fires before the redirects table.
	 *
	 * @since 1.1.0
	 *
	 * @param \IPAIS\Tables\INFYP_Redirect_List_Table $list_table The list table instance.
	 */
	do_action( 'infyp_before_redirects_table', $redirect_list_table );
	?>

	<form method="get">
		<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Preserving page parameter for form submission ?>
		<input type="hidden" name="page" value="<?php echo esc_attr( isset( $_REQUEST['page'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['page'] ) ) : '' ); ?>" />
		<?php
		$redirect_list_table->search_box( __( 'Search URLs', 'infy-ai-image-seo-optimizer' ), 'redirect-search' );
		$redirect_list_table->display();
		?>
	</form>

	<?php
	/**
	 * Fires after the redirects table.
	 *
	 * @since 1.1.0
	 *
	 * @param \IPAIS\Tables\INFYP_Redirect_List_Table $list_table The list table instance.
	 */
	do_action( 'infyp_after_redirects_table', $redirect_list_table );
	?>
</div>
