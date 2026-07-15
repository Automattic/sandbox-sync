<?php
/** Upload generation tracking and partition walking tests. */

use SandboxSync\Files;
use SandboxSync\Job;
use SandboxSync\Uploads_State;

ss_test_section( 'Uploads state: partition generations' );

$state_file = WP_CONTENT_DIR . '/.sandbox-sync-uploads-state.php';
$lock_file  = WP_CONTENT_DIR . '/.sandbox-sync-uploads-state.lock';
$uploads    = WP_CONTENT_DIR . '/uploads';
@unlink( $state_file );
@unlink( $lock_file );
if ( is_dir( $uploads ) ) {
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $uploads, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $iterator as $item ) {
		$item->isDir() && ! $item->isLink() ? @rmdir( $item->getPathname() ) : @unlink( $item->getPathname() );
	}
	@rmdir( $uploads );
}
mkdir( $uploads . '/2026/07', 0755, true );
mkdir( $uploads . '/2025/12', 0755, true );
mkdir( $uploads . '/custom', 0755, true );
file_put_contents( $uploads . '/2026/07/current.jpg', 'current' );
file_put_contents( $uploads . '/2025/12/old.jpg', 'old' );
file_put_contents( $uploads . '/2026/loose.txt', 'loose' );
file_put_contents( $uploads . '/custom/logo.png', 'logo' );

$initial = Uploads_State::snapshot();
ss_assert( ! is_wp_error( $initial ), 'absent uploads state starts with a valid empty generation clock' );
ss_assert( in_array( '2026/07', $initial['scopes'], true ) && in_array( '2025/12', $initial['scopes'], true ) && in_array( 'other', $initial['scopes'], true ), 'snapshot cheaply discovers dated and other partitions' );
ss_assert_same( 0, $initial['generations']['2026/07'], 'existing untracked month starts at generation zero' );

ss_assert_same( true, Uploads_State::mark_file( $uploads . '/2026/07/current.jpg' ), 'absolute uploads path advances its month generation' );
$after_first = Uploads_State::snapshot();
ss_assert( $after_first['generations']['2026/07'] > 0, 'month generation becomes nonzero after a tracked mutation' );
ss_assert_same( '2026/07/current.jpg', $after_first['changes'][0]['p'], 'tracked mutation journal retains the exact path for forced hashing' );
ss_assert_same( true, Uploads_State::mark_file( 'custom/logo.png' ), 'relative non-dated path advances other generation' );
$after_other = Uploads_State::snapshot();
ss_assert( $after_other['generations']['other'] > $after_first['generations']['2026/07'], 'generation clock is monotonic across partitions' );
ss_assert_same( null, Uploads_State::scope_for_file( '/outside/uploads.jpg' ), 'absolute path outside uploads is ignored' );

ss_assert_same(
	array( '2026/07/current.jpg' ),
	array_column( iterator_to_array( Files::walk_upload_scope( $uploads, '2026/07', false ), false ), 'p' ),
	'month walker descends only into its exact date partition'
);
ss_assert_same(
	array( '2026/loose.txt', 'custom/logo.png' ),
	array_column( iterator_to_array( Files::walk_upload_scope( $uploads, 'other', false ), false ), 'p' ),
	'other walker includes loose year files and non-date directories without descending into months'
);

ss_assert_same(
	array(
		'2026/07' => array( 'bytes' => 7, 'files' => 1 ),
		'other'   => array( 'bytes' => 9, 'files' => 2 ),
	),
	Files::upload_scope_totals( $uploads, array( '2026/07', 'other', '2026/07' ) ),
	'stat-only scope totals count each partition once for copied/total progress'
);

ss_assert_same(
	array(
		array( 'label' => '2025', 'scopes' => array( '2025/12' ) ),
		array( 'label' => '2026', 'scopes' => array( '2026/06', '2026/07' ) ),
		array( 'label' => 'non-date content', 'scopes' => array( 'other' ) ),
	),
	Uploads_State::baseline_groups( array( '2026/07', 'other', '2025/12', '2026/06', '2026/07' ) ),
	'first baseline groups exact month scopes into one resumable stream per year'
);

ss_test_section( 'Uploads state: per-partition cursor' );

