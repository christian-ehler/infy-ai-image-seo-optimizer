<?php
// Key-free harness for the WordPress AI (core AI Client) provider path:
// response schema per served provider, error mapping, plugin-side keyword cap
// and usage-row pricing. The core client is a stub: no network, no API keys.
//
// Usage: php tests/wp-ai-provider-harness.php [plugin_dir]
// Needs vendor/autoload.php (composer dump-autoload).

namespace {
	define( 'ABSPATH', '/tmp/' );
	define( 'INFYP_DEFAULT_MODEL', 'gemini-3.1-flash-lite-preview' );
	$plugin_dir = $argv[1] ?? dirname( __DIR__ );
	define( 'INFYP_PLUGIN_DIR', $plugin_dir . '/' );

	$GLOBALS['opts']       = [];
	$GLOBALS['hooks']      = [];
	$GLOBALS['schemas']    = [];
	$GLOBALS['connectors'] = [];
	$GLOBALS['next']       = null;
	$GLOBALS['rows']       = [];
	$GLOBALS['fails']      = 0;
	$GLOBALS['passes']     = 0;

	function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['opts'] ) ? $GLOBALS['opts'][ $k ] : $d; }
	function _doing_it_wrong( $f, $m, $v ) {}
	function add_action( $h, $cb, $p = 10, $n = 1 ) { return true; }
	function add_filter( $h, $cb, $p = 10, $n = 1 ) { return true; }
	function do_action( $h, ...$args ) {}
	function apply_filters( $h, $v, ...$args ) { return $v; }
	function sanitize_text_field( $s ) { return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $s ) ) ); }
	function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
	function sanitize_file_name( $s ) { return preg_replace( '/[^A-Za-z0-9._\-]/', '', (string) $s ); }
	function get_attached_file( $id ) { return false; }
	function get_post_mime_type( $id ) { return 'image/jpeg'; }
	function get_current_user_id() { return 1; }
	function get_transient( $k ) { return false; }
	function set_transient( $k, $v, $e = 0 ) { return true; }
	function delete_transient( $k ) { return true; }
	function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
	function esc_html__( $s, $d = '' ) { return esc_html( $s ); }
	function __( $s, $d = '' ) { return $s; }
	function is_wp_error( $v ) { return $v instanceof WP_Error; }
	function wp_get_connectors() { return $GLOBALS['connectors']; }
	function wp_ai_client_prompt( $text ) { return new Harness_Builder(); }

	class WP_Error {
		public $code; public $message; public $data;
		function __construct( $c = '', $m = '', $d = null ) { $this->code = $c; $this->message = $m; $this->data = $d; }
		function get_error_code() { return $this->code; }
		function get_error_message() { return $this->message; }
	}

	// Stand-in for WP_AI_Client_Prompt_Builder: records the schema, returns
	// whatever the scenario queued (a result, a WP_Error, or a throwable).
	class Harness_Builder {
		function is_supported_for_text_generation() { return true; }
		function with_file( $f ) { return $this; }
		function using_model_preference( $m ) { return $this; }
		function using_temperature( $t ) { return $this; }
		function as_json_response( $s ) { $GLOBALS['schemas'][] = $s; return $this; }
		function generate_result() {
			$next = $GLOBALS['next'];
			if ( $next instanceof Throwable ) { throw $next; }
			return $next;
		}
	}
	class Harness_Result {
		private $text; private $id;
		function __construct( $text, $id ) { $this->text = $text; $this->id = $id; }
		function toText() { return $this->text; }
		function getModelMetadata() { return $this; }
		function getId() { return $this->id; }
	}
	class Harness_Wpdb {
		public $prefix = 'wp_';
		function insert( $t, $row ) { $GLOBALS['rows'][] = $row; return 1; }
	}
	$GLOBALS['wpdb'] = new Harness_Wpdb();

	function check( $label, $cond ) {
		if ( $cond ) { $GLOBALS['passes']++; } else { $GLOBALS['fails']++; echo "FAIL  $label\n"; }
	}
	function connector( $id, $name ) {
		return [ 'name' => $name, 'type' => 'ai_provider', 'authentication' => [ 'method' => 'none' ] ];
	}
	// Any keyword Anthropic's structured output rejects, anywhere in a schema.
	// Property maps are skipped as keyword holders (their keys are names).
	function anthropic_rejects( array $schema ) {
		$bad = [];
		if ( isset( $schema['type'] ) && is_string( $schema['type'] ) ) {
			foreach ( [ 'maxItems', 'uniqueItems', 'minLength', 'maxLength', 'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf' ] as $k ) {
				if ( array_key_exists( $k, $schema ) ) { $bad[] = $k; }
			}
			if ( isset( $schema['minItems'] ) && $schema['minItems'] > 1 ) { $bad[] = 'minItems>1'; }
		}
		foreach ( $schema as $v ) { if ( is_array( $v ) ) { $bad = array_merge( $bad, anthropic_rejects( $v ) ); } }
		return $bad;
	}
}

