<?php
/**
 * Pure-PHP tar streaming (source side) + tar extraction (sandbox side).
 *
 * The tar builder is adapted from the Knife's knife-uploads-http-export.php:
 * no exec/passthru (shell functions are disabled on WP Cloud/Atomic), ustar
 * headers with name+prefix splitting, and symlinks are skipped everywhere so
 * dev-repo symlinks never travel between sites.
 */

namespace SandboxSync;

defined( 'ABSPATH' ) || exit;

class Tar_Exporter {

	const VERIFIED_MANIFEST_PATH = '.sandbox-sync-transfer-manifest.json';
	const VERIFIED_MANIFEST_VERSION = 1;
	const USTAR_MAX_SIZE = 8589934591; // Eleven octal digits: 8 GiB minus 1 byte.

	/**
	 * GNU long-name record: paths that fit neither the 100-byte name field
	 * nor a name/prefix split are carried in a preceding type-L entry whose
	 * payload is the full path. Both extractors restore it; this bound keeps
	 * a hostile record from forcing an unbounded path buffer.
	 */
	const GNU_LONG_NAME     = '././@LongLink';
	const LONG_NAME_MAX     = 4096;

	/**
	 * Streams the CONTENTS of $base_dir as a tar archive to the output
	 * buffer, ending with the standard 1024-byte end-of-archive marker.
	 * Top-level (and nested) symlinks are skipped.
	 */
	public static function stream( $base_dir ) {
		$valid = self::validate_tree( $base_dir );
		if ( is_wp_error( $valid ) ) {
			self::abort( $valid->get_error_message() );
		}

		$entries = is_dir( $base_dir ) ? scandir( $base_dir ) : array();
		if ( ! $entries ) {
			$entries = array();
		}
		sort( $entries );

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$full_path = $base_dir . '/' . $entry;
			if ( is_link( $full_path ) ) {
				continue;
			}
			if ( is_dir( $full_path ) ) {
				self::stream_directory_recursive( $full_path, $entry );
			} elseif ( is_file( $full_path ) ) {
				self::stream_file( $entry, $full_path, (int) @filemtime( $full_path ) );
			}
		}

