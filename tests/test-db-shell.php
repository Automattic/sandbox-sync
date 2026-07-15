<?php
/**
 * Db_Shell tests: opt-in gating, DB_HOST parsing, command construction
 * (credentials never in argv), INSERT rewriting, preamble validation and
 * binary discovery.
 */

use SandboxSync\Db_Shell;
use SandboxSync\Db_Shell_Dump_Reader;

ss_test_section( 'Db_Shell: opt-in gating' );

ss_assert_same( false, Db_Shell::enabled(), 'shell fast path is off by default' );

$GLOBALS['ss_test_filters']['sandbox_sync_allow_shell_db'] = function () {
	return true;
};
ss_assert_same( true, Db_Shell::enabled(), 'filter can opt in' );
$GLOBALS['ss_test_filters']['sandbox_sync_allow_shell_db'] = function () {
	return false;
};
ss_assert_same( false, Db_Shell::enabled(), 'filter can force off' );
unset( $GLOBALS['ss_test_filters']['sandbox_sync_allow_shell_db'] );

ss_assert_same( false, Db_Shell::source_available(), 'source unavailable while disabled' );
ss_assert_same( false, Db_Shell::target_available(), 'target unavailable while disabled' );

ss_test_section( 'Db_Shell: DB_HOST parsing' );

ss_assert_same(
	array( 'host' => 'localhost', 'port' => null, 'socket' => null ),
	Db_Shell::parse_db_host( 'localhost' ),
	'plain host'
);
ss_assert_same(
	array( 'host' => 'db.example.com', 'port' => 3307, 'socket' => null ),
	Db_Shell::parse_db_host( 'db.example.com:3307' ),
	'host:port'
);
ss_assert_same(
	array( 'host' => 'localhost', 'port' => null, 'socket' => '/var/run/mysqld/mysqld.sock' ),
	Db_Shell::parse_db_host( 'localhost:/var/run/mysqld/mysqld.sock' ),
	'host:socket'
);
ss_assert_same(
	array( 'host' => 'localhost', 'port' => null, 'socket' => '/tmp/mysql.sock' ),
	Db_Shell::parse_db_host( ':/tmp/mysql.sock' ),
	'bare socket with empty host defaults host to localhost'
);
ss_assert_same(
	array( 'host' => '::1', 'port' => null, 'socket' => null ),
	Db_Shell::parse_db_host( '::1' ),
	'IPv6 literal stays a plain host'
);
ss_assert_same(
	array( 'host' => 'localhost', 'port' => null, 'socket' => null ),
	Db_Shell::parse_db_host( '' ),
	'empty DB_HOST defaults to localhost'
);

ss_test_section( 'Db_Shell: command construction' );

$dump_args = Db_Shell::mysqldump_args(
	'/usr/bin/mysqldump',
	'wp_posts',
	'wordpress',
	'wp_user',
	Db_Shell::parse_db_host( 'db.example.com:3307' ),
	'utf8mb4'
);
ss_assert_same( '/usr/bin/mysqldump', $dump_args[0], 'dump binary is argv[0]' );
ss_assert_same( '--no-defaults', $dump_args[1], '--no-defaults is the first option so config files cannot inject options' );
ss_assert( in_array( '--single-transaction', $dump_args, true ), 'dump uses a consistent snapshot' );
ss_assert( in_array( '--no-create-info', $dump_args, true ), 'dump is data-only (schema travels via the verified probe)' );
ss_assert( in_array( '--hex-blob', $dump_args, true ), 'binary columns travel as hex' );
ss_assert( in_array( '--host=db.example.com', $dump_args, true ), 'dump host from DB_HOST' );
ss_assert( in_array( '--port=3307', $dump_args, true ), 'dump port from DB_HOST' );
ss_assert( in_array( '--protocol=TCP', $dump_args, true ), 'explicit TCP when a port is given' );
ss_assert_same( 'wp_posts', end( $dump_args ), 'table is the final argv element' );
foreach ( $dump_args as $arg ) {
	ss_assert( false === stripos( $arg, 'password' ) && ! preg_match( '/^-p/', $arg ), "no credential material in argv: {$arg}" );
}

