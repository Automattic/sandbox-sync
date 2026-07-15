<?php
/**
 * Test helper (wp eval-file): starts a background uploads-only sync job,
 * as the admin UI would, so the cron worker path can be exercised headlessly.
 */

$job = SandboxSync\Sync_Runner::start_job(
	array(
		'db'          => false,
		'dirs'        => array( 'uploads' ),
		'incremental' => true,
	),
	true, // background
	1
);

if ( is_wp_error( $job ) ) {
	WP_CLI::error( $job->get_error_message() );
}
WP_CLI::log( 'job ' . $job['id'] . ' started in background; cron scheduled: ' . var_export( (bool) wp_next_scheduled( 'sandbox_sync_process_job' ), true ) );
