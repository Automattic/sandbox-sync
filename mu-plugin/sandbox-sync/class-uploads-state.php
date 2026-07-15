<?php
/** Fast uploads change generations and per-sandbox synchronization cursors. */

namespace SandboxSync;

defined( 'ABSPATH' ) || exit;

class Uploads_State {

	const SCHEMA        = 1;
	const CURSOR_SCHEMA = 2;
	const PREFIX        = "<?php exit; ?>\n";
	const OPT_CURSOR    = 'sandbox_sync_uploads_cursor';
	const MAX_CHANGE_EVENTS = 20000;

	/** Registers best-effort generation tracking for WordPress-managed media. */
	public static function init() {
		add_filter( 'wp_handle_upload', array( __CLASS__, 'track_upload' ), 100, 1 );
		add_filter( 'wp_update_attachment_metadata', array( __CLASS__, 'track_attachment_metadata' ), 100, 2 );
		add_filter( 'update_attached_file', array( __CLASS__, 'track_attached_file' ), 100, 2 );
		add_filter( 'wp_delete_file', array( __CLASS__, 'track_deleted_file' ), 100, 1 );
		add_action( 'add_attachment', array( __CLASS__, 'track_attachment' ), 100, 1 );
		add_action( 'edit_attachment', array( __CLASS__, 'track_attachment' ), 100, 1 );
		add_action( 'delete_attachment', array( __CLASS__, 'track_attachment' ), 1, 1 );
	}

	public static function track_upload( $upload ) {
		if ( is_array( $upload ) && ! empty( $upload['file'] ) ) {
			self::mark_file( $upload['file'] );
		}
		return $upload;
	}

	public static function track_attachment_metadata( $metadata, $attachment_id ) {
		self::track_attachment( $attachment_id );
		return $metadata;
	}

	public static function track_attached_file( $file, $attachment_id ) {
		self::mark_file( $file );
		return $file;
	}

	public static function track_deleted_file( $file ) {
		self::mark_file( $file );
		return $file;
	}

	public static function track_attachment( $attachment_id ) {
		if ( function_exists( 'get_attached_file' ) ) {
			$file = get_attached_file( (int) $attachment_id, true );
			if ( is_string( $file ) && '' !== $file ) {
				self::mark_file( $file );
			}
		}
	}

	/** Marks the month containing a path. Tracking is deliberately best effort. */
	public static function mark_file( $file ) {
		$scope = self::scope_for_file( $file );
		$path  = self::relative_file_path( $file );
		if ( null === $scope || null === $path ) {
			return false;
		}
		$result = self::transaction(
			function ( $state ) use ( $scope, $path ) {
				$state['clock'] = max( (int) $state['clock'] + 1, time() );
				$state['generations'][ $scope ] = $state['clock'];
				$state['changes'][] = array(
					'g' => $state['clock'],
					's' => $scope,
					'p' => $path,
				);
				while ( count( $state['changes'] ) > self::MAX_CHANGE_EVENTS ) {
					$dropped = array_shift( $state['changes'] );
					if ( is_array( $dropped ) && isset( $dropped['s'], $dropped['g'] ) ) {
						$state['history_floor'][ $dropped['s'] ] = max( (int) ( $state['history_floor'][ $dropped['s'] ] ?? 0 ), (int) $dropped['g'] );
					}
				}
				return array(
					'state' => $state,
					'value' => $state['clock'],
				);
			}
		);
		return ! is_wp_error( $result );
	}

	/** Returns the monotonic generations and cheap date-partition inventory. */
	public static function snapshot() {
		$state = self::transaction(
			function ( $current ) {
				return array(
					'state' => $current,
					'value' => $current,
					'persist' => false,
				);
			}
		);
		if ( is_wp_error( $state ) ) {
			return $state;
		}
		$scopes = self::discover_scopes( self::uploads_dir() );
		foreach ( array_keys( $state['generations'] ) as $scope ) {
			if ( self::valid_scope( $scope ) && ! in_array( $scope, $scopes, true ) ) {
				$scopes[] = $scope;
			}
		}
		sort( $scopes, SORT_STRING );
		$generations = array();
		foreach ( $scopes as $scope ) {
			$generations[ $scope ] = isset( $state['generations'][ $scope ] ) ? (int) $state['generations'][ $scope ] : 0;
		}
		return array(
			'schema'        => self::SCHEMA,
			'scopes'        => $scopes,
			'generations'   => $generations,
			'current_scope' => self::current_scope(),
			'changes'       => array_values( $state['changes'] ),
			'history_floor' => $state['history_floor'],
		);
	}

