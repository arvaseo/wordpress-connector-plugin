<?php
/**
 * The generated asset folders (minified copies, combined bundles, optimized CSS copies and Google
 * Fonts copies): when each file was last used, when it may go, and room made again when a folder
 * is full.
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
 * folder schedules one background clean-up.
 *
 * The grace rule (2.11.0), because a saved page in a cache can point at a file for as long as the
 * cache keeps the page:
 * - A file goes only after it has gone unused for the longest of 7 days, the page cache's
 *   lifetime plus 2 hours, and the longest lifetime the homepage's headers showed plus a day.
 *   Google Fonts copies keep their 30 days.
 * - Clear first, delete later: the clean-up marks the moment, clears every cache Arva SEO can
 *   reach, and deletes 24 hours later only files last used before the mark, and never one the live
 *   homepage links to. That ends the old gap between deleting a file and clearing the pages.
 * - While a cache Arva SEO can't clear may keep pages (OtherCaches::blockers()), only files unused
 *   for 90 days go, and the folders may grow to twice their limits. Pressing "I've cleared it" for
 *   that cache lets the files it was the reason for go, an hour later.
 * - The clear is worth it only when the deletion will happen. Nothing is cleared unless the live
 *   homepage can be read now (or was looked at in the last two days), because the deletion needs
 *   it; a homepage that can't be read is tried again after a day, and after a deletion gave up on
 *   it, after a week. The daily clean-up clears the caches only when at least 20 files can go or a
 *   folder is four fifths full, and at most once a week (a full folder: once a day). Files the
 *   homepage links to never count.
 */
final class AssetCache {

	public const HOOK = 'arvaseo_connector_asset_cache_rotate';

	/** The deletion that comes a day after the clear (or an hour after "I've cleared it"). */
	public const HOOK_SWEEP = 'arvaseo_connector_asset_cache_sweep';

	/** The mark, what is kept and when files were last deleted (not autoloaded). */
	public const STATE = 'arvaseo_connector_asset_cache_state';

	/** Files are deleted this long after the clear. */
	public const GRACE = DAY_IN_SECONDS;

	/** A cache Arva SEO can't clear may keep pages: only files unused this long go. */
	public const BLOCKED_AFTER = 90 * DAY_IN_SECONDS;

	/** A file's last use is only recorded this closely (a day for Google Fonts copies). */
	private const SLACK = DAY_IN_SECONDS;

	/** The daily clean-up clears every cache only for at least this many files to go... */
	public const WORTH = 20;

	/** ...or when a folder holds this share of the files or bytes it may. */
	private const CROWDED = 0.8;

	/** What a folder may hold before any doubling (AssetMinifier, AssetBundles; optimized CSS copies may hold a few more). */
	private const FOLDER_FILES = 250;

	private const FOLDER_BYTES = 33554432;

	/** The daily clean-up clears every cache at most this often. */
	private const MARK_EVERY = WEEK_IN_SECONDS;

	/** A clean-up asked for by a full folder, at most this often. */
	private const MARK_EVERY_FULL = DAY_IN_SECONDS;

	/** A homepage that can't be read is looked at again after this long. */
	private const UNREADABLE_WAIT = DAY_IN_SECONDS;

