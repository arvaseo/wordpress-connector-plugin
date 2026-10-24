<?php
/**
 * Applies the PageSpeed fixes that are on (or being tried) to the pages visitors see.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Speed;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress filters only: image and script attributes, resource hints, embeds and the image
 * editor's output format. Nothing rewrites whole pages, the database or theme files, so turning
 * a fix off restores the original output at once. Pages only: never wp-admin, REST, feeds,
 * embeds, AMP or the Customizer.
 */
final class Runtime {

	/** Images on a page whose size we looked up, at most (each can be a database query). */
	private const MAX_LOOKUPS = 20;

	/**
	 * Images on this page so far, in the order they're output.
	 *
	 * @var int
	 */
	private static int $images_seen = 0;

	/**
	 * Images at the top of the page that always load straight away (one is usually the main image).
	 *
	 * @var int
	 */
	private static int $eager = Fixes::EAGER_IMAGES;

	/**
	 * Media-library lookups on this page so far.
	 *
	 * @var int
	 */
	private static int $lookups = 0;

	/**
	 * Whether the featured image has been given priority on this page.
	 *
	 * @var bool
	 */
	private static bool $main_image_done = false;

	/**
	 * On a page Arva SEO tested: the folder and name of the image the latest PageSpeed test found
	 * as the largest content, without size or extension (/wp-content/uploads/2026/10/hero), or null.
	 *
	 * @var string|null
	 */
	private static ?string $report_image = null;

	/**
	 * That image's address when a slider or page builder draws it as a background: preloaded in
	 * the head, since no <img> tag carries it.
	 *
	 * @var string|null
	 */
	private static ?string $preload = null;

	/**
	 * Scripts deferred on this page, shown to administrators trying the fix.
	 *
	 * @var string[]
	 */
	private static array $deferred = array();

	/**
	 * Hooks.
	 */
	public static function init(): void {
		// Uploads happen in the dashboard, so this one isn't limited to pages.
		add_filter( 'image_editor_output_format', array( self::class, 'webp_output' ) );
		add_action( 'template_redirect', array( self::class, 'on_page' ), 0 );
		add_action( 'admin_bar_menu', array( self::class, 'admin_bar' ), 90 );
		Exclusions::init();
		ServerRules::init();
		LocalFonts::init();
		WebpLibrary::init();
		ImageCompression::init();
		CssOptimizer::init();
		Cloudflare::init();
		PageCache::init();
	}

	/**
	 * A new plugin version must not keep serving HTML produced by the old speed code.
	 * Settings and generated CSS are preserved; only cached pages are invalidated once.
	 */
	public static function upgrade(): void {
		$option = 'arvaseo_connector_speed_version';
		if ( ARVASEO_CONNECTOR_VERSION === get_option( $option ) ) {
			return;
		}
		update_option( $option, ARVASEO_CONNECTOR_VERSION, false );
		// Report new capabilities promptly so Arva SEO does not keep showing an older plugin.
		if ( ! wp_next_scheduled( 'arvaseo_connector_register' ) ) {
			wp_schedule_single_event( time(), 'arvaseo_connector_register' );
		}
		foreach ( Fixes::stored()['fixes'] as $fix ) {
			if ( 'on' === $fix['state'] ) {
				Fixes::pages_changed( 'upgrade' );
				break;
			}
		}
	}

