<?php
/** Database import, validation, activation, identity preservation, and rollback. */

namespace SandboxSync;

defined( 'ABSPATH' ) || exit;

trait Sync_Runner_Database {

	private static function step_table( &$job, $source_table, $schema_only = false ) {
		global $wpdb;

		$manifest_table = null;
		foreach ( (array) $job['manifest']['tables'] as $table ) {
			if ( $table['name'] === $source_table ) {
				$manifest_table = $table;
				break;
			}
		}
		if ( ! $manifest_table ) {
			return new \WP_Error( 'bad_table', "Table {$source_table} is not in the manifest." );
		}

		$source_prefix    = $job['manifest']['site']['prefix'];
		$target           = self::target_table_name( $source_prefix, $source_table );
		$shadow           = self::working_table_name( 'shadow', $job['id'], $target );
		$checkpoint_table = self::working_table_name( 'checkpoint', $job['id'], $target . '_checkpoint' );
		// Compatibility for a job planned by an older in-flight worker. New jobs
		// pre-register every shadow/checkpoint/backup name in one manifest save.
		$registered = isset( $job['working_tables'] ) && is_array( $job['working_tables'] ) ? $job['working_tables'] : array();
		if ( ! in_array( $shadow, $registered, true ) || ! in_array( $checkpoint_table, $registered, true ) ) {
			self::register_working_tables( $job, array( $shadow, $checkpoint_table ) );
			Job::save( $job );
		}
		if ( $schema_only ) {
			return self::prepare_schema_only_table( $job, $source_table, $shadow, $checkpoint_table, $manifest_table );
		}

		// Incremental skip: both the source table and our local copy are
		// unchanged since the last sync. postmeta may still need a scoped
		// refresh when posts were delta-imported for title/content edits that
		// did not change the postmeta checksum.
		$force_postmeta_delta = false;
		if ( Table_Delta::is_postmeta_table( $source_table, $source_prefix )
			&& ! empty( $job['posts_row_plan']['dirty'] )
			&& empty( $job['posts_full_import'] ) ) {
			$force_postmeta_delta = true;
		}
		if ( ! $force_postmeta_delta
			&& ! in_array( $source_table, (array) $job['force_tables'], true )
			&& ! empty( $job['options']['incremental'] )
			&& isset( $manifest_table['checksum'] ) && null !== $manifest_table['checksum'] ) {
			$state = get_option( self::OPT_TABLE_STATE );
			$state = is_array( $state ) ? $state : array();
			if ( isset( $state[ $target ]['source_checksum'], $state[ $target ]['local_checksum'] )
				&& null !== $state[ $target ]['source_checksum']
				&& (string) $state[ $target ]['source_checksum'] === (string) $manifest_table['checksum']
				&& self::table_exists( $target )
				&& (string) DB_Exporter::table_checksum( $target ) === (string) $state[ $target ]['local_checksum'] ) {
				return array(
					'message' => sprintf( 'Skipped %s: unchanged on both sides since the last sync.', $source_table ),
					'skipped' => true,
				);
			}
		}

		// Row delta for posts / postmeta (protocol 14+): clone local shadow and
		// apply only changed rows. Falls back to full import on any soft failure.
		if ( ! empty( $job['options']['incremental'] )
			&& Table_Delta::source_supports_delta( $job )
			&& empty( $job['steps'][ $job['current'] ]['yielded'] )
			&& null === self::load_import_checkpoint( $shadow, $checkpoint_table ) ) {
			$delta = self::try_content_table_delta( $job, $source_table, $source_prefix, $target, $shadow, $checkpoint_table, $manifest_table, $force_postmeta_delta );
			if ( ! is_wp_error( $delta ) && is_array( $delta ) && ! empty( $delta['delta'] ) ) {
				if ( ! in_array( $source_table, (array) $job['imported'], true ) ) {
					$job['imported'][] = $source_table;
				}
				self::register_prepared_table( $job, $source_table );
				return $delta;
			}
			if ( is_wp_error( $delta ) && in_array( $delta->get_error_code(), array( 'job_cancelled', 'lease_lost', 'transfer_cancelled' ), true ) ) {
				return $delta;
			}
			if ( is_wp_error( $delta ) ) {
				Job::log( $job, sprintf( 'Row delta unavailable for %s (%s); using full table import.', $source_table, $delta->get_error_message() ) );
				if ( Table_Delta::is_posts_table( $source_table, $source_prefix ) ) {
					$job['posts_full_import'] = true;
					unset( $job['posts_row_plan'] );
					Job::save( $job );
				}
			}
		}

		// Opportunistic shell fast path: one continuous mysqldump-to-mysql
		// stream instead of thousands of JSON chunks. Any failure falls back
		// to the chunked importer below, which starts from a clean slate
		// because the shell path always drops its partial shadow on error.
		if ( self::shell_db_eligible( $job )
			&& (int) $manifest_table['size'] >= Db_Shell::min_table_bytes()
			&& empty( $job['steps'][ $job['current'] ]['yielded'] )
			&& null === self::load_import_checkpoint( $shadow, $checkpoint_table ) ) {
			$shell = self::import_table_via_shell( $job, $source_table, $shadow, $checkpoint_table, $manifest_table );
			if ( ! is_wp_error( $shell ) ) {
				return $shell;
			}
			if ( in_array( $shell->get_error_code(), array( 'job_cancelled', 'lease_lost', 'transfer_cancelled' ), true ) ) {
				return $shell; // Not a fast-path defect: stop, do not fall back.
			}
			$job['shell_db_failures'] = isset( $job['shell_db_failures'] ) ? (int) $job['shell_db_failures'] + 1 : 1;
			Job::log( $job, sprintf( 'Shell fast path failed for %s (%s); using the chunked importer instead.', $source_table, $shell->get_error_message() ) );
			Job::save( $job );
		}

		// Adaptive chunk size: aim for CHUNK_TARGET_BYTES per request.
		$avg_row = max( 64, (int) $manifest_table['avg_row'] );
		$limit   = (int) min( self::CHUNK_MAX_ROWS, max( self::CHUNK_MIN_ROWS, floor( self::CHUNK_TARGET_BYTES / $avg_row ) ) );

		$statement_limit = isset( $job['preflight']['statement_limit'] ) ? (int) $job['preflight']['statement_limit'] : self::database_statement_limit();
		$offset          = 0;
		$total_rows      = 0;
		$expected_rows   = null;
		$first           = true;
		$chunk           = null;
		$resume_done     = false;
		$prefetch        = null;
		$prefetch_query  = null;

		// A planned yield re-enters this step every slice; only an unexpected
		// re-entry (crashed or killed worker) is worth a log line.
		$yield_reentry = ! empty( $job['steps'][ $job['current'] ]['yielded'] );

		try {
			$resume = self::load_import_checkpoint( $shadow, $checkpoint_table );
			if ( is_wp_error( $resume ) ) {
				return $resume;
			}
			if ( is_array( $resume ) ) {
				$offset        = $resume['next_offset'];
				$total_rows    = $resume['total_rows'];
				$expected_rows = $resume['expected_rows'];
				$resume_done   = $resume['done'];
				$first         = false;
				$chunk         = array( 'next_cursor' => $resume['next_cursor'] );
				if ( ! $yield_reentry ) {
					Job::log( $job, sprintf( 'Resuming %s after %d committed rows.', $source_table, $total_rows ) );
				}
			} else {
				if ( false === $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i, %i', $checkpoint_table, $shadow ) ) ) {
					return new \WP_Error( 'shadow_drop', "Could not reset working tables for {$source_table}: " . $wpdb->last_error );
				}
			}
			while ( ! $resume_done ) {
				// Short lease: this loop checkpoints every chunk (sub-second to a
				// few seconds apart), so a killed worker frees the job quickly.
				$checkpoint = self::checkpoint( $job, true, Job::CHUNK_LOCK_TTL );
				if ( is_wp_error( $checkpoint ) ) {
					return $checkpoint;
				}
				// Harvest the prefetched chunk, or fetch synchronously.
				if ( null !== $prefetch ) {
					$chunk    = Client::finish_json_async( $prefetch );
					$prefetch = null;
					if ( is_wp_error( $chunk ) && is_array( $prefetch_query ) ) {
						$chunk = Client::get_json( 'table', $prefetch_query );
					}
					$prefetch_query = null;
				} else {
					$chunk = Client::get_json( 'table', self::chunk_query( $source_table, $limit, $chunk, $offset, $statement_limit ) );
				}
				if ( is_wp_error( $chunk ) ) {
					return $chunk;
				}
				$valid_chunk = self::validate_table_chunk( $chunk, $source_table, $limit, $first );
				if ( is_wp_error( $valid_chunk ) ) {
					return $valid_chunk;
				}
				$checkpoint = self::checkpoint( $job, true, Job::CHUNK_LOCK_TTL );
				if ( is_wp_error( $checkpoint ) ) {
					return $checkpoint;
				}

				// Overlap network with import: while this chunk's statements run
				// locally, the next chunk (whose keyset cursor we now know)
				// downloads in the background.
				if ( empty( $chunk['done'] ) ) {
					$prefetch_query = self::chunk_query( $source_table, $limit, $chunk, $offset + $limit, $statement_limit );
					$prefetch       = Client::start_json_async( 'table', $prefetch_query );
				}

				if ( $first ) {
					$first         = false;
					$expected_rows = isset( $chunk['total_rows'] ) ? (int) $chunk['total_rows'] : null;
					$created       = self::create_shadow_from_chunk( $chunk, $source_table, $shadow, $manifest_table );
					if ( is_wp_error( $created ) ) {
						return $created;
					}
					if ( false === $wpdb->query(
						$wpdb->prepare(
							'CREATE TABLE %i (
					`id` TINYINT UNSIGNED NOT NULL,
					`next_offset` BIGINT UNSIGNED NOT NULL,
					`next_cursor` TEXT NULL,
					`total_rows` BIGINT UNSIGNED NOT NULL,
					`expected_rows` BIGINT UNSIGNED NOT NULL,
					`done` TINYINT UNSIGNED NOT NULL DEFAULT 0,
					PRIMARY KEY (`id`)
					) ENGINE=InnoDB',
							$checkpoint_table
						)
					) ) {
						return new \WP_Error( 'checkpoint_create', "Could not create import checkpoint for {$source_table}: " . $wpdb->last_error );
					}
				}

				$statements = DB_Exporter::decode_statements( $chunk );
				if ( is_wp_error( $statements ) ) {
					return $statements;
				}

				// One transaction per chunk: single fsync instead of one per
				// statement.
				if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
					return new \WP_Error( 'transaction', "Could not start import transaction for {$shadow}: " . $wpdb->last_error );
				}
				foreach ( $statements as $statement ) {
					if ( strlen( $statement ) > $statement_limit ) {
						$wpdb->query( 'ROLLBACK' );
						return new \WP_Error( 'statement_too_large', sprintf( 'Source returned a %d-byte statement above the negotiated %d-byte target limit.', strlen( $statement ), $statement_limit ) );
					}
					$head = "INSERT INTO `{$source_table}`";
					if ( 0 !== strpos( $statement, $head ) ) {
						$wpdb->query( 'ROLLBACK' );
						return new \WP_Error( 'insert_format', "Unexpected INSERT format for {$source_table}." );
					}
					$statement = "INSERT INTO `{$shadow}`" . substr( $statement, strlen( $head ) );
					if ( false === $wpdb->query( $statement ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Strictly decoded, hashed and anchored INSERT payload.
						$wpdb->query( 'ROLLBACK' );
						return new \WP_Error( 'insert_failed', "INSERT failed for {$shadow}: " . $wpdb->last_error );
					}
				}
				$committed_rows = $total_rows + (int) $chunk['row_count'];
				$next_offset    = $offset + $limit;
				$next_cursor    = isset( $chunk['next_cursor'] ) && null !== $chunk['next_cursor'] ? (string) $chunk['next_cursor'] : '';
				$done           = ! empty( $chunk['done'] ) ? 1 : 0;
				$checkpoint_sql = $wpdb->prepare(
					'REPLACE INTO %i (`id`, `next_offset`, `next_cursor`, `total_rows`, `expected_rows`, `done`) VALUES (1, %d, %s, %d, %d, %d)',
					$checkpoint_table,
					$next_offset,
					$next_cursor,
					$committed_rows,
					$expected_rows,
					$done
				);
				if ( false === $wpdb->query( $checkpoint_sql ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.
					$wpdb->query( 'ROLLBACK' );
					return new \WP_Error( 'checkpoint_write', "Could not checkpoint import for {$source_table}: " . $wpdb->last_error );
				}
				if ( false === $wpdb->query( 'COMMIT' ) ) {
					$wpdb->query( 'ROLLBACK' );
					return new \WP_Error( 'commit_failed', "COMMIT failed for {$shadow}: " . $wpdb->last_error );
				}

				$total_rows = $committed_rows;
				if ( isset( $job['steps'][ $job['current'] ] ) ) {
					$job['steps'][ $job['current'] ]['message'] = sprintf( 'Transferred %d of %s rows.', $total_rows, null === $expected_rows ? '?' : $expected_rows );
					$job['heartbeat']                           = time();
					Job::save( $job );
				}
				if ( ! empty( $chunk['done'] ) ) {
					break;
				}
				$offset += $limit;
				// Yield at the slice deadline: the chunk just committed durably,
				// so the next tick resumes from the checkpoint instead of this
				// process running the whole table (and outliving the platform's
				// web-request lifetime).
				if ( self::slice_deadline_reached() ) {
					return array(
						'yield'   => true,
						'message' => sprintf(
							'Importing %s: %s of %s rows committed…',
							$source_table,
							number_format_i18n( $total_rows ),
							null === $expected_rows ? '?' : number_format_i18n( $expected_rows )
						),
					);
				}
			}

			$actual_rows = DB_Exporter::exact_row_count( $shadow );
			// Transfer integrity: every committed row must land in the shadow.
			// The opening source count is only a snapshot; live inserts/deletes
			// during keyset pagination legitimately shift the delivered total
			// (same policy as the shell path and post-import drift report).
			if ( null === $expected_rows || $actual_rows !== $total_rows ) {
				return new \WP_Error(
					'row_count_mismatch',
					sprintf( 'Row-count validation failed for %s (source start: %s, transferred: %d, imported: %d).', $source_table, null === $expected_rows ? 'missing' : $expected_rows, $total_rows, $actual_rows )
				);
			}
			if ( $expected_rows !== $total_rows ) {
				Job::log( $job, sprintf( 'Live drift on %s during export: source counted %d rows at start, keyset snapshot delivered %d.', $source_table, $expected_rows, $total_rows ) );
			}

			if ( ! in_array( $source_table, (array) $job['imported'], true ) ) {
				$job['imported'][] = $source_table;
			}
			self::register_prepared_table( $job, $source_table );

			return array(
				'message' => sprintf( 'Imported %s (%d rows, %d rows/chunk).', $source_table, $total_rows, $limit ),
			);
		} finally {
			if ( null !== $prefetch ) {
				Client::close_json_async( $prefetch );
			}
		}
	}

