<?php
/**
 * Defer scripts, for the head scripts it can't defer: with "Move jQuery down the page", WordPress's
 * jQuery files, which every script that uses jQuery waits for, move down to just before the first
 * script that might need them, so they no longer hold up the first paint.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Speed;

defined( 'ABSPATH' ) || exit;

/**
 * The jQuery files are never deferred (Fixes::DEFER_ALWAYS_EXCLUDED): inline code and the footer scripts of
 * themes and builders call it as soon as they run. On most sites it is nevertheless printed in
 * the head, render-blocking, although nothing before the footer uses it. When the administrator
 * chose "Move jQuery down the page" (off until then; choosing it sends Defer scripts back to
 * being tried, so the pages are checked again), the page's final HTML shows whether that's so:
 * jQuery's tags (and their own inline parts) move, in their order, to just before the first
 * point where anything could run that might use jQuery:
 *
 * - a script that runs while the page is read: inline, or external without defer, or async;
 * - any element with an inline event handler (onclick="jQuery(...)");
 *
 * - a script held back with another type (type="text/plain", how consent tools such as
 *   Complianz, CookieYes or Cookiebot keep scripts from running until the visitor agrees): the
 *   tool makes it run at a time of its own, so it counts when it's an external file or its code
 *   mentions jQuery or adds scripts (since 2.10.7);
 * - the end of the body.
 *
 * Deferred scripts and modules run after the page is read, when jQuery has run anyway, and data
 * scripts (JSON, templates, speculation rules, Arva SEO's own delayed scripts) never run, so they
 * don't stop the move. If that point is still in the head, nothing moves. Code that runs whenever
 * it's ready also matters when it comes before jQuery: an async script; an inline loader that
 * adds scripts (Google Tag Manager's snippet, whose Custom HTML tags often call jQuery) or inline
 * code that mentions jQuery; a held-back script as above; and, since 2.10.7, an external script
 * that runs while the page is read and may add scripts: one from another site (a consent tool's
 * or a tag manager's, which nothing here can read), or one of this site's files whose code adds
 * scripts or mentions jQuery. In the head jQuery used to run before any of it could; lower down,
 * it would usually run after it, so then nothing moves either (Google's gtag.js never calls
 * jQuery, so it doesn't count, and arvaseo_connector_script_without_jquery can name others).
 * jQuery itself never writes into the page, so where it is read doesn't change what it does.
 * Listing jquery-core (or its file) under "Leave these scripts alone" keeps it in the head, and
 * developers can veto with arvaseo_connector_move_head_scripts.
 */
final class HeadScripts {

	/** The handles that move, in WordPress's order. */
	public const HANDLES = array( 'jquery-core', 'jquery-migrate' );

	/** Script types that run as classic or module JavaScript. */
	private const JS_TYPES = array( '', 'text/javascript', 'application/javascript', 'text/ecmascript', 'application/ecmascript', 'module' );

	/** This site's script files read to see whether they add scripts, up to this size. */
	private const MAX_READ = 524288;

