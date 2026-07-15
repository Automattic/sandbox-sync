<?php
/**
 * wp eval-file helper: fetches one directory tar with and without gz=1 and
 * reports wire sizes + gzip magic bytes.
 * Usage (on the sandbox): wp eval-file fixtures/check-tar-gzip.php
 */

use SandboxSync\Auth;
use SandboxSync\Env;

$conn = Auth::get_connection();
if ( ! $conn ) {
	echo "Not connected.\n";
	exit( 1 );
}

foreach ( array( 'plain' => 0, 'gzip' => 1 ) as $label => $gz ) {
	$url  = $conn['source_url'] . '/?sandbox_sync=tar&dir=themes' . ( $gz ? '&gz=1' : '' );
	$ch   = curl_init( $url );
	curl_setopt_array(
		$ch,
		array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_SSL_VERIFYPEER => Env::ssl_verify( $url ),
			CURLOPT_SSL_VERIFYHOST => 0,
			CURLOPT_HTTPHEADER     => array( 'Authorization: Bearer ' . $conn['token'] ),
		)
	);
	$body = curl_exec( $ch );
	curl_close( $ch );

	echo sprintf(
		"%-6s %8.2f MB  magic=%s\n",
		$label,
		strlen( (string) $body ) / 1048576,
		"\x1f\x8b" === substr( (string) $body, 0, 2 ) ? 'gzip' : 'tar'
	);
}