	/**
	 * The shell fast path needs: both sites opted in (source advertises the
	 * capability in the manifest; this sandbox passes Db_Shell checks), a
	 * process that can safely block for minutes (CLI worker — a web worker is
	 * subject to platform request kills mid-stream, so it keeps the yieldable
	 * chunked path), and no history of repeated shell failures in this job.
	 */
	private static function shell_db_eligible( $job ) {
		if ( empty( $job['manifest']['site']['db_shell'] ) || ! Db_Shell::target_available() ) {
			return false;
		}
		if ( isset( $job['shell_db_failures'] ) && (int) $job['shell_db_failures'] >= 2 ) {
			return false;
		}
		$cli_only = (bool) apply_filters( 'sandbox_sync_shell_db_cli_only', true );
		if ( $cli_only && 'cli' !== PHP_SAPI ) {
			return false;
		}
		return true;
	}

	/**
	 * Imports one table as a single continuous stream: the source pipes
	 * `mysqldump` (gzip'd, SHA-256 trailer) and this side pipes each SQL line
	 * into a local `mysql` process, rewriting INSERT targets to the shadow
	 * table on the fly. The schema still arrives via the verified JSON probe
	 * so the shadow is created from a manifest-hash-checked CREATE TABLE.
	 *
	 * Not resumable mid-table by design: any failure drops the partial import
	 * and the caller falls back to the chunked keyset importer. Transfer
	 * integrity comes from gzip framing, the end-to-end dump hash and mysql's
	 * abort-on-first-error batch mode — strictly stronger than the chunked
	 * path's per-chunk hashes.
	 *
	 * Fencing: rows stream into an attempt-unique table whose name no other
	 * worker ever computes, so an orphaned mysql child from a lost lease can
	 * only ever write into its own doomed attempt table — never into the
	 * canonical shadow a successor is filling. The attempt table is renamed
	 * to the canonical shadow only after the child exited cleanly AND the
	 * lease was re-verified; RENAME fails closed if a successor already
	 * created the shadow.
	 *
	 * @return array|\WP_Error Step result array on success.
	 */
	private static function import_table_via_shell( &$job, $source_table, $shadow, $checkpoint_table, $manifest_table ) {
		global $wpdb;

		$checkpoint = self::checkpoint( $job, true, Job::STREAM_LOCK_TTL );
		if ( is_wp_error( $checkpoint ) ) {
			return $checkpoint;
		}
		if ( false === $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i, %i', $checkpoint_table, $shadow ) ) ) {
			return new \WP_Error( 'shadow_drop', "Could not reset working tables for {$source_table}: " . $wpdb->last_error );
		}

		// Attempt-unique staging table; registered (and persisted) before it
		// exists so abandoned-job recovery is guaranteed to clean it up.
		$attempt = self::working_table_name( 'shadow', $job['id'], $source_table . '@attempt@' . substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 8 ) );
		self::register_working_tables( $job, array( $attempt ) );
		Job::save( $job );

		// Verified schema probe: CREATE TABLE hash must match the manifest.
		$probe = Client::get_json(
			'table',
			array(
				'table'        => $source_table,
				'schema_probe' => 1,
			)
		);
		if ( is_wp_error( $probe ) ) {
			return $probe;
		}
		$expected_rows = isset( $probe['total_rows'] ) ? (int) $probe['total_rows'] : null;
		$created       = self::create_shadow_from_chunk( $probe, $source_table, $attempt, $manifest_table );
		if ( is_wp_error( $created ) ) {
			return $created;
		}

