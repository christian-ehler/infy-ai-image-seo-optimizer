<?php
/**
 * Google OAuth 2.0 Token Management for Search Console Integration
 *
 * This file contains the INFYP_Google_OAuth_Manager class which provides centralized,
 * secure management of Google OAuth 2.0 tokens for Search Console integration. It
 * implements encryption, automatic token refresh, and secure access patterns to protect
 * sensitive OAuth credentials.
 *
 * Security Features:
 * - Encryption at rest using INFYP_Encryption_Service (WordPress salts + OpenSSL)
 * - In-memory caching (cleared on shutdown)
 * - Automatic token refresh before expiry
 * - Token revocation via Google API on disconnect
 * - Support for both built-in and custom OAuth credentials
 *
 * OAuth Flow:
 * 1. User initiates connection (quick or custom method)
 * 2. Authorization URL is generated and user is redirected to Google
 * 3. Google redirects back with authorization code
 * 4. Code is exchanged for access_token + refresh_token
 * 5. Tokens are encrypted and stored in wp_options
 * 6. Access token is auto-refreshed when it expires
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
 * Google OAuth Manager for Search Console Integration
 *
 * Implements singleton pattern to provide centralized Google OAuth 2.0 token
 * management with encryption, automatic refresh, and secure access controls.
 * All tokens are encrypted before storage and decrypted only when needed,
 * with in-memory caching for performance.
 *
 * Design Pattern: Singleton
 * Ensures only one instance manages OAuth tokens, providing consistent
 * encryption keys and cache state throughout the request lifecycle.
 *
 * Encryption Strategy:
 * Tokens are stored as a JSON blob, encrypted using INFYP_Encryption_Service
 * which leverages WordPress salts (AUTH_KEY, SECURE_AUTH_KEY, etc.). This
 * provides encryption at rest while allowing decryption when needed for
 * API calls.
 *
 * Token Storage Structure:
 * ```
 * infyp_google_oauth_tokens => encrypted({
 *     "access_token": "ya29.xxx",
 *     "refresh_token": "1//xxx",
 *     "expires_at": 1700000000
 * })
 * ```
 *
 * Usage Example:
 * ```php
 * $manager = INFYP_Google_OAuth_Manager::get_instance();
 *
 * // Check connection status
 * if ( $manager->is_connected() ) {
 *     $token = $manager->get_access_token();
 *     // Use $token for Google API calls
 * }
 *
 * // Get authorization URL for OAuth flow
 * $auth_url = $manager->get_authorization_url( $redirect_uri );
 *
 * // Exchange code for tokens after Google redirect
 * $result = $manager->exchange_code_for_tokens( $code, $redirect_uri );
 * ```
 *
 * @package    AI_Image_SEO
 * @subpackage Core
 * @since 1.0.0
 *
 * @see INFYP_Encryption_Service For encryption implementation
 * @see INFYP_API_Key_Manager For similar singleton + encryption pattern
 * @see get_option() For retrieving stored tokens
 * @see update_option() For storing encrypted tokens
 */
class INFYP_Google_OAuth_Manager {

    /**
     * Singleton instance
     *
     * @since 1.0.0
     * @var    INFYP_Google_OAuth_Manager|null
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
     * In-memory cache for decrypted tokens
     *
     * Stores decrypted tokens temporarily during request to avoid repeated
     * decryption operations. Cleared on shutdown for security.
     *
     * @since 1.0.0
     * @var    array|null
     * @access private
     */
    private $token_cache = null;

    /**
     * In-memory cache for decrypted custom credentials
     *
     * Stores decrypted custom OAuth credentials temporarily during request
     * to avoid repeated decryption operations. Cleared on shutdown for security.
     *
     * @since 1.0.0
     * @var    array|null
     * @access private
     */
    private $custom_creds_cache = null;

    /**
     * Option key for encrypted OAuth tokens
     *
     * Stores encrypted JSON containing access_token, refresh_token, and expires_at.
     *
     * @since 1.0.0
     * @var   string
     */
    const OPTION_TOKENS = 'infyp_google_oauth_tokens';

    /**
     * Option key for selected Search Console property URL
     *
     * Stores the property URL string (e.g., 'https://example.com/' or 'sc-domain:example.com').
     *
     * @since 1.0.0
     * @var   string
     */
    const OPTION_PROPERTY = 'infyp_gsc_connected_property';

