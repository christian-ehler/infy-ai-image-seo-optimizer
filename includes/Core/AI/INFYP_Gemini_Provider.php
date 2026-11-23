<?php
/**
 * Gemini AI Provider
 *
 * Handles all Google Gemini API calls for image SEO optimization.
 * Extracted from INFYP_AI_Optimizer.
 *
 * @package IPAIS\Core\AI
 * @since 1.0.0
 */

namespace IPAIS\Core\AI;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class INFYP_Gemini_Provider
 *
 * Google Gemini implementation of the AI provider interface.
 */
class INFYP_Gemini_Provider extends INFYP_AI_Provider_Base {

	private const API_BASE_URL = 'https://generativelanguage.googleapis.com/v1beta/models/';

	/**
	 * Strip the API key from a URL or error message to prevent accidental exposure in logs.
	 *
	 * @param string $text Text that may contain the API key as a query parameter.
	 * @return string Text with the key value redacted.
	 */
	private static function redact_key( string $text ): string {
		return preg_replace( '/key=[^&\s]+/', 'key=***REDACTED***', $text );
	}

	/**
	 * Get the display name of this provider.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'Google Gemini';
	}

	/**
	 * Get the slug identifier for this provider.
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return 'google';
	}

	/**
	 * Check if this provider supports the given model.
	 *
	 * @param string $model The model identifier.
	 * @return bool
	 */
	public function supports_model( string $model ): bool {
		return strpos( $model, 'gemini' ) === 0;
	}

