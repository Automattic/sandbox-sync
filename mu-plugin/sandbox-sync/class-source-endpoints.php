<?php
/**
 * Source-side (live) HTTP endpoints, addressed as ?sandbox_sync=<action>.
 *
 * Handled at mu-plugin load time (before regular plugins/themes) so nothing
 * can pollute the streamed output — same reason the Knife's export mu-plugin
 * runs without a WordPress hook.
 *
 * Actions:
 *   handshake       POST, authenticated by one-time pairing key. Issues bearer token.
 *   manifest        GET, bearer. Site info + table manifest + syncable dirs.
 *   checksums       POST, bearer. Exact checksums for a bounded table set.
 *   table           GET, bearer. One table chunk (keyset cursor, gzipped SQL).
 *   tar             GET/POST, bearer. (Gzipped) tar stream of one wp-content dir.
 *   file            GET, bearer. One bounded raw-file range for resumable downloads.
 *   files_manifest  GET, bearer. File list (path/size/mtime) of one dir.
 *   progress        GET, bearer. Bounded live progress for manifest traversal.
 *   constants       GET names; POST exact selected values under a transfer grant.
 *   plugins         GET, bearer. Installed plugins (for selective sync).
 *   uploads_scopes  GET, bearer. Year/month layout of uploads/ (scoped sync).
 */

namespace SandboxSync;

defined( 'ABSPATH' ) || exit;

class Source_Endpoints {

	const SYNCABLE_DIRS = array( 'plugins', 'mu-plugins', 'themes', 'uploads' );
	// Transfer grants are bearer-bound and limited to the job's exact selections.
	// Keep their idle lease long enough to survive a missed WP-Cron window; every
	// authenticated transfer request still slides the expiry forward.
	const TRANSFER_TTL  = 43200; // 12 hours.
	const FILE_CHUNK    = 16777216; // 16 MiB per resumable response.
	const HANDSHAKE_MAX = 16384; // Pairing key + sandbox URL should remain tiny.

	public static function maybe_handle_request() {
		if ( empty( $_GET['sandbox_sync'] ) ) {
			Safety::enforce_activation_guard();
			return;
		}

		$action = preg_replace( '/[^a-z_]/', '', (string) $_GET['sandbox_sync'] );

		// Pairing uses a low-entropy human-entered one-time code, so rate-limit it
		// before verification. High-entropy bearer requests verify first: an
		// attacker sharing a NAT cannot lock out a valid long-running transfer.
		if ( 'handshake' === $action && Auth::is_rate_limited() ) {
			self::respond_json( array( 'error' => 'Too many failed attempts. Try again later.' ), 429 );
		}

		if ( 'handshake' === $action ) {
			self::handle_handshake();
		}

		if ( ! in_array( $action, array( 'manifest', 'checksums', 'table', 'table_dump', 'tar', 'file', 'file_hashes', 'files_manifest', 'uploads_state', 'uploads_totals', 'progress', 'constants', 'plugins', 'uploads_scopes', 'transfer' ), true ) ) {
			self::respond_json( array( 'error' => 'Unknown action.' ), 400 );
		}
		$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
		if ( ( in_array( $action, array( 'tar', 'constants' ), true ) && ! in_array( $method, array( 'GET', 'POST' ), true ) )
			|| ( in_array( $action, array( 'checksums', 'file_hashes', 'uploads_totals', 'transfer' ), true ) && 'POST' !== $method )
			|| ( ! in_array( $action, array( 'tar', 'constants', 'checksums', 'file_hashes', 'uploads_totals', 'transfer' ), true ) && 'GET' !== $method ) ) {
			self::respond_json( array( 'error' => 'Method not allowed for this action.' ), 405 );
		}

		if ( ! Auth::verify_bearer_request() ) {
			Auth::record_auth_failure();
			if ( Auth::is_rate_limited() ) {
				self::respond_json( array( 'error' => 'Too many failed attempts. Try again later.' ), 429 );
			}
			self::respond_json( array( 'error' => 'Unauthorized.' ), 401 );
		}
		Auth::clear_auth_failures();
		if ( 'transfer' === $action ) {
			self::handle_transfer();
		}
		$transfer = null;
		if ( in_array( $action, array( 'manifest', 'checksums', 'table', 'table_dump', 'tar', 'file', 'file_hashes', 'files_manifest', 'uploads_state', 'uploads_totals', 'progress' ), true ) || ( 'constants' === $action && 'POST' === $method ) ) {
			$transfer = self::authorize_transfer( $action );
		}

		switch ( $action ) {
			case 'manifest':
				self::handle_manifest( $transfer );
				break;
			case 'checksums':
				self::handle_checksums( $transfer );
				break;
			case 'files_manifest':
				self::handle_files_manifest();
				break;
			case 'file_hashes':
				self::handle_file_hashes();
				break;
			case 'uploads_state':
				self::handle_uploads_state();
				break;
			case 'uploads_totals':
				self::handle_uploads_totals();
				break;
			case 'progress':
				self::handle_progress( $transfer );
				break;
			case 'file':
				self::handle_file();
				break;
			case 'constants':
				self::handle_constants( $transfer );
				break;
			case 'plugins':
				self::handle_plugins();
				break;
			case 'uploads_scopes':
				self::handle_uploads_scopes();
				break;
			case 'table':
				self::handle_table( $transfer );
				break;
			case 'table_dump':
				self::handle_table_dump( $transfer );
				break;
			case 'tar':
				self::handle_tar();
				break;
		}
		exit;
	}

	private static function transfer_path( $id ) {
		return WP_CONTENT_DIR . '/.sandbox-sync-transfer-' . hash( 'sha256', (string) $id ) . '.php';
	}