    /**
     * Option key for connection method
     *
     * Stores either 'quick' (built-in credentials) or 'custom' (user-provided credentials).
     *
     * @since 1.0.0
     * @var   string
     */
    const OPTION_METHOD = 'infyp_gsc_connection_method';

    /**
     * Option key for encrypted custom OAuth credentials
     *
     * Stores encrypted JSON containing client_id and client_secret when the user
     * provides their own Google OAuth application credentials.
     *
     * @since 1.0.0
     * @var   string
     */
    const OPTION_CUSTOM_CREDS = 'infyp_gsc_custom_credentials';

    /**
     * Google OAuth 2.0 authorization endpoint
     *
     * @since 1.0.0
     * @var   string
     */
    const GOOGLE_AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    /**
     * Google OAuth 2.0 token endpoint
     *
     * Used for exchanging authorization codes and refreshing tokens.
     *
     * @since 1.0.0
     * @var   string
     */
    const GOOGLE_TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /**
     * Google OAuth 2.0 token revocation endpoint
     *
     * @since 1.0.0
     * @var   string
     */
    const GOOGLE_REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    /**
     * OAuth scope for Google Search Console read-only access
     *
     * @since 1.0.0
     * @var   string
     */
    const SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';

    /**
     * Token refresh buffer in seconds
     *
     * Refresh the access token this many seconds before actual expiry
     * to avoid failed API calls due to race conditions.
     *
     * @since 1.0.0
     * @var   int
     */
    const TOKEN_REFRESH_BUFFER = 300;

    /**
     * OAuth proxy base URL for quick connect flow.
     *
     * When using the quick connect method, OAuth is proxied through
     * infypress.com so the client_secret never ships in plugin code.
     *
     * @since 1.0.0
     * @var   string
     */
    const PROXY_URL = 'https://infypress.com/wp-json/ai-products/v1/gsc';

    /**
     * Cron hook name for Search Console sync
     *
     * Used when unscheduling cron events during disconnect.
     *
     * @since 1.0.0
     * @var   string
     */
    const CRON_HOOK = 'infyp_sync_search_console';

    /**
     * Get singleton instance
     *
     * Returns the single instance of this class, creating it if it doesn't
     * exist yet. This ensures consistent state across the request lifecycle.
     *
     * @since 1.0.0
     * @return INFYP_Google_OAuth_Manager The singleton instance.
     */
    public static function get_instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor
     *
     * Initializes the encryption service and registers the shutdown action
     * to clear in-memory caches for security.
     *
     * @since 1.0.0
     * @access private
     */
    private function __construct() {
        $this->encryption = INFYP_Encryption_Service::get_instance();

        // Clear cache on shutdown for security
        add_action( 'shutdown', [ $this, 'clear_cache' ] );
    }

    /**
     * Check if Google Search Console is connected
     *
     * Verifies that valid OAuth tokens exist. Does not check if the access
     * token is currently valid (it may need refreshing), only that tokens
     * are stored and a refresh_token is present.
     *
     * @since 1.0.0
     * @return bool True if valid tokens exist, false otherwise.
     */
    public function is_connected() {
        $tokens = $this->get_tokens();

        if ( empty( $tokens ) || ! is_array( $tokens ) ) {
            return false;
        }

        // Must have at least a refresh_token to be considered connected
        if ( empty( $tokens['refresh_token'] ) ) {
            return false;
        }

        return true;
    }

    /**
     * Get a valid access token
     *
     * Returns the current access token, automatically refreshing it if it
     * has expired or is about to expire (within TOKEN_REFRESH_BUFFER seconds).
     *
     * @since 1.0.0
     * @return string|false The access token string, or false on failure.
     */
    public function get_access_token() {
        $tokens = $this->get_tokens();

        if ( empty( $tokens ) || ! is_array( $tokens ) ) {
            return false;
        }

        if ( empty( $tokens['access_token'] ) ) {
            return false;
        }

        // Check if token needs refresh
        $expires_at = isset( $tokens['expires_at'] ) ? (int) $tokens['expires_at'] : 0;

        if ( $expires_at > 0 && ( time() + self::TOKEN_REFRESH_BUFFER ) >= $expires_at ) {
            // Token is expired or about to expire, refresh it
            $refreshed = $this->refresh_access_token();

            if ( is_wp_error( $refreshed ) || $refreshed === false ) {
                return false;
            }

            // Re-read tokens after refresh
            $tokens = $this->get_tokens();

            if ( empty( $tokens ) || empty( $tokens['access_token'] ) ) {
                return false;
            }
        }

        return $tokens['access_token'];
    }

