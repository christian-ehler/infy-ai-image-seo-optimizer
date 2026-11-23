<?php
/**
 * Security Management System for AI Image SEO Plugin
 *
 * This file contains the INFYP_Security_Manager class which provides comprehensive
 * security infrastructure for the entire plugin. It implements multiple layers of
 * protection including rate limiting, input validation, capability checking, and
 * security event monitoring.
 *
 * Security Features:
 * - Rate limiting for API calls and bulk operations
 * - AJAX request validation with nonce verification
 * - User capability checking for action authorization
 * - Input sanitization and validation (filenames, IDs, SQL params)
 * - Security header injection (X-Content-Type-Options, X-Frame-Options, etc.)
 * - Security event logging and alerting
 * - IP address tracking and validation
 * - Token generation and verification
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
 * Centralized Security Manager for Infy AI Image SEO
 *
 * Implements a singleton pattern to provide a single point of security management
 * across the entire plugin. All security-related operations should go through this
 * class to ensure consistent enforcement of security policies.
 *
 * Design Pattern: Singleton
 * This ensures only one instance of the security manager exists, maintaining
 * consistent rate limit tracking and security event monitoring throughout the
 * plugin lifecycle.
 *
 * Rate Limiting Strategy:
 * The class implements per-user rate limiting using WordPress transients, in a
 * fixed window that starts with the first attempt. Each bucket has its own
 * limit and window:
 * - API Calls: 500 per hour (prevents API quota exhaustion; filterable)
 * - Filename Updates: 500 per hour (available to extensions)
 * The plugin applies the limit to the AI optimization routes of its REST API
 * (`infyp/v1`); the admin screens are not rate limited. Extensions can register
 * additional rate limits via the `infyp_rate_limits` filter and map actions to
 * buckets via `infyp_rate_limited_actions`.
 *
 * Security Headers:
 * When on plugin admin pages, the following security headers are set:
 * - X-Content-Type-Options: nosniff (prevents MIME type sniffing)
 * - X-Frame-Options: SAMEORIGIN (prevents clickjacking)
 * - X-XSS-Protection: 1; mode=block (enables XSS filtering)
 * - Referrer-Policy: strict-origin-when-cross-origin (controls referrer info)
 *
 * Usage Example:
 * ```php
 * // Get the security manager instance
 * $security = INFYP_Security_Manager::get_instance();
 *
 * // Validate a filename
 * if ( ! $security->validate_filename( $filename ) ) {
 *     return new WP_Error( 'invalid_filename', 'Filename contains invalid characters' );
 * }
 *
 * // Check if user can perform action
 * if ( ! $security->check_capability_for_action( 'infyp_optimize_with_ai' ) ) {
 *     wp_die( 'Insufficient permissions' );
 * }
 * ```
 *
 * Hooks Fired:
 * - `infyp_security_event`: Logs security-related events
 * - `infyp_rate_limit_exceeded`: Fires when rate limit is exceeded
 *
 * @package    AI_Image_SEO
 * @subpackage Core
 * @since 1.0.0
 *
 * @see wp_verify_nonce() For nonce verification
 * @see current_user_can() For capability checking
 * @see set_transient() For rate limiting storage
 */
class INFYP_Security_Manager {
    
    /**
     * Singleton instance
     *
     * @since 1.0.0
     * @var    INFYP_Security_Manager|null
     * @access private
     */
    private static $instance = null;

