<?php
/**
 * Background Search Console Data Sync
 *
 * Syncs Google Search Console performance data via WP-Cron
 * and calculates optimization impact by comparing before/after
 * metrics for pages with optimized images.
 *
 * @package   IPAIS\Cron
 * @since 1.0.0
 */

namespace IPAIS\Cron;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use IPAIS\Core\INFYP_Google_OAuth_Manager;
use IPAIS\Core\INFYP_Logger;
use IPAIS\Core\INFYP_Search_Console_Client;
use IPAIS\Database\INFYP_Search_Console_Table;

/**
 * Class INFYP_Search_Console_Sync
 *
 * Handles background synchronization of Google Search Console data.
 * Runs via WP-Cron twice daily to keep performance metrics up to date
 * and calculates the SEO impact of image optimizations.
 *
 * @since 1.0.0
 */
class INFYP_Search_Console_Sync {

	/**
	 * Cron hook name
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const CRON_HOOK = 'infyp_sync_search_console';

	/**
	 * Option key for last sync timestamp
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const LAST_SYNC_OPTION = 'infyp_gsc_last_sync';

	/**
	 * Option key for optimization impact data
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const IMPACT_OPTION = 'infyp_gsc_optimization_impact';

	/**
	 * Singleton instance
	 *
	 * @since 1.0.0
	 * @var    INFYP_Search_Console_Sync|null
	 * @access private
	 */
	private static $instance = null;

	/**
	 * Get singleton instance
	 *
	 * @since 1.0.0
	 * @return INFYP_Search_Console_Sync
	 */
	public static function get_instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor - registers cron hook
	 *
	 * @since 1.0.0
	 * @access private
	 */
	private function __construct() {
		add_action( self::CRON_HOOK, [ $this, 'run_sync' ] );
	}

	/**
	 * Schedule the cron job if not already scheduled
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function schedule_cron() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'twicedaily', self::CRON_HOOK );
		}
	}

	/**
	 * Unschedule the cron job (for plugin deactivation)
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function unschedule_cron() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Main cron callback - syncs Search Console data
	 *
	 * Pulls the last 5 days of data from Google Search Console
	 * for both image and web search types, cleans up old data,
	 * and recalculates optimization impact.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function run_sync() {
		$oauth_manager = INFYP_Google_OAuth_Manager::get_instance();

		if ( ! $oauth_manager->is_connected() ) {
			INFYP_Logger::log( 'Search Console Sync: Skipping sync — not connected to Google.', 'INFO' );
			return;
		}

		$property = $oauth_manager->get_property();

		if ( empty( $property ) ) {
			INFYP_Logger::log( 'Search Console Sync: Skipping sync — no property selected.', 'INFO' );
			return;
		}

		$client = new INFYP_Search_Console_Client();
		$table  = new INFYP_Search_Console_Table();

		$start_date = gmdate( 'Y-m-d', strtotime( '-5 days' ) );
		$end_date   = gmdate( 'Y-m-d', strtotime( '-1 day' ) );

		$this->sync_image_search_data( $client, $property, $start_date, $end_date, $table );
		$this->sync_web_search_data( $client, $property, $start_date, $end_date, $table );

		$table->cleanup_old_data( 16 );

		$this->calculate_optimization_impact( $table );

		update_option( self::LAST_SYNC_OPTION, time(), false );

		INFYP_Logger::log( 'Search Console Sync: Sync completed successfully.', 'INFO' );
	}

	/**
	 * Run an initial sync after first connection
	 *
	 * Pulls the last 30 days of data instead of the usual 5 days.
	 * This method should be called manually (not from cron) after
	 * the user first connects their Google account.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function run_initial_sync() {
		$oauth_manager = INFYP_Google_OAuth_Manager::get_instance();

		if ( ! $oauth_manager->is_connected() ) {
			INFYP_Logger::log( 'Search Console Sync: Skipping initial sync — not connected to Google.', 'INFO' );
			return;
		}

		$property = $oauth_manager->get_property();

		if ( empty( $property ) ) {
			INFYP_Logger::log( 'Search Console Sync: Skipping initial sync — no property selected.', 'INFO' );
			return;
		}

		$client = new INFYP_Search_Console_Client();
		$table  = new INFYP_Search_Console_Table();

		$start_date = gmdate( 'Y-m-d', strtotime( '-30 days' ) );
		$end_date   = gmdate( 'Y-m-d', strtotime( '-1 day' ) );

		$this->sync_image_search_data( $client, $property, $start_date, $end_date, $table );
		$this->sync_web_search_data( $client, $property, $start_date, $end_date, $table );

		$table->cleanup_old_data( 16 );

		$this->calculate_optimization_impact( $table );

		update_option( self::LAST_SYNC_OPTION, time(), false );

		INFYP_Logger::log( 'Search Console Sync: Initial sync completed successfully (30 days).', 'INFO' );
	}

	/**
	 * Get the last sync Unix timestamp
	 *
	 * @since 1.0.0
	 * @return int|false Unix timestamp of the last sync, or false if never synced.
	 */
	public static function get_last_sync_time() {
		$last_sync = get_option( self::LAST_SYNC_OPTION, false );

		if ( empty( $last_sync ) ) {
			return false;
		}

		return (int) $last_sync;
	}

