<?php
/**
 * Source identities and complete, ordered assembly of optimized stylesheets.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Speed;

defined( 'ABSPATH' ) || exit;

/** A partial optimized bundle is never substituted for its complete original. */
final class CssSources {

	/**
	 * Original order of only the styles this plugin removes.
	 *
	 * @var \WeakMap<\WP_Styles,array{queue:list<string>,removed:list<string>}>|null
	 */
	private static ?\WeakMap $extras = null;

	/**
	 * Remove a chosen WordPress extra without changing the original render identity.
	 *
	 * @param string $handle Chosen extra.
	 */
	public static function dequeue_extra( string $handle ): void {
		$styles = wp_styles();
		if ( in_array( $handle, $styles->queue, true ) ) {
			self::$extras          ??= new \WeakMap();
			$row                     = self::$extras[ $styles ] ?? array(
				'queue'   => array_values( $styles->queue ),
				'removed' => array(),
			);
			$row['removed'][]        = $handle;
			self::$extras[ $styles ] = $row;
		}
		wp_dequeue_style( $handle );
	}


	/**
	 * Public file identity, without sharing file contents or local filesystem paths.
	 *
	 * @param string $id    Printed stylesheet id.
	 * @param string $href  Public source URL.
	 * @param string $media Media.
	 * @return array{id:string,href:string,key:string,md5:string|null,media:string}|null
	 */
	public static function source( string $id, string $href, string $media = 'all' ): ?array {
		if ( ! preg_match( '/^[A-Za-z0-9_-]{1,200}$/D', $id ) || strlen( $href ) > 2048 || strlen( $media ) > 200 ) {
			return null;
		}
		$href  = \WP_Http::make_absolute_url( $href, trailingslashit( wp_styles()->base_url ) );
		$parts = wp_parse_url( $href );
		if ( ! is_array( $parts ) || ! in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) ) {
			return null;
		}
		$local = Addresses::local_source( $href, 'css' );
		if ( null !== $local ) {
			$md5 = md5_file( $local['path'] );
			if ( false === $md5 ) {
				return null;
			}
			$key = 'local:' . hash( 'sha256', $local['url'] . '|' . $md5 );
		} else {
			// Unknown query semantics are not assumed to represent a static local file.
			// Public CDN support is a separate phase; only Google Fonts is eligible here.
			if ( 'https' !== $parts['scheme'] || 'fonts.googleapis.com' !== strtolower( (string) ( $parts['host'] ?? '' ) ) || ! in_array( $parts['path'] ?? '', array( '/css', '/css2' ), true ) ) {
				return null;
			}
			$md5 = null;
			$key = 'url:' . hash( 'sha256', Addresses::external_key( $href ) );
		}
		return array(
			'id'    => $id,
			'href'  => $href,
			'key'   => $key,
			'md5'   => $md5,
			'media' => '' === $media ? 'all' : $media,
		);
	}

	/**
	 * Ordered enqueue sources; changing a minify/combine choice never changes the signature.
	 *
	 * @param string $template Page type.
	 * @param string $page     Optional per-URL scope; empty groups by page type.
	 * @return array{signature:string,shape:string,sources:list<array{id:string,href:string,key:string,md5:string|null,media:string}>}
	 */
	public static function snapshot( string $template, string $page = '' ): array {
		$styles  = self::original_styles();
		$sources = array();
		$keys    = array();
		$shape   = array();
		foreach ( $styles->to_do as $handle ) {
			$style = $styles->registered[ $handle ] ?? null;
			if ( null === $style || ! is_string( $style->src ) || '' === $style->src || CssOptimizer::signed_in_only( (string) $handle, $style ) || 1 === (int) $styles->get_data( $handle, 'group' ) ) {
				continue;
			}
			$source = self::for_handle( (string) $handle );
			if ( null === $source || 'print' === strtolower( $source['media'] ) ) {
				continue;
			}
			$sources[]             = $source;
			$inline                = $styles->get_data( $handle, 'after' );
			$inline_hash           = hash( 'sha256', (string) wp_json_encode( is_array( $inline ) ? $inline : array() ) );
			$keys[ $source['id'] ] = array( $source['key'], $source['media'], $inline_hash );
			$shape[]               = array( $source['id'], $source['media'], $inline_hash );
		}
		return array(
			'signature' => sha1( 'sources-v2|' . ( '' === $page ? '' : 'url-v1|' . Addresses::page_url( $page ) . '|' ) . $template . '|' . wp_json_encode( $keys ) ),
			'shape'     => hash( 'sha256', (string) wp_json_encode( $shape ) ),
			'sources'   => $sources,
		);
	}

	/** Resolve original dependencies on a copy, without changing what WordPress prints. */
	private static function original_styles(): \WP_Styles {
		$original = wp_styles();
		$styles   = clone $original;
		$extra    = self::$extras[ $original ] ?? null;
		if ( null !== $extra ) {
			$restored      = array_filter( $extra['queue'], static fn ( string $handle ): bool => in_array( $handle, $original->queue, true ) || in_array( $handle, $extra['removed'], true ) );
			$styles->queue = array_values( array_unique( array_merge( $restored, $original->queue ) ) );
		}
		$styles->to_do = array();
		$styles->all_deps( $styles->queue );
		return $styles;
	}

	/**
	 * Resolve the original registered URL, including WordPress's version parameter.
	 *
	 * @param string $handle Registered handle.
	 * @return array{id:string,href:string,key:string,md5:string|null,media:string}|null
	 */
	public static function for_handle( string $handle ): ?array {
		$styles = wp_styles();
		$style  = $styles->registered[ $handle ] ?? null;
		if ( null === $style || ! is_string( $style->src ) || '' === $style->src ) {
			return null;
		}
		$href = $style->src;
		$ver  = null === $style->ver ? '' : ( $style->ver ?: $styles->default_version );
		if ( '' !== $ver ) {
			$href = add_query_arg( 'ver', (string) $ver, $href );
		}
		return self::source( $handle . '-css', $href, is_string( $style->args ) ? $style->args : 'all' );
	}

	/**
	 * A previous result is usable only with the same verified source identity.
	 *
	 * @param array<string,mixed>            $source Current file.
	 * @param array<int,array<string,mixed>> $results Saved sheets.
	 * @return array<string,mixed>|null
	 */
	public static function match( array $source, array $results ): ?array {
		$google = is_string( $source['href'] ?? null ) && LocalFonts::is_google_css( $source['href'] );
		if ( $google && Fixes::applies( 'google_fonts' ) && Fixes::stored()['fonts_local'] && LocalFonts::has_copy( $source['href'] ) ) {
			return self::local_font( $source );
		}
		foreach ( $results as $result ) {
			if ( ( $result['key'] ?? null ) !== ( $source['key'] ?? '' ) || ! is_string( $result['used'] ?? null ) || ! is_string( $result['critical'] ?? null ) || ! self::safe( $result['used'] ) || ! self::safe( $result['critical'] ) ) {
				continue;
			}
			if ( null !== ( $source['md5'] ?? null ) && ( $result['md5'] ?? null ) !== $source['md5'] ) {
				continue;
			}
			return array_merge(
				$result,
				array(
					'id'    => $source['id'],
					'href'  => $source['href'],
					'media' => $source['media'],
				)
			);
		}
		return null;
	}

	/**
	 * Keep a local Google Fonts copy local instead of restoring Google's font-file addresses.
	 *
	 * @param array<string,mixed> $source Original Google stylesheet.
	 * @return array<string,mixed>|null
	 */
	private static function local_font( array $source ): ?array {
		$url   = LocalFonts::local_url( $source['href'] );
		$asset = is_string( $url ) ? Addresses::local_source( $url, 'css' ) : null;
		if ( null === $asset ) {
			return null;
		}
		$text = file_get_contents( $asset['path'], false, null, 0, 500001 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- bounded verified local font copy.
		if ( ! is_string( $text ) || ! self::safe( $text ) ) {
			return null;
		}
		$text = AssetBundles::relocate_css( $text, $url );
		if ( null === $text ) {
			return null;
		}
		$text = FontDisplay::swap( $text );
		return $source + array(
			'used'     => $text,
			'critical' => $text,
			'sha'      => hash( 'sha256', $text ),
		);
	}

	/**
	 * Accept only unchanged originals or rewrites verified by our own filters in this response.
	 *
	 * @param array<string,mixed> $source Original source.
	 * @param string              $href Printed address.
	 */
	public static function printed_matches( array $source, string $href ): bool {
		$original = AssetMinifier::original_for( $href ) ?? $href;
		$printed  = self::source( $source['id'], $original, $source['media'] );
		if ( null !== $printed && $source['key'] === $printed['key'] ) {
			return true;
		}
		return LocalFonts::is_google_css( $source['href'] ) && ( Fixes::applies( 'google_fonts' ) || Fixes::applies( 'font_display_swap' ) ) && Runtime::font_display( $source['href'] ) === $original;
	}

	/**
	 * Every member must be usable; original inline CSS is kept in cascade order in both outputs.
	 *
	 * @param array<int,array<string,mixed>> $parts Ordered bundle members.
	 * @param array<int,array<string,mixed>> $results Saved sheets.
	 * @param string                         $media Printed bundle media.
	 * @return array{used:string,critical:string}|null
	 */
	public static function assemble( array $parts, array $results, ?string $media = null ): ?array {
		if ( array() === $parts || count( $parts ) > 80 ) {
			return null;
		}
		$used     = array();
		$critical = array();
		foreach ( $parts as $part ) {
			if ( 'file' === ( $part['source'] ?? '' ) && is_array( $part['file'] ?? null ) ) {
				if ( null !== $media && ( $part['file']['media'] ?? 'all' ) !== $media ) {
					return null;
				}
				$sheet = self::match( $part['file'], $results );
				if ( null === $sheet ) {
					return null;
				}
			} elseif ( 'inline' === ( $part['source'] ?? '' ) && is_string( $part['id'] ?? null ) && is_string( $part['text'] ?? null ) && is_string( $part['url'] ?? null ) && InlineAssets::approved( $part['id'], $part['text'], 'css' ) ) {
				$text = AssetBundles::relocate_css( $part['text'], $part['url'] );
				if ( null === $text ) {
					return null;
				}
				$sheet = array(
					'used'     => $text,
					'critical' => $text,
				);
			} else {
				return null;
			}
			// Hoisting an import/namespace out of a later member can change the cascade.
			if ( ! self::safe( $sheet['used'] ) || ! self::safe( $sheet['critical'] ) || preg_match( '/@(?:import|charset|namespace)\b/i', $sheet['used'] . $sheet['critical'] ) ) {
				return null;
			}
			$used[]     = $sheet['used'];
			$critical[] = $sheet['critical'];
		}
		$out = array(
			'used'     => implode( "\n", $used ),
			'critical' => implode( "\n", $critical ),
		);
		return strlen( $out['used'] ) + strlen( $out['critical'] ) <= 2000000 ? $out : null;
	}

	/**
	 * Refuse CSS that could escape the style element or exceed one-sheet limits.
	 *
	 * @param string $css CSS.
	 */
	private static function safe( string $css ): bool {
		return strlen( $css ) <= 500000 && false === stripos( $css, '</style' ) && ! str_contains( $css, "\0" );
	}
}
