<?php
/**
 * Dependency-free test bootstrap: minimal WordPress shims (in-memory options,
 * escaping helpers, WP_Error) so the mu-plugin classes under test can load
 * without a WordPress install. Run the suite with: php tests/run-tests.php
 */

define( 'ABSPATH', '/tmp/' );
define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/sandbox-sync-test-content' );
if ( ! is_dir( WP_CONTENT_DIR ) ) {
	mkdir( WP_CONTENT_DIR, 0700, true );
}

// -- In-memory options store -------------------------------------------------

$GLOBALS['ss_test_options'] = array();

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['ss_test_options'] ) ? $GLOBALS['ss_test_options'][ $name ] : $default;
}

function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['ss_test_options'][ $name ] = $value;
	return true;
}

function delete_option( $name ) {
	unset( $GLOBALS['ss_test_options'][ $name ] );
	return true;
}

function add_option( $name, $value, $deprecated = '', $autoload = null ) {
	if ( array_key_exists( $name, $GLOBALS['ss_test_options'] ) ) {
		return false;
	}
	$GLOBALS['ss_test_options'][ $name ] = $value;
	return true;
}

function ss_test_reset_options() {
	$GLOBALS['ss_test_options']    = array();
	$GLOBALS['ss_test_transients'] = array();
}

// -- In-memory transients ------------------------------------------------------

$GLOBALS['ss_test_transients'] = array();

function get_transient( $name ) {
	if ( ! isset( $GLOBALS['ss_test_transients'][ $name ] ) ) {
		return false;
	}
	list( $value, $expires ) = $GLOBALS['ss_test_transients'][ $name ];
	if ( $expires > 0 && $expires < time() ) {
		unset( $GLOBALS['ss_test_transients'][ $name ] );
		return false;
	}
	return $value;
}

function set_transient( $name, $value, $expiration = 0 ) {
	$GLOBALS['ss_test_transients'][ $name ] = array( $value, $expiration > 0 ? time() + $expiration : 0 );
	return true;
}

function delete_transient( $name ) {
	unset( $GLOBALS['ss_test_transients'][ $name ] );
	return true;
}

// -- Filters / environment shims -------------------------------------------------

// Filters registered by tests: [ hook => callable ].
$GLOBALS['ss_test_filters'] = array();
$GLOBALS['ss_test_registered_filters'] = array();

function apply_filters( $hook, $value, ...$args ) {
	if ( isset( $GLOBALS['ss_test_filters'][ $hook ] ) ) {
		return call_user_func( $GLOBALS['ss_test_filters'][ $hook ], $value, ...$args );
	}
	return $value;
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['ss_test_registered_filters'][ $hook ][] = array( $callback, $priority, $accepted_args );
	return true;
}

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	return add_filter( $hook, $callback, $priority, $accepted_args );
}

$GLOBALS['ss_test_env_type'] = 'production';
$GLOBALS['ss_test_home_url'] = 'https://example.com';
$GLOBALS['ss_test_site_url'] = 'https://admin.example.com';

function wp_get_environment_type() {
	return $GLOBALS['ss_test_env_type'];
}

function home_url( $path = '' ) {
	return $GLOBALS['ss_test_home_url'] . $path;
}

function site_url( $path = '' ) {
	return $GLOBALS['ss_test_site_url'] . $path;
}

