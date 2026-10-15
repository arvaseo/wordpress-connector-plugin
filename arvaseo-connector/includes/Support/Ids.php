<?php
/**
 * Random identifiers and encodings.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Cryptographically random values.
 */
final class Ids {

	/**
	 * URL-safe base64 without padding.
	 *
	 * @param string $bytes Raw bytes.
	 */
	public static function base64url( string $bytes ): string {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- encoding random bytes, not obfuscation.
	}

	/**
	 * A random URL-safe token.
	 *
	 * @param int $bytes Entropy in bytes.
	 */
	public static function token( int $bytes = 32 ): string {
		return self::base64url( random_bytes( $bytes ) );
	}

	/**
	 * A lowercase version 4 UUID.
	 */
	public static function uuid(): string {
		return strtolower( wp_generate_uuid4() );
	}

	/**
	 * Whether a value is a lowercase UUID.
	 *
	 * @param mixed $value Value.
	 */
	public static function is_uuid( $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value );
	}

	/**
	 * SHA-256 of a value's canonical JSON.
	 *
	 * @param mixed $value Value.
	 */
	public static function hash( $value ): string {
		return hash( 'sha256', (string) wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * UTC time in ISO 8601 with Z.
	 *
	 * @param int|null $timestamp Unix time; now when null.
	 */
	public static function iso( ?int $timestamp = null ): string {
		return gmdate( 'Y-m-d\TH:i:s\Z', $timestamp ?? time() );
	}

	/**
	 * MySQL DATETIME in UTC.
	 *
	 * @param int|null $timestamp Unix time; now when null.
	 */
	public static function mysql( ?int $timestamp = null ): string {
		return gmdate( 'Y-m-d H:i:s', $timestamp ?? time() );
	}
}
