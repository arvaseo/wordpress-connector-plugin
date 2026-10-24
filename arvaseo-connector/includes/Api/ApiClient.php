<?php
/**
 * Calls to the Arva SEO API.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Api;

use ArvaSEO\Connector\Auth\TokenStore;
use ArvaSEO\Connector\Config;
use ArvaSEO\Connector\Logging\AuditLogger;
use ArvaSEO\Connector\Site\IndexNowKey;
use ArvaSEO\Connector\Support\Lock;

defined( 'ABSPATH' ) || exit;

/**
 * Talks only to the configured Arva SEO API host over TLS with bounded timeouts. Access tokens
 * go in the Authorization header, never in a URL. Expired tokens are refreshed once, under a lock
 * so two requests never refresh at the same time. A revoked or renewed-elsewhere connection is
 * marked "Reconnect required" and synchronisation pauses until an administrator reconnects.
 */
final class ApiClient {

	/** Ordinary API responses stay bounded at 2 MiB. */
	public const RESPONSE_LIMIT = 2097152;

	/** The largest opt-in response; CSS can expand up to sixfold when JSON-escaped. */
	public const MAX_RESPONSE_LIMIT = 16777216;

	/**
	 * Transport.
	 *
	 * @var HttpTransport
	 */
	private HttpTransport $http;

	/**
	 * Constructor.
	 *
	 * @param HttpTransport|null $http Transport (tests pass a fake).
	 */
	public function __construct( ?HttpTransport $http = null ) {
		$this->http = $http ?? new WpHttpTransport();
	}

	/**
	 * The shared instance (filterable for tests).
	 */
	public static function instance(): self {
		static $instance = null;
		$transport       = apply_filters( 'arvaseo_connector_http_transport', null );
		if ( $transport instanceof HttpTransport ) {
			return new self( $transport );
		}
		$instance ??= new self();
		return $instance;
	}

	/**
	 * A call with the site's access token.
	 *
	 * @param string                    $method HTTP method.
	 * @param string                    $path   Path under the site, e.g. "/inventory" ("" for the site itself).
	 * @param array<string, mixed>|null $body    JSON body.
	 * @param int                       $timeout Seconds (deep scans send files with longer ones).
	 * @param int                       $response_limit Maximum response bytes (bounded to 16 MiB).
	 */
	public function site( string $method, string $path, ?array $body = null, int $timeout = 15, int $response_limit = self::RESPONSE_LIMIT ): ApiResponse {
		$site_id = TokenStore::site_id();
		if ( null === $site_id || ! TokenStore::is_connected() ) {
			return new ApiResponse( 0, array(), 'not_connected', __( 'This site isn’t connected to Arva SEO.', 'arvaseo-connector' ) );
		}
		$url = '/api/v1/wordpress/sites/' . rawurlencode( $site_id ) . $path;

		$token = $this->access_token();
		if ( null === $token ) {
			return new ApiResponse( 401, array(), 'reauth_required', __( 'Reconnect this site to Arva SEO.', 'arvaseo-connector' ) );
		}
		$response = $this->send( $method, $url, $body, $token, $timeout, $response_limit );
		if ( 401 === $response->status && 'invalid_token' === $response->error_code ) {
			$token = $this->access_token( true );
			if ( null !== $token ) {
				$response = $this->send( $method, $url, $body, $token, $timeout, $response_limit );
			}
		}
		if ( 401 === $response->status ) {
			$this->require_reconnect( $response->error_code ?? 'invalid_token' );
		}
		$this->record( $response );
		return $response;
	}

	/**
	 * Why a request didn't get an answer: dns (this server couldn't look up Arva SEO's address),
	 * timeout, tls (no secure connection) or other.
	 *
	 * @param \WP_Error $error The transport's error.
	 */
	public static function network_reason( \WP_Error $error ): string {
		$message = strtolower( $error->get_error_message() );
		return match ( true ) {
			str_contains( $message, 'curl error 6:' ) || str_contains( $message, 'resolve host' ) || str_contains( $message, 'getaddrinfo' ) || str_contains( $message, 'name or service not known' ) => 'dns',
			str_contains( $message, 'curl error 28:' ) || str_contains( $message, 'timed out' ) || str_contains( $message, 'timeout' ) => 'timeout',
			1 === preg_match( '/curl error (35|51|53|54|58|59|60|64|66|77|80|82|83|90|91):/', $message ) || str_contains( $message, 'ssl' ) || str_contains( $message, 'certificate' ) => 'tls',
			default => 'other',
		};
	}

