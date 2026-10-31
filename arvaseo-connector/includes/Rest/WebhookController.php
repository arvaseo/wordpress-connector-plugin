<?php
/**
 * The connector's REST routes.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Rest;

use ArvaSEO\Connector\Auth\AuthService;
use ArvaSEO\Connector\Auth\TokenStore;
use ArvaSEO\Connector\Cron\CronManager;
use ArvaSEO\Connector\Cron\PollingService;
use ArvaSEO\Connector\Install\Schema;
use ArvaSEO\Connector\Logging\AuditLogger;
use ArvaSEO\Connector\Support\Ids;
use ArvaSEO\Connector\Sync\SyncState;

defined( 'ABSPATH' ) || exit;

/**
 * POST /wp-json/arvaseo/v1/events          signed events from Arva SEO (triggers only)
 * GET  /wp-json/arvaseo/v1/connection-health  signed; connection state for Arva SEO's diagnostics
 * GET  /wp-json/arvaseo/v1/auth-header-probe  the health check's own loopback, with a one-time token
 *
 * Every route has a real permission check. Events only start work and return 202: waiting content,
 * SEO updates and fix switches are collected once the answer has gone back (2.7.0), with cron as the
 * fallback. Content is never accepted here: it is collected over the plugin's authenticated
 * connection.
 */
final class WebhookController {

	public const EVENT_TYPES = array( 'content.available', 'seo.updates_available', 'speed.fixes_requested', 'speed.css_ready', 'health.refresh_requested', 'inventory.refresh_requested', 'connection.challenge', 'connection.revoked' );

	/**
	 * The secret that signed the current request.
	 *
	 * @var string|null
	 */
	private static ?string $secret = null;

