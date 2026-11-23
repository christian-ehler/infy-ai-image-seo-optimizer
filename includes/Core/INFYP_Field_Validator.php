<?php
/**
 * Field validation for image SEO attributes.
 *
 * Provides server-side validation of alt text, filenames, and title text that
 * mirrors the JavaScript scoring engine logic, ensuring consistent status
 * indicators across PHP-rendered table rows and JS-driven modals.
 *
 * @package    IPAIS
 * @subpackage Core
 * @since 1.0.0
 * @author     InfyPress
 * @license    GPL-2.0-or-later
 */

namespace IPAIS\Core;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class INFYP_Field_Validator
 *
 * Provides detailed field validation that matches the SEO scoring algorithm.
 *
 * @since 1.0.0
 */
class INFYP_Field_Validator {

    /**
     * Validate alt text and return detailed status.
     *
     * Evaluates alt text against the dynamic scoring parameters and returns
     * a status array indicating whether the text is missing, too short, too
     * long, or optimized. Matches JavaScript scoringEngine logic exactly.
     *
     * @since 1.0.0
     *
     * @param string $alt_text The alt text to validate.
     * @param array  $keywords Optional. SEO keywords for the image. Default empty array.
     * @return array {
     *     Validation result.
     *
     *     @type string $class  CSS class name ('empty', 'success', 'warning').
     *     @type string $text   Human-readable status label.
     *     @type string $icon   Dashicons icon name.
     *     @type array  $issues List of issue identifiers.
     * }
     */
    public static function validate_alt_text( $alt_text, $keywords = [] ) {
        // Get dynamic parameters
        $params = INFYP_SEO_Score_Calculator::get_scoring_params();
        $alt_params = $params['alt_text'];
        
        if ( empty( trim( $alt_text ) ) ) {
            return [
                'class' => 'empty',
                'text' => __('Missing', 'infy-ai-image-seo-optimizer'),
                'icon' => 'dismiss',
                'issues' => []
            ];
        }
        
        $char_count = mb_strlen( $alt_text, 'UTF-8' );
        $word_count = count( array_filter( explode( ' ', trim( $alt_text ) ) ) );

        // Match JavaScript logic exactly — tolerance-aware: play room around
        // the optimal range still counts as optimized.
        $char_zone = INFYP_SEO_Score_Calculator::evaluate_chars( $char_count, $alt_params );
        $word_zone = INFYP_SEO_Score_Calculator::evaluate_words( $word_count, $alt_params );

        if ( INFYP_SEO_Score_Calculator::within_tolerance( $char_zone ) &&
             INFYP_SEO_Score_Calculator::within_tolerance( $word_zone ) ) {
            return [
                'class' => 'success',
                'text' => __('Optimized', 'infy-ai-image-seo-optimizer'),
                'icon' => 'yes-alt',
                'issues' => []
            ];
        } else {
            // Determine specific issue
            if ( 'too_short' === $char_zone || 'too_short' === $word_zone ) {
                return [
                    'class' => 'warning',
                    'text' => __('Too short', 'infy-ai-image-seo-optimizer'),
                    'icon' => 'warning',
                    'issues' => ['length']
                ];
            } else {
                return [
                    'class' => 'warning',
                    'text' => __('Too long', 'infy-ai-image-seo-optimizer'),
                    'icon' => 'warning',
                    'issues' => ['length']
                ];
            }
        }
    }
    
    /**
     * Validate a filename and return detailed status.
     *
     * Checks filename length, word count, format (lowercase + hyphens), and
     * whether it is a generic camera filename. Matches JavaScript scoringEngine
     * logic exactly.
     *
     * @since 1.0.0
     *
     * @param string $filename The filename (without extension) to validate.
     * @param array  $keywords Optional. SEO keywords for the image. Default empty array.
     * @return array {
     *     Validation result.
     *
     *     @type string $class  CSS class name ('empty', 'success', 'warning').
     *     @type string $text   Human-readable status label.
     *     @type string $icon   Dashicons icon name.
     *     @type array  $issues List of issue identifiers.
     * }
     */
    public static function validate_filename( $filename, $keywords = [] ) {
        // Get dynamic parameters
        $params = INFYP_SEO_Score_Calculator::get_scoring_params();
        $filename_params = $params['filename'];
        
        if ( empty( trim( $filename ) ) ) {
            return [
                'class' => 'empty',
                'text' => __('Missing', 'infy-ai-image-seo-optimizer'),
                'icon' => 'dismiss',
                'issues' => []
            ];
        }
        
        // Check if generic first (matches JS logic)
        $is_generic = preg_match( '/^(DSC|IMG|DCIM|image)[\d_-]+$/i', $filename );
        
        if ( $is_generic ) {
            return [
                'class' => 'warning',
                'text' => __('Generic', 'infy-ai-image-seo-optimizer'),
                'icon' => 'warning',
                'issues' => ['generic']
            ];
        }
        
        $char_count = mb_strlen( $filename, 'UTF-8' );
        $words = array_filter( preg_split( '/[-\s]+/', $filename ) );
        $word_count = count( $words );
        
        // Check if optimal (matches JS logic exactly) — tolerance-aware: play
        // room around the optimal range still counts as optimized.
        $char_zone = INFYP_SEO_Score_Calculator::evaluate_chars( $char_count, $filename_params );
        $word_zone = INFYP_SEO_Score_Calculator::evaluate_words( $word_count, $filename_params );

        if ( INFYP_SEO_Score_Calculator::within_tolerance( $char_zone ) &&
             INFYP_SEO_Score_Calculator::within_tolerance( $word_zone ) &&
             preg_match( '/^[a-z0-9\-]+$/', $filename ) ) {
            return [
                'class' => 'success',
                'text' => __('Optimized', 'infy-ai-image-seo-optimizer'),
                'icon' => 'yes-alt',
                'issues' => []
            ];
        } else {
            // Determine specific issue for filename
            if ( 'too_short' === $char_zone || 'too_short' === $word_zone ) {
                return [
                    'class' => 'warning',
                    'text' => __('Too short', 'infy-ai-image-seo-optimizer'),
                    'icon' => 'warning',
                    'issues' => ['format']
                ];
            } else if ( 'too_long' === $char_zone || 'too_long' === $word_zone ) {
                return [
                    'class' => 'warning',
                    'text' => __('Too long', 'infy-ai-image-seo-optimizer'),
                    'icon' => 'warning',
                    'issues' => ['format']
                ];
            } else {
                // Format issues (uppercase, special chars, etc.)
                return [
                    'class' => 'warning',
                    'text' => __('Invalid format', 'infy-ai-image-seo-optimizer'),
                    'icon' => 'warning',
                    'issues' => ['format']
                ];
            }
        }
    }
    
