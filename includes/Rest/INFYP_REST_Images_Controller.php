<?php
/**
 * REST Images Controller - /images routes on the infyp/v1 namespace
 *
 * Routes:
 * - GET  /images                      List images (offset or cursor pagination)
 * - GET  /images/{id}                 Single image SEO data
 * - POST /images/{id}/optimize        Full AI optimization (all fields, one AI call)
 * - POST /images/{id}/field/{field}   Single-field AI optimization
 *
 * All write routes call the shared INFYP_Optimization_Service through the
 * public plugin API, so extension fields (registered via the
 * infyp_allowed_optimize_field_types filter) work here automatically.
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
 * REST controller for image resources.
 *
 * @package IPAIS\Rest
 * @since 1.0.6
 */
class INFYP_REST_Images_Controller extends INFYP_REST_Controller {

    /**
     * Constructor.
     *
     * @since 1.0.6
     */
    public function __construct() {
        parent::__construct();
        $this->rest_base = 'images';
    }

    /**
     * Register the /images routes.
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
                    'callback'            => [ $this, 'get_items' ],
                    'permission_callback' => [ $this, 'get_items_permissions_check' ],
                    'args'                => $this->get_collection_params(),
                ],
                'schema' => [ $this, 'get_public_item_schema' ],
            ]
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id>[\d]+)',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_item' ],
                    'permission_callback' => [ $this, 'get_item_permissions_check' ],
                    'args'                => [
                        'id' => [
                            'description' => esc_html__( 'Attachment ID of the image.', 'infy-ai-image-seo-optimizer' ),
                            'type'        => 'integer',
                            'required'    => true,
                        ],
                    ],
                ],
                'schema' => [ $this, 'get_public_item_schema' ],
            ]
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id>[\d]+)/optimize',
            [
                [
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => [ $this, 'optimize_item' ],
                    'permission_callback' => [ $this, 'optimize_permissions_check' ],
                    'args'                => $this->get_optimize_args(),
                ],
                'schema' => [ $this, 'get_public_item_schema' ],
            ]
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id>[\d]+)/field/(?P<field>[a-z_]+)',
            [
                [
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => [ $this, 'optimize_field_item' ],
                    'permission_callback' => [ $this, 'optimize_permissions_check' ],
                    'args'                => array_merge(
                        [
                            'field' => [
                                'description'       => esc_html__( 'Field type to optimize.', 'infy-ai-image-seo-optimizer' ),
                                'type'              => 'string',
                                'required'          => true,
                                'validate_callback' => [ $this, 'validate_field_type' ],
                            ],
                            'keywords' => [
                                'description' => esc_html__( 'Keyword context for generation. Defaults to the stored image keywords.', 'infy-ai-image-seo-optimizer' ),
                                'type'        => 'array',
                                'items'       => [ 'type' => 'string' ],
                                'required'    => false,
                            ],
                        ],
                        $this->get_optimize_args()
                    ),
                ],
                'schema' => [ $this, 'get_public_item_schema' ],
            ]
        );
    }

    /**
     * Shared body args for the optimize routes.
     *
     * @since 1.0.6
     *
     * @return array Route argument definitions.
     */
    protected function get_optimize_args() {
        /** This filter is documented in includes/Ajax/INFYP_Settings_Ajax.php */
        $max_prompt_length = (int) apply_filters( 'infyp_max_prompt_length', 1000 );

        return [
            'custom_prompt'  => [
                'description'       => esc_html__( 'Per-image prompt override. Replaces the global prompt addon and any context filters entirely for this request.', 'infy-ai-image-seo-optimizer' ),
                'type'              => 'string',
                'required'          => false,
                'default'           => '',
                'maxLength'         => $max_prompt_length,
                'sanitize_callback' => 'sanitize_textarea_field',
                // A custom sanitize_callback disables core's implicit schema
                // validation for this arg, so maxLength must be re-enabled
                // explicitly (see WP_REST_Request::sanitize_params()).
                'validate_callback' => 'rest_validate_request_arg',
            ],
            'clear_keywords' => [
                'description' => esc_html__( 'Delete stored keywords before optimizing so the AI generates fresh ones. Ignored on field routes.', 'infy-ai-image-seo-optimizer' ),
                'type'        => 'boolean',
                'required'    => false,
                'default'     => false,
            ],
            'dry_run'        => [
                'description' => esc_html__( 'Generate values without persisting anything. AI usage is still billed by your provider.', 'infy-ai-image-seo-optimizer' ),
                'type'        => 'boolean',
                'required'    => false,
                'default'     => false,
            ],
            'options'        => [
                'description'          => esc_html__( 'Free-form options forwarded to registered extensions. Keys are defined and documented by whichever extension consumes them; unrecognised keys are ignored.', 'infy-ai-image-seo-optimizer' ),
                'type'                 => 'object',
                'required'             => false,
                'default'              => [],
                'additionalProperties' => true,
            ],
        ];
    }

