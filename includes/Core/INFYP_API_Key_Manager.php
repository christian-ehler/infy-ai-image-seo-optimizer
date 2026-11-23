<?php
/**
 * Secure API Key Management System
 *
 * This file contains the INFYP_API_Key_Manager class which provides centralized,
 * secure management of API keys for multiple AI providers. It implements encryption,
 * validation, and secure access patterns to protect sensitive API credentials.
 *
 * Security Features:
 * - Encryption at rest using WordPress salts
 * - In-memory caching (cleared on shutdown)
 * - Validation of API key formats
 * - Secure migration from plaintext to encrypted keys
 * - Provider-based key organization
 *
 * Supported Providers:
 * - OpenAI (GPT models)
 * - Google Gemini (Gemini models)
 * - Anthropic (Claude models)
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
 * API Key Manager for Secure Credential Storage
 *
 * Implements singleton pattern to provide centralized API key management with
 * encryption, validation, and secure access controls. All API keys are encrypted
 * before storage and decrypted only when needed, with in-memory caching for
 * performance.
 *
 * Design Pattern: Singleton
 * Ensures only one instance manages API keys, providing consistent encryption
 * keys and cache state throughout the request lifecycle.
 *
 * Encryption Strategy:
 * API keys are encrypted using WordPress salts (AUTH_KEY, SECURE_AUTH_KEY, etc.)
 * which are unique per installation. This provides encryption at rest while
 * allowing decryption when needed for API calls.
 *
 * Key Storage Structure:
 * ```
 * infyp_api_keys => [
 *     'openai' => 'encrypted_key_string',
 *     'google' => 'encrypted_key_string',
 *     'anthropic' => 'encrypted_key_string'
 * ]
 * ```
 *
 * Usage Example:
 * ```php
 * $manager = INFYP_API_Key_Manager::get_instance();
 *
 * // Store encrypted API key
 * $manager->set_api_key( 'openai', 'sk-...' );
 *
 * // Retrieve decrypted key
 * $api_key = $manager->get_api_key( 'openai' );
 *
 * // Check if key exists
 * if ( $manager->has_api_key( 'google' ) ) {
 *     // Use Gemini API
 * }
 * ```
 *
 * @package    AI_Image_SEO
 * @subpackage Core
 * @since 1.0.0
 *
 * @see INFYP_Encryption_Service For encryption implementation
 * @see get_option() For retrieving stored keys
 * @see update_option() For storing encrypted keys
 */
class INFYP_API_Key_Manager {
    
    /**
     * Singleton instance
     *
     * @since 1.0.0
     * @var    INFYP_API_Key_Manager|null
     * @access private
     */
    private static $instance = null;

    /**
     * Encryption service instance
     *
     * @since 1.0.0
     * @var    INFYP_Encryption_Service
     * @access private
     */
    private $encryption;

    /**
     * In-memory cache for decrypted API keys
     *
     * Stores decrypted keys temporarily during request to avoid repeated
     * decryption operations. Cleared on shutdown for security.
     *
     * @since 1.0.0
     * @var    array
     * @access private
     */
    private $key_cache = [];

    /**
     * Built-in API provider slugs.
     *
     * @since 1.0.0
     * @var   array
     */
    const BUILTIN_PROVIDERS = ['openai', 'google', 'anthropic'];

    /**
     * Returns the list of allowed API key provider slugs.
     *
     * Defaults to the three built-in providers but can be extended
     * via the `infyp_allowed_api_key_providers` filter so that
     * add-ons registering custom AI providers can also store API keys.
     *
     * @since 1.0.0
     * @return array Provider slug strings.
     */
    public static function get_allowed_providers() {
        /** This filter is documented in INFYP_Settings::sanitize_api_keys() */
        return apply_filters( 'infyp_allowed_api_key_providers', self::BUILTIN_PROVIDERS );
    }
    
    /**
     * Get the singleton instance.
     *
     * @since 1.0.0
     *
     * @return INFYP_API_Key_Manager The singleton instance.
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
     * Initializes the encryption service and registers shutdown and migration hooks.
     *
     * @since 1.0.0
     * @access private
     */
    private function __construct() {
        $this->encryption = INFYP_Encryption_Service::get_instance();
        
        // Clear cache on shutdown
        add_action('shutdown', [$this, 'clear_cache']);
    }
    