namespace IPAIS\Core {
	class INFYP_Logger { public static function log( $m, $l = 'DEBUG', $c = [] ) {} }
}

namespace {
	use IPAIS\Core\AI\INFYP_WP_AI_Provider as P;
	use IPAIS\Core\AI\INFYP_WP_AI_Support as S;
	use IPAIS\Core\AI\INFYP_AI_Provider_Base as B;
	use IPAIS\Core\INFYP_API_Retry_Handler as H;

	require $plugin_dir . '/vendor/autoload.php';

	// Image bytes are never read: seed the provider's per-request base64 cache.
	$cache = new ReflectionProperty( B::class, 'base64_cache' );
	$cache->setAccessible( true );
	$cache->setValue( null, [ 7 => 'AAAA' ] );

	$p    = new P();
	$ok   = new Harness_Result( '{"keywords":["a","b","c"],"filename":"f","alt_text":"a","title":"t"}', 'x' );
	$call = function ( $model, $type = 'full' ) use ( $p, $ok ) {
		$GLOBALS['schemas'] = [];
		$GLOBALS['next']    = $ok;
		if ( 'full' === $type ) { $p->call_full( '', 'prompt', 7, $model ); }
		elseif ( 'keywords' === $type ) { $p->call_keywords( '', 'prompt', 7, $model ); }
		else { $p->call_single_field( '', 'prompt', 7, $model, 'alt_text' ); }
		return $GLOBALS['schemas'][0] ?? null;
	};

	// Schemas as shipped in 1.0.9 before this fix (Gemini/OpenAI must keep them byte for byte).
	$full_json = '{"type":"object","properties":{"keywords":{"type":"array","items":{"type":"string"},"minItems":1,"maxItems":10},"filename":{"type":"string"},"alt_text":{"type":"string"},"title":{"type":"string"}},"required":["keywords","filename","alt_text","title"],"additionalProperties":false}';
	$kw_json   = '{"type":"object","properties":{"keywords":{"type":"array","items":{"type":"string"},"minItems":1,"maxItems":10}},"required":["keywords"],"additionalProperties":false}';
	$sf_json   = '{"type":"object","properties":{"alt_text":{"type":"string"}},"required":["alt_text"],"additionalProperties":false}';

	// --- Schema: all three providers connected, each model served by its own provider.
	$GLOBALS['connectors'] = [ 'google' => connector( 'google', 'Google' ), 'anthropic' => connector( 'anthropic', 'Anthropic' ), 'openai' => connector( 'openai', 'OpenAI' ) ];
	S::reset_cache();
	foreach ( [ 'gemini-3.1-flash-lite', 'gemini-3.5-flash-lite', 'gpt-6-luna' ] as $m ) {
		check( "$m full schema unchanged", $full_json === json_encode( $call( $m ) ) );
		check( "$m keywords schema unchanged", $kw_json === json_encode( $call( $m, 'keywords' ) ) );
		check( "$m single-field schema unchanged", $sf_json === json_encode( $call( $m, 'single' ) ) );
	}
	foreach ( [ 'claude-haiku-5-5', 'claude-haiku-4-5-20251001', 'claude-example-model' ] as $m ) {
		foreach ( [ 'full', 'keywords', 'single' ] as $t ) {
			$s = $call( $m, $t );
			check( "$m $t schema sent", is_array( $s ) );
			check( "$m $t no rejected keyword (" . implode( ',', anthropic_rejects( (array) $s ) ) . ')', [] === anthropic_rejects( (array) $s ) );
			check( "$m $t keeps additionalProperties false", false === ( $s['additionalProperties'] ?? null ) );
		}
		$s = $call( $m );
		check( "$m keeps minItems 1 and required", 1 === $s['properties']['keywords']['minItems'] && [ 'keywords', 'filename', 'alt_text', 'title' ] === $s['required'] );
		check( "$m full = shipped minus maxItems", str_replace( ',"maxItems":10', '', $full_json ) === json_encode( $s ) );
	}

