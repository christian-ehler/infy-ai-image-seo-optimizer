<?php
/**
 * REST Controller Base - Shared infrastructure for the infyp/v1 namespace
 *
 * Provides the namespace constant, permission helpers that return proper
 * WP_Error objects (401 for unauthenticated, 403 for forbidden), and the
 * rate-limit wiring shared by all plugin REST controllers.
 *
 * Authentication is handled entirely by WordPress core: Application
 * Passwords are the documented programmatic path; cookie + X-WP-Nonce
 * remains valid for logged-in browser contexts.
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

use \IPAIS\Core\INFYP_Security_Manager;

/**
 * Abstract base controller for all infyp/v1 REST routes.
 *
 * @package IPAIS\Rest
 * @since 1.0.6
 */
abstract class INFYP_REST_Controller extends \WP_REST_Controller {

    /**
     * The REST namespace shared by all plugin routes.
     *
     * Extensions registering routes via the `infyp_rest_routes` action
     * receive this string and must not hardcode their own copy.
     *
     * @since 1.0.6
     * @var   string
     */
    const REST_NAMESPACE = 'infyp/v1';

    /**
     * Constructor. Sets the WP_REST_Controller namespace property.
     *
     * @since 1.0.6
     */
    public function __construct() {
        $this->namespace = self::REST_NAMESPACE;
    }

    /**
     * Register the REST rate-limit action mapping.
     *
     * Maps the REST optimization actions onto the existing per-user
     * rate-limit buckets (`api_calls`: 500/hour by default). The
     * `infyp_rate_limited_actions` filter is evaluated on every check, so
     * this only needs to be hooked before the first REST callback runs.
     *
     * Note: buckets are keyed per user, not per application password — all
     * of a user's application passwords share one bucket. The limit itself
     * is filterable through `infyp_rate_limits`; setting it to 0 removes the
     * cap entirely.
     *
     * @since 1.0.6
     *
     * @return void
     */
    public static function register_rate_limits() {
        add_filter( 'infyp_rate_limited_actions', function ( $actions ) {
            $actions['infyp_rest_optimize']       = 'api_calls';
            $actions['infyp_rest_field_optimize'] = 'api_calls';
            return $actions;
        } );
    }

    /**
     * Seconds until the rate-limit window ends, for the Retry-After header of
     * the 429 response built from the WP_Error below.
     *
     * @since 1.0.9
     * @var int
     */
    private static $retry_after = 0;

    /**
     * Check the per-user rate limit for a REST action.
     *
     * @since 1.0.6
     * @since 1.0.9 The 429 response gets a `Retry-After` header (seconds until
     *              the window ends), added when WordPress sends the error.
     *
     * @param string $action Rate-limited action name (must be present in the
     *                       infyp_rate_limited_actions map).
     * @return true|\WP_Error True when within limits, WP_Error(429) otherwise.
     */
    protected function check_rate_limit( $action ) {
        $security = INFYP_Security_Manager::get_instance();
        if ( $security->check_rate_limit( $action ) ) {
            return true;
        }

        // A WP_Error cannot carry response headers; add the header when the
        // REST server has turned the error into a response.
        self::$retry_after = max( 1, $security->get_rate_limit_retry_after( $action ) );
        if ( ! has_filter( 'rest_post_dispatch', [ self::class, 'add_retry_after_header' ] ) ) {
            add_filter( 'rest_post_dispatch', [ self::class, 'add_retry_after_header' ], 10, 1 );
        }

        return new \WP_Error(
            'infyp_rest_rate_limited',
            esc_html__( 'Rate limit exceeded. Please retry later.', 'infy-ai-image-seo-optimizer' ),
            [ 'status' => 429 ]
        );
    }

    /**
     * Add `Retry-After` to this plugin's rate-limit response.
     *
     * Hooked to `rest_post_dispatch` only after a refused call.
     *
     * @since 1.0.9
     *
     * @param \WP_HTTP_Response|mixed $response The response about to be sent.
     * @return \WP_HTTP_Response|mixed
     */
    public static function add_retry_after_header( $response ) {
        if ( $response instanceof \WP_HTTP_Response && 429 === $response->get_status() ) {
            $data = $response->get_data();
            if ( is_array( $data ) && isset( $data['code'] ) && 'infyp_rest_rate_limited' === $data['code'] && self::$retry_after > 0 ) {
                $response->header( 'Retry-After', (string) self::$retry_after );
            }
        }
        return $response;
    }

    /**
     * Build a permission WP_Error with the correct HTTP status.
     *
     * Uses rest_authorization_required_code() so unauthenticated requests
     * get 401 and authenticated-but-forbidden requests get 403.
     *
     * @since 1.0.6
     *
     * @param string $message Human-readable denial reason.
     * @return \WP_Error
     */
    protected function permission_error( $message ) {
        return new \WP_Error(
            'infyp_rest_forbidden',
            $message,
            [ 'status' => rest_authorization_required_code() ]
        );
    }

    /**
     * Validate that a request targets an editable image attachment.
     *
     * @since 1.0.6
     *
     * @param \WP_REST_Request $request The request (expects an 'id' route param).
     * @return true|\WP_Error True when the image exists and the user may edit it.
     */
    protected function check_image_permission( $request ) {
        $image_id = (int) $request['id'];

        if ( ! INFYP_Security_Manager::get_instance()->validate_attachment_id( $image_id ) ) {
            return new \WP_Error(
                'infyp_rest_invalid_image',
                esc_html__( 'Invalid image ID.', 'infy-ai-image-seo-optimizer' ),
                [ 'status' => 404 ]
            );
        }

        if ( ! current_user_can( 'edit_post', $image_id ) ) {
            return $this->permission_error(
                esc_html__( 'You do not have permission to edit this image.', 'infy-ai-image-seo-optimizer' )
            );
        }

        return true;
    }

    /**
     * Get the allowed optimizable field types.
     *
     * The whitelist is extensible: extensions register additional types via
     * the `infyp_allowed_optimize_field_types` filter, and those types become
     * valid on the REST field routes automatically.
     *
     * @since 1.0.6
     *
     * @return string[] Allowed field type slugs.
     */
    protected function get_allowed_field_types() {
        /** This filter is documented in includes/Ajax/INFYP_AI_Ajax.php */
        $types = apply_filters( 'infyp_allowed_optimize_field_types', [ 'filename', 'alt_text', 'title' ] );

        return array_values( array_unique( array_map( 'sanitize_key', (array) $types ) ) );
    }
}
