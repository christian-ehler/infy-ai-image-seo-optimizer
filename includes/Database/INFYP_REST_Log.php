<?php
/**
 * REST Activity Log - Persistence for REST API optimization requests
 *
 * Records every optimization request handled by the infyp/v1 REST routes
 * (full and single-field, successes and failures, dry-run or persisted)
 * so administrators can audit programmatic usage on the REST API admin
 * page. Field-level change history remains in the Changelog; this log
 * answers "who called the API, for which image, and what happened".
 *
 * @package   IPAIS\Database
 * @since 1.0.6
 * @author    InfyPress
 * @license   GPL-2.0-or-later
 */

namespace IPAIS\Database;

// Exit if accessed directly (placed after namespace per PHP requirement).
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * REST Activity Log Class
 *
 * @package IPAIS\Database
 * @since 1.0.6
 */
class INFYP_REST_Log {

    /**
     * Schema version. Bump to trigger dbDelta.
     *
     * @since 1.0.6
     * @var   string
     */
    const SCHEMA_VERSION = '1';

    /**
     * Option storing the installed schema version.
     *
     * @since 1.0.6
     * @var   string
     */
    const SCHEMA_OPTION = 'infyp_rest_log_schema_version';

    /**
     * Default retention in days (filterable via infyp_rest_log_retention_days).
     *
     * @since 1.0.6
     * @var   int
     */
    const RETENTION_DAYS = 90;

    /**
     * Singleton instance.
     *
     * @since 1.0.6
     * @var   INFYP_REST_Log|null
     */
    private static $instance = null;

    /**
     * Get the singleton instance.
     *
     * @since 1.0.6
     *
     * @return INFYP_REST_Log
     */
    public static function get_instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Table name (prefixed).
     *
     * @since 1.0.6
     *
     * @return string
     */
    public function table() {
        global $wpdb;
        return $wpdb->prefix . 'infyp_rest_log';
    }

    /**
     * Create or upgrade the log table when the schema version changed.
     *
     * @since 1.0.6
     *
     * @return void
     */
    public function maybe_install() {
        if ( get_option( self::SCHEMA_OPTION ) === self::SCHEMA_VERSION ) {
            return;
        }

        global $wpdb;

        if ( ! function_exists( 'dbDelta' ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        $charset_collate = $wpdb->get_charset_collate();

        dbDelta(
            "CREATE TABLE {$this->table()} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                created_at DATETIME NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                route VARCHAR(60) NOT NULL,
                image_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                dry_run TINYINT(1) NOT NULL DEFAULT 0,
                success TINYINT(1) NOT NULL DEFAULT 1,
                model VARCHAR(100) NULL,
                error TEXT NULL,
                PRIMARY KEY  (id),
                KEY created_at (created_at),
                KEY user_id (user_id),
                KEY image_id (image_id),
                KEY route (route)
            ) {$charset_collate};"
        );

        update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION );
    }

    /**
     * Record one REST optimization request.
     *
     * @since 1.0.6
     *
     * @param array $entry {
     *     Log entry.
     *
     *     @type string $route    Short route label ('optimize' or 'field:{type}').
     *     @type int    $image_id Attachment ID.
     *     @type bool   $dry_run  Whether the request was a dry run.
     *     @type bool   $success  Whether the request succeeded.
     *     @type string $model    AI model used (successes). Default ''.
     *     @type string $error    Error message (failures). Default ''.
     * }
     * @return void
     */
    public function record( array $entry ) {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom log table; wpdb::insert() prepares internally.
        $wpdb->insert(
            $this->table(),
            [
                'created_at' => current_time( 'mysql' ),
                'user_id'    => get_current_user_id(),
                'route'      => substr( sanitize_text_field( $entry['route'] ?? '' ), 0, 60 ),
                'image_id'   => absint( $entry['image_id'] ?? 0 ),
                'dry_run'    => empty( $entry['dry_run'] ) ? 0 : 1,
                'success'    => empty( $entry['success'] ) ? 0 : 1,
                'model'      => substr( sanitize_text_field( $entry['model'] ?? '' ), 0, 100 ),
                'error'      => (string) ( $entry['error'] ?? '' ),
            ],
            [ '%s', '%d', '%s', '%d', '%d', '%d', '%s', '%s' ]
        );

        // Probabilistic pruning: ~1% of inserts sweep out expired rows, so no
        // extra cron is needed and the table stays bounded.
        if ( 1 === wp_rand( 1, 100 ) ) {
            $this->prune();
        }
    }