$socket_args = Db_Shell::mysqldump_args(
	'/usr/bin/mysqldump',
	'wp_posts',
	'wordpress',
	'wp_user',
	Db_Shell::parse_db_host( 'localhost:/tmp/mysql.sock' ),
	''
);
ss_assert( in_array( '--socket=/tmp/mysql.sock', $socket_args, true ), 'socket form uses --socket' );
ss_assert( ! in_array( '--host=localhost', $socket_args, true ), 'socket form omits --host' );

$mysql_args = Db_Shell::mysql_args(
	'/usr/bin/mysql',
	'sandbox_db',
	'sb_user',
	Db_Shell::parse_db_host( 'localhost' ),
	'utf8mb4'
);
ss_assert_same( '--no-defaults', $mysql_args[1], 'mysql --no-defaults first' );
ss_assert( in_array( '--init-command=SET SESSION unique_checks=0, foreign_key_checks=0', $mysql_args, true ), 'import keeps bulk-load session settings' );
ss_assert_same( 'sandbox_db', end( $mysql_args ), 'database is the final mysql argv element' );

ss_test_section( 'Db_Shell: INSERT rewrite' );

$line = "INSERT INTO `wp_posts` VALUES (1,'a'),(2,'b');\n";
ss_assert_same(
	"INSERT INTO `ssx_shadow_wp_posts` VALUES (1,'a'),(2,'b');\n",
	Db_Shell::rewrite_insert_line( $line, 'wp_posts', 'ssx_shadow_wp_posts' ),
	'INSERT head is rewritten to the shadow table'
);
ss_assert_same(
	null,
	Db_Shell::rewrite_insert_line( "INSERT INTO `wp_users` VALUES (1);\n", 'wp_posts', 'ssx' ),
	'INSERT for a different table is not rewritten'
);
ss_assert_same(
	null,
	Db_Shell::rewrite_insert_line( "DROP TABLE `wp_posts`;\n", 'wp_posts', 'ssx' ),
	'non-INSERT statements are never rewritten'
);
ss_assert_same(
	null,
	Db_Shell::rewrite_insert_line( "INSERT INTO `wp_posts_evil` VALUES (1);\n", 'wp_posts', 'ssx' ),
	'prefix-similar table name does not match (backtick anchors)'
);
// A malicious payload embedding the head mid-line must not be rewritten.
ss_assert_same(
	null,
	Db_Shell::rewrite_insert_line( " INSERT INTO `wp_posts` VALUES (1);\n", 'wp_posts', 'ssx' ),
	'leading whitespace breaks the anchor (fails closed)'
);

ss_test_section( 'Db_Shell: preamble validation' );

ss_assert_same( true, Db_Shell::is_allowed_preamble_line( "\n" ), 'blank line allowed' );
ss_assert_same( true, Db_Shell::is_allowed_preamble_line( "/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;\n" ), 'conditional SET allowed' );
ss_assert_same( true, Db_Shell::is_allowed_preamble_line( "/*!40103 SET TIME_ZONE='+00:00' */;\n" ), 'conditional TIME_ZONE SET allowed' );
ss_assert_same( true, Db_Shell::is_allowed_preamble_line( "SET NAMES utf8mb4;\n" ), 'plain SET allowed' );
ss_assert_same( true, Db_Shell::is_allowed_preamble_line( "/*M!100616 SET @OLD_NOTE_VERBOSITY=@@NOTE_VERBOSITY, NOTE_VERBOSITY=0 */;\n" ), 'MariaDB 6-digit conditional SET allowed' );
ss_assert_same( true, Db_Shell::is_allowed_preamble_line( "/*M!999999\\- enable the sandbox mode */ \n" ), 'MariaDB sandbox-mode marker allowed (no semicolon, trailing space)' );
ss_assert_same( false, Db_Shell::is_allowed_preamble_line( "/*M!999999\\- enable the sandbox mode */ DROP TABLE x;\n" ), 'sandbox marker with a payload appended is rejected' );
ss_assert_same( false, Db_Shell::is_allowed_preamble_line( "DROP TABLE `wp_posts`;\n" ), 'DROP rejected' );
ss_assert_same( false, Db_Shell::is_allowed_preamble_line( "LOCK TABLES `wp_posts` WRITE;\n" ), 'LOCK TABLES rejected' );
ss_assert_same( false, Db_Shell::is_allowed_preamble_line( "/*!50003 CREATE TRIGGER bad */;\n" ), 'conditional non-SET rejected' );
ss_assert_same( false, Db_Shell::is_allowed_preamble_line( "-- comment\n" ), 'comment lines rejected (skip-comments dump has none)' );
ss_assert_same( true, Db_Shell::is_allowed_preamble_line( "set autocommit=0;\n" ), 'MariaDB per-table autocommit wrapper allowed' );
ss_assert_same( true, Db_Shell::is_allowed_preamble_line( "commit;\n" ), 'MariaDB per-table commit allowed' );

