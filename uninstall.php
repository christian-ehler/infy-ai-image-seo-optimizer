<?php
/**
 * Plugin uninstall handler
 *
 * @package   IPAIS
 * @since 1.0.0
 */

// Security check
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// No capability check here: WordPress only loads this file from uninstall_plugin(),
// after its own permission checks. WP-CLI runs it without a logged-in user, so a
// current_user_can() check would skip the whole cleanup on `wp plugin uninstall`.

global $wpdb;

// Validate table prefix for security
if ( ! preg_match( '/^[a-zA-Z0-9_]+$/', $wpdb->prefix ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
// Direct queries required for uninstall operations - caching not applicable during plugin removal

// Start transaction - SQL command doesn't need prepare (no user input)
// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- START TRANSACTION is a literal SQL command with no variables
$wpdb->query( 'START TRANSACTION' );

try {
	/**
	 * Fires before the plugin removes its data during uninstall.
	 *
	 * Extensions should clean up their own data here.
	 *
	 * @since 1.0.0
	 */
	do_action('infyp_before_uninstall');

	// Remove custom tables
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange
	// Schema changes required to remove plugin tables during uninstall
	$infyp_changelog_table = $wpdb->prefix . 'infyp_changelog';
	// Table name is safe - comes from $wpdb->prefix + hardcoded suffix
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- DROP TABLE during uninstall, table name validated
	$wpdb->query( "DROP TABLE IF EXISTS `{$infyp_changelog_table}`" );

	// API usage table
	$infyp_api_usage_table = $wpdb->prefix . 'infyp_api_usage';
	// Table name is safe - comes from $wpdb->prefix + hardcoded suffix
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- DROP TABLE during uninstall, table name validated
	$wpdb->query( "DROP TABLE IF EXISTS `{$infyp_api_usage_table}`" );
	// Search Console data table
	$infyp_gsc_data_table = $wpdb->prefix . 'infyp_gsc_data';
	// Table name is safe - comes from $wpdb->prefix + hardcoded suffix
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- DROP TABLE during uninstall, table name validated
	$wpdb->query( "DROP TABLE IF EXISTS `{$infyp_gsc_data_table}`" );
	// Image usage and lookup tables
	$infyp_image_usage_table = $wpdb->prefix . 'infyp_image_usage';
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- DROP TABLE during uninstall, table name validated
	$wpdb->query( "DROP TABLE IF EXISTS `{$infyp_image_usage_table}`" );
	$infyp_post_types_table = $wpdb->prefix . 'infyp_image_post_types';
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- DROP TABLE during uninstall, table name validated
	$wpdb->query( "DROP TABLE IF EXISTS `{$infyp_post_types_table}`" );
	$infyp_search_index_table = $wpdb->prefix . 'infyp_search_index';
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- DROP TABLE during uninstall, table name validated
	$wpdb->query( "DROP TABLE IF EXISTS `{$infyp_search_index_table}`" );
	// Redirects table
	$infyp_redirects_table = $wpdb->prefix . 'infyp_redirects';
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- DROP TABLE during uninstall, table name validated
	$wpdb->query( "DROP TABLE IF EXISTS `{$infyp_redirects_table}`" );
	// REST API activity log table
	$infyp_rest_log_table = $wpdb->prefix . 'infyp_rest_log';
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- DROP TABLE during uninstall, table name validated
	$wpdb->query( "DROP TABLE IF EXISTS `{$infyp_rest_log_table}`" );
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.SchemaChange

	// Remove plugin options
	$infyp_options_to_delete = [
		'infyp_optimization_settings',
		'infyp_prompt_settings',
		'infyp_api_keys',
		'infyp_connection_method',
		'infyp_post_type_settings',
		'infyp_version',
		'infyp_db_version',
		'infyp_installed_version',
		'infyp_ai_model',
		'infyp_api_usage_retention_days',
		// Saved-model migration record and notice state
		'infyp_model_migration',
		// Wizard options
		'infyp_wizard_completed',
		'infyp_wizard_data',
		'infyp_wizard_notice_dismissed',
		// Scoring and weights
		'infyp_scoring_parameters',
		'infyp_seo_weights',
		// Page builder stats
		'infyp_pagebuilder_stats',
		// Index flags
		'infyp_lookup_table_populated',
		'infyp_search_index_populated',
		'infyp_lookup_schema_version',
		// Background content scan
		'infyp_content_scan_cursor',
		'infyp_content_scan_completed',
		// SEO score cache
		'infyp_scores_stale_since',
		// Wizard redirect
		'infyp_wizard_redirect_time',
		// Google Search Console
		'infyp_gsc_connection_method',
		'infyp_gsc_last_sync',
		'infyp_gsc_optimization_impact',
		'infyp_gsc_connected_property',
		'infyp_gsc_custom_credentials',
		'infyp_google_oauth_tokens',
		// Dashboard cache
		'infyp_cached_dashboard_stats',
		// Request queue
		'infyp_request_queue',
		// Mock data flag
		'infyp_gsc_mock_connection',
		// Redirect settings
		'infyp_redirect_settings',
		// Redirect delivery health state
		'infyp_redirect_health',
		'infyp_redirect_unmatched_404s',
		// REST API activity log schema version
		'infyp_rest_log_schema_version',
	];

	foreach ( $infyp_options_to_delete as $infyp_option ) {
		delete_option( $infyp_option );
	}

	// Remove dynamic options (cache busters)
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( 'infyp_cache_buster_' ) . '%'
		)
	);

	// Remove transients - Direct query required for bulk deletion
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options}
			 WHERE option_name LIKE %s
			 OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_infyp_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_infyp_' ) . '%'
		)
	);

	// Remove post meta
	$infyp_meta_keys_to_delete = [
		'_infyp_has_been_optimized',
		'_infyp_last_optimized',
		'_infyp_optimization_data',
		'_infyp_api_usage_logged',
		'_infyp_seo_score',
		'_infyp_keywords',
		'_infyp_keywords_manual',
		'_infyp_filename_optimized',
		'_infyp_suggested_filename',
		'_infyp_optimized',
		'_infyp_optimized_date',
		// Pending image upload marker (INFYP_Cache_Manager::UPLOAD_PENDING_META).
		'_infyp_upload_pending',
	];

	foreach ( $infyp_meta_keys_to_delete as $infyp_meta_key ) {
		// Direct deletion required for cleanup during uninstall
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Necessary for complete cleanup during uninstall
		$wpdb->delete( $wpdb->postmeta, [ 'meta_key' => $infyp_meta_key ], [ '%s' ] );
	}

	// Remove user meta (user preferences)
	$infyp_user_meta_keys_to_delete = [
		'infyp_dismissed_compression_card',
		'infyp_hide_all_banners',
		'infyp_dismissed_extension_banner',
		// Screen options (items per page)
		'infyp_redirects_per_page',
		'infyp_rest_log_per_page',
	];

	foreach ( $infyp_user_meta_keys_to_delete as $infyp_user_meta_key ) {
		// Direct deletion required for cleanup during uninstall
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Necessary for complete cleanup during uninstall
		$wpdb->delete( $wpdb->usermeta, [ 'meta_key' => $infyp_user_meta_key ], [ '%s' ] );
	}

	// Clear cron events
	$infyp_cron_hooks = [
		'infyp_cleanup_api_usage_logs',
		'infyp_batch_cache_scores',
		'infyp_calculate_stats',
		'infyp_sync_search_console',
		'infyp_daily_maintenance',
		'infyp_check_redirect_expiry',
		'infyp_scan_content_images',
		'infyp_redirect_health_check',
	];

	foreach ( $infyp_cron_hooks as $infyp_hook ) {
		$infyp_timestamp = wp_next_scheduled( $infyp_hook );
		if ( $infyp_timestamp ) {
			wp_unschedule_event( $infyp_timestamp, $infyp_hook );
		}
		// Clear scheduled hook
		wp_clear_scheduled_hook( $infyp_hook );
	}

	// Clear cache
	if ( function_exists( 'wp_cache_flush' ) ) {
		wp_cache_flush();
	}

	// Clear cache groups
	if ( function_exists( 'wp_cache_delete_group' ) ) {
		$infyp_cache_groups = [ 'infyp', 'infyp_queries', 'infyp_api', 'infyp_images', 'infyp_api_usage' ];
		foreach ( $infyp_cache_groups as $infyp_group ) {
			wp_cache_delete_group( $infyp_group );
		}
	}

	// Remove capabilities
	$role = get_role( 'administrator' );
	if ( $role ) {
		$infyp_capabilities = [ 'infyp_manage_settings', 'infyp_optimize_images', 'infyp_view_api_usage' ];
		foreach ( $infyp_capabilities as $infyp_cap ) {
			$role->remove_cap( $infyp_cap );
		}
	}

	// Clean uploads directory
	$infyp_upload_dir = wp_upload_dir();
	$infyp_log_dir = $infyp_upload_dir['basedir'] . '/ai-image-seo-logs/';
	if ( is_dir( $infyp_log_dir ) ) {
		// Remove logs
		$infyp_files = glob( $infyp_log_dir . '*' );
		foreach ( $infyp_files as $infyp_file ) {
			if ( is_file( $infyp_file ) ) {
				wp_delete_file( $infyp_file );
			}
		}
		// Remove dir using WP_Filesystem
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once( ABSPATH . 'wp-admin/includes/file.php' );
		}
		WP_Filesystem();
		global $wp_filesystem;
		
		if ( $wp_filesystem && $wp_filesystem->is_dir( $infyp_log_dir ) ) {
			$wp_filesystem->rmdir( $infyp_log_dir, true ); // true = recursive
		}
	}

	// Commit transaction - SQL command doesn't need prepare (no user input)
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- COMMIT is a literal SQL command with no variables
	$wpdb->query( 'COMMIT' );

} catch ( Exception $e ) {
	// Rollback on error - SQL command doesn't need prepare (no user input)
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- ROLLBACK is a literal SQL command with no variables
	$wpdb->query( 'ROLLBACK' );
}

// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

// Final cleanup
do_action( 'infyp_uninstall_complete' );