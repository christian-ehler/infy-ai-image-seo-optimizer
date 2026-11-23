<?php
/**
 * REST Scoring Controller - /settings/scoring routes on the infyp/v1 namespace
 *
 * Read and change the SEO scoring configuration: the component weights that
 * make up an image's score, and the per-field parameters (length bounds and
 * score components) each field is measured against.
 *
 * Routes:
 * - GET    /settings/scoring  Effective configuration
 * - PATCH  /settings/scoring  Deep-merge partial changes
 * - PUT    /settings/scoring  Replace the whole configuration
 * - DELETE /settings/scoring  Restore defaults
 *
 * Writes are validated as a whole after merging, so a partial change can
 * never leave the stored configuration invalid. Because a scoring change
 * makes every previously calculated score obsolete, successful writes discard
 * the cached scores and queue a background recalculation.
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

use \IPAIS\Core\INFYP_SEO_Score_Calculator;

/**
 * REST controller for the SEO scoring configuration.
 *
 * @package IPAIS\Rest
 * @since 1.0.6
 */
class INFYP_REST_Scoring_Controller extends INFYP_REST_Controller {

    /**
     * Constructor.
     *
     * @since 1.0.6
     */
    public function __construct() {
        parent::__construct();
        $this->rest_base = 'settings/scoring';
    }

    /**
     * Register the /settings/scoring route.
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
                    'permission_callback' => [ $this, 'permissions_check' ],
                ],
                [
                    'methods'             => 'PATCH',
                    'callback'            => [ $this, 'patch_item' ],
                    'permission_callback' => [ $this, 'permissions_check' ],
                    'args'                => $this->get_write_args(),
                ],
                [
                    'methods'             => 'PUT',
                    'callback'            => [ $this, 'put_item' ],
                    'permission_callback' => [ $this, 'permissions_check' ],
                    'args'                => $this->get_write_args(),
                ],
                [
                    'methods'             => \WP_REST_Server::DELETABLE,
                    'callback'            => [ $this, 'delete_item' ],
                    'permission_callback' => [ $this, 'permissions_check' ],
                ],
                'schema' => [ $this, 'get_public_item_schema' ],
            ]
        );
    }

    /**
     * Body arguments shared by the write methods.
     *
     * Both members are objects with a closed key set, validated in full after
     * merging rather than per-key here, because the meaningful rules are
     * cross-field (totals and min/max ordering).
     *
     * @since 1.0.6
     *
     * @return array Route argument definitions.
     */
    protected function get_write_args() {
        return [
            'weights'    => [
                'description'          => __( 'Score component weights. Must total 100 once merged.', 'infy-ai-image-seo-optimizer' ),
                'type'                 => 'object',
                'required'             => false,
                'additionalProperties' => false,
                'properties'           => $this->build_weight_properties(),
                'validate_callback'    => 'rest_validate_request_arg',
            ],
            'parameters' => [
                'description'          => __( 'Per-field scoring parameters, keyed by field section.', 'infy-ai-image-seo-optimizer' ),
                'type'                 => 'object',
                'required'             => false,
                'additionalProperties' => false,
                'properties'           => $this->build_parameter_properties(),
                'validate_callback'    => 'rest_validate_request_arg',
            ],
        ];
    }

    /**
     * Build the schema properties for the weights object.
     *
     * @since 1.0.6
     *
     * @return array
     */
    protected function build_weight_properties() {
        $properties = [];

        foreach ( INFYP_SEO_Score_Calculator::get_default_weights() as $key => $default ) {
            $properties[ $key ] = [
                /* translators: %s: Weight component name. */
                'description' => sprintf( __( 'Weight for the %s component.', 'infy-ai-image-seo-optimizer' ), $key ),
                'type'        => 'integer',
                'minimum'     => 0,
                'maximum'     => 100,
                'default'     => $default,
            ];
        }

        return $properties;
    }

