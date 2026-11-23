<?php
/**
 * Redirect Health Check
 *
 * Answers one question honestly: do this site's image 301 redirects actually
 * work for visitors? The PHP redirect engine only runs when the web server
 * hands requests for missing files to WordPress. Some server configurations
 * (nginx static-asset location blocks, hardened hosting panels, CDN edges)
 * answer those requests themselves with a 404 — and until this class existed
 * the plugin had no way to know or warn (docs/claude/image-redirect-plan.md,
 * Phase 1b).
 *
 * Two probes, one verdict:
 *
 * 1. Loopback probe (automated): insert a synthetic redirect row for a file
 *    that never existed, HEAD-request its old URL without following
 *    redirects, and interpret the raw response. Runs on a weekly cron, after
 *    the first capture, when the server software changes, and on demand.
 * 2. Browser probe (primary evidence): the admin's own browser fetches the
 *    probe URL on the redirects page. It travels the real public path —
 *    including any CDN or cache layer the loopback never sees — so its
 *    verdict outranks the loopback's.
 *
 * States: `unverified` (never probed), `php_verified` (loopback saw the
 * 301), `browser_verified` (a real browser saw it — strongest),
 * `manual_needed` (a probe got a definitive non-redirect answer),
 * `inconclusive` (probe could not complete — e.g. loopback blocked).
 * Per plan invariant 6, only definitive probe evidence ever sets
 * `manual_needed`.
 *
 * @package    AI_Image_SEO
 * @subpackage Core
 * @since      1.0.7
 */

namespace IPAIS\Core;

use IPAIS\Database\INFYP_Redirect_Table;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Probes whether image redirects reach visitors and surfaces the result.
 *
 * @since 1.0.7
 */
class INFYP_Redirect_Health {

	/**
	 * Option storing the current health state and evidence.
	 *
	 * @var string
	 */
	const OPTION = 'infyp_redirect_health';

	/**
	 * Transient holding an in-flight browser-probe row reference.
	 *
	 * @var string
	 */
	const BROWSER_PROBE_TRANSIENT = 'infyp_redirect_health_browser_probe';

	/**
	 * Weekly cron hook.
	 *
	 * @var string
	 */
	const CRON_HOOK = 'infyp_redirect_health_check';

	/**
	 * Filename prefix for synthetic probe URLs. Never collides with real
	 * uploads: the file never exists and the prefix is namespaced.
	 *
	 * @var string
	 */
	const PROBE_PREFIX = 'infyp-redirect-probe-';

	/**
	 * Singleton instance.
	 *
	 * @var INFYP_Redirect_Health|null
	 */
	private static $instance = null;

