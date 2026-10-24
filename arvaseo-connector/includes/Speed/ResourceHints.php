<?php
/**
 * Administrator-chosen browser DNS and connection hints.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Speed;

defined( 'ABSPATH' ) || exit;

/**
 * Adds origins through WordPress's resource-hint API; never contacts those origins or changes DNS.
 *
 * @see https://developer.wordpress.org/reference/hooks/wp_resource_hints/
 * @see https://web.dev/articles/preconnect-and-dns-prefetch#how_to_implement_relpreconnect
 */
final class ResourceHints {

	/** Maximum explicit DNS lookups to suggest. */
	public const MAX_PREFETCH = 10;

	/** Only the most important third-party connections should be started early. */
	public const MAX_PRECONNECT = 4;

	/**
	 * Clean a textarea or saved list into unique HTTPS origins. Credentials are rejected entirely;
	 * paths and query strings from ordinary pasted URLs are discarded and never saved or printed.
	 * WordPress drops ports when printing these hints, so non-default ports are not accepted.
	 *
	 * @param mixed $value Textarea or list.
	 * @param int   $limit Maximum origins to keep.
	 * @return string[]
	 */
	public static function normalize( $value, int $limit ): array {
		$limit = max( 0, min( self::MAX_PREFETCH, $limit ) );
		if ( 0 === $limit || ( ! is_array( $value ) && ! is_string( $value ) ) ) {
			return array();
		}
		$lines   = is_string( $value ) ? preg_split( '/[\r\n,]+/', $value ) : $value;
		$origins = array();
		foreach ( array_slice( is_array( $lines ) ? $lines : array(), 0, 100 ) as $line ) {
			if ( ! is_string( $line ) ) {
				continue;
			}
			$line = trim( $line );
			if ( '' === $line || strlen( $line ) > 2048 || preg_match( '/[\x00-\x20\x7f\\\\]/', $line ) ) {
				continue;
			}
			$url    = 0 === strpos( $line, '//' ) ? 'https:' . $line : $line;
			$url    = false === strpos( $url, '://' ) ? 'https://' . $url : $url;
			$parsed = wp_parse_url( $url );
			if ( ! is_array( $parsed ) || isset( $parsed['user'] ) || isset( $parsed['pass'] ) ) {
				continue;
			}
			$scheme = strtolower( $parsed['scheme'] ?? '' );
			$host   = strtolower( $parsed['host'] ?? '' );
			if ( ! in_array( $scheme, array( 'http', 'https' ), true )
				|| false === strpos( $host, '.' )
				|| false === filter_var( $host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME )
				|| false !== filter_var( $host, FILTER_VALIDATE_IP )
				|| preg_match( '/\.(?:localhost|local|internal)$/', $host )
				|| ( isset( $parsed['port'] ) && ( 'https' === $scheme ? 443 : 80 ) !== $parsed['port'] ) ) {
				continue;
			}
			$origin             = 'https://' . $host;
			$origins[ $origin ] = $origin;
			if ( count( $origins ) >= $limit ) {
				break;
			}
		}
		return array_values( $origins );
	}

	/**
	 * Preserve other plugins' hint attributes, adding only missing origins in this relation.
	 * DNS-prefetch and preconnect are separate hints: the former can be the latter's fallback.
	 *
	 * @param array<int|string, string|array<string, mixed>> $urls WordPress's existing hints.
	 * @param string                                         $relation Hint relation.
	 * @return array<int|string, string|array<string, mixed>>
	 */
	public static function filter( array $urls, string $relation ): array {
		$id = 'dns-prefetch' === $relation ? 'dns_prefetch' : ( 'preconnect' === $relation ? 'preconnect' : null );
		if ( null === $id || ! Fixes::applies( $id ) ) {
			return $urls;
		}
		$settings = Fixes::stored();
		$limit    = 'preconnect' === $relation ? self::MAX_PRECONNECT : self::MAX_PREFETCH;
		$origins  = self::normalize( $settings[ $id . '_origins' ] ?? array(), $limit );
		$seen     = array();
		foreach ( $urls as $url ) {
			$href = is_array( $url ) ? ( $url['href'] ?? null ) : $url;
			if ( is_string( $href ) ) {
				$seen[ self::identity( $href, $relation ) ] = true;
			}
		}
		// DNS is already resolved for this host. Preconnect is redundant only for the same origin.
		foreach ( array( home_url( '/' ), site_url( '/' ) ) as $own ) {
			$seen[ self::identity( $own, $relation ) ] = true;
		}
		foreach ( $origins as $origin ) {
			$key = self::identity( $origin, $relation );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			if ( 'preconnect' === $relation ) {
				$hint = array( 'href' => $origin );
				if ( ! empty( $settings['preconnect_anonymous'] ) ) {
					$hint['crossorigin'] = 'anonymous';
				}
				$urls[] = $hint;
			} else {
				$urls[] = '//' . wp_parse_url( $origin, PHP_URL_HOST );
			}
		}
		return $urls;
	}

	/**
	 * DNS lookup identity is a host; a connection identity includes its scheme and port.
	 *
	 * @param string $url Hint or site URL.
	 * @param string $relation Hint relation.
	 */
	private static function identity( string $url, string $relation ): string {
		$url    = 0 === strpos( $url, '//' ) ? 'https:' . $url : $url;
		$url    = false === strpos( $url, '://' ) ? 'https://' . $url : $url;
		$parsed = wp_parse_url( $url );
		if ( ! is_array( $parsed ) || empty( $parsed['host'] ) ) {
			return '';
		}
		$host = strtolower( $parsed['host'] );
		if ( 'dns-prefetch' === $relation ) {
			return $host;
		}
		$scheme = strtolower( $parsed['scheme'] ?? 'https' );
		$port   = $parsed['port'] ?? ( 'https' === $scheme ? 443 : 80 );
		return $scheme . '://' . $host . ':' . $port;
	}
}
