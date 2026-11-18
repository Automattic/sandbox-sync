<?php
/**
 * Job engine and Sync_Runner internals: fenced slice leases, working-table
 * naming, durable import checkpoints, and database preflight metadata.
 */

use SandboxSync\Job;
use SandboxSync\Sync_Runner;

ss_test_section( 'Job: fenced slice lease' );
ss_test_reset_options();

ss_assert_same( true, Job::acquire_slice_lock( 'job-a' ), 'first worker acquires lease' );
$first = get_option( Job::SLICE_LOCK );
ss_assert( is_array( $first ) && 'job-a' === $first['job_id'], 'lease records job ownership' );
ss_assert_same( false, Job::acquire_slice_lock( 'job-a' ), 'second acquisition is rejected while lease is current' );
ss_assert_same( true, Job::renew_slice_lock(), 'owner can renew its lease' );
$renewed = get_option( Job::SLICE_LOCK );
ss_assert_same( $first['owner'], $renewed['owner'], 'renew keeps owner token' );
ss_assert_same( $first['fence'], $renewed['fence'], 'renew keeps fencing token' );
ss_assert_same( true, Job::renew_slice_lock( Job::CHUNK_LOCK_TTL ), 'owner can renew with the short chunk-loop TTL' );
$short = get_option( Job::SLICE_LOCK );
ss_assert( $short['expires_at'] <= time() + Job::CHUNK_LOCK_TTL && $short['expires_at'] > time() + Job::CHUNK_LOCK_TTL - 5, 'chunk-loop renewal shortens the lease so a killed worker frees the job in ~2 minutes' );
ss_assert_same( true, Job::renew_slice_lock( 1 ), 'a too-small custom TTL is accepted' );
ss_assert( get_option( Job::SLICE_LOCK )['expires_at'] >= time() + 30, 'custom TTLs are clamped to a safe minimum' );
ss_assert_same( true, Job::renew_slice_lock(), 'default renewal restores the full long-operation TTL' );
ss_assert( get_option( Job::SLICE_LOCK )['expires_at'] > time() + Job::SLICE_LOCK_TTL - 5, 'default lease expiry uses SLICE_LOCK_TTL' );
ss_assert_same( true, Job::release_slice_lock(), 'owner can release its lease' );
ss_assert_same( false, get_option( Job::SLICE_LOCK ), 'released lease option is removed' );

$orphaned_job = array(
	'id'        => 'job-orphan',
	'heartbeat' => time() - 35,
	'current'   => 0,
	'steps'     => array( array( 'status' => 'pending' ) ),
);
update_option( Job::OPT, $orphaned_job );
update_option(
	Job::SLICE_LOCK,
	array(
		'owner'       => 'vanished-before-step',
		'job_id'      => 'job-orphan',
		'fence'       => 3,
		'acquired_at' => time() - 35,
		'expires_at'  => time() + 865,
	)
);
ss_assert_same( true, Job::acquire_slice_lock( 'job-orphan' ), 'an orphan lease that never published step start is fenced after its grace period' );
ss_assert_same( 4, get_option( Job::SLICE_LOCK )['fence'], 'pre-step orphan recovery advances the fencing token' );
ss_assert_same( true, Job::release_slice_lock(), 'recovery owner releases the replaced orphan lease' );
delete_option( Job::OPT );

// Mid-step worker death: step is "running" but heartbeat has gone cold while the
// long HTTP lease is still valid. Successors must fence instead of waiting 900s.
$stale_job = array(
	'id'        => 'job-stale-hb',
	'heartbeat' => time() - Job::CHUNK_LOCK_TTL - 5,
	'current'   => 0,
	'steps'     => array( array( 'status' => 'running' ) ),
);
update_option( Job::OPT, $stale_job );
update_option(
	Job::SLICE_LOCK,
	array(
		'owner'       => 'dead-mid-step',
		'job_id'      => 'job-stale-hb',
		'fence'       => 11,
		'acquired_at' => time() - 200,
		'expires_at'  => time() + 700,
	)
);
ss_assert_same( true, Job::acquire_slice_lock( 'job-stale-hb' ), 'a lease whose job heartbeat is colder than CHUNK_LOCK_TTL is fenced' );
ss_assert_same( 12, get_option( Job::SLICE_LOCK )['fence'], 'stale-heartbeat recovery advances the fencing token' );
ss_assert_same( true, Job::release_slice_lock(), 'stale-heartbeat recovery owner releases the lease' );
delete_option( Job::OPT );

update_option(
	Job::SLICE_LOCK,
	array(
		'owner'      => 'dead-owner',
		'job_id'     => 'old-job',
		'fence'      => 7,
		'acquired_at' => time() - 2000,
		'expires_at' => time() - 1,
	)
);
ss_assert_same( true, Job::acquire_slice_lock( 'job-b' ), 'expired lease can be taken over' );
$takeover = get_option( Job::SLICE_LOCK );
ss_assert_same( 8, $takeover['fence'], 'takeover increments fencing token' );

