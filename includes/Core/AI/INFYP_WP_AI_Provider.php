<?php
/**
 * WordPress Core AI Provider
 *
 * Routes optimization requests through the WordPress 7.0+ core AI Client
 * instead of a vendor HTTP API. Credentials are managed entirely by core's
 * Connectors API (Settings → Connectors); the plugin never sees a key, so the
 * `$api_key` argument required by the provider interface is accepted and
 * ignored.
 *
 * @package IPAIS\Core\AI
 * @since 1.0.6
 */

// phpcs:disable WordPress.Files.FileName -- repo convention: class files are named after the class (INFYP_*.php).

namespace IPAIS\Core\AI;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class INFYP_WP_AI_Provider
 *
 * WordPress core AI Client implementation of the AI provider interface.
 *
 * The core client receives the same prompts the vendor providers do (forced
 * to the plain-JSON prompt format by INFYP_WP_AI_Support), a JSON schema for
 * the expected response shape, and the resolved model id as a *preference* —
 * core routes the request to a compatible model from whichever connector the
 * site owner configured.
 */
class INFYP_WP_AI_Provider extends INFYP_AI_Provider_Base {

	/**
	 * Get the display name of this provider.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'WordPress AI';
	}

	/**
	 * Get the slug identifier for this provider.
	 *
	 * @return string
	 */
	public function get_slug(): string {
		// phpcs:ignore WordPress.WP.CapitalPDangit -- lowercase provider slug, not prose.
		return 'wordpress';
	}

	/**
	 * Check if this provider supports the given model.
	 *
	 * The core AI Client accepts any model id as a preference, so support is
	 * governed purely by whether the WordPress AI connection method is
	 * active — never by the model string.
	 *
	 * @param string $model The model identifier.
	 * @return bool
	 */
	public function supports_model( string $model ): bool {
		return INFYP_WP_AI_Support::is_active();
	}

	/**
	 * Call the core AI Client for full optimization (all fields).
	 *
	 * @param string $api_key      Unused. Credentials are managed by core connectors.
	 * @param string $prompt       The prompt to send.
	 * @param int    $image_id     The attachment ID.
	 * @param string $model_name   The model to use (sent as a preference).
	 * @param array  $call_params  Optional. Model-specific call parameters from the Model Config Registry.
	 * @return array|\WP_Error Parsed response array or WP_Error on failure.
	 */
	public function call_full( string $api_key, string $prompt, int $image_id, string $model_name, array $call_params = array() ): array|\WP_Error {
		return $this->execute_prompt( $prompt, $image_id, $model_name, $call_params, self::get_full_schema() );
	}

	/**
	 * Call the core AI Client specifically for keyword generation.
	 *
	 * @param string $api_key      Unused. Credentials are managed by core connectors.
	 * @param string $prompt       The prompt to send.
	 * @param int    $image_id     The attachment ID.
	 * @param string $model_name   The model to use (sent as a preference).
	 * @param array  $call_params  Optional. Model-specific call parameters from the Model Config Registry.
	 * @return array|\WP_Error Parsed response array or WP_Error on failure.
	 */
	public function call_keywords( string $api_key, string $prompt, int $image_id, string $model_name, array $call_params = array() ): array|\WP_Error {
		$parsed = $this->execute_prompt( $prompt, $image_id, $model_name, $call_params, self::get_keywords_schema() );

		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		// Simple validation for keywords only (mirrors the vendor providers).
		if ( isset( $parsed['keywords'] ) && is_array( $parsed['keywords'] ) ) {
			return $parsed;
		}

		return new \WP_Error( 'invalid_response_format', 'Keywords not in expected format.' );
	}

