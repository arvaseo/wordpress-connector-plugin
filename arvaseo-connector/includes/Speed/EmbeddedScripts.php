<?php
/**
 * Explicit trials for directly embedded chat widgets, outside WordPress's enqueue system.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Speed;

defined( 'ABSPATH' ) || exit;

/** Never evaluates inline code, changes consent tools, or guesses unknown widget dependencies. */
final class EmbeddedScripts {
	/** Only self-contained widget loaders with a reviewed attribute contract. */
	private const LOADERS = array( 'widgets.leadconnectorhq.com/loader.js' );

	/**
	 * Tags actually transformed in this request.
	 *
	 * @var array<array<string,string>>
	 */
	private static array $delayed = array();

	/** Reset between responses, alongside the existing trial proof recorder. */
	public static function reset(): void {
		self::$delayed = array();
	}

	/**
	 * Respect known consent managers rather than hiding scripts from their blocker. This first
	 * version leaves such sites untouched; no category or grant is inferred from a widget name.
	 *
	 * @param string $html Complete HTML.
	 */
	public static function restricted( string $html ): bool {
		if ( preg_match( '~http-equiv\s*=\s*[\'\"]?Content-Security-Policy|data-(?:consent|cookieconsent|wpconsent)|cookiebot|complianz|__tcfapi~i', $html ) ) {
			return true;
		}
		foreach ( Fixes::active_plugin_slugs() as $slug ) {
			if ( preg_match( '/consent|cookie|complianz|iubenda|borlabs|gdpr/i', $slug ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Eligible original tags, bounded and privacy-minimized. A tag hash covers its URL including
	 * query and configuration, but only the public origin/path is retained for the review list.
	 * No remote JavaScript is downloaded. Vendor code can change independently of this hash.
	 *
	 * @param string $html Complete HTML.
	 * @return array<array<string,mixed>>
	 */
	private static function scan( string $html ): array {
		if ( strlen( $html ) > 4194304 || self::restricted( $html ) ) {
			return array();
		}
		$rows     = array();
		$urls     = array();
		$position = 0;
		$visited  = 0;
		for ( $start = strpos( $html, '<' ); false !== $start; $start = strpos( $html, '<', $position ) ) {
			$token = AssetCombiner::token( $html, $start );
			if ( null === $token || ++$visited > 10000 ) {
				return array();
			}
			$position = $token['end'];
			if ( 'script' !== $token['tag'] || $token['close'] ) {
				continue;
			}
			$tag = new \WP_HTML_Tag_Processor( $token['open'] . '</script>' );
			$tag->next_tag();
			$url = $tag->get_attribute( 'src' );
			if ( ! is_string( $url ) ) {
				// A coupled initializer cannot safely wait while its companion still runs immediately.
				if ( preg_match( '/leadconnector|LCChatWidget|chat-widget/i', $token['body'] ) ) {
					return array();
				}
				continue;
			}
			$parts = wp_parse_url( $url );
			if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) || isset( $parts['port'] ) || isset( $parts['fragment'] ) ) {
				continue;
			}
			$address = strtolower( $parts['host'] ?? '' ) . ( $parts['path'] ?? '' );
			if ( ! in_array( $address, self::LOADERS, true ) ) {
				continue;
			}
			$urls[ $address ] = ( $urls[ $address ] ?? 0 ) + 1;
			$id               = (string) $tag->get_attribute( 'id' );
			$handle           = (string) preg_replace( '/-js$/', '', $id );
			if ( '' !== trim( $token['body'] ) || isset( wp_scripts()->registered[ $handle ] ) || ! in_array( strtolower( (string) $tag->get_attribute( 'type' ) ), array( '', 'text/javascript', 'application/javascript' ), true )
				|| array_diff( $tag->get_attribute_names_with_prefix( '' ) ?? array(), array( 'src', 'id', 'type', 'defer', 'async', 'crossorigin', 'referrerpolicy', 'data-resources-url', 'data-widget-id' ) )
				|| Exclusions::leaves_script( $handle, $url ) ) {
				continue;
			}
			$rows[] = array(
				'feature' => 'embedded_js',
				'url'     => 'https://' . $address,
				'hash'    => hash( 'sha256', $token['open'] ),
				'start'   => $start,
				'end'     => $token['end'],
				'open'    => $token['open'],
				'tag'     => substr( $html, $start, $token['end'] - $start ),
			);
			if ( count( $rows ) > 20 ) {
				return array();
			}
		}
		return array_values( array_filter( $rows, static fn ( array $row ): bool => 1 === $urls[ substr( $row['url'], 8 ) ] ) );
	}

	/**
	 * Review candidates; source code and original query strings never enter the saved report.
	 *
	 * @param string $html Complete HTML.
	 * @return array<array<string,string>>
	 */
	public static function inspect( string $html ): array {
		$rows = array_merge( self::scan( $html ), self::$delayed );
		return array_map( static fn ( array $row ): array => array_intersect_key( $row, array_flip( array( 'feature', 'url', 'hash' ) ) ), $rows );
	}

	/**
	 * Transform only explicitly approved tag fingerprints. The existing complete-response buffer
	 * rejects CSP headers, partial documents and non-HTML before reaching this method.
	 *
	 * @param string $html Complete HTML.
	 */
	public static function filter( string $html ): string {
		if ( ! SafeAssetTrials::active( 'embedded_js' ) ) {
			return $html;
		}
		$rows = array_filter( self::scan( $html ), static fn ( array $row ): bool => SafeAssetTrials::approved( 'embedded_js', $row['url'], $row['open'] ) );
		if ( ! $rows || false === stripos( $html, '</body>' ) ) {
			return $html;
		}
		foreach ( array_reverse( $rows ) as $row ) {
			$html = substr_replace( $html, '<template data-arvaseo-embedded="1">' . $row['tag'] . '</template>', $row['start'], $row['end'] - $row['start'] );
			SafeAssetTrials::applied( 'embedded_js', $row['hash'], $row['url'] );
			self::$delayed[] = array_intersect_key( $row, array_flip( array( 'feature', 'url', 'hash' ) ) );
		}
		$loader = wp_get_inline_script_tag( self::loader(), array( 'id' => 'arvaseo-embedded-loader' ) );
		return substr_replace( $html, $loader, (int) strripos( $html, '</body>' ), 0 );
	}

	/** First interaction only; native script nodes retain their data attributes and position. */
	public static function loader(): string {
		return <<<'JS'
(function(){if(window.__arvaEmbedded)return;window.__arvaEmbedded=true;
var started=false,events=['pointerdown','keydown','touchstart','scroll','wheel'];
function start(){if(started)return;started=true;events.forEach(function(e){removeEventListener(e,start);});
var queue=[].slice.call(document.querySelectorAll('template[data-arvaseo-embedded="1"]'));
(function next(){var old=queue.shift();if(!old)return;var source=old.content.querySelector('script');
if(!source){next();return;}var type=(source.getAttribute('type')||'').toLowerCase();
if(type&&type!=='text/javascript'&&type!=='application/javascript'){next();return;}
var script=document.createElement('script');[].slice.call(source.attributes).forEach(function(a){script.setAttribute(a.name,a.value);});
script.async=false;var finished=false,timer=setTimeout(finish,15000);
function finish(){if(finished)return;finished=true;clearTimeout(timer);next();}
script.onload=script.onerror=finish;old.parentNode.replaceChild(script,old);})();}
events.forEach(function(e){addEventListener(e,start,{passive:true});});if(window.scrollY>0)start();})();
JS;
	}
}
