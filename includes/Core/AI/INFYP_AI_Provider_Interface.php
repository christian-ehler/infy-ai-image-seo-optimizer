<?php
/**
 * AI Provider Interface
 *
 * Defines the contract that all AI provider implementations must follow.
 * Each supported AI service (OpenAI, Google Gemini, Anthropic Claude) implements
 * this interface to ensure consistent behavior across providers.
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

/**
 * Interface INFYP_AI_Provider_Interface
 *
 * Contract for AI provider implementations. Each provider must support
 * three types of API calls: full optimization (all fields), keyword
 * generation, and single-field optimization.
 *
 * @since 1.0.0
 */
interface INFYP_AI_Provider_Interface {

	/**
	 * Get the human-readable display name of the AI provider.
	 *
	 * @since 1.0.0
	 *
	 * @return string The provider display name (e.g., "OpenAI", "Google Gemini", "Anthropic Claude").
	 */
	public function get_name(): string;

	/**
	 * Get the unique slug identifier for this provider.
	 *
	 * Used internally for option keys, hook names, and provider selection.
	 *
	 * @since 1.0.0
	 *
	 * @return string The provider slug (e.g., "openai", "google", "anthropic").
	 */
	public function get_slug(): string;

	/**
	 * Check whether this provider supports a given model name.
	 *
	 * @since 1.0.0
	 *
	 * @param string $model The model identifier to check (a registry model id).
	 *
	 * @return bool True if the model is supported by this provider, false otherwise.
	 */
	public function supports_model( string $model ): bool;

	/**
	 * Make a full optimization API call to generate all SEO fields.
	 *
	 * Sends the image and prompt to the AI provider to generate a complete
	 * set of SEO metadata including keywords, filename, alt text, and title.
	 *
	 * @since 1.0.0
	 *
	 * @param string $api_key      The API key for authenticating with the provider.
	 * @param string $prompt       The constructed prompt to send to the AI model.
	 * @param int    $image_id     The WordPress attachment ID of the image being optimized.
	 * @param string $model_name   The specific AI model to use for this request.
	 * @param array  $call_params  Optional. Model-specific call parameters from the Model Config Registry.
	 *
	 * @return array|\WP_Error Associative array of generated SEO fields on success, WP_Error on failure.
	 */
	public function call_full( string $api_key, string $prompt, int $image_id, string $model_name, array $call_params = [] ): array|\WP_Error;

	/**
	 * Make an API call specifically for keyword generation.
	 *
	 * Analyzes the image and generates relevant SEO keywords that can be
	 * used to optimize other metadata fields.
	 *
	 * @since 1.0.0
	 *
	 * @param string $api_key      The API key for authenticating with the provider.
	 * @param string $prompt       The constructed prompt for keyword generation.
	 * @param int    $image_id     The WordPress attachment ID of the image being analyzed.
	 * @param string $model_name   The specific AI model to use for this request.
	 * @param array  $call_params  Optional. Model-specific call parameters from the Model Config Registry.
	 *
	 * @return array|\WP_Error Associative array containing generated keywords on success, WP_Error on failure.
	 */
	public function call_keywords( string $api_key, string $prompt, int $image_id, string $model_name, array $call_params = [] ): array|\WP_Error;

	/**
	 * Make an API call to optimize a single metadata field.
	 *
	 * Generates optimized content for a specific SEO field (e.g., alt text,
	 * title, or filename) using the provided prompt and image context.
	 *
	 * @since 1.0.0
	 *
	 * @param string $api_key      The API key for authenticating with the provider.
	 * @param string $prompt       The constructed prompt for field optimization.
	 * @param int    $image_id     The WordPress attachment ID of the image being optimized.
	 * @param string $model_name   The specific AI model to use for this request.
	 * @param string $field_type   The type of field being optimized (e.g., "alt_text", "title", "filename").
	 * @param array  $call_params  Optional. Model-specific call parameters from the Model Config Registry.
	 *
	 * @return array|\WP_Error Associative array containing the optimized field value on success, WP_Error on failure.
	 */
	public function call_single_field( string $api_key, string $prompt, int $image_id, string $model_name, string $field_type, array $call_params = [] ): array|\WP_Error;
}
