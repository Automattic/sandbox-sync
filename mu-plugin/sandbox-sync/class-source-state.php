<?php
/** Crash-safe, process-locked source transfer state files. */

namespace SandboxSync;

defined( 'ABSPATH' ) || exit;

class Source_State {

	const PREFIX = "<?php exit; ?>\n";

	/** @return array|null|\WP_Error */
	public static function read( $path ) {
		return self::transaction(
			$path,
			function ( $state ) {
				return array(
					'state'   => $state,
					'value'   => $state,
					'persist' => false,
				);
			}
		);
	}

	/**
	 * Atomically reads, validates, and optionally replaces one state file.
	 * Callback return: [ 'state' => array|null, 'value' => mixed ] or WP_Error.
	 */
	public static function transaction( $path, $callback ) {
		if ( ! self::safe_path( $path ) || ! is_callable( $callback ) ) {
			return new \WP_Error( 'source_state_path', 'Source state path is unsafe.' );
		}
		$lock = fopen( WP_CONTENT_DIR . '/.sandbox-sync-source-state.lock', 'c' );
		if ( ! $lock || ! flock( $lock, LOCK_EX ) ) {
			if ( $lock ) {
				fclose( $lock );
			}
			return new \WP_Error( 'source_state_lock', 'Could not lock source transfer state.' );
		}
		@chmod( WP_CONTENT_DIR . '/.sandbox-sync-source-state.lock', 0600 );
		try {
			$state = self::read_unlocked( $path );
			if ( is_wp_error( $state ) ) {
				return $state;
			}
			$change = call_user_func( $callback, $state );
			if ( is_wp_error( $change ) ) {
				return $change;
			}
			if ( ! is_array( $change ) || ! array_key_exists( 'state', $change ) || ! array_key_exists( 'value', $change )
				|| ( null !== $change['state'] && ! is_array( $change['state'] ) ) ) {
				return new \WP_Error( 'source_state_change', 'Source state mutation returned an invalid result.' );
			}
			if ( isset( $change['persist'] ) && false === $change['persist'] ) {
				return $change['value'];
			}
			if ( null === $change['state'] ) {
				if ( is_file( $path ) && ! unlink( $path ) ) {
					return new \WP_Error( 'source_state_delete', 'Could not delete source transfer state.' );
				}
			} else {
				$written = self::write_unlocked( $path, $change['state'] );
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

	/** Removes bounded, expired transfer artifacts whenever a new grant begins. */
	public static function cleanup_expired_transfers() {
		$lock = fopen( WP_CONTENT_DIR . '/.sandbox-sync-source-state.lock', 'c' );
		if ( ! $lock || ! flock( $lock, LOCK_EX ) ) {
			if ( $lock ) {
				fclose( $lock );
			}
			return new \WP_Error( 'source_state_lock', 'Could not lock source transfer state for cleanup.' );
		}
		$removed = 0;
		try {
			$files = glob( WP_CONTENT_DIR . '/.sandbox-sync-transfer-*.php' );
			$files = false === $files ? array() : array_slice( $files, 0, 10000 );
			foreach ( $files as $file ) {
				if ( ! preg_match( '/^\.sandbox-sync-transfer-[a-f0-9]{64}\.php$/', basename( $file ) ) ) {
					continue;
				}
				$state = self::read_unlocked( $file );
				if ( null === $state && ! is_file( $file ) ) {
					++$removed;
				}
			}
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
		return $removed;
	}

	/** @return array|null|\WP_Error */
	private static function read_unlocked( $path ) {
		if ( ! is_file( $path ) ) {
			return null;
		}
		if ( is_link( $path ) ) {
			return new \WP_Error( 'source_state_symlink', 'Source transfer state must not be a symlink.' );
		}
		$raw = file_get_contents( $path );
		if ( false === $raw || 0 !== strpos( $raw, self::PREFIX ) ) {
			return new \WP_Error( 'source_state_corrupt', 'Source transfer state is unreadable or malformed.' );
		}
		$state = json_decode( substr( $raw, strlen( self::PREFIX ) ), true );
		if ( ! is_array( $state ) || empty( $state['expires_at'] ) ) {
			return new \WP_Error( 'source_state_corrupt', 'Source transfer state is incomplete.' );
		}
		if ( (int) $state['expires_at'] <= time() ) {
			if ( ! unlink( $path ) && is_file( $path ) ) {
				return new \WP_Error( 'source_state_delete', 'Expired source transfer state could not be removed.' );
			}
			return null;
		}
		return $state;
	}

	/** @return true|\WP_Error */
	private static function write_unlocked( $path, $state ) {
		$json = wp_json_encode( $state );
		if ( false === $json ) {
			return new \WP_Error( 'source_state_write', 'Source transfer state could not be JSON encoded.' );
		}
		$data = self::PREFIX . $json;
		$tmp  = $path . '.' . bin2hex( random_bytes( 8 ) ) . '.tmp';
		$fh   = fopen( $tmp, 'xb' );
		if ( ! $fh ) {
			return new \WP_Error( 'source_state_write', 'Could not create temporary source transfer state.' );
		}
		$written = 0;
		while ( $written < strlen( $data ) ) {
			$count = fwrite( $fh, substr( $data, $written ) );
			if ( false === $count || 0 === $count ) {
				fclose( $fh );
				@unlink( $tmp );
				return new \WP_Error( 'source_state_write', 'Short write while saving source transfer state.' );
			}
			$written += $count;
		}
		$flushed = fflush( $fh );
		if ( function_exists( 'fsync' ) ) {
			$flushed = @fsync( $fh ) && $flushed;
		}
		fclose( $fh );
		@chmod( $tmp, 0600 );
		if ( ! $flushed || ! rename( $tmp, $path ) ) {
			@unlink( $tmp );
			return new \WP_Error( 'source_state_write', 'Could not atomically publish source transfer state.' );
		}
		return true;
	}

	private static function safe_path( $path ) {
		$base = rtrim( WP_CONTENT_DIR, DIRECTORY_SEPARATOR );
		return is_string( $path ) && dirname( $path ) === $base
			&& (bool) preg_match( '/^\.sandbox-sync-(source-freeze|transfer-[a-f0-9]{64})\.php$/', basename( $path ) );
	}
}
