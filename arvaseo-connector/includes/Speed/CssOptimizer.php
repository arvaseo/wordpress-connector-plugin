<?php
/**
 * Load styles faster (critical CSS) and Remove unused CSS: CSS worked out by Arva SEO's renderer,
 * one page optimization per page type.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Speed;

use ArvaSEO\Connector\Api\ApiClient;
use ArvaSEO\Connector\Auth\TokenStore;
use ArvaSEO\Connector\Install\Capabilities;
use ArvaSEO\Connector\Logging\AuditLogger;

defined( 'ABSPATH' ) || exit;

/**
 * Pages are grouped by type (homepage, page, post, product, category…) and by the stylesheets
 * WordPress prints in their head, their files' contents included: a signature. A signature without
 * results is sent to Arva SEO, which loads that page as a phone and as a computer and works out each
 * stylesheet's critical and used CSS; the plugin fetches them and keeps them in uploads/arvaseo-css.
 * A changed stylesheet makes a new signature, so CSS that no longer matches is never used, and a
 * cache clear costs nothing. While Arva SEO renders a page (a short-lived signed parameter), the
 * page keeps the same CSS preparation as visitors, without generated CSS or page caching.
 */
final class CssOptimizer {

	public const INDEX = 'arvaseo_connector_css_index';

	/** Files that can reach the 404 template when they're missing; never page types. */
	private const NOT_PAGES = '/\.(?:css|m?js|map|json|webmanifest|xml|txt|ico|png|jpe?g|gif|webp|avif|svg|woff2?|ttf|otf|eot|mp4|webm|mp3|pdf|zip|gz)$/i';

	public const HOOK = 'arvaseo_connector_css_sync';

	public const FOLDER = 'arvaseo-css';

	/** At most this many page types are kept track of. */
	private const MAX_ENTRIES = 300;

	/** External used-CSS copies stay bounded and disposable, like their JSON source results. */
	private const MAX_USED_BYTES = 500000;

	private const USED_CACHE_BYTES = 33554432;

	/** Sent to Arva SEO at most per sync. */
	private const BATCH = 20;

	/** Fetch CSS one result at a time, with bounded work per scheduled sync. */
	private const RESULTS_PER_SYNC = 10;

	/** Leave longer backlogs to the next scheduled sync instead of holding an admin page open. */
	private const FETCH_SECONDS = 10;

	/** Handles the toolbar adds for signed-in people: never part of a signature, never changed. */
	private const SIGNED_IN_HANDLES = array( 'admin-bar', 'dashicons', 'arvaseo-speed-facade' );

	/**
	 * Whether a stylesheet is only there for signed-in people: the toolbar's own, and any other
	 * plugin's toolbar styles (they depend on admin-bar or are named after it, like Yoast SEO's
	 * yoast-seo-adminbar). Left out of the signature, so an administrator trying a fix gets the
	 * same page type, and the same results, as visitors.
	 *
	 * @param string               $handle Style handle.
	 * @param \_WP_Dependency|null $style  Its registration.
	 */
	public static function signed_in_only( string $handle, $style ): bool {
		if ( in_array( $handle, self::SIGNED_IN_HANDLES, true ) || 1 === preg_match( '/admin[-_]?bar/i', $handle ) ) {
			return true;
		}
		$deps   = null === $style ? array() : (array) $style->deps;
		$hidden = (bool) apply_filters( 'arvaseo_connector_css_signed_in_only', array() !== array_intersect( $deps, array( 'admin-bar' ) ), $handle );
		return $hidden;
	}

	/**
	 * Whether this request is Arva SEO's renderer loading the page.
	 *
	 * @var bool|null
	 */
	private static ?bool $rendering = null;

	/**
	 * This page's signature and results, once worked out.
	 *
	 * @var array{signature: string, template: string, sheets: array<string, array{used: string, critical: string, href: string}>}|null
	 */
	private static ?array $page = null;

