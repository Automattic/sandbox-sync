<?php
/**
 * Plugin Name: Sandbox Sync
 * Description: Pair a live site with its sandbox and pull-sync the database, plugins, mu-plugins, themes and uploads from live to sandbox over authenticated HTTP.
 * Version: 0.1.0
 * Author: Newspack
 *
 * Install this file (or a symlink to it) in wp-content/mu-plugins/ on BOTH
 * sites. Each site can act as the source (live) or the sandbox; the admin
 * screen under Tools > Sandbox Sync exposes both roles.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Guard against double inclusion (e.g. two copies of the loader in mu-plugins/).
if ( defined( 'SANDBOX_SYNC_VERSION' ) ) {
	return;
}
define( 'SANDBOX_SYNC_VERSION', '0.1.0' );
define( 'SANDBOX_SYNC_PROTOCOL', 14 );

// PHP resolves symlinks for __FILE__/__DIR__, so this points at the real repo
// checkout even when this loader is symlinked into mu-plugins/.
define( 'SANDBOX_SYNC_DIR', __DIR__ . '/sandbox-sync' );

require_once SANDBOX_SYNC_DIR . '/class-env.php';
require_once SANDBOX_SYNC_DIR . '/class-error-info.php';
require_once SANDBOX_SYNC_DIR . '/class-auth.php';
require_once SANDBOX_SYNC_DIR . '/class-tar-exporter.php';
require_once SANDBOX_SYNC_DIR . '/class-tar-extractor.php';
require_once SANDBOX_SYNC_DIR . '/class-tar-stream-extractor.php';
require_once SANDBOX_SYNC_DIR . '/class-db-exporter.php';
require_once SANDBOX_SYNC_DIR . '/class-table-delta.php';
require_once SANDBOX_SYNC_DIR . '/class-db-shell.php';
require_once SANDBOX_SYNC_DIR . '/class-db-shell-dump-reader.php';
require_once SANDBOX_SYNC_DIR . '/class-files.php';
require_once SANDBOX_SYNC_DIR . '/class-uploads-state.php';
require_once SANDBOX_SYNC_DIR . '/class-source-state.php';
require_once SANDBOX_SYNC_DIR . '/class-source-endpoints.php';
require_once SANDBOX_SYNC_DIR . '/class-client.php';
require_once SANDBOX_SYNC_DIR . '/class-search-replace.php';
require_once SANDBOX_SYNC_DIR . '/class-safety.php';
require_once SANDBOX_SYNC_DIR . '/class-job.php';
require_once SANDBOX_SYNC_DIR . '/trait-sync-runner-database.php';
require_once SANDBOX_SYNC_DIR . '/trait-sync-runner-files.php';
require_once SANDBOX_SYNC_DIR . '/class-sync-runner.php';
require_once SANDBOX_SYNC_DIR . '/class-admin-page.php';

// Source-side export endpoints (?sandbox_sync=...). Handled right now, at
// mu-plugin load time, so no regular plugin or theme code can pollute the
// streamed tar/SQL output (same reason the Knife's export mu-plugin runs
// without any WordPress hook).
SandboxSync\Source_Endpoints::maybe_handle_request();

SandboxSync\Admin_Page::init();
SandboxSync\Uploads_State::init();

// Enforces the "block outgoing email" safety rule (if enabled by a sync).
SandboxSync\Safety::init();

// Background sync worker: processes job steps in time-budgeted slices and
// reschedules itself until the job finishes.
add_action( 'sandbox_sync_process_job', array( 'SandboxSync\\Sync_Runner', 'cron_worker' ) );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once SANDBOX_SYNC_DIR . '/class-cli.php';
	WP_CLI::add_command( 'sandbox-sync', 'SandboxSync\\CLI' );
}
