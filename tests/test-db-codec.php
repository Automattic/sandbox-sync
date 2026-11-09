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

ss_test_section( 'DB codec: schema hash is table-name and redundant-charset insensitive' );

// A sandbox keeps its own table prefix, so the local copy of wp_posts is
// sb_posts. SHOW CREATE TABLE embeds the name; the structural hash must not.
$posts_body = "(\n  `ID` bigint unsigned NOT NULL AUTO_INCREMENT,\n  `post_title` text COLLATE utf8mb4_unicode_520_ci NOT NULL,\n  PRIMARY KEY (`ID`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci";
ss_assert_same(
	DB_Exporter::schema_sha256( 'CREATE TABLE `wp_posts` ' . $posts_body ),
	DB_Exporter::schema_sha256( 'CREATE TABLE `sb_posts` ' . $posts_body ),
	'table name (prefix) does not change the schema hash'
);
ss_assert_same(
	DB_Exporter::schema_sha256( 'CREATE TABLE `wp_posts` ' . $posts_body ),
	DB_Exporter::schema_sha256( 'CREATE TABLE `ssx_1ab2_sb_posts` ' . $posts_body ),
	'working (shadow) table name does not change the schema hash'
);

// MySQL 8 is not round-trip stable: SHOW CREATE TABLE on a dbDelta-created
// table prints `text COLLATE x`, and re-executing that statement yields a
// table whose SHOW CREATE TABLE prints `text CHARACTER SET utf8mb4 COLLATE x`.
// Both describe the same column because the table default charset is utf8mb4.
$mysql_source = "CREATE TABLE `wp_posts` (\n  `ID` bigint unsigned NOT NULL AUTO_INCREMENT,\n  `post_content` longtext COLLATE utf8mb4_unicode_520_ci NOT NULL,\n  `post_status` varchar(20) COLLATE utf8mb4_unicode_520_ci NOT NULL DEFAULT 'publish',\n  PRIMARY KEY (`ID`)\n) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci";
$mysql_shadow = "CREATE TABLE `wp_posts` (\n  `ID` bigint unsigned NOT NULL AUTO_INCREMENT,\n  `post_content` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,\n  `post_status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL DEFAULT 'publish',\n  PRIMARY KEY (`ID`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci";
ss_assert_same(
	DB_Exporter::schema_sha256( $mysql_source ),
	DB_Exporter::schema_sha256( $mysql_shadow ),
	'column CHARACTER SET equal to the table default charset is not a schema difference'
);

// MariaDB omits the column collation entirely when it equals the table
// collation; that form must hash the same as the explicit MySQL spellings.
$maria_source = "CREATE TABLE `wp_posts` (\n  `ID` bigint unsigned NOT NULL AUTO_INCREMENT,\n  `post_content` longtext NOT NULL,\n  `post_status` varchar(20) NOT NULL DEFAULT 'publish',\n  PRIMARY KEY (`ID`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci";
ss_assert_same(
	DB_Exporter::schema_sha256( $maria_source ),
	DB_Exporter::schema_sha256( $mysql_shadow ),
	'column COLLATE equal to the explicit table collation is not a schema difference'
);

// Real differences still change the hash.
$other_charset = str_replace( '`post_content` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci', '`post_content` longtext CHARACTER SET latin1 COLLATE latin1_swedish_ci', $mysql_shadow );
ss_assert(
	DB_Exporter::schema_sha256( $mysql_shadow ) !== DB_Exporter::schema_sha256( $other_charset ),
	'a column whose charset differs from the table default still changes the hash'
);
$other_collation = str_replace( '`post_status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci', '`post_status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin', $mysql_shadow );
ss_assert(
	DB_Exporter::schema_sha256( $mysql_shadow ) !== DB_Exporter::schema_sha256( $other_collation ),
	'a column whose collation differs from the table collation still changes the hash'
);

// Without an explicit table COLLATE=, the charset default collation differs
// between servers (utf8mb4_0900_ai_ci vs utf8mb4_general_ci), so a column
// collation is meaningful and must be kept.
$no_table_collate = "CREATE TABLE `wp_x` (\n  `a` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
ss_assert(
	false !== strpos( DB_Exporter::normalize_create_sql( $no_table_collate ), 'COLLATE utf8mb4_unicode_ci' ),
	'column COLLATE is kept when the table declares no explicit collation'
);
