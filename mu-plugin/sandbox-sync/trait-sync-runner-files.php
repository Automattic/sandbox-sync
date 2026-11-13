<?php
/** File planning, staged code sync, in-place uploads sync, and recovery. */

namespace SandboxSync;

defined( 'ABSPATH' ) || exit;

trait Sync_Runner_Files {

	/** Online bulk plan, or the guarded final delta when $final is true. */
	private static function step_uploads_plan_fast( &$job, $index, $final ) {
		$checkpoint = self::checkpoint( $job );
		if ( is_wp_error( $checkpoint ) ) {
			return $checkpoint;
		}
		if ( empty( $job['working_dirs']['uploads'] ) ) {
			$prepared = self::prepare_uploads_in_place( $job );
			if ( is_wp_error( $prepared ) ) {
				return $prepared;
			}
		}

		$source = Client::get_json( 'uploads_state' );
		$local  = Uploads_State::snapshot();
		if ( is_wp_error( $source ) || is_wp_error( $local ) ) {
			return is_wp_error( $source ) ? $source : $local;
		}
		if ( (int) ( $source['schema'] ?? 0 ) !== Uploads_State::SCHEMA || empty( $source['source_id'] ) || ! isset( $source['scopes'], $source['generations'] ) ) {
			return new \WP_Error( 'uploads_state_response', 'Source returned malformed fast-uploads state.' );
		}

		$all_scopes = array_values(
			array_unique(
				array_merge(
					(array) $source['scopes'],
					(array) $local['scopes'],
					array_keys( (array) $source['generations'] ),
					array_keys( (array) $local['generations'] )
				)
			)
		);
		$all_scopes = array_values(
			array_filter(
				$all_scopes,
				function ( $scope ) use ( $job ) {
					return Uploads_State::valid_scope( $scope ) && self::upload_scope_selected( $scope, $job['options']['uploads_scope'] );
				}
			)
		);
		sort( $all_scopes, SORT_STRING );

		$dirty     = array();
		$committed = array();
		$bulk_step = null;
		$bulk_note = '';
		if ( ! $final ) {
			$cursor      = Uploads_State::cursor();
			$uncommitted = array();
			foreach ( $all_scopes as $scope ) {
				// Mixed-mode planning: each partition is judged on its own
				// committed cursor entry. Committed partitions take the cheap
				// generation check (skip or metadata diff); partitions that
				// never completed a verified sync from this source take the
				// baseline stream (or the adopt diff).
				if ( ! Uploads_State::partition_committed( (string) $source['source_id'], $scope, $cursor ) ) {
					$uncommitted[] = $scope;
					continue;
				}
				$committed[ $scope ] = array(
					'source_generation' => (int) ( $cursor['partitions'][ $scope ]['source_generation'] ?? -1 ),
					'local_generation'  => (int) ( $cursor['partitions'][ $scope ]['local_generation'] ?? -1 ),
				);
				$source_generation   = (int) ( $source['generations'][ $scope ] ?? 0 );
				$local_generation    = (int) ( $local['generations'][ $scope ] ?? 0 );
				// Generations are trusted uniformly: any WordPress-managed write
				// bumps the owning scope, so an unchanged generation skips the
				// scan even for the current month and non-dated content. Files
				// mutated outside WordPress (FTP, WP-CLI, cron scripts) are
				// invisible to every scope alike; --audit-uploads covers those.
				if ( ! empty( $job['options']['uploads_full_audit'] )
					|| $source_generation !== $committed[ $scope ]['source_generation']
					|| $local_generation !== $committed[ $scope ]['local_generation'] ) {
					$dirty[] = $scope;
				}
			}
			$job['uploads_fast'] = array(
				'source_id'            => (string) $source['source_id'],
				'selected_scopes'      => $all_scopes,
				'pre_source'           => (array) $source['generations'],
				'pre_local'            => (array) $local['generations'],
				'final_source'         => (array) $source['generations'],
				'deletion_files'       => array(),
				'planned_scopes'       => array(),
				'batch_seq'            => 0,
				'ready'                => false,
				'committed_partitions' => $committed,
			);
			if ( ! empty( $uncommitted ) && ! empty( $job['options']['uploads_adopt'] ) ) {
				// Adopt mode: partitions without a committed entry, but the
				// operator asserts the sandbox already holds (most of) the
				// files — e.g. a previous baseline copied everything and then
				// the job died before committing. Instead of the destructive
				// clear-and-restream baseline, they join the incremental
				// machinery as dirty: each live manifest is compared against
				// the files on disk (size + mtime; the tar extractor restores
				// source mtimes) and only real differences travel. Partitions
				// that already match (and need no deferred deletions) commit
				// their cursor entry as soon as the comparison finishes;
				// partitions whose transfers complete with no deletions do the
				// same after their last fetch. Deletion-bearing partitions wait
				// for activation so orphans cannot survive a mid-job failure.
				Job::log(
					$job,
					sprintf(
						'Adopting existing sandbox uploads: %d of %d selected partition(s) have no committed sync record and will be compared file-by-file against the live site; only missing or changed files will be copied. Nothing is cleared up front.',
						count( $uncommitted ),
						count( $all_scopes )
					)
				);
				$dirty = array_values( array_unique( array_merge( $dirty, $uncommitted ) ) );
				sort( $dirty, SORT_STRING );
			} elseif ( ! empty( $uncommitted ) ) {
				if ( ! function_exists( 'curl_multi_init' ) ) {
					return new \WP_Error( 'bulk_stream_curl', 'The fast first uploads sync requires the PHP cURL extension.' );
				}
				$groups = Uploads_State::baseline_groups( $uncommitted );
				$job['uploads_fast']['baseline_mode']  = 'bulk_stream';
				$job['uploads_fast']['bulk_completed'] = array();
				$job['uploads_fast']['bulk_progress']  = array();
				// Best-effort stat-only totals from the source, so progress can
				// read "1.2 GB / 20.2 GB". Purely cosmetic: a failed or slow
				// totals request never blocks the sync itself.
				$grand_bytes = 0;
				$grand_files = 0;
				if ( ! empty( $groups ) ) {
					$totals = Client::post_json( 'uploads_totals', array( 'scopes' => $uncommitted ) );
					if ( ! is_wp_error( $totals ) && isset( $totals['totals'] ) && is_array( $totals['totals'] ) ) {
						foreach ( $groups as &$group ) {
							$group_bytes = 0;
							$group_files = 0;
							foreach ( $group['scopes'] as $scope ) {
								$group_bytes += (int) ( $totals['totals'][ $scope ]['bytes'] ?? 0 );
								$group_files += (int) ( $totals['totals'][ $scope ]['files'] ?? 0 );
							}
							$group['total_bytes'] = $group_bytes;
							$group['total_files'] = $group_files;
							$grand_bytes         += $group_bytes;
							$grand_files         += $group_files;
						}
						unset( $group );
					}
				}
				if ( ! empty( $groups ) ) {
					$bulk_step = Job::make_step(
						'uploads_bulk',
						sprintf(
							'Copy %s uploads for the first time (%d group(s), up to %d at once)',
							count( $uncommitted ) === count( $all_scopes ) ? 'all selected' : 'never-synced',
							count( $groups ),
							Client::baseline_parallel_streams()
						),
						array( 'groups' => $groups )
					);
					$bulk_note = sprintf(
						'%d never-synced partition(s)%s will be copied in full as %d resumable group(s), up to %d at once. A final check for late changes runs afterward.',
						count( $uncommitted ),
						$grand_bytes > 0 ? sprintf( ' (%s across %s files)', size_format( $grand_bytes, 1 ), number_format_i18n( $grand_files ) ) : '',
						count( $groups ),
						Client::baseline_parallel_streams()
					);
				}
			}
		} else {
			if ( empty( $job['uploads_fast']['source_id'] ) || ! hash_equals( (string) $job['uploads_fast']['source_id'], (string) $source['source_id'] ) ) {
				return new \WP_Error( 'uploads_source_changed', 'The source identity changed during uploads pre-copy.' );
			}
			$all_scopes = array_values( array_unique( array_merge( $all_scopes, (array) $job['uploads_fast']['selected_scopes'] ) ) );
			foreach ( $all_scopes as $scope ) {
				if ( ! self::upload_scope_selected( $scope, $job['options']['uploads_scope'] ) ) {
					continue;
				}
				if ( (int) ( $source['generations'][ $scope ] ?? 0 ) !== (int) ( $job['uploads_fast']['pre_source'][ $scope ] ?? 0 )
					|| (int) ( $local['generations'][ $scope ] ?? 0 ) !== (int) ( $job['uploads_fast']['pre_local'][ $scope ] ?? 0 )
					|| ! in_array( $scope, (array) $job['uploads_fast']['selected_scopes'], true ) ) {
					$dirty[] = $scope;
				}
			}
			$job['uploads_fast']['selected_scopes'] = $all_scopes;
			$job['uploads_fast']['final_source']    = (array) $source['generations'];
		}

		$total_files = 0;
		$total_bytes = 0;
		$total_delete = 0;
		$steps       = array();
		foreach ( $dirty as $scope_index => $scope ) {
			self::publish_uploads_job_progress(
				$job,
				$index,
				sprintf( 'Scanning uploads partition %d/%d: %s…', $scope_index + 1, count( $dirty ), $scope )
			);
			$checkpoint = self::checkpoint( $job );
			if ( is_wp_error( $checkpoint ) ) {
				return $checkpoint;
			}
			// Committed partitions diff forward from their committed cursor
			// generations. Adopt/uncommitted partitions have no trustworthy
			// history, so they diff from the current generations: no forced
			// paths, a pure metadata comparison of the full partition.
			$source_after = $final ? (int) ( $job['uploads_fast']['pre_source'][ $scope ] ?? 0 ) : ( isset( $committed[ $scope ] ) ? $committed[ $scope ]['source_generation'] : (int) ( $source['generations'][ $scope ] ?? 0 ) );
			$local_after  = $final ? (int) ( $job['uploads_fast']['pre_local'][ $scope ] ?? 0 ) : ( isset( $committed[ $scope ] ) ? $committed[ $scope ]['local_generation'] : (int) ( $local['generations'][ $scope ] ?? 0 ) );
			$force_all    = false;
			$forced       = array();
			self::collect_forced_upload_paths( $source, $scope, $source_after, $forced, $force_all );
			self::collect_forced_upload_paths( $local, $scope, $local_after, $forced, $force_all );
			$result = self::plan_fast_upload_scope( $job, $index, $scope, $scope_index + 1, count( $dirty ), $forced, $force_all );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$total_files  += $result['files'];
			$total_bytes  += $result['bytes'];
			$total_delete += $result['delete'];
			$steps         = array_merge( $steps, $result['steps'] );
			Job::log(
				$job,
				sprintf(
					'Uploads partition %d/%d (%s) complete: %s live files, %s paths compared, %s changed files (%s) queued, %s deletion candidates deferred.',
					$scope_index + 1,
					count( $dirty ),
					$scope,
					number_format_i18n( $result['remote'] ),
					number_format_i18n( $result['compared'] ),
					number_format_i18n( $result['files'] ),
					size_format( $result['bytes'], 1 ),
					number_format_i18n( $result['delete'] )
				)
			);
			$job['uploads_fast']['planned_scopes'][ $scope ] = true;
			$job['uploads_fast']['final_source'][ $scope ] = (int) ( $source['generations'][ $scope ] ?? 0 );
			// Durable early commit for partitions that are already exact: no
			// bytes to fetch and no deletions deferred to activation. A later
			// failure must not force another metadata scan of this year.
			if ( 0 === (int) $result['files'] && 0 === (int) $result['delete'] ) {
				$early = self::maybe_commit_upload_partition( $job, $scope );
				if ( is_wp_error( $early ) ) {
					return $early;
				}
			} elseif ( (int) $result['files'] > 0 ) {
				$job['uploads_fast']['scope_remaining'][ $scope ]    = count( $result['steps'] );
				$job['uploads_fast']['scope_delete_count'][ $scope ] = (int) $result['delete'];
			}
			$checkpoint = self::checkpoint( $job );
			if ( is_wp_error( $checkpoint ) ) {
				return $checkpoint;
			}
		}

		if ( $final ) {
			$job['uploads_fast']['ready'] = true;
		}
		// The baseline stream step (never-synced partitions) runs before the
		// incremental fetches; both sets touch disjoint partitions.
		if ( null !== $bulk_step ) {
			$steps = array_merge( array( $bulk_step ), $steps );
		}
		Job::insert_steps( $job, $index, $steps );
		Job::save( $job );
		if ( $final ) {
			$phase = 'final guarded delta';
		} elseif ( ! empty( $job['options']['uploads_adopt'] ) && empty( $committed ) ) {
			$phase = 'adopt-existing pre-copy';
		} else {
			$phase = 'dirty-partition pre-copy';
		}
		$messages = array();
		if ( '' !== $bulk_note ) {
			$messages[] = $bulk_note;
		}
		if ( ! empty( $dirty ) ) {
			$messages[] = sprintf(
				'Fast uploads %s scanned %d/%d selected partition(s): %d changed files (%.1f MiB) queued; ordinary files hash during transfer; %d deletions deferred until final validation.',
				$phase,
				count( $dirty ),
				count( $all_scopes ),
				$total_files,
				$total_bytes / 1048576,
				$total_delete
			);
		}
		if ( empty( $messages ) ) {
			return array(
				'message' => "Fast uploads {$phase}: no generations changed; no media traversal or hashing needed.",
				'skipped' => true,
			);
		}
		return array( 'message' => implode( "\n", $messages ) );
	}

	private static function step_uploads_finalize( &$job, $index ) {
		if ( empty( $job['uploads_fast'] ) ) {
			return array(
				'message' => 'Fast uploads final delta was not required.',
				'skipped' => true,
			);
		}
		$guarded = Safety::begin_activation_guard( $job['id'] );
		if ( is_wp_error( $guarded ) ) {
			return $guarded;
		}
		$job['activation_guard_active'] = true;
		Job::save( $job );
		return self::step_uploads_plan_fast( $job, $index, true );
	}

