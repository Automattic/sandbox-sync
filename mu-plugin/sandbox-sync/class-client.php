<?php
/**
 * Sandbox-side HTTP client for talking to the paired source (live) site.
 *
 * JSON requests go over a shared keep-alive curl handle (one TLS handshake
 * for the whole sync instead of one per chunk); tar downloads support
 * on-the-fly gzip and parallel (curl_multi) batches. Every curl path falls
 * back to the WP HTTP API when curl is unavailable.
 */

namespace SandboxSync;

defined( 'ABSPATH' ) || exit;

class Client {

	const OPT_TRANSFER_ID         = 'sandbox_sync_transfer_id';
	const OPT_TRANSFER_RENEWED_AT = 'sandbox_sync_transfer_renewed_at';
	const TRANSFER_RENEW_INTERVAL = 900; // One source keepalive per 15 minutes of local work.
	const TIMEOUT                 = 600;
	const MAX_TAR_BYTES           = 10737418240; // 10 GiB decompressed safety ceiling, filterable.
	const MAX_JSON_BYTES          = 67108864; // 64 MiB compressed/on-wire response ceiling.
	const MAX_FILE_MANIFEST_BYTES = 536870912; // 512 MiB streamed NDJSON ceiling.

	// Conservative live-site default; filterable up to a hard safety cap of 4.
	const PARALLEL_DOWNLOADS = 2;

	// First-baseline year streams run concurrently; each occupies one source
	// PHP worker for its whole duration, so the cap stays deliberately small.
	const BASELINE_PARALLEL_STREAMS = 4;

	// Abort a baseline stream that trickles below this rate for this long. A
	// stalled TCP connection would otherwise hang the worker forever, since
	// the streams intentionally run without an overall curl timeout.
	const BASELINE_STALL_BYTES_PER_SEC = 1024;
	const BASELINE_STALL_SECONDS       = 120;

	public static function parallel_downloads() {
		return max( 1, min( 4, (int) apply_filters( 'sandbox_sync_parallel_upload_downloads', self::PARALLEL_DOWNLOADS ) ) );
	}

	public static function baseline_parallel_streams() {
		return max( 1, min( 8, (int) apply_filters( 'sandbox_sync_baseline_parallel_streams', self::BASELINE_PARALLEL_STREAMS ) ) );
	}

	/**
	 * Shared curl handle: curl reuses the open connection across requests
	 * on the same handle (keep-alive), which matters when a DB sync makes
	 * hundreds of chunk requests.
	 *
	 * @var resource|\CurlHandle|null
	 */
	private static $curl                    = null;
	private static $last_transport_security = array();

	/**
	 * TLS verification is based on the destination host: public targets are
	 * always verified, while explicitly local development hosts may use their
	 * locally trusted certificates. See Env::ssl_verify().
	 */
	private static function ssl_verify( $url ) {
		return Env::ssl_verify( $url );
	}

	/**
	 * One-time handshake: exchanges the pairing key for a bearer token and
	 * persists the connection on success.
	 *
	 * @return array|\WP_Error Source site info on success.
	 */
	public static function handshake( $source_url, $pairing_key ) {
		$source_url = untrailingslashit( esc_url_raw( $source_url ) );
		$url_error  = self::validate_source_url( $source_url );
		if ( is_wp_error( $url_error ) ) {
			return $url_error;
		}

		$response = wp_remote_post(
			$source_url . '/?sandbox_sync=handshake',
			array(
				'timeout'             => 60,
				'sslverify'           => self::ssl_verify( $source_url ),
				'limit_response_size' => 1048576,
				'headers'             => array( 'Content-Type' => 'application/json' ),
				'body'                => wp_json_encode(
					array(
						'pairing_key' => $pairing_key,
						'sandbox_url' => untrailingslashit( home_url() ),
					)
				),
			)
		);

		$data = self::parse_json_response( $response );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( empty( $data['token'] ) ) {
			return new \WP_Error( 'no_token', 'Handshake succeeded but no token was returned.' );
		}

		$saved = Auth::save_connection( $source_url, $data['token'], isset( $data['token_expires_at'] ) ? (int) $data['token_expires_at'] : 0 );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		return isset( $data['site'] ) ? $data['site'] : array();
	}

