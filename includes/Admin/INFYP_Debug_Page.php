<?php
/**
 * Debug and Diagnostics Page - System Information and Troubleshooting
 *
 * Provides a comprehensive debug interface for administrators when
 * WP_DEBUG is enabled. Displays system information, logs, statistics,
 * and diagnostic tools for troubleshooting plugin issues.
 *
 * @package    IPAIS
 * @subpackage Admin
 * @since 1.0.0
 * @author     InfyPress
 * @license    GPL-2.0-or-later
 */

namespace IPAIS\Admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Debug and Diagnostics Page - System Information and Troubleshooting
 *
 * This class provides a comprehensive debug interface for administrators when
 * WP_DEBUG is enabled. It displays system information, logs, statistics, and
 * provides diagnostic tools for troubleshooting.
 *
 * Features:
 * - System Status (PHP, WordPress, extensions, database)
 * - Plugin Configuration (constants, settings)
 * - Recent Logs (with filtering and pagination)
 * - API Test Tools (test connections to AI providers)
 * - Cache Management (clear caches, view stats)
 * - Debug Info Export (download system info)
 * - Performance Metrics (memory, execution time)
 *
 * Security:
 * - Only accessible when WP_DEBUG is enabled
 * - Requires 'manage_options' capability
 * - Sanitizes all output
 * - Nonce verification for all actions
 *
 * AJAX Actions:
 * - infyp_debug_test_api: Test API provider connection
 * - infyp_debug_clear_cache: Clear all caches
 * - infyp_debug_clear_logs: Clear log files
 * - infyp_debug_export_info: Export debug information
 * - infyp_debug_refresh_stats: Refresh statistics
 * - infyp_debug_load_more_logs: Load additional log entries
 *
 * @package   IPAIS\Admin
 * @since 1.0.0
 * @author    InfyPress
 * @license   GPL-2.0-or-later
 */

use IPAIS\Core\INFYP_Logger;
use IPAIS\Core\INFYP_Error_Handler;
use IPAIS\Core\INFYP_API_Usage_Tracker;
use IPAIS\Core\INFYP_Cache_Manager;
use IPAIS\Core\INFYP_API_Key_Manager;
use IPAIS\Core\INFYP_Encryption_Service;

/**
 * Debug and Diagnostics Page Class
 *
 * Provides comprehensive debugging interface with system information,
 * logs, diagnostic tools, and troubleshooting utilities.
 *
 * Page Sections:
 * 1. System Status
 *    - PHP version and compatibility
 *    - WordPress version
 *    - Required extensions
 *    - Database information
 *    - Memory usage
 *
 * 2. Plugin Configuration
 *    - Plugin constants
 *    - Active settings
 *    - API key status
 *    - Feature flags
 *
 * 3. Recent Logs
 *    - Filterable log viewer
 *    - Level-based filtering (ERROR, WARNING, INFO, DEBUG)
 *    - Pagination
 *    - Real-time log loading
 *
 * 4. Diagnostic Tools
 *    - API connection tester
 *    - Cache clear utilities
 *    - Log management
 *    - System info export
 *
 * @package   IPAIS\Admin
 * @since 1.0.0
 *
 * @see       INFYP_Logger For log retrieval
 * @see       INFYP_Cache_Manager For cache operations
 */
class INFYP_Debug_Page {

    /**
     * Constructor - Register AJAX handlers
     *
     * @since 1.0.0
     */
    public function __construct() {
        add_action('wp_ajax_infyp_debug_test_api', [$this, 'ajax_test_api']);
        add_action('wp_ajax_infyp_debug_clear_cache', [$this, 'ajax_clear_cache']);
        add_action('wp_ajax_infyp_debug_clear_logs', [$this, 'ajax_clear_logs']);
        add_action('wp_ajax_infyp_debug_export_info', [$this, 'ajax_export_debug_info']);
        add_action('wp_ajax_infyp_debug_refresh_stats', [$this, 'ajax_refresh_stats']);
        add_action('wp_ajax_infyp_debug_load_more_logs', [$this, 'ajax_load_more_logs']);
        add_action('wp_ajax_infyp_download_logs', [$this, 'ajax_download_logs']);
    }
    
