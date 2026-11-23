<?php
/**
 * Redirect Database Table — Stores 301 redirect mappings for renamed images.
 *
 * When an image file is renamed, the old URL(s) are stored here along with the
 * new URL(s) so the redirect engine can serve 301s for any external references
 * (search engines, social shares, CDNs) that still point to the old path.
 *
 * Table Schema:
 * - id: Auto-increment primary key
 * - image_id: Attachment ID of the renamed image
 * - old_url: The previous URL (unique — no duplicate redirects)
 * - new_url: The current URL to redirect to
 * - status: active | disabled | expired
 * - hit_count: Number of times this redirect has been served
 * - last_hit_at: Timestamp of the most recent redirect hit
 * - created_at: When the redirect was created
 * - expires_at: When the redirect should expire (NULL = never)
 * - created_by: WordPress user ID who triggered the rename
 * - source: How the rename was initiated (manual | ai)
 * - pagebuilder_results: JSON-encoded per-adapter update results
 *
 * @package   IPAIS\Database
 * @since     1.1.0
 * @author    InfyPress
 * @license   GPL-2.0-or-later
 */

namespace IPAIS\Database;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Redirect Table Database Management Class
 *
 * Handles table creation, schema management, and CRUD operations for the
 * redirect mapping table. Provides logging for all image URL redirects.
 *
 * Table Name: {$wpdb->prefix}infyp_redirects
 *
 * @package   IPAIS\Database
 * @since     1.1.0
 */
class INFYP_Redirect_Table {