delete_option( Uploads_State::OPT_CURSOR );

ss_assert_same( false, Uploads_State::partition_committed( 'source', '2026/07' ), 'missing cursor: every partition selects the Knife-like first-baseline path' );

ss_assert_same(
	true,
	Uploads_State::commit_partitions( 'source', array( '2025/12' => array( 'source_generation' => 2, 'local_generation' => 3 ) ) ),
	'a finished baseline group commits its partitions immediately'
);
ss_assert_same( true, Uploads_State::partition_committed( 'source', '2025/12' ), 'committed partition takes the incremental generation-check path' );
ss_assert_same( false, Uploads_State::partition_committed( 'source', '2026/07' ), 'partitions without an entry stay on the baseline path (mixed mode)' );
ss_assert_same( false, Uploads_State::partition_committed( 'replacement-source', '2025/12' ), 'a different live source ignores this cursor entirely' );

ss_assert_same(
	true,
	Uploads_State::commit_partitions( 'source', array( '2026/07' => array( 'source_generation' => 7, 'local_generation' => 1 ) ) ),
	'later commits merge into the cursor'
);
$cursor = Uploads_State::cursor();
ss_assert_same( 2, $cursor['partitions']['2025/12']['source_generation'], 'merging commits preserves earlier partitions' );
ss_assert_same( 7, $cursor['partitions']['2026/07']['source_generation'], 'merging commits adds new partitions' );

ss_assert_same(
	true,
	Uploads_State::commit_partitions( 'replacement-source', array( 'other' => array( 'source_generation' => 1, 'local_generation' => 1 ) ) ),
	'committing for a new source replaces the cursor'
);
$cursor = Uploads_State::cursor();
ss_assert_same( 'replacement-source', $cursor['source_id'], 'partitions synced from another live site say nothing about the new one' );
ss_assert_same( array( 'other' ), array_keys( $cursor['partitions'] ), 'no partitions carry over across sources' );

ss_assert_same( true, Uploads_State::remove_partitions( 'replacement-source', array( 'other' ) ), 'partitions are uncommitted before their destructive clear' );
ss_assert_same( false, Uploads_State::partition_committed( 'replacement-source', 'other' ), 'a cleared partition can never be skipped on a stale claim' );
ss_assert_same( true, Uploads_State::remove_partitions( 'unrelated-source', array( 'other' ) ), 'uncommitting for a foreign source is a safe no-op' );

ss_test_section( 'Uploads state: schema-1 cursor migration' );

update_option(
	Uploads_State::OPT_CURSOR,
	array(
		'schema'             => 1,
		'source_id'          => 'legacy-source',
		'scopes'             => array( '2026/07', '2025/12', 'bogus scope' ),
		'source_generations' => array( '2026/07' => 2, '2025/12' => 4 ),
		'local_generations'  => array( '2026/07' => 3 ),
		'updated_at'         => 1700000000,
	),
	false
);
$migrated = Uploads_State::cursor();
ss_assert_same( Uploads_State::CURSOR_SCHEMA, (int) $migrated['schema'], 'schema-1 cursor migrates on read' );
ss_assert_same( 'legacy-source', $migrated['source_id'], 'migration keeps the source identity' );
ss_assert_same(
	array( 'source_generation' => 2, 'local_generation' => 3 ),
	$migrated['partitions']['2026/07'],
	'migration converts parallel generation maps into per-partition entries'
);
ss_assert_same(
	array( 'source_generation' => 4, 'local_generation' => 0 ),
	$migrated['partitions']['2025/12'],
	'missing legacy local generation defaults to zero (forces a rescan, never a skip)'
);
ss_assert( ! isset( $migrated['partitions']['bogus scope'] ), 'invalid legacy scopes are dropped by migration' );
ss_assert_same( true, Uploads_State::partition_committed( 'legacy-source', '2025/12' ), 'a legacy full-sync cursor keeps its committed partitions after upgrade' );

ss_test_section( 'Uploads state: cursor commit gating' );

delete_option( Uploads_State::OPT_CURSOR );

$commit = new ReflectionMethod( SandboxSync\Sync_Runner::class, 'commit_fast_uploads_cursor' );
if ( PHP_VERSION_ID < 80100 ) {
	$commit->setAccessible( true ); // No effect (and deprecated) on PHP 8.1+.
}