$intruder = $takeover;
$intruder['owner'] = 'different-owner';
update_option( Job::SLICE_LOCK, $intruder );
ss_assert_same( false, Job::renew_slice_lock(), 'owner detects a lost lease' );
ss_assert_same( false, Job::release_slice_lock(), 'non-owner cannot release replacement lease' );
ss_assert_same( $intruder, get_option( Job::SLICE_LOCK ), 'replacement lease remains intact' );
delete_option( Job::SLICE_LOCK );

ss_test_section( 'Job: working-table ownership names' );

$shadow_a = Sync_Runner::working_table_name( 'shadow', 'abcdef123456', 'wp_example_table' );
$shadow_b = Sync_Runner::working_table_name( 'shadow', 'abcdef123456', 'wp_other_table' );
$other_job = Sync_Runner::working_table_name( 'shadow', '999999999999', 'wp_example_table' );
$backup = Sync_Runner::working_table_name( 'backup', 'abcdef123456', str_repeat( 'very_long_target_', 10 ) );
ss_assert( 0 === strpos( $shadow_a, 'ssx_abcdef123456_' ), 'shadow name includes owning job id' );
ss_assert( $shadow_a !== $shadow_b, 'different targets get different working names' );
ss_assert( $shadow_a !== $other_job, 'different jobs get different working names' );
ss_assert( 0 === strpos( $backup, 'ssxbak_abcdef123456_' ), 'backup name includes owning job id' );
ss_assert( strlen( $backup ) <= 64, 'working table name respects MySQL identifier limit' );

ss_test_section( 'Job: durable table-import checkpoints' );

$row = array(
	'next_offset'   => '500',
	'next_cursor'   => 'eyJJRCI6NTAwfQ',
	'total_rows'    => '500',
	'expected_rows' => '1200',
	'done'          => '0',
);
$state = Sync_Runner::validate_import_checkpoint_state( $row, 500, 'test' );
ss_assert( ! is_wp_error( $state ), 'a checkpoint matching durable shadow rows is resumable' );
ss_assert_same( 500, $state['next_offset'], 'checkpoint offsets normalize to integers' );
ss_assert_same( 'eyJJRCI6NTAwfQ', $state['next_cursor'], 'checkpoint cursor is retained exactly' );

$done                = $row;
$done['total_rows']  = '1200';
$done['next_offset'] = '1500';
$done['done']        = '1';
$done_state          = Sync_Runner::validate_import_checkpoint_state( $done, 1200, 'test' );
ss_assert( ! is_wp_error( $done_state ) && $done_state['done'], 'a complete checkpoint can finish without refetching' );

$diverged = Sync_Runner::validate_import_checkpoint_state( $row, 499, 'test' );
ss_assert_same( 'checkpoint_diverged', $diverged->get_error_code(), 'shadow/checkpoint row divergence fails closed' );

// Live inserts during keyset export can push total_rows past the opening count.
$ahead               = $row;
$ahead['total_rows'] = '1201';
$ahead['next_offset'] = '1201';
$ahead_state         = Sync_Runner::validate_import_checkpoint_state( $ahead, 1201, 'test' );
ss_assert( ! is_wp_error( $ahead_state ), 'transferred rows may exceed the opening source count when the live table grows' );
ss_assert_same( 1201, is_wp_error( $ahead_state ) ? 0 : $ahead_state['total_rows'], 'live-growth checkpoint retains the delivered total' );

$done_drift                = $done;
$done_drift['total_rows']  = '1203';
$done_drift['next_offset'] = '1503';
$done_drift_state          = Sync_Runner::validate_import_checkpoint_state( $done_drift, 1203, 'test' );
ss_assert( ! is_wp_error( $done_drift_state ) && ! empty( $done_drift_state['done'] ), 'a completed checkpoint with live drift remains resumable as finished' );

$bad_cursor                = $row;
$bad_cursor['next_cursor'] = '../escape';
ss_assert_same( 'checkpoint_corrupt', Sync_Runner::validate_import_checkpoint_state( $bad_cursor, 500, 'test' )->get_error_code(), 'malformed resume cursors are rejected' );

$bad_done         = $row;
$bad_done['done'] = '2';
ss_assert_same( 'checkpoint_corrupt', Sync_Runner::validate_import_checkpoint_state( $bad_done, 500, 'test' )->get_error_code(), 'completion is a strict boolean marker' );

ss_test_section( 'Job: database preflight collation metadata' );

$available_collations = new ReflectionMethod( Sync_Runner::class, 'available_database_collations' );
if ( PHP_VERSION_ID < 80100 ) {
	$available_collations->setAccessible( true );
}

