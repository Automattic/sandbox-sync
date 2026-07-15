<?php
/** Real-protocol smoke test for name-only discovery and scoped value fetch. */

use SandboxSync\Client;

$name      = 'SANDBOX_SYNC_TEST';
$discovery = Client::get_json( 'constants' );
if ( is_wp_error( $discovery ) ) {
	throw new RuntimeException( $discovery->get_error_message() );
}
$names = array();
foreach ( (array) $discovery['constants'] as $constant ) {
	if ( ! is_array( $constant ) || array_keys( $constant ) !== array( 'name' ) ) {
		throw new RuntimeException( 'Constant discovery exposed fields other than the name.' );
	}
	$names[] = $constant['name'];
}
if ( ! in_array( $name, $names, true ) ) {
	throw new RuntimeException( 'Custom scalar constant is missing from discovery.' );
}
foreach ( array( 'DB_NAME', 'DB_PASSWORD', 'AUTH_KEY', 'NONCE_SALT', 'WP_HOME', 'WP_CONTENT_DIR' ) as $blocked ) {
	if ( in_array( $blocked, $names, true ) ) {
		throw new RuntimeException( "Denylisted constant was exposed: {$blocked}" );
	}
}

Client::reset_transfer_session();
$session = Client::begin_transfer_session(
	array(
		'db'               => false,
		'dirs'             => array(),
		'config_constants' => array( $name ),
	)
);
if ( is_wp_error( $session ) ) {
	throw new RuntimeException( $session->get_error_message() );
}

try {
	$response = Client::post_json( 'constants', array( 'names' => array( $name ) ) );
	if ( is_wp_error( $response ) ) {
		throw new RuntimeException( $response->get_error_message() );
	}
	$constants = (array) $response['constants'];
	if ( 1 !== count( $constants ) || $name !== $constants[0]['name'] || ! array_key_exists( 'value', $constants[0] ) ) {
		throw new RuntimeException( 'Scoped constant value response is incomplete or contains extra constants.' );
	}
	echo 'constant-scope-ok discovery_names=' . count( $names ) . ' selected=1 values_printed=0' . PHP_EOL;
} finally {
	$closed = Client::end_transfer_session();
	if ( is_wp_error( $closed ) ) {
		throw new RuntimeException( $closed->get_error_message() );
	}
}
