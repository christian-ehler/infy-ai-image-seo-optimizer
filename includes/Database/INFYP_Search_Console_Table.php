<?php
/**
 * Search Console Database Table - Google Search Console Data Storage
 *
 * This class manages a custom WordPress database table that stores Google
 * Search Console performance data synced from the API. It provides structured
 * storage and querying capabilities for:
 *
 * - Daily performance metrics (clicks, impressions, CTR, position)
 * - Dimension breakdowns (page, query, device, country)
 * - Search type filtering (image, web, video, news)
 * - Date range comparisons for before/after analysis
 * - Top queries and pages by impressions
 *
 * Table Schema:
 * - id: Auto-increment primary key
 * - date: Date of the performance data
 * - search_type: Type of search (image, web, etc.)
 * - page_url: Full URL of the page
 * - query: Search query (nullable)
 * - device: Device type (nullable)
 * - country: Country code (nullable)
 * - clicks: Number of clicks
 * - impressions: Number of impressions
 * - ctr: Click-through rate
 * - position: Average position
 * - synced_at: Timestamp when data was synced
 *
 * @package   IPAIS\Database
 * @since 1.0.0
 * @author    InfyPress
 * @license   GPL-2.0-or-later
 */

namespace IPAIS\Database;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Search Console Database Table Management Class
 *
 * Handles creation, schema management, and query operations for the
 * Google Search Console data custom database table.
 *
 * Table Name: {$wpdb->prefix}infyp_gsc_data
 *
 * @package   IPAIS\Database
 * @since 1.0.0
 */
class INFYP_Search_Console_Table {

