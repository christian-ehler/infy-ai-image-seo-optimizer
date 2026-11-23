<?php
/**
 * REST Settings Controller - /settings route on the infyp/v1 namespace
 *
 * Read view of the optimization configuration relevant to API consumers
 * (active model, output language, allowed field types, request limits),
 * plus PATCH support for the generation settings a client may switch
 * between requests (model, language, prompt addon, per-image prompt,
 * timeout). Connection method and derived values stay read-only. Never
 * exposes API keys or license information.
 *
 * @package   IPAIS\Rest
 * @since 1.0.6
 * @author    InfyPress
 * @license   GPL-2.0-or-later
 */

namespace IPAIS\Rest;

// Exit if accessed directly (placed after namespace per PHP requirement).
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * REST controller for the plugin settings resource.
 *
 * @package IPAIS\Rest
 * @since 1.0.6
 */
class INFYP_REST_Settings_Controller extends INFYP_REST_Controller {

    /**
     * Constructor.
     *
     * @since 1.0.6
     */
    public function __construct() {
        parent::__construct();
        $this->rest_base = 'settings';
    }

    /**
     * Register the /settings route.
     *
     * @since 1.0.6
     *
     * @return void
     */
    public function register_routes() {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_item' ],
                    'permission_callback' => [ $this, 'get_item_permissions_check' ],
                ],
                [
                    'methods'             => 'PATCH',
                    'callback'            => [ $this, 'update_item' ],
                    'permission_callback' => [ $this, 'update_item_permissions_check' ],
                    'args'                => $this->get_update_args(),
                ],
                'schema' => [ $this, 'get_public_item_schema' ],
            ]
        );
    }

    /**
     * Permission check: settings management capability.
     *
     * @since 1.0.6
     *
     * @param \WP_REST_Request $request The request.
     * @return true|\WP_Error
     */
    public function get_item_permissions_check( $request ) {
        if ( ! infyp_user_can( 'manage_settings' ) ) {
            return $this->permission_error(
                esc_html__( 'You do not have permission to view these settings.', 'infy-ai-image-seo-optimizer' )
            );
        }
        return true;
    }

    /**
     * Permission check for writes: settings management capability.
     *
     * @since 1.0.6
     *
     * @param \WP_REST_Request $request The request.
     * @return true|\WP_Error
     */
    public function update_item_permissions_check( $request ) {
        if ( ! infyp_user_can( 'manage_settings' ) ) {
            return $this->permission_error(
                esc_html__( 'You do not have permission to change these settings.', 'infy-ai-image-seo-optimizer' )
            );
        }
        return true;
    }

    /**
     * Body arguments for PATCH /settings.
     *
     * Only the generation settings an API client may want to switch between
     * requests are writable. Connection method and derived values stay
     * read-only; API keys are never writable over REST.
     *
     * @since 1.0.6
     *
     * @return array Route argument definitions.
     */
    protected function get_update_args() {
        /** This filter is documented in includes/Ajax/INFYP_Settings_Ajax.php */
        $max_prompt_length = (int) apply_filters( 'infyp_max_prompt_length', 1000 );

        return [
            'ai_model'         => [
                'description'       => esc_html__( 'AI model identifier to activate. Must be one of the models the plugin supports.', 'infy-ai-image-seo-optimizer' ),
                'type'              => 'string',
                'required'          => false,
                'enum'              => \IPAIS\Core\AI\INFYP_Model_Config_Registry::get_instance()->get_valid_model_ids(),
                // The enum lists the selectable models. Renamed or retired ids
                // are accepted too and stored as the canonical id.
                'validate_callback' => [ $this, 'validate_ai_model' ],
                'sanitize_callback' => [ $this, 'sanitize_ai_model' ],
            ],
            'language'         => [
                'description'       => esc_html__( 'Output language for generated text. Must be a valid WordPress locale.', 'infy-ai-image-seo-optimizer' ),
                'type'              => 'string',
                'required'          => false,
                'validate_callback' => [ $this, 'validate_language' ],
            ],
            'prompt_addon'     => [
                'description'       => esc_html__( 'Global prompt addon text appended to every generation prompt. Empty string clears it.', 'infy-ai-image-seo-optimizer' ),
                'type'              => 'string',
                'required'          => false,
                'maxLength'         => $max_prompt_length,
                'sanitize_callback' => 'sanitize_textarea_field',
                // A custom sanitize_callback disables core's implicit schema
                // validation for this arg, so maxLength must be re-enabled
                // explicitly (see WP_REST_Request::sanitize_params()).
                'validate_callback' => 'rest_validate_request_arg',
            ],
            'per_image_prompt' => [
                'description' => esc_html__( 'Whether the admin UI prompts for per-image instructions.', 'infy-ai-image-seo-optimizer' ),
                'type'        => 'boolean',
                'required'    => false,
            ],
            'request_timeout'  => [
                'description' => esc_html__( 'AI provider request timeout in seconds.', 'infy-ai-image-seo-optimizer' ),
                'type'        => 'integer',
                'required'    => false,
                'minimum'     => 60,
                'maximum'     => 120,
            ],
        ];
    }

    /**
     * Validate the ai_model parameter: a registered model id or one of its aliases.
     *
     * Unknown values get the standard REST enum error, which lists the
     * selectable model ids.
     *
     * @since 1.0.9
     *
     * @param mixed            $value   The requested model id.
     * @param \WP_REST_Request $request The request.
     * @param string           $param   Parameter name.
     * @return true|\WP_Error
     */
    public function validate_ai_model( $value, $request = null, $param = 'ai_model' ) {
        $registry = \IPAIS\Core\AI\INFYP_Model_Config_Registry::get_instance();

        if ( is_string( $value ) && in_array( $value, $registry->get_accepted_model_ids(), true ) ) {
            return true;
        }

        return rest_validate_value_from_schema(
            $value,
            [
                'type' => 'string',
                'enum' => $registry->get_valid_model_ids(),
            ],
            (string) $param
        );
    }

    /**
     * Sanitize the ai_model parameter: aliases become the canonical model id.
     *
     * Core validates request arguments before it sanitizes them, so
     * validate_ai_model() has already accepted the value; values that are
     * not strings are passed through unchanged.
     *
     * @since 1.0.9
     *
     * @param mixed $value The requested model id.
     * @return mixed Canonical model id, or the value unchanged.
     */
    public function sanitize_ai_model( $value ) {
        if ( ! is_string( $value ) ) {
            return $value;
        }

        return \IPAIS\Core\AI\INFYP_Model_Config_Registry::get_canonical_id( sanitize_text_field( $value ) );
    }

    /**
     * Validate the language parameter against WordPress locales.
     *
     * @since 1.0.6
     *
     * @param string $value The requested language.
     * @return true|\WP_Error
     */
    public function validate_language( $value ) {
        if ( ! \IPAIS\Core\INFYP_Language_Manager::is_valid_language( sanitize_text_field( $value ) ) ) {
            return new \WP_Error(
                'rest_invalid_param',
                esc_html__( 'language is not a valid WordPress locale.', 'infy-ai-image-seo-optimizer' ),
                [ 'status' => 400 ]
            );
        }
        return true;
    }

    /**
     * PATCH /settings — change generation settings.
     *
     * Applies only the recognised writable fields present in the request,
     * mirroring the sanitisation rules of the admin AJAX save handlers, and
     * returns the full refreshed settings payload.
     *
     * @since 1.0.6
     *
     * @param \WP_REST_Request $request The request.
     * @return \WP_REST_Response|\WP_Error
     */
    public function update_item( $request ) {
        $writable = array_keys( $this->get_update_args() );
        $provided = array_intersect( $writable, array_keys( $request->get_params() ) );

        if ( empty( $provided ) ) {
            return new \WP_Error(
                'infyp_no_settings',
                esc_html__( 'Provide at least one writable setting to change.', 'infy-ai-image-seo-optimizer' ),
                [ 'status' => 400 ]
            );
        }

        $optimization = get_option( 'infyp_optimization_settings', [] );
        $prompts      = get_option( 'infyp_prompt_settings', [] );

        if ( in_array( 'ai_model', $provided, true ) ) {
            $optimization['ai_model'] = \IPAIS\Core\AI\INFYP_Model_Config_Registry::get_canonical_id( sanitize_text_field( (string) $request['ai_model'] ) );
        }
        if ( in_array( 'request_timeout', $provided, true ) ) {
            $optimization['request_timeout'] = absint( $request['request_timeout'] );
        }
        if ( in_array( 'language', $provided, true ) ) {
            $prompts['language'] = sanitize_text_field( $request['language'] );
        }
        if ( in_array( 'prompt_addon', $provided, true ) ) {
            $prompts['prompt_addon'] = (string) $request['prompt_addon'];
        }
        if ( in_array( 'per_image_prompt', $provided, true ) ) {
            $prompts['enable_per_image_prompt'] = $request['per_image_prompt'] ? 1 : 0;
        }

        update_option( 'infyp_optimization_settings', $optimization );
        update_option( 'infyp_prompt_settings', $prompts );

        \IPAIS\Core\INFYP_Cache_Manager::get_instance()->invalidate_settings_cache();

        /** This action is documented in includes/Ajax/INFYP_Settings_Ajax.php */
        do_action( 'infyp_settings_updated' );

        return $this->get_item( $request );
    }

    /**
     * GET /settings — current optimization configuration.
     *
     * @since 1.0.6
     *
     * @param \WP_REST_Request $request The request.
     * @return \WP_REST_Response
     */
    public function get_item( $request ) {
        $optimization = get_option( 'infyp_optimization_settings', [] );
        $prompts      = get_option( 'infyp_prompt_settings', [] );

        /** This filter is documented in includes/Ajax/INFYP_AI_Ajax.php */
        $max_keywords = (int) apply_filters( 'infyp_max_keywords', 3 );

        /** This filter is documented in includes/Ajax/INFYP_Settings_Ajax.php */
        $max_prompt_length = (int) apply_filters( 'infyp_max_prompt_length', 1000 );

        $settings = [
            'ai_model'                => \IPAIS\Core\AI\INFYP_Model_Config_Registry::get_canonical_id( (string) ( $optimization['ai_model'] ?? ( defined( 'INFYP_DEFAULT_MODEL' ) ? INFYP_DEFAULT_MODEL : '' ) ) ),
            'connection_method'       => \IPAIS\Core\AI\INFYP_WP_AI_Support::get_connection_method(),
            'wp_ai_ready'             => \IPAIS\Core\AI\INFYP_WP_AI_Support::is_ready(),
            'language'                => $prompts['language'] ?? \IPAIS\Core\INFYP_Language_Manager::get_default_language(),
            'prompt_addon'            => (string) ( $prompts['prompt_addon'] ?? '' ),
            'per_image_prompt'        => ! empty( $prompts['enable_per_image_prompt'] ),
            'allowed_field_types'     => $this->get_allowed_field_types(),
            'max_keywords'            => $max_keywords,
            'max_custom_prompt_chars' => $max_prompt_length,
            'request_timeout'         => (int) ( $optimization['request_timeout'] ?? ( $optimization['timeout'] ?? 60 ) ),
            // Cast so an empty map serialises as {} rather than [], matching the schema.
            'extensions'              => (object) $this->get_extensions(),
        ];

        /**
         * Filter the REST settings response.
         *
         * Extensions can advertise their own settings here without the
         * free plugin knowing about them.
         *
         * @since 1.0.6
         *
         * @param array $settings The settings payload.
         */
        $settings = apply_filters( 'infyp_rest_settings_response', $settings );

        return rest_ensure_response( $settings );
    }

    /**
     * Collect the capabilities advertised by registered extensions.
     *
     * Lets clients discover, in one request, which optional capabilities this
     * install offers and which `options` keys the optimize routes will honour,
     * without hardcoding knowledge of any particular extension.
     *
     * Each entry should be keyed by a unique slug chosen by the extension.
     * Entry shape is up to the extension; a `label` and an `options` list of
     * recognised keys are the useful conventions.
     *
     * @since 1.0.6
     *
     * @return array Advertised capabilities keyed by extension slug.
     */
    protected function get_extensions() {
        /**
         * Filter the capabilities advertised on the settings resource.
         *
         * @since 1.0.6
         *
         * @param array $extensions Capabilities keyed by extension slug.
         */
        $extensions = apply_filters( 'infyp_rest_extensions', [] );

        return is_array( $extensions ) ? $extensions : [];
    }

    /**
     * The settings resource schema.
     *
     * @since 1.0.6
     *
     * @return array
     */
    public function get_item_schema() {
        if ( $this->schema ) {
            return $this->add_additional_fields_schema( $this->schema );
        }

        $this->schema = [
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'infyp_settings',
            'type'       => 'object',
            'properties' => [
                'ai_model'                => [
                    'description' => esc_html__( 'Active AI model identifier. Writable via PATCH.', 'infy-ai-image-seo-optimizer' ),
                    'type'        => 'string',
                ],
                'connection_method'       => [
                    'description' => esc_html__( 'How the plugin talks to AI services: plugin API keys or WordPress AI Connectors (WordPress 7.0+).', 'infy-ai-image-seo-optimizer' ),
                    'type'        => 'string',
                    'enum'        => [ 'api_keys', 'wp_ai' ],
                    'readonly'    => true,
                ],
                'wp_ai_ready'             => [
                    'description' => esc_html__( 'Whether WordPress AI Connectors is selected, available and has a configured connector.', 'infy-ai-image-seo-optimizer' ),
                    'type'        => 'boolean',
                    'readonly'    => true,
                ],
                'language'                => [
                    'description' => esc_html__( 'Output language for generated text. Writable via PATCH.', 'infy-ai-image-seo-optimizer' ),
                    'type'        => 'string',
                ],
                'prompt_addon'            => [
                    'description' => esc_html__( 'Global prompt addon text. Writable via PATCH.', 'infy-ai-image-seo-optimizer' ),
                    'type'        => 'string',
                ],
                'per_image_prompt'        => [
                    'description' => esc_html__( 'Whether the admin UI prompts for per-image instructions. Writable via PATCH.', 'infy-ai-image-seo-optimizer' ),
                    'type'        => 'boolean',
                ],
                'allowed_field_types'     => [
                    'description' => esc_html__( 'Field types accepted by the optimization endpoints.', 'infy-ai-image-seo-optimizer' ),
                    'type'        => 'array',
                    'items'       => [ 'type' => 'string' ],
                    'readonly'    => true,
                ],
                'max_keywords'            => [
                    'description' => esc_html__( 'Maximum number of stored keywords per image.', 'infy-ai-image-seo-optimizer' ),
                    'type'        => 'integer',
                    'readonly'    => true,
                ],
                'max_custom_prompt_chars' => [
                    'description' => esc_html__( 'Maximum length of the custom_prompt parameter.', 'infy-ai-image-seo-optimizer' ),
                    'type'        => 'integer',
                    'readonly'    => true,
                ],
                'request_timeout'         => [
                    'description' => esc_html__( 'AI provider request timeout in seconds. Writable via PATCH.', 'infy-ai-image-seo-optimizer' ),
                    'type'        => 'integer',
                    'minimum'     => 60,
                    'maximum'     => 120,
                ],
                'extensions'              => [
                    'description' => esc_html__( 'Optional capabilities advertised by registered extensions, keyed by extension slug. Empty when none are registered.', 'infy-ai-image-seo-optimizer' ),
                    'type'        => 'object',
                    'readonly'    => true,
                ],
            ],
        ];

        return $this->add_additional_fields_schema( $this->schema );
    }
}