	/**
	 * Call the core AI Client for a single field optimization.
	 *
	 * @param string $api_key      Unused. Credentials are managed by core connectors.
	 * @param string $prompt       The prompt to send.
	 * @param int    $image_id     The attachment ID.
	 * @param string $model_name   The model to use (sent as a preference).
	 * @param string $field_type   The specific field being optimized (e.g., 'alt_text', 'title', 'filename').
	 * @param array  $call_params  Optional. Model-specific call parameters from the Model Config Registry.
	 * @return array|\WP_Error Parsed response array or WP_Error on failure.
	 */
	public function call_single_field( string $api_key, string $prompt, int $image_id, string $model_name, string $field_type, array $call_params = array() ): array|\WP_Error {
		$parsed = $this->execute_prompt( $prompt, $image_id, $model_name, $call_params, self::get_single_field_schema( $field_type ) );

		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		if ( isset( $parsed[ $field_type ] ) ) {
			return array( $field_type => (string) $parsed[ $field_type ] );
		}

		/** This filter is documented in includes/Core/AI/INFYP_Gemini_Provider.php */
		$possible_keys = apply_filters(
			'infyp_ai_response_possible_keys',
			array(
				'filename' => array( 'filename', 'file_name', 'name' ),
				'alt_text' => array( 'alt_text', 'altText', 'alt', 'description' ),
				'title'    => array( 'title', 'image_title', 'name' ),
			),
			$field_type
		);

		if ( isset( $possible_keys[ $field_type ] ) ) {
			foreach ( $possible_keys[ $field_type ] as $key ) {
				if ( isset( $parsed[ $key ] ) ) {
					return array( $field_type => (string) $parsed[ $key ] );
				}
			}
		}

		return new \WP_Error( 'invalid_response_format', sprintf( 'The API did not return valid JSON with %s field.', $field_type ) );
	}

	/**
	 * Execute a prompt through the core AI Client and decode the JSON reply.
	 *
	 * @param string $prompt      The prompt to send.
	 * @param int    $image_id    The attachment ID.
	 * @param string $model_name  Resolved API model id, sent as a preference.
	 * @param array  $call_params Model call parameters (max tokens, temperature).
	 * @param array  $schema      JSON schema describing the expected response.
	 * @return array|\WP_Error Decoded response array or WP_Error on failure.
	 */
	private function execute_prompt( string $prompt, int $image_id, string $model_name, array $call_params, array $schema ): array|\WP_Error {
		if ( ! INFYP_WP_AI_Support::is_configured() ) {
			return new \WP_Error(
				'wp_ai_not_configured',
				esc_html__( 'No AI connector is configured in WordPress. Configure one under Settings → Connectors or switch back to plugin API keys.', 'infy-ai-image-seo-optimizer' )
			);
		}

		if ( self::may_reach_anthropic( $model_name ) ) {
			$schema = self::strip_anthropic_unsupported( $schema );
		}

		try {
			$builder = INFYP_WP_AI_Support::prompt( $prompt );

			$builder = $this->attach_image( $builder, $image_id );
			if ( is_wp_error( $builder ) ) {
				return $builder;
			}

			$builder = $builder->using_model_preference( $model_name );

			// The registry's per-model token caps are deliberately NOT applied
			// here. The model is only a preference — when its provider has no
			// connector, core silently serves the request with the connector's
			// own default model, and a cap tuned for the selected model can
			// starve a substituted reasoning model into truncated, unparseable
			// output (observed: a preferred small model was served by a
			// reasoning model that returned 5 bytes of JSON under a 500-token cap).
			// Core and the connector apply their own output limits.

			// Temperature from config (null = omit). Provider-specific extras
			// (e.g. Gemini thinking_config) are intentionally ignored — core
			// decides which provider actually serves the request.
			if ( isset( $call_params['temperature'] ) && null !== $call_params['temperature'] ) {
				$builder = $builder->using_temperature( (float) $call_params['temperature'] );
			}

			// generate_result() instead of generate_text(): the result DTO
			// carries the metadata of the model that ACTUALLY served the
			// request. Core treats the model as a preference and may
			// substitute another connected provider's model — the usage log
			// must record the serving model, not the requested one.
			self::$last_serving_model = '';
			$result = $builder->as_json_response( $schema )->generate_result();
		} catch ( \Throwable $e ) {
			return $this->map_throwable( $e );
		}

		if ( is_wp_error( $result ) ) {
			return $this->map_error( $result );
		}

		$text = '';
		try {
			$text = (string) $result->toText();
			self::$last_serving_model = (string) $result->getModelMetadata()->getId();
		} catch ( \Throwable $e ) {
			// Metadata extraction must never break generation.
			if ( '' === $text ) {
				return $this->map_throwable( $e );
			}
		}

		$parsed = json_decode( $text, true );
		if ( is_array( $parsed ) ) {
			return $parsed;
		}

		return new \WP_Error( 'invalid_response_format', 'The API did not return valid JSON.' );
	}

