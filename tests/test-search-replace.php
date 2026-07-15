<?php
/**
 * Search_Replace::replace_value tests: plain strings, serialized data with
 * length changes, nested structures, objects, broken serialized data.
 */

use SandboxSync\Search_Replace;

$pairs = array(
	'https://live.local' => 'https://sandbox.local',
	'live.local'         => 'sandbox.local',
);

ss_test_section( 'Search-replace: plain strings' );

ss_assert_same(
	'Visit https://sandbox.local/about now',
	Search_Replace::replace_value( 'Visit https://live.local/about now', $pairs ),
	'plain string URL replaced'
);
ss_assert_same(
	'no match here',
	Search_Replace::replace_value( 'no match here', $pairs ),
	'string without matches unchanged'
);
ss_assert_same(
	'mail@sandbox.local',
	Search_Replace::replace_value( 'mail@live.local', $pairs ),
	'bare-domain replacement covers emails'
);

ss_test_section( 'Search-replace: serialized data' );

// live.local (10) -> sandbox.local (13): the s:N length prefix MUST change.
$serialized = serialize(
	array(
		'home'   => 'https://live.local',
		'nested' => array( 'link' => 'http://live.local/feed' ),
		'count'  => 42,
	)
);
$replaced = Search_Replace::replace_value( $serialized, $pairs );
$decoded  = unserialize( $replaced );
ss_assert( false !== $decoded, 'replaced serialized value still unserializes (lengths fixed)' );
ss_assert_same( 'https://sandbox.local', $decoded['home'], 'serialized top-level string replaced' );
ss_assert_same( 'http://sandbox.local/feed', $decoded['nested']['link'], 'serialized nested string replaced' );
ss_assert_same( 42, $decoded['count'], 'non-string values untouched' );

ss_assert_same(
	serialize( 'https://sandbox.local' ),
	Search_Replace::replace_value( serialize( 'https://live.local' ), $pairs ),
	'serialized scalar string replaced with corrected length'
);

$keys_value = serialize( array( 'live.local' => 'live.local' ) );
$decoded    = unserialize( Search_Replace::replace_value( $keys_value, $pairs ) );
ss_assert_same( 'sandbox.local', $decoded['sandbox.local'], 'serialized array keys and values are replaced safely' );

$json = '{"url":"https:\/\/live.local\/story","label":"keep formatting"}';
ss_assert_same(
	'{"url":"https:\/\/sandbox.local\/story","label":"keep formatting"}',
	Search_Replace::replace_value( $json, $pairs ),
	'JSON escaped URLs are replaced without re-encoding the document'
);

ss_assert_same( 'b:0;', Search_Replace::replace_value( 'b:0;', $pairs ), 'serialized false survives round-trip' );
ss_assert_same( 'N;', Search_Replace::replace_value( 'N;', $pairs ), 'serialized null survives round-trip' );

ss_test_section( 'Search-replace: objects and broken data' );

// Object string properties are rewritten at the serialization-token level,
// without loading or invoking the original class.
$object_value = 'O:8:"stdClass":1:{s:3:"url";s:18:"https://live.local";}';
$obj_result   = Search_Replace::replace_value( $object_value, $pairs );
$obj_decoded  = unserialize( $obj_result );
ss_assert( $obj_decoded instanceof stdClass, 'serialized object still unserializes to the original class' );
ss_assert_same( 'https://sandbox.local', $obj_decoded->url, 'serialized object property is replaced safely' );

$broken = 's:50:"https://live.local";'; // Wrong length prefix: unserializable.
ss_assert_same( $broken, Search_Replace::replace_value( $broken, $pairs ), 'broken serialized data left untouched' );

$array_with_object = serialize( array( 'url' => 'https://live.local', 'obj' => (object) array( 'u' => 'https://live.local' ) ) );
$mixed_decoded     = unserialize( Search_Replace::replace_value( $array_with_object, $pairs ) );
ss_assert_same( 'https://sandbox.local', $mixed_decoded['url'], 'strings replaced next to embedded objects' );
ss_assert( $mixed_decoded['obj'] instanceof stdClass, 'embedded object preserved' );
ss_assert_same( 'https://sandbox.local', $mixed_decoded['obj']->u, 'embedded object property is rewritten' );

ss_test_section( 'Search-replace: column length and overflow detection' );

ss_assert_same( 255, Search_Replace::column_max_bytes( 'varchar(255)' ), 'VARCHAR length is the rewrite ceiling' );
ss_assert_same( 191, Search_Replace::column_max_bytes( 'VARCHAR(191) COLLATE utf8mb4_unicode_ci' ), 'VARCHAR length is parsed from a typed column definition' );
ss_assert_same( 10, Search_Replace::column_max_bytes( 'char(10)' ), 'CHAR length is the rewrite ceiling' );
ss_assert_same( null, Search_Replace::column_max_bytes( 'longtext' ), 'TEXT columns are unbounded' );
ss_assert_same( null, Search_Replace::column_max_bytes( 'json' ), 'JSON columns are unbounded' );

ss_assert_same( true, Search_Replace::is_data_too_long_error( 'WordPress database error: Processing the value for the following field failed: `url`. The supplied value may be too long or contains invalid data.' ), 'WP-wrapped length error is recognized' );
ss_assert_same( true, Search_Replace::is_data_too_long_error( 'Error 1406: Data too long for column \'url\' at row 1' ), 'MySQL errno 1406 is recognized' );
ss_assert_same( false, Search_Replace::is_data_too_long_error( 'Deadlock found when trying to get lock' ), 'unrelated DB errors are not treated as overflow' );

// live.local (10) → sandbox.local (13) grows by 3; a URL already at 253 chars
// overflows VARCHAR(255) after rewrite and must be skipped, never truncated.
$near_limit = 'https://live.local/' . str_repeat( 'a', 253 - strlen( 'https://live.local/' ) );
$rewritten  = Search_Replace::replace_value( $near_limit, $pairs );
ss_assert( strlen( $rewritten ) > 255, 'hostname expansion pushes a near-limit Yoast URL past VARCHAR(255)' );
ss_assert( strlen( $near_limit ) <= 255, 'the original live URL still fits the column' );