$catalog = $available_collations->invoke(
	null,
	array(
		array( 'Collation' => 'utf8mb4_unicode_ci' ),
		// MariaDB exposes charset-independent UCA names this way.
		array( 'Collation' => 'uca1400_ai_ci' ),
	),
	array(
		array(
			'COLLATION_NAME'      => 'uca1400_ai_ci',
			'CHARACTER_SET_NAME'  => 'utf8mb3',
			'FULL_COLLATION_NAME' => 'utf8mb3_uca1400_ai_ci',
		),
		array(
			'COLLATION_NAME'      => 'uca1400_ai_ci',
			'CHARACTER_SET_NAME'  => 'utf8mb4',
			'FULL_COLLATION_NAME' => 'utf8mb4_uca1400_ai_ci',
		),
	)
);

ss_assert( isset( $catalog['utf8mb4_unicode_ci'] ), 'ordinary SHOW COLLATION names remain available' );
ss_assert( isset( $catalog['uca1400_ai_ci'] ), 'MariaDB charset-independent base names remain available' );
ss_assert( isset( $catalog['utf8mb3_uca1400_ai_ci'] ), 'MariaDB utf8mb3 UCA effective names are accepted' );
ss_assert( isset( $catalog['utf8mb4_uca1400_ai_ci'] ), 'MariaDB utf8mb4 UCA effective names are accepted' );
ss_assert( ! isset( $catalog['latin1_uca1400_ai_ci'] ), 'unreported character-set combinations are not invented' );

$mysql_catalog = $available_collations->invoke(
	null,
	array(),
	array(
		array(
			'COLLATION_NAME'     => 'utf8mb4_0900_ai_ci',
			'CHARACTER_SET_NAME' => 'utf8mb4',
		),
	)
);
ss_assert( isset( $mysql_catalog['utf8mb4_0900_ai_ci'] ), 'MySQL applicability rows without FULL_COLLATION_NAME remain supported' );

ss_test_section( 'Job: duration formatting and durable log files' );

ss_assert_same( '45s', Job::format_duration( 45 ), 'sub-minute durations stay in seconds' );
ss_assert_same( '41m 28s', Job::format_duration( 2488 ), 'thousands of seconds become minutes' );
ss_assert_same( '1h 2m 5s', Job::format_duration( 3725 ), 'hour-scale durations include hours' );
ss_assert_same( '0s', Job::format_duration( -3 ), 'negative durations clamp to zero' );

ss_assert_same( null, Job::log_path( '../etc/passwd' ), 'path traversal in a job id is rejected' );
ss_assert_same( null, Job::log_path( 'short' ), 'non-12-hex job ids are rejected' );
$safe_path = Job::log_path( 'abcdef012345' );
ss_assert( is_string( $safe_path ) && substr( $safe_path, -17 ) === '/abcdef012345.log', 'safe job ids map to a basename under the log dir' );

$log_dir = Job::log_dir();
ss_test_rmrf( $log_dir );
ss_assert_same( true, Job::begin_log_file( 'abcdef012345' ), 'a new job opens an empty durable log' );
$job = array( 'id' => 'abcdef012345', 'log' => array() );
Job::log( $job, 'Imported wp_posts (10 rows).' );
Job::log( $job, 'FAILED: boom' );
$contents = Job::read_log_file( 'abcdef012345' );
ss_assert( is_string( $contents ) && false !== strpos( $contents, 'Imported wp_posts' ), 'log lines are appended to the durable file' );
ss_assert( false !== strpos( $contents, 'FAILED: boom' ), 'later log lines append after earlier ones' );
ss_assert_same( 2, count( $job['log'] ), 'in-option ring buffer still receives each line' );

Job::begin_log_file( 'ffffffffffff' );
file_put_contents( Job::log_path( 'ffffffffffff' ), "orphan\n" );
ss_assert_same( 1, Job::prune_log_files( array( 'abcdef012345' ) ), 'history retention prunes log files outside the keep set' );
ss_assert( is_file( Job::log_path( 'abcdef012345' ) ), 'kept job logs survive pruning' );
ss_assert( ! is_file( Job::log_path( 'ffffffffffff' ) ), 'pruned job logs are deleted' );
ss_test_rmrf( $log_dir );

ss_test_section( 'Job: kept sandbox-only entries in the public state' );

ss_test_reset_options();
$kept_state_job                 = Job::create( array( 'dirs' => array( 'plugins', 'themes' ) ), 1, false );
$kept_state                     = Job::public_state( $kept_state_job );
ss_assert_same( array(), $kept_state['kept_plugins'], 'a job that kept nothing exposes an empty plugin list' );
ss_assert_same( array(), $kept_state['kept_themes'], 'a job that kept nothing exposes an empty theme list' );
$kept_state_job['kept_plugins'] = array( 'dev-tool', 'hello.php' );
$kept_state_job['kept_themes']  = array( 'my-theme' );
$kept_state                     = Job::public_state( $kept_state_job );
ss_assert_same( array( 'dev-tool', 'hello.php' ), $kept_state['kept_plugins'], 'the public job state lists kept plugins' );
ss_assert_same( array( 'my-theme' ), $kept_state['kept_themes'], 'the public job state lists kept themes' );
Job::delete();
ss_test_rmrf( Job::log_dir() );
ss_test_reset_options();