    /**
     * Validate the {field} route parameter against the extensible whitelist.
     *
     * @since 1.0.6
     *
     * @param string $value The requested field type.
     * @return true|\WP_Error
     */
    public function validate_field_type( $value ) {
        if ( ! in_array( $value, $this->get_allowed_field_types(), true ) ) {
            return new \WP_Error(
                'rest_invalid_param',
                esc_html__( 'Invalid field type specified.', 'infy-ai-image-seo-optimizer' ),
                [ 'status' => 400 ]
            );
        }
        return true;
    }

    /**
     * Permission check for the collection route.
     *
     * Requires the optimize capability (upload_files by default). Authors
     * without edit_others_posts are additionally scoped to their own
     * attachments inside get_items().
     *
     * @since 1.0.6
     *
     * @param \WP_REST_Request $request The request.
     * @return true|\WP_Error
     */
    public function get_items_permissions_check( $request ) {
        if ( ! infyp_user_can( 'optimize' ) ) {
            return $this->permission_error(
                esc_html__( 'You do not have permission to list images.', 'infy-ai-image-seo-optimizer' )
            );
        }
        return true;
    }

    /**
     * Permission check for the single-item route.
     *
     * @since 1.0.6
     *
     * @param \WP_REST_Request $request The request.
     * @return true|\WP_Error
     */
    public function get_item_permissions_check( $request ) {
        return $this->check_image_permission( $request );
    }

    /**
     * Permission check for the optimize routes.
     *
     * @since 1.0.6
     *
     * @param \WP_REST_Request $request The request.
     * @return true|\WP_Error
     */
    public function optimize_permissions_check( $request ) {
        if ( ! infyp_user_can( 'optimize' ) ) {
            return $this->permission_error(
                esc_html__( 'You do not have permission to optimize images.', 'infy-ai-image-seo-optimizer' )
            );
        }

        $image_check = $this->check_image_permission( $request );
        if ( is_wp_error( $image_check ) ) {
            return $image_check;
        }

        /**
         * Filter the permission decision for REST optimization requests.
         *
         * Extensions can veto or additionally restrict optimize calls
         * (e.g. per-field gating) without the free plugin knowing about
         * their rules. Return a WP_Error to deny with a custom reason.
         *
         * @since 1.0.6
         *
         * @param true|\WP_Error   $allowed Current decision.
         * @param \WP_REST_Request $request The request.
         * @param string|null      $field   Field type for field routes, null for full optimize.
         */
        return apply_filters( 'infyp_rest_optimize_permission', true, $request, $request['field'] ?? null );
    }

