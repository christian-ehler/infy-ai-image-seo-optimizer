<?php
/**
 * Changelog Query — read-side data access for the changelog custom table.
 *
 * Encapsulates all SQL for the {prefix}infyp_changelog table so that
 * INFYP_Changelog_List_Table never touches $wpdb directly. All whitelist
 * validation, sanitize_sql_orderby(), and $wpdb->prepare() calls live here.
 *
 * @package   IPAIS\Tables
 * @since     1.1.0
 * @author    InfyPress
 * @license   GPL-2.0-or-later
 *
 * @see       IPAIS\Database\INFYP_Changelog       Write-side (add_entry, table creation)
 * @see       IPAIS\Tables\INFYP_Changelog_List_Table  Consumer of this class
 */

namespace IPAIS\Tables;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class INFYP_Changelog_Query {

	/**
	 * Columns selected in data queries.
	 *
	 * @var string
	 */
	private const COLUMNS = 'id, timestamp, user_id, image_id, field_changed, old_value, new_value, ai_model, context';

	/**
	 * Columns allowed in ORDER BY (whitelist).
	 *
	 * @var array
	 */
	private const ALLOWED_ORDERBY = [
		'id',
		'timestamp',
		'user_id',
		'image_id',
		'field_changed',
		'old_value',
		'new_value',
		'ai_model',
		'context',
	];

	/**
	 * Get the total number of changelog entries.
	 *
	 * @since 1.1.0
	 *
	 * @return int
	 */
	public function get_total_count(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, count only
		return (int) $wpdb->get_var( "SELECT COUNT(id) FROM `{$wpdb->prefix}infyp_changelog`" );
	}

	/**
	 * Get changelog entries with sorting and pagination.
	 *
	 * @since 1.1.0
	 *
	 * @param array $args {
	 *     Optional. Query arguments.
	 *
	 *     @type string $orderby  Column to sort by. Default 'timestamp'.
	 *     @type string $order    Sort direction: 'ASC' or 'DESC'. Default 'DESC'.
	 *     @type int    $per_page Rows per page. Default 20.
	 *     @type int    $offset   Row offset. Default 0.
	 * }
	 * @return array Array of associative arrays (ARRAY_A rows).
	 */
	public function get_entries( array $args = [] ): array {
		global $wpdb;

		$defaults = [
			'orderby'  => 'timestamp',
			'order'    => 'DESC',
			'per_page' => 20,
			'offset'   => 0,
		];
		$args = wp_parse_args( $args, $defaults );

		// Whitelist validation.
		$orderby = in_array( $args['orderby'], self::ALLOWED_ORDERBY, true ) ? $args['orderby'] : 'timestamp';
		$order   = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

		// sanitize_sql_orderby() is recognized by Plugin Check as a safe escaping function.
		$orderby_sql = sanitize_sql_orderby( "{$orderby} {$order}" );
		if ( ! $orderby_sql ) {
			$orderby_sql = sanitize_sql_orderby( 'timestamp DESC' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $orderby_sql whitelist-validated + sanitize_sql_orderby(); table name from $wpdb->prefix
			$wpdb->prepare( "SELECT id, timestamp, user_id, image_id, field_changed, old_value, new_value, ai_model, context FROM `{$wpdb->prefix}infyp_changelog` ORDER BY {$orderby_sql} LIMIT %d OFFSET %d", absint( $args['per_page'] ), absint( $args['offset'] ) ),
			ARRAY_A
		);
	}
}
