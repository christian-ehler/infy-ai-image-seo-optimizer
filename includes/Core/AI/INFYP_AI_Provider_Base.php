<?php
/**
 * Abstract Base Class for AI Providers
 *
 * Provides shared utility methods used by all AI provider implementations.
 * Concrete provider classes (OpenAI, Gemini, Claude) extend this base to
 * inherit common functionality like request timeouts and base64 image caching.
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
 * Abstract Class INFYP_AI_Provider_Base
 *
 * Implements the INFYP_AI_Provider_Interface and provides shared utility
 * methods for all AI provider implementations. Concrete providers must
 * implement the abstract API call methods defined by the interface.
 *
 * Shared Functionality:
 * - Request timeout configuration retrieval
 * - Base64 image encoding with transient caching
 * - Cache cleanup for processed images
 *
 * @since 1.0.0
 *
 * @see INFYP_AI_Provider_Interface For the contract this class implements.
 */
abstract class INFYP_AI_Provider_Base implements INFYP_AI_Provider_Interface {

	/**
	 * Get the human-readable display name of the AI provider.
	 *
	 * @since 1.0.0
	 *
	 * @return string The provider display name.
	 */
	abstract public function get_name(): string;

	/**
	 * Get the unique slug identifier for this provider.
	 *
	 * @since 1.0.0
	 *
	 * @return string The provider slug.
	 */
	abstract public function get_slug(): string;

	/**
	 * Check whether this provider supports a given model name.
	 *
	 * @since 1.0.0
	 *
	 * @param string $model The model identifier to check.
	 *
	 * @return bool True if the model is supported by this provider.
	 */
	abstract public function supports_model( string $model ): bool;

	/**
	 * Make a full optimization API call to generate all SEO fields.
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
	abstract public function call_full( string $api_key, string $prompt, int $image_id, string $model_name, array $call_params = [] ): array|\WP_Error;

	/**
	 * Make an API call specifically for keyword generation.
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
	abstract public function call_keywords( string $api_key, string $prompt, int $image_id, string $model_name, array $call_params = [] ): array|\WP_Error;

	/**
	 * Make an API call to optimize a single metadata field.
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
	abstract public function call_single_field( string $api_key, string $prompt, int $image_id, string $model_name, string $field_type, array $call_params = [] ): array|\WP_Error;

	/**
	 * Get the configured request timeout for API calls.
	 *
	 * Retrieves the timeout value from the plugin's optimization settings.
	 * Enforces a 60s floor — legacy installs may still have an old 30s value
	 * saved in the option, and there is currently no admin UI to update it.
	 *
	 * @since 1.0.0
	 *
	 * @return int Timeout in seconds (floor 60, ceiling 120).
	 */
	protected static function get_request_timeout() {
		$settings = get_option( 'infyp_optimization_settings', [] );
		$timeout  = isset( $settings['request_timeout'] ) ? absint( $settings['request_timeout'] ) : 60;
		return max( 60, min( 120, $timeout ) );
	}

	/**
	 * Get base64-encoded image data with transient caching.
	 *
	 * Caches the base64 string in a transient to avoid repeated
	 * file reads and encoding during bulk operations where the same
	 * image is processed across multiple API calls.
	 *
	 * @since 1.0.0
	 *
	 * @param int $image_id The attachment ID.
	 *
	 * @return string|\WP_Error Base64-encoded image data or WP_Error on failure.
	 */
	/**
	 * Per-request static cache for base64 image data.
	 * Avoids writing multi-MB strings to wp_options (transients).
	 *
	 * @var array<int, string>
	 */
	private static $base64_cache = [];

	public static function get_cached_base64( $image_id ) {
		if ( isset( self::$base64_cache[ $image_id ] ) ) {
			return self::$base64_cache[ $image_id ];
		}

		$image_path = get_attached_file( $image_id );
		if ( ! $image_path || ! file_exists( $image_path ) ) {
			return new \WP_Error( 'image_fetch_failed', 'Could not find the local image file on the server.' );
		}

		// For images > 1MB, swap in an intermediate size. Vision models rescale
		// internally, so sending full-res bloats the base64 payload without accuracy gain.
		if ( filesize( $image_path ) > 1024 * 1024 ) {
			$sizes_to_try = [ 'large', 'medium_large', 'medium' ];
			$original_dir = trailingslashit( dirname( $image_path ) );

			foreach ( $sizes_to_try as $size ) {
				$intermediate = image_get_intermediate_size( $image_id, $size );
				if ( $intermediate && ! empty( $intermediate['file'] ) ) {
					$candidate_path = $original_dir . $intermediate['file'];
					// If the file exists and is under 1MB (or we're on the last fallback 'medium'), use it.
					if ( file_exists( $candidate_path ) && ( filesize( $candidate_path ) <= 1024 * 1024 || $size === 'medium' ) ) {
						$image_path = $candidate_path;
						break;
					}
				}
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local file, not a URL
		$image_data = file_get_contents( $image_path );
		if ( false === $image_data ) {
			return new \WP_Error( 'image_read_failed', 'Could not read the image file from the server.' );
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required for API payloads
		$base64_image = base64_encode( $image_data );

		self::$base64_cache[ $image_id ] = $base64_image;

		return $base64_image;
	}

	/**
	 * Clear cached base64 data for an image.
	 *
	 * Should be called after an image finishes processing (all fields done)
	 * to free memory from the transients table.
	 *
	 * @since 1.0.0
	 *
	 * @param int $image_id The attachment ID.
	 *
	 * @return void
	 */
	public static function clear_cached_base64( $image_id ) {
		unset( self::$base64_cache[ $image_id ] );
	}
}
