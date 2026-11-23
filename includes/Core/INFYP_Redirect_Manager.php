<?php
/**
 * Redirect Manager — Captures and serves 301 redirects for renamed images.
 *
 * Two responsibilities:
 * 1. **Capture:** Hooks into the rename pipeline (infyp_filename_updated) to store
 *    old→new URL mappings in the infyp_redirects table.
 * 2. **Serve:** Hooks into template_redirect (priority 1) to intercept requests
 *    for old image URLs and send a 301 Moved Permanently response.
 *
 * Performance safeguards:
 * - Only intercepts requests whose path matches the uploads directory (zero overhead
 *   for normal page loads).
 * - Defers hit-count DB write to avoid slowing the 301 response.
 *
 * @package   IPAIS\Core
 * @since     1.1.0
 * @author    InfyPress
 * @license   GPL-2.0-or-later
 */

namespace IPAIS\Core;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use IPAIS\Database\INFYP_Redirect_Table;

/**
 * Redirect Manager Singleton
 *
 * @since 1.1.0
 */
class INFYP_Redirect_Manager {

	/**
	 * Singleton instance.
	 *
	 * @var INFYP_Redirect_Manager|null
	 */
	private static $instance = null;

	/**
	 * Source label set by the caller of the current rename.
	 *
	 * @since 1.0.9
	 * @var string|null 'ai', 'manual', or null (manual).
	 */
	private static $rename_source = null;

	/**
	 * Redirect IDs that need their hit_count incremented.
	 *
	 * Deferred to the shutdown hook for performance.
	 *
	 * @var array
	 */
	private $pending_hits = [];

	/**
	 * Get singleton instance.
	 *
	 * @since 1.1.0
	 *
	 * @return INFYP_Redirect_Manager
	 */
	public static function get_instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor — registers hooks.
	 *
	 * @since 1.1.0
	 */
	private function __construct() {
		// Capture redirects after a successful rename.
		add_action( 'infyp_filename_updated', [ $this, 'capture_redirects' ], 10, 3 );

		// Serve 301 redirects on the front end. `init` instead of
		// `template_redirect`: a matching request exits before WordPress
		// builds the main query, and a 404 for a renamed image never pays
		// for template resolution. The uploads-path fast-path keeps normal
		// page loads at zero extra cost either way.
		add_action( 'init', [ $this, 'maybe_serve_redirect' ], 1 );

		// Flush deferred hit counts.
		add_action( 'shutdown', [ $this, 'flush_pending_hits' ] );

		// Clean up redirects when an attachment is permanently deleted.
		add_action( 'delete_attachment', [ $this, 'on_delete_attachment' ] );
	}

	// ------------------------------------------------------------------
	//  Capture
	// ------------------------------------------------------------------

	/**
	 * Capture redirect mappings after a filename update.
	 *
	 * Hooked to `infyp_filename_updated` which fires with the full URL map
	 * and page-builder update results.
	 *
	 * @since 1.1.0
	 *
	 * @param int   $image_id Attachment ID.
	 * @param array $url_map  Old URL => New URL mappings.
	 * @param array $results  Page-builder adapter results.
	 */
	public function capture_redirects( $image_id, $url_map, $results ) {
		if ( ! $this->is_enabled() ) {
			return;
		}

		if ( empty( $url_map ) || ! is_array( $url_map ) ) {
			return;
		}

		$source    = $this->determine_source();
		$expires   = $this->get_expiry_date();
		$pb_json   = $this->build_pagebuilder_json( $results );

		foreach ( $url_map as $old_url => $new_url ) {
			if ( $old_url === $new_url ) {
				continue;
			}

			// Resolve redirect chains: any existing redirect pointing to $old_url
			// should now point directly to $new_url.
			INFYP_Redirect_Table::update_chains( $old_url, $new_url );

			INFYP_Redirect_Table::insert( [
				'image_id'            => $image_id,
				'old_url'             => $old_url,
				'new_url'             => $new_url,
				'source'              => $source,
				'expires_at'          => $expires,
				'pagebuilder_results' => $pb_json,
			] );
		}

		// Clean up any self-referencing redirects created by chain resolution
		// (e.g. circular rename A→B→A turns A→B into A→A). Runs once after all
		// inserts instead of per-URL to avoid redundant DELETE queries.
		INFYP_Redirect_Table::delete_self_referencing();

		$this->flush_lookup_cache();
	}

