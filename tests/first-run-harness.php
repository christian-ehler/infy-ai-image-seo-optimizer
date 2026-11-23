<?php
// Key-free harness for the first run:
// - the setup wizard's key step: a key the provider rejects is not stored and
//   blocks the step (error payload with the provider's reason, as plain
//   text); a key that could not be checked (transport failure, unexpected
//   status) is stored and reported as unverified; nonce and capability
//   checks come first;
// - the active model follows the provider of the stored key (key step and
//   wizard completion);
// - wizard completion reports whether images can be optimized, and the final
//   screen does not claim "ready" without a working connection (API keys or
//   the WordPress AI connector), and names what is missing;
// - a failed Optimize returns an error payload with an escaped message.
//
// Real classes: INFYP_Setup_Wizard, INFYP_API_Key_Manager (three-state live
// test against scripted HTTP responses), INFYP_Model_Config_Registry,
// INFYP_WP_AI_Support, INFYP_AI_Ajax. Stubbed: WordPress functions, the
// encryption service, the optimization service. No network, no API keys.
//
// Usage: php tests/first-run-harness.php [plugin_dir]
// Needs vendor/autoload.php (composer dump-autoload).

namespace {
	define( 'ABSPATH', '/tmp/' );
	define( 'INFYP_DEFAULT_MODEL', 'gemini-3.1-flash-lite-preview' );
	define( 'MINUTE_IN_SECONDS', 60 );
	define( 'HOUR_IN_SECONDS', 3600 );
	$plugin_dir = $argv[1] ?? dirname( __DIR__ );
	define( 'INFYP_PLUGIN_DIR', rtrim( $plugin_dir, '/' ) . '/' );
	define( 'INFYP_PLUGIN_URL', 'https://example.test/wp-content/plugins/infy-ai-image-seo-optimizer/' );

	$GLOBALS['opts']       = [];
	$GLOBALS['transients'] = [];
	$GLOBALS['hooks']      = [];
	$GLOBALS['http']       = [];   // provider slug => scripted response
	$GLOBALS['requests']   = [];
	$GLOBALS['json']       = null; // first wp_send_json_* call of a request
	$GLOBALS['nonce_ok']   = true;
	$GLOBALS['caps']       = true;
	$GLOBALS['fails']      = 0;
	$GLOBALS['passes']     = 0;