	/**
	 * Print one loader even if the theme invokes its footer more than once.
	 *
	 * @var bool
	 */
	private static bool $loader_printed = false;

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( self::HOOK, array( self::class, 'cron_sync' ) );
	}

	/**
	 * The scheduled sync.
	 */
	public static function cron_sync(): void {
		self::sync();
	}

	/**
	 * A new page.
	 */
	public static function reset(): void {
		self::$page           = null;
		self::$rendering      = null;
		self::$loader_printed = false;
	}

	// ----- Arva SEO's renderer -----------------------------------------------------------------

	/**
	 * Whether Arva SEO's renderer is loading this page: a valid, unexpired signed parameter.
	 */
	public static function is_render_request(): bool {
		if ( null !== self::$rendering ) {
			return self::$rendering;
		}
		$token           = isset( $_GET['arvaseo_render'] ) ? sanitize_text_field( wp_unslash( $_GET['arvaseo_render'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a signed token, checked below.
		self::$rendering = '' !== $token && self::valid_token( $token );
		return self::$rendering;
	}

	/**
	 * A token Arva SEO signed with one of this site's signing keys: key ID, expiry, HMAC.
	 *
	 * @param string $token Token.
	 */
	public static function valid_token( string $token ): bool {
		$parts = explode( '.', $token );
		if ( 3 !== count( $parts ) || ! ctype_digit( $parts[1] ) || (int) $parts[1] < time() || (int) $parts[1] > time() + 900 ) {
			return false;
		}
		$credentials = TokenStore::credentials();
		foreach ( (array) ( $credentials['signing_keys'] ?? array() ) as $key ) {
			if ( ( $key['id'] ?? '' ) !== $parts[0] ) {
				continue;
			}
			$expected = rtrim( strtr( base64_encode( hash_hmac( 'sha256', 'arvaseo-render|' . $parts[1], (string) $key['secret'], true ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64url of an HMAC.
			return hash_equals( $expected, $parts[2] );
		}
		return false;
	}

	/**
	 * For the renderer: the page as it is without these fixes, and never from or into a page cache.
	 */
	public static function serve_original(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- the constant page caches read.
		}
		nocache_headers();
	}

	// ----- On a page -----------------------------------------------------------------------------

	/**
	 * Hooks for a page while Load styles faster or Remove unused CSS is on or being tried: page
	 * types are noted from every page anyone sees; the CSS is used where the fix applies.
	 */
	public static function on_page(): void {
		self::$page = null;
		add_action( 'wp_head', array( self::class, 'prepare' ), 2 );
		add_filter( 'style_loader_tag', array( self::class, 'style_tag' ), 20, 4 );
		add_action( 'wp_footer', array( self::class, 'loader' ), PHP_INT_MAX );
	}

	/**
	 * After the theme and plugins enqueued their styles (wp_head 1): work out this page's signature,
	 * use its results if there are any, note the page for Arva SEO if not, and print the critical CSS.
	 */
	public static function prepare(): void {
		if ( self::combined_mode() ) {
			return; // Final printed bundles, not the original enqueue handles, define this result.
		}
		$styles = wp_styles();
		$styles->all_deps( $styles->queue );
		$sheets = array();
		foreach ( $styles->to_do as $handle ) {
			$style = $styles->registered[ $handle ] ?? null;
			if ( null === $style || ! is_string( $style->src ) || '' === $style->src || self::signed_in_only( (string) $handle, $style ) ) {
				continue;
			}
			if ( 1 === (int) $styles->get_data( $handle, 'group' ) ) {
				continue; // Printed in the footer, so not holding the page up.
			}
			$sheets[ (string) $handle ] = self::content_key( $style->src, $style->ver );
		}
		if ( array() === $sheets ) {
			return;
		}
		$template  = self::template();
		$scope     = ( Fixes::stored()['ccss_per_url'] ?? false ) ? 'url-v1|' . self::current_url() . '|' : '';
		$signature = sha1( $scope . $template . '|' . (string) wp_json_encode( $sheets ) );
		$stored    = self::load( $signature );
		if ( null === $stored ) {
			self::note( $signature, $template );
			return;
		}
		if ( ! Fixes::applies( 'unused_css' ) && ! Fixes::applies( 'critical_css' ) ) {
			return;
		}
		$excluded = Fixes::stored()['css_exclude'];
		$usable   = array();
		foreach ( $sheets as $handle => $key ) {
			$sheet = self::stored_sheet( $stored, $handle . '-css', $key, static fn ( string $href ): string => self::content_key( $href, null ), false );
			if ( null !== $sheet && ! in_array( $handle, $excluded, true ) ) {
				$usable[ $handle ] = $sheet;
			}
		}
		self::$page = array(
			'signature' => $signature,
			'template'  => $template,
			'sheets'    => $usable,
		);
		if ( ! Fixes::applies( 'unused_css' ) && array() !== $usable ) {
			$critical = implode( "\n", array_filter( array_column( $usable, 'critical' ) ) );
			$critical = self::font_css( $critical );
			if ( '' !== $critical ) {
				printf( "<style id=\"arvaseo-critical-css\">%s</style>\n", self::safe_css( $critical ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS made safe for a style element.
			}
		}
	}

	/** Whether CSS must be combined before its generated CSS can be selected. */
	public static function combined_mode(): bool {
		return Fixes::applies( 'combine_css' );
	}

	/**
	 * Select results only after the combiner has produced the final head stylesheets.
	 * The renderer follows that same preparation but must never consume its own results.
	 *
	 * @param string $html Complete, already combined page HTML.
	 */
	public static function combined_html( string $html ): string {
		if ( ! self::combined_mode() || ! self::wanted() || self::is_render_request() || self::$loader_printed
			|| null !== Exclusions::page_reason( 'critical_css' ) || strlen( $html ) > 4194304
			|| ! preg_match( '~\A(?:\xEF\xBB\xBF)?\s*(?:<!doctype\s+html\b[^>]*>\s*)?<html\b~i', $html ) || false === stripos( $html, '</html>' ) ) {
			return $html;
		}
		// A signed-out renderer cannot reproduce administrator-only trial preparations.
		foreach ( array( 'combine_css', 'minify_css', 'minify_inline_css', 'combine_inline_css', 'combine_external_css', 'font_display_swap', 'google_fonts', 'wp_extras' ) as $fix ) {
			if ( 'trying' === Fixes::state( $fix ) && Fixes::applies( $fix ) ) {
				return $html;
			}
		}
		$page = self::combined_sheets( $html );
		if ( null === $page || array() === $page['sheets'] ) {
			return $html;
		}
		$template = self::template();
		$scope    = ( Fixes::stored()['ccss_per_url'] ?? false ) ? 'url-v1|' . self::current_url() . '|' : '';
		$settings = array( 'exclude' => Fixes::stored()['css_exclude'] );
		foreach ( array( 'minify_css', 'minify_inline_css', 'combine_inline_css', 'combine_external_css', 'font_display_swap', 'google_fonts' ) as $fix ) {
			$settings[ $fix ] = Fixes::applies( $fix );
		}
		$signature = sha1( 'combined-css-v1|' . $scope . $template . '|' . (string) wp_json_encode( array( $settings, $page['context'] ) ) );
		$stored    = self::load( $signature );
		if ( null === $stored ) {
			// Toolbar styles can split public bundles even when those styles are excluded.
			// Optimize now visits anonymously; signed-in previews may reuse matching results.
			if ( ! is_user_logged_in() ) {
				self::note( $signature, $template );
			}
			return $html;
		}
		if ( ! Fixes::applies( 'unused_css' ) && ! Fixes::applies( 'critical_css' ) ) {
			return $html;
		}
		$keys = array();
		foreach ( $stored as $sheet ) {
			if ( is_string( $sheet['href'] ?? null ) && ! isset( $keys[ $sheet['href'] ] ) ) {
				$keys[ $sheet['href'] ] = self::combined_href_key( $sheet['href'] );
			}
		}
		$key_of     = static fn ( string $href ): string => $keys[ $href ] ?? self::combined_href_key( $href );
		$edits      = array();
		self::$page = array(
			'signature' => $signature,
			'template'  => $template,
			'sheets'    => array(),
		);
		foreach ( $page['sheets'] as $sheet ) {
			$href_key = self::combined_href_key( $sheet['href'] );
			$result   = self::stored_sheet( $stored, $sheet['id'], $href_key, $key_of, true );
			if ( null === $result ) {
				continue;
			}
			$key                             = $sheet['id'] . '|' . $href_key;
			$handle                          = substr( $sheet['id'], 0, -4 );
			self::$page['sheets'][ $handle ] = $result;
			$replacement                     = self::style_tag( $sheet['tag'], $handle, $sheet['href'], $sheet['media'] );
			if ( ! Fixes::applies( 'unused_css' ) ) {
				$critical = self::font_css( (string) $result['critical'] );
				if ( '' !== $critical ) {
					$replacement = sprintf( '<style id="arvaseo-critical-%1$s"%2$s>%3$s</style>', esc_attr( substr( sha1( $key ), 0, 16 ) ), 'all' === $sheet['media'] ? '' : ' media="' . esc_attr( $sheet['media'] ) . '"', self::safe_css( $critical ) ) . "\n" . $replacement;
				}
			}
			$edits[] = array(
				'start'  => $sheet['start'],
				'length' => $sheet['length'],
				'html'   => $replacement,
			);
		}
		if ( array() === $edits ) {
			return $html;
		}
		$edits[] = array(
			'start'  => $page['body_end'],
			'length' => 0,
			'html'   => self::loader_markup(),
		);
		usort( $edits, static fn ( array $a, array $b ): int => $a['start'] <=> $b['start'] );
		foreach ( array_reverse( $edits ) as $edit ) {
			$html = substr_replace( $html, $edit['html'], $edit['start'], $edit['length'] );
		}
		return $html;
	}

	/**
	 * Parse bounded, active head styles without entering comments, scripts or inert containers.
	 * Inline and excluded CSS still influence the signature because they affect the rendered page.
	 *
	 * @param string $html Complete HTML.
	 * @return array{sheets:array<int,array<string,mixed>>,context:array<int,mixed>,body_end:int}|null
	 */
	private static function combined_sheets( string $html ): ?array {
		$position = 0;
		$seen     = 0;
		$in_head  = false;
		$head_end = false;
		$body_end = null;
		$sheets   = array();
		$context  = array();
		$ids      = array();
		for ( $start = strpos( $html, '<' ); false !== $start; $start = strpos( $html, '<', $position ) ) {
			$token = AssetCombiner::token( $html, $start );
			if ( null === $token || ++$seen > 10000 ) {
				return null;
			}
			$position = $token['end'];
			if ( 'head' === $token['tag'] ) {
				$in_head  = ! $token['close'];
				$head_end = $head_end || $token['close'];
				continue;
			}
			if ( 'body' === $token['tag'] && $token['close'] ) {
				$body_end = $token['start'];
			}
			if ( $token['close'] || ! in_array( $token['tag'], array( 'base', 'meta', 'link', 'style' ), true ) ) {
				continue;
			}
			$tags = new \WP_HTML_Tag_Processor( $token['open'] . ( 'style' === $token['tag'] ? '</style>' : '' ) );
			if ( ! $tags->next_tag() || 'base' === $token['tag'] || ( 'meta' === $token['tag'] && str_starts_with( strtolower( (string) $tags->get_attribute( 'http-equiv' ) ), 'content-security-policy' ) ) ) {
				return null;
			}
			if ( ! $in_head || ! in_array( $token['tag'], array( 'link', 'style' ), true ) ) {
				continue;
			}
			$id     = (string) $tags->get_attribute( 'id' );
			$handle = (string) preg_replace( 'style' === $token['tag'] ? '/-inline-css$/' : '/-css$/', '', $id );
			$style  = wp_styles()->registered[ $handle ] ?? null;
			if ( self::signed_in_only( $handle, $style ) || ( null !== $style && 1 === (int) wp_styles()->get_data( $handle, 'group' ) ) ) {
				continue;
			}
			$media = (string) ( $tags->get_attribute( 'media' ) ?? 'all' );
			if ( 'style' === $token['tag'] ) {
				$context[] = array( 'inline', $id, $media, hash( 'sha256', $token['body'] ) );
				continue;
			}
			if ( 'stylesheet' !== strtolower( (string) $tags->get_attribute( 'rel' ) ) ) {
				continue;
			}
			$href      = \WP_Http::make_absolute_url( (string) $tags->get_attribute( 'href' ), self::current_url() );
			$context[] = array( 'link', $id, $media, self::combined_href_key( $href ), self::content_key( $href, null ) );
			$allowed   = array( 'id', 'href', 'rel', 'media', 'type', 'data-arvaseo-combined', 'data-arvaseo-minified', 'data-arvaseo-font-display' );
			$combined  = 'css' === $tags->get_attribute( 'data-arvaseo-combined' ) && str_contains( $href, '/' . AssetBundles::FOLDER . '/' ) && AssetMinifier::same_origin( $href, home_url( '/' ) );
			if ( '' === $id || ! self::is_sheet_id( $id ) || isset( $ids[ $id ] ) || ! str_ends_with( $id, '-css' ) || str_starts_with( $id, 'arvaseo-' )
				|| ( ! $combined && null === $style ) || in_array( $handle, Fixes::stored()['css_exclude'], true ) || 'print' === strtolower( trim( $media ) )
				|| ! in_array( strtolower( (string) $tags->get_attribute( 'type' ) ), array( '', 'text/css' ), true )
				|| array_diff( $tags->get_attribute_names_with_prefix( '' ) ?? array(), $allowed ) ) {
				continue;
			}
			$ids[ $id ] = true;
			$sheets[]   = array(
				'id'     => $id,
				'href'   => $href,
				'media'  => '' === $media ? 'all' : $media,
				'start'  => $token['start'],
				'length' => $token['end'] - $token['start'],
				'tag'    => $token['open'],
			);
		}
		return $head_end && null !== $body_end ? array(
			'sheets'   => $sheets,
			'context'  => $context,
			'body_end' => $body_end,
		) : null;
	}

	/**
	 * Ignore known cache busters only for Google Fonts and verified static local CSS.
	 * Preserve origin, path and content because they determine relative resources and styles.
	 *
	 * @param string $href Actual printed or rendered stylesheet address.
	 */
	private static function combined_href_key( string $href ): string {
		if ( 'fonts.googleapis.com' === strtolower( (string) wp_parse_url( $href, PHP_URL_HOST ) )
			&& in_array( wp_parse_url( $href, PHP_URL_PATH ), array( '/css', '/css2' ), true ) ) {
			return self::content_key( $href, null );
		}
		$asset = AssetMinifier::source( $href, 'css', true );
		if ( null !== $asset ) {
			return $asset['url'] . '|' . self::content_key( $asset['url'], null );
		}
		return $href;
	}

	/**
	 * A stylesheet id WordPress prints: its handle (letters in either case, digits, - and _) and -css.
	 *
	 * @param string $id The id attribute.
	 */
	private static function is_sheet_id( string $id ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9_-]+$/', $id );
	}

	/**
	 * The stored result for one printed stylesheet. By its id first: case is compared loosely,
	 * because 2.10.2 and earlier stored ids in lower case, so handles such as Elementor's
	 * e-animation-fadeInUp or a theme's google-fonts-Inter never found their results and stayed
	 * render-blocking. Otherwise by its address: Arva SEO's renderer keeps one result per address,
	 * named after the first link that loaded it, and themes print the same file (a Google Fonts
	 * stylesheet, typically) under several handles. A file's used CSS on a page is the same
	 * whichever link loads it. When several results fit (a theme that versions files with the
	 * time gives the phone and the computer render different addresses), the last one is used,
	 * as before.
	 *
	 * @param array<int, array<string, mixed>> $stored       The page type's stored sheets.
	 * @param string                           $id           The link's id (handle-css).
	 * @param string                           $key          The link's address key.
	 * @param callable(string): string         $key_of       A stored address's key.
	 * @param bool                             $id_needs_key Whether an id match must have the same address too.
	 * @return array<string, mixed>|null
	 */
	private static function stored_sheet( array $stored, string $id, string $key, callable $key_of, bool $id_needs_key ): ?array {
		$same_key = static fn ( array $sheet ): bool => is_string( $sheet['href'] ?? null ) && '' !== $sheet['href'] && $key_of( $sheet['href'] ) === $key;
		$exact    = null;
		$folded   = array();
		$by_key   = null;
		foreach ( $stored as $sheet ) {
			$stored_id = is_string( $sheet['id'] ?? null ) ? $sheet['id'] : '';
			if ( '' !== $stored_id && strtolower( $stored_id ) === strtolower( $id ) && ( ! $id_needs_key || $same_key( $sheet ) ) ) {
				if ( $stored_id === $id ) {
					$exact = $sheet;
				} else {
					$folded[ $stored_id ] = $sheet;
				}
			} elseif ( $same_key( $sheet ) ) {
				$by_key = $sheet;
			}
		}
		if ( null !== $exact ) {
			return $exact;
		}
		if ( array() !== $folded ) {
			// Two handles that differ only in case are ambiguous: leave the stylesheet as it is.
			return 1 === count( $folded ) ? reset( $folded ) : null;
		}
		return $by_key;
	}

	/**
	 * A stylesheet's tag: with Remove unused CSS, its used CSS in place and the full file on the
	 * first interaction; with Load styles faster, the file loading without holding the page up.
	 *
	 * @param mixed  $tag    The link tag.
	 * @param string $handle Style handle.
	 * @param string $href   Address.
	 * @param string $media  Media.
	 * @return mixed
	 */
	public static function style_tag( $tag, $handle, $href = '', $media = 'all' ) {
		$sheet = self::$page['sheets'][ (string) $handle ] ?? null;
		if ( ! is_string( $tag ) || null === $sheet || ! str_contains( $tag, 'stylesheet' ) ) {
			return $tag;
		}
		$media    = is_string( $media ) && '' !== $media ? $media : 'all';
		$fallback = '<noscript>' . trim( $tag ) . '</noscript>';
		if ( Fixes::applies( 'unused_css' ) ) {
			$used   = self::font_css( (string) $sheet['used'] );
			$url    = ! ( Fixes::stored()['ucss_inline'] ?? true ) ? self::used_url( $used ) : null;
			$styles = null !== $url
				? sprintf( '<link rel="stylesheet" id="%1$s-ucss" href="%2$s" media="%3$s" />', esc_attr( (string) $handle ), esc_url( $url ), esc_attr( $media ) ) // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- replaces the registered stylesheet with its generated used-CSS copy.
				: sprintf( '<style id="%1$s-ucss"%2$s>%3$s</style>', esc_attr( (string) $handle ), 'all' === $media ? '' : ' media="' . esc_attr( $media ) . '"', self::safe_css( $used ) );
			$late   = sprintf(
				'<link rel="stylesheet" id="%1$s-css" data-arvaseo-href="%2$s" data-arvaseo-media="%3$s" media="print" />', // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- the enqueued stylesheet's own tag, loaded later.
				esc_attr( (string) $handle ),
				esc_url( (string) $href ),
				esc_attr( $media )
			);
			return $styles . "\n" . $late . "\n" . $fallback . "\n";
		}
		$tags = new \WP_HTML_Tag_Processor( $tag );
		if ( ! $tags->next_tag( array( 'tag_name' => 'link' ) ) ) {
			return $tag;
		}
		$tags->set_attribute( 'media', 'print' );
		$tags->set_attribute( 'data-arvaseo-media', $media );
		$tags->set_attribute( 'data-arvaseo-async', 'css' );
		$tags->remove_attribute( 'onload' );
		return $tags->get_updated_html() . $fallback . "\n";
	}

	/**
	 * One inline or external library handles asynchronous sheets and interaction-delayed originals.
	 */
	public static function loader(): void {
		echo self::loader_markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WordPress builds the bundled script tag.
	}

	/** A string is also safe to append from the final output-buffer callback. */
	private static function loader_markup(): string {
		if ( self::$loader_printed || null === self::$page || array() === self::$page['sheets'] || ( ! Fixes::applies( 'unused_css' ) && ! Fixes::applies( 'critical_css' ) ) ) {
			return '';
		}
		$file = ARVASEO_CONNECTOR_DIR . '/assets/js/css-loader.js';
		$js   = is_readable( $file ) ? file_get_contents( $file ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- this plugin's bounded, bundled static library.
		if ( ! is_string( $js ) || '' === $js ) {
			return '';
		}
		self::$loader_printed = true;
		if ( Fixes::stored()['css_async_inline'] ?? true ) {
			return wp_get_inline_script_tag( $js, array( 'id' => Fixes::applies( 'unused_css' ) ? 'arvaseo-ucss-loader' : 'arvaseo-css-loader' ) );
		}
		return wp_get_script_tag(
			array(
				'src'   => plugins_url( 'assets/js/css-loader.js', ARVASEO_CONNECTOR_FILE ) . '?ver=' . rawurlencode( ARVASEO_CONNECTOR_VERSION ),
				'id'    => 'arvaseo-css-loader',
				'defer' => true,
			)
		);
	}

	/**
	 * Apply the font choice at delivery time, leaving fetched results reusable when it is switched.
	 *
	 * @param string $css Generated CSS.
	 */
	private static function font_css( string $css ): string {
		return Fixes::applies( 'font_display_swap' ) ? FontDisplay::swap( $css ) : $css;
	}

	/**
	 * Atomic immutable used-CSS copy, or null so the existing inline delivery remains available.
	 *
	 * @param string $css Generated used CSS, whose resource addresses are already absolute.
	 */
	private static function used_url( string $css ): ?string {
		if ( '' === $css || strlen( $css ) > self::MAX_USED_BYTES ) {
			return null;
		}
		$fs      = self::filesystem();
		$uploads = wp_get_upload_dir();
		$base    = realpath( (string) $uploads['basedir'] );
		$dir     = self::dir() . '/used';
		if ( null === $fs || false === $base || is_link( self::dir() ) || is_link( $dir ) || ! wp_mkdir_p( $dir ) ) {
			return null;
		}
		$real = realpath( $dir );
		if ( false === $real || ! str_starts_with( $real, $base . DIRECTORY_SEPARATOR ) ) {
			return null;
		}
		$name = hash( 'sha256', 'used-css-v1|' . $css ) . '.css';
		$file = $dir . '/' . $name;
		$url  = untrailingslashit( (string) $uploads['baseurl'] ) . '/' . self::FOLDER . '/used/' . $name;
		if ( is_link( $file ) ) {
			return null;
		}
		if ( is_file( $file ) && is_readable( $file ) && filesize( $file ) === strlen( $css ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_filesize -- generated local static copy.
			return $url;
		}
		$files = glob( $dir . '/*.css' );
		if ( false === $files || count( $files ) >= self::MAX_ENTRIES ) {
			return null;
		}
		$bytes = strlen( $css );
		foreach ( $files as $existing ) {
			$bytes += (int) filesize( $existing ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_filesize -- bounded generated cache.
			if ( $bytes > self::USED_CACHE_BYTES ) {
				return null;
			}
		}
		$temp = $dir . '/.' . $name . '.' . wp_generate_password( 12, false, false ) . '.tmp';
		if ( ! $fs->put_contents( $temp, $css, FS_CHMOD_FILE ) || ! $fs->move( $temp, $file, true ) ) {
			$fs->delete( $temp );
			return null;
		}
		return $url;
	}

	/**
	 * CSS that can't end the style element it's printed in.
	 *
	 * @param string $css CSS.
	 */
	public static function safe_css( string $css ): string {
		return str_ireplace( '</style', '<\/style', $css );
	}

	// ----- Signatures ----------------------------------------------------------------------------

	/**
	 * What identifies a stylesheet's contents: its file's checksum for files on this site, the
	 * address and version for others. A version change without a CSS change changes nothing.
	 *
	 * @param string           $src     Address.
	 * @param string|bool|null $version Version.
	 */
	public static function content_key( string $src, $version ): string {
		// Google Fonts CSS is selected by family/text/subset/display, not a theme's cache-buster.
		// Some themes pass time() as v/ver on every request, otherwise making a fresh paid
		// optimization each visit and preventing previously generated CSS from being used.
		if ( 'fonts.googleapis.com' === strtolower( (string) wp_parse_url( $src, PHP_URL_HOST ) )
			&& in_array( wp_parse_url( $src, PHP_URL_PATH ), array( '/css', '/css2' ), true ) ) {
			return remove_query_arg( array( 'v', 'ver' ), $src );
		}
		$path = self::local_path( $src );
		if ( null === $path ) {
			return $src . '?' . ( is_string( $version ) ? $version : '' );
		}
		$stat  = @stat( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a missing file is handled.
		$cache = get_option( 'arvaseo_connector_css_hashes', array() );
		$cache = is_array( $cache ) ? $cache : array();
		$id    = is_array( $stat ) ? $stat['mtime'] . ':' . $stat['size'] : 'missing';
		if ( ( $cache[ $path ]['id'] ?? null ) !== $id ) {
			$cache[ $path ] = array(
				'id'  => $id,
				'md5' => is_array( $stat ) ? (string) md5_file( $path ) : 'missing',
			);
			update_option( 'arvaseo_connector_css_hashes', array_slice( $cache, -500, null, true ), false );
		}
		return 'file:' . $cache[ $path ]['md5'];
	}

	/**
	 * A stylesheet address on this site as a file path, or null.
	 *
	 * @param string $src Address.
	 */
	private static function local_path( string $src ): ?string {
		$asset = AssetMinifier::source( $src, 'css', true );
		if ( null !== $asset ) {
			return $asset['path']; // The verified decoded path also covers escaped filenames.
		}
		$src = (string) strtok( set_url_scheme( str_starts_with( $src, '//' ) ? 'https:' . $src : $src ), '?' );
		$map = array(
			set_url_scheme( content_url() )   => WP_CONTENT_DIR,
			set_url_scheme( includes_url() )  => ABSPATH . 'wp-includes/',
			set_url_scheme( site_url( '/' ) ) => ABSPATH,
		);
		foreach ( $map as $url => $dir ) {
			$url = trailingslashit( $url );
			if ( str_starts_with( $src, $url ) ) {
				$path = trailingslashit( $dir ) . ltrim( substr( $src, strlen( $url ) ), '/' );
				return str_contains( $path, '..' ) || ! str_ends_with( strtolower( $path ), '.css' ) ? null : $path;
			}
			if ( str_starts_with( $src, '/' ) && ! str_starts_with( $src, '//' ) ) {
				return null;
			}
		}
		return null;
	}

	/**
	 * The page type: front, blog, single-{post type}, archive-{post type}, category, tag,
	 * tax-{taxonomy}, author, date, search, 404 or other.
	 */
	public static function template(): string {
		$type = match ( true ) {
			is_front_page()          => 'front',
			is_home()                => 'blog',
			is_404()                 => '404',
			is_search()              => 'search',
			is_singular()            => 'single-' . get_post_type(),
			is_post_type_archive()   => 'archive-' . (string) get_query_var( 'post_type' ),
			is_category()            => 'category',
			is_tag()                 => 'tag',
			is_tax()                 => 'tax-' . (string) ( get_queried_object()->taxonomy ?? 'term' ),
			is_author()              => 'author',
			is_date()                => 'date',
			default                  => 'other',
		};
		return substr( (string) preg_replace( '/[^a-z0-9_:-]/', '-', strtolower( $type ) ), 0, 60 );
	}

	// ----- Results and the list sent to Arva SEO -------------------------------------------------

	/**
	 * The page types known, by signature.
	 *
	 * @return array<string, array{template: string, url: string, status: string, at: int, error?: string, error_status?: int, retry_at?: int}>
	 */
	public static function index(): array {
		$index = get_option( self::INDEX, array() );
		return is_array( $index ) ? $index : array();
	}

	/**
	 * Save the index.
	 *
	 * @param array<string, array<string, mixed>> $index Index.
	 */
	private static function save_index( array $index ): void {
		$dropped = array();
		if ( count( $index ) > self::MAX_ENTRIES ) {
			uasort( $index, static fn ( $a, $b ) => ( $b['at'] ?? 0 ) <=> ( $a['at'] ?? 0 ) );
			$dropped = array_keys( array_slice( $index, self::MAX_ENTRIES, null, true ) );
			$index   = array_slice( $index, 0, self::MAX_ENTRIES, true );
		}
		update_option( self::INDEX, $index, false );
		// A page type that is no longer tracked never uses its results again: they'd only fill uploads.
		foreach ( $dropped as $signature ) {
			self::delete_result( (string) $signature );
		}
	}

	/**
	 * Delete the stored results of every signature the index no longer has (daily maintenance).
	 * Earlier versions left them behind when old page types were dropped: hundreds of MB on a
	 * site whose page types changed often.
	 *
	 * @return int Files deleted.
	 */
	public static function prune_results(): int {
		$files = glob( self::dir() . '/*.json' );
		if ( ! is_array( $files ) || is_link( self::dir() ) ) {
			return 0;
		}
		$index   = self::index();
		$deleted = 0;
		foreach ( $files as $file ) {
			$signature = basename( $file, '.json' );
			if ( ! isset( $index[ $signature ] ) && self::delete_result( $signature ) ) {
				++$deleted;
			}
		}
		return $deleted;
	}

	/**
	 * Delete one signature's stored results.
	 *
	 * @param string $signature Signature.
	 */
	private static function delete_result( string $signature ): bool {
		$file = self::dir() . '/' . $signature . '.json';
		if ( ! preg_match( '/^[a-f0-9]{40}$/', $signature ) || is_link( self::dir() ) || is_link( $file ) || ! is_file( $file ) ) {
			return false;
		}
		wp_delete_file( $file );
		clearstatcache( true, $file );
		return ! file_exists( $file );
	}

	/**
	 * A page type without results, noted for the next sync (from a page anyone can see).
	 *
	 * @param string $signature Signature.
	 * @param string $template  Page type.
	 */
	private static function note( string $signature, string $template ): void {
		if ( is_user_logged_in() && ! current_user_can( Capabilities::MANAGE ) ) {
			return; // Signed-in visitors' pages differ (account menus).
		}
		if ( '404' === $template && self::is_file_address( self::current_url() ) ) {
			return; // A missing stylesheet, script or manifest reaches the 404 template too: only pages count.
		}
		$index = self::index();
		if ( isset( $index[ $signature ] ) ) {
			// Still waiting: a sync that was lost (a failed or cancelled run) must not leave it waiting for good.
			if ( in_array( $index[ $signature ]['status'], array( 'needed', 'requested' ), true ) && ! wp_next_scheduled( self::HOOK ) ) {
				wp_schedule_single_event( time() + 30, self::HOOK );
			}
			return;
		}
		$index[ $signature ] = array(
			'template' => $template,
			'url'      => self::current_url(),
			'status'   => 'needed',
			'at'       => time(),
		);
		self::save_index( $index );
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + 30, self::HOOK );
		}
	}

	/**
	 * Whether an address is a file rather than a page (it ends in .css, .js, .json, an image…).
	 *
	 * @param string $url Address.
	 */
	public static function is_file_address( string $url ): bool {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		return 1 === preg_match( self::NOT_PAGES, $path );
	}

	/**
	 * The page's address as visitors reach it, without tracking parameters.
	 */
	public static function current_url(): string {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$url = home_url( (string) $uri );
		return (string) remove_query_arg( array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid', 'msclkid', 'arvaseo_render', 'arvaseo_note' ), $url );
	}

	/**
	 * Stored results for a signature.
	 *
	 * @param string $signature Signature.
	 * @return array<int, array{id: string|null, href: string, used: string, critical: string}>|null
	 */
	public static function load( string $signature ): ?array {
		$file = self::dir() . '/' . $signature . '.json';
		if ( ! preg_match( '/^[a-f0-9]{40}$/', $signature ) || ! is_readable( $file ) ) {
			return null;
		}
		$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file.
		return is_array( $data['sheets'] ?? null ) ? $data['sheets'] : null;
	}

	/**
	 * Send what's needed and fetch what's ready (WP-Cron, and from the page's buttons).
	 *
	 * @return array{sent: int, ready: int, allowance: array<string, mixed>|null}
	 */
	public static function sync(): array {
		$out = array(
			'sent'      => 0,
			'ready'     => 0,
			'allowance' => null,
		);
		if ( ! TokenStore::is_connected() || ! ( self::wanted() ) ) {
			return $out;
		}
		$index  = self::index();
		$client = ApiClient::instance();
		// The next run is scheduled before any request: a run cut short (a slow download past the
		// time limit) used to leave page types waiting for good, since pages kept by the page
		// cache don't note them again.
		self::schedule_sync( $index );

		$send = array();
		foreach ( $index as $signature => $entry ) {
			if ( 'needed' === $entry['status'] || ( in_array( $entry['status'], array( 'no_allowance', 'daily_cap' ), true ) && ( $entry['retry_at'] ?? 0 ) <= time() ) ) {
				$send[] = array(
					'signature' => (string) $signature,
					'url'       => $entry['url'],
					'template'  => $entry['template'],
				);
			}
			if ( count( $send ) >= self::BATCH ) {
				break;
			}
		}
		if ( array() !== $send ) {
			$response = $client->site( 'POST', '/css-optimizations', array( 'items' => $send ) );
			if ( $response->ok() ) {
				foreach ( (array) ( $response->body['data'] ?? array() ) as $row ) {
					$signature = (string) ( $row['signature'] ?? '' );
					if ( ! isset( $index[ $signature ] ) ) {
						continue;
					}
					$status                          = (string) ( $row['status'] ?? 'needed' );
					$index[ $signature ]['status']   = in_array( $status, array( 'queued', 'rendering', 'ready' ), true ) ? 'requested' : $status;
					$index[ $signature ]['retry_at'] = 'no_allowance' === $status ? self::next_month() : ( time() + DAY_IN_SECONDS );
					++$out['sent'];
				}
				$out['allowance'] = is_array( $response->body['allowance'] ?? null ) ? $response->body['allowance'] : null;
			}
		}

		$waiting = array_reverse( array_filter( $index, static fn ( $entry ) => 'requested' === $entry['status'] ), true );
		uasort( $waiting, static fn ( $a, $b ) => ( $b['at'] ?? 0 ) <=> ( $a['at'] ?? 0 ) );
		$waiting = array_keys( $waiting );
		$started = microtime( true );
		$fetched = 0;
		// A single result may contain hundreds of KB of CSS. Combining ten can exceed
		// the API client's response limit and leave already-generated results waiting forever.
		foreach ( array_slice( $waiting, 0, self::RESULTS_PER_SYNC ) as $signature ) {
			if ( $fetched > 0 && microtime( true ) - $started >= self::FETCH_SECONDS ) {
				break;
			}
			// The renderer accepts up to 2 MB of CSS per result; JSON escaping can enlarge it.
			// Only this single-result download opts into the larger bounded response allowance.
			$response = $client->site( 'GET', '/css-optimizations?signatures=' . $signature, null, self::FETCH_SECONDS, ApiClient::MAX_RESPONSE_LIMIT );
			++$fetched;
			if ( ! $response->ok() ) {
				break;
			}
			$out['allowance'] = is_array( $response->body['allowance'] ?? null ) ? $response->body['allowance'] : $out['allowance'];
			foreach ( (array) ( $response->body['data'] ?? array() ) as $row ) {
				$signature = (string) ( $row['signature'] ?? '' );
				if ( ! isset( $index[ $signature ] ) ) {
					continue;
				}
				if ( 'ready' === ( $row['status'] ?? '' ) && self::store( $signature, (array) ( $row['sheets'] ?? array() ) ) ) {
					$index[ $signature ]['status'] = 'ready';
					++$out['ready'];
				} elseif ( 'failed' === ( $row['status'] ?? '' ) ) {
					$index[ $signature ]['status']       = 'failed';
					$index[ $signature ]['error']        = substr( (string) ( $row['error_code'] ?? '' ), 0, 40 );
					$index[ $signature ]['error_status'] = (int) ( $row['error_status'] ?? 0 );
				}
			}
		}
		if ( null !== $out['allowance'] ) {
			update_option( 'arvaseo_connector_css_allowance', $out['allowance'] + array( 'at' => time() ), false );
		}
		self::save_index( $index );
		self::schedule_sync( $index );
		if ( $out['ready'] > 0 ) {
			Fixes::pages_changed( 'css' );
			AuditLogger::info(
				'speed.css_ready',
				/* translators: %d: number of page types. */
				sprintf( _n( 'PageSpeed fixes: optimized CSS is ready for %d page type.', 'PageSpeed fixes: optimized CSS is ready for %d page types.', $out['ready'], 'arvaseo-connector' ), $out['ready'] )
			);
		}
		return $out;
	}

	/**
	 * Another sync in two minutes, while page types wait to be sent or for their results.
	 *
	 * @param array<string, array<string, mixed>> $index Index.
	 */
	private static function schedule_sync( array $index ): void {
		if ( array() !== array_filter( $index, static fn ( $entry ) => in_array( $entry['status'] ?? '', array( 'needed', 'requested' ), true ) ) && ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + 2 * MINUTE_IN_SECONDS, self::HOOK );
		}
	}

	/**
	 * Keep one signature's results.
	 *
	 * @param string                           $signature Signature.
	 * @param array<int, array<string, mixed>> $sheets    Sheets.
	 */
	private static function store( string $signature, array $sheets ): bool {
		$clean = array();
		foreach ( $sheets as $sheet ) {
			if ( ! is_array( $sheet ) || ! is_string( $sheet['used'] ?? null ) || ! is_string( $sheet['critical'] ?? null ) ) {
				continue;
			}
			$clean[] = array(
				// Case is kept: handles such as Elementor's e-animation-fadeInUp are printed as they are.
				'id'       => is_string( $sheet['id'] ?? null ) ? (string) preg_replace( '/[^A-Za-z0-9_-]/', '', $sheet['id'] ) : null,
				'href'     => esc_url_raw( (string) ( $sheet['href'] ?? '' ) ),
				'used'     => wp_strip_all_tags( $sheet['used'] ),
				'critical' => wp_strip_all_tags( $sheet['critical'] ),
			);
		}
		$fs = self::filesystem();
		return null !== $fs && wp_mkdir_p( self::dir() ) && (bool) $fs->put_contents(
			self::dir() . '/' . $signature . '.json',
			(string) wp_json_encode(
				array(
					'at'     => time(),
					'sheets' => $clean,
				)
			),
			FS_CHMOD_FILE
		);
	}

	/**
	 * Forget every result (Regenerate), so each page type is sent again as it's next visited.
	 */
	public static function forget_all(): void {
		$fs = self::filesystem();
		if ( null !== $fs && $fs->is_dir( self::dir() ) ) {
			$fs->rmdir( self::dir(), true );
		}
		$index = self::index();
		foreach ( $index as $signature => $entry ) {
			$index[ $signature ]['status'] = 'needed';
			unset( $index[ $signature ]['error'], $index[ $signature ]['retry_at'] );
		}
		self::save_index( $index );
		Fixes::pages_changed( 'css' );
		AuditLogger::info( 'speed.css_regenerate', __( 'PageSpeed fixes: optimized CSS will be worked out again.', 'arvaseo-connector' ) );
	}

	/**
	 * Note the homepage and a page of each common type now, instead of waiting for visits.
	 */
	public static function queue_samples(): int {
		$urls = array( home_url( '/' ) );
		foreach ( array( 'page', 'post', 'product' ) as $type ) {
			$ids = get_posts(
				array(
					'post_type'      => $type,
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'has_password'   => false,
				)
			);
			if ( array() !== $ids ) {
				$urls[] = (string) get_permalink( (int) $ids[0] );
			}
		}
		$count = 0;
		foreach ( array_unique( array_filter( $urls ) ) as $url ) {
			// The page notes its own signature; ask for it as a visitor would see it, past any page cache.
			$response = wp_remote_get(
				add_query_arg( 'arvaseo_note', (string) time(), $url ),
				array(
					'timeout'   => 10,
					'sslverify' => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's filter for requests to the site itself.
				)
			);
			if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
				++$count;
			}
		}
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + 10, self::HOOK );
		}
		return $count;
	}

	/**
	 * Remove every result and the index (uninstall).
	 */
	public static function delete_all(): void {
		$fs = self::filesystem();
		if ( null !== $fs && $fs->is_dir( self::dir() ) ) {
			$fs->rmdir( self::dir(), true );
		}
		delete_option( self::INDEX );
		delete_option( 'arvaseo_connector_css_hashes' );
		delete_option( 'arvaseo_connector_css_allowance' );
	}

	/**
	 * Counts for the page: page types with optimized CSS, waiting, failed, waiting for allowance.
	 *
	 * @return array{ready: int, waiting: int, failed: int, no_allowance: int}
	 */
	public static function counts(): array {
		$counts = array(
			'ready'        => 0,
			'waiting'      => 0,
			'failed'       => 0,
			'no_allowance' => 0,
		);
		foreach ( self::index() as $entry ) {
			$status = $entry['status'];
			if ( 'not_a_page' === $status ) {
				continue;
			}
			if ( 'ready' === $status ) {
				++$counts['ready'];
			} elseif ( in_array( $status, array( 'needed', 'requested', 'daily_cap' ), true ) ) {
				++$counts['waiting'];
			} elseif ( 'no_allowance' === $status ) {
				++$counts['no_allowance'];
			} else {
				++$counts['failed'];
			}
		}
		return $counts;
	}

	/**
	 * The page types that couldn't be optimized, with why in plain words.
	 *
	 * @return list<array{label: string, url: string, reason: string}>
	 */
	public static function failures(): array {
		$out = array();
		foreach ( self::index() as $entry ) {
			if ( in_array( $entry['status'], array( 'failed', 'not_this_site' ), true ) ) {
				$out[] = array(
					'label'  => self::template_label( (string) $entry['template'] ),
					'url'    => (string) $entry['url'],
					'reason' => 'not_this_site' === $entry['status'] ? __( 'This address isn’t on the website connected to Arva SEO.', 'arvaseo-connector' ) : self::reason( (string) ( $entry['error'] ?? '' ), (int) ( $entry['error_status'] ?? 0 ) ),
				);
			}
		}
		return $out;
	}

	/**
	 * Ask again for the page types that failed (Optimize now).
	 */
	public static function retry_failed(): int {
		$index = self::index();
		$count = 0;
		foreach ( $index as $signature => $entry ) {
			if ( 'failed' === $entry['status'] ) {
				$index[ $signature ]['status'] = 'needed';
				unset( $index[ $signature ]['error'], $index[ $signature ]['error_status'] );
				++$count;
			}
		}
		if ( $count > 0 ) {
			self::save_index( $index );
		}
		return $count;
	}

	/**
	 * Why a page type couldn't be optimized, from Arva SEO's error code and the page's status.
	 *
	 * @param string $code   Error code.
	 * @param int    $status The page's HTTP status, 0 when unknown.
	 */
	public static function reason( string $code, int $status ): string {
		if ( 'page_error' === $code ) {
			if ( in_array( $status, array( 401, 403, 429 ), true ) ) {
				return __( 'Your host’s bot protection blocked Arva SEO from loading the page. Arva SEO now tries again under its own name, ArvaSEO-Bot; if it still fails, ask your host to allow ArvaSEO-Bot.', 'arvaseo-connector' );
			}
			if ( $status >= 500 ) {
				/* translators: %d: HTTP status, such as 503. */
				return sprintf( __( 'The page answered with a server error (%d).', 'arvaseo-connector' ), $status );
			}
			return $status > 0
				/* translators: %d: HTTP status, such as 410. */
				? sprintf( __( 'The page answered with an error (%d).', 'arvaseo-connector' ), $status )
				: __( 'The page didn’t load.', 'arvaseo-connector' );
		}
		return match ( $code ) {
			'timeout'      => __( 'The page took too long to load.', 'arvaseo-connector' ),
			'too_large'    => __( 'The page’s styles are too large to optimize.', 'arvaseo-connector' ),
			'not_allowed'  => __( 'The page loads from an address Arva SEO doesn’t load (a private network).', 'arvaseo-connector' ),
			default        => __( 'Arva SEO couldn’t work this page out.', 'arvaseo-connector' ),
		};
	}

	/**
	 * A page type's name for people: Homepage, Pages, Posts, Not found page…
	 *
	 * @param string $template Page type.
	 */
	public static function template_label( string $template ): string {
		$fixed = array(
			'front'    => __( 'Homepage', 'arvaseo-connector' ),
			'blog'     => __( 'Blog page', 'arvaseo-connector' ),
			'404'      => __( 'Not found page', 'arvaseo-connector' ),
			'search'   => __( 'Search results', 'arvaseo-connector' ),
			'category' => __( 'Categories', 'arvaseo-connector' ),
			'tag'      => __( 'Tags', 'arvaseo-connector' ),
			'author'   => __( 'Author pages', 'arvaseo-connector' ),
			'date'     => __( 'Date archives', 'arvaseo-connector' ),
		);
		if ( isset( $fixed[ $template ] ) ) {
			return $fixed[ $template ];
		}
		if ( 1 === preg_match( '/^(single|archive)-(.+)$/', $template, $m ) ) {
			$type = get_post_type_object( $m[2] );
			$name = null !== $type ? (string) $type->labels->name : $m[2];
			/* translators: %s: a post type's name, such as Products. */
			return 'single' === $m[1] ? $name : sprintf( __( '%s list', 'arvaseo-connector' ), $name );
		}
		if ( 1 === preg_match( '/^tax-(.+)$/', $template, $m ) ) {
			$tax = get_taxonomy( $m[1] );
			return false !== $tax ? (string) $tax->labels->name : $m[1];
		}
		return __( 'Other pages', 'arvaseo-connector' );
	}

	/**
	 * Whether either fix is on or being tried.
	 */
	public static function wanted(): bool {
		return in_array( Fixes::state( 'critical_css' ), array( 'on', 'trying' ), true ) || in_array( Fixes::state( 'unused_css' ), array( 'on', 'trying' ), true );
	}

	/**
	 * The first day of next month, when a new allowance starts.
	 */
	private static function next_month(): int {
		return (int) strtotime( 'first day of next month 00:10 UTC' );
	}

	/**
	 * The results' folder.
	 */
	private static function dir(): string {
		return untrailingslashit( (string) wp_get_upload_dir()['basedir'] ) . '/' . self::FOLDER;
	}

	/**
	 * WordPress's file functions, writing directly.
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
