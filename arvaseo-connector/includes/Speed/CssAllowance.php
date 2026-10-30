<?php
/**
 * Read-only display of the shared Critical CSS and Unused CSS allowance.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Speed;

use ArvaSEO\Connector\Api\ApiClient;
use ArvaSEO\Connector\Auth\TokenStore;
use ArvaSEO\Connector\Install\Capabilities;

defined( 'ABSPATH' ) || exit;

/** The API remains the authority; reading a balance never requests a render. */
final class CssAllowance {
	public const OPTION = 'arvaseo_connector_css_allowance';

	/** Connection identity prevents a previous workspace's balance being shown after reconnecting. */
	private static function context(): string {
		$connection = TokenStore::connection();
		return hash( 'sha256', (string) wp_json_encode( array( TokenStore::site_id(), $connection['workspace']['id'] ?? '', $connection['project']['id'] ?? '' ) ) );
	}

	/**
	 * Validate the existing API shape without interpreting missing values as zero.
	 *
	 * @param array<string,mixed> $data API or saved balance.
	 */
	private static function valid( array $data ): bool {
		if ( ! isset( $data['used'], $data['resets_at'] ) || ! is_int( $data['used'] ) || $data['used'] < 0 || ! is_string( $data['resets_at'] )
			|| ! array_key_exists( 'limit', $data ) || ! array_key_exists( 'remaining', $data ) ) {
			return false;
		}
		foreach ( array( 'limit', 'remaining' ) as $key ) {
			if ( null !== $data[ $key ] && ( ! is_int( $data[ $key ] ) || $data[ $key ] < 0 ) ) {
				return false;
			}
		}
		if ( ( null === $data['limit'] ) !== ( null === $data['remaining'] ) ) {
			return false;
		}
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $data['resets_at'] ) ) {
			return false;
		}
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:s\Z', $data['resets_at'], new \DateTimeZone( 'UTC' ) );
		return false !== $date && $date->format( 'Y-m-d\TH:i:s\Z' ) === $data['resets_at'];
	}

	/**
	 * Cache only a valid, connection-bound snapshot. This does not enforce or spend quota.
	 *
	 * @param array<string,mixed> $data Existing API allowance payload.
	 */
	public static function save( array $data ): bool {
		if ( ! TokenStore::is_connected() || ! self::valid( $data ) ) {
			return false;
		}
		$clean = array_intersect_key( $data, array_flip( array( 'used', 'limit', 'remaining', 'resets_at' ) ) );
		update_option(
			self::OPTION,
			$clean + array(
				'at'      => time(),
				'context' => self::context(),
			),
			false
		);
		return true;
	}

	/**
	 * Last known balance, marked stale after five minutes or the reported reset.
	 *
	 * @return array{used:int,limit:int|null,remaining:int|null,resets_at:string,at:int,stale:bool}|null
	 */
	public static function snapshot(): ?array {
		$data = get_option( self::OPTION, array() );
		if ( ! TokenStore::is_connected() || ! is_array( $data ) || ( $data['context'] ?? '' ) !== self::context() || ! self::valid( $data ) || ! is_int( $data['at'] ?? null ) ) {
			return null;
		}
		return array(
			'used'      => $data['used'],
			'limit'     => $data['limit'],
			'remaining' => $data['remaining'],
			'resets_at' => $data['resets_at'],
			'at'        => $data['at'],
			'stale'     => $data['at'] > time() || time() - $data['at'] >= 5 * MINUTE_IN_SECONDS || strtotime( $data['resets_at'] ) <= time(),
		);
	}

	/** Explicit administrator refresh; no signatures, jobs, settings changes or credit reservations. */
	public static function refresh(): string {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			return 'forbidden';
		}
		if ( ! TokenStore::is_connected() ) {
			return 'disconnected';
		}
		$key = 'arvaseo_connector_css_quota_refresh_' . self::context();
		if ( false !== get_transient( $key ) ) {
			return 'wait';
		}
		set_transient( $key, 1, 10 );
		$response = ApiClient::instance()->site( 'GET', '/css-optimizations', null, 5 );
		if ( ! $response->ok() || ! is_array( $response->body['allowance'] ?? null ) || ! self::save( $response->body['allowance'] ) ) {
			return 'unavailable';
		}
		$rows = array();
		foreach ( array_slice( (array) ( $response->body['paused'] ?? array() ), 0, 20 ) as $row ) {
			if ( ! is_array( $row ) || ! is_string( $row['url'] ?? null ) || ! AssetMinifier::same_origin( $row['url'], home_url( '/' ) ) ) {
				continue;
			}
			$rows[] = array(
				'url'  => $row['url'],
				'file' => is_string( $row['file_name'] ?? null ) ? substr( $row['file_name'], 0, 160 ) : '',
			);
		}
		update_option(
			'arvaseo_connector_css_pauses',
			array(
				'context' => self::context(),
				'at'      => time(),
				'rows'    => $rows,
			),
			false
		);
		return 'updated';
	}

	/**
	 * Fresh server-reported churn pauses, without starting or retrying renders.
	 *
	 * @return list<array{url:string,file:string}>
	 */
	public static function paused_pages(): array {
		$saved = get_option( 'arvaseo_connector_css_pauses', array() );
		return TokenStore::is_connected() && is_array( $saved ) && ( $saved['context'] ?? '' ) === self::context() && ( $saved['at'] ?? 0 ) > time() - 300 && is_array( $saved['rows'] ?? null ) ? $saved['rows'] : array();
	}
}
