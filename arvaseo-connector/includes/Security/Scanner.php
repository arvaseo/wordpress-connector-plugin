<?php
/**
 * The security scan: runs on this server, in the background, weekly and on Scan now. It only
 * reports; it never changes or deletes a file.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Security;

use ArvaSEO\Connector\Logging\AuditLogger;
use ArvaSEO\Connector\Settings;
use ArvaSEO\Connector\Support\Lock;

defined( 'ABSPATH' ) || exit;

/**
 * Steps, each resumable at any file: WordPress's files against WordPress.org's checksums; each
 * WordPress.org plugin against its checksums; a walk through wp-admin, wp-includes, the main
 * folder and wp-content (unexpected PHP, PHP in uploads, code disguised as images, and the
 * signature patterns for files that aren't verified official copies); exposed files and settings.
 * Batches run on WP-Cron for up to 20 seconds each, and while someone watches the Health page
 * (its progress check runs a batch when WP-Cron is slow), so a busy host isn't slowed down.
 */
final class Scanner {

	public const HOOK = 'arvaseo_connector_security_batch';

	public const WEEKLY = 'arvaseo_connector_security_weekly';

	public const OPTION = 'arvaseo_connector_security_scan';

	/** Seconds of work per batch, and the pause between batches. */
	private const SECONDS = 20;

	private const PAUSE = 5;

	/** Files larger than this are listed but not read for patterns. */
	public const MAX_READ = 2000000;

	/** A scan stops walking after this many files and says it covered part of the site. */
	private const MAX_FILES = 150000;

	/** A WordPress.org plugin whose files mostly differ isn't that plugin's official copy. */
	private const DIFFERS = 0.5;

	/** Files the main folder may have that WordPress also ships, deleted by many site owners. */
	private const DELETABLE = '/^(?:readme\.html|license\.txt|licen[cs]e\.txt|wp-config-sample\.php|liesmich\.html|leggimi\.txt|lisenssi\.html|[a-z]{2}(?:_[A-Z]{2})?\.txt)$/';

