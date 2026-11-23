<?php
/**
 * Model Config Registry - Centralized Store for AI Model Configurations
 *
 * This file implements a singleton registry that stores configuration arrays for
 * every AI model the plugin can use. Each model must have a registered config
 * to be usable; the Provider Manager gates on config existence, preventing
 * unconfigured models from executing API calls.
 *
 * The plugin ships configs for Google Gemini, Anthropic Claude and OpenAI
 * models (see register_builtin_models()). Additional models can be
 * registered via the `infyp_register_model_configs` action.
 *
 * Architecture:
 * - Singleton pattern for global coordination
 * - Lazy initialization on first access
 * - Extensible via `infyp_register_model_configs` action hook
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

/**
 * Model Config Registry Class
 *
 * Centralized store for AI model configurations. Every model that the plugin
 * can call must have a registered config containing its identity, provider,
 * call parameters, and pricing information.
 *
 * The registry is initialized lazily on first access. Built-in models are
 * registered internally, then the `infyp_register_model_configs` action fires
 * to allow extensions to register additional models.
 *
 * Usage Example:
 * ```php
 * $registry = INFYP_Model_Config_Registry::get_instance();
 *
 * // Check if a model is configured
 * if ( $registry->has_config( 'gpt-6-luna' ) ) {
 *     $config = $registry->get_config( 'gpt-6-luna' );
 *     $params = $registry->get_call_params( 'gpt-6-luna', 'full' );
 * }
 *
 * // Get dropdown data for settings page
 * $dropdown = $registry->get_dropdown_models();
 * // Returns: ['OpenAI' => ['gpt-6-luna' => 'GPT-6 Luna (...)'], ...]
 * ```
 *
 * Hooks Fired:
 * - `infyp_register_model_configs`: Action to register custom model configs
 *
 * @package   IPAIS\Core\AI
 * @since 1.0.0
 *
 * @see       INFYP_AI_Provider_Manager For provider routing that consumes configs
 */
class INFYP_Model_Config_Registry {

	/**
	 * Singleton instance
	 *
	 * @since 1.0.0
	 * @var   INFYP_Model_Config_Registry|null
	 */
	private static ?self $instance = null;

	/**
	 * Registered model configurations
	 *
	 * Associative array keyed by model_id (and aliases). Each value is a
	 * complete model config array containing identity, provider, call_params,
	 * and pricing information.
	 *
	 * @since 1.0.0
	 * @var   array<string, array>
	 */
	private array $configs = [];

	/**
	 * Whether the registry has been initialized
	 *
	 * Set to true after free models are registered and the
	 * `infyp_register_model_configs` action has fired.
	 *
	 * @since 1.0.0
	 * @var   bool
	 */
	private bool $initialized = false;

