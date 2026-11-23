<?php
// Harness for the upload path and rename redirects:
// - infyp_after_image_upload fires once per completed upload (server-side and
//   client-side sub-sizes), never on regeneration;
// - infyp_is_upload_complete();
// - the pending marker survives cache flushes and transient sweeps, expires;
// - redirects of a renamed image (real INFYP_File_Renamer on real temp files),
//   case-sensitive URLs on a case-insensitive column, self-redirect guard.
//
// Uses WordPress core's real hook API (wp-includes/plugin.php) and the real
// plugin classes. Storage is an in-memory SQLite database behind a minimal
// $wpdb; the redirect table's URL columns use a case-insensitive collation,
// like MySQL's default.
//
// Usage: php tests/upload-path-harness.php <wordpress-core-dir> [plugin-dir]
// Needs PHP with pdo_sqlite. Not shipped (tests/ is excluded from the build).

namespace IPAIS\Core {
	class INFYP_Logger { public static function log() {} }
	class INFYP_Security_Manager {
		public static function get_instance() { return new self(); }
		public function validate_filename( $name ) { return (bool) preg_match( '/^[A-Za-z0-9._-]+$/', $name ); }
	}
	class INFYP_Error_Handler {
		public static function get_instance() { return new self(); }
		public function log_error() {}
	}
}

namespace IPAIS\Core\PageBuilders {
	class INFYP_PageBuilder_Manager {
		public static function get_instance() { return new self(); }
		public function process_url_updates( $map, $options ) { return []; }
	}
}