	/**
	 * For administrators while fixes are on or being tried: an Arva SEO item in the toolbar (a
	 * reminder that visitors don't see the fixes being tried), with "Leave this page alone" on
	 * the pages of the site.
	 *
	 * @param \WP_Admin_Bar $bar The toolbar.
	 */
	public static function admin_bar( $bar ): void {
		if ( ! current_user_can( \ArvaSEO\Connector\Install\Capabilities::MANAGE ) || ( defined( Fixes::KILL_SWITCH ) && constant( Fixes::KILL_SWITCH ) ) ) {
			return;
		}
		$states = array_map( array( Fixes::class, 'state' ), array_keys( Fixes::all() ) );
		$trying = count( array_keys( $states, 'trying', true ) );
		if ( 0 === $trying && ! in_array( 'on', $states, true ) ) {
			return;
		}
		$bar->add_node(
			array(
				'id'    => 'arvaseo-speed-trying',
				'title' => esc_html(
					$trying > 0
						/* translators: %d: number of fixes being tried. */
						? sprintf( _n( 'Arva SEO: trying %d PageSpeed fix', 'Arva SEO: trying %d PageSpeed fixes', $trying, 'arvaseo-connector' ), $trying )
						: __( 'Arva SEO: PageSpeed fixes', 'arvaseo-connector' )
				),
				'href'  => admin_url( 'admin.php?page=arvaseo-speed' ),
				'meta'  => array( 'title' => $trying > 0 ? __( 'Only you see the fixes you’re trying while signed in. Visitors see your site as before.', 'arvaseo-connector' ) : __( 'PageSpeed fixes are on for your visitors.', 'arvaseo-connector' ) ),
			)
		);
		if ( PageCache::active() ) {
			$bar->add_node(
				array(
					'parent' => 'arvaseo-speed-trying',
					'id'     => 'arvaseo-page-cache-clear',
					'title'  => esc_html__( 'Clear the page cache', 'arvaseo-connector' ),
					'href'   => wp_nonce_url(
						add_query_arg(
							array(
								'action' => 'arvaseo_connector_page_cache',
								'do'     => 'clear',
							),
							admin_url( 'admin-post.php' )
						),
						'arvaseo_connector_page_cache'
					),
				)
			);
		}
		Exclusions::toolbar_items( $bar, 'arvaseo-speed-trying' );
	}

	/**
	 * A page a visitor sees: hook the fixes that apply.
	 */
	public static function on_page(): void {
		if ( ! self::is_page_request() ) {
			return;
		}
		CssOptimizer::reset();
		AssetMinifier::reset();
		// Arva SEO's renderer: the page with its original styles, uncached, and none of the fixes.
		if ( CssOptimizer::is_render_request() ) {
			CssOptimizer::serve_original();
			return;
		}
		Exclusions::reset();
		self::$images_seen     = 0;
		self::$lookups         = 0;
		self::$main_image_done = false;
		self::$deferred        = array();
		self::$eager           = Fixes::stored()['eager_images'];
		$main                  = Fixes::applies( 'lcp_image' ) ? self::report_main() : null;
		self::$report_image    = null !== $main ? self::image_stem( $main['image'] ) : null;
		self::$preload         = null !== $main && 'background' === $main['element'] ? $main['image'] : null;

		if ( Fixes::applies( 'lcp_image' ) ) {
			add_filter( 'wp_get_attachment_image_attributes', array( self::class, 'main_image' ), 20, 2 );
		}
		if ( null !== $main && function_exists( 'wp_high_priority_element_flag' ) ) {
			// Only the test's main image gets the "load first" hint, not the image WordPress guesses (often the logo).
			wp_high_priority_element_flag( false );
		}
		if ( null !== self::$preload ) {
			add_action( 'wp_head', array( self::class, 'preload_main_image' ), 1 );
		}
		if ( Fixes::applies( 'image_dimensions' ) || Fixes::applies( 'lazy_media' ) || Fixes::applies( 'video_facades' ) ) {
			// A block whose class is in the list leaves everything inside it alone.
			add_filter( 'pre_render_block', array( Exclusions::class, 'block_start' ), 10, 2 );
			add_filter( 'render_block', array( Exclusions::class, 'block_end' ), PHP_INT_MAX, 2 );
		}
		if ( Fixes::applies( 'image_dimensions' ) || Fixes::applies( 'lazy_media' ) || null !== self::$report_image ) {
			add_filter( 'render_block', array( self::class, 'filter_html' ), 20 );
			add_filter( 'the_content', array( self::class, 'filter_html' ), 20 );
			add_filter( 'widget_text_content', array( self::class, 'filter_html' ), 20 );
			add_filter( 'widget_block_content', array( self::class, 'filter_html' ), 20 );
			add_filter( 'get_custom_logo', array( self::class, 'filter_html' ), 20 );
		}
		if ( Fixes::applies( 'video_facades' ) ) {
			add_filter( 'embed_oembed_html', array( self::class, 'youtube_facade' ), 20, 2 );
			add_filter( 'render_block', array( self::class, 'youtube_block' ), 20, 2 );
		}
		if ( Fixes::applies( 'google_fonts' ) ) {
			add_filter( 'style_loader_src', array( self::class, 'font_display' ), 20 );
			add_filter( 'wp_resource_hints', array( self::class, 'font_preconnect' ), 20, 2 );
		}
		// Keep tag attributes, dependencies and order: filter only the selected asset address.
		// Source: https://developer.wordpress.org/reference/hooks/style_loader_tag/.
		if ( Fixes::applies( 'minify_css' ) ) {
			add_filter( 'style_loader_tag', array( AssetMinifier::class, 'style_tag' ), 30, 4 );
		}
		if ( Fixes::applies( 'minify_js' ) ) {
			add_filter( 'script_loader_tag', array( AssetMinifier::class, 'script_tag' ), 30, 3 );
		}
		if ( Fixes::applies( 'dns_prefetch' ) || Fixes::applies( 'preconnect' ) ) {
			add_filter( 'wp_resource_hints', array( ResourceHints::class, 'filter' ), 99, 2 );
		}
		if ( Fixes::applies( 'defer_scripts' ) ) {
			add_action( 'wp_enqueue_scripts', array( self::class, 'defer_scripts' ), PHP_INT_MAX );
			add_action( 'wp_footer', array( self::class, 'remember_deferred' ), PHP_INT_MAX );
		}
		if ( Fixes::applies( 'delay_scripts' ) ) {
			DelayScripts::on_page();
		}
		if ( CssOptimizer::wanted() && null === Exclusions::page_reason( 'critical_css' ) ) {
			CssOptimizer::on_page();
		}
		if ( Fixes::applies( 'webp_uploads' ) ) {
			WebpLibrary::on_page();
		}
		if ( Fixes::applies( 'wp_extras' ) ) {
			self::remove_extras();
		}
		if ( Fixes::applies( 'viewport' ) ) {
			add_action( 'wp_head', array( self::class, 'viewport' ), 0 );
		}
	}

