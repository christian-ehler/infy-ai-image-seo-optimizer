<?php
/**
 * Uncode Theme Adapter - Uncode Page Builder Support
 *
 * This adapter handles image URL updates for the Uncode WordPress theme,
 * which uses a modified version of WPBakery Page Builder. Content is stored
 * as shortcodes in post_content using both vc_* and uncode_* prefixes.
 *
 * The adapter extends the WPBakery adapter and inherits all shortcode
 * processing logic. It overrides detection, post discovery, and cache
 * clearing to handle Uncode-specific patterns.
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
 * Uncode Theme Adapter Class
 *
 * Extends the WPBakery adapter with Uncode-specific detection and
 * shortcode pattern matching. Handles both [vc_*] and [uncode_*]
 * shortcode formats used by the Uncode theme.
 *
 * @package   IPAIS\Core\PageBuilders
 * @since 1.0.0
 *
 * @see       INFYP_WPBakery_Adapter For inherited shortcode processing
 */
class INFYP_Uncode_Adapter extends INFYP_WPBakery_Adapter {

	/**
	 * Check if Uncode theme is active.
	 *
	 * Returns false if WPBakery plugin is separately installed to avoid
	 * double-processing the same posts.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public function is_active() {
		// Avoid double-processing if WPBakery plugin is installed separately.
		if ( defined( 'WPB_VC_VERSION' ) || class_exists( 'Vc_Manager' ) ) {
			return false;
		}

		// Check for Uncode theme.
		$template = get_template();
		if ( $template === 'uncode' ) {
			return true;
		}

		// Fallback: check if any posts have Uncode shortcodes.
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time check.
		$has_content = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_content LIKE %s AND post_status IN ('publish','draft','private') LIMIT 1",
				'%' . $wpdb->esc_like( '[uncode_' ) . '%'
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
		return 'Uncode';
	}

	/**
	 * Find posts containing Uncode content with target URLs.
	 *
	 * Extends the parent query to also match [uncode_*] shortcodes
	 * in addition to [vc_*] shortcodes.
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

		// Match both [vc_*] and [uncode_*] shortcode patterns.
		$query_template = "SELECT ID FROM {$wpdb->posts}
			WHERE (post_content LIKE %s OR post_content LIKE %s)
			AND (" . implode( ' OR ', $url_like_conditions ) . ")
			AND post_type IN (" . $post_type_placeholders . ")
			AND post_status IN ('publish', 'draft', 'private', 'future')
			ORDER BY ID DESC";

		$query_params = [
			'%' . $wpdb->esc_like( '[vc_' ) . '%',
			'%' . $wpdb->esc_like( '[uncode_' ) . '%',
		];
		foreach ( $url_map as $old_url => $new_url ) {
			$query_params[] = '%' . $wpdb->esc_like( $old_url ) . '%';
		}
		$query_params = array_merge( $query_params, $supported_types );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query template uses placeholders, prepared below.
		$query = $wpdb->prepare( $query_template, $query_params );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is prepared via $wpdb->prepare() on line 135.
		return $wpdb->get_col( $query );
	}

	/**
	 * Clear Uncode-related caches for updated posts.
	 *
	 * @since 1.0.0
	 * @param array $updated_post_ids Array of updated post IDs.
	 * @return void
	 */
	public function clear_cache( $updated_post_ids = [] ) {
		// Call parent cache clearing for basic post cache.
		parent::clear_cache( $updated_post_ids );

		// Clear Uncode adaptive CSS if it exists.
		if ( function_exists( 'uncode_delete_dynamic_css_transient' ) ) {
			uncode_delete_dynamic_css_transient();
		}
	}

	/**
	 * Validate data integrity for a given post.
	 *
	 * Checks for both vc_* and uncode_* shortcodes.
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

		return strpos( $post->post_content, '[vc_' ) !== false
			|| strpos( $post->post_content, '[uncode_' ) !== false;
	}
}