namespace {
	if ( empty( $argv[1] ) || ! is_file( $argv[1] . '/wp-includes/plugin.php' ) ) {
		fwrite( STDERR, "Usage: php tests/upload-path-harness.php <wordpress-core-dir> [plugin-dir]\n" );
		exit( 2 );
	}
	define( 'ABSPATH', rtrim( $argv[1], '/' ) . '/' );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'MINUTE_IN_SECONDS', 60 );
	$plugin_dir = $argv[2] ?? dirname( __DIR__ );
	require ABSPATH . 'wp-includes/plugin.php';

	// ---- Storage: SQLite behind a minimal $wpdb --------------------------
	class Harness_DB {
		public $prefix    = 'wp_';
		public $options   = 'wp_options';
		public $postmeta  = 'wp_postmeta';
		public $insert_id = 0;
		public $pdo;

		public function __construct() {
			$this->pdo = new PDO( 'sqlite::memory:' );
			$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
			$this->pdo->exec( 'CREATE TABLE wp_options (option_name TEXT PRIMARY KEY, option_value TEXT)' );
			$this->pdo->exec( 'CREATE TABLE wp_postmeta (meta_id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER, meta_key TEXT, meta_value TEXT)' );
			$this->pdo->exec( "CREATE TABLE wp_infyp_redirects (id INTEGER PRIMARY KEY AUTOINCREMENT, image_id INTEGER, old_url TEXT COLLATE NOCASE, new_url TEXT COLLATE NOCASE, status TEXT DEFAULT 'active', hit_count INTEGER DEFAULT 0, last_hit_at TEXT, created_at TEXT, expires_at TEXT, created_by INTEGER DEFAULT 0, source TEXT DEFAULT 'manual', pagebuilder_results TEXT)" );
		}
		public function esc_like( $s ) { return addcslashes( $s, '_%\\' ); }
		public function prepare( $query, ...$args ) {
			if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
			$i = 0;
			return preg_replace_callback( '/%[sd]/', function ( $m ) use ( &$i, $args ) {
				$v = $args[ $i++ ];
				return '%d' === $m[0] ? (string) (int) $v : $this->pdo->quote( (string) $v );
			}, $query );
		}
		// MySQL -> SQLite: BINARY comparison, UNSIGNED cast, backslash LIKE escapes.
		private function translate( $q ) {
			$q = preg_replace( '/\bBINARY\s+(\w+)/', '$1 COLLATE BINARY', $q );
			$q = str_replace( 'AS UNSIGNED', 'AS INTEGER', $q );
			return preg_replace( "/LIKE\s+('(?:[^']|'')*')/", "LIKE $1 ESCAPE '\\\\'", $q );
		}
		public function query( $q ) { $st = $this->pdo->prepare( $this->translate( $q ) ); $st->execute(); return $st->rowCount(); }
		public function get_var( $q ) { $v = $this->pdo->query( $this->translate( $q ) )->fetchColumn(); return false === $v ? null : $v; }
		public function get_row( $q ) { $r = $this->pdo->query( $this->translate( $q ) )->fetch( PDO::FETCH_OBJ ); return $r ?: null; }
		public function get_results( $q ) { return $this->pdo->query( $this->translate( $q ) )->fetchAll( PDO::FETCH_OBJ ); }
		public function insert( $table, $data, $format = null ) {
			$cols = array_keys( $data );
			$st   = $this->pdo->prepare( "INSERT INTO $table (" . implode( ',', $cols ) . ') VALUES (:' . implode( ',:', $cols ) . ')' );
			$st->execute( array_combine( array_map( fn( $c ) => ':' . $c, $cols ), array_values( $data ) ) );
			$this->insert_id = (int) $this->pdo->lastInsertId();
			return 1;
		}
		public function update( $table, $data, $where, $f = null, $wf = null ) {
			$set = implode( ',', array_map( fn( $c ) => "$c = :s_$c", array_keys( $data ) ) );
			$whr = implode( ' AND ', array_map( fn( $c ) => "$c = :w_$c", array_keys( $where ) ) );
			$params = [];
			foreach ( $data as $c => $v ) { $params[ ":s_$c" ] = $v; }
			foreach ( $where as $c => $v ) { $params[ ":w_$c" ] = $v; }
			$st = $this->pdo->prepare( "UPDATE $table SET $set WHERE $whr" );
			$st->execute( $params );
			return $st->rowCount();
		}
		public function delete( $table, $where, $f = null ) {
			$whr = implode( ' AND ', array_map( fn( $c ) => "$c = :$c", array_keys( $where ) ) );
			$st  = $this->pdo->prepare( "DELETE FROM $table WHERE $whr" );
			$st->execute( array_combine( array_map( fn( $c ) => ':' . $c, array_keys( $where ) ), array_values( $where ) ) );
			return $st->rowCount();
		}
	}
	$GLOBALS['wpdb'] = new Harness_DB();
	function hdb() { return $GLOBALS['wpdb']; }

	// Options (plain array), transients (wp_options rows, as core stores them without an object cache).
	$GLOBALS['OPT'] = [];
	function get_option( $k, $d = false ) { return $GLOBALS['OPT'][ $k ] ?? $d; }
	function update_option( $k, $v, $a = null ) { $GLOBALS['OPT'][ $k ] = $v; return true; }
	function delete_option( $k ) { unset( $GLOBALS['OPT'][ $k ] ); return true; }
	function set_transient( $k, $v, $ttl = 0 ) {
		delete_transient( $k );
		hdb()->insert( 'wp_options', [ 'option_name' => "_transient_$k", 'option_value' => serialize( $v ) ] );
		if ( $ttl ) { hdb()->insert( 'wp_options', [ 'option_name' => "_transient_timeout_$k", 'option_value' => (string) ( time() + $ttl ) ] ); }
		return true;
	}
	function get_transient( $k ) {
		$t = hdb()->get_var( hdb()->prepare( 'SELECT option_value FROM wp_options WHERE option_name = %s', "_transient_timeout_$k" ) );
		if ( null !== $t && (int) $t < time() ) { delete_transient( $k ); return false; }
		$v = hdb()->get_var( hdb()->prepare( 'SELECT option_value FROM wp_options WHERE option_name = %s', "_transient_$k" ) );
		return null === $v ? false : unserialize( $v );
	}
	function delete_transient( $k ) {
		hdb()->delete( 'wp_options', [ 'option_name' => "_transient_$k" ] );
		hdb()->delete( 'wp_options', [ 'option_name' => "_transient_timeout_$k" ] );
		return true;
	}

	// Object cache (in memory; wp_cache_flush() empties it).
	$GLOBALS['OC'] = [];
	function wp_cache_get( $k, $g = '' ) { return $GLOBALS['OC'][ "$g:$k" ] ?? false; }
	function wp_cache_set( $k, $v, $g = '', $e = 0 ) { $GLOBALS['OC'][ "$g:$k" ] = $v; return true; }
	function wp_cache_delete( $k, $g = '' ) { unset( $GLOBALS['OC'][ "$g:$k" ] ); return true; }
	function wp_cache_flush() { $GLOBALS['OC'] = []; return true; }
	function wp_cache_flush_group( $g ) { foreach ( array_keys( $GLOBALS['OC'] ) as $k ) { if ( 0 === strpos( $k, "$g:" ) ) { unset( $GLOBALS['OC'][ $k ] ); } } return true; }
	function wp_cache_supports( $f ) { return true; }
	function wp_using_ext_object_cache() { return false; }

	// Post meta in SQLite; actions as core fires them; no write when unchanged.
	function get_post_meta( $id, $k, $single = false ) {
		$v = hdb()->get_var( hdb()->prepare( 'SELECT meta_value FROM wp_postmeta WHERE post_id = %d AND meta_key = %s ORDER BY meta_id LIMIT 1', $id, $k ) );
		return null === $v ? '' : h_unser( $v );
	}
	function update_post_meta( $id, $k, $v ) {
		$old = hdb()->get_var( hdb()->prepare( 'SELECT meta_value FROM wp_postmeta WHERE post_id = %d AND meta_key = %s', $id, $k ) );
		if ( null !== $old && $old === h_ser( $v ) ) { return false; }
		if ( null === $old ) {
			hdb()->insert( 'wp_postmeta', [ 'post_id' => $id, 'meta_key' => $k, 'meta_value' => h_ser( $v ) ] );
			do_action( 'added_post_meta', hdb()->insert_id, $id, $k, $v );
		} else {
			hdb()->update( 'wp_postmeta', [ 'meta_value' => h_ser( $v ) ], [ 'post_id' => $id, 'meta_key' => $k ] );
			do_action( 'updated_post_meta', 1, $id, $k, $v );
		}
		return true;
	}
	function delete_post_meta( $id, $k ) {
		$n = hdb()->delete( 'wp_postmeta', [ 'post_id' => $id, 'meta_key' => $k ] );
		if ( $n ) { do_action( 'deleted_post_meta', [], $id, $k, '' ); }
		return (bool) $n;
	}
	function wp_get_attachment_metadata( $id, $unfiltered = false ) { $m = get_post_meta( $id, '_wp_attachment_metadata', true ); return '' === $m ? false : $m; }
	function wp_update_attachment_metadata( $id, $data ) { return update_post_meta( $id, '_wp_attachment_metadata', $data ); }

	// Posts, files, URLs.
	class WP_Post { public $ID; public $post_name; public $post_type = 'attachment'; public function __construct( $id, $name ) { $this->ID = $id; $this->post_name = $name; } }
	class WP_REST_Request { private $p; public function __construct( $p ) { $this->p = $p; } public function get_param( $k ) { return $this->p[ $k ] ?? null; } }
	class WP_Error { public function __construct( ...$a ) {} }
	class Harness_Redirect extends Exception {}
	$GLOBALS['POSTS'] = []; $GLOBALS['FILES'] = [];
	$GLOBALS['UPLOADS'] = sys_get_temp_dir() . '/infyp-harness-' . bin2hex( random_bytes( 6 ) );
	@mkdir( $GLOBALS['UPLOADS'] . '/2026/11', 0777, true );
	$GLOBALS['UPLOADS'] = realpath( $GLOBALS['UPLOADS'] );
	// Remove the scratch folder even when a check aborts the run.
	register_shutdown_function( static function () {
		$dir = $GLOBALS['UPLOADS'];
		array_map( 'unlink', glob( $dir . '/2026/11/*' ) ?: [] );
		@rmdir( $dir . '/2026/11' ); @rmdir( $dir . '/2026' ); @rmdir( $dir );
	} );
	define( 'BASEURL', 'https://example.test/wp-content/uploads' );
	function get_post( $id ) { return $GLOBALS['POSTS'][ $id ] ?? null; }
	function get_post_type( $id ) { return isset( $GLOBALS['POSTS'][ $id ] ) ? 'attachment' : false; }
	function wp_attachment_is_image( $id ) { return true; }
	function wp_upload_dir() { return [ 'basedir' => $GLOBALS['UPLOADS'], 'baseurl' => BASEURL, 'error' => false ]; }
	function wp_get_upload_dir() { return wp_upload_dir(); }
	function _wp_relative_upload_path( $p ) { $b = $GLOBALS['UPLOADS'] . '/'; return 0 === strpos( $p, $b ) ? substr( $p, strlen( $b ) ) : $p; }
	function get_attached_file( $id, $u = false ) { return $GLOBALS['FILES'][ $id ] ?? false; }
	function update_attached_file( $id, $f ) { $GLOBALS['FILES'][ $id ] = $f; return true; }
	function wp_get_attachment_url( $id ) { return BASEURL . '/' . _wp_relative_upload_path( $GLOBALS['FILES'][ $id ] ); }
	function wp_update_post( $arr ) {
		$id = $arr['ID']; $before = clone $GLOBALS['POSTS'][ $id ];
		$GLOBALS['POSTS'][ $id ]->post_name = strtolower( $arr['post_name'] );
		do_action( 'attachment_updated', $id, $GLOBALS['POSTS'][ $id ], $before );
		return $id;
	}
	// Server-side sub-sizes: creates the files, then runs the metadata filter like core.
	$GLOBALS['THROW'] = false; $GLOBALS['NSIZES'] = [];
	function wp_generate_attachment_metadata( $id, $file ) {
		if ( $GLOBALS['THROW'] ) { throw new RuntimeException( 'editor failed' ); }
		$dims = array_slice( [ 'thumbnail' => '150x150', 'medium' => '300x200', 'medium_large' => '768x512', 'large' => '1024x683', '1536x1536' => '1536x1024', '2048x2048' => '2048x1365' ], 0, $GLOBALS['NSIZES'][ $id ] ?? 3, true );
		$info = pathinfo( $file ); $meta = [ 'file' => _wp_relative_upload_path( $file ), 'sizes' => [] ];
		foreach ( $dims as $name => $d ) {
			$f = $info['filename'] . "-$d." . $info['extension'];
			touch( $info['dirname'] . '/' . $f );
			$meta['sizes'][ $name ] = [ 'file' => $f, 'width' => 1, 'height' => 1, 'mime-type' => 'image/jpeg' ];
		}
		return apply_filters( 'wp_generate_attachment_metadata', $meta, $id, 'create' );
	}

	// Misc WordPress API.
	function absint( $v ) { return abs( (int) $v ); }
	function wp_doing_ajax() { return false; }
	function sanitize_text_field( $s ) { return $s; }
	// Like core: arrays and objects serialized, scalars stored as strings.
	function h_ser( $v ) { return is_array( $v ) || is_object( $v ) ? serialize( $v ) : (string) $v; }
	function h_unser( $v ) { $u = @unserialize( $v ); return false === $u && 'b:0;' !== $v ? $v : $u; }
	function __( $s ) { return $s; }
	function current_user_can( $c ) { return true; }
	function get_current_user_id() { return 1; }
	function get_current_blog_id() { return 1; }
	function is_multisite() { return false; }
	function is_admin() { return false; }
	function is_wp_error( $v ) { return $v instanceof WP_Error; }
	function current_datetime() { return new DateTimeImmutable( 'now' ); }
	function wp_date( $f ) { return gmdate( $f ); }
	function wp_parse_args( $a, $d ) { return array_merge( $d, (array) $a ); }
	function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
	function wp_basename( $p ) { return basename( $p ); }
	function untrailingslashit( $s ) { return rtrim( $s, '/\\' ); }
	function trailingslashit( $s ) { return untrailingslashit( $s ) . '/'; }
	function esc_url_raw( $u ) { return $u; }
	function wp_unslash( $v ) { return $v; }
	function home_url( $p = '' ) { return 'https://example.test' . $p; }
	function clean_attachment_cache() {}
	function wp_safe_redirect( $location, $status = 302 ) { throw new Harness_Redirect( $location ); }
	$GLOBALS['CSMP'] = true;
	function wp_is_client_side_media_processing_enabled() { return $GLOBALS['CSMP']; }
	$GLOBALS['wp_filesystem'] = new class() {
		public function move( $a, $b ) { return rename( $a, $b ); }
		public function delete( $f ) { return @unlink( $f ); }
	};

	foreach ( [ 'Core/INFYP_Cache_Manager', 'Core/INFYP_Filename_Updater', 'Core/INFYP_File_Renamer', 'Core/INFYP_Redirect_Manager', 'Database/INFYP_Redirect_Table', 'Core/INFYP_Plugin_API' ] as $f ) {
		require $plugin_dir . '/includes/' . $f . '.php';
	}

	use IPAIS\Core\INFYP_Cache_Manager as CM;
	use IPAIS\Core\INFYP_File_Renamer;
	use IPAIS\Database\INFYP_Redirect_Table;

	$cm = CM::get_instance();
	new IPAIS\Core\INFYP_Filename_Updater();
	$rm = IPAIS\Core\INFYP_Redirect_Manager::get_instance();

	$fires = [];
	add_action( 'infyp_after_image_upload', function ( $id, $meta ) use ( &$fires ) {
		$saved = wp_get_attachment_metadata( $id );
		$fires[] = [ $id, count( $meta['sizes'] ?? [] ), count( $saved['sizes'] ?? [] ) ];
	}, 10, 2 );

	$fail = 0; $pass = 0;
	function check( $label, $cond ) { global $fail, $pass; if ( $cond ) { $pass++; } else { $fail++; echo "FAIL $label\n"; } }
	// A new request: per-request state is empty, stored data stays.
	function new_request() {
		foreach ( [ 'awaiting_client_sub_sizes', 'regenerating', 'completed_uploads' ] as $p ) { ( new ReflectionProperty( CM::class, $p ) )->setValue( null, [] ); }
	}
	function sizes( $stem, $n ) {
		$s = [];
		foreach ( array_slice( [ 'thumbnail' => '150x150', 'medium' => '300x200', 'medium_large' => '768x512', 'large' => '1024x683', '1536x1536' => '1536x1024', '2048x2048' => '2048x1365' ], 0, $n, true ) as $k => $d ) {
			$s[ $k ] = [ 'file' => "$stem-$d.jpg", 'width' => 1, 'height' => 1, 'mime-type' => 'image/jpeg' ];
		}
		return $s;
	}
	function gen( $id, $meta, $ctx = 'create' ) { return apply_filters( 'wp_generate_attachment_metadata', $meta, $id, $ctx ); }
	function add_att( $id, $name ) { $GLOBALS['POSTS'][ $id ] = new WP_Post( $id, $name ); do_action( 'add_attachment', $id ); }
	function client_create( $id, $name ) {
		add_att( $id, $name );
		do_action( 'rest_insert_attachment', get_post( $id ), new WP_REST_Request( [ 'generate_sub_sizes' => false ] ), true );
		wp_update_attachment_metadata( $id, gen( $id, [ 'file' => "2026/11/$name.jpg", 'sizes' => [] ] ) );
	}
	function finalize( $id, $n ) {
		new_request();
		$m = wp_get_attachment_metadata( $id ); $m['sizes'] = sizes( "f$id", $n );
		wp_update_attachment_metadata( $id, gen( $id, $m, 'update' ) );
	}
	function complete( $id ) { return infyp_is_upload_complete( $id ); }

	// ---- 1. Upload paths ---------------------------------------------------
	new_request(); $fires = [];
	add_att( 10, 'img_4711' );
	check( 'classic: pending after create', ! complete( 10 ) );
	wp_update_attachment_metadata( 10, [ 'file' => '2026/11/IMG_4711.jpg', 'sizes' => sizes( 'IMG_4711', 5 ) ] );
	$m = gen( 10, [ 'file' => '2026/11/IMG_4711.jpg', 'sizes' => sizes( 'IMG_4711', 5 ), 'filesize' => 5 ] );
	check( 'classic: not fired before the save', [] === $fires );
	wp_update_attachment_metadata( 10, $m );
	check( 'classic: fired once after the save, sizes in saved metadata', [ [ 10, 5, 5 ] ] === $fires );
	check( 'classic: complete, marker row gone', complete( 10 ) && '' === get_post_meta( 10, CM::UPLOAD_PENDING_META, true ) );

	new_request(); $fires = [];
	add_att( 11, 'a' );
	wp_update_attachment_metadata( 11, [ 'sizes' => sizes( 'a', 3 ) ] );
	wp_update_attachment_metadata( 11, gen( 11, [ 'sizes' => sizes( 'a', 3 ) ] ) );
	check( 'classic, metadata unchanged by the filter: fired once', [ [ 11, 3, 3 ] ] === $fires );

	new_request(); $fires = [];
	client_create( 20, 'photo' );
	check( 'client: no fire in the create request', [] === $fires );
	new_request();
	check( 'client: incomplete between create and finalize', ! complete( 20 ) );
	finalize( 20, 6 );
	check( 'client: fired once at finalize after the save', [ [ 20, 6, 6 ] ] === $fires );
	check( 'client: complete after finalize', complete( 20 ) );

	new_request(); $fires = [];
	client_create( 30, 'small' );
	new_request();
	$listener = function ( $id ) { if ( 30 === $id ) { $m = wp_get_attachment_metadata( 30 ); $m['changed_by_listener'] = true; wp_update_attachment_metadata( 30, $m ); } };
	add_action( 'infyp_after_image_upload', $listener, 20 );
	$m = gen( 30, wp_get_attachment_metadata( 30 ), 'update' );
	check( 'no sub-sizes: fired once although nothing is saved', [ [ 30, 0, 0 ] ] === $fires );
	wp_update_attachment_metadata( 30, $m );
	remove_action( 'infyp_after_image_upload', $listener, 20 );
	check( 'no sub-sizes: the caller\'s save keeps the listener\'s change', ! empty( wp_get_attachment_metadata( 30 )['changed_by_listener'] ) && 1 === count( $fires ) );

	new_request(); $fires = [];
	add_att( 40, 'api' );
	do_action( 'rest_insert_attachment', get_post( 40 ), new WP_REST_Request( [ 'generate_sub_sizes' => true ] ), true );
	wp_update_attachment_metadata( 40, gen( 40, [ 'sizes' => [] ] ) );
	check( 'REST upload with server sub-sizes: fired in the first request', [ [ 40, 0, 0 ] ] === $fires );

	// REST client that sends generate_sub_sizes=false and never finalizes.
	new_request(); $fires = []; $GLOBALS['CSMP'] = false;
	client_create( 41, 'api-nofinalize' );
	check( 'client-side processing disabled: such a client gets one fire', [ [ 41, 0, 0 ] ] === $fires && complete( 41 ) );
	$GLOBALS['CSMP'] = true; new_request(); $fires = [];
	client_create( 42, 'api-nofinalize-2' );
	check( 'client-side processing enabled: waits for finalize (no fire, pending)', [] === $fires && ! complete( 42 ) );

	new_request(); $fires = [];
	foreach ( [ 51, 52, 53 ] as $id ) { client_create( $id, "f$id" ); new_request(); }
	check( '3 files: no fire after the creates', [] === $fires );
	foreach ( [ 52, 51, 53 ] as $id ) { finalize( $id, 5 ); }
	check( '3 files: one fire each, in finalize order', [ 52, 51, 53 ] === array_column( $fires, 0 ) );

	new_request(); $fires = [];
	CM::begin_metadata_regeneration( 10 ); wp_update_attachment_metadata( 10, gen( 10, [ 'sizes' => sizes( 'new', 5 ) ] ) ); CM::end_metadata_regeneration( 10 );
	wp_update_attachment_metadata( 10, gen( 10, [ 'sizes' => sizes( 'new2', 5 ) ] ) );
	check( 'existing image: rename and thumbnail regeneration fire 0 times', [] === $fires );

	new_request(); $fires = [];
	client_create( 60, 'race' );
	new_request(); CM::begin_metadata_regeneration( 60 ); wp_update_attachment_metadata( 60, gen( 60, [ 'sizes' => sizes( 'renamed', 5 ) ] ) ); CM::end_metadata_regeneration( 60 );
	check( 'rename while an upload is pending: no fire, still pending', [] === $fires && ! complete( 60 ) );
	finalize( 60, 5 );
	check( 'rename while pending: one fire at finalize', 1 === count( $fires ) );

	new_request(); $fires = [];
	add_att( 80, 'del' ); hdb()->delete( 'wp_postmeta', [ 'post_id' => 80 ] ); do_action( 'delete_attachment', 80 );
	check( 'deleted while pending: no marker, not an attachment any more', '' === get_post_meta( 80, CM::UPLOAD_PENDING_META, true ) );

	new_request(); $fires = [];
	add_att( 90, 'nosave' ); gen( 90, [ 'sizes' => sizes( 'nosave', 2 ) ] );
	do_action( 'shutdown' );
	check( 'metadata never saved: shutdown fires once', 1 === count( $fires ) );
	do_action( 'shutdown' );
	check( 'metadata never saved: no second fire', 1 === count( $fires ) );
	check( 'non-attachment ID: not complete', ! complete( 999 ) );

	// ---- 2. Marker survives cache clearing mid-batch -----------------------
	new_request(); $fires = [];
	foreach ( [ 101, 102, 103 ] as $id ) { client_create( $id, "b$id" ); new_request(); }
	finalize( 101, 5 );
	// File 1 is renamed (another tab, an add-on) while 2 and 3 still receive sub-sizes:
	// the rename clears the plugin caches, a page-builder adapter flushes the object cache.
	new_request();
	$cm->clear_all_cache();
	wp_cache_flush();
	check( 'cache clear mid-batch: files 2 and 3 still pending', ! complete( 102 ) && ! complete( 103 ) );
	finalize( 102, 5 );
	check( 'cache clear mid-batch: file 2 fires at its finalize, file 3 still pending', [ 101, 102 ] === array_column( $fires, 0 ) && complete( 102 ) && ! complete( 103 ) );
	finalize( 103, 5 );
	check( 'cache clear mid-batch: one fire each', [ 101, 102, 103 ] === array_column( $fires, 0 ) );

	// ---- 3. Abandoned upload -------------------------------------------------
	new_request(); $fires = [];
	client_create( 70, 'gone' );
	client_create( 71, 'fresh' );
	hdb()->update( 'wp_postmeta', [ 'meta_value' => (string) ( time() - CM::UPLOAD_PENDING_TTL - 1 ) ], [ 'post_id' => 70, 'meta_key' => CM::UPLOAD_PENDING_META ] );
	new_request();
	check( 'abandoned: expired marker counts as complete', complete( 70 ) && ! complete( 71 ) );
	wp_update_attachment_metadata( 70, gen( 70, [ 'sizes' => sizes( 'gone', 4 ) ] ) );
	check( 'abandoned: a later regeneration does not fire late', [] === $fires );
	check( 'abandoned: maintenance deletes the expired marker only', 1 === CM::delete_expired_upload_markers() && '' === get_post_meta( 70, CM::UPLOAD_PENDING_META, true ) && ! complete( 71 ) );

	// ---- 4. Renames and redirects (real renamer, real files) ------------------
	function upload_file( $id, $name, $n, $extra = [] ) {
		$dir = $GLOBALS['UPLOADS'] . '/2026/11';
		touch( "$dir/$name" );
		$stem = pathinfo( $name, PATHINFO_FILENAME );
		$meta = array_merge( [ 'file' => "2026/11/$name", 'sizes' => sizes( $stem, $n ) ], $extra );
		foreach ( $meta['sizes'] as $s ) { touch( "$dir/{$s['file']}" ); }
		if ( ! empty( $meta['original_image'] ) ) { touch( "$dir/{$meta['original_image']}" ); }
		$GLOBALS['FILES'][ $id ] = "$dir/$name";
		$GLOBALS['POSTS'][ $id ] = new WP_Post( $id, strtolower( $stem ) );
		update_post_meta( $id, '_wp_attachment_metadata', $meta );
		return $meta;
	}
	function rows( $id ) { return hdb()->get_results( hdb()->prepare( 'SELECT old_url, new_url FROM wp_infyp_redirects WHERE image_id = %d', $id ) ); }
	function target( $url ) { $r = INFYP_Redirect_Table::get_by_old_url( $url ); return $r ? $r->new_url : null; }
	function serve( $url ) {
		global $rm;
		$_SERVER['REQUEST_URI'] = parse_url( $url, PHP_URL_PATH );
		try { $rm->maybe_serve_redirect(); } catch ( Harness_Redirect $r ) { return $r->getMessage(); }
		return null;
	}
	$U = BASEURL . '/2026/11/';

	new_request(); $fires = [];
	upload_file( 200, 'IMG_4711.jpg', 5 ); $GLOBALS['NSIZES'][200] = 5;
	check( 'rename upper-case: renamer succeeds', true === INFYP_File_Renamer::rename_file( 200, 'red-bike' ) );
	$r = rows( 200 );
	check( 'rename upper-case: one row per old file (main + 5 sizes)', 6 === count( $r ) );
	check( 'rename upper-case: case of old names kept', ( target( $U . 'IMG_4711-300x200.jpg' ) ) === $U . 'red-bike-300x200.jpg' && $U . 'red-bike.jpg' === target( $U . 'IMG_4711.jpg' ) );
	check( 'rename upper-case: old size URL answers 301 to the new size', $U . 'red-bike-1024x683.jpg' === serve( $U . 'IMG_4711-1024x683.jpg' ) );
	check( 'rename: action fired 0 times', [] === $fires );
	check( 'rename: new size files exist, old ones deleted', is_file( $GLOBALS['UPLOADS'] . '/2026/11/red-bike-150x150.jpg' ) && ! is_file( $GLOBALS['UPLOADS'] . '/2026/11/IMG_4711-150x150.jpg' ) );

	upload_file( 201, 'DSC_0001-scaled.jpg', 6, [ 'original_image' => 'DSC_0001.jpg' ] ); $GLOBALS['NSIZES'][201] = 5;
	$meta201 = wp_get_attachment_metadata( 201 ); foreach ( $meta201['sizes'] as $k => $s ) { $meta201['sizes'][ $k ]['file'] = str_replace( '-scaled', '', $s['file'] ); touch( $GLOBALS['UPLOADS'] . '/2026/11/' . $meta201['sizes'][ $k ]['file'] ); } update_post_meta( 201, '_wp_attachment_metadata', $meta201 );
	INFYP_File_Renamer::rename_file( 201, 'harbour' );
	check( 'rename large: -scaled main, original and 6 sizes each get a row', 8 === count( rows( 201 ) ) );
	check( 'rename large: -scaled and pre-scale original -> new main file', $U . 'harbour.jpg' === target( $U . 'DSC_0001-scaled.jpg' ) && $U . 'harbour.jpg' === target( $U . 'DSC_0001.jpg' ) );
	check( 'rename large: a size that no longer exists -> new main file', $U . 'harbour.jpg' === target( $U . 'DSC_0001-2048x1365.jpg' ) );

	upload_file( 202, 'Photo.jpg', 2 ); $GLOBALS['NSIZES'][202] = 2;
	INFYP_File_Renamer::rename_file( 202, 'photo' );
	check( 'case-only rename keeps its rows (not deleted as self-referencing)', 3 === count( rows( 202 ) ) );
	check( 'case-only rename: old URL redirects', $U . 'photo-150x150.jpg' === serve( $U . 'Photo-150x150.jpg' ) );
	check( 'case-only rename: request for the target itself does not redirect to itself', null === serve( $U . 'photo-150x150.jpg' ) );

	upload_file( 203, 'Sun.jpg', 1 ); $GLOBALS['NSIZES'][203] = 1;
	upload_file( 204, 'sun.jpg', 1 ); $GLOBALS['NSIZES'][204] = 1;
	INFYP_File_Renamer::rename_file( 203, 'sun-upper' );
	INFYP_File_Renamer::rename_file( 204, 'sun-lower' );
	check( 'two files differing only by case keep separate rows', 2 === count( rows( 203 ) ) && 2 === count( rows( 204 ) ) );
	check( 'lookup prefers the byte-exact row', $U . 'sun-upper.jpg' === target( $U . 'Sun.jpg' ) && $U . 'sun-lower.jpg' === target( $U . 'sun.jpg' ) );
	hdb()->insert( 'wp_infyp_redirects', [ 'image_id' => 299, 'old_url' => $U . 'legacy_cam.jpg', 'new_url' => $U . 'legacy-new.jpg', 'status' => 'active' ] );
	check( 'row stored lower-cased by an older version still matches', $U . 'legacy-new.jpg' === target( $U . 'LEGACY_CAM.jpg' ) );

	upload_file( 205, 'Exc.jpg', 2 ); $GLOBALS['NSIZES'][205] = 2;
	$GLOBALS['THROW'] = true; $threw = false;
	try { INFYP_File_Renamer::rename_file( 205, 'exc-one' ); } catch ( RuntimeException $e ) { $threw = true; }
	$GLOBALS['THROW'] = false;
	$regen = ( new ReflectionProperty( CM::class, 'regenerating' ) )->getValue();
	$progress = ( new ReflectionProperty( IPAIS\Core\INFYP_Filename_Updater::class, 'renames_in_progress' ) )->getValue();
	check( 'exception during regeneration: no state left behind', $threw && [] === $regen && [] === $progress );
	check( 'exception during regeneration: a later rename of the image is handled', true === INFYP_File_Renamer::rename_file( 205, 'exc-two' ) && $U . 'exc-two.jpg' === target( $U . 'exc-one.jpg' ) );

	// ---- 5. Slug edit without a file rename ------------------------------------
	$before = count( hdb()->get_results( 'SELECT id FROM wp_infyp_redirects' ) );
	upload_file( 206, 'plain.jpg', 2 );
	$b = clone get_post( 206 ); $GLOBALS['POSTS'][206]->post_name = 'plain-2';
	do_action( 'attachment_updated', 206, get_post( 206 ), $b );
	check( 'slug edit without a file rename: no redirect rows', count( hdb()->get_results( 'SELECT id FROM wp_infyp_redirects' ) ) === $before );


	echo "$pass passed, $fail failed\n";
	exit( $fail ? 1 : 0 );
}
