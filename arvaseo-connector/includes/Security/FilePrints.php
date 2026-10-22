<?php
/**
 * Fingerprints of the files WordPress.org can't vouch for (2.7.0): themes and child themes,
 * must-use plugins, drop-ins and plugins that aren't WordPress.org's copies. The security scan
 * records them, and reports what changed since.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Security;

use ArvaSEO\Connector\Install\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Each part of the site (a theme, a plugin, a must-use plugin, the drop-ins) is recorded quietly
 * the first time a scan sees it, and again after it's updated (WordPress's updater, or a new
 * version number). From then on a scan reports a new or changed PHP or JavaScript file until
 * someone marks it "This is expected", which records the file as it is now. For the riskiest
 * files, the last copy seen stays in this site's database so the finding can show the lines that
 * were added; only those lines (at most 10) reach Arva SEO, never the file.
 */
final class FilePrints {

	/** Which parts have a recorded starting point: component => [version, at]. */
	public const BASELINES = 'arvaseo_connector_file_baselines';

	/** Copies of the riskiest files are kept up to this size. */
	public const MAX_COPY = 102400;

	/** Added lines kept with a finding, at most. */
	public const MAX_LINES = 10;

	/** The finding types this class makes. */
	public const TYPES = array( 'file_changed', 'file_added' );

	/** Code that hacked files add: loading scripts, printing into every page, hidden code. */
	private const RISKY = '/\b(?:wp_enqueue_script|wp_register_script|wp_head|wp_footer|wp_body_open|eval|assert|base64_decode|gzinflate|gzuncompress|str_rot13|create_function|curl_exec|wp_remote_(?:get|post)|file_get_contents\s*\(\s*[\'"]https?)\b|document\.write|createElement\s*\(\s*[\'"]script|\$_(?:GET|POST|REQUEST|COOKIE)\s*\[/i';

	/** Signs of hidden code strong enough for a new file too. */
	private const HIDDEN = '/\b(?:eval|assert|base64_decode|gzinflate|gzuncompress|str_rot13|create_function)\s*\(/i';