    /**
     * Store OAuth tokens
     *
     * Encrypts and stores the OAuth token set in the database. Calculates
     * the absolute expiry time from the relative expires_in value.
     *
     * @since 1.0.0
     *
     * @param string $access_token  The OAuth access token.
     * @param string $refresh_token The OAuth refresh token.
     * @param int    $expires_in    Token lifetime in seconds from now.
     * @return bool True on success, false on failure.
     */
    public function store_tokens( $access_token, $refresh_token, $expires_in ) {
        if ( empty( $access_token ) || empty( $refresh_token ) ) {
            return false;
        }

        $token_data = [
            'access_token'  => $access_token,
            'refresh_token' => $refresh_token,
            'expires_at'    => time() + (int) $expires_in,
        ];

        $json = wp_json_encode( $token_data );

        if ( $json === false ) {
            INFYP_Logger::log( 'Google OAuth: Failed to encode token data as JSON.', 'ERROR' );
            return false;
        }

        $encrypted = $this->encryption->encrypt( $json );

        if ( $encrypted === false ) {
            INFYP_Logger::log( 'Google OAuth: Failed to encrypt token data.', 'ERROR' );
            return false;
        }

        // Update in-memory cache
        $this->token_cache = $token_data;

        return update_option( self::OPTION_TOKENS, $encrypted, false );
    }

    /**
     * Refresh the access token using the refresh token
     *
     * Makes a POST request to Google's token endpoint to exchange the
     * refresh_token for a new access_token. If the refresh fails with
     * a 400 or 401 status, the stored tokens are deleted (marking the
     * connection as disconnected).
     *
     * @since 1.0.0
     * @return true|\WP_Error True on success, WP_Error on failure.
     */
    public function refresh_access_token() {
        $tokens = $this->get_tokens();

        if ( empty( $tokens ) || empty( $tokens['refresh_token'] ) ) {
            return new \WP_Error(
                'no_refresh_token',
                __( 'No refresh token available. Please reconnect to Google Search Console.', 'infy-ai-image-seo-optimizer' )
            );
        }

        $method = $this->get_connection_method();

        // Quick connect: refresh via proxy.
        if ( $method !== 'custom' ) {
            return $this->refresh_via_proxy( $tokens['refresh_token'] );
        }

        // Custom credentials: refresh directly with Google.
        return $this->refresh_directly( $tokens );
    }

