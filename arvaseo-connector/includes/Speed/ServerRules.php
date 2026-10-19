<?php
/**
 * Browser caching and compression: a marked block of rules in .htaccess, on Apache and LiteSpeed.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Speed;

defined( 'ABSPATH' ) || exit;

/**
 * The rules give images, fonts, videos, styles and scripts a year-long browser cache and compress
 * pages, styles and scripts. They only use <IfModule> blocks, but a server that doesn't allow a
 * directive in .htaccess answers every request with an error, so every change is checked from the
 * server straight away: if the site doesn't answer normally, or can't be checked, the rules are
 * taken out again in the same request and the site is as before.
 */
final class ServerRules {

	public const MARKER = 'Arva SEO Connector';

	/** Types that get a year-long browser cache. WordPress and plugins version their files. */
	private const CACHE_TYPES = array(
		'image/jpeg',
		'image/png',
		'image/gif',
		'image/webp',
		'image/avif',
		'image/svg+xml',
		'image/x-icon',
		'image/vnd.microsoft.icon',
		'video/mp4',
		'video/webm',
		'font/woff2',
		'font/woff',
		'font/ttf',
		'application/font-woff2',
		'application/font-woff',
		'text/css',
		'text/javascript',
		'application/javascript',
	);

	/** Types that get compressed. */
	private const COMPRESS_TYPES = array(
		'text/html',
		'text/plain',
		'text/css',
		'text/xml',
		'text/javascript',
		'application/javascript',
		'application/json',
		'application/xml',
		'application/rss+xml',
		'application/atom+xml',
		'image/svg+xml',
		'font/ttf',
		'application/vnd.ms-fontobject',
	);

	/**
	 * Hooks: the wp-config.php switch takes the rules out too.
	 */
	public static function init(): void {
		add_action( 'admin_init', array( self::class, 'kill_switch' ) );
	}

	/**
	 * With every fix switched off in wp-config.php, the rules come out of .htaccess as well.
	 */
	public static function kill_switch(): void {
		if ( defined( Fixes::KILL_SWITCH ) && constant( Fixes::KILL_SWITCH ) && self::present() ) {
			self::disable();
		}
	}

	/**
	 * The plugin is deactivated or deleted: its rules come out and the fix is off, so nothing of
	 * it is left running. Turning the fix on again after reactivating adds them back.
	 */
	public static function remove_on_exit(): void {
		if ( is_multisite() ) {
			return;
		}
		if ( self::present() ) {
			self::disable();
		}
		// Straight into the setting: scheduled jobs are already cleared, so nothing is sent.
		$saved = get_option( Fixes::OPTION, array() );
		if ( is_array( $saved ) && 'on' === ( $saved['fixes']['browser_caching']['state'] ?? null ) ) {
			$saved['fixes']['browser_caching'] = array(
				'state' => 'off',
				'since' => null,
			);
			$saved['server_check']             = null;
			update_option( Fixes::OPTION, $saved, true );
		}
	}

	/**
	 * The rules, line by line.
	 *
	 * @return string[]
	 */
	public static function lines(): array {
		$lines = array(
			'# Long browser cache lifetimes, and compression. Added by Arva SEO → PageSpeed fixes;',
			'# turn off "Browser caching and compression" there to remove them.',
			'<IfModule mod_expires.c>',
			'	ExpiresActive On',
		);
		foreach ( self::CACHE_TYPES as $type ) {
			$lines[] = '	ExpiresByType ' . $type . ' "access plus 1 year"';
		}
		$lines[] = '</IfModule>';
		$lines[] = '<IfModule mod_brotli.c>';
		$lines[] = '	AddOutputFilterByType BROTLI_COMPRESS ' . implode( ' ', self::COMPRESS_TYPES );
		$lines[] = '</IfModule>';
		$lines[] = '<IfModule mod_deflate.c>';
		$lines[] = '	AddOutputFilterByType DEFLATE ' . implode( ' ', self::COMPRESS_TYPES );
		$lines[] = '</IfModule>';
		return $lines;
	}

	/**
	 * The same rules for nginx, for the host to add (nginx doesn't read .htaccess).
	 */
	public static function nginx(): string {
		return implode(
			"\n",
			array(
				'location ~* \.(?:jpe?g|png|gif|webp|avif|svg|ico|mp4|webm|woff2?|ttf|css|js)$ {',
				'    expires 1y;',
				'    add_header Cache-Control "public";',
				'}',
				'gzip on;',
				'gzip_types text/plain text/css text/xml text/javascript application/javascript application/json application/xml application/rss+xml image/svg+xml font/ttf;',
			)
		);
	}

