<?php
/**
 * Optimization Service - Transport-agnostic generate-and-persist pipeline
 *
 * Owns the full "generate AI data and save it" flow that was previously
 * embedded in the AJAX handlers. Callable from AJAX, REST, WP-CLI, or cron:
 * no method reads $_POST and no method emits JSON — errors are returned as
 * WP_Error and results as arrays.
 *
 * Responsibilities:
 * - Full multi-field optimization (run): generate + sanitize + persist +
 *   fire the extension hooks in the exact order the AJAX path always used.
 * - Single-field optimization (run_field): generate one field and persist
 *   it, including atomic filename renames (generate + rename + content
 *   reference updates in one call).
 * - Field persistence (apply / apply_field): the only save paths for
 *   AI-generated SEO data.
 *
 * @package   IPAIS\Core
 * @since 1.0.6
 * @author    InfyPress
 * @license   GPL-2.0-or-later
 */

namespace IPAIS\Core;

// Exit if accessed directly (placed after namespace per PHP requirement).
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use \IPAIS\Core\INFYP_File_Renamer;
use \IPAIS\Core\INFYP_AI_Optimizer;
use \IPAIS\Core\INFYP_Content_Updater;
use \IPAIS\Database\INFYP_Changelog;
use \IPAIS\Core\INFYP_Security_Manager;
use \IPAIS\Core\INFYP_Cache_Manager;
use \IPAIS\Core\INFYP_Memory_Manager;
use \IPAIS\Core\INFYP_Redirect_Manager;

/**
 * Optimization Service Class
 *
 * Singleton service encapsulating the generate-and-persist optimization
 * pipeline shared by the AJAX handlers and any programmatic caller.
 *
 * @package IPAIS\Core
 * @since 1.0.6
 */
class INFYP_Optimization_Service {

    /**
     * Singleton instance
     *
     * @since 1.0.6
     * @var   INFYP_Optimization_Service|null
     */
    private static $instance = null;

    /**
     * Cache manager instance for cache invalidation
     *
     * @since 1.0.6
     * @var   INFYP_Cache_Manager
     */
    private $cache;

    /**
     * Security manager instance for input validation
     *
     * @since 1.0.6
     * @var   INFYP_Security_Manager
     */
    private $security;

    /**
     * Get the singleton instance.
     *
     * @since 1.0.6
     *
     * @return INFYP_Optimization_Service
     */
    public static function get_instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor. Private — use get_instance().
     *
     * @since 1.0.6
     */
    private function __construct() {
        $this->cache    = INFYP_Cache_Manager::get_instance();
        $this->security = INFYP_Security_Manager::get_instance();
    }

