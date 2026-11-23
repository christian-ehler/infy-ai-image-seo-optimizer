<?php
/**
 * SEO score calculation engine for image attachments.
 *
 * Computes a 0-100 SEO score for each image based on configurable weights
 * across alt text, title, filename, keyword density, and uniqueness criteria.
 * Scoring parameters and weights are customisable via wp_options and are
 * statically cached per request for performance.
 *
 * @package    IPAIS
 * @subpackage Core
 * @since 1.0.0
 * @author     InfyPress
 * @license    GPL-2.0-or-later
 */

namespace IPAIS\Core;

// Security check
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SEO Score Calculator
 *
 * Calculates image SEO scores based on best practices.
 *
 * @since 1.0.0
 */
class INFYP_SEO_Score_Calculator {

    /**
     * Default weight configuration for score components.
     *
     * @since 1.0.0
     * @var    array<string, int>
     * @access private
     */
    private static $default_weights = [
        'alt_presence' => 20,
        'alt_quality' => 15,
        'title_presence' => 15,
        'title_quality' => 10,
        'filename_quality' => 15,
        'keyword_density' => 15,
        'uniqueness' => 10
    ];
    
    /**
     * Default scoring parameters for alt text, filename, and title fields.
     *
     * @since 1.0.0
     * @var    array<string, array<string, int>>
     * @access private
     */
    private static $default_params = [
        'alt_text' => [
            'min_chars' => 50,
            'max_chars' => 125,
            'min_words' => 10,
            'max_words' => 16,
            'tolerance_words' => 1,
            'tolerance_chars_pct' => 15,
            'auto_link' => 1,
            'base_score' => 30,
            'length_score' => 30,
            'quality_score' => 20,
            'keyword_score' => 20
        ],
        'filename' => [
            'min_chars' => 40,
            'max_chars' => 60,
            'min_words' => 3,
            'max_words' => 6,
            'tolerance_words' => 1,
            'tolerance_chars_pct' => 15,
            'auto_link' => 1,
            'base_score' => 30,
            'format_score' => 20,
            'length_score' => 20,
            'descriptive_score' => 15,
            'keyword_score' => 15
        ],
        'title' => [
            'min_chars' => 40,
            'max_chars' => 60,
            'min_words' => 6,
            'max_words' => 10,
            'tolerance_words' => 1,
            'tolerance_chars_pct' => 15,
            'auto_link' => 1,
            'base_score' => 25,
            'length_score' => 30,
            'relation_score' => 20,
            'keyword_score' => 25
        ]
    ];

    /**
     * Default word tolerance applied when a stored params array predates the
     * tolerance keys.
     *
     * @since 1.0.6
     * @var int
     */
    const DEFAULT_TOLERANCE_WORDS = 1;

    /**
     * Default character tolerance (percent of each limit) applied when a
     * stored params array predates the tolerance keys.
     *
     * @since 1.0.6
     * @var int
     */
    const DEFAULT_TOLERANCE_CHARS_PCT = 15;
    
    /**
     * Static cache for weights (loaded once per request)
     * @var array|null
     */
    private static $cached_weights = null;

    /**
     * Static cache for scoring params (loaded once per request)
     * @var array|null
     */
    private static $cached_params = null;

    /**
     * Get the current scoring weights, merging saved custom values with defaults.
     *
     * Results are statically cached for the duration of the request. Handles
     * legacy weight format conversion transparently.
     *
     * @since 1.0.0
     *
     * @return array<string, int> Associative array of weight component names to integer values.
     */
    public static function get_weights() {
        if ( self::$cached_weights !== null ) {
            return self::$cached_weights;
        }

        $custom_weights = get_option( 'infyp_seo_weights', [] );

        // Legacy format check
        if ( isset( $custom_weights['alt_text'] ) || isset( $custom_weights['filename'] ) || isset( $custom_weights['title_text'] ) ) {
            $legacy_weights = $custom_weights;
            $custom_weights = self::convert_legacy_weights( $legacy_weights );
            // Update format
            update_option( 'infyp_seo_weights', $custom_weights );
        }

        self::$cached_weights = wp_parse_args( $custom_weights, self::$default_weights );
        return self::$cached_weights;
    }
    
    /**
     * Convert legacy 3-weight or 5-weight format to the current 7-weight format.
     *
     * Splits combined alt/title weights into separate presence and quality
     * components and normalises the total to 100%.
     *
     * @since 1.0.0
     * @access private
     *
     * @param array $legacy_weights Legacy weight values from wp_options.
     * @return array<string, int> Converted weights in the current format.
     */
    private static function convert_legacy_weights( $legacy_weights ) {
        $new_weights = [];
        
        // Check for 5-weight format
        if ( isset( $legacy_weights['keyword_density'] ) && isset( $legacy_weights['uniqueness'] ) ) {
            // New format
            $alt_weight = isset( $legacy_weights['alt_text'] ) ? floatval( $legacy_weights['alt_text'] ) : 35;
            $filename_weight = isset( $legacy_weights['filename'] ) ? floatval( $legacy_weights['filename'] ) : 15;
            $title_weight = isset( $legacy_weights['title_text'] ) ? floatval( $legacy_weights['title_text'] ) : 25;
            $keyword_weight = floatval( $legacy_weights['keyword_density'] );
            $uniqueness_weight = floatval( $legacy_weights['uniqueness'] );
            
            // Split combined weights
            $new_weights['alt_presence'] = round( $alt_weight * (20/35) );
            $new_weights['alt_quality'] = round( $alt_weight * (15/35) );
            
            // Title text UI weight splits into presence (15/25) and quality (10/25)
            $new_weights['title_presence'] = round( $title_weight * (15/25) );
            $new_weights['title_quality'] = round( $title_weight * (10/25) );
            
            // These stay as is
            $new_weights['filename_quality'] = $filename_weight;
            $new_weights['keyword_density'] = $keyword_weight;
            $new_weights['uniqueness'] = $uniqueness_weight;
        } else {
            // Old 3-weight format
            $alt_weight = isset( $legacy_weights['alt_text'] ) ? floatval( $legacy_weights['alt_text'] ) : 35;
            $filename_weight = isset( $legacy_weights['filename'] ) ? floatval( $legacy_weights['filename'] ) : 15;
            $title_weight = isset( $legacy_weights['title_text'] ) ? floatval( $legacy_weights['title_text'] ) : 25;
            
            // UI weights should total 75% (visible portion)
            $ui_total = $alt_weight + $filename_weight + $title_weight;
            
            // If user entered values totaling more than 75, normalize them
            if ( $ui_total > 75 ) {
                $scale = 75 / $ui_total;
                $alt_weight = round( $alt_weight * $scale );
                $filename_weight = round( $filename_weight * $scale );
                $title_weight = round( $title_weight * $scale );
            }
            
            // Split combined weights into individual components
            $new_weights['alt_presence'] = round( $alt_weight * (20/35) );
            $new_weights['alt_quality'] = round( $alt_weight * (15/35) );
            $new_weights['title_presence'] = round( $title_weight * (15/25) );
            $new_weights['title_quality'] = round( $title_weight * (10/25) );
            $new_weights['filename_quality'] = $filename_weight;
            
            // Add fixed hidden weights
            $new_weights['keyword_density'] = 15;
            $new_weights['uniqueness'] = 10;
        }
        
        // Ensure exactly 100%
        $total = array_sum( $new_weights );
        if ( $total !== 100 ) {
            // Adjust alt_presence for any rounding errors
            $new_weights['alt_presence'] += ( 100 - $total );
        }
        
        return $new_weights;
    }
    