	/**
	 * Runs every Knife-like first-baseline stream, up to
	 * Client::baseline_parallel_streams() years concurrently. Each year/non-date
	 * group stays an independent durable boundary: completion is recorded the
	 * moment its stream finishes, and an interrupted group resumes after the
	 * last file it durably installed instead of re-streaming the whole year.
	 */
	private static function step_uploads_bulk_baseline( &$job, $index, $args ) {
		$groups = isset( $args['groups'] ) ? (array) $args['groups'] : array();
		if ( 'bulk_stream' !== (string) ( $job['uploads_fast']['baseline_mode'] ?? '' ) || empty( $groups ) ) {
			return new \WP_Error( 'uploads_bulk_state', 'The first-baseline uploads stream is missing its durable plan.' );
		}
		$plan = array(); // key => [ 'label' =>, 'scopes' => ].
		foreach ( $groups as $group ) {
			$scopes = isset( $group['scopes'] ) ? array_values( array_unique( (array) $group['scopes'] ) ) : array();
			if ( empty( $scopes ) ) {
				return new \WP_Error( 'uploads_bulk_state', 'A first-baseline stream group has no uploads partitions.' );
			}
			foreach ( $scopes as $scope ) {
				if ( ! Uploads_State::valid_scope( $scope ) || ! in_array( $scope, (array) $job['uploads_fast']['selected_scopes'], true ) ) {
					return new \WP_Error( 'uploads_bulk_scope', 'The first-baseline stream escaped its selected uploads partitions.' );
				}
			}
			$key          = hash( 'sha256', wp_json_encode( $scopes ) );
			$plan[ $key ] = array(
				'label'       => isset( $group['label'] ) ? (string) $group['label'] : 'uploads',
				'scopes'      => $scopes,
				'total_bytes' => (int) ( $group['total_bytes'] ?? 0 ),
				'total_files' => (int) ( $group['total_files'] ?? 0 ),
			);
		}
		$pending = array_diff_key( $plan, (array) ( $job['uploads_fast']['bulk_completed'] ?? array() ) );
		if ( empty( $pending ) ) {
			return array(
				'message' => sprintf( 'All %d uploads group(s) were already copied by an earlier attempt; nothing to redo.', count( $plan ) ),
				'skipped' => true,
			);
		}
		if ( empty( $job['working_dirs']['uploads'] ) ) {
			$prepared = self::prepare_uploads_in_place( $job );
			if ( is_wp_error( $prepared ) ) {
				return $prepared;
			}
		}
		$target = $job['working_dirs']['uploads']['target'];

		// Clear each pending group's partitions exactly once, before its first
		// stream ever starts. A group that already holds a resume cursor keeps
		// its installed files: the source skips everything up to the cursor.
		foreach ( $pending as $key => $group ) {
			if ( ! empty( $job['uploads_fast']['bulk_progress'][ $key ]['cleared'] ) ) {
				continue;
			}
			// Uncommit before destroying: once these partitions are cleared,
			// any cursor entry claiming they completed a sync is a lie that a
			// crash here would make permanent (a later job would skip them on
			// a generation match while the files are gone).
			$uncommitted = Uploads_State::remove_partitions( (string) $job['uploads_fast']['source_id'], $group['scopes'] );
			if ( is_wp_error( $uncommitted ) ) {
				return $uncommitted;
			}
			$last_clear = time();
			$cleared    = Files::clear_upload_scopes(
				$target,
				$group['scopes'],
				function ( $deleted ) use ( &$job, $index, $group, &$last_clear ) {
					if ( time() - $last_clear < 5 ) {
						return true;
					}
					$job['steps'][ $index ]['message'] = sprintf(
						"Preparing uploads group %s:\nremoving old sandbox files before the fresh copy (%s removed so far)",
						$group['label'],
						number_format_i18n( $deleted )
					);
					$last_clear = time();
					return self::checkpoint( $job );
				}
			);
			if ( is_wp_error( $cleared ) ) {
				return $cleared;
			}
			$job['uploads_fast']['bulk_progress'][ $key ] = array(
				'cleared'       => true,
				'resume_after'  => '',
				'files'         => 0,
				'payload_bytes' => 0,
			);
			$checkpoint = self::checkpoint( $job );
			if ( is_wp_error( $checkpoint ) ) {
				return $checkpoint;
			}
		}

		Job::log(
			$job,
			sprintf(
				'First uploads copy: starting %d group(s), up to %d at once (%d already finished).',
				count( $pending ),
				Client::baseline_parallel_streams(),
				count( $plan ) - count( $pending )
			)
		);
		Job::save( $job );

		// Transient transport failures (stall aborts, dropped connections,
		// truncated archives) retry inside the step from each group's durable
		// resume cursor, so one flaky stream cannot fail the job and discard
		// hours of completed years. A wave that advanced at least one cursor
		// does not consume an attempt.
		$attempts  = 0;
		$last_log  = time();
		$retryable = array( 'bulk_stream_stalled', 'bulk_stream_transport', 'tar_stream_truncated', 'http_500', 'http_502', 'http_503', 'http_504', 'http_0' );
		while ( true ) {
			$streams = array();
			$base    = array();
			foreach ( array_diff_key( $plan, (array) $job['uploads_fast']['bulk_completed'] ) as $key => $group ) {
				$temp_dir = self::file_work_root( $job ) . '/bulk-stream-' . substr( $key, 0, 16 );
				if ( is_link( $temp_dir ) || ( ! is_dir( $temp_dir ) && ! mkdir( $temp_dir, 0700, true ) ) ) {
					return new \WP_Error( 'bulk_stream_temp', 'Cannot prepare a safe same-filesystem uploads stream directory.' );
				}
				$streams[ $key ] = array(
					'scopes'       => $group['scopes'],
					'temp_dir'     => $temp_dir,
					'resume_after' => (string) ( $job['uploads_fast']['bulk_progress'][ $key ]['resume_after'] ?? '' ),
				);
				// Counters from interrupted attempts, so resumed streams report
				// cumulative totals instead of restarting at zero.
				$base[ $key ] = array(
					'files'         => (int) ( $job['uploads_fast']['bulk_progress'][ $key ]['files'] ?? 0 ),
					'payload_bytes' => (int) ( $job['uploads_fast']['bulk_progress'][ $key ]['payload_bytes'] ?? 0 ),
				);
			}
			if ( empty( $streams ) ) {
				break;
			}

			$results = Client::stream_uploads_baselines(
				$streams,
				$target,
				function ( $snapshot ) use ( &$job, $index, $plan, $base, &$last_log ) {
					$lines   = array();
					$compact = array();
					foreach ( $snapshot as $key => $stats ) {
						$files   = $base[ $key ]['files'] + (int) ( $stats['files'] ?? 0 );
						$payload = $base[ $key ]['payload_bytes'] + (int) ( $stats['payload_bytes'] ?? 0 );
						$total   = (int) $plan[ $key ]['total_bytes'];
						$copied  = $total > 0
							? sprintf( '%s / %s copied', size_format( $payload, 1 ), size_format( $total, 1 ) )
							: sprintf( '%s copied', size_format( $payload, 1 ) );
						$lines[]   = sprintf( '• %s — %s, %s files done', $plan[ $key ]['label'], $copied, number_format_i18n( $files ) );
						$compact[] = sprintf( '%s %s, %s files', $plan[ $key ]['label'], $copied, number_format_i18n( $files ) );
						// Persist the resume cursor at every checkpoint so a later
						// worker restarts each year after its last installed file.
						if ( '' !== (string) ( $stats['last_installed'] ?? '' ) ) {
							$job['uploads_fast']['bulk_progress'][ $key ]['resume_after']  = (string) $stats['last_installed'];
							$job['uploads_fast']['bulk_progress'][ $key ]['files']         = $files;
							$job['uploads_fast']['bulk_progress'][ $key ]['payload_bytes'] = $payload;
						}
					}
					$done_count = count( (array) $job['uploads_fast']['bulk_completed'] );
					// One line per active stream; the UI renders the newlines. A
					// group usually maps to one year of media (or non-dated files).
					$job['steps'][ $index ]['message'] = sprintf(
						"Copying %d group(s) at the same time — %d of %d finished:\n%s",
						count( $snapshot ),
						$done_count,
						count( $plan ),
						$lines ? implode( "\n", $lines ) : 'finishing the last streams…'
					);
					if ( time() - $last_log >= 60 ) {
						Job::log( $job, sprintf( 'First uploads copy progress (%d/%d groups finished): %s.', $done_count, count( $plan ), implode( '; ', $compact ) ) );
						$last_log = time();
					}
					// Do not issue a nested source-session HTTP request from the
					// transfer loop; the active streams are already authenticated.
					// Renew only the local fenced lease, heartbeat, guard and
					// cancellation state (checkpoint saves the job, making the
					// resume cursors above durable).
					return self::checkpoint( $job, false );
				},
				function ( $key, $stats ) use ( &$job, $plan, $base ) {
					$job['uploads_fast']['bulk_completed'][ $key ] = array(
						'label'         => $plan[ $key ]['label'],
						'scopes'        => $plan[ $key ]['scopes'],
						'files'         => $base[ $key ]['files'] + (int) $stats['files'],
						'payload_bytes' => $base[ $key ]['payload_bytes'] + (int) $stats['payload_bytes'],
						'finished_at'   => time(),
					);
					unset( $job['uploads_fast']['bulk_progress'][ $key ] );
					Job::log(
						$job,
						sprintf(
							'Finished uploads group %s: %s across %s files.',
							$plan[ $key ]['label'],
							size_format( $job['uploads_fast']['bulk_completed'][ $key ]['payload_bytes'], 1 ),
							number_format_i18n( $job['uploads_fast']['bulk_completed'][ $key ]['files'] )
						)
					);
					Job::save( $job );
					// Commit this group's partitions to the durable cursor the
					// moment its stream finished, under a just-verified lease:
					// a later failure anywhere (another group, the database
					// phase, a full disk) costs a generation check on the next
					// run instead of a re-copy of this year. Entries record the
					// PLAN-TIME generations, so anything the live site changed
					// after planning — including mid-stream, behind the
					// traversal — shows up as dirty on the next comparison.
					// The stream itself never bumps local generations (files
					// install through direct renames, not WordPress hooks).
					$fenced = self::checkpoint( $job, false );
					if ( is_wp_error( $fenced ) ) {
						return; // Fenced out or cancelled: leave the group uncommitted; re-verification is cheap.
					}
					$entries = array();
					foreach ( $plan[ $key ]['scopes'] as $scope ) {
						$entries[ $scope ] = array(
							'source_generation' => (int) ( $job['uploads_fast']['pre_source'][ $scope ] ?? 0 ),
							'local_generation'  => (int) ( $job['uploads_fast']['pre_local'][ $scope ] ?? 0 ),
						);
					}
					$committed = Uploads_State::commit_partitions( (string) $job['uploads_fast']['source_id'], $entries );
					if ( is_wp_error( $committed ) ) {
						Job::log( $job, sprintf( 'Could not commit the uploads cursor for group %s (will retry at activation): %s', $plan[ $key ]['label'], $committed->get_error_message() ) );
					}
				}
			);
			foreach ( $streams as $stream ) {
				if ( is_dir( $stream['temp_dir'] ) ) {
					self::delete_recursive( $stream['temp_dir'] );
				}
			}
			if ( is_wp_error( $results ) ) {
				return $results;
			}

			$failure  = null;
			$progress = false;
			foreach ( $results as $key => $result ) {
				if ( ! is_wp_error( $result ) ) {
					$progress = true;
					continue;
				}
				if ( 'job_cancelled' === $result->get_error_code() || 'lease_lost' === $result->get_error_code() ) {
					return $result;
				}
				if ( ! in_array( $result->get_error_code(), $retryable, true ) ) {
					return new \WP_Error(
						$result->get_error_code(),
						sprintf( 'Copying uploads group %s failed: %s', $plan[ $key ]['label'], $result->get_error_message() )
					);
				}
				$cursor_before = (string) ( $streams[ $key ]['resume_after'] ?? '' );
				$cursor_after  = (string) ( $job['uploads_fast']['bulk_progress'][ $key ]['resume_after'] ?? '' );
				if ( $cursor_after !== $cursor_before ) {
					$progress = true;
				}
				if ( null === $failure ) {
					$failure = new \WP_Error(
						$result->get_error_code(),
						sprintf( 'Copying uploads group %s was interrupted: %s', $plan[ $key ]['label'], $result->get_error_message() )
					);
				}
			}
			if ( null === $failure ) {
				break;
			}
			$attempts = $progress ? 0 : $attempts + 1;
			if ( $attempts >= 3 ) {
				return $failure;
			}
			Job::log( $job, 'Retrying interrupted group(s) from where they left off: ' . $failure->get_error_message() );
			// Renew/reopen the scoped source session before the next wave.
			$checkpoint = self::checkpoint( $job );
			if ( is_wp_error( $checkpoint ) ) {
				return $checkpoint;
			}
			sleep( min( 30, 2 ** $attempts * 5 ) );
		}

		// Renew/reopen the scoped source session after the long streams.
		$checkpoint = self::checkpoint( $job );
		if ( is_wp_error( $checkpoint ) ) {
			return $checkpoint;
		}
		$total_files = 0;
		$total_bytes = 0;
		foreach ( (array) $job['uploads_fast']['bulk_completed'] as $done ) {
			$total_files += (int) $done['files'];
			$total_bytes += (int) $done['payload_bytes'];
		}
		return array(
			'message' => sprintf(
				'Copied all %d uploads group(s): %s across %s files streamed directly into uploads/.',
				count( $plan ),
				size_format( $total_bytes, 1 ),
				number_format_i18n( $total_files )
			),
		);
	}