		// End-of-archive marker: two 512-byte zero blocks.
		echo str_repeat( "\0", 1024 );
		flush();
	}

	/**
	 * Streams selected uploads date partitions as one uncompressed baseline
	 * archive in a single deterministic filesystem pass. Multiple YYYY/MM
	 * scopes may be grouped into one year request; "other" retains the same
	 * non-date semantics as Files::walk_upload_scope().
	 *
	 * Entries are validated inline while streaming (no separate preflight
	 * traversal), and the sorted traversal order lets a retried request resume
	 * after the last file the sandbox durably installed. Unlike incremental
	 * archives, this intentionally performs no content hashing: the target
	 * validates tar framing and writes every completed file atomically, then
	 * the ordinary generation-based final delta closes races.
	 *
	 * @param string        $base_dir     Uploads directory.
	 * @param string[]      $scopes       Exact YYYY/MM scopes and/or "other".
	 * @param callable|null $checkpoint   Optional cooperative-cancellation callback.
	 * @param string        $resume_after Skip everything up to and including this
	 *                                    relative file path in traversal order.
	 * @return true|\WP_Error An error truncates the archive; the sandbox
	 *                        detects the missing end marker and retries.
	 */
	public static function stream_upload_scopes( $base_dir, $scopes, $checkpoint = null, $resume_after = '' ) {
		$nodes = self::prepare_upload_scopes( $base_dir, $scopes );
		if ( is_wp_error( $nodes ) ) {
			return $nodes;
		}
		return self::stream_upload_scope_nodes( $nodes, $checkpoint, $resume_after );
	}

	/**
	 * Cheap non-recursive baseline preflight: resolves the selected top-level
	 * nodes in deterministic traversal order so request errors can still be
	 * reported as JSON before any archive byte is emitted.
	 *
	 * @return array[]|\WP_Error
	 */
	public static function prepare_upload_scopes( $base_dir, $scopes ) {
		$nodes = self::prepare_upload_scope_nodes( $base_dir, $scopes );
		if ( is_wp_error( $nodes ) ) {
			return $nodes;
		}
		usort(
			$nodes,
			function ( $a, $b ) {
				return self::traversal_cmp( $a['path'], $b['path'] );
			}
		);
		return $nodes;
	}

	/** @return true|\WP_Error Streams prepared nodes and the end-of-archive marker. */
	public static function stream_upload_scope_nodes( $nodes, $checkpoint = null, $resume_after = '' ) {
		foreach ( (array) $nodes as $node ) {
			if ( ! self::checkpoint_allows_progress( $checkpoint ) ) {
				return new \WP_Error( 'tar_cancelled', 'The baseline uploads stream was cancelled.' );
			}
			if ( 'dir' === $node['type'] ) {
				if ( ! self::subtree_may_contain_remaining( $node['path'], $resume_after ) ) {
					continue;
				}
				$result = self::stream_scope_directory( $node['full'], $node['path'], $checkpoint, $resume_after );
			} else {
				if ( ! self::path_is_after( $node['path'], $resume_after ) ) {
					continue;
				}
				$result = self::emit_scope_file( $node['path'], $node['full'], $checkpoint );
			}
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		if ( ! self::checkpoint_allows_progress( $checkpoint ) ) {
			return new \WP_Error( 'tar_cancelled', 'The baseline uploads stream was cancelled.' );
		}
		echo str_repeat( "\0", 1024 );
		flush();
		return true;
	}

	/**
	 * Deterministic depth-first traversal order: component-wise byte
	 * comparison, ancestors before descendants. This is the exact order
	 * stream_upload_scope_nodes() emits files in, so a resume cursor can be
	 * compared against it.
	 *
	 * @return int Negative when $a sorts before $b.
	 */
	public static function traversal_cmp( $a, $b ) {
		$a_parts = explode( '/', (string) $a );
		$b_parts = explode( '/', (string) $b );
		$common  = min( count( $a_parts ), count( $b_parts ) );
		for ( $i = 0; $i < $common; $i++ ) {
			$cmp = strcmp( $a_parts[ $i ], $b_parts[ $i ] );
			if ( 0 !== $cmp ) {
				return $cmp;
			}
		}
		return count( $a_parts ) - count( $b_parts );
	}

	/**
	 * Streams ONLY the given relative file paths from $base_dir as a tar
	 * archive (used for incremental batches). Invalid, traversal, symlinked
	 * or missing paths are silently skipped — the sandbox re-diffs on the
	 * next run anyway.
	 *
	 * @param string   $base_dir Directory the paths are relative to.
	 * @param string[] $paths    Relative file paths.
	 */
	public static function stream_paths( $base_dir, $paths ) {
		$prepared = self::prepare_paths( $base_dir, $paths );
		if ( is_wp_error( $prepared ) ) {
			self::abort( $prepared->get_error_message() );
		}

		foreach ( $prepared as $file ) {
			self::stream_file( $file['path'], $file['full'], $file['mtime'] );
		}

		echo str_repeat( "\0", 1024 );
		flush();
	}

	/**
	 * Streams metadata-selected files and hashes every byte while it is written
	 * to the tar. A transfer-bound HMAC manifest is appended as the final entry,
	 * allowing the sandbox to verify the payload without a separate source read.
	 *
	 * @param string  $base_dir Directory the paths are relative to.
	 * @param array[] $requested Entries containing p/s/m and optionally o.
	 * @param string  $dir       Logical sync directory.
	 * @param string  $nonce     Request binding supplied by the sandbox.
	 * @param string  $token     Authenticated bearer token.
	 */
	public static function stream_verified_paths( $base_dir, $requested, $dir, $nonce, $token ) {
		$paths = array_column( $requested, 'p' );
		if ( in_array( self::VERIFIED_MANIFEST_PATH, $paths, true ) ) {
			self::abort( 'reserved transfer-manifest path requested' );
		}
		$prepared = self::prepare_paths( $base_dir, $paths );
		if ( is_wp_error( $prepared ) || count( $prepared ) !== count( $requested ) ) {
			self::abort( is_wp_error( $prepared ) ? $prepared->get_error_message() : 'incomplete verified path preparation' );
		}

		// Check every planned file before emitting the first byte. A later race is
		// checked again from the open descriptor and fails as a truncated archive.
		foreach ( $prepared as $index => $file ) {
			$expected = $requested[ $index ];
			if ( (int) $expected['s'] !== (int) $file['size'] || (int) $expected['m'] !== (int) $file['mtime']
				|| ( isset( $expected['o'] ) && (int) $expected['o'] !== (int) $file['mode'] ) ) {
				self::abort( "source metadata changed before streaming {$file['path']}" );
			}
		}

		$files = array();
		foreach ( $prepared as $index => $file ) {
			$files[] = self::stream_file_hashed( $file, $requested[ $index ] );
		}

		$payload = wp_json_encode(
			array(
				'v'     => self::VERIFIED_MANIFEST_VERSION,
				'dir'   => $dir,
				'nonce' => $nonce,
				'files' => $files,
			)
		);
		if ( false === $payload ) {
			self::abort( 'cannot encode transfer manifest' );
		}
		$envelope = wp_json_encode(
			array(
				'payload'   => base64_encode( $payload ),
				'signature' => hash_hmac( 'sha256', $payload, self::manifest_signing_key( $token ) ),
			)
		);
		if ( false === $envelope ) {
			self::abort( 'cannot sign transfer manifest' );
		}
		self::stream_string( self::VERIFIED_MANIFEST_PATH, $envelope, time(), 0600 );
		echo str_repeat( "\0", 1024 );
		flush();
	}

	/** Domain-separated HMAC key shared by the authenticated endpoints. */
	public static function manifest_signing_key( $token ) {
		return hash( 'sha256', "sandbox-sync-upload-manifest\0" . (string) $token, true );
	}

	/**
	 * Validates and resolves a requested incremental path list without ever
	 * following a symlinked component. The resolved list can be prepared before
	 * response headers are emitted, so invalid requests fail as JSON rather than
	 * as a partial archive.
	 *
	 * @return array|\WP_Error
	 */
	public static function prepare_paths( $base_dir, $paths ) {
		$base = realpath( $base_dir );
		if ( false === $base || ! is_dir( $base ) || is_link( $base_dir ) ) {
			return new \WP_Error( 'tar_base', 'Archive source directory is missing or unsafe.' );
		}

		$prepared = array();
		foreach ( $paths as $path ) {
			if ( ! self::is_safe_relative_path( $path ) ) {
				return new \WP_Error( 'tar_path', 'Unsafe archive path requested.' );
			}
			if ( strlen( $path ) > self::LONG_NAME_MAX ) {
				return new \WP_Error( 'tar_long_path', "Path cannot be represented safely in ustar: {$path}" );
			}

			$current = $base;
			foreach ( explode( '/', $path ) as $part ) {
				$current .= DIRECTORY_SEPARATOR . $part;
				$stat     = @lstat( $current );
				if ( false === $stat ) {
					return new \WP_Error( 'tar_missing', "Requested archive path is missing: {$path}" );
				}
				if ( is_link( $current ) ) {
					return new \WP_Error( 'tar_symlink', "Requested archive path crosses a symlink: {$path}" );
				}
			}

			$real = realpath( $current );
			if ( false === $real || ! is_file( $real ) || ! self::is_within( $base, $real ) ) {
				return new \WP_Error( 'tar_escape', "Requested archive path escapes its source directory: {$path}" );
			}
			$prepared[] = array(
				'path'  => $path,
				'full'  => $real,
				'size'  => (int) @filesize( $real ),
				'mtime' => (int) @filemtime( $real ),
				'mode'  => (int) ( @fileperms( $real ) & 0777 ),
			);
		}
		return $prepared;
	}

	/**
	 * Preflights all paths before a full archive starts streaming. Unsupported
	 * paths fail loudly instead of being silently truncated into collisions.
	 *
	 * @return true|\WP_Error
	 */
	public static function validate_tree( $base_dir ) {
		if ( ! is_dir( $base_dir ) ) {
			return true; // A missing syncable dir intentionally exports an empty tar.
		}
		$base = realpath( $base_dir );
		if ( false === $base || is_link( $base_dir ) ) {
			return new \WP_Error( 'tar_base', 'Archive source directory is unsafe.' );
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $base, \RecursiveDirectoryIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::SELF_FIRST
		);
		foreach ( $iterator as $item ) {
			if ( $item->isLink() ) {
				continue;
			}
			$path = substr( $item->getPathname(), strlen( $base ) + 1 ) . ( $item->isDir() ? '/' : '' );
			if ( ! self::is_safe_relative_path( rtrim( $path, '/' ) ) ) {
				return new \WP_Error( 'tar_path', "Unsafe path in source tree: {$path}" );
			}
			if ( strlen( $path ) > self::LONG_NAME_MAX ) {
				return new \WP_Error( 'tar_long_path', "Path cannot be represented safely in ustar: {$path}" );
			}
			$metadata = self::validate_ustar_metadata( $path, $item->getPathname(), $item->isDir() );
			if ( is_wp_error( $metadata ) ) {
				return $metadata;
			}
		}
		return true;
	}

	public static function is_safe_relative_path( $path ) {
		if ( ! is_string( $path ) || '' === $path || false !== strpos( $path, "\0" ) || 0 === strpos( $path, '/' ) || false !== strpos( $path, '\\' ) ) {
			return false;
		}
		foreach ( explode( '/', $path ) as $part ) {
			if ( '' === $part || '.' === $part || '..' === $part ) {
				return false;
			}
		}
		return true;
	}

	private static function is_within( $base, $candidate ) {
		return $candidate === $base || 0 === strpos( $candidate, rtrim( $base, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR );
	}

	private static function can_pack_name( $path ) {
		if ( strlen( $path ) <= 100 ) {
			return true;
		}
		$pos = 0;
		while ( ( $pos = strpos( $path, '/', $pos ) ) !== false ) {
			$prefix = substr( $path, 0, $pos );
			$name   = substr( $path, $pos + 1 );
			++$pos;
			if ( '' !== $name && strlen( $prefix ) <= 155 && strlen( $name ) <= 100 ) {
				return true;
			}
		}
		return false;
	}

	/** @return array[]|\WP_Error Selected top-level nodes with archive paths. */
	private static function prepare_upload_scope_nodes( $base_dir, $scopes ) {
		$scopes = array_values( array_unique( (array) $scopes ) );
		if ( empty( $scopes ) ) {
			return new \WP_Error( 'tar_upload_scopes', 'At least one uploads partition is required.' );
		}
		foreach ( $scopes as $scope ) {
			if ( ! Uploads_State::valid_scope( $scope ) ) {
				return new \WP_Error( 'tar_upload_scopes', 'An invalid uploads partition was requested.' );
			}
		}
		if ( ! file_exists( $base_dir ) ) {
			return array();
		}
		$base = realpath( $base_dir );
		if ( false === $base || ! is_dir( $base ) || is_link( $base_dir ) ) {
			return new \WP_Error( 'tar_base', 'Uploads archive source is missing or unsafe.' );
		}

		$nodes = array();
		foreach ( $scopes as $scope ) {
			if ( 'other' !== $scope ) {
				$parts   = explode( '/', $scope, 2 );
				$year    = $base . '/' . $parts[0];
				$month   = $year . '/' . $parts[1];
				$year_st = @lstat( $year );
				$mon_st  = @lstat( $month );
				if ( false === $year_st || false === $mon_st ) {
					continue;
				}
				if ( is_link( $year ) || is_link( $month ) ) {
					continue; // Symlinked uploads are deliberately outside sync scope.
				}
				if ( ! is_dir( $month ) ) {
					return new \WP_Error( 'tar_upload_scope', "Uploads partition {$scope} is not a directory." );
				}
				$nodes[] = array(
					'type' => 'dir',
					'full' => $month,
					'path' => $scope,
				);
			}
		}

		if ( in_array( 'other', $scopes, true ) ) {
			foreach ( (array) @scandir( $base ) as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}
				$full = $base . '/' . $entry;
				if ( is_link( $full ) ) {
					continue;
				}
				if ( preg_match( '/^\d{4}$/', $entry ) && is_dir( $full ) ) {
					foreach ( (array) @scandir( $full ) as $child ) {
						if ( '.' === $child || '..' === $child ) {
							continue;
						}
						$child_full = $full . '/' . $child;
						if ( is_link( $child_full ) || ( preg_match( '/^(0[1-9]|1[0-2])$/', $child ) && is_dir( $child_full ) ) ) {
							continue;
						}
						if ( is_dir( $child_full ) || is_file( $child_full ) ) {
							$nodes[] = array(
								'type' => is_dir( $child_full ) ? 'dir' : 'file',
								'full' => $child_full,
								'path' => $entry . '/' . $child,
							);
						}
					}
				} elseif ( is_dir( $full ) || is_file( $full ) ) {
					$nodes[] = array(
						'type' => is_dir( $full ) ? 'dir' : 'file',
						'full' => $full,
						'path' => $entry,
					);
				}
			}
		}
		return $nodes;
	}

	/** Whether any file in traversal order below $dir_path can follow $resume_after. */
	private static function subtree_may_contain_remaining( $dir_path, $resume_after ) {
		if ( '' === $resume_after ) {
			return true;
		}
		if ( self::traversal_cmp( $dir_path, $resume_after ) >= 0 ) {
			return true;
		}
		return 0 === strpos( $resume_after, rtrim( $dir_path, '/' ) . '/' );
	}

	private static function path_is_after( $path, $resume_after ) {
		return '' === $resume_after || self::traversal_cmp( $path, $resume_after ) > 0;
	}

	/**
	 * Emits one directory subtree in sorted depth-first order, validating each
	 * entry inline (single filesystem pass — no separate preflight traversal).
	 *
	 * @return true|\WP_Error
	 */
	private static function stream_scope_directory( $full, $rel, $checkpoint, $resume_after ) {
		if ( ! self::is_safe_relative_path( $rel ) || strlen( $rel ) + 1 > self::LONG_NAME_MAX ) {
			return new \WP_Error( 'tar_long_path', "Path cannot be represented safely in ustar: {$rel}/" );
		}
		$mtime    = @filemtime( $full );
		$mode     = @fileperms( $full );
		$metadata = self::validate_ustar_metadata( $rel . '/', $full, true );
		if ( is_wp_error( $metadata ) ) {
			return $metadata;
		}
		// Directory headers are idempotent on the sandbox (mkdir-if-missing),
		// so ancestors of a resume cursor are re-sent rather than tracked.
		echo self::build_header( $rel . '/', 0, $mtime ? $mtime : 0, false !== $mode ? $mode & 0777 : 0755, '5' );

		$entries = @scandir( $full );
		if ( false === $entries ) {
			return new \WP_Error( 'tar_read', "Cannot list uploads directory: {$rel}" );
		}
		sort( $entries, SORT_STRING );
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			if ( ! self::checkpoint_allows_progress( $checkpoint ) ) {
				return new \WP_Error( 'tar_cancelled', 'The baseline uploads stream was cancelled.' );
			}
			$child_full = $full . '/' . $entry;
			$child_rel  = $rel . '/' . $entry;
			if ( is_link( $child_full ) ) {
				continue;
			}
			if ( is_dir( $child_full ) ) {
				if ( ! self::subtree_may_contain_remaining( $child_rel, $resume_after ) ) {
					continue;
				}
				$result = self::stream_scope_directory( $child_full, $child_rel, $checkpoint, $resume_after );
			} elseif ( is_file( $child_full ) ) {
				if ( ! self::path_is_after( $child_rel, $resume_after ) ) {
					continue;
				}
				$result = self::emit_scope_file( $child_rel, $child_full, $checkpoint );
			} else {
				continue;
			}
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		return true;
	}

	/**
	 * Streams one baseline file with inline validation, returning errors
	 * instead of aborting so the endpoint controls the failure response.
	 *
	 * @return true|\WP_Error
	 */
	private static function emit_scope_file( $rel_path, $real_path, $checkpoint ) {
		if ( ! self::is_safe_relative_path( $rel_path ) || strlen( $rel_path ) > self::LONG_NAME_MAX ) {
			return new \WP_Error( 'tar_long_path', "Path cannot be represented safely in ustar: {$rel_path}" );
		}
		$fh = @fopen( $real_path, 'rb' );
		if ( ! $fh ) {
			return new \WP_Error( 'tar_open', "Cannot open {$rel_path}" );
		}
		$stat = fstat( $fh );
		if ( ! is_array( $stat ) || ! isset( $stat['size'], $stat['mtime'] ) ) {
			fclose( $fh );
			return new \WP_Error( 'tar_stat', "Cannot stat {$rel_path}" );
		}
		$size  = (int) $stat['size'];
		$mtime = (int) $stat['mtime'];
		if ( $size < 0 || $mtime < 0 || $size > self::USTAR_MAX_SIZE || $mtime > self::USTAR_MAX_SIZE ) {
			fclose( $fh );
			return new \WP_Error( 'tar_numeric_range', "File metadata cannot be represented safely in ustar: {$rel_path}" );
		}
		echo self::build_header( $rel_path, $size, $mtime, isset( $stat['mode'] ) ? (int) $stat['mode'] & 0777 : 0644, '0' );

		$written = 0;
		while ( $written < $size && ! feof( $fh ) ) {
			if ( ! self::checkpoint_allows_progress( $checkpoint ) ) {
				fclose( $fh );
				return new \WP_Error( 'tar_cancelled', 'The baseline uploads stream was cancelled.' );
			}
			$chunk = fread( $fh, min( 524288, $size - $written ) );
			if ( false === $chunk || '' === $chunk ) {
				fclose( $fh );
				return new \WP_Error( 'tar_read', "Read error on {$rel_path}" );
			}
			echo $chunk;
			$written += strlen( $chunk );
		}
		$after = fstat( $fh );
		fclose( $fh );
		if ( $written !== $size || ! is_array( $after ) || (int) $after['size'] !== $size || (int) $after['mtime'] !== $mtime ) {
			return new \WP_Error( 'tar_changed', "Source changed while streaming {$rel_path}" );
		}
		$remainder = $size % 512;
		if ( $remainder > 0 ) {
			echo str_repeat( "\0", 512 - $remainder );
		}
		flush();
		return true;
	}

	private static function checkpoint_allows_progress( $checkpoint ) {
		return ! is_callable( $checkpoint ) || false !== call_user_func( $checkpoint );
	}

	private static function require_checkpoint( $checkpoint ) {
		if ( ! self::checkpoint_allows_progress( $checkpoint ) ) {
			self::abort( 'baseline uploads stream cancelled' );
		}
	}

	/** @return true|\WP_Error */
	private static function validate_ustar_metadata( $path, $full_path, $directory ) {
		$size  = $directory ? 0 : @filesize( $full_path );
		$mtime = @filemtime( $full_path );
		if ( false === $size || false === $mtime || $size < 0 || $mtime < 0 || $size > self::USTAR_MAX_SIZE || $mtime > self::USTAR_MAX_SIZE ) {
			return new \WP_Error( 'tar_numeric_range', "File metadata cannot be represented safely in ustar: {$path}" );
		}
		return true;
	}

	/**
	 * Packs a path into the tar header's name (100) and prefix (155) fields.
	 */
	private static function pack_name( $path ) {
		if ( strlen( $path ) <= 100 ) {
			return array(
				str_pad( substr( $path, 0, 100 ), 100, "\0" ),
				str_repeat( "\0", 155 ),
			);
		}

		$best = false;
		$pos  = 0;
		while ( ( $pos = strpos( $path, '/', $pos ) ) !== false ) {
			$prefix_part = substr( $path, 0, $pos );
			$name_part   = substr( $path, $pos + 1 );
			++$pos;
			// Never split at a trailing slash (directory entries): an empty
			// name field starts with NUL, which extractors read as the
			// end-of-archive marker and stop mid-stream.
			if ( '' === $name_part ) {
				continue;
			}
			if ( strlen( $prefix_part ) <= 155 && strlen( $name_part ) <= 100 ) {
				$best = array( $name_part, $prefix_part );
			}
		}

		if ( $best ) {
			return array(
				str_pad( substr( $best[0], 0, 100 ), 100, "\0" ),
				str_pad( substr( $best[1], 0, 155 ), 155, "\0" ),
			);
		}

		// Unreachable: build_header() carries such paths in a GNU long-name
		// record and only packs a placeholder here. Never truncate silently.
		self::abort( "unrepresentable ustar path: {$path}" );
	}

	/**
	 * Builds the header block(s) for one entry. A path the ustar fields cannot
	 * hold is preceded by a GNU type-L record carrying the full path; its own
	 * header then holds a 100-byte placeholder that extractors replace.
	 */
	private static function build_header( $path, $size, $mtime, $mode, $typeflag ) {
		if ( $size < 0 || $mtime < 0 || $mode < 0 || $size > self::USTAR_MAX_SIZE || $mtime > self::USTAR_MAX_SIZE || $mode > 2097151 ) {
			self::abort( "numeric metadata cannot be represented safely in ustar: {$path}" );
		}
		if ( self::can_pack_name( $path ) ) {
			return self::build_raw_header( self::pack_name( $path ), $size, $mtime, $mode, $typeflag );
		}
		if ( strlen( $path ) > self::LONG_NAME_MAX ) {
			self::abort( "unrepresentable ustar path: {$path}" );
		}
		$payload = $path . "\0";
		$padded  = $payload . str_repeat( "\0", ( 512 - ( strlen( $payload ) % 512 ) ) % 512 );
		$record  = self::build_raw_header( self::pack_name( self::GNU_LONG_NAME ), strlen( $payload ), 0, 0644, 'L' ) . $padded;
		// Placeholder name: the first 100 bytes, never NUL-led, so a reader
		// without long-name support still sees a well-formed header.
		$placeholder = array( str_pad( substr( $path, 0, 100 ), 100, "\0" ), str_repeat( "\0", 155 ) );
		return $record . self::build_raw_header( $placeholder, $size, $mtime, $mode, $typeflag );
	}

	private static function build_raw_header( $name_parts, $size, $mtime, $mode, $typeflag ) {
		$header  = $name_parts[0];                                              // name:     0-99   (100)
		$header .= str_pad( decoct( $mode ), 7, '0', STR_PAD_LEFT ) . "\0";     // mode:   100-107    (8)
		$header .= str_pad( decoct( 0 ), 7, '0', STR_PAD_LEFT ) . "\0";         // uid:    108-115    (8)
		$header .= str_pad( decoct( 0 ), 7, '0', STR_PAD_LEFT ) . "\0";         // gid:    116-123    (8)
		$header .= str_pad( decoct( $size ), 11, '0', STR_PAD_LEFT ) . "\0";    // size:   124-135   (12)
		$header .= str_pad( decoct( $mtime ), 11, '0', STR_PAD_LEFT ) . "\0";   // mtime:  136-147   (12)
		$header .= '        ';                                                  // chksum: 148-155    (8) placeholder
		$header .= $typeflag;                                                   // type:   156        (1)
		$header .= str_repeat( "\0", 100 );                                     // link:   157-256  (100)
		$header .= "ustar\0";                                                   // magic:  257-262    (6)
		$header .= '00';                                                        // ver:    263-264    (2)
		$header .= str_pad( '', 32, "\0" );                                     // uname:  265-296   (32)
		$header .= str_pad( '', 32, "\0" );                                     // gname:  297-328   (32)
		$header .= str_repeat( "\0", 8 );                                       // devmaj: 329-336    (8)
		$header .= str_repeat( "\0", 8 );                                       // devmin: 337-344    (8)
		$header .= $name_parts[1];                                              // prefix: 345-499  (155)
		$header .= str_repeat( "\0", 12 );                                      // pad:    500-511   (12)

		$checksum = 0;
		for ( $i = 0; $i < 512; $i++ ) {
			$checksum += ord( $header[ $i ] );
		}
		$ckstr  = str_pad( decoct( $checksum ), 6, '0', STR_PAD_LEFT ) . "\0 ";
		$header = substr_replace( $header, $ckstr, 148, 8 );

		return $header;
	}

	private static function abort( $message = 'Sandbox Sync tar export aborted.' ) {
		error_log( 'sandbox-sync tar export: aborting - ' . $message );
		if ( ! headers_sent() ) {
			http_response_code( 500 );
			echo $message;
		}
		exit;
	}

	private static function stream_file( $rel_path, $real_path, $mtime, $checkpoint = null ) {
		self::require_checkpoint( $checkpoint );
		$fh = @fopen( $real_path, 'rb' );
		if ( ! $fh ) {
			self::abort( "cannot open {$rel_path}" );
		}

		$stat = fstat( $fh );
		if ( ! is_array( $stat ) || ! isset( $stat['size'] ) ) {
			fclose( $fh );
			self::abort( "cannot stat {$rel_path}" );
		}
		$size = $stat['size'];

		$mode         = isset( $stat['mode'] ) ? (int) $stat['mode'] & 0777 : 0644;
		$header_mtime = isset( $stat['mtime'] ) ? (int) $stat['mtime'] : (int) $mtime;
		echo self::build_header( $rel_path, $size, $header_mtime, $mode, '0' );

		$written = 0;
		while ( $written < $size && ! feof( $fh ) ) {
			self::require_checkpoint( $checkpoint );
			$chunk = fread( $fh, 524288 );
			if ( false === $chunk ) {
				fclose( $fh );
				self::abort( "read error on {$rel_path}" );
			}
			echo $chunk;
			$written += strlen( $chunk );
		}
		$after = fstat( $fh );
		fclose( $fh );
		self::require_checkpoint( $checkpoint );

		if ( $written !== $size || ! is_array( $after ) || (int) $after['size'] !== (int) $stat['size'] || (int) $after['mtime'] !== (int) $stat['mtime'] ) {
			self::abort( "short read on {$rel_path}" );
		}

		$remainder = $size % 512;
		if ( $remainder > 0 ) {
			echo str_repeat( "\0", 512 - $remainder );
		}

		flush();
	}

	/** Streams one file once while deriving the manifest entry from its bytes. */
	private static function stream_file_hashed( $file, $expected ) {
		$fh = @fopen( $file['full'], 'rb' );
		if ( ! $fh ) {
			self::abort( "cannot open {$file['path']}" );
		}
		$before = fstat( $fh );
		if ( ! is_array( $before ) || (int) $before['size'] !== (int) $expected['s'] || (int) $before['mtime'] !== (int) $expected['m']
			|| ( isset( $expected['o'] ) && ( (int) $before['mode'] & 0777 ) !== (int) $expected['o'] ) ) {
			fclose( $fh );
			self::abort( "source metadata changed before reading {$file['path']}" );
		}

		$size = (int) $before['size'];
		$mode = (int) $before['mode'] & 0777;
		echo self::build_header( $file['path'], $size, (int) $before['mtime'], $mode, '0' );
		$hash    = hash_init( 'sha256' );
		$written = 0;
		while ( $written < $size && ! feof( $fh ) ) {
			$chunk = fread( $fh, min( 524288, $size - $written ) );
			if ( false === $chunk || '' === $chunk ) {
				fclose( $fh );
				self::abort( "short read on {$file['path']}" );
			}
			hash_update( $hash, $chunk );
			echo $chunk;
			$written += strlen( $chunk );
		}
		$after = fstat( $fh );
		fclose( $fh );
		if ( $written !== $size || ! is_array( $after ) || (int) $after['size'] !== $size || (int) $after['mtime'] !== (int) $before['mtime'] ) {
			self::abort( "source changed while streaming {$file['path']}" );
		}
		$remainder = $size % 512;
		if ( $remainder > 0 ) {
			echo str_repeat( "\0", 512 - $remainder );
		}
		flush();
		return array(
			'p' => $file['path'],
			's' => $size,
			'm' => (int) $before['mtime'],
			'o' => $mode,
			'h' => hash_final( $hash ),
		);
	}

	private static function stream_string( $path, $content, $mtime, $mode ) {
		$size = strlen( $content );
		echo self::build_header( $path, $size, $mtime, $mode, '0' );
		echo $content;
		$remainder = $size % 512;
		if ( $remainder > 0 ) {
			echo str_repeat( "\0", 512 - $remainder );
		}
	}

	private static function stream_directory_recursive( $base_dir, $rel_prefix, $checkpoint = null ) {
		self::require_checkpoint( $checkpoint );
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator(
				$base_dir,
				\RecursiveDirectoryIterator::SKIP_DOTS
			),
			\RecursiveIteratorIterator::SELF_FIRST
		);

		$dir_mtime = @filemtime( $base_dir );
		$dir_mode  = @fileperms( $base_dir );
		echo self::build_header( $rel_prefix . '/', 0, $dir_mtime ? $dir_mtime : 0, false !== $dir_mode ? $dir_mode & 0777 : 0755, '5' );

		foreach ( $iterator as $item ) {
			self::require_checkpoint( $checkpoint );
			$rel_path = $rel_prefix . '/' . substr( $item->getPathname(), strlen( $base_dir ) + 1 );

			if ( $item->isLink() ) {
				continue;
			}

			if ( $item->isDir() ) {
				echo self::build_header( $rel_path . '/', 0, $item->getMTime(), $item->getPerms() & 0777, '5' );
				flush();
				continue;
			}

			if ( ! $item->isFile() ) {
				continue;
			}

			self::stream_file( $rel_path, $item->getPathname(), $item->getMTime(), $checkpoint );
		}
	}
}
