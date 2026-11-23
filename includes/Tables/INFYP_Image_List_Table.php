<?php
/**
 * Image list table for the admin media overview page.
 *
 * Extends WP_List_Table to display images with SEO metadata, filtering,
 * sorting, and bulk action support. Delegates query building, column
 * rendering, and filter UI to extracted helper classes.
 *
 * @package    IPAIS
 * @subpackage Tables
 * @since 1.0.0
 * @author     InfyPress
 * @license    GPL-2.0-or-later
 */

namespace IPAIS\Tables;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use \WP_List_Table;
use \WP_Query;
use \IPAIS\Core\INFYP_Image_Usage;
use \IPAIS\Core\INFYP_Cache_Manager;
use \IPAIS\Core\INFYP_Security_Manager;
use \IPAIS\Core\INFYP_Error_Handler;
use \IPAIS\Core\INFYP_SEO_Score_Calculator;
use \IPAIS\Core\INFYP_Field_Validator;

/**
 * Class INFYP_Image_List_Table - OPTIMIZED VERSION
 *
 * Enhanced with security, caching, and performance optimizations.
 *
 * Delegates rendering/query/filter work to extracted helper classes:
 * - INFYP_Image_Query_Builder  (SQL queries, filtering, counts)
 * - INFYP_Image_Column_Renderer (column HTML output)
 * - INFYP_Image_List_Filters    (filter/sort UI controls)
 *
 * Default Behavior:
 * - Shows images from Media Library (unattached)
 * - Shows images attached to or used in Posts and Pages
 * - All public post types are included via get_post_types()
 *
 * Available Hooks:
 * - infyp_free_allowed_post_types: Modify allowed post types
 * - infyp_allowed_post_types: Filter which public post types are shown
 * - infyp_image_list_query_args: Modify WP_Query arguments
 * - infyp_should_include_image: Final filter for image inclusion
 */
if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
}

class INFYP_Image_List_Table extends WP_List_Table {

    /** @var INFYP_Cache_Manager */
    private $cache;

    /** @var int */
    private $total_filtered_count = 0;

    /** @var INFYP_Security_Manager */
    private $security;

    /** @var INFYP_Error_Handler */
    private $error_handler;

    /** @var array Batch-loaded metadata cache for visible images */
    private $metadata_cache = [];

    /** @var string */
    private $current_search_term = '';

    /** @var string */
    private $current_search_like = '';

    /** @var string */
    private $current_exact_search = '';

    /** @var INFYP_Image_Query_Builder */
    private $query_builder;

    /** @var INFYP_Image_Column_Renderer */
    private $column_renderer;

    /** @var INFYP_Image_List_Filters */
    private $filters;

    /**
     * Constructor.
     *
     * Initialises the list table, manager singletons, and extracted helper
     * classes for query building, column rendering, and filter UI.
     *
     * @since 1.0.0
     */
    public function __construct() {
        parent::__construct( [
            'singular' => 'image',
            'plural'   => 'images',
            'ajax'     => false
        ] );

        $this->cache           = INFYP_Cache_Manager::get_instance();
        $this->security        = INFYP_Security_Manager::get_instance();
        $this->error_handler   = INFYP_Error_Handler::get_instance();
        $this->query_builder   = new INFYP_Image_Query_Builder( $this->cache );
        $this->column_renderer = new INFYP_Image_Column_Renderer();
        $this->filters         = new INFYP_Image_List_Filters( $this->cache );
    }

    /* =========================================================================
       METADATA CACHING - Performance Optimization
       Eliminates N+1 queries by batch-loading metadata for all visible rows
       ========================================================================= */

    /**
     * Get cached metadata for an image
     *
     * Returns metadata from the batch-loaded cache. Falls back to individual
     * get_post_meta() calls if cache miss (should only happen in edge cases).
     *
     * @since 1.0.0
     * @param int    $image_id The attachment ID
     * @param string $meta_key The meta key to retrieve
     * @param mixed  $default  Default value if not found
     * @return mixed The meta value or default
     */
    private function get_cached_meta( $image_id, $meta_key, $default = '' ) {
        // Check if metadata is cached
        if ( isset( $this->metadata_cache[ $image_id ][ $meta_key ] ) ) {
            $value = $this->metadata_cache[ $image_id ][ $meta_key ];
            return ( $value !== '' && $value !== false ) ? $value : $default;
        }

        // Fallback to individual query (should rarely happen if batch_load_metadata was called)
        $value = get_post_meta( $image_id, $meta_key, true );

        // Store in cache for potential reuse within same request
        if ( ! isset( $this->metadata_cache[ $image_id ] ) ) {
            $this->metadata_cache[ $image_id ] = [];
        }
        $this->metadata_cache[ $image_id ][ $meta_key ] = $value;

        return ( $value !== '' && $value !== false ) ? $value : $default;
    }

