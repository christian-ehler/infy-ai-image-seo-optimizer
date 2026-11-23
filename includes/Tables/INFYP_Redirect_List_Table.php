<?php
/**
 * Redirect List Table — Displays 301 redirect mappings in the admin.
 *
 * Extends WP_List_Table to show a sortable, filterable, paginated table of
 * all image URL redirects with status badges, hit counts, page-builder
 * results, and before/after URL comparison.
 *
 * @package   IPAIS\Tables
 * @since     1.1.0
 * @author    InfyPress
 * @license   GPL-2.0-or-later
 */

namespace IPAIS\Tables;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use \WP_List_Table;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class INFYP_Redirect_List_Table extends WP_List_Table {

	/**
	 * Query helper.
	 *
	 * @var INFYP_Redirect_Query
	 */
	private $query;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( [
			'singular' => __( 'Redirect', 'infy-ai-image-seo-optimizer' ),
			'plural'   => __( 'Redirects', 'infy-ai-image-seo-optimizer' ),
			'ajax'     => false,
		] );

		$this->query = new INFYP_Redirect_Query();
	}

	/**
	 * Column definitions.
	 *
	 * @return array
	 */
	public function get_columns() {
		return [
			'cb'                   => '<input type="checkbox" />',
			'image_id'             => __( 'Image', 'infy-ai-image-seo-optimizer' ),
			'old_url'              => __( 'Old URL', 'infy-ai-image-seo-optimizer' ),
			'new_url'              => __( 'New URL', 'infy-ai-image-seo-optimizer' ),
			'status_code'          => __( 'Status Code', 'infy-ai-image-seo-optimizer' ),
			'hit_count'            => __( 'Hits', 'infy-ai-image-seo-optimizer' ),
			'last_hit_at'          => __( 'Last Hit', 'infy-ai-image-seo-optimizer' ),
			'created_at'           => __( 'Created', 'infy-ai-image-seo-optimizer' ),
			'source'               => __( 'Source', 'infy-ai-image-seo-optimizer' ),
			'pagebuilder_results'  => __( 'Page Builders', 'infy-ai-image-seo-optimizer' ),
			'status'               => __( 'Status', 'infy-ai-image-seo-optimizer' ),
		];
	}

	/**
	 * Sortable columns.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return [
			'created_at' => [ 'created_at', true ],
			'hit_count'  => [ 'hit_count', false ],
			'status'     => [ 'status', false ],
			'source'     => [ 'source', false ],
		];
	}

	/**
	 * Bulk actions.
	 *
	 * @return array
	 */
	protected function get_bulk_actions() {
		return [
			'disable' => __( 'Disable', 'infy-ai-image-seo-optimizer' ),
			'enable'  => __( 'Re-enable', 'infy-ai-image-seo-optimizer' ),
			'delete'  => __( 'Delete', 'infy-ai-image-seo-optimizer' ),
		];
	}

	/**
	 * Extra table navigation — filter dropdowns.
	 *
	 * @param string $which 'top' or 'bottom'.
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filter display
		$current_status = isset( $_REQUEST['redirect_status'] ) ? sanitize_key( wp_unslash( $_REQUEST['redirect_status'] ) ) : '';
		$current_source = isset( $_REQUEST['redirect_source'] ) ? sanitize_key( wp_unslash( $_REQUEST['redirect_source'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		?>
		<div class="alignleft actions">
			<select name="redirect_status">
				<option value=""><?php esc_html_e( 'All statuses', 'infy-ai-image-seo-optimizer' ); ?></option>
				<option value="active" <?php selected( $current_status, 'active' ); ?>><?php esc_html_e( 'Active', 'infy-ai-image-seo-optimizer' ); ?></option>
				<option value="disabled" <?php selected( $current_status, 'disabled' ); ?>><?php esc_html_e( 'Disabled', 'infy-ai-image-seo-optimizer' ); ?></option>
				<option value="expired" <?php selected( $current_status, 'expired' ); ?>><?php esc_html_e( 'Expired', 'infy-ai-image-seo-optimizer' ); ?></option>
			</select>

			<select name="redirect_source">
				<option value=""><?php esc_html_e( 'All sources', 'infy-ai-image-seo-optimizer' ); ?></option>
				<option value="manual" <?php selected( $current_source, 'manual' ); ?>><?php esc_html_e( 'Manual', 'infy-ai-image-seo-optimizer' ); ?></option>
				<option value="ai" <?php selected( $current_source, 'ai' ); ?>><?php esc_html_e( 'AI', 'infy-ai-image-seo-optimizer' ); ?></option>
			</select>

			<?php submit_button( __( 'Filter', 'infy-ai-image-seo-optimizer' ), '', 'filter_action', false ); ?>
		</div>
		<?php
	}

	/**
	 * Prepare items for display.
	 */
	public function prepare_items() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'infy-ai-image-seo-optimizer' ) );
		}

		$per_page = $this->get_items_per_page( 'infyp_redirects_per_page', 20 );

		$this->_column_headers = $this->get_column_info();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- WP_List_Table sorting/filtering, read-only
		$orderby = isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : 'created_at';
		$order   = isset( $_REQUEST['order'] ) ? sanitize_key( wp_unslash( $_REQUEST['order'] ) ) : 'DESC';
		$status  = isset( $_REQUEST['redirect_status'] ) ? sanitize_key( wp_unslash( $_REQUEST['redirect_status'] ) ) : '';
		$source  = isset( $_REQUEST['redirect_source'] ) ? sanitize_key( wp_unslash( $_REQUEST['redirect_source'] ) ) : '';
		$search  = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$filters = [
			'status' => $status,
			'source' => $source,
			'search' => $search,
		];

		$total_items = $this->query->get_total_count( $filters );

		$this->set_pagination_args( [
			'total_items' => $total_items,
			'per_page'    => $per_page,
		] );

		$current_page = $this->get_pagenum();

		$this->items = $this->query->get_entries( [
			'orderby'  => $orderby,
			'order'    => $order,
			'per_page' => $per_page,
			'offset'   => ( $current_page - 1 ) * $per_page,
			'status'   => $status,
			'source'   => $source,
			'search'   => $search,
		] );
	}

	/**
	 * Checkbox column for bulk actions.
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	public function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="redirect_ids[]" value="%d" />', absint( $item['id'] ) );
	}

	/**
	 * Default column renderer.
	 *
	 * @param array  $item        Row data.
	 * @param string $column_name Column name.
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		return esc_html( $item[ $column_name ] ?? '' );
	}

	/**
	 * Image column — thumbnail.
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	public function column_image_id( $item ) {
		$image_html = wp_get_attachment_image(
			$item['image_id'],
			'thumbnail',
			false,
			[
				'class'    => 'infyp-redirect-thumb',
				'loading'  => 'lazy',
				'decoding' => 'async',
				'sizes'    => '40px',
			]
		);

		if ( ! empty( $image_html ) ) {
			return $image_html;
		}

		/* translators: %s: Image ID */
		return sprintf( esc_html__( 'ID: %s', 'infy-ai-image-seo-optimizer' ), esc_html( $item['image_id'] ) );
	}

	/**
	 * Old URL column — truncated with title tooltip.
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	public function column_old_url( $item ) {
		$url = $item['old_url'];
		$display = self::get_upload_relative_path( $url );

		$is_active    = $item['status'] === 'active';
		$toggle_action = $is_active ? 'disable' : 'enable';
		$toggle_label  = $is_active
			? __( 'Disable', 'infy-ai-image-seo-optimizer' )
			: __( 'Enable', 'infy-ai-image-seo-optimizer' );

		$actions = [
			'open' => sprintf(
				'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
				esc_url( $url ),
				esc_html__( 'View URL', 'infy-ai-image-seo-optimizer' )
			),
			'toggle' => sprintf(
				'<a href="%s">%s</a>',
				esc_url( wp_nonce_url(
					add_query_arg( [
						'page'            => 'ai-image-seo-optimizer-redirects',
						'action'          => $toggle_action,
						'redirect_ids[]'  => $item['id'],
					], admin_url( 'admin.php' ) ),
					'bulk-redirects'
				) ),
				esc_html( $toggle_label )
			),
			'delete' => sprintf(
				'<a href="%s" class="submitdelete" onclick="return confirm(\'%s\');">%s</a>',
				esc_url( wp_nonce_url(
					add_query_arg( [
						'page'            => 'ai-image-seo-optimizer-redirects',
						'action'          => 'delete',
						'redirect_ids[]'  => $item['id'],
					], admin_url( 'admin.php' ) ),
					'bulk-redirects'
				) ),
				esc_js( __( 'Are you sure you want to delete this redirect? External links pointing to the old URL will no longer be redirected.', 'infy-ai-image-seo-optimizer' ) ),
				esc_html__( 'Delete', 'infy-ai-image-seo-optimizer' )
			),
		];

		return sprintf(
			'<span class="infyp-redirect-url" title="%s">%s</span>%s',
			esc_attr( $url ),
			esc_html( $display ),
			$this->row_actions( $actions )
		);
	}

	/**
	 * New URL column — truncated with title tooltip.
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	public function column_new_url( $item ) {
		$url     = $item['new_url'];
		$display = self::get_upload_relative_path( $url );

		$actions = [
			'open' => sprintf(
				'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
				esc_url( $url ),
				esc_html__( 'View URL', 'infy-ai-image-seo-optimizer' )
			),
		];

		return sprintf(
			'<span class="infyp-redirect-url" title="%s">%s</span>%s',
			esc_attr( $url ),
			esc_html( $display ),
			$this->row_actions( $actions )
		);
	}

	/**
	 * Status column — colored badge.
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	public function column_status( $item ) {
		$status = $item['status'];
		$classes = [
			'active'   => 'infyp-badge infyp-badge-success',
			'disabled' => 'infyp-badge infyp-badge-muted',
			'expired'  => 'infyp-badge infyp-badge-warning',
		];
		$labels = [
			'active'   => __( 'Active', 'infy-ai-image-seo-optimizer' ),
			'disabled' => __( 'Disabled', 'infy-ai-image-seo-optimizer' ),
			'expired'  => __( 'Expired', 'infy-ai-image-seo-optimizer' ),
		];

		$class = $classes[ $status ] ?? 'infyp-badge';
		/* Fallback label for any future status values not yet in $labels. */
		$label = $labels[ $status ] ?? ucfirst( $status );

		return sprintf( '<span class="%s">%s</span>', esc_attr( $class ), esc_html( $label ) );
	}

	/**
	 * Status code column — shows the HTTP redirect code.
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	public function column_status_code( $item ) {
		if ( $item['status'] === 'active' ) {
			return '<span class="infyp-badge infyp-badge-info">301</span>';
		}
		return '<span aria-hidden="true">&mdash;</span>';
	}

	/**
	 * Hit count column.
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	public function column_hit_count( $item ) {
		return esc_html( number_format( (int) $item['hit_count'] ) );
	}

	/**
	 * Last hit column — relative time or dash.
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	public function column_last_hit_at( $item ) {
		if ( empty( $item['last_hit_at'] ) ) {
			return '<span aria-hidden="true">&mdash;</span>';
		}
		/* translators: %s: Human-readable time difference (e.g. "5 hours"). */
		return esc_html( sprintf( __( '%s ago', 'infy-ai-image-seo-optimizer' ), human_time_diff( strtotime( $item['last_hit_at'] ), current_datetime()->getTimestamp() ) ) );
	}

	/**
	 * Created column.
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	public function column_created_at( $item ) {
		return esc_html( gmdate( 'Y/m/d', strtotime( $item['created_at'] ) ) );
	}

	/**
	 * Source column — badge.
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	public function column_source( $item ) {
		$source = $item['source'];
		if ( $source === 'ai' ) {
			return '<span class="infyp-badge infyp-badge-info">AI</span>';
		}
		return '<span class="infyp-badge">' . esc_html__( 'Manual', 'infy-ai-image-seo-optimizer' ) . '</span>';
	}

	/**
	 * Page builder results column — per-adapter status icons.
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	public function column_pagebuilder_results( $item ) {
		if ( empty( $item['pagebuilder_results'] ) ) {
			return '<span aria-hidden="true">&mdash;</span>';
		}

		$results = json_decode( $item['pagebuilder_results'], true );
		if ( ! is_array( $results ) ) {
			return '<span aria-hidden="true">&mdash;</span>';
		}

		$html = '<div class="infyp-pb-results">';
		foreach ( $results as $adapter => $stats ) {
			$updated = (int) ( $stats['updated'] ?? 0 );
			$errors  = (int) ( $stats['errors'] ?? 0 );

			if ( $errors > 0 ) {
				$icon = '<span class="dashicons dashicons-warning infyp-pb-icon-warning"></span>';
			} elseif ( $updated > 0 ) {
				$icon = '<span class="dashicons dashicons-yes-alt infyp-pb-icon-success"></span>';
			} else {
				$icon = '<span class="dashicons dashicons-minus infyp-pb-icon-none"></span>';
			}

			$html .= sprintf(
				'<span class="infyp-pb-result" title="%s: %s">%s %s</span> ',
				esc_attr( ucfirst( $adapter ) ),
				/* translators: 1: number of posts updated, 2: number of URLs replaced */
				esc_attr( sprintf( __( '%1$d posts, %2$d URLs', 'infy-ai-image-seo-optimizer' ), $updated, (int) ( $stats['replaced'] ?? 0 ) ) ),
				$icon,
				esc_html( ucfirst( $adapter ) )
			);
		}
		$html .= '</div>';

		return $html;
	}

	/**
	 * Message when no items are found.
	 */
	public function no_items() {
		esc_html_e( 'No redirects found. Redirects are created automatically when image filenames are changed.', 'infy-ai-image-seo-optimizer' );
	}

	/**
	 * Extract the upload-relative path from a full URL.
	 *
	 * Turns "https://example.com/wp-content/uploads/2024/01/photo.jpg"
	 * into "/2024/01/photo.jpg". Falls back to basename if the uploads
	 * prefix isn't found.
	 *
	 * Caches the upload path in a static variable to avoid calling
	 * wp_get_upload_dir() on every cell render (called twice per row).
	 *
	 * @since 1.1.0
	 *
	 * @param string $url Full URL.
	 * @return string Display path with leading slash.
	 */
	private static function get_upload_relative_path( $url ) {
		static $upload_path = null;

		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! $path ) {
			return $url;
		}

		if ( $upload_path === null ) {
			$upload_dir  = wp_get_upload_dir();
			$upload_path = wp_parse_url( $upload_dir['baseurl'], PHP_URL_PATH );
			$upload_path = $upload_path ? trailingslashit( $upload_path ) : '';
		}

		if ( $upload_path ) {
			$pos = strpos( $path, $upload_path );
			if ( $pos !== false ) {
				return '/' . substr( $path, $pos + strlen( $upload_path ) );
			}
		}

		return '/' . basename( $path );
	}
}
