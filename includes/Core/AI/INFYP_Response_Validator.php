<?php
/**
 * AI Response Validation for SEO Data
 *
 * This file contains the INFYP_Response_Validator class which provides
 * validation methods for AI-generated SEO responses. It validates structure,
 * field types, character lengths, word counts, and filename formats.
 *
 * Extracted from INFYP_AI_Optimizer for better separation of concerns.
 *
 * @package    AI_Image_SEO
 * @subpackage Core\AI
 * @since 1.0.0
 * @author     InfyPress
 * @license    GPL-2.0+
 */

namespace IPAIS\Core\AI;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use IPAIS\Core\INFYP_Logger;
use IPAIS\Core\INFYP_SEO_Score_Calculator;

/**
 * Static utility class for validating AI-generated SEO responses.
 *
 * @since 1.0.0
 */
class INFYP_Response_Validator {

    /**
     * Validates that the SEO response has the correct structure.
     *
     * @since 1.0.0
     * @param mixed $response The response to validate.
     * @param bool $expect_keywords Whether to expect keywords in the response (default true).
     * @return bool True if valid, false otherwise.
     */
    public static function validate_seo_response($response, $expect_keywords = true) {
        if (!is_array($response)) {
            return false;
        }

        // Check that all required keys exist
        $required_keys = ['alt_text', 'title', 'filename'];
        if ($expect_keywords) {
            $required_keys[] = 'keywords';
        }

        foreach ($required_keys as $key) {
            if (!isset($response[$key])) {
                return false;
            }

            // String fields
            if ($key !== 'keywords' && (!is_string($response[$key]) || empty(trim($response[$key])))) {
                return false;
            }

            // Keywords array - be more flexible
            if ($key === 'keywords' && !is_array($response[$key])) {
                return false;
            }
        }

        // Validate keywords are strings and we have at least 1
        if (isset($response['keywords'])) {
            $valid_keywords = 0;
            foreach ($response['keywords'] as $keyword) {
                if (is_string($keyword) && !empty(trim($keyword))) {
                    $valid_keywords++;
                }
            }
            if ($valid_keywords < 1) {
                return false;
            }
        }

        // Don't check for ONLY required keys - allow extra fields
        // Just ensure we have all required ones

        // Get scoring params and apply 25% relaxation for validation bounds.
        // The scoring params define the "ideal" range; the validator uses relaxed
        // bounds so valid-but-not-perfect responses aren't rejected outright.
        $scoring_params = INFYP_SEO_Score_Calculator::get_scoring_params();

        $alt_min_chars  = (int) floor($scoring_params['alt_text']['min_chars'] * 0.75);
        $alt_max_chars  = (int) ceil($scoring_params['alt_text']['max_chars'] * 1.25);
        $alt_min_words  = (int) floor($scoring_params['alt_text']['min_words'] * 0.75);
        $alt_max_words  = (int) ceil($scoring_params['alt_text']['max_words'] * 1.25);

        $title_min_chars = (int) floor($scoring_params['title']['min_chars'] * 0.75);
        $title_max_chars = (int) ceil($scoring_params['title']['max_chars'] * 1.25);
        $title_min_words = (int) floor($scoring_params['title']['min_words'] * 0.75);
        $title_max_words = (int) ceil($scoring_params['title']['max_words'] * 1.25);

        $filename_min_chars = (int) floor($scoring_params['filename']['min_chars'] * 0.75);
        $filename_max_chars = (int) ceil($scoring_params['filename']['max_chars'] * 1.25);
        $filename_min_words = (int) floor($scoring_params['filename']['min_words'] * 0.75);
        $filename_max_words = (int) ceil($scoring_params['filename']['max_words'] * 1.25);

        // Validate character lengths
        $alt_text_len = mb_strlen($response['alt_text'], 'UTF-8');
        $title_len = mb_strlen($response['title'], 'UTF-8');
        $filename_len = mb_strlen($response['filename'], 'UTF-8');

        if ($alt_text_len < $alt_min_chars || $alt_text_len > $alt_max_chars) {
            return false;
        }

        if ($title_len < $title_min_chars || $title_len > $title_max_chars) {
            return false;
        }

        if ($filename_len < $filename_min_chars || $filename_len > $filename_max_chars) {
            return false;
        }

        // Validate word counts
        $alt_words = count( preg_split( '/[\s\p{Z}]+/u', trim( $response['alt_text'] ), -1, PREG_SPLIT_NO_EMPTY ) );
        $title_words = count( preg_split( '/[\s\p{Z}]+/u', trim( $response['title'] ), -1, PREG_SPLIT_NO_EMPTY ) );
        $filename_words = count(explode('-', $response['filename']));

        if ($alt_words < $alt_min_words || $alt_words > $alt_max_words) {
            return false;
        }

        if ($title_words < $title_min_words || $title_words > $title_max_words) {
            return false;
        }

        if ($filename_words < $filename_min_words || $filename_words > $filename_max_words) {
            return false;
        }

        // Validate filename format (lowercase letters, numbers, hyphens only)
        if (!preg_match('/^[a-z0-9-]+$/', $response['filename'])) {
            return false;
        }

        return true;
    }