    /**
     * Batch load metadata for multiple images in a single query
     *
     * Uses WordPress's update_meta_cache() which performs a single SQL query
     * to load all metadata for the given post IDs into WordPress's object cache.
     * Then populates our local cache with the specific meta keys we need.
     *
     * Meta keys loaded:
     * - _wp_attachment_image_alt (alt text)
     * - _infyp_keywords (SEO keywords array)
     * - _infyp_keywords_manual (manual keyword flag)
     * - _infyp_has_been_optimized (optimization status)
     * - _wp_attached_file (for filename)
     *
     * @since 1.0.0
     * @param array $image_ids Array of attachment IDs
     * @return void
     */
    private function batch_load_metadata( array $image_ids ) {
        if ( empty( $image_ids ) ) {
            return;
        }

        // Clear existing cache for fresh load
        $this->metadata_cache = [];

        // WordPress's update_meta_cache loads ALL meta for given IDs in one query
        // This primes the object cache so subsequent get_post_meta() calls are free
        update_meta_cache( 'post', $image_ids );

        // Meta keys we need for table rendering
        $meta_keys = [
            '_wp_attachment_image_alt',
            '_infyp_keywords',
            '_infyp_keywords_manual',
            '_infyp_has_been_optimized',
            '_wp_attached_file',
            '_infyp_seo_score',
        ];

        // Populate our local cache from the now-primed WordPress object cache
        foreach ( $image_ids as $image_id ) {
            $this->metadata_cache[ $image_id ] = [];

            foreach ( $meta_keys as $meta_key ) {
                // get_post_meta now hits the primed object cache (no DB query)
                $value = get_post_meta( $image_id, $meta_key, true );
                $this->metadata_cache[ $image_id ][ $meta_key ] = $value;
            }
        }

        // Sync metadata cache to column renderer so it can render without N+1 queries
        $this->column_renderer->set_metadata_cache( $this->metadata_cache );
    }

    /* =========================================================================
       END METADATA CACHING
       ========================================================================= */

    /**
     * Override display to set rendering flag and handle skeleton loading
     *
     * Performance optimization: On initial page load (when prepare_items() is skipped),
     * we show the skeleton loader and hide the table. JavaScript will then load data
     * via AJAX and show the real table.
     */
    public function display() {
        // Set flag to indicate we're rendering the table
        if (!defined('INFYP_RENDERING_TABLE')) {
            define('INFYP_RENDERING_TABLE', true);
        }

        // Ensure column headers are set (required for parent::display() to render table)
        if ( empty( $this->_column_headers ) ) {
            $this->_column_headers = $this->get_column_info();
        }

        // Ensure pagination args are set so tablenav-pages container is rendered
        // This allows AJAX to update pagination after data loads
        if ( empty( $this->_pagination_args ) ) {
            $per_page = $this->get_items_per_page( 'images_per_page', 20 );
            $this->set_pagination_args( [
                'total_items' => 0,
                'per_page'    => $per_page,
                'total_pages' => 0
            ] );
        }

        // Check if we have items (determines initial visibility)
        // When prepare_items() is skipped, $this->items will be empty
        $has_items = ! empty( $this->items );

        // Skeleton: visible when no items (initial load), hidden when items exist
        $skeleton_display = $has_items ? 'none' : 'block';

        // Table wrapper: hidden when no items (initial load), visible when items exist
        $wrapper_display = $has_items ? 'block' : 'none';

        // Show skeleton loading placeholder with loading message
        echo '<div class="infyp-table-skeleton" style="display: ' . esc_attr( $skeleton_display ) . ';">';

        // Loading message header
        echo '<div class="infyp-skeleton-loading-message">';
        echo '<span class="spinner is-active"></span>';
        echo '<span class="infyp-loading-text">' . esc_html__( 'Loading images...', 'infy-ai-image-seo-optimizer' ) . '</span>';
        echo '</div>';

        // Skeleton header
        echo '<div class="infyp-skeleton-header">';
        echo '<div class="infyp-skeleton-cell infyp-skeleton-checkbox"></div>';
        echo '<div class="infyp-skeleton-cell infyp-skeleton-image"></div>';
        echo '<div class="infyp-skeleton-cell infyp-skeleton-title"></div>';
        echo '<div class="infyp-skeleton-cell infyp-skeleton-field"></div>';
        echo '<div class="infyp-skeleton-cell infyp-skeleton-field"></div>';
        echo '<div class="infyp-skeleton-cell infyp-skeleton-actions"></div>';
        echo '</div>';

        // Skeleton rows
        for ($i = 0; $i < 5; $i++) {
            echo '<div class="infyp-skeleton-row">';
            echo '<div class="infyp-skeleton-cell infyp-skeleton-checkbox"></div>';
            echo '<div class="infyp-skeleton-cell infyp-skeleton-image"></div>';
            echo '<div class="infyp-skeleton-cell infyp-skeleton-title"></div>';
            echo '<div class="infyp-skeleton-cell infyp-skeleton-field"></div>';
            echo '<div class="infyp-skeleton-cell infyp-skeleton-field"></div>';
            echo '<div class="infyp-skeleton-cell infyp-skeleton-actions"></div>';
            echo '</div>';
        }
        echo '</div>';

        // Table wrapper with conditional visibility
        echo '<div class="infyp-table-wrapper" style="display: ' . esc_attr( $wrapper_display ) . ';">';

        // Call parent display method
        parent::display();

        echo '</div>';
    }

