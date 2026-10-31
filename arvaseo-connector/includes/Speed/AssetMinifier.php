<?php
/**
 * Opt-in CSS and classic JavaScript minification, with disposable copies in uploads.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Speed;

defined( 'ABSPATH' ) || exit;

/**
 * Minifies one local static asset at a time. Original files, inline code, script order and loading
 * attributes are retained. Existing CSS/defer exclusions also apply. Source files are resolved
 * against WordPress's public content/includes directories and canonicalised before reading;
 * external URLs, dynamic endpoints, modules and scripts deriving paths from their own URL are skipped.
 */
final class AssetMinifier {

	/** Uploads subdirectory. */
	public const FOLDER = 'arvaseo-minified';

	/** Cache format, bumped when the library or path handling changes. */
	private const FORMAT = '1-minify-1.3.75';

	/** Individual source limit (1 MiB); per-page source budget (4 MiB). */
	private const MAX_BYTES  = 1048576;
	private const PAGE_BYTES = 4194304;

	/** New copies are stopped at these limits; cached pages keep their existing files. */
	private const MAX_FILES   = 250;
	private const CACHE_BYTES = 33554432;

	/**
	 * Result by source URL, including skips, for this request only.
	 *
	 * @var array<string, string|null>
	 */
	private static array $seen = array();

	/**
	 * Verified original URLs of copies delivered in this response.
	 *
	 * @var array<string,string>
	 */
	private static array $originals = array();

	/**
	 * A copy is trusted only after our own filter generated or verified it in this request.
	 *
	 * @param string $url Printed copy URL.
	 */
	public static function original_for( string $url ): ?string {
		return self::$originals[ $url ] ?? null;
	}

	/**
	 * Source bytes processed on this page.
	 *
	 * @var int
	 */
	private static int $bytes = 0;

	/**
	 * New files generated during this request.
	 *
	 * @var int
	 */
	private static int $generated = 0;

	/** Reset per-request safety budgets, also used by integration tests. */
	public static function reset(): void {
		self::$seen      = array();
		self::$originals = array();
		self::$bytes     = 0;
		self::$generated = 0;
	}

	/**
	 * Preserve Critical/unused CSS's link and noscript fallbacks while replacing their source.
	 * Source: https://developer.wordpress.org/reference/hooks/style_loader_tag/.
	 *
	 * @param mixed $tag    Style HTML.
	 * @param mixed $handle Registered handle.
	 * @param mixed $href   Original address.
	 * @param mixed $media  Media attribute.
	 * @return mixed
	 */
	public static function style_tag( $tag, $handle, $href = '', $media = 'all' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress filter contract; actual attributes may have changed in earlier filters.
		if ( ! is_string( $tag ) || ( ! Fixes::applies( 'minify_css' ) && ! Fixes::applies( 'font_display_swap' ) ) || in_array( (string) $handle, Fixes::stored()['css_exclude'], true ) ) {
			return $tag;
		}
		$tags = new \WP_HTML_Tag_Processor( $tag );
		while ( $tags->next_tag( array( 'tag_name' => 'link' ) ) ) {
			if ( 'stylesheet' !== $tags->get_attribute( 'rel' ) || null !== $tags->get_attribute( 'integrity' ) || null !== $tags->get_attribute( Exclusions::SKIP_ATTRIBUTE ) ) {
				continue;
			}
			$attribute = is_string( $tags->get_attribute( 'data-arvaseo-href' ) ) ? 'data-arvaseo-href' : 'href';
			$source    = $tags->get_attribute( $attribute );
			$url       = is_string( $source ) ? self::local_url( $source, 'css' ) : null;
			if ( null !== $url ) {
				$tags->set_attribute( $attribute, $url );
				$tags->set_attribute( Fixes::applies( 'minify_css' ) ? 'data-arvaseo-minified' : 'data-arvaseo-font-display', 'css' );
			}
		}
		return $tags->get_updated_html();
	}