    /**
     * Enhanced validation with detailed feedback for debugging and retry logic.
     *
     * @since 1.0.0
     * @param array $response The response to validate.
     * @param bool $expect_keywords Whether to expect keywords in the response (default true).
     * @return array Array with 'valid' boolean and 'errors' array with detailed feedback.
     */
    public static function validate_seo_response_detailed($response, $expect_keywords = true) {
        $errors = [];

        if (!is_array($response)) {
            return ['valid' => false, 'errors' => ['Response is not an array']];
        }

        // Check that all required keys exist
        $required_keys = ['alt_text', 'title', 'filename'];
        if ($expect_keywords) {
            $required_keys[] = 'keywords';
        }

        foreach ($required_keys as $key) {
            if (!isset($response[$key])) {
                $errors[] = "Missing required field: {$key}";
                continue;
            }

            // String fields
            if ($key !== 'keywords' && (!is_string($response[$key]) || empty(trim($response[$key])))) {
                $errors[] = "Field {$key} must be a non-empty string";
                continue;
            }

            // Keywords array - be more flexible
            if ($key === 'keywords' && !is_array($response[$key])) {
                $errors[] = "Keywords must be an array";
                continue;
            }
        }

        // Sanitize filename to ensure ASCII-only BEFORE validation
        // This handles cases where AI returns accented characters or doesn't properly transliterate
        if (isset($response['filename']) && is_string($response['filename'])) {
            $original_filename = $response['filename'];

            // Step 1: Remove accents (café → cafe, montaña → montana)
            $response['filename'] = remove_accents($response['filename']);

            // Step 2: Convert to lowercase ASCII slug (handles Cyrillic, Chinese, etc.)
            $response['filename'] = sanitize_title_with_dashes($response['filename']);

            // Step 3: Ensure lowercase
            $response['filename'] = strtolower($response['filename']);

            // Step 4: Final cleanup - remove any remaining non-ASCII characters
            $response['filename'] = preg_replace('/[^a-z0-9-]/', '-', $response['filename']);
            $response['filename'] = preg_replace('/-+/', '-', $response['filename']);
            $response['filename'] = trim($response['filename'], '-');

            // Step 5: If sanitization resulted in empty/very short filename, generate fallback
            if (empty($response['filename']) || strlen($response['filename']) < 10) {
                // Use a generic but descriptive fallback that meets minimum length (40 chars)
                $hash = substr(md5($original_filename), 0, 20);
                $response['filename'] = 'optimized-image-seo-' . $hash;

                // Log this for debugging
                INFYP_Logger::log(sprintf(
                    'Filename sanitization produced empty result. Original: "%s", Using fallback: "%s"',
                    $original_filename,
                    $response['filename']
                ), 'WARNING');
            }
        }

        // Early return if structure is invalid
        if (!empty($errors)) {
            return ['valid' => false, 'errors' => $errors];
        }

        // Get scoring params and apply 25% relaxation for validation bounds.
        // The scoring params define the "ideal" range; the validator uses relaxed
        // bounds so valid-but-not-perfect responses aren't rejected outright.
        $scoring_params = INFYP_SEO_Score_Calculator::get_scoring_params();

        $alt_min_chars  = (int) floor($scoring_params['alt_text']['min_chars'] * 0.75);
        $alt_max_chars  = (int) ceil($scoring_params['alt_text']['max_chars'] * 1.25);
        $alt_min_words  = (int) floor($scoring_params['alt_text']['min_words'] * 0.75);
        $alt_max_words  = (int) ceil($scoring_params['alt_text']['max_words'] * 1.25);

        $title_min_chars = (int) floor($scoring_params['title']['min_chars'] * 0.75);
        $title_max_chars = (int) ceil($scoring_params['title']['max_chars'] * 1.25);
        $title_min_words = (int) floor($scoring_params['title']['min_words'] * 0.75);
        $title_max_words = (int) ceil($scoring_params['title']['max_words'] * 1.25);

        $filename_min_chars = (int) floor($scoring_params['filename']['min_chars'] * 0.75);
        $filename_max_chars = (int) ceil($scoring_params['filename']['max_chars'] * 1.25);
        $filename_min_words = (int) floor($scoring_params['filename']['min_words'] * 0.75);
        $filename_max_words = (int) ceil($scoring_params['filename']['max_words'] * 1.25);

        // Validate character lengths
        $alt_text_len = mb_strlen($response['alt_text'], 'UTF-8');
        $title_len = mb_strlen($response['title'], 'UTF-8');
        $filename_len = mb_strlen($response['filename'], 'UTF-8');

        if ($alt_text_len < $alt_min_chars) {
            $errors[] = sprintf("Alt text too short: minimum %d chars (got %d)", $alt_min_chars, $alt_text_len);
        } elseif ($alt_text_len > $alt_max_chars) {
            $errors[] = sprintf("Alt text too long: maximum %d chars (got %d)", $alt_max_chars, $alt_text_len);
        }

        if ($title_len < $title_min_chars) {
            $errors[] = sprintf("Title too short: minimum %d chars (got %d)", $title_min_chars, $title_len);
        } elseif ($title_len > $title_max_chars) {
            $errors[] = sprintf("Title too long: maximum %d chars (got %d)", $title_max_chars, $title_len);
        }

        if ($filename_len < $filename_min_chars) {
            $errors[] = sprintf("Filename too short: minimum %d chars (got %d)", $filename_min_chars, $filename_len);
        } elseif ($filename_len > $filename_max_chars) {
            $errors[] = sprintf("Filename too long: maximum %d chars (got %d)", $filename_max_chars, $filename_len);
        }

        // Validate word counts
        $alt_words = count( preg_split( '/[\s\p{Z}]+/u', trim( $response['alt_text'] ), -1, PREG_SPLIT_NO_EMPTY ) );
        $title_words = count( preg_split( '/[\s\p{Z}]+/u', trim( $response['title'] ), -1, PREG_SPLIT_NO_EMPTY ) );
        $filename_words = count(explode('-', $response['filename']));

        if ($alt_words < $alt_min_words) {
            $errors[] = sprintf("Alt text too few words: minimum %d words (got %d)", $alt_min_words, $alt_words);
        } elseif ($alt_words > $alt_max_words) {
            $errors[] = sprintf("Alt text too many words: maximum %d words (got %d)", $alt_max_words, $alt_words);
        }

        if ($title_words < $title_min_words) {
            $errors[] = sprintf("Title too few words: minimum %d words (got %d)", $title_min_words, $title_words);
        } elseif ($title_words > $title_max_words) {
            $errors[] = sprintf("Title too many words: maximum %d words (got %d)", $title_max_words, $title_words);
        }

        if ($filename_words < $filename_min_words) {
            $errors[] = sprintf("Filename too few segments: minimum %d hyphen-separated words (got %d)", $filename_min_words, $filename_words);
        } elseif ($filename_words > $filename_max_words) {
            $errors[] = sprintf("Filename too many segments: maximum %d hyphen-separated words (got %d)", $filename_max_words, $filename_words);
        }

        // Validate keywords array contents - at least 1 valid keyword
        if (isset($response['keywords'])) {
            $valid_keywords = 0;
            foreach ($response['keywords'] as $i => $keyword) {
                if (is_string($keyword) && !empty(trim($keyword))) {
                    $valid_keywords++;
                } else {
                    $errors[] = sprintf("Keyword %d must be a non-empty string", $i + 1);
                }
            }
            if ($valid_keywords < 1) {
                $errors[] = "Must have at least 1 valid keyword";
            }
        }

        // Validate filename format (lowercase letters, numbers, hyphens only)
        if (!preg_match('/^[a-z0-9-]+$/', $response['filename'])) {
            $errors[] = "Filename must contain only lowercase letters, numbers, and hyphens";
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'stats' => [
                'alt_text' => ['chars' => $alt_text_len, 'words' => $alt_words],
                'title' => ['chars' => $title_len, 'words' => $title_words],
                'filename' => ['chars' => $filename_len, 'words' => $filename_words],
                'keywords' => ['count' => isset($response['keywords']) ? count($response['keywords']) : 0]
            ]
        ];
    }
}
