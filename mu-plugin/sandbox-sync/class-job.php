<?php
/**
 * Persistent sync job: an ordered list of steps with per-step status, a
 * timestamped log, and a heartbeat. Stored in a single option so it survives
 * page navigations, PHP restarts, and the DB table swap (the option is on
 * the preserved list).
 *
 * Only one job exists at a time; an active job doubles as the "a sync is
 * running" lock at the workflow level. A separate short-lived slice lock
 * (see acquire_slice_lock) prevents the cron worker and browser ticks from
 * processing the same step concurrently.
 */

namespace SandboxSync;

defined( 'ABSPATH' ) || exit;

class Job {

	const OPT        = 'sandbox_sync_job';
	const SLICE_LOCK = 'sandbox_sync_slice_lock';
	const CANCEL_OPT = 'sandbox_sync_cancel_request';

	// Longer than the longest single HTTP operation (Client::TIMEOUT=600).
	// Long loops renew the lease at checkpoints.
	const SLICE_LOCK_TTL = 900;

	// Short lease for tight chunked loops that checkpoint every few seconds
	// (table import). If the platform kills the worker process mid-loop, the
	// job resumes within ~2 minutes instead of waiting out the full TTL that
	// only genuinely long blocking HTTP streams need.
	const CHUNK_LOCK_TTL = 120;

	// Lease TTL while a worker is blocked inside one long streaming transfer
	// (shell table dump). Renewals only happen as data arrives, and the stall
	// detector allows up to BASELINE_STALL_SECONDS (120s) of silence, so this
	// must comfortably exceed that gap — while still freeing a killed worker's
	// job in minutes, not the 15-minute idle default.
	const STREAM_LOCK_TTL = 300;
	const LOG_MAX        = 300;  // In-option log entries kept for the live UI.

	/** Directory for durable per-job log files (retained with sync history). */
	public static function log_dir() {
		return WP_CONTENT_DIR . '/.sandbox-sync-logs';
	}

	/**
	 * Absolute path for a job's append-only log file, or null when the id is
	 * not a safe basename (prevents path traversal through a forged job id).
	 */
	public static function log_path( $job_id ) {
		$job_id = (string) $job_id;
		if ( ! preg_match( '/^[a-f0-9]{12}$/', $job_id ) ) {
			return null;
		}
		return self::log_dir() . '/' . $job_id . '.log';
	}

	/** Ensures the log directory exists with restrictive permissions. */
	public static function ensure_log_dir() {
		$dir = self::log_dir();
		if ( is_dir( $dir ) ) {
			return true;
		}
		if ( ! @mkdir( $dir, 0700, true ) && ! is_dir( $dir ) ) {
			return false;
		}
		@chmod( $dir, 0700 );
		// Block web access if the host serves wp-content as static files.
		$index = $dir . '/index.php';
		if ( ! is_file( $index ) ) {
			@file_put_contents( $index, "<?php\n// Silence.\n" );
		}
		return true;
	}

	/**
	 * Opens (truncates) the durable log for a new job. Best-effort: a missing
	 * file never blocks the sync; the in-option ring buffer still works.
	 */
	public static function begin_log_file( $job_id ) {
		$path = self::log_path( $job_id );
		if ( null === $path || ! self::ensure_log_dir() ) {
			return false;
		}
		$handle = @fopen( $path, 'wb' );
		if ( ! $handle ) {
			return false;
		}
		fwrite( $handle, sprintf( "# Sandbox Sync job %s started %s\n", $job_id, gmdate( 'c' ) ) );
		fclose( $handle );
		@chmod( $path, 0600 );
		return true;
	}

	/** Appends one timestamped line to the durable log file. */
	public static function append_log_file( $job_id, $timestamp, $message ) {
		$path = self::log_path( $job_id );
		if ( null === $path ) {
			return;
		}
		if ( ! is_dir( self::log_dir() ) && ! self::ensure_log_dir() ) {
			return;
		}
		$line = sprintf( "[%s] %s\n", gmdate( 'Y-m-d H:i:s', (int) $timestamp ), (string) $message );
		@file_put_contents( $path, $line, FILE_APPEND | LOCK_EX );
	}