	/**
	 * Classic external scripts only. Inline before/after tags and delayed-script markers stay intact.
	 * Source: https://developer.wordpress.org/reference/hooks/script_loader_tag/.
	 *
	 * @param mixed $tag    Script HTML.
	 * @param mixed $handle Registered handle.
	 * @param mixed $src    Original address.
	 * @return mixed
	 */
	public static function script_tag( $tag, $handle, $src = '' ) {
		if ( ! is_string( $tag ) || ! Fixes::applies( 'minify_js' ) || Exclusions::leaves_optimization( 'minify_js', (string) $handle, (string) $src ) ) {
			return $tag;
		}
		$tags = new \WP_HTML_Tag_Processor( $tag );
		while ( $tags->next_tag( array( 'tag_name' => 'script' ) ) ) {
			$type = strtolower( (string) $tags->get_attribute( 'type' ) );
			if ( ! in_array( $type, array( '', 'text/javascript', 'application/javascript', DelayScripts::TYPE ), true ) || null !== $tags->get_attribute( 'integrity' ) || null !== $tags->get_attribute( Exclusions::SKIP_ATTRIBUTE ) ) {
				continue;
			}
			$attribute = DelayScripts::TYPE === $type ? 'data-arvaseo-src' : 'src';
			$source    = $tags->get_attribute( $attribute );
			if ( ! is_string( $source ) || Exclusions::leaves_optimization( 'minify_js', (string) $handle, $source ) ) {
				continue;
			}
			$url = self::local_url( $source, 'js' );
			if ( null !== $url ) {
				$tags->set_attribute( $attribute, $url );
				$tags->set_attribute( 'data-arvaseo-minified', 'js' );
			}
		}
		return $tags->get_updated_html();
	}

