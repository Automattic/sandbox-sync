<?php
/**
 * Files tests: scan (symlink rules), size+mtime diff, batching, extraneous
 * deletion with directory pruning.
 */

use SandboxSync\Files;
use SandboxSync\Client;
use SandboxSync\Sync_Runner;

ss_test_section( 'Files: diff' );

$source = array(
	array( 'p' => 'a.txt', 's' => 10, 'm' => 100 ),
	array( 'p' => 'sub/b.txt', 's' => 20, 'm' => 200 ),
	array( 'p' => 'sub/c.txt', 's' => 30, 'm' => 300 ),
	array( 'p' => 'new.txt', 's' => 5, 'm' => 500 ),
);
$local = array(
	array( 'p' => 'a.txt', 's' => 10, 'm' => 100 ),   // identical -> skip
	array( 'p' => 'sub/b.txt', 's' => 21, 'm' => 200 ), // size differs -> fetch
	array( 'p' => 'sub/c.txt', 's' => 30, 'm' => 301 ), // mtime differs -> fetch
	array( 'p' => 'stale.txt', 's' => 1, 'm' => 1 ),   // not on source -> delete
);

$diff = Files::diff( $source, $local );
ss_assert_same( array( 'sub/b.txt', 'sub/c.txt', 'new.txt' ), array_column( $diff['fetch'], 'p' ), 'changed + new files are fetched' );
ss_assert_same( array( 'stale.txt' ), $diff['delete'], 'extraneous local files are deleted' );

$diff_empty = Files::diff( $source, $source );
ss_assert_same( array(), $diff_empty['fetch'], 'identical trees: nothing to fetch' );
ss_assert_same( array(), $diff_empty['delete'], 'identical trees: nothing to delete' );

$forced_fetch = array();
Files::diff_iterators(
	new ArrayIterator( $source ),
	new ArrayIterator( $source ),
	function ( $entry ) use ( &$forced_fetch ) { $forced_fetch[] = $entry['p']; },
	function () {},
	function ( $entry ) { return 'sub/c.txt' === $entry['p']; }
);
ss_assert_same( array( 'sub/c.txt' ), $forced_fetch, 'path predicate force-fetches a hook-tracked file even when size and mtime match' );

$diff_fresh = Files::diff( $source, array() );
ss_assert_same( 4, count( $diff_fresh['fetch'] ), 'empty local tree: everything is fetched' );

ss_test_section( 'Files: top-level filtering (plugin selection)' );

$plugin_files = array(
	array( 'p' => 'akismet/akismet.php', 's' => 1, 'm' => 1 ),
	array( 'p' => 'akismet/views/config.php', 's' => 1, 'm' => 1 ),
	array( 'p' => 'classic-widgets/classic-widgets.php', 's' => 1, 'm' => 1 ),
	array( 'p' => 'hello.php', 's' => 1, 'm' => 1 ),
	array( 'p' => 'stop-emails/stop-emails.php', 's' => 1, 'm' => 1 ),
);

ss_assert_same(
	array( 'akismet/akismet.php', 'akismet/views/config.php', 'hello.php' ),
	array_column( Files::filter_top_level( $plugin_files, array( 'akismet', 'hello.php' ) ), 'p' ),
	'filter keeps only selected top-level dirs and single-file plugins'
);
ss_assert_same( array(), Files::filter_top_level( $plugin_files, array() ), 'empty selection keeps nothing' );
ss_assert_same(
	array( 'akismet/akismet.php', 'akismet/views/config.php', 'classic-widgets/classic-widgets.php', 'hello.php' ),
	array_column( Files::exclude_top_level( $plugin_files, array( 'stop-emails' ) ), 'p' ),
	'exclude drops protected plugins'
);

ss_test_section( 'Files: uploads date scopes' );

$upload_files = array(
	array( 'p' => '2026/07/photo.jpg', 's' => 1, 'm' => 1 ),
	array( 'p' => '2026/06/older.jpg', 's' => 1, 'm' => 1 ),
	array( 'p' => '2026/stray.jpg', 's' => 1, 'm' => 1 ),
	array( 'p' => '2025/12/last-year.jpg', 's' => 1, 'm' => 1 ),
	array( 'p' => 'custom-dir/logo.png', 's' => 1, 'm' => 1 ),
	array( 'p' => 'root-file.pdf', 's' => 1, 'm' => 1 ),
);

