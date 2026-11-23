<?php
/**
 * API usage tracking and cost estimation.
 *
 * Records every AI provider API call to a custom database table, calculates
 * estimated costs based on token usage, and exposes aggregated statistics
 * through both PHP methods and an AJAX endpoint for the admin dashboard.
 *
 * @package    IPAIS
 * @subpackage Core
 * @since 1.0.0
 * @author     InfyPress
 * @license    GPL-2.0-or-later
 */

namespace IPAIS\Core;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * API Usage Tracker
 *
 * Tracks all API calls, costs, and quota usage across providers.
 *
 * @since 1.0.0
 */
class INFYP_API_Usage_Tracker {

    /**
     * Singleton instance.
     *
     * @since 1.0.0
     * @var    INFYP_API_Usage_Tracker|null
     * @access private
     */
    private static $instance = null;

    /**
     * Custom database table name (without prefix).
     *
     * @since 1.0.0
     * @var   string
     */
    const TABLE_NAME = 'infyp_api_usage';

    /**
     * Get the singleton instance.
     *
     * @since 1.0.0
     *
     * @return INFYP_API_Usage_Tracker The singleton instance.
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor.
     *
     * Registers the AJAX handler for retrieving API statistics.
     *
     * @since 1.0.0
     * @access private
     */
    private function __construct() {
        add_action('wp_ajax_infyp_get_api_stats', [$this, 'ajax_get_api_stats']);
    }
    
