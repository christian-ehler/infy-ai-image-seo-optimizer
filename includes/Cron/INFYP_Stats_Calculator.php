<?php
/**
 * Background Statistics Calculator
 *
 * Pre-computes dashboard statistics via WP-Cron to avoid
 * heavy calculations on page load. Statistics are cached
 * and updated hourly in the background.
 *
 * @package   IPAIS\Cron
 * @since 1.0.0
 */

namespace IPAIS\Cron;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use IPAIS\Core\INFYP_Field_Validator;
use IPAIS\Core\INFYP_SEO_Score_Calculator;

/**
 * Class INFYP_Stats_Calculator
 *
 * Handles background calculation of image statistics for the dashboard.
 * Runs via WP-Cron to avoid performance issues on page load.
 */
class INFYP_Stats_Calculator {

    /**
     * Option key for storing cached statistics
     */
    const STATS_OPTION_KEY = 'infyp_cached_dashboard_stats';

    /**
     * Cron hook name
     */
    const CRON_HOOK = 'infyp_calculate_stats';

    /**
     * Singleton instance
     * @var INFYP_Stats_Calculator|null
     */
    private static $instance = null;

    /**
     * Get singleton instance
     *
     * @return INFYP_Stats_Calculator
     */
    public static function get_instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor - registers cron hook
     */
    private function __construct() {
        add_action( self::CRON_HOOK, [ $this, 'calculate_and_cache_stats' ] );
    }

