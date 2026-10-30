<?php
/**
 * Explicit PageSpeed tests from WordPress, using the paired workspace's allowance.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Speed;

use ArvaSEO\Connector\Api\ApiClient;
use ArvaSEO\Connector\Auth\TokenStore;
use ArvaSEO\Connector\Install\Capabilities;

defined( 'ABSPATH' ) || exit;

/** Never run paid tests while rendering an admin screen or refreshing results. */
final class PageSpeed {
	public const OPTION = 'arvaseo_connector_pagespeed_tests';

	/** Bind saved tests to their paired site and workspace. */
	private static function context(): string {
		$connection = TokenStore::connection();
		return hash( 'sha256', (string) wp_json_encode( array( TokenStore::site_id(), $connection['workspace']['id'] ?? '', $connection['project']['id'] ?? '' ) ) );
	}

	/**
	 * Last successful response for this connection.
	 *
	 * @return array<string,mixed>|null Saved history.
	 */
	public static function snapshot(): ?array {
		$saved = get_option( self::OPTION );
		return TokenStore::is_connected() && is_array( $saved ) && ( $saved['context'] ?? '' ) === self::context() ? $saved : null;
	}

	/**
	 * Explicit administrator request; retries reuse the form's UUID server-side.
	 *
	 * @param string   $action start or check.
	 * @param string   $url Own public page.
	 * @param string[] $devices Requested device types.
	 * @param int      $runs One or three Google runs.
	 * @param string   $request_id Idempotency UUID.
	 */
	public static function request( string $action, string $url = '', array $devices = array(), int $runs = 3, string $request_id = '' ): string {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			return 'forbidden';
		}
		if ( ! TokenStore::is_connected() ) {
			return 'disconnected';
		}
		if ( ! in_array( $action, array( 'start', 'check' ), true ) ) {
			return 'invalid';
		}
		$start = 'start' === $action;
		if ( $start && ! empty( self::snapshot()['uncertain'] ) ) {
			return 'check_first';
		}
		if ( $start && ( ! AssetMinifier::same_origin( $url, home_url( '/' ) ) || ! wp_http_validate_url( $url ) || wp_parse_url( $url, PHP_URL_QUERY )
			|| array() === $devices || array_diff( $devices, array( 'mobile', 'desktop' ) ) || count( $devices ) > 2 || ! in_array( $runs, array( 1, 3 ), true )
			|| ! wp_is_uuid( $request_id ) ) ) {
			return 'invalid';
		}
		$key = 'arvaseo_pagespeed_' . self::context() . '_' . $action;
		if ( get_transient( $key ) ) {
			return 'wait';
		}
		set_transient( $key, 1, $start ? 60 : 10 );
		$response = ApiClient::instance()->site(
			$start ? 'POST' : 'GET',
			'/pagespeed-tests',
			$start ? array(
				'url'        => $url,
				'strategies' => array_values( array_unique( $devices ) ),
				'runs'       => $runs,
				'request_id' => $request_id,
			) : null
		);
		if ( ! $response->ok() || ! is_array( $response->body['data'] ?? null ) ) {
			if ( $start && ( 0 === $response->status || $response->status >= 500 ) ) {
				update_option(
					self::OPTION,
					array_merge(
						self::snapshot() ?? array(
							'context' => self::context(),
							'at'      => time(),
							'data'    => array(),
						),
						array( 'uncertain' => true )
					),
					false
				);
			}
			if ( 402 === $response->status ) {
				return 'no_allowance';
			}
			return 'unavailable';
		}
		$saved = self::snapshot() ?? array();
		update_option(
			self::OPTION,
			array(
				'context'   => self::context(),
				'at'        => time(),
				'data'      => array_slice( $response->body['data'], 0, 10 ),
				'allowance' => $response->body['allowance'] ?? $saved['allowance'] ?? null,
			),
			false
		);
		return $start ? 'started' : 'updated';
	}
}
