<?php
/**
 * OpenAI Provider
 *
 * Implements the AI provider interface for OpenAI's GPT models.
 * Handles full optimization, keyword generation, and single-field
 * optimization API calls to the OpenAI chat completions endpoint.
 *
 * @package    AI_Image_SEO
 * @subpackage Core\AI
 * @since 1.0.0
 * @author     InfyPress
 * @license    GPL-2.0+
 */

namespace IPAIS\Core\AI;

use IPAIS\Core\INFYP_Logger;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class INFYP_OpenAI_Provider
 *
 * OpenAI GPT provider implementation. Sends image data as base64-encoded
 * payloads to the OpenAI chat completions API and parses JSON responses
 * for SEO metadata generation.
 *
 * @since 1.0.0
 */
class INFYP_OpenAI_Provider extends INFYP_AI_Provider_Base {

	private const API_URL = 'https://api.openai.com/v1/chat/completions';

	/**
	 * Get the human-readable display name of this provider.
	 *
	 * @since 1.0.0
	 *
	 * @return string The provider display name.
	 */
	public function get_name(): string {
		return 'OpenAI';
	}

	/**
	 * Get the unique slug identifier for this provider.
	 *
	 * @since 1.0.0
	 *
	 * @return string The provider slug.
	 */
	public function get_slug(): string {
		return 'openai';
	}

	/**
	 * Check whether this provider supports a given model name.
	 *
	 * @since 1.0.0
	 *
	 * @param string $model The model identifier to check.
	 *
	 * @return bool True if the model is a GPT model supported by this provider.
	 */
	public function supports_model( string $model ): bool {
		return strpos( $model, 'gpt' ) === 0;
	}

	/**
	 * Make a full optimization API call to generate all SEO fields.
	 *
	 * Sends the image and prompt to the OpenAI chat completions API to generate
	 * a complete set of SEO metadata including keywords, filename, alt text, and title.
	 *
	 * @since 1.0.0
	 *
	 * @param string $api_key      The OpenAI API key.
	 * @param string $prompt       The constructed prompt to send to the model.
	 * @param int    $image_id     The WordPress attachment ID of the image being optimized.
	 * @param string $model_name   The specific GPT model to use (API model id resolved by the registry).
	 * @param array  $call_params  Optional. Model-specific call parameters from the Model Config Registry.
	 *
	 * @return array|\WP_Error Associative array of generated SEO fields on success, WP_Error on failure.
	 */
	public function call_full( string $api_key, string $prompt, int $image_id, string $model_name, array $call_params = [] ): array|\WP_Error {
		$api_url = self::API_URL;

		// Get image file and convert to base64
		$base64_image = static::get_cached_base64( $image_id );
		if ( is_wp_error( $base64_image ) ) {
			return $base64_image;
		}
		$mime_type = get_post_mime_type( $image_id );

		// Add system message to reinforce JSON formatting
		$system_message = "You are an SEO specialist that ALWAYS responds with valid JSON objects. Never include any text outside the JSON structure.";

		$body = [
			'model' => $model_name,
			'messages' => [
				['role' => 'system', 'content' => $system_message],
				['role' => 'user','content' => [
					['type' => 'text', 'text' => $prompt],
					['type' => 'image_url', 'image_url' => ['url' => 'data:' . $mime_type . ';base64,' . $base64_image]]
				]]
			],
		];

		// Token limit from config or fallback
		$body['max_completion_tokens'] = $call_params['max_completion_tokens'] ?? 400;

		// Response format from config or fallback
		if ( isset( $call_params['response_format'] ) ) {
			$body['response_format'] = $call_params['response_format'];
		} elseif ( empty( $call_params ) ) {
			$body['response_format'] = ['type' => 'json_object'];
		}

		// Temperature from config (null = omit)
		if ( isset( $call_params['temperature'] ) && $call_params['temperature'] !== null ) {
			$body['temperature'] = $call_params['temperature'];
		} elseif ( empty( $call_params ) ) {
			$body['temperature'] = 0.3;
		}

		// Model-specific extras (reasoning_effort, etc.)
		if ( ! empty( $call_params['extra'] ) ) {
			$body = array_merge( $body, $call_params['extra'] );
		}

		$response = wp_remote_post($api_url, [
			'method' => 'POST',
			'headers' => ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $api_key],
			'body' => wp_json_encode($body),
			'timeout' => static::get_request_timeout(),
		]);

