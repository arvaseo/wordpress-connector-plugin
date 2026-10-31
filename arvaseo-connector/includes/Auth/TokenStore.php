<?php
/**
 * Stored credentials and connection details.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * Credentials (access and refresh tokens, event signing keys) are kept encrypted in one option
 * that is never autoloaded; the connection's non-secret details are in another. Neither is ever
 * printed, sent to the browser or included in diagnostics.
 */
final class TokenStore {

	private const SECRETS = 'arvaseo_connector_credentials';

	private const CONNECTION = 'arvaseo_connector_connection';

	/**
	 * Decrypted credentials, or null when there are none or they can't be read.
	 *
	 * @return array{access_token: string, access_expires_at: int, refresh_token: string, refresh_expires_at: int, signing_keys: array<int, array{id: string, secret: string, retire_at: ?string}>}|null
	 */
	public static function credentials(): ?array {
		$stored = get_option( self::SECRETS, '' );
		if ( ! is_string( $stored ) || '' === $stored ) {
			return null;
		}
		$plain = Crypto::decrypt( $stored );
		$data  = null === $plain ? null : json_decode( $plain, true );
		return is_array( $data ) && isset( $data['access_token'], $data['refresh_token'] ) ? $data : null;
	}

	/**
	 * Whether credentials exist but can't be decrypted (salts or key changed).
	 */
	public static function unreadable(): bool {
		$stored = get_option( self::SECRETS, '' );
		return is_string( $stored ) && '' !== $stored && null === self::credentials();
	}

	/**
	 * Save credentials from a token response.
	 *
	 * @param array<string, mixed> $response Token endpoint response.
	 */
	public static function save_from_response( array $response ): void {
		$keys = array();
		foreach ( (array) ( $response['signing_keys'] ?? array() ) as $key ) {
			if ( is_array( $key ) && isset( $key['id'], $key['secret'] ) && is_string( $key['id'] ) && is_string( $key['secret'] ) ) {
				$keys[] = array(
					'id'        => $key['id'],
					'secret'    => $key['secret'],
					'retire_at' => isset( $key['retire_at'] ) && is_string( $key['retire_at'] ) ? $key['retire_at'] : null,
				);
			}
		}
		$credentials = array(
			'access_token'       => (string) $response['access_token'],
			'access_expires_at'  => time() + max( 60, (int) ( $response['expires_in'] ?? 3600 ) ),
			'refresh_token'      => (string) $response['refresh_token'],
			'refresh_expires_at' => time() + max( 3600, (int) ( $response['refresh_token_expires_in'] ?? 7776000 ) ),
			'signing_keys'       => $keys,
		);
		update_option( self::SECRETS, Crypto::encrypt( (string) wp_json_encode( $credentials ) ), false );
	}

	/**
	 * Remove every credential.
	 */
	public static function clear(): void {
		delete_option( self::SECRETS );
	}

	/**
	 * Connection details (never secret).
	 *
	 * @return array<string, mixed>
	 */
	public static function connection(): array {
		$value = get_option( self::CONNECTION, array() );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Merge connection details.
	 *
	 * @param array<string, mixed> $changes Changes.
	 */
	public static function update_connection( array $changes ): void {
		if ( isset( $changes['site_registration_id'] ) && null !== self::site_id() && self::site_id() !== $changes['site_registration_id'] ) {
			\ArvaSEO\Connector\Speed\CssDelivery::clear();
		}
		update_option( self::CONNECTION, array_merge( self::connection(), $changes ), false );
	}

	/**
	 * Forget the connection details.
	 */
	public static function clear_connection(): void {
		delete_option( self::CONNECTION );
		\ArvaSEO\Connector\Speed\CssDelivery::clear();
	}

	/**
	 * The opaque site registration ID, if connected.
	 */
	public static function site_id(): ?string {
		$id = self::connection()['site_registration_id'] ?? null;
		return is_string( $id ) && preg_match( '/^[0-9A-HJKMNP-TV-Z]{26}$/i', $id ) ? $id : null;
	}

	/**
	 * Connected and usable (credentials present and readable, not waiting for reconnection).
	 */
	public static function is_connected(): bool {
		return null !== self::site_id() && null !== self::credentials() && 'connected' === ( self::connection()['status'] ?? '' );
	}

	/**
	 * Whether a scope was granted.
	 *
	 * @param string $scope Scope.
	 */
	public static function has_scope( string $scope ): bool {
		return in_array( $scope, (array) ( self::connection()['scopes'] ?? array() ), true );
	}
}