    /**
     * Run a full multi-field optimization (generate + persist).
     *
     * Mirrors the historical infyp_optimize_with_ai orchestration exactly,
     * firing every extension hook in the same order:
     *
     *  1. infyp_before_image_optimization (action)
     *  2. memory check
     *  3. clear_keywords handling
     *  4. old-data snapshot
     *  5. existing-keywords read
     *  6. INFYP_AI_Optimizer::generate_seo_data()
     *  7. error guard
     *  8. INFYP_AI_Optimizer::record_optimization()
     *  9. process_result()
     * 10. apply()
     * 11. infyp_after_ai_optimization (filter)
     * 12. cache invalidation
     * 13. infyp_image_optimized (action)
     *
     * When $args['dry_run'] is true, steps 8 and 10-13 are skipped: the
     * generated values are returned without persisting anything. Note that
     * extension fields added on the infyp_after_ai_optimization hook are
     * absent from dry-run results, because extensions currently persist
     * inside that hook (it cannot be fired safely without saving).
     *
     * @since 1.0.6
     *
     * @param int   $image_id The attachment ID to optimize.
     * @param array $args     {
     *     Optional arguments.
     *
     *     @type string $custom_prompt  Per-image prompt override. Replaces the
     *                                  global prompt addon and the
     *                                  infyp_image_context / infyp_prompt_addon
     *                                  filter output entirely. Default ''.
     *     @type bool   $clear_keywords Delete stored keywords before optimizing
     *                                  so the AI generates fresh ones. Default false.
     *     @type bool   $dry_run        Generate without persisting. Default false.
     *     @type array  $options        Arbitrary caller options passed through to
     *                                  callbacks on the infyp_optimize_prompt filter.
     *                                  Ignored by this class. Default [].
     * }
     * @return array|\WP_Error {
     *     Result envelope, or WP_Error on failure.
     *
     *     @type array  $new_data        The generated (and, unless dry_run, applied) field data.
     *     @type string $model           The AI model used.
     *     @type bool   $dry_run         Whether persistence was skipped.
     *     @type bool   $prompt_modified Whether a callback changed the prompt override.
     * }
     */
    public function run( $image_id, array $args = [] ) {
        $image_id       = (int) $image_id;
        $custom_prompt  = isset( $args['custom_prompt'] ) ? (string) $args['custom_prompt'] : '';
        $clear_keywords = ! empty( $args['clear_keywords'] );
        $dry_run        = ! empty( $args['dry_run'] );

        // Let extensions augment the prompt for this request.
        $base_prompt   = $custom_prompt;
        $custom_prompt = $this->build_prompt( $image_id, $custom_prompt, $args );

        if ( ! $this->security->validate_attachment_id( $image_id ) ) {
            return new \WP_Error( 'infyp_invalid_image', esc_html__( 'Invalid image ID.', 'infy-ai-image-seo-optimizer' ) );
        }

        /** This action is documented in includes/Ajax/INFYP_AI_Ajax.php */
        do_action( 'infyp_before_image_optimization', $image_id );

        // Check memory before processing
        if ( ! INFYP_Memory_Manager::has_sufficient_memory( 50 ) ) {
            return new \WP_Error( 'infyp_insufficient_memory', esc_html__( 'Insufficient memory for image optimization. Please try again later.', 'infy-ai-image-seo-optimizer' ) );
        }

        $settings   = get_option( 'infyp_optimization_settings', [] );
        $model_name = $settings['ai_model'] ?? INFYP_DEFAULT_MODEL;

        // Check if we should clear keywords before optimization (for bulk re-optimization)
        if ( $clear_keywords && ! $dry_run ) {
            delete_post_meta( $image_id, '_infyp_keywords' );
            delete_post_meta( $image_id, '_infyp_keywords_manual' );
        }

        // Get current data
        $old_data = [
            'alt_text' => get_post_meta( $image_id, '_wp_attachment_image_alt', true ),
            'title'    => get_the_title( $image_id ),
            'filename' => pathinfo( get_attached_file( $image_id ), PATHINFO_FILENAME ),
        ];

        // Check for existing keywords (after potential clearing)
        $existing_keywords = get_post_meta( $image_id, '_infyp_keywords', true );
        if ( $clear_keywords && $dry_run ) {
            // A dry run must not delete anything, but should behave as if it had.
            $existing_keywords = null;
        }

        // Generate SEO data - pass existing keywords if available
        if ( ! empty( $existing_keywords ) && is_array( $existing_keywords ) ) {
            $result = INFYP_AI_Optimizer::generate_seo_data( $image_id, $existing_keywords, $custom_prompt );
        } else {
            $result = INFYP_AI_Optimizer::generate_seo_data( $image_id, null, $custom_prompt );
        }

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        if ( $dry_run ) {
            // Sanitize/shape only; skip record_optimization, apply, extension
            // persistence hooks, and cache invalidation.
            $new_data = $this->process_result( $result, $old_data, $image_id );

            return [
                'new_data'        => $new_data,
                'model'           => $model_name,
                'dry_run'         => true,
                'prompt_modified' => $custom_prompt !== $base_prompt,
            ];
        }

        // Record optimization
        INFYP_AI_Optimizer::record_optimization( $image_id );

        // Process new data
        $new_data = $this->process_result( $result, $old_data, $image_id );

        // Apply optimization
        $this->apply( $image_id, $old_data, $new_data, $model_name );

        /** This filter is documented in includes/Ajax/INFYP_AI_Ajax.php */
        $new_data = apply_filters( 'infyp_after_ai_optimization', $new_data, $image_id, $model_name );
        if ( ! is_array( $new_data ) ) {
            $new_data = [];
        }

        // Clear caches
        $this->cache->invalidate_image_cache( $image_id );
        clean_attachment_cache( $image_id );

        /** This action is documented in includes/Ajax/INFYP_AI_Ajax.php */
        do_action( 'infyp_image_optimized', $image_id, $new_data, $model_name );

        return [
            'new_data'        => $new_data,
            'model'           => $model_name,
            'dry_run'         => false,
            'prompt_modified' => $custom_prompt !== $base_prompt,
        ];
    }

