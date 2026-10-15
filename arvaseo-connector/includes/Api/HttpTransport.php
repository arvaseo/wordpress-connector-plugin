<?php
/**
 * Outbound HTTP, behind an interface so tests can replace it.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Sends one request; returns WP_Error or the WordPress HTTP API response array.
 */
interface HttpTransport {

	/**
	 * Send a request.
	 *
	 * @param string               $url  Absolute URL.
	 * @param array<string, mixed> $args wp_remote_request() arguments.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function request( string $url, array $args );
}