	/**
	 * What to tell an administrator when Arva SEO couldn't be reached.
	 *
	 * @param string $reason dns, timeout, tls or other.
	 */
	public static function network_message( string $reason ): string {
		return match ( $reason ) {
			'dns'     => __( 'This server couldn’t look up Arva SEO’s address (a DNS problem on your host). After an address change, a host can keep old records for up to a day or two: try again later, or ask your host to refresh its DNS cache for api.arvaseo.com.', 'arvaseo-connector' ),
			'timeout' => __( 'Arva SEO didn’t answer in time. Try again in a few minutes; if it keeps happening, ask your host whether it blocks outgoing connections to api.arvaseo.com.', 'arvaseo-connector' ),
			'tls'     => __( 'This server couldn’t make a secure connection to Arva SEO. Ask your host to update its list of trusted certificates (CA bundle) and OpenSSL.', 'arvaseo-connector' ),
			default   => __( 'Couldn’t reach Arva SEO. Check the server’s internet connection and try again.', 'arvaseo-connector' ),
		};
	}

	/**
	 * A call without a token (the code exchange, revocation).
	 *
	 * @param string               $path Path.
	 * @param array<string, mixed> $body JSON body.
	 */
	public function public_post( string $path, array $body ): ApiResponse {
		return $this->send( 'POST', $path, $body, null );
	}

	/**
	 * A valid access token, refreshing it when it expires within a minute (or when forced).
	 *
	 * @param bool $force Refresh even if not expired.
	 */
	public function access_token( bool $force = false ): ?string {
		$credentials = TokenStore::credentials();
		if ( null === $credentials ) {
			return null;
		}
		if ( ! $force && $credentials['access_expires_at'] > time() + 60 ) {
			return $credentials['access_token'];
		}
		$before = $credentials['access_token'];
		if ( ! Lock::acquire( 'refresh', 30 ) ) {
			// Another request is refreshing: wait briefly for its result.
			for ( $i = 0; $i < 20; $i++ ) {
				usleep( 250000 );
				$latest = TokenStore::credentials();
				if ( null !== $latest && $latest['access_token'] !== $before ) {
					return $latest['access_token'];
				}
			}
			return null;
		}
		try {
			$latest = TokenStore::credentials();
			if ( null !== $latest && $latest['access_token'] !== $before ) {
				return $latest['access_token'];
			}
			$response = $this->send(
				'POST',
				'/api/v1/wordpress/token',
				array(
					'grant_type'    => 'refresh_token',
					'refresh_token' => $credentials['refresh_token'],
				),
				null
			);
			if ( $response->ok() && isset( $response->body['access_token'], $response->body['refresh_token'] ) ) {
				TokenStore::save_from_response( $response->body );
				$this->apply_site_details( $response->body );
				return (string) $response->body['access_token'];
			}
			if ( in_array( $response->error_code, array( 'invalid_grant', 'reauth_required' ), true ) ) {
				$this->require_reconnect( (string) $response->error_code );
			} else {
				AuditLogger::warning(
					'connection.refresh_failed',
					__( 'Couldn’t renew the connection’s access; will retry.', 'arvaseo-connector' ),
					array(
						'http_status' => $response->status,
						'code'        => $response->error_code,
					)
				);
			}
			return null;
		} finally {
			Lock::release( 'refresh' );
		}
	}

	/**
	 * Keep connection details from a token response in step (scopes, names, entitlements).
	 *
	 * @param array<string, mixed> $body Token response.
	 */
	public function apply_site_details( array $body ): void {
		$site = is_array( $body['site'] ?? null ) ? $body['site'] : array();
		TokenStore::update_connection(
			array(
				'scopes'       => array_values( array_intersect( Config::SCOPES, explode( ' ', (string) ( $body['scope'] ?? '' ) ) ) ),
				'workspace'    => self::name_pair( $site['workspace'] ?? null ),
				'project'      => self::name_pair( $site['project'] ?? null ),
				'client'       => isset( $site['client'] ) && is_string( $site['client'] ) ? sanitize_text_field( $site['client'] ) : null,
				'app_url'      => self::app_link( $site['app_url'] ?? null ),
				'entitlements' => array_map( 'boolval', array_intersect_key( (array) ( $body['entitlements'] ?? array() ), array_flip( array( 'connector', 'health_analysis', 'vulnerability_intelligence', 'publishing' ) ) ) ),
				'indexnow_key' => IndexNowKey::sanitize( $site['indexnow_key'] ?? null ),
			)
		);
	}

	/**
	 * A link into the Arva SEO app, only if it points at the configured app.
	 *
	 * @param mixed $url Candidate.
	 */
	public static function app_link( $url ): ?string {
		if ( ! is_string( $url ) ) {
			return null;
		}
		$app = wp_parse_url( Config::app_url() );
		$got = wp_parse_url( $url );
		if ( ! is_array( $got ) || ( $got['scheme'] ?? '' ) !== ( $app['scheme'] ?? '' ) || strtolower( $got['host'] ?? '' ) !== strtolower( $app['host'] ?? '' ) || ( $got['port'] ?? null ) !== ( $app['port'] ?? null ) ) {
			return null;
		}
		return esc_url_raw( $url );
	}

