<?php
/**
 * Sandbox-side sync engine, built around a persistent Job (class-job.php).
 *
 * A job is a queue of steps processed in "slices" (time-budgeted batches).
 * Two drivers share the same slice processor, serialized by the slice lock:
 *   - browser ticks (AJAX) while the admin screen is open, and
 *   - a WP-Cron worker, so background jobs survive a closed tab.
 *
 * Step types:
 *   manifest        fetch source manifest, expand the step plan
 *   dir_plan        hash-diff code into a stage or uploads into an in-place plan
 *   dir_fetch       pull one batch of changed files (incremental)
 *   dir_file        resume one oversized file in bounded byte ranges
 *   table           import one table into a shadow table (or skip via checksums)
 *   search_replace  rewrite source URLs in imported shadow tables
 *   activate        guarded database swap and safety application
 *   dir_activate    activate staged code or validate/quarantine uploads
 *   finalize        release the guard after database and files agree
 *
 * Direction guardrails (enforced at job creation):
 *   - the site must be explicitly flagged as a sandbox by an admin,
 *   - a site with paired sandboxes (source role) refuses to sync into itself.
 */

namespace SandboxSync;

defined( 'ABSPATH' ) || exit;

class Sync_Runner {
	use Sync_Runner_Database;
	use Sync_Runner_Files;

	const OPT_LAST_REPORT = 'sandbox_sync_last_report';
	const OPT_HISTORY     = 'sandbox_sync_history';
	// Per-table checksums recorded after each sync; powers incremental skips.
	const OPT_TABLE_STATE = 'sandbox_sync_table_state';

	const SHADOW_PREFIX = 'ssx_';
	const BACKUP_PREFIX = 'ssxbak_';

	const CRON_HOOK = 'sandbox_sync_process_job';

	// Target bytes per table-export chunk; the row limit adapts to this.
	const CHUNK_TARGET_BYTES = 4194304; // 4 MB
	const CHUNK_MIN_ROWS     = 1;
	const CHUNK_MAX_ROWS     = 2000;

	// A running job whose heartbeat is older than this is considered dead.
	const STALE_JOB_SECONDS          = 1800;
	const DEFAULT_SCHEMA_ONLY_TABLES = array( 'newspack_newsletters_subscription_attempts' );

	/**
	 * Unix time when the current slice's budget runs out. Long chunked steps
	 * consult this (via slice_deadline_reached) to yield instead of running to
	 * completion inside one PHP process: hosted platforms kill long web
	 * requests mid-step, and a hard-killed worker blocks the job for its
	 * remaining lease TTL.
	 *
	 * @var int|null
	 */
	private static $slice_deadline = null;

	private static function slice_deadline_reached() {
		return null !== self::$slice_deadline && time() >= self::$slice_deadline;
	}

	public static function default_options() {
		return array(
			'db'                 => true,
			'dirs'               => Source_Endpoints::SYNCABLE_DIRS,
			'incremental'        => true,
			// Default uploads mode: metadata-diff only hook-dirty date partitions,
			// then SHA-256 only the payloads that will actually travel.
			'uploads_fast'       => true,
			'uploads_full_audit' => false,
			// First sync from a source with files already on disk: compare in
			// place and transfer only differences instead of the destructive
			// clear-and-restream bulk baseline.
			'uploads_adopt'      => false,
			'excluded_tables'    => array(),
			// Recreate these tables from the source schema but intentionally copy
			// no rows. Suitable for disposable logs, caches and recovery data.
			'schema_only_tables' => self::DEFAULT_SCHEMA_ONLY_TABLES,
			'safety'             => Safety::default_keys(),
			// null = sync all plugins; an array limits plugins/ to those
			// top-level entries (per-plugin selection).
			'plugins'            => null,
			// null = all uploads; an array of 'YYYY', 'YYYY/MM' or 'other'
			// limits uploads/ to those date scopes.
			'uploads_scope'      => null,
			// Constants from the live wp-config.php to replicate.
			'config_constants'   => array(),
		);
	}

	// Sandbox-local plugins the sync must never delete or overwrite: the
	// email kill switch installed by the safety step.
	const PROTECTED_PLUGINS = array( Safety::STOP_EMAILS_SLUG );

	// Top-level entries the last sync wrote into plugins/ and themes/. An entry
	// on the sandbox that is neither on live nor in its record was installed
	// there by someone, so the sync keeps it instead of deleting it.
	const OPT_SYNCED_PLUGINS = 'sandbox_sync_synced_plugins';
	const OPT_SYNCED_THEMES  = 'sandbox_sync_synced_themes';
	const KEPT_DIRS          = array(
		'plugins' => self::OPT_SYNCED_PLUGINS,
		'themes'  => self::OPT_SYNCED_THEMES,
	);

	// -- Kept sandbox-only entries -------------------------------------------------

	/**
	 * Top-level entries of plugins/ or themes/ that only the sandbox has and
	 * that the sync must leave alone.
	 *
	 * With a record, an entry survives when live does not deliver it and no
	 * earlier sync wrote it. Without one there is no telling a local install
	 * from an entry live has since removed, so only an entry live runs as a
	 * symlink is kept: a symlink never travels, which makes a real directory
	 * of that name on the sandbox a local build.
	 *
	 * @param string[] $local      Candidate entries on the sandbox.
	 * @param string[] $remote     Top-level names in live's file manifest.
	 * @param mixed    $record     Names the last sync wrote; anything but an array means no record yet.
	 * @param string[] $live_links Names live holds as symlinks.
	 * @param string[] $protected  Names with their own unconditional protection.
	 * @return string[] Sorted names.
	 */
	public static function kept_top_level_names( $local, $remote, $record, $live_links, $protected = array() ) {
		$live_links = self::sorted_names( $live_links );
		$candidates = array_diff( self::sorted_names( $local ), (array) $protected, self::sorted_names( $remote ) );
		$kept       = is_array( $record )
			? array_diff( $candidates, array_diff( self::sorted_names( $record ), $live_links ) )
			: array_intersect( $candidates, $live_links );
		return self::sorted_names( $kept );
	}

	/**
	 * Sandbox entries that can be a plugin or a theme: real directories, plus
	 * single PHP files in plugins/. Any other stray file keeps following live.
	 *
	 * @param string $dir     'plugins' or 'themes'.
	 * @param array  $listing Files::top_level_listing() of the sandbox directory.
	 * @return string[] Sorted names.
	 */
	public static function kept_candidates( $dir, $listing ) {
		$candidates = isset( $listing['dirs'] ) && is_array( $listing['dirs'] ) ? $listing['dirs'] : array();
		if ( 'plugins' === $dir && isset( $listing['files'] ) && is_array( $listing['files'] ) ) {
			foreach ( $listing['files'] as $file ) {
				if ( is_string( $file ) && 'php' === strtolower( (string) pathinfo( $file, PATHINFO_EXTENSION ) ) ) {
					$candidates[] = $file;
				}
			}
		}
		return self::sorted_names( $candidates );
	}

