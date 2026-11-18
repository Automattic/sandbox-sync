<?php
/**
 * Pairing + bearer-token auth between the live (source) site and its sandboxes.
 *
 * A source site can be paired with MULTIPLE sandboxes: it keeps a registry of
 * bearer-token hashes (one per sandbox), each with the sandbox URL and pairing
 * timestamp. Only SHA-256 hashes are stored on the source (pairing key and
 * bearer tokens), the same approach the Knife uses for its uploads-export
 * endpoint. Each sandbox stores its own cleartext token because it must send
 * it on every request.
 */

namespace SandboxSync;

defined( 'ABSPATH' ) || exit;

class Auth {
	// Source-side options.
	const OPT_PAIRING_KEY_HASH    = 'sandbox_sync_pairing_key_hash';
	const OPT_PAIRING_KEY_CREATED = 'sandbox_sync_pairing_key_created';
	const OPT_PAIRING_RECORD      = 'sandbox_sync_pairing_record';
	// Registry of paired sandboxes: [ token_hash => [ 'url' => ..., 'paired_at' => ... ] ].
	const OPT_SANDBOXES = 'sandbox_sync_sandboxes';

	// Legacy source-side options (single-sandbox era). Migrated on read.
	const LEGACY_OPT_TOKEN_HASH  = 'sandbox_sync_token_hash';
	const LEGACY_OPT_SANDBOX_URL = 'sandbox_sync_sandbox_url';
	const LEGACY_OPT_PAIRED_AT   = 'sandbox_sync_paired_at';

	// Sandbox-side options.
	const OPT_SOURCE_URL    = 'sandbox_sync_source_url';
	const OPT_TOKEN         = 'sandbox_sync_token';
	const OPT_TOKEN_EXPIRES = 'sandbox_sync_token_expires';
	// Explicit role flag: a site refuses to run a sync into itself unless
	// an admin marked it as a sandbox (direction guardrail).
	const OPT_IS_SANDBOX = 'sandbox_sync_is_sandbox';

	// Default bearer-token lifetime (filterable via sandbox_sync_token_ttl).
	const TOKEN_TTL            = 2592000; // 30 days.
	const TOKEN_EXPIRY_WARNING = 604800; // 7 days.
	const PAIRING_KEY_TTL      = 900; // 15 minutes.

	// Brute-force guard for the export endpoints.
	const FAIL_LIMIT  = 10;
	const FAIL_WINDOW = 600; // Seconds.

	/**
	 * Source side: generate a one-time pairing key. Only its hash is stored;
	 * the cleartext is returned once so the admin can copy it to the sandbox.
	 * One pending key at a time: generate one key per sandbox you pair.
	 */
	public static function generate_pairing_key() {
		$key    = bin2hex( random_bytes( 20 ) );
		$record = array(
			'hash'       => hash( 'sha256', $key ),
			'created_at' => time(),
		);
		update_option( self::OPT_PAIRING_RECORD, $record, false );
		// Mirrors support a rolling upgrade from the pre-CAS protocol.
		update_option( self::OPT_PAIRING_KEY_HASH, $record['hash'], false );
		update_option( self::OPT_PAIRING_KEY_CREATED, $record['created_at'], false );
		return $key;
	}

