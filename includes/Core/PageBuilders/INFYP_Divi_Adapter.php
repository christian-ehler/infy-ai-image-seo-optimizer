<?php
/**
 * Divi Builder Adapter - Divi / Extra / Divi Builder Plugin Support
 *
 * This adapter handles image URL updates for the Divi Builder (Elegant
 * Themes). Divi stores layout content as shortcodes in post_content
 * (e.g. [et_pb_image src="..."], [et_pb_section background_image="..."])
 * and page-level custom CSS in the _et_pb_custom_css post meta. Reusable
 * layouts (Divi Library) and Theme Builder templates live in their own,
 * non-public, post types.
 *
 * The adapter supports:
 * - All Divi shortcodes containing image URLs (et_pb_image, et_pb_gallery,
 *   et_pb_slide, background_image / image_url attributes, etc.)
 * - Divi page-level custom CSS stored in _et_pb_custom_css meta
 * - Divi Library items and Theme Builder templates (non-public post types)
 *
 * Processing Strategy:
 * 1. Detect Divi via ET_BUILDER_VERSION / ET_CORE_VERSION constants,
 *    et_setup_theme()/ET_Builder_Element, or [et_pb_ content fallback
 * 2. Query posts (including Divi Library / Theme Builder post types)
 *    containing [et_pb_ shortcodes that reference target URLs
 * 3. Replace image URLs using safe string replacement
 * 4. Process the _et_pb_custom_css meta key for background-image URLs
 * 5. Save post_content with wp_kses filters temporarily removed
 * 6. Clear Divi's static CSS cache
 *
 * Scope note: this targets Divi's classic shortcode storage (the dominant
 * model). Divi layouts that do not persist image URLs as shortcode/CSS
 * strings are out of scope for this adapter.
 *
 * @package   IPAIS\Core\PageBuilders
 * @since 1.0.4
 * @author    InfyPress
 * @license   GPL-2.0-or-later
 */

namespace IPAIS\Core\PageBuilders;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Divi Builder Adapter Class
 *
 * Specialized adapter for handling Divi Builder content. Processes
 * shortcode-based post_content (including Divi Library and Theme Builder
 * post types) and the Divi page custom CSS meta key.
 *
 * @package   IPAIS\Core\PageBuilders
 * @since 1.0.4
 *
 * @see       INFYP_PageBuilder_Base_Adapter For inherited utilities
 * @see       INFYP_WPBakery_Adapter For the shortcode-builder reference pattern
 */
class INFYP_Divi_Adapter extends INFYP_PageBuilder_Base_Adapter {

	/**
	 * Divi meta keys that may contain image URLs (page-level custom CSS).
	 *
	 * @since 1.0.4
	 * @var array
	 */
	const CSS_META_KEYS = [
		'_et_pb_custom_css',
	];

	/**
	 * Divi-specific post types used for reusable layouts and Theme Builder
	 * templates. These are not registered as public, so they are merged
	 * into the queried post-type set explicitly.
	 *
	 * @since 1.0.4
	 * @var array
	 */
	const DIVI_POST_TYPES = [
		'et_pb_layout',
		'et_template',
		'et_header_layout',
		'et_body_layout',
		'et_footer_layout',
	];

	/**
	 * Shortcode prefix used to identify Divi content.
	 *
	 * @since 1.0.4
	 * @var string
	 */
	protected $shortcode_prefix = '[et_pb_';

