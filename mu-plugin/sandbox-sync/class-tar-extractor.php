<?php
/** Symlink-safe ustar extraction with strict integrity validation. */

namespace SandboxSync;

defined( 'ABSPATH' ) || exit;

class Tar_Extractor {

	/**
	 * Extracts a streaming-hashed uploads batch into its bounded temporary
	 * stage, then authenticates the trailing manifest before returning hashes.
	 *
	 * @return array[]|\WP_Error Verified hashed manifest entries.
	 */
	public static function extract_verified( $tar_path, $dest_dir, $dir, $nonce, $token, $expected ) {
		return self::extract(
			$tar_path,
			$dest_dir,
			array(
				'dir'      => (string) $dir,
				'nonce'    => (string) $nonce,
				'token'    => (string) $token,
				'expected' => array_values( (array) $expected ),
			)
		);
	}

	/** @return true|array[]|\WP_Error */
	public static function extract( $tar_path, $dest_dir, $verification = null ) {
		$fh = @fopen( $tar_path, 'rb' );
		if ( ! $fh ) {
			return new \WP_Error( 'tar_open', "Cannot open tar file {$tar_path}." );
		}
		$dest_dir = rtrim( $dest_dir, '/' );
		if ( is_link( $dest_dir ) ) {
			fclose( $fh );
			return new \WP_Error( 'tar_dest_symlink', "Destination may not be a symlink: {$dest_dir}." );
		}
		if ( ! is_dir( $dest_dir ) && ! mkdir( $dest_dir, 0755, true ) ) {
			fclose( $fh );
			return new \WP_Error( 'tar_dest', "Cannot create destination {$dest_dir}." );
		}
		$dest_real = realpath( $dest_dir );
		if ( false === $dest_real ) {
			fclose( $fh );
			return new \WP_Error( 'tar_dest', "Cannot resolve destination {$dest_dir}." );
		}
		$total_payload = 0;
		$max_payload   = (int) apply_filters( 'sandbox_sync_max_tar_bytes', 10737418240, $tar_path );
		$expected_map  = array();
		$actual_map    = array();
		$manifest_raw  = null;
		$manifest_seen = false;
		if ( is_array( $verification ) ) {
			foreach ( $verification['expected'] as $entry ) {
				if ( ! is_array( $entry ) || ! isset( $entry['p'], $entry['s'], $entry['m'] ) || ! Tar_Exporter::is_safe_relative_path( $entry['p'] )
					|| Tar_Exporter::VERIFIED_MANIFEST_PATH === $entry['p'] || isset( $expected_map[ $entry['p'] ] ) ) {
					fclose( $fh );
					return new \WP_Error( 'tar_manifest_expected', 'Expected verified archive metadata is malformed or duplicated.' );
				}
				$expected_map[ $entry['p'] ] = $entry;
			}
		}
		while ( true ) {
			$header = self::read_exact( $fh, 512 );
			if ( false === $header ) {
				fclose( $fh );
				return new \WP_Error( 'tar_truncated', 'Archive ended before its end-of-archive marker.' );
			}
			if ( '' === rtrim( $header, "\0" ) ) {
				$second = self::read_exact( $fh, 512 );
				if ( false === $second || '' !== rtrim( $second, "\0" ) ) {
					fclose( $fh );
					return new \WP_Error( 'tar_truncated', 'Archive is missing the second end-of-archive block.' );
				}
				break;
			}
			if ( ! self::valid_header_checksum( $header ) ) {
				fclose( $fh );
				return new \WP_Error( 'tar_checksum', 'Archive contains a header with an invalid checksum.' );
			}
			$name           = rtrim( substr( $header, 0, 100 ), "\0" );
			$size           = octdec( trim( substr( $header, 124, 12 ) ) );
			$mtime          = octdec( trim( substr( $header, 136, 12 ) ) );
			$mode           = octdec( trim( substr( $header, 100, 8 ) ) ) & 0777;
			$type           = substr( $header, 156, 1 );
			$prefix         = rtrim( substr( $header, 345, 155 ), "\0" );
			$total_payload += $size;
			if ( $size < 0 || $total_payload > $max_payload ) {
				fclose( $fh );
				return new \WP_Error( 'tar_limit', 'Archive exceeds the configured decompressed payload limit.' );
			}
			$path = '' !== $prefix ? $prefix . '/' . $name : $name;
			if ( '5' === $type ) {
				$path = rtrim( $path, '/' );
			}
			if ( ! Tar_Exporter::is_safe_relative_path( $path ) ) {
				fclose( $fh );
				return new \WP_Error( 'tar_path', "Unsafe path in archive: {$path}" );
			}
			if ( is_array( $verification ) && Tar_Exporter::VERIFIED_MANIFEST_PATH === $path ) {
				if ( $manifest_seen || ( '0' !== $type && "\0" !== $type ) || $size > 1048576 ) {
					fclose( $fh );
					return new \WP_Error( 'tar_manifest_entry', 'Verified archive has a malformed or duplicate transfer manifest.' );
				}
				$manifest_raw = self::read_exact( $fh, $size );
				$pad          = ( 512 - ( $size % 512 ) ) % 512;
				if ( false === $manifest_raw || ( $pad > 0 && false === self::read_exact( $fh, $pad ) ) ) {
					fclose( $fh );
					return new \WP_Error( 'tar_truncated', 'Verified archive transfer manifest is truncated.' );
				}
				$manifest_seen = true;
				continue;
			}
			if ( is_array( $verification ) && $manifest_seen ) {
				fclose( $fh );
				return new \WP_Error( 'tar_manifest_order', 'Verified archive contains payload after its transfer manifest.' );
			}
			$target = $dest_real . '/' . $path;
			if ( '5' === $type ) {
				$created = self::ensure_directory( $dest_real, $path );
				if ( is_wp_error( $created ) ) {
					fclose( $fh );
					return $created;
				}
				if ( $mode > 0 ) {
					@chmod( $target, $mode );
				}
			} elseif ( '0' === $type || "\0" === $type ) {
				if ( is_array( $verification ) ) {
					if ( ! isset( $expected_map[ $path ] ) || isset( $actual_map[ $path ] ) ) {
						fclose( $fh );
						return new \WP_Error( 'tar_manifest_paths', 'Verified archive contains an unexpected or duplicate payload path.' );
					}
					$expected = $expected_map[ $path ];
					if ( (int) $expected['s'] !== (int) $size || (int) $expected['m'] !== (int) $mtime
						|| ( isset( $expected['o'] ) && (int) $expected['o'] !== (int) $mode ) ) {
						fclose( $fh );
						return new \WP_Error( 'tar_manifest_metadata', 'Verified archive payload metadata differs from the requested transfer.' );
					}
				}
				$parent_rel = dirname( $path );
				if ( '.' !== $parent_rel ) {
					$created = self::ensure_directory( $dest_real, $parent_rel );
					if ( is_wp_error( $created ) ) {
						fclose( $fh );
						return $created;
					}
				}
				if ( is_link( $target ) ) {
					fclose( $fh );
					return new \WP_Error( 'tar_symlink', "Refusing to overwrite a symlink: {$path}" );
				}
				$out = @fopen( $target, 'wb' );
				if ( ! $out ) {
					fclose( $fh );
					return new \WP_Error( 'tar_write', "Cannot write file {$target}." );
				}
				$remaining = $size;
				$hash      = is_array( $verification ) ? hash_init( 'sha256' ) : null;
				while ( $remaining > 0 ) {
					$chunk = self::read_exact( $fh, min( 524288, $remaining ) );
					if ( false === $chunk || ! self::write_all( $out, $chunk ) ) {
						fclose( $out );
						fclose( $fh );
						return new \WP_Error( false === $chunk ? 'tar_truncated' : 'tar_write', "Archive read/write failed while extracting {$target}." );
					}
					if ( null !== $hash ) {
						hash_update( $hash, $chunk );
					}
					$remaining -= strlen( $chunk );
				}
				if ( ! fflush( $out ) ) {
					fclose( $out );
					fclose( $fh );
					return new \WP_Error( 'tar_write', "Could not flush {$target}." );
				}
				fclose( $out );
				if ( $mtime > 0 ) {
					@touch( $target, $mtime );
				}
				if ( $mode > 0 ) {
					@chmod( $target, $mode );
				}
				$pad = ( 512 - ( $size % 512 ) ) % 512;
				if ( $pad > 0 && false === self::read_exact( $fh, $pad ) ) {
					fclose( $fh );
					return new \WP_Error( 'tar_truncated', "Archive padding is truncated after {$path}." );
				}
				if ( null !== $hash ) {
					$actual_map[ $path ] = array(
						'p' => $path,
						's' => (int) $size,
						'm' => (int) $mtime,
						'o' => (int) $mode,
						'h' => hash_final( $hash ),
					);
				}
			} else {
				$skip = $size + ( ( 512 - ( $size % 512 ) ) % 512 );
				if ( $skip > 0 && ! self::discard_exact( $fh, $skip ) ) {
					fclose( $fh );
					return new \WP_Error( 'tar_truncated', "Unsupported archive entry is truncated: {$path}." );
				}
			}
		}
		fclose( $fh );
		if ( is_array( $verification ) ) {
			return self::verify_manifest( $manifest_raw, $verification, $expected_map, $actual_map );
		}
		return true;
	}