	/**
	 * Authenticated GET returning decoded JSON. Uses the shared keep-alive
	 * curl handle when curl is available, the WP HTTP API otherwise.
	 *
	 * @return array|\WP_Error
	 */
	public static function get_json( $action, $query = array() ) {
		$conn = Auth::get_connection();
		if ( ! $conn ) {
			return new \WP_Error( 'not_connected', 'This site is not connected to a source site.' );
		}
		$url_error = self::validate_source_url( $conn['source_url'] );
		if ( is_wp_error( $url_error ) ) {
			return $url_error;
		}

		$url = add_query_arg(
			array_merge( array( 'sandbox_sync' => $action ), self::session_query(), $query ),
			$conn['source_url'] . '/'
		);

		if ( function_exists( 'curl_init' ) ) {
			for ( $attempt = 0; $attempt < 3; $attempt++ ) {
				$result = self::curl_get_json( $url, $conn['token'] );
				if ( ! is_wp_error( $result ) || ! self::is_retryable( $result ) || 2 === $attempt ) {
					return $result;
				}
				usleep( (int) ( 200000 * pow( 2, $attempt ) + random_int( 0, 100000 ) ) );
			}
		}

		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$response = wp_remote_get(
				$url,
				array(
					'timeout'             => self::TIMEOUT,
					'sslverify'           => self::ssl_verify( $url ),
					'limit_response_size' => self::max_json_bytes(),
					'headers'             => array( 'Authorization' => 'Bearer ' . $conn['token'] ),
				)
			);
			$result   = self::parse_json_response( $response );
			if ( ! is_wp_error( $result ) || ! self::is_retryable( $result ) || 2 === $attempt ) {
				return $result;
			}
			usleep( (int) ( 200000 * pow( 2, $attempt ) + random_int( 0, 100000 ) ) );
		}
		return $result;
	}

	/** Authenticated bounded JSON POST with retry/backoff. */
	public static function post_json( $action, $data, $query = array() ) {
		$conn = Auth::get_connection();
		if ( ! $conn ) {
			return new \WP_Error( 'not_connected', 'This site is not connected to a source site.' );
		}
		$url_error = self::validate_source_url( $conn['source_url'] );
		if ( is_wp_error( $url_error ) ) {
			return $url_error;
		}
		$body = wp_json_encode( $data );
		if ( false === $body || strlen( $body ) > 262144 ) {
			return new \WP_Error( 'request_too_large', 'JSON request exceeds the configured transfer limit.' );
		}
		$url = add_query_arg(
			array_merge( array( 'sandbox_sync' => $action ), self::session_query(), $query ),
			$conn['source_url'] . '/'
		);
		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$response = wp_remote_post(
				$url,
				array(
					'timeout'             => self::TIMEOUT,
					'sslverify'           => self::ssl_verify( $url ),
					'limit_response_size' => self::max_json_bytes(),
					'headers'             => array(
						'Authorization' => 'Bearer ' . $conn['token'],
						'Content-Type'  => 'application/json',
					),
					'body'                => $body,
				)
			);
			$result   = self::parse_json_response( $response );
			if ( ! is_wp_error( $result ) || ! self::is_retryable( $result ) || 2 === $attempt ) {
				return $result;
			}
			usleep( (int) ( 200000 * pow( 2, $attempt ) + random_int( 0, 100000 ) ) );
		}
		return $result;
	}

	/**
	 * Fetches independent JSON resources concurrently when curl-multi exists.
	 * Each failed/unavailable async handle falls back to the normal retried GET.
	 *
	 * @param array<string,array> $requests Action => query arguments.
	 * @return array<string,array|\WP_Error>
	 */
	public static function get_json_parallel( $requests ) {
		$conn = Auth::get_connection();
		if ( ! function_exists( 'curl_multi_init' ) || ! $conn || is_wp_error( self::validate_source_url( $conn['source_url'] ) ) ) {
			$results = array();
			foreach ( $requests as $action => $query ) {
				$results[ $action ] = self::get_json( $action, (array) $query );
			}
			return $results;
		}

		$mh      = curl_multi_init();
		$handles = array();
		$urls    = array();
		foreach ( $requests as $action => $query ) {
			$url = add_query_arg(
				array_merge( array( 'sandbox_sync' => $action ), self::session_query(), (array) $query ),
				$conn['source_url'] . '/'
			);
			$ch = curl_init();
			curl_setopt_array( $ch, self::curl_json_options( $url, $conn['token'] ) );
			curl_multi_add_handle( $mh, $ch );
			$handles[ $action ] = $ch;
			$urls[ $action ]    = $url;
		}

		$running = 1;
		$multi_error = null;
		while ( $running > 0 ) {
			$status = curl_multi_exec( $mh, $running );
			if ( CURLM_OK !== $status ) {
				$multi_error = curl_multi_strerror( $status );
				break;
			}
			if ( $running > 0 && -1 === curl_multi_select( $mh, 1.0 ) ) {
				usleep( 1000 );
			}
		}

		$results = array();
		foreach ( $handles as $action => $ch ) {
			$body   = curl_multi_getcontent( $ch );
			$errno  = curl_errno( $ch );
			$code   = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
			$error  = curl_error( $ch );
			self::capture_curl_security( $ch, $urls[ $action ] );
			curl_multi_remove_handle( $mh, $ch );
			curl_close( $ch );
			$result = null !== $multi_error
				? new \WP_Error( 'curl_multi', 'Parallel source metadata request failed: ' . $multi_error )
				: ( 0 !== $errno ? new \WP_Error( 'curl', 'Parallel source metadata request failed: ' . $error ) : self::parse_raw_json( $code, (string) $body ) );
			$results[ $action ] = is_wp_error( $result ) ? self::get_json( $action, (array) $requests[ $action ] ) : $result;
		}
		curl_multi_close( $mh );
		return $results;
	}

	/**
	 * JSON GET over the shared curl handle.
	 *
	 * @return array|\WP_Error
	 */
	private static function curl_get_json( $url, $token ) {
		if ( null === self::$curl ) {
			self::$curl = curl_init();
		}
		$ch = self::$curl;

		curl_setopt_array( $ch, self::curl_json_options( $url, $token ) );

		$body = curl_exec( $ch );
		if ( false === $body ) {
			return new \WP_Error( 'curl', 'Request to the source site failed: ' . curl_error( $ch ) );
		}
		self::capture_curl_security( $ch, $url );

		return self::parse_raw_json( (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE ), (string) $body );
	}

	/**
	 * Common curl options for a JSON GET.
	 */
	private static function curl_json_options( $url, $token ) {
		$verify  = self::ssl_verify( $url );
		$max     = self::max_json_bytes();
		$options = array(
			CURLOPT_URL            => $url,
			CURLOPT_HTTPGET        => true,
			CURLOPT_POST           => false,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_CONNECTTIMEOUT => 30,
			CURLOPT_TIMEOUT        => self::TIMEOUT,
			CURLOPT_SSL_VERIFYPEER => $verify,
			CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
			CURLOPT_ENCODING       => '',
			CURLOPT_HTTPHEADER     => array( 'Authorization: Bearer ' . $token ),
		);
		if ( defined( 'CURLOPT_CERTINFO' ) ) {
			$options[ CURLOPT_CERTINFO ] = true;
		}
		if ( defined( 'CURLOPT_MAXFILESIZE_LARGE' ) ) {
			$options[ CURLOPT_MAXFILESIZE_LARGE ] = $max;
		}
		if ( defined( 'CURLOPT_XFERINFOFUNCTION' ) ) {
			$options[ CURLOPT_NOPROGRESS ]       = false;
			$options[ CURLOPT_XFERINFOFUNCTION ] = function ( $handle, $download_total, $downloaded ) use ( $max ) {
				return $downloaded > $max ? 1 : 0;
			};
		}
		return $options;
	}

	private static function capture_curl_security( $ch, $url ) {
		$parts = wp_parse_url( $url );
		$info  = array(
			'scheme'      => isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : '',
			'host'        => isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '',
			'resolved_ip' => defined( 'CURLINFO_PRIMARY_IP' ) ? (string) curl_getinfo( $ch, CURLINFO_PRIMARY_IP ) : '',
			'verified'    => self::ssl_verify( $url ),
			'subject'     => '',
			'issuer'      => '',
			'expires'     => '',
		);
		if ( defined( 'CURLINFO_CERTINFO' ) ) {
			$certs           = curl_getinfo( $ch, CURLINFO_CERTINFO );
			$leaf            = is_array( $certs ) && isset( $certs[0] ) && is_array( $certs[0] ) ? $certs[0] : array();
			$info['subject'] = isset( $leaf['Subject'] ) ? (string) $leaf['Subject'] : '';
			$info['issuer']  = isset( $leaf['Issuer'] ) ? (string) $leaf['Issuer'] : '';
			$info['expires'] = isset( $leaf['Expire date'] ) ? (string) $leaf['Expire date'] : '';
		}
		self::$last_transport_security = $info;
	}

	public static function transport_security( $url ) {
		$parts = wp_parse_url( $url );
		$host  = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
		if ( ! empty( self::$last_transport_security ) && self::$last_transport_security['host'] === $host ) {
			return self::$last_transport_security;
		}
		return array(
			'scheme'      => isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : '',
			'host'        => $host,
			'resolved_ip' => '',
			'verified'    => self::ssl_verify( $url ),
			'subject'     => '',
			'issuer'      => '',
			'expires'     => '',
		);
	}

	private static function max_json_bytes() {
		return max( 1048576, min( 268435456, (int) apply_filters( 'sandbox_sync_max_json_bytes', self::MAX_JSON_BYTES ) ) );
	}

	// -- Async prefetch ------------------------------------------------------

	/**
	 * Starts a JSON GET without blocking (used to download the next table
	 * chunk while the current one imports). Returns null when curl is
	 * unavailable — callers then fall back to the synchronous path.
	 *
	 * @return array|null Opaque handle for finish_json_async().
	 */
	public static function start_json_async( $action, $query = array() ) {
		if ( ! function_exists( 'curl_multi_init' ) ) {
			return null;
		}
		$conn = Auth::get_connection();
		if ( ! $conn ) {
			return null;
		}
		if ( is_wp_error( self::validate_source_url( $conn['source_url'] ) ) ) {
			return null;
		}

		$url = add_query_arg(
			array_merge( array( 'sandbox_sync' => $action ), self::session_query(), $query ),
			$conn['source_url'] . '/'
		);

		// Dedicated handle: the shared one may be busy with sync requests.
		$ch = curl_init();
		curl_setopt_array( $ch, self::curl_json_options( $url, $conn['token'] ) );

		$mh = curl_multi_init();
		curl_multi_add_handle( $mh, $ch );
		$running = 0;
		curl_multi_exec( $mh, $running ); // Kick off the transfer.

		return array(
			'mh' => $mh,
			'ch' => $ch,
		);
	}

	/**
	 * Blocks until an async JSON GET finishes and returns the decoded body.
	 *
	 * @param array $async Handle from start_json_async().
	 * @return array|\WP_Error
	 */
	public static function finish_json_async( $async ) {
		$mh      = $async['mh'];
		$ch      = $async['ch'];
		$running = 1;

		while ( $running > 0 ) {
			$status = curl_multi_exec( $mh, $running );
			if ( CURLM_OK !== $status ) {
				self::close_json_async( $async );
				return new \WP_Error( 'curl_multi', 'Async request failed in curl_multi: ' . curl_multi_strerror( $status ) );
			}
			if ( $running > 0 ) {
				if ( -1 === curl_multi_select( $mh, 1.0 ) ) {
					usleep( 1000 );
				}
			}
		}

		$body  = curl_multi_getcontent( $ch );
		$errno = curl_errno( $ch );
		$error = curl_error( $ch );
		$code  = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );

		curl_multi_remove_handle( $mh, $ch );
		curl_close( $ch );
		curl_multi_close( $mh );

		if ( 0 !== $errno || null === $body || '' === $body ) {
			return new \WP_Error( 'curl', 'Async request to the source site failed: ' . ( $error ? $error : 'empty response' ) );
		}

		return self::parse_raw_json( $code, (string) $body );
	}

	/** Safely abandons a prefetch that will no longer be consumed. */
	public static function close_json_async( $async ) {
		if ( ! is_array( $async ) || empty( $async['mh'] ) || empty( $async['ch'] ) ) {
			return;
		}
		@curl_multi_remove_handle( $async['mh'], $async['ch'] );
		@curl_close( $async['ch'] );
		@curl_multi_close( $async['mh'] );
	}

	/**
	 * Authenticated streaming download of a directory tar into $dest_file.
	 *
	 * @param string        $dir       One of the syncable dirs.
	 * @param string        $dest_file Local file to stream into.
	 * @param array[]|string[]|null $paths Optional path filter, or uploads
	 *                                      metadata entries for stream hashing.
	 * @return true|\WP_Error
	 */
	public static function download_tar( $dir, $dest_file, $paths = null ) {
		$conn = Auth::get_connection();
		if ( ! $conn ) {
			return new \WP_Error( 'not_connected', 'This site is not connected to a source site.' );
		}
		$url_error = self::validate_source_url( $conn['source_url'] );
		if ( is_wp_error( $url_error ) ) {
			return $url_error;
		}

		$url = self::tar_url( $conn, $dir );

		$args = array(
			'timeout'             => self::TIMEOUT,
			'sslverify'           => self::ssl_verify( $url ),
			'stream'              => true,
			'filename'            => $dest_file,
			'limit_response_size' => self::max_tar_bytes( $dir ),
			'headers'             => array( 'Authorization' => 'Bearer ' . $conn['token'] ),
		);

		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			@unlink( $dest_file );
			if ( null !== $paths ) {
				$args['method']                  = 'POST';
				$args['headers']['Content-Type'] = 'application/json';
				$args['body']                    = self::tar_request_body( $dir, $paths );
				$response                        = wp_remote_request( $url, $args );
			} else {
				$response = wp_remote_get( $url, $args );
			}
			if ( is_wp_error( $response ) ) {
				$error = $response;
			} else {
				$code = wp_remote_retrieve_response_code( $response );
				if ( 200 === $code ) {
					break;
				}
				$body  = file_exists( $dest_file ) ? file_get_contents( $dest_file ) : '';
				$error = new \WP_Error( 'http_' . $code, "Tar download for {$dir} failed (HTTP {$code}): " . substr( (string) $body, 0, 200 ) );
			}
			if ( ! self::is_retryable( $error ) || 2 === $attempt ) {
				return $error;
			}
			usleep( (int) ( 200000 * pow( 2, $attempt ) + random_int( 0, 100000 ) ) );
		}

		return self::finalize_tar_download( $dir, $dest_file );
	}

	/**
	 * Pulls first-sync uploads partitions directly into incremental tar
	 * parsers, running up to baseline_parallel_streams() year/non-date streams
	 * concurrently over curl_multi. No complete archive touches disk and no
	 * gzip is used for already-compressed media payloads.
	 *
	 * Every stream carries its own stall detector (low-speed abort) because
	 * these requests intentionally run with no overall curl timeout.
	 *
	 * @param array         $streams     key => [ 'scopes' => string[],
	 *                                   'temp_dir' => string,
	 *                                   'resume_after' => string ].
	 * @param string        $dest_dir    Uploads directory files install into.
	 * @param callable|null $progress    Called every ~5s with key => stats for
	 *                                   active streams; a WP_Error return
	 *                                   aborts all remaining streams.
	 * @param callable|null $on_complete Called with ($key, $stats) as soon as
	 *                                   one stream finishes, so completion is
	 *                                   durable while the others continue.
	 * @return array|\WP_Error key => stream stats array or WP_Error.
	 */
	public static function stream_uploads_baselines( $streams, $dest_dir, $progress = null, $on_complete = null ) {
		if ( ! function_exists( 'curl_multi_init' ) ) {
			return new \WP_Error( 'bulk_stream_curl', 'The fast first uploads sync requires the PHP cURL extension.' );
		}
		$conn = Auth::get_connection();
		if ( ! $conn ) {
			return new \WP_Error( 'not_connected', 'This site is not connected to a source site.' );
		}
		$url_error = self::validate_source_url( $conn['source_url'] );
		if ( is_wp_error( $url_error ) ) {
			return $url_error;
		}
		if ( empty( $streams ) ) {
			return array();
		}

		$url         = self::tar_url( $conn, 'uploads', false );
		$verify      = self::ssl_verify( $url );
		$concurrency = self::baseline_parallel_streams();
		$stall_rate  = max( 1, (int) apply_filters( 'sandbox_sync_baseline_stall_bytes_per_sec', self::BASELINE_STALL_BYTES_PER_SEC ) );
		$stall_time  = max( 10, (int) apply_filters( 'sandbox_sync_baseline_stall_seconds', self::BASELINE_STALL_SECONDS ) );

		$queue   = array_keys( $streams );
		$active  = array(); // key => [ 'ch' => handle, 'receiver' => extractor ].
		$results = array();
		$mh      = curl_multi_init();

		$close_active = function () use ( &$active, $mh ) {
			foreach ( $active as $entry ) {
				curl_multi_remove_handle( $mh, $entry['ch'] );
				curl_close( $entry['ch'] );
			}
			$active = array();
			curl_multi_close( $mh );
		};

		$start_next = function () use ( &$queue, &$active, &$results, $streams, $dest_dir, $url, $verify, $conn, $stall_rate, $stall_time, $mh ) {
			$key    = array_shift( $queue );
			$stream = $streams[ $key ];
			$scopes = array_values( array_unique( (array) $stream['scopes'] ) );
			$resume = isset( $stream['resume_after'] ) ? (string) $stream['resume_after'] : '';
			$body   = wp_json_encode(
				'' !== $resume
					? array(
						'upload_scopes' => $scopes,
						'resume_after' => $resume,
					)
					: array( 'upload_scopes' => $scopes )
			);
			if ( false === $body ) {
				$results[ $key ] = new \WP_Error( 'bulk_stream_request', 'Cannot encode the selected baseline uploads partitions.' );
				return;
			}
			$max_payload = max( 1073741824, (int) apply_filters( 'sandbox_sync_max_bulk_upload_bytes', 1099511627776, $scopes ) ); // 1 TiB.
			$receiver    = new Tar_Stream_Extractor( $dest_dir, $stream['temp_dir'], $scopes, $max_payload );
			if ( is_wp_error( $receiver->error() ) ) {
				$results[ $key ] = $receiver->error();
				return;
			}
			$ch      = curl_init();
			$options = array(
				CURLOPT_URL             => $url,
				CURLOPT_POST            => true,
				CURLOPT_POSTFIELDS      => $body,
				CURLOPT_FOLLOWLOCATION  => false,
				CURLOPT_CONNECTTIMEOUT  => 30,
				CURLOPT_TIMEOUT         => 0,
				CURLOPT_LOW_SPEED_LIMIT => $stall_rate,
				CURLOPT_LOW_SPEED_TIME  => $stall_time,
				CURLOPT_SSL_VERIFYPEER  => $verify,
				CURLOPT_SSL_VERIFYHOST  => $verify ? 2 : 0,
				CURLOPT_BUFFERSIZE      => 524288,
				CURLOPT_HTTPHEADER      => array(
					'Authorization: Bearer ' . $conn['token'],
					'Content-Type: application/json',
					'Accept-Encoding: identity',
				),
				CURLOPT_WRITEFUNCTION   => function ( $handle, $data ) use ( $receiver ) {
					return $receiver->write( $data );
				},
			);
			if ( defined( 'CURLOPT_TCP_KEEPALIVE' ) ) {
				$options[ CURLOPT_TCP_KEEPALIVE ] = 1;
			}
			curl_setopt_array( $ch, $options );
			curl_multi_add_handle( $mh, $ch );
			$active[ $key ] = array(
				'ch' => $ch,
				'receiver' => $receiver,
			);
		};

		$last_progress = 0;
		while ( ! empty( $queue ) || ! empty( $active ) ) {
			while ( ! empty( $queue ) && count( $active ) < $concurrency ) {
				$start_next();
			}
			if ( empty( $active ) ) {
				break;
			}

			$running = 0;
			$status  = curl_multi_exec( $mh, $running );
			if ( CURLM_OK !== $status ) {
				$error = new \WP_Error( 'curl_multi', 'Parallel baseline streams failed: ' . curl_multi_strerror( $status ) );
				foreach ( array_merge( array_keys( $active ), $queue ) as $key ) {
					$results[ $key ] = $error;
				}
				$close_active();
				return $results;
			}

			// Drain completed handles first so their completion is made durable
			// before any long-running progress work.
			while ( false !== ( $info = curl_multi_info_read( $mh ) ) ) {
				foreach ( $active as $key => $entry ) {
					if ( $entry['ch'] !== $info['handle'] ) {
						continue;
					}
					$errno    = (int) $info['result'];
					$code     = (int) curl_getinfo( $entry['ch'], CURLINFO_RESPONSE_CODE );
					$receiver = $entry['receiver'];
					curl_multi_remove_handle( $mh, $entry['ch'] );
					curl_close( $entry['ch'] );
					unset( $active[ $key ] );

					if ( 200 !== $code ) {
						$results[ $key ] = new \WP_Error( 'http_' . $code, "Baseline uploads stream failed with HTTP {$code}." );
					} elseif ( is_wp_error( $receiver->error() ) ) {
						$results[ $key ] = $receiver->error();
					} elseif ( CURLE_OPERATION_TIMEDOUT === $errno ) {
						$results[ $key ] = new \WP_Error(
							'bulk_stream_stalled',
							sprintf( 'Baseline uploads stream stalled below %d B/s for %d seconds and was aborted; it will resume from its durable cursor.', $stall_rate, $stall_time )
						);
					} elseif ( 0 !== $errno ) {
						$results[ $key ] = new \WP_Error( 'bulk_stream_transport', 'Baseline uploads stream failed: ' . curl_strerror( $errno ) );
					} else {
						$results[ $key ] = $receiver->finish();
					}
					if ( ! is_wp_error( $results[ $key ] ) && is_callable( $on_complete ) ) {
						call_user_func( $on_complete, $key, $results[ $key ] );
					}
					break;
				}
			}

			if ( is_callable( $progress ) && time() - $last_progress >= 5 ) {
				$last_progress = time();
				$snapshot      = array();
				foreach ( $active as $key => $entry ) {
					$snapshot[ $key ] = $entry['receiver']->stats();
				}
				$outcome = call_user_func( $progress, $snapshot );
				if ( is_wp_error( $outcome ) ) {
					foreach ( array_merge( array_keys( $active ), $queue ) as $key ) {
						$results[ $key ] = $outcome;
					}
					$close_active();
					return $results;
				}
			}

			if ( ! empty( $active ) && $running > 0 && -1 === curl_multi_select( $mh, 1.0 ) ) {
				usleep( 1000 );
			}
		}
		curl_multi_close( $mh );
		return $results;
	}

	/**
	 * Streams one table's gzip'd mysqldump (shell fast path) line by line.
	 *
	 * Each complete SQL line is handed to $on_line while the stream is still
	 * in flight. Transfer integrity: the stream must end with the source's
	 * SHA-256 trailer, and the hash of every received line must match it —
	 * otherwise the caller must discard the partial import.
	 *
	 * @param string   $table      Source table name.
	 * @param callable $on_line    fn( string $line_with_newline ): true|WP_Error.
	 * @param callable $checkpoint Optional fn(): true|WP_Error, called every ~5s.
	 * @return array|\WP_Error ['bytes' => raw dump bytes, 'lines' => count].
	 */
	public static function stream_table_dump( $table, $on_line, $checkpoint = null ) {
		if ( ! function_exists( 'curl_init' ) || ! function_exists( 'inflate_init' ) ) {
			return new \WP_Error( 'shell_dump_unavailable', 'The shell dump fast path requires the PHP cURL and zlib extensions.' );
		}
		$conn = Auth::get_connection();
		if ( ! $conn ) {
			return new \WP_Error( 'not_connected', 'This site is not connected to a source site.' );
		}
		$url_error = self::validate_source_url( $conn['source_url'] );
		if ( is_wp_error( $url_error ) ) {
			return $url_error;
		}
		$url = add_query_arg(
			array_merge(
				array(
					'sandbox_sync' => 'table_dump',
					'table'        => $table,
				),
				self::session_query()
			),
			$conn['source_url'] . '/'
		);

		$stall_rate = max( 1, (int) apply_filters( 'sandbox_sync_baseline_stall_bytes_per_sec', self::BASELINE_STALL_BYTES_PER_SEC ) );
		$stall_time = max( 10, (int) apply_filters( 'sandbox_sync_baseline_stall_seconds', self::BASELINE_STALL_SECONDS ) );

		// mysqldump lines are ~net_buffer_length (1 MiB); dumps of very large
		// tables are legitimately huge, so the transfer ceiling is generous.
		$reader = new Db_Shell_Dump_Reader(
			$table,
			$on_line,
			(int) apply_filters( 'sandbox_sync_shell_dump_max_line_bytes', 67108864 ), // 64 MiB.
			(int) apply_filters( 'sandbox_sync_shell_dump_max_bytes', 137438953472 ) // 128 GiB.
		);
		$sink_error      = null;
		$error_body      = '';
		$last_checkpoint = time();

		$ch      = curl_init();
		$options = array(
			CURLOPT_URL             => $url,
			CURLOPT_HTTPGET         => true,
			CURLOPT_FOLLOWLOCATION  => false,
			CURLOPT_CONNECTTIMEOUT  => 30,
			CURLOPT_TIMEOUT         => 0,
			CURLOPT_LOW_SPEED_LIMIT => $stall_rate,
			CURLOPT_LOW_SPEED_TIME  => $stall_time,
			CURLOPT_SSL_VERIFYPEER  => self::ssl_verify( $url ),
			CURLOPT_SSL_VERIFYHOST  => self::ssl_verify( $url ) ? 2 : 0,
			CURLOPT_BUFFERSIZE      => 524288,
			CURLOPT_HTTPHEADER      => array(
				'Authorization: Bearer ' . $conn['token'],
				'Accept-Encoding: identity',
			),
			CURLOPT_WRITEFUNCTION   => function ( $handle, $data ) use ( $reader, $checkpoint, &$sink_error, &$error_body, &$last_checkpoint ) {
				$code = (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE );
				if ( 200 !== $code ) {
					$error_body = substr( $error_body . $data, 0, 65536 );
					return strlen( $data );
				}
				$outcome = $reader->feed( $data );
				if ( is_wp_error( $outcome ) ) {
					$sink_error = $outcome;
					return -1;
				}
				if ( is_callable( $checkpoint ) && time() - $last_checkpoint >= 5 ) {
					$last_checkpoint = time();
					$tick            = call_user_func( $checkpoint );
					if ( is_wp_error( $tick ) ) {
						$sink_error = $tick;
						return -1;
					}
				}
				return strlen( $data );
			},
		);
		if ( defined( 'CURLOPT_TCP_KEEPALIVE' ) ) {
			$options[ CURLOPT_TCP_KEEPALIVE ] = 1;
		}
		curl_setopt_array( $ch, $options );
		curl_exec( $ch );
		$errno = curl_errno( $ch );
		$code  = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
		curl_close( $ch );

		if ( is_wp_error( $sink_error ) ) {
			return $sink_error;
		}
		if ( 200 !== $code ) {
			$parsed = self::parse_raw_json( $code, $error_body );
			return is_wp_error( $parsed ) ? $parsed : new \WP_Error( 'http_' . $code, "Table dump stream failed with HTTP {$code}." );
		}
		if ( CURLE_OPERATION_TIMEDOUT === $errno ) {
			return new \WP_Error( 'shell_dump_stalled', sprintf( 'The %s dump stream stalled below %d B/s for %d seconds and was aborted.', $table, $stall_rate, $stall_time ) );
		}
		if ( 0 !== $errno ) {
			return new \WP_Error( 'shell_dump_transport', "The {$table} dump stream failed: " . curl_strerror( $errno ) );
		}
		// Full wire validation: clean gzip end, single exact trailer, hash.
		return $reader->finish();
	}

	/**
	 * Streams a potentially very large file manifest as NDJSON to disk.
	 *
	 * @return int|\WP_Error Validated entry count.
	 */
	public static function download_file_manifest( $dir, $dest_file, $hashes = false, $scope = '' ) {
		$conn = Auth::get_connection();
		if ( ! $conn ) {
			return new \WP_Error( 'not_connected', 'This site is not connected to a source site.' );
		}
		$url_error = self::validate_source_url( $conn['source_url'] );
		if ( is_wp_error( $url_error ) ) {
			return $url_error;
		}
		$url  = add_query_arg(
			array_merge(
				array(
					'sandbox_sync' => 'files_manifest',
					'dir'          => $dir,
					'format'       => 'ndjson',
					'hashes'       => $hashes ? 1 : 0,
					'scope'        => $scope,
				),
				self::session_query()
			),
			$conn['source_url'] . '/'
		);
		$args = array(
			'timeout'             => self::TIMEOUT,
			'sslverify'           => self::ssl_verify( $url ),
			'stream'              => true,
			'filename'            => $dest_file,
			'limit_response_size' => self::max_tar_bytes( $dest_file ),
			'headers'             => array( 'Authorization' => 'Bearer ' . $conn['token'] ),
		);
		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			@unlink( $dest_file );
			$response = wp_remote_get( $url, $args );
			if ( is_wp_error( $response ) ) {
				$error = $response;
			} else {
				$code = wp_remote_retrieve_response_code( $response );
				if ( 200 === $code ) {
					$size = is_file( $dest_file ) ? filesize( $dest_file ) : false;
					if ( false === $size || $size > self::max_file_manifest_bytes() ) {
						return new \WP_Error( 'manifest_size', 'File manifest is missing or exceeds the configured transfer limit.' );
					}
					$valid = Files::validate_ndjson_manifest( $dest_file, $hashes );
					return $valid;
				}
				$error = new \WP_Error( 'http_' . $code, "File manifest download for {$dir} failed with HTTP {$code}." );
			}
			if ( ! self::is_retryable( $error ) || 2 === $attempt ) {
				return $error;
			}
			usleep( (int) ( 200000 * pow( 2, $attempt ) + random_int( 0, 100000 ) ) );
		}
		return new \WP_Error( 'manifest_download', "File manifest download for {$dir} failed." );
	}

	/**
	 * Best-effort live source traversal counters for the admin status poll.
	 * This deliberately uses one short, non-retried request so observability
	 * can never delay or fail the transfer itself.
	 *
	 * @return array|null|\WP_Error
	 */
	public static function get_transfer_progress() {
		$conn = Auth::get_connection();
		$id   = get_option( self::OPT_TRANSFER_ID, '' );
		if ( ! $conn || ! is_string( $id ) || ! preg_match( '/^[a-f0-9]{64}$/', $id ) ) {
			return null;
		}
		$url_error = self::validate_source_url( $conn['source_url'] );
		if ( is_wp_error( $url_error ) ) {
			return $url_error;
		}
		$url      = add_query_arg(
			array_merge( array( 'sandbox_sync' => 'progress' ), self::session_query() ),
			$conn['source_url'] . '/'
		);
		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => 3,
				'sslverify'           => self::ssl_verify( $url ),
				'limit_response_size' => 65536,
				'headers'             => array( 'Authorization' => 'Bearer ' . $conn['token'] ),
			)
		);
		$result = self::parse_json_response( $response );
		if ( is_wp_error( $result ) || empty( $result['progress'] ) || ! is_array( $result['progress'] ) ) {
			return is_wp_error( $result ) ? $result : null;
		}
		$progress = $result['progress'];
		if ( ! isset( $progress['dir'], $progress['phase'], $progress['files'], $progress['bytes'], $progress['done'], $progress['updated_at'] )
			|| ! in_array( $progress['dir'], Source_Endpoints::SYNCABLE_DIRS, true )
			|| ! in_array( $progress['phase'], array( 'source_manifest', 'source_hashes' ), true ) ) {
			return new \WP_Error( 'progress_response', 'Source returned malformed transfer progress.' );
		}
		return array(
			'dir'        => $progress['dir'],
			'phase'      => $progress['phase'],
			'files'      => max( 0, (int) $progress['files'] ),
			'bytes'      => max( 0, (int) $progress['bytes'] ),
			'total_files' => max( 0, (int) ( $progress['total_files'] ?? 0 ) ),
			'total_bytes' => max( 0, (int) ( $progress['total_bytes'] ?? 0 ) ),
			'done'       => (bool) $progress['done'],
			'updated_at' => max( 0, (int) $progress['updated_at'] ),
		);
	}

	/**
	 * Best-effort read of the live plugin list for a running job's plugin
	 * comparison. Like get_transfer_progress(), this is one short, non-retried
	 * request: the comparison is informational, and the retried 600-second
	 * get_json() path could hold the manifest step long enough for the job's
	 * heartbeat to go stale and another worker to take over the lease.
	 *
	 * @return array|\WP_Error The source's `plugins` response.
	 */
	public static function get_source_plugins() {
		$conn = Auth::get_connection();
		if ( ! $conn ) {
			return new \WP_Error( 'not_connected', 'This site is not connected to a source site.' );
		}
		$url_error = self::validate_source_url( $conn['source_url'] );
		if ( is_wp_error( $url_error ) ) {
			return $url_error;
		}
		$url = add_query_arg( array( 'sandbox_sync' => 'plugins' ), $conn['source_url'] . '/' );
		return self::parse_json_response(
			wp_remote_get(
				$url,
				array(
					'timeout'             => 10,
					'sslverify'           => self::ssl_verify( $url ),
					'limit_response_size' => 1048576,
					'headers'             => array( 'Authorization' => 'Bearer ' . $conn['token'] ),
				)
			)
		);
	}

	private static function max_file_manifest_bytes() {
		return max( 1048576, min( 1073741824, (int) apply_filters( 'sandbox_sync_max_file_manifest_bytes', self::MAX_FILE_MANIFEST_BYTES ) ) );
	}

	/**
	 * Downloads one large file in bounded ranges. Completed ranges remain in
	 * the job-owned partial file, so retries and replacement workers continue
	 * from the last fsynced byte instead of restarting the object.
	 *
	 * @param string        $dir        Syncable wp-content directory.
	 * @param array         $entry      Hashed source manifest entry.
	 * @param string        $dest_file  Job-owned partial-download path.
	 * @param callable|null $checkpoint Called before and after each range.
	 * @return true|\WP_Error
	 */
	public static function download_file_resumable( $dir, $entry, $dest_file, $checkpoint = null ) {
		if ( ! is_array( $entry ) || ! isset( $entry['p'], $entry['s'], $entry['m'], $entry['h'] )
			|| ! Tar_Exporter::is_safe_relative_path( $entry['p'] ) || ! preg_match( '/^[a-f0-9]{64}$/', (string) $entry['h'] )
			|| ! is_numeric( $entry['s'] ) || (int) $entry['s'] < 0 || (int) $entry['s'] > self::max_tar_bytes( $entry['p'] ) ) {
			return new \WP_Error( 'file_resume_data', 'Resumable file metadata is malformed or exceeds the configured file limit.' );
		}
		$conn = Auth::get_connection();
		if ( ! $conn ) {
			return new \WP_Error( 'not_connected', 'This site is not connected to a source site.' );
		}
		$url_error = self::validate_source_url( $conn['source_url'] );
		if ( is_wp_error( $url_error ) ) {
			return $url_error;
		}
		$parent = dirname( $dest_file );
		if ( ! is_dir( $parent ) && ! mkdir( $parent, 0700, true ) ) {
			return new \WP_Error( 'file_resume_dir', 'Cannot create the resumable download cache.' );
		}

		$expected_size = (int) $entry['s'];
		if ( 0 === $expected_size ) {
			$created = file_put_contents( $dest_file, '', LOCK_EX );
			if ( 0 !== $created || ! hash_equals( (string) $entry['h'], hash_file( 'sha256', $dest_file ) ) ) {
				@unlink( $dest_file );
				return new \WP_Error( 'file_resume_hash', "Empty file failed SHA-256 validation for {$entry['p']}." );
			}
			@chmod( $dest_file, 0600 );
			return true;
		}
		$offset        = is_file( $dest_file ) && ! is_link( $dest_file ) ? filesize( $dest_file ) : 0;
		if ( false === $offset || $offset > $expected_size || is_link( $dest_file ) ) {
			@unlink( $dest_file );
			$offset = 0;
		}
		if ( $offset === $expected_size && $expected_size > 0 ) {
			$cached_hash = hash_file( 'sha256', $dest_file );
			if ( false !== $cached_hash && hash_equals( (string) $entry['h'], $cached_hash ) ) {
				return true;
			}
			@unlink( $dest_file );
			$offset = 0;
		}

		while ( $offset < $expected_size ) {
			if ( is_callable( $checkpoint ) ) {
				$checked = call_user_func( $checkpoint );
				if ( is_wp_error( $checked ) ) {
					return $checked;
				}
			}
			$url = add_query_arg(
				array_merge(
					array(
						'sandbox_sync' => 'file',
						'dir'          => $dir,
						'path'         => $entry['p'],
						'offset'       => $offset,
						'size'         => $expected_size,
						'mtime'        => (int) $entry['m'],
						'sha256'       => $entry['h'],
					),
					self::session_query()
				),
				$conn['source_url'] . '/'
			);
			$chunk_file = $dest_file . '.chunk';
			$error      = null;
			for ( $attempt = 0; $attempt < 3; $attempt++ ) {
				@unlink( $chunk_file );
				$response = wp_remote_get(
					$url,
					array(
						'timeout'             => self::TIMEOUT,
						'sslverify'           => self::ssl_verify( $url ),
						'stream'              => true,
						'filename'            => $chunk_file,
						'limit_response_size' => Files::BATCH_MAX_BYTES,
						'headers'             => array( 'Authorization' => 'Bearer ' . $conn['token'] ),
					)
				);
				if ( is_wp_error( $response ) ) {
					$error = $response;
				} else {
					$code = wp_remote_retrieve_response_code( $response );
					if ( 206 === $code ) {
						$range = (string) wp_remote_retrieve_header( $response, 'content-range' );
						$hash  = strtolower( (string) wp_remote_retrieve_header( $response, 'x-sandbox-sync-sha256' ) );
						$mtime = (string) wp_remote_retrieve_header( $response, 'x-sandbox-sync-mtime' );
						$mode  = (string) wp_remote_retrieve_header( $response, 'x-sandbox-sync-mode' );
						if ( preg_match( '/^bytes (\d+)-(\d+)\/(\d+)$/', $range, $matches )
							&& $offset === (int) $matches[1] && (int) $matches[2] >= $offset && $expected_size === (int) $matches[3]
							&& hash_equals( (string) $entry['h'], $hash ) && (int) $entry['m'] === (int) $mtime
							&& ( ! isset( $entry['o'] ) || (int) $entry['o'] === (int) $mode ) ) {
							$chunk_size = is_file( $chunk_file ) ? filesize( $chunk_file ) : false;
							$announced  = (int) $matches[2] - (int) $matches[1] + 1;
							if ( false !== $chunk_size && $announced === (int) $chunk_size && $announced <= Files::BATCH_MAX_BYTES ) {
								$error = null;
								break;
							}
						}
						$error = new \WP_Error( 'file_range', 'Source returned a malformed, truncated, or mismatched file range.' );
					} else {
						$body  = is_file( $chunk_file ) ? file_get_contents( $chunk_file ) : '';
						$data  = json_decode( (string) $body, true );
						$detail = is_array( $data ) && isset( $data['error'] ) ? $data['error'] : substr( (string) $body, 0, 200 );
						$error  = new \WP_Error( 'http_' . $code, "Resumable file request failed (HTTP {$code}): {$detail}" );
					}
				}
				if ( ! self::is_retryable( $error ) || 2 === $attempt ) {
					@unlink( $chunk_file );
					return $error;
				}
				usleep( (int) ( 200000 * pow( 2, $attempt ) + random_int( 0, 100000 ) ) );
			}

			$appended = self::append_file_exact( $chunk_file, $dest_file );
			@unlink( $chunk_file );
			if ( is_wp_error( $appended ) ) {
				return $appended;
			}
			clearstatcache( true, $dest_file );
			$offset = filesize( $dest_file );
			if ( false === $offset || $offset > $expected_size ) {
				return new \WP_Error( 'file_resume_size', 'Resumable download exceeded its declared size.' );
			}
			if ( is_callable( $checkpoint ) ) {
				$checked = call_user_func( $checkpoint );
				if ( is_wp_error( $checked ) ) {
					return $checked;
				}
			}
		}

		clearstatcache( true, $dest_file );
		$actual_hash = hash_file( 'sha256', $dest_file );
		if ( false === $actual_hash || ! hash_equals( (string) $entry['h'], $actual_hash ) ) {
			@unlink( $dest_file );
			return new \WP_Error( 'file_resume_hash', "Resumable download failed SHA-256 validation for {$entry['p']}." );
		}
		@chmod( $dest_file, 0600 );
		return true;
	}

	/** @return true|\WP_Error */
	private static function append_file_exact( $source, $dest ) {
		$in  = fopen( $source, 'rb' );
		$out = fopen( $dest, 'ab' );
		if ( ! $in || ! $out ) {
			if ( $in ) {
				fclose( $in );
			}
			if ( $out ) {
				fclose( $out );
			}
			return new \WP_Error( 'file_resume_open', 'Cannot open a resumable file range for assembly.' );
		}
		while ( ! feof( $in ) ) {
			$chunk = fread( $in, 1048576 );
			if ( false === $chunk ) {
				fclose( $in );
				fclose( $out );
				return new \WP_Error( 'file_resume_read', 'Cannot read a downloaded file range.' );
			}
			$written = 0;
			while ( $written < strlen( $chunk ) ) {
				$count = fwrite( $out, substr( $chunk, $written ) );
				if ( false === $count || 0 === $count ) {
					fclose( $in );
					fclose( $out );
					return new \WP_Error( 'file_resume_write', 'Short write while assembling a resumable file.' );
				}
				$written += $count;
			}
		}
		$flushed = fflush( $out );
		if ( function_exists( 'fsync' ) ) {
			$flushed = @fsync( $out ) && $flushed;
		}
		fclose( $in );
		fclose( $out );
		return $flushed ? true : new \WP_Error( 'file_resume_flush', 'Could not durably flush a resumable file range.' );
	}

	/**
	 * Downloads several path batches of one directory concurrently
	 * (curl_multi). Falls back to sequential downloads without curl.
	 *
	 * @param string        $dir         One of the syncable dirs.
	 * @param array[]       $batches     Arrays of relative paths, one per download.
	 * @param string[]      $dest_files  Destination file per batch (same count).
	 * @param callable|null $checkpoint  Optional fn(): true|WP_Error, polled ~every 5s.
	 * @return true|\WP_Error First error encountered, if any.
	 */
	public static function download_tars_parallel( $dir, $batches, $dest_files, $checkpoint = null ) {
		if ( ! function_exists( 'curl_multi_init' ) || count( $batches ) < 2 ) {
			foreach ( $batches as $i => $paths ) {
				if ( is_callable( $checkpoint ) ) {
					$checked = call_user_func( $checkpoint );
					if ( is_wp_error( $checked ) ) {
						return $checked;
					}
				}
				$result = self::download_tar( $dir, $dest_files[ $i ], $paths );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}
			return true;
		}

		$conn = Auth::get_connection();
		if ( ! $conn ) {
			return new \WP_Error( 'not_connected', 'This site is not connected to a source site.' );
		}
		$url_error = self::validate_source_url( $conn['source_url'] );
		if ( is_wp_error( $url_error ) ) {
			return $url_error;
		}

		$url     = self::tar_url( $conn, $dir );
		$verify  = self::ssl_verify( $url );
		$max_tar = self::max_tar_bytes( $dir );

		$mh      = curl_multi_init();
		$handles = array();
		$streams = array();

		foreach ( $batches as $i => $paths ) {
			$fp = fopen( $dest_files[ $i ], 'wb' );
			if ( ! $fp ) {
				foreach ( $handles as $j => $open_ch ) {
					curl_multi_remove_handle( $mh, $open_ch );
					curl_close( $open_ch );
					fclose( $streams[ $j ] );
				}
				curl_multi_close( $mh );
				return new \WP_Error( 'tmp_open', "Cannot open temp file for {$dir} batch {$i}." );
			}
			$ch      = curl_init();
			$options = array(
				CURLOPT_URL            => $url,
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => self::tar_request_body( $dir, $paths ),
				CURLOPT_FILE           => $fp,
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_CONNECTTIMEOUT => 30,
				CURLOPT_TIMEOUT        => self::TIMEOUT,
				CURLOPT_SSL_VERIFYPEER => $verify,
				CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
				CURLOPT_HTTPHEADER     => array(
					'Authorization: Bearer ' . $conn['token'],
					'Content-Type: application/json',
				),
			);
			if ( defined( 'CURLOPT_MAXFILESIZE_LARGE' ) ) {
				$options[ CURLOPT_MAXFILESIZE_LARGE ] = $max_tar;
			}
			if ( defined( 'CURLOPT_XFERINFOFUNCTION' ) ) {
				$options[ CURLOPT_NOPROGRESS ]       = false;
				$options[ CURLOPT_XFERINFOFUNCTION ] = function ( $handle, $download_total, $downloaded ) use ( $max_tar ) {
					return $downloaded > $max_tar ? 1 : 0;
				};
			}
			curl_setopt_array( $ch, $options );
			curl_multi_add_handle( $mh, $ch );
			$handles[ $i ] = $ch;
			$streams[ $i ] = $fp;
		}

		$running          = 1;
		$multi_error      = null;
		$last_checkpoint  = time();
		while ( $running > 0 ) {
			$status = curl_multi_exec( $mh, $running );
			if ( CURLM_OK !== $status ) {
				$multi_error = curl_multi_strerror( $status );
				break;
			}
			if ( is_callable( $checkpoint ) && time() - $last_checkpoint >= 5 ) {
				$last_checkpoint = time();
				$checked         = call_user_func( $checkpoint );
				if ( is_wp_error( $checked ) ) {
					foreach ( $handles as $j => $open_ch ) {
						curl_multi_remove_handle( $mh, $open_ch );
						curl_close( $open_ch );
						fclose( $streams[ $j ] );
						@unlink( $dest_files[ $j ] );
					}
					curl_multi_close( $mh );
					return $checked;
				}
			}
			if ( $running > 0 ) {
				if ( -1 === curl_multi_select( $mh, 1.0 ) ) {
					usleep( 1000 );
				}
			}
		}

		$error = null;
		foreach ( $handles as $i => $ch ) {
			$errno   = curl_errno( $ch );
			$err_msg = curl_error( $ch );
			$code    = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
			curl_multi_remove_handle( $mh, $ch );
			curl_close( $ch );
			fclose( $streams[ $i ] );

			if ( null !== $error ) {
				continue;
			}
			if ( null !== $multi_error ) {
				$error = new \WP_Error( 'curl_multi', "Parallel tar download failed: {$multi_error}" );
			} elseif ( 0 !== $errno ) {
				$error = new \WP_Error( 'curl', "Tar download for {$dir} (batch {$i}) failed: " . $err_msg );
			} elseif ( 200 !== $code ) {
				$error = new \WP_Error( 'http_' . $code, "Tar download for {$dir} (batch {$i}) failed with HTTP {$code}." );
			} else {
				$finalized = self::finalize_tar_download( $dir, $dest_files[ $i ] );
				if ( is_wp_error( $finalized ) ) {
					$error = $finalized;
				}
			}
		}
		curl_multi_close( $mh );

		if ( null !== $error && self::is_retryable( $error ) ) {
			// Retry the complete idempotent batch set sequentially; download_tar
			// supplies bounded backoff and replaces each temporary file.
			foreach ( $batches as $i => $paths ) {
				$result = self::download_tar( $dir, $dest_files[ $i ], $paths );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}
			return true;
		}
		return null !== $error ? $error : true;
	}

	/** Stable request binding, recoverable when a cached archive survives a worker. */
	public static function upload_archive_nonce( $dir, $entries ) {
		$id = get_option( self::OPT_TRANSFER_ID, '' );
		return hash( 'sha256', (string) $id . "\0" . (string) $dir . "\0" . wp_json_encode( array_values( $entries ) ) );
	}

	private static function tar_request_body( $dir, $batch ) {
		$batch = array_values( (array) $batch );
		if ( 'uploads' === $dir && ! empty( $batch ) && is_array( $batch[0] ) ) {
			return wp_json_encode(
				array(
					'files' => $batch,
					'nonce' => self::upload_archive_nonce( $dir, $batch ),
				)
			);
		}
		return wp_json_encode( array( 'paths' => $batch ) );
	}

	/**
	 * Tar endpoint URL. Ordinary batches request gzip; the first uploads
	 * baseline deliberately streams raw tar to minimize media CPU and latency.
	 */
	private static function tar_url( $conn, $dir, $gzip = true ) {
		return add_query_arg(
			array_merge(
				array(
					'sandbox_sync' => 'tar',
					'dir'          => $dir,
					'gz'           => $gzip ? 1 : 0,
				),
				self::session_query()
			),
			$conn['source_url'] . '/'
		);
	}

	private static function session_query() {
		$transfer_id = get_option( self::OPT_TRANSFER_ID, '' );
		if ( is_string( $transfer_id ) && preg_match( '/^[a-f0-9]{64}$/', $transfer_id ) ) {
			return array( 'transfer_id' => $transfer_id );
		}
		return array();
	}

	/** Drops local stale state before a newly created job opens its own grant. */
	public static function reset_transfer_session() {
		delete_option( self::OPT_TRANSFER_ID );
		delete_option( self::OPT_TRANSFER_RENEWED_AT );
	}

	/** Opens an idempotent, expiring grant scoped to this job's selections. */
	public static function begin_transfer_session( $options ) {
		$id = get_option( self::OPT_TRANSFER_ID, '' );
		if ( ! is_string( $id ) || ! preg_match( '/^[a-f0-9]{64}$/', $id ) ) {
			$id = bin2hex( random_bytes( 32 ) );
			update_option( self::OPT_TRANSFER_ID, $id, false );
		}
		$result = self::post_json(
			'transfer',
			array(
				'operation'            => 'begin',
				'transfer_id'          => $id,
				'database'             => ! empty( $options['db'] ),
				'dirs'                 => isset( $options['dirs'] ) ? array_values( (array) $options['dirs'] ) : array(),
				'constants'            => isset( $options['config_constants'] ) ? array_values( array_unique( (array) $options['config_constants'] ) ) : array(),
				'schema_only_suffixes' => isset( $options['schema_only_tables'] ) ? array_values( array_unique( (array) $options['schema_only_tables'] ) ) : array(),
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( empty( $result['transfer_id'] ) || ! hash_equals( $id, (string) $result['transfer_id'] ) ) {
			return new \WP_Error( 'transfer_response', 'Source did not confirm ownership of the scoped transfer session.' );
		}
		update_option( self::OPT_TRANSFER_RENEWED_AT, time(), false );
		return true;
	}

	/** Narrows a database grant to exact tables and binds rowless policies. */
	public static function restrict_transfer_tables( $tables, $schema_only_tables = array() ) {
		$id = get_option( self::OPT_TRANSFER_ID, '' );
		if ( ! is_string( $id ) || ! preg_match( '/^[a-f0-9]{64}$/', $id ) ) {
			return new \WP_Error( 'transfer_missing', 'No source transfer session is available to scope.' );
		}
		$result = self::post_json(
			'transfer',
			array(
				'operation'          => 'scope',
				'transfer_id'        => $id,
				'tables'             => array_values( array_unique( (array) $tables ) ),
				'schema_only_tables' => array_values( array_unique( (array) $schema_only_tables ) ),
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$confirmed = isset( $result['table_count'], $result['schema_only_table_count'] )
			&& count( array_unique( (array) $tables ) ) === (int) $result['table_count']
			&& count( array_unique( (array) $schema_only_tables ) ) === (int) $result['schema_only_table_count']
			? true
			: new \WP_Error( 'transfer_scope_response', 'Source did not confirm the complete table scope.' );
		if ( true === $confirmed ) {
			update_option( self::OPT_TRANSFER_RENEWED_AT, time(), false );
		}
		return $confirmed;
	}

	/**
	 * Keeps an active scoped grant alive and repairs only an expired/missing
	 * grant by recreating the same ID and exact job scope. Other 403s fail closed.
	 *
	 * @return true|string|\WP_Error `reopened` when an expired grant was repaired.
	 */
	public static function maintain_transfer_session( $options, $tables = array(), $schema_only_tables = array() ) {
		$id = get_option( self::OPT_TRANSFER_ID, '' );
		if ( ! is_string( $id ) || ! preg_match( '/^[a-f0-9]{64}$/', $id ) ) {
			return true;
		}
		$renewed_at = (int) get_option( self::OPT_TRANSFER_RENEWED_AT, 0 );
		if ( $renewed_at > 0 && time() - $renewed_at < self::TRANSFER_RENEW_INTERVAL ) {
			return true;
		}

		$result = self::post_json(
			'transfer',
			array(
				'operation'   => 'renew',
				'transfer_id' => $id,
			)
		);
		if ( ! is_wp_error( $result ) ) {
			if ( empty( $result['renewed'] ) || empty( $result['transfer_id'] ) || ! hash_equals( $id, (string) $result['transfer_id'] ) ) {
				return new \WP_Error( 'transfer_renew_response', 'Source did not confirm renewal of the scoped transfer session.' );
			}
			update_option( self::OPT_TRANSFER_RENEWED_AT, time(), false );
			return true;
		}

		$source_code    = self::source_error_code( $result );
		$rolling_legacy = 'http_400' === $result->get_error_code() && false !== strpos( $result->get_error_message(), 'Unknown transfer-session operation' );
		if ( 'transfer_owner' !== $source_code && ! $rolling_legacy ) {
			return $result;
		}

		$reopened = self::begin_transfer_session( $options );
		if ( is_wp_error( $reopened ) ) {
			return $reopened;
		}
		if ( ! empty( $options['db'] ) && ! empty( $tables ) ) {
			$scoped = self::restrict_transfer_tables( $tables, $schema_only_tables );
			if ( is_wp_error( $scoped ) ) {
				return $scoped;
			}
		}
		return 'reopened';
	}

	/**
	 * Best-effort immediate abort signal for an in-flight source operation.
	 * A short, non-retried request keeps the local Cancel action responsive.
	 *
	 * @return true|\WP_Error
	 */
	public static function request_transfer_cancel() {
		$conn = Auth::get_connection();
		$id   = get_option( self::OPT_TRANSFER_ID, '' );
		if ( ! $conn || ! is_string( $id ) || ! preg_match( '/^[a-f0-9]{64}$/', $id ) ) {
			return true;
		}
		$url_error = self::validate_source_url( $conn['source_url'] );
		if ( is_wp_error( $url_error ) ) {
			return $url_error;
		}
		$url      = add_query_arg(
			array_merge( array( 'sandbox_sync' => 'transfer' ), self::session_query() ),
			$conn['source_url'] . '/'
		);
		$response = wp_remote_post(
			$url,
			array(
				'timeout'             => 5,
				'sslverify'           => self::ssl_verify( $url ),
				'limit_response_size' => 65536,
				'headers'             => array(
					'Authorization' => 'Bearer ' . $conn['token'],
					'Content-Type'  => 'application/json',
				),
				'body'                => wp_json_encode(
					array(
						'operation'   => 'cancel',
						'transfer_id' => $id,
					)
				),
			)
		);
		$result = self::parse_json_response( $response );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return ! empty( $result['cancel_requested'] )
			? true
			: new \WP_Error( 'transfer_cancel_response', 'Source did not acknowledge the transfer cancellation.' );
	}

	/** Closes the scoped grant; expiry remains a fail-safe if the source is down. */
	public static function end_transfer_session() {
		$id = get_option( self::OPT_TRANSFER_ID, '' );
		if ( ! is_string( $id ) || ! preg_match( '/^[a-f0-9]{64}$/', $id ) ) {
			return true;
		}
		$result = self::post_json(
			'transfer',
			array(
				'operation'   => 'end',
				'transfer_id' => $id,
			)
		);
		if ( is_wp_error( $result ) && ! in_array( $result->get_error_code(), array( 'http_403', 'http_404' ), true ) ) {
			return $result;
		}
		delete_option( self::OPT_TRANSFER_ID );
		delete_option( self::OPT_TRANSFER_RENEWED_AT );
		return true;
	}

	/**
	 * Post-download processing: decompress in place when the payload is
	 * gzipped (magic bytes 1f 8b), then sanity-check the archive size.
	 *
	 * @return true|\WP_Error
	 */
	private static function finalize_tar_download( $dir, $dest_file ) {
		if ( ! file_exists( $dest_file ) ) {
			return new \WP_Error( 'tar_missing', "Tar download for {$dir} produced no file." );
		}

		$head = (string) file_get_contents( $dest_file, false, null, 0, 2 );
		if ( "\x1f\x8b" === $head ) {
			$result = self::gunzip_file( $dest_file );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		// A valid archive always ends with the 1024-byte end-of-archive
		// marker, so anything smaller than that is a truncated stream.
		$size = filesize( $dest_file );
		if ( $size < 1024 ) {
			return new \WP_Error( 'tar_truncated', "Tar download for {$dir} looks truncated." );
		}
		$tail = file_get_contents( $dest_file, false, null, $size - 1024, 1024 );
		if ( str_repeat( "\0", 1024 ) !== $tail ) {
			return new \WP_Error( 'tar_truncated', "Tar download for {$dir} is missing its end-of-archive marker." );
		}

		return true;
	}

	/** Validates (and, when needed, decompresses) a cached archive. */
	public static function validate_tar_archive( $dir, $file ) {
		return self::finalize_tar_download( $dir, $file );
	}

	/**
	 * Streams a .gz file through zlib into its decompressed replacement.
	 *
	 * @return true|\WP_Error
	 */
	private static function gunzip_file( $file ) {
		$in = gzopen( $file, 'rb' );
		if ( ! $in ) {
			return new \WP_Error( 'gz_open', "Cannot open gzipped download {$file}." );
		}
		$tmp = $file . '.raw';
		$out = fopen( $tmp, 'wb' );
		if ( ! $out ) {
			gzclose( $in );
			return new \WP_Error( 'gz_tmp', "Cannot create {$tmp} for decompression." );
		}

		$written = 0;
		$limit   = self::max_tar_bytes( $file );
		while ( ! gzeof( $in ) ) {
			$chunk = gzread( $in, 1048576 );
			if ( false === $chunk ) {
				gzclose( $in );
				fclose( $out );
				@unlink( $tmp );
				return new \WP_Error( 'gz_read', "Corrupt gzip stream in {$file}." );
			}
			$written += strlen( $chunk );
			if ( $written > $limit ) {
				gzclose( $in );
				fclose( $out );
				@unlink( $tmp );
				return new \WP_Error( 'gz_limit', 'Decompressed archive exceeds the configured safety limit.' );
			}
			$offset = 0;
			while ( $offset < strlen( $chunk ) ) {
				$n = fwrite( $out, substr( $chunk, $offset ) );
				if ( false === $n || 0 === $n ) {
					gzclose( $in );
					fclose( $out );
					@unlink( $tmp );
					return new \WP_Error( 'gz_write', 'Short write while decompressing the archive.' );
				}
				$offset += $n;
			}
		}
		gzclose( $in );
		fclose( $out );

		if ( ! rename( $tmp, $file ) ) {
			@unlink( $tmp );
			return new \WP_Error( 'gz_rename', "Could not replace {$file} with its decompressed copy." );
		}
		return true;
	}

	private static function max_tar_bytes( $context = '' ) {
		return max( 1048576, (int) apply_filters( 'sandbox_sync_max_tar_bytes', self::MAX_TAR_BYTES, $context ) );
	}

	/**
	 * Shared status/body validation for both transports.
	 *
	 * @return array|\WP_Error Decoded JSON body.
	 */
	private static function parse_raw_json( $code, $body ) {
		if ( strlen( $body ) > self::max_json_bytes() ) {
			return new \WP_Error( 'response_too_large', 'Source JSON response exceeds the configured transfer limit.' );
		}
		$data = json_decode( $body, true );

		if ( 200 !== $code ) {
			$message = is_array( $data ) && isset( $data['error'] ) ? $data['error'] : substr( $body, 0, 200 );
			return new \WP_Error(
				'http_' . $code,
				"Source site returned HTTP {$code}: {$message}",
				array( 'source_code' => is_array( $data ) && isset( $data['code'] ) ? (string) $data['code'] : '' )
			);
		}

		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'bad_json', 'Source site returned a non-JSON response: ' . substr( $body, 0, 200 ) );
		}

		return $data;
	}

	/**
	 * @param array|\WP_Error $response wp_remote_* response.
	 * @return array|\WP_Error Decoded JSON body.
	 */
	private static function parse_json_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		if ( strlen( $body ) > self::max_json_bytes() ) {
			return new \WP_Error( 'response_too_large', 'Source JSON response exceeds the configured transfer limit.' );
		}
		$data = json_decode( $body, true );

		if ( 200 !== $code ) {
			$message = is_array( $data ) && isset( $data['error'] ) ? $data['error'] : substr( $body, 0, 200 );
			return new \WP_Error(
				'http_' . $code,
				"Source site returned HTTP {$code}: {$message}",
				array( 'source_code' => is_array( $data ) && isset( $data['code'] ) ? (string) $data['code'] : '' )
			);
		}

		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'bad_json', 'Source site returned a non-JSON response: ' . substr( $body, 0, 200 ) );
		}

		return $data;
	}

	private static function source_error_code( $error ) {
		if ( ! is_wp_error( $error ) || ! method_exists( $error, 'get_error_data' ) ) {
			return '';
		}
		$data = $error->get_error_data();
		return is_array( $data ) && isset( $data['source_code'] ) ? (string) $data['source_code'] : '';
	}

	/**
	 * Reject public plaintext sources before any credential-bearing request.
	 * Existing stored connections are checked too, so upgrading closes the gap
	 * without requiring an administrator to reconnect first.
	 *
	 * @return true|\WP_Error
	 */
	private static function validate_source_url( $url ) {
		if ( ! Env::is_allowed_source_url( $url ) ) {
			return new \WP_Error(
				'insecure_source_url',
				'Invalid or insecure source URL. Public source sites must use HTTPS; HTTP is allowed only for an explicitly local host.'
			);
		}
		return true;
	}

	private static function is_retryable( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return false;
		}
		return Error_Info::retryable( $error->get_error_code() );
	}
}
