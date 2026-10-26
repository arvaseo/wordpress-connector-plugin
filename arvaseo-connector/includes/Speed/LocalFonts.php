<?php
/**
 * Faster Google Fonts, "Serve them from your site": copies of the Google Fonts a page uses, kept in
 * the uploads folder and linked instead of Google's.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Speed;

use ArvaSEO\Connector\Logging\AuditLogger;

defined( 'ABSPATH' ) || exit;

/**
 * A stylesheet from fonts.googleapis.com is copied once, in the background: the stylesheet, then
 * the WOFF2 files it names, with their addresses changed to the copies. Until the copy is ready,
 * the page keeps Google's address, so nothing is ever missing. Visitors' browsers then don't
 * connect to Google for fonts at all. Google Fonts are open-source licensed, so copies are allowed.
 */
final class LocalFonts {

	public const HOOK = 'arvaseo_connector_copy_fonts';

	/** Folder inside uploads. */
	public const FOLDER = 'arvaseo-fonts';

	/** At most this many font files per stylesheet, of at most this size. */
	private const MAX_FILES = 40;

	private const MAX_BYTES = 1048576;

	/** Google sends WOFF2 to a current browser. */
	private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';

	/**
	 * Hooks: the background copy.
	 */
	public static function init(): void {
		add_action( self::HOOK, array( self::class, 'copy' ) );
	}

	/**
	 * The copy of a Google Fonts stylesheet, or null while there isn't one (a copy is then
	 * scheduled, once).
	 *
	 * @param string $src The stylesheet's address on fonts.googleapis.com.
	 */
	public static function local_url( string $src ): ?string {
		$key  = self::key( $src );
		$file = self::dir() . '/' . $key . '.css';
		if ( file_exists( $file ) ) {
			return self::url() . '/' . $key . '.css';
		}
		if ( ! wp_next_scheduled( self::HOOK, array( $src ) ) && ! get_transient( 'arvaseo_connector_fonts_failed_' . $key ) ) {
			wp_schedule_single_event( time(), self::HOOK, array( $src ) );
		}
		return null;
	}

	/**
	 * Whether a stylesheet has its copy.
	 *
	 * @param string $src The stylesheet's address.
	 */
	public static function has_copy( string $src ): bool {
		return file_exists( self::dir() . '/' . self::key( $src ) . '.css' );
	}

	/**
	 * Copy one stylesheet and its fonts (WP-Cron).
	 *
	 * @param mixed $src The stylesheet's address.
	 */
	public static function copy( $src ): void {
		if ( ! is_string( $src ) || ! self::is_google_css( $src ) ) {
			return;
		}
		$key = self::key( $src );
		$css = self::fetch( self::absolute( $src ), 262144 );
		if ( null === $css || ! str_contains( $css, '@font-face' ) ) {
			self::failed( $key, 'stylesheet' );
			return;
		}
		$fs = self::filesystem();
		if ( null === $fs || ! wp_mkdir_p( self::dir() . '/' . $key ) ) {
			self::failed( $key, 'folder' );
			return;
		}
		preg_match_all( '~url\(\s*[\'"]?(https://fonts\.gstatic\.com/[^\'")\s]+)[\'"]?\s*\)~i', $css, $m );
		$files = array_slice( array_values( array_unique( $m[1] ) ), 0, self::MAX_FILES );
		foreach ( $files as $font ) {
			$body = self::fetch( $font, self::MAX_BYTES );
			$name = sanitize_file_name( basename( (string) wp_parse_url( $font, PHP_URL_PATH ) ) );
			if ( null === $body || '' === $name || ! $fs->put_contents( self::dir() . '/' . $key . '/' . $name, $body, FS_CHMOD_FILE ) ) {
				self::failed( $key, 'font' );
				return;
			}
			$css = str_replace( $font, self::url() . '/' . $key . '/' . $name, $css );
		}
		// Text shows straight away in a similar font while each one loads.
		$css = (string) preg_replace( '/font-display:\s*[a-z]+;?/i', '', $css );
		$css = str_replace( '@font-face {', "@font-face {\n  font-display: swap;", $css );
		if ( ! $fs->put_contents( self::dir() . '/' . $key . '.css', "/* Copied from Google Fonts by Arva SEO → PageSpeed fixes. */\n" . $css, FS_CHMOD_FILE ) ) {
			self::failed( $key, 'stylesheet_write' );
			return;
		}
		Fixes::pages_changed( 'fonts' );
		AuditLogger::info(
			'speed.fonts_copied',
			/* translators: %d: number of font files. */
			sprintf( _n( 'PageSpeed fixes: Google Fonts copied to your site (%d file).', 'PageSpeed fixes: Google Fonts copied to your site (%d files).', count( $files ), 'arvaseo-connector' ), count( $files ) ),
			array( 'files' => count( $files ) )
		);
	}