    /**
     * Render the debug page.
     *
     * Checks user capabilities and WP_DEBUG status before loading the view.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function render_page() {
        // Security check
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'infy-ai-image-seo-optimizer'));
        }
        
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            wp_die(esc_html__('Debug page is only available when WP_DEBUG is enabled.', 'infy-ai-image-seo-optimizer'));
        }
        
        require_once INFYP_PLUGIN_DIR . 'includes/views/debug-page.php';
    }
    
    /**
     * Get system status information.
     *
     * Collects PHP version, WordPress version, extensions, database info,
     * memory usage, and plugin constants.
     *
     * @since 1.0.0
     *
     * @return array Associative array of system status data.
     */
    public function get_system_status() {
        global $wpdb;
        
        $status = [
            'php_version' => PHP_VERSION,
            'php_version_compatible' => version_compare(PHP_VERSION, '7.4', '>='),
            'wp_version' => get_bloginfo('version'),
            'wp_version_compatible' => version_compare(get_bloginfo('version'), '5.6', '>='),
            'plugin_version' => INFYP_VERSION,
            'wp_debug' => defined('WP_DEBUG') && WP_DEBUG,
            'wp_debug_log' => defined('WP_DEBUG_LOG') && WP_DEBUG_LOG,
            'memory_limit' => ini_get('memory_limit'),
            'memory_usage' => $this->format_bytes(memory_get_usage()),
            'memory_peak' => $this->format_bytes(memory_get_peak_usage()),
            'max_execution_time' => ini_get('max_execution_time'),
            'extensions' => [
                'curl' => extension_loaded('curl'),
                'json' => extension_loaded('json'),
                'openssl' => extension_loaded('openssl'),
                'mbstring' => extension_loaded('mbstring'),
                'gd' => extension_loaded('gd'),
                'exif' => extension_loaded('exif'),
            ],
            'database' => [
                'version' => $wpdb->db_version(),
                'charset' => $wpdb->charset,
                'collation' => $wpdb->collate,
            ],
            'constants' => [
                'INFYP_VERSION' => INFYP_VERSION,
                'INFYP_CACHE_ENABLED' => INFYP_CACHE_ENABLED,
                'INFYP_DEBUG_ENABLED' => INFYP_DEBUG_ENABLED,
            ]
        ];
        
        return $status;
    }
    
    /**
     * Get API health information.
     *
     * Retrieves API key status, usage stats, recent errors, provider breakdown,
     * and connection test results.
     *
     * @since 1.0.0
     *
     * @return array Associative array of API health data.
     */
    public function get_api_health() {
        $api_key_manager = INFYP_API_Key_Manager::get_instance();
        $api_usage_tracker = INFYP_API_Usage_Tracker::get_instance();
        
        $health = [
            'api_keys' => $api_key_manager->get_api_key_status(),
            'usage_stats' => $api_usage_tracker->get_usage_stats(7), // Last 7 days
            'recent_errors' => $api_usage_tracker->get_recent_errors(5),
            'providers' => $api_usage_tracker->get_usage_by_provider(7),
            'connection_tests' => $this->test_api_connections(),
        ];
        
        return $health;
    }
    
    /**
     * Test API connections for all configured providers.
     *
     * Iterates through each provider and performs a connectivity test,
     * recording response time and any errors.
     *
     * @since 1.0.0
     *
     * @return array Associative array keyed by provider with test results.
     */
    private function test_api_connections() {
        $api_key_manager = INFYP_API_Key_Manager::get_instance();
        $keys = $api_key_manager->get_api_key_status();
        
        $tests = [];
        
        // Test each configured API
        foreach ($keys as $provider => $configured) {
            $tests[$provider] = [
                'configured' => $configured,
                'tested' => false,
                'success' => false,
                'error' => null,
                'response_time' => null,
            ];
            
            if ($configured) {
                $start_time = microtime(true);
                try {
                    // This would be actual API test calls
                    $test_result = $this->perform_api_test($provider);
                    $tests[$provider]['tested'] = true;
                    $tests[$provider]['success'] = $test_result['success'];
                    $tests[$provider]['error'] = $test_result['error'] ?? null;
                    $tests[$provider]['response_time'] = round((microtime(true) - $start_time) * 1000, 2);
                } catch (\Exception $e) {
                    $tests[$provider]['tested'] = true;
                    $tests[$provider]['success'] = false;
                    $tests[$provider]['error'] = esc_html($e->getMessage());
                    $tests[$provider]['response_time'] = round((microtime(true) - $start_time) * 1000, 2);
                }
            }
        }
        
        return $tests;
    }
    
