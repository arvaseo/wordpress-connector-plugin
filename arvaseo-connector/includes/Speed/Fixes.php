<?php
/**
 * PageSpeed fixes: what each one does, the Lighthouse audits it addresses, and its state.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Speed;

use ArvaSEO\Connector\Logging\AuditLogger;
use ArvaSEO\Connector\Support\Ids;

defined( 'ABSPATH' ) || exit;

/**
 * Every fix starts off. "Trying" applies it only for signed-in administrators, so they can check
 * the site before visitors see it; "On" applies it for everyone. A fix another speed plugin
 * already does is "handled" and never applied, so pages aren't processed twice. Arva SEO keeps
 * the same list (App\Domain\WordPress\SpeedFixes) and learns the states with the site's details.
 *
 * Stored in the arvaseo_connector_speed option (autoloaded: the front end reads it on every page).
 */
final class Fixes {

	public const OPTION = 'arvaseo_connector_speed';

	/** Turning every fix off from wp-config.php, for when the dashboard can't be reached. */
	public const KILL_SWITCH = 'ARVASEO_CONNECTOR_DISABLE_SPEED_FIXES';

	public const STATES = array( 'off', 'trying', 'on' );

	/** The WordPress extras "Remove unused WordPress extras" can switch off, and which start ticked. */
	public const EXTRAS = array(
		'emoji'          => true,
		'oembed'         => true,
		'dashicons'      => true,
		'jquery_migrate' => false,
		'block_css'      => false,
	);

	/** Scripts Defer scripts always leaves alone: jQuery (inline code often needs it) and consent tools. */
	public const DEFER_ALWAYS_EXCLUDED = array( 'jquery', 'jquery-core', 'jquery-migrate' );

	/** Script handles that look like consent or cookie tools, left alone too. */
	public const DEFER_EXCLUDED_PATTERN = '/(consent|cookie|gdpr|complianz|cmplz|cookiebot|iubenda|borlabs|termly|usercentrics)/i';

	/** Images at the top of each page that lazy loading leaves alone, by default and at most. */
	public const EAGER_IMAGES = 3;

	public const MAX_EAGER_IMAGES = 10;

