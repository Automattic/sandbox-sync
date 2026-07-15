<?php
/**
 * Opportunistic shell fast path for database transfer.
 *
 * When BOTH sites opt in (SANDBOX_SYNC_ALLOW_SHELL_DB constant or the
 * sandbox_sync_allow_shell_db filter) and the binaries exist, a table travels
 * as one continuous `mysqldump` stream from the source into a `mysql` client
 * process on the sandbox, instead of thousands of PHP-escaped JSON chunks.
 *
 * Security posture (see docs/THREAT-MODEL.md):
 * - The feature is off by default and requires an explicit per-site opt-in.
 * - The only request-derived value near a command line is a table name that
 *   must match ^[A-Za-z0-9_]+$ AND the source's own transfer-scoped table
 *   list; it is additionally escapeshellarg-quoted.
 * - Credentials travel via the MYSQL_PWD environment variable, never argv
 *   (argv is world-readable through /proc on shared hosts).
 * - Both commands run with --no-defaults so ~/.my.cnf cannot inject options.
 * - The dump stream carries a SHA-256 trailer computed on the source; the
 *   sandbox refuses to finalize an import whose received bytes do not match.
 */

namespace SandboxSync;

defined( 'ABSPATH' ) || exit;

class Db_Shell {

	// Emitted by the source after mysqldump exits 0; consumed (never imported)
	// by the sandbox. A missing or mismatched trailer fails the import closed.
	const TRAILER_PREFIX = '-- SANDBOX_SYNC_DUMP_COMPLETE sha256:';

	// Bytes streamed from mysqldump per read; also the deflate flush cadence.
	const READ_CHUNK = 524288;

	// Only tables at least this large (data+index from the manifest) use the
	// stream: below it, the fixed cost of the extra schema probe request, the
	// dump request and two process spawns exceeds what the stream saves over
	// one or two chunked requests.
	const MIN_TABLE_BYTES = 16777216; // 16 MiB.

	/** Manifest size threshold for choosing the stream over chunks. */
	public static function min_table_bytes() {
		$default = defined( 'SANDBOX_SYNC_SHELL_DB_MIN_BYTES' ) ? (int) SANDBOX_SYNC_SHELL_DB_MIN_BYTES : self::MIN_TABLE_BYTES;
		return max( 0, (int) apply_filters( 'sandbox_sync_shell_db_min_bytes', $default ) );
	}

	/** Explicit per-site opt-in: constant first, filter can override. */
	public static function enabled() {
		$default = defined( 'SANDBOX_SYNC_ALLOW_SHELL_DB' ) && SANDBOX_SYNC_ALLOW_SHELL_DB;
		return (bool) apply_filters( 'sandbox_sync_allow_shell_db', $default );
	}