	/** Plans one metadata-only scope; ordinary payload hashes arrive with the tar. */
	private static function plan_fast_upload_scope( &$job, $index, $scope, $scope_number, $scope_total, $forced_paths, $force_all ) {
		$root = self::file_work_root( $job );
		$manifests = $root . '/fast-manifests';
		if ( ! is_dir( $manifests ) && ! mkdir( $manifests, 0700, true ) ) {
			return new \WP_Error( 'uploads_manifest_dir', 'Cannot create fast uploads manifest directory.' );
		}
		$key = hash( 'sha256', $scope );
		$remote_file = $manifests . '/source-' . $key . '.ndjson';
		$changed_file = $manifests . '/changed-' . $key . '.ndjson';
		$delete_file = $manifests . '/delete-' . $key . '.ndjson';
		$count = Client::download_file_manifest( 'uploads', $remote_file, false, $scope );
		if ( is_wp_error( $count ) ) {
			return $count;
		}
		self::publish_uploads_job_progress(
			$job,
			$index,
			sprintf( 'Uploads partition %d/%d (%s): live manifest has %s files; comparing sandbox metadata…', $scope_number, $scope_total, $scope, number_format_i18n( $count ) )
		);
		$changed = @fopen( $changed_file, 'wb' );
		$deletes = @fopen( $delete_file, 'wb' );
		if ( ! $changed || ! $deletes ) {
			if ( $changed ) {
				fclose( $changed ); }
			if ( $deletes ) {
				fclose( $deletes ); }
			return new \WP_Error( 'uploads_plan_store', 'Cannot persist a scoped uploads delta.' );
		}
		$stats = (object) array(
			'files' => 0,
			'bytes' => 0,
			'delete' => 0,
			'compared' => 0,
			'updated_at' => time(),
			'error' => null,
		);
		$completed = Files::diff_iterators(
			Files::read_ndjson_manifest( $remote_file ),
			Files::walk_upload_scope( WP_CONTENT_DIR . '/uploads', $scope, false ),
			function ( $entry ) use ( $changed, $stats ) {
				$line = wp_json_encode( $entry );
				if ( false === $line || fwrite( $changed, $line . "\n" ) !== strlen( $line ) + 1 ) {
					$stats->error = new \WP_Error( 'uploads_plan_store', 'Cannot persist all changed upload paths.' );
					return false;
				}
				++$stats->files;
				$stats->bytes += (int) $entry['s'];
				return true;
			},
			function ( $path ) use ( $deletes, $stats ) {
				$line = wp_json_encode( $path );
				if ( false === $line || fwrite( $deletes, $line . "\n" ) !== strlen( $line ) + 1 ) {
					$stats->error = new \WP_Error( 'uploads_plan_store', 'Cannot persist all upload deletion candidates.' );
					return false;
				}
				++$stats->delete;
				return true;
			},
			$force_all ? true : function ( $entry ) use ( $forced_paths ) {
				return isset( $forced_paths[ $entry['p'] ] );
			},
			function () use ( &$job, $index, $scope, $scope_number, $scope_total, $count, $stats ) {
				++$stats->compared;
				if ( 0 !== $stats->compared % 5000 && time() - $stats->updated_at < 5 ) {
					return true;
				}
				self::publish_uploads_job_progress(
					$job,
					$index,
					sprintf(
						'Uploads partition %d/%d (%s): compared %s paths against %s live files; %s changed files (%s) found.',
						$scope_number,
						$scope_total,
						$scope,
						number_format_i18n( $stats->compared ),
						number_format_i18n( $count ),
						number_format_i18n( $stats->files ),
						size_format( $stats->bytes, 1 )
					)
				);
				$checkpoint = self::checkpoint( $job );
				if ( is_wp_error( $checkpoint ) ) {
					$stats->error = $checkpoint;
					return false;
				}
				$stats->updated_at = time();
				return true;
			}
		);
		fclose( $changed );
		fclose( $deletes );
		@unlink( $remote_file );
		if ( is_wp_error( $stats->error ) ) {
			return $stats->error;
		}
		if ( ! $completed ) {
			return new \WP_Error( 'uploads_plan_stopped', 'Scoped uploads comparison stopped before completion.' );
		}

		$steps = self::batch_fast_uploads( $job, $changed_file, $index, $scope, $scope_number, $scope_total, $stats->files, $stats->bytes );
		@unlink( $changed_file );
		if ( is_wp_error( $steps ) ) {
			return $steps;
		}
		$job['uploads_fast']['deletion_files'][ $scope ] = $delete_file;
		return array(
			'remote' => $count,
			'compared' => $stats->compared,
			'files' => $stats->files,
			'bytes' => $stats->bytes,
			'delete' => $stats->delete,
			'steps' => $steps,
		);
	}

	private static function publish_uploads_job_progress( &$job, $index, $message ) {
		if ( isset( $job['steps'][ $index ] ) ) {
			$job['steps'][ $index ]['message'] = $message;
			$job['heartbeat'] = time();
			Job::save( $job );
		}
	}

	private static function collect_forced_upload_paths( $snapshot, $scope, $after_generation, &$paths, &$force_all ) {
		if ( (int) ( $snapshot['history_floor'][ $scope ] ?? 0 ) > (int) $after_generation ) {
			$force_all = true;
			return;
		}
		foreach ( (array) ( $snapshot['changes'] ?? array() ) as $change ) {
			if ( is_array( $change ) && isset( $change['g'], $change['s'], $change['p'] ) && $scope === $change['s'] && (int) $change['g'] > (int) $after_generation && Tar_Exporter::is_safe_relative_path( $change['p'] ) ) {
				$paths[ $change['p'] ] = true;
			}
		}
	}

	private static function batch_fast_uploads( &$job, $changed_file, $index, $scope, $scope_number, $scope_total, $total_files, $total_bytes ) {
		$handle = @fopen( $changed_file, 'rb' );
		if ( ! $handle ) {
			return new \WP_Error( 'uploads_changed_open', 'Cannot read changed uploads plan.' );
		}
		$steps       = array();
		$batch       = array();
		$batch_bytes = 0;
		$flush = function ( &$entries, &$bytes ) use ( &$job, &$steps, $scope ) {
			if ( empty( $entries ) ) {
				return true;
			}
			$seq = (int) $job['uploads_fast']['batch_seq']++;
			$file = self::store_file_batch( $job, 'uploads', $seq, $entries );
			if ( is_wp_error( $file ) ) {
				return $file;
			}
			$steps[] = Job::make_step(
				'dir_fetch',
				sprintf( 'Fetch changed uploads batch (%d files)', count( $entries ) ),
				array(
					'dir' => 'uploads',
					'batch_file' => $file,
					'file_count' => count( $entries ),
					'scope' => $scope,
				)
			);
			$entries = array();
			$bytes   = 0;
			return true;
		};
		$queued_files  = 0;
		$queued_bytes  = 0;
		$last_progress = time();
		while ( false !== ( $line = fgets( $handle ) ) ) {
			$entry = json_decode( trim( $line ), true );
			if ( ! is_array( $entry ) || ! isset( $entry['p'], $entry['s'], $entry['m'] ) ) {
				fclose( $handle );
				return new \WP_Error( 'uploads_changed_data', 'Changed uploads plan is malformed.' );
			}
			if ( (int) $entry['s'] > Files::BATCH_MAX_BYTES ) {
				$result = $flush( $batch, $batch_bytes );
				if ( is_wp_error( $result ) ) {
					fclose( $handle );
					return $result; }
				// Oversized objects retain range resumability. They are the only upload
				// path that still needs a content address before downloading.
				$hashed = Client::post_json( 'file_hashes', array( 'files' => array( $entry ) ), array( 'dir' => 'uploads' ) );
				$verified = is_array( $hashed ) && isset( $hashed['files'][0] ) ? $hashed['files'][0] : null;
				if ( is_wp_error( $hashed ) || ! is_array( $verified ) || empty( $verified['h'] )
					|| (string) $verified['p'] !== (string) $entry['p'] || (int) $verified['s'] !== (int) $entry['s'] || (int) $verified['m'] !== (int) $entry['m'] ) {
					fclose( $handle );
					return is_wp_error( $hashed ) ? $hashed : new \WP_Error( 'uploads_hash_changed', 'A large upload changed before its resumable transfer was addressed.' );
				}
				$steps[] = Job::make_step(
					'dir_file',
					sprintf( 'Resume changed upload: %s (%.1f MiB)', $verified['p'], $verified['s'] / 1048576 ),
					array(
						'dir' => 'uploads',
						'entry' => $verified,
						'scope' => $scope,
					)
				);
			} else {
				if ( ! empty( $batch ) && ( count( $batch ) >= Files::BATCH_MAX_FILES || $batch_bytes + (int) $entry['s'] > Files::BATCH_MAX_BYTES ) ) {
					$result = $flush( $batch, $batch_bytes );
					if ( is_wp_error( $result ) ) {
						fclose( $handle );
						return $result; }
				}
				$batch[] = $entry;
				$batch_bytes += (int) $entry['s'];
			}
			++$queued_files;
			$queued_bytes += (int) $entry['s'];
			if ( 0 === $queued_files % Files::BATCH_MAX_FILES || time() - $last_progress >= 5 ) {
				self::publish_uploads_job_progress(
					$job,
					$index,
					sprintf(
						'Uploads partition %d/%d (%s): prepared %s of %s transfer candidates (%s of %s); hashes will be computed during streaming.',
						$scope_number,
						$scope_total,
						$scope,
						number_format_i18n( $queued_files ),
						number_format_i18n( $total_files ),
						size_format( min( $total_bytes, $queued_bytes ), 1 ),
						size_format( $total_bytes, 1 )
					)
				);
				$checkpoint = self::checkpoint( $job );
				if ( is_wp_error( $checkpoint ) ) {
					fclose( $handle );
					return $checkpoint; }
				$last_progress = time();
			}
		}
		fclose( $handle );
		$result = $flush( $batch, $batch_bytes );
		return is_wp_error( $result ) ? $result : $steps;
	}

	private static function upload_scope_selected( $scope, $selection ) {
		if ( null === $selection ) {
			return true; }
		if ( 'other' === $scope ) {
			return in_array( 'other', (array) $selection, true ); }
		list( $year ) = explode( '/', $scope, 2 );
		return in_array( $year, (array) $selection, true ) || in_array( $scope, (array) $selection, true );
	}

	private static function activate_fast_uploads( &$job, $target, $quarantine ) {
		$source = Client::get_json( 'uploads_state' );
		if ( is_wp_error( $source ) ) {
			return $source; }
		foreach ( (array) $job['uploads_fast']['selected_scopes'] as $scope ) {
			if ( (int) ( $source['generations'][ $scope ] ?? 0 ) !== (int) ( $job['uploads_fast']['final_source'][ $scope ] ?? 0 ) ) {
				return new \WP_Error( 'uploads_generation_changed', "Live uploads partition {$scope} changed after the final delta; no deletion was applied." );
			}
		}
		$moved = 0;
		foreach ( (array) $job['uploads_fast']['deletion_files'] as $file ) {
			$handle = @fopen( $file, 'rb' );
			if ( ! $handle ) {
				return new \WP_Error( 'uploads_delete_plan', 'Cannot read a final scoped uploads deletion plan.' ); }
			while ( false !== ( $line = fgets( $handle ) ) ) {
				$path = json_decode( trim( $line ), true );
				if ( ! is_string( $path ) ) {
					fclose( $handle );
					return new \WP_Error( 'uploads_delete_plan', 'A final scoped deletion path is malformed.' ); }
				$result = self::move_tree_file( $target, $quarantine, $path, false );
				if ( is_wp_error( $result ) ) {
					fclose( $handle );
					return $result; }
				if ( $result ) {
					++$moved; }
			}
			fclose( $handle );
		}
		// The live source is never paused, so close the narrow generation race
		// after quarantine: if WordPress changed a selected live partition
		// during the moves, restore everything and retry later.
		$after = Client::get_json( 'uploads_state' );
		if ( is_wp_error( $after ) ) {
			self::restore_uploads_quarantine( $quarantine, $target );
			return $after;
		}
		foreach ( (array) $job['uploads_fast']['selected_scopes'] as $scope ) {
			if ( (int) ( $after['generations'][ $scope ] ?? 0 ) !== (int) ( $job['uploads_fast']['final_source'][ $scope ] ?? 0 ) ) {
				self::restore_uploads_quarantine( $quarantine, $target );
				return new \WP_Error( 'uploads_generation_race', "Live uploads partition {$scope} changed during activation; quarantined deletions were restored." );
			}
		}
		$job['uploads_fast']['activated'] = true;
		return $moved;
	}

	/**
	 * Commits one uploads partition with plan-time generations under the
	 * fenced lease. Safe to call mid-job for baseline streams and for
	 * adopt/incremental partitions whose transfers are done and that have no
	 * deferred deletions — anything the live site changed after planning
	 * (including mid-stream) still shows up as dirty via a generation bump.
	 *
	 * @return true|\WP_Error
	 */
	private static function maybe_commit_upload_partition( &$job, $scope ) {
		if ( empty( $job['uploads_fast']['source_id'] ) || ! Uploads_State::valid_scope( $scope ) ) {
			return true;
		}
		$fenced = self::checkpoint( $job, false );
		if ( is_wp_error( $fenced ) ) {
			return $fenced;
		}
		$entries = array(
			$scope => array(
				'source_generation' => (int) ( $job['uploads_fast']['pre_source'][ $scope ] ?? 0 ),
				'local_generation'  => (int) ( $job['uploads_fast']['pre_local'][ $scope ] ?? 0 ),
			),
		);
		$committed = Uploads_State::commit_partitions( (string) $job['uploads_fast']['source_id'], $entries );
		if ( is_wp_error( $committed ) ) {
			Job::log( $job, sprintf( 'Could not early-commit uploads partition %s (will retry at activation): %s', $scope, $committed->get_error_message() ) );
			return true;
		}
		Job::log( $job, sprintf( 'Committed uploads partition %s (plan-time generations); a later failure will not re-copy it.', $scope ) );
		return true;
	}

	/**
	 * Decrements the outstanding transfer counter for an uploads partition and
	 * early-commits it when the last batch finishes with no deferred deletions.
	 *
	 * @return true|\WP_Error
	 */
	private static function note_upload_scope_transfer_done( &$job, $scope ) {
		if ( '' === (string) $scope || empty( $job['uploads_fast']['scope_remaining'][ $scope ] ) ) {
			return true;
		}
		$job['uploads_fast']['scope_remaining'][ $scope ] = max( 0, (int) $job['uploads_fast']['scope_remaining'][ $scope ] - 1 );
		if ( (int) $job['uploads_fast']['scope_remaining'][ $scope ] > 0 ) {
			return true;
		}
		unset( $job['uploads_fast']['scope_remaining'][ $scope ] );
		// Deletion-bearing partitions wait for activate_fast_uploads(): committing
		// before the quarantine pass would let a mid-job failure skip the
		// partition forever while leaving orphan sandbox files behind.
		if ( ! empty( $job['uploads_fast']['scope_delete_count'][ $scope ] ) ) {
			return true;
		}
		return self::maybe_commit_upload_partition( $job, $scope );
	}

	/**
	 * Commits every selected partition to the cursor after full activation.
	 * The "activated" flag is set exclusively by activate_fast_uploads() after
	 * every baseline group streamed, the final delta transferred and the
	 * guarded deletion pass survived its generation race check, so these
	 * entries can never claim files that did not actually travel.
	 *
	 * Baseline groups and deletion-free adopt/incremental partitions already
	 * committed their plan-time generations earlier; this final pass upgrades
	 * every entry to the delta-validated final source generations and the fresh
	 * local generations, which is what makes clean partitions skippable on the
	 * next run.
	 */
	private static function commit_fast_uploads_cursor( &$job ) {
		if ( empty( $job['uploads_fast']['activated'] ) ) {
			return true; }
		$local = Uploads_State::snapshot();
		if ( is_wp_error( $local ) ) {
			return $local; }
		$entries = array();
		foreach ( (array) $job['uploads_fast']['selected_scopes'] as $scope ) {
			$entries[ $scope ] = array(
				'source_generation' => (int) ( $job['uploads_fast']['final_source'][ $scope ] ?? 0 ),
				'local_generation'  => (int) ( $local['generations'][ $scope ] ?? 0 ),
			);
		}
		return Uploads_State::commit_partitions( (string) $job['uploads_fast']['source_id'], $entries );
	}

