<?php
/**
 * Auth tests: pairing keys, multi-sandbox token registry, bearer
 * verification, per-sandbox revocation, legacy option migration.
 */

use SandboxSync\Auth;

function ss_test_set_bearer( $token ) {
	if ( null === $token ) {
		unset( $_SERVER['HTTP_AUTHORIZATION'] );
	} else {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
	}
}

ss_test_section( 'Auth: pairing key' );
ss_test_reset_options();

$key = Auth::generate_pairing_key();
ss_assert( is_string( $key ) && 40 === strlen( $key ), 'pairing key is a 40-char hex string' );
ss_assert_same( hash( 'sha256', $key ), get_option( Auth::OPT_PAIRING_KEY_HASH ), 'only the key hash is stored' );
ss_assert( abs( time() - get_option( Auth::OPT_PAIRING_KEY_CREATED ) ) < 2, 'pairing key creation time is recorded' );

ss_assert_same( false, Auth::consume_pairing_key( 'wrong-key' ), 'wrong pairing key is rejected' );
ss_assert_same( true, Auth::consume_pairing_key( $key ), 'correct pairing key is accepted' );
ss_assert_same( false, Auth::consume_pairing_key( $key ), 'pairing key is single-use' );

$expired_key = Auth::generate_pairing_key();
$expired_record = get_option( Auth::OPT_PAIRING_RECORD );
$expired_record['created_at'] = time() - Auth::PAIRING_KEY_TTL - 1;
update_option( Auth::OPT_PAIRING_RECORD, $expired_record );
ss_assert_same( false, Auth::consume_pairing_key( $expired_key ), 'expired pairing key is rejected' );
ss_assert_same( false, get_option( Auth::OPT_PAIRING_KEY_HASH ), 'expired pairing key is removed' );

ss_test_section( 'Auth: multi-sandbox tokens' );
ss_test_reset_options();

ss_assert_same( false, Auth::is_source_paired(), 'no sandboxes paired initially' );

$token_a = Auth::issue_token( 'https://sandbox-a.local' );
$token_b = Auth::issue_token( 'https://sandbox-b.local' );
ss_assert( $token_a !== $token_b, 'each sandbox gets a distinct token' );

$sandboxes = Auth::get_sandboxes();
ss_assert_same( 2, count( $sandboxes ), 'registry holds both sandboxes' );
ss_assert_same( 'https://sandbox-a.local', $sandboxes[ hash( 'sha256', $token_a ) ]['url'], 'sandbox A stored under its token hash' );
ss_assert_same( 'https://sandbox-b.local', $sandboxes[ hash( 'sha256', $token_b ) ]['url'], 'sandbox B stored under its token hash' );
ss_assert( ! in_array( $token_a, array_keys( $sandboxes ), true ), 'cleartext token is never stored' );

ss_test_set_bearer( $token_a );
ss_assert_same( true, Auth::verify_bearer_request(), 'token A authenticates' );
ss_test_set_bearer( $token_b );
ss_assert_same( true, Auth::verify_bearer_request(), 'token B authenticates' );
ss_test_set_bearer( 'not-a-real-token' );
ss_assert_same( false, Auth::verify_bearer_request(), 'unknown token is rejected' );
ss_test_set_bearer( null );
ss_assert_same( false, Auth::verify_bearer_request(), 'missing header is rejected' );

ss_test_section( 'Auth: per-sandbox revocation' );

ss_assert_same( true, Auth::revoke_sandbox( hash( 'sha256', $token_a ) ), 'revoking sandbox A succeeds' );
ss_assert_same( false, Auth::revoke_sandbox( hash( 'sha256', $token_a ) ), 'revoking sandbox A twice fails' );

ss_test_set_bearer( $token_a );
ss_assert_same( false, Auth::verify_bearer_request(), 'revoked token A no longer authenticates' );
ss_test_set_bearer( $token_b );
ss_assert_same( true, Auth::verify_bearer_request(), 'token B still authenticates after revoking A' );
ss_assert_same( true, Auth::is_source_paired(), 'still paired while B remains' );

$rotated_b = Auth::issue_token( 'https://sandbox-b.local/' );
ss_assert_same( 1, count( Auth::get_sandboxes() ), 're-pairing the same sandbox rotates instead of accumulating tokens' );
ss_test_set_bearer( $token_b );
ss_assert_same( false, Auth::verify_bearer_request(), 'rotated old token no longer authenticates' );
ss_test_set_bearer( $rotated_b );
ss_assert_same( true, Auth::verify_bearer_request(), 'rotated replacement token authenticates' );

Auth::revoke_source();
ss_test_set_bearer( $rotated_b );
ss_assert_same( false, Auth::verify_bearer_request(), 'revoke_source kills all tokens' );
ss_assert_same( false, Auth::is_source_paired(), 'no sandboxes paired after revoke_source' );
ss_test_set_bearer( null );

ss_test_section( 'Auth: legacy single-sandbox migration' );
ss_test_reset_options();

