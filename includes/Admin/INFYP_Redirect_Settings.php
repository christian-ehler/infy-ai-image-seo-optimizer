<?php
/**
 * Redirect Settings — Renders the redirect configuration card on the Settings page.
 *
 * Hooks into `infyp_after_settings_cards` to add a "301 Redirects" card
 * on the existing Settings page. Also registers the option with the WordPress
 * Settings API for sanitization and handles AJAX save.
 *
 * @package   IPAIS\Admin
 * @since     1.1.0
 * @author    InfyPress
 * @license   GPL-2.0-or-later
 */

namespace IPAIS\Admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class INFYP_Redirect_Settings
 *
 * Renders the 301 Redirect configuration card on the plugin Settings page
 * and handles saving via AJAX. Uses the WordPress Settings API for
 * sanitization and conditionally enqueues its own JS module.
 *
 * @since 1.1.0
 */
class INFYP_Redirect_Settings {

	/**
	 * Constructor — register hooks.
	 *
	 * @since 1.1.0
	 */
	public function __construct() {
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
		add_action( 'infyp_after_settings_cards', [ $this, 'render_card' ] );
		add_action( 'wp_ajax_infyp_save_redirect_settings', [ $this, 'ajax_save' ] );
	}

	/**
	 * Register the redirect settings option.
	 *
	 * @since 1.1.0
	 */
	public function register_settings() {
		register_setting( 'infyp_redirect_settings_group', 'infyp_redirect_settings', [
			'type'              => 'array',
			'sanitize_callback' => [ $this, 'sanitize' ],
		] );
	}

	/**
	 * Enqueue the redirect-settings JS module on the Settings page.
	 *
	 * Uses wp_localize_script to pass translated status messages so the
	 * external JS file stays free of inline PHP.
	 *
	 * @since 1.1.0
	 *
	 * @param string $hook The current admin page hook suffix.
	 */
	public function enqueue_scripts( $hook ) {
		if ( strpos( $hook, 'ai-image-seo-optimizer-settings' ) === false ) {
			return;
		}

		wp_enqueue_script(
			'infyp-redirect-settings',
			INFYP_PLUGIN_URL . 'assets/js/redirect-settings.js',
			[ 'jquery' ],
			INFYP_VERSION,
			true
		);

		wp_localize_script( 'infyp-redirect-settings', 'infypRedirectSettings', [
			'saving' => __( 'Saving...', 'infy-ai-image-seo-optimizer' ),
			'saved'  => __( 'Saved!', 'infy-ai-image-seo-optimizer' ),
			'error'  => __( 'Error saving.', 'infy-ai-image-seo-optimizer' ),
		] );
	}

	/**
	 * Sanitize redirect settings.
	 *
	 * @since 1.1.0
	 *
	 * @param mixed $input Raw input.
	 * @return array Sanitized settings.
	 */
	public function sanitize( $input ) {
		$defaults = [
			'enabled'       => '1',
			'expiry_months' => '12',
		];

		if ( ! is_array( $input ) ) {
			return $defaults;
		}

		return [
			'enabled'       => ! empty( $input['enabled'] ) ? '1' : '0',
			'expiry_months' => in_array( (int) ( $input['expiry_months'] ?? 12 ), [ 0, 6, 12, 24 ], true )
				? (string) (int) $input['expiry_months']
				: '12',
		];
	}

	/**
	 * AJAX handler for saving redirect settings.
	 *
	 * @since 1.1.0
	 */
	public function ajax_save() {
		check_ajax_referer( 'infyp_redirect_settings_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'infy-ai-image-seo-optimizer' ) ] );
		}

		$settings = $this->sanitize( [
			'enabled'       => isset( $_POST['enabled'] ) ? sanitize_text_field( wp_unslash( $_POST['enabled'] ) ) : '0',
			'expiry_months' => isset( $_POST['expiry_months'] ) ? sanitize_text_field( wp_unslash( $_POST['expiry_months'] ) ) : '12',
		] );

		update_option( 'infyp_redirect_settings', $settings );

		wp_send_json_success( [ 'message' => __( 'Redirect settings saved.', 'infy-ai-image-seo-optimizer' ) ] );
	}

	/**
	 * Render the redirect settings card on the Settings page.
	 *
	 * @since 1.1.0
	 */
	public function render_card() {
		$settings = \IPAIS\Core\INFYP_Redirect_Manager::get_settings();
		?>
		<div class="infyp-card" id="infyp-redirect-settings-card">
			<div class="infyp-card-header">
				<h3 class="infyp-card-title"><?php esc_html_e( '301 Redirects', 'infy-ai-image-seo-optimizer' ); ?></h3>
				<span class="infyp-badge infyp-badge-success">
					<span class="dashicons dashicons-randomize"></span>
					<?php esc_html_e( 'SEO Protection', 'infy-ai-image-seo-optimizer' ); ?>
				</span>
			</div>
			<div class="infyp-card-body">
				<div class="infyp-intro-box">
					<span class="dashicons dashicons-info-outline"></span>
					<p><?php esc_html_e( 'When images are renamed, 301 redirects are automatically created to prevent broken links from search engines, social media, and external sites.', 'infy-ai-image-seo-optimizer' ); ?></p>
				</div>

				<form id="infyp-redirect-settings-form" method="post">
					<?php wp_nonce_field( 'infyp_redirect_settings_nonce', 'infyp_redirect_settings_nonce' ); ?>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<label for="infyp-redirect-enabled"><?php esc_html_e( 'Enable Redirects', 'infy-ai-image-seo-optimizer' ); ?></label>
							</th>
							<td>
								<label class="infyp-toggle-switch">
									<input type="checkbox" id="infyp-redirect-enabled" name="enabled" value="1" <?php checked( $settings['enabled'], '1' ); ?> />
									<span class="infyp-toggle-slider"></span>
								</label>
								<p class="description"><?php esc_html_e( 'Automatically create 301 redirects when image filenames are changed.', 'infy-ai-image-seo-optimizer' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="infyp-redirect-expiry"><?php esc_html_e( 'Auto-expire After', 'infy-ai-image-seo-optimizer' ); ?></label>
							</th>
							<td>
								<select id="infyp-redirect-expiry" name="expiry_months">
									<option value="6" <?php selected( $settings['expiry_months'], '6' ); ?>><?php esc_html_e( '6 months', 'infy-ai-image-seo-optimizer' ); ?></option>
									<option value="12" <?php selected( $settings['expiry_months'], '12' ); ?>><?php esc_html_e( '12 months', 'infy-ai-image-seo-optimizer' ); ?></option>
									<option value="24" <?php selected( $settings['expiry_months'], '24' ); ?>><?php esc_html_e( '24 months', 'infy-ai-image-seo-optimizer' ); ?></option>
									<option value="0" <?php selected( $settings['expiry_months'], '0' ); ?>><?php esc_html_e( 'Never', 'infy-ai-image-seo-optimizer' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'Redirects older than this period will be automatically marked as expired.', 'infy-ai-image-seo-optimizer' ); ?></p>
							</td>
						</tr>
					</table>

					<p class="submit">
						<button type="submit" class="button button-primary" id="infyp-save-redirect-settings">
							<?php esc_html_e( 'Save Redirect Settings', 'infy-ai-image-seo-optimizer' ); ?>
						</button>
						<span id="infyp-redirect-settings-status" class="infyp-text-sm infyp-text-muted infyp-redirect-status-msg"></span>
					</p>
				</form>
			</div>
		</div>
		<?php
	}
}
