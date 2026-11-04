<?php
/**
 * Tar round-trip tests: Tar_Exporter::stream -> Tar_Extractor::extract must
 * reproduce the directory contents, skip symlinks, and reject unsafe paths.
 */

use SandboxSync\Tar_Exporter;
use SandboxSync\Tar_Extractor;
use SandboxSync\Tar_Stream_Extractor;

ss_test_section( 'Tar: export/extract round-trip' );

$base = sys_get_temp_dir() . '/sandbox-sync-tar-test-' . bin2hex( random_bytes( 4 ) );
$src  = $base . '/src';
$dst  = $base . '/dst';
mkdir( $src . '/plugin-a/inc', 0755, true );
mkdir( $src . '/plugin-b', 0755, true );
mkdir( $base . '/outside', 0755, true );

file_put_contents( $src . '/loose-file.php', "<?php // loose\n" );
chmod( $src . '/loose-file.php', 0755 );
file_put_contents( $src . '/plugin-a/plugin-a.php', "<?php // plugin a\n" );
file_put_contents( $src . '/plugin-a/inc/helper.php', str_repeat( 'x', 1000 ) ); // Forces multi-block content + padding.
file_put_contents( $src . '/plugin-b/binary.dat', random_bytes( 700 ) );
file_put_contents( $src . '/plugin-b/empty.txt', '' );
file_put_contents( $src . '/plugin-b/file..with-dots.txt', 'dots are safe' );
symlink( $base . '/outside', $src . '/linked-plugin' );          // Top-level symlink: must be skipped.
symlink( $src . '/loose-file.php', $src . '/plugin-b/nested-link.php' ); // Nested symlink: must be skipped.
file_put_contents( $base . '/outside/secret.txt', 'outside secret' );
symlink( $base . '/outside', $src . '/plugin-b/nested-dir-link' );

// Deep path (> 100 chars) to exercise the ustar name+prefix split.
$deep_dir = $src . '/plugin-a/' . str_repeat( 'deep-directory-name/', 5 );
mkdir( $deep_dir, 0755, true );
file_put_contents( $deep_dir . 'deep-file.txt', 'deep content' );

$tar_file = $base . '/archive.tar';
ob_start();
Tar_Exporter::stream( $src );
file_put_contents( $tar_file, ob_get_clean() );

ss_assert( filesize( $tar_file ) >= 1024, 'archive ends with the 1024-byte EOT marker' );
ss_assert_same(
	str_repeat( "\0", 1024 ),
	substr( file_get_contents( $tar_file ), -1024 ),
	'last 1024 bytes are zero blocks'
);
$result = Tar_Extractor::extract( $tar_file, $dst );
ss_assert_same( true, $result, 'extraction succeeds' );
ss_assert_same( 0755, fileperms( $dst . '/loose-file.php' ) & 0777, 'file mode is preserved' );

ss_assert_same(
	file_get_contents( $src . '/loose-file.php' ),
	file_get_contents( $dst . '/loose-file.php' ),
	'top-level loose file round-trips'
);
ss_assert_same(
	file_get_contents( $src . '/plugin-a/inc/helper.php' ),
	file_get_contents( $dst . '/plugin-a/inc/helper.php' ),
	'nested file with padding round-trips'
);
ss_assert_same(
	file_get_contents( $src . '/plugin-b/binary.dat' ),
	file_get_contents( $dst . '/plugin-b/binary.dat' ),
	'binary content round-trips byte-identical'
);
ss_assert( file_exists( $dst . '/plugin-b/empty.txt' ) && 0 === filesize( $dst . '/plugin-b/empty.txt' ), 'empty file round-trips' );
ss_assert_same( 'dots are safe', file_get_contents( $dst . '/plugin-b/file..with-dots.txt' ), 'safe filename containing two dots round-trips' );
ss_assert_same(
	'deep content',
	file_get_contents( $dst . '/plugin-a/' . str_repeat( 'deep-directory-name/', 5 ) . 'deep-file.txt' ),
	'path longer than 100 chars round-trips via ustar prefix field'
);

