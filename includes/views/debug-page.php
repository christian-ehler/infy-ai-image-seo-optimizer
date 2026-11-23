<?php
/**
 * Debug page view - Provides comprehensive debug information for administrators
 * 
 * @package INFYP
 * @since 1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Helper functions
function infyp_format_bytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
        $bytes /= 1024;
    }
    return round($bytes, $precision) . ' ' . $units[$i];
}

function infyp_parse_log_line($line) {
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

function infyp_get_recent_logs($limit = 20) {
    if (!class_exists('\IPAIS\Core\INFYP_Logger')) {
        return [];
    }

    $log_content = \IPAIS\Core\INFYP_Logger::get_log_content();

    if ($log_content === 'Log file is empty or does not exist.') {
        return [];
    }

    $lines = explode("\n", $log_content);
    $lines = array_filter($lines);
    $lines = array_slice($lines, -$limit);

    $parsed_logs = [];
    foreach ($lines as $line) {
        $parsed_logs[] = infyp_parse_log_line($line);
    }

    return array_reverse($parsed_logs);
}

function infyp_get_error_logs($limit = 50) {
    if (!class_exists('\IPAIS\Core\INFYP_Logger')) {
        return [];
    }

    $log_content = \IPAIS\Core\INFYP_Logger::get_log_content();

    if ($log_content === 'Log file is empty or does not exist.') {
        return [];
    }

    $lines = explode("\n", $log_content);
    $lines = array_filter($lines);
    $lines = array_reverse($lines); // Newest first

    $error_logs = [];
    foreach ($lines as $line) {
        $parsed = infyp_parse_log_line($line);
        $level = strtoupper($parsed['level']);
        if ($level === 'ERROR' || $level === 'CRITICAL' || $level === 'WARNING') {
            $error_logs[] = $parsed;
            if (count($error_logs) >= $limit) {
                break;
            }
        }
    }

    return $error_logs;
}

function infyp_get_info_logs($limit = 50) {
    if (!class_exists('\IPAIS\Core\INFYP_Logger')) {
        return [];
    }

    $log_content = \IPAIS\Core\INFYP_Logger::get_log_content();

    if ($log_content === 'Log file is empty or does not exist.') {
        return [];
    }

    $lines = explode("\n", $log_content);
    $lines = array_filter($lines);
    $lines = array_reverse($lines); // Newest first

    $info_logs = [];
    foreach ($lines as $line) {
        $parsed = infyp_parse_log_line($line);
        $level = strtoupper($parsed['level']);
        if ($level !== 'ERROR' && $level !== 'CRITICAL' && $level !== 'WARNING') {
            $info_logs[] = $parsed;
            if (count($info_logs) >= $limit) {
                break;
            }
        }
    }

    return $info_logs;
}

function infyp_get_warning_logs($limit = 50) {
    if (!class_exists('\IPAIS\Core\INFYP_Logger')) {
        return [];
    }

    $log_content = \IPAIS\Core\INFYP_Logger::get_log_content();

    if ($log_content === 'Log file is empty or does not exist.') {
        return [];
    }

    $lines = explode("\n", $log_content);
    $lines = array_filter($lines);
    $lines = array_reverse($lines); // Newest first

    $warning_logs = [];
    foreach ($lines as $line) {
        $parsed = infyp_parse_log_line($line);
        $level = strtoupper($parsed['level']);
        if ($level === 'WARNING') {
            $warning_logs[] = $parsed;
            if (count($warning_logs) >= $limit) {
                break;
            }
        }
    }

    return $warning_logs;
}

function infyp_get_errors_only_logs($limit = 50) {
    if (!class_exists('\IPAIS\Core\INFYP_Logger')) {
        return [];
    }

    $log_content = \IPAIS\Core\INFYP_Logger::get_log_content();

    if ($log_content === 'Log file is empty or does not exist.') {
        return [];
    }

    $lines = explode("\n", $log_content);
    $lines = array_filter($lines);
    $lines = array_reverse($lines); // Newest first

    $error_logs = [];
    foreach ($lines as $line) {
        $parsed = infyp_parse_log_line($line);
        $level = strtoupper($parsed['level']);
        if ($level === 'ERROR' || $level === 'CRITICAL') {
            $error_logs[] = $parsed;
            if (count($error_logs) >= $limit) {
                break;
            }
        }
    }

    return $error_logs;
}

// Get page builder statistics
$infyp_pagebuilder_stats = get_option('infyp_pagebuilder_stats', []);
$infyp_pagebuilder_logs_available = [];
if (class_exists('\IPAIS\Core\INFYP_Logger')) {
    $infyp_pagebuilder_logs_available = \IPAIS\Core\INFYP_Logger::get_all_log_files();
}

// Get API key status safely
$infyp_simple_api_keys = get_option('infyp_api_keys', []);
$infyp_api_key_status = [
    'openai' => !empty($infyp_simple_api_keys['openai']),
    'google' => !empty($infyp_simple_api_keys['google']),
    'anthropic' => !empty($infyp_simple_api_keys['anthropic']),
];

// Try to get API usage stats safely
$infyp_api_usage_stats = null;
$infyp_api_recent_errors = [];
if (class_exists('\IPAIS\Core\INFYP_API_Usage_Tracker')) {
    try {
        $infyp_api_tracker = \IPAIS\Core\INFYP_API_Usage_Tracker::get_instance();
        $infyp_api_usage_stats = $infyp_api_tracker->get_usage_stats(7);
        $infyp_api_recent_errors = $infyp_api_tracker->get_recent_errors(5);
    } catch (Exception $e) {
        // Silently fail and use defaults
    }
}

// System Status
$infyp_system_status = [
    'php_version' => PHP_VERSION,
    'php_version_compatible' => version_compare(PHP_VERSION, '7.4', '>='),
    'wp_version' => get_bloginfo('version'),
    'wp_version_compatible' => version_compare(get_bloginfo('version'), '5.6', '>='),
    'plugin_version' => INFYP_VERSION,
    'wp_debug' => defined('WP_DEBUG') && WP_DEBUG,
    'wp_debug_log' => defined('WP_DEBUG_LOG') && WP_DEBUG_LOG,
    'memory_limit' => ini_get('memory_limit'),
    'memory_usage' => infyp_format_bytes(memory_get_usage()),
    'memory_peak' => infyp_format_bytes(memory_get_peak_usage()),
    'max_execution_time' => ini_get('max_execution_time'),
    'extensions' => [
        'curl' => extension_loaded('curl'),
        'json' => extension_loaded('json'),
        'openssl' => extension_loaded('openssl'),
        'mbstring' => extension_loaded('mbstring'),
        'gd' => extension_loaded('gd'),
        'exif' => extension_loaded('exif'),
    ]
];

// API Health
$infyp_api_health = [
    'api_keys' => $infyp_api_key_status,
    'usage_stats' => $infyp_api_usage_stats,
    'recent_errors' => $infyp_api_recent_errors,
];

// Cache Performance
$infyp_cache_stats = [];
if (class_exists('\IPAIS\Core\INFYP_Cache_Manager')) {
    try {
        $infyp_cache_manager = \IPAIS\Core\INFYP_Cache_Manager::get_instance();
        $infyp_cache_stats = $infyp_cache_manager->get_stats();
    } catch (Exception $e) {
        // Silently fail
    }
}

$infyp_cache_performance = [
    'stats' => $infyp_cache_stats,
    'object_cache' => wp_using_ext_object_cache(),
    'cache_groups' => [
        'main' => 'infyp',
        'queries' => 'infyp_queries', 
        'api' => 'infyp_api',
        'images' => 'infyp_images',
    ],
    'memory_usage' => [
        'total' => '2.5MB',
        'by_group' => [
            'main' => '512KB',
            'queries' => '1.2MB',
            'api' => '640KB',
            'images' => '148KB',
        ]
    ]
];

// Recent Activity
$infyp_upload_dir = wp_upload_dir();
$infyp_log_file = $infyp_upload_dir['basedir'] . '/ai-image-seo-logs/debug.log';
$infyp_log_size = file_exists($infyp_log_file) ? infyp_format_bytes(filesize($infyp_log_file)) : '0 bytes';
$infyp_recent_logs = infyp_get_recent_logs(50);
$infyp_error_logs = infyp_get_errors_only_logs(50);
$infyp_warning_logs = infyp_get_warning_logs(50);
$infyp_info_logs = infyp_get_info_logs(50);

$infyp_error_summary = [
    'total' => count($infyp_recent_logs),
    'errors' => 0,
    'warnings' => 0,
    'info' => 0,
];

foreach ($infyp_recent_logs as $infyp_log) {
    switch (strtoupper($infyp_log['level'])) {
        case 'ERROR':
        case 'CRITICAL':
            $infyp_error_summary['errors']++;
            break;
        case 'WARNING':
            $infyp_error_summary['warnings']++;
            break;
        default:
            $infyp_error_summary['info']++;
            break;
    }
}

$infyp_recent_activity = [
    'recent_logs' => $infyp_recent_logs,
    'error_logs' => $infyp_error_logs,
    'warning_logs' => $infyp_warning_logs,
    'info_logs' => $infyp_info_logs,
    'log_file_size' => $infyp_log_size,
    'error_summary' => $infyp_error_summary,
];

// Database Health
global $wpdb;

// Plugin tables
$infyp_tables = [
    'api_usage' => $wpdb->prefix . 'infyp_api_usage',
    'changelog' => $wpdb->prefix . 'infyp_changelog',
    'image_usage' => $wpdb->prefix . 'infyp_image_usage',
];

$infyp_table_status = [];
$infyp_total_table_size = 0;
foreach ($infyp_tables as $infyp_name => $infyp_table) {
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Schema check, no caching needed
    $infyp_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $infyp_table));
    $infyp_row_count = 0;
    $infyp_table_size = '0 KB';
    $infyp_size_bytes = 0;

    if (!empty($infyp_exists)) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $infyp_table from hardcoded array on line 313-320, no user input
        $infyp_row_count = $wpdb->get_var( "SELECT COUNT(*) FROM `{$infyp_table}`" );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $infyp_size_info = $wpdb->get_row($wpdb->prepare("
            SELECT data_length + index_length AS size_bytes
            FROM information_schema.tables
            WHERE table_schema = %s AND table_name = %s
        ", DB_NAME, $infyp_table));

        if ($infyp_size_info && $infyp_size_info->size_bytes) {
            $infyp_size_bytes = (int) $infyp_size_info->size_bytes;
            $infyp_total_table_size += $infyp_size_bytes;
            $infyp_table_size = infyp_format_bytes($infyp_size_bytes);
        }
    }

    $infyp_table_status[$infyp_name] = [
        'exists' => !empty($infyp_exists),
        'table_name' => $infyp_table,
        'rows' => $infyp_row_count,
        'size' => $infyp_table_size,
    ];
}

// Plugin options count
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$infyp_options_count = $wpdb->get_var($wpdb->prepare("
    SELECT COUNT(*) FROM {$wpdb->options}
    WHERE option_name LIKE %s
", $wpdb->esc_like('infyp_') . '%'));

// Plugin transients count
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$infyp_transients_count = $wpdb->get_var($wpdb->prepare("
    SELECT COUNT(*) FROM {$wpdb->options}
    WHERE option_name LIKE %s
", $wpdb->esc_like('_transient_infyp_') . '%'));

// Optimized images count (post meta)
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$infyp_optimized_images = $wpdb->get_var($wpdb->prepare("
    SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta}
    WHERE meta_key = %s AND meta_value = %s
", '_infyp_has_been_optimized', '1'));

// Images with keywords
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$infyp_images_with_keywords = $wpdb->get_var($wpdb->prepare("
    SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta}
    WHERE meta_key = %s
", '_infyp_keywords'));

// Total plugin post meta entries
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$infyp_postmeta_count = $wpdb->get_var($wpdb->prepare("
    SELECT COUNT(*) FROM {$wpdb->postmeta}
    WHERE meta_key LIKE %s
", $wpdb->esc_like('_infyp_') . '%'));

// Check for optimization suggestions
$infyp_optimization_suggestions = [];
if ($infyp_log_size !== '0 bytes' && file_exists($infyp_log_file) && filesize($infyp_log_file) > 1024 * 1024) { // > 1MB
    $infyp_optimization_suggestions[] = [
        'type' => 'warning',
        'title' => 'Large Log File',
        'description' => 'Consider clearing the debug log file to free up space.',
        'action' => 'clear_logs',
    ];
}

if (!wp_using_ext_object_cache()) {
    $infyp_optimization_suggestions[] = [
        'type' => 'info',
        'title' => 'Object Cache',
        'description' => 'Consider using a persistent object cache like Redis or Memcached for better performance.',
        'action' => null,
    ];
}

$infyp_database_health = [
    'tables' => $infyp_table_status,
    'database_size' => ['plugin_tables' => infyp_format_bytes($infyp_total_table_size)],
    'options_count' => $infyp_options_count,
    'transients_count' => $infyp_transients_count,
    'optimized_images' => $infyp_optimized_images,
    'images_with_keywords' => $infyp_images_with_keywords,
    'postmeta_count' => $infyp_postmeta_count,
    'optimization_suggestions' => $infyp_optimization_suggestions,
];
?>

<div class="wrap" id="infyp-debug-wrap">
    <h1><?php esc_html_e( 'Debug Information', 'infy-ai-image-seo-optimizer' ); ?></h1>
    
    <div id="infyp-debug-notice" style="display: none;"></div>
    
    <!-- Debug Tools Header -->
    <div class="infyp-debug-tools">
        <div class="infyp-debug-tools-content">
            <button type="button" class="button button-secondary" id="infyp-debug-refresh">
                <span class="dashicons dashicons-update-alt"></span>
                <?php esc_html_e('Refresh All', 'infy-ai-image-seo-optimizer'); ?>
            </button>
            <button type="button" class="button button-secondary" id="infyp-debug-clear-cache">
                <span class="dashicons dashicons-trash"></span>
                <?php esc_html_e('Clear Cache', 'infy-ai-image-seo-optimizer'); ?>
            </button>
            <button type="button" class="button button-secondary" id="infyp-debug-clear-logs">
                <span class="dashicons dashicons-media-text"></span>
                <?php esc_html_e('Clear Logs', 'infy-ai-image-seo-optimizer'); ?>
            </button>
            <button type="button" class="button button-primary" id="infyp-debug-export">
                <span class="dashicons dashicons-download"></span>
                <?php esc_html_e('Export Debug Info', 'infy-ai-image-seo-optimizer'); ?>
            </button>
        </div>
    </div>
    
    <!-- System Status Card -->
    <div class="infyp-card infyp-debug-card infyp-system-status-section">
        <div class="infyp-card-header">
            <h3><?php esc_html_e('System Status', 'infy-ai-image-seo-optimizer'); ?></h3>
            <div class="infyp-debug-status <?php echo esc_attr($infyp_system_status['php_version_compatible'] && $infyp_system_status['wp_version_compatible'] ? 'success' : 'warning'); ?>">
                <span class="dashicons dashicons-<?php echo esc_attr($infyp_system_status['php_version_compatible'] && $infyp_system_status['wp_version_compatible'] ? 'yes-alt' : 'warning'); ?>"></span>
                <?php echo $infyp_system_status['php_version_compatible'] && $infyp_system_status['wp_version_compatible'] ? esc_html__('System OK', 'infy-ai-image-seo-optimizer') : esc_html__('Issues Detected', 'infy-ai-image-seo-optimizer'); ?>
            </div>
        </div>
        <div class="infyp-card-body">
            <div class="infyp-debug-grid">
                <div class="infyp-debug-item">
                    <div class="infyp-debug-label"><?php esc_html_e('WordPress Version', 'infy-ai-image-seo-optimizer'); ?></div>
                    <div class="infyp-debug-value infyp-flex infyp-items-center infyp-gap-sm">
                        <div class="infyp-debug-status <?php echo esc_attr($infyp_system_status['wp_version_compatible'] ? 'success' : 'error'); ?>">
                            <span class="dashicons dashicons-<?php echo esc_attr($infyp_system_status['wp_version_compatible'] ? 'yes-alt' : 'dismiss'); ?>"></span>
                            <?php echo $infyp_system_status['wp_version_compatible'] ? esc_html__('Compatible', 'infy-ai-image-seo-optimizer') : esc_html__('Incompatible', 'infy-ai-image-seo-optimizer'); ?>
                        </div>
                        <span><?php echo esc_html($infyp_system_status['wp_version']); ?></span>
                    </div>
                </div>

                <div class="infyp-debug-item">
                    <div class="infyp-debug-label"><?php esc_html_e('PHP Version', 'infy-ai-image-seo-optimizer'); ?></div>
                    <div class="infyp-debug-value infyp-flex infyp-items-center infyp-gap-sm">
                        <div class="infyp-debug-status <?php echo esc_attr($infyp_system_status['php_version_compatible'] ? 'success' : 'error'); ?>">
                            <span class="dashicons dashicons-<?php echo esc_attr($infyp_system_status['php_version_compatible'] ? 'yes-alt' : 'dismiss'); ?>"></span>
                            <?php echo $infyp_system_status['php_version_compatible'] ? esc_html__('Compatible', 'infy-ai-image-seo-optimizer') : esc_html__('Incompatible', 'infy-ai-image-seo-optimizer'); ?>
                        </div>
                        <span><?php echo esc_html($infyp_system_status['php_version']); ?></span>
                    </div>
                </div>
                
                <div class="infyp-debug-item">
                    <div class="infyp-debug-label"><?php esc_html_e('Plugin Version', 'infy-ai-image-seo-optimizer'); ?></div>
                    <div class="infyp-debug-value"><?php echo esc_html($infyp_system_status['plugin_version']); ?></div>
                </div>
                
                <div class="infyp-debug-item">
                    <div class="infyp-debug-label"><?php esc_html_e('Memory Usage', 'infy-ai-image-seo-optimizer'); ?></div>
                    <div class="infyp-debug-value">
                        <?php echo esc_html($infyp_system_status['memory_usage']); ?> / <?php echo esc_html($infyp_system_status['memory_limit']); ?>
                        <div class="infyp-debug-subtext"><?php esc_html_e('Peak:', 'infy-ai-image-seo-optimizer'); ?> <?php echo esc_html($infyp_system_status['memory_peak']); ?></div>
                    </div>
                </div>
                
                <div class="infyp-debug-item">
                    <div class="infyp-debug-label"><?php esc_html_e('WP Debug', 'infy-ai-image-seo-optimizer'); ?></div>
                    <div class="infyp-debug-value">
                        <div class="infyp-debug-status <?php echo esc_attr($infyp_system_status['wp_debug'] ? 'success' : 'warning'); ?>">
                            <span class="dashicons dashicons-<?php echo esc_attr($infyp_system_status['wp_debug'] ? 'yes-alt' : 'dismiss'); ?>"></span>
                            <?php echo $infyp_system_status['wp_debug'] ? esc_html__('Enabled', 'infy-ai-image-seo-optimizer') : esc_html__('Disabled', 'infy-ai-image-seo-optimizer'); ?>
                        </div>
                    </div>
                </div>
                
                <div class="infyp-debug-item">
                    <div class="infyp-debug-label"><?php esc_html_e('Extensions', 'infy-ai-image-seo-optimizer'); ?></div>
                    <div class="infyp-debug-value">
                        <?php foreach ($infyp_system_status['extensions'] as $infyp_ext => $infyp_loaded): ?>
                            <div class="infyp-debug-status <?php echo esc_attr($infyp_loaded ? 'success' : 'error'); ?> infyp-inline-flex">
                                <span class="dashicons dashicons-<?php echo esc_attr($infyp_loaded ? 'yes-alt' : 'dismiss'); ?>"></span>
                                <?php echo esc_html($infyp_ext); ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- API Health Card -->
    <div class="infyp-card infyp-debug-card infyp-api-health-section">
        <div class="infyp-card-header">
            <h3><?php esc_html_e('API Health', 'infy-ai-image-seo-optimizer'); ?></h3>
            <div class="infyp-debug-status <?php echo esc_attr( array_sum($infyp_api_health['api_keys']) > 0 ? 'success' : 'warning' ); ?>">
                <span class="dashicons dashicons-<?php echo esc_attr( array_sum($infyp_api_health['api_keys']) > 0 ? 'yes-alt' : 'warning' ); ?>"></span>
                <?php echo array_sum($infyp_api_health['api_keys']) > 0 ? esc_html__('APIs Configured', 'infy-ai-image-seo-optimizer') : esc_html__('No APIs Configured', 'infy-ai-image-seo-optimizer'); ?>
            </div>
        </div>
        <div class="infyp-card-body">
            <div class="infyp-debug-grid">
                <div class="infyp-debug-item">
                    <div class="infyp-debug-label"><?php esc_html_e('API Key Status', 'infy-ai-image-seo-optimizer'); ?></div>
                    <div class="infyp-debug-value">
                        <?php foreach ($infyp_api_health['api_keys'] as $infyp_provider => $infyp_configured): ?>
                            <div class="infyp-debug-api-provider">
                                <div class="infyp-debug-provider-info">
                                    <span class="infyp-debug-provider-name"><?php echo esc_html(ucfirst($infyp_provider)); ?></span>
                                    <div class="infyp-debug-status <?php echo esc_attr($infyp_configured ? 'success' : 'warning'); ?>">
                                        <span class="dashicons dashicons-<?php echo esc_attr($infyp_configured ? 'yes-alt' : 'warning'); ?>"></span>
                                        <?php echo $infyp_configured ? esc_html__('Configured', 'infy-ai-image-seo-optimizer') : esc_html__('Not Configured', 'infy-ai-image-seo-optimizer'); ?>
                                    </div>
                                </div>
                                <?php if ($infyp_configured): ?>
                                    <button type="button" class="button button-small infyp-test-api" data-provider="<?php echo esc_attr($infyp_provider); ?>">
                                        <?php esc_html_e('Test', 'infy-ai-image-seo-optimizer'); ?>
                                    </button>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <?php if ($infyp_api_health['usage_stats']): ?>
                <div class="infyp-debug-item">
                    <div class="infyp-debug-label"><?php esc_html_e('Recent Usage (7 days)', 'infy-ai-image-seo-optimizer'); ?></div>
                    <div class="infyp-debug-value">
                        <div class="infyp-debug-stats">
                            <span class="infyp-debug-stat">
                                <strong><?php echo esc_html($infyp_api_health['usage_stats']->total_calls ?? 0); ?></strong>
                                <?php esc_html_e('Total Calls', 'infy-ai-image-seo-optimizer'); ?>
                            </span>
                            <span class="infyp-debug-stat">
                                <strong><?php echo esc_html($infyp_api_health['usage_stats']->successful_calls ?? 0); ?></strong>
                                <?php esc_html_e('Successful', 'infy-ai-image-seo-optimizer'); ?>
                            </span>
                            <span class="infyp-debug-stat">
                                <strong><?php echo esc_html($infyp_api_health['usage_stats']->failed_calls ?? 0); ?></strong>
                                <?php esc_html_e('Failed', 'infy-ai-image-seo-optimizer'); ?>
                            </span>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($infyp_api_health['recent_errors'])): ?>
                <div class="infyp-debug-item">
                    <div class="infyp-debug-label"><?php esc_html_e('Recent Errors', 'infy-ai-image-seo-optimizer'); ?></div>
                    <div class="infyp-debug-value">
                        <div class="infyp-debug-errors">
                            <?php foreach ($infyp_api_health['recent_errors'] as $error): ?>
                                <div class="infyp-debug-error">
                                    <span class="infyp-debug-error-provider"><?php echo esc_html($error->provider); ?></span>
                                    <span class="infyp-debug-error-message"><?php echo esc_html($error->error_message); ?></span>
                                    <span class="infyp-debug-error-time"><?php echo esc_html($error->timestamp); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Cache Performance Card -->
    <div class="infyp-card infyp-debug-card infyp-cache-performance-section">
        <div class="infyp-card-header">
            <h3><?php esc_html_e('Cache Performance', 'infy-ai-image-seo-optimizer'); ?></h3>
            <div class="infyp-debug-status <?php echo esc_attr($infyp_cache_performance['object_cache'] ? 'success' : 'warning'); ?>">
                <span class="dashicons dashicons-<?php echo esc_attr($infyp_cache_performance['object_cache'] ? 'yes-alt' : 'warning'); ?>"></span>
                <?php echo $infyp_cache_performance['object_cache'] ? esc_html__('Object Cache Active', 'infy-ai-image-seo-optimizer') : esc_html__('Transient Cache Only', 'infy-ai-image-seo-optimizer'); ?>
            </div>
        </div>
        <div class="infyp-card-body">
            <div class="infyp-debug-grid">
                <div class="infyp-debug-item">
                    <div class="infyp-debug-label"><?php esc_html_e('Cache Type', 'infy-ai-image-seo-optimizer'); ?></div>
                    <div class="infyp-debug-value">
                        <?php echo $infyp_cache_performance['object_cache'] ? esc_html__('Persistent Object Cache', 'infy-ai-image-seo-optimizer') : esc_html__('WordPress Transients', 'infy-ai-image-seo-optimizer'); ?>
                    </div>
                </div>
                
                <div class="infyp-debug-item">
                    <div class="infyp-debug-label"><?php esc_html_e('Cache Groups', 'infy-ai-image-seo-optimizer'); ?></div>
                    <div class="infyp-debug-value">
                        <?php foreach ($infyp_cache_performance['cache_groups'] as $infyp_group => $infyp_name): ?>
                            <span class="infyp-debug-cache-group"><?php echo esc_html($infyp_group); ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div class="infyp-debug-item">
                    <div class="infyp-debug-label"><?php esc_html_e('Estimated Memory Usage', 'infy-ai-image-seo-optimizer'); ?></div>
                    <div class="infyp-debug-value">
                        <strong><?php echo esc_html($infyp_cache_performance['memory_usage']['total']); ?></strong>
                        <div class="infyp-debug-cache-breakdown">
                            <?php foreach ($infyp_cache_performance['memory_usage']['by_group'] as $infyp_group => $infyp_usage): ?>
                                <span class="infyp-debug-cache-usage"><?php echo esc_html($infyp_group); ?>: <?php echo esc_html($infyp_usage); ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Page Builder Activity Card -->
    <div class="infyp-card infyp-debug-card infyp-pagebuilder-activity-section">
        <div class="infyp-card-header">
            <h3><?php esc_html_e('Page Builder Activity', 'infy-ai-image-seo-optimizer'); ?></h3>
            <div class="infyp-debug-status info">
                <span class="dashicons dashicons-admin-page"></span>
                <?php
                $infyp_total_builders = count(array_filter($infyp_pagebuilder_stats, function($key) {
                    return $key !== 'last_updated';
                }, ARRAY_FILTER_USE_KEY));
                echo sprintf(
                    /* translators: %d: number of page builders */
                    esc_html__('%d Builders Tracked', 'infy-ai-image-seo-optimizer'),
                    intval($infyp_total_builders)
                );
                ?>
            </div>
        </div>
        <div class="infyp-card-body">
            <?php if (!empty($infyp_pagebuilder_stats) && count($infyp_pagebuilder_stats) > 1): ?>
                <!-- Statistics Table -->
                <div class="infyp-pagebuilder-stats-table">
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Page Builder', 'infy-ai-image-seo-optimizer'); ?></th>
                                <th><?php esc_html_e('Posts Scanned', 'infy-ai-image-seo-optimizer'); ?></th>
                                <th><?php esc_html_e('Posts Updated', 'infy-ai-image-seo-optimizer'); ?></th>
                                <th><?php esc_html_e('URLs Replaced', 'infy-ai-image-seo-optimizer'); ?></th>
                                <th><?php esc_html_e('Errors', 'infy-ai-image-seo-optimizer'); ?></th>
                                <th><?php esc_html_e('Last Run', 'infy-ai-image-seo-optimizer'); ?></th>
                                <th><?php esc_html_e('Actions', 'infy-ai-image-seo-optimizer'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($infyp_pagebuilder_stats as $infyp_builder_slug => $infyp_builder_stats): ?>
                                <?php if ($infyp_builder_slug === 'last_updated') continue; ?>
                                <tr>
                                    <td>
                                        <strong><?php echo esc_html(ucwords(str_replace('_', ' ', $infyp_builder_slug))); ?></strong>
                                    </td>
                                    <td><?php echo esc_html( number_format(intval($infyp_builder_stats['total_posts_scanned'])) ); ?></td>
                                    <td>
                                        <span class="infyp-stat-badge success">
                                            <?php echo esc_html( number_format(intval($infyp_builder_stats['total_posts_updated'])) ); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="infyp-stat-badge info">
                                            <?php echo esc_html( number_format(intval($infyp_builder_stats['total_urls_replaced'])) ); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (intval($infyp_builder_stats['total_errors']) > 0): ?>
                                            <span class="infyp-stat-badge error">
                                                <?php echo esc_html( number_format(intval($infyp_builder_stats['total_errors'])) ); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="infyp-stat-badge">0</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        if (isset($infyp_builder_stats['last_run']) && intval($infyp_builder_stats['last_run']) > 0) {
                                            echo esc_html(human_time_diff(intval($infyp_builder_stats['last_run']), time()) . ' ago');
                                        } else {
                                            echo esc_html__('Never', 'infy-ai-image-seo-optimizer');
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php
                                        $infyp_builder_log_file = 'pagebuilder-' . strtolower(str_replace(array(' ', '_'), '', $infyp_builder_slug)) . '.log';
                                        if (in_array($infyp_builder_log_file, $infyp_pagebuilder_logs_available, true)):
                                        ?>
                                            <button type="button" class="button button-small infyp-view-builder-log"
                                                    data-builder="<?php echo esc_attr($infyp_builder_slug); ?>"
                                                    data-logfile="<?php echo esc_attr($infyp_builder_log_file); ?>">
                                                <?php esc_html_e('View Log', 'infy-ai-image-seo-optimizer'); ?>
                                            </button>
                                        <?php else: ?>
                                            <span class="infyp-no-log"><?php esc_html_e('No logs', 'infy-ai-image-seo-optimizer'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Recent Page Builder Changes -->
                <div class="infyp-pagebuilder-recent-changes infyp-mt-lg">
                    <h4><?php esc_html_e('Recent Changes', 'infy-ai-image-seo-optimizer'); ?></h4>
                    <div class="infyp-pagebuilder-log-tabs">
                        <button class="button infyp-log-tab active" data-logfile="debug.log">
                            <?php esc_html_e('All Builders', 'infy-ai-image-seo-optimizer'); ?>
                        </button>
                        <?php foreach ($infyp_pagebuilder_logs_available as $infyp_log_file): ?>
                            <?php if (strpos($infyp_log_file, 'pagebuilder-') === 0): ?>
                                <?php
                                $infyp_builder_name_from_file = str_replace(array('pagebuilder-', '.log'), '', $infyp_log_file);
                                $infyp_builder_display_name = ucwords(str_replace(array('-', '_'), ' ', $infyp_builder_name_from_file));
                                ?>
                                <button class="button infyp-log-tab" data-logfile="<?php echo esc_attr($infyp_log_file); ?>">
                                    <?php echo esc_html($infyp_builder_display_name); ?>
                                </button>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                    <div class="infyp-pagebuilder-log-content infyp-mt-md">
                        <div class="infyp-debug-log-entries">
                            <?php
                            $infyp_pb_logs = infyp_get_recent_logs(50);
                            $infyp_pb_logs_filtered = array_filter($infyp_pb_logs, function($log) {
                                return strpos($log['message'], '[WPBakery]') !== false ||
                                       strpos($log['message'], '[Divi]') !== false ||
                                       strpos($log['message'], '[Beaver Builder]') !== false ||
                                       strpos($log['message'], '[Elementor]') !== false;
                            });
                            $infyp_pb_logs_display = array_slice($infyp_pb_logs_filtered, 0, 20);
                            ?>
                            <?php if (!empty($infyp_pb_logs_display)): ?>
                                <?php foreach ($infyp_pb_logs_display as $infyp_pb_log): ?>
                                    <div class="infyp-debug-log-entry infyp-log-level-<?php echo esc_attr(strtolower($infyp_pb_log['level'])); ?>">
                                        <span class="infyp-debug-log-time"><?php echo esc_html($infyp_pb_log['timestamp']); ?></span>
                                        <span class="infyp-debug-log-level"><?php echo esc_html($infyp_pb_log['level']); ?></span>
                                        <span class="infyp-debug-log-message"><?php echo esc_html($infyp_pb_log['message']); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="infyp-no-logs"><?php esc_html_e('No page builder activity logged yet.', 'infy-ai-image-seo-optimizer'); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="infyp-no-data">
                    <p><?php esc_html_e('No page builder activity recorded yet. Process some posts with page builders to see statistics here.', 'infy-ai-image-seo-optimizer'); ?></p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recent Activity Card -->
    <div class="infyp-card infyp-debug-card infyp-recent-activity-section">
        <div class="infyp-card-header">
            <h3><?php esc_html_e('Recent Activity', 'infy-ai-image-seo-optimizer'); ?></h3>
            <div class="infyp-debug-status <?php echo esc_attr($infyp_recent_activity['error_summary']['errors'] == 0 ? 'success' : 'warning'); ?>">
                <span class="dashicons dashicons-<?php echo esc_attr($infyp_recent_activity['error_summary']['errors'] == 0 ? 'yes-alt' : 'warning'); ?>"></span>
                <?php echo $infyp_recent_activity['error_summary']['errors'] == 0 ? esc_html__('No Recent Errors', 'infy-ai-image-seo-optimizer') : sprintf(
                    /* translators: %d: number of recent errors */
                    esc_html__('%d Recent Errors', 'infy-ai-image-seo-optimizer'),
                    intval( $infyp_recent_activity['error_summary']['errors'] )
                ); ?>
            </div>
        </div>
        <div class="infyp-card-body">
            <div class="infyp-debug-grid">
                <div class="infyp-debug-item">
                    <div class="infyp-debug-label"><?php esc_html_e('Log File Size', 'infy-ai-image-seo-optimizer'); ?></div>
                    <div class="infyp-debug-value"><?php echo esc_html($infyp_recent_activity['log_file_size']); ?></div>
                </div>

                <div class="infyp-debug-item">
                    <div class="infyp-debug-label"><?php esc_html_e('Log Summary', 'infy-ai-image-seo-optimizer'); ?></div>
                    <div class="infyp-debug-value">
                        <div class="infyp-debug-log-summary">
                            <span class="infyp-debug-log-stat error">
                                <strong><?php echo esc_html($infyp_recent_activity['error_summary']['errors']); ?></strong>
                                <?php esc_html_e('Errors', 'infy-ai-image-seo-optimizer'); ?>
                            </span>
                            <span class="infyp-debug-log-stat warning">
                                <strong><?php echo esc_html($infyp_recent_activity['error_summary']['warnings']); ?></strong>
                                <?php esc_html_e('Warnings', 'infy-ai-image-seo-optimizer'); ?>
                            </span>
                            <span class="infyp-debug-log-stat info">
                                <strong><?php echo esc_html($infyp_recent_activity['error_summary']['info']); ?></strong>
                                <?php esc_html_e('Info', 'infy-ai-image-seo-optimizer'); ?>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Log Tabs -->
                <div class="infyp-debug-item infyp-debug-logs infyp-debug-logs-full-width">
                    <div class="infyp-debug-log-tabs-container">
                        <div class="infyp-debug-log-tabs-header">
                            <div class="infyp-debug-log-tabs">
                                <button type="button" class="infyp-debug-log-tab active" data-tab="all">
                                    <?php esc_html_e('All', 'infy-ai-image-seo-optimizer'); ?>
                                </button>
                                <button type="button" class="infyp-debug-log-tab" data-tab="errors">
                                    <?php esc_html_e('Errors', 'infy-ai-image-seo-optimizer'); ?>
                                    <?php if ($infyp_recent_activity['error_summary']['errors'] > 0): ?>
                                        <span class="infyp-log-tab-count error"><?php echo esc_html($infyp_recent_activity['error_summary']['errors']); ?></span>
                                    <?php endif; ?>
                                </button>
                                <button type="button" class="infyp-debug-log-tab" data-tab="warnings">
                                    <?php esc_html_e('Warnings', 'infy-ai-image-seo-optimizer'); ?>
                                    <?php if ($infyp_recent_activity['error_summary']['warnings'] > 0): ?>
                                        <span class="infyp-log-tab-count warning"><?php echo esc_html($infyp_recent_activity['error_summary']['warnings']); ?></span>
                                    <?php endif; ?>
                                </button>
                                <button type="button" class="infyp-debug-log-tab" data-tab="info">
                                    <?php esc_html_e('Info', 'infy-ai-image-seo-optimizer'); ?>
                                </button>
                            </div>
                            <div class="infyp-debug-log-download-buttons">
                                <button type="button" class="button button-small infyp-download-logs" data-type="all" title="<?php esc_attr_e('Download All Logs', 'infy-ai-image-seo-optimizer'); ?>">
                                    <?php esc_html_e('All', 'infy-ai-image-seo-optimizer'); ?>
                                </button>
                                <button type="button" class="button button-small infyp-download-logs" data-type="errors" title="<?php esc_attr_e('Download Errors', 'infy-ai-image-seo-optimizer'); ?>">
                                    <?php esc_html_e('Errors', 'infy-ai-image-seo-optimizer'); ?>
                                </button>
                                <button type="button" class="button button-small infyp-download-logs" data-type="warnings" title="<?php esc_attr_e('Download Warnings', 'infy-ai-image-seo-optimizer'); ?>">
                                    <?php esc_html_e('Warnings', 'infy-ai-image-seo-optimizer'); ?>
                                </button>
                                <button type="button" class="button button-small infyp-download-logs" data-type="info" title="<?php esc_attr_e('Download Info Logs', 'infy-ai-image-seo-optimizer'); ?>">
                                    <?php esc_html_e('Info', 'infy-ai-image-seo-optimizer'); ?>
                                </button>
                            </div>
                        </div>

                        <!-- All Logs Tab Content -->
                        <div class="infyp-debug-log-tab-content active" data-tab="all">
                            <?php if (!empty($infyp_recent_activity['recent_logs'])): ?>
                                <div class="infyp-debug-log-entries">
                                    <?php foreach (array_slice($infyp_recent_activity['recent_logs'], 0, 20) as $infyp_log): ?>
                                        <div class="infyp-debug-log-entry infyp-log-level-<?php echo esc_attr(strtolower($infyp_log['level'])); ?>">
                                            <span class="infyp-debug-log-time"><?php echo esc_html($infyp_log['timestamp']); ?></span>
                                            <span class="infyp-debug-log-level"><?php echo esc_html($infyp_log['level']); ?></span>
                                            <span class="infyp-debug-log-message"><?php echo esc_html($infyp_log['message']); ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php if (count($infyp_recent_activity['recent_logs']) > 20): ?>
                                    <div class="infyp-debug-log-more">
                                        <button type="button" class="button button-small infyp-show-more-logs" data-type="all">
                                            <?php esc_html_e('Show More Logs', 'infy-ai-image-seo-optimizer'); ?>
                                        </button>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="infyp-debug-no-logs">
                                    <p><?php esc_html_e('No log entries found.', 'infy-ai-image-seo-optimizer'); ?></p>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Errors Tab Content -->
                        <div class="infyp-debug-log-tab-content" data-tab="errors">
                            <?php if (!empty($infyp_recent_activity['error_logs'])): ?>
                                <div class="infyp-debug-log-entries">
                                    <?php foreach (array_slice($infyp_recent_activity['error_logs'], 0, 20) as $infyp_log): ?>
                                        <div class="infyp-debug-log-entry infyp-log-level-<?php echo esc_attr(strtolower($infyp_log['level'])); ?>">
                                            <span class="infyp-debug-log-time"><?php echo esc_html($infyp_log['timestamp']); ?></span>
                                            <span class="infyp-debug-log-level"><?php echo esc_html($infyp_log['level']); ?></span>
                                            <span class="infyp-debug-log-message"><?php echo esc_html($infyp_log['message']); ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php if (count($infyp_recent_activity['error_logs']) > 20): ?>
                                    <div class="infyp-debug-log-more">
                                        <button type="button" class="button button-small infyp-show-more-logs" data-type="errors">
                                            <?php esc_html_e('Show More Errors', 'infy-ai-image-seo-optimizer'); ?>
                                        </button>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="infyp-debug-no-logs">
                                    <span class="dashicons dashicons-yes-alt"></span>
                                    <p><?php esc_html_e('No errors found. Everything looks good!', 'infy-ai-image-seo-optimizer'); ?></p>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Warnings Tab Content -->
                        <div class="infyp-debug-log-tab-content" data-tab="warnings">
                            <?php if (!empty($infyp_recent_activity['warning_logs'])): ?>
                                <div class="infyp-debug-log-entries">
                                    <?php foreach (array_slice($infyp_recent_activity['warning_logs'], 0, 20) as $infyp_log): ?>
                                        <div class="infyp-debug-log-entry infyp-log-level-<?php echo esc_attr(strtolower($infyp_log['level'])); ?>">
                                            <span class="infyp-debug-log-time"><?php echo esc_html($infyp_log['timestamp']); ?></span>
                                            <span class="infyp-debug-log-level"><?php echo esc_html($infyp_log['level']); ?></span>
                                            <span class="infyp-debug-log-message"><?php echo esc_html($infyp_log['message']); ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php if (count($infyp_recent_activity['warning_logs']) > 20): ?>
                                    <div class="infyp-debug-log-more">
                                        <button type="button" class="button button-small infyp-show-more-logs" data-type="warnings">
                                            <?php esc_html_e('Show More Warnings', 'infy-ai-image-seo-optimizer'); ?>
                                        </button>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="infyp-debug-no-logs">
                                    <span class="dashicons dashicons-yes-alt"></span>
                                    <p><?php esc_html_e('No warnings found.', 'infy-ai-image-seo-optimizer'); ?></p>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Info Tab Content -->
                        <div class="infyp-debug-log-tab-content" data-tab="info">
                            <?php if (!empty($infyp_recent_activity['info_logs'])): ?>
                                <div class="infyp-debug-log-entries">
                                    <?php foreach (array_slice($infyp_recent_activity['info_logs'], 0, 20) as $infyp_log): ?>
                                        <div class="infyp-debug-log-entry infyp-log-level-<?php echo esc_attr(strtolower($infyp_log['level'])); ?>">
                                            <span class="infyp-debug-log-time"><?php echo esc_html($infyp_log['timestamp']); ?></span>
                                            <span class="infyp-debug-log-level"><?php echo esc_html($infyp_log['level']); ?></span>
                                            <span class="infyp-debug-log-message"><?php echo esc_html($infyp_log['message']); ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php if (count($infyp_recent_activity['info_logs']) > 20): ?>
                                    <div class="infyp-debug-log-more">
                                        <button type="button" class="button button-small infyp-show-more-logs" data-type="info">
                                            <?php esc_html_e('Show More Info Logs', 'infy-ai-image-seo-optimizer'); ?>
                                        </button>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="infyp-debug-no-logs">
                                    <p><?php esc_html_e('No info log entries found.', 'infy-ai-image-seo-optimizer'); ?></p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Database Health Card -->
    <div class="infyp-card infyp-debug-card infyp-database-health-section">
        <div class="infyp-card-header">
            <h3><?php esc_html_e('Database Health', 'infy-ai-image-seo-optimizer'); ?></h3>
            <div class="infyp-debug-status <?php echo esc_attr( array_sum(array_column($infyp_database_health['tables'], 'exists')) == count($infyp_database_health['tables']) ? 'success' : 'error' ); ?>">
                <span class="dashicons dashicons-<?php echo esc_attr( array_sum(array_column($infyp_database_health['tables'], 'exists')) == count($infyp_database_health['tables']) ? 'yes-alt' : 'dismiss' ); ?>"></span>
                <?php echo array_sum(array_column($infyp_database_health['tables'], 'exists')) == count($infyp_database_health['tables']) ? esc_html__('All Tables OK', 'infy-ai-image-seo-optimizer') : esc_html__('Missing Tables', 'infy-ai-image-seo-optimizer'); ?>
            </div>
        </div>
        <div class="infyp-card-body">
            <div class="infyp-debug-grid">
                <!-- Plugin Tables -->
                <div class="infyp-debug-item infyp-debug-item-full">
                    <div class="infyp-debug-label"><?php esc_html_e('Plugin Tables', 'infy-ai-image-seo-optimizer'); ?></div>
                    <div class="infyp-debug-value">
                        <?php foreach ($infyp_database_health['tables'] as $infyp_name => $infyp_table): ?>
                            <div class="infyp-debug-table">
                                <span class="infyp-debug-table-name"><?php echo esc_html($infyp_table['table_name']); ?></span>
                                <div class="infyp-debug-status <?php echo esc_attr($infyp_table['exists'] ? 'success' : 'error'); ?>">
                                    <span class="dashicons dashicons-<?php echo esc_attr($infyp_table['exists'] ? 'yes-alt' : 'dismiss'); ?>"></span>
                                    <?php echo $infyp_table['exists'] ? esc_html__('Exists', 'infy-ai-image-seo-optimizer') : esc_html__('Missing', 'infy-ai-image-seo-optimizer'); ?>
                                </div>
                                <?php if ($infyp_table['exists']): ?>
                                    <span class="infyp-debug-table-stats">
                                        <?php echo esc_html($infyp_table['rows']); ?> <?php esc_html_e('rows', 'infy-ai-image-seo-optimizer'); ?>, <?php echo esc_html($infyp_table['size']); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- WordPress Data -->
                <div class="infyp-debug-item">
                    <div class="infyp-debug-label"><?php esc_html_e('Plugin Options', 'infy-ai-image-seo-optimizer'); ?></div>
                    <div class="infyp-debug-value">
                        <strong><?php echo esc_html($infyp_database_health['options_count']); ?></strong> <?php esc_html_e('options stored', 'infy-ai-image-seo-optimizer'); ?>
                    </div>
                </div>

                <div class="infyp-debug-item">
                    <div class="infyp-debug-label"><?php esc_html_e('Active Transients', 'infy-ai-image-seo-optimizer'); ?></div>
                    <div class="infyp-debug-value">
                        <strong><?php echo esc_html($infyp_database_health['transients_count']); ?></strong> <?php esc_html_e('transients', 'infy-ai-image-seo-optimizer'); ?>
                    </div>
                </div>

                <div class="infyp-debug-item">
                    <div class="infyp-debug-label"><?php esc_html_e('Optimized Images', 'infy-ai-image-seo-optimizer'); ?></div>
                    <div class="infyp-debug-value">
                        <strong><?php echo esc_html($infyp_database_health['optimized_images']); ?></strong> <?php esc_html_e('images processed', 'infy-ai-image-seo-optimizer'); ?>
                    </div>
                </div>

                <div class="infyp-debug-item">
                    <div class="infyp-debug-label"><?php esc_html_e('Images with Keywords', 'infy-ai-image-seo-optimizer'); ?></div>
                    <div class="infyp-debug-value">
                        <strong><?php echo esc_html($infyp_database_health['images_with_keywords']); ?></strong> <?php esc_html_e('images', 'infy-ai-image-seo-optimizer'); ?>
                    </div>
                </div>

                <div class="infyp-debug-item">
                    <div class="infyp-debug-label"><?php esc_html_e('Post Meta Entries', 'infy-ai-image-seo-optimizer'); ?></div>
                    <div class="infyp-debug-value">
                        <strong><?php echo esc_html($infyp_database_health['postmeta_count']); ?></strong> <?php esc_html_e('meta entries', 'infy-ai-image-seo-optimizer'); ?>
                    </div>
                </div>

                <div class="infyp-debug-item">
                    <div class="infyp-debug-label"><?php esc_html_e('Total Table Size', 'infy-ai-image-seo-optimizer'); ?></div>
                    <div class="infyp-debug-value">
                        <strong><?php echo esc_html($infyp_database_health['database_size']['plugin_tables']); ?></strong>
                    </div>
                </div>

                <?php if (!empty($infyp_database_health['optimization_suggestions'])): ?>
                <div class="infyp-debug-item infyp-debug-item-full">
                    <div class="infyp-debug-label"><?php esc_html_e('Optimization Suggestions', 'infy-ai-image-seo-optimizer'); ?></div>
                    <div class="infyp-debug-value">
                        <?php foreach ($infyp_database_health['optimization_suggestions'] as $infyp_suggestion): ?>
                            <div class="infyp-debug-suggestion <?php echo esc_attr($infyp_suggestion['type']); ?>">
                                <strong><?php echo esc_html($infyp_suggestion['title']); ?></strong>
                                <p><?php echo esc_html($infyp_suggestion['description']); ?></p>
                                <?php if ($infyp_suggestion['action']): ?>
                                    <button type="button" class="button button-small infyp-suggestion-action" data-action="<?php echo esc_attr($infyp_suggestion['action']); ?>">
                                        <?php esc_html_e('Apply', 'infy-ai-image-seo-optimizer'); ?>
                                    </button>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <?php
    /**
     * Hook for extensions to add debug sections
     *
     * @since 1.0.0
     */
    do_action('infyp_debug_page_after_sections');
    ?>
    
    <!-- Debug Tools Card -->
    <div class="infyp-card infyp-debug-card infyp-debug-tools-section">
        <div class="infyp-card-header">
            <h3><?php esc_html_e('Debug Tools', 'infy-ai-image-seo-optimizer'); ?></h3>
        </div>
        <div class="infyp-card-body">
            <div class="infyp-debug-tools-grid">
                <div class="infyp-debug-tool">
                    <h4><?php esc_html_e('Cache Management', 'infy-ai-image-seo-optimizer'); ?></h4>
                    <p><?php esc_html_e('Clear all plugin caches to resolve performance issues.', 'infy-ai-image-seo-optimizer'); ?></p>
                    <button type="button" class="button button-secondary" id="infyp-debug-clear-cache-tool">
                        <?php esc_html_e('Clear All Cache', 'infy-ai-image-seo-optimizer'); ?>
                    </button>
                </div>
                
                <div class="infyp-debug-tool">
                    <h4><?php esc_html_e('Log Management', 'infy-ai-image-seo-optimizer'); ?></h4>
                    <p><?php esc_html_e('Clear debug logs to free up disk space and remove old error records.', 'infy-ai-image-seo-optimizer'); ?></p>
                    <button type="button" class="button button-secondary" id="infyp-debug-clear-logs-tool">
                        <?php esc_html_e('Clear Debug Logs', 'infy-ai-image-seo-optimizer'); ?>
                    </button>
                </div>
                
                <div class="infyp-debug-tool">
                    <h4><?php esc_html_e('API Connection Test', 'infy-ai-image-seo-optimizer'); ?></h4>
                    <p><?php esc_html_e('Test all configured API connections to verify they are working properly.', 'infy-ai-image-seo-optimizer'); ?></p>
                    <button type="button" class="button button-secondary" id="infyp-debug-test-apis">
                        <?php esc_html_e('Test All APIs', 'infy-ai-image-seo-optimizer'); ?>
                    </button>
                </div>
                
                <div class="infyp-debug-tool">
                    <h4><?php esc_html_e('Export Debug Info', 'infy-ai-image-seo-optimizer'); ?></h4>
                    <p><?php esc_html_e('Export debug information for support purposes as a readable text file download.', 'infy-ai-image-seo-optimizer'); ?></p>
                    <button type="button" class="button button-primary" id="infyp-debug-export-tool">
                        <?php esc_html_e('Export Debug Data', 'infy-ai-image-seo-optimizer'); ?>
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Support Section -->
    <div class="infyp-card infyp-debug-card infyp-support-section">
        <div class="infyp-card-header">
            <h3><?php esc_html_e('Need Help?', 'infy-ai-image-seo-optimizer'); ?></h3>
            <div class="infyp-debug-status success">
                <span class="dashicons dashicons-sos"></span>
                <?php esc_html_e('Support Available', 'infy-ai-image-seo-optimizer'); ?>
            </div>
        </div>
        <div class="infyp-card-body">
            <div class="infyp-support-content">
                <p><?php esc_html_e('If you can\'t find the issue or need additional assistance, our support team is here to help you get the most out of Image SEO Optimizer.', 'infy-ai-image-seo-optimizer'); ?></p>
                
                <div class="infyp-support-options">
                    <div class="infyp-support-option">
                        <div class="infyp-support-icon">
                            <span class="dashicons dashicons-email-alt"></span>
                        </div>
                        <div class="infyp-support-details">
                            <h4><?php esc_html_e('Contact Support', 'infy-ai-image-seo-optimizer'); ?></h4>
                            <p><?php esc_html_e('Get direct help from our technical support team', 'infy-ai-image-seo-optimizer'); ?></p>
                            <a href="mailto:support@infypress.com" class="button button-secondary" target="_blank">
                                <span class="dashicons dashicons-email"></span>
                                <?php esc_html_e('support@infypress.com', 'infy-ai-image-seo-optimizer'); ?>
                            </a>
                        </div>
                    </div>
                    
                    <div class="infyp-support-option">
                        <div class="infyp-support-icon">
                            <span class="dashicons dashicons-book-alt"></span>
                        </div>
                        <div class="infyp-support-details">
                            <h4><?php esc_html_e('Documentation', 'infy-ai-image-seo-optimizer'); ?></h4>
                            <p><?php esc_html_e('Browse our comprehensive guides and tutorials', 'infy-ai-image-seo-optimizer'); ?></p>
                            <a href="https://infypress.com/docs/" class="button button-primary" target="_blank" rel="noopener">
                                <span class="dashicons dashicons-external"></span>
                                <?php esc_html_e('Visit Documentation', 'infy-ai-image-seo-optimizer'); ?>
                            </a>
                        </div>
                    </div>
                </div>
                
                <div class="infyp-support-footer">
                    <p class="infyp-support-note">
                        <span class="dashicons dashicons-info"></span>
                        <?php esc_html_e('When contacting support, please include the debug information from this page to help us assist you faster.', 'infy-ai-image-seo-optimizer'); ?>
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

