<?php
/**
 * AI Response Cleaning and Normalization
 *
 * This file contains the INFYP_Response_Cleaner class which provides
 * methods for cleaning and extracting valid data from potentially malformed
 * AI responses across all supported providers (OpenAI, Google Gemini, Anthropic Claude).
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

/**
 * Static utility class for cleaning and normalizing AI-generated SEO responses.
 *
 * @since 1.0.0
 */
class INFYP_Response_Cleaner {

    /**
     * Attempts to clean and extract valid data from a malformed GPT response.
     *
     * @since 1.0.0
     * @param mixed $response The potentially malformed response.
     * @return array|null Cleaned response or null if unrecoverable.
     */
    public static function clean_gpt_response($response) {
        if (!is_array($response)) {
            return null;
        }

        $cleaned = [];

        // Try to find the required fields even if they're nested or have different keys
        $alt_text_keys = ['alt_text', 'altText', 'alt', 'alternative_text'];
        $title_keys = ['title', 'image_title', 'imageTitle'];
        $filename_keys = ['filename', 'file_name', 'slug'];
        $keywords_keys = ['keywords', 'tags', 'seo_keywords'];

        foreach ($alt_text_keys as $key) {
            if (isset($response[$key]) && is_string($response[$key])) {
                $cleaned['alt_text'] = trim($response[$key]);
                break;
            }
        }

        foreach ($title_keys as $key) {
            if (isset($response[$key]) && is_string($response[$key])) {
                $cleaned['title'] = trim($response[$key]);
                break;
            }
        }

        foreach ($filename_keys as $key) {
            if (isset($response[$key]) && is_string($response[$key])) {
                // Clean the filename
                $filename = strtolower(trim($response[$key]));
                // Strip image extensions before char sanitization so .jpg doesn't become -jpg.
                $filename = \IPAIS\Core\INFYP_File_Renamer::strip_file_extension( $filename );
                $filename = preg_replace('/[^a-z0-9-]/', '-', $filename);
                $filename = preg_replace('/-+/', '-', $filename);
                $filename = trim($filename, '-');
                $cleaned['filename'] = $filename;
                break;
            }
        }

        // Try to find keywords array
        /** This filter is documented in includes/Ajax/INFYP_AI_Ajax.php */
        $max_keywords = apply_filters('infyp_max_keywords', 3);

        foreach ($keywords_keys as $key) {
            if (isset($response[$key]) && is_array($response[$key])) {
                $keywords = [];
                foreach ($response[$key] as $keyword) {
                    if (is_string($keyword) && !empty(trim($keyword))) {
                        $keywords[] = trim($keyword);
                        if (count($keywords) >= $max_keywords) break;
                    }
                }
                if (count($keywords) >= 2) { // Min 2 keywords
                    $cleaned['keywords'] = $keywords;
                    break;
                }
            }
        }

        // Only return if we found all four required fields
        if (count($cleaned) === 4) {
            return $cleaned;
        }

        return null;
    }

