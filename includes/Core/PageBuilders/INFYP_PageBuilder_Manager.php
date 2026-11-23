<?php
/**
 * Page Builder Manager - Centralized Coordinator for Page Builder Adapters
 *
 * This file implements the Manager class for coordinating URL updates across
 * multiple page builder systems. It follows the Adapter pattern to provide a
 * unified interface for handling different page builders (Gutenberg, Elementor,
 * Visual Composer, etc.) while isolating builder-specific logic.
 *
 * The manager automatically detects active page builders, coordinates batch processing,
 * manages memory usage, handles caching, and provides extensibility via hooks.
 *
 * Architecture:
 * - Singleton pattern for global coordination
 * - Adapter pattern for extensibility
 * - Strategy pattern for processing approaches
 * - Observer pattern via WordPress hooks
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
use IPAIS\Core\INFYP_Cache_Manager;

/**
 * Page Builder Manager Class
 *
 * Central coordinator for managing page builder adapters and orchestrating
 * image URL updates across different page builder systems.
 *
 * This class implements a Singleton pattern to provide a centralized point of control
 * for all page builder operations. It automatically detects and registers active
 * page builders, coordinates URL replacement operations, manages memory usage,
 * and provides hooks for extensibility.
 *
 * Built-in Page Builders:
 * - Gutenberg (WordPress Block Editor) - via INFYP_Gutenberg_Adapter
 * - Elementor - via INFYP_Elementor_Adapter
 * - Visual Composer - via INFYP_Visual_Composer_Adapter
 * - WPBakery - via INFYP_WPBakery_Adapter
 * - Uncode - via INFYP_Uncode_Adapter
 * - Divi - via INFYP_Divi_Adapter
 *
 * Extensibility:
 * The `infyp_register_pagebuilder_adapters` filter allows third-party
 * plugins to register additional adapters for builders like
 * Beaver Builder, etc.
 *
 * Usage Example:
 * ```php
 * $manager = INFYP_PageBuilder_Manager::get_instance();
 *
 * $url_map = [
 *     'https://example.com/old-image.jpg' => 'https://example.com/new-image.jpg',
 * ];
 *
 * $results = $manager->process_url_updates( $url_map );
 *
 * // Results contain:
 * // - total_updated_posts: Array of post IDs
 * // - total_errors: Array of error messages
 * // - adapter_results: Per-adapter results
 * // - processing_time: Time in seconds
 * ```
 *
 * Hooks Fired:
 * - `infyp_register_pagebuilder_adapters`: Filter to register custom adapters
 * - `infyp_before_pagebuilder_url_updates`: Action before processing starts
 * - `infyp_custom_adapter_processing`: Filter for custom adapter processing
 * - `infyp_after_pagebuilder_url_updates`: Action after processing completes
 * - `infyp_pagebuilder_adapter_info`: Filter for adapter information
 *
 * @package   IPAIS\Core\PageBuilders
 * @since 1.0.0
 *
 * @see       INFYP_PageBuilder_Adapter_Interface For adapter contract
 * @see       INFYP_PageBuilder_Base_Adapter For base implementation
 */
class INFYP_PageBuilder_Manager {

    /**
     * Singleton instance
     *
     * @since 1.0.0
     * @var   INFYP_PageBuilder_Manager|null
     */
    private static $instance = null;

    /**
     * Registered page builder adapters
     *
     * Associative array of page builder adapters implementing the
     * INFYP_PageBuilder_Adapter_Interface. Keys are adapter slugs
     * (e.g., 'gutenberg', 'elementor'), values are adapter instances.
     *
     * @since 1.0.0
     * @var   array<string, INFYP_PageBuilder_Adapter_Interface>
     */
    private $adapters = [];

    /**
     * Cache manager instance
     *
     * @since 1.0.0
     * @var   INFYP_Cache_Manager
     */
    private $cache;

    /**
     * Memory limit for processing (in bytes)
     *
     * Set to 80% of PHP's memory_limit to prevent out-of-memory errors
     * during batch processing. Defaults to 256MB if memory_limit is unlimited.
     *
     * @since 1.0.0
     * @var   int
     */
    private $memory_limit;

    /**
     * Processing statistics
     *
     * Tracks overall processing metrics across all adapters.
     * Not currently populated but reserved for future use.
     *
     * @since 1.0.0
     * @var   array
     */
    private $stats = [];

    /**
     * Private constructor (Singleton pattern)
     *
     * Initializes the manager by setting up cache, memory limits,
     * and registering page builder adapters.
     *
     * @since 1.0.0
     */
    private function __construct() {
        $this->cache = INFYP_Cache_Manager::get_instance();
        $this->init_memory_limit();
        $this->init_adapters();
    }