    /**
     * Build the effective prompt override for a generation request.
     *
     * Gives callbacks a single place to augment or replace the prompt for one
     * request — for example to prepend descriptive material, brand rules or
     * taxonomy hints derived from the image or from caller-supplied options.
     *
     * The caller's own $options array is passed through untouched, so
     * callbacks can define and read whatever keys they document, without
     * this class needing to know about them.
     *
     * @since 1.0.6
     *
     * @param int    $image_id      The attachment ID.
     * @param string $custom_prompt Caller-supplied prompt override ('' when none).
     * @param array  $args          The full optimization arguments, including
     *                              'dry_run' and the caller's 'options'.
     * @return string The effective prompt override.
     */
    protected function build_prompt( $image_id, $custom_prompt, array $args ) {
        /**
         * Filter the prompt override used for a single generation request.
         *
         * The returned string replaces the global prompt addon and the
         * infyp_image_context filter output for this request, exactly as a
         * caller-supplied custom prompt does. Return the value unchanged to
         * opt out.
         *
         * Callbacks should treat a truthy $args['dry_run'] as a signal not to
         * persist anything they generate while building the prompt.
         *
         * @since 1.0.6
         *
         * @param string $custom_prompt The current prompt override.
         * @param int    $image_id      The attachment ID.
         * @param array  $args          Optimization arguments, including the
         *                              caller's arbitrary 'options' array.
         */
        $prompt = apply_filters( 'infyp_optimize_prompt', $custom_prompt, $image_id, $args );

        return is_string( $prompt ) ? $prompt : $custom_prompt;
    }

    /**
     * Process raw AI optimization result into sanitized field data.
     *
     * Sanitizes AI-generated values, processes keywords, and ensures
     * filename uniqueness before applying changes.
     *
     * (Moved verbatim from INFYP_AI_Ajax::process_ai_result().)
     *
     * @since 1.0.6
     *
     * @param array $result   Raw AI result containing alt_text, title, filename, and keywords.
     * @param array $old_data Current image data for comparison.
     * @param int   $image_id The attachment ID being optimized.
     * @return array Sanitized and processed field data ready to apply.
     */
    public function process_result( array $result, array $old_data, $image_id ) {
        $new_data = [
            'alt_text' => isset( $result['alt_text'] ) ? sanitize_textarea_field( $result['alt_text'] ) : '',
            'title'    => isset( $result['title'] ) ? sanitize_text_field( $result['title'] ) : '',
            'filename' => isset( $result['filename'] ) ? INFYP_File_Renamer::strip_file_extension( sanitize_file_name( $result['filename'] ) ) : '',
        ];

        /**
         * Filter processed AI result data before applying optimizations.
         * Allows extensions to add additional fields (e.g., caption, description).
         *
         * @since 1.0.0
         *
         * @param array $new_data  Processed field data.
         * @param array $result    Raw AI result.
         * @param int   $image_id  Image attachment ID.
         */
        $new_data = apply_filters( 'infyp_process_ai_result_data', $new_data, $result, $image_id );

        // Process keywords if provided
        if ( isset( $result['keywords'] ) && is_array( $result['keywords'] ) ) {
            /** This filter is documented in includes/Ajax/INFYP_AI_Ajax.php */
            $max_keywords = apply_filters( 'infyp_max_keywords', 3 );

            $keywords = array_map( 'sanitize_text_field', $result['keywords'] );
            $keywords = array_map( 'trim', $keywords ); // Trim whitespace
            $keywords = array_filter( $keywords, function( $keyword ) {
                return ! empty( $keyword ); // Remove empty values while preserving UTF-8
            } );
            $keywords = array_slice( $keywords, 0, $max_keywords ); // Limit keywords
            $keywords = array_values( $keywords ); // Re-index array after filtering

            if ( count( $keywords ) >= 2 ) { // Min 2 keywords
                $new_data['keywords'] = $keywords;
            }
        }

        // Ensure filename uniqueness.
        // Strip extension again in case an extension hook added one via infyp_process_ai_result_data.
        if ( ! empty( $new_data['filename'] ) && $old_data['filename'] !== $new_data['filename'] ) {
            $clean_stem = INFYP_File_Renamer::strip_file_extension( $new_data['filename'] );
            $file_path  = get_attached_file( $image_id );
            if ( $file_path ) {
                $directory       = dirname( $file_path );
                $extension       = pathinfo( $file_path, PATHINFO_EXTENSION );
                $unique_filename = wp_unique_filename( $directory, $clean_stem . '.' . $extension );
                $new_data['filename'] = pathinfo( $unique_filename, PATHINFO_FILENAME );
            }
        }

        return $new_data;
    }

