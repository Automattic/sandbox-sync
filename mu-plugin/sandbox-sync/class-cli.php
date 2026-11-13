<?php
/**
 * WP-CLI commands mirroring the admin screen — handy for scripted testing.
 *
 *   wp sandbox-sync generate-key                 (on the live site)
 *   wp sandbox-sync connect <url> <pairing-key>  (on the sandbox)
 *   wp sandbox-sync mark-sandbox [--off]         (on the sandbox; guardrail flag)
 *   wp sandbox-sync run [--user=<login>] [--skip-uploads] [--db-only]
 *                       [--files-only] [--full] [--exclude-tables=<a,b>]
 *                       [--schema-only-tables=<a,b>]
 *   wp sandbox-sync status | cancel | disconnect | revoke | cleanup
 *   wp sandbox-sync source-stop                     (on live; emergency stop)
 */

namespace SandboxSync;

defined( 'ABSPATH' ) || exit;

class CLI {

	/**
	 * Generates a one-time pairing key (run on the SOURCE/live site).
	 *
	 * @subcommand generate-key
	 */
	public function generate_key() {
		\WP_CLI::log( 'Pairing key (single use, shown once):' );
		\WP_CLI::log( Auth::generate_pairing_key() );
	}

	/**
	 * Connects this site (as sandbox) to a source site.
	 *
	 * ## OPTIONS
	 *
	 * <source-url>
	 * : The live site URL, e.g. https://live.local
	 *
	 * <pairing-key>
	 * : The pairing key generated on the live site.
	 */
	public function connect( $args ) {
		list( $source_url, $pairing_key ) = $args;
		$site                             = Client::handshake( $source_url, $pairing_key );
		if ( is_wp_error( $site ) ) {
			\WP_CLI::error( $site->get_error_message() );
		}
		\WP_CLI::success( sprintf( 'Connected to %s.', isset( $site['home'] ) ? $site['home'] : $source_url ) );
	}

	/**
	 * Marks (or unmarks) this site as a sandbox — required before any sync
	 * can overwrite it (direction guardrail).
	 *
	 * ## OPTIONS
	 *
	 * [--off]
	 * : Remove the flag (blocks future syncs into this site).
	 *
	 * @subcommand mark-sandbox
	 */
	public function mark_sandbox( $args, $assoc_args ) {
		$off = ! empty( $assoc_args['off'] );
		Auth::set_sandbox_flag( ! $off );
		\WP_CLI::success( $off ? 'Sandbox flag removed; syncs into this site are blocked.' : 'Site marked as a sandbox; syncs may overwrite it.' );
	}