// Simulate options written by the pre-multi-sandbox version.
$legacy_token = 'legacy-token-cleartext';
update_option( Auth::LEGACY_OPT_TOKEN_HASH, hash( 'sha256', $legacy_token ) );
update_option( Auth::LEGACY_OPT_SANDBOX_URL, 'https://old-sandbox.local' );
update_option( Auth::LEGACY_OPT_PAIRED_AT, 1750000000 );

$sandboxes = Auth::get_sandboxes();
ss_assert_same( 1, count( $sandboxes ), 'legacy sandbox migrated into the registry' );
ss_assert_same( 'https://old-sandbox.local', $sandboxes[ hash( 'sha256', $legacy_token ) ]['url'], 'legacy URL migrated' );
ss_assert_same( 1750000000, $sandboxes[ hash( 'sha256', $legacy_token ) ]['paired_at'], 'legacy paired_at migrated' );
ss_assert_same( false, get_option( Auth::LEGACY_OPT_TOKEN_HASH ), 'legacy token option removed after migration' );

ss_test_set_bearer( $legacy_token );
ss_assert_same( true, Auth::verify_bearer_request(), 'legacy token still authenticates after migration' );
ss_test_set_bearer( null );

$new_token = Auth::issue_token( 'https://new-sandbox.local' );
ss_assert_same( 2, count( Auth::get_sandboxes() ), 'legacy + new sandbox coexist' );

ss_test_section( 'Auth: token TTL' );
ss_test_reset_options();

$token = Auth::issue_token( 'https://sandbox.local' );
$sandboxes = Auth::get_sandboxes();
$entry = $sandboxes[ hash( 'sha256', $token ) ];
ss_assert( isset( $entry['expires_at'] ), 'issued tokens carry an expiry' );
$delta = $entry['expires_at'] - time() - Auth::token_ttl();
ss_assert( abs( $delta ) < 5, 'expiry is now + TTL' );

ss_test_set_bearer( $token );
ss_assert_same( true, Auth::verify_bearer_request(), 'token authenticates before expiry' );

// Force the token to be expired and confirm it is pruned + rejected.
$sandboxes[ hash( 'sha256', $token ) ]['expires_at'] = time() - 10;
update_option( Auth::OPT_SANDBOXES, $sandboxes );
ss_assert_same( array(), Auth::get_sandboxes(), 'expired tokens are pruned from the registry' );
ss_assert_same( false, Auth::verify_bearer_request(), 'expired token no longer authenticates' );
ss_test_set_bearer( null );

// Entries without expires_at (pre-TTL era) get one assigned instead of dying.
update_option( Auth::OPT_SANDBOXES, array( 'somehash' => array( 'url' => 'https://x.local', 'paired_at' => 1 ) ) );
$sandboxes = Auth::get_sandboxes();
ss_assert( isset( $sandboxes['somehash']['expires_at'] ) && $sandboxes['somehash']['expires_at'] > time(), 'pre-TTL entries get an expiry assigned' );

ss_test_section( 'Auth: sandbox flag (direction guardrail)' );
ss_test_reset_options();

ss_assert_same( false, Auth::is_sandbox_site(), 'flag is off by default' );
Auth::set_sandbox_flag( true );
ss_assert_same( true, Auth::is_sandbox_site(), 'flag can be enabled' );
Auth::set_sandbox_flag( false );
ss_assert_same( false, Auth::is_sandbox_site(), 'flag can be disabled' );

ss_test_section( 'Auth: rate limiting' );
ss_test_reset_options();
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';

ss_assert_same( false, Auth::is_rate_limited(), 'fresh client is not rate limited' );
for ( $i = 0; $i < Auth::FAIL_LIMIT; $i++ ) {
	Auth::record_auth_failure();
}
ss_assert_same( true, Auth::is_rate_limited(), 'client is limited after FAIL_LIMIT failures' );
Auth::clear_auth_failures();
ss_assert_same( false, Auth::is_rate_limited(), 'successful authentication can clear the client failure budget' );
$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
ss_assert_same( false, Auth::is_rate_limited(), 'other clients are unaffected' );
unset( $_SERVER['REMOTE_ADDR'] );

ss_test_section( 'Auth: sandbox-side connection' );
ss_test_reset_options();

ss_assert_same( null, Auth::get_connection(), 'no connection initially' );
ss_assert_same( true, Auth::save_connection( 'https://live.local/', 'some-token' ), 'connection token is sealed successfully' );
$sealed = get_option( Auth::OPT_TOKEN );
ss_assert( is_array( $sealed ) && ! in_array( 'some-token', $sealed, true ), 'cleartext token is not stored in the options table' );
$conn = Auth::get_connection();
ss_assert_same( 'https://live.local', $conn['source_url'], 'source URL stored without trailing slash' );
ss_assert_same( 'some-token', $conn['token'], 'token stored on sandbox side' );

// Legacy cleartext options are transparently migrated on read.
update_option( Auth::OPT_TOKEN, 'legacy-cleartext' );
$conn = Auth::get_connection();
ss_assert_same( 'legacy-cleartext', $conn['token'], 'legacy cleartext token remains usable during migration' );
ss_assert( is_array( get_option( Auth::OPT_TOKEN ) ), 'legacy cleartext token is replaced by a sealed value' );
Auth::disconnect();
ss_assert_same( null, Auth::get_connection(), 'disconnect clears the connection' );
