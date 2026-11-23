<?php
/**
 * Page Builder Base Adapter - Abstract Foundation for Page Builder Adapters
 *
 * This abstract class provides a comprehensive foundation for implementing
 * page builder adapters. It implements the INFYP_PageBuilder_Adapter_Interface
 * and provides common functionality used by all adapters including:
 *
 * - Statistics tracking and persistence
 * - Enhanced logging with context
 * - Safe URL replacement utilities
 * - Recursive data traversal
 * - Serialization/Base64 handling
 * - Batch processing with memory management
 * - Data validation
 *
 * Concrete adapter classes (Gutenberg, Elementor, etc.) extend this base class
 * and implement builder-specific data format handling while inheriting all
 * common utilities.
 *
 * @package   IPAIS\Core\PageBuilders
 * @since 1.0.0
 * @author    InfyPress
 * @license   GPL-2.0-or-later
 */

namespace IPAIS\Core\PageBuilders;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use IPAIS\Core\INFYP_Logger;

/**
 * Abstract Base Adapter for Page Builders
 *
 * Provides common functionality for all page builder adapters including
 * statistics tracking, logging, URL replacement, data traversal, and
 * batch processing utilities.
 *
 * This abstract class implements most of the INFYP_PageBuilder_Adapter_Interface
 * methods, leaving only the builder-specific methods (is_active(), get_name(),
 * process_url_updates(), clear_cache()) to be implemented by concrete classes.
 *
 * Features:
 * - Statistics: Automatic tracking and database persistence
 * - Logging: Context-aware logging with post information
 * - URL Replacement: Safe replacement in strings, arrays, and objects
 * - Data Handling: Serialization and Base64 detection/decoding
 * - Batch Processing: Memory-managed batch processing
 * - Validation: Basic data integrity validation
 *
 * Usage Example (Implementing a New Adapter):
 * ```php
 * class INFYP_Custom_Builder_Adapter extends INFYP_PageBuilder_Base_Adapter {
 *
 *     public function is_active() {
 *         return class_exists('CustomBuilder\\Plugin');
 *     }
 *
 *     public function get_name() {
 *         return 'Custom Builder';
 *     }
 *
 *     public function process_url_updates($url_map) {
 *         $this->reset_stats();
 *
 *         // Get posts with custom builder data
 *         $post_ids = $this->get_custom_builder_posts();
 *
 *         // Use inherited batch processing
 *         $results = $this->process_posts_in_batches(
 *             $post_ids,
 *             $url_map,
 *             [$this, 'process_single_post'],
 *             50
 *         );
 *
 *         $this->finalize_stats();
 *         return $results;
 *     }
 *
 *     public function clear_cache($updated_post_ids = []) {
 *         // Builder-specific cache clearing
 *         return true;
 *     }
 *
 *     private function process_single_post($post_id, $url_map) {
 *         // Use inherited utilities: safe_url_replace(), recursive_url_replace()
 *         // Return ['updated' => bool, 'errors' => array]
 *     }
 * }
 * ```
 *
 * @package   IPAIS\Core\PageBuilders
 * @since 1.0.0
 *
 * @see       INFYP_PageBuilder_Adapter_Interface For interface definition
 * @see       INFYP_Gutenberg_Adapter For concrete implementation example
 * @see       INFYP_Elementor_Adapter For concrete implementation example
 */
abstract class INFYP_PageBuilder_Base_Adapter implements INFYP_PageBuilder_Adapter_Interface {

    /**
     * Processing statistics for this adapter
     *
     * Tracks current session statistics including posts scanned/updated,
     * URLs replaced, errors, timing, and memory usage.
     *
     * @since 1.0.0
     * @var   array {
     *     @type int   $posts_scanned Number of posts scanned.
     *     @type int   $posts_updated Number of posts updated.
     *     @type int   $urls_replaced Number of URLs replaced.
     *     @type int   $errors        Number of errors encountered.
     *     @type float $start_time    Processing start timestamp.
     *     @type float $end_time      Processing end timestamp (null until finalized).
     *     @type int   $memory_peak   Peak memory usage in bytes.
     * }
     */
    protected $stats;

