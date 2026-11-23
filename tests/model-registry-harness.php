<?php
// Key-free harness for the model registry, the saved-model migration, the
// settings write paths and the setup wizard's model choice. Minimal WordPress
// stubs, no network, no API keys. One scenario per process because the
// registry is a lazily built singleton.
//
// Usage: php tests/model-registry-harness.php [plugin_dir] [scenario|all]
// Needs vendor/autoload.php (composer dump-autoload).

namespace {
	define( 'ABSPATH', '/tmp/' );
	define( 'INFYP_DEFAULT_MODEL', 'gemini-3.1-flash-lite-preview' );
	$plugin_dir = $argv[1] ?? dirname( __DIR__ );
	define( 'INFYP_PLUGIN_DIR', $plugin_dir . '/' );

	$GLOBALS['opts']    = [];
	$GLOBALS['autoload'] = [];
	$GLOBALS['wrong']   = [];
	$GLOBALS['hooks']   = [];
	$GLOBALS['writes']  = [];
	$GLOBALS['logs']    = [];
	$GLOBALS['keys']    = [];
	$GLOBALS['caps']    = true;
	$GLOBALS['fails']   = 0;
	$GLOBALS['passes']  = 0;

	function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['opts'] ) ? $GLOBALS['opts'][ $k ] : $d; }
	function update_option( $k, $v, $a = null ) {
		$filtered = apply_filters( 'sanitize_option_' . $k, $v, $k );
		if ( array_key_exists( $k, $GLOBALS['opts'] ) && $GLOBALS['opts'][ $k ] === $filtered ) { return false; }
		if ( null !== $a ) { $GLOBALS['autoload'][ $k ] = (bool) $a; } elseif ( ! isset( $GLOBALS['autoload'][ $k ] ) ) { $GLOBALS['autoload'][ $k ] = true; }
		$GLOBALS['writes'][] = $k; $GLOBALS['opts'][ $k ] = $filtered; return true;
	}
	function wp_load_alloptions() {
		$all = [];
		foreach ( $GLOBALS['opts'] as $k => $v ) { if ( ! empty( $GLOBALS['autoload'][ $k ] ) ) { $all[ $k ] = is_array( $v ) ? serialize( $v ) : $v; } }
		return $all;
	}
	function maybe_unserialize( $v ) { return is_string( $v ) && 0 === strpos( $v, 'a:' ) ? unserialize( $v ) : $v; }
	function _doing_it_wrong( $f, $m, $v ) { $GLOBALS['wrong'][] = $m; }
	function add_action( $h, $cb, $p = 10, $n = 1 ) { $GLOBALS['hooks'][ $h ][] = $cb; return true; }
	function add_filter( $h, $cb, $p = 10, $n = 1 ) { $GLOBALS['hooks'][ $h ][] = $cb; return true; }
	function do_action( $h, ...$args ) { foreach ( $GLOBALS['hooks'][ $h ] ?? [] as $cb ) { $cb( ...$args ); } }
	function apply_filters( $h, $v, ...$args ) { foreach ( $GLOBALS['hooks'][ $h ] ?? [] as $cb ) { $v = $cb( $v, ...$args ); } return $v; }
	function current_user_can( $c ) { return $GLOBALS['caps']; }
	function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
	function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
	function get_post_mime_type( $id ) { return 'image/jpeg'; }
	function absint( $v ) { return abs( (int) $v ); }
	function wp_remote_post( $url, $args ) { $GLOBALS['requests'][] = [ $url, json_decode( $args['body'], true ) ]; return new WP_Error( 'harness_stop', 'stopped' ); }
	function wp_parse_args( $a, $d = [] ) { return array_merge( $d, (array) $a ); }
	function wp_json_encode( $v ) { return json_encode( $v ); }
	function esc_html( $s ) { return htmlspecialchars( (string) $s ); }
	function esc_attr( $s ) { return htmlspecialchars( (string) $s ); }
	function esc_url( $s ) { return (string) $s; }
	function esc_html__( $s, $d = '' ) { return $s; }
	function esc_html_e( $s, $d = '' ) { echo $s; }
	function __( $s, $d = '' ) { return $s; }
	function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
	function wp_create_nonce( $a ) { return 'nonce'; }
	function check_ajax_referer( $a, $q, $die ) { return true; }
	function wp_print_inline_script_tag( $s ) { echo '<script>' . $s . '</script>'; }
	function is_wp_error( $v ) { return $v instanceof WP_Error; }
	function wp_send_json_success( $d = null ) { throw new RuntimeException( 'json_success' ); }
	function wp_send_json_error( $d = null, $s = null ) { throw new RuntimeException( 'json_error' ); }
	function rest_validate_value_from_schema( $v, $schema, $param ) {
		if ( ! is_string( $v ) ) { return new WP_Error( 'rest_invalid_type', "$param is not of type string." ); }
		if ( ! in_array( $v, $schema['enum'], true ) ) { return new WP_Error( 'rest_not_in_enum', "$param is not one of " . implode( ', ', $schema['enum'] ) . '.' ); }
		return true;
	}
	class WP_Error { public $code; public $message; function __construct( $c = '', $m = '', $d = null ) { $this->code = $c; $this->message = $m; } }
	class WP_REST_Controller { protected $namespace; }

	function check( $label, $cond ) {
		if ( $cond ) { $GLOBALS['passes']++; } else { $GLOBALS['fails']++; echo "FAIL  $label\n"; }
	}
}