ss_assert_same(
	array( '2026/07/photo.jpg', '2026/06/older.jpg', '2026/stray.jpg' ),
	array_column( Files::filter_upload_scopes( $upload_files, array( '2026' ) ), 'p' ),
	'whole-year scope keeps all its months and year-root files'
);
ss_assert_same(
	array( '2026/07/photo.jpg', '2026/stray.jpg' ),
	array_column( Files::filter_upload_scopes( $upload_files, array( '2026/07' ) ), 'p' ),
	'month scope keeps that month plus year-root files of the same year'
);
ss_assert_same(
	array( 'custom-dir/logo.png', 'root-file.pdf' ),
	array_column( Files::filter_upload_scopes( $upload_files, array( 'other' ) ), 'p' ),
	'other keeps non-dated dirs and root files'
);
ss_assert_same(
	array( '2026/07/photo.jpg', '2026/stray.jpg', '2025/12/last-year.jpg', 'custom-dir/logo.png', 'root-file.pdf' ),
	array_column( Files::filter_upload_scopes( $upload_files, array( '2026/07', '2025', 'other' ) ), 'p' ),
	'mixed scopes combine'
);
ss_assert_same( array(), Files::filter_upload_scopes( $upload_files, array() ), 'empty scope selection keeps nothing' );

ss_test_section( 'Files: destructive first-baseline scope clearing' );

$clear_root = sys_get_temp_dir() . '/ss-uploads-clear-' . bin2hex( random_bytes( 4 ) );
mkdir( $clear_root . '/2024/01', 0755, true );
mkdir( $clear_root . '/2024/02', 0755, true );
mkdir( $clear_root . '/custom', 0755, true );
file_put_contents( $clear_root . '/2024/01/stale.jpg', 'stale month' );
file_put_contents( $clear_root . '/2024/02/keep.jpg', 'keep month' );
file_put_contents( $clear_root . '/2024/loose.pdf', 'stale other' );
file_put_contents( $clear_root . '/custom/stale.png', 'stale custom' );
$cleared_month = Files::clear_upload_scopes( $clear_root, array( '2024/01' ) );
ss_assert_same( 1, $cleared_month, 'baseline month clearing removes only that exact month' );
ss_assert( ! file_exists( $clear_root . '/2024/01/stale.jpg' ), 'cleared month content is gone before its retryable stream' );
ss_assert_same( 'keep month', file_get_contents( $clear_root . '/2024/02/keep.jpg' ), 'unselected month survives baseline clearing' );
ss_assert_same( 'stale other', file_get_contents( $clear_root . '/2024/loose.pdf' ), 'other content survives exact-month clearing' );
$cleared_other = Files::clear_upload_scopes( $clear_root, array( 'other' ) );
ss_assert_same( 2, $cleared_other, 'other clearing removes loose-year and non-date files' );
ss_assert( ! file_exists( $clear_root . '/2024/loose.pdf' ) && ! file_exists( $clear_root . '/custom/stale.png' ), 'other baseline content is cleared without traversing date months' );
ss_assert_same( 'keep month', file_get_contents( $clear_root . '/2024/02/keep.jpg' ), 'dated month remains after other clearing' );
ss_test_rmrf( $clear_root );

ss_test_section( 'Files: batching' );

$small = array(
	array( 'p' => 'x.txt', 's' => 1, 'm' => 1 ),
	array( 'p' => 'y.txt', 's' => 1, 'm' => 1 ),
);
ss_assert_same( array( array( 'x.txt', 'y.txt' ) ), Files::batch( $small ), 'small sets fit one batch' );

$big = array(
	array( 'p' => 'big1.bin', 's' => Files::BATCH_MAX_BYTES, 'm' => 1 ),
	array( 'p' => 'big2.bin', 's' => Files::BATCH_MAX_BYTES, 'm' => 1 ),
	array( 'p' => 'tail.txt', 's' => 1, 'm' => 1 ),
);
$batches = Files::batch( $big );
ss_assert_same( 3, count( $batches ), 'byte cap splits batches' );
ss_assert_same( array( 'tail.txt' ), $batches[2], 'remainder lands in the final batch' );

$many = array();
for ( $i = 0; $i < Files::BATCH_MAX_FILES + 1; $i++ ) {
	$many[] = array( 'p' => "f{$i}", 's' => 1, 'm' => 1 );
}
$batches = Files::batch( $many );
ss_assert_same( 2, count( $batches ), 'file-count cap splits batches' );
ss_assert_same( Files::BATCH_MAX_FILES, count( $batches[0] ), 'first batch is exactly at the cap' );