    /**
     * Current post ID being processed
     *
     * Set during batch processing to provide context for logging.
     * Used by log_url_replacement() and other logging methods.
     *
     * @since 1.0.0
     * @var   int|null
     */
    protected $current_post_id = null;

    /**
     * Enable verbose URL replacement logging
     *
     * When true, logs each individual URL replacement. Can be set to false
     * for performance in large batch operations.
     *
     * @since 1.0.0
     * @var   bool
     */
    protected $log_url_replacements = true;

    /**
     * Constructor - Initialize adapter with fresh statistics
     *
     * @since 1.0.0
     */
    public function __construct() {
        $this->reset_stats();
    }

    /**
     * Reset statistics to initial state
     *
     * Called at the beginning of each processing operation to ensure
     * clean statistics for the current run.
     *
     * @since 1.0.0
     */
    protected function reset_stats() {
        $this->stats = [
            'posts_scanned' => 0,
            'posts_updated' => 0,
            'urls_replaced' => 0,
            'errors' => 0,
            'start_time' => microtime(true),
            'end_time' => null,
            'memory_peak' => 0
        ];
    }

    /**
     * Update a specific statistic value
     *
     * Updates a statistics key either by incrementing or setting a value.
     * Automatically tracks peak memory usage.
     *
     * @since 1.0.0
     *
     * @param string $key       The statistic key to update.
     * @param mixed  $value     The value to set or increment by. Default 1.
     * @param bool   $increment Whether to increment (true) or set (false). Default true.
     */
    protected function update_stats($key, $value = 1, $increment = true) {
        if ($increment && isset($this->stats[$key])) {
            $this->stats[$key] += $value;
        } else {
            $this->stats[$key] = $value;
        }

        // Track peak memory usage
        $this->stats['memory_peak'] = max($this->stats['memory_peak'], memory_get_peak_usage(true));
    }

    /**
     * Finalize statistics and persist to database.
     */
    protected function finalize_stats() {
        $this->stats['end_time'] = microtime(true);
        $this->stats['duration'] = $this->stats['end_time'] - $this->stats['start_time'];
        $this->stats['last_run'] = time();

        // Persist statistics to database.
        $this->save_stats_to_db();
    }

    /**
     * Save current statistics to database.
     */
    protected function save_stats_to_db() {
        $all_stats = get_option( 'infyp_pagebuilder_stats', array() );

        // Get builder slug (lowercase, no spaces).
        $builder_slug = strtolower( str_replace( ' ', '_', $this->get_name() ) );

        // Initialize builder stats if not exists.
        if ( ! isset( $all_stats[ $builder_slug ] ) ) {
            $all_stats[ $builder_slug ] = array(
                'total_posts_scanned' => 0,
                'total_posts_updated' => 0,
                'total_urls_replaced' => 0,
                'total_errors' => 0,
                'last_run' => 0,
                'last_duration' => 0,
            );
        }

        // Update cumulative statistics.
        $all_stats[ $builder_slug ]['total_posts_scanned'] += $this->stats['posts_scanned'];
        $all_stats[ $builder_slug ]['total_posts_updated'] += $this->stats['posts_updated'];
        $all_stats[ $builder_slug ]['total_urls_replaced'] += $this->stats['urls_replaced'];
        $all_stats[ $builder_slug ]['total_errors'] += $this->stats['errors'];
        $all_stats[ $builder_slug ]['last_run'] = $this->stats['last_run'];
        $all_stats[ $builder_slug ]['last_duration'] = $this->stats['duration'];

        // Add builder-specific statistics.
        foreach ( $this->stats as $key => $value ) {
            if ( ! in_array( $key, array( 'posts_scanned', 'posts_updated', 'urls_replaced', 'errors', 'start_time', 'end_time', 'duration', 'memory_peak', 'last_run' ), true ) ) {
                if ( ! isset( $all_stats[ $builder_slug ][ $key ] ) ) {
                    $all_stats[ $builder_slug ][ $key ] = 0;
                }
                $all_stats[ $builder_slug ][ $key ] += $value;
            }
        }

        // Update global timestamp.
        $all_stats['last_updated'] = time();

        update_option( 'infyp_pagebuilder_stats', $all_stats );
    }

