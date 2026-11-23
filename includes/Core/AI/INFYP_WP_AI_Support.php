<?php
/**
 * WordPress Core AI Client Capability Helper
 *
 * Central gate for the WordPress 7.0+ AI Client / Connectors integration.
 * Every availability, configuration and selection check for the "WordPress AI"
 * connection method flows through this class so the gating logic exists in
 * exactly one place — PHP callers and the JS-facing localized vars alike.
 *
 * @package    AI_Image_SEO
 * @subpackage Core\AI
 * @since 1.0.6
 * @author     InfyPress
 * @license    GPL-2.0+
 */

// phpcs:disable WordPress.Files.FileName -- repo convention: class files are named after the class (INFYP_*.php).

namespace IPAIS\Core\AI;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class INFYP_WP_AI_Support
 *
 * Static capability helper for the WordPress core AI Client (WP 7.0+).
 *
 * Terminology used across the plugin:
 * - available:  the core AI Client exists at runtime (WP 7.0+).
 * - configured: at least one AI connector can serve text generation.
 * - selected:   the site owner chose "WordPress AI" as connection method.
 * - active:     selected AND available (safe to route requests through core).
 * - ready:      active AND configured (requests will actually succeed).
 *
 * @since 1.0.6
 */
class INFYP_WP_AI_Support {

	/**
	 * Option name storing the chosen connection method.
	 *
	 * @since 1.0.6
	 * @var string
	 */
	const OPTION_CONNECTION_METHOD = 'infyp_connection_method';

	/**
	 * Connection method value for the plugin's own API keys.
	 *
	 * @since 1.0.6
	 * @var string
	 */
	const METHOD_API_KEYS = 'api_keys';

	/**
	 * Connection method value for WordPress core AI Connectors.
	 *
	 * @since 1.0.6
	 * @var string
	 */
	const METHOD_WP_AI = 'wp_ai';

	/**
	 * Per-request memoized result of the core readiness probe.
	 *
	 * @since 1.0.6
	 * @var bool|null
	 */
	private static $configured_cache = null;

	/**
	 * Whether the WordPress core AI Client exists at runtime.
	 *
	 * Function existence is the authoritative gate — it is true on
	 * WordPress 7.0+ regardless of how core happens to be structured.
	 *
	 * @since 1.0.6
	 *
	 * @return bool True when the core AI Client can be called.
	 */
	public static function is_available() {
		return function_exists( 'wp_ai_client_prompt' );
	}

	/**
	 * Build a core AI Client prompt builder.
	 *
	 * The single dispatch point for `wp_ai_client_prompt()`. The call is
	 * dynamic on purpose: the plugin supports WordPress 6.1+ and only reaches
	 * this code behind an is_available() runtime guard, so a direct call would
	 * be a false positive in the Plugin Check minimum-version analysis.
	 *
	 * @since 1.0.6
	 *
	 * @param string $text The prompt text.
	 * @return object The WP_AI_Client_Prompt_Builder instance.
	 * @throws \BadFunctionCallException When the core AI Client is unavailable.
	 */
	public static function prompt( $text ) {
		if ( ! self::is_available() ) {
			throw new \BadFunctionCallException( 'The WordPress core AI Client is not available on this site.' );
		}

		return call_user_func( 'wp_ai_client_prompt', $text );
	}

	/**
	 * Whether at least one AI connector is configured for text generation.
	 *
	 * Uses the core AI Client's deterministic support check — no API call is
	 * made. The result is memoized per request because it is consulted from
	 * views, script localization and notices within a single page load.
	 *
	 * @since 1.0.6
	 *
	 * @return bool True when a configured connector can generate text.
	 */
	public static function is_configured() {
		if ( null !== self::$configured_cache ) {
			return self::$configured_cache;
		}

		if ( ! self::is_available() ) {
			self::$configured_cache = false;
			return false;
		}

		try {
			self::$configured_cache = (bool) self::prompt( 'probe' )->is_supported_for_text_generation();
		} catch ( \Throwable $e ) {
			self::$configured_cache = false;
		}

		return self::$configured_cache;
	}