$job = array(
	'uploads_fast' => array(
		'source_id'       => 'source',
		'final_source'    => array( '2026/07' => 5 ),
		'selected_scopes' => array( '2026/07' ),
	),
);
ss_assert_same( true, $commit->invokeArgs( null, array( &$job ) ), 'commit without full activation succeeds as a no-op' );
ss_assert_same( array(), Uploads_State::cursor(), 'a partially transferred job never writes the full-activation cursor upgrade' );

$job['uploads_fast']['activated'] = true;
ss_assert_same( true, $commit->invokeArgs( null, array( &$job ) ), 'commit after full activation succeeds' );
$committed = Uploads_State::cursor();
ss_assert_same( 'source', $committed['source_id'], 'full activation commits every selected partition' );
ss_assert_same( 5, $committed['partitions']['2026/07']['source_generation'], 'activation upgrades entries to the delta-validated final source generations' );
ss_assert_same( true, Uploads_State::partition_committed( 'source', '2026/07' ), 'the next sync from the same source returns to incremental mode' );

ss_assert_same( false, SandboxSync\Sync_Runner::default_options()['uploads_adopt'], 'adopt-existing-uploads mode is opt-in' );

ss_test_section( 'Uploads state: early per-partition commit helper' );

delete_option( Uploads_State::OPT_CURSOR );

$early = new ReflectionMethod( SandboxSync\Sync_Runner::class, 'maybe_commit_upload_partition' );
$note  = new ReflectionMethod( SandboxSync\Sync_Runner::class, 'note_upload_scope_transfer_done' );
if ( PHP_VERSION_ID < 80100 ) {
	$early->setAccessible( true );
	$note->setAccessible( true );
}

ss_assert_same( true, Job::acquire_slice_lock( 'early-commit-job' ), 'test worker acquires a lease for early-commit helpers' );
$job = array(
	'id'           => 'early-commit-job',
	'uploads_fast' => array(
		'source_id'  => 'source',
		'pre_source' => array( '2024/01' => 11, '2025/09' => 22 ),
		'pre_local'  => array( '2024/01' => 3, '2025/09' => 4 ),
	),
);
ss_assert_same( true, $early->invokeArgs( null, array( &$job, '2024/01' ) ), 'early commit of a finished adopt/baseline partition succeeds' );
ss_assert_same( true, Uploads_State::partition_committed( 'source', '2024/01' ), 'early-committed partition is durable before activation' );
ss_assert_same( false, Uploads_State::partition_committed( 'source', '2025/09' ), 'unfinished partitions stay off the cursor' );
ss_assert_same( 11, Uploads_State::cursor()['partitions']['2024/01']['source_generation'], 'early commit records plan-time source generations' );

$job['uploads_fast']['scope_remaining']['2025/09']    = 2;
$job['uploads_fast']['scope_delete_count']['2025/09'] = 0;
ss_assert_same( true, $note->invokeArgs( null, array( &$job, '2025/09' ) ), 'first finished batch only decrements the counter' );
ss_assert_same( false, Uploads_State::partition_committed( 'source', '2025/09' ), 'partition waits for its last transfer batch' );
ss_assert_same( true, $note->invokeArgs( null, array( &$job, '2025/09' ) ), 'last finished batch early-commits a deletion-free partition' );
ss_assert_same( true, Uploads_State::partition_committed( 'source', '2025/09' ), 'deletion-free adopt partition commits when transfers finish' );

delete_option( Uploads_State::OPT_CURSOR );
$job['uploads_fast']['scope_remaining']['2025/09']    = 1;
$job['uploads_fast']['scope_delete_count']['2025/09'] = 5;
ss_assert_same( true, $note->invokeArgs( null, array( &$job, '2025/09' ) ), 'deletion-bearing partitions finish transfers without committing' );
ss_assert_same( false, Uploads_State::partition_committed( 'source', '2025/09' ), 'deferred deletions keep the partition off the cursor until activation' );
Job::release_slice_lock();

@unlink( $state_file );
@unlink( $lock_file );
delete_option( Uploads_State::OPT_CURSOR );