    /**
     * Override display_tablenav to ensure pagination container always exists
     *
     * WP_List_Table's pagination() method returns early when total_items <= 0,
     * which means no .tablenav-pages container is created on initial load.
     * We need this container to exist so AJAX can populate it with pagination.
     *
     * @param string $which Either 'top' or 'bottom'
     */
    protected function display_tablenav( $which ) {
        ?>
        <div class="tablenav <?php echo esc_attr( $which ); ?>">
            <?php if ( $this->has_items() ) : ?>
                <div class="alignleft actions bulkactions">
                    <?php $this->bulk_actions( $which ); ?>
                </div>
            <?php endif; ?>
            <?php
            $this->extra_tablenav( $which );
            // Always output the pagination container (even if empty)
            // This ensures AJAX can update it after data loads
            ?>
            <div class="tablenav-pages">
                <?php $this->pagination( $which ); ?>
            </div>
            <br class="clear" />
        </div>
        <?php
    }

    /**
     * Output a single table row with keywords data attribute.
     *
     * @since 1.0.0
     *
     * @param object $item The current WP_Post attachment object.
     */
    public function single_row( $item ) {
        // Set a flag to indicate we're outputting table rows
        if (!defined('INFYP_RENDERING_TABLE')) {
            define('INFYP_RENDERING_TABLE', true);
        }

        // Get keywords from batch-loaded cache (avoids N+1 query)
        $keywords = $this->get_cached_meta( $item->ID, '_infyp_keywords', [] );
        $keywords_json = ! empty( $keywords ) && is_array( $keywords ) ?
            wp_json_encode( $keywords ) : '[]';

        echo '<tr data-id="' . esc_attr( $item->ID ) . '" data-keywords=\'' . esc_attr( $keywords_json ) . '\'>';
        $this->single_row_columns( $item );
        echo '</tr>';
    }

    /**
     * Define the columns for the image list table.
     *
     * @since 1.0.0
     *
     * @return array Associative array of column slug => label.
     */
    public function get_columns() {
        $columns = [];

        // Allow extensions to add checkbox column via filter
        $columns = apply_filters( 'infyp_image_table_columns_before', $columns );

        $columns['id']              = __( 'ID', 'infy-ai-image-seo-optimizer' );
        $columns['thumbnail']       = __( 'Image', 'infy-ai-image-seo-optimizer' );
        $columns['keywords']        = __( 'Keywords', 'infy-ai-image-seo-optimizer' );
        $columns['filename']        = __( 'Filename', 'infy-ai-image-seo-optimizer' );
        $columns['alt_text']        = __( 'Alt Text', 'infy-ai-image-seo-optimizer' );
        $columns['title']           = __( 'Title', 'infy-ai-image-seo-optimizer' );

        // Hook for extensions to add columns after title
        $columns = apply_filters( 'infyp_table_columns_after_title', $columns );

        $columns['usage_info']      = __( 'Usage', 'infy-ai-image-seo-optimizer' );
        $columns['actions']         = __( 'Actions', 'infy-ai-image-seo-optimizer' );
        $columns['seo_score']       = __( 'Score', 'infy-ai-image-seo-optimizer' );

        return $columns;
    }

