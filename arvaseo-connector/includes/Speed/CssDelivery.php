<?php
/**
 * Deliver CSS work after the response and retain a small, honest delivery status.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Speed;

use ArvaSEO\Connector\Auth\TokenStore;
use ArvaSEO\Connector\Api\ApiClient;
use ArvaSEO\Connector\Cron\CronManager;

defined( 'ABSPATH' ) || exit;

/** Readiness events are hints; all CSS is fetched over the authenticated site connection. */
final class CssDelivery {

	public const OPTION = 'arvaseo_connector_css_delivery';

	public const CURSOR = 'arvaseo_connector_css_updates_cursor';

	/** Disconnecting or changing the registered site must not reuse another connection's cursor. */
	public static function clear(): void {
		delete_option( self::OPTION );
		delete_option( self::CURSOR );
		CssPass::clear();
		remove_action( 'shutdown', array( self::class, 'after_response' ), 1000 );
	}

	/** A page or signed event needs CSS work, once this response is sent. */
	public static function send_soon(): void {
		if ( TokenStore::is_connected() && false === has_action( 'shutdown', array( self::class, 'after_response' ) ) ) {
			if ( ! wp_next_scheduled( CssOptimizer::HOOK ) ) {
				wp_schedule_single_event( time() + 120, CssOptimizer::HOOK );
			}
			add_action( 'shutdown', array( self::class, 'after_response' ), 1000 );
		}
	}

	/** The API never waits for this sync; hosts without finish_request get an immediately due cron job. */
	public static function after_response(): void {
		remove_action( 'shutdown', array( self::class, 'after_response' ), 1000 );
		if ( ! TokenStore::is_connected() ) {
			return;
		}
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			ignore_user_abort( true );
			fastcgi_finish_request();
		} elseif ( function_exists( 'litespeed_finish_request' ) ) {
			ignore_user_abort( true );
			litespeed_finish_request();
		} else {
			CronManager::run_soon( CssOptimizer::HOOK, 0 );
			return;
		}
		CssOptimizer::sync();
	}

	/**
	 * Remember only a successful authenticated API check and a boolean notification result.
	 *
	 * @param array<string,mixed> $answer API answer.
	 */
	public static function checked( array $answer ): void {
		$state               = self::status();
		$state['checked_at'] = time();
		if ( is_bool( $answer['notify_ok'] ?? null ) ) {
			$state['notify_ok'] = $answer['notify_ok'];
		}
		update_option( self::OPTION, $state, false );
	}

	/**
	 * Download metadata only; individual stylesheet results keep their existing bounded fetches.
	 *
	 * @return array{data:list<array<string,mixed>>,next:array{since:string,after:string|null},has_more:bool}|null
	 */
	public static function updates(): ?array {
		$cursor = get_option( self::CURSOR, array() );
		$query  = array(
			'since' => is_string( $cursor['since'] ?? null ) ? $cursor['since'] : '1970-01-01T00:00:00Z',
			'limit' => 50,
		);
		if ( is_string( $cursor['after'] ?? null ) ) {
			$query['after'] = $cursor['after'];
		}
		$response = ApiClient::instance()->site( 'GET', '/css-optimizations/updates?' . http_build_query( $query ), null, 10 );
		if ( ! $response->ok() ) {
			return null;
		}
		$body = $response->body;
		$next = $body['next'] ?? null;
		if ( ! is_array( $body['data'] ?? null ) || count( $body['data'] ) > 50 || ! is_bool( $body['has_more'] ?? null ) || ! is_array( $next ) || ! is_string( $next['since'] ?? null ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $next['since'] ) || ( null !== ( $next['after'] ?? null ) && ( ! is_string( $next['after'] ) || ! preg_match( '/^[0-9A-HJKMNP-TV-Z]{26}$/D', $next['after'] ) ) ) ) {
			return null;
		}
		$data = array();
		foreach ( $body['data'] as $row ) {
			if ( ! is_array( $row ) || 2 !== ( $row['format'] ?? null ) || ! is_string( $row['signature'] ?? null ) || ! preg_match( '/^[a-f0-9]{40,64}$/D', $row['signature'] ) ) {
				return null;
			}
			$data[] = $row;
		}
		self::checked( $body );
		return array(
			'data'     => $data,
			'next'     => array(
				'since' => $next['since'],
				'after' => $next['after'] ?? null,
			),
			'has_more' => $body['has_more'],
		);
	}

	/**
	 * Advance only after the index durably records every download still needed.
	 *
	 * @param array{since:string,after:string|null} $cursor Server cursor.
	 */
	public static function advance( array $cursor ): void {
		update_option( self::CURSOR, $cursor, false );
	}

	/**
	 * Local delivery diagnostics for the admin screen. Paused work is not blamed on cron.
	 *
	 * @return array{checked_at:int,notify_ok:bool|null,waiting_since:int,earlier:int}
	 */
	public static function diagnostics(): array {
		$state = self::status() + array(
			'waiting_since' => 0,
			'earlier'       => 0,
		);
		$index = CssOptimizer::index();
		foreach ( $index as $entry ) {
			if ( (int) ( $entry['format'] ?? 1 ) > 2 || ! in_array( $entry['status'] ?? '', array( 'needed', 'requested', 'refreshing', 'redownload' ), true ) ) {
				continue;
			}
			$at = (int) ( $entry['refresh_at'] ?? $entry['at'] ?? 0 );
			if ( $at > 0 ) {
				$state['waiting_since'] = $state['waiting_since'] > 0 ? min( $state['waiting_since'], $at ) : $at;
			}
			$previous = $entry['prev'] ?? null;
			if ( 'refreshing' === $entry['status'] || ( is_string( $previous ) && in_array( $index[ $previous ]['status'] ?? '', array( 'ready', 'refreshing', 'redownload' ), true ) ) ) {
				++$state['earlier'];
			}
		}
		return $state;
	}

	/**
	 * Missing evidence stays unknown instead of being reported as a successful notification.
	 *
	 * @return array{checked_at:int,notify_ok:bool|null}
	 */
	public static function status(): array {
		$state = get_option( self::OPTION, array() );
		return array(
			'checked_at' => max( 0, (int) ( $state['checked_at'] ?? 0 ) ),
			'notify_ok'  => is_bool( $state['notify_ok'] ?? null ) ? $state['notify_ok'] : null,
		);
	}
}
