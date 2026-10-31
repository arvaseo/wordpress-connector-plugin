<?php
/**
 * Combines adjacent, compatible enqueued assets after WordPress printed the page.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Speed;

defined( 'ABSPATH' ) || exit;

/** Keeps inline code, loading strategies and inert HTML as combination boundaries. */
final class AssetCombiner {

	/** Largest complete HTML response considered. */
	private const MAX_HTML = 4194304;

	/**
	 * Whether this request already has our output buffer.
	 *
	 * @var bool
	 */
	private static bool $buffer_started = false;

	/**
	 * Original members of CSS bundles in this response.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	private static array $css_members = array();

	/**
	 * Original members are request-local, never inferred from a generated filename.
	 *
	 * @param string $url Bundle URL.
	 * @return array<int,array<string,mixed>>|null
	 */
	public static function css_members( string $url ): ?array {
		return self::$css_members[ $url ] ?? null;
	}

	/** Start inside the page-cache buffer so it receives the final combined HTML. */
	public static function on_page(): void {
		if ( self::$buffer_started || ! self::wanted() ) {
			return;
		}
		AssetBundles::reset();
		self::$buffer_started = ob_start( array( self::class, 'capture' ) );
	}

	/** Inline changes, moving jQuery and late icon libraries use this same complete-response buffer, including when combining is off. */
	private static function wanted(): bool {
		if ( HtmlMinifier::wanted() || CssOptimizer::upgrade_pending() || ( CssOptimizer::per_file() && CssOptimizer::wanted() ) ) {
			return true;
		}

		if ( HeadScripts::wanted() || IconStyles::wanted() || SafeAssetTrials::active( 'body_css' ) ) {
			return true;
		}
		$fixes = CssOptimizer::is_render_request()
			? array( 'combine_css', 'minify_inline_css', 'combine_inline_css', 'font_display_swap' )
			: array( 'combine_css', 'combine_js', 'minify_inline_css', 'minify_inline_js', 'combine_inline_css', 'combine_inline_js', 'font_display_swap' );
		foreach ( $fixes as $fix ) {
			if ( Fixes::applies( $fix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A flushed, discarded, compressed or non-HTML response stays untouched.
	 *
	 * @param string $html  Complete output buffer.
	 * @param int    $phase PHP output-buffer phase.
	 */
	public static function capture( string $html, int $phase ): string {
		if ( $phase & PHP_OUTPUT_HANDLER_FINAL ) {
			self::$buffer_started = false;
		}
		if ( ! ( $phase & PHP_OUTPUT_HANDLER_START ) || ! ( $phase & PHP_OUTPUT_HANDLER_FINAL ) || ( $phase & PHP_OUTPUT_HANDLER_CLEAN ) || http_response_code() >= 300 ) {
			return $html;
		}
		foreach ( headers_list() as $header ) {
			if ( preg_match( '/^(?:Content-Encoding|Content-Length|Content-Security-Policy(?:-Report-Only)?)\s*:/i', $header ) || ( preg_match( '/^Content-Type\s*:/i', $header ) && ! preg_match( '~^Content-Type\s*:\s*text/html(?:\s*;|\s*$)~i', $header ) ) ) {
				return $html;
			}
		}
		return self::filter( $html );
	}

	/**
	 * Replace only whitespace-separated runs; every other token is a boundary.
	 *
	 * @param string $html Final page HTML, after other asset filters.
	 */
	public static function filter( string $html ): string {
		self::$css_members = array();
		if ( CssOptimizer::original_sources_render() ) {
			return $html;
		}
		$rendering = CssOptimizer::is_render_request();
		$css       = Fixes::applies( 'combine_css' );
		$js        = ! $rendering && Fixes::applies( 'combine_js' );
		if ( ! self::wanted() || strlen( $html ) > self::MAX_HTML || ! preg_match( '~\A(?:\xEF\xBB\xBF)?\s*(?:<!doctype\s+html\b[^>]*>\s*)?<html\b~i', $html ) || false === stripos( $html, '</html>' ) || self::restricted_html( $html ) ) {
			return $html;
		}
		if ( HeadScripts::wanted() ) {
			$html = HeadScripts::move( $html );
		}
		if ( IconStyles::wanted() ) {
			$html = IconStyles::defer( $html );
		}
		InlineAssets::prepare( $html );
		$position     = 0;
		$visited      = 0;
		$run          = array();
		$edits        = array();
		$inline_edits = array();
		for ( $start = strpos( $html, '<', $position ); false !== $start; $start = strpos( $html, '<', $position ) ) {
			$token = self::token( $html, $start );
			if ( null === $token || ++$visited > 10000 ) {
				return $html;
			}
			$asset    = self::asset( $token, $css, $js );
			$minified = ! $rendering || 'style' === $token['tag'] ? InlineAssets::minify( $token ) : null;
			if ( null !== $minified ) {
				$inline_edits[] = array(
					'start'  => $token['start'],
					'length' => $token['end'] - $token['start'],
					'html'   => $minified,
				);
			}
			$last = $run ? $run[ count( $run ) - 1 ] : null;
			if ( $last && ( null === $asset || $asset['key'] !== $last['key'] || '' !== trim( substr( $html, $last['end'], $start - $last['end'] ) ) ) ) {
				self::finish( $run, $edits );
				$run = array();
			}
			if ( null !== $asset ) {
				$run[] = $asset;
			}
			$position = $token['end'];
		}
		self::finish( $run, $edits );
		// A published bundle owns its whole range; in-place edits only apply outside those ranges.
		$bundles = $edits;
		$cursor  = 0;
		foreach ( $inline_edits as $inline_edit ) {
			while ( isset( $bundles[ $cursor ] ) && $inline_edit['start'] >= $bundles[ $cursor ]['start'] + $bundles[ $cursor ]['length'] ) {
				++$cursor;
			}
			if ( ! isset( $bundles[ $cursor ] ) || $inline_edit['start'] < $bundles[ $cursor ]['start'] ) {
				$edits[] = $inline_edit;
			}
		}
		usort( $edits, static fn ( array $a, array $b ): int => $a['start'] <=> $b['start'] );
		foreach ( array_reverse( $edits ) as $edit ) {
			$html = substr_replace( $html, $edit['html'], $edit['start'], $edit['length'] );
		}
		$html = SafeAssetTrials::inline_body_css( CssOptimizer::combined_html( $html ) );
		return HtmlMinifier::wanted() ? HtmlMinifier::filter( $html, Fixes::stored()['html_keep_comments'] ) : $html;
	}


	/**
	 * A base URL or document policy may make an uploads URL behave differently.
	 *
	 * @param string $html Complete HTML.
	 */
	private static function restricted_html( string $html ): bool {
		$tags = new \WP_HTML_Tag_Processor( $html );
		$seen = 0;
		while ( $tags->next_tag() ) {
			if ( ++$seen > 10000 || 'BASE' === $tags->get_tag() ) {
				return true;
			}
			if ( 'META' === $tags->get_tag() && str_starts_with( strtolower( (string) $tags->get_attribute( 'http-equiv' ) ), 'content-security-policy' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Read one markup token without interpreting fake tags inside comments or raw text.
	 * Nested template/foreign containers are skipped as a whole, with a depth bound.
	 *
	 * @param string $html  Page HTML.
	 * @param int    $start Opening angle bracket.
	 * @param int    $depth Inert-container nesting.
	 * @return array{start: int, end: int, open: string, tag: string, close: bool, body: string}|null
	 */
	public static function token( string $html, int $start, int $depth = 0 ): ?array {
		if ( $depth > 20 ) {
			return null;
		}
		if ( '<!--' === substr( $html, $start, 4 ) ) {
			$end = strpos( $html, '-->', $start + 4 );
			return false === $end ? null : array(
				'start' => $start,
				'end'   => $end + 3,
				'open'  => '',
				'tag'   => '',
				'close' => false,
				'body'  => '',
			);
		}
		if ( ! preg_match( '/\A<(\/?)([a-z][a-z0-9:-]*)\b/i', substr( $html, $start, 100 ), $match ) ) {
			return array(
				'start' => $start,
				'end'   => $start + 1,
				'open'  => '',
				'tag'   => '',
				'close' => false,
				'body'  => '',
			);
		}
		$quote = '';
		$end   = $start;
		$limit = min( strlen( $html ), $start + 16384 );
		for ( ; $end < $limit; ++$end ) {
			$char = $html[ $end ];
			if ( '' !== $quote ) {
				if ( $char === $quote ) {
					$quote = '';
				}
			} elseif ( '"' === $char || "'" === $char ) {
				$quote = $char;
			} elseif ( '>' === $char ) {
				break;
			}
		}
		if ( $end === $limit ) {
			return null;
		}
		++$end;
		$tag   = strtolower( $match[2] );
		$token = array(
			'start' => $start,
			'end'   => $end,
			'open'  => substr( $html, $start, $end - $start ),
			'tag'   => $tag,
			'close' => '/' === $match[1],
			'body'  => '',
		);
		if ( $token['close'] ) {
			return $token;
		}
		if ( 'plaintext' === $tag ) {
			$token['end'] = strlen( $html );
			return $token;
		}
		if ( in_array( $tag, array( 'template', 'svg', 'math' ), true ) ) {
			$cursor = $end;
			for ( $nested = strpos( $html, '<', $cursor ); false !== $nested; $nested = strpos( $html, '<', $cursor ) ) {
				$child = self::token( $html, $nested, $depth + 1 );
				if ( null === $child ) {
					return null;
				}
				if ( $child['close'] && $tag === $child['tag'] ) {
					$token['end'] = $child['end'];
					return $token;
				}
				$cursor = $child['end'];
			}
			return null;
		}
		if ( in_array( $tag, array( 'script', 'style', 'textarea', 'title', 'xmp', 'iframe', 'noembed', 'noframes', 'noscript' ), true ) ) {
			if ( ! preg_match( '~</' . preg_quote( $tag, '~' ) . '\s*>~i', $html, $closing, PREG_OFFSET_CAPTURE, $end ) ) {
				return null;
			}
			$token['body'] = substr( $html, $end, $closing[0][1] - $end );
			$token['end']  = $closing[0][1] + strlen( $closing[0][0] );
		}
		return $token;
	}

	/**
	 * Inspect actual printed attributes as well as the WordPress registration.
	 *
	 * @param array{start: int, end: int, open: string, tag: string, close: bool, body: string} $token Token.
	 * @param bool                                                                              $css CSS enabled.
	 * @param bool                                                                              $js JavaScript enabled.
	 * @return array{start: int, end: int, open: string, source: string, kind: string, key: string, part: array{source: string, url: string, text?: string, id?: string}}|null
	 */
	private static function asset( array $token, bool $css, bool $js ): ?array {
		$inline = InlineAssets::inspect( $token );
		if ( null !== $inline && ( 'css' === $inline['kind'] ? $css : $js ) && InlineAssets::approved( $inline['id'], $inline['text'], $inline['kind'] ) ) {
			$handle = (string) preg_replace( '/-inline-css$/', '', $inline['id'] );
			if ( 'css' === $inline['kind'] && CssOptimizer::signed_in_only( $handle, wp_styles()->registered[ $handle ] ?? null ) ) {
				return null;
			}
			$tags = new \WP_HTML_Tag_Processor( $token['open'] );
			$tags->next_tag();
			$key = array( $inline['kind'], strtolower( (string) $tags->get_attribute( 'type' ) ), (string) $tags->get_attribute( 'nonce' ) );
			$key = 'css' === $inline['kind'] ? array_merge( $key, array( strtolower( (string) ( $tags->get_attribute( 'media' ) ?? 'all' ) ) ) ) : array_merge( $key, array( false, '' ) );
			return array(
				'start'  => $token['start'],
				'end'    => $token['end'],
				'open'   => $token['open'],
				'source' => InlineAssets::document_url(),
				'kind'   => $inline['kind'],
				'key'    => (string) wp_json_encode( $key ),
				'part'   => array(
					'source' => 'inline',
					'url'    => InlineAssets::document_url(),
					'text'   => $inline['text'],
					'id'     => $inline['id'],
				),
			);
		}
		$kind = 'link' === $token['tag'] ? 'css' : ( 'script' === $token['tag'] ? 'js' : '' );
		if ( '' === $kind || $token['close'] || ( 'css' === $kind && ! $css ) || ( 'js' === $kind && ( ! $js || '' !== trim( $token['body'] ) ) ) ) {
			return null;
		}
		$tags = new \WP_HTML_Tag_Processor( $token['open'] . ( 'js' === $kind ? '</script>' : '' ) );
		if ( ! $tags->next_tag() ) {
			return null;
		}
		$allowed = 'css' === $kind ? array( 'id', 'href', 'rel', 'media', 'type', 'nonce', 'data-arvaseo-minified', 'data-arvaseo-font-display' ) : array( 'id', 'src', 'type', 'defer', 'nonce', 'data-wp-strategy', 'data-arvaseo-minified' );
		if ( array_diff( $tags->get_attribute_names_with_prefix( '' ) ?? array(), $allowed ) ) {
			return null;
		}
		$id     = $tags->get_attribute( 'id' );
		$source = $tags->get_attribute( 'css' === $kind ? 'href' : 'src' );
		$suffix = '-' . $kind;
		if ( ! is_string( $id ) || ! str_ends_with( $id, $suffix ) || ! is_string( $source ) || '' === $source ) {
			return null;
		}
		$handle   = substr( $id, 0, -strlen( $suffix ) );
		$registry = 'css' === $kind ? wp_styles() : wp_scripts();
		$item     = $registry->registered[ $handle ] ?? null;
		if ( null === $item || ! is_string( $item->src ) || '' === $item->src ) {
			return null;
		}
		if ( 'css' === $kind && CssOptimizer::signed_in_only( $handle, $item ) ) {
			return null;
		}
		if ( ! InlineAssets::registered_safe( $handle, $kind, $registry ) ) {
			return null;
		}
		$external = ! AssetMinifier::same_origin( \WP_Http::make_absolute_url( $source, trailingslashit( $registry->base_url ) ), home_url( '/' ) );
		if ( $external && ! Fixes::applies( 'combine_external_' . $kind ) ) {
			return null;
		}
		$minified = ( $kind === $tags->get_attribute( 'data-arvaseo-minified' ) || ( 'css' === $kind && 'css' === $tags->get_attribute( 'data-arvaseo-font-display' ) ) ) && str_contains( $source, '/' . AssetMinifier::FOLDER . '/' );
		if ( ! $minified && self::source_key( $source, $registry->base_url, $external ) !== self::source_key( $item->src, $registry->base_url, $external ) ) {
			return null;
		}
		$type  = strtolower( (string) $tags->get_attribute( 'type' ) );
		$nonce = (string) $tags->get_attribute( 'nonce' );
		if ( 'css' === $kind ) {
			if ( 'stylesheet' !== strtolower( (string) $tags->get_attribute( 'rel' ) ) || ! in_array( $type, array( '', 'text/css' ), true ) || in_array( $handle, Fixes::stored()['css_exclude'], true ) ) {
				return null;
			}
			$key = array( $kind, $type, $nonce, strtolower( (string) ( $tags->get_attribute( 'media' ) ?? 'all' ) ) );
		} else {
			$strategy = (string) $tags->get_attribute( 'data-wp-strategy' );
			if ( ! in_array( $type, array( '', 'text/javascript', 'application/javascript' ), true ) || ! in_array( $strategy, array( '', 'defer' ), true ) || 'module' === $registry->get_data( $handle, 'type' ) || Exclusions::leaves_optimization( 'combine_js', $handle, $source ) ) {
				return null;
			}
			$key = array( $kind, $type, $nonce, null !== $tags->get_attribute( 'defer' ), $strategy );
		}
		$key = wp_json_encode( $key );
		if ( ! is_string( $key ) ) {
			return null;
		}
		return array(
			'start'  => $token['start'],
			'end'    => $token['end'],
			'open'   => $token['open'],
			'source' => $source,
			'kind'   => $kind,
			'key'    => $key,
			'part'   => array(
				'source' => $external ? 'external' : 'local',
				'url'    => $source,
			),
		);
	}

	/**
	 * Version-query differences do not change the registered source's local path.
	 *
	 * @param string $source Printed or registered URL.
	 * @param string $base WordPress asset base.
	 * @param bool   $external Whether the full query must match the external registration.
	 */
	private static function source_key( string $source, string $base, bool $external = false ): string {
		$source = \WP_Http::make_absolute_url( $source, trailingslashit( $base ) );
		$kind   = strtolower( pathinfo( (string) wp_parse_url( $source, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
		if ( $external && Fixes::uses( 'combine_' . $kind, Changes::CHANGING_ADDRESSES ) ) {
			$source = Addresses::external_key( $source );
		}
		$parts = wp_parse_url( $source );
		return is_array( $parts ) ? strtolower( (string) ( $parts['scheme'] ?? '' ) . '://' . (string) ( $parts['host'] ?? '' ) ) . ':' . ( $parts['port'] ?? '' ) . ( $parts['path'] ?? '' ) . ( $external && isset( $parts['query'] ) ? '?' . $parts['query'] : '' ) : '';
	}

	/**
	 * A failed bundle leaves every original byte and resource in place. When it failed because some
	 * of its files can't be combined (WordPress's block library starts with an @import, for
	 * instance), the files around them are still combined, in order, and those stay where they
	 * are: until 2.10.5 one such file left the whole run uncombined, while Arva SEO's renderer,
	 * whose run that file didn't reach, got a bundle, so the page's stored CSS matched none of
	 * its stylesheets.
	 *
	 * @param array<int, array{start: int, end: int, open: string, source: string, kind: string, key: string, part: array{source: string, url: string, text?: string, id?: string}}> $run Run.
	 * @param array<int, array{start: int, length: int, html: string}>                                                                                                               $edits Pending edits.
	 */
	private static function finish( array $run, array &$edits ): void {
		if ( count( $run ) < 2 ) {
			return;
		}
		$url = AssetBundles::create_parts( array_column( $run, 'part' ), $run[0]['kind'] );
		if ( null === $url ) {
			// Until revision 2 (2.10.5) one file that can't be combined left the whole run as it is.
			if ( ! Fixes::uses( 'css' === $run[0]['kind'] ? 'combine_css' : 'combine_js', Changes::COMBINE_AROUND ) ) {
				return;
			}
			$part = array();
			$cut  = false;
			foreach ( $run as $asset ) {
				if ( AssetBundles::combinable( $asset['part'], $asset['kind'] ) ) {
					$part[] = $asset;
					continue;
				}
				$cut = true;
				self::finish( $part, $edits );
				$part = array();
			}
			if ( $cut ) {
				self::finish( $part, $edits );
			}
			return;
		}
		if ( 'css' === $run[0]['kind'] ) {
			self::$css_members[ $url ] = array_map(
				static function ( array $asset ): array {
					if ( 'inline' === $asset['part']['source'] ) {
						return $asset['part'];
					}
					$tags = new \WP_HTML_Tag_Processor( $asset['open'] );
					$tags->next_tag();
					$id = (string) $tags->get_attribute( 'id' );
					return array(
						'source' => 'file',
						'file'   => CssSources::for_handle( substr( $id, 0, -4 ) ),
					);
				},
				$run
			);
		}
		AssetDiagnostics::bundle( $url, array_column( $run, 'source' ) );
		$open = 'css' === $run[0]['kind'] ? (string) preg_replace( '/\A<style\b/i', '<link rel="stylesheet"', $run[0]['open'] ) : $run[0]['open']; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- replaces the original approved styles in place.
		$tags = new \WP_HTML_Tag_Processor( $open . ( 'js' === $run[0]['kind'] ? '</script>' : '' ) );
		if ( ! $tags->next_tag() ) {
			return;
		}
		$tags->set_attribute( 'css' === $run[0]['kind'] ? 'href' : 'src', $url );
		$tags->set_attribute( 'data-arvaseo-combined', $run[0]['kind'] );
		$tag     = $tags->get_updated_html();
		$last    = $run[ count( $run ) - 1 ];
		$edits[] = array(
			'start'  => $run[0]['start'],
			'length' => $last['end'] - $run[0]['start'],
			'html'   => $tag,
		);
	}
}