	/**
	 * Get the stored connection method.
	 *
	 * Anything that is not exactly the WordPress AI value degrades to the
	 * API-keys default, so unknown or tampered values can never enable the
	 * core path unintentionally.
	 *
	 * @since 1.0.6
	 *
	 * @return string Either 'api_keys' or 'wp_ai'.
	 */
	public static function get_connection_method() {
		$method = get_option( self::OPTION_CONNECTION_METHOD, self::METHOD_API_KEYS );

		return ( self::METHOD_WP_AI === $method ) ? self::METHOD_WP_AI : self::METHOD_API_KEYS;
	}

	/**
	 * Whether the site owner selected the WordPress AI connection method.
	 *
	 * Deliberately ignores runtime availability: the optimizer branches on
	 * the *selection* so a site that loses core AI support (e.g. restored to
	 * an older WordPress) fails with an explicit error instead of silently
	 * falling back to — and billing — the stored provider API keys.
	 *
	 * @since 1.0.6
	 *
	 * @return bool True when 'wp_ai' is the stored method.
	 */
	public static function is_selected() {
		return self::METHOD_WP_AI === self::get_connection_method();
	}

	/**
	 * Whether the WordPress AI path is selected and callable.
	 *
	 * @since 1.0.6
	 *
	 * @return bool True when selected and the core AI Client is available.
	 */
	public static function is_active() {
		return self::is_selected() && self::is_available();
	}

	/**
	 * Whether WordPress AI requests will actually succeed.
	 *
	 * @since 1.0.6
	 *
	 * @return bool True when active and a connector is configured.
	 */
	public static function is_ready() {
		return self::is_active() && self::is_configured();
	}

	/**
	 * Plugin provider slugs that have a connected core AI connector.
	 *
	 * Maps the core connector list onto the plugin's provider vocabulary
	 * (google / anthropic / openai) by matching the connector id and name,
	 * so the UI can warn when a selected model's provider has no connector.
	 *
	 * @since 1.0.6
	 *
	 * @return string[] Connected provider slugs (subset of google|anthropic|openai).
	 */
	public static function get_connected_provider_slugs() {
		$slugs = array();

		foreach ( self::get_provider_states() as $state ) {
			if ( empty( $state['connected'] ) ) {
				continue;
			}

			$haystack = strtolower( $state['id'] . ' ' . $state['name'] );

			if ( false !== strpos( $haystack, 'google' ) || false !== strpos( $haystack, 'gemini' ) ) {
				$slugs[] = 'google';
			} elseif ( false !== strpos( $haystack, 'anthropic' ) || false !== strpos( $haystack, 'claude' ) ) {
				$slugs[] = 'anthropic';
			} elseif ( false !== strpos( $haystack, 'openai' ) || false !== strpos( $haystack, 'gpt' ) ) {
				$slugs[] = 'openai';
			}
		}

		return array_values( array_unique( $slugs ) );
	}

	/**
	 * Admin URL of the core Settings → Connectors screen.
	 *
	 * Core registers the screen as a plain admin file (wp-admin/menu.php) and
	 * ships no dedicated URL helper, so admin_url() is the native way.
	 *
	 * @since 1.0.6
	 *
	 * @return string Absolute admin URL.
	 */
	public static function connectors_admin_url() {
		return admin_url( 'options-connectors.php' );
	}