    /**
     * Enhanced AI response cleaning that works across all providers.
     *
     * @since 1.0.0
     * @param mixed $response The potentially malformed response.
     * @param string $provider The AI provider.
     * @return array|null Cleaned response or null if unrecoverable.
     */
    public static function clean_ai_response($response, $provider) {
        if (!is_array($response)) {
            return null;
        }

        $cleaned = [];

        // Try to find the required fields even if they're nested or have different keys
        $alt_text_keys = ['alt_text', 'altText', 'alt', 'alternative_text', 'alternativeText'];
        $title_keys = ['title', 'image_title', 'imageTitle', 'seo_title', 'seoTitle'];
        $filename_keys = ['filename', 'file_name', 'fileName', 'slug', 'url_slug'];
        $keywords_keys = ['keywords', 'tags', 'seo_keywords', 'seoKeywords', 'keyword'];

        // Search for alt text
        foreach ($alt_text_keys as $key) {
            if (isset($response[$key]) && is_string($response[$key]) && !empty($response[$key])) {
                $cleaned['alt_text'] = trim($response[$key]);
                break;
            }
        }

        // Search for title
        foreach ($title_keys as $key) {
            if (isset($response[$key]) && is_string($response[$key]) && !empty($response[$key])) {
                $cleaned['title'] = trim($response[$key]);
                break;
            }
        }

        // Search for filename
        foreach ($filename_keys as $key) {
            if (isset($response[$key]) && is_string($response[$key]) && !empty($response[$key])) {
                // Clean the filename using WordPress functions FIRST to preserve meaning
                $original_filename = trim($response[$key]);
                $filename = $original_filename;

                // Step 0: Strip image extensions before any processing so .jpg doesn't survive as -jpg.
                $filename = \IPAIS\Core\INFYP_File_Renamer::strip_file_extension( $filename );

                // Step 1: Remove accents BEFORE stripping characters (café → cafe, montaña → montana)
                $filename = remove_accents($filename);

                // Step 2: WordPress sanitization (handles Cyrillic → Latin conversion)
                $filename = sanitize_title_with_dashes($filename);

                // Step 3: Ensure lowercase
                $filename = strtolower($filename);

                // Step 4: Final cleanup - remove any remaining non-ASCII characters
                $filename = preg_replace('/[^a-z0-9-]/', '-', $filename);
                $filename = preg_replace('/-+/', '-', $filename);
                $filename = trim($filename, '-');

                // Step 5: If sanitization resulted in empty/very short filename, use fallback
                if (empty($filename) || strlen($filename) < 10) {
                    // Generate fallback that already meets minimum length requirement (40 chars)
                    $hash = substr(md5($original_filename), 0, 20);
                    $filename = 'optimized-image-seo-' . $hash;

                    INFYP_Logger::log(sprintf(
                        'Cleanup: Filename sanitization produced empty result. Original: "%s", Using fallback: "%s"',
                        $original_filename,
                        $filename
                    ), 'WARNING');
                } else {
                    // Only add suffix if we didn't use fallback and filename is still too short
                    if (strlen($filename) < 40) {
                        $filename .= '-optimized-image-seo';
                    }
                }

                $cleaned['filename'] = substr($filename, 0, 60); // Ensure max length
                break;
            }
        }

        /** This filter is documented in includes/Ajax/INFYP_AI_Ajax.php */
        $max_keywords = apply_filters('infyp_max_keywords', 3);

        // Search for keywords
        foreach ($keywords_keys as $key) {
            if (isset($response[$key])) {
                $keywords = [];

                // Handle both array and string (comma-separated) formats
                if (is_array($response[$key])) {
                    foreach ($response[$key] as $keyword) {
                        if (is_string($keyword) && !empty(trim($keyword))) {
                            $keywords[] = trim($keyword);
                            if (count($keywords) >= $max_keywords) break;
                        }
                    }
                } elseif (is_string($response[$key])) {
                    // Try to split comma-separated keywords
                    $potential_keywords = explode(',', $response[$key]);
                    foreach ($potential_keywords as $keyword) {
                        $keyword = trim($keyword);
                        if (!empty($keyword)) {
                            $keywords[] = $keyword;
                            if (count($keywords) >= $max_keywords) break;
                        }
                    }
                }

                if (count($keywords) >= 2) {
                    $cleaned['keywords'] = array_slice($keywords, 0, $max_keywords);
                    break;
                }
            }
        }

        // If we're missing keywords but have other fields, try to generate them
        if (count($cleaned) === 3 && !isset($cleaned['keywords'])) {
            $keywords = [];

            // Extract potential keywords from title and alt text
            if (isset($cleaned['title'])) {
                $words = explode(' ', strtolower($cleaned['title']));
                foreach ($words as $word) {
                    if (strlen($word) > 3 && !in_array($word, ['with', 'from', 'this', 'that', 'have'])) {
                        $keywords[] = $word;
                        if (count($keywords) >= 2) break;
                    }
                }
            }

            if (count($keywords) < 2 && isset($cleaned['alt_text'])) {
                $words = explode(' ', strtolower($cleaned['alt_text']));
                foreach ($words as $word) {
                    // Use mb_strlen for UTF-8 character counting
                    $word_length = function_exists('mb_strlen') ? mb_strlen($word, 'UTF-8') : strlen($word);
                    if ($word_length > 3 && !in_array($word, $keywords) && !in_array($word, ['with', 'from', 'this', 'that', 'have'])) {
                        $keywords[] = $word;
                        if (count($keywords) >= 2) break;
                    }
                }
            }

            if (count($keywords) >= 2) {
                $cleaned['keywords'] = $keywords;
            }
        }

        // Only return if we found all four required fields
        if (count($cleaned) === 4) {
            // Final length adjustments to ensure minimum requirements
            if (mb_strlen($cleaned['alt_text'], 'UTF-8') < 50) {
                $cleaned['alt_text'] .= ' - ' . $cleaned['title'];
            }
            if (mb_strlen($cleaned['alt_text'], 'UTF-8') > 125) {
                $cleaned['alt_text'] = mb_substr($cleaned['alt_text'], 0, 125, 'UTF-8');
            }

            if (mb_strlen($cleaned['title'], 'UTF-8') > 60) {
                $cleaned['title'] = mb_substr($cleaned['title'], 0, 60, 'UTF-8');
            }

            return $cleaned;
        }

        return null;
    }
}