    /**
     * GET /images — list images.
     *
     * Two pagination modes:
     * - Offset (default): page/per_page, returns X-WP-Total / X-WP-TotalPages headers.
     * - Cursor: pass the opaque `cursor` string from a previous response's
     *   Link header. Constant-time at any depth; totals headers are omitted
     *   (computing them would defeat the point of cursors). Cursor mode
     *   requires an empty search and a date/modified/id sort.
     *
     * @since 1.0.6
     *
     * @param \WP_REST_Request $request The request.
     * @return \WP_REST_Response
     */
    public function get_items( $request ) {
        $cursor = $this->decode_cursor( (string) $request['cursor'] );

        $args = [
            'per_page'    => (int) $request['per_page'],
            'page_number' => (int) $request['page'],
            'search_term' => (string) $request['search'],
            'orderby'     => 'id' === $request['orderby'] ? 'ID' : (string) $request['orderby'],
            'order'       => (string) $request['order'],
            'cursor'      => $cursor,
        ];

        // Author scoping: callers who cannot edit others' posts only see
        // their own uploads.
        if ( ! current_user_can( 'edit_others_posts' ) ) {
            $args['author'] = get_current_user_id();
        }

        /**
         * Filter the query arguments for the REST images collection.
         *
         * @since 1.0.6
         *
         * @param array            $args    Arguments passed to infyp_query_images().
         * @param \WP_REST_Request $request The request.
         */
        $args = apply_filters( 'infyp_rest_images_query_args', $args, $request );

        $result = infyp_query_images( $args );

        $items = [];
        foreach ( $result['items'] as $post ) {
            $data    = $this->prepare_item_for_response( $post, $request );
            $items[] = $this->prepare_response_for_collection( $data );
        }

        /**
         * Filter the prepared REST images collection.
         *
         * @since 1.0.6
         *
         * @param array            $items   Prepared collection items.
         * @param \WP_REST_Request $request The request.
         */
        $items = apply_filters( 'infyp_rest_images_collection', $items, $request );

        $response = rest_ensure_response( $items );

        if ( null === $cursor ) {
            // Offset mode: expose totals the WP way.
            $total = (int) $result['total'];
            $response->header( 'X-WP-Total', (string) $total );
            $response->header( 'X-WP-TotalPages', (string) (int) ceil( $total / max( 1, (int) $request['per_page'] ) ) );
        }

        // Link headers for cursor navigation (available whenever the sort
        // supports cursors, regardless of the mode used for this request).
        if ( ! empty( $result['cursor'] ) ) {
            $base = rest_url( $this->namespace . '/' . $this->rest_base );
            foreach ( [ 'next', 'prev' ] as $rel ) {
                if ( empty( $result['cursor'][ $rel ]['id'] ) ) {
                    continue;
                }
                $link = add_query_arg(
                    array_filter( [
                        'per_page' => (int) $request['per_page'],
                        'orderby'  => (string) $request['orderby'],
                        'order'    => (string) $request['order'],
                        'cursor'   => $this->encode_cursor( $result['cursor'][ $rel ], $rel ),
                    ] ),
                    $base
                );
                $response->link_header( $rel, $link );
            }
        }

        return $response;
    }

    /**
     * GET /images/{id} — single image.
     *
     * @since 1.0.6
     *
     * @param \WP_REST_Request $request The request.
     * @return \WP_REST_Response|\WP_Error
     */
    public function get_item( $request ) {
        $post = get_post( (int) $request['id'] );

        if ( ! $post ) {
            return new \WP_Error(
                'infyp_rest_invalid_image',
                esc_html__( 'Invalid image ID.', 'infy-ai-image-seo-optimizer' ),
                [ 'status' => 404 ]
            );
        }

        return $this->prepare_item_for_response( $post, $request );
    }

