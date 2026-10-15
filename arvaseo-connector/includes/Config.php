<?php
/**
 * Fixed connector configuration. The Arva SEO addresses can only be changed by constants in
 * wp-config.php (for staging or local testing), never by a request or a setting.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector;

defined( 'ABSPATH' ) || exit;

/**
 * Addresses, protocol versions and limits.
 */
final class Config {

	public const PROTOCOL_VERSION = '1.0';

	/** Raise when the data the plugin sends changes; administrators are asked to consent again. */
	public const CONSENT_SCHEMA_VERSION = '1';

	public const DEFAULT_API_URL = 'https://api.arvaseo.com';

	public const DEFAULT_APP_URL = 'https://app.arvaseo.com';

	public const SCOPES = array(
		'site:register',
		'plugin-inventory:write',
		'health-snapshot:write',
		'health-assessment:read',
		'content-delivery:read',
		'content-status:write',
	);

	public const HEALTH_SCOPES = array( 'plugin-inventory:write', 'health-snapshot:write', 'health-assessment:read' );

	public const CONTENT_SCOPES = array( 'content-delivery:read', 'content-status:write' );

	/** Signed events older or newer than this many seconds are refused. */
	public const MAX_CLOCK_SKEW = 300;

	/** Largest event body accepted from Arva SEO. */
	public const MAX_EVENT_BYTES = 65536;

	/** Largest delivery accepted (content plus metadata). */
	public const MAX_DELIVERY_BYTES = 1048576;

	/**
	 * Arva SEO's API base URL (no trailing slash).
	 */
	public static function api_url(): string {
		return self::checked_url( defined( 'ARVASEO_CONNECTOR_API_URL' ) ? (string) constant( 'ARVASEO_CONNECTOR_API_URL' ) : self::DEFAULT_API_URL, self::DEFAULT_API_URL );
	}

	/**
	 * The Arva SEO app's base URL, where people sign in and approve the connection.
	 */
	public static function app_url(): string {
		return self::checked_url( defined( 'ARVASEO_CONNECTOR_APP_URL' ) ? (string) constant( 'ARVASEO_CONNECTOR_APP_URL' ) : self::DEFAULT_APP_URL, self::DEFAULT_APP_URL );
	}

	/**
	 * Host of the API, for comparisons.
	 */
	public static function api_host(): string {
		return strtolower( (string) wp_parse_url( self::api_url(), PHP_URL_HOST ) );
	}

	/**
	 * Only https addresses, except plain http on a local or development site talking to a
	 * local Arva SEO (localhost, *.localhost, host.docker.internal) for testing.
	 *
	 * @param string $url      Configured URL.
	 * @param string $fallback Default.
	 */
	private static function checked_url( string $url, string $fallback ): string {
		$url   = untrailingslashit( trim( $url ) );
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			return $fallback;
		}
		$scheme = strtolower( $parts['scheme'] ?? '' );
		if ( 'https' === $scheme ) {
			return $url;
		}
		$host  = strtolower( $parts['host'] );
		$local = in_array( $host, array( 'localhost', '127.0.0.1', 'host.docker.internal' ), true ) || str_ends_with( $host, '.localhost' );
		if ( 'http' === $scheme && $local && in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) {
			return $url;
		}
		return $fallback;
	}
}
