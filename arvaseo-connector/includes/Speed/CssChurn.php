<?php
/**
 * A bounded, local allowance guard for pages whose stylesheet signatures keep changing.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Speed;

use ArvaSEO\Connector\Support\Lock;

defined( 'ABSPATH' ) || exit;

/** History survives index compaction, without storing query strings or stylesheet contents. */
final class CssChurn {

	public const OPTION = 'arvaseo_connector_css_churn';

	/**
	 * Admit at most three new signatures for one page and template in a rolling day.
	 * A busy or failed storage operation never authorizes unchecked rendering.
	 *
	 * @param string                    $url       Public page URL.
	 * @param string                    $template  Page type.
	 * @param string                    $signature Signature.
	 * @param list<array<string,mixed>> $sources   Public source identities.
	 * @param int|null                  $now       Clock, injectable in tests.
	 * @return array{allowed:bool,file:string,retry_at:int}|null
	 */
	public static function check( string $url, string $template, string $signature, array $sources, ?int $now = null ): ?array {
		$now = $now ?? time();
		return Lock::with(
			'css-churn',
			10,
			static function () use ( $url, $template, $signature, $sources, $now ): ?array {
				wp_cache_delete( self::OPTION, 'options' );
				wp_cache_delete( 'notoptions', 'options' );
				$before  = get_option( self::OPTION, array() );
				$history = is_array( $before ) ? $before : array();
				$history = array_filter( $history, static fn ( $row ): bool => is_array( $row ) && (int) ( $row['at'] ?? 0 ) > $now - DAY_IN_SECONDS );
				$page    = hash( 'sha256', Addresses::page_url( $url ) . '|' . $template );
				$entry   = $history[ $page ] ?? array();
				$seen    = array_filter( (array) ( $entry['signatures'] ?? array() ), static fn ( $at ): bool => (int) $at > $now - DAY_IN_SECONDS );
				$allowed = isset( $seen[ $signature ] ) || count( $seen ) < 3;
				$file    = '';
				$current = array();
				foreach ( array_slice( $sources, 0, 200 ) as $source ) {
					$id             = (string) ( $source['id'] ?? '' );
					$path           = (string) wp_parse_url( (string) ( $source['href'] ?? '' ), PHP_URL_PATH );
					$key            = hash( 'sha256', (string) ( $source['key'] ?? '' ) . '|' . (string) ( $source['media'] ?? '' ) );
					$current[ $id ] = $key;
					if ( '' === $file && ( $entry['sources'][ $id ] ?? $key ) !== $key ) {
						$file = substr( sanitize_text_field( $path ), 0, 300 );
					}
				}
				if ( $allowed ) {
					$seen[ $signature ] = $seen[ $signature ] ?? $now;
					$history[ $page ]   = array(
						'at'         => $now,
						'signatures' => $seen,
						'sources'    => $current,
					);
				}
				uasort( $history, static fn ( array $a, array $b ): int => $b['at'] <=> $a['at'] );
				$history = array_slice( $history, 0, 50, true );
				if ( $history !== $before && ! update_option( self::OPTION, $history, false ) ) {
					return null;
				}
				return array(
					'allowed'  => $allowed,
					'file'     => $file,
					'retry_at' => $allowed ? 0 : (int) min( $seen ) + DAY_IN_SECONDS,
				);
			}
		);
	}
}
