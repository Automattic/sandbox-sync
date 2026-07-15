<?php
/**
 * Safety tests: rule registry defaults, fnmatch pattern matching, and the
 * wp-config.php managed-block editing.
 */

use SandboxSync\Safety;

ss_test_section( 'Safety: rule registry' );

$rules = Safety::rules();
ss_assert( isset( $rules['scrub_secrets'], $rules['block_email'], $rules['constants'], $rules['disable_cron'] ), 'registry contains the core rules' );

$defaults = Safety::default_keys();
ss_assert( in_array( 'scrub_secrets', $defaults, true ), 'scrub_secrets is on by default' );
ss_assert( in_array( 'block_email', $defaults, true ), 'block_email is on by default' );
ss_assert( ! in_array( 'disable_cron', $defaults, true ), 'disable_cron is OFF by default (would stall our own cron worker)' );
ss_assert( ! isset( $rules['anonymize_pii'] ), 'anonymize_pii rule has been removed' );
ss_assert( ! in_array( 'anonymize_pii', $defaults, true ), 'anonymize_pii is not in the default set' );
foreach ( $rules as $key => $rule ) {
	ss_assert( ! empty( $rule['desc'] ) && false !== stripos( $rule['desc'], 'Changes:' ), "rule {$key} desc states what it changes" );
}

ss_test_section( 'Safety: pattern matching (Knife semantics)' );

$names = array(
	'newspack_mailchimp_api_key',
	'mailchimp-woocommerce-store_id',
	'woocommerce_stripe_settings',
	'jetpack_private_options',
	'siteurl',
	'blogname',
);

ss_assert_same(
	array( 'newspack_mailchimp_api_key' ),
	Safety::match_patterns( array( 'newspack_mailchimp_*' ), $names ),
	'prefix wildcard matches'
);
ss_assert_same(
	array( 'newspack_mailchimp_api_key', 'mailchimp-woocommerce-store_id' ),
	Safety::match_patterns( array( '*mailchimp*' ), $names ),
	'contains wildcard matches'
);
ss_assert_same(
	array( 'woocommerce_stripe_settings' ),
	Safety::match_patterns( array( 'woocommerce_stripe_settings' ), $names ),
	'exact name matches without wildcards'
);
ss_assert_same(
	array( 'newspack_mailchimp_api_key' ),
	Safety::match_patterns( array( 'NEWSPACK_MAILCHIMP_*' ), $names ),
	'matching is case-insensitive'
);
ss_assert_same( array(), Safety::match_patterns( array( 'stripe_*' ), $names ), 'non-matching pattern matches nothing' );
ss_assert_same(
	array( 'newspack_mailchimp_api_key' ),
	Safety::match_patterns( array( '*mailchimp*', 'newspack_*' ), array( 'newspack_mailchimp_api_key' ) ),
	'a name matching several patterns is returned once'
);

ss_test_section( 'Safety: wp-config managed block' );

$block = Safety::build_config_block(
	array(
		'JETPACK_DEV_DEBUG'   => true,
		'WP_ENVIRONMENT_TYPE' => 'staging',
	)
);
ss_assert( false !== strpos( $block, "if ( ! defined( 'JETPACK_DEV_DEBUG' ) ) { define( 'JETPACK_DEV_DEBUG', true ); }" ), 'boolean constants render as literals with guards' );
ss_assert( false !== strpos( $block, "define( 'WP_ENVIRONMENT_TYPE', 'staging' );" ), 'string constants are quoted' );
ss_assert_same( '', Safety::build_config_block( array() ), 'empty constants build an empty block' );

$config = <<<'PHP'
<?php
define( 'DB_NAME', 'wp' );

/* That's all, stop editing! Happy publishing. */
require_once ABSPATH . 'wp-settings.php';
PHP;

$updated = Safety::upsert_config_block( $config, $block );
ss_assert( false !== strpos( $updated, Safety::CONFIG_BLOCK_BEGIN ), 'block is inserted' );
ss_assert(
	strpos( $updated, Safety::CONFIG_BLOCK_END ) < strpos( $updated, "That's all" ),
	'block lands before the stop-editing line'
);
ss_assert( false !== strpos( $updated, "define( 'DB_NAME', 'wp' );" ), 'existing config is untouched' );

// Re-upserting replaces (never duplicates) the managed block.
$block2   = Safety::build_config_block( array( 'DISABLE_WP_CRON' => true ) );
$updated2 = Safety::upsert_config_block( $updated, $block2 );
ss_assert_same( 1, substr_count( $updated2, Safety::CONFIG_BLOCK_BEGIN ), 'upsert replaces instead of duplicating' );
ss_assert( false !== strpos( $updated2, 'DISABLE_WP_CRON' ), 'new constants land in the replaced block' );
ss_assert( false === strpos( $updated2, 'JETPACK_DEV_DEBUG' ), 'old constants are gone after replacement' );