ss_assert( ! file_exists( $dst . '/linked-plugin' ), 'top-level symlink was not exported' );
ss_assert( ! file_exists( $dst . '/plugin-b/nested-link.php' ), 'nested symlink was not exported' );
ss_assert( ! file_exists( $dst . '/plugin-b/nested-dir-link' ), 'nested directory symlink was not exported' );

$nested_escape = Tar_Exporter::prepare_paths( $src, array( 'plugin-b/nested-dir-link/secret.txt' ) );
ss_assert( is_wp_error( $nested_escape ), 'incremental path crossing a nested source symlink is rejected' );

ss_test_section( 'Tar: single-pass verified uploads batches' );

$verified_paths = array( 'loose-file.php', 'plugin-b/binary.dat', 'plugin-b/empty.txt' );
$verified_plan  = array();
foreach ( $verified_paths as $path ) {
	$full            = $src . '/' . $path;
	$verified_plan[] = array(
		'p' => $path,
		's' => filesize( $full ),
		'm' => filemtime( $full ),
		'o' => fileperms( $full ) & 0777,
	);
}
$verified_token = 'test-bearer-token';
$verified_nonce = hash( 'sha256', 'verified-request' );
ob_start();
Tar_Exporter::stream_verified_paths( $src, $verified_plan, 'uploads', $verified_nonce, $verified_token );
$verified_bytes = ob_get_clean();
$verified_tar   = $base . '/verified.tar';
file_put_contents( $verified_tar, $verified_bytes );
$verified_dst = $base . '/verified-dst';
$verified     = Tar_Extractor::extract_verified( $verified_tar, $verified_dst, 'uploads', $verified_nonce, $verified_token, $verified_plan );
ss_assert( is_array( $verified ) && count( $verified_plan ) === count( $verified ), 'stream-hashed archive verifies every requested file' );
ss_assert_same( hash_file( 'sha256', $src . '/plugin-b/binary.dat' ), $verified[1]['h'], 'verified manifest carries the streamed payload SHA-256' );
ss_assert( ! file_exists( $verified_dst . '/' . Tar_Exporter::VERIFIED_MANIFEST_PATH ), 'private transfer manifest is never extracted into uploads' );

$wrong_token_dst = $base . '/wrong-token-dst';
ss_assert(
	is_wp_error( Tar_Extractor::extract_verified( $verified_tar, $wrong_token_dst, 'uploads', $verified_nonce, 'wrong-token', $verified_plan ) ),
	'archive signed by another bearer session is rejected'
);

$tampered_bytes      = $verified_bytes;
$first_payload_offset = 512;
$tampered_bytes[ $first_payload_offset ] = chr( ord( $tampered_bytes[ $first_payload_offset ] ) ^ 1 );
$tampered_tar = $base . '/verified-tampered.tar';
file_put_contents( $tampered_tar, $tampered_bytes );
ss_assert(
	is_wp_error( Tar_Extractor::extract_verified( $tampered_tar, $base . '/tampered-dst', 'uploads', $verified_nonce, $verified_token, $verified_plan ) ),
	'payload corruption is rejected by the streamed SHA-256 manifest'
);

ss_test_section( 'Tar: direct first-baseline uploads stream' );

$bulk_src = $base . '/bulk-src';
$bulk_dst = $base . '/bulk-dst';
$bulk_tmp = $base . '/bulk-tmp';
mkdir( $bulk_src . '/2024/01', 0755, true );
mkdir( $bulk_src . '/2024/02', 0755, true );
mkdir( $bulk_src . '/custom', 0755, true );
file_put_contents( $bulk_src . '/2024/01/photo.jpg', str_repeat( 'photo', 317 ) );
file_put_contents( $bulk_src . '/2024/02/private.jpg', 'not selected' );
file_put_contents( $bulk_src . '/2024/loose.pdf', 'loose year file' );
file_put_contents( $bulk_src . '/custom/logo.png', 'custom logo' );

