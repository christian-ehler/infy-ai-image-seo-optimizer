<?php
/**
 * Redirect Query — read-side data access for the redirects custom table.
 *
 * Encapsulates all SQL for {prefix}infyp_redirects so that
 * INFYP_Redirect_List_Table never touches $wpdb directly.
 *
 * @package   IPAIS\Tables
 * @since     1.1.0
 * @author    InfyPress
 * @license   GPL-2.0-or-later
 */

namespace IPAIS\Tables;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class INFYP_Redirect_Query
 *
 * Read-side data access layer for the {prefix}infyp_redirects table.
 * Encapsulates all SELECT queries so INFYP_Redirect_List_Table never
 * touches $wpdb directly. Supports filtering, searching, sorting, and
 * pagination with a whitelisted ORDER BY clause.
 *
 * @since 1.1.0
 */
class INFYP_Redirect_Query {

	/**
	 * Columns allowed in ORDER BY (whitelist).
	 *
	 * @var array
	 */
	private const ALLOWED_ORDERBY = [
		'id',
		'created_at',
		'hit_count',
		'last_hit_at',
		'status',
		'source',
		'image_id',
	];

	/**
	 * Get the table name.
	 *
	 * @return string
	 */
	private function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'infyp_redirects';
	}

	/**
	 * Build a WHERE clause from filters.
	 *
	 * @param array $filters {
	 *     @type string $status  Filter by status.
	 *     @type string $source  Filter by source (manual|ai).
	 *     @type string $search  Search old_url or new_url.
	 * }
	 * @return array [ 'sql' => string, 'values' => array ]
	 */
	private function build_where( array $filters ) {
		global $wpdb;

		$conditions = [];
		$values     = [];

		if ( ! empty( $filters['status'] ) ) {
			$conditions[] = 'status = %s';
			$values[]     = $filters['status'];
		}

		if ( ! empty( $filters['source'] ) ) {
			$conditions[] = 'source = %s';
			$values[]     = $filters['source'];
		}

		if ( ! empty( $filters['search'] ) ) {
			$like         = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
			$conditions[] = '(old_url LIKE %s OR new_url LIKE %s)';
			$values[]     = $like;
			$values[]     = $like;
		}

		$sql = '';
		if ( ! empty( $conditions ) ) {
			$sql = 'WHERE ' . implode( ' AND ', $conditions );
		}

		return [ 'sql' => $sql, 'values' => $values ];
	}

	/**
	 * Get total count of redirect entries (with optional filters).
	 *
	 * @since 1.1.0
	 *
	 * @param array $filters Optional filters (status, source, search).
	 * @return int
	 */
	public function get_total_count( array $filters = [] ): int {
		global $wpdb;
		$table = $this->table_name();
		$where = $this->build_where( $filters );

		if ( ! empty( $where['values'] ) ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table safely derived from $wpdb->prefix; $where['sql'] is a pre-validated SQL fragment
			$count = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(id) FROM `{$table}` {$where['sql']}", $where['values'] )
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
			return $count;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is safely derived from $wpdb->prefix
		$count = (int) $wpdb->get_var( "SELECT COUNT(id) FROM `{$table}`" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return $count;
	}

	/**
	 * Get redirect entries with sorting, pagination, and filtering.
	 *
	 * @since 1.1.0
	 *
	 * @param array $args {
	 *     @type string $orderby  Column to sort by. Default 'created_at'.
	 *     @type string $order    ASC or DESC. Default 'DESC'.
	 *     @type int    $per_page Rows per page. Default 20.
	 *     @type int    $offset   Row offset. Default 0.
	 *     @type string $status   Filter by status.
	 *     @type string $source   Filter by source.
	 *     @type string $search   Search term.
	 * }
	 * @return array Array of associative arrays.
	 */
	public function get_entries( array $args = [] ): array {
		global $wpdb;
		$table = $this->table_name();

		$defaults = [
			'orderby'  => 'created_at',
			'order'    => 'DESC',
			'per_page' => 20,
			'offset'   => 0,
			'status'   => '',
			'source'   => '',
			'search'   => '',
		];
		$args = wp_parse_args( $args, $defaults );

		// Whitelist validation.
		$orderby = in_array( $args['orderby'], self::ALLOWED_ORDERBY, true ) ? $args['orderby'] : 'created_at';
		$order   = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

		$orderby_sql = sanitize_sql_orderby( "{$orderby} {$order}" );
		if ( ! $orderby_sql ) {
			$orderby_sql = sanitize_sql_orderby( 'created_at DESC' );
		}

		$where = $this->build_where( [
			'status' => $args['status'],
			'source' => $args['source'],
			'search' => $args['search'],
		] );

		$query_values = $where['values'];
		$query_values[] = absint( $args['per_page'] );
		$query_values[] = absint( $args['offset'] );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table safely derived from $wpdb->prefix; $where['sql'] and $orderby_sql are pre-validated SQL fragments
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` {$where['sql']} ORDER BY {$orderby_sql} LIMIT %d OFFSET %d",
				...$query_values
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return $results;
	}
}