ss_test_section( 'Db_Shell: statement boundaries (MariaDB multi-line INSERTs)' );

ss_assert_same( false, Db_Shell::line_terminates_statement( "INSERT INTO `wp_posts` VALUES\n" ), 'INSERT head line without ; keeps the statement open' );
ss_assert_same( false, Db_Shell::line_terminates_statement( "(5,'a\\n;b'),\n" ), 'row tuple line ending in a comma keeps the statement open' );
ss_assert_same( true, Db_Shell::line_terminates_statement( "(6,'z');\n" ), 'row tuple line ending in ; terminates the statement' );
ss_assert_same( true, Db_Shell::line_terminates_statement( "INSERT INTO `wp_posts` VALUES (1);\n" ), 'single-line INSERT terminates immediately' );

ss_test_section( 'Db_Shell: binary discovery' );

$bin_dir = sys_get_temp_dir() . '/ss-db-shell-bin-' . getmypid();
ss_test_rmrf( $bin_dir );
mkdir( $bin_dir, 0700, true );
file_put_contents( $bin_dir . '/mysqldump', "#!/bin/sh\n" );
chmod( $bin_dir . '/mysqldump', 0755 );
file_put_contents( $bin_dir . '/notexec', '' );
chmod( $bin_dir . '/notexec', 0644 );

ss_assert_same( $bin_dir . '/mysqldump', Db_Shell::find_binary( 'mysqldump', $bin_dir ), 'finds executable on the given PATH' );
ss_assert_same( null, Db_Shell::find_binary( 'notexec', $bin_dir ), 'non-executable file is not a binary' );
ss_assert_same( null, Db_Shell::find_binary( 'missing-binary-name', $bin_dir ), 'missing binary returns null' );
ss_assert_same( null, Db_Shell::find_binary( '../etc/passwd', $bin_dir ), 'path traversal in binary name is rejected' );
ss_assert_same( null, Db_Shell::find_binary( 'evil name', $bin_dir ), 'binary name with spaces is rejected' );
ss_test_rmrf( $bin_dir );

ss_test_section( 'Db_Shell: trailer constant' );

ss_assert_same( 0, strpos( Db_Shell::TRAILER_PREFIX, '-- ' ), 'trailer is a SQL comment, harmless if it ever reached mysql' );

ss_test_section( 'Db_Shell: GTID and scope hardening' );

$gtid_args = Db_Shell::mysqldump_args( '/usr/bin/mysqldump', 'wp_posts', 'db', 'u', Db_Shell::parse_db_host( 'localhost' ), '', true );
ss_assert( in_array( '--set-gtid-purged=OFF', $gtid_args, true ), 'MySQL-family dumps suppress GTID_PURGED at the source' );
$no_gtid_args = Db_Shell::mysqldump_args( '/usr/bin/mysqldump', 'wp_posts', 'db', 'u', Db_Shell::parse_db_host( 'localhost' ), '', false );
ss_assert( ! in_array( '--set-gtid-purged=OFF', $no_gtid_args, true ), 'MariaDB dumps omit the unknown GTID option' );

ss_assert_same( false, Db_Shell::is_allowed_preamble_line( "SET @@GLOBAL.GTID_PURGED='uuid:1-5';\n" ), 'GLOBAL-scope SET rejected' );
ss_assert_same( false, Db_Shell::is_allowed_preamble_line( "SET @@GLOBAL.GTID_PURGED=/*!80000 '+'*/ 'uuid:1-5';\n" ), 'GLOBAL-scope SET with embedded conditional rejected' );
ss_assert_same( false, Db_Shell::is_allowed_preamble_line( "/*!80000 SET GLOBAL innodb_stats_auto_recalc=0 */;\n" ), 'GLOBAL inside a conditional comment rejected' );
ss_assert_same( false, Db_Shell::is_allowed_preamble_line( "SET PERSIST max_connections=1000;\n" ), 'PERSIST-scope SET rejected' );