	/**
	 * Local production emergency stop. Marks every active transfer cancelled so
	 * cooperative source loops exit. State files remain briefly so in-flight
	 * requests can observe the cancellation.
	 *
	 * @return array|\WP_Error
	 */
	public static function emergency_stop() {
		$files     = glob( WP_CONTENT_DIR . '/.sandbox-sync-transfer-*.php' );
		$files     = false === $files ? array() : array_slice( $files, 0, 10000 );
		$cancelled = 0;
		foreach ( $files as $file ) {
			if ( ! preg_match( '/^\.sandbox-sync-transfer-[a-f0-9]{64}\.php$/', basename( $file ) ) ) {
				continue;
			}
			$result = Source_State::transaction(
				$file,
				function ( $active ) {
					if ( ! $active ) {
						return array(
							'state' => null,
							'value' => false,
						);
					}
					$active['cancel_requested'] = true;
					$active['expires_at']       = time() + 300;
					if ( isset( $active['progress'] ) && is_array( $active['progress'] ) ) {
						$active['progress']['done']       = true;
						$active['progress']['updated_at'] = time();
					}
					return array(
						'state' => $active,
						'value' => true,
					);
				}
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( $result ) {
				++$cancelled;
			}
		}

		// Versions before 0.12 wrote an application-wide write-freeze file.
		// Current code never reads it, but remove the leftover so nothing
		// stale lingers in wp-content after an upgrade.
		$legacy_freeze = WP_CONTENT_DIR . '/.sandbox-sync-source-freeze.php';
		if ( is_file( $legacy_freeze ) ) {
			@unlink( $legacy_freeze );
		}

		return array( 'transfers_cancelled' => $cancelled );
	}

	private static function handle_transfer() {
		if ( isset( $_SERVER['CONTENT_LENGTH'] ) && (int) $_SERVER['CONTENT_LENGTH'] > 262144 ) {
			self::respond_json( array( 'error' => 'Transfer-session request is too large.' ), 413 );
		}
		$body      = json_decode( (string) file_get_contents( 'php://input' ), true );
		$operation = is_array( $body ) && isset( $body['operation'] ) ? (string) $body['operation'] : '';
		$id        = is_array( $body ) && isset( $body['transfer_id'] ) ? (string) $body['transfer_id'] : '';
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $id ) ) {
			self::respond_json( array( 'error' => 'A client-generated 256-bit transfer ID is required.' ), 400 );
		}
		$token_hash           = hash( 'sha256', Auth::get_bearer_token() );
		$tables               = array();
		$schema_only_tables   = array();
		$schema_only_suffixes = is_array( $body ) && isset( $body['schema_only_suffixes'] ) && is_array( $body['schema_only_suffixes'] ) ? array_values( array_unique( $body['schema_only_suffixes'] ) ) : array();
		if ( count( $schema_only_suffixes ) > 1000 ) {
			self::respond_json( array( 'error' => 'Schema-only suffix scope exceeds the configured limit.' ), 413 );
		}
		foreach ( $schema_only_suffixes as $suffix ) {
			if ( ! is_string( $suffix ) || ! preg_match( '/^[a-z0-9_]+$/', $suffix ) ) {
				self::respond_json( array( 'error' => 'Schema-only suffix scope contains a malformed table suffix.' ), 400 );
			}
		}
		$constants = is_array( $body ) && isset( $body['constants'] ) && is_array( $body['constants'] ) ? array_values( array_unique( $body['constants'] ) ) : array();
		if ( count( $constants ) > 500 ) {
			self::respond_json( array( 'error' => 'Transfer constant scope exceeds the configured limit.' ), 413 );
		}
		foreach ( $constants as $constant ) {
			if ( ! is_string( $constant ) || ! preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', $constant ) || ! Safety::is_offerable_constant( $constant ) ) {
				self::respond_json( array( 'error' => 'Transfer constant scope contains an unsafe or malformed name.' ), 400 );
			}
		}
		if ( 'scope' === $operation ) {
			$tables             = isset( $body['tables'] ) && is_array( $body['tables'] ) ? array_values( array_unique( $body['tables'] ) ) : array();
			$schema_only_tables = isset( $body['schema_only_tables'] ) && is_array( $body['schema_only_tables'] ) ? array_values( array_unique( $body['schema_only_tables'] ) ) : array();
			if ( count( $tables ) > 1000 ) {
				self::respond_json( array( 'error' => 'Transfer table scope exceeds the configured limit.' ), 413 );
			}
			foreach ( $tables as $table ) {
				if ( ! is_string( $table ) || ! preg_match( '/^[A-Za-z0-9_]+$/', $table ) || ! DB_Exporter::is_exportable( $table ) ) {
					self::respond_json( array( 'error' => 'Transfer table scope contains a non-exportable table.' ), 400 );
				}
			}
			foreach ( $schema_only_tables as $table ) {
				if ( ! is_string( $table ) || ! in_array( $table, $tables, true ) ) {
					self::respond_json( array( 'error' => 'Schema-only table scope must be a subset of the transfer table scope.' ), 400 );
				}
			}
		}
		if ( ! in_array( $operation, array( 'begin', 'scope', 'renew', 'cancel', 'end' ), true ) ) {
			self::respond_json( array( 'error' => 'Unknown transfer-session operation.' ), 400 );
		}
		$dirs = is_array( $body ) && isset( $body['dirs'] ) && is_array( $body['dirs'] ) ? array_values( array_unique( $body['dirs'] ) ) : array();
		foreach ( $dirs as $dir ) {
			if ( ! in_array( $dir, self::SYNCABLE_DIRS, true ) ) {
				self::respond_json( array( 'error' => 'Transfer session contains an invalid directory scope.' ), 400 );
			}
		}
		if ( 'begin' === $operation ) {
			$cleaned = Source_State::cleanup_expired_transfers();
			if ( is_wp_error( $cleaned ) ) {
				self::respond_json( array( 'error' => $cleaned->get_error_message() ), 500 );
			}
		}
		$result = Source_State::transaction(
			self::transfer_path( $id ),
			function ( $active ) use ( $operation, $token_hash, $id, $body, $dirs, $tables, $schema_only_tables, $schema_only_suffixes, $constants ) {
				if ( 'renew' === $operation ) {
					if ( ! $active || ! hash_equals( (string) $active['id_hash'], hash( 'sha256', $id ) ) || ! hash_equals( (string) $active['token_hash'], $token_hash ) ) {
						return new \WP_Error( 'transfer_owner', 'A valid scoped transfer session is required.' );
					}
					$active['expires_at'] = time() + self::TRANSFER_TTL;
					return array(
						'state' => $active,
						'value' => array(
							'transfer_id' => $id,
							'renewed'     => true,
							'expires_at'  => $active['expires_at'],
						),
					);
				}
				if ( 'cancel' === $operation ) {
					if ( ! $active || ! hash_equals( (string) $active['token_hash'], $token_hash ) ) {
						return new \WP_Error( 'transfer_owner', 'Transfer-session ownership could not be verified.' );
					}
					$active['cancel_requested'] = true;
					$active['expires_at']       = time() + self::TRANSFER_TTL;
					if ( isset( $active['progress'] ) && is_array( $active['progress'] ) ) {
						$active['progress']['done']       = true;
						$active['progress']['updated_at'] = time();
					}
					return array(
						'state' => $active,
						'value' => array( 'cancel_requested' => true ),
					);
				}
				if ( 'scope' === $operation ) {
					if ( ! $active || ! hash_equals( (string) $active['token_hash'], $token_hash ) || empty( $active['database'] ) ) {
						return new \WP_Error( 'transfer_owner', 'Transfer-session ownership/database scope could not be verified.' );
					}
					$active['tables']             = $tables;
					$active['schema_only_tables'] = $schema_only_tables;
					$active['expires_at']         = time() + self::TRANSFER_TTL;
					return array(
						'state' => $active,
						'value' => array(
							'scoped'                  => true,
							'table_count'             => count( $tables ),
							'schema_only_table_count' => count( $schema_only_tables ),
						),
					);
				}
				if ( 'end' === $operation ) {
					if ( ! $active || ! hash_equals( (string) $active['token_hash'], $token_hash ) ) {
						return new \WP_Error( 'transfer_owner', 'Transfer-session ownership could not be verified.' );
					}
					return array(
						'state' => null,
						'value' => array( 'released' => true ),
					);
				}
				if ( $active && ! hash_equals( (string) $active['token_hash'], $token_hash ) ) {
					return new \WP_Error( 'transfer_exists', 'Transfer-session ID is already owned by another credential.' );
				}
				$state = array(
					'id_hash'             => hash( 'sha256', $id ),
					'token_hash'          => $token_hash,
					'database'            => ! empty( $body['database'] ),
					'tables'              => array(),
					'schema_only_tables'  => array(),
					'schema_only_suffixes' => $schema_only_suffixes,
					'progress'             => null,
					'cancel_requested'     => false,
					'dirs'                => $dirs,
					'constants'           => $constants,
					'created_at'          => $active && isset( $active['created_at'] ) ? (int) $active['created_at'] : time(),
					'expires_at'          => time() + self::TRANSFER_TTL,
				);
				return array(
					'state' => $state,
					'value' => array(
						'transfer_id' => $id,
						'expires_at'  => $state['expires_at'],
					),
				);
			}
		);
		if ( is_wp_error( $result ) ) {
			$status = 'transfer_owner' === $result->get_error_code() ? 403 : ( 'transfer_exists' === $result->get_error_code() ? 423 : 500 );
			self::respond_json(
				array(
					'error' => $result->get_error_message(),
					'code'  => $result->get_error_code(),
				),
				$status
			);
		}
		self::respond_json( $result );
	}

	private static function authorize_transfer( $action ) {
		$id         = isset( $_GET['transfer_id'] ) ? (string) $_GET['transfer_id'] : '';
		$token_hash = hash( 'sha256', Auth::get_bearer_token() );
		$state      = Source_State::transaction(
			self::transfer_path( $id ),
			function ( $active ) use ( $action, $id, $token_hash ) {
				if ( ! $active || ! hash_equals( (string) $active['id_hash'], hash( 'sha256', $id ) ) || ! hash_equals( (string) $active['token_hash'], $token_hash ) ) {
					return new \WP_Error( 'transfer_owner', 'A valid scoped transfer session is required.' );
				}
				if ( in_array( $action, array( 'table', 'table_dump', 'checksums' ), true ) && empty( $active['database'] ) ) {
					return new \WP_Error( 'transfer_scope', 'This transfer session is not authorized for database export.' );
				}
				if ( ! empty( $active['cancel_requested'] ) && 'progress' !== $action ) {
					return new \WP_Error( 'transfer_cancelled', 'The source transfer has been cancelled.' );
				}
				if ( in_array( $action, array( 'tar', 'file', 'file_hashes', 'files_manifest', 'uploads_state', 'uploads_totals' ), true ) ) {
					$dir = isset( $_GET['dir'] ) ? (string) $_GET['dir'] : '';
					if ( in_array( $action, array( 'uploads_state', 'uploads_totals' ), true ) ) {
						$dir = 'uploads';
					}
					if ( ! in_array( $dir, (array) $active['dirs'], true ) ) {
						return new \WP_Error( 'transfer_scope', 'This transfer session is not authorized for the requested directory.' );
					}
				}
				$active['expires_at'] = time() + self::TRANSFER_TTL;
				return array(
					'state' => $active,
					'value' => $active,
				);
			}
		);
		if ( is_wp_error( $state ) ) {
			$status = 'transfer_cancelled' === $state->get_error_code()
				? 409
				: ( in_array( $state->get_error_code(), array( 'transfer_owner', 'transfer_scope' ), true ) ? 403 : 500 );
			self::respond_json(
				array(
					'error' => $state->get_error_message(),
					'code'  => $state->get_error_code(),
				),
				$status
			);
		}
		return $state;
	}

	private static function respond_json( $data, $status = 200 ) {
		http_response_code( $status );
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Cache-Control: no-store, private' );
		header( 'Pragma: no-cache' );
		header( 'X-Content-Type-Options: nosniff' );
		echo wp_json_encode( $data );
		exit;
	}

	private static function site_info() {
		global $wpdb, $wp_version;
		$db_version = (string) $wpdb->get_var( 'SELECT VERSION()' );
		$sql_mode   = (string) $wpdb->get_var( 'SELECT @@SESSION.sql_mode' );
		$packet     = (int) $wpdb->get_var( 'SELECT @@max_allowed_packet' );
		return array(
			'home'               => get_option( 'home' ),
			'siteurl'            => get_option( 'siteurl' ),
			'prefix'             => $wpdb->prefix,
			'charset'            => defined( 'DB_CHARSET' ) ? DB_CHARSET : '',
			'version'            => SANDBOX_SYNC_VERSION,
			'protocol'           => SANDBOX_SYNC_PROTOCOL,
			'wordpress_version'  => isset( $wp_version ) ? $wp_version : '',
			'php_version'        => PHP_VERSION,
			'db_version'         => $db_version,
			'db_family'          => false !== stripos( $db_version, 'mariadb' ) ? 'mariadb' : 'mysql',
			'sql_mode'           => $sql_mode,
			'max_allowed_packet' => $packet,
			'zlib'               => function_exists( 'gzencode' ),
			// Opt-in shell fast path: the sandbox only attempts table_dump when
			// the source advertises it here (and its own side is enabled too).
			'db_shell'           => Db_Shell::source_available() && function_exists( 'deflate_init' ),
		);
	}

	// -- handshake ----------------------------------------------------------

	private static function handle_handshake() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			self::respond_json( array( 'error' => 'POST required.' ), 405 );
		}

		$content_length = isset( $_SERVER['CONTENT_LENGTH'] ) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
		if ( $content_length > self::HANDSHAKE_MAX ) {
			self::respond_json( array( 'error' => 'Handshake request is too large.' ), 413 );
		}
		$raw = file_get_contents( 'php://input', false, null, 0, self::HANDSHAKE_MAX + 1 );
		if ( false === $raw || strlen( $raw ) > self::HANDSHAKE_MAX ) {
			self::respond_json( array( 'error' => 'Handshake request is too large.' ), 413 );
		}
		$body = json_decode( $raw, true );
		if ( ! is_array( $body ) ) {
			self::respond_json( array( 'error' => 'Handshake request must contain valid JSON.' ), 400 );
		}
		$key  = isset( $body['pairing_key'] ) ? (string) $body['pairing_key'] : '';
		$url  = isset( $body['sandbox_url'] ) ? (string) $body['sandbox_url'] : '';

		if ( '' === $key || '' === $url ) {
			self::respond_json( array( 'error' => 'pairing_key and sandbox_url are required.' ), 400 );
		}

		if ( ! Auth::consume_pairing_key( $key ) ) {
			Auth::record_auth_failure();
			self::respond_json( array( 'error' => 'Invalid or already-used pairing key.' ), 401 );
		}
		Auth::clear_auth_failures();

		$token = Auth::issue_token( $url );

		self::respond_json(
			array(
				'token'            => $token,
				'token_expires_at' => time() + Auth::token_ttl(),
				'site'             => self::site_info(),
			)
		);
	}

	// -- manifest -----------------------------------------------------------

	private static function handle_manifest( $transfer ) {
		set_time_limit( 0 );

		$dirs = array();
		foreach ( self::SYNCABLE_DIRS as $dir ) {
			if ( in_array( $dir, (array) $transfer['dirs'], true ) && is_dir( WP_CONTENT_DIR . '/' . $dir ) ) {
				$dirs[] = $dir;
			}
		}

		self::respond_json(
			array(
				'site'   => self::site_info(),
				'tables' => ! empty( $transfer['database'] ) ? DB_Exporter::get_manifest( ! empty( $_GET['checksums'] ), (array) ( $transfer['schema_only_suffixes'] ?? array() ) ) : array(),
				'dirs'   => $dirs,
			)
		);
	}

	/** Returns exact checksums only for the tables being validated. */
	private static function handle_checksums( $transfer ) {
		set_time_limit( 0 );
		if ( isset( $_SERVER['CONTENT_LENGTH'] ) && (int) $_SERVER['CONTENT_LENGTH'] > 262144 ) {
			self::respond_json( array( 'error' => 'Checksum request is too large.' ), 413 );
		}
		$body   = json_decode( (string) file_get_contents( 'php://input' ), true );
		$tables = is_array( $body ) && isset( $body['tables'] ) && is_array( $body['tables'] ) ? array_values( array_unique( $body['tables'] ) ) : array();
		if ( empty( $tables ) || count( $tables ) > 1000 ) {
			self::respond_json( array( 'error' => 'Between 1 and 1000 table names are required.' ), 400 );
		}
		$checksums = array();
		foreach ( $tables as $table ) {
			if ( ! is_string( $table ) || ! in_array( $table, (array) $transfer['tables'], true ) || ! preg_match( '/^[A-Za-z0-9_]+$/', $table ) || ! DB_Exporter::is_exportable( $table ) ) {
				self::respond_json( array( 'error' => 'A requested table is not exportable.' ), 400 );
			}
			$checksums[ $table ] = DB_Exporter::table_checksum( $table );
		}
		self::respond_json( array( 'checksums' => $checksums ) );
	}

	// -- constants ------------------------------------------------------------

	/**
	 * GET discovers names without values. POST returns values only for exact
	 * names bound to the active transfer; hard-denied environment/secrets are
	 * never discoverable or selectable.
	 */
	private static function handle_constants( $transfer ) {
		if ( 'GET' === ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
			self::respond_json( array( 'constants' => Safety::offered_constants() ) );
		}
		if ( isset( $_SERVER['CONTENT_LENGTH'] ) && (int) $_SERVER['CONTENT_LENGTH'] > 65536 ) {
			self::respond_json( array( 'error' => 'Constant value request is too large.' ), 413 );
		}
		$body  = json_decode( (string) file_get_contents( 'php://input' ), true );
		$names = is_array( $body ) && isset( $body['names'] ) && is_array( $body['names'] ) ? array_values( array_unique( $body['names'] ) ) : array();
		if ( empty( $names ) || count( $names ) > 500 ) {
			self::respond_json( array( 'error' => 'Between 1 and 500 selected constant names are required.' ), 400 );
		}
		foreach ( $names as $name ) {
			if ( ! is_string( $name ) || ! preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', $name )
				|| ! in_array( $name, (array) ( $transfer['constants'] ?? array() ), true ) || ! Safety::is_offerable_constant( $name ) ) {
				self::respond_json( array( 'error' => 'A requested constant is outside this transfer session or is unsafe.' ), 403 );
			}
		}
		$constants = Safety::offered_constants( $names );
		if ( count( $constants ) !== count( $names ) ) {
			self::respond_json( array( 'error' => 'A selected constant changed, disappeared, or is no longer a simple scalar literal.' ), 409 );
		}
		self::respond_json( array( 'constants' => $constants ) );
	}

	// -- plugins --------------------------------------------------------------

	/**
	 * Installed plugins on this site, so the sandbox can offer a per-plugin
	 * sync selection.
	 */
	private static function handle_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$active  = (array) get_option( 'active_plugins' );
		$plugins = array();
		foreach ( get_plugins() as $file => $data ) {
			// Top-level entry in plugins/: directory, or the file itself
			// for single-file plugins like hello.php.
			$top = strtok( $file, '/' );

			$plugins[] = array(
				'file'    => $file,
				'top'     => $top,
				'name'    => $data['Name'],
				'version' => $data['Version'],
				'active'  => in_array( $file, $active, true ),
				// Symlinked plugins are invisible to the file sync (Files::scan
				// skips symlinks), so they can't be selected for syncing.
				'symlink' => is_link( WP_CONTENT_DIR . '/plugins/' . $top ),
			);
		}

		self::respond_json( array( 'plugins' => $plugins ) );
	}

	// -- files_manifest -------------------------------------------------------

	private static function handle_files_manifest() {
		set_time_limit( 0 );

		$dir = isset( $_GET['dir'] ) ? (string) $_GET['dir'] : '';
		if ( ! in_array( $dir, self::SYNCABLE_DIRS, true ) ) {
			self::respond_json( array( 'error' => "Unknown dir: {$dir}" ), 400 );
		}
		$scope = isset( $_GET['scope'] ) ? (string) $_GET['scope'] : '';
		if ( '' !== $scope && ( 'uploads' !== $dir || ! Uploads_State::valid_scope( $scope ) ) ) {
			self::respond_json( array( 'error' => 'Malformed uploads manifest scope.' ), 400 );
		}
		if ( isset( $_GET['format'] ) && 'ndjson' === $_GET['format'] ) {
			while ( ob_get_level() > 0 ) {
				ob_end_clean();
			}
			header( 'Content-Type: application/x-ndjson; charset=utf-8' );
			header( 'Cache-Control: no-store, private' );
			header( 'X-Accel-Buffering: no' );
			header( 'X-Content-Type-Options: nosniff' );
			$count             = 0;
			$bytes             = 0;
			$published         = microtime( true );
			$cancelled_checked = $published;
			self::publish_transfer_progress( $dir, 'source_manifest', 0, 0, false );
			$max_files = max( 1, (int) apply_filters( 'sandbox_sync_max_manifest_files', Files::MAX_MANIFEST_FILES ) );
			$iterator = '' !== $scope
				? Files::walk_upload_scope( WP_CONTENT_DIR . '/uploads', $scope, ! empty( $_GET['hashes'] ) )
				: Files::walk( WP_CONTENT_DIR . '/' . $dir, ! empty( $_GET['hashes'] ) );
			foreach ( $iterator as $file ) {
				if ( $count >= $max_files ) {
					self::publish_transfer_progress( $dir, 'source_manifest', $count, $bytes, true );
					// Deliberately emit a non-manifest record so the target fails
					// validation instead of accepting a silently truncated tree.
					echo wp_json_encode( array( 'error' => 'File manifest entry limit exceeded.' ) ) . "\n";
					exit;
				}
				echo wp_json_encode( $file ) . "\n";
				++$count;
				$bytes += (int) $file['s'];
				if ( microtime( true ) - $published >= 2 ) {
					self::publish_transfer_progress( $dir, 'source_manifest', $count, $bytes, false );
					$published = microtime( true );
				}
				if ( microtime( true ) - $cancelled_checked >= 1 ) {
					if ( self::transfer_cancel_requested() ) {
						self::publish_transfer_progress( $dir, 'source_manifest', $count, $bytes, true );
						echo wp_json_encode( array( 'error' => 'Source file manifest cancelled by an operator.' ) ) . "\n";
						flush();
						exit;
					}
					$cancelled_checked = microtime( true );
				}
				if ( 0 === $count % 1000 ) {
					flush();
				}
			}
			self::publish_transfer_progress( $dir, 'source_manifest', $count, $bytes, true );
			exit;
		}

		self::respond_json(
			array(
				'dir'   => $dir,
				'files' => iterator_to_array(
					'' !== $scope
						? Files::walk_upload_scope( WP_CONTENT_DIR . '/uploads', $scope, ! empty( $_GET['hashes'] ) )
						: Files::walk( WP_CONTENT_DIR . '/' . $dir, ! empty( $_GET['hashes'] ) ),
					false
				),
			)
		);
	}

	/**
	 * Stat-only bytes/files totals for the requested uploads partitions, so
	 * the sandbox can show "copied / total" progress during first-baseline
	 * streams. No file content is read.
	 */
	private static function handle_uploads_totals() {
		if ( isset( $_SERVER['CONTENT_LENGTH'] ) && (int) $_SERVER['CONTENT_LENGTH'] > 262144 ) {
			self::respond_json( array( 'error' => 'Uploads totals request is too large.' ), 413 );
		}
		$body   = json_decode( (string) file_get_contents( 'php://input' ), true );
		$scopes = is_array( $body ) && isset( $body['scopes'] ) && is_array( $body['scopes'] ) ? array_values( array_unique( $body['scopes'] ) ) : array();
		if ( empty( $scopes ) || count( $scopes ) > 2000 ) {
			self::respond_json( array( 'error' => 'Between 1 and 2000 uploads partitions are required.' ), 400 );
		}
		foreach ( $scopes as $scope ) {
			if ( ! is_string( $scope ) || ! Uploads_State::valid_scope( $scope ) ) {
				self::respond_json( array( 'error' => 'An invalid uploads partition was requested.' ), 400 );
			}
		}
		set_time_limit( 0 );
		self::respond_json( array( 'totals' => Files::upload_scope_totals( WP_CONTENT_DIR . '/uploads', $scopes ) ) );
	}

	/** Returns generations without walking or hashing media payloads. */
	private static function handle_uploads_state() {
		$snapshot = Uploads_State::snapshot();
		if ( is_wp_error( $snapshot ) ) {
			self::respond_json( array( 'error' => $snapshot->get_error_message() ), 500 );
		}
		$snapshot['source_id'] = hash( 'sha256', untrailingslashit( home_url() ) );
		self::respond_json( $snapshot );
	}

	/** Hashes only metadata-different paths selected by the sandbox planner. */
	private static function handle_file_hashes() {
		if ( isset( $_SERVER['CONTENT_LENGTH'] ) && (int) $_SERVER['CONTENT_LENGTH'] > 262144 ) {
			self::respond_json( array( 'error' => 'File hash request is too large.' ), 413 );
		}
		$dir  = isset( $_GET['dir'] ) ? (string) $_GET['dir'] : '';
		$body = json_decode( (string) file_get_contents( 'php://input' ), true );
		$requested = is_array( $body ) && isset( $body['files'] ) && is_array( $body['files'] ) ? $body['files'] : array();
		if ( 'uploads' !== $dir || empty( $requested ) || count( $requested ) > Files::BATCH_MAX_FILES ) {
			self::respond_json( array( 'error' => 'Between 1 and 400 uploads entries are required.' ), 400 );
		}
		$paths = array();
		$by_path = array();
		$request_total_bytes = 0;
		foreach ( $requested as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['p'], $entry['s'], $entry['m'] ) || ! Tar_Exporter::is_safe_relative_path( $entry['p'] ) ) {
				self::respond_json( array( 'error' => 'File hash request contains malformed metadata.' ), 400 );
			}
			$paths[] = $entry['p'];
			$by_path[ $entry['p'] ] = $entry;
			$request_total_bytes += max( 0, (int) $entry['s'] );
		}
		$prepared = Tar_Exporter::prepare_paths( WP_CONTENT_DIR . '/uploads', $paths );
		if ( is_wp_error( $prepared ) ) {
			self::respond_json( array( 'error' => $prepared->get_error_message() ), 409 );
		}
		$files = array();
		$hashed_files = 0;
		$hashed_bytes = 0;
		$published    = microtime( true );
		$cancelled_checked = $published;
		$request_total_files = count( $prepared );
		self::publish_transfer_progress( 'uploads', 'source_hashes', 0, 0, false, $request_total_files, $request_total_bytes );
		foreach ( $prepared as $file ) {
			$expected = $by_path[ $file['path'] ];
			$size = @filesize( $file['full'] );
			$mtime = @filemtime( $file['full'] );
			$mode = @fileperms( $file['full'] );
			if ( false === $size || false === $mtime || false === $mode || (int) $expected['s'] !== (int) $size || (int) $expected['m'] !== (int) $mtime ) {
				self::respond_json( array( 'error' => 'A selected upload changed before hashing.' ), 409 );
			}
			$handle = @fopen( $file['full'], 'rb' );
			$context = hash_init( 'sha256' );
			if ( ! $handle || false === $context ) {
				if ( $handle ) {
					fclose( $handle );
				}
				self::respond_json( array( 'error' => 'A selected upload could not be hashed.' ), 500 );
			}
			while ( ! feof( $handle ) ) {
				$chunk = fread( $handle, 1048576 );
				if ( false === $chunk ) {
					fclose( $handle );
					self::respond_json( array( 'error' => 'A selected upload could not be read completely for hashing.' ), 500 );
				}
				if ( '' === $chunk ) {
					break;
				}
				hash_update( $context, $chunk );
				$hashed_bytes += strlen( $chunk );
				if ( microtime( true ) - $published >= 2 ) {
					self::publish_transfer_progress( 'uploads', 'source_hashes', $hashed_files, $hashed_bytes, false, $request_total_files, $request_total_bytes );
					$published = microtime( true );
				}
				if ( microtime( true ) - $cancelled_checked >= 1 && self::transfer_cancel_requested() ) {
					fclose( $handle );
					self::publish_transfer_progress( 'uploads', 'source_hashes', $hashed_files, $hashed_bytes, true, $request_total_files, $request_total_bytes );
					self::respond_json( array( 'error' => 'Source upload hashing cancelled by an operator.' ), 409 );
				}
				if ( microtime( true ) - $cancelled_checked >= 1 ) {
					$cancelled_checked = microtime( true );
				}
			}
			fclose( $handle );
			$hash = hash_final( $context );
			++$hashed_files;
			if ( 0 === $hashed_files % 25 || microtime( true ) - $published >= 2 ) {
				self::publish_transfer_progress( 'uploads', 'source_hashes', $hashed_files, $hashed_bytes, false, $request_total_files, $request_total_bytes );
				$published = microtime( true );
			}
			$files[] = array(
				'p' => $file['path'],
				's' => (int) $size,
				'm' => (int) $mtime,
				'o' => (int) ( $mode & 0777 ),
				'h' => $hash,
			);
		}
		self::publish_transfer_progress( 'uploads', 'source_hashes', $hashed_files, $hashed_bytes, true, $request_total_files, $request_total_bytes );
		self::respond_json( array( 'files' => $files ) );
	}

	/** Fails closed if the active request can no longer verify its session. */
	private static function transfer_cancel_requested() {
		$id         = isset( $_GET['transfer_id'] ) ? (string) $_GET['transfer_id'] : '';
		$token_hash = hash( 'sha256', Auth::get_bearer_token() );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $id ) ) {
			return true;
		}
		$active = Source_State::read( self::transfer_path( $id ) );
		return is_wp_error( $active )
			|| ! is_array( $active )
			|| ! isset( $active['id_hash'], $active['token_hash'] )
			|| ! hash_equals( (string) $active['id_hash'], hash( 'sha256', $id ) )
			|| ! hash_equals( (string) $active['token_hash'], $token_hash )
			|| ! empty( $active['cancel_requested'] );
	}

	/** Keeps a long, actively streaming response from aging out its grant. */
	private static function renew_streaming_transfer_lease() {
		$id         = isset( $_GET['transfer_id'] ) ? (string) $_GET['transfer_id'] : '';
		$token_hash = hash( 'sha256', Auth::get_bearer_token() );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $id ) ) {
			return false;
		}
		$result = Source_State::transaction(
			self::transfer_path( $id ),
			function ( $active ) use ( $id, $token_hash ) {
				if ( ! $active || ! isset( $active['id_hash'], $active['token_hash'] )
					|| ! hash_equals( (string) $active['id_hash'], hash( 'sha256', $id ) )
					|| ! hash_equals( (string) $active['token_hash'], $token_hash )
					|| ! empty( $active['cancel_requested'] ) ) {
					return new \WP_Error( 'transfer_cancelled', 'The streaming transfer is no longer active.' );
				}
				$active['expires_at'] = time() + self::TRANSFER_TTL;
				return array(
					'state' => $active,
					'value' => true,
				);
			}
		);
		return true === $result;
	}

	/** Publishes bounded counters without retaining source paths or filenames. */
	private static function publish_transfer_progress( $dir, $phase, $files, $bytes, $done, $total_files = 0, $total_bytes = 0 ) {
		$id         = isset( $_GET['transfer_id'] ) ? (string) $_GET['transfer_id'] : '';
		$token_hash = hash( 'sha256', Auth::get_bearer_token() );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $id ) ) {
			return;
		}
		Source_State::transaction(
			self::transfer_path( $id ),
			function ( $active ) use ( $id, $token_hash, $dir, $phase, $files, $bytes, $done, $total_files, $total_bytes ) {
				if ( ! $active
					|| ! hash_equals( (string) $active['id_hash'], hash( 'sha256', $id ) )
					|| ! hash_equals( (string) $active['token_hash'], $token_hash ) ) {
					return new \WP_Error( 'transfer_owner', 'Transfer progress ownership could not be verified.' );
				}
				$active['progress'] = array(
					'dir'        => in_array( $dir, self::SYNCABLE_DIRS, true ) ? $dir : '',
					'phase'      => in_array( $phase, array( 'source_manifest', 'source_hashes' ), true ) ? $phase : '',
					'files'      => max( 0, (int) $files ),
					'bytes'      => max( 0, (int) $bytes ),
					'total_files' => max( 0, (int) $total_files ),
					'total_bytes' => max( 0, (int) $total_bytes ),
					'done'       => (bool) $done,
					'updated_at' => time(),
				);
				$active['expires_at'] = time() + self::TRANSFER_TTL;
				return array(
					'state' => $active,
					'value' => true,
				);
			}
		);
	}

	/** Returns only operator-safe aggregate counters from the active transfer. */
	private static function handle_progress( $transfer ) {
		$progress = isset( $transfer['progress'] ) && is_array( $transfer['progress'] ) ? $transfer['progress'] : null;
		self::respond_json( array( 'progress' => $progress ) );
	}

	// -- resumable file -------------------------------------------------------

	/** Streams one bounded, integrity-addressed raw-file range. */
	private static function handle_file() {
		$dir      = isset( $_GET['dir'] ) ? (string) $_GET['dir'] : '';
		$path     = isset( $_GET['path'] ) ? (string) $_GET['path'] : '';
		$offset   = isset( $_GET['offset'] ) ? (int) $_GET['offset'] : -1;
		$size     = isset( $_GET['size'] ) ? (int) $_GET['size'] : -1;
		$mtime    = isset( $_GET['mtime'] ) ? (int) $_GET['mtime'] : -1;
		$expected = isset( $_GET['sha256'] ) ? strtolower( (string) $_GET['sha256'] ) : '';
		if ( ! in_array( $dir, self::SYNCABLE_DIRS, true ) || strlen( $path ) > 4096 || $offset < 0 || $size < 0 || $mtime < 0 || ! preg_match( '/^[a-f0-9]{64}$/', $expected ) ) {
			self::respond_json( array( 'error' => 'Malformed resumable file request.' ), 400 );
		}
		$prepared = Tar_Exporter::prepare_paths( WP_CONTENT_DIR . '/' . $dir, array( $path ) );
		if ( is_wp_error( $prepared ) ) {
			self::respond_json( array( 'error' => $prepared->get_error_message() ), 400 );
		}
		$file        = $prepared[0]['full'];
		$actual_size = filesize( $file );
		$actual_time = filemtime( $file );
		if ( false === $actual_size || false === $actual_time || $size !== (int) $actual_size || $mtime !== (int) $actual_time ) {
			self::respond_json( array( 'error' => 'Source file metadata changed after its manifest was issued.' ), 409 );
		}
		if ( $offset > $size ) {
			self::respond_json( array( 'error' => 'Requested file offset is beyond end of file.' ), 416 );
		}
		// Do not re-read a large object merely to reproduce the content address
		// already issued by file_hashes. The sandbox hashes the fully assembled
		// partial before installation, so a same-metadata source mutation still
		// fails closed without adding another complete source-disk pass.

		$length = min( self::FILE_CHUNK, $size - $offset );
		$end    = $length > 0 ? $offset + $length - 1 : $offset;
		$mode   = (int) ( fileperms( $file ) & 0777 );
		$fh     = fopen( $file, 'rb' );
		if ( ! $fh || ( $offset > 0 && 0 !== fseek( $fh, $offset ) ) ) {
			if ( $fh ) {
				fclose( $fh );
			}
			self::respond_json( array( 'error' => 'Could not open the requested source file range.' ), 500 );
		}

		set_time_limit( 0 );
		ignore_user_abort( false );
		ini_set( 'zlib.output_compression', 'Off' );
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		http_response_code( 206 );
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Encoding: identity' );
		header( 'Content-Length: ' . $length );
		header( "Content-Range: bytes {$offset}-{$end}/{$size}" );
		header( 'X-Sandbox-Sync-SHA256: ' . $expected );
		header( 'X-Sandbox-Sync-Mtime: ' . $mtime );
		header( 'X-Sandbox-Sync-Mode: ' . $mode );
		header( 'X-Accel-Buffering: no' );
		header( 'Cache-Control: no-store, private' );
		header( 'X-Content-Type-Options: nosniff' );

		$remaining = $length;
		while ( $remaining > 0 && ! connection_aborted() ) {
			$chunk = fread( $fh, min( 1048576, $remaining ) );
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			echo $chunk;
			$remaining -= strlen( $chunk );
			flush();
		}
		fclose( $fh );
		exit;
	}

	// -- uploads_scopes -------------------------------------------------------

	/**
	 * Year/month layout of the uploads directory, so the sandbox can offer a
	 * date-scoped uploads sync. Symlinked entries are skipped (invisible to
	 * the file sync anyway); "other" flags non-date entries in the root.
	 */
	private static function handle_uploads_scopes() {
		$base  = WP_CONTENT_DIR . '/uploads';
		$years = array();
		$other = false;

		$entries = is_dir( $base ) ? scandir( $base ) : array();
		foreach ( (array) $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry || is_link( $base . '/' . $entry ) ) {
				continue;
			}
			if ( preg_match( '/^\d{4}$/', $entry ) && is_dir( $base . '/' . $entry ) ) {
				$months = array();
				foreach ( (array) scandir( $base . '/' . $entry ) as $sub ) {
					if ( preg_match( '/^\d{2}$/', $sub )
						&& is_dir( $base . '/' . $entry . '/' . $sub )
						&& ! is_link( $base . '/' . $entry . '/' . $sub ) ) {
						$months[] = $sub;
					}
				}
				sort( $months );
				$years[] = array(
					'year'   => $entry,
					'months' => $months,
				);
			} else {
				$other = true;
			}
		}

		usort(
			$years,
			function ( $a, $b ) {
				return strcmp( $b['year'], $a['year'] );
			}
		);

		self::respond_json(
			array(
				'years' => $years,
				'other' => $other,
			)
		);
	}

	// -- table --------------------------------------------------------------

	private static function handle_table( $transfer ) {
		set_time_limit( 0 );

		$table  = isset( $_GET['table'] ) ? (string) $_GET['table'] : '';
		$offset = isset( $_GET['offset'] ) ? max( 0, (int) $_GET['offset'] ) : 0;
		$limit  = isset( $_GET['limit'] ) ? (int) $_GET['limit'] : 200;
		$limit  = max( 1, min( 5000, $limit ) );

		// Keyset cursor from the previous chunk (URL-safe unpadded base64
		// JSON — see next_cursor below). Stray padding is stripped for
		// robustness before the strict decode.
		$cursor = null;
		if ( isset( $_GET['cursor'] ) && '' !== $_GET['cursor'] ) {
			if ( strlen( (string) $_GET['cursor'] ) > 4096 ) {
				self::respond_json( array( 'error' => 'Cursor is too large.' ), 400 );
			}
			$raw     = base64_decode( rtrim( strtr( (string) $_GET['cursor'], '-_', '+/' ), '=' ), true );
			$decoded = false === $raw ? null : json_decode( $raw, true );
			if ( ! is_array( $decoded ) ) {
				self::respond_json( array( 'error' => 'Malformed cursor.' ), 400 );
			}
			$cursor = $decoded;
			if ( count( $cursor ) > 32 ) {
				self::respond_json( array( 'error' => 'Cursor has too many columns.' ), 400 );
			}
			foreach ( $cursor as $value ) {
				if ( ! is_scalar( $value ) && null !== $value ) {
					self::respond_json( array( 'error' => 'Cursor values must be scalar.' ), 400 );
				}
			}
		}

		if ( ! in_array( $table, (array) $transfer['tables'], true ) || ! preg_match( '/^[A-Za-z0-9_]+$/', $table ) || ! DB_Exporter::is_exportable( $table ) ) {
			self::respond_json( array( 'error' => "Table not exportable: {$table}" ), 400 );
		}

		// Lightweight row index for posts delta planning (protocol 14+).
		if ( ! empty( $_GET['row_index'] ) ) {
			$columns = isset( $_GET['columns'] ) ? array_map( 'trim', explode( ',', (string) $_GET['columns'] ) ) : array();
			$limit   = isset( $_GET['limit'] ) ? (int) $_GET['limit'] : 5000;
			$cursor  = null;
			if ( isset( $_GET['cursor'] ) && '' !== $_GET['cursor'] ) {
				if ( strlen( (string) $_GET['cursor'] ) > 4096 ) {
					self::respond_json( array( 'error' => 'Cursor is too large.' ), 400 );
				}
				$raw     = base64_decode( rtrim( strtr( (string) $_GET['cursor'], '-_', '+/' ), '=' ), true );
				$decoded = false === $raw ? null : json_decode( $raw, true );
				if ( ! is_array( $decoded ) ) {
					self::respond_json( array( 'error' => 'Malformed cursor.' ), 400 );
				}
				$cursor = $decoded;
			}
			$index = DB_Exporter::export_row_index( $table, $columns, $limit, $cursor );
			if ( is_wp_error( $index ) ) {
				self::respond_json( array( 'error' => $index->get_error_message() ), 500 );
			}
			self::respond_json(
				array(
					'table'       => $table,
					'rows'        => $index['rows'],
					'done'        => $index['done'],
					'next_cursor' => null !== $index['next_cursor'] ? rtrim( strtr( base64_encode( wp_json_encode( $index['next_cursor'] ) ), '+/', '-_' ), '=' ) : null,
				)
			);
		}

		// Full rows by primary key (posts delta upserts).
		if ( isset( $_GET['ids'] ) && '' !== (string) $_GET['ids'] ) {
			$ids = array_map( 'intval', explode( ',', (string) $_GET['ids'] ) );
			$max_bytes = isset( $_GET['max_bytes'] ) ? (int) $_GET['max_bytes'] : DB_Exporter::MAX_STATEMENT_BYTES;
			$chunk     = DB_Exporter::export_rows_by_ids( $table, $ids, $max_bytes );
			if ( is_wp_error( $chunk ) ) {
				self::respond_json( array( 'error' => $chunk->get_error_message() ), 400 );
			}
			self::respond_json(
				array_merge(
					array(
						'table'          => $table,
						'row_count'      => $chunk['row_count'],
						'done'           => true,
						'next_cursor'    => null,
						'create_sql_b64' => '',
						'create_sha256'  => '',
						'total_rows'     => $chunk['row_count'],
					),
					DB_Exporter::encode_statements( $chunk['insert_sql'] )
				)
			);
		}

		// postmeta rows for a set of post_id values.
		if ( isset( $_GET['post_ids'] ) && '' !== (string) $_GET['post_ids'] ) {
			$post_ids  = array_map( 'intval', explode( ',', (string) $_GET['post_ids'] ) );
			$max_bytes = isset( $_GET['max_bytes'] ) ? (int) $_GET['max_bytes'] : DB_Exporter::MAX_STATEMENT_BYTES;
			$chunk     = DB_Exporter::export_rows_by_post_ids( $table, $post_ids, $max_bytes );
			if ( is_wp_error( $chunk ) ) {
				self::respond_json( array( 'error' => $chunk->get_error_message() ), 400 );
			}
			self::respond_json(
				array_merge(
					array(
						'table'          => $table,
						'row_count'      => $chunk['row_count'],
						'done'           => true,
						'next_cursor'    => null,
						'create_sql_b64' => '',
						'create_sha256'  => '',
						'total_rows'     => $chunk['row_count'],
					),
					DB_Exporter::encode_statements( $chunk['insert_sql'] )
				)
			);
		}

		// Schema probe (shell fast path): the verified CREATE TABLE plus the
		// current row count, with zero row data. Available for any authorized
		// table — it discloses no more than the manifest's schema hash already
		// commits to.
		if ( ! empty( $_GET['schema_probe'] ) ) {
			$create = DB_Exporter::export_schema( $table );
			if ( is_wp_error( $create ) ) {
				self::respond_json( array( 'error' => $create->get_error_message() ), 500 );
			}
			self::respond_json(
				array(
					'table'          => $table,
					'create_sql_b64' => base64_encode( $create ),
					'create_sha256'  => hash( 'sha256', $create ),
					'total_rows'     => DB_Exporter::exact_row_count( $table ),
				)
			);
		}
		$schema_only_request = ! empty( $_GET['schema_only'] );
		$schema_only_table   = in_array( $table, (array) ( $transfer['schema_only_tables'] ?? array() ), true );
		if ( $schema_only_table && ! $schema_only_request ) {
			self::respond_json( array( 'error' => "Row export is forbidden by the schema-only transfer policy for {$table}." ), 403 );
		}
		if ( $schema_only_request && ! $schema_only_table ) {
			self::respond_json( array( 'error' => "Table {$table} is not bound to the schema-only transfer policy." ), 403 );
		}
		if ( $schema_only_request ) {
			$create = DB_Exporter::export_schema( $table );
			if ( is_wp_error( $create ) ) {
				self::respond_json( array( 'error' => $create->get_error_message() ), 500 );
			}
			self::respond_json(
				array_merge(
					array(
						'table'          => $table,
						'row_count'      => 0,
						'done'           => true,
						'next_cursor'    => null,
						'create_sql_b64' => base64_encode( $create ),
						'create_sha256'  => hash( 'sha256', $create ),
						'total_rows'     => 0,
					),
					DB_Exporter::encode_statements( array() )
				)
			);
		}

		$max_bytes = isset( $_GET['max_bytes'] ) ? (int) $_GET['max_bytes'] : DB_Exporter::MAX_STATEMENT_BYTES;
		$max_bytes = max( 65536, min( DB_Exporter::HARD_MAX_STATEMENT_BYTES, $max_bytes ) );
		$chunk     = DB_Exporter::export_chunk( $table, $offset, $limit, $cursor, $max_bytes );
		if ( is_wp_error( $chunk ) ) {
			self::respond_json( array( 'error' => $chunk->get_error_message() ), 500 );
		}

		self::respond_json(
			array_merge(
				array(
					'table'          => $table,
					'row_count'      => $chunk['row_count'],
					'done'           => $chunk['done'],
					// URL-safe unpadded base64: query-string transport mangles
					// both +/ (add_query_arg / urldecoding) and the trailing
					// = padding (add_query_arg collapses ==), so neither may
					// appear in the cursor.
					'next_cursor'    => null !== $chunk['next_cursor'] ? rtrim( strtr( base64_encode( wp_json_encode( $chunk['next_cursor'] ) ), '+/', '-_' ), '=' ) : null,
					'create_sql_b64' => '' !== $chunk['create_sql'] ? base64_encode( $chunk['create_sql'] ) : '',
					'create_sha256'  => '' !== $chunk['create_sql'] ? hash( 'sha256', $chunk['create_sql'] ) : '',
					'total_rows'     => ( 0 === $offset && null === $cursor ) ? DB_Exporter::exact_row_count( $table ) : null,
				),
				DB_Exporter::encode_statements( $chunk['insert_sql'] )
			)
		);
	}

	// -- table_dump (shell fast path) -----------------------------------------

	/**
	 * Streams one table as a gzip'd mysqldump with a SHA-256 trailer.
	 *
	 * Only reachable when this site explicitly opted in (see Db_Shell). The
	 * table name is transfer-scoped, regex-validated and passed as a single
	 * argv element — no shell ever parses it. Credentials travel via the
	 * process environment, never argv.
	 */
	private static function handle_table_dump( $transfer ) {
		global $wpdb;
		set_time_limit( 0 );
		if ( ! Db_Shell::source_available() || ! function_exists( 'deflate_init' ) ) {
			self::respond_json(
				array(
					'error' => 'The shell dump fast path is not enabled on this source.',
					'code'  => 'shell_dump_unavailable',
				),
				403
			);
		}
		$table = isset( $_GET['table'] ) ? (string) $_GET['table'] : '';
		if ( ! in_array( $table, (array) $transfer['tables'], true ) || ! preg_match( '/^[A-Za-z0-9_]+$/', $table ) || ! DB_Exporter::is_exportable( $table ) ) {
			self::respond_json( array( 'error' => "Table not exportable: {$table}" ), 400 );
		}
		if ( in_array( $table, (array) ( $transfer['schema_only_tables'] ?? array() ), true ) ) {
			self::respond_json( array( 'error' => "Row export is forbidden by the schema-only transfer policy for {$table}." ), 403 );
		}

		$db_version = (string) $wpdb->get_var( 'SELECT VERSION()' );
		$binary     = Db_Shell::find_binary( 'mysqldump' );
		$args       = Db_Shell::mysqldump_args(
			$binary,
			$table,
			DB_NAME,
			DB_USER,
			Db_Shell::parse_db_host( DB_HOST ),
			! empty( $wpdb->charset ) ? $wpdb->charset : '',
			// MySQL family: suppress SET @@GLOBAL.GTID_PURGED, which the
			// sandbox's statement whitelist would (rightly) refuse. MariaDB's
			// mysqldump does not know the option.
			false === stripos( $db_version, 'mariadb' )
		);
		$pipes  = array();
		$proc   = proc_open(
			$args,
			array(
				0 => array( 'file', '/dev/null', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes,
			null,
			array(
				'MYSQL_PWD' => DB_PASSWORD,
				'PATH'      => (string) getenv( 'PATH' ),
			)
		);
		if ( ! is_resource( $proc ) ) {
			self::respond_json( array( 'error' => 'Could not start the mysqldump process.' ), 500 );
		}
		// Both pipes nonblocking and drained together via stream_select: a
		// mysqldump that fills its stderr pipe while PHP blocks on stdout (or
		// vice versa) would otherwise deadlock both processes.
		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );

		ignore_user_abort( false );
		ini_set( 'zlib.output_compression', 'Off' );
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		if ( headers_sent() ) {
			proc_terminate( $proc );
			proc_close( $proc );
			http_response_code( 500 );
			exit;
		}
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . $table . '.sql.gz"' );
		header( 'Content-Encoding: identity' );
		header( 'X-Accel-Buffering: no' );
		header( 'Cache-Control: no-store' );
		header( 'X-Content-Type-Options: nosniff' );

		// Cheap level: SQL text still shrinks 4-8x and mysqldump, not the
		// deflate, must stay the bottleneck.
		$deflate            = deflate_init( ZLIB_ENCODING_GZIP, array( 'level' => 1 ) );
		$hash               = hash_init( 'sha256' );
		$aborted            = false;
		$last_byte          = "\n";
		$streamed           = 0;
		$last_cancel_check  = 0.0;
		$last_lease_renewal = microtime( true );

		$idle_since = microtime( true );
		$max_idle   = max( 60, (int) apply_filters( 'sandbox_sync_shell_dump_idle_seconds', 600 ) );
		while ( true ) {
			// Session/abort checks run every iteration — including idle ones —
			// so a hung or silent mysqldump cannot retain this worker forever.
			if ( connection_aborted() ) {
				$aborted = true;
				break;
			}
			$now = microtime( true );
			if ( $now - $last_cancel_check >= 1 ) {
				$last_cancel_check = $now;
				if ( $now - $last_lease_renewal >= 60 ) {
					$last_lease_renewal = $now;
					if ( ! self::renew_streaming_transfer_lease() ) {
						$aborted = true;
						break;
					}
				} elseif ( self::transfer_cancel_requested() ) {
					$aborted = true;
					break;
				}
			}

			$read   = array( $pipes[1], $pipes[2] );
			$write  = null;
			$except = null;
			if ( false === @stream_select( $read, $write, $except, 1 ) ) {
				usleep( 20000 );
			}

			// Drain stderr continuously so mysqldump never blocks on it.
			$err = fread( $pipes[2], 65536 );
			if ( is_string( $err ) && '' !== $err ) {
				// Discarded beyond liveness: a failed dump is detected by exit
				// code and missing trailer, not by parsing localized messages.
				$idle_since = microtime( true );
			}

			$chunk = fread( $pipes[1], Db_Shell::READ_CHUNK );
			if ( false === $chunk || '' === $chunk ) {
				// EOF is the only clean exit; a dead process drains through
				// here naturally (buffered stdout first, then EOF). A process
				// that goes silent without closing its pipe hits the idle cap.
				if ( feof( $pipes[1] ) ) {
					break;
				}
				if ( microtime( true ) - $idle_since > $max_idle ) {
					$aborted = true;
					break;
				}
				continue;
			}
			$idle_since = microtime( true );
			hash_update( $hash, $chunk );
			$last_byte = substr( $chunk, -1 );
			$streamed += strlen( $chunk );
			echo deflate_add( $deflate, $chunk, ZLIB_NO_FLUSH );
			flush();
		}
		fclose( $pipes[1] );
		if ( is_resource( $pipes[2] ) ) {
			stream_get_contents( $pipes[2] ); // Final nonblocking drain.
			fclose( $pipes[2] );
		}
		if ( $aborted ) {
			proc_terminate( $proc );
		}
		$exit = proc_close( $proc );

		// The completion trailer only exists after a fully successful dump. A
		// killed/failed mysqldump ends the response without it, which the
		// sandbox fails closed. The trailer hash covers exactly the raw dump
		// bytes (newline-terminated).
		if ( ! $aborted && 0 === $exit ) {
			$trailer = '';
			if ( $streamed > 0 && "\n" !== $last_byte ) {
				hash_update( $hash, "\n" );
				$trailer .= "\n";
			}
			$trailer .= Db_Shell::TRAILER_PREFIX . hash_final( $hash ) . "\n";
			echo deflate_add( $deflate, $trailer, ZLIB_FINISH );
			flush();
		}
		exit;
	}

	// -- tar ----------------------------------------------------------------

	private static function handle_tar() {
		$dir = isset( $_GET['dir'] ) ? (string) $_GET['dir'] : '';
		if ( ! in_array( $dir, self::SYNCABLE_DIRS, true ) ) {
			self::respond_json( array( 'error' => "Unknown dir: {$dir}" ), 400 );
		}

		// Optional incremental batch. Protocol 10 uploads send metadata entries
		// so their hashes can be produced while the tar payload is streamed.
		$paths          = null;
		$files          = null;
		$upload_scopes  = null;
		$resume_after   = '';
		$nonce          = '';
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			if ( isset( $_SERVER['CONTENT_LENGTH'] ) && (int) $_SERVER['CONTENT_LENGTH'] > 4194304 ) {
				self::respond_json( array( 'error' => 'Tar request body is too large.' ), 413 );
			}
			$body = json_decode( (string) file_get_contents( 'php://input' ), true );
			if ( is_array( $body ) && isset( $body['upload_scopes'] ) && is_array( $body['upload_scopes'] ) ) {
				$upload_scopes = array_values( array_unique( $body['upload_scopes'] ) );
				if ( 'uploads' !== $dir || empty( $upload_scopes ) || count( $upload_scopes ) > 2500 ) {
					self::respond_json( array( 'error' => 'Malformed baseline uploads stream request.' ), 400 );
				}
				foreach ( $upload_scopes as $scope ) {
					if ( ! Uploads_State::valid_scope( $scope ) ) {
						self::respond_json( array( 'error' => 'Baseline uploads stream contains an invalid partition.' ), 400 );
					}
				}
				if ( isset( $body['resume_after'] ) && '' !== $body['resume_after'] ) {
					$resume_after = (string) $body['resume_after'];
					$resume_scope = preg_match( '#^(\d{4})/(0[1-9]|1[0-2])(?:/|$)#', $resume_after, $matches )
						? $matches[1] . '/' . $matches[2]
						: 'other';
					if ( ! Tar_Exporter::is_safe_relative_path( $resume_after ) || strlen( $resume_after ) > 4096
						|| ! in_array( $resume_scope, $upload_scopes, true ) ) {
						self::respond_json( array( 'error' => 'Baseline uploads resume cursor is invalid for the requested partitions.' ), 400 );
					}
				}
			} elseif ( is_array( $body ) && isset( $body['files'] ) && is_array( $body['files'] ) ) {
				$files = array_values( $body['files'] );
				$nonce = isset( $body['nonce'] ) ? (string) $body['nonce'] : '';
				if ( 'uploads' !== $dir || empty( $files ) || count( $files ) > Files::BATCH_MAX_FILES || ! preg_match( '/^[a-f0-9]{64}$/', $nonce ) ) {
					self::respond_json( array( 'error' => 'Malformed verified uploads tar request.' ), 400 );
				}
				$paths = array();
				$seen  = array();
				foreach ( $files as $entry ) {
					if ( ! is_array( $entry ) || ! isset( $entry['p'], $entry['s'], $entry['m'] ) || ! Tar_Exporter::is_safe_relative_path( $entry['p'] )
						|| ! is_numeric( $entry['s'] ) || (int) $entry['s'] < 0 || ! is_numeric( $entry['m'] ) || (int) $entry['m'] < 0
						|| Tar_Exporter::VERIFIED_MANIFEST_PATH === $entry['p'] || isset( $seen[ $entry['p'] ] )
						|| ( isset( $entry['o'] ) && ( ! is_numeric( $entry['o'] ) || (int) $entry['o'] < 0 || (int) $entry['o'] > 0777 ) ) ) {
						self::respond_json( array( 'error' => 'Verified uploads tar metadata is malformed.' ), 400 );
					}
					$paths[] = $entry['p'];
					$seen[ $entry['p'] ] = true;
				}
			} elseif ( is_array( $body ) && isset( $body['paths'] ) && is_array( $body['paths'] ) ) {
				$paths = $body['paths'];
				if ( count( $paths ) > Files::BATCH_MAX_FILES ) {
					self::respond_json( array( 'error' => 'Too many paths in one tar request.' ), 413 );
				}
			}
		}

		set_time_limit( 0 );

		// A first baseline can stream for hours. Poll its transfer state without
		// doing a state-file read for every 512 KiB payload chunk, and stop when
		// either endpoint cancels or disconnects.
		$baseline_checkpoint = null;
		if ( null !== $upload_scopes ) {
			$last_cancel_check   = 0.0;
			$last_lease_renewal  = microtime( true );
			$baseline_checkpoint = function () use ( &$last_cancel_check, &$last_lease_renewal ) {
				if ( connection_aborted() ) {
					return false;
				}
				$now = microtime( true );
				if ( $now - $last_cancel_check < 1 ) {
					return true;
				}
				$last_cancel_check = $now;
				if ( $now - $last_lease_renewal >= 60 ) {
					$last_lease_renewal = $now;
					return self::renew_streaming_transfer_lease();
				}
				return ! self::transfer_cancel_requested();
			};
		}

		// Validate the complete archive request before clearing buffers or
		// emitting binary response headers. This prevents partial archives and
		// rejects requested paths that cross nested symlinks. Baseline streams
		// only resolve their top-level nodes here: per-entry validation happens
		// inline during the single streaming pass, and an invalid entry
		// truncates the archive, which the sandbox detects and retries.
		$baseline_nodes = null;
		if ( null !== $upload_scopes ) {
			$baseline_nodes = Tar_Exporter::prepare_upload_scopes( WP_CONTENT_DIR . '/uploads', $upload_scopes );
			if ( is_wp_error( $baseline_nodes ) ) {
				self::respond_json( array( 'error' => $baseline_nodes->get_error_message() ), 400 );
			}
		} elseif ( null !== $paths ) {
			$prepared = Tar_Exporter::prepare_paths( WP_CONTENT_DIR . '/' . $dir, $paths );
			if ( is_wp_error( $prepared ) ) {
				self::respond_json( array( 'error' => $prepared->get_error_message() ), 400 );
			}
			if ( null !== $files ) {
				foreach ( $prepared as $index => $file ) {
					$expected = $files[ $index ];
					if ( (int) $expected['s'] !== (int) $file['size'] || (int) $expected['m'] !== (int) $file['mtime']
						|| ( isset( $expected['o'] ) && (int) $expected['o'] !== (int) $file['mode'] ) ) {
						self::respond_json( array( 'error' => 'A selected upload changed before its archive started.' ), 409 );
					}
				}
			}
		} else {
			$valid = Tar_Exporter::validate_tree( WP_CONTENT_DIR . '/' . $dir );
			if ( is_wp_error( $valid ) ) {
				self::respond_json( array( 'error' => $valid->get_error_message() ), 500 );
			}
		}

		ignore_user_abort( null === $upload_scopes );
		ini_set( 'zlib.output_compression', 'Off' );

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		if ( headers_sent() ) {
			http_response_code( 500 );
			exit;
		}

		// Client-requested gzip: the tar stream is compressed on the fly
		// through an output handler (the exporter echoes, the handler
		// deflates). uploads/ holds already-compressed media, so it gets the
		// cheapest level; code/text dirs compress hard.
		$gzip = null === $upload_scopes && ! empty( $_GET['gz'] ) && function_exists( 'deflate_init' );

		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . $dir . ( $gzip ? '.tar.gz' : '.tar' ) . '"' );
		header( 'Content-Encoding: identity' );
		header( 'X-Accel-Buffering: no' );
		header( 'Cache-Control: no-store' );
		header( 'X-Content-Type-Options: nosniff' );

		if ( $gzip ) {
			$deflate = deflate_init( ZLIB_ENCODING_GZIP, array( 'level' => 'uploads' === $dir ? 1 : 6 ) );
			ob_start(
				function ( $buffer, $phase ) use ( $deflate ) {
					return deflate_add( $deflate, $buffer, ( $phase & PHP_OUTPUT_HANDLER_FINAL ) ? ZLIB_FINISH : ZLIB_SYNC_FLUSH );
				},
				524288
			);
		}

		// Streams an empty (EOT-only) archive when the directory is missing,
		// which the sandbox treats as "nothing to sync".
		if ( null !== $upload_scopes ) {
			$streamed = Tar_Exporter::stream_upload_scope_nodes( $baseline_nodes, $baseline_checkpoint, $resume_after );
			if ( is_wp_error( $streamed ) ) {
				// Headers are out; exiting without the end-of-archive marker is
				// the deliberate failure signal for the streaming extractor.
				error_log( 'sandbox-sync baseline stream: aborting - ' . $streamed->get_error_message() );
			}
			exit;
		} elseif ( null !== $files ) {
			Tar_Exporter::stream_verified_paths( WP_CONTENT_DIR . '/' . $dir, $files, $dir, $nonce, Auth::get_bearer_token() );
		} elseif ( null !== $paths ) {
			Tar_Exporter::stream_paths( WP_CONTENT_DIR . '/' . $dir, $paths );
		} else {
			Tar_Exporter::stream( WP_CONTENT_DIR . '/' . $dir );
		}
		exit;
	}
}
