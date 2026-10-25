<?php
/**
 * Defer scripts, for the head scripts it can't defer: WordPress's jQuery files, which every
 * script that uses jQuery waits for, move down to just before the first script that might need
 * them, so they no longer hold up the first paint.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Speed;

defined( 'ABSPATH' ) || exit;

/**
 * The jQuery files are never deferred (Fixes::DEFER_ALWAYS_EXCLUDED): inline code and the footer scripts of
 * themes and builders call it as soon as they run. On most sites it is nevertheless printed in
 * the head, render-blocking, although nothing before the footer uses it. The page's final HTML
 * shows whether that's so: jQuery's tags (and their own inline parts) move, in their order, to
 * just before the first point where anything could run that might use jQuery:
 *
 * - a script that runs while the page is read: inline, or external without defer, or async;
 * - any element with an inline event handler (onclick="jQuery(...)");
 * - the end of the body.
 *
 * Deferred scripts and modules run after the page is read, when jQuery has run anyway, and data
 * scripts (JSON, speculation rules, delayed scripts) don't run, so they don't stop the move. If
 * that point is still in the head, nothing moves. jQuery itself never writes into the page, so
 * where it is read doesn't change what it does. Developers can veto with
 * arvaseo_connector_move_head_scripts.
 */
final class HeadScripts {

	/** The handles that move, in WordPress's order. */
	public const HANDLES = array( 'jquery-core', 'jquery-migrate' );

	/** Script types that run as classic or module JavaScript. */
	private const JS_TYPES = array( '', 'text/javascript', 'application/javascript', 'text/ecmascript', 'application/ecmascript', 'module' );

	/** Whether this page's jQuery may move: Defer scripts applies, and it isn't Arva SEO's renderer. */
	public static function wanted(): bool {
		return Fixes::applies( 'defer_scripts' ) && ! CssOptimizer::is_render_request();
	}

	/**
	 * The page with jQuery's head tags moved down, or unchanged.
	 *
	 * @param string $html Complete page HTML.
	 */
	public static function move( string $html ): string {
		$position   = 0;
		$seen       = 0;
		$group      = null;
		$collecting = true;
		$in_body    = false;
		for ( $start = strpos( $html, '<' ); false !== $start; $start = strpos( $html, '<', $position ) ) {
			$token = AssetCombiner::token( $html, $start );
			if ( null === $token || ++$seen > 10000 ) {
				return $html;
			}
			$position = $token['end'];
			if ( '' === $token['tag'] ) {
				continue; // A comment, or a stray "<".
			}
			if ( null === $group ) {
				// jQuery's first tag, in the head.
				if ( 'body' === $token['tag'] || ( 'head' === $token['tag'] && $token['close'] ) ) {
					return $html;
				}
				$part = 'script' === $token['tag'] && ! $token['close'] ? self::part( $token ) : null;
				if ( false === $part ) {
					return $html; // Printed in a way that must stay (async, deferred, a module…).
				}
				if ( is_string( $part ) ) {
					$group = array(
						'start' => $token['start'],
						'end'   => $token['end'],
						'files' => array( $part ),
					);
				}
				continue;
			}
			if ( $collecting ) {
				// jQuery's other tags, right after it (only whitespace between).
				$gap  = substr( $html, $group['end'], $token['start'] - $group['end'] );
				$part = '' === trim( $gap ) && 'script' === $token['tag'] && ! $token['close'] ? self::part( $token ) : null;
				if ( false === $part ) {
					return $html;
				}
				if ( is_string( $part ) ) {
					$group['end']     = $token['end'];
					$group['files'][] = $part;
					continue;
				}
				$collecting = false;
			}
			$end_of_body = 'body' === $token['tag'] && $token['close'];
			if ( 'body' === $token['tag'] && ! $token['close'] ) {
				if ( self::is_barrier( $html, $token ) ) {
					return $html; // <body onload="…">
				}
				$in_body = true;
				continue;
			}
			if ( ! $end_of_body && ! self::is_barrier( $html, $token ) ) {
				continue;
			}
			// Something here may use jQuery: it goes just before, unless that's still in the head.
			if ( ! $in_body || ! in_array( 'jquery-core', $group['files'], true ) ) {
				return $html;
			}
			/**
			 * Whether Defer scripts moves WordPress's jQuery from the head to just before the first
			 * script of the page that might use it.
			 *
			 * @param bool     $move    True to move it.
			 * @param string[] $handles The handles that would move.
			 */
			if ( ! (bool) apply_filters( 'arvaseo_connector_move_head_scripts', true, $group['files'] ) ) {
				return $html;
			}
			$tags = substr( $html, $group['start'], $group['end'] - $group['start'] );
			return substr( $html, 0, $group['start'] ) . substr( $html, $group['end'], $token['start'] - $group['end'] ) . $tags . "\n" . substr( $html, $token['start'] );
		}
		return $html;
	}