    /**
     * Delete entries older than the retention window.
     *
     * @since 1.0.6
     *
     * @return void
     */
    public function prune() {
        global $wpdb;

        /**
         * Filter the REST log retention in days.
         *
         * @since 1.0.6
         *
         * @param int $days Retention window. Default 90.
         */
        $days = max( 1, (int) apply_filters( 'infyp_rest_log_retention_days', self::RETENTION_DAYS ) );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table name from trusted helper; retention sweep.
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$this->table()} WHERE created_at < DATE_SUB( %s, INTERVAL %d DAY )",
                current_time( 'mysql' ),
                $days
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /**
     * Query log entries with filters and pagination.
     *
     * @since 1.0.6
     *
     * @param array $args {
     *     Optional filters.
     *
     *     @type int    $user_id  Restrict to one user. Default 0 (all).
     *     @type string $route    Restrict to one route label. Default '' (all).
     *     @type int    $image_id Restrict to one attachment. Default 0 (all).
     *     @type string $result   'success', 'failed', or '' (all). Default ''.
     *     @type int    $per_page Rows per page, 1-100. Default 25.
     *     @type int    $page     1-based page. Default 1.
     *     @type string $orderby  Sort column: 'id', 'created_at', 'user_id', 'route',
     *                            'image_id', or 'success'. Default 'id'.
     *     @type string $order    'asc' or 'desc'. Default 'desc'.
     * }
     * @return array { @type object[] $items Rows. @type int $total Matching rows. }
     */
    public function query( array $args = [] ) {
        global $wpdb;

        $user_id  = absint( $args['user_id'] ?? 0 );
        $route    = sanitize_text_field( $args['route'] ?? '' );
        $image_id = absint( $args['image_id'] ?? 0 );
        $result   = in_array( $args['result'] ?? '', [ 'success', 'failed' ], true ) ? $args['result'] : '';
        $per_page = min( 100, max( 1, absint( $args['per_page'] ?? 25 ) ) );
        $page     = max( 1, absint( $args['page'] ?? 1 ) );

        // Whitelist the sort column; it is interpolated, never bound.
        $allowed_orderby = [ 'id', 'created_at', 'user_id', 'route', 'image_id', 'success' ];
        $orderby         = in_array( $args['orderby'] ?? '', $allowed_orderby, true ) ? $args['orderby'] : 'id';
        $order           = 'asc' === strtolower( (string) ( $args['order'] ?? 'desc' ) ) ? 'ASC' : 'DESC';

        $where  = ' WHERE 1=1';
        $params = [];

        if ( $user_id ) {
            $where   .= ' AND user_id = %d';
            $params[] = $user_id;
        }
        if ( '' !== $route ) {
            $where   .= ' AND route = %s';
            $params[] = $route;
        }
        if ( $image_id ) {
            $where   .= ' AND image_id = %d';
            $params[] = $image_id;
        }
        if ( 'success' === $result ) {
            $where .= ' AND success = 1';
        } elseif ( 'failed' === $result ) {
            $where .= ' AND success = 0';
        }

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table from trusted helper; WHERE built from whitelisted clauses and prepared when params exist; ORDER BY column and direction come from a whitelist; admin audit view must be live.
        $count_sql = "SELECT COUNT(*) FROM {$this->table()}" . $where;
        $total     = (int) $wpdb->get_var( $params ? $wpdb->prepare( $count_sql, $params ) : $count_sql );

        $list_sql      = "SELECT * FROM {$this->table()}" . $where . " ORDER BY {$orderby} {$order}, id DESC LIMIT %d OFFSET %d";
        $list_params   = $params;
        $list_params[] = $per_page;
        $list_params[] = ( $page - 1 ) * $per_page;
        $items         = (array) $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ) );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        return [
            'items' => $items,
            'total' => $total,
        ];
    }

    /**
     * Summary counters for the admin page header.
     *
     * @since 1.0.6
     *
     * @return array Keys: total, last_24h, last_7d, failed_7d, dry_run_7d.
     */
    public function get_stats() {
        global $wpdb;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table from trusted helper; live counters for an admin screen.
        $now = current_time( 'mysql' );

        return [
            'total'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table()}" ),
            'last_24h'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table()} WHERE created_at >= DATE_SUB( %s, INTERVAL 1 DAY )", $now ) ),
            'last_7d'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table()} WHERE created_at >= DATE_SUB( %s, INTERVAL 7 DAY )", $now ) ),
            'failed_7d' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table()} WHERE success = 0 AND created_at >= DATE_SUB( %s, INTERVAL 7 DAY )", $now ) ),
            'dry_run_7d' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table()} WHERE dry_run = 1 AND created_at >= DATE_SUB( %s, INTERVAL 7 DAY )", $now ) ),
        ];
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /**
     * Daily request counts for the activity chart.
     *
     * Returns one row per day in the window — including days with no activity,
     * filled with zeroes — so the chart renders a continuous axis and the
     * JavaScript stays presentational.
     *
     * Dry runs are counted separately and are also included in their
     * success/failure bucket, since a dry run still succeeds or fails.
     *
     * @since 1.0.6
     *
     * @param int $days Days to cover, including today. Default 30, clamped to 1-365.
     * @return array[] Ordered oldest-first. Each entry: date (Y-m-d), success,
     *                 failed, dry_run, total.
     */
    public function get_daily_counts( $days = 30 ) {
        global $wpdb;

        $days = min( 365, max( 1, absint( $days ) ) );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table from trusted helper; live counters for an admin chart.
        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DATE(created_at) AS day,
                        SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) AS success,
                        SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) AS failed,
                        SUM(CASE WHEN dry_run = 1 THEN 1 ELSE 0 END) AS dry_run,
                        COUNT(*) AS total
                   FROM {$this->table()}
                  WHERE created_at >= DATE_SUB( %s, INTERVAL %d DAY )
               GROUP BY DATE(created_at)",
                current_time( 'mysql' ),
                $days - 1
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        $by_day = [];
        foreach ( $rows as $row ) {
            $by_day[ (string) $row->day ] = $row;
        }

        // Walk the window so gaps become explicit zeroes.
        $series  = [];
        $cursor  = strtotime( gmdate( 'Y-m-d', strtotime( current_time( 'mysql' ) ) ) . ' -' . ( $days - 1 ) . ' days' );
        for ( $i = 0; $i < $days; $i++ ) {
            $key   = gmdate( 'Y-m-d', $cursor );
            $row   = $by_day[ $key ] ?? null;
            $series[] = [
                'date'    => $key,
                'success' => $row ? (int) $row->success : 0,
                'failed'  => $row ? (int) $row->failed : 0,
                'dry_run' => $row ? (int) $row->dry_run : 0,
                'total'   => $row ? (int) $row->total : 0,
            ];
            $cursor = strtotime( '+1 day', $cursor );
        }

        return $series;
    }

    /**
     * Distinct users that appear in the log (for the filter dropdown).
     *
     * @since 1.0.6
     *
     * @return object[] Rows with user_id.
     */
    public function get_logged_users() {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table from trusted helper; small distinct list for a dropdown.
        return (array) $wpdb->get_results( "SELECT DISTINCT user_id FROM {$this->table()} ORDER BY user_id ASC LIMIT 100" );
    }

    /**
     * Distinct routes that appear in the log (for the filter dropdown).
     *
     * @since 1.0.6
     *
     * @return string[]
     */
    public function get_logged_routes() {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table from trusted helper; small distinct list for a dropdown.
        return array_map( 'strval', (array) $wpdb->get_col( "SELECT DISTINCT route FROM {$this->table()} ORDER BY route ASC LIMIT 50" ) );
    }
}
