<?php
/**
 * Incremental reader for the shell fast path's gzip'd mysqldump stream.
 *
 * Owns every wire-integrity rule in one testable place:
 * - incremental gzip inflation that must reach a clean ZLIB_STREAM_END
 *   (a truncated gzip footer can still inflate completely, so "it inflated"
 *   is not proof of completeness), with no compressed bytes after the end;
 * - a per-line size ceiling enforced for every extracted line (not only for
 *   buffered partials) and a total decompressed-byte ceiling;
 * - exactly one completion trailer, matched against the full line, with no
 *   data lines after it;
 * - a SHA-256 over every payload byte that must equal the trailer hash.
 *
 * The caller supplies an $on_line sink receiving each newline-terminated SQL
 * line while the stream is still in flight.
 */

namespace SandboxSync;

defined( 'ABSPATH' ) || exit;

class Db_Shell_Dump_Reader {

	private $table;
	private $on_line;
	private $max_line;
	private $max_bytes;

	private $inflate;
	private $hash;
	private $buffer         = '';
	private $raw_bytes      = 0;
	private $lines          = 0;
	private $trailer_hash   = null;
	private $gzip_ended     = false;
	private $error          = null;

	/**
	 * @param string   $table     Source table name (for error messages).
	 * @param callable $on_line   fn( string $line_with_newline ): true|WP_Error.
	 * @param int      $max_line  Per-line byte ceiling.
	 * @param int      $max_bytes Total decompressed byte ceiling.
	 */
	public function __construct( $table, $on_line, $max_line, $max_bytes ) {
		$this->table     = (string) $table;
		$this->on_line   = $on_line;
		$this->max_line  = max( 65536, (int) $max_line );
		$this->max_bytes = max( 1048576, (int) $max_bytes );
		$this->hash      = hash_init( 'sha256' );
		if ( function_exists( 'inflate_init' ) ) {
			$this->inflate = inflate_init( ZLIB_ENCODING_GZIP );
		}
		if ( ! $this->inflate ) {
			$this->error = new \WP_Error( 'shell_dump_unavailable', 'Incremental gzip decoding is unavailable on this sandbox.' );
		}
	}

	/**
	 * Feeds compressed wire bytes. Returns true or the first fatal WP_Error
	 * (which is sticky: further calls keep returning it).
	 */
	public function feed( $data ) {
		if ( is_wp_error( $this->error ) ) {
			return $this->error;
		}
		if ( '' === $data ) {
			return true;
		}
		if ( $this->gzip_ended ) {
			return $this->fail( 'shell_dump_gzip', "Compressed data continued after the {$this->table} dump's gzip stream ended." );
		}
		$inflated = @inflate_add( $this->inflate, $data );
		if ( false === $inflated ) {
			return $this->fail( 'shell_dump_gzip', "The {$this->table} dump stream is not valid gzip data." );
		}
		if ( ZLIB_STREAM_END === @inflate_get_status( $this->inflate ) ) {
			$this->gzip_ended = true;
		}
		if ( strlen( $inflated ) > 0 && $this->raw_bytes + $this->lines_buffered_bytes() + strlen( $inflated ) > $this->max_bytes ) {
			return $this->fail( 'shell_dump_too_large', sprintf( 'The %s dump exceeded the %s decompressed transfer ceiling.', $this->table, size_format( $this->max_bytes ) ) );
		}
		return $this->consume( $inflated, false );
	}

	/**
	 * Declares end of wire input and runs the final validations. Returns
	 * ['bytes' => payload bytes, 'lines' => line count] or WP_Error.
	 */
	public function finish() {
		if ( is_wp_error( $this->error ) ) {
			return $this->error;
		}
		if ( ! $this->gzip_ended ) {
			return $this->fail( 'shell_dump_gzip', "The {$this->table} dump stream ended before its gzip framing completed; the transfer was truncated." );
		}
		$outcome = $this->consume( '', true );
		if ( is_wp_error( $outcome ) ) {
			return $outcome;
		}
		if ( null === $this->trailer_hash ) {
			return $this->fail( 'shell_dump_truncated', "The {$this->table} dump stream ended without a completion trailer; the source dump did not finish cleanly." );
		}
		$received = hash_final( $this->hash );
		$this->hash = null;
		if ( ! hash_equals( $this->trailer_hash, $received ) ) {
			return $this->fail( 'shell_dump_hash', "The {$this->table} dump stream hash does not match the source trailer." );
		}
		return array(
			'bytes' => $this->raw_bytes,
			'lines' => $this->lines,
		);
	}

	/** Payload bytes observed so far (for progress reporting). */
	public function bytes() {
		return $this->raw_bytes + $this->lines_buffered_bytes();
	}

	private function lines_buffered_bytes() {
		return strlen( $this->buffer );
	}

	private function fail( $code, $message ) {
		$this->error = new \WP_Error( $code, $message );
		return $this->error;
	}

	private function consume( $data, $final ) {
		$this->buffer .= $data;
		while ( true ) {
			$pos = strpos( $this->buffer, "\n" );
			if ( false === $pos ) {
				if ( strlen( $this->buffer ) > $this->max_line ) {
					return $this->fail( 'shell_dump_line', "A dump line for {$this->table} exceeds the size limit." );
				}
				if ( ! $final || '' === $this->buffer ) {
					return true;
				}
				// Stream ended mid-line; process the unterminated remainder
				// (a trailer the source left unterminated, or truncated data
				// that the missing-trailer check will reject).
				$pos = strlen( $this->buffer ) - 1;
			}
			if ( $pos + 1 > $this->max_line ) {
				return $this->fail( 'shell_dump_line', "A dump line for {$this->table} exceeds the size limit." );
			}
			$line         = substr( $this->buffer, 0, $pos + 1 );
			$this->buffer = substr( $this->buffer, $pos + 1 );

			if ( null !== $this->trailer_hash ) {
				return $this->fail( 'shell_dump_trailer', "Unexpected data after the completion trailer in the {$this->table} dump stream." );
			}
			if ( 0 === strncmp( $line, Db_Shell::TRAILER_PREFIX, strlen( Db_Shell::TRAILER_PREFIX ) ) ) {
				if ( ! preg_match( '/^' . preg_quote( Db_Shell::TRAILER_PREFIX, '/' ) . '([a-f0-9]{64})\n?$/', $line, $matches ) ) {
					return $this->fail( 'shell_dump_trailer', "Malformed completion trailer in the {$this->table} dump stream." );
				}
				if ( '' !== $this->buffer ) {
					return $this->fail( 'shell_dump_trailer', "Unexpected data after the completion trailer in the {$this->table} dump stream." );
				}
				$this->trailer_hash = $matches[1];
				continue;
			}
			hash_update( $this->hash, $line );
			$this->raw_bytes += strlen( $line );
			++$this->lines;
			$outcome = call_user_func( $this->on_line, $line );
			if ( is_wp_error( $outcome ) ) {
				$this->error = $outcome;
				return $outcome;
			}
		}
	}
}
