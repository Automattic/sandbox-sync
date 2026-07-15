<?php
/**
 * Sync_Runner::plan_steps_from_manifest tests: selective options, table
 * exclusions, prefix handling.
 */

use SandboxSync\Sync_Runner;

$manifest = array(
	'site'   => array(
		'home'   => 'https://live.local',
		'prefix' => 'wp_',
	),
	'tables' => array(
		array( 'name' => 'wp_posts', 'rows' => 100, 'size' => 1000, 'avg_row' => 10 ),
		array( 'name' => 'wp_options', 'rows' => 50, 'size' => 500, 'avg_row' => 10 ),
		array( 'name' => 'wp_actionscheduler_logs', 'rows' => 9000, 'size' => 90000, 'avg_row' => 10 ),
	),
	'dirs'   => array( 'plugins', 'mu-plugins', 'themes', 'uploads' ),
);

function ss_plan_summary( $steps ) {
	return array_map(
		function ( $step ) {
			return $step['type'] . ':' . ( isset( $step['args']['dir'] ) ? $step['args']['dir'] : ( isset( $step['args']['table'] ) ? $step['args']['table'] : '' ) );
		},
		$steps
	);
}

ss_test_section( 'Plan: full defaults' );

$steps = Sync_Runner::plan_steps_from_manifest( $manifest, Sync_Runner::default_options() );
ss_assert_same(
	array(
		'dir_plan:plugins',
		'dir_plan:mu-plugins',
		'dir_plan:themes',
		'dir_plan:uploads',
		'refresh_manifest:',
		'uploads_finalize:',
		'table:wp_posts',
			'table:wp_options',
			'table:wp_actionscheduler_logs',
			'validate_db:',
		'search_replace:',
		'activate:',
		'dir_activate:plugins',
		'dir_activate:mu-plugins',
		'dir_activate:themes',
		'dir_activate:uploads',
		'safety:',
		'finalize:',
	),
	ss_plan_summary( $steps ),
	'default options stages all dirs, activates the database under a traffic guard, then activates files before finalization'
);

ss_test_section( 'Plan: selective options' );

$options         = Sync_Runner::default_options();
$options['dirs'] = array( 'plugins' );
$options['db']   = false;
ss_assert_same(
	array( 'dir_plan:plugins', 'dir_activate:plugins', 'safety:', 'finalize:' ),
	ss_plan_summary( Sync_Runner::plan_steps_from_manifest( $manifest, $options ) ),
	'files-only + single dir plans staging, deferred activation, safety, then finalization'
);

$options         = Sync_Runner::default_options();
$options['dirs'] = array();
ss_assert_same(
	array( 'refresh_manifest:', 'table:wp_posts', 'table:wp_options', 'table:wp_actionscheduler_logs', 'validate_db:', 'search_replace:', 'activate:', 'safety:', 'finalize:' ),
	ss_plan_summary( Sync_Runner::plan_steps_from_manifest( $manifest, $options ) ),
	'db-only plans no dir steps'
);

$options         = Sync_Runner::default_options();
$options['dirs'] = array( 'plugins', 'not-a-real-dir' );
$summary         = ss_plan_summary( Sync_Runner::plan_steps_from_manifest( $manifest, $options ) );
ss_assert( ! in_array( 'dir_plan:not-a-real-dir', $summary, true ), 'dirs missing from the manifest are ignored' );

ss_test_section( 'Plan: excluded tables' );

$options                    = Sync_Runner::default_options();
$options['dirs']            = array();
$options['excluded_tables'] = array( 'actionscheduler_logs' );
ss_assert_same(
	array( 'refresh_manifest:', 'table:wp_posts', 'table:wp_options', 'validate_db:', 'search_replace:', 'activate:', 'safety:', 'finalize:' ),
	ss_plan_summary( Sync_Runner::plan_steps_from_manifest( $manifest, $options ) ),
	'excluded suffixes (prefix-agnostic) are skipped'
);

