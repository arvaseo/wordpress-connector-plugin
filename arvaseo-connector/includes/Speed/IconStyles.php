<?php
/**
 * Load styles faster, for icon fonts printed outside WordPress's styles: Font Awesome and similar
 * libraries that a theme or widget prints straight into the page load once the page has loaded.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Speed;

defined( 'ABSPATH' ) || exit;

/**
 * Widgets and themes often print an icon library's stylesheet as a plain link tag, inside the page
 * (Solace Extra's menu prints Font Awesome from cdnjs for every menu item with a sub-menu). It isn't
 * in WordPress's style queue, so Arva SEO has no used CSS for it and it keeps holding the page up:
 * scripts after it wait for it, and its font, often over 100 KB, loads with the page's first
 * paint. Icons are decoration, so such a stylesheet (and so its font) now loads once the page has
 * loaded, or at the visitor's first scroll, tap, click, key press or mouse move if that's sooner.
 *
 * Only known icon libraries move. A stylesheet WordPress printed from its queue is left to the
 * used-CSS delivery; one with its own onload handler, a media other than all or screen, or the
 * data-arvaseo-skip marker stays as it is, and a noscript copy keeps it for browsers without
 * JavaScript. Developers can decide per stylesheet with arvaseo_connector_late_icon_style.
 */
final class IconStyles {

	/** Parts of the addresses of icon font libraries. */
	public const KNOWN = array(
		'font-awesome',
		'fontawesome',
		'line-awesome',
		'bootstrap-icons',
		'remixicon',
		'boxicons',
		'ionicons',
		'material-icons',
		'material-symbols',
		'themify-icons',
		'simple-line-icons',
	);

	/** Font Awesome's own hosts, whose paths don't name it (use.fontawesome.com/releases/v5.15.4/css/all.css). */
	public const KNOWN_HOSTS = array( 'use.fontawesome.com', 'pro.fontawesome.com', 'ka-f.fontawesome.com', 'ka-p.fontawesome.com' );

	/** Moved tags carry their address here until the loader sets it. */
	public const ATTRIBUTE = 'data-arvaseo-late-href';

	/** Whether icon libraries load late on this page: Load styles faster or Remove unused CSS applies. */
	public static function wanted(): bool {
		return ( Fixes::applies( 'critical_css' ) || Fixes::applies( 'unused_css' ) )
			&& ! CssOptimizer::is_render_request()
			&& null === Exclusions::page_reason( 'critical_css' );
	}

	/**
	 * The page with its icon library links loading late, plus the loader, or unchanged.
	 *
	 * @param string $html Complete page HTML.
	 */
	public static function defer( string $html ): string {
		$edits    = array();
		$position = 0;
		$seen     = 0;
		for ( $start = strpos( $html, '<' ); false !== $start; $start = strpos( $html, '<', $position ) ) {
			$token = AssetCombiner::token( $html, $start );
			if ( null === $token || ++$seen > 10000 ) {
				return $html;
			}
			$position = $token['end'];
			if ( 'link' !== $token['tag'] || $token['close'] ) {
				continue; // Comments, raw text (scripts, noscript copies) and templates come as one token.
			}
			$late = self::late_tag( $token['open'] );
			if ( null !== $late ) {
				$edits[] = array( $token['start'], $token['end'] - $token['start'], $late );
			}
		}
		if ( array() === $edits ) {
			return $html;
		}
		$body_end = strripos( $html, '</body>' );
		$at       = false !== $body_end ? $body_end : strripos( $html, '</html>' );
		if ( false === $at || $at < $edits[ count( $edits ) - 1 ][0] ) {
			return $html; // Nowhere after the links to put the loader.
		}
		$html = substr_replace( $html, self::loader() . "\n", $at, 0 );
		foreach ( array_reverse( $edits ) as $edit ) {
			$html = substr_replace( $html, $edit[2], $edit[0], $edit[1] );
		}
		return $html;
	}