	/**
	 * The .htaccess file WordPress uses.
	 */
	public static function file(): string {
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		return get_home_path() . '.htaccess';
	}

	/**
	 * Why the rules can't be written here, or null when they can.
	 */
	public static function unavailable(): ?string {
		global $is_apache;
		if ( is_multisite() ) {
			return __( 'Not on a multisite network: its sites share one .htaccess file. Ask your host to turn on browser caching and compression for the network.', 'arvaseo-connector' );
		}
		if ( ! $is_apache ) {
			return __( 'Your server doesn’t read .htaccess files (it’s probably nginx). Ask your host to add the rules below.', 'arvaseo-connector' );
		}
		$file = self::file();
		$dir  = dirname( $file );
		if ( file_exists( $file ) ? ! wp_is_writable( $file ) : ! wp_is_writable( $dir ) ) {
			return __( 'WordPress can’t write to your .htaccess file. Ask your host to make it writable, or to add the rules below.', 'arvaseo-connector' );
		}
		return null;
	}

	/**
	 * Whether the rules are in .htaccess.
	 */
	public static function present(): bool {
		$file = self::file();
		if ( ! is_readable( $file ) ) {
			return false;
		}
		$contents = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file.
		return str_contains( $contents, '# BEGIN ' . self::MARKER ) && str_contains( $contents, 'ExpiresActive On' );
	}

	/**
	 * Write the rules, then check the site still answers. Takes them out again if it doesn't.
	 *
	 * @return array{ok: bool, error: string|null, cache: bool, compression: string|null}
	 */
	public static function enable(): array {
		if ( null !== self::unavailable() ) {
			return self::result( false, 'unavailable' );
		}
		if ( ! self::write( self::lines() ) ) {
			return self::result( false, 'not_written' );
		}
		$check = self::check();
		if ( null !== $check['error'] ) {
			self::write( array() );
		}
		return $check;
	}

	/**
	 * Take the rules out.
	 */
	public static function disable(): bool {
		return ! file_exists( self::file() ) || self::write( array() );
	}

	/**
	 * Write the marked block (empty to take the rules out), leaving the rest of the file alone.
	 *
	 * @param string[] $lines Rules.
	 */
	private static function write( array $lines ): bool {
		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}
		return (bool) insert_with_markers( self::file(), self::MARKER, $lines );
	}

	/**
	 * Ask the site for its homepage and one of this plugin's files, from the server: both must
	 * answer normally. Also reports what the server applied.
	 *
	 * @return array{ok: bool, error: string|null, cache: bool, compression: string|null}
	 */
	public static function check(): array {
		$args  = array(
			'timeout'     => 10,
			'redirection' => 3,
			'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's filter for requests to the site itself.
			'decompress'  => false,
			'headers'     => array(
				'Accept-Encoding' => 'br, gzip, deflate',
				'Cache-Control'   => 'no-cache',
			),
		);
		$page  = wp_remote_get( home_url( '/' ), $args );
		$asset = wp_remote_get( plugins_url( 'assets/admin.css', ARVASEO_CONNECTOR_FILE ) . '?arvaseo-check=' . time(), $args );
		if ( is_wp_error( $page ) || is_wp_error( $asset ) ) {
			return self::result( false, 'unchecked' );
		}
		foreach ( array( $page, $asset ) as $response ) {
			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( $code >= 500 || 0 === $code ) {
				return self::result( false, 'server_error' );
			}
		}
		$expires  = (string) wp_remote_retrieve_header( $asset, 'expires' );
		$control  = (string) wp_remote_retrieve_header( $asset, 'cache-control' );
		$cache    = '' !== $expires || (bool) preg_match( '/max-age=(\d{7,})/', $control );
		$encoding = strtolower( (string) wp_remote_retrieve_header( $page, 'content-encoding' ) );
		return self::result( true, null, $cache, in_array( $encoding, array( 'br', 'gzip', 'deflate' ), true ) ? $encoding : null );
	}

	/**
	 * A check's result.
	 *
	 * @param bool        $ok          The site answered normally.
	 * @param string|null $error       unavailable, not_written, unchecked or server_error.
	 * @param bool        $cache       Static files got a long cache lifetime.
	 * @param string|null $compression The page's compression, if any.
	 * @return array{ok: bool, error: string|null, cache: bool, compression: string|null}
	 */
	private static function result( bool $ok, ?string $error, bool $cache = false, ?string $compression = null ): array {
		return array(
			'ok'          => $ok,
			'error'       => $error,
			'cache'       => $cache,
			'compression' => $compression,
		);
	}
}