	function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['opts'] ) ? $GLOBALS['opts'][ $k ] : $d; }
	function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
	function delete_option( $k ) { unset( $GLOBALS['opts'][ $k ] ); return true; }
	function get_transient( $k ) { return $GLOBALS['transients'][ $k ] ?? false; }
	function set_transient( $k, $v, $t = 0 ) { $GLOBALS['transients'][ $k ] = $v; return true; }
	function delete_transient( $k ) { unset( $GLOBALS['transients'][ $k ] ); return true; }
	function _doing_it_wrong( $f, $m, $v ) {}
	function add_action( $h, $cb, $p = 10, $n = 1 ) { return true; }
	function add_filter( $h, $cb, $p = 10, $n = 1 ) { $GLOBALS['hooks'][ $h ][] = $cb; return true; }
	function do_action( $h, ...$args ) {}
	function apply_filters( $h, $v, ...$args ) { foreach ( $GLOBALS['hooks'][ $h ] ?? [] as $cb ) { $v = $cb( $v, ...$args ); } return $v; }
	function current_user_can( $c, ...$args ) { return $GLOBALS['caps']; }
	function check_ajax_referer( $a, $q = false, $die = true ) { return $GLOBALS['nonce_ok']; }
	function sanitize_text_field( $s ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $s ) ) ); }
	function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
	function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
	function wp_unslash( $v ) { return is_array( $v ) ? array_map( 'wp_unslash', $v ) : stripslashes( (string) $v ); }
	function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
	function absint( $v ) { return abs( (int) $v ); }
	function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
	function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
	function esc_url( $s ) { return (string) $s; }
	function __( $s, $d = '' ) { return $s; }
	function esc_html__( $s, $d = '' ) { return esc_html( $s ); }
	function esc_html_e( $s, $d = '' ) { echo esc_html( $s ); }
	function esc_attr_e( $s, $d = '' ) { echo esc_attr( $s ); }
	function _n( $one, $many, $n, $d = '' ) { return 1 === (int) $n ? $one : $many; }
	function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
	function wp_json_encode( $v ) { return json_encode( $v ); }
	function is_wp_error( $v ) { return $v instanceof WP_Error; }
	function wp_send_json_success( $d = null ) {
		if ( null === $GLOBALS['json'] ) { $GLOBALS['json'] = [ 'success' => true, 'data' => $d ]; }
		throw new Harness_Json_Sent();
	}
	function wp_send_json_error( $d = null, $s = null ) {
		if ( null === $GLOBALS['json'] ) { $GLOBALS['json'] = [ 'success' => false, 'data' => $d ]; }
		throw new Harness_Json_Sent();
	}
	// Scripted provider answers: [ 'code' => int, 'body' => string ] or 'transport'.
	function wp_remote_get( $url, $args = [] ) {
		$GLOBALS['requests'][] = $url;
		foreach ( $GLOBALS['http'] as $needle => $answer ) {
			if ( false !== strpos( $url, $needle ) ) {
				return 'transport' === $answer ? new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) : $answer;
			}
		}
		return [ 'code' => 200, 'body' => '{}' ];
	}
	function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
	function wp_remote_retrieve_body( $r ) { return $r['body']; }

	class Harness_Json_Sent extends Exception {}
	class WP_Error {
		public $code; public $message;
		function __construct( $c = '', $m = '', $d = null ) { $this->code = $c; $this->message = $m; }
		function get_error_message() { return $this->message; }
		function get_error_code() { return $this->code; }
	}

	function check( $label, $cond ) {
		if ( $cond ) { $GLOBALS['passes']++; } else { $GLOBALS['fails']++; echo "FAIL  $label\n"; }
	}
}

namespace IPAIS\Core {
	class INFYP_Logger { public static function log( ...$a ) {} }
	// Reversible stand-in; the real service needs OpenSSL and site salts.
	class INFYP_Encryption_Service {
		public static function get_instance() { return new self(); }
		public function encrypt( $d ) { return 'enc:' . base64_encode( (string) $d ); }
		public function decrypt( $d ) { return 0 === strpos( (string) $d, 'enc:' ) ? base64_decode( substr( $d, 4 ) ) : false; }
		public function get_masked_key( $k, $v = 4 ) { return str_repeat( '*', 8 ) . substr( $k, -$v ); }
	}
	class INFYP_Security_Manager {
		public static function get_instance() { return new self(); }
		public function validate_attachment_id( $id ) { return $id > 0; }
	}
	class INFYP_Cache_Manager { public static function get_instance() { return new self(); } }
	class INFYP_Error_Handler { public static function get_instance() { return new self(); } }
	class INFYP_Optimization_Service {
		public static $result;
		public static function get_instance() { return new self(); }
		public function run( $id, $args ) { return self::$result; }
	}
}

namespace {
	use IPAIS\Admin\INFYP_Setup_Wizard;
	use IPAIS\Core\INFYP_API_Key_Manager;

	require $plugin_dir . '/vendor/autoload.php';

	$wizard = ( new ReflectionClass( INFYP_Setup_Wizard::class ) )->newInstanceWithoutConstructor();

	const REJECT_OPENAI = [ 'code' => 401, 'body' => '{"error":{"message":"Incorrect API key provided: sk-bad. You can find your API key at <b>platform</b> &amp; more."}}' ];
	const REJECT_GOOGLE = [ 'code' => 400, 'body' => '{"error":{"message":"API key not valid. Please pass a valid API key."}}' ];
	const ACCEPT        = [ 'code' => 200, 'body' => '{"data":[]}' ];

