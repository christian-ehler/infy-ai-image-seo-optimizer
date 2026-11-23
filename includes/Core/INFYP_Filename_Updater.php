<?php
/**
 * Filename Updater Class
 *
 * Handles automatic propagation of image filename changes across WordPress content
 * and all supported page builders. When an attachment's filename is updated, this class
 * ensures all references (including image sizes) are updated throughout the site.
 *
 * Production-optimized with support for multiple page builders and comprehensive
 * URL mapping to handle all image size variations.
 *
 * @package    IPAIS
 * @subpackage Core
 * @since 1.0.0
 * @author     InfyPress Team
 * @license    GPL-2.0-or-later
 */

namespace IPAIS\Core;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use IPAIS\Core\PageBuilders\INFYP_PageBuilder_Manager;

/**
 * Filename Updater Class
 *
 * Automatically updates image URLs across WordPress when an attachment's filename changes.
 * Integrates with the Page Builder Manager to ensure changes propagate to:
 * - Gutenberg blocks
 * - Elementor
 * - Visual Composer
 * - Additional builders via extensions
 *
 * **Supported Features:**
 * - Automatic URL mapping for all image sizes
 * - Integration with attachment_updated hook
 * - Manual trigger capability
 * - Comprehensive logging and error handling
 * - Cache clearing for page builders
 *
 * **Hook Integration:**
 * - `attachment_updated` (priority 20) - Automatic filename change detection
 * - `infyp_manual_filename_update` - Manual update trigger
 * - `infyp_filename_url_map` (filter) - Allows extensions to modify URL mappings
 * - `infyp_filename_updated` (action) - Fires after successful update
 *
 * **Usage Example:**
 * ```php
 * // Automatic usage via WordPress hooks (no manual code needed)
 * // When user renames attachment in Media Library, updates happen automatically
 *
 * // Manual trigger:
 * $url_map = [
 *     'old-url.jpg' => 'new-url.jpg',
 *     'old-url-300x200.jpg' => 'new-url-300x200.jpg'
 * ];
 * do_action( 'infyp_manual_filename_update', $attachment_id, $url_map );
 * ```
 *
 * @since 1.0.0
 */
class INFYP_Filename_Updater {

    /**
     * Page builder manager instance.
     *
     * Handles communication with all registered page builder adapters.
     *
     * @since 1.0.0
     * @var   INFYP_PageBuilder_Manager
     */
    private $page_builder_manager;

    /**
     * Cache for URL mappings.
     *
     * Temporary storage for URL mapping data to improve performance
     * during a single update operation.
     *
     * @since 1.0.0
     * @var   array
     */
    private $url_cache = [];

    /**
     * Renames by INFYP_File_Renamer in this request, keyed by attachment ID.
     *
     * Each entry holds the old file path, the old metadata, the old
     * attachment URL and the post before the rename. The URL map for these
     * renames is built from the exact old and new file names when
     * `infyp_file_renamed` fires, after the new metadata exists.
     *
     * @since 1.0.9
     * @var   array<int, array<string, mixed>>
     */
    private static $renames_in_progress = [];

    /**
     * Constructor - Sets up WordPress hooks.
     *
     * Registers all necessary WordPress actions and hooks for automatic
     * filename update detection and processing.
     *
     * @since 1.0.0
     */
    public function __construct() {
        add_action( 'init', [ $this, 'init_manager' ], 5 );
        add_action( 'attachment_updated', [ $this, 'handle_attachment_update' ], 20, 3 );
        add_action( 'infyp_file_renamed', [ $this, 'handle_file_renamed' ], 5, 1 );
        add_action( 'shutdown', [ $this, 'clear_cache' ] );
    }