    /**
     * Rate limiting configuration data
     *
     * Defines rate limits for different action types. Each entry specifies the
     * maximum number of attempts allowed within a given time window (in seconds).
     *
     * @since 1.0.0
     * @var    array {
     *     Rate limit configurations keyed by action type.
     *
     * Defaults can be changed — or a bucket switched off with a limit of 0 —
     * through the `infyp_rate_limits` filter, applied in
     * {@see self::init_security_measures()}.
     *
     *     @type array $api_calls {
     *         @type int $limit  Maximum API calls allowed.
     *         @type int $window Time window in seconds.
     *     }
     *     @type array $filename_updates {
     *         @type int $limit  Maximum filename updates allowed.
     *         @type int $window Time window in seconds.
     *     }
     * }
     * @access private
     */
    private $rate_limits = [
        'api_calls' => ['limit' => 500, 'window' => 3600], // 500 per hour
        'filename_updates' => ['limit' => 500, 'window' => 3600], // 500 per hour
    ];

    /**
     * Get singleton instance of Security Manager
     *
     * Implements the singleton pattern to ensure only one security manager
     * instance exists throughout the plugin lifecycle.
     *
     * @since 1.0.0
     * @access public
     * @static
     *
     * @return INFYP_Security_Manager The singleton instance.
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Private constructor to enforce singleton pattern
     *
     * Hooks into WordPress initialization to set up the rate-limit
     * configuration and security headers.
     *
     * @since 1.0.0
     * @access private
     *
     * @return void
     */
    private function __construct() {
        add_action('init', [$this, 'init_security_measures']);
    }

    /**
     * Initialize security infrastructure
     *
     * Sets up security headers and event monitoring hooks. This method is called
     * during WordPress initialization to ensure security measures are in place
     * before any requests are processed.
     *
     * @since 1.0.0
     * @access public
     *
     * @return void
     */
    public function init_security_measures() {
        // Allow extensions to register additional rate limits
        $this->rate_limits = apply_filters('infyp_rate_limits', $this->rate_limits);

        // Clamp rate limits to a sane range. 0 is allowed and means "no cap" —
        // an explicit opt-out for site owners running large bulk jobs, who are
        // spending their own API budget.
        foreach ( $this->rate_limits as $key => &$infyp_limit ) {
            if ( isset( $infyp_limit['limit'] ) ) {
                $infyp_limit['limit'] = max( 0, min( 100000, intval( $infyp_limit['limit'] ) ) );
            }
            if ( isset( $infyp_limit['window'] ) ) {
                $infyp_limit['window'] = max( 10, min( 86400, intval( $infyp_limit['window'] ) ) );
            }
        }
        unset( $infyp_limit );

        // Set security headers
        add_action('send_headers', [$this, 'set_security_headers']);
    }

    /**
     * Inject security headers into HTTP response
     *
     * Adds security-related HTTP headers when on plugin admin pages to protect
     * against common web vulnerabilities like XSS, clickjacking, and MIME sniffing.
     *
     * Headers Set:
     * - X-Content-Type-Options: nosniff
     * - X-Frame-Options: SAMEORIGIN
     * - X-XSS-Protection: 1; mode=block
     * - Referrer-Policy: strict-origin-when-cross-origin
     *
     * Only applies to plugin admin pages to avoid interfering with other plugins
     * or WordPress core functionality.
     *
     * @since 1.0.0
     * @access public
     *
     * @return void
     */
    public function set_security_headers() {
        if (is_admin() && $this->is_plugin_page()) {
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: SAMEORIGIN');
            header('X-XSS-Protection: 1; mode=block');
            header('Referrer-Policy: strict-origin-when-cross-origin');
        }
    }
    
    /**
     * Former AJAX validator; it was never hooked.
     *
     * @since 1.0.0
     * @deprecated 1.0.9 No replacement. Rate limits apply to the REST routes.
     *
     * @param mixed $valid   Passed through unchanged.
     * @param array $request Unused.
     * @return mixed $valid.
     */
    public function validate_ajax_request($valid, $request = []) {
        _deprecated_function( __METHOD__, '1.0.9' );
        return $valid;
    }

