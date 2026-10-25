<?php
/**
 * The generated asset folders (minified copies and combined bundles): when each file was last
 * used, and room made again when a folder is full.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Speed;

use ArvaSEO\Connector\Logging\AuditLogger;

defined( 'ABSPATH' ) || exit;

/**
 * New copies stop at each folder's limits (250 files / 32 MiB) and existing ones are never
 * evicted while a page is being made, because saved pages may still use them. Until 2.10.3
 * nothing ever made room again: once a folder was full (in hours, on a theme that versions its
 * files with the time), every changed file kept its original, unminified and uncombined address
 * for good. Now a reused file records its use (its modified time, at most hourly), and a full
 * folder schedules one background clean-up: files no page used for a day go, then every saved
 * page is made again, so no saved page can point at a deleted file.
 */
final class AssetCache {

	public const HOOK = 'arvaseo_connector_asset_cache_rotate';

	/** When the last clean-up ran (not autoloaded). */
	public const LAST = 'arvaseo_connector_asset_cache_rotated';

	/** A reused file's modified time is moved forward at most this often. */
	private const TOUCH_EVERY = HOUR_IN_SECONDS;

	/** At most one clean-up in this time. */
	private const GAP = 6 * HOUR_IN_SECONDS;

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( self::HOOK, array( self::class, 'scheduled' ) );
	}

	/**
	 * The scheduled clean-up.
	 */
	public static function scheduled(): void {
		self::rotate();
	}

	/**
	 * A generated file a page uses again: note when, at most hourly.
	 *
	 * @param string $file Generated file.
	 */
	public static function used( string $file ): void {
		$time = @filemtime( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a file removed meanwhile is handled.
		if ( false !== $time && $time < time() - self::TOUCH_EVERY && ! is_link( $file ) ) {
			@touch( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_touch -- last-use time of our own generated file.
		}
	}

	/**
	 * A folder had no room for a new file: clean up in the background, at most every six hours.
	 */
	public static function full(): void {
		if ( false !== wp_next_scheduled( self::HOOK ) ) {
			return;
		}
		$last = (int) get_option( self::LAST, 0 );
		wp_schedule_single_event( max( time() + MINUTE_IN_SECONDS, $last + self::GAP ), self::HOOK );
	}

	/**
	 * Files unused this long aren't in any saved page: a page lives PageCache::ttl() (10 hours by
	 * default) and the files it uses were touched when it was made, an hour at most before.
	 */
	public static function unused_after(): int {
		return max( DAY_IN_SECONDS, PageCache::ttl() + 2 * self::TOUCH_EVERY );
	}

	/**
	 * The clean-up: delete minified copies and bundles no page used lately, then have every saved
	 * page made again (this plugin's page cache, the host's and Cloudflare's).
	 *
	 * @return int Files deleted.
	 */
	public static function rotate(): int {
		update_option( self::LAST, time(), false );
		$before  = time() - self::unused_after();
		$removed = AssetMinifier::remove_unused( $before ) + AssetBundles::remove_unused( $before );
		if ( $removed > 0 ) {
			Fixes::pages_changed( 'assets' );
			AuditLogger::info(
				'speed.assets_cleaned',
				/* translators: %d: number of files. */
				sprintf( _n( 'PageSpeed fixes: %d unused minified or combined file was deleted to make room.', 'PageSpeed fixes: %d unused minified or combined files were deleted to make room.', $removed, 'arvaseo-connector' ), $removed )
			);
		}
		return $removed;
	}

	/**
	 * Delete a generated folder's .css and .js files last used before a time. Only plain files
	 * directly inside it: never links, never subfolders.
	 *
	 * @param string $dir    The folder (already checked to be inside uploads).
	 * @param int    $before Last use before this time.
	 * @return int Files deleted.
	 */
	public static function remove_unused( string $dir, int $before ): int {
		if ( is_link( $dir ) || ! is_dir( $dir ) ) {
			return 0;
		}
		$removed = 0;
		clearstatcache();
		foreach ( array_merge( glob( $dir . '/*.css' ) ?: array(), glob( $dir . '/*.js' ) ?: array() ) as $file ) {
			if ( is_link( $file ) || ! is_file( $file ) ) {
				continue;
			}
			$time = filemtime( $file );
			if ( false !== $time && $time < $before ) {
				wp_delete_file( $file );
				clearstatcache( true, $file );
				$removed += file_exists( $file ) ? 0 : 1;
			}
		}
		return $removed;
	}
}