	/**
	 * The fixes, in the order the page lists them.
	 *
	 * @return array<string, array{title: string, what: string, group: string, risk: string, audits: array<string, string>, handled_by: string[], states: string[]}>
	 */
	public static function all(): array {
		$speed_plugins = array( 'wp-rocket', 'litespeed-cache', 'perfmatters', 'flying-press', 'nitropack' );
		$fixes         = array(
			'page_cache'       => array(
				'title'      => __( 'Page caching', 'arvaseo-connector' ),
				'what'       => __( 'Each page is saved as ready-made HTML and sent to visitors without running WordPress, so it starts arriving in milliseconds instead of a second or more. Signed-in users, carts and checkouts, and pages that change for each visitor are never cached, and pages are cleared when you change them. Turning it on checks your homepage straight away: if it isn’t served from the cache, it’s turned off again.', 'arvaseo-connector' ),
				'group'      => 'sooner',
				'risk'       => 'check',
				'audits'     => array(
					'server-response-time'     => 'Reduce initial server response time',
					'document-latency-insight' => 'Document request latency',
				),
				'handled_by' => array_merge( PageCache::CACHE_PLUGINS, PageCache::HOST_PLUGINS ),
				// Signed-in users are never cached, so there's nothing to try first.
				'states'     => array( 'off', 'on' ),
			),
			'lcp_image'        => array(
				'title'      => __( 'Show the main image first', 'arvaseo-connector' ),
				'what'       => __( 'On posts and pages, the featured image loads straight away with a high priority instead of waiting its turn, so the largest thing on the screen appears sooner. On every page you tested with PageSpeed in Arva SEO, so does the image the test found as the largest thing on the screen, even when a slider or page builder shows it as a background.', 'arvaseo-connector' ),
				'group'      => 'sooner',
				'risk'       => 'safe',
				'audits'     => array(
					'lcp-lazy-loaded'                  => 'Largest Contentful Paint image was lazily loaded',
					'prioritize-lcp-image'             => 'Preload Largest Contentful Paint image',
					'lcp-discovery-insight'            => 'LCP request discovery',
					'largest-contentful-paint-element' => 'Largest Contentful Paint element',
				),
				'handled_by' => $speed_plugins,
				'states'     => self::STATES,
			),
			'lazy_media'       => array(
				'title'      => __( 'Lazy-load images, videos and maps', 'arvaseo-connector' ),
				'what'       => __( 'Images, videos and maps further down the page load only when a visitor scrolls near them. The first few images on a page always load straight away.', 'arvaseo-connector' ),
				'group'      => 'sooner',
				'risk'       => 'safe',
				'audits'     => array(
					'offscreen-images' => 'Defer offscreen images',
				),
				'handled_by' => array_merge( $speed_plugins, array( 'a3-lazy-load', 'rocket-lazy-load', 'sg-cachepress', 'w3-total-cache', 'autoptimize', 'wp-smushit' ) ),
				'states'     => self::STATES,
			),
			'critical_css'     => array(
				'title'      => __( 'Load styles faster', 'arvaseo-connector' ),
				'what'       => __( 'The styles each page needs for its first screen go straight into the page, and your theme’s and plugins’ stylesheets load without holding it up. Arva SEO works them out on its servers once for each type of page, using the page optimizations in your plan. You can also let icon fonts that a theme or widget prints into the page itself (Font Awesome and similar) load once the page has loaded.', 'arvaseo-connector' ),
				'group'      => 'sooner',
				'risk'       => 'check',
				'audits'     => array(
					'render-blocking-resources' => 'Eliminate render-blocking resources',
					'render-blocking-insight'   => 'Render blocking requests',
				),
				'handled_by' => array( 'wp-rocket', 'litespeed-cache', 'perfmatters', 'flying-press', 'nitropack', 'autoptimize', 'jetpack-boost' ),
				'states'     => self::STATES,
			),
			'unused_css'       => array(
				'title'      => __( 'Remove unused CSS', 'arvaseo-connector' ),
				'what'       => __( 'Each page gets only the styles it uses, worked out by Arva SEO for each type of page. The full stylesheets load on the visitor’s first scroll, tap, click or key press, so menus, pop-ups and sliders keep their styles.', 'arvaseo-connector' ),
				'group'      => 'sooner',
				'risk'       => 'check',
				'audits'     => array(
					'unused-css-rules'          => 'Reduce unused CSS',
					'render-blocking-resources' => 'Eliminate render-blocking resources',
					'render-blocking-insight'   => 'Render blocking requests',
				),
				'handled_by' => array( 'wp-rocket', 'litespeed-cache', 'perfmatters', 'flying-press', 'nitropack' ),
				'states'     => self::STATES,
			),
			'defer_scripts'    => array(
				'title'      => __( 'Defer scripts', 'arvaseo-connector' ),
				'what'       => __( 'Scripts in the page’s head load in the background and run once the page has been read, instead of holding it up. jQuery isn’t deferred, and consent tools and any scripts you list are left alone. You can also let jQuery move down the page, to just before the first script that may use it.', 'arvaseo-connector' ),
				'group'      => 'sooner',
				'risk'       => 'check',
				'audits'     => array(
					'render-blocking-resources' => 'Eliminate render-blocking resources',
					'render-blocking-insight'   => 'Render blocking requests',
				),
				'handled_by' => array_merge( $speed_plugins, array( 'autoptimize', 'jetpack-boost', 'sg-cachepress' ) ),
				'states'     => self::STATES,
			),
			'image_dimensions' => array(
				'title'      => __( 'Add missing image sizes', 'arvaseo-connector' ),
				'what'       => __( 'Images from your media library that are missing a width and height get them, so the browser keeps room for them and the page stops jumping while it loads.', 'arvaseo-connector' ),
				'group'      => 'still',
				'risk'       => 'safe',
				'audits'     => array(
					'unsized-images'        => 'Image elements do not have explicit width and height',
					'cls-culprits-insight'  => 'Layout shift culprits',
					'layout-shift-elements' => 'Avoid large layout shifts',
				),
				'handled_by' => $speed_plugins,
				'states'     => self::STATES,
			),
			'viewport'         => array(
				'title'      => __( 'Add the mobile viewport tag', 'arvaseo-connector' ),
				'what'       => __( 'Adds the tag that tells phones to show the page at their own width. Most themes already have it: turn this on only when PageSpeed says it’s missing.', 'arvaseo-connector' ),
				'group'      => 'still',
				'risk'       => 'safe',
				'audits'     => array(
					'viewport'         => 'Does not have a <meta name="viewport"> tag with width or initial-scale',
					'viewport-insight' => 'Optimize viewport for mobile',
				),
				'handled_by' => array(),
				'states'     => self::STATES,
			),
			'video_facades'    => array(
				'title'      => __( 'Click-to-play videos', 'arvaseo-connector' ),
				'what'       => __( 'YouTube videos show a preview picture with a play button, and the player loads when someone presses it. Until then nothing loads from YouTube except the picture.', 'arvaseo-connector' ),
				'group'      => 'nothing',
				'risk'       => 'check',
				'audits'     => array(
					'third-party-facades'   => 'Lazy load third-party resources with facades',
					'third-party-summary'   => 'Reduce the impact of third-party code',
					'third-parties-insight' => '3rd parties',
				),
				'handled_by' => array_merge( $speed_plugins, array( 'wp-youtube-lyte' ) ),
				'states'     => self::STATES,
			),
			'delay_scripts'    => array(
				'title'      => __( 'Delay chat widgets and trackers', 'arvaseo-connector' ),
				'what'       => __( 'Chat boxes, heatmaps and social widgets load on the visitor’s first scroll, tap, click or key press instead of while the page loads, so the page is ready to use sooner. Analytics and consent tools are never delayed unless you list them.', 'arvaseo-connector' ),
				'group'      => 'nothing',
				'risk'       => 'check',
				'audits'     => array(
					'third-party-summary'       => 'Reduce the impact of third-party code',
					'third-parties-insight'     => '3rd parties',
					'bootup-time'               => 'Reduce JavaScript execution time',
					'mainthread-work-breakdown' => 'Minimize main-thread work',
				),
				'handled_by' => array( 'wp-rocket', 'perfmatters', 'flying-press', 'nitropack', 'flying-scripts', 'litespeed-cache' ),
				'states'     => self::STATES,
			),
			'dns_prefetch'     => array(
				'title'      => __( 'DNS prefetch', 'arvaseo-connector' ),
				'what'       => __( 'Looks up the addresses of external services you list before the page needs them. Add only services the page actually uses, up to 10 domains. This does not change your DNS records.', 'arvaseo-connector' ),
				'group'      => 'sooner',
				'risk'       => 'safe',
				'audits'     => array( 'uses-rel-preconnect' => 'Preconnect to required origins' ),
				'handled_by' => array(),
				'states'     => self::STATES,
			),
			'preconnect'       => array(
				'title'      => __( 'Preconnect', 'arvaseo-connector' ),
				'what'       => __( 'Starts connections to the external services you list before their files are needed. Use only important services needed immediately, such as an external font or asset host, up to 4 origins. Too many early connections can slow a page down.', 'arvaseo-connector' ),
				'group'      => 'sooner',
				'risk'       => 'check',
				'audits'     => array( 'uses-rel-preconnect' => 'Preconnect to required origins' ),
				'handled_by' => array(),
				'states'     => self::STATES,
			),
			'minify_css'       => array(
				'title'      => __( 'Minify CSS', 'arvaseo-connector' ),
				'what'       => __( 'Serves smaller cached copies of local stylesheets by removing unnecessary spacing and comments. Original files stay intact, relative image and font addresses keep working, and file order stays the same. Already-minified or unsupported files are left alone.', 'arvaseo-connector' ),
				'group'      => 'smaller',
				'risk'       => 'check',
				'audits'     => array( 'unminified-css' => 'Minify CSS' ),
				'handled_by' => array_merge( $speed_plugins, array( 'autoptimize', 'w3-total-cache', 'sg-cachepress', 'breeze', 'hummingbird-performance', 'wp-optimize' ) ),
				'states'     => self::STATES,
			),
			'minify_js'        => array(
				'title'      => __( 'Minify JavaScript', 'arvaseo-connector' ),
				'what'       => __( 'Serves smaller cached copies of supported local classic scripts. Original files, dependencies, inline code and execution order stay intact. External, already-minified, module and other scripts that depend on their original address are left alone.', 'arvaseo-connector' ),
				'group'      => 'smaller',
				'risk'       => 'check',
				'audits'     => array( 'unminified-javascript' => 'Minify JavaScript' ),
				'handled_by' => array_merge( $speed_plugins, array( 'autoptimize', 'w3-total-cache', 'sg-cachepress', 'breeze', 'hummingbird-performance', 'wp-optimize' ) ),
				'states'     => self::STATES,
			),
			'combine_css'      => array(
				'title'      => __( 'Combine CSS', 'arvaseo-connector' ),
				'what'       => __( 'Combines adjacent compatible local stylesheets into one cached file, preserving their order and image or font addresses. When Critical CSS or Remove unused CSS is enabled, compatible files are combined first, then their optimized styles are generated. Unapproved inline styles and incompatible media rules stay separate. Try it and check your pages; fewer files do not always make a site faster.', 'arvaseo-connector' ),
				'group'      => 'smaller',
				'risk'       => 'check',
				'audits'     => array(),
				'handled_by' => array_merge( $speed_plugins, array( 'autoptimize', 'w3-total-cache', 'sg-cachepress', 'breeze', 'hummingbird-performance', 'wp-optimize' ) ),
				'states'     => self::STATES,
			),
			'combine_js'       => array(
				'title'      => __( 'Combine JavaScript', 'arvaseo-connector' ),
				'what'       => __( 'Combines adjacent compatible local classic scripts into one cached file in their existing order. Inline code, asynchronous, module, delayed and unsupported scripts stay separate. Original files stay intact. Try it and check menus, forms and checkout before enabling it for visitors.', 'arvaseo-connector' ),
				'group'      => 'smaller',
				'risk'       => 'check',
				'audits'     => array(),
				'handled_by' => array_merge( $speed_plugins, array( 'autoptimize', 'w3-total-cache', 'sg-cachepress', 'breeze', 'hummingbird-performance', 'wp-optimize' ) ),
				'states'     => self::STATES,
			),
			'google_fonts'     => array(
				'title'      => __( 'Faster Google Fonts', 'arvaseo-connector' ),
				'what'       => __( 'Text shows straight away in a similar font while Google Fonts load, and the browser connects to Google’s font servers early.', 'arvaseo-connector' ),
				'group'      => 'smaller',
				'risk'       => 'safe',
				'audits'     => array(
					'font-display'         => 'Ensure text remains visible during webfont load',
					'font-display-insight' => 'Font display',
					'uses-rel-preconnect'  => 'Preconnect to required origins',
				),
				'handled_by' => array_merge( $speed_plugins, array( 'autoptimize', 'sg-cachepress', 'host-webfonts-local' ) ),
				'states'     => self::STATES,
			),
			'wp_extras'        => array(
				'title'      => __( 'Remove unused WordPress extras', 'arvaseo-connector' ),
				'what'       => __( 'Switches off the parts of WordPress most sites don’t use, so pages load less. Choose which below.', 'arvaseo-connector' ),
				'group'      => 'smaller',
				'risk'       => 'check',
				'audits'     => array(
					'unused-javascript'         => 'Reduce unused JavaScript',
					'unused-css-rules'          => 'Reduce unused CSS',
					'render-blocking-resources' => 'Eliminate render-blocking resources',
				),
				'handled_by' => array(),
				'states'     => self::STATES,
			),
			'browser_caching'  => array(
				'title'      => __( 'Browser caching and compression', 'arvaseo-connector' ),
				'what'       => __( 'Visitors’ browsers keep your images, fonts, styles and scripts for a year instead of downloading them on every visit, and pages, styles and scripts are sent compressed. The rules go in your .htaccess file, and your site is checked straight away: if it doesn’t answer normally, they’re taken out again.', 'arvaseo-connector' ),
				'group'      => 'smaller',
				'risk'       => 'check',
				'audits'     => array(
					'uses-long-cache-ttl'      => 'Serve static assets with an efficient cache policy',
					'cache-insight'            => 'Use efficient cache lifetimes',
					'uses-text-compression'    => 'Enable text compression',
					'document-latency-insight' => 'Document request latency',
				),
				'handled_by' => array( 'wp-rocket', 'litespeed-cache', 'w3-total-cache', 'wp-fastest-cache', 'sg-cachepress', 'breeze', 'hummingbird-performance', 'wp-optimize', 'swift-performance-lite', 'nitropack' ),
				// Server rules apply to every visitor at once, so there's nothing to try first.
				'states'     => array( 'off', 'on' ),
			),
			'webp_uploads'     => array(
				'title'      => __( 'New uploads as WebP', 'arvaseo-connector' ),
				'what'       => __( 'Images you upload from now on also get smaller WebP copies, which WordPress shows instead. You can convert the images already in your media library too. Your originals always stay as they are.', 'arvaseo-connector' ),
				'group'      => 'smaller',
				'risk'       => 'safe',
				'audits'     => array(
					'modern-image-formats'   => 'Serve images in next-gen formats',
					'image-delivery-insight' => 'Improve image delivery',
					'uses-optimized-images'  => 'Efficiently encode images',
				),
				'handled_by' => array( 'webp-uploads', 'ewww-image-optimizer', 'imagify', 'shortpixel-image-optimiser', 'optimole-wp', 'webp-express', 'webp-converter-for-media' ),
				// Uploads aren't pages a visitor sees, so there's nothing to try first.
				'states'     => array( 'off', 'on' ),
			),
			'compress_images'  => array(
				'title'      => __( 'Compress images', 'arvaseo-connector' ),
				'what'       => __( 'Images you upload from now on are saved smaller: photos lose the hidden camera data and are saved at a quality you can’t tell apart, and PNGs are saved without loss. When this site is connected, Arva SEO’s servers compress them more. You can compress the images already in your media library too, and the originals are kept so you can put them back.', 'arvaseo-connector' ),
				'group'      => 'smaller',
				'risk'       => 'safe',
				'audits'     => array(
					'uses-optimized-images'  => 'Efficiently encode images',
					'image-delivery-insight' => 'Improve image delivery',
					'total-byte-weight'      => 'Avoid enormous network payloads',
				),
				'handled_by' => array( 'wp-smushit', 'ewww-image-optimizer', 'imagify', 'shortpixel-image-optimiser', 'optimole-wp', 'tiny-compress-images', 'resmushit-image-optimizer', 'wp-compress-image-optimizer' ),
				// Like WebP: nothing on a page to try first.
				'states'     => array( 'off', 'on' ),
			),
		);
		$advanced = array(
			'minify_inline_css'    => array(
				__( 'Minify inline CSS', 'arvaseo-connector' ),
				__( 'Removes unnecessary spacing from supported style blocks in the page. Their position and attributes stay intact; original theme files are unchanged.', 'arvaseo-connector' ),
			),
			'minify_inline_js'     => array(
				__( 'Minify inline JavaScript', 'arvaseo-connector' ),
				__( 'Makes supported classic inline scripts smaller in their original tags. JSON data, modules, excluded scripts and unsupported code are left alone.', 'arvaseo-connector' ),
			),
			'combine_inline_css'   => array(
				__( 'Combine inline CSS', 'arvaseo-connector' ),
				__( 'Includes the public static style blocks you approve in adjacent CSS bundles. Turn on Combine CSS too. Critical and used CSS stay separate; changed or unapproved snippets keep their original delivery.', 'arvaseo-connector' ),
			),
			'combine_inline_js'    => array(
				__( 'Combine inline JavaScript', 'arvaseo-connector' ),
				__( 'Includes approved public static inline scripts in adjacent synchronous JavaScript bundles. Turn on Combine JavaScript too. Personal configuration, modules and scripts with incompatible timing stay separate.', 'arvaseo-connector' ),
			),
			'combine_external_css' => array(
				__( 'Combine external CSS', 'arvaseo-connector' ),
				__( 'Includes approved public HTTPS stylesheets in CSS bundles once their background download is ready. Turn on Combine CSS too. Until then, or if a resource cannot be safely copied, its original delivery is kept.', 'arvaseo-connector' ),
			),
			'combine_external_js'  => array(
				__( 'Combine external JavaScript', 'arvaseo-connector' ),
				__( 'Includes approved public HTTPS classic scripts in compatible JavaScript bundles after background caching. Turn on Combine JavaScript too. Integrity-protected, personalized and unsupported scripts stay at their original addresses.', 'arvaseo-connector' ),
			),
			'font_display_swap'    => array(
				__( 'Font display: swap', 'arvaseo-connector' ),
				__( 'Text uses a fallback font while supported webfonts download. Adds font-display: swap to supported local, inline and generated CSS; approved external CSS is covered when combined. Theme files are unchanged.', 'arvaseo-connector' ),
			),
		);
		foreach ( $advanced as $id => $description ) {
			$kind         = str_ends_with( $id, '_js' ) ? 'js' : 'css';
			$owner        = str_starts_with( $id, 'combine_' ) ? 'combine_' . $kind : 'minify_' . $kind;
			$fixes[ $id ] = array(
				'title'      => $description[0],
				'what'       => $description[1],
				'group'      => 'smaller',
				'risk'       => 'check',
				'audits'     => array(),
				'handled_by' => $fixes[ $owner ]['handled_by'],
				'states'     => self::STATES,
			);
			if ( 'font_display_swap' === $id ) {
				$fixes[ $id ]['audits'] = array(
					'font-display'         => 'Font display',
					'font-display-insight' => 'Font display',
				);
			}
		}
		return $fixes;
	}