	/**
	 * Components resolved in this request: component => watch|quiet.
	 *
	 * @var array<string, string>
	 */
	private static array $modes = array();

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( 'upgrader_process_complete', array( self::class, 'after_update' ), 10, 2 );
		add_action( 'deleted_plugin', array( self::class, 'after_plugin_deleted' ), 10, 2 );
		add_action( 'deleted_theme', array( self::class, 'after_theme_deleted' ), 10, 2 );
		add_action( 'arvaseo_connector_maintenance', array( self::class, 'check_risky_files' ) );
	}

	// ----- During a scan --------------------------------------------------------------------

	/**
	 * Check one PHP or JavaScript file met in the scan's walk. The first time a part of the site
	 * is seen (or after it's updated) its files are only recorded.
	 *
	 * @param array<string, mixed> $state    The scan's state.
	 * @param string               $path     Absolute path (normalized).
	 * @param string               $relative Path from the main folder.
	 * @param string               $kind     php|js|htaccess|svg.
	 * @return array<string, mixed>
	 */
	public static function check( array $state, string $path, string $relative, string $kind ): array {
		if ( ! in_array( $kind, array( 'php', 'js' ), true ) ) {
			return $state;
		}
		$component = self::component( $path, (array) ( $state['plugins'] ?? array() ) );
		if ( null === $component || self::is_own_file( $path ) ) {
			return $state;
		}
		$mode = self::mode( $component, $state );
		// Remembered for the end of the scan, which records a starting point for the new ones.
		$state['prints'][ $component ] = $mode;

		$stat = @stat( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a file deleted meanwhile is skipped.
		if ( false === $stat ) {
			return $state;
		}
		$row   = self::row( $relative );
		$risky = self::is_risky( $relative );
		if ( null !== $row && ! $risky && (int) $row['size'] === (int) $stat['size'] && (int) $row['mtime'] === (int) $stat['mtime'] ) {
			return $state; // Unchanged, without reading it again.
		}
		$sha = @hash_file( 'sha256', $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
		if ( false === $sha ) {
			return $state;
		}
		if ( null !== $row && $row['sha256'] === $sha ) {
			if ( (int) $row['mtime'] !== (int) $stat['mtime'] || (int) $row['size'] !== (int) $stat['size'] ) {
				self::record( $relative, $component, $path, $sha, $stat, $risky );
			}
			return $state;
		}
		if ( 'quiet' === $mode || Feed::is_safe( $sha ) ) {
			self::record( $relative, $component, $path, $sha, $stat, $risky );
			return $state;
		}
		// Reported until someone marks it as expected: the recorded copy stays as it was.
		$finding = self::finding( $relative, $path, $sha, $row );
		if ( 'file_changed' === $finding['type'] && null !== $row && is_string( $row['copy'] ) && self::only_safe_lines( (array) $finding['all_lines'] ) ) {
			// Every added line is one Arva SEO found safe on many sites (a host's own line).
			self::record( $relative, $component, $path, $sha, $stat, $risky );
			return $state;
		}
		unset( $finding['all_lines'] );
		return self::add( $state, $finding );
	}

	/**
	 * At the end of a full scan: every part seen for the first time (or since its update) now has
	 * its starting point. A scan that stopped early records none, so files it didn't reach aren't
	 * reported as new next time.
	 *
	 * @param array<string, mixed> $state The scan's state.
	 */
	public static function finish( array $state ): void {
		if ( ! empty( $state['partial'] ) ) {
			return;
		}
		$baselines = self::baselines();
		$changed   = false;
		foreach ( (array) ( $state['prints'] ?? array() ) as $component => $mode ) {
			if ( 'quiet' === $mode ) {
				$baselines[ (string) $component ] = array(
					'version' => self::version( (string) $component, $state ),
					'at'      => time(),
				);
				$changed                          = true;
			}
		}
		if ( $changed ) {
			update_option( self::BASELINES, $baselines, false );
		}
		self::$modes = array();
	}

	/**
	 * Which part of the site a file belongs to, or null for files this doesn't watch: WordPress's
	 * own files, plugins verified against WordPress.org, uploads and caches.
	 *
	 * @param string                $path    Absolute path (normalized).
	 * @param array<string, string> $plugins The scan's plugin statuses: slug => verified|differs|not_listed|unreachable.
	 */
	public static function component( string $path, array $plugins ): ?string {
		$mu = wp_normalize_path( WPMU_PLUGIN_DIR ) . '/';
		if ( str_starts_with( $path, $mu ) ) {
			$inside = substr( $path, strlen( $mu ) );
			$name   = str_contains( $inside, '/' ) ? (string) strtok( $inside, '/' ) : (string) preg_replace( '/\.php$/i', '', $inside );
			return 'mu:' . $name;
		}
		$folder = wp_normalize_path( WP_PLUGIN_DIR ) . '/';
		if ( str_starts_with( $path, $folder ) ) {
			$inside = substr( $path, strlen( $folder ) );
			$slug   = str_contains( $inside, '/' ) ? (string) strtok( $inside, '/' ) : $inside;
			return 'verified' === ( $plugins[ $slug ] ?? '' ) ? null : 'plugin:' . $slug;
		}
		foreach ( self::theme_roots() as $root ) {
			if ( str_starts_with( $path, $root ) ) {
				$inside = substr( $path, strlen( $root ) );
				return str_contains( $inside, '/' ) ? 'theme:' . strtok( $inside, '/' ) : null;
			}
		}
		$content = wp_normalize_path( WP_CONTENT_DIR ) . '/';
		return str_starts_with( $path, $content ) && ! str_contains( substr( $path, strlen( $content ) ), '/' ) ? 'dropins' : null;
	}

	/**
	 * A theme's functions.php, header.php or footer.php, or a must-use plugin's main file: where
	 * injected code usually goes, so their changes are high and their copies are kept.
	 *
	 * @param string $relative Path from the main folder.
	 */
	public static function is_risky( string $relative ): bool {
		$path = wp_normalize_path( ABSPATH . $relative );
		foreach ( self::theme_roots() as $root ) {
			if ( str_starts_with( $path, $root ) && 1 === preg_match( '~^[^/]+/(?:functions|header|footer)\.php$~i', substr( $path, strlen( $root ) ) ) ) {
				return true;
			}
		}
		$mu = wp_normalize_path( WPMU_PLUGIN_DIR ) . '/';
		return str_starts_with( $path, $mu ) && 1 === preg_match( '~^[^/]+\.php$~i', substr( $path, strlen( $mu ) ) );
	}

	/**
	 * The lines a file has that its earlier copy didn't (each counted once, blank lines skipped):
	 * [line number, text], in file order.
	 *
	 * @param string $before The earlier copy.
	 * @param string $after  The file now.
	 * @return array<int, array{0: int, 1: string}>
	 */
	public static function added_lines( string $before, string $after ): array {
		$old   = array_count_values( array_map( 'trim', preg_split( '/\R/', $before ) ?: array() ) );
		$added = array();
		foreach ( preg_split( '/\R/', $after ) ?: array() as $i => $line ) {
			$text = trim( $line );
			if ( '' === $text ) {
				continue;
			}
			if ( ! empty( $old[ $text ] ) ) {
				--$old[ $text ];
				continue;
			}
			$added[] = array( $i + 1, $text );
		}
		return $added;
	}

	/**
	 * Whether a line loads or prints something, hides code, or names another website.
	 *
	 * @param string $line Code.
	 */
	public static function is_risky_line( string $line ): bool {
		if ( 1 === preg_match( self::RISKY, $line ) ) {
			return true;
		}
		$own = self::bare_host( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		if ( preg_match_all( '~(?:https?:)?//([a-z0-9][a-z0-9.-]*\.[a-z]{2,})~i', $line, $m ) ) {
			foreach ( $m[1] as $host ) {
				$host = self::bare_host( $host );
				if ( $host !== $own && ! str_ends_with( $host, '.' . $own ) && ! in_array( $host, array( 'w3.org', 'www.w3.org', 'schema.org', 'wordpress.org', 'gmpg.org' ), true ) ) {
					return true;
				}
			}
		}
		return false;
	}

	// ----- Outside a scan -------------------------------------------------------------------

	/**
	 * "This is expected": the file as it is now becomes its recorded copy, so it isn't reported
	 * again until it changes.
	 *
	 * @param string $relative Path from the main folder.
	 */
	public static function accept( string $relative ): bool {
		$path = wp_normalize_path( ABSPATH . $relative );
		if ( str_contains( $relative, '..' ) || ! is_file( $path ) ) {
			self::delete_paths( array( $relative ) );
			return true;
		}
		$component = self::component( $path, self::plugin_statuses() );
		$stat      = @stat( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see check().
		$sha       = @hash_file( 'sha256', $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see check().
		if ( null === $component || false === $stat || false === $sha ) {
			return false;
		}
		self::record( $relative, $component, $path, $sha, $stat, self::is_risky( $relative ) );
		return true;
	}

	/**
	 * After WordPress's updater installed or updated plugins or themes: their files are recorded
	 * again, quietly, at the next scan.
	 *
	 * @param mixed                $upgrader The upgrader.
	 * @param array<string, mixed> $extra    What it did: type, action, plugins or themes.
	 */
	public static function after_update( $upgrader, $extra ): void {
		$extra = is_array( $extra ) ? $extra : array();
		$type  = (string) ( $extra['type'] ?? '' );
		$items = array();
		if ( 'plugin' === $type ) {
			$items = (array) ( $extra['plugins'] ?? ( isset( $extra['plugin'] ) ? array( $extra['plugin'] ) : array() ) );
			if ( array() === $items && is_object( $upgrader ) && method_exists( $upgrader, 'plugin_info' ) ) {
				$items = array( (string) $upgrader->plugin_info() );
			}
			$items = array_map( static fn ( $file ): string => 'plugin:' . ( str_contains( (string) $file, '/' ) ? strtok( (string) $file, '/' ) : (string) $file ), $items );
		} elseif ( 'theme' === $type ) {
			$items = (array) ( $extra['themes'] ?? ( isset( $extra['theme'] ) ? array( $extra['theme'] ) : array() ) );
			if ( array() === $items && is_object( $upgrader ) && method_exists( $upgrader, 'theme_info' ) ) {
				$theme = $upgrader->theme_info();
				$items = $theme instanceof \WP_Theme ? array( $theme->get_stylesheet() ) : array();
			}
			$items = array_map( static fn ( $slug ): string => 'theme:' . (string) $slug, $items );
		}
		foreach ( array_filter( $items, static fn ( string $c ): bool => ! in_array( $c, array( 'plugin:', 'theme:' ), true ) ) as $component ) {
			self::forget_component( $component );
		}
	}

	/**
	 * A plugin was deleted.
	 *
	 * @param string $file    Its main file.
	 * @param bool   $deleted Whether it was.
	 */
	public static function after_plugin_deleted( $file, $deleted ): void {
		if ( $deleted ) {
			self::forget_component( 'plugin:' . ( str_contains( (string) $file, '/' ) ? strtok( (string) $file, '/' ) : (string) $file ) );
		}
	}

	/**
	 * A theme was deleted.
	 *
	 * @param string $stylesheet Its folder.
	 * @param bool   $deleted    Whether it was.
	 */
	public static function after_theme_deleted( $stylesheet, $deleted ): void {
		if ( $deleted ) {
			self::forget_component( 'theme:' . (string) $stylesheet );
		}
	}

	/**
	 * Daily: when a risky file (a theme's functions.php, header.php or footer.php, a must-use
	 * plugin) differs from its recorded copy, scan now rather than at the weekly scan.
	 */
	public static function check_risky_files(): void {
		self::daily();
	}

	/**
	 * The daily check's work: whether a scan was started.
	 */
	public static function daily(): bool {
		if ( Scanner::running() || ! self::table_ready() ) {
			return false;
		}
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT path, sha256 FROM %i WHERE copy IS NOT NULL LIMIT 200', Schema::table( 'file_prints' ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		foreach ( (array) $rows as $row ) {
			$file = wp_normalize_path( ABSPATH . $row['path'] );
			$sha  = is_file( $file ) ? @hash_file( 'sha256', $file ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see check().
			if ( false !== $sha && $sha !== $row['sha256'] ) {
				return Scanner::start( 'changed' );
			}
		}
		return false;
	}

	/**
	 * The recorded copy of a risky file, if one is kept.
	 *
	 * @param string $relative Path from the main folder.
	 */
	public static function copy( string $relative ): ?string {
		$row = self::row( $relative );
		return null !== $row && is_string( $row['copy'] ) ? $row['copy'] : null;
	}

	/**
	 * How many files have fingerprints (the Health page, tests).
	 */
	public static function count(): int {
		global $wpdb;
		return self::table_ready() ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Schema::table( 'file_prints' ) ) ) : 0; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
	}

	/**
	 * Remove everything (uninstall, tests). The table goes with Schema::drop().
	 */
	public static function clear(): void {
		global $wpdb;
		delete_option( self::BASELINES );
		if ( self::table_ready() ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Schema::table( 'file_prints' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		}
		self::$modes = array();
	}

	// ----- Helpers --------------------------------------------------------------------------

	/**
	 * Watch a part of the site (it has a starting point at this version) or record it quietly.
	 * A new version since its starting point drops its old records first.
	 *
	 * @param string               $component Component.
	 * @param array<string, mixed> $state     The scan's state.
	 */
	private static function mode( string $component, array $state ): string {
		if ( isset( $state['prints'][ $component ] ) ) {
			return (string) $state['prints'][ $component ];
		}
		if ( ! isset( self::$modes[ $component ] ) ) {
			$baseline = self::baselines()[ $component ] ?? null;
			$mode     = 'quiet';
			if ( is_array( $baseline ) ) {
				if ( self::version( $component, $state ) === (string) $baseline['version'] ) {
					$mode = 'watch';
				} else {
					self::forget_component( $component );
				}
			}
			self::$modes[ $component ] = $mode;
		}
		return self::$modes[ $component ];
	}

	/**
	 * A part's version: a plugin's or theme's Version header, a must-use plugin's main file's.
	 *
	 * @param string               $component Component.
	 * @param array<string, mixed> $state     The scan's state (its list of plugins and versions).
	 */
	private static function version( string $component, array $state ): string {
		[ $kind, $name ] = array_pad( explode( ':', $component, 2 ), 2, '' );
		if ( 'plugin' === $kind ) {
			foreach ( (array) ( $state['plugin_queue'] ?? array() ) as $entry ) {
				if ( $entry[0] === $name ) {
					return (string) $entry[1];
				}
			}
			return '';
		}
		if ( 'theme' === $kind ) {
			$theme = wp_get_theme( $name );
			return $theme->exists() ? (string) $theme->get( 'Version' ) : '';
		}
		if ( 'mu' === $kind ) {
			$file = WPMU_PLUGIN_DIR . '/' . $name . '.php';
			if ( is_file( $file ) ) {
				$data = get_file_data( $file, array( 'Version' => 'Version' ) );
				return (string) $data['Version'];
			}
		}
		return '';
	}

	/**
	 * A finding for a new or changed file.
	 *
	 * @param string                    $relative Path from the main folder.
	 * @param string                    $path     Absolute path.
	 * @param string                    $sha      Its SHA-256 now.
	 * @param array<string, mixed>|null $row      Its record, if it has one.
	 * @return array<string, mixed>
	 */
	private static function finding( string $relative, string $path, string $sha, ?array $row ): array {
		$risky = self::is_risky( $relative );
		$size  = (int) @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see check().
		$now   = $size <= Scanner::MAX_READ ? @file_get_contents( $path ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file read.
		$lines = array();
		if ( null === $row ) {
			$type = 'file_added';
			if ( is_string( $now ) && $size <= self::MAX_COPY ) {
				$lines = self::added_lines( '', $now );
			}
			$level = $risky || ( is_string( $now ) && 1 === preg_match( self::HIDDEN, $now ) ) ? 'high' : 'medium';
		} else {
			$type = 'file_changed';
			if ( is_string( $now ) && is_string( $row['copy'] ) ) {
				$lines = self::added_lines( $row['copy'], $now );
			}
			$flagged = array_filter( $lines, static fn ( array $l ): bool => self::is_risky_line( $l[1] ) );
			$level   = $risky || array() !== $flagged ? 'high' : 'medium';
		}
		$first = null;
		foreach ( $lines as $line ) {
			if ( self::is_risky_line( $line[1] ) ) {
				$first = $line;
				break;
			}
		}
		$first                ??= $lines[0] ?? null;
		$finding                = Findings::make(
			$type,
			$level,
			array(
				'path'      => $relative,
				'line'      => null !== $first ? $first[0] : null,
				'excerpt'   => null !== $first ? mb_substr( $first[1], 0, 200 ) : null,
				'file_hash' => (string) @md5_file( $path ), // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see check().
				'sha256'    => $sha,
			)
		);
		$finding['all_lines']   = array_column( $lines, 1 );
		$finding['added_lines'] = array_map( static fn ( array $l ): string => mb_substr( $l[1], 0, 200 ), array_slice( $lines, 0, self::MAX_LINES ) );
		$finding['added_count'] = count( $lines );
		$finding['recorded_at'] = null !== $row ? (string) $row['recorded_at'] : null;
		return $finding;
	}

	/**
	 * Whether a change only added lines Arva SEO found safe (none added: not safe, it's unknown).
	 *
	 * @param array<int, string> $lines The added lines.
	 */
	private static function only_safe_lines( array $lines ): bool {
		foreach ( $lines as $line ) {
			if ( ! Feed::is_safe_line( $line ) ) {
				return false;
			}
		}
		return array() !== $lines;
	}

	/**
	 * Add a finding to the scan's (Scanner keeps the most severe when there are too many).
	 *
	 * @param array<string, mixed> $state   State.
	 * @param array<string, mixed> $finding Finding.
	 * @return array<string, mixed>
	 */
	private static function add( array $state, array $finding ): array {
		$state['findings'][] = $finding;
		return $state;
	}

	/**
	 * Record a file as it is now.
	 *
	 * @param string                 $relative  Path from the main folder.
	 * @param string                 $component Component.
	 * @param string                 $path      Absolute path.
	 * @param string                 $sha       SHA-256.
	 * @param array<int|string, int> $stat  Its stat().
	 * @param bool                   $risky     Keep a copy.
	 */
	private static function record( string $relative, string $component, string $path, string $sha, array $stat, bool $risky ): void {
		if ( ! self::table_ready() ) {
			return;
		}
		global $wpdb;
		$copy = null;
		if ( $risky && (int) $stat['size'] <= self::MAX_COPY ) {
			$read = @file_get_contents( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file read.
			$copy = is_string( $read ) && hash( 'sha256', $read ) === $sha ? $read : null;
		}
		$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
			Schema::table( 'file_prints' ),
			array(
				'path_hash'   => md5( $relative ),
				'path'        => $relative,
				'component'   => mb_substr( $component, 0, 200 ),
				'sha256'      => $sha,
				'size'        => (int) $stat['size'],
				'mtime'       => (int) $stat['mtime'],
				'copy'        => $copy,
				'recorded_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
		);
	}

	/**
	 * A file's record.
	 *
	 * @param string $relative Path from the main folder.
	 * @return array{sha256: string, size: int, mtime: int, copy: string|null, recorded_at: string}|null
	 */
	private static function row( string $relative ): ?array {
		if ( ! self::table_ready() ) {
			return null;
		}
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT sha256, size, mtime, copy, recorded_at FROM %i WHERE path_hash = %s', Schema::table( 'file_prints' ), md5( $relative ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- own table, one row by key.
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Forget a part's records and starting point: it's recorded again, quietly, at the next scan.
	 *
	 * @param string $component Component.
	 */
	private static function forget_component( string $component ): void {
		$baselines = self::baselines();
		if ( isset( $baselines[ $component ] ) ) {
			unset( $baselines[ $component ] );
			update_option( self::BASELINES, $baselines, false );
		}
		self::$modes[ $component ] = 'quiet';
		if ( self::table_ready() ) {
			global $wpdb;
			$wpdb->delete( Schema::table( 'file_prints' ), array( 'component' => $component ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		}
	}

	/**
	 * Remove records by path.
	 *
	 * @param array<int, string> $paths Paths from the main folder.
	 */
	private static function delete_paths( array $paths ): void {
		if ( ! self::table_ready() ) {
			return;
		}
		global $wpdb;
		foreach ( $paths as $relative ) {
			$wpdb->delete( Schema::table( 'file_prints' ), array( 'path_hash' => md5( $relative ) ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		}
	}

	/**
	 * The starting points.
	 *
	 * @return array<string, array{version: string, at: int}>
	 */
	private static function baselines(): array {
		$value = get_option( self::BASELINES, array() );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * The plugins' statuses from the last scan's coverage, for "This is expected" outside a scan:
	 * every plugin not checked against WordPress.org.
	 *
	 * @return array<string, string>
	 */
	private static function plugin_statuses(): array {
		$pattern = (array) ( Findings::latest()['coverage']['plugins_pattern'] ?? array() );
		$all     = array();
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( array_keys( get_plugins() ) as $file ) {
			$slug         = str_contains( $file, '/' ) ? (string) strtok( $file, '/' ) : $file;
			$all[ $slug ] = in_array( $slug, $pattern, true ) ? 'not_listed' : 'verified';
		}
		return $all;
	}

	/**
	 * The folders themes live in, normalized, with a trailing slash.
	 *
	 * @return array<int, string>
	 */
	private static function theme_roots(): array {
		global $wp_theme_directories;
		$roots = array_merge( (array) $wp_theme_directories, array( get_theme_root() ) );
		return array_values( array_unique( array_map( static fn ( $dir ): string => trailingslashit( wp_normalize_path( (string) $dir ) ), $roots ) ) );
	}

	/**
	 * Arva SEO's own page cache drop-in, which it writes again whenever its settings change.
	 *
	 * @param string $path Absolute path (normalized).
	 */
	private static function is_own_file( string $path ): bool {
		return wp_normalize_path( \ArvaSEO\Connector\Speed\PageCache::dropin() ) === $path && \ArvaSEO\Connector\Speed\PageCache::dropin_is_ours();
	}

	/**
	 * A host without www., lower case.
	 *
	 * @param string $host Host.
	 */
	private static function bare_host( string $host ): string {
		return (string) preg_replace( '/^www\./', '', strtolower( $host ) );
	}

	/**
	 * Whether the table exists (it's made on the first request after the update).
	 */
	private static function table_ready(): bool {
		return get_option( Schema::OPTION ) === Schema::VERSION;
	}
}
