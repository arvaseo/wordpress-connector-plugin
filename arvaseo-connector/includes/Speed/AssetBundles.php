<?php
/**
 * Immutable local bundles of compatible CSS or classic JavaScript files.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Speed;

defined( 'ABSPATH' ) || exit;

/** Bounded, same-origin static-file reads and atomic writes; never fetches URLs. */
final class AssetBundles {

	public const FOLDER = 'arvaseo-combined';

	private const FORMAT      = '1';
	private const MAX_BYTES   = 1048576;
	private const PAGE_BYTES  = 4194304;
	private const MAX_FILES   = 250;
	private const CACHE_BYTES = 33554432;

	/**
	 * Source reads for this request.
	 *
	 * @var array<string, array{text: string, url: string}|null>
	 */
	private static array $sources = array();

	/**
	 * Source bytes processed during this request.
	 *
	 * @var int
	 */
	private static int $bytes = 0;

	/**
	 * New bundles published during this request.
	 *
	 * @var int
	 */
	private static int $generated = 0;

	/** Forget request-local source results and budgets. */
	public static function reset(): void {
		self::$sources   = array();
		self::$bytes     = 0;
		self::$generated = 0;
	}

	/**
	 * Combine all sources or leave the entire group unchanged on any unsupported operation.
	 *
	 * @param string[] $sources Ordered public addresses.
	 * @param string   $kind    css or js.
	 */
	public static function create( array $sources, string $kind ): ?string {
		if ( ! in_array( $kind, array( 'css', 'js' ), true ) || count( $sources ) < 2 || count( $sources ) > 80 ) {
			return null;
		}
		$assets = array();
		foreach ( $sources as $source ) {
			$asset = is_string( $source ) ? self::read( $source, $kind ) : null;
			if ( null === $asset ) {
				return null;
			}
			$assets[] = $asset;
		}
		$name = hash( 'sha256', self::FORMAT . '|' . $kind . '|' . wp_json_encode( $assets ) ) . '.' . $kind;
		$file = self::dir() . '/' . $name;
		$url  = self::url() . '/' . $name;
		if ( ! AssetMinifier::same_origin( $url, home_url( '/' ) ) || is_link( $file ) || ( file_exists( self::dir() ) && ! self::safe_folder() ) ) {
			return null;
		}
		if ( is_file( $file ) && is_readable( $file ) && filesize( $file ) > 0 ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_filesize -- immutable generated static file.
			return $url;
		}
		if ( self::$generated >= 12 ) {
			return null;
		}
		$parts = array();
		foreach ( $assets as $asset ) {
			$text = 'css' === $kind ? self::css( $asset['text'], $asset['url'] ) : $asset['text'];
			if ( null === $text ) {
				return null;
			}
			$parts[] = $text;
		}
		// A line comment or an unterminated ASI boundary must not consume the following file.
		$text = implode( 'js' === $kind ? "\n;\n" : "\n", $parts ) . "\n";
		$fs   = self::filesystem();
		if ( null === $fs || ! self::room( strlen( $text ) ) || ! wp_mkdir_p( self::dir() ) || ! self::safe_folder() ) {
			return null;
		}
		$temp = self::dir() . '/.' . $name . '.' . wp_generate_password( 12, false, false ) . '.tmp';
		if ( ! $fs->put_contents( $temp, $text, FS_CHMOD_FILE ) || ! $fs->move( $temp, $file, true ) ) {
			$fs->delete( $temp );
			return null;
		}
		++self::$generated;
		return $url;
	}