    /**
     * Check if current user has required capability for an action
     *
     * Maps plugin action names to WordPress capabilities and checks if the current
     * user has the required capability. This provides granular permission control
     * where different actions can require different capability levels.
     *
     * Capability Mapping:
     * - Image operations (update_image_data, update_filename, optimize_with_ai): upload_files
     * - Settings operations (save_api_keys, save_settings): manage_options
     * - Bulk operations: upload_files
     *
     * @since 1.0.0
     * @access public
     *
     * @param string $action The action name to check (e.g., 'infyp_optimize_with_ai').
     *
     * @return bool True if user has required capability, false otherwise.
     *
     * @see current_user_can() WordPress capability checking function
     */
    public function check_capability_for_action($action) {
        $capabilities = [
            'infyp_update_image_data' => 'upload_files',
            'infyp_update_filename' => 'upload_files',
            'infyp_optimize_with_ai' => 'upload_files',
            'infyp_save_api_keys' => 'manage_options',
            'infyp_save_prompt_settings' => 'manage_options',
            'infyp_save_post_type_settings' => 'manage_options',
            // Short action aliases used by infyp_user_can() and the REST layer.
            // Without these, unknown keys fall through to manage_options and
            // Editors would be denied actions they can perform via admin-ajax.
            'optimize' => 'upload_files',
            'edit_field' => 'upload_files',
            'manage_settings' => 'manage_options',
        ];
        $capabilities = apply_filters('infyp_capability_map', $capabilities);

        $required_cap = isset($capabilities[$action]) ? $capabilities[$action] : 'manage_options';
        return current_user_can($required_cap);
    }
    
    /**
     * Check if action is within rate limit
     *
     * Implements per-user rate limiting using WordPress transients. Counts the
     * attempts for a rate-limited action within a fixed window that starts with
     * the first counted attempt. Later attempts do not move the end of the
     * window: once it has passed, a new window starts.
     *
     * When a rate limit is exceeded, the method fires the `infyp_rate_limit_exceeded`
     * action hook for logging and monitoring purposes.
     *
     * Rate Limit Storage:
     * Uses transients with keys format: `infyp_rate_{limit_key}_{user_id}`
     * Each transient stores the attempt count and the end of the window, and
     * expires when the window ends.
     *
     * @since 1.0.0
     * @since 1.0.9 Fixed window: the window ends one window length after the
     *              first attempt and is no longer extended by later attempts.
     * @access public
     *
     * @param string $action The action to check (e.g., 'infyp_rest_optimize').
     *
     * @return bool True if within rate limit, false if limit exceeded.
     *
     * @see get_transient() For retrieving attempt count
     * @see set_transient() For storing attempt count
     */
    public function check_rate_limit($action) {
        $user_id = get_current_user_id();
        $limit_key = $this->get_rate_limit_key($action);

        if (!$limit_key) {
            return true; // No rate limit for this action
        }

        $config = $this->get_rate_limit_config($limit_key);

        // A limit of 0 (or less) disables the bucket entirely.
        if ($config['limit'] <= 0) {
            return true;
        }

        $transient_key = $this->get_rate_limit_transient_key($limit_key, $user_id);
        $now = time();
        $state = $this->read_rate_limit_state($transient_key, $now);

        if ($state === null) {
            $state = ['count' => 0, 'reset' => $now + $config['window']];
        }

        if ($state['count'] >= $config['limit']) {
            do_action('infyp_rate_limit_exceeded', $action, $user_id);
            return false;
        }

        $state['count']++;
        // The transient lives exactly until the end of the window, so later
        // attempts never extend it.
        set_transient($transient_key, $state, max(1, $state['reset'] - $now));
        return true;
    }

    /**
     * Seconds until the current user's rate-limit window for an action ends.
     *
     * Used for the `Retry-After` header of a refused request.
     *
     * @since 1.0.9
     *
     * @param string $action The rate-limited action.
     * @return int Seconds until a new window starts; 0 when no window is active
     *             or the action is not rate limited.
     */
    public function get_rate_limit_retry_after($action) {
        $limit_key = $this->get_rate_limit_key($action);
        if (!$limit_key) {
            return 0;
        }

        $now = time();
        $state = $this->read_rate_limit_state(
            $this->get_rate_limit_transient_key($limit_key, get_current_user_id()),
            $now
        );

        return $state === null ? 0 : max(1, $state['reset'] - $now);
    }

