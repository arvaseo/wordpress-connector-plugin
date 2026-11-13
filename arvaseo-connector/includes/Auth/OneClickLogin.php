<?php
/**
 * One-click login from Arva SEO.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Auth;

use ArvaSEO\Connector\Logging\AuditLogger;
use ArvaSEO\Connector\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Off until the site owner switches it on in Settings. Arva SEO asks for an address with a signed event
 * (the same signature as every other event); the answer is a single-use address that expires in a minute and
 * logs in as the administrator who connected the site, and only while that user is still an administrator.
 * Only a hash of the token is stored. Every login, and every refusal, goes to the Activity list.
 */
final class OneClickLogin {

	public const QUERY = 'arvaseo_login';

	public const TTL = 60;

	/**
	 * Hook the visit that uses an address.
	 */
	public static function init(): void {
		add_action( 'init', array( self::class, 'maybe_log_in' ), 1 );
	}

	/**
	 * The administrator who connected the site, when still allowed to manage it.
	 *
	 * @return \WP_User|null
	 */
	public static function connecting_admin(): ?\WP_User {
		$id   = (int) ( TokenStore::connection()['connected_by'] ?? 0 );
		$user = $id > 0 ? get_userdata( $id ) : false;
		return ( $user instanceof \WP_User && user_can( $user, 'manage_options' ) ) ? $user : null;
	}

	/**
	 * A new address for the connecting administrator, or a reason code.
	 *
	 * @param string $requested_by Name of the Arva SEO person who asked (for the Activity list).
	 * @return string|\WP_Error
	 */
	public static function issue( string $requested_by ) {
		if ( ! Settings::get( 'one_click_login' ) ) {
			AuditLogger::warning( 'login.refused', __( 'Arva SEO asked to log in, but one-click login is off.', 'arvaseo-connector' ), array( 'reason' => 'off' ) );
			return new \WP_Error( 'arvaseo_login_off', __( 'One-click login is off.', 'arvaseo-connector' ), array( 'status' => 403 ) );
		}
		$user = self::connecting_admin();
		if ( null === $user ) {
			AuditLogger::warning( 'login.refused', __( 'Arva SEO asked to log in, but the administrator who connected this site is gone or is no longer an administrator.', 'arvaseo-connector' ), array( 'reason' => 'admin_gone' ) );
			return new \WP_Error( 'arvaseo_login_admin_gone', __( 'The connecting administrator is gone.', 'arvaseo-connector' ), array( 'status' => 409 ) );
		}
		$token = bin2hex( random_bytes( 32 ) );
		set_transient(
			self::key( $token ),
			array(
				'user' => (int) $user->ID,
				'by'   => $requested_by,
			),
			self::TTL
		);
		AuditLogger::info(
			'login.issued',
			__( 'A one-time login address was made for Arva SEO.', 'arvaseo-connector' ),
			array(
				'requested_by' => $requested_by,
				'user_id'      => (int) $user->ID,
			)
		);
		return add_query_arg( self::QUERY, $token, home_url( '/' ) );
	}

	/**
	 * Use an address: log in once, or explain.
	 */
	public static function maybe_log_in(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the single-use token is the credential.
		$token = isset( $_GET[ self::QUERY ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ self::QUERY ] ) ) : '';
		if ( '' === $token ) {
			return;
		}
		$fail = static function ( string $reason ): void {
			AuditLogger::warning( 'login.failed', __( 'A one-click login address was refused.', 'arvaseo-connector' ), array( 'reason' => $reason ) );
			wp_die( esc_html__( 'This login link has expired or was already used. Go back to Arva SEO and press “Log in to WordPress” again.', 'arvaseo-connector' ), esc_html__( 'Login link expired', 'arvaseo-connector' ), array( 'response' => 403 ) );
		};
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
			$fail( 'malformed' );
		}
		$record = get_transient( self::key( $token ) );
		delete_transient( self::key( $token ) ); // Single use, whatever happens next.
		if ( ! is_array( $record ) ) {
			$fail( 'expired_or_used' );
		}
		if ( ! Settings::get( 'one_click_login' ) || ! TokenStore::is_connected() ) {
			$fail( 'off' );
		}
		$user = get_userdata( (int) ( $record['user'] ?? 0 ) );
		$now  = self::connecting_admin();
		if ( ! $user instanceof \WP_User || null === $now || $now->ID !== $user->ID ) {
			$fail( 'admin_gone' );
		}
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, false );
		do_action( 'wp_login', $user->user_login, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core hook, so security plugins see the login.
		AuditLogger::info(
			'login.used',
			__( 'Someone logged in from Arva SEO with one click.', 'arvaseo-connector' ),
			array(
				'requested_by' => (string) ( $record['by'] ?? '' ),
				'user_id'      => (int) $user->ID,
			)
		);
		wp_safe_redirect( admin_url() );
		exit;
	}

	/**
	 * Transient name for a token (only its hash is stored).
	 *
	 * @param string $token Token.
	 */
	private static function key( string $token ): string {
		return 'arvaseo_login_' . substr( hash( 'sha256', $token ), 0, 40 );
	}
}