	/**
	 * Runs a sync from the connected source site (run on the SANDBOX).
	 *
	 * Requires the sandbox flag (`wp sandbox-sync mark-sandbox`).
	 * Pass --user=<admin-login> so that admin's password/session survive the swap.
	 *
	 * ## OPTIONS
	 *
	 * [--db-only]
	 * : Sync only the database (no files).
	 *
	 * [--files-only]
	 * : Sync only files (no database).
	 *
	 * [--skip-uploads]
	 * : Do not sync the uploads directory.
	 *
	 * [--full]
	 * : Replace every selected table and directory, unchanged ones included.
	 *   Without this flag, tables and files that did not change are skipped.
	 *
	 * [--audit-uploads]
	 * : Metadata-scan every selected uploads partition. Unchanged media payloads
	 *   are still not hashed or transferred.
	 *
	 * [--adopt-uploads]
	 * : Never-synced uploads folders only: trust files already present in
	 *   uploads/ instead of clearing and re-copying them. Each such partition
	 *   is compared file-by-file against the live site (size + mtime, hashes
	 *   for candidates) and only missing or changed files travel. Partitions
	 *   with a committed sync record (each year commits as it finishes) are
	 *   unaffected and keep their cheap generation check. Use after an
	 *   interrupted copy that already moved the bulk of the media.
	 *
	 * [--exclude-tables=<tables>]
	 * : Comma-separated table suffixes (without prefix) to leave untouched.
	 *
	 * [--schema-only-tables=<tables>]
	 * : Comma-separated table suffixes (without prefix) to recreate empty from
	 *   the live schema. Defaults to disposable Newspack recovery tables.
	 *
	 * [--safety=<rules>]
	 * : Comma-separated safety rule keys to apply after the sync (see
	 *   `wp sandbox-sync safety-rules` for keys and what each changes).
	 *   Defaults to the recommended set: options/plugins/runtime guards and
	 *   wp-config safety constants — not users, posts, or comments.
	 *
	 * [--no-safety]
	 * : Apply no safety rules (also clears the email block / config
	 *   constants from previous syncs). Production credentials stay live!
	 *
	 * [--plugins=<tops>]
	 * : Comma-separated plugin directories (or single-file names) to sync;
	 *   others are neither downloaded nor deleted. Default: all.
	 *
	 * [--uploads=<scopes>]
	 * : Comma-separated uploads date scopes to sync: YYYY, YYYY/MM or
	 *   "other" (root files / non-dated folders). Out-of-scope uploads are
	 *   neither downloaded nor deleted. Default: everything.
	 *
	 * [--constants=<names>]
	 * : Comma-separated wp-config.php constants to replicate from the live
	 *   site (see `wp sandbox-sync source-constants`). Default: none.
	 *
	 * [--dry-run]
	 * : Fetch and report the source manifest without creating or mutating a job.
	 *
	 * [--yes]
	 * : Skip the destructive confirmation prompt.
	 */
	public function run( $args, $assoc_args ) {
		$options = Sync_Runner::default_options();

		if ( ! empty( $assoc_args['db-only'] ) ) {
			$options['dirs'] = array();
		}
		if ( ! empty( $assoc_args['files-only'] ) ) {
			$options['db'] = false;
		}
		if ( ! empty( $assoc_args['skip-uploads'] ) ) {
			$options['dirs'] = array_values( array_diff( $options['dirs'], array( 'uploads' ) ) );
		}
		if ( ! empty( $assoc_args['full'] ) ) {
			$options['incremental'] = false;
		}
		if ( ! empty( $assoc_args['audit-uploads'] ) ) {
			$options['uploads_full_audit'] = true;
		}
		if ( ! empty( $assoc_args['adopt-uploads'] ) ) {
			$options['uploads_adopt'] = true;
		}
		if ( ! empty( $assoc_args['exclude-tables'] ) ) {
			$options['excluded_tables'] = array_map( 'trim', explode( ',', (string) $assoc_args['exclude-tables'] ) );
		}
		if ( array_key_exists( 'schema-only-tables', $assoc_args ) ) {
			$options['schema_only_tables'] = '' === trim( (string) $assoc_args['schema-only-tables'] )
				? array()
				: array_map( 'trim', explode( ',', (string) $assoc_args['schema-only-tables'] ) );
		}
		// --no-safety arrives as safety=false (WP-CLI negated flag).
		if ( isset( $assoc_args['safety'] ) && false === $assoc_args['safety'] ) {
			$options['safety'] = array();
		} elseif ( ! empty( $assoc_args['safety'] ) && is_string( $assoc_args['safety'] ) ) {
			$options['safety'] = array_map( 'trim', explode( ',', $assoc_args['safety'] ) );
		}
		if ( ! empty( $assoc_args['plugins'] ) && is_string( $assoc_args['plugins'] ) ) {
			$options['plugins'] = array_map( 'trim', explode( ',', $assoc_args['plugins'] ) );
		}
		if ( ! empty( $assoc_args['uploads'] ) && is_string( $assoc_args['uploads'] ) ) {
			$options['uploads_scope'] = array_map( 'trim', explode( ',', $assoc_args['uploads'] ) );
		}
		if ( ! empty( $assoc_args['constants'] ) && is_string( $assoc_args['constants'] ) ) {
			$options['config_constants'] = array_map( 'trim', explode( ',', $assoc_args['constants'] ) );
		}

		if ( ! empty( $assoc_args['dry-run'] ) ) {
			$session = Client::begin_transfer_session( $options );
			if ( is_wp_error( $session ) ) {
				\WP_CLI::error( $session->get_error_message() );
			}
			try {
				$manifest = Client::get_json( 'manifest', ! empty( $options['incremental'] ) && ! empty( $options['db'] ) ? array( 'checksums' => 1 ) : array() );
			} finally {
				Client::end_transfer_session();
			}
			if ( is_wp_error( $manifest ) ) {
				\WP_CLI::error( $manifest->get_error_message() );
			}
			$bytes = array_sum( wp_list_pluck( (array) $manifest['tables'], 'size' ) );
			\WP_CLI::log( sprintf( 'Dry run: source %s, %d table(s), approximately %.1f MB database, directories: %s.', $manifest['site']['home'], count( (array) $manifest['tables'] ), $bytes / 1048576, implode( ', ', (array) $manifest['dirs'] ) ) );
			\WP_CLI::success( 'Dry run complete; no job or target mutation was created. The temporary source transfer session was released.' );
			return;
		}

		// Same refusal as start_job(), given before the prompt: a confirmation
		// that lists nothing to replace is not worth answering.
		if ( empty( $options['db'] ) && empty( $options['dirs'] ) ) {
			\WP_CLI::error( 'Nothing selected: enable the database and/or at least one directory.' );
		}

		if ( empty( $assoc_args['yes'] ) ) {
			$confirm = Start_Confirmation::strings();
			\WP_CLI::log( $confirm['intro'] );
			foreach ( Start_Confirmation::mode_lines( $options ) as $line ) {
				\WP_CLI::log( '  - ' . $line );
			}
			\WP_CLI::log( '  - the live source site is never paused or frozen' );
			\WP_CLI::log( '  - ' . Start_Confirmation::safety_line( $options ) );
			\WP_CLI::confirm( Start_Confirmation::outro( $options ) );
		}

		// CLI drives the job inline; no cron worker needed.
		$job = Sync_Runner::start_job( $options, false, get_current_user_id() );
		if ( is_wp_error( $job ) ) {
			\WP_CLI::error( $job->get_error_message() );
		}

		$printed        = 0;
		$last_progress  = '';
		while ( true ) {
			$job = Sync_Runner::process_slice( 30 );
			if ( is_array( $job ) ) {
				for ( $i = $printed; $i < count( $job['log'] ); $i++ ) {
					\WP_CLI::log( $job['log'][ $i ][1] );
				}
				$printed = count( $job['log'] );
			}
			if ( ! Job::is_active( $job ) ) {
				break;
			}
			// No progress this pass (slice lock held elsewhere): back off.
			// Yielding steps keep the same step index, so heartbeat movement
			// also counts as progress.
			$progress = $job['current'] . ':' . (int) $job['heartbeat'];
			if ( $progress === $last_progress ) {
				sleep( 1 );
			}
			$last_progress = $progress;
		}

		if ( ! is_array( $job ) || Job::S_DONE !== $job['status'] ) {
			$message = is_array( $job ) && $job['error'] ? $job['error'] : 'Sync did not complete.';
			if ( is_array( $job ) && ! empty( $job['error_info'] ) ) {
				$message .= sprintf( ' [%s/%s] %s', $job['error_info']['category'], $job['error_info']['code'], $job['error_info']['remediation'] );
			}
			\WP_CLI::error( $message );
		}
		\WP_CLI::success( 'Sync complete.' );
	}