    /**
     * Define which columns are sortable.
     *
     * @since 1.0.0
     *
     * @return array Associative array of sortable column definitions.
     */
    protected function get_sortable_columns() {
        return [
            'id'             => ['ID', false],
            'title'          => ['title', false],
            'last_optimized' => ['post_modified', true],
        ];
    }

    /**
     * Define which column should be primary
     */
    protected function get_primary_column_name() {
        return 'filename';
    }

    /**
     * Add extra controls to the table navigation.
     */
    protected function extra_tablenav( $which ) {
        // Post type filter is now rendered in main-page.php above search
        // Keep this empty to avoid duplicate filters
    }

    /* =========================================================================
       FILTER / SORT UI — delegated to INFYP_Image_List_Filters
       ========================================================================= */

    /**
     * Render the post type filter UI standalone (called from main-page.php)
     */
    public function render_post_type_filter_standalone() {
        $this->filters->render_post_type_filter_standalone();
    }

    /**
     * Render the page/post filter dropdown (called from main-page.php)
     *
     * Outputs the HTML shell for the AJAX-powered page/post filter.
     * The dropdown results are populated dynamically via JS.
     *
     * @since 1.0.0
     */
    public function render_page_filter() {
        $this->filters->render_page_filter();
    }

    /**
     * Render the SEO score sort toggle button (called from main-page.php)
     *
     * @since 1.0.0
     */
    public function render_seo_sort_control() {
        $this->filters->render_seo_sort_control();
    }

    /**
     * Render the upload date sort toggle button (called from main-page.php)
     *
     * Three states: desc (newest first) -> asc (oldest first) -> inactive (back to default SEO sort)
     *
     * @since 1.0.0
     */
    public function render_date_sort_control() {
        $this->filters->render_date_sort_control();
    }

    /**
     * Render the post type filter UI
     *
     * @deprecated Now delegated through render_post_type_filter_standalone().
     */
    private function render_post_type_filter() {
        $this->filters->render_post_type_filter_standalone();
    }

    /* =========================================================================
       PREPARE ITEMS
       ========================================================================= */

    /**
     * Minimal setup for AJAX row rendering with optional metadata preloading
     *
     * Sets up column headers without running the expensive database query.
     * Optionally preloads metadata for given image IDs to avoid N+1 queries.
     *
     * Performance: When image_ids provided, loads all metadata in a single query
     * instead of 8+ queries per row.
     *
     * @since 1.0.0
     * @param array $image_ids Optional. Array of image IDs to preload metadata for.
     * @return void
     */
    public function prepare_items_for_ajax( $image_ids = [] ) {
        $this->_column_headers = $this->get_column_info();

        // Batch load metadata if image IDs provided (eliminates N+1 queries)
        if ( ! empty( $image_ids ) ) {
            $this->batch_load_metadata( $image_ids );
        }
    }

    /**
     * Prepares the list of items for display - OPTIMIZED VERSION
     */
    public function prepare_items() {
        // Additional security check for admin area
        if ( ! is_admin() || ! current_user_can( 'upload_files' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'infy-ai-image-seo-optimizer' ) );
        }

        // Sanitize and validate input
        $sanitized_search_term = '';
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- WP_List_Table handles nonce verification
        if ( ! empty( $_REQUEST['s'] ) ) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitization happens immediately after unslashing
            $sanitized_search_term = sanitize_text_field( trim( wp_unslash($_REQUEST['s']) ) );
            $_REQUEST['s'] = $sanitized_search_term; // Overwrite for display
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        $this->_column_headers = $this->get_column_info();
        $per_page = $this->get_items_per_page( 'images_per_page', 20 );
        $current_page = $this->get_pagenum();

        // Validate and sanitize orderby/order
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Standard WP_List_Table sorting parameters
        $orderby = isset($_REQUEST['orderby']) ? sanitize_key(wp_unslash($_REQUEST['orderby'])) : 'seo_score';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Standard WP_List_Table sorting parameters
        $order = isset($_REQUEST['order']) ? sanitize_key(wp_unslash($_REQUEST['order'])) : 'asc';

        // Ensure order is only asc or desc
        $order = in_array(strtolower($order), ['asc', 'desc'], true) ? $order : 'desc';

        if ($orderby === 'last_optimized') {
            $orderby = 'modified';
        }

