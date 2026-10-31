<?php
/**
 * Bounded CSS metadata with short, serialized writes. Generated files have a separate lifetime.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Speed;

use ArvaSEO\Connector\Support\Lock;

defined( 'ABSPATH' ) || exit;

/** Never hold the index lock during an HTTP request or while writing CSS files. */
final class CssIndex {

	private const LIMIT = 300;

	private const MAX_BYTES = 67108864;

	/**
	 * Read the current index without changing its autoload setting.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		$index = get_option( CssOptimizer::INDEX, array() );
		return is_array( $index ) ? array_filter( $index, 'is_array' ) : array();
	}

	/**
	 * Apply a small metadata change to the latest stored value, not a pre-request snapshot.
	 *
	 * @param callable $change Metadata operation.
	 * @phpstan-param callable(array<string, array<string, mixed>>): array<string, array<string, mixed>> $change
	 */
	public static function patch( callable $change ): bool {
		$result = Lock::with(
			'css-index',
			10,
			static function () use ( $change ): bool {
				// Another PHP worker may have changed this non-autoloaded option since this request began.
				wp_cache_delete( CssOptimizer::INDEX, 'options' );
				wp_cache_delete( 'notoptions', 'options' );
				$before = self::all();
				$after  = $change( $before );
				if ( $before === $after ) {
					return true;
				}
				return update_option( CssOptimizer::INDEX, $after, false );
			}
		);
		return true === $result;
	}

	/**
	 * Keep the newest result and pending request per page and format, up to 300 entries.
	 * This only removes metadata: cached pages can still need the generated files.
	 */
	public static function compact(): int {
		$removed = 0;
		$saved   = self::patch(
			static function ( array $index ) use ( &$removed ): array {
				$ordered = $index;
				uasort( $ordered, static fn ( array $a, array $b ): int => self::seen( $b ) <=> self::seen( $a ) );
				$keep  = array();
				$pages = array();
				$bytes = 0;
				$root  = wp_get_upload_dir()['basedir'] . '/' . CssOptimizer::FOLDER;
				foreach ( $ordered as $signature => $entry ) {
					if ( (int) ( $entry['format'] ?? 1 ) > 2 ) {
						$keep[ $signature ] = true; // A rollback must not interpret a later format.
						continue;
					}
					$ready = in_array( $entry['status'] ?? '', array( 'ready', 'refreshing', 'redownload' ), true );
					if ( ! $ready && self::seen( $entry ) < time() - 7 * DAY_IN_SECONDS ) {
						continue;
					}
					$page   = (string) ( $entry['template'] ?? '' ) . '|' . Addresses::page_url( (string) ( $entry['url'] ?? '' ) );
					$bucket = $page . '|' . (string) ( $entry['format'] ?? 1 ) . '|' . ( $ready ? 'ready' : 'waiting' );
					if ( isset( $pages[ $bucket ] ) || count( $keep ) >= self::LIMIT ) {
						continue;
					}
					$size = strlen( (string) wp_json_encode( $entry ) );
					$file = $root . '/' . $signature . '.json';
					if ( preg_match( '/^[a-f0-9]{40,64}$/D', (string) $signature ) && ! is_link( $root ) && ! is_link( $file ) && is_file( $file ) ) {
						$size += (int) filesize( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_filesize -- track own result sizes without reading CSS.
					}
					if ( $bytes + $size > self::MAX_BYTES ) {
						continue; // Only metadata goes; result files retain their separate grace period.
					}
					$bytes             += $size;
					$pages[ $bucket ]   = true;
					$keep[ $signature ] = true;
				}
				// Preserve stable order for callers, while eviction follows last use rather than insertion.
				$after   = array_intersect_key( $index, $keep );
				$removed = count( $index ) - count( $after );
				return $after;
			}
		);
		return $saved ? $removed : 0;
	}

	/**
	 * Old entries use at until a real page visit records seen.
	 *
	 * @param array<string, mixed> $entry Entry.
	 */
	private static function seen( array $entry ): int {
		return (int) ( $entry['seen'] ?? $entry['at'] ?? 0 );
	}
}