	function fresh_site( array $opts = [] ) {
		$GLOBALS['opts']       = array_merge( [ 'infyp_optimization_settings' => [ 'ai_model' => INFYP_DEFAULT_MODEL, 'batch_size' => 10 ] ], $opts );
		$GLOBALS['transients'] = [];
		$GLOBALS['requests']   = [];
		$GLOBALS['http']       = [];
		$GLOBALS['nonce_ok']   = true;
		$GLOBALS['caps']       = true;
		INFYP_API_Key_Manager::get_instance()->clear_cache();
		\IPAIS\Core\AI\INFYP_WP_AI_Support::reset_cache();
	}

	function call( callable $fn ) {
		$GLOBALS['json'] = null;
		try { $fn(); } catch ( Harness_Json_Sent $e ) {}
		return $GLOBALS['json'];
	}

	function save_keys( $wizard, array $keys ) {
		$_POST = [ 'nonce' => 'n', 'step' => 'api-keys', 'data' => array_merge( [ 'connection_method' => 'api_keys' ], $keys ) ];
		return call( [ $wizard, 'ajax_save_step' ] );
	}

	function stored( $provider ) { return INFYP_API_Key_Manager::get_instance()->get_api_key( $provider ); }
	function model() { return get_option( 'infyp_optimization_settings' )['ai_model']; }

	// ---- Key step: rejected key -------------------------------------------
	fresh_site();
	$GLOBALS['http'] = [ 'api.openai.com' => REJECT_OPENAI ];
	$r = save_keys( $wizard, [ 'openai' => 'sk-bad-0123456789' ] );
	check( 'rejected: error response', false === $r['success'] );
	check( 'rejected: code invalid_api_key', 'invalid_api_key' === ( $r['data']['code'] ?? '' ) );
	check( 'rejected: message present', is_string( $r['data']['message'] ?? null ) && '' !== $r['data']['message'] );
	check( 'rejected: provider reason keyed by provider', isset( $r['data']['invalid']['openai'] ) && 1 === count( $r['data']['invalid'] ) );
	check( 'rejected: reason is plain text', 'Incorrect API key provided: sk-bad. You can find your API key at platform & more.' === $r['data']['invalid']['openai'] );
	check( 'rejected: key not stored', null === stored( 'openai' ) && empty( get_option( 'infyp_api_keys', [] ) ) );
	check( 'rejected: model unchanged', INFYP_DEFAULT_MODEL === model() );
	check( 'rejected: one provider request', 1 === count( $GLOBALS['requests'] ) );
	check( 'rejected: message names the provider and its reply', false !== strpos( $r['data']['message'], 'The OpenAI API key was rejected' ) && false !== strpos( $r['data']['message'], 'OpenAI: Incorrect API key provided' ) );
	check( 'rejected: message says no key was saved', false !== strpos( $r['data']['message'], 'no key from this step was saved' ) );
	check( 'rejected: message points at the skip button, not at clearing the field', false !== strpos( $r['data']['message'], '"Continue without a key"' ) && false === strpos( $r['data']['message'], 'clear the field' ) );

	// One rejected key blocks the whole submission (nothing stored).
	fresh_site();
	$GLOBALS['http'] = [ 'generativelanguage.googleapis.com' => REJECT_GOOGLE, 'api.openai.com' => ACCEPT ];
	$r = save_keys( $wizard, [ 'openai' => 'sk-good-0123456789', 'google' => 'AIza-bad-0123456789' ] );
	check( 'mixed: error response', false === $r['success'] && [ 'google' ] === array_keys( $r['data']['invalid'] ) );
	check( 'mixed: good key not stored either', null === stored( 'openai' ) && null === stored( 'google' ) );
	check( 'mixed: message names only the rejected provider', false !== strpos( $r['data']['message'], 'The Google Gemini API key was rejected' ) && false === strpos( $r['data']['message'], 'OpenAI' ) );

	// Two rejected keys: plural message naming both.
	fresh_site();
	$GLOBALS['http'] = [ 'generativelanguage.googleapis.com' => REJECT_GOOGLE, 'api.openai.com' => REJECT_OPENAI ];
	$r = save_keys( $wizard, [ 'openai' => 'sk-bad-0123456789', 'google' => 'AIza-bad-0123456789' ] );
	check( 'two rejected: plural message names both', false !== strpos( $r['data']['message'], 'The API keys for OpenAI, Google Gemini were rejected, so no key from this step was saved.' ) && false !== strpos( $r['data']['message'], 'Google Gemini: API key not valid.' ) );