	/**
	 * Model id that served the most recent core AI Client request.
	 *
	 * Empty when no request ran yet or metadata was unavailable. Used by the
	 * usage tracker so substituted requests are logged truthfully.
	 *
	 * @since 1.0.6
	 *
	 * @var string
	 */
	private static $last_serving_model = '';

	/**
	 * The model id that actually served the last request, if known.
	 *
	 * @since 1.0.6
	 *
	 * @return string Serving model id, or empty string when unknown.
	 */
	public static function get_last_serving_model() {
		return self::$last_serving_model;
	}

	/**
	 * Attach the image to the prompt builder.
	 *
	 * Input form verified against the SDK bundled in WordPress 7.1
	 * (`WordPress\AiClient\Files\DTO\File`): the File DTO auto-detects URLs,
	 * data URIs, local paths and plain base64. A data URI is used here so the
	 * existing base64 pipeline — including its >1MB downsizing to intermediate
	 * sizes and per-request caching — is reused unchanged.
	 *
	 * @param object $builder  The core AI Client prompt builder.
	 * @param int    $image_id The attachment ID.
	 * @return object|\WP_Error The builder with the file attached, or WP_Error.
	 */
	private function attach_image( $builder, int $image_id ) {
		$base64_image = static::get_cached_base64( $image_id );
		if ( is_wp_error( $base64_image ) ) {
			return $base64_image;
		}

		$mime_type = get_post_mime_type( $image_id );
		if ( ! is_string( $mime_type ) || '' === $mime_type ) {
			$mime_type = 'image/jpeg';
		}

		return $builder->with_file( 'data:' . $mime_type . ';base64,' . $base64_image );
	}

	/**
	 * Map a WP_Error from the core AI Client to the plugin's error taxonomy.
	 *
	 * The retry handler classifies errors by code and message patterns, so
	 * rate-limit and transient failures are normalized to the same codes and
	 * phrases the vendor providers emit.
	 *
	 * @param \WP_Error $error The error returned by the core AI Client.
	 * @return \WP_Error Mapped error.
	 */
	private function map_error( \WP_Error $error ): \WP_Error {
		$code     = (string) $error->get_error_code();
		$message  = (string) $error->get_error_message();
		$haystack = strtolower( $code . ' ' . $message );

		if ( $this->contains_any( $haystack, array( '429', 'rate limit', 'rate_limit', 'too many requests', 'too_many_requests', 'quota' ) ) ) {
			return new \WP_Error( 'rate_limit', esc_html__( 'Rate limit exceeded. Please wait and try again.', 'infy-ai-image-seo-optimizer' ) );
		}

		// The provider answered with a 4xx (core code `*_client_error`): report
		// its reason. Checked before the connector patterns below, which a
		// provider reason such as "... is not supported" would otherwise match.
		// `wp_ai_client_error` is this class's own code for thrown exceptions.
		if ( 'wp_ai_client_error' !== $code && '_client_error' === substr( $code, -13 ) ) {
			return new \WP_Error(
				'api_http_error',
				sprintf(
					/* translators: %s: Error message */
					__( 'Optimization error: %s', 'infy-ai-image-seo-optimizer' ),
					sanitize_text_field( $message )
				)
			);
		}

		if ( $this->contains_any( $haystack, array( '500', '502', '503', '504', 'server error', 'unavailable', 'timeout', 'timed out', 'overloaded' ) ) ) {
			return new \WP_Error( 'server_error', esc_html__( 'AI service temporarily unavailable.', 'infy-ai-image-seo-optimizer' ) );
		}

		if ( $this->contains_any( $haystack, array( 'no model', 'no provider', 'not configured', 'no connector', 'prevented', 'not supported' ) ) ) {
			return new \WP_Error(
				'wp_ai_not_configured',
				esc_html__( 'No AI connector is configured in WordPress. Configure one under Settings → Connectors or switch back to plugin API keys.', 'infy-ai-image-seo-optimizer' )
			);
		}

		// Pass through — core messages never contain credentials.
		return $error;
	}

