<?php
/**
 * Model Migration - Moves a saved model id to its canonical registry id
 *
 * When a model id is renamed or retired, the registry keeps the old id as an
 * alias of the entry that now serves it. This class rewrites the saved
 * setting to the canonical id once, records the change as [from, to] and,
 * for a real model replacement, shows a one-time admin notice naming both
 * models. There is no hard-coded from/to map: the next retirement is a
 * registry change only.
 *
 * Plugin updates do not re-run activation, so maybe_migrate() is called on
 * every admin request (it reads one autoloaded option). Until it runs, cron, REST
 * and CLI calls resolve the alias at call time through the registry.
 *
 * @package   IPAIS\Core\AI
 * @since 1.0.9
 * @author    InfyPress
 * @license   GPL-2.0-or-later
 */

namespace IPAIS\Core\AI;

use IPAIS\Core\INFYP_Logger;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Model Migration Class
 *
 * @since 1.0.9
 */
class INFYP_Model_Migration {

	/**
	 * Option holding the last rewrite: from, to, time and whether the notice is pending.
	 *
	 * @since 1.0.9
	 */
	const OPTION = 'infyp_model_migration';

	/**
	 * AJAX action and nonce name for dismissing the notice.
	 *
	 * @since 1.0.9
	 */
	const DISMISS_ACTION = 'infyp_dismiss_model_migration_notice';

	/**
	 * Rewrite a saved alias to its canonical model id and register the notice hooks.
	 *
	 * Idempotent: once the saved id is canonical nothing is written. Ids the
	 * registry does not know (for example models of a deactivated extension)
	 * are left untouched.
	 *
	 * @since 1.0.9
	 *
	 * @return void
	 */
	public static function maybe_migrate() {
		add_action( 'admin_notices', [ __CLASS__, 'render_notice' ] );
		add_action( 'wp_ajax_' . self::DISMISS_ACTION, [ __CLASS__, 'ajax_dismiss_notice' ] );

		$settings = get_option( 'infyp_optimization_settings', [] );
		if ( ! is_array( $settings ) || empty( $settings['ai_model'] ) || ! is_string( $settings['ai_model'] ) ) {
			return;
		}

		$from     = $settings['ai_model'];
		$registry = INFYP_Model_Config_Registry::get_instance();

		if ( ! $registry->has_config( $from ) ) {
			return;
		}

		$to = INFYP_Model_Config_Registry::get_canonical_id( $from );
		if ( $to === $from ) {
			return;
		}

		// Must run before INFYP_Settings registers its sanitize callback for
		// this option (admin_init, priority 10): that callback rebuilds the
		// array from known keys only. infyp_maybe_upgrade() is hooked at
		// priority 5 for that reason.
		$settings['ai_model'] = $to;
		update_option( 'infyp_optimization_settings', $settings );

		// Autoloaded only while the notice is pending, so render_notice()
		// costs no query on sites without one.
		$notice = $registry->is_replaced_id( $from );
		update_option(
			self::OPTION,
			[
				'from'   => $from,
				'to'     => $to,
				'time'   => time(),
				'notice' => $notice,
			],
			$notice
		);

		INFYP_Logger::log( sprintf( 'Model migration infyp_optimization_settings.ai_model: [%s, %s]', $from, $to ), 'WARNING' );
	}

	/**
	 * Show the one-time notice after a saved model was replaced by its successor.
	 *
	 * @since 1.0.9
	 *
	 * @return void
	 */
	public static function render_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// A pending notice is autoloaded: read it from the autoload cache so
		// sites without one make no extra query.
		$alloptions = wp_load_alloptions();
		if ( ! isset( $alloptions[ self::OPTION ] ) ) {
			return;
		}

		$state = maybe_unserialize( $alloptions[ self::OPTION ] );
		if ( ! is_array( $state ) || empty( $state['notice'] ) || empty( $state['from'] ) || empty( $state['to'] ) ) {
			return;
		}

		$registry = INFYP_Model_Config_Registry::get_instance();
		$to       = $registry->get_config( (string) $state['to'] );
		$to_label = ( null !== $to && ! empty( $to['display_name'] ) ) ? (string) $to['display_name'] : (string) $state['to'];

		$settings_url = admin_url( 'admin.php?page=ai-image-seo-optimizer-settings' );
		?>
		<div class="notice notice-info is-dismissible infyp-model-migration-notice">
			<p>
				<strong><?php esc_html_e( 'Image SEO Optimizer', 'infy-ai-image-seo-optimizer' ); ?></strong> &mdash;
				<?php
				printf(
					/* translators: 1: retired AI model id, 2: name of the AI model that replaces it */
					esc_html__( 'The AI model %1$s has been retired by its provider. Image optimization now uses %2$s.', 'infy-ai-image-seo-optimizer' ),
					'<code>' . esc_html( (string) $state['from'] ) . '</code>',
					'<strong>' . esc_html( $to_label ) . '</strong>'
				);
				?>
				<a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Review the model in Settings', 'infy-ai-image-seo-optimizer' ); ?></a>
			</p>
		</div>
		<?php
		$nonce  = wp_create_nonce( self::DISMISS_ACTION );
		$script = 'jQuery(function($){$(".infyp-model-migration-notice").on("click",".notice-dismiss",function(){'
			. '$.post(ajaxurl,{action:' . wp_json_encode( self::DISMISS_ACTION ) . ',nonce:' . wp_json_encode( $nonce ) . '});'
			. '});});';
		add_action(
			'admin_footer',
			static function () use ( $script ) {
				wp_print_inline_script_tag( $script );
			}
		);
	}

	/**
	 * AJAX handler: dismiss the migration notice for this site.
	 *
	 * @since 1.0.9
	 *
	 * @return void Sends a JSON response and terminates.
	 */
	public static function ajax_dismiss_notice() {
		if ( ! check_ajax_referer( self::DISMISS_ACTION, 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed.', 'infy-ai-image-seo-optimizer' ) ], 403 );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'infy-ai-image-seo-optimizer' ) ], 403 );
		}

		$state = get_option( self::OPTION, [] );
		if ( is_array( $state ) && ! empty( $state['notice'] ) ) {
			$state['notice'] = false;
			update_option( self::OPTION, $state, false );
		}

		wp_send_json_success();
	}
}
