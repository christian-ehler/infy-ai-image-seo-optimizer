<?php
/**
 * Database Optimizer - Performance Optimization via Indexing
 *
 * This class manages database performance optimization by creating and
 * maintaining appropriate indexes on plugin tables and WordPress core tables.
 * Proper indexing dramatically improves query performance for:
 *
 * - API usage queries (provider, user, timestamp)
 * - Image usage tracking (image_id, post_id)
 * - Content searches (full-text search capability)
 * - Changelog lookups (image_id, timestamp)
 *
 * Optimization Strategy:
 * - Compound indexes for multi-column WHERE clauses
 * - Timestamp indexes for date range queries
 * - Foreign key indexes for JOIN operations
 * - Full-text indexes for content search (MySQL 5.6+)
 *
 * Safety Features:
 * - Checks if indexes already exist before creating
 * - Verifies table existence before optimization
 * - MySQL version detection for feature compatibility
 * - Non-blocking index creation (doesn't fail silently)
 *
 * Performance Impact:
 * - Read operations: 10-100x faster with proper indexes
 * - Write operations: Slight overhead (acceptable trade-off)
 * - Disk space: Minimal increase (indexes are small)
 *
 * @package   IPAIS\Database
 * @since 1.0.0
 * @author    InfyPress
 * @license   GPL-2.0-or-later
 */

namespace IPAIS\Database;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Database Performance Optimizer Class
 *
 * Manages database optimization through strategic index creation and
 * table structure improvements. Called during plugin activation and upgrades.
 *
 * Optimized Tables:
 * 1. infyp_api_usage:
 *    - idx_provider_timestamp: Query by provider and date
 *    - idx_user_timestamp: Query by user and date
 *    - idx_timestamp: Date range queries
 *
 * 2. infyp_image_usage (created):
 *    - Tracks image->post relationships
 *    - Speeds up "where is this image used" queries
 *
 * 3. wp_posts (optional):
 *    - Full-text index on post_content
 *    - Enables fast content searching
 *    - Currently disabled (requires background job)
 *
 * When To Run:
 * - Plugin activation
 * - Plugin upgrade (version changes)
 * - Manual trigger from Debug page
 *
 * @package   IPAIS\Database
 * @since 1.0.0
 *
 * @see       register_activation_hook() WordPress activation hooks
 */
class INFYP_Database_Optimizer {

    /**
     * Cron hook that chains the background content-image scan.
     *
     * @since 1.0.8
     * @var string
     */
    const CRON_CONTENT_SCAN = 'infyp_scan_content_images';

    /**
     * Option holding the highest post ID the content scan has processed.
     *
     * @since 1.0.8
     * @var string
     */
    const OPTION_CONTENT_SCAN_CURSOR = 'infyp_content_scan_cursor';

    /**
     * Option holding the timestamp of the last completed content scan.
     *
     * @since 1.0.8
     * @var string
     */
    const OPTION_CONTENT_SCAN_DONE = 'infyp_content_scan_completed';

    /**
     * Option holding the lookup-index schema version.
     *
     * Bumping {@see self::LOOKUP_SCHEMA_VERSION} forces one rebuild on upgrade.
     *
     * @since 1.0.8
     * @var string
     */
    const OPTION_LOOKUP_SCHEMA_VERSION = 'infyp_lookup_schema_version';

    /**
     * Current lookup-index schema version.
     *
     * 2 — adds the 'content' relationship and redefines 'media' as the whole
     * library rather than only unattached images.
     *
     * @since 1.0.8
     * @var int
     */
    const LOOKUP_SCHEMA_VERSION = 2;

    /**
     * Rows per multi-value INSERT during index population.
     *
     * @since 1.0.8
     * @var int
     */
    const INSERT_CHUNK = 200;

    /**
     * Optimize database tables by adding performance indexes
     *
     * Creates compound indexes on API usage table for common query patterns.
     * Checks for existing indexes before creating to avoid errors.
     *
     * Index Strategy:
     * - Compound indexes for multi-column WHERE clauses
     * - Timestamp indexes for date range filtering
     * - Provider indexes for usage by AI service
     *
     * @since 1.0.0
     *
     * @global wpdb $wpdb WordPress database object.
     *
     * @return void
     */
    public static function optimize_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // API usage table optimization
        $api_usage_table = $wpdb->prefix . 'infyp_api_usage';
        
        // Check if table exists before adding indexes
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Schema check, no caching needed
        $table_exists = $wpdb->get_var(
            $wpdb->prepare("SHOW TABLES LIKE %s", $api_usage_table)
        );
        
        if ( $table_exists ) {
            // Add compound indexes for common queries
            $indexes = [
                'idx_provider_timestamp' => "CREATE INDEX idx_provider_timestamp ON `{$api_usage_table}` (provider, timestamp)",
                'idx_user_timestamp' => "CREATE INDEX idx_user_timestamp ON `{$api_usage_table}` (user_id, timestamp)",
                'idx_timestamp' => "CREATE INDEX idx_timestamp ON `{$api_usage_table}` (timestamp)"
            ];
            
            foreach ( $indexes as $index_name => $index_sql ) {
                // Check if index already exists
                // Table name is safe, comes from $wpdb->prefix
                // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter
                $index_exists = $wpdb->get_results(
                    $wpdb->prepare(
                        "SHOW INDEX FROM `{$api_usage_table}` WHERE Key_name = %s",
                        $index_name
                    )
                );
                // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter

                if ( empty( $index_exists ) ) {
                    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- CREATE INDEX operation, table name from $wpdb->prefix is safe
                    $wpdb->query( $index_sql );
                }
            }
        }
        