	/**
	 * Map an exception thrown by the core AI Client or its SDK.
	 *
	 * @param \Throwable $e The thrown exception.
	 * @return \WP_Error Mapped error.
	 */
	private function map_throwable( \Throwable $e ): \WP_Error {
		return $this->map_error( new \WP_Error( 'wp_ai_client_error', $e->getMessage() ) );
	}

	/**
	 * Check whether a haystack contains any of the given needles.
	 *
	 * @param string   $haystack Lower-cased text to search.
	 * @param string[] $needles  Substrings to look for.
	 * @return bool
	 */
	private function contains_any( string $haystack, array $needles ): bool {
		foreach ( $needles as $needle ) {
			if ( false !== strpos( $haystack, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether Anthropic may serve a request for this model.
	 *
	 * True for a Claude model, and when Anthropic has a connector but the
	 * preferred model's provider has none: core then serves the request with
	 * another connected provider's model.
	 *
	 * @param string $model_name Resolved API model id.
	 * @return bool
	 */
	private static function may_reach_anthropic( string $model_name ): bool {
		$model = strtolower( $model_name );
		if ( false !== strpos( $model, 'claude' ) ) {
			return true;
		}

		$connected = INFYP_WP_AI_Support::get_connected_provider_slugs();
		if ( ! in_array( 'anthropic', $connected, true ) ) {
			return false;
		}

		$own = false !== strpos( $model, 'gemini' ) ? 'google' : 'openai';
		return ! in_array( $own, $connected, true );
	}

	/**
	 * Remove the JSON schema keywords Anthropic's structured output rejects.
	 *
	 * Anthropic answers HTTP 400 for numeric and string constraints and for
	 * array constraints other than minItems 0 or 1. The plugin enforces its
	 * own limits after the response (keywords are capped by the
	 * infyp_max_keywords filter), so dropping them changes no stored result.
	 *
	 * @param array $schema JSON schema.
	 * @return array Schema without the unsupported keywords.
	 */
	private static function strip_anthropic_unsupported( array $schema ): array {
		if ( isset( $schema['type'] ) && is_string( $schema['type'] ) ) {
			foreach ( array( 'maxItems', 'uniqueItems', 'minLength', 'maxLength', 'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf' ) as $keyword ) {
				unset( $schema[ $keyword ] );
			}
			if ( isset( $schema['minItems'] ) && $schema['minItems'] > 1 ) {
				$schema['minItems'] = 1;
			}
		}

		foreach ( $schema as $key => $value ) {
			if ( is_array( $value ) ) {
				$schema[ $key ] = self::strip_anthropic_unsupported( $value );
			}
		}

		return $schema;
	}

	/**
	 * JSON schema for the full optimization response.
	 *
	 * @return array
	 */
	private static function get_full_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'keywords' => array(
					'type'     => 'array',
					'items'    => array( 'type' => 'string' ),
					'minItems' => 1,
					'maxItems' => 10,
				),
				'filename' => array( 'type' => 'string' ),
				'alt_text' => array( 'type' => 'string' ),
				'title'    => array( 'type' => 'string' ),
			),
			'required'             => array( 'keywords', 'filename', 'alt_text', 'title' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * JSON schema for the keywords-only response.
	 *
	 * @return array
	 */
	private static function get_keywords_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'keywords' => array(
					'type'     => 'array',
					'items'    => array( 'type' => 'string' ),
					'minItems' => 1,
					'maxItems' => 10,
				),
			),
			'required'             => array( 'keywords' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * JSON schema for a single-field response.
	 *
	 * @param string $field_type The field being optimized.
	 * @return array
	 */
	private static function get_single_field_schema( string $field_type ): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				$field_type => array( 'type' => 'string' ),
			),
			'required'             => array( $field_type ),
			'additionalProperties' => false,
		);
	}
}