    /**
     * POST /images/{id}/optimize — full AI optimization.
     *
     * Runs the complete pipeline (keywords, alt text, title, filename rename
     * with content-reference updates, plus any extension fields) in a single
     * AI call — identical to the admin "Optimize with AI" button.
     *
     * Synchronous: a generation can take 10-60s. Callers behind aggressive
     * gateway timeouts should use dry_run sparingly and batch work through
     * background tooling.
     *
     * @since 1.0.6
     *
     * @param \WP_REST_Request $request The request.
     * @return \WP_REST_Response|\WP_Error
     */
    public function optimize_item( $request ) {
        $limited = $this->check_rate_limit( 'infyp_rest_optimize' );
        if ( is_wp_error( $limited ) ) {
            return $limited;
        }

        $image_id = (int) $request['id'];

        // A single generation can exceed typical PHP execution limits.
        if ( function_exists( 'set_time_limit' ) ) {
            // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- A single AI generation can exceed the default limit; without this the request dies mid-call and the caller cannot tell a timeout from a failure.
            set_time_limit( 120 );
        }

        $result = infyp_optimize_and_save( $image_id, [
            'custom_prompt'  => (string) $request['custom_prompt'],
            'clear_keywords' => (bool) $request['clear_keywords'],
            'dry_run'        => (bool) $request['dry_run'],
            'options'        => (array) $request['options'],
        ] );

        \IPAIS\Database\INFYP_REST_Log::get_instance()->record( [
            'route'    => 'optimize',
            'image_id' => $image_id,
            'dry_run'  => (bool) $request['dry_run'],
            'success'  => ! is_wp_error( $result ),
            'model'    => is_wp_error( $result ) ? '' : (string) $result['model'],
            'error'    => is_wp_error( $result ) ? $result->get_error_message() : '',
        ] );

        if ( is_wp_error( $result ) ) {
            return $this->normalize_service_error( $result );
        }

        /**
         * Fires after a successful REST optimization.
         *
         * @since 1.0.6
         *
         * @param int              $image_id The attachment ID.
         * @param array            $result   The service result envelope.
         * @param \WP_REST_Request $request  The request.
         */
        do_action( 'infyp_rest_after_optimize', $image_id, $result, $request );

        $item = $this->prepare_item_for_response( get_post( $image_id ), $request );

        $response = rest_ensure_response( [
            'optimization' => [
                'new_data'        => $result['new_data'],
                'model'           => $result['model'],
                'dry_run'         => (bool) $result['dry_run'],
                'prompt_modified' => ! empty( $result['prompt_modified'] ),
            ],
            'image'        => $this->prepare_response_for_collection( $item ),
        ] );
        $response->set_status( 200 );

        return $response;
    }

    /**
     * POST /images/{id}/field/{field} — single-field AI optimization.
     *
     * Persists atomically, including filename renames (file + content
     * references in one call).
     *
     * @since 1.0.6
     *
     * @param \WP_REST_Request $request The request.
     * @return \WP_REST_Response|\WP_Error
     */
    public function optimize_field_item( $request ) {
        $limited = $this->check_rate_limit( 'infyp_rest_field_optimize' );
        if ( is_wp_error( $limited ) ) {
            return $limited;
        }

        $image_id = (int) $request['id'];

        if ( function_exists( 'set_time_limit' ) ) {
            // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- A single AI generation can exceed the default limit; without this the request dies mid-call and the caller cannot tell a timeout from a failure.
            set_time_limit( 120 );
        }

        $args = [
            'custom_prompt' => (string) $request['custom_prompt'],
            'dry_run'       => (bool) $request['dry_run'],
            'options'       => (array) $request['options'],
        ];

        if ( is_array( $request['keywords'] ) && ! empty( $request['keywords'] ) ) {
            $args['keywords'] = array_map( 'sanitize_text_field', $request['keywords'] );
        }

        $result = infyp_optimize_field_and_save( $image_id, (string) $request['field'], $args );

        \IPAIS\Database\INFYP_REST_Log::get_instance()->record( [
            'route'    => 'field:' . (string) $request['field'],
            'image_id' => $image_id,
            'dry_run'  => (bool) $request['dry_run'],
            'success'  => ! is_wp_error( $result ),
            'model'    => is_wp_error( $result ) ? '' : (string) $result['model'],
            'error'    => is_wp_error( $result ) ? $result->get_error_message() : '',
        ] );

        if ( is_wp_error( $result ) ) {
            return $this->normalize_service_error( $result );
        }

        /** This action is documented in includes/Rest/INFYP_REST_Images_Controller.php */
        do_action( 'infyp_rest_after_optimize', $image_id, $result, $request );

        $item = $this->prepare_item_for_response( get_post( $image_id ), $request );

        return rest_ensure_response( [
            'optimization' => [
                'field_type'    => $result['field_type'],
                'old_value'     => $result['old_value'],
                'new_value'     => $result['new_value'],
                'applied'       => (bool) $result['applied'],
                'updated_posts' => (int) $result['updated_posts'],
                'model'         => $result['model'],
                'dry_run'       => (bool) $result['dry_run'],
                'prompt_modified' => ! empty( $result['prompt_modified'] ),
            ],
            'image'        => $this->prepare_response_for_collection( $item ),
        ] );
    }