	/**
	 * A page request, as opposed to the dashboard, the REST API, feeds, embeds, AMP or the
	 * Customizer preview.
	 */
	public static function is_page_request(): bool {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || wp_is_json_request() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}
		if ( is_feed() || is_embed() || is_robots() || is_trackback() || is_customize_preview() ) {
			return false;
		}
		return ! ( function_exists( 'amp_is_request' ) && amp_is_request() );
	}

	// ----- Show the main image first ---------------------------------------------------------

	/**
	 * The featured image of the post or page being viewed: load it now, with a high priority.
	 *
	 * @param array<string, string> $attr       Image attributes.
	 * @param \WP_Post              $attachment The image.
	 * @return array<string, string>
	 */
	public static function main_image( array $attr, $attachment ): array {
		if ( self::$main_image_done ) {
			return $attr;
		}
		$post_id  = is_singular() ? get_queried_object_id() : 0;
		$featured = $post_id > 0 && (int) get_post_thumbnail_id( $post_id ) === (int) $attachment->ID;
		if ( ! $featured && ! self::is_report_image( (string) ( $attr['src'] ?? '' ), (string) ( $attr['srcset'] ?? '' ) ) ) {
			return $attr;
		}
		self::$main_image_done = true;
		unset( $attr['loading'] );
		$attr['fetchpriority'] = 'high';
		$attr['decoding']      = 'async';
		return $attr;
	}

	// ----- Image sizes and lazy loading --------------------------------------------------------

	/**
	 * Add missing width and height to media-library images, and lazy loading to images below the
	 * first few and to embedded frames.
	 *
	 * @param mixed $html Block, content or widget output.
	 * @return mixed
	 */
	public static function filter_html( $html ) {
		if ( ! is_string( $html ) || ( false === stripos( $html, '<img' ) && false === stripos( $html, '<iframe' ) ) ) {
			return $html;
		}
		$sizes = Fixes::applies( 'image_dimensions' );
		$lazy  = Fixes::applies( 'lazy_media' );
		$tags  = new \WP_HTML_Tag_Processor( $html );
		while ( $tags->next_tag() ) {
			$name = $tags->get_tag();
			if ( 'IMG' === $name ) {
				// Nested blocks reach this filter again inside their parents: each image counts once.
				if ( null !== $tags->get_attribute( 'data-arvaseo-seen' ) ) {
					continue;
				}
				$tags->set_attribute( 'data-arvaseo-seen', '1' );
				++self::$images_seen;
				$attrs = Exclusions::attributes( $tags );
				if ( ! self::$main_image_done && self::is_report_image( (string) $tags->get_attribute( 'src' ), (string) $tags->get_attribute( 'srcset' ) ) ) {
					// The image the latest PageSpeed test found as this page's largest content.
					self::$main_image_done = true;
					if ( 'lazy' === $tags->get_attribute( 'loading' ) ) {
						$tags->remove_attribute( 'loading' );
					}
					$tags->set_attribute( 'fetchpriority', 'high' );
				}
				if ( $sizes && ! Exclusions::leaves_element( 'image_dimensions', $name, $attrs ) ) {
					self::add_size( $tags );
				}
				if ( $lazy && self::$images_seen > self::$eager && null === $tags->get_attribute( 'loading' ) && 'high' !== $tags->get_attribute( 'fetchpriority' ) && ! Exclusions::leaves_element( 'lazy_media', $name, $attrs ) ) {
					$tags->set_attribute( 'loading', 'lazy' );
				}
			} elseif ( 'IFRAME' === $name && $lazy && null === $tags->get_attribute( 'loading' ) && ! Exclusions::leaves_element( 'lazy_media', $name, Exclusions::attributes( $tags ) ) ) {
				$tags->set_attribute( 'loading', 'lazy' );
			}
		}
		return $tags->get_updated_html();
	}

	/**
	 * The image the latest PageSpeed test of this page found as its largest content, and how it's
	 * drawn: img, or background (a slider's or page builder's). Arva SEO before 2026-10-23 only
	 * named the homepage's.
	 *
	 * @return array{image: string, element: string}|null
	 */
	private static function report_main(): ?array {
		$images = \ArvaSEO\Connector\Health\AssessmentService::main_images();
		if ( null !== $images ) {
			return $images[ Exclusions::path_key( Exclusions::current_path() ) ] ?? null;
		}
		if ( ! is_front_page() ) {
			return null;
		}
		$result = \ArvaSEO\Connector\Health\AssessmentService::pagespeed_result();
		$image  = $result['lcp_image'] ?? null;
		return is_string( $image ) ? array(
			'image'   => $image,
			'element' => 'background' === ( $result['lcp_element'] ?? '' ) ? 'background' : 'img',
		) : null;
	}

	/**
	 * A background main image, fetched first: printed at the top of the head.
	 */
	public static function preload_main_image(): void {
		if ( null !== self::$preload ) {
			printf( '<link rel="preload" as="image" href="%s" fetchpriority="high">' . "\n", esc_url( self::$preload ) );
		}
	}

	/**
	 * An image's folder and name, without a size suffix (-300x200, -scaled) or extension, lower case.
	 *
	 * @param string $url Image address.
	 */
	public static function image_stem( string $url ): ?string {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( ! preg_match( '~^(.*/)?([^/]+?)(?:-\d+x\d+)?(?:-scaled)?\.[a-z0-9]+$~i', $path, $m ) ) {
			return null;
		}
		return strtolower( $m[1] . $m[2] );
	}

	/**
	 * Whether an image (any of its sizes) is the report's image.
	 *
	 * @param string $src    The image's address.
	 * @param string $srcset Its other sizes.
	 */
	private static function is_report_image( string $src, string $srcset ): bool {
		if ( null === self::$report_image || '' === $src ) {
			return false;
		}
		foreach ( array_merge( array( $src ), preg_split( '/\s*,\s*/', $srcset ) ?: array() ) as $candidate ) {
			$url = strtok( trim( (string) $candidate ), ' ' );
			if ( is_string( $url ) && self::image_stem( $url ) === self::$report_image ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Width and height from the media library, for an image that has neither.
	 *
	 * @param \WP_HTML_Tag_Processor $tags At an IMG.
	 */
	private static function add_size( \WP_HTML_Tag_Processor $tags ): void {
		if ( null !== $tags->get_attribute( 'width' ) || null !== $tags->get_attribute( 'height' ) ) {
			return;
		}
		$src = $tags->get_attribute( 'src' );
		if ( ! is_string( $src ) || '' === $src || str_ends_with( strtolower( (string) wp_parse_url( $src, PHP_URL_PATH ) ), '.svg' ) ) {
			return;
		}
		$id    = 0;
		$class = $tags->get_attribute( 'class' );
		if ( is_string( $class ) && preg_match( '/\bwp-image-(\d+)\b/', $class, $m ) ) {
			$id = (int) $m[1];
		} elseif ( self::$lookups < self::MAX_LOOKUPS && str_contains( $src, self::uploads_url() ) ) {
			++self::$lookups;
			$id = attachment_url_to_postid( (string) preg_replace( '/-\d+x\d+(?=\.[a-z0-9]+$)/i', '', explode( '?', $src )[0] ) );
		}
		if ( $id <= 0 ) {
			return;
		}
		$meta = wp_get_attachment_metadata( $id );
		$dims = is_array( $meta ) ? wp_image_src_get_dimensions( $src, $meta, $id ) : false;
		if ( is_array( $dims ) && $dims[0] > 0 && $dims[1] > 0 ) {
			$tags->set_attribute( 'width', (string) $dims[0] );
			$tags->set_attribute( 'height', (string) $dims[1] );
		}
	}

	/**
	 * The uploads folder's address, once per page.
	 */
	private static function uploads_url(): string {
		static $url = null;
		$url      ??= (string) wp_get_upload_dir()['baseurl'];
		return $url;
	}

	// ----- Click-to-play videos ----------------------------------------------------------------

	/**
	 * The YouTube video ID and start time in an address, if it is one.
	 *
	 * @param string $url Address.
	 * @return array{id: string, start: int}|null
	 */
	public static function youtube( string $url ): ?array {
		if ( ! preg_match( '~^https?://(?:www\.|m\.)?(?:youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/)|youtu\.be/|youtube-nocookie\.com/embed/)([A-Za-z0-9_-]{11})~', $url, $m ) ) {
			return null;
		}
		$query = array();
		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$start = $query['start'] ?? $query['t'] ?? 0;
		return array(
			'id'    => $m[1],
			'start' => is_numeric( $start ) ? (int) $start : 0,
		);
	}

	/**
	 * Embedded YouTube videos as a preview picture with a play button.
	 *
	 * @param mixed  $html Embed markup.
	 * @param string $url  The embedded address.
	 * @return mixed
	 */
	public static function youtube_facade( $html, $url ) {
		$video = is_string( $html ) && is_string( $url ) ? self::youtube( $url ) : null;
		if ( null === $video || ! str_contains( $html, '<iframe' ) ) {
			return $html;
		}
		// The frame's own class, id and address, and the video's link as it was pasted in.
		$frame             = new \WP_HTML_Tag_Processor( $html );
		$attrs             = $frame->next_tag( array( 'tag_name' => 'iframe' ) ) ? Exclusions::attributes( $frame ) : array();
		$attrs['data-src'] = trim( ( $attrs['data-src'] ?? '' ) . ' ' . $url );
		if ( Exclusions::leaves_element( 'video_facades', 'IFRAME', $attrs ) ) {
			return $html;
		}
		$title = __( 'YouTube video', 'arvaseo-connector' );
		if ( preg_match( '/\btitle="([^"]{1,200})"/', $html, $m ) ) {
			$title = html_entity_decode( $m[1], ENT_QUOTES );
		}
		self::enqueue_facade();
		$embed = 'https://www.youtube-nocookie.com/embed/' . rawurlencode( $video['id'] ) . '?autoplay=1&rel=0' . ( $video['start'] > 0 ? '&start=' . $video['start'] : '' );
		return sprintf(
			'<div class="arvaseo-yt" data-arvaseo-embed="%1$s" data-arvaseo-title="%2$s"><img src="%3$s" alt="" width="480" height="360" loading="lazy" decoding="async" data-arvaseo-seen="1" /><button type="button" class="arvaseo-yt__play" aria-label="%4$s"><svg viewBox="0 0 68 48" width="68" height="48" aria-hidden="true" focusable="false"><path d="M66.5 7.7a8.5 8.5 0 0 0-6-6C55.2.3 34 .3 34 .3s-21.2 0-26.5 1.4a8.5 8.5 0 0 0-6 6C.1 13 .1 24 .1 24s0 11 1.4 16.3a8.5 8.5 0 0 0 6 6c5.3 1.4 26.5 1.4 26.5 1.4s21.2 0 26.5-1.4a8.5 8.5 0 0 0 6-6C67.9 35 67.9 24 67.9 24s0-11-1.4-16.3z" fill="#f00"/><path d="M45 24 27 14v20" fill="#fff"/></svg></button></div>',
			esc_url( $embed ),
			esc_attr( $title ),
			esc_url( 'https://i.ytimg.com/vi/' . rawurlencode( $video['id'] ) . '/hqdefault.jpg' ),
			/* translators: %s: the video's title. */
			esc_attr( sprintf( __( 'Play video: %s', 'arvaseo-connector' ), $title ) )
		);
	}

	/**
	 * The YouTube embed block.
	 *
	 * @param mixed                $html  Block output.
	 * @param array<string, mixed> $block The block.
	 * @return mixed
	 */
	public static function youtube_block( $html, $block ) {
		if ( ! is_array( $block ) || 'core/embed' !== ( $block['blockName'] ?? '' ) ) {
			return $html;
		}
		$url = $block['attrs']['url'] ?? '';
		if ( ! is_string( $html ) || ! is_string( $url ) || null === self::youtube( $url ) || str_contains( $html, 'arvaseo-yt' ) ) {
			return $html;
		}
		return preg_replace_callback(
			'~<iframe\b[^>]*></iframe>~is',
			static fn ( array $m ) => (string) self::youtube_facade( $m[0], $url ),
			$html,
			1
		);
	}

	/**
	 * The facade's small script and styles, once per page.
	 */
	private static function enqueue_facade(): void {
		if ( wp_script_is( 'arvaseo-speed-facade', 'enqueued' ) ) {
			return;
		}
		wp_enqueue_style( 'arvaseo-speed-facade', plugins_url( 'assets/speed-facade.css', ARVASEO_CONNECTOR_FILE ), array(), ARVASEO_CONNECTOR_VERSION );
		wp_enqueue_script(
			'arvaseo-speed-facade',
			plugins_url( 'assets/speed-facade.js', ARVASEO_CONNECTOR_FILE ),
			array(),
			ARVASEO_CONNECTOR_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	// ----- Faster Google Fonts -----------------------------------------------------------------

	/**
	 * Show text in a similar font while a Google font loads.
	 *
	 * @param mixed $src Stylesheet address.
	 * @return mixed
	 */
	public static function font_display( $src ) {
		if ( ! is_string( $src ) || ! str_contains( $src, 'fonts.googleapis.com/css' ) ) {
			return $src;
		}
		// Served from the site once its copy is ready; Google's address until then.
		$local = Fixes::stored()['fonts_local'] && LocalFonts::is_google_css( $src ) ? LocalFonts::local_url( $src ) : null;
		if ( null !== $local ) {
			return $local;
		}
		// Added as is: add_query_arg() would keep only the last of several family= parameters.
		return str_contains( $src, 'display=' ) ? $src : $src . ( str_contains( $src, '?' ) ? '&' : '?' ) . 'display=swap';
	}

	/**
	 * Connect to Google's font servers early, when the page uses Google Fonts.
	 *
	 * @param array<int, mixed> $urls Resource hints.
	 * @param string            $type Relation type.
	 * @return array<int, mixed>
	 */
	public static function font_preconnect( $urls, $type ) {
		if ( 'preconnect' !== $type || ! is_array( $urls ) || ! self::uses_google_fonts() ) {
			return $urls;
		}
		$urls[] = array(
			'href'        => 'https://fonts.gstatic.com',
			'crossorigin' => 'anonymous',
		);
		$urls[] = 'https://fonts.googleapis.com';
		return $urls;
	}

	/**
	 * Whether an enqueued stylesheet comes from Google Fonts.
	 */
	private static function uses_google_fonts(): bool {
		$styles = wp_styles();
		$local  = Fixes::stored()['fonts_local'];
		foreach ( $styles->queue as $handle ) {
			$style = $styles->registered[ $handle ] ?? null;
			if ( null !== $style && is_string( $style->src ) && str_contains( $style->src, 'fonts.googleapis.com' ) && ! ( $local && LocalFonts::has_copy( $style->src ) ) ) {
				return true;
			}
		}
		return false;
	}

	// ----- Defer scripts -----------------------------------------------------------------------

	/**
	 * Scripts in the head load with defer. WordPress itself keeps any script blocking that inline
	 * code or a blocking script depends on (script loading strategies, WordPress 6.3+).
	 */
	public static function defer_scripts(): void {
		$scripts = wp_scripts();
		$handles = array();
		$walk    = static function ( string $handle ) use ( &$walk, &$handles, $scripts ): void {
			if ( isset( $handles[ $handle ] ) || ! isset( $scripts->registered[ $handle ] ) ) {
				return;
			}
			$handles[ $handle ] = true;
			foreach ( $scripts->registered[ $handle ]->deps as $dep ) {
				$walk( (string) $dep );
			}
		};
		foreach ( $scripts->queue as $handle ) {
			$walk( (string) $handle );
		}
		foreach ( array_keys( $handles ) as $handle ) {
			$script = $scripts->registered[ $handle ];
			if ( ! $script->src || Exclusions::leaves_script( $handle, (string) $script->src ) ) {
				continue;
			}
			if ( 1 === (int) $scripts->get_data( $handle, 'group' ) || $scripts->get_data( $handle, 'strategy' ) ) {
				continue; // Already in the footer, or already deferred or async.
			}
			$scripts->add_data( $handle, 'strategy', 'defer' );
			self::$deferred[] = $handle;
		}
	}

	/**
	 * For an administrator trying Defer scripts: the scripts it deferred on this page, so they can
	 * leave one alone if something stopped working. Not-found pages are skipped: the browser asks
	 * for /favicon.ico after every page, and that 404 would replace the page they opened.
	 */
	public static function remember_deferred(): void {
		if ( 'trying' !== Fixes::state( 'defer_scripts' ) || ! current_user_can( \ArvaSEO\Connector\Install\Capabilities::MANAGE ) || is_404() ) {
			return;
		}
		$scripts = wp_scripts();
		$seen    = array();
		foreach ( array_slice( self::$deferred, 0, 50 ) as $handle ) {
			$script          = $scripts->registered[ $handle ] ?? null;
			$host            = null !== $script && is_string( $script->src ) ? wp_parse_url( $script->src, PHP_URL_HOST ) : null;
			$seen[ $handle ] = is_string( $host ) && '' !== $host ? $host : (string) wp_parse_url( home_url(), PHP_URL_HOST );
		}
		set_transient( 'arvaseo_connector_deferred', $seen, WEEK_IN_SECONDS );
	}

	// ----- Remove unused WordPress extras ------------------------------------------------------

	/**
	 * Switch off the extras the administrator chose.
	 */
	private static function remove_extras(): void {
		$extras = Fixes::stored()['extras'];
		if ( $extras['emoji'] ) {
			remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
			remove_action( 'wp_print_styles', 'print_emoji_styles' );
			remove_action( 'wp_enqueue_scripts', 'wp_enqueue_emoji_styles' );
			add_filter( 'emoji_svg_url', '__return_false' );
		}
		if ( $extras['oembed'] ) {
			remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
			remove_action( 'wp_head', 'wp_oembed_add_host_js' );
		}
		add_action(
			'wp_enqueue_scripts',
			static function () use ( $extras ): void {
				if ( $extras['oembed'] ) {
					wp_dequeue_script( 'wp-embed' );
				}
				if ( $extras['dashicons'] && ! is_user_logged_in() ) {
					wp_dequeue_style( 'dashicons' );
				}
				if ( $extras['jquery_migrate'] ) {
					$jquery = wp_scripts()->registered['jquery'] ?? null;
					if ( $jquery ) {
						$jquery->deps = array_values( array_diff( $jquery->deps, array( 'jquery-migrate' ) ) );
					}
				}
				if ( $extras['block_css'] && ! wp_is_block_theme() && is_singular() && ! has_blocks( get_queried_object_id() ) ) {
					foreach ( array( 'wp-block-library', 'wp-block-library-theme', 'classic-theme-styles', 'global-styles' ) as $handle ) {
						wp_dequeue_style( $handle );
					}
				}
			},
			PHP_INT_MAX - 1
		);
	}

	// ----- New uploads as WebP -----------------------------------------------------------------

	/**
	 * New JPEG and PNG uploads also get WebP sizes, which WordPress then shows.
	 *
	 * @param mixed $formats Output formats by source type.
	 * @return mixed
	 */
	public static function webp_output( $formats ) {
		if ( ! is_array( $formats ) || 'on' !== Fixes::state( 'webp_uploads' ) || ( defined( Fixes::KILL_SWITCH ) && constant( Fixes::KILL_SWITCH ) ) ) {
			return $formats;
		}
		return array_merge(
			$formats,
			array(
				'image/jpeg' => 'image/webp',
				'image/png'  => 'image/webp',
			)
		);
	}

	// ----- The mobile viewport tag -------------------------------------------------------------

	/**
	 * The viewport tag phones need.
	 */
	public static function viewport(): void {
		echo '<meta name="viewport" content="width=device-width, initial-scale=1" />' . "\n";
	}
}
