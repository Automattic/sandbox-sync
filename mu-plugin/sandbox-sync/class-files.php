<?php
/**
 * File-level sync helpers: directory scanning (source + sandbox use the same
 * walk rules), SHA-256/metadata diffing, staged extraneous-file deletion,
 * and batching for chunked downloads.
 *
 * Symlinks are invisible to every operation here: symlinked entries are
 * neither listed, nor fetched, nor deleted.
 */

namespace SandboxSync;

defined( 'ABSPATH' ) || exit;

class Files {

	// Batch limits for chunked downloads.
	const BATCH_MAX_BYTES    = 33554432; // 32 MB
	const BATCH_MAX_FILES    = 400;
	const MAX_MANIFEST_FILES = 2000000;

	/**
	 * Lists all regular files under $base_dir (recursively), skipping
	 * symlinks at any depth.
	 *
	 * @return array[] Each entry: [ 'p' => relative path, 's' => size, 'm' => mtime, 'o' => mode ].
	 */
	public static function scan( $base_dir, $with_hashes = false ) {
		return iterator_to_array( self::walk( $base_dir, $with_hashes ), false );
	}

	/** Returns total regular-file bytes without materializing the tree. */
	public static function tree_bytes( $base_dir ) {
		$total = 0;
		foreach ( self::walk( $base_dir, false ) as $file ) {
			$total += (int) $file['s'];
		}
		return $total;
	}

	/**
	 * Stat-only bytes/files totals per uploads partition, used to show
	 * "copied / total" progress for first-baseline streams. Scopes are
	 * disjoint by construction, so each file is counted exactly once.
	 *
	 * @param string   $base_dir Uploads directory.
	 * @param string[] $scopes   YYYY/MM scopes and/or "other".
	 * @return array<string,array{bytes:int,files:int}>
	 */
	public static function upload_scope_totals( $base_dir, $scopes ) {
		$totals = array();
		foreach ( array_values( array_unique( (array) $scopes ) ) as $scope ) {
			$bytes = 0;
			$files = 0;
			foreach ( self::walk_upload_scope( $base_dir, $scope, false ) as $file ) {
				$bytes += (int) $file['s'];
				++$files;
			}
			$totals[ $scope ] = array(
				'bytes' => $bytes,
				'files' => $files,
			);
		}
		return $totals;
	}

	/** Copies one staged file without changing the metadata used by manifests. */
	public static function copy_preserving_metadata( $source, $destination ) {
		$mode  = @fileperms( $source );
		$mtime = @filemtime( $source );
		$size  = @filesize( $source );
		if ( false === $mode || false === $mtime || false === $size ) {
			return new \WP_Error( 'stage_stat', "Cannot read source metadata for {$source}." );
		}
		if ( ! @copy( $source, $destination ) ) {
			return new \WP_Error( 'stage_copy', "Cannot stage unchanged file {$source}." );
		}
		if ( ! @chmod( $destination, $mode & 0777 ) || ! @touch( $destination, $mtime ) ) {
			@unlink( $destination );
			return new \WP_Error( 'stage_metadata', "Cannot preserve staged file metadata for {$source}." );
		}
		clearstatcache( true, $destination );
		if ( @filesize( $destination ) !== $size || ( @fileperms( $destination ) & 0777 ) !== ( $mode & 0777 ) || @filemtime( $destination ) !== $mtime ) {
			@unlink( $destination );
			return new \WP_Error( 'stage_metadata', "Staged file metadata does not match {$source}." );
		}
		return true;
	}

