<?php
/**
 * Prompt Builder - Centralized AI Prompt Construction
 *
 * Constructs all AI prompts used throughout the plugin. Uses private helper
 * methods to avoid duplicating shared instruction blocks (filename rules,
 * keyword integration, language handling) across provider branches.
 *
 * The class builds prompts for:
 * - Single field optimization (filename, alt_text, title)
 * - Keywords-only generation
 * - Simplified retry prompts
 *
 * Provider differences (JSON vs tool_use response format) are resolved via
 * the `prompt_format` field in the Model Config Registry, not string-prefix
 * matching.
 *
 * @package   IPAIS\Core\AI
 * @since 1.0.0
 * @author    InfyPress
 * @license   GPL-2.0-or-later
 */

namespace IPAIS\Core\AI;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use \IPAIS\Core\INFYP_SEO_Score_Calculator;

/**
 * Prompt Builder Class
 *
 * Static utility for building provider-aware AI prompts. Uses the Model Config
 * Registry to resolve prompt format (json, json_plain, tool_use) instead of
 * hardcoded provider detection.
 *
 * @package   IPAIS\Core\AI
 * @since 1.0.0
 */
class INFYP_Prompt_Builder {

	/* =========================================================================
	   PUBLIC METHODS
	   ========================================================================= */

	/**
	 * Get prompt for single field optimization.
	 *
	 * @since 1.0.0
	 *
	 * @param string     $model             AI model identifier.
	 * @param string     $field_type        Field being optimized ('filename', 'alt_text', 'title').
	 * @param string     $language          Target language for the output.
	 * @param string     $prompt_addon      Custom user instructions.
	 * @param array|null $existing_keywords Optional keywords for context.
	 * @return string The constructed prompt.
	 */
	public static function get_single_field_prompt( $model, $field_type, $language, $prompt_addon, $existing_keywords = null ) {
		// Allow extensions to provide custom prompts for extended field types.
		$custom_prompt = apply_filters( 'infyp_extended_field_prompt', null, $model, $field_type, $language, $prompt_addon, $existing_keywords );
		if ( $custom_prompt !== null ) {
			return $custom_prompt;
		}

		$params        = INFYP_SEO_Score_Calculator::get_scoring_params();
		$prompt_format = self::resolve_prompt_format( $model );

		// Assemble prompt sections.
		$sections = [];

		switch ( $field_type ) {
			case 'filename':
				$sections['opening']      = "Generate an SEO-optimized filename for this image following strict SEO guidelines.";
				$sections['variation']    = self::get_variation_instruction();
				$sections['addon']        = self::get_addon_block( $prompt_addon );
				$sections['keywords']     = self::get_keyword_integration_rules( $existing_keywords, 'filename', $params );
				$sections['requirements'] = self::get_filename_format_rules( $params );
				$sections['language']     = self::get_language_rules( $language, 'filename' );
				$sections['response']     = self::get_response_format_instruction( $prompt_format, 'filename' );
				break;

			case 'alt_text':
				$sections['opening']      = "Generate SEO-optimized alt text for this image.";
				$sections['variation']    = self::get_variation_instruction();
				$sections['addon']        = self::get_addon_block( $prompt_addon );
				$sections['keywords']     = self::get_keyword_integration_rules( $existing_keywords, 'alt_text', $params );
				$sections['requirements'] = self::get_field_requirements( 'alt_text', $params, $language );
				$sections['response']     = self::get_response_format_instruction( $prompt_format, 'alt_text' );
				break;

			case 'title':
				$sections['opening']      = "Generate an SEO-optimized title for this image.";
				$sections['variation']    = self::get_variation_instruction();
				$sections['addon']        = self::get_addon_block( $prompt_addon );
				$sections['keywords']     = self::get_keyword_integration_rules( $existing_keywords, 'title', $params );
				$sections['requirements'] = self::get_field_requirements( 'title', $params, $language );
				$sections['response']     = self::get_response_format_instruction( $prompt_format, 'title' );
				break;
		}

		/**
		 * Filter prompt sections before assembly.
		 *
		 * Developers can add, remove, or modify individual sections without
		 * needing to parse the full prompt string. Set a section to empty
		 * string to remove it.
		 *
		 * @since 1.0.0
		 * @param array  $sections   Associative array of prompt sections.
		 * @param string $field_type Field being optimized.
		 * @param string $model      AI model identifier.
		 * @param string $language   Target language.
		 */
		$sections = apply_filters( 'infyp_prompt_sections', $sections, $field_type, $model, $language );

		$prompt = implode( "\n", array_filter( $sections ) );

		/**
		 * Filter the generated prompt for a single field optimization.
		 *
		 * @since 1.0.0
		 * @param string     $prompt            The generated prompt.
		 * @param string     $model             AI model identifier.
		 * @param string     $field_type        The field being optimized.
		 * @param string     $language          Target language.
		 * @param string     $prompt_addon      Custom user instructions.
		 * @param array|null $existing_keywords Keywords for context.
		 */
		$prompt = apply_filters( 'infyp_single_field_prompt', $prompt, $model, $field_type, $language, $prompt_addon, $existing_keywords );

		return $prompt;
	}

