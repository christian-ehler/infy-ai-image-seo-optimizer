<?php
/**
 * Changelog List Table - AI Optimization Change History
 *
 * Extends WP_List_Table to display a sortable, paginated log of all changes
 * made by the AI optimizer (alt text, titles, filenames) and manual edits.
 * Shown on the Changelog admin submenu page.
 *
 * Columns:
 * - Date: Timestamp of the change (sortable, default DESC)
 * - AI Model: Which AI model made the change, or em-dash for manual edits
 * - Image: Thumbnail preview linked to the attachment
 * - Field: Which metadata field was changed (Alt Text, Title, Filename, etc.)
 * - Before/After: Old and new values for comparison
 * - Context: Links to posts where the change was applied (JSON-decoded)
 * - User: WordPress user who triggered the change
 *
 * Data Source:
 * - Custom table: {$wpdb->prefix}infyp_changelog
 * - Written by INFYP_Changelog::add_entry()
 *
 * @package   IPAIS\Tables
 * @since 1.0.0
 * @author    InfyPress
 * @license   GPL-2.0-or-later
 *
 * @see       IPAIS\Database\INFYP_Changelog For the data writer
 * @see       IPAIS\Admin\INFYP_Admin_Pages::create_changelog_page() For page registration
 */

namespace IPAIS\Tables;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use \WP_List_Table;

/**
 * Changelog List Table Class
 *
 * Renders the data grid for the changelog admin page. Each row represents
 * a single metadata change (alt text, title, filename, caption, description)
 * logged by the AI optimizer or manual inline edits.
 *
 * Sorting:
 * - timestamp (default, DESC): When the change was made
 * - ai_model: Which AI model was used
 *
 * Screen Option:
 * - infyp_changelog_entries_per_page (default: 20)
 *
 * @package   IPAIS\Tables
 * @since 1.0.0
 */
if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
}

class INFYP_Changelog_List_Table extends WP_List_Table {

    /**
     * Query helper for the changelog custom table.
     *
     * @var INFYP_Changelog_Query
     */
    private $query;

    /**
     * Constructor. Sets up the table with singular, plural, and AJAX properties.
     *
     * @since 1.0.0
     */
    public function __construct() {
        parent::__construct( [
            'singular' => __( 'Log Entry', 'infy-ai-image-seo-optimizer' ),
            'plural'   => __( 'Log Entries', 'infy-ai-image-seo-optimizer' ),
            'ajax'     => false
        ] );

        $this->query = new INFYP_Changelog_Query();
    }

    /**
     * Defines the columns that will be displayed in the table.
     *
     * @since 1.0.0
     * @return array An associative array of column slugs to titles.
     */
    public function get_columns() {
        return [
            'timestamp'     => __( 'Date', 'infy-ai-image-seo-optimizer' ),
            'ai_model'      => __( 'AI Model', 'infy-ai-image-seo-optimizer' ),
            'image_id'      => __( 'Image', 'infy-ai-image-seo-optimizer' ),
            'field_changed' => __( 'Field', 'infy-ai-image-seo-optimizer' ),
            'old_value'     => __( 'Before', 'infy-ai-image-seo-optimizer' ),
            'new_value'     => __( 'After', 'infy-ai-image-seo-optimizer' ),
            'context'       => __( 'Context', 'infy-ai-image-seo-optimizer' ),
            'user_id'       => __( 'User', 'infy-ai-image-seo-optimizer' ),
        ];
    }
	
    /**
     * Defines which columns are sortable.
     *
     * @since 1.0.0
     * @return array An associative array of sortable columns.
     */
    protected function get_sortable_columns() {
        return [
            'timestamp' => ['timestamp', true],
            'ai_model'  => ['ai_model', false],
        ];
    }

    /**
     * Override display to show pagination only at the bottom.
     *
     * @since 1.1.0
     */
    public function display() {
        $this->display_tablenav( 'top' );
        $this->screen->render_screen_reader_content( 'heading_list' );

        ?>
        <table class="wp-list-table <?php echo esc_attr( implode( ' ', $this->get_table_classes() ) ); ?>">
            <thead>
                <tr>
                    <?php $this->print_column_headers(); ?>
                </tr>
            </thead>
            <tbody id="the-list">
                <?php $this->display_rows_or_placeholder(); ?>
            </tbody>
            <tfoot>
                <tr>
                    <?php $this->print_column_headers( false ); ?>
                </tr>
            </tfoot>
        </table>
        <?php

        $this->display_tablenav( 'bottom' );
    }

    /**
     * Render the top/bottom table navigation.
     *
     * Only show pagination in the bottom nav.
     *
     * @since 1.1.0
     *
     * @param string $which 'top' or 'bottom'.
     */
    protected function display_tablenav( $which ) {
        if ( 'top' === $which ) {
            return;
        }
        ?>
        <div class="tablenav <?php echo esc_attr( $which ); ?>">
            <?php $this->pagination( $which ); ?>
            <br class="clear" />
        </div>
        <?php
    }