// Different source prefix: exclusion still matches on the suffix.
$other_prefix                      = $manifest;
$other_prefix['site']['prefix']    = 'live_';
$other_prefix['tables'][0]['name'] = 'live_posts';
$other_prefix['tables'][1]['name'] = 'live_options';
$other_prefix['tables'][2]['name'] = 'live_actionscheduler_logs';
ss_assert_same(
	array( 'refresh_manifest:', 'table:live_posts', 'table:live_options', 'validate_db:', 'search_replace:', 'activate:', 'safety:', 'finalize:' ),
	ss_plan_summary( Sync_Runner::plan_steps_from_manifest( $other_prefix, $options ) ),
	'exclusion works across differing table prefixes'
);

ss_test_section( 'Plan: schema-only tables' );

ss_assert_same(
	array( 'newspack_newsletters_subscription_attempts' ),
	Sync_Runner::default_options()['schema_only_tables'],
	'Newspack subscription-attempt recovery data is schema-only by default'
);

$schema_manifest             = $manifest;
$schema_manifest['tables'][] = array(
	'name'    => 'wp_newspack_newsletters_subscription_attempts',
	'rows'    => 25,
	'size'    => 4096,
	'avg_row' => 160,
);
$schema_steps = Sync_Runner::plan_steps_from_manifest( $schema_manifest, Sync_Runner::default_options() );
$schema_step  = null;
foreach ( $schema_steps as $step ) {
	if ( 'table' === $step['type'] && 'wp_newspack_newsletters_subscription_attempts' === $step['args']['table'] ) {
		$schema_step = $step;
		break;
	}
}
ss_assert( is_array( $schema_step ) && ! empty( $schema_step['args']['schema_only'] ), 'schema-only suffix plans an empty-schema table step' );
ss_assert( false !== strpos( $schema_step['label'], 'Prepare empty schema-only table' ), 'schema-only plan is explicit in the operator-visible label' );

$full_options                       = Sync_Runner::default_options();
$full_options['schema_only_tables'] = array();
$full_steps                         = Sync_Runner::plan_steps_from_manifest( $schema_manifest, $full_options );
$full_schema_step                   = null;
foreach ( $full_steps as $step ) {
	if ( 'table' === $step['type'] && 'wp_newspack_newsletters_subscription_attempts' === $step['args']['table'] ) {
		$full_schema_step = $step;
		break;
	}
}
ss_assert( is_array( $full_schema_step ) && empty( $full_schema_step['args']['schema_only'] ), 'removing a suffix from schema-only restores the normal full-import policy' );

$excluded_schema_options                     = Sync_Runner::default_options();
$excluded_schema_options['excluded_tables']  = array( 'newspack_newsletters_subscription_attempts' );
$excluded_schema_steps                       = Sync_Runner::plan_steps_from_manifest( $schema_manifest, $excluded_schema_options );
$excluded_schema_table_step                  = array_filter(
	$excluded_schema_steps,
	function ( $step ) {
		return 'table' === $step['type'] && 'wp_newspack_newsletters_subscription_attempts' === $step['args']['table'];
	}
);
ss_assert_same( array(), array_values( $excluded_schema_table_step ), 'exclusion wins when a suffix is also configured as schema-only' );

ss_test_section( 'Plan: recoverable transfer scope' );

$scope_options                        = Sync_Runner::default_options();
$scope_options['excluded_tables']     = array( 'actionscheduler_logs' );
$scope_options['schema_only_tables']  = array( 'newspack_newsletters_subscription_attempts' );
$scope = Sync_Runner::transfer_table_scope(
	array(
		'options'  => $scope_options,
		'manifest' => $schema_manifest,
	)
);
ss_assert_same(
	array( 'wp_posts', 'wp_options', 'wp_newspack_newsletters_subscription_attempts' ),
	$scope['tables'],
	'expired-session recovery reconstructs the exact included table list'
);
ss_assert_same(
	array( 'wp_newspack_newsletters_subscription_attempts' ),
	$scope['schema_only_tables'],
	'expired-session recovery preserves the exact schema-only subset'
);
