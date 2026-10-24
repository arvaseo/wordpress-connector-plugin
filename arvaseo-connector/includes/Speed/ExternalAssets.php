<?php
/**
 * Approved public CSS/JS cached by a pinned background download, never during a page response.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Speed;

use ArvaSEO\Connector\Content\MediaImporter;
use ArvaSEO\Connector\Support\Lock;

defined( 'ABSPATH' ) || exit;

/** All sources require an exact administrator approval and both combination switches. */
final class ExternalAssets {

	public const HOOK = 'arvaseo_connector_external_asset_warm';

	public const CACHE_PREFIX = 'arvaseo_ext_';

	public const INDEX = 'arvaseo_connector_external_assets';

	private const MAX_URLS = 20;

	private const MAX_BYTES = 1048576;

	/** Background work only. */
	public static function init(): void {
		add_action( self::HOOK, array( self::class, 'warm' ), 10, 2 );
	}

	/**
	 * Exact HTTPS .css/.js addresses, retaining only numeric public version query parameters.
	 * This syntax-only step never performs DNS or HTTP requests and never discovers sources.
	 *
	 * @param mixed $input One address per line, or an array of addresses.
	 * @return string[]
	 */
	public static function normalize_urls( $input ): array {
		$lines = is_string( $input ) ? preg_split( '/\r\n|\r|\n/', $input ) : ( is_array( $input ) ? $input : array() );
		$urls  = array();
		foreach ( (array) $lines as $line ) {
			$url = is_string( $line ) ? self::normalize_url( trim( $line ) ) : null;
			if ( null !== $url && ! in_array( $url, $urls, true ) ) {
				$urls[] = $url;
				if ( count( $urls ) >= self::MAX_URLS ) {
					break;
				}
			}
		}
		return $urls;
	}