ss_test_section( 'Files: uploads transfer concurrency and request binding' );

ss_assert_same( 2, Client::parallel_downloads(), 'uploads default conservatively uses two source streams' );
$GLOBALS['ss_test_filters']['sandbox_sync_parallel_upload_downloads'] = function () { return 99; };
ss_assert_same( 4, Client::parallel_downloads(), 'uploads concurrency filter cannot exceed the hard cap' );
$GLOBALS['ss_test_filters']['sandbox_sync_parallel_upload_downloads'] = function () { return 0; };
ss_assert_same( 1, Client::parallel_downloads(), 'uploads concurrency filter cannot disable all transfer workers' );
unset( $GLOBALS['ss_test_filters']['sandbox_sync_parallel_upload_downloads'] );

ss_assert_same( 4, Client::baseline_parallel_streams(), 'first-baseline streams default to four concurrent years' );
$GLOBALS['ss_test_filters']['sandbox_sync_baseline_parallel_streams'] = function () { return 99; };
ss_assert_same( 8, Client::baseline_parallel_streams(), 'baseline concurrency filter cannot exceed the hard cap' );
$GLOBALS['ss_test_filters']['sandbox_sync_baseline_parallel_streams'] = function () { return 0; };
ss_assert_same( 1, Client::baseline_parallel_streams(), 'baseline concurrency filter cannot disable all streams' );
unset( $GLOBALS['ss_test_filters']['sandbox_sync_baseline_parallel_streams'] );

update_option( Client::OPT_TRANSFER_ID, str_repeat( 'a', 64 ), false );
$nonce_plan = array( array( 'p' => '2026/07/photo.jpg', 's' => 123, 'm' => 456, 'o' => 0644 ) );
$nonce      = Client::upload_archive_nonce( 'uploads', $nonce_plan );
ss_assert( preg_match( '/^[a-f0-9]{64}$/', $nonce ), 'uploads archive request binding is a bounded SHA-256 nonce' );
$nonce_plan[0]['m']++;
ss_assert( $nonce !== Client::upload_archive_nonce( 'uploads', $nonce_plan ), 'uploads archive nonce changes with requested metadata' );
delete_option( Client::OPT_TRANSFER_ID );

$batch_job = array( 'id' => 'batchjournaltest' );
$batch_entries = array(
	array( 'p' => '2026/07/a.jpg', 's' => 10, 'm' => 20, 'o' => 0644 ),
	array( 'p' => '2026/07/b.jpg', 's' => 30, 'm' => 40, 'o' => 0600 ),
);
$store_batch = new ReflectionMethod( Sync_Runner::class, 'store_file_batch' );
$load_batch  = new ReflectionMethod( Sync_Runner::class, 'load_file_batch' );
if ( PHP_VERSION_ID < 80100 ) {
	$store_batch->setAccessible( true );
	$load_batch->setAccessible( true );
}
$batch_file = $store_batch->invoke( null, $batch_job, 'uploads', 7, $batch_entries );
ss_assert( is_string( $batch_file ) && is_file( $batch_file ), 'file batch persists its primary job-owned copy' );
ss_assert( is_file( dirname( $batch_file ) . '/recovery.ndjson' ), 'file batch also persists an append-only recovery record' );
unlink( $batch_file );
$recovered_batch = $load_batch->invoke( null, $batch_job, $batch_file );
ss_assert_same( $batch_entries, $recovered_batch, 'missing file batch is rebuilt from its checksummed recovery journal' );
ss_assert( is_file( $batch_file ), 'journal recovery restores the primary batch for later retries' );
file_put_contents( $batch_file, '{corrupt' );
ss_assert_same( $batch_entries, $load_batch->invoke( null, $batch_job, $batch_file ), 'malformed primary batch also recovers from the journal' );
ss_test_rmrf( WP_CONTENT_DIR . '/.sandbox-sync-work/batchjournaltest' );

