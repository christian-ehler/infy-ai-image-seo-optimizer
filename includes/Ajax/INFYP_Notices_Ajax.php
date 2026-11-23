<?php
/**
 * Notices AJAX Handlers - Banner & Notice Dismissal Operations
 *
 * Registers and handles all AJAX requests for notice and banner
 * dismissal operations including compression card and bulk banner management.
 *
 * @package   IPAIS\Ajax
 * @since 1.0.0
 */

namespace IPAIS\Ajax;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Notices AJAX Handlers Class
 *
 * @since 1.0.0
 */
class INFYP_Notices_Ajax {

	/**
	 * Register all AJAX handlers for notice/banner operations.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function register() {
		$handlers = [
			'infyp_dismiss_compression_notice'  => 'ajax_dismiss_compression_notice_handler',
			'infyp_dismiss_extension_banner'    => 'ajax_dismiss_extension_banner_handler',
			'infyp_reset_all_banners'           => 'ajax_reset_all_banners_handler',
			'infyp_toggle_hide_all_banners'     => 'ajax_toggle_hide_all_banners_handler',
		];

		foreach ( $handlers as $action => $method ) {
			add_action( 'wp_ajax_' . $action, [ $this, $method ] );
		}
	}

	/**
	 * Handle extension banner card dismissal
	 */
	public function ajax_dismiss_extension_banner_handler() {
		if ( ! check_ajax_referer( 'infyp_dismiss_extension_banner', 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Security check failed', 'infy-ai-image-seo-optimizer' ) ] );
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'You do not have permission to perform this action', 'infy-ai-image-seo-optimizer' ) ] );
			return;
		}

		update_user_meta( get_current_user_id(), 'infyp_dismissed_extension_banner', time() );

		wp_send_json_success( [ 'message' => esc_html__( 'Card dismissed', 'infy-ai-image-seo-optimizer' ) ] );
	}

	/**
	 * Handle compression card dismissal
	 */
	public function ajax_dismiss_compression_notice_handler() {
		// Verify nonce
		if (!check_ajax_referer('infyp_dismiss_compression_card', 'nonce', false)) {
			wp_send_json_error([
				'message' => esc_html__('Security check failed', 'infy-ai-image-seo-optimizer')
			]);
			return;
		}

		// Check user capabilities
		if (!current_user_can('install_plugins')) {
			wp_send_json_error([
				'message' => esc_html__('You do not have permission to perform this action', 'infy-ai-image-seo-optimizer')
			]);
			return;
		}

		// Store dismissal timestamp
		update_user_meta(get_current_user_id(), 'infyp_dismissed_compression_card', time());

		wp_send_json_success([
			'message' => esc_html__('Card dismissed', 'infy-ai-image-seo-optimizer')
		]);
	}

	/**
	 * AJAX handler to reset all dismissed banners
	 * Removes all banner dismissal timestamps so they show again
	 */
	public function ajax_reset_all_banners_handler() {
		// Verify nonce
		if (!check_ajax_referer('infyp_reset_all_banners', 'nonce', false)) {
			wp_send_json_error([
				'message' => esc_html__('Security check failed', 'infy-ai-image-seo-optimizer')
			]);
			return;
		}

		// Check user capabilities
		if (!current_user_can('manage_options')) {
			wp_send_json_error([
				'message' => esc_html__('You do not have permission to perform this action', 'infy-ai-image-seo-optimizer')
			]);
			return;
		}

		$user_id = get_current_user_id();

		// Define banner dismissal meta keys
		$banner_meta_keys = [
			'infyp_dismissed_compression_card',
			'infyp_dismissed_extension_banner',
		];

		/**
		 * Filter the list of user meta keys to reset when resetting all banners.
		 * Extensions can add their own dismissed notice meta keys here.
		 *
		 * @since 1.0.0
		 * @param array $banner_meta_keys Array of user meta keys to delete.
		 * @param int   $user_id          The current user ID.
		 */
		$banner_meta_keys = apply_filters( 'infyp_reset_banner_meta_keys', $banner_meta_keys, $user_id );

		// Remove all banner dismissal meta keys (only allow infyp_ prefixed keys for safety)
		foreach ( $banner_meta_keys as $meta_key ) {
			if ( strpos( $meta_key, 'infyp_' ) === 0 ) {
				delete_user_meta( $user_id, $meta_key );
			}
		}

		wp_send_json_success([
			'message' => esc_html__('All banners have been reset and will show again', 'infy-ai-image-seo-optimizer')
		]);
	}

	/**
	 * AJAX handler to toggle hide all banners permanently
	 * Sets or removes the permanent hide flag
	 */
	public function ajax_toggle_hide_all_banners_handler() {
		// Verify nonce
		if (!check_ajax_referer('infyp_toggle_hide_banners', 'nonce', false)) {
			wp_send_json_error([
				'message' => esc_html__('Security check failed', 'infy-ai-image-seo-optimizer')
			]);
			return;
		}

		// Check user capabilities
		if (!current_user_can('manage_options')) {
			wp_send_json_error([
				'message' => esc_html__('You do not have permission to perform this action', 'infy-ai-image-seo-optimizer')
			]);
			return;
		}

		$user_id = get_current_user_id();
		$hide_status = isset($_POST['hide_banners']) ? sanitize_text_field(wp_unslash($_POST['hide_banners'])) : '0';

		// Update the user meta
		update_user_meta($user_id, 'infyp_hide_all_banners', $hide_status);

		$message = ($hide_status === '1')
			? esc_html__('All banners are now hidden permanently', 'infy-ai-image-seo-optimizer')
			: esc_html__('All banners are now visible', 'infy-ai-image-seo-optimizer');

		wp_send_json_success([
			'message' => $message,
			'hidden' => $hide_status === '1'
		]);
	}
}