	/** Image types checked for code hidden at the start. */
	private const IMAGE = '/\.(?:jpe?g|png|gif|ico|bmp|webp|avif)$/i';

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( self::HOOK, array( self::class, 'batch' ) );
		add_action( self::WEEKLY, array( self::class, 'weekly' ) );
		add_action( 'init', array( self::class, 'schedule_weekly' ) );
	}

	/**
	 * The weekly scan, when it's on (it is by default).
	 */
	public static function schedule_weekly(): void {
		$next = wp_next_scheduled( self::WEEKLY );
		if ( ! Settings::get( 'security_weekly' ) ) {
			if ( false !== $next ) {
				wp_clear_scheduled_hook( self::WEEKLY );
			}
			return;
		}
		if ( false === $next ) {
			// A random hour in the first day, so sites with the plugin don't all scan at once.
			wp_schedule_event( time() + wp_rand( HOUR_IN_SECONDS, DAY_IN_SECONDS ), 'weekly', self::WEEKLY );
		}
	}

	/**
	 * Weekly job.
	 */
	public static function weekly(): void {
		if ( Settings::get( 'security_weekly' ) && ! self::running() ) {
			self::start( 'weekly' );
		}
	}

	/**
	 * Progress of the current or last scan.
	 *
	 * @return array<string, mixed>
	 */
	public static function progress(): array {
		$saved = get_option( self::OPTION, array() );
		return is_array( $saved ) ? $saved : array();
	}

	/**
	 * Whether a scan is running.
	 */
	public static function running(): bool {
		return 'running' === ( self::progress()['status'] ?? '' );
	}

	/**
	 * Start a scan (a running one carries on).
	 *
	 * @param string $trigger manual|weekly.
	 */
	public static function start( string $trigger = 'manual' ): bool {
		if ( self::running() ) {
			return false;
		}
		update_option(
			self::OPTION,
			array(
				'status'        => 'running',
				'trigger'       => $trigger,
				'started_at'    => time(),
				'last_batch_at' => 0,
				'step'          => 'core',
				'core_index'    => 0,
				'core_version'  => (string) get_bloginfo( 'version' ),
				'core_ok'       => null,
				'plugin_queue'  => self::folder_plugins(),
				'plugin_index'  => 0,
				'plugin_file'   => 0,
				'plugin_seen'   => 0,
				'plugin_found'  => array(),
				'plugins'       => array(),
				'walk'          => array(),
				'files'         => 0,
				'findings'      => array(),
				'partial'       => false,
				'exposure'      => null,
				// Files that aren't verified official copies, for a deep scan (DeepScan).
				'candidates'    => array(),
				'more_files'    => false,
			),
			false
		);
		wp_clear_scheduled_hook( self::HOOK );
		wp_schedule_single_event( time(), self::HOOK );
		if ( function_exists( 'spawn_cron' ) && ! wp_doing_cron() ) {
			spawn_cron();
		}
		AuditLogger::info( 'security.scan_started', __( 'Security scan started.', 'arvaseo-connector' ), array( 'status' => $trigger ) );
		return true;
	}

	/**
	 * Stop after the batch that's running.
	 */
	public static function stop(): void {
		$progress = self::progress();
		if ( 'running' !== ( $progress['status'] ?? '' ) ) {
			return;
		}
		$progress['status'] = 'stopped';
		update_option( self::OPTION, $progress, false );
		wp_clear_scheduled_hook( self::HOOK );
		AuditLogger::info( 'security.scan_stopped', __( 'Security scan stopped.', 'arvaseo-connector' ) );
	}

	/**
	 * What a step is doing, for the progress line.
	 *
	 * @param string $step Step.
	 */
	public static function step_label( string $step ): string {
		return match ( $step ) {
			'core'     => __( 'Checking WordPress’s own files', 'arvaseo-connector' ),
			'plugins'  => __( 'Checking plugins against WordPress.org', 'arvaseo-connector' ),
			'walk'     => __( 'Looking through the site’s files', 'arvaseo-connector' ),
			'exposure' => __( 'Checking for exposed files and settings', 'arvaseo-connector' ),
			default    => __( 'Finishing', 'arvaseo-connector' ),
		};
	}

	/**
	 * Run a batch when the last one was a while ago (the Health page's progress check).
	 */
	public static function tick(): void {
		$progress = self::progress();
		if ( 'running' === ( $progress['status'] ?? '' ) && (int) ( $progress['last_batch_at'] ?? 0 ) < time() - 15 ) {
			self::batch( 8 );
		}
	}

	/**
	 * One batch: as many steps as fit in the time, then the next batch is scheduled.
	 *
	 * @param float $seconds Seconds of work (each batch does at least one file).
	 */
	public static function batch( float $seconds = self::SECONDS ): void {
		Lock::with(
			'security',
			(int) ceil( $seconds ) + 60,
			static function () use ( $seconds ): void {
				$state = self::progress();
				if ( 'running' !== ( $state['status'] ?? '' ) ) {
					return;
				}
				$until = microtime( true ) + $seconds;
				do {
					$state = match ( $state['step'] ) {
						'core'     => self::core( $state, $until ),
						'plugins'  => self::plugins( $state, $until ),
						'walk'     => self::walk( $state, $until ),
						'exposure' => self::exposure( $state ),
						default    => self::finish( $state ),
					};
				} while ( 'running' === $state['status'] && microtime( true ) < $until );
				$state['last_batch_at'] = time();
				update_option( self::OPTION, $state, false );
				if ( 'running' === $state['status'] && ! wp_next_scheduled( self::HOOK ) ) {
					wp_schedule_single_event( time() + self::PAUSE, self::HOOK );
				}
			}
		);
	}

	// ----- Steps --------------------------------------------------------------------------

	/**
	 * WordPress's own files against WordPress.org's checksums.
	 *
	 * @param array<string, mixed> $state State.
	 * @param float                $until Deadline.
	 * @return array<string, mixed>
	 */
	private static function core( array $state, float $until ): array {
		$sums = Checksums::core();
		if ( null === $sums ) {
			$state['core_ok'] = false;
			$state['step']    = 'plugins';
			return $state;
		}
		$paths = array_keys( $sums );
		$count = count( $paths );
		$first = (int) $state['core_index'];
		for ( $i = $first; $i < $count; $i++ ) {
			if ( $i > $first && microtime( true ) >= $until ) {
				$state['core_index'] = $i;
				return $state;
			}
			$path = $paths[ $i ];
			$file = ABSPATH . $path;
			++$state['files'];
			if ( ! is_file( $file ) ) {
				if ( ! str_contains( $path, '/' ) && 1 === preg_match( self::DELETABLE, $path ) ) {
					continue;
				}
				$state = self::add( $state, Findings::make( 'core_missing', 'medium', array( 'path' => $path ) ) );
				continue;
			}
			$md5 = md5_file( $file );
			if ( false !== $md5 && $md5 !== $sums[ $path ] ) {
				$state = self::add( self::candidate( $state, $path ), self::changed_file( 'core_changed', $path, $file, $md5 ) );
			}
		}
		$state['core_ok'] = true;
		$state['step']    = 'plugins';
		return $state;
	}

	/**
	 * Each plugin from WordPress.org against its checksums at the installed version.
	 *
	 * @param array<string, mixed> $state State.
	 * @param float                $until Deadline.
	 * @return array<string, mixed>
	 */
	private static function plugins( array $state, float $until ): array {
		$queue = (array) $state['plugin_queue'];
		$total = count( $queue );
		$moved = false;
		while ( (int) $state['plugin_index'] < $total ) {
			if ( $moved && microtime( true ) >= $until ) {
				return $state;
			}
			$moved                  = true;
			list( $slug, $version ) = $queue[ (int) $state['plugin_index'] ];
			$reachable              = true;
			$sums                   = Checksums::plugin( $slug, $version, $reachable );
			if ( null === $sums ) {
				$state['plugins'][ $slug ] = $reachable ? 'not_listed' : 'unreachable';
				$state                     = self::next_plugin( $state );
				continue;
			}
			$paths = array_keys( $sums );
			$count = count( $paths );
			$first = (int) $state['plugin_file'];
			for ( $i = $first; $i < $count; $i++ ) {
				if ( $i > $first && microtime( true ) >= $until ) {
					$state['plugin_file'] = $i;
					return $state;
				}
				$path = $paths[ $i ];
				$file = WP_PLUGIN_DIR . '/' . $slug . '/' . $path;
				if ( ! is_file( $file ) ) {
					continue;
				}
				++$state['files'];
				++$state['plugin_seen'];
				$md5 = md5_file( $file );
				if ( false !== $md5 && ! in_array( $md5, $sums[ $path ], true ) ) {
					$state['plugin_found'][] = self::changed_file( 'plugin_changed', Exposure::relative( $file ), $file, $md5 );
				}
			}
			$found = (array) $state['plugin_found'];
			if ( (int) $state['plugin_seen'] > 0 && count( $found ) / (int) $state['plugin_seen'] > self::DIFFERS ) {
				// Mostly different files: another product with the same folder name (often a
				// premium edition). Its files are checked by pattern in the walk instead.
				$state['plugins'][ $slug ] = 'differs';
			} else {
				$state['plugins'][ $slug ] = 'verified';
				foreach ( $found as $finding ) {
					$state = self::add( self::candidate( $state, (string) $finding['path'] ), $finding );
				}
			}
			$state = self::next_plugin( $state );
		}
		$state['step'] = 'walk';
		$state['walk'] = self::walk_roots();
		return $state;
	}

	/**
	 * Move on to the next plugin.
	 *
	 * @param array<string, mixed> $state State.
	 * @return array<string, mixed>
	 */
	private static function next_plugin( array $state ): array {
		++$state['plugin_index'];
		$state['plugin_file']  = 0;
		$state['plugin_seen']  = 0;
		$state['plugin_found'] = array();
		return $state;
	}

	/**
	 * The folders to walk: the main folder (its files only), wp-admin, wp-includes and wp-content.
	 *
	 * @return array<int, array{0: string, 1: int, 2: bool}> [folder, offset, recurse]
	 */
	private static function walk_roots(): array {
		$roots   = array(
			array( rtrim( wp_normalize_path( ABSPATH ), '/' ), 0, false ),
			array( wp_normalize_path( ABSPATH . 'wp-admin' ), 0, true ),
			array( wp_normalize_path( ABSPATH . 'wp-includes' ), 0, true ),
			array( wp_normalize_path( WP_CONTENT_DIR ), 0, true ),
		);
		$plugins = wp_normalize_path( WP_PLUGIN_DIR );
		if ( ! str_starts_with( $plugins . '/', wp_normalize_path( WP_CONTENT_DIR ) . '/' ) ) {
			$roots[] = array( $plugins, 0, true );
		}
		return $roots;
	}

	/**
	 * Walk the folders, a breadth-first queue of [folder, offset, recurse].
	 *
	 * @param array<string, mixed> $state State.
	 * @param float                $until Deadline.
	 * @return array<string, mixed>
	 */
	private static function walk( array $state, float $until ): array {
		$ctx  = self::context();
		$done = 0;
		while ( array() !== $state['walk'] ) {
			list( $dir, $offset, $recurse ) = $state['walk'][0];
			$names                          = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an unreadable folder is skipped.
			$names                          = is_array( $names ) ? array_values( array_diff( $names, array( '.', '..' ) ) ) : array();
			$count                          = count( $names );
			for ( $i = (int) $offset; $i < $count; $i++ ) {
				if ( $done > 0 && microtime( true ) >= $until ) {
					$state['walk'][0][1] = $i;
					return $state;
				}
				++$done;
				$path = $dir . '/' . $names[ $i ];
				if ( is_link( $path ) ) {
					continue;
				}
				if ( is_dir( $path ) ) {
					if ( $recurse && '.git' !== $names[ $i ] && ! self::is_root_folder( $path ) ) {
						$state['walk'][] = array( $path, 0, true );
					}
					continue;
				}
				if ( (int) $state['files'] >= self::MAX_FILES ) {
					$state['partial'] = true;
					$state['walk']    = array();
					break 2;
				}
				$state = self::file( $state, $path, $ctx );
			}
			array_shift( $state['walk'] );
		}
		$state['step'] = 'exposure';
		return $state;
	}

	/**
	 * Whether a folder is one of the walk's own starting points (walked on its own).
	 *
	 * @param string $path Folder.
	 */
	private static function is_root_folder( string $path ): bool {
		$plugins = wp_normalize_path( WP_PLUGIN_DIR );
		return $path === $plugins && ! str_starts_with( $plugins . '/', wp_normalize_path( WP_CONTENT_DIR ) . '/' );
	}

	/**
	 * What the walk needs for every file, worked out once per batch.
	 *
	 * @return array{root: string, content: string, plugins: string, uploads: string|null, core: array<string, string>|null}
	 */
	private static function context(): array {
		$uploads = wp_upload_dir( null, false );
		return array(
			'root'    => wp_normalize_path( ABSPATH ),
			'content' => wp_normalize_path( WP_CONTENT_DIR ) . '/',
			'plugins' => wp_normalize_path( WP_PLUGIN_DIR ) . '/',
			'uploads' => empty( $uploads['error'] ) ? wp_normalize_path( (string) $uploads['basedir'] ) . '/' : null,
			'core'    => Checksums::core(),
		);
	}

	/**
	 * Check one file met in the walk.
	 *
	 * @param array<string, mixed>                                                                                          $state State.
	 * @param string                                                                                                        $path  Absolute path (normalized).
	 * @param array{root: string, content: string, plugins: string, uploads: string|null, core: array<string, string>|null} $ctx   Context.
	 * @return array<string, mixed>
	 */
	private static function file( array $state, string $path, array $ctx ): array {
		$root     = $ctx['root'];
		$content  = $ctx['content'];
		$plugins  = $ctx['plugins'];
		$uploads  = $ctx['uploads'];
		$core     = $ctx['core'];
		$relative = Exposure::relative( $path );
		$kind     = Signatures::kind( $path );
		$name     = basename( $path );

		// Uploads: PHP, code disguised as an image, and the patterns for scripts, SVGs and .htaccess.
		if ( null !== $uploads && str_starts_with( $path, $uploads ) ) {
			++$state['files'];
			if ( 'php' === $kind ) {
				// Empty index files, and plugins' logs that stop PHP on their first line.
				if ( self::is_silent_php( $path ) || KnownFiles::exits_first( $path ) ) {
					return $state;
				}
				$state = self::candidate( $state, $relative );
				$match = self::read_and_match( $path, 'php' );
				return self::add( $state, Findings::make( 'uploads_php', null !== $match ? 'critical' : 'high', self::details( $relative, $path, $match ) ) );
			}
			if ( 1 === preg_match( self::IMAGE, $name ) ) {
				$head = self::head( $path );
				if ( null !== $head && 1 === preg_match( '/<\?php|<\?=|\beval\s*\(/i', $head ) ) {
					return self::add( $state, Findings::make( 'uploads_disguised', 'critical', self::details( $relative, $path, null ) ) );
				}
				return $state;
			}
			return null !== $kind ? self::pattern( $state, $path, $relative, $kind ) : $state;
		}

		// WordPress's own folders and the main folder: anything WordPress doesn't ship.
		$in_core = str_starts_with( $relative, 'wp-admin/' ) || str_starts_with( $relative, 'wp-includes/' );
		if ( $in_core || ( ! str_contains( $relative, '/' ) && str_starts_with( $path, $root ) ) ) {
			if ( 'htaccess' === $kind ) {
				++$state['files'];
				return self::pattern( $state, $path, $relative, $kind );
			}
			if ( 'php' !== $kind || 'wp-config.php' === $relative || ( null !== $core && isset( $core[ $relative ] ) ) ) {
				return $state;
			}
			++$state['files'];
			// A security plugin's firewall loader (Wordfence's wordfence-waf.php) belongs here.
			if ( ! $in_core && KnownFiles::firewall_loader( $path ) ) {
				return $state;
			}
			if ( null === $core ) {
				// Without WordPress.org's list the folder can only be checked by pattern.
				return self::pattern( $state, $path, $relative, $kind );
			}
			$state = self::candidate( $state, $relative );
			$match = self::read_and_match( $path, 'php' );
			$type  = $in_core ? 'core_unexpected' : 'root_unexpected';
			$level = null !== $match ? 'critical' : ( $in_core ? 'high' : 'medium' );
			return self::add( $state, Findings::make( $type, $level, self::details( $relative, $path, $match ) ) );
		}

		if ( null === $kind ) {
			return $state;
		}
		++$state['files'];

		// A plugin from WordPress.org whose files matched: only files it doesn't ship.
		if ( str_starts_with( $path, $plugins ) ) {
			$inside = substr( $path, strlen( $plugins ) );
			$slug   = strtok( $inside, '/' );
			if ( false !== $slug && str_contains( $inside, '/' ) && 'verified' === ( $state['plugins'][ $slug ] ?? '' ) ) {
				$sums    = self::walk_sums( $state, $slug );
				$in_slug = substr( $inside, strlen( $slug ) + 1 );
				if ( null !== $sums && isset( $sums[ $in_slug ] ) ) {
					return $state;
				}
				if ( 'php' === $kind ) {
					$state = self::candidate( $state, $relative );
					$match = self::read_and_match( $path, 'php' );
					return self::add( $state, Findings::make( 'plugin_unexpected', null !== $match ? 'critical' : 'high', self::details( $relative, $path, $match ) ) );
				}
			}
		}
		// Everything else in wp-content: themes, premium and custom plugins, must-use plugins,
		// drop-ins and caches, by pattern.
		return str_starts_with( $path, $content ) || str_starts_with( $path, $plugins ) ? self::pattern( $state, $path, $relative, $kind ) : $state;
	}

	/**
	 * Exposed files and settings.
	 *
	 * @param array<string, mixed> $state State.
	 * @return array<string, mixed>
	 */
	private static function exposure( array $state ): array {
		$result = Exposure::run();
		foreach ( $result['findings'] as $finding ) {
			$state = self::add( $state, $finding );
		}
		$state['exposure'] = $result['checked'];
		$state['step']     = 'finish';
		return $state;
	}

	/**
	 * Save the results.
	 *
	 * @param array<string, mixed> $state State.
	 * @return array<string, mixed>
	 */
	private static function finish( array $state ): array {
		$statuses = array_count_values( (array) $state['plugins'] );
		Findings::save(
			(array) $state['findings'],
			array(
				'seconds'  => max( 0, time() - (int) $state['started_at'] ),
				'files'    => (int) $state['files'],
				'partial'  => (bool) $state['partial'],
				'trigger'  => (string) $state['trigger'],
				'coverage' => array(
					'core'            => $state['core_ok'] ? (string) $state['core_version'] : null,
					'plugins'         => count( (array) $state['plugin_queue'] ),
					'plugins_checked' => (int) ( $statuses['verified'] ?? 0 ),
					'plugins_pattern' => array_keys( array_filter( (array) $state['plugins'], static fn ( string $s ): bool => 'verified' !== $s ) ),
					'exposure'        => (bool) $state['exposure'],
				),
			)
		);
		update_option(
			DeepScan::CANDIDATES,
			array(
				'scanned_at' => time(),
				'paths'      => (array) ( $state['candidates'] ?? array() ),
				'partial'    => ! empty( $state['more_files'] ) || ! empty( $state['partial'] ),
			),
			false
		);
		$state['status']      = 'done';
		$state['finished_at'] = time();
		$state['findings']    = array();
		$state['walk']        = array();
		$state['candidates']  = array();
		$counts               = Findings::counts();
		AuditLogger::info(
			'security.scan_finished',
			/* translators: 1: number of files, 2: number of findings. */
			sprintf( __( 'Security scan finished: %1$d files checked, %2$d findings.', 'arvaseo-connector' ), (int) $state['files'], array_sum( $counts ) ),
			array( 'count' => array_sum( $counts ) )
		);
		/**
		 * After a security scan's results are saved (the connection sends them to Arva SEO).
		 */
		do_action( 'arvaseo_connector_security_scanned' );
		return $state;
	}

	// ----- Helpers ------------------------------------------------------------------------

	/**
	 * Plugins in a folder of their own: [slug, version], from get_plugins().
	 *
	 * @return array<int, array{0: string, 1: string}>
	 */
	private static function folder_plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$list = array();
		foreach ( get_plugins() as $file => $data ) {
			if ( str_contains( $file, '/' ) ) {
				$list[ strtok( $file, '/' ) ] = array( (string) strtok( $file, '/' ), (string) ( $data['Version'] ?? '' ) );
			}
		}
		return array_values( $list );
	}

	/**
	 * A verified plugin's checksums, kept for the rest of the request (the walk asks once per file).
	 *
	 * @param array<string, mixed> $state State.
	 * @param string               $slug  Folder name.
	 * @return array<string, string[]>|null
	 */
	private static function walk_sums( array $state, string $slug ): ?array {
		static $memo = array();
		if ( ! array_key_exists( $slug, $memo ) ) {
			$memo[ $slug ] = null;
			foreach ( (array) $state['plugin_queue'] as $entry ) {
				if ( $entry[0] === $slug ) {
					$memo[ $slug ] = Checksums::plugin( $slug, $entry[1] );
					break;
				}
			}
		}
		return $memo[ $slug ];
	}

	/**
	 * Whether a PHP file only stops folder listing ("Silence is golden"): nothing but comments.
	 *
	 * @param string $path Absolute path.
	 */
	private static function is_silent_php( string $path ): bool {
		$size = @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- unreadable files aren't silent.
		if ( false === $size || $size > 1024 ) {
			return false;
		}
		$code = (string) @file_get_contents( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file read.
		$code = (string) preg_replace( array( '~/\*.*?\*/~s', '~(?://|#)[^\n]*~', '~<\?php|\?>~i' ), '', $code );
		return '' === trim( $code );
	}

	/**
	 * A file that differs from its official copy, more severe when it also matches a pattern.
	 *
	 * @param string $type     core_changed|plugin_changed.
	 * @param string $relative Path from the main folder.
	 * @param string $file     Absolute path.
	 * @param string $md5      Its md5.
	 * @return array<string, mixed>
	 */
	private static function changed_file( string $type, string $relative, string $file, string $md5 ): array {
		$kind  = Signatures::kind( $file );
		$match = null !== $kind ? self::read_and_match( $file, $kind ) : null;
		return Findings::make( $type, null !== $match ? 'critical' : 'high', array_merge( self::details( $relative, $file, $match ), array( 'file_hash' => $md5 ) ) );
	}

	/**
	 * A pattern finding for a file, when it matches.
	 *
	 * @param array<string, mixed> $state    State.
	 * @param string               $path     Absolute path.
	 * @param string               $relative Path from the main folder.
	 * @param string               $kind     php|js|htaccess|svg.
	 * @return array<string, mixed>
	 */
	private static function pattern( array $state, string $path, string $relative, string $kind ): array {
		if ( 'php' === $kind && KnownFiles::exits_first( $path ) ) {
			return $state; // Nothing in it can run.
		}
		$state = self::candidate( $state, $relative );
		$match = self::read_and_match( $path, $kind );
		if ( null === $match ) {
			return $state;
		}
		return self::add( $state, Findings::make( 'code', $match['severity'], self::details( $relative, $path, $match ) ) );
	}

	/**
	 * The details of a finding about a file.
	 *
	 * @param string                    $relative Path from the main folder.
	 * @param string                    $file     Absolute path.
	 * @param array<string, mixed>|null $found    Signature match.
	 * @return array<string, mixed>
	 */
	private static function details( string $relative, string $file, ?array $found ): array {
		$md5 = @md5_file( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a file deleted meanwhile has no hash.
		return array(
			'path'      => $relative,
			'signature' => $found['id'] ?? null,
			'title'     => $found['title'] ?? null,
			'line'      => $found['line'] ?? null,
			'excerpt'   => $found['excerpt'] ?? null,
			'file_hash' => false !== $md5 ? $md5 : null,
		);
	}

	/**
	 * Read a file (when not too large) and match the patterns.
	 *
	 * @param string $path Absolute path.
	 * @param string $kind php|js|htaccess|svg.
	 * @return array{id: string, severity: string, title: string, line: int, excerpt: string}|null
	 */
	private static function read_and_match( string $path, string $kind ): ?array {
		$size = @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- unreadable files are skipped.
		if ( false === $size || $size > self::MAX_READ || $size < 8 ) {
			return null;
		}
		$contents = @file_get_contents( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file read for patterns; unreadable files are skipped.
		// A security plugin's firewall loads through auto_prepend_file: those lines are expected.
		$expected = 'htaccess' === $kind ? static fn ( string $id, string $line ): bool => 'htaccess.auto_prepend' === $id && KnownFiles::firewall_prepend( $line ) : null;
		return is_string( $contents ) ? Signatures::match( $contents, $kind, $expected ) : null;
	}

	/**
	 * A file's first 512 bytes.
	 *
	 * @param string $path Absolute path.
	 */
	private static function head( string $path ): ?string {
		$handle = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- reading the start of a local file.
		if ( false === $handle ) {
			return null;
		}
		$head = fread( $handle, 512 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- see above.
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
		return false === $head ? null : $head;
	}

	/**
	 * Remember a file that isn't a verified official copy, for a deep scan.
	 *
	 * @param array<string, mixed> $state    State.
	 * @param string               $relative Path from the main folder.
	 * @return array<string, mixed>
	 */
	private static function candidate( array $state, string $relative ): array {
		$state['candidates'] ??= array();
		if ( count( $state['candidates'] ) < DeepScan::MAX_CANDIDATES ) {
			$state['candidates'][] = $relative;
		} else {
			$state['more_files'] = true;
		}
		return $state;
	}

	/**
	 * Add a finding (the most severe are kept when there are too many).
	 *
	 * @param array<string, mixed> $state   State.
	 * @param array<string, mixed> $finding Finding.
	 * @return array<string, mixed>
	 */
	private static function add( array $state, array $finding ): array {
		$state['findings'][] = $finding;
		if ( count( $state['findings'] ) > Findings::MAX * 2 ) {
			$order = array_flip( Findings::SEVERITIES );
			usort( $state['findings'], static fn ( array $a, array $b ): int => $order[ $a['severity'] ] <=> $order[ $b['severity'] ] );
			$state['findings'] = array_slice( $state['findings'], 0, Findings::MAX );
		}
		return $state;
	}

	/**
	 * Remove the scan's state (uninstall, tests).
	 */
	public static function clear(): void {
		wp_clear_scheduled_hook( self::HOOK );
		wp_clear_scheduled_hook( self::WEEKLY );
		delete_option( self::OPTION );
	}
}