	/**
	 * The groups, in the words of what visitors notice.
	 *
	 * @return array<string, string>
	 */
	public static function groups(): array {
		return array(
			'sooner'  => __( 'Your page shows sooner', 'arvaseo-connector' ),
			'still'   => __( 'The page holds still and fits phones', 'arvaseo-connector' ),
			'nothing' => __( 'Less waiting for things nobody has asked for', 'arvaseo-connector' ),
			'smaller' => __( 'Smaller files', 'arvaseo-connector' ),
		);
	}

	/**
	 * Display names of the speed plugins that can make a fix "handled".
	 *
	 * @return array<string, string>
	 */
	public static function plugin_names(): array {
		return array(
			'wp-rocket'                   => 'WP Rocket',
			'litespeed-cache'             => 'LiteSpeed Cache',
			'perfmatters'                 => 'Perfmatters',
			'flying-press'                => 'FlyingPress',
			'nitropack'                   => 'NitroPack',
			'a3-lazy-load'                => 'a3 Lazy Load',
			'rocket-lazy-load'            => 'LazyLoad by WP Rocket',
			'sg-cachepress'               => 'Speed Optimizer (SiteGround)',
			'w3-total-cache'              => 'W3 Total Cache',
			'autoptimize'                 => 'Autoptimize',
			'wp-smushit'                  => 'Smush',
			'jetpack-boost'               => 'Jetpack Boost',
			'wp-youtube-lyte'             => 'WP YouTube Lyte',
			'host-webfonts-local'         => 'OMGF',
			'webp-uploads'                => 'Modern Image Formats',
			'ewww-image-optimizer'        => 'EWWW Image Optimizer',
			'imagify'                     => 'Imagify',
			'shortpixel-image-optimiser'  => 'ShortPixel',
			'optimole-wp'                 => 'Optimole',
			'webp-express'                => 'WebP Express',
			'webp-converter-for-media'    => 'Converter for Media',
			'wp-fastest-cache'            => 'WP Fastest Cache',
			'breeze'                      => 'Breeze',
			'hummingbird-performance'     => 'Hummingbird',
			'wp-optimize'                 => 'WP-Optimize',
			'swift-performance-lite'      => 'Swift Performance',
			'flying-scripts'              => 'Flying Scripts',
			'tiny-compress-images'        => 'TinyPNG',
			'resmushit-image-optimizer'   => 'reSmush.it',
			'wp-compress-image-optimizer' => 'WP Compress',
			'wp-super-cache'              => 'WP Super Cache',
			'cache-enabler'               => 'Cache Enabler',
			'comet-cache'                 => 'Comet Cache',
			'powered-cache'               => 'Powered Cache',
			'borlabs-cache'               => 'Borlabs Cache',
			'wp-cloudflare-page-cache'    => 'Super Page Cache',
		);
	}