ob_start();
$bulk_streamed = Tar_Exporter::stream_upload_scopes( $bulk_src, array( '2024/01', 'other' ) );
$bulk_bytes    = ob_get_clean();
ss_assert_same( true, $bulk_streamed, 'single-pass baseline stream completes without a preflight traversal' );
$receiver = new Tar_Stream_Extractor( $bulk_dst, $bulk_tmp, array( '2024/01', 'other' ), 10485760 );
$offset     = 0;
$sizes      = array( 1, 37, 511, 8192, 73 );
$iteration  = 0;
while ( $offset < strlen( $bulk_bytes ) ) {
	$chunk = substr( $bulk_bytes, $offset, $sizes[ $iteration++ % count( $sizes ) ] );
	ss_assert_same( strlen( $chunk ), $receiver->write( $chunk ), 'stream parser accepts an arbitrarily chunked tar segment' );
	$offset += strlen( $chunk );
}
$bulk_result = $receiver->finish();
ss_assert( is_array( $bulk_result ) && 3 === $bulk_result['files'], 'direct baseline parser completes all selected files' );
ss_assert_same( file_get_contents( $bulk_src . '/2024/01/photo.jpg' ), file_get_contents( $bulk_dst . '/2024/01/photo.jpg' ), 'direct baseline preserves selected dated media bytes' );
ss_assert_same( 'loose year file', file_get_contents( $bulk_dst . '/2024/loose.pdf' ), 'direct baseline includes loose year content in other' );
ss_assert_same( 'custom logo', file_get_contents( $bulk_dst . '/custom/logo.png' ), 'direct baseline includes non-date content' );
ss_assert( ! file_exists( $bulk_dst . '/2024/02/private.jpg' ), 'direct baseline cannot write an unselected month' );

$scope_dst      = $base . '/bulk-scope-dst';
$scope_tmp      = $base . '/bulk-scope-tmp';
$scope_receiver = new Tar_Stream_Extractor( $scope_dst, $scope_tmp, array( '2024/01' ), 10485760 );
ss_assert_same( 0, $scope_receiver->write( $bulk_bytes ), 'target parser rejects an archive entry outside its exact allowed scopes' );
ss_assert( is_wp_error( $scope_receiver->error() ), 'scope violation is retained as the stream failure' );
ss_assert( ! file_exists( $scope_dst . '/custom/logo.png' ), 'rejected stream never writes the out-of-scope payload' );

$corrupt_bulk     = $bulk_bytes;
$corrupt_bulk[0]  = 'X';
$corrupt_receiver = new Tar_Stream_Extractor( $base . '/bulk-corrupt-dst', $base . '/bulk-corrupt-tmp', array( '2024/01', 'other' ), 10485760 );
ss_assert_same( 0, $corrupt_receiver->write( $corrupt_bulk ), 'direct baseline rejects a corrupt tar header' );
ss_assert( is_wp_error( $corrupt_receiver->error() ), 'direct baseline exposes corrupt-header failure' );

$non_ustar          = ss_tar_reseal_header( substr_replace( $bulk_bytes, str_repeat( "\0", 6 ), 257, 6 ) );
$non_ustar_receiver = new Tar_Stream_Extractor( $base . '/bulk-format-dst', $base . '/bulk-format-tmp', array( '2024/01', 'other' ), 10485760 );
ss_assert_same( 0, $non_ustar_receiver->write( $non_ustar ), 'direct baseline rejects a checksummed non-ustar stream' );
ss_assert_same( 'tar_stream_format', $non_ustar_receiver->error()->get_error_code(), 'unsupported tar format has a specific failure code' );

