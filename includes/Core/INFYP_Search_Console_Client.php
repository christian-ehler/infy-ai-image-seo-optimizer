<?php
/**
 * Google Search Console API client.
 *
 * Provides authenticated HTTP wrappers around the Search Console v3 and
 * URL Inspection v1 APIs, including automatic token refresh on 401,
 * rate-limit awareness, and convenience methods for image/web search
 * analytics and sitemap inspection.
 *
 * @package    IPAIS
 * @subpackage Core
 * @since 1.0.0
 * @author     InfyPress
 * @license    GPL-2.0-or-later
 */

namespace IPAIS\Core;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Google Search Console API Client
 *
 * Wraps the Google Search Console API endpoints (Search Analytics, Sitemaps,
 * URL Inspection, and Sites) for use within the plugin. Depends on
 * INFYP_Google_OAuth_Manager for OAuth 2.0 authentication and token management.
 *
 * All API calls go directly from the user's WordPress installation to Google.
 * No data is transmitted to InfyPress servers.
 *
 * API Endpoints Used:
 * - Search Analytics: Query image/web search performance data
 * - Sitemaps: List and inspect sitemaps including image sitemap status
 * - URL Inspection: Check indexing status of individual pages
 * - Sites: List available Search Console properties
 *
 * Error Handling:
 * - Returns WP_Error on all failure paths with descriptive error codes
 * - Automatically retries once on 401 (expired token) after refreshing
 * - Includes Retry-After data on 429 (rate limit) responses
 * - Surfaces Google's error messages when available
 *
 * @package IPAIS\Core
 * @since 1.0.0
 */
class INFYP_Search_Console_Client {

	/**
	 * Google Search Console API v3 base URL
	 *
	 * Used for Sites, Search Analytics, and Sitemaps endpoints.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const API_BASE = 'https://www.googleapis.com/webmasters/v3';

	/**
	 * Google Search Console URL Inspection API base URL
	 *
	 * Used for the URL Inspection endpoint which lives on a different host.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const INSPECTION_BASE = 'https://searchconsole.googleapis.com/v1';

	/**
	 * HTTP request timeout in seconds
	 *
	 * @since 1.0.0
	 * @var   int
	 */
	const REQUEST_TIMEOUT = 30;

	/**
	 * User-Agent header sent with all API requests
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const USER_AGENT = 'InfyPress-AI-Image-SEO/1.0';

	/**
	 * OAuth Manager instance for authentication
	 *
	 * @since 1.0.0
	 * @var    INFYP_Google_OAuth_Manager
	 * @access private
	 */
	private $oauth_manager;

	/**
	 * Constructor
	 *
	 * Accepts an INFYP_Google_OAuth_Manager instance via dependency injection.
	 * Falls back to the singleton instance if none is provided.
	 *
	 * @since 1.0.0
	 * @param INFYP_Google_OAuth_Manager|null $oauth_manager OAuth manager instance.
	 */
	public function __construct( ?INFYP_Google_OAuth_Manager $oauth_manager = null ) {
		$this->oauth_manager = $oauth_manager ?: INFYP_Google_OAuth_Manager::get_instance();
	}

	/* =========================================================================
	   CORE HTTP
	   ========================================================================= */

	/**
	 * Make an authenticated request to a Google API endpoint
	 *
	 * Retrieves the current access token from the OAuth Manager, builds the
	 * request with appropriate headers, and handles the response. On a 401
	 * response the token is refreshed and the request is retried once.
	 *
	 * @since 1.0.0
	 *
	 * @param string     $method HTTP method (GET, POST, PUT, DELETE).
	 * @param string     $url    Fully-qualified API URL.
	 * @param array|null $body   Optional request body (will be JSON-encoded for non-GET).
	 * @return array|\WP_Error Decoded JSON response array on success, WP_Error on failure.
	 */
	public function api_request( $method, $url, $body = null ) {
		return $this->do_api_request( $method, $url, $body, true );
	}