	/**
	 * Id and name from the API.
	 *
	 * @param mixed $value Value.
	 * @return array{id: string, name: string, domain?: string}|null
	 */
	private static function name_pair( $value ): ?array {
		if ( ! is_array( $value ) || ! isset( $value['id'], $value['name'] ) ) {
			return null;
		}
		$pair = array(
			'id'   => sanitize_text_field( (string) $value['id'] ),
			'name' => sanitize_text_field( (string) $value['name'] ),
		);
		if ( isset( $value['domain'] ) ) {
			$pair['domain'] = sanitize_text_field( (string) $value['domain'] );
		}
		return $pair;
	}

	/**
	 * Pause synchronisation until an administrator reconnects.
	 *
	 * @param string $code Error code.
	 */
	private function require_reconnect( string $code ): void {
		if ( 'reauth_required' === ( TokenStore::connection()['status'] ?? '' ) ) {
			return;
		}
		TokenStore::update_connection(
			array(
				'status'     => 'reauth_required',
				'last_error' => array(
					'code' => $code,
					'at'   => time(),
				),
			)
		);
		AuditLogger::error( 'connection.reauth_required', __( 'Arva SEO no longer accepts this connection. Reconnect to resume.', 'arvaseo-connector' ), array( 'code' => $code ) );
	}

	/**
	 * Remember the last success and the last error.
	 *
	 * @param ApiResponse $response Response.
	 */
	private function record( ApiResponse $response ): void {
		if ( $response->ok() ) {
			TokenStore::update_connection( array( 'last_success_at' => time() ) );
			return;
		}
		TokenStore::update_connection(
			array(
				'last_error' => array(
					'code'        => $response->error_code ?? 'http_' . $response->status,
					'http_status' => $response->status,
					'at'          => time(),
				),
			)
		);
	}

	/**
	 * One HTTP request to the API host.
	 *
	 * @param string                    $method  Method.
	 * @param string                    $path    Path starting with /api/.
	 * @param array<string, mixed>|null $body    JSON body.
	 * @param string|null               $token   Bearer token.
	 * @param int                       $timeout Seconds.
	 * @param int                       $response_limit Maximum response bytes.
	 */
	private function send( string $method, string $path, ?array $body, ?string $token, int $timeout = 15, int $response_limit = self::RESPONSE_LIMIT ): ApiResponse {
		$headers = array(
			'Accept'       => 'application/json',
			'User-Agent'   => 'ArvaSEO-Connector/' . ARVASEO_CONNECTOR_VERSION . '; WordPress/' . get_bloginfo( 'version' ),
			'X-Request-Id' => AuditLogger::request_id(),
		);
		if ( null !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}
		$args = array(
			'method'              => $method,
			'headers'             => $headers,
			'timeout'             => $timeout,
			'redirection'         => 0,
			'sslverify'           => true,
			'limit_response_size' => max( 1024, min( self::MAX_RESPONSE_LIMIT, $response_limit ) ),
			'cookies'             => array(),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}

		$started = microtime( true );
		$result  = $this->http->request( Config::api_url() . $path, $args );
		if ( is_wp_error( $result ) ) {
			$reason = self::network_reason( $result );
			AuditLogger::warning(
				'api.network_error',
				__( 'Couldn’t reach Arva SEO.', 'arvaseo-connector' ),
				array(
					'code'        => $result->get_error_code(),
					// What the server said (2.5.1), such as "cURL error 6: Could not resolve host".
					'reason'      => $reason,
					'error'       => mb_substr( wp_strip_all_tags( $result->get_error_message() ), 0, 200 ),
					'duration_ms' => (int) ( ( microtime( true ) - $started ) * 1000 ),
				)
			);
			return new ApiResponse( 0, array( 'reason' => $reason ), 'network_error', self::network_message( $reason ) );
		}
		$status  = (int) wp_remote_retrieve_response_code( $result );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $result ), true );
		$decoded = is_array( $decoded ) ? $decoded : array();
		$retry   = wp_remote_retrieve_header( $result, 'retry-after' );
		if ( $status >= 200 && $status < 300 ) {
			return new ApiResponse( $status, $decoded );
		}
		$code    = isset( $decoded['code'] ) && is_string( $decoded['code'] ) ? sanitize_key( $decoded['code'] ) : 'http_' . $status;
		$message = isset( $decoded['message'] ) && is_string( $decoded['message'] ) ? mb_substr( sanitize_text_field( $decoded['message'] ), 0, 300 ) : __( 'Arva SEO couldn’t complete the request.', 'arvaseo-connector' );
		return new ApiResponse( $status, $decoded, $code, $message, is_numeric( $retry ) ? min( 3600, (int) $retry ) : null );
	}
}
