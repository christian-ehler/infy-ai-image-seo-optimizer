<?php
/**
 * GSC Mock Data Seeder - Development/Testing Only
 *
 * Provides a fake Google Search Console connection with realistic mock data
 * seeded from actual media library images. This allows testing the Search
 * Console dashboard UI without a real Google account connection.
 *
 * TEMPORARY: Remove this file and its references after testing is complete.
 *
 * @package   IPAIS\Dev
 * @since 1.0.0
 */

namespace IPAIS\Dev;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use IPAIS\Core\INFYP_Google_OAuth_Manager;
use IPAIS\Database\INFYP_Search_Console_Table;

/**
 * GSC Mock Data Seeder
 *
 * @since 1.0.0
 */
class INFYP_GSC_Mock_Data {

	/**
	 * Option key to flag mock connection.
	 *
	 * @var string
	 */
	const MOCK_FLAG = 'infyp_gsc_mock_connection';

	/**
	 * Number of days of mock data to generate.
	 *
	 * @var int
	 */
	const DAYS_OF_DATA = 90;

	/**
	 * Constructor — registers AJAX handlers.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_action( 'wp_ajax_infyp_gsc_test_connect', [ $this, 'handle_test_connect' ] );
		add_action( 'wp_ajax_infyp_gsc_test_disconnect', [ $this, 'handle_test_disconnect' ] );
	}

	/**
	 * Check if currently using a mock connection.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public static function is_mock_connection() {
		return (bool) get_option( self::MOCK_FLAG, false );
	}

	/**
	 * AJAX handler: Create mock connection and seed data.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function handle_test_connect() {
		if ( ! check_ajax_referer( 'infyp_ajax_nonce', 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => 'Security check failed.' ] );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Insufficient permissions.' ] );
		}

		$result = $this->create_mock_connection();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		wp_send_json_success( [
			'message'    => sprintf( 'Mock connection created with %d data rows.', $result ),
			'rows_added' => $result,
		] );
	}

	/**
	 * AJAX handler: Remove mock connection and clear data.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function handle_test_disconnect() {
		if ( ! check_ajax_referer( 'infyp_ajax_nonce', 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => 'Security check failed.' ] );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Insufficient permissions.' ] );
		}

		$this->remove_mock_connection();

		wp_send_json_success( [ 'message' => 'Mock connection removed.' ] );
	}

	/**
	 * Create a mock GSC connection.
	 *
	 * Sets fake OAuth tokens, property, and seeds the database with
	 * realistic mock performance data derived from actual media library images.
	 *
	 * @since 1.0.0
	 * @return int|\WP_Error Number of rows inserted, or WP_Error on failure.
	 */
	public function create_mock_connection() {
		$site_url = site_url( '/' );

		// Store mock tokens via the OAuth manager (handles encryption).
		$oauth = INFYP_Google_OAuth_Manager::get_instance();
		$oauth->store_tokens(
			'mock_access_token_' . wp_generate_password( 20, false ),
			'mock_refresh_token_' . wp_generate_password( 20, false ),
			86400 * 365 // Expires in 1 year.
		);

		$oauth->set_property( $site_url );
		update_option( 'infyp_gsc_connection_method', 'mock', false );
		update_option( 'infyp_gsc_last_sync', time(), false );
		update_option( self::MOCK_FLAG, true, false );

		// Ensure the GSC data table exists.
		INFYP_Search_Console_Table::on_activation();

		// Seed mock data.
		$rows = $this->generate_mock_data( $site_url );

		if ( empty( $rows ) ) {
			return new \WP_Error( 'no_data', 'No images found in media library to generate mock data.' );
		}

		$table  = new INFYP_Search_Console_Table();
		$result = $table->upsert_rows( $rows );

		if ( false === $result ) {
			return new \WP_Error( 'db_error', 'Failed to insert mock data into database.' );
		}

		// Generate mock optimization impact data.
		$this->generate_mock_impact( $site_url );

		return count( $rows );
	}

