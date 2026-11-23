<?php
/**
 * Advanced Cache Management System for AI Image SEO Plugin
 *
 * This file contains the INFYP_Cache_Manager class which provides a comprehensive
 * multi-tier caching infrastructure for the plugin. It implements intelligent caching
 * strategies with object cache support, transient fallbacks, and automatic invalidation.
 *
 * Caching Strategy:
 * - Object Cache: Primary caching layer (Redis/Memcached when available)
 * - Transients: Fallback for non-persistent cache environments
 * - Query Caching: Database query result caching with versioning
 * - API Response Caching: Cached API responses to reduce external calls
 * - Image Data Caching: Pre-computed image metadata and usage information
 *
 * Cache Groups:
 * - infyp: General plugin cache (settings, configurations)
 * - infyp_queries: Database query results
 * - infyp_api: API response caching
 * - infyp_images: Image metadata and usage data
 *
 * Features:
 * - Automatic cache invalidation on data changes
 * - Cache warming for frequently accessed data
 * - Cache buster mechanism for group-level invalidation
 * - Preloading support for batch operations
 * - Statistical tracking and monitoring
 *
 * @package    AI_Image_SEO
 * @subpackage Core
 * @since 1.0.0
 * @author     InfyPress
 * @license    GPL-2.0+
 */

namespace IPAIS\Core;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Advanced Cache Manager for Infy AI Image SEO
 *
 * Implements a singleton pattern to provide centralized cache management across
 * the plugin. Supports both persistent object cache (Redis/Memcached) and transient-
 * based caching for maximum compatibility.
 *
 * Design Pattern: Singleton
 * Ensures only one cache manager instance exists, maintaining consistent cache
 * state and preventing duplicate invalidation operations.
 *
 * Caching Workflow:
 * 1. **Read**: Check object cache, fallback to transients if cache miss
 * 2. **Write**: Store in object cache, duplicate to transients if non-persistent
 * 3. **Invalidate**: Remove from both object cache and transients
 * 4. **Flush**: Clear entire cache groups with versioning
 *
 * Cache Expiration Times:
 * - EXPIRE_HOUR (3600s): Short-lived data (image metadata, user settings)
 * - EXPIRE_DAY (86400s): Medium-lived data (API responses, post type settings)
 * - EXPIRE_WEEK (604800s): Long-lived data (rarely changing configurations)
 *
 * Automatic Invalidation:
 * The cache manager hooks into WordPress actions to automatically invalidate
 * cached data when relevant changes occur:
 * - add_attachment: Clears image list caches
 * - edit_attachment: Invalidates specific image cache
 * - delete_attachment: Removes image and list caches
 * - infyp_image_optimized: Clears optimized image cache
 * - infyp_settings_updated: Invalidates settings cache
 *
 * Usage Example:
 * ```php
 * $cache = INFYP_Cache_Manager::get_instance();
 *
 * // Simple get/set
 * $value = $cache->get( 'my_key' );
 * if ( $value === false ) {
 *     $value = expensive_operation();
 *     $cache->set( 'my_key', $value, INFYP_Cache_Manager::CACHE_GROUP, INFYP_Cache_Manager::EXPIRE_HOUR );
 * }
 *
 * // Remember pattern (get or compute and cache)
 * $result = $cache->remember( 'my_key', function() {
 *     return expensive_operation();
 * }, INFYP_Cache_Manager::CACHE_GROUP, INFYP_Cache_Manager::EXPIRE_DAY );
 * ```
 *
 * @package    AI_Image_SEO
 * @subpackage Core
 * @since 1.0.0
 *
 * @see wp_cache_get() For object cache operations
 * @see get_transient() For transient fallback
 * @see wp_using_ext_object_cache() For cache type detection
 */
class INFYP_Cache_Manager {
    
    /**
     * Singleton instance
     *
     * @since 1.0.0
     * @var    INFYP_Cache_Manager|null
     * @access private
     */
    private static $instance = null;

    /**
     * Featured image IDs captured before a _thumbnail_id write, keyed by post ID
     *
     * @since 1.0.8
     * @var    int[]
     * @access private
     */
    private $previous_thumbnails = [];

    /**
     * General plugin cache group identifier
     *
     * @since 1.0.0
     * @var   string
     */
    const CACHE_GROUP = 'infyp';

    /**
     * Database query cache group identifier
     *
     * @since 1.0.0
     * @var   string
     */
    const CACHE_GROUP_QUERIES = 'infyp_queries';

    /**
     * API response cache group identifier
     *
     * @since 1.0.0
     * @var   string
     */
    const CACHE_GROUP_API = 'infyp_api';

    /**
     * Image metadata cache group identifier
     *
     * @since 1.0.0
     * @var   string
     */
    const CACHE_GROUP_IMAGES = 'infyp_images';

    /**
     * One hour cache expiration (3600 seconds)
     *
     * @since 1.0.0
     * @var   int
     */
    const EXPIRE_HOUR = 3600;

    /**
     * One day cache expiration (86400 seconds)
     *
     * @since 1.0.0
     * @var   int
     */
    const EXPIRE_DAY = 86400;

    /**
     * One week cache expiration (604800 seconds)
     *
     * @since 1.0.0
     * @var   int
     */
    const EXPIRE_WEEK = 604800;

    /**
     * Transient key for cached total image count
     *
     * @since 1.0.0
     * @var   string
     */
    const TRANSIENT_TOTAL_COUNT = 'infyp_total_image_count';

    /**
     * Transient key for cached filter counts
     *
     * @since 1.0.0
     * @var   string
     */
    const TRANSIENT_FILTER_COUNTS = 'infyp_filter_counts';

    /**
     * Post meta key marking an image upload that is not complete yet.
     *
     * Holds the Unix time the attachment was created. Set on
     * `add_attachment`, deleted when `infyp_after_image_upload` fires, and
     * gone with the attachment. A marker older than UPLOAD_PENDING_TTL counts
     * as expired when read (an upload the browser abandoned before it sent
     * the sub-sizes); the daily maintenance deletes expired markers. Post meta
     * rather than a transient: cache flushes and transient sweeps must not
     * end a pending upload.
     *
     * @since 1.0.9
     * @var   string
     */
    const UPLOAD_PENDING_META = '_infyp_upload_pending';

    /**
     * Lifetime of the upload-pending marker in seconds.
     *
     * Long enough for a large batch whose files are created first and
     * receive their sub-sizes one after another.
     *
     * @since 1.0.9
     * @var   int
     */
    const UPLOAD_PENDING_TTL = 86400;