	/**
	 * What's stored, over the defaults.
	 *
	 * @return array{fixes: array<string, array{state: string, since: string|null}>, extras: array<string, bool>, defer_exclude: string[], defer_plugins: string[], exclude_pages: string[], exclude_elements: string[], shop_pages: bool, eager_images: int, defer_checked_at: string|null, defer_on_at: int|null, server_check: array{at: string, cache: bool, compression: string|null}|null, fonts_local: bool, delay_scripts: string[], css_exclude: string[], dns_prefetch_origins: string[], preconnect_origins: string[], preconnect_anonymous: bool, remote: bool, ucss_inline: bool, ccss_per_url: bool, css_async_inline: bool, js_loading_mode: string, move_jquery: bool, late_icons: bool, combine_inline_css_ids: string[], combine_inline_js_ids: string[], external_asset_urls: string[]}
	 */
	public static function stored(): array {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$fixes = array();
		foreach ( self::all() as $id => $fix ) {
			$row          = is_array( $saved['fixes'][ $id ] ?? null ) ? $saved['fixes'][ $id ] : array();
			$state        = is_string( $row['state'] ?? null ) && in_array( $row['state'], $fix['states'], true ) ? $row['state'] : 'off';
			$fixes[ $id ] = array(
				'state' => $state,
				'since' => is_string( $row['since'] ?? null ) ? $row['since'] : null,
			);
		}
		$extras = array();
		foreach ( self::EXTRAS as $key => $default ) {
			$extras[ $key ] = (bool) ( $saved['extras'][ $key ] ?? $default );
		}
		return array(
			'fixes'                  => $fixes,
			'extras'                 => $extras,
			'defer_exclude'          => self::strings( $saved['defer_exclude'] ?? array() ),
			'defer_plugins'          => self::strings( $saved['defer_plugins'] ?? array() ),
			'exclude_pages'          => self::strings( $saved['exclude_pages'] ?? array() ),
			'exclude_elements'       => self::strings( $saved['exclude_elements'] ?? array() ),
			'shop_pages'             => (bool) ( $saved['shop_pages'] ?? true ),
			'eager_images'           => is_int( $saved['eager_images'] ?? null ) ? max( 0, min( self::MAX_EAGER_IMAGES, $saved['eager_images'] ) ) : self::EAGER_IMAGES,
			'defer_checked_at'       => is_string( $saved['defer_checked_at'] ?? null ) ? $saved['defer_checked_at'] : null,
			'defer_on_at'            => is_int( $saved['defer_on_at'] ?? null ) ? $saved['defer_on_at'] : null,
			'fonts_local'            => (bool) ( $saved['fonts_local'] ?? false ),
			'ucss_inline'            => (bool) ( $saved['ucss_inline'] ?? true ),
			'ccss_per_url'           => (bool) ( $saved['ccss_per_url'] ?? false ),
			'css_async_inline'       => (bool) ( $saved['css_async_inline'] ?? true ),
			'js_loading_mode'        => 'delayed' === ( $saved['js_loading_mode'] ?? '' ) ? 'delayed' : 'deferred',
			// 2.10.5: both start off, so updating never changes what an administrator already checked.
			'move_jquery'            => (bool) ( $saved['move_jquery'] ?? false ),
			'late_icons'             => (bool) ( $saved['late_icons'] ?? false ),
			'combine_inline_css_ids' => self::inline_approvals( $saved['combine_inline_css_ids'] ?? array() ),
			'combine_inline_js_ids'  => self::inline_approvals( $saved['combine_inline_js_ids'] ?? array() ),
			'external_asset_urls'    => ExternalAssets::normalize_urls( $saved['external_asset_urls'] ?? array() ),
			'delay_scripts'          => self::strings( $saved['delay_scripts'] ?? array() ),
			'css_exclude'            => self::strings( $saved['css_exclude'] ?? array() ),
			'dns_prefetch_origins'   => ResourceHints::normalize( $saved['dns_prefetch_origins'] ?? array(), 10 ),
			'preconnect_origins'     => ResourceHints::normalize( $saved['preconnect_origins'] ?? array(), 4 ),
			'preconnect_anonymous'   => (bool) ( $saved['preconnect_anonymous'] ?? false ),
			'remote'                 => (bool) ( $saved['remote'] ?? false ),
			'server_check'           => is_array( $saved['server_check'] ?? null ) && is_string( $saved['server_check']['at'] ?? null ) ? array(
				'at'          => $saved['server_check']['at'],
				'cache'       => (bool) ( $saved['server_check']['cache'] ?? false ),
				'compression' => is_string( $saved['server_check']['compression'] ?? null ) ? $saved['server_check']['compression'] : null,
			) : null,
		);
	}