$wrong_root_type      = substr_replace( $bulk_bytes, str_pad( '2024/01', 100, "\0" ), 0, 100 );
$wrong_root_type[156] = '0';
$wrong_root_type      = ss_tar_reseal_header( $wrong_root_type );
$wrong_root_receiver  = new Tar_Stream_Extractor( $base . '/bulk-root-dst', $base . '/bulk-root-tmp', array( '2024/01', 'other' ), 10485760 );
ss_assert_same( 0, $wrong_root_receiver->write( $wrong_root_type ), 'dated partition root cannot be replaced by a regular file' );
ss_assert_same( 'tar_stream_type', $wrong_root_receiver->error()->get_error_code(), 'dated partition type confusion has a specific failure code' );

$numeric_overflow  = ss_tar_reseal_header( substr_replace( $bulk_bytes, '100000000000', 124, 12 ) );
$overflow_receiver = new Tar_Stream_Extractor( $base . '/bulk-overflow-dst', $base . '/bulk-overflow-tmp', array( '2024/01', 'other' ), 10485760 );
ss_assert_same( 0, $overflow_receiver->write( $numeric_overflow ), 'target rejects tar numeric metadata beyond the supported ustar range' );
ss_assert_same( 'tar_stream_header', $overflow_receiver->error()->get_error_code(), 'numeric overflow has a specific failure code' );

ss_test_section( 'Tar: baseline resume and traversal order' );

ss_assert( Tar_Exporter::traversal_cmp( '2024/01', '2024/01/photo.jpg' ) < 0, 'a directory sorts before its own children' );
ss_assert( Tar_Exporter::traversal_cmp( 'a/b', 'a.txt' ) < 0, 'depth-first order: descendants of an earlier sibling come before a later file' );
ss_assert_same( 0, Tar_Exporter::traversal_cmp( 'custom/logo.png', 'custom/logo.png' ), 'identical paths compare equal' );
ss_assert( Tar_Exporter::traversal_cmp( 'custom/logo.png', '2024/loose.pdf' ) > 0, 'component-wise byte order matches the sorted stream' );

ob_start();
$resume_streamed = Tar_Exporter::stream_upload_scopes( $bulk_src, array( '2024/01', 'other' ), null, '2024/01/photo.jpg' );
$resume_bytes    = ob_get_clean();
ss_assert_same( true, $resume_streamed, 'resumed baseline stream completes' );
$resume_dst      = $base . '/bulk-resume-dst';
$resume_receiver = new Tar_Stream_Extractor( $resume_dst, $base . '/bulk-resume-tmp', array( '2024/01', 'other' ), 10485760 );
ss_assert_same( strlen( $resume_bytes ), $resume_receiver->write( $resume_bytes ), 'resumed stream parses cleanly' );
$resume_result = $resume_receiver->finish();
ss_assert( is_array( $resume_result ) && 2 === $resume_result['files'], 'resume skips every file up to and including the cursor' );
ss_assert( ! file_exists( $resume_dst . '/2024/01/photo.jpg' ), 'the already-installed cursor file is not re-streamed' );
ss_assert_same( 'loose year file', file_get_contents( $resume_dst . '/2024/loose.pdf' ), 'files after the cursor stream normally' );
ss_assert_same( 'custom/logo.png', $resume_result['last_installed'], 'parser reports the durable resume cursor' );

$cancel_checks = 0;
ob_start();
$cancelled = Tar_Exporter::stream_upload_scopes(
	$bulk_src,
	array( '2024/01' ),
	function () use ( &$cancel_checks ) {
		++$cancel_checks;
		return false;
	}
);
ob_end_clean();
ss_assert_same( 'tar_cancelled', $cancelled->get_error_code(), 'baseline stream cooperatively observes cancellation' );
ss_assert_same( 1, $cancel_checks, 'cancelled baseline stream stops at its first checkpoint' );