		$import = Db_Shell::open_mysql_import();
		if ( is_wp_error( $import ) ) {
			return $import;
		}

		$streamed_bytes = 0;
		$in_insert      = false;
		$on_line        = function ( $line ) use ( $source_table, $attempt, $import, &$streamed_bytes, &$in_insert ) {
			$streamed_bytes += strlen( $line );
			// Every forwarded line must contain at most one statement: a ";"
			// outside a string literal is only legal as the final character.
			// This blocks smuggling a second statement after a valid INSERT
			// head ("INSERT INTO `t` VALUES (1); DROP TABLE `wp_users`;").
			if ( ! Db_Shell::line_is_single_statement( $line ) ) {
				return new \WP_Error(
					'shell_dump_statement',
					sprintf( 'Multiple statements on one line in the %s dump stream: %s', $source_table, substr( trim( $line ), 0, 160 ) )
				);
			}
			// MariaDB's mysqldump splits extended INSERTs across lines (one
			// row tuple per line). String values never contain a literal
			// newline (mysqldump escapes them), so a line ending in ";" is
			// exactly a statement boundary; every line until then belongs to
			// the INSERT whose head was already rewritten to the attempt table.
			if ( $in_insert ) {
				if ( Db_Shell::line_terminates_statement( $line ) ) {
					$in_insert = false;
				}
				return call_user_func( $import['write'], $line );
			}
			$rewritten = Db_Shell::rewrite_insert_line( $line, $source_table, $attempt );
			if ( null !== $rewritten ) {
				$in_insert = ! Db_Shell::line_terminates_statement( $line );
				return call_user_func( $import['write'], $rewritten );
			}
			if ( Db_Shell::is_allowed_preamble_line( $line ) ) {
				return call_user_func( $import['write'], $line );
			}
			return new \WP_Error(
				'shell_dump_statement',
				sprintf(
					'Unexpected non-INSERT statement in the %s dump stream: %s',
					$source_table,
					substr( trim( $line ), 0, 160 )
				)
			);
		};

		$tick = function () use ( &$job, $source_table, &$streamed_bytes, $expected_rows ) {
			if ( isset( $job['steps'][ $job['current'] ] ) ) {
				$job['steps'][ $job['current'] ]['message'] = sprintf(
					'Streaming %s: %s received (%s rows expected)…',
					$source_table,
					size_format( $streamed_bytes, 1 ),
					null === $expected_rows ? '?' : number_format_i18n( $expected_rows )
				);
			}
			return self::checkpoint( $job, true, Job::STREAM_LOCK_TTL );
		};

