<?php
/**
 * Advanced Logging System for AI Image SEO Plugin
 *
 * This file contains the INFYP_Logger class which provides comprehensive logging
 * capabilities with automatic rotation, dual-tier logging, and structured log
 * management for debugging and monitoring.
 *
 * Logging Features:
 * - Dual-tier logging: Critical events always logged, debug events only when WP_DEBUG enabled
 * - Automatic log rotation at 10 MB file size
 * - Multiple rotation retention (keeps last 3 rotations)
 * - Separate logs for page builders (WPBakery, Divi, Elementor, etc.)
 * - Structured log entries with timestamps and severity levels
 * - Contextual logging with additional metadata
 * - Log parsing and retrieval for admin viewing
 * - Log clearing and management
 *
 * Log Levels:
 * - CRITICAL: Always logged, most severe issues
 * - ERROR: Always logged, error conditions
 * - WARNING: Always logged, warning conditions
 * - EXTERNAL_URL: Always logged, external resource tracking
 * - INFO: Debug-only, informational messages
 * - DEBUG: Debug-only, detailed debugging information
 *
 * Storage Location:
 * Logs are stored in: wp-content/uploads/ai-image-seo-logs/
 * - debug.log: Main plugin log
 * - pagebuilder-{name}.log: Page builder specific logs
 *
 * @package    AI_Image_SEO
 * @subpackage Core
 * @since 1.0.0
 * @author     InfyPress
 * @license    GPL-2.0+
 */

namespace IPAIS\Core;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Advanced Logger Class with Rotation and Management
 *
 * Provides comprehensive logging capabilities with automatic file rotation,
 * dual-tier logging (critical vs debug), and specialized logging for different
 * components like page builders.
 *
 * Design Pattern: Static utility class
 * Uses static methods for easy access from anywhere in the plugin without
 * requiring instantiation.
 *
 * Log Rotation Strategy:
 * When a log file reaches 10 MB, it is automatically rotated:
 * - Current log becomes debug.log.1
 * - Previous debug.log.1 becomes debug.log.2
 * - Previous debug.log.2 becomes debug.log.3
 * - Previous debug.log.3 is deleted
 *
 * This keeps the last 3 rotations plus the current log (up to 40 MB total).
 *
 * Dual-Tier Logging:
 * - **Critical Levels** (ERROR, WARNING, CRITICAL, EXTERNAL_URL): Always logged
 *   regardless of WP_DEBUG setting. Important for production issue tracking.
 * - **Debug Levels** (INFO, DEBUG): Only logged when WP_DEBUG is true. Provides
 *   detailed information for development and troubleshooting.
 *
 * Usage Example:
 * ```php
 * // Simple logging
 * INFYP_Logger::log( 'Image optimization started', 'INFO' );
 *
 * // Logging with context
 * INFYP_Logger::log(
 *     'API call failed',
 *     'ERROR',
 *     ['image_id' => 123, 'provider' => 'openai']
 * );
 *
 * // Page builder specific log
 * INFYP_Logger::log_pagebuilder(
 *     'Elementor',
 *     'Widget processed',
 *     'INFO',
 *     ['widget_id' => 'abc']
 * );
 * ```
 *
 * @package    AI_Image_SEO
 * @subpackage Core
 * @since 1.0.0
 *
 * @see wp_upload_dir() For log directory location
 * @see WP_Filesystem For file operations
 */
class INFYP_Logger {

    /**
     * Cached log file path.
     *
     * @since 1.0.0
     * @var    string|null
     * @access private
     */
    private static $log_file = null;

    /**
     * Cached log directory path.
     *
     * @since 1.0.0
     * @var    string|null
     * @access private
     */
    private static $log_dir = null;

    /**
     * Maximum log file size before rotation (10 MB).
     *
     * @since 1.0.0
     * @var   int
     */
    const MAX_LOG_SIZE = 10 * 1024 * 1024;

    /**
     * Number of rotated log files to keep
     */
    const MAX_ROTATIONS = 3;

    /**
     * Critical log levels that are always logged (even without WP_DEBUG)
     */
    const CRITICAL_LEVELS = array( 'ERROR', 'WARNING', 'CRITICAL', 'EXTERNAL_URL' );