	/**
	 * Internal request handler with optional retry on 401
	 *
	 * @since 1.0.0
	 * @access private
	 *
	 * @param string     $method      HTTP method.
	 * @param string     $url         Fully-qualified API URL.
	 * @param array|null $body        Optional request body.
	 * @param bool       $allow_retry Whether to attempt a token refresh on 401.
	 * @return array|\WP_Error Decoded JSON response array on success, WP_Error on failure.
	 */
	private function do_api_request( $method, $url, $body = null, $allow_retry = true ) {
		// Ensure we are connected before making any request.
		if ( ! $this->oauth_manager->is_connected() ) {
			return new \WP_Error(
				'gsc_not_connected',
				__( 'Google Search Console is not connected. Please connect your Google account first.', 'infy-ai-image-seo-optimizer' )
			);
		}

		// Get the current access token (auto-refreshes if nearly expired).
		$access_token = $this->oauth_manager->get_access_token();

		if ( is_wp_error( $access_token ) ) {
			return new \WP_Error(
				'gsc_auth_failed',
				sprintf(
					/* translators: %s: error message from OAuth manager */
					__( 'Failed to obtain access token: %s', 'infy-ai-image-seo-optimizer' ),
					$access_token->get_error_message()
				)
			);
		}

		// Build request arguments.
		$args = [
			'method'  => strtoupper( $method ),
			'timeout' => self::REQUEST_TIMEOUT,
			'headers' => [
				'Authorization' => 'Bearer ' . $access_token,
				'Content-Type'  => 'application/json',
				'User-Agent'    => self::USER_AGENT,
			],
		];

		if ( $body !== null && strtoupper( $method ) !== 'GET' ) {
			$args['body'] = wp_json_encode( $body );
		}

		// Execute the HTTP request.
		$response = wp_remote_request( $url, $args );

		// Check for transport-level errors (DNS, timeout, connection refused, etc.).
		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'gsc_http_error',
				sprintf(
					/* translators: %s: HTTP error message */
					__( 'HTTP request to Google Search Console failed: %s', 'infy-ai-image-seo-optimizer' ),
					$response->get_error_message()
				)
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body_raw    = wp_remote_retrieve_body( $response );
		$data        = json_decode( $body_raw, true );

		// ------------------------------------------------------------------
		// Handle specific HTTP error codes.
		// ------------------------------------------------------------------

		// 401 Unauthorized — token may have expired mid-request.
		if ( $status_code === 401 ) {
			if ( $allow_retry ) {
				// Attempt one token refresh and retry the request.
				$refresh_result = $this->oauth_manager->refresh_access_token();

				if ( is_wp_error( $refresh_result ) ) {
					return new \WP_Error(
						'gsc_auth_failed',
						sprintf(
							/* translators: %s: error message from token refresh */
							__( 'Google authentication failed after token refresh: %s', 'infy-ai-image-seo-optimizer' ),
							$refresh_result->get_error_message()
						)
					);
				}

				// Retry once without further retry attempts.
				return $this->do_api_request( $method, $url, $body !== null ? $body : null, false );
			}

			$error_message = isset( $data['error']['message'] )
				? $data['error']['message']
				: __( 'Authentication failed. Please reconnect your Google account.', 'infy-ai-image-seo-optimizer' );

			return new \WP_Error( 'gsc_auth_failed', $error_message );
		}

		// 403 Forbidden — insufficient permissions.
		if ( $status_code === 403 ) {
			$error_message = isset( $data['error']['message'] )
				? $data['error']['message']
				: __( 'Permission denied. Your Google account may not have access to this Search Console property.', 'infy-ai-image-seo-optimizer' );

			return new \WP_Error( 'gsc_api_error', $error_message );
		}

		// 429 Too Many Requests — rate limited.
		if ( $status_code === 429 ) {
			$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );
			$retry_after = $retry_after ? (int) $retry_after : 60;

			$error_message = isset( $data['error']['message'] )
				? $data['error']['message']
				: __( 'Google API rate limit exceeded. Please try again later.', 'infy-ai-image-seo-optimizer' );

			return new \WP_Error(
				'gsc_rate_limited',
				$error_message,
				[ 'retry_after' => $retry_after ]
			);
		}

