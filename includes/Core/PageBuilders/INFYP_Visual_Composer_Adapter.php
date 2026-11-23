<?php
/**
 * Visual Composer Website Builder Adapter - Visual Composer Support
 *
 * This adapter handles image URL updates for Visual Composer Website Builder
 * (WPBakery Page Builder alternative). Visual Composer stores page content as
 * JSON-encoded data in the 'vcv-pageContent' meta key with a nested element structure.
 *
 * The adapter supports:
 * - Visual Composer elements with image fields
 * - Gallery elements
 * - Background images
 * - Custom element images
 * - Dynamic content detection (skips ACF fields, featured images, etc.)
 *
 * Processing Strategy:
 * 1. Detect Visual Composer posts via meta key
 * 2. Decode JSON content (with fallbacks for URL-encoded, Base64, serialized)
 * 3. Create backup before processing
 * 4. Recursively process element tree
 * 5. Skip dynamic sources (ACF, featured images, etc.)
 * 6. Update image URLs and gallery arrays
 * 7. Validate encoded content before saving
 * 8. Clear Visual Composer caches
 *
 * Data Structure:
 * Visual Composer uses this format:
 * - elements{}: Object of element IDs
 *   - [element-id]:
 *     - tag: Element type
 *     - settings: Element settings (where images live)
 *     - elements{}: Nested child elements
 *
 * Important Notes:
 * - Preserves attachment IDs (only replaces URL strings)
 * - Skips dynamic sources to avoid breaking ACF/featured image bindings
 * - Creates backups with '_backup' suffix for safety
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
 * Visual Composer Website Builder Adapter Class
 *
 * Specialized adapter for handling Visual Composer (VCV) page builder content.
 * Processes JSON-based element structures with intelligent dynamic source detection.
 *
 * Key Features:
 * - Multiple decoding strategies (JSON, URL-encoded, Base64, serialized)
 * - Dynamic content detection (skips ACF fields, featured images)
 * - Automatic backups before processing
 * - Gallery array processing
 * - Attachment ID preservation
 * - Comprehensive error handling and validation
 *
 * @package   IPAIS\Core\PageBuilders
 * @since 1.0.0
 *
 * @see       INFYP_PageBuilder_Base_Adapter For inherited utilities
 * @see       https://visualcomposer.com/ Visual Composer Official Site
 */
class INFYP_Visual_Composer_Adapter extends INFYP_PageBuilder_Base_Adapter {

	/**
	 * Visual Composer meta key for page content
	 *
	 * Stores the complete element tree as JSON.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const META_KEY = 'vcv-pageContent';

	/**
	 * Backup meta key suffix
	 *
	 * Appended to META_KEY when creating backups before processing.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const BACKUP_SUFFIX = '_backup';

	/**
	 * Image field names to process in element settings
	 *
	 * List of setting keys that may contain image URLs.
	 *
	 * @since 1.0.0
	 * @var   array<string>
	 */
	private $image_fields = array(
		'image',
		'backgroundImage',
		'url',
		'src',
		'images', // Gallery field.
	);

	/**
	 * Dynamic source indicators to skip
	 *
	 * Elements with these indicators use dynamic content (ACF, featured images)
	 * and should not have URLs updated.
	 *
	 * @since 1.0.0
	 * @var   array
	 */
	private $dynamic_indicators = array(
		'source'       => array( 'featured_image', 'acf_field', 'post_thumbnail' ),
		'dynamicField' => true,
	);

	/**
	 * Get adapter name
	 *
	 * @return string
	 */
	public function get_name() {
		return 'Visual Composer Website Builder';
	}

