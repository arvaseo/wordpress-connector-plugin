<?php
/**
 * One bounded upgrade cleanup; cached pages retain their generated files through the grace period.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Speed;

use ArvaSEO\Connector\Support\Lock;

defined( 'ABSPATH' ) || exit;

/** Duplicate font copies are only removed by AssetCache's later, cache-aware sweep. */
final class Housekeeping {

	public const HOOK       = 'arvaseo_connector_housekeeping_2120';
	public const OPTION     = 'arvaseo_connector_housekeeping_2120';
	private const MAX_BYTES = 67108864;

	/** Register the one-time worker. */
	public static function init(): void {
		add_action( self::HOOK, array( self::class, 'run' ) );
		if ( ! empty( self::report()['queued'] ) && empty( self::report()['done'] ) ) {
			self::schedule();
		}
	}

	/** Schedule once, fifteen minutes after upgrading. */
	public static function schedule(): void {
		if ( empty( self::report()['done'] ) && ! wp_next_scheduled( self::HOOK ) ) {
			update_option( self::OPTION, self::report() + array( 'queued' => true ), false );
			wp_schedule_single_event( time() + 15 * MINUTE_IN_SECONDS, self::HOOK );
		}
	}

	/**
	 * Small local report, without source URLs or stylesheet contents.
	 *
	 * @return array<string,mixed>
	 */
	public static function report(): array {
		$report = get_option( self::OPTION, array() );
		return is_array( $report ) ? $report : array();
	}

	/** One admin report for the latest completed cleanup stage, with measured deletion totals only. */
	public static function notice(): ?string {
		$report = self::report();
		if ( empty( $report['done'] ) || ! empty( $report['shown'] ) ) {
			return null;
		}
		$report['shown'] = true;
		update_option( self::OPTION, $report, false );
		if ( (int) ( $report['fonts_deleted'] ?? 0 ) > 0 ) {
			/* translators: 1: removed duplicate copies, 2: measured bytes removed. */
			return sprintf( __( 'Arva SEO removed %1$d duplicate Google Fonts copies (%2$s) after the cache grace period.', 'arvaseo-connector' ), (int) $report['fonts_deleted'], size_format( (int) $report['bytes_deleted'] ) );
		}
		$count = count( (array) ( $report['duplicates'] ?? array() ) );
		if ( $count > 0 && ! empty( $report['kept_because'] ) ) {
			/* translators: 1: duplicate copies, 2: caches which may still use them. */
			return sprintf( __( 'Arva SEO kept %1$d duplicate font copies because %2$s may still have pages that use them.', 'arvaseo-connector' ), $count, (string) $report['kept_because'] );
		}
		if ( $count > 0 ) {
			/* translators: %d: duplicate copies queued for safe cleanup. */
			return sprintf( __( 'Arva SEO found %d duplicate font copies. They stay available until cache clearing and the grace period allow safe cleanup.', 'arvaseo-connector' ), $count );
		}
		return ! empty( $report['truncated'] ) ? __( 'Upgrade cleanup reached its work limit. Unchecked files were kept; normal background cleanup continues.', 'arvaseo-connector' ) : null;
	}