	// --- Schema: substitution. Gemini preferred, only Anthropic connected → core serves Claude.
	$GLOBALS['connectors'] = [ 'anthropic' => connector( 'anthropic', 'Anthropic' ) ];
	check( 'gemini without its connector, anthropic only: stripped', [] === anthropic_rejects( $call( 'gemini-3.1-flash-lite' ) ) );
	check( 'luna without its connector, anthropic only: stripped', [] === anthropic_rejects( $call( 'gpt-6-luna' ) ) );
	$GLOBALS['connectors'] = [ 'google' => connector( 'google', 'Google' ), 'openai' => connector( 'openai', 'OpenAI' ) ];
	check( 'no anthropic connector: gemini unchanged', $full_json === json_encode( $call( 'gemini-3.1-flash-lite' ) ) );
	check( 'no anthropic connector: luna unchanged', $full_json === json_encode( $call( 'gpt-6-luna' ) ) );

	// --- Stripper on a schema with every constrained keyword.
	$has_strip = method_exists( P::class, 'strip_anthropic_unsupported' );
	check( 'stripper exists', $has_strip );
	$strip = $has_strip ? new ReflectionMethod( P::class, 'strip_anthropic_unsupported' ) : null;
	if ( $strip ) { $strip->setAccessible( true ); }
	$wide = [
		'type'                 => 'object',
		'properties'           => [
			'minimum' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 9, 'multipleOf' => 2, 'exclusiveMinimum' => 0, 'exclusiveMaximum' => 10 ],
			'tags'    => [ 'type' => 'array', 'items' => [ 'type' => 'string', 'minLength' => 2, 'maxLength' => 40, 'pattern' => '^[a-z]+$' ], 'minItems' => 2, 'maxItems' => 5, 'uniqueItems' => true ],
			'one'     => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'minItems' => 1 ],
		],
		'required'             => [ 'minimum', 'tags', 'one' ],
		'additionalProperties' => false,
	];
	$out = $strip ? $strip->invoke( null, $wide ) : $wide;
	check( 'stripper: nothing rejected left', [] === anthropic_rejects( $out ) );
	check( 'stripper: property named like a keyword kept', isset( $out['properties']['minimum'] ) && 'integer' === $out['properties']['minimum']['type'] );
	check( 'stripper: minItems 2 -> 1, minItems 1 kept', 1 === $out['properties']['tags']['minItems'] && 1 === $out['properties']['one']['minItems'] );
	check( 'stripper: pattern, required, additionalProperties kept', '^[a-z]+$' === $out['properties']['tags']['items']['pattern'] && $wide['required'] === $out['required'] && false === $out['additionalProperties'] );

	// --- Error mapping.
	$GLOBALS['connectors'] = [ 'anthropic' => connector( 'anthropic', 'Anthropic' ) ];
	$run = function ( $next ) use ( $p ) {
		$GLOBALS['next'] = $next;
		return $p->call_full( '', 'prompt', 7, 'claude-haiku-5-5' );
	};
	$reason = "Bad Request (400) - output_format.schema: For 'array' type, property 'maxItems' is not supported";
	$e = $run( new WP_Error( 'prompt_client_error', $reason, [ 'status' => 400 ] ) );
	check( '400: WP_Error', $e instanceof WP_Error );
	check( '400: provider error code', 'api_http_error' === $e->get_error_code() );
	check( '400: existing string with the provider reason', 'Optimization error: ' . $reason === $e->get_error_message() );
	check( '400: not reported as missing connector', false === strpos( $e->get_error_message(), 'No AI connector' ) );
	$e = $run( new WP_Error( 'prompt_client_error', 'Unauthorized (401) - invalid x-api-key', [ 'status' => 401 ] ) );
	check( '401: provider error with reason', 'api_http_error' === $e->get_error_code() && false !== strpos( $e->get_error_message(), 'invalid x-api-key' ) );
	$e = $run( new WP_Error( 'prompt_client_error', 'Too Many Requests (429) - rate limited', [ 'status' => 429 ] ) );
	check( '429: still rate_limit', 'rate_limit' === $e->get_error_code() );
	$e = $run( new WP_Error( 'prompt_upstream_server_error', 'Service Unavailable (503)', [ 'status' => 503 ] ) );
	check( '503: still server_error', 'server_error' === $e->get_error_code() );
	$e = $run( new WP_Error( 'prompt_invalid_argument', 'No models found that support the requested capabilities.', [ 'status' => 400 ] ) );
	check( 'no model for the request: still wp_ai_not_configured', 'wp_ai_not_configured' === $e->get_error_code() );
	$e = $run( new WP_Error( 'prompt_prevented', 'AI features are disabled.', [ 'status' => 503 ] ) );
	check( 'prevented: still wp_ai_not_configured', 'wp_ai_not_configured' === $e->get_error_code() );
	$e = $run( new RuntimeException( 'No provider available' ) );
	check( 'thrown, no provider: still wp_ai_not_configured', 'wp_ai_not_configured' === $e->get_error_code() );
	$e = $run( new RuntimeException( 'Something else broke' ) );
	check( 'thrown, other: passes through as wp_ai_client_error', 'wp_ai_client_error' === $e->get_error_code() );
	$GLOBALS['schemas'] = [];
	$cfg =new ReflectionProperty( S::class, 'configured_cache' );
	$cfg->setAccessible( true );
	$cfg->setValue( null, false );
	$e = $run( $ok );
	check( 'really no connector: wp_ai_not_configured, no request', 'wp_ai_not_configured' === $e->get_error_code() && [] === $GLOBALS['schemas'] );
	S::reset_cache();

	// --- Plugin-side limits on a reply the schema no longer bounds.
	$svc = ( new ReflectionClass( IPAIS\Core\INFYP_Optimization_Service::class ) )->newInstanceWithoutConstructor();
	$many = array_map( function ( $i ) { return "keyword $i"; }, range( 1, 25 ) );
	$GLOBALS['next'] = new Harness_Result( json_encode( [ 'keywords' => $many, 'filename' => 'f', 'alt_text' => 'a', 'title' => 't' ] ), 'claude-haiku-5-5' );
	$reply = $p->call_full( '', 'prompt', 7, 'claude-haiku-5-5' );
	check( '25 keywords come back from the provider', 25 === count( $reply['keywords'] ) );
	$new = $svc->process_result( $reply, [ 'filename' => 'f' ], 7 );
	check( 'stored keywords cut to infyp_max_keywords (3)', [ 'keyword 1', 'keyword 2', 'keyword 3' ] === $new['keywords'] );
	$GLOBALS['next'] = new Harness_Result( json_encode( [ 'keywords' => $many ] ), 'claude-haiku-5-5' );
	$kw = $p->call_keywords( '', 'prompt', 7, 'claude-haiku-5-5' );
	check( 'keywords-only reply passes validation (cap applied by the caller)', is_array( $kw ) && 25 === count( $kw['keywords'] ) );

	// --- Usage rows: wp_ai priced like the direct path for the same tokens.
	$serving = new ReflectionProperty( P::class, 'last_serving_model' );
	$serving->setAccessible( true );
	$result = [ 'keywords' => [ 'a', 'b' ], 'filename' => 'f', 'alt_text' => str_repeat( 'x', 400 ), 'title' => 't' ];
	$cases  = [
		[ 'gemini-3.1-flash-lite-preview', 'google', 'gemini-3.1-flash-lite', 'gemini-3.1-flash-lite-preview' ],
		[ 'claude-haiku-4-5', 'anthropic', 'claude-haiku-4-5-20251001', 'claude-haiku-4-5' ],
		[ 'gpt-6-luna', 'openai', 'gpt-6-luna', 'gpt-6-luna' ],
		// Retired id still saved: the row names the model that answered.
		[ 'gpt-4.1-mini', 'openai', 'gpt-6-luna', 'gpt-6-luna' ],
		// Substitution: Claude preferred, Gemini served → priced as Gemini.
		[ 'claude-haiku-5-5', 'anthropic', 'gemini-3.1-flash-lite', 'gemini-3.1-flash-lite-preview' ],
	];
	foreach ( $cases as $c ) {
		list( $model, $direct_provider, $api_id, $expect_model ) = $c;
		$GLOBALS['rows'] = [];
		$serving->setValue( null, $api_id );
		H::execute_with_retry( function () use ( $result ) { return $result; }, 'wordpress', $model, 7 );
		$serving->setValue( null, '' );
		H::execute_with_retry( function () use ( $result ) { return $result; }, $direct_provider, $expect_model, 7 );
		list( $wp, $direct ) = $GLOBALS['rows'];
		check( "usage $model via $api_id: row model $expect_model (got {$wp['model']})", $expect_model === $wp['model'] );
		check( "usage $model via $api_id: cost equals direct ({$wp['cost']} vs {$direct['cost']})", $wp['cost'] === $direct['cost'] && $wp['tokens_used'] === $direct['tokens_used'] );
		check( "usage $model via $api_id: row model has a registry price", null !== IPAIS\Core\AI\INFYP_Model_Config_Registry::get_instance()->get_config( $wp['model'] ) );
	}
	// An id the registry does not know is still logged as reported.
	$GLOBALS['rows'] = [];
	$serving->setValue( null, 'some-other-model' );
	H::execute_with_retry( function () use ( $result ) { return $result; }, 'wordpress', 'claude-haiku-5-5', 7 );
	check( 'unknown serving id logged as reported', 'some-other-model' === $GLOBALS['rows'][0]['model'] );

	printf( "wp-ai-provider: %d passed, %d failed\n", $GLOBALS['passes'], $GLOBALS['fails'] );
	exit( $GLOBALS['fails'] ? 1 : 0 );
}