    /**
     * Attachments created in this request whose sub-sizes the browser
     * creates and sends in later requests (client-side media processing).
     * Keyed by "<blog id>:<attachment id>".
     *
     * @since 1.0.9
     * @var   array<string, bool>
     */
    private static $awaiting_client_sub_sizes = [];

    /**
     * Attachments whose metadata is being regenerated for an existing file,
     * keyed by "<blog id>:<attachment id>" with a nesting depth.
     *
     * @since 1.0.9
     * @var   array<string, int>
     */
    private static $regenerating = [];

    /**
     * Attachments whose upload is complete and whose metadata is about to be
     * saved; `infyp_after_image_upload` fires once the save has happened.
     * Keyed by "<blog id>:<attachment id>", value is the attachment ID.
     *
     * @since 1.0.9
     * @var   array<string, int>
     */
    private static $completed_uploads = [];

    /**
     * Check if extended post types (beyond post/page) are enabled via filter
     *
     * @return bool True if custom post types are allowed
     */
    private static function has_extended_post_types() {
        $allowed = \IPAIS\Tables\INFYP_Image_Query_Builder::get_allowed_post_types();
        return count( $allowed ) > 2 || ! empty( array_diff( array_values( $allowed ), [ 'post', 'page' ] ) );
    }

    /**
     * Get the singleton instance of the cache manager.
     *
     * @since 1.0.0
     *
     * @return INFYP_Cache_Manager The singleton instance.
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Private constructor.
     *
     * Registers WordPress action hooks for cache invalidation and attachment
     * lifecycle events.
     *
     * @since 1.0.0
     * @access private
     */
    private function __construct() {
        add_action('init', [$this, 'init_cache_system']);
        add_action('infyp_image_optimized', [$this, 'invalidate_image_cache'], 10, 1);
        add_action('infyp_settings_updated', [$this, 'invalidate_settings_cache']);
        
        // New image: clear caches, index it and mark the upload as pending.
        add_action('add_attachment', [$this, 'handle_new_attachment']);

        // A REST upload that asks the server not to create sub-sizes gets them
        // from the browser in later requests; the upload completes then.
        add_action('rest_insert_attachment', [$this, 'note_client_side_processing'], 10, 3);

        // Metadata generated: first SEO score, and completion of a pending upload.
        add_filter('wp_generate_attachment_metadata', [$this, 'handle_attachment_metadata_generated'], 99, 2);

        // infyp_after_image_upload fires once the completed metadata is saved.
        add_action('added_post_meta', [$this, 'handle_attachment_metadata_saved'], 10, 4);
        add_action('updated_post_meta', [$this, 'handle_attachment_metadata_saved'], 10, 4);

        // Also hook into edit_attachment for updates
        add_action('edit_attachment', [$this, 'handle_attachment_update']);

        // Add hook for when attachments are deleted
        add_action('delete_attachment', [$this, 'handle_attachment_delete']);

        // Hook into meta changes that affect filter counts
        add_action('updated_post_meta', [$this, 'handle_meta_change_for_counts'], 10, 4);
        add_action('added_post_meta', [$this, 'handle_meta_change_for_counts'], 10, 4);
        add_action('deleted_post_meta', [$this, 'handle_meta_change_for_counts'], 10, 4);

        // These fire BEFORE the write, which is the only moment the outgoing
        // featured image is still readable — it needs reindexing too.
        add_action('update_post_meta', [$this, 'capture_previous_thumbnail'], 10, 3);
        add_action('delete_post_meta', [$this, 'capture_previous_thumbnail'], 10, 3);

        // Keep content-embedded images indexed as posts are edited.
        // wp_after_insert_post fires once the content is written and covers
        // custom post types, the block editor and REST writes alike.
        add_action('wp_after_insert_post', [$this, 'handle_post_saved'], 10, 2);
        add_action('deleted_post', [$this, 'handle_post_removed']);
        add_action('trashed_post', [$this, 'handle_post_removed']);
        add_action('untrashed_post', [$this, 'handle_post_restored']);
    }

    /**
     * Remember the featured image a post had before the meta write lands.
     *
     * Hooked to the pre-write `update_post_meta` / `delete_post_meta` actions.
     *
     * @since 1.0.8
     *
     * @param mixed  $unused   Meta ID (or IDs, when deleting). Not used.
     * @param int    $post_id  The post whose meta is changing.
     * @param string $meta_key The meta key being written.
     * @return void
     */
    public function capture_previous_thumbnail( $unused, $post_id, $meta_key ) {
        if ( '_thumbnail_id' !== $meta_key ) {
            return;
        }

        $this->previous_thumbnails[ (int) $post_id ] = (int) get_post_meta( $post_id, '_thumbnail_id', true );
    }

    /**
     * Reindex the images involved in a featured-image change.
     *
     * Both the incoming and the outgoing attachment need their lookup rows
     * rebuilt: one gains a 'featured' relationship, the other loses it.
     *
     * @since 1.0.8
     *
     * @param int   $post_id    The post whose featured image changed.
     * @param mixed $meta_value The new `_thumbnail_id` value.
     * @return void
     */
    private function handle_featured_image_change( $post_id, $meta_value ) {
        $post_id  = (int) $post_id;
        $image_ids = [];

        if ( isset( $this->previous_thumbnails[ $post_id ] ) ) {
            $image_ids[] = $this->previous_thumbnails[ $post_id ];
            unset( $this->previous_thumbnails[ $post_id ] );
        }

        if ( is_scalar( $meta_value ) ) {
            $image_ids[] = (int) $meta_value;
        }

        $image_ids = array_filter( array_unique( $image_ids ) );
        if ( empty( $image_ids ) ) {
            return;
        }

        if ( class_exists( '\IPAIS\Database\INFYP_Database_Optimizer' ) ) {
            foreach ( $image_ids as $image_id ) {
                if ( wp_attachment_is_image( $image_id ) ) {
                    \IPAIS\Database\INFYP_Database_Optimizer::update_image_indexes( $image_id );
                }
            }
        }

        $this->invalidate_count_caches();

        if ( class_exists( '\IPAIS\Cron\INFYP_Stats_Calculator' ) ) {
            \IPAIS\Cron\INFYP_Stats_Calculator::invalidate_cache();
        }
    }

    /**
     * Re-index a post's content images after it is saved.
     *
     * @since 1.0.8
     *
     * @param int      $post_id The post ID.
     * @param \WP_Post $post    The post object.
     * @return void
     */
    public function handle_post_saved( $post_id, $post = null ) {
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }

        $post_type = $post instanceof \WP_Post ? $post->post_type : get_post_type( $post_id );
        if ( 'attachment' === $post_type ) {
            return;
        }