ss_test_section( 'Db_Shell: single-statement enforcement (anti-smuggling)' );

ss_assert_same( true, Db_Shell::line_is_single_statement( "INSERT INTO `ssx_t` VALUES (1,'a'),(2,'b');\n" ), 'plain INSERT passes' );
ss_assert_same( true, Db_Shell::line_is_single_statement( "INSERT INTO `ssx_t` VALUES (1,'a:1:{s:3:\\\"foo\\\";i:1;}');\n" ), 'semicolons inside serialized PHP strings are fine' );
ss_assert_same( true, Db_Shell::line_is_single_statement( "(3,'x; DROP TABLE `wp_users`;'),\n" ), 'attack-shaped text inside a string literal is fine' );
ss_assert_same( true, Db_Shell::line_is_single_statement( "(4,'ends with backslash \\\\'),\n" ), 'escaped backslash before closing quote handled' );
ss_assert_same( true, Db_Shell::line_is_single_statement( "(5,'quote \\' semi ; here');\n" ), 'escaped quote inside string handled' );
ss_assert_same( true, Db_Shell::line_is_single_statement( "\n" ), 'blank line passes' );
ss_assert_same( false, Db_Shell::line_is_single_statement( "INSERT INTO `ssx_t` VALUES (1); DROP TABLE `wp_users`;\n" ), 'second statement after INSERT rejected' );
ss_assert_same( false, Db_Shell::line_is_single_statement( "(1,'a'); DELETE FROM `wp_options`;\n" ), 'second statement after tuple rejected' );
ss_assert_same( false, Db_Shell::line_is_single_statement( "SET NAMES utf8mb4; DROP TABLE x;\n" ), 'second statement after SET rejected' );

ss_test_section( 'Db_Shell_Dump_Reader: wire integrity' );

/**
 * Builds the exact wire format the source emits: gzip(payload + trailer).
 * $mutate lets tests corrupt specific aspects.
 */
function ss_test_dump_wire( $payload, $trailer_hash = null, $suffix = '' ) {
	$hash = null === $trailer_hash ? hash( 'sha256', $payload ) : $trailer_hash;
	return gzencode( $payload . SandboxSync\Db_Shell::TRAILER_PREFIX . $hash . "\n" . $suffix, 1 );
}

function ss_test_new_reader( &$lines, $max_line = 67108864, $max_bytes = 1073741824 ) {
	$lines = array();
	return new Db_Shell_Dump_Reader(
		'wp_posts',
		function ( $line ) use ( &$lines ) {
			$lines[] = $line;
			return true;
		},
		$max_line,
		$max_bytes
	);
}

// Happy path, fed one byte at a time to exercise incremental inflation.
$payload = "SET NAMES utf8mb4;\nINSERT INTO `wp_posts` VALUES (1,'a;b');\n";
$wire    = ss_test_dump_wire( $payload );
$reader  = ss_test_new_reader( $lines );
$feed_ok = true;
foreach ( str_split( $wire, 3 ) as $piece ) {
	$outcome = $reader->feed( $piece );
	$feed_ok = $feed_ok && true === $outcome;
}
ss_assert( $feed_ok, 'reader accepts a valid stream fed in tiny pieces' );
$result = $reader->finish();
ss_assert( ! is_wp_error( $result ), 'valid stream finishes cleanly' );
ss_assert_same( strlen( $payload ), is_array( $result ) ? $result['bytes'] : -1, 'payload byte count excludes the trailer' );
ss_assert_same( array( "SET NAMES utf8mb4;\n", "INSERT INTO `wp_posts` VALUES (1,'a;b');\n" ), $lines, 'lines delivered intact and in order' );

// Truncated gzip: valid deflate data but the stream never reaches its end.
$reader  = ss_test_new_reader( $lines );
$reader->feed( substr( $wire, 0, strlen( $wire ) - 5 ) );
$result = $reader->finish();
ss_assert( is_wp_error( $result ) && 'shell_dump_gzip' === $result->get_error_code(), 'truncated gzip footer is rejected at finish' );