	/**
	 * Get prompt for generating keywords only.
	 *
	 * @since 1.0.0
	 *
	 * @param string $model        The AI model being used.
	 * @param string $language     The target language.
	 * @param string $prompt_addon Custom instructions from the user.
	 * @return string The optimized prompt for keywords generation.
	 */
	public static function get_keywords_only_prompt( $model, $language, $prompt_addon = '' ) {
		$prompt_format = self::resolve_prompt_format( $model );

		$sections = [];
		$sections['opening'] = "Analyze this image and generate SEO keywords.";

		if ( ! empty( $prompt_addon ) ) {
			$sections['addon'] = "CUSTOM REQUIREMENTS:\n" . trim( $prompt_addon ) . "\n\nApply these requirements while following the format below.";
		}

		$sections['instructions']  = "IMPORTANT Instructions:\n";
		$sections['instructions'] .= "- IGNORE the filename completely - do NOT use it for keyword generation\n";
		$sections['instructions'] .= "- Focus ONLY on the actual visual content of the image\n";
		$sections['instructions'] .= "- Analyze what is shown in the image itself";

		$sections['requirements']  = "Requirements:\n";
		$sections['requirements'] .= "- Generate EXACTLY 3 relevant SEO keywords\n";
		$sections['requirements'] .= "- Each keyword: 1-3 words, based on image content only\n";
		$sections['requirements'] .= "- Language: {$language}\n";
		$sections['requirements'] .= "- Focus on searchable terms that describe what's in the image";

		$sections['response'] = self::get_response_format_instruction( $prompt_format, 'keywords' );

		/** @see get_single_field_prompt() for full docblock */
		$sections = apply_filters( 'infyp_prompt_sections', $sections, 'keywords', $model, $language );

		$prompt = implode( "\n\n", array_filter( $sections ) );

		/**
		 * Filter the keywords-only prompt after construction.
		 *
		 * @since 1.0.0
		 * @param string $prompt       The constructed prompt.
		 * @param string $model        AI model identifier.
		 * @param string $language     Target language.
		 * @param string $prompt_addon Custom instructions included.
		 */
		return apply_filters( 'infyp_keywords_prompt', $prompt, $model, $language, $prompt_addon );
	}

	/**
	 * Generates a simplified prompt for retry attempts.
	 *
	 * @since 1.0.0
	 *
	 * @param string $model    The AI model being used.
	 * @param string $language The target language.
	 * @return string A simplified prompt.
	 */
	public static function get_simplified_prompt( $model, $language ) {
		$prompt_format = self::resolve_prompt_format( $model );

		switch ( $prompt_format ) {
			case 'tool_use':
				$prompt = "Use save_seo_data tool with: alt_text (50-125 chars), title (40-60 chars), filename (40-60 chars), keywords (3 items). Language: {$language}";
				break;

			case 'json_plain':
				$prompt = "Analyze image. Return JSON only: {\"alt_text\":\"text 50-125 chars\",\"title\":\"title 40-60 chars\",\"filename\":\"filename-40-60-chars\",\"keywords\":[\"word1\",\"word2\"]}. Language: {$language}";
				break;

			case 'json':
			default:
				$prompt = "Return ONLY this JSON with SEO data for the image in {$language}: {\"alt_text\":\"descriptive text 50-125 chars\",\"title\":\"title 40-60 chars\",\"filename\":\"lowercase-hyphenated-40-60-chars\",\"keywords\":[\"keyword1\",\"keyword2\"]}";
				break;
		}

		$registry = INFYP_Model_Config_Registry::get_instance();
		$config   = $registry->get_config( $model );
		$provider = $config['provider'] ?? '';

		/**
		 * Filters the simplified retry prompt.
		 *
		 * @since 1.0.0
		 * @param string $prompt   The simplified prompt.
		 * @param string $model    The AI model identifier.
		 * @param string $provider The resolved provider slug.
		 * @param string $language The target language.
		 */
		return apply_filters( 'infyp_simplified_prompt', $prompt, $model, $provider, $language );
	}

	/* =========================================================================
	   PRIVATE HELPERS — Shared instruction blocks
	   ========================================================================= */