	/**
	 * Check if Visual Composer is active
	 *
	 * @return bool
	 */
	public function is_active() {
		// Check for VCV_VERSION constant.
		if ( defined( 'VCV_VERSION' ) ) {
			return true;
		}

		// Check for Visual Composer container class.
		if ( class_exists( 'VisualComposer\\Framework\\Container' ) ) {
			return true;
		}

		// Fallback: Check if any posts have VC content.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Detection check, no caching needed for one-time activation check.
		$has_content = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT 1",
				self::META_KEY
			)
		);

		return ( $has_content > 0 );
	}

	/**
	 * Process URL updates
	 *
	 * @param array $url_map Mapping of old URLs to new URLs.
	 * @return array Results with updated_posts and errors.
	 */
	public function process_url_updates( $url_map ) {
		if ( ! $this->is_active() ) {
			$this->log_info( 'Visual Composer is not active, skipping' );
			return array(
				'updated_posts' => array(),
				'errors'        => array(),
			);
		}

		$this->log_info( 'Starting Visual Composer URL updates' );
		$this->reset_stats();

		// Get posts with VC content.
		$post_ids = $this->get_posts_with_vc_content();
		$this->log_info( sprintf( 'Found %d posts with Visual Composer content', count( $post_ids ) ) );

		if ( empty( $post_ids ) ) {
			$this->finalize_stats();
			return array(
				'updated_posts' => array(),
				'errors'        => array(),
			);
		}

		// Process in batches using base adapter method.
		$results = $this->process_posts_in_batches(
			$post_ids,
			$url_map,
			array( $this, 'process_single_post' ),
			50 // Process 50 posts per batch.
		);

		$this->finalize_stats();
		$this->log_info( sprintf(
			'Visual Composer processing complete: %d posts scanned, %d updated, %d URLs replaced',
			$this->stats['posts_scanned'],
			$this->stats['posts_updated'],
			$this->stats['urls_replaced']
		) );

		return $results;
	}

	/**
	 * Clear Visual Composer caches for updated posts
	 *
	 * @param array $updated_post_ids Array of post IDs that were updated.
	 * @return bool True on success, false on failure.
	 */
	public function clear_cache( $updated_post_ids = array() ) {
		if ( ! $this->is_active() ) {
			return false;
		}

		if ( empty( $updated_post_ids ) ) {
			return true;
		}

		try {
			// Clear Visual Composer cache for each post.
			foreach ( $updated_post_ids as $post_id ) {
				$this->clear_vc_cache( $post_id );
			}

			// Clear global Visual Composer cache if available.
			if ( function_exists( 'vchelper' ) ) {
				try {
					vchelper( 'Cache' )->clear();
					$this->log_info( 'Cleared global Visual Composer cache' );
				} catch ( \Exception $e ) {
					$this->log_warning( sprintf(
						'Failed to clear global Visual Composer cache: %s',
						$e->getMessage()
					) );
				}
			}

			// Clear WordPress object cache.
			wp_cache_flush();

			return true;

		} catch ( \Exception $e ) {
			$this->log_error( sprintf(
				'Error clearing Visual Composer cache: %s',
				$e->getMessage()
			) );
			return false;
		}
	}

	/**
	 * Get posts with Visual Composer content
	 *
	 * @return array Array of post IDs.
	 */
	private function get_posts_with_vc_content() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fetching all posts with VC content for processing, no caching needed for one-time bulk operation.
		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s",
				self::META_KEY
			)
		);

		return array_map( 'intval', $post_ids );
	}

	/**
	 * Process a single post
	 *
	 * @param int   $post_id Post ID.
	 * @param array $url_map URL mapping.
	 * @return array Result with 'updated' boolean and optional 'errors' array.
	 * @throws \Exception If processing fails.
	 */
	protected function process_single_post( $post_id, $url_map ) {
		// Set current post ID for enhanced logging.
		$this->current_post_id = $post_id;

		// Load raw content.
		$raw_content = get_post_meta( $post_id, self::META_KEY, true );

		if ( empty( $raw_content ) ) {
			$this->log_debug( sprintf( 'Post %d has empty Visual Composer content', $post_id ) );
			return array( 'updated' => false );
		}

		// Decode JSON content.
		$data = $this->decode_vc_content( $raw_content );

		if ( ! $data ) {
			throw new \Exception( 'Failed to decode Visual Composer content' );
		}

		// Validate structure.
		if ( ! isset( $data['elements'] ) ) {
			$this->log_debug( sprintf( 'Post %d: Visual Composer content missing elements key', $post_id ) );
			return array( 'updated' => false );
		}

		// Backup before processing.
		update_post_meta( $post_id, self::META_KEY . self::BACKUP_SUFFIX, $raw_content );

		// Process elements recursively.
		$replaced_count = 0;
		$this->process_vc_elements( $data['elements'], $url_map, $replaced_count );

		// Save if changes were made.
		if ( $replaced_count > 0 ) {
			$encoded = $this->encode_vc_content( $data );

			// Validate encoded content.
			if ( ! $encoded ) {
				throw new \Exception( 'Failed to encode Visual Composer content' );
			}

			// Double-check we can decode it back.
			$validation = $this->decode_vc_content( $encoded );
			if ( ! $validation || ! isset( $validation['elements'] ) ) {
				throw new \Exception( 'Encoded content failed validation' );
			}

			// Save updated content.
			update_post_meta( $post_id, self::META_KEY, $encoded );

			// Clear caches.
			$this->clear_vc_cache( $post_id );

			$this->update_stats( 'posts_updated' );
			$this->update_stats( 'urls_replaced', $replaced_count );

			$this->log_info( sprintf(
				'Post %d: Updated %d URLs in Visual Composer content',
				$post_id,
				$replaced_count
			) );

			return array( 'updated' => true );
		}

		return array( 'updated' => false );
	}

	/**
	 * Decode Visual Composer content with multiple fallback strategies
	 *
	 * @param string $raw_content Raw content from meta.
	 * @return array|null Decoded data or null on failure.
	 */
	private function decode_vc_content( $raw_content ) {
		// Strategy 1: Direct JSON decode.
		$data = json_decode( $raw_content, true );
		if ( json_last_error() === JSON_ERROR_NONE && is_array( $data ) ) {
			return $data;
		}

		// Strategy 2: URL-encoded JSON.
		$decoded_url = urldecode( $raw_content );
		$data        = json_decode( $decoded_url, true );
		if ( json_last_error() === JSON_ERROR_NONE && is_array( $data ) ) {
			$this->log_debug( 'Decoded Visual Composer content using URL decoding' );
			return $data;
		}

		// Strategy 3: Base64-encoded JSON.
		if ( base64_encode( base64_decode( $raw_content, true ) ) === $raw_content ) {
			$decoded_base64 = base64_decode( $raw_content, true );
			$data           = json_decode( $decoded_base64, true );
			if ( json_last_error() === JSON_ERROR_NONE && is_array( $data ) ) {
				$this->log_debug( 'Decoded Visual Composer content using Base64 decoding' );
				return $data;
			}
		}

		// Strategy 4: Serialized PHP (rare).
		$maybe_unserialized = @unserialize( $raw_content, ['allowed_classes' => false] );
		if ( $maybe_unserialized !== false ) {
			$this->log_debug( 'Decoded Visual Composer content using PHP unserialize' );
			return $maybe_unserialized;
		}

		$this->log_error( 'Failed to decode Visual Composer content with all strategies' );
		return null;
	}

	/**
	 * Encode Visual Composer content back to JSON
	 *
	 * @param array $data Data to encode.
	 * @return string|null Encoded JSON or null on failure.
	 */
	private function encode_vc_content( $data ) {
		$encoded = wp_json_encode( $data );

		if ( $encoded === false ) {
			$this->log_error( 'Failed to encode Visual Composer content to JSON' );
			return null;
		}

		return $encoded;
	}

	/**
	 * Process Visual Composer elements recursively
	 *
	 * @param array $elements       Elements array (passed by reference).
	 * @param array $url_map        URL mapping.
	 * @param int   $replaced_count Counter for replacements (passed by reference).
	 */
	private function process_vc_elements( &$elements, $url_map, &$replaced_count ) {
		if ( ! is_array( $elements ) ) {
			return;
		}

		foreach ( $elements as $element_id => &$element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			// Check if this is a dynamic source (skip if yes).
			if ( $this->is_dynamic_source( $element ) ) {
				$this->log_debug( sprintf(
					'Skipping dynamic element: %s',
					$element_id
				) );
				continue;
			}

			// Process settings.
			if ( isset( $element['settings'] ) && is_array( $element['settings'] ) ) {
				$this->process_element_settings( $element['settings'], $url_map, $replaced_count );
			}

			// Recurse into child elements.
			if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$this->process_vc_elements( $element['elements'], $url_map, $replaced_count );
			}
		}
	}

	/**
	 * Process element settings
	 *
	 * @param array $settings       Settings array (passed by reference).
	 * @param array $url_map        URL mapping.
	 * @param int   $replaced_count Counter for replacements (passed by reference).
	 */
	private function process_element_settings( &$settings, $url_map, &$replaced_count ) {
		foreach ( $settings as $key => &$value ) {
			// Check if this is an image field.
			if ( ! $this->is_image_field( $key ) ) {
				continue;
			}

			// Handle gallery arrays.
			if ( 'images' === $key && is_array( $value ) ) {
				$this->process_gallery( $value, $url_map, $replaced_count );
				continue;
			}

			// Skip attachment IDs (PRESERVE).
			if ( is_numeric( $value ) ) {
				continue;
			}

			// Process URL (REPLACE).
			if ( is_string( $value ) && $this->is_url( $value ) ) {
				$result = $this->safe_url_replace( $value, $url_map, $key );

				if ( $result['replaced_count'] > 0 ) {
					$value = $result['data'];
					$replaced_count += $result['replaced_count'];
				}
			}
		}
	}

	/**
	 * Process gallery images
	 *
	 * @param array $images         Gallery images array (passed by reference).
	 * @param array $url_map        URL mapping.
	 * @param int   $replaced_count Counter for replacements (passed by reference).
	 */
	private function process_gallery( &$images, $url_map, &$replaced_count ) {
		foreach ( $images as &$image ) {
			if ( ! is_array( $image ) ) {
				continue;
			}

			// Process URL in gallery item.
			if ( isset( $image['url'] ) && is_string( $image['url'] ) && $this->is_url( $image['url'] ) ) {
				$result = $this->safe_url_replace( $image['url'], $url_map, 'gallery_image' );

				if ( $result['replaced_count'] > 0 ) {
					$image['url'] = $result['data'];
					$replaced_count += $result['replaced_count'];
				}
			}

			// Note: Preserve 'id' field if present (attachment ID).
		}
	}

	/**
	 * Check if element is using a dynamic source
	 *
	 * @param array $element Element data.
	 * @return bool True if dynamic source detected.
	 */
	private function is_dynamic_source( $element ) {
		if ( ! is_array( $element ) ) {
			return false;
		}

		// Check settings for dynamic indicators.
		if ( isset( $element['settings'] ) && is_array( $element['settings'] ) ) {
			$settings = $element['settings'];

			// Check for source field with dynamic values.
			if ( isset( $settings['source'] ) ) {
				if ( in_array( $settings['source'], $this->dynamic_indicators['source'], true ) ) {
					return true;
				}
			}

			// Check for dynamicField flag.
			if ( isset( $settings['dynamicField'] ) && $settings['dynamicField'] === true ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if a key is an image field
	 *
	 * @param string $key Field key.
	 * @return bool True if image field.
	 */
	private function is_image_field( $key ) {
		return in_array( $key, $this->image_fields, true );
	}

	/**
	 * Check if a value is a URL
	 *
	 * @param string $value Value to check.
	 * @return bool True if URL.
	 */
	private function is_url( $value ) {
		return is_string( $value ) && ( strpos( $value, 'http://' ) === 0 || strpos( $value, 'https://' ) === 0 );
	}

	/**
	 * Clear Visual Composer cache
	 *
	 * @param int $post_id Post ID.
	 */
	private function clear_vc_cache( $post_id ) {
		// Clear Visual Composer cache if helper exists.
		if ( function_exists( 'vchelper' ) ) {
			try {
				vchelper( 'Cache' )->clear();
				$this->log_debug( sprintf( 'Post %d: Cleared Visual Composer cache', $post_id ) );
			} catch ( \Exception $e ) {
				$this->log_warning( sprintf(
					'Post %d: Failed to clear Visual Composer cache: %s',
					$post_id,
					$e->getMessage()
				) );
			}
		}

		// Clear WordPress caches.
		clean_post_cache( $post_id );
		wp_cache_delete( $post_id, 'posts' );
	}
}