    /**
     * Get API key for a specific provider
     *
     * @param string $provider The API provider (openai, google, anthropic)
     * @return string|null Decrypted API key or null if not found
     */
    public function get_api_key($provider) {
        if (!in_array($provider, self::get_allowed_providers(), true)) {
            return null;
        }
        
        // Check memory cache first
        if (isset($this->key_cache[$provider])) {
            return $this->key_cache[$provider];
        }
        
        // Get encrypted keys from database
        $encrypted_keys = get_option('infyp_api_keys', []);
        
        if (!isset($encrypted_keys[$provider]) || empty($encrypted_keys[$provider])) {
            return null;
        }
        
        // Decrypt the key
        $decrypted_key = $this->encryption->decrypt($encrypted_keys[$provider]);
        
        if ($decrypted_key === false) {
            return null;
        }
        
        // Cache in memory for this request
        $this->key_cache[$provider] = $decrypted_key;
        
        return $decrypted_key;
    }
    
    /**
     * Set API key for a specific provider
     *
     * @param string $provider The API provider
     * @param string $key The API key to store
     * @return bool True on success
     */
    public function set_api_key($provider, $key) {
        if (!in_array($provider, self::get_allowed_providers(), true)) {
            return false;
        }
        
        // Get current keys
        $api_keys = get_option('infyp_api_keys', []);
        
        // Encrypt and store the key
        if (!empty($key)) {
            $encrypted_key = $this->encryption->encrypt($key);
            if ($encrypted_key === false) {
                return false;
            }
            $api_keys[$provider] = $encrypted_key;
        } else {
            // Remove empty keys
            unset($api_keys[$provider]);
        }
        
        // Update cache
        $this->key_cache[$provider] = $key;
        
        // Save to database
        return update_option('infyp_api_keys', $api_keys);
    }
    
    /**
     * Set multiple API keys at once
     *
     * @param array $keys Array of provider => key pairs
     * @return bool True on success
     */
    public function set_api_keys($keys) {
        if (!is_array($keys)) {
            return false;
        }
        
        $api_keys = get_option('infyp_api_keys', []);
        
        foreach (self::get_allowed_providers() as $provider) {
            if (isset($keys[$provider])) {
                // Minimal sanitization for API keys - just trim whitespace and remove any HTML
                // API keys contain various characters that should not be modified
                $key = trim( wp_strip_all_tags( $keys[$provider] ) );

                if (!empty($key)) {
                    $encrypted_key = $this->encryption->encrypt($key);
                    if ($encrypted_key !== false) {
                        $api_keys[$provider] = $encrypted_key;
                        $this->key_cache[$provider] = $key;
                    }
                } else {
                    unset($api_keys[$provider]);
                    unset($this->key_cache[$provider]);
                }
            }
        }
        
        return update_option('infyp_api_keys', $api_keys);
    }
    
    /**
     * Get all API keys (decrypted)
     *
     * @param bool $mask Whether to mask the keys for display
     * @return array Array of provider => key pairs
     */
    public function get_all_api_keys($mask = false) {
        $encrypted_keys = get_option('infyp_api_keys', []);
        $decrypted_keys = [];
        
        foreach (self::get_allowed_providers() as $provider) {
            if (isset($encrypted_keys[$provider]) && !empty($encrypted_keys[$provider])) {
                $decrypted = $this->encryption->decrypt($encrypted_keys[$provider]);
                if ($decrypted !== false) {
                    $decrypted_keys[$provider] = $mask ? $this->encryption->get_masked_key($decrypted) : $decrypted;
                }
            } else {
                $decrypted_keys[$provider] = '';
            }
        }
        
        return $decrypted_keys;
    }
    
    /**
     * Check if API key exists for a provider
     *
     * @param string $provider The API provider
     * @return bool True if key exists
     */
    public function has_api_key($provider) {
        $key = $this->get_api_key($provider);
        return !empty($key);
    }
    
    /**
     * Get API key status for all providers
     *
     * @return array Array of provider => bool pairs
     */
    public function get_api_key_status() {
        $status = [];
        
        foreach (self::get_allowed_providers() as $provider) {
            $status[$provider] = $this->has_api_key($provider);
        }
        
        return $status;
    }
    