    /**
     * Prepare an image for the REST response.
     *
     * SEO score is the CACHED value only — a GET must never trigger score
     * computation (which writes meta). Missing scores are null; the
     * infyp_batch_cache_scores cron backfills them.
     *
     * @since 1.0.6
     *
     * @param \WP_Post         $item    The attachment post.
     * @param \WP_REST_Request $request The request.
     * @return \WP_REST_Response
     */
    public function prepare_item_for_response( $item, $request ) {
        $image_id = (int) $item->ID;

        $keywords     = get_post_meta( $image_id, '_infyp_keywords', true );
        $cached_score = get_post_meta( $image_id, '_infyp_seo_score', true );
        $file         = get_attached_file( $image_id );

        $data = [
            'id'              => $image_id,
            'date'            => mysql_to_rfc3339( $item->post_date ),
            'modified'        => mysql_to_rfc3339( $item->post_modified ),
            'source_url'      => wp_get_attachment_url( $image_id ),
            'mime_type'       => $item->post_mime_type,
            'filename'        => $file ? pathinfo( $file, PATHINFO_FILENAME ) : '',
            'title'           => $item->post_title,
            'alt_text'        => (string) get_post_meta( $image_id, '_wp_attachment_image_alt', true ),
            'caption'         => (string) $item->post_excerpt,
            'description'     => (string) $item->post_content,
            'keywords'        => is_array( $keywords ) ? array_values( $keywords ) : [],
            'keywords_manual' => (bool) get_post_meta( $image_id, '_infyp_keywords_manual', true ),
            'optimized'       => (bool) get_post_meta( $image_id, '_infyp_has_been_optimized', true ),
            'last_optimized'  => (string) get_post_meta( $image_id, '_infyp_last_optimized', true ),
            'seo_score'       => '' === (string) $cached_score ? null : (int) $cached_score,
        ];

        /**
         * Filter a single image's REST response data.
         *
         * @since 1.0.6
         *
         * @param array            $data     Prepared response fields.
         * @param int              $image_id The attachment ID.
         * @param \WP_REST_Request $request  The request.
         */
        $data = apply_filters( 'infyp_rest_image_response', $data, $image_id, $request );

        $context = ! empty( $request['context'] ) ? $request['context'] : 'view';
        $data    = $this->filter_response_by_context( $data, $context );

        return rest_ensure_response( $data );
    }

    /**
     * Collection query parameters.
     *
     * @since 1.0.6
     *
     * @return array
     */
    public function get_collection_params() {
        return [
            'context'  => $this->get_context_param( [ 'default' => 'view' ] ),
            'page'     => [
                'description' => esc_html__( 'Current page of the collection (offset mode).', 'infy-ai-image-seo-optimizer' ),
                'type'        => 'integer',
                'default'     => 1,
                'minimum'     => 1,
            ],
            'per_page' => [
                'description' => esc_html__( 'Maximum number of items to return.', 'infy-ai-image-seo-optimizer' ),
                'type'        => 'integer',
                'default'     => 10,
                'minimum'     => 1,
                'maximum'     => 100,
            ],
            'search'   => [
                'description' => esc_html__( 'Limit results to those matching a string.', 'infy-ai-image-seo-optimizer' ),
                'type'        => 'string',
                'default'     => '',
            ],
            'orderby'  => [
                'description' => esc_html__( 'Sort collection by attribute. Cursor pagination requires date, modified, or id.', 'infy-ai-image-seo-optimizer' ),
                'type'        => 'string',
                'default'     => 'date',
                'enum'        => [ 'date', 'modified', 'id', 'title', 'seo_score' ],
            ],
            'order'    => [
                'description' => esc_html__( 'Order sort attribute ascending or descending.', 'infy-ai-image-seo-optimizer' ),
                'type'        => 'string',
                'default'     => 'desc',
                'enum'        => [ 'asc', 'desc' ],
            ],
            'cursor'   => [
                'description' => esc_html__( 'Opaque pagination cursor from a previous response Link header. When set, page is ignored and total headers are omitted.', 'infy-ai-image-seo-optimizer' ),
                'type'        => 'string',
                'default'     => '',
            ],
        ];
    }