    /**
     * Apply AI optimization results to the image attachment.
     *
     * Updates alt text, title, filename, and keywords as needed. Logs
     * changes to the changelog and updates content references.
     *
     * (Moved verbatim from INFYP_AI_Ajax::apply_ai_optimization().)
     *
     * @since 1.0.6
     *
     * @param int    $image_id   The attachment ID.
     * @param array  $old_data   Previous values for alt_text, title, and filename.
     * @param array  $new_data   New AI-generated values to apply.
     * @param string $model_name The AI model name used for the optimization.
     * @return void
     */
    public function apply( $image_id, array $old_data, array $new_data, $model_name ) {
        // Update alt text
        if ( $old_data['alt_text'] !== $new_data['alt_text'] ) {
            update_post_meta( $image_id, '_wp_attachment_image_alt', $new_data['alt_text'] );
            INFYP_Changelog::add_entry( $image_id, 'Alt Text (AI)', $old_data['alt_text'], $new_data['alt_text'], $model_name );
        }

        // Update title
        if ( $old_data['title'] !== $new_data['title'] ) {
            wp_update_post( [ 'ID' => $image_id, 'post_title' => $new_data['title'] ] );
            INFYP_Changelog::add_entry( $image_id, 'Title (AI)', $old_data['title'], $new_data['title'], $model_name );
        }

        // Update filename if needed
        if ( ! empty( $new_data['filename'] ) && $old_data['filename'] !== $new_data['filename'] ) {
            /**
             * Filter the filename before applying the rename.
             *
             * @since 1.0.0
             * @param string $filename  The proposed filename (without extension).
             * @param int    $image_id  The attachment ID.
             * @param string $old_name  The current filename.
             */
            $new_data['filename'] = apply_filters( 'infyp_filename_before_apply', $new_data['filename'], $image_id, $old_data['filename'] );

            // Capture old URL BEFORE rename — after rename, wp_get_attachment_url returns the new URL.
            $old_url = wp_get_attachment_url( $image_id );

            // Label redirects created by this rename as AI-driven, whichever
            // entry point (AJAX, REST, CLI, cron) called the service.
            $previous_source = INFYP_Redirect_Manager::set_rename_source( 'ai' );
            try {
                $renamed = INFYP_File_Renamer::rename_file( $image_id, $new_data['filename'] );
            } finally {
                INFYP_Redirect_Manager::set_rename_source( $previous_source );
            }

            // rename_file() returns WP_Error (truthy!) when the caller lacks
            // upload_files — a non-issue on the capability-checked AJAX path,
            // but real for CLI/REST/cron callers of this service.
            if ( $renamed && ! is_wp_error( $renamed ) ) {
                $new_url = wp_get_attachment_url( $image_id );

                // Update content references (stem-based replacement handles all size variants)
                $content_updater = new INFYP_Content_Updater( $old_url, $new_url );
                $updated_posts   = $content_updater->run_update();

                $context = count( $updated_posts ) > 0
                    ? sprintf(
                        /* translators: %d: Number of posts */
                        esc_html__( 'Updated in %d posts', 'infy-ai-image-seo-optimizer' ),
                        count( $updated_posts )
                    )
                    : null;

                INFYP_Changelog::add_entry( $image_id, 'Filename (AI)', $old_data['filename'], $new_data['filename'], $model_name, $context );
            }
        }

        // Save keywords if provided by AI and not manually set by user
        $existing_keywords = get_post_meta( $image_id, '_infyp_keywords', true );
        $keywords_manual   = get_post_meta( $image_id, '_infyp_keywords_manual', true );

        // Update keywords if they were generated/returned by AI AND not manually set by user
        if ( ! empty( $new_data['keywords'] ) && is_array( $new_data['keywords'] ) && ! $keywords_manual ) {
            // Check if keywords actually changed for changelog
            $keywords_changed = $existing_keywords !== $new_data['keywords'];

            // Save AI-generated keywords (whether new or re-optimized)
            update_post_meta( $image_id, '_infyp_keywords', $new_data['keywords'] );
            update_post_meta( $image_id, '_infyp_keywords_manual', false );

            // Add changelog entry if keywords changed
            if ( $keywords_changed ) {
                $old_keywords_str = is_array( $existing_keywords ) ? implode( ', ', $existing_keywords ) : '';
                $new_keywords_str = implode( ', ', $new_data['keywords'] );
                INFYP_Changelog::add_entry( $image_id, 'Keywords (AI)', $old_keywords_str, $new_keywords_str, $model_name );
            }

            // Mark as optimized
            update_post_meta( $image_id, '_infyp_has_been_optimized', true );
        } else {
            // Mark as optimized even if keywords weren't updated (e.g., manually set)
            update_post_meta( $image_id, '_infyp_has_been_optimized', true );
        }
    }

