<?php
/**
 * Extracts attachment IDs referenced from a blob of post content.
 *
 * The post-type filter is driven by the wp_infyp_image_post_types lookup
 * table, which historically only knew about two relationships: post_parent
 * ("attached") and _thumbnail_id ("featured"). Images placed inside the
 * editor — Gutenberg blocks, classic <img> markup, galleries — were invisible
 * to it, so they never appeared under their post type.
 *
 * This scanner turns content into a list of attachment IDs so the indexer can
 * record a third relationship: "content".
 *
 * @package    IPAIS
 * @subpackage Database
 * @since 1.0.8
 * @author     InfyPress
 * @license    GPL-2.0-or-later
 */

namespace IPAIS\Database;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class INFYP_Content_Image_Scanner
 *
 * Stateless helper — every method is static.
 *
 * @since 1.0.8
 */
final class INFYP_Content_Image_Scanner {

	/**
	 * Image extensions recognised in upload URLs.
	 *
	 * @since 1.0.8
	 * @var string
	 */
	const URL_EXTENSIONS = 'jpe?g|png|gif|webp|avif';

	/**
	 * Multiplier applied to byte offsets when keying candidates.
	 *
	 * Leaves room to slot the individual IDs of a comma list — `ids="12,34"` —
	 * between the offsets of neighbouring matches without colliding.
	 *
	 * @since 1.0.8
	 * @var int
	 */
	const OFFSET_SCALE = 1000;

	/**
	 * Cache of the uploads path fragments used to spot upload URLs.
	 *
	 * @since 1.0.8
	 * @var string[]|null
	 */
	private static $upload_bases = null;

	/**
	 * Extract every local image attachment ID referenced by a blob of content.
	 *
	 * Resolution is ID-first and URL-second:
	 *
	 * 1. `wp-image-123` classes (classic editor and core blocks).
	 * 2. Block attribute IDs — `"id":123`, `"imageId":123`, `"mediaId":123`.
	 * 3. Shortcode attributes — `image="123"`, `ids="12,34"`, `image_id="123"`.
	 * 4. Upload URLs, matched on the **path** rather than the full URL so
	 *    content that still carries a staging or CDN domain resolves too.
	 *
	 * The result is intersected with real image attachments, so a stale or
	 * foreign ID can never produce a phantom index row.
	 *
	 * @since 1.0.8
	 *
	 * @param string $content Raw post content.
	 * @return int[] Attachment IDs, unique, in order of first appearance.
	 */
	public static function extract_image_ids( $content ) {
		if ( ! is_string( $content ) || '' === $content ) {
			return array();
		}

		// Block attributes store JSON with escaped slashes: "https:\/\/site.com\/file.jpg".
		$content = str_replace( '\\/', '/', $content );

		// Candidates keyed by byte offset (scaled, so a comma list of IDs can
		// slot in between two offsets) — the final list keeps document order.
		$by_offset = array();

		self::collect_ids( '/wp-image-(\d+)/i', $content, $by_offset );
		self::collect_ids( '/"(?:id|imageId|mediaId|thumbnailId|image_id|imageID)"\s*:\s*(\d+)/', $content, $by_offset );

		// Shortcode attributes, single ID or a comma list: [gallery ids="12,34"].
		if ( preg_match_all(
			'/\b(?:image|image_id|img_id|media|default_id|ids|include)\s*=\s*["\']([\d\s,]+)["\']/i',
			$content,
			$matches,
			PREG_OFFSET_CAPTURE
		) ) {
			foreach ( $matches[1] as $match ) {
				$offset = (int) $match[1] * self::OFFSET_SCALE;
				foreach ( preg_split( '/[\s,]+/', $match[0] ) as $index => $id ) {
					if ( '' === $id || (int) $id <= 0 ) {
						continue;
					}
					$by_offset[ $offset + min( $index, self::OFFSET_SCALE - 1 ) ] = array( 'id' => (int) $id );
				}
			}
		}

		// Upload URLs — keep the relative path, resolve it in one batch below.
		$paths   = array();
		$pattern = '#(?:' . implode( '|', self::get_upload_bases() ) . ')/([^"\'\s\\\\)<>]+?\.(?:' . self::URL_EXTENSIONS . '))#i';

		if ( preg_match_all( $pattern, $content, $matches, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches[1] as $match ) {
				$path = self::normalize_path( $match[0] );
				if ( '' === $path ) {
					continue;
				}
				$by_offset[ (int) $match[1] * self::OFFSET_SCALE ] = array( 'path' => $path );
				$paths[ $path ]                                    = true;
			}
		}

		if ( empty( $by_offset ) ) {
			return array();
		}

		$resolved = empty( $paths ) ? array() : self::resolve_paths( array_keys( $paths ) );

		ksort( $by_offset, SORT_NUMERIC );

		$ids = array();
		foreach ( $by_offset as $candidate ) {
			if ( isset( $candidate['id'] ) ) {
				$id = $candidate['id'];
			} else {
				$id = isset( $resolved[ $candidate['path'] ] ) ? $resolved[ $candidate['path'] ] : 0;
			}

			if ( $id > 0 ) {
				$ids[ $id ] = $id;
			}
		}

		if ( empty( $ids ) ) {
			return array();
		}

		/**
		 * Filters the raw attachment IDs found in a blob of content, before
		 * they are validated against the attachment table.
		 *
		 * @since 1.0.8
		 * @param int[]  $ids     Candidate attachment IDs in document order.
		 * @param string $content The content that was scanned (slashes unescaped).
		 */
		$ids = apply_filters( 'infyp_content_image_ids', array_values( $ids ), $content );

		return self::filter_image_attachments( $ids );
	}

