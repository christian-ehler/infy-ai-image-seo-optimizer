<?php
namespace IPAIS\Core;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * API Retry Handler
 * 
 * Implements exponential backoff and retry logic for API calls
 *
 * @package IPAIS\Core
 * @since 1.0.0
 */
class INFYP_API_Retry_Handler {
    
    /**
     * Maximum retry attempts
     */
    const MAX_RETRIES = 3;
    
    /**
     * Base delay in seconds
     */
    const BASE_DELAY = 1;
    
    /**
     * Maximum delay in seconds
     */
    const MAX_DELAY = 32;
    
    /**
     * Rate limit tracking
     */
    private static $rate_limits = [];
    
    /**
     * Execute API call with retry logic.
     *
     * In 'sync' context (default for AJAX), retries are skipped and errors
     * return immediately to avoid blocking the web request with sleep().
     * In 'background' context (cron/queue), exponential backoff retries apply.
     *
     * @param callable $api_callback The API call to execute.
     * @param string   $provider     The API provider.
     * @param string   $model        The model being used.
     * @param int|null $image_id     The image being processed.
     * @param string   $context      'sync' (AJAX — no retry) or 'background' (cron — retry with sleep).
     * @return mixed|WP_Error
     */
    public static function execute_with_retry($api_callback, $provider, $model, $image_id = null, $context = 'sync') {
        $config = apply_filters('infyp_api_retry_config', [
            'max_retries' => self::MAX_RETRIES,
            'base_delay'  => self::BASE_DELAY,
            'max_delay'   => self::MAX_DELAY,
        ]);
        // In sync context (AJAX), never retry — return errors immediately.
        $max_retries = 'sync' === $context ? 0 : $config['max_retries'];
        $base_delay  = $config['base_delay'];
        $max_delay   = $config['max_delay'];

        $retry_count = 0;
        $last_error = null;
        $tracker = INFYP_API_Usage_Tracker::get_instance();
        
        // Check if we're rate limited
        if (self::is_rate_limited($provider)) {
            $wait_time = self::get_rate_limit_wait_time($provider);
            
            $tracker->track_api_call($provider, $model, 'rate_limited', [
                'error_message' => sprintf('Rate limited. Need to wait %d seconds.', $wait_time),
                'rate_limited' => 1
            ], $image_id);
            
            return new \WP_Error(
                'rate_limited',
                /* translators: %d: number of seconds to wait */
                sprintf(__('API rate limit reached. Please wait %d seconds before trying again.', 'infy-ai-image-seo-optimizer'), $wait_time)
            );
        }
        
        while ($retry_count <= $max_retries) {
            $start_time = microtime(true);
            
            try {
                // Execute the API call
                $result = call_user_func($api_callback);
                
                $response_time = microtime(true) - $start_time;
                
                // Check if result is an error
                if (is_wp_error($result)) {
                    $error_code = $result->get_error_code();
                    $error_message = $result->get_error_message();
                    
                    // Check for rate limiting
                    if (self::is_rate_limit_error($error_code, $error_message)) {
                        self::set_rate_limit($provider);
                        
                        $tracker->track_api_call($provider, $model, 'rate_limited', [
                            'error_message' => $error_message,
                            'retry_count' => $retry_count,
                            'response_time' => $response_time,
                            'rate_limited' => 1
                        ], $image_id);
                        
                        // If we have retries left, wait and retry
                        if ($retry_count < $max_retries) {
                            $delay = self::calculate_backoff_delay($retry_count, $base_delay, $max_delay);
                            INFYP_Logger::log(sprintf(
                                'Rate limited by %s. Waiting %d seconds before retry %d/%d',
                                $provider,
                                $delay,
                                $retry_count + 1,
                                $max_retries
                            ), 'WARNING');
                            
                            sleep($delay);
                            $retry_count++;
                            continue;
                        }
                    }
                    
                    // Check if this is a retryable error
                    if (self::is_retryable_error($error_code, $error_message)) {
                        $tracker->track_api_call($provider, $model, 'error', [
                            'error_message' => $error_message,
                            'retry_count' => $retry_count,
                            'response_time' => $response_time
                        ], $image_id);
                        
                        if ($retry_count < $max_retries) {
                            $delay = self::calculate_backoff_delay($retry_count, $base_delay, $max_delay);
                            INFYP_Logger::log(sprintf(
                                'API error from %s: %s. Retrying in %d seconds (%d/%d)',
                                $provider,
                                $error_message,
                                $delay,
                                $retry_count + 1,
                                $max_retries
                            ), 'WARNING');
                            
                            sleep($delay);
                            $retry_count++;
                            $last_error = $result;
                            continue;
                        }
                    }
                    
                    // Non-retryable error
                    $tracker->track_api_call($provider, $model, 'error', [
                        'error_message' => $error_message,
                        'retry_count' => $retry_count,
                        'response_time' => $response_time
                    ], $image_id);
                    
                    return $result;
                }
                
                // Success! Under WordPress AI the core client may have served
                // the request with a different model than requested (the model
                // is only a preference) — log the model that actually answered.
                $logged_model = $model;
                if ('wordpress' === $provider) {
                    $serving = \IPAIS\Core\AI\INFYP_WP_AI_Provider::get_last_serving_model();
                    if ('' !== $serving && $serving !== $model) {
                        $logged_model = $serving;
                        // Core reports the provider's API id; log the registry id
                        // it belongs to, so the row is priced like the direct path.
                        $registry = \IPAIS\Core\AI\INFYP_Model_Config_Registry::get_instance();
                        foreach (array_keys($registry->get_all_configs()) as $registry_id) {
                            if ($serving === $registry->resolve_api_model_id($registry_id)) {
                                $logged_model = $registry_id;
                                break;
                            }
                        }
                    }
                }

                $tracker->track_api_call($provider, $logged_model, 'success', [
                    'retry_count' => $retry_count,
                    'response_time' => $response_time,
                    'tokens_used' => self::estimate_tokens($result)
                ], $image_id);
                
                // Clear rate limit on success
                self::clear_rate_limit($provider);
                
                return $result;
                
            } catch (\Exception $e) {
                $response_time = microtime(true) - $start_time;
                
                $tracker->track_api_call($provider, $model, 'error', [
                    'error_message' => $e->getMessage(),
                    'retry_count' => $retry_count,
                    'response_time' => $response_time
                ], $image_id);
                
                if ($retry_count < $max_retries) {
                    $delay = self::calculate_backoff_delay($retry_count, $base_delay, $max_delay);
                    INFYP_Logger::log(sprintf(
                        'Exception from %s: %s. Retrying in %d seconds (%d/%d)',
                        $provider,
                        $e->getMessage(),
                        $delay,
                        $retry_count + 1,
                        $max_retries
                    ), 'ERROR');
                    
                    sleep($delay);
                    $retry_count++;
                    $last_error = new \WP_Error('api_exception', $e->getMessage());
                    continue;
                }
                
                return new \WP_Error('api_exception', $e->getMessage());
            }
        }
        
        // All retries exhausted
        return $last_error ?: new \WP_Error(
            'max_retries_exceeded',
            /* translators: %d: number of retry attempts */
            sprintf(__('Failed after %d retry attempts', 'infy-ai-image-seo-optimizer'), $max_retries)
        );
    }
    