    /**
     * Optimize a single field (generate + persist).
     *
     * Unlike the historical AJAX path, filename optimization is atomic by
     * default: the file is renamed and content references are rewritten in
     * the same call. Pass 'skip_filename_apply' => true to only generate a
     * proposed stem (the legacy admin-modal behavior, where the rename is
     * confirmed by the user via a separate call).
     *
     * @since 1.0.6
     *
     * @param int    $image_id   The attachment ID.
     * @param string $field_type Field to optimize. One of the types allowed by
     *                           the infyp_allowed_optimize_field_types filter.
     * @param array  $args       {
     *     Optional arguments.
     *
     *     @type array|null $keywords            Keyword context. Falls back to stored keywords.
     *     @type string     $custom_prompt       Per-image prompt override. Default ''.
     *     @type bool       $dry_run             Generate without persisting any field. Default false.
     *     @type bool       $skip_filename_apply Generate the filename stem without renaming. Default false.
     *     @type array      $options             Arbitrary caller options passed through to
     *                                           callbacks on the infyp_optimize_prompt
     *                                           filter. Default [].
     * }
     * @return array|\WP_Error {
     *     Result array, or WP_Error on failure.
     *
     *     @type string $field_type    The optimized field.
     *     @type string $old_value     Value before optimization.
     *     @type string $new_value     The generated (and, unless skipped, applied) value.
     *     @type mixed  $keywords_used Keyword context used for generation.
     *     @type bool   $applied       Whether the value was persisted.
     *     @type int    $updated_posts   Number of posts rewritten (filename renames only).
     *     @type bool   $prompt_modified Whether a callback changed the prompt override.
     * }
     */
    public function run_field( $image_id, $field_type, array $args = [] ) {
        $image_id      = (int) $image_id;
        $field_type    = (string) $field_type;
        $custom_prompt = isset( $args['custom_prompt'] ) ? (string) $args['custom_prompt'] : '';
        $dry_run       = ! empty( $args['dry_run'] );

        // Let extensions augment the prompt for this request.
        $base_prompt   = $custom_prompt;
        $custom_prompt = $this->build_prompt( $image_id, $custom_prompt, $args );

        // Validate field type - extensible via filter for add-ons
        /** This filter is documented in includes/Ajax/INFYP_AI_Ajax.php */
        $allowed_field_types = apply_filters( 'infyp_allowed_optimize_field_types', [ 'filename', 'alt_text', 'title' ] );
        if ( ! in_array( $field_type, $allowed_field_types, true ) ) {
            return new \WP_Error( 'infyp_invalid_field_type', esc_html__( 'Invalid field type specified.', 'infy-ai-image-seo-optimizer' ) );
        }

        if ( ! $this->security->validate_attachment_id( $image_id ) ) {
            return new \WP_Error( 'infyp_invalid_image', esc_html__( 'Invalid image ID.', 'infy-ai-image-seo-optimizer' ) );
        }

        // Check memory before processing
        if ( ! INFYP_Memory_Manager::has_sufficient_memory( 30 ) ) {
            return new \WP_Error( 'infyp_insufficient_memory', esc_html__( 'Insufficient memory for optimization. Please try again later.', 'infy-ai-image-seo-optimizer' ) );
        }

        // Keyword context: explicit argument first, then stored keywords.
        if ( isset( $args['keywords'] ) && is_array( $args['keywords'] ) && ! empty( $args['keywords'] ) ) {
            $existing_keywords = array_map( 'sanitize_text_field', $args['keywords'] );
        } else {
            $existing_keywords = get_post_meta( $image_id, '_infyp_keywords', true );
        }

        $settings   = get_option( 'infyp_optimization_settings', [] );
        $model_name = $settings['ai_model'] ?? INFYP_DEFAULT_MODEL;

        // Get current value for the field
        $current_value = $this->get_current_field_value( $image_id, $field_type );

        /** This filter is documented in includes/Ajax/INFYP_AI_Ajax.php */
        $extension_result = apply_filters( 'infyp_optimize_field_result', null, $image_id, $field_type, $existing_keywords, $custom_prompt );

        // Optimize the specific field
        try {
            if ( $extension_result !== null ) {
                $result = $extension_result;
            } else {
                $result = INFYP_AI_Optimizer::optimize_single_field( $image_id, $field_type, $existing_keywords, $custom_prompt );
            }
        } catch ( \Exception $e ) {
            return new \WP_Error(
                'infyp_optimization_error',
                sprintf(
                    /* translators: %s: Error message */
                    esc_html__( 'Optimization error: %s', 'infy-ai-image-seo-optimizer' ),
                    esc_html( $e->getMessage() )
                )
            );
        }

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        // For the core fields the AI must return the matching key.
        if ( in_array( $field_type, [ 'filename', 'alt_text', 'title' ], true ) && ! isset( $result[ $field_type ] ) ) {
            return new \WP_Error(
                'infyp_missing_result_field',
                sprintf(
                    /* translators: %s: field name */
                    esc_html__( 'The API response did not contain the %s field.', 'infy-ai-image-seo-optimizer' ),
                    $field_type
                )
            );
        }

        $new_value      = '';
        $changelog_data = [];
        $applied        = false;
        $updated_posts  = 0;

        switch ( $field_type ) {
            case 'filename':
                // Strip any image extension the AI may have included — we only use stems.
                $new_value = INFYP_File_Renamer::strip_file_extension( $result['filename'] );

                if ( ! $dry_run && empty( $args['skip_filename_apply'] ) ) {
                    // Atomic rename: persist the new filename immediately.
                    $apply_result = $this->apply_field( $image_id, 'filename', $new_value, [
                        'label' => 'filename',
                        'model' => $model_name,
                        'log_context' => 'ai',
                    ] );

                    if ( is_wp_error( $apply_result ) ) {
                        return $apply_result;
                    }

                    $new_value     = $apply_result['new_value'];
                    $applied       = empty( $apply_result['unchanged'] );
                    $updated_posts = $apply_result['updated_posts'];
                }
                break;

            case 'alt_text':
                $new_value = $result['alt_text'];
                if ( ! $dry_run && $new_value && $new_value !== $current_value ) {
                    update_post_meta( $image_id, '_wp_attachment_image_alt', $new_value );
                    $applied        = true;
                    $changelog_data = [
                        'field'     => 'alt_text',
                        'old_value' => $current_value,
                        'new_value' => $new_value,
                    ];
                }
                break;

            case 'title':
                $new_value = $result['title'];
                if ( ! $dry_run && $new_value && $new_value !== $current_value ) {
                    wp_update_post( [
                        'ID'         => $image_id,
                        'post_title' => $new_value,
                    ] );
                    $applied        = true;
                    $changelog_data = [
                        'field'     => 'title',
                        'old_value' => $current_value,
                        'new_value' => $new_value,
                    ];
                }
                break;

            default:
                if ( ! $dry_run ) {
                    /** This filter is documented in includes/Ajax/INFYP_AI_Ajax.php */
                    $extended_save = apply_filters( 'infyp_save_extended_field_result', null, $image_id, $field_type, $result, $current_value );
                    if ( is_array( $extended_save ) ) {
                        $new_value      = $extended_save['new_value'] ?? '';
                        $changelog_data = $extended_save['changelog_data'] ?? [];
                        $applied        = true;
                    }
                } elseif ( isset( $result[ $field_type ] ) ) {
                    $new_value = $result[ $field_type ];
                }
                break;
        }

        // Log the change if applicable
        if ( ! empty( $changelog_data ) ) {
            INFYP_Changelog::add_entry(
                $image_id,
                $changelog_data['field'],
                $changelog_data['old_value'],
                $changelog_data['new_value'],
                $model_name,
                'ai'
            );
        }

        if ( ! $dry_run ) {
            // Clear caches
            $this->cache->invalidate_image_cache( $image_id );
            clean_attachment_cache( $image_id );
        }

        return [
            'field_type'    => $field_type,
            'old_value'     => $current_value,
            'new_value'     => $new_value,
            'keywords_used' => $existing_keywords,
            'applied'       => $applied,
            'updated_posts' => $updated_posts,
            'model'           => $model_name,
            'dry_run'         => $dry_run,
            'prompt_modified' => $custom_prompt !== $base_prompt,
        ];
    }