	/**
	 * Memory-bounded, path-sorted file iterator used by streamed manifests and
	 * merge-diffing. Symlinked entries are never followed.
	 *
	 * @param string        $base_dir    Directory to walk.
	 * @param bool          $with_hashes Whether to hash each file.
	 * @param string        $prefix      Relative path of $base_dir, used by the recursion.
	 * @param string[]|null $only_tops   Top-level entries to walk, or null for all. Entries
	 *                                   a sync leaves alone are skipped whole, unread. The
	 *                                   recursion does not pass it on, so it never applies
	 *                                   below the top level.
	 */
	public static function walk( $base_dir, $with_hashes = false, $prefix = '', $only_tops = null ) {
		if ( ! is_dir( $base_dir ) || is_link( $base_dir ) ) {
			return;
		}
		$entries = scandir( $base_dir );
		if ( false === $entries ) {
			return;
		}
		sort( $entries, SORT_STRING );
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			if ( is_array( $only_tops ) && ! in_array( $entry, $only_tops, true ) ) {
				continue;
			}
			$full = $base_dir . '/' . $entry;
			$path = '' === $prefix ? $entry : $prefix . '/' . $entry;
			if ( is_link( $full ) ) {
				continue;
			}
			if ( is_file( $full ) ) {
				$file = array(
					'p' => $path,
					's' => (int) filesize( $full ),
					'm' => (int) filemtime( $full ),
					'o' => (int) ( fileperms( $full ) & 0777 ),
				);
				if ( $with_hashes ) {
					$file['h'] = hash_file( 'sha256', $full );
				}
				yield $file;
			} elseif ( is_dir( $full ) ) {
				foreach ( self::walk( $full, $with_hashes, $path ) as $file ) {
					yield $file;
				}
			}
		}
	}

	/**
	 * Walks one uploads partition without descending into unrelated months.
	 * "other" contains every path that is not below an exact YYYY/MM pair.
	 */
	public static function walk_upload_scope( $base_dir, $scope, $with_hashes = false ) {
		if ( ! Uploads_State::valid_scope( $scope ) || ! is_dir( $base_dir ) || is_link( $base_dir ) ) {
			return;
		}
		if ( 'other' !== $scope ) {
			$scoped = $base_dir . '/' . $scope;
			foreach ( self::walk( $scoped, $with_hashes, $scope ) as $file ) {
				yield $file;
			}
			return;
		}

		$entries = @scandir( $base_dir );
		if ( false === $entries ) {
			return;
		}
		sort( $entries, SORT_STRING );
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$full = $base_dir . '/' . $entry;
			if ( is_link( $full ) ) {
				continue;
			}
			if ( is_file( $full ) ) {
				foreach ( self::walk_single_file( $full, $entry, $with_hashes ) as $file ) {
					yield $file;
				}
				continue;
			}
			if ( ! is_dir( $full ) ) {
				continue;
			}
			if ( ! preg_match( '/^\d{4}$/', $entry ) ) {
				foreach ( self::walk( $full, $with_hashes, $entry ) as $file ) {
					yield $file;
				}
				continue;
			}

			// A year may contain loose files or non-month directories. Include
			// those in "other" while skipping exact month subtrees entirely.
			$children = @scandir( $full );
			if ( false === $children ) {
				continue;
			}
			sort( $children, SORT_STRING );
			foreach ( $children as $child ) {
				if ( '.' === $child || '..' === $child ) {
					continue;
				}
				$child_full = $full . '/' . $child;
				if ( is_link( $child_full ) || ( preg_match( '/^(0[1-9]|1[0-2])$/', $child ) && is_dir( $child_full ) ) ) {
					continue;
				}
				$prefix = $entry . '/' . $child;
				if ( is_file( $child_full ) ) {
					foreach ( self::walk_single_file( $child_full, $prefix, $with_hashes ) as $file ) {
						yield $file;
					}
				} elseif ( is_dir( $child_full ) ) {
					foreach ( self::walk( $child_full, $with_hashes, $prefix ) as $file ) {
						yield $file;
					}
				}
			}
		}
	}

	private static function walk_single_file( $full, $path, $with_hashes ) {
		$file = array(
			'p' => $path,
			's' => (int) filesize( $full ),
			'm' => (int) filemtime( $full ),
			'o' => (int) ( fileperms( $full ) & 0777 ),
		);
		if ( $with_hashes ) {
			$file['h'] = hash_file( 'sha256', $full );
		}
		yield $file;
	}

	public static function read_ndjson_manifest( $file ) {
		$fh = @fopen( $file, 'rb' );
		if ( ! $fh ) {
			return;
		}
		while ( false !== ( $line = fgets( $fh ) ) ) {
			$entry = json_decode( $line, true );
			if ( is_array( $entry ) && isset( $entry['p'], $entry['s'], $entry['m'] ) && Tar_Exporter::is_safe_relative_path( $entry['p'] ) ) {
				yield $entry;
			}
		}
		fclose( $fh );
	}

	/** @return int|\WP_Error Valid entry count. */
	public static function validate_ndjson_manifest( $file, $require_hashes = false ) {
		$fh = @fopen( $file, 'rb' );
		if ( ! $fh ) {
			return new \WP_Error( 'manifest_open', 'Cannot open streamed file manifest.' );
		}
		$count     = 0;
		$max_files = max( 1, (int) apply_filters( 'sandbox_sync_max_manifest_files', self::MAX_MANIFEST_FILES ) );
		while ( false !== ( $line = fgets( $fh, 1048577 ) ) ) {
			if ( strlen( $line ) > 1048576 || ( ! feof( $fh ) && "\n" !== substr( $line, -1 ) ) ) {
				fclose( $fh );
				return new \WP_Error( 'manifest_line', 'Streamed file manifest contains an oversized line.' );
			}
			$entry = json_decode( $line, true );
			if ( ! is_array( $entry ) || ! isset( $entry['p'], $entry['s'], $entry['m'] )
				|| ! self::is_valid_manifest_entry( $entry, $require_hashes ) ) {
				fclose( $fh );
				return new \WP_Error( 'manifest_data', 'Streamed file manifest contains a malformed entry.' );
			}
			++$count;
			if ( $count > $max_files ) {
				fclose( $fh );
				return new \WP_Error( 'manifest_count', 'Streamed file manifest exceeds the configured entry limit.' );
			}
		}
		fclose( $fh );
		return $count;
	}

	private static function is_valid_manifest_entry( $entry, $require_hashes ) {
		if ( ! Tar_Exporter::is_safe_relative_path( $entry['p'] ) || ! is_numeric( $entry['s'] ) || ! is_numeric( $entry['m'] ) || (int) $entry['s'] < 0 || (int) $entry['m'] < 0 ) {
			return false;
		}
		if ( isset( $entry['o'] ) && ( ! is_numeric( $entry['o'] ) || (int) $entry['o'] < 0 || (int) $entry['o'] > 0777 ) ) {
			return false;
		}
		if ( $require_hashes && ( empty( $entry['h'] ) || ! preg_match( '/^[a-f0-9]{64}$/', (string) $entry['h'] ) ) ) {
			return false;
		}
		return true;
	}

	public static function filter_iterator( $iterator, $predicate ) {
		foreach ( $iterator as $entry ) {
			if ( call_user_func( $predicate, $entry ) ) {
				yield $entry;
			}
		}
	}

	/**
	 * Compares paths in the component-by-component depth-first order emitted by
	 * walk(). A plain strcmp is not sufficient: "a/child" is emitted before
	 * "a.txt", even though those complete strings sort in the opposite order.
	 */
	public static function compare_paths( $left, $right ) {
		$left_parts  = explode( '/', (string) $left );
		$right_parts = explode( '/', (string) $right );
		$length      = min( count( $left_parts ), count( $right_parts ) );
		for ( $i = 0; $i < $length; $i++ ) {
			$compared = strcmp( $left_parts[ $i ], $right_parts[ $i ] );
			if ( 0 !== $compared ) {
				return $compared;
			}
		}
		return count( $left_parts ) <=> count( $right_parts );
	}

	/**
	 * Merge-diffs two path-sorted iterators without materializing either tree.
	 * Callbacks receive the complete source entry or the local relative path.
	 * Returning false from a callback (including the optional progress callback)
	 * stops the scan so callers can honor cancellation or a lost execution lease.
	 *
	 * @return bool True when the complete diff was consumed, false when stopped.
	 */
	public static function diff_iterators( $source, $local, $on_fetch, $on_delete, $force_fetch = false, $on_progress = null ) {
		$source->rewind();
		$local->rewind();
		while ( $source->valid() || $local->valid() ) {
			if ( null !== $on_progress && false === call_user_func( $on_progress ) ) {
				return false;
			}
			$s = $source->valid() ? $source->current() : null;
			$l = $local->valid() ? $local->current() : null;
			if ( null === $l || ( null !== $s && self::compare_paths( $s['p'], $l['p'] ) < 0 ) ) {
				if ( false === call_user_func( $on_fetch, $s ) ) {
					return false;
				}
				$source->next();
				continue;
			}
			if ( null === $s || self::compare_paths( $l['p'], $s['p'] ) < 0 ) {
				if ( false === call_user_func( $on_delete, $l['p'] ) ) {
					return false;
				}
				$local->next();
				continue;
			}
			if ( true === $force_fetch
				|| ( is_callable( $force_fetch ) && call_user_func( $force_fetch, $s ) )
				|| (int) $s['s'] !== (int) $l['s']
				|| (int) $s['m'] !== (int) $l['m']
				|| ( isset( $s['o'] ) && ( ! isset( $l['o'] ) || (int) $s['o'] !== (int) $l['o'] ) )
				|| ( isset( $s['h'] ) && ( ! isset( $l['h'] ) || ! hash_equals( (string) $s['h'], (string) $l['h'] ) ) ) ) {
				if ( false === call_user_func( $on_fetch, $s ) ) {
					return false;
				}
			}
			$source->next();
			$local->next();
		}
		return true;
	}

	/**
	 * Diffs a source file list against a local one.
	 *
	 * A file needs fetching when it is missing locally or when size or mtime
	 * differ (the tar extractor restores source mtimes, so unchanged synced
	 * files compare equal on the next run). Local files absent from the
	 * source are extraneous and get deleted.
	 *
	 * @param array[] $source_files From the source's files manifest.
	 * @param array[] $local_files  From self::scan().
	 * @return array [ 'fetch' => [ [p,s,m], ... ], 'delete' => [ path, ... ] ]
	 */
	public static function diff( $source_files, $local_files ) {
		$local_by_path = array();
		foreach ( $local_files as $file ) {
			$local_by_path[ $file['p'] ] = $file;
		}

		$fetch = array();
		foreach ( $source_files as $file ) {
			$path = $file['p'];
			if ( ! isset( $local_by_path[ $path ] )
				|| (int) $local_by_path[ $path ]['s'] !== (int) $file['s']
				|| (int) $local_by_path[ $path ]['m'] !== (int) $file['m']
				|| ( isset( $file['o'] ) && ( ! isset( $local_by_path[ $path ]['o'] ) || (int) $local_by_path[ $path ]['o'] !== (int) $file['o'] ) )
				|| ( isset( $file['h'] ) && ( ! isset( $local_by_path[ $path ]['h'] ) || ! hash_equals( (string) $file['h'], (string) $local_by_path[ $path ]['h'] ) ) ) ) {
				$fetch[] = $file;
			}
			unset( $local_by_path[ $path ] );
		}

		return array(
			'fetch'  => $fetch,
			'delete' => array_keys( $local_by_path ),
		);
	}

	/**
	 * Keeps only files whose top-level path segment is in $tops. Pure
	 * function; used for per-plugin sync selection.
	 *
	 * @param array[]  $files Entries with a 'p' relative path.
	 * @param string[] $tops  Allowed top-level entries (dir or file names).
	 */
	public static function filter_top_level( $files, $tops ) {
		$allowed = array_flip( $tops );
		return array_values(
			array_filter(
				$files,
				function ( $file ) use ( $allowed ) {
					return isset( $allowed[ strtok( $file['p'], '/' ) ] );
				}
			)
		);
	}

	/**
	 * Drops files whose top-level path segment is in $tops (inverse of
	 * filter_top_level; used to protect sandbox-local plugins).
	 */
	public static function exclude_top_level( $files, $tops ) {
		$excluded = array_flip( $tops );
		return array_values(
			array_filter(
				$files,
				function ( $file ) use ( $excluded ) {
					return ! isset( $excluded[ strtok( $file['p'], '/' ) ] );
				}
			)
		);
	}

	/**
	 * Top-level layout of a code directory, by entry name. Both sites read it
	 * to tell what a sync delivered apart from what only the sandbox has: a
	 * symlink never travels, so its name must not count as a synced entry.
	 *
	 * @param string $base_dir Directory to list. Never followed when it is a symlink.
	 * @return array{dirs:string[],files:string[],links:string[]} Sorted names.
	 */
	public static function top_level_listing( $base_dir ) {
		$listing = array(
			'dirs'  => array(),
			'files' => array(),
			'links' => array(),
		);
		if ( ! is_dir( $base_dir ) || is_link( $base_dir ) ) {
			return $listing;
		}
		$entries = scandir( $base_dir );
		if ( false === $entries ) {
			return $listing;
		}
		sort( $entries, SORT_STRING );
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$full = $base_dir . '/' . $entry;
			if ( is_link( $full ) ) {
				$listing['links'][] = $entry;
			} elseif ( is_dir( $full ) ) {
				$listing['dirs'][] = $entry;
			} elseif ( is_file( $full ) ) {
				$listing['files'][] = $entry;
			}
		}
		return $listing;
	}

	/**
	 * Unique top-level path segments of a file manifest, sorted.
	 *
	 * @param iterable $files Entries with a 'p' relative path.
	 * @return string[]
	 */
	public static function top_level_names( $files ) {
		$tops = array();
		$last = null;
		foreach ( $files as $file ) {
			if ( ! isset( $file['p'] ) || ! is_string( $file['p'] ) || '' === $file['p'] ) {
				continue;
			}
			$top = (string) strtok( $file['p'], '/' );
			// Manifests are path-sorted, so one comparison drops nearly every repeat.
			if ( $top !== $last ) {
				$tops[] = $top;
				$last   = $top;
			}
		}
		$tops = array_values( array_unique( $tops ) );
		sort( $tops, SORT_STRING );
		return $tops;
	}

	/**
	 * Relative symlink paths below a directory. These prefixes are protected
	 * from both fetch and delete operations so extraction never crosses a local
	 * development-repository link.
	 */
	public static function symlink_paths( $base_dir ) {
		$links = array();
		if ( ! is_dir( $base_dir ) ) {
			return $links;
		}
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $base_dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::SELF_FIRST
		);
		foreach ( $iterator as $item ) {
			if ( $item->isLink() ) {
				$links[] = substr( $item->getPathname(), strlen( rtrim( $base_dir, '/' ) ) + 1 );
			}
		}
		sort( $links );
		return $links;
	}

	/**
	 * Removes entries equal to or nested below any protected relative prefix.
	 */
	public static function exclude_path_prefixes( $files, $prefixes ) {
		if ( empty( $prefixes ) ) {
			return array_values( $files );
		}
		return array_values(
			array_filter(
				$files,
				function ( $file ) use ( $prefixes ) {
					$path = isset( $file['p'] ) ? $file['p'] : '';
					foreach ( $prefixes as $prefix ) {
						if ( $path === $prefix || 0 === strpos( $path, rtrim( $prefix, '/' ) . '/' ) ) {
							return false;
						}
					}
					return true;
				}
			)
		);
	}

	/**
	 * Keeps only uploads-directory files matching the selected date scopes.
	 * Pure function; used for the year/month uploads selector.
	 *
	 * Scopes: 'YYYY' (whole year), 'YYYY/MM' (one month), 'other'
	 * (everything not under a YYYY/ directory: root files, custom dirs).
	 * Files directly under a year (e.g. 2026/stray.jpg) belong to the year:
	 * they are kept when the whole year or any of its months is selected.
	 *
	 * @param array[]  $files  Entries with a 'p' relative path.
	 * @param string[] $scopes Selected scopes.
	 */
	public static function filter_upload_scopes( $files, $scopes ) {
		$selected = array_flip( $scopes );
		$years    = array();
		foreach ( $scopes as $scope ) {
			if ( preg_match( '#^(\d{4})(/|$)#', $scope, $m ) ) {
				$years[ $m[1] ] = true;
			}
		}

		return array_values(
			array_filter(
				$files,
				function ( $file ) use ( $selected, $years ) {
					if ( preg_match( '#^(\d{4})/(\d{2})/#', $file['p'], $m ) ) {
						return isset( $selected[ $m[1] ] ) || isset( $selected[ $m[1] . '/' . $m[2] ] );
					}
					if ( preg_match( '#^(\d{4})/#', $file['p'], $m ) ) {
						return isset( $years[ $m[1] ] );
					}
					return isset( $selected['other'] );
				}
			)
		);
	}

	/**
	 * Splits a fetch list into batches capped by bytes and file count.
	 *
	 * @param array[] $fetch_files Entries from diff()['fetch'].
	 * @return array[] Array of batches; each batch is a list of relative paths.
	 */
	public static function batch( $fetch_files ) {
		$batches = array();
		$current = array();
		$bytes   = 0;

		foreach ( $fetch_files as $file ) {
			$current[] = $file['p'];
			$bytes    += (int) $file['s'];
			if ( count( $current ) >= self::BATCH_MAX_FILES || $bytes >= self::BATCH_MAX_BYTES ) {
				$batches[] = $current;
				$current   = array();
				$bytes     = 0;
			}
		}
		if ( ! empty( $current ) ) {
			$batches[] = $current;
		}

		return $batches;
	}

	/**
	 * Deletes extraneous local files (never symlinks), pruning directories
	 * that end up empty. Paths are validated against traversal.
	 *
	 * @param string   $base_dir Directory the paths are relative to.
	 * @param string[] $paths    Relative paths from diff()['delete'].
	 * @return int Number of deleted files.
	 */
	public static function delete_paths( $base_dir, $paths ) {
		$deleted = 0;
		foreach ( $paths as $path ) {
			if ( ! is_string( $path ) || '' === $path || false !== strpos( $path, '..' ) || 0 === strpos( $path, '/' ) ) {
				continue;
			}
			$full = $base_dir . '/' . $path;
			if ( is_link( $full ) || ! is_file( $full ) ) {
				continue;
			}
			if ( unlink( $full ) ) {
				++$deleted;
				// Prune now-empty parent directories up to (not including) base.
				$parent = dirname( $full );
				while ( strlen( $parent ) > strlen( $base_dir ) && is_dir( $parent ) ) {
					$rest = array_diff( (array) scandir( $parent ), array( '.', '..' ) );
					if ( ! empty( $rest ) ) {
						break;
					}
					if ( ! rmdir( $parent ) ) {
						break;
					}
					$parent = dirname( $parent );
				}
			}
		}
		return $deleted;
	}

	/**
	 * Destructively clears only the selected first-sync uploads partitions.
	 * Symlinks are never followed or removed. Exact month directories can be
	 * removed as a unit; "other" preserves every exact YYYY/MM directory.
	 *
	 * @return int|\WP_Error Number of regular files removed.
	 */
	public static function clear_upload_scopes( $base_dir, $scopes, $checkpoint = null ) {
		if ( is_link( $base_dir ) || ( ! is_dir( $base_dir ) && ! mkdir( $base_dir, 0755, true ) ) ) {
			return new \WP_Error( 'uploads_bulk_base', 'Cannot create a safe uploads directory for the baseline stream.' );
		}
		$base = realpath( $base_dir );
		if ( false === $base ) {
			return new \WP_Error( 'uploads_bulk_base', 'Cannot resolve the uploads directory for the baseline stream.' );
		}
		$deleted = 0;
		foreach ( array_values( array_unique( (array) $scopes ) ) as $scope ) {
			if ( ! Uploads_State::valid_scope( $scope ) ) {
				return new \WP_Error( 'uploads_bulk_scope', 'Cannot clear an invalid uploads partition.' );
			}
			if ( 'other' !== $scope ) {
				$parts = explode( '/', $scope, 2 );
				$year  = $base . '/' . $parts[0];
				$path  = $year . '/' . $parts[1];
				if ( is_link( $year ) || is_link( $path ) ) {
					continue;
				}
				$result = self::delete_tree_without_links( $path, $deleted, $checkpoint );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				continue;
			}

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
						$child_path = $full . '/' . $child;
						if ( is_link( $child_path ) || ( preg_match( '/^(0[1-9]|1[0-2])$/', $child ) && is_dir( $child_path ) ) ) {
							continue;
						}
						$result = self::delete_tree_without_links( $child_path, $deleted, $checkpoint );
						if ( is_wp_error( $result ) ) {
							return $result;
						}
					}
				} else {
					$result = self::delete_tree_without_links( $full, $deleted, $checkpoint );
					if ( is_wp_error( $result ) ) {
						return $result;
					}
				}
			}
		}
		return $deleted;
	}

	/** @return true|\WP_Error */
	private static function delete_tree_without_links( $path, &$deleted, $checkpoint ) {
		$stat = @lstat( $path );
		if ( false === $stat || is_link( $path ) ) {
			return true;
		}
		if ( is_file( $path ) ) {
			if ( ! unlink( $path ) ) {
				return new \WP_Error( 'uploads_bulk_clear', "Cannot remove existing baseline upload {$path}." );
			}
			++$deleted;
			if ( is_callable( $checkpoint ) && 0 === $deleted % self::BATCH_MAX_FILES ) {
				$result = call_user_func( $checkpoint, $deleted );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}
			return true;
		}
		if ( ! is_dir( $path ) ) {
			return true;
		}
		foreach ( (array) @scandir( $path ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$result = self::delete_tree_without_links( $path . '/' . $entry, $deleted, $checkpoint );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		$remaining = array_diff( (array) @scandir( $path ), array( '.', '..' ) );
		if ( empty( $remaining ) ) {
			@rmdir( $path );
		}
		return true;
	}

	/** Creates an empty streamed directory without crossing a local symlink. */
	public static function install_streamed_directory( $base_dir, $path, $mode, $mtime ) {
		$dir = self::ensure_install_directory( $base_dir, $path );
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}
		@chmod( $dir, $mode > 0 ? $mode : 0755 );
		if ( $mtime > 0 ) {
			@touch( $dir, $mtime );
		}
		return true;
	}

	/** Installs a size-checked first-baseline file without a second hash/read. */
	public static function install_streamed_file( $base_dir, $source_file, $entry ) {
		if ( ! is_array( $entry ) || ! isset( $entry['p'], $entry['s'], $entry['m'] )
			|| ! self::is_valid_manifest_entry( $entry, false ) || ! is_file( $source_file ) || is_link( $source_file ) ) {
			return new \WP_Error( 'file_install_data', 'Streamed baseline file metadata or content is malformed.' );
		}
		$actual_size = @filesize( $source_file );
		if ( false === $actual_size || (int) $entry['s'] !== (int) $actual_size ) {
			return new \WP_Error( 'file_install_size', 'Streamed baseline file failed its final size check.' );
		}
		return self::install_prepared_file( $base_dir, $source_file, $entry, false );
	}

	/**
	 * Atomically moves a completely downloaded, content-verified file into a
	 * destination tree. The source must be on the same filesystem as the tree.
	 * Every path component is checked with lstat so a local symlink can never
	 * redirect the write outside the intended tree.
	 *
	 * @param string $base_dir    Destination directory.
	 * @param string $source_file Completed download in the same work root.
	 * @param array  $entry       Hashed source manifest entry.
	 * @return true|\WP_Error
	 */
	public static function install_verified_file( $base_dir, $source_file, $entry ) {
		if ( ! is_array( $entry ) || ! isset( $entry['p'], $entry['s'], $entry['m'], $entry['h'] )
			|| ! self::is_valid_manifest_entry( $entry, true ) || ! is_file( $source_file ) || is_link( $source_file ) ) {
			return new \WP_Error( 'file_install_data', 'Resumable file metadata or downloaded content is malformed.' );
		}
		$actual_size = filesize( $source_file );
		$actual_hash = hash_file( 'sha256', $source_file );
		if ( false === $actual_size || (int) $entry['s'] !== (int) $actual_size || false === $actual_hash || ! hash_equals( (string) $entry['h'], $actual_hash ) ) {
			return new \WP_Error( 'file_install_hash', 'Resumable file failed its final size or SHA-256 verification.' );
		}

		return self::install_prepared_file( $base_dir, $source_file, $entry, true );
	}

	/** @return true|\WP_Error */
	private static function install_prepared_file( $base_dir, $source_file, $entry, $durable ) {
		$base = realpath( $base_dir );
		if ( false === $base || ! is_dir( $base ) || is_link( $base_dir ) ) {
			return new \WP_Error( 'file_install_base', 'File destination directory is missing or unsafe.' );
		}
		$parts = explode( '/', $entry['p'] );
		$name  = array_pop( $parts );
		$dir   = self::ensure_install_directory( $base, implode( '/', $parts ) );
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$target = $dir . DIRECTORY_SEPARATOR . $name;
		$stat   = @lstat( $target );
		if ( false !== $stat && ( is_link( $target ) || ! is_file( $target ) ) ) {
			return new \WP_Error( 'file_install_target', "Cannot safely replace destination file {$entry['p']}." );
		}
		// Apply metadata before the rename so the visible destination changes in
		// one same-filesystem operation. On POSIX, rename replaces an existing
		// regular file atomically; deliberately never unlink the old file first.
		$mode = isset( $entry['o'] ) ? (int) $entry['o'] : 0644;
		if ( ! chmod( $source_file, $mode ) || ! touch( $source_file, (int) $entry['m'] ) ) {
			return new \WP_Error( 'file_install_metadata', "Cannot restore mode or mtime for downloaded file {$entry['p']}." );
		}
		if ( $durable ) {
			// Incremental cursor commits retain the stronger fsync guarantee. The
			// Knife-like baseline avoids one fsync per media file for throughput;
			// a crash simply retries the uncommitted year.
			$fh = @fopen( $source_file, 'r+b' );
			if ( ! $fh ) {
				return new \WP_Error( 'file_install_flush', "Cannot open verified file {$entry['p']} for a durable install." );
			}
			$flushed = fflush( $fh );
			if ( function_exists( 'fsync' ) ) {
				$flushed = @fsync( $fh ) && $flushed;
			}
			fclose( $fh );
			if ( ! $flushed ) {
				return new \WP_Error( 'file_install_flush', "Cannot durably flush verified file {$entry['p']}." );
			}
		}
		if ( ! rename( $source_file, $target ) ) {
			return new \WP_Error( 'file_install_move', "Cannot atomically move verified file {$entry['p']} into its destination." );
		}
		// Directory fsync is not supported by every PHP filesystem wrapper;
		// perform it where available without turning portability into failure.
		if ( $durable && function_exists( 'fsync' ) ) {
			$dir_handle = @fopen( $dir, 'r' );
			if ( $dir_handle ) {
				@fsync( $dir_handle );
				fclose( $dir_handle );
			}
		}
		return true;
	}

	/** @return string|\WP_Error */
	private static function ensure_install_directory( $base_dir, $relative ) {
		$base = realpath( $base_dir );
		if ( false === $base || ! is_dir( $base ) || is_link( $base_dir ) ) {
			return new \WP_Error( 'file_install_base', 'File destination directory is missing or unsafe.' );
		}
		$dir = $base;
		if ( '' === $relative ) {
			return $dir;
		}
		foreach ( explode( '/', $relative ) as $part ) {
			if ( '' === $part || '.' === $part || '..' === $part ) {
				return new \WP_Error( 'file_install_path', 'Destination directory path is unsafe.' );
			}
			$next = $dir . DIRECTORY_SEPARATOR . $part;
			$stat = @lstat( $next );
			if ( false === $stat ) {
				if ( ! mkdir( $next, 0755 ) ) {
					return new \WP_Error( 'file_install_mkdir', "Cannot create destination directory {$relative}." );
				}
			} elseif ( is_link( $next ) || ! is_dir( $next ) ) {
				return new \WP_Error( 'file_install_path', "Destination path {$relative} crosses a non-directory or symlink." );
			}
			$real = realpath( $next );
			if ( false === $real || 0 !== strpos( $real, rtrim( $base, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR ) ) {
				return new \WP_Error( 'file_install_escape', "Destination path {$relative} escapes its intended directory." );
			}
			$dir = $real;
		}
		return $dir;
	}

	/** Returns whether a destination path exactly matches a hashed manifest entry. */
	public static function manifest_entry_matches( $base_dir, $entry ) {
		if ( ! is_array( $entry ) || ! isset( $entry['p'], $entry['s'], $entry['m'], $entry['h'] ) || ! self::is_valid_manifest_entry( $entry, true ) ) {
			return false;
		}
		$base = realpath( $base_dir );
		if ( false === $base || is_link( $base_dir ) ) {
			return false;
		}
		$current = $base;
		foreach ( explode( '/', $entry['p'] ) as $part ) {
			$current .= DIRECTORY_SEPARATOR . $part;
			if ( false === @lstat( $current ) || is_link( $current ) ) {
				return false;
			}
		}
		$real = realpath( $current );
		if ( false === $real || ! is_file( $real ) || 0 !== strpos( $real, rtrim( $base, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR ) ) {
			return false;
		}
		$size = filesize( $real );
		$time = filemtime( $real );
		$mode = fileperms( $real );
		$hash = hash_file( 'sha256', $real );
		return false !== $size && false !== $time && false !== $mode && false !== $hash
			&& (int) $entry['s'] === (int) $size && (int) $entry['m'] === (int) $time
			&& ( ! isset( $entry['o'] ) || ( $mode & 0777 ) === (int) $entry['o'] )
			&& hash_equals( (string) $entry['h'], $hash );
	}

	/** Fast exact metadata match used before a changed file is payload-hashed. */
	public static function metadata_entry_matches( $base_dir, $entry ) {
		if ( ! is_array( $entry ) || ! isset( $entry['p'], $entry['s'], $entry['m'] ) || ! self::is_valid_manifest_entry( $entry, false ) ) {
			return false;
		}
		$base = realpath( $base_dir );
		if ( false === $base || is_link( $base_dir ) ) {
			return false;
		}
		$current = $base;
		foreach ( explode( '/', $entry['p'] ) as $part ) {
			$current .= DIRECTORY_SEPARATOR . $part;
			if ( false === @lstat( $current ) || is_link( $current ) ) {
				return false;
			}
		}
		$real = realpath( $current );
		if ( false === $real || ! is_file( $real ) || 0 !== strpos( $real, rtrim( $base, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR ) ) {
			return false;
		}
		$size = @filesize( $real );
		$time = @filemtime( $real );
		$mode = @fileperms( $real );
		return false !== $size && false !== $time && false !== $mode
			&& (int) $entry['s'] === (int) $size && (int) $entry['m'] === (int) $time
			&& ( ! isset( $entry['o'] ) || ( $mode & 0777 ) === (int) $entry['o'] );
	}
}