    /**
     * Get current scoring parameters, statically cached per request.
     *
     * Merges custom parameters stored in the 'infyp_scoring_parameters' option
     * with defaults for alt_text, filename, and title sections.
     *
     * @since 1.0.0
     *
     * @return array<string, array<string, int>> Scoring parameter arrays keyed by field type.
     */
    public static function get_scoring_params() {
        if ( self::$cached_params !== null ) {
            return self::$cached_params;
        }

        $custom_params = get_option( 'infyp_scoring_parameters', [] );

        // Ensure custom_params is an array
        if ( ! is_array( $custom_params ) ) {
            $custom_params = [];
        }

        // Deep-merge per section so newly-added defaults (e.g. auto_link in v1.0.3)
        // fall through to the default when the saved option doesn't yet contain them.
        // wp_parse_args() at the top level only merges section keys, not their inner
        // arrays — using it directly would replace whole sections wholesale and drop
        // any inner keys not present in the saved value.
        $merged = [];
        foreach ( ['alt_text', 'filename', 'title'] as $section ) {
            $section_custom = ( isset( $custom_params[$section] ) && is_array( $custom_params[$section] ) )
                ? $custom_params[$section]
                : [];
            $merged[$section] = wp_parse_args(
                $section_custom,
                self::$default_params[$section]
            );
        }

        self::$cached_params = $merged;
        return self::$cached_params;
    }

    /**
     * Resolve which tolerance zone a metric count falls into.
     *
     * The configured [min, max] range is the optimal target; a tolerance band
     * on each side is still "acceptable" (full credit, no warning). Only
     * beyond the band does the metric count as too short / too long.
     *
     * @since 1.0.6
     *
     * @param int|float $count          Measured value (characters or words).
     * @param int|float $min            Lower bound of the optimal range.
     * @param int|float $max            Upper bound of the optimal range.
     * @param int|float $tolerance_low  Play room below $min.
     * @param int|float $tolerance_high Play room above $max.
     * @return string One of 'optimal', 'acceptable', 'too_short', 'too_long'.
     */
    public static function evaluate_metric( $count, $min, $max, $tolerance_low, $tolerance_high ) {
        if ( $count < $min - $tolerance_low ) {
            return 'too_short';
        }
        if ( $count > $max + $tolerance_high ) {
            return 'too_long';
        }
        if ( $count < $min || $count > $max ) {
            return 'acceptable';
        }
        return 'optimal';
    }

    /**
     * Evaluate a character count against a field's params, tolerance-aware.
     *
     * The tolerance is tolerance_chars_pct percent of each limit: e.g. 40-60
     * chars at 15% accepts 34-69.
     *
     * @since 1.0.6
     *
     * @param int   $count  Character count.
     * @param array $params Field params (min_chars, max_chars, optionally tolerance_chars_pct).
     * @return string Zone name (see evaluate_metric()).
     */
    public static function evaluate_chars( $count, array $params ) {
        $pct = isset( $params['tolerance_chars_pct'] ) && is_numeric( $params['tolerance_chars_pct'] )
            ? max( 0, (float) $params['tolerance_chars_pct'] )
            : self::DEFAULT_TOLERANCE_CHARS_PCT;

        return self::evaluate_metric(
            $count,
            $params['min_chars'],
            $params['max_chars'],
            (int) round( $params['min_chars'] * $pct / 100 ),
            (int) round( $params['max_chars'] * $pct / 100 )
        );
    }

    /**
     * Evaluate a word count against a field's params, tolerance-aware.
     *
     * The tolerance is tolerance_words absolute words on each side: e.g.
     * 3-6 words at ±1 accepts 2-7.
     *
     * @since 1.0.6
     *
     * @param int   $count  Word count.
     * @param array $params Field params (min_words, max_words, optionally tolerance_words).
     * @return string Zone name (see evaluate_metric()).
     */
    public static function evaluate_words( $count, array $params ) {
        $tolerance = isset( $params['tolerance_words'] ) && is_numeric( $params['tolerance_words'] )
            ? max( 0, (int) $params['tolerance_words'] )
            : self::DEFAULT_TOLERANCE_WORDS;

        return self::evaluate_metric(
            $count,
            $params['min_words'],
            $params['max_words'],
            $tolerance,
            $tolerance
        );
    }

    /**
     * Whether a zone counts as meeting the length target (full credit).
     *
     * @since 1.0.6
     *
     * @param string $zone Zone name from evaluate_metric().
     * @return bool True for 'optimal' and 'acceptable'.
     */
    public static function within_tolerance( $zone ) {
        return 'optimal' === $zone || 'acceptable' === $zone;
    }