	/** Whether this page's jQuery may move: Defer scripts applies with "Move jQuery down the page", and it isn't Arva SEO's renderer. */
	public static function wanted(): bool {
		return Fixes::applies( 'defer_scripts' ) && Fixes::stored()['move_jquery'] && ! CssOptimizer::is_render_request();
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
				if ( null === $part && self::runs_whenever( $html, $token ) ) {
					return $html; // Code before it that may run, and use jQuery, before jQuery's new place.
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
			$tags = substr( $html, $group['start'], $group['end'] - $group['start'] );
			if ( ! $in_body || ! in_array( 'jquery-core', $group['files'], true ) || self::listed( $tags ) ) {
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
			return substr( $html, 0, $group['start'] ) . substr( $html, $group['end'], $token['start'] - $group['end'] ) . $tags . "\n" . substr( $html, $token['start'] );
		}
		return $html;
	}

	/**
	 * Whether the administrator listed jQuery under Defer scripts' "Leave these scripts alone",
	 * by handle or by part of its file address: then it stays in the head as well.
	 *
	 * @param string $tags The tags that would move.
	 */
	private static function listed( string $tags ): bool {
		$sources = array();
		$scan    = new \WP_HTML_Tag_Processor( $tags );
		while ( $scan->next_tag( array( 'tag_name' => 'script' ) ) ) {
			$sources[] = (string) $scan->get_attribute( 'src' );
		}
		foreach ( Fixes::stored()['defer_exclude'] as $entry ) {
			$entry = trim( (string) $entry );
			if ( in_array( strtolower( $entry ), array( 'jquery', 'jquery-core', 'jquery-migrate' ), true ) ) {
				return true;
			}
			foreach ( $sources as $source ) {
				if ( '' !== $entry && '' !== $source && ( str_contains( $entry, '/' ) || str_contains( $entry, '.' ) ) && false !== stripos( $source, $entry ) ) {
					return true;
				}
			}
		}
		return false;
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
	 * Whether something before jQuery may run at a time of its own and use jQuery: an async script
	 * (other than one known never to use it), an inline script that adds scripts or mentions
	 * jQuery, or an inline event handler that mentions it. With jQuery in the head it ran first.
	 *
	 * @param string                                                                            $html  Page HTML.
	 * @param array{start: int, end: int, open: string, tag: string, close: bool, body: string} $token Token.
	 */
	private static function runs_whenever( string $html, array $token ): bool {
		if ( $token['close'] || '' === $token['tag'] ) {
			return false;
		}
		if ( preg_match_all( '/\son[a-z]+\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $token['open'], $handlers, PREG_SET_ORDER ) ) {
			foreach ( $handlers as $handler ) {
				if ( 1 === preg_match( '/\bjQuery\b/', html_entity_decode( implode( '', array_slice( $handler, 1 ) ) ) ) ) {
					return true;
				}
			}
		}
		if ( in_array( $token['tag'], array( 'svg', 'math', 'template' ), true ) ) {
			return 'template' !== $token['tag'] && false !== stripos( substr( $html, $token['start'], $token['end'] - $token['start'] ), '<script' );
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
			// Data and templates never run; a held-back script runs when its tool decides.
			return ! self::inert( $type ) && self::held_back_may_use_jquery( $tags, $token['body'] );
		}
		$src = $tags->get_attribute( 'src' );
		if ( is_string( $src ) ) {
			if ( null !== $tags->get_attribute( 'async' ) ) {
				return ! self::never_uses_jquery( $src );
			}
			if ( null !== $tags->get_attribute( 'defer' ) || 'module' === $type ) {
				return false; // Runs after the page has been read, when jQuery has run.
			}
			// It runs before jQuery wherever jQuery is, but what it adds runs when it has loaded.
			return self::may_add_scripts( $src );
		}
		if ( 'module' === $type && null === $tags->get_attribute( 'async' ) ) {
			return false; // Runs after the page has been read, when jQuery has run.
		}
		return self::code_may_use_jquery( $token['body'] );
	}

	/**
	 * Whether code mentions jQuery, imports a module or adds scripts (createElement('script')).
	 *
	 * @param string $code JavaScript.
	 */
	private static function code_may_use_jquery( string $code ): bool {
		return 1 === preg_match( '/\bjQuery\b|\bimport\s*\(/', $code )
			|| ( 1 === preg_match( '/createElement\s*\(/', $code ) && 1 === preg_match( '/[\'"`]script[\'"`]/i', $code ) );
	}

	/**
	 * Whether a script type holds data or a template, which no tool makes run: JSON (ld+json too),
	 * import maps, speculation rules, HTML templates, and Arva SEO's delayed scripts (run by its
	 * own loader after the first interaction, below jQuery's new place).
	 *
	 * @param string $type The type, lower case.
	 */
	private static function inert( string $type ): bool {
		return in_array( $type, array( 'importmap', 'speculationrules', 'text/html', 'arvaseo/delay' ), true )
			|| 1 === preg_match( '~(?:^|[/+])json$|template|tmpl|handlebars|mustache|x-jsrender~', $type );
	}

	/**
	 * Whether a held-back script (type="text/plain" and the like) may use jQuery once its tool
	 * makes it run: an external file (src, or data-src and similar, as tools keep it) that isn't
	 * known never to call jQuery, or code that may use it.
	 *
	 * @param \WP_HTML_Tag_Processor $tags The script's tag, found.
	 * @param string                 $code Its code.
	 */
	private static function held_back_may_use_jquery( \WP_HTML_Tag_Processor $tags, string $code ): bool {
		$sources = array( $tags->get_attribute( 'src' ) );
		foreach ( $tags->get_attribute_names_with_prefix( 'data-' ) ?? array() as $name ) {
			if ( str_ends_with( $name, 'src' ) ) {
				$sources[] = $tags->get_attribute( $name );
			}
		}
		foreach ( $sources as $src ) {
			if ( is_string( $src ) && '' !== trim( $src ) ) {
				return ! self::never_uses_jquery( $src );
			}
		}
		return self::code_may_use_jquery( $code );
	}

	/**
	 * Whether an external script that runs as soon as it's read may add scripts that use jQuery:
	 * one from another site may (a consent tool turning held-back scripts on, a tag manager), one
	 * of this site's files when its code adds scripts or mentions jQuery.
	 *
	 * @param string $src Script address.
	 */
	private static function may_add_scripts( string $src ): bool {
		if ( self::never_uses_jquery( $src ) ) {
			return false;
		}
		$file = AssetMinifier::source( html_entity_decode( $src ), 'js', true );
		if ( null === $file ) {
			return true; // Nothing here can read it.
		}
		$size = @filesize( $file['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a file removed meanwhile counts as unreadable.
		$code = false !== $size && $size <= self::MAX_READ ? file_get_contents( $file['path'] ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a bounded local script file.
		return ! is_string( $code ) || self::code_may_use_jquery( $code );
	}

	/**
	 * Whether a script that runs at a time of its own (async, held back by a consent tool, or
	 * added by a script before jQuery) is known never to call jQuery: Google's gtag.js (Google Tag
	 * Manager's gtm.js runs the site's own Custom HTML tags, so it isn't).
	 *
	 * @param string $src Script address.
	 */
	private static function never_uses_jquery( string $src ): bool {
		$host  = strtolower( (string) wp_parse_url( $src, PHP_URL_HOST ) );
		$path  = (string) wp_parse_url( $src, PHP_URL_PATH );
		$known = ( 'googletagmanager.com' === $host || str_ends_with( $host, '.googletagmanager.com' ) ) && str_starts_with( $path, '/gtag/js' );
		/**
		 * Whether a script never calls jQuery, nor adds scripts that do, so it doesn't keep jQuery
		 * in the head when "Move jQuery down the page" is on (async scripts, scripts a consent tool
		 * holds back, and external scripts before jQuery that may add scripts).
		 *
		 * @param bool   $known True for Google's gtag.js.
		 * @param string $src   The script's address.
		 */
		return (bool) apply_filters( 'arvaseo_connector_script_without_jquery', $known, $src );
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
			// Data, speculation rules, templates, delayed scripts: nothing runs. A script a consent
			// tool holds back runs once the tool says so, possibly while the page is still read.
			return ! self::inert( $type ) && self::held_back_may_use_jquery( $tags, $token['body'] );
		}
		if ( null !== $tags->get_attribute( 'async' ) ) {
			$src = $tags->get_attribute( 'src' );
			return ! ( is_string( $src ) && self::never_uses_jquery( $src ) );
		}
		if ( 'module' === $type ) {
			return false; // Modules run after the page has been read.
		}
		return ! ( is_string( $tags->get_attribute( 'src' ) ) && null !== $tags->get_attribute( 'defer' ) );
	}
}