// Empty block removes it entirely.
$removed = Safety::upsert_config_block( $updated2, '' );
ss_assert( false === strpos( $removed, Safety::CONFIG_BLOCK_BEGIN ), 'empty block removes the managed section' );

ss_test_section( 'Safety: strip prior CLI defines' );
$with_cli = "<?php\ndefine( 'JETPACK_DEV_DEBUG', true );\ndefine( 'WP_DEBUG', false );\n/* That's all, stop editing! */\n";
$stripped = Safety::strip_config_defines( $with_cli, array( 'JETPACK_DEV_DEBUG' ) );
ss_assert( false === strpos( $stripped, 'JETPACK_DEV_DEBUG' ), 'CLI define for listed name is removed' );
ss_assert( false !== strpos( $stripped, "define( 'WP_DEBUG', false )" ), 'unlisted defines are preserved' );

ss_test_section( 'Safety: replication denylist' );
ss_assert( false !== strpos( $removed, "define( 'DB_NAME', 'wp' );" ), 'removal leaves the rest of the config intact' );

// Config without a stop-editing line: block is appended.
$no_anchor = "<?php\ndefine( 'DB_NAME', 'wp' );\n";
$appended  = Safety::upsert_config_block( $no_anchor, $block );
ss_assert( false !== strpos( $appended, Safety::CONFIG_BLOCK_BEGIN ), 'block appended when no stop-editing anchor exists' );

// Numeric constants render unquoted.
$numeric_block = Safety::build_config_block( array( 'WP_MEMORY_LIMIT' => '256M', 'AUTOSAVE_INTERVAL' => 120 ) );
ss_assert( false !== strpos( $numeric_block, "define( 'AUTOSAVE_INTERVAL', 120 );" ), 'integer constants render unquoted' );
ss_assert( false !== strpos( $numeric_block, "define( 'WP_MEMORY_LIMIT', '256M' );" ), 'string constants stay quoted' );

ss_test_section( 'Safety: parsing live wp-config constants' );

$live_config = <<<'PHP'
<?php
define( 'DB_NAME', 'live_db' );
define( 'DB_PASSWORD', 'hunter2' );
define( 'AUTH_KEY', 'abc' );
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'AUTOSAVE_INTERVAL', 120 );
define( 'SOME_RATIO', 0.5 );
define( 'WP_MEMORY_LIMIT', '256M' );
define( "NEWSPACK_FLAG", "on" );
define( 'ESCAPED_STR', 'it\'s fine' );
define( 'MY_PATH', dirname( __FILE__ ) . '/tmp' );
define( 'FROM_ENV', getenv( 'FOO' ) );
define( 'WP_DEBUG', false ); // duplicate, first one wins
	define( 'INDENTED', 'yes' );

/* That's all, stop editing! Happy publishing. */
require_once ABSPATH . 'wp-settings.php';
PHP;

$parsed  = Safety::parse_config_constants( $live_config );
$by_name = array();
foreach ( $parsed as $constant ) {
	$by_name[ $constant['name'] ] = $constant;
}

ss_assert_same( true, $by_name['WP_DEBUG']['value'], 'boolean true parses' );
ss_assert_same( false, $by_name['WP_DEBUG_DISPLAY']['value'], 'boolean false parses' );
ss_assert_same( 120, $by_name['AUTOSAVE_INTERVAL']['value'], 'integer parses' );
ss_assert_same( 0.5, $by_name['SOME_RATIO']['value'], 'float parses' );
ss_assert_same( '256M', $by_name['WP_MEMORY_LIMIT']['value'], 'single-quoted string parses' );
ss_assert_same( 'on', $by_name['NEWSPACK_FLAG']['value'], 'double-quoted string parses' );
ss_assert_same( "it's fine", $by_name['ESCAPED_STR']['value'], 'escaped quote is unescaped' );
ss_assert_same( 'yes', $by_name['INDENTED']['value'], 'indented define parses' );
ss_assert( ! $by_name['MY_PATH']['replicable'], 'expressions are not replicable' );
ss_assert( ! $by_name['FROM_ENV']['replicable'], 'function calls are not replicable' );
ss_assert_same( true, $by_name['WP_DEBUG']['value'], 'duplicate define keeps the first value' );
ss_assert_same( 1, count( array_filter( $parsed, function ( $c ) { return 'WP_DEBUG' === $c['name']; } ) ), 'duplicates are collapsed' );