	/**
	 * The strings in a stored list.
	 *
	 * @param mixed $stored Stored list.
	 * @return string[]
	 */
	private static function strings( $stored ): array {
		return array_values( array_filter( (array) $stored, 'is_string' ) );
	}

	/**
	 * Public static inline approvals bind an element ID to its exact content hash.
	 *
	 * @param mixed $value Stored list or textarea.
	 * @return string[]
	 */
	private static function inline_approvals( $value ): array {
		$lines = is_string( $value ) ? preg_split( '/\r?\n/', $value ) : (array) $value;
		$valid = array();
		foreach ( $lines ?: array() as $line ) {
			if ( is_string( $line ) && preg_match( '/\A[A-Za-z][A-Za-z0-9_:.-]{0,127}\|[a-f0-9]{64}\z/D', trim( $line ) ) ) {
				$valid[] = trim( $line );
			}
		}
		return array_slice( array_values( array_unique( $valid ) ), 0, 40 );
	}

	/**
	 * Save, keeping the stored shape.
	 *
	 * @param array<string, mixed> $data The stored() shape.
	 */
	private static function save( array $data ): void {
		update_option( self::OPTION, $data, true );
		// Arva SEO learns the new states with the site's details (scheduled right away).
		wp_schedule_single_event( time(), 'arvaseo_connector_register' );
	}