    /**
     * Calculate SEO score for an image
     * 
     * @param int $image_id The attachment ID
     * @return array Score data including percentage and status
     */
    public static function calculate_score( $image_id ) {
        $score = 0;
        $breakdown = [];
        $suggestions = [];
        
        // Get weights
        $weights = self::get_weights();
        $scoring_params = self::get_scoring_params();
        
        // Get image data
        $alt_text = get_post_meta( $image_id, '_wp_attachment_image_alt', true );
        $title_text = get_post_meta( $image_id, '_wp_attachment_title_text', true );
        if ( empty( $title_text ) ) {
            $title_text = get_the_title( $image_id );
        }
        $filename = pathinfo( get_attached_file( $image_id ), PATHINFO_FILENAME );
        $keywords = get_post_meta( $image_id, '_infyp_keywords', true );
        if ( ! is_array( $keywords ) ) {
            $keywords = [];
        }
        $has_been_optimized = get_post_meta( $image_id, '_infyp_has_been_optimized', true );
        
        // 1. Alt Text Presence and Quality
        $alt_score = 0;
        $alt_presence_score = 0;
        $alt_quality_score = 0;
        $alt_details = [
            'char_count' => 0,
            'word_count' => 0,
            'has_keywords' => false
        ];
        
        if ( ! empty( $alt_text ) ) {
            $score += $weights['alt_presence'];
            $alt_score += $weights['alt_presence'];
            $alt_presence_score = $weights['alt_presence'];
            
            $alt_length = mb_strlen( $alt_text, 'UTF-8' );
            $word_count = str_word_count( $alt_text );
            $alt_details['char_count'] = $alt_length;
            $alt_details['word_count'] = $word_count;
            
            $alt_params = ( isset( $scoring_params['alt_text'] ) && is_array( $scoring_params['alt_text'] ) ) 
                ? $scoring_params['alt_text'] 
                : [
                    'min_chars' => 50,
                    'max_chars' => 125,
                    'min_words' => 10,
                    'max_words' => 16
                ];
            
            if ( self::within_tolerance( self::evaluate_chars( $alt_length, $alt_params ) ) &&
                 self::within_tolerance( self::evaluate_words( $word_count, $alt_params ) ) ) {
                $score += $weights['alt_quality'];
                $alt_score += $weights['alt_quality'];
                $alt_quality_score = $weights['alt_quality'];
            } else {
                // Partial score for suboptimal quality
                $score += $weights['alt_quality'] * 0.5;
                $alt_score += $weights['alt_quality'] * 0.5;
                $alt_quality_score = $weights['alt_quality'] * 0.5;
            }
            
            // Check for keywords in alt text
            foreach ( $keywords as $keyword ) {
                if ( stripos( $alt_text, $keyword ) !== false ) {
                    $alt_details['has_keywords'] = true;
                    break;
                }
            }
        }
        
        // Keep both formats for compatibility
        $breakdown['alt_text'] = [
            'score' => $alt_score,
            'max_score' => $weights['alt_presence'] + $weights['alt_quality'],
            'details' => $alt_details
        ];
        
        $breakdown['alt_presence'] = [
            'score' => $alt_presence_score,
            'max_score' => $weights['alt_presence'],
            'details' => $alt_details
        ];
        
        $breakdown['alt_quality'] = [
            'score' => $alt_quality_score,
            'max_score' => $weights['alt_quality'],
            'details' => $alt_details
        ];
        
        // 3. Title Presence and Quality
        $title_score = 0;
        $title_presence_score = 0;
        $title_quality_score = 0;
        $title_details = [
            'char_count' => 0,
            'word_count' => 0,
            'has_keywords' => false
        ];
        
        if ( ! empty( $title_text ) ) {
            $score += $weights['title_presence'];
            $title_score += $weights['title_presence'];
            $title_presence_score = $weights['title_presence'];
            
            $title_length = mb_strlen( $title_text, 'UTF-8' );
            $title_words = str_word_count( $title_text );
            $title_details['char_count'] = $title_length;
            $title_details['word_count'] = $title_words;
            
            $title_params = ( isset( $scoring_params['title'] ) && is_array( $scoring_params['title'] ) ) 
                ? $scoring_params['title'] 
                : [
                    'min_chars' => 40,
                    'max_chars' => 60,
                    'min_words' => 6,
                    'max_words' => 10
                ];
            
            if ( self::within_tolerance( self::evaluate_chars( $title_length, $title_params ) ) &&
                 self::within_tolerance( self::evaluate_words( $title_words, $title_params ) ) ) {
                $score += $weights['title_quality'];
                $title_score += $weights['title_quality'];
                $title_quality_score = $weights['title_quality'];
            } else {
                // Partial score for suboptimal quality
                $score += $weights['title_quality'] * 0.5;
                $title_score += $weights['title_quality'] * 0.5;
                $title_quality_score = $weights['title_quality'] * 0.5;
            }
            
            // Check for keywords in title text
            foreach ( $keywords as $keyword ) {
                if ( stripos( $title_text, $keyword ) !== false ) {
                    $title_details['has_keywords'] = true;
                    break;
                }
            }
        }
        
        // Keep both formats for compatibility
        $breakdown['title_text'] = [
            'score' => $title_score,
            'max_score' => $weights['title_presence'] + $weights['title_quality'],
            'details' => $title_details
        ];
        
        $breakdown['title_presence'] = [
            'score' => $title_presence_score,
            'max_score' => $weights['title_presence'],
            'details' => $title_details
        ];
        
        $breakdown['title_quality'] = [
            'score' => $title_quality_score,
            'max_score' => $weights['title_quality'],
            'details' => $title_details
        ];
        
        // 5. Filename Quality
        $filename_score = 0;
        $filename_max = $weights['filename_quality'];
        $filename_details = [
            'char_count' => 0,
            'word_count' => 0,
            'has_keywords' => false,
            'is_seo_friendly' => false,
            'is_generic' => false
        ];
        
        if ( ! empty( $filename ) ) {
            $filename_part = $filename;
            $filename_length = mb_strlen( $filename_part, 'UTF-8' );
            // Match JavaScript word counting logic
            $filename_words = count( array_filter( preg_split( '/[-_\s]+/', $filename_part ), function( $w ) {
                return strlen( $w ) > 0;
            } ) );
            
            $filename_details['char_count'] = $filename_length;
            $filename_details['word_count'] = $filename_words;
            
            $filename_params = ( isset( $scoring_params['filename'] ) && is_array( $scoring_params['filename'] ) ) 
                ? $scoring_params['filename'] 
                : [
                    'min_chars' => 40,
                    'max_chars' => 60,
                    'min_words' => 3,
                    'max_words' => 6
                ];
            
            // Match JavaScript logic EXACTLY
            // JavaScript checks the FULL filename with extension, PHP gets just the part
            // So we need to adapt the pattern for filename part only
            $is_generic = preg_match( '/^(DSC|IMG|DCIM|image)[\\d_-]+$/i', $filename_part );
            $has_optimized_filename = ! $is_generic;
            
            // Check if filename is SEO-friendly format (lowercase and hyphens)
            $is_seo_friendly = preg_match('/^[a-z0-9\-]+$/', $filename_part);
            
            $filename_details['is_generic'] = $is_generic;
            $filename_details['is_seo_friendly'] = $is_seo_friendly;
            
            if ( $has_optimized_filename ) {
                $score += $weights['filename_quality'];
                $filename_score += $weights['filename_quality'];
            }
            
            // Check for keywords in filename
            foreach ( $keywords as $keyword ) {
                $keyword_hyphenated = strtolower( str_replace( ' ', '-', $keyword ) );
                if ( stripos( $filename, $keyword ) !== false || stripos( $filename, $keyword_hyphenated ) !== false ) {
                    $filename_details['has_keywords'] = true;
                    break;
                }
            }
        }
        
        // Keep both formats for compatibility
        $breakdown['filename'] = [
            'score' => $filename_score,
            'max_score' => $filename_max,
            'details' => $filename_details
        ];
        
        $breakdown['filename_quality'] = [
            'score' => $filename_score,
            'max_score' => $filename_max,
            'details' => $filename_details
        ];
        
        // 6. Keyword Density
        $keyword_score = 0;
        $keyword_max = $weights['keyword_density'];
        $keyword_details = [
            'has_keywords' => ! empty( $keywords ),
            'keyword_count' => count( $keywords ),
            'placement' => [
                'alt' => [],
                'title' => [],
                'filename' => []
            ],
            'fields_with_keywords' => 0
        ];
        
        if ( ! empty( $keywords ) ) {
            $keyword_placement = [
                'alt' => [],
                'title' => [],
                'filename' => []
            ];
            
            foreach ( $keywords as $kw ) {
                if ( ! empty( $alt_text ) && stripos( $alt_text, $kw ) !== false ) {
                    $keyword_placement['alt'][] = $kw;
                }
                if ( ! empty( $title_text ) && stripos( $title_text, $kw ) !== false ) {
                    $keyword_placement['title'][] = $kw;
                }
                // Check both original and hyphenated version for filename
                $hyphenated_kw = strtolower( str_replace( ' ', '-', $kw ) );
                if ( ! empty( $filename ) && ( stripos( $filename, $kw ) !== false || stripos( $filename, $hyphenated_kw ) !== false ) ) {
                    $keyword_placement['filename'][] = $kw;
                }
            }
            
            $keyword_details['placement'] = $keyword_placement;
            
            $fields_with_keywords = 0;
            foreach ( $keyword_placement as $field => $kws ) {
                if ( count( $kws ) > 0 ) {
                    $fields_with_keywords++;
                }
            }
            
            $keyword_details['fields_with_keywords'] = $fields_with_keywords;
            
            // Check if all keywords can fit in filename
            $all_keywords_for_filename = implode( '-', array_map( function( $kw ) {
                return strtolower( str_replace( ' ', '-', $kw ) );
            }, $keywords ) );
            $can_fit_all_in_filename = mb_strlen( $all_keywords_for_filename ) <= $filename_params['max_chars'];
            
            // Match frontend scoring logic exactly - simplified without checking can_fit_all
            if ( $fields_with_keywords === 3 ) {
                $score += $weights['keyword_density'];
                $keyword_score = $weights['keyword_density'];
            } elseif ( $fields_with_keywords === 2 ) {
                $keyword_score = $weights['keyword_density'] * 0.85;
                $score += $keyword_score;
            } elseif ( $fields_with_keywords === 1 ) {
                $keyword_score = $weights['keyword_density'] * 0.5;
                $score += $keyword_score;
            }
        } else {
            // No keywords defined, give 0 points (matching JavaScript behavior)
            // $score += 0; // No points for missing keywords
        }
        
        $breakdown['keyword_density'] = [
            'score' => $keyword_score,
            'max_score' => $keyword_max,
            'details' => $keyword_details
        ];
        
        // 7. Uniqueness
        $uniqueness_score = 0;
        $uniqueness_max = isset( $weights['uniqueness'] ) ? $weights['uniqueness'] : 0;
        $uniqueness_details = [
            'is_unique' => true,
            'duplicate_count' => 0
        ];
        
        if ( isset( $weights['uniqueness'] ) ) {
            // For now, assume all images are unique
            $score += $weights['uniqueness'];
            $uniqueness_score = $weights['uniqueness'];
        }
        
        $breakdown['uniqueness'] = [
            'score' => $uniqueness_score,
            'max_score' => $uniqueness_max,
            'details' => $uniqueness_details
        ];
        
        // Calculate percentage
        $max_score = array_sum( $weights );
        $percentage = $max_score > 0 ? round( ( $score / $max_score ) * 100 ) : 0;
        
        // Add AI optimization indicator
        $breakdown['has_ai_optimization'] = $has_been_optimized;
        
        // Determine status
        $thresholds = apply_filters('infyp_seo_score_thresholds', [
            'optimized' => 80,
            'good' => 60,
            'fair' => 40,
        ]);

        if ( $percentage >= $thresholds['optimized'] ) {
            $status = __( 'Optimized', 'infy-ai-image-seo-optimizer' );
            $status_class = 'optimized';
        } elseif ( $percentage >= $thresholds['good'] ) {
            $status = __( 'Good', 'infy-ai-image-seo-optimizer' );
            $status_class = 'good';
        } elseif ( $percentage >= $thresholds['fair'] ) {
            $status = __( 'Fair', 'infy-ai-image-seo-optimizer' );
            $status_class = 'fair';
        } else {
            $status = __( 'Poor', 'infy-ai-image-seo-optimizer' );
            $status_class = 'poor';
        }

        // Cache the score in post meta for sorting
        update_post_meta( $image_id, '_infyp_seo_score', $percentage );

        $result = [
            'score' => $score,
            'max_score' => $max_score,
            'percentage' => $percentage,
            'status' => $status,
            'status_class' => $status_class,
            'breakdown' => $breakdown,
            'suggestions' => $suggestions,
            'weights' => $weights
        ];

        /**
         * Filter the final SEO score result.
         *
         * @since 1.0.0
         * @param array $result     The score result array with 'percentage', 'breakdown', 'suggestions'.
         * @param int   $image_id   The attachment ID.
         */
        $result = apply_filters('infyp_seo_score_result', $result, $image_id);

        return $result;
    }