	/** @return array[]|\WP_Error */
	private static function verify_manifest( $manifest_raw, $verification, $expected_map, $actual_map ) {
		if ( ! is_string( $manifest_raw ) ) {
			return new \WP_Error( 'tar_manifest_missing', 'Verified archive is missing its signed transfer manifest.' );
		}
		$envelope = json_decode( $manifest_raw, true );
		if ( ! is_array( $envelope ) || ! isset( $envelope['payload'], $envelope['signature'] )
			|| ! is_string( $envelope['payload'] ) || ! preg_match( '/^[a-f0-9]{64}$/', (string) $envelope['signature'] ) ) {
			return new \WP_Error( 'tar_manifest_data', 'Verified archive transfer manifest envelope is malformed.' );
		}
		$payload = base64_decode( $envelope['payload'], true );
		if ( false === $payload ) {
			return new \WP_Error( 'tar_manifest_data', 'Verified archive transfer manifest payload is malformed.' );
		}
		$signature = hash_hmac( 'sha256', $payload, Tar_Exporter::manifest_signing_key( $verification['token'] ) );
		if ( ! hash_equals( $signature, (string) $envelope['signature'] ) ) {
			return new \WP_Error( 'tar_manifest_signature', 'Verified archive transfer manifest signature does not match.' );
		}
		$data = json_decode( $payload, true );
		if ( ! is_array( $data ) || (int) ( $data['v'] ?? 0 ) !== Tar_Exporter::VERIFIED_MANIFEST_VERSION
			|| ! isset( $data['dir'], $data['nonce'], $data['files'] ) || ! is_array( $data['files'] )
			|| ! hash_equals( (string) $verification['dir'], (string) $data['dir'] )
			|| ! hash_equals( (string) $verification['nonce'], (string) $data['nonce'] ) ) {
			return new \WP_Error( 'tar_manifest_binding', 'Verified archive transfer manifest is not bound to this request.' );
		}
		$manifest_map = array();
		foreach ( $data['files'] as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['p'], $entry['s'], $entry['m'], $entry['o'], $entry['h'] )
				|| ! preg_match( '/^[a-f0-9]{64}$/', (string) $entry['h'] ) || isset( $manifest_map[ $entry['p'] ] ) ) {
				return new \WP_Error( 'tar_manifest_data', 'Verified archive transfer manifest contains malformed file data.' );
			}
			$manifest_map[ $entry['p'] ] = $entry;
		}
		if ( count( $expected_map ) !== count( $actual_map ) || count( $actual_map ) !== count( $manifest_map ) ) {
			return new \WP_Error( 'tar_manifest_count', 'Verified archive does not contain every requested file exactly once.' );
		}
		foreach ( $actual_map as $path => $actual ) {
			if ( ! isset( $manifest_map[ $path ] ) || $actual !== $manifest_map[ $path ] ) {
				return new \WP_Error( 'tar_manifest_hash', 'Verified archive payload failed its streaming SHA-256 manifest check.' );
			}
		}
		return array_values( $actual_map );
	}

	/** @return true|\WP_Error */
	private static function ensure_directory( $root, $relative ) {
		$current = $root;
		foreach ( explode( '/', $relative ) as $part ) {
			$current .= '/' . $part;
			if ( is_link( $current ) ) {
				return new \WP_Error( 'tar_symlink', "Archive path crosses a symlink: {$relative}" );
			}
			if ( file_exists( $current ) ) {
				if ( ! is_dir( $current ) ) {
					return new \WP_Error( 'tar_mkdir', "Archive directory collides with a file: {$relative}" );
				}
			} elseif ( ! mkdir( $current, 0755 ) ) {
				return new \WP_Error( 'tar_mkdir', "Cannot create directory {$current}." );
			}
			$real = realpath( $current );
			if ( false === $real || ( $real !== $root && 0 !== strpos( $real, rtrim( $root, '/' ) . '/' ) ) ) {
				return new \WP_Error( 'tar_escape', "Archive path escapes the destination: {$relative}" );
			}
		}
		return true;
	}

	private static function read_exact( $fh, $bytes ) {
		$data = '';
		while ( strlen( $data ) < $bytes ) {
			$chunk = fread( $fh, $bytes - strlen( $data ) );
			if ( false === $chunk || '' === $chunk ) {
				return false;
			}
			$data .= $chunk;
		}
		return $data;
	}

	private static function write_all( $fh, $data ) {
		$offset = 0;
		$length = strlen( $data );
		while ( $offset < $length ) {
			$written = fwrite( $fh, substr( $data, $offset ) );
			if ( false === $written || 0 === $written ) {
				return false;
			}
			$offset += $written;
		}
		return true;
	}

	private static function discard_exact( $fh, $bytes ) {
		$remaining = $bytes;
		while ( $remaining > 0 ) {
			$chunk = self::read_exact( $fh, min( 1048576, $remaining ) );
			if ( false === $chunk ) {
				return false;
			}
			$remaining -= strlen( $chunk );
		}
		return true;
	}

	private static function valid_header_checksum( $header ) {
		$stored = trim( substr( $header, 148, 8 ), " \0" );
		if ( '' === $stored || ! preg_match( '/^[0-7]+$/', $stored ) ) {
			return false;
		}
		$check = substr_replace( $header, '        ', 148, 8 );
		$sum   = 0;
		for ( $i = 0; $i < 512; ++$i ) {
			$sum += ord( $check[ $i ] );
		}
		return octdec( $stored ) === $sum;
	}
}
