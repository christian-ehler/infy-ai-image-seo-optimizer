<?php
/**
 * Admin Notices Manager - Clean Admin Interface
 *
 * This class filters and manages admin notices on plugin pages to provide
 * a clean, distraction-free user interface. It removes third-party plugin
 * notices while preserving WordPress core notices and plugin-specific notices.
 *
 * Problem Solved:
 * Many WordPress plugins display admin notices globally, cluttering the
 * interface on all admin pages. This manager removes those notices on
 * AI Image SEO plugin pages only.
 *
 * Filtering Strategy:
 * - Allow WordPress core notices (from wp-admin/ and wp-includes/)
 * - Allow plugin's own notices (IPAIS namespace and plugin directory)
 * - Block all third-party plugin notices
 * - Uses Reflection API to determine notice source
 *
 * Security:
 * - Only filters on plugin pages (screen ID check)
 * - Preserves core WordPress functionality
 * - Graceful error handling for reflection failures
 *
 * @package   IPAIS\Admin
 * @since 1.0.0
 * @author    InfyPress
 * @license   GPL-2.0-or-later
 */

namespace IPAIS\Admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin Notices Filter Class
 *
 * Filters admin_notices hook to remove third-party plugin notices on
 * AI Image SEO admin pages, providing a cleaner interface.
 *
 * How It Works:
 * 1. Hooks into 'in_admin_header' at priority 9999 (very late)
 * 2. Checks if current page is a plugin page
 * 3. Inspects all registered admin_notices callbacks
 * 4. Uses Reflection to determine callback source file
 * 5. Removes callbacks from third-party plugins
 * 6. Re-adds allowed callbacks (core + plugin)
 *
 * Allowed Notice Sources:
 * - WordPress core (ABSPATH/wp-admin/, ABSPATH/wp-includes/)
 * - Plugin's own files (IPAIS namespace and INFYP_PLUGIN_DIR)
 * - Explicitly whitelisted notice names
 *
 * @package   IPAIS\Admin
 * @since 1.0.0
 *
 * @see       ReflectionFunction For callback source detection
 * @see       ReflectionMethod For method callback source detection
 */
class INFYP_Admin_Notices_Manager {

    /**
     * Whitelisted notice callback names
     *
     * Array of notice callback names that are always allowed.
     *
     * @since 1.0.0
     * @var   array<string>
     */
    private $allowed_notices = [];

    /**
     * Constructor - Register WordPress hooks
     *
     * @since 1.0.0
     */
    public function __construct() {
        add_action('in_admin_header', [$this, 'filter_admin_notices'], 9999);
    }

    /**
     * Filter admin notices on plugin pages.
     *
     * Inspects all registered admin_notices callbacks and removes those
     * from third-party plugins, keeping only WordPress core and plugin-own notices.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function filter_admin_notices() {
        if ($this->is_plugin_page()) {
            global $wp_filter;

            $this->allowed_notices = [
                'infyp_admin_notice', // Allow our own notices
            ];

            if (isset($wp_filter['admin_notices'])) {
                $admin_notices_cbs = $wp_filter['admin_notices']->callbacks;
                $wp_filter['admin_notices']->callbacks = [];

                foreach ($admin_notices_cbs as $priority => $callbacks) {
                    foreach ($callbacks as $name => $callback_details) {
                        if ($this->is_allowed_callback($callback_details['function'])) {
                            $wp_filter['admin_notices']->add_filter($name, $callback_details['function'], $priority, $callback_details['accepted_args']);
                        }
                    }
                }
            }
        }
    }

    /**
     * Check if the current admin page belongs to this plugin.
     *
     * @since 1.0.0
     *
     * @return bool True if the current screen is a plugin page.
     */
    private function is_plugin_page() {
        $screen = get_current_screen();
        return $screen && strpos($screen->id, 'ai-image-seo-optimizer') !== false;
    }

    /**
     * Determine if a callback is allowed to display its notice.
     *
     * Uses Reflection API to inspect the source file of each callback
     * and allows only WordPress core and plugin-own notices.
     *
     * @since 1.0.0
     *
     * @param callable $callback The callback to check.
     * @return bool True if the callback is allowed to display.
     */
    private function is_allowed_callback($callback) {
        // Allow our own plugin's notices (and extensions using IPAIS namespace)
        if (is_array($callback)) {
            $class_name = is_object($callback[0]) ? get_class($callback[0]) : (is_string($callback[0]) ? $callback[0] : ' ');
            if (strpos($class_name, 'IPAIS') === 0 || strpos($class_name, 'INFYP_') === 0) {
                return true;
            }
        }

        try {
            $reflection = null;
            if (is_string($callback) && function_exists($callback)) {
                $reflection = new \ReflectionFunction($callback);
            } elseif (is_array($callback) && method_exists($callback[0], $callback[1])) {
                $reflection = new \ReflectionMethod($callback[0], $callback[1]);
            } elseif ($callback instanceof \Closure) {
                $reflection = new \ReflectionFunction($callback);
            }

            if ($reflection) {
                $file_path = $reflection->getFileName();
                if ($file_path) {
                    // Normalize paths for comparison
                    $file_path = wp_normalize_path($file_path);
                    $wp_admin_path = wp_normalize_path(ABSPATH . 'wp-admin/');
                    $wp_includes_path = wp_normalize_path(ABSPATH . 'wp-includes/');
                    $plugin_path = wp_normalize_path(INFYP_PLUGIN_DIR);

                    // Check if the callback is defined in a WP core file or our plugin
                    if (strpos($file_path, $wp_admin_path) === 0 || strpos($file_path, $wp_includes_path) === 0 || strpos($file_path, $plugin_path) === 0) {
                        return true;
                    }
                }
            }
        } catch (\ReflectionException $e) {
            // Can't reflect, so we can't determine the path. Disallowing is safer.
            return false;
        }

        // By default, disallow everything else
        return false;
    }
}