	/** After a deletion gave up on an unreadable homepage, nothing is cleared again for this long. */
	private const GAVE_UP_WAIT = WEEK_IN_SECONDS;

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
		add_action( self::HOOK_SWEEP, array( self::class, 'scheduled_sweep' ) );
	}

	/**
	 * The scheduled clean-up (a folder had no room).
	 */
	public static function scheduled(): void {
		self::rotate();
	}

	/**
	 * The scheduled deletion, a day after the caches were cleared.
	 */
	public static function scheduled_sweep(): void {
		self::sweep();
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
	 * at least, for caches in front of the site that nothing here can see, and a day more than the
	 * longest time the homepage's headers said a cache may keep a page.
	 */
	public static function unused_after(): int {
		return max( WEEK_IN_SECONDS, PageCache::ttl() + 2 * self::TOUCH_EVERY, OtherCaches::longest_lifetime() + DAY_IN_SECONDS );
	}

	/**
	 * The caches that may keep saved pages using generated files and that Arva SEO can't clear:
	 * the ones found (OtherCaches::blockers()), or the name the developer filter gives.
	 *
	 * @return list<array{id: string, name: string}>
	 */
	public static function blockers(): array {
		$found = array();
		foreach ( OtherCaches::blockers() as $entry ) {
			$found[] = array(
				'id'   => $entry['id'],
				'name' => $entry['name'],
			);
		}
		$names = implode( ', ', array_column( $found, 'name' ) );
		/**
		 * What keeps the generated-file clean-up from deleting minified and combined files: a
		 * cache that may still serve saved pages using them and that Arva SEO can't clear. Return
		 * null when the host's cache is cleared some other way, or a name to keep the files.
		 *
		 * @param string|null $blocker The cache's name (several are joined with commas), or null.
		 */
		$blocker = apply_filters( 'arvaseo_connector_asset_cleanup_blocker', '' === $names ? null : $names );
		if ( ! is_string( $blocker ) || '' === $blocker ) {
			return array();
		}
		return $blocker === $names ? $found : array(
			array(
				'id'   => 'other',
				'name' => $blocker,
			),
		);
	}

	/**
	 * A cache that may keep saved pages using generated files, and that Arva SEO can't clear when
	 * it deletes them: its name, or null when there's none. Since 2.11.0 this doesn't stop the
	 * clean-up: files are kept 90 days instead of a week, until it is cleared by hand.
	 */
	public static function cleanup_blocker(): ?string {
		$blockers = self::blockers();
		return array() === $blockers ? null : implode( ', ', array_column( $blockers, 'name' ) );
	}

	/**
	 * A folder's limit: twice as much while a cache Arva SEO can't clear may keep pages.
	 *
	 * @param int $limit Files or bytes.
	 */
	public static function limit( int $limit ): int {
		return array() === self::blockers() ? $limit : 2 * $limit;
	}

	/**
	 * Files last used before this time may be deleted: unused for $normal, and, for every cache
	 * that may keep pages and can't be cleared, unused for 90 days, or last used before the
	 * administrator said they cleared it.
	 *
	 * @param int      $normal Seconds a file must have been unused.
	 * @param int|null $now    Now (tests).
	 */
	public static function unused_before( int $normal, ?int $now = null ): int {
		$now    = $now ?? time();
		$before = $now - $normal;
		foreach ( self::blockers() as $blocker ) {
			$limit   = $now - self::BLOCKED_AFTER;
			$handled = OtherCaches::handled_at( $blocker['id'] );
			if ( null !== $handled ) {
				$limit = max( $limit, $handled - self::SLACK );
			}
			$before = min( $before, $limit );
		}
		return $before;
	}

	/**
	 * What the folders hold that is old enough to go, what is kept only because of a cache that
	 * can't be cleared, and whether a folder is nearly full.
	 *
	 * @param string[]|null $linked File names the live homepage links to: they never go, so they
	 *                              aren't counted. Null: count everything.
	 * @return array{go: int, kept_files: int, kept_bytes: int, crowded: bool}
	 */
	private static function scan( ?array $linked = null ): array {
		$out   = array(
			'go'         => 0,
			'kept_files' => 0,
			'kept_bytes' => 0,
			'crowded'    => false,
		);
		$rules = array(
			array( array( AssetMinifier::directory(), AssetBundles::directory(), CssOptimizer::used_directory() ), self::unused_after(), array( '*.css', '*.js' ), true ),
			array( array( LocalFonts::directory() ), LocalFonts::UNUSED_AFTER, array( '*.css' ), false ),
		);
		foreach ( $rules as list( $dirs, $normal, $patterns, $limited ) ) {
			$ready   = time() - $normal;
			$allowed = self::unused_before( $normal );
			foreach ( $dirs as $dir ) {
				if ( is_link( $dir ) || ! is_dir( $dir ) ) {
					continue;
				}
				$files = array();
				foreach ( $patterns as $pattern ) {
					$files = array_merge( $files, glob( $dir . '/' . $pattern ) ?: array() );
				}
				$held = 0;
				foreach ( $files as $file ) {
					$time = is_link( $file ) || ! is_file( $file ) ? false : @filemtime( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a file removed meanwhile is skipped.
					if ( false === $time ) {
						continue;
					}
					$size  = (int) @filesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_filesize -- our own generated file.
					$held += $size;
					if ( null !== $linked && in_array( basename( $file ), $linked, true ) ) {
						continue;
					}
					if ( $time < $allowed ) {
						++$out['go'];
					} elseif ( $time < $ready ) {
						++$out['kept_files'];
						$out['kept_bytes'] += $size;
					}
				}
				if ( $limited && ( count( $files ) >= self::CROWDED * self::FOLDER_FILES || $held >= self::CROWDED * self::FOLDER_BYTES ) ) {
					$out['crowded'] = true;
				}
			}
		}
		return $out;
	}

	/**
	 * What's stored.
	 *
	 * @return array{mark: int|null, marked: int|null, retry_after: int|null, tries: int, kept: array{files: int, bytes: int, since: int, names: string}|null, swept: array{at: int, files: int}|null}
	 */
	public static function state(): array {
		$saved = get_option( self::STATE, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$kept  = is_array( $saved['kept'] ?? null ) ? $saved['kept'] : null;
		$swept = is_array( $saved['swept'] ?? null ) ? $saved['swept'] : null;
		return array(
			'mark'        => is_int( $saved['mark'] ?? null ) ? $saved['mark'] : null,
			'marked'      => is_int( $saved['marked'] ?? null ) ? $saved['marked'] : null,
			'retry_after' => is_int( $saved['retry_after'] ?? null ) ? $saved['retry_after'] : null,
			'tries'       => (int) ( $saved['tries'] ?? 0 ),
			'kept'        => null === $kept ? null : array(
				'files' => (int) ( $kept['files'] ?? 0 ),
				'bytes' => (int) ( $kept['bytes'] ?? 0 ),
				'since' => (int) ( $kept['since'] ?? 0 ),
				'names' => (string) ( $kept['names'] ?? '' ),
			),
			'swept'       => null === $swept ? null : array(
				'at'    => (int) ( $swept['at'] ?? 0 ),
				'files' => (int) ( $swept['files'] ?? 0 ),
			),
		);
	}

	/**
	 * Change some of what's stored.
	 *
	 * @param array<string, mixed> $changes Keys of state().
	 */
	private static function save_state( array $changes ): void {
		update_option( self::STATE, array_merge( self::state(), $changes ), false );
	}

	/**
	 * Old files kept only because a cache can't be cleared: how many, how big, since when, and
	 * the caches. Null when nothing is kept for that.
	 *
	 * @return array{files: int, bytes: int, since: int, names: string}|null
	 */
	public static function kept(): ?array {
		return self::state()['kept'];
	}

	/**
	 * The clean-up (a full folder, or the daily maintenance): when files are old enough to go and
	 * the deletion can happen, clear every cache Arva SEO can reach now, and delete them a day
	 * later. A clean-up that finds a clear waiting for its day does the deletion once it is due.
	 *
	 * @param bool $daily The daily maintenance: it clears the caches only when it is worth it
	 *                    (WORTH files, or a nearly full folder) and at most once a week.
	 * @return int Files deleted by this run.
	 */
	public static function rotate( bool $daily = false ): int {
		update_option( self::LAST, time(), false );
		$state = self::state();
		if ( null !== $state['mark'] ) {
			return $state['mark'] <= time() - self::GRACE ? self::sweep() : 0;
		}
		$scan = self::scan();
		self::remember_kept( $scan );
		if ( $scan['go'] > 0 ) {
			self::begin( $daily ? 'daily' : 'full', self::GRACE );
		}
		return 0;
	}

	/**
	 * Mark (clear every cache, schedule the deletion) when the deletion can happen and the clear is
	 * worth it. A clear that no deletion follows would empty every cache again and again for nothing.
	 *
	 * @param string $why   daily (the daily maintenance), full (a folder had no room) or asked (someone said they cleared a cache).
	 * @param int    $after Seconds until the deletion.
	 */
	private static function begin( string $why, int $after ): void {
		$now   = time();
		$state = self::state();
		if ( 'asked' !== $why ) {
			if ( null !== $state['retry_after'] && $state['retry_after'] > $now ) {
				return;
			}
			$every = 'daily' === $why ? self::MARK_EVERY : self::MARK_EVERY_FULL;
			if ( null !== $state['marked'] && $state['marked'] > $now - $every ) {
				return;
			}
		}
		$linked = self::linked_files();
		if ( null === $linked ) {
			// The deletion would give up on a homepage it can't read: no clear for it.
			self::save_state( array( 'retry_after' => $now + self::UNREADABLE_WAIT ) );
			return;
		}
		$scan = self::scan( $linked );
		if ( 0 === $scan['go'] || ( 'daily' === $why && $scan['go'] < self::WORTH && ! $scan['crowded'] ) ) {
			return;
		}
		self::mark( $after );
	}

	/**
	 * The generated files the live homepage links to; where it can't be read, the last daily look
	 * at it (two days old at most) stands in. Null when neither is there.
	 *
	 * @return string[]|null
	 */
	private static function linked_files(): ?array {
		$linked = OtherCaches::homepage_files();
		if ( null !== $linked ) {
			return $linked;
		}
		$header = OtherCaches::state()['header'];
		if ( null === $header['at'] || $header['at'] <= time() - 2 * DAY_IN_SECONDS ) {
			return null;
		}
		$linked = $header['linked'];
		foreach ( $header['linked'] as $name ) {
			$linked[] = (string) preg_replace( '/\.css$/', '', $name );
		}
		return $linked;
	}

	/**
	 * Remember (and log, once) what is kept because of a cache Arva SEO can't clear.
	 *
	 * @param array{go: int, kept_files: int, kept_bytes: int, crowded: bool} $scan What scan() found.
	 */
	private static function remember_kept( array $scan ): void {
		$blockers = self::cleanup_blocker();
		if ( null === $blockers || 0 === $scan['kept_files'] ) {
			delete_option( self::KEPT );
			self::save_state( array( 'kept' => null ) );
			return;
		}
		$kept = self::state()['kept'];
		self::save_state(
			array(
				'kept' => array(
					'files' => $scan['kept_files'],
					'bytes' => $scan['kept_bytes'],
					'since' => null !== $kept && $kept['names'] === $blockers ? $kept['since'] : time(),
					'names' => $blockers,
				),
			)
		);
		if ( get_option( self::KEPT ) !== $blockers ) {
			update_option( self::KEPT, $blockers, false );
			AuditLogger::info(
				'speed.assets_kept',
				/* translators: 1: number of files, 2: caches such as WP Fastest Cache. */
				sprintf( _n( 'PageSpeed fixes: %1$d old generated file is kept, because %2$s may still have saved pages that use it and Arva SEO can’t clear it. Files unused for 90 days are deleted. After you clear it, press “I’ve cleared it” on the Speed page.', 'PageSpeed fixes: %1$d old generated files are kept, because %2$s may still have saved pages that use them and Arva SEO can’t clear it. Files unused for 90 days are deleted. After you clear it, press “I’ve cleared it” on the Speed page.', $scan['kept_files'], 'arvaseo-connector' ), $scan['kept_files'], $blockers ),
				array(
					'cache' => $blockers,
					'files' => $scan['kept_files'],
				)
			);
		}
	}

	/**
	 * Clear first: every cache Arva SEO can reach is cleared now, and the deletion is scheduled.
	 *
	 * @param int $after Seconds until the deletion.
	 */
	private static function mark( int $after ): void {
		self::save_state(
			array(
				'mark'        => time(),
				'marked'      => time(),
				'retry_after' => null,
				'tries'       => 0,
			)
		);
		wp_clear_scheduled_hook( self::HOOK_SWEEP );
		wp_schedule_single_event( time() + $after, self::HOOK_SWEEP );
		Fixes::pages_changed( 'mark' );
		AuditLogger::info(
			'speed.assets_marked',
			__( 'PageSpeed fixes: old minified, combined and optimized CSS files will be deleted after the caches that may still use them were cleared.', 'arvaseo-connector' )
		);
	}

	/**
	 * Someone cleared a cache Arva SEO can't reach and said so: the files kept for it can go in an
	 * hour, after everything else Arva SEO can clear is cleared again.
	 */
	public static function cleared_by_hand(): void {
		$state = self::state();
		if ( null !== $state['mark'] ) {
			// A clear is waiting for its day already: the deletion comes sooner.
			$next = wp_next_scheduled( self::HOOK_SWEEP );
			if ( false === $next || $next > time() + HOUR_IN_SECONDS ) {
				wp_clear_scheduled_hook( self::HOOK_SWEEP );
				wp_schedule_single_event( time() + HOUR_IN_SECONDS, self::HOOK_SWEEP );
			}
			return;
		}
		$scan = self::scan();
		self::remember_kept( $scan );
		if ( $scan['go'] > 0 ) {
			self::begin( 'asked', HOUR_IN_SECONDS );
		}
	}

	/**
	 * The deletion a day after the clear: minified copies, bundles, optimized CSS copies and
	 * Google Fonts copies last used before the mark and unused for as long as the rule says, never
	 * one the live homepage links to. Where the homepage can't be read, nothing is deleted, and it
	 * is tried again in an hour (three times); then nothing is cleared again for a week.
	 *
	 * @return int Files deleted.
	 */
	public static function sweep(): int {
		$state = self::state();
		if ( null === $state['mark'] ) {
			return 0;
		}
		$linked = self::linked_files();
		if ( null === $linked ) {
			if ( $state['tries'] < 2 ) {
				self::save_state( array( 'tries' => $state['tries'] + 1 ) );
				wp_schedule_single_event( time() + HOUR_IN_SECONDS, self::HOOK_SWEEP );
			} else {
				// Given up. The caches were cleared for nothing, so the next clean-up waits a week before it clears them again.
				self::save_state(
					array(
						'mark'        => null,
						'retry_after' => time() + self::GAVE_UP_WAIT,
						'tries'       => 0,
					)
				);
			}
			return 0;
		}
		$cap     = $state['mark'] - self::SLACK;
		$removed = AssetMinifier::remove_unused( min( self::unused_before( self::unused_after() ), $cap ), $linked )
			+ AssetBundles::remove_unused( min( self::unused_before( self::unused_after() ), $cap ), $linked )
			+ CssOptimizer::remove_unused_copies( min( self::unused_before( self::unused_after() ), $cap ), $linked )
			+ LocalFonts::remove_unused( min( self::unused_before( LocalFonts::UNUSED_AFTER ), $cap ), $linked );
		wp_clear_scheduled_hook( self::HOOK_SWEEP );
		self::save_state(
			array(
				'mark'        => null,
				'retry_after' => null,
				'tries'       => 0,
				'swept'       => array(
					'at'    => time(),
					'files' => $removed,
				),
			)
		);
		self::remember_kept( self::scan() );
		if ( $removed > 0 ) {
			AuditLogger::info(
				'speed.assets_cleaned',
				/* translators: %d: number of files. */
				sprintf( _n( 'PageSpeed fixes: %d unused generated file was deleted to make room.', 'PageSpeed fixes: %d unused generated files were deleted to make room.', $removed, 'arvaseo-connector' ), $removed )
			);
		}
		return $removed;
	}

	/**
	 * Delete a generated folder's .css and .js files last used before a time. Only plain files
	 * directly inside it: never links or subfolders, and never one in $keep.
	 *
	 * @param string   $dir    The folder (already checked to be inside uploads).
	 * @param int      $before Last use before this time.
	 * @param string[] $keep   File names that stay (the ones the live homepage links to).
	 * @return int Files deleted.
	 */
	public static function remove_unused( string $dir, int $before, array $keep = array() ): int {
		if ( is_link( $dir ) || ! is_dir( $dir ) ) {
			return 0;
		}
		$removed = 0;
		clearstatcache();
		foreach ( array_merge( glob( $dir . '/*.css' ) ?: array(), glob( $dir . '/*.js' ) ?: array() ) as $file ) {
			if ( is_link( $file ) || ! is_file( $file ) || in_array( basename( $file ), $keep, true ) ) {
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