	/**
	 * The record to store once this sync is final: what live delivered, minus
	 * the names the sandbox holds as symlinks, which a sync never writes. A
	 * per-plugin selection only speaks for the selected entries.
	 *
	 * @param string[]      $remote      Top-level names in live's file manifest.
	 * @param string[]      $previous    The record before this sync.
	 * @param string[]|null $selection   Per-plugin selection, or null when the whole directory synced.
	 * @param string[]      $local_links Names the sandbox holds as symlinks.
	 * @return string[] Sorted names.
	 */
	public static function next_synced_record( $remote, $previous, $selection, $local_links ) {
		$written = array_diff( self::sorted_names( $remote ), self::sorted_names( $local_links ) );
		if ( ! is_array( $selection ) ) {
			return self::sorted_names( $written );
		}
		$selection = self::sorted_names( $selection );
		return self::sorted_names(
			array_merge(
				array_diff( self::sorted_names( $previous ), $selection ),
				array_intersect( $written, $selection )
			)
		);
	}

	/**
	 * Live's activation list with the sandbox's kept plugins appended, so a
	 * kept plugin that was active is still active after the swap. Live's own
	 * order is left as it is.
	 *
	 * @param mixed    $imported     active_plugins as imported from live.
	 * @param mixed    $local_active active_plugins on the sandbox before the swap.
	 * @param string[] $kept         Kept top-level plugin entries.
	 * @return string[]
	 */
	public static function carry_kept_active_plugins( $imported, $local_active, $kept ) {
		$merged = array();
		foreach ( is_array( $imported ) ? $imported : array() as $plugin ) {
			if ( is_string( $plugin ) ) {
				$merged[] = $plugin;
			}
		}
		foreach ( is_array( $local_active ) ? $local_active : array() as $plugin ) {
			if ( is_string( $plugin ) && in_array( strtok( $plugin, '/' ), (array) $kept, true ) && ! in_array( $plugin, $merged, true ) ) {
				$merged[] = $plugin;
			}
		}
		return $merged;
	}

	/**
	 * Real and symlinked top-level names live reports for a code directory.
	 * 'real' is null when live cannot say: a release that predates the layout
	 * report describes its plugins only.
	 *
	 * @param mixed  $response Decoded response of the source `plugins` action.
	 * @param string $dir      'plugins' or 'themes'.
	 * @return array{real:string[]|null,links:string[]}
	 */
	public static function live_top_levels( $response, $dir ) {
		$none = array(
			'real'  => null,
			'links' => array(),
		);
		if ( ! is_array( $response ) ) {
			return $none;
		}
		if ( isset( $response['tops'] ) && is_array( $response['tops'] ) && isset( $response['tops'][ $dir ] ) && is_array( $response['tops'][ $dir ] ) ) {
			$tops = $response['tops'][ $dir ];
			return array(
				'real'  => self::sorted_names(
					array_merge(
						isset( $tops['dirs'] ) && is_array( $tops['dirs'] ) ? $tops['dirs'] : array(),
						isset( $tops['files'] ) && is_array( $tops['files'] ) ? $tops['files'] : array()
					)
				),
				'links' => self::sorted_names( isset( $tops['links'] ) ? $tops['links'] : array() ),
			);
		}
		if ( 'plugins' !== $dir || ! isset( $response['plugins'] ) || ! is_array( $response['plugins'] ) ) {
			return $none;
		}
		$real  = array();
		$links = array();
		foreach ( $response['plugins'] as $plugin ) {
			if ( ! is_array( $plugin ) || ! isset( $plugin['top'] ) ) {
				continue;
			}
			if ( ! empty( $plugin['symlink'] ) ) {
				$links[] = $plugin['top'];
			} else {
				$real[] = $plugin['top'];
			}
		}
		return array(
			'real'  => self::sorted_names( $real ),
			'links' => self::sorted_names( $links ),
		);
	}

	/**
	 * What a sync started now would keep, for the start confirmation. The
	 * plan step decides again from live's file manifest; this stands in live's
	 * reported layout for it, so the two can only differ when live changes
	 * in between. Where live cannot report its layout, nothing is promised.
	 *
	 * @param mixed $response Decoded response of the source `plugins` action.
	 * @return array{plugins:string[],themes:string[]}
	 */
	public static function preview_kept( $response ) {
		$preview = array();
		foreach ( self::KEPT_DIRS as $dir => $option ) {
			$live            = self::live_top_levels( $response, $dir );
			$record          = get_option( $option );
			$preview[ $dir ] = ! is_array( $live['real'] ) ? array() : self::kept_top_level_names(
				self::kept_candidates( $dir, Files::top_level_listing( WP_CONTENT_DIR . '/' . $dir ) ),
				$live['real'],
				is_array( $record ) ? $record : null,
				$live['links'],
				'plugins' === $dir ? self::PROTECTED_PLUGINS : array()
			);
		}
		return $preview;
	}

	/**
	 * Sandbox-only entries the next sync removes because no earlier sync is
	 * recorded yet, for the start confirmation: the one time this still
	 * happens deserves to be said before the sync starts, not in its log.
	 * Empty once a record exists, and where live cannot report its layout.
	 *
	 * @param mixed $response Decoded response of the source `plugins` action.
	 * @return array{plugins:string[],themes:string[]}
	 */
	public static function preview_first_sync_removals( $response ) {
		$removals = array();
		foreach ( self::KEPT_DIRS as $dir => $option ) {
			$removals[ $dir ] = array();
			$live             = self::live_top_levels( $response, $dir );
			if ( is_array( get_option( $option ) ) || ! is_array( $live['real'] ) ) {
				continue;
			}
			$protected        = 'plugins' === $dir ? self::PROTECTED_PLUGINS : array();
			$candidates       = self::kept_candidates( $dir, Files::top_level_listing( WP_CONTENT_DIR . '/' . $dir ) );
			$kept             = self::kept_top_level_names( $candidates, $live['real'], null, $live['links'], $protected );
			$removals[ $dir ] = self::sorted_names( array_diff( $candidates, $protected, $live['real'], $kept ) );
		}
		return $removals;
	}

	/**
	 * One line naming what a finished sync kept, for the history table.
	 * Empty when it kept nothing, or for a report older than this record.
	 *
	 * @param mixed $report Sync report.
	 * @return string
	 */
	public static function kept_summary( $report ) {
		$parts = array();
		foreach ( array_keys( self::KEPT_DIRS ) as $dir ) {
			$names = self::sorted_names( is_array( $report ) && isset( $report[ 'kept_' . $dir ] ) ? $report[ 'kept_' . $dir ] : array() );
			if ( ! empty( $names ) ) {
				$parts[] = 'kept ' . $dir . ': ' . implode( ', ', $names );
			}
		}
		return implode( '; ', $parts );
	}

	/**
	 * Unique non-empty string names, sorted. Names stay values throughout: a
	 * directory called "2024" would turn into an integer as an array key.
	 *
	 * @param mixed $names Candidate names.
	 * @return string[]
	 */
	private static function sorted_names( $names ) {
		$clean = array();
		foreach ( is_array( $names ) ? $names : array() as $name ) {
			if ( is_string( $name ) && '' !== $name ) {
				$clean[] = $name;
			}
		}
		$clean = array_values( array_unique( $clean ) );
		sort( $clean, SORT_STRING );
		return $clean;
	}

	// -- Job lifecycle -----------------------------------------------------------

	/**
	 * Creates a new sync job after checking the direction guardrails.
	 *
	 * @param array $options    Partial options, merged over defaults.
	 * @param bool  $background Schedule the cron worker.
	 * @param int   $user_id    Admin whose session survives the swap.
	 * @return array|\WP_Error The created job.
	 */
	public static function start_job( $options, $background, $user_id ) {
		$connection = Auth::get_connection();
		if ( ! $connection ) {
			return new \WP_Error( 'not_connected', 'This site is not connected to a source site.' );
		}
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			return new \WP_Error( 'multisite_unsupported', 'Multisite synchronization is not supported yet; refusing a partial network clone.' );
		}