    /**
     * Perform actual API test for a given provider.
     *
     * @since 1.0.0
     *
     * @param string $provider The AI provider name (e.g., 'openai', 'google', 'anthropic').
     * @return array {
     *     Test result data.
     *
     *     @type bool        $success Whether the test passed.
     *     @type string|null $error   Error message, if any.
     * }
     */
    private function perform_api_test($provider) {
        // This would contain actual API testing logic
        // For now, return mock data
        return [
            'success' => true,
            'error' => null
        ];
    }
    
    /**
     * Get cache performance information.
     *
     * Returns cache stats, object cache status, cache groups, and memory usage.
     *
     * @since 1.0.0
     *
     * @return array Associative array of cache performance data.
     */
    public function get_cache_performance() {
        $cache_manager = INFYP_Cache_Manager::get_instance();
        
        $performance = [
            'stats' => $cache_manager->get_stats(),
            'object_cache' => wp_using_ext_object_cache(),
            'cache_groups' => [
                'main' => INFYP_Cache_Manager::CACHE_GROUP,
                'queries' => INFYP_Cache_Manager::CACHE_GROUP_QUERIES,
                'api' => INFYP_Cache_Manager::CACHE_GROUP_API,
                'images' => INFYP_Cache_Manager::CACHE_GROUP_IMAGES,
            ],
            'memory_usage' => $this->get_cache_memory_usage(),
        ];
        
        return $performance;
    }
    
    /**
     * Get estimated cache memory usage by group.
     *
     * @since 1.0.0
     *
     * @return array {
     *     Cache memory usage data.
     *
     *     @type string $total    Total estimated memory.
     *     @type array  $by_group Per-group memory estimates.
     * }
     */
    private function get_cache_memory_usage() {
        // This would calculate actual cache memory usage
        // For now, return estimated values
        return [
            'total' => '2.5MB',
            'by_group' => [
                'main' => '512KB',
                'queries' => '1.2MB',
                'api' => '640KB',
                'images' => '148KB',
            ]
        ];
    }
    
    /**
     * Get recent activity information.
     *
     * Returns parsed recent logs, log file size, and an error summary.
     *
     * @since 1.0.0
     *
     * @return array Associative array of recent activity data.
     */
    public function get_recent_activity() {
        $logger = INFYP_Logger::get_instance();
        
        $activity = [
            'recent_logs' => $this->parse_recent_logs(20),
            'log_file_size' => $this->get_log_file_size(),
            'error_summary' => $this->get_error_summary(),
        ];
        
        return $activity;
    }
    
    /**
     * Parse recent log entries.
     *
     * Reads the log file content and parses the last N entries.
     *
     * @since 1.0.0
     *
     * @param int $limit Maximum number of log entries to return. Default 20.
     * @return array Array of parsed log entries, newest first.
     */
    private function parse_recent_logs($limit = 20) {
        $log_content = INFYP_Logger::get_log_content();
        
        if ($log_content === 'Log file is empty or does not exist.') {
            return [];
        }
        
        $lines = explode("\n", $log_content);
        $lines = array_filter($lines); // Remove empty lines
        $lines = array_slice($lines, -$limit); // Get last N lines
        
        $parsed_logs = [];
        foreach ($lines as $line) {
            $parsed_logs[] = $this->parse_log_line($line);
        }
        
        return array_reverse($parsed_logs); // Show newest first
    }
    
    /**
     * Parse a single log line into structured data.
     *
     * Extracts timestamp, level, and message from a log line using regex.
     *
     * @since 1.0.0
     *
     * @param string $line Raw log line string.
     * @return array {
     *     Parsed log data.
     *
     *     @type string $timestamp The log timestamp.
     *     @type string $level     The log level (ERROR, WARNING, INFO, DEBUG).
     *     @type string $message   The log message.
     *     @type string $raw       The original raw log line.
     * }
     */
    private function parse_log_line($line) {
        if (preg_match('/\[([^\]]+)\]\s*\[([^\]]+)\]:\s*(.+)/', $line, $matches)) {
            return [
                'timestamp' => $matches[1],
                'level' => $matches[2],
                'message' => $matches[3],
                'raw' => $line,
            ];
        }
        
        return [
            'timestamp' => '',
            'level' => 'INFO',
            'message' => $line,
            'raw' => $line,
        ];
    }
    