	/**
	 * Register routes.
	 */
	public static function register(): void {
		register_rest_route(
			'arvaseo/v1',
			'/events',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'event' ),
				'permission_callback' => array( self::class, 'signed' ),
			)
		);
		register_rest_route(
			'arvaseo/v1',
			'/connection-health',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'connection_health' ),
				'permission_callback' => array( self::class, 'signed' ),
			)
		);
		register_rest_route(
			'arvaseo/v1',
			'/auth-header-probe',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'auth_header_probe' ),
				'permission_callback' => array( self::class, 'probe_allowed' ),
			)
		);
	}

	/**
	 * Permission: a valid signature.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public static function signed( \WP_REST_Request $request ) {
		$verified = SignatureVerifier::verify( $request );
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}
		if ( ! SignatureVerifier::within_rate_limit() ) {
			return new \WP_Error( 'arvaseo_rate_limited', __( 'Too many requests.', 'arvaseo-connector' ), array( 'status' => 429 ) );
		}
		// Kept out of the request's parameters, which other plugins can read or log.
		self::$secret = $verified['secret'];
		return true;
	}

	/**
	 * One event.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function event( \WP_REST_Request $request ) {
		$body  = (string) $request->get_body();
		$event = json_decode( $body, true );
		$keys  = array( 'schema_version', 'event_type', 'event_id', 'site_registration_id', 'occurred_at', 'data' );
		if ( ! is_array( $event ) || array_diff( array_keys( $event ), $keys ) !== array() || array_diff( $keys, array_keys( $event ) ) !== array()
			|| '1.0' !== $event['schema_version'] || ! in_array( $event['event_type'], self::EVENT_TYPES, true )
			|| (string) $request->get_header( 'x_arvaseo_event_id' ) !== $event['event_id'] || TokenStore::site_id() !== $event['site_registration_id'] || ! is_array( $event['data'] ) ) {
			return new \WP_Error( 'arvaseo_invalid_event', __( 'Invalid event.', 'arvaseo-connector' ), array( 'status' => 422 ) );
		}
		if ( ! SignatureVerifier::remember( $event['event_id'], $event['event_type'], $body ) ) {
			return new \WP_Error( 'event_replay', __( 'This event was already received.', 'arvaseo-connector' ), array( 'status' => 409 ) );
		}

		switch ( $event['event_type'] ) {
			case 'connection.challenge':
				$challenge = $event['data']['challenge'] ?? '';
				if ( ! is_string( $challenge ) || ! preg_match( '/^[A-Za-z0-9]{16,128}$/', $challenge ) ) {
					return new \WP_Error( 'arvaseo_invalid_event', __( 'Invalid event.', 'arvaseo-connector' ), array( 'status' => 422 ) );
				}
				TokenStore::update_connection( array( 'push_status' => 'verified' ) );
				return new \WP_REST_Response( array( 'proof' => hash_hmac( 'sha256', "challenge\n" . $challenge, (string) self::$secret ) ), 200 );

			case 'connection.revoked':
				AuthService::forget( 'revoked_in_arvaseo' );
				return new \WP_REST_Response( array( 'accepted' => true ), 200 );

			case 'content.available':
			case 'seo.updates_available':
			case 'speed.fixes_requested':
				// Applied in seconds, not on the next visit that runs WordPress's cron. The cron
				// job still runs in two minutes, for anything this request doesn't finish.
				CronManager::run_soon( 'arvaseo_connector_poll', 2 * MINUTE_IN_SECONDS );
				self::poll_when_done();
				return self::accepted( $event );

			case 'speed.css_ready':
				if ( array_diff( array_keys( $event['data'] ), array( 'count' ) ) || ( isset( $event['data']['count'] ) && ( ! is_int( $event['data']['count'] ) || $event['data']['count'] < 0 || $event['data']['count'] > 300 ) ) ) {
					return new \WP_Error( 'arvaseo_invalid_event', __( 'Invalid event.', 'arvaseo-connector' ), array( 'status' => 422 ) );
				}
				\ArvaSEO\Connector\Speed\CssDelivery::send_soon();
				return self::accepted( $event );

			case 'inventory.refresh_requested':
				CronManager::run_soon( 'arvaseo_connector_inventory', 5 );
				return self::accepted( $event );

			default: // health.refresh_requested.
				$recent = (int) ( SyncState::get( 'health' )['at'] ?? 0 ) > time() - 10 * MINUTE_IN_SECONDS;
				if ( ! $recent ) {
					CronManager::run_soon( 'arvaseo_connector_health', 5 );
				}
				return self::accepted( $event, ! $recent );
		}
	}

	/**
	 * Collect from Arva SEO at the end of this request.
	 */
	private static function poll_when_done(): void {
		if ( false === has_action( 'shutdown', array( self::class, 'poll_now' ) ) ) {
			add_action( 'shutdown', array( self::class, 'poll_now' ), 1000 );
		}
	}

	/**
	 * After the answer: sent and the connection closed first where the server allows (PHP-FPM,
	 * LiteSpeed), since Arva SEO only waits a few seconds for it. Same lock and backoff as cron.
	 */
	public static function poll_now(): void {
		ignore_user_abort( true );
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		} elseif ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
		}
		PollingService::poll();
	}

	/**
	 * Connection state for Arva SEO (no secrets).
	 *
	 * @return \WP_REST_Response
	 */
	public static function connection_health() {
		global $wpdb;
		$table    = Schema::table( 'deliveries' );
		$pending  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE ack_sent = 0 AND ack IS NOT NULL', $table ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		$pending += (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE result_sent = 0 AND result IS NOT NULL', Schema::table( 'seo_updates' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		$state    = SyncState::all();
		return new \WP_REST_Response(
			array(
				'connected'                => TokenStore::is_connected(),
				'site_registration_id'     => TokenStore::site_id(),
				'plugin_version'           => ARVASEO_CONNECTOR_VERSION,
				'last_inventory_at'        => isset( $state['inventory']['last_success_at'] ) ? Ids::iso( (int) $state['inventory']['last_success_at'] ) : null,
				'last_health_at'           => isset( $state['health']['last_success_at'] ) ? Ids::iso( (int) $state['health']['last_success_at'] ) : null,
				'pending_acknowledgements' => $pending,
				'wp_cron_disabled'         => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			),
			200
		);
	}

	/**
	 * Permission for the probe: the one-time token the health check just made.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function probe_allowed( \WP_REST_Request $request ): bool {
		$expected = get_transient( 'arvaseo_connector_auth_probe' );
		$given    = (string) $request->get_header( 'x_arvaseo_probe' );
		return is_string( $expected ) && '' !== $given && hash_equals( $expected, hash( 'sha256', $given ) );
	}

	/**
	 * Whether an Authorization header reached PHP.
	 *
	 * @return \WP_REST_Response
	 */
	public static function auth_header_probe() {
		$present = ! empty( $_SERVER['PHP_AUTH_USER'] ) || ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) || ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
		return new \WP_REST_Response( array( 'authorization_header' => $present ), 200 );
	}

	/**
	 * 202 Accepted.
	 *
	 * @param array<string, mixed> $event  Event.
	 * @param bool                 $queued Work was queued.
	 */
	private static function accepted( array $event, bool $queued = true ): \WP_REST_Response {
		AuditLogger::info(
			'event.received',
			__( 'Received a request from Arva SEO.', 'arvaseo-connector' ),
			array(
				'event_type' => $event['event_type'],
				'event_id'   => $event['event_id'],
			)
		);
		return new \WP_REST_Response(
			array(
				'accepted' => true,
				'queued'   => $queued,
			),
			202
		);
	}
}
