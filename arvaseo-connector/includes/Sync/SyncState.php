<?php
/**
 * Bookkeeping for each kind of sync.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Sync;

use ArvaSEO\Connector\Api\ApiResponse;

defined( 'ABSPATH' ) || exit;

/**
 * Last result, consecutive failures and the next allowed attempt (exponential backoff with
 * jitter, honouring Retry-After) for inventory, health, polling and acknowledgements.
 */
final class SyncState {

	private const OPTION = 'arvaseo_connector_sync_state';

	/**
	 * State of one kind.
	 *
	 * @param string $kind inventory|health|poll|assessment.
	 * @return array<string, mixed>
	 */
	public static function get( string $kind ): array {
		$all = get_option( self::OPTION, array() );
		return is_array( $all ) && is_array( $all[ $kind ] ?? null ) ? $all[ $kind ] : array();
	}

	/**
	 * Every kind.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		$all = get_option( self::OPTION, array() );
		return is_array( $all ) ? $all : array();
	}

	/**
	 * Record a success.
	 *
	 * @param string               $kind Kind.
	 * @param array<string, mixed> $data Extra data (ids, hashes, counts).
	 */
	public static function success( string $kind, array $data = array() ): void {
		self::put(
			$kind,
			array_merge(
				$data,
				array(
					'status'               => 'uploaded',
					'at'                   => time(),
					'last_success_at'      => time(),
					'consecutive_failures' => 0,
					'next_attempt_at'      => 0,
					'error'                => null,
				)
			)
		);
	}

	/**
	 * Record a failure and when to try again.
	 *
	 * @param string      $kind     Kind.
	 * @param ApiResponse $response Response.
	 */
	public static function failure( string $kind, ApiResponse $response ): void {
		$previous = self::get( $kind );
		$failures = (int) ( $previous['consecutive_failures'] ?? 0 ) + 1;
		$delay    = $response->retry_after ?? min( HOUR_IN_SECONDS, 60 * ( 2 ** min( 6, $failures - 1 ) ) ) + wp_rand( 0, 30 );
		self::put(
			$kind,
			array_merge(
				$previous,
				array(
					'status'               => 'failed',
					'at'                   => time(),
					'consecutive_failures' => $failures,
					'next_attempt_at'      => $response->retryable() ? time() + $delay : time() + 6 * HOUR_IN_SECONDS,
					'error'                => array(
						'code'        => $response->error_code,
						'http_status' => $response->status,
						'message'     => $response->message,
					),
				)
			)
		);
	}

	/**
	 * Whether backoff allows an attempt now.
	 *
	 * @param string $kind Kind.
	 */
	public static function may_attempt( string $kind ): bool {
		return (int) ( self::get( $kind )['next_attempt_at'] ?? 0 ) <= time();
	}

	/**
	 * Merge data into one kind.
	 *
	 * @param string               $kind Kind.
	 * @param array<string, mixed> $data Data.
	 */
	public static function put( string $kind, array $data ): void {
		$all          = self::all();
		$all[ $kind ] = array_merge( is_array( $all[ $kind ] ?? null ) ? $all[ $kind ] : array(), $data );
		update_option( self::OPTION, $all, false );
	}

	/**
	 * Forget everything (disconnect, uninstall).
	 */
	public static function clear(): void {
		delete_option( self::OPTION );
	}
}