	// A long provider reply is capped after it is reduced to plain text (characters, not bytes).
	fresh_site();
	$GLOBALS['http'] = [ 'api.openai.com' => [ 'code' => 401, 'body' => json_encode( [ 'error' => [ 'message' => '<p>' . str_repeat( 'Schlüssel ungültig &amp; ', 40 ) . '</p>' ] ] ) ] ];
	$r = save_keys( $wizard, [ 'openai' => 'sk-bad-0123456789' ] );
	$reply = $r['data']['invalid']['openai'] ?? '';
	check( 'long reply: capped at 300 characters with ellipsis, valid UTF-8, no tags or entities', 300 === mb_strlen( $reply, 'UTF-8' ) && "\u{2026}" === mb_substr( $reply, -1, 1, 'UTF-8' ) && 0 === strpos( $reply, 'Schlüssel ungültig & ' ) && mb_check_encoding( $reply, 'UTF-8' ) && false === strpos( $reply, '<' ) && false === strpos( $reply, '&amp;' ) && false !== strpos( $r['data']['message'], $reply ) );

	// A reply without a final full stop does not run into the next sentence.
	fresh_site();
	$GLOBALS['http'] = [ 'api.anthropic.com' => [ 'code' => 401, 'body' => '{"type":"error","error":{"type":"authentication_error","message":"invalid x-api-key"}}' ], 'api.openai.com' => REJECT_OPENAI ];
	$r = save_keys( $wizard, [ 'anthropic' => 'sk-ant-bad-0123456789', 'openai' => 'sk-bad-0123456789' ] );
	check( 'punctuation: reply ends a sentence', false !== strpos( $r['data']['message'], 'OpenAI: Incorrect API key provided: sk-bad. You can find your API key at platform & more. Anthropic Claude: invalid x-api-key. Check the keys' ) );
	check( 'punctuation: tooltip reply unchanged', 'invalid x-api-key' === $r['data']['invalid']['anthropic'] );
	$end = new ReflectionMethod( INFYP_Setup_Wizard::class, 'end_sentence' );
	$end->setAccessible( true );
	$cases = [
		'Bad key'             => 'Bad key.',
		'Bad key.'            => 'Bad key.',
		'Bad key!'            => 'Bad key!',
		'Bad key?'            => 'Bad key?',
		'キーが無効です。'    => 'キーが無効です。',
		"Cut off\u{2026}"     => "Cut off\u{2026}",
		'Say "bad key."'      => 'Say "bad key."',
		'Bad key (HTTP 401)'  => 'Bad key (HTTP 401).',
		'(Bad key.)'          => '(Bad key.)',
		'«Clé invalide.»'     => '«Clé invalide.»',
		'OpenAI'              => 'OpenAI.',
		''                    => '',
	];
	foreach ( $cases as $in => $out ) {
		check( "end_sentence '$in'", $out === $end->invoke( $wizard, (string) $in ) );
	}