	/**
	 * Get human-readable time since last sync
	 *
	 * @since 1.0.0
	 * @return string Human-readable time difference (e.g. "2 hours ago") or "Never".
	 */
	public static function get_last_sync_text() {
		$last_sync = self::get_last_sync_time();

		if ( false === $last_sync ) {
			return __( 'Never', 'infy-ai-image-seo-optimizer' );
		}

		return human_time_diff( $last_sync, time() ) . ' ' . __( 'ago', 'infy-ai-image-seo-optimizer' );
	}

	/**
	 * Sync image search data from Google Search Console
	 *
	 * @since 1.0.0
	 * @access private
	 *
	 * @param INFYP_Search_Console_Client $client     Search Console API client.
	 * @param string                      $property   Search Console property URL.
	 * @param string                      $start_date Start date in Y-m-d format.
	 * @param string                      $end_date   End date in Y-m-d format.
	 * @param INFYP_Search_Console_Table  $table      Database table instance.
	 * @return int|false Number of rows synced, or false on failure.
	 */
	private function sync_image_search_data( $client, $property, $start_date, $end_date, $table ) {
		$response = $client->get_image_search_data( $property, $start_date, $end_date, [ 'date', 'page', 'query' ], 25000 );

		if ( is_wp_error( $response ) ) {
			INFYP_Logger::log( 'Search Console Sync: Failed to fetch image search data — ' . $response->get_error_message(), 'ERROR' );
			return false;
		}

		if ( empty( $response['rows'] ) ) {
			INFYP_Logger::log( 'Search Console Sync: No image search data rows returned.', 'INFO' );
			return 0;
		}

		$rows = array();

		foreach ( $response['rows'] as $row ) {
			$rows[] = array(
				'date'        => isset( $row['keys'][0] ) ? $row['keys'][0] : '',
				'page_url'    => isset( $row['keys'][1] ) ? $row['keys'][1] : '',
				'query'       => isset( $row['keys'][2] ) ? $row['keys'][2] : null,
				'search_type' => 'image',
				'clicks'      => isset( $row['clicks'] ) ? (int) $row['clicks'] : 0,
				'impressions' => isset( $row['impressions'] ) ? (int) $row['impressions'] : 0,
				'ctr'         => isset( $row['ctr'] ) ? (float) $row['ctr'] : 0.0,
				'position'    => isset( $row['position'] ) ? (float) $row['position'] : 0.0,
			);
		}

		$result = $table->upsert_rows( $rows );

		INFYP_Logger::log( 'Search Console Sync: Synced ' . count( $rows ) . ' image search rows.', 'INFO' );

		return false === $result ? false : count( $rows );
	}

	/**
	 * Sync web search data from Google Search Console
	 *
	 * @since 1.0.0
	 * @access private
	 *
	 * @param INFYP_Search_Console_Client $client     Search Console API client.
	 * @param string                      $property   Search Console property URL.
	 * @param string                      $start_date Start date in Y-m-d format.
	 * @param string                      $end_date   End date in Y-m-d format.
	 * @param INFYP_Search_Console_Table  $table      Database table instance.
	 * @return int|false Number of rows synced, or false on failure.
	 */
	private function sync_web_search_data( $client, $property, $start_date, $end_date, $table ) {
		$response = $client->get_web_search_data( $property, $start_date, $end_date, [ 'date', 'page' ], 25000 );

		if ( is_wp_error( $response ) ) {
			INFYP_Logger::log( 'Search Console Sync: Failed to fetch web search data — ' . $response->get_error_message(), 'ERROR' );
			return false;
		}

		if ( empty( $response['rows'] ) ) {
			INFYP_Logger::log( 'Search Console Sync: No web search data rows returned.', 'INFO' );
			return 0;
		}

		$rows = array();

		foreach ( $response['rows'] as $row ) {
			$rows[] = array(
				'date'        => isset( $row['keys'][0] ) ? $row['keys'][0] : '',
				'page_url'    => isset( $row['keys'][1] ) ? $row['keys'][1] : '',
				'query'       => null,
				'search_type' => 'web',
				'clicks'      => isset( $row['clicks'] ) ? (int) $row['clicks'] : 0,
				'impressions' => isset( $row['impressions'] ) ? (int) $row['impressions'] : 0,
				'ctr'         => isset( $row['ctr'] ) ? (float) $row['ctr'] : 0.0,
				'position'    => isset( $row['position'] ) ? (float) $row['position'] : 0.0,
			);
		}

		$result = $table->upsert_rows( $rows );

		INFYP_Logger::log( 'Search Console Sync: Synced ' . count( $rows ) . ' web search rows.', 'INFO' );

		return false === $result ? false : count( $rows );
	}

