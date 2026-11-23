<?php
/**
 * Anthropic Claude AI Provider
 *
 * Implements the AI provider interface for Anthropic's Claude models.
 * Handles all Claude-specific API calls including full optimization,
 * keyword generation, and single-field optimization using tool use.
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
 * Class INFYP_Claude_Provider
 *
 * Concrete AI provider implementation for Anthropic Claude. Uses Claude's
 * tool use (function calling) feature to extract structured SEO data from
 * images via the Messages API.
 *
 * @since 1.0.0
 *
 * @see INFYP_AI_Provider_Base For inherited utility methods.
 * @see INFYP_AI_Provider_Interface For the contract this class fulfills.
 */
class INFYP_Claude_Provider extends INFYP_AI_Provider_Base {

	private const API_URL = 'https://api.anthropic.com/v1/messages';

	/**
	 * Get the human-readable display name of the AI provider.
	 *
	 * @since 1.0.0
	 *
	 * @return string The provider display name.
	 */
	public function get_name(): string {
		return 'Anthropic Claude';
	}

	/**
	 * Get the unique slug identifier for this provider.
	 *
	 * @since 1.0.0
	 *
	 * @return string The provider slug.
	 */
	public function get_slug(): string {
		return 'anthropic';
	}

	/**
	 * Check whether this provider supports a given model name.
	 *
	 * Matches any model identifier that starts with "claude".
	 *
	 * @since 1.0.0
	 *
	 * @param string $model The model identifier to check.
	 *
	 * @return bool True if the model is supported by this provider.
	 */
	public function supports_model( string $model ): bool {
		return strpos( $model, 'claude' ) === 0;
	}

