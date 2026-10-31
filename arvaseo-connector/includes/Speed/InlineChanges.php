<?php
/**
 * Local, bounded observation of changing public inline snippets during an administrator's preview.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Speed;

use ArvaSEO\Connector\Support\Lock;
use ArvaSEO\Connector\Install\Capabilities;

defined( 'ABSPATH' ) || exit;

/** Page-scoped fingerprints only; no snippet body, query string or visitor information is retained. */
final class InlineChanges {
	public const OPTION = 'arvaseo_connector_inline_changes';

	/**
	 * Record at most three distinct hashes per page, ID and kind in a rolling day.
	 *
	 * @param string   $id   Printed identifier.
	 * @param string   $kind CSS or JS.
	 * @param string   $hash Exact-body checksum.
	 * @param int|null $now  Test clock.
	 */
	public static function observe( string $id, string $kind, string $hash, ?int $now = null ): void {
		if ( ! current_user_can( Capabilities::MANAGE ) || ! in_array( $kind, array( 'css', 'js' ), true ) || ! preg_match( '/^[A-Za-z][A-Za-z0-9_:.-]{0,127}$/D', $id ) || ! preg_match( '/^[a-f0-9]{64}$/D', $hash ) ) {
			return;
		}
		$now   = $now ?? time();
		$scope = self::scope( $id, $kind );
		Lock::with(
			'inline-changes',
			10,
			static function () use ( $id, $kind, $hash, $now, $scope ): void {
				wp_cache_delete( self::OPTION, 'options' );
				$history = get_option( self::OPTION, array() );
				$history = is_array( $history ) ? $history : array();
				$history = array_filter( $history, static fn ( $entry ): bool => is_array( $entry ) && (int) ( $entry['at'] ?? 0 ) > $now - DAY_IN_SECONDS );
				$samples = array_filter( (array) ( $history[ $scope ]['samples'] ?? array() ), static fn ( $at ): bool => (int) $at > $now - DAY_IN_SECONDS );
				if ( count( $samples ) < 3 ) {
					$samples[ $hash ] = $samples[ $hash ] ?? $now;
				}
				$history[ $scope ] = array(
					'id'      => $id,
					'kind'    => $kind,
					'at'      => $now,
					'samples' => $samples,
				);
				uasort( $history, static fn ( array $a, array $b ): int => $b['at'] <=> $a['at'] );
				update_option( self::OPTION, array_slice( $history, 0, 100, true ), false );
			}
		);
	}

	/**
	 * This page only: a different page can legitimately print another static snippet under that ID.
	 *
	 * @param string $id   Printed identifier.
	 * @param string $kind CSS or JS.
	 */
	public static function changing( string $id, string $kind ): bool {
		$history = get_option( self::OPTION, array() );
		return self::unstable( is_array( $history ) ? (array) ( $history[ self::scope( $id, $kind ) ] ?? array() ) : array() );
	}

	/**
	 * Admin warnings across captured pages, without exposing paths or snippet contents.
	 *
	 * @param string $kind CSS or JS.
	 * @return string[] Printed IDs.
	 */
	public static function warnings( string $kind ): array {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) get_option( self::OPTION, array() ) as $entry ) {
			if ( is_array( $entry ) && ( $entry['kind'] ?? '' ) === $kind && is_string( $entry['id'] ?? null ) && self::unstable( $entry ) ) {
				$out[] = $entry['id'];
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Whether three recent versions were observed.
	 *
	 * @param array<string,mixed> $entry Stored observations.
	 */
	private static function unstable( array $entry ): bool {
		return count( array_filter( (array) ( $entry['samples'] ?? array() ), static fn ( $at ): bool => (int) $at > time() - DAY_IN_SECONDS ) ) >= 3;
	}

	/**
	 * Keep variation counts separate for each page and asset.
	 *
	 * @param string $id   Printed identifier.
	 * @param string $kind CSS or JS.
	 */
	private static function scope( string $id, string $kind ): string {
		return hash( 'sha256', Exclusions::current_path() . '|' . $kind . '|' . $id );
	}
}