    /**
     * Build the transient key for a rate-limit bucket and user.
     *
     * @since 1.0.9
     *
     * @param string $limit_key Bucket name.
     * @param int    $user_id   User ID.
     * @return string
     */
    private function get_rate_limit_transient_key($limit_key, $user_id) {
        return 'infyp_rate_' . $limit_key . '_' . (int) $user_id;
    }

    /**
     * Read the stored state of an active rate-limit window.
     *
     * A plain integer stored before 1.0.9 (sliding window) carries no window
     * end; it is ignored and the next attempt starts a new window.
     *
     * @since 1.0.9
     *
     * @param string $transient_key Transient key.
     * @param int    $now           Current Unix time.
     * @return array{count:int,reset:int}|null Null when no window is active.
     */
    private function read_rate_limit_state($transient_key, $now) {
        $state = get_transient($transient_key);

        if (!is_array($state) || !isset($state['count'], $state['reset'])) {
            return null;
        }

        $state = [
            'count' => (int) $state['count'],
            'reset' => (int) $state['reset'],
        ];

        // Window over (transient storage without expiry, clock skew).
        if ($state['reset'] <= $now) {
            return null;
        }

        return $state;
    }

    /**
     * Resolve the limit and window for a rate-limit bucket.
     *
     * The buckets themselves were already filtered and clamped in
     * {@see self::init_security_measures()}; this only normalises the shape
     * so a malformed filter return cannot produce a fatal.
     *
     * @since 1.0.8
     *
     * @param string $limit_key Bucket name ('api_calls', 'filename_updates').
     * @return array {
     *     @type int $limit  Maximum attempts per window. 0 disables the bucket.
     *     @type int $window Window length in seconds.
     * }
     */
    private function get_rate_limit_config($limit_key) {
        $config = isset($this->rate_limits[$limit_key]) && is_array($this->rate_limits[$limit_key])
            ? $this->rate_limits[$limit_key]
            : [];

        return [
            'limit'  => isset($config['limit']) ? (int) $config['limit'] : 0,
            'window' => max(1, isset($config['window']) ? (int) $config['window'] : HOUR_IN_SECONDS),
        ];
    }

    /**
     * Get rate limit key for action
     *
     * @param string $action
     * @return string|null
     */
    private function get_rate_limit_key($action) {
        $rate_limited_actions = [
            'infyp_optimize_with_ai' => 'api_calls',
            'infyp_update_filename' => 'filename_updates',
        ];
        $rate_limited_actions = apply_filters('infyp_rate_limited_actions', $rate_limited_actions);

        return isset($rate_limited_actions[$action]) ? $rate_limited_actions[$action] : null;
    }
    
    /**
     * Validate input data for security
     *
     * @param array $request
     * @return bool|WP_Error
     */
    public function validate_input_data($request) {
        $action = isset($request['action']) ? $request['action'] : '';
        
        switch ($action) {
            case 'infyp_update_filename':
                if (!isset($request['value']) || !$this->validate_filename($request['value'])) {
                    return new \WP_Error('invalid_filename', esc_html__('Invalid filename provided.', 'infy-ai-image-seo-optimizer'));
                }
                break;
                
            case 'infyp_update_image_data':
                if (!isset($request['field']) || !in_array($request['field'], ['title', 'alt_text'], true)) {
                    return new \WP_Error('invalid_field', esc_html__('Invalid field specified.', 'infy-ai-image-seo-optimizer'));
                }
                if (isset($request['value']) && strlen($request['value']) > 1000) {
                    return new \WP_Error('value_too_long', esc_html__('Value exceeds maximum length.', 'infy-ai-image-seo-optimizer'));
                }
                break;
                
            case 'infyp_optimize_with_ai':
                if (!isset($request['image_id']) || !$this->validate_attachment_id($request['image_id'])) {
                    return new \WP_Error('invalid_image_id', esc_html__('Invalid image ID.', 'infy-ai-image-seo-optimizer'));
                }
                break;
        }
        
        return true;
    }
    
