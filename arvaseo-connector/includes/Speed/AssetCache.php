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
 * folder schedules one background clean-up: files no page used for a week go, then every saved
 * page is made again, so no saved page can point at a deleted file.
 *
 * That last step only holds for caches Arva SEO clears: its own, the host's (SiteGround, LiteSpeed,
 * WP Engine, Breeze), Cloudflare once connected, and cache plugins with a documented API to clear
 * everything (PageCache::can_clear()). Since 2.10.5 nothing is deleted while any other page cache
 * may keep pages: another cache plugin, an advanced-cache.php that isn't one of those, Kinsta's
 * or Cloudways' server cache, SiteGround's without its plugin, or Cloudflare without the
 * connection. A full folder then keeps the original addresses for new files, as before 2.10.3.
 */
final class AssetCache {

	public const HOOK = 'arvaseo_connector_asset_cache_rotate';

	/** When the last clean-up ran (not autoloaded). */
	public const LAST = 'arvaseo_connector_asset_cache_rotated';

	/** A reused file's modified time is moved forward at most this often. */
	private const TOUCH_EVERY = HOUR_IN_SECONDS;

	/** At most one clean-up in this time. */
	private const GAP = 6 * HOUR_IN_SECONDS;

	/** What kept the last clean-up from deleting anything (not autoloaded), so it's logged once. */
	public const KEPT = 'arvaseo_connector_asset_cache_kept';

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
	 * A generated file a page uses again: note when, at most hourly (or as often as asked).
	 *
	 * @param string $file  Generated file.
	 * @param int    $every Seconds between two notes.
	 */
	public static function used( string $file, int $every = self::TOUCH_EVERY ): void {
		$time = @filemtime( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a file removed meanwhile is handled.
		if ( false !== $time && $time < time() - $every && ! is_link( $file ) ) {
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
	 * default) and the files it uses were touched when it was made, an hour at most before. A week
	 * at least, for caches in front of the site that nothing here can see.
	 */
	public static function unused_after(): int {
		return max( WEEK_IN_SECONDS, PageCache::ttl() + 2 * self::TOUCH_EVERY );
	}

	/**
	 * A cache that may keep saved pages using generated files, and that Arva SEO can't clear when
	 * it deletes them: its name, or null when there's none.
	 */
	public static function cleanup_blocker(): ?string {
		$active  = Fixes::active_plugin_slugs();
		$known   = array_merge( PageCache::CACHE_PLUGINS, PageCache::HOST_PLUGINS );
		$blocker = null;
		foreach ( $known as $slug ) {
			if ( in_array( $slug, $active, true ) && ! PageCache::can_clear( $slug ) ) {
				$blocker = Fixes::plugin_names()[ $slug ] ?? $slug;
				break;
			}
		}
		$host = PageCache::host_cache();
		if ( null === $blocker && null !== $host && ( 'kinsta' === $host['id'] || ( 'cloudways' === $host['id'] && ! PageCache::can_clear( 'breeze' ) ) ) ) {
			$blocker = $host['name'];
		}
		if ( null === $blocker ) {
			$blocker = PageCache::uncleared_host_cache();
		}
		if ( null === $blocker && defined( 'WP_CACHE' ) && WP_CACHE && file_exists( PageCache::dropin() ) && ! PageCache::dropin_is_ours() && array() === array_intersect( $known, $active ) ) {
			$blocker = __( 'another page cache (wp-content/advanced-cache.php)', 'arvaseo-connector' );
		}
		if ( null === $blocker && Cloudflare::seen() && ! Cloudflare::connected() ) {
			$blocker = __( 'Cloudflare (not connected to Arva SEO)', 'arvaseo-connector' );
		}
		/**
		 * What keeps the generated-file clean-up from deleting minified and combined files: a
		 * cache that may still serve saved pages using them and that Arva SEO can't clear. Return
		 * null when the host's cache is cleared some other way, or a name to keep the files.
		 *
		 * @param string|null $blocker The cache's name, or null.
		 */
		$blocker = apply_filters( 'arvaseo_connector_asset_cleanup_blocker', $blocker );
		return is_string( $blocker ) && '' !== $blocker ? $blocker : null;
	}

	/**
	 * The clean-up: delete minified copies and bundles no page used lately, then have every saved
	 * page made again (this plugin's page cache, the host's, Cloudflare's and the cache plugins'
	 * Arva SEO can clear). Nothing is deleted while another cache may keep pages.
	 *
	 * @return int Files deleted.
	 */
	public static function rotate(): int {
		update_option( self::LAST, time(), false );
		$blocker = self::cleanup_blocker();
		if ( null !== $blocker ) {
			if ( get_option( self::KEPT ) !== $blocker ) {
				update_option( self::KEPT, $blocker, false );
				AuditLogger::info(
					'speed.assets_kept',
					/* translators: %s: a page cache, such as WP Fastest Cache. */
					sprintf( __( 'PageSpeed fixes: unused minified and combined files were kept, because %s may still have saved pages that use them and Arva SEO can’t clear it. Until there’s room, new files keep their original addresses.', 'arvaseo-connector' ), $blocker ),
					array( 'cache' => $blocker )
				);
			}
			return 0;
		}
		delete_option( self::KEPT );
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
