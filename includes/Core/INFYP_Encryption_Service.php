<?php
namespace IPAIS\Core;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Encryption Service for Infy AI Image SEO
 * 
 * Provides secure encryption/decryption for sensitive data like API keys
 * using WordPress salts and OpenSSL for production-ready security.
 *
 * @package IPAIS\Core
 * @since 1.0.0
 */
class INFYP_Encryption_Service {
    
    /**
     * Singleton instance
     */
    private static $instance = null;
    
    /**
     * Encryption method
     */
    const CIPHER_METHOD = 'aes-256-gcm';
    
    /**
     * Tag length for GCM
     */
    const TAG_LENGTH = 16;
    
    /**
     * Salt for key derivation
     */
    const KEY_SALT = 'infyp_encryption_2024';
    
    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Private constructor
     */
    private function __construct() {
        // Check if OpenSSL is available
        if (!extension_loaded('openssl')) {
            add_action('admin_notices', [$this, 'show_openssl_notice']);
        }
    }
    
    /**
     * Encrypt sensitive data
     *
     * @param string $data Plain text data to encrypt
     * @return string|false Base64 encoded encrypted data with IV and tag, or false on failure
     */
    public function encrypt($data) {
        if (empty($data)) {
            return '';
        }
        
        // Check OpenSSL availability
        if (!extension_loaded('openssl')) {
            return false;
        }
        
        try {
            // Generate encryption key
            $key = $this->get_encryption_key();
            
            // Generate random IV
            $iv_length = openssl_cipher_iv_length(self::CIPHER_METHOD);
            $iv = openssl_random_pseudo_bytes($iv_length);
            
            // Encrypt data
            $tag = '';
            $encrypted = openssl_encrypt(
                $data,
                self::CIPHER_METHOD,
                $key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag,
                '',
                self::TAG_LENGTH
            );
            
            if ($encrypted === false) {
                throw new \Exception('Encryption failed');
            }
            
            // Combine IV, tag, and encrypted data
            $combined = $iv . $tag . $encrypted;
            
            // Return base64 encoded
            return base64_encode($combined);
            
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Decrypt sensitive data
     *
     * @param string $encrypted_data Base64 encoded encrypted data
     * @return string|false Decrypted plain text or false on failure
     */
    public function decrypt($encrypted_data) {
        if (empty($encrypted_data)) {
            return '';
        }
        
        // Check if data is already plain text (backward compatibility)
        if (!$this->is_encrypted($encrypted_data)) {
            return $encrypted_data;
        }
        
        // Check OpenSSL availability
        if (!extension_loaded('openssl')) {
            return false;
        }
        
        try {
            // Decode from base64
            $combined = base64_decode($encrypted_data, true);
            if ($combined === false) {
                throw new \Exception('Invalid encrypted data format');
            }
            
            // Extract components
            $iv_length = openssl_cipher_iv_length(self::CIPHER_METHOD);
            if (strlen($combined) < $iv_length + self::TAG_LENGTH) {
                throw new \Exception('Encrypted data too short');
            }
            
            $iv = substr($combined, 0, $iv_length);
            $tag = substr($combined, $iv_length, self::TAG_LENGTH);
            $encrypted = substr($combined, $iv_length + self::TAG_LENGTH);
            
            // Get encryption key
            $key = $this->get_encryption_key();
            
            // Decrypt
            $decrypted = openssl_decrypt(
                $encrypted,
                self::CIPHER_METHOD,
                $key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );
            
            if ($decrypted === false) {
                throw new \Exception('Decryption failed');
            }
            
            return $decrypted;
            
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Check if a string is encrypted
     *
     * @param string $data Data to check
     * @return bool True if encrypted, false otherwise
     */
    public function is_encrypted($data) {
        if (empty($data)) {
            return false;
        }
        
        // Check if it's base64
        if (base64_encode(base64_decode($data, true)) !== $data) {
            return false;
        }
        
        // Decode and check length
        $decoded = base64_decode($data, true);
        if ($decoded === false) {
            return false;
        }
        
        // Check minimum length (IV + tag + at least some data)
        $iv_length = openssl_cipher_iv_length(self::CIPHER_METHOD);
        return strlen($decoded) >= $iv_length + self::TAG_LENGTH + 1;
    }
    
    /**
     * Generate or retrieve the encryption key
     *
     * @return string 32-byte encryption key
     */
    private function get_encryption_key() {
        // Use WordPress AUTH_KEY and SECURE_AUTH_KEY for key derivation
        $auth_key = defined('AUTH_KEY') ? AUTH_KEY : '';
        $secure_auth_key = defined('SECURE_AUTH_KEY') ? SECURE_AUTH_KEY : '';

        // Refuse to encrypt with default/empty WordPress salts
        $placeholder = 'put your unique phrase here';
        if (empty($auth_key) || empty($secure_auth_key) || $auth_key === $placeholder || $secure_auth_key === $placeholder) {
            throw new \RuntimeException('WordPress security keys (AUTH_KEY, SECURE_AUTH_KEY) must be configured for encryption.');
        }
        
        // Combine keys with our salt
        $combined_key = $auth_key . self::KEY_SALT . $secure_auth_key;
        
        // Use PBKDF2 for key derivation
        return hash_pbkdf2('sha256', $combined_key, self::KEY_SALT, 10000, 32, true);
    }
    
    /**
     * Encrypt an array of API keys
     *
     * @param array $api_keys Array of provider => key pairs
     * @return array Encrypted keys
     */
    public function encrypt_api_keys($api_keys) {
        if (!is_array($api_keys)) {
            return [];
        }
        
        $encrypted_keys = [];
        foreach ($api_keys as $provider => $key) {
            if (!empty($key)) {
                $encrypted = $this->encrypt($key);
                if ($encrypted === false) {
                    continue;
                }
                $encrypted_keys[$provider] = $encrypted;
            } else {
                $encrypted_keys[$provider] = '';
            }
        }
        
        return $encrypted_keys;
    }
    
    /**
     * Get masked API key for display
     *
     * @param string $key The API key to mask
     * @param int $visible_chars Number of characters to show at start and end
     * @return string Masked key
     */
    public function get_masked_key($key, $visible_chars = 4) {
        if (empty($key)) {
            return '';
        }
        
        $length = strlen($key);
        
        if ($length <= ($visible_chars * 2)) {
            return str_repeat('•', $length);
        }
        
        $start = substr($key, 0, $visible_chars);
        $end = substr($key, -$visible_chars);
        $masked_length = $length - ($visible_chars * 2);
        
        return $start . str_repeat('•', $masked_length) . $end;
    }
    
    /**
     * Show admin notice if OpenSSL is not available
     */
    public function show_openssl_notice() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'ai-image-seo-optimizer') === false) {
            return;
        }

        echo '<div class="notice notice-warning is-dismissible">';
        echo '<p><strong>' . esc_html__('Image SEO Optimizer - Security Notice', 'infy-ai-image-seo-optimizer') . '</strong></p>';
        echo '<p>' . esc_html__('OpenSSL PHP extension is not available. API keys will be stored without encryption. Please contact your hosting provider to enable OpenSSL for enhanced security.', 'infy-ai-image-seo-optimizer') . '</p>';
        echo '</div>';
    }
    
    /**
     * Test encryption functionality
     *
     * @return array Test results
     */
    public function test_encryption() {
        $results = [
            'openssl_available' => extension_loaded('openssl'),
            'encryption_working' => false,
            'decryption_working' => false,
            'key_generation' => false
        ];
        
        try {
            // Test key generation
            $key = $this->get_encryption_key();
            $results['key_generation'] = (strlen($key) === 32);
            
            // Test encryption/decryption
            $test_data = 'test-api-key-' . time();
            $encrypted = $this->encrypt($test_data);
            
            if ($encrypted !== false && $encrypted !== $test_data) {
                $results['encryption_working'] = true;
                
                $decrypted = $this->decrypt($encrypted);
                $results['decryption_working'] = ($decrypted === $test_data);
            }
            
        } catch (\Exception $e) {
        }
        
        return $results;
    }
}