// Our own managed block never leaks into the parsed list.
$with_block = Safety::upsert_config_block( $live_config, Safety::build_config_block( array( 'JETPACK_DEV_DEBUG' => true ) ) );
$reparsed   = Safety::parse_config_constants( $with_block );
ss_assert_same( array(), array_filter( $reparsed, function ( $c ) { return 'JETPACK_DEV_DEBUG' === $c['name']; } ), 'managed block constants are excluded from parsing' );

ss_test_section( 'Safety: replication denylist' );

ss_assert( ! Safety::is_offerable_constant( 'DB_PASSWORD' ), 'DB credentials are never offered' );
ss_assert( ! Safety::is_offerable_constant( 'AUTH_KEY' ), 'salts/keys are never offered' );
ss_assert( ! Safety::is_offerable_constant( 'WP_HOME' ), 'URL constants are never offered' );
ss_assert( ! Safety::is_offerable_constant( 'MY_SERVICE_API_TOKEN' ), 'token-shaped names are never offered' );
ss_assert( ! Safety::is_offerable_constant( 'SMTP_PASSWORD_BACKUP' ), 'password-shaped names are never offered' );
ss_assert( Safety::is_offerable_constant( 'WP_DEBUG' ), 'WP_DEBUG is offerable' );
ss_assert( Safety::is_offerable_constant( 'WP_MEMORY_LIMIT' ), 'WP_MEMORY_LIMIT is offerable' );
ss_assert( Safety::is_offerable_constant( 'NEWSPACK_FLAG' ), 'custom constants are offerable by default' );
ss_assert( Safety::is_offerable_constant( 'SANDBOX_SYNC_TEST' ), 'custom boolean feature flags are offerable' );
ss_assert( ! Safety::is_offerable_constant( 'AWS_ACCESS_KEY_ID' ), 'access-key identifiers are denied' );
ss_assert( ! Safety::is_offerable_constant( 'GOOGLE_APPLICATION_CREDENTIALS' ), 'credential-shaped constants are denied' );

$GLOBALS['ss_test_filters']['sandbox_sync_constant_denylist'] = function () {
	return array();
};
ss_assert( ! Safety::is_offerable_constant( 'DB_PASSWORD' ), 'filters cannot remove the built-in credential denylist' );
unset( $GLOBALS['ss_test_filters']['sandbox_sync_constant_denylist'] );

$GLOBALS['ss_test_filters']['sandbox_sync_constant_denylist'] = function () {
	return array( 'NEWSPACK_FLAG' );
};
ss_assert( ! Safety::is_offerable_constant( 'NEWSPACK_FLAG' ), 'sites can add custom constant deny patterns' );
unset( $GLOBALS['ss_test_filters']['sandbox_sync_constant_denylist'] );

ss_test_section( 'Safety: pre-activation runtime guard' );
ss_test_reset_options();
$previous = Safety::engage_runtime_guard( 'https://live.example.com' );
ss_assert_same( 1, get_option( Safety::OPT_BLOCK_EMAIL ), 'runtime guard blocks email before activation' );
ss_assert_same( array( 'https://live.example.com', 'https://example.com', 'https://admin.example.com' ), get_option( Safety::OPT_BLOCK_HTTP ), 'runtime guard allows the paired source and WordPress loopback origins' );
ss_assert( isset( $GLOBALS['ss_test_registered_filters']['pre_wp_mail'] ), 'runtime email hook is registered immediately' );
ss_assert( isset( $GLOBALS['ss_test_registered_filters']['pre_http_request'] ), 'runtime HTTP hook is registered immediately' );
$http_guard = $GLOBALS['ss_test_registered_filters']['pre_http_request'][0][0];
ss_assert_same( false, $http_guard( false, array(), 'https://example.com/wp-cron.php' ), 'runtime HTTP guard permits the home-origin cron loopback' );
ss_assert_same( false, $http_guard( false, array(), 'https://admin.example.com/wp-json/' ), 'runtime HTTP guard permits a distinct site-url loopback origin' );
ss_assert( is_wp_error( $http_guard( false, array(), 'https://api.production.example/' ) ), 'runtime HTTP guard still blocks unrelated external origins' );
Safety::restore_runtime_guard( $previous );
ss_assert_same( false, get_option( Safety::OPT_BLOCK_EMAIL ), 'email guard state restores after rollback' );
ss_assert_same( false, get_option( Safety::OPT_BLOCK_HTTP ), 'HTTP guard state restores after rollback' );