    /**
     * Calculate exponential backoff delay
     */
    private static function calculate_backoff_delay($retry_count, $base_delay = null, $max_delay = null) {
        $base_delay = $base_delay ?? self::BASE_DELAY;
        $max_delay = $max_delay ?? self::MAX_DELAY;
        $delay = min($base_delay * pow(2, $retry_count), $max_delay);
        
        // Add jitter (±20%)
        $jitter = $delay * 0.2;
        $delay = $delay + (wp_rand() / getrandmax() * 2 - 1) * $jitter;
        
        return max(1, round($delay));
    }
    
    /**
     * Check if error is retryable
     */
    private static function is_retryable_error($error_code, $error_message) {
        $retryable_codes = [
            'http_request_failed',
            'timeout',
            'connection_error',
            'server_error',
            '500',
            '502',
            '503',
            '504'
        ];
        
        if (in_array($error_code, $retryable_codes)) {
            return true;
        }
        
        // Check error message for retryable patterns
        $retryable_patterns = [
            'timeout',
            'timed out',
            'connection',
            'temporary',
            'try again',
            'server error',
            'unavailable'
        ];
        
        $error_lower = strtolower($error_message);
        foreach ($retryable_patterns as $pattern) {
            if (strpos($error_lower, $pattern) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if error indicates rate limiting
     */
    private static function is_rate_limit_error($error_code, $error_message) {
        $rate_limit_codes = ['429', 'rate_limit', 'too_many_requests'];
        
        if (in_array($error_code, $rate_limit_codes)) {
            return true;
        }
        
        $rate_limit_patterns = [
            'rate limit',
            'too many requests',
            'quota exceeded',
            '429'
        ];
        
        $error_lower = strtolower($error_message);
        foreach ($rate_limit_patterns as $pattern) {
            if (strpos($error_lower, $pattern) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Set rate limit for provider
     */
    private static function set_rate_limit($provider, $wait_seconds = 60) {
        self::$rate_limits[$provider] = time() + $wait_seconds;
        
        // Also store in transient for persistence
        set_transient('infyp_rate_limit_' . $provider, self::$rate_limits[$provider], $wait_seconds);
    }
    
    /**
     * Check if provider is rate limited
     */
    private static function is_rate_limited($provider) {
        // Check in-memory first
        if (isset(self::$rate_limits[$provider]) && self::$rate_limits[$provider] > time()) {
            return true;
        }
        
        // Check transient
        $limit_time = get_transient('infyp_rate_limit_' . $provider);
        if ($limit_time && $limit_time > time()) {
            self::$rate_limits[$provider] = $limit_time;
            return true;
        }
        
        return false;
    }
    
    /**
     * Get wait time for rate limited provider
     */
    private static function get_rate_limit_wait_time($provider) {
        $limit_time = self::$rate_limits[$provider] ?? get_transient('infyp_rate_limit_' . $provider);
        
        if ($limit_time) {
            return max(0, $limit_time - time());
        }
        
        return 0;
    }
    
    /**
     * Clear rate limit
     */
    private static function clear_rate_limit($provider) {
        unset(self::$rate_limits[$provider]);
        delete_transient('infyp_rate_limit_' . $provider);
    }
    
    /**
     * Estimate tokens used (rough approximation)
     */
    private static function estimate_tokens($result) {
        if (!is_array($result)) {
            return 1000; // Default estimation
        }
        
        // Rough estimation based on text length
        $text_length = 0;
        foreach (['alt_text', 'title', 'filename', 'description'] as $field) {
            if (isset($result[$field])) {
                $text_length += strlen($result[$field]);
            }
        }
        
        // Roughly 4 characters per token + base for image analysis
        return 1000 + ceil($text_length / 4);
    }
}