$fast_batch = new ReflectionMethod( Sync_Runner::class, 'batch_fast_uploads' );
if ( PHP_VERSION_ID < 80100 ) {
	$fast_batch->setAccessible( true );
}
$changed_file = sys_get_temp_dir() . '/ss-fast-batch-' . bin2hex( random_bytes( 4 ) ) . '.ndjson';
file_put_contents( $changed_file, wp_json_encode( $batch_entries[0] ) . "\n" );
$fast_job = array(
	'id'           => 'fastbatchtest',
	'uploads_fast' => array( 'batch_seq' => 0 ),
);
$fast_steps = $fast_batch->invokeArgs( null, array( &$fast_job, $changed_file, 0, '2026/07', 1, 1, 1, 10 ) );
ss_assert_same( '2026/07', $fast_steps[0]['args']['scope'], 'fast uploads batches retain their partition scope for cursor commits' );
unlink( $changed_file );
ss_test_rmrf( WP_CONTENT_DIR . '/.sandbox-sync-work/fastbatchtest' );

ss_test_section( 'Files: scan + delete on a real tree' );

$root = sys_get_temp_dir() . '/ss-files-test-' . bin2hex( random_bytes( 4 ) );
mkdir( $root . '/sub/deep', 0755, true );
file_put_contents( $root . '/top.txt', 'hello' );
file_put_contents( $root . '/sub/mid.txt', 'world!' );
file_put_contents( $root . '/sub/deep/leaf.txt', 'x' );
symlink( $root . '/top.txt', $root . '/link.txt' );
mkdir( $root . '/linked-target' );
file_put_contents( $root . '/linked-target/inside.txt', 'y' );
symlink( $root . '/linked-target', $root . '/linkdir' );

$scanned = array_column( Files::scan( $root ), 'p' );
sort( $scanned );
ss_assert_same( array( 'linked-target/inside.txt', 'sub/deep/leaf.txt', 'sub/mid.txt', 'top.txt' ), $scanned, 'scan lists files, skips symlinks and symlinked dirs' );
ss_assert_same( 13, Files::tree_bytes( $root ), 'tree byte preflight totals regular files without following symlinks' );
ss_assert_same( array( 'link.txt', 'linkdir' ), Files::symlink_paths( $root ), 'symlink paths are collected for protection' );
ss_assert_same(
	array( 'safe/file.php' ),
	array_column(
		Files::exclude_path_prefixes(
			array(
				array( 'p' => 'linkdir/remote.php' ),
				array( 'p' => 'link.txt' ),
				array( 'p' => 'safe/file.php' ),
			),
			array( 'link.txt', 'linkdir' )
		),
		'p'
	),
	'files at or below protected symlinks are excluded'
);

$entry = Files::scan( $root )[ array_search( 'top.txt', array_column( Files::scan( $root ), 'p' ), true ) ];
ss_assert_same( 5, $entry['s'], 'scan reports file size' );
ss_assert( $entry['m'] > 0, 'scan reports mtime' );
$hashed = Files::scan( $root, true );
$hashed_entry = $hashed[ array_search( 'top.txt', array_column( $hashed, 'p' ), true ) ];
ss_assert_same( hash( 'sha256', 'hello' ), $hashed_entry['h'], 'validation scan includes SHA-256 content hash' );
ss_assert_same( fileperms( $root . '/top.txt' ) & 0777, $hashed_entry['o'], 'manifest scan carries the source file mode' );

$hash_source = array( array( 'p' => 'same.txt', 's' => 4, 'm' => 10, 'h' => hash( 'sha256', 'AAAA' ) ) );
$hash_local  = array( array( 'p' => 'same.txt', 's' => 4, 'm' => 10, 'h' => hash( 'sha256', 'BBBB' ) ) );
ss_assert_same( array( 'same.txt' ), array_column( Files::diff( $hash_source, $hash_local )['fetch'], 'p' ), 'hash catches same-size same-mtime edits' );

ss_test_section( 'Files: streamed manifests + merge diff' );

$manifest = $root . '/manifest.ndjson';
$manifest_entries = array(
	array( 'p' => 'a/child.txt', 's' => 1, 'm' => 10, 'h' => hash( 'sha256', 'a' ) ),
	array( 'p' => 'a.txt', 's' => 2, 'm' => 20, 'h' => hash( 'sha256', 'bb' ) ),
	array( 'p' => 'new.txt', 's' => 3, 'm' => 30, 'h' => hash( 'sha256', 'ccc' ) ),
);
file_put_contents( $manifest, implode( "\n", array_map( 'json_encode', $manifest_entries ) ) . "\n" );
ss_assert_same( 3, Files::validate_ndjson_manifest( $manifest, true ), 'valid hashed NDJSON manifest is accepted' );
ss_assert_same( array_column( $manifest_entries, 'p' ), array_column( iterator_to_array( Files::read_ndjson_manifest( $manifest ), false ), 'p' ), 'NDJSON entries stream back in order' );
ss_assert( Files::compare_paths( 'a/child.txt', 'a.txt' ) < 0, 'component ordering matches depth-first walk ordering' );

