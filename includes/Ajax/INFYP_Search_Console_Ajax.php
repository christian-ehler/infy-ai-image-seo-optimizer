<?php
/**
 * Search Console AJAX Handlers - Google Search Console Integration
 *
 * Registers and handles all AJAX requests for the Google Search Console
 * integration including OAuth flow, property selection, dashboard data
 * retrieval, and manual sync operations.
 *
 * @package   IPAIS\Ajax
 * @since 1.0.0
 */

namespace IPAIS\Ajax;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use IPAIS\Core\INFYP_Google_OAuth_Manager;
use IPAIS\Core\INFYP_Search_Console_Client;
use IPAIS\Cron\INFYP_Search_Console_Sync;
use IPAIS\Database\INFYP_Search_Console_Table;

/**
 * Search Console AJAX Handlers Class
 *
 * @since 1.0.0
 */
class INFYP_Search_Console_Ajax {

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->register_ajax_handlers();
	}

	/**
	 * Register all AJAX handlers for Search Console operations.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function register_ajax_handlers() {
		$handlers = [
			'infyp_gsc_get_auth_url'           => 'handle_get_auth_url',
			'infyp_gsc_handle_callback'         => 'handle_callback',
			'infyp_gsc_disconnect'              => 'handle_disconnect',
			'infyp_gsc_select_property'         => 'handle_select_property',
			'infyp_gsc_get_dashboard_data'      => 'handle_get_dashboard_data',
			'infyp_gsc_get_table_data'          => 'handle_get_table_data',
			'infyp_gsc_manual_sync'             => 'handle_manual_sync',
		];

		foreach ( $handlers as $action => $method ) {
			add_action( 'wp_ajax_' . $action, [ $this, $method ] );
		}
	}

	/**
	 * Verify nonce and capability for the current AJAX request.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function verify_request() {
		if ( ! check_ajax_referer( 'infyp_ajax_nonce', 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Security check failed.', 'infy-ai-image-seo-optimizer' ) ] );
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'You do not have permission to perform this action.', 'infy-ai-image-seo-optimizer' ) ] );
			return;
		}
	}

	/**
	 * Build the OAuth redirect URI.
	 *
	 * @since 1.0.0
	 * @return string The fully-qualified admin redirect URI.
	 */
	private function get_redirect_uri() {
		return admin_url( 'admin.php?page=ai-image-seo-optimizer-search-console&gsc_callback=1' );
	}

	/**
	 * Parse a date range string into start/end dates and previous period dates.
	 *
	 * @since 1.0.0
	 *
	 * @param string $range        Date range identifier: '7d', '28d', '3m', '6m', '12m', or 'custom'.
	 * @param string $custom_start Custom start date in Y-m-d format.
	 * @param string $custom_end   Custom end date in Y-m-d format.
	 * @return array Associative array with start_date, end_date, prev_start, prev_end.
	 */
	private function parse_date_range( $range, $custom_start = '', $custom_end = '' ) {
		$end_date = gmdate( 'Y-m-d', strtotime( '-1 day' ) );

		switch ( $range ) {
			case '7d':
				$start_date = gmdate( 'Y-m-d', strtotime( '-7 days' ) );
				break;

			case '3m':
				$start_date = gmdate( 'Y-m-d', strtotime( '-3 months' ) );
				break;

			case '6m':
				$start_date = gmdate( 'Y-m-d', strtotime( '-6 months' ) );
				break;

			case '12m':
				$start_date = gmdate( 'Y-m-d', strtotime( '-12 months' ) );
				break;

			case 'custom':
				$start_date = ! empty( $custom_start ) ? sanitize_text_field( $custom_start ) : gmdate( 'Y-m-d', strtotime( '-28 days' ) );
				$end_date   = ! empty( $custom_end ) ? sanitize_text_field( $custom_end ) : $end_date;
				break;

			case '28d':
			default:
				$start_date = gmdate( 'Y-m-d', strtotime( '-28 days' ) );
				break;
		}

		// Calculate the period length in days.
		$period_start = new \DateTime( $start_date );
		$period_end   = new \DateTime( $end_date );
		$interval     = $period_start->diff( $period_end );
		$period_days  = $interval->days + 1;

		// Previous period ends the day before the current period starts.
		$prev_end   = gmdate( 'Y-m-d', strtotime( $start_date . ' -1 day' ) );
		$prev_start = gmdate( 'Y-m-d', strtotime( $prev_end . ' -' . ( $period_days - 1 ) . ' days' ) );

		return [
			'start_date' => $start_date,
			'end_date'   => $end_date,
			'prev_start' => $prev_start,
			'prev_end'   => $prev_end,
		];
	}

	/**
	 * AJAX handler: Generate OAuth authorization URL.
	 *
	 * @since 1.0.0
	 * @return void Sends JSON response and terminates.
	 */
	public function handle_get_auth_url() {
		$this->verify_request();

		$oauth = INFYP_Google_OAuth_Manager::get_instance();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verify_request().
		$method = isset( $_POST['method'] ) ? sanitize_text_field( wp_unslash( $_POST['method'] ) ) : 'quick';

		if ( ! in_array( $method, [ 'quick', 'custom' ], true ) ) {
			$method = 'quick';
		}

		update_option( 'infyp_gsc_connection_method', sanitize_text_field( $method ), false );

		$redirect_uri = $this->get_redirect_uri();
		$auth_url     = $oauth->get_authorization_url( $redirect_uri );

		if ( is_wp_error( $auth_url ) ) {
			wp_send_json_error( [ 'message' => esc_html( $auth_url->get_error_message() ) ] );
			return;
		}

		wp_send_json_success( [ 'auth_url' => $auth_url ] );
	}

	/**
	 * AJAX handler: Exchange authorization code for tokens.
	 *
	 * @since 1.0.0
	 * @return void Sends JSON response and terminates.
	 */
	public function handle_callback() {
		$this->verify_request();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verify_request().
		$state = isset( $_POST['state'] ) ? sanitize_text_field( wp_unslash( $_POST['state'] ) ) : '';

		// Validate OAuth state (required for CSRF protection).
		if ( empty( $state ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Missing OAuth state parameter. Please try again.', 'infy-ai-image-seo-optimizer' ) ] );
			return;
		}
		$stored = get_transient( 'infyp_gsc_oauth_state_' . $state );
		delete_transient( 'infyp_gsc_oauth_state_' . $state );
		if ( empty( $stored ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Invalid or expired session. Please try again.', 'infy-ai-image-seo-optimizer' ) ] );
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verify_request().
		$proxy_code = isset( $_POST['proxy_code'] ) ? sanitize_text_field( wp_unslash( $_POST['proxy_code'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verify_request().
		$code = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';

		// Determine which code to use: proxy_code for quick connect, code for custom.
		$exchange_code = ! empty( $proxy_code ) ? $proxy_code : $code;

		if ( empty( $exchange_code ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Authorization code is missing.', 'infy-ai-image-seo-optimizer' ) ] );
			return;
		}

		$oauth        = INFYP_Google_OAuth_Manager::get_instance();
		$redirect_uri = $this->get_redirect_uri();
		$result       = $oauth->exchange_code_for_tokens( $exchange_code, $redirect_uri );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		// Retrieve available Search Console properties.
		$sites  = [];
		$client = new INFYP_Search_Console_Client();
		$result = $client->list_sites();

		if ( ! is_wp_error( $result ) ) {
			$sites = $result;
		}

		wp_send_json_success( [
			'message' => esc_html__( 'Connected successfully!', 'infy-ai-image-seo-optimizer' ),
			'sites'   => $sites,
		] );
	}

	/**
	 * AJAX handler: Disconnect from Google Search Console.
	 *
	 * @since 1.0.0
	 * @return void Sends JSON response and terminates.
	 */
	public function handle_disconnect() {
		$this->verify_request();

		$oauth = INFYP_Google_OAuth_Manager::get_instance();
		$oauth->disconnect();

		// Truncate the GSC data table by dropping and recreating it.
		INFYP_Search_Console_Table::drop_table();
		INFYP_Search_Console_Table::on_activation();

		wp_send_json_success( [ 'message' => esc_html__( 'Disconnected from Google Search Console.', 'infy-ai-image-seo-optimizer' ) ] );
	}

	/**
	 * AJAX handler: Select a Search Console property.
	 *
	 * @since 1.0.0
	 * @return void Sends JSON response and terminates.
	 */
	public function handle_select_property() {
		$this->verify_request();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verify_request().
		$property_url = isset( $_POST['property_url'] ) ? sanitize_text_field( wp_unslash( $_POST['property_url'] ) ) : '';

		if ( empty( $property_url ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Property URL is required.', 'infy-ai-image-seo-optimizer' ) ] );
			return;
		}

		// Validate that the selected property matches this site's domain.
		$site_host = wp_parse_url( site_url(), PHP_URL_HOST );

		if ( strpos( $property_url, 'sc-domain:' ) === 0 ) {
			$property_domain = substr( $property_url, 10 );
			if ( $property_domain !== $site_host
				&& substr( $site_host, -strlen( '.' . $property_domain ) ) !== '.' . $property_domain ) {
				wp_send_json_error( [
					'message' => esc_html__( 'The selected property does not match this site\'s domain.', 'infy-ai-image-seo-optimizer' ),
				] );
				return;
			}
		} else {
			$property_host = wp_parse_url( $property_url, PHP_URL_HOST );
			if ( $property_host !== $site_host ) {
				wp_send_json_error( [
					'message' => esc_html__( 'The selected property does not match this site\'s domain.', 'infy-ai-image-seo-optimizer' ),
				] );
				return;
			}
		}

		$oauth = INFYP_Google_OAuth_Manager::get_instance();
		$oauth->set_property( $property_url );

		// Schedule the recurring sync cron job.
		INFYP_Search_Console_Sync::schedule_cron();

		// Trigger the initial data sync.
		$sync = INFYP_Search_Console_Sync::get_instance();
		$sync->run_initial_sync();

		wp_send_json_success( [ 'message' => esc_html__( 'Property selected. Initial sync started.', 'infy-ai-image-seo-optimizer' ) ] );
	}

	/**
	 * AJAX handler: Fetch dashboard metrics and supporting data.
	 *
	 * Returns all data needed for the Search Console dashboard in a single response:
	 * summary KPIs, daily time series (current + previous period for comparison),
	 * top combined query/page rows, winning/losing queries, optimization impact,
	 * and image sitemap status.
	 *
	 * Tables (combined, winners, losers) are loaded separately via handle_get_table_data()
	 * to support server-side pagination, so top_combined and winning/losing data here
	 * serve only as initial previews.
	 *
	 * @since 1.0.0
	 * @return void Sends JSON response and terminates.
	 */
	public function handle_get_dashboard_data() {
		$this->verify_request();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verify_request().
		$date_range = isset( $_POST['date_range'] ) ? sanitize_text_field( wp_unslash( $_POST['date_range'] ) ) : '28d';

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verify_request().
		$custom_start = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verify_request().
		$custom_end = isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : '';

		$dates = $this->parse_date_range( $date_range, $custom_start, $custom_end );

		$table = new INFYP_Search_Console_Table();

		// Current period data.
		$summary      = $table->get_summary( $dates['start_date'], $dates['end_date'], 'image' );
		$top_combined = $table->get_performance_data( array(
			'start_date'         => $dates['start_date'],
			'end_date'           => $dates['end_date'],
			'search_type'        => 'image',
			'dimensions'         => array( 'query', 'page_url' ),
			'exclude_null_query' => true,
			'order_by'           => 'impressions',
			'order'              => 'DESC',
			'limit'              => 10,
		) );
		// Daily time series for the performance chart (current + previous period for dashed comparison lines).
		$daily_series      = $table->get_daily_time_series( $dates['start_date'], $dates['end_date'], 'image' );
		$prev_daily_series = $table->get_daily_time_series( $dates['prev_start'], $dates['prev_end'], 'image' );

		// Previous period summary for KPI delta comparison.
		$comparison = $table->get_summary( $dates['prev_start'], $dates['prev_end'], 'image' );

		// Winning and losing queries (period-over-period).
		$winning_losing = $table->get_winning_losing_queries(
			$dates['start_date'],
			$dates['end_date'],
			$dates['prev_start'],
			$dates['prev_end'],
			10
		);

		// Optimization impact data.
		$impact = get_option( 'infyp_gsc_optimization_impact', [] );

		// Sitemap info (fresh from API).
		$sitemap = null;
		$oauth   = INFYP_Google_OAuth_Manager::get_instance();

		if ( $oauth->is_connected() ) {
			$property = $oauth->get_property();

			if ( ! empty( $property ) ) {
				$client         = new INFYP_Search_Console_Client();
				$sitemap_result = $client->get_image_sitemap_info( $property );

				if ( ! is_wp_error( $sitemap_result ) ) {
					$sitemap = $sitemap_result;
				}
			}
		}

		// Last sync timestamp.
		$last_sync = INFYP_Search_Console_Sync::get_last_sync_text();

		wp_send_json_success( [
			'summary'           => $summary,
			'comparison'        => $comparison,
			'top_combined'      => $top_combined ? $top_combined : array(),
			'daily_series'      => $daily_series,
			'prev_daily_series' => $prev_daily_series,
			'winning_queries'   => $winning_losing['winners'],
			'losing_queries'    => $winning_losing['losers'],
			'impact'            => $impact,
			'sitemap'           => $sitemap,
			'last_sync'         => $last_sync,
		] );
	}

	/**
	 * AJAX handler: Fetch table-specific data with search, sort, and drill-down.
	 *
	 * Supports table types: queries, pages, query_pages, page_queries, winners, losers.
	 *
	 * @since 1.0.0
	 * @return void Sends JSON response and terminates.
	 */
	public function handle_get_table_data() {
		$this->verify_request();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in verify_request().
		$table       = isset( $_POST['table'] ) ? sanitize_text_field( wp_unslash( $_POST['table'] ) ) : '';
		$date_range  = isset( $_POST['date_range'] ) ? sanitize_text_field( wp_unslash( $_POST['date_range'] ) ) : '28d';
		$custom_start = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '';
		$custom_end  = isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : '';
		$search      = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$order_by    = isset( $_POST['order_by'] ) ? sanitize_text_field( wp_unslash( $_POST['order_by'] ) ) : '';
		$order       = isset( $_POST['order'] ) ? sanitize_text_field( wp_unslash( $_POST['order'] ) ) : 'desc';
		$limit       = isset( $_POST['limit'] ) ? absint( $_POST['limit'] ) : 25;
		$offset      = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$drill_query = isset( $_POST['drill_query'] ) ? sanitize_text_field( wp_unslash( $_POST['drill_query'] ) ) : '';
		$drill_page  = isset( $_POST['drill_page'] ) ? sanitize_text_field( wp_unslash( $_POST['drill_page'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$valid_tables = array( 'combined', 'queries', 'pages', 'query_pages', 'page_queries', 'winners', 'losers' );
		if ( ! in_array( $table, $valid_tables, true ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid table type.', 'infy-ai-image-seo-optimizer' ) ) );
			return;
		}

		$dates = $this->parse_date_range( $date_range, $custom_start, $custom_end );
		$db    = new INFYP_Search_Console_Table();

		// Handle winners/losers separately since they use a different query method.
		if ( 'winners' === $table || 'losers' === $table ) {
			$wl_result = $db->get_winning_losing_queries(
				$dates['start_date'],
				$dates['end_date'],
				$dates['prev_start'],
				$dates['prev_end'],
				$limit,
				array(
					'query_filter' => $search,
					'order_by'     => $order_by ?: 'position_change',
					'order'        => $order,
					'type'         => $table,
					'offset'       => $offset,
				)
			);
			wp_send_json_success( array(
				'rows'  => $wl_result['rows'],
				'total' => $wl_result['total'],
			) );
			return;
		}

		// Build args for get_performance_data / get_filtered_count.
		$args = array(
			'start_date'  => $dates['start_date'],
			'end_date'    => $dates['end_date'],
			'search_type' => 'image',
			'order_by'    => $order_by ?: 'impressions',
			'order'       => $order,
			'limit'       => $limit,
			'offset'      => $offset,
		);

		switch ( $table ) {
			case 'combined':
				$args['dimensions']         = array( 'query', 'page_url' );
				$args['combined_search']    = $search;
				$args['exclude_null_query'] = true;
				break;

			case 'queries':
				$args['dimensions']         = array( 'query' );
				$args['query_filter']       = $search;
				$args['exclude_null_query'] = true;
				break;

			case 'pages':
				$args['dimensions']  = array( 'page_url' );
				$args['page_filter'] = $search;
				break;

			case 'query_pages':
				$args['dimensions']         = array( 'page_url' );
				$args['query_filter_exact'] = $drill_query;
				$args['page_filter']        = $search;
				break;

			case 'page_queries':
				$args['dimensions']         = array( 'query' );
				$args['page_filter_exact']  = $drill_page;
				$args['query_filter']       = $search;
				$args['exclude_null_query'] = true;
				break;
		}

		$rows  = $db->get_performance_data( $args );
		$total = $db->get_filtered_count( $args );

		wp_send_json_success( array(
			'rows'  => $rows ? $rows : array(),
			'total' => $total,
		) );
	}

	/**
	 * AJAX handler: Trigger an immediate manual sync.
	 *
	 * @since 1.0.0
	 * @return void Sends JSON response and terminates.
	 */
	public function handle_manual_sync() {
		$this->verify_request();

		$oauth = INFYP_Google_OAuth_Manager::get_instance();

		if ( ! $oauth->is_connected() ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Not connected to Google Search Console.', 'infy-ai-image-seo-optimizer' ) ] );
			return;
		}

		$sync = INFYP_Search_Console_Sync::get_instance();
		$sync->run_sync();

		wp_send_json_success( [
			'message'   => esc_html__( 'Sync completed successfully.', 'infy-ai-image-seo-optimizer' ),
			'last_sync' => INFYP_Search_Console_Sync::get_last_sync_text(),
		] );
	}

}