    /**
     * Refresh an access token via the InfyPress proxy.
     *
     * Used by the quick connect flow. The proxy holds the Google client_secret
     * and performs the refresh on our behalf.
     *
     * @since 1.0.0
     * @access private
     *
     * @param string $refresh_token The refresh token.
     * @return true|\WP_Error True on success, WP_Error on failure.
     */
    private function refresh_via_proxy( $refresh_token ) {
        $response = wp_remote_post( self::PROXY_URL . '/refresh', [
            'timeout' => 30,
            'body'    => [
                'refresh_token' => $refresh_token,
                'site_url'      => site_url(),
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            INFYP_Logger::log( 'Google OAuth: Proxy token refresh HTTP error - ' . $response->get_error_message(), 'ERROR' );
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body        = wp_remote_retrieve_body( $response );
        $data        = json_decode( $body, true );

        // If refresh fails with 400 or 401, mark as disconnected.
        if ( in_array( $status_code, [ 400, 401 ], true ) ) {
            $error_msg = isset( $data['message'] ) ? $data['message'] : 'Unknown error';
            INFYP_Logger::log( 'Google OAuth: Proxy token refresh failed (' . $status_code . '): ' . $error_msg, 'ERROR' );

            delete_option( self::OPTION_TOKENS );
            $this->token_cache = null;

            return new \WP_Error(
                'refresh_failed',
                __( 'Token refresh failed. Please reconnect to Google Search Console.', 'infy-ai-image-seo-optimizer' )
            );
        }

        if ( $status_code !== 200 || empty( $data['access_token'] ) ) {
            INFYP_Logger::log( 'Google OAuth: Unexpected proxy refresh response (' . $status_code . '): ' . $body, 'ERROR' );
            return new \WP_Error(
                'refresh_unexpected',
                __( 'Unexpected response from proxy refresh endpoint.', 'infy-ai-image-seo-optimizer' )
            );
        }

        $expires_in = isset( $data['expires_in'] ) ? (int) $data['expires_in'] : 3600;

        $stored = $this->store_tokens( $data['access_token'], $refresh_token, $expires_in );

        if ( ! $stored ) {
            return new \WP_Error(
                'store_failed',
                __( 'Failed to store refreshed tokens.', 'infy-ai-image-seo-optimizer' )
            );
        }

        return true;
    }

    /**
     * Refresh an access token directly with Google.
     *
     * Used by the custom credentials flow where the user provides their own
     * client_id and client_secret.
     *
     * @since 1.0.0
     * @access private
     *
     * @param array $tokens The current token data including refresh_token.
     * @return true|\WP_Error True on success, WP_Error on failure.
     */
    private function refresh_directly( $tokens ) {
        $client_id     = $this->get_client_id();
        $client_secret = $this->get_client_secret();

        if ( empty( $client_id ) || empty( $client_secret ) ) {
            return new \WP_Error(
                'missing_credentials',
                __( 'OAuth client credentials are not configured.', 'infy-ai-image-seo-optimizer' )
            );
        }

        $response = wp_remote_post( self::GOOGLE_TOKEN_URL, [
            'timeout' => 30,
            'body'    => [
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'refresh_token' => $tokens['refresh_token'],
                'grant_type'    => 'refresh_token',
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            INFYP_Logger::log( 'Google OAuth: Token refresh HTTP error - ' . $response->get_error_message(), 'ERROR' );
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body        = wp_remote_retrieve_body( $response );
        $data        = json_decode( $body, true );

        // If refresh fails with 400 or 401, mark as disconnected
        if ( in_array( $status_code, [ 400, 401 ], true ) ) {
            $error_desc = isset( $data['error_description'] ) ? $data['error_description'] : 'Unknown error';
            INFYP_Logger::log( 'Google OAuth: Token refresh failed (' . $status_code . '): ' . $error_desc, 'ERROR' );

            // Delete stored tokens — user must reconnect
            delete_option( self::OPTION_TOKENS );
            $this->token_cache = null;

            return new \WP_Error(
                'refresh_failed',
                __( 'Token refresh failed. Please reconnect to Google Search Console.', 'infy-ai-image-seo-optimizer' )
            );
        }

        if ( $status_code !== 200 || empty( $data['access_token'] ) ) {
            INFYP_Logger::log( 'Google OAuth: Unexpected token refresh response (' . $status_code . '): ' . $body, 'ERROR' );
            return new \WP_Error(
                'refresh_unexpected',
                __( 'Unexpected response from Google token endpoint.', 'infy-ai-image-seo-optimizer' )
            );
        }

        // Google may or may not return a new refresh_token
        $new_refresh_token = ! empty( $data['refresh_token'] ) ? $data['refresh_token'] : $tokens['refresh_token'];
        $expires_in        = isset( $data['expires_in'] ) ? (int) $data['expires_in'] : 3600;

        $stored = $this->store_tokens( $data['access_token'], $new_refresh_token, $expires_in );

        if ( ! $stored ) {
            return new \WP_Error(
                'store_failed',
                __( 'Failed to store refreshed tokens.', 'infy-ai-image-seo-optimizer' )
            );
        }

        return true;
    }

    /**
     * Revoke the OAuth token via Google's revocation endpoint
     *
     * Sends a POST request to Google to revoke the token, then deletes
     * all locally stored OAuth options.
     *
     * @since 1.0.0
     * @return true|\WP_Error True on success, WP_Error on failure.
     */
    public function revoke_token() {
        $tokens = $this->get_tokens();

        // Use refresh_token for revocation if available, otherwise access_token
        $token_to_revoke = '';
        if ( ! empty( $tokens['refresh_token'] ) ) {
            $token_to_revoke = $tokens['refresh_token'];
        } elseif ( ! empty( $tokens['access_token'] ) ) {
            $token_to_revoke = $tokens['access_token'];
        }

        if ( ! empty( $token_to_revoke ) ) {
            $response = wp_remote_post( self::GOOGLE_REVOKE_URL, [
                'timeout' => 30,
                'body'    => [
                    'token' => $token_to_revoke,
                ],
            ] );

            if ( is_wp_error( $response ) ) {
                INFYP_Logger::log( 'Google OAuth: Token revocation HTTP error - ' . $response->get_error_message(), 'WARNING' );
                // Continue with local cleanup even if revocation fails
            }
        }

        // Delete all local OAuth options
        delete_option( self::OPTION_TOKENS );
        delete_option( self::OPTION_PROPERTY );
        delete_option( self::OPTION_METHOD );
        delete_option( self::OPTION_CUSTOM_CREDS );

        // Clear in-memory caches
        $this->token_cache       = null;
        $this->custom_creds_cache = null;

        return true;
    }

    /**
     * Get connection information
     *
     * Returns an array summarizing the current Google Search Console
     * connection status, including property URL and connection method.
     *
     * @since 1.0.0
     * @return array {
     *     Connection information array.
     *
     *     @type bool   $connected Whether a valid connection exists.
     *     @type string $property  The connected Search Console property URL, or empty string.
     *     @type string $method    The connection method ('quick', 'custom', or empty string).
     * }
     */
    public function get_connection_info() {
        return [
            'connected' => $this->is_connected(),
            'property'  => $this->get_property(),
            'method'    => $this->get_connection_method(),
        ];
    }

    /**
     * Get the OAuth client ID
     *
     * Returns the client_id to use for OAuth flows. If custom credentials
     * are stored, those are used. Otherwise falls back to the built-in
     * constants (INFYP_GSC_CLIENT_ID) which can be defined in wp-config.php
     * or the main plugin file.
     *
     * @since 1.0.0
     * @return string The OAuth client ID, or empty string if not configured.
     */
    public function get_client_id() {
        // Check for custom credentials first
        $custom_creds = $this->get_custom_credentials();

        if ( ! empty( $custom_creds['client_id'] ) ) {
            return $custom_creds['client_id'];
        }

        // Fall back to built-in constant
        if ( defined( 'INFYP_GSC_CLIENT_ID' ) ) {
            return INFYP_GSC_CLIENT_ID;
        }

        return '';
    }

    /**
     * Get the OAuth client secret
     *
     * Returns the client_secret to use for OAuth flows. If custom credentials
     * are stored, those are used. Otherwise falls back to the built-in
     * constants (INFYP_GSC_CLIENT_SECRET) which can be defined in wp-config.php
     * or the main plugin file.
     *
     * @since 1.0.0
     * @return string The OAuth client secret, or empty string if not configured.
     */
    public function get_client_secret() {
        // Check for custom credentials first
        $custom_creds = $this->get_custom_credentials();

        if ( ! empty( $custom_creds['client_secret'] ) ) {
            return $custom_creds['client_secret'];
        }

        // Fall back to built-in constant
        if ( defined( 'INFYP_GSC_CLIENT_SECRET' ) ) {
            return INFYP_GSC_CLIENT_SECRET;
        }

        return '';
    }

    /**
     * Build the Google OAuth authorization URL
     *
     * Constructs the full authorization URL with required parameters
     * including scope, redirect_uri, access_type=offline (to receive
     * a refresh_token), and prompt=consent (to always show consent screen).
     *
     * @since 1.0.0
     *
     * @param string $redirect_uri The URI Google should redirect to after authorization.
     * @return string|\WP_Error The full authorization URL, or WP_Error if client_id is missing.
     */
    public function get_authorization_url( $redirect_uri ) {
        $method = get_option( self::OPTION_METHOD, 'quick' );

        // Quick connect: redirect to the proxy instead of directly to Google.
        if ( $method !== 'custom' ) {
            $state = wp_generate_password( 32, false, false );
            set_transient( 'infyp_gsc_oauth_state_' . $state, '1', 600 );

            $params = [
                'redirect_uri' => $redirect_uri,
                'state'        => $state,
                'site_url'     => site_url(),
            ];

            return self::PROXY_URL . '/authorize?' . http_build_query( $params, '', '&' );
        }

        // Custom credentials: redirect directly to Google.
        $client_id = $this->get_client_id();

        if ( empty( $client_id ) ) {
            return new \WP_Error(
                'missing_client_id',
                __( 'OAuth client ID is not configured. Please set up your Google API credentials.', 'infy-ai-image-seo-optimizer' )
            );
        }

        $state = wp_generate_password( 32, false, false );
        set_transient( 'infyp_gsc_oauth_state_' . $state, '1', 600 );

        $params = [
            'client_id'     => $client_id,
            'redirect_uri'  => $redirect_uri,
            'response_type' => 'code',
            'scope'         => self::SCOPE,
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'state'         => $state,
        ];

        return self::GOOGLE_AUTH_URL . '?' . http_build_query( $params, '', '&' );
    }

    /**
     * Exchange an authorization code for OAuth tokens
     *
     * Makes a POST request to Google's token endpoint to exchange the
     * authorization code received from the OAuth redirect for an
     * access_token and refresh_token pair.
     *
     * @since 1.0.0
     *
     * @param string $code         The authorization code from Google.
     * @param string $redirect_uri The same redirect_uri used in the authorization request.
     * @return array|\WP_Error {
     *     Token array on success, WP_Error on failure.
     *
     *     @type string $access_token  The OAuth access token.
     *     @type string $refresh_token The OAuth refresh token.
     *     @type int    $expires_in    Token lifetime in seconds.
     * }
     */
    public function exchange_code_for_tokens( $code, $redirect_uri ) {
        $method = $this->get_connection_method();

        // Quick connect: $code is actually proxy_code — exchange via proxy.
        if ( $method !== 'custom' ) {
            return $this->exchange_proxy_code_for_tokens( $code );
        }

        // Custom credentials: exchange directly with Google.
        return $this->exchange_code_directly( $code, $redirect_uri );
    }

    /**
     * Exchange a proxy_code for tokens via the InfyPress proxy.
     *
     * Used by the quick connect flow. The proxy holds the Google client_secret
     * and returns the tokens in exchange for a one-time proxy_code.
     *
     * @since 1.0.0
     * @access private
     *
     * @param string $proxy_code The one-time proxy code from the proxy callback.
     * @return array|\WP_Error Token array on success, WP_Error on failure.
     */
    private function exchange_proxy_code_for_tokens( $proxy_code ) {
        if ( empty( $proxy_code ) ) {
            return new \WP_Error(
                'missing_code',
                __( 'Proxy code is required.', 'infy-ai-image-seo-optimizer' )
            );
        }

        $response = wp_remote_post( self::PROXY_URL . '/tokens', [
            'timeout' => 30,
            'body'    => [
                'proxy_code' => $proxy_code,
                'site_url'   => site_url(),
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            INFYP_Logger::log( 'Google OAuth: Proxy token exchange HTTP error - ' . $response->get_error_message(), 'ERROR' );
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body        = wp_remote_retrieve_body( $response );
        $data        = json_decode( $body, true );

        if ( $status_code !== 200 || empty( $data['access_token'] ) ) {
            $error_msg = isset( $data['message'] ) ? $data['message'] : __( 'Unknown error', 'infy-ai-image-seo-optimizer' );

            INFYP_Logger::log( 'Google OAuth: Proxy token exchange failed (' . $status_code . '): ' . $error_msg, 'ERROR' );

            return new \WP_Error(
                'proxy_exchange_failed',
                sprintf(
                    /* translators: %s: Error description from proxy */
                    __( 'Failed to retrieve tokens from proxy: %s', 'infy-ai-image-seo-optimizer' ),
                    $error_msg
                )
            );
        }

        if ( empty( $data['refresh_token'] ) ) {
            return new \WP_Error(
                'no_refresh_token',
                __( 'No refresh token received. Please revoke app access in your Google Account settings and try again.', 'infy-ai-image-seo-optimizer' )
            );
        }

        $expires_in = isset( $data['expires_in'] ) ? (int) $data['expires_in'] : 3600;

        $stored = $this->store_tokens( $data['access_token'], $data['refresh_token'], $expires_in );

        if ( ! $stored ) {
            return new \WP_Error(
                'store_failed',
                __( 'Failed to store OAuth tokens.', 'infy-ai-image-seo-optimizer' )
            );
        }

        return [
            'access_token'  => $data['access_token'],
            'refresh_token' => $data['refresh_token'],
            'expires_in'    => $expires_in,
        ];
    }

    /**
     * Exchange an authorization code directly with Google.
     *
     * Used by the custom credentials flow where the user provides their own
     * client_id and client_secret.
     *
     * @since 1.0.0
     * @access private
     *
     * @param string $code         The authorization code from Google.
     * @param string $redirect_uri The same redirect_uri used in the authorization request.
     * @return array|\WP_Error Token array on success, WP_Error on failure.
     */
    private function exchange_code_directly( $code, $redirect_uri ) {
        $client_id     = $this->get_client_id();
        $client_secret = $this->get_client_secret();

        if ( empty( $client_id ) || empty( $client_secret ) ) {
            return new \WP_Error(
                'missing_credentials',
                __( 'OAuth client credentials are not configured.', 'infy-ai-image-seo-optimizer' )
            );
        }

        if ( empty( $code ) ) {
            return new \WP_Error(
                'missing_code',
                __( 'Authorization code is required.', 'infy-ai-image-seo-optimizer' )
            );
        }

        $response = wp_remote_post( self::GOOGLE_TOKEN_URL, [
            'timeout' => 30,
            'body'    => [
                'code'          => $code,
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'redirect_uri'  => $redirect_uri,
                'grant_type'    => 'authorization_code',
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            INFYP_Logger::log( 'Google OAuth: Code exchange HTTP error - ' . $response->get_error_message(), 'ERROR' );
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body        = wp_remote_retrieve_body( $response );
        $data        = json_decode( $body, true );

        if ( $status_code !== 200 || empty( $data['access_token'] ) ) {
            $error_desc = isset( $data['error_description'] ) ? $data['error_description'] : __( 'Unknown error', 'infy-ai-image-seo-optimizer' );

            INFYP_Logger::log( 'Google OAuth: Code exchange failed (' . $status_code . '): ' . $error_desc, 'ERROR' );

            return new \WP_Error(
                'exchange_failed',
                sprintf(
                    /* translators: %s: Error description from Google */
                    __( 'Failed to exchange authorization code: %s', 'infy-ai-image-seo-optimizer' ),
                    $error_desc
                )
            );
        }

        if ( empty( $data['refresh_token'] ) ) {
            INFYP_Logger::log( 'Google OAuth: No refresh_token in code exchange response. User may need to revoke app access and retry.', 'WARNING' );

            return new \WP_Error(
                'no_refresh_token',
                __( 'Google did not provide a refresh token. Please revoke app access in your Google Account settings and try again.', 'infy-ai-image-seo-optimizer' )
            );
        }

        $expires_in = isset( $data['expires_in'] ) ? (int) $data['expires_in'] : 3600;

        // Store the tokens
        $stored = $this->store_tokens( $data['access_token'], $data['refresh_token'], $expires_in );

        if ( ! $stored ) {
            return new \WP_Error(
                'store_failed',
                __( 'Failed to store OAuth tokens.', 'infy-ai-image-seo-optimizer' )
            );
        }

        return [
            'access_token'  => $data['access_token'],
            'refresh_token' => $data['refresh_token'],
            'expires_in'    => $expires_in,
        ];
    }

    /**
     * Get the connection method
     *
     * Returns the method used to connect to Google Search Console.
     * Either 'quick' (built-in credentials) or 'custom' (user-provided).
     *
     * @since 1.0.0
     * @return string The connection method ('quick' or 'custom'), or empty string if not set.
     */
    public function get_connection_method() {
        $method = get_option( self::OPTION_METHOD, '' );

        if ( ! in_array( $method, [ 'quick', 'custom' ], true ) ) {
            return '';
        }

        return $method;
    }

    /**
     * Set the selected Search Console property
     *
     * Stores the selected Search Console property URL that will be used
     * for data queries.
     *
     * @since 1.0.0
     *
     * @param string $property_url The Search Console property URL
     *                             (e.g., 'https://example.com/' or 'sc-domain:example.com').
     * @return bool True on success, false on failure.
     */
    public function set_property( $property_url ) {
        if ( empty( $property_url ) ) {
            return false;
        }

        return update_option( self::OPTION_PROPERTY, sanitize_text_field( $property_url ), false );
    }

    /**
     * Get the selected Search Console property URL
     *
     * @since 1.0.0
     * @return string The property URL, or empty string if not set.
     */
    public function get_property() {
        return get_option( self::OPTION_PROPERTY, '' );
    }

    /**
     * Disconnect from Google Search Console
     *
     * Performs a full disconnect: revokes the OAuth token via Google API,
     * deletes all locally stored GSC options, and unschedules any
     * related cron events.
     *
     * @since 1.0.0
     * @return true|\WP_Error True on success. Note: local cleanup always
     *                        proceeds even if remote revocation fails.
     */
    public function disconnect() {
        // Revoke the token (handles its own cleanup of options)
        $this->revoke_token();

        // Unschedule the sync cron
        $timestamp = wp_next_scheduled( self::CRON_HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_HOOK );
        }
        wp_clear_scheduled_hook( self::CRON_HOOK );

        // Delete any additional GSC-related options not covered by revoke_token
        delete_option( 'infyp_gsc_last_sync' );
        delete_option( 'infyp_gsc_optimization_impact' );

        return true;
    }

    /**
     * Clear in-memory caches
     *
     * Called on shutdown to ensure sensitive token data does not persist
     * in memory longer than necessary.
     *
     * @since 1.0.0
     * @return void
     */
    public function clear_cache() {
        $this->token_cache        = null;
        $this->custom_creds_cache = null;
    }

    /**
     * Get decrypted tokens from storage
     *
     * Retrieves and decrypts the stored OAuth tokens. Uses in-memory
     * cache to avoid repeated decryption within the same request.
     *
     * @since 1.0.0
     * @access private
     *
     * @return array|null {
     *     Token array on success, null on failure.
     *
     *     @type string $access_token  The OAuth access token.
     *     @type string $refresh_token The OAuth refresh token.
     *     @type int    $expires_at    Unix timestamp when access_token expires.
     * }
     */
    private function get_tokens() {
        // Check in-memory cache first
        if ( $this->token_cache !== null ) {
            return $this->token_cache;
        }

        $encrypted = get_option( self::OPTION_TOKENS, '' );

        if ( empty( $encrypted ) ) {
            return null;
        }

        $decrypted = $this->encryption->decrypt( $encrypted );

        if ( $decrypted === false || empty( $decrypted ) ) {
            INFYP_Logger::log( 'Google OAuth: Failed to decrypt stored tokens.', 'ERROR' );
            return null;
        }

        $tokens = json_decode( $decrypted, true );

        if ( ! is_array( $tokens ) ) {
            INFYP_Logger::log( 'Google OAuth: Stored token data is not valid JSON.', 'ERROR' );
            return null;
        }

        // Cache in memory for this request
        $this->token_cache = $tokens;

        return $tokens;
    }

    /**
     * Get decrypted custom OAuth credentials
     *
     * Retrieves and decrypts the stored custom OAuth client credentials.
     * Uses in-memory cache to avoid repeated decryption within the same request.
     *
     * @since 1.0.0
     * @access private
     *
     * @return array|null {
     *     Credentials array on success, null if not set or on failure.
     *
     *     @type string $client_id     The OAuth client ID.
     *     @type string $client_secret The OAuth client secret.
     * }
     */
    private function get_custom_credentials() {
        // Check in-memory cache first
        if ( $this->custom_creds_cache !== null ) {
            return $this->custom_creds_cache;
        }

        $encrypted = get_option( self::OPTION_CUSTOM_CREDS, '' );

        if ( empty( $encrypted ) ) {
            return null;
        }

        $decrypted = $this->encryption->decrypt( $encrypted );

        if ( $decrypted === false || empty( $decrypted ) ) {
            INFYP_Logger::log( 'Google OAuth: Failed to decrypt custom credentials.', 'ERROR' );
            return null;
        }

        $creds = json_decode( $decrypted, true );

        if ( ! is_array( $creds ) ) {
            INFYP_Logger::log( 'Google OAuth: Custom credentials data is not valid JSON.', 'ERROR' );
            return null;
        }

        // Cache in memory for this request
        $this->custom_creds_cache = $creds;

        return $creds;
    }
}