        if ( class_exists( '\IPAIS\Database\INFYP_Database_Optimizer' ) ) {
            \IPAIS\Database\INFYP_Database_Optimizer::update_post_content_index( $post_id );
        }
    }

    /**
     * Drop a post's images from the indexes when it is trashed or deleted.
     *
     * @since 1.0.8
     *
     * @param int $post_id The post ID.
     * @return void
     */
    public function handle_post_removed( $post_id ) {
        if ( 'attachment' === get_post_type( $post_id ) ) {
            return; // Attachments are handled by handle_attachment_delete().
        }

        if ( class_exists( '\IPAIS\Database\INFYP_Database_Optimizer' ) ) {
            \IPAIS\Database\INFYP_Database_Optimizer::remove_post_from_indexes( $post_id );
        }
    }

    /**
     * Re-index a post's images when it is restored from the trash.
     *
     * @since 1.0.8
     *
     * @param int $post_id The post ID.
     * @return void
     */
    public function handle_post_restored( $post_id ) {
        $this->handle_post_saved( $post_id );

        // Trashing dropped the featured/attached rows too — put them back.
        if ( class_exists( '\IPAIS\Database\INFYP_Database_Optimizer' ) ) {
            \IPAIS\Database\INFYP_Database_Optimizer::refresh_post_owned_images( $post_id );
        }
    }

    /**
     * Handle meta changes that affect filter counts
     *
     * Invalidates count caches when alt text or optimization status changes.
     *
     * @since 1.0.0
     * @param int    $meta_id    Meta ID.
     * @param int    $post_id    Post ID.
     * @param string $meta_key   Meta key.
     * @param mixed  $meta_value Meta value (only for updated/added).
     */
    public function handle_meta_change_for_counts( $meta_id, $post_id, $meta_key, $meta_value = null ) {
        // Featured images are the one watched key where $post_id is the POST,
        // not the image — the attachment to reindex is named in the meta value.
        if ( '_thumbnail_id' === $meta_key ) {
            $this->handle_featured_image_change( $post_id, $meta_value );
            return;
        }

        // Only process meta keys that affect filter counts or search
        $watched_keys = [
            '_wp_attachment_image_alt',
            '_infyp_has_been_optimized',
        ];

        if ( in_array( $meta_key, $watched_keys, true ) ) {
            // Verify this is an image attachment
            if ( wp_attachment_is_image( $post_id ) ) {
                $this->invalidate_count_caches();

                // Also invalidate dashboard stats cache
                if ( class_exists( '\IPAIS\Cron\INFYP_Stats_Calculator' ) ) {
                    \IPAIS\Cron\INFYP_Stats_Calculator::invalidate_cache();
                }

                // Update search index so FULLTEXT search stays in sync
                if ( $meta_key === '_wp_attachment_image_alt' && class_exists( '\IPAIS\Database\INFYP_Database_Optimizer' ) ) {
                    \IPAIS\Database\INFYP_Database_Optimizer::update_image_indexes( $post_id );
                }
            }
        }
    }
    
    /**
     * Initialize the cache system on WordPress init.
     *
     * Registers global cache groups when a persistent external object cache
     * (Redis, Memcached) is available.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function init_cache_system() {
        // Register cache groups if persistent object cache is available
        if (wp_using_ext_object_cache()) {
            wp_cache_add_global_groups([
                self::CACHE_GROUP,
                self::CACHE_GROUP_QUERIES,
                self::CACHE_GROUP_API,
                self::CACHE_GROUP_IMAGES
            ]);
        }
    }
    
    /**
     * Get cached value with fallback to transients
     *
     * @param string $key Cache key
     * @param string $group Cache group
     * @return mixed|false
     */
    public function get($key, $group = self::CACHE_GROUP) {
        // Try object cache first
        $value = wp_cache_get($key, $group);
        
        if ($value === false && !wp_using_ext_object_cache()) {
            // Fallback to transients for non-persistent cache
            $value = get_transient($this->get_transient_key($key, $group));
        }
        
        return $value;
    }
    
    /**
     * Set cached value with fallback to transients
     *
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param string $group Cache group
     * @param int $expiration Expiration time in seconds
     * @return bool
     */
    public function set($key, $value, $group = self::CACHE_GROUP, $expiration = self::EXPIRE_HOUR) {
        // Set in object cache
        $result = wp_cache_set($key, $value, $group, $expiration);
        
        if (!wp_using_ext_object_cache()) {
            // Also set as transient for persistence
            set_transient($this->get_transient_key($key, $group), $value, $expiration);
        }
        
        return $result;
    }
    
    /**
     * Delete cached value
     *
     * @param string $key Cache key
     * @param string $group Cache group
     * @return bool
     */
    public function delete($key, $group = self::CACHE_GROUP) {
        $result = wp_cache_delete($key, $group);
        
        if (!wp_using_ext_object_cache()) {
            delete_transient($this->get_transient_key($key, $group));
        }
        
        return $result;
    }
    
    /**
     * Get or set cached value with callback
     *
     * @param string $key Cache key
     * @param callable $callback Function to generate value
     * @param string $group Cache group
     * @param int $expiration Expiration time
     * @return mixed
     */
	private static $buster_cache = [];

	public function remember($key, $callback, $group = self::CACHE_GROUP, $expiration = self::EXPIRE_HOUR) {
		// Check for cache buster (cached per-request to avoid repeated get_option calls)
		$cache_buster_key = 'infyp_cache_buster_' . $group;
		if ( ! isset( self::$buster_cache[ $group ] ) ) {
			self::$buster_cache[ $group ] = get_option( $cache_buster_key, 0 );
		}
		$cache_buster = self::$buster_cache[ $group ];

		// Modify key with cache buster
		$versioned_key = $key . '_v' . $cache_buster;

		$value = $this->get($versioned_key, $group);

		if ($value === false) {
			$value = call_user_func($callback);
			$this->set($versioned_key, $value, $group, $expiration);
		}

		return $value;
	}
    
    /**
     * Get image data from cache
     *
     * @param int $image_id
     * @return array|false
     */
    public function get_image_data($image_id) {
        $cache_key = 'image_' . $image_id;
        $data = $this->get($cache_key, self::CACHE_GROUP_IMAGES);
        
        if ($data === false) {
            // Build image data
            $data = [
                'url' => wp_get_attachment_url($image_id),
                'alt' => get_post_meta($image_id, '_wp_attachment_image_alt', true),
                'title' => get_the_title($image_id),
                'metadata' => wp_get_attachment_metadata($image_id),
                'usage' => $this->get_cached_image_usage($image_id)
            ];
            
            $this->set($cache_key, $data, self::CACHE_GROUP_IMAGES, self::EXPIRE_HOUR);
        }
        
        return $data;
    }
    
    /**
     * Get cached image usage information
     *
     * @param int $image_id
     * @return array
     */
    private function get_cached_image_usage($image_id) {
        $cache_key = 'usage_' . $image_id;
        
        return $this->remember($cache_key, function() use ($image_id) {
            // This would call the actual usage finder
            return \IPAIS\Core\INFYP_Image_Usage::find_usage($image_id);
        }, self::CACHE_GROUP_IMAGES, self::EXPIRE_HOUR * 2);
    }
    
    /**
     * Invalidate image cache
     *
     * @param int $image_id
     */
	public function invalidate_image_cache($image_id) {
		$this->delete('image_' . $image_id, self::CACHE_GROUP_IMAGES);
		$this->delete('usage_' . $image_id, self::CACHE_GROUP_IMAGES);

		// Invalidate count caches (optimization status or alt text may have changed)
		$this->invalidate_count_caches();

		// Clear image list caches to ensure table shows updated data
		$this->clear_image_list_cache($image_id);

		// Invalidate dashboard stats so next page load recalculates
		if ( class_exists( '\IPAIS\Cron\INFYP_Stats_Calculator' ) ) {
			\IPAIS\Cron\INFYP_Stats_Calculator::invalidate_cache();
		}
	}
    
    /**
     * Invalidate settings-related cache entries.
     *
     * Removes cached API key, post type, and optimization settings so the
     * next access fetches fresh values from the database.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function invalidate_settings_cache() {
        $this->delete('settings_api_keys', self::CACHE_GROUP);
        $this->delete('settings_post_types', self::CACHE_GROUP);
        $this->delete('settings_optimization', self::CACHE_GROUP);
    }

    /* =========================================================================
       TRANSIENT-BASED COUNT CACHING (Phase 1 Performance Optimization)
       Persistent caching for total counts and filter counts
       ========================================================================= */

    /**
     * Get cached total image count
     *
     * Returns the total number of images from cache, or calculates and caches it.
     * This eliminates expensive COUNT queries on every page load.
     *
     * @since 1.0.0
     * @return int Total image count
     */
    public function get_cached_total_count() {
        $has_cpts = self::has_extended_post_types();
        $cache_key = self::TRANSIENT_TOTAL_COUNT . ( $has_cpts ? '_extended' : '_base' );
        $count = get_transient( $cache_key );

        if ( $count === false ) {
            $count = $this->calculate_total_count( $has_cpts );
            set_transient( $cache_key, $count, self::EXPIRE_HOUR );
        }

        return (int) $count;
    }

    /**
     * Calculate total image count from database
     *
     * @since 1.0.0
     * @param bool $has_extended_types Whether extended post types are enabled
     * @return int Total count
     */
    private function calculate_total_count( $has_extended_types ) {
        global $wpdb;

        $mime_sql = infyp_get_mime_type_sql();

        // Base query for all images
        $sql = "
            SELECT COUNT(DISTINCT p.ID)
            FROM {$wpdb->posts} p
            WHERE p.post_type = 'attachment'
            AND p.post_status = 'inherit'
            AND p.post_mime_type IN ({$mime_sql})
        ";

        // When only base post types are enabled, restrict to those
        if ( ! $has_extended_types ) {
            // Check if lookup table exists
            $lookup_table = $wpdb->prefix . 'infyp_image_post_types';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $lookup_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $lookup_table ) ) ? true : false;

            if ( $lookup_exists ) {
                $sql = "
                    SELECT COUNT(DISTINCT p.ID)
                    FROM {$wpdb->posts} p
                    INNER JOIN `{$lookup_table}` ipt ON p.ID = ipt.image_id
                    WHERE p.post_type = 'attachment'
                    AND p.post_status = 'inherit'
                    AND p.post_mime_type IN ({$mime_sql})
                    AND ipt.post_type IN ('media', 'post', 'page')
                ";
            } else {
                // Fallback: include unattached, posts, pages
                $sql .= " AND (
                    p.post_parent = 0
                    OR p.post_parent IN (SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('post', 'page') AND post_status IN ('publish', 'draft', 'private'))
                    OR p.ID IN (SELECT pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} pp ON pm.post_id = pp.ID WHERE pm.meta_key = '_thumbnail_id' AND pp.post_type IN ('post', 'page') AND pp.post_status IN ('publish', 'draft', 'private'))
                )";
            }
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL built internally, $mime_sql from esc_sql()
        return (int) $wpdb->get_var( $sql );
    }

    /**
     * Get cached filter counts
     *
     * Returns all filter counts from cache, or calculates and caches them.
     * Single query for all counts, avoiding multiple COUNT queries.
     *
     * @since 1.0.0
     * @return array Filter counts ['total' => int, 'missing_alt' => int, 'optimized' => int, 'not_optimized' => int]
     */
    public function get_cached_filter_counts() {
        $has_cpts = self::has_extended_post_types();
        $cache_key = self::TRANSIENT_FILTER_COUNTS . ( $has_cpts ? '_extended' : '_base' );
        $counts = get_transient( $cache_key );

        if ( $counts === false ) {
            $counts = $this->calculate_filter_counts( $has_cpts );
            set_transient( $cache_key, $counts, self::EXPIRE_HOUR );
        }

        return $counts;
    }

    /**
     * Calculate all filter counts in a single query
     *
     * @since 1.0.0
     * @param bool $has_extended_types Whether extended post types are enabled
     * @return array Filter counts
     */
    private function calculate_filter_counts( $has_extended_types ) {
        global $wpdb;

        $mime_sql = infyp_get_mime_type_sql();

        // Check if lookup table exists
        $lookup_table = $wpdb->prefix . 'infyp_image_post_types';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $lookup_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $lookup_table ) ) ? true : false;

        // Build FROM and WHERE based on version and lookup table
        $from = "FROM {$wpdb->posts} p";
        $where = "WHERE p.post_type = 'attachment'
                  AND p.post_status = 'inherit'
                  AND p.post_mime_type IN ({$mime_sql})";

        if ( ! $has_extended_types ) {
            if ( $lookup_exists ) {
                $from .= " INNER JOIN `{$lookup_table}` ipt ON p.ID = ipt.image_id";
                $where .= " AND ipt.post_type IN ('media', 'post', 'page')";
            } else {
                $where .= " AND (
                    p.post_parent = 0
                    OR p.post_parent IN (SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('post', 'page') AND post_status IN ('publish', 'draft', 'private'))
                    OR p.ID IN (SELECT pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} pp ON pm.post_id = pp.ID WHERE pm.meta_key = '_thumbnail_id' AND pp.post_type IN ('post', 'page') AND pp.post_status IN ('publish', 'draft', 'private'))
                )";
            }
        }

        // Single query to get all counts
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query parts are built safely from hardcoded strings and $wpdb table names
        $result = $wpdb->get_row( "
            SELECT
                COUNT(DISTINCT p.ID) as total,
                COUNT(DISTINCT CASE WHEN pm_alt.meta_value IS NULL OR pm_alt.meta_value = '' THEN p.ID END) as missing_alt,
                COUNT(DISTINCT CASE WHEN pm_opt.meta_value = '1' THEN p.ID END) as optimized,
                COUNT(DISTINCT CASE WHEN pm_opt.meta_value IS NULL OR pm_opt.meta_value != '1' THEN p.ID END) as not_optimized
            {$from}
            LEFT JOIN {$wpdb->postmeta} pm_alt ON p.ID = pm_alt.post_id AND pm_alt.meta_key = '_wp_attachment_image_alt'
            LEFT JOIN {$wpdb->postmeta} pm_opt ON p.ID = pm_opt.post_id AND pm_opt.meta_key = '_infyp_has_been_optimized'
            {$where}
        ", ARRAY_A );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        return [
            'total'         => (int) ( $result['total'] ?? 0 ),
            'missing_alt'   => (int) ( $result['missing_alt'] ?? 0 ),
            'optimized'     => (int) ( $result['optimized'] ?? 0 ),
            'not_optimized' => (int) ( $result['not_optimized'] ?? 0 ),
        ];
    }

    /**
     * Invalidate count caches
     *
     * Called when images are added, deleted, or optimized.
     *
     * @since 1.0.0
     */
    public function invalidate_count_caches() {
        delete_transient( self::TRANSIENT_TOTAL_COUNT . '_extended' );
        delete_transient( self::TRANSIENT_TOTAL_COUNT . '_base' );
        delete_transient( self::TRANSIENT_FILTER_COUNTS . '_extended' );
        delete_transient( self::TRANSIENT_FILTER_COUNTS . '_base' );
    }

    /**
     * Get cached count for a specific filter
     *
     * Convenience method to get a single filter count from the cached counts.
     *
     * @since 1.0.0
     * @param string $filter Filter name (total, missing_alt, optimized, not_optimized)
     * @return int Count for the specified filter
     */
    public function get_cached_count_for_filter( $filter ) {
        $counts = $this->get_cached_filter_counts();
        return (int) ( $counts[ $filter ] ?? 0 );
    }
    
    /**
     * Clear all plugin caches across every cache group.
     *
     * Flushes object cache groups and removes transients when a persistent
     * object cache is not in use.
     *
     * @since 1.0.0
     *
     * @global wpdb $wpdb WordPress database abstraction object.
     * @return void
     */
    public function clear_all_cache() {
        $groups = [
            self::CACHE_GROUP,
            self::CACHE_GROUP_QUERIES,
            self::CACHE_GROUP_API,
            self::CACHE_GROUP_IMAGES
        ];
        
        foreach ($groups as $group) {
            $this->flush_group($group);
        }
        
        // Clear transients if not using object cache
        if (!wp_using_ext_object_cache()) {
            global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cache cleanup operation
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                    $wpdb->esc_like('_transient_infyp_') . '%',
                    $wpdb->esc_like('_transient_timeout_infyp_') . '%'
                )
            );
        }
    }
    
    /**
     * Flush an entire cache group.
     *
     * Uses wp_cache_flush_group() when available, otherwise increments
     * a cache buster option to effectively invalidate all keys in the group.
     *
     * @since 1.0.0
     *
     * @param string $group Cache group identifier to flush.
     * @return void
     */
	public function flush_group($group) {
		if (function_exists('wp_cache_flush_group')) {
			wp_cache_flush_group($group);
		} else {
			// Improved fallback
			$cache_buster_key = 'infyp_cache_buster_' . $group;
			update_option($cache_buster_key, time(), false);
		}
	}
    
    /**
     * Get transient key for fallback caching
     *
     * @param string $key
     * @param string $group
     * @return string
     */
    private function get_transient_key($key, $group) {
        return 'infyp_' . substr(md5($group . '_' . $key), 0, 20);
    }
    
    /**
     * Warm cache with frequently accessed data.
     *
     * Pre-populates the cache with post type settings and API key
     * availability so subsequent requests avoid database lookups.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function warm_cache() {
        // Cache post type settings
        $this->remember('settings_post_types', function() {
            return get_option('infyp_post_type_settings', ['default' => '1']);
        }, self::CACHE_GROUP, self::EXPIRE_DAY);

        // Cache API keys availability (dynamic — includes custom providers)
        $this->remember('api_keys_status', function() {
            $keys = get_option('infyp_api_keys', []);
            $status = [];
            foreach ( $keys as $provider => $key ) {
                $status[ $provider ] = ! empty( $key );
            }
            return $status;
        }, self::CACHE_GROUP, self::EXPIRE_HOUR);
    }

    /**
     * Warm table data cache for first-time visitors
     *
     * Pre-populates cache with common filter/search combinations to eliminate
     * slow first-load times. Called async on first admin page visit.
     *
     * @since 1.0.0
     * @return bool True if warming was performed, false if skipped
     */
    public function warm_table_data_cache() {
        // Check if already warmed recently (avoid duplicate work)
        $last_warmed = get_transient( 'infyp_table_cache_warmed' );
        if ( $last_warmed ) {
            return false;
        }

        // Set a brief lock to prevent concurrent warming
        set_transient( 'infyp_table_cache_warmed', time(), 5 * MINUTE_IN_SECONDS );

        // Create table instance for cache warming
        if ( ! class_exists( '\IPAIS\Tables\INFYP_Image_List_Table' ) ) {
            require_once INFYP_PLUGIN_DIR . 'includes/Tables/INFYP_Image_List_Table.php';
        }

        $table = new \IPAIS\Tables\INFYP_Image_List_Table();

        // Define common filter combinations to pre-cache
        // These represent the most frequent user interactions
        $filter_combinations = array(
            // Default view (All images, lowest SEO score first)
            array( 'filter' => 'all', 'search' => '', 'orderby' => 'seo_score', 'order' => 'asc' ),
            // Common filters users click
            array( 'filter' => 'post', 'search' => '', 'orderby' => 'seo_score', 'order' => 'asc' ),
            array( 'filter' => 'page', 'search' => '', 'orderby' => 'seo_score', 'order' => 'asc' ),
            array( 'filter' => 'media', 'search' => '', 'orderby' => 'seo_score', 'order' => 'asc' ),
        );

        foreach ( $filter_combinations as $combo ) {
            // Pass filter directly to get_images() instead of polluting $_REQUEST
            // This prevents state leakage if warming runs during a real user request
            $table->get_images( 20, 1, $combo['search'], $combo['orderby'], $combo['order'], null, $combo['filter'] );
        }

        return true;
    }

    /**
     * Check if table cache needs warming
     *
     * @since 1.0.0
     * @return bool True if cache needs warming
     */
    public function needs_table_cache_warming() {
        // Check if default view is cached
        $cache_key = 'image_list_' . md5( serialize( array(
            'search'   => '',
            'page'     => 1,
            'per_page' => 20,
            'orderby'  => 'date',
            'order'    => 'desc',
            'filter'   => 'all',
        ) ) );

        $cached = $this->get( $cache_key, self::CACHE_GROUP_QUERIES );
        return $cached === false;
    }
    
    /**
     * Get cache statistics for all plugin cache groups.
     *
     * Returns whether a persistent object cache is in use and per-group
     * hit/miss statistics.
     *
     * @since 1.0.0
     *
     * @return array {
     *     @type bool  $using_object_cache Whether an external object cache is active.
     *     @type array $groups             Per-group statistics arrays.
     * }
     */
    public function get_stats() {
        $stats = [
            'using_object_cache' => wp_using_ext_object_cache(),
            'groups' => [
                self::CACHE_GROUP => $this->get_group_stats(self::CACHE_GROUP),
                self::CACHE_GROUP_QUERIES => $this->get_group_stats(self::CACHE_GROUP_QUERIES),
                self::CACHE_GROUP_API => $this->get_group_stats(self::CACHE_GROUP_API),
                self::CACHE_GROUP_IMAGES => $this->get_group_stats(self::CACHE_GROUP_IMAGES),
            ]
        ];
        
        return $stats;
    }
    
    /**
     * Get hit/miss statistics for a single cache group.
     *
     * @since 1.0.0
     * @access private
     *
     * @param string $group Cache group identifier.
     * @return array {
     *     @type int $hits   Number of cache hits.
     *     @type int $misses Number of cache misses.
     * }
     */
    private function get_group_stats($group) {
        // This is a simplified version - actual implementation would depend on cache backend
        return [
            'hits' => 0,
            'misses' => 0,
            'size' => 0
        ];
    }
    
    /**
     * Handle new attachment upload - clear relevant caches
     *
     * @param int $attachment_id
     * @since 1.0.0
     */
	public function handle_new_attachment($attachment_id) {
		if (!wp_attachment_is_image($attachment_id)) {
			return;
		}

		// The upload completes when its metadata, sub-sizes included, is generated.
		update_post_meta( (int) $attachment_id, self::UPLOAD_PENDING_META, time() );

		// Invalidate count caches (new image affects totals)
		$this->invalidate_count_caches();

		// Clear image list related caches
		$this->clear_image_list_caches();

		// Update search/filter indexes for the new image
		if (class_exists('\IPAIS\Database\INFYP_Database_Optimizer')) {
			\IPAIS\Database\INFYP_Database_Optimizer::update_image_indexes($attachment_id);
		}

		// Invalidate dashboard stats cache (new image affects stats)
		if (class_exists('\IPAIS\Cron\INFYP_Stats_Calculator')) {
			\IPAIS\Cron\INFYP_Stats_Calculator::invalidate_cache();
		}
	}

	/**
	 * Handle generated attachment metadata.
	 *
	 * Runs on every `wp_generate_attachment_metadata` call for an image: the
	 * first request of an upload, the request that completes the sub-sizes of
	 * a client-side upload, and any later regeneration (rename, thumbnail
	 * regeneration). Writes the SEO score each time, so the list shows the
	 * image with its score from the first request on. Completes a pending
	 * upload; see maybe_complete_upload().
	 *
	 * @since 1.0.0
	 * @since 1.0.9 Fires infyp_after_image_upload only for a pending upload,
	 *              once, after the completed metadata is saved.
	 * @param array $metadata      Attachment metadata.
	 * @param int   $attachment_id Attachment ID.
	 * @return array Unchanged metadata.
	 */
	public function handle_attachment_metadata_generated( $metadata, $attachment_id ) {
		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return $metadata;
		}

		$metadata = $this->maybe_complete_upload( (int) $attachment_id, $metadata );

		// Calculate initial SEO score so the image list table shows the correct
		// score immediately instead of 0% until the user interacts with a modal.
		if ( class_exists( '\IPAIS\Core\INFYP_SEO_Score_Calculator' ) ) {
			INFYP_SEO_Score_Calculator::calculate_score( $attachment_id );
		}

		return $metadata;
	}

	/**
	 * Record a REST upload whose sub-sizes the browser sends later.
	 *
	 * With client-side media processing the browser creates the attachment
	 * with `generate_sub_sizes=false`, uploads each sub-size separately and
	 * then asks the server to store them, which generates the metadata again.
	 * The metadata generated while the attachment is created carries no
	 * sub-sizes, so it does not complete the upload.
	 *
	 * Only when client-side media processing is enabled on this site: other
	 * REST clients may send `generate_sub_sizes=false` without ever storing
	 * sub-sizes later, and their upload completes in the create request. A
	 * client that sends it while processing is enabled but never finishes
	 * the upload is treated like an abandoned browser upload: the action
	 * does not fire, and the image counts as complete once the marker expires.
	 *
	 * Hooked to `rest_insert_attachment`.
	 *
	 * @since 1.0.9
	 *
	 * @param mixed $attachment Inserted or updated attachment (WP_Post).
	 * @param mixed $request    Request object (WP_REST_Request).
	 * @param bool  $creating   True when the attachment is created.
	 * @return void
	 */
	public function note_client_side_processing( $attachment, $request, $creating ) {
		if ( ! $creating || ! ( $attachment instanceof \WP_Post ) || ! ( $request instanceof \WP_REST_Request ) ) {
			return;
		}

		if ( false !== $request->get_param( 'generate_sub_sizes' ) ) {
			return;
		}

		if ( ! function_exists( 'wp_is_client_side_media_processing_enabled' ) || ! wp_is_client_side_media_processing_enabled() ) {
			return;
		}

		if ( self::is_upload_pending( $attachment->ID ) ) {
			self::$awaiting_client_sub_sizes[ self::request_key( $attachment->ID ) ] = true;
		}
	}

	/**
	 * Complete a pending upload when its metadata is generated.
	 *
	 * Not complete: an attachment that is not pending (an existing image whose
	 * metadata is regenerated), metadata regenerated by our own renamer, and
	 * the first metadata of a client-side upload whose sub-sizes follow.
	 * Otherwise the upload is complete and infyp_after_image_upload fires as
	 * soon as the metadata is saved. When the saved metadata already matches,
	 * the caller's save changes nothing: the action fires at once and the
	 * metadata as the callbacks left it is returned, so that save cannot undo
	 * a change they made.
	 *
	 * @since 1.0.9
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param mixed $metadata      Generated metadata.
	 * @return mixed The metadata to pass on.
	 */
	private function maybe_complete_upload( $attachment_id, $metadata ) {
		$key = self::request_key( $attachment_id );

		if ( isset( self::$regenerating[ $key ] ) || isset( self::$awaiting_client_sub_sizes[ $key ] ) ) {
			return $metadata;
		}

		// One cached meta read for an existing image.
		if ( isset( self::$completed_uploads[ $key ] ) || ! self::is_upload_pending( $attachment_id ) ) {
			return $metadata;
		}

		if ( is_array( $metadata ) && wp_get_attachment_metadata( $attachment_id, true ) === $metadata ) {
			$this->fire_upload_complete( $attachment_id, $metadata );

			$saved = wp_get_attachment_metadata( $attachment_id, true );
			return ! empty( $saved ) ? $saved : $metadata;
		}

		self::$completed_uploads[ $key ] = $attachment_id;

		// Safety net for a caller that generates metadata without saving it.
		if ( ! has_action( 'shutdown', [ $this, 'fire_unsaved_completed_uploads' ] ) ) {
			add_action( 'shutdown', [ $this, 'fire_unsaved_completed_uploads' ] );
		}

		return $metadata;
	}

	/**
	 * Fire infyp_after_image_upload once the completed metadata is saved.
	 *
	 * Hooked to `added_post_meta` and `updated_post_meta`.
	 *
	 * @since 1.0.9
	 *
	 * @param int    $meta_id    Meta ID.
	 * @param int    $object_id  Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Saved value.
	 * @return void
	 */
	public function handle_attachment_metadata_saved( $meta_id, $object_id, $meta_key, $meta_value = null ) {
		if ( '_wp_attachment_metadata' !== $meta_key || empty( self::$completed_uploads ) ) {
			return;
		}

		$attachment_id = (int) $object_id;
		if ( ! isset( self::$completed_uploads[ self::request_key( $attachment_id ) ] ) ) {
			return;
		}

		$this->fire_upload_complete(
			$attachment_id,
			is_array( $meta_value ) ? $meta_value : wp_get_attachment_metadata( $attachment_id )
		);
	}

	/**
	 * Fire infyp_after_image_upload for completed uploads whose metadata was
	 * never saved in this request.
	 *
	 * Hooked to `shutdown` only when such an upload exists.
	 *
	 * @since 1.0.9
	 *
	 * @return void
	 */
	public function fire_unsaved_completed_uploads() {
		foreach ( self::$completed_uploads as $key => $attachment_id ) {
			$blog_id  = (int) strtok( (string) $key, ':' );
			$switched = false;
			if ( is_multisite() && get_current_blog_id() !== $blog_id ) {
				switch_to_blog( $blog_id );
				$switched = true;
			}

			$this->fire_upload_complete( (int) $attachment_id, wp_get_attachment_metadata( $attachment_id ) );

			if ( $switched ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * Clear the pending marker and fire infyp_after_image_upload.
	 *
	 * @since 1.0.9
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param mixed $metadata      Saved attachment metadata.
	 * @return void
	 */
	private function fire_upload_complete( $attachment_id, $metadata ) {
		unset( self::$completed_uploads[ self::request_key( $attachment_id ) ] );
		delete_post_meta( $attachment_id, self::UPLOAD_PENDING_META );

		/**
		 * Fires once per uploaded image, when the upload is complete.
		 *
		 * Complete means the original and every sub-size file exist and are
		 * listed in the saved attachment metadata. This holds for uploads
		 * whose sub-sizes the server creates and for uploads whose sub-sizes
		 * the browser creates and sends in later requests: the action fires
		 * in the request that stores the last of them. It does not fire when
		 * the metadata of an existing image is generated again (renaming the
		 * file, regenerating thumbnails), and not for an upload the browser
		 * abandoned before it was complete.
		 *
		 * Renaming the file or changing the metadata from a callback is safe:
		 * the metadata is saved before the action fires.
		 *
		 * @since 1.0.0
		 * @since 1.0.9 Fires exactly once per upload, after the metadata is saved.
		 *
		 * @param int   $attachment_id The attachment ID of the newly uploaded image.
		 * @param array $metadata      The saved attachment metadata.
		 */
		do_action( 'infyp_after_image_upload', $attachment_id, is_array( $metadata ) ? $metadata : [] );
	}

	/**
	 * Whether an image upload is still waiting for its sub-sizes.
	 *
	 * True from the creation of the attachment until infyp_after_image_upload
	 * has fired, or until the marker of an abandoned upload expires. False for
	 * every other attachment. Public callers use infyp_is_upload_complete().
	 *
	 * @since 1.0.9
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public static function is_upload_pending( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		if ( $attachment_id < 1 ) {
			return false;
		}

		$created = (int) get_post_meta( $attachment_id, self::UPLOAD_PENDING_META, true );

		return $created > 0 && ( time() - $created ) < self::UPLOAD_PENDING_TTL;
	}

	/**
	 * Delete upload-pending markers that have expired.
	 *
	 * Expired markers already count as complete when read; this removes the
	 * rows. Called by the daily maintenance.
	 *
	 * @since 1.0.9
	 *
	 * @return int Number of rows deleted.
	 */
	public static function delete_expired_upload_markers() {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Bulk cleanup of expired markers; read paths treat them as expired already.
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s AND CAST( meta_value AS UNSIGNED ) < %d",
				self::UPLOAD_PENDING_META,
				time() - self::UPLOAD_PENDING_TTL
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key

		return (int) $deleted;
	}

	/**
	 * Key for the per-request upload state: blog ID and attachment ID.
	 *
	 * @since 1.0.9
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	private static function request_key( $attachment_id ) {
		return get_current_blog_id() . ':' . (int) $attachment_id;
	}

	/**
	 * Mark the start of a metadata regeneration for an existing file.
	 *
	 * Metadata generated between this call and end_metadata_regeneration()
	 * never completes an upload. Calls nest.
	 *
	 * @internal Used by INFYP_File_Renamer.
	 * @since 1.0.9
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	public static function begin_metadata_regeneration( $attachment_id ) {
		$key = self::request_key( $attachment_id );

		self::$regenerating[ $key ] = ( self::$regenerating[ $key ] ?? 0 ) + 1;
	}

	/**
	 * Mark the end of a metadata regeneration started with
	 * begin_metadata_regeneration().
	 *
	 * @internal Used by INFYP_File_Renamer.
	 * @since 1.0.9
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	public static function end_metadata_regeneration( $attachment_id ) {
		$key = self::request_key( $attachment_id );

		if ( ! isset( self::$regenerating[ $key ] ) ) {
			return;
		}

		--self::$regenerating[ $key ];
		if ( self::$regenerating[ $key ] < 1 ) {
			unset( self::$regenerating[ $key ] );
		}
	}
    
    /**
     * Handle cache invalidation on attachment update.
     *
     * Invalidates image-specific caches, query caches, and updates search
     * indexes when an existing image attachment is modified.
     *
     * @since 1.0.0
     *
     * @param int $attachment_id The attachment ID being updated.
     * @return void
     */
    public function handle_attachment_update($attachment_id) {
        if (!wp_attachment_is_image($attachment_id)) {
            return;
        }

        // Invalidate specific caches
        $this->invalidate_image_cache($attachment_id);

        // Clear related query caches
        $this->delete('recent_images', self::CACHE_GROUP_QUERIES);
        $this->delete('image_stats', self::CACHE_GROUP_QUERIES);

        // Update search/filter indexes for the updated image
        if (class_exists('\IPAIS\Database\INFYP_Database_Optimizer')) {
            \IPAIS\Database\INFYP_Database_Optimizer::update_image_indexes($attachment_id);
        }
    }
    
    /**
     * Handle attachment deletion to clear caches
     *
     * OPTIMIZED: More granular cache invalidation to avoid flushing entire groups
     *
     * @param int $attachment_id The attachment ID being deleted
     */
    public function handle_attachment_delete($attachment_id) {
        // An upload deleted before it completed never completes. Its marker
        // is post meta and goes with the attachment.
        $upload_key = self::request_key( $attachment_id );
        unset( self::$completed_uploads[ $upload_key ], self::$awaiting_client_sub_sizes[ $upload_key ] );

        // Invalidate count caches (deleted image affects totals)
        $this->invalidate_count_caches();

        // Clear specific image caches (not the entire group)
        $this->invalidate_image_cache($attachment_id);

        // Clear usage cache for this image
        $this->delete('image_usage_' . $attachment_id, self::CACHE_GROUP_IMAGES);
        wp_cache_delete('infyp_image_usage_' . $attachment_id, 'infyp');

        // Clear query caches that might list this image
        $this->delete('recent_images', self::CACHE_GROUP_QUERIES);
        $this->delete('image_stats', self::CACHE_GROUP_QUERIES);

        // Remove image from search/filter indexes
        if (class_exists('\IPAIS\Database\INFYP_Database_Optimizer')) {
            \IPAIS\Database\INFYP_Database_Optimizer::remove_image_from_indexes($attachment_id);
        }

        // Invalidate the dashboard stats cache (will recalculate on next cron)
        if (class_exists('\IPAIS\Cron\INFYP_Stats_Calculator')) {
            \IPAIS\Cron\INFYP_Stats_Calculator::invalidate_cache();
        }

        // Update cache buster for image list queries (more targeted than full flush)
        $cache_buster_key = 'infyp_cache_buster_' . self::CACHE_GROUP_QUERIES;
        update_option($cache_buster_key, time(), false);
    }
	
	/**
	 * Clear all image list query caches.
	 *
	 * Uses multiple strategies (group flush, key pattern deletion, transient
	 * cleanup, cache buster) to ensure stale image list data is purged across
	 * all cache backends.
	 *
	 * @since 1.0.0
	 *
	 * @global wpdb $wpdb WordPress database abstraction object.
	 * @return void
	 */
	public function clear_image_list_caches() {
		global $wpdb;

		// Method 1: Try to flush the entire group
		$this->flush_group(self::CACHE_GROUP_QUERIES);

		// Method 2: Delete specific known non-versioned cache keys.
		if (wp_using_ext_object_cache()) {
			wp_cache_delete('recent_images', self::CACHE_GROUP_QUERIES);
			wp_cache_delete('image_stats', self::CACHE_GROUP_QUERIES);
		}

		// Clear the all image IDs cache from our AJAX handler
		wp_cache_delete('infyp_all_image_ids', 'infyp_image_data');

		// Method 3: Clear transients (for non-persistent cache)
		if (!wp_using_ext_object_cache()) {
			// Clear all query-related transients
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cache cleanup operation
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} 
					 WHERE option_name LIKE %s 
					 OR option_name LIKE %s",
					$wpdb->esc_like('_transient_infyp_' . substr(md5(self::CACHE_GROUP_QUERIES . '_'), 0, 10)) . '%',
					$wpdb->esc_like('_transient_timeout_infyp_' . substr(md5(self::CACHE_GROUP_QUERIES . '_'), 0, 10)) . '%'
				)
			);
		}

		// Method 4: Force cache invalidation by setting a global cache buster
		$cache_buster_key = 'infyp_cache_buster_' . self::CACHE_GROUP_QUERIES;
		$current_time = time();
		update_option($cache_buster_key, $current_time, false);
	}
	
	/**
	 * Clear image-related query caches.
	 *
	 * Updates the cache buster and purges image list transients or object
	 * cache entries for a specific image or all images.
	 *
	 * @since 1.0.0
	 *
	 * @global wpdb $wpdb WordPress database abstraction object.
	 * @param int|null $image_id Optional. Specific image attachment ID. Default null (all).
	 * @return void
	 */
	public function clear_image_list_cache($image_id = null) {
		global $wpdb;

		// IMPORTANT: Update the cache buster to invalidate all versioned keys
		$cache_buster_key = 'infyp_cache_buster_' . self::CACHE_GROUP_QUERIES;
		update_option($cache_buster_key, time(), false);

		// Drop the per-request memo too, or remember() keeps serving the old
		// version for the rest of this request.
		unset( self::$buster_cache[ self::CACHE_GROUP_QUERIES ] );

		// Clear all image list caches
		if (wp_using_ext_object_cache()) {
			// For object cache, flush the entire group
			$this->flush_group(self::CACHE_GROUP_QUERIES);
		} else {
			// For transients, delete by pattern
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cache cleanup operation
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options}
					 WHERE option_name LIKE %s
					 AND option_name LIKE %s",
					$wpdb->esc_like( '_transient_infyp_' ) . '%',
					'%' . $wpdb->esc_like( 'image_list_' ) . '%'
				)
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cache cleanup operation
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options}
					 WHERE option_name LIKE %s
					 AND option_name LIKE %s",
					$wpdb->esc_like( '_transient_infyp_' ) . '%',
					'%' . $wpdb->esc_like( 'image_count_' ) . '%'
				)
			);
		}
	}
}