    /**
     * Initialize the WordPress filesystem API.
     */
    private static function init_filesystem() {
        global $wp_filesystem;
        if ( empty( $wp_filesystem ) ) {
            if ( ! function_exists( 'WP_Filesystem' ) ) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }
            WP_Filesystem();
        }
    }

    /**
     * Gets the log directory path, creating it if it doesn't exist.
     *
     * @return string The path to the log directory.
     */
    private static function get_log_dir() {
        if ( self::$log_dir === null ) {
            $upload_dir = wp_upload_dir();
            self::$log_dir = $upload_dir['basedir'] . '/ai-image-seo-logs/';
            wp_mkdir_p( self::$log_dir );
            INFYP_Logger_Security::protect_log_directory( self::$log_dir );
        }
        return self::$log_dir;
    }

    /**
     * Gets the full path to the log file, creating the directory if it doesn't exist.
     *
     * @param string $filename Optional filename (default: debug.log).
     * @return string The path to the log file.
     */
    private static function get_log_file_path( $filename = 'debug.log' ) {
        return self::get_log_dir() . basename( $filename );
    }

    /**
     * Logs a message to the debug file with dual-tier logic.
     *
     * @param string|array $message The message to log. Can be a string or an array/object.
     * @param string $level   The log level (e.g., INFO, DEBUG, ERROR).
     * @param string $context Optional context array for structured logging.
     */
    public static function log( $message, $level = 'DEBUG', $context = array() ) {
        // Dual-tier logging: Always log critical events, otherwise only when WP_DEBUG is enabled.
        $is_critical = in_array( $level, self::CRITICAL_LEVELS, true );
        $should_log = $is_critical || ( defined( 'WP_DEBUG' ) && WP_DEBUG );

        if ( ! $should_log ) {
            return;
        }

        // Check and perform log rotation if needed.
        $log_file = self::get_log_file_path();
        self::check_log_rotation( $log_file );

        // Format the log entry.
        $message_string = is_string( $message ) ? $message : wp_json_encode( $message );

        if ( ! empty( $context ) ) {
            $message_string .= ' - Context: ' . wp_json_encode( $context );
        }

        $log_entry = sprintf(
            "[%s] [%s]: %s\n",
            gmdate( 'Y-m-d H:i:s' ),
            $level,
            $message_string
        );

        // Append the log entry using atomic file append (avoids reading entire file into memory).
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- FILE_APPEND|LOCK_EX is not available via WP_Filesystem; this is a log file write, not user content.
        @file_put_contents( $log_file, $log_entry, FILE_APPEND | LOCK_EX );
    }

    /**
     * Logs a message to a page builder-specific log file.
     *
     * @param string $builder_name The page builder name (e.g., 'WPBakery', 'Divi').
     * @param string $message The message to log.
     * @param string $level The log level.
     * @param array $context Optional context array.
     */
    public static function log_pagebuilder( $builder_name, $message, $level = 'INFO', $context = array() ) {
        // Dual-tier logging applies to page builder logs too.
        $is_critical = in_array( $level, self::CRITICAL_LEVELS, true );
        $should_log = $is_critical || ( defined( 'WP_DEBUG' ) && WP_DEBUG );

        if ( ! $should_log ) {
            return;
        }

        // Create sanitized filename.
        $builder_slug = sanitize_file_name( strtolower( str_replace( array( ' ', '_' ), '-', $builder_name ) ) );
        $log_file = self::get_log_file_path( 'pagebuilder-' . $builder_slug . '.log' );

        // Check and perform log rotation.
        self::check_log_rotation( $log_file );

        // Format the log entry.
        $message_string = is_string( $message ) ? $message : wp_json_encode( $message );

        if ( ! empty( $context ) ) {
            $message_string .= ' - Context: ' . wp_json_encode( $context );
        }

        $log_entry = sprintf(
            "[%s] [%s]: %s\n",
            gmdate( 'Y-m-d H:i:s' ),
            $level,
            $message_string
        );

        // Append to builder-specific log using WP_Filesystem.
        global $wp_filesystem;
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- FILE_APPEND|LOCK_EX for atomic append, not available via WP_Filesystem.
        @file_put_contents( $log_file, $log_entry, FILE_APPEND | LOCK_EX );
    }

    /**
     * Checks if log rotation is needed and performs it.
     *
     * @param string $log_file The log file to check.
     */
    private static function check_log_rotation( $log_file ) {
        if ( ! file_exists( $log_file ) ) {
            return;
        }

        $file_size = filesize( $log_file );

        if ( $file_size >= self::MAX_LOG_SIZE ) {
            self::rotate_log( $log_file );
        }
    }

    /**
     * Rotates the log file, keeping a maximum number of rotations.
     *
     * @param string $log_file The log file to rotate.
     */
    private static function rotate_log( $log_file ) {
        global $wp_filesystem;
        self::init_filesystem();

        if ( ! $wp_filesystem ) {
            return;
        }

        // Shift existing rotations.
        for ( $i = self::MAX_ROTATIONS - 1; $i > 0; $i-- ) {
            $old_file = $log_file . '.' . $i;
            $new_file = $log_file . '.' . ( $i + 1 );

            if ( $wp_filesystem->exists( $old_file ) ) {
                if ( $i === self::MAX_ROTATIONS - 1 ) {
                    // Delete the oldest rotation.
                    wp_delete_file( $old_file );
                } else {
                    // Move to next rotation number.
                    $wp_filesystem->move( $old_file, $new_file, true );
                }
            }
        }

        // Rotate the current log to .1.
        if ( $wp_filesystem->exists( $log_file ) ) {
            $wp_filesystem->move( $log_file, $log_file . '.1', true );
        }
    }

    /**
     * Retrieves the log content for the debug viewer.
     *
     * @param string $filename Optional filename (default: debug.log).
     * @return string The log content.
     */
    public static function get_log_content( $filename = 'debug.log' ) {
        $log_file = self::get_log_file_path( $filename );
        global $wp_filesystem;
        self::init_filesystem();
        if ( $wp_filesystem && $wp_filesystem->exists( $log_file ) ) {
            return $wp_filesystem->get_contents( $log_file );
        }
        return 'Log file is empty or does not exist.';
    }

    /**
     * Retrieves parsed log entries for structured display.
     *
     * @param string $filename Optional filename (default: debug.log).
     * @param int $limit Maximum number of entries to return (default: 50).
     * @return array Array of parsed log entries.
     */
    public static function get_parsed_logs( $filename = 'debug.log', $limit = 50 ) {
        $log_file = self::get_log_file_path( $filename );
        global $wp_filesystem;
        self::init_filesystem();
        if ( ! $wp_filesystem || ! $wp_filesystem->exists( $log_file ) ) {
            return array();
        }

        $content = $wp_filesystem->get_contents( $log_file );
        $lines = explode( "\n", $content );
        $lines = array_filter( $lines ); // Remove empty lines.
        $lines = array_slice( $lines, -$limit ); // Get last N lines.

        $parsed_logs = array();
        foreach ( $lines as $line ) {
            // Parse format: [2025-01-15 10:30:45] [INFO]: Message - Context: {...}.
            if ( preg_match( '/^\[([^\]]+)\] \[([^\]]+)\]: (.+)$/', $line, $matches ) ) {
                $entry = array(
                    'timestamp' => $matches[1],
                    'level' => $matches[2],
                    'message' => $matches[3],
                    'context' => array(),
                );

                // Extract context if present.
                if ( strpos( $matches[3], 'Context:' ) !== false ) {
                    $parts = explode( ' - Context: ', $matches[3], 2 );
                    $entry['message'] = $parts[0];
                    if ( isset( $parts[1] ) ) {
                        $context = json_decode( $parts[1], true );
                        $entry['context'] = is_array( $context ) ? $context : array();
                    }
                }

                $parsed_logs[] = $entry;
            }
        }

        return array_reverse( $parsed_logs ); // Most recent first.
    }

    /**
     * Gets all available log files (main + page builder logs).
     *
     * @return array Array of log file names.
     */
    public static function get_all_log_files() {
        $log_dir = self::get_log_dir();
        $log_files = array();

        if ( is_dir( $log_dir ) ) {
            $files = scandir( $log_dir );
            foreach ( $files as $file ) {
                if ( $file !== '.' && $file !== '..' && strpos( $file, '.log' ) !== false ) {
                    // Exclude rotated files (.log.1, .log.2, etc.).
                    if ( ! preg_match( '/\.log\.\d+$/', $file ) ) {
                        $log_files[] = $file;
                    }
                }
            }
        }

        return $log_files;
    }

    /**
     * Clears the log file.
     *
     * @param string $filename Optional filename (default: debug.log).
     */
    public static function clear_log( $filename = 'debug.log' ) {
        $log_file = self::get_log_file_path( $filename );
        global $wp_filesystem;
        self::init_filesystem();
        if ( $wp_filesystem && $wp_filesystem->exists( $log_file ) ) {
            $wp_filesystem->put_contents( $log_file, '', FS_CHMOD_FILE );
        }

        // Also clear rotated files.
        for ( $i = 1; $i <= self::MAX_ROTATIONS; $i++ ) {
            $rotated_file = $log_file . '.' . $i;
            if ( file_exists( $rotated_file ) ) {
                wp_delete_file( $rotated_file );
            }
        }
    }

    /**
     * Clears all page builder logs.
     */
    public static function clear_all_pagebuilder_logs() {
        $log_dir = self::get_log_dir();
        if ( is_dir( $log_dir ) ) {
            $files = glob( $log_dir . 'pagebuilder-*.log*' );
            foreach ( $files as $file ) {
                wp_delete_file( $file );
            }
        }
    }
    
    /**
     * Get a singleton instance for compatibility with the debug page.
     *
     * @since 1.0.0
     *
     * @return INFYP_Logger The singleton instance.
     */
    public static function get_instance() {
        static $instance = null;
        if ($instance === null) {
            $instance = new static();
        }
        return $instance;
    }
}