	/**
	 * Get the full table name with WordPress prefix.
	 *
	 * @since 1.1.0
	 *
	 * @return string Full table name.
	 */
	public static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'infyp_redirects';
	}

	/**
	 * Creates the database table during plugin activation.
	 *
	 * @since 1.1.0
	 */
	public static function on_activation() {
		global $wpdb;
		$table_name      = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			image_id bigint(20) UNSIGNED NOT NULL,
			old_url varchar(2048) NOT NULL,
			new_url varchar(2048) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			hit_count bigint(20) UNSIGNED NOT NULL DEFAULT 0,
			last_hit_at datetime NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			expires_at datetime NULL,
			created_by bigint(20) UNSIGNED NOT NULL DEFAULT 0,
			source varchar(20) NOT NULL DEFAULT 'manual',
			pagebuilder_results text NULL,
			PRIMARY KEY  (id),
			KEY idx_old_url (old_url(191)),
			KEY idx_image_id (image_id),
			KEY idx_status (status),
			KEY idx_expires_at (expires_at)
		) $charset_collate;";

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		dbDelta( $sql );
	}

	/**
	 * Insert a redirect mapping.
	 *
	 * If a redirect already exists for the same old_url, update it to point
	 * to the new target (handles re-renames).
	 *
	 * @since 1.1.0
	 *
	 * @param array $data {
	 *     @type int    $image_id            Attachment ID.
	 *     @type string $old_url             Previous URL.
	 *     @type string $new_url             New URL.
	 *     @type string $source              'manual' or 'ai'.
	 *     @type int    $created_by          User ID.
	 *     @type string $expires_at          Expiry datetime or null.
	 *     @type string $pagebuilder_results JSON string.
	 * }
	 * @return int|false Inserted row ID or false on failure.
	 */
	public static function insert( $data ) {
		global $wpdb;
		$table_name = self::get_table_name();

		$defaults = [
			'image_id'            => 0,
			'old_url'             => '',
			'new_url'             => '',
			'status'              => 'active',
			'hit_count'           => 0,
			'last_hit_at'         => null,
			'created_at'          => current_datetime()->format( 'Y-m-d H:i:s' ),
			'expires_at'          => null,
			'created_by'          => get_current_user_id(),
			'source'              => 'manual',
			'pagebuilder_results' => null,
		];

		$data = wp_parse_args( $data, $defaults );

		// Check for existing redirect with same old_url — update target instead.
		// URL paths are case-sensitive but the column collation is not: compare
		// bytes, so two files differing only by case keep their own rows.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table_name is safely derived from $wpdb->prefix
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM `{$table_name}` WHERE old_url = %s AND BINARY old_url = %s LIMIT 1",
				$data['old_url'],
				$data['old_url']
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$table_name,
				[
					'new_url'             => $data['new_url'],
					'image_id'            => $data['image_id'],
					'status'              => 'active',
					'source'              => $data['source'],
					'expires_at'          => $data['expires_at'],
					'pagebuilder_results' => $data['pagebuilder_results'],
				],
				[ 'id' => $existing ],
				[ '%s', '%d', '%s', '%s', '%s', '%s' ],
				[ '%d' ]
			);
			self::invalidate_stats_cache();
			return (int) $existing;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->insert(
			$table_name,
			$data,
			[
				'%d', // image_id
				'%s', // old_url
				'%s', // new_url
				'%s', // status
				'%d', // hit_count
				'%s', // last_hit_at
				'%s', // created_at
				'%s', // expires_at
				'%d', // created_by
				'%s', // source
				'%s', // pagebuilder_results
			]
		);

		self::invalidate_stats_cache();

		return $result ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Look up a redirect by old URL.
	 *
	 * Only returns active, non-expired redirects for serving 301s.
	 * Checks expires_at in real time so expired redirects aren't served
	 * between cron runs.
	 *
	 * The match ignores case (column collation), so rows stored by older
	 * versions with a lower-cased old URL keep working; a row whose old URL
	 * matches byte for byte wins over one that matches only without case.
	 *
	 * @since 1.1.0
	 *
	 * @param string $old_url The old URL to look up.
	 * @return object|null Row object with new_url, or null if not found.
	 */
	public static function get_by_old_url( $old_url ) {
		global $wpdb;
		$table_name = self::get_table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table_name is safely derived from $wpdb->prefix
		$result = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, new_url FROM `{$table_name}` WHERE old_url = %s AND status = 'active' AND ( expires_at IS NULL OR expires_at >= %s ) ORDER BY ( BINARY old_url = %s ) DESC, id DESC LIMIT 1",
				$old_url,
				current_datetime()->format( 'Y-m-d H:i:s' ),
				$old_url
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return $result;
	}

	/**
	 * Increment hit count for a redirect.
	 *
	 * Uses a lightweight UPDATE for performance (called during 301 serving).
	 *
	 * @since 1.1.0
	 *
	 * @param int $redirect_id Row ID.
	 */
	public static function record_hit( $redirect_id ) {
		global $wpdb;
		$table_name = self::get_table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table_name is safely derived from $wpdb->prefix
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$table_name}` SET hit_count = hit_count + 1, last_hit_at = %s WHERE id = %d",
				current_datetime()->format( 'Y-m-d H:i:s' ),
				$redirect_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Update redirect chains when an image is re-renamed.
	 *
	 * If image was renamed A→B and now B→C, update the A→B redirect to A→C.
	 *
	 * @since 1.1.0
	 *
	 * @param string $old_new_url The URL that was previously the "new" target.
	 * @param string $final_url   The actual new URL.
	 * @return int Number of rows updated.
	 */
	public static function update_chains( $old_new_url, $final_url ) {
		global $wpdb;
		$table_name = self::get_table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table_name is safely derived from $wpdb->prefix
		$result = (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$table_name}` SET new_url = %s WHERE new_url = %s AND BINARY new_url = %s AND status = 'active'",
				$final_url,
				$old_new_url,
				$old_new_url
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return $result;
	}

	/**
	 * Update status for a redirect.
	 *
	 * @since 1.1.0
	 *
	 * @param int    $redirect_id Row ID.
	 * @param string $status      New status (active|disabled|expired).
	 * @return bool True on success.
	 */
	public static function update_status( $redirect_id, $status ) {
		global $wpdb;
		$table_name = self::get_table_name();

		$allowed = [ 'active', 'disabled', 'expired' ];
		if ( ! in_array( $status, $allowed, true ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = (bool) $wpdb->update(
			$table_name,
			[ 'status' => $status ],
			[ 'id' => $redirect_id ],
			[ '%s' ],
			[ '%d' ]
		);

		self::invalidate_stats_cache();

		return $result;
	}

	/**
	 * Bulk update status for multiple redirects.
	 *
	 * @since 1.1.0
	 *
	 * @param array  $ids    Array of redirect IDs.
	 * @param string $status New status.
	 * @return int Number of rows updated.
	 */
	public static function bulk_update_status( $ids, $status ) {
		global $wpdb;
		$table_name = self::get_table_name();

		$allowed = [ 'active', 'disabled', 'expired' ];
		if ( ! in_array( $status, $allowed, true ) ) {
			return 0;
		}

		$ids = array_map( 'absint', $ids );
		if ( empty( $ids ) ) {
			return 0;
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table_name safely derived from $wpdb->prefix; $placeholders is array_fill of %d format strings
		$result = (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$table_name}` SET status = %s WHERE id IN ({$placeholders})",
				array_merge( [ $status ], $ids )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter

		self::invalidate_stats_cache();

		return $result;
	}

	/**
	 * Delete a redirect by ID.
	 *
	 * @since 1.1.0
	 *
	 * @param int $redirect_id Row ID.
	 * @return bool True on success.
	 */
	public static function delete( $redirect_id ) {
		global $wpdb;
		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = (bool) $wpdb->delete( $table_name, [ 'id' => absint( $redirect_id ) ], [ '%d' ] );

		self::invalidate_stats_cache();

		return $result;
	}

	/**
	 * Bulk delete redirects by IDs.
	 *
	 * @since 1.1.0
	 *
	 * @param array $ids Array of redirect IDs.
	 * @return int Number of rows deleted.
	 */
	public static function bulk_delete( $ids ) {
		global $wpdb;
		$table_name = self::get_table_name();

		$ids = array_map( 'absint', $ids );
		if ( empty( $ids ) ) {
			return 0;
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table_name safely derived from $wpdb->prefix; $placeholders is array_fill of %d format strings
		$result = (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM `{$table_name}` WHERE id IN ({$placeholders})",
				$ids
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter

		self::invalidate_stats_cache();

		return $result;
	}

	/**
	 * Expire redirects past their expiry date.
	 *
	 * Called by the daily cron job.
	 *
	 * @since 1.1.0
	 *
	 * @return int Number of redirects expired.
	 */
	public static function expire_old_redirects() {
		global $wpdb;
		$table_name = self::get_table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table_name is safely derived from $wpdb->prefix
		$result = (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$table_name}` SET status = 'expired' WHERE status = 'active' AND expires_at IS NOT NULL AND expires_at < %s",
				current_datetime()->format( 'Y-m-d H:i:s' )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( $result > 0 ) {
			self::invalidate_stats_cache();
		}

		return $result;
	}

	/**
	 * Get summary statistics for the redirects page.
	 *
	 * Results are cached in a short-lived transient (5 minutes) to avoid
	 * a full-table aggregate query on every page load. The cache is
	 * invalidated by insert(), bulk_update_status(), bulk_delete(),
	 * delete(), delete_by_image(), and expire_old_redirects().
	 *
	 * @since 1.1.0
	 *
	 * @return array {
	 *     @type int $total_active    Active redirect count.
	 *     @type int $total_disabled  Disabled redirect count.
	 *     @type int $total_expired   Expired redirect count.
	 *     @type int $total_hits      Sum of all hits.
	 *     @type int $zero_hit_count  Redirects with 0 hits.
	 * }
	 */
	public static function get_summary_stats() {
		$cached = get_transient( 'infyp_redirect_stats' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		$table_name = self::get_table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Aggregate query with no user input; table name safely derived from $wpdb->prefix.
		$row = $wpdb->get_row(
			"SELECT
				SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS total_active,
				SUM(CASE WHEN status = 'disabled' THEN 1 ELSE 0 END) AS total_disabled,
				SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) AS total_expired,
				SUM(hit_count) AS total_hits,
				SUM(CASE WHEN hit_count = 0 THEN 1 ELSE 0 END) AS zero_hit_count
			FROM `{$table_name}`",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$stats = [
			'total_active'   => (int) ( $row['total_active'] ?? 0 ),
			'total_disabled' => (int) ( $row['total_disabled'] ?? 0 ),
			'total_expired'  => (int) ( $row['total_expired'] ?? 0 ),
			'total_hits'     => (int) ( $row['total_hits'] ?? 0 ),
			'zero_hit_count' => (int) ( $row['zero_hit_count'] ?? 0 ),
		];

		set_transient( 'infyp_redirect_stats', $stats, 5 * MINUTE_IN_SECONDS );

		return $stats;
	}

	/**
	 * Invalidate the summary stats transient cache.
	 *
	 * Called internally after any write operation that changes redirect
	 * counts or statuses so the next get_summary_stats() call is fresh.
	 *
	 * @since 1.1.0
	 */
	private static function invalidate_stats_cache() {
		delete_transient( 'infyp_redirect_stats' );
	}

	/**
	 * Delete redirects where old_url equals new_url.
	 *
	 * Self-referencing redirects can be created by chain resolution during
	 * circular renames (A→B→A). They cause infinite redirect loops in browsers.
	 *
	 * @since 1.1.1
	 *
	 * @return int Number of rows deleted.
	 */
	public static function delete_self_referencing() {
		global $wpdb;
		$table_name = self::get_table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- No user input; table name safely derived from $wpdb->prefix. Column comparison has no parameterisable values.
		$result = (int) $wpdb->query(
			"DELETE FROM `{$table_name}` WHERE BINARY old_url = BINARY new_url"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return $result;
	}

	/**
	 * Delete all redirects for a given image.
	 *
	 * Useful when an image is permanently deleted from the media library.
	 *
	 * @since 1.1.0
	 *
	 * @param int $image_id Attachment ID.
	 * @return int Number of rows deleted.
	 */
	public static function delete_by_image( $image_id ) {
		global $wpdb;
		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = (int) $wpdb->delete( $table_name, [ 'image_id' => absint( $image_id ) ], [ '%d' ] );

		if ( $result > 0 ) {
			self::invalidate_stats_cache();
		}

		return $result;
	}
}