	/**
	 * Private constructor (Singleton pattern)
	 *
	 * Empty by design. Initialization is deferred to init() which is
	 * called lazily on first public access.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {}

	/**
	 * Get singleton instance
	 *
	 * Returns the single instance of the model config registry, creating
	 * it if it doesn't exist.
	 *
	 * @since 1.0.0
	 *
	 * @return self The singleton instance.
	 */
	public static function get_instance(): self {
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
	 * Initialize the registry (lazy)
	 *
	 * Called automatically on first public access. Registers the built-in
	 * model configs, then fires the `infyp_register_model_configs` action
	 * to allow extensions to register additional models.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function init(): void {
		if ( $this->initialized ) {
			return;
		}

		$this->register_builtin_models();

		/**
		 * Fires to allow extensions to register additional AI model configs.
		 *
		 * Extensions and third-party plugins hook here to register their
		 * model configurations. Each config must pass validation in register().
		 *
		 * @since 1.0.0
		 *
		 * @param INFYP_Model_Config_Registry $registry The registry instance.
		 *                                              Call $registry->register( $config ) to add models.
		 *
		 * @example
		 * add_action( 'infyp_register_model_configs', function( $registry ) {
		 *     $registry->register( [
		 *         'model_id'      => 'example-reasoning-model',
		 *         'api_model_id'  => 'example-reasoning-model-2026-01-01',
		 *         'display_name'  => 'Example Reasoning Model',
		 *         'description'   => 'Cost-efficient reasoning',
		 *         'provider'      => 'openai',
		 *         'group'         => 'OpenAI',
		 *         'call_params'   => [
		 *             'full'         => ['max_completion_tokens' => 8192, 'temperature' => null, 'response_format' => ['type' => 'json_object'], 'extra' => ['reasoning_effort' => 'low']],
		 *             'keywords'     => ['max_completion_tokens' => 8192, 'temperature' => null, 'response_format' => ['type' => 'json_object'], 'extra' => ['reasoning_effort' => 'low']],
		 *             'single_field' => ['max_completion_tokens' => 8192, 'temperature' => null, 'response_format' => ['type' => 'json_object'], 'extra' => ['reasoning_effort' => 'low']],
		 *         ],
		 *         'pricing' => ['blended_per_million' => 3.00],
		 *     ] );
		 * } );
		 */
		do_action( 'infyp_register_model_configs', $this );

		$this->initialized = true;
	}

	/**
	 * Register the built-in model configurations
	 *
	 * Google: Gemini 3.1 Flash-Lite (default) and Gemini 3.5 Flash-Lite.
	 * Anthropic: Claude 5.5 Haiku (provider pick) and Claude 4.5 Haiku.
	 * OpenAI: GPT-6 Luna, which replaces GPT-4.1 mini.
	 *
	 * Values measured on 2026-11-22 (20 images, production prompts and call
	 * parameters). Pricing: blended_per_million is the list price per million
	 * tokens weighted 0.75 input / 0.25 output (used by the usage cost
	 * estimate); input_/output_per_million are list prices in USD;
	 * measured_per_image is the measured USD cost of one optimization
	 * (keywords + three single-field calls). The last three are display-only.
	 *
	 * @since 1.0.0
	 * @since 1.0.9 Added Claude 5.5 Haiku and GPT-6 Luna; GPT-4.1 mini became
	 *              an alias of GPT-6 Luna; Gemini 3.1 Flash-Lite calls the GA id.
	 *
	 * @return void
	 */
	private function register_builtin_models(): void {

		// Gemini 3.1 Flash-Lite — default model. model_id stays the id sites
		// saved; the API id is the GA name (the preview name is shut down).
		// The legacy 'gemini-3-flash-preview' id resolves here.
		$this->register( [
			'model_id'      => 'gemini-3.1-flash-lite-preview',
			'display_name'  => 'Gemini 3.1 Flash-Lite',
			'description'   => 'Most cost-effective frontier model',
			'provider'      => 'google',
			'group'         => 'Google',
			'prompt_format' => 'json_plain',
			'call_params'   => [
				'full'         => [ 'max_completion_tokens' => 2048, 'temperature' => null, 'response_format' => null, 'extra' => [] ],
				'keywords'     => [ 'max_completion_tokens' => 512, 'temperature' => null, 'response_format' => null, 'extra' => [] ],
				'single_field' => [ 'max_completion_tokens' => 512, 'temperature' => null, 'response_format' => null, 'extra' => [] ],
			],
			// $0.25 in / $1.50 out per MTok -> 0.75 x 0.25 + 0.25 x 1.50 = 0.5625.
			'pricing'       => [ 'blended_per_million' => 0.56, 'input_per_million' => 0.25, 'output_per_million' => 1.50, 'measured_per_image' => 0.00146 ],
			'api_model_id'  => 'gemini-3.1-flash-lite',
			'aliases'       => [ 'gemini-3-flash-preview' ],
		] );

		// Gemini 3.5 Flash-Lite — newer-generation budget option.
		$this->register( [
			'model_id'      => 'gemini-3.5-flash-lite',
			'display_name'  => 'Gemini 3.5 Flash-Lite',
			'description'   => 'Newer generation, cost-effective',
			'provider'      => 'google',
			'group'         => 'Google',
			'prompt_format' => 'json_plain',
			'call_params'   => [
				'full'         => [ 'max_completion_tokens' => 2048, 'temperature' => null, 'response_format' => null, 'extra' => [] ],
				'keywords'     => [ 'max_completion_tokens' => 512, 'temperature' => null, 'response_format' => null, 'extra' => [] ],
				'single_field' => [ 'max_completion_tokens' => 512, 'temperature' => null, 'response_format' => null, 'extra' => [] ],
			],
			// $0.30 in / $2.50 out per MTok -> 0.75 x 0.30 + 0.25 x 2.50 = 0.85.
			'pricing'       => [ 'blended_per_million' => 0.85, 'input_per_million' => 0.30, 'output_per_million' => 2.50, 'measured_per_image' => 0.00180 ],
			'api_model_id'  => 'gemini-3.5-flash-lite',
		] );

		// Claude 5.5 Haiku — the Anthropic pick. Forced tool choice (provider);
		// temperature must not be sent; no thinking parameter (a forced tool
		// call does not think).
		$this->register( [
			'model_id'         => 'claude-haiku-5-5',
			'display_name'     => 'Claude 5.5 Haiku',
			'description'      => 'Newer generation, low cost',
			'provider'         => 'anthropic',
			'group'            => 'Anthropic',
			'prompt_format'    => 'tool_use',
			'call_params'      => [
				'full'         => [ 'max_completion_tokens' => 1024, 'temperature' => null, 'response_format' => null, 'extra' => [] ],
				'keywords'     => [ 'max_completion_tokens' => 500, 'temperature' => null, 'response_format' => null, 'extra' => [] ],
				'single_field' => [ 'max_completion_tokens' => 500, 'temperature' => null, 'response_format' => null, 'extra' => [] ],
			],
			// $0.10 in / $0.50 out per MTok -> 0.75 x 0.10 + 0.25 x 0.50 = 0.20.
			'pricing'          => [ 'blended_per_million' => 0.20, 'input_per_million' => 0.10, 'output_per_million' => 0.50, 'measured_per_image' => 0.00099 ],
			'api_model_id'     => 'claude-haiku-5-5',
			'provider_default' => true,
		] );

		// Claude 4.5 Haiku — stays selectable until the provider announces its
		// retirement.
		$this->register( [
			'model_id'      => 'claude-haiku-4-5',
			'display_name'  => 'Claude 4.5 Haiku',
			'description'   => 'Fast, cost-effective analysis',
			'provider'      => 'anthropic',
			'group'         => 'Anthropic',
			'prompt_format' => 'tool_use',
			'call_params'   => [
				'full'         => [ 'max_completion_tokens' => 1024, 'temperature' => null, 'response_format' => null, 'extra' => [] ],
				'keywords'     => [ 'max_completion_tokens' => 500, 'temperature' => null, 'response_format' => null, 'extra' => [] ],
				'single_field' => [ 'max_completion_tokens' => 500, 'temperature' => null, 'response_format' => null, 'extra' => [] ],
			],
			// $1.00 in / $5.00 out per MTok -> 0.75 x 1.00 + 0.25 x 5.00 = 2.00.
			'pricing'       => [ 'blended_per_million' => 2.00, 'input_per_million' => 1.00, 'output_per_million' => 5.00, 'measured_per_image' => 0.01021 ],
			'api_model_id'  => 'claude-haiku-4-5-20251001',
			'aliases'       => [ 'claude-haiku-4-5-20251001' ],
		] );

		// GPT-6 Luna — replaces GPT-4.1 mini (shutdown 2027-01-14); sites that
		// saved GPT-4.1 mini are moved here once and see a notice. The API
		// accepts only the default temperature, so none is sent; reasoning
		// effort must be 'none', otherwise reasoning tokens exhaust the caps.
		$this->register( [
			'model_id'         => 'gpt-6-luna',
			'display_name'     => 'GPT-6 Luna',
			'description'      => 'Cost-effective image analysis',
			'provider'         => 'openai',
			'group'            => 'OpenAI',
			'prompt_format'    => 'json',
			'call_params'      => [
				'full'         => [ 'max_completion_tokens' => 400, 'temperature' => null, 'response_format' => [ 'type' => 'json_object' ], 'extra' => [ 'reasoning_effort' => 'none' ] ],
				'keywords'     => [ 'max_completion_tokens' => 150, 'temperature' => null, 'response_format' => [ 'type' => 'json_object' ], 'extra' => [ 'reasoning_effort' => 'none' ] ],
				'single_field' => [ 'max_completion_tokens' => 150, 'temperature' => null, 'response_format' => [ 'type' => 'json_object' ], 'extra' => [ 'reasoning_effort' => 'none' ] ],
			],
			// $0.10 in / $0.50 out per MTok -> 0.75 x 0.10 + 0.25 x 0.50 = 0.20.
			'pricing'          => [ 'blended_per_million' => 0.20, 'input_per_million' => 0.10, 'output_per_million' => 0.50, 'measured_per_image' => 0.00063 ],
			'api_model_id'     => 'gpt-6-luna',
			'replaces'         => [ 'gpt-4.1-mini', 'gpt-4.1-mini-2025-04-14' ],
			'provider_default' => true,
		] );
	}

	/**
	 * Register a model configuration
	 *
	 * Validates the config array for required fields and structure, then stores
	 * it keyed by model_id. If the config includes aliases, those are also
	 * indexed to point to the same config by reference.
	 *
	 * Required fields: model_id, display_name, provider, group, call_params, pricing.
	 * Optional:
	 * - description:      shown in parentheses in the dropdown.
	 * - api_model_id:     id sent to the provider. Set it explicitly; when the
	 *                     provider renames its API id, only this field changes.
	 * - aliases:          other ids that resolve to this entry (legacy spellings,
	 *                     retired models). Saved aliases are rewritten to model_id.
	 * - replaces:         retired model ids this entry succeeds. They are indexed
	 *                     as aliases too; a site moved from one of them sees a
	 *                     one-time admin notice naming both models.
	 * - provider_default: true marks the model the setup wizard picks for its
	 *                     provider (the global default model wins for its own
	 *                     provider).
	 * - pricing:          'blended_per_million' (required, cost estimate);
	 *                     optional display values 'input_per_million',
	 *                     'output_per_million', 'measured_per_image' (USD).
	 * call_params must contain 'full', 'keywords', and 'single_field' keys, each
	 * with a 'max_completion_tokens' value.
	 *
	 * Retiring a model: only 'replaces' may take over an id that is already
	 * registered (a primary entry or another entry's alias); a retired
	 * primary entry's aliases move to the successor too. The plugin's default
	 * model (INFYP_DEFAULT_MODEL) can never be retired. An 'aliases' entry
	 * that collides with a registered id is ignored with _doing_it_wrong().
	 *
	 * Registration order matters: built-in models register first, then the
	 * `infyp_register_model_configs` callbacks in hook order. 'replaces' only
	 * retires entries registered before it; an entry registered later under
	 * a replaced id becomes a primary entry again, and a later 'replaces' of
	 * the same id wins over an earlier one.
	 *
	 * @since 1.0.0
	 * @since 1.0.9 Added the 'replaces' and 'provider_default' keys and the
	 *              optional display pricing keys.
	 *
	 * @param array $config The model configuration array. See class docblock for structure.
	 *
	 * @return bool True on successful registration, false on validation failure.
	 */
	public function register( array $config ): bool {

		// Validate required top-level fields.
		$required_fields = [ 'model_id', 'display_name', 'provider', 'group', 'call_params', 'pricing' ];

		foreach ( $required_fields as $field ) {
			if ( ! isset( $config[ $field ] ) || ( is_string( $config[ $field ] ) && '' === $config[ $field ] ) ) {
				return false;
			}
		}

		// Validate call_params structure.
		$required_call_types = [ 'full', 'keywords', 'single_field' ];

		if ( ! is_array( $config['call_params'] ) ) {
			return false;
		}

		foreach ( $required_call_types as $call_type ) {
			if ( ! isset( $config['call_params'][ $call_type ] ) || ! is_array( $config['call_params'][ $call_type ] ) ) {
				return false;
			}

			if ( ! isset( $config['call_params'][ $call_type ]['max_completion_tokens'] ) ) {
				return false;
			}
		}

		// Store the config keyed by model_id. If the id was an alias so far,
		// its slot is a reference: unset it first, otherwise the assignment
		// would overwrite the entry the alias pointed to. Re-registering a
		// primary id writes through, so its aliases follow the new config.
		$model_id = (string) $config['model_id'];
		if ( isset( $this->configs[ $model_id ] ) && $this->configs[ $model_id ]['model_id'] !== $model_id ) {
			unset( $this->configs[ $model_id ] );
		}
		$this->configs[ $model_id ] = $config;

		// Index aliases and replaced ids to point to the same config by
		// reference. Only 'replaces' may take over a registered id (see the
		// docblock); a retired primary entry's aliases move along.
		$replaces = $this->get_alias_ids( $config, [ 'replaces' ] );

		foreach ( $this->get_alias_ids( $config ) as $alias ) {
			if ( isset( $this->configs[ $alias ] ) && $this->configs[ $alias ]['model_id'] !== $model_id ) {
				if ( ! in_array( $alias, $replaces, true ) ) {
					$this->doing_it_wrong( sprintf( 'Model "%1$s": alias "%2$s" is already registered and was ignored. Use "replaces" to retire a model.', $model_id, $alias ) );
					continue;
				}
				if ( defined( 'INFYP_DEFAULT_MODEL' ) && INFYP_DEFAULT_MODEL === $alias ) {
					$this->doing_it_wrong( sprintf( 'Model "%1$s": the default model "%2$s" cannot be replaced; ignored.', $model_id, $alias ) );
					continue;
				}
				if ( $this->configs[ $alias ]['model_id'] === $alias ) {
					foreach ( array_keys( $this->configs ) as $key ) {
						if ( $key !== $alias && $this->configs[ $key ]['model_id'] === $alias ) {
							unset( $this->configs[ $key ] );
							$this->configs[ $key ] = &$this->configs[ $model_id ];
						}
					}
				}
			}
			unset( $this->configs[ $alias ] );
			$this->configs[ $alias ] = &$this->configs[ $model_id ];
		}

		return true;
	}

	/**
	 * Ids that resolve to a config besides its model_id: aliases and replaced ids.
	 *
	 * @since 1.0.9
	 *
	 * @param array    $config Model config.
	 * @param string[] $keys   Config keys to read.
	 * @return string[] Alias ids, without the model_id itself.
	 */
	private function get_alias_ids( array $config, array $keys = [ 'aliases', 'replaces' ] ): array {
		$ids = [];

		foreach ( $keys as $key ) {
			if ( empty( $config[ $key ] ) || ! is_array( $config[ $key ] ) ) {
				continue;
			}
			foreach ( $config[ $key ] as $id ) {
				if ( is_string( $id ) && '' !== $id && $id !== $config['model_id'] ) {
					$ids[ $id ] = $id;
				}
			}
		}

		return array_values( $ids );
	}

	/**
	 * Report a registration mistake to developers (shown with WP_DEBUG only).
	 *
	 * @since 1.0.9
	 *
	 * @param string $message Message, not translated (developer notice).
	 * @return void
	 */
	private function doing_it_wrong( string $message ): void {
		if ( function_exists( '_doing_it_wrong' ) ) {
			_doing_it_wrong( __CLASS__ . '::register', esc_html( $message ), '1.0.9' );
		}
	}

	/**
	 * Get the configuration for a model
	 *
	 * Returns the full config array for the given model_id (or alias).
	 * Triggers lazy initialization on first call.
	 *
	 * @since 1.0.0
	 *
	 * @param string $model_id The model identifier or alias.
	 *
	 * @return array|null The model config array, or null if not registered.
	 */
	public function get_config( string $model_id ): ?array {
		$this->init();

		return $this->configs[ $model_id ] ?? null;
	}

	/**
	 * Get call parameters for a specific model and call type
	 *
	 * Convenience method that returns just the call_params entry for
	 * the given call type (full, keywords, or single_field).
	 *
	 * @since 1.0.0
	 *
	 * @param string $model_id  The model identifier or alias.
	 * @param string $call_type The call type: 'full', 'keywords', or 'single_field'.
	 *
	 * @return array|null The call parameters array, or null if model or call type not found.
	 */
	public function get_call_params( string $model_id, string $call_type ): ?array {
		$config = $this->get_config( $model_id );

		if ( null === $config ) {
			return null;
		}

		return $config['call_params'][ $call_type ] ?? null;
	}

	/**
	 * Check whether a model has a registered configuration
	 *
	 * @since 1.0.0
	 *
	 * @param string $model_id The model identifier or alias.
	 *
	 * @return bool True if the model has a config, false otherwise.
	 */
	public function has_config( string $model_id ): bool {
		$this->init();

		return isset( $this->configs[ $model_id ] );
	}

	/**
	 * Get all registered model configurations
	 *
	 * Returns all configs, optionally filtered by provider slug. Aliases are
	 * excluded to avoid duplicate entries; only primary model_id entries are
	 * returned.
	 *
	 * @since 1.0.0
	 *
	 * @param string|null $provider Optional provider slug to filter by (e.g., 'openai', 'google', 'anthropic').
	 *
	 * @return array Associative array of model_id => config arrays.
	 */
	public function get_all_configs( ?string $provider = null ): array {
		$this->init();

		$result = [];

		foreach ( $this->configs as $key => $config ) {
			// Skip alias entries — only include primary model_id.
			if ( $key !== $config['model_id'] ) {
				continue;
			}

			if ( null !== $provider && $config['provider'] !== $provider ) {
				continue;
			}

			$result[ $key ] = $config;
		}

		return $result;
	}

	/**
	 * Get models grouped for dropdown rendering
	 *
	 * Returns an array structured for HTML `<optgroup>` rendering, grouped
	 * by the config's 'group' field (e.g., 'OpenAI', 'Google', 'Anthropic').
	 *
	 * @since 1.0.0
	 *
	 * @return array Grouped array: ['Google' => ['gemini-3.1-flash-lite-preview' => 'Gemini 3.1 Flash-Lite (...)'], 'OpenAI' => [...], ...]
	 */
	public function get_dropdown_models(): array {
		$all_configs = $this->get_all_configs();
		$grouped     = [];

		foreach ( $all_configs as $model_id => $config ) {
			$group = $config['group'];

			if ( ! isset( $grouped[ $group ] ) ) {
				$grouped[ $group ] = [];
			}

			$label = $config['display_name'];
			if ( ! empty( $config['description'] ) ) {
				$label .= ' (' . $config['description'] . ')';
			}
			$grouped[ $group ][ $model_id ] = $label;
		}

		// Within each provider group, list older models first and the newest
		// last, regardless of registration order (extensions register after
		// the built-ins). Version numbers are read from the model id; ties
		// keep their registration order (stable sort).
		foreach ( $grouped as $group => $models ) {
			$position = 0;
			$sortable = [];

			foreach ( $models as $model_id => $label ) {
				$sortable[ $model_id ] = [
					'version' => $this->extract_model_version( $model_id ),
					'index'   => $position++,
					'label'   => $label,
				];
			}

			uasort(
				$sortable,
				static function ( $a, $b ) {
					$cmp = version_compare( $a['version'], $b['version'] );
					return 0 !== $cmp ? $cmp : $a['index'] <=> $b['index'];
				}
			);

			$grouped[ $group ] = array_map(
				static function ( $entry ) {
					return $entry['label'];
				},
				$sortable
			);
		}

		return $grouped;
	}

	/**
	 * Extract a comparable version string from a model id.
	 *
	 * Reads the first dotted/dashed number sequence after the family name,
	 * e.g. 'gemini-3.5-flash-lite' → '3.5', 'claude-haiku-4-5' → '4.5',
	 * 'gpt-6-luna' → '6'. Ids without a number sort as '0' (oldest).
	 *
	 * @since 1.0.6
	 *
	 * @param string $model_id The registry model id.
	 * @return string Version string usable with version_compare().
	 */
	private function extract_model_version( string $model_id ): string {
		if ( preg_match( '/(\d+(?:[.-]\d+)*)/', $model_id, $m ) ) {
			return str_replace( '-', '.', $m[1] );
		}
		return '0';
	}

	/**
	 * Get all valid model IDs
	 *
	 * Returns an array of all model_id values that have registered configs.
	 * Aliases are excluded; only primary model IDs are returned.
	 *
	 * @since 1.0.0
	 *
	 * @return array Indexed array of model_id strings.
	 */
	public function get_valid_model_ids(): array {
		$all_configs = $this->get_all_configs();

		return array_keys( $all_configs );
	}

	/**
	 * Get every model id the plugin accepts as input
	 *
	 * Primary model ids followed by every alias and replaced id. Write paths
	 * (settings form, AJAX, REST) accept all of them and store the canonical
	 * id from get_canonical_id(). Selectable models are still the primary ids
	 * from get_valid_model_ids().
	 *
	 * @since 1.0.9
	 *
	 * @return string[] Indexed array of model ids.
	 */
	public function get_accepted_model_ids(): array {
		$this->init();

		return array_values(
			array_unique(
				array_merge( $this->get_valid_model_ids(), array_map( 'strval', array_keys( $this->configs ) ) )
			)
		);
	}

	/**
	 * Resolve a model id or alias to the canonical (primary) model id
	 *
	 * A retired or legacy id that a registered entry lists in 'aliases' or
	 * 'replaces' resolves to that entry's model_id. A primary id is returned
	 * unchanged, and so is an id the registry does not know: callers that
	 * need validation check has_config() first.
	 *
	 * Static so callers that only hold the class name can use it; calling it
	 * on the instance works as well.
	 *
	 * @since 1.0.9
	 *
	 * @param string $id Model id as saved or requested.
	 * @return string Canonical model id, or $id when it is not registered.
	 */
	public static function get_canonical_id( string $id ): string {
		$config = self::get_instance()->get_config( $id );

		if ( null === $config || empty( $config['model_id'] ) || ! is_string( $config['model_id'] ) ) {
			return $id;
		}

		return $config['model_id'];
	}

	/**
	 * Whether an id is a retired model that a registered entry replaces
	 *
	 * True only for ids listed in the 'replaces' key of the entry they resolve
	 * to. Legacy spellings of the same model (plain 'aliases') return false,
	 * so moving a site off them needs no notice.
	 *
	 * @since 1.0.9
	 *
	 * @param string $id Model id.
	 * @return bool True when $id is a replaced model id.
	 */
	public function is_replaced_id( string $id ): bool {
		$config = $this->get_config( $id );

		return null !== $config
			&& ! empty( $config['replaces'] )
			&& is_array( $config['replaces'] )
			&& in_array( $id, $config['replaces'], true );
	}

	/**
	 * Get the model a provider starts with
	 *
	 * For the provider of the plugin's default model, that default model.
	 * Otherwise the first registered entry of the provider marked
	 * 'provider_default', else the first registered entry of the provider.
	 * Used by the setup wizard, so it never names a model id itself.
	 *
	 * @since 1.0.9
	 *
	 * @param string $provider Provider slug ('google', 'openai', 'anthropic', ...).
	 * @return string Model id, or an empty string when the provider has no model.
	 */
	public function get_default_model_for_provider( string $provider ): string {
		if ( defined( 'INFYP_DEFAULT_MODEL' ) ) {
			$default = $this->get_config( (string) INFYP_DEFAULT_MODEL );
			if ( null !== $default && $default['provider'] === $provider ) {
				return (string) $default['model_id'];
			}
		}

		$configs = $this->get_all_configs( $provider );

		foreach ( $configs as $model_id => $config ) {
			if ( ! empty( $config['provider_default'] ) ) {
				return (string) $model_id;
			}
		}

		$first = array_key_first( $configs );

		return null === $first ? '' : (string) $first;
	}

	/**
	 * Get model labels map (short names only, no description).
	 *
	 * Returns model_id => display_name for all registered models.
	 * Used for the optimization spinner and other compact UI elements.
	 *
	 * @since 1.0.0
	 *
	 * @return array Associative array of model_id => display_name.
	 */
	public function get_model_labels(): array {
		$all_configs = $this->get_all_configs();
		$labels      = [];

		foreach ( $all_configs as $model_id => $config ) {
			$labels[ $model_id ] = $config['display_name'];
		}

		return $labels;
	}

	/**
	 * Get the blended pricing per million tokens for a model
	 *
	 * Returns the blended_per_million value from the model's pricing config.
	 * Falls back to 1.0 if the model is not registered or pricing is missing.
	 *
	 * @since 1.0.0
	 *
	 * @param string $model_id The model identifier or alias.
	 *
	 * @return float The blended cost per million tokens, or 1.0 as fallback.
	 */
	public function get_pricing( string $model_id ): float {
		$config = $this->get_config( $model_id );

		if ( null === $config || ! isset( $config['pricing']['blended_per_million'] ) ) {
			return 1.0;
		}

		return (float) $config['pricing']['blended_per_million'];
	}

	/**
	 * Resolve a model ID to its API model identifier
	 *
	 * Some models use a different identifier for API calls than their
	 * registry model_id. For example, 'claude-haiku-4-5' resolves to
	 * 'claude-haiku-4-5-20251001' for the Anthropic API.
	 *
	 * If the config has an 'api_model_id' field set, that value is returned.
	 * Otherwise the entry's model_id is returned; an unregistered id is
	 * returned unchanged. An alias resolves to its entry, so a retired id
	 * saved on a site calls the successor even before the saved setting is
	 * migrated.
	 *
	 * @since 1.0.0
	 *
	 * @param string $model_id The model identifier or alias.
	 *
	 * @return string The API model identifier to use in API calls.
	 */
	public function resolve_api_model_id( string $model_id ): string {
		$config = $this->get_config( $model_id );

		if ( null === $config ) {
			return $model_id;
		}

		// An alias without api_model_id on its entry calls the entry's own
		// id, never the retired id it was given.
		return ! empty( $config['api_model_id'] ) ? (string) $config['api_model_id'] : (string) $config['model_id'];
	}
}