    /**
     * Initializes the page builder manager singleton.
     *
     * Lazy-loads the Page Builder Manager instance on first use.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function init_manager() {
        if ( ! $this->page_builder_manager ) {
            $this->page_builder_manager = INFYP_PageBuilder_Manager::get_instance();
        }
    }

    /**
     * Handles attachment filename updates.
     *
     * Callback for 'attachment_updated' hook. Detects filename changes and triggers
     * URL update process across all page builders and content.
     *
     * @since 1.0.0
     *
     * @param int      $post_id     The attachment ID.
     * @param \WP_Post $post_after  The attachment post object after the update.
     * @param \WP_Post $post_before The attachment post object before the update.
     *
     * @return void
     */
    public function handle_attachment_update( $post_id, $post_after, $post_before ) {
        if ( ! $this->page_builder_manager ) {
            $this->init_manager();
        }

        if ( ! wp_attachment_is_image( $post_id ) ) {
            return;
        }

        if ( $post_after->post_name === $post_before->post_name ) {
            return;
        }

        // Our own renamer reports its change through infyp_file_renamed, once
        // the new file names are known.
        if ( isset( self::$renames_in_progress[ (int) $post_id ] ) ) {
            return;
        }

        $start_time = microtime( true );

        INFYP_Logger::log( 'Attachment filename update detected - ID: ' . $post_id, 'INFO' );

        try {
            $url_map = $this->build_url_map( $post_before, $post_after );
            $this->apply_url_map( (int) $post_id, $url_map, $start_time );
        } catch ( \Exception $e ) {
            INFYP_Logger::log( 'Fatal error during filename update: ' . $e->getMessage(), 'ERROR' );
            do_action( 'infyp_filename_update_error', $post_id, $e );
        }
    }

    /**
     * Record a file rename by INFYP_File_Renamer before the attachment changes.
     *
     * Called after the file was moved and before the attached file, the slug
     * and the metadata are updated. handle_file_renamed() completes it.
     *
     * @internal Used by INFYP_File_Renamer.
     * @since 1.0.9
     *
     * @param int    $attachment_id Attachment ID.
     * @param string $old_file      Absolute path of the main file before the rename.
     * @param array  $old_metadata  Attachment metadata before the rename.
     * @param string $old_url       Attachment URL before the rename.
     * @return void
     */
    public static function begin_file_rename( $attachment_id, $old_file, $old_metadata, $old_url = '' ) {
        $post = get_post( $attachment_id );

        self::$renames_in_progress[ (int) $attachment_id ] = [
            'old_file'     => (string) $old_file,
            'old_metadata' => $old_metadata,
            'old_url'      => (string) $old_url,
            'post_before'  => $post instanceof \WP_Post ? clone $post : null,
        ];
    }

    /**
     * Forget a rename recorded by begin_file_rename().
     *
     * No-op when handle_file_renamed() already handled it.
     *
     * @internal Used by INFYP_File_Renamer.
     * @since 1.0.9
     *
     * @param int $attachment_id Attachment ID.
     * @return void
     */
    public static function end_file_rename( $attachment_id ) {
        unset( self::$renames_in_progress[ (int) $attachment_id ] );
    }

    /**
     * Update content and record redirects after a rename by INFYP_File_Renamer.
     *
     * Callback for `infyp_file_renamed` (priority 5). The map pairs every old
     * file of the image (main file, `-scaled` main file, pre-scale original,
     * each sub-size) with its new file, by the exact names in the old and
     * the new metadata, so the case of the old names is kept.
     *
     * @since 1.0.9
     *
     * @param int $attachment_id Attachment ID.
     * @return void
     */
    public function handle_file_renamed( $attachment_id ) {
        $attachment_id = (int) $attachment_id;

        if ( ! isset( self::$renames_in_progress[ $attachment_id ] ) ) {
            return;
        }

        $rename = self::$renames_in_progress[ $attachment_id ];
        unset( self::$renames_in_progress[ $attachment_id ] );

        $this->init_manager();

        $post_after = get_post( $attachment_id );
        if ( ! $post_after instanceof \WP_Post || ! $rename['post_before'] instanceof \WP_Post ) {
            return;
        }

        $start_time = microtime( true );

        INFYP_Logger::log( 'Attachment file renamed - ID: ' . $attachment_id, 'INFO' );

        try {
            $url_map = $this->build_rename_url_map( $attachment_id, $rename );

            /** This filter is documented in includes/Core/INFYP_Filename_Updater.php */
            $url_map = apply_filters( 'infyp_filename_url_map', $url_map, $rename['post_before'], $post_after );

            $this->apply_url_map( $attachment_id, is_array( $url_map ) ? $url_map : [], $start_time );
        } catch ( \Exception $e ) {
            INFYP_Logger::log( 'Fatal error during filename update: ' . $e->getMessage(), 'ERROR' );
            do_action( 'infyp_filename_update_error', $attachment_id, $e );
        }
    }