	/**
	 * Source side: verify and consume (single use) the pairing key.
	 */
	public static function consume_pairing_key( $key ) {
		$stored_record = get_option( self::OPT_PAIRING_RECORD );
		$canonical     = is_array( $stored_record );
		$record        = $canonical ? $stored_record : array(
			'hash'       => get_option( self::OPT_PAIRING_KEY_HASH ),
			'created_at' => (int) get_option( self::OPT_PAIRING_KEY_CREATED ),
		);
		$expected      = isset( $record['hash'] ) ? $record['hash'] : '';
		$created       = isset( $record['created_at'] ) ? (int) $record['created_at'] : 0;
		if ( ! is_string( $expected ) || '' === $expected || ! is_string( $key ) || '' === $key ) {
			return false;
		}
		if ( $created <= 0 || ( time() - $created ) > self::PAIRING_KEY_TTL ) {
			self::compare_and_delete_option( self::OPT_PAIRING_RECORD, $record );
			delete_option( self::OPT_PAIRING_KEY_HASH );
			delete_option( self::OPT_PAIRING_KEY_CREATED );
			return false;
		}
		if ( ! hash_equals( $expected, hash( 'sha256', $key ) ) ) {
			return false;
		}
		$consumed = $canonical
			? self::compare_and_delete_option( self::OPT_PAIRING_RECORD, $record )
			: self::compare_and_delete_option( self::OPT_PAIRING_KEY_HASH, $expected );
		if ( ! $consumed ) {
			return false;
		}
		delete_option( self::OPT_PAIRING_KEY_HASH );
		delete_option( self::OPT_PAIRING_KEY_CREATED );
		return true;
	}

	/**
	 * Source side: the registry of paired sandboxes.
	 *
	 * Transparently migrates legacy single-sandbox development options into
	 * the current registry.
	 *
	 * @return array [ token_hash => [ 'url' => string, 'paired_at' => int ] ]
	 */
	public static function get_sandboxes() {
		$sandboxes = get_option( self::OPT_SANDBOXES );
		$sandboxes = is_array( $sandboxes ) ? $sandboxes : array();
		$dirty     = false;

		$legacy_hash = get_option( self::LEGACY_OPT_TOKEN_HASH );
		if ( is_string( $legacy_hash ) && '' !== $legacy_hash ) {
			$sandboxes[ $legacy_hash ] = array(
				'url'        => (string) get_option( self::LEGACY_OPT_SANDBOX_URL ),
				'paired_at'  => (int) get_option( self::LEGACY_OPT_PAIRED_AT ),
				'expires_at' => time() + self::token_ttl(),
			);
			$dirty                     = true;
			delete_option( self::LEGACY_OPT_TOKEN_HASH );
			delete_option( self::LEGACY_OPT_SANDBOX_URL );
			delete_option( self::LEGACY_OPT_PAIRED_AT );
		}

		// Prune expired tokens. Entries without expires_at (pre-TTL era) get
		// one, counted from now.
		foreach ( $sandboxes as $token_hash => $info ) {
			if ( empty( $info['expires_at'] ) ) {
				$sandboxes[ $token_hash ]['expires_at'] = time() + self::token_ttl();
				$dirty                                  = true;
			} elseif ( (int) $info['expires_at'] < time() ) {
				unset( $sandboxes[ $token_hash ] );
				$dirty = true;
			}
		}

		if ( $dirty ) {
			update_option( self::OPT_SANDBOXES, $sandboxes, false );
		}

		return $sandboxes;
	}

	/**
	 * Bearer-token lifetime in seconds.
	 */
	public static function token_ttl() {
		$ttl = self::TOKEN_TTL;
		if ( function_exists( 'apply_filters' ) ) {
			$ttl = (int) apply_filters( 'sandbox_sync_token_ttl', $ttl );
		}
		return max( 3600, $ttl );
	}

	/**
	 * Source side: issue a bearer token for a newly paired sandbox and add it
	 * to the registry. Returns the cleartext token (sent once, over the
	 * handshake response).
	 */
	public static function issue_token( $sandbox_url ) {
		$token = bin2hex( random_bytes( 32 ) );

		$sandboxes      = self::get_sandboxes();
		$normalized_url = esc_url_raw( untrailingslashit( $sandbox_url ) );
		// Re-pairing the same sandbox rotates rather than accumulating live
		// credentials for one receiver.
		foreach ( $sandboxes as $hash => $info ) {
			if ( isset( $info['url'] ) && untrailingslashit( $info['url'] ) === $normalized_url ) {
				unset( $sandboxes[ $hash ] );
			}
		}
		$sandboxes[ hash( 'sha256', $token ) ] = array(
			'url'        => $normalized_url,
			'paired_at'  => time(),
			'expires_at' => time() + self::token_ttl(),
		);
		update_option( self::OPT_SANDBOXES, $sandboxes, false );

		return $token;
	}

