<?php
/**
 * Stable identities for verified static files and observed volatile external addresses.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Speed;

use ArvaSEO\Connector\Support\Lock;

defined( 'ABSPATH' ) || exit;

/** Query normalization never substitutes for checking a local file or an external approval. */
final class Addresses {

	public const VOLATILE = 'arvaseo_connector_volatile_addresses';

	public const BUSTERS = array( 'v', 'ver', 'version', 't', 'ts', 'time', 'timestamp', 'cache', 'cb', 'cachebuster', 'cache_buster', 'nocache', 'rev', 'revision', 'build', '_' );

	/**
	 * Remove only known busters or bare tokens. The caller must still verify the file on disk.
	 *
	 * @param string $url Address.
	 */
	public static function static_url( string $url ): ?string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || isset( $parts['fragment'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return null;
		}
		foreach ( explode( '&', (string) ( $parts['query'] ?? '' ) ) as $item ) {
			if ( '' === $item || ! str_contains( $item, '=' ) ) {
				continue;
			}
			$name = rawurldecode( explode( '=', $item, 2 )[0] );
			if ( ! in_array( $name, self::BUSTERS, true ) ) {
				return null;
			}
		}
		return explode( '?', $url, 2 )[0];
	}

	/**
	 * Verified local source. Reuse the existing path/origin/symlink boundary checks.
	 *
	 * @param string $url  Address.
	 * @param string $kind css or js.
	 * @param bool   $allow_minified Whether our own generated files are eligible.
	 * @return array{path: string, url: string, original: string}|null
	 */
	public static function local_source( string $url, string $kind, bool $allow_minified = true ): ?array {
		$plain = self::static_url( $url );
		if ( null === $plain ) {
			return null;
		}
		$source = AssetMinifier::source( $plain, $kind, $allow_minified );
		if ( null !== $source ) {
			$source['original'] = $url;
		}
		return $source;
	}

	/**
	 * Include resource base and actual bytes; same-second edits must never reuse old CSS.
	 *
	 * @param string $url Address.
	 */
	public static function local_static_key( string $url ): ?string {
		$kind   = strtolower( pathinfo( (string) wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
		$source = self::local_source( $url, $kind );
		if ( null === $source ) {
			return null;
		}
		$hash = md5_file( $source['path'] );
		return false === $hash ? null : 'local:' . hash( 'sha256', $source['url'] . '|' . $hash );
	}

	/**
	 * Observe only recent clock-like busters; semantic query variants have independent histories.
	 * Only hashes and timestamps are retained, never complete URLs or parameter values.
	 *
	 * @param string $url Address.
	 * @return string[] Volatile parameter names in this address.
	 */
	public static function volatile_params( string $url ): array {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) ) {
			return array();
		}
		parse_str( (string) ( $parts['query'] ?? '' ), $query );
		$candidates = array();
		$now        = time();
		foreach ( self::BUSTERS as $name ) {
			$value = $query[ $name ] ?? null;
			if ( is_string( $value ) && self::recent_clock( $value, $now ) ) {
				$candidates[ $name ] = hash( 'sha256', $value );
			}
		}
		if ( array() === $candidates ) {
			return array();
		}
		$context = self::without_parameters( $url, self::BUSTERS );
		$result  = Lock::with(
			'volatile-addresses',
			10,
			static function () use ( $candidates, $context, $now ): array {
				$history = get_option( self::VOLATILE, array() );
				$history = is_array( $history ) ? $history : array();
				$history = array_filter( $history, static fn ( $row ) => is_array( $row ) && ( $row['at'] ?? 0 ) > $now - DAY_IN_SECONDS );
				$names   = array();
				foreach ( $candidates as $name => $hash ) {
					$key      = hash( 'sha256', $context . '|' . $name );
					$row      = $history[ $key ] ?? array();
					$volatile = ! empty( $row['volatile'] ) || ( isset( $row['hash'] ) && $row['hash'] !== $hash );
					unset( $history[ $key ] );
					$history[ $key ] = array(
						'hash'     => $hash,
						'at'       => $now,
						'volatile' => $volatile,
					);
					if ( $volatile ) {
						$names[] = $name;
					}
				}
				update_option( self::VOLATILE, array_slice( $history, -200, null, true ), false );
				return $names;
			}
		);
		return is_array( $result ) ? $result : array();
	}

	/**
	 * Whether this address contains a demonstrated changing clock value.
	 *
	 * @param string $url Address.
	 */
	public static function is_volatile( string $url ): bool {
		return array() !== self::volatile_params( $url );
	}

	/**
	 * Stable external identity; a first observation, real version or unknown parameter is retained.
	 *
	 * @param string $url Address.
	 */
	public static function external_key( string $url ): string {
		$names = self::volatile_params( $url );
		return array() === $names ? $url : self::without_parameters( $url, $names );
	}

	/**
	 * Keep repeated parameters, bare tokens and encoding intact (Google Fonts repeats family).
	 *
	 * @param string   $url   Address.
	 * @param string[] $names Parameters to remove.
	 */
	public static function without_parameters( string $url, array $names ): string {
		$fragment = explode( '#', $url, 2 );
		$parts    = explode( '?', $fragment[0], 2 );
		if ( ! isset( $parts[1] ) ) {
			return $url;
		}
		$keep = array_filter( explode( '&', $parts[1] ), static fn ( string $pair ): bool => ! in_array( rawurldecode( str_replace( '+', ' ', explode( '=', $pair, 2 )[0] ) ), $names, true ) );
		return $parts[0] . ( array() === $keep ? '' : '?' . implode( '&', $keep ) ) . ( isset( $fragment[1] ) ? '#' . $fragment[1] : '' );
	}

	/**
	 * Match page-cache tracking exclusions without discarding content-bearing parameters.
	 *
	 * @param string $url Page address.
	 */
	public static function page_url( string $url ): string {
		return self::without_parameters( $url, PageCache::tracking_parameters() );
	}

	/**
	 * A bounded public clock value; used to retain an approved URL until volatility is demonstrated.
	 *
	 * @param string $value Query value.
	 */
	public static function clock_value( string $value ): bool {
		return self::recent_clock( $value, time() );
	}

	/**
	 * Clock-like values only, not ordinary package versions or old numeric identifiers.
	 *
	 * @param string $value Parameter value.
	 * @param int    $now   Current UTC epoch.
	 */
	private static function recent_clock( string $value, int $now ): bool {
		$stamp = 0;
		if ( ctype_digit( $value ) && in_array( strlen( $value ), array( 10, 13 ), true ) ) {
			$stamp = 13 === strlen( $value ) ? (int) floor( (float) $value / 1000 ) : (int) $value;
		} elseif ( 1 === preg_match( '/^[a-f0-9]{13}(?:\.[0-9]{8})?$/i', $value ) ) {
			$stamp = (int) hexdec( substr( $value, 0, 8 ) );
		}
		return $stamp >= $now - 2 * DAY_IN_SECONDS && $stamp <= $now + DAY_IN_SECONDS;
	}
}