	/**
	 * How many stylesheets have been copied.
	 */
	public static function copied(): int {
		$files = glob( self::dir() . '/*.css' );
		return is_array( $files ) ? count( $files ) : 0;
	}

	/**
	 * Remove every copy (uninstall, or when asked).
	 */
	public static function delete_all(): void {
		$fs = self::filesystem();
		if ( null !== $fs && $fs->is_dir( self::dir() ) ) {
			$fs->rmdir( self::dir(), true );
		}
	}

	/**
	 * Whether an address is a Google Fonts stylesheet.
	 *
	 * @param string $src Address.
	 */
	public static function is_google_css( string $src ): bool {
		return (bool) preg_match( '~^(?:https?:)?//fonts\.googleapis\.com/css2?\?~i', $src );
	}

	/**
	 * A stylesheet's copy name: the same families, weights and text give the same copy, whatever
	 * cache buster (v, ver) or display a theme adds. Some themes add v=time() to every Google Fonts
	 * address; that used to name a new copy on every page view, so the copy was never used, a new
	 * download was scheduled each time and each finished copy cleared the page caches again.
	 * css2 addresses can name several families with repeated family= parameters, which
	 * remove_query_arg() collapses to the last one: those keep every other parameter, in order.
	 *
	 * @param string $src Address.
	 */
	private static function key( string $src ): string {
		$url   = self::absolute( $src );
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		$pairs = '' === $query ? array() : explode( '&', $query );
		$names = array_map( static fn ( string $pair ): string => strtolower( rawurldecode( explode( '=', $pair, 2 )[0] ) ), $pairs );
		if ( count( $names ) === count( array_unique( $names ) ) ) {
			// One of each: the name 2.10.3 and earlier gave it without a cache buster, so existing copies stay in use.
			return substr( md5( (string) remove_query_arg( array( 'display', 'ver', 'v' ), $url ) ), 0, 16 );
		}
		$kept = array();
		foreach ( $pairs as $i => $pair ) {
			if ( ! in_array( $names[ $i ], array( 'display', 'ver', 'v' ), true ) ) {
				$kept[] = $pair;
			}
		}
		return substr( md5( 'families|' . strtok( $url, '?' ) . '?' . implode( '&', $kept ) ), 0, 16 );
	}

	/**
	 * A protocol-relative address made https.
	 *
	 * @param string $src Address.
	 */
	private static function absolute( string $src ): string {
		return str_starts_with( $src, '//' ) ? 'https:' . $src : $src;
	}

	/**
	 * Download a file, or null.
	 *
	 * @param string $url   Address.
	 * @param int    $limit Largest size accepted.
	 */
	private static function fetch( string $url, int $limit ): ?string {
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 15,
				'user-agent'          => self::USER_AGENT,
				'limit_response_size' => $limit,
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$body = (string) wp_remote_retrieve_body( $response );
		return '' === $body || strlen( $body ) >= $limit ? null : $body;
	}

	/**
	 * A copy that couldn't be made: Google's address stays, and it isn't tried again for a day.
	 *
	 * @param string $key    Copy name.
	 * @param string $reason What failed.
	 */
	private static function failed( string $key, string $reason ): void {
		set_transient( 'arvaseo_connector_fonts_failed_' . $key, $reason, DAY_IN_SECONDS );
		AuditLogger::warning( 'speed.fonts_not_copied', __( 'PageSpeed fixes: Google Fonts couldn’t be copied to your site, so Google’s address stays. It’s tried again tomorrow.', 'arvaseo-connector' ), array( 'reason' => $reason ) );
	}

	/**
	 * The copies' folder.
	 */
	private static function dir(): string {
		return untrailingslashit( (string) wp_get_upload_dir()['basedir'] ) . '/' . self::FOLDER;
	}

	/**
	 * The copies' address.
	 */
	private static function url(): string {
		return untrailingslashit( set_url_scheme( (string) wp_get_upload_dir()['baseurl'] ) ) . '/' . self::FOLDER;
	}

	/**
	 * WordPress's file functions, writing directly (cron has nobody to ask for FTP details).
	 */
	private static function filesystem(): ?\WP_Filesystem_Base {
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$uploads = (string) wp_get_upload_dir()['basedir'];
		if ( 'direct' !== get_filesystem_method( array(), $uploads, true ) || ! WP_Filesystem( false, $uploads, true ) ) {
			return null;
		}
		global $wp_filesystem;
		return $wp_filesystem instanceof \WP_Filesystem_Base ? $wp_filesystem : null;
	}
}