	/** @return string[] */
	public static function discover_scopes( $base ) {
		$scopes = array( 'other', self::current_scope() );
		if ( ! is_dir( $base ) || is_link( $base ) ) {
			return array_values( array_unique( $scopes ) );
		}
		foreach ( (array) @scandir( $base ) as $year ) {
			if ( ! preg_match( '/^\d{4}$/', (string) $year ) || ! is_dir( $base . '/' . $year ) || is_link( $base . '/' . $year ) ) {
				continue;
			}
			foreach ( (array) @scandir( $base . '/' . $year ) as $month ) {
				if ( preg_match( '/^(0[1-9]|1[0-2])$/', (string) $month ) && is_dir( $base . '/' . $year . '/' . $month ) && ! is_link( $base . '/' . $year . '/' . $month ) ) {
					$scopes[] = $year . '/' . $month;
				}
			}
		}
		$scopes = array_values( array_unique( $scopes ) );
		sort( $scopes, SORT_STRING );
		return $scopes;
	}

	public static function current_scope() {
		return function_exists( 'wp_date' ) ? wp_date( 'Y/m' ) : gmdate( 'Y/m' );
	}

	public static function valid_scope( $scope ) {
		return 'other' === $scope || ( is_string( $scope ) && (bool) preg_match( '#^\d{4}/(0[1-9]|1[0-2])$#', $scope ) );
	}

	/**
	 * Groups exact month scopes into Knife-like year streams. "other" remains a
	 * separate bounded stream so date partitions can still be selected safely.
	 *
	 * @return array[] Each entry contains label and exact scopes.
	 */
	public static function baseline_groups( $scopes ) {
		$grouped = array();
		foreach ( array_values( array_unique( (array) $scopes ) ) as $scope ) {
			if ( ! self::valid_scope( $scope ) ) {
				continue;
			}
			$key = 'other' === $scope ? 'other' : substr( $scope, 0, 4 );
			$grouped[ $key ][] = $scope;
		}
		ksort( $grouped, SORT_STRING );
		$groups = array();
		foreach ( $grouped as $label => $members ) {
			sort( $members, SORT_STRING );
			$groups[] = array(
				'label'  => 'other' === $label ? 'non-date content' : (string) $label,
				'scopes' => $members,
			);
		}
		return $groups;
	}

	/** Maps an absolute or uploads-relative path to YYYY/MM or "other". */
	public static function scope_for_file( $file ) {
		if ( ! is_string( $file ) || '' === $file ) {
			return null;
		}
		$base = rtrim( self::uploads_dir(), DIRECTORY_SEPARATOR );
		$path = str_replace( '\\', '/', $file );
		$root = str_replace( '\\', '/', $base );
		if ( 0 === strpos( $path, $root . '/' ) ) {
			$path = substr( $path, strlen( $root ) + 1 );
		} elseif ( 0 === strpos( $path, '/' ) ) {
			return null;
		}
		if ( preg_match( '#^(\d{4})/(0[1-9]|1[0-2])(?:/|$)#', $path, $matches ) ) {
			return $matches[1] . '/' . $matches[2];
		}
		return 'other';
	}

	private static function relative_file_path( $file ) {
		if ( ! is_string( $file ) || '' === $file ) {
			return null;
		}
		$path = str_replace( '\\', '/', $file );
		$root = str_replace( '\\', '/', rtrim( self::uploads_dir(), DIRECTORY_SEPARATOR ) );
		if ( 0 === strpos( $path, $root . '/' ) ) {
			$path = substr( $path, strlen( $root ) + 1 );
		} elseif ( 0 === strpos( $path, '/' ) ) {
			return null;
		}
		return Tar_Exporter::is_safe_relative_path( $path ) ? $path : null;
	}

	/**
	 * The per-partition sync cursor. Schema 2 is a per-partition map:
	 *
	 *   source_id  => hash identifying the live source
	 *   partitions => scope => [ 'source_generation' => int (plan-time),
	 *                            'local_generation'  => int ]
	 *
	 * A schema-1 cursor (single atomic blob with parallel generation maps) is
	 * migrated on read; it carried the same per-scope facts, just committed
	 * all-or-nothing.
	 */
	public static function cursor() {
		$cursor = get_option( self::OPT_CURSOR, array() );
		if ( ! is_array( $cursor ) || empty( $cursor['schema'] ) ) {
			return array();
		}
		if ( self::CURSOR_SCHEMA === (int) $cursor['schema'] ) {
			return isset( $cursor['partitions'] ) && is_array( $cursor['partitions'] ) ? $cursor : array();
		}
		if ( 1 === (int) $cursor['schema'] && ! empty( $cursor['source_id'] ) ) {
			return self::migrate_cursor_v1( $cursor );
		}
		return array();
	}