	/**
	 * Per-provider state of the AI connectors registered with core.
	 *
	 * Core registers non-AI connectors too (e.g. spam filtering); only
	 * `ai_provider` connectors are listed. A provider counts as connected
	 * when its API-key credential resolves the same way core checks it:
	 * database setting, PHP constant, or environment variable.
	 *
	 * Only meaningful after the `init` action; all consumers (admin views,
	 * AJAX responses) run later than that.
	 *
	 * @since 1.0.6
	 *
	 * @return array[] {
	 *     One entry per AI provider connector.
	 *
	 *     @type string $id        Connector id (e.g. 'google').
	 *     @type string $name      Human-readable connector name.
	 *     @type bool   $connected Whether a credential is configured.
	 * }
	 */
	public static function get_provider_states() {
		if ( ! function_exists( 'wp_get_connectors' ) ) {
			return array();
		}

		try {
			// Dynamic call on purpose: wp_get_connectors() is WP 7.0+ and
			// guarded above; a direct call would trip the Plugin Check
			// minimum-version analysis (plugin supports WP 6.1+).
			$connectors = (array) call_user_func( 'wp_get_connectors' );
		} catch ( \Throwable $e ) {
			return array();
		}

		$states = array();

		foreach ( $connectors as $id => $connector ) {
			if ( ! is_array( $connector ) || empty( $connector['name'] ) ) {
				continue;
			}
			if ( ! isset( $connector['type'] ) || 'ai_provider' !== $connector['type'] ) {
				continue;
			}

			$auth      = isset( $connector['authentication'] ) && is_array( $connector['authentication'] ) ? $connector['authentication'] : array();
			$method    = $auth['method'] ?? '';
			$connected = false;

			if ( 'none' === $method ) {
				$connected = true;
			} elseif ( 'api_key' === $method ) {
				$setting  = $auth['setting_name'] ?? '';
				$constant = $auth['constant_name'] ?? '';
				$env_var  = $auth['env_var_name'] ?? '';

				$connected = ( '' !== $setting && '' !== (string) get_option( $setting, '' ) )
					|| ( '' !== $constant && defined( $constant ) )
					|| ( '' !== $env_var && false !== getenv( $env_var ) && '' !== getenv( $env_var ) );
			}

			$states[] = array(
				'id'        => (string) $id,
				'name'      => (string) $connector['name'],
				'connected' => $connected,
			);
		}

		return $states;
	}

	/**
	 * Register the runtime hooks for the WordPress AI path.
	 *
	 * Prompts are built before provider dispatch and keyed to the selected
	 * model's native prompt format (e.g. tool-use instructions for Claude
	 * models). The core AI Client always receives a JSON-schema request, so
	 * while the WordPress AI method is active every prompt is forced to the
	 * plain-JSON format.
	 *
	 * @since 1.0.6
	 *
	 * @return void
	 */
	public static function register_hooks() {
		add_filter( 'infyp_prompt_format', array( __CLASS__, 'filter_prompt_format' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_show_state_notice' ) );
	}

	/**
	 * Warn on plugin screens when WordPress AI is selected but cannot serve.
	 *
	 * Two states are surfaced: the core AI Client disappeared (site restored
	 * to an older WordPress) and no connector being configured. There is
	 * deliberately no silent fallback to stored API keys in either state.
	 *
	 * @since 1.0.6
	 *
	 * @return void
	 */
	public static function maybe_show_state_notice() {
		if ( ! current_user_can( 'manage_options' ) || ! self::is_selected() ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || false === strpos( $screen->id, 'ai-image-seo-optimizer' ) ) {
			return;
		}

		if ( ! self::is_available() ) {
			echo '<div class="notice notice-error">';
			echo '<p><strong>' . esc_html__( 'Image SEO Optimizer — WordPress AI unavailable', 'infy-ai-image-seo-optimizer' ) . '</strong></p>';
			echo '<p>' . esc_html__( 'WordPress AI Connectors is selected as connection method, but this site no longer runs WordPress 7.0 or later. Switch back to plugin API keys on the API Keys page.', 'infy-ai-image-seo-optimizer' ) . '</p>';
			echo '</div>';
			return;
		}

		if ( ! self::is_configured() ) {
			echo '<div class="notice notice-warning">';
			echo '<p><strong>' . esc_html__( 'Image SEO Optimizer — no AI connector configured', 'infy-ai-image-seo-optimizer' ) . '</strong></p>';
			echo '<p>' . sprintf(
				wp_kses(
					/* translators: %s: URL of the WordPress Settings → Connectors screen */
					__( 'WordPress AI Connectors is selected as connection method, but no AI connector is configured. <a href="%s">Configure one in Settings → Connectors</a> or switch back to plugin API keys.', 'infy-ai-image-seo-optimizer' ),
					array( 'a' => array( 'href' => array() ) )
				),
				esc_url( self::connectors_admin_url() )
			) . '</p>';
			echo '</div>';
		}
	}

	/**
	 * Force the plain-JSON prompt format while WordPress AI is active.
	 *
	 * @since 1.0.6
	 *
	 * @param string $format Resolved prompt format.
	 * @return string Filtered prompt format.
	 */
	public static function filter_prompt_format( $format ) {
		return self::is_active() ? 'json_plain' : $format;
	}

	/**
	 * Reset the per-request memoization (used by tests).
	 *
	 * @since 1.0.6
	 *
	 * @return void
	 */
	public static function reset_cache() {
		self::$configured_cache = null;
	}
}