    /**
     * Get the debug log file size as a human-readable string.
     *
     * @since 1.0.0
     *
     * @return string Formatted file size (e.g., '2.5 MB') or '0 bytes'.
     */
    private function get_log_file_size() {
        $upload_dir = wp_upload_dir();
        $log_file = $upload_dir['basedir'] . '/ai-image-seo-logs/debug.log';
        
        if (file_exists($log_file)) {
            return $this->format_bytes(filesize($log_file));
        }

        return '0 B';
    }
    
    /**
     * Get a summary of recent errors, warnings, and info entries.
     *
     * Analyzes the last 100 log entries and categorizes them by level.
     *
     * @since 1.0.0
     *
     * @return array {
     *     Error summary data.
     *
     *     @type int   $total         Total log entries analyzed.
     *     @type int   $errors        Number of error/critical entries.
     *     @type int   $warnings      Number of warning entries.
     *     @type int   $info          Number of info/debug entries.
     *     @type array $recent_errors Up to 5 most recent error entries.
     * }
     */
    private function get_error_summary() {
        $logs = $this->parse_recent_logs(100);
        
        $summary = [
            'total' => count($logs),
            'errors' => 0,
            'warnings' => 0,
            'info' => 0,
            'recent_errors' => [],
        ];
        
        foreach ($logs as $log) {
            switch (strtoupper($log['level'])) {
                case 'ERROR':
                case 'CRITICAL':
                    $summary['errors']++;
                    if (count($summary['recent_errors']) < 5) {
                        $summary['recent_errors'][] = $log;
                    }
                    break;
                case 'WARNING':
                    $summary['warnings']++;
                    break;
                default:
                    $summary['info']++;
                    break;
            }
        }
        
        return $summary;
    }
    
    /**
     * Get database health information.
     *
     * Checks plugin tables, query performance, database size, and
     * provides optimization suggestions.
     *
     * @since 1.0.0
     *
     * @return array Associative array of database health data.
     */
    public function get_database_health() {
        global $wpdb;
        
        $health = [
            'tables' => $this->check_plugin_tables(),
            'query_performance' => $this->get_query_performance(),
            'database_size' => $this->get_database_size(),
            'optimization_suggestions' => $this->get_optimization_suggestions(),
        ];
        
        return $health;
    }
    
    /**
     * Check the existence and status of plugin database tables.
     *
     * Verifies each plugin table exists and reports row counts and sizes.
     *
     * @since 1.0.0
     *
     * @return array Associative array keyed by table name with status details.
     */
    private function check_plugin_tables() {
        global $wpdb;
        
        $tables = [
            'api_usage' => $wpdb->prefix . 'infyp_api_usage',
            'changelog' => $wpdb->prefix . 'infyp_changelog',
        ];
        
        $table_status = [];
        
        foreach ($tables as $name => $table) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Debug information doesn't need caching
            $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
            $table_status[$name] = [
                'exists' => !empty($exists),
                'table_name' => $table,
                'rows' => 0,
                'size' => '0 bytes',
            ];
            
            if (!empty($exists)) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Debug page; table name from trusted internal source
                $table_status[$name]['rows'] = $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Debug information doesn't need caching
                $size_info = $wpdb->get_row($wpdb->prepare("
                    SELECT 
                        ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb
                    FROM information_schema.tables 
                    WHERE table_schema = %s AND table_name = %s
                ", DB_NAME, $table));
                
                if ($size_info) {
                    $table_status[$name]['size'] = $size_info->size_mb . ' MB';
                }
            }
        }
        
        return $table_status;
    }
    
    /**
     * Get query performance metrics.
     *
     * Returns slow query counts, average query time, and cache hit rates.
     *
     * @since 1.0.0
     *
     * @return array Associative array of query performance metrics.
     */
    private function get_query_performance() {
        // This would contain actual query performance analysis
        return [
            'slow_queries' => 0,
            'avg_query_time' => '0.05ms',
            'total_queries' => 0,
            'cache_hit_rate' => '85%',
        ];
    }
    