$oversized_path   = $bulk_src . '/2024/01/oversized.mov';
$oversized_handle = fopen( $oversized_path, 'wb' );
$sparse_created   = is_resource( $oversized_handle ) && ftruncate( $oversized_handle, Tar_Exporter::USTAR_MAX_SIZE + 1 );
if ( is_resource( $oversized_handle ) ) {
	fclose( $oversized_handle );
}
clearstatcache( true, $oversized_path );
ss_assert( $sparse_created, 'test filesystem creates an allocation-free sparse file above the ustar limit' );
ob_start();
$oversized_result = Tar_Exporter::stream_upload_scopes( $bulk_src, array( '2024/01' ) );
ob_end_clean();
ss_assert_same( 'tar_numeric_range', $oversized_result->get_error_code(), 'inline validation rejects a file ustar cannot represent, truncating the archive' );
unlink( $oversized_path );

$partial_receiver = new Tar_Stream_Extractor( $base . '/bulk-partial-dst', $base . '/bulk-partial-tmp', array( '2024/01', 'other' ), 10485760 );
$partial_receiver->write( substr( $bulk_bytes, 0, 700 ) );
ss_assert( is_wp_error( $partial_receiver->finish() ), 'truncated baseline stream fails without a complete tar end marker' );
ss_assert( ! file_exists( $base . '/bulk-partial-dst/2024/01/photo.jpg' ), 'an incomplete streamed file is never made visible' );

ss_test_section( 'Tar: edge cases' );

// Empty source directory: EOT-only archive extracts to nothing.
$empty_src = $base . '/empty-src';
$empty_dst = $base . '/empty-dst';
mkdir( $empty_src );
ob_start();
Tar_Exporter::stream( $empty_src );
$empty_tar = $base . '/empty.tar';
file_put_contents( $empty_tar, ob_get_clean() );
ss_assert_same( 1024, filesize( $empty_tar ), 'empty dir produces EOT-only archive' );
ss_assert_same( true, Tar_Extractor::extract( $empty_tar, $empty_dst ), 'EOT-only archive extracts cleanly' );
ss_assert_same( array( '.', '..' ), scandir( $empty_dst ), 'nothing extracted from empty archive' );

// Malicious archive with ../ traversal must be rejected.
$evil_name   = '../evil.php';
$evil_header = ss_tar_reseal_header(
	str_pad( $evil_name, 100, "\0" )
	. str_pad( decoct( 0644 ), 7, '0', STR_PAD_LEFT ) . "\0"
	. str_pad( '0', 7, '0', STR_PAD_LEFT ) . "\0"
	. str_pad( '0', 7, '0', STR_PAD_LEFT ) . "\0"
	. str_pad( decoct( 4 ), 11, '0', STR_PAD_LEFT ) . "\0"
	. str_pad( decoct( 0 ), 11, '0', STR_PAD_LEFT ) . "\0"
	. '        ' . '0' . str_repeat( "\0", 100 ) . "ustar\0" . '00'
	. str_repeat( "\0", 32 ) . str_repeat( "\0", 32 )
	. str_repeat( "\0", 8 ) . str_repeat( "\0", 8 )
	. str_repeat( "\0", 155 ) . str_repeat( "\0", 12 )
);

$evil_tar = $base . '/evil.tar';
file_put_contents( $evil_tar, $evil_header . str_pad( 'pwnd', 512, "\0" ) . str_repeat( "\0", 1024 ) );

$evil_dst = $base . '/evil-dst';
mkdir( $evil_dst );
$evil_result = Tar_Extractor::extract( $evil_tar, $evil_dst );
ss_assert( is_wp_error( $evil_result ), 'path traversal archive is rejected' );
ss_assert( ! file_exists( $base . '/evil.php' ), 'traversal file was not written' );