	/**
	 * Reads a retained job log. Returns the text, an empty string when the
	 * file is missing, or WP_Error for an unsafe/unknown id.
	 *
	 * @return string|\WP_Error
	 */
	public static function read_log_file( $job_id ) {
		$path = self::log_path( $job_id );
		if ( null === $path ) {
			return new \WP_Error( 'log_id', 'Invalid job id.' );
		}
		if ( ! is_file( $path ) ) {
			return '';
		}
		$raw = @file_get_contents( $path );
		return false === $raw ? '' : $raw;
	}

	/**
	 * Deletes log files whose job ids are not in $keep_ids (history retention).
	 * Never touches the active job's file when its id is still listed.
	 */
	public static function prune_log_files( $keep_ids ) {
		$dir = self::log_dir();
		if ( ! is_dir( $dir ) ) {
			return 0;
		}
		$keep = array();
		foreach ( (array) $keep_ids as $id ) {
			if ( preg_match( '/^[a-f0-9]{12}$/', (string) $id ) ) {
				$keep[ (string) $id ] = true;
			}
		}
		$removed = 0;
		foreach ( scandir( $dir ) as $entry ) {
			if ( ! preg_match( '/^([a-f0-9]{12})\.log$/', $entry, $m ) ) {
				continue;
			}
			if ( isset( $keep[ $m[1] ] ) ) {
				continue;
			}
			if ( @unlink( $dir . '/' . $entry ) ) {
				++$removed;
			}
		}
		return $removed;
	}

	/**
	 * Human-readable duration for UI/CLI (e.g. 2488 → "41m 28s", 3725 → "1h 2m 5s").
	 */
	public static function format_duration( $seconds ) {
		$seconds = max( 0, (int) $seconds );
		if ( $seconds < 60 ) {
			return $seconds . 's';
		}
		$hours   = intdiv( $seconds, 3600 );
		$minutes = intdiv( $seconds % 3600, 60 );
		$secs    = $seconds % 60;
		if ( $hours > 0 ) {
			return sprintf( '%dh %dm %ds', $hours, $minutes, $secs );
		}
		return sprintf( '%dm %ds', $minutes, $secs );
	}

	/** @var array|null Lock owned by this PHP process. */
	private static $held_lock = null;

	// Job statuses.
	const S_RUNNING   = 'running';
	const S_DONE      = 'done';
	const S_FAILED    = 'failed';
	const S_CANCELLED = 'cancelled';

	/**
	 * Creates and persists a fresh job.
	 *
	 * @param array  $options    Sync options (db, dirs, incremental, excluded/schema-only tables).
	 * @param int    $user_id    Admin starting the job (their session survives the swap).
	 * @param bool   $background Whether a cron worker should drive the job.
	 * @return array The job.
	 */
	public static function create( $options, $user_id, $background ) {
		delete_option( self::CANCEL_OPT );
		$job = array(
			'id'                     => substr( bin2hex( random_bytes( 8 ) ), 0, 12 ),
			'status'                 => self::S_RUNNING,
			'background'             => (bool) $background,
			'options'                => $options,
			'user_id'                => (int) $user_id,
			'created_at'             => time(),
			'finished_at'            => 0,
			'heartbeat'              => time(),
			// Bulk files are pre-copied first; the manifest-expanded plan then
			// refreshes database metadata immediately before the final uploads
			// delta and table import. The live source is never paused.
			'steps'                  => array(
				self::make_step( 'transfer_session', 'Open a scoped source transfer session' ),
				self::make_step( 'manifest', 'Fetch manifest from source' ),
			),
			'current'                => 0,
			'manifest'               => null,
			'imported'               => array(), // Source table names actually imported (not skipped).
			'prepared_tables'        => array(), // Imported or schema-only shadows ready for activation.
			'working_tables'         => array(), // Exact job-owned shadow/backup table names.
			'working_dirs'           => array(), // Stages/backups, or uploads in-place/quarantine state.
			'uploads_fast'           => array(), // Generation snapshots and scoped deletion candidates.
			'db_swap'                => null, // Prepared/active database activation mapping for rollback.
			'force_tables'           => array(), // Table groups that must import atomically.
			'runtime_guard_previous' => array(),
			'preserved_user_id'      => 0,
			'cancel_requested'       => false,
			'log'                    => array(),
			'error'                  => '',
			'error_info'             => null,
		);
		self::save( $job );
		self::begin_log_file( $job['id'] );
		return $job;
	}