	// A reply that echoes the tested key is masked before it is cached or shown.
	fresh_site();
	$GLOBALS['http'] = [ 'api.openai.com' => [ 'code' => 401, 'body' => '{"error":{"message":"Incorrect API key provided: sk-bad-01**********6789. You can find your API key at https://platform.openai.com/account/api-keys."}}' ] ];
	$r = save_keys( $wizard, [ 'openai' => 'sk-bad-0123456789' ] );
	$cached = (string) ( array_values( $GLOBALS['transients'] )[0]['message'] ?? '' );
	check( 'key echo: masked in reply, message and cache', 'Incorrect API key provided: ****. You can find your API key at https://platform.openai.com/account/api-keys.' === $r['data']['invalid']['openai'] && false === strpos( $r['data']['message'], 'sk-bad' ) && false === strpos( $cached, 'sk-bad' ) && false === strpos( $cached, '6789' ) );
	fresh_site();
	$GLOBALS['http'] = [ 'api.anthropic.com' => [ 'code' => 401, 'body' => '{"error":{"message":"Key sk-ant-bad-0123456789 is not valid"}}' ] ];
	$r = save_keys( $wizard, [ 'anthropic' => 'sk-ant-bad-0123456789' ] );
	check( 'key echo: full key masked', 'Key **** is not valid' === $r['data']['invalid']['anthropic'] );
	check( 'key echo: wording without a key unchanged', 'API key not valid. Please pass a valid API key.' === ( function () use ( $wizard ) { fresh_site(); $GLOBALS['http'] = [ 'generativelanguage' => REJECT_GOOGLE ]; return save_keys( $wizard, [ 'google' => 'AIza-bad-0123456789' ] )['data']['invalid']['google'] ?? ''; } )() );

	// Format check: no provider request for a value that cannot be a key.
	fresh_site();
	$r = save_keys( $wizard, [ 'anthropic' => 'abc' ] );
	check( 'too short: rejected without request', false === $r['success'] && isset( $r['data']['invalid']['anthropic'] ) && [] === $GLOBALS['requests'] );
	check( 'too short: not stored', null === stored( 'anthropic' ) );

	// A replacement key that is rejected keeps the stored one.
	fresh_site();
	INFYP_API_Key_Manager::get_instance()->set_api_key( 'openai', 'sk-old-0123456789' );
	$GLOBALS['http'] = [ 'api.openai.com' => REJECT_OPENAI ];
	$r = save_keys( $wizard, [ 'openai' => 'sk-bad-0123456789' ] );
	check( 'replacement rejected: old key kept', false === $r['success'] && 'sk-old-0123456789' === stored( 'openai' ) );

	// ---- Key step: valid key, model follows the key -----------------------
	foreach ( [ 'openai' => [ 'api.openai.com', 'gpt-6-luna' ], 'anthropic' => [ 'api.anthropic.com', 'claude-haiku-5-5' ], 'google' => [ 'generativelanguage', INFYP_DEFAULT_MODEL ] ] as $provider => $case ) {
		fresh_site();
		$GLOBALS['http'] = [ $case[0] => ACCEPT ];
		$r = save_keys( $wizard, [ $provider => 'key-' . $provider . '-0123456789' ] );
		check( "valid $provider: success", true === $r['success'] && ! isset( $r['data']['unverified'] ) );
		check( "valid $provider: stored", 'key-' . $provider . '-0123456789' === stored( $provider ) );
		check( "valid $provider: model -> {$case[1]}", $case[1] === model() );
	}

	// Tested on blur first: the save reuses the cached result (no second request).
	fresh_site();
	$GLOBALS['http'] = [ 'api.openai.com' => ACCEPT ];
	INFYP_API_Key_Manager::get_instance()->test_api_key( 'openai', 'sk-good-0123456789' );
	save_keys( $wizard, [ 'openai' => 'sk-good-0123456789' ] );
	check( 'blur then save: one provider request', 1 === count( $GLOBALS['requests'] ) );

	// ---- Key step: provider unreachable -----------------------------------
	foreach ( [ 'transport' => 'transport', 'http 503' => [ 'code' => 503, 'body' => '' ] ] as $label => $answer ) {
		fresh_site();
		$GLOBALS['http'] = [ 'api.openai.com' => $answer ];
		$r = save_keys( $wizard, [ 'openai' => 'sk-maybe-0123456789' ] );
		check( "$label: success (continue allowed)", true === $r['success'] );
		check( "$label: reported unverified", isset( $r['data']['unverified']['openai'] ) && '' !== $r['data']['unverified']['openai'] && ! isset( $r['data']['invalid'] ) );
		check( "$label: key stored", 'sk-maybe-0123456789' === stored( 'openai' ) );
		check( "$label: model follows the key", 'gpt-6-luna' === model() );
	}

