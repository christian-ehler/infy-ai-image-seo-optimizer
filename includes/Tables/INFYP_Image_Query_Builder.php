<?php
/**
 * Image query builder for the image list table.
 *
 * Builds optimized SQL queries with JOIN-based filtering, FULLTEXT search,
 * weighted relevance scoring, cursor pagination, and SEO score caching
 * for the admin image list table.
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

use \IPAIS\Core\INFYP_Cache_Manager;
use \IPAIS\Core\INFYP_SEO_Score_Calculator;

/**
 * Class INFYP_Image_Query_Builder
 *
 * Extracted from INFYP_Image_List_Table — contains all query-building and
 * data-fetching logic for the image list table.
 *
 * Responsible for:
 * - Building optimized SQL queries with JOIN logic and cursor pagination
 * - Post-type filtering (lookup table or subquery fallback)
 * - FULLTEXT and LIKE search support with weighted relevance scoring
 * - SEO score caching/pre-calculation
 * - Total count retrieval with cache-first pattern
 *
 * @since 1.0.0
 */
class INFYP_Image_Query_Builder {

	/**
	 * Cache manager instance
	 * @var INFYP_Cache_Manager
	 */
	private $cache;

	/**
	 * Current search term for relevance scoring
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private $current_search_term = '';

	/**
	 * Current search LIKE pattern for relevance scoring
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private $current_search_like = '';

	/**
	 * Current exact search pattern for relevance scoring
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private $current_exact_search = '';

	/**
	 * Constructor
	 *
	 * @param INFYP_Cache_Manager|null $cache Optional cache manager instance.
	 */
	public function __construct( $cache = null ) {
		$this->cache = $cache ?? INFYP_Cache_Manager::get_instance();
	}

	/**
	 * Get allowed post types for image filtering.
	 *
	 * Returns all public WordPress post types (excluding 'attachment' which is
	 * handled separately as the 'media' filter). This includes WordPress core
	 * types (post, page) and any custom post types registered by themes or plugins.
	 *
	 * The 'media' key is a special UI filter for unattached images — not a real
	 * post type — and is always included.
	 *
	 * @since 1.0.0
	 * @return array Associative array of slug => slug.
	 */
	public static function get_allowed_post_types() {
		// Get all public post types registered in WordPress
		$public_types = get_post_types( [ 'public' => true ], 'names' );

		// Remove 'attachment' — handled separately as the 'media' UI filter
		unset( $public_types['attachment'] );

		// Add 'media' as a special UI filter for unattached images
		$types = [ 'media' => 'media' ];

		foreach ( $public_types as $slug ) {
			$types[ $slug ] = $slug;
		}

		/**
		 * Filter the allowed post types for image filtering.
		 *
		 * Developers can modify which post types appear in the image list
		 * filter. By default, all public post types are included.
		 *
		 * @since 1.0.0
		 * @param array $types Associative array of slug => slug.
		 */
		return apply_filters( 'infyp_allowed_post_types', $types );
	}