	/**
	 * Resolve the prompt format for a model from the Model Config Registry.
	 *
	 * @since 1.0.0
	 *
	 * @param string $model AI model identifier.
	 * @return string One of 'json', 'json_plain', 'tool_use'.
	 */
	private static function resolve_prompt_format( $model ) {
		$registry = INFYP_Model_Config_Registry::get_instance();
		$config   = $registry->get_config( $model );

		if ( ! empty( $config['prompt_format'] ) ) {
			$format = $config['prompt_format'];
		} else {
			// Fallback for models without prompt_format (backward compat).
			$provider = $config['provider'] ?? '';
			switch ( $provider ) {
				case 'openai':    $format = 'json'; break;
				case 'google':    $format = 'json_plain'; break;
				case 'anthropic': $format = 'tool_use'; break;
				default:          $format = 'json'; break;
			}
		}

		/**
		 * Override the resolved prompt format for any model.
		 *
		 * @since 1.0.0
		 * @param string $format   Resolved format ('json', 'json_plain', 'tool_use').
		 * @param string $model    AI model identifier.
		 * @param string $provider Provider slug.
		 */
		return apply_filters( 'infyp_prompt_format', $format, $model, $config['provider'] ?? '' );
	}

	/**
	 * Standard variation instruction to ensure unique results.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	private static function get_variation_instruction() {
		return "IMPORTANT: Generate a unique, creative variation. Do not repeat previous suggestions.";
	}

	/**
	 * Wraps user custom instructions in a standard block.
	 *
	 * @since 1.0.0
	 *
	 * @param string $prompt_addon Raw user instructions.
	 * @return string Formatted block or empty string.
	 */
	private static function get_addon_block( $prompt_addon ) {
		if ( empty( $prompt_addon ) ) {
			return '';
		}
		$prompt_addon = mb_substr( wp_strip_all_tags( trim( $prompt_addon ) ), 0, 500, 'UTF-8' );
		return "PRIMARY REQUIREMENTS (MUST FOLLOW):\n" . $prompt_addon;
	}

	/**
	 * Keyword integration rules for a given field context.
	 *
	 * @since 1.0.0
	 *
	 * @param array|null $existing_keywords Keywords to integrate.
	 * @param string     $field_type        Field context ('filename', 'alt_text', 'title').
	 * @param array      $params            Scoring params from SEO Score Calculator.
	 * @return string Keyword instruction block or empty string.
	 */
	private static function get_keyword_integration_rules( $existing_keywords, $field_type, $params ) {
		if ( empty( $existing_keywords ) || ! is_array( $existing_keywords ) ) {
			return '';
		}

		$keywords_string = implode( ', ', $existing_keywords );
		$block  = "KEYWORDS TO USE: {$keywords_string}\n\n";
		$block .= "KEYWORD INTEGRATION RULES:\n";
		$block .= "- Try to incorporate ALL provided keywords naturally\n";

		if ( 'filename' === $field_type ) {
			$block .= "- For multi-word keywords (e.g., 'Game background'), convert spaces to hyphens (game-background)\n";
			$block .= "- If including all keywords would exceed {$params['filename']['max_chars']} chars or {$params['filename']['max_words']} words:\n";
		} else {
			$block .= "- Keywords should flow naturally within the text\n";
			$field_key = isset( $params[ $field_type ] ) ? $field_type : 'alt_text';
			$block .= "- If all keywords cannot fit naturally within {$params[ $field_key ]['max_chars']} chars:\n";
		}

		$block .= "- Prioritize using at least 1-2 of the most relevant keywords\n";
		$block .= "- Choose keywords that best describe the main subject\n";
		$block .= "- Maintain keyword meaning — do not force keywords if they compromise quality";

		return $block;
	}

	/**
	 * Filename-specific format, stopword, and length rules.
	 *
	 * @since 1.0.0
	 *
	 * @param array $params Scoring params containing filename min/max values.
	 * @return string Filename rules block.
	 */
	private static function get_filename_format_rules( $params ) {
		$p = $params['filename'];

		$block  = "SEO FILENAME REQUIREMENTS:\n";
		$block .= "- Length: MUST be between {$p['min_chars']}-{$p['max_chars']} characters\n";
		$block .= "- Words: MUST use {$p['min_words']}-{$p['max_words']} hyphen-separated words\n";
		$block .= "- Format: ONLY lowercase ASCII letters (a-z), numbers (0-9), and hyphens (-)\n";
		$block .= "- No extension: Do NOT include .jpg, .png, .webp or any file extension — return only the stem\n";
		$block .= "- No stopwords: Do NOT use filler words (with, from, the, and, for, in, on, of, a, an, to, is, at, by)\n";
		$block .= "  Bad: chocolate-cake-with-cream-on-plate  Good: chocolate-cake-cream-plate-dessert\n";
		$block .= "- Be descriptive: Describe what's in the image using relevant, specific terms\n";
		$block .= "- Avoid generic names: Never use DSC, IMG, image, photo, picture followed by numbers\n";
		$block .= "- Front-load important words: Put the most important descriptive words first";

		return $block;
	}

