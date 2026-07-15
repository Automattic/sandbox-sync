<?php
/**
 * wp eval-file helper: pages a table from the source in tiny keyset chunks
 * and verifies cursor progression + row totals + payload decoding.
 * Usage (on the sandbox): wp eval-file fixtures/check-keyset.php
 */

use SandboxSync\Client;
use SandboxSync\DB_Exporter;

$table  = 'wp_posts';
$limit  = 5;
$cursor = null;
$total  = 0;
$chunks = 0;
$ids    = array();

while ( true ) {
	$query = array( 'table' => $table, 'limit' => $limit );
	if ( null !== $cursor ) {
		$query['cursor'] = $cursor;
	} else {
		$query['offset'] = 0;
	}

	$chunk = Client::get_json( 'table', $query );
	if ( is_wp_error( $chunk ) ) {
		echo 'ERROR: ' . $chunk->get_error_message() . "\n";
		exit( 1 );
	}

	$chunks++;
	$total += (int) $chunk['row_count'];

	$statements = DB_Exporter::decode_statements( $chunk );
	if ( is_wp_error( $statements ) ) {
		echo 'DECODE ERROR: ' . $statements->get_error_message() . "\n";
		exit( 1 );
	}
	echo sprintf(
		"chunk %d: rows=%d statements=%d gz=%s cursor=%s done=%s\n",
		$chunks,
		$chunk['row_count'],
		count( $statements ),
		isset( $chunk['sql_gz_b64'] ) ? 'yes' : 'no',
		isset( $chunk['next_cursor'] ) && $chunk['next_cursor'] ? json_decode( base64_decode( strtr( $chunk['next_cursor'], '-_', '+/' ) ), true )['ID'] : '-',
		empty( $chunk['done'] ) ? 'no' : 'yes'
	);

	// Collect IDs from the statements to check ordering/uniqueness.
	foreach ( $statements as $statement ) {
		if ( preg_match_all( '/^\((\d+),/m', $statement, $m ) ) {
			foreach ( $m[1] as $id ) {
				$ids[] = (int) $id;
			}
		}
	}

	if ( ! empty( $chunk['done'] ) ) {
		break;
	}
	$cursor = $chunk['next_cursor'];
}

$sorted = $ids;
sort( $sorted );
echo sprintf(
	"total rows=%d in %d chunks; ids unique=%s strictly-ascending=%s\n",
	$total,
	$chunks,
	count( $ids ) === count( array_unique( $ids ) ) ? 'yes' : 'NO',
	$ids === $sorted ? 'yes' : 'NO'
);