	/**
	 * Canonical local source, with bounded reads and syntax that is safe to relocate.
	 *
	 * @param string $source Public source URL.
	 * @param string $kind   Expected extension.
	 * @return array{text: string, url: string}|null
	 */
	private static function read( string $source, string $kind ): ?array {
		$key = $kind . '|' . $source;
		if ( array_key_exists( $key, self::$sources ) ) {
			return self::$sources[ $key ];
		}
		self::$sources[ $key ] = null;
		if ( count( self::$sources ) > 80 || str_contains( $source, '/' . self::FOLDER . '/' ) ) {
			return null;
		}
		$asset = AssetMinifier::source( $source, $kind, true );
		if ( null === $asset ) {
			return null;
		}
		$size = filesize( $asset['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_filesize -- canonical public static asset.
		if ( false === $size || $size < 1 || $size > self::MAX_BYTES || self::$bytes + $size > self::PAGE_BYTES ) {
			return null;
		}
		self::$bytes += $size;
		$text         = file_get_contents( $asset['path'], false, null, 0, self::MAX_BYTES + 1 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- bounded canonical static asset, never a URL.
		if ( false === $text || strlen( $text ) > self::MAX_BYTES || str_contains( $text, "\0" ) || 1 !== preg_match( '//u', $text ) ) {
			return null;
		}
		$text = str_starts_with( $text, "\xEF\xBB\xBF" ) ? substr( $text, 3 ) : $text;
		if ( 'js' === $kind ) {
			// These features depend on the original script URL or its separate execution scope.
			$unsafe = preg_match( '/\b(?:import|export|importScripts|currentScript)\b|\bnew\s+(?:URL|Worker|SharedWorker)\b|\bdocument\s*(?:\.\s*(?:currentScript|scripts|write|writeln)|\[\s*[\'\"](?:currentScript|scripts|write|writeln))|\bdocument\s*\[|\bdefine\s*\(/', $text );
			$start  = preg_replace( '~\A(?:\s+|/\*.*?\*/|//[^\r\n]*(?:\r?\n|\z))*~s', '', $text );
			if ( 0 !== $unsafe || null === $start || preg_match( '/\A[\'\"]/', $start ) ) {
				return null; // Leading string directives, including use strict, cannot change scope.
			}
		}
		self::$sources[ $key ] = array(
			'text' => $text,
			'url'  => $asset['url'],
		);
		return self::$sources[ $key ];
	}

	/**
	 * Relocate active CSS url() tokens, preserving comments, string content and formatting.
	 * Unsupported escapes/directives leave the complete original group intact.
	 *
	 * @param string $text   Original CSS.
	 * @param string $source Original public address.
	 */
	private static function css( string $text, string $source ): ?string {
		$out    = '';
		$length = strlen( $text );
		for ( $i = 0; $i < $length; ) {
			if ( '/*' === substr( $text, $i, 2 ) ) {
				$end = strpos( $text, '*/', $i + 2 );
				if ( false === $end ) {
					return null;
				}
				$out .= substr( $text, $i, $end + 2 - $i );
				$i    = $end + 2;
				continue;
			}
			if ( '"' === $text[ $i ] || "'" === $text[ $i ] ) {
				$end = self::string_end( $text, $i );
				if ( null === $end ) {
					return null;
				}
				$out .= substr( $text, $i, $end + 1 - $i );
				$i    = $end + 1;
				continue;
			}
			if ( '\\' === $text[ $i ] || ( '@' === $text[ $i ] && preg_match( '/\A@(?:import|charset|namespace)\b/i', substr( $text, $i ) ) ) ) {
				return null;
			}
			// image-set() accepts bare URL strings that cannot be relocated like url() tokens.
			if ( ( 0 === $i || ! preg_match( '/[a-z0-9_-]/i', $text[ $i - 1 ] ) ) && preg_match( '/\A(?:-webkit-)?image-set\s*\(/i', substr( $text, $i ) ) ) {
				return null;
			}
			if ( 'url(' !== strtolower( substr( $text, $i, 4 ) ) || ( $i > 0 && preg_match( '/[a-z0-9_-]/i', $text[ $i - 1 ] ) ) ) {
				$out .= $text[ $i++ ];
				continue;
			}
			$start = $i;
			$i    += 4;
			while ( $i < $length && ctype_space( $text[ $i ] ) ) {
				++$i;
			}
			if ( $i >= $length ) {
				return null;
			}
			if ( '"' === $text[ $i ] || "'" === $text[ $i ] ) {
				$end = self::string_end( $text, $i );
				if ( null === $end ) {
					return null;
				}
				$value = substr( $text, $i + 1, $end - $i - 1 );
				$i     = $end + 1;
				while ( $i < $length && ctype_space( $text[ $i ] ) ) {
					++$i;
				}
				if ( $i >= $length || ')' !== $text[ $i ] ) {
					return null;
				}
			} else {
				$end = strpos( $text, ')', $i );
				if ( false === $end ) {
					return null;
				}
				$value = trim( substr( $text, $i, $end - $i ) );
				$i     = $end;
				if ( preg_match( '/[\s\'\"(]/', $value ) ) {
					return null;
				}
			}
			if ( str_contains( $value, '\\' ) ) {
				return null;
			}
			if ( '' === $value || preg_match( '/^(?:#|\/|[a-z][a-z0-9+.-]*:)/i', $value ) ) {
				$out .= substr( $text, $start, $i + 1 - $start );
			} else {
				$url  = \WP_Http::make_absolute_url( $value, $source );
				$out .= 'url("' . str_replace( array( '\\', '"', "\r", "\n", "\f" ), array( '\\\\', '\\"', '\\D ', '\\A ', '\\C ' ), $url ) . '")';
			}
			++$i;
		}
		return $out;
	}

	/**
	 * Closing quote, skipping escaped characters.
	 *
	 * @param string $text  CSS text.
	 * @param int    $start Opening quote offset.
	 */
	private static function string_end( string $text, int $start ): ?int {
		$length = strlen( $text );
		for ( $i = $start + 1; $i < $length; ++$i ) {
			if ( '\\' === $text[ $i ] ) {
				++$i;
			} elseif ( $text[ $start ] === $text[ $i ] ) {
				return $i;
			}
		}
		return null;
	}

	/**
	 * Cache files are kept while existing page caches may still reference them.
	 *
	 * @param int $bytes Size of the proposed new bundle.
	 */
	private static function room( int $bytes ): bool {
		$files = array_merge( glob( self::dir() . '/*.css' ) ?: array(), glob( self::dir() . '/*.js' ) ?: array() );
		$total = 0;
		foreach ( $files as $file ) {
			$total += (int) filesize( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_filesize -- generated static files only.
		}
		return count( $files ) < self::MAX_FILES && $total + $bytes <= self::CACHE_BYTES;
	}

	/** Uninstall or manual purge only; page caches must be invalidated by the caller. */
	public static function delete_all(): void {
		$fs = self::filesystem();
		if ( null !== $fs && self::safe_folder() ) {
			// Generated bundles are direct files. Never descend into an unexpected directory/link.
			$entries = scandir( self::dir() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_scandir -- bounded own-cache directory, no recursion.
			foreach ( is_array( $entries ) ? $entries : array() as $name ) {
				if ( '.' === $name || '..' === $name ) {
					continue;
				}
				$file = self::dir() . '/' . $name;
				if ( is_link( $file ) || is_file( $file ) ) {
					$fs->delete( $file, false, 'f' );
				}
			}
			$fs->rmdir( self::dir(), false );
		}
		self::reset();
	}

	/** Cache directory must remain within this site's canonical uploads directory. */
	private static function safe_folder(): bool {
		$uploads = realpath( (string) wp_get_upload_dir()['basedir'] );
		$folder  = realpath( self::dir() );
		return ! is_link( self::dir() ) && false !== $uploads && false !== $folder && str_starts_with( $folder, $uploads . DIRECTORY_SEPARATOR );
	}

	/** Current site's private filesystem path for public static bundles. */
	private static function dir(): string {
		return untrailingslashit( (string) wp_get_upload_dir()['basedir'] ) . '/' . self::FOLDER;
	}

	/** Public bundle address. */
	private static function url(): string {
		return untrailingslashit( set_url_scheme( (string) wp_get_upload_dir()['baseurl'] ) ) . '/' . self::FOLDER;
	}

	/** Direct filesystem access only, without prompting for FTP credentials. */
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
