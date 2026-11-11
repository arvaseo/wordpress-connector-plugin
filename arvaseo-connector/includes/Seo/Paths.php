<?php
/**
 * Web address paths for the broken-address log and the redirects: one way to write a path, what
 * to ignore, what may never be redirected, and what a redirect may point to.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Seo;

defined( 'ABSPATH' ) || exit;

/**
 * Pure helpers. A request's path is never trusted: it is cut to a plain path, decoded once,
 * checked and lower-cased before it is compared with anything or stored.
 */
final class Paths {

	/** The longest path kept. */
	public const MAX_LENGTH = 500;

	/** The longest redirect target kept. */
	public const MAX_TARGET = 2000;

	/** Files that are not pages: a missing one is not a broken link anyone can fix with a redirect. */
	private const ASSET = '/\.(?:jpe?g|png|gif|webp|avif|svg|ico|bmp|css|js|map|woff2?|ttf|eot|otf|mp[34]|webm|ogg|wav|mov|avi|swf|txt)$/';

	/** Files and folders that bots probe for; none of them is a page of this site. */
	private const SCANNER = '~(?:\.(?:php\d?|phtml|asp|aspx|jsp|cgi|pl|sh|py|exe|sql|bak|old|orig|env|ini|log|conf|cfg|yml|yaml|zip|rar|7z|tar|tgz|gz)(?:/|$)|(?:^|/)\.|/(?:wp-admin|wp-includes|wp-content|wp-json|wp-login|wp-config|wp-cron|xmlrpc|cgi-bin|phpmyadmin|pma|phpinfo|vendor|node_modules|cpanel|webmail|server-status|actuator|owa|ecp|autodiscover|boaform|eval-stdin|adminer)(?:[./-]|/|$))~i';

	/** What can never be redirected, so no rule can lock anyone out of the dashboard or break the site's own endpoints. */
	private const PROTECTED = '~^/(?:wp-admin|wp-login\.php|wp-json|wp-cron\.php|xmlrpc\.php|wp-includes|wp-content|robots\.txt|favicon\.ico)(?:/|$)~i';

	/**
	 * A request address as a plain path inside this site: no query or fragment, decoded once,
	 * without the site's own folder, single slashes, no trailing slash (except "/"), lower case.
	 * Null for anything that is not a usable path (control characters, backslashes, "..", broken
	 * text encoding, a different website, too long).
	 *
	 * @param string $uri A request address (REQUEST_URI), a path, or this site's full address.
	 */
	public static function normalize( string $uri ): ?string {
		$uri = trim( $uri );
		if ( '' === $uri || strlen( $uri ) > 3 * self::MAX_LENGTH ) {
			return null;
		}
		$path = substr( $uri, 0, strcspn( $uri, '?#' ) );
		if ( preg_match( '~^[a-z][a-z0-9+.-]*://~i', $path ) ) {
			$parts = wp_parse_url( $path );
			$host  = is_array( $parts ) ? strtolower( (string) ( $parts['host'] ?? '' ) ) : '';
			if ( '' === $host || strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) !== $host ) {
				return null;
			}
			$path = (string) ( $parts['path'] ?? '/' );
		}
		$path = rawurldecode( $path );
		if ( '' === $path || preg_match( '/[\x00-\x1F\x7F\\\\]/', $path ) || ! mb_check_encoding( $path, 'UTF-8' ) ) {
			return null;
		}
		if ( '/' !== $path[0] ) {
			$path = '/' . $path;
		}
		$path = (string) preg_replace( '~/{2,}~', '/', $path );
		$base = untrailingslashit( (string) wp_parse_url( home_url(), PHP_URL_PATH ) );
		if ( '' !== $base && ( strtolower( $path ) === strtolower( $base ) || str_starts_with( strtolower( $path ), strtolower( $base ) . '/' ) ) ) {
			$path = substr( $path, strlen( $base ) );
			$path = '' === $path ? '/' : $path;
		}
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '.' === $segment || '..' === $segment ) {
				return null;
			}
		}
		if ( '/' !== $path ) {
			$path = rtrim( $path, '/' );
		}
		$path = mb_strtolower( $path, 'UTF-8' );
		return '' !== $path && strlen( $path ) <= self::MAX_LENGTH ? $path : null;
	}

	/**
	 * Whether a missing address is noise that should not be logged: a file that is not a page,
	 * something bots probe for, or text that looks like an attack rather than a link.
	 *
	 * @param string $path A normalized path.
	 */
	public static function is_noise( string $path ): bool {
		if ( strlen( $path ) > 200 || preg_match( '~[<>"\'`;${}|^]|%[0-9a-f]{2}~i', $path ) ) {
			return true;
		}
		return 1 === preg_match( self::ASSET, $path ) || 1 === preg_match( self::SCANNER, $path );
	}

	/**
	 * Whether an address can never be the start of a redirect.
	 *
	 * @param string $path A normalized path.
	 */
	public static function is_protected( string $path ): bool {
		return '/' === $path || 1 === preg_match( self::PROTECTED, $path );
	}

	/**
	 * What a redirect may point to: a path on this site (starting with a single "/"), or an
	 * explicit http or https address on another website. This site's own full address becomes a
	 * path. Anything else (javascript:, data:, //host, backslashes, spaces, addresses with a user
	 * name) is refused, so a rule can never become an open redirect by accident.
	 *
	 * @param string $target What an administrator typed.
	 * @return string|null The cleaned target, or null.
	 */
	public static function sanitize_target( string $target ): ?string {
		$target = trim( $target );
		if ( '' === $target || strlen( $target ) > self::MAX_TARGET || preg_match( '/[\x00-\x20\x7F\\\\<>"\'`]/', $target ) ) {
			return null;
		}
		if ( '/' === $target[0] ) {
			return str_starts_with( $target, '//' ) ? null : $target;
		}
		if ( ! preg_match( '~^https?://~i', $target ) ) {
			return null;
		}
		$parts = wp_parse_url( $target );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return null;
		}
		$host = strtolower( (string) $parts['host'] );
		if ( ! preg_match( '/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/', $host ) || str_contains( $host, '..' ) ) {
			return null;
		}
		if ( isset( $parts['port'] ) && ( (int) $parts['port'] < 1 || (int) $parts['port'] > 65535 ) ) {
			return null;
		}
		if ( strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) === $host && ! isset( $parts['port'] ) ) {
			$local = ( $parts['path'] ?? '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' ) . ( isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '' );
			return str_starts_with( $local, '//' ) ? null : $local;
		}
		$clean = esc_url_raw( $target, array( 'http', 'https' ) );
		return '' !== $clean ? $clean : null;
	}

	/**
	 * Whether a target is another website.
	 *
	 * @param string $target A sanitized target.
	 */
	public static function is_external( string $target ): bool {
		return '' !== $target && '/' !== $target[0];
	}
}