	/**
	 * Retrieves the image posts from the database - OPTIMIZED VERSION
	 *
	 * PERFORMANCE OPTIMIZATION:
	 * - Uses database-level pagination (LIMIT/OFFSET) instead of loading all images
	 * - Filters at database level where possible using SQL JOINs
	 * - Does NOT call find_usage() for each image - usage is loaded on-demand
	 * - Uses cached totals instead of COUNT query (no_found_rows optimization)
	 * - For sites with 10,000+ images, this reduces load time from timeout to < 3 seconds
	 *
	 * By default, shows images from media library (unattached) or attached to any public post type
	 * Developers can customize visible post types via hooks
	 *
	 * @since 1.0.0
	 * @since 1.0.6 Added the $options parameter.
	 *
	 * @param int         $per_page         Images per page.
	 * @param int         $page_number      Page number (1-based).
	 * @param string      $search_term      Search term.
	 * @param string      $orderby          Sort field.
	 * @param string      $order            Sort direction ('asc'|'desc').
	 * @param array|null  $cursor           Cursor data: ['id' => int, 'value' => string, 'direction' => 'next'|'prev'].
	 * @param string|null $post_type_filter Explicit post type filter. Null falls back to $_REQUEST (admin list table only).
	 * @param int         $parent_post      Restrict to images attached to this post.
	 * @param array       $options          {
	 *     Optional behavior flags.
	 *
	 *     @type bool $skip_score_backfill Skip the synchronous SEO-score backfill and its
	 *                                     cron scheduling. Read-only callers (REST, CLI)
	 *                                     must not trigger writes. Default false.
	 *     @type bool $force_cursor        Use cursor pagination whenever a valid cursor is
	 *                                     provided, regardless of page number. Cursor still
	 *                                     requires an empty search term and a date/ID sort.
	 *                                     Default false.
	 *     @type int  $author              Restrict results to attachments uploaded by this
	 *                                     user ID (REST author scoping for callers without
	 *                                     edit_others_posts). Default 0 (no restriction).
	 * }
	 * @return array {
	 *     @type \WP_Post[] $items  Result posts.
	 *     @type int        $total  Total count for the view.
	 *     @type array|null $cursor Next/prev cursor data when the sort supports cursors.
	 * }
	 */
	public function get_images( $per_page = 20, $page_number = 1, $search_term = '', $orderby = 'seo_score', $order = 'asc', $cursor = null, $post_type_filter = null, $parent_post = 0, $options = [] ) {
		global $wpdb;

		// Get the current post type filter - supports multiple delimiters
		// Accept explicit parameter (e.g., from cache warming) or fall back to $_REQUEST
		if ( $post_type_filter === null ) {
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Standard WP_List_Table filtering
			$post_type_filter = isset($_REQUEST['post_type_filter']) ?
				sanitize_text_field(wp_unslash($_REQUEST['post_type_filter'])) :
				'all';
			// phpcs:enable WordPress.Security.NonceVerification.Recommended
		}

		// Validate filter against allowed post types
		$filter_types = $this->parse_post_type_filter($post_type_filter);

		// Calculate offset for pagination
		$offset = ($page_number - 1) * $per_page;

		// Validate orderby to prevent SQL injection
		$allowed_orderby = ['date', 'title', 'ID', 'modified', 'post_date', 'post_title', 'post_modified', 'seo_score'];
		if (!in_array($orderby, $allowed_orderby, true)) {
			$orderby = 'date';
		}

		// Map orderby to actual column names
		$orderby_map = [
			'date' => 'p.post_date',
			'post_date' => 'p.post_date',
			'title' => 'p.post_title',
			'post_title' => 'p.post_title',
			'ID' => 'p.ID',
			'modified' => 'p.post_modified',
			'post_modified' => 'p.post_modified',
			'seo_score' => 'sort_score', // Uses alias from SELECT clause
		];
		$orderby_column = $orderby_map[$orderby] ?? 'p.post_date';

		// Flag for SEO score sorting (needs special JOIN)
		$sort_by_score = ($orderby === 'seo_score');

		// Validate order
		$order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

		// Build the optimized SQL query
		$sql_parts = $this->build_optimized_query_parts($filter_types, $search_term, $parent_post);

		// Author scoping (REST callers without edit_others_posts see only their
		// own uploads). Applied to the WHERE so results and totals both respect it.
		$author_scoped = ! empty( $options['author'] );
		if ( $author_scoped ) {
			$sql_parts['where'] .= $wpdb->prepare( ' AND p.post_author = %d', (int) $options['author'] );
		}

		// Ensure SEO scores exist for all visible images. Without this, images
		// uploaded before the score-on-upload fix (or via REST/CLI) would show 0%
		// until the user interacts with them. Runs one lightweight query and
		// calculates at most 10 scores inline; schedules a cron for the rest.
		// Read-only callers (REST listings, CLI) opt out: a GET must never write
		// meta or schedule cron. The existing infyp_batch_cache_scores cron
		// backfills those scores instead.
		if ( empty( $options['skip_score_backfill'] ) ) {
			$this->ensure_scores_cached($sql_parts);
		}

		// Add JOIN for SEO score sorting
		$from_clause = $sql_parts['from'];
		$select_clause = "p.*";
		$needs_group_by = $sql_parts['needs_group_by'];
		$order_by_clause = " ORDER BY {$orderby_column} {$order}";

		if ($sort_by_score) {
			$from_clause .= " LEFT JOIN {$wpdb->postmeta} pm_score ON (p.ID = pm_score.post_id AND pm_score.meta_key = '_infyp_seo_score')";
			// Include score in SELECT for reliable sorting with GROUP BY
			$select_clause = "p.*, COALESCE(CAST(pm_score.meta_value AS SIGNED), 0) as sort_score";
			// Postmeta JOIN can theoretically produce duplicates
			$needs_group_by = true;
		}

		// Add weighted relevance scoring when searching
		// This ensures exact filename matches appear first, followed by partial matches
		if (!empty($search_term) && !empty($this->current_search_like)) {
			$search_table = $wpdb->prefix . 'infyp_search_index';

			// Check if search index table is joined (for v2 schema with separate columns)
			if (strpos($from_clause, $search_table) !== false) {
				// Weighted relevance scoring using the new schema:
				// - Exact filename match: 10000 (highest priority)
				// - Filename contains search term: 1000
				// - Title contains search term: 300
				// - Alt text contains search term: 200
				// - Caption/description contains: 100
				$relevance_sql = $wpdb->prepare(
					"(
						CASE WHEN LOWER(si.filename) = LOWER(%s) THEN 10000 ELSE 0 END +
						CASE WHEN LOWER(si.filename) LIKE LOWER(%s) THEN 1000 ELSE 0 END +
						CASE WHEN LOWER(si.title) LIKE LOWER(%s) THEN 300 ELSE 0 END +
						CASE WHEN LOWER(si.alt_text) LIKE LOWER(%s) THEN 200 ELSE 0 END +
						CASE WHEN LOWER(p.post_excerpt) LIKE LOWER(%s) OR LOWER(p.post_content) LIKE LOWER(%s) THEN 100 ELSE 0 END
					) as relevance_score",
					$this->current_exact_search,
					$this->current_search_like,
					$this->current_search_like,
					$this->current_search_like,
					$this->current_search_like,
					$this->current_search_like
				);
				$select_clause .= ", " . $relevance_sql;

				// Order by relevance first, then by the original order criteria
				$order_by_clause = " ORDER BY relevance_score DESC, {$orderby_column} {$order}";
			} else {
				// Fallback: LIKE-based relevance scoring when search index not available
				$relevance_sql = $wpdb->prepare(
					"(
						CASE WHEN LOWER(SUBSTRING_INDEX(pm_file.meta_value, '/', -1)) = LOWER(CONCAT(%s, '.', SUBSTRING_INDEX(pm_file.meta_value, '.', -1))) THEN 10000 ELSE 0 END +
						CASE WHEN pm_file.meta_value LIKE %s THEN 1000 ELSE 0 END +
						CASE WHEN p.post_title LIKE %s THEN 300 ELSE 0 END +
						CASE WHEN pm_alt.meta_value LIKE %s THEN 200 ELSE 0 END +
						CASE WHEN p.post_excerpt LIKE %s OR p.post_content LIKE %s THEN 100 ELSE 0 END
					) as relevance_score",
					$this->current_exact_search,
					$this->current_search_like,
					$this->current_search_like,
					$this->current_search_like,
					$this->current_search_like,
					$this->current_search_like
				);
				$select_clause .= ", " . $relevance_sql;

				// Order by relevance first, then by the original order criteria
				$order_by_clause = " ORDER BY relevance_score DESC, {$orderby_column} {$order}";
			}
		}

		// PERFORMANCE: Use cached total count instead of COUNT query when possible
		// This is the "no_found_rows" optimization - skip expensive COUNT for pagination
		// Cached totals are library-wide, so an author-scoped view must COUNT directly.
		if ( $author_scoped ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Parts built with proper escaping above; per-author totals are not cacheable library-wide.
			$total_count = (int) $wpdb->get_var( 'SELECT COUNT(DISTINCT p.ID) ' . $sql_parts['from'] . $sql_parts['where'] );
		} else {
			$total_count = $this->get_total_for_current_view($filter_types, $search_term, $sql_parts, $parent_post);
		}

		// Only add GROUP BY when JOINs can produce duplicate rows (lookup table, postmeta JOINs)
		$group_by_clause = $needs_group_by ? " GROUP BY p.ID" : "";

		// Build cursor WHERE clause for deep page optimization
		// Keyset pagination: use WHERE instead of OFFSET for constant-time deep page access
		$cursor_where = "";
		$use_cursor = false;

		// Cursor engages automatically past page 50 (deep-page optimization), or on
		// any page when the caller explicitly requests cursor mode (REST/CLI iteration).
		$cursor_requested = ( $page_number > 50 ) || ! empty( $options['force_cursor'] );

		if ( $cursor !== null && ! empty( $cursor['id'] ) && $cursor_requested && empty( $search_term ) ) {
			$cursor_id = absint( $cursor['id'] );
			$cursor_value = $cursor['value'] ?? '';
			$cursor_direction = ( $cursor['direction'] ?? 'next' ) === 'prev' ? 'prev' : 'next';

			// Build cursor condition based on sort column and direction
			// For DESC order + next: values LESS than cursor (older/lower)
			// For ASC order + next: values GREATER than cursor (newer/higher)
			$is_forward = ( $cursor_direction === 'next' );
			$is_desc = ( $order === 'DESC' );
			$cursor_op = ( $is_forward === $is_desc ) ? '<' : '>';

			if ( $orderby_column === 'p.post_date' || $orderby_column === 'p.post_modified' ) {
				// Composite cursor for date columns: (date, ID) to handle ties
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $orderby_column from whitelisted $orderby_map, $cursor_op is '<' or '>' from ternary
				$cursor_where = $wpdb->prepare(
					" AND ({$orderby_column} {$cursor_op} %s OR ({$orderby_column} = %s AND p.ID {$cursor_op} %d))",
					$cursor_value,
					$cursor_value,
					$cursor_id
				);
				// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$use_cursor = true;
			} elseif ( $orderby_column === 'p.ID' ) {
				// Simple cursor for ID sort
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $cursor_op is '<' or '>' from ternary
				$cursor_where = $wpdb->prepare(
					" AND p.ID {$cursor_op} %d",
					$cursor_id
				);
				// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$use_cursor = true;
			}
			// For title and score sorts, fall back to OFFSET (composite cursors are unreliable for text/computed columns)
		}

		// Build the final query
		if ( $use_cursor ) {
			// Cursor-based: no OFFSET needed, constant-time regardless of page depth
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Query parts are built with proper escaping
			$results_sql = "SELECT {$select_clause} " . $from_clause . $sql_parts['where'] .
						   $cursor_where .
						   $group_by_clause .
						   $order_by_clause .
						   $wpdb->prepare( " LIMIT %d", $per_page );
		} else {
			// OFFSET-based: used for first 50 pages, random jumps, and text/score sorts
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Query parts are built with proper escaping
			$results_sql = "SELECT {$select_clause} " . $from_clause . $sql_parts['where'] .
						   $group_by_clause .
						   $order_by_clause .
						   $wpdb->prepare( " LIMIT %d OFFSET %d", $per_page, $offset );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is dynamically built with escaped values, list table display doesn't need caching
		$results = $wpdb->get_results($results_sql);

		// Convert to WP_Post objects for compatibility
		$images = [];
		if (!empty($results)) {
			foreach ($results as $row) {
				$images[] = new \WP_Post($row);
			}
		}

		// Build cursor data for the response (enables cursor pagination on next request)
		$cursor_data = null;
		if ( ! empty( $images ) ) {
			$last = end( $images );
			$first = reset( $images );

			// Map orderby column to the post property name
			$value_map = [
				'p.post_date'     => 'post_date',
				'p.post_modified' => 'post_modified',
				'p.ID'            => 'ID',
			];
			$value_prop = $value_map[ $orderby_column ] ?? null;

			if ( $value_prop !== null ) {
				$cursor_data = [
					'next' => [
						'id'    => $last->ID,
						'value' => $last->$value_prop,
					],
					'prev' => [
						'id'    => $first->ID,
						'value' => $first->$value_prop,
					],
				];
			}
		}

		// Return items, total count, and cursor data
		return [
			'items'  => $images,
			'total'  => $total_count,
			'cursor' => $cursor_data,
		];
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
	public function ensure_scores_cached( $sql_parts ) {
		global $wpdb;

		// Find images without cached SEO scores or with stale zero scores.
		// A score of 0 is never legitimate (uniqueness alone awards 10 points),
		// so any stored 0 indicates a stale or incomplete calculation.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Query parts are built with proper escaping
		$uncached_sql = "SELECT p.ID " . $sql_parts['from'] .
						" LEFT JOIN {$wpdb->postmeta} pm_score_check ON (p.ID = pm_score_check.post_id AND pm_score_check.meta_key = '_infyp_seo_score')" .
						$sql_parts['where'] .
						" AND (pm_score_check.meta_value IS NULL OR pm_score_check.meta_value = '0')" .
						" GROUP BY p.ID" .
						" LIMIT 10";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL built internally from build_optimized_query_parts()
		$uncached_ids = $wpdb->get_col( $uncached_sql );

		if ( empty( $uncached_ids ) ) {
			return;
		}

		// Process small batch synchronously (keeps current page accurate)
		foreach ( $uncached_ids as $image_id ) {
			\IPAIS\Core\INFYP_SEO_Score_Calculator::calculate_score( (int) $image_id );
		}

		// If we hit the limit, there are likely more uncached scores — schedule background job
		if ( count( $uncached_ids ) >= 10 && ! wp_next_scheduled( 'infyp_batch_cache_scores' ) ) {
			wp_schedule_single_event( time() + 5, 'infyp_batch_cache_scores' );
		}
	}

	/**
	 * Get the next batch of image IDs that have never been AI-optimized.
	 *
	 * Keyset ("seek") pagination on the primary key: cheap at any library size,
	 * resumable by passing the last returned ID back as $after_id, and
	 * self-terminating for optimization jobs because processing an image writes
	 * the very meta this query filters on (_infyp_has_been_optimized).
	 *
	 * Deliberately queries wp_posts directly rather than joining the
	 * infyp_image_post_types lookup table — a stale lookup table must never
	 * hide images from a whole-library job.
	 *
	 * @since 1.0.6
	 *
	 * @param int $after_id Return only IDs greater than this. Pass 0 to start
	 *                      from the beginning, then the last ID of each batch.
	 * @param int $limit    Maximum IDs to return. Clamped to 1-1000.
	 * @return int[] Ascending attachment IDs, at most $limit of them.
	 */
	public function get_unoptimized_image_ids( $after_id = 0, $limit = 100 ) {
		global $wpdb;

		$after_id = max( 0, (int) $after_id );
		$limit    = min( 1000, max( 1, (int) $limit ) );

		$sql = $wpdb->prepare(
			"SELECT p.ID
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} pm
			     ON pm.post_id = p.ID AND pm.meta_key = '_infyp_has_been_optimized'
			 WHERE p.post_type = 'attachment'
			   AND p.post_mime_type LIKE %s
			   AND p.post_status != 'trash'
			   AND ( pm.meta_value IS NULL OR pm.meta_value != '1' )
			   AND p.ID > %d
			 ORDER BY p.ID ASC
			 LIMIT %d",
			$wpdb->esc_like( 'image/' ) . '%',
			$after_id,
			$limit
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; keyset batch reads must not be cached (results change as the job writes the filtered meta).
		return array_map( 'intval', $wpdb->get_col( $sql ) );
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
	public function get_total_for_current_view( $filter_types, $search_term, $sql_parts, $parent_post = 0 ) {
		global $wpdb;

		// When filtering by parent post (or search), we must COUNT from the actual filtered query
		// because cached totals/filter counts don't account for the parent_post WHERE clause
		if ( ! empty( $search_term ) || $parent_post > 0 ) {
			$allowed_types = self::get_allowed_post_types();
			$cache_key = 'filtered_count_' . md5( $search_term . implode( ',', $filter_types ) . '_t' . count( $allowed_types ) . '_pp' . $parent_post );
			return (int) $this->cache->remember(
				$cache_key,
				function () use ( $wpdb, $sql_parts ) {
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Query parts are built with proper escaping
					$count_sql = "SELECT COUNT(DISTINCT p.ID) " . $sql_parts['from'] . $sql_parts['where'];
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is dynamically built with escaped values
					return (int) $wpdb->get_var( $count_sql );
				},
				INFYP_Cache_Manager::CACHE_GROUP_QUERIES,
				60 // 60-second TTL — short enough to stay accurate, long enough to avoid repeated COUNT queries
			);
		}

		// For 'all' filter without search or parent_post, use cached total count
		if ( in_array( 'all', $filter_types, true ) ) {
			return $this->cache->get_cached_total_count();
		}

		// For specific filters, use cached filter counts
		$filter_counts = $this->cache->get_cached_filter_counts();

		// Map filter types to count keys
		// Note: For combined filters like 'post+page', we need a COUNT query
		if ( count( $filter_types ) === 1 ) {
			$filter = $filter_types[0];
			switch ( $filter ) {
				case 'missing_alt':
					return (int) $filter_counts['missing_alt'];
				case 'optimized':
					return (int) $filter_counts['optimized'];
				case 'not_optimized':
					return (int) $filter_counts['not_optimized'];
			}
		}

		// For post-type filters (post, page, media, combined), cache with short TTL
		$allowed_types = self::get_allowed_post_types();
		$cache_key = 'filter_count_' . md5( implode( ',', $filter_types ) . '_t' . count( $allowed_types ) );
		return (int) $this->cache->remember(
			$cache_key,
			function () use ( $wpdb, $sql_parts ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Query parts are built with proper escaping
				$count_sql = "SELECT COUNT(DISTINCT p.ID) " . $sql_parts['from'] . $sql_parts['where'];
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is dynamically built with escaped values
				return (int) $wpdb->get_var( $count_sql );
			},
			INFYP_Cache_Manager::CACHE_GROUP_QUERIES,
			60
		);
	}

	/**
	 * Parse post type filter string into array of filter types
	 *
	 * @param string $post_type_filter The filter string (e.g., 'all', 'post+page', 'media')
	 * @return array Array of filter types
	 */
	public function parse_post_type_filter($post_type_filter) {
		if ($post_type_filter === 'all') {
			return ['all'];
		}

		// Parse the filter - support both plus and comma delimiters
		if (strpos($post_type_filter, '+') !== false) {
			$requested_types = array_map('sanitize_key', explode('+', $post_type_filter));
		} else if (strpos($post_type_filter, ',') !== false) {
			$requested_types = array_map('sanitize_key', explode(',', $post_type_filter));
		} else {
			$requested_types = [sanitize_key($post_type_filter)];
		}

		// Validate against allowed post types (media is always valid)
		$allowed = self::get_allowed_post_types();
		$valid_types = ['media', 'all']; // Always allowed
		$valid_types = array_merge($valid_types, array_values($allowed));
		// Allow extensions to add extra valid filter types (e.g. product_cat)
		$valid_types = apply_filters( 'infyp_valid_filter_types', $valid_types, $allowed );
		$requested_types = array_intersect($requested_types, $valid_types);

		if (empty($requested_types)) {
			return ['all'];
		}

		return $requested_types;
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
	public function build_optimized_query_parts($filter_types, $search_term, $parent_post = 0) {
		global $wpdb;

		$from = "FROM {$wpdb->posts} p";
		$mime_sql = infyp_get_mime_type_sql();
		$where_conditions = [
			"p.post_type = 'attachment'",
			"p.post_status = 'inherit'",
			"p.post_mime_type IN ({$mime_sql})"
		];

		// Track whether JOINs that can produce duplicate rows are added
		$needs_group_by = false;

		// Check if lookup tables exist for optimized queries
		$lookup_table = $wpdb->prefix . 'infyp_image_post_types';
		$search_table = $wpdb->prefix . 'infyp_search_index';

		static $lookup_exists = null;
		if ($lookup_exists === null) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check, cached via static variable per request
			$lookup_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $lookup_table)) ? true : false;
		}

		static $search_exists = null;
		if ($search_exists === null) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check, cached via static variable per request
			$search_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $search_table)) ? true : false;
		}

		// Add search conditions if search term provided
		if (!empty($search_term)) {
			// Try weighted FULLTEXT search first (much faster for large datasets)
			if ($search_exists && strlen($search_term) >= 3) {
				// Use weighted FULLTEXT search with the search index table (v2 schema)
				$from .= " INNER JOIN `{$search_table}` si ON p.ID = si.image_id";

				// Build proper FULLTEXT Boolean mode query
				// Use suffix-only wildcard (term*) which is 40-60% faster than *term*
				// InnoDB default innodb_ft_min_token_size is 3, so words < 3 chars won't match
				$search_words = preg_split('/\s+/', trim($search_term));
				$fulltext_terms = [];
				foreach ($search_words as $word) {
					if (strlen($word) >= 3) {
						// Strip FULLTEXT boolean operators only — do NOT use esc_like() here
						// because it escapes underscores with backslashes, which corrupts
						// FULLTEXT queries (underscores are valid word chars in InnoDB).
						$clean_word = preg_replace( '/[+\-><()~*"@]/', '', sanitize_text_field( $word ) );
						if ( strlen( $clean_word ) >= 3 ) {
							$fulltext_terms[] = '+' . $clean_word . '*';
						}
					}
				}

				if (!empty($fulltext_terms)) {
					$fulltext_query = implode(' ', $fulltext_terms);
					// Use combined_text for filtering (matches any field)
					$where_conditions[] = $wpdb->prepare(
						"MATCH(si.combined_text) AGAINST(%s IN BOOLEAN MODE)",
						$fulltext_query
					);
				}

				// Also add LIKE condition for exact filename matching (for relevance scoring)
				$search_like = '%' . $wpdb->esc_like($search_term) . '%';
				$exact_search = $wpdb->esc_like($search_term);

				// Store search parameters for relevance scoring in get_images()
				$this->current_search_term = $search_term;
				$this->current_search_like = $search_like;
				$this->current_exact_search = $exact_search;
			} else {
				// Fall back to LIKE search with weighted relevance
				$search_like = '%' . $wpdb->esc_like($search_term) . '%';
				$exact_search = $wpdb->esc_like($search_term);

				// Join with postmeta for alt text and filename search
				$from .= " LEFT JOIN {$wpdb->postmeta} pm_alt ON (p.ID = pm_alt.post_id AND pm_alt.meta_key = '_wp_attachment_image_alt')";
				$from .= " LEFT JOIN {$wpdb->postmeta} pm_file ON (p.ID = pm_file.post_id AND pm_file.meta_key = '_wp_attached_file')";
				// Postmeta JOINs can produce duplicates in edge cases
				$needs_group_by = true;

				// Search in title, alt text, filename, caption, and description
				$where_conditions[] = $wpdb->prepare(
					"(p.post_title LIKE %s OR pm_alt.meta_value LIKE %s OR pm_file.meta_value LIKE %s OR p.post_excerpt LIKE %s OR p.post_content LIKE %s)",
					$search_like, $search_like, $search_like, $search_like, $search_like
				);

				// Store search parameters for relevance scoring
				$this->current_search_term = $search_term;
				$this->current_search_like = $search_like;
				$this->current_exact_search = $exact_search;
			}
		} else {
			// Clear search parameters when no search
			$this->current_search_term = '';
			$this->current_search_like = '';
			$this->current_exact_search = '';
		}

		// Apply post type filtering at database level
		if (!in_array('all', $filter_types, true)) {
			// Separate taxonomy-based filters from post-type filters.
			// Taxonomy filters use termmeta, not the post-type lookup table, so they
			// always need a direct subquery regardless of whether the lookup table exists.
			$post_type_filters = [];
			$taxonomy_conditions = [];

			// Allow extensions to handle taxonomy-based filter types (e.g. product_cat)
			$taxonomy_filter_types = apply_filters( 'infyp_taxonomy_filter_types', [], $filter_types );

			foreach ($filter_types as $filter_type) {
				if ( isset( $taxonomy_filter_types[ $filter_type ] ) ) {
					$taxonomy_conditions[] = $taxonomy_filter_types[ $filter_type ];
				} else {
					$post_type_filters[] = $filter_type;
				}
			}

			// Build conditions for post-type based filters
			$post_type_conditions = [];

			if (!empty($post_type_filters)) {
				// Try optimized lookup table first (10-100x faster)
				if ($lookup_exists) {
					$from .= " INNER JOIN `{$lookup_table}` ipt ON p.ID = ipt.image_id";
					// Lookup table can have multiple rows per image (one per post type)
					$needs_group_by = true;

					// Build post type conditions using the lookup table
					$type_placeholders = [];
					$type_values = [];

					foreach ($post_type_filters as $filter_type) {
						$type_placeholders[] = '%s';
						$type_values[] = $filter_type;
					}

					if (!empty($type_values)) {
						// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Dynamic placeholders array is built safely from sanitized filter types
						$post_type_conditions[] = $wpdb->prepare(
							"ipt.post_type IN (" . implode(',', $type_placeholders) . ")",
							...$type_values
						);
						// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
					}
				} else {
					// Fall back to subquery-based filtering (slower but works without lookup table)
					$usage_table = esc_sql( $wpdb->prefix . 'infyp_image_usage' );

					foreach ($post_type_filters as $filter_type) {
						if ( 'media' === $filter_type ) {
							// Media means the whole library — every image qualifies.
							$post_type_conditions[] = '1=1';
							continue;
						}

						$safe_type = sanitize_key($filter_type);

						// Attached via post_parent
						$post_type_conditions[] = $wpdb->prepare(
							"(p.post_parent IN (SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ('publish', 'draft', 'private')))",
							$safe_type
						);

						// Used as a featured image
						$post_type_conditions[] = $wpdb->prepare(
							"(p.ID IN (SELECT pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} pp ON pm.post_id = pp.ID WHERE pm.meta_key = '_thumbnail_id' AND pp.post_type = %s AND pp.post_status IN ('publish', 'draft', 'private')))",
							$safe_type
						);

						// Embedded in the content of a post of this type. Mirrors the
						// lookup table's 'content' relationship so both code paths
						// answer the filter the same way.
						// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from esc_sql( $wpdb->prefix ).
						$post_type_conditions[] = $wpdb->prepare(
							"(EXISTS (SELECT 1 FROM `{$usage_table}` iu INNER JOIN {$wpdb->posts} up ON up.ID = iu.post_id WHERE iu.image_id = p.ID AND up.post_type = %s AND up.post_status IN ('publish', 'draft', 'private')))",
							$safe_type
						);
						// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

						if ( 'post' !== $safe_type && 'page' !== $safe_type ) {
							// Allow extensions to add extra conditions (e.g. WooCommerce gallery images)
							$post_type_conditions = apply_filters( 'infyp_post_type_fallback_conditions', $post_type_conditions, $filter_type, $wpdb );
						}
					}
				}
			}

			// Merge all conditions: lookup-table/subquery conditions OR taxonomy conditions
			$all_filter_conditions = array_merge($post_type_conditions, $taxonomy_conditions);

			if (!empty($all_filter_conditions)) {
				// When lookup table is used, post_type_conditions are WHERE clauses on ipt,
				// but taxonomy_conditions are standalone subqueries. If we have BOTH, we need
				// to handle the INNER JOIN case: the JOIN already filters to lookup rows, so
				// taxonomy matches (which bypass the lookup) need a separate OR path.
				if ($lookup_exists && !empty($post_type_filters) && !empty($taxonomy_conditions)) {
					// We have both lookup-table filters AND taxonomy filters.
					// Switch from INNER JOIN to LEFT JOIN so taxonomy-only images aren't excluded.
					$from = str_replace(
						"INNER JOIN `{$lookup_table}` ipt ON p.ID = ipt.image_id",
						"LEFT JOIN `{$lookup_table}` ipt ON p.ID = ipt.image_id",
						$from
					);
					// Combine: (lookup conditions) OR (taxonomy conditions)
					$lookup_part = !empty($post_type_conditions) ? '(' . implode(' AND ', $post_type_conditions) . ')' : '';
					$tax_part = '(' . implode(' OR ', $taxonomy_conditions) . ')';
					if ($lookup_part) {
						$where_conditions[] = "({$lookup_part} OR {$tax_part})";
					} else {
						$where_conditions[] = $tax_part;
					}
				} elseif (!empty($post_type_conditions) && $lookup_exists && !empty($post_type_filters)) {
					// Only lookup-table conditions (INNER JOIN already applied)
					foreach ($post_type_conditions as $cond) {
						$where_conditions[] = $cond;
					}
				} else {
					// Only subquery/taxonomy conditions (no lookup table or only taxonomy)
					$where_conditions[] = '(' . implode(' OR ', $all_filter_conditions) . ')';
				}
			}
		} else {
			// 'all' filter - scope to allowed post types
			$allowed = self::get_allowed_post_types();
			$allowed_values = array_values($allowed);

			// If CPTs beyond post/page are allowed, show everything (no filter needed)
			if (count($allowed_values) <= 2 && !array_diff($allowed_values, ['post', 'page'])) {
				// Only post/page allowed - filter to those + media
				if ($lookup_exists) {
					$from .= " INNER JOIN `{$lookup_table}` ipt ON p.ID = ipt.image_id";
					$needs_group_by = true;
					$where_conditions[] = "ipt.post_type IN ('media', 'post', 'page')";
				} else {
					$base_condition = "(
						p.post_parent = 0
						OR p.post_parent IN (SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('post', 'page') AND post_status IN ('publish', 'draft', 'private'))
						OR p.ID IN (SELECT pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} pp ON pm.post_id = pp.ID WHERE pm.meta_key = '_thumbnail_id' AND pp.post_type IN ('post', 'page') AND pp.post_status IN ('publish', 'draft', 'private'))
					)";
					$where_conditions[] = $base_condition;
				}
			}
			// When CPTs are allowed, no additional conditions needed - show everything
		}

		// Filter by parent post (page/post filter)
		if ( $parent_post > 0 ) {
			$usage_table_name = esc_sql( $wpdb->prefix . 'infyp_image_usage' );
			static $usage_has_data = null;
			if ( $usage_has_data === null ) {
				// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table from $wpdb->prefix, cached via static var.
				$usage_has_data = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $usage_table_name ) )
					&& (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$usage_table_name}` LIMIT 1" ) > 0;
				// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}

			if ( $usage_has_data ) {
				// Use image_usage table for comprehensive matching.
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from esc_sql( $wpdb->prefix ).
				$where_conditions[] = $wpdb->prepare(
					"(p.post_parent = %d OR p.ID IN (SELECT iu.image_id FROM `{$usage_table_name}` iu WHERE iu.post_id = %d))",
					$parent_post,
					$parent_post
				);
				// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			} else {
				// Fallback: post_parent + _thumbnail_id
				$where_conditions[] = $wpdb->prepare(
					"(p.post_parent = %d OR p.ID IN (SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_thumbnail_id'))",
					$parent_post,
					$parent_post
				);
			}
		}

		/**
		 * Filter the WHERE conditions for the image query.
		 *
		 * SECURITY: All values MUST use $wpdb->prepare() with placeholders.
		 * Raw strings are injected directly into the SQL query.
		 *
		 * @since 1.0.0
		 * @param array $where_conditions Array of prepared SQL WHERE condition strings.
		 * @param array $filter_types     Active filter types.
		 */
		$where_conditions = apply_filters('infyp_image_query_where_conditions', $where_conditions, $filter_types);
		if ( ! is_array( $where_conditions ) ) {
			$where_conditions = [];
		}

		$where = " WHERE " . implode(' AND ', $where_conditions);

		return [
			'from' => $from,
			'where' => $where,
			'needs_group_by' => $needs_group_by,
		];
	}

}
