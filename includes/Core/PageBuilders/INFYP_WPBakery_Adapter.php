<?php
/**
 * WPBakery Page Builder Adapter - WPBakery Support
 *
 * This adapter handles image URL updates for WPBakery Page Builder
 * (formerly Visual Composer). WPBakery stores page content as shortcodes
 * in post_content and custom CSS in post meta.
 *
 * The adapter supports:
 * - All WPBakery shortcodes containing image URLs (vc_single_image, vc_gallery, etc.)
 * - Background image URLs in vc_row, vc_column, vc_section CSS attributes
 * - Custom CSS stored in _wpb_shortcodes_custom_css and _wpb_post_custom_css meta
 *
 * Processing Strategy:
 * 1. Detect WPBakery via WPB_VC_VERSION constant or Vc_Manager class
 * 2. Query posts containing [vc_ shortcodes in post_content
 * 3. Replace image URLs using safe string replacement
 * 4. Process WPBakery CSS meta keys for background-image URLs
 * 5. Save post_content with wp_kses filters temporarily removed
 * 6. Clear WPBakery caches
 *
 * @package   IPAIS\Core\PageBuilders
 * @since 1.0.0
 * @author    InfyPress
 * @license   GPL-2.0-or-later
 */

namespace IPAIS\Core\PageBuilders;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WPBakery Page Builder Adapter Class
 *
 * Specialized adapter for handling WPBakery Page Builder content.
 * Processes shortcode-based post_content and CSS meta keys.
 *
 * @package   IPAIS\Core\PageBuilders
 * @since 1.0.0
 *
 * @see       INFYP_PageBuilder_Base_Adapter For inherited utilities
 */
class INFYP_WPBakery_Adapter extends INFYP_PageBuilder_Base_Adapter {

	/**
	 * WPBakery CSS meta keys that may contain image URLs.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	const CSS_META_KEYS = [
		'_wpb_shortcodes_custom_css',
		'_wpb_post_custom_css',
	];

	/**
	 * Shortcode prefix used to identify WPBakery content.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	protected $shortcode_prefix = '[vc_';

	/**
	 * Check if WPBakery is active.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public function is_active() {
		if ( defined( 'WPB_VC_VERSION' ) ) {
			return true;
		}

		if ( class_exists( 'Vc_Manager' ) ) {
			return true;
		}

		// Fallback: check if any posts have WPBakery content.
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time check, not worth caching.
		$has_content = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_content LIKE %s AND post_status IN ('publish','draft','private') LIMIT 1",
				'%' . $wpdb->esc_like( '[vc_' ) . '%'
			)
		);

		return $has_content > 0;
	}

	/**
	 * Get the display name of this page builder.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public function get_name() {
		return 'WPBakery';
	}

	/**
	 * Process URL updates for WPBakery content.
	 *
	 * Finds all posts containing WPBakery shortcodes, replaces image URLs
	 * in both post_content and CSS meta keys.
	 *
	 * @since 1.0.0
	 * @param array $url_map Array of old_url => new_url mappings.
	 * @return array Array containing 'updated_posts' and 'errors' keys.
	 */
	public function process_url_updates( $url_map ) {
		global $wpdb;

		$this->reset_stats();
		$this->log_info( 'Starting ' . $this->get_name() . ' URL updates', [ 'url_count' => count( $url_map ) ] );

		// Find posts with WPBakery shortcodes that contain target URLs.
		$post_ids = $this->find_posts_with_content( $wpdb, $url_map );

		if ( empty( $post_ids ) ) {
			$this->log_info( 'No ' . $this->get_name() . ' posts found with target URLs' );
			$this->finalize_stats();
			return [ 'updated_posts' => [], 'errors' => [] ];
		}

		$this->log_info( 'Found ' . $this->get_name() . ' posts', [ 'count' => count( $post_ids ) ] );

		// Process posts in batches.
		$results = $this->process_posts_in_batches(
			$post_ids,
			$url_map,
			[ $this, 'process_single_post' ],
			30
		);

		$this->finalize_stats();
		$this->log_info( 'Completed ' . $this->get_name() . ' URL updates', $this->get_stats() );

		return $results;
	}

