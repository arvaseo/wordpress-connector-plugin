<?php
/**
 * Bounded observations of the stylesheets a completed HTML response actually used or kept.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Speed;

defined( 'ABSPATH' ) || exit;

/** These are saved page observations, not claims about every URL or a still-cached response. */
final class CssCoverage {
	/**
	 * Store only counts and public printed identifiers; no CSS or HTML bodies.
	 *
	 * @param string                            $signature Stylesheet set.
	 * @param int                               $matched   Replacements made.
	 * @param list<array{id:string,why:string}> $kept     Links kept unchanged.
	 * @param bool                              $earlier   An earlier result supplied replacements.
	 */
	public static function record( string $signature, int $matched, array $kept, bool $earlier ): void {
		$delivery = array(
			'matched'  => max( 0, $matched ),
			'kept'     => count( $kept ),
			'earlier'  => $earlier && $matched > 0,
			'examples' => array_slice( $kept, 0, 20 ),
		);
		// Most responses repeat the same observation: avoid an option lock on every visit.
		$saved = CssIndex::all()[ $signature ]['delivery'] ?? array();
		$at    = (int) ( $saved['at'] ?? 0 );
		unset( $saved['at'] );
		if ( $saved === $delivery && $at > time() - HOUR_IN_SECONDS ) {
			return;
		}
		CssIndex::patch(
			static function ( array $index ) use ( $signature, $delivery ): array {
				if ( ! isset( $index[ $signature ] ) || 2 !== ( $index[ $signature ]['format'] ?? 1 ) ) {
					return $index;
				}
				$previous = $index[ $signature ]['delivery'] ?? array();
				$at       = (int) ( $previous['at'] ?? 0 );
				unset( $previous['at'] );
				if ( $previous === $delivery && $at > time() - HOUR_IN_SECONDS ) {
					return $index;
				}
				$index[ $signature ]['delivery'] = $delivery + array( 'at' => time() );
				return $index;
			}
		);
	}

	/**
	 * The latest checked stylesheet set per page address; unknown stays unknown.
	 *
	 * @return array{kept_as_is:int|null,using_earlier_css:int|null,coverage_checked_at:int|null,page_types_checked:int}
	 */
	public static function summary(): array {
		$reports = self::reports( 300 );
		return array(
			'kept_as_is'          => $reports ? array_sum( array_column( $reports, 'kept' ) ) : null,
			'using_earlier_css'   => $reports ? count( array_filter( $reports, static fn ( array $row ): bool => $row['earlier'] ) ) : null,
			'coverage_checked_at' => $reports ? max( array_column( $reports, 'at' ) ) : null,
			'page_types_checked'  => count( $reports ),
		);
	}

	/**
	 * Saved local observations from the last seven days, with bounded reason examples.
	 *
	 * @param int $limit Maximum reports.
	 * @return list<array{url:string,at:int,matched:int,kept:int,earlier:bool,examples:list<array{id:string,why:string}>}>
	 */
	public static function reports( int $limit = 10 ): array {
		$index = array_reverse( CssOptimizer::index(), true );
		uasort( $index, static fn ( array $a, array $b ): int => (int) ( $b['delivery']['at'] ?? 0 ) <=> (int) ( $a['delivery']['at'] ?? 0 ) );
		$out  = array();
		$seen = array();
		foreach ( $index as $entry ) {
			$row = $entry['delivery'] ?? null;
			$url = (string) ( $entry['url'] ?? '' );
			if ( isset( $seen[ $url ] ) || 2 !== ( $entry['format'] ?? 1 ) || ! is_array( $row ) || (int) ( $row['at'] ?? 0 ) < time() - 7 * DAY_IN_SECONDS ) {
				continue;
			}
			$examples = array();
			foreach ( array_slice( (array) ( $row['examples'] ?? array() ), 0, 20 ) as $example ) {
				if ( is_array( $example ) && is_string( $example['id'] ?? null ) && preg_match( '/^[A-Za-z0-9_-]{1,200}$/D', $example['id'] ) && in_array( $example['why'] ?? '', array( 'awaiting_approval', 'incomplete_bundle', 'result_pending_or_changed' ), true ) ) {
					$examples[] = array(
						'id'  => $example['id'],
						'why' => $example['why'],
					);
				}
			}
			$out[]        = array(
				'url'      => $url,
				'at'       => (int) $row['at'],
				'matched'  => max( 0, (int) ( $row['matched'] ?? 0 ) ),
				'kept'     => max( 0, (int) ( $row['kept'] ?? 0 ) ),
				'earlier'  => true === ( $row['earlier'] ?? false ),
				'examples' => $examples,
			);
			$seen[ $url ] = true;
			if ( count( $out ) >= max( 1, min( 300, $limit ) ) ) {
				break;
			}
		}
		return $out;
	}
}