    /**
     * Run the page builder updates for a URL map and announce the change.
     *
     * @since 1.0.9
     *
     * @param int   $attachment_id Attachment ID.
     * @param array $url_map       Old URL => new URL.
     * @param float $start_time    Microtime when the update began.
     * @return void
     */
    private function apply_url_map( $attachment_id, $url_map, $start_time ) {
        if ( empty( $url_map ) ) {
            INFYP_Logger::log( 'No URL mappings generated', 'INFO' );
            return;
        }

        INFYP_Logger::log( 'Generated URL mappings: ' . count( $url_map ) . ' URLs', 'INFO' );

        $options = [
            'validate_data' => true,
            'source'        => 'attachment_update',
        ];

        $results = $this->page_builder_manager->process_url_updates( $url_map, $options );

        $this->log_results( $results, $attachment_id, $start_time );

        do_action( 'infyp_filename_updated', $attachment_id, $url_map, $results );

        clean_attachment_cache( $attachment_id );
    }

    /**
     * Build the URL map of a rename by INFYP_File_Renamer.
     *
     * Every old file gets an entry: the old main file (the `-scaled` file for
     * a large upload) maps to the new main file; each old sub-size maps to the
     * new file of the same size name, or to the new main file when that size
     * no longer exists; the old pre-scale original maps to the new original,
     * or to the new main file (the renamer keeps only the scaled file).
     *
     * @since 1.0.9
     *
     * @param int   $attachment_id Attachment ID.
     * @param array $rename        Entry recorded by begin_file_rename().
     * @return array Old URL => new URL.
     */
    private function build_rename_url_map( $attachment_id, $rename ) {
        $url_map    = [];
        $upload_dir = wp_upload_dir();

        if ( ! empty( $upload_dir['error'] ) || empty( $upload_dir['baseurl'] ) ) {
            return $url_map;
        }

        $old_file = $rename['old_file'];
        $new_file = get_attached_file( $attachment_id, true );

        if ( ! $old_file || ! $new_file ) {
            return $url_map;
        }

        $old_meta = $rename['old_metadata'];
        $new_meta = wp_get_attachment_metadata( $attachment_id, true );
        $new_meta = is_array( $new_meta ) ? $new_meta : [];

        $old_dir_url  = self::upload_dir_url( $old_file, $upload_dir['baseurl'] );
        $new_dir_url  = self::upload_dir_url( $new_file, $upload_dir['baseurl'] );
        $new_main_url = $new_dir_url . '/' . wp_basename( $new_file );

        // Every file the image has now; none of them may be redirected away.
        $new_urls = [ $new_main_url => true ];
        if ( ! empty( $new_meta['sizes'] ) ) {
            foreach ( (array) $new_meta['sizes'] as $size_data ) {
                if ( ! empty( $size_data['file'] ) ) {
                    $new_urls[ $new_dir_url . '/' . wp_basename( $size_data['file'] ) ] = true;
                }
            }
        }
        if ( ! empty( $new_meta['original_image'] ) ) {
            $new_urls[ $new_dir_url . '/' . wp_basename( $new_meta['original_image'] ) ] = true;
        }

        $add = static function ( $old_url, $new_url ) use ( &$url_map, $new_urls ) {
            if ( $old_url && $new_url && $old_url !== $new_url && ! isset( $new_urls[ $old_url ] ) && ! isset( $url_map[ $old_url ] ) ) {
                $url_map[ $old_url ] = $new_url;
            }
        };

        // Main file, as stored and as served (the attachment URL may be filtered).
        $add( $old_dir_url . '/' . wp_basename( $old_file ), $new_main_url );

        $new_attachment_url = wp_get_attachment_url( $attachment_id );
        if ( $rename['old_url'] && is_string( $new_attachment_url ) && $new_attachment_url !== $new_main_url ) {
            $add( $rename['old_url'], $new_attachment_url );
        }

        // Sub-sizes, by size name.
        if ( ! empty( $old_meta['sizes'] ) && is_array( $old_meta['sizes'] ) ) {
            foreach ( $old_meta['sizes'] as $size => $size_data ) {
                if ( empty( $size_data['file'] ) ) {
                    continue;
                }

                $target = $new_main_url;
                if ( ! empty( $new_meta['sizes'][ $size ]['file'] ) ) {
                    $target = $new_dir_url . '/' . wp_basename( $new_meta['sizes'][ $size ]['file'] );
                }

                $add( $old_dir_url . '/' . wp_basename( $size_data['file'] ), $target );
            }
        }

        // Pre-scale original of a large upload.
        if ( ! empty( $old_meta['original_image'] ) ) {
            $target = ! empty( $new_meta['original_image'] )
                ? $new_dir_url . '/' . wp_basename( $new_meta['original_image'] )
                : $new_main_url;

            $add( $old_dir_url . '/' . wp_basename( $old_meta['original_image'] ), $target );
        }

        return $url_map;
    }