    /**
     * Build the schema properties for the parameters object.
     *
     * @since 1.0.6
     *
     * @return array
     */
    protected function build_parameter_properties() {
        $properties = [];

        foreach ( INFYP_SEO_Score_Calculator::get_default_scoring_params() as $section => $defaults ) {
            $section_properties = [];

            foreach ( $defaults as $key => $default ) {
                if ( 'auto_link' === $key ) {
                    $section_properties[ $key ] = [
                        'description' => __( 'Whether the field participates in link-related scoring.', 'infy-ai-image-seo-optimizer' ),
                        'type'        => 'boolean',
                        'default'     => (bool) $default,
                    ];
                    continue;
                }

                if ( 'tolerance_words' === $key ) {
                    $section_properties[ $key ] = [
                        'description' => __( 'Extra words tolerated on each side of the word range before a length warning (0 = strict).', 'infy-ai-image-seo-optimizer' ),
                        'type'        => 'number',
                        'minimum'     => 0,
                        'maximum'     => 5,
                        'default'     => $default,
                    ];
                    continue;
                }

                if ( 'tolerance_chars_pct' === $key ) {
                    $section_properties[ $key ] = [
                        'description' => __( 'Percent of each character limit tolerated as play room before a length warning (0 = strict).', 'infy-ai-image-seo-optimizer' ),
                        'type'        => 'number',
                        'minimum'     => 0,
                        'maximum'     => 50,
                        'default'     => $default,
                    ];
                    continue;
                }

                $section_properties[ $key ] = [
                    /* translators: %s: Scoring parameter key. */
                    'description' => sprintf( __( 'Value for %s.', 'infy-ai-image-seo-optimizer' ), $key ),
                    'type'        => 'number',
                    'minimum'     => 0,
                    'default'     => $default,
                ];
            }

            $properties[ $section ] = [
                /* translators: %s: Scoring section name. */
                'description'          => sprintf( __( 'Scoring parameters for the %s field.', 'infy-ai-image-seo-optimizer' ), $section ),
                'type'                 => 'object',
                'additionalProperties' => false,
                'properties'           => $section_properties,
            ];
        }

        return $properties;
    }

    /**
     * Permission check: scoring configuration is site-wide.
     *
     * @since 1.0.6
     *
     * @param \WP_REST_Request $request The request.
     * @return true|\WP_Error
     */
    public function permissions_check( $request ) {
        if ( ! infyp_user_can( 'manage_settings' ) ) {
            return $this->permission_error(
                __( 'You do not have permission to manage the scoring configuration.', 'infy-ai-image-seo-optimizer' )
            );
        }

        return true;
    }

    /**
     * GET — the effective scoring configuration.
     *
     * @since 1.0.6
     *
     * @param \WP_REST_Request $request The request.
     * @return \WP_REST_Response
     */
    public function get_item( $request ) {
        return rest_ensure_response( $this->prepare_payload() );
    }

    /**
     * PATCH — merge partial changes into the current configuration.
     *
     * @since 1.0.6
     *
     * @param \WP_REST_Request $request The request.
     * @return \WP_REST_Response|\WP_Error
     */
    public function patch_item( $request ) {
        return $this->write( $request, true );
    }

    /**
     * PUT — replace the configuration outright.
     *
     * Missing members fall back to the defaults rather than to the currently
     * stored values, so the result depends only on the request body.
     *
     * @since 1.0.6
     *
     * @param \WP_REST_Request $request The request.
     * @return \WP_REST_Response|\WP_Error
     */
    public function put_item( $request ) {
        return $this->write( $request, false );
    }

    /**
     * Apply a write request.
     *
     * @since 1.0.6
     *
     * @param \WP_REST_Request $request The request.
     * @param bool             $merge   True to merge onto current values (PATCH),
     *                                  false to start from defaults (PUT).
     * @return \WP_REST_Response|\WP_Error
     */
    protected function write( $request, $merge ) {
        $has_weights    = null !== $request->get_param( 'weights' );
        $has_parameters = null !== $request->get_param( 'parameters' );

        if ( ! $has_weights && ! $has_parameters ) {
            return new \WP_Error(
                'infyp_nothing_to_update',
                __( 'Provide weights, parameters, or both.', 'infy-ai-image-seo-optimizer' ),
                [ 'status' => 400 ]
            );
        }

        $base_weights = $merge
            ? INFYP_SEO_Score_Calculator::get_weights()
            : INFYP_SEO_Score_Calculator::get_default_weights();

        $base_params = $merge
            ? INFYP_SEO_Score_Calculator::get_scoring_params()
            : INFYP_SEO_Score_Calculator::get_default_scoring_params();

        $weights = $has_weights
            ? array_merge( $base_weights, (array) $request->get_param( 'weights' ) )
            : $base_weights;

        $parameters = $base_params;
        if ( $has_parameters ) {
            foreach ( (array) $request->get_param( 'parameters' ) as $section => $values ) {
                if ( isset( $parameters[ $section ] ) && is_array( $values ) ) {
                    $parameters[ $section ] = array_merge( $parameters[ $section ], $values );
                }
            }
        }

        /**
         * Filter the merged scoring configuration before validation.
         *
         * @since 1.0.6
         *
         * @param array            $config  Keys 'weights' and 'parameters'.
         * @param \WP_REST_Request $request The request.
         */
        $config = apply_filters(
            'infyp_rest_scoring_pre_save',
            [
                'weights'    => $weights,
                'parameters' => $parameters,
            ],
            $request
        );

        $weights    = $config['weights'];
        $parameters = $config['parameters'];

        // Validate the merged result, so a partial change cannot leave the
        // stored configuration invalid.
        $valid = INFYP_SEO_Score_Calculator::validate_weights( $weights );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        $valid = INFYP_SEO_Score_Calculator::validate_scoring_params( $parameters );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        // Nothing is written until both members validate.
        INFYP_SEO_Score_Calculator::persist_weights( $weights );
        INFYP_SEO_Score_Calculator::save_scoring_params( $parameters );

        $invalidated = INFYP_SEO_Score_Calculator::invalidate_cached_scores();

        /**
         * Fires after the scoring configuration changes through REST.
         *
         * @since 1.0.6
         *
         * @param array            $config      The stored configuration.
         * @param int              $invalidated Number of cached scores discarded.
         * @param \WP_REST_Request $request     The request.
         */
        do_action( 'infyp_rest_scoring_updated', $config, $invalidated, $request );

        $payload                        = $this->prepare_payload();
        $payload['scores_invalidated']  = $invalidated;

        $response = rest_ensure_response( $payload );

        // Accepted: the configuration is stored, but the scores it governs are
        // recalculated in the background.
        $response->set_status( $invalidated > 0 ? 202 : 200 );

        return $response;
    }