	/**
	 * Remove mock connection and clean up all data.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function remove_mock_connection() {
		// Only remove if it's a mock connection.
		if ( ! self::is_mock_connection() ) {
			return;
		}

		delete_option( 'infyp_google_oauth_tokens' );
		delete_option( 'infyp_gsc_connected_property' );
		delete_option( 'infyp_gsc_connection_method' );
		delete_option( 'infyp_gsc_last_sync' );
		delete_option( 'infyp_gsc_optimization_impact' );
		delete_option( self::MOCK_FLAG );

		// Truncate GSC data table.
		INFYP_Search_Console_Table::drop_table();
		INFYP_Search_Console_Table::on_activation();
	}

	/**
	 * Generate mock GSC performance data rows.
	 *
	 * Queries the media library for images, finds pages that use them,
	 * and creates realistic daily performance data.
	 *
	 * @since 1.0.0
	 *
	 * @param string $site_url The site URL for the mock property.
	 * @return array Array of row data for upsert.
	 */
	private function generate_mock_data( $site_url ) {
		global $wpdb;

		// Get images from media library.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time mock data generation.
		$images = $wpdb->get_results(
			"SELECT ID, post_title, post_name, guid
			 FROM {$wpdb->posts}
			 WHERE post_type = 'attachment'
			   AND post_mime_type LIKE 'image/%'
			   AND post_status = 'inherit'
			 ORDER BY post_date DESC
			 LIMIT 40"
		);

		if ( empty( $images ) ) {
			return [];
		}

		// Get published pages and posts to use as page URLs.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time mock data generation.
		$pages = $wpdb->get_results(
			"SELECT ID, post_title, post_name, post_type
			 FROM {$wpdb->posts}
			 WHERE post_status = 'publish'
			   AND post_type IN ('post', 'page')
			 ORDER BY post_date DESC
			 LIMIT 20"
		);

		if ( empty( $pages ) ) {
			// Fallback: use the homepage.
			$pages = [
				(object) [
					'ID'         => 0,
					'post_title' => 'Home',
					'post_name'  => '',
					'post_type'  => 'page',
				],
			];
		}

		// Build page URLs.
		$page_urls = [];
		foreach ( $pages as $page ) {
			if ( $page->ID > 0 ) {
				$url = get_permalink( $page->ID );
				if ( $url ) {
					$page_urls[] = $url;
				}
			} else {
				$page_urls[] = $site_url;
			}
		}

		// Generate search queries from image filenames.
		$image_queries = [];
		foreach ( $images as $image ) {
			$queries = $this->generate_queries_from_image( $image );
			$image_queries = array_merge( $image_queries, $queries );
		}

		// Deduplicate and limit.
		$image_queries = array_unique( $image_queries );
		$image_queries = array_slice( $image_queries, 0, 50 );

		if ( empty( $image_queries ) ) {
			$image_queries = [ 'image', 'photo', 'picture' ];
		}

		// Device and country distributions.
		$devices   = [ 'DESKTOP', 'MOBILE', 'TABLET' ];
		$countries = [ 'usa', 'deu', 'gbr', 'fra', 'ind', 'bra', 'jpn', 'can', 'aus', 'esp' ];

		$rows       = [];
		$today      = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );
		$query_count = count( $image_queries );
		$page_count  = count( $page_urls );

		// Note: wp_rand() handles its own entropy — no seeding needed.

		// Assign a position trend direction per query (-1 = improving, 0 = stable, 1 = declining).
		$query_trends = [];
		foreach ( $image_queries as $idx => $q ) {
			// ~40% improving, ~20% declining, ~40% stable.
			if ( $idx % 5 === 0 || $idx % 5 === 3 ) {
				$query_trends[ $q ] = -1; // Improving (position decreasing).
			} elseif ( $idx % 5 === 1 ) {
				$query_trends[ $q ] = 1; // Declining (position increasing).
			} else {
				$query_trends[ $q ] = 0; // Stable.
			}
		}