    /**
     * URL of the uploads directory that holds a file, without trailing slash.
     *
     * @since 1.0.9
     *
     * @param string $file    Absolute file path.
     * @param string $baseurl Uploads base URL.
     * @return string
     */
    private static function upload_dir_url( $file, $baseurl ) {
        $relative = _wp_relative_upload_path( $file );
        $dir      = $relative ? dirname( $relative ) : '.';

        return untrailingslashit( $baseurl ) . ( '.' === $dir ? '' : '/' . $dir );
    }

    /**
     * Builds comprehensive URL mapping for all image sizes.
     *
     * Creates a complete map of old URLs to new URLs including:
     * - Base attachment URL
     * - All registered image sizes
     * - Upload directory relative paths
     *
     * Allows extensions to modify mappings via 'infyp_filename_url_map' filter.
     *
     * @since 1.0.0
     * @access private
     *
     * @param \WP_Post $post_before The attachment before update.
     * @param \WP_Post $post_after  The attachment after update.
     * @return array Associative array of old_url => new_url mappings.
     */
    private function build_url_map( $post_before, $post_after ) {
        $url_map = [];

        try {
            $upload_dir = wp_upload_dir();

            if ( is_wp_error( $upload_dir ) ) {
                INFYP_Logger::log( 'Failed to get upload directory', 'ERROR' );
                return $url_map;
            }

            // Note: $post_before->ID === $post_after->ID (same attachment).
            // By the time attachment_updated fires, update_attached_file() has already
            // changed the DB metadata to the new path. So wp_get_attachment_url() and
            // get_attached_file() both return the NEW values for either post object.
            //
            // Instead, derive old URLs from the new URL by replacing post_name
            // (the slug), which WordPress passes correctly as before/after snapshots.
            $new_base_url = wp_get_attachment_url( $post_after->ID );
            $new_file     = get_attached_file( $post_after->ID );

            if ( ! $new_base_url || ! $new_file ) {
                return $url_map;
            }

            // Reconstruct old URL/path by replacing the new slug with the old slug.
            $old_slug = $post_before->post_name;
            $new_slug = $post_after->post_name;

            if ( $old_slug === $new_slug ) {
                return $url_map;
            }

            // A slug edit without a file rename leaves every URL as it is.
            // Without this check, sub-size files whose names start with the
            // old slug were mapped to files that do not exist.
            if ( 0 !== stripos( wp_basename( $new_file ), $new_slug ) ) {
                return $url_map;
            }

            // Get the extension from the current file.
            $extension    = pathinfo( $new_file, PATHINFO_EXTENSION );
            $new_basename = $new_slug . '.' . $extension;
            $old_basename = $old_slug . '.' . $extension;

            $old_base_url = str_replace( $new_basename, $old_basename, $new_base_url );
            $old_file     = str_replace( $new_basename, $old_basename, $new_file );

            if ( $old_base_url !== $new_base_url ) {
                $url_map[ $old_base_url ] = $new_base_url;
            }

            // Also map relative-path-based URLs.
            $old_relative = _wp_relative_upload_path( $old_file );
            $new_relative = _wp_relative_upload_path( $new_file );
            if ( $old_relative && $new_relative && $old_relative !== $new_relative ) {
                $old_url = $upload_dir['baseurl'] . '/' . $old_relative;
                $new_url = $upload_dir['baseurl'] . '/' . $new_relative;
                if ( ! isset( $url_map[ $old_url ] ) ) {
                    $url_map[ $old_url ] = $new_url;
                }
            }

            // Handle image sizes.
            // Metadata may still contain old size filenames (if called before
            // wp_generate_attachment_metadata) or new ones (if called after).
            // Use stem-based mapping: replace old slug with new slug in size filenames.
            $metadata = wp_get_attachment_metadata( $post_after->ID );
            $file_dir = dirname( $new_file );

            if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
                foreach ( $metadata['sizes'] as $size => $size_data ) {
                    if ( empty( $size_data['file'] ) ) {
                        continue;
                    }

                    $size_file = $size_data['file'];

                    // Determine old and new size filenames based on slug.
                    // When one slug is a prefix of the other (e.g. "orange-gradient"
                    // vs "orange-gradient-grid-pattern"), both strpos checks would
                    // match. Prefer the longer (more specific) slug in that case.
                    $old_matches = strpos( $size_file, $old_slug ) === 0;
                    $new_matches = strpos( $size_file, $new_slug ) === 0;

                    if ( $old_matches && $new_matches ) {
                        // Ambiguous — the longer slug is the more specific match.
                        if ( strlen( $old_slug ) >= strlen( $new_slug ) ) {
                            $old_size_file = $size_file;
                            $new_size_file = $new_slug . substr( $size_file, strlen( $old_slug ) );
                        } else {
                            $new_size_file = $size_file;
                            $old_size_file = $old_slug . substr( $size_file, strlen( $new_slug ) );
                        }
                    } elseif ( $new_matches ) {
                        // Metadata already has new filenames.
                        $new_size_file = $size_file;
                        $old_size_file = $old_slug . substr( $size_file, strlen( $new_slug ) );
                    } elseif ( $old_matches ) {
                        // Metadata still has old filenames.
                        $old_size_file = $size_file;
                        $new_size_file = $new_slug . substr( $size_file, strlen( $old_slug ) );
                    } else {
                        continue;
                    }

                    $old_size_url = $upload_dir['baseurl'] . '/' . dirname( _wp_relative_upload_path( $file_dir . '/' . $old_size_file ) ?: '' ) . '/' . $old_size_file;
                    $new_size_url = $upload_dir['baseurl'] . '/' . dirname( _wp_relative_upload_path( $file_dir . '/' . $new_size_file ) ?: '' ) . '/' . $new_size_file;

                    if ( $old_size_url !== $new_size_url ) {
                        $url_map[ $old_size_url ] = $new_size_url;
                    }
                }
            }

            // Handle original_image (pre-scaled original for large uploads).
            if ( ! empty( $metadata['original_image'] ) ) {
                $orig_file = $metadata['original_image'];
                $orig_old_match = strpos( $orig_file, $old_slug ) === 0;
                $orig_new_match = strpos( $orig_file, $new_slug ) === 0;

                if ( $orig_old_match && $orig_new_match ) {
                    if ( strlen( $old_slug ) >= strlen( $new_slug ) ) {
                        $old_orig = $orig_file;
                        $new_orig = $new_slug . substr( $orig_file, strlen( $old_slug ) );
                    } else {
                        $new_orig = $orig_file;
                        $old_orig = $old_slug . substr( $orig_file, strlen( $new_slug ) );
                    }
                } elseif ( $orig_new_match ) {
                    $old_orig = $old_slug . substr( $orig_file, strlen( $new_slug ) );
                    $new_orig = $orig_file;
                } elseif ( $orig_old_match ) {
                    $old_orig = $orig_file;
                    $new_orig = $new_slug . substr( $orig_file, strlen( $old_slug ) );
                } else {
                    $old_orig = null;
                    $new_orig = null;
                }

                if ( $old_orig && $new_orig && $old_orig !== $new_orig ) {
                    $rel_dir = dirname( _wp_relative_upload_path( $new_file ) ?: '' );
                    $url_map[ $upload_dir['baseurl'] . '/' . $rel_dir . '/' . $old_orig ] =
                        $upload_dir['baseurl'] . '/' . $rel_dir . '/' . $new_orig;
                }
            }

            /**
             * Filter the URL map before processing pagebuilder updates.
             *
             * Allows extensions to modify or add to the URL mappings.
             * Useful for handling CDN URLs, custom image sizes, or additional URL variations.
             *
             * @since 1.0.0
             *
             * @param array    $url_map     URL mappings (old_url => new_url)
             * @param \WP_Post $post_before Attachment post before update
             * @param \WP_Post $post_after  Attachment post after update
             * @return array Modified URL map
             */
            $url_map = apply_filters( 'infyp_filename_url_map', $url_map, $post_before, $post_after );

            INFYP_Logger::log( 'Built URL map with ' . count( $url_map ) . ' mappings', 'INFO' );

        } catch ( \Exception $e ) {
            INFYP_Logger::log( 'Exception building URL map: ' . $e->getMessage(), 'ERROR' );
        }

