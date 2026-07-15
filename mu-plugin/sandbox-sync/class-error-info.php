<?php
/**
 * Stable operational error taxonomy shared by the worker, UI, CLI, reports,
 * and automation consumers.
 */

namespace SandboxSync;

defined( 'ABSPATH' ) || exit;

class Error_Info {

	/** Converts a WP_Error into a redacted, actionable public record. */
	public static function from_wp_error( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return self::record( 'unknown', 'internal', false, 'Inspect the job log and application logs before retrying.', 'Unknown sync failure.' );
		}
		$code     = (string) $error->get_error_code();
		$category = self::category( $code );
		return self::record( $code, $category, self::retryable( $code ), self::remediation( $category ), $error->get_error_message() );
	}

	/** Classifies an error code without requiring a WP_Error (unit-testable). */
	public static function category( $code ) {
		$code = strtolower( (string) $code );
		if ( preg_match( '/^(not_connected|no_token|unauthorized|auth_|token_|pair|http_401|http_403)/', $code ) ) {
			return 'authentication';
		}
		if ( preg_match( '/^(curl|curl_multi|http_request_failed|http_408|http_425|http_429|http_5\d\d|manifest_download|response_empty)/', $code ) ) {
			return 'transport';
		}
		if ( preg_match( '/(capacity|disk_space|packet|memory|too_large|_limit$|manifest_count|manifest_size)/', $code ) ) {
			return 'capacity';
		}
		if ( preg_match( '/(protocol|compat|unsupported|multisite|db_version|identity_group|primary_key|foreign_key)/', $code ) ) {
			return 'compatibility';
		}
		if ( preg_match( '/(checksum|hash|validation|truncated|corrupt|mismatch|source_changed|source_unstable|file_range|bad_json|manifest_data|manifest_line)/', $code ) ) {
			return 'integrity';
		}
		if ( preg_match( '/(not_a_sandbox|is_a_source|self_sync|unsafe|symlink|escape|guard|direction|insecure_source)/', $code ) ) {
			return 'safety';
		}
		if ( preg_match( '/(job_running|job_start_busy|lease|cleanup_busy|lock|session|http_409|http_423|http_416)/', $code ) ) {
			return 'concurrency';
		}
		if ( preg_match( '/(mkdir|_open|_read|_write|_flush|_move|_rename|_copy|_path|_file|_journal|tmp_|stage_|archive_cache|backup_cleanup|maintenance_mode)/', $code ) ) {
			return 'filesystem';
		}
		if ( preg_match( '/(db_|sql|table|query|import|shadow|swap|rollback|option_preserve|user_copy|prefix_map)/', $code ) ) {
			return 'database';
		}
		if ( preg_match( '/(config|environment|missing|invalid|bad_step|request_too_large)/', $code ) ) {
			return 'configuration';
		}
		if ( 'job_cancelled' === $code ) {
			return 'cancelled';
		}
		return 'internal';
	}

	/** Whether the same operation is safe and useful to retry automatically. */
	public static function retryable( $code ) {
		$code = strtolower( (string) $code );
		return in_array( $code, array( 'curl', 'curl_multi', 'http_request_failed', 'http_408', 'http_425', 'http_429', 'response_empty' ), true )
			|| (bool) preg_match( '/^http_5\d\d$/', $code );
	}

	private static function remediation( $category ) {
		switch ( $category ) {
			case 'authentication':
				return 'Verify the source URL and credential expiry, then re-pair the sandbox if needed.';
			case 'transport':
				return 'Retry after checking source availability, DNS/TLS, proxy limits, and request timeouts.';
			case 'capacity':
				return 'Free staging/database capacity or raise the explicitly documented platform limit, then retry.';
			case 'compatibility':
				return 'Upgrade both endpoints or migrate/exclude the reported unsupported object before retrying.';
			case 'integrity':
				return 'Keep the source stable, discard partial work with cleanup, and retry; investigate storage/network corruption if it repeats.';
			case 'safety':
				return 'Verify source/target roles and the named safety precondition; do not bypass it without an independent control.';
			case 'concurrency':
				return 'Wait for the owning job/session to finish or expire; use the documented recovery cleanup only after confirming it is stale.';
			case 'filesystem':
				return 'Check wp-content ownership, permissions, free space, and same-filesystem staging requirements.';
			case 'database':
				return 'Check database privileges, engine health, server limits, and the detailed database error before retrying.';
			case 'configuration':
				return 'Correct the reported configuration or environment prerequisite and rerun preflight.';
			case 'cancelled':
				return 'No action is required unless rollback reported a separate failure.';
			default:
				return 'Inspect the job log and application logs, correct the underlying cause, then retry.';
		}
	}

	private static function record( $code, $category, $retryable, $remediation, $message ) {
		return array(
			'code'        => (string) $code,
			'category'    => (string) $category,
			'retryable'   => (bool) $retryable,
			'message'     => (string) $message,
			'remediation' => (string) $remediation,
		);
	}
}
