<?php
/**
 * Centralized Error Handling and Logging System
 *
 * This file contains the INFYP_Error_Handler class which provides standardized
 * error handling, logging, and user feedback mechanisms across the entire plugin.
 * It ensures consistent error reporting and helps with debugging and troubleshooting.
 *
 * Error Management Features:
 * - Categorized error logging (security, database, API, validation, etc.)
 * - Contextual error information tracking
 * - User-friendly error message generation
 * - AJAX error response handling
 * - Input validation with error feedback
 * - IP address tracking for security events
 * - Separate logging for different error severities
 *
 * Error Categories:
 * - Security: CRITICAL level (authentication, authorization failures)
 * - Database: ERROR level (query failures, data integrity issues)
 * - File Operation: ERROR level (file system errors)
 * - API: WARNING level (external API failures)
 * - Validation: WARNING level (input validation failures)
 * - General: INFO level (informational messages)
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
 * Centralized Error Handler for Consistent Error Management
 *
 * Implements singleton pattern to provide a unified error handling interface
 * across the plugin. All errors should be channeled through this class to ensure
 * consistent logging, categorization, and user feedback.
 *
 * Design Pattern: Singleton
 * Ensures all errors are logged through a single instance, maintaining consistent
 * error tracking and preventing duplicate error handling logic.
 *
 * Error Flow:
 * 1. **Technical Error Occurs**: Exception, WP_Error, or error condition
 * 2. **Log Technical Details**: Full error context logged for debugging
 * 3. **Generate User Message**: Sanitized, user-friendly error message
 * 4. **Return WP_Error**: Standardized error object for handling
 *
 * Usage Example:
 * ```php
 * $handler = INFYP_Error_Handler::get_instance();
 *
 * // Log an error with context
 * $handler->log_error(
 *     $exception,
 *     'image_optimization',
 *     'api',
 *     ['image_id' => 123, 'model' => $model_id]
 * );
 *
 * // Create user-friendly error
 * $error = $handler->create_error(
 *     'Unable to optimize image. Please try again.',
 *     $technical_exception,
 *     'image_optimization',
 *     'api'
 * );
 *
 * // Handle AJAX errors
 * $handler->handle_ajax_error(
 *     'Operation failed',
 *     $exception,
 *     'ajax_operation',
 *     'validation'
 * );
 * ```
 *
 * @package    AI_Image_SEO
 * @subpackage Core
 * @since 1.0.0
 *
 * @see WP_Error For WordPress error handling
 * @see wp_send_json_error() For AJAX error responses
 */
class INFYP_Error_Handler {

    /**
     * Singleton instance.
     *
     * @since 1.0.0
     * @var    INFYP_Error_Handler|null
     * @access private
     */
    private static $instance = null;

    /**
     * Error types mapped to their log severity levels.
     *
     * @since 1.0.0
     * @var   array<string, string>
     */
    const ERROR_TYPES = [
        'security' => 'CRITICAL',
        'database' => 'ERROR', 
        'file_operation' => 'ERROR',
        'api' => 'WARNING',
        'validation' => 'WARNING',
        'general' => 'INFO'
    ];
    
    /**
     * Private constructor for singleton pattern.
     *
     * @since 1.0.0
     * @access private
     */
    private function __construct() {
        // Private constructor for singleton
    }

    /**
     * Get the singleton instance.
     *
     * @since 1.0.0
     *
     * @return INFYP_Error_Handler The singleton instance.
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Log an error with context and automatic categorization
     *
     * @param mixed $error Error object, string, or WP_Error
     * @param string $operation Operation being performed
     * @param string $type Error type (security, database, etc.)
     * @param array $context Additional context data
     * @return void
     */
    public function log_error($error, $operation = 'unknown', $type = 'general', $context = []) {
        $log_level = self::ERROR_TYPES[$type] ?? 'INFO';
        
        // Format error message
        if (is_wp_error($error)) {
            $message = $error->get_error_message();
            $code = $error->get_error_code();
        } elseif ($error instanceof \Exception) {
            $message = $error->getMessage();
            $code = $error->getCode();
        } else {
            $message = (string) $error;
            $code = 0;
        }
        
        // Build log entry
        $log_entry = sprintf(
            '[INFYP %s] %s | Operation: %s | Message: %s',
            $log_level,
            $type,
            $operation,
            $message
        );
        
        // Add code if available
        if ($code) {
            /* translators: %s: Error code */
            $log_entry .= sprintf(' | Code: %s', $code);
        }
        
        // Add context if provided
        if (!empty($context)) {
            $log_entry .= ' | Context: ' . wp_json_encode($context);
        }
        
        // Add user context for security issues
        if ($type === 'security') {
            $user_id = get_current_user_id();
            $user_ip = $this->get_user_ip();
            /* translators: 1: User ID, 2: IP address */
            $log_entry .= sprintf(' | User: %1$d | IP: %2$s', $user_id, $user_ip);
        }
        
        INFYP_Logger::log( $log_entry, $log_level );
    }
    