	// ---- Key step: nothing typed, key already stored ----------------------
	fresh_site( [ 'infyp_optimization_settings' => [ 'ai_model' => 'claude-haiku-5-5' ] ] );
	INFYP_API_Key_Manager::get_instance()->set_api_key( 'anthropic', 'sk-ant-0123456789' );
	$r = save_keys( $wizard, [] );
	check( 'stored key, nothing typed: success, no request', true === $r['success'] && [] === $GLOBALS['requests'] && 'claude-haiku-5-5' === model() );

	// ---- Key step: WordPress AI chosen but unavailable -> API keys path ----
	fresh_site();
	$GLOBALS['http'] = [ 'api.openai.com' => REJECT_OPENAI ];
	$r = save_keys( $wizard, [ 'connection_method' => 'wp_ai', 'openai' => 'sk-bad-0123456789' ] );
	check( 'wp_ai unavailable: key still checked', false === $r['success'] && 'api_keys' === get_option( 'infyp_connection_method' ) );

	// ---- Security first ----------------------------------------------------
	fresh_site();
	$GLOBALS['nonce_ok'] = false;
	$r = save_keys( $wizard, [ 'openai' => 'sk-good-0123456789' ] );
	check( 'bad nonce: error, no request, nothing stored', false === $r['success'] && 'Security check failed.' === $r['data']['message'] && [] === $GLOBALS['requests'] && null === stored( 'openai' ) );
	fresh_site();
	$GLOBALS['caps'] = false;
	$r = save_keys( $wizard, [ 'openai' => 'sk-good-0123456789' ] );
	check( 'no capability: error, nothing stored', false === $r['success'] && 'Insufficient permissions.' === $r['data']['message'] && null === stored( 'openai' ) );

	// ---- Completion: readiness and the final screen ------------------------
	$_POST = [ 'nonce' => 'n' ];
	fresh_site();
	$r = call( [ $wizard, 'ajax_complete_wizard' ] );
	check( 'complete without key: success, not ready', true === $r['success'] && false === $r['data']['ready'] );
	check( 'complete without key: no ready claim', false === strpos( $r['data']['html'], 'Ready to optimize images' ) && false === strpos( $r['data']['html'], 'All Set' ) );
	check( 'complete without key: says what is missing', false !== strpos( $r['data']['html'], 'Missing: a working API key' ) );
	check( 'complete without key: links to API Keys page', false !== strpos( $r['data']['html'], 'admin.php?page=ai-image-seo-optimizer-api-keys' ) );
	check( 'complete without key: wizard marked completed', is_int( get_option( 'infyp_wizard_completed' ) ) );

	// Key added outside the wizard (or key step skipped with a stored key).
	fresh_site();
	INFYP_API_Key_Manager::get_instance()->set_api_key( 'openai', 'sk-good-0123456789' );
	$r = call( [ $wizard, 'ajax_complete_wizard' ] );
	check( 'complete with openai key: model follows the key', 'gpt-6-luna' === model() );
	check( 'complete with openai key: ready', true === $r['data']['ready'] && false !== strpos( $r['data']['html'], 'Ready to optimize images' ) );
	check( 'complete with openai key: provider counted', false !== strpos( $r['data']['html'], '1 AI provider configured' ) );

	// WordPress AI selected but not callable on this site.
	fresh_site( [ 'infyp_connection_method' => 'wp_ai' ] );
	$r = call( [ $wizard, 'ajax_complete_wizard' ] );
	check( 'complete wp_ai unavailable: not ready', false === $r['data']['ready'] );

	// Unregistered model (deactivated extension) with a key: not blocked.
	fresh_site( [ 'infyp_optimization_settings' => [ 'ai_model' => 'ext-model-x' ] ] );
	INFYP_API_Key_Manager::get_instance()->set_api_key( 'google', 'AIza-0123456789' );
	$r = call( [ $wizard, 'ajax_complete_wizard' ] );
	check( 'complete unregistered model: kept, ready', 'ext-model-x' === model() && true === $r['data']['ready'] );