    /**
     * Get display data from a cached percentage (no DB queries)
     *
     * Use this instead of calculate_score() when you only need the percentage,
     * status label, and status CSS class for table rendering.
     *
     * @param int $percentage The cached SEO score percentage (0-100)
     * @return array ['percentage' => int, 'status' => string, 'status_class' => string]
     */
    public static function get_display_data_from_percentage( $percentage ) {
        $percentage = max( 0, min( 100, intval( $percentage ) ) );

        if ( $percentage >= 80 ) {
            $status = __( 'Optimized', 'infy-ai-image-seo-optimizer' );
            $status_class = 'optimized';
        } elseif ( $percentage >= 60 ) {
            $status = __( 'Good', 'infy-ai-image-seo-optimizer' );
            $status_class = 'good';
        } elseif ( $percentage >= 40 ) {
            $status = __( 'Fair', 'infy-ai-image-seo-optimizer' );
            $status_class = 'fair';
        } else {
            $status = __( 'Poor', 'infy-ai-image-seo-optimizer' );
            $status_class = 'poor';
        }

        return [
            'percentage'   => $percentage,
            'status'       => $status,
            'status_class' => $status_class,
        ];
    }

    /**
     * Calculate the detailed alt text sub-score.
     *
     * Evaluates alt text against character count, word count, keyword presence,
     * and quality heuristics (keyword stuffing, starts-with-image patterns).
     *
     * @since 1.0.0
     * @access private
     *
     * @param string $alt_text  The alt text to evaluate.
     * @param array  $keywords  SEO keywords for the image.
     * @param float  $max_score Maximum possible score for this component.
     * @return array {
     *     @type float  $score       Earned score.
     *     @type float  $max_score   Maximum possible score.
     *     @type array  $suggestions Improvement suggestions.
     *     @type array  $details     Detailed metrics (char_count, word_count, has_keywords).
     * }
     */
    private static function calculate_alt_text_score( $alt_text, $keywords, $max_score ) {
        $suggestions = [];
        
        // Get dynamic parameters
        $params = self::get_scoring_params();
        $alt_params = $params['alt_text'];
        
        // Define optimal ranges from params
        $optimal_min_chars = $alt_params['min_chars'];
        $optimal_max_chars = $alt_params['max_chars'];
        $optimal_min_words = $alt_params['min_words'];
        $optimal_max_words = $alt_params['max_words'];
        
        if ( empty( $alt_text ) ) {
            $suggestions[] = sprintf( 
                /* translators: 1: Minimum character count, 2: Maximum character count */
                __( 'Add descriptive alt text (%1$d-%2$d characters)', 'infy-ai-image-seo-optimizer' ),
                $optimal_min_chars,
                $optimal_max_chars
            );
            return [
                'score' => 0,
                'max_score' => $max_score,
                'suggestions' => $suggestions,
                'details' => [
                    'char_count' => 0,
                    'word_count' => 0,
                    'has_keywords' => false
                ]
            ];
        }
        
        $word_count = str_word_count( $alt_text );
        $char_count = mb_strlen( $alt_text, 'UTF-8' );
        $has_keywords = false;
        $current_score = 0;
        
        // Base score for having alt text
        $current_score += ( $max_score * ( $alt_params['base_score'] / 100 ) );
        
        // Length scoring — tolerance-aware: counts within the play room around
        // the optimal range earn full length credit and trigger no suggestion.
        $chars_ok = self::within_tolerance( self::evaluate_chars( $char_count, $alt_params ) );
        $words_ok = self::within_tolerance( self::evaluate_words( $word_count, $alt_params ) );

        if ( $chars_ok && $words_ok ) {
            $current_score += ( $max_score * ( $alt_params['length_score'] / 100 ) ); // Perfect length
        } else if ( $chars_ok ) {
            $current_score += ( $max_score * ( $alt_params['length_score'] / 100 ) * 0.66 ); // Good character count but word count off
            if ( $word_count < $optimal_min_words ) {
                $suggestions[] = sprintf(
                    /* translators: 1: Current word count, 2: Minimum word count, 3: Maximum word count */
                    __( 'Alt text needs more words (%1$d words). Aim for %2$d-%3$d words', 'infy-ai-image-seo-optimizer' ),
                    $word_count,
                    $optimal_min_words,
                    $optimal_max_words
                );
            } else {
                $suggestions[] = sprintf(
                    /* translators: 1: Current word count, 2: Maximum word count */
                    __( 'Alt text has too many words (%1$d words). Keep under %2$d words', 'infy-ai-image-seo-optimizer' ),
                    $word_count,
                    $optimal_max_words
                );
            }
        } else if ( $words_ok ) {
            $current_score += ( $max_score * ( $alt_params['length_score'] / 100 ) * 0.66 ); // Good word count but character count off
            if ( $char_count < $optimal_min_chars ) {
                $suggestions[] = sprintf(
                    /* translators: 1: Current character count, 2: Minimum character count, 3: Maximum character count */
                    __( 'Alt text too short (%1$d chars). Aim for %2$d-%3$d characters', 'infy-ai-image-seo-optimizer' ),
                    $char_count,
                    $optimal_min_chars,
                    $optimal_max_chars
                );
            } else {
                $suggestions[] = sprintf(
                    /* translators: 1: Current character count, 2: Maximum character count */
                    __( 'Alt text too long (%1$d chars). Keep under %2$d characters', 'infy-ai-image-seo-optimizer' ),
                    $char_count,
                    $optimal_max_chars
                );
            }
        } else {
            // Neither optimal
            $current_score += ( $max_score * ( $alt_params['length_score'] / 100 ) * 0.33 ); // Minimal credit
            $suggestions[] = sprintf(
                /* translators: 1: Current character count, 2: Current word count, 3: Minimum character count, 4: Maximum character count, 5: Minimum word count, 6: Maximum word count */
                __( 'Alt text needs adjustment: %1$d chars, %2$d words. Target: %3$d-%4$d chars, %5$d-%6$d words', 'infy-ai-image-seo-optimizer' ),
                $char_count,
                $word_count,
                $optimal_min_chars,
                $optimal_max_chars,
                $optimal_min_words,
                $optimal_max_words
            );
        }
        
        // Quality check
        $generic_terms = ['image', 'picture', 'photo', 'img', 'pic'];
        $alt_lower = strtolower( $alt_text );
        $is_generic = false;
        
        foreach ( $generic_terms as $term ) {
            if ( $alt_lower === $term || ( $word_count === 1 && strpos( $alt_lower, $term ) !== false ) ) {
                $is_generic = true;
                $suggestions[] = __( 'Alt text is too generic. Be more descriptive', 'infy-ai-image-seo-optimizer' );
                break;
            }
        }
        
        // Check for keyword stuffing
        $has_keyword_stuffing = self::has_keyword_stuffing( $alt_text );
        if ( $has_keyword_stuffing ) {
            $suggestions[] = __( 'Avoid keyword stuffing in alt text', 'infy-ai-image-seo-optimizer' );
        }
        
        // Award quality score
        if ( ! $is_generic && ! $has_keyword_stuffing ) {
            $current_score += ( $max_score * ( $alt_params['quality_score'] / 100 ) ); // Full quality bonus
        } else if ( ! $is_generic || ! $has_keyword_stuffing ) {
            $current_score += ( $max_score * ( $alt_params['quality_score'] / 100 ) * 0.5 ); // Partial quality bonus
        }
        // No quality bonus if both generic and keyword stuffing
        
        // Keyword presence bonus
        if ( ! empty( $keywords ) && is_array( $keywords ) ) {
            foreach ( $keywords as $keyword ) {
                if ( stripos( $alt_text, $keyword ) !== false ) {
                    $has_keywords = true;
                    $current_score += ( $max_score * ( $alt_params['keyword_score'] / 100 ) ); // Keyword bonus
                    break;
                }
            }
            
            if ( ! $has_keywords && $word_count > 5 ) {
                $suggestions[] = __( 'Consider including relevant keywords naturally', 'infy-ai-image-seo-optimizer' );
            }
        } else {
            // No keywords defined, so give full credit for this section
            $current_score += ( $max_score * ( $alt_params['keyword_score'] / 100 ) );
        }
        
        return [
            'score' => max( 0, min( $max_score, $current_score ) ),
            'max_score' => $max_score,
            'suggestions' => $suggestions,
            'details' => [
                'char_count' => $char_count,
                'word_count' => $word_count,
                'has_keywords' => $has_keywords,
                /* translators: 1: minimum characters, 2: maximum characters */
                'optimal_range' => sprintf( '%1$d-%2$d chars', $optimal_min_chars, $optimal_max_chars )
            ]
        ];
    }
    
