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

	/**
	 * The fixes, in the order the page lists them.
	 *
	 * @return array<string, array{title: string, what: string, group: string, risk: string, audits: array<string, string>, handled_by: string[], states: string[]}>
	 */
	public static function all(): array {
		$speed_plugins = array( 'wp-rocket', 'litespeed-cache', 'perfmatters', 'flying-press', 'nitropack' );
		return array(
			'lcp_image'        => array(
				'title'      => __( 'Show the main image first', 'arvaseo-connector' ),
				'what'       => __( 'On posts and pages, the featured image loads straight away with a high priority instead of waiting its turn, so the largest thing on the screen appears sooner.', 'arvaseo-connector' ),
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
			'defer_scripts'    => array(
				'title'      => __( 'Defer scripts', 'arvaseo-connector' ),
				'what'       => __( 'Scripts in the page’s head load in the background and run once the page has been read, instead of holding it up. jQuery, consent tools and any scripts you list are left alone.', 'arvaseo-connector' ),
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
			'webp_uploads'     => array(
				'title'      => __( 'New uploads as WebP', 'arvaseo-connector' ),
				'what'       => __( 'Images you upload from now on also get smaller WebP copies, which WordPress shows instead. Your existing images and the originals stay as they are.', 'arvaseo-connector' ),
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
		);
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
			'wp-rocket'                  => 'WP Rocket',
			'litespeed-cache'            => 'LiteSpeed Cache',
			'perfmatters'                => 'Perfmatters',
			'flying-press'               => 'FlyingPress',
			'nitropack'                  => 'NitroPack',
			'a3-lazy-load'               => 'a3 Lazy Load',
			'rocket-lazy-load'           => 'LazyLoad by WP Rocket',
			'sg-cachepress'              => 'Speed Optimizer (SiteGround)',
			'w3-total-cache'             => 'W3 Total Cache',
			'autoptimize'                => 'Autoptimize',
			'wp-smushit'                 => 'Smush',
			'jetpack-boost'              => 'Jetpack Boost',
			'wp-youtube-lyte'            => 'WP YouTube Lyte',
			'host-webfonts-local'        => 'OMGF',
			'webp-uploads'               => 'Modern Image Formats',
			'ewww-image-optimizer'       => 'EWWW Image Optimizer',
			'imagify'                    => 'Imagify',
			'shortpixel-image-optimiser' => 'ShortPixel',
			'optimole-wp'                => 'Optimole',
			'webp-express'               => 'WebP Express',
			'webp-converter-for-media'   => 'Converter for Media',
		);
	}

	/**
	 * What's stored, over the defaults.
	 *
	 * @return array{fixes: array<string, array{state: string, since: string|null}>, extras: array<string, bool>, defer_exclude: string[], defer_checked_at: string|null, defer_on_at: int|null}
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
			'fixes'            => $fixes,
			'extras'           => $extras,
			'defer_exclude'    => array_values( array_filter( (array) ( $saved['defer_exclude'] ?? array() ), 'is_string' ) ),
			'defer_checked_at' => is_string( $saved['defer_checked_at'] ?? null ) ? $saved['defer_checked_at'] : null,
			'defer_on_at'      => is_int( $saved['defer_on_at'] ?? null ) ? $saved['defer_on_at'] : null,
		);
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
		$state = self::state( $id );
		return 'on' === $state || ( 'trying' === $state && current_user_can( \ArvaSEO\Connector\Install\Capabilities::MANAGE ) );
	}

	/**
	 * Change a fix's state. Defer scripts goes on only after it's been tried and the administrator
	 * confirmed they checked their important pages.
	 *
	 * @param string $id    Fix ID.
	 * @param string $state off|trying|on.
	 * @param bool   $checked Defer scripts: the checklist was ticked.
	 * @return string|null Why it didn't change (invalid, handled, unavailable, try_first,
	 *                     check_pages), or null when it did.
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
		$from                 = $data['fixes'][ $id ]['state'];
		$data['fixes'][ $id ] = array(
			'state' => $state,
			'since' => $from === $state ? $data['fixes'][ $id ]['since'] : Ids::iso(),
		);
		self::save( $data );
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
	 * Save the extras to remove and the scripts Defer scripts leaves alone.
	 *
	 * @param array<string, mixed> $extras  Ticked extras (key => anything truthy).
	 * @param string               $exclude Script handles, one per line or comma separated.
	 */
	public static function save_options( array $extras, string $exclude ): void {
		$data = self::stored();
		foreach ( array_keys( self::EXTRAS ) as $key ) {
			$data['extras'][ $key ] = ! empty( $extras[ $key ] );
		}
		$handles = array();
		foreach ( preg_split( '/[\s,]+/', $exclude ) as $handle ) {
			$handle = sanitize_key( $handle );
			if ( '' !== $handle ) {
				$handles[] = $handle;
			}
		}
		$data['defer_exclude'] = array_slice( array_values( array_unique( $handles ) ), 0, 100 );
		self::save( $data );
		AuditLogger::info( 'speed.options_saved', __( 'PageSpeed fix choices saved.', 'arvaseo-connector' ) );
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
