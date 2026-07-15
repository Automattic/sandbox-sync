<?php
/** Incremental, constant-memory tar extraction for first-sync uploads streams. */

namespace SandboxSync;

defined( 'ABSPATH' ) || exit;

/**
 * Consumes raw ustar bytes directly from a cURL write callback. Completed files
 * are closed and atomically renamed into uploads one at a time, so neither a
 * full archive nor a full-year staging copy is stored on disk.
 */
class Tar_Stream_Extractor {

	private $dest_dir;
	private $temp_dir;
	private $scopes;
	private $max_payload;
	private $buffer = '';
	private $state = 'header';
	private $remaining = 0;
	private $padding = 0;
	private $current = null;
	private $current_path = '';
	private $current_handle = null;
	private $error = null;
	private $wire_bytes = 0;
	private $payload_bytes = 0;
	private $declared_payload = 0;
	private $files = 0;
	private $directories = 0;
	private $last_installed = '';

	public function __construct( $dest_dir, $temp_dir, $scopes, $max_payload ) {
		$this->dest_dir    = rtrim( $dest_dir, '/' );
		$this->temp_dir    = rtrim( $temp_dir, '/' );
		$this->scopes      = array_values( array_unique( (array) $scopes ) );
		$this->max_payload = max( 1, (int) $max_payload );

		if ( empty( $this->scopes ) ) {
			$this->fail( 'tar_stream_scopes', 'The baseline stream has no allowed uploads partitions.' );
			return;
		}
		foreach ( $this->scopes as $scope ) {
			if ( ! Uploads_State::valid_scope( $scope ) ) {
				$this->fail( 'tar_stream_scopes', 'The baseline stream contains an invalid uploads partition.' );
				return;
			}
		}
		foreach ( array( $this->dest_dir, $this->temp_dir ) as $dir ) {
			if ( is_link( $dir ) || ( ! is_dir( $dir ) && ! mkdir( $dir, 0700, true ) ) ) {
				$this->fail( 'tar_stream_dest', "Cannot create a safe baseline stream directory: {$dir}." );
				return;
			}
		}
	}

	/** cURL-compatible writer: returns the accepted byte count, or zero on error. */
	public function write( $chunk ) {
		$length = strlen( $chunk );
		if ( is_wp_error( $this->error ) || 0 === $length ) {
			return is_wp_error( $this->error ) ? 0 : $length;
		}
		$this->wire_bytes += $length;
		$this->buffer     .= $chunk;

		while ( ! is_wp_error( $this->error ) ) {
			if ( 'done' === $this->state ) {
				if ( '' !== $this->buffer ) {
					$this->fail( 'tar_stream_trailing', 'The baseline tar contains data after its end marker.' );
				}
				break;
			}

			if ( 'header' === $this->state || 'eot' === $this->state ) {
				if ( strlen( $this->buffer ) < 512 ) {
					break;
				}
				$header       = substr( $this->buffer, 0, 512 );
				$this->buffer = (string) substr( $this->buffer, 512 );
				if ( '' === rtrim( $header, "\0" ) ) {
					$this->state = 'eot' === $this->state ? 'done' : 'eot';
					continue;
				}
				if ( 'eot' === $this->state ) {
					$this->fail( 'tar_stream_eot', 'The baseline tar is missing its second end-of-archive block.' );
					continue;
				}
				$this->begin_entry( $header );
				continue;
			}

			if ( 'data' === $this->state ) {
				if ( 0 === $this->remaining ) {
					$this->finish_file();
					continue;
				}
				if ( '' === $this->buffer ) {
					break;
				}
				$take         = min( $this->remaining, strlen( $this->buffer ) );
				$data         = substr( $this->buffer, 0, $take );
				$this->buffer = (string) substr( $this->buffer, $take );
				if ( ! self::write_all( $this->current_handle, $data ) ) {
					$this->fail( 'tar_stream_write', "Cannot write streamed upload {$this->current['p']}." );
					continue;
				}
				$this->remaining    -= $take;
				$this->payload_bytes += $take;
				continue;
			}

			if ( 'padding' === $this->state ) {
				if ( 0 === $this->padding ) {
					$this->state = 'header';
					continue;
				}
				if ( strlen( $this->buffer ) < $this->padding ) {
					break;
				}
				$padding      = substr( $this->buffer, 0, $this->padding );
				$this->buffer = (string) substr( $this->buffer, $this->padding );
				if ( '' !== rtrim( $padding, "\0" ) ) {
					$this->fail( 'tar_stream_padding', 'The baseline tar contains non-zero file padding.' );
					continue;
				}
				$this->padding = 0;
				$this->state   = 'header';
				continue;
			}

			$this->fail( 'tar_stream_state', 'The baseline tar parser entered an invalid state.' );
		}

		return is_wp_error( $this->error ) ? 0 : $length;
	}