    /**
     * Calculate the detailed title text sub-score.
     *
     * Evaluates title text against character count, word count, keyword presence,
     * relation to alt text, and quality heuristics.
     *
     * @since 1.0.0
     * @access private
     *
     * @param string $title_text The title text to evaluate.
     * @param string $alt_text   The corresponding alt text for relation scoring.
     * @param array  $keywords   SEO keywords for the image.
     * @param float  $max_score  Maximum possible score for this component.
     * @return array {
     *     @type float  $score       Earned score.
     *     @type float  $max_score   Maximum possible score.
     *     @type array  $suggestions Improvement suggestions.
     *     @type array  $details     Detailed metrics.
     * }
     */
    private static function calculate_title_text_score( $title_text, $alt_text, $keywords, $max_score ) {
        $suggestions = [];
        
        // Get dynamic parameters
        $params = self::get_scoring_params();
        $title_params = $params['title'];
        
        // Define optimal ranges from params
        $optimal_min_chars = $title_params['min_chars'];
        $optimal_max_chars = $title_params['max_chars'];
        $optimal_min_words = $title_params['min_words'];
        $optimal_max_words = $title_params['max_words'];
        
        // Title text is optional, so missing it doesn't heavily penalize
        if ( empty( $title_text ) ) {
            return [
                'score' => 0,
                'max_score' => $max_score,
                'suggestions' => [ __( 'Title text is optional but can improve user experience', 'infy-ai-image-seo-optimizer' ) ],
                'details' => [
                    'char_count' => 0,
                    'word_count' => 0,
                    'has_keywords' => false
                ]
            ];
        }
        
        $current_score = 0;
        $char_count = mb_strlen( $title_text, 'UTF-8' );
        $word_count = str_word_count( $title_text );
        $alt_char_count = mb_strlen( $alt_text, 'UTF-8' );
        $has_keywords = false;
        
        // Base score for having title
        $current_score += ( $max_score * ( $title_params['base_score'] / 100 ) );
        
        // Length scoring — tolerance-aware: counts within the play room around
        // the optimal range earn full length credit and trigger no suggestion.
        $chars_ok = self::within_tolerance( self::evaluate_chars( $char_count, $title_params ) );
        $words_ok = self::within_tolerance( self::evaluate_words( $word_count, $title_params ) );

        if ( $chars_ok && $words_ok ) {
            $current_score += ( $max_score * ( $title_params['length_score'] / 100 ) ); // Perfect length
        } else if ( $chars_ok ) {
            $current_score += ( $max_score * ( $title_params['length_score'] / 100 ) * 0.66 ); // Good character count
            if ( $word_count < $optimal_min_words ) {
                $suggestions[] = sprintf(
                    /* translators: 1: Current word count, 2: Minimum word count, 3: Maximum word count */
                    __( 'Title needs more words (%1$d words). Aim for %2$d-%3$d words', 'infy-ai-image-seo-optimizer' ),
                    $word_count,
                    $optimal_min_words,
                    $optimal_max_words
                );
            } else {
                $suggestions[] = sprintf(
                    /* translators: 1: Current word count, 2: Maximum word count */
                    __( 'Title has too many words (%1$d words). Keep under %2$d words', 'infy-ai-image-seo-optimizer' ),
                    $word_count,
                    $optimal_max_words
                );
            }
        } else if ( $words_ok ) {
            $current_score += ( $max_score * ( $title_params['length_score'] / 100 ) * 0.66 ); // Good word count
            if ( $char_count < $optimal_min_chars ) {
                $suggestions[] = sprintf(
                    /* translators: 1: Current character count, 2: Minimum character count, 3: Maximum character count */
                    __( 'Title too short (%1$d chars). Aim for %2$d-%3$d characters', 'infy-ai-image-seo-optimizer' ),
                    $char_count,
                    $optimal_min_chars,
                    $optimal_max_chars
                );
            } else {
                $suggestions[] = sprintf(
                    /* translators: 1: Current character count, 2: Maximum character count */
                    __( 'Title too long (%1$d chars). Keep under %2$d characters', 'infy-ai-image-seo-optimizer' ),
                    $char_count,
                    $optimal_max_chars
                );
            }
        } else {
            $current_score += ( $max_score * ( $title_params['length_score'] / 100 ) * 0.5 ); // Partial credit
            $suggestions[] = sprintf(
                /* translators: 1: Current character count, 2: Current word count, 3: Minimum character count, 4: Maximum character count, 5: Minimum word count, 6: Maximum word count */
                __( 'Title needs adjustment: %1$d chars, %2$d words. Target: %3$d-%4$d chars, %5$d-%6$d words', 'infy-ai-image-seo-optimizer' ),
                $char_count,
                $word_count,
                $optimal_min_chars,
                $optimal_max_chars,
                $optimal_min_words,
                $optimal_max_words
            );
        }
        
        // Check relation to alt text (title should be shorter)
        if ( ! empty( $alt_text ) ) {
            if ( $char_count < $alt_char_count ) {
                $current_score += ( $max_score * ( $title_params['relation_score'] / 100 ) );
            } else {
                $current_score -= ( $max_score * ( $title_params['relation_score'] / 100 ) * 0.5 );
                $suggestions[] = __( 'Title text should be shorter than alt text', 'infy-ai-image-seo-optimizer' );
            }
            
            // Check if it's not duplicate of alt text
            if ( strtolower( trim( $title_text ) ) === strtolower( trim( $alt_text ) ) ) {
                $current_score -= ( $max_score * ( $title_params['relation_score'] / 100 ) );
                $suggestions[] = __( 'Title text should not duplicate alt text', 'infy-ai-image-seo-optimizer' );
            }
        } else {
            // No alt text to compare to, give full relation score
            $current_score += ( $max_score * ( $title_params['relation_score'] / 100 ) );
        }
        
        // Check quality
        if ( preg_match( '/^\d+$/', $title_text ) ) {
            $current_score -= ( $max_score * ( $title_params['keyword_score'] / 100 ) * 0.4 );
            $suggestions[] = __( 'Title text should be descriptive, not just numbers', 'infy-ai-image-seo-optimizer' );
        } else {
            // Check keyword presence
            if ( ! empty( $keywords ) && is_array( $keywords ) ) {
                foreach ( $keywords as $keyword ) {
                    if ( stripos( $title_text, $keyword ) !== false ) {
                        $has_keywords = true;
                        $current_score += ( $max_score * ( $title_params['keyword_score'] / 100 ) );
                        break;
                    }
                }
                
                if ( ! $has_keywords && $word_count > 3 ) {
                    $suggestions[] = __( 'Consider including relevant keywords naturally', 'infy-ai-image-seo-optimizer' );
                }
            } else {
                // No keywords defined, so give credit for this section
                $current_score += ( $max_score * ( $title_params['keyword_score'] / 100 ) );
            }
        }
        
        return [
            'score' => max( 0, min( $max_score, $current_score ) ),
            'max_score' => $max_score,
            'suggestions' => $suggestions,
            'details' => [
                'char_count' => $char_count,
                'word_count' => $word_count,
                'has_keywords' => $has_keywords,
                /* translators: 1: minimum characters, 2: maximum characters */
                'optimal_range' => sprintf( '%1$d-%2$d chars', $optimal_min_chars, $optimal_max_chars )
            ]
        ];
    }
    