    /**
     * Create the API usage database table.
     *
     * Uses dbDelta() to create or update the custom table schema.
     * Called during plugin activation.
     *
     * @since 1.0.0
     *
     * @global wpdb $wpdb WordPress database abstraction object.
     * @return void
     */
    public static function create_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            timestamp datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            provider varchar(50) NOT NULL,
            model varchar(100) NOT NULL,
            endpoint varchar(100) NOT NULL,
            status varchar(20) NOT NULL,
            response_time float DEFAULT NULL,
            tokens_used int DEFAULT NULL,
            cost decimal(10,6) DEFAULT NULL,
            error_message text DEFAULT NULL,
            image_id bigint(20) DEFAULT NULL,
            user_id bigint(20) NOT NULL,
            retry_count int DEFAULT 0,
            rate_limited tinyint(1) DEFAULT 0,
            PRIMARY KEY (id),
            KEY idx_timestamp (timestamp),
            KEY idx_provider (provider),
            KEY idx_status (status),
            KEY idx_user_id (user_id),
            KEY idx_image_id (image_id)
        ) $charset_collate;";
        
        if ( ! function_exists( 'dbDelta' ) ) {
            require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        }
        dbDelta($sql);
    }
    
    /**
     * Record an API call in the usage table.
     *
     * Inserts a row capturing the provider, model, status, response time,
     * token count, estimated cost, and optional error details.
     *
     * @since 1.0.0
     *
     * @param string   $provider The AI provider slug (e.g. 'openai', 'google', 'anthropic').
     * @param string   $model    The AI model identifier used for the call.
     * @param string   $status   Call outcome ('success' or 'error').
     * @param array    $data     Optional. Additional data including 'endpoint', 'response_time',
     *                           'tokens_used', 'error_message', 'retry_count', 'rate_limited'.
     * @param int|null $image_id Optional. The WordPress attachment ID being processed.
     * @return void
     */
    public function track_api_call($provider, $model, $status, $data = [], $image_id = null) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        
        $insert_data = [
            'provider' => $provider,
            'model' => $model,
            'endpoint' => $data['endpoint'] ?? 'generate',
            'status' => $status,
            'response_time' => $data['response_time'] ?? null,
            'tokens_used' => $data['tokens_used'] ?? null,
            'cost' => $this->calculate_cost($model, $data['tokens_used'] ?? 1),
            'error_message' => $data['error_message'] ?? null,
            'image_id' => $image_id,
            'user_id' => get_current_user_id(),
            'retry_count' => $data['retry_count'] ?? 0,
            'rate_limited' => $data['rate_limited'] ?? 0,
        ];
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Insert operation, no caching needed
        $wpdb->insert($table_name, $insert_data);
    }
    
    /**
     * Calculate the estimated cost for an API call.
     *
     * Uses blended per-million-token pricing to estimate the cost based on
     * the model and approximate token count.
     *
     * @since 1.0.0
     * @access private
     *
     * @param string $model  The AI model identifier.
     * @param int    $tokens Optional. Approximate token count. Default 1.
     * @return float Estimated cost in USD.
     */
    private function calculate_cost($model, $tokens = 1) {
        $registry  = \IPAIS\Core\AI\INFYP_Model_Config_Registry::get_instance();
        $base_cost = $registry->get_pricing($model);

        // Rough estimation: 1 image analysis ≈ 1000 tokens
        $cost = ($base_cost / 1000) * ($tokens / 1000);

        /**
         * Filters the calculated cost for a single API call.
         *
         * Extensions can override pricing for custom models or apply
         * provider-specific cost adjustments.
         *
         * @since 1.0.0
         * @param float  $cost      Calculated cost in USD.
         * @param string $model     The AI model identifier.
         * @param int    $tokens    Approximate token count for the call.
         * @param float  $base_cost Base cost per million tokens used in calculation.
         */
        return apply_filters('infyp_calculate_api_cost', $cost, $model, $tokens, $base_cost);
    }
    
    /**
     * Get aggregated usage statistics for a time period.
     *
     * Returns total calls, success/failure counts, average response time,
     * total cost, and total tokens for the specified number of days.
     *
     * @since 1.0.0
     *
     * @param int         $days     Optional. Number of days to look back. Default 30.
     * @param string|null $provider Optional. Filter by provider slug. Default null (all providers).
     * @return object|null Database row with aggregated statistics.
     */
    public function get_usage_stats($days = 30, $provider = null) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $where = "WHERE timestamp >= DATE_SUB(NOW(), INTERVAL %d DAY)";
        $params = [$days];
        
        if ($provider) {
            $where .= " AND provider = %s";
            $params[] = $provider;
        }
        
        // Build the query with proper escaping
        // Table name is safe as it comes from our code
        // WHERE clause is built dynamically with placeholders
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
        // phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        $query = $wpdb->prepare(
            "SELECT
                COUNT(*) as total_calls,
                SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as successful_calls,
                SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) as failed_calls,
                SUM(CASE WHEN rate_limited = 1 THEN 1 ELSE 0 END) as rate_limited_calls,
                AVG(response_time) as avg_response_time,
                SUM(cost) as total_cost,
                SUM(tokens_used) as total_tokens
            FROM {$table_name}
            {$where}",
            $params
        );
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $stats = $wpdb->get_row($query);
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter
        // phpcs:enable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
        
        return $stats;
    }
    
    /**
     * Get usage statistics grouped by provider and model.
     *
     * Returns call counts and costs broken down by provider/model combination,
     * with results cached for 5 minutes via the object cache.
     *
     * @since 1.0.0
     *
     * @param int $days Optional. Number of days to look back. Default 30.
     * @return array Array of objects with provider, model, total_calls, successful_calls, total_cost.
     */
    public function get_usage_by_provider($days = 30) {
        // Try cache first
        $cache_key = 'infyp_usage_by_provider_' . $days;
        $cached = wp_cache_get($cache_key, 'infyp');
        if ($cached !== false) {
            return $cached;
        }
        
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                provider,
                model,
                COUNT(*) as total_calls,
                SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as successful_calls,
                SUM(cost) as total_cost
            FROM {$table_name}
            WHERE timestamp >= DATE_SUB(NOW(), INTERVAL %d DAY)
            GROUP BY provider, model
            ORDER BY total_calls DESC",
            $days
        ));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
        
        // Cache for 5 minutes
        wp_cache_set($cache_key, $results, 'infyp', 300);
        
        return $results;
    }
    
    /**
     * Get daily usage data suitable for chart rendering.
     *
     * Returns per-day, per-provider call counts and costs ordered by date
     * descending.
     *
     * @since 1.0.0
     *
     * @param int $days Optional. Number of days to look back. Default 30.
     * @return array Array of objects with date, provider, calls, and cost.
     */
    public function get_daily_usage($days = 30) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
        return $wpdb->get_results($wpdb->prepare(
            "SELECT 
                DATE(timestamp) as date,
                provider,
                COUNT(*) as calls,
                SUM(cost) as cost
            FROM {$table_name}
            WHERE timestamp >= DATE_SUB(NOW(), INTERVAL %d DAY)
            GROUP BY DATE(timestamp), provider
            ORDER BY date DESC",
            $days
        ));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
    }
    
    /**
     * Retrieve the most recent API error records.
     *
     * @since 1.0.0
     *
     * @param int $limit Optional. Maximum number of error records to return. Default 10.
     * @return array Array of error row objects ordered by timestamp descending.
     */
    public function get_recent_errors($limit = 10) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
        return $wpdb->get_results($wpdb->prepare(
            "SELECT provider, error_message, timestamp FROM {$table_name}
            WHERE status = 'error'
            ORDER BY timestamp DESC
            LIMIT %d",
            $limit
        ));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
    }
    
    /**
     * AJAX handler for retrieving API usage statistics.
     *
     * Returns aggregated stats, per-provider breakdown, and daily usage
     * data as a JSON response. Requires 'manage_options' capability.
     *
     * @since 1.0.0
     *
     * @return void Sends a JSON response and terminates.
     */
    public function ajax_get_api_stats() {
        check_ajax_referer('infyp_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die();
        }
        
        $days = intval($_POST['days'] ?? 30);
        $stats = $this->get_usage_stats($days);
        $by_provider = $this->get_usage_by_provider($days);
        $daily = $this->get_daily_usage($days);
        
        wp_send_json_success([
            'stats' => $stats,
            'by_provider' => $by_provider,
            'daily' => $daily
        ]);
    }
    
    /**
     * Delete usage records older than 90 days.
     *
     * Intended to be called by a scheduled cron task to keep the custom
     * table size manageable.
     *
     * @since 1.0.0
     *
     * @global wpdb $wpdb WordPress database abstraction object.
     * @return void
     */
    public function cleanup_old_records() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        
        // phpcs:disable WordPress.DB.DirectDatabaseQuery
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table_name} WHERE timestamp < DATE_SUB(NOW(), INTERVAL %d DAY)",
                90
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        // phpcs:enable WordPress.DB.DirectDatabaseQuery
    }
}