    /**
     * Validate filename for security
     *
     * @param string $filename
     * @return bool
     */
    public function validate_filename($filename) {
        // Remove any path components
        $filename = basename($filename);
        
        // Check for directory traversal attempts
        if (strpos($filename, '..') !== false || strpos($filename, '/') !== false || strpos($filename, '\\') !== false) {
            return false;
        }
        
        // Allow only safe characters
        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $filename)) {
            return false;
        }
        
        // Check length
        if (strlen($filename) < 1 || strlen($filename) > 200) {
            return false;
        }
        
        // Prevent hidden files
        if (strpos($filename, '.') === 0) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Validate attachment ID
     *
     * @param int $attachment_id
     * @return bool
     */
    public function validate_attachment_id($attachment_id) {
        $attachment_id = intval($attachment_id);
        
        if ($attachment_id <= 0) {
            return false;
        }
        
        $post = get_post($attachment_id);
        if (!$post || $post->post_type !== 'attachment') {
            return false;
        }
        
        // Check if it's an image
        if (!wp_attachment_is_image($attachment_id)) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Log security event
     *
     * @param string $event_type
     * @param array $data
     * @param string $severity
     */
    public function log_security_event($event_type, $data = [], $severity = 'warning') {
        $log_entry = [
            'timestamp' => current_time('mysql'),
            'event_type' => $event_type,
            'user_id' => get_current_user_id(),
            'ip_address' => $this->get_client_ip(),
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '',
            'data' => $data,
            'severity' => $severity
        ];
        
        // Store in transient for admin review (last 100 events)
        $events = get_transient('infyp_security_events') ?: [];
        array_unshift($events, $log_entry);
        $max_events = apply_filters('infyp_max_security_events', 100);
        $events = array_slice($events, 0, $max_events);
        set_transient('infyp_security_events', $events, DAY_IN_SECONDS);
        
        // For critical events, send admin notification
        if ($severity === 'critical') {
            $this->send_security_alert($log_entry);
        }
    }
    
    /**
     * Get client IP address safely
     *
     * @return string
     */
    private function get_client_ip() {
        $ip_keys = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
        
        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = sanitize_text_field(wp_unslash($_SERVER[$key]));
                // Handle comma-separated IPs
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        
        return 'unknown';
    }
    
    /**
     * Send security alert to admin
     *
     * @param array $event
     */
    private function send_security_alert($event) {
        $admin_email = get_option('admin_email');
        /* translators: 1: Site name, 2: Event type */
        $subject = sprintf(esc_html__('[%1$s] Security Alert: %2$s', 'infy-ai-image-seo-optimizer'), get_bloginfo('name'), $event['event_type']);
        
        $message = sprintf(
            /* translators: 1: Event type, 2: Timestamp, 3: User ID, 4: IP address, 5: Event details */
            esc_html__('A critical security event has been detected:

Event: %1$s
Time: %2$s
User ID: %3$s
IP: %4$s
Details: %5$s', 'infy-ai-image-seo-optimizer'),
            $event['event_type'],
            $event['timestamp'],
            $event['user_id'],
            $event['ip_address'],
            wp_json_encode($event['data'])
        );
        
        wp_mail($admin_email, $subject, $message);
    }
    
    /**
     * Check if current page is plugin page
     *
     * @return bool
     */
    private function is_plugin_page() {
        $screen = get_current_screen();
        return $screen && strpos($screen->id, 'ai-image-seo-optimizer') !== false;
    }

}