$local_entries = array(
	array( 'p' => 'a/child.txt', 's' => 1, 'm' => 10, 'h' => hash( 'sha256', 'z' ) ),
	array( 'p' => 'a.txt', 's' => 2, 'm' => 20, 'h' => hash( 'sha256', 'bb' ) ),
	array( 'p' => 'stale.txt', 's' => 1, 'm' => 1, 'h' => hash( 'sha256', 'x' ) ),
);
$as_generator = function ( $entries ) {
	foreach ( $entries as $item ) {
		yield $item;
	}
};
$stream_fetch  = array();
$stream_delete = array();
Files::diff_iterators(
	$as_generator( $manifest_entries ),
	$as_generator( $local_entries ),
	function ( $entry ) use ( &$stream_fetch ) { $stream_fetch[] = $entry['p']; },
	function ( $path ) use ( &$stream_delete ) { $stream_delete[] = $path; }
);
ss_assert_same( array( 'a/child.txt', 'new.txt' ), $stream_fetch, 'stream diff detects a hash mismatch and source-only file across directory/file ordering' );
ss_assert_same( array( 'stale.txt' ), $stream_delete, 'stream diff detects a local-only file' );

$progress_calls = 0;
$completed      = Files::diff_iterators(
	$as_generator( $manifest_entries ),
	$as_generator( $local_entries ),
	function () {},
	function () {},
	false,
	function () use ( &$progress_calls ) {
		++$progress_calls;
		return $progress_calls < 2;
	}
);
ss_assert_same( false, $completed, 'stream diff can stop promptly when its progress callback loses the execution lease' );
ss_assert_same( 2, $progress_calls, 'stream diff stops at the first rejected progress checkpoint' );

$metadata_source = $root . '/metadata-source.bin';
$metadata_copy   = $root . '/metadata-copy.bin';
file_put_contents( $metadata_source, 'metadata-preserving-copy' );
chmod( $metadata_source, 0640 );
touch( $metadata_source, 1234567890 );
ss_assert_same( true, Files::copy_preserving_metadata( $metadata_source, $metadata_copy ), 'incremental staging copies an unchanged file successfully' );
ss_assert_same( file_get_contents( $metadata_source ), file_get_contents( $metadata_copy ), 'incremental staging preserves unchanged file content' );
ss_assert_same( fileperms( $metadata_source ) & 0777, fileperms( $metadata_copy ) & 0777, 'incremental staging preserves unchanged file mode' );
ss_assert_same( filemtime( $metadata_source ), filemtime( $metadata_copy ), 'incremental staging preserves unchanged file mtime used by final validation' );
@unlink( $metadata_source );
@unlink( $metadata_copy );

file_put_contents( $manifest, "{not-json}\n" );
ss_assert( is_wp_error( Files::validate_ndjson_manifest( $manifest, true ) ), 'malformed NDJSON manifest is rejected' );
@unlink( $manifest );

ss_test_section( 'Files: verified resumable-file installation' );

$stage = $root . '/stage';
mkdir( $stage );
$download = $root . '/large.part';
file_put_contents( $download, 'verified-content' );
$install_entry = array(
	'p' => 'nested/large.bin',
	's' => strlen( 'verified-content' ),
	'm' => 1234567890,
	'o' => 0640,
	'h' => hash( 'sha256', 'verified-content' ),
);
ss_assert_same( true, Files::install_verified_file( $stage, $download, $install_entry ), 'verified content is installed below the staging root' );
ss_assert( Files::manifest_entry_matches( $stage, $install_entry ), 'installed resumable file matches size, hash, mtime, and mode' );
ss_assert_same( 0640, fileperms( $stage . '/nested/large.bin' ) & 0777, 'resumable install restores the source mode' );

