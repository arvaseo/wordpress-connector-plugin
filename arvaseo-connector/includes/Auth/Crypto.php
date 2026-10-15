<?php
/**
 * Encryption of stored credentials.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * Encrypts tokens and signing secrets at rest with libsodium (XSalsa20-Poly1305 secretbox;
 * WordPress ships sodium_compat when the extension is missing). The key is derived with
 * HKDF-SHA256 from ARVASEO_CONNECTOR_ENCRYPTION_KEY when defined in wp-config.php, otherwise
 * from this site's AUTH_KEY and SECURE_AUTH_KEY salts. No key ships with the plugin.
 *
 * If the salts or the constant change, stored credentials can no longer be decrypted: the
 * connection then shows "Reconnect required" and the administrator connects again. This
 * protects a database copy without the wp-config.php secrets; it doesn't protect against an
 * attacker who can read both.
 */
final class Crypto {

	private const PREFIX = 'v1:';

	/**
	 * Encrypt a value.
	 *
	 * @param string $plain Plain text.
	 */
	public static function encrypt( string $plain ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$box   = sodium_crypto_secretbox( $plain, $nonce, self::key() );
		return self::PREFIX . base64_encode( $nonce . $box ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- storage encoding of ciphertext.
	}

	/**
	 * Decrypt a value; null when it can't be decrypted (tampered, or the key changed).
	 *
	 * @param string $stored Stored value.
	 */
	public static function decrypt( string $stored ): ?string {
		if ( 0 !== strpos( $stored, self::PREFIX ) ) {
			return null;
		}
		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- storage encoding of ciphertext.
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}
		$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), self::key() );
		return false === $plain ? null : $plain;
	}

	/**
	 * Where the key comes from (for diagnostics; never the key).
	 */
	public static function key_source(): string {
		return defined( 'ARVASEO_CONNECTOR_ENCRYPTION_KEY' ) && '' !== (string) constant( 'ARVASEO_CONNECTOR_ENCRYPTION_KEY' ) ? 'constant' : 'wordpress_salts';
	}

	/**
	 * The derived 32-byte key.
	 */
	private static function key(): string {
		if ( 'constant' === self::key_source() ) {
			$material = (string) constant( 'ARVASEO_CONNECTOR_ENCRYPTION_KEY' );
		} else {
			$material = wp_salt( 'auth' ) . wp_salt( 'secure_auth' );
		}
		return hash_hkdf( 'sha256', $material, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 'arvaseo-connector-credentials-v1', 'arvaseo-connector:' . get_current_blog_id() );
	}
}