		// Guardrail 1: explicit opt-in. Syncing OVERWRITES this site.
		if ( ! Auth::is_sandbox_site() ) {
			return new \WP_Error(
				'not_a_sandbox',
				'This site is not marked as a sandbox. An administrator must explicitly flag it as a sandbox before its content can be overwritten by a sync.'
			);
		}

		// Guardrail 2: a source site (has paired sandboxes) must never be
		// overwritten by a sync — that is the wrong direction.
		if ( Auth::is_source_paired() ) {
			return new \WP_Error(
				'is_a_source',
				'This site has paired sandboxes (it acts as a live/source site). Refusing to overwrite it. Revoke its sandboxes first if this is intentional.'
			);
		}
		if ( ! Job::acquire_slice_lock( 'job-start' ) ) {
			return new \WP_Error( 'job_start_busy', 'Another worker is creating or recovering a sync job. Retry shortly.' );
		}

		try {
			$existing = Job::get();
			if ( Job::is_active( $existing ) ) {
				if ( ( time() - (int) $existing['heartbeat'] ) < self::STALE_JOB_SECONDS ) {
					return new \WP_Error( 'job_running', 'A sync is already running. Cancel it first or wait for it to finish.' );
				}
				// Dead job (no heartbeat for a long time): mark failed, clean up, move on.
				$existing['status'] = Job::S_FAILED;
				$existing['error']  = 'Job abandoned (no heartbeat).';
				$existing['error_info'] = Error_Info::from_wp_error( new \WP_Error( 'job_abandoned', $existing['error'] ) );
				Job::save( $existing );
				$rolled_back = self::rollback_database( $existing );
				if ( is_wp_error( $rolled_back ) ) {
					return $rolled_back;
				}
				self::rollback_file_dirs( $existing );
				Safety::end_activation_guard( $existing['id'] );
				Client::end_transfer_session();
				Safety::restore_runtime_guard( isset( $existing['runtime_guard_previous'] ) ? $existing['runtime_guard_previous'] : array() );
				self::drop_working_tables( $existing );
			}

			$defaults = self::default_options();
			$clean    = array(
				'db'                 => ! empty( $options['db'] ),
				'incremental'        => ! empty( $options['incremental'] ),
				'uploads_fast'       => ! array_key_exists( 'uploads_fast', $options ) || ! empty( $options['uploads_fast'] ),
				'uploads_full_audit' => ! empty( $options['uploads_full_audit'] ),
				'uploads_adopt'      => ! empty( $options['uploads_adopt'] ),
				'dirs'               => array(),
				'excluded_tables'    => array(),
				'schema_only_tables' => array(),
			);
			$dirs     = isset( $options['dirs'] ) && is_array( $options['dirs'] ) ? $options['dirs'] : $defaults['dirs'];
			foreach ( $dirs as $dir ) {
				if ( in_array( $dir, Source_Endpoints::SYNCABLE_DIRS, true ) ) {
					$clean['dirs'][] = $dir;
				}
			}
			$excluded = isset( $options['excluded_tables'] ) && is_array( $options['excluded_tables'] ) ? $options['excluded_tables'] : array();
			foreach ( $excluded as $suffix ) {
				$suffix = strtolower( trim( (string) $suffix ) );
				if ( '' !== $suffix && preg_match( '/^[a-z0-9_]+$/', $suffix ) ) {
					$clean['excluded_tables'][] = $suffix;
				}
			}
			$schema_only = isset( $options['schema_only_tables'] ) && is_array( $options['schema_only_tables'] )
				? $options['schema_only_tables']
				: $defaults['schema_only_tables'];
			foreach ( $schema_only as $suffix ) {
				$suffix = strtolower( trim( (string) $suffix ) );
				if ( '' !== $suffix
					&& preg_match( '/^[a-z0-9_]+$/', $suffix )
					&& ! in_array( $suffix, $clean['excluded_tables'], true ) ) {
					$clean['schema_only_tables'][] = $suffix;
				}
			}
			$clean['excluded_tables']    = array_values( array_unique( $clean['excluded_tables'] ) );
			$clean['schema_only_tables'] = array_values( array_unique( $clean['schema_only_tables'] ) );

			// Safety rules: only known keys pass through. Absent key = defaults;
			// an explicit empty array means "apply no rules" (still clears the
			// email block / constants set by earlier syncs).
			$clean['safety'] = array();
			$safety          = array_key_exists( 'safety', $options ) && is_array( $options['safety'] ) ? $options['safety'] : $defaults['safety'];
			foreach ( $safety as $key ) {
				if ( array_key_exists( $key, Safety::rules() ) ) {
					$clean['safety'][] = $key;
				}
			}

			// Per-plugin selection: null/absent = all plugins.
			$clean['plugins'] = null;
			if ( isset( $options['plugins'] ) && is_array( $options['plugins'] ) ) {
				$clean['plugins'] = array();
				foreach ( $options['plugins'] as $top ) {
					$top = basename( trim( (string) $top ) );
					if ( '' !== $top && preg_match( '/^[A-Za-z0-9._-]+$/', $top ) ) {
						$clean['plugins'][] = $top;
					}
				}
			}

			// Uploads date scopes: null/absent = everything.
			$clean['uploads_scope'] = null;
			if ( isset( $options['uploads_scope'] ) && is_array( $options['uploads_scope'] ) ) {
				$clean['uploads_scope'] = array();
				foreach ( $options['uploads_scope'] as $scope ) {
					$scope = trim( (string) $scope );
					if ( 'other' === $scope || preg_match( '#^\d{4}(/\d{2})?$#', $scope ) ) {
						$clean['uploads_scope'][] = $scope;
					}
				}
			}

			// Live wp-config.php constants to replicate (validated again against
			// the source's offered list when the safety step runs).
			$clean['config_constants'] = array();
			if ( isset( $options['config_constants'] ) && is_array( $options['config_constants'] ) ) {
				foreach ( $options['config_constants'] as $name ) {
					$name = trim( (string) $name );
					if ( preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', $name ) ) {
						$clean['config_constants'][] = $name;
					}
				}
			}

			if ( ! $clean['db'] && empty( $clean['dirs'] ) ) {
				return new \WP_Error( 'nothing_selected', 'Nothing selected: enable the database and/or at least one directory.' );
			}

			Client::reset_transfer_session();
			$job                           = Job::create( $clean, $user_id, $background );
			$job['runtime_guard_previous'] = Safety::engage_runtime_guard( $connection['source_url'] );
			Job::log(
				$job,
				sprintf(
					'Job %s created (db: %s, dirs: %s, incremental: %s, mode: %s).',
					$job['id'],
					$clean['db'] ? 'yes' : 'no',
					$clean['dirs'] ? implode( ' ', $clean['dirs'] ) : 'none',
					$clean['incremental'] ? 'yes' : 'no',
					$background ? 'background' : 'foreground'
				)
			);
			Job::save( $job );

			if ( $background ) {
				self::schedule_worker();
			}

			return $job;
		} finally {
			Job::release_slice_lock();
		}
	}

	/**
	 * Cancels the active job and drops working tables.
	 */
	public static function cancel_job() {
		$job = Job::get();
		if ( ! Job::is_active( $job ) ) {
			return new \WP_Error( 'no_job', 'No sync is currently running.' );
		}
		Job::request_cancel( $job['id'] );
		$job['cancel_requested'] = true;
		$source_cancelled = Client::request_transfer_cancel();
		if ( is_wp_error( $source_cancelled ) ) {
			Job::log( $job, 'Cancellation requested locally; the live abort signal was not acknowledged (' . $source_cancelled->get_error_message() . '). The worker will still stop at its next safe checkpoint.' );
		} else {
			Job::log( $job, 'Cancellation requested locally and signalled to the live source; active source traversal will stop cooperatively.' );
		}
		Job::save( $job );
		self::schedule_worker();
		return $job;
	}

	private static function finalize_cancel( &$job ) {
		$job['status']           = Job::S_CANCELLED;
		$job['cancel_requested'] = false;
		$job['finished_at']      = time();
		$job['heartbeat']        = time();
		$db_rolled               = self::rollback_database( $job );
		$rolled                  = self::rollback_file_dirs( $job );
		$session_closed          = Client::end_transfer_session();
		Safety::end_activation_guard( $job['id'] );
		Safety::restore_runtime_guard( isset( $job['runtime_guard_previous'] ) ? $job['runtime_guard_previous'] : array() );
		Job::log( $job, sprintf( 'Job cancelled at a safe checkpoint; %d database table(s) and %d file directorie(s) rolled back.', is_wp_error( $db_rolled ) ? 0 : $db_rolled, $rolled ) );
		if ( is_wp_error( $db_rolled ) ) {
			$job['status'] = Job::S_FAILED;
			$job['error']  = 'Cancellation reached a safe checkpoint, but database rollback failed: ' . $db_rolled->get_error_message();
			$job['error_info'] = Error_Info::from_wp_error( new \WP_Error( 'rollback_failed', $job['error'] ) );
			Job::log( $job, 'DATABASE ROLLBACK FAILED: ' . $db_rolled->get_error_message() );
		} else {
			self::drop_working_tables( $job );
		}
		if ( is_wp_error( $session_closed ) ) {
			Job::log( $job, 'SOURCE TRANSFER SESSION RELEASE FAILED (it will expire automatically): ' . $session_closed->get_error_message() );
		}
		Job::clear_cancel_request();
		Job::save( $job );
		self::write_report( $job, $job['status'] );
	}

	/**
	 * Renews the fenced lease and publishes a heartbeat from inside long steps.
	 *
	 * @return true|\WP_Error
	 */
	private static function checkpoint( &$job, $maintain_source = true, $lease_ttl = null ) {
		if ( Job::is_cancel_requested( $job['id'] ) ) {
			return new \WP_Error( 'job_cancelled', 'Cancellation requested.' );
		}
		if ( ! Job::renew_slice_lock( $lease_ttl ) ) {
			return new \WP_Error( 'lease_lost', 'Job execution lease was lost; stopping before further mutation.' );
		}
		if ( $maintain_source ) {
			$maintained = self::maintain_source_transfer( $job );
			if ( is_wp_error( $maintained ) ) {
				return $maintained;
			}
		}
		if ( ! empty( $job['activation_guard_active'] ) ) {
			$guarded = Safety::begin_activation_guard( $job['id'] );
			if ( is_wp_error( $guarded ) ) {
				return $guarded;
			}
		}
		$job['heartbeat'] = time();
		Job::save( $job );
		return true;
	}

	/** @return true|\WP_Error */
	public static function checkpoint_public( &$job, $maintain_source = true, $lease_ttl = null ) {
		return self::checkpoint( $job, $maintain_source, $lease_ttl );
	}

	/** @return array */
	public static function build_replacement_pairs_public( $source_site ) {
		return self::build_replacement_pairs( $source_site );
	}

	/** @return int */
	public static function database_statement_limit_public( $packet = null ) {
		return self::database_statement_limit( $packet );
	}

	/**
	 * Removes any finished/failed job record and leftover working tables.
	 */
	public static function cleanup() {
		$job = Job::get();
		if ( Job::is_active( $job ) && ( time() - (int) $job['heartbeat'] ) < self::STALE_JOB_SECONDS ) {
			return new \WP_Error( 'job_running', 'A sync is running; cancel it instead.' );
		}
		$job_id = is_array( $job ) && isset( $job['id'] ) ? $job['id'] : '';
		if ( ! Job::acquire_slice_lock( $job_id ) ) {
			return new \WP_Error( 'cleanup_busy', 'Cleanup could not acquire the job lease; another worker is still active.' );
		}
		$db_rolled = self::rollback_database( $job );
		if ( is_wp_error( $db_rolled ) ) {
			Job::release_slice_lock();
			return $db_rolled;
		}
		self::rollback_file_dirs( $job );
		if ( is_array( $job ) && ! empty( $job['id'] ) ) {
			Safety::end_activation_guard( $job['id'] );
		}
		Client::end_transfer_session();
		$dropped = self::drop_working_tables( $job );
		Job::delete();
		Job::clear_cancel_request();
		Job::release_slice_lock();
		return $dropped;
	}

	public static function schedule_worker() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 2, self::CRON_HOOK );
		}
	}

	/**
	 * Cron worker entry point.
	 */
	public static function cron_worker() {
		self::process_slice( 20 );
	}

	// -- Slice processing ---------------------------------------------------------

	/**
	 * Processes queued steps for up to $budget seconds. Safe to call from
	 * anywhere: returns immediately if another process holds the slice lock.
	 *
	 * @return array|null The job after processing.
	 */
	public static function process_slice( $budget = 15 ) {
		$job = Job::get();
		if ( ! Job::is_active( $job ) ) {
			return $job;
		}
		if ( ! Job::acquire_slice_lock( $job['id'] ) ) {
			return $job;
		}

		set_time_limit( 0 );
		ignore_user_abort( true );
		$slice_start          = time();
		$lease_lost           = false;
		$session_checked      = false;
		self::$slice_deadline = $slice_start + max( 1, (int) $budget );

		try {
			while ( Job::is_active( $job ) && $job['current'] < count( $job['steps'] ) ) {
				// Another request may have cancelled the job mid-slice.
				if ( Job::is_cancel_requested( $job['id'] ) ) {
					self::finalize_cancel( $job );
					break;
				}
				if ( ! Job::renew_slice_lock() ) {
					$job        = Job::get();
					$lease_lost = true;
					break;
				}

				$index                            = $job['current'];
				$job['steps'][ $index ]['status'] = 'running';
				// A yielded step keeps its original start time across re-entries.
				if ( empty( $job['steps'][ $index ]['yielded'] ) || empty( $job['steps'][ $index ]['started_at'] ) ) {
					$job['steps'][ $index ]['started_at'] = time();
				}
				$job['heartbeat'] = time();
				Job::save( $job );

				$session_ready   = $session_checked ? true : self::maintain_source_transfer( $job );
				$session_checked = true;
				$result          = is_wp_error( $session_ready ) ? $session_ready : self::run_step( $job, $index );
				// Cancellation can arrive while a blocking source request is in
				// progress. Prefer the operator's cancellation over classifying the
				// source's cooperative abort response as an ordinary job failure.
				if ( Job::is_cancel_requested( $job['id'] ) ) {
					self::finalize_cancel( $job );
					break;
				}

				$job['steps'][ $index ]['finished_at'] = time();
				if ( is_wp_error( $result ) ) {
					if ( 'job_cancelled' === $result->get_error_code() ) {
						self::finalize_cancel( $job );
						break;
					}
					if ( 'lease_lost' === $result->get_error_code() ) {
						$job        = Job::get();
						$lease_lost = true;
						break;
					}
					$job['steps'][ $index ]['status']  = 'failed';
					$job['steps'][ $index ]['message'] = $result->get_error_message();
					$job['status']                     = Job::S_FAILED;
					$job['error']                      = $result->get_error_message();
					$job['error_info']                 = Error_Info::from_wp_error( $result );
					$job['finished_at']                = time();
					Job::log( $job, 'FAILED: ' . $result->get_error_message() );
					$db_rolled = self::rollback_database( $job );
					if ( is_wp_error( $db_rolled ) ) {
						Job::log( $job, 'DATABASE ROLLBACK FAILED: ' . $db_rolled->get_error_message() );
					}
					$rolled         = self::rollback_file_dirs( $job );
					$session_closed = Client::end_transfer_session();
					Safety::end_activation_guard( $job['id'] );
					Safety::restore_runtime_guard( isset( $job['runtime_guard_previous'] ) ? $job['runtime_guard_previous'] : array() );
					if ( $rolled > 0 ) {
						Job::log( $job, sprintf( 'Rolled back %d activated file directorie(s).', $rolled ) );
					}
					if ( is_wp_error( $session_closed ) ) {
						Job::log( $job, 'SOURCE TRANSFER SESSION RELEASE FAILED (it will expire automatically): ' . $session_closed->get_error_message() );
					}
					if ( ! is_wp_error( $db_rolled ) ) {
						self::drop_working_tables( $job );
					}
					Job::save( $job );
					self::write_report( $job, 'failed' );
					break;
				}

				if ( ! empty( $result['yield'] ) ) {
					// The step committed durable progress and paused at the slice
					// deadline; it stays current and re-enters on the next tick.
					// This bounds every worker process to one slice budget so a
					// platform request kill can no longer strand a long step.
					$job['steps'][ $index ]['status']  = 'running';
					$job['steps'][ $index ]['yielded'] = true;
					if ( ! empty( $result['message'] ) ) {
						$job['steps'][ $index ]['message'] = $result['message'];
					}
					$job['heartbeat'] = time();
					Job::save( $job );
					break;
				}

				$job['steps'][ $index ]['status']  = ! empty( $result['skipped'] ) ? 'skipped' : 'done';
				$job['steps'][ $index ]['message'] = $result['message'];
				$job['steps'][ $index ]['yielded'] = false;
				Job::log( $job, $result['message'] );
				++$job['current'];

				if ( $job['current'] >= count( $job['steps'] ) ) {
					$job['status']      = Job::S_DONE;
					$job['finished_at'] = time();
					Job::log( $job, sprintf( 'Sync complete in %s.', Job::format_duration( time() - (int) $job['created_at'] ) ) );
				}

				$job['heartbeat'] = time();
				Job::save( $job );

				if ( ( time() - $slice_start ) >= $budget ) {
					break;
				}
			}
		} finally {
			self::$slice_deadline = null;
			Job::release_slice_lock();
		}

		if ( Job::is_active( $job ) && ! empty( $job['background'] ) ) {
			self::schedule_worker();
		}
		if ( $lease_lost ) {
			// Never overwrite the successor's state after fencing. A foreground
			// poll or the worker scheduled above will reacquire the lease and
			// replay the current idempotent step immediately.
			return Job::get();
		}

		return $job;
	}

	// -- Step planning --------------------------------------------------------------

	/**
	 * Builds the step list that follows the manifest step. Pure function
	 * (unit-tested): no I/O, no globals.
	 *
	 * @param array $manifest ['site' =>, 'tables' =>, 'dirs' =>].
	 * @param array $options  Normalized job options.
	 * @return array[] Steps.
	 */
	public static function plan_steps_from_manifest( $manifest, $options ) {
		$steps = array();
		$dirs  = array();

		$source_prefix = isset( $manifest['site']['prefix'] ) ? $manifest['site']['prefix'] : '';

		foreach ( (array) $options['dirs'] as $dir ) {
			if ( in_array( $dir, (array) $manifest['dirs'], true ) ) {
				$dirs[]  = $dir;
				$steps[] = Job::make_step( 'dir_plan', "Plan files: {$dir}/", array( 'dir' => $dir ) );
			}
		}
		$fast_uploads = in_array( 'uploads', $dirs, true ) && ! empty( $options['incremental'] ) && ! empty( $options['uploads_fast'] );
		if ( ! empty( $options['db'] ) ) {
			$steps[] = Job::make_step( 'refresh_manifest', 'Refresh the database manifest after online pre-copy' );
		}
		if ( $fast_uploads ) {
			$steps[] = Job::make_step( 'uploads_finalize', 'Guard sandbox and close the final uploads delta' );
		}

		if ( ! empty( $options['db'] ) ) {
			$table_steps = array();
			foreach ( (array) $manifest['tables'] as $table ) {
				$suffix = ( '' !== $source_prefix && 0 === strpos( $table['name'], $source_prefix ) )
					? substr( $table['name'], strlen( $source_prefix ) )
					: $table['name'];
				if ( in_array( strtolower( $suffix ), (array) $options['excluded_tables'], true ) ) {
					continue;
				}
				$schema_only = in_array( strtolower( $suffix ), (array) ( $options['schema_only_tables'] ?? array() ), true );
				$table_steps[] = Job::make_step(
					'table',
					$schema_only
						? "Prepare empty schema-only table: {$table['name']}"
						: "Import table: {$table['name']} (~{$table['rows']} rows)",
					array(
						'table'       => $table['name'],
						'schema_only' => $schema_only,
					)
				);
			}
			// posts before postmeta so the row plan exists before meta is scoped.
			usort(
				$table_steps,
				function ( $a, $b ) use ( $source_prefix ) {
					$rank = function ( $step ) use ( $source_prefix ) {
						$name = isset( $step['args']['table'] ) ? (string) $step['args']['table'] : '';
						$suf  = Table_Delta::table_suffix( $name, $source_prefix );
						if ( 'posts' === $suf ) {
							return 0;
						}
						if ( 'postmeta' === $suf ) {
							return 1;
						}
						return 2;
					};
					return $rank( $a ) - $rank( $b );
				}
			);
			foreach ( $table_steps as $step ) {
				$steps[] = $step;
			}
			$steps[] = Job::make_step( 'validate_db', 'Check the imported snapshot against the live database' );
			$steps[] = Job::make_step( 'search_replace', 'Search & replace URLs' );
			$steps[] = Job::make_step( 'activate', 'Activate database under the sandbox traffic guard' );
		}
		foreach ( $dirs as $dir ) {
			$steps[] = Job::make_step( 'dir_activate', "Activate files: {$dir}/", array( 'dir' => $dir ) );
		}
		$steps[] = Job::make_step( 'safety', 'Apply sandbox safety rules to the activated snapshot' );

		$steps[] = Job::make_step( 'finalize', 'Finalize sync and remove rollback data' );

		return $steps;
	}

	/** Exact database scope used both for initial binding and session recovery. */
	public static function transfer_table_scope( $job ) {
		$scope = array(
			'tables'             => array(),
			'schema_only_tables' => array(),
		);
		if ( empty( $job['options']['db'] ) || empty( $job['manifest']['tables'] ) ) {
			return $scope;
		}
		$prefix      = isset( $job['manifest']['site']['prefix'] ) ? (string) $job['manifest']['site']['prefix'] : '';
		$excluded    = array_map( 'strtolower', (array) ( $job['options']['excluded_tables'] ?? array() ) );
		$schema_only = array_map( 'strtolower', (array) ( $job['options']['schema_only_tables'] ?? array() ) );
		foreach ( (array) $job['manifest']['tables'] as $table ) {
			if ( ! is_array( $table ) || empty( $table['name'] ) ) {
				continue;
			}
			$name   = (string) $table['name'];
			$suffix = '' !== $prefix && 0 === strpos( $name, $prefix ) ? substr( $name, strlen( $prefix ) ) : $name;
			$suffix = strtolower( $suffix );
			if ( in_array( $suffix, $excluded, true ) ) {
				continue;
			}
			$scope['tables'][] = $name;
			if ( in_array( $suffix, $schema_only, true ) ) {
				$scope['schema_only_tables'][] = $name;
			}
		}
		return $scope;
	}

	/** Renews local-work keepalive or narrowly reopens an expired source grant. */
	private static function maintain_source_transfer( &$job ) {
		$scope      = self::transfer_table_scope( $job );
		$maintained = Client::maintain_transfer_session( $job['options'], $scope['tables'], $scope['schema_only_tables'] );
		if ( is_wp_error( $maintained ) ) {
			return $maintained;
		}
		if ( 'reopened' === $maintained ) {
			Job::log( $job, 'Reopened an expired source transfer session with the same job ID and exact directory/table scope; resuming from the durable batch checkpoint.' );
		}
		return true;
	}

	// -- Step executors ---------------------------------------------------------------

	/**
	 * @param array $job   By reference; steps may be inserted.
	 * @param int   $index Step being executed.
	 * @return array|\WP_Error [ 'message' => string, 'skipped' => bool? ]
	 */
	private static function run_step( &$job, $index ) {
		$step = $job['steps'][ $index ];
		$args = $step['args'];

		switch ( $step['type'] ) {
			case 'transfer_session':
				return self::step_transfer_session( $job );
			case 'manifest':
				return self::step_manifest( $job, $index );
			case 'refresh_manifest':
				return self::step_refresh_manifest( $job );
			case 'dir_plan':
				return self::step_dir_plan( $job, $index, $args['dir'] );
			case 'dir_fetch':
				return self::step_dir_fetch( $job, $args['dir'], $args['batch_file'], isset( $args['scope'] ) ? (string) $args['scope'] : '' );
			case 'dir_file':
				return self::step_dir_file( $job, $args['dir'], $args['entry'], isset( $args['scope'] ) ? (string) $args['scope'] : '' );
			case 'uploads_bulk':
				return self::step_uploads_bulk_baseline( $job, $index, $args );
			case 'uploads_finalize':
				return self::step_uploads_finalize( $job, $index );
			case 'dir_activate':
				return self::step_dir_activate( $job, $args['dir'] );
			case 'table':
				return self::step_table( $job, $args['table'], ! empty( $args['schema_only'] ) );
			case 'validate_db':
				return self::step_validate_db( $job );
			case 'search_replace':
				return self::step_search_replace( $job );
			case 'swap':
				return self::step_swap( $job );
			case 'activate':
				return self::step_activate( $job );
			case 'safety':
				return self::step_safety( $job );
			case 'finalize':
				return self::step_finalize( $job );
		}
		return new \WP_Error( 'bad_step', "Unknown step type: {$step['type']}" );
	}

	/**
	 * Rebinds row/checksum/schema metadata right before table import. Online
	 * pre-copy can take hours; ordinary live writes during that time must become
	 * part of this snapshot rather than being exported from stale metadata.
	 */
	private static function step_refresh_manifest( &$job ) {
		$manifest = Client::get_json( 'manifest', array( 'checksums' => 1 ) );
		if ( is_wp_error( $manifest ) ) {
			return $manifest;
		}
		if ( ! isset( $manifest['site']['protocol'], $manifest['tables'] ) || SANDBOX_SYNC_PROTOCOL !== (int) $manifest['site']['protocol'] ) {
			return new \WP_Error( 'refresh_manifest', 'Source returned an invalid refreshed database manifest.' );
		}
		$old_names = array_column( (array) $job['manifest']['tables'], 'name' );
		$new_names = array_column( (array) $manifest['tables'], 'name' );
		sort( $old_names, SORT_STRING );
		sort( $new_names, SORT_STRING );
		if ( $old_names !== $new_names ) {
			return new \WP_Error( 'table_set_changed', 'The live table set changed during online file pre-copy. Retry to build a new exact plan.' );
		}
		$preflight = self::database_preflight( $manifest, $job['options'] );
		if ( is_wp_error( $preflight ) ) {
			return $preflight;
		}
		$job['preflight']          = array_merge( (array) $job['preflight'], $preflight );
		$job['manifest']['site']   = $manifest['site'];
		$job['manifest']['tables'] = $manifest['tables'];

		// Re-evaluate the options/users/usermeta consistency group against the
		// refreshed checksums. If any member changed during pre-copy, import all.
		$prefix   = (string) $manifest['site']['prefix'];
		$identity = array( $prefix . 'options', $prefix . 'users', $prefix . 'usermeta' );
		$by_name  = array();
		foreach ( (array) $manifest['tables'] as $table ) {
			$by_name[ $table['name'] ] = $table;
		}
		if ( ! empty( $job['options']['incremental'] ) && 3 === count( array_intersect( $identity, array_keys( $by_name ) ) ) ) {
			$state = get_option( self::OPT_TABLE_STATE );
			$state = is_array( $state ) ? $state : array();
			$all_unchanged = true;
			foreach ( $identity as $source_table ) {
				$target = self::target_table_name( $prefix, $source_table );
				$entry  = $by_name[ $source_table ];
				$all_unchanged = $all_unchanged
					&& isset( $entry['checksum'], $state[ $target ]['source_checksum'], $state[ $target ]['local_checksum'] )
					&& null !== $entry['checksum']
					&& (string) $entry['checksum'] === (string) $state[ $target ]['source_checksum']
					&& self::table_exists( $target )
					&& (string) DB_Exporter::table_checksum( $target ) === (string) $state[ $target ]['local_checksum'];
			}
			$job['force_tables'] = $all_unchanged ? array() : $identity;
		}
		// A sync that does not plan plugins/ still replaces the activation
		// list, so it needs the same answer about which plugins live lacks.
		if ( ! isset( $job['kept_plugins'] ) ) {
			$live_tops = self::live_tops( $job );
			if ( is_wp_error( $live_tops ) ) {
				return $live_tops;
			}
			$checkpoint = self::checkpoint( $job );
			if ( is_wp_error( $checkpoint ) ) {
				return $checkpoint;
			}
			self::plan_activation_carry( $job, $live_tops );
		}
		Job::save( $job );
		return array( 'message' => sprintf( 'Database manifest refreshed after online pre-copy: %d tables rebound to fresh row/checksum metadata.', count( $manifest['tables'] ) ) );
	}

	private static function step_transfer_session( &$job ) {
		$result = Client::begin_transfer_session( $job['options'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array( 'message' => 'Opened an expiring source transfer session scoped to the selected database and content directories.' );
	}

	private static function step_manifest( &$job, $index ) {
		$want_checksums = ! empty( $job['options']['incremental'] ) && ! empty( $job['options']['db'] );

		$manifest = Client::get_json( 'manifest', $want_checksums ? array( 'checksums' => 1 ) : array() );
		if ( is_wp_error( $manifest ) ) {
			return $manifest;
		}
		$checkpoint = self::checkpoint( $job );
		if ( is_wp_error( $checkpoint ) ) {
			return $checkpoint;
		}
		if ( empty( $manifest['site']['prefix'] ) || ( ! empty( $job['options']['db'] ) && empty( $manifest['tables'] ) ) ) {
			return new \WP_Error( 'bad_manifest', 'Source returned an empty or invalid manifest.' );
		}
		if ( ! isset( $manifest['site']['protocol'] ) || SANDBOX_SYNC_PROTOCOL !== (int) $manifest['site']['protocol'] ) {
			return new \WP_Error( 'protocol_mismatch', sprintf( 'Source transfer protocol is incompatible (source: %s, sandbox: %d). Upgrade Sandbox Sync on both sites.', $manifest['site']['protocol'] ?? 'missing', SANDBOX_SYNC_PROTOCOL ) );
		}
		$preflight = self::database_preflight( $manifest, $job['options'] );
		if ( is_wp_error( $preflight ) ) {
			return $preflight;
		}
		$job['preflight'] = $preflight;
		$connection       = Auth::get_connection();
		if ( $connection ) {
			$job['preflight']['transport'] = Client::transport_security( $connection['source_url'] );
		}
		if ( ! empty( $job['options']['db'] ) ) {
			$source_prefix = (string) $manifest['site']['prefix'];
			$unsupported   = array();
			foreach ( (array) $manifest['tables'] as $table ) {
				$suffix = 0 === strpos( $table['name'], $source_prefix ) ? substr( $table['name'], strlen( $source_prefix ) ) : $table['name'];
				if ( in_array( strtolower( $suffix ), (array) $job['options']['excluded_tables'], true ) ) {
					continue;
				}
				$schema_only = in_array( strtolower( $suffix ), (array) ( $job['options']['schema_only_tables'] ?? array() ), true );
				$supported   = $schema_only ? ! empty( $table['compat']['schema_only_supported'] ) : ! empty( $table['compat']['supported'] );
				if ( ! $supported ) {
					$reasons       = $schema_only ? ( $table['compat']['schema_only_reasons'] ?? array() ) : ( $table['compat']['reasons'] ?? array() );
					$policy        = $schema_only ? 'schema-only' : 'full-sync';
					$unsupported[] = $table['name'] . " [{$policy}] (" . implode( '; ', (array) ( $reasons ? $reasons : array( 'compatibility metadata missing' ) ) ) . ')';
				}
			}
			if ( ! empty( $unsupported ) ) {
				return new \WP_Error( 'unsupported_tables', 'Database preflight found unsupported tables; exclude or migrate them before syncing: ' . implode( ', ', $unsupported ) );
			}
		}

		// Final direction sanity check: never sync a site onto itself.
		$source_home = untrailingslashit( (string) $manifest['site']['home'] );
		if ( untrailingslashit( home_url() ) === $source_home ) {
			return new \WP_Error( 'self_sync', 'The source site reports the same URL as this site. Refusing to sync a site onto itself.' );
		}

		$job['manifest'] = array(
			'site'   => $manifest['site'],
			'tables' => $manifest['tables'],
			'dirs'   => isset( $manifest['dirs'] ) ? $manifest['dirs'] : array(),
		);

		// WordPress identity is a three-table consistency group. Importing only
		// users or usermeta can mismatch IDs/capabilities, while importing options
		// alone can replace role definitions. Either all three remain unchanged,
		// or all three are forced through the same atomic swap.
		if ( ! empty( $job['options']['db'] ) ) {
			$prefix   = $job['manifest']['site']['prefix'];
			$identity = array( $prefix . 'options', $prefix . 'users', $prefix . 'usermeta' );
			foreach ( array( 'options', 'users', 'usermeta' ) as $identity_suffix ) {
				if ( in_array( $identity_suffix, (array) ( $job['options']['schema_only_tables'] ?? array() ), true ) ) {
					return new \WP_Error( 'identity_schema_only', 'options, users and usermeta cannot be schema-only because sandbox identity preservation requires their complete row data.' );
				}
			}
			$included = array();
			$by_name  = array();
			foreach ( $job['manifest']['tables'] as $table ) {
				$by_name[ $table['name'] ] = $table;
			}
			foreach ( $identity as $table ) {
				$suffix = substr( $table, strlen( $prefix ) );
				if ( isset( $by_name[ $table ] ) && ! in_array( strtolower( $suffix ), (array) $job['options']['excluded_tables'], true ) ) {
					$included[] = $table;
				}
			}
			if ( count( $included ) > 0 && count( $included ) < 3 ) {
				return new \WP_Error( 'identity_group', 'options, users and usermeta must be synced or excluded as one consistency group.' );
			}
			if ( 3 === count( $included ) && ! empty( $job['options']['incremental'] ) ) {
				$state         = get_option( self::OPT_TABLE_STATE );
				$state         = is_array( $state ) ? $state : array();
				$all_unchanged = true;
				foreach ( $identity as $source_table ) {
					$target        = self::target_table_name( $prefix, $source_table );
					$entry         = $by_name[ $source_table ];
					$all_unchanged = $all_unchanged
						&& isset( $entry['checksum'], $state[ $target ]['source_checksum'], $state[ $target ]['local_checksum'] )
						&& null !== $entry['checksum']
						&& (string) $entry['checksum'] === (string) $state[ $target ]['source_checksum']
						&& self::table_exists( $target )
						&& (string) DB_Exporter::table_checksum( $target ) === (string) $state[ $target ]['local_checksum'];
				}
				if ( ! $all_unchanged ) {
					$job['force_tables'] = $identity;
				}
			}
			$scope  = self::transfer_table_scope( $job );
			$scoped = Client::restrict_transfer_tables( $scope['tables'], $scope['schema_only_tables'] );
			if ( is_wp_error( $scoped ) ) {
				return $scoped;
			}
		}

		$steps = self::plan_steps_from_manifest( $job['manifest'], $job['options'] );
		// Persist the complete, exact working-table ownership set once while the
		// plan is published. Per-table steps then avoid two option writes apiece,
		// while cleanup remains safe even if a worker dies during table creation.
		$working_tables = array();
		foreach ( $steps as $step ) {
			if ( 'table' !== $step['type'] ) {
				continue;
			}
			$source_table = $step['args']['table'];
			$target       = self::target_table_name( $job['manifest']['site']['prefix'], $source_table );
			$working_tables[] = self::working_table_name( 'shadow', $job['id'], $target );
			$working_tables[] = self::working_table_name( 'checkpoint', $job['id'], $target . '_checkpoint' );
			$working_tables[] = self::working_table_name( 'backup', $job['id'], $target );
		}
		self::register_working_tables( $job, $working_tables );
		Job::insert_steps( $job, $index, $steps );

		$total_size_mb = round( array_sum( wp_list_pluck( $manifest['tables'], 'size' ) ) / 1048576, 1 );
		$transport     = isset( $job['preflight']['transport'] ) ? $job['preflight']['transport'] : array();
		$tls           = ! empty( $transport ) ? sprintf( ' Transport: %s://%s%s, certificate verification %s%s.', $transport['scheme'], $transport['host'], ! empty( $transport['resolved_ip'] ) ? ' (' . $transport['resolved_ip'] . ')' : '', ! empty( $transport['verified'] ) ? 'ON' : 'OFF', ! empty( $transport['subject'] ) ? ', leaf ' . $transport['subject'] : '' ) : '';
		return array(
			'message' => sprintf(
				'Manifest received from %s: %d tables (~%s MB), dirs: %s. %d steps planned.%s',
				$manifest['site']['home'],
				count( $manifest['tables'] ),
				$total_size_mb,
				implode( ', ', array_intersect( (array) $job['options']['dirs'], (array) $job['manifest']['dirs'] ) ),
				count( $steps ),
				$tls
			),
		);
	}

	private static function step_safety( &$job ) {
		$checkpoint = self::checkpoint( $job );
		if ( is_wp_error( $checkpoint ) ) {
			return $checkpoint;
		}
		$enabled  = isset( $job['options']['safety'] ) ? (array) $job['options']['safety'] : array();
		$selected = isset( $job['options']['config_constants'] ) ? (array) $job['options']['config_constants'] : array();

		// Values were deliberately omitted from discovery. Fetch only the exact
		// names bound to this active transfer session and fail if the source
		// selection changed before safety activation.
		$replicated = array();
		if ( ! empty( $selected ) ) {
			$response = Client::post_json( 'constants', array( 'names' => array_values( array_unique( $selected ) ) ) );
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			$checkpoint = self::checkpoint( $job );
			if ( is_wp_error( $checkpoint ) ) {
				return $checkpoint;
			}
			foreach ( (array) $response['constants'] as $constant ) {
				if ( in_array( $constant['name'], $selected, true ) ) {
					$replicated[ $constant['name'] ] = $constant['value'];
				}
			}
			if ( count( $replicated ) !== count( array_unique( $selected ) ) ) {
				return new \WP_Error( 'constant_selection_changed', 'The source did not return every selected wp-config.php constant.' );
			}
			Job::log( $job, 'Safety: replicating ' . count( $replicated ) . ' wp-config constant(s) from live: ' . implode( ', ', array_keys( $replicated ) ) . '.' );
		}

		$result = Safety::apply(
			$enabled,
			$replicated,
			array(
				'preserve_user_id' => ! empty( $job['preserved_user_id'] ) ? (int) $job['preserved_user_id'] : (int) $job['user_id'],
				'database_synced'  => ! empty( $job['options']['db'] ),
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		foreach ( $result as $line ) {
			Job::log( $job, 'Safety: ' . $line );
		}

		if ( empty( $enabled ) && empty( $replicated ) ) {
			return array(
				'message' => 'No safety rules selected; cleared any previously applied stateful rules.',
				'skipped' => true,
			);
		}

		$message = sprintf( 'Applied %d safety rule(s)%s.', count( $enabled ), $enabled ? ': ' . implode( ', ', $enabled ) : '' );
		if ( ! empty( $replicated ) ) {
			$message .= sprintf( ' Replicated %d wp-config constant(s).', count( $replicated ) );
		}
		return array( 'message' => $message );
	}

	private static function step_finalize( &$job ) {
		$checkpoint = self::checkpoint( $job );
		if ( is_wp_error( $checkpoint ) ) {
			return $checkpoint;
		}
		$uploads_committed = self::commit_fast_uploads_cursor( $job );
		if ( is_wp_error( $uploads_committed ) ) {
			return $uploads_committed;
		}
		$session_closed = Client::end_transfer_session();
		if ( is_wp_error( $session_closed ) ) {
			return $session_closed;
		}
		$checkpoint = self::checkpoint( $job );
		if ( is_wp_error( $checkpoint ) ) {
			return $checkpoint;
		}
		if ( ! empty( $job['activation_guard_active'] ) ) {
			$guarded = Safety::begin_activation_guard( $job['id'] );
			if ( is_wp_error( $guarded ) || ! Safety::end_activation_guard( $job['id'] ) ) {
				return is_wp_error( $guarded ) ? $guarded : new \WP_Error( 'activation_guard_release', 'The synchronized state is ready, but the sandbox traffic guard could not be removed.' );
			}
			$job['activation_guard_active'] = false;
			Job::save( $job );
		}
		$db_removed = self::finalize_database_swap( $job );
		if ( is_wp_error( $db_removed ) ) {
			return $db_removed;
		}
		// Backups are gone and shadows were renamed into place, so the only
		// registered working tables left are per-table import checkpoints —
		// drop them here or every successful sync leaks one per chunked table.
		$db_removed += self::drop_working_tables( $job );
		// Before the rollback trees are deleted, which can take long enough for
		// a hosted platform to kill the worker: the sync is final from here on.
		self::commit_synced_records( $job );
		$removed = self::finalize_file_dirs( $job );
		self::write_report( $job );
		return array( 'message' => sprintf( 'Finalized sync and removed %d database plus %d file rollback/staging resource(s).', $db_removed, $removed ) );
	}

	/**
	 * Stores what this sync wrote to plugins/ and themes/. Called only by the
	 * finalize step: a sync that failed or was cancelled leaves the sandbox
	 * as it was, and so must its records.
	 *
	 * @param array $job Finished job.
	 */
	private static function commit_synced_records( $job ) {
		foreach ( self::KEPT_DIRS as $dir => $option ) {
			$key = 'synced_' . $dir . '_next';
			if ( isset( $job[ $key ] ) && is_array( $job[ $key ] ) ) {
				update_option( $option, self::sorted_names( $job[ $key ] ), false );
			}
		}
	}

	private static function write_report( $job, $status = 'done' ) {
		$skipped = 0;
		foreach ( $job['steps'] as $step ) {
			if ( 'table' === $step['type'] && 'skipped' === $step['status'] ) {
				++$skipped;
			}
		}
		$job_id = isset( $job['id'] ) ? (string) $job['id'] : '';
		$report = array(
			'job_id'          => $job_id,
			'status'          => $status,
			'finished_at'     => time(),
			'duration_s'      => time() - (int) $job['created_at'],
			'source'          => isset( $job['manifest']['site']['home'] ) ? $job['manifest']['site']['home'] : '',
			'tables_imported' => count( $job['imported'] ),
			'tables_skipped'  => $skipped,
			'dirs'            => $job['options']['dirs'],
			'kept_plugins'    => self::sorted_names( isset( $job['kept_plugins'] ) ? $job['kept_plugins'] : array() ),
			'kept_themes'     => self::sorted_names( isset( $job['kept_themes'] ) ? $job['kept_themes'] : array() ),
			'error'           => isset( $job['error'] ) ? $job['error'] : '',
			'error_info'      => isset( $job['error_info'] ) && is_array( $job['error_info'] ) ? $job['error_info'] : null,
			'has_log'         => null !== Job::log_path( $job_id ) && is_file( (string) Job::log_path( $job_id ) ),
		);
		if ( 'done' === $status ) {
			update_option( self::OPT_LAST_REPORT, $report, false );
		}
		$history   = get_option( self::OPT_HISTORY );
		$history   = is_array( $history ) ? $history : array();
		$history[] = $report;
		$limit     = max( 1, (int) apply_filters( 'sandbox_sync_history_limit', 20 ) );
		$kept      = array_slice( $history, -$limit );
		update_option( self::OPT_HISTORY, $kept, false );
		// Drop log files that fell out of the retained history window.
		$keep_ids = array();
		foreach ( $kept as $entry ) {
			if ( ! empty( $entry['job_id'] ) ) {
				$keep_ids[] = $entry['job_id'];
			}
		}
		Job::prune_log_files( $keep_ids );
	}

	/**
	 * Replacement pairs, longest key first so full URLs win over bare hosts.
	 */
}