namespace IPAIS\Core {
	class INFYP_Logger { public static function log( $m, $l = 'DEBUG', $c = [] ) { $GLOBALS['logs'][] = "[$l] $m"; } }
	class INFYP_API_Key_Manager {
		private static $i;
		public static function get_instance() { return self::$i ?: ( self::$i = new self() ); }
		public function has_api_key( $p ) { return ! empty( get_option( 'infyp_api_keys', [] )[ $p ] ); }
		public function set_api_key( $p, $k ) { $keys = get_option( 'infyp_api_keys', [] ); $keys[ $p ] = 'enc:' . $k; update_option( 'infyp_api_keys', $keys ); }
		// The wizard checks keys before storing them (1.0.9); here every key is accepted.
		public function validate_api_key( $p, $k ) { return true; }
		public function test_api_key( $p, $k = null ) { return [ 'state' => 'valid', 'message' => '' ]; }
		public function get_provider_for_model( $m ) {
			if ( 0 === strpos( $m, 'gemini' ) ) { return 'google'; }
			if ( 0 === strpos( $m, 'gpt' ) ) { return 'openai'; }
			if ( 0 === strpos( $m, 'claude' ) ) { return 'anthropic'; }
			$c = \IPAIS\Core\AI\INFYP_Model_Config_Registry::get_instance()->get_config( $m );
			return $c['provider'] ?? null;
		}
	}
}

namespace {
	use IPAIS\Core\AI\INFYP_Model_Config_Registry as R;
	use IPAIS\Core\AI\INFYP_Model_Migration as M;

	$dir      = $plugin_dir;
	$scenario = $argv[2] ?? 'all';
	$all      = [ 'builtin', 'successor', 'retire-primary-with-aliases', 're-register-primary', 'alias-to-primary', 'pro-resolved',
		'collisions', 'resolve-fallback', 'request-bodies', 'migrate-replaced', 'migrate-snapshot', 'migrate-legacy', 'migrate-noop', 'write-paths', 'wizard' ];
	if ( 'all' === $scenario ) {
		$failed = 0;
		foreach ( $all as $one ) {
			passthru( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' ' . escapeshellarg( $dir ) . ' ' . escapeshellarg( $one ), $code );
			$failed += $code ? 1 : 0;
		}
		echo $failed ? "$failed scenario(s) failed\n" : "all scenarios passed\n";
		exit( $failed ? 1 : 0 );
	}
	require $dir . '/vendor/autoload.php';

	$params = [ 'max_completion_tokens' => 777, 'temperature' => null, 'response_format' => null, 'extra' => [ 'reasoning_effort' => 'none' ] ];
	$successor = [
		'model_id'     => 'test-successor-1',
		'display_name' => 'Test Successor',
		'provider'     => 'openai',
		'group'        => 'OpenAI',
		'call_params'  => [ 'full' => $params, 'keywords' => $params, 'single_field' => $params ],
		'pricing'      => [ 'blended_per_million' => 0.3 ],
		'api_model_id' => 'test-successor-api',
		'aliases'      => [ 'test-old-snapshot' ],
		'replaces'     => [ 'gpt-6-luna' ],
	];