	/** @return array Schema-2 shape (not persisted; committed cursors rewrite it). */
	private static function migrate_cursor_v1( $cursor ) {
		$partitions = array();
		foreach ( (array) ( $cursor['scopes'] ?? array() ) as $scope ) {
			if ( ! self::valid_scope( $scope ) || ! array_key_exists( $scope, (array) ( $cursor['source_generations'] ?? array() ) ) ) {
				continue;
			}
			$partitions[ $scope ] = array(
				'source_generation' => (int) $cursor['source_generations'][ $scope ],
				'local_generation'  => (int) ( $cursor['local_generations'][ $scope ] ?? 0 ),
			);
		}
		return array(
			'schema'     => self::CURSOR_SCHEMA,
			'source_id'  => (string) $cursor['source_id'],
			'partitions' => $partitions,
			'updated_at' => (int) ( $cursor['updated_at'] ?? 0 ),
		);
	}

	/** True when the cursor belongs to $source_id (any committed partitions). */
	public static function cursor_matches_source( $source_id, $cursor = null ) {
		$cursor = null === $cursor ? self::cursor() : $cursor;
		return is_string( $source_id ) && '' !== $source_id && is_array( $cursor ) && ! empty( $cursor['source_id'] )
			&& hash_equals( $source_id, (string) $cursor['source_id'] );
	}

	/**
	 * True when $scope has a committed entry for $source_id. Partitions without
	 * an entry have never completed a verified sync from this source and take
	 * the baseline (or adopt) path; partitions with one take the incremental
	 * generation-check path.
	 */
	public static function partition_committed( $source_id, $scope, $cursor = null ) {
		$cursor = null === $cursor ? self::cursor() : $cursor;
		return self::cursor_matches_source( $source_id, $cursor )
			&& isset( $cursor['partitions'] ) && is_array( $cursor['partitions'] )
			&& array_key_exists( $scope, $cursor['partitions'] )
			&& is_array( $cursor['partitions'][ $scope ] );
	}