    /**
     * Get singleton instance
     *
     * Returns the single instance of the page builder manager, creating
     * it if it doesn't exist.
     *
     * @since 1.0.0
     *
     * @return INFYP_PageBuilder_Manager The singleton instance.
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Prevent cloning of the singleton instance
     *
     * @since 1.0.0
     */
    private function __clone() {}

    /**
     * Prevent unserialization of the singleton instance
     *
     * @since 1.0.0
     *
     * @throws \Exception Always throws to prevent unserialization.
     */
    public function __wakeup() {
        throw new \Exception("Cannot unserialize singleton");
    }

    /**
     * Initialize memory limit for batch processing
     *
     * Sets the memory limit to 80% of PHP's memory_limit to prevent
     * out-of-memory errors during large batch operations. Falls back
     * to 256MB if memory_limit is unlimited (-1).
     *
     * @since 1.0.0
     */
    private function init_memory_limit() {
        $memory_limit = ini_get('memory_limit');
        if ($memory_limit != -1) {
            $this->memory_limit = wp_convert_hr_to_bytes($memory_limit) * 0.8; // Use 80%
        } else {
            $this->memory_limit = 256 * 1024 * 1024; // Default 256MB
        }
    }

    /**
     * Initialize all available page builder adapters
     *
     * Registers built-in page builder adapters: Gutenberg, Elementor,
     * Visual Composer, WPBakery, and Uncode
     */
    private function init_adapters() {
        // Always register Gutenberg adapter (core WordPress)
        $this->adapters['gutenberg'] = new INFYP_Gutenberg_Adapter();

        // Register Elementor adapter if available
        if (defined('ELEMENTOR_VERSION') || class_exists('\Elementor\Plugin')) {
            $this->adapters['elementor'] = new INFYP_Elementor_Adapter();
        }

        // Register Visual Composer adapter
        $vc_adapter = new INFYP_Visual_Composer_Adapter();
        if ($vc_adapter->is_active()) {
            $this->adapters['visual_composer'] = $vc_adapter;
        }

        // Register WPBakery adapter if available
        $wpbakery_adapter = new INFYP_WPBakery_Adapter();
        if ($wpbakery_adapter->is_active()) {
            $this->adapters['wpbakery'] = $wpbakery_adapter;
        }

        // Register Uncode adapter if available
        $uncode_adapter = new INFYP_Uncode_Adapter();
        if ($uncode_adapter->is_active()) {
            $this->adapters['uncode'] = $uncode_adapter;
        }

        // Register Divi adapter if available
        $divi_adapter = new INFYP_Divi_Adapter();
        if ($divi_adapter->is_active()) {
            $this->adapters['divi'] = $divi_adapter;
        }

        /**
         * Allow external plugins to register additional pagebuilder adapters.
         *
         * This filter enables extensions to add support for additional pagebuilders
         * like Divi or Beaver Builder without modifying core plugin code.
         *
         * @since 1.0.0
         *
         * @param array $adapters Associative array of registered adapters.
         *                        Key: adapter slug (e.g., 'divi', 'beaver')
         *
         * @return array Modified adapters array
         *
         * @example
         * add_filter('infyp_register_pagebuilder_adapters', function($adapters) {
         *     $divi_adapter = new My_Divi_Adapter();
         *     if ($divi_adapter->is_active()) {
         *         $adapters['divi'] = $divi_adapter;
         *     }
         *     return $adapters;
         * }, 10, 1);
         */
        $this->adapters = apply_filters('infyp_register_pagebuilder_adapters', $this->adapters);

        // Validate all adapters implement the required interface.
        $this->adapters = array_filter( $this->adapters, function( $adapter ) {
            return $adapter instanceof INFYP_PageBuilder_Adapter_Interface;
        } );

        // Only log during debug mode and on plugin main page
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only checking page context for debug logging
        if (defined('WP_DEBUG') && WP_DEBUG && is_admin() && isset($_GET['page']) && sanitize_text_field(wp_unslash($_GET['page'])) === 'infy-ai-image-seo-optimizer') {
            INFYP_Logger::log('Initialized page builder adapters: ' . implode(', ', array_keys($this->adapters)), 'DEBUG');
        }
    }