    /**
     * Persist a single field value.
     *
     * The only shared save path for individual fields. For 'filename' this
     * performs the real rename: physical file, attachment metadata, content
     * reference rewriting, and the changelog entry (previously duplicated
     * inside the infyp_update_filename AJAX handler).
     *
     * @since 1.0.6
     *
     * @param int    $image_id   The attachment ID.
     * @param string $field_type One of 'alt_text', 'title', 'filename', or an
     *                           extension field type.
     * @param mixed  $value      The value to persist. For extension fields this
     *                           is the raw AI result array the extension's save
     *                           filter expects.
     * @param array  $context    {
     *     Optional persistence context.
     *
     *     @type string      $label       Changelog field label. Defaults to $field_type
     *                                    ('Filename' capitalized label is used by the
     *                                    manual rename path).
     *     @type string|null $model       AI model name for the changelog. Default null.
     *     @type string|null $log_context Changelog context/source column. Default null.
     *     @type string|null $source      Redirect source label for a filename
     *                                    change: 'ai' or 'manual'. Default: 'ai'
     *                                    when $log_context is 'ai', 'manual' for
     *                                    any other $log_context, otherwise
     *                                    unchanged (since 1.0.9).
     * }
     * @return array|\WP_Error {
     *     Result array, or WP_Error on failure.
     *
     *     @type string $new_value     The persisted value.
     *     @type string $old_value     The previous value.
     *     @type bool   $unchanged     True when the value matched and nothing was written.
     *     @type int    $updated_posts Posts rewritten (filename only).
     * }
     */
    public function apply_field( $image_id, $field_type, $value, array $context = [] ) {
        $image_id    = (int) $image_id;
        $label       = isset( $context['label'] ) ? $context['label'] : $field_type;
        $model       = isset( $context['model'] ) ? $context['model'] : null;
        $log_context = isset( $context['log_context'] ) ? $context['log_context'] : null;

        if ( ! $this->security->validate_attachment_id( $image_id ) ) {
            return new \WP_Error( 'infyp_invalid_image', esc_html__( 'Invalid image ID.', 'infy-ai-image-seo-optimizer' ) );
        }

        switch ( $field_type ) {
            case 'filename':
                $new_stem = INFYP_File_Renamer::strip_file_extension( sanitize_file_name( (string) $value ) );

                if ( ! $this->security->validate_filename( $new_stem ) ) {
                    return new \WP_Error( 'infyp_invalid_filename', esc_html__( 'Invalid filename provided.', 'infy-ai-image-seo-optimizer' ) );
                }

                $old_stem = pathinfo( get_attached_file( $image_id ), PATHINFO_FILENAME );

                if ( $new_stem === $old_stem ) {
                    return [
                        'new_value'     => $new_stem,
                        'old_value'     => $old_stem,
                        'unchanged'     => true,
                        'updated_posts' => 0,
                    ];
                }

                // Capture old URL BEFORE rename — after rename, wp_get_attachment_url returns the new URL.
                $old_url = wp_get_attachment_url( $image_id );

                // Label the redirect only when the caller states the source
                // (context 'source', or log_context); otherwise keep whatever
                // an outer caller set, or the redirect manager's fallback.
                $rename_source = null;
                if ( isset( $context['source'] ) && in_array( $context['source'], [ 'ai', 'manual' ], true ) ) {
                    $rename_source = $context['source'];
                } elseif ( null !== $log_context ) {
                    $rename_source = 'ai' === $log_context ? 'ai' : 'manual';
                }

                $previous_source = null !== $rename_source ? INFYP_Redirect_Manager::set_rename_source( $rename_source ) : null;
                try {
                    $success = INFYP_File_Renamer::rename_file( $image_id, $new_stem );
                } finally {
                    if ( null !== $rename_source ) {
                        INFYP_Redirect_Manager::set_rename_source( $previous_source );
                    }
                }

                if ( is_wp_error( $success ) ) {
                    return $success;
                }
                if ( ! $success ) {
                    return new \WP_Error( 'infyp_rename_failed', esc_html__( 'Failed to rename file. It may already exist.', 'infy-ai-image-seo-optimizer' ) );
                }

                $new_url = wp_get_attachment_url( $image_id );

                // Update content if URLs changed
                $updated_count = 0;
                if ( $old_url && $new_url && $old_url !== $new_url ) {
                    $content_updater  = new INFYP_Content_Updater( $old_url, $new_url );
                    $updated_post_ids = $content_updater->run_update();
                    $updated_count    = count( $updated_post_ids );
                }

                // Log the change
                INFYP_Changelog::add_entry(
                    $image_id,
                    $label === $field_type ? esc_html__( 'Filename', 'infy-ai-image-seo-optimizer' ) : $label,
                    $old_stem,
                    $new_stem,
                    $model,
                    $updated_count > 0 ? sprintf(
                        /* translators: %d: Number of posts */
                        esc_html__( 'Updated in %d posts', 'infy-ai-image-seo-optimizer' ),
                        $updated_count
                    ) : $log_context
                );

                // Clear caches
                $this->cache->invalidate_image_cache( $image_id );
                clean_attachment_cache( $image_id );

                return [
                    'new_value'     => $new_stem,
                    'old_value'     => $old_stem,
                    'unchanged'     => false,
                    'updated_posts' => $updated_count,
                ];

            case 'alt_text':
                $old_value = get_post_meta( $image_id, '_wp_attachment_image_alt', true );
                $new_value = sanitize_text_field( (string) $value );

                if ( $new_value === $old_value ) {
                    return [ 'new_value' => $new_value, 'old_value' => $old_value, 'unchanged' => true, 'updated_posts' => 0 ];
                }

                update_post_meta( $image_id, '_wp_attachment_image_alt', $new_value );
                INFYP_Changelog::add_entry( $image_id, $label, $old_value, $new_value, $model, $log_context );

                $this->cache->invalidate_image_cache( $image_id );
                clean_attachment_cache( $image_id );

                return [ 'new_value' => $new_value, 'old_value' => $old_value, 'unchanged' => false, 'updated_posts' => 0 ];

            case 'title':
                $old_value = get_the_title( $image_id );
                $new_value = sanitize_text_field( (string) $value );

                if ( $new_value === $old_value ) {
                    return [ 'new_value' => $new_value, 'old_value' => $old_value, 'unchanged' => true, 'updated_posts' => 0 ];
                }

                wp_update_post( [ 'ID' => $image_id, 'post_title' => $new_value ] );
                INFYP_Changelog::add_entry( $image_id, $label, $old_value, $new_value, $model, $log_context );

                $this->cache->invalidate_image_cache( $image_id );
                clean_attachment_cache( $image_id );

                return [ 'new_value' => $new_value, 'old_value' => $old_value, 'unchanged' => false, 'updated_posts' => 0 ];

            default:
                $current_value = $this->get_current_field_value( $image_id, $field_type );

                /** This filter is documented in includes/Ajax/INFYP_AI_Ajax.php */
                $extended_save = apply_filters( 'infyp_save_extended_field_result', null, $image_id, $field_type, (array) $value, $current_value );

                if ( ! is_array( $extended_save ) ) {
                    return new \WP_Error( 'infyp_unhandled_field', esc_html__( 'Invalid field type specified.', 'infy-ai-image-seo-optimizer' ) );
                }

                $new_value      = $extended_save['new_value'] ?? '';
                $changelog_data = $extended_save['changelog_data'] ?? [];

                if ( ! empty( $changelog_data ) ) {
                    INFYP_Changelog::add_entry(
                        $image_id,
                        $changelog_data['field'],
                        $changelog_data['old_value'],
                        $changelog_data['new_value'],
                        $model,
                        $log_context
                    );
                }

                $this->cache->invalidate_image_cache( $image_id );
                clean_attachment_cache( $image_id );

                return [ 'new_value' => $new_value, 'old_value' => $current_value, 'unchanged' => false, 'updated_posts' => 0 ];
        }
    }

    /**
     * Read the current value of an optimizable field.
     *
     * @since 1.0.6
     *
     * @param int    $image_id   The attachment ID.
     * @param string $field_type The field type.
     * @return string Current value ('' for unknown/extension fields).
     */
    private function get_current_field_value( $image_id, $field_type ) {
        switch ( $field_type ) {
            case 'filename':
                return pathinfo( get_attached_file( $image_id ), PATHINFO_FILENAME );
            case 'alt_text':
                return get_post_meta( $image_id, '_wp_attachment_image_alt', true );
            case 'title':
                return get_the_title( $image_id );
        }
        return '';
    }
}