// A pre-existing destination symlink must never be followed during extract.
$pivot_dst = $base . '/pivot-dst';
mkdir( $pivot_dst );
symlink( $base . '/outside', $pivot_dst . '/plugin-a' );
$pivot_result = Tar_Extractor::extract( $tar_file, $pivot_dst );
ss_assert( is_wp_error( $pivot_result ), 'destination symlink pivot is rejected' );
ss_assert( ! file_exists( $base . '/outside/plugin-a.php' ), 'nothing is written through destination symlink' );

// Corrupt headers and incomplete EOT markers must fail closed.
$corrupt_tar = $base . '/corrupt.tar';
$corrupt     = file_get_contents( $tar_file );
$corrupt[0]  = 'X';
file_put_contents( $corrupt_tar, $corrupt );
ss_assert( is_wp_error( Tar_Extractor::extract( $corrupt_tar, $base . '/corrupt-dst' ) ), 'invalid tar header checksum is rejected' );

$truncated_tar = $base . '/truncated.tar';
file_put_contents( $truncated_tar, substr( file_get_contents( $tar_file ), 0, -512 ) );
ss_assert( is_wp_error( Tar_Extractor::extract( $truncated_tar, $base . '/truncated-dst' ) ), 'archive missing second EOT block is rejected' );

ss_test_section( 'Tar: GNU long names for paths the ustar fields cannot hold' );

// Real-world media names (Spanish headlines as file names) exceed the 100-byte
// ustar name field in a single component, so no name/prefix split exists.
// Those entries travel as a GNU "././@LongLink" (type L) record and must
// round-trip through every archive path instead of aborting the transfer.
$long_name = 'Fotos-de-funeraria-San-Martin-quien-apoyo-con-los-traslados-de-los-cuerpos-de-Luis-Alberto-y-Maria-del-Carmen-en-la-comunidad-1.jpeg';
$long_dir  = str_repeat( 'directorio-con-un-nombre-muy-largo-', 3 ) . 'fin';
ss_assert( strlen( $long_name ) > 100 && strlen( $long_dir ) > 100, 'fixture names exceed the ustar name field' );

$long_src = $base . '/long-src';
mkdir( $long_src . '/a/' . $long_dir, 0755, true );
file_put_contents( $long_src . '/a/' . $long_name, 'long file content' );
file_put_contents( $long_src . '/a/' . $long_dir . '/inside.txt', 'inside long dir' );
file_put_contents( $long_src . '/a/short.txt', 'short' );
ss_assert_same( true, Tar_Exporter::validate_tree( $long_src ), 'preflight accepts single components longer than 100 bytes' );

$long_tar = $base . '/long.tar';
ob_start();
Tar_Exporter::stream( $long_src );
file_put_contents( $long_tar, ob_get_clean() );
$long_dst = $base . '/long-dst';
ss_assert_same( true, Tar_Extractor::extract( $long_tar, $long_dst ), 'full-directory archive with long names extracts' );
ss_assert_same( 'long file content', @file_get_contents( $long_dst . '/a/' . $long_name ), 'long file name is restored exactly by the batch extractor' );
ss_assert_same( 'inside long dir', @file_get_contents( $long_dst . '/a/' . $long_dir . '/inside.txt' ), 'long directory name is restored exactly by the batch extractor' );
ss_assert_same( 'short', @file_get_contents( $long_dst . '/a/short.txt' ), 'short names next to long names still extract' );

ss_assert( is_array( Tar_Exporter::prepare_paths( $long_src, array( 'a/' . $long_name ) ) ), 'verified batch planning accepts a long file name' );
$long_full = $long_src . '/a/' . $long_name;
$long_plan = array(
	array(
		'p' => 'a/' . $long_name,
		's' => filesize( $long_full ),
		'm' => filemtime( $long_full ),
		'o' => fileperms( $long_full ) & 0777,
	),
);
$long_nonce = hash( 'sha256', 'long-nonce' );
$long_token = 'long-token';
ob_start();
Tar_Exporter::stream_verified_paths( $long_src, $long_plan, 'uploads', $long_nonce, $long_token );
$long_verified_tar = $base . '/long-verified.tar';
file_put_contents( $long_verified_tar, ob_get_clean() );
$long_verified_dst = $base . '/long-verified-dst';
$long_verified     = Tar_Extractor::extract_verified( $long_verified_tar, $long_verified_dst, 'uploads', $long_nonce, $long_token, $long_plan );
ss_assert( is_array( $long_verified ) && 1 === count( $long_verified ) && $long_verified[0]['p'] === 'a/' . $long_name, 'verified batch manifest binds to the restored long path' );
ss_assert_same( 'long file content', @file_get_contents( $long_verified_dst . '/a/' . $long_name ), 'verified batch extracts the long file name' );

