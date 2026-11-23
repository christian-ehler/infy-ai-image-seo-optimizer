<?php
/**
 * AI Provider Manager - Centralized Router for AI Provider API Calls
 *
 * This file implements the Manager class for routing API calls to the correct
 * AI provider based on the selected model. It follows the same Singleton + Adapter
 * pattern used by INFYP_PageBuilder_Manager, replacing the inline routing logic
 * previously scattered across INFYP_AI_Optimizer methods (execute_single_field_api_call,
 * execute_keywords_api_call, execute_with_format_retry).
 *
 * The manager automatically registers available AI providers, resolves model names
 * (including Claude alias mapping), and delegates API calls to the appropriate
 * provider implementation.
 *
 * Architecture:
 * - Singleton pattern for global coordination
 * - Adapter pattern for provider extensibility
 * - Strategy pattern for provider selection
 * - Observer pattern via WordPress hooks
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

use IPAIS\Core\INFYP_Logger;
use IPAIS\Core\AI\INFYP_Model_Config_Registry;

/**
 * AI Provider Manager Class
 *
 * Central coordinator for managing AI provider adapters and routing API calls
 * to the correct provider based on the selected model.
 *
 * This class implements a Singleton pattern to provide a centralized point of control
 * for all AI provider operations. It automatically registers providers, handles
 * model-to-provider resolution, and delegates API calls.
 *
 * Built-in Providers:
 * - OpenAI (GPT models) - via INFYP_OpenAI_Provider
 * - Google Gemini - via INFYP_Gemini_Provider
 * - Anthropic Claude - via INFYP_Claude_Provider
 *
 * Extensibility:
 * The `infyp_register_ai_providers` filter allows extensions or third-party
 * plugins to register additional AI providers.
 *
 * Usage Example:
 * ```php
 * $manager = INFYP_AI_Provider_Manager::get_instance();
 *
 * // Full optimization (all SEO fields)
 * $result = $manager->execute_full( $model_id, $api_key, $prompt, $image_id );
 *
 * // Keywords only
 * $result = $manager->execute_keywords( $model_id, $api_key, $prompt, $image_id );
 *
 * // Single field
 * $result = $manager->execute_single_field( $model_id, $api_key, $prompt, $image_id, 'alt_text' );
 * ```
 *
 * Hooks Fired:
 * - `infyp_register_ai_providers`: Filter to register custom AI providers
 * - `infyp_before_ai_api_call`: Action before any API call is executed
 * - `infyp_after_ai_api_call`: Action after any API call completes
 *
 * @package   IPAIS\Core\AI
 * @since 1.0.0
 *
 * @see       INFYP_AI_Provider_Interface For provider contract
 * @see       INFYP_AI_Provider_Base For base provider implementation
 */
class INFYP_AI_Provider_Manager {

	/**
	 * Singleton instance
	 *
	 * @since 1.0.0
	 * @var   INFYP_AI_Provider_Manager|null
	 */
	private static $instance = null;

	/**
	 * Registered AI providers
	 *
	 * Associative array of AI providers implementing the
	 * INFYP_AI_Provider_Interface. Keys are provider slugs
	 * (e.g., 'openai', 'google', 'anthropic'), values are provider instances.
	 *
	 * @since 1.0.0
	 * @var   array<string, INFYP_AI_Provider_Interface>
	 */
	private $providers = [];

	/**
	 * Private constructor (Singleton pattern)
	 *
	 * Initializes the manager by registering all available AI providers.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {
		$this->init_providers();
	}

	/**
	 * Get singleton instance
	 *
	 * Returns the single instance of the AI provider manager, creating
	 * it if it doesn't exist.
	 *
	 * @since 1.0.0
	 *
	 * @return INFYP_AI_Provider_Manager The singleton instance.
	 */
	public static function get_instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Prevent cloning of the singleton instance
	 *
	 * @since 1.0.0
	 */
	private function __clone() {}

