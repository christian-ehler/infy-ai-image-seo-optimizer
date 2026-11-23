<?php
/**
 * Redirect Expiry Cron Job
 *
 * Runs daily to mark redirects as expired when they pass their expires_at date.
 *
 * @package   IPAIS\Cron
 * @since     1.1.0
 * @author    InfyPress
 * @license   GPL-2.0-or-later
 */

namespace IPAIS\Cron;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use IPAIS\Database\INFYP_Redirect_Table;

/**
 * Class INFYP_Redirect_Expiry
 *
 * Singleton that registers a daily cron hook to expire old redirects.
 */
class INFYP_Redirect_Expiry {

	/**
	 * Cron hook name.
	 */
	const CRON_HOOK = 'infyp_check_redirect_expiry';

	/**
	 * Singleton instance.
	 *
	 * @var INFYP_Redirect_Expiry|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return INFYP_Redirect_Expiry
	 */
	public static function get_instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor — registers cron hook.
	 */
	private function __construct() {
		add_action( self::CRON_HOOK, [ $this, 'run' ] );
	}

	/**
	 * Schedule the daily cron job.
	 *
	 * @since 1.1.0
	 */
	public static function schedule_cron() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Unschedule the cron job (for plugin deactivation).
	 *
	 * @since 1.1.0
	 */
	public static function unschedule_cron() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Run the expiry check.
	 *
	 * @since 1.1.0
	 */
	public function run() {
		$expired_count = INFYP_Redirect_Table::expire_old_redirects();

		if ( $expired_count > 0 && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			\IPAIS\Core\INFYP_Logger::log(
				sprintf( 'Redirect expiry cron: expired %d redirects', $expired_count ),
				'INFO'
			);
		}
	}
}