$long_bulk_src = $base . '/long-bulk-src';
mkdir( $long_bulk_src . '/2026/01', 0755, true );
mkdir( $long_bulk_src . '/2025-temp/10', 0755, true );
file_put_contents( $long_bulk_src . '/2026/01/' . $long_name, 'dated long media' );
file_put_contents( $long_bulk_src . '/2026/01/zz-after.jpg', 'after the long one' );
file_put_contents( $long_bulk_src . '/2025-temp/10/' . $long_name, 'non-dated long media' );
ob_start();
$long_bulk_streamed = Tar_Exporter::stream_upload_scopes( $long_bulk_src, array( '2026/01', 'other' ) );
$long_bulk_bytes    = ob_get_clean();
ss_assert_same( true, $long_bulk_streamed, 'baseline stream no longer aborts on a long media name' );
$long_bulk_dst      = $base . '/long-bulk-dst';
$long_bulk_receiver = new Tar_Stream_Extractor( $long_bulk_dst, $base . '/long-bulk-tmp', array( '2026/01', 'other' ), 10485760 );
$offset = 0;
while ( $offset < strlen( $long_bulk_bytes ) ) {
	$chunk   = substr( $long_bulk_bytes, $offset, 300 );
	$offset += strlen( $chunk );
	if ( strlen( $chunk ) !== $long_bulk_receiver->write( $chunk ) ) {
		break;
	}
}
$long_bulk_result = $long_bulk_receiver->finish();
ss_assert( is_array( $long_bulk_result ) && 3 === $long_bulk_result['files'], 'streaming extractor installs every file including long names' );
ss_assert_same( 'dated long media', @file_get_contents( $long_bulk_dst . '/2026/01/' . $long_name ), 'streaming extractor restores a long dated media name' );
ss_assert_same( 'non-dated long media', @file_get_contents( $long_bulk_dst . '/2025-temp/10/' . $long_name ), 'streaming extractor restores a long name under a non-dated partition' );
ss_assert_same( '2026/01/zz-after.jpg', is_array( $long_bulk_result ) ? $long_bulk_result['last_installed'] : '', 'resume cursor keeps tracking after a long-named file' );

// A long-name record must be followed by the entry it names. Keep only the
// first long-name record of the baseline stream (its header plus data block)
// and end the archive there: both extractors must refuse the dangling record.
$dangling = substr( $long_bulk_bytes, 512 * 2, 1024 ) . str_repeat( "\0", 1024 );
ss_assert_same( 'L', substr( $dangling, 156, 1 ), 'fixture isolates a long-name record' );
$dangling_tar = $base . '/dangling.tar';
file_put_contents( $dangling_tar, $dangling );
ss_assert( is_wp_error( Tar_Extractor::extract( $dangling_tar, $base . '/dangling-dst' ) ), 'batch extractor rejects a long-name record that names nothing' );
$dangling_receiver = new Tar_Stream_Extractor( $base . '/dangling-stream-dst', $base . '/dangling-stream-tmp', array( '2026/01', 'other' ), 10485760 );
$dangling_receiver->write( $dangling );
ss_assert( is_wp_error( $dangling_receiver->finish() ), 'streaming extractor rejects a long-name record that names nothing' );

ss_test_rmrf( $base );