    /**
     * Validate API key format
     *
     * @param string $provider The API provider
     * @param string $key The API key to validate
     * @return bool|\WP_Error True if valid, WP_Error otherwise
     */
    public function validate_api_key($provider, $key) {
        if (empty($key)) {
            return new \WP_Error('empty_key', esc_html__('API key cannot be empty', 'infy-ai-image-seo-optimizer'));
        }

        // Deliberately provider-agnostic: providers change their key formats
        // (Google did in 2026), so structural assumptions ("must start with
        // AIza") reject valid keys sooner or later. Only reject values that
        // cannot be a secret at all; real verification is a live call in
        // test_api_key().
        if (strlen($key) < 8) {
            return new \WP_Error('invalid_length', esc_html__('API key appears too short', 'infy-ai-image-seo-optimizer'));
        }

        if (strlen($key) > 512) {
            return new \WP_Error('invalid_length', esc_html__('API key appears too long', 'infy-ai-image-seo-optimizer'));
        }

        if (preg_match('/[\s\x00-\x1F\x7F]/', $key)) {
            return new \WP_Error('invalid_characters', esc_html__('API keys cannot contain spaces or control characters', 'infy-ai-image-seo-optimizer'));
        }

        return true;
    }

    /**
     * Verify an API key with a live request to the provider.
     *
     * Performs a zero-token, authenticated models-list request. This is the
     * authoritative check — format heuristics are not (see validate_api_key).
     *
     * Three-state result by design:
     * - 'valid'      HTTP 2xx — the provider accepted the key.
     * - 'invalid'    HTTP 400/401/403 — rejected; the provider's own error
     *                message is passed through (e.g. Anthropic's "Identity
     *                verification is required" means a REAL key with an
     *                account-level block, and the user must see that).
     * - 'unverified' network failure / timeout / any other status — the key
     *                could not be checked; this must never be presented as
     *                "invalid".
     *
     * Results are cached for 5 minutes per provider+key so repeated UI
     * checks do not hammer provider APIs.
     *
     * @since 1.0.6
     *
     * @param string      $provider The API provider slug.
     * @param string|null $key      Key to test, or null to test the stored key.
     * @return array {
     *     @type string $state   'valid' | 'invalid' | 'unverified'.
     *     @type string $message Human-readable detail for the UI.
     * }
     */
    public function test_api_key($provider, $key = null) {
        if (null === $key || '' === $key) {
            $key = (string) $this->get_api_key($provider);
        }

        if ('' === $key) {
            return [
                'state'   => 'invalid',
                'message' => esc_html__('No API key stored for this provider.', 'infy-ai-image-seo-optimizer'),
            ];
        }

        $cache_id = 'infyp_keytest_' . md5($provider . '|' . $key);
        $cached   = get_transient($cache_id);
        if (is_array($cached) && isset($cached['state'])) {
            return $cached;
        }

        /**
         * Filter the live-test request definitions per provider.
         *
         * Each entry: [ 'url' => string, 'headers' => array ] — headers may
         * reference the key via the %key% placeholder. Extensions register
         * their providers here so the Test button works for them too.
         *
         * @since 1.0.6
         *
         * @param array $endpoints Request definitions keyed by provider slug.
         */
        $endpoints = apply_filters('infyp_api_key_test_endpoints', [
            'google'    => [
                'url'     => 'https://generativelanguage.googleapis.com/v1beta/models?pageSize=1',
                'headers' => [ 'x-goog-api-key' => '%key%' ],
            ],
            'openai'    => [
                'url'     => 'https://api.openai.com/v1/models',
                'headers' => [ 'Authorization' => 'Bearer %key%' ],
            ],
            'anthropic' => [
                'url'     => 'https://api.anthropic.com/v1/models',
                'headers' => [
                    'x-api-key'         => '%key%',
                    'anthropic-version' => '2023-06-01',
                ],
            ],
        ]);

        if (empty($endpoints[$provider]['url'])) {
            return [
                'state'   => 'unverified',
                'message' => esc_html__('No live test is available for this provider.', 'infy-ai-image-seo-optimizer'),
            ];
        }

        $headers = [];
        foreach ((array) ($endpoints[$provider]['headers'] ?? []) as $name => $value) {
            $headers[$name] = str_replace('%key%', $key, (string) $value);
        }

        $response = wp_remote_get(
            $endpoints[$provider]['url'],
            [
                'headers' => $headers,
                'timeout' => 10,
            ]
        );

        if (is_wp_error($response)) {
            // Network problems are not the key's fault — never report invalid.
            $result = [
                'state'   => 'unverified',
                'message' => esc_html__('Could not reach the provider to verify the key. It was saved and can be tested again later.', 'infy-ai-image-seo-optimizer'),
            ];
            set_transient($cache_id, $result, MINUTE_IN_SECONDS);
            return $result;
        }

        $code = (int) wp_remote_retrieve_response_code($response);

        if ($code >= 200 && $code < 300) {
            $result = [
                'state'   => 'valid',
                'message' => esc_html__('Key verified with the provider.', 'infy-ai-image-seo-optimizer'),
            ];
        } elseif (in_array($code, [ 400, 401, 403 ], true)) {
            $body   = json_decode((string) wp_remote_retrieve_body($response), true);
            $detail = '';
            if (is_array($body)) {
                // google: error.message | openai/anthropic: error.message
                $detail = (string) ($body['error']['message'] ?? '');
                // The reply is cached and shown: drop any echo of the tested
                // key, e.g. OpenAI's "sk-abc12****wxyz" (first/last characters).
                $detail = (string) preg_replace('/[\w-]*\*{4,}[\w-]*/', '****', str_replace($key, '****', $detail));
            }
            $result = [
                'state'   => 'invalid',
                'message' => '' !== $detail
                    ? $detail
                    : sprintf(
                        /* translators: %d: HTTP status code. */
                        esc_html__('The provider rejected this key (HTTP %d).', 'infy-ai-image-seo-optimizer'),
                        $code
                    ),
            ];
        } else {
            $result = [
                'state'   => 'unverified',
                'message' => sprintf(
                    /* translators: %d: HTTP status code. */
                    esc_html__('Verification inconclusive (HTTP %d). The key was saved.', 'infy-ai-image-seo-optimizer'),
                    $code
                ),
            ];
        }

        set_transient($cache_id, $result, 5 * MINUTE_IN_SECONDS);
        return $result;
    }
    