		// For each day in the range, generate data rows.
		for ( $d = self::DAYS_OF_DATA; $d >= 1; $d-- ) {
			$date = gmdate( 'Y-m-d', strtotime( "-{$d} days" ) );

			// Day-of-week factor (weekdays get more traffic).
			$dow = (int) gmdate( 'N', strtotime( $date ) );
			$dow_factor = ( $dow <= 5 ) ? 1.0 : 0.6;

			// Trend factor (gradual increase over time simulating optimization impact).
			$trend_factor = 0.6 + ( ( self::DAYS_OF_DATA - $d ) / self::DAYS_OF_DATA ) * 0.6;

			// Day progress (0.0 = start, 1.0 = today) for position trending.
			$day_progress = ( self::DAYS_OF_DATA - $d ) / self::DAYS_OF_DATA;

			// Pick a subset of queries and pages for this day (not all every day).
			$daily_query_count = min( wp_rand( 5, 15 ), $query_count );
			$daily_page_count  = min( wp_rand( 3, 8 ), $page_count );

			$daily_queries = array_slice( $image_queries, wp_rand( 0, max( 0, $query_count - $daily_query_count ) ), $daily_query_count );
			$daily_pages   = array_slice( $page_urls, wp_rand( 0, max( 0, $page_count - $daily_page_count ) ), $daily_page_count );

			foreach ( $daily_queries as $qi => $query ) {
				// Each query appears on 1-3 pages.
				$pages_for_query = array_slice( $daily_pages, 0, wp_rand( 1, min( 3, count( $daily_pages ) ) ) );

				foreach ( $pages_for_query as $page_url ) {
					// Pick a primary device.
					$device = $devices[ wp_rand( 0, 2 ) ];

					// Base metrics with randomness.
					$base_impressions = wp_rand( 5, 200 );
					$impressions      = max( 1, (int) round( $base_impressions * $dow_factor * $trend_factor ) );
					$base_ctr         = ( wp_rand( 10, 120 ) ) / 1000; // 1% - 12%
					$clicks           = max( 0, (int) round( $impressions * $base_ctr ) );
					$ctr              = $impressions > 0 ? round( $clicks / $impressions, 4 ) : 0;

					// Position with trend: improving queries get better positions over time.
					$base_position = wp_rand( 80, 400 ) / 10; // 8.0 - 40.0
					$trend_dir     = isset( $query_trends[ $query ] ) ? $query_trends[ $query ] : 0;
					$pos_shift     = $trend_dir * $day_progress * wp_rand( 30, 120 ) / 10; // up to 12 positions shift
					$position      = round( max( 1.0, $base_position + $pos_shift + wp_rand( -20, 20 ) / 10 ), 2 );

					// Country.
					$country = $countries[ wp_rand( 0, count( $countries ) - 1 ) ];

					$rows[] = [
						'date'        => $date,
						'search_type' => 'image',
						'page_url'    => $page_url,
						'query'       => $query,
						'device'      => $device,
						'country'     => $country,
						'clicks'      => $clicks,
						'impressions' => $impressions,
						'ctr'         => $ctr,
						'position'    => $position,
					];
				}
			}
		}