	/**
	 * The speed plugin already doing a fix's job, if one is active.
	 *
	 * @param string $id Fix ID.
	 */
	public static function handled_by( string $id ): ?string {
		if ( 'page_cache' === $id ) {
			// A cache plugin, or the host's own cache unless Arva SEO's was chosen instead.
			return PageCache::handled_by();
		}
		$fix = self::all()[ $id ] ?? null;
		if ( null === $fix || array() === $fix['handled_by'] ) {
			return null;
		}
		$active = self::active_plugin_slugs();
		foreach ( $fix['handled_by'] as $slug ) {
			if ( in_array( $slug, $active, true ) ) {
				return self::plugin_names()[ $slug ] ?? $slug;
			}
		}
		return null;
	}

	/**
	 * Folders of the active plugins (network-active ones too).
	 *
	 * @return string[]
	 */
	public static function active_plugin_slugs(): array {
		$files = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$files = array_merge( $files, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		return array_values( array_unique( array_map( static fn ( $file ) => dirname( (string) $file ), $files ) ) );
	}

	/**
	 * Why a fix can't run on this server, if it can't.
	 *
	 * @param string $id Fix ID.
	 */
	public static function unavailable( string $id ): ?string {
		if ( 'webp_uploads' === $id && ! wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) {
			return __( 'This server’s image library can’t make WebP images. Ask your host to enable WebP in Imagick or GD.', 'arvaseo-connector' );
		}
		if ( 'browser_caching' === $id && 'on' !== ( self::stored()['fixes'][ $id ]['state'] ?? 'off' ) ) {
			return ServerRules::unavailable();
		}
		if ( 'page_cache' === $id && 'on' !== ( self::stored()['fixes'][ $id ]['state'] ?? 'off' ) ) {
			return PageCache::unavailable();
		}
		if ( in_array( $id, array( 'critical_css', 'unused_css' ), true ) && 'off' === ( self::stored()['fixes'][ $id ]['state'] ?? 'off' ) && ! \ArvaSEO\Connector\Auth\TokenStore::is_connected() ) {
			return __( 'Connect to Arva SEO first: these styles are worked out on Arva SEO’s servers, using the page optimizations in your plan.', 'arvaseo-connector' );
		}
		return null;
	}

	/**
	 * A fix's state as the page shows it: off, trying, on, handled or unavailable.
	 *
	 * @param string $id Fix ID.
	 */
	public static function state( string $id ): string {
		if ( null !== self::handled_by( $id ) ) {
			return 'handled';
		}
		if ( null !== self::unavailable( $id ) ) {
			return 'unavailable';
		}
		return self::stored()['fixes'][ $id ]['state'] ?? 'off';
	}

	/**
	 * Whether the site's own code applies a fix for this request: on for everyone, or being tried
	 * by a signed-in administrator. Never when switched off in wp-config.php or handled elsewhere.
	 *
	 * @param string $id Fix ID.
	 */
	public static function applies( string $id ): bool {
		if ( defined( self::KILL_SWITCH ) && constant( self::KILL_SWITCH ) ) {
			return false;
		}
		$parents = array(
			'combine_inline_css'   => 'combine_css',
			'combine_external_css' => 'combine_css',
			'combine_inline_js'    => 'combine_js',
			'combine_external_js'  => 'combine_js',
		);
		if ( isset( $parents[ $id ] ) && ! self::applies( $parents[ $id ] ) ) {
			return false;
		}
		$state   = self::state( $id );
		$applies = ( 'on' === $state || ( 'trying' === $state && current_user_can( \ArvaSEO\Connector\Install\Capabilities::MANAGE ) ) )
			&& null === Exclusions::page_reason( $id );
		/**
		 * Whether a PageSpeed fix runs on the page being viewed.
		 *
		 * @param bool   $applies True when it runs.
		 * @param string $id      Fix ID, such as defer_scripts or lazy_media.
		 */
		return (bool) apply_filters( 'arvaseo_connector_speed_fix_applies', $applies, $id );
	}

	/**
	 * Change a fix's state. Defer scripts goes on only after it's been tried and the administrator
	 * confirmed they checked their important pages.
	 *
	 * @param string $id    Fix ID.
	 * @param string $state off|trying|on.
	 * @param bool   $checked Defer scripts: the checklist was ticked.
	 * @return string|null Why it didn't change (invalid, handled, unavailable, try_first,
	 *                     check_pages; for Browser caching not_written, unchecked or
	 *                     server_error; for Page caching dropin_not_written or cache_*), or null
	 *                     when it did.
	 */
	public static function set( string $id, string $state, bool $checked = false ): ?string {
		$fix = self::all()[ $id ] ?? null;
		if ( null === $fix || ! in_array( $state, $fix['states'], true ) ) {
			return 'invalid';
		}
		if ( 'off' !== $state && null !== self::handled_by( $id ) ) {
			return 'handled';
		}
		if ( 'off' !== $state && null !== self::unavailable( $id ) ) {
			return 'unavailable';
		}
		$data = self::stored();
		if ( 'defer_scripts' === $id && 'on' === $state ) {
			if ( 'trying' !== $data['fixes'][ $id ]['state'] ) {
				return 'try_first';
			}
			if ( ! $checked ) {
				return 'check_pages';
			}
			$data['defer_checked_at'] = Ids::iso();
			$data['defer_on_at']      = time();
		}
		if ( 'defer_scripts' === $id && 'on' !== $state ) {
			$data['defer_on_at'] = null;
		}
		if ( 'page_cache' === $id ) {
			if ( 'on' === $state ) {
				$error = PageCache::enable();
				if ( null !== $error ) {
					AuditLogger::warning(
						'speed.page_cache_refused',
						__( 'PageSpeed fix “Page caching” wasn’t turned on: your homepage wasn’t served from the cache when checked, so everything was taken out again.', 'arvaseo-connector' ),
						array( 'reason' => $error )
					);
					return $error;
				}
			} else {
				PageCache::disable();
			}
		}
		if ( 'browser_caching' === $id ) {
			if ( 'on' === $state ) {
				$check = ServerRules::enable();
				if ( null !== $check['error'] ) {
					AuditLogger::warning(
						'speed.server_rules_refused',
						__( 'PageSpeed fix “Browser caching and compression” wasn’t turned on: the site didn’t answer normally with the rules, so they were taken out again.', 'arvaseo-connector' ),
						array( 'reason' => $check['error'] )
					);
					return $check['error'];
				}
				$data['server_check'] = array(
					'at'          => Ids::iso(),
					'cache'       => $check['cache'],
					'compression' => $check['compression'],
				);
			} else {
				ServerRules::disable();
				$data['server_check'] = null;
			}
		}
		$from                 = $data['fixes'][ $id ]['state'];
		$data['fixes'][ $id ] = array(
			'state' => $state,
			'since' => $from === $state ? $data['fixes'][ $id ]['since'] : Ids::iso(),
		);
		self::save( $data );
		if ( ( 'on' === $from ) !== ( 'on' === $state ) ) {
			self::pages_changed( 'fix' );
		}
		AuditLogger::info(
			'speed.fix_changed',
			/* translators: 1: fix name, 2: new state (off, trying, on). */
			sprintf( __( 'PageSpeed fix “%1$s”: %2$s.', 'arvaseo-connector' ), $fix['title'], self::state_label( $state ) ),
			array(
				'fix'  => $id,
				'from' => $from,
				'to'   => $state,
			)
		);
		return null;
	}

	/**
	 * Save choices. Only the keys given change:
	 * - extras: the ticked extras (key => anything truthy);
	 * - defer_exclude: text box of script handles or parts of file addresses;
	 * - defer_plugins: plugin folders whose scripts Defer scripts leaves alone;
	 * - exclude_pages: text box of page addresses;
	 * - exclude_elements: text box of .classes, #ids or parts of file addresses;
	 * - shop_pages: leave shop pages alone (Defer scripts);
	 * - eager_images: images at the top of each page that load straight away;
	 * - fonts_local: Faster Google Fonts serves copies from the site;
	 * - delay_scripts: text box of scripts Delay chat widgets and trackers also delays;
	 * - move_jquery: Defer scripts moves jQuery out of the head (turning it on goes back to trying);
	 * - late_icons: Load styles faster loads icon libraries printed into the page after it.
	 *
	 * @param array<string, mixed> $choices Choices.
	 */
	public static function save_choices( array $choices ): void {
		$data = self::stored();
		foreach ( array( 'ucss_inline', 'ccss_per_url', 'css_async_inline' ) as $field ) {
			if ( array_key_exists( $field, $choices ) ) {
				$data[ $field ] = (bool) $choices[ $field ];
			}
		}
		if ( array_key_exists( 'js_loading_mode', $choices ) ) {
			$mode = 'delayed' === $choices['js_loading_mode'] ? 'delayed' : 'deferred';
			if ( $mode !== $data['js_loading_mode'] ) {
				$data = self::check_defer_again( $data );
			}
			$data['js_loading_mode'] = $mode;
		}
		if ( array_key_exists( 'move_jquery', $choices ) ) {
			$move = (bool) $choices['move_jquery'];
			if ( $move && ! $data['move_jquery'] ) {
				// What runs before jQuery changes: the administrator checks their pages again first.
				$data = self::check_defer_again( $data );
			}
			$data['move_jquery'] = $move;
		}
		if ( array_key_exists( 'late_icons', $choices ) ) {
			$data['late_icons'] = (bool) $choices['late_icons'];
		}
		foreach ( array( 'combine_inline_css_ids', 'combine_inline_js_ids' ) as $field ) {
			if ( array_key_exists( $field, $choices ) ) {
				$data[ $field ] = self::inline_approvals( $choices[ $field ] );
			}
		}
		if ( array_key_exists( 'external_asset_urls', $choices ) ) {
			$data['external_asset_urls'] = ExternalAssets::normalize_urls( $choices['external_asset_urls'] );
		}
		if ( array_key_exists( 'extras', $choices ) ) {
			foreach ( array_keys( self::EXTRAS ) as $key ) {
				$data['extras'][ $key ] = ! empty( ( (array) $choices['extras'] )[ $key ] );
			}
		}
		if ( array_key_exists( 'defer_exclude', $choices ) ) {
			$data['defer_exclude'] = Exclusions::script_lines( (string) $choices['defer_exclude'] );
		}
		if ( array_key_exists( 'defer_plugins', $choices ) ) {
			$data['defer_plugins'] = array_slice( array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $choices['defer_plugins'] ) ) ) ), 0, Exclusions::MAX_LINES );
		}
		if ( array_key_exists( 'exclude_pages', $choices ) ) {
			$data['exclude_pages'] = Exclusions::page_lines( (string) $choices['exclude_pages'] );
		}
		if ( array_key_exists( 'exclude_elements', $choices ) ) {
			$data['exclude_elements'] = Exclusions::element_lines( (string) $choices['exclude_elements'] );
		}
		if ( array_key_exists( 'shop_pages', $choices ) ) {
			$data['shop_pages'] = (bool) $choices['shop_pages'];
		}
		if ( array_key_exists( 'remote', $choices ) ) {
			$data['remote'] = (bool) $choices['remote'];
		}
		if ( array_key_exists( 'css_exclude', $choices ) ) {
			$data['css_exclude'] = array_slice( array_values( array_unique( array_filter( array_map( 'sanitize_key', Exclusions::lines( (string) $choices['css_exclude'] ) ) ) ) ), 0, Exclusions::MAX_LINES );
		}
		if ( array_key_exists( 'delay_scripts', $choices ) ) {
			$data['delay_scripts'] = Exclusions::script_lines( (string) $choices['delay_scripts'] );
		}
		if ( array_key_exists( 'fonts_local', $choices ) ) {
			$data['fonts_local'] = (bool) $choices['fonts_local'];
		}
		foreach ( array(
			'dns_prefetch_origins' => 10,
			'preconnect_origins'   => 4,
		) as $field => $limit ) {
			if ( array_key_exists( $field, $choices ) ) {
				$data[ $field ] = ResourceHints::normalize( $choices[ $field ], $limit );
			}
		}
		if ( array_key_exists( 'preconnect_anonymous', $choices ) ) {
			$data['preconnect_anonymous'] = (bool) $choices['preconnect_anonymous'];
		}
		if ( array_key_exists( 'eager_images', $choices ) ) {
			$data['eager_images'] = max( 0, min( self::MAX_EAGER_IMAGES, (int) $choices['eager_images'] ) );
		}
		self::save( $data );
		Exclusions::reset();
		if ( array() !== array_diff( array_keys( $choices ), array( 'remote' ) ) ) {
			self::pages_changed( 'choices' );
		}
		AuditLogger::info( 'speed.options_saved', __( 'PageSpeed fix choices saved.', 'arvaseo-connector' ), array( 'changed' => array_keys( $choices ) ) );
	}

	/**
	 * Defer scripts changes what it does: while it's on for visitors, it goes back to being tried,
	 * so the administrator checks their important pages again before visitors get the change.
	 *
	 * @param array<string, mixed> $data The stored() shape.
	 * @return array<string, mixed>
	 */
	private static function check_defer_again( array $data ): array {
		if ( 'on' === $data['fixes']['defer_scripts']['state'] ) {
			$data['fixes']['defer_scripts'] = array(
				'state' => 'trying',
				'since' => gmdate( 'c' ),
			);
			$data['defer_checked_at']       = null;
			$data['defer_on_at']            = null;
		}
		return $data;
	}

	/**
	 * What visitors get changed on every page: page caches (Cloudflare here) clear their copies.
	 *
	 * @param string $why Someone's change: fix, choices, exclusions, cleared, regenerate, webp,
	 *                    site or upgrade. Background ones, which can come many in a row: compress,
	 *                    css, fonts, external_assets. Or assets (unused generated files deleted).
	 */
	public static function pages_changed( string $why ): void {
		/**
		 * After a change that alters what visitors get on the site's pages.
		 *
		 * @param string $why fix, choices, exclusions, cleared, regenerate, webp, site, upgrade,
		 *                    assets, or a background change: compress, css, fonts, external_assets.
		 */
		do_action( 'arvaseo_connector_pages_changed', $why );
	}

	/**
	 * A state in words.
	 *
	 * @param string $state State.
	 */
	public static function state_label( string $state ): string {
		$labels = array(
			'off'         => __( 'Off', 'arvaseo-connector' ),
			'trying'      => __( 'Trying (only you see it)', 'arvaseo-connector' ),
			'on'          => __( 'On', 'arvaseo-connector' ),
			'handled'     => __( 'Done by another plugin', 'arvaseo-connector' ),
			'unavailable' => __( 'Not available on this server', 'arvaseo-connector' ),
		);
		return $labels[ $state ] ?? $state;
	}

	/**
	 * The states as Arva SEO receives them with the site's details.
	 *
	 * @return array<int, array{id: string, state: string, since: string|null, handled_by: string|null}>
	 */
	public static function for_registration(): array {
		$stored = self::stored();
		$rows   = array();
		foreach ( array_keys( self::all() ) as $id ) {
			$handled = self::handled_by( $id );
			$state   = self::state( $id );
			$rows[]  = array(
				'id'         => $id,
				'state'      => 'unavailable' === $state ? 'off' : $state,
				'since'      => in_array( $state, array( 'on', 'trying' ), true ) ? $stored['fixes'][ $id ]['since'] : null,
				'handled_by' => $handled,
			);
		}
		return $rows;
	}

	/**
	 * The fix for a Lighthouse audit, the main one first.
	 *
	 * @param string $audit_id Lighthouse audit ID.
	 * @return string[]
	 */
	public static function for_audit( string $audit_id ): array {
		return array_keys( array_filter( self::all(), static fn ( array $fix ) => isset( $fix['audits'][ $audit_id ] ) ) );
	}

	/**
	 * Anchor of a fix's card on the page.
	 *
	 * @param string $id Fix ID.
	 */
	public static function anchor( string $id ): string {
		return 'arvaseo-fix-' . str_replace( '_', '-', $id );
	}
}