    /**
     * Delete API key for a provider
     *
     * @param string $provider The API provider
     * @return bool True on success
     */
    public function delete_api_key($provider) {
        if (!in_array($provider, self::get_allowed_providers(), true)) {
            return false;
        }
        
        $api_keys = get_option('infyp_api_keys', []);
        unset($api_keys[$provider]);
        unset($this->key_cache[$provider]);
        
        return update_option('infyp_api_keys', $api_keys);
    }
    
    /**
     * Clear the in-memory key cache.
     *
     * Called on shutdown to prevent decrypted keys from persisting in memory
     * longer than necessary.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function clear_cache() {
        $this->key_cache = [];
    }
    
    /**
     * Resolves a model identifier to its provider slug.
     *
     * Checks built-in model name prefixes first, then consults the
     * Model Config Registry for custom models registered via
     * `infyp_register_model_configs`. Falls back to the
     * `infyp_provider_for_model` filter for edge cases.
     *
     * @since 1.0.0
     * @param string $model The AI model identifier (a registry model id or alias).
     * @return string|null Provider slug or null if unresolvable.
     */
    public function get_provider_for_model($model) {
        // Built-in prefix matching for core providers.
        if (strpos($model, 'gemini') === 0) {
            return 'google';
        } elseif (strpos($model, 'gpt') === 0) {
            return 'openai';
        } elseif (strpos($model, 'claude') === 0) {
            return 'anthropic';
        }

        // Fallback: consult the Model Config Registry for custom models.
        $config = \IPAIS\Core\AI\INFYP_Model_Config_Registry::get_instance()->get_config($model);
        if ($config && !empty($config['provider'])) {
            return $config['provider'];
        }

        /**
         * Filters the provider slug resolved for a model identifier.
         *
         * Fires only when built-in prefix matching and the Model Config
         * Registry both fail to resolve the provider. Extensions can use
         * this to map custom model names to their provider slug.
         *
         * @since 1.0.0
         * @param string|null $provider Resolved provider slug (null at this point).
         * @param string      $model    The model identifier being resolved.
         */
        return apply_filters('infyp_provider_for_model', null, $model);
    }
}