	/**
	 * Cheap pre-check: could this content possibly reference an image?
	 *
	 * Mirrors the SQL prefilter used by the bulk backfill so single-post
	 * refreshes skip the regex work on text-only posts.
	 *
	 * @since 1.0.8
	 *
	 * @param string $content Raw post content.
	 * @return bool True when the content is worth scanning.
	 */
	public static function content_may_contain_images( $content ) {
		if ( ! is_string( $content ) || '' === $content ) {
			return false;
		}

		foreach ( self::get_prefilter_terms() as $term ) {
			if ( false !== stripos( $content, $term ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Substrings that mark content as possibly containing an image reference.
	 *
	 * Used both as `LIKE` terms in the bulk backfill query and as an in-PHP
	 * pre-check, so the two paths always agree on what they skip.
	 *
	 * @since 1.0.8
	 *
	 * @return string[] Substrings to test for.
	 */
	public static function get_prefilter_terms() {
		$terms = array( 'wp-content/uploads', 'wp-image-', '[gallery', '"imageId"', '"mediaId"' );

		// Custom upload directories won't contain 'wp-content/uploads'.
		foreach ( self::get_upload_bases() as $base ) {
			$terms[] = str_replace( '\\', '', $base );
		}

		/**
		 * Filters the substrings that mark content as worth scanning for images.
		 *
		 * @since 1.0.8
		 * @param string[] $terms Substrings tested against post content.
		 */
		return array_values( array_unique( apply_filters( 'infyp_content_scan_prefilter_terms', $terms ) ) );
	}

	/**
	 * Run one ID-capturing pattern and merge the hits into the candidate map.
	 *
	 * @since 1.0.8
	 *
	 * @param string $pattern   Regex with a single capture group holding the ID.
	 * @param string $content   Content to search.
	 * @param array  $by_offset Candidate map, keyed by byte offset. Passed by reference.
	 * @return void
	 */
	private static function collect_ids( $pattern, $content, &$by_offset ) {
		if ( ! preg_match_all( $pattern, $content, $matches, PREG_OFFSET_CAPTURE ) ) {
			return;
		}

		foreach ( $matches[1] as $match ) {
			$id = (int) $match[0];
			if ( $id > 0 ) {
				$by_offset[ (int) $match[1] * self::OFFSET_SCALE ] = array( 'id' => $id );
			}
		}
	}

	/**
	 * Uploads path fragments to look for inside URLs, regex-quoted.
	 *
	 * Always includes the site's real uploads path (which honours
	 * UPLOADS / custom upload_path / multisite), plus the conventional
	 * `/wp-content/uploads` so content imported from another install still
	 * resolves.
	 *
	 * @since 1.0.8
	 *
	 * @return string[] Regex-quoted path fragments without a trailing slash.
	 */
	private static function get_upload_bases() {
		if ( null !== self::$upload_bases ) {
			return self::$upload_bases;
		}

		$bases = array( 'wp-content/uploads' );

		$upload_dir = wp_upload_dir( null, false );
		if ( ! empty( $upload_dir['baseurl'] ) ) {
			$path = wp_parse_url( $upload_dir['baseurl'], PHP_URL_PATH );
			if ( $path ) {
				$bases[] = trim( $path, '/' );
			}
		}

		$bases = array_unique( array_filter( $bases ) );

		self::$upload_bases = array_map(
			static function ( $base ) {
				return preg_quote( $base, '#' );
			},
			$bases
		);

		return self::$upload_bases;
	}

	/**
	 * Reduce a matched URL path to the form stored in `_wp_attached_file`.
	 *
	 * Drops any query string or fragment and strips the `-800x600` size
	 * suffix that WordPress appends to generated thumbnails.
	 *
	 * @since 1.0.8
	 *
	 * @param string $path Path captured after the uploads base.
	 * @return string Normalized relative path, or '' when unusable.
	 */
	private static function normalize_path( $path ) {
		$path = ltrim( $path, '/' );

		// Trim anything after the extension (?ver=, #anchor).
		$path = preg_split( '/[?#]/', $path )[0];

		if ( '' === $path || false !== strpos( $path, '..' ) ) {
			return '';
		}

		// Drop the generated-size suffix: foo-300x200.jpg becomes foo.jpg.
		$path = preg_replace( '/-\d+x\d+(\.(?:' . self::URL_EXTENSIONS . '))$/i', '$1', $path );

		return $path;
	}

	/**
	 * Resolve relative upload paths to attachment IDs in a single query.
	 *
	 * Matches `_wp_attached_file` exactly (never LIKE). Big-image uploads are
	 * stored as `foo-scaled.jpg` while content may reference either form, so
	 * both variants are queried.
	 *
	 * @since 1.0.8
	 *
	 * @global \wpdb $wpdb WordPress database object.
	 *
	 * @param string[] $paths Relative upload paths.
	 * @return array<string,int> Map of requested path => attachment ID.
	 */
	private static function resolve_paths( array $paths ) {
		global $wpdb;

		if ( empty( $paths ) ) {
			return array();
		}

		// Each requested path may be stored with or without the -scaled suffix.
		$variants = array();
		foreach ( $paths as $path ) {
			$variants[ $path ] = $path;

			$unscaled = preg_replace( '/-scaled(\.(?:' . self::URL_EXTENSIONS . '))$/i', '$1', $path );
			if ( $unscaled !== $path ) {
				$variants[ $unscaled ] = $path;
			} else {
				$scaled = preg_replace( '/(\.(?:' . self::URL_EXTENSIONS . '))$/i', '-scaled$1', $path );
				if ( $scaled !== $path ) {
					$variants[ $scaled ] = $path;
				}
			}
		}

		$placeholders = implode( ', ', array_fill( 0, count( $variants ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholder list is generated from the value count; results feed a rebuildable index.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_value
				 FROM {$wpdb->postmeta}
				 WHERE meta_key = '_wp_attached_file'
				 AND meta_value IN ({$placeholders})",
				...array_keys( $variants )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		$resolved = array();
		foreach ( (array) $rows as $row ) {
			if ( isset( $variants[ $row->meta_value ] ) ) {
				$resolved[ $variants[ $row->meta_value ] ] = (int) $row->post_id;
			}
		}

		return $resolved;
	}

	/**
	 * Keep only IDs that really are image attachments.
	 *
	 * @since 1.0.8
	 *
	 * @global \wpdb $wpdb WordPress database object.
	 *
	 * @param int[] $ids Candidate IDs.
	 * @return int[] Validated IDs, in the order they were given.
	 */
	private static function filter_image_attachments( array $ids ) {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );

		if ( empty( $ids ) ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$mime_sql     = infyp_get_mime_type_sql();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Placeholders generated from the ID count; $mime_sql comes from esc_sql().
		$valid = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE ID IN ({$placeholders})
				 AND post_type = 'attachment'
				 AND post_status = 'inherit'
				 AND post_mime_type IN ({$mime_sql})",
				...$ids
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$valid = array_map( 'intval', (array) $valid );

		// Preserve document order.
		return array_values( array_intersect( $ids, $valid ) );
	}
}