// Garbage compressed bytes.
$reader = ss_test_new_reader( $lines );
$result = $reader->feed( 'this is not gzip at all' );
ss_assert( is_wp_error( $result ) && 'shell_dump_gzip' === $result->get_error_code(), 'non-gzip data is rejected' );

// Compressed bytes after the gzip stream ended.
$reader = ss_test_new_reader( $lines );
$reader->feed( $wire );
$result = $reader->feed( $wire );
ss_assert( is_wp_error( $result ) && 'shell_dump_gzip' === $result->get_error_code(), 'a second gzip stream after the end is rejected' );

// Missing trailer entirely.
$reader = ss_test_new_reader( $lines );
$reader->feed( gzencode( $payload, 1 ) );
$result = $reader->finish();
ss_assert( is_wp_error( $result ) && 'shell_dump_truncated' === $result->get_error_code(), 'stream without a trailer is rejected' );

// Wrong hash in the trailer.
$reader = ss_test_new_reader( $lines );
$reader->feed( ss_test_dump_wire( $payload, str_repeat( '0', 64 ) ) );
$result = $reader->finish();
ss_assert( is_wp_error( $result ) && 'shell_dump_hash' === $result->get_error_code(), 'hash mismatch is rejected' );

// Malformed trailer: garbage appended to the trailer line itself.
$reader = ss_test_new_reader( $lines );
$reader->feed( gzencode( $payload . SandboxSync\Db_Shell::TRAILER_PREFIX . hash( 'sha256', $payload ) . " extra\n", 1 ) );
$result = $reader->finish();
ss_assert( is_wp_error( $result ) && 'shell_dump_trailer' === $result->get_error_code(), 'trailer with trailing garbage is rejected (exact match)' );

// Data after the trailer.
$reader = ss_test_new_reader( $lines );
$result = $reader->feed( ss_test_dump_wire( $payload, null, "DROP TABLE `wp_users`;\n" ) );
if ( ! is_wp_error( $result ) ) {
	$result = $reader->finish();
}
ss_assert( is_wp_error( $result ) && 'shell_dump_trailer' === $result->get_error_code(), 'data after the trailer is rejected' );

// A second trailer.
$reader = ss_test_new_reader( $lines );
$result = $reader->feed( ss_test_dump_wire( $payload, null, SandboxSync\Db_Shell::TRAILER_PREFIX . hash( 'sha256', $payload ) . "\n" ) );
if ( ! is_wp_error( $result ) ) {
	$result = $reader->finish();
}
ss_assert( is_wp_error( $result ) && 'shell_dump_trailer' === $result->get_error_code(), 'a second trailer is rejected' );

// Per-line ceiling applies to terminated lines too (bypass fix): a line WITH
// a newline that exceeds the cap must be rejected, not silently processed.
$reader = ss_test_new_reader( $lines, 65536 );
$long   = "INSERT INTO `wp_posts` VALUES (1,'" . str_repeat( 'x', 70000 ) . "');\n";
$result = $reader->feed( ss_test_dump_wire( $long ) );
ss_assert( is_wp_error( $result ) && 'shell_dump_line' === $result->get_error_code(), 'newline-terminated oversized line is rejected' );

// Total decompressed ceiling.
$reader = ss_test_new_reader( $lines, 67108864, 1048576 );
$big    = str_repeat( "INSERT INTO `wp_posts` VALUES (1,'" . str_repeat( 'y', 1000 ) . "');\n", 1200 );
$result = $reader->feed( ss_test_dump_wire( $big ) );
ss_assert( is_wp_error( $result ) && 'shell_dump_too_large' === $result->get_error_code(), 'decompressed transfer ceiling is enforced' );

// Sink errors propagate and are sticky.
$reader = new Db_Shell_Dump_Reader(
	'wp_posts',
	function () {
		return new WP_Error( 'sink_says_no', 'nope' );
	},
	67108864,
	1073741824
);
$result = $reader->feed( $wire );
ss_assert( is_wp_error( $result ) && 'sink_says_no' === $result->get_error_code(), 'sink errors abort the stream' );
$again = $reader->feed( 'x' );
ss_assert( is_wp_error( $again ) && 'sink_says_no' === $again->get_error_code(), 'reader errors are sticky' );
