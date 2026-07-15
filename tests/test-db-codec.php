<?php
/**
 * Wire codec tests: gzipped statement payloads must round-trip exactly,
 * including binary bytes, with graceful fallbacks for older payload shapes.
 */

use SandboxSync\DB_Exporter;

ss_test_section( 'DB codec: gzip round-trip' );

$statements = array(
	"INSERT INTO `wp_posts` (`ID`, `post_title`) VALUES (1, 'Hello');",
	"INSERT INTO `wp_options` (`option_value`) VALUES ('" . 'a:1:{s:3:"url";s:18:"https://live.local";}' . "');",
	// Binary-ish payload with escaped NULs and every byte class the escaper
	// leaves intact (raw high bytes survive gzip untouched).
	"INSERT INTO `wp_meta` (`v`) VALUES ('\\0\\Z" . chr( 0xE9 ) . chr( 0xFF ) . chr( 0x01 ) . "');",
);

$payload = DB_Exporter::encode_statements( $statements );
ss_assert( isset( $payload['sql_gz_b64'] ), 'zlib present: payload is gzipped' );
ss_assert_same( 64, strlen( $payload['sql_sha256'] ), 'payload carries a SHA-256 integrity digest' );
ss_assert_same( $statements, DB_Exporter::decode_statements( $payload ), 'statements round-trip byte-exact' );
$wrong_hash = $payload;
$wrong_hash['sql_sha256'] = str_repeat( '0', 64 );
ss_assert( is_wp_error( DB_Exporter::decode_statements( $wrong_hash ) ), 'payload with wrong integrity digest is rejected' );

// Compression actually pays for itself on SQL text.
$repetitive = array_fill( 0, 50, "INSERT INTO `wp_posts` (`ID`, `post_content`) VALUES (1, 'Lorem ipsum dolor sit amet, consectetur adipiscing elit.');" );
$packed     = DB_Exporter::encode_statements( $repetitive );
ss_assert(
	strlen( $packed['sql_gz_b64'] ) < strlen( implode( '', $repetitive ) ) / 5,
	'gzipped payload is at least 5x smaller than the raw SQL'
);

ss_test_section( 'DB codec: edge cases and fallbacks' );

ss_assert_same( array(), DB_Exporter::decode_statements( DB_Exporter::encode_statements( array() ) ), 'empty statement list round-trips to empty' );

$single = DB_Exporter::encode_statements( array( 'SELECT 1;' ) );
ss_assert_same( array( 'SELECT 1;' ), DB_Exporter::decode_statements( $single ), 'single statement round-trips' );

// Plain-b64 payload (source without zlib).
ss_assert_same(
	array( 'SELECT 2;' ),
	DB_Exporter::decode_statements( array( 'sql_b64' => array( base64_encode( 'SELECT 2;' ) ), 'sql_sha256' => hash( 'sha256', 'SELECT 2;' ) ) ),
	'plain base64 fallback decodes'
);

ss_assert( is_wp_error( DB_Exporter::decode_statements( array( 'insert_sql_b64' => array( base64_encode( 'SELECT 3;' ) ) ) ) ), 'unhashed legacy SQL payload is rejected' );
ss_assert( is_wp_error( DB_Exporter::decode_statements( array() ) ), 'payload without codec fields is rejected' );

$bad = DB_Exporter::decode_statements( array( 'sql_gz_b64' => base64_encode( 'not gzip at all' ), 'sql_sha256' => hash( 'sha256', 'irrelevant' ) ) );
ss_assert( is_wp_error( $bad ), 'corrupt gzip payload is a WP_Error' );
ss_assert( is_wp_error( DB_Exporter::decode_statements( array( 'sql_b64' => array( '***not-base64***' ), 'sql_sha256' => hash( 'sha256', '' ) ) ) ), 'invalid plain base64 is rejected strictly' );

// The statement separator never collides with escaped SQL: _real_escape
// output cannot contain raw NUL bytes.
ss_assert( false !== strpos( DB_Exporter::STMT_SEPARATOR, "\0" ), 'separator contains NUL (collision-free with escaped SQL)' );

ss_test_section( 'DB codec: CREATE TABLE schema hash ignores AUTO_INCREMENT' );

$create_a = "CREATE TABLE `wp_usermeta` (\n  `umeta_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n  `user_id` bigint(20) unsigned NOT NULL DEFAULT '0',\n  PRIMARY KEY (`umeta_id`)\n) ENGINE=InnoDB AUTO_INCREMENT=100 DEFAULT CHARSET=utf8mb4";
$create_b = "CREATE TABLE `wp_usermeta` (\n  `umeta_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n  `user_id` bigint(20) unsigned NOT NULL DEFAULT '0',\n  PRIMARY KEY (`umeta_id`)\n) ENGINE=InnoDB AUTO_INCREMENT=999999 DEFAULT CHARSET=utf8mb4";
$create_c = "CREATE TABLE `wp_usermeta` (\n  `umeta_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n  `user_id` bigint(20) unsigned NOT NULL DEFAULT '0',\n  `meta_key` varchar(255) DEFAULT NULL,\n  PRIMARY KEY (`umeta_id`)\n) ENGINE=InnoDB AUTO_INCREMENT=100 DEFAULT CHARSET=utf8mb4";

ss_assert_same(
	DB_Exporter::schema_sha256( $create_a ),
	DB_Exporter::schema_sha256( $create_b ),
	'AUTO_INCREMENT counter alone does not change schema hash'
);
ss_assert(
	DB_Exporter::schema_sha256( $create_a ) !== DB_Exporter::schema_sha256( $create_c ),
	'real column changes still change the schema hash'
);
ss_assert(
	false === strpos( DB_Exporter::normalize_create_sql( $create_a ), 'AUTO_INCREMENT=' ),
	'normalize strips table-option AUTO_INCREMENT'
);
ss_assert(
	false !== strpos( DB_Exporter::normalize_create_sql( $create_a ), 'NOT NULL AUTO_INCREMENT' ),
	'normalize keeps column AUTO_INCREMENT attribute'
);