	/**
	 * Normalize safe syntax without interpreting or dropping any part of its query string.
	 *
	 * @param string $url Address.
	 */
	private static function normalize_url( string $url ): ?string {
		if ( strlen( $url ) > 2048 || preg_match( '/[^\x21-\x7E]|[\\\\<>"\']/', $url ) ) {
			return null;
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== strtolower( $parts['scheme'] ?? '' ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) || ( isset( $parts['port'] ) && 443 !== $parts['port'] ) ) {
			return null;
		}
		$host = strtolower( $parts['host'] );
		$path = $parts['path'] ?? '';
		if ( strlen( $host ) > 253 || ! preg_match( '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $host ) || preg_match( '/\.(?:localhost|local|localdomain|internal|intranet|lan|home|corp|test|invalid|example|onion|arpa)$/D', $host ) || ! preg_match( '~^/[^?#]*\.(?:css|js)$~iD', $path ) || preg_match( '/%(?![a-f0-9]{2})|%(?:00|0[ad]|2f|5c)/i', $path ) ) {
			return null;
		}
		if ( isset( $parts['query'] ) ) {
			$seen = array();
			foreach ( explode( '&', $parts['query'] ) as $pair ) {
				if ( ! preg_match( '/^(ver|v)=([0-9]+(?:[._-][0-9]+){0,6})$/D', $pair, $match ) || strlen( $match[2] ) > 40 || isset( $seen[ $match[1] ] ) ) {
					return null;
				}
				$seen[ $match[1] ] = true;
			}
		}
		return 'https://' . $host . $path . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
	}

	/**
	 * Only read an approved cache entry here. A miss schedules one background job.
	 *
	 * @param string $url  Exact approved address.
	 * @param string $kind css or js.
	 * @return array{text: string, url: string}|null
	 */
	public static function cached( string $url, string $kind ): ?array {
		$url = self::normalize_url( $url );
		if ( null === $url || ! self::approved( $url, $kind ) ) {
			return null;
		}
		$key    = self::key( $url, $kind );
		$cached = self::read( $key, $url, $kind );
		if ( null !== $cached ) {
			return $cached;
		}
		if ( false === get_transient( $key . '_failed' ) && ! wp_next_scheduled( self::HOOK, array( $url, $kind ) ) ) {
			wp_schedule_single_event( time() + 5, self::HOOK, array( $url, $kind ) );
		}
		return null;
	}

	/**
	 * Check stored states so a cron callback can warm a Try without impersonating an administrator.
	 * The output caller still applies the parent fix's page and signed-in Try restrictions.
	 *
	 * @param string $url  Canonical address.
	 * @param string $kind css or js.
	 */
	private static function approved( string $url, string $kind ): bool {
		if ( ! in_array( $kind, array( 'css', 'js' ), true ) || ( defined( Fixes::KILL_SWITCH ) && constant( Fixes::KILL_SWITCH ) ) || strtolower( (string) pathinfo( (string) wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) ) !== $kind ) {
			return false;
		}
		$stored = Fixes::stored();
		if ( ! in_array( $url, self::normalize_urls( $stored['external_asset_urls'] ?? array() ), true ) ) {
			return false;
		}
		foreach ( array( 'combine_' . $kind, 'combine_external_' . $kind ) as $id ) {
			if ( ! in_array( $stored['fixes'][ $id ]['state'] ?? 'off', array( 'on', 'trying' ), true ) || null !== Fixes::handled_by( $id ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Warm an approved immutable six-hour entry. Every failed attempt cools down for 15 minutes.
	 *
	 * @param string $url  Approved address.
	 * @param string $kind css or js.
	 */
	public static function warm( string $url, string $kind ): void {
		$url = self::normalize_url( $url );
		if ( null === $url ) {
			return;
		}
		wp_clear_scheduled_hook( self::HOOK, array( $url, $kind ) );
		if ( ! self::approved( $url, $kind ) || ! Lock::acquire( 'external_assets', 45 ) ) {
			return;
		}
		try {
			$key = self::key( $url, $kind );
			if ( null !== self::read( $key, $url, $kind ) || false !== get_transient( $key . '_failed' ) ) {
				return;
			}
			self::remember( $key, $url, $kind );
			$body = self::download( $url, $kind );
			if ( null === $body ) {
				set_transient( $key . '_failed', true, 15 * MINUTE_IN_SECONDS );
				return;
			}
			// Approval can be withdrawn while the background request is running.
			if ( ! self::approved( $url, $kind ) ) {
				return;
			}
			if ( set_transient(
				$key,
				array(
					'url'    => $url,
					'kind'   => $kind,
					'text'   => $body,
					'sha256' => hash( 'sha256', $body ),
				),
				6 * HOUR_IN_SECONDS
			) ) {
				Fixes::pages_changed( 'external_assets' );
			}
		} finally {
			Lock::release( 'external_assets' );
		}
	}

	/**
	 * Bounded cache reads; no HTTP transport can be reached through this method.
	 *
	 * @param string $key  Transient name.
	 * @param string $url  Canonical address.
	 * @param string $kind Expected type.
	 * @return array{text: string, url: string}|null
	 */
	private static function read( string $key, string $url, string $kind ): ?array {
		$entry = get_transient( $key );
		if ( ! is_array( $entry ) || ( $entry['url'] ?? null ) !== $url || ( $entry['kind'] ?? null ) !== $kind || ! is_string( $entry['text'] ?? null ) || ! is_string( $entry['sha256'] ?? null ) || ! self::text( $entry['text'] ) || ! hash_equals( hash( 'sha256', $entry['text'] ), $entry['sha256'] ) ) {
			return null;
		}
		return array(
			'text' => $entry['text'],
			'url'  => $url,
		);
	}

	/**
	 * Resolve once, validate every A/AAAA answer, and pin that exact snapshot's first address.
	 *
	 * @param string $host Hostname.
	 * @return string[]
	 */
	private static function addresses( string $host ): array {
		if ( ! function_exists( 'dns_get_record' ) ) {
			return array();
		}
		$records = @dns_get_record( $host, DNS_A | DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- DNS failures are a normal refused source.
		if ( ! is_array( $records ) || count( $records ) > 64 ) {
			return array();
		}
		$ips = array();
		foreach ( $records as $record ) {
			$ip = $record['ip'] ?? $record['ipv6'] ?? null;
			if ( null === $ip ) {
				continue;
			}
			if ( ! is_string( $ip ) || ! MediaImporter::is_public_ip( $ip ) ) {
				return array();
			}
			$ips[] = $ip;
		}
		return array_values( array_unique( $ips ) );
	}

	/**
	 * Pinned cURL is required here: the WP HTTP API can fall back to an unpinned streams transport.
	 * No proxy, redirects, credentials, cookies, compressed bodies or non-HTTPS protocols.
	 * TLS keeps the original hostname, while RESOLVE fixes its connection address.
	 *
	 * @param string $url  Canonical approved address.
	 * @param string $kind css or js.
	 */
	private static function download( string $url, string $kind ): ?string {
		if ( ! function_exists( 'curl_init' ) || ! defined( 'CURLOPT_RESOLVE' ) || ( new \WP_Http() )->block_request( $url ) ) {
			return null; // Never fall back to a transport that can re-resolve the hostname.
		}
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		$ips  = self::addresses( $host );
		if ( array() === $ips ) {
			return null;
		}
		$ip       = $ips[0];
		$body     = '';
		$headers  = array();
		$received = 0;
		// phpcs:disable WordPress.WP.AlternativeFunctions -- cURL's pinned connection and streaming byte limits are required; the WP HTTP API has an unpinned fallback.
		$handle = curl_init();
		if ( false === $handle ) {
			return null;
		}
		try {
			$options = array(
				CURLOPT_URL            => $url,
				CURLOPT_RESOLVE        => array( $host . ':443:' . ( str_contains( $ip, ':' ) ? '[' . $ip . ']' : $ip ) ),
				CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_MAXREDIRS      => 0,
				CURLOPT_PROXY          => '',
				CURLOPT_NOPROXY        => '*',
				CURLOPT_NETRC          => false, // CURL_NETRC_IGNORED (0): never consult .netrc credentials.
				CURLOPT_SSL_VERIFYPEER => true,
				CURLOPT_SSL_VERIFYHOST => 2,
				CURLOPT_CONNECTTIMEOUT => 5,
				CURLOPT_TIMEOUT        => 15,
				CURLOPT_FRESH_CONNECT  => true,
				CURLOPT_FORBID_REUSE   => true,
				CURLOPT_HTTPHEADER     => array( 'Accept: ' . ( 'css' === $kind ? 'text/css' : 'application/javascript, text/javascript' ), 'Accept-Encoding: identity' ),
				CURLOPT_USERAGENT      => 'Arva SEO static asset cache',
				CURLOPT_HEADERFUNCTION => static function ( $curl, string $line ) use ( &$headers, &$received ): int {
					$received += strlen( $line );
					if ( $received > 32768 || preg_match( '/^[ \t]|[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $line ) ) {
						return 0;
					}
					if ( str_starts_with( $line, 'HTTP/' ) ) {
						$headers = array();
					} elseif ( str_contains( $line, ':' ) ) {
						list( $name, $value ) = explode( ':', $line, 2 );
						$name             = strtolower( trim( $name ) );
						$value            = trim( $value );
						$headers[ $name ] = isset( $headers[ $name ] ) ? $headers[ $name ] . ', ' . $value : $value;
						if ( 'content-length' === $name && ( ! ctype_digit( $value ) || (int) $value > self::MAX_BYTES ) ) {
							return 0;
						}
					}
					return strlen( $line );
				},
				CURLOPT_WRITEFUNCTION  => static function ( $curl, string $chunk ) use ( &$body ): int {
					if ( strlen( $body ) + strlen( $chunk ) > self::MAX_BYTES ) {
						return 0;
					}
					$body .= $chunk;
					return strlen( $chunk );
				},
			);
			if ( ! curl_setopt_array( $handle, $options ) || false === curl_exec( $handle ) || 200 !== curl_getinfo( $handle, CURLINFO_RESPONSE_CODE ) || inet_pton( $ip ) !== @inet_pton( (string) curl_getinfo( $handle, CURLINFO_PRIMARY_IP ) ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a malformed peer address is a failed request.
				return null;
			}
		} catch ( \Throwable $error ) {
			return null;
		} finally {
			// PHP 8 uses CurlHandle objects; releasing this reference closes the handle.
			unset( $handle );
		}
		// phpcs:enable WordPress.WP.AlternativeFunctions
		return self::public_headers( $headers, $kind, strlen( $body ) ) && self::text( $body ) ? $body : null;
	}

	/**
	 * Refuse private, user-varying, compressed, non-UTF-8 or unexpected response representations.
	 *
	 * @param array<string, string> $headers Response headers.
	 * @param string                $kind    css or js.
	 * @param int                   $bytes   Received body size.
	 */
	private static function public_headers( array $headers, string $kind, int $bytes ): bool {
		if ( isset( $headers['set-cookie'] ) || ( isset( $headers['vary'] ) && 'accept-encoding' !== strtolower( trim( $headers['vary'] ) ) ) || isset( $headers['www-authenticate'] ) || preg_match( '/(?:^|,)\s*(?:private|no-store|no-cache)\b/i', $headers['cache-control'] ?? '' ) || ! in_array( strtolower( $headers['content-encoding'] ?? '' ), array( '', 'identity' ), true ) ) {
			return false;
		}
		if ( isset( $headers['content-length'] ) && ( ! ctype_digit( $headers['content-length'] ) || (int) $headers['content-length'] !== $bytes ) ) {
			return false;
		}
		$type = array_map( 'trim', explode( ';', strtolower( $headers['content-type'] ?? '' ) ) );
		$mime = array_shift( $type );
		if ( ! in_array( $mime, 'css' === $kind ? array( 'text/css' ) : array( 'application/javascript', 'text/javascript', 'application/ecmascript', 'text/ecmascript', 'application/x-javascript' ), true ) ) {
			return false;
		}
		foreach ( $type as $parameter ) {
			if ( ! preg_match( '/^charset\s*=\s*["\']?(?:utf-8|utf8|us-ascii)["\']?$/D', $parameter ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Static UTF-8 text only, bounded before caching and again before use.
	 *
	 * @param string $body Response body.
	 */
	private static function text( string $body ): bool {
		return '' !== $body && strlen( $body ) <= self::MAX_BYTES && 1 === preg_match( '//u', $body ) && ! preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]|<\s*(?:!doctype|html\b|head\b|body\b|script\b)|<\?php/i', $body );
	}

	/**
	 * Keep at most 20 tracked entries, removing approvals withdrawn since the last background job.
	 *
	 * @param string $key  Cache key.
	 * @param string $url  Canonical address.
	 * @param string $kind css or js.
	 */
	private static function remember( string $key, string $url, string $kind ): void {
		$index   = self::index();
		$allowed = self::normalize_urls( Fixes::stored()['external_asset_urls'] ?? array() );
		foreach ( $index as $old => $entry ) {
			if ( ! in_array( $entry['url'], $allowed, true ) ) {
				delete_transient( $old );
				delete_transient( $old . '_failed' );
				wp_clear_scheduled_hook( self::HOOK, array( $entry['url'], $entry['kind'] ) );
				unset( $index[ $old ] );
			}
		}
		unset( $index[ $key ] );
		$count = count( $index );
		while ( $count >= self::MAX_URLS ) {
			$old = array_key_first( $index );
			delete_transient( $old );
			delete_transient( $old . '_failed' );
			wp_clear_scheduled_hook( self::HOOK, array( $index[ $old ]['url'], $index[ $old ]['kind'] ) );
			unset( $index[ $old ] );
			--$count;
		}
		$index[ $key ] = array(
			'url'  => $url,
			'kind' => $kind,
		);
		update_option( self::INDEX, $index, false );
	}

	/**
	 * The bounded cache manifest.
	 *
	 * @return array<string, array{url: string, kind: string}>
	 */
	private static function index(): array {
		$saved = get_option( self::INDEX, array() );
		return is_array( $saved ) ? array_filter( $saved, static fn ( $entry, $key ) => is_string( $key ) && str_starts_with( $key, self::CACHE_PREFIX ) && is_array( $entry ) && is_string( $entry['url'] ?? null ) && is_string( $entry['kind'] ?? null ), ARRAY_FILTER_USE_BOTH ) : array();
	}

	/**
	 * A URL/kind cache key, independent of any page or visitor identity.
	 *
	 * @param string $url  Canonical address.
	 * @param string $kind css or js.
	 */
	private static function key( string $url, string $kind ): string {
		return self::CACHE_PREFIX . hash( 'sha256', $kind . '|' . $url );
	}

	/** Remove plugin-owned copies, failure cooldowns and every queued warm job on uninstall. */
	public static function delete_all(): void {
		foreach ( self::index() as $key => $entry ) {
			delete_transient( $key );
			delete_transient( $key . '_failed' );
		}
		delete_option( self::INDEX );
		wp_unschedule_hook( self::HOOK );
	}
}
