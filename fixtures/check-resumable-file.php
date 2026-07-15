<?php
/** Real-protocol smoke test for a partial raw-file resume. Run via wp eval-file. */

use SandboxSync\Client;
use SandboxSync\Files;

$manifest = sys_get_temp_dir() . '/sandbox-sync-range-manifest-' . bin2hex( random_bytes( 4 ) ) . '.ndjson';
$partial  = sys_get_temp_dir() . '/sandbox-sync-range-partial-' . bin2hex( random_bytes( 4 ) ) . '.bin';

Client::reset_transfer_session();
$session = Client::begin_transfer_session(
	array(
		'db'   => false,
		'dirs' => array( 'plugins' ),
	)
);
if ( is_wp_error( $session ) ) {
	throw new RuntimeException( $session->get_error_message() );
}

try {
	$count = Client::download_file_manifest( 'plugins', $manifest, true );
	if ( is_wp_error( $count ) ) {
		throw new RuntimeException( $count->get_error_message() );
	}
	$entry = null;
	foreach ( Files::read_ndjson_manifest( $manifest ) as $candidate ) {
		if ( (int) $candidate['s'] > 2 ) {
			$entry = $candidate;
			break;
		}
	}
	if ( ! $entry ) {
		throw new RuntimeException( 'No non-empty source plugin file is available for the range smoke test.' );
	}

	$downloaded = Client::download_file_resumable( 'plugins', $entry, $partial );
	if ( is_wp_error( $downloaded ) ) {
		throw new RuntimeException( $downloaded->get_error_message() );
	}
	$half = max( 1, (int) floor( (int) $entry['s'] / 2 ) );
	$fh   = fopen( $partial, 'c+' );
	if ( ! $fh || ! ftruncate( $fh, $half ) ) {
		throw new RuntimeException( 'Could not prepare a partial local file for resume.' );
	}
	fclose( $fh );
	clearstatcache( true, $partial );

	$resumed = Client::download_file_resumable( 'plugins', $entry, $partial );
	if ( is_wp_error( $resumed ) ) {
		throw new RuntimeException( $resumed->get_error_message() );
	}
	$hash = hash_file( 'sha256', $partial );
	if ( filesize( $partial ) !== (int) $entry['s'] || false === $hash || ! hash_equals( (string) $entry['h'], $hash ) ) {
		throw new RuntimeException( 'Resumed file failed final size/hash verification.' );
	}
	echo 'resumable-file-ok path=' . $entry['p'] . ' bytes=' . $entry['s'] . ' resumed_at=' . $half . PHP_EOL;
} finally {
	@unlink( $manifest );
	@unlink( $partial );
	$closed = Client::end_transfer_session();
	if ( is_wp_error( $closed ) ) {
		throw new RuntimeException( $closed->get_error_message() );
	}
}