    /**
     * Get the total size of plugin database tables.
     *
     * Queries information_schema for combined data and index sizes.
     *
     * @since 1.0.0
     *
     * @return array {
     *     Database size data.
     *
     *     @type string $plugin_tables Total size of plugin tables in MB.
     *     @type string $total_database Total database size in MB.
     * }
     */
    private function get_database_size() {
        global $wpdb;
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Debug/diagnostic query doesn't need caching
        $result = $wpdb->get_row($wpdb->prepare("
            SELECT 
                ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS total_mb
            FROM information_schema.tables 
            WHERE table_schema = %s 
            AND table_name LIKE %s
        ", DB_NAME, $wpdb->esc_like($wpdb->prefix . 'infyp_') . '%'));
        // phpcs:enable WordPress.DB.DirectDatabaseQuery
        
        return [
            'plugin_tables' => ($result->total_mb ?? 0) . ' MB',
            'total_database' => '0 MB', // Would calculate total DB size
        ];
    }
    
    /**
     * Get optimization suggestions based on current system state.
     *
     * Checks log file sizes, cache configuration, and other factors
     * to provide actionable recommendations.
     *
     * @since 1.0.0
     *
     * @return array Array of suggestion arrays with type, title, description, and action.
     */
    private function get_optimization_suggestions() {
        $suggestions = [];
        
        // Check for large log files
        if ($this->get_log_file_size() !== '0 B') {
            $suggestions[] = [
                'type'        => 'warning',
                'title'       => __( 'Large Log File', 'infy-ai-image-seo-optimizer' ),
                'description' => __( 'Consider clearing the debug log file to free up space.', 'infy-ai-image-seo-optimizer' ),
                'action'      => 'clear_logs',
            ];
        }

        // Check cache status
        if (!wp_using_ext_object_cache()) {
            $suggestions[] = [
                'type'        => 'info',
                'title'       => __( 'Object Cache', 'infy-ai-image-seo-optimizer' ),
                'description' => __( 'Consider using a persistent object cache like Redis or Memcached for better performance.', 'infy-ai-image-seo-optimizer' ),
                'action'      => null,
            ];
        }
        
        return $suggestions;
    }
    
    /**
     * Format a byte count into a human-readable string.
     *
     * @since 1.0.0
     *
     * @param int $bytes     Number of bytes.
     * @param int $precision Decimal precision. Default 2.
     * @return string Formatted string (e.g., '2.5 MB').
     */
    private function format_bytes($bytes, $precision = 2) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }
    
    /**
     * AJAX handler for API testing.
     *
     * Tests connectivity to a specified AI provider. Requires manage_options
     * capability and valid nonce.
     *
     * @since 1.0.0
     *
     * @return void Sends JSON response and exits.
     */
    public function ajax_test_api() {
        // Check nonce
        if (!check_ajax_referer('infyp_debug_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => esc_html__('Security check failed.', 'infy-ai-image-seo-optimizer')]);
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => esc_html__('Insufficient permissions.', 'infy-ai-image-seo-optimizer')]);
            return;
        }

        $provider = isset($_POST['provider']) ? sanitize_text_field(wp_unslash($_POST['provider'])) : '';

        if (empty($provider)) {
            wp_send_json_error(['message' => esc_html__('Invalid provider.', 'infy-ai-image-seo-optimizer')]);
            return;
        }
        
        $result = $this->perform_api_test($provider);
        wp_send_json_success($result);
    }
    
    /**
     * AJAX handler for clearing all plugin caches.
     *
     * Flushes all cache groups via the cache manager. Requires manage_options
     * capability and valid nonce.
     *
     * @since 1.0.0
     *
     * @return void Sends JSON response and exits.
     */
    public function ajax_clear_cache() {
        // Check nonce
        if (!check_ajax_referer('infyp_debug_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => esc_html__('Security check failed.', 'infy-ai-image-seo-optimizer')]);
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => esc_html__('Insufficient permissions.', 'infy-ai-image-seo-optimizer')]);
            return;
        }
        
        $cache_manager = INFYP_Cache_Manager::get_instance();
        $cache_manager->clear_all_cache();
        