	/**
	 * Whether a script tag is one of jQuery's: its handle, null for any other script, false for
	 * one of jQuery's printed in a way that must stay where it is.
	 *
	 * @param array{start: int, end: int, open: string, tag: string, close: bool, body: string} $token Script token.
	 * @return string|false|null
	 */
	private static function part( array $token ) {
		$tags = new \WP_HTML_Tag_Processor( $token['open'] . '</script>' );
		if ( ! $tags->next_tag() ) {
			return null;
		}
		$id = (string) $tags->get_attribute( 'id' );
		if ( ! preg_match( '/^(' . implode( '|', array_map( 'preg_quote', self::HANDLES ) ) . ')-js(-before|-after|-extra|-translations)?$/', $id, $m ) ) {
			return null;
		}
		$type   = strtolower( trim( (string) $tags->get_attribute( 'type' ) ) );
		$inline = isset( $m[2] ) && '' !== $m[2];
		$src    = $tags->get_attribute( 'src' );
		if ( ! in_array( $type, array( '', 'text/javascript', 'application/javascript' ), true ) || null !== $tags->get_attribute( 'async' ) || null !== $tags->get_attribute( 'defer' ) || null !== $tags->get_attribute( 'nomodule' ) || is_string( $src ) === $inline ) {
			return false;
		}
		return $m[1];
	}

	/**
	 * Whether anything at this token could run before the page has been read and use jQuery.
	 *
	 * @param string                                                                            $html  Page HTML.
	 * @param array{start: int, end: int, open: string, tag: string, close: bool, body: string} $token Token.
	 */
	private static function is_barrier( string $html, array $token ): bool {
		if ( $token['close'] ) {
			return false;
		}
		if ( 1 === preg_match( '/\son[a-z]+\s*=/i', $token['open'] ) ) {
			return true; // An inline event handler.
		}
		if ( in_array( $token['tag'], array( 'svg', 'math', 'template' ), true ) ) {
			return false !== stripos( substr( $html, $token['start'], $token['end'] - $token['start'] ), '<script' );
		}
		if ( 'script' !== $token['tag'] ) {
			return false;
		}
		$tags = new \WP_HTML_Tag_Processor( $token['open'] . '</script>' );
		if ( ! $tags->next_tag() ) {
			return true;
		}
		$type = strtolower( trim( (string) $tags->get_attribute( 'type' ) ) );
		if ( ! in_array( $type, self::JS_TYPES, true ) ) {
			return false; // Data, speculation rules, templates, delayed scripts: nothing runs now.
		}
		if ( null !== $tags->get_attribute( 'async' ) ) {
			return true;
		}
		if ( 'module' === $type ) {
			return false; // Modules run after the page has been read.
		}
		return ! ( is_string( $tags->get_attribute( 'src' ) ) && null !== $tags->get_attribute( 'defer' ) );
	}
}
