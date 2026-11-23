<?php
/**
 * Table & Search AJAX Handlers
 *
 * Handles all table/search related AJAX operations extracted from INFYP_Ajax_Handlers.
 * Provides live search, paginated table data, cache warming, index building,
 * and post/page search with image counts.
 *
 * @package IPAIS
 * @since 1.0.0
 */

namespace IPAIS\Ajax;

use \IPAIS\Tables\INFYP_Image_List_Table;
use \IPAIS\Core\INFYP_Cache_Manager;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class INFYP_Table_Ajax
 *
 * Domain-specific AJAX handler for table and search operations.
 *
 * @since 1.0.0
 */
class INFYP_Table_Ajax {

    /**
     * Cache manager instance.
     *
     * @var INFYP_Cache_Manager
     */
    private $cache;

    /**
     * AJAX handler map.
     *
     * @var array<string, string>
     */
    private $handlers = [
        'infyp_live_search'             => 'ajax_live_search_handler',
        'infyp_get_table_data'          => 'ajax_get_table_data_handler',
        'infyp_warm_table_cache'        => 'ajax_warm_table_cache_handler',
        'infyp_build_search_indexes'    => 'ajax_build_search_indexes_handler',
        'infyp_search_posts_with_images' => 'ajax_search_posts_with_images_handler',
    ];

    /**
     * Constructor.
     */
    public function __construct() {
        $this->cache = INFYP_Cache_Manager::get_instance();
    }

    /**
     * Register AJAX handlers.
     *
     * @return void
     */
    public function register() {
        foreach ( $this->handlers as $action => $method ) {
            add_action( 'wp_ajax_' . $action, [ $this, $method ] );
        }
    }

    /**
     * AJAX handler for live image search
     * Returns matching images with thumbnails and metadata for dropdown display
     *
     * Uses FULLTEXT search index when available for fast results,
     * falls back to LIKE queries otherwise.
     */
    public function ajax_live_search_handler() {
        // Verify nonce
        if (!check_ajax_referer('infyp_ajax_nonce', 'nonce', false)) {
            wp_send_json_error([
                'message' => esc_html__('Security check failed', 'infy-ai-image-seo-optimizer')
            ]);
        }

        // Check user capabilities
        if (!current_user_can('upload_files')) {
            wp_send_json_error([
                'message' => esc_html__('You do not have permission to perform this action', 'infy-ai-image-seo-optimizer')
            ]);
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $search_term = isset($_POST['search']) ? trim(sanitize_text_field(wp_unslash($_POST['search']))) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $limit = isset($_POST['limit']) ? absint($_POST['limit']) : 5;

        if (strlen($search_term) < 2) {
            wp_send_json_success([
                'results' => [],
                'total' => 0
            ]);
        }

        global $wpdb;
        $search_table = $wpdb->prefix . 'infyp_search_index';

        // Check if FULLTEXT search index is available
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check
        $search_index_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $search_table ) );

        $mime_sql = infyp_get_mime_type_sql();