		if (is_wp_error($response)) { return $response; }

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( $status_code < 200 || $status_code >= 300 ) {
			$msg = 'OpenAI API error (HTTP ' . $status_code . ')';
			if ( $status_code === 401 || $status_code === 403 ) {
				$msg = esc_html__( 'Invalid API key or insufficient permissions.', 'infy-ai-image-seo-optimizer' ) . ' (HTTP ' . $status_code . ')';
			} elseif ( $status_code === 429 ) {
				$msg = esc_html__( 'Rate limit exceeded. Please wait and try again.', 'infy-ai-image-seo-optimizer' ) . ' (HTTP 429)';
			} elseif ( $status_code >= 500 ) {
				$msg = esc_html__( 'AI service temporarily unavailable.', 'infy-ai-image-seo-optimizer' ) . ' (HTTP ' . $status_code . ')';
			}
			return new \WP_Error( 'api_http_error', $msg );
		}

		$response_body = wp_remote_retrieve_body($response);
		$data = json_decode($response_body, true);

		if (isset($data['choices'][0]['message']['content'])) {
			$json_string = trim($data['choices'][0]['message']['content']);

			// Remove any potential markdown formatting
			$json_string = preg_replace('/^```json\s*/', '', $json_string);
			$json_string = preg_replace('/\s*```$/', '', $json_string);

			$parsed_response = json_decode($json_string, true);

			// Return parsed response for validation in execute_with_format_retry
			if (is_array($parsed_response)) {
				return $parsed_response;
			}

			INFYP_Logger::log( 'OpenAI: Invalid JSON response: ' . mb_substr( $json_string, 0, 500 ), 'ERROR' );
			return new \WP_Error('invalid_response_format', esc_html__( 'The API did not return valid JSON. Check the debug log for details.', 'infy-ai-image-seo-optimizer' ));
		}

		$error_message = 'Could not parse response from OpenAI API. ';
		if (isset($data['error']['message'])) {
			 $error_message .= $data['error']['message'];
		}