	/**
	 * Invalidate cached lookups after table writes.
	 *
	 * Where the object cache cannot flush a group (no persistent backend, or
	 * pre-flush_group backends), stale positive entries age out via their
	 * one-hour TTL — the same window a re-renamed image's old 301 target can
	 * lag by.
	 *
	 * @since 1.0.7
	 */
	private function flush_lookup_cache() {
		if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) ) {
			wp_cache_flush_group( 'infyp_redirects' );
		}
	}

	// ------------------------------------------------------------------
	//  Serve
	// ------------------------------------------------------------------

	/**
	 * Intercept front-end requests for old image URLs and serve a 301.
	 *
	 * Only activates when the request URI matches the uploads directory path,
	 * so normal page loads have zero additional overhead.
	 *
	 * @since 1.1.0
	 */
	public function maybe_serve_redirect() {
		if ( ! $this->is_enabled() ) {
			return;
		}

		// Only intercept front-end requests.
		if ( is_admin() ) {
			return;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( empty( $request_uri ) ) {
			return;
		}

		// Fast path: skip requests that clearly aren't upload files.
		// Use the actual uploads directory path instead of a hardcoded value
		// to support custom WP_CONTENT_DIR, UPLOADS constant, and Bedrock layouts.
		$upload_path = $this->get_uploads_path();
		if ( strpos( $request_uri, $upload_path ) === false ) {
			return;
		}

		// Build the full URL from the request.
		// Strip the home path to avoid doubling on subdirectory installs
		// (e.g. home_url = example.com/blog, REQUEST_URI = /blog/wp-content/...).
		$home_path = wp_parse_url( home_url(), PHP_URL_PATH );
		if ( $home_path && $home_path !== '/' ) {
			$relative_uri = preg_replace( '#^' . preg_quote( $home_path, '#' ) . '#', '', $request_uri, 1 );
		} else {
			$relative_uri = $request_uri;
		}
		$requested_url = home_url( $relative_uri );

		// Strip query strings — stored redirect URLs never include them.
		$requested_url = strtok( $requested_url, '?' );

		$redirect = $this->lookup_with_cache( $requested_url );

		// Encoded requests (my%20image.jpg) must match rows stored with the
		// literal filename. Second lookup only when decoding changes the URL.
		if ( ! $redirect ) {
			$decoded_url = rawurldecode( $requested_url );
			if ( $decoded_url !== $requested_url ) {
				$redirect = $this->lookup_with_cache( $decoded_url );
			}
		}

		if ( ! $redirect ) {
			$this->count_unmatched_request( $requested_url );
			return;
		}

		// A row can match without case (old IMG_4711.jpg, new img_4711.jpg).
		// If the request is the target itself, the target file is missing and
		// a 301 would point at itself: let the 404 happen instead.
		if ( $redirect->new_url === $requested_url || rawurldecode( $redirect->new_url ) === rawurldecode( $requested_url ) ) {
			return;
		}

		// Defer hit tracking to shutdown to not delay the 301.
		$this->pending_hits[] = (int) $redirect->id;

		// Send 301 with a cache header long enough for SEO benefit but short
		// enough to allow revocation if the redirect target changes.
		header( 'Cache-Control: public, max-age=604800' ); // 1 week.
		wp_safe_redirect( $redirect->new_url, 301, 'infy-image-seo' );
		exit;
	}

	/**
	 * Redirect lookup with positive and negative object caching.
	 *
	 * On hosts with a persistent object cache this absorbs 404 floods
	 * against dead image URLs (bots hammering an old hotlinked file) without
	 * a DB query per request. Without one, wp_cache_* is per-request and the
	 * lookup behaves exactly as before.
	 *
	 * @since 1.0.7
	 *
	 * @param string $url Requested URL, query-stripped.
	 * @return object|null Redirect row or null.
	 */
	private function lookup_with_cache( $url ) {
		$key    = 'lookup_' . md5( $url );
		$cached = wp_cache_get( $key, 'infyp_redirects' );
		if ( 'MISS' === $cached ) {
			return null;
		}
		if ( is_object( $cached ) ) {
			return $cached;
		}

		$redirect = INFYP_Redirect_Table::get_by_old_url( $url );
		if ( $redirect ) {
			wp_cache_set( $key, $redirect, 'infyp_redirects', HOUR_IN_SECONDS );
		} else {
			wp_cache_set( $key, 'MISS', 'infyp_redirects', 5 * MINUTE_IN_SECONDS );
		}
		return $redirect ?: null;
	}

	/**
	 * Count uploads-path requests that reached WordPress with no redirect row.
	 *
	 * Feeds the health UI: a growing count means real visitors are hitting
	 * old image URLs the table does not cover (deleted redirects, expired
	 * rows, or pre-plugin renames). Bounded: one 7-day rolling window, at
	 * most 1000 counted per day, image extensions only.
	 *
	 * @since 1.0.7
	 *
	 * @param string $url Requested URL.
	 */
	private function count_unmatched_request( $url ) {
		if ( ! preg_match( '/\.(jpe?g|png|gif|webp|avif)$/i', $url ) ) {
			return;
		}

		$counts = get_option( 'infyp_redirect_unmatched_404s', [] );
		if ( ! is_array( $counts ) ) {
			$counts = [];
		}
		$today = wp_date( 'Y-m-d' );
		if ( isset( $counts[ $today ] ) && $counts[ $today ] >= 1000 ) {
			return;
		}
		$counts[ $today ] = isset( $counts[ $today ] ) ? $counts[ $today ] + 1 : 1;
		// Prune to a rolling week.
		$cutoff = wp_date( 'Y-m-d', strtotime( '-7 days' ) );
		foreach ( array_keys( $counts ) as $day ) {
			if ( $day < $cutoff ) {
				unset( $counts[ $day ] );
			}
		}
		update_option( 'infyp_redirect_unmatched_404s', $counts, false );
	}

	/**
	 * Unmatched-404 total for the last 7 days.
	 *
	 * @since 1.0.7
	 * @return int
	 */
	public static function get_unmatched_404_count() {
		$counts = get_option( 'infyp_redirect_unmatched_404s', [] );
		return is_array( $counts ) ? (int) array_sum( $counts ) : 0;
	}

	// ------------------------------------------------------------------
	//  Deferred hit tracking
	// ------------------------------------------------------------------

	/**
	 * Flush pending hit count increments.
	 *
	 * @since 1.1.0
	 */
	public function flush_pending_hits() {
		foreach ( $this->pending_hits as $redirect_id ) {
			INFYP_Redirect_Table::record_hit( $redirect_id );
		}
		$this->pending_hits = [];
	}

	// ------------------------------------------------------------------
	//  Cleanup
	// ------------------------------------------------------------------

	/**
	 * Remove redirect entries when an attachment is deleted.
	 *
	 * @since 1.1.0
	 *
	 * @param int $post_id Attachment ID.
	 */
	public function on_delete_attachment( $post_id ) {
		INFYP_Redirect_Table::delete_by_image( $post_id );
		$this->flush_lookup_cache();
	}

	// ------------------------------------------------------------------
	//  Path helpers
	// ------------------------------------------------------------------

	/**
	 * Get the URL path component of the uploads directory.
	 *
	 * Dynamically resolves the path using wp_get_upload_dir() so it works
	 * with custom WP_CONTENT_DIR, the UPLOADS constant, and Bedrock layouts.
	 *
	 * @since 1.1.1
	 *
	 * @return string Path component (e.g. '/wp-content/uploads/').
	 */
	private function get_uploads_path() {
		static $path = null;
		if ( $path === null ) {
			$upload_dir = wp_get_upload_dir();
			$path       = wp_parse_url( $upload_dir['baseurl'], PHP_URL_PATH );
			if ( $path ) {
				// Ensure trailing slash so '/wp-content/uploadsx/' doesn't match.
				$path = trailingslashit( $path );
			} else {
				// Fallback — should never happen on a healthy install.
				$path = '/wp-content/uploads/';
			}
		}
		return $path;
	}

	// ------------------------------------------------------------------
	//  Settings helpers
	// ------------------------------------------------------------------

	/**
	 * Check if the redirect feature is enabled.
	 *
	 * @since 1.1.0
	 *
	 * @return bool
	 */
	public function is_enabled() {
		$settings = get_option( 'infyp_redirect_settings', [] );
		// Enabled by default.
		return ! isset( $settings['enabled'] ) || ! empty( $settings['enabled'] );
	}

	/**
	 * Get the redirect settings.
	 *
	 * @since 1.1.0
	 *
	 * @return array
	 */
	public static function get_settings() {
		return wp_parse_args( get_option( 'infyp_redirect_settings', [] ), [
			'enabled'       => '1',
			'expiry_months' => '12',
		] );
	}

	/**
	 * Set the source label for redirects captured by the renames that follow.
	 *
	 * The code that starts a rename states its source; it is not derived from
	 * the request type, so AJAX, REST, WP-CLI and cron renames are labelled
	 * alike. Callers restore the returned previous value when done.
	 *
	 * @since 1.0.9
	 *
	 * @param string|null $source 'ai', 'manual', or null to reset (manual).
	 * @return string|null The previous value.
	 */
	public static function set_rename_source( $source ) {
		$previous            = self::$rename_source;
		self::$rename_source = in_array( $source, [ 'ai', 'manual' ], true ) ? $source : null;
		return $previous;
	}

	/**
	 * Determine the source of the current rename (manual or AI).
	 *
	 * @since 1.1.0
	 * @since 1.0.9 Uses the source set by the caller; the AJAX action check
	 *              remains only as the fallback for callers that set none.
	 *
	 * @return string 'ai' or 'manual'.
	 */
	private function determine_source() {
		if ( null !== self::$rename_source ) {
			return self::$rename_source;
		}

		// Fallback for callers that do not state a source (1.0.8 behaviour).
		if ( wp_doing_ajax() ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only check for source determination
			$action = isset( $_REQUEST['action'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['action'] ) ) : '';
			if ( strpos( $action, 'infyp_ai_' ) === 0 || strpos( $action, 'infyp_optimize' ) === 0 ) {
				return 'ai';
			}
		}

		return 'manual';
	}

	/**
	 * Calculate the expiry date based on settings.
	 *
	 * Uses current_datetime() (site timezone) to stay consistent with the
	 * created_at column and expire_old_redirects().
	 *
	 * @since 1.1.0
	 *
	 * @return string|null MySQL datetime or null for "never".
	 */
	private function get_expiry_date() {
		$settings = self::get_settings();
		$months   = (int) ( $settings['expiry_months'] ?? 12 );

		if ( $months <= 0 ) {
			return null; // Never expires.
		}

		return current_datetime()->modify( "+{$months} months" )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Build a JSON summary of page-builder adapter results.
	 *
	 * @since 1.1.0
	 *
	 * @param array $results Results from INFYP_PageBuilder_Manager::process_url_updates().
	 * @return string|null JSON string or null.
	 */
	private function build_pagebuilder_json( $results ) {
		if ( empty( $results['adapter_results'] ) || ! is_array( $results['adapter_results'] ) ) {
			return null;
		}

		$summary = [];
		foreach ( $results['adapter_results'] as $adapter_name => $adapter_result ) {
			$summary[ $adapter_name ] = [
				'scanned'  => isset( $adapter_result['stats']['posts_scanned'] ) ? (int) $adapter_result['stats']['posts_scanned'] : 0,
				'updated'  => isset( $adapter_result['updated_posts'] ) ? count( $adapter_result['updated_posts'] ) : 0,
				'replaced' => isset( $adapter_result['stats']['urls_replaced'] ) ? (int) $adapter_result['stats']['urls_replaced'] : 0,
				'errors'   => isset( $adapter_result['errors'] ) ? count( $adapter_result['errors'] ) : 0,
			];
		}

		return wp_json_encode( $summary );
	}
}