        // Add full-text search capability for post content (if MySQL version supports it)
        $mysql_version = $wpdb->db_version();
        if ( version_compare( $mysql_version, '5.6', '>=' ) ) {
            // Check if fulltext index exists
            // Table name is safe - uses $wpdb->posts (core WordPress table)
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema check, table name validated
            $fulltext_exists = $wpdb->get_results(
                "SHOW INDEX FROM `{$wpdb->posts}` WHERE Key_name = 'infyp_content_fulltext'"
            );
            
            if ( empty( $fulltext_exists ) ) {
                // Note: This should be done carefully and may require significant time on large databases
                // Consider running during plugin activation or as a background job
                // $wpdb->query("ALTER TABLE {$wpdb->posts} ADD FULLTEXT infyp_content_fulltext (post_content)");
            }
        }
    }
    
    /**
     * Create the image usage tracking table.
     *
     * Stores image-to-post relationships for fast "where is this image used"
     * queries. Includes unique constraint on image_id, post_id, and usage_type.
     *
     * @since 1.0.0
     *
     * @global wpdb $wpdb WordPress database object.
     *
     * @return void
     */
    public static function create_image_usage_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'infyp_image_usage';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            image_id bigint(20) UNSIGNED NOT NULL,
            post_id bigint(20) UNSIGNED NOT NULL,
            usage_type varchar(50) NOT NULL DEFAULT 'content',
            position int(11) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_usage (image_id, post_id, usage_type),
            KEY idx_image (image_id),
            KEY idx_post (post_id),
            KEY idx_type (usage_type)
        ) $charset_collate;";
        
        if ( ! function_exists( 'dbDelta' ) ) {
            require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        }
        dbDelta( $sql );
    }

    /**
     * Run all database optimizations.
     *
     * Creates tables, adds indexes, and auto-populates lookup and search
     * index tables when they have not been populated yet.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public static function run_all_optimizations() {
        self::optimize_tables();
        self::create_image_usage_table();
        self::create_image_post_type_lookup_table();
        self::create_search_index_table();

        // A schema bump means the existing lookup rows no longer describe what
        // the filters expect — force one rebuild on the next admin page view.
        self::maybe_invalidate_lookup_schema();

        // Auto-populate indexes so search and filtering work immediately
        // Uses single INSERT...SELECT queries so this is fast even for large libraries
        if ( ! get_option( 'infyp_lookup_table_populated' ) ) {
            self::populate_image_post_type_lookup();
        }
        if ( ! get_option( 'infyp_search_index_populated' ) ) {
            self::populate_search_index();
        }
    }

    /**
     * Creates image-post-type lookup table for fast filtering
     *
     * This denormalized table pre-computes image-to-post-type relationships,
     * replacing expensive subqueries with fast indexed JOINs.
     *
     * Performance improvement: 10-100x faster post type filtering
     *
     * @since 1.0.0
     *
     * @return void
     */
    public static function create_image_post_type_lookup_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'infyp_image_post_types';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            image_id bigint(20) UNSIGNED NOT NULL,
            post_type varchar(50) NOT NULL,
            relationship_type varchar(20) NOT NULL DEFAULT 'attached',
            PRIMARY KEY (image_id, post_type, relationship_type),
            KEY idx_post_type (post_type),
            KEY idx_image_id (image_id)
        ) $charset_collate;";

        if ( ! function_exists( 'dbDelta' ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        dbDelta( $sql );
    }

    /**
     * Populates the image-post-type lookup table
     *
     * Processes images in batches to avoid memory issues on large sites.
     * Handles three relationship types:
     * - attached: Image attached to post via post_parent
     * - featured: Image used as featured image (_thumbnail_id)
     * - library:  Every image, so the Media filter always shows the whole library
     *
     * A fourth type, 'content' (image embedded in post content), is written by
     * {@see populate_image_content_usage()} because scanning post content is too
     * slow to run inline here.
     *
     * @since 1.0.0
     * @since 1.0.8 'unattached' replaced by 'library'; content scan scheduled.
     *
     * @param bool $truncate Whether to clear the table before populating. Default true.
     * @return array Statistics about the population process.
     */
    public static function populate_image_post_type_lookup( $truncate = true ) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'infyp_image_post_types';
        $stats = array(
            'attached' => 0,
            'featured' => 0,
            'library'  => 0,
            'total'    => 0,
        );

        // Check if table exists
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Schema check
        $table_exists = $wpdb->get_var(
            $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name )
        );

        if ( ! $table_exists ) {
            self::create_image_post_type_lookup_table();
        }

        if ( $truncate ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Truncate operation, table name from $wpdb->prefix is safe
            $wpdb->query( "TRUNCATE TABLE `{$table_name}`" );
        }

        // Insert attached images (by post_parent)
        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Bulk insert for performance, table names from $wpdb->prefix are safe
        $attached = $wpdb->query( "
            INSERT IGNORE INTO `{$table_name}` (image_id, post_type, relationship_type)
            SELECT DISTINCT
                a.ID,
                p.post_type,
                'attached'
            FROM {$wpdb->posts} a
            INNER JOIN {$wpdb->posts} p ON a.post_parent = p.ID
            WHERE a.post_type = 'attachment'
            AND a.post_mime_type LIKE 'image/%'
            AND a.post_status = 'inherit'
            AND p.post_status IN ('publish', 'draft', 'private', 'pending', 'future')
        " );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $stats['attached'] = $attached !== false ? $attached : 0;

        // Insert featured images (by _thumbnail_id)
        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Bulk insert for performance, table names from $wpdb->prefix are safe
        $featured = $wpdb->query( "
            INSERT IGNORE INTO `{$table_name}` (image_id, post_type, relationship_type)
            SELECT DISTINCT
                CAST(pm.meta_value AS UNSIGNED),
                p.post_type,
                'featured'
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
            INNER JOIN {$wpdb->posts} a ON CAST(pm.meta_value AS UNSIGNED) = a.ID
            WHERE pm.meta_key = '_thumbnail_id'
            AND pm.meta_value REGEXP '^[0-9]+$'
            AND a.post_type = 'attachment'
            AND a.post_mime_type LIKE 'image/%'
            AND p.post_status IN ('publish', 'draft', 'private', 'pending', 'future')
        " );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $stats['featured'] = $featured !== false ? $featured : 0;

        // Insert every image as 'media'.
        // The Media filter means "the whole media library", not "orphans" — an
        // image stays listed under Media no matter how many posts also use it.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Bulk insert for performance, table names from $wpdb->prefix are safe
        $library = $wpdb->query( "
            INSERT IGNORE INTO `{$table_name}` (image_id, post_type, relationship_type)
            SELECT
                a.ID,
                'media',
                'library'
            FROM {$wpdb->posts} a
            WHERE a.post_type = 'attachment'
            AND a.post_mime_type LIKE 'image/%'
            AND a.post_status = 'inherit'
        " );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $stats['library'] = $library !== false ? $library : 0;

        // Allow extensions to populate additional image relationships (e.g. WooCommerce galleries, taxonomy thumbnails)
        $stats = apply_filters( 'infyp_populate_lookup_table', $stats, $table_name, $wpdb );

        $stats['total'] = array_sum( array_filter( $stats, 'is_int' ) );

        if ( $truncate ) {
            // Content relationships were wiped with the table — rebuild them in
            // the background rather than scanning every post inside this request.
            self::schedule_content_scan( true );
        }

        // Update last populated timestamp
        update_option( 'infyp_lookup_table_populated', time() );

        // The filters now answer differently — nothing cached from before applies.
        self::invalidate_index_caches();

        return $stats;
    }

    /**
     * Force a lookup rebuild when the index schema has changed.
     *
     * Clears `infyp_lookup_table_populated`, which both marks the index as
     * stale and bypasses the one-hour throttle in the build-indexes AJAX
     * handler — so the first admin page view after an upgrade rebuilds with no
     * user action.
     *
     * @since 1.0.8
     *
     * @return bool True when a rebuild was queued.
     */
    public static function maybe_invalidate_lookup_schema() {
        $stored = (int) get_option( self::OPTION_LOOKUP_SCHEMA_VERSION, 0 );

        if ( $stored >= self::LOOKUP_SCHEMA_VERSION ) {
            return false;
        }

        delete_option( 'infyp_lookup_table_populated' );
        delete_option( self::OPTION_CONTENT_SCAN_CURSOR );
        delete_option( self::OPTION_CONTENT_SCAN_DONE );
        update_option( self::OPTION_LOOKUP_SCHEMA_VERSION, self::LOOKUP_SCHEMA_VERSION );

        return true;
    }

    /**
     * Queue the background scan that indexes images embedded in post content.
     *
     * The scan is chained through the {@see self::CRON_CONTENT_SCAN} single
     * event: each run processes a batch and reschedules itself until every
     * post has been read.
     *
     * @since 1.0.8
     *
     * @param bool $restart Whether to start over from the first post.
     * @return void
     */
    public static function schedule_content_scan( $restart = false ) {
        global $wpdb;

        if ( $restart ) {
            delete_option( self::OPTION_CONTENT_SCAN_CURSOR );
            delete_option( self::OPTION_CONTENT_SCAN_DONE );

            // Clear content usage rows so the rescan also repairs references
            // that changed while the plugin was inactive and no hook fired.
            $usage_table = $wpdb->prefix . 'infyp_image_usage';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $usage_table ) ) ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Index rebuild
                $wpdb->delete( $usage_table, array( 'usage_type' => 'content' ), array( '%s' ) );
            }
        }

        if ( ! wp_next_scheduled( self::CRON_CONTENT_SCAN ) ) {
            wp_schedule_single_event( time() + 5, self::CRON_CONTENT_SCAN );
        }
    }

    /**
     * Run one batch of the content-image scan, then chain the next.
     *
     * Hooked to {@see self::CRON_CONTENT_SCAN}. Safe to call directly (WP-CLI,
     * tests) — it simply processes a batch and reschedules while work remains.
     *
     * @since 1.0.8
     *
     * @return array Result of the batch, as returned by populate_image_content_usage().
     */
    public static function run_content_scan_batch() {
        $cursor = (int) get_option( self::OPTION_CONTENT_SCAN_CURSOR, 0 );

        $result = self::populate_image_content_usage( $cursor );

        if ( $result['done'] ) {
            delete_option( self::OPTION_CONTENT_SCAN_CURSOR );
            update_option( self::OPTION_CONTENT_SCAN_DONE, time() );
            // Nothing left to chain — drop any run this one got ahead of.
            wp_clear_scheduled_hook( self::CRON_CONTENT_SCAN );
        } else {
            update_option( self::OPTION_CONTENT_SCAN_CURSOR, $result['last_id'] );
            wp_schedule_single_event( time() + 5, self::CRON_CONTENT_SCAN );
        }

        self::invalidate_index_caches();

        return $result;
    }

    /**
     * Index one batch of posts for the images embedded in their content.
     *
     * Writes both tables from a single scan:
     * - `infyp_image_usage`      — image_id + post_id + 'content' + position
     * - `infyp_image_post_types` — image_id + post_type + 'content'
     *
     * Uses keyset pagination on the primary key so it stays cheap at any size
     * and can resume after a timeout. Only `ID`, `post_type` and `post_content`
     * are selected, and each batch is freed before the next one runs.
     *
     * @since 1.0.8
     *
     * @global \wpdb $wpdb WordPress database object.
     *
     * @param int $after_id Process posts with an ID greater than this.
     * @param int $batch    Posts per batch. Clamped to 1-500.
     * @return array {
     *     @type int  $processed   Posts read in this batch.
     *     @type int  $usage_rows  Rows written to the usage table.
     *     @type int  $lookup_rows Rows written to the lookup table.
     *     @type int  $last_id     Highest post ID processed — pass back as $after_id.
     *     @type bool $done        True when no posts remain.
     * }
     */
    public static function populate_image_content_usage( $after_id = 0, $batch = 100 ) {
        global $wpdb;

        $after_id = max( 0, (int) $after_id );
        $batch    = min( 500, max( 1, (int) $batch ) );

        $result = array(
            'processed'   => 0,
            'usage_rows'  => 0,
            'lookup_rows' => 0,
            'last_id'     => $after_id,
            'done'        => true,
        );

        $usage_table  = $wpdb->prefix . 'infyp_image_usage';
        $lookup_table = $wpdb->prefix . 'infyp_image_post_types';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check
        if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $usage_table ) ) ) {
            self::create_image_usage_table();
        }
        if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $lookup_table ) ) ) {
            self::create_image_post_type_lookup_table();
        }
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        // Cheap prefilter so text-only posts never reach the regex pass.
        $prefilter   = array();
        $params      = array( $after_id );
        foreach ( INFYP_Content_Image_Scanner::get_prefilter_terms() as $term ) {
            $prefilter[] = 'p.post_content LIKE %s';
            $params[]    = '%' . $wpdb->esc_like( $term ) . '%';
        }
        $prefilter_sql = empty( $prefilter ) ? '1=1' : '( ' . implode( ' OR ', $prefilter ) . ' )';
        $params[]      = $batch;

        $statuses = self::get_indexed_post_statuses();
        $status_sql = "'" . implode( "', '", array_map( 'esc_sql', $statuses ) ) . "'";

        // Every %s in $prefilter_sql has a matching entry in $params, in order:
        // the cursor, then one LIKE term per prefilter, then the batch size.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Placeholder count is derived from $params itself; statuses passed through esc_sql().
        $posts = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.ID, p.post_type, p.post_content
                 FROM {$wpdb->posts} p
                 WHERE p.ID > %d
                 AND p.post_type NOT IN ('attachment', 'revision')
                 AND p.post_status IN ({$status_sql})
                 AND {$prefilter_sql}
                 ORDER BY p.ID ASC
                 LIMIT %d",
                $params
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter

        if ( empty( $posts ) ) {
            return $result;
        }

        $result['processed'] = count( $posts );
        $result['done']      = count( $posts ) < $batch;

        $usage_values  = array();
        $lookup_values = array();

        foreach ( $posts as $post ) {
            $result['last_id'] = (int) $post->ID;

            $image_ids = INFYP_Content_Image_Scanner::extract_image_ids( $post->post_content );
            if ( empty( $image_ids ) ) {
                continue;
            }

            $position = 0;
            foreach ( $image_ids as $image_id ) {
                $usage_values[]  = array( $image_id, $post->ID, 'content', $position );
                $lookup_values[] = array( $image_id, $post->post_type, 'content' );
                $position++;
            }
        }

        unset( $posts );

        $result['usage_rows']  = self::insert_usage_rows( $usage_values );
        $result['lookup_rows'] = self::insert_lookup_rows( $lookup_values );

        return $result;
    }

    /**
     * Re-index a single post's content-embedded images.
     *
     * Called whenever a post is saved, trashed, restored or deleted, so the
     * filter never needs a manual index rebuild to stay accurate.
     *
     * @since 1.0.8
     *
     * @global \wpdb $wpdb WordPress database object.
     *
     * @param int $post_id The post whose content should be re-scanned.
     * @return void
     */
    public static function update_post_content_index( $post_id ) {
        global $wpdb;

        $post_id = absint( $post_id );
        if ( ! $post_id ) {
            return;
        }

        $post = get_post( $post_id );

        // Anything that no longer qualifies is treated as a removal.
        if ( ! $post
            || 'revision' === $post->post_type
            || 'attachment' === $post->post_type
            || ! in_array( $post->post_status, self::get_indexed_post_statuses(), true )
        ) {
            self::remove_post_from_indexes( $post_id );
            return;
        }

        $usage_table = $wpdb->prefix . 'infyp_image_usage';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check
        if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $usage_table ) ) ) {
            return;
        }

        $previous_ids = self::get_content_usage_image_ids( $post_id );

        $image_ids = INFYP_Content_Image_Scanner::content_may_contain_images( $post->post_content )
            ? INFYP_Content_Image_Scanner::extract_image_ids( $post->post_content )
            : array();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Incremental index update
        $wpdb->delete( $usage_table, array( 'post_id' => $post_id, 'usage_type' => 'content' ), array( '%d', '%s' ) );

        if ( ! empty( $image_ids ) ) {
            $usage_values = array();
            $position     = 0;
            foreach ( $image_ids as $image_id ) {
                $usage_values[] = array( $image_id, $post_id, 'content', $position );
                $position++;
            }
            self::insert_usage_rows( $usage_values );
        }

        self::sync_content_lookup_rows( array_merge( $previous_ids, $image_ids ) );
        self::invalidate_index_caches();
    }

    /**
     * Drop a post's rows from the usage index and refresh the images it held.
     *
     * @since 1.0.8
     *
     * @global \wpdb $wpdb WordPress database object.
     *
     * @param int $post_id The post being trashed or deleted.
     * @return void
     */
    public static function remove_post_from_indexes( $post_id ) {
        global $wpdb;

        $post_id = absint( $post_id );
        if ( ! $post_id ) {
            return;
        }

        $usage_table = $wpdb->prefix . 'infyp_image_usage';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check
        if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $usage_table ) ) ) {
            return;
        }

        $affected_ids = self::get_content_usage_image_ids( $post_id );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup
        $wpdb->delete( $usage_table, array( 'post_id' => $post_id ), array( '%d' ) );

        self::sync_content_lookup_rows( $affected_ids );

        // The post may also have owned 'attached' or 'featured' rows.
        self::refresh_post_owned_images( $post_id );

        self::invalidate_index_caches();
    }

    /**
     * Rebuild the index rows of the images a post owns outright.
     *
     * That means its featured image and any attachment whose post_parent is
     * this post — the two relationships that live on the post rather than in
     * its content, and so change when the post is trashed or restored.
     *
     * @since 1.0.8
     *
     * @global \wpdb $wpdb WordPress database object.
     *
     * @param int $post_id The post ID.
     * @return void
     */
    public static function refresh_post_owned_images( $post_id ) {
        global $wpdb;

        $post_id = absint( $post_id );
        if ( ! $post_id ) {
            return;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Small, targeted lookup
        $owned_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'attachment'",
                $post_id
            )
        );

        $thumbnail_id = (int) get_post_meta( $post_id, '_thumbnail_id', true );
        if ( $thumbnail_id ) {
            $owned_ids[] = $thumbnail_id;
        }

        foreach ( array_unique( array_map( 'absint', (array) $owned_ids ) ) as $image_id ) {
            if ( $image_id ) {
                self::update_image_indexes( $image_id );
            }
        }
    }

    /**
     * Rebuild the 'content' lookup rows for a set of images from the usage table.
     *
     * The usage table is the source of truth for content relationships; this
     * derives the post types from it so a removed image loses its filter row
     * as soon as the post that used it stops referencing it.
     *
     * @since 1.0.8
     *
     * @global \wpdb $wpdb WordPress database object.
     *
     * @param int[] $image_ids Attachment IDs to refresh.
     * @return void
     */
    public static function sync_content_lookup_rows( array $image_ids ) {
        global $wpdb;

        $image_ids = array_values( array_unique( array_filter( array_map( 'absint', $image_ids ) ) ) );
        if ( empty( $image_ids ) ) {
            return;
        }

        $lookup_table = $wpdb->prefix . 'infyp_image_post_types';
        $usage_table  = $wpdb->prefix . 'infyp_image_usage';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check
        if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $lookup_table ) ) ) {
            return;
        }

        $id_placeholders = implode( ', ', array_fill( 0, count( $image_ids ), '%d' ) );
        $statuses        = self::get_indexed_post_statuses();
        $status_sql      = "'" . implode( "', '", array_map( 'esc_sql', $statuses ) ) . "'";

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Placeholders built from the ID count; statuses passed through esc_sql().
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM `{$lookup_table}`
                 WHERE relationship_type = 'content'
                 AND image_id IN ({$id_placeholders})",
                ...$image_ids
            )
        );

        $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO `{$lookup_table}` (image_id, post_type, relationship_type)
                 SELECT DISTINCT iu.image_id, p.post_type, 'content'
                 FROM `{$usage_table}` iu
                 INNER JOIN {$wpdb->posts} p ON p.ID = iu.post_id
                 WHERE iu.usage_type = 'content'
                 AND p.post_status IN ({$status_sql})
                 AND iu.image_id IN ({$id_placeholders})",
                ...$image_ids
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
    }

    /**
     * Post statuses whose images are indexed.
     *
     * @since 1.0.8
     *
     * @return string[] Post status slugs.
     */
    public static function get_indexed_post_statuses() {
        /**
         * Filters the post statuses whose images appear in the post-type filter.
         *
         * @since 1.0.8
         * @param string[] $statuses Post status slugs.
         */
        $statuses = apply_filters(
            'infyp_indexed_post_statuses',
            array( 'publish', 'draft', 'private', 'pending', 'future' )
        );

        return array_values( array_filter( array_map( 'sanitize_key', (array) $statuses ) ) );
    }

    /**
     * Read the image IDs currently recorded as content usage for a post.
     *
     * @since 1.0.8
     *
     * @global \wpdb $wpdb WordPress database object.
     *
     * @param int $post_id Post ID.
     * @return int[] Attachment IDs.
     */
    private static function get_content_usage_image_ids( $post_id ) {
        global $wpdb;

        $usage_table = $wpdb->prefix . 'infyp_image_usage';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from $wpdb->prefix; index maintenance.
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT image_id FROM `{$usage_table}` WHERE post_id = %d AND usage_type = 'content'",
                $post_id
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        return array_map( 'absint', (array) $ids );
    }

    /**
     * Bulk-insert `[ image_id, post_id, usage_type, position ]` rows.
     *
     * @since 1.0.8
     *
     * @param array[] $rows Rows as [ int $image_id, int $post_id, string $usage_type, int $position ].
     * @return int Rows inserted.
     */
    private static function insert_usage_rows( array $rows ) {
        global $wpdb;

        $inserted = 0;

        foreach ( array_chunk( $rows, self::INSERT_CHUNK ) as $chunk ) {
            $args = array();
            foreach ( $chunk as $row ) {
                $args[] = (int) $row[0];
                $args[] = (int) $row[1];
                $args[] = (string) $row[2];
                $args[] = (int) $row[3];
            }

            $inserted += self::insert_prepared_rows(
                $wpdb->prefix . 'infyp_image_usage',
                'image_id, post_id, usage_type, position',
                '(%d, %d, %s, %d)',
                count( $chunk ),
                $args
            );
        }

        return $inserted;
    }

    /**
     * Bulk-insert `[ image_id, post_type, relationship_type ]` rows.
     *
     * @since 1.0.8
     *
     * @param array[] $rows Rows as [ int $image_id, string $post_type, string $relationship_type ].
     * @return int Rows inserted.
     */
    private static function insert_lookup_rows( array $rows ) {
        global $wpdb;

        $inserted = 0;

        foreach ( array_chunk( $rows, self::INSERT_CHUNK ) as $chunk ) {
            $args = array();
            foreach ( $chunk as $row ) {
                $args[] = (int) $row[0];
                $args[] = (string) $row[1];
                $args[] = (string) $row[2];
            }

            $inserted += self::insert_prepared_rows(
                $wpdb->prefix . 'infyp_image_post_types',
                'image_id, post_type, relationship_type',
                '(%d, %s, %s)',
                count( $chunk ),
                $args
            );
        }

        return $inserted;
    }

    /**
     * Run one multi-row `INSERT IGNORE` through a single $wpdb->prepare() call.
     *
     * The VALUES list is a repeat of $row_placeholders, one copy per row, and
     * every value travels as a bound parameter — nothing from the caller is
     * concatenated into the SQL. Only the table and column names, which come
     * from constants in this class, are interpolated.
     *
     * @since 1.0.8
     *
     * @global \wpdb $wpdb WordPress database object.
     *
     * @param string $table            Fully-qualified table name.
     * @param string $columns          Comma-separated column list.
     * @param string $row_placeholders Placeholder tuple for one row, e.g. '(%d, %s)'.
     * @param int    $row_count        Number of rows in $args.
     * @param array  $args             Flat list of values, $row_count tuples long.
     * @return int Rows inserted.
     */
    private static function insert_prepared_rows( $table, $columns, $row_placeholders, $row_count, array $args ) {
        global $wpdb;

        if ( $row_count < 1 || empty( $args ) ) {
            return 0;
        }

        $values = implode( ', ', array_fill( 0, $row_count, $row_placeholders ) );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table and columns are internal constants; every caller value is a bound parameter in $args.
        $result = $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO `{$table}` ({$columns}) VALUES {$values}",
                $args
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter

        return ( false === $result ) ? 0 : (int) $result;
    }

    /**
     * Drop the cached filter counts and query results after an index change.
     *
     * @since 1.0.8
     *
     * @return void
     */
    private static function invalidate_index_caches() {
        if ( ! class_exists( '\IPAIS\Core\INFYP_Cache_Manager' ) ) {
            return;
        }

        $cache = \IPAIS\Core\INFYP_Cache_Manager::get_instance();

        // Count tiles live in transients; the filtered row counts and list
        // results live in versioned query-cache keys. An index change moves
        // both, so both have to go.
        $cache->invalidate_count_caches();
        $cache->clear_image_list_cache();
    }

    /**
     * Creates search index table for fast full-text search with weighted relevance
     *
     * Stores searchable fields in separate columns with individual FULLTEXT indexes
     * to enable weighted relevance scoring (filename matches rank higher than title, etc.)
     *
     * Schema v2 - Separate columns for weighted search:
     * - filename: highest weight (exact/partial filename matches)
     * - title: medium-high weight
     * - alt_text: medium weight
     * - combined_text: fallback for broad search (includes post_excerpt + post_content)
     *
     * @since 1.0.0
     * @since 1.0.0 Restructured with separate columns for weighted relevance
     *
     * @return void
     */
    public static function create_search_index_table() {
        global $wpdb;

        // Check MySQL version (FULLTEXT on InnoDB requires 5.6+)
        $mysql_version = $wpdb->db_version();
        if ( version_compare( $mysql_version, '5.6', '<' ) ) {
            return;
        }

        $table_name = $wpdb->prefix . 'infyp_search_index';
        $charset_collate = $wpdb->get_charset_collate();

        // Schema v2: Separate columns for weighted search relevance
        $sql = "CREATE TABLE $table_name (
            image_id bigint(20) UNSIGNED NOT NULL PRIMARY KEY,
            filename varchar(255) DEFAULT '' NOT NULL,
            title text NOT NULL,
            alt_text text NOT NULL,
            combined_text text NOT NULL,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FULLTEXT KEY idx_filename (filename),
            FULLTEXT KEY idx_title (title),
            FULLTEXT KEY idx_alt (alt_text),
            FULLTEXT KEY idx_combined (combined_text)
        ) ENGINE=InnoDB $charset_collate;";

        if ( ! function_exists( 'dbDelta' ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        dbDelta( $sql );

        // Check if we need to migrate from old schema (single search_text column)
        self::maybe_migrate_search_index();
    }

    /**
     * Migrate search index from old schema to new weighted schema
     *
     * Detects if the table has the old single-column schema and triggers
     * a repopulation with the new multi-column schema.
     *
     * @since 1.0.0
     *
     * @return void
     */
    private static function maybe_migrate_search_index() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'infyp_search_index';

        // Check if 'filename' column exists (new schema indicator)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Schema check; $table_name from $wpdb->prefix + hardcoded suffix
        $columns = $wpdb->get_results( "SHOW COLUMNS FROM `{$table_name}`" );

        $has_filename_column = false;
        $has_old_search_text = false;

        foreach ( $columns as $column ) {
            if ( $column->Field === 'filename' ) {
                $has_filename_column = true;
            }
            if ( $column->Field === 'search_text' ) {
                $has_old_search_text = true;
            }
        }

        // If old schema detected (has search_text but no filename), drop and recreate
        if ( $has_old_search_text && ! $has_filename_column ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Schema migration; $table_name from $wpdb->prefix + hardcoded suffix
            $wpdb->query( "DROP TABLE IF EXISTS `{$table_name}`" );

            // Recreate with new schema
            $charset_collate = $wpdb->get_charset_collate();
            $sql = "CREATE TABLE $table_name (
                image_id bigint(20) UNSIGNED NOT NULL PRIMARY KEY,
                filename varchar(255) DEFAULT '' NOT NULL,
                title text NOT NULL,
                alt_text text NOT NULL,
                combined_text text NOT NULL,
                updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FULLTEXT KEY idx_filename (filename),
                FULLTEXT KEY idx_title (title),
                FULLTEXT KEY idx_alt (alt_text),
                FULLTEXT KEY idx_combined (combined_text)
            ) ENGINE=InnoDB $charset_collate;";

            if ( ! function_exists( 'dbDelta' ) ) {
                require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            }
            dbDelta( $sql );

            // Mark for repopulation
            delete_option( 'infyp_search_index_populated' );
        }
    }

    /**
     * Populates the search index table with weighted fields
     *
     * Populates separate columns for filename, title, alt_text, and combined_text
     * to enable weighted relevance scoring.
     *
     * @since 1.0.0
     * @since 1.0.0 Updated to populate separate columns for weighted search
     *
     * @param bool $truncate Whether to clear the table before populating. Default true.
     * @return int Number of images indexed.
     */
    public static function populate_search_index( $truncate = true ) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'infyp_search_index';

        // Check if table exists
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Schema check
        $table_exists = $wpdb->get_var(
            $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name )
        );

        if ( ! $table_exists ) {
            self::create_search_index_table();
            // Check again after creation
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Schema check
            $table_exists = $wpdb->get_var(
                $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name )
            );
            if ( ! $table_exists ) {
                return 0; // MySQL version too old
            }
        }

        if ( $truncate ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Truncate operation, table name from $wpdb->prefix is safe
            $wpdb->query( "TRUNCATE TABLE `{$table_name}`" );
        }

        // Populate with separate columns for weighted relevance scoring
        // - filename: extracted from _wp_attached_file (just the filename without path)
        // - title: post_title
        // - alt_text: _wp_attachment_image_alt meta
        // - combined_text: all fields concatenated (includes post_excerpt + post_content for broad search)
        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Bulk insert for performance, table names from $wpdb->prefix are safe
        $indexed = $wpdb->query( "
            INSERT INTO `{$table_name}` (image_id, filename, title, alt_text, combined_text)
            SELECT
                p.ID,
                COALESCE(
                    SUBSTRING_INDEX(
                        SUBSTRING_INDEX(pm_file.meta_value, '/', -1),
                        '.',
                        1
                    ),
                    ''
                ) as filename,
                COALESCE(p.post_title, '') as title,
                COALESCE(pm_alt.meta_value, '') as alt_text,
                CONCAT_WS(' ',
                    COALESCE(SUBSTRING_INDEX(SUBSTRING_INDEX(pm_file.meta_value, '/', -1), '.', 1), ''),
                    COALESCE(p.post_title, ''),
                    COALESCE(pm_alt.meta_value, ''),
                    COALESCE(p.post_excerpt, ''),
                    COALESCE(p.post_content, '')
                ) as combined_text
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm_alt
                ON p.ID = pm_alt.post_id AND pm_alt.meta_key = '_wp_attachment_image_alt'
            LEFT JOIN {$wpdb->postmeta} pm_file
                ON p.ID = pm_file.post_id AND pm_file.meta_key = '_wp_attached_file'
            WHERE p.post_type = 'attachment'
            AND p.post_mime_type LIKE 'image/%'
            AND p.post_status = 'inherit'
            ON DUPLICATE KEY UPDATE
                filename = VALUES(filename),
                title = VALUES(title),
                alt_text = VALUES(alt_text),
                combined_text = VALUES(combined_text),
                updated_at = CURRENT_TIMESTAMP
        " );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        // Update last populated timestamp
        update_option( 'infyp_search_index_populated', time() );

        return $indexed !== false ? $indexed : 0;
    }

    /**
     * Updates indexes for a single image
     *
     * Called when an image is added, edited, or deleted.
     *
     * @since 1.0.0
     *
     * @param int $image_id The attachment ID.
     * @return void
     */
    public static function update_image_indexes( $image_id ) {
        global $wpdb;

        $image_id = absint( $image_id );
        if ( ! $image_id ) {
            return;
        }

        // Check if attachment is an image
        $mime_type = get_post_mime_type( $image_id );
        if ( ! $mime_type || strpos( $mime_type, 'image/' ) !== 0 ) {
            return;
        }

        $lookup_table = $wpdb->prefix . 'infyp_image_post_types';
        $search_table = $wpdb->prefix . 'infyp_search_index';

        // Check if lookup table exists
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Schema check
        $lookup_exists = $wpdb->get_var(
            $wpdb->prepare( 'SHOW TABLES LIKE %s', $lookup_table )
        );

        if ( $lookup_exists ) {
            // Delete existing entries for this image
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Incremental update
            $wpdb->delete( $lookup_table, array( 'image_id' => $image_id ), array( '%d' ) );

            // Get attachment data
            $attachment = get_post( $image_id );
            if ( ! $attachment ) {
                return;
            }

            // Check if attached to a post
            if ( $attachment->post_parent > 0 ) {
                $parent = get_post( $attachment->post_parent );
                if ( $parent && in_array( $parent->post_status, array( 'publish', 'draft', 'private', 'pending', 'future' ), true ) ) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Incremental update
                    $wpdb->insert(
                        $lookup_table,
                        array(
                            'image_id'          => $image_id,
                            'post_type'         => $parent->post_type,
                            'relationship_type' => 'attached',
                        ),
                        array( '%d', '%s', '%s' )
                    );
                }
            }

            // Check if used as featured image anywhere
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Lookup query
            $featured_posts = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT p.post_type
                    FROM {$wpdb->postmeta} pm
                    INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                    WHERE pm.meta_key = '_thumbnail_id'
                    AND pm.meta_value = %s
                    AND p.post_status IN ('publish', 'draft', 'private', 'pending', 'future')",
                    $image_id
                )
            );

            foreach ( $featured_posts as $post ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Incremental update
                $wpdb->replace(
                    $lookup_table,
                    array(
                        'image_id'          => $image_id,
                        'post_type'         => $post->post_type,
                        'relationship_type' => 'featured',
                    ),
                    array( '%d', '%s', '%s' )
                );
            }

            // Every image belongs to the media library, however many posts use it.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Incremental update
            $wpdb->insert(
                $lookup_table,
                array(
                    'image_id'          => $image_id,
                    'post_type'         => 'media',
                    'relationship_type' => 'library',
                ),
                array( '%d', '%s', '%s' )
            );

            // Restore the content relationships that the delete above removed.
            // The usage table is maintained per post, so it survives this rebuild.
            self::sync_content_lookup_rows( array( $image_id ) );
        }

        // Update search index
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Schema check
        $search_exists = $wpdb->get_var(
            $wpdb->prepare( 'SHOW TABLES LIKE %s', $search_table )
        );

        if ( $search_exists ) {
            $attachment = get_post( $image_id );
            if ( ! $attachment ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Cleanup
                $wpdb->delete( $search_table, array( 'image_id' => $image_id ), array( '%d' ) );
                return;
            }

            $alt_text = get_post_meta( $image_id, '_wp_attachment_image_alt', true );
            $file_path = get_post_meta( $image_id, '_wp_attached_file', true );
            // Extract filename without extension for better search matching
            $filename_with_ext = $file_path ? basename( $file_path ) : '';
            $filename = pathinfo( $filename_with_ext, PATHINFO_FILENAME );

            $title = $attachment->post_title ? $attachment->post_title : '';
            $alt_text = $alt_text ? $alt_text : '';

            // Build combined text including post_excerpt (caption) and post_content (description) for broad search.
            $combined_parts = array( $filename, $title, $alt_text );
            if ( ! empty( $attachment->post_excerpt ) ) {
                $combined_parts[] = $attachment->post_excerpt;
            }
            if ( ! empty( $attachment->post_content ) ) {
                $combined_parts[] = $attachment->post_content;
            }

            /**
             * Filters the combined text parts before they are stored in the search index.
             *
             * Extensions can add custom field data (e.g., caption, description) for search.
             *
             * @since 1.0.0
             * @param array  $combined_parts Array of text strings to concatenate.
             * @param int    $image_id       The attachment ID.
             * @param object $attachment     The WP_Post attachment object.
             */
            $combined_parts = apply_filters( 'infyp_search_index_combined_parts', $combined_parts, $image_id, $attachment );
            $combined_text = implode( ' ', array_filter( $combined_parts ) );

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Incremental update
            $wpdb->replace(
                $search_table,
                array(
                    'image_id'      => $image_id,
                    'filename'      => $filename,
                    'title'         => $title,
                    'alt_text'      => $alt_text,
                    'combined_text' => $combined_text,
                ),
                array( '%d', '%s', '%s', '%s', '%s' )
            );
        }
    }

    /**
     * Removes an image from all indexes
     *
     * @since 1.0.0
     *
     * @param int $image_id The attachment ID.
     * @return void
     */
    public static function remove_image_from_indexes( $image_id ) {
        global $wpdb;

        $image_id = absint( $image_id );
        if ( ! $image_id ) {
            return;
        }

        $lookup_table = $wpdb->prefix . 'infyp_image_post_types';
        $search_table = $wpdb->prefix . 'infyp_search_index';
        $usage_table  = $wpdb->prefix . 'infyp_image_usage';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Cleanup
        $wpdb->delete( $lookup_table, array( 'image_id' => $image_id ), array( '%d' ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Cleanup
        $wpdb->delete( $search_table, array( 'image_id' => $image_id ), array( '%d' ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Cleanup
        $wpdb->delete( $usage_table, array( 'image_id' => $image_id ), array( '%d' ) );
    }

    /**
     * Checks if lookup tables are populated and up-to-date
     *
     * @since 1.0.0
     *
     * @return array Status of each index.
     */
    public static function get_index_status() {
        global $wpdb;

        $lookup_table = $wpdb->prefix . 'infyp_image_post_types';
        $search_table = $wpdb->prefix . 'infyp_search_index';

        $status = array(
            'lookup' => array(
                'exists'      => false,
                'count'       => 0,
                'last_update' => null,
            ),
            'search' => array(
                'exists'      => false,
                'count'       => 0,
                'last_update' => null,
            ),
        );

        // Check lookup table
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Schema check
        $lookup_exists = $wpdb->get_var(
            $wpdb->prepare( 'SHOW TABLES LIKE %s', $lookup_table )
        );

        if ( $lookup_exists ) {
            $status['lookup']['exists'] = true;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Count query, table name from $wpdb->prefix is safe
            $status['lookup']['count'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$lookup_table}`" );
            $status['lookup']['last_update'] = get_option( 'infyp_lookup_table_populated' );
        }

        // Check search table
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Schema check
        $search_exists = $wpdb->get_var(
            $wpdb->prepare( 'SHOW TABLES LIKE %s', $search_table )
        );

        if ( $search_exists ) {
            $status['search']['exists'] = true;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Count query, table name from $wpdb->prefix is safe
            $status['search']['count'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$search_table}`" );
            $status['search']['last_update'] = get_option( 'infyp_search_index_populated' );
        }

        return $status;
    }
}