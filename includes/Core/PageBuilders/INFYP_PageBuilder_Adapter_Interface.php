<?php
/**
 * Page Builder Adapter Interface - Contract for Page Builder Implementations
 *
 * This interface defines the contract that all page builder adapters must implement
 * to ensure consistent behavior across different page builder systems. It follows
 * the Adapter design pattern to provide a uniform interface for handling URL updates
 * in various page builder data structures.
 *
 * The interface ensures that all adapters:
 * - Can detect their activation status
 * - Provide human-readable identification
 * - Process URL updates in their specific format
 * - Clear their caches appropriately
 * - Validate data integrity after updates
 * - Provide processing statistics
 *
 * Implementing this interface allows new page builder support to be added without
 * modifying core plugin logic, making the system highly extensible.
 *
 * @package   IPAIS\Core\PageBuilders
 * @since 1.0.0
 * @author    InfyPress
 * @license   GPL-2.0-or-later
 */

namespace IPAIS\Core\PageBuilders;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Page Builder Adapter Interface
 *
 * Defines the contract for page builder adapters implementing URL update
 * functionality. All page builder adapters must implement this interface
 * to ensure consistent behavior and enable the Manager class to work with
 * them uniformly.
 *
 * Design Pattern: Adapter Pattern
 * - Each page builder (Gutenberg, Elementor, etc.) has unique data structures
 * - This interface abstracts those differences behind a common contract
 * - The Manager can work with any adapter without knowing implementation details
 *
 * Implementation Requirements:
 * - Must extend INFYP_PageBuilder_Base_Adapter (recommended) or implement all methods
 * - Must handle builder-specific data formats (JSON, serialized, blocks, etc.)
 * - Must provide safe URL replacement without corrupting data structures
 * - Must implement proper error handling and logging
 * - Must track statistics for monitoring and debugging
 *
 * Usage Example (Creating a Custom Adapter):
 * ```php
 * class INFYP_Divi_Adapter extends INFYP_PageBuilder_Base_Adapter {
 *     public function is_active() {
 *         return defined('ET_BUILDER_VERSION');
 *     }
 *
 *     public function get_name() {
 *         return 'Divi Builder';
 *     }
 *
 *     public function process_url_updates($url_map) {
 *         // Divi-specific implementation
 *         // ...
 *         return ['updated_posts' => $updated, 'errors' => $errors];
 *     }
 *
 *     // Implement other required methods...
 * }
 *
 * // Register via filter
 * add_filter('infyp_register_pagebuilder_adapters', function($adapters) {
 *     $adapters['divi'] = new INFYP_Divi_Adapter();
 *     return $adapters;
 * });
 * ```
 *
 * @package   IPAIS\Core\PageBuilders
 * @since 1.0.0
 *
 * @see       INFYP_PageBuilder_Base_Adapter For base implementation
 * @see       INFYP_PageBuilder_Manager For adapter coordination
 */
interface INFYP_PageBuilder_Adapter_Interface {

    /**
     * Check if this page builder is active and available
     *
     * Determines whether the page builder is installed, activated, and
     * ready to process URL updates. This method is called before any
     * processing to avoid errors with inactive builders.
     *
     * Implementation Notes:
     * - Check for builder-specific constants (e.g., ELEMENTOR_VERSION)
     * - Check for builder-specific classes (e.g., \Elementor\Plugin)
     * - Optionally check if any posts use this builder's data
     * - Should be lightweight as it's called frequently
     *
     * @since 1.0.0
     *
     * @return bool True if the page builder is active and ready, false otherwise.
     */
    public function is_active();

    /**
     * Get the human-readable display name of this page builder
     *
     * Returns a friendly name for the page builder used in logs, UI,
     * and statistics. Should be consistent across all calls.
     *
     * @since 1.0.0
     *
     * @return string The human-readable name (e.g., 'Elementor', 'Gutenberg Block Editor').
     */
    public function get_name();

    /**
     * Process URL updates for this page builder's content
     *
     * Main processing method that handles URL replacement in the builder's
     * specific data format. Should process all posts using this builder,
     * replace URLs according to the map, and return results.
     *
     * Implementation Requirements:
     * - Query for posts containing builder data
     * - Parse builder-specific data format (JSON, blocks, etc.)
     * - Replace URLs safely without corrupting structure
     * - Validate data integrity before saving
     * - Handle errors gracefully
     * - Update statistics
     * - Log processing activity
     *
     * @since 1.0.0
     *
     * @param array $url_map Associative array mapping old URLs to new URLs.
     *                       Format: ['old_url' => 'new_url', ...]
     *
     * @return array {
     *     Processing results.
     *
     *     @type array $updated_posts Array of post IDs that were successfully updated.
     *     @type array $errors        Array of error messages encountered during processing.
     * }
     */
    public function process_url_updates($url_map);

    /**
     * Clear page builder caches after updates
     *
     * Clears any caches maintained by the page builder to ensure updated
     * URLs are reflected immediately. This may include generated CSS,
     * compiled JavaScript, precompiled templates, etc.
     *
     * Implementation Notes:
     * - Clear builder-specific caches (e.g., Elementor CSS files)
     * - Clear WordPress post caches for updated posts
     * - Trigger cache regeneration if needed
     * - Handle cases where cache clearing fails gracefully
     *
     * @since 1.0.0
     *
     * @param array $updated_post_ids Optional. Array of post IDs that were updated.
     *                                If empty, should clear global caches only.
     *
     * @return bool True on success, false on failure.
     */
    public function clear_cache($updated_post_ids = []);

    /**
     * Validate page builder data integrity after updates
     *
     * Verifies that the page builder data is still valid and intact after
     * URL updates have been applied. Should check data structure, format,
     * and parsability.
     *
     * Implementation Notes:
     * - Verify data can be parsed/decoded
     * - Check required structure elements exist
     * - Validate data can be re-encoded
     * - Should NOT modify data, only validate
     * - Log validation failures for debugging
     *
     * @since 1.0.0
     *
     * @param int $post_id The post ID to validate.
     *
     * @return bool True if data is valid and intact, false if corrupted.
     */
    public function validate_data($post_id);

    /**
     * Get processing statistics for this page builder
     *
     * Returns statistics about the most recent processing operation,
     * including counts, timing, and performance metrics. Used for
     * monitoring, debugging, and displaying results to users.
     *
     * @since 1.0.0
     *
     * @return array {
     *     Statistics array with processing metrics.
     *
     *     @type int   $posts_scanned   Number of posts scanned.
     *     @type int   $posts_updated   Number of posts actually updated.
     *     @type int   $urls_replaced   Total number of URL replacements made.
     *     @type int   $errors          Number of errors encountered.
     *     @type float $start_time      Processing start timestamp.
     *     @type float $end_time        Processing end timestamp.
     *     @type float $duration        Total processing time in seconds.
     *     @type int   $memory_peak     Peak memory usage in bytes.
     * }
     */
    public function get_stats();
}
