<?php
/**
 * Conservative HTML savings without changing text-node whitespace or protected blocks.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Speed;

defined( 'ABSPATH' ) || exit;

/** Leave an unreadable document exactly as received; every edit waits until parsing completes. */
final class HtmlMinifier {

	/** Only public HTML or an administrator's explicit Trying preview is eligible. */
	public static function wanted(): bool {
		if ( ! Fixes::applies( 'minify_html' ) || CssOptimizer::is_render_request() || is_admin() || is_feed() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( function_exists( 'is_amp_endpoint' ) && is_amp_endpoint() ) || get_query_var( 'sitemap' ) || get_query_var( 'sitemap-stylesheet' ) ) {
			return false;
		}
		return ! is_user_logged_in() || ( 'trying' === Fixes::state( 'minify_html' ) && current_user_can( \ArvaSEO\Connector\Install\Capabilities::MANAGE ) );
	}

	/**
	 * Bounded, plain comment fragments from the exclusions textarea.
	 *
	 * @param mixed $value Stored list or form text.
	 * @return string[]
	 */
	public static function comments( $value ): array {
		$lines = is_string( $value ) ? preg_split( '/\r?\n/', $value ) : (array) $value;
		$valid = array();
		foreach ( array_slice( $lines ?: array(), 0, 100 ) as $line ) {
			if ( is_string( $line ) ) {
				$line = trim( substr( sanitize_text_field( $line ), 0, 200 ) );
				if ( '' !== $line ) {
					$valid[] = $line;
				}
			}
		}
		return array_values( array_unique( $valid ) );
	}

	/**
	 * Shrink tag formatting and ordinary comments. Text is never collapsed: CSS can make it significant.
	 *
	 * @param string   $html Complete document.
	 * @param string[] $keep Additional comment fragments to preserve.
	 */
	public static function filter( string $html, array $keep = array() ): string {
		if ( strlen( $html ) > 4194304 || ! preg_match( '~\A(?:\xEF\xBB\xBF)?\s*(?:<!doctype\s+html\b[^>]*>\s*)?<html\b~i', $html ) || false === stripos( $html, '</html>' ) || str_contains( $html, '<?' ) ) {
			return $html;
		}
		$position = 0;
		$visited  = 0;
		$edits    = array();
		for ( $start = strpos( $html, '<' ); false !== $start; $start = strpos( $html, '<', $position ) ) {
			$token = AssetCombiner::token( $html, $start );
			if ( null === $token || ++$visited > 10000 ) {
				return $html;
			}
			$position = $token['end'];
			if ( '<!--' === substr( $html, $start, 4 ) ) {
				$comment = substr( $html, $start, $position - $start );
				if ( ! self::keep_comment( $comment, $keep ) ) {
					$edits[] = array( $start, $position - $start, '' );
				}
				continue;
			}
			if ( in_array( $token['tag'], array( 'pre', 'listing' ), true ) && ! $token['close'] ) {
				$end = self::pre_end( $html, $position, $token['tag'] );
				if ( null === $end ) {
					return $html;
				}
				$position = $end;
				continue;
			}
			// The tokenizer consumes executable, raw-text and foreign/template containers in one piece.
			if ( '' === $token['tag'] || in_array( $token['tag'], array( 'script', 'style', 'textarea', 'title', 'xmp', 'iframe', 'noembed', 'noframes', 'noscript', 'plaintext', 'template', 'svg', 'math' ), true ) ) {
				continue;
			}
			$open = self::tag( $token['open'] );
			if ( $open !== $token['open'] ) {
				$edits[] = array( $start, strlen( $token['open'] ), $open );
			}
		}
		foreach ( array_reverse( $edits ) as $edit ) {
			$html = substr_replace( $html, $edit[2], $edit[0], $edit[1] );
		}
		return $html;
	}

	/**
	 * Skip a preformatted container in full, including its opening tag and comments.
	 *
	 * @param string $html     Complete document.
	 * @param int    $position Start of preformatted content.
	 * @param string $tag      Container name.
	 */
	private static function pre_end( string $html, int $position, string $tag ): ?int {
		$depth = 1;
		$count = 0;
		for ( $nested = strpos( $html, '<', $position ); false !== $nested; $nested = strpos( $html, '<', $position ) ) {
			$child = AssetCombiner::token( $html, $nested );
			if ( null === $child || ++$count > 10000 ) {
				return null;
			}
			$position = $child['end'];
			if ( $child['tag'] === $tag ) {
				$depth += $child['close'] ? -1 : 1;
				if ( 0 === $depth ) {
					return $position;
				}
			}
		}
		return null;
	}

	/**
	 * Preserve structural/conditional comments and known cache/builder markers.
	 *
	 * @param string   $comment Comment including delimiters.
	 * @param string[] $keep    Administrator's extra fragments.
	 */
	private static function keep_comment( string $comment, array $keep ): bool {
		if ( preg_match( '~^<!--\s*-->$|\[(?:if|endif)\b|\b(?:v-if|v-for|v-else|teleport|ssr:|htmx|alpine|x-if|x-for|container|bindings=)~i', $comment ) ) {
			return true; // Empty and framework hydration anchors are structural, not author notes.
		}
		if ( preg_match( '~^<!--\s*(?:[\[\]!#$/:@]|/?ko\b)|\b(?:wp:|WordPress|elementor|arvaseo|arva seo|cache|litespeed|wprocket|rocket|autoptimize|breeze|hummingbird|nitropack|perfmatters|esi|ssi|react|vue|svelte|ng-)~i', $comment ) ) {
			return true;
		}
		foreach ( array_slice( $keep, 0, 100 ) as $fragment ) {
			if ( is_string( $fragment ) && '' !== trim( $fragment ) && false !== stripos( $comment, $fragment ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Collapse only unquoted tag whitespace; preserve attribute bytes and unquoted-value boundaries.
	 *
	 * @param string $open Tag text.
	 */
	private static function tag( string $open ): string {
		$out   = '';
		$quote = '';
		$space = false;
		for ( $i = 0, $length = strlen( $open ); $i < $length; ++$i ) {
			$char = $open[ $i ];
			if ( '' !== $quote ) {
				$out .= $char;
				if ( $quote === $char ) {
					$quote = '';
				}
				continue;
			}
			if ( str_contains( " \t\r\n\f", $char ) ) {
				$space = true;
				continue;
			}
			if ( $space && ( '>' !== $char || ! in_array( substr( $out, -1 ), array( '"', "'" ), true ) ) ) {
				$out .= ' ';
			}
			$space = false;
			$out  .= $char;
			if ( '"' === $char || "'" === $char ) {
				$quote = $char;
			}
		}
		return $out;
	}
}