	/**
	 * Merges committed partition entries into the cursor under the uploads
	 * state file lock (workers and web requests share the option, and two
	 * read-modify-write commits without the lock could drop partitions).
	 * A different source_id replaces the whole cursor: partitions synced from
	 * another live site say nothing about this one.
	 *
	 * @param string $source_id  Live source identity hash.
	 * @param array  $partitions scope => [ 'source_generation', 'local_generation' ].
	 * @return true|\WP_Error
	 */
	public static function commit_partitions( $source_id, $partitions ) {
		if ( ! is_string( $source_id ) || '' === $source_id ) {
			return new \WP_Error( 'uploads_cursor_source', 'Cannot commit an uploads cursor without a source identity.' );
		}
		$result = self::transaction(
			function ( $state ) use ( $source_id, $partitions ) {
				$cursor = self::cursor();
				if ( ! self::cursor_matches_source( $source_id, $cursor ) ) {
					$cursor = array(
						'source_id'  => $source_id,
						'partitions' => array(),
					);
				}
				foreach ( (array) $partitions as $scope => $entry ) {
					if ( ! self::valid_scope( $scope ) || ! is_array( $entry ) ) {
						continue;
					}
					$cursor['partitions'][ $scope ] = array(
						'source_generation' => (int) ( $entry['source_generation'] ?? 0 ),
						'local_generation'  => (int) ( $entry['local_generation'] ?? 0 ),
					);
				}
				$cursor['schema']     = self::CURSOR_SCHEMA;
				$cursor['updated_at'] = time();
				update_option( self::OPT_CURSOR, $cursor, false );
				// The state file itself is unchanged; the lock was only borrowed
				// to serialize the option read-modify-write.
				return array(
					'state' => $state,
					'value' => true,
					'persist' => false,
				);
			}
		);
		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Removes committed entries before their partitions are destructively
	 * cleared for a fresh baseline (uncommit-before-destroy): if the clear or
	 * the re-stream is interrupted, the cursor must already say "never
	 * completed" rather than claim files that are now gone.
	 *
	 * @param string   $source_id Live source identity hash.
	 * @param string[] $scopes    Partitions about to be cleared.
	 * @return true|\WP_Error
	 */
	public static function remove_partitions( $source_id, $scopes ) {
		$result = self::transaction(
			function ( $state ) use ( $source_id, $scopes ) {
				$cursor = self::cursor();
				if ( self::cursor_matches_source( $source_id, $cursor ) ) {
					foreach ( (array) $scopes as $scope ) {
						unset( $cursor['partitions'][ $scope ] );
					}
					$cursor['schema']     = self::CURSOR_SCHEMA;
					$cursor['updated_at'] = time();
					update_option( self::OPT_CURSOR, $cursor, false );
				}
				return array(
					'state' => $state,
					'value' => true,
					'persist' => false,
				);
			}
		);
		return is_wp_error( $result ) ? $result : true;
	}

	private static function uploads_dir() {
		return WP_CONTENT_DIR . '/uploads';
	}

	private static function path() {
		return WP_CONTENT_DIR . '/.sandbox-sync-uploads-state.php';
	}

	private static function lock_path() {
		return WP_CONTENT_DIR . '/.sandbox-sync-uploads-state.lock';
	}

	/** @return mixed|\WP_Error */
	private static function transaction( $callback ) {
		$lock = @fopen( self::lock_path(), 'c' );
		if ( ! $lock || ! flock( $lock, LOCK_EX ) ) {
			if ( $lock ) {
				fclose( $lock );
			}
			return new \WP_Error( 'uploads_state_lock', 'Could not lock uploads change state.' );
		}
		@chmod( self::lock_path(), 0600 );
		try {
			$state = self::read_unlocked();
			if ( is_wp_error( $state ) ) {
				return $state;
			}
			$change = call_user_func( $callback, $state );
			if ( is_wp_error( $change ) ) {
				return $change;
			}
			if ( ! is_array( $change ) || ! isset( $change['state'] ) || ! array_key_exists( 'value', $change ) ) {
				return new \WP_Error( 'uploads_state_change', 'Uploads state mutation returned an invalid result.' );
			}
			if ( ! isset( $change['persist'] ) || false !== $change['persist'] ) {
				$written = self::write_unlocked( $change['state'] );
				if ( is_wp_error( $written ) ) {
					return $written;
				}
			}
			return $change['value'];
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
	}

	private static function read_unlocked() {
		$path = self::path();
		if ( ! is_file( $path ) ) {
			return array(
				'schema' => self::SCHEMA,
				'clock' => 0,
				'generations' => array(),
				'changes' => array(),
				'history_floor' => array(),
			);
		}
		if ( is_link( $path ) ) {
			return new \WP_Error( 'uploads_state_symlink', 'Uploads state must not be a symlink.' );
		}
		$raw = @file_get_contents( $path );
		if ( false === $raw || 0 !== strpos( $raw, self::PREFIX ) ) {
			return new \WP_Error( 'uploads_state_corrupt', 'Uploads state is unreadable or malformed; run a full uploads audit.' );
		}
		$state = json_decode( substr( $raw, strlen( self::PREFIX ) ), true );
		if ( ! is_array( $state ) || self::SCHEMA !== (int) ( $state['schema'] ?? 0 ) || ! isset( $state['clock'], $state['generations'] ) || ! is_array( $state['generations'] ) ) {
			return new \WP_Error( 'uploads_state_schema', 'Uploads state has an unsupported schema; run a full uploads audit.' );
		}
		$state['changes']       = isset( $state['changes'] ) && is_array( $state['changes'] ) ? $state['changes'] : array();
		$state['history_floor'] = isset( $state['history_floor'] ) && is_array( $state['history_floor'] ) ? $state['history_floor'] : array();
		return $state;
	}

	private static function write_unlocked( $state ) {
		$state['schema'] = self::SCHEMA;
		$json = wp_json_encode( $state );
		if ( false === $json ) {
			return new \WP_Error( 'uploads_state_write', 'Uploads state could not be encoded.' );
		}
		$path = self::path();
		$tmp  = $path . '.' . bin2hex( random_bytes( 8 ) ) . '.tmp';
		$data = self::PREFIX . $json;
		$fh   = @fopen( $tmp, 'xb' );
		if ( ! $fh ) {
			return new \WP_Error( 'uploads_state_write', 'Could not create temporary uploads state.' );
		}
		$ok = fwrite( $fh, $data ) === strlen( $data ) && fflush( $fh );
		if ( function_exists( 'fsync' ) ) {
			$ok = @fsync( $fh ) && $ok;
		}
		fclose( $fh );
		@chmod( $tmp, 0600 );
		if ( ! $ok || ! @rename( $tmp, $path ) ) {
			@unlink( $tmp );
			return new \WP_Error( 'uploads_state_write', 'Could not atomically publish uploads state.' );
		}
		return true;
	}
}
