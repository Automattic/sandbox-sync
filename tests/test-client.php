<?php
/** Scoped transfer renewal/recovery tests over simulated HTTP responses. */

use SandboxSync\Auth;
use SandboxSync\Client;

ss_test_section( 'Client: expired scoped-session recovery' );
ss_test_reset_options();

Auth::save_connection( 'https://live.example.com', 'test-bearer' );
$transfer_id = str_repeat( 'd', 64 );
update_option( Client::OPT_TRANSFER_ID, $transfer_id, false );
update_option( Client::OPT_TRANSFER_RENEWED_AT, time() - Client::TRANSFER_RENEW_INTERVAL - 1, false );

$operations = array();
$GLOBALS['ss_test_remote_post'] = function ( $url, $args ) use ( &$operations, $transfer_id ) {
	$body         = json_decode( $args['body'], true );
	$operations[] = $body;
	if ( 'renew' === $body['operation'] ) {
		return array(
			'response' => array( 'code' => 403 ),
			'body'     => wp_json_encode( array( 'error' => 'A valid scoped transfer session is required.', 'code' => 'transfer_owner' ) ),
		);
	}
	if ( 'begin' === $body['operation'] ) {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode( array( 'transfer_id' => $transfer_id, 'expires_at' => time() + 43200 ) ),
		);
	}
	return array(
		'response' => array( 'code' => 200 ),
		'body'     => wp_json_encode(
			array(
				'scoped'                  => true,
				'table_count'             => count( $body['tables'] ),
				'schema_only_table_count' => count( $body['schema_only_tables'] ),
			)
		),
	);
};

$options = array(
	'db'                 => true,
	'dirs'               => array( 'uploads' ),
	'config_constants'   => array( 'WP_DEBUG' ),
	'schema_only_tables' => array( 'disposable_log' ),
);
$tables      = array( 'wp_posts', 'wp_disposable_log' );
$schema_only = array( 'wp_disposable_log' );
$recovered   = Client::maintain_transfer_session( $options, $tables, $schema_only );

ss_assert_same( 'reopened', $recovered, 'an explicitly expired/missing grant is reopened automatically' );
ss_assert_same( array( 'renew', 'begin', 'scope' ), array_column( $operations, 'operation' ), 'recovery renews, reopens, then restores table scope in order' );
ss_assert_same( array( 'uploads' ), $operations[1]['dirs'], 'recovery retains the exact selected directory scope' );
ss_assert_same( array( 'WP_DEBUG' ), $operations[1]['constants'], 'recovery retains the exact selected constant scope' );
ss_assert_same( $tables, $operations[2]['tables'], 'recovery restores only the exact included table list' );
ss_assert_same( $schema_only, $operations[2]['schema_only_tables'], 'recovery restores the exact schema-only subset' );
ss_assert( (int) get_option( Client::OPT_TRANSFER_RENEWED_AT ) >= time() - 1, 'successful recovery records a fresh local keepalive time' );

ss_test_section( 'Client: scoped-session recovery fails closed' );

update_option( Client::OPT_TRANSFER_RENEWED_AT, time() - Client::TRANSFER_RENEW_INTERVAL - 1, false );
$calls = 0;
$GLOBALS['ss_test_remote_post'] = function () use ( &$calls ) {
	++$calls;
	return array(
		'response' => array( 'code' => 403 ),
		'body'     => wp_json_encode( array( 'error' => 'Directory not allowed.', 'code' => 'transfer_scope' ) ),
	);
};
$denied = Client::maintain_transfer_session( $options, $tables, $schema_only );
ss_assert( is_wp_error( $denied ), 'an unrelated scope denial is not converted into a new grant' );
ss_assert_same( 1, $calls, 'an unrelated 403 fails closed without begin or scope requests' );

unset( $GLOBALS['ss_test_remote_post'] );
Auth::disconnect();