	/** @return array|\WP_Error */
	public function finish() {
		if ( is_wp_error( $this->error ) ) {
			return $this->error;
		}
		if ( 'done' !== $this->state || '' !== $this->buffer || null !== $this->current_handle ) {
			$this->fail( 'tar_stream_truncated', 'The baseline uploads stream ended before the complete tar end marker.' );
			return $this->error;
		}
		return $this->stats();
	}

	public function error() {
		return $this->error;
	}

	public function stats() {
		return array(
			'files'          => $this->files,
			'directories'    => $this->directories,
			'payload_bytes'  => $this->payload_bytes,
			'wire_bytes'     => $this->wire_bytes,
			'last_installed' => $this->last_installed,
		);
	}

	private function begin_entry( $header ) {
		if ( ! self::valid_header_checksum( $header ) ) {
			$this->fail( 'tar_stream_checksum', 'The baseline tar contains an invalid header checksum.' );
			return;
		}
		if ( "ustar\0" !== substr( $header, 257, 6 ) || '00' !== substr( $header, 263, 2 ) ) {
			$this->fail( 'tar_stream_format', 'The baseline stream is not a supported ustar archive.' );
			return;
		}
		$size  = self::parse_octal( substr( $header, 124, 12 ) );
		$mtime = self::parse_octal( substr( $header, 136, 12 ) );
		$mode  = self::parse_octal( substr( $header, 100, 8 ) );
		if ( false === $size || false === $mtime || false === $mode
			|| $size > Tar_Exporter::USTAR_MAX_SIZE || $mtime > Tar_Exporter::USTAR_MAX_SIZE || $mode > 2097151 ) {
			$this->fail( 'tar_stream_header', 'The baseline tar contains malformed numeric metadata.' );
			return;
		}
		$name   = rtrim( substr( $header, 0, 100 ), "\0" );
		$prefix = rtrim( substr( $header, 345, 155 ), "\0" );
		$type   = substr( $header, 156, 1 );
		$path   = '' !== $prefix ? $prefix . '/' . $name : $name;
		if ( '5' === $type ) {
			$path = rtrim( $path, '/' );
		}
		if ( ! Tar_Exporter::is_safe_relative_path( $path ) || ! $this->path_is_allowed( $path ) ) {
			$this->fail( 'tar_stream_scope', "The baseline tar attempted to write outside its selected uploads partitions: {$path}." );
			return;
		}
		$this->declared_payload += $size;
		if ( $this->declared_payload > $this->max_payload ) {
			$this->fail( 'tar_stream_limit', 'The baseline uploads stream exceeds its configured payload limit.' );
			return;
		}

		if ( '5' === $type ) {
			if ( 0 !== $size ) {
				$this->fail( 'tar_stream_directory', 'A baseline tar directory has a non-zero payload.' );
				return;
			}
			$created = Files::install_streamed_directory( $this->dest_dir, $path, $mode & 0777, $mtime );
			if ( is_wp_error( $created ) ) {
				$this->error = $created;
				return;
			}
			++$this->directories;
			$this->state = 'header';
			return;
		}
		if ( '0' !== $type && "\0" !== $type ) {
			$this->fail( 'tar_stream_type', "Unsupported entry type in baseline tar: {$path}." );
			return;
		}
		if ( preg_match( '#^\d{4}/(0[1-9]|1[0-2])$#', $path ) ) {
			$this->fail( 'tar_stream_type', 'A dated uploads partition root must be a directory.' );
			return;
		}

		$this->current = array(
			'p' => $path,
			's' => $size,
			'm' => $mtime,
			'o' => $mode & 0777,
		);
		$this->current_path = $this->temp_dir . '/current-' . hash( 'sha256', $path ) . '.part';
		if ( is_link( $this->current_path ) || ( file_exists( $this->current_path ) && ! unlink( $this->current_path ) ) ) {
			$this->fail( 'tar_stream_temp', "Cannot reset streamed upload temporary file for {$path}." );
			return;
		}
		$this->current_handle = @fopen( $this->current_path, 'wb' );
		if ( ! $this->current_handle ) {
			$this->fail( 'tar_stream_temp', "Cannot create streamed upload temporary file for {$path}." );
			return;
		}
		$this->remaining = $size;
		$this->padding   = ( 512 - ( $size % 512 ) ) % 512;
		$this->state     = 'data';
	}

