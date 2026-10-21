<?php
/**
 * Connecting, reconnecting and disconnecting.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Auth;

use ArvaSEO\Connector\Api\ApiClient;
use ArvaSEO\Connector\Config;
use ArvaSEO\Connector\Cron\CronManager;
use ArvaSEO\Connector\Health\AssessmentService;
use ArvaSEO\Connector\Install\Capabilities;
use ArvaSEO\Connector\Logging\AuditLogger;
use ArvaSEO\Connector\Privacy\PrivacyService;
use ArvaSEO\Connector\Site\SiteRegistrationService;
use ArvaSEO\Connector\Support\Ids;

defined( 'ABSPATH' ) || exit;

/**
 * The browser-based pairing flow (authorization code with PKCE S256). No Arva SEO password is
 * ever entered here: the administrator approves on app.arvaseo.com, which sends a short-lived,
 * single-use code back to this site's wp-admin. The plugin checks the state it made for this
 * administrator and exchanges the code server to server with its secret verifier. The site's
 * credentials come only from that exchange, never from the browser.
 */
final class AuthService {

	/** Seconds a started connection waits for the administrator to come back. */
	private const PENDING_TTL = 600;

	/**
	 * Start: remember state and verifier for this administrator, return the app's address.
	 *
	 * @param bool $share_health   Consent to share inventory and Website Health.
	 * @param bool $allow_content  Allow content delivery.
	 * @param bool $share_security Consent to share the security scan's results too.
	 */
	public static function start( bool $share_health, bool $allow_content, bool $share_security = false ): string {
		$state    = Ids::token( 32 );
		$verifier = Ids::token( 64 );
		$scopes   = array( 'site:register' );
		if ( $share_health ) {
			$scopes = array_merge( $scopes, Config::HEALTH_SCOPES );
		}
		if ( $allow_content ) {
			$scopes = array_merge( $scopes, Config::CONTENT_SCOPES );
		}
		set_transient(
			self::pending_key(),
			Crypto::encrypt(
				(string) wp_json_encode(
					array(
						'state_hash'     => hash( 'sha256', $state ),
						'verifier'       => $verifier,
						'share_health'   => $share_health,
						'share_security' => $share_health && $share_security,
						'allow_content'  => $allow_content,
						'site_url'       => self::site_url(),
					)
				)
			),
			self::PENDING_TTL
		);
		AuditLogger::info( 'connection.started', __( 'Started connecting to Arva SEO.', 'arvaseo-connector' ), array( 'scopes' => $scopes ) );

		return add_query_arg(
			array_map(
				'rawurlencode',
				array(
					'site_url'              => self::site_url(),
					'site_name'             => mb_substr( wp_strip_all_tags( get_bloginfo( 'name' ) ), 0, 200 ),
					'redirect_uri'          => self::redirect_uri(),
					'state'                 => $state,
					'code_challenge'        => Ids::base64url( hash( 'sha256', $verifier, true ) ),
					'code_challenge_method' => 'S256',
					'scope'                 => implode( ' ', $scopes ),
					'plugin_version'        => ARVASEO_CONNECTOR_VERSION,
				)
			),
			Config::app_url() . '/connect/wordpress'
		);
	}

	/**
	 * The administrator came back from Arva SEO.
	 *
	 * @param array<string, string> $query Query parameters (code, state or error).
	 * @return array{ok: bool, notice: string}
	 */
	public static function callback( array $query ): array {
		$pending_raw = get_transient( self::pending_key() );
		delete_transient( self::pending_key() );
		$pending = is_string( $pending_raw ) ? json_decode( (string) Crypto::decrypt( $pending_raw ), true ) : null;
		$state   = isset( $query['state'] ) ? (string) $query['state'] : '';

		if ( ! is_array( $pending ) || '' === $state || ! hash_equals( (string) $pending['state_hash'], hash( 'sha256', $state ) ) || self::site_url() !== $pending['site_url'] ) {
			AuditLogger::warning( 'connection.state_mismatch', __( 'A connection reply didn’t match a connection started here; it was ignored.', 'arvaseo-connector' ) );
			return array(
				'ok'     => false,
				'notice' => 'state_mismatch',
			);
		}
		if ( isset( $query['error'] ) ) {
			AuditLogger::info( 'connection.cancelled', __( 'Connecting was cancelled in Arva SEO.', 'arvaseo-connector' ) );
			return array(
				'ok'     => false,
				'notice' => 'cancelled',
			);
		}
		$code = isset( $query['code'] ) ? (string) $query['code'] : '';
		if ( ! preg_match( '/^arvwp_code_[A-Za-z0-9]{20,100}$/', $code ) ) {
			return array(
				'ok'     => false,
				'notice' => 'invalid_code',
			);
		}

		$response = ApiClient::instance()->public_post(
			'/api/v1/wordpress/token',
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'code_verifier' => $pending['verifier'],
				'redirect_uri'  => self::redirect_uri(),
				'site_url'      => self::site_url(),
			)
		);
		$site_id  = $response->body['site_registration_id'] ?? null;
		if ( ! $response->ok() || ! is_string( $site_id ) || ! isset( $response->body['access_token'], $response->body['refresh_token'] ) ) {
			AuditLogger::error(
				'connection.exchange_failed',
				__( 'Arva SEO didn’t accept the connection code.', 'arvaseo-connector' ),
				array(
					'http_status' => $response->status,
					'code'        => $response->error_code,
				)
			);
			return array(
				'ok'     => false,
				'notice' => 'exchange_failed',
			);
		}

