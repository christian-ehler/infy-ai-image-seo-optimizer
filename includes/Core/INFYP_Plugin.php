<?php
/**
 * Main plugin class — global accessor for subsystems.
 *
 * Provides a single entry point for extensions to access plugin internals
 * via the infyp() global function. Follows the WooCommerce WC() / EDD EDD()
 * pattern used by all major WordPress plugins.
 *
 * Usage:
 *   infyp()->cache       — INFYP_Cache_Manager instance
 *   infyp()->security    — INFYP_Security_Manager instance
 *   infyp()->api_keys    — INFYP_API_Key_Manager instance
 *   infyp()->api_usage   — INFYP_API_Usage_Tracker instance
 *
 * @package IPAIS\Core
 * @since   1.1.0
 */

namespace IPAIS\Core;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin instance.
 *
 * Singleton that exposes core subsystems as public properties.
 * Only singleton-based subsystems are exposed here. Static-only
 * classes (Changelog, AI_Optimizer, Image_Usage, Language_Manager)
 * are accessed via the infyp_*() helper functions in INFYP_Plugin_API.php.
 */
class INFYP_Plugin {

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	public $version = INFYP_VERSION;

	/**
	 * Singleton instance.
	 *
	 * @var INFYP_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Whether subsystems have been initialized.
	 *
	 * @var bool
	 */
	private $initialized = false;

	/**
	 * Cache manager instance.
	 *
	 * @var INFYP_Cache_Manager|null
	 */
	public $cache = null;

	/**
	 * Security manager instance.
	 *
	 * @var INFYP_Security_Manager|null
	 */
	public $security = null;

	/**
	 * API key manager instance.
	 *
	 * @var INFYP_API_Key_Manager|null
	 */
	public $api_keys = null;

	/**
	 * API usage tracker instance.
	 *
	 * @var INFYP_API_Usage_Tracker|null
	 */
	public $api_usage = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return INFYP_Plugin
	 */
	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor — use instance() instead.
	 */
	private function __construct() {}

	/**
	 * Initialize subsystem references.
	 *
	 * Called after infyp_run_ai_image_seo() has created all singletons.
	 * Safe to call multiple times — only initializes once.
	 *
	 * @return void
	 */
	public function init() {
		if ( $this->initialized ) {
			return;
		}

		$this->cache     = INFYP_Cache_Manager::get_instance();
		$this->security  = INFYP_Security_Manager::get_instance();
		$this->api_keys  = INFYP_API_Key_Manager::get_instance();
		$this->api_usage = INFYP_API_Usage_Tracker::get_instance();

		$this->initialized = true;
	}

	/**
	 * Check if subsystems are initialized.
	 *
	 * @return bool
	 */
	public function is_initialized() {
		return $this->initialized;
	}

	/**
	 * Prevent cloning.
	 */
	private function __clone() {}

	/**
	 * Prevent unserialization.
	 *
	 * @throws \Exception Always.
	 */
	public function __wakeup() {
		throw new \Exception( 'Cannot unserialize singleton.' );
	}
}