        // Get post type filter - supports multiple delimiters
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Standard WP_List_Table filtering
        $post_type_filter = isset($_REQUEST['post_type_filter']) ?
            sanitize_text_field(wp_unslash($_REQUEST['post_type_filter'])) :
            'all';
        $parent_post_filter = isset($_REQUEST['parent_post']) ? absint($_REQUEST['parent_post']) : 0;
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        // Get items with caching (include filter in cache key)
        $cache_key = 'image_list_' . md5(serialize([
            'search' => $sanitized_search_term,
            'page' => $current_page,
            'per_page' => $per_page,
            'orderby' => $orderby,
            'order' => $order,
            'filter' => $post_type_filter,
            'parent_post' => $parent_post_filter
        ]));

        $result = $this->cache->remember(
            $cache_key,
            function() use ($per_page, $current_page, $sanitized_search_term, $orderby, $order, $parent_post_filter) {
                return $this->get_images($per_page, $current_page, $sanitized_search_term, $orderby, $order, null, null, $parent_post_filter);
            },
            INFYP_Cache_Manager::CACHE_GROUP_QUERIES,
            300 // 5 minutes cache
        );

        // Extract items and total from the result
        if (is_array($result) && isset($result['items']) && isset($result['total'])) {
            $this->items = $result['items'];
            $total_items = $result['total'];
        } else {
            // Fallback for backwards compatibility
            $this->items = is_array($result) ? $result : [];
            $total_items = count($this->items);
        }

        // Batch load metadata for all visible items (eliminates N+1 queries)
        // This reduces ~160 queries to ~1 query for 20 items
        if ( ! empty( $this->items ) ) {
            $image_ids = array_map( function( $item ) {
                return $item->ID;
            }, $this->items );
            $this->batch_load_metadata( $image_ids );
        }

        // Ensure we have at least some total for pagination to show
        if ($total_items == 0 && !empty($this->items)) {
            $total_items = count($this->items);
        }

