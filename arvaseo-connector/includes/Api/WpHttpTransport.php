<?php
/**
 * The WordPress HTTP API transport.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Uses wp_remote_request(); the client sets TLS verification on and redirects off.
 */
final class WpHttpTransport implements HttpTransport {

	/**
	 * Send a request.
	 *
	 * @param string               $url  Absolute URL.
	 * @param array<string, mixed> $args Arguments.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function request( string $url, array $args ) {
		return wp_remote_request( $url, $args );
	}
}
