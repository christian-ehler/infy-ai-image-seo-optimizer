<?php
/**
 * File Renamer - Secure Attachment File Renaming
 *
 * Handles the actual filesystem rename of WordPress attachment files
 * with comprehensive security validation:
 * - Filename sanitization via Security Manager
 * - Upload directory boundary checks (prevents path traversal)
 * - Extension allowlist enforcement
 * - Collision detection
 * - Pre-rename filter for extension control
 *
 * After a successful rename, WordPress attachment metadata is regenerated
 * (thumbnails, image sizes) and caches are invalidated. URL propagation
 * across post content and page builders is handled separately by
 * INFYP_Content_Updater and INFYP_Filename_Updater.
 *
 * @package   IPAIS\Core
 * @since 1.0.0
 *
 * @see INFYP_Content_Updater  For URL replacement in post content.
 * @see INFYP_Filename_Updater For URL propagation across page builders.
 */

namespace IPAIS\Core;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * File Renamer Class
 *
 * Provides a single static method to securely rename attachment files
 * on the WordPress filesystem. Used by AJAX handlers for both manual
 * filename edits and AI-generated filename optimization.
 *
 * @package IPAIS\Core
 * @since 1.0.0
 */
class INFYP_File_Renamer {

	/**
	 * Strips known image file extensions from a filename string.
	 *
	 * The plugin stores filenames as stems only (without extension).
	 * AI providers sometimes include extensions in their output, and users
	 * may paste filenames with extensions. This method removes them.
	 *
	 * Uses an image-extension-specific regex so legitimate dots are preserved:
	 * - `sunset.jpg` → `sunset`
	 * - `version-2.0-release` → `version-2.0-release` (not an image extension)
	 * - `file.jpg.png` → `file` (handles double extensions via loop)
	 *
	 * @since 1.0.0
	 *
	 * @param string $filename The filename that may contain an image extension.
	 * @return string The filename with image extensions removed.
	 */
	public static function strip_file_extension( $filename ) {
		$pattern = '/\.(?:jpe?g|png|webp|gif|bmp|tiff?|svg|avif|heic|heif)$/i';

		// Loop to handle double extensions (e.g., file.jpg.png), max 3 iterations.
		for ( $i = 0; $i < 3; $i++ ) {
			$stripped = preg_replace( $pattern, '', $filename );
			if ( $stripped === $filename ) {
				break;
			}
			$filename = $stripped;
		}

		return $filename;
	}

	/**
	 * Rename an attachment file with security validation.
	 *
	 * Validates the filename, ensures the file is within the uploads
	 * directory, checks the extension against an allowlist, performs
	 * the rename via WP_Filesystem, and regenerates attachment metadata.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $post_id              The attachment post ID.
	 * @param string $new_filename_sans_ext The new filename without extension.
	 * @return bool True on success, false on failure.
	 */
	public static function rename_file( $post_id, $new_filename_sans_ext ) {
		// Defense-in-depth: verify the current user can modify files.
		if ( ! current_user_can( 'upload_files' ) ) {
			return new \WP_Error( 'insufficient_permissions', __( 'You do not have permission to rename files.', 'infy-ai-image-seo-optimizer' ) );
		}

		// Guard against callers passing a filename with an image extension.
		// The real extension is appended from the original file below.
		$new_filename_sans_ext = self::strip_file_extension( $new_filename_sans_ext );

		$security      = INFYP_Security_Manager::get_instance();
		$error_handler = INFYP_Error_Handler::get_instance();
		$cache         = INFYP_Cache_Manager::get_instance();

		// Use security manager for validation.
		if ( ! $security->validate_filename( $new_filename_sans_ext ) ) {
			$error_handler->log_error(
				'Invalid filename rejected',
				'rename_file',
				'security',
				[ 'filename' => $new_filename_sans_ext, 'post_id' => $post_id ]
			);
			return false;
		}

		$file_path = get_attached_file( $post_id, true );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return false;
		}

		// Validate file is within uploads directory.
		$upload_dir      = wp_upload_dir();
		$real_file_path  = realpath( $file_path );
		$real_upload_path = realpath( $upload_dir['basedir'] );
		$real_upload_base = $real_upload_path ? trailingslashit( $real_upload_path ) : false;

		if ( $real_file_path === false || $real_upload_base === false || strpos( $real_file_path, $real_upload_base ) !== 0 ) {
			$error_handler->log_error(
				'File path outside uploads directory',
				'rename_file',
				'security',
				[ 'file_path' => $file_path, 'post_id' => $post_id ]
			);
			return false;
		}

		$path_parts = pathinfo( $file_path );
		$extension  = $path_parts['extension'];

		// Validate extension against allowlist.
		$allowed_extensions = [ 'jpg', 'jpeg', 'png', 'webp', 'gif' ];
		if ( ! in_array( strtolower( $extension ), $allowed_extensions, true ) ) {
			return false;
		}

		$new_filename = $new_filename_sans_ext . '.' . $extension;
		$new_path     = $path_parts['dirname'] . '/' . $new_filename;