	/**
	 * Call Gemini API for full optimization (all fields).
	 *
	 * Extracted from INFYP_AI_Optimizer::call_gemini_api().
	 *
	 * @param string $api_key      The API key.
	 * @param string $prompt       The prompt to send.
	 * @param int    $image_id     The attachment ID.
	 * @param string $model_name   The model to use.
	 * @param array  $call_params  Optional. Model-specific call parameters from the Model Config Registry.
	 * @return array|\WP_Error Parsed response array or WP_Error on failure.
	 */
	public function call_full( string $api_key, string $prompt, int $image_id, string $model_name, array $call_params = [] ): array|\WP_Error {
		$api_url = self::API_BASE_URL . $model_name . ':generateContent?key=' . $api_key;

		$base64_image = static::get_cached_base64( $image_id );
		if ( is_wp_error( $base64_image ) ) {
			return $base64_image;
		}
		$mime_type = get_post_mime_type( $image_id );

		$gen_config = [
			'response_mime_type' => 'application/json',
		];

		// Max output tokens from config
		if ( isset( $call_params['max_completion_tokens'] ) ) {
			$gen_config['maxOutputTokens'] = $call_params['max_completion_tokens'];
		}

		// Temperature from config (null = omit)
		if ( isset( $call_params['temperature'] ) && $call_params['temperature'] !== null ) {
			$gen_config['temperature'] = $call_params['temperature'];
		}

		// Thinking config from extras
		if ( ! empty( $call_params['extra']['thinking_config'] ) ) {
			$gen_config['thinking_config'] = $call_params['extra']['thinking_config'];
		}

		$body = [
			'contents'         => [ [ 'parts' => [ [ 'text' => $prompt ], [ 'inline_data' => [ 'mime_type' => $mime_type, 'data' => $base64_image ] ] ] ] ],
			'generationConfig' => $gen_config,
		];

		$response = wp_remote_post( $api_url, [
			'method'  => 'POST',
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( $body ),
			'timeout' => static::get_request_timeout(),
		] );

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( $response->get_error_code(), self::redact_key( $response->get_error_message() ) );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( $status_code < 200 || $status_code >= 300 ) {
			$msg = 'Gemini API error (HTTP ' . $status_code . ')';
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

		if ( isset( $data['candidates'][0]['content']['parts'][0]['text'] ) ) {
			$text_response   = $data['candidates'][0]['content']['parts'][0]['text'];
			$parsed_response = json_decode( $text_response, true );

			// Return parsed response for validation in execute_with_format_retry
			if ( is_array( $parsed_response ) ) {
				return $parsed_response;
			}

			return new \WP_Error( 'invalid_response_format', 'The API did not return valid JSON.' );
		}

		$error_message = 'Could not parse response from Gemini API. ';
		if ( isset( $data['error']['message'] ) ) {
			$error_message .= $data['error']['message'];
		}

		return new \WP_Error( 'api_error', $error_message );
	}

	/**
	 * Call Gemini API specifically for keyword generation.
	 *
	 * Extracted from INFYP_AI_Optimizer::call_gemini_api_keywords().
	 *
	 * @param string $api_key      The API key.
	 * @param string $prompt       The prompt to send.
	 * @param int    $image_id     The attachment ID.
	 * @param string $model_name   The model to use.
	 * @param array  $call_params  Optional. Model-specific call parameters from the Model Config Registry.
	 * @return array|\WP_Error Parsed response array or WP_Error on failure.
	 */
	public function call_keywords( string $api_key, string $prompt, int $image_id, string $model_name, array $call_params = [] ): array|\WP_Error {
		$api_url = self::API_BASE_URL . $model_name . ':generateContent?key=' . $api_key;

		$base64_image = static::get_cached_base64( $image_id );
		if ( is_wp_error( $base64_image ) ) {
			return $base64_image;
		}
		$mime_type = get_post_mime_type( $image_id );

		$gen_config = [
			'response_mime_type' => 'application/json',
		];

		// Max output tokens from config
		if ( isset( $call_params['max_completion_tokens'] ) ) {
			$gen_config['maxOutputTokens'] = $call_params['max_completion_tokens'];
		}

		// Temperature from config (null = omit)
		if ( isset( $call_params['temperature'] ) && $call_params['temperature'] !== null ) {
			$gen_config['temperature'] = $call_params['temperature'];
		}

		// Thinking config from extras
		if ( ! empty( $call_params['extra']['thinking_config'] ) ) {
			$gen_config['thinking_config'] = $call_params['extra']['thinking_config'];
		}

		$body = [
			'contents'         => [ [ 'parts' => [
				[ 'text' => $prompt ],
				[ 'inline_data' => [ 'mime_type' => $mime_type, 'data' => $base64_image ] ],
			] ] ],
			'generationConfig' => $gen_config,
		];

		$response = wp_remote_post( $api_url, [
			'method'  => 'POST',
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( $body ),
			'timeout' => static::get_request_timeout(),
		] );

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( $response->get_error_code(), self::redact_key( $response->get_error_message() ) );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( $status_code < 200 || $status_code >= 300 ) {
			$msg = 'Gemini API error (HTTP ' . $status_code . ')';
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

		if ( isset( $data['candidates'][0]['content']['parts'][0]['text'] ) ) {
			$text_response   = $data['candidates'][0]['content']['parts'][0]['text'];
			$parsed_response = json_decode( $text_response, true );

			// Simple validation for keywords only
			if ( is_array( $parsed_response ) && isset( $parsed_response['keywords'] ) && is_array( $parsed_response['keywords'] ) ) {
				return $parsed_response;
			}

			return new \WP_Error( 'invalid_response_format', 'Keywords not in expected format.' );
		}

		$error_message = 'Could not parse response from Gemini API. ';
		if ( isset( $data['error']['message'] ) ) {
			$error_message .= $data['error']['message'];
		}

		return new \WP_Error( 'api_error', $error_message );
	}

	/**
	 * Call Gemini API for a single field optimization.
	 *
	 * Extracted from INFYP_AI_Optimizer::call_gemini_api_single_field().
	 *
	 * @param string $api_key      The API key.
	 * @param string $prompt       The prompt to send.
	 * @param int    $image_id     The attachment ID.
	 * @param string $model_name   The model to use.
	 * @param string $field_type   The specific field being optimized (e.g., 'alt_text', 'title', 'filename').
	 * @param array  $call_params  Optional. Model-specific call parameters from the Model Config Registry.
	 * @return array|\WP_Error Parsed response array or WP_Error on failure.
	 */
	public function call_single_field( string $api_key, string $prompt, int $image_id, string $model_name, string $field_type, array $call_params = [] ): array|\WP_Error {
		$api_url = self::API_BASE_URL . $model_name . ':generateContent?key=' . $api_key;

		// Get image file and convert to base64
		$base64_image = static::get_cached_base64( $image_id );
		if ( is_wp_error( $base64_image ) ) {
			return $base64_image;
		}
		$mime_type = get_post_mime_type( $image_id );

		// Build generation config from call_params or legacy defaults
		$gen_config = [
			'response_mime_type' => 'application/json',
		];

		// Max output tokens from config
		if ( isset( $call_params['max_completion_tokens'] ) ) {
			$gen_config['maxOutputTokens'] = $call_params['max_completion_tokens'];
		}

		// Temperature from config (null = omit)
		if ( isset( $call_params['temperature'] ) && $call_params['temperature'] !== null ) {
			$gen_config['temperature'] = $call_params['temperature'];
		}

		// Thinking config from extras
		if ( ! empty( $call_params['extra']['thinking_config'] ) ) {
			$gen_config['thinking_config'] = $call_params['extra']['thinking_config'];
		}

		$body = [
			'contents'         => [ [ 'parts' => [
				[ 'text' => $prompt ],
				[ 'inline_data' => [ 'mime_type' => $mime_type, 'data' => $base64_image ] ],
			] ] ],
			'generationConfig' => $gen_config,
		];

		// Log the request for debugging
		// error_log('[Gemini API] Request URL: ' . $api_url);
		// error_log('[Gemini API] Model: ' . $model_name);
		// error_log('[Gemini API] Prompt: ' . $prompt);

		$response = wp_remote_post( $api_url, [
			'method'  => 'POST',
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( $body ),
			'timeout' => static::get_request_timeout(),
		] );

		if ( is_wp_error( $response ) ) {
			// error_log('[Gemini API] WP Error: ' . $response->get_error_message());
			return $response;
		}

		$response_body = wp_remote_retrieve_body( $response );
		$data          = json_decode( $response_body, true );

		// Use same parsing as working main optimization
		if ( isset( $data['candidates'][0]['content']['parts'][0]['text'] ) ) {
			$text_response   = $data['candidates'][0]['content']['parts'][0]['text'];
			$parsed_response = json_decode( $text_response, true );

			// Check for the expected field
			if ( is_array( $parsed_response ) ) {
				// Check if the expected field exists
				if ( isset( $parsed_response[ $field_type ] ) ) {
					return $parsed_response;
				}

				// Fallback: Try to find any valid field in the response
				$possible_keys = apply_filters( 'infyp_ai_response_possible_keys', [
					'filename' => [ 'filename', 'file_name', 'name' ],
					'alt_text' => [ 'alt_text', 'altText', 'alt', 'description' ],
					'title'    => [ 'title', 'image_title', 'name' ],
				], $field_type );

				if ( isset( $possible_keys[ $field_type ] ) ) {
					foreach ( $possible_keys[ $field_type ] as $key ) {
						if ( isset( $parsed_response[ $key ] ) ) {
							// Return with the expected key
							return [ $field_type => $parsed_response[ $key ] ];
						}
					}
				}

				// Log for debugging if parsing fails
				// if (defined('WP_DEBUG') && WP_DEBUG) {
				//     error_log('[Gemini Single Field] Expected field "' . $field_type . '" not found.');
				//     error_log('[Gemini Single Field] Raw response: ' . $text_response);
				//     error_log('[Gemini Single Field] Parsed: ' . print_r($parsed_response, true));
				// }
			}

			return new \WP_Error( 'invalid_response_format', sprintf( 'The API did not return valid JSON with %s field.', $field_type ) );
		}

		$error_message = 'Could not parse response from Gemini API. ';
		if ( isset( $data['error']['message'] ) ) {
			$error_message .= $data['error']['message'];
		}

		return new \WP_Error( 'api_error', $error_message );
	}
}