    /**
     * The image resource schema.
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
            'title'      => 'infyp_image',
            'type'       => 'object',
            'properties' => [
                'id'              => [
                    'description' => esc_html__( 'Attachment ID.', 'infy-ai-image-seo-optimizer' ),
                    'type'        => 'integer',
                    'context'     => [ 'view', 'edit', 'embed' ],
                    'readonly'    => true,
                ],
                'date'            => [
                    'description' => esc_html__( 'Upload date (RFC3339).', 'infy-ai-image-seo-optimizer' ),
                    'type'        => 'string',
                    'format'      => 'date-time',
                    'context'     => [ 'view', 'edit', 'embed' ],
                    'readonly'    => true,
                ],
                'modified'        => [
                    'description' => esc_html__( 'Last modification date (RFC3339).', 'infy-ai-image-seo-optimizer' ),
                    'type'        => 'string',
                    'format'      => 'date-time',
                    'context'     => [ 'view', 'edit' ],
                    'readonly'    => true,
                ],
                'source_url'      => [
                    'description' => esc_html__( 'Public URL of the image file.', 'infy-ai-image-seo-optimizer' ),
                    'type'        => 'string',
                    'format'      => 'uri',
                    'context'     => [ 'view', 'edit', 'embed' ],
                    'readonly'    => true,
                ],
                'mime_type'       => [
                    'description' => esc_html__( 'Image MIME type.', 'infy-ai-image-seo-optimizer' ),
                    'type'        => 'string',
                    'context'     => [ 'view', 'edit', 'embed' ],
                    'readonly'    => true,
                ],
                'filename'        => [
                    'description' => esc_html__( 'Current filename stem (without extension).', 'infy-ai-image-seo-optimizer' ),
                    'type'        => 'string',
                    'context'     => [ 'view', 'edit', 'embed' ],
                    'readonly'    => true,
                ],
                'title'           => [
                    'description' => esc_html__( 'Attachment title.', 'infy-ai-image-seo-optimizer' ),
                    'type'        => 'string',
                    'context'     => [ 'view', 'edit', 'embed' ],
                    'readonly'    => true,
                ],
                'alt_text'        => [
                    'description' => esc_html__( 'Image alt text.', 'infy-ai-image-seo-optimizer' ),
                    'type'        => 'string',
                    'context'     => [ 'view', 'edit', 'embed' ],
                    'readonly'    => true,
                ],
                'caption'         => [
                    'description' => esc_html__( 'Attachment caption.', 'infy-ai-image-seo-optimizer' ),
                    'type'        => 'string',
                    'context'     => [ 'view', 'edit' ],
                    'readonly'    => true,
                ],
                'description'     => [
                    'description' => esc_html__( 'Attachment description.', 'infy-ai-image-seo-optimizer' ),
                    'type'        => 'string',
                    'context'     => [ 'view', 'edit' ],
                    'readonly'    => true,
                ],
                'keywords'        => [
                    'description' => esc_html__( 'Stored SEO keywords.', 'infy-ai-image-seo-optimizer' ),
                    'type'        => 'array',
                    'items'       => [ 'type' => 'string' ],
                    'context'     => [ 'view', 'edit' ],
                    'readonly'    => true,
                ],
                'keywords_manual' => [
                    'description' => esc_html__( 'Whether keywords were set manually (AI will not overwrite them).', 'infy-ai-image-seo-optimizer' ),
                    'type'        => 'boolean',
                    'context'     => [ 'view', 'edit' ],
                    'readonly'    => true,
                ],
                'optimized'       => [
                    'description' => esc_html__( 'Whether the image has been AI-optimized.', 'infy-ai-image-seo-optimizer' ),
                    'type'        => 'boolean',
                    'context'     => [ 'view', 'edit', 'embed' ],
                    'readonly'    => true,
                ],
                'last_optimized'  => [
                    'description' => esc_html__( 'Timestamp of the last optimization.', 'infy-ai-image-seo-optimizer' ),
                    'type'        => 'string',
                    'context'     => [ 'view', 'edit' ],
                    'readonly'    => true,
                ],
                'seo_score'       => [
                    'description' => esc_html__( 'Cached SEO score (0-100), or null when not yet computed.', 'infy-ai-image-seo-optimizer' ),
                    'type'        => [ 'integer', 'null' ],
                    'context'     => [ 'view', 'edit', 'embed' ],
                    'readonly'    => true,
                ],
            ],
        ];

        return $this->add_additional_fields_schema( $this->schema );
    }

    /**
     * Map a service WP_Error to an HTTP status when it has none.
     *
     * @since 1.0.6
     *
     * @param \WP_Error $error The service error.
     * @return \WP_Error Error with a status in its data.
     */
    protected function normalize_service_error( $error ) {
        $data = $error->get_error_data();
        if ( is_array( $data ) && isset( $data['status'] ) ) {
            return $error;
        }

        $status_map = [
            'infyp_invalid_image'       => 404,
            'infyp_invalid_field_type'  => 400,
            'infyp_missing_result_field' => 502,
            'infyp_insufficient_memory' => 503,
            // AI backend not usable: missing plugin key or unconfigured /
            // unavailable WordPress AI Connectors (WP 7.0+).
            'api_key_missing'           => 503,
            'wp_ai_not_configured'      => 503,
            'wp_ai_unavailable'         => 501,
        ];

        $status = $status_map[ $error->get_error_code() ] ?? 500;
        $error->add_data( [ 'status' => $status ] );

        return $error;
    }