		$stream = Client::stream_table_dump( $source_table, $on_line, $tick );
		if ( is_wp_error( $stream ) ) {
			call_user_func( $import['abort'] );
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $attempt ) );
			return $stream;
		}
		$finished = call_user_func( $import['finish'] );
		if ( is_wp_error( $finished ) ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $attempt ) );
			return $finished;
		}

		// The lease may have expired while mysql drained its final buffer; a
		// successor may already be running its own attempt. Fail closed before
		// publishing — and after a lost lease, touch nothing shared: the
		// attempt table stays behind for working-table cleanup to collect.
		$checkpoint = self::checkpoint( $job, true, Job::STREAM_LOCK_TTL );
		if ( is_wp_error( $checkpoint ) ) {
			return $checkpoint;
		}

		// Publish atomically. RENAME fails if the shadow already exists (a
		// fenced-out predecessor cannot overwrite a successor's work).
		if ( false === $wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i', $attempt, $shadow ) ) ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $attempt ) );
			return new \WP_Error( 'shell_publish', "Could not publish the streamed import for {$source_table}: " . $wpdb->last_error );
		}

		$actual_rows = DB_Exporter::exact_row_count( $shadow );
		if ( null !== $expected_rows && $actual_rows !== $expected_rows ) {
			// The dump is a consistent snapshot taken slightly after the probe
			// count, so live writes legitimately shift the number. Transfer
			// integrity is already guaranteed by the hash trailer; report the
			// drift instead of failing (same policy as the post-import report).
			Job::log( $job, sprintf( 'Live drift on %s during export: probe counted %d rows, snapshot delivered %d.', $source_table, $expected_rows, $actual_rows ) );
		}

		if ( ! in_array( $source_table, (array) $job['imported'], true ) ) {
			$job['imported'][] = $source_table;
		}
		self::register_prepared_table( $job, $source_table );

		return array(
			'message' => sprintf( 'Imported %s via shell stream (%d rows, %s dumped).', $source_table, $actual_rows, size_format( (int) $stream['bytes'], 1 ) ),
		);
	}

	/**
	 * Recreate a table's exact source schema in an empty shadow without reading
	 * or transferring any source rows. The shadow joins the normal atomic
	 * activation/rollback mapping, while data validation and URL rewriting
	 * deliberately ignore it.
	 */
	private static function prepare_schema_only_table( &$job, $source_table, $shadow, $checkpoint_table, $manifest_table ) {
		global $wpdb;
		$checkpoint = self::checkpoint( $job );
		if ( is_wp_error( $checkpoint ) ) {
			return $checkpoint;
		}
		if ( false === $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i, %i', $checkpoint_table, $shadow ) ) ) {
			return new \WP_Error( 'shadow_drop', "Could not reset schema-only working table for {$source_table}: " . $wpdb->last_error );
		}
		$chunk = Client::get_json(
			'table',
			array(
				'table'       => $source_table,
				'schema_only' => 1,
			)
		);
		if ( is_wp_error( $chunk ) ) {
			return $chunk;
		}
		$valid = self::validate_table_chunk( $chunk, $source_table, 1, true );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		if ( 0 !== (int) $chunk['row_count'] || empty( $chunk['done'] ) || 0 !== (int) $chunk['total_rows'] ) {
			return new \WP_Error( 'schema_only_payload', "Source returned row data for schema-only table {$source_table}." );
		}
		$created = self::create_shadow_from_chunk( $chunk, $source_table, $shadow, $manifest_table );
		if ( is_wp_error( $created ) ) {
			return $created;
		}
		self::register_prepared_table( $job, $source_table );
		return array( 'message' => sprintf( 'Prepared empty schema-only table %s; no source rows were requested or copied.', $source_table ) );
	}

	/** @return true|\WP_Error */
	private static function create_shadow_from_chunk( $chunk, $source_table, $shadow, $manifest_table ) {
		global $wpdb;
		$source_schema = base64_decode( (string) ( $chunk['create_sql_b64'] ?? '' ), true );
		if ( false === $source_schema || '' === $source_schema ) {
			return new \WP_Error( 'no_create', "Source did not return a CREATE TABLE for {$source_table}." );
		}
		if ( empty( $chunk['create_sha256'] ) || ! hash_equals( (string) $chunk['create_sha256'], hash( 'sha256', $source_schema ) ) ) {
			return new \WP_Error( 'create_hash', "CREATE TABLE payload failed integrity validation for {$source_table}." );
		}
		$manifest_hash = isset( $manifest_table['compat']['schema_sha256'] ) ? (string) $manifest_table['compat']['schema_sha256'] : '';
		if ( '' === $manifest_hash || ! hash_equals( $manifest_hash, DB_Exporter::schema_sha256( $source_schema ) ) ) {
			return new \WP_Error( 'schema_changed', "Source schema changed after the manifest was captured for {$source_table}." );
		}
		$pattern    = '/^CREATE TABLE `' . preg_quote( $source_table, '/' ) . '`/';
		$create_sql = preg_replace( $pattern, "CREATE TABLE `{$shadow}`", $source_schema, 1, $replaced );
		if ( 1 !== $replaced ) {
			return new \WP_Error( 'create_format', "Unexpected CREATE TABLE format for {$source_table}." );
		}
		if ( false === $wpdb->query( $create_sql ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Integrity-checked SHOW CREATE payload with an anchored table-name replacement.
			return new \WP_Error( 'create_failed', "CREATE TABLE failed for {$shadow}: " . $wpdb->last_error );
		}
		$created = $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE TABLE %i', $shadow ), ARRAY_N );
		$actual  = is_array( $created ) && isset( $created[1] ) ? $created[1] : '';
		$actual  = preg_replace( '/^CREATE TABLE `' . preg_quote( $shadow, '/' ) . '`/', "CREATE TABLE `{$source_table}`", $actual, 1 );
		if ( '' === $actual || ! hash_equals( DB_Exporter::schema_sha256( $source_schema ), DB_Exporter::schema_sha256( $actual ) ) ) {
			return new \WP_Error( 'schema_mismatch', "Target database did not reproduce the source schema exactly for {$source_table}." );
		}
		return true;
	}

	private static function register_prepared_table( &$job, $source_table ) {
		if ( ! isset( $job['prepared_tables'] ) || ! is_array( $job['prepared_tables'] ) ) {
			$job['prepared_tables'] = isset( $job['imported'] ) && is_array( $job['imported'] ) ? $job['imported'] : array();
		}
		if ( ! in_array( $source_table, $job['prepared_tables'], true ) ) {
			$job['prepared_tables'][] = $source_table;
		}
	}

	/**
	 * Loads a transactionally coupled table-import checkpoint.
	 *
	 * The checkpoint row is committed in the same InnoDB transaction as each
	 * imported chunk. A worker can therefore resume without guessing whether a
	 * downloaded chunk reached durable storage. A missing half of the pair is
	 * treated as an interrupted initialisation and rebuilt by the caller.
	 *
	 * @return array|null|\WP_Error Resume state, null for a clean restart.
	 */
	private static function load_import_checkpoint( $shadow, $checkpoint_table ) {
		global $wpdb;
		$has_shadow     = self::table_exists( $shadow );
		$has_checkpoint = self::table_exists( $checkpoint_table );
		if ( ! $has_shadow || ! $has_checkpoint ) {
			return null;
		}

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT `next_offset`, `next_cursor`, `total_rows`, `expected_rows`, `done` FROM %i WHERE `id` = 1', $checkpoint_table ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			// A crash after CREATE TABLE but before the first chunk commit.
			return null;
		}
		$actual_rows = DB_Exporter::exact_row_count( $shadow );
		return self::validate_import_checkpoint_state( $row, $actual_rows, $checkpoint_table );
	}

	/**
	 * Pure validation for durable import state (public for fault tests).
	 *
	 * @return array|\WP_Error
	 */
	public static function validate_import_checkpoint_state( $row, $actual_rows, $name = 'checkpoint' ) {
		if ( ! is_array( $row ) ) {
			return new \WP_Error( 'checkpoint_corrupt', "Import checkpoint {$name} is malformed." );
		}
		foreach ( array( 'next_offset', 'total_rows', 'expected_rows', 'done' ) as $field ) {
			if ( ! isset( $row[ $field ] ) || ! preg_match( '/^\d+$/', (string) $row[ $field ] ) ) {
				return new \WP_Error( 'checkpoint_corrupt', "Import checkpoint {$name} contains invalid {$field}." );
			}
		}
		$state = array(
			'next_offset'   => (int) $row['next_offset'],
			'next_cursor'   => isset( $row['next_cursor'] ) && '' !== $row['next_cursor'] ? (string) $row['next_cursor'] : null,
			'total_rows'    => (int) $row['total_rows'],
			'expected_rows' => (int) $row['expected_rows'],
			'done'          => 1 === (int) $row['done'],
		);
		if ( ( null !== $state['next_cursor'] && ( strlen( $state['next_cursor'] ) > 4096 || ! preg_match( '/^[A-Za-z0-9_-]+$/', $state['next_cursor'] ) ) )
			|| $state['next_offset'] < $state['total_rows']
			|| (int) $row['done'] > 1 ) {
			return new \WP_Error( 'checkpoint_corrupt', "Import checkpoint {$name} is internally inconsistent." );
		}
		if ( (int) $actual_rows !== $state['total_rows'] ) {
			return new \WP_Error( 'checkpoint_diverged', sprintf( 'Import checkpoint %s records %d rows but the shadow contains %d; refusing a duplicate or incomplete resume.', $name, $state['total_rows'], $actual_rows ) );
		}
		return $state;
	}

	/**
	 * Query args for the next table chunk: keyset cursor when the previous
	 * chunk provided one, OFFSET fallback otherwise (PK-less tables).
	 *
	 * @param string     $table    Source table.
	 * @param int        $limit    Rows per chunk.
	 * @param array|null $previous Previous decoded chunk (null = first).
	 * @param int        $offset   OFFSET fallback value.
	 */
	private static function chunk_query( $table, $limit, $previous, $offset, $statement_limit ) {
		$query = array(
			'table'     => $table,
			'limit'     => $limit,
			'max_bytes' => $statement_limit,
		);
		if ( is_array( $previous ) && ! empty( $previous['next_cursor'] ) ) {
			// Already base64-JSON from the source; travels back as-is.
			$query['cursor'] = $previous['next_cursor'];
		} else {
			$query['offset'] = $offset;
		}
		return $query;
	}

	/**
	 * Attempts posts/postmeta row-delta import. Returns WP_Error to fall back,
	 * or a step result array with delta=true on success.
	 *
	 * @return array|\WP_Error|null Null when this table is not a content-delta candidate.
	 */
	private static function try_content_table_delta( &$job, $source_table, $source_prefix, $target, $shadow, $checkpoint_table, $manifest_table, $force_postmeta_delta ) {
		$is_posts    = Table_Delta::is_posts_table( $source_table, $source_prefix );
		$is_postmeta = Table_Delta::is_postmeta_table( $source_table, $source_prefix );
		if ( ! $is_posts && ! $is_postmeta ) {
			return null;
		}
		if ( $is_posts ) {
			$plan = Table_Delta::posts_plan( $job, $source_table, $target );
			if ( is_wp_error( $plan ) ) {
				return $plan;
			}
			return Table_Delta::apply_posts_delta( $job, $source_table, $shadow, $checkpoint_table, $manifest_table, $plan );
		}

		// postmeta: need a posts plan. Prefer one already built; otherwise find
		// the posts table and build it (posts step may run after postmeta in
		// older plans — we also reorder planning so posts comes first).
		if ( ! empty( $job['posts_full_import'] ) ) {
			return new \WP_Error( 'delta_posts_full', 'Posts used a full import this job; postmeta follows.' );
		}
		$posts_source = null;
		foreach ( (array) $job['manifest']['tables'] as $table ) {
			if ( Table_Delta::is_posts_table( $table['name'], $source_prefix ) ) {
				$posts_source = $table['name'];
				break;
			}
		}
		if ( null === $posts_source ) {
			return new \WP_Error( 'delta_no_posts', 'No posts table in the manifest; cannot scope postmeta.' );
		}
		$posts_target = self::target_table_name( $source_prefix, $posts_source );
		$plan         = Table_Delta::posts_plan( $job, $posts_source, $posts_target );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		if ( ! $force_postmeta_delta && empty( $plan['dirty'] ) ) {
			return new \WP_Error( 'delta_postmeta_clean', 'Posts index is unchanged; postmeta should use checksum skip or full import.' );
		}
		return Table_Delta::apply_postmeta_delta( $job, $source_table, $target, $shadow, $checkpoint_table, $manifest_table, $plan );
	}

	/** @return true|\WP_Error */
	private static function validate_table_chunk( $chunk, $table, $limit, $first ) {
		if ( ! is_array( $chunk ) || ! isset( $chunk['table'], $chunk['row_count'], $chunk['done'] ) || (string) $chunk['table'] !== (string) $table ) {
			return new \WP_Error( 'chunk_shape', "Source returned a malformed or mismatched chunk for {$table}." );
		}
		if ( ! is_numeric( $chunk['row_count'] ) || (int) $chunk['row_count'] < 0 || (int) $chunk['row_count'] > $limit ) {
			return new \WP_Error( 'chunk_rows', "Source returned an invalid row count for {$table}." );
		}
		if ( ! is_bool( $chunk['done'] ) && ! in_array( $chunk['done'], array( 0, 1, '0', '1' ), true ) ) {
			return new \WP_Error( 'chunk_done', "Source returned an invalid completion marker for {$table}." );
		}
		if ( isset( $chunk['next_cursor'] ) && null !== $chunk['next_cursor'] && ( ! is_string( $chunk['next_cursor'] ) || strlen( $chunk['next_cursor'] ) > 4096 || ! preg_match( '/^[A-Za-z0-9_-]+$/', $chunk['next_cursor'] ) ) ) {
			return new \WP_Error( 'chunk_cursor', "Source returned an invalid cursor for {$table}." );
		}
		if ( $first && ( ! isset( $chunk['total_rows'] ) || ! is_numeric( $chunk['total_rows'] ) || (int) $chunk['total_rows'] < 0 ) ) {
			return new \WP_Error( 'chunk_total', "Source omitted the exact starting row count for {$table}." );
		}
		return true;
	}

	/**
	 * Best-effort drift report. The live source is deliberately never frozen
	 * or blocked, so tables that keep writing during the export (options,
	 * sessions, Action Scheduler) legitimately drift from the imported
	 * snapshot. Chunk-level row-count validation during import already covers
	 * transfer integrity; this step only reports which tables kept moving, and
	 * never fails or delays the sync because the live site stayed busy.
	 */
	private static function step_validate_db( &$job ) {
		if ( empty( $job['imported'] ) ) {
			return array(
				'message' => 'No imported tables require a snapshot comparison.',
				'skipped' => true,
			);
		}
		$tables   = array_values( array_unique( (array) $job['imported'] ) );
		$delta    = isset( $job['delta_imported'] ) && is_array( $job['delta_imported'] ) ? $job['delta_imported'] : array();
		$compare  = array_values( array_diff( $tables, $delta ) );
		if ( empty( $compare ) ) {
			return array(
				'message' => sprintf( 'Imported %d table(s) via row delta; live checksum comparison skipped for those tables (shadows mix prior sandbox rows with fresh live rows).', count( $tables ) ),
			);
		}
		$response = Client::post_json( 'checksums', array( 'tables' => $compare ) );
		if ( is_wp_error( $response ) || ! isset( $response['checksums'] ) || ! is_array( $response['checksums'] ) ) {
			$reason = is_wp_error( $response ) ? $response->get_error_message() : 'malformed checksum response';
			return array( 'message' => sprintf( 'Imported %d table(s); the live-drift comparison was unavailable (%s). Transfer integrity was already enforced per chunk during import.', count( $tables ), $reason ) );
		}

		$source_prefix = $job['manifest']['site']['prefix'];
		$unchanged     = 0;
		$drifted       = array();
		$unverifiable  = array();
		foreach ( $compare as $source_table ) {
			$checkpoint = self::checkpoint( $job );
			if ( is_wp_error( $checkpoint ) ) {
				return $checkpoint;
			}
			$source_checksum = $response['checksums'][ $source_table ] ?? null;
			if ( null === $source_checksum ) {
				$unverifiable[] = $source_table;
				continue;
			}
			$target = self::target_table_name( $source_prefix, $source_table );
			$shadow = self::working_table_name( 'shadow', $job['id'], $target );
			$local  = DB_Exporter::table_checksum( $shadow );
			if ( null !== $local && (string) $local === (string) $source_checksum ) {
				++$unchanged;
			} else {
				$drifted[] = $source_table;
			}
		}

		$message = sprintf( 'Snapshot comparison: %d of %d imported table(s) still match the live database exactly.', $unchanged, count( $compare ) );
		if ( ! empty( $drifted ) ) {
			$message .= sprintf( ' %d table(s) kept changing on the live site during the export (expected — the live site is never paused): %s. The sandbox holds each table as of its own export moment.', count( $drifted ), implode( ', ', $drifted ) );
		}
		if ( ! empty( $unverifiable ) ) {
			$message .= sprintf( ' %d table(s) do not support checksums and were not compared: %s.', count( $unverifiable ), implode( ', ', $unverifiable ) );
		}
		if ( ! empty( $delta ) ) {
			$message .= sprintf( ' %d table(s) used row delta and were not checksum-compared: %s.', count( $delta ), implode( ', ', $delta ) );
		}
		return array( 'message' => $message );
	}

	private static function step_search_replace( &$job ) {
		if ( empty( $job['imported'] ) ) {
			return array(
				'message' => 'No tables were imported; search-replace skipped.',
				'skipped' => true,
			);
		}

		$pairs = self::build_replacement_pairs( $job['manifest']['site'] );
		if ( empty( $pairs ) ) {
			return array(
				'message' => 'Source and sandbox URLs are identical; search-replace skipped.',
				'skipped' => true,
			);
		}

		$source_prefix = $job['manifest']['site']['prefix'];
		// Per-table progress persists in the job so the step can yield at the
		// slice deadline (and survive worker kills) without redoing tables.
		$done_tables       = isset( $job['sr_done'] ) && is_array( $job['sr_done'] ) ? $job['sr_done'] : array();
		$total_updated     = isset( $job['sr_updated'] ) ? (int) $job['sr_updated'] : 0;
		$total_overflow    = isset( $job['sr_skipped_overflow'] ) ? (int) $job['sr_skipped_overflow'] : 0;
		foreach ( $job['imported'] as $source_table ) {
			if ( in_array( $source_table, $done_tables, true ) ) {
				continue;
			}
			if ( ! empty( $job['delta_imported'] ) && in_array( $source_table, (array) $job['delta_imported'], true ) ) {
				$done_tables[]  = $source_table;
				$job['sr_done'] = $done_tables;
				Job::save( $job );
				continue;
			}
			$checkpoint = self::checkpoint( $job, true, Job::CHUNK_LOCK_TTL );
			if ( is_wp_error( $checkpoint ) ) {
				return $checkpoint;
			}
			$shadow  = self::working_table_name( 'shadow', $job['id'], self::target_table_name( $source_prefix, $source_table ) );
			$suffix  = 0 === strpos( $source_table, $source_prefix ) ? substr( $source_table, strlen( $source_prefix ) ) : $source_table;
			$skip    = 'posts' === $suffix ? array( 'guid' ) : array();
			$result  = Search_Replace::run_on_table( $shadow, $pairs, $skip );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$updated           = (int) ( $result['updated'] ?? 0 );
			$overflow          = (int) ( $result['skipped_overflow'] ?? 0 );
			$total_updated    += $updated;
			$total_overflow   += $overflow;
			$done_tables[]     = $source_table;
			$job['sr_done']    = $done_tables;
			$job['sr_updated'] = $total_updated;
			$job['sr_skipped_overflow'] = $total_overflow;
			if ( $overflow > 0 ) {
				Job::log(
					$job,
					sprintf(
						'Search-replace left %d value(s) in %s unchanged: the rewritten URL would exceed the column length (common for Yoast link rows when the sandbox hostname is longer than live).',
						$overflow,
						$source_table
					)
				);
			}
			Job::save( $job );
			if ( count( $done_tables ) < count( $job['imported'] ) && self::slice_deadline_reached() ) {
				return array(
					'yield'   => true,
					'message' => sprintf( 'Rewriting URLs: %d of %d tables done, %d rows updated…', count( $done_tables ), count( $job['imported'] ), $total_updated ),
				);
			}
		}

		$described = array();
		foreach ( $pairs as $from => $to ) {
			$described[] = "{$from} => {$to}";
		}
		$message = sprintf( 'Search-replace done: %d rows updated across %d tables (%s).', $total_updated, count( $job['imported'] ), implode( ', ', $described ) );
		if ( $total_overflow > 0 ) {
			$message .= sprintf( ' %d value(s) left unchanged because the rewritten URL exceeded the column length.', $total_overflow );
		}
		return array(
			'message' => $message,
		);
	}

	private static function step_swap( &$job ) {
		global $wpdb;

		$source_prefix = $job['manifest']['site']['prefix'];
		if ( ! empty( $job['db_swap']['state'] ) && in_array( $job['db_swap']['state'], array( 'active', 'activating' ), true ) ) {
			if ( self::database_swap_looks_active( $job['db_swap']['mapping'] ) ) {
				$job['db_swap']['state'] = 'active';
				Job::save( $job );
				return array( 'message' => 'Database swap was already activated by a previous worker attempt; rollback tables remain protected.' );
			}
		}

		$prepared = isset( $job['prepared_tables'] ) && is_array( $job['prepared_tables'] )
			? $job['prepared_tables']
			: (array) $job['imported'];
		if ( empty( $prepared ) ) {
			return array(
				'message' => 'Database already up to date — nothing to swap.',
				'skipped' => true,
			);
		}
		$checkpoint = self::checkpoint( $job );
		if ( is_wp_error( $checkpoint ) ) {
			return $checkpoint;
		}

		// Verify every shadow table exists before touching anything.
		$mapping = array();
		foreach ( $prepared as $source_table ) {
			$target = self::target_table_name( $source_prefix, $source_table );
			$shadow = self::working_table_name( 'shadow', $job['id'], $target );
			if ( ! self::table_exists( $shadow ) ) {
				return new \WP_Error( 'missing_shadow', "Shadow table {$shadow} is missing." );
			}
			$mapping[ $source_table ] = array(
				'shadow'     => $shadow,
				'target'     => $target,
				'backup'     => self::working_table_name( 'backup', $job['id'], $target ),
				'had_target' => self::table_exists( $target ),
			);
		}
		$working_tables = array();
		foreach ( $mapping as $names ) {
			$working_tables[] = $names['shadow'];
			$working_tables[] = $names['backup'];
		}
		self::register_working_tables( $job, $working_tables );
		Job::save( $job );

		$preserved = self::preserve_sandbox_identity( $job, $mapping );
		if ( is_wp_error( $preserved ) ) {
			return $preserved;
		}
		$checkpoint = self::checkpoint( $job );
		if ( is_wp_error( $checkpoint ) ) {
			return $checkpoint;
		}

		$renames = array();
		foreach ( $mapping as $names ) {
			if ( false === $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $names['backup'] ) ) ) {
				return new \WP_Error( 'backup_reset', "Could not reset rollback table {$names['backup']}: " . $wpdb->last_error );
			}
			if ( ! empty( $names['had_target'] ) ) {
				$renames[] = $wpdb->prepare( '%i TO %i', $names['target'], $names['backup'] );
			}
			$renames[] = $wpdb->prepare( '%i TO %i', $names['shadow'], $names['target'] );
		}
		$job['db_swap'] = array(
			'state'   => 'activating',
			'mapping' => $mapping,
		);
		Job::save( $job );
		$checkpoint = self::checkpoint( $job );
		if ( is_wp_error( $checkpoint ) ) {
			return $checkpoint;
		}

		if ( false === $wpdb->query( 'RENAME TABLE ' . implode( ', ', $renames ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Every rename fragment is prepared with identifier placeholders.
			$job['db_swap'] = null;
			Job::save( $job );
			return new \WP_Error( 'rename_failed', 'Atomic table swap failed: ' . $wpdb->last_error );
		}
		$job['db_swap']['state'] = 'active';
		Job::save( $job );

		wp_cache_flush();

		// Record checksums so the next incremental run can skip unchanged
		// tables: the source checksum from the manifest, and our local one
		// computed on the freshly swapped-in table.
		$state              = get_option( self::OPT_TABLE_STATE );
		$state              = is_array( $state ) ? $state : array();
		$manifest_checksums = array();
		foreach ( (array) $job['manifest']['tables'] as $table ) {
			$manifest_checksums[ $table['name'] ] = isset( $table['checksum'] ) ? $table['checksum'] : null;
		}
		foreach ( $mapping as $source_table => $names ) {
			$suffix = 0 === strpos( $source_table, $source_prefix ) ? substr( $source_table, strlen( $source_prefix ) ) : $source_table;
			if ( in_array( strtolower( $suffix ), (array) ( $job['options']['schema_only_tables'] ?? array() ), true ) ) {
				unset( $state[ $names['target'] ] );
			} else {
				$state[ $names['target'] ] = array(
					'source_checksum' => $manifest_checksums[ $source_table ],
					'local_checksum'  => DB_Exporter::table_checksum( $names['target'] ),
					'synced_at'       => time(),
				);
			}
		}
		update_option( self::OPT_TABLE_STATE, $state, false );

		$schema_only_count = count( array_diff( $prepared, (array) $job['imported'] ) );
		return array(
			'message' => sprintf( 'Database swapped: %d tables replaced (%d schema-only emptied); rollback tables retained until finalization.', count( $mapping ), $schema_only_count ),
		);
	}

	/**
	 * Activates the database under one early-load request guard. The guard remains
	 * through deferred file activation and the later safety step, and is removed
	 * by finalization only after the complete snapshot is ready.
	 */
	private static function step_activate( &$job ) {
		$guarded = Safety::begin_activation_guard( $job['id'] );
		if ( is_wp_error( $guarded ) ) {
			return $guarded;
		}
		$job['activation_guard_active'] = true;
		Job::save( $job );
		$swapped = self::step_swap( $job );
		if ( is_wp_error( $swapped ) ) {
			return $swapped;
		}
		return array( 'message' => $swapped['message'] . ' Traffic remains guarded until deferred file activation and sandbox safety complete.' );
	}

	private static function database_swap_looks_active( $mapping ) {
		if ( empty( $mapping ) ) {
			return false;
		}
		foreach ( $mapping as $names ) {
			if ( self::table_exists( $names['shadow'] ) || ! self::table_exists( $names['target'] ) ) {
				return false;
			}
			if ( ! empty( $names['had_target'] ) && ! self::table_exists( $names['backup'] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Atomically restores all pre-sync tables while moving the imported tables
	 * back into their job-owned shadow names for exact cleanup.
	 *
	 * @return int|\WP_Error Restored table count.
	 */
	private static function rollback_database( &$job ) {
		global $wpdb;
		if ( empty( $job['db_swap']['mapping'] ) ) {
			return 0;
		}
		// Failure/cancel already restored targets and dropped shadows; cleanup
		// must not re-interpret that layout as an ambiguous mid-swap.
		if ( isset( $job['db_swap']['state'] ) && in_array( $job['db_swap']['state'], array( 'rolled_back', 'finalized' ), true ) ) {
			return 0;
		}
		$mapping = $job['db_swap']['mapping'];
		if ( ! self::database_swap_looks_active( $mapping ) ) {
			// A prepared-but-not-executed atomic rename needs no rollback.
			if ( isset( $job['db_swap']['state'] ) && 'activating' === $job['db_swap']['state'] ) {
				$all_shadows = true;
				foreach ( $mapping as $names ) {
					$all_shadows = $all_shadows && self::table_exists( $names['shadow'] );
				}
				if ( $all_shadows ) {
					$job['db_swap'] = null;
					return 0;
				}
			}
			return new \WP_Error( 'rollback_state', 'Database activation state is ambiguous; working tables were retained for manual recovery.' );
		}

		$renames = array();
		foreach ( $mapping as $names ) {
			$renames[] = $wpdb->prepare( '%i TO %i', $names['target'], $names['shadow'] );
			if ( ! empty( $names['had_target'] ) ) {
				$renames[] = $wpdb->prepare( '%i TO %i', $names['backup'], $names['target'] );
			}
		}
		if ( false === $wpdb->query( 'RENAME TABLE ' . implode( ', ', $renames ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Every rename fragment is prepared with identifier placeholders.
			return new \WP_Error( 'rollback_failed', 'Atomic database rollback failed: ' . $wpdb->last_error );
		}
		$job['db_swap']['state'] = 'rolled_back';
		wp_cache_flush();
		Job::save( $job );
		return count( $mapping );
	}

	/** @return int|\WP_Error */
	private static function finalize_database_swap( &$job ) {
		global $wpdb;
		if ( empty( $job['db_swap']['mapping'] ) ) {
			return 0;
		}
		if ( 'finalized' === $job['db_swap']['state'] ) {
			return 0;
		}
		$backups = array();
		foreach ( $job['db_swap']['mapping'] as $names ) {
			if ( ! empty( $names['had_target'] ) ) {
				$backups[] = $names['backup'];
			}
		}
		$present = array_values( array_filter( $backups, array( __CLASS__, 'table_exists' ) ) );
		if ( 'active' === $job['db_swap']['state'] && empty( $present ) && ! empty( $backups ) ) {
			// Previous attempt completed the one-statement drop but died before
			// persisting the finalized state.
			$job['db_swap']['state'] = 'finalized';
			Job::save( $job );
			return 0;
		}
		if ( 'active' !== $job['db_swap']['state'] || count( $present ) !== count( $backups ) || ! self::database_swap_looks_active( $job['db_swap']['mapping'] ) ) {
			return new \WP_Error( 'finalize_state', 'Database rollback state is not safe to finalize.' );
		}
		if ( ! empty( $backups ) ) {
			$quoted = array_map(
				function ( $name ) use ( $wpdb ) {
					return $wpdb->prepare( '%i', $name );
				},
				$backups
			);
			if ( false === $wpdb->query( 'DROP TABLE ' . implode( ', ', $quoted ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Every identifier is prepared above.
				return new \WP_Error( 'finalize_drop', 'Could not remove database rollback tables: ' . $wpdb->last_error );
			}
		}
		$job['db_swap']['state'] = 'finalized';
		Job::save( $job );
		return count( $backups );
	}


	private static function build_replacement_pairs( $source_site ) {
		$source_home    = untrailingslashit( (string) $source_site['home'] );
		$local_home     = untrailingslashit( home_url() );
		$source_siteurl = untrailingslashit( isset( $source_site['siteurl'] ) ? (string) $source_site['siteurl'] : $source_home );
		$local_siteurl  = untrailingslashit( (string) get_option( 'siteurl', $local_home ) );

		$pairs = array();
		foreach ( array(
			$source_home    => $local_home,
			$source_siteurl => $local_siteurl,
		) as $from => $to ) {
			if ( $from && $from !== $to ) {
				$pairs[ $from ] = $to;
				if ( 0 === strpos( $from, 'https://' ) ) {
					$pairs[ 'http://' . substr( $from, 8 ) ] = $to;
				} elseif ( 0 === strpos( $from, 'http://' ) ) {
					$pairs[ 'https://' . substr( $from, 7 ) ] = $to;
				}
			}
		}

		// Bare-host rewriting changes email addresses and prose. Keep it opt-in
		// for legacy workflows that intentionally relied on that behavior.
		if ( apply_filters( 'sandbox_sync_replace_bare_host', false, $source_site ) ) {
			$source_host = wp_parse_url( $source_home, PHP_URL_HOST );
			$local_host  = wp_parse_url( $local_home, PHP_URL_HOST );
			if ( $source_host && $local_host && $source_host !== $local_host ) {
				$pairs[ $source_host ] = $local_host;
			}
		}

		uksort(
			$pairs,
			function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);

		return $pairs;
	}

	/**
	 * Kept plugins whose activation the swap carries. A sync that planned
	 * plugins/ decided there what it keeps. One that leaves plugins/ alone
	 * worked it out from live's reported layout when it refreshed the manifest,
	 * so both kinds of sync give the same answer.
	 *
	 * @param array $job Job.
	 * @return string[] Top-level plugin entries.
	 */
	private static function kept_plugins_for_activation( $job ) {
		foreach ( array( 'kept_plugins', 'activation_kept' ) as $key ) {
			if ( isset( $job[ $key ] ) && is_array( $job[ $key ] ) ) {
				return $job[ $key ];
			}
		}
		return array();
	}

	/**
	 * Appends the sandbox's kept, active plugins to the activation list in the
	 * shadow options table, before that table replaces the sandbox's own.
	 *
	 * @param array  $job            Job; logged to when an activation is carried.
	 * @param string $shadow_options Shadow options table.
	 * @return true|\WP_Error
	 */
	private static function carry_kept_plugin_activation( &$job, $shadow_options ) {
		global $wpdb;
		$kept = self::kept_plugins_for_activation( $job );
		if ( empty( $kept ) ) {
			return true;
		}
		// Read both rows as stored: option filters and caches describe the
		// running request, not what the swap replaces.
		$local_raw  = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'active_plugins' ) );
		$shadow_raw = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $shadow_options, 'active_plugins' ) );
		$imported   = null === $shadow_raw ? array() : maybe_unserialize( $shadow_raw );
		$merged     = self::carry_kept_active_plugins( $imported, null === $local_raw ? array() : maybe_unserialize( $local_raw ), $kept );
		$carried    = array();
		foreach ( $merged as $plugin ) {
			if ( ! is_array( $imported ) || ! in_array( $plugin, $imported, true ) ) {
				$carried[] = $plugin;
			}
		}
		if ( empty( $carried ) ) {
			return true;
		}
		$written = null === $shadow_raw
			? $wpdb->query( $wpdb->prepare( "INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'yes')", $shadow_options, 'active_plugins', maybe_serialize( $merged ) ) )
			: $wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s', $shadow_options, maybe_serialize( $merged ), 'active_plugins' ) );
		if ( false === $written ) {
			return new \WP_Error( 'active_plugins_carry', 'Failed keeping sandbox-only plugins active across the swap: ' . $wpdb->last_error );
		}
		Job::log( $job, sprintf( 'Kept %d sandbox-only plugin(s) active across the database swap: %s.', count( $carried ), implode( ', ', $carried ) ) );
		return true;
	}

	/**
	 * Writes into the shadow tables everything the sandbox must keep across
	 * the swap: its connection/flag/job options and the job creator's
	 * credentials + session.
	 *
	 * @return true|\WP_Error
	 */
	private static function preserve_sandbox_identity( &$job, $mapping ) {
		global $wpdb;
		$checkpoint = self::checkpoint( $job );
		if ( is_wp_error( $checkpoint ) ) {
			return $checkpoint;
		}

		$source_prefix  = $job['manifest']['site']['prefix'];
		$shadow_options = self::working_table_name( 'shadow', $job['id'], self::target_table_name( $source_prefix, $source_prefix . 'options' ) );
		$local_prefix   = $wpdb->prefix;
		$has_options    = isset( $mapping[ $source_prefix . 'options' ] ) && self::table_exists( $shadow_options );

		if ( $has_options ) {
			// 1. Options: strip live's source-role options, keep sandbox's own.
			$names        = array_merge( Auth::preserved_option_names(), Auth::source_role_option_names() );
			$placeholders = implode( ', ', array_fill( 0, count( $names ), '%s' ) );
			if ( false === $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE option_name IN ({$placeholders})", array_merge( array( $shadow_options ), $names ) ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholder list is generated from fixed option names.
				return new \WP_Error( 'option_preserve', 'Failed clearing role/identity options from the shadow table: ' . $wpdb->last_error );
			}

			foreach ( Auth::preserved_option_names() as $name ) {
				$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
				if ( null === $raw ) {
					continue;
				}
				$inserted = $wpdb->query(
					$wpdb->prepare(
						"INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
						$shadow_options,
						$name,
						$raw
					)
				);
				if ( false === $inserted ) {
					return new \WP_Error( 'option_preserve', "Failed preserving sandbox option {$name}: " . $wpdb->last_error );
				}
			}

			// Plugins only the sandbox has stay active: their activation lives
			// in a row that live's copy is about to replace.
			$carried = self::carry_kept_plugin_activation( $job, $shadow_options );
			if ( is_wp_error( $carried ) ) {
				return $carried;
			}

			// Prefix-bearing option/meta keys are data, not table identifiers. Map
			// them explicitly when source and sandbox use different prefixes.
			if ( $source_prefix !== $local_prefix ) {
				$source_roles = $source_prefix . 'user_roles';
				$local_roles  = $local_prefix . 'user_roles';
				$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s', $shadow_options, $local_roles ) );
				if ( false === $wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_name = %s WHERE option_name = %s', $shadow_options, $local_roles, $source_roles ) ) ) {
					return new \WP_Error( 'prefix_map', 'Failed mapping user-role option prefix: ' . $wpdb->last_error );
				}
			}
		}
		$checkpoint = self::checkpoint( $job );
		if ( is_wp_error( $checkpoint ) ) {
			return $checkpoint;
		}

		// 2. Job creator's user: keep password + session valid post-swap.
		$user_id = (int) $job['user_id'];
		if ( ! $user_id ) {
			return true; // CLI without --user: nothing to preserve.
		}

		$shadow_users    = self::working_table_name( 'shadow', $job['id'], self::target_table_name( $source_prefix, $source_prefix . 'users' ) );
		$shadow_usermeta = self::working_table_name( 'shadow', $job['id'], self::target_table_name( $source_prefix, $source_prefix . 'usermeta' ) );
		if ( ! self::table_exists( $shadow_users ) || ! self::table_exists( $shadow_usermeta ) ) {
			return true;
		}

		$user_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->users} WHERE ID = %d", $user_id ), ARRAY_A );
		if ( ! $user_row ) {
			return true;
		}

		$target_id = $wpdb->get_var( $wpdb->prepare( 'SELECT ID FROM %i WHERE user_login = %s', $shadow_users, $user_row['user_login'] ) );

		if ( $target_id ) {
			$updated = $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET user_pass = %s, user_email = %s, user_nicename = %s, display_name = %s, user_url = %s WHERE ID = %d',
					$shadow_users,
					$user_row['user_pass'],
					$user_row['user_email'],
					$user_row['user_nicename'],
					$user_row['display_name'],
					$user_row['user_url'],
					$target_id
				)
			);
			if ( false === $updated ) {
				return new \WP_Error( 'user_copy', 'Failed preserving the sandbox administrator identity: ' . $wpdb->last_error );
			}
		} else {
			$insert_row = $user_row;
			unset( $insert_row['ID'] );
			if ( false === $wpdb->insert( $shadow_users, $insert_row ) ) {
				return new \WP_Error( 'user_copy', 'Failed copying the admin user into the synced database: ' . $wpdb->last_error );
			}
			$target_id = $wpdb->insert_id;

		}
		$job['preserved_user_id'] = (int) $target_id;

		if ( $source_prefix !== $local_prefix ) {
			foreach ( array( 'capabilities', 'user_level' ) as $suffix ) {
				$from = $source_prefix . $suffix;
				$to   = $local_prefix . $suffix;
				$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE meta_key = %s', $shadow_usermeta, $to ) );
				if ( false === $wpdb->query( $wpdb->prepare( 'UPDATE %i SET meta_key = %s WHERE meta_key = %s', $shadow_usermeta, $to, $from ) ) ) {
					return new \WP_Error( 'prefix_map', "Failed mapping usermeta key {$from}: " . $wpdb->last_error );
				}
			}
		}

		// Always preserve the initiating administrator's local capabilities,
		// whether their login already existed on the source or was copied now.
		foreach ( array( $local_prefix . 'capabilities', $local_prefix . 'user_level' ) as $meta_key ) {
			$checkpoint = self::checkpoint( $job );
			if ( is_wp_error( $checkpoint ) ) {
				return $checkpoint;
			}
			$meta_value = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s",
					$user_id,
					$meta_key
				)
			);
			if ( null !== $meta_value ) {
				$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE user_id = %d AND meta_key = %s', $shadow_usermeta, $target_id, $meta_key ) );
				if ( false === $wpdb->insert(
					$shadow_usermeta,
					array(
						'user_id'    => $target_id,
						'meta_key'   => $meta_key,
						'meta_value' => $meta_value,
					)
				) ) {
					return new \WP_Error( 'user_copy', "Failed preserving administrator metadata {$meta_key}: " . $wpdb->last_error );
				}
			}
		}

		// 3. Session tokens: keep the creator's browser session alive.
		// Imported production sessions and application passwords must never be
		// valid on the sandbox. Only the initiating sandbox session is restored.
		if ( false === $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE meta_key IN ('session_tokens', '_application_passwords')", $shadow_usermeta ) ) ) {
			return new \WP_Error( 'session_copy', 'Failed invalidating imported production sessions: ' . $wpdb->last_error );
		}
		$sessions = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = 'session_tokens'",
				$user_id
			)
		);
		if ( null !== $sessions ) {
			if ( false === $wpdb->insert(
				$shadow_usermeta,
				array(
					'user_id'    => $target_id,
					'meta_key'   => 'session_tokens',
					'meta_value' => $sessions,
				)
			) ) {
				return new \WP_Error( 'session_copy', 'Failed preserving administrator session tokens: ' . $wpdb->last_error );
			}
		}

		return true;
	}

	// -- Helpers -----------------------------------------------------------------------

	/**
	 * Fails closed on database/runtime incompatibility.
	 *
	 * Database free space is deliberately not gated here. Managed database
	 * services do not expose their host filesystem to PHP, and a guessed value
	 * is not a trustworthy preflight signal. Imports remain isolated in shadow
	 * tables until validated activation, so capacity exhaustion fails before the
	 * active sandbox tables are replaced. Filesystem staging retains its separate
	 * measurable capacity checks.
	 */
	private static function database_preflight( $manifest, $options ) {
		global $wpdb, $wp_version;

		$report = array(
			'source_php'       => isset( $manifest['site']['php_version'] ) ? $manifest['site']['php_version'] : '',
			'target_php'       => PHP_VERSION,
			'source_wordpress' => isset( $manifest['site']['wordpress_version'] ) ? $manifest['site']['wordpress_version'] : '',
			'target_wordpress' => isset( $wp_version ) ? $wp_version : '',
		);
		if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
			return new \WP_Error( 'php_version', 'Sandbox Sync requires PHP 7.4 or newer on the sandbox.' );
		}
		if ( ! empty( $report['source_php'] ) && version_compare( $report['source_php'], '7.4', '<' ) ) {
			return new \WP_Error( 'source_php_version', 'Sandbox Sync requires PHP 7.4 or newer on the source.' );
		}
		if ( empty( $options['db'] ) ) {
			return $report;
		}

		$source_db = isset( $manifest['site']['db_version'] ) ? (string) $manifest['site']['db_version'] : '';
		$target_db = (string) $wpdb->get_var( 'SELECT VERSION()' );
		if ( ! self::supported_database_version( $source_db ) || ! self::supported_database_version( $target_db ) ) {
			return new \WP_Error( 'db_version', sprintf( 'Unsupported database version (source: %s, sandbox: %s). Supported baselines are MySQL 5.7+ and MariaDB 10.3+.', '' !== $source_db ? $source_db : 'unknown', '' !== $target_db ? $target_db : 'unknown' ) );
		}
		$report['source_db'] = $source_db;
		$report['target_db'] = $target_db;

		$engines = $wpdb->get_results( 'SHOW ENGINES', ARRAY_A );
		$innodb  = false;
		foreach ( (array) $engines as $engine ) {
			if ( 'INNODB' === strtoupper( (string) ( $engine['Engine'] ?? '' ) ) && 'NO' !== strtoupper( (string) ( $engine['Support'] ?? '' ) ) ) {
				$innodb = true;
				break;
			}
		}
		if ( ! $innodb ) {
			return new \WP_Error( 'innodb_unavailable', 'The sandbox database does not provide InnoDB, which is required for validated atomic activation.' );
		}

		$available_collations = self::available_database_collations(
			$wpdb->get_results( 'SHOW COLLATION', ARRAY_A ),
			$wpdb->get_results( 'SELECT * FROM information_schema.COLLATION_CHARACTER_SET_APPLICABILITY', ARRAY_A )
		);
		$source_prefix = isset( $manifest['site']['prefix'] ) ? (string) $manifest['site']['prefix'] : '';
		$selected_size = 0;
		$selected      = 0;
		$missing       = array();
		foreach ( (array) $manifest['tables'] as $table ) {
			$suffix = 0 === strpos( $table['name'], $source_prefix ) ? substr( $table['name'], strlen( $source_prefix ) ) : $table['name'];
			if ( in_array( strtolower( $suffix ), (array) $options['excluded_tables'], true ) ) {
				continue;
			}
			++$selected;
			$selected_size += max( 0, (int) ( $table['size'] ?? 0 ) );
			$collation      = strtolower( (string) ( $table['collation'] ?? '' ) );
			if ( '' !== $collation && ! isset( $available_collations[ $collation ] ) ) {
				$missing[] = $table['name'] . ':' . $collation;
			}
		}
		if ( $missing ) {
			return new \WP_Error( 'collation_unavailable', 'Sandbox database lacks source collations required by: ' . implode( ', ', $missing ) );
		}
		$max_tables = max( 1, (int) apply_filters( 'sandbox_sync_max_atomic_tables', 1000 ) );
		if ( $selected > $max_tables ) {
			return new \WP_Error( 'table_count', sprintf( 'The %d-table atomic rename exceeds the configured %d-table safety limit.', $selected, $max_tables ) );
		}

		$source_packet = (int) ( $manifest['site']['max_allowed_packet'] ?? 0 );
		$target_packet = (int) $wpdb->get_var( 'SELECT @@max_allowed_packet' );
		if ( $source_packet < 1048576 || $target_packet < 1048576 ) {
			return new \WP_Error( 'max_allowed_packet', sprintf( 'max_allowed_packet must be at least 1 MiB (source: %d, sandbox: %d bytes).', $source_packet, $target_packet ) );
		}
		$report['source_max_allowed_packet'] = $source_packet;
		$report['target_max_allowed_packet'] = $target_packet;
		$report['statement_limit']           = self::database_statement_limit( $target_packet );

		$memory = self::ini_bytes( ini_get( 'memory_limit' ) );
		if ( $memory > 0 && $memory < 33554432 ) {
			return new \WP_Error( 'memory_limit', sprintf( 'PHP memory_limit is %.1f MiB; at least 32 MiB is required.', $memory / 1048576 ) );
		}
		$report['memory_limit'] = $memory;

		$report['selected_tables']   = $selected;
		$report['selected_db_bytes'] = $selected_size;
		return $report;
	}

	/**
	 * Index the effective collation names accepted by the target database.
	 *
	 * MariaDB's charset-independent UCA collations appear in SHOW COLLATION as
	 * names such as `uca1400_ai_ci`, while table metadata uses the effective
	 * `utf8mb3_uca1400_ai_ci` form. FULL_COLLATION_NAME is the authoritative
	 * mapping for that form. MySQL does not expose that column, so retain both
	 * the SHOW output and COLLATION_NAME as portable fallbacks.
	 *
	 * @param array $show_rows          Rows returned by SHOW COLLATION.
	 * @param array $applicability_rows Rows from COLLATION_CHARACTER_SET_APPLICABILITY.
	 * @return array<string,bool> Lowercase collation names keyed to true.
	 */
	private static function available_database_collations( $show_rows, $applicability_rows ) {
		$available = array();
		foreach ( (array) $show_rows as $row ) {
			$name = isset( $row['Collation'] ) ? trim( (string) $row['Collation'] ) : '';
			if ( '' !== $name ) {
				$available[ strtolower( $name ) ] = true;
			}
		}

		foreach ( (array) $applicability_rows as $row ) {
			foreach ( array( 'FULL_COLLATION_NAME', 'COLLATION_NAME' ) as $column ) {
				$name = isset( $row[ $column ] ) ? trim( (string) $row[ $column ] ) : '';
				if ( '' !== $name ) {
					$available[ strtolower( $name ) ] = true;
				}
			}
		}

		return $available;
	}

	private static function supported_database_version( $version ) {
		if ( ! preg_match( '/(\d+\.\d+(?:\.\d+)?)/', (string) $version, $match ) ) {
			return false;
		}
		$is_mariadb = false !== stripos( (string) $version, 'mariadb' );
		return version_compare( $match[1], $is_mariadb ? '10.3' : '5.7', '>=' );
	}

	private static function database_statement_limit( $packet = null ) {
		global $wpdb;
		$packet = null === $packet ? (int) $wpdb->get_var( 'SELECT @@max_allowed_packet' ) : (int) $packet;
		$memory = self::ini_bytes( ini_get( 'memory_limit' ) );
		$limits = array( DB_Exporter::HARD_MAX_STATEMENT_BYTES, max( 65536, (int) floor( $packet * 0.8 ) ) );
		if ( $memory > 0 ) {
			$limits[] = max( 65536, (int) floor( $memory / 8 ) );
		}
		return max( 65536, min( $limits ) );
	}

	private static function ini_bytes( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value || '-1' === $value ) {
			return -1;
		}
		$number = (float) $value;
		switch ( strtolower( substr( $value, -1 ) ) ) {
			case 'g':
				$number *= 1024;
				// Fall through.
			case 'm':
				$number *= 1024;
				// Fall through.
			case 'k':
				$number *= 1024;
		}
		return (int) $number;
	}

	private static function target_table_name( $source_prefix, $source_table ) {
		global $wpdb;
		$suffix = ( '' !== $source_prefix && 0 === strpos( $source_table, $source_prefix ) )
			? substr( $source_table, strlen( $source_prefix ) )
			: $source_table;
		return $wpdb->prefix . $suffix;
	}

	private static function table_exists( $table ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	/**
	 * Deterministic, length-safe job-owned working table name.
	 */
	public static function working_table_name( $kind, $job_id, $target ) {
		$prefix = 'backup' === $kind ? self::BACKUP_PREFIX : self::SHADOW_PREFIX;
		$job    = substr( preg_replace( '/[^a-z0-9]/i', '', (string) $job_id ), 0, 12 );
		$job    = '' !== $job ? strtolower( $job ) : 'unknown';
		$hash   = substr( hash( 'sha256', (string) $target ), 0, 10 );
		$room   = 64 - strlen( $prefix ) - strlen( $job ) - strlen( $hash ) - 2;
		$name   = substr( preg_replace( '/[^A-Za-z0-9_]/', '_', (string) $target ), 0, max( 1, $room ) );
		return $prefix . $job . '_' . $name . '_' . $hash;
	}

	private static function register_working_tables( &$job, $tables ) {
		if ( ! isset( $job['working_tables'] ) || ! is_array( $job['working_tables'] ) ) {
			$job['working_tables'] = array();
		}
		foreach ( (array) $tables as $table ) {
			if ( ! in_array( $table, $job['working_tables'], true ) ) {
				$job['working_tables'][] = $table;
			}
		}
	}

	/**
	 * Drops only the exact tables registered to this job. Never prefix-scans
	 * the database, because that can destroy unrelated plugin or tenant tables.
	 *
	 * @param array|null $job Job owning the working resources.
	 */
	public static function drop_working_tables( $job = null ) {
		global $wpdb;
		if ( null === $job ) {
			$job = Job::get();
		}
		if ( ! is_array( $job ) || empty( $job['working_tables'] ) ) {
			return 0;
		}
		$dropped = 0;
		foreach ( array_unique( (array) $job['working_tables'] ) as $table ) {
			if ( ! is_string( $table ) || ! preg_match( '/^(ssx_|ssxbak_)[A-Za-z0-9_]+$/', $table ) ) {
				continue;
			}
			if ( self::table_exists( $table ) && false !== $wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $table ) ) ) {
				++$dropped;
			}
		}
		return $dropped;
	}
}
