<?php
/**
 * AJAX Request Handler Router
 *
 * Thin coordinator that instantiates and registers all domain-specific
 * AJAX handler classes. Each domain handler is responsible for its own
 * action registration and request processing.
 *
 * Domain Handlers:
 * - INFYP_Image_Ajax:    Image metadata, usage, filename, URL operations
 * - INFYP_AI_Ajax:       AI optimization, keywords, SEO scoring
 * - INFYP_Settings_Ajax: API keys, prompts, weights, scoring params
 * - INFYP_Notices_Ajax:  Banner/notice dismissals
 * - INFYP_Table_Ajax:    Live search, table data, cache warming, indexes
 *
 * @package   IPAIS\Ajax
 * @since 1.0.0
 * @since 1.0.0 Refactored into domain-specific handler classes
 * @author    InfyPress
 * @license   GPL-2.0-or-later
 */

namespace IPAIS\Ajax;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AJAX Handler Router
 *
 * Instantiates and wires all domain-specific AJAX handlers.
 *
 * @package   IPAIS\Ajax
 * @since 1.0.0
 */
class INFYP_Ajax_Handlers {

    /**
     * Constructor — registers all domain handlers.
     */
    public function __construct() {
        $this->register_handlers();
    }

    /**
     * Instantiate and register all domain-specific AJAX handlers.
     *
     * @since 1.0.0
     */
    private function register_handlers() {
        // Image metadata, usage, filename, URL operations
        $image_ajax = new INFYP_Image_Ajax();
        $image_ajax->register();

        // AI optimization, keywords, SEO scoring
        $ai_ajax = new INFYP_AI_Ajax();
        $ai_ajax->register();

        // Settings: API keys, prompts, weights, scoring params
        $settings_ajax = new INFYP_Settings_Ajax();
        $settings_ajax->register();

        // Notice/banner dismissals
        $notices_ajax = new INFYP_Notices_Ajax();
        $notices_ajax->register();

        // Table data, live search, cache warming, indexes
        $table_ajax = new INFYP_Table_Ajax();
        $table_ajax->register();
    }
}
