<?php
/**
 * Changelog Database Table - Change History and Audit Log
 *
 * This class manages a custom WordPress database table that logs all changes
 * made to image metadata by the plugin. It provides a complete audit trail
 * of modifications including:
 *
 * - Who made the change (user_id)
 * - When it was made (timestamp)
 * - Which image was affected (image_id)
 * - What field was changed (field_changed)
 * - Old and new values (old_value, new_value)
 * - How it was changed (ai_model, context)
 *
 * Table Schema:
 * - id: Auto-increment primary key
 * - timestamp: MySQL datetime when change occurred
 * - user_id: WordPress user ID who made the change
 * - image_id: Attachment ID that was modified
 * - field_changed: Field name (Title, Alt Text, Filename, Keywords)
 * - old_value: Previous value (TEXT)
 * - new_value: Updated value (TEXT)
 * - ai_model: AI provider used (NULL for manual changes)
 * - context: Additional context (Bulk Optimizer, Manual Edit, etc.)
 *
 * Use Cases:
 * - Audit trail for compliance
 * - Undo functionality (future feature)
 * - Analytics and reporting
 * - Debugging and troubleshooting
 * - A/B testing of AI models
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
 * Changelog Database Management Class
 *
 * Handles creation, schema management, and CRUD operations for the
 * changelog custom database table. Provides logging for all metadata changes.
 *
 * Table Name: {$wpdb->prefix}infyp_changelog
 *
 * Data Retention:
 * - No automatic deletion (preserves full history)
 * - Admin can manually clear via Debug page
 * - Consider implementing retention policy in future
 *
 * Performance Considerations:
 * - Table can grow large over time
 * - Primary key on id for fast lookups
 * - Consider adding indexes on image_id and timestamp
 * - Batch inserts not currently implemented (could improve)
 *
 * @package   IPAIS\Database
 * @since 1.0.0
 *
 * @see       INFYP_Changelog_List_Table For admin display
 * @see       dbDelta() WordPress schema management function
 */
class INFYP_Changelog {

    /**
     * Get the full changelog table name with WordPress prefix
     *
     * @since 1.0.0
     *
     * @return string Full table name including prefix.
     */
    private static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'infyp_changelog';
    }

    /**
     * Creates the custom database table during plugin activation.
     *
     * This method defines the schema for the changelog table and uses the dbDelta
     * function to create or update the table structure in the WordPress database.
     *
     * @since 1.0.0
     */
    public static function on_activation() {
        global $wpdb;
        $table_name = self::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            timestamp datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            user_id bigint(20) UNSIGNED NOT NULL,
            image_id bigint(20) UNSIGNED NOT NULL,
            field_changed varchar(255) NOT NULL,
            old_value text NOT NULL,
            new_value text NOT NULL,
            ai_model varchar(100) NULL,
            context text NULL,
            PRIMARY KEY  (id),
            KEY idx_image_id (image_id),
            KEY idx_timestamp (timestamp)
        ) $charset_collate;";

        if ( ! function_exists( 'dbDelta' ) ) {
            require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        }
        dbDelta( $sql );
    }

    /**
     * Adds a new entry to the changelog table.
     *
     * Records details of a change, including the user, image, field, old and new values,
     * and optionally the AI model and context used for the change.
     *
     * @since 1.0.0
     * @param int    $image_id  The ID of the image that was changed.
     * @param string $field     The name of the field that was changed (e.g., 'Title', 'Alt Text').
     * @param string $old_value The value of the field before the change.
     * @param string $new_value The value of the field after the change.
     * @param string|null $ai_model  Optional. The AI model used for the change.
     * @param string|null $context   Optional. Additional context for the change (e.g., 'Bulk Optimizer').
     */
    public static function add_entry( $image_id, $field, $old_value, $new_value, $ai_model = null, $context = null ) {
        if ($old_value === $new_value) { return; }

        $entry = [
            'timestamp'     => current_time( 'mysql' ),
            'ai_model'      => $ai_model,
            'user_id'       => get_current_user_id(),
            'image_id'      => $image_id,
            'field_changed' => $field,
            'old_value'     => $old_value,
            'new_value'     => $new_value,
            'context'       => $context,
        ];

        /**
         * Filter a changelog entry before saving.
         *
         * Return false to skip saving the entry.
         *
         * @since 1.0.0
         * @param array $entry The changelog entry data.
         */
        $entry = apply_filters('infyp_changelog_entry', $entry);
        if (false === $entry) {
            return;
        }

        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Insert operation for logging, no caching needed
        $wpdb->insert( self::get_table_name(),
            $entry,
            [ '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s' ]
        );
    }
}