	switch ( $scenario ) {
		case 'builtin':
			$r = R::get_instance();
			foreach ( $r->get_all_configs() as $id => $c ) {
				check( "api_model_id set for $id", ! empty( $c['api_model_id'] ) );
			}
			check( 'canonical legacy gemini', 'gemini-3.1-flash-lite-preview' === R::get_canonical_id( 'gemini-3-flash-preview' ) );
			check( 'canonical haiku snapshot', 'claude-haiku-4-5' === R::get_canonical_id( 'claude-haiku-4-5-20251001' ) );
			check( 'canonical primary unchanged', 'claude-haiku-4-5' === R::get_canonical_id( 'claude-haiku-4-5' ) );
			check( 'canonical unknown unchanged', 'no-such-model' === R::get_canonical_id( 'no-such-model' ) );
			check( 'canonical via instance call', 'claude-haiku-4-5' === $r->get_canonical_id( 'claude-haiku-4-5-20251001' ) );
			check( 'accepted has aliases', in_array( 'gemini-3-flash-preview', $r->get_accepted_model_ids(), true ) && in_array( 'claude-haiku-4-5-20251001', $r->get_accepted_model_ids(), true ) );
			check( 'valid has no aliases', ! in_array( 'gemini-3-flash-preview', $r->get_valid_model_ids(), true ) && ! in_array( 'gpt-4.1-mini', $r->get_valid_model_ids(), true ) );
			$ids = $r->get_valid_model_ids(); sort( $ids );
			check( 'selectable lineup', [ 'claude-haiku-4-5', 'claude-haiku-5-5', 'gemini-3.1-flash-lite-preview', 'gemini-3.5-flash-lite', 'gpt-6-luna' ] === $ids );
			check( 'legacy alias not a replacement', ! $r->is_replaced_id( 'gemini-3-flash-preview' ) );
			check( 'default google', INFYP_DEFAULT_MODEL === $r->get_default_model_for_provider( 'google' ) );
			check( 'default openai', 'gpt-6-luna' === $r->get_default_model_for_provider( 'openai' ) );
			check( 'default anthropic', 'claude-haiku-5-5' === $r->get_default_model_for_provider( 'anthropic' ) );
			check( 'default unknown provider', '' === $r->get_default_model_for_provider( 'acme' ) );
			check( 'alias api id', 'gemini-3.1-flash-lite' === $r->resolve_api_model_id( 'gemini-3-flash-preview' ) );
			// Registry data (measured lineup).
			check( 'default calls GA id', 'gemini-3.1-flash-lite' === $r->resolve_api_model_id( INFYP_DEFAULT_MODEL ) );
			check( 'gpt-4.1-mini -> gpt-6-luna', 'gpt-6-luna' === R::get_canonical_id( 'gpt-4.1-mini' ) && 'gpt-6-luna' === $r->resolve_api_model_id( 'gpt-4.1-mini' ) );
			check( 'gpt-4.1-mini snapshot -> gpt-6-luna', 'gpt-6-luna' === R::get_canonical_id( 'gpt-4.1-mini-2025-04-14' ) );
			check( 'gpt-4.1-mini and its dated snapshot replaced', $r->is_replaced_id( 'gpt-4.1-mini' ) && $r->is_replaced_id( 'gpt-4.1-mini-2025-04-14' ) );
			check( 'snapshot not selectable', ! in_array( 'gpt-4.1-mini-2025-04-14', $r->get_valid_model_ids(), true ) && in_array( 'gpt-4.1-mini-2025-04-14', $r->get_accepted_model_ids(), true ) );
			check( 'claude aliases not replacements', ! $r->is_replaced_id( 'claude-haiku-4-5-20251001' ) );
			check( 'haiku 4.5 unchanged', 'claude-haiku-4-5-20251001' === $r->resolve_api_model_id( 'claude-haiku-4-5' ) && ! $r->is_replaced_id( 'claude-haiku-4-5' ) );
			$caps = [ 'gpt-6-luna' => [ 400, 150, 150 ], 'claude-haiku-5-5' => [ 1024, 500, 500 ], INFYP_DEFAULT_MODEL => [ 2048, 512, 512 ], 'gemini-3.5-flash-lite' => [ 2048, 512, 512 ] ];
			foreach ( $caps as $id => $c ) {
				$p = [ $r->get_call_params( $id, 'full' ), $r->get_call_params( $id, 'keywords' ), $r->get_call_params( $id, 'single_field' ) ];
				check( "$id caps", $c === array_column( $p, 'max_completion_tokens' ) );
				check( "$id no temperature", [ null, null, null ] === array_map( function ( $x ) { return $x['temperature']; }, $p ) );
			}
			foreach ( [ 'full', 'keywords', 'single_field' ] as $t ) {
				$lp = $r->get_call_params( 'gpt-6-luna', $t );
				check( "luna $t effort none + json_object", [ 'reasoning_effort' => 'none' ] === $lp['extra'] && [ 'type' => 'json_object' ] === $lp['response_format'] );
				check( "haiku 5.5 $t no extra", [] === $r->get_call_params( 'claude-haiku-5-5', $t )['extra'] );
			}
			check( 'prices', 0.20 === $r->get_pricing( 'gpt-6-luna' ) && 0.20 === $r->get_pricing( 'claude-haiku-5-5' ) && 0.56 === $r->get_pricing( INFYP_DEFAULT_MODEL ) && 0.20 === $r->get_pricing( 'gpt-4.1-mini' ) );
			check( 'labels', 'GPT-6 Luna' === $r->get_model_labels()['gpt-6-luna'] && 'Claude 5.5 Haiku' === $r->get_model_labels()['claude-haiku-5-5'] );
			break;

		case 'successor':
			add_action( 'infyp_register_model_configs', function ( $reg ) use ( $successor ) { $reg->register( $successor ); } );
			$r = R::get_instance();
			check( 'retired resolves to successor api id', 'test-successor-api' === $r->resolve_api_model_id( 'gpt-6-luna' ) );
			check( 'retired gets successor params', 777 === $r->get_call_params( 'gpt-6-luna', 'full' )['max_completion_tokens'] );
			check( 'retired entry aliases follow (chain)', 'test-successor-1' === R::get_canonical_id( 'gpt-4.1-mini' ) && 'test-successor-1' === R::get_canonical_id( 'gpt-4.1-mini-2025-04-14' ) );
			check( 'own alias resolves', 'test-successor-1' === R::get_canonical_id( 'test-old-snapshot' ) );
			check( 'canonical retired', 'test-successor-1' === R::get_canonical_id( 'gpt-6-luna' ) );
			check( 'retired gone from valid ids', ! in_array( 'gpt-6-luna', $r->get_valid_model_ids(), true ) );
			check( 'retired gone from dropdown', ! isset( $r->get_dropdown_models()['OpenAI']['gpt-6-luna'] ) && isset( $r->get_dropdown_models()['OpenAI']['test-successor-1'] ) );
			check( 'retired accepted', in_array( 'gpt-6-luna', $r->get_accepted_model_ids(), true ) );
			check( 'is_replaced_id', $r->is_replaced_id( 'gpt-6-luna' ) && ! $r->is_replaced_id( 'test-old-snapshot' ) );
			check( 'openai default = successor', 'test-successor-1' === $r->get_default_model_for_provider( 'openai' ) );
			check( 'pricing via alias', 0.3 === $r->get_pricing( 'gpt-6-luna' ) );
			break;

		case 'retire-primary-with-aliases':
			$s = $successor;
			$s['provider'] = 'anthropic'; $s['group'] = 'Anthropic'; $s['model_id'] = 'test-claude-next';
			$s['aliases'] = []; $s['replaces'] = [ 'claude-haiku-4-5' ];
			add_action( 'infyp_register_model_configs', function ( $reg ) use ( $s ) { $reg->register( $s ); } );
			$r = R::get_instance();
			check( 'old primary -> successor', 'test-claude-next' === R::get_canonical_id( 'claude-haiku-4-5' ) );
			check( 'old primary alias follows', 'test-claude-next' === R::get_canonical_id( 'claude-haiku-4-5-20251001' ) );
			check( 'old alias api id', 'test-successor-api' === $r->resolve_api_model_id( 'claude-haiku-4-5-20251001' ) );
			check( 'old not selectable', ! in_array( 'claude-haiku-4-5', $r->get_valid_model_ids(), true ) );
			break;

		case 're-register-primary':
			add_action( 'infyp_register_model_configs', function ( $reg ) {
				// get_all_configs() here would re-enter init(); build the entry by hand.
				$p = [ 'max_completion_tokens' => 1 ];
				$c = [ 'model_id' => 'claude-haiku-4-5', 'display_name' => 'H', 'provider' => 'anthropic', 'group' => 'Anthropic',
					'call_params' => [ 'full' => $p, 'keywords' => $p, 'single_field' => $p ], 'pricing' => [ 'blended_per_million' => 9.9 ] ];
				$reg->register( $c );
			} );
			$r = R::get_instance();
			check( 're-registered primary updated', 9.9 === $r->get_pricing( 'claude-haiku-4-5' ) );
			check( 'its alias follows', 9.9 === $r->get_pricing( 'claude-haiku-4-5-20251001' ) );
			break;

		case 'alias-to-primary':
			// An id that was an alias becomes a primary entry: the old target stays intact.
			add_action( 'infyp_register_model_configs', function ( $reg ) use ( $successor ) {
				$s = $successor; $s['model_id'] = 'gemini-3-flash-preview'; $s['provider'] = 'google'; $s['group'] = 'Google';
				$s['aliases'] = []; $s['replaces'] = [];
				$reg->register( $s );
			} );
			$r = R::get_instance();
			check( 'new primary', 'gemini-3-flash-preview' === R::get_canonical_id( 'gemini-3-flash-preview' ) );
			check( 'old target intact', 'gemini-3.1-flash-lite-preview' === $r->get_config( 'gemini-3.1-flash-lite-preview' )['model_id'] && 0.56 === $r->get_pricing( 'gemini-3.1-flash-lite-preview' ) );
			break;

		case 'pro-resolved':
			// Pro registers its successor with retired ids as plain aliases.
			add_action( 'infyp_register_model_configs', function ( $reg ) use ( $successor ) {
				$s = $successor; $s['model_id'] = 'claude-sonnet-5-5'; $s['provider'] = 'anthropic'; $s['group'] = 'Anthropic';
				$s['aliases'] = [ 'claude-sonnet-4-5', 'claude-sonnet-4-5-20250929' ]; unset( $s['replaces'] );
				$reg->register( $s );
			} );
			check( 'pro canonical passes through', 'claude-sonnet-5-5' === R::get_canonical_id( 'claude-sonnet-5-5' ) );
			check( 'pro retired resolves', 'claude-sonnet-5-5' === R::get_canonical_id( 'claude-sonnet-4-5' ) );
			check( 'pro alias no notice', ! R::get_instance()->is_replaced_id( 'claude-sonnet-4-5' ) );
			break;

		case 'collisions':
			add_action( 'infyp_register_model_configs', function ( $reg ) use ( $successor ) {
				$s = $successor; $s['model_id'] = 'third-party-a'; $s['replaces'] = [];
				$s['aliases'] = [ 'claude-haiku-4-5', 'gemini-3-flash-preview', 'third-party-old' ];
				$reg->register( $s );
				$t = $successor; $t['model_id'] = 'third-party-b'; $t['aliases'] = []; $t['replaces'] = [ INFYP_DEFAULT_MODEL ];
				$reg->register( $t );
			} );
			$r = R::get_instance();
			check( 'alias over primary ignored', 'claude-haiku-4-5' === R::get_canonical_id( 'claude-haiku-4-5' ) && in_array( 'claude-haiku-4-5', $r->get_valid_model_ids(), true ) );
			check( 'alias over other alias ignored', INFYP_DEFAULT_MODEL === R::get_canonical_id( 'gemini-3-flash-preview' ) );
			check( 'free alias still indexed', 'third-party-a' === R::get_canonical_id( 'third-party-old' ) );
			check( 'default model cannot be replaced', INFYP_DEFAULT_MODEL === R::get_canonical_id( INFYP_DEFAULT_MODEL ) && in_array( INFYP_DEFAULT_MODEL, $r->get_valid_model_ids(), true ) );
			check( 'doing_it_wrong x3', 3 === count( $GLOBALS['wrong'] ) );
			break;

		case 'resolve-fallback':
			add_action( 'infyp_register_model_configs', function ( $reg ) use ( $successor ) {
				$s = $successor; unset( $s['api_model_id'] ); $s['model_id'] = 'no-api-id-model'; $s['aliases'] = [ 'old-no-api-id' ]; $s['replaces'] = [];
				$reg->register( $s );
			} );
			check( 'alias without api_model_id calls entry id', 'no-api-id-model' === R::get_instance()->resolve_api_model_id( 'old-no-api-id' ) );
			check( 'unknown id unchanged', 'nope' === R::get_instance()->resolve_api_model_id( 'nope' ) );
			break;

		case 'request-bodies':
			// What the providers send for the registry's call parameters (saved alias -> successor).
			$r      = R::get_instance();
			$openai = new class() extends \IPAIS\Core\AI\INFYP_OpenAI_Provider { public static function get_cached_base64( $id ) { return 'AAAA'; } };
			$claude = new class() extends \IPAIS\Core\AI\INFYP_Claude_Provider { public static function get_cached_base64( $id ) { return 'AAAA'; } };
			$runs   = [ [ $openai, 'gpt-4.1-mini' ], [ $claude, 'claude-haiku-5-5' ], [ $claude, 'claude-haiku-4-5' ] ];
			foreach ( $runs as $run ) {
				list( $prov, $saved ) = $run;
				$api = $r->resolve_api_model_id( $saved );
				$GLOBALS['requests'] = [];
				$prov->call_full( 'k', 'p', 1, $api, $r->get_call_params( $saved, 'full' ) );
				$prov->call_keywords( 'k', 'p', 1, $api, $r->get_call_params( $saved, 'keywords' ) );
				$prov->call_single_field( 'k', 'p', 1, $api, 'alt_text', $r->get_call_params( $saved, 'single_field' ) );
				check( "$saved: three requests", 3 === count( $GLOBALS['requests'] ) );
				foreach ( $GLOBALS['requests'] as $i => $req ) {
					$b = $req[1];
					check( "$saved #$i: no temperature", ! array_key_exists( 'temperature', $b ) );
					if ( $prov === $openai ) {
						check( "$saved #$i: model gpt-6-luna, effort none, json_object", 'gpt-6-luna' === $b['model'] && 'none' === ( $b['reasoning_effort'] ?? null ) && [ 'type' => 'json_object' ] === ( $b['response_format'] ?? null ) && in_array( $b['max_completion_tokens'], [ 400, 150 ], true ) );
					} else {
						check( "$saved #$i: model $api, no thinking", $api === $b['model'] && ! array_key_exists( 'thinking', $b ) );
					}
				}
			}
			break;

		case 'migrate-replaced':
			// Real registry data: gpt-4.1-mini is replaced by gpt-6-luna.
			// 1.0.8-shaped options.
			$GLOBALS['opts']['infyp_optimization_settings'] = [ 'ai_model' => 'gpt-4.1-mini', 'batch_size' => 10, 'timeout' => 60, 'request_timeout' => 90 ];
			M::maybe_migrate();
			$s = get_option( 'infyp_optimization_settings' );
			check( 'rewritten', 'gpt-6-luna' === $s['ai_model'] );
			check( 'other keys kept', 10 === $s['batch_size'] && 60 === $s['timeout'] && 90 === $s['request_timeout'] );
			$st = get_option( M::OPTION );
			check( 'record from/to/notice', 'gpt-4.1-mini' === $st['from'] && 'gpt-6-luna' === $st['to'] && true === $st['notice'] );
			check( 'pending notice autoloaded', true === $GLOBALS['autoload'][ M::OPTION ] );
			check( 'log line', 1 === count( $GLOBALS['logs'] ) && false !== strpos( $GLOBALS['logs'][0], '[gpt-4.1-mini, gpt-6-luna]' ) && 0 === strpos( $GLOBALS['logs'][0], '[WARNING]' ) );
			$n_writes = count( $GLOBALS['writes'] );
			M::maybe_migrate(); M::maybe_migrate();
			check( 'idempotent', count( $GLOBALS['writes'] ) === $n_writes && 1 === count( $GLOBALS['logs'] ) );
			ob_start(); M::render_notice(); do_action( 'admin_footer' ); $html = ob_get_clean(); unset( $GLOBALS['hooks']['admin_footer'] );
			check( 'notice shown', false !== strpos( $html, 'gpt-4.1-mini' ) && false !== strpos( $html, 'GPT-6 Luna' ) && false !== strpos( $html, 'infyp_dismiss_model_migration_notice' ) );
			$GLOBALS['caps'] = false;
			ob_start(); M::render_notice(); check( 'no notice without cap', '' === ob_get_clean() );
			try { M::ajax_dismiss_notice(); } catch ( RuntimeException $e ) { check( 'dismiss denied without cap', 'json_error' === $e->getMessage() ); }
			$GLOBALS['caps'] = true;
			try { M::ajax_dismiss_notice(); } catch ( RuntimeException $e ) { check( 'dismiss ok', 'json_success' === $e->getMessage() ); }
			ob_start(); M::render_notice(); check( 'notice gone after dismiss', '' === ob_get_clean() );
			check( 'dismissed record not autoloaded', false === $GLOBALS['autoload'][ M::OPTION ] && false === get_option( M::OPTION )['notice'] );
			check( 'hooks registered', ! empty( $GLOBALS['hooks']['admin_notices'] ) && ! empty( $GLOBALS['hooks']['wp_ajax_infyp_dismiss_model_migration_notice'] ) );
			break;

		case 'migrate-snapshot':
			// A saved dated GPT-4.1 mini snapshot gets the same one-time notice.
			$GLOBALS['opts']['infyp_optimization_settings'] = [ 'ai_model' => 'gpt-4.1-mini-2025-04-14' ];
			M::maybe_migrate();
			check( 'snapshot rewritten', 'gpt-6-luna' === get_option( 'infyp_optimization_settings' )['ai_model'] );
			$st = get_option( M::OPTION );
			check( 'snapshot record with notice', 'gpt-4.1-mini-2025-04-14' === $st['from'] && 'gpt-6-luna' === $st['to'] && true === $st['notice'] && true === $GLOBALS['autoload'][ M::OPTION ] );
			ob_start(); M::render_notice(); do_action( 'admin_footer' ); $html = ob_get_clean(); unset( $GLOBALS['hooks']['admin_footer'] );
			check( 'snapshot notice shown', false !== strpos( $html, 'gpt-4.1-mini-2025-04-14' ) && false !== strpos( $html, 'GPT-6 Luna' ) );
			// Claude dated alias stays a silent rewrite.
			$GLOBALS['opts'] = [ 'infyp_optimization_settings' => [ 'ai_model' => 'claude-haiku-4-5-20251001' ] ];
			M::maybe_migrate();
			check( 'claude alias rewritten', 'claude-haiku-4-5' === get_option( 'infyp_optimization_settings' )['ai_model'] );
			check( 'claude alias silent', false === get_option( M::OPTION )['notice'] && false === $GLOBALS['autoload'][ M::OPTION ] );
			break;

		case 'migrate-legacy':
			$GLOBALS['opts']['infyp_optimization_settings'] = [ 'ai_model' => 'gemini-3-flash-preview' ];
			M::maybe_migrate();
			check( 'legacy rewritten', INFYP_DEFAULT_MODEL === get_option( 'infyp_optimization_settings' )['ai_model'] );
			check( 'legacy silent', false === get_option( M::OPTION )['notice'] && false === $GLOBALS['autoload'][ M::OPTION ] );
			ob_start(); M::render_notice(); check( 'no legacy notice', '' === ob_get_clean() );
			break;

		case 'migrate-noop':
			foreach ( [ 'some-extension-model', INFYP_DEFAULT_MODEL, 'claude-haiku-4-5', 'gpt-6-luna', '' ] as $id ) {
				$GLOBALS['opts'] = [ 'infyp_optimization_settings' => [ 'ai_model' => $id ] ];
				$GLOBALS['writes'] = [];
				M::maybe_migrate();
				check( "noop for '$id'", [] === $GLOBALS['writes'] && false === get_option( M::OPTION ) );
			}
			$GLOBALS['opts'] = []; $GLOBALS['writes'] = [];
			M::maybe_migrate();
			check( 'fresh install: nothing written, no notice', [] === $GLOBALS['writes'] );
			$GLOBALS['opts'] = [ 'infyp_optimization_settings' => 'garbage' ];
			M::maybe_migrate();
			check( 'non-array option tolerated', 'garbage' === get_option( 'infyp_optimization_settings' ) );
			break;

		case 'write-paths':
			$settings = new \IPAIS\Admin\INFYP_Settings();
			$GLOBALS['opts']['infyp_optimization_settings'] = [ 'ai_model' => 'claude-haiku-4-5' ];
			check( 'settings api: retired -> canonical', 'gpt-6-luna' === $settings->sanitize_optimization_settings( [ 'ai_model' => 'gpt-4.1-mini' ] )['ai_model'] );
			check( 'settings api: legacy -> canonical', INFYP_DEFAULT_MODEL === $settings->sanitize_optimization_settings( [ 'ai_model' => 'gemini-3-flash-preview' ] )['ai_model'] );
			check( 'settings api: unknown keeps saved', 'claude-haiku-4-5' === $settings->sanitize_optimization_settings( [ 'ai_model' => '<b>evil</b>' ] )['ai_model'] );
			check( 'settings api: non-string keeps saved', 'claude-haiku-4-5' === $settings->sanitize_optimization_settings( [ 'ai_model' => [ 'x' ] ] )['ai_model'] );
			$GLOBALS['opts'] = [];
			check( 'settings api: unknown, nothing saved -> default', INFYP_DEFAULT_MODEL === $settings->sanitize_optimization_settings( [ 'ai_model' => 'nope' ] )['ai_model'] );

			$rc = ( new ReflectionClass( '\IPAIS\Rest\INFYP_REST_Settings_Controller' ) )->newInstanceWithoutConstructor();
			check( 'rest: retired valid', true === $rc->validate_ai_model( 'gpt-4.1-mini' ) );
			check( 'rest: snapshot alias valid', true === $rc->validate_ai_model( 'gpt-4.1-mini-2025-04-14' ) );
			$e = $rc->validate_ai_model( 'nope' );
			check( 'rest: unknown -> enum error listing selectable ids', is_wp_error( $e ) && false !== strpos( $e->message, 'gpt-6-luna' ) && false === strpos( $e->message, 'gpt-4.1-mini,' ) );
			check( 'rest: non-string invalid', is_wp_error( $rc->validate_ai_model( 5 ) ) );
			check( 'rest: sanitize -> canonical', 'gpt-6-luna' === $rc->sanitize_ai_model( 'gpt-4.1-mini' ) );
			check( 'rest: sanitize unknown unchanged', 'nope' === $rc->sanitize_ai_model( 'nope' ) );
			$args = ( new ReflectionMethod( $rc, 'get_update_args' ) );
			$args->setAccessible( true );
			$a = $args->invoke( $rc )['ai_model'];
			check( 'rest: enum = selectable ids', $a['enum'] === R::get_instance()->get_valid_model_ids() && is_callable( $a['validate_callback'] ) && is_callable( $a['sanitize_callback'] ) );

			$ajax = ( new ReflectionClass( '\IPAIS\Ajax\INFYP_Settings_Ajax' ) )->newInstanceWithoutConstructor();
			$m = new ReflectionMethod( $ajax, 'get_valid_ai_models' ); $m->setAccessible( true );
			check( 'ajax: accepts retired', in_array( 'gpt-4.1-mini', $m->invoke( $ajax ), true ) );
			break;

		case 'wizard':
			$w  = ( new ReflectionClass( '\IPAIS\Admin\INFYP_Setup_Wizard' ) )->newInstanceWithoutConstructor();
			$sk = new ReflectionMethod( $w, 'save_api_keys' ); $sk->setAccessible( true );
			$fresh = [ 'ai_model' => INFYP_DEFAULT_MODEL, 'batch_size' => 10, 'timeout' => 60 ];
			$cases = [
				'only openai'        => [ [ 'openai' => 'k' ], 'gpt-6-luna' ],
				'only anthropic'     => [ [ 'anthropic' => 'k' ], 'claude-haiku-5-5' ],
				'google + openai'    => [ [ 'google' => 'k', 'openai' => 'k' ], INFYP_DEFAULT_MODEL ],
				'openai + anthropic' => [ [ 'openai' => 'k', 'anthropic' => 'k' ], 'gpt-6-luna' ],
				'no keys'            => [ [], INFYP_DEFAULT_MODEL ],
			];
			foreach ( $cases as $label => $case ) {
				$GLOBALS['opts'] = [ 'infyp_optimization_settings' => $fresh ];
				$sk->invoke( $w, array_merge( [ 'connection_method' => 'api_keys' ], $case[0] ) );
				$s = get_option( 'infyp_optimization_settings' );
				check( "wizard $label -> {$case[1]}", $case[1] === $s['ai_model'] && 10 === $s['batch_size'] );
			}
			// Re-run: a saved model whose provider still has a stored key stays.
			$GLOBALS['opts'] = [ 'infyp_optimization_settings' => [ 'ai_model' => 'claude-haiku-4-5' ], 'infyp_api_keys' => [ 'anthropic' => 'enc:x' ] ];
			$sk->invoke( $w, [ 'connection_method' => 'api_keys', 'openai' => 'k' ] );
			check( 'wizard re-run keeps keyed model', 'claude-haiku-4-5' === get_option( 'infyp_optimization_settings' )['ai_model'] );
			// A model the registry does not know (deactivated extension) stays.
			$GLOBALS['opts'] = [ 'infyp_optimization_settings' => [ 'ai_model' => 'gpt-5-mini' ] ];
			$sk->invoke( $w, [ 'connection_method' => 'api_keys', 'google' => 'k' ] );
			check( 'wizard keeps unregistered model', 'gpt-5-mini' === get_option( 'infyp_optimization_settings' )['ai_model'] );
			// WordPress AI connection mode: model untouched.
			$GLOBALS['opts'] = [ 'infyp_optimization_settings' => $fresh, 'infyp_connection_method' => 'wp_ai' ];
			$sk->invoke( $w, [ 'openai' => 'k' ] );
			check( 'wizard wp_ai mode keeps model', INFYP_DEFAULT_MODEL === get_option( 'infyp_optimization_settings' )['ai_model'] );
			$sp = new ReflectionMethod( $w, 'save_preferences' ); $sp->setAccessible( true );
			$GLOBALS['opts'] = [ 'infyp_optimization_settings' => $fresh ];
			$sp->invoke( $w, [ 'default_model' => 'gpt-4.1-mini' ] );
			check( 'default_model no longer written', ! isset( get_option( 'infyp_optimization_settings' )['default_model'] ) );
			break;
	}

	printf( "%s: %d passed, %d failed\n", $scenario, $GLOBALS['passes'], $GLOBALS['fails'] );
	exit( $GLOBALS['fails'] ? 1 : 0 );
}