		// Final security check: ensure new path stays within uploads.
		$real_dirname = realpath( $path_parts['dirname'] );
		if ( $real_dirname === false ) {
			return false;
		}
		$real_new_path = $real_dirname . '/' . $new_filename;
		if ( strpos( $real_new_path, $real_upload_base ) !== 0 ) {
			$error_handler->log_error(
				'New file path would be outside uploads directory',
				'rename_file',
				'security',
				[ 'new_path' => $new_path, 'post_id' => $post_id ]
			);
			return false;
		}

		// No-op if the filename is unchanged.
		if ( $new_path === $file_path ) {
			return true;
		}

		// Prevent overwriting existing files.
		if ( file_exists( $new_path ) ) {
			return false;
		}

		/**
		 * Filter whether to proceed with a file rename.
		 *
		 * Return false to cancel the rename.
		 *
		 * @since 1.0.0
		 * @param bool   $proceed      Whether to proceed. Default true.
		 * @param int    $post_id      The attachment ID.
		 * @param string $new_filename The proposed new filename (without extension).
		 * @param string $old_path     The current file path.
		 */
		$proceed = apply_filters( 'infyp_pre_file_rename', true, $post_id, $new_filename_sans_ext, $file_path );
		if ( ! $proceed ) {
			return false;
		}

		// Use WordPress filesystem API for better compatibility.
		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( empty( $wp_filesystem ) ) {
			WP_Filesystem();
		}

		// Capture old thumbnail file paths BEFORE rename for cleanup afterwards.
		$old_metadata        = wp_get_attachment_metadata( $post_id );
		$old_url             = wp_get_attachment_url( $post_id );
		$old_thumbnail_files = [];
		if ( ! empty( $old_metadata['sizes'] ) && is_array( $old_metadata['sizes'] ) ) {
			foreach ( $old_metadata['sizes'] as $size_data ) {
				if ( ! empty( $size_data['file'] ) ) {
					$thumb_file = basename( $size_data['file'] );
					$thumb_path = $path_parts['dirname'] . '/' . $thumb_file;
					if ( $thumb_path !== $file_path && file_exists( $thumb_path ) ) {
						$old_thumbnail_files[] = $thumb_path;
					}
				}
			}
		}
		// Also capture -scaled or original_image variant.
		if ( ! empty( $old_metadata['original_image'] ) ) {
			$original_file = basename( $old_metadata['original_image'] );
			$original_path = $path_parts['dirname'] . '/' . $original_file;
			if ( $original_path !== $file_path && file_exists( $original_path ) ) {
				$old_thumbnail_files[] = $original_path;
			}
		}

		if ( $wp_filesystem->move( $file_path, $new_path ) ) {
			// Old URLs are mapped to the new ones from the exact old and new
			// file names once the new metadata exists (infyp_file_renamed).
			INFYP_Filename_Updater::begin_file_rename(
				$post_id,
				$file_path,
				is_array( $old_metadata ) ? $old_metadata : [],
				is_string( $old_url ) ? $old_url : ''
			);

			try {
				update_attached_file( $post_id, $new_path );
				wp_update_post( [ 'ID' => $post_id, 'post_name' => $new_filename_sans_ext ] );

				// Regenerate metadata (thumbnails, image sizes). This is not an
				// upload, so it must not fire infyp_after_image_upload.
				if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
					require_once ABSPATH . 'wp-admin/includes/image.php';
				}
				INFYP_Cache_Manager::begin_metadata_regeneration( $post_id );
				try {
					$metadata = wp_generate_attachment_metadata( $post_id, $new_path );
					wp_update_attachment_metadata( $post_id, $metadata );
				} finally {
					INFYP_Cache_Manager::end_metadata_regeneration( $post_id );
				}

				// Delete old thumbnail files that are no longer needed.
				if ( ! empty( $old_thumbnail_files ) ) {
					// Build set of new files to protect from deletion.
					$protected_files = [ $new_path ];
					if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
						foreach ( $metadata['sizes'] as $size_data ) {
							if ( ! empty( $size_data['file'] ) ) {
								$protected_files[] = dirname( $new_path ) . '/' . basename( $size_data['file'] );
							}
						}
					}
					if ( ! empty( $metadata['original_image'] ) ) {
						$protected_files[] = dirname( $new_path ) . '/' . basename( $metadata['original_image'] );
					}

					foreach ( $old_thumbnail_files as $old_thumb ) {
						if ( ! in_array( $old_thumb, $protected_files, true ) && file_exists( $old_thumb ) ) {
							$wp_filesystem->delete( $old_thumb );
						}
					}
				}

				// Clear caches.
				$cache->invalidate_image_cache( $post_id );
				clean_attachment_cache( $post_id );

				/**
				 * Fires after a file has been successfully renamed.
				 *
				 * @since 1.0.0
				 * @param int    $post_id  The attachment ID.
				 * @param string $old_path The previous file path.
				 * @param string $new_path The new file path.
				 */
				do_action( 'infyp_file_renamed', $post_id, $file_path, $new_path );
			} finally {
				// Normally done on infyp_file_renamed; also after an exception, so
				// a later rename of this image in the same request is handled.
				INFYP_Filename_Updater::end_file_rename( $post_id );
			}

			return true;
		}

		return false;
	}
}