	/**
	 * Get the full table name with WordPress prefix
	 *
	 * @since 1.0.0
	 *
	 * @return string Full table name including prefix.
	 */
	public static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'infyp_gsc_data';
	}

	/**
	 * Creates the custom database table during plugin activation.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function on_activation() {
		global $wpdb;
		$table_name      = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			date date NOT NULL,
			search_type varchar(10) NOT NULL DEFAULT 'image',
			page_url varchar(2048) NOT NULL,
			query varchar(500) DEFAULT NULL,
			device varchar(10) DEFAULT NULL,
			country varchar(5) DEFAULT NULL,
			clicks int(11) NOT NULL DEFAULT 0,
			impressions int(11) NOT NULL DEFAULT 0,
			ctr decimal(6,4) NOT NULL DEFAULT 0.0000,
			position decimal(6,2) NOT NULL DEFAULT 0.00,
			synced_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY idx_unique_row (date, search_type, page_url(191), query(191), device, country),
			KEY idx_date_type (date, search_type),
			KEY idx_page_url (page_url(191)),
			KEY idx_synced_at (synced_at)
		) $charset_collate;";

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		}
		dbDelta( $sql );
	}

	/**
	 * Bulk insert or update rows of Search Console data
	 *
	 * Uses INSERT ... ON DUPLICATE KEY UPDATE for efficient upsert operations.
	 *
	 * @since 1.0.0
	 *
	 * @param array $rows Array of associative arrays with row data.
	 * @return int|false Number of rows affected, or false on failure.
	 */
	public function upsert_rows( $rows ) {
		if ( empty( $rows ) ) {
			return 0;
		}

		global $wpdb;
		$table_name = esc_sql( self::get_table_name() );

		$total_affected = 0;
		$batch_size     = 50;
		$batches        = array_chunk( $rows, $batch_size );

		foreach ( $batches as $batch ) {
			$values       = array();
			$placeholders = array();

			foreach ( $batch as $row ) {
				$placeholders[] = '(%s, %s, %s, %s, %s, %s, %d, %d, %f, %f, %s)';
				$values[]       = $row['date'];
				$values[]       = isset( $row['search_type'] ) ? $row['search_type'] : 'image';
				$values[]       = $row['page_url'];
				$values[]       = isset( $row['query'] ) ? $row['query'] : null;
				$values[]       = isset( $row['device'] ) ? $row['device'] : null;
				$values[]       = isset( $row['country'] ) ? $row['country'] : null;
				$values[]       = isset( $row['clicks'] ) ? (int) $row['clicks'] : 0;
				$values[]       = isset( $row['impressions'] ) ? (int) $row['impressions'] : 0;
				$values[]       = isset( $row['ctr'] ) ? (float) $row['ctr'] : 0.0;
				$values[]       = isset( $row['position'] ) ? (float) $row['position'] : 0.0;
				$values[]       = current_time( 'mysql' );
			}

			$placeholders_sql = implode( ', ', $placeholders );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$sql = $wpdb->prepare(
				"INSERT INTO `{$table_name}`
					(date, search_type, page_url, query, device, country, clicks, impressions, ctr, position, synced_at)
				VALUES {$placeholders_sql}
				ON DUPLICATE KEY UPDATE
					clicks = VALUES(clicks),
					impressions = VALUES(impressions),
					ctr = VALUES(ctr),
					position = VALUES(position),
					synced_at = VALUES(synced_at)",
				$values
			);

			$result = $wpdb->query( $sql );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

			if ( false === $result ) {
				return false;
			}

			$total_affected += $result;
		}

		return $total_affected;
	}

	/**
	 * Query performance data with flexible filters and dimension grouping
	 *
	 * @since 1.0.0
	 *
	 * @param array $args Query arguments.
	 * @return array|null Array of result objects, or null on failure.
	 */
	public function get_performance_data( $args = array() ) {
		global $wpdb;
		$table_name = esc_sql( self::get_table_name() );

		$defaults = array(
			'start_date'         => '',
			'end_date'           => '',
			'search_type'        => 'image',
			'dimensions'         => array(),
			'page_filter'        => '',
			'query_filter'       => '',
			'page_filter_exact'  => '',
			'query_filter_exact' => '',
			'combined_search'    => '',
			'exclude_null_query' => false,
			'limit'              => 100,
			'offset'             => 0,
			'order_by'           => 'impressions',
			'order'              => 'DESC',
		);

		$args = wp_parse_args( $args, $defaults );

		// Build SELECT columns based on dimensions
		$valid_dimensions = array( 'date', 'search_type', 'page_url', 'query', 'device', 'country' );
		$select_columns   = array();
		$group_by_columns = array();

		if ( ! empty( $args['dimensions'] ) ) {
			foreach ( $args['dimensions'] as $dimension ) {
				if ( in_array( $dimension, $valid_dimensions, true ) ) {
					$select_columns[]   = $dimension;
					$group_by_columns[] = $dimension;
				}
			}
		}

		if ( ! empty( $group_by_columns ) ) {
			$select_columns[] = 'SUM(clicks) AS clicks';
			$select_columns[] = 'SUM(impressions) AS impressions';
			$select_columns[] = 'AVG(ctr) AS ctr';
			$select_columns[] = 'AVG(position) AS position';
		} else {
			$select_columns = array( '*' );
		}

		$select_sql = implode( ', ', $select_columns );

		// Build WHERE clause
		$where_clauses = array();
		$where_values  = array();

		if ( ! empty( $args['start_date'] ) ) {
			$where_clauses[] = 'date >= %s';
			$where_values[]  = $args['start_date'];
		}

		if ( ! empty( $args['end_date'] ) ) {
			$where_clauses[] = 'date <= %s';
			$where_values[]  = $args['end_date'];
		}

		if ( ! empty( $args['search_type'] ) ) {
			$where_clauses[] = 'search_type = %s';
			$where_values[]  = $args['search_type'];
		}

		if ( ! empty( $args['page_filter'] ) ) {
			$where_clauses[] = 'page_url LIKE %s';
			$where_values[]  = '%' . $wpdb->esc_like( $args['page_filter'] ) . '%';
		}

		if ( ! empty( $args['query_filter'] ) ) {
			$where_clauses[] = 'query LIKE %s';
			$where_values[]  = '%' . $wpdb->esc_like( $args['query_filter'] ) . '%';
		}

		if ( ! empty( $args['page_filter_exact'] ) ) {
			$where_clauses[] = 'page_url = %s';
			$where_values[]  = $args['page_filter_exact'];
		}

		if ( ! empty( $args['query_filter_exact'] ) ) {
			$where_clauses[] = 'query = %s';
			$where_values[]  = $args['query_filter_exact'];
		}

		if ( ! empty( $args['combined_search'] ) ) {
			$like            = '%' . $wpdb->esc_like( $args['combined_search'] ) . '%';
			$where_clauses[] = '(query LIKE %s OR page_url LIKE %s)';
			$where_values[]  = $like;
			$where_values[]  = $like;
		}

		if ( ! empty( $args['exclude_null_query'] ) ) {
			$where_clauses[] = 'query IS NOT NULL';
		}

		$where_sql = ! empty( $where_clauses ) ? 'WHERE ' . implode( ' AND ', $where_clauses ) : '';
		$group_by_sql = ! empty( $group_by_columns ) ? 'GROUP BY ' . implode( ', ', $group_by_columns ) : '';

		// Validate ORDER BY column
		$valid_order_columns = array( 'date', 'clicks', 'impressions', 'ctr', 'position', 'page_url', 'query' );
		$order_by            = in_array( $args['order_by'], $valid_order_columns, true ) ? $args['order_by'] : 'impressions';
		$order               = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

		$limit  = absint( $args['limit'] );
		$offset = absint( $args['offset'] );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		if ( ! empty( $where_values ) ) {
			$sql = $wpdb->prepare(
				"SELECT {$select_sql} FROM `{$table_name}` {$where_sql} {$group_by_sql} ORDER BY {$order_by} {$order} LIMIT %d OFFSET %d",
				array_merge( $where_values, array( $limit, $offset ) )
			);
		} else {
			$sql = $wpdb->prepare(
				"SELECT {$select_sql} FROM `{$table_name}` {$group_by_sql} ORDER BY {$order_by} {$order} LIMIT %d OFFSET %d",
				$limit,
				$offset
			);
		}

		return $wpdb->get_results( $sql );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Get the count of rows matching filter criteria
	 *
	 * Uses the same filter logic as get_performance_data() but returns
	 * the total count for pagination/info display.
	 *
	 * @since 1.0.0
	 *
	 * @param array $args Query arguments (same as get_performance_data).
	 * @return int Total matching row count.
	 */
	public function get_filtered_count( $args = array() ) {
		global $wpdb;
		$table_name = esc_sql( self::get_table_name() );

		$defaults = array(
			'start_date'         => '',
			'end_date'           => '',
			'search_type'        => 'image',
			'dimensions'         => array(),
			'page_filter'        => '',
			'query_filter'       => '',
			'page_filter_exact'  => '',
			'query_filter_exact' => '',
			'combined_search'    => '',
			'exclude_null_query' => false,
		);

		$args = wp_parse_args( $args, $defaults );

		// Build WHERE clause (same logic as get_performance_data).
		$where_clauses = array();
		$where_values  = array();

		if ( ! empty( $args['start_date'] ) ) {
			$where_clauses[] = 'date >= %s';
			$where_values[]  = $args['start_date'];
		}

		if ( ! empty( $args['end_date'] ) ) {
			$where_clauses[] = 'date <= %s';
			$where_values[]  = $args['end_date'];
		}

		if ( ! empty( $args['search_type'] ) ) {
			$where_clauses[] = 'search_type = %s';
			$where_values[]  = $args['search_type'];
		}

		if ( ! empty( $args['page_filter'] ) ) {
			$where_clauses[] = 'page_url LIKE %s';
			$where_values[]  = '%' . $wpdb->esc_like( $args['page_filter'] ) . '%';
		}

		if ( ! empty( $args['query_filter'] ) ) {
			$where_clauses[] = 'query LIKE %s';
			$where_values[]  = '%' . $wpdb->esc_like( $args['query_filter'] ) . '%';
		}

		if ( ! empty( $args['page_filter_exact'] ) ) {
			$where_clauses[] = 'page_url = %s';
			$where_values[]  = $args['page_filter_exact'];
		}

		if ( ! empty( $args['query_filter_exact'] ) ) {
			$where_clauses[] = 'query = %s';
			$where_values[]  = $args['query_filter_exact'];
		}

		if ( ! empty( $args['combined_search'] ) ) {
			$like            = '%' . $wpdb->esc_like( $args['combined_search'] ) . '%';
			$where_clauses[] = '(query LIKE %s OR page_url LIKE %s)';
			$where_values[]  = $like;
			$where_values[]  = $like;
		}

		if ( ! empty( $args['exclude_null_query'] ) ) {
			$where_clauses[] = 'query IS NOT NULL';
		}

		$where_sql = ! empty( $where_clauses ) ? 'WHERE ' . implode( ' AND ', $where_clauses ) : '';

		// Count based on dimensions.
		$valid_dimensions    = array( 'date', 'search_type', 'page_url', 'query', 'device', 'country' );
		$count_dimensions    = array();

		if ( ! empty( $args['dimensions'] ) ) {
			foreach ( $args['dimensions'] as $dimension ) {
				if ( in_array( $dimension, $valid_dimensions, true ) ) {
					$count_dimensions[] = $dimension;
				}
			}
		}

		if ( count( $count_dimensions ) > 1 ) {
			$group_cols = implode( ', ', $count_dimensions );
			$count_sql  = "SELECT COUNT(*) FROM (SELECT 1 FROM `{$table_name}` {$where_sql} GROUP BY {$group_cols}) AS sub";
		} elseif ( count( $count_dimensions ) === 1 ) {
			$count_sql = "SELECT COUNT(DISTINCT {$count_dimensions[0]}) FROM `{$table_name}` {$where_sql}";
		} else {
			$count_sql = "SELECT COUNT(*) FROM `{$table_name}` {$where_sql}";
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		if ( ! empty( $where_values ) ) {
			$count_sql = $wpdb->prepare( $count_sql, $where_values );
		}

		return (int) $wpdb->get_var( $count_sql );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Get summary totals for a date range and search type
	 *
	 * @since 1.0.0
	 *
	 * @param string $start_date  Start date (Y-m-d).
	 * @param string $end_date    End date (Y-m-d).
	 * @param string $search_type Search type filter. Default 'image'.
	 * @return object|null Summary object.
	 */
	public function get_summary( $start_date, $end_date, $search_type = 'image' ) {
		global $wpdb;
		$table_name = esc_sql( self::get_table_name() );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					SUM(clicks) AS total_clicks,
					SUM(impressions) AS total_impressions,
					AVG(ctr) AS avg_ctr,
					AVG(position) AS avg_position,
					COUNT(DISTINCT page_url) AS unique_pages
				FROM `{$table_name}`
				WHERE date >= %s AND date <= %s AND search_type = %s",
				$start_date,
				$end_date,
				$search_type
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Get daily time series data for sparklines and performance charts
	 *
	 * @since 1.0.0
	 *
	 * @param string $start_date  Start date (Y-m-d).
	 * @param string $end_date    End date (Y-m-d).
	 * @param string $search_type Search type filter. Default 'image'.
	 * @return array|null Array of daily data objects with date, clicks, impressions, ctr, position.
	 */
	public function get_daily_time_series( $start_date, $end_date, $search_type = 'image' ) {
		global $wpdb;
		$table_name = esc_sql( self::get_table_name() );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					date,
					SUM(clicks) AS clicks,
					SUM(impressions) AS impressions,
					AVG(ctr) AS ctr,
					AVG(position) AS position
				FROM `{$table_name}`
				WHERE date >= %s AND date <= %s
					AND search_type = %s
				GROUP BY date
				ORDER BY date ASC",
				$start_date,
				$end_date,
				$search_type
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Get winning and losing queries by comparing two date periods
	 *
	 * Winners are queries where average position improved (decreased).
	 * Losers are queries where average position worsened (increased).
	 *
	 * @since 1.0.0
	 *
	 * @param string $current_start Start date of current period (Y-m-d).
	 * @param string $current_end   End date of current period (Y-m-d).
	 * @param string $prev_start    Start date of previous period (Y-m-d).
	 * @param string $prev_end      End date of previous period (Y-m-d).
	 * @param int    $limit         Maximum queries per group. Default 10.
	 * @return array Associative array with 'winners' and 'losers' arrays.
	 */
	public function get_winning_losing_queries( $current_start, $current_end, $prev_start, $prev_end, $limit = 10, $args = array() ) {
		global $wpdb;
		$table_name = esc_sql( self::get_table_name() );
		$limit      = absint( $limit );

		$defaults = array(
			'query_filter' => '',
			'order_by'     => 'position_change',
			'order'        => '',
			'type'         => 'both',
			'offset'       => 0,
		);

		$args = wp_parse_args( $args, $defaults );

		// Build optional query filter clause.
		$query_filter_sql = '';
		$cur_values       = array( $current_start, $current_end );
		$prev_values      = array( $prev_start, $prev_end );

		if ( ! empty( $args['query_filter'] ) ) {
			$like              = '%' . $wpdb->esc_like( $args['query_filter'] ) . '%';
			$query_filter_sql  = ' AND query LIKE %s';
			$cur_values[]      = $like;
			$prev_values[]     = $like;
		}

		$all_values = array_merge( $cur_values, $prev_values );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$sql = $wpdb->prepare(
			"SELECT
				cur.query,
				cur.clicks AS current_clicks,
				cur.impressions AS current_impressions,
				cur.position AS current_position,
				prev.clicks AS prev_clicks,
				prev.impressions AS prev_impressions,
				prev.position AS prev_position,
				(prev.position - cur.position) AS position_change,
				(cur.impressions - prev.impressions) AS impressions_change
			FROM (
				SELECT query, SUM(clicks) AS clicks, SUM(impressions) AS impressions, AVG(position) AS position
				FROM `{$table_name}`
				WHERE date >= %s AND date <= %s AND search_type = 'image' AND query IS NOT NULL{$query_filter_sql}
				GROUP BY query
			) cur
			INNER JOIN (
				SELECT query, SUM(clicks) AS clicks, SUM(impressions) AS impressions, AVG(position) AS position
				FROM `{$table_name}`
				WHERE date >= %s AND date <= %s AND search_type = 'image' AND query IS NOT NULL{$query_filter_sql}
				GROUP BY query
			) prev ON cur.query = prev.query",
			$all_values
		);

		$rows = $wpdb->get_results( $sql );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( ! $rows ) {
			if ( 'both' === $args['type'] ) {
				return array( 'winners' => array(), 'losers' => array() );
			}
			return array( 'rows' => array(), 'total' => 0 );
		}

		$winners = array();
		$losers  = array();

		foreach ( $rows as $row ) {
			$change = (float) $row->position_change;
			if ( $change > 0.5 ) {
				$winners[] = $row;
			} elseif ( $change < -0.5 ) {
				$losers[] = $row;
			}
		}

		// Original call mode: return both groups with default sorting.
		if ( 'both' === $args['type'] ) {
			usort( $winners, function ( $a, $b ) {
				return (float) $b->position_change <=> (float) $a->position_change;
			} );
			usort( $losers, function ( $a, $b ) {
				return (float) $a->position_change <=> (float) $b->position_change;
			} );
			return array(
				'winners' => array_slice( $winners, 0, $limit ),
				'losers'  => array_slice( $losers, 0, $limit ),
			);
		}

		// Single-type mode for AJAX table requests.
		$target = 'winners' === $args['type'] ? $winners : $losers;

		// Custom sort.
		$valid_wl_columns = array( 'position_change', 'current_position', 'current_impressions', 'current_clicks', 'impressions_change' );
		$order_column     = in_array( $args['order_by'], $valid_wl_columns, true ) ? $args['order_by'] : 'position_change';
		$order_dir        = strtoupper( $args['order'] );

		if ( ! in_array( $order_dir, array( 'ASC', 'DESC' ), true ) ) {
			$order_dir = 'winners' === $args['type'] ? 'DESC' : 'ASC';
		}

		usort( $target, function ( $a, $b ) use ( $order_column, $order_dir ) {
			$va  = (float) $a->$order_column;
			$vb  = (float) $b->$order_column;
			$cmp = $va <=> $vb;
			return 'ASC' === $order_dir ? $cmp : -$cmp;
		} );

		$total  = count( $target );
		$offset = absint( $args['offset'] );
		$target = array_slice( $target, $offset, $limit );

		return array(
			'rows'  => $target,
			'total' => $total,
		);
	}

	/**
	 * Delete data older than a specified number of months
	 *
	 * @since 1.0.0
	 *
	 * @param int $months Number of months of data to retain. Default 16.
	 * @return int|false Number of rows deleted, or false on failure.
	 */
	public function cleanup_old_data( $months = 16 ) {
		global $wpdb;
		$table_name = esc_sql( self::get_table_name() );
		$months     = absint( $months );

		if ( $months < 1 ) {
			$months = 16;
		}

		$cutoff_date = gmdate( 'Y-m-d', strtotime( "-{$months} months" ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM `{$table_name}` WHERE date < %s",
				$cutoff_date
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Drops the custom database table during plugin uninstall
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function drop_table() {
		global $wpdb;
		$table_name = esc_sql( self::get_table_name() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS `{$table_name}`" );
	}

	/**
	 * Get the total number of rows in the table
	 *
	 * @since 1.0.0
	 *
	 * @return int Total row count.
	 */
	public function get_row_count() {
		global $wpdb;
		$table_name = esc_sql( self::get_table_name() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table_name}`" );
	}

}