$replacement = $root . '/replacement.part';
file_put_contents( $replacement, 'replacement-content' );
$replacement_entry = array(
	'p' => 'nested/large.bin',
	's' => strlen( 'replacement-content' ),
	'm' => 1234567891,
	'o' => 0600,
	'h' => hash( 'sha256', 'replacement-content' ),
);
ss_assert_same( true, Files::install_verified_file( $stage, $replacement, $replacement_entry ), 'verified content atomically replaces an existing regular file' );
ss_assert_same( 'replacement-content', file_get_contents( $stage . '/nested/large.bin' ), 'atomic replacement exposes the complete new content' );
ss_assert( Files::manifest_entry_matches( $stage, $replacement_entry ), 'atomic replacement preserves source hash, mtime, and mode metadata' );

$outside = $root . '/outside';
mkdir( $outside );
symlink( $outside, $stage . '/escape' );
$unsafe_download = $root . '/unsafe.part';
file_put_contents( $unsafe_download, 'x' );
$unsafe_entry = array(
	'p' => 'escape/file.bin',
	's' => 1,
	'm' => 1,
	'o' => 0644,
	'h' => hash( 'sha256', 'x' ),
);
ss_assert( is_wp_error( Files::install_verified_file( $stage, $unsafe_download, $unsafe_entry ) ), 'resumable install refuses a symlinked staging component' );
ss_assert( ! file_exists( $outside . '/file.bin' ), 'rejected resumable install cannot escape the staging tree' );

@unlink( $unsafe_download );
@unlink( $stage . '/escape' );
@unlink( $stage . '/nested/large.bin' );
@rmdir( $stage . '/nested' );
@rmdir( $stage );
@rmdir( $outside );

ss_test_section( 'Files: uploads same-filesystem quarantine' );

$active_uploads = $root . '/active-uploads';
$quarantine     = $root . '/uploads-quarantine';
mkdir( $active_uploads . '/2026/07', 0700, true );
mkdir( $quarantine, 0700, true );
file_put_contents( $active_uploads . '/2026/07/stale.jpg', 'stale-media' );
$move_file = new ReflectionMethod( Sync_Runner::class, 'move_tree_file' );
if ( PHP_VERSION_ID < 80100 ) {
	$move_file->setAccessible( true );
}
$moved = $move_file->invoke( null, $active_uploads, $quarantine, '2026/07/stale.jpg', false );
ss_assert_same( true, $moved, 'extraneous uploads are renamed into quarantine without a duplicate copy' );
ss_assert( ! file_exists( $active_uploads . '/2026/07/stale.jpg' ), 'quarantined upload leaves the active tree' );
ss_assert_same( 'stale-media', file_get_contents( $quarantine . '/2026/07/stale.jpg' ), 'quarantine retains exact upload content for rollback' );
$restored = $move_file->invoke( null, $quarantine, $active_uploads, '2026/07/stale.jpg', true );
ss_assert_same( true, $restored, 'quarantined upload can be atomically restored during rollback' );
ss_assert_same( 'stale-media', file_get_contents( $active_uploads . '/2026/07/stale.jpg' ), 'rollback restores quarantined upload content' );
@unlink( $active_uploads . '/2026/07/stale.jpg' );
@rmdir( $active_uploads . '/2026/07' );
@rmdir( $active_uploads . '/2026' );
@rmdir( $active_uploads );
@rmdir( $quarantine );

$final_job_root = WP_CONTENT_DIR . '/.sandbox-sync-work/unituploadsfinalize';
$final_target   = WP_CONTENT_DIR . '/uploads-finalize-test';
$final_backup   = $final_job_root . '/uploads-quarantine';
mkdir( $final_target, 0700, true );
mkdir( $final_backup, 0700, true );
file_put_contents( $final_target . '/active.jpg', 'active-media' );
file_put_contents( $final_backup . '/stale.jpg', 'stale-media' );
$final_job = array(
	'id'           => 'unituploadsfinalize',
	'working_dirs' => array(
		'uploads' => array(
			'target'    => $final_target,
			'stage'     => $final_target,
			'backup'    => $final_backup,
			'activated' => true,
			'in_place'  => true,
		),
	),
);
$finalize = new ReflectionMethod( Sync_Runner::class, 'finalize_file_dirs' );
if ( PHP_VERSION_ID < 80100 ) {
	$finalize->setAccessible( true );
}
$finalize->invokeArgs( null, array( &$final_job ) );
ss_assert_same( 'active-media', file_get_contents( $final_target . '/active.jpg' ), 'uploads finalization never deletes the active in-place tree' );
ss_assert( ! file_exists( $final_backup ), 'uploads finalization permanently removes quarantined stale files' );
ss_assert_same( array(), $final_job['working_dirs'], 'uploads finalization clears its recorded work state' );
@unlink( $final_target . '/active.jpg' );
@rmdir( $final_target );