    /**
     * Create a user-friendly error response
     *
     * @param string $user_message Message safe to show to users
     * @param mixed $technical_error Technical error for logging
     * @param string $operation Operation context
     * @param string $type Error type
     * @return WP_Error
     */
    public function create_error($user_message, $technical_error = null, $operation = 'unknown', $type = 'general') {
        // Log the technical details
        if ($technical_error) {
            $this->log_error($technical_error, $operation, $type);
        }
        
        // Return user-friendly WP_Error
        return new \WP_Error(
            'infyp_' . $type . '_error',
            $user_message,
            ['operation' => $operation, 'type' => $type]
        );
    }
    
    /**
     * Handle AJAX errors consistently
     *
     * @param string $user_message User-friendly message
     * @param mixed $technical_error Technical error for logging
     * @param string $operation Operation context
     * @param string $type Error type
     * @return void (sends JSON response and exits)
     */
    public function handle_ajax_error($user_message, $technical_error = null, $operation = 'ajax_operation', $type = 'general') {
        // Log the error
        if ($technical_error) {
            $this->log_error($technical_error, $operation, $type);
        } else {
            $this->log_error($user_message, $operation, $type);
        }
        
        // Send JSON error response
        wp_send_json_error(['message' => $user_message]);
    }
    
    /**
     * Validate and sanitize user input with error handling
     *
     * @param mixed $input Input to validate
     * @param string $type Validation type (email, filename, etc.)
     * @param string $operation Operation context
     * @return mixed Sanitized input or WP_Error on failure
     */
    public function validate_input($input, $type, $operation = 'input_validation') {
        switch ($type) {
            case 'filename':
                $sanitized = $this->sanitize_filename($input);
                if ($sanitized === false) {
                    return $this->create_error(
                        esc_html__('Invalid filename provided.', 'infy-ai-image-seo-optimizer'),
                        'Invalid filename: ' . $input,
                        $operation,
                        'validation'
                    );
                }
                return $sanitized;
                
            case 'post_id':
                $post_id = intval($input);
                if ($post_id <= 0 || !get_post($post_id)) {
                    return $this->create_error(
                        esc_html__('Invalid post ID provided.', 'infy-ai-image-seo-optimizer'),
                        'Invalid post ID: ' . $input,
                        $operation,
                        'validation'
                    );
                }
                return $post_id;
                
            default:
                return sanitize_text_field($input);
        }
    }
    
    /**
     * Safely get user IP address
     *
     * @return string
     */
    private function get_user_ip() {
        $ip_keys = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
        
        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = sanitize_text_field(wp_unslash($_SERVER[$key]));
                // Handle comma-separated IPs (forwarded headers)
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                
                // Basic IP validation
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return 'unknown';
    }
    
    /**
     * Sanitize filename for security
     *
     * @param string $filename
     * @return string|false
     */
    private function sanitize_filename($filename) {
        // Remove directory traversal attempts
        $filename = basename($filename);
        
        // Remove any remaining path separators
        $filename = str_replace(['/', '\\', '..'], '', $filename);
        
        // Remove special characters that could be dangerous
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '', $filename);
        
        // Ensure filename is not empty and not too long
        if (empty($filename) || strlen($filename) > 100) {
            return false;
        }
        
        // Prevent hidden files and current/parent directory references
        if (strpos($filename, '.') === 0) {
            return false;
        }
        
        return $filename;
    }
}