	private static function step_dir_plan( &$job, $index, $dir ) {
		if ( 'uploads' === $dir && ! empty( $job['options']['incremental'] ) && ! empty( $job['options']['uploads_fast'] ) ) {
			return self::step_uploads_plan_fast( $job, $index, false );
		}
		$checkpoint = self::checkpoint( $job );
		if ( is_wp_error( $checkpoint ) ) {
			return $checkpoint;
		}
		$target = WP_CONTENT_DIR . '/' . $dir;
		if ( ! is_dir( $target ) && ! mkdir( $target, 0755, true ) ) {
			return new \WP_Error( 'mkdir', "Cannot create {$target}." );
		}

		$protected_symlinks = Files::symlink_paths( $target );
		$in_place_uploads   = 'uploads' === $dir;

		// plugins/ and themes/: entries this sync leaves alone, or may take
		// whole from live, are set aside before anything is staged. They are
		// never copied, hashed or moved, so work done in them while a long sync
		// runs is not swapped out for a copy taken now. Telling them apart needs
		// the names live holds as symlinks, hence the request up front, while
		// there is still nothing to undo if it fails.
		$kept_plan = null;
		if ( isset( self::KEPT_DIRS[ $dir ] ) ) {
			$live_tops = self::live_tops( $job );
			if ( is_wp_error( $live_tops ) ) {
				return $live_tops;
			}
			$checkpoint = self::checkpoint( $job );
			if ( is_wp_error( $checkpoint ) ) {
				return $checkpoint;
			}
			$kept_plan = self::plan_set_aside( $job, $dir, $target, $live_tops[ $dir ]['links'] );
		}
		$staged_tops = null === $kept_plan ? null : $kept_plan['staged_tops'];

		// Stream the source manifest to a job-owned NDJSON file and merge it with
		// the local iterator. Neither complete tree nor any path batch is stored
		// in the WordPress job option.
		$clone_active = ! $in_place_uploads && ( ! empty( $job['options']['incremental'] ) || 'plugins' === $dir || ! empty( $protected_symlinks ) );
		$clone_bytes  = 0;
		if ( $clone_active ) {
			$scanned_at = time();
			$scanned    = 0;
			foreach ( Files::walk( $target, false, '', $staged_tops ) as $file ) {
				$clone_bytes += (int) $file['s'];
				++$scanned;
				if ( 0 === $scanned % 250 || time() - $scanned_at >= 30 ) {
					$checkpoint = self::checkpoint( $job );
					if ( is_wp_error( $checkpoint ) ) {
						return $checkpoint;
					}
					$scanned_at = time();
				}
			}
			$free_before = disk_free_space( WP_CONTENT_DIR );
			if ( false === $free_before && ! apply_filters( 'sandbox_sync_allow_unknown_file_capacity', false, $clone_bytes, $dir ) ) {
				return new \WP_Error( 'file_capacity_unknown', "Cannot verify free space before staging {$dir}/. Configure sandbox_sync_allow_unknown_file_capacity only when platform capacity is independently enforced." );
			}
			if ( false !== $free_before && $free_before < $clone_bytes + ( 64 * 1024 * 1024 ) ) {
				return new \WP_Error( 'disk_space', sprintf( '%s/ needs approximately %.1f MB to clone the active tree before transfer; only %.1f MB is free.', $dir, ( $clone_bytes + ( 64 * 1024 * 1024 ) ) / 1048576, $free_before / 1048576 ) );
			}
		}
		$staged = $in_place_uploads
			? self::prepare_uploads_in_place( $job )
			: self::prepare_file_stage( $job, $dir, $clone_active, $clone_bytes, $staged_tops );
		if ( is_wp_error( $staged ) ) {
			return $staged;
		}
		$checkpoint = self::checkpoint( $job );
		if ( is_wp_error( $checkpoint ) ) {
			return $checkpoint;
		}

		$manifest_file = self::file_work_root( $job ) . '/manifest-' . preg_replace( '/[^a-z0-9_-]/i', '_', $dir ) . '.ndjson';
		// Content hashes are mandatory for planning. Size+mtime alone can miss an
		// in-place edit whose metadata was deliberately preserved; the second
		// hashed manifest in validate_staged_dir also detects source changes that
		// occur after this plan was built.
		if ( isset( $job['steps'][ $index ] ) ) {
			$job['steps'][ $index ]['message'] = "Scanning and hashing the live {$dir}/ tree…";
		}
		$checkpoint = self::checkpoint( $job );
		if ( is_wp_error( $checkpoint ) ) {
			self::discard_file_stage( $job, $dir );
			return $checkpoint;
		}
		$remote_count = Client::download_file_manifest( $dir, $manifest_file, true );
		if ( is_wp_error( $remote_count ) ) {
			self::discard_file_stage( $job, $dir );
			return $remote_count;
		}
		if ( isset( $job['steps'][ $index ] ) ) {
			$job['steps'][ $index ]['message'] = sprintf( 'Live manifest ready: %d files. Comparing with the sandbox…', $remote_count );
		}
		$checkpoint = self::checkpoint( $job );
		if ( is_wp_error( $checkpoint ) ) {
			if ( 'lease_lost' !== $checkpoint->get_error_code() ) {
				self::discard_file_stage( $job, $dir );
			}
			return $checkpoint;
		}
		// Live's manifest settles which set-aside entries are kept. The closure
		// below copies $job, so the kept set has to be in it by now.
		$kept = null === $kept_plan ? array() : self::plan_kept_entries( $job, $dir, $manifest_file, $kept_plan );
		$predicate   = function ( $file ) use ( $dir, $job, $protected_symlinks ) {
			return self::file_in_scope( $file, $dir, $job, $protected_symlinks );
		};
		$remote_iter = Files::filter_iterator( Files::read_ndjson_manifest( $manifest_file ), $predicate );
		// Only staged entries are compared with live: an entry that was set
		// aside has no copy in the stage, so one live delivers is fetched whole.
		$local_iter  = Files::filter_iterator( Files::walk( $target, true, '', $staged_tops ), $predicate );

		$plan         = (object) array(
			'steps'       => array(),
			'batch'       => array(),
			'batch_bytes' => 0,
			'fetch_count' => 0,
			'fetch_bytes' => 0,
			'delete_buf'  => array(),
			'deleted'     => 0,
			'batch_error' => null,
			'scanned'     => 0,
			'checked_at'  => time(),
		);
		$flush_batch  = function () use ( &$job, $dir, $plan ) {
			if ( empty( $plan->batch ) || $plan->batch_error instanceof \WP_Error ) {
				return;
			}
			$file = self::store_file_batch( $job, $dir, count( $plan->steps ), $plan->batch );
			if ( is_wp_error( $file ) ) {
				$plan->batch_error = $file;
				return;
			}
			$plan->steps[]     = Job::make_step(
				'dir_fetch',
				'',
				array(
					'dir'        => $dir,
					'batch_file' => $file,
					'file_count' => count( $plan->batch ),
				)
			);
			$plan->batch       = array();
			$plan->batch_bytes = 0;
		};
		$flush_delete = function () use ( $staged, $plan, $in_place_uploads ) {
			if ( ! empty( $plan->delete_buf ) ) {
				// Uploads are updated in place. Their extraneous files stay active
				// until final validation, when they are moved (not copied) into a
				// job-owned quarantine for safe rollback until finalization.
				$plan->deleted   += $in_place_uploads ? count( $plan->delete_buf ) : Files::delete_paths( $staged, $plan->delete_buf );
				$plan->delete_buf = array();
			}
		};
		$diff_completed = Files::diff_iterators(
			$remote_iter,
			$local_iter,
			function ( $file ) use ( $plan, $flush_batch, $dir ) {
				if ( $plan->batch_error instanceof \WP_Error ) {
					return false;
				}
				if ( (int) $file['s'] > Files::BATCH_MAX_BYTES ) {
					$flush_batch();
					if ( $plan->batch_error instanceof \WP_Error ) {
						return false;
					}
					$entry = array(
						'p' => $file['p'],
						's' => (int) $file['s'],
						'm' => (int) $file['m'],
						'h' => (string) $file['h'],
						'o' => isset( $file['o'] ) ? (int) $file['o'] : 0644,
					);
					$plan->steps[] = Job::make_step(
						'dir_file',
						'',
						array(
							'dir'   => $dir,
							'entry' => $entry,
						)
					);
					$plan->fetch_count++;
					$plan->fetch_bytes += (int) $file['s'];
					return true;
				}
				$plan->batch[]      = array(
					'p' => $file['p'],
					's' => (int) $file['s'],
					'm' => (int) $file['m'],
					'h' => (string) $file['h'],
					'o' => isset( $file['o'] ) ? (int) $file['o'] : 0644,
				);
				$plan->batch_bytes += (int) $file['s'];
				$plan->fetch_count++;
				$plan->fetch_bytes += (int) $file['s'];
				if ( count( $plan->batch ) >= Files::BATCH_MAX_FILES || $plan->batch_bytes >= Files::BATCH_MAX_BYTES ) {
					$flush_batch();
				}
				return ! $plan->batch_error instanceof \WP_Error;
			},
			function ( $path ) use ( $plan, $flush_delete ) {
				$plan->delete_buf[] = $path;
				if ( count( $plan->delete_buf ) >= Files::BATCH_MAX_FILES ) {
					$flush_delete();
				}
			},
			empty( $job['options']['incremental'] ),
			function () use ( &$job, $plan, $index, $remote_count ) {
				++$plan->scanned;
				if ( 0 !== $plan->scanned % 250 && time() - $plan->checked_at < 30 ) {
					return true;
				}
				if ( isset( $job['steps'][ $index ] ) ) {
					$job['steps'][ $index ]['message'] = sprintf(
						'Compared %d paths against %d live files; %d files (%.1f MiB) currently need transfer.',
						$plan->scanned,
						$remote_count,
						$plan->fetch_count,
						$plan->fetch_bytes / 1048576
					);
				}
				$checkpoint = self::checkpoint( $job );
				if ( is_wp_error( $checkpoint ) ) {
					$plan->batch_error = $checkpoint;
					return false;
				}
				$plan->checked_at = time();
				return true;
			}
		);
		if ( ! $plan->batch_error instanceof \WP_Error ) {
			$flush_batch();
			$flush_delete();
		}
		if ( $plan->batch_error instanceof \WP_Error ) {
			if ( 'lease_lost' === $plan->batch_error->get_error_code() ) {
				return $plan->batch_error;
			}
			@unlink( $manifest_file );
			self::discard_file_stage( $job, $dir );
			return $plan->batch_error;
		}
		@unlink( $manifest_file );
		if ( ! $diff_completed ) {
			self::discard_file_stage( $job, $dir );
			return new \WP_Error( 'file_plan_stopped', "Stopped while planning {$dir}/ before the complete tree was compared." );
		}
		$free_bytes = disk_free_space( WP_CONTENT_DIR );
		$required   = $in_place_uploads
			? $plan->fetch_bytes + ( Client::parallel_downloads() * Files::BATCH_MAX_BYTES ) + ( 64 * 1024 * 1024 )
			: ( 2 * $plan->fetch_bytes ) + ( 64 * 1024 * 1024 );
		if ( false === $free_bytes && ! apply_filters( 'sandbox_sync_allow_unknown_file_capacity', false, $required, $dir ) ) {
			self::discard_file_stage( $job, $dir );
			return new \WP_Error( 'file_capacity_unknown', "Cannot verify free space for {$dir}/ archives and extracted data." );
		}
		if ( false !== $free_bytes && $free_bytes < $required ) {
			self::discard_file_stage( $job, $dir );
			return new \WP_Error( 'disk_space', sprintf( '%s/ needs approximately %.1f MB of free transfer capacity; only %.1f MB is available.', $dir, $required / 1048576, $free_bytes / 1048576 ) );
		}
		$transfer_steps = count( $plan->steps );
		$batch_number   = 0;
		foreach ( $plan->steps as $i => $fetch_step ) {
			if ( 'dir_file' === $fetch_step['type'] ) {
				$fetch_step['label'] = sprintf( 'Resume large file: %s/%s (%.1f MB)', $dir, $fetch_step['args']['entry']['p'], $fetch_step['args']['entry']['s'] / 1048576 );
			} else {
				++$batch_number;
				$fetch_step['label'] = sprintf( 'Fetch files: %s/ (archive batch %d, %d files)', $dir, $batch_number, $fetch_step['args']['file_count'] );
			}
			$plan->steps[ $i ]   = $fetch_step;
		}
		Job::insert_steps( $job, $index, $plan->steps );

		$kept_note = empty( $kept ) ? '' : sprintf( ' %d sandbox-only %s kept.', count( $kept ), 'plugins' === $dir ? 'plugin(s)' : 'theme(s)' );
		if ( empty( $plan->steps ) && 0 === $plan->deleted ) {
			self::discard_file_stage( $job, $dir );
			return array(
				'message' => sprintf( '%s/ is already up to date (%d source files streamed).', $dir, $remote_count ) . $kept_note,
				'skipped' => true,
			);
		}
		return array(
			'message' => sprintf(
				$in_place_uploads
					? '%s/: %d files to fetch in %d resumable transfer step(s), %d extraneous queued for validated removal.'
					: '%s/: %d files to fetch in %d resumable transfer step(s), %d extraneous deleted.',
				$dir,
				$plan->fetch_count,
				$transfer_steps,
				$plan->deleted
			) . $kept_note,
		);
	}