    /**
     * Prepares the list of items for display.
     *
     * This is the most important method, where data is retrieved from the database,
     * sorted, paginated, and assigned to the `$items` property.
     *
     * @since 1.0.0
     */
    public function prepare_items() {
        if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'infy-ai-image-seo-optimizer' ) );
        }

        $per_page = $this->get_items_per_page( 'infyp_changelog_entries_per_page', 20 );

        $this->_column_headers = $this->get_column_info();

        $total_items = $this->query->get_total_count();

        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page'    => $per_page,
        ]);

        $current_page = $this->get_pagenum();

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- WP_List_Table sorting, read-only
        $orderby = isset($_REQUEST['orderby']) ? sanitize_key(wp_unslash($_REQUEST['orderby'])) : 'timestamp';
        $order   = isset($_REQUEST['order']) ? sanitize_key(wp_unslash($_REQUEST['order'])) : 'DESC';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        $this->items = $this->query->get_entries([
            'orderby'  => $orderby,
            'order'    => $order,
            'per_page' => $per_page,
            'offset'   => ( $current_page - 1 ) * $per_page,
        ]);
    }
    
    /**
     * Renders the default content for a cell.
     *
     * @since 1.0.0
     * @param array  $item        The data for the current row.
     * @param string $column_name The name of the current column.
     * @return string The content to display in the cell.
     */
    public function column_default( $item, $column_name ) {
        return esc_html( $item[ $column_name ] );
    }
	
    /**
     * Renders the content for the 'context' column.
     *
     * This method formats the JSON data stored in the context field into a readable
     * list of links to the pages where a filename change was applied.
     *
     * @since 1.0.0
     * @param array $item The data for the current row.
     * @return string The formatted HTML for the context column.
     */
    public function column_context($item) {
        if (empty($item['context'])) {
            return '<span aria-hidden="true">—</span>';
        }

        if ($item['context'] === 'Media Library only') {
            return esc_html__( 'Media Library only', 'infy-ai-image-seo-optimizer' );
        }

        $context_data = json_decode($item['context'], true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($context_data) && isset($context_data['pages'])) {
            $html = '<ul>';
            foreach ($context_data['pages'] as $link_data) {
                if (isset($link_data['link'], $link_data['title'], $link_data['id'])) {
                    $html .= sprintf(
                        '<li><a href="%s" target="_blank">%s (ID: %d)</a></li>',
                        esc_url($link_data['link']),
                        esc_html($link_data['title']),
                        absint($link_data['id'])
                    );
                }
            }
            $html .= '</ul>';

            if (isset($context_data['more']) && $context_data['more'] > 0) {
                /* translators: %d: number of additional items */
                $html .= sprintf('<p>' . esc_html__( '...and %d more.', 'infy-ai-image-seo-optimizer' ) . '</p>', absint($context_data['more']));
            }

            return $html;
        }

        // Fallback for older or non-JSON context data
        $links = json_decode($item['context'], true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($links)) {
            $html = '<ul>';
            foreach ($links as $link_data) {
                $html .= sprintf(
                    '<li><a href="%s" target="_blank">%s (ID: %d)</a></li>',
                    esc_url($link_data['link']),
                    esc_html($link_data['title']),
                    absint($link_data['id'])
                );
            }
            $html .= '</ul>';
            return $html;
        }

        // Fallback for non-JSON or malformed data
        return esc_html($item['context']);
    }

    /**
     * Renders the 'ai_model' column.
     *
     * Shows the AI model name in italics, or an em-dash for manual (non-AI) changes.
     *
     * @since 1.0.0
     *
     * @param array $item The data for the current row.
     * @return string HTML for the AI model cell.
     */
    public function column_ai_model($item) {
        if (!empty($item['ai_model'])) {
            return '<em>' . esc_html($item['ai_model']) . '</em>';
        }
        return '<span aria-hidden="true">—</span>';
    }

    /**
     * Renders the 'image_id' column.
     *
     * Shows a 40x40 lazy-loaded thumbnail via wp_get_attachment_image().
     * Falls back to displaying the numeric ID if the attachment no longer exists.
     *
     * @since 1.0.0
     *
     * @param array $item The data for the current row.
     * @return string HTML for the image thumbnail or ID fallback.
     */
    public function column_image_id($item) {
        // Use wp_get_attachment_image() for proper thumbnail handling.
        // This ensures we load the 150x150 thumbnail, not the full-size original.
        // sizes="40px" tells browser to pick smallest appropriate srcset image.
        $image_html = wp_get_attachment_image(
            $item['image_id'],
            'thumbnail',
            false,
            array(
                'loading'  => 'lazy',
                'decoding' => 'async',
                'style'    => 'width: 40px; height: 40px; object-fit: cover;',
                'sizes'    => '40px',
            )
        );

        if ( ! empty( $image_html ) ) {
            return $image_html;
        }

        /* translators: %s: Image ID */
        return sprintf( esc_html__( 'ID: %s', 'infy-ai-image-seo-optimizer' ), esc_html( $item['image_id'] ) );
    }

    /**
     * Renders the 'user_id' column.
     *
     * Looks up the WordPress user by ID and displays their display_name.
     * Falls back to "Unknown User" if the user no longer exists.
     *
     * @since 1.0.0
     *
     * @param array $item The data for the current row.
     * @return string Escaped user display name or fallback text.
     */
    public function column_user_id($item) {
        $user = get_userdata($item['user_id']);
        return $user ? esc_html($user->display_name) : esc_html__( 'Unknown User', 'infy-ai-image-seo-optimizer' );
    }

    /**
     * Renders the 'timestamp' column.
     *
     * Formats the stored UTC timestamp into Y/m/d H:i:s display format.
     *
     * @since 1.0.0
     *
     * @param array $item The data for the current row.
     * @return string Escaped formatted date string.
     */
    public function column_timestamp($item) {
        return esc_html( gmdate( 'Y/m/d H:i:s', strtotime( $item['timestamp'] ) ) );
    }
}