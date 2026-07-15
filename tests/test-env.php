<?php
/**
 * Env tests: local-environment detection and automatic sslverify switching.
 */

use SandboxSync\Env;

ss_test_section( 'Env: local host detection' );

ss_assert_same( true, Env::is_local_host( 'localhost' ), 'localhost is local' );
ss_assert_same( true, Env::is_local_host( '127.0.0.1' ), '127.0.0.1 is local' );
ss_assert_same( true, Env::is_local_host( '192.168.1.20' ), '192.168.x.x is local' );
ss_assert_same( true, Env::is_local_host( 'live.local' ), '*.local is local' );
ss_assert_same( true, Env::is_local_host( 'my-site.test' ), '*.test is local' );
ss_assert_same( true, Env::is_local_host( 'SANDBOX.LOCAL' ), 'detection is case-insensitive' );
ss_assert_same( false, Env::is_local_host( 'example.com' ), 'example.com is not local' );
ss_assert_same( false, Env::is_local_host( 'localhost.example.com' ), 'localhost subdomain of a real domain is not local' );
ss_assert_same( false, Env::is_local_host( 'mylocal' ), 'bare word ending in "local" is not local' );
ss_assert_same( false, Env::is_local_host( '' ), 'empty host is not local' );

ss_test_section( 'Env: sslverify switching' );

$GLOBALS['ss_test_env_type'] = 'production';
$GLOBALS['ss_test_home_url'] = 'https://example.com';
ss_assert_same( true, Env::ssl_verify( 'https://source.example.org' ), 'production + real hosts: verification ON' );

ss_assert_same( false, Env::ssl_verify( 'https://live.local' ), 'local target host: verification OFF' );

$GLOBALS['ss_test_home_url'] = 'https://sandbox.local';
ss_assert_same( true, Env::ssl_verify( 'https://source.example.org' ), 'local caller still verifies a public target' );

$GLOBALS['ss_test_home_url'] = 'https://example.com';
$GLOBALS['ss_test_env_type'] = 'local';
ss_assert_same( true, Env::ssl_verify( 'https://source.example.org' ), 'local environment still verifies a public target' );
$GLOBALS['ss_test_env_type'] = 'development';
ss_assert_same( true, Env::ssl_verify( 'https://source.example.org' ), 'development environment still verifies a public target' );
$GLOBALS['ss_test_env_type'] = 'staging';
ss_assert_same( true, Env::ssl_verify( 'https://source.example.org' ), 'wp_get_environment_type staging: verification ON' );

$GLOBALS['ss_test_env_type'] = 'production';
$GLOBALS['ss_test_filters']['sandbox_sync_sslverify'] = function () {
	return false;
};
ss_assert_same( false, Env::ssl_verify( 'https://source.example.org' ), 'sandbox_sync_sslverify filter can force OFF' );
unset( $GLOBALS['ss_test_filters']['sandbox_sync_sslverify'] );

ss_test_section( 'Env: source URL policy' );

ss_assert_same( true, Env::is_allowed_source_url( 'https://source.example.org' ), 'public HTTPS source is allowed' );
ss_assert_same( false, Env::is_allowed_source_url( 'http://source.example.org' ), 'public HTTP source is rejected' );
ss_assert_same( true, Env::is_allowed_source_url( 'http://live.local' ), 'local HTTP source is allowed for development' );
ss_assert_same( false, Env::is_allowed_source_url( 'ftp://live.local' ), 'non-HTTP source scheme is rejected' );
ss_assert_same( false, Env::is_allowed_source_url( 'not-a-url' ), 'malformed source URL is rejected' );