	/** True when $function exists and is not blocked by disable_functions. */
	public static function function_allowed( $function ) {
		if ( ! function_exists( $function ) ) {
			return false;
		}
		foreach ( explode( ',', (string) ini_get( 'disable_functions' ) ) as $disabled ) {
			if ( trim( $disabled ) === $function ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Locates an executable on PATH (plus common system dirs) without shelling
	 * out. Returns the absolute path or null.
	 *
	 * Web SAPIs often run with a minimal PATH that misses the MySQL client
	 * binaries even when they exist (nix/devbox profiles, custom prefixes), so
	 * the search dirs can be extended with the SANDBOX_SYNC_DB_SHELL_PATH
	 * constant (colon-separated) or the sandbox_sync_db_shell_bin_dirs filter.
	 */
	public static function find_binary( $name, $path = null ) {
		if ( ! preg_match( '/^[a-z0-9_.-]+$/', (string) $name ) ) {
			return null;
		}
		$path = null === $path ? (string) getenv( 'PATH' ) : (string) $path;
		$dirs = array_values( array_filter( explode( PATH_SEPARATOR, $path ) ) );
		$extra_dirs = array( '/usr/bin', '/usr/local/bin', '/opt/homebrew/bin' );
		if ( defined( 'SANDBOX_SYNC_DB_SHELL_PATH' ) && is_string( SANDBOX_SYNC_DB_SHELL_PATH ) ) {
			$extra_dirs = array_merge( explode( PATH_SEPARATOR, SANDBOX_SYNC_DB_SHELL_PATH ), $extra_dirs );
		}
		$extra_dirs = apply_filters( 'sandbox_sync_db_shell_bin_dirs', $extra_dirs );
		foreach ( (array) $extra_dirs as $extra ) {
			$extra = (string) $extra;
			if ( '' !== $extra && ! in_array( $extra, $dirs, true ) ) {
				$dirs[] = $extra;
			}
		}
		foreach ( $dirs as $dir ) {
			$candidate = rtrim( $dir, '/' ) . '/' . $name;
			if ( is_file( $candidate ) && is_executable( $candidate ) ) {
				return $candidate;
			}
		}
		return null;
	}

	/** The source side needs proc_open + mysqldump. */
	public static function source_available() {
		return self::enabled()
			&& self::function_allowed( 'proc_open' )
			&& null !== self::find_binary( 'mysqldump' );
	}

	/** The sandbox side needs proc_open + mysql. */
	public static function target_available() {
		return self::enabled()
			&& self::function_allowed( 'proc_open' )
			&& null !== self::find_binary( 'mysql' );
	}

	/**
	 * WordPress DB_HOST syntax: "host", "host:port" or "host:/path/to.sock"
	 * (host may be empty for the socket form). IPv6 literals keep their
	 * colons and are treated as a plain host.
	 *
	 * @return array{host:string,port:?int,socket:?string}
	 */
	public static function parse_db_host( $host ) {
		$host   = (string) $host;
		$port   = null;
		$socket = null;
		$colon  = strpos( $host, ':' );
		if ( false !== $colon ) {
			$rest = substr( $host, $colon + 1 );
			if ( '' !== $rest && '/' === $rest[0] ) {
				$socket = $rest;
				$host   = substr( $host, 0, $colon );
			} elseif ( ctype_digit( $rest ) ) {
				$port = (int) $rest;
				$host = substr( $host, 0, $colon );
			}
			// Anything else (e.g. IPv6 "::1") stays a literal host.
		}
		return array(
			'host'   => '' === $host ? 'localhost' : $host,
			'port'   => $port,
			'socket' => $socket,
		);
	}

	/**
	 * mysqldump argv (no shell, no password). --no-defaults must be first.
	 * Data-only stream: schema travels via the existing verified schema probe.
	 *
	 * @param bool $gtid_off Add --set-gtid-purged=OFF (MySQL-family only:
	 *                       without it a GTID-enabled server emits a
	 *                       SET @@GLOBAL.GTID_PURGED statement the sandbox
	 *                       rightly refuses to execute; MariaDB's mysqldump
	 *                       does not know the option and would error out).
	 * @return string[]
	 */
	public static function mysqldump_args( $binary, $table, $database, $user, $host_info, $charset = '', $gtid_off = false ) {
		$args = array(
			$binary,
			'--no-defaults',
			'--user=' . $user,
			'--single-transaction',
			'--quick',
			'--no-create-info',
			'--skip-triggers',
			'--skip-comments',
			'--skip-dump-date',
			'--skip-add-locks',
			'--skip-disable-keys',
			'--no-tablespaces',
			'--hex-blob',
		);
		if ( $gtid_off ) {
			$args[] = '--set-gtid-purged=OFF';
		}
		if ( '' !== (string) $charset ) {
			$args[] = '--default-character-set=' . $charset;
		}
		if ( ! empty( $host_info['socket'] ) ) {
			$args[] = '--socket=' . $host_info['socket'];
		} else {
			$args[] = '--host=' . $host_info['host'];
			if ( ! empty( $host_info['port'] ) ) {
				$args[] = '--port=' . (int) $host_info['port'];
				$args[] = '--protocol=TCP';
			}
		}
		$args[] = $database;
		$args[] = $table;
		return $args;
	}

	/**
	 * mysql import argv (no shell, no password). The init-command keeps the
	 * bulk-load behavior of the chunked path; mysql aborts on the first
	 * failed statement when non-interactive, which fails a bad stream closed.
	 *
	 * @return string[]
	 */
	public static function mysql_args( $binary, $database, $user, $host_info, $charset = '' ) {
		$args = array(
			$binary,
			'--no-defaults',
			'--user=' . $user,
			'--batch',
			'--init-command=SET SESSION unique_checks=0, foreign_key_checks=0',
		);
		if ( '' !== (string) $charset ) {
			$args[] = '--default-character-set=' . $charset;
		}
		if ( ! empty( $host_info['socket'] ) ) {
			$args[] = '--socket=' . $host_info['socket'];
		} else {
			$args[] = '--host=' . $host_info['host'];
			if ( ! empty( $host_info['port'] ) ) {
				$args[] = '--port=' . (int) $host_info['port'];
				$args[] = '--protocol=TCP';
			}
		}
		$args[] = $database;
		return $args;
	}

	/**
	 * Rewrites a dump line addressed to the source table so it lands in the
	 * sandbox's shadow table. Only INSERT lines matching the exact anchored
	 * head are rewritten — the same anchor contract as the chunked importer.
	 *
	 * @return string|null Rewritten line, or null when the line is not an
	 *                     INSERT for $table.
	 */
	public static function rewrite_insert_line( $line, $table, $shadow ) {
		$head = 'INSERT INTO `' . $table . '` ';
		if ( 0 !== strncmp( $line, $head, strlen( $head ) ) ) {
			return null;
		}
		return 'INSERT INTO `' . $shadow . '` ' . substr( $line, strlen( $head ) );
	}

	/**
	 * True for the only non-INSERT top-level lines a --skip-comments
	 * --no-create-info mysqldump stream legitimately contains: versioned
	 * conditional SET comments, plain SET statements, MariaDB's per-table
	 * autocommit/commit wrapper, the MariaDB 11 sandbox-mode marker, and
	 * blank lines. Anything else fails the import closed (and the sync falls
	 * back to the chunked path).
	 */
	public static function is_allowed_preamble_line( $line ) {
		$trimmed = trim( $line );
		if ( '' === $trimmed ) {
			return true;
		}
		// Nothing with GLOBAL/PERSIST scope is ever forwarded, including the
		// conditional-comment form (SET @@GLOBAL.GTID_PURGED=/*!80000 ...*/).
		if ( preg_match( '/\b(GLOBAL|PERSIST)\b/i', $trimmed ) ) {
			return false;
		}
		// Versioned conditional SETs: /*!40101 SET ... */; — MariaDB also emits
		// /*M!100616 SET ... */; with a 6-digit version.
		if ( preg_match( '#^/\*M?![0-9]{5,6} SET [^;]{1,4096}\*/;$#', $trimmed ) ) {
			return true;
		}
		// MariaDB 11 mysqldump always starts with this sandbox-mode marker; it
		// only restricts what a mariadb client may execute (no \! commands).
		if ( preg_match( '#^/\*M!999999\x5c- enable the sandbox mode \*/;?$#', $trimmed ) ) {
			return true;
		}
		// MariaDB wraps each table's rows in set autocommit=0; ... commit;
		// (lowercase). One transaction per table also matches the import model.
		if ( preg_match( '/^(commit|begin|start transaction);$/i', $trimmed ) ) {
			return true;
		}
		return (bool) preg_match( '/^SET\s[^;]{1,4096};$/i', $trimmed );
	}

	/**
	 * True when $line contains exactly one SQL statement — i.e. no ";"
	 * appears outside a string literal except as the final character.
	 *
	 * This closes the smuggling hole where a line shaped like
	 * "INSERT INTO `t` VALUES (1); DROP TABLE `wp_users`;" passes the
	 * anchored-head rewrite but executes a second statement against a real
	 * table. String literals are stripped first (mysqldump escapes quotes
	 * and never emits raw newlines, so the possessive pattern below is
	 * linear); any PCRE failure fails closed.
	 */
	public static function line_is_single_statement( $line ) {
		$stripped = preg_replace( "/'(?:[^'\\\\]++|\\\\.)*+'/s", "''", $line );
		if ( null === $stripped ) {
			return false;
		}
		$stripped = rtrim( $stripped );
		if ( '' === $stripped ) {
			return true;
		}
		if ( ';' === substr( $stripped, -1 ) ) {
			$stripped = substr( $stripped, 0, -1 );
		}
		return false === strpos( $stripped, ';' );
	}

	/**
	 * True when this dump line ends the current SQL statement. mysqldump
	 * never emits a literal newline inside a string (it escapes them), so a
	 * statement's final line — and only its final line — ends with ";".
	 */
	public static function line_terminates_statement( $line ) {
		return ';' === substr( rtrim( $line ), -1 );
	}

	/**
	 * Spawns the sandbox-side mysql import process.
	 *
	 * @return array|\WP_Error ['write' => closure(string): true|WP_Error,
	 *                          'finish' => closure(): true|WP_Error,
	 *                          'abort' => closure(): void]
	 */
	public static function open_mysql_import() {
		global $wpdb;
		if ( ! self::target_available() ) {
			return new \WP_Error( 'shell_dump_unavailable', 'The mysql client fast path is not available on this sandbox.' );
		}
		$binary = self::find_binary( 'mysql' );
		$args   = self::mysql_args(
			$binary,
			DB_NAME,
			DB_USER,
			self::parse_db_host( DB_HOST ),
			is_object( $wpdb ) && ! empty( $wpdb->charset ) ? $wpdb->charset : ''
		);
		$pipes  = array();
		$proc   = proc_open(
			$args,
			array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'file', '/dev/null', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes,
			null,
			array(
				'MYSQL_PWD' => DB_PASSWORD,
				'PATH'      => (string) getenv( 'PATH' ),
			)
		);
		if ( ! is_resource( $proc ) ) {
			return new \WP_Error( 'shell_import_spawn', 'Could not start the mysql import process.' );
		}
		// Nonblocking pipes: a stalled mysql (or a full stderr buffer) must
		// surface as a bounded error, never freeze this worker past its lease.
		stream_set_blocking( $pipes[0], false );
		stream_set_blocking( $pipes[2], false );
		$stderr = '';

		$drain_stderr = function () use ( &$pipes, &$stderr ) {
			$data = is_resource( $pipes[2] ) ? stream_get_contents( $pipes[2] ) : '';
			if ( is_string( $data ) && '' !== $data ) {
				$stderr = substr( $stderr . $data, -4096 );
			}
		};

		$stall_limit = max( 30, (int) apply_filters( 'sandbox_sync_shell_import_stall_seconds', 120 ) );

		return array(
			'write'  => function ( $sql ) use ( &$proc, &$pipes, $drain_stderr, $stall_limit ) {
				$total         = strlen( $sql );
				$sent          = 0;
				$last_progress = microtime( true );
				while ( $sent < $total ) {
					$written = @fwrite( $pipes[0], substr( $sql, $sent, 262144 ) );
					$drain_stderr();
					if ( is_int( $written ) && $written > 0 ) {
						$sent         += $written;
						$last_progress = microtime( true );
						continue;
					}
					if ( false === $written ) {
						return new \WP_Error( 'shell_import_write', 'The mysql import process stopped accepting data.' );
					}
					$status = proc_get_status( $proc );
					if ( empty( $status['running'] ) ) {
						return new \WP_Error( 'shell_import_write', 'The mysql import process exited mid-stream.' );
					}
					if ( microtime( true ) - $last_progress > $stall_limit ) {
						return new \WP_Error( 'shell_import_stalled', sprintf( 'The mysql import process accepted no data for %d seconds.', $stall_limit ) );
					}
					$write  = array( $pipes[0] );
					$read   = array( $pipes[2] );
					$except = null;
					@stream_select( $read, $write, $except, 1 );
				}
				return true;
			},
			'finish' => function () use ( &$proc, &$pipes, $drain_stderr, &$stderr ) {
				if ( is_resource( $pipes[0] ) ) {
					fclose( $pipes[0] );
				}
				$drain_stderr();
				if ( is_resource( $pipes[2] ) ) {
					stream_set_blocking( $pipes[2], true );
					$tail = stream_get_contents( $pipes[2] );
					if ( is_string( $tail ) && '' !== $tail ) {
						$stderr = substr( $stderr . $tail, -4096 );
					}
					fclose( $pipes[2] );
				}
				$exit = proc_close( $proc );
				if ( 0 !== $exit ) {
					return new \WP_Error( 'shell_import_failed', sprintf( 'mysql import exited with code %d: %s', $exit, trim( $stderr ) ) );
				}
				return true;
			},
			'abort'  => function () use ( &$proc, &$pipes ) {
				foreach ( $pipes as $pipe ) {
					if ( is_resource( $pipe ) ) {
						@fclose( $pipe );
					}
				}
				if ( is_resource( $proc ) ) {
					@proc_terminate( $proc );
					@proc_close( $proc );
				}
			},
		);
	}
}