	private static function step_dir_fetch( &$job, $dir, $batch_file, $scope = '' ) {
		$entries = self::load_file_batch( $job, $batch_file );
		if ( is_wp_error( $entries ) ) {
			return $entries;
		}
		$stage = self::file_stage_path( $job, $dir );
		if ( ! is_dir( $stage ) ) {
			return new \WP_Error( 'missing_stage', "File staging directory is missing for {$dir}." );
		}
		$in_place = ! empty( $job['working_dirs'][ $dir ]['in_place'] );
		if ( $in_place ) {
			foreach ( $entries as $entry ) {
				if ( ! isset( $entry['m'], $entry['o'] ) ) {
					return new \WP_Error( 'batch_metadata', 'An uploads batch created by an older release lacks verification metadata; restart the job.' );
				}
			}
			// A dead worker may have installed only part of this batch. Keep only
			// entries that still differ so the retry is idempotent and bounded.
			$entries = array_values(
				array_filter(
					$entries,
					function ( $entry ) use ( $stage ) {
						return empty( $entry['h'] ) || ! Files::manifest_entry_matches( $stage, $entry );
					}
				)
			);
			if ( empty( $entries ) ) {
				@unlink( $batch_file );
				if ( 'uploads' === $dir && '' !== $scope ) {
					$noted = self::note_upload_scope_transfer_done( $job, $scope );
					if ( is_wp_error( $noted ) ) {
						return $noted;
					}
				}
				return array(
					'message' => 'Verified already-synced uploads batch after worker recovery.',
					'skipped' => true,
				);
			}
		}
		$paths = array_column( $entries, 'p' );
		// Greedily place largest files into the currently lightest request so
		// parallel tar downloads finish at roughly the same time.
		$groups = array();
		$n      = max( 1, min( Client::parallel_downloads(), (int) ceil( count( $paths ) / 25 ) ) );
		$totals = array_fill( 0, $n, 0 );
		usort(
			$entries,
			function ( $a, $b ) {
				$size = (int) $b['s'] <=> (int) $a['s'];
				return 0 !== $size ? $size : strcmp( $a['p'], $b['p'] );
			}
		);
		foreach ( $entries as $entry ) {
			$index              = array_search( min( $totals ), $totals, true );
			$groups[ $index ][] = $in_place ? $entry : $entry['p'];
			$totals[ $index ]  += (int) $entry['s'];
		}

		$archive_dir = self::file_work_root( $job ) . '/archives';
		if ( ! is_dir( $archive_dir ) && ! mkdir( $archive_dir, 0700, true ) ) {
			return new \WP_Error( 'archive_cache', 'Cannot create the job-owned archive cache.' );
		}
		$tmp_files = array();
		foreach ( $groups as $i => $group ) {
			$tmp_files[ $i ] = $archive_dir . '/' . hash( 'sha256', $dir . "\0" . wp_json_encode( $group ) ) . '.tar';
		}

		$missing_groups = array();
		$missing_files  = array();
		foreach ( $tmp_files as $i => $file ) {
			$cached = is_file( $file ) ? Client::validate_tar_archive( $dir, $file ) : new \WP_Error( 'cache_miss', 'Archive is not cached.' );
			if ( is_wp_error( $cached ) ) {
				@unlink( $file );
				$missing_groups[ $i ] = $groups[ $i ];
				$missing_files[ $i ]  = $file;
			}
		}
		if ( ! empty( $missing_groups ) ) {
			$downloaded = Client::download_tars_parallel(
				$dir,
				$missing_groups,
				$missing_files,
				function () use ( &$job ) {
					return self::checkpoint( $job );
				}
			);
			if ( is_wp_error( $downloaded ) ) {
				return $downloaded;
			}
		}
		$checkpoint = self::checkpoint( $job );
		if ( is_wp_error( $checkpoint ) ) {
			return $checkpoint;
		}

		$total_size = 0;
		if ( ! $in_place ) {
			// The stage contains independent copies of unchanged files. Remove every
			// changed destination before extraction so types are replaced cleanly.
			Files::delete_paths( $stage, $paths );
		}
		$entry_map = array();
		foreach ( $entries as $entry ) {
			$entry_map[ $entry['p'] ] = $entry;
		}
		foreach ( $tmp_files as $i => $tmp ) {
			$checkpoint = self::checkpoint( $job );
			if ( is_wp_error( $checkpoint ) ) {
				return $checkpoint;
			}
			$total_size += (int) filesize( $tmp );
			$extract_to  = $stage;
			if ( $in_place ) {
				$extract_to = self::file_work_root( $job ) . '/batch-stage/' . hash( 'sha256', $dir . "\0" . wp_json_encode( $groups[ $i ] ) );
				if ( file_exists( $extract_to ) && ! self::delete_recursive( $extract_to ) ) {
					return new \WP_Error( 'batch_stage_cleanup', 'Cannot clear an interrupted uploads batch stage.' );
				}
				if ( ! mkdir( $extract_to, 0700, true ) ) {
					return new \WP_Error( 'batch_stage_mkdir', 'Cannot create the bounded uploads batch stage.' );
				}
			}
			if ( $in_place ) {
				$conn = Auth::get_connection();
				if ( ! $conn || empty( $conn['token'] ) ) {
					self::delete_recursive( $extract_to );
					return new \WP_Error( 'not_connected', 'The source connection disappeared before uploads verification.' );
				}
				$extracted = Tar_Extractor::extract_verified(
					$tmp,
					$extract_to,
					$dir,
					Client::upload_archive_nonce( $dir, $groups[ $i ] ),
					$conn['token'],
					$groups[ $i ]
				);
			} else {
				$extracted = Tar_Extractor::extract( $tmp, $extract_to );
			}
			if ( is_wp_error( $extracted ) ) {
				if ( $in_place ) {
					self::delete_recursive( $extract_to );
				}
				return $extracted;
			}
			if ( $in_place ) {
				foreach ( $extracted as $verified_entry ) {
					$entry_map[ $verified_entry['p'] ] = $verified_entry;
				}
				$entries = array_values( $entry_map );
				$persisted = self::replace_file_batch( $job, $batch_file, $entries );
				if ( is_wp_error( $persisted ) ) {
					self::delete_recursive( $extract_to );
					return $persisted;
				}
				foreach ( $groups[ $i ] as $planned_entry ) {
					$path      = $planned_entry['p'];
					$source    = $extract_to . '/' . $path;
					$installed = Files::install_verified_file( $stage, $source, $entry_map[ $path ] );
					if ( is_wp_error( $installed ) ) {
						self::delete_recursive( $extract_to );
						@unlink( $tmp );
						return $installed;
					}
				}
				self::delete_recursive( $extract_to );
				@unlink( $tmp );
			}
		}
		array_map(
			function ( $f ) {
				@unlink( $f );
			},
			$tmp_files
		);
		@unlink( $batch_file );

		if ( 'uploads' === $dir && '' !== $scope ) {
			$noted = self::note_upload_scope_transfer_done( $job, $scope );
			if ( is_wp_error( $noted ) ) {
				return $noted;
			}
		}

		return array(
			'message' => sprintf(
				$in_place
					? 'Transferred and SHA-256 verified %d files to %s/ (%s MB, %d parallel download(s), atomic updates).'
					: 'Fetched %d files into %s/ (%s MB, %d parallel download(s)).',
				count( $paths ),
				$dir,
				round( $total_size / 1048576, 1 ),
				count( $groups )
			),
		);
	}

	private static function step_dir_file( &$job, $dir, $entry, $scope = '' ) {
		$stage = self::file_stage_path( $job, $dir );
		if ( ! is_dir( $stage ) ) {
			return new \WP_Error( 'missing_stage', "File staging directory is missing for {$dir}." );
		}
		if ( Files::manifest_entry_matches( $stage, $entry ) ) {
			if ( 'uploads' === $dir && '' !== $scope ) {
				$noted = self::note_upload_scope_transfer_done( $job, $scope );
				if ( is_wp_error( $noted ) ) {
					return $noted;
				}
			}
			return array(
				'message' => sprintf( 'Verified already-staged large file %s/%s after worker recovery.', $dir, $entry['p'] ),
				'skipped' => true,
			);
		}
		$cache_dir = self::file_work_root( $job ) . '/files';
		if ( ! is_dir( $cache_dir ) && ! mkdir( $cache_dir, 0700, true ) ) {
			return new \WP_Error( 'file_cache', 'Cannot create the job-owned resumable file cache.' );
		}
		$partial = $cache_dir . '/' . hash( 'sha256', $dir . "\0" . $entry['p'] . "\0" . $entry['h'] ) . '.part';
		$result  = Client::download_file_resumable(
			$dir,
			$entry,
			$partial,
			function () use ( &$job ) {
				return self::checkpoint( $job );
			}
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$installed = Files::install_verified_file( $stage, $partial, $entry );
		if ( is_wp_error( $installed ) ) {
			return $installed;
		}
		if ( 'uploads' === $dir && '' !== $scope ) {
			$noted = self::note_upload_scope_transfer_done( $job, $scope );
			if ( is_wp_error( $noted ) ) {
				return $noted;
			}
		}
		return array(
			'message' => sprintf( 'Resumed and SHA-256 verified large file %s/%s (%.1f MB).', $dir, $entry['p'], $entry['s'] / 1048576 ),
		);
	}

	private static function step_dir_activate( &$job, $dir ) {
		if ( empty( $job['working_dirs'][ $dir ] ) ) {
			return array(
				'message' => "Skipped {$dir}/ activation because its source and sandbox trees already matched.",
				'skipped' => true,
			);
		}
		$state  = $job['working_dirs'][ $dir ];
		$target = $state['target'];
		$stage  = $state['stage'];
		$backup = $state['backup'];

		if ( ! is_dir( $stage ) || is_link( $stage ) || is_link( $target ) ) {
			return new \WP_Error( 'unsafe_stage', "Cannot safely activate staged {$dir} files." );
		}
		if ( ! empty( $state['in_place'] ) ) {
			$validated = ! empty( $job['uploads_fast']['ready'] )
				? self::activate_fast_uploads( $job, $target, $backup )
				: self::validate_in_place_uploads( $job, $target, $backup );
			if ( is_wp_error( $validated ) ) {
				return $validated;
			}
			$job['working_dirs'][ $dir ]['activated'] = true;
			Job::save( $job );
			// Commit the uploads cursor the moment every selected partition is
			// fully transferred, validated and activated — not at job finalize —
			// so a later failure (database swap, safety pass) can no longer
			// discard hours of uploads work. commit_fast_uploads_cursor() is a
			// no-op unless activate_fast_uploads() completed the whole pipeline.
			$committed = self::commit_fast_uploads_cursor( $job );
			if ( is_wp_error( $committed ) ) {
				return $committed;
			}
			return array(
				'message' => sprintf( 'Validated uploads/ in place and quarantined %d extraneous file(s) without copying the active tree.', $validated ),
			);
		}
		$validated = self::validate_staged_dir( $job, $dir, $stage, $target );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		if ( file_exists( $backup ) && ! self::delete_recursive( $backup ) ) {
			return new \WP_Error( 'backup_cleanup', "Cannot clear previous job backup {$backup}." );
		}
		// Stored before the swap, so that a rollback steps around the same
		// entries the swap left where they were.
		$left                                = self::entries_left_in_place( $job, $dir, $target, $stage );
		$job['working_dirs'][ $dir ]['left'] = $left['leave'];
		$checkpoint                          = self::checkpoint( $job );
		if ( is_wp_error( $checkpoint ) ) {
			return $checkpoint;
		}

		$had_target = is_dir( $target );
		// WP Cloud (and similar hosts) forbid renaming the well-known
		// plugins/mu-plugins/themes directories themselves (EPERM) even when
		// siblings on the same mount rename fine. Evacuate/promote top-level
		// children instead; protected symlinks stay put.
		$content_dirs = array( 'plugins', 'mu-plugins', 'themes' );
		if ( in_array( $dir, $content_dirs, true ) ) {
			$swapped = self::activate_dir_by_contents( $target, $stage, $backup, $left['leave'] );
			if ( is_wp_error( $swapped ) ) {
				return $swapped;
			}
			if ( ! empty( $left['late'] ) ) {
				Job::log( $job, sprintf( '%s/: %d entr%s that appeared after this sync was planned stayed in place: %s.', $dir, count( $left['late'] ), 1 === count( $left['late'] ) ? 'y' : 'ies', implode( ', ', $left['late'] ) ) );
			}
			$job['working_dirs'][ $dir ]['activated']    = true;
			$job['working_dirs'][ $dir ]['had_target']   = $had_target;
			$job['working_dirs'][ $dir ]['content_swap'] = true;
			Job::save( $job );
			self::invalidate_opcache_tree( $target );
			return array( 'message' => "Activated validated {$dir}/ by swapping directory contents (host forbids renaming the {$dir}/ directory itself); rollback copy retained until the job finalizes." );
		}

		// Two directory renames in the same parent (WP_CONTENT_DIR) — typically
		// milliseconds. Stage/backup are hidden siblings of the target so the
		// rename cannot cross mounts the way a nested .sandbox-sync-work path can.
		if ( $had_target && ! @rename( $target, $backup ) ) {
			$detail = self::rename_failure_detail( $target, $backup );
			return new \WP_Error( 'file_activate', "Cannot move active {$dir} directory into its rollback location.{$detail}" );
		}
		if ( ! @rename( $stage, $target ) ) {
			if ( $had_target ) {
				@rename( $backup, $target );
			}
			$detail = self::rename_failure_detail( $stage, $target );
			return new \WP_Error( 'file_activate', "Cannot activate staged {$dir} directory; the original was restored.{$detail}" );
		}

		$job['working_dirs'][ $dir ]['activated']  = true;
		$job['working_dirs'][ $dir ]['had_target'] = $had_target;
		Job::save( $job );
		if ( in_array( $dir, array( 'plugins', 'mu-plugins', 'themes' ), true ) ) {
			self::invalidate_opcache_tree( $target );
		}

		return array( 'message' => "Activated validated {$dir}/ files; rollback copy retained until the job finalizes." );
	}

	/**
	 * Top-level entries the content swap leaves where they are: those the plan
	 * settled (kept, protected, unselected) and those that appeared since. The
	 * plan never judged a late entry, so it stays unless live delivers one of
	 * that name. A staged symlink is no delivery, only a stand-in for a link
	 * the sandbox had when the stage was cloned.
	 *
	 * @param array  $job    Job.
	 * @param string $dir    Synced directory.
	 * @param string $target Active directory.
	 * @param string $stage  Staged directory.
	 * @return array{leave:string[],late:string[]}
	 */
	private static function entries_left_in_place( $job, $dir, $target, $stage ) {
		$settled = isset( $job[ 'in_place_' . $dir ] ) && is_array( $job[ 'in_place_' . $dir ] ) ? $job[ 'in_place_' . $dir ] : array();
		$late    = array();
		if ( isset( $job[ 'planned_' . $dir ] ) && is_array( $job[ 'planned_' . $dir ] ) ) {
			$now = Files::top_level_listing( $target );
			foreach ( array_merge( $now['dirs'], $now['files'] ) as $name ) {
				if ( in_array( $name, $job[ 'planned_' . $dir ], true ) || in_array( $name, $settled, true ) ) {
					continue;
				}
				$staged = $stage . '/' . $name;
				if ( is_link( $staged ) || ! file_exists( $staged ) ) {
					$late[] = $name;
				}
			}
		}
		return array(
			'leave' => self::sorted_names( array_merge( $settled, $late ) ),
			'late'  => self::sorted_names( $late ),
		);
	}

	/**
	 * Activates a staged code tree without renaming the target directory.
	 * Moves every non-symlink child of $target into $backup, then every child
	 * of $stage into $target. Existing symlinks in $target are left untouched
	 * (and matching stage entries are discarded), and so is every entry named
	 * in $leave: it is never moved, so it cannot be lost to a failed swap.
	 *
	 * @param string   $target Active directory.
	 * @param string   $stage  Staged directory.
	 * @param string   $backup Rollback directory, created here.
	 * @param string[] $leave  Top-level entries of $target to leave where they are.
	 * @return true|\WP_Error
	 */
	private static function activate_dir_by_contents( $target, $stage, $backup, $leave = array() ) {
		if ( ! is_dir( $target ) && ! mkdir( $target, 0755, true ) ) {
			return new \WP_Error( 'file_activate', "Cannot create target directory {$target}." );
		}
		if ( ! mkdir( $backup, 0755, true ) ) {
			return new \WP_Error( 'file_activate', "Cannot create rollback directory {$backup}." );
		}
		$evacuated = self::move_dir_children(
			$target,
			$backup,
			array(
				'skip_symlinks' => true,
				'skip_names'    => $leave,
			)
		);
		if ( is_wp_error( $evacuated ) ) {
			self::move_dir_children( $backup, $target, array( 'preserve_symlinks_in_to' => true ) );
			self::delete_recursive( $backup );
			return $evacuated;
		}
		$promoted = self::move_dir_children(
			$stage,
			$target,
			array(
				'preserve_symlinks_in_to' => true,
				'preserve_names_in_to'    => $leave,
			)
		);
		if ( is_wp_error( $promoted ) ) {
			// Roll forward failed: put the previous children back.
			self::delete_dir_children( $target, true, $leave );
			self::move_dir_children( $backup, $target, array( 'preserve_symlinks_in_to' => true ) );
			self::delete_recursive( $backup );
			return $promoted;
		}
		if ( is_dir( $stage ) ) {
			self::delete_recursive( $stage );
		}
		return true;
	}

	/**
	 * Moves every top-level child from $from into $to.
	 *
	 * @param array $opts {
	 *     @type bool     $skip_symlinks           Leave symlinks in $from alone.
	 *     @type string[] $skip_names              Leave these entries of $from alone.
	 *     @type bool     $preserve_symlinks_in_to Skip names that are already symlinks in $to; remove the $from entry.
	 *     @type string[] $preserve_names_in_to    Same for these names when $to has an entry of that name.
	 * }
	 * @return true|\WP_Error
	 */
	private static function move_dir_children( $from, $to, $opts = array() ) {
		if ( ! is_dir( $from ) ) {
			return true;
		}
		if ( ! is_dir( $to ) && ! mkdir( $to, 0755, true ) ) {
			return new \WP_Error( 'file_activate', "Cannot create directory {$to}." );
		}
		$entries = scandir( $from );
		if ( false === $entries ) {
			return new \WP_Error( 'file_activate', "Cannot scan {$from}." );
		}
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$src = $from . '/' . $entry;
			$dst = $to . '/' . $entry;
			if ( ! empty( $opts['skip_symlinks'] ) && is_link( $src ) ) {
				continue;
			}
			if ( ! empty( $opts['skip_names'] ) && in_array( $entry, (array) $opts['skip_names'], true ) ) {
				continue;
			}
			$preserved = ! empty( $opts['preserve_names_in_to'] )
				&& in_array( $entry, (array) $opts['preserve_names_in_to'], true )
				&& ( file_exists( $dst ) || is_link( $dst ) );
			if ( $preserved || ( ! empty( $opts['preserve_symlinks_in_to'] ) && is_link( $dst ) ) ) {
				// Keep what the target has; drop the staged stand-in.
				if ( is_link( $src ) || is_file( $src ) ) {
					@unlink( $src );
				} elseif ( is_dir( $src ) ) {
					self::delete_recursive( $src );
				}
				continue;
			}
			if ( file_exists( $dst ) || is_link( $dst ) ) {
				return new \WP_Error( 'file_activate', "Cannot move {$entry}: destination already exists in {$to}." );
			}
			if ( ! @rename( $src, $dst ) ) {
				$detail = self::rename_failure_detail( $src, $dst );
				return new \WP_Error( 'file_activate', "Cannot move {$entry} from {$from} to {$to}.{$detail}" );
			}
		}
		return true;
	}