    /**
     * Get persisted statistics from database.
     *
     * @return array Statistics for this page builder.
     */
    public function get_persisted_stats() {
        $all_stats = get_option( 'infyp_pagebuilder_stats', array() );
        $builder_slug = strtolower( str_replace( ' ', '_', $this->get_name() ) );

        return isset( $all_stats[ $builder_slug ] ) ? $all_stats[ $builder_slug ] : array();
    }

    /**
     * Get statistics about processing for this page builder.
     *
     * @return array Statistics array with counts, timing, etc.
     */
    public function get_stats() {
        return $this->stats;
    }

    /**
     * Log an error with context.
     *
     * @param string $message The error message.
     * @param array  $context Additional context data.
     */
    protected function log_error($message, $context = []) {
        $this->update_stats('errors');
        // Log to both general log and page builder-specific log.
        $this->write_log( $message, 'ERROR', $context );
    }

    /**
     * Log an info message with context.
     *
     * @param string $message The info message.
     * @param array  $context Additional context data.
     */
    protected function log_info($message, $context = []) {
        // Log to both general log and page builder-specific log.
        $this->write_log( $message, 'INFO', $context );
    }

    /**
     * Log a warning with context.
     *
     * @param string $message The warning message.
     * @param array  $context Additional context data.
     */
    protected function log_warning($message, $context = []) {
        // Warnings are always logged (critical level).
        $this->write_log( $message, 'WARNING', $context );
    }

    /**
     * Log a debug message with context (written only when WP_DEBUG is on).
     *
     * @since 1.0.9
     *
     * @param string $message The debug message.
     * @param array  $context Additional context data.
     */
    protected function log_debug($message, $context = []) {
        $this->write_log( $message, 'DEBUG', $context );
    }

    /**
     * Write a message to the general log and the page builder log.
     *
     * Logging must never break a rename: any failure while writing the log
     * is swallowed.
     *
     * @since 1.0.9
     *
     * @param string $message The message.
     * @param string $level   Log level.
     * @param array  $context Additional context data.
     */
    private function write_log($message, $level, $context = []) {
        try {
            INFYP_Logger::log( '[' . $this->get_name() . '] ' . $message, $level, $context );
            INFYP_Logger::log_pagebuilder( $this->get_name(), $message, $level, $context );
        } catch ( \Throwable $e ) {
            // A failed log write must not abort the caller.
            unset( $e );
        }
    }

    /**
     * Log external URL detection (critical - always logged).
     *
     * @param string $message The message.
     * @param array  $context Additional context data.
     */
    protected function log_external_url($message, $context = []) {
        // EXTERNAL_URL is a critical level (always logged).
        $this->write_log( $message, 'EXTERNAL_URL', $context );
    }

    /**
     * Get enhanced context for logging with post information.
     *
     * @param int   $post_id The post ID.
     * @param array $extra_context Additional context to merge.
     * @return array Enhanced context array.
     */
    protected function get_enhanced_context($post_id, $extra_context = []) {
        $post = get_post($post_id);

        $context = [
            'post_id' => $post_id,
        ];

        if ($post) {
            $context['post_title'] = $post->post_title;
            $context['post_type'] = $post->post_type;
            $context['post_status'] = $post->post_status;
        }

        return array_merge($context, $extra_context);
    }

    /**
     * Extract filename from URL.
     *
     * @param string $url The URL to extract filename from.
     * @return string The filename.
     */
    protected function extract_filename($url) {
        $path = wp_parse_url($url, PHP_URL_PATH);
        return basename($path);
    }