    /**
     * Process URL updates across active page builders.
     *
     * @param array $url_map Array of old_url => new_url mappings.
     * @param array $options Processing options.
     * @return array Processing results.
     */
    public function process_url_updates($url_map, $options = []) {
        $start_time = microtime(true);

        INFYP_Logger::log('Starting page builder URL updates for ' . count($url_map) . ' URLs', 'INFO');

        /**
         * Fires before processing URL updates across pagebuilders.
         *
         * Allows external code to prepare for URL updates, modify options,
         * or perform pre-processing tasks.
         *
         * @since 1.0.0
         *
         * @param array $url_map URL mappings (old_url => new_url)
         * @param array $options Processing options
         */
        do_action('infyp_before_pagebuilder_url_updates', $url_map, $options);

        $results = [
            'total_updated_posts' => [],
            'total_errors' => [],
            'adapter_results' => [],
            'processing_time' => 0
        ];

        // Check memory before starting
        if (memory_get_usage(true) > $this->memory_limit * 0.5) {
            INFYP_Logger::log('High memory usage detected before processing', 'WARNING');
            if (function_exists('wp_cache_flush')) {
                wp_cache_flush();
            }
        }

        // Process each adapter
        foreach ($this->adapters as $key => $adapter) {
            if (!$adapter->is_active()) {
                INFYP_Logger::log('Skipping inactive adapter: ' . $adapter->get_name(), 'INFO');
                continue;
            }

            try {
                INFYP_Logger::log('Processing adapter: ' . $adapter->get_name(), 'INFO');

                /**
                 * Allow custom processing for specific adapters.
                 *
                 * Return a custom result array to bypass default processing,
                 * or null to use default adapter processing.
                 *
                 * @since 1.0.0
                 *
                 * @param array|null $custom_result Custom result or null
                 * @param string     $key           Adapter key (e.g., 'divi', 'beaver')
                 * @param object     $adapter       Adapter instance
                 * @param array      $url_map       URL mappings
                 * @return array|null Result array with 'updated_posts' and 'errors' keys, or null
                 */
                $custom_result = apply_filters(
                    'infyp_custom_adapter_processing',
                    null,
                    $key,
                    $adapter,
                    $url_map
                );

                // Use custom result if provided, otherwise use default processing
                $adapter_result = ($custom_result !== null)
                    ? $custom_result
                    : $adapter->process_url_updates($url_map);
                
                // Store results
                $results['adapter_results'][$key] = $adapter_result;
                
                // Merge updated posts
                if (!empty($adapter_result['updated_posts'])) {
                    $results['total_updated_posts'] = array_merge(
                        $results['total_updated_posts'], 
                        $adapter_result['updated_posts']
                    );
                }
                
                // Merge errors
                if (!empty($adapter_result['errors'])) {
                    $results['total_errors'] = array_merge(
                        $results['total_errors'], 
                        $adapter_result['errors']
                    );
                }
                
                // Clear adapter cache after processing
                if (!empty($adapter_result['updated_posts'])) {
                    $adapter->clear_cache($adapter_result['updated_posts']);
                }

            } catch (\Exception $e) {
                $error = sprintf('Adapter %s failed: %s', $adapter->get_name(), $e->getMessage());
                $results['total_errors'][] = $error;
                INFYP_Logger::log($error, 'ERROR');
            }

            // Check memory usage
            if (memory_get_usage(true) > $this->memory_limit) {
                INFYP_Logger::log('Memory limit reached during processing', 'WARNING');
                break;
            }
        }

        // Remove duplicate post IDs
        $results['total_updated_posts'] = array_unique($results['total_updated_posts']);

        // Clear global caches
        $this->clear_global_caches();

        // Calculate processing time
        $results['processing_time'] = microtime(true) - $start_time;

        // Log summary
        INFYP_Logger::log(sprintf(
            'Completed page builder URL updates - Posts updated: %d, Errors: %d, Time: %.2fs',
            count($results['total_updated_posts']),
            count($results['total_errors']),
            $results['processing_time']
        ), 'INFO');

        /**
         * Fires after processing URL updates across pagebuilders.
         *
         * Allows external code to perform post-processing tasks,
         * log results, or trigger additional actions.
         *
         * @since 1.0.0
         *
         * @param array $results Processing results with keys:
         *                       - total_updated_posts: Array of updated post IDs
         *                       - total_errors: Array of error messages
         *                       - adapter_results: Results from each adapter
         *                       - processing_time: Total processing time in seconds
         * @param array $url_map URL mappings that were processed
         * @param array $options Processing options
         */
        do_action('infyp_after_pagebuilder_url_updates', $results, $url_map, $options);

        return $results;
    }