    /**
     * Schedule the cron job if not already scheduled
     *
     * @return void
     */
    public static function schedule_cron() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), 'hourly', self::CRON_HOOK );
        }
    }

    /**
     * Unschedule the cron job (for plugin deactivation)
     *
     * @return void
     */
    public static function unschedule_cron() {
        $timestamp = wp_next_scheduled( self::CRON_HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_HOOK );
        }
    }

    /**
     * Get cached statistics
     *
     * Returns pre-computed statistics from the database.
     * If no cached stats exist, triggers a calculation.
     *
     * @param bool $force_recalc Whether to force recalculation
     * @return array Statistics array
     */
    public static function get_stats( $force_recalc = false ) {
        $stats = get_option( self::STATS_OPTION_KEY, [] );

        // Stats cached before a key was introduced would render as zeroes
        // until the hourly cron next runs, so treat a missing key as a cache
        // miss and recalculate immediately.
        if ( ! empty( $stats ) && ! array_key_exists( 'optimized_images', $stats ) ) {
            $force_recalc = true;
        }

        // If no cached stats or force recalc, calculate now
        if ( empty( $stats ) || $force_recalc ) {
            $instance = self::get_instance();
            $stats = $instance->calculate_stats();
            update_option( self::STATS_OPTION_KEY, $stats, false );
        }

        return $stats;
    }

    /**
     * Calculate and cache statistics (cron callback)
     *
     * This method is called by WP-Cron hourly.
     *
     * @return void
     */
    public function calculate_and_cache_stats() {
        $stats = $this->calculate_stats();
        update_option( self::STATS_OPTION_KEY, $stats, false );
    }

    /**
     * Calculate image statistics
     *
     * Performs the actual calculation of statistics.
     * Uses efficient SQL queries where possible.
     *
     * @return array Statistics array
     */
    public function calculate_stats() {
        global $wpdb;

        // Get total image count (efficient single query)
        // Excludes SVG images as they're typically icons/decorative and don't need SEO optimization
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $total_images = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type = 'attachment'
             AND post_mime_type LIKE 'image/%'
             AND post_mime_type NOT LIKE 'image/svg%'
             AND post_status = 'inherit'"
        );

        // If no images, return empty stats
        if ( $total_images === 0 ) {
            return [
                'total_images'          => 0,
                'optimized_images'      => 0,
                'optimized_percentage'  => 0,
                'needs_filename_opt'    => 0,
                'needs_alt_opt'         => 0,
                'needs_title_opt'       => 0,
                'filename_percentage'   => 0,
                'alt_percentage'        => 0,
                'title_percentage'      => 0,
                'overall_percentage'    => 0,
                'last_updated'          => time(),
            ];
        }

        // Count images that have actually been through an AI optimization.
        // This is coverage — how many images were processed — and is distinct
        // from overall_percentage below, which measures how well they scored.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $optimized_images = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_infyp_has_been_optimized'
             WHERE p.post_type = 'attachment'
             AND p.post_mime_type LIKE 'image/%'
             AND p.post_mime_type NOT LIKE 'image/svg%'
             AND p.post_status = 'inherit'
             AND pm.meta_value = '1'"
        );

        // Get count of images missing alt text (efficient SQL)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $missing_alt = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wp_attachment_image_alt'
             WHERE p.post_type = 'attachment'
             AND p.post_mime_type LIKE 'image/%'
             AND p.post_mime_type NOT LIKE 'image/svg%'
             AND p.post_status = 'inherit'
             AND (pm.meta_value IS NULL OR pm.meta_value = '')"
        );

        // Get count of images with poor titles (contains common bad patterns)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $poor_titles = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type = 'attachment'
             AND post_mime_type LIKE 'image/%'
             AND post_mime_type NOT LIKE 'image/svg%'
             AND post_status = 'inherit'
             AND (
                 post_title = ''
                 OR post_title REGEXP '^[0-9]+$'
                 OR post_title REGEXP '^IMG_?[0-9]+$'
                 OR post_title REGEXP '^DSC_?[0-9]+$'
                 OR post_title REGEXP '^Screenshot'
                 OR post_title REGEXP '^image[_-]?[0-9]*$'
                 OR post_title REGEXP '^photo[_-]?[0-9]*$'
                 OR LENGTH(post_title) < 3
             )"
        );

        // Get count of images with keywords set
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $images_with_keywords = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_infyp_keywords'
             WHERE p.post_type = 'attachment'
             AND p.post_mime_type LIKE 'image/%'
             AND p.post_mime_type NOT LIKE 'image/svg%'
             AND p.post_status = 'inherit'
             AND pm.meta_value IS NOT NULL AND pm.meta_value != ''"
        );

        // Sample-based calculation for filename optimization
        // (filenames require filesystem check, so we sample)
        $sample_size = min( 200, $total_images );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.LikeWildcardsInQuery -- Hardcoded LIKE pattern for image MIME types is safe
        $sample_images = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE post_type = 'attachment'
                 AND post_mime_type LIKE 'image/%%'
                 AND post_mime_type NOT LIKE 'image/svg%%'
                 AND post_status = 'inherit'
                 ORDER BY RAND()
                 LIMIT %d",
                $sample_size
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.LikeWildcardsInQuery

        $needs_filename_opt = 0;
        $total_score = 0;
        $scored_count = 0;

        foreach ( $sample_images as $image_id ) {
            // Check filename
            $filename = pathinfo( get_attached_file( $image_id ), PATHINFO_FILENAME );
            $keywords = get_post_meta( $image_id, '_infyp_keywords', true );
            $filename_validation = INFYP_Field_Validator::validate_filename( $filename, $keywords );

            if ( $filename_validation['class'] !== 'success' ) {
                $needs_filename_opt++;
            }

            // Calculate SEO score (for overall percentage)
            $score_data = INFYP_SEO_Score_Calculator::calculate_score( $image_id );
            $total_score += $score_data['percentage'];
            $scored_count++;
        }

        // Extrapolate filename needs to total
        if ( $sample_size > 0 ) {
            $filename_needs_percentage = $needs_filename_opt / $sample_size;
            $needs_filename_opt = round( $filename_needs_percentage * $total_images );
        }

        // Calculate percentages
        $filename_percentage = $total_images > 0 ? round( ( ( $total_images - $needs_filename_opt ) / $total_images ) * 100 ) : 0;
        $alt_percentage = $total_images > 0 ? round( ( ( $total_images - $missing_alt ) / $total_images ) * 100 ) : 0;
        $title_percentage = $total_images > 0 ? round( ( ( $total_images - $poor_titles ) / $total_images ) * 100 ) : 0;
        $keywords_percentage = $total_images > 0 ? round( ( $images_with_keywords / $total_images ) * 100 ) : 0;

        // Coverage: share of the library that has been optimized at all.
        $optimized_percentage = $total_images > 0 ? round( ( $optimized_images / $total_images ) * 100 ) : 0;

        // Quality: average SEO score of the images that have one. This is NOT
        // a coverage figure — a fully optimized library still averages below
        // 100 whenever generated text sits outside the configured thresholds.
        $overall_percentage = $scored_count > 0 ? round( $total_score / $scored_count ) : 0;

        return [
            'total_images'           => $total_images,
            'optimized_images'       => $optimized_images,
            'optimized_percentage'   => $optimized_percentage,
            'needs_filename_opt'     => $needs_filename_opt,
            'needs_alt_opt'          => $missing_alt,
            'needs_title_opt'        => $poor_titles,
            'images_with_keywords'   => $images_with_keywords,
            'filename_percentage'    => $filename_percentage,
            'alt_percentage'         => $alt_percentage,
            'title_percentage'       => $title_percentage,
            'keywords_percentage'    => $keywords_percentage,
            'overall_percentage'     => $overall_percentage,
            'last_updated'           => time(),
        ];
    }

    /**
     * Get human-readable time since last update
     *
     * @return string Human-readable time difference
     */
    public static function get_last_updated_text() {
        $stats = get_option( self::STATS_OPTION_KEY, [] );

        if ( empty( $stats['last_updated'] ) ) {
            return __( 'Never', 'infy-ai-image-seo-optimizer' );
        }

        return human_time_diff( $stats['last_updated'], time() ) . ' ' . __( 'ago', 'infy-ai-image-seo-optimizer' );
    }

    /**
     * Force refresh statistics
     *
     * Manually triggers a statistics recalculation.
     * Useful for AJAX "Refresh" button.
     *
     * @return array Updated statistics
     */
    public static function refresh_stats() {
        $instance = self::get_instance();
        $stats = $instance->calculate_stats();
        update_option( self::STATS_OPTION_KEY, $stats, false );
        return $stats;
    }

    /**
     * Invalidate cached statistics
     *
     * Call this when images are added, deleted, or modified.
     *
     * @return void
     */
    public static function invalidate_cache() {
        delete_option( self::STATS_OPTION_KEY );
    }
}