	fresh_site();
	$GLOBALS['nonce_ok'] = false;
	$r = call( [ $wizard, 'ajax_complete_wizard' ] );
	check( 'complete bad nonce: error, not marked', false === $r['success'] && false === get_option( 'infyp_wizard_completed' ) );

	// ---- WordPress AI connection (core AI Client present from here on) ------
	$GLOBALS['wp_ai_configured'] = true;
	// Conditional, so it is declared here at runtime and not hoisted.
	if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
		function wp_ai_client_prompt( $text ) {
			return new class() {
				public function is_supported_for_text_generation() { return $GLOBALS['wp_ai_configured']; }
			};
		}
	}

	// Choosing WordPress AI and continuing stores the method; no key needed.
	fresh_site();
	$r = save_keys( $wizard, [ 'connection_method' => 'wp_ai' ] );
	check( 'wp_ai continue: success, method stored, no request', true === $r['success'] && 'wp_ai' === get_option( 'infyp_connection_method' ) && [] === $GLOBALS['requests'] );

	// Completing with WordPress AI and no keys: ready when a connector works.
	$_POST = [ 'nonce' => 'n' ];
	$r = call( [ $wizard, 'ajax_complete_wizard' ] );
	check( 'complete wp_ai configured, no keys: ready', true === $r['success'] && true === $r['data']['ready'] && false !== strpos( $r['data']['html'], 'Ready to optimize images' ) );
	check( 'complete wp_ai configured, no keys: no add-a-key text', false === strpos( $r['data']['html'], 'Add an API key' ) && false === strpos( $r['data']['html'], 'Missing:' ) );

	// WordPress AI selected, core AI Client present, no connector configured.
	fresh_site( [ 'infyp_connection_method' => 'wp_ai' ] );
	$GLOBALS['wp_ai_configured'] = false;
	$r = call( [ $wizard, 'ajax_complete_wizard' ] );
	check( 'complete wp_ai unconfigured: not ready', true === $r['success'] && false === $r['data']['ready'] && false === strpos( $r['data']['html'], 'Ready to optimize images' ) );
	check( 'complete wp_ai unconfigured: talks about the connector', false !== strpos( $r['data']['html'], 'Missing: a configured WordPress AI connector' ) && false !== strpos( $r['data']['html'], 'options-connectors.php' ) );
	check( 'complete wp_ai unconfigured: no API key advice', false === stripos( $r['data']['html'], 'API key' ) );
	$GLOBALS['wp_ai_configured'] = true;

	// ---- Optimize error payload --------------------------------------------
	$ajax = new \IPAIS\Ajax\INFYP_AI_Ajax();
	fresh_site();
	\IPAIS\Core\INFYP_Optimization_Service::$result = new WP_Error( 'missing_key', 'The API key for the selected model is not set.' );
	$_POST = [ 'nonce' => 'n', 'image_id' => '42' ];
	$r = call( [ $ajax, 'ajax_optimize_with_ai_handler' ] );
	check( 'optimize error: error response', false === $r['success'] );
	check( 'optimize error: only a message', [ 'message' ] === array_keys( $r['data'] ) );
	check( 'optimize error: carries the reason', false !== strpos( $r['data']['message'], 'The API key for the selected model is not set.' ) );

	\IPAIS\Core\INFYP_Optimization_Service::$result = new WP_Error( 'provider', 'Bad <img src=x onerror=alert(1)> "quota"' );
	$r = call( [ $ajax, 'ajax_optimize_with_ai_handler' ] );
	check( 'optimize error: message escaped', false === strpos( $r['data']['message'], '<img' ) && false !== strpos( $r['data']['message'], '&lt;img' ) );

	$GLOBALS['nonce_ok'] = false;
	$r = call( [ $ajax, 'ajax_optimize_with_ai_handler' ] );
	check( 'optimize bad nonce: security message', false === $r['success'] && 'Security check failed.' === $r['data']['message'] );

	printf( "first-run: %d passed, %d failed\n", $GLOBALS['passes'], $GLOBALS['fails'] );
	exit( $GLOBALS['fails'] ? 1 : 0 );
}