	/**
	 * Source side: validate the Authorization: Bearer header of the current
	 * request against the sandbox registry.
	 */
	public static function verify_bearer_request( $record_usage = true ) {
		$provided = self::get_bearer_token();
		if ( '' === $provided ) {
			return false;
		}
		$provided_hash = hash( 'sha256', $provided );

		$sandboxes = self::get_sandboxes();
		foreach ( $sandboxes as $token_hash => $info ) {
			if ( is_string( $token_hash ) && hash_equals( $token_hash, $provided_hash ) ) {
				if ( $record_usage && ( empty( $info['last_used_at'] ) || ( time() - (int) $info['last_used_at'] ) >= 300 ) ) {
					$sandboxes[ $token_hash ]['last_used_at'] = time();
					$sandboxes[ $token_hash ]['last_ip']      = isset( $_SERVER['REMOTE_ADDR'] ) ? substr( preg_replace( '/[^0-9a-fA-F:.]/', '', (string) $_SERVER['REMOTE_ADDR'] ), 0, 64 ) : '';
					update_option( self::OPT_SANDBOXES, $sandboxes, false );
				}
				return true;
			}
		}
		return false;
	}

	/**
	 * Reads the bearer token from the request headers (covers the common
	 * SAPI variations, like the Knife's export endpoint does).
	 */
	public static function get_bearer_token() {
		$auth_header = '';
		if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$auth_header = $_SERVER['HTTP_AUTHORIZATION'];
		} elseif ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$auth_header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
		} elseif ( function_exists( 'getallheaders' ) ) {
			foreach ( getallheaders() as $name => $value ) {
				if ( 0 === strcasecmp( $name, 'Authorization' ) ) {
					$auth_header = $value;
					break;
				}
			}
		}
		return ( 0 === stripos( $auth_header, 'Bearer ' ) ) ? substr( $auth_header, 7 ) : '';
	}

	/**
	 * Source side: is at least one sandbox paired?
	 */
	public static function is_source_paired() {
		return ! empty( self::get_sandboxes() );
	}

	/**
	 * Source side: revoke one sandbox by its token hash.
	 *
	 * @return bool Whether the sandbox existed and was revoked.
	 */
	public static function revoke_sandbox( $token_hash ) {
		$sandboxes = self::get_sandboxes();
		if ( ! isset( $sandboxes[ $token_hash ] ) ) {
			return false;
		}
		unset( $sandboxes[ $token_hash ] );
		update_option( self::OPT_SANDBOXES, $sandboxes, false );
		return true;
	}

	/**
	 * Source side: revoke every sandbox and any pending pairing key.
	 */
	public static function revoke_source() {
		delete_option( self::OPT_PAIRING_RECORD );
		delete_option( self::OPT_PAIRING_KEY_HASH );
		delete_option( self::OPT_PAIRING_KEY_CREATED );
		delete_option( self::OPT_SANDBOXES );
		delete_option( self::LEGACY_OPT_TOKEN_HASH );
		delete_option( self::LEGACY_OPT_SANDBOX_URL );
		delete_option( self::LEGACY_OPT_PAIRED_AT );
	}

	/**
	 * Sandbox side: persist the connection to the source site.
	 */
	public static function save_connection( $source_url, $token, $expires_at = 0 ) {
		$url    = esc_url_raw( untrailingslashit( $source_url ) );
		$sealed = self::seal_token( $token, $url );
		if ( is_wp_error( $sealed ) ) {
			return $sealed;
		}
		update_option( self::OPT_SOURCE_URL, $url, false );
		update_option( self::OPT_TOKEN, $sealed, false );
		if ( $expires_at > 0 ) {
			update_option( self::OPT_TOKEN_EXPIRES, (int) $expires_at, false );
		} else {
			delete_option( self::OPT_TOKEN_EXPIRES );
		}
		return true;
	}

	/**
	 * Sandbox side: returns [ 'source_url' => ..., 'token' => ... ] or null.
	 */
	public static function get_connection() {
		$url    = get_option( self::OPT_SOURCE_URL );
		$stored = get_option( self::OPT_TOKEN );
		if ( ! $url || ! $stored ) {
			return null;
		}
		if ( is_string( $stored ) ) {
			// One-time migration from the early cleartext development option.
			$token  = $stored;
			$sealed = self::seal_token( $token, $url );
			if ( ! is_wp_error( $sealed ) ) {
				update_option( self::OPT_TOKEN, $sealed, false );
			}
		} else {
			$token = self::unseal_token( $stored, $url );
			if ( is_wp_error( $token ) ) {
				return null;
			}
		}
		return array(
			'source_url' => $url,
			'token'      => $token,
			'expires_at' => (int) get_option( self::OPT_TOKEN_EXPIRES ),
		);
	}

	private static function token_key() {
		if ( ! function_exists( 'wp_salt' ) || ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'openssl_decrypt' ) ) {
			return null;
		}
		return hash( 'sha256', wp_salt( 'auth' ), true );
	}

	/** @return array|\WP_Error */
	private static function seal_token( $token, $url ) {
		$key = self::token_key();
		if ( null === $key ) {
			return new \WP_Error( 'token_encryption', 'OpenSSL and WordPress salts are required to store the source token securely.' );
		}
		$iv     = random_bytes( 12 );
		$tag    = '';
		$cipher = openssl_encrypt( (string) $token, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, (string) $url );
		if ( false === $cipher ) {
			return new \WP_Error( 'token_encryption', 'Could not encrypt the source token.' );
		}
		return array(
			'v'      => 1,
			'iv'     => base64_encode( $iv ),
			'tag'    => base64_encode( $tag ),
			'cipher' => base64_encode( $cipher ),
		);
	}

	/** @return string|\WP_Error */
	private static function unseal_token( $sealed, $url ) {
		$key = self::token_key();
		if ( null === $key || ! is_array( $sealed ) || 1 !== (int) ( $sealed['v'] ?? 0 ) ) {
			return new \WP_Error( 'token_decryption', 'Stored source token is unavailable or uses an unsupported format.' );
		}
		$iv     = base64_decode( (string) ( $sealed['iv'] ?? '' ), true );
		$tag    = base64_decode( (string) ( $sealed['tag'] ?? '' ), true );
		$cipher = base64_decode( (string) ( $sealed['cipher'] ?? '' ), true );
		if ( false === $iv || false === $tag || false === $cipher ) {
			return new \WP_Error( 'token_decryption', 'Stored source token is corrupt.' );
		}
		$plain = openssl_decrypt( $cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, (string) $url );
		return false === $plain ? new \WP_Error( 'token_decryption', 'Stored source token could not be decrypted.' ) : $plain;
	}

	/**
	 * Sandbox side: forget the connection.
	 */
	public static function disconnect() {
		delete_option( self::OPT_SOURCE_URL );
		delete_option( self::OPT_TOKEN );
		delete_option( self::OPT_TOKEN_EXPIRES );
		delete_option( 'sandbox_sync_source_freeze_id' );
		delete_option( 'sandbox_sync_transfer_id' );
		delete_option( 'sandbox_sync_transfer_renewed_at' );
	}

	// -- Direction guardrail ----------------------------------------------------

	/**
	 * Has an admin explicitly marked this site as a sandbox?
	 */
	public static function is_sandbox_site() {
		return (bool) get_option( self::OPT_IS_SANDBOX );
	}

	public static function set_sandbox_flag( $enabled ) {
		if ( $enabled ) {
			update_option( self::OPT_IS_SANDBOX, 1, false );
		} else {
			delete_option( self::OPT_IS_SANDBOX );
		}
	}

	// -- Brute-force guard --------------------------------------------------------

	private static function fail_key( $bucket_offset = 0 ) {
		$ip       = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
		$identity = (string) apply_filters( 'sandbox_sync_auth_rate_identity', $ip, $_SERVER );
		$bucket   = (int) floor( time() / self::FAIL_WINDOW ) + (int) $bucket_offset;
		return 'sandbox_sync_authfail_' . md5( $identity . '|' . $bucket );
	}

	/**
	 * Has this client exceeded the failed-auth budget?
	 */
	public static function is_rate_limited() {
		global $wpdb;
		if ( is_object( $wpdb ) && ! empty( $wpdb->options ) ) {
			return (int) get_option( self::fail_key(), 0 ) >= self::FAIL_LIMIT;
		}
		return (int) get_transient( self::fail_key() ) >= self::FAIL_LIMIT;
	}

	/**
	 * Records a failed auth attempt for the current client.
	 */
	public static function record_auth_failure() {
		global $wpdb;
		$key = self::fail_key();
		if ( is_object( $wpdb ) && ! empty( $wpdb->options ) ) {
			if ( ! add_option( $key, 1, '', false ) ) {
				// One atomic SQL increment avoids lost updates from concurrent auth
				// failures; time-bucketed names provide expiry without a race-prone
				// read/modify/write transient.
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$wpdb->options} SET option_value = CAST(option_value AS UNSIGNED) + 1 WHERE option_name = %s",
						$key
					)
				);
				wp_cache_delete( $key, 'options' );
			}
			delete_option( self::fail_key( -2 ) );
			return;
		}
		$count = (int) get_transient( $key );
		set_transient( $key, $count + 1, self::FAIL_WINDOW );
	}

	public static function clear_auth_failures() {
		global $wpdb;
		if ( is_object( $wpdb ) && ! empty( $wpdb->options ) ) {
			delete_option( self::fail_key() );
			delete_option( self::fail_key( -1 ) );
			return;
		}
		delete_transient( self::fail_key() );
	}

	/**
	 * Option names the sandbox must carry over into the synced database so it
	 * stays connected + mid-sync state survives the table swap.
	 */
	public static function preserved_option_names() {
		return array(
			self::OPT_SOURCE_URL,
			self::OPT_TOKEN,
			self::OPT_TOKEN_EXPIRES,
			self::OPT_IS_SANDBOX,
			Safety::OPT_BLOCK_EMAIL,
			Safety::OPT_BLOCK_HTTP,
			Job::OPT,
			Job::SLICE_LOCK,
			Job::CANCEL_OPT,
			Sync_Runner::OPT_TABLE_STATE,
			Sync_Runner::OPT_LAST_REPORT,
			Sync_Runner::OPT_HISTORY,
			Sync_Runner::OPT_SYNCED_PLUGINS,
			Sync_Runner::OPT_SYNCED_THEMES,
			Uploads_State::OPT_CURSOR,
			'sandbox_sync_source_freeze_id',
			'sandbox_sync_transfer_id',
			'sandbox_sync_transfer_renewed_at',
		);
	}

	/**
	 * Source-role options that must NOT leak from live into the sandbox DB
	 * (the sandbox should not think it is a paired source after a sync).
	 */
	public static function source_role_option_names() {
		return array(
			self::OPT_PAIRING_RECORD,
			self::OPT_PAIRING_KEY_HASH,
			self::OPT_PAIRING_KEY_CREATED,
			self::OPT_SANDBOXES,
			self::LEGACY_OPT_TOKEN_HASH,
			self::LEGACY_OPT_SANDBOX_URL,
			self::LEGACY_OPT_PAIRED_AT,
		);
	}

	private static function compare_and_delete_option( $name, $expected ) {
		global $wpdb;
		if ( is_object( $wpdb ) && ! empty( $wpdb->options ) && function_exists( 'maybe_serialize' ) ) {
			$deleted = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
					$name,
					maybe_serialize( $expected )
				)
			);
			if ( 1 === $deleted ) {
				wp_cache_delete( $name, 'options' );
				return true;
			}
			return false;
		}
		if ( get_option( $name ) !== $expected ) {
			return false;
		}
		return delete_option( $name );
	}
}
