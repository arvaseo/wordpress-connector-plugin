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

	/** Start inside the page-cache buffer so it receives the final combined HTML. */
	public static function on_page(): void {
		if ( self::$buffer_started || ( ! Fixes::applies( 'combine_css' ) && ! Fixes::applies( 'combine_js' ) ) ) {
			return;
		}
		AssetBundles::reset();
		self::$buffer_started = ob_start( array( self::class, 'capture' ) );
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
		$css = Fixes::applies( 'combine_css' );
		$js  = Fixes::applies( 'combine_js' );
		if ( ( ! $css && ! $js ) || strlen( $html ) > self::MAX_HTML || ! preg_match( '~\A(?:\xEF\xBB\xBF)?\s*(?:<!doctype\s+html\b[^>]*>\s*)?<html\b~i', $html ) || false === stripos( $html, '</html>' ) || self::restricted_html( $html ) ) {
			return $html;
		}
		$position = 0;
		$visited  = 0;
		$run      = array();
		$edits    = array();
		for ( $start = strpos( $html, '<', $position ); false !== $start; $start = strpos( $html, '<', $position ) ) {
			$token = self::token( $html, $start );
			if ( null === $token || ++$visited > 10000 ) {
				return $html;
			}
			$asset = self::asset( $token, $css, $js );
			$last  = $run ? $run[ count( $run ) - 1 ] : null;
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
		foreach ( array_reverse( $edits ) as $edit ) {
			$html = substr_replace( $html, $edit['html'], $edit['start'], $edit['length'] );
		}
		return $html;
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
	private static function token( string $html, int $start, int $depth = 0 ): ?array {
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
	 * @return array{start: int, end: int, open: string, source: string, kind: string, key: string}|null
	 */
	private static function asset( array $token, bool $css, bool $js ): ?array {
		$kind = 'link' === $token['tag'] ? 'css' : ( 'script' === $token['tag'] ? 'js' : '' );
		if ( '' === $kind || $token['close'] || ( 'css' === $kind && ! $css ) || ( 'js' === $kind && ( ! $js || '' !== trim( $token['body'] ) ) ) ) {
			return null;
		}
		$tags = new \WP_HTML_Tag_Processor( $token['open'] . ( 'js' === $kind ? '</script>' : '' ) );
		if ( ! $tags->next_tag() ) {
			return null;
		}
		$allowed = 'css' === $kind ? array( 'id', 'href', 'rel', 'media', 'type', 'nonce', 'data-arvaseo-minified' ) : array( 'id', 'src', 'type', 'defer', 'nonce', 'data-wp-strategy', 'data-arvaseo-minified' );
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
		foreach ( array( 'conditional', 'before', 'after', 'data' ) as $extra ) {
			if ( $registry->get_data( $handle, $extra ) ) {
				return null;
			}
		}
		$minified = $kind === $tags->get_attribute( 'data-arvaseo-minified' ) && str_contains( $source, '/' . AssetMinifier::FOLDER . '/' );
		if ( ! $minified && self::source_key( $source, $registry->base_url ) !== self::source_key( $item->src, $registry->base_url ) ) {
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
			if ( ! in_array( $type, array( '', 'text/javascript', 'application/javascript' ), true ) || ! in_array( $strategy, array( '', 'defer' ), true ) || 'module' === $registry->get_data( $handle, 'type' ) || Exclusions::leaves_script( $handle, $source ) ) {
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
		);
	}

	/**
	 * Version-query differences do not change the registered source's local path.
	 *
	 * @param string $source Printed or registered URL.
	 * @param string $base WordPress asset base.
	 */
	private static function source_key( string $source, string $base ): string {
		$source = \WP_Http::make_absolute_url( $source, trailingslashit( $base ) );
		$parts  = wp_parse_url( $source );
		return is_array( $parts ) ? strtolower( (string) ( $parts['scheme'] ?? '' ) . '://' . (string) ( $parts['host'] ?? '' ) ) . ':' . ( $parts['port'] ?? '' ) . ( $parts['path'] ?? '' ) : '';
	}

	/**
	 * A failed bundle leaves every original byte and resource in place.
	 *
	 * @param array<int, array{start: int, end: int, open: string, source: string, kind: string, key: string}> $run Run.
	 * @param array<int, array{start: int, length: int, html: string}>                                         $edits Pending edits.
	 */
	private static function finish( array $run, array &$edits ): void {
		if ( count( $run ) < 2 ) {
			return;
		}
		$url = AssetBundles::create( array_column( $run, 'source' ), $run[0]['kind'] );
		if ( null === $url ) {
			return;
		}
		$tags = new \WP_HTML_Tag_Processor( $run[0]['open'] . ( 'js' === $run[0]['kind'] ? '</script>' : '' ) );
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