	/**
	 * Field requirements for alt_text and title fields.
	 *
	 * @since 1.0.0
	 *
	 * @param string $field_type Field type ('alt_text', 'title').
	 * @param array  $params     Scoring params.
	 * @param string $language   Target language.
	 * @return string Requirements block.
	 */
	private static function get_field_requirements( $field_type, $params, $language ) {
		$p = $params[ $field_type ] ?? $params['alt_text'];

		$block  = strtoupper( str_replace( '_', ' ', $field_type ) ) . " REQUIREMENTS:\n";
		$block .= "- Length: MUST be between {$p['min_chars']}-{$p['max_chars']} characters\n";
		$block .= "- Words: MUST use {$p['min_words']}-{$p['max_words']} words\n";

		if ( 'alt_text' === $field_type ) {
			$block .= "- Content: Describe what's actually visible in the image\n";
			$block .= "- Style: Be specific and descriptive, not generic\n";
			$block .= "- Avoid: Starting with \"image of\", \"picture of\", \"photo of\"\n";
			$block .= "- Accessibility: Write for screen readers - be informative\n";
		} elseif ( 'title' === $field_type ) {
			$block .= "- Concise and descriptive\n";
		}

		$block .= "- Language: {$language}";

		return $block;
	}

	/**
	 * Language and transliteration rules for filenames.
	 *
	 * @since 1.0.0
	 *
	 * @param string $language   Target language.
	 * @param string $field_type Field context.
	 * @return string Language rules block or empty string for non-filename fields.
	 */
	private static function get_language_rules( $language, $field_type ) {
		if ( 'filename' !== $field_type ) {
			return '';
		}

		$block  = "FILENAME LANGUAGE HANDLING ({$language}):\n";
		$block .= "- If {$language} uses non-Latin characters (Chinese, Russian, Arabic, Japanese, Korean, Thai, Hebrew, Greek, etc.):\n";
		$block .= "- MUST use phonetic transliteration to Latin script (e.g., 'meijing', 'moskva', 'krasivyy-zakat')\n";
		$block .= "- Do NOT translate to English — keep the original language's sounds in Latin letters\n";
		$block .= "- If {$language} uses Latin alphabet (English, Spanish, French, German, etc.):\n";
		$block .= "- Use {$language} words naturally, converting to lowercase ASCII (remove accents/diacritics)\n";
		$block .= "- Examples: cafe (not cafe), nino (not nino), schon (not schon)";

		return $block;
	}

	/**
	 * Response format instruction based on provider's prompt_format.
	 *
	 * @since 1.0.0
	 *
	 * @param string $prompt_format One of 'json', 'json_plain', 'tool_use'.
	 * @param string $field_type    Field or prompt type ('filename', 'alt_text', 'title', 'keywords').
	 * @return string Response format instruction.
	 */
	private static function get_response_format_instruction( $prompt_format, $field_type ) {
		// Map field types to JSON key names and tool names.
		$json_examples = [
			'filename' => '{"filename": "your-optimized-filename-here"}',
			'alt_text' => '{"alt_text": "Your descriptive alt text here"}',
			'title'    => '{"title": "Your optimized title here"}',
			'keywords' => '{"keywords": ["keyword1", "keyword2", "keyword3"]}',
		];

		$tool_names = [
			'filename' => 'save_filename',
			'alt_text' => 'save_alt_text',
			'title'    => 'save_title',
			'keywords' => 'save_keywords',
		];

		$example = $json_examples[ $field_type ] ?? '{"value": "your value here"}';
		$tool    = $tool_names[ $field_type ] ?? 'save_seo_data';

		switch ( $prompt_format ) {
			case 'tool_use':
				return "Use the {$tool} tool with the optimized {$field_type}.";

			case 'json_plain':
				return "Respond with only the JSON object, no additional text:\n{$example}";

			case 'json':
			default:
				$block  = "CRITICAL JSON FORMAT RULES:\n";
				$block .= "1. Your ENTIRE response must be a single JSON object\n";
				$block .= "2. Do NOT include any text before or after the JSON\n";
				$block .= "3. Do NOT include markdown formatting like ```json\n\n";
				$block .= "Return ONLY a JSON object with this exact structure:\n{$example}";
				return $block;
		}
	}
}