	/**
	 * A public copy or null on every unsupported, unsafe or failed operation.
	 *
	 * @param string $source Public source URL.
	 * @param string $kind   css or js.
	 */
	private static function local_url( string $source, string $kind ): ?string {
		$swap   = 'css' === $kind && Fixes::applies( 'font_display_swap' );
		$minify = Fixes::applies( 'minify_' . $kind );
		$key    = $kind . '|' . (int) $swap . '|' . (int) $minify . '|' . $source;
		if ( array_key_exists( $key, self::$seen ) ) {
			return self::$seen[ $key ];
		}
		self::$seen[ $key ] = null;
		$asset              = $minify && Fixes::uses( 'minify_' . $kind, Changes::CHANGING_ADDRESSES ) ? Addresses::local_source( $source, $kind, $swap ) : self::source( $source, $kind, $swap );
		if ( null === $asset || count( self::$seen ) > 80 ) {
			return null;
		}
		$size = filesize( $asset['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_filesize -- canonical, bounded local static source.
		if ( false === $size || $size < 1 || $size > self::MAX_BYTES || self::$bytes + $size > self::PAGE_BYTES ) {
			return null;
		}
		self::$bytes += $size;
		$text         = file_get_contents( $asset['path'], false, null, 0, self::MAX_BYTES + 1 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- bounded read from a canonical allowlisted local file, never a URL.
		if ( false === $text || strlen( $text ) > self::MAX_BYTES || str_contains( $text, "\0" ) ) {
			return null;
		}
		// Moving these scripts changes their resource base; leave them at their original URL.
		if ( 'js' === $kind && null !== self::script_obstacle( $text ) && ! ( SafeAssetTrials::supported_amd( $text ) && SafeAssetTrials::approved( 'amd', $asset['url'], $text ) ) ) {
			return null;
		}

		// Moving @imports ahead of @layer order declarations can change the cascade.
		if ( 'css' === $kind && preg_match( '/@import\b/i', $text ) && preg_match( '/@layer\b/i', $text ) ) {
			return null;
		}
		try {
			$input = $swap ? FontDisplay::swap( $text ) : $text;
		} catch ( \Throwable $error ) {
			return null;
		}
		// Reuse 2.9 copies when their output is unchanged, including at the cache capacity limit.
		$format = self::FORMAT;
		if ( ! $minify || $input !== $text ) {
			$format .= '|' . (int) $swap . '|' . (int) $minify;
		}
		$name = hash( 'sha256', $format . '|' . $asset['url'] . '|' . $text ) . '.' . $kind;
		// Scripts were named after their full address until 2.10.2, cache busters included.
		$names = 'js' === $kind && $asset['original'] !== $asset['url'] ? array( $name, hash( 'sha256', $format . '|' . $asset['original'] . '|' . $text ) . '.' . $kind ) : array( $name );
		if ( ! self::same_origin( self::url() . '/' . $name, $asset['url'] ) || ( file_exists( self::dir() ) && ! self::safe_folder() ) ) {
			return null;
		}
		foreach ( $names as $existing ) {
			$file = self::dir() . '/' . $existing;
			if ( is_link( $file ) ) {
				return null;
			}
			if ( is_file( $file ) && is_readable( $file ) && filesize( $file ) > 0 ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_filesize -- generated static cache entry.
				AssetCache::used( $file );
				self::$seen[ $key ]                     = self::url() . '/' . $existing;
				self::$originals[ self::$seen[ $key ] ] = $asset['url'];
				if ( 'js' === $kind && 'amd' === self::script_obstacle( $text ) ) {
					SafeAssetTrials::applied( 'amd', hash( 'sha256', $text ), $asset['url'] );
				}
				return self::$seen[ $key ];
			}
		}
		$file = self::dir() . '/' . $name;
		$url  = self::url() . '/' . $name;
		if ( self::$generated >= 12 ) {
			return null;
		}
		try {
			if ( $minify ) {
				$minifier = 'css' === $kind ? new InlineCssMinifier( $input, $asset['url'] ) : new InlineJsMinifier( $input );
				$minified = $minifier->execute( 'css' === $kind ? $url : null );
			} else {
				$minified = $input !== $text ? AssetBundles::relocate_css( $input, $asset['url'] ) : null;
			}
		} catch ( \Throwable $error ) {
			return null;
		}
		if ( ! is_string( $minified ) || '' === $minified || $minified === $text || ( $input === $text && strlen( $minified ) >= strlen( $text ) ) ) {
			return null;
		}
		if ( ! self::room( strlen( $minified ) ) ) {
			AssetCache::full();
			return null;
		}
		$fs = self::filesystem();
		if ( null === $fs || ! wp_mkdir_p( self::dir() ) || ! self::safe_folder() ) {
			return null;
		}
		// Publish atomically, so another visitor never receives a partly written asset.
		$temp = self::dir() . '/.' . $name . '.' . wp_generate_password( 12, false, false ) . '.tmp';
		if ( ! $fs->put_contents( $temp, $minified, FS_CHMOD_FILE ) || ! $fs->move( $temp, $file, true ) ) {
			$fs->delete( $temp );
			return null;
		}
		++self::$generated;
		self::$seen[ $key ]      = $url;
		self::$originals[ $url ] = $asset['url'];
		if ( 'js' === $kind && 'amd' === self::script_obstacle( $text ) ) {
			SafeAssetTrials::applied( 'amd', hash( 'sha256', $text ), $asset['url'] );
		}
		return $url;
	}

	/**
	 * URL -> canonical local file. No HTTP request, arbitrary URL prefix or traversal is accepted.
	 *
	 * The combiner may also read existing minified copies; minification keeps its original skips.
	 *
	 * @param string $source         Original URL.
	 * @param string $kind           Expected extension.
	 * @param bool   $allow_minified Whether already-minified static files are eligible.
	 * @return array{path: string, url: string, original: string}|null
	 */
	public static function source( string $source, string $kind, bool $allow_minified = false ): ?array {
		if ( ! in_array( $kind, array( 'css', 'js' ), true ) ) {
			return null;
		}
		$home = wp_parse_url( home_url( '/' ) );
		if ( ! is_array( $home ) || ! isset( $home['scheme'], $home['host'] ) ) {
			return null;
		}
		$origin = $home['scheme'] . '://' . $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
		if ( str_starts_with( $source, '//' ) ) {
			$source = $home['scheme'] . ':' . $source;
		} elseif ( str_starts_with( $source, '/' ) ) {
			$source = $origin . $source;
		}
		$parts = wp_parse_url( $source );
		if ( ! is_array( $parts ) || ! self::same_origin( $source, home_url( '/' ) ) || isset( $parts['user'], $parts['pass'] ) || isset( $parts['fragment'] ) ) {
			return null;
		}
		$path = rawurldecode( (string) ( $parts['path'] ?? '' ) );
		if ( str_contains( $path, "\0" ) || str_contains( $path, '\\' ) || preg_match( '~(?:^|/)\.{1,2}(?:/|$)~', $path ) || ! str_ends_with( strtolower( $path ), '.' . $kind ) || ( ! $allow_minified && ( preg_match( '/[.-]min\.' . $kind . '$/i', $path ) || str_contains( $path, '/' . self::FOLDER . '/' ) ) ) ) {
			return null;
		}
		parse_str( (string) ( $parts['query'] ?? '' ), $query );
		if ( array_diff( array_keys( $query ), array( 'ver', 'v' ) ) ) {
			return null; // Query-dependent CSS/JS may be a generated endpoint rather than this file.
		}
		foreach ( array( array( content_url( '/' ), WP_CONTENT_DIR ), array( includes_url( '/' ), ABSPATH . WPINC ) ) as $mapping ) {
			$prefix = rawurldecode( (string) wp_parse_url( $mapping[0], PHP_URL_PATH ) );
			$root   = realpath( $mapping[1] );
			if ( false === $root || ! str_starts_with( $path, $prefix ) || ! self::same_origin( $source, $mapping[0] ) ) {
				continue;
			}
			$local = realpath( $root . '/' . substr( $path, strlen( $prefix ) ) );
			if ( false !== $local && str_starts_with( $local, $root . DIRECTORY_SEPARATOR ) && is_file( $local ) && is_readable( $local ) && str_ends_with( strtolower( $local ), '.' . $kind ) ) {
				return array(
					'path'     => $local,
					// Static cache busters change neither the bytes nor a stylesheet's resource base.
					// Some themes print time() as one (Solace: ?v=1792909342), which named a new copy
					// of every script on each page view until the cache was full. Normalize only
					// after the same-origin file and query allowlist are verified.
					'url'      => remove_query_arg( array( 'v', 'ver' ), $source ),
					'original' => $source,
				);
			}
		}
		return null;
	}

	/**
	 * Explain the existing relocation guard without changing its eligibility rules.
	 *
	 * @param string $text Source bytes, never retained in diagnostics.
	 */
	public static function script_obstacle( string $text ): ?string {
		if ( preg_match( '/\bdefine\s*\(/', $text ) ) {
			return 'amd';
		}
		$unsafe = preg_match( '/\b(?:import|export|importScripts)\b|\bnew\s+(?:URL|Worker|SharedWorker)\b|\bdocument\s*(?:\.\s*(?:currentScript|scripts)|\[\s*[\'\"]currentScript)/', $text );
		return 0 === $unsafe ? null : 'resource_base';
	}

	/**
	 * Same public origin, rejecting credentials and non-web protocols.
	 *
	 * @param string $first  First URL.
	 * @param string $second Second URL.
	 */
	public static function same_origin( string $first, string $second ): bool {
		$a = wp_parse_url( $first );
		$b = wp_parse_url( $second );
		return is_array( $a ) && is_array( $b ) && in_array( $a['scheme'] ?? '', array( 'http', 'https' ), true ) && ( $a['scheme'] ?? '' ) === ( $b['scheme'] ?? '' ) && strtolower( (string) ( $a['host'] ?? '' ) ) === strtolower( (string) ( $b['host'] ?? '' ) ) && ( $a['port'] ?? ( 'https' === $a['scheme'] ? 443 : 80 ) ) === ( $b['port'] ?? ( 'https' === ( $b['scheme'] ?? '' ) ? 443 : 80 ) ) && ! isset( $a['user'] ) && ! isset( $a['pass'] );
	}

	/**
	 * Keep up to 250 files / 32 MiB. Avoid evicting recent copies referenced by a page cache.
	 *
	 * @param int $bytes New file bytes.
	 */
	private static function room( int $bytes ): bool {
		$files = array_merge( glob( self::dir() . '/*.css' ) ?: array(), glob( self::dir() . '/*.js' ) ?: array() );
		$total = 0;
		foreach ( $files as $file ) {
			$total += (int) filesize( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_filesize -- generated static cache files.
		}
		return count( $files ) < AssetCache::limit( self::MAX_FILES ) && $total + $bytes <= AssetCache::limit( self::CACHE_BYTES );
	}

	/**
	 * Delete copies no page used since a time (AssetCache::sweep(), a day after the caches were cleared).
	 *
	 * @param int      $before Last use before this time.
	 * @param string[] $keep   File names that stay (the ones the live homepage links to).
	 * @return int Files deleted.
	 */
	public static function remove_unused( int $before, array $keep = array() ): int {
		return file_exists( self::dir() ) && self::safe_folder() ? AssetCache::remove_unused( self::dir(), $before, $keep ) : 0;
	}

	/**
	 * The folder the copies are in.
	 */
	public static function directory(): string {
		return self::dir();
	}

	/**
	 * Uninstall/manual purge only; caller must also clear page caches referencing these URLs.
	 */
	public static function delete_all(): void {
		$fs = self::filesystem();
		if ( null !== $fs && self::safe_folder() ) {
			$fs->delete( self::dir(), true );
		}
		self::reset();
	}

	/** Cache directory is inside this site's uploads, never a symlink escape. */
	private static function safe_folder(): bool {
		$uploads = realpath( (string) wp_get_upload_dir()['basedir'] );
		$folder  = realpath( self::dir() );
		return false !== $uploads && false !== $folder && str_starts_with( $folder, $uploads . DIRECTORY_SEPARATOR );
	}

	/** Current site's cache directory. */
	private static function dir(): string {
		return untrailingslashit( (string) wp_get_upload_dir()['basedir'] ) . '/' . self::FOLDER;
	}

	/** Current site's public cache URL. */
	private static function url(): string {
		return untrailingslashit( set_url_scheme( (string) wp_get_upload_dir()['baseurl'] ) ) . '/' . self::FOLDER;
	}

	/** Direct filesystem access only: never ask for FTP credentials during a page request. */
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