	/** Do bounded local work; network cache clearing happens in the separate existing rotation worker. */
	public static function run(): void {
		Lock::with(
			'housekeeping-2120',
			25,
			static function (): void {
				if ( ! empty( self::report()['done'] ) ) {
					return;
				}
				$deadline = microtime( true ) + 20;
				$report   = array(
					'done'          => true,
					'at'            => time(),
					'touched'       => 0,
					'jobs_removed'  => 0,
					'index_removed' => 0,
					'duplicates'    => array(),
					'fonts_deleted' => 0,
					'bytes_deleted' => 0,
					'kept_because'  => '',
					'truncated'     => false,
				);
				$used     = CssOptimizer::used_directory();
				if ( is_dir( $used ) && ! is_link( $used ) ) {
					$count = 0;
					foreach ( new \DirectoryIterator( $used ) as $file ) {
						if ( ++$count > 2000 || microtime( true ) >= $deadline ) {
							$report['truncated'] = true;
							break;
						}
						if ( $file->isFile() && ! $file->isLink() && 'css' === strtolower( $file->getExtension() ) && touch( $file->getPathname() ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- record the start of the grace period for our generated copies.
							++$report['touched'];
						}
					}
				}
				$report['index_removed'] = CssIndex::compact();
				$events                  = _get_cron_array();
				$seen                    = array();
				$visited                 = 0;
				foreach ( $events as $at => $hooks ) {
					foreach ( $hooks[ LocalFonts::HOOK ] ?? array() as $event ) {
						if ( ++$visited > 1000 || microtime( true ) >= $deadline ) {
							$report['truncated'] = true;
							break 2;
						}
						$args = $event['args'] ?? array();
						$key  = isset( $args[0] ) && is_string( $args[0] ) ? LocalFonts::copy_key( $args[0] ) : null;
						if ( null === $key ) {
							continue;
						}
						if ( isset( $seen[ $key ] ) ) {
							if ( wp_unschedule_event( (int) $at, LocalFonts::HOOK, $args ) ) {
								++$report['jobs_removed'];
							}
						} else {
							$seen[ $key ] = true;
						}
					}
				}
				$remaining = self::MAX_BYTES;
				$copies    = array();
				$dir       = LocalFonts::directory();
				if ( is_dir( $dir ) && ! is_link( $dir ) ) {
					$count = 0;
					foreach ( new \DirectoryIterator( $dir ) as $file ) {
						if ( ++$count > 1000 || microtime( true ) >= $deadline || $remaining <= 0 ) {
							$report['truncated'] = true;
							break;
						}
						if ( ! $file->isFile() || $file->isLink() || ! preg_match( '/^([a-f0-9]{16})\.css$/D', $file->getFilename(), $match ) ) {
							continue;
						}
						$copy = self::fingerprint( $match[1], $remaining, $deadline );
						if ( null !== $copy ) {
							$copies[ $match[1] ] = $copy;
						}
					}
				}
				uasort( $copies, static fn ( array $a, array $b ): int => $b['used'] <=> $a['used'] );
				$keepers = array();
				foreach ( $copies as $key => $copy ) {
					$hash = $copy['hash'];
					if ( isset( $keepers[ $hash ] ) && $copy['used'] < time() - 3 * DAY_IN_SECONDS && count( $report['duplicates'] ) < 300 ) {
						$report['duplicates'][ $key ] = array(
							'keeper' => $keepers[ $hash ],
							'hash'   => $hash,
						);
					} else {
						$keepers[ $hash ] = $keepers[ $hash ] ?? $key;
					}
				}
				$report['kept_because'] = implode( ', ', array_column( AssetCache::blockers(), 'name' ) );
				update_option( self::OPTION, $report, false );
				wp_clear_scheduled_hook( self::HOOK );
				// Rotation performs its own homepage check and cache clear; deletion waits at least a day.
				if ( ! wp_next_scheduled( AssetCache::HOOK ) ) {
					wp_schedule_single_event( time() + MINUTE_IN_SECONDS, AssetCache::HOOK );
				}
			}
		);
	}

	/**
	 * Duplicate candidates available to the cache rotation. Blocking caches keep them all.
	 *
	 * @param string[] $linked Files retained by the live homepage.
	 */
	public static function duplicate_count( array $linked = array() ): int {
		if ( AssetCache::blockers() ) {
			return 0;
		}
		$count = 0;
		foreach ( (array) ( self::report()['duplicates'] ?? array() ) as $key => $copy ) {
			$dir = LocalFonts::directory();
			if ( ! is_string( $key ) || ! preg_match( '/^[a-f0-9]{16}$/D', $key ) || ! is_array( $copy ) || ! is_string( $copy['keeper'] ?? null ) || ! preg_match( '/^[a-f0-9]{16}$/D', $copy['keeper'] ) ) {
				continue;
			}
			$file   = $dir . '/' . $key . '.css';
			$keeper = $dir . '/' . $copy['keeper'] . '.css';
			if ( ! is_link( $dir ) && ! is_link( $file ) && ! is_link( $keeper ) && is_file( $file ) && is_file( $keeper ) && filemtime( $file ) < time() - 3 * DAY_IN_SECONDS && ! in_array( $key, $linked, true ) && ! in_array( $key . '.css', $linked, true ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Recheck actual bytes and last use after the normal cache grace period, never just a filename.
	 *
	 * @param int      $before Last use must be before this mark, after caches were cleared.
	 * @param string[] $linked Live homepage files to keep.
	 * @return int Copies removed (not font-file count).
	 */
	public static function sweep_duplicates( int $before, array $linked ): int {
		return Lock::with( 'font-duplicates', 25, static fn (): int => self::sweep_locked( $before, $linked ) ) ?? 0;
	}

	/**
	 * Serialized duplicate removal; only the worker that owns this lock records removed bytes.
	 *
	 * @param int      $before Grace cutoff.
	 * @param string[] $linked Retained files.
	 */
	private static function sweep_locked( int $before, array $linked ): int {
		if ( AssetCache::blockers() ) {
			return 0;
		}
		$report    = self::report();
		$remaining = self::MAX_BYTES;
		$deadline  = microtime( true ) + 15;
		$removed   = 0;
		foreach ( (array) ( $report['duplicates'] ?? array() ) as $key => $pair ) {
			if ( microtime( true ) >= $deadline || $remaining <= 0 ) {
				break;
			}
			if ( ! is_string( $key ) || ! preg_match( '/^[a-f0-9]{16}$/D', $key ) || ! is_array( $pair ) || ! is_string( $pair['keeper'] ?? null ) || in_array( $key, $linked, true ) || in_array( $key . '.css', $linked, true ) ) {
				continue;
			}
			$old = self::fingerprint( $key, $remaining, $deadline );
			$new = self::fingerprint( $pair['keeper'], $remaining, $deadline );
			if ( null === $old || null === $new || $old['used'] >= min( $before, time() - 3 * DAY_IN_SECONDS ) || $new['used'] < $old['used'] || $new['hash'] !== $old['hash'] || ( $pair['hash'] ?? '' ) !== $old['hash'] ) {
				continue;
			}
			$dir           = LocalFonts::directory();
			$deleted_bytes = (int) filesize( $dir . '/' . $key . '.css' );
			wp_delete_file( $dir . '/' . $key . '.css' );
			clearstatcache();
			if ( ! file_exists( $dir . '/' . $key . '.css' ) ) {
				// If the stylesheet could not be removed, all font files must remain available too.
				foreach ( $old['files'] as $name ) {
					$path  = $dir . '/' . $key . '/' . $name;
					$bytes = (int) filesize( $path );
					wp_delete_file( $path );
					clearstatcache( true, $path );
					if ( ! file_exists( $path ) ) {
						$deleted_bytes += $bytes;
					}
				}
				// Only a validated flat font-copy directory is ever removed, never recursive unknown content.
				@rmdir( $dir . '/' . $key ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- this validated flat copy directory only.
				++$removed;
				$report['bytes_deleted'] = (int) ( $report['bytes_deleted'] ?? 0 ) + $deleted_bytes;
				unset( $report['duplicates'][ $key ] );
			}
		}
		if ( $removed > 0 ) {
			$report['fonts_deleted'] = (int) ( $report['fonts_deleted'] ?? 0 ) + $removed;
			$report['shown']         = false;
			update_option( self::OPTION, $report, false );
		}
		return $removed;
	}

	/**
	 * Verified local copy identity, with a shared byte and time budget.
	 *
	 * @param string $key       Own copy key.
	 * @param int    $remaining Remaining bytes to read.
	 * @param float  $deadline  Latest time to continue.
	 * @return array{hash:string,used:int,bytes:int,files:string[]}|null
	 */
	private static function fingerprint( string $key, int &$remaining, float $deadline ): ?array {
		$root = LocalFonts::directory();
		$dir  = $root . '/' . $key;
		$css  = $root . '/' . $key . '.css';
		if ( ! preg_match( '/^[a-f0-9]{16}$/D', $key ) || is_link( $root ) || is_link( $dir ) || is_link( $css ) || ! is_dir( $dir ) || ! is_readable( $dir ) || ! is_file( $css ) || ! is_readable( $css ) || filesize( $css ) > 500000 || filesize( $css ) > $remaining || microtime( true ) >= $deadline ) {
			return null;
		}
		$text = file_get_contents( $css ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- verified own copy, bounded read.
		if ( false === $text ) {
			return null;
		}
		$bytes      = strlen( $text );
		$remaining -= $bytes;
		$hashes     = array();
		foreach ( new \DirectoryIterator( $dir ) as $file ) {
			if ( $file->isDot() ) {
				continue;
			}
			if ( count( $hashes ) >= 40 || ! $file->isFile() || $file->isLink() || ! preg_match( '/^[A-Za-z0-9_.-]+\.(?:woff2?|ttf|otf)$/iD', $file->getFilename() ) || $file->getSize() > 1048576 || $file->getSize() > $remaining || microtime( true ) >= $deadline ) {
				return null;
			}
			$hash = hash_file( 'sha256', $file->getPathname() );
			if ( false === $hash ) {
				return null;
			}
			$hashes[ $file->getFilename() ] = $hash;
			$remaining                     -= $file->getSize();
			$bytes                         += $file->getSize();
		}
		if ( ! $hashes ) {
			return null;
		}
		ksort( $hashes );
		$base = trailingslashit( wp_get_upload_dir()['baseurl'] ) . LocalFonts::FOLDER . '/';
		$text = str_replace( $base . $key . '/', $base . '{copy}/', $text );
		return array(
			'hash'  => hash( 'sha256', $text . '|' . wp_json_encode( $hashes ) ),
			'used'  => (int) filemtime( $css ),
			'bytes' => $bytes,
			'files' => array_keys( $hashes ),
		);
	}
}
