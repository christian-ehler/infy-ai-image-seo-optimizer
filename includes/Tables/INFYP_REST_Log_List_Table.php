<?php
/**
 * REST Activity Log List Table
 *
 * Renders the REST API activity log using the core list-table UI: sortable
 * columns, per-page screen option, pagination, and the user/route/result/image
 * filter row.
 *
 * @package   IPAIS\Tables
 * @since 1.0.6
 * @author    InfyPress
 * @license   GPL-2.0-or-later
 */

namespace IPAIS\Tables;

// Exit if accessed directly (placed after namespace per PHP requirement).
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use \IPAIS\Database\INFYP_REST_Log;

if ( ! class_exists( '\WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * REST Activity Log List Table Class
 *
 * @package IPAIS\Tables
 * @since 1.0.6
 */
class INFYP_REST_Log_List_Table extends \WP_List_Table {

    /**
     * Screen option key for the per-page setting.
     *
     * @since 1.0.6
     * @var   string
     */
    const PER_PAGE_OPTION = 'infyp_rest_log_per_page';

    /**
     * Constructor.
     *
     * @since 1.0.6
     */
    public function __construct() {
        parent::__construct( [
            'singular' => 'infyp_rest_log_entry',
            'plural'   => 'infyp_rest_log_entries',
            'ajax'     => false,
        ] );
    }

    /**
     * Column definitions.
     *
     * @since 1.0.6
     *
     * @return array
     */
    public function get_columns() {
        return [
            'created_at' => esc_html__( 'Time', 'infy-ai-image-seo-optimizer' ),
            'user_id'    => esc_html__( 'User', 'infy-ai-image-seo-optimizer' ),
            'route'      => esc_html__( 'Route', 'infy-ai-image-seo-optimizer' ),
            'image_id'   => esc_html__( 'Image', 'infy-ai-image-seo-optimizer' ),
            'dry_run'    => esc_html__( 'Mode', 'infy-ai-image-seo-optimizer' ),
            'success'    => esc_html__( 'Result', 'infy-ai-image-seo-optimizer' ),
            'detail'     => esc_html__( 'Model', 'infy-ai-image-seo-optimizer' ),
        ];
    }

    /**
     * Sortable columns.
     *
     * @since 1.0.6
     *
     * @return array
     */
    public function get_sortable_columns() {
        return [
            'created_at' => [ 'created_at', true ],
            'user_id'    => [ 'user_id', false ],
            'route'      => [ 'route', false ],
            'image_id'   => [ 'image_id', false ],
            'success'    => [ 'success', false ],
        ];
    }

    /**
     * Message shown when the log is empty.
     *
     * @since 1.0.6
     *
     * @return void
     */
    public function no_items() {
        esc_html_e( 'No REST API activity logged yet.', 'infy-ai-image-seo-optimizer' );
    }

    /**
     * Read the active filters from the request.
     *
     * Read-only GET filters on an admin listing, mirroring the core list tables.
     *
     * @since 1.0.6
     *
     * @return array
     */
    public function get_active_filters() {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only GET filters on an admin listing.
        return [
            'user_id'  => isset( $_GET['log_user'] ) ? absint( $_GET['log_user'] ) : 0,
            'route'    => isset( $_GET['log_route'] ) ? sanitize_text_field( wp_unslash( $_GET['log_route'] ) ) : '',
            'image_id' => isset( $_GET['log_image'] ) ? absint( $_GET['log_image'] ) : 0,
            'result'   => isset( $_GET['log_result'] ) ? sanitize_text_field( wp_unslash( $_GET['log_result'] ) ) : '',
        ];
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
    }

    /**
     * Fetch rows for the current view.
     *
     * @since 1.0.6
     *
     * @return void
     */
    public function prepare_items() {
        $log = INFYP_REST_Log::get_instance();

        $per_page = $this->get_items_per_page( self::PER_PAGE_OPTION, 25 );
        $paged    = $this->get_pagenum();

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only GET sorting on an admin listing.
        $orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'created_at';
        $order   = isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'desc';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        $args = array_merge(
            $this->get_active_filters(),
            [
                'per_page' => $per_page,
                'page'     => $paged,
                'orderby'  => $orderby,
                'order'    => $order,
            ]
        );

        $result = $log->query( $args );

        $this->items = $result['items'];

        $this->set_pagination_args( [
            'total_items' => (int) $result['total'],
            'per_page'    => $per_page,
            'total_pages' => (int) ceil( $result['total'] / max( 1, $per_page ) ),
        ] );

        $this->_column_headers = [ $this->get_columns(), [], $this->get_sortable_columns() ];
    }

    /**
     * Render the filter controls above the table.
     *
     * @since 1.0.6
     *
     * @param string $which 'top' or 'bottom'.
     * @return void
     */
    public function extra_tablenav( $which ) {
        if ( 'top' !== $which ) {
            return;
        }

        $log     = INFYP_REST_Log::get_instance();
        $filters = $this->get_active_filters();

        echo '<div class="alignleft actions infyp-rest-filters">';

        echo '<label class="screen-reader-text" for="infyp-log-user">' . esc_html__( 'Filter by user', 'infy-ai-image-seo-optimizer' ) . '</label>';
        echo '<select name="log_user" id="infyp-log-user">';
        echo '<option value="0">' . esc_html__( 'All users', 'infy-ai-image-seo-optimizer' ) . '</option>';
        foreach ( $log->get_logged_users() as $row ) {
            $user  = get_userdata( (int) $row->user_id );
            $label = $user ? $user->user_login : sprintf( '#%d', (int) $row->user_id );
            printf(
                '<option value="%d" %s>%s</option>',
                (int) $row->user_id,
                selected( $filters['user_id'], (int) $row->user_id, false ),
                esc_html( $label )
            );
        }
        echo '</select>';

        echo '<label class="screen-reader-text" for="infyp-log-route">' . esc_html__( 'Filter by route', 'infy-ai-image-seo-optimizer' ) . '</label>';
        echo '<select name="log_route" id="infyp-log-route">';
        echo '<option value="">' . esc_html__( 'All routes', 'infy-ai-image-seo-optimizer' ) . '</option>';
        foreach ( $log->get_logged_routes() as $route ) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr( $route ),
                selected( $filters['route'], $route, false ),
                esc_html( $route )
            );
        }
        echo '</select>';

        echo '<label class="screen-reader-text" for="infyp-log-result">' . esc_html__( 'Filter by result', 'infy-ai-image-seo-optimizer' ) . '</label>';
        echo '<select name="log_result" id="infyp-log-result">';
        echo '<option value="">' . esc_html__( 'All results', 'infy-ai-image-seo-optimizer' ) . '</option>';
        printf( '<option value="success" %s>%s</option>', selected( $filters['result'], 'success', false ), esc_html__( 'Success', 'infy-ai-image-seo-optimizer' ) );
        printf( '<option value="failed" %s>%s</option>', selected( $filters['result'], 'failed', false ), esc_html__( 'Failed', 'infy-ai-image-seo-optimizer' ) );
        echo '</select>';

        echo '<label class="screen-reader-text" for="infyp-log-image">' . esc_html__( 'Filter by image ID', 'infy-ai-image-seo-optimizer' ) . '</label>';
        printf(
            '<input type="number" name="log_image" id="infyp-log-image" min="0" placeholder="%s" value="%s" class="infyp-rest-filter-image">',
            esc_attr__( 'Image ID', 'infy-ai-image-seo-optimizer' ),
            $filters['image_id'] ? esc_attr( (string) $filters['image_id'] ) : ''
        );

        submit_button( esc_html__( 'Filter', 'infy-ai-image-seo-optimizer' ), 'secondary', 'infyp-filter', false );

        echo '</div>';
    }

    /**
     * Time column.
     *
     * @since 1.0.6
     *
     * @param object $item Log row.
     * @return string
     */
    public function column_created_at( $item ) {
        return esc_html( (string) $item->created_at );
    }

    /**
     * User column.
     *
     * @since 1.0.6
     *
     * @param object $item Log row.
     * @return string
     */
    public function column_user_id( $item ) {
        $user = get_userdata( (int) $item->user_id );

        return esc_html( $user ? $user->user_login : sprintf( '#%d', (int) $item->user_id ) );
    }

    /**
     * Route column.
     *
     * @since 1.0.6
     *
     * @param object $item Log row.
     * @return string
     */
    public function column_route( $item ) {
        return '<code>' . esc_html( (string) $item->route ) . '</code>';
    }

    /**
     * Image column, linked to the attachment editor when available.
     *
     * @since 1.0.6
     *
     * @param object $item Log row.
     * @return string
     */
    public function column_image_id( $item ) {
        $image_id = (int) $item->image_id;
        $title    = get_the_title( $image_id );
        $edit     = get_edit_post_link( $image_id );
        $label    = $title ? $title : sprintf( '#%d', $image_id );

        if ( $edit ) {
            return '<a href="' . esc_url( $edit ) . '">' . esc_html( $label ) . '</a>';
        }

        return esc_html( sprintf( '#%d', $image_id ) );
    }

    /**
     * Mode column.
     *
     * @since 1.0.6
     *
     * @param object $item Log row.
     * @return string
     */
    public function column_dry_run( $item ) {
        if ( $item->dry_run ) {
            return '<span class="infyp-status notice-warning">' . esc_html__( 'Dry run', 'infy-ai-image-seo-optimizer' ) . '</span>';
        }

        return '<span class="infyp-status notice-info">' . esc_html__( 'Persisted', 'infy-ai-image-seo-optimizer' ) . '</span>';
    }

    /**
     * Result column.
     *
     * @since 1.0.6
     *
     * @param object $item Log row.
     * @return string
     */
    public function column_success( $item ) {
        // Reuses the .infyp-status badge the API Usage table uses, so both
        // tables read the same way.
        if ( $item->success ) {
            return '<span class="infyp-status notice-success">' . esc_html__( 'Success', 'infy-ai-image-seo-optimizer' ) . '</span>';
        }

        return '<span class="infyp-status notice-error">' . esc_html__( 'Failed', 'infy-ai-image-seo-optimizer' ) . '</span>';
    }

    /**
     * Model / error detail column.
     *
     * @since 1.0.6
     *
     * @param object $item Log row.
     * @return string
     */
    public function column_detail( $item ) {
        return $item->success
            ? esc_html( (string) $item->model )
            : esc_html( (string) $item->error );
    }

    /**
     * Fallback renderer for any column without a dedicated method.
     *
     * @since 1.0.6
     *
     * @param object $item        Log row.
     * @param string $column_name Column key.
     * @return string
     */
    public function column_default( $item, $column_name ) {
        return isset( $item->$column_name ) ? esc_html( (string) $item->$column_name ) : '';
    }
}