	/**
	 * Returns the singleton instance.
	 *
	 * @since 1.0.7
	 * @return INFYP_Redirect_Health
	 */
	public static function get_instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor — registers hooks.
	 *
	 * @since 1.0.7
	 */
	private function __construct() {
		add_action( self::CRON_HOOK, [ $this, 'run_loopback_probe' ] );

		// First rename on a never-verified site: check shortly after, so the
		// warning appears before a bulk run compounds the damage.
		add_action( 'infyp_filename_updated', [ $this, 'maybe_schedule_first_probe' ], 20 );

		// Server stack changed since the last probe (migration, host change):
		// yesterday's verdict is void.
		add_action( 'admin_init', [ $this, 'maybe_reprobe_on_server_change' ] );

		// Warning surfaces.
		add_action( 'admin_notices', [ $this, 'render_admin_notice' ] );
		add_filter( 'site_status_tests', [ $this, 'register_site_health_test' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );

		// Probe endpoints (manage_options enforced in the handlers).
		add_action( 'wp_ajax_infyp_redirect_health_probe', [ $this, 'ajax_run_probe' ] );
		add_action( 'wp_ajax_infyp_redirect_health_report', [ $this, 'ajax_browser_report' ] );

		// Self-healing schedule.
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'weekly', self::CRON_HOOK );
		}
	}

	/**
	 * Unschedule the cron (called on plugin deactivation).
	 *
	 * @since 1.0.7
	 */
	public static function unschedule_cron() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	// ------------------------------------------------------------------
	//  State
	// ------------------------------------------------------------------

	/**
	 * Current health state record.
	 *
	 * @since 1.0.7
	 * @return array {state, checked_at, method, evidence, server_software}
	 */
	public static function get_state() {
		$defaults = [
			'state'           => 'unverified',
			'checked_at'      => 0,
			'method'          => '',
			'evidence'        => [],
			'server_software' => '',
		];
		$state = get_option( self::OPTION, [] );
		return wp_parse_args( is_array( $state ) ? $state : [], $defaults );
	}

	/**
	 * Whether the current state warrants a warning.
	 *
	 * @since 1.0.7
	 * @return bool
	 */
	public static function needs_warning() {
		return self::get_state()['state'] === 'manual_needed';
	}

	/**
	 * Persist a new verdict.
	 *
	 * Browser evidence outranks loopback: a loopback result never downgrades
	 * a browser-verified state unless it is a definitive failure (the server
	 * config may have changed underneath).
	 *
	 * @since 1.0.7
	 *
	 * @param string $state    New state.
	 * @param string $method   'loopback' or 'browser'.
	 * @param array  $evidence Raw probe evidence for display/debugging.
	 */
	private function set_state( $state, $method, array $evidence ) {
		$current = self::get_state();
		if (
			'loopback' === $method
			&& 'browser_verified' === $current['state']
			&& 'manual_needed' !== $state
		) {
			// Keep the stronger browser verdict; just refresh the timestamp.
			$state = $current['state'];
			$method = $current['method'];
		}

		update_option( self::OPTION, [
			'state'           => $state,
			'checked_at'      => time(),
			'method'          => $method,
			'evidence'        => $evidence,
			'server_software' => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '',
		], false );
	}

	// ------------------------------------------------------------------
	//  Probe row lifecycle
	// ------------------------------------------------------------------

	/**
	 * Insert a synthetic probe redirect and return its URLs.
	 *
	 * The old URL points at a file that never existed inside uploads (so the
	 * request exercises the exact missing-file path real renames create).
	 * The row expires after an hour as a safety net if deletion fails.
	 *
	 * @since 1.0.7
	 * @return array{old_url:string,new_url:string}|null Null when the
	 *               redirect feature is unavailable.
	 */
	private function create_probe_row() {
		if ( ! class_exists( '\IPAIS\Database\INFYP_Redirect_Table' ) ) {
			return null;
		}

		$uploads = wp_get_upload_dir();
		if ( empty( $uploads['baseurl'] ) ) {
			return null;
		}

		$token   = strtolower( wp_generate_password( 12, false, false ) );
		$old_url = trailingslashit( $uploads['baseurl'] ) . self::PROBE_PREFIX . $token . '.jpg';
		$new_url = home_url( '/?' . self::PROBE_PREFIX . 'target=' . $token );

		INFYP_Redirect_Table::insert( [
			'image_id'   => 0,
			'old_url'    => $old_url,
			'new_url'    => $new_url,
			'source'     => 'manual',
			'expires_at' => current_datetime()->modify( '+1 hour' )->format( 'Y-m-d H:i:s' ),
		] );

		return [
			'old_url' => $old_url,
			'new_url' => $new_url,
		];
	}

	/**
	 * Delete a probe row by its old URL.
	 *
	 * @since 1.0.7
	 * @param string $old_url Probe source URL.
	 */
	private function delete_probe_row( $old_url ) {
		$row = INFYP_Redirect_Table::get_by_old_url( $old_url );
		if ( $row ) {
			INFYP_Redirect_Table::delete( (int) $row->id );
		}
	}

	// ------------------------------------------------------------------
	//  Loopback probe
	// ------------------------------------------------------------------

	/**
	 * Run the automated loopback probe and store the verdict.
	 *
	 * @since 1.0.7
	 * @return array The stored state record.
	 */
	public function run_loopback_probe() {
		if ( ! INFYP_Redirect_Manager::get_instance()->is_enabled() ) {
			return self::get_state();
		}

		$probe = $this->create_probe_row();
		if ( ! $probe ) {
			return self::get_state();
		}

		$response = wp_remote_head( $probe['old_url'], [
			'redirection' => 0,
			'timeout'     => 10,
			// Loopback request: `local` lets core apply its own
			// https_local_ssl_verify handling for self-signed dev certs
			// while keeping full SSL verification on real hosts.
			'local'       => true,
			'headers'     => [ 'Cache-Control' => 'no-cache' ],
		] );

		$this->delete_probe_row( $probe['old_url'] );

		if ( is_wp_error( $response ) ) {
			// Loopback blocked or unreachable — not evidence of failure.
			$this->set_state( 'inconclusive', 'loopback', [
				'error' => $response->get_error_message(),
			] );
			return self::get_state();
		}

		$code     = (int) wp_remote_retrieve_response_code( $response );
		$location = (string) wp_remote_retrieve_header( $response, 'location' );
		$evidence = [
			'http_code' => $code,
			'location'  => $location,
		];

		if ( in_array( $code, [ 301, 302, 307, 308 ], true ) && false !== strpos( $location, self::PROBE_PREFIX . 'target' ) ) {
			$this->set_state( 'php_verified', 'loopback', $evidence );
		} elseif ( in_array( $code, [ 200, 403, 404, 410 ], true ) ) {
			// Definitive: the request was answered without our redirect.
			$this->set_state( 'manual_needed', 'loopback', $evidence );
		} else {
			$this->set_state( 'inconclusive', 'loopback', $evidence );
		}

		return self::get_state();
	}

	/**
	 * Schedule a near-term probe after the first rename on an unverified site.
	 *
	 * @since 1.0.7
	 */
	public function maybe_schedule_first_probe() {
		$state = self::get_state();
		if ( 'unverified' !== $state['state'] ) {
			return;
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK ) || wp_next_scheduled( self::CRON_HOOK ) > time() + MINUTE_IN_SECONDS ) {
			wp_schedule_single_event( time() + 30, self::CRON_HOOK );
		}
	}

	/**
	 * Void the verdict when the server software string changes.
	 *
	 * @since 1.0.7
	 */
	public function maybe_reprobe_on_server_change() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$state = self::get_state();
		if ( ! $state['checked_at'] || empty( $_SERVER['SERVER_SOFTWARE'] ) ) {
			return;
		}
		$current = sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) );
		if ( $state['server_software'] && $current !== $state['server_software'] ) {
			wp_schedule_single_event( time() + 10, self::CRON_HOOK );
			// Stamp immediately so this only fires once per change.
			$state['server_software'] = $current;
			update_option( self::OPTION, $state, false );
		}
	}

	// ------------------------------------------------------------------
	//  Browser probe (AJAX)
	// ------------------------------------------------------------------

	/**
	 * AJAX: run the loopback probe now and hand the browser a live probe URL.
	 *
	 * The response feeds the JS half: the browser fetches probe_url on the
	 * public path and reports what it saw to ajax_browser_report().
	 *
	 * @since 1.0.7
	 */
	public function ajax_run_probe() {
		check_ajax_referer( 'infyp_redirect_health_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'infy-ai-image-seo-optimizer' ) ] );
		}

		$loopback = $this->run_loopback_probe();

		// Fresh row for the browser leg, tracked so the report can be trusted.
		$probe = $this->create_probe_row();
		if ( $probe ) {
			set_transient( self::BROWSER_PROBE_TRANSIENT, $probe, 5 * MINUTE_IN_SECONDS );
		}

		wp_send_json_success( [
			'loopback' => $this->public_state( $loopback ),
			'probe'    => $probe ? [
				'url'    => $probe['old_url'],
				'target' => $probe['new_url'],
			] : null,
		] );
	}

	/**
	 * AJAX: receive the browser's probe observation and store the verdict.
	 *
	 * @since 1.0.7
	 */
	public function ajax_browser_report() {
		check_ajax_referer( 'infyp_redirect_health_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'infy-ai-image-seo-optimizer' ) ] );
		}

		$probe = get_transient( self::BROWSER_PROBE_TRANSIENT );
		if ( ! $probe ) {
			wp_send_json_error( [ 'message' => __( 'No probe in flight. Run the test again.', 'infy-ai-image-seo-optimizer' ) ] );
		}
		delete_transient( self::BROWSER_PROBE_TRANSIENT );
		$this->delete_probe_row( $probe['old_url'] );

		$redirected = isset( $_POST['redirected'] ) && '1' === $_POST['redirected'];
		$final_url  = isset( $_POST['final_url'] ) ? esc_url_raw( wp_unslash( $_POST['final_url'] ) ) : '';
		$status     = isset( $_POST['status'] ) ? (int) $_POST['status'] : 0;
		$evidence   = [
			'redirected' => $redirected,
			'final_url'  => $final_url,
			'http_code'  => $status,
		];

		if ( $redirected && false !== strpos( $final_url, self::PROBE_PREFIX . 'target' ) ) {
			$this->set_state( 'browser_verified', 'browser', $evidence );
		} elseif ( in_array( $status, [ 200, 403, 404, 410 ], true ) && ! $redirected ) {
			$this->set_state( 'manual_needed', 'browser', $evidence );
		} else {
			$this->set_state( 'inconclusive', 'browser', $evidence );
		}

		wp_send_json_success( $this->public_state( self::get_state() ) );
	}

	/**
	 * Shape a state record for JS/display.
	 *
	 * @since 1.0.7
	 * @param array $state State record.
	 * @return array
	 */
	private function public_state( array $state ) {
		return [
			'state'      => $state['state'],
			'label'      => self::state_label( $state['state'] ),
			'checked_at' => $state['checked_at'] ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $state['checked_at'] ) : '',
			'evidence'   => $state['evidence'],
		];
	}

	/**
	 * Human label for a state.
	 *
	 * @since 1.0.7
	 * @param string $state State key.
	 * @return string
	 */
	public static function state_label( $state ) {
		switch ( $state ) {
			case 'php_verified':
				return __( 'Working (verified from this server)', 'infy-ai-image-seo-optimizer' );
			case 'browser_verified':
				return __( 'Working (verified in your browser)', 'infy-ai-image-seo-optimizer' );
			case 'manual_needed':
				return __( 'Not working — your server answers old image URLs before WordPress can redirect them', 'infy-ai-image-seo-optimizer' );
			case 'inconclusive':
				return __( 'Could not complete the check', 'infy-ai-image-seo-optimizer' );
			default:
				return __( 'Not tested yet', 'infy-ai-image-seo-optimizer' );
		}
	}

	/**
	 * Enqueue the browser-probe script on the Redirects page.
	 *
	 * @since 1.0.7
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_scripts( $hook ) {
		if ( false === strpos( $hook, 'ai-image-seo-optimizer-redirects' ) ) {
			return;
		}

		wp_enqueue_script(
			'infyp-redirect-health',
			INFYP_PLUGIN_URL . 'assets/js/redirect-health.js',
			[ 'jquery' ],
			INFYP_VERSION,
			true
		);

		wp_localize_script( 'infyp-redirect-health', 'infypRedirectHealth', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'infyp_redirect_health_nonce' ),
			'i18n'    => [
				'testNow'     => __( 'Test redirects now', 'infy-ai-image-seo-optimizer' ),
				'testing'     => __( 'Testing…', 'infy-ai-image-seo-optimizer' ),
				'lastChecked' => __( 'Last checked:', 'infy-ai-image-seo-optimizer' ),
				'error'       => __( 'Test failed — try again.', 'infy-ai-image-seo-optimizer' ),
			],
		] );
	}

	// ------------------------------------------------------------------
	//  Warning surfaces
	// ------------------------------------------------------------------

	/**
	 * Warning notice on plugin admin screens while redirects are broken.
	 *
	 * @since 1.0.7
	 */
	public function render_admin_notice() {
		if ( ! self::needs_warning() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( (string) $screen->id, 'ai-image-seo' ) ) {
			return;
		}

		$redirects_url = admin_url( 'admin.php?page=ai-image-seo-optimizer-redirects' );
		?>
		<div class="notice notice-error">
			<p>
				<strong><?php esc_html_e( 'Image redirects are not reaching visitors.', 'infy-ai-image-seo-optimizer' ); ?></strong>
				<?php esc_html_e( 'A test of an old image URL was answered by your web server before WordPress could send the 301 redirect. Renamed images keep working inside your content, but external links and search engines that still use an old image URL get an error instead of the new image.', 'infy-ai-image-seo-optimizer' ); ?>
				<a href="<?php echo esc_url( $redirects_url ); ?>"><?php esc_html_e( 'Open the Redirects page for details and to re-test.', 'infy-ai-image-seo-optimizer' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Site Health test registration.
	 *
	 * @since 1.0.7
	 * @param array $tests Site Health tests.
	 * @return array
	 */
	public function register_site_health_test( $tests ) {
		$tests['direct']['infyp_redirect_health'] = [
			'label' => __( 'Image redirect delivery', 'infy-ai-image-seo-optimizer' ),
			'test'  => [ $this, 'site_health_test' ],
		];
		return $tests;
	}

	/**
	 * Site Health test callback.
	 *
	 * @since 1.0.7
	 * @return array
	 */
	public function site_health_test() {
		$state  = self::get_state();
		$result = [
			'label'       => __( 'Image redirects reach visitors', 'infy-ai-image-seo-optimizer' ),
			'status'      => 'good',
			'badge'       => [
				'label' => __( 'SEO', 'infy-ai-image-seo-optimizer' ),
				'color' => 'blue',
			],
			'description' => '<p>' . esc_html__( 'When Image SEO Optimizer renames an image file, it serves a 301 redirect from the old URL. The last check confirmed those redirects reach visitors.', 'infy-ai-image-seo-optimizer' ) . '</p>',
			'test'        => 'infyp_redirect_health',
			'actions'     => sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=ai-image-seo-optimizer-redirects' ) ),
				esc_html__( 'Open the Redirects page', 'infy-ai-image-seo-optimizer' )
			),
		];

		if ( 'manual_needed' === $state['state'] ) {
			$result['status']      = 'critical';
			$result['label']       = __( 'Old image URLs return errors instead of redirects', 'infy-ai-image-seo-optimizer' );
			$result['description'] = '<p>' . esc_html__( 'Your web server answers requests for renamed image files itself, so the plugin\'s 301 redirects never run. External links and image search results that still point at old URLs receive an error. Your server configuration needs a rule that passes missing image files to WordPress, or server-level redirect rules.', 'infy-ai-image-seo-optimizer' ) . '</p>';
		} elseif ( in_array( $state['state'], [ 'unverified', 'inconclusive' ], true ) ) {
			$result['status']      = 'recommended';
			$result['label']       = __( 'Image redirect delivery has not been verified', 'infy-ai-image-seo-optimizer' );
			$result['description'] = '<p>' . esc_html__( 'Run the redirect test on the Redirects page to confirm old image URLs actually reach the plugin\'s 301 redirects on this server.', 'infy-ai-image-seo-optimizer' ) . '</p>';
		}

		return $result;
	}
}
