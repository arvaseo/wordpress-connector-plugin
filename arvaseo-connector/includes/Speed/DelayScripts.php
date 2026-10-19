<?php
/**
 * Delay chat widgets and trackers: chosen scripts load on the visitor's first scroll, tap, click or
 * key press instead of while the page loads.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Speed;

defined( 'ABSPATH' ) || exit;

/**
 * Only scripts WordPress prints (enqueued by the theme or a plugin) can be delayed, with their
 * inline parts. A delayed script's tags get type="arvaseo/delay", so the browser skips them; a
 * small loader puts them back, in their order, on the first interaction. Known chat, heatmap and
 * social widgets are delayed when the fix is on; anything else only when it's listed. Analytics,
 * tag managers and consent tools are never delayed unless listed by name, and a script other
 * scripts need is never delayed, so nothing waits on a script that isn't there.
 */
final class DelayScripts {

	public const TYPE = 'arvaseo/delay';

	/** Parts of the addresses of widgets that are fine to load once someone interacts. */
	public const KNOWN = array(
		'embed.tawk.to',
		'client.crisp.chat',
		'widget.intercom.io',
		'js.intercomcdn.com',
		'cdn.livechatinc.com',
		'code.tidio.co',
		'static.zdassets.com',
		'js.driftt.com',
		'js.hs-scripts.com',
		'js.usemessages.com',
		'static.hotjar.com',
		'script.hotjar.com',
		'www.clarity.ms',
		'connect.facebook.net/en_US/sdk.js',
		'platform.twitter.com/widgets.js',
		'assets.pinterest.com/js/pinit.js',
		'apps.elfsight.com',
		'static.elfsight.com',
	);

	/** Never delayed unless listed by name: measuring, tag managers and consent. */
	public const NEVER_PATTERN = '~(google-analytics\.com|googletagmanager\.com|gtag/js|analytics\.js|fbevents\.js|consent|cookie|gdpr|complianz|cookiebot|iubenda|usercentrics)~i';

	/**
	 * Delayed on this page: handle => true.
	 *
	 * @var array<string, bool>
	 */
	private static array $delayed = array();

	/**
	 * Hooks for a page where the fix applies.
	 */
	public static function on_page(): void {
		self::$delayed = array();
		add_action( 'wp_enqueue_scripts', array( self::class, 'choose' ), PHP_INT_MAX );
		add_filter( 'script_loader_tag', array( self::class, 'tag' ), 20, 2 );
		add_action( 'wp_footer', array( self::class, 'loader' ), PHP_INT_MAX );
	}

	/**
	 * Which queued scripts are delayed on this page.
	 */
	public static function choose(): void {
		$scripts = wp_scripts();
		$listed  = Fixes::stored()['delay_scripts'];
		$needed  = array();
		foreach ( $scripts->queue as $handle ) {
			$queued = $scripts->registered[ $handle ] ?? null;
			foreach ( null !== $queued ? $queued->deps : array() as $dep ) {
				$needed[ (string) $dep ] = true;
			}
		}
		foreach ( $scripts->queue as $handle ) {
			$script = $scripts->registered[ $handle ] ?? null;
			if ( null === $script || ! is_string( $script->src ) || '' === $script->src || isset( $needed[ $handle ] ) ) {
				continue;
			}
			if ( self::delays( (string) $handle, $script->src, $listed ) ) {
				self::$delayed[ (string) $handle ] = true;
			}
		}
	}

	/**
	 * Whether a script is delayed: listed (by handle or part of its address), or a known widget
	 * that isn't measuring or consent. The developer filter has the last word.
	 *
	 * @param string   $handle Script handle.
	 * @param string   $src    Script address.
	 * @param string[] $listed The administrator's list.
	 */
	public static function delays( string $handle, string $src, array $listed ): bool {
		$delay = false;
		foreach ( $listed as $entry ) {
			if ( $entry === $handle || ( ( str_contains( $entry, '/' ) || str_contains( $entry, '.' ) ) && false !== stripos( $src, $entry ) ) ) {
				$delay = true;
				break;
			}
		}
		if ( ! $delay && ! preg_match( self::NEVER_PATTERN, $handle . ' ' . $src ) ) {
			foreach ( self::KNOWN as $known ) {
				if ( false !== stripos( $src, $known ) ) {
					$delay = true;
					break;
				}
			}
		}
		/**
		 * Whether Delay chat widgets and trackers delays a script until the first interaction.
		 *
		 * @param bool   $delay  True to delay it.
		 * @param string $handle Script handle.
		 * @param string $src    Script address.
		 */
		return (bool) apply_filters( 'arvaseo_connector_delay_script', $delay, $handle, $src );
	}

	/**
	 * A delayed script's tags (with its inline parts): the browser skips them until the loader runs.
	 *
	 * @param mixed  $tag    The script's HTML.
	 * @param string $handle Script handle.
	 * @return mixed
	 */
	public static function tag( $tag, $handle ) {
		if ( ! is_string( $tag ) || ! isset( self::$delayed[ (string) $handle ] ) || str_contains( $tag, 'type="module"' ) ) {
			return $tag;
		}
		$tags = new \WP_HTML_Tag_Processor( $tag );
		while ( $tags->next_tag( array( 'tag_name' => 'script' ) ) ) {
			$type = $tags->get_attribute( 'type' );
			if ( is_string( $type ) && '' !== $type && ! in_array( strtolower( $type ), array( 'text/javascript', 'application/javascript' ), true ) ) {
				continue; // JSON data, templates and the like stay as they are.
			}
			$tags->set_attribute( 'type', self::TYPE );
			$address = $tags->get_attribute( 'src' );
			if ( is_string( $address ) ) {
				$tags->remove_attribute( 'src' );
				$tags->set_attribute( 'data-arvaseo-src', $address );
			}
		}
		return $tags->get_updated_html();
	}

	/**
	 * The loader, when something on the page was delayed.
	 */
	public static function loader(): void {
		if ( array() === self::$delayed ) {
			return;
		}
		$js = <<<'JS'
(function(){var done=false,ev=['pointerdown','keydown','touchstart','scroll','wheel','mousemove'];
function go(){if(done)return;done=true;ev.forEach(function(e){removeEventListener(e,go,{passive:true});});
var list=[].slice.call(document.querySelectorAll('script[type="arvaseo/delay"]'));
(function next(){var old=list.shift();if(!old)return;var s=document.createElement('script');
[].slice.call(old.attributes).forEach(function(a){if(a.name!=='type'&&a.name!=='data-arvaseo-src')s.setAttribute(a.name,a.value);});
var src=old.getAttribute('data-arvaseo-src');if(src){s.src=src;s.async=false;s.onload=s.onerror=next;}else{s.text=old.text;}
old.parentNode.replaceChild(s,old);if(!src)next();})();}
ev.forEach(function(e){addEventListener(e,go,{passive:true});});})();
JS;
		wp_print_inline_script_tag( $js, array( 'id' => 'arvaseo-delay-loader' ) );
	}

	/**
	 * What was delayed on this page (handles), for tests and the list shown while trying.
	 *
	 * @return string[]
	 */
	public static function delayed(): array {
		return array_keys( self::$delayed );
	}
}