		return $rows;
	}

	/**
	 * Generate search queries from an image's metadata.
	 *
	 * Extracts meaningful keywords from the image filename, title, and alt text
	 * to create realistic search queries that someone might use in Google Image Search.
	 *
	 * @since 1.0.0
	 *
	 * @param object $image WP_Post object for the attachment.
	 * @return array Array of search query strings.
	 */
	private function generate_queries_from_image( $image ) {
		$queries = [];

		// Extract keywords from post_name (slug).
		$slug = $image->post_name;

		// Remove common image prefixes/suffixes and dimensions.
		$clean = preg_replace( '/[-_]?\d{2,4}x\d{2,4}/', '', $slug );
		$clean = preg_replace( '/[-_](scaled|rotated|cropped|e\d+|thumbnail|medium|large)$/i', '', $clean );

		// Split into words.
		$words = preg_split( '/[-_]+/', $clean );
		$words = array_filter( $words, function ( $w ) {
			return strlen( $w ) > 2 && ! is_numeric( $w );
		} );

		if ( ! empty( $words ) ) {
			// Full phrase as query.
			$phrase = implode( ' ', $words );
			if ( strlen( $phrase ) > 3 ) {
				$queries[] = $phrase;
			}

			// First 2-3 words.
			if ( count( $words ) >= 2 ) {
				$queries[] = implode( ' ', array_slice( $words, 0, min( 3, count( $words ) ) ) );
			}

			// Add "image" or "photo" suffix variations.
			if ( count( $words ) <= 4 ) {
				$queries[] = $phrase . ' image';
				$queries[] = $phrase . ' photo';
			}
		}

		// Use post_title if it differs from slug.
		$title = strtolower( trim( $image->post_title ) );
		if ( ! empty( $title ) && $title !== $slug ) {
			$title_words = preg_split( '/[\s_-]+/', $title );
			$title_words = array_filter( $title_words, function ( $w ) {
				return strlen( $w ) > 2 && ! is_numeric( $w );
			} );

			if ( count( $title_words ) >= 2 ) {
				$queries[] = implode( ' ', array_slice( $title_words, 0, 4 ) );
			}
		}

		// Also check alt text from post meta.
		$alt = get_post_meta( $image->ID, '_wp_attachment_image_alt', true );
		if ( ! empty( $alt ) ) {
			$alt_lower = strtolower( trim( $alt ) );
			if ( strlen( $alt_lower ) > 3 && strlen( $alt_lower ) < 80 ) {
				$queries[] = $alt_lower;
			}
		}

		return array_unique( array_filter( $queries ) );
	}

	/**
	 * Generate mock optimization impact data.
	 *
	 * Creates realistic before/after comparison data stored in the
	 * infyp_gsc_optimization_impact option.
	 *
	 * @since 1.0.0
	 *
	 * @param string $site_url The site URL.
	 * @return void
	 */
	private function generate_mock_impact( $site_url ) {
		global $wpdb;

		// Get some pages for per-page impact.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time mock data generation.
		$pages = $wpdb->get_results(
			"SELECT ID, post_title
			 FROM {$wpdb->posts}
			 WHERE post_status = 'publish'
			   AND post_type IN ('post', 'page')
			 ORDER BY post_date DESC
			 LIMIT 50"
		);

		$per_page_impact = [];
		foreach ( $pages as $page ) {
			$url = get_permalink( $page->ID );
			if ( ! $url ) {
				continue;
			}

			$impr_before  = wp_rand( 200, 2000 );
			$impr_after   = (int) round( $impr_before * ( 1 + wp_rand( 10, 60 ) / 100 ) );
			$clicks_before = (int) round( $impr_before * wp_rand( 20, 80 ) / 1000 );
			$clicks_after  = (int) round( $impr_after * wp_rand( 30, 100 ) / 1000 );
			$pos_before   = round( wp_rand( 150, 400 ) / 10, 1 );
			$pos_after    = round( $pos_before - wp_rand( 10, 80 ) / 10, 1 );

			$per_page_impact[] = [
				'page_url'           => $url,
				'optimized_date'     => gmdate( 'Y-m-d', strtotime( '-' . wp_rand( 14, 60 ) . ' days' ) ),
				'impressions_before' => $impr_before,
				'impressions_after'  => $impr_after,
				'clicks_before'      => $clicks_before,
				'clicks_after'       => $clicks_after,
				'position_before'    => $pos_before,
				'position_after'     => max( 1, $pos_after ),
			];
		}

		// Aggregate totals.
		$total_impr_before  = array_sum( array_column( $per_page_impact, 'impressions_before' ) );
		$total_impr_after   = array_sum( array_column( $per_page_impact, 'impressions_after' ) );
		$total_clicks_before = array_sum( array_column( $per_page_impact, 'clicks_before' ) );
		$total_clicks_after  = array_sum( array_column( $per_page_impact, 'clicks_after' ) );

		$positions_before = array_column( $per_page_impact, 'position_before' );
		$positions_after  = array_column( $per_page_impact, 'position_after' );

		$impact = [
			'pages_tracked'            => count( $per_page_impact ),
			'total_impressions_before'  => $total_impr_before,
			'total_impressions_after'   => $total_impr_after,
			'total_clicks_before'       => $total_clicks_before,
			'total_clicks_after'        => $total_clicks_after,
			'avg_position_before'       => count( $positions_before ) > 0 ? round( array_sum( $positions_before ) / count( $positions_before ), 1 ) : 0,
			'avg_position_after'        => count( $positions_after ) > 0 ? round( array_sum( $positions_after ) / count( $positions_after ), 1 ) : 0,
			'per_page_impact'           => $per_page_impact,
		];

		update_option( 'infyp_gsc_optimization_impact', $impact, false );
	}
}