    /**
     * Calculate the detailed filename sub-score.
     *
     * Evaluates filename against character count, word count, SEO-friendly
     * format (lowercase + hyphens), generic-name detection, and keyword
     * presence.
     *
     * @since 1.0.0
     * @access private
     *
     * @param string $filename  The filename (without extension) to evaluate.
     * @param array  $keywords  SEO keywords for the image.
     * @param float  $max_score Maximum possible score for this component.
     * @return array {
     *     @type float  $score       Earned score.
     *     @type float  $max_score   Maximum possible score.
     *     @type array  $suggestions Improvement suggestions.
     *     @type array  $details     Detailed metrics.
     * }
     */
    private static function calculate_filename_score( $filename, $keywords, $max_score ) {
        $suggestions = [];
        
        // Get dynamic parameters
        $params = self::get_scoring_params();
        $filename_params = $params['filename'];
        
        // Define optimal ranges from params
        $optimal_min_chars = $filename_params['min_chars'];
        $optimal_max_chars = $filename_params['max_chars'];
        $optimal_min_words = $filename_params['min_words'];
        $optimal_max_words = $filename_params['max_words'];
        
        if ( empty( $filename ) ) {
            $suggestions[] = __( 'Filename is missing', 'infy-ai-image-seo-optimizer' );
            return [
                'score' => 0,
                'max_score' => $max_score,
                'suggestions' => $suggestions,
                'details' => [
                    'char_count' => 0,
                    'word_count' => 0,
                    'seo_friendly' => false,
                    'has_keywords' => false
                ]
            ];
        }
        
        $current_score = 0;
        $char_count = mb_strlen( $filename, 'UTF-8' );
        $word_count = count( explode( '-', $filename ) );
        $has_keywords = false;
        $seo_friendly = true;
        
        // Base score for having a filename
        $current_score += ( $max_score * ( $filename_params['base_score'] / 100 ) );
        
        // Check for SEO-friendly format (lowercase, hyphens, no special chars)
        if ( preg_match( '/^[a-z0-9\-]+$/', $filename ) ) {
            $current_score += ( $max_score * ( $filename_params['format_score'] / 100 ) );
        } else {
            $seo_friendly = false;
            $suggestions[] = __( 'Use lowercase letters, numbers, and hyphens only', 'infy-ai-image-seo-optimizer' );
            
            // Check specific issues
            if ( preg_match( '/[A-Z]/', $filename ) ) {
                $suggestions[] = __( 'Convert uppercase letters to lowercase', 'infy-ai-image-seo-optimizer' );
            }
            if ( preg_match( '/_/', $filename ) ) {
                $suggestions[] = __( 'Replace underscores with hyphens', 'infy-ai-image-seo-optimizer' );
            }
            if ( preg_match( '/\s/', $filename ) ) {
                $suggestions[] = __( 'Remove spaces (use hyphens instead)', 'infy-ai-image-seo-optimizer' );
            }
        }
        
        // Check length — tolerance-aware: play room around the optimal range
        // still earns full length credit and triggers no suggestion.
        if ( self::within_tolerance( self::evaluate_chars( $char_count, $filename_params ) ) ) {
            $current_score += ( $max_score * ( $filename_params['length_score'] / 100 ) );
        } else {
            if ( $char_count < 10 ) {
                $current_score -= ( $max_score * ( $filename_params['length_score'] / 100 ) * 0.5 );
                $suggestions[] = sprintf(
                    /* translators: 1: Current character count, 2: Minimum character count, 3: Maximum character count */
                    __( 'Filename too short (%1$d chars). Aim for %2$d-%3$d characters', 'infy-ai-image-seo-optimizer' ),
                    $char_count,
                    $optimal_min_chars,
                    $optimal_max_chars
                );
            } elseif ( $char_count > 100 ) {
                $current_score -= ( $max_score * ( $filename_params['length_score'] / 100 ) * 0.75 );
                $suggestions[] = sprintf(
                    /* translators: 1: Current character count, 2: Maximum character count */
                    __( 'Filename too long (%1$d chars). Keep under %2$d characters', 'infy-ai-image-seo-optimizer' ),
                    $char_count,
                    $optimal_max_chars
                );
            } else {
                $current_score += ( $max_score * ( $filename_params['length_score'] / 100 ) * 0.5 ); // Partial credit
            }
        }
        
        // Check for generic camera filenames
        if ( preg_match( '/^(img|dsc|image|photo|pic|dsc_|img_)[\-_]?\d+$/i', $filename ) ) {
            $current_score -= ( $max_score * ( $filename_params['descriptive_score'] / 100 ) );
            $suggestions[] = __( 'Replace generic filename with descriptive keywords', 'infy-ai-image-seo-optimizer' );
        } else {
            // Check for descriptive content
            if ( self::within_tolerance( self::evaluate_words( $word_count, $filename_params ) ) ) {
                $current_score += ( $max_score * ( $filename_params['descriptive_score'] / 100 ) );
            } elseif ( $word_count < 2 ) {
                $suggestions[] = sprintf(
                    /* translators: 1: Minimum word count, 2: Maximum word count */
                    __( 'Add more descriptive words (%1$d-%2$d words ideal)', 'infy-ai-image-seo-optimizer' ),
                    $optimal_min_words,
                    $optimal_max_words
                );
            }
            
            // Check keyword presence
            if ( ! empty( $keywords ) && is_array( $keywords ) ) {
                foreach ( $keywords as $keyword ) {
                    if ( stripos( $filename, str_replace( ' ', '-', strtolower( $keyword ) ) ) !== false ) {
                        $has_keywords = true;
                        $current_score += ( $max_score * ( $filename_params['keyword_score'] / 100 ) );
                        break;
                    }
                }
                
                if ( ! $has_keywords ) {
                    $suggestions[] = __( 'Consider including relevant keywords in filename', 'infy-ai-image-seo-optimizer' );
                }
            } else {
                $current_score += ( $max_score * ( $filename_params['keyword_score'] / 100 ) ); // No keywords to check
            }
        }
        
        return [
            'score' => max( 0, min( $max_score, $current_score ) ),
            'max_score' => $max_score,
            'suggestions' => $suggestions,
            'details' => [
                'char_count' => $char_count,
                'word_count' => $word_count,
                'seo_friendly' => $seo_friendly,
                'has_keywords' => $has_keywords,
                /* translators: 1: minimum characters, 2: maximum characters */
                'optimal_range' => sprintf( '%1$d-%2$d chars', $optimal_min_chars, $optimal_max_chars )
            ]
        ];
    }
    