	public static function make_step( $type, $label, $args = array() ) {
		return array(
			'type'        => $type,
			'label'       => $label,
			'args'        => $args,
			'status'      => 'pending',
			'message'     => '',
			'started_at'  => 0,
			'finished_at' => 0,
		);
	}

	/**
	 * @return array|null The current job, if any.
	 */
	public static function get() {
		$job = get_option( self::OPT );
		return is_array( $job ) ? $job : null;
	}

	public static function save( $job ) {
		update_option( self::OPT, $job, false );
	}

	public static function delete() {
		delete_option( self::OPT );
	}

	public static function is_active( $job ) {
		return is_array( $job ) && self::S_RUNNING === $job['status'];
	}

	public static function log( &$job, $message ) {
		$now = time();
		$job['log'][] = array( $now, $message );
		if ( count( $job['log'] ) > self::LOG_MAX ) {
			$job['log'] = array_slice( $job['log'], -self::LOG_MAX );
		}
		// Full history lives on disk; the option only keeps a UI-sized ring buffer.
		if ( ! empty( $job['id'] ) ) {
			self::append_log_file( $job['id'], $now, $message );
		}
	}

	/**
	 * Inserts steps right after position $index.
	 */
	public static function insert_steps( &$job, $index, $steps ) {
		array_splice( $job['steps'], $index + 1, 0, $steps );
	}

	/**
	 * Redacted, UI-friendly view of the job (step args can contain long path
	 * lists that would bloat every poll response).
	 */
	public static function public_state( $job ) {
		if ( ! is_array( $job ) ) {
			return null;
		}

		$steps = array();
		foreach ( $job['steps'] as $step ) {
			$steps[] = array(
				'type'    => $step['type'],
				'label'   => $step['label'],
				'status'  => $step['status'],
				'message' => $step['message'],
			);
		}

		$done = 0;
		foreach ( $job['steps'] as $step ) {
			if ( in_array( $step['status'], array( 'done', 'skipped' ), true ) ) {
				++$done;
			}
		}
		$elapsed = max( 0, ( ! empty( $job['finished_at'] ) ? (int) $job['finished_at'] : time() ) - (int) $job['created_at'] );
		$lock         = get_option( self::SLICE_LOCK );
		$lease_active = is_array( $lock )
			&& isset( $lock['job_id'], $lock['expires_at'] )
			&& hash_equals( (string) $job['id'], (string) $lock['job_id'] )
			&& (int) $lock['expires_at'] > time();

		return array(
			'id'               => $job['id'],
			'status'           => $job['status'],
			'background'       => $job['background'],
			'options'          => $job['options'],
			'created_at'       => $job['created_at'],
			'finished_at'      => $job['finished_at'],
			'heartbeat'        => $job['heartbeat'],
			'heartbeat_age'    => time() - (int) $job['heartbeat'],
			'lease_active'     => $lease_active,
			'lease_expires_in' => $lease_active ? (int) $lock['expires_at'] - time() : 0,
			'steps'            => $steps,
			'current'          => $job['current'],
			'steps_total'      => count( $job['steps'] ),
			'steps_done'       => $done,
			'elapsed_s'        => $elapsed,
			// Step counts grow dynamically and step costs differ by orders of
			// magnitude. A step-average ETA is actively misleading, so retain the
			// field as null for API compatibility until a byte/row-based model exists.
			'eta_s'            => null,
			'log'              => array_slice( $job['log'], -100 ),
			'error'            => $job['error'],
			'error_info'       => isset( $job['error_info'] ) && is_array( $job['error_info'] ) ? $job['error_info'] : null,
			'cancel_requested' => self::is_cancel_requested( $job['id'] ),
			// Sandbox-only entries this sync leaves in place (set by the plan steps).
			'kept_plugins'     => isset( $job['kept_plugins'] ) && is_array( $job['kept_plugins'] ) ? array_values( $job['kept_plugins'] ) : array(),
			'kept_themes'      => isset( $job['kept_themes'] ) && is_array( $job['kept_themes'] ) ? array_values( $job['kept_themes'] ) : array(),
		);
	}