	/**
	 * Lists the available post-sync safety rules, defaults, and what each changes.
	 *
	 * ## EXAMPLES
	 *
	 *     wp sandbox-sync safety-rules
	 *
	 * @subcommand safety-rules
	 */
	public function safety_rules() {
		\WP_CLI::log( 'Post-sync safety rules (default set does not rewrite users, posts, or comments):' );
		\WP_CLI::log( '' );
		foreach ( Safety::rules() as $key => $rule ) {
			\WP_CLI::log( sprintf( '%-20s %s  %s', $key, $rule['default'] ? '[default on]' : '[off]       ', $rule['label'] ) );
			\WP_CLI::log( '  ' . $rule['desc'] );
			\WP_CLI::log( '' );
		}
	}

	/**
	 * Lists wp-config.php constants the live site offers for replication
	 * (run on the sandbox).
	 *
	 * @subcommand source-constants
	 */
	public function source_constants() {
		$response = Client::get_json( 'constants' );
		if ( is_wp_error( $response ) ) {
			\WP_CLI::error( $response->get_error_message() );
		}
		$constants = (array) $response['constants'];
		if ( empty( $constants ) ) {
			\WP_CLI::log( 'No replicable constants found in the live wp-config.php.' );
			return;
		}
		foreach ( $constants as $constant ) {
			\WP_CLI::log( (string) $constant['name'] );
		}
	}

	/**
	 * Lists plugins installed on the live site (run on the sandbox), for
	 * use with `run --plugins=<tops>`.
	 *
	 * @subcommand source-plugins
	 */
	public function source_plugins() {
		$response = Client::get_json( 'plugins' );
		if ( is_wp_error( $response ) ) {
			\WP_CLI::error( $response->get_error_message() );
		}
		foreach ( (array) $response['plugins'] as $plugin ) {
			\WP_CLI::log(
				sprintf(
					'%-32s %-10s %s (%s)%s',
					$plugin['top'],
					'v' . $plugin['version'],
					$plugin['name'],
					$plugin['active'] ? 'active' : 'inactive',
					! empty( $plugin['symlink'] ) ? ' [symlinked — not synced]' : ''
				)
			);
		}
	}

	/**
	 * Requests cooperative cancellation and rollback at the next safe checkpoint.
	 */
	public function cancel() {
		$job = Sync_Runner::cancel_job();
		if ( is_wp_error( $job ) ) {
			\WP_CLI::error( $job->get_error_message() );
		}
		\WP_CLI::success( 'Emergency stop requested locally and signalled to the live source.' );
	}