		// Any other non-2xx status code.
		if ( $status_code < 200 || $status_code >= 300 ) {
			$error_message = isset( $data['error']['message'] )
				? $data['error']['message']
				: sprintf(
					/* translators: %d: HTTP status code */
					__( 'Google Search Console API returned HTTP %d.', 'infy-ai-image-seo-optimizer' ),
					$status_code
				);

			return new \WP_Error( 'gsc_api_error', $error_message );
		}

		// Success — return the decoded response (or empty array for 204 No Content).
		return is_array( $data ) ? $data : [];
	}

	/* =========================================================================
	   SITES
	   ========================================================================= */

	/**
	 * List all Search Console sites available to the authenticated user
	 *
	 * Returns an array of site entries, each containing at minimum siteUrl
	 * and permissionLevel.
	 *
	 * @since 1.0.0
	 *
	 * @return array|\WP_Error Array of site entries on success, WP_Error on failure.
	 */
	public function list_sites() {
		$url      = self::API_BASE . '/sites';
		$response = $this->api_request( 'GET', $url );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return isset( $response['siteEntry'] ) ? $response['siteEntry'] : [];
	}

	/* =========================================================================
	   SEARCH ANALYTICS
	   ========================================================================= */

	/**
	 * Query the Search Analytics API
	 *
	 * Sends a POST request to the searchAnalytics/query endpoint with the
	 * provided parameters. The $params array should contain fields such as
	 * startDate, endDate, type, dimensions, dimensionFilterGroups, rowLimit,
	 * startRow, dataState, and aggregationType.
	 *
	 * @since 1.0.0
	 *
	 * @param string $site_url The Search Console property URL (e.g. https://example.com).
	 * @param array  $params   Request body parameters for the query.
	 * @return array|\WP_Error Response array with rows on success, WP_Error on failure.
	 */
	public function query_search_analytics( $site_url, $params ) {
		$encoded  = $this->encode_site_url( $site_url );
		$url      = self::API_BASE . '/sites/' . $encoded . '/searchAnalytics/query';
		$response = $this->api_request( 'POST', $url, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $response;
	}

	/**
	 * Get image search performance data
	 *
	 * Convenience method that calls query_search_analytics with type=image
	 * and the specified dimensions and row limit.
	 *
	 * @since 1.0.0
	 *
	 * @param string $site_url   The Search Console property URL.
	 * @param string $start_date Start date in Y-m-d format.
	 * @param string $end_date   End date in Y-m-d format.
	 * @param array  $dimensions Dimensions to group by. Default: ['date', 'page', 'query'].
	 * @param int    $row_limit  Maximum number of rows to return. Default: 25000.
	 * @return array|\WP_Error Response array with rows on success, WP_Error on failure.
	 */
	public function get_image_search_data( $site_url, $start_date, $end_date, $dimensions = [ 'date', 'page', 'query' ], $row_limit = 25000 ) {
		$params = [
			'startDate'  => $this->format_date( $start_date ),
			'endDate'    => $this->format_date( $end_date ),
			'type'       => 'image',
			'dimensions' => $dimensions,
			'rowLimit'   => $row_limit,
		];

		return $this->query_search_analytics( $site_url, $params );
	}

	/**
	 * Get web search performance data
	 *
	 * Convenience method that calls query_search_analytics with type=web
	 * and the specified dimensions and row limit. Useful for comparing
	 * web search metrics alongside image search metrics.
	 *
	 * @since 1.0.0
	 *
	 * @param string $site_url   The Search Console property URL.
	 * @param string $start_date Start date in Y-m-d format.
	 * @param string $end_date   End date in Y-m-d format.
	 * @param array  $dimensions Dimensions to group by. Default: ['date', 'page'].
	 * @param int    $row_limit  Maximum number of rows to return. Default: 25000.
	 * @return array|\WP_Error Response array with rows on success, WP_Error on failure.
	 */
	public function get_web_search_data( $site_url, $start_date, $end_date, $dimensions = [ 'date', 'page' ], $row_limit = 25000 ) {
		$params = [
			'startDate'  => $this->format_date( $start_date ),
			'endDate'    => $this->format_date( $end_date ),
			'type'       => 'web',
			'dimensions' => $dimensions,
			'rowLimit'   => $row_limit,
		];

		return $this->query_search_analytics( $site_url, $params );
	}

	/* =========================================================================
	   SITEMAPS
	   ========================================================================= */

	/**
	 * List all sitemaps submitted for a Search Console property
	 *
	 * @since 1.0.0
	 *
	 * @param string $site_url The Search Console property URL.
	 * @return array|\WP_Error Array of sitemap entries on success, WP_Error on failure.
	 */
	public function list_sitemaps( $site_url ) {
		$encoded  = $this->encode_site_url( $site_url );
		$url      = self::API_BASE . '/sites/' . $encoded . '/sitemaps';
		$response = $this->api_request( 'GET', $url );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return isset( $response['sitemap'] ) ? $response['sitemap'] : [];
	}

	/**
	 * Get image sitemap information for a property
	 *
	 * Lists all sitemaps, filters for those that include image content, and
	 * returns a summary array. Returns null if no image sitemap is found.
	 *
	 * @since 1.0.0
	 *
	 * @param string $site_url The Search Console property URL.
	 * @return array|null|\WP_Error Summary array on success, null if no image sitemap, WP_Error on failure.
	 */
	public function get_image_sitemap_info( $site_url ) {
		$sitemaps = $this->list_sitemaps( $site_url );

		if ( is_wp_error( $sitemaps ) ) {
			return $sitemaps;
		}

		// Look for sitemaps containing image content.
		foreach ( $sitemaps as $sitemap ) {
			if ( ! isset( $sitemap['contents'] ) || ! is_array( $sitemap['contents'] ) ) {
				continue;
			}

			foreach ( $sitemap['contents'] as $content ) {
				if ( isset( $content['type'] ) && strtolower( $content['type'] ) === 'image' ) {
					return [
						'sitemap_url'      => isset( $sitemap['path'] ) ? $sitemap['path'] : '',
						'images_submitted' => isset( $content['submitted'] ) ? (int) $content['submitted'] : 0,
						'last_downloaded'  => isset( $sitemap['lastDownloaded'] ) ? $sitemap['lastDownloaded'] : null,
						'errors'           => isset( $sitemap['errors'] ) ? (int) $sitemap['errors'] : 0,
						'warnings'         => isset( $sitemap['warnings'] ) ? (int) $sitemap['warnings'] : 0,
					];
				}
			}
		}

		// No image sitemap found.
		return null;
	}

	/* =========================================================================
	   HELPER METHODS
	   ========================================================================= */

	/**
	 * URL-encode a site URL for use in API path segments
	 *
	 * Google's Search Console API requires site URLs in path segments to be
	 * URL-encoded (e.g. https%3A%2F%2Fexample.com).
	 *
	 * @since 1.0.0
	 * @access private
	 *
	 * @param string $site_url The site URL to encode.
	 * @return string URL-encoded site URL safe for path segments.
	 */
	private function encode_site_url( $site_url ) {
		return rawurlencode( $site_url );
	}

	/**
	 * Ensure a date string is in Y-m-d format
	 *
	 * Accepts various date formats and normalises them to the Y-m-d format
	 * required by the Google Search Console API.
	 *
	 * @since 1.0.0
	 * @access private
	 *
	 * @param string $date Date string in any format recognised by strtotime().
	 * @return string Date in Y-m-d format.
	 */
	private function format_date( $date ) {
		// Already in Y-m-d format.
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return $date;
		}

		$timestamp = strtotime( $date );

		if ( $timestamp === false ) {
			// Fallback: return as-is and let the API validate.
			return $date;
		}

		return gmdate( 'Y-m-d', $timestamp );
	}
}