        return $url_map;
    }

    /**
     * Log the results of a filename update operation.
     *
     * Outputs summary and per-adapter breakdowns to the plugin logger.
     * Only the first 3 errors are logged to avoid flooding the log file.
     *
     * @since 1.0.0
     *
     * @param array $results       Results from INFYP_PageBuilder_Manager::process_url_updates().
     * @param int   $attachment_id The attachment ID that was updated.
     * @param float $start_time    Microtime timestamp when the update began.
     */
    private function log_results( $results, $attachment_id, $start_time ) {
        $duration      = microtime( true ) - $start_time;
        $updated_count = isset( $results['total_updated_posts'] ) ? count( $results['total_updated_posts'] ) : 0;
        $error_count   = isset( $results['total_errors'] ) ? count( $results['total_errors'] ) : 0;

        $log_message = sprintf(
            'Filename update completed - Attachment: %d, Duration: %.2fs, Updated: %d posts, Errors: %d',
            $attachment_id,
            $duration,
            $updated_count,
            $error_count
        );

        if ( $error_count > 0 ) {
            INFYP_Logger::log( $log_message, 'WARNING' );

            $errors_to_log = array_slice( $results['total_errors'], 0, 3 );
            foreach ( $errors_to_log as $error ) {
                INFYP_Logger::log( 'Update error: ' . $error, 'ERROR' );
            }
        } else {
            INFYP_Logger::log( $log_message, 'INFO' );
        }

        if ( ! empty( $results['adapter_results'] ) ) {
            foreach ( $results['adapter_results'] as $adapter => $adapter_result ) {
                $adapter_updated = isset( $adapter_result['updated_posts'] ) ? count( $adapter_result['updated_posts'] ) : 0;
                $adapter_errors  = isset( $adapter_result['errors'] ) ? count( $adapter_result['errors'] ) : 0;

                INFYP_Logger::log( sprintf(
                    '%s adapter: %d posts updated, %d errors',
                    ucfirst( $adapter ),
                    $adapter_updated,
                    $adapter_errors
                ), 'INFO' );
            }
        }
    }

    /**
     * Manually trigger a URL update for an attachment.
     *
     * Processes the provided URL map through all registered page builder
     * adapters, as if the attachment had been renamed through the UI.
     *
     * @since 1.0.0
     *
     * @param int   $attachment_id The attachment ID to update.
     * @param array $url_map       Associative array of old_url => new_url mappings.
     * @return array Results from INFYP_PageBuilder_Manager::process_url_updates().
     */
    public function manual_update( $attachment_id, $url_map ) {
        if ( ! $this->page_builder_manager ) {
            $this->init_manager();
        }

        INFYP_Logger::log( 'Manual filename update triggered for attachment ' . $attachment_id, 'INFO' );

        $options = [
            'validate_data' => true,
            'source'        => 'manual',
        ];

        $results = $this->page_builder_manager->process_url_updates( $url_map, $options );
        $this->log_results( $results, $attachment_id, microtime( true ) );

        return $results;
    }

    /**
     * Get information about all supported page builder adapters.
     *
     * @since 1.0.0
     *
     * @return array Adapter info from INFYP_PageBuilder_Manager::get_adapter_info().
     */
    public function get_supported_builders() {
        if ( ! $this->page_builder_manager ) {
            $this->init_manager();
        }

        return $this->page_builder_manager->get_adapter_info();
    }

    /**
     * Get the list of currently active page builder adapters.
     *
     * @since 1.0.0
     *
     * @return array Active builder identifiers.
     */
    public function get_active_builders() {
        if ( ! $this->page_builder_manager ) {
            $this->init_manager();
        }

        return $this->page_builder_manager->get_active_builders();
    }

    /**
     * Perform a dry-run update to preview which posts would be affected.
     *
     * Creates a synthetic URL map by appending '_old' to the attachment's
     * current slug, then asks the page builder manager to test (not apply)
     * the resulting URL replacements.
     *
     * @since 1.0.0
     *
     * @param int $attachment_id The attachment ID to test.
     * @return array {
     *     @type int   $attachment_id  The tested attachment ID.
     *     @type array $url_mappings   The generated old_url => new_url map.
     *     @type array $would_update   Posts that would be modified.
     *     @type array $active_builders Currently active page builders.
     * }
     */
    public function test_update( $attachment_id ) {
        $post = get_post( $attachment_id );

        if ( ! $post || $post->post_type !== 'attachment' ) {
            return [ 'error' => 'Invalid attachment ID' ];
        }

        $test_before             = clone $post;
        $test_before->post_name  = $post->post_name . '_old';

        $url_map = $this->build_url_map( $test_before, $post );

        if ( ! $this->page_builder_manager ) {
            $this->init_manager();
        }

        $test_results = $this->page_builder_manager->test_updates( $url_map );

        return [
            'attachment_id'  => $attachment_id,
            'url_mappings'   => $url_map,
            'would_update'   => $test_results['would_update'],
            'active_builders' => $this->get_active_builders(),
        ];
    }

    /**
     * Clear the internal URL mapping cache.
     *
     * Hooked to the `shutdown` action to free memory after each request.
     *
     * @since 1.0.0
     */
    public function clear_cache() {
        $this->url_cache = [];
    }
}