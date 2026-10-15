<?php
/**
 * Verifying events signed by Arva SEO.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Rest;

use ArvaSEO\Connector\Auth\TokenStore;
use ArvaSEO\Connector\Config;
use ArvaSEO\Connector\Support\Ids;

defined( 'ABSPATH' ) || exit;

/**
 * Protocol v1 (same as Arva SEO's EventSigner):
 *
 *   X-ArvaSEO-Key-Id, X-ArvaSEO-Timestamp (Unix seconds, ±300 s), X-ArvaSEO-Event-Id (UUID),
 *   X-ArvaSEO-Signature: v1=hex(HMAC-SHA256(secret, "v1\n{ts}\n{event_id}\n{METHOD}\n{route}\n{sha256(body)}"))
 *
 * Per-site secrets come only from the authenticated token exchange; old and new keys both work
 * during a rotation until the old one's retire time. Comparison is constant-time. Failures give
 * no detail beyond "unauthorized". WordPress nonces are not used here: they protect browser
 * actions, not server-to-server requests.
 */
final class SignatureVerifier {

	/**
	 * Verify a request; returns the secret that signed it, or a WP_Error.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array{key_id: string, secret: string}|\WP_Error
	 */
	public static function verify( \WP_REST_Request $request ) {
		$deny = new \WP_Error( 'arvaseo_unauthorized', __( 'Unauthorized.', 'arvaseo-connector' ), array( 'status' => 401 ) );
		$body = (string) $request->get_body();
		if ( strlen( $body ) > Config::MAX_EVENT_BYTES ) {
			return new \WP_Error( 'arvaseo_payload_too_large', __( 'Payload too large.', 'arvaseo-connector' ), array( 'status' => 413 ) );
		}
		$credentials = TokenStore::credentials();
		if ( null === $credentials || null === TokenStore::site_id() ) {
			return $deny;
		}
		$key_id    = (string) $request->get_header( 'x_arvaseo_key_id' );
		$timestamp = (string) $request->get_header( 'x_arvaseo_timestamp' );
		$event_id  = (string) $request->get_header( 'x_arvaseo_event_id' );
		$signature = (string) $request->get_header( 'x_arvaseo_signature' );
		if ( ! ctype_digit( $timestamp ) || abs( time() - (int) $timestamp ) > Config::MAX_CLOCK_SKEW || ! Ids::is_uuid( $event_id ) || ! preg_match( '/^v1=([a-f0-9]{64})$/', $signature, $m ) ) {
			return $deny;
		}
		$canonical = implode( "\n", array( 'v1', $timestamp, $event_id, strtoupper( $request->get_method() ), $request->get_route(), hash( 'sha256', $body ) ) );
		foreach ( $credentials['signing_keys'] as $key ) {
			if ( ! hash_equals( (string) $key['id'], $key_id ) ) {
				continue;
			}
			if ( null !== $key['retire_at'] && strtotime( $key['retire_at'] ) < time() ) {
				return $deny;
			}
			if ( hash_equals( hash_hmac( 'sha256', $canonical, (string) $key['secret'] ), $m[1] ) ) {
				return array(
					'key_id' => $key_id,
					'secret' => (string) $key['secret'],
				);
			}
			return $deny;
		}
		return $deny;
	}

	/**
	 * Accept each event ID once (kept 7 days). False when it was seen before.
	 *
	 * @param string $event_id   Event ID.
	 * @param string $event_type Event type.
	 * @param string $body       Raw body.
	 */
	public static function remember( string $event_id, string $event_type, string $body ): bool {
		global $wpdb;
		$inserted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- own table; INSERT IGNORE makes the check atomic.
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (event_id, event_type, payload_hash, received_at) VALUES (%s, %s, %s, %s)',
				\ArvaSEO\Connector\Install\Schema::table( 'events' ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->prefix.
				$event_id,
				substr( $event_type, 0, 40 ),
				hash( 'sha256', $body ),
				Ids::mysql()
			)
		);
		return 1 === (int) $inserted;
	}

	/**
	 * At most 60 signed events a minute.
	 */
	public static function within_rate_limit(): bool {
		$key   = 'arvaseo_connector_event_rate_' . gmdate( 'YmdHi' );
		$count = (int) get_transient( $key );
		if ( $count >= 60 ) {
			return false;
		}
		set_transient( $key, $count + 1, 2 * MINUTE_IN_SECONDS );
		return true;
	}
}