	public static function request_cancel( $job_id ) {
		update_option( self::CANCEL_OPT, (string) $job_id, false );
	}

	public static function is_cancel_requested( $job_id ) {
		return hash_equals( (string) $job_id, (string) get_option( self::CANCEL_OPT, '' ) );
	}

	public static function clear_cancel_request() {
		delete_option( self::CANCEL_OPT );
	}

	// -- Slice lock (cron worker vs browser ticks) ------------------------------

	/**
	 * Tries to take the short-lived processing lock.
	 */
	public static function acquire_slice_lock( $job_id = '' ) {
		$now      = time();
		$existing = get_option( self::SLICE_LOCK );
		if ( is_numeric( $existing ) && ( $now - (int) $existing ) < self::SLICE_LOCK_TTL ) {
			return false; // Active lock written by a pre-fencing development version.
		}
		if ( is_array( $existing ) && ! empty( $existing['expires_at'] ) && (int) $existing['expires_at'] > $now ) {
			// A worker always renews and marks the step running immediately after
			// acquisition. If it disappeared before that publication point, fence
			// it after a short grace period instead of blocking recovery for the
			// full long-operation TTL.
			$job          = self::get();
			$current      = is_array( $job ) && isset( $job['steps'][ $job['current'] ] ) ? $job['steps'][ $job['current'] ] : null;
			$before_step  = is_array( $current ) && 'pending' === $current['status'];
			$same_job     = is_array( $job ) && isset( $existing['job_id'], $job['id'] ) && hash_equals( (string) $job['id'], (string) $existing['job_id'] );
			$unpublished  = $same_job && $before_step && isset( $existing['acquired_at'], $job['heartbeat'] ) && (int) $job['heartbeat'] <= (int) $existing['acquired_at'];
			// Mid-step death: heartbeat stops while the lock keeps renewing until
			// the process vanishes. Without this, successors wait the full 900s
			// HTTP TTL even though the job has been idle for minutes (seen on WP
			// Cloud when a remote worker loop died during plugins dir_fetch).
			$heartbeat_stale = $same_job
				&& isset( $job['heartbeat'] )
				&& ( $now - (int) $job['heartbeat'] ) > self::CHUNK_LOCK_TTL;
			if ( $heartbeat_stale ) {
				// Fall through and fence the abandoned lease.
			} elseif ( ! $unpublished || $now - (int) $existing['acquired_at'] < 30 ) {
				return false;
			}
		}

		$fence = is_array( $existing ) && isset( $existing['fence'] ) ? (int) $existing['fence'] + 1 : 1;
		$lock  = array(
			'owner'       => bin2hex( random_bytes( 16 ) ),
			'job_id'      => (string) $job_id,
			'fence'       => $fence,
			'acquired_at' => $now,
			'expires_at'  => $now + self::SLICE_LOCK_TTL,
		);

		$acquired = false === $existing
			? add_option( self::SLICE_LOCK, $lock, '', false )
			: self::compare_and_swap_lock( $existing, $lock );
		if ( ! $acquired ) {
			return false;
		}
		self::$held_lock = $lock;
		return true;
	}