	/**
	 * Find posts containing WPBakery shortcodes with target URLs.
	 *
	 * @since 1.0.0
	 * @param \wpdb $wpdb     WordPress database instance.
	 * @param array $url_map  Array of old_url => new_url mappings.
	 * @return array Array of post IDs.
	 */
	protected function find_posts_with_content( $wpdb, $url_map ) {
		$post_types = get_post_types( [ 'public' => true ], 'names' );
		$supported_types = [];

		foreach ( $post_types as $post_type ) {
			if ( post_type_supports( $post_type, 'editor' ) ) {
				$supported_types[] = $post_type;
			}
		}

		if ( empty( $supported_types ) ) {
			return [];
		}

		// Build URL LIKE conditions.
		$url_like_conditions = [];
		foreach ( $url_map as $old_url => $new_url ) {
			$url_like_conditions[] = 'post_content LIKE %s';
		}

		$post_type_placeholders = implode( ', ', array_fill( 0, count( $supported_types ), '%s' ) );

		$query_template = "SELECT ID FROM {$wpdb->posts}
			WHERE post_content LIKE %s
			AND (" . implode( ' OR ', $url_like_conditions ) . ")
			AND post_type IN (" . $post_type_placeholders . ")
			AND post_status IN ('publish', 'draft', 'private', 'future')
			ORDER BY ID DESC";

		$query_params = [ '%' . $wpdb->esc_like( $this->shortcode_prefix ) . '%' ];
		foreach ( $url_map as $old_url => $new_url ) {
			$query_params[] = '%' . $wpdb->esc_like( $old_url ) . '%';
		}
		$query_params = array_merge( $query_params, $supported_types );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query template uses placeholders, prepared below.
		$query = $wpdb->prepare( $query_template, $query_params );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is prepared via $wpdb->prepare() on line 190.
		return $wpdb->get_col( $query );
	}

	/**
	 * Process a single post's WPBakery content.
	 *
	 * Replaces URLs in post_content shortcodes and CSS meta keys.
	 *
	 * @since 1.0.0
	 * @param int   $post_id The post ID to process.
	 * @param array $url_map Array of old_url => new_url mappings.
	 * @return array Result with 'updated' and 'errors' keys.
	 */
	public function process_single_post( $post_id, $url_map ) {
		$result = [ 'updated' => false, 'errors' => [] ];

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return $result;
		}

		try {
			$post = get_post( $post_id );
			if ( ! $post || empty( $post->post_content ) ) {
				return $result;
			}

			$total_replaced = 0;

			// Stage 1: Replace URLs in post_content.
			$content_result = $this->safe_url_replace( $post->post_content, $url_map );
			if ( $content_result['replaced_count'] > 0 ) {
				$update_data = [
					'ID'           => $post_id,
					'post_content' => $content_result['data'],
				];

				// Temporarily disable kses filters to preserve WPBakery CSS.
				remove_filter( 'content_save_pre', 'wp_filter_post_kses' );
				remove_filter( 'content_filtered_save_pre', 'wp_filter_post_kses' );

				$update_success = wp_update_post( $update_data, true );

				add_filter( 'content_save_pre', 'wp_filter_post_kses' );
				add_filter( 'content_filtered_save_pre', 'wp_filter_post_kses' );

				if ( is_wp_error( $update_success ) ) {
					$result['errors'][] = sprintf( 'Failed to update post %d: %s', $post_id, $update_success->get_error_message() );
					return $result;
				}

				$total_replaced += $content_result['replaced_count'];
			}

			// Stage 2: Replace URLs in WPBakery CSS meta keys.
			foreach ( self::CSS_META_KEYS as $meta_key ) {
				$meta_value = get_post_meta( $post_id, $meta_key, true );
				if ( empty( $meta_value ) ) {
					continue;
				}

				$meta_result = $this->safe_url_replace( $meta_value, $url_map );
				if ( $meta_result['replaced_count'] > 0 ) {
					update_post_meta( $post_id, $meta_key, $meta_result['data'] );
					$total_replaced += $meta_result['replaced_count'];
				}
			}

			if ( $total_replaced > 0 ) {
				$result['updated'] = true;
				$this->update_stats( 'posts_updated' );
				$this->update_stats( 'urls_replaced', $total_replaced );

				$this->log_info( 'Updated ' . $this->get_name() . ' post', [
					'post_id'      => $post_id,
					'replacements' => $total_replaced,
				] );
			}
		} catch ( \Exception $e ) {
			$error = sprintf( 'Exception processing %s post %d: %s', $this->get_name(), $post_id, $e->getMessage() );
			$result['errors'][] = $error;
			$this->log_error( $error, [ 'post_id' => $post_id ] );
		}

		return $result;
	}

	/**
	 * Clear WPBakery-related caches for updated posts.
	 *
	 * @since 1.0.0
	 * @param array $updated_post_ids Array of updated post IDs.
	 * @return void
	 */
	public function clear_cache( $updated_post_ids = [] ) {
		foreach ( $updated_post_ids as $post_id ) {
			clean_post_cache( $post_id );

			// Clear WPBakery-generated CSS meta cache.
			wp_cache_delete( $post_id, 'post_meta' );
		}

		if ( ! empty( $updated_post_ids ) ) {
			wp_cache_flush();
		}
	}

	/**
	 * Validate data integrity for a given post.
	 *
	 * @since 1.0.0
	 * @param int $post_id The post ID to validate.
	 * @return bool True if data is valid.
	 */
	public function validate_data( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}

		// Check that post_content still contains WPBakery shortcodes.
		return strpos( $post->post_content, '[vc_' ) !== false
			|| strpos( $post->post_content, '[/vc_' ) !== false;
	}
}