    /**
     * Validate title text and return detailed status.
     *
     * Evaluates image title text against character and word count thresholds
     * defined in the scoring parameters. Matches JavaScript scoringEngine logic
     * exactly.
     *
     * @since 1.0.0
     *
     * @param string $title_text The title text to validate.
     * @param string $alt_text   Optional. Alt text for cross-field comparison. Default empty string.
     * @param array  $keywords   Optional. SEO keywords for the image. Default empty array.
     * @return array {
     *     Validation result.
     *
     *     @type string $class  CSS class name ('empty', 'success', 'warning').
     *     @type string $text   Human-readable status label.
     *     @type string $icon   Dashicons icon name.
     *     @type array  $issues List of issue identifiers.
     * }
     */
    public static function validate_title( $title_text, $alt_text = '', $keywords = [] ) {
        // Get dynamic parameters
        $params = INFYP_SEO_Score_Calculator::get_scoring_params();
        $title_params = $params['title'];
        
        if ( empty( trim( $title_text ) ) ) {
            return [
                'class' => 'empty',
                'text' => __('Missing', 'infy-ai-image-seo-optimizer'),
                'icon' => 'dismiss',
                'issues' => []
            ];
        }
        
        $char_count = mb_strlen( $title_text, 'UTF-8' );
        $word_count = count( array_filter( explode( ' ', trim( $title_text ) ) ) );

        // Match JavaScript logic exactly — tolerance-aware: play room around
        // the optimal range still counts as optimized.
        $char_zone = INFYP_SEO_Score_Calculator::evaluate_chars( $char_count, $title_params );
        $word_zone = INFYP_SEO_Score_Calculator::evaluate_words( $word_count, $title_params );

        if ( INFYP_SEO_Score_Calculator::within_tolerance( $char_zone ) &&
             INFYP_SEO_Score_Calculator::within_tolerance( $word_zone ) ) {
            return [
                'class' => 'success',
                'text' => __('Optimized', 'infy-ai-image-seo-optimizer'),
                'icon' => 'yes-alt',
                'issues' => []
            ];
        } else {
            // Determine specific issue
            if ( 'too_short' === $char_zone || 'too_short' === $word_zone ) {
                return [
                    'class' => 'warning',
                    'text' => __('Too short', 'infy-ai-image-seo-optimizer'),
                    'icon' => 'warning',
                    'issues' => ['length']
                ];
            } else {
                return [
                    'class' => 'warning',
                    'text' => __('Too long', 'infy-ai-image-seo-optimizer'),
                    'icon' => 'warning',
                    'issues' => ['length']
                ];
            }
        }
    }
    
    /**
     * Check whether text contains keyword stuffing.
     *
     * Detects excessive repetition of non-stop-words by comparing individual
     * word frequency against a threshold ratio.
     *
     * @since 1.0.0
     * @access private
     *
     * @param string $text The text to analyze.
     * @return bool True if keyword stuffing is detected, false otherwise.
     */
    private static function has_keyword_stuffing( $text ) {
        $words = str_word_count( strtolower( $text ), 1 );
        $word_count = count( $words );
        
        if ( $word_count < 5 ) {
            return false;
        }
        
        $word_frequency = array_count_values( $words );
        foreach ( $word_frequency as $word => $count ) {
            if ( in_array( $word, ['the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by'] ) ) {
                continue;
            }
            
            if ( $count > 2 || ( $count / $word_count ) > 0.2 ) {
                return true;
            }
        }
        
        return false;
    }
}