	/**
	 * Deletes every non-symlink top-level child of $dir (the directory itself stays).
	 *
	 * @param string   $dir           Directory to empty.
	 * @param bool     $skip_symlinks Leave symlinks alone.
	 * @param string[] $skip_names    Entries to leave alone: the ones a content swap never moved.
	 * @return true|\WP_Error
	 */
	private static function delete_dir_children( $dir, $skip_symlinks = true, $skip_names = array() ) {
		if ( ! is_dir( $dir ) ) {
			return true;
		}
		$entries = scandir( $dir );
		if ( false === $entries ) {
			return new \WP_Error( 'file_activate', "Cannot scan {$dir}." );
		}
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( $skip_symlinks && is_link( $path ) ) {
				continue;
			}
			if ( in_array( $entry, (array) $skip_names, true ) ) {
				continue;
			}
			if ( ! self::delete_recursive( $path ) ) {
				return new \WP_Error( 'file_activate', "Cannot remove {$path}." );
			}
		}
		return true;
	}

	/**
	 * Placeholder written into a fresh stage so the web-reachable sidecar never
	 * lists or executes while files land. The source manifest never contains it.
	 */
	private static function stage_guard_content() {
		return "<?php http_response_code( 404 ); exit;\n";
	}

	/**
	 * Removes the staging placeholder before validation. A source-delivered
	 * index.php (plugins/ and themes/ normally ship one) overwrote the
	 * placeholder during extraction and is kept; mu-plugins/ rarely has one,
	 * and activating the placeholder there would load a 404 stub as a
	 * must-use plugin on every request.
	 */
	private static function discard_stage_guard( $stage ) {
		$guard = $stage . '/index.php';
		if ( is_link( $guard ) || ! is_file( $guard ) ) {
			return;
		}
		if ( @file_get_contents( $guard ) === self::stage_guard_content() ) {
			@unlink( $guard );
		}
	}

	/** @return true|\WP_Error */
	private static function validate_staged_dir( &$job, $dir, $stage, $active_target ) {
		self::discard_stage_guard( $stage );
		$manifest_file = self::file_work_root( $job ) . '/validation-' . preg_replace( '/[^a-z0-9_-]/i', '_', $dir ) . '.ndjson';
		$remote_count  = Client::download_file_manifest( $dir, $manifest_file, true );
		if ( is_wp_error( $remote_count ) ) {
			@unlink( $manifest_file );
			return $remote_count;
		}
		$checkpoint = self::checkpoint( $job );
		if ( is_wp_error( $checkpoint ) ) {
			@unlink( $manifest_file );
			return $checkpoint;
		}
		$symlinks    = Files::symlink_paths( $active_target );
		$predicate   = function ( $file ) use ( $dir, $job, $symlinks ) {
			return self::file_in_scope( $file, $dir, $job, $symlinks );
		};
		$remote_iter = Files::filter_iterator( Files::read_ndjson_manifest( $manifest_file ), $predicate );
		$stage_iter  = Files::filter_iterator( Files::walk( $stage, true ), $predicate );
		$mismatched  = 0;
		$unexpected  = 0;
		$scan_error = null;
		$scanned    = 0;
		$checked_at = time();
		Files::diff_iterators(
			$remote_iter,
			$stage_iter,
			function () use ( &$mismatched ) {
				$mismatched++; },
			function () use ( &$unexpected ) {
				$unexpected++; },
			false,
			function () use ( &$job, &$scan_error, &$scanned, &$checked_at ) {
				++$scanned;
				if ( 0 !== $scanned % 250 && time() - $checked_at < 30 ) {
					return true;
				}
				$checkpoint = self::checkpoint( $job );
				if ( is_wp_error( $checkpoint ) ) {
					$scan_error = $checkpoint;
					return false;
				}
				$checked_at = time();
				return true;
			}
		);
		@unlink( $manifest_file );
		if ( is_wp_error( $scan_error ) ) {
			return $scan_error;
		}
		if ( $mismatched || $unexpected ) {
			return new \WP_Error(
				'file_validation',
				sprintf( '%s/ changed during transfer or failed SHA-256 validation (%d mismatched/missing, %d unexpected across %d source files). Retry from a stable source.', $dir, $mismatched, $unexpected, $remote_count )
			);
		}
		return true;
	}

	/**
	 * Validates the active uploads tree after all per-file atomic installs. Only
	 * after every source file matches is an extraneous local file renamed into
	 * the same-filesystem quarantine. Renames consume no duplicate file space.
	 *
	 * @return int|\WP_Error Number of quarantined files.
	 */
	private static function validate_in_place_uploads( &$job, $target, $quarantine ) {
		$root          = self::file_work_root( $job );
		$manifest_file = $root . '/validation-uploads.ndjson';
		$deletion_file = $root . '/validation-uploads-delete.ndjson';
		$remote_count  = Client::download_file_manifest( 'uploads', $manifest_file, true );
		if ( is_wp_error( $remote_count ) ) {
			@unlink( $manifest_file );
			return $remote_count;
		}
		$delete_handle = @fopen( $deletion_file, 'wb' );
		if ( false === $delete_handle ) {
			@unlink( $manifest_file );
			return new \WP_Error( 'uploads_delete_plan', 'Cannot persist the validated uploads deletion plan.' );
		}
		$symlinks    = Files::symlink_paths( $target );
		$predicate   = function ( $file ) use ( $job, $symlinks ) {
			return self::file_in_scope( $file, 'uploads', $job, $symlinks );
		};
		$remote_iter = Files::filter_iterator( Files::read_ndjson_manifest( $manifest_file ), $predicate );
		$target_iter = Files::filter_iterator( Files::walk( $target, true ), $predicate );
		$mismatched  = 0;
		$scan_error  = null;
		$scanned     = 0;
		$checked_at  = time();
		$completed   = Files::diff_iterators(
			$remote_iter,
			$target_iter,
			function () use ( &$mismatched ) {
				++$mismatched;
			},
			function ( $path ) use ( &$scan_error, $delete_handle ) {
				$json = wp_json_encode( $path );
				$line = false === $json ? false : $json . "\n";
				if ( false === $line || fwrite( $delete_handle, $line ) !== strlen( $line ) ) {
					$scan_error = new \WP_Error( 'uploads_delete_plan', 'Cannot persist the complete uploads deletion plan.' );
					return false;
				}
				return true;
			},
			false,
			function () use ( &$job, &$scan_error, &$scanned, &$checked_at ) {
				++$scanned;
				if ( 0 !== $scanned % 250 && time() - $checked_at < 30 ) {
					return true;
				}
				$checkpoint = self::checkpoint( $job );
				if ( is_wp_error( $checkpoint ) ) {
					$scan_error = $checkpoint;
					return false;
				}
				$checked_at = time();
				return true;
			}
		);
		fclose( $delete_handle );
		@unlink( $manifest_file );
		if ( is_wp_error( $scan_error ) ) {
			@unlink( $deletion_file );
			return $scan_error;
		}
		if ( ! $completed ) {
			@unlink( $deletion_file );
			return new \WP_Error( 'file_validation_stopped', 'Stopped before uploads validation completed.' );
		}
		if ( $mismatched ) {
			@unlink( $deletion_file );
			return new \WP_Error(
				'file_validation',
				sprintf( 'uploads/ changed during transfer or failed SHA-256 validation (%d mismatched/missing across %d source files). Retry from a stable source.', $mismatched, $remote_count )
			);
		}

		$handle = @fopen( $deletion_file, 'rb' );
		if ( false === $handle ) {
			return new \WP_Error( 'uploads_delete_plan', 'Cannot read the validated uploads deletion plan.' );
		}
		$moved = 0;
		while ( false !== ( $line = fgets( $handle ) ) ) {
			$path = json_decode( trim( $line ), true );
			if ( ! is_string( $path ) ) {
				fclose( $handle );
				return new \WP_Error( 'uploads_delete_plan', 'The uploads deletion plan is malformed.' );
			}
			if ( 0 === $moved % Files::BATCH_MAX_FILES ) {
				$checkpoint = self::checkpoint( $job );
				if ( is_wp_error( $checkpoint ) ) {
					fclose( $handle );
					return $checkpoint;
				}
			}
			$result = self::move_tree_file( $target, $quarantine, $path, false );
			if ( is_wp_error( $result ) ) {
				fclose( $handle );
				return $result;
			}
			if ( $result ) {
				++$moved;
			}
		}
		fclose( $handle );
		@unlink( $deletion_file );
		return $moved;
	}
	private static function file_work_root( $job ) {
		$id = isset( $job['id'] ) ? preg_replace( '/[^a-z0-9]/i', '', $job['id'] ) : 'unknown';
		return WP_CONTENT_DIR . '/.sandbox-sync-work/' . $id;
	}

	/**
	 * Extra context for a failed directory rename (device mismatch, PHP warning).
	 */
	private static function rename_failure_detail( $from, $to ) {
		$parts = array();
		$last  = error_get_last();
		if ( is_array( $last ) && ! empty( $last['message'] ) && false !== stripos( $last['message'], 'rename' ) ) {
			$parts[] = $last['message'];
		}
		$from_stat = @stat( $from );
		$to_parent = @stat( dirname( $to ) );
		if ( is_array( $from_stat ) && is_array( $to_parent ) && isset( $from_stat['dev'], $to_parent['dev'] ) && (int) $from_stat['dev'] !== (int) $to_parent['dev'] ) {
			$parts[] = sprintf( 'source and destination are on different devices (%d vs %d)', (int) $from_stat['dev'], (int) $to_parent['dev'] );
		}
		if ( file_exists( $to ) ) {
			$parts[] = 'destination already exists';
		}
		if ( ! is_writable( dirname( $to ) ) ) {
			$parts[] = 'destination parent is not writable';
		}
		return empty( $parts ) ? '' : ' (' . implode( '; ', $parts ) . ')';
	}

	private static function file_stage_path( $job, $dir ) {
		if ( isset( $job['working_dirs'][ $dir ]['stage'] ) ) {
			return $job['working_dirs'][ $dir ]['stage'];
		}
		return self::file_work_root( $job ) . '/stage/' . $dir;
	}

	/**
	 * What live reports about the top level of its plugins/ and themes/
	 * directories, asked once per job.
	 *
	 * @param array $job Job; caches the answer and logs when live cannot give it in full.
	 * @return array|\WP_Error Per directory: 'real' (names, or null when live cannot say) and 'links'.
	 */
	private static function live_tops( &$job ) {
		if ( isset( $job['live_tops'] ) && is_array( $job['live_tops'] ) ) {
			return $job['live_tops'];
		}
		$response = Client::get_json( 'plugins' );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$tops = array();
		foreach ( array_keys( self::KEPT_DIRS ) as $dir ) {
			$tops[ $dir ] = self::live_top_levels( $response, $dir );
		}
		if ( ! isset( $response['tops'] ) || ! is_array( $response['tops'] ) ) {
			Job::log( $job, 'The live site runs a release that does not report the layout of its plugins/ and themes/ directories. A theme build over a package live runs as a symlink is therefore not recognised, and is only kept once a sync has recorded what it wrote.' );
		}
		$job['live_tops'] = $tops;
		return $tops;
	}

	/**
	 * Splits the top level of plugins/ or themes/ before anything is staged.
	 * Entries the sync leaves alone, or may take whole from live, are set
	 * aside: never copied into the stage, hashed or compared. The rest is
	 * staged as before.
	 *
	 * @param array    $job        Job; remembers which entries existed when the plan was made.
	 * @param string   $dir        'plugins' or 'themes'.
	 * @param string   $target     Active directory on the sandbox.
	 * @param string[] $live_links Names live holds as symlinks.
	 * @return array Plan for plan_kept_entries(); 'staged_tops' lists what to clone and compare.
	 */
	private static function plan_set_aside( &$job, $dir, $target, $live_links ) {
		$listing    = Files::top_level_listing( $target );
		$planned    = self::sorted_names( array_merge( $listing['dirs'], $listing['files'] ) );
		$candidates = self::kept_candidates( $dir, $listing );
		$record     = get_option( self::KEPT_DIRS[ $dir ] );
		$record     = is_array( $record ) ? self::sorted_names( $record ) : null;
		$protected  = 'plugins' === $dir ? self::PROTECTED_PLUGINS : array();
		$selection  = 'plugins' === $dir && isset( $job['options']['plugins'] ) && is_array( $job['options']['plugins'] ) ? $job['options']['plugins'] : null;

		// What live delivers is not known yet. An entry is set aside when it
		// would be kept should live turn out not to deliver it.
		$set_aside = self::kept_top_level_names( $candidates, array(), $record, $live_links, $protected );
		// Settled without live's answer: protected entries and, under a
		// per-plugin selection, everything the selection leaves out.
		$settled = self::sorted_names(
			array_merge(
				array_intersect( $planned, $protected ),
				is_array( $selection ) ? array_diff( $planned, $selection ) : array()
			)
		);

		$job[ 'planned_' . $dir ] = $planned;
		return array(
			'candidates'  => $candidates,
			'record'      => $record,
			'selection'   => $selection,
			'protected'   => $protected,
			'live_links'  => self::sorted_names( $live_links ),
			'local_links' => $listing['links'],
			'set_aside'   => $set_aside,
			'settled'     => $settled,
			'staged_tops' => self::sorted_names( array_diff( array_merge( $planned, $listing['links'] ), $set_aside, $settled ) ),
		);
	}

	/**
	 * Settles, once live's manifest is in, which set-aside entries this sync
	 * keeps and what the record will hold when the sync is final. Runs before
	 * the trees are compared, because the scope predicate reads the kept set
	 * from the job. The caller's next checkpoint persists the job: saving it
	 * here would bypass the lease check that fences a superseded worker.
	 *
	 * @param array  $job           Job; updated and logged to.
	 * @param string $dir           'plugins' or 'themes'.
	 * @param string $manifest_file Live's file manifest for $dir (NDJSON).
	 * @param array  $plan          Result of plan_set_aside().
	 * @return string[] Kept top-level names.
	 */
	private static function plan_kept_entries( &$job, $dir, $manifest_file, $plan ) {
		$remote = Files::top_level_names( Files::read_ndjson_manifest( $manifest_file ) );
		$kept   = self::kept_top_level_names( $plan['candidates'], $remote, $plan['record'], $plan['live_links'], $plan['protected'] );
		$kind   = 'plugins' === $dir ? 'plugin(s)' : 'theme(s)';

		$job[ 'kept_' . $dir ]     = $kept;
		$job[ 'in_place_' . $dir ] = self::sorted_names( array_merge( $kept, $plan['settled'] ) );
		// Without a record, everything already here counts as synced: the
		// assumption every sync made before records existed.
		$job[ 'synced_' . $dir . '_next' ] = self::next_synced_record(
			$remote,
			null !== $plan['record'] ? $plan['record'] : array_diff( $plan['candidates'], $kept ),
			$plan['selection'],
			$plan['local_links']
		);

		if ( null === $plan['record'] ) {
			Job::log( $job, sprintf( '%s/: no record yet of what earlier syncs wrote here, so everything already in it counts as synced from live: what live does not have is removed, as before. Entries added on this sandbox after this sync are kept.', $dir ) );
		}
		if ( ! empty( $kept ) ) {
			Job::log( $job, sprintf( 'Keeping %d sandbox-only %s: %s.', count( $kept ), $kind, implode( ', ', $kept ) ) );
		}
		// Set aside, delivered by live after all, and in scope of this run.
		$replaced = self::sorted_names( array_diff( $plan['set_aside'], $kept, $plan['settled'] ) );
		if ( ! empty( $replaced ) ) {
			Job::log( $job, sprintf( '%s/: live now delivers %s, which this sandbox had on its own. Live\'s copy replaces the sandbox\'s.', $dir, implode( ', ', $replaced ) ) );
		}
		return $kept;
	}

	/**
	 * Decides, for a sync that does not plan plugins/, which plugins keep
	 * their activation across the database swap: the same rule as the plan
	 * step, with live's reported layout standing in for its file manifest.
	 *
	 * @param array $job       Job; updated.
	 * @param array $live_tops Result of live_tops().
	 */
	private static function plan_activation_carry( &$job, $live_tops ) {
		$live   = isset( $live_tops['plugins'] ) && is_array( $live_tops['plugins'] ) ? $live_tops['plugins'] : array();
		$record = get_option( self::OPT_SYNCED_PLUGINS );

		$job['activation_kept'] = self::kept_top_level_names(
			self::kept_candidates( 'plugins', Files::top_level_listing( WP_CONTENT_DIR . '/plugins' ) ),
			isset( $live['real'] ) && is_array( $live['real'] ) ? $live['real'] : array(),
			is_array( $record ) ? $record : null,
			isset( $live['links'] ) ? $live['links'] : array(),
			self::PROTECTED_PLUGINS
		);
	}

	private static function file_in_scope( $file, $dir, $job, $protected_symlinks ) {
		$path = isset( $file['p'] ) ? $file['p'] : '';
		foreach ( $protected_symlinks as $prefix ) {
			if ( $path === $prefix || 0 === strpos( $path, rtrim( $prefix, '/' ) . '/' ) ) {
				return false;
			}
		}
		// Sandbox-only entries this job keeps are invisible to the fetch plan,
		// the deletion pass and stage validation alike, selection or not.
		if ( isset( self::KEPT_DIRS[ $dir ] ) && ! empty( $job[ 'kept_' . $dir ] ) && in_array( strtok( $path, '/' ), (array) $job[ 'kept_' . $dir ], true ) ) {
			return false;
		}
		if ( 'plugins' === $dir ) {
			$top = strtok( $path, '/' );
			if ( in_array( $top, self::PROTECTED_PLUGINS, true ) ) {
				return false;
			}
			if ( is_array( $job['options']['plugins'] ) && ! in_array( $top, $job['options']['plugins'], true ) ) {
				return false;
			}
		}
		if ( 'uploads' === $dir && is_array( $job['options']['uploads_scope'] ) ) {
			$scopes = $job['options']['uploads_scope'];
			if ( preg_match( '#^(\d{4})/(\d{2})/#', $path, $m ) ) {
				return in_array( $m[1], $scopes, true ) || in_array( $m[1] . '/' . $m[2], $scopes, true );
			}
			if ( preg_match( '#^(\d{4})/#', $path, $m ) ) {
				foreach ( $scopes as $scope ) {
					if ( $scope === $m[1] || 0 === strpos( $scope, $m[1] . '/' ) ) {
						return true;
					}
				}
				return false;
			}
			return in_array( 'other', $scopes, true );
		}
		return true;
	}

	/** @return string|\WP_Error */
	private static function store_file_batch( $job, $dir, $index, $entries ) {
		$base = self::file_work_root( $job ) . '/batches';
		if ( ! is_dir( $base ) && ! mkdir( $base, 0700, true ) ) {
			return new \WP_Error( 'batch_store', "Cannot create batch store {$base}." );
		}
		$file = $base . '/' . preg_replace( '/[^a-z0-9_-]/i', '_', $dir ) . '-' . (int) $index . '.json';
		$json = wp_json_encode( array_values( $entries ) );
		if ( false === $json || file_put_contents( $file, $json, LOCK_EX ) !== strlen( $json ) ) {
			@unlink( $file );
			return new \WP_Error( 'batch_store', "Cannot persist file batch {$index} for {$dir}." );
		}
		@chmod( $file, 0600 );
		$journaled = self::append_batch_journal( $base, basename( $file ), $json );
		if ( is_wp_error( $journaled ) ) {
			@unlink( $file );
			return $journaled;
		}
		return $file;
	}

	/** Atomically enriches an existing job-owned batch with streamed hashes. */
	private static function replace_file_batch( $job, $file, $entries ) {
		$base = realpath( self::file_work_root( $job ) . '/batches' );
		$real = is_string( $file ) ? realpath( $file ) : false;
		if ( false === $base || false === $real || 0 !== strpos( $real, rtrim( $base, '/' ) . '/' ) ) {
			return new \WP_Error( 'batch_path', 'Cannot update a file batch outside the job work directory.' );
		}
		$json = wp_json_encode( array_values( $entries ) );
		$tmp  = $real . '.tmp';
		if ( false === $json || file_put_contents( $tmp, $json, LOCK_EX ) !== strlen( $json ) || ! @rename( $tmp, $real ) ) {
			@unlink( $tmp );
			return new \WP_Error( 'batch_store', 'Cannot persist streamed upload verification metadata.' );
		}
		@chmod( $real, 0600 );
		return self::append_batch_journal( $base, basename( $real ), $json );
	}

	/**
	 * Stores a checksummed, append-only recovery copy. One journal avoids
	 * doubling the thousands of filesystem entries created by a large baseline.
	 */
	private static function append_batch_journal( $base, $name, $json ) {
		$record = wp_json_encode(
			array(
				'name'   => (string) $name,
				'json'   => base64_encode( $json ),
				'sha256' => hash( 'sha256', $json ),
			)
		);
		if ( false === $record ) {
			return new \WP_Error( 'batch_journal', 'Cannot encode the file-batch recovery journal.' );
		}
		$line    = $record . "\n";
		$journal = rtrim( $base, '/' ) . '/recovery.ndjson';
		if ( file_put_contents( $journal, $line, FILE_APPEND | LOCK_EX ) !== strlen( $line ) ) {
			return new \WP_Error( 'batch_journal', 'Cannot append the file-batch recovery journal.' );
		}
		@chmod( $journal, 0600 );
		return true;
	}

	/** @return array|\WP_Error */
	private static function load_file_batch( $job, $file ) {
		$base = realpath( self::file_work_root( $job ) . '/batches' );
		$parent = is_string( $file ) ? realpath( dirname( $file ) ) : false;
		$name = is_string( $file ) ? basename( $file ) : '';
		if ( false === $base || false === $parent || ! hash_equals( $base, $parent ) || ! preg_match( '/^[A-Za-z0-9_-]+-[0-9]+\.json$/', $name ) ) {
			return new \WP_Error( 'batch_path', 'File batch path is missing or outside the job work directory.' );
		}
		$real = $base . '/' . $name;
		$json = self::read_batch_with_recovery( $base, $real, $name );
		if ( is_wp_error( $json ) ) {
			return $json;
		}
		$entries = json_decode( $json, true );
		if ( ! is_array( $entries ) || count( $entries ) > Files::BATCH_MAX_FILES ) {
			return new \WP_Error( 'batch_data', 'Stored file batch is malformed or oversized.' );
		}
		$normalized = array();
		foreach ( $entries as $entry ) {
			// Read old in-flight path-only batches during a rolling deployment.
			$entry = is_string( $entry ) ? array(
				'p' => $entry,
				's' => 0,
			) : $entry;
			if ( ! is_array( $entry ) || ! isset( $entry['p'], $entry['s'] ) || ! Tar_Exporter::is_safe_relative_path( $entry['p'] ) || ! is_numeric( $entry['s'] ) || (int) $entry['s'] < 0 ) {
				return new \WP_Error( 'batch_data', 'Stored file batch contains an unsafe path.' );
			}
			$item = array(
				'p' => $entry['p'],
				's' => (int) $entry['s'],
			);
			if ( isset( $entry['m'] ) ) {
				if ( ! is_numeric( $entry['m'] ) || ( isset( $entry['h'] ) && ( ! is_string( $entry['h'] ) || ! preg_match( '/^[a-f0-9]{64}$/', $entry['h'] ) ) ) ) {
					return new \WP_Error( 'batch_data', 'Stored file batch contains invalid verification metadata.' );
				}
				$item['m'] = (int) $entry['m'];
				$item['o'] = isset( $entry['o'] ) && is_numeric( $entry['o'] ) ? (int) $entry['o'] : 0644;
				if ( isset( $entry['h'] ) ) {
					$item['h'] = $entry['h'];
				}
			}
			$normalized[] = $item;
		}
		return $normalized;
	}

	/** @return string|\WP_Error */
	private static function read_batch_with_recovery( $base, $file, $name ) {
		// Shared filesystems can briefly lag across PHP workers. Retry metadata
		// discovery before consulting the durable append-only recovery copy.
		foreach ( array( 0, 100000, 250000 ) as $delay ) {
			if ( $delay > 0 ) {
				usleep( $delay );
			}
			clearstatcache( true, $file );
			$stat = @lstat( $file );
			$real = false !== $stat && ! is_link( $file ) ? realpath( $file ) : false;
			if ( false !== $real && 0 === strpos( $real, rtrim( $base, '/' ) . '/' ) && is_file( $real ) ) {
				$json = @file_get_contents( $real );
				if ( is_string( $json ) && is_array( json_decode( $json, true ) ) ) {
					return $json;
				}
			}
		}

		$journal = $base . '/recovery.ndjson';
		$handle  = @fopen( $journal, 'rb' );
		if ( ! $handle ) {
			return new \WP_Error( 'batch_path', "File batch {$name} is unavailable and has no recovery journal." );
		}
		$recovered = null;
		while ( false !== ( $line = fgets( $handle, 8388609 ) ) ) {
			if ( strlen( $line ) > 8388608 ) {
				fclose( $handle );
				return new \WP_Error( 'batch_journal', 'The file-batch recovery journal contains an oversized record.' );
			}
			$record = json_decode( $line, true );
			if ( ! is_array( $record ) || ! isset( $record['name'], $record['json'], $record['sha256'] ) || ! hash_equals( $name, (string) $record['name'] ) ) {
				continue;
			}
			$json = base64_decode( (string) $record['json'], true );
			if ( false !== $json && preg_match( '/^[a-f0-9]{64}$/', (string) $record['sha256'] )
				&& hash_equals( (string) $record['sha256'], hash( 'sha256', $json ) ) ) {
				$recovered = $json; // Last valid record contains the newest hashes.
			}
		}
		fclose( $handle );
		if ( null === $recovered ) {
			return new \WP_Error( 'batch_path', "File batch {$name} is unavailable and its recovery record is missing or corrupt." );
		}
		$tmp = $file . '.recover';
		if ( file_put_contents( $tmp, $recovered, LOCK_EX ) !== strlen( $recovered ) || ! @rename( $tmp, $file ) ) {
			@unlink( $tmp );
			return new \WP_Error( 'batch_store', "Recovered file batch {$name} could not be restored." );
		}
		@chmod( $file, 0600 );
		return $recovered;
	}

	/**
	 * Creates a same-filesystem stage. Incremental/partial syncs copy the active
	 * tree. Copies deliberately avoid sharing inodes with the rollback tree
	 * after activation.
	 *
 * Stage and backup live as hidden siblings of the target under
 * WP_CONTENT_DIR (not nested under .sandbox-sync-work). For plugins,
 * mu-plugins and themes, activation evacuates/promotes top-level children
 * because some hosts (WP Cloud) refuse to rename those directories themselves.
 * Other dirs still use two sibling renames when the platform allows it.
	 *
	 * @return string|\WP_Error Stage path.
	 */
	private static function prepare_file_stage( &$job, $dir, $clone_active, $clone_bytes = 0, $only_tops = null ) {
		$job_id = isset( $job['id'] ) ? preg_replace( '/[^a-z0-9]/i', '', (string) $job['id'] ) : 'unknown';
		$target = WP_CONTENT_DIR . '/' . $dir;
		$stage  = WP_CONTENT_DIR . '/.sandbox-sync-stage-' . $job_id . '-' . $dir;
		$backup = WP_CONTENT_DIR . '/.sandbox-sync-bak-' . $job_id . '-' . $dir;
		$root   = self::file_work_root( $job ); // Still used for manifests/archives.

		if ( $clone_active && is_dir( $target ) ) {
			$free_bytes = @disk_free_space( WP_CONTENT_DIR );
			$required   = $clone_bytes + ( 64 * 1048576 );
			if ( false !== $free_bytes && $free_bytes < $required ) {
				return new \WP_Error( 'disk_space', sprintf( '%s/ staging requires approximately %.1f MB before downloads; only %.1f MB is available.', $dir, $required / 1048576, $free_bytes / 1048576 ) );
			}
		}

		if ( ! is_dir( $root ) && ! mkdir( $root, 0700, true ) ) {
			return new \WP_Error( 'stage_mkdir', "Cannot create file work directory {$root}." );
		}
		if ( ! file_exists( $root . '/.htaccess' ) ) {
			@file_put_contents( $root . '/.htaccess', "Require all denied\nDeny from all\n" );
			@file_put_contents( $root . '/index.php', "<?php http_response_code( 404 ); exit;\n" );
		}

		foreach ( array( $stage, $backup ) as $sidecar ) {
			if ( file_exists( $sidecar ) && ! self::delete_recursive( $sidecar ) ) {
				return new \WP_Error( 'stage_cleanup', "Cannot clear stale sidecar {$sidecar}." );
			}
		}
		if ( ! mkdir( $stage, 0755, true ) ) {
			return new \WP_Error( 'stage_mkdir', "Cannot create stage {$stage}." );
		}
		@file_put_contents( $stage . '/index.php', self::stage_guard_content() );

		if ( $clone_active ) {
			$clone_state = null;
			$cloned      = self::clone_tree_for_stage( $target, $stage, $job, $clone_state, $only_tops );
			if ( is_wp_error( $cloned ) ) {
				self::delete_recursive( $stage );
				return $cloned;
			}
		}

		$job['working_dirs'][ $dir ] = array(
			'target'     => $target,
			'stage'      => $stage,
			'backup'     => $backup,
			'activated'  => false,
			'had_target' => is_dir( $target ),
		);
		Job::save( $job );
		return $stage;
	}

	/** @return string|\WP_Error Active uploads path. */
	private static function prepare_uploads_in_place( &$job ) {
		$root       = self::file_work_root( $job );
		$target     = WP_CONTENT_DIR . '/uploads';
		$quarantine = $root . '/uploads-quarantine';
		if ( is_link( $target ) || ( ! is_dir( $target ) && ! mkdir( $target, 0755, true ) ) ) {
			return new \WP_Error( 'stage_mkdir', "Cannot create safe uploads directory {$target}." );
		}
		foreach ( array( $root, $quarantine ) as $path ) {
			if ( is_link( $path ) ) {
				return new \WP_Error( 'stage_symlink', "Uploads work path may not be a symlink: {$path}." );
			}
			if ( ! is_dir( $path ) && ! mkdir( $path, 0700, true ) ) {
				return new \WP_Error( 'stage_mkdir', "Cannot create uploads work directory {$path}." );
			}
		}
		if ( ! file_exists( $root . '/.htaccess' ) ) {
			@file_put_contents( $root . '/.htaccess', "Require all denied\nDeny from all\n" );
			@file_put_contents( $root . '/index.php', "<?php http_response_code( 404 ); exit;\n" );
		}
		$job['working_dirs']['uploads'] = array(
			'target'     => $target,
			'stage'      => $target,
			'backup'     => $quarantine,
			'activated'  => false,
			'had_target' => true,
			'in_place'   => true,
		);
		Job::save( $job );
		return $target;
	}

	/**
	 * Same-filesystem, symlink-safe rename between two trees.
	 *
	 * @return bool|\WP_Error True when moved, false when the source is absent.
	 */
	private static function move_tree_file( $from_base, $to_base, $path, $replace ) {
		if ( ! Tar_Exporter::is_safe_relative_path( $path ) ) {
			return new \WP_Error( 'file_move_path', 'Refusing to move an unsafe relative file path.' );
		}
		if ( ! is_dir( $to_base ) && ! mkdir( $to_base, 0700, true ) ) {
			return new \WP_Error( 'file_move_mkdir', "Cannot create file move destination {$to_base}." );
		}
		$from_root = realpath( $from_base );
		$to_root   = realpath( $to_base );
		if ( false === $from_root || false === $to_root || is_link( $from_base ) || is_link( $to_base ) ) {
			return new \WP_Error( 'file_move_base', 'A file move root is missing or unsafe.' );
		}
		$source = $from_root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $path );
		$stat   = @lstat( $source );
		if ( false === $stat ) {
			return false;
		}
		$source_real = realpath( $source );
		if ( is_link( $source ) || ! is_file( $source ) || false === $source_real || 0 !== strpos( $source_real, rtrim( $from_root, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR ) ) {
			return new \WP_Error( 'file_move_source', "Cannot safely move {$path}." );
		}

		$parts = explode( '/', $path );
		$name  = array_pop( $parts );
		$dir   = $to_root;
		foreach ( $parts as $part ) {
			$next = $dir . DIRECTORY_SEPARATOR . $part;
			$stat = @lstat( $next );
			if ( false === $stat ) {
				if ( ! mkdir( $next, 0700 ) ) {
					return new \WP_Error( 'file_move_mkdir', "Cannot create a destination directory for {$path}." );
				}
			} elseif ( is_link( $next ) || ! is_dir( $next ) ) {
				return new \WP_Error( 'file_move_destination', "Destination path for {$path} is unsafe." );
			}
			$real = realpath( $next );
			if ( false === $real || 0 !== strpos( $real, rtrim( $to_root, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR ) ) {
				return new \WP_Error( 'file_move_destination', "Destination path for {$path} escapes its root." );
			}
			$dir = $real;
		}
		$target      = $dir . DIRECTORY_SEPARATOR . $name;
		$target_stat = @lstat( $target );
		if ( false !== $target_stat && ( ! $replace || is_link( $target ) || ! is_file( $target ) ) ) {
			return new \WP_Error( 'file_move_target', "Cannot safely replace destination {$path}." );
		}
		if ( ! rename( $source, $target ) ) {
			return new \WP_Error( 'file_move_rename', "Cannot atomically move {$path}." );
		}
		$parent = dirname( $source );
		while ( strlen( $parent ) > strlen( $from_root ) && is_dir( $parent ) ) {
			$rest = array_diff( (array) scandir( $parent ), array( '.', '..' ) );
			if ( ! empty( $rest ) || ! @rmdir( $parent ) ) {
				break;
			}
			$parent = dirname( $parent );
		}
		return true;
	}

	/** @return int Number of quarantined uploads restored. */
	private static function restore_uploads_quarantine( $quarantine, $target ) {
		if ( ! is_dir( $quarantine ) ) {
			return 0;
		}
		$restored = 0;
		foreach ( Files::walk( $quarantine ) as $file ) {
			$result = self::move_tree_file( $quarantine, $target, $file['p'], true );
			if ( true === $result ) {
				++$restored;
			}
		}
		return $restored;
	}

	/**
	 * Copies a tree into the stage, symlinks recreated as symlinks.
	 *
	 * @param string        $source Directory to copy.
	 * @param string        $dest   Existing directory to copy into.
	 * @param array         $job    Job, for checkpoints.
	 * @param object|null   $state  Progress shared across the recursion.
	 * @param string[]|null $only   Top-level entries of $source to copy, or null for all.
	 * @return true|\WP_Error
	 */
	private static function clone_tree_for_stage( $source, $dest, &$job, &$state, $only = null ) {
		if ( ! is_dir( $source ) ) {
			return true;
		}
		if ( null === $state ) {
			$state = (object) array(
				'count'      => 0,
				'checked_at' => time(),
			);
		}
		$entries = scandir( $source );
		if ( false === $entries ) {
			return new \WP_Error( 'stage_scan', "Cannot scan {$source}." );
		}
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			if ( is_array( $only ) && ! in_array( $entry, $only, true ) ) {
				continue;
			}
			++$state->count;
			if ( 0 === $state->count % 250 || time() - $state->checked_at >= 30 ) {
				$checkpoint = self::checkpoint( $job );
				if ( is_wp_error( $checkpoint ) ) {
					return $checkpoint;
				}
				$state->checked_at = time();
			}
			$from = $source . '/' . $entry;
			$to   = $dest . '/' . $entry;
			if ( is_link( $from ) ) {
				$link = readlink( $from );
				if ( false === $link || ! symlink( $link, $to ) ) {
					return new \WP_Error( 'stage_symlink', "Cannot preserve symlink {$from}." );
				}
			} elseif ( is_dir( $from ) ) {
				$mode = fileperms( $from );
				if ( ! mkdir( $to, false !== $mode ? $mode & 0777 : 0755 ) ) {
					return new \WP_Error( 'stage_mkdir', "Cannot clone directory {$from}." );
				}
				$result = self::clone_tree_for_stage( $from, $to, $job, $state );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			} elseif ( is_file( $from ) ) {
				$copied = Files::copy_preserving_metadata( $from, $to );
				if ( is_wp_error( $copied ) ) {
					return $copied;
				}
			}
		}
		return true;
	}

	private static function invalidate_opcache_tree( $dir ) {
		if ( ! function_exists( 'opcache_invalidate' ) || ! is_dir( $dir ) ) {
			return;
		}
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS )
		);
		foreach ( $iterator as $item ) {
			if ( ! $item->isLink() && $item->isFile() && 'php' === strtolower( $item->getExtension() ) ) {
				@opcache_invalidate( $item->getPathname(), true );
			}
		}
	}

	private static function discard_file_stage( &$job, $dir ) {
		if ( empty( $job['working_dirs'][ $dir ] ) ) {
			return;
		}
		$state = $job['working_dirs'][ $dir ];
		if ( ! empty( $state['in_place'] ) ) {
			self::restore_uploads_quarantine( $state['backup'], $state['target'] );
			if ( is_dir( $state['backup'] ) ) {
				self::delete_recursive( $state['backup'] );
			}
			unset( $job['working_dirs'][ $dir ] );
			if ( empty( $job['working_dirs'] ) ) {
				$root = self::file_work_root( $job );
				if ( is_dir( $root ) ) {
					self::delete_recursive( $root );
				}
			}
			Job::save( $job );
			return;
		}
		if ( empty( $state['activated'] ) && file_exists( $state['stage'] ) ) {
			self::delete_recursive( $state['stage'] );
		}
		unset( $job['working_dirs'][ $dir ] );
		Job::save( $job );
	}

	private static function rollback_file_dirs( &$job ) {
		if ( empty( $job['working_dirs'] ) ) {
			return 0;
		}
		$rolled = 0;
		foreach ( array_reverse( $job['working_dirs'], true ) as $dir => $state ) {
			if ( ! empty( $state['in_place'] ) ) {
				$restored = self::restore_uploads_quarantine( $state['backup'], $state['target'] );
				if ( ! empty( $state['activated'] ) || $restored > 0 ) {
					++$rolled;
				}
				continue;
			}
			if ( ! empty( $state['activated'] ) ) {
				if ( ! empty( $state['content_swap'] ) ) {
					// Host forbids renaming the target directory; restore by
					// swapping children back. Never delete_recursive($target).
					// Entries the swap left in place were never moved to the
					// backup, so deleting them here would destroy them.
					self::delete_dir_children( $state['target'], true, isset( $state['left'] ) ? (array) $state['left'] : array() );
					if ( ! empty( $state['had_target'] ) && is_dir( $state['backup'] ) ) {
						self::move_dir_children( $state['backup'], $state['target'], array( 'preserve_symlinks_in_to' => true ) );
					}
					if ( is_dir( $state['backup'] ) ) {
						self::delete_recursive( $state['backup'] );
					}
					++$rolled;
					continue;
				}
				if ( file_exists( $state['target'] ) ) {
					self::delete_recursive( $state['target'] );
				}
				if ( ! empty( $state['had_target'] ) && is_dir( $state['backup'] ) ) {
					@rename( $state['backup'], $state['target'] );
				}
				++$rolled;
			} elseif ( file_exists( $state['stage'] ) ) {
				self::delete_recursive( $state['stage'] );
			}
		}
		$root = self::file_work_root( $job );
		if ( is_dir( $root ) ) {
			self::delete_recursive( $root );
		}
		$job['working_dirs'] = array();
		return $rolled;
	}

	private static function finalize_file_dirs( &$job ) {
		$removed = 0;
		foreach ( (array) $job['working_dirs'] as $state ) {
			if ( ! empty( $state['in_place'] ) ) {
				if ( ! empty( $state['backup'] ) && file_exists( $state['backup'] ) && self::delete_recursive( $state['backup'] ) ) {
					++$removed;
				}
				continue;
			}
			foreach ( array( 'stage', 'backup' ) as $key ) {
				if ( ! empty( $state[ $key ] ) && file_exists( $state[ $key ] ) && self::delete_recursive( $state[ $key ] ) ) {
					++$removed;
				}
			}
		}
		$root = self::file_work_root( $job );
		if ( is_dir( $root ) ) {
			self::delete_recursive( $root );
		}
		$job['working_dirs'] = array();
		Job::save( $job );
		return $removed;
	}

	private static function delete_recursive( $path ) {
		if ( is_link( $path ) || is_file( $path ) ) {
			return unlink( $path );
		}
		if ( is_dir( $path ) ) {
			$entries = scandir( $path );
			if ( false === $entries ) {
				return false;
			}
			foreach ( $entries as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}
				if ( ! self::delete_recursive( $path . '/' . $entry ) ) {
					return false;
				}
			}
			return rmdir( $path );
		}
		return true;
	}

	/**
	 * Local (target) name for a source table: local prefix + source suffix.
	 */
}