	/**
	 * Calculate the SEO impact of image optimizations
	 *
	 * Queries pages that have been optimized (have _infyp_last_optimized meta),
	 * compares 28-day before/after Search Console metrics around the optimization
	 * date, and stores the aggregated impact data.
	 *
	 * @since 1.0.0
	 * @access private
	 *
	 * @param INFYP_Search_Console_Table $table Database table instance.
	 * @return void
	 */
	private function calculate_optimization_impact( $table ) {
		global $wpdb;

		// Get pages that have optimized images with their optimization dates.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$optimized_pages = $wpdb->get_results(
			"SELECT DISTINCT p.guid AS page_url, pm.meta_value AS optimized_date
			FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
			WHERE pm.meta_key = '_infyp_last_optimized'
			AND pm.meta_value != ''
			AND p.post_status = 'publish'
			ORDER BY pm.meta_value DESC"
		);

		if ( empty( $optimized_pages ) ) {
			INFYP_Logger::log( 'Search Console Sync: No optimized pages found for impact calculation.', 'INFO' );
			return;
		}

		$total_impressions_before = 0;
		$total_impressions_after  = 0;
		$total_clicks_before      = 0;
		$total_clicks_after       = 0;
		$sum_position_before      = 0.0;
		$sum_position_after       = 0.0;
		$pages_tracked            = 0;
		$per_page_impact          = array();

		foreach ( $optimized_pages as $page ) {
			$optimized_date = $page->optimized_date;

			// Ensure we have a valid date.
			$optimized_timestamp = strtotime( $optimized_date );
			if ( false === $optimized_timestamp ) {
				continue;
			}

			$before_start = gmdate( 'Y-m-d', strtotime( '-28 days', $optimized_timestamp ) );
			$before_end   = gmdate( 'Y-m-d', strtotime( '-1 day', $optimized_timestamp ) );
			$after_start  = gmdate( 'Y-m-d', $optimized_timestamp );
			$after_end    = gmdate( 'Y-m-d', strtotime( '+27 days', $optimized_timestamp ) );

			$gsc_table_name = esc_sql( INFYP_Search_Console_Table::get_table_name() );

			// Get before-period metrics for this page.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$before = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT
						COALESCE( SUM( impressions ), 0 ) AS total_impressions,
						COALESCE( SUM( clicks ), 0 ) AS total_clicks,
						COALESCE( AVG( position ), 0 ) AS avg_position
					FROM `{$gsc_table_name}`
					WHERE page_url = %s
						AND search_type = 'image'
						AND date >= %s
						AND date <= %s",
					$page->page_url,
					$before_start,
					$before_end
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			// Get after-period metrics for this page.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$after = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT
						COALESCE( SUM( impressions ), 0 ) AS total_impressions,
						COALESCE( SUM( clicks ), 0 ) AS total_clicks,
						COALESCE( AVG( position ), 0 ) AS avg_position
					FROM `{$gsc_table_name}`
					WHERE page_url = %s
						AND search_type = 'image'
						AND date >= %s
						AND date <= %s",
					$page->page_url,
					$after_start,
					$after_end
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			if ( null === $before || null === $after ) {
				continue;
			}

			$impressions_before = (int) $before->total_impressions;
			$impressions_after  = (int) $after->total_impressions;
			$clicks_before      = (int) $before->total_clicks;
			$clicks_after       = (int) $after->total_clicks;
			$position_before    = (float) $before->avg_position;
			$position_after     = (float) $after->avg_position;

			$total_impressions_before += $impressions_before;
			$total_impressions_after  += $impressions_after;
			$total_clicks_before      += $clicks_before;
			$total_clicks_after       += $clicks_after;
			$sum_position_before      += $position_before;
			$sum_position_after       += $position_after;
			$pages_tracked++;

			$per_page_impact[] = array(
				'page_url'           => $page->page_url,
				'optimized_date'     => $optimized_date,
				'impressions_before' => $impressions_before,
				'impressions_after'  => $impressions_after,
				'clicks_before'      => $clicks_before,
				'clicks_after'       => $clicks_after,
				'position_before'    => $position_before,
				'position_after'     => $position_after,
			);
		}

		// Sort per-page impact by impressions_after descending.
		// All pages are retained for client-side search and pagination in the dashboard.
		usort( $per_page_impact, function ( $a, $b ) {
			return $b['impressions_after'] - $a['impressions_after'];
		} );

		$impact = array(
			'total_impressions_before' => $total_impressions_before,
			'total_impressions_after'  => $total_impressions_after,
			'total_clicks_before'      => $total_clicks_before,
			'total_clicks_after'       => $total_clicks_after,
			'avg_position_before'      => $pages_tracked > 0 ? round( $sum_position_before / $pages_tracked, 2 ) : 0.0,
			'avg_position_after'       => $pages_tracked > 0 ? round( $sum_position_after / $pages_tracked, 2 ) : 0.0,
			'pages_tracked'            => $pages_tracked,
			'per_page_impact'          => $per_page_impact,
			'last_calculated'          => time(),
		);

		update_option( self::IMPACT_OPTION, $impact, false );

		INFYP_Logger::log( 'Search Console Sync: Optimization impact calculated for ' . $pages_tracked . ' pages.', 'INFO' );
	}
}