    /**
     * Check for keyword stuffing patterns in a given text.
     *
     * Detects excessive repetition of non-stop-words by comparing individual
     * word frequency against a threshold ratio (>2 occurrences or >20% of total).
     *
     * @since 1.0.0
     * @access private
     *
     * @param string $text The text to analyze for keyword stuffing.
     * @return bool True if keyword stuffing is detected, false otherwise.
     */
    private static function has_keyword_stuffing( $text ) {
        $words = str_word_count( strtolower( $text ), 1 );
        $word_count = count( $words );
        
        if ( $word_count < 5 ) {
            return false;
        }
        
        // Check for repeated words (keyword stuffing indicator)
        $word_frequency = array_count_values( $words );
        foreach ( $word_frequency as $word => $count ) {
            // Skip common words
            if ( in_array( $word, ['the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by'] ) ) {
                continue;
            }
            
            // If any word appears more than 2 times or more than 20% of total words
            if ( $count > 2 || ( $count / $word_count ) > 0.2 ) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Save custom scoring weights.
     *
     * Accepts weights in either UI format (combined alt_text, title_text keys) or
     * backend 7-weight format, validates and normalises them to total 100%, then
     * persists to the database.
     *
     * @since 1.0.0
     *
     * @param array $weights Associative array of weight values to save.
     * @return array Saved weights in UI format for the AJAX response.
     */
    public static function save_weights( $weights ) {
        $valid_weights = [];
        
        // Check if we're receiving UI weights (has alt_text, filename, or title_text keys)
        if ( isset( $weights['alt_text'] ) || isset( $weights['filename'] ) || isset( $weights['title_text'] ) ) {
            // Convert UI weights to backend format
            $weights = self::convert_legacy_weights( $weights );
        }
        
        // Validate and sanitize weights
        foreach ( self::$default_weights as $key => $default ) {
            if ( isset( $weights[$key] ) && is_numeric( $weights[$key] ) ) {
                $valid_weights[$key] = max( 0, min( 100, intval( $weights[$key] ) ) );
            } else {
                // Use default if not set
                $valid_weights[$key] = $default;
            }
        }
        
        // Ensure weights add up to 100
        $total = array_sum( $valid_weights );
        if ( $total !== 100 && $total > 0 ) {
            // Normalize to 100
            foreach ( $valid_weights as $key => $value ) {
                $valid_weights[$key] = round( ( $value / $total ) * 100 );
            }
            
            // Handle rounding errors
            $diff = 100 - array_sum( $valid_weights );
            if ( $diff !== 0 && isset( $valid_weights['alt_presence'] ) ) {
                $valid_weights['alt_presence'] += $diff;
            }
        }
        
        update_option( 'infyp_seo_weights', $valid_weights );
        
        // Return weights in UI format for the AJAX response
        return [
            'alt_text' => $valid_weights['alt_presence'] + $valid_weights['alt_quality'],
            'filename' => $valid_weights['filename_quality'],
            'title_text' => $valid_weights['title_presence'] + $valid_weights['title_quality'],
            'keyword_density' => $valid_weights['keyword_density'],
            'uniqueness' => $valid_weights['uniqueness']
        ];
    }
    
    /**
     * Reset scoring weights to their default values.
     *
     * Deletes the custom weights option from the database and returns
     * the default weights in UI format.
     *
     * @since 1.0.0
     *
     * @return array Default weights in UI format for the AJAX response.
     */
    public static function reset_weights() {
        delete_option( 'infyp_seo_weights' );

        // Return weights in UI format for the AJAX response
        return [
            'alt_text' => 35,         // alt_presence (20) + alt_quality (15)
            'filename' => 15,         // filename_quality
            'title_text' => 25,       // title_presence (15) + title_quality (10)
            'keyword_density' => 15,  // keyword_density
            'uniqueness' => 10        // uniqueness
        ];
    }

    /**
     * Option storing the time cached scores were last invalidated.
     *
     * @since 1.0.6
     * @var   string
     */
    const STALE_OPTION = 'infyp_scores_stale_since';

    /**
     * Length-bound keys present in every scoring parameter section.
     *
     * @since 1.0.6
     * @var   string[]
     */
    private static $bound_keys = [ 'min_chars', 'max_chars', 'min_words', 'max_words' ];

    /**
     * Tolerance keys present in every scoring parameter section, with their
     * allowed maximum (minimum is always 0; 0 = strict, no play room).
     *
     * @since 1.0.6
     * @var   array<string, int>
     */
    private static $tolerance_keys = [
        'tolerance_words'     => 5,
        'tolerance_chars_pct' => 50,
    ];

    /**
     * Get the default scoring weights.
     *
     * @since 1.0.6
     *
     * @return array<string, int>
     */
    public static function get_default_weights() {
        return self::$default_weights;
    }

    /**
     * Get the default scoring parameters.
     *
     * @since 1.0.6
     *
     * @return array<string, array<string, int>>
     */
    public static function get_default_scoring_params() {
        return self::$default_params;
    }

    /**
     * Clear the per-request static caches.
     *
     * Call after persisting weights or parameters so later reads in the same
     * request see the new values.
     *
     * @since 1.0.6
     *
     * @return void
     */
    public static function reset_cache() {
        self::$cached_weights = null;
        self::$cached_params  = null;
    }

    /**
     * Report whether weights or parameters deviate from the defaults.
     *
     * @since 1.0.6
     *
     * @return array{weights: bool, parameters: bool}
     */
    public static function is_customized() {
        return [
            'weights'    => [] !== (array) get_option( 'infyp_seo_weights', [] ),
            'parameters' => [] !== (array) get_option( 'infyp_scoring_parameters', [] ),
        ];
    }

    /**
     * Validate a complete set of scoring weights.
     *
     * Every default key must be present, each value must be an integer in
     * 0-100, and the set must total exactly 100. Unlike save_weights(), which
     * rescales to fit, this rejects rather than silently altering the input.
     *
     * @since 1.0.6
     *
     * @param array $weights Weights keyed by component name.
     * @return true|\WP_Error True when valid, WP_Error describing the first problem otherwise.
     */
    public static function validate_weights( $weights ) {
        if ( ! is_array( $weights ) ) {
            return new \WP_Error(
                'infyp_invalid_weights',
                __( 'Weights must be supplied as an object.', 'infy-ai-image-seo-optimizer' ),
                [ 'status' => 400 ]
            );
        }

        $unknown = array_diff( array_keys( $weights ), array_keys( self::$default_weights ) );
        if ( $unknown ) {
            return new \WP_Error(
                'infyp_unknown_weight',
                sprintf(
                    /* translators: %s: Comma-separated list of unrecognised weight names. */
                    __( 'Unknown weight(s): %s.', 'infy-ai-image-seo-optimizer' ),
                    implode( ', ', $unknown )
                ),
                [ 'status' => 400 ]
            );
        }

        foreach ( self::$default_weights as $key => $unused_default ) {
            if ( ! isset( $weights[ $key ] ) || ! is_numeric( $weights[ $key ] ) ) {
                return new \WP_Error(
                    'infyp_missing_weight',
                    sprintf(
                        /* translators: %s: Weight name. */
                        __( 'Missing or non-numeric weight: %s.', 'infy-ai-image-seo-optimizer' ),
                        $key
                    ),
                    [ 'status' => 400 ]
                );
            }

            $value = (int) $weights[ $key ];
            if ( $value < 0 || $value > 100 ) {
                return new \WP_Error(
                    'infyp_weight_out_of_range',
                    sprintf(
                        /* translators: %s: Weight name. */
                        __( 'Weight %s must be between 0 and 100.', 'infy-ai-image-seo-optimizer' ),
                        $key
                    ),
                    [ 'status' => 400 ]
                );
            }
        }

        $total = 0;
        foreach ( self::$default_weights as $key => $unused_default ) {
            $total += (int) $weights[ $key ];
        }

        if ( 100 !== $total ) {
            return new \WP_Error(
                'infyp_weights_total',
                sprintf(
                    /* translators: %d: The total the submitted weights add up to. */
                    __( 'Weights must total exactly 100. The submitted values total %d.', 'infy-ai-image-seo-optimizer' ),
                    $total
                ),
                [ 'status' => 400 ]
            );
        }

        return true;
    }

    /**
     * Validate a complete set of scoring parameters.
     *
     * Each section must contain every key its defaults define, length bounds
     * must be positive with min below max, and the section's score components
     * must total exactly 100.
     *
     * @since 1.0.6
     *
     * @param array $params Parameters keyed by section name.
     * @return true|\WP_Error True when valid, WP_Error describing the first problem otherwise.
     */
    public static function validate_scoring_params( $params ) {
        if ( ! is_array( $params ) ) {
            return new \WP_Error(
                'infyp_invalid_params',
                __( 'Scoring parameters must be supplied as an object.', 'infy-ai-image-seo-optimizer' ),
                [ 'status' => 400 ]
            );
        }

        $unknown_sections = array_diff( array_keys( $params ), array_keys( self::$default_params ) );
        if ( $unknown_sections ) {
            return new \WP_Error(
                'infyp_unknown_section',
                sprintf(
                    /* translators: %s: Comma-separated list of unrecognised section names. */
                    __( 'Unknown scoring section(s): %s.', 'infy-ai-image-seo-optimizer' ),
                    implode( ', ', $unknown_sections )
                ),
                [ 'status' => 400 ]
            );
        }

        foreach ( self::$default_params as $section => $defaults ) {
            if ( ! isset( $params[ $section ] ) || ! is_array( $params[ $section ] ) ) {
                return new \WP_Error(
                    'infyp_missing_section',
                    sprintf(
                        /* translators: %s: Scoring section name. */
                        __( 'Missing scoring section: %s.', 'infy-ai-image-seo-optimizer' ),
                        $section
                    ),
                    [ 'status' => 400 ]
                );
            }

            $section_values = $params[ $section ];

            $unknown_keys = array_diff( array_keys( $section_values ), array_keys( $defaults ) );
            if ( $unknown_keys ) {
                return new \WP_Error(
                    'infyp_unknown_param',
                    sprintf(
                        /* translators: 1: Scoring section name, 2: Comma-separated list of unrecognised keys. */
                        __( 'Unknown key(s) in section %1$s: %2$s.', 'infy-ai-image-seo-optimizer' ),
                        $section,
                        implode( ', ', $unknown_keys )
                    ),
                    [ 'status' => 400 ]
                );
            }

            $score_total = 0;

            foreach ( $defaults as $key => $unused_default ) {
                if ( ! isset( $section_values[ $key ] ) || ! is_numeric( $section_values[ $key ] ) ) {
                    return new \WP_Error(
                        'infyp_missing_param',
                        sprintf(
                            /* translators: 1: Scoring section name, 2: Parameter key. */
                            __( 'Missing or non-numeric value for %1$s.%2$s.', 'infy-ai-image-seo-optimizer' ),
                            $section,
                            $key
                        ),
                        [ 'status' => 400 ]
                    );
                }

                if ( 'auto_link' === $key ) {
                    continue;
                }

                $value = (float) $section_values[ $key ];

                if ( isset( self::$tolerance_keys[ $key ] ) ) {
                    if ( $value < 0 || $value > self::$tolerance_keys[ $key ] ) {
                        return new \WP_Error(
                            'infyp_param_out_of_range',
                            sprintf(
                                /* translators: 1: Scoring section name, 2: Parameter key, 3: Maximum allowed value. */
                                __( '%1$s.%2$s must be between 0 and %3$d.', 'infy-ai-image-seo-optimizer' ),
                                $section,
                                $key,
                                self::$tolerance_keys[ $key ]
                            ),
                            [ 'status' => 400 ]
                        );
                    }
                    continue;
                }

                if ( in_array( $key, self::$bound_keys, true ) ) {
                    if ( $value < 1 ) {
                        return new \WP_Error(
                            'infyp_param_out_of_range',
                            sprintf(
                                /* translators: 1: Scoring section name, 2: Parameter key. */
                                __( '%1$s.%2$s must be a positive number.', 'infy-ai-image-seo-optimizer' ),
                                $section,
                                $key
                            ),
                            [ 'status' => 400 ]
                        );
                    }
                    continue;
                }

                if ( $value < 0 || $value > 100 ) {
                    return new \WP_Error(
                        'infyp_param_out_of_range',
                        sprintf(
                            /* translators: 1: Scoring section name, 2: Parameter key. */
                            __( '%1$s.%2$s must be between 0 and 100.', 'infy-ai-image-seo-optimizer' ),
                            $section,
                            $key
                        ),
                        [ 'status' => 400 ]
                    );
                }

                $score_total += $value;
            }

            if ( (int) $section_values['min_chars'] >= (int) $section_values['max_chars'] ) {
                return new \WP_Error(
                    'infyp_param_bounds',
                    sprintf(
                        /* translators: %s: Scoring section name. */
                        __( 'In section %s, min_chars must be lower than max_chars.', 'infy-ai-image-seo-optimizer' ),
                        $section
                    ),
                    [ 'status' => 400 ]
                );
            }

            if ( (int) $section_values['min_words'] >= (int) $section_values['max_words'] ) {
                return new \WP_Error(
                    'infyp_param_bounds',
                    sprintf(
                        /* translators: %s: Scoring section name. */
                        __( 'In section %s, min_words must be lower than max_words.', 'infy-ai-image-seo-optimizer' ),
                        $section
                    ),
                    [ 'status' => 400 ]
                );
            }

            if ( abs( $score_total - 100 ) > 0.01 ) {
                return new \WP_Error(
                    'infyp_param_total',
                    sprintf(
                        /* translators: 1: Scoring section name, 2: The total the submitted score components add up to. */
                        __( 'Score components in section %1$s must total exactly 100. The submitted values total %2$s.', 'infy-ai-image-seo-optimizer' ),
                        $section,
                        rtrim( rtrim( number_format( $score_total, 2, '.', '' ), '0' ), '.' )
                    ),
                    [ 'status' => 400 ]
                );
            }
        }

        return true;
    }

    /**
     * Persist a validated set of weights exactly as supplied.
     *
     * Unlike save_weights(), no rescaling happens — pass the result of
     * validate_weights() first.
     *
     * @since 1.0.6
     *
     * @param array $weights Validated weights.
     * @return array The stored weights.
     */
    public static function persist_weights( $weights ) {
        $stored = [];
        foreach ( self::$default_weights as $key => $unused_default ) {
            $stored[ $key ] = (int) $weights[ $key ];
        }

        update_option( 'infyp_seo_weights', $stored );
        self::reset_cache();

        return $stored;
    }

    /**
     * Persist a validated set of scoring parameters.
     *
     * @since 1.0.6
     *
     * @param array $params Validated parameters.
     * @return array The stored parameters.
     */
    public static function save_scoring_params( $params ) {
        $stored = [];

        foreach ( self::$default_params as $section => $defaults ) {
            foreach ( $defaults as $key => $unused_default ) {
                $value = $params[ $section ][ $key ];

                if ( 'auto_link' === $key ) {
                    $stored[ $section ][ $key ] = (int) (bool) $value;
                    continue;
                }

                $stored[ $section ][ $key ] = ( in_array( $key, self::$bound_keys, true ) || 'tolerance_words' === $key )
                    ? (int) $value
                    : (float) $value;
            }
        }

        update_option( 'infyp_scoring_parameters', $stored );
        self::reset_cache();

        return $stored;
    }

    /**
     * Reset scoring parameters to their default values.
     *
     * @since 1.0.6
     *
     * @return array The default parameters.
     */
    public static function reset_scoring_params() {
        delete_option( 'infyp_scoring_parameters' );
        self::reset_cache();

        return self::$default_params;
    }

    /**
     * Discard every cached SEO score and queue a background recalculation.
     *
     * Scores are cached in `_infyp_seo_score` post meta and are only computed
     * when missing, so a change to weights or parameters would otherwise leave
     * every stored score reflecting the previous configuration indefinitely.
     * Deleting the meta returns those images to the "not yet computed" state
     * that the infyp_batch_cache_scores cron already backfills in batches.
     *
     * Reads report a null score until the backfill reaches each image.
     *
     * @since 1.0.6
     *
     * @return int Number of cached scores discarded.
     */
    public static function invalidate_cached_scores() {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Counting rows about to be deleted; no cache to consult.
        $count = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", '_infyp_seo_score' )
        );

        if ( $count > 0 ) {
            delete_post_meta_by_key( '_infyp_seo_score' );
            update_option( self::STALE_OPTION, current_time( 'mysql' ) );

            if ( ! wp_next_scheduled( 'infyp_batch_cache_scores' ) ) {
                wp_schedule_single_event( time() + 5, 'infyp_batch_cache_scores' );
            }
        }

        /**
         * Fires after cached SEO scores are discarded.
         *
         * @since 1.0.6
         *
         * @param int $count Number of cached scores discarded.
         */
        do_action( 'infyp_scores_invalidated', $count );

        return $count;
    }

    /**
     * Get the time cached scores were last invalidated.
     *
     * @since 1.0.6
     *
     * @return string|null MySQL datetime, or null when never invalidated.
     */
    public static function get_scores_stale_since() {
        $value = get_option( self::STALE_OPTION, '' );

        return '' === $value ? null : (string) $value;
    }
}