<?php
/**
 * Environment and target-host detection for local-development safeguards.
 */

namespace SandboxSync;

defined( 'ABSPATH' ) || exit;

class Env {

	/**
	 * Is this WordPress running in a local/dev environment?
	 */
	public static function is_local() {
		if ( function_exists( 'wp_get_environment_type' )
			&& in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) {
			return true;
		}
		if ( function_exists( 'home_url' ) ) {
			$host = parse_url( home_url(), PHP_URL_HOST );
			if ( self::is_local_host( $host ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Does a hostname look like a local dev host?
	 */
	public static function is_local_host( $host ) {
		if ( ! is_string( $host ) || '' === $host ) {
			return false;
		}
		$host = strtolower( $host );

		if ( 'localhost' === $host || '::1' === $host || '[::1]' === $host ) {
			return true;
		}
		if ( preg_match( '/^127\.\d{1,3}\.\d{1,3}\.\d{1,3}$/', $host )
			|| preg_match( '/^10\.\d{1,3}\.\d{1,3}\.\d{1,3}$/', $host )
			|| preg_match( '/^192\.168\.\d{1,3}\.\d{1,3}$/', $host ) ) {
			return true;
		}
		foreach ( array( '.local', '.test', '.localhost', '.ddev.site', '.lndo.site' ) as $suffix ) {
			if ( strlen( $host ) > strlen( $suffix ) && substr( $host, -strlen( $suffix ) ) === $suffix ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Should TLS certificates be verified when talking to $url?
	 *
	 * Verification is ON unless the destination itself is an explicitly local
	 * development host. The caller's environment never weakens verification of
	 * a public source. Overridable via the sandbox_sync_sslverify filter.
	 */
	public static function ssl_verify( $url ) {
		$target_host = parse_url( (string) $url, PHP_URL_HOST );
		// Certificate validity is a property of the target. A local sandbox
		// pulling from a public source must still verify that public source's
		// certificate; using the caller's environment here would expose the
		// pairing key, bearer token and all transferred data to interception.
		$verify = ! self::is_local_host( $target_host );

		if ( function_exists( 'apply_filters' ) ) {
			$verify = (bool) apply_filters( 'sandbox_sync_sslverify', $verify, $url );
		}
		return $verify;
	}

	/**
	 * Is a source URL safe enough to carry pairing credentials and site data?
	 *
	 * Public sources must use HTTPS. Plain HTTP is accepted only for an
	 * explicitly local target, and can be overridden for unusual development
	 * environments through a deliberately named filter.
	 */
	public static function is_allowed_source_url( $url ) {
		$parts  = parse_url( (string) $url );
		$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : '';
		$host   = isset( $parts['host'] ) ? $parts['host'] : '';

		$allowed = '' !== $host && ( 'https' === $scheme || ( 'http' === $scheme && self::is_local_host( $host ) ) );
		if ( function_exists( 'apply_filters' ) ) {
			$allowed = (bool) apply_filters( 'sandbox_sync_allow_insecure_source_url', $allowed, $url );
		}
		return $allowed;
	}
}