    /**
     * Log URL replacement with enhanced context.
     *
     * @param int    $post_id  The post ID.
     * @param string $old_url  The old URL.
     * @param string $new_url  The new URL.
     * @param string $field    Optional field name.
     */
    protected function log_url_replacement($post_id, $old_url, $new_url, $field = '') {
        $context = $this->get_enhanced_context($post_id, [
            'old_url' => $old_url,
            'new_url' => $new_url,
            'old_filename' => $this->extract_filename($old_url),
            'new_filename' => $this->extract_filename($new_url),
        ]);

        if (!empty($field)) {
            $context['field'] = $field;
        }

        $this->log_info('URL replaced', $context);
    }

    /**
     * Safely perform URL replacement in string data.
     *
     * @param string $data          The data to process.
     * @param array  $url_map       Array of old_url => new_url mappings.
     * @param string $field_context Optional field name for logging context.
     * @return array Array with 'data' and 'replaced_count' keys.
     */
    protected function safe_url_replace($data, $url_map, $field_context = '') {
        if (!is_string($data) || empty($data)) {
            return ['data' => $data, 'replaced_count' => 0];
        }

        $original_data = $data;
        $total_replaced = 0;

        foreach ($url_map as $old_url => $new_url) {
            $data = str_replace($old_url, $new_url, $data, $replaced);

            if ($replaced > 0) {
                $total_replaced += $replaced;

                // Log individual URL replacement if verbose logging is enabled and post_id is set
                if ($this->log_url_replacements && $this->current_post_id !== null) {
                    $this->log_url_replacement($this->current_post_id, $old_url, $new_url, $field_context);
                }
            }
        }

        return [
            'data' => $data,
            'replaced_count' => $total_replaced
        ];
    }

    /**
     * Recursively traverse data structures and replace URLs.
     *
     * @param mixed $data    The data to traverse.
     * @param array $url_map Array of old_url => new_url mappings.
     * @return array Array with 'data' and 'replaced_count' keys.
     */
    protected function recursive_url_replace($data, $url_map) {
        $total_replaced = 0;

        if (is_string($data)) {
            $result = $this->safe_url_replace($data, $url_map);
            return $result;
        }

        if (is_array($data)) {
            foreach ($data as $key => &$value) {
                $result = $this->recursive_url_replace($value, $url_map);
                $value = $result['data'];
                $total_replaced += $result['replaced_count'];
            }
            unset($value); // Unset reference
        } elseif (is_object($data)) {
            $vars = get_object_vars($data);
            foreach ($vars as $key => $value) {
                $result = $this->recursive_url_replace($value, $url_map);
                $data->{$key} = $result['data'];
                $total_replaced += $result['replaced_count'];
            }
        }

        return ['data' => $data, 'replaced_count' => $total_replaced];
    }

    /**
     * Safely decode serialized data.
     *
     * @param string $data The potentially serialized data.
     * @return array Array with 'data', 'is_serialized', and 'success' keys.
     */
    protected function safe_unserialize($data) {
        if (!is_string($data) || empty($data)) {
            return ['data' => $data, 'is_serialized' => false, 'success' => true];
        }

        // Check if data appears to be serialized
        if (!$this->is_serialized($data)) {
            return ['data' => $data, 'is_serialized' => false, 'success' => true];
        }

        $unserialized = @unserialize($data, ['allowed_classes' => false]);
        if ($unserialized === false && $data !== serialize(false)) {
            return ['data' => $data, 'is_serialized' => true, 'success' => false];
        }

        return ['data' => $unserialized, 'is_serialized' => true, 'success' => true];
    }