        if ( $search_index_exists && strlen( $search_term ) >= 3 ) {
            // FULLTEXT search path — fast
            $search_words = preg_split( '/\s+/', trim( $search_term ) );
            $fulltext_terms = [];
            foreach ( $search_words as $word ) {
                if ( strlen( $word ) >= 3 ) {
                    // Strip FULLTEXT boolean operators only — do NOT use esc_like() here
                    // because it escapes underscores with backslashes, which corrupts
                    // FULLTEXT queries (underscores are valid word chars in InnoDB).
                    $clean_word = preg_replace( '/[+\-><()~*"@]/', '', sanitize_text_field( $word ) );
                    if ( strlen( $clean_word ) >= 3 ) {
                        $fulltext_terms[] = '+' . $clean_word . '*';
                    }
                }
            }

            if ( ! empty( $fulltext_terms ) ) {
                $fulltext_query = implode( ' ', $fulltext_terms );

                // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $search_table from $wpdb->prefix + hardcoded suffix, $mime_sql from esc_sql()
                $results_raw = $wpdb->get_results( $wpdb->prepare(
                    "SELECT p.ID, p.post_title, si.filename
                     FROM {$wpdb->posts} p
                     INNER JOIN `{$search_table}` si ON p.ID = si.image_id
                     WHERE p.post_type = 'attachment'
                     AND p.post_mime_type IN ({$mime_sql})
                     AND p.post_status = 'inherit'
                     AND MATCH(si.combined_text) AGAINST(%s IN BOOLEAN MODE)
                     ORDER BY p.post_date DESC
                     LIMIT %d",
                    $fulltext_query,
                    $limit
                ) );
                // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

                // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $search_table from $wpdb->prefix + hardcoded suffix, $mime_sql from esc_sql()
                $total = $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*)
                     FROM {$wpdb->posts} p
                     INNER JOIN `{$search_table}` si ON p.ID = si.image_id
                     WHERE p.post_type = 'attachment'
                     AND p.post_mime_type IN ({$mime_sql})
                     AND p.post_status = 'inherit'
                     AND MATCH(si.combined_text) AGAINST(%s IN BOOLEAN MODE)",
                    $fulltext_query
                ) );
                // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            } else {
                $results_raw = [];
                $total = 0;
            }
        } else {
            // LIKE fallback — search in title, alt text, and filename
            $like = '%' . $wpdb->esc_like( $search_term ) . '%';

            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $mime_sql from esc_sql()
            $results_raw = $wpdb->get_results( $wpdb->prepare(
                "SELECT DISTINCT p.ID, p.post_title
                 FROM {$wpdb->posts} p
                 LEFT JOIN {$wpdb->postmeta} pm_alt ON (p.ID = pm_alt.post_id AND pm_alt.meta_key = '_wp_attachment_image_alt')
                 LEFT JOIN {$wpdb->postmeta} pm_file ON (p.ID = pm_file.post_id AND pm_file.meta_key = '_wp_attached_file')
                 WHERE p.post_type = 'attachment'
                 AND p.post_mime_type IN ({$mime_sql})
                 AND p.post_status = 'inherit'
                 AND (
                     p.post_title LIKE %s
                     OR pm_alt.meta_value LIKE %s
                     OR pm_file.meta_value LIKE %s
                 )
                 ORDER BY p.post_date DESC
                 LIMIT %d",
                $like,
                $like,
                $like,
                $limit
            ) );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $mime_sql from esc_sql()
            $total = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(DISTINCT p.ID)
                 FROM {$wpdb->posts} p
                 LEFT JOIN {$wpdb->postmeta} pm_alt ON (p.ID = pm_alt.post_id AND pm_alt.meta_key = '_wp_attachment_image_alt')
                 LEFT JOIN {$wpdb->postmeta} pm_file ON (p.ID = pm_file.post_id AND pm_file.meta_key = '_wp_attached_file')
                 WHERE p.post_type = 'attachment'
                 AND p.post_mime_type IN ({$mime_sql})
                 AND p.post_status = 'inherit'
                 AND (
                     p.post_title LIKE %s
                     OR pm_alt.meta_value LIKE %s
                     OR pm_file.meta_value LIKE %s
                 )",
                $like,
                $like,
                $like
            ) );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        }

        // Build results with batch-loaded metadata
        $results = [];
        if ( ! empty( $results_raw ) ) {
            $image_ids = wp_list_pluck( $results_raw, 'ID' );
            update_meta_cache( 'post', $image_ids );

            foreach ( $results_raw as $image ) {
                $id = $image->ID;
                $thumb = wp_get_attachment_image_src( $id, 'thumbnail' );
                $alt_text = get_post_meta( $id, '_wp_attachment_image_alt', true );
                $file_path = get_post_meta( $id, '_wp_attached_file', true );
                $filename = $file_path ? basename( $file_path ) : '';
                $percentage = intval( get_post_meta( $id, '_infyp_seo_score', true ) );

                $results[] = [
                    'id'        => $id,
                    'title'     => $image->post_title ?: $filename,
                    'filename'  => $filename,
                    'alt_text'  => $alt_text,
                    'thumbnail' => $thumb ? $thumb[0] : '',
                    'seo_score' => $percentage
                ];
            }
        }

        wp_send_json_success([
            'results'     => $results,
            'total'       => (int) $total,
            'search_term' => $search_term
        ]);
    }

    /**
     * AJAX handler for getting paginated table data
     *
     * This endpoint returns table data for AJAX-based pagination,
     * enabling fast initial page load with async data fetching.
     *
     * @since 1.0.0
     * @return void Sends JSON response and exits
     */
    public function ajax_get_table_data_handler() {
        // Verify nonce
        if (!check_ajax_referer('infyp_ajax_nonce', 'nonce', false)) {
            wp_send_json_error([
                'message' => esc_html__('Security check failed', 'infy-ai-image-seo-optimizer')
            ]);
        }

        // Check user capabilities
        if (!current_user_can('upload_files')) {
            wp_send_json_error([
                'message' => esc_html__('You do not have permission to perform this action', 'infy-ai-image-seo-optimizer')
            ]);
        }

        // Get parameters with defaults
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified above
        $page = isset($_POST['page']) ? absint($_POST['page']) : 1;
        $per_page = isset($_POST['per_page']) ? absint($_POST['per_page']) : 20;
        $search = isset($_POST['search']) ? trim(sanitize_text_field(wp_unslash($_POST['search']))) : '';
        $orderby = isset($_POST['orderby']) ? sanitize_key($_POST['orderby']) : 'seo_score';
        $order = isset($_POST['order']) ? sanitize_key($_POST['order']) : 'asc';
        $post_type_filter = isset($_POST['post_type_filter']) ? sanitize_text_field(wp_unslash($_POST['post_type_filter'])) : 'all';
        $parent_post = isset($_POST['parent_post']) ? absint($_POST['parent_post']) : 0;

        // Cursor params for keyset pagination (deep pages)
        $cursor = null;
        if ( ! empty( $_POST['cursor_id'] ) ) {
            $cursor = [
                'id'        => absint( $_POST['cursor_id'] ),
                'value'     => isset( $_POST['cursor_value'] ) ? sanitize_text_field( wp_unslash( $_POST['cursor_value'] ) ) : '',
                'direction' => isset( $_POST['cursor_direction'] ) && $_POST['cursor_direction'] === 'prev' ? 'prev' : 'next',
            ];
        }
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        // Set the filter in $_REQUEST for the table class to use
        $_REQUEST['post_type_filter'] = $post_type_filter;
        $_REQUEST['parent_post'] = $parent_post;
        $_REQUEST['s'] = $search;
        $_REQUEST['orderby'] = $orderby;
        $_REQUEST['order'] = $order;

        // Ensure per_page is reasonable
        $per_page = min(max($per_page, 5), 100);

        // Create table instance and get data
        $table = new INFYP_Image_List_Table();

        // Get images first to extract IDs for batch metadata loading
        $result = $table->get_images($per_page, $page, $search, $orderby, $order, $cursor, null, $parent_post);

        $images = $result['items'] ?? [];
        $total = $result['total'] ?? 0;
        $total_pages = ceil($total / $per_page);

        // Extract image IDs for batch metadata loading (eliminates N+1 queries)
        $image_ids = array_map(function($image) {
            return $image->ID;
        }, $images);

        // Calculate SEO scores for any visible images that don't have one yet.
        // Must happen BEFORE batch_load_metadata() caches the (empty) meta values.
        if ( ! empty( $image_ids ) ) {
            global $wpdb;
            $placeholders = implode( ',', array_fill( 0, count( $image_ids ), '%d' ) );
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Dynamic placeholder count; table names from $wpdb
            $uncached_ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT p.ID FROM {$wpdb->posts} p
                     LEFT JOIN {$wpdb->postmeta} pm ON (p.ID = pm.post_id AND pm.meta_key = '_infyp_seo_score')
                     WHERE p.ID IN ({$placeholders})
                     AND pm.meta_value IS NULL",
                    array_map( 'intval', $image_ids )
                )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
            foreach ( $uncached_ids as $uncached_id ) {
                \IPAIS\Core\INFYP_SEO_Score_Calculator::calculate_score( (int) $uncached_id );
            }
        }

        // Set up column headers AND preload metadata for all visible images
        // This reduces ~160 queries to ~1 query for 20 items
        $table->prepare_items_for_ajax($image_ids);

        // Build table rows HTML (now uses cached metadata)
        $rows_html = '';
        if (!empty($images)) {
            ob_start();
            foreach ($images as $image) {
                $table->single_row($image);
            }
            $rows_html = ob_get_clean();
        }

        // Build pagination HTML
        $pagination_html = $this->build_pagination_html($page, $total_pages, $total, $per_page);

        $response_data = [
            'rows_html'       => $rows_html,
            'pagination_html' => $pagination_html,
            'total'           => $total,
            'total_pages'     => $total_pages,
            'current_page'    => $page,
            'per_page'        => $per_page,
            'has_items'       => ! empty( $images ),
        ];

        // Include cursor data for keyset pagination on deep pages
        $cursor_data = $result['cursor'] ?? null;
        if ( $cursor_data !== null ) {
            $response_data['cursor'] = $cursor_data;
        }

        wp_send_json_success( $response_data );
    }

    /**
     * Build pagination HTML for AJAX response
     *
     * @param int $current_page Current page number
     * @param int $total_pages Total number of pages
     * @param int $total_items Total number of items
     * @param int $per_page Items per page
     * @return string Pagination HTML
     */
    private function build_pagination_html($current_page, $total_pages, $total_items, $per_page) {
        $items_text = esc_html( sprintf(
            /* translators: %s: Number of items */
            _n( '%s item', '%s items', $total_items, 'infy-ai-image-seo-optimizer' ),
            number_format_i18n( $total_items )
        ) );

        if ($total_pages <= 1) {
            return '<span class="displaying-num">' . $items_text . '</span>';
        }

        $output = '<span class="displaying-num">' . $items_text . '</span>';

        $output .= '<span class="pagination-links">';

        // First page link
        $output .= '<button type="button" class="first-page button infyp-ajax-page" data-page="1"' .
                   ($current_page <= 1 ? ' disabled' : '') . '>';
        $output .= '<span class="screen-reader-text">' . esc_html__('First page', 'infy-ai-image-seo-optimizer') . '</span>';
        $output .= '<span aria-hidden="true">&laquo;</span></button>';

        // Previous page link
        $output .= '<button type="button" class="prev-page button infyp-ajax-page" data-page="' . esc_attr( max(1, $current_page - 1) ) . '"' .
                   ($current_page <= 1 ? ' disabled' : '') . '>';
        $output .= '<span class="screen-reader-text">' . esc_html__('Previous page', 'infy-ai-image-seo-optimizer') . '</span>';
        $output .= '<span aria-hidden="true">&lsaquo;</span></button>';

        // Current page input
        $output .= '<span class="paging-input">';
        $output .= '<label for="current-page-selector" class="screen-reader-text">' . esc_html__('Current Page', 'infy-ai-image-seo-optimizer') . '</label>';
        $output .= '<input class="current-page infyp-page-input" id="current-page-selector" type="text" name="paged" value="' . esc_attr( $current_page ) . '" size="1" aria-describedby="table-paging">';
        $output .= '<span class="tablenav-paging-text"> ' . esc_html__('of', 'infy-ai-image-seo-optimizer') . ' <span class="total-pages">' . esc_html( $total_pages ) . '</span></span>';
        $output .= '</span>';

        // Next page link
        $output .= '<button type="button" class="next-page button infyp-ajax-page" data-page="' . esc_attr( min($total_pages, $current_page + 1) ) . '"' .
                   ($current_page >= $total_pages ? ' disabled' : '') . '>';
        $output .= '<span class="screen-reader-text">' . esc_html__('Next page', 'infy-ai-image-seo-optimizer') . '</span>';
        $output .= '<span aria-hidden="true">&rsaquo;</span></button>';

        // Last page link
        $output .= '<button type="button" class="last-page button infyp-ajax-page" data-page="' . esc_attr( $total_pages ) . '"' .
                   ($current_page >= $total_pages ? ' disabled' : '') . '>';
        $output .= '<span class="screen-reader-text">' . esc_html__('Last page', 'infy-ai-image-seo-optimizer') . '</span>';
        $output .= '<span aria-hidden="true">&raquo;</span></button>';

        $output .= '</span>';

        return $output;
    }

    /**
     * AJAX handler: Warm table data cache
     *
     * Pre-populates the cache with common filter combinations to eliminate
     * slow first-load times. Called automatically on first page visit.
     *
     * @since 1.0.0
     * @return void Sends JSON response
     */
    public function ajax_warm_table_cache_handler() {
        // Verify nonce
        if ( ! check_ajax_referer( 'infyp_ajax_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Security check failed.', 'infy-ai-image-seo-optimizer' ) ) );
        }

        // Check user capability
        if ( ! current_user_can( 'upload_files' ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Insufficient permissions.', 'infy-ai-image-seo-optimizer' ) ) );
        }

        // Perform cache warming
        $warmed = $this->cache->warm_table_data_cache();

        if ( $warmed ) {
            wp_send_json_success( array(
                'message' => esc_html__( 'Cache warmed successfully.', 'infy-ai-image-seo-optimizer' ),
                'warmed'  => true,
            ) );
        } else {
            wp_send_json_success( array(
                'message' => esc_html__( 'Cache already warm.', 'infy-ai-image-seo-optimizer' ),
                'warmed'  => false,
            ) );
        }
    }

    /**
     * AJAX handler for building search and filter indexes
     *
     * Creates and populates the lookup tables for fast filtering and searching.
     * This should be called on first load or when rebuilding indexes is needed.
     *
     * @since 1.0.0
     * @return void Sends JSON response
     */
    public function ajax_build_search_indexes_handler() {
        // Verify nonce
        if ( ! check_ajax_referer( 'infyp_ajax_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Security check failed.', 'infy-ai-image-seo-optimizer' ) ) );
        }

        // Check user capability
        if ( ! current_user_can( 'upload_files' ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Insufficient permissions.', 'infy-ai-image-seo-optimizer' ) ) );
        }

        // Check if we should skip (already built recently)
        $last_build = get_option( 'infyp_lookup_table_populated', 0 );
        $force = isset( $_POST['force'] ) && $_POST['force'] === 'true';

        // Skip if built in the last hour, unless forced
        if ( ! $force && $last_build && ( time() - $last_build ) < HOUR_IN_SECONDS ) {
            // A content scan interrupted by a timeout or a deactivation would
            // otherwise never resume — make sure it is still queued.
            if ( class_exists( '\IPAIS\Database\INFYP_Database_Optimizer' )
                && ! get_option( \IPAIS\Database\INFYP_Database_Optimizer::OPTION_CONTENT_SCAN_DONE )
            ) {
                \IPAIS\Database\INFYP_Database_Optimizer::schedule_content_scan();
            }

            wp_send_json_success( array(
                'message' => esc_html__( 'Indexes already up to date.', 'infy-ai-image-seo-optimizer' ),
                'skipped' => true,
                'lookup'  => array( 'exists' => true ),
                'search'  => array( 'exists' => true ),
            ) );
            return;
        }

        // Build the indexes
        if ( ! class_exists( '\IPAIS\Database\INFYP_Database_Optimizer' ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Database optimizer not available.', 'infy-ai-image-seo-optimizer' ) ) );
        }

        $optimizer = '\IPAIS\Database\INFYP_Database_Optimizer';

        // Create tables if they don't exist
        $optimizer::create_image_post_type_lookup_table();
        $optimizer::create_search_index_table();

        // Populate the tables. Content-embedded images are indexed by the
        // background scan that populate_image_post_type_lookup() queues —
        // reading every post's content is far too slow to do inline here.
        $lookup_stats = $optimizer::populate_image_post_type_lookup();
        $search_count = $optimizer::populate_search_index();

        // Run the first content batch now so the current page already improves.
        $content_scan = $optimizer::run_content_scan_batch();

        // Get final status
        $status = $optimizer::get_index_status();

        wp_send_json_success( array(
            'message'      => esc_html__( 'Search indexes built successfully.', 'infy-ai-image-seo-optimizer' ),
            'lookup_stats' => $lookup_stats,
            'search_count' => $search_count,
            'content_scan' => $content_scan,
            'status'       => $status,
        ) );
    }

    /**
     * AJAX handler: Search posts/pages that have images
     *
     * Returns posts with image counts for the page/post filter dropdown.
     * Uses wp_infyp_image_usage table when available, falls back to post_parent + _thumbnail_id.
     *
     * @since 1.0.0
     * @return void Sends JSON response and exits
     */
    public function ajax_search_posts_with_images_handler() {
        // Verify nonce
        if ( ! check_ajax_referer( 'infyp_ajax_nonce', 'nonce', false ) ) {
            wp_send_json_error( [
                'message' => esc_html__( 'Security check failed', 'infy-ai-image-seo-optimizer' ),
            ] );
        }

        // Check user capabilities
        if ( ! current_user_can( 'upload_files' ) ) {
            wp_send_json_error( [
                'message' => esc_html__( 'You do not have permission to perform this action', 'infy-ai-image-seo-optimizer' ),
            ] );
        }

        global $wpdb;

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified above
        $search = isset( $_POST['search'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['search'] ) ) ) : '';
        $post_type_filter = isset( $_POST['post_type_filter'] ) ? sanitize_text_field( wp_unslash( $_POST['post_type_filter'] ) ) : 'all';
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        // Get allowed post types from the central registry
        $allowed_post_types = \IPAIS\Tables\INFYP_Image_Query_Builder::get_allowed_post_types();
        $allowed_types = array_values( $allowed_post_types );

        // Narrow by post type filter if set
        if ( $post_type_filter !== 'all' && $post_type_filter !== 'media' ) {
            $filter_types = array_map( 'trim', preg_split( '/[,|+]/', $post_type_filter ) );
            $filter_types = array_filter( $filter_types, function( $t ) {
                return $t !== 'all' && $t !== 'media';
            } );
            if ( ! empty( $filter_types ) ) {
                $allowed_types = array_intersect( $allowed_types, $filter_types );
            }
        }

        if ( empty( $allowed_types ) ) {
            wp_send_json_success( [ 'results' => [] ] );
        }

        // Build type placeholders
        $type_placeholders = implode( ',', array_fill( 0, count( $allowed_types ), '%s' ) );

        // Check if image_usage table exists AND has data
        $usage_table = esc_sql( $wpdb->prefix . 'infyp_image_usage' );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check
        $usage_exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $usage_table ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix, row count check.
        $usage_has_data = $usage_exists && (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$usage_table}` LIMIT 1" ) > 0;

        if ( $usage_has_data ) {
            // Use the optimized image_usage lookup table
            $sql = "SELECT p.ID, p.post_title, p.post_type,
                           COUNT(DISTINCT iu.image_id) as image_count
                    FROM {$wpdb->posts} p
                    INNER JOIN `{$usage_table}` iu ON iu.post_id = p.ID
                    WHERE p.post_status IN ('publish', 'draft', 'private')
                      AND p.post_type IN ({$type_placeholders})";

            $params = $allowed_types;

            if ( $search !== '' ) {
                $sql .= ' AND p.post_title LIKE %s';
                $params[] = '%' . $wpdb->esc_like( $search ) . '%';
            }

            $sql .= ' GROUP BY p.ID ORDER BY image_count DESC, p.post_title ASC LIMIT 20';
        } else {
            // Fallback: use post_parent + _thumbnail_id
            $sql = "SELECT p.ID, p.post_title, p.post_type,
                           (
                               (SELECT COUNT(*) FROM {$wpdb->posts} a
                                WHERE a.post_type = 'attachment' AND a.post_status = 'inherit'
                                AND a.post_parent = p.ID)
                               +
                               (SELECT COUNT(*) FROM {$wpdb->postmeta} pm
                                WHERE pm.post_id = p.ID AND pm.meta_key = '_thumbnail_id')
                           ) as image_count
                    FROM {$wpdb->posts} p
                    WHERE p.post_status IN ('publish', 'draft', 'private')
                      AND p.post_type IN ({$type_placeholders})";

            $params = $allowed_types;

            if ( $search !== '' ) {
                $sql .= ' AND p.post_title LIKE %s';
                $params[] = '%' . $wpdb->esc_like( $search ) . '%';
            }

            $sql .= ' HAVING image_count > 0 ORDER BY image_count DESC, p.post_title ASC LIMIT 20';
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Dynamic query built safely with prepare().
        $results = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) );

        $items = [];
        foreach ( $results as $row ) {
            $items[] = [
                'id'          => (int) $row->ID,
                'title'       => $row->post_title ?: __( '(no title)', 'infy-ai-image-seo-optimizer' ),
                'type'        => $row->post_type,
                'image_count' => (int) $row->image_count,
            ];
        }

        wp_send_json_success( [ 'results' => $items ] );
    }
}