	/**
	 * A link tag that loads late, with its noscript copy, or null when it stays.
	 *
	 * @param string $tag The link tag as printed.
	 */
	private static function late_tag( string $tag ): ?string {
		$tags = new \WP_HTML_Tag_Processor( $tag );
		if ( ! $tags->next_tag( array( 'tag_name' => 'link' ) ) ) {
			return null;
		}
		$href  = $tags->get_attribute( 'href' );
		$media = strtolower( trim( (string) ( $tags->get_attribute( 'media' ) ?? 'all' ) ) );
		if ( 'stylesheet' !== strtolower( trim( (string) $tags->get_attribute( 'rel' ) ) ) || ! is_string( $href ) || '' === trim( $href )
			|| ! in_array( $media, array( '', 'all', 'screen' ), true ) || null !== $tags->get_attribute( Exclusions::SKIP_ATTRIBUTE )
			|| array() !== ( $tags->get_attribute_names_with_prefix( 'on' ) ?? array() ) || ! self::is_icon_library( $href ) || self::queued( $tags ) ) {
			return null;
		}
		/**
		 * Whether Load styles faster loads an icon library's stylesheet, printed outside WordPress's
		 * styles, once the page has loaded.
		 *
		 * @param bool   $late True to load it late.
		 * @param string $href The stylesheet's address.
		 */
		if ( ! (bool) apply_filters( 'arvaseo_connector_late_icon_style', true, $href ) ) {
			return null;
		}
		$tags->remove_attribute( 'href' );
		$tags->set_attribute( self::ATTRIBUTE, $href );
		$tags->set_attribute( 'data-arvaseo-media', '' === $media ? 'all' : $media );
		$tags->set_attribute( 'media', 'print' );
		return $tags->get_updated_html() . '<noscript>' . $tag . '</noscript>';
	}

	/**
	 * Whether an address is an icon font library's stylesheet.
	 *
	 * @param string $href Address.
	 */
	public static function is_icon_library( string $href ): bool {
		$href = html_entity_decode( $href, ENT_QUOTES );
		$path = strtolower( rawurldecode( (string) wp_parse_url( $href, PHP_URL_PATH ) ) );
		if ( ! str_ends_with( $path, '.css' ) ) {
			return false;
		}
		if ( in_array( strtolower( (string) wp_parse_url( $href, PHP_URL_HOST ) ), self::KNOWN_HOSTS, true ) ) {
			return true;
		}
		foreach ( self::KNOWN as $known ) {
			if ( str_contains( $path, $known ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether WordPress printed the link from its style queue ("handle-css"): used-CSS delivery
	 * handles those.
	 *
	 * @param \WP_HTML_Tag_Processor $tags The link.
	 */
	private static function queued( \WP_HTML_Tag_Processor $tags ): bool {
		$id = $tags->get_attribute( 'id' );
		if ( ! is_string( $id ) || ! str_ends_with( $id, '-css' ) ) {
			return false;
		}
		return isset( wp_styles()->registered[ substr( $id, 0, -4 ) ] );
	}

	/**
	 * The loader: after the page's load event (or the first interaction), each moved link gets its
	 * address and media back.
	 */
	private static function loader(): string {
		$js = <<<'JS'
(function(){var done=false,ev=['pointerdown','keydown','touchstart','scroll','wheel','mousemove'];
function go(){if(done)return;done=true;ev.forEach(function(e){removeEventListener(e,go,{passive:true});});
[].slice.call(document.querySelectorAll('link[data-arvaseo-late-href]')).forEach(function(l){
l.href=l.getAttribute('data-arvaseo-late-href');l.media=l.getAttribute('data-arvaseo-media')||'all';l.removeAttribute('data-arvaseo-late-href');});}
function later(){setTimeout(go,0);}
if(document.readyState==='complete'){later();}else{addEventListener('load',later);}
ev.forEach(function(e){addEventListener(e,go,{passive:true});});})();
JS;
		return wp_get_inline_script_tag( $js, array( 'id' => 'arvaseo-late-icons' ) );
	}
}