function wp_salt( $scheme = 'auth' ) {
	return 'sandbox-sync-test-salt-' . $scheme;
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

// -- Misc WP shims ------------------------------------------------------------

function esc_url_raw( $url ) {
	return trim( (string) $url );
}

function untrailingslashit( $value ) {
	return rtrim( (string) $value, '/' );
}

function wp_json_encode( $value, $flags = 0, $depth = 512 ) {
	return json_encode( $value, $flags, $depth );
}

function size_format( $bytes, $decimals = 0 ) {
	return number_format( (float) $bytes, $decimals ) . ' B';
}

function add_query_arg( $args, $url ) {
	$separator = false === strpos( $url, '?' ) ? '?' : '&';
	return $url . $separator . http_build_query( $args );
}

function wp_remote_post( $url, $args = array() ) {
	if ( isset( $GLOBALS['ss_test_remote_post'] ) && is_callable( $GLOBALS['ss_test_remote_post'] ) ) {
		return call_user_func( $GLOBALS['ss_test_remote_post'], $url, $args );
	}
	return new WP_Error( 'http_request_failed', 'No test HTTP response was configured.' );
}

function wp_remote_retrieve_response_code( $response ) {
	return is_array( $response ) && isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0;
}

function wp_remote_retrieve_body( $response ) {
	return is_array( $response ) && isset( $response['body'] ) ? (string) $response['body'] : '';
}

/**
 * Copy of WordPress core's is_serialized() (wp-includes/functions.php),
 * trimmed to the strict-check-off behavior our code relies on.
 */
function is_serialized( $data, $strict = true ) {
	if ( ! is_string( $data ) ) {
		return false;
	}
	$data = trim( $data );
	if ( 'N;' === $data ) {
		return true;
	}
	if ( strlen( $data ) < 4 ) {
		return false;
	}
	if ( ':' !== $data[1] ) {
		return false;
	}
	if ( $strict ) {
		$lastc = substr( $data, -1 );
		if ( ';' !== $lastc && '}' !== $lastc ) {
			return false;
		}
	} else {
		$semicolon = strpos( $data, ';' );
		$brace     = strpos( $data, '}' );
		if ( false === $semicolon && false === $brace ) {
			return false;
		}
		if ( false !== $semicolon && $semicolon < 3 ) {
			return false;
		}
		if ( false !== $brace && $brace < 4 ) {
			return false;
		}
	}
	$token = $data[0];
	switch ( $token ) {
		case 's':
			if ( $strict ) {
				if ( '"' !== substr( $data, -2, 1 ) ) {
					return false;
				}
			} elseif ( false === strpos( $data, '"' ) ) {
				return false;
			}
			// Fall through.
		case 'a':
		case 'O':
		case 'E':
			return (bool) preg_match( "/^{$token}:[0-9]+:/s", $data );
		case 'b':
		case 'i':
		case 'd':
			$end = $strict ? '$' : '';
			return (bool) preg_match( "/^{$token}:[0-9.E+-]+;{$end}/", $data );
	}
	return false;
}

class WP_Error {
	private $code;
	private $message;
	private $data;

	public function __construct( $code = '', $message = '', $data = null ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}

	public function get_error_data() {
		return $this->data;
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

// -- Classes under test --------------------------------------------------------

define( 'SANDBOX_SYNC_TEST_MU_DIR', dirname( __DIR__ ) . '/mu-plugin/sandbox-sync' );
require_once SANDBOX_SYNC_TEST_MU_DIR . '/class-env.php';
require_once SANDBOX_SYNC_TEST_MU_DIR . '/class-error-info.php';
require_once SANDBOX_SYNC_TEST_MU_DIR . '/class-db-exporter.php';
require_once SANDBOX_SYNC_TEST_MU_DIR . '/class-table-delta.php';
require_once SANDBOX_SYNC_TEST_MU_DIR . '/class-db-shell.php';
require_once SANDBOX_SYNC_TEST_MU_DIR . '/class-db-shell-dump-reader.php';
require_once SANDBOX_SYNC_TEST_MU_DIR . '/class-auth.php';
require_once SANDBOX_SYNC_TEST_MU_DIR . '/class-search-replace.php';
require_once SANDBOX_SYNC_TEST_MU_DIR . '/class-tar-exporter.php';
require_once SANDBOX_SYNC_TEST_MU_DIR . '/class-tar-extractor.php';
require_once SANDBOX_SYNC_TEST_MU_DIR . '/class-tar-stream-extractor.php';
require_once SANDBOX_SYNC_TEST_MU_DIR . '/class-files.php';
require_once SANDBOX_SYNC_TEST_MU_DIR . '/class-uploads-state.php';
require_once SANDBOX_SYNC_TEST_MU_DIR . '/class-source-state.php';
require_once SANDBOX_SYNC_TEST_MU_DIR . '/class-safety.php';
require_once SANDBOX_SYNC_TEST_MU_DIR . '/class-job.php';
require_once SANDBOX_SYNC_TEST_MU_DIR . '/class-source-endpoints.php';
require_once SANDBOX_SYNC_TEST_MU_DIR . '/class-client.php';
require_once SANDBOX_SYNC_TEST_MU_DIR . '/trait-sync-runner-database.php';
require_once SANDBOX_SYNC_TEST_MU_DIR . '/trait-sync-runner-files.php';
require_once SANDBOX_SYNC_TEST_MU_DIR . '/class-sync-runner.php';

// -- Shared filesystem/tar test helpers -------------------------------------------

function ss_test_rmrf( $path ) {
	if ( is_link( $path ) || is_file( $path ) ) {
		unlink( $path );
		return;
	}
	if ( is_dir( $path ) ) {
		foreach ( scandir( $path ) as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				ss_test_rmrf( $path . '/' . $entry );
			}
		}
		rmdir( $path );
	}
}

/** Recomputes the ustar checksum of the first header block after tampering. */
function ss_tar_reseal_header( $bytes ) {
	$bytes    = substr_replace( $bytes, '        ', 148, 8 );
	$checksum = 0;
	for ( $i = 0; $i < 512; $i++ ) {
		$checksum += ord( $bytes[ $i ] );
	}
	return substr_replace( $bytes, str_pad( decoct( $checksum ), 6, '0', STR_PAD_LEFT ) . "\0 ", 148, 8 );
}

// -- Tiny assertion framework ---------------------------------------------------

$GLOBALS['ss_test_stats'] = array(
	'passed'   => 0,
	'failed'   => 0,
	'failures' => array(),
);

function ss_assert( $condition, $label ) {
	if ( $condition ) {
		$GLOBALS['ss_test_stats']['passed']++;
		return;
	}
	$GLOBALS['ss_test_stats']['failed']++;
	$GLOBALS['ss_test_stats']['failures'][] = $label;
	echo "  FAIL: {$label}\n";
}

function ss_assert_same( $expected, $actual, $label ) {
	if ( $expected === $actual ) {
		$GLOBALS['ss_test_stats']['passed']++;
		return;
	}
	$GLOBALS['ss_test_stats']['failed']++;
	$GLOBALS['ss_test_stats']['failures'][] = $label;
	echo "  FAIL: {$label}\n";
	echo '    expected: ' . var_export( $expected, true ) . "\n";
	echo '    actual:   ' . var_export( $actual, true ) . "\n";
}

function ss_test_section( $title ) {
	echo "\n== {$title} ==\n";
}

function ss_test_summary() {
	$stats = $GLOBALS['ss_test_stats'];
	echo "\n----------------------------------------\n";
	echo "Passed: {$stats['passed']}  Failed: {$stats['failed']}\n";
	if ( $stats['failed'] > 0 ) {
		echo "Failing assertions:\n";
		foreach ( $stats['failures'] as $failure ) {
			echo "  - {$failure}\n";
		}
		exit( 1 );
	}
	echo "All tests passed.\n";
	exit( 0 );
}