    /**
     * DELETE — restore the default configuration.
     *
     * @since 1.0.6
     *
     * @param \WP_REST_Request $request The request.
     * @return \WP_REST_Response
     */
    public function delete_item( $request ) {
        INFYP_SEO_Score_Calculator::reset_weights();
        INFYP_SEO_Score_Calculator::reset_scoring_params();
        INFYP_SEO_Score_Calculator::reset_cache();

        $invalidated = INFYP_SEO_Score_Calculator::invalidate_cached_scores();

        /** This action is documented in includes/Rest/INFYP_REST_Scoring_Controller.php */
        do_action(
            'infyp_rest_scoring_updated',
            [
                'weights'    => INFYP_SEO_Score_Calculator::get_weights(),
                'parameters' => INFYP_SEO_Score_Calculator::get_scoring_params(),
            ],
            $invalidated,
            $request
        );

        $payload                       = $this->prepare_payload();
        $payload['scores_invalidated'] = $invalidated;

        $response = rest_ensure_response( $payload );
        $response->set_status( $invalidated > 0 ? 202 : 200 );

        return $response;
    }

    /**
     * Shape the scoring configuration payload.
     *
     * @since 1.0.6
     *
     * @return array
     */
    protected function prepare_payload() {
        $payload = [
            'weights'            => (object) INFYP_SEO_Score_Calculator::get_weights(),
            'parameters'         => (object) INFYP_SEO_Score_Calculator::get_scoring_params(),
            'is_customized'      => (object) INFYP_SEO_Score_Calculator::is_customized(),
            'scores_stale_since' => INFYP_SEO_Score_Calculator::get_scores_stale_since(),
        ];

        /**
         * Filter the scoring configuration response.
         *
         * @since 1.0.6
         *
         * @param array $payload The response payload.
         */
        return apply_filters( 'infyp_rest_scoring_response', $payload );
    }

    /**
     * The scoring configuration schema.
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
            'title'      => 'infyp_scoring',
            'type'       => 'object',
            'properties' => [
                'weights'            => [
                    'description'          => __( 'Score component weights, totalling 100.', 'infy-ai-image-seo-optimizer' ),
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'properties'           => $this->build_weight_properties(),
                ],
                'parameters'         => [
                    'description'          => __( 'Per-field scoring parameters, keyed by field section.', 'infy-ai-image-seo-optimizer' ),
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'properties'           => $this->build_parameter_properties(),
                ],
                'is_customized'      => [
                    'description' => __( 'Whether stored weights or parameters deviate from the defaults.', 'infy-ai-image-seo-optimizer' ),
                    'type'        => 'object',
                    'readonly'    => true,
                ],
                'scores_stale_since' => [
                    'description' => __( 'When cached scores were last discarded, or null if never. Scores read as null until the background recalculation reaches each image.', 'infy-ai-image-seo-optimizer' ),
                    'type'        => [ 'string', 'null' ],
                    'readonly'    => true,
                ],
                'scores_invalidated' => [
                    'description' => __( 'Number of cached scores discarded by a write. Present on write responses only.', 'infy-ai-image-seo-optimizer' ),
                    'type'        => 'integer',
                    'readonly'    => true,
                ],
            ],
        ];

        return $this->add_additional_fields_schema( $this->schema );
    }
}