	/**
	 * Renews the current process's lease. False means ownership was lost and
	 * the caller must stop before another destructive operation.
	 */
	/**
	 * @param int|null $ttl Seconds until the renewed lease expires. Defaults to
	 *                      SLICE_LOCK_TTL; chunked loops that checkpoint every
	 *                      few seconds pass CHUNK_LOCK_TTL so a killed worker
	 *                      blocks successors only briefly.
	 */
	public static function renew_slice_lock( $ttl = null ) {
		if ( ! is_array( self::$held_lock ) ) {
			return false;
		}
		$current = get_option( self::SLICE_LOCK );
		if ( ! self::same_lock( $current, self::$held_lock ) ) {
			self::$held_lock = null;
			return false;
		}
		$renewed               = self::$held_lock;
		$renewed['expires_at'] = time() + ( null === $ttl ? self::SLICE_LOCK_TTL : max( 30, (int) $ttl ) );
		// An immediate renewal commonly lands in the acquisition second, making
		// the serialized replacement byte-for-byte identical. MySQL reports zero
		// changed rows for that no-op; ownership is still valid and no write is
		// necessary.
		if ( $renewed === $current ) {
			return true;
		}
		if ( ! self::compare_and_swap_lock( $current, $renewed ) ) {
			self::$held_lock = null;
			return false;
		}
		self::$held_lock = $renewed;
		return true;
	}

	public static function release_slice_lock() {
		if ( ! is_array( self::$held_lock ) ) {
			return false;
		}
		$current = get_option( self::SLICE_LOCK );
		if ( ! self::same_lock( $current, self::$held_lock ) ) {
			self::$held_lock = null;
			return false;
		}

		$deleted         = self::compare_and_delete_lock( $current );
		self::$held_lock = null;
		return $deleted;
	}

	private static function same_lock( $a, $b ) {
		return is_array( $a ) && is_array( $b )
			&& isset( $a['owner'], $a['fence'], $b['owner'], $b['fence'] )
			&& hash_equals( (string) $a['owner'], (string) $b['owner'] )
			&& (int) $a['fence'] === (int) $b['fence'];
	}

	/**
	 * Atomic compare-and-swap against the serialized option value. The simple
	 * fallback keeps the dependency-free unit harness working; production
	 * WordPress always supplies $wpdb and uses the conditional SQL update.
	 */
	private static function compare_and_swap_lock( $expected, $replacement ) {
		global $wpdb;
		if ( isset( $wpdb->options ) && function_exists( 'maybe_serialize' ) ) {
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
					maybe_serialize( $replacement ),
					self::SLICE_LOCK,
					maybe_serialize( $expected )
				)
			);
			if ( 1 === $updated ) {
				wp_cache_delete( self::SLICE_LOCK, 'options' );
				return true;
			}
			return false;
		}
		if ( get_option( self::SLICE_LOCK ) !== $expected ) {
			return false;
		}
		update_option( self::SLICE_LOCK, $replacement, false );
		return get_option( self::SLICE_LOCK ) === $replacement;
	}

	private static function compare_and_delete_lock( $expected ) {
		global $wpdb;
		if ( isset( $wpdb->options ) && function_exists( 'maybe_serialize' ) ) {
			$deleted = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
					self::SLICE_LOCK,
					maybe_serialize( $expected )
				)
			);
			if ( 1 === $deleted ) {
				wp_cache_delete( self::SLICE_LOCK, 'options' );
				return true;
			}
			return false;
		}
		if ( get_option( self::SLICE_LOCK ) !== $expected ) {
			return false;
		}
		return delete_option( self::SLICE_LOCK );
	}
}
