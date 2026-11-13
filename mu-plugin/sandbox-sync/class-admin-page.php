<?php
/**
 * Tools > Sandbox Sync admin screen + AJAX handlers.
 *
 * The same screen serves both roles:
 *  - Source (live) box: pairing keys, list of paired sandboxes (with token
 *    expiry) and per-sandbox revocation.
 *  - Sandbox box: connection management, the "this site is a sandbox"
 *    guardrail flag, sync options (what to sync, incremental, background),
 *    and a live job panel (progress, per-step log, cancel) that reattaches
 *    to a running job when the page is reopened.
 *
 * Assets are inlined because the mu-plugin's class directory lives outside
 * wp-content (only the loader is symlinked into mu-plugins/), so there is no
 * URL to enqueue files from.
 */

namespace SandboxSync;

defined( 'ABSPATH' ) || exit;

class Admin_Page {

	const NONCE_ACTION = 'sandbox-sync';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'wp_ajax_sandbox_sync', array( __CLASS__, 'handle_ajax' ) );
	}

	public static function register_menu() {
		add_management_page(
			'Sandbox Sync',
			'Sandbox Sync',
			'manage_options',
			'sandbox-sync',
			array( __CLASS__, 'render_page' )
		);
	}

	// -- AJAX -----------------------------------------------------------------

	public static function handle_ajax() {
		check_ajax_referer( self::NONCE_ACTION );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}

		$op = isset( $_POST['op'] ) ? sanitize_key( $_POST['op'] ) : '';

		switch ( $op ) {
			case 'generate_key':
				wp_send_json_success(
					array(
						'message'     => 'Pairing key generated. Copy it to the sandbox now — it is shown only once.',
						'pairing_key' => Auth::generate_pairing_key(),
					)
				);
				break;

			case 'revoke':
				$hash = isset( $_POST['hash'] ) ? sanitize_text_field( wp_unslash( $_POST['hash'] ) ) : '';
				if ( '' === $hash ) {
					Auth::revoke_source();
					wp_send_json_success( array( 'message' => 'All sandbox access revoked.' ) );
				}
				if ( ! Auth::revoke_sandbox( $hash ) ) {
					wp_send_json_error( array( 'message' => 'Unknown sandbox.' ) );
				}
				wp_send_json_success( array( 'message' => 'Sandbox access revoked.' ) );
				break;

			case 'connect':
				$source_url  = isset( $_POST['source_url'] ) ? esc_url_raw( wp_unslash( $_POST['source_url'] ) ) : '';
				$pairing_key = isset( $_POST['pairing_key'] ) ? sanitize_text_field( wp_unslash( $_POST['pairing_key'] ) ) : '';
				$site        = Client::handshake( $source_url, $pairing_key );
				if ( is_wp_error( $site ) ) {
					wp_send_json_error( array( 'message' => $site->get_error_message() ) );
				}
				wp_send_json_success(
					array(
						'message' => sprintf( 'Connected to %s.', isset( $site['home'] ) ? $site['home'] : $source_url ),
					)
				);
				break;

			case 'disconnect':
				Auth::disconnect();
				wp_send_json_success( array( 'message' => 'Disconnected from the source site.' ) );
				break;

			case 'set_sandbox_flag':
				$enabled = ! empty( $_POST['enabled'] ) && '0' !== $_POST['enabled'];
				Auth::set_sandbox_flag( $enabled );
				wp_send_json_success(
					array(
						'message' => $enabled
							? 'This site is now marked as a sandbox: syncs may overwrite it.'
							: 'Sandbox flag removed: syncs into this site are blocked.',
					)
				);
				break;

			case 'fetch_source_meta':
				// Plugin list, offerable wp-config constants and uploads
				// year/month layout from the live site, for the pickers.
				$metadata  = Client::get_json_parallel(
					array(
						'plugins'        => array(),
						'constants'      => array(),
						'uploads_scopes' => array(),
					)
				);
				$plugins   = $metadata['plugins'];
				$constants = $metadata['constants'];
				$uploads   = $metadata['uploads_scopes'];
				if ( is_wp_error( $plugins ) ) {
					wp_send_json_error( array( 'message' => $plugins->get_error_message() ) );
				}
				if ( is_wp_error( $constants ) ) {
					wp_send_json_error( array( 'message' => $constants->get_error_message() ) );
				}
				if ( is_wp_error( $uploads ) ) {
					wp_send_json_error( array( 'message' => $uploads->get_error_message() ) );
				}
				wp_send_json_success(
					array(
						'plugins'   => $plugins['plugins'],
						'constants' => $constants['constants'],
						'uploads'   => $uploads,
						// Sandbox-only plugins and themes a sync started now would keep,
						// and those it would still remove while no sync is recorded.
						'kept'                => Sync_Runner::preview_kept( $plugins ),
						'first_sync_removals' => Sync_Runner::preview_first_sync_removals( $plugins ),
					)
				);
				break;

			case 'start_job':
				$raw_options = isset( $_POST['options'] ) ? json_decode( wp_unslash( $_POST['options'] ), true ) : array();
				$raw_options = is_array( $raw_options ) ? $raw_options : array();
				$background  = ! empty( $_POST['background'] ) && '0' !== $_POST['background'];

				$job = Sync_Runner::start_job( $raw_options, $background, get_current_user_id() );
				if ( is_wp_error( $job ) ) {
					wp_send_json_error( array( 'message' => $job->get_error_message() ) );
				}
				wp_send_json_success( array( 'job' => Job::public_state( $job ) ) );
				break;

			case 'job_tick':
				// Browser-driven processing: runs steps for a short budget if
				// the slice lock is free (the cron worker may hold it).
				$job = Sync_Runner::process_slice( 8 );
				wp_send_json_success( array( 'job' => self::public_job_state( $job ) ) );
				break;

			case 'job_status':
				wp_send_json_success( array( 'job' => self::public_job_state( Job::get() ) ) );
				break;

			case 'cancel_job':
				$job = Sync_Runner::cancel_job();
				if ( is_wp_error( $job ) ) {
					wp_send_json_error( array( 'message' => $job->get_error_message() ) );
				}
				wp_send_json_success(
					array(
						'message' => 'Emergency stop requested locally and signalled to the live source. Cleanup will run at the next safe checkpoint.',
						'job'     => Job::public_state( $job ),
					)
				);
				break;

			case 'cleanup':
				$result = Sync_Runner::cleanup();
				if ( is_wp_error( $result ) ) {
					wp_send_json_error( array( 'message' => $result->get_error_message() ) );
				}
				wp_send_json_success( array( 'message' => "Cleaned up ({$result} working tables dropped)." ) );
				break;

			case 'job_log':
				$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
				if ( ! self::history_has_job( $job_id ) ) {
					$current = Job::get();
					if ( ! is_array( $current ) || ! hash_equals( (string) $current['id'], $job_id ) ) {
						wp_send_json_error( array( 'message' => 'No retained log for that job.' ), 404 );
					}
				}
				$log = Job::read_log_file( $job_id );
				if ( is_wp_error( $log ) ) {
					wp_send_json_error( array( 'message' => $log->get_error_message() ), 400 );
				}
				wp_send_json_success(
					array(
						'job_id' => $job_id,
						'log'    => $log,
						'path'   => Job::log_path( $job_id ),
					)
				);
				break;

			default:
				wp_send_json_error( array( 'message' => 'Unknown operation.' ), 400 );
		}
	}

	/** True when $job_id appears in the retained sync history. */
	private static function history_has_job( $job_id ) {
		if ( ! is_string( $job_id ) || ! preg_match( '/^[a-f0-9]{12}$/', $job_id ) ) {
			return false;
		}
		$history = get_option( Sync_Runner::OPT_HISTORY );
		if ( ! is_array( $history ) ) {
			return false;
		}
		foreach ( $history as $report ) {
			if ( ! empty( $report['job_id'] ) && hash_equals( (string) $report['job_id'], $job_id ) ) {
				return true;
			}
		}
		return false;
	}

	/** Adds best-effort source counters only while a directory plan is active. */
	private static function public_job_state( $job ) {
		$state = Job::public_state( $job );
		if ( ! is_array( $state ) || Job::S_RUNNING !== $state['status'] || ! isset( $job['steps'][ $job['current'] ] ) ) {
			return $state;
		}
		$step = $job['steps'][ $job['current'] ];
		$dir  = 'uploads_finalize' === $step['type'] ? 'uploads' : ( $step['args']['dir'] ?? '' );
		if ( ( 'dir_plan' !== $step['type'] && 'uploads_finalize' !== $step['type'] ) || '' === $dir ) {
			return $state;
		}
		$progress = Client::get_transfer_progress();
		if ( is_array( $progress )
			&& $dir === $progress['dir']
			&& time() - (int) $progress['updated_at'] <= 15 ) {
			$state['live_progress'] = $progress;
		}
		return $state;
	}

	// -- Page -----------------------------------------------------------------

	public static function render_page() {
		$connection  = Auth::get_connection();
		$history     = get_option( Sync_Runner::OPT_HISTORY );
		$history     = is_array( $history ) ? array_reverse( $history ) : array();
		$sandboxes   = Auth::get_sandboxes();
		$is_sandbox  = Auth::is_sandbox_site();
		$job         = Job::get();
		$admin_config = wp_json_encode(
			array(
				'nonce'      => wp_create_nonce( self::NONCE_ACTION ),
				'initialJob' => Job::public_state( $job ),
			),
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
		);
		?>
		<div class="wrap">
			<h1>Sandbox Sync</h1>

			<div id="sbs-notice" class="notice" style="display:none;"><p></p></div>

			<div class="sbs-cards">

				<div class="card sbs-card">
					<h2>This site as a source (live)</h2>
					<?php if ( ! empty( $sandboxes ) ) : ?>
						<table class="widefat striped" style="margin-bottom:12px;">
							<thead>
								<tr>
									<th>Paired sandbox</th>
									<th>Since</th>
									<th>Token expires</th>
									<th style="width:90px;"></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $sandboxes as $token_hash => $info ) : ?>
									<tr>
										<td><strong><?php echo esc_html( ! empty( $info['url'] ) ? $info['url'] : 'unknown' ); ?></strong></td>
										<td><?php echo ! empty( $info['paired_at'] ) ? esc_html( wp_date( 'Y-m-d H:i', (int) $info['paired_at'] ) ) : '—'; ?></td>
										<td><?php echo ! empty( $info['expires_at'] ) ? esc_html( wp_date( 'Y-m-d', (int) $info['expires_at'] ) ) : '—'; ?></td>
										<td><button class="button button-small sbs-revoke-one" data-hash="<?php echo esc_attr( $token_hash ); ?>">Revoke</button></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
						<?php if ( count( $sandboxes ) > 1 ) : ?>
							<p><button class="button" id="sbs-revoke-all">Revoke all sandboxes</button></p>
						<?php endif; ?>
					<?php else : ?>
						<p>No sandbox is paired with this site.</p>
					<?php endif; ?>
					<p>
						<button class="button button-secondary" id="sbs-generate-key">Generate pairing key</button>
					</p>
					<div id="sbs-key-box" style="display:none;">
						<p><strong>Pairing key (shown once, copy it now):</strong></p>
						<p><code id="sbs-key" style="user-select:all;font-size:14px;padding:6px;display:inline-block;background:#f0f0f1;"></code></p>
						<p class="description">Paste it in the “Connect” form on the sandbox site. The key is single-use; generate one key per sandbox you pair. Only its hash is stored here.</p>
					</div>
				</div>

				<div class="card sbs-card">
					<h2>This site as a sandbox</h2>

					<?php if ( $connection ) : ?>
						<p>
						Connected to source: <strong><?php echo esc_html( $connection['source_url'] ); ?></strong>
						<?php if ( ! empty( $connection['expires_at'] ) ) : ?>
							— token expires <?php echo esc_html( wp_date( 'Y-m-d H:i', (int) $connection['expires_at'] ) ); ?>
							<?php if ( (int) $connection['expires_at'] <= time() + Auth::TOKEN_EXPIRY_WARNING ) : ?>
								<strong style="color:#b32d2e;">(re-pair soon)</strong>
							<?php endif; ?>
						<?php else : ?>
							— <strong style="color:#b32d2e;">token expiry unknown; re-pair to rotate this legacy credential</strong>
						<?php endif; ?>
							<button class="button button-small" id="sbs-disconnect" style="margin-left:8px;">Disconnect</button>
						</p>

						<p>
							<label>
								<input type="checkbox" id="sbs-sandbox-flag" <?php checked( $is_sandbox ); ?>>
								<strong>This site is a sandbox</strong> — its database and files may be overwritten by syncs.
							</label>
						</p>
						<p class="description" id="sbs-flag-warning" style="color:#b32d2e;<?php echo $is_sandbox ? 'display:none;' : ''; ?>">Syncing is blocked until this flag is enabled (direction guardrail).</p>

						<h3>Sync options</h3>
						<p>
							<label><input type="checkbox" class="sbs-opt" id="sbs-opt-db" checked> Database</label>
							&nbsp; <label><input type="checkbox" class="sbs-opt sbs-opt-dir" value="plugins" checked> plugins/</label>
							&nbsp; <label><input type="checkbox" class="sbs-opt sbs-opt-dir" value="mu-plugins" checked> mu-plugins/</label>
							&nbsp; <label><input type="checkbox" class="sbs-opt sbs-opt-dir" value="themes" checked> themes/</label>
							&nbsp; <label><input type="checkbox" class="sbs-opt sbs-opt-dir" value="uploads" checked> uploads/</label>
						</p>
						<p>
							<label><input type="checkbox" id="sbs-opt-incremental" checked> Incremental (only changed files/tables travel)</label>
							&nbsp; <label><input type="checkbox" id="sbs-opt-background" checked> Run in background (keeps going if you close this tab)</label>
						</p>
						<p>
							<label><input type="checkbox" id="sbs-opt-uploads-audit"> Rescan all selected uploads folders (advanced)</label>
							<span class="description">The first sync already scans every selected year and month. On later syncs, enable this only after files were changed directly through SFTP, SSH or another process that bypassed WordPress; otherwise clean historical months are skipped. This compares file metadata and still hashes only files that appear changed.</span>
						</p>
						<p>
							<label><input type="checkbox" id="sbs-opt-uploads-adopt"> Adopt existing uploads in never-synced folders (advanced)</label>
							<span class="description">Only affects selected folders that have never completed a verified sync from this source (each year commits its own record the moment it finishes copying; folders with a record are unaffected either way). Those never-synced folders would normally be cleared and copied from live in full. Enable this if their files are already here — for example a copy that was interrupted partway through a year: each such folder is instead compared file-by-file and only missing or changed files are downloaded. Slower per file than the direct copy, but avoids re-transferring data you already have.</span>
						</p>

						<div class="sbs-accordion">

							<details class="sbs-acc">
								<summary>Plugins to sync <span class="sbs-acc-hint" id="sbs-plugins-hint">all</span></summary>
								<div class="sbs-acc-body">
									<p class="description">Fetched from the live site. Unselected plugins are neither downloaded nor deleted on the sandbox. <?php echo esc_html( is_array( get_option( Sync_Runner::OPT_SYNCED_PLUGINS ) ) ? 'Plugins that only this sandbox has are kept.' : 'No earlier sync is recorded on this sandbox yet: the next one still removes plugins live does not have, and keeps them from then on.' ); ?></p>
									<p>
										<button type="button" class="button button-small sbs-check-all" data-target="sbs-plugin">All</button>
										<button type="button" class="button button-small sbs-check-none" data-target="sbs-plugin">None</button>
									</p>
									<div id="sbs-plugins-list" class="sbs-picker">Loading plugin list from live…</div>
								</div>
							</details>

							<details class="sbs-acc">
								<summary>Uploads to sync <span class="sbs-acc-hint" id="sbs-uploads-hint">all</span></summary>
								<div class="sbs-acc-body">
									<p class="description">Limit the uploads sync to specific years or months (fetched from the live site). Unselected periods are neither downloaded nor deleted on the sandbox.</p>
									<p>
										<button type="button" class="button button-small" id="sbs-uploads-all">All</button>
										<button type="button" class="button button-small" id="sbs-uploads-none">None</button>
									</p>
									<div id="sbs-uploads-list" class="sbs-picker">Loading uploads layout from live…</div>
								</div>
							</details>

							<details class="sbs-acc">
								<summary>wp-config.php constants <span class="sbs-acc-hint" id="sbs-constants-hint">none</span></summary>
								<div class="sbs-acc-body">
									<p class="description">Simple scalar constants from the live site's <code>wp-config.php</code>. All are unselected by default; values are hidden during discovery and fetched only for the exact names selected when the sync runs. Credentials, salts, keys, tokens, passwords, URLs, paths and database settings are never offered.</p>
									<div id="sbs-constants-list" class="sbs-picker">Loading constants from live…</div>
								</div>
							</details>

							<details class="sbs-acc">
								<summary>Excluded tables <span class="sbs-acc-hint" id="sbs-excluded-hint">none</span></summary>
								<div class="sbs-acc-body">
									<p class="description">One table per line, without the prefix (e.g. <code>actionscheduler_logs</code>). Excluded tables keep their current sandbox content.</p>
									<textarea id="sbs-opt-excluded" rows="3" style="width:100%;"></textarea>
								</div>
							</details>

							<details class="sbs-acc">
								<summary>Schema-only tables <span class="sbs-acc-hint" id="sbs-schema-only-hint"><?php echo count( Sync_Runner::DEFAULT_SCHEMA_ONLY_TABLES ); ?> table(s)</span></summary>
								<div class="sbs-acc-body">
									<p class="description">One table per line, without the prefix. These tables are recreated atomically from the live schema but contain no live rows. Use this for disposable logs, caches, recovery data and other tables whose production contents should not enter the sandbox.</p>
									<textarea id="sbs-opt-schema-only" rows="3" style="width:100%;"><?php echo esc_textarea( implode( "\n", Sync_Runner::DEFAULT_SCHEMA_ONLY_TABLES ) ); ?></textarea>
								</div>
							</details>

							<details class="sbs-acc">
								<summary>Sandbox safety (keep/omit) <span class="sbs-acc-hint" id="sbs-safety-hint"></span></summary>
								<div class="sbs-acc-body">
									<p class="description">Applied after guarded database activation, before ordinary traffic resumes. These rules neutralize production integrations and secrets; they do <strong>not</strong> rewrite users or editorial content. Each checkbox describes exactly what it changes.</p>
									<?php foreach ( Safety::rules() as $key => $rule ) : ?>
										<label class="sbs-rule">
											<input type="checkbox" class="sbs-safety" value="<?php echo esc_attr( $key ); ?>" <?php checked( $rule['default'] ); ?>>
											<span>
												<strong><?php echo esc_html( $rule['label'] ); ?></strong><br>
												<span class="description"><?php echo esc_html( $rule['desc'] ); ?></span>
											</span>
										</label>
									<?php endforeach; ?>
								</div>
							</details>

						</div>

						<p>
							<button class="button button-primary" id="sbs-start" <?php disabled( ! $is_sandbox ); ?>>Start sync from live</button>
							<button class="button" id="sbs-cancel" style="display:none;">Cancel sync</button>
						</p>

						<div id="sbs-job" style="display:none;">
							<h3>Sync job <code id="sbs-job-id"></code></h3>
							<div id="sbs-job-statusline"></div>
							<div style="background:#f0f0f1;border-radius:3px;height:20px;overflow:hidden;margin-bottom:8px;">
								<div id="sbs-progress-bar" style="background:#2271b1;height:100%;width:0;transition:width .3s;"></div>
							</div>
							<pre id="sbs-log" style="background:#1d2327;color:#c3e6cb;padding:12px;max-height:360px;overflow:auto;font-size:12px;line-height:1.6;border-radius:3px;"></pre>
						</div>
					<?php else : ?>
						<p>Connect this site to its live (source) site:</p>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><label for="sbs-source-url">Live site URL</label></th>
								<td><input type="url" id="sbs-source-url" class="regular-text" placeholder="https://live.local"></td>
							</tr>
							<tr>
								<th scope="row"><label for="sbs-pairing-key">Pairing key</label></th>
								<td><input type="text" id="sbs-pairing-key" class="regular-text" placeholder="Key generated on the live site"></td>
							</tr>
						</table>
						<p><button class="button button-primary" id="sbs-connect">Connect</button></p>
					<?php endif; ?>

					<?php if ( ! empty( $history ) ) : ?>
						<details>
							<summary><strong>Sync history (last <?php echo (int) count( $history ); ?>)</strong></summary>
							<table class="widefat striped" style="margin-top:8px;">
								<thead><tr><th>Finished</th><th>Job</th><th>Status</th><th>Source</th><th>Tables</th><th>Dirs</th><th>Duration</th><th>Log</th></tr></thead>
								<tbody>
								<?php foreach ( $history as $report ) : ?>
									<?php
									$report_id  = (string) ( $report['job_id'] ?? '' );
									$has_log    = ! empty( $report['has_log'] ) || ( null !== Job::log_path( $report_id ) && is_file( (string) Job::log_path( $report_id ) ) );
									$duration_s = (int) ( $report['duration_s'] ?? 0 );
									?>
									<tr>
										<td><?php echo esc_html( wp_date( 'Y-m-d H:i', (int) ( $report['finished_at'] ?? 0 ) ) ); ?></td>
										<td><code><?php echo esc_html( $report_id ); ?></code></td>
						<td title="<?php echo esc_attr( (string) ( $report['error'] ?? '' ) ); ?>"><?php echo esc_html( (string) ( $report['status'] ?? 'unknown' ) ); ?><?php echo ! empty( $report['error_info']['category'] ) ? '<br><small>' . esc_html( (string) $report['error_info']['category'] ) . '</small>' : ''; ?></td>
										<td><?php echo esc_html( (string) ( $report['source'] ?? '' ) ); ?></td>
										<td><?php echo (int) ( $report['tables_imported'] ?? 0 ); ?> imported / <?php echo (int) ( $report['tables_skipped'] ?? 0 ); ?> skipped</td>
										<td>
											<?php
											echo esc_html( implode( ', ', (array) ( $report['dirs'] ?? array() ) ) );
											$kept_summary = Sync_Runner::kept_summary( $report );
											echo '' !== $kept_summary ? '<br><small>' . esc_html( $kept_summary ) . '</small>' : '';
											?>
										</td>
										<td title="<?php echo esc_attr( $duration_s . 's' ); ?>"><?php echo esc_html( Job::format_duration( $duration_s ) ); ?></td>
										<td>
											<?php if ( $has_log ) : ?>
												<button type="button" class="button button-small sbs-view-log" data-job-id="<?php echo esc_attr( $report_id ); ?>">View</button>
											<?php else : ?>
												<span class="description">—</span>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
								</tbody>
							</table>
							<dialog id="sbs-log-dialog" style="width:min(900px,92vw);max-height:80vh;padding:0;border:1px solid #c3c4c7;border-radius:4px;">
								<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 12px;border-bottom:1px solid #dcdcde;background:#f6f7f7;">
									<strong id="sbs-log-dialog-title">Job log</strong>
									<form method="dialog"><button class="button">Close</button></form>
								</div>
								<pre id="sbs-log-dialog-body" style="margin:0;padding:12px;max-height:calc(80vh - 52px);overflow:auto;background:#1d2327;color:#c3e6cb;font-size:12px;line-height:1.55;white-space:pre-wrap;"></pre>
							</dialog>
						</details>
					<?php endif; ?>
				</div>

			</div>
		</div>

		<style>
			<?php readfile( SANDBOX_SYNC_DIR . '/assets/admin.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile,WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted bundled CSS. ?>
		</style>

		<script>
			<?php echo 'window.SandboxSyncAdmin = ' . $admin_config . ';'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON_HEX_* encoded bootstrap data. ?>
		</script>
		<script>
			<?php readfile( SANDBOX_SYNC_DIR . '/assets/admin.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile,WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted bundled JavaScript. ?>
		</script>
		<?php
	}
}