	/**
	 * Prevent unserialization of the singleton instance
	 *
	 * @since 1.0.0
	 *
	 * @throws \Exception Always throws to prevent unserialization.
	 */
	public function __wakeup() {
		throw new \Exception( 'Cannot unserialize singleton' );
	}

	/**
	 * Initialize all available AI providers
	 *
	 * Registers the built-in providers (OpenAI, Gemini, Claude) and allows
	 * extensions to add additional providers via the infyp_register_ai_providers filter.
	 *
	 * @since 1.0.0
	 */
	private function init_providers() {
		// Register built-in providers
		$this->providers['openai']    = new INFYP_OpenAI_Provider();
		$this->providers['google']    = new INFYP_Gemini_Provider();
		$this->providers['anthropic'] = new INFYP_Claude_Provider();

		// WordPress core AI Client (WP 7.0+). Registered whenever available —
		// not only when selected — so display-name resolution (e.g. the API
		// usage table) can see it while the user is still on API keys.
		if ( INFYP_WP_AI_Support::is_available() ) {
			$this->providers['wordpress'] = new INFYP_WP_AI_Provider();
		}

		/**
		 * Allow external plugins to register additional AI providers.
		 *
		 * This filter enables extensions to add support for additional AI services
		 * without modifying the core plugin code.
		 *
		 * @since 1.0.0
		 *
		 * @param array $providers Associative array of registered providers.
		 *                         Key: provider slug (e.g., 'openai', 'google', 'anthropic')
		 *                         Value: provider instance implementing INFYP_AI_Provider_Interface
		 *
		 * @return array Modified providers array.
		 *
		 * @example
		 * add_filter( 'infyp_register_ai_providers', function( $providers ) {
		 *     $providers['custom_ai'] = new My_Custom_AI_Provider();
		 *     return $providers;
		 * } );
		 */
		$this->providers = apply_filters( 'infyp_register_ai_providers', $this->providers );

		// Validate all providers implement the required interface.
		$this->providers = array_filter( $this->providers, function( $provider ) {
			return $provider instanceof INFYP_AI_Provider_Interface;
		} );

		// Log registered providers in debug mode on the plugin admin page
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only checking page context for debug logging
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && is_admin() && isset( $_GET['page'] ) && sanitize_text_field( wp_unslash( $_GET['page'] ) ) === 'infy-ai-image-seo-optimizer' ) {
			INFYP_Logger::log( 'Initialized AI providers: ' . implode( ', ', array_keys( $this->providers ) ), 'DEBUG' );
		}
	}

	/**
	 * Get the provider that supports the given model.
	 *
	 * Loops through all registered providers and returns the first one
	 * whose supports_model() method returns true for the given model name.
	 *
	 * @since 1.0.0
	 *
	 * @param string $model The AI model identifier (a model id or alias registered in INFYP_Model_Config_Registry).
	 *
	 * @return INFYP_AI_Provider_Interface|null The matching provider instance, or null if no provider supports the model.
	 */
	public function get_provider_for_model( string $model ): ?INFYP_AI_Provider_Interface {
		// While the WordPress AI connection method is active, every model is
		// served by the core AI Client (the model id becomes a preference).
		// Checked first because the vendor providers' prefix matches would
		// otherwise win for gemini/gpt/claude model ids.
		if ( INFYP_WP_AI_Support::is_active() && isset( $this->providers['wordpress'] ) ) {
			return $this->providers['wordpress'];
		}

		foreach ( $this->providers as $provider ) {
			if ( $provider->supports_model( $model ) ) {
				return $provider;
			}
		}

		return null;
	}

	/**
	 * Resolve a model name to its full API identifier.
	 *
	 * Some providers use alias names that need to be mapped to full API model
	 * identifiers, for example a short id that maps to a dated snapshot id
	 * (the registry's api_model_id).
	 *
	 * If the provider implements a resolve_model() method, it will be called
	 * to perform the mapping. Otherwise, the original model name is returned.
	 *
	 * @since 1.0.0
	 *
	 * @param string $model The AI model identifier, possibly an alias.
	 *
	 * @return string The resolved model name suitable for API calls.
	 */
	public function resolve_model_name( string $model ): string {
		return INFYP_Model_Config_Registry::get_instance()->resolve_api_model_id( $model );
	}

	/**
	 * Execute a full optimization API call (all SEO fields).
	 *
	 * Routes the API call to the correct provider based on the model name.
	 * Replaces the inline routing logic previously in INFYP_AI_Optimizer::execute_with_format_retry().
	 *
	 * @since 1.0.0
	 *
	 * @param string $model    The AI model identifier (a model id or alias registered in INFYP_Model_Config_Registry).
	 * @param string $api_key  The API key for authenticating with the provider.
	 * @param string $prompt   The constructed prompt to send to the AI model.
	 * @param int    $image_id The WordPress attachment ID of the image being optimized.
	 *
	 * @return array|\WP_Error Associative array of generated SEO fields on success, WP_Error on failure.
	 *                         Expected keys: 'keywords', 'filename', 'alt_text', 'title'.
	 */
	public function execute_full( string $model, string $api_key, string $prompt, int $image_id ) {
		$registry = INFYP_Model_Config_Registry::get_instance();
		$config   = $registry->get_config( $model );

		if ( ! $config ) {
			return new \WP_Error(
				'unconfigured_model',
				'The selected AI model does not have a configuration. Please select a configured model or register a model config via the infyp_register_model_configs action.'
			);
		}

		$provider = $this->get_provider_for_model( $model );
		if ( ! $provider ) {
			INFYP_Logger::log( 'No provider found for model: ' . $model, 'ERROR' );
			return new \WP_Error( 'invalid_model', 'No AI provider found for model: ' . $model );
		}

		$resolved_model = $registry->resolve_api_model_id( $model );
		$call_params    = $config['call_params']['full'] ?? [];

		/**
		 * Fires before an AI API call is executed.
		 *
		 * @since 1.0.0
		 *
		 * @param string $call_type      The type of call ('full', 'keywords', 'single_field').
		 * @param string $model          The original model identifier.
		 * @param string $resolved_model The resolved model name for the API.
		 * @param string $provider_slug  The provider slug handling the call.
		 * @param int    $image_id       The attachment ID being processed.
		 */
		do_action( 'infyp_before_ai_api_call', 'full', $model, $resolved_model, $provider->get_slug(), $image_id );

		$result = $provider->call_full( $api_key, $prompt, $image_id, $resolved_model, $call_params );

		/**
		 * Fires after an AI API call completes.
		 *
		 * @since 1.0.0
		 *
		 * @param string         $call_type     The type of call ('full', 'keywords', 'single_field').
		 * @param array|\WP_Error $result       The API call result.
		 * @param string         $model         The original model identifier.
		 * @param string         $provider_slug The provider slug that handled the call.
		 * @param int            $image_id      The attachment ID that was processed.
		 */
		do_action( 'infyp_after_ai_api_call', 'full', $result, $model, $provider->get_slug(), $image_id );

		return $result;
	}

	/**
	 * Execute a keywords-only API call.
	 *
	 * Routes the API call to the correct provider based on the model name.
	 * Replaces the inline routing logic previously in INFYP_AI_Optimizer::execute_keywords_api_call().
	 *
	 * @since 1.0.0
	 *
	 * @param string $model    The AI model identifier (a model id or alias registered in INFYP_Model_Config_Registry).
	 * @param string $api_key  The API key for authenticating with the provider.
	 * @param string $prompt   The constructed prompt for keyword generation.
	 * @param int    $image_id The WordPress attachment ID of the image being analyzed.
	 *
	 * @return array|\WP_Error Associative array containing 'keywords' array on success, WP_Error on failure.
	 */
	public function execute_keywords( string $model, string $api_key, string $prompt, int $image_id ) {
		$registry = INFYP_Model_Config_Registry::get_instance();
		$config   = $registry->get_config( $model );

		if ( ! $config ) {
			return new \WP_Error(
				'unconfigured_model',
				'The selected AI model does not have a configuration. Please select a configured model or register a model config via the infyp_register_model_configs action.'
			);
		}

		$provider = $this->get_provider_for_model( $model );
		if ( ! $provider ) {
			INFYP_Logger::log( 'No provider found for model: ' . $model, 'ERROR' );
			return new \WP_Error( 'invalid_model', 'No AI provider found for model: ' . $model );
		}

		$resolved_model = $registry->resolve_api_model_id( $model );
		$call_params    = $config['call_params']['keywords'] ?? [];

		/** This action is documented in INFYP_AI_Provider_Manager::execute_full() */
		do_action( 'infyp_before_ai_api_call', 'keywords', $model, $resolved_model, $provider->get_slug(), $image_id );

		$result = $provider->call_keywords( $api_key, $prompt, $image_id, $resolved_model, $call_params );

		/** This action is documented in INFYP_AI_Provider_Manager::execute_full() */
		do_action( 'infyp_after_ai_api_call', 'keywords', $result, $model, $provider->get_slug(), $image_id );

		return $result;
	}

	/**
	 * Execute a single-field optimization API call.
	 *
	 * Routes the API call to the correct provider based on the model name.
	 * Replaces the inline routing logic previously in INFYP_AI_Optimizer::execute_single_field_api_call().
	 *
	 * @since 1.0.0
	 *
	 * @param string $model      The AI model identifier (a model id or alias registered in INFYP_Model_Config_Registry).
	 * @param string $api_key    The API key for authenticating with the provider.
	 * @param string $prompt     The constructed prompt for field optimization.
	 * @param int    $image_id   The WordPress attachment ID of the image being optimized.
	 * @param string $field_type The type of field being optimized (e.g., 'alt_text', 'title', 'filename').
	 *
	 * @return array|\WP_Error Associative array containing the optimized field value on success, WP_Error on failure.
	 */
	public function execute_single_field( string $model, string $api_key, string $prompt, int $image_id, string $field_type ) {
		$registry = INFYP_Model_Config_Registry::get_instance();
		$config   = $registry->get_config( $model );

		if ( ! $config ) {
			return new \WP_Error(
				'unconfigured_model',
				'The selected AI model does not have a configuration. Please select a configured model or register a model config via the infyp_register_model_configs action.'
			);
		}

		$provider = $this->get_provider_for_model( $model );
		if ( ! $provider ) {
			INFYP_Logger::log( 'No provider found for model: ' . $model, 'ERROR' );
			return new \WP_Error( 'invalid_model', 'No AI provider found for model: ' . $model );
		}

		$resolved_model = $registry->resolve_api_model_id( $model );
		$call_params    = $config['call_params']['single_field'] ?? [];

		/** This action is documented in INFYP_AI_Provider_Manager::execute_full() */
		do_action( 'infyp_before_ai_api_call', 'single_field', $model, $resolved_model, $provider->get_slug(), $image_id );

		$result = $provider->call_single_field( $api_key, $prompt, $image_id, $resolved_model, $field_type, $call_params );

		/** This action is documented in INFYP_AI_Provider_Manager::execute_full() */
		do_action( 'infyp_after_ai_api_call', 'single_field', $result, $model, $provider->get_slug(), $image_id );

		return $result;
	}

	/**
	 * Get all registered AI providers.
	 *
	 * Returns the full associative array of registered provider instances,
	 * keyed by provider slug.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, INFYP_AI_Provider_Interface> Registered providers.
	 */
	public function get_registered_providers(): array {
		return $this->providers;
	}
}