        $this->set_pagination_args( [
            'total_items' => $total_items,
            'per_page' => $per_page,
            'total_pages' => ceil($total_items / $per_page)
        ]);
    }

    /* =========================================================================
       QUERY METHODS — delegated to INFYP_Image_Query_Builder
       ========================================================================= */

    /**
     * Get WordPress core post types for image filtering.
     *
     * Delegates to INFYP_Image_Query_Builder::get_allowed_post_types().
     *
     * @since 1.0.0
     *
     * @return array Associative array of slug => slug.
     */
    private static function get_allowed_post_types() {
        return INFYP_Image_Query_Builder::get_allowed_post_types();
    }

    /**
     * Retrieve image posts from the database.
     *
     * Delegates to the query builder for optimised database-level pagination,
     * JOIN-based filtering, and cached totals.
     *
     * @since 1.0.0
     *
     * @param int         $per_page         Number of images per page. Default 20.
     * @param int         $page_number      Current page number. Default 1.
     * @param string      $search_term      Search term. Default empty.
     * @param string      $orderby          Column to order by. Default 'seo_score'.
     * @param string      $order            Sort direction ('asc' or 'desc'). Default 'asc'.
     * @param array|null  $cursor           Optional cursor for keyset pagination.
     * @param string|null $post_type_filter Optional post-type filter string.
     * @param int         $parent_post      Optional parent post ID. Default 0.
     * @return array {
     *     @type array      $items  Array of WP_Post objects.
     *     @type int        $total  Total matching image count.
     *     @type array|null $cursor Cursor data for next/prev navigation.
     * }
     */
    public function get_images( $per_page = 20, $page_number = 1, $search_term = '', $orderby = 'seo_score', $order = 'asc', $cursor = null, $post_type_filter = null, $parent_post = 0 ) {
        return $this->query_builder->get_images( $per_page, $page_number, $search_term, $orderby, $order, $cursor, $post_type_filter, $parent_post );
    }

    /**
     * Build optimized SQL query parts for image filtering
     *
     * This method constructs SQL that filters at the database level instead of PHP,
     * dramatically improving performance for large media libraries.
     *
     * @param array $filter_types Array of post type filters
     * @param string $search_term Search term
     * @param int $parent_post Optional parent post ID
     * @return array Array with 'from' and 'where' SQL parts
     */
    private function build_optimized_query_parts($filter_types, $search_term, $parent_post = 0) {
        return $this->query_builder->build_optimized_query_parts( $filter_types, $search_term, $parent_post );
    }

    /**
     * Ensure SEO scores are cached for images that don't have them.
     *
     * When sorting by score, images need cached scores for accurate SQL sorting.
     * This method processes a small batch synchronously (for immediate page accuracy)
     * and schedules a background cron job to process the rest (avoids 500-700 query burst).
     *
     * @since 1.0.0
     * @param array $sql_parts SQL parts for finding images without scores.
     */
    private function ensure_scores_cached( $sql_parts ) {
        $this->query_builder->ensure_scores_cached( $sql_parts );
    }

    /**
     * Get total count for current view with caching optimization
     *
     * Uses cached counts when possible, falls back to COUNT query for filtered views.
     * This implements the "no_found_rows" optimization from the research.
     *
     * @since 1.0.0
     * @param array  $filter_types Current filter types
     * @param string $search_term  Search term if any
     * @param array  $sql_parts    SQL parts for fallback query
     * @param int    $parent_post  Parent post ID
     * @return int Total count
     */
    private function get_total_for_current_view( $filter_types, $search_term, $sql_parts, $parent_post = 0 ) {
        return $this->query_builder->get_total_for_current_view( $filter_types, $search_term, $sql_parts, $parent_post );
    }

    /**
     * Parse post type filter string into array of filter types
     *
     * @param string $post_type_filter The filter string (e.g., 'all', 'post+page', 'media')
     * @return array Array of filter types
     */
    private function parse_post_type_filter($post_type_filter) {
        return $this->query_builder->parse_post_type_filter( $post_type_filter );
    }

    /* =========================================================================
       COLUMN RENDERING — delegated to INFYP_Image_Column_Renderer
       ========================================================================= */

    /**
     * Checkbox column for bulk selection
     *
     * @param object $item The current item
     * @return string
     */
    public function column_cb( $item ) {
        return $this->column_renderer->column_cb( $item );
    }

    /**
     * Render the ID column.
     *
     * @since 1.0.0
     *
     * @param object $item The current WP_Post attachment object.
     * @return string Column HTML.
     */
    public function column_id( $item ) {
        return $this->column_renderer->column_id( $item );
    }

    /**
     * Render the thumbnail column.
     *
     * @since 1.0.0
     *
     * @param object $item The current WP_Post attachment object.
     * @return string Column HTML.
     */
    public function column_thumbnail( $item ) {
        return $this->column_renderer->column_thumbnail( $item );
    }

    /**
     * Render the keywords column.
     *
     * @since 1.0.0
     *
     * @param object $item The current WP_Post attachment object.
     * @return string Column HTML.
     */
    public function column_keywords($item) {
        return $this->column_renderer->column_keywords( $item );
    }

    /**
     * Render the filename column.
     *
     * @since 1.0.0
     *
     * @param object $item The current WP_Post attachment object.
     * @return string Column HTML.
     */
    public function column_filename($item) {
        return $this->column_renderer->column_filename( $item );
    }

    /**
     * Render the title column.
     *
     * @since 1.0.0
     *
     * @param object $item The current WP_Post attachment object.
     * @return string Column HTML.
     */
    public function column_title($item) {
        return $this->column_renderer->column_title( $item );
    }

    /**
     * Render the alt text column.
     *
     * @since 1.0.0
     *
     * @param object $item The current WP_Post attachment object.
     * @return string Column HTML.
     */
    public function column_alt_text($item) {
        return $this->column_renderer->column_alt_text( $item );
    }

    /**
     * Render the usage info column.
     *
     * @since 1.0.0
     *
     * @param object $item The current WP_Post attachment object.
     * @return string Column HTML.
     */
    public function column_usage_info($item) {
        return $this->column_renderer->column_usage_info( $item );
    }

    /**
     * Render the SEO score column.
     *
     * @since 1.0.0
     *
     * @param object $item The current WP_Post attachment object.
     * @return string Column HTML.
     */
    public function column_seo_score($item) {
        return $this->column_renderer->column_seo_score( $item );
    }

    /**
     * Render the actions column.
     *
     * @since 1.0.0
     *
     * @param object $item The current WP_Post attachment object.
     * @return string Column HTML.
     */
    public function column_actions($item) {
        return $this->column_renderer->column_actions( $item );
    }

    /**
     * Render a default column value.
     *
     * @since 1.0.0
     *
     * @param object $item        The current WP_Post attachment object.
     * @param string $column_name The column slug.
     * @return string Column HTML.
     */
    public function column_default( $item, $column_name ) {
        return $this->column_renderer->column_default( $item, $column_name );
    }

}