$rollback_job_root = WP_CONTENT_DIR . '/.sandbox-sync-work/unituploadsrollback';
$rollback_target   = WP_CONTENT_DIR . '/uploads-rollback-test';
$rollback_backup   = $rollback_job_root . '/uploads-quarantine';
mkdir( $rollback_target, 0700, true );
mkdir( $rollback_backup, 0700, true );
file_put_contents( $rollback_target . '/verified-new.jpg', 'verified-new-media' );
file_put_contents( $rollback_backup . '/restored-stale.jpg', 'previous-media' );
$rollback_job = array(
	'id'           => 'unituploadsrollback',
	'working_dirs' => array(
		'uploads' => array(
			'target'    => $rollback_target,
			'stage'     => $rollback_target,
			'backup'    => $rollback_backup,
			'activated' => true,
			'in_place'  => true,
		),
	),
);
$rollback = new ReflectionMethod( Sync_Runner::class, 'rollback_file_dirs' );
if ( PHP_VERSION_ID < 80100 ) {
	$rollback->setAccessible( true );
}
$rollback->invokeArgs( null, array( &$rollback_job ) );
ss_assert_same( 'verified-new-media', file_get_contents( $rollback_target . '/verified-new.jpg' ), 'uploads rollback retains verified in-place additions as retry residue' );
ss_assert_same( 'previous-media', file_get_contents( $rollback_target . '/restored-stale.jpg' ), 'uploads rollback restores quarantined deletions' );
ss_assert_same( array(), $rollback_job['working_dirs'], 'uploads rollback clears its recorded work state' );
@unlink( $rollback_target . '/verified-new.jpg' );
@unlink( $rollback_target . '/restored-stale.jpg' );
@rmdir( $rollback_target );

ss_test_section( 'Files: content-swap activation (WP Cloud code dirs)' );

$swap_root   = sys_get_temp_dir() . '/sandbox-sync-content-swap-' . getmypid();
$swap_target = $swap_root . '/plugins';
$swap_stage  = $swap_root . '/.sandbox-sync-stage-plugins';
$swap_backup = $swap_root . '/.sandbox-sync-bak-plugins';
@mkdir( $swap_target . '/old-plugin', 0700, true );
@mkdir( $swap_stage . '/new-plugin', 0700, true );
file_put_contents( $swap_target . '/old-plugin/a.php', 'old' );
file_put_contents( $swap_target . '/keep.php', 'keep-file' );
file_put_contents( $swap_stage . '/new-plugin/b.php', 'new' );
file_put_contents( $swap_stage . '/hello.php', 'hello' );
symlink( $swap_root . '/elsewhere', $swap_target . '/protected-link' );

$activate = new ReflectionMethod( Sync_Runner::class, 'activate_dir_by_contents' );
if ( PHP_VERSION_ID < 80100 ) {
	$activate->setAccessible( true );
}
$result = $activate->invokeArgs( null, array( $swap_target, $swap_stage, $swap_backup ) );
ss_assert_same( true, $result, 'content-swap activation succeeds' );
ss_assert_same( 'new', file_get_contents( $swap_target . '/new-plugin/b.php' ), 'staged plugin is promoted into the target' );
ss_assert_same( 'hello', file_get_contents( $swap_target . '/hello.php' ), 'staged single-file plugin is promoted' );
ss_assert( ! file_exists( $swap_target . '/old-plugin' ), 'previous plugin children leave the target' );
ss_assert_same( 'old', file_get_contents( $swap_backup . '/old-plugin/a.php' ), 'previous children land in the rollback backup' );
ss_assert( is_link( $swap_target . '/protected-link' ), 'existing symlinks stay in the target directory' );
ss_assert( ! is_dir( $swap_stage ), 'empty stage tree is removed after promote' );