		return new \WP_Error('api_error', $error_message);
	}

	/**
	 * Make an API call specifically for keyword generation.
	 *
	 * Analyzes the image and generates relevant SEO keywords using the
	 * OpenAI chat completions API.
	 *
	 * @since 1.0.0
	 *
	 * @param string $api_key      The OpenAI API key.
	 * @param string $prompt       The constructed prompt for keyword generation.
	 * @param int    $image_id     The WordPress attachment ID of the image being analyzed.
	 * @param string $model_name   The specific GPT model to use.
	 * @param array  $call_params  Optional. Model-specific call parameters from the Model Config Registry.
	 *
	 * @return array|\WP_Error Associative array containing generated keywords on success, WP_Error on failure.
	 */
	public function call_keywords( string $api_key, string $prompt, int $image_id, string $model_name, array $call_params = [] ): array|\WP_Error {
		$api_url = self::API_URL;

		// Get image file and convert to base64
		$base64_image = static::get_cached_base64( $image_id );
		if ( is_wp_error( $base64_image ) ) {
			return $base64_image;
		}
		$mime_type = get_post_mime_type( $image_id );

		$system_message = "You are an SEO specialist that ALWAYS responds with valid JSON containing exactly 3 keywords.";

		$body = [
			'model' => $model_name,
			'messages' => [
				['role' => 'system', 'content' => $system_message],
				['role' => 'user','content' => [
					['type' => 'text', 'text' => $prompt],
					['type' => 'image_url', 'image_url' => ['url' => 'data:' . $mime_type . ';base64,' . $base64_image]]
				]]
			],
		];

		// Token limit from config or fallback
		$body['max_completion_tokens'] = $call_params['max_completion_tokens'] ?? 150;

		// Response format from config or fallback
		if ( isset( $call_params['response_format'] ) ) {
			$body['response_format'] = $call_params['response_format'];
		} elseif ( empty( $call_params ) ) {
			$body['response_format'] = ['type' => 'json_object'];
		}

		// Temperature from config (null = omit)
		if ( isset( $call_params['temperature'] ) && $call_params['temperature'] !== null ) {
			$body['temperature'] = $call_params['temperature'];
		} elseif ( empty( $call_params ) ) {
			$body['temperature'] = 0.7;
		}

		// Model-specific extras (reasoning_effort, etc.)
		if ( ! empty( $call_params['extra'] ) ) {
			$body = array_merge( $body, $call_params['extra'] );
		}

		$response = wp_remote_post($api_url, [
			'method' => 'POST',
			'headers' => ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $api_key],
			'body' => wp_json_encode($body),
			'timeout' => static::get_request_timeout(),
		]);

		if (is_wp_error($response)) { return $response; }

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( $status_code < 200 || $status_code >= 300 ) {
			$msg = 'OpenAI API error (HTTP ' . $status_code . ')';
			if ( $status_code === 401 || $status_code === 403 ) {
				$msg = esc_html__( 'Invalid API key or insufficient permissions.', 'infy-ai-image-seo-optimizer' ) . ' (HTTP ' . $status_code . ')';
			} elseif ( $status_code === 429 ) {
				$msg = esc_html__( 'Rate limit exceeded. Please wait and try again.', 'infy-ai-image-seo-optimizer' ) . ' (HTTP 429)';
			} elseif ( $status_code >= 500 ) {
				$msg = esc_html__( 'AI service temporarily unavailable.', 'infy-ai-image-seo-optimizer' ) . ' (HTTP ' . $status_code . ')';
			}
			return new \WP_Error( 'api_http_error', $msg );
		}

		$response_body = wp_remote_retrieve_body($response);
		$data = json_decode($response_body, true);

		if (isset($data['choices'][0]['message']['content'])) {
			$json_string = trim($data['choices'][0]['message']['content']);

			// Check if response is empty
			if (empty($json_string)) {
				return new \WP_Error('empty_response', 'OpenAI API returned an empty response for keywords.');
			}

			// Remove any potential markdown formatting
			$json_string = preg_replace('/^```json\s*/', '', $json_string);
			$json_string = preg_replace('/\s*```$/', '', $json_string);

			// Try to decode the JSON
			$parsed_response = json_decode($json_string, true);

			// Check if JSON decoding failed
			if (json_last_error() !== JSON_ERROR_NONE) {
				INFYP_Logger::log( 'OpenAI keywords: JSON decode error: ' . json_last_error_msg() . ' | ' . mb_substr( $json_string, 0, 500 ), 'ERROR' );
				return new \WP_Error('json_decode_error', esc_html__( 'Failed to decode API response. Check the debug log for details.', 'infy-ai-image-seo-optimizer' ));
			}

			// Simple validation for keywords only
			if (is_array($parsed_response) && isset($parsed_response['keywords']) && is_array($parsed_response['keywords'])) {
				return $parsed_response;
			}

			// Try to extract keywords from a plain text response (fallback)
			if (!is_array($parsed_response)) {
				// Try to find keywords in various formats
				// Format 1: keyword1, keyword2, keyword3
				if (preg_match_all('/([a-zA-Z0-9\s-]+)(?:,|$)/', $json_string, $matches)) {
					$keywords = array_map('trim', $matches[1]);
					$keywords = array_filter($keywords); // Remove empty values
					if (count($keywords) >= 3) {
						return ['keywords' => array_slice($keywords, 0, 3)];
					}
				}

				// Format 2: 1. keyword1 2. keyword2 3. keyword3
				if (preg_match_all('/\d+\.\s*([a-zA-Z0-9\s-]+)/', $json_string, $matches)) {
					$keywords = array_map('trim', $matches[1]);
					if (count($keywords) >= 3) {
						return ['keywords' => array_slice($keywords, 0, 3)];
					}
				}
			}

			// Log the actual structure received for debugging
			$structure = is_array($parsed_response) ? array_keys($parsed_response) : gettype($parsed_response);
			INFYP_Logger::log( 'OpenAI keywords: unexpected format. Keys: ' . wp_json_encode( $structure ), 'ERROR' );
			return new \WP_Error('invalid_response_format', esc_html__( 'Keywords not in expected format. Check the debug log for details.', 'infy-ai-image-seo-optimizer' ));
		}

		$error_message = 'Could not parse response from OpenAI API. ';
		if (isset($data['error']['message'])) {
			$error_message .= $data['error']['message'];
		}
		return new \WP_Error('api_error', $error_message);
	}

	/**
	 * Make an API call to optimize a single metadata field.
	 *
	 * Generates optimized content for a specific SEO field (e.g., alt text,
	 * title, or filename) using the OpenAI chat completions API.
	 *
	 * @since 1.0.0
	 *
	 * @param string $api_key      The OpenAI API key.
	 * @param string $prompt       The constructed prompt for field optimization.
	 * @param int    $image_id     The WordPress attachment ID of the image being optimized.
	 * @param string $model_name   The specific GPT model to use.
	 * @param string $field_type   The type of field being optimized (e.g., "alt_text", "title", "filename").
	 * @param array  $call_params  Optional. Model-specific call parameters from the Model Config Registry.
	 *
	 * @return array|\WP_Error Associative array containing the optimized field value on success, WP_Error on failure.
	 */
	public function call_single_field( string $api_key, string $prompt, int $image_id, string $model_name, string $field_type, array $call_params = [] ): array|\WP_Error {
		$api_url = self::API_URL;

		// Get image file and convert to base64
		$base64_image = static::get_cached_base64( $image_id );
		if ( is_wp_error( $base64_image ) ) {
			return $base64_image;
		}
		$mime_type = get_post_mime_type( $image_id );

		$system_message = "You are an SEO specialist that ALWAYS responds with valid JSON for the requested field.";

		$body = [
			'model' => $model_name,
			'messages' => [
				['role' => 'system', 'content' => $system_message],
				['role' => 'user','content' => [
					['type' => 'text', 'text' => $prompt],
					['type' => 'image_url', 'image_url' => ['url' => 'data:' . $mime_type . ';base64,' . $base64_image]]
				]]
			],
		];

		// Token limit from config or fallback
		$body['max_completion_tokens'] = $call_params['max_completion_tokens'] ?? 150;

		// Response format from config or fallback
		if ( isset( $call_params['response_format'] ) ) {
			$body['response_format'] = $call_params['response_format'];
		} elseif ( empty( $call_params ) ) {
			$body['response_format'] = ['type' => 'json_object'];
		}

		// Temperature from config (null = omit)
		if ( isset( $call_params['temperature'] ) && $call_params['temperature'] !== null ) {
			$body['temperature'] = $call_params['temperature'];
		} elseif ( empty( $call_params ) ) {
			$body['temperature'] = 0.7;
		}

		// Model-specific extras (reasoning_effort, etc.)
		if ( ! empty( $call_params['extra'] ) ) {
			$body = array_merge( $body, $call_params['extra'] );
		}

		$response = wp_remote_post($api_url, [
			'method' => 'POST',
			'headers' => ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $api_key],
			'body' => wp_json_encode($body),
			'timeout' => static::get_request_timeout(),
		]);

		if (is_wp_error($response)) { return $response; }

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( $status_code < 200 || $status_code >= 300 ) {
			$msg = 'OpenAI API error (HTTP ' . $status_code . ')';
			if ( $status_code === 401 || $status_code === 403 ) {
				$msg = esc_html__( 'Invalid API key or insufficient permissions.', 'infy-ai-image-seo-optimizer' ) . ' (HTTP ' . $status_code . ')';
			} elseif ( $status_code === 429 ) {
				$msg = esc_html__( 'Rate limit exceeded. Please wait and try again.', 'infy-ai-image-seo-optimizer' ) . ' (HTTP 429)';
			} elseif ( $status_code >= 500 ) {
				$msg = esc_html__( 'AI service temporarily unavailable.', 'infy-ai-image-seo-optimizer' ) . ' (HTTP ' . $status_code . ')';
			}
			return new \WP_Error( 'api_http_error', $msg );
		}

		$response_body = wp_remote_retrieve_body($response);
		$data = json_decode($response_body, true);

		if (isset($data['choices'][0]['message']['content'])) {
			$json_string = trim($data['choices'][0]['message']['content']);
			$json_string = preg_replace('/^```json\s*/', '', $json_string);
			$json_string = preg_replace('/\s*```$/', '', $json_string);

			$parsed_response = json_decode($json_string, true);

			if (is_array($parsed_response)) {
				// Check if the expected field exists
				if (isset($parsed_response[$field_type])) {
					return $parsed_response;
				}

				// Fallback: Try to find any valid field in the response
				// Sometimes the API might return a slightly different key
				$possible_keys = apply_filters('infyp_ai_response_possible_keys', [
					'filename' => ['filename', 'file_name', 'name'],
					'alt_text' => ['alt_text', 'altText', 'alt', 'description'],
					'title' => ['title', 'image_title', 'name'],
				], $field_type);

				if (isset($possible_keys[$field_type])) {
					foreach ($possible_keys[$field_type] as $key) {
						if (isset($parsed_response[$key])) {
							// Return with the expected key
							return [$field_type => $parsed_response[$key]];
						}
					}
				}

				// Log the actual response for debugging
				// error_log('[GPT Single Field] Expected field "' . $field_type . '" not found. Response: ' . json_encode($parsed_response));
			}

			return new \WP_Error('invalid_response_format', sprintf('The API did not return valid JSON with %s field.', $field_type));
		}

		$error_message = 'Could not parse response from OpenAI API. ';
		if (isset($data['error']['message'])) {
			$error_message .= $data['error']['message'];
		}

		return new \WP_Error('api_error', $error_message);
	}
}