    /**
     * Clear global caches after URL updates
     *
     * Clears WordPress object cache, plugin cache, and popular caching
     * plugin caches (WP Rocket, W3 Total Cache, WP Engine) to ensure
     * updated URLs are reflected immediately.
     *
     * @since 1.0.0
     */
    private function clear_global_caches() {
        // Clear object cache
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }

        // Clear our plugin cache
        $this->cache->clear_all_cache();

        // Clear page cache plugins
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain(); // WP Rocket
        }
        
        if (function_exists('w3tc_flush_all')) {
            w3tc_flush_all(); // W3 Total Cache
        }
        
        if (class_exists('WpeCommon')) {
            \WpeCommon::purge_memcached(); // WP Engine
            \WpeCommon::clear_maxcdn_cache();
        }
    }

    /**
     * Get information about registered adapters.
     *
     * @return array Adapter information.
     */
    public function get_adapter_info() {
        $info = [];

        foreach ($this->adapters as $key => $adapter) {
            $info[$key] = [
                'name' => $adapter->get_name(),
                'is_active' => $adapter->is_active(),
                'stats' => $adapter->get_stats()
            ];
        }

        /**
         * Filter the adapter information returned.
         *
         * Allows external plugins to modify or enhance the adapter information
         * before it's displayed to users.
         *
         * @since 1.0.0
         *
         * @param array $info Adapter information array with keys:
         *                    - name: Human-readable adapter name
         *                    - is_active: Whether the adapter is active
         *                    - stats: Processing statistics
         * @return array Modified adapter information
         */
        return apply_filters('infyp_pagebuilder_adapter_info', $info);
    }

    /**
     * Get currently active page builders
     *
     * Returns an associative array of active page builders with their
     * slugs as keys and human-readable names as values.
     *
     * @since 1.0.0
     *
     * @return array<string, string> List of active page builders.
     *                               Key: adapter slug, Value: adapter name.
     */
    public function get_active_builders() {
        $active = [];

        foreach ($this->adapters as $key => $adapter) {
            if ($adapter->is_active()) {
                $active[$key] = $adapter->get_name();
            }
        }

        return $active;
    }

    /**
     * Test URL updates without applying changes (dry run)
     *
     * Performs a quick scan to determine which posts would be affected
     * by a given URL map without actually updating any data. Useful for
     * previewing the scope of an operation before committing to it.
     *
     * @since 1.0.0
     *
     * @param array $url_map Associative array of old_url => new_url mappings.
     *
     * @return array {
     *     Test results indicating affected posts.
     *
     *     @type array  $would_update Array of post IDs that would be updated.
     *     @type float  $scan_time    Time taken for the scan in seconds.
     * }
     */
    public function test_updates($url_map) {
        $results = [
            'would_update' => [],
            'scan_time' => 0
        ];

        $start_time = microtime(true);

        foreach ($this->adapters as $key => $adapter) {
            if (!$adapter->is_active()) {
                continue;
            }

            // Quick scan for affected posts
            global $wpdb;

            // Define meta keys for each adapter type
            $meta_keys = [
                'elementor' => '_elementor_data',
                'wpbakery' => '_wpb_shortcodes_custom_css',
                'divi' => '_et_pb_page_layout',
                'beaver' => '_fl_builder_data',
                'visual_composer' => 'vcv-pageContent',
            ];

            if ($key === 'gutenberg') {
                // Scan regular content
                foreach ($url_map as $old_url => $new_url) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Preview scan, real-time data needed
                    $posts = $wpdb->get_col($wpdb->prepare(
                        "SELECT ID FROM {$wpdb->posts}
                         WHERE post_content LIKE %s
                         AND post_type NOT IN ('attachment', 'revision')
                         LIMIT 10",
                        '%' . $wpdb->esc_like($old_url) . '%'
                    ));

                    $results['would_update'] = array_merge($results['would_update'], $posts);
                }
            } elseif (isset($meta_keys[$key])) {
                // Scan meta for all other page builders
                foreach ($url_map as $old_url => $new_url) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Preview scan, real-time data needed
                    $posts = $wpdb->get_col($wpdb->prepare(
                        "SELECT post_id FROM {$wpdb->postmeta}
                         WHERE meta_key = %s
                         AND meta_value LIKE %s
                         LIMIT 10",
                        $meta_keys[$key],
                        '%' . $wpdb->esc_like($old_url) . '%'
                    ));

                    $results['would_update'] = array_merge($results['would_update'], $posts);
                }
            }
        }

        $results['would_update'] = array_unique($results['would_update']);
        $results['scan_time'] = microtime(true) - $start_time;

        return $results;
    }
}