	/**
	 * Check if Divi is active.
	 *
	 * @since 1.0.4
	 * @return bool
	 */
	public function is_active() {
		if ( defined( 'ET_BUILDER_VERSION' ) || defined( 'ET_CORE_VERSION' ) ) {
			return true;
		}

		if ( function_exists( 'et_setup_theme' ) || class_exists( 'ET_Builder_Element' ) ) {
			return true;
		}

		// Fallback: check if any posts have Divi content.
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time check, not worth caching.
		$has_content = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_content LIKE %s AND post_status IN ('publish','draft','private') LIMIT 1",
				'%' . $wpdb->esc_like( '[et_pb_' ) . '%'
			)
		);

		return $has_content > 0;
	}

	/**
	 * Get the display name of this page builder.
	 *
	 * @since 1.0.4
	 * @return string
	 */
	public function get_name() {
		return 'Divi';
	}

	/**
	 * Process URL updates for Divi content.
	 *
	 * Finds all posts containing Divi shortcodes (including Divi Library
	 * and Theme Builder post types), replaces image URLs in both
	 * post_content and the Divi custom CSS meta key.
	 *
	 * @since 1.0.4
	 * @param array $url_map Array of old_url => new_url mappings.
	 * @return array Array containing 'updated_posts' and 'errors' keys.
	 */
	public function process_url_updates( $url_map ) {
		global $wpdb;

		$this->reset_stats();
		$this->log_info( 'Starting ' . $this->get_name() . ' URL updates', [ 'url_count' => count( $url_map ) ] );

		// Find posts with Divi shortcodes that contain target URLs.
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
	 * Find posts containing Divi shortcodes with target URLs.
	 *
	 * Includes public/editor post types plus Divi Library and Theme
	 * Builder post types (which are not registered as public).
	 *
	 * @since 1.0.4
	 * @param \wpdb $wpdb     WordPress database instance.
	 * @param array $url_map  Array of old_url => new_url mappings.
	 * @return array Array of post IDs.
	 */
	protected function find_posts_with_content( $wpdb, $url_map ) {
		$post_types      = get_post_types( [ 'public' => true ], 'names' );
		$supported_types = [];

		foreach ( $post_types as $post_type ) {
			if ( post_type_supports( $post_type, 'editor' ) ) {
				$supported_types[] = $post_type;
			}
		}

		// Merge in Divi Library / Theme Builder post types when registered.
		foreach ( self::DIVI_POST_TYPES as $divi_pt ) {
			if ( post_type_exists( $divi_pt ) && ! in_array( $divi_pt, $supported_types, true ) ) {
				$supported_types[] = $divi_pt;
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

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is prepared via $wpdb->prepare() on the line above.
		return $wpdb->get_col( $query );
	}

	/**
	 * Process a single post's Divi content.
	 *
	 * Replaces URLs in post_content shortcodes and the Divi custom CSS
	 * meta key.
	 *
	 * @since 1.0.4
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

				// Temporarily disable kses filters to preserve Divi shortcodes/CSS.
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

			// Stage 2: Replace URLs in Divi custom CSS meta keys.
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
			$error              = sprintf( 'Exception processing %s post %d: %s', $this->get_name(), $post_id, $e->getMessage() );
			$result['errors'][] = $error;
			$this->log_error( $error, [ 'post_id' => $post_id ] );
		}

		return $result;
	}

	/**
	 * Clear Divi-related caches for updated posts.
	 *
	 * Clears WordPress post caches and purges Divi's static CSS cache so
	 * regenerated stylesheets reference the new image URLs.
	 *
	 * @since 1.0.4
	 * @param array $updated_post_ids Array of updated post IDs.
	 * @return bool True on success.
	 */
	public function clear_cache( $updated_post_ids = [] ) {
		foreach ( $updated_post_ids as $post_id ) {
			clean_post_cache( $post_id );
			wp_cache_delete( $post_id, 'post_meta' );
		}

		// Purge Divi transients / static CSS cache when the API is available.
		if ( function_exists( 'et_core_clear_transients' ) ) {
			et_core_clear_transients();
		}

		if ( class_exists( 'ET_Core_PageResource' ) && method_exists( 'ET_Core_PageResource', 'do_cache_bust' ) ) {
			\ET_Core_PageResource::do_cache_bust();
		}

		// Remove the on-disk Divi static CSS cache directory if present.
		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['basedir'] ) ) {
			$et_cache_dir = trailingslashit( $upload_dir['basedir'] ) . 'et-cache';

			if ( is_dir( $et_cache_dir ) ) {
				global $wp_filesystem;
				if ( ! function_exists( 'WP_Filesystem' ) ) {
					require_once ABSPATH . 'wp-admin/includes/file.php';
				}
				if ( empty( $wp_filesystem ) ) {
					WP_Filesystem();
				}
				if ( ! empty( $wp_filesystem ) ) {
					$wp_filesystem->rmdir( $et_cache_dir, true );
				}
			}
		}

		if ( ! empty( $updated_post_ids ) ) {
			wp_cache_flush();
		}

		return true;
	}

	/**
	 * Validate data integrity for a given post.
	 *
	 * @since 1.0.4
	 * @param int $post_id The post ID to validate.
	 * @return bool True if data is valid.
	 */
	public function validate_data( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}

		// Check that post_content still contains Divi shortcodes.
		return strpos( $post->post_content, '[et_pb_' ) !== false
			|| strpos( $post->post_content, '[/et_pb_' ) !== false;
	}
}