	private function finish_file() {
		if ( ! is_resource( $this->current_handle ) || ! fflush( $this->current_handle ) ) {
			$this->fail( 'tar_stream_write', "Cannot flush streamed upload {$this->current['p']}." );
			return;
		}
		fclose( $this->current_handle );
		$this->current_handle = null;
		$installed = Files::install_streamed_file( $this->dest_dir, $this->current_path, $this->current );
		if ( is_wp_error( $installed ) ) {
			$this->error = $installed;
			@unlink( $this->current_path );
			return;
		}
		++$this->files;
		// Files arrive in the source's deterministic traversal order, so the
		// last installed path is a durable resume cursor for a retried stream.
		$this->last_installed = $this->current['p'];
		$this->current        = null;
		$this->current_path   = '';
		$this->state          = 'padding';
	}

	private function path_is_allowed( $path ) {
		$scope = preg_match( '#^(\d{4})/(0[1-9]|1[0-2])(?:/|$)#', $path, $matches )
			? $matches[1] . '/' . $matches[2]
			: 'other';
		return in_array( $scope, $this->scopes, true );
	}

	private function fail( $code, $message ) {
		if ( is_resource( $this->current_handle ) ) {
			fclose( $this->current_handle );
		}
		$this->current_handle = null;
		if ( '' !== $this->current_path ) {
			@unlink( $this->current_path );
		}
		if ( ! is_wp_error( $this->error ) ) {
			$this->error = new \WP_Error( $code, $message );
		}
	}

	private static function parse_octal( $field ) {
		$value = trim( $field, " \0" );
		if ( '' === $value ) {
			return 0;
		}
		return preg_match( '/^[0-7]+$/', $value ) ? octdec( $value ) : false;
	}

	private static function valid_header_checksum( $header ) {
		$stored = self::parse_octal( substr( $header, 148, 8 ) );
		if ( false === $stored ) {
			return false;
		}
		$check = substr_replace( $header, '        ', 148, 8 );
		$sum   = 0;
		for ( $i = 0; $i < 512; ++$i ) {
			$sum += ord( $check[ $i ] );
		}
		return $sum === $stored;
	}

	private static function write_all( $handle, $data ) {
		$length = strlen( $data );
		$offset = 0;
		while ( $offset < $length ) {
			$written = fwrite( $handle, substr( $data, $offset ) );
			if ( false === $written || 0 === $written ) {
				return false;
			}
			$offset += $written;
		}
		return true;
	}

	public function __destruct() {
		if ( is_resource( $this->current_handle ) ) {
			fclose( $this->current_handle );
		}
		if ( '' !== $this->current_path && file_exists( $this->current_path ) ) {
			@unlink( $this->current_path );
		}
	}
}