    /**
     * Check if a string is serialized.
     *
     * @param string $data The data to check.
     * @return bool True if serialized, false otherwise.
     */
    protected function is_serialized($data) {
        if (!is_string($data)) {
            return false;
        }

        $data = trim($data);
        if (empty($data)) {
            return false;
        }

        if ($data === serialize(false)) {
            return true;
        }

        if (strlen($data) < 4) {
            return false;
        }

        if (':' !== $data[1]) {
            return false;
        }

        $lastc = substr($data, -1);
        if (';' !== $lastc && '}' !== $lastc) {
            return false;
        }

        $token = $data[0];
        switch ($token) {
            case 's':
                return (bool) preg_match('/^s:\d+:"/', $data);
            case 'a':
            case 'O':
                return (bool) preg_match("/^{$token}:\d+:/s", $data);
            case 'b':
            case 'i':
            case 'd':
                return (bool) preg_match("/^{$token}:[0-9.E+-]+;$/", $data);
        }

        return false;
    }

    /**
     * Safely decode base64 data.
     *
     * @param string $data The potentially base64-encoded data.
     * @return array Array with 'data', 'is_base64', and 'success' keys.
     */
    protected function safe_base64_decode($data) {
        if (!is_string($data) || empty($data)) {
            return ['data' => $data, 'is_base64' => false, 'success' => true];
        }

        // Basic check for base64 pattern
        if (!preg_match('/^[a-zA-Z0-9\/\r\n+]*={0,2}$/', $data)) {
            return ['data' => $data, 'is_base64' => false, 'success' => true];
        }

        $decoded = base64_decode($data, true);
        if ($decoded === false) {
            return ['data' => $data, 'is_base64' => true, 'success' => false];
        }

        // Additional validation: check if decoded data makes sense
        if (mb_check_encoding($decoded, 'UTF-8') || strlen($decoded) > 0) {
            return ['data' => $decoded, 'is_base64' => true, 'success' => true];
        }

        return ['data' => $data, 'is_base64' => false, 'success' => true];
    }

    /**
     * Validate post data after updates.
     *
     * @param int $post_id The post ID to validate.
     * @return bool True if data is valid, false otherwise.
     */
    public function validate_data($post_id) {
        // Basic validation - can be overridden by specific adapters
        $post = get_post($post_id);
        return ($post && !is_wp_error($post));
    }

    /**
     * Process a batch of posts with memory management.
     *
     * @param array    $post_ids   Array of post IDs to process.
     * @param array    $url_map    Array of old_url => new_url mappings.
     * @param callable $processor  Function to process each post.
     * @param int      $batch_size Number of posts to process in each batch.
     * @return array Results with updated_posts and errors.
     */
    protected function process_posts_in_batches($post_ids, $url_map, $processor, $batch_size = 50) {
        $results = ['updated_posts' => [], 'errors' => []];
        $total_posts = count($post_ids);
        $processed = 0;

        $this->log_info("Starting batch processing", [
            'total_posts' => $total_posts,
            'batch_size' => $batch_size
        ]);

        for ($i = 0; $i < $total_posts; $i += $batch_size) {
            $batch = array_slice($post_ids, $i, $batch_size);
            
            foreach ($batch as $post_id) {
                try {
                    $result = call_user_func($processor, $post_id, $url_map);
                    if ($result['updated']) {
                        $results['updated_posts'][] = $post_id;
                    }
                    if (!empty($result['errors'])) {
                        $results['errors'] = array_merge($results['errors'], $result['errors']);
                    }
                } catch (\Exception $e) {
                    $error = sprintf('Failed to process post %d: %s', $post_id, $e->getMessage());
                    $results['errors'][] = $error;
                    $this->log_error($error, ['post_id' => $post_id, 'exception' => $e]);
                }

                $processed++;
                $this->update_stats('posts_scanned');

                // Free WordPress object cache for this post to prevent memory growth.
                clean_post_cache( $post_id );
            }

            // Clear plugin caches per batch.
            $infyp_cache_mgr = \IPAIS\Core\INFYP_Cache_Manager::get_instance();
            $infyp_cache_mgr->clear_all_cache();

            // Allow for garbage collection.
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }

            $this->log_info("Processed batch", [
                'processed' => $processed,
                'total' => $total_posts,
                'memory_usage' => memory_get_usage(true)
            ]);
        }

        return $results;
    }
}