		TokenStore::save_from_response( $response->body );
		TokenStore::clear_connection();
		TokenStore::update_connection(
			array(
				'site_registration_id' => sanitize_text_field( $site_id ),
				'status'               => 'connected',
				'connected_at'         => time(),
				'connected_by'         => get_current_user_id(),
				'last_success_at'      => time(),
			)
		);
		ApiClient::instance()->apply_site_details( $response->body );
		PrivacyService::consent( ! empty( $pending['share_health'] ) ? array( 'inventory', 'health' ) : array() );
		PrivacyService::consent_security( ! empty( $pending['share_health'] ) && ! empty( $pending['share_security'] ) );
		AssessmentService::clear();

		$registered = SiteRegistrationService::register();
		CronManager::schedule_sync();
		if ( ! empty( $pending['share_health'] ) ) {
			CronManager::run_soon( 'arvaseo_connector_inventory' );
			CronManager::run_soon( 'arvaseo_connector_health', 30 );
		}
		AuditLogger::info(
			'connection.connected',
			__( 'Connected to Arva SEO.', 'arvaseo-connector' ),
			array(
				'scopes'  => TokenStore::connection()['scopes'] ?? array(),
				'project' => TokenStore::connection()['project']['name'] ?? null,
			)
		);
		return array(
			'ok'     => true,
			'notice' => $registered ? 'connected' : 'connected_registration_pending',
		);
	}

	/**
	 * Disconnect: revoke at Arva SEO when possible, then forget everything locally.
	 */
	public static function disconnect(): void {
		$credentials = TokenStore::credentials();
		if ( null !== $credentials ) {
			ApiClient::instance()->public_post( '/api/v1/wordpress/revoke', array( 'refresh_token' => $credentials['refresh_token'] ) );
		}
		self::forget( 'disconnected_in_wordpress' );
	}

	/**
	 * Local disconnect (also when Arva SEO says the connection was revoked).
	 *
	 * @param string $reason Reason code for the log.
	 */
	public static function forget( string $reason ): void {
		$connection = TokenStore::connection();
		TokenStore::clear();
		TokenStore::clear_connection();
		TokenStore::update_connection(
			array(
				'status'           => 'disconnected',
				'disconnected_at'  => time(),
				'previous_project' => $connection['project']['name'] ?? null,
			)
		);
		CronManager::clear_sync();
		AssessmentService::mark_stale();
		AuditLogger::info( 'connection.disconnected', __( 'Disconnected from Arva SEO. Nothing more is sent or received.', 'arvaseo-connector' ), array( 'reason' => $reason ) );
	}

	/**
	 * Whether the current user may manage the connection.
	 */
	public static function can_manage(): bool {
		return current_user_can( Capabilities::MANAGE );
	}

	/**
	 * This site's address as Arva SEO knows it.
	 */
	public static function site_url(): string {
		return untrailingslashit( home_url() );
	}

	/**
	 * Where Arva SEO sends the administrator back (this site's own wp-admin).
	 */
	public static function redirect_uri(): string {
		return admin_url( 'admin-post.php?action=arvaseo_connector_callback' );
	}

	/**
	 * Pending connection, per administrator.
	 */
	private static function pending_key(): string {
		return 'arvaseo_connector_pairing_' . get_current_user_id();
	}
}