    /**
     * Encode cursor data into the opaque REST cursor string.
     *
     * @since 1.0.6
     *
     * @param array  $cursor    Cursor data ('id', 'value').
     * @param string $direction 'next' or 'prev'.
     * @return string Opaque cursor.
     */
    protected function encode_cursor( $cursor, $direction ) {
        return rtrim(
            strtr(
                base64_encode( wp_json_encode( [
                    'id'        => (int) $cursor['id'],
                    'value'     => (string) $cursor['value'],
                    'direction' => 'prev' === $direction ? 'prev' : 'next',
                ] ) ),
                '+/',
                '-_'
            ),
            '='
        );
    }

    /**
     * Decode an opaque REST cursor string.
     *
     * @since 1.0.6
     *
     * @param string $cursor The opaque cursor from the request.
     * @return array|null Cursor array for the query builder, or null when absent/invalid.
     */
    protected function decode_cursor( $cursor ) {
        if ( '' === $cursor ) {
            return null;
        }

        $decoded = base64_decode( strtr( $cursor, '-_', '+/' ), true );
        if ( false === $decoded ) {
            return null;
        }

        $data = json_decode( $decoded, true );
        if ( ! is_array( $data ) || empty( $data['id'] ) ) {
            return null;
        }

        return [
            'id'        => (int) $data['id'],
            'value'     => isset( $data['value'] ) ? (string) $data['value'] : '',
            'direction' => ( isset( $data['direction'] ) && 'prev' === $data['direction'] ) ? 'prev' : 'next',
        ];
    }
}