	/**
	 * Immediately cancels active source transfers. Run locally on the
	 * production/source site when the sandbox is unavailable or its worker
	 * cannot complete cancellation. Also clears legacy pre-0.12 freeze state.
	 *
	 * @subcommand source-stop
	 */
	public function source_stop() {
		$result = Source_Endpoints::emergency_stop();
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}
		\WP_CLI::success( sprintf( 'Emergency stop signalled to %d source transfer(s).', (int) $result['transfers_cancelled'] ) );
	}

	/**
	 * Shows connection/pairing status for both roles.
	 */
	public function status( $args = array(), $assoc_args = array() ) {
		$connection = Auth::get_connection();
		if ( isset( $assoc_args['format'] ) && 'json' === $assoc_args['format'] ) {
			$connection_summary = $connection
				? array(
					'source_url' => $connection['source_url'],
					'expires_at' => isset( $connection['expires_at'] ) ? (int) $connection['expires_at'] : 0,
				)
				: null;
			$paired_summary = array();
			foreach ( Auth::get_sandboxes() as $token_hash => $info ) {
				$paired_summary[] = array(
					'url'         => isset( $info['url'] ) ? $info['url'] : '',
					'paired_at'   => isset( $info['paired_at'] ) ? (int) $info['paired_at'] : 0,
					'expires_at'  => isset( $info['expires_at'] ) ? (int) $info['expires_at'] : 0,
					'hash_prefix' => substr( $token_hash, 0, 12 ),
				);
			}
			\WP_CLI::line(
				wp_json_encode(
					array(
						'connection'     => $connection_summary,
						'is_sandbox'     => Auth::is_sandbox_site(),
						'paired_sources' => $paired_summary,
						'job'            => Job::public_state( Job::get() ),
						'last_report'    => get_option( Sync_Runner::OPT_LAST_REPORT ),
					)
				)
			);
			return;
		}
		$expiry = '';
		if ( $connection ) {
			$expiry = ! empty( $connection['expires_at'] )
				? ' (token expires ' . wp_date( 'Y-m-d H:i', $connection['expires_at'] ) . ( (int) $connection['expires_at'] <= time() + Auth::TOKEN_EXPIRY_WARNING ? '; re-pair soon' : '' ) . ')'
				: ' (token expiry unknown; re-pair to rotate this legacy credential)';
		}
		\WP_CLI::log( 'As sandbox:   ' . ( $connection ? 'connected to ' . $connection['source_url'] . $expiry : 'not connected' ) );
		\WP_CLI::log( 'Sandbox flag: ' . ( Auth::is_sandbox_site() ? 'ON (syncs may overwrite this site)' : 'off (syncs blocked)' ) );

		$sandboxes = Auth::get_sandboxes();
		if ( empty( $sandboxes ) ) {
			\WP_CLI::log( 'As source:    no sandboxes paired' );
		} else {
			\WP_CLI::log( sprintf( 'As source:    %d sandbox(es) paired:', count( $sandboxes ) ) );
			foreach ( $sandboxes as $token_hash => $info ) {
				\WP_CLI::log(
					sprintf(
						'  - %s (since %s, token expires %s, hash %s…)',
						! empty( $info['url'] ) ? $info['url'] : 'unknown',
						! empty( $info['paired_at'] ) ? wp_date( 'Y-m-d H:i', (int) $info['paired_at'] ) : '—',
						! empty( $info['expires_at'] ) ? wp_date( 'Y-m-d', (int) $info['expires_at'] ) : '—',
						substr( $token_hash, 0, 12 )
					)
				);
			}
		}

		$job = Job::get();
		if ( is_array( $job ) ) {
			\WP_CLI::log(
				sprintf(
					'Sync job:     %s [%s] step %d/%d (heartbeat %ds ago)',
					$job['id'],
					$job['status'],
					min( $job['current'] + 1, count( $job['steps'] ) ),
					count( $job['steps'] ),
					time() - (int) $job['heartbeat']
				)
			);
		}

		$report = get_option( Sync_Runner::OPT_LAST_REPORT );
		if ( is_array( $report ) ) {
			\WP_CLI::log(
				sprintf(
					'Last sync:    from %s at %s (%d tables imported, %d skipped, %s).',
					$report['source'],
					wp_date( 'Y-m-d H:i:s', (int) $report['finished_at'] ),
					isset( $report['tables_imported'] ) ? $report['tables_imported'] : 0,
					isset( $report['tables_skipped'] ) ? $report['tables_skipped'] : 0,
					Job::format_duration( (int) ( $report['duration_s'] ?? 0 ) )
				)
			);
		}
	}

	/**
	 * Lists retained sync reports (newest first).
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : `text` (default) or `json`.
	 */
	public function history( $args = array(), $assoc_args = array() ) {
		$history = get_option( Sync_Runner::OPT_HISTORY );
		$history = is_array( $history ) ? array_reverse( $history ) : array();
		if ( isset( $assoc_args['format'] ) && 'json' === $assoc_args['format'] ) {
			\WP_CLI::line( wp_json_encode( $history ) );
			return;
		}
		if ( empty( $history ) ) {
			\WP_CLI::log( 'No sync history is available.' );
			return;
		}
		foreach ( $history as $report ) {
			$error_category = ! empty( $report['error_info']['category'] ) ? ' [' . $report['error_info']['category'] . ']' : '';
			$job_id         = (string) ( $report['job_id'] ?? '' );
			$log_path       = Job::log_path( $job_id );
			$log_note       = ( $log_path && is_file( $log_path ) ) ? ' log:' . $log_path : '';
			\WP_CLI::log(
				sprintf(
					'%s %-12s %-10s%s %4d imported / %4d skipped %10s %s%s',
					wp_date( 'Y-m-d H:i:s', (int) ( $report['finished_at'] ?? 0 ) ),
					$job_id,
					(string) ( $report['status'] ?? 'unknown' ),
					$error_category,
					(int) ( $report['tables_imported'] ?? 0 ),
					(int) ( $report['tables_skipped'] ?? 0 ),
					Job::format_duration( (int) ( $report['duration_s'] ?? 0 ) ),
					(string) ( $report['source'] ?? '' ),
					$log_note
				)
			);
		}
	}

	/**
	 * Prints the retained log file for a finished (or still-running) job.
	 *
	 * ## OPTIONS
	 *
	 * <job_id>
	 * : 12-character job id from `wp sandbox-sync history`.
	 */
	public function log( $args = array(), $assoc_args = array() ) {
		$job_id = isset( $args[0] ) ? (string) $args[0] : '';
		$path   = Job::log_path( $job_id );
		if ( null === $path ) {
			\WP_CLI::error( 'Invalid job id.' );
		}
		$log = Job::read_log_file( $job_id );
		if ( is_wp_error( $log ) ) {
			\WP_CLI::error( $log->get_error_message() );
		}
		if ( '' === $log ) {
			\WP_CLI::error( 'No log file found for that job (logs are kept for the retained sync history window).' );
		}
		\WP_CLI::log( 'Log file: ' . $path );
		echo $log; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI raw log dump.
	}

	/**
	 * Forgets the connection to the source site (sandbox side).
	 */
	public function disconnect() {
		Auth::disconnect();
		\WP_CLI::success( 'Disconnected.' );
	}

	/**
	 * Revokes sandbox access (source side).
	 *
	 * ## OPTIONS
	 *
	 * [<token-hash>]
	 * : Token hash (or unique prefix) of one sandbox, as shown by `status`.
	 *   Omit to revoke ALL sandboxes and any pending pairing key.
	 */
	public function revoke( $args ) {
		$prefix = isset( $args[0] ) ? (string) $args[0] : '';

		if ( '' === $prefix ) {
			Auth::revoke_source();
			\WP_CLI::success( 'All sandbox access revoked.' );
			return;
		}

		$matches = array();
		foreach ( Auth::get_sandboxes() as $token_hash => $info ) {
			if ( 0 === strpos( $token_hash, $prefix ) ) {
				$matches[ $token_hash ] = $info;
			}
		}

		if ( empty( $matches ) ) {
			\WP_CLI::error( "No paired sandbox matches token hash prefix '{$prefix}'." );
		}
		if ( count( $matches ) > 1 ) {
			\WP_CLI::error( "Token hash prefix '{$prefix}' is ambiguous (matches " . count( $matches ) . ' sandboxes).' );
		}

		$token_hash = array_key_first( $matches );
		Auth::revoke_sandbox( $token_hash );
		\WP_CLI::success( sprintf( 'Revoked sandbox %s.', ! empty( $matches[ $token_hash ]['url'] ) ? $matches[ $token_hash ]['url'] : $token_hash ) );
	}

	/**
	 * Drops leftover shadow/backup working tables (ssx_*, ssxbak_*) and
	 * removes the finished/failed job record.
	 */
	public function cleanup() {
		$result = Sync_Runner::cleanup();
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}
		\WP_CLI::success( "Dropped {$result} working tables." );
	}
}
