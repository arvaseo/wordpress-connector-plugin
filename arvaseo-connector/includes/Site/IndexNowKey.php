<?php
/**
 * The website's IndexNow key file.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Site;

use ArvaSEO\Connector\Auth\TokenStore;

defined( 'ABSPATH' ) || exit;

/**
 * Serves the IndexNow key Arva SEO gave this site as /{key}.txt, so Bing, Yandex and the other
 * IndexNow search engines accept the pages Arva SEO tells them changed. Nothing is stored
 * besides the key, which is public by design; it stops being served when the site disconnects.
 */
final class IndexNowKey {

	/**
	 * What IndexNow accepts as a key.
	 */
	private const PATTERN = '/^[a-zA-Z0-9-]{8,128}$/';

	/**
	 * A key from the API, or null if it isn't one.
	 *
	 * @param mixed $key Candidate.
	 */
	public static function sanitize( $key ): ?string {
		return is_string( $key ) && preg_match( self::PATTERN, $key ) ? $key : null;
	}

	/**
	 * The stored key, while connected.
	 */
	public static function key(): ?string {
		return TokenStore::is_connected() ? self::sanitize( TokenStore::connection()['indexnow_key'] ?? null ) : null;
	}

	/**
	 * Answer a request for /{key}.txt with the key, before WordPress looks for a page.
	 */
	public static function maybe_serve(): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) || ! str_ends_with( (string) wp_parse_url( $uri, PHP_URL_PATH ), '.txt' ) ) {
			return;
		}
		$key = self::key();
		if ( null === $key || wp_parse_url( $uri, PHP_URL_PATH ) !== '/' . $key . '.txt' ) {
			return;
		}

		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		header( 'Cache-Control: public, max-age=3600' );
		if ( 'HEAD' !== $method ) {
			echo esc_html( $key );
		}
		exit;
	}
}