	/**
	 * Make a full optimization API call to generate all SEO fields.
	 *
	 * Sends the image to Claude with a tool definition for structured output
	 * containing alt text, title, filename, and keywords.
	 *
	 * @since 1.0.0
	 *
	 * @param string $api_key      The Anthropic API key.
	 * @param string $prompt       The constructed prompt to send to Claude.
	 * @param int    $image_id     The WordPress attachment ID of the image being optimized.
	 * @param string $model_name   The specific Claude model to use for this request.
	 * @param array  $call_params  Optional. Model-specific call parameters from the Model Config Registry.
	 *
	 * @return array|\WP_Error Associative array of generated SEO fields on success, WP_Error on failure.
	 */
	public function call_full( string $api_key, string $prompt, int $image_id, string $model_name, array $call_params = [] ): array|\WP_Error {
		$api_url = self::API_URL;

		$base64_image = static::get_cached_base64( $image_id );
		if ( is_wp_error( $base64_image ) ) {
			return $base64_image;
		}
		$mime_type = get_post_mime_type( $image_id );

		$seo_tool_definition = [
			'name'         => 'save_seo_data',
			'description'  => 'Saves the SEO data extracted from an image.',
			'input_schema' => [
				'type'       => 'object',
				'properties' => [
					'alt_text' => [
						'type'        => 'string',
						'description' => 'A detailed, descriptive alt text for accessibility and SEO (50-125 characters).',
					],
					'title'    => [
						'type'        => 'string',
						'description' => 'A catchy, descriptive title for the image (40-60 characters).',
					],
					'filename' => [
						'type'        => 'string',
						'description' => 'A short, URL-friendly slug using lowercase letters and hyphens, without a file extension.',
					],
					'keywords' => [
						'type'        => 'array',
						'items'       => [ 'type' => 'string' ],
						'description' => 'An array of 3 relevant SEO keywords that describe the image content.',
						'minItems'    => 2,
						'maxItems'    => 3,
					],
				],
				'required'   => [ 'alt_text', 'title', 'filename', 'keywords' ],
			],
		];

		$body = [
			'model'      => $model_name,
			'max_tokens' => $call_params['max_completion_tokens'] ?? 1024,
			'messages'   => [
				[
					'role'    => 'user',
					'content' => [
						[ 'type' => 'image', 'source' => [ 'type' => 'base64', 'media_type' => $mime_type, 'data' => $base64_image ] ],
						[ 'type' => 'text', 'text' => $prompt ],
					],
				],
			],
			'tools'       => [ $seo_tool_definition ],
			'tool_choice' => [ 'type' => 'tool', 'name' => 'save_seo_data' ],
		];

		// Temperature from config (null = omit)
		if ( isset( $call_params['temperature'] ) && $call_params['temperature'] !== null ) {
			$body['temperature'] = $call_params['temperature'];
		}

		// Optional extended thinking from extras
		if ( ! empty( $call_params['extra']['thinking'] ) ) {
			$body['thinking'] = $call_params['extra']['thinking'];
		}

		$response = wp_remote_post( $api_url, [
			'method'  => 'POST',
			'headers' => [
				'Content-Type'     => 'application/json',
				'x-api-key'        => $api_key,
				'anthropic-version' => '2023-06-01',
			],
			'body'    => wp_json_encode( $body ),
			'timeout' => static::get_request_timeout(),
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( $status_code < 200 || $status_code >= 300 ) {
			$msg = 'Claude API error (HTTP ' . $status_code . ')';
			if ( $status_code === 401 || $status_code === 403 ) {
				$msg = esc_html__( 'Invalid API key or insufficient permissions.', 'infy-ai-image-seo-optimizer' ) . ' (HTTP ' . $status_code . ')';
			} elseif ( $status_code === 429 ) {
				$msg = esc_html__( 'Rate limit exceeded. Please wait and try again.', 'infy-ai-image-seo-optimizer' ) . ' (HTTP 429)';
			} elseif ( $status_code >= 500 ) {
				$msg = esc_html__( 'AI service temporarily unavailable.', 'infy-ai-image-seo-optimizer' ) . ' (HTTP ' . $status_code . ')';
			}
			return new \WP_Error( 'api_http_error', $msg );
		}

		$response_body = wp_remote_retrieve_body( $response );
		$data          = json_decode( $response_body, true );

		if ( isset( $data['content'] ) && is_array( $data['content'] ) ) {
			foreach ( $data['content'] as $content_block ) {
				if ( $content_block['type'] === 'tool_use' && $content_block['name'] === 'save_seo_data' ) {
					// Return parsed response for validation in execute_with_format_retry
					if ( is_array( $content_block['input'] ) ) {
						return $content_block['input'];
					}
					return new \WP_Error( 'invalid_response_format', 'The API did not return valid tool input.' );
				}
			}
		}

		$error_message = 'Could not parse tool_use response from Anthropic API. ';
		if ( isset( $data['error']['message'] ) ) {
			$error_message .= $data['error']['message'];
		}

		return new \WP_Error( 'api_error', $error_message );
	}

	/**
	 * Make an API call specifically for keyword generation.
	 *
	 * Sends the image to Claude with a tool definition that extracts
	 * exactly 3 SEO keywords from the image content.
	 *
	 * @since 1.0.0
	 *
	 * @param string $api_key      The Anthropic API key.
	 * @param string $prompt       The constructed prompt for keyword generation.
	 * @param int    $image_id     The WordPress attachment ID of the image being analyzed.
	 * @param string $model_name   The specific Claude model to use for this request.
	 * @param array  $call_params  Optional. Model-specific call parameters from the Model Config Registry.
	 *
	 * @return array|\WP_Error Associative array containing generated keywords on success, WP_Error on failure.
	 */
	public function call_keywords( string $api_key, string $prompt, int $image_id, string $model_name, array $call_params = [] ): array|\WP_Error {
		$api_url = self::API_URL;

		$base64_image = static::get_cached_base64( $image_id );
		if ( is_wp_error( $base64_image ) ) {
			return $base64_image;
		}
		$mime_type = get_post_mime_type( $image_id );

		$keywords_tool_definition = [
			'name'         => 'save_keywords',
			'description'  => 'Saves the SEO keywords extracted from an image.',
			'input_schema' => [
				'type'       => 'object',
				'properties' => [
					'keywords' => [
						'type'        => 'array',
						'description' => 'Exactly 3 SEO keywords',
						'items'       => [ 'type' => 'string' ],
						'minItems'    => 3,
						'maxItems'    => 3,
					],
				],
				'required'   => [ 'keywords' ],
			],
		];

		$body = [
			'model'      => $model_name,
			'max_tokens' => $call_params['max_completion_tokens'] ?? 500,
			'messages'   => [
				[
					'role'    => 'user',
					'content' => [
						[ 'type' => 'text', 'text' => $prompt ],
						[ 'type' => 'image', 'source' => [
							'type'       => 'base64',
							'media_type' => $mime_type,
							'data'       => $base64_image,
						] ],
					],
				],
			],
			'tools'      => [ $keywords_tool_definition ],
		];

		// Temperature from config (null = omit)
		if ( isset( $call_params['temperature'] ) && $call_params['temperature'] !== null ) {
			$body['temperature'] = $call_params['temperature'];
		}

		// Optional extended thinking from extras
		if ( ! empty( $call_params['extra']['thinking'] ) ) {
			$body['thinking'] = $call_params['extra']['thinking'];
		}

		$response = wp_remote_post( $api_url, [
			'method'  => 'POST',
			'headers' => [
				'Content-Type'     => 'application/json',
				'x-api-key'        => $api_key,
				'anthropic-version' => '2023-06-01',
			],
			'body'    => wp_json_encode( $body ),
			'timeout' => static::get_request_timeout(),
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( $status_code < 200 || $status_code >= 300 ) {
			$msg = 'Claude API error (HTTP ' . $status_code . ')';
			if ( $status_code === 401 || $status_code === 403 ) {
				$msg = esc_html__( 'Invalid API key or insufficient permissions.', 'infy-ai-image-seo-optimizer' ) . ' (HTTP ' . $status_code . ')';
			} elseif ( $status_code === 429 ) {
				$msg = esc_html__( 'Rate limit exceeded. Please wait and try again.', 'infy-ai-image-seo-optimizer' ) . ' (HTTP 429)';
			} elseif ( $status_code >= 500 ) {
				$msg = esc_html__( 'AI service temporarily unavailable.', 'infy-ai-image-seo-optimizer' ) . ' (HTTP ' . $status_code . ')';
			}
			return new \WP_Error( 'api_http_error', $msg );
		}

		$response_body = wp_remote_retrieve_body( $response );
		$data          = json_decode( $response_body, true );

		if ( isset( $data['content'] ) && is_array( $data['content'] ) ) {
			foreach ( $data['content'] as $content_block ) {
				if ( $content_block['type'] === 'tool_use' && $content_block['name'] === 'save_keywords' ) {
					// Simple validation for keywords only
					if ( isset( $content_block['input']['keywords'] ) && is_array( $content_block['input']['keywords'] ) ) {
						return $content_block['input'];
					}
					return new \WP_Error( 'invalid_response_format', 'Keywords not in expected format.' );
				}
			}
		}

		$error_message = 'Could not parse response from Claude API. ';
		if ( isset( $data['error']['message'] ) ) {
			$error_message .= $data['error']['message'];
		}

		return new \WP_Error( 'api_error', $error_message );
	}

	/**
	 * Make an API call to optimize a single metadata field.
	 *
	 * Dynamically builds a tool definition for the specified field type
	 * and sends it to Claude for optimization.
	 *
	 * @since 1.0.0
	 *
	 * @param string $api_key      The Anthropic API key.
	 * @param string $prompt       The constructed prompt for field optimization.
	 * @param int    $image_id     The WordPress attachment ID of the image being optimized.
	 * @param string $model_name   The specific Claude model to use for this request.
	 * @param string $field_type   The type of field being optimized (e.g., "alt_text", "title", "filename").
	 * @param array  $call_params  Optional. Model-specific call parameters from the Model Config Registry.
	 *
	 * @return array|\WP_Error Associative array containing the optimized field value on success, WP_Error on failure.
	 */
	public function call_single_field( string $api_key, string $prompt, int $image_id, string $model_name, string $field_type, array $call_params = [] ): array|\WP_Error {
		$api_url = self::API_URL;

		$base64_image = static::get_cached_base64( $image_id );
		if ( is_wp_error( $base64_image ) ) {
			return $base64_image;
		}
		$mime_type = get_post_mime_type( $image_id );

		// Define tool based on field type
		$tool_name       = 'save_' . $field_type;
		$tool_definition = [
			'name'         => $tool_name,
			'description'  => 'Saves the optimized ' . str_replace( '_', ' ', $field_type ),
			'input_schema' => [
				'type'       => 'object',
				'properties' => [
					$field_type => [
						'type'        => 'string',
						'description' => 'The optimized ' . str_replace( '_', ' ', $field_type ),
					],
				],
				'required'   => [ $field_type ],
			],
		];

		$body = [
			'model'      => $model_name,
			'max_tokens' => $call_params['max_completion_tokens'] ?? 500,
			'messages'   => [
				[
					'role'    => 'user',
					'content' => [
						[ 'type' => 'text', 'text' => $prompt ],
						[ 'type' => 'image', 'source' => [
							'type'       => 'base64',
							'media_type' => $mime_type,
							'data'       => $base64_image,
						] ],
					],
				],
			],
			'tools'       => [ $tool_definition ],
			'tool_choice' => [ 'type' => 'tool', 'name' => $tool_name ],
		];

		// Temperature from config (null = omit)
		if ( isset( $call_params['temperature'] ) && $call_params['temperature'] !== null ) {
			$body['temperature'] = $call_params['temperature'];
		}

		// Optional extended thinking from extras
		if ( ! empty( $call_params['extra']['thinking'] ) ) {
			$body['thinking'] = $call_params['extra']['thinking'];
		}

		$response = wp_remote_post( $api_url, [
			'method'  => 'POST',
			'headers' => [
				'Content-Type'     => 'application/json',
				'x-api-key'        => $api_key,
				'anthropic-version' => '2023-06-01',
			],
			'body'    => wp_json_encode( $body ),
			'timeout' => static::get_request_timeout(),
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( $status_code < 200 || $status_code >= 300 ) {
			$msg = 'Claude API error (HTTP ' . $status_code . ')';
			if ( $status_code === 401 || $status_code === 403 ) {
				$msg = esc_html__( 'Invalid API key or insufficient permissions.', 'infy-ai-image-seo-optimizer' ) . ' (HTTP ' . $status_code . ')';
			} elseif ( $status_code === 429 ) {
				$msg = esc_html__( 'Rate limit exceeded. Please wait and try again.', 'infy-ai-image-seo-optimizer' ) . ' (HTTP 429)';
			} elseif ( $status_code >= 500 ) {
				$msg = esc_html__( 'AI service temporarily unavailable.', 'infy-ai-image-seo-optimizer' ) . ' (HTTP ' . $status_code . ')';
			}
			return new \WP_Error( 'api_http_error', $msg );
		}

		$response_body = wp_remote_retrieve_body( $response );
		$data          = json_decode( $response_body, true );

		if ( isset( $data['content'] ) && is_array( $data['content'] ) ) {
			foreach ( $data['content'] as $content_block ) {
				if ( $content_block['type'] === 'tool_use' && $content_block['name'] === $tool_name ) {
					if ( isset( $content_block['input'][ $field_type ] ) ) {
						return $content_block['input'];
					}
					return new \WP_Error( 'invalid_response_format', 'Field not in expected format.' );
				}
			}
		}

		$error_message = 'Could not parse response from Claude API. ';
		if ( isset( $data['error']['message'] ) ) {
			$error_message .= $data['error']['message'];
		}

		return new \WP_Error( 'api_error', $error_message );
	}
}