$content_job = array(
	'id'           => 'unitcontentswap',
	'working_dirs' => array(
		'plugins' => array(
			'target'       => $swap_target,
			'stage'        => $swap_stage,
			'backup'       => $swap_backup,
			'activated'    => true,
			'had_target'   => true,
			'content_swap' => true,
		),
	),
);
$rollback_content = new ReflectionMethod( Sync_Runner::class, 'rollback_file_dirs' );
if ( PHP_VERSION_ID < 80100 ) {
	$rollback_content->setAccessible( true );
}
$rollback_content->invokeArgs( null, array( &$content_job ) );
ss_assert_same( 'old', file_get_contents( $swap_target . '/old-plugin/a.php' ), 'content-swap rollback restores evacuated plugin children' );
ss_assert( ! file_exists( $swap_target . '/new-plugin' ), 'content-swap rollback removes the promoted children' );
ss_assert( is_link( $swap_target . '/protected-link' ), 'content-swap rollback preserves protected symlinks' );
ss_assert( is_dir( $swap_target ), 'content-swap rollback never deletes the target directory itself' );

foreach ( array( $swap_target . '/old-plugin/a.php', $swap_target . '/keep.php', $swap_target . '/protected-link' ) as $f ) {
	@unlink( $f );
}
@rmdir( $swap_target . '/old-plugin' );
@rmdir( $swap_target );
@rmdir( $swap_root );

$deleted = Files::delete_paths( $root, array( 'sub/deep/leaf.txt', '../outside.txt', 'link.txt', 'missing.txt' ) );
ss_assert_same( 1, $deleted, 'only real, safe, non-symlink paths are deleted' );
ss_assert( ! file_exists( $root . '/sub/deep' ), 'emptied directories are pruned' );
ss_assert( file_exists( $root . '/sub/mid.txt' ), 'sibling files survive pruning' );
ss_assert( is_link( $root . '/link.txt' ), 'symlinks are never deleted' );

// Cleanup.
foreach ( array( '/link.txt', '/linkdir', '/top.txt', '/sub/mid.txt', '/linked-target/inside.txt' ) as $f ) {
	@unlink( $root . $f );
}
@rmdir( $root . '/linked-target' );
@rmdir( $root . '/sub' );
@rmdir( $root );

ss_test_section( 'Files: staged directory guard is discarded before validation' );

// prepare_file_stage() writes a placeholder index.php into every new stage so
// the web-reachable sidecar never lists or executes while files land. The
// source manifest knows nothing about that placeholder, so validation must
// see the stage without it; for mu-plugins/ (no index.php upstream) leaving
// it behind both fails validation and, if activated, would load a 404 stub
// as a must-use plugin.
$guard_root  = sys_get_temp_dir() . '/ss-guard-test-' . bin2hex( random_bytes( 4 ) );
$guard_stage = $guard_root . '/stage-mu-plugins';
mkdir( $guard_stage, 0755, true );
$discard_guard = new ReflectionMethod( Sync_Runner::class, 'discard_stage_guard' );
if ( PHP_VERSION_ID < 80100 ) {
	$discard_guard->setAccessible( true );
}

file_put_contents( $guard_stage . '/index.php', "<?php http_response_code( 404 ); exit;\n" );
file_put_contents( $guard_stage . '/zz-test-notice.php', "<?php // synced mu-plugin\n" );
$discard_guard->invoke( null, $guard_stage );
ss_assert( ! file_exists( $guard_stage . '/index.php' ), 'the staging placeholder index.php is removed before validation' );
ss_assert( file_exists( $guard_stage . '/zz-test-notice.php' ), 'synced files next to the placeholder are untouched' );

file_put_contents( $guard_stage . '/index.php', "<?php\n// Silence is golden.\n" );
$discard_guard->invoke( null, $guard_stage );
ss_assert_same( "<?php\n// Silence is golden.\n", @file_get_contents( $guard_stage . '/index.php' ), 'an index.php delivered by the source replaces the placeholder and is kept' );
@unlink( $guard_stage . '/index.php' );

file_put_contents( $guard_root . '/outside-index.php', "<?php http_response_code( 404 ); exit;\n" );
symlink( $guard_root . '/outside-index.php', $guard_stage . '/index.php' );
$discard_guard->invoke( null, $guard_stage );
ss_assert( is_link( $guard_stage . '/index.php' ), 'a symlinked index.php in the stage is never treated as the placeholder' );
ss_assert( file_exists( $guard_root . '/outside-index.php' ), 'symlink targets are never deleted' );

ss_assert_same( null, $discard_guard->invoke( null, $guard_root . '/missing-stage' ), 'a stage without any index.php is a no-op' );

@unlink( $guard_stage . '/index.php' );
@unlink( $guard_stage . '/zz-test-notice.php' );
@unlink( $guard_root . '/outside-index.php' );
@rmdir( $guard_stage );
@rmdir( $guard_root );