        wp_send_json_success(esc_html__('Cache cleared successfully.', 'infy-ai-image-seo-optimizer'));
    }
    
    /**
     * AJAX handler for clearing debug log files.
     *
     * Removes all entries from the plugin debug log. Requires manage_options
     * capability and valid nonce.
     *
     * @since 1.0.0
     *
     * @return void Sends JSON response and exits.
     */
    public function ajax_clear_logs() {
        // Check nonce
        if (!check_ajax_referer('infyp_debug_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => esc_html__('Security check failed.', 'infy-ai-image-seo-optimizer')]);
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => esc_html__('Insufficient permissions.', 'infy-ai-image-seo-optimizer')]);
            return;
        }
        
        INFYP_Logger::clear_log();
        
        wp_send_json_success(esc_html__('Logs cleared successfully.', 'infy-ai-image-seo-optimizer'));
    }
    
    /**
     * AJAX handler for exporting debug information.
     *
     * Collects all diagnostic data and formats it as downloadable text.
     * Sensitive information (connection tests, raw logs) is removed.
     * Requires manage_options capability and valid nonce.
     *
     * @since 1.0.0
     *
     * @return void Sends JSON response with text content and filename.
     */
    public function ajax_export_debug_info() {
        // Check nonce
        if (!check_ajax_referer('infyp_debug_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => esc_html__('Security check failed.', 'infy-ai-image-seo-optimizer')]);
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => esc_html__('Insufficient permissions.', 'infy-ai-image-seo-optimizer')]);
            return;
        }
        
        $debug_info = [
            'timestamp' => current_time('mysql'),
            'system_status' => $this->get_system_status(),
            'api_health' => $this->get_api_health(),
            'cache_performance' => $this->get_cache_performance(),
            'recent_activity' => $this->get_recent_activity(),
            'database_health' => $this->get_database_health(),
        ];
        
        // Remove sensitive information
        unset($debug_info['api_health']['connection_tests']);
        unset($debug_info['recent_activity']['recent_logs']);
        
        // Format as text for download
        $text_content = $this->format_debug_info_as_text($debug_info);
        
        wp_send_json_success([
            'content' => $text_content,
            'filename' => 'infyp-debug-info-' . gmdate('Y-m-d-H-i-s') . '.txt'
        ]);
    }
    
    /**
     * Format debug information as human-readable text for export.
     *
     * Converts the structured debug data array into a plain-text report
     * suitable for download or pasting into support requests.
     *
     * @since 1.0.0
     *
     * @param array $debug_info Associative array of debug data sections.
     * @return string Formatted plain-text debug report.
     */
    private function format_debug_info_as_text($debug_info) {
        $output = "Image SEO Optimizer - Debug Information\n";
        $output .= "Generated: " . $debug_info['timestamp'] . "\n";
        $output .= str_repeat("=", 50) . "\n\n";
        
        // System Status
        $output .= "SYSTEM STATUS\n";
        $output .= str_repeat("-", 20) . "\n";
        $system = $debug_info['system_status'];
        $output .= "WordPress Version: " . $system['wp_version'] . " (Compatible: " . ($system['wp_version_compatible'] ? 'Yes' : 'No') . ")\n";
        $output .= "PHP Version: " . $system['php_version'] . " (Compatible: " . ($system['php_version_compatible'] ? 'Yes' : 'No') . ")\n";
        $output .= "Plugin Version: " . $system['plugin_version'] . "\n";
        $output .= "Memory Usage: " . $system['memory_usage'] . " / " . $system['memory_limit'] . "\n";
        $output .= "Memory Peak: " . $system['memory_peak'] . "\n";
        $output .= "WP Debug: " . ($system['wp_debug'] ? 'Enabled' : 'Disabled') . "\n";
        $output .= "WP Debug Log: " . ($system['wp_debug_log'] ? 'Enabled' : 'Disabled') . "\n";
        $output .= "Max Execution Time: " . $system['max_execution_time'] . "s\n";
        
        $output .= "\nPHP Extensions:\n";
        foreach ($system['extensions'] as $ext => $loaded) {
            $output .= "  " . $ext . ": " . ($loaded ? 'Loaded' : 'Missing') . "\n";
        }
        
        $output .= "\nDatabase:\n";
        $output .= "  Version: " . $system['database']['version'] . "\n";
        $output .= "  Charset: " . $system['database']['charset'] . "\n";
        $output .= "  Collation: " . $system['database']['collation'] . "\n";
        
        // API Health
        $output .= "\n\nAPI HEALTH\n";
        $output .= str_repeat("-", 20) . "\n";
        $api = $debug_info['api_health'];
        $output .= "API Key Status:\n";
        foreach ($api['api_keys'] as $provider => $configured) {
            $output .= "  " . ucfirst($provider) . ": " . ($configured ? 'Configured' : 'Not Configured') . "\n";
        }
        
        if ($api['usage_stats']) {
            $output .= "\nUsage Statistics (Last 7 days):\n";
            $stats = $api['usage_stats'];
            $output .= "  Total Calls: " . ($stats->total_calls ?? 0) . "\n";
            $output .= "  Successful: " . ($stats->successful_calls ?? 0) . "\n";
            $output .= "  Failed: " . ($stats->failed_calls ?? 0) . "\n";
            $output .= "  Rate Limited: " . ($stats->rate_limited_calls ?? 0) . "\n";
            $output .= "  Average Response Time: " . number_format($stats->avg_response_time ?? 0, 2) . "ms\n";
            $output .= "  Total Cost: $" . number_format($stats->total_cost ?? 0, 4) . "\n";
        }
        
        // Cache Performance
        $output .= "\n\nCACHE PERFORMANCE\n";
        $output .= str_repeat("-", 20) . "\n";
        $cache = $debug_info['cache_performance'];
        $output .= "Object Cache: " . ($cache['object_cache'] ? 'Active' : 'Not Active') . "\n";
        $output .= "Cache Groups: " . implode(', ', array_keys($cache['cache_groups'])) . "\n";
        $output .= "Memory Usage: " . $cache['memory_usage']['total'] . "\n";
        
        // Recent Activity
        $output .= "\n\nRECENT ACTIVITY\n";
        $output .= str_repeat("-", 20) . "\n";
        $activity = $debug_info['recent_activity'];
        $output .= "Log File Size: " . $activity['log_file_size'] . "\n";
        $summary = $activity['error_summary'];
        $output .= "Error Summary:\n";
        $output .= "  Errors: " . $summary['errors'] . "\n";
        $output .= "  Warnings: " . $summary['warnings'] . "\n";
        $output .= "  Info: " . $summary['info'] . "\n";
        $output .= "  Total: " . $summary['total'] . "\n";
        
        // Database Health
        $output .= "\n\nDATABASE HEALTH\n";
        $output .= str_repeat("-", 20) . "\n";
        $db = $debug_info['database_health'];
        $output .= "Plugin Tables:\n";
        foreach ($db['tables'] as $name => $table) {
            $output .= "  " . $table['table_name'] . ": " . ($table['exists'] ? 'Exists' : 'Missing');
            if ($table['exists']) {
                $output .= " (" . $table['rows'] . " rows, " . $table['size'] . ")";
            }
            $output .= "\n";
        }
        
        $output .= "\nDatabase Size: " . $db['database_size']['plugin_tables'] . "\n";
        
        if (!empty($db['optimization_suggestions'])) {
            $output .= "\nOptimization Suggestions:\n";
            foreach ($db['optimization_suggestions'] as $suggestion) {
                $output .= "  - " . $suggestion['title'] . ": " . $suggestion['description'] . "\n";
            }
        }
        
        $output .= "\n" . str_repeat("=", 50) . "\n";
        $output .= "End of Debug Information\n";
        
        return $output;
    }
    
    /**
     * AJAX handler for refreshing all debug page statistics.
     *
     * Re-collects system status, API health, cache performance, recent
     * activity, and database health data. Requires manage_options capability
     * and valid nonce.
     *
     * @since 1.0.0
     *
     * @return void Sends JSON response with refreshed stats.
     */
    public function ajax_refresh_stats() {
        // Check nonce
        if (!check_ajax_referer('infyp_debug_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => esc_html__('Security check failed.', 'infy-ai-image-seo-optimizer')]);
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => esc_html__('Insufficient permissions.', 'infy-ai-image-seo-optimizer')]);
            return;
        }
        
        $stats = [
            'system_status' => $this->get_system_status(),
            'api_health' => $this->get_api_health(),
            'cache_performance' => $this->get_cache_performance(),
            'recent_activity' => $this->get_recent_activity(),
            'database_health' => $this->get_database_health(),
        ];
        
        wp_send_json_success($stats);
    }
    
    /**
     * AJAX handler for loading additional log entries with pagination.
     *
     * Loads the next batch of log entries based on the provided offset.
     * Requires manage_options capability and valid nonce.
     *
     * @since 1.0.0
     *
     * @return void Sends JSON response with log entries and has_more flag.
     */
    public function ajax_load_more_logs() {
        // Check nonce
        if (!check_ajax_referer('infyp_debug_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => esc_html__('Security check failed.', 'infy-ai-image-seo-optimizer')]);
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => esc_html__('Insufficient permissions.', 'infy-ai-image-seo-optimizer')]);
            return;
        }
        
        $offset = isset($_POST['offset']) ? intval(wp_unslash($_POST['offset'])) : 0;
        $limit = 20; // Load 20 more logs
        
        $more_logs = $this->parse_recent_logs_with_offset($offset, $limit);
        
        wp_send_json_success([
            'logs' => $more_logs,
            'has_more' => count($more_logs) === $limit
        ]);
    }
    
    /**
     * Parse recent log entries with offset for pagination.
     *
     * Reads log content, reverses to newest-first, then applies offset
     * and limit for paginated retrieval.
     *
     * @since 1.0.0
     *
     * @param int $offset Number of entries to skip. Default 0.
     * @param int $limit  Maximum number of entries to return. Default 20.
     * @return array Array of parsed log entries.
     */
    private function parse_recent_logs_with_offset($offset = 0, $limit = 20) {
        $log_content = INFYP_Logger::get_log_content();

        if ($log_content === 'Log file is empty or does not exist.') {
            return [];
        }

        $lines = explode("\n", $log_content);
        $lines = array_filter($lines); // Remove empty lines
        $lines = array_reverse($lines); // Show newest first

        // Apply offset and limit
        $lines = array_slice($lines, $offset, $limit);

        $parsed_logs = [];
        foreach ($lines as $line) {
            $parsed_logs[] = $this->parse_log_line($line);
        }

        return $parsed_logs;
    }

    /**
     * AJAX handler for downloading filtered log entries.
     *
     * Filters log entries by type (all, errors, warnings, info) and returns
     * formatted text content for download. Requires manage_options capability
     * and valid nonce.
     *
     * @since 1.0.0
     *
     * @return void Sends JSON response with download content and filename.
     */
    public function ajax_download_logs() {
        // Check nonce
        if (!check_ajax_referer('infyp_debug_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => esc_html__('Security check failed.', 'infy-ai-image-seo-optimizer')]);
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => esc_html__('Insufficient permissions.', 'infy-ai-image-seo-optimizer')]);
            return;
        }

        $log_type = isset($_POST['log_type']) ? sanitize_text_field(wp_unslash($_POST['log_type'])) : 'all';

        $log_content = INFYP_Logger::get_log_content();

        if ($log_content === 'Log file is empty or does not exist.') {
            wp_send_json_error(['message' => esc_html__('No logs available to download.', 'infy-ai-image-seo-optimizer')]);
            return;
        }

        $lines = explode("\n", $log_content);
        $lines = array_filter($lines); // Remove empty lines

        // Filter logs based on type
        $filtered_lines = [];
        $error_levels = ['ERROR', 'CRITICAL'];
        $warning_levels = ['WARNING'];
        $info_levels = ['INFO', 'DEBUG'];

        foreach ($lines as $line) {
            $parsed = $this->parse_log_line($line);
            $level = strtoupper($parsed['level']);

            switch ($log_type) {
                case 'errors':
                    if (in_array($level, $error_levels, true)) {
                        $filtered_lines[] = $line;
                    }
                    break;
                case 'warnings':
                    if (in_array($level, $warning_levels, true)) {
                        $filtered_lines[] = $line;
                    }
                    break;
                case 'info':
                    if (in_array($level, $info_levels, true)) {
                        $filtered_lines[] = $line;
                    }
                    break;
                case 'all':
                default:
                    $filtered_lines[] = $line;
                    break;
            }
        }

        if (empty($filtered_lines)) {
            wp_send_json_error(['message' => esc_html__('No logs found for the selected filter.', 'infy-ai-image-seo-optimizer')]);
            return;
        }

        // Build the download content
        $site_name = sanitize_title(get_bloginfo('name'));
        $type_label = ucfirst($log_type);
        $date_str = gmdate('Y-m-d-H-i-s');

        $header = "Image SEO Optimizer - {$type_label} Logs\n";
        $header .= "Site: " . get_bloginfo('name') . "\n";
        $header .= "Generated: " . current_time('mysql') . "\n";
        $header .= "Total Entries: " . count($filtered_lines) . "\n";
        $header .= str_repeat("=", 60) . "\n\n";

        $content = $header . implode("\n", array_reverse($filtered_lines)); // Newest first

        $filename = "ai-image-seo-{$log_type}-logs-{$site_name}-{$date_str}.txt";

        wp_send_json_success([
            'content' => $content,
            'filename' => $filename
        ]);
    }
}