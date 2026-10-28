<?php
/**
 * Other caches on the site (2.11.0): the page caches and CDNs that may keep copies of the pages,
 * how Arva SEO clears the ones it can reach when what visitors see changes, and the ones it can't.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Speed;

use ArvaSEO\Connector\Auth\TokenStore;
use ArvaSEO\Connector\Cron\CronManager;
use ArvaSEO\Connector\Logging\AuditLogger;
use ArvaSEO\Connector\Support\Ids;

defined( 'ABSPATH' ) || exit;

/**
 * Where caches are found:
 * - active plugins, and the documented function or action each one clears itself with;
 * - constants and paths of hosts (WP Engine, Kinsta, Pantheon, SpinupWP, Cloudways, SiteGround,
 *   Hostinger);
 * - once a day, the cache headers of the site's own homepage, asked for anonymously from the
 *   server (a fixed list of headers, and the comments cache plugins leave in the page). Only the
 *   names of what was found are kept. A header that isn't there means "unknown", not "no cache":
 *   the request may be answered by the origin, not the CDN in front of it.
 *
 * Clearing: every cache found that has a documented way to clear everything is cleared when
 * what visitors see changes, at most every 15 minutes (a change that comes sooner waits for one
 * more clear at the end), and only for changes Arva SEO makes: a fix, a choice, an exclusion, new
 * optimized CSS, new font copies, compressed images. The caches that cache plugins and hosts run
 * for themselves when a theme, menu or widget changes aren't cleared again. Clear every cache now
 * and Regenerate clear straight away. The ones with no way in (a host's CDN, Kinsta's cache) are
 * listed with how to clear them, and a person says "I've cleared it".
 */
final class OtherCaches {

	/** What was found, and when (not autoloaded). */
	public const OPTION = 'arvaseo_connector_other_caches';

	/** The daily look at the homepage's headers. */
	public const HOOK_CHECK = 'arvaseo_connector_other_caches_check';

	/** The clear that changes made inside the gap wait for (this was PageCache::HOOK_PURGE). */
	public const HOOK_CLEAR = 'arvaseo_connector_host_cache_purge';

	/** When the other caches were last cleared (not autoloaded; this was PageCache::PURGED). */
	public const LAST_CLEAR = 'arvaseo_connector_host_cache_purged';

	/** At most one clear in this time (the owner's choice, 2026-10-27). */
	public const GAP = 15 * MINUTE_IN_SECONDS;

	/** Our own action, that the official Cloudflare plugin clears everything on. */
	public const ACTION_CLOUDFLARE = 'arvaseo_connector_purge_cloudflare_plugin';

	/** Our own action, that Proxy Cache Purge clears everything on. */
	public const ACTION_VARNISH = 'arvaseo_connector_purge_proxy_cache';

	/** The signed address that sends LiteSpeed's purge header when no web response can. */
	public const LITESPEED_QUERY = 'arvaseo_litespeed_purge';

	/** At most this many caches are shown and reported. */
	public const MAX_REPORTED = 15;

	/** The header check runs again after this long. */
	private const CHECK_EVERY = 20 * HOUR_IN_SECONDS;

	/** A page's lifetime in a cache counts for at most this long. */
	private const MAX_LIFETIME = 30 * DAY_IN_SECONDS;

	/** The only response headers read, and the only ones kept. */
	private const HEADERS = array( 'cf-cache-status', 'cf-apo-via', 'x-litespeed-cache', 'x-qc-cache', 'x-hcdn-cache-status', 'platform', 'x-proxy-cache', 'x-kinsta-cache', 'x-varnish', 'via', 'x-cache', 'age', 'x-fastcgi-cache', 'x-cache-status', 'x-ac', 'cache-control' );

	/** Cloudflare keeps the page in these states (DYNAMIC and BYPASS mean it doesn't). */
	private const CLOUDFLARE_KEEPS = array( 'HIT', 'MISS', 'EXPIRED', 'STALE', 'REVALIDATED', 'UPDATING' );

	/**
	 * Changes that clear the other caches: the ones only Arva SEO knows about. A theme, a menu or a
	 * widget changing is left to the cache plugins and hosts, which watch for those themselves.
	 */
	private const REASONS = array( 'fix', 'choices', 'exclusions', 'regenerate', 'webp', 'upgrade', 'css', 'fonts', 'compress', 'external_assets', 'cleared', 'mark' );

	/** Someone asked for these, or files are about to be deleted: no waiting for the gap. */
	private const AT_ONCE = array( 'cleared', 'regenerate', 'mark' );

	/** Cache plugins with a documented way to clear everything: slug => id, name, kind of API, API. */
	private const PLUGINS = array(
		'wp-super-cache'          => array( 'wp-super-cache', 'WP Super Cache', 'function', 'wp_cache_clear_cache' ),
		'w3-total-cache'          => array( 'w3-total-cache', 'W3 Total Cache', 'function', 'w3tc_pgcache_flush' ),
		'wp-rocket'               => array( 'wp-rocket', 'WP Rocket', 'function', 'rocket_clean_domain' ),
		'wp-fastest-cache'        => array( 'wp-fastest-cache', 'WP Fastest Cache', 'function', 'wpfc_clear_all_cache' ),
		'cache-enabler'           => array( 'cache-enabler', 'Cache Enabler', 'action', 'cache_enabler_clear_complete_cache' ),
		'hummingbird-performance' => array( 'hummingbird', 'Hummingbird', 'action', 'wphb_clear_page_cache' ),
		'comet-cache'             => array( 'comet-cache', 'Comet Cache', 'method', 'comet_cache' ),
		'nginx-helper'            => array( 'nginx-cache', 'Nginx Helper', 'action', 'rt_nginx_helper_purge_all' ),
		'breeze'                  => array( 'breeze', 'Breeze', 'action', 'breeze_clear_all_cache' ),
		'wp-optimize'             => array( 'wp-optimize', 'WP-Optimize', 'custom', '' ),
		'nitropack'               => array( 'nitropack', 'NitroPack', 'function', 'nitropack_sdk_purge' ),
	);

	/** Hosts' plugins: slug => the cache's id. */
	private const PLUGIN_HOSTS = array(
		'sg-cachepress'   => 'siteground',
		'litespeed-cache' => 'litespeed',
	);

	/** Cache plugins Arva SEO can't clear until each one's trigger is verified: slug => name. */
	private const BY_HAND_PLUGINS = array(
		'wp-cloudflare-page-cache' => 'Super Page Cache',
		'powered-cache'            => 'Powered Cache',
		'jetpack-boost'            => 'Jetpack Boost',
		'flying-press'             => 'FlyingPress',
		'swift-performance-lite'   => 'Swift Performance',
		'borlabs-cache'            => 'Borlabs Cache',
	);

	/** Comments cache plugins leave in a page: id => text (case doesn't matter). */
	private const COMMENTS = array(
		'wp-rocket'        => 'performance optimized by wp rocket',
		'w3-total-cache'   => 'performance optimized by w3 total cache',
		'wp-super-cache'   => 'cached page generated by wp-super-cache',
		'wp-fastest-cache' => 'wp fastest cache file was created',
		'cache-enabler'    => 'cache enabler by keycdn',
		'litespeed'        => 'page generated by litespeed cache',
		'breeze'           => 'cache served by breeze cache',
		'wp-optimize'      => 'cached by wp-optimize',
		'hummingbird'      => 'hummingbird cache file was created',
	);

	/**
	 * Plugins that attach their clear-everything action only while their page caching is on
	 * (Hummingbird's module runs only then): without it, and without a sign in the homepage that
	 * its pages are being sent, its page cache is off.
	 */
	private const HOOK_ONLY_WHEN_ON = array( 'hummingbird' );

	/** The folders in uploads that hold generated files, as pages link to them. */
	private const GENERATED = '~/(?:arvaseo-minified|arvaseo-combined|arvaseo-fonts|arvaseo-css/used)/([A-Za-z0-9._-]+)~';

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( 'arvaseo_connector_pages_changed', array( self::class, 'pages_changed' ) );
		add_action( self::HOOK_CLEAR, array( self::class, 'clear_later' ) );
		add_action( self::HOOK_CHECK, array( self::class, 'check' ) );
		// Before WordPress resolves the request: the signed address LiteSpeed's purge header is sent from.
		add_action( 'init', array( self::class, 'serve_litespeed_purge' ), 0 );
		foreach ( array( 'activated_plugin', 'deactivated_plugin' ) as $hook ) {
			add_action( $hook, array( self::class, 'plugins_changed' ) );
		}
	}

	/**
	 * Tell the official Cloudflare plugin and Proxy Cache Purge to clear everything on our own
	 * action. Added when the plugin file loads (arvaseo-connector.php), before either of them reads
	 * the list. Other plugins' hooks, so they can't carry our prefix.
	 */
	public static function register_filters(): void {
		add_filter( 'cloudflare_purge_everything_actions', array( self::class, 'cloudflare_actions' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the Cloudflare plugin's documented filter.
		// Proxy Cache Purge registers the events of the first list and treats those on the second as full purges.
		add_filter( 'varnish_http_purge_events', array( self::class, 'proxy_cache_events' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Proxy Cache Purge's documented filter.
		add_filter( 'varnish_http_purge_events_full', array( self::class, 'proxy_cache_events' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Proxy Cache Purge's documented filter.
	}

	/**
	 * The official Cloudflare plugin's list of actions that purge everything.
	 *
	 * @param mixed $actions Action names.
	 * @return string[]
	 */
	public static function cloudflare_actions( $actions ): array {
		$actions   = is_array( $actions ) ? $actions : array();
		$actions[] = self::ACTION_CLOUDFLARE;
		return array_values( array_unique( array_map( 'strval', $actions ) ) );
	}

	/**
	 * Proxy Cache Purge's list of actions that purge everything.
	 *
	 * @param mixed $events Action names.
	 * @return string[]
	 */
	public static function proxy_cache_events( $events ): array {
		$events   = is_array( $events ) ? $events : array();
		$events[] = self::ACTION_VARNISH;
		return array_values( array_unique( array_map( 'strval', $events ) ) );
	}

	// ----- What was stored ---------------------------------------------------------------------

	/**
	 * What's stored, over the defaults.
	 *
	 * @return array{header: array{at: int|null, tried: int, code: int, seen: array<string, string>, ids: string[], comments: string[], lifetimes: array<int, array{0: int, 1: int}>, linked: string[]}, handled: array<string, int>, cleared_at: array<string, int>, changed_at: int, cleared: array{at: int, done: string[], by_hand: string[]}|null, reported: string}
	 */
	public static function state(): array {
		$saved   = get_option( self::OPTION, array() );
		$saved   = is_array( $saved ) ? $saved : array();
		$header  = is_array( $saved['header'] ?? null ) ? $saved['header'] : array();
		$cleared = is_array( $saved['cleared'] ?? null ) ? $saved['cleared'] : null;
		$strings = static fn ( $items ): array => array_values( array_filter( is_array( $items ) ? $items : array(), 'is_string' ) );
		$times   = static function ( $map ): array {
			$out = array();
			foreach ( is_array( $map ) ? $map : array() as $id => $at ) {
				if ( is_string( $id ) && is_int( $at ) ) {
					$out[ $id ] = $at;
				}
			}
			return $out;
		};
		$seen    = array();
		foreach ( is_array( $header['seen'] ?? null ) ? $header['seen'] : array() as $name => $value ) {
			if ( is_string( $name ) && is_string( $value ) && in_array( $name, self::HEADERS, true ) ) {
				$seen[ $name ] = $value;
			}
		}
		$lifetimes = array();
		foreach ( is_array( $header['lifetimes'] ?? null ) ? $header['lifetimes'] : array() as $pair ) {
			if ( is_array( $pair ) && is_int( $pair[0] ?? null ) && is_int( $pair[1] ?? null ) ) {
				$lifetimes[] = array( $pair[0], $pair[1] );
			}
		}
		return array(
			'header'     => array(
				'at'        => is_int( $header['at'] ?? null ) ? $header['at'] : null,
				'tried'     => is_int( $header['tried'] ?? null ) ? $header['tried'] : 0,
				'code'      => is_int( $header['code'] ?? null ) ? $header['code'] : 0,
				'seen'      => $seen,
				'ids'       => $strings( $header['ids'] ?? null ),
				'comments'  => $strings( $header['comments'] ?? null ),
				'lifetimes' => array_slice( $lifetimes, -7 ),
				'linked'    => array_slice( $strings( $header['linked'] ?? null ), 0, 200 ),
			),
			'handled'    => $times( $saved['handled'] ?? null ),
			'cleared_at' => $times( $saved['cleared_at'] ?? null ),
			'changed_at' => is_int( $saved['changed_at'] ?? null ) ? $saved['changed_at'] : 0,
			'cleared'    => null === $cleared ? null : array(
				'at'      => (int) ( $cleared['at'] ?? 0 ),
				'done'    => $strings( $cleared['done'] ?? null ),
				'by_hand' => $strings( $cleared['by_hand'] ?? null ),
			),
			'reported'   => is_string( $saved['reported'] ?? null ) ? $saved['reported'] : '',
		);
	}

	/**
	 * Change some of what's stored.
	 *
	 * @param array<string, mixed> $changes Keys of state().
	 */
	private static function save( array $changes ): void {
		update_option( self::OPTION, array_merge( self::state(), $changes ), false );
	}

	// ----- Finding caches ----------------------------------------------------------------------

	/**
	 * The caches found, the ones that need a person first.
	 *
	 * @return list<array{id: string, name: string, kind: string, clearing: string, detail: string|null, where: string|null, seen_by: string[], blocks: bool}>
	 */
	public static function found(): array {
		return array_values( self::build() );
	}

	/**
	 * Whether any cache was found.
	 */
	public static function any(): bool {
		return array() !== self::found();
	}

	/**
	 * The caches Arva SEO can't clear that may still keep saved pages: while one is there, generated
	 * files are kept much longer (AssetCache).
	 *
	 * @return list<array{id: string, name: string, kind: string, clearing: string, detail: string|null, where: string|null, seen_by: string[], blocks: bool}>
	 */
	public static function blockers(): array {
		return array_values( array_filter( self::found(), static fn ( array $entry ): bool => $entry['blocks'] ) );
	}

	/**
	 * The caches an administrator has to clear since something changed.
	 *
	 * @return list<array{id: string, name: string, kind: string, clearing: string, detail: string|null, where: string|null, seen_by: string[], blocks: bool}>
	 */
	public static function waiting(): array {
		$state = self::state();
		return array_values(
			array_filter(
				self::found(),
				static fn ( array $entry ): bool => 'by_hand' === $entry['clearing'] && $state['changed_at'] > ( $state['handled'][ $entry['id'] ] ?? 0 )
			)
		);
	}

	/**
	 * When an administrator said they cleared a cache, or null.
	 *
	 * @param string $id Cache ID.
	 */
	public static function handled_at( string $id ): ?int {
		return self::state()['handled'][ $id ] ?? null;
	}

	/**
	 * When the homepage's headers were last looked at (ISO, UTC), or null.
	 */
	public static function checked_at(): ?string {
		$at = self::state()['header']['at'];
		return null === $at ? null : Ids::iso( $at );
	}

	/**
	 * The longest time a cache in front of the site may keep a page, as the homepage's headers say
	 * (last week's longest), in seconds; 0 when nothing says.
	 */
	public static function longest_lifetime(): int {
		$longest = 0;
		foreach ( self::state()['header']['lifetimes'] as $pair ) {
			if ( $pair[0] > time() - WEEK_IN_SECONDS ) {
				$longest = max( $longest, $pair[1] );
			}
		}
		return $longest;
	}

	/**
	 * Build the list from plugins, constants, paths and the header check.
	 *
	 * @return array<string, array{id: string, name: string, kind: string, clearing: string, detail: string|null, where: string|null, seen_by: string[], blocks: bool}>
	 */
	private static function build(): array {
		$active  = Fixes::active_plugin_slugs();
		$state   = self::state();
		$header  = $state['header'];
		$ids     = $header['ids'];
		$checked = null !== $header['at'];
		$entries = array();
		$rank    = array(
			'none'    => 0,
			'by_hand' => 1,
			'auto'    => 2,
		);
		$put     = static function ( array $entry ) use ( &$entries, $rank ): void {
			$id = $entry['id'];
			if ( ! isset( $entries[ $id ] ) ) {
				$entries[ $id ] = $entry;
				return;
			}
			$old             = $entries[ $id ];
			$keep            = $rank[ $entry['clearing'] ] > $rank[ $old['clearing'] ] ? $entry : $old;
			$keep['seen_by'] = array_values( array_unique( array_merge( $old['seen_by'], $entry['seen_by'] ) ) );
			$entries[ $id ]  = $keep;
		};

		// Cache plugins with a documented way to clear everything.
		foreach ( self::PLUGINS as $slug => list( $id, $name ) ) {
			$is_active = in_array( $slug, $active, true );
			$available = self::available( $id );
			if ( ! $is_active && ! $available ) {
				continue;
			}
			if ( ! $available && $checked && in_array( $id, self::HOOK_ONLY_WHEN_ON, true ) && ! in_array( $id, $header['comments'], true ) ) {
				$put( self::entry( $id, $name, 'plugin', 'none', array( 'plugin' ), __( 'Its page caching is off.', 'arvaseo-connector' ) ) );
				continue;
			}
			$put(
				$available
					? self::entry( $id, $name, 'plugin', 'auto', array( 'plugin' ) )
					: self::entry( $id, $name, 'plugin', 'by_hand', array( 'plugin' ), self::plugin_how( $name, 'wp-fastest-cache' === $id && self::constant_on( 'WPFC_DISABLE_HOOK_CLEAR_ALL_CACHE' ) ), $name )
			);
		}
		// The rest, until each one's trigger is verified.
		foreach ( self::BY_HAND_PLUGINS as $slug => $name ) {
			if ( in_array( $slug, $active, true ) ) {
				$put( self::entry( $slug, $name, 'plugin', 'by_hand', array( 'plugin' ), self::plugin_how( $name ), $name ) );
			}
		}

		// Hosts.
		$path = self::path();
		if ( in_array( 'sg-cachepress', $active, true ) || function_exists( 'sg_cachepress_purge_cache' ) || in_array( 'siteground', $ids, true ) || str_starts_with( $path, '/home/customer/www/' ) || (bool) apply_filters( 'arvaseo_connector_siteground', false ) ) {
			$seen_by = array_values( array_filter( array( in_array( 'sg-cachepress', $active, true ) || function_exists( 'sg_cachepress_purge_cache' ) ? 'plugin' : null, in_array( 'siteground', $ids, true ) ? 'header' : null, str_starts_with( $path, '/home/customer/www/' ) ? 'path' : null ) ) );
			$put(
				self::available( 'siteground' )
					? self::entry( 'siteground', __( 'SiteGround’s dynamic cache', 'arvaseo-connector' ), 'host', 'auto', $seen_by )
					: self::entry( 'siteground', __( 'SiteGround’s dynamic cache', 'arvaseo-connector' ), 'host', 'by_hand', array() === $seen_by ? array( 'path' ) : $seen_by, __( 'Install SiteGround’s Speed Optimizer plugin (you can turn its caching options off): Arva SEO then clears it whenever your pages change. Until then, clear it in Site Tools → Speed → Caching.', 'arvaseo-connector' ), __( 'Site Tools → Speed → Caching', 'arvaseo-connector' ) )
			);
		}
		$litespeed_plugin = in_array( 'litespeed-cache', $active, true ) || (bool) has_action( 'litespeed_purge_all' );
		if ( $litespeed_plugin || in_array( 'litespeed', $ids, true ) || in_array( 'litespeed', $header['comments'], true ) ) {
			$seen_by = array_values( array_filter( array( $litespeed_plugin ? 'plugin' : null, in_array( 'litespeed', $ids, true ) ? 'header' : null, in_array( 'litespeed', $header['comments'], true ) ? 'comment' : null ) ) );
			$put(
				self::available( 'litespeed' )
					? self::entry( 'litespeed', $litespeed_plugin ? 'LiteSpeed Cache' : __( 'LiteSpeed’s server cache', 'arvaseo-connector' ), 'host', 'auto', $seen_by )
					: self::entry( 'litespeed', 'LiteSpeed Cache', 'host', 'by_hand', $seen_by, self::plugin_how( 'LiteSpeed Cache' ), 'LiteSpeed Cache' )
			);
		}
		if ( class_exists( 'WpeCommon', false ) || function_exists( 'is_wpe' ) || self::constant( 'WPE_APIKEY' ) ) {
			$put(
				self::available( 'wpengine' )
					? self::entry( 'wpengine', __( 'WP Engine’s page cache', 'arvaseo-connector' ), 'host', 'auto', array( 'constant' ) )
					: self::entry( 'wpengine', __( 'WP Engine’s page cache', 'arvaseo-connector' ), 'host', 'by_hand', array( 'constant' ), __( 'In WP Engine’s User Portal, open your environment, then Caching, and choose Clear all caches.', 'arvaseo-connector' ), __( 'WP Engine → Caching', 'arvaseo-connector' ) )
			);
		}
		if ( self::constant( 'KINSTAMU_VERSION' ) || in_array( 'kinsta', $ids, true ) ) {
			// Kinsta has no documented way to clear its cache from a plugin: by hand (D19).
			$put( self::entry( 'kinsta', __( 'Kinsta’s page cache', 'arvaseo-connector' ), 'host', 'by_hand', array_values( array_filter( array( self::constant( 'KINSTAMU_VERSION' ) ? 'constant' : null, in_array( 'kinsta', $ids, true ) ? 'header' : null ) ) ), __( 'In MyKinsta, open your site, then Tools, and choose Clear cache. Or run “wp kinsta cache purge”.', 'arvaseo-connector' ), __( 'MyKinsta → Tools → Clear cache', 'arvaseo-connector' ) ) );
		}
		if ( self::constant( 'PANTHEON_ENVIRONMENT' ) ) {
			$put( self::entry( 'pantheon', __( 'Pantheon’s edge cache', 'arvaseo-connector' ), 'host', 'by_hand', array( 'constant' ), __( 'In your Pantheon dashboard, open your site and choose Clear caches.', 'arvaseo-connector' ), __( 'Pantheon → Clear caches', 'arvaseo-connector' ) ) );
		}
		if ( self::constant( 'SPINUPWP_CACHE_PATH' ) ) {
			$put( self::entry( 'spinupwp', __( 'SpinupWP’s page cache', 'arvaseo-connector' ), 'host', 'by_hand', array( 'constant' ), __( 'In SpinupWP, open your site, then Cache, and choose Purge Page Cache.', 'arvaseo-connector' ), __( 'SpinupWP → Cache', 'arvaseo-connector' ) ) );
		}
		if ( ( isset( $_SERVER['cw_allowed_ip'] ) || self::constant( 'cw_allowed_ip' ) ) && ! self::available( 'breeze' ) ) {
			$put( self::entry( 'cloudways', __( 'Cloudways’ Varnish cache', 'arvaseo-connector' ), 'host', 'by_hand', array( 'constant' ), __( 'In Cloudways, open your application and purge Varnish from its Varnish settings. The Breeze plugin can do it for you.', 'arvaseo-connector' ), __( 'Cloudways → Varnish', 'arvaseo-connector' ) ) );
		}
		if ( in_array( 'hostinger', $active, true ) || in_array( 'hostinger', $ids, true ) || 1 === preg_match( '~^/home/u\d+/domains/~', $path ) ) {
			// The host alone is no cache: only what its headers show is. On ariesvincent.com the
			// headers say Hostinger and a LiteSpeed server, with no page cache and no CDN.
			$put(
				self::entry(
					'hostinger',
					'Hostinger',
					'host',
					'none',
					array_values( array_filter( array( in_array( 'hostinger', $active, true ) ? 'plugin' : null, in_array( 'hostinger', $ids, true ) ? 'header' : null, 1 === preg_match( '~^/home/u\d+/domains/~', $path ) ? 'path' : null ) ) ),
					$checked ? __( 'No server page cache seen.', 'arvaseo-connector' ) : __( 'Arva SEO hasn’t looked at its cache headers yet.', 'arvaseo-connector' )
				)
			);
		}
		if ( in_array( 'hostinger-cdn', $ids, true ) ) {
			$put( self::entry( 'hostinger-cdn', __( 'Hostinger CDN', 'arvaseo-connector' ), 'cdn', 'by_hand', array( 'header' ), __( 'In hPanel, open Performance, then CDN, and choose Flush cache.', 'arvaseo-connector' ), __( 'hPanel → Performance → CDN', 'arvaseo-connector' ) ) );
		}

		// Cloudflare.
		$put_cloudflare = self::cloudflare_entry( $active, $header );
		if ( null !== $put_cloudflare ) {
			$put( $put_cloudflare );
		}

		// Varnish, nginx and other caches in front of the site.
		if ( in_array( 'varnish-http-purge', $active, true ) || in_array( 'varnish', $ids, true ) || self::available( 'varnish' ) ) {
			$seen_by = array_values( array_filter( array( in_array( 'varnish-http-purge', $active, true ) ? 'plugin' : null, in_array( 'varnish', $ids, true ) ? 'header' : null ) ) );
			$put(
				self::available( 'varnish' )
					? self::entry( 'varnish', __( 'Varnish (Proxy Cache Purge)', 'arvaseo-connector' ), 'server', 'auto', array() === $seen_by ? array( 'plugin' ) : $seen_by )
					: self::entry( 'varnish', __( 'A Varnish cache', 'arvaseo-connector' ), 'server', 'by_hand', array() === $seen_by ? array( 'header' ) : $seen_by, __( 'Ask your host to clear its Varnish cache, or install the Proxy Cache Purge plugin if your host supports it.', 'arvaseo-connector' ), __( 'Your host’s Varnish', 'arvaseo-connector' ) )
			);
		}
		if ( in_array( 'nginx-cache', $ids, true ) ) {
			$put( self::entry( 'nginx-cache', __( 'Your server’s nginx cache', 'arvaseo-connector' ), 'server', 'by_hand', array( 'header' ), __( 'Ask your host to clear its nginx cache, or set up the Nginx Helper plugin for it.', 'arvaseo-connector' ), __( 'Your host’s nginx cache', 'arvaseo-connector' ) ) );
		}
		if ( in_array( 'wpcom-edge', $ids, true ) ) {
			$put( self::entry( 'wpcom-edge', __( 'Your host’s edge cache', 'arvaseo-connector' ), 'host', 'by_hand', array( 'header' ), __( 'Clear it from your host’s dashboard (look for Caching), or ask your host.', 'arvaseo-connector' ), __( 'Your host’s dashboard → Caching', 'arvaseo-connector' ) ) );
		}
		if ( in_array( 'cloudfront', $ids, true ) ) {
			$put( self::entry( 'cloudfront', 'Amazon CloudFront', 'cdn', 'by_hand', array( 'header' ), __( 'In Amazon CloudFront, create an invalidation for /* on your distribution.', 'arvaseo-connector' ), __( 'CloudFront → Invalidations', 'arvaseo-connector' ) ) );
		}
		if ( in_array( 'proxy-cache', $ids, true ) ) {
			$put( self::entry( 'proxy-cache', __( 'A cache in front of your site', 'arvaseo-connector' ), 'server', 'by_hand', array( 'header' ), __( 'Ask your host or CDN to clear its cache for this site.', 'arvaseo-connector' ), __( 'Your host or CDN', 'arvaseo-connector' ) ) );
		}

		// Another plugin's advanced-cache.php.
		if ( ( ( defined( 'WP_CACHE' ) && WP_CACHE ) || (bool) apply_filters( 'arvaseo_connector_host_constant', false, 'WP_CACHE' ) ) && file_exists( PageCache::dropin() ) && ! PageCache::dropin_is_ours() && array() === array_intersect( array_merge( array_keys( self::PLUGINS ), array_keys( self::BY_HAND_PLUGINS ), PageCache::HOST_PLUGINS ), $active ) ) {
			$put( self::entry( 'advanced-cache', __( 'Another page cache (wp-content/advanced-cache.php)', 'arvaseo-connector' ), 'plugin', 'by_hand', array( 'path' ), __( 'Open the plugin that made wp-content/advanced-cache.php and clear its cache, or ask your host.', 'arvaseo-connector' ), 'advanced-cache.php' ) );
		}

		// Saved pages of a cache plugin that isn't active any more, still being sent.
		$names = array();
		foreach ( self::PLUGINS as $row ) {
			$names[ $row[0] ] = $row[1];
		}
		foreach ( $header['comments'] as $id ) {
			if ( isset( $names[ $id ] ) && ! isset( $entries[ $id ] ) ) {
				$put( self::entry( $id, $names[ $id ], 'plugin', 'by_hand', array( 'comment' ), __( 'Its saved pages are still being sent although the plugin isn’t active. Delete its cache folder in wp-content (usually “cache”), or ask your host.', 'arvaseo-connector' ), __( 'wp-content/cache', 'arvaseo-connector' ) ) );
			}
		}

		// People first, then what is cleared for them, then what was only noted.
		$order = array(
			'by_hand' => 0,
			'auto'    => 1,
			'none'    => 2,
		);
		uasort(
			$entries,
			static fn ( array $a, array $b ): int => array( $order[ $a['clearing'] ], $a['name'] ) <=> array( $order[ $b['clearing'] ], $b['name'] )
		);
		return array_slice( $entries, 0, self::MAX_REPORTED, true );
	}

	/**
	 * Cloudflare's entry: connected with a token, the official plugin, or in front of the site.
	 *
	 * @param string[]             $active Active plugin folders.
	 * @param array<string, mixed> $header The header check.
	 * @return array<string, mixed>|null
	 */
	private static function cloudflare_entry( array $active, array $header ): ?array {
		$connected = Cloudflare::connected();
		$plugin    = in_array( 'cloudflare', $active, true );
		$caching   = in_array( 'cloudflare', $header['ids'], true );
		$status    = strtoupper( (string) ( $header['seen']['cf-cache-status'] ?? '' ) );
		$behind    = Cloudflare::seen();
		$seen_by   = array_values( array_filter( array( $connected ? 'connection' : null, $plugin ? 'plugin' : null, $caching || '' !== $status ? 'header' : null, $behind ? 'path' : null ) ) );
		if ( $connected || ( $plugin && has_action( self::ACTION_CLOUDFLARE ) ) ) {
			return self::entry( 'cloudflare', 'Cloudflare', 'cdn', 'auto', $seen_by );
		}
		$how   = __( 'In Cloudflare, open Caching, then Configuration, and choose Purge Everything. Or connect Cloudflare on this page and Arva SEO does it for you.', 'arvaseo-connector' );
		$where = __( 'Cloudflare → Caching → Purge Everything', 'arvaseo-connector' );
		if ( $caching ) {
			return self::entry( 'cloudflare', 'Cloudflare', 'cdn', 'by_hand', $seen_by, $how, $where );
		}
		if ( '' !== $status ) {
			// DYNAMIC or BYPASS: it passes pages through and keeps none, so it blocks nothing.
			return self::entry( 'cloudflare', 'Cloudflare', 'cdn', 'none', $seen_by, __( 'In front of your site, but not keeping your pages.', 'arvaseo-connector' ) );
		}
		if ( $behind || $plugin ) {
			// In front of the site, and nothing says whether it keeps pages: the headers asked for from this server may not have passed through it.
			return self::entry( 'cloudflare', 'Cloudflare', 'cdn', 'by_hand', $seen_by, $how, $where );
		}
		return null;
	}

	/**
	 * Whether a host's constant is there (WPE_APIKEY, KINSTAMU_VERSION, PANTHEON_ENVIRONMENT,
	 * SPINUPWP_CACHE_PATH). A setup that can't define it can say so with the filter.
	 *
	 * @param string $name Constant name.
	 */
	private static function constant( string $name ): bool {
		/**
		 * Whether a host's constant counts as there, for setups that can't define it.
		 *
		 * @param bool   $defined Whether it's defined.
		 * @param string $name    Its name.
		 */
		return defined( $name ) || (bool) apply_filters( 'arvaseo_connector_host_constant', false, $name );
	}

	/**
	 * Whether a constant is defined and on (WPFC_DISABLE_HOOK_CLEAR_ALL_CACHE). The same filter as
	 * constant() lets a setup that can't define it say so.
	 *
	 * @param string $name Constant name.
	 */
	private static function constant_on( string $name ): bool {
		return ( defined( $name ) && (bool) constant( $name ) ) || (bool) apply_filters( 'arvaseo_connector_host_constant', false, $name );
	}

	/**
	 * Where WordPress is on the server, as hosts' folders show it (SiteGround: /home/customer/www/,
	 * Hostinger: /home/u123456789/domains/).
	 */
	private static function path(): string {
		/**
		 * The folder WordPress is in, for setups that move it.
		 *
		 * @param string $path ABSPATH, with forward slashes.
		 */
		return (string) apply_filters( 'arvaseo_connector_site_path', wp_normalize_path( ABSPATH ) );
	}

	/**
	 * One cache found.
	 *
	 * @param string   $id       ID.
	 * @param string   $name     Name.
	 * @param string   $kind     plugin, host, cdn or server.
	 * @param string   $clearing auto, by_hand or none.
	 * @param string[] $seen_by  plugin, constant, path, header, comment or connection.
	 * @param string   $detail   How to clear it by hand, or what was seen when there's nothing to clear.
	 * @param string   $where    A short place to look, for the Activity Log.
	 * @return array{id: string, name: string, kind: string, clearing: string, detail: string|null, where: string|null, seen_by: string[], blocks: bool}
	 */
	private static function entry( string $id, string $name, string $kind, string $clearing, array $seen_by, string $detail = '', string $where = '' ): array {
		return array(
			'id'       => $id,
			'name'     => $name,
			'kind'     => $kind,
			'clearing' => $clearing,
			'detail'   => '' === $detail ? null : $detail,
			'where'    => '' === $where ? null : $where,
			'seen_by'  => array() === $seen_by ? array( 'plugin' ) : $seen_by,
			'blocks'   => 'by_hand' === $clearing,
		);
	}

	/**
	 * What to do for a cache plugin there's no way into.
	 *
	 * @param string $name     Plugin name.
	 * @param bool   $switched Its clearing from other plugins is switched off in wp-config.php.
	 */
	private static function plugin_how( string $name, bool $switched = false ): string {
		/* translators: %s: a cache plugin's name. */
		$how = sprintf( __( 'Open %s and use its button that clears every saved page.', 'arvaseo-connector' ), $name );
		if ( $switched ) {
			/* translators: %s: how to clear the plugin's cache by hand. */
			return sprintf( __( 'Clearing it from other plugins is switched off in wp-config.php (WPFC_DISABLE_HOOK_CLEAR_ALL_CACHE). %s', 'arvaseo-connector' ), $how );
		}
		return $how;
	}

	// ----- The ways in -------------------------------------------------------------------------

	/**
	 * How each cache is cleared: whether the way in is there, and the call.
	 *
	 * @return array<string, array{available: callable, clear: callable}>
	 */
	private static function adapters(): array {
		$function = static fn ( string $name ): array => array(
			'available' => static fn (): bool => function_exists( $name ),
			'clear'     => static function () use ( $name ): void {
				call_user_func( $name );
			},
		);
		$action   = static fn ( string $hook ): array => array(
			'available' => static fn (): bool => (bool) has_action( $hook ),
			'clear'     => static function () use ( $hook ): void {
				do_action( $hook ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the cache plugins' documented purge hooks.
			},
		);
		$method   = static fn ( string $type, string $name ): array => array(
			'available' => static fn (): bool => class_exists( $type, false ) && is_callable( array( $type, $name ) ),
			'clear'     => static function () use ( $type, $name ): void {
				call_user_func( array( $type, $name ) );
			},
		);
		return array(
			'wp-super-cache'   => $function( 'wp_cache_clear_cache' ),
			'w3-total-cache'   => $function( 'w3tc_pgcache_flush' ),
			'wp-rocket'        => $function( 'rocket_clean_domain' ),
			// WP Fastest Cache's own function (it fires its wpfc_clear_all_cache action). The constant
			// WPFC_DISABLE_HOOK_CLEAR_ALL_CACHE switches that function off for everyone but not the action
			// behind it (checked against 1.5.3's source), so with the constant set the cache is the
			// owner's to clear and nothing is fired.
			'wp-fastest-cache' => array(
				'available' => static fn (): bool => ! self::constant_on( 'WPFC_DISABLE_HOOK_CLEAR_ALL_CACHE' ) && ( function_exists( 'wpfc_clear_all_cache' ) || (bool) has_action( 'wpfc_clear_all_cache' ) ),
				'clear'     => static function (): void {
					if ( function_exists( 'wpfc_clear_all_cache' ) ) {
						self::call_function( 'wpfc_clear_all_cache', false );
						return;
					}
					do_action( 'wpfc_clear_all_cache' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WP Fastest Cache's documented purge action.
				},
			),
			'cache-enabler'    => $action( 'cache_enabler_clear_complete_cache' ),
			'hummingbird'      => $action( 'wphb_clear_page_cache' ),
			'comet-cache'      => $method( 'comet_cache', 'clear' ),
			'nginx-cache'      => $action( 'rt_nginx_helper_purge_all' ),
			'breeze'           => $action( 'breeze_clear_all_cache' ),
			'siteground'       => $function( 'sg_cachepress_purge_cache' ),
			'wpengine'         => $method( 'WpeCommon', 'purge_varnish_cache' ),
			'nitropack'        => $function( 'nitropack_sdk_purge' ),
			'varnish'          => $action( self::ACTION_VARNISH ),
			'wp-optimize'      => array(
				'available' => static function (): bool {
					$cache = self::wp_optimize_page_cache();
					return is_object( $cache ) && is_callable( array( $cache, 'purge' ) );
				},
				'clear'     => static function (): void {
					$cache = self::wp_optimize_page_cache();
					if ( is_object( $cache ) && is_callable( array( $cache, 'purge' ) ) ) {
						$cache->purge();
					}
				},
			),
			// LiteSpeed Cache's own action, or the server's purge header when the plugin isn't there (D18).
			'litespeed'        => array(
				'available' => static fn (): bool => (bool) has_action( 'litespeed_purge_all' ) || self::litespeed_server(),
				'clear'     => static function (): void {
					if ( has_action( 'litespeed_purge_all' ) ) {
						do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's documented purge API.
						return;
					}
					self::purge_litespeed_server();
				},
			),
			// Cloudflare: through the token (Cloudflare::pages_changed does it), else the official plugin.
			'cloudflare'       => array(
				'available' => static fn (): bool => Cloudflare::connected() || (bool) has_action( self::ACTION_CLOUDFLARE ),
				'clear'     => static function (): void {
					if ( ! Cloudflare::connected() ) {
						do_action( self::ACTION_CLOUDFLARE ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- our own action, attached to the Cloudflare plugin's purge.
					}
				},
			),
		);
	}

	/**
	 * WP-Optimize's page cache: WP_Optimize()->get_page_cache(), or null when it isn't there.
	 *
	 * @return object|null
	 */
	private static function wp_optimize_page_cache(): ?object {
		if ( ! function_exists( 'WP_Optimize' ) ) {
			return null;
		}
		$plugin = WP_Optimize(); // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- WP-Optimize's own function.
		if ( ! is_object( $plugin ) || ! is_callable( array( $plugin, 'get_page_cache' ) ) ) {
			return null;
		}
		$cache = $plugin->get_page_cache();
		return is_object( $cache ) ? $cache : null;
	}

	/**
	 * Call another plugin's function by name (it exists: the caller checked).
	 *
	 * @param string $name         Function name.
	 * @param mixed  ...$arguments Its arguments.
	 */
	private static function call_function( string $name, ...$arguments ): void {
		call_user_func_array( $name, $arguments );
	}

	/**
	 * Whether there is a way in to clear a cache.
	 *
	 * @param string $id Cache ID.
	 */
	private static function available( string $id ): bool {
		$adapter = self::adapters()[ $id ] ?? null;
		if ( null === $adapter ) {
			return false;
		}
		try {
			return (bool) call_user_func( $adapter['available'] );
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	/**
	 * Whether Arva SEO can clear a cache plugin's saved pages with the plugin's own call.
	 *
	 * @param string $slug Plugin folder.
	 */
	public static function can_clear_plugin( string $slug ): bool {
		$id = isset( self::PLUGINS[ $slug ] ) ? self::PLUGINS[ $slug ][0] : ( self::PLUGIN_HOSTS[ $slug ] ?? '' );
		return '' !== $id && self::available( $id );
	}

	/**
	 * Whether LiteSpeed's server cache was seen and the LiteSpeed plugin isn't there to clear it.
	 */
	private static function litespeed_server(): bool {
		return in_array( 'litespeed', self::state()['header']['ids'], true ) && ! in_array( 'litespeed-cache', Fixes::active_plugin_slugs(), true );
	}

	/**
	 * Clear one cache.
	 *
	 * @param string $id Cache ID.
	 * @return bool Whether the call ran.
	 */
	private static function clear_one( string $id ): bool {
		$adapter = self::adapters()[ $id ] ?? null;
		if ( null === $adapter ) {
			return false;
		}
		try {
			if ( ! call_user_func( $adapter['available'] ) ) {
				return false;
			}
			call_user_func( $adapter['clear'] );
			return true;
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	// ----- Clearing ----------------------------------------------------------------------------

	/**
	 * What visitors see changed (the arvaseo_connector_pages_changed action). Other caches are
	 * cleared when it's a change Arva SEO makes, at most once in GAP: the first change clears, the
	 * ones that come meanwhile share one more clear when the gap is up. A change someone asked for
	 * (Clear every cache now, Regenerate) or that comes right before files are deleted clears at once.
	 *
	 * @param mixed $why What changed (Fixes::pages_changed()).
	 */
	public static function pages_changed( $why = '' ): void {
		$why = is_string( $why ) ? $why : '';
		if ( ! in_array( $why, self::REASONS, true ) ) {
			return;
		}
		$state = self::state();
		// Noted at most once a minute (changes come in bursts), but always when someone has said they
		// cleared a cache since the last one: this change is not covered by that.
		if ( $state['changed_at'] < time() - MINUTE_IN_SECONDS || $state['changed_at'] <= max( array_merge( array( 0 ), array_values( $state['handled'] ) ) ) ) {
			self::save( array( 'changed_at' => time() ) );
		}
		if ( ! self::any() ) {
			return;
		}
		if ( in_array( $why, self::AT_ONCE, true ) ) {
			// This clear covers the changes that were waiting for the next one.
			wp_clear_scheduled_hook( self::HOOK_CLEAR );
			update_option( self::LAST_CLEAR, time(), false );
			self::clear_all( $why );
			return;
		}
		$last = (int) get_option( self::LAST_CLEAR, 0 );
		if ( $last <= time() - self::GAP ) {
			update_option( self::LAST_CLEAR, time(), false );
			self::clear_all( $why );
			return;
		}
		if ( false === wp_next_scheduled( self::HOOK_CLEAR ) ) {
			wp_schedule_single_event( $last + self::GAP, self::HOOK_CLEAR );
		}
	}

	/**
	 * The clear that changes inside the gap waited for.
	 */
	public static function clear_later(): void {
		update_option( self::LAST_CLEAR, time(), false );
		self::clear_all( 'later' );
	}

	/**
	 * Clear every cache Arva SEO can reach, once, and say what it left for a person.
	 *
	 * @param string $why What changed, for the log.
	 * @return array{done: string[], by_hand: string[]} IDs.
	 */
	public static function clear_all( string $why = '' ): array {
		$done    = array();
		$by_hand = array();
		$names   = array();
		$hand    = array();
		$times   = self::state()['cleared_at'];
		foreach ( self::found() as $entry ) {
			if ( 'auto' === $entry['clearing'] && self::clear_one( $entry['id'] ) ) {
				$done[]                = $entry['id'];
				$names[]               = $entry['name'];
				$times[ $entry['id'] ] = time();
			} elseif ( 'by_hand' === $entry['clearing'] ) {
				$by_hand[] = $entry['id'];
				$hand[]    = null === $entry['where'] ? $entry['name'] : sprintf( '%1$s (%2$s)', $entry['name'], $entry['where'] );
			}
		}
		self::save(
			array(
				'cleared_at' => array_slice( $times, -30, null, true ),
				'cleared'    => array(
					'at'      => time(),
					'done'    => $done,
					'by_hand' => $by_hand,
				),
			)
		);
		if ( array() === $done && array() === $by_hand ) {
			return array(
				'done'    => $done,
				'by_hand' => $by_hand,
			);
		}
		$parts = array();
		if ( PageCache::active() ) {
			array_unshift( $names, __( 'Arva SEO’s page cache', 'arvaseo-connector' ) );
		}
		if ( array() !== $names ) {
			/* translators: %s: names of caches, such as WP Super Cache, Cloudflare. */
			$parts[] = sprintf( __( 'Cleared: %s.', 'arvaseo-connector' ), implode( ', ', $names ) );
		}
		if ( array() !== $hand ) {
			/* translators: %s: names of caches, with where to clear them. */
			$parts[] = sprintf( __( 'Clear yourself: %s.', 'arvaseo-connector' ), implode( ', ', $hand ) );
		}
		AuditLogger::info(
			'speed.other_caches_cleared',
			implode( ' ', $parts ),
			array(
				'why'     => $why,
				'cleared' => $done,
				'by_hand' => $by_hand,
			)
		);
		return array(
			'done'    => $done,
			'by_hand' => $by_hand,
		);
	}

	/**
	 * The plugin is being deleted with its data: saved pages in other caches may still link to the
	 * files it made, and the plugin's hooks are not loaded to clear them. Clear every cache that can
	 * be reached now (Cloudflare through its token too), and say whether the files may be deleted
	 * with it: not while a cache Arva SEO can't clear is listed, since it may still show pages that
	 * use them. The files are left in uploads then; nothing uses them once the pages are cleared.
	 *
	 * @return bool Whether the generated files may be deleted.
	 */
	public static function clear_before_uninstall(): bool {
		try {
			if ( Cloudflare::connected() ) {
				Cloudflare::purge_everything( 'uninstall' );
			}
			return array() === self::clear_all( 'uninstall' )['by_hand'];
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	/**
	 * An administrator cleared a cache Arva SEO can't reach. Files kept because of it can go after
	 * an hour (AssetCache), once everything else Arva SEO can clear is cleared again.
	 *
	 * @param string $id Cache ID.
	 * @return bool Whether it's one of the caches listed for hand clearing.
	 */
	public static function mark_handled( string $id ): bool {
		foreach ( self::found() as $entry ) {
			if ( $id !== $entry['id'] || 'by_hand' !== $entry['clearing'] ) {
				continue;
			}
			$handled        = self::state()['handled'];
			$handled[ $id ] = time();
			self::save( array( 'handled' => $handled ) );
			AuditLogger::info(
				'speed.other_cache_cleared_by_hand',
				/* translators: %s: a cache, such as Hostinger CDN. */
				sprintf( __( '%s was cleared by hand.', 'arvaseo-connector' ), $entry['name'] ),
				array( 'cache' => $id )
			);
			AssetCache::cleared_by_hand();
			self::schedule_registration();
			return true;
		}
		return false;
	}

	// ----- LiteSpeed's purge header (D18) ------------------------------------------------------

	/**
	 * Send LiteSpeed's purge header with this web response, or, where no web response can (WP-CLI,
	 * a system cron running PHP), ask the site for a page that sends it. The header works only in a
	 * web response, and only where the header check saw LiteSpeed's cache and its plugin isn't there.
	 */
	public static function purge_litespeed_server(): void {
		if ( 'cli' !== PHP_SAPI && ! headers_sent() ) {
			header( 'X-LiteSpeed-Purge: *', false );
			return;
		}
		wp_remote_get(
			add_query_arg( self::LITESPEED_QUERY, rawurlencode( self::litespeed_token() ), home_url( '/' ) ),
			array(
				'timeout'     => 10,
				'redirection' => 0,
				'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's filter for requests to the site itself.
				'user-agent'  => 'ArvaSEO-CacheClear/' . ARVASEO_CONNECTOR_VERSION,
			)
		);
	}

	/**
	 * A token that says this site asked: the time and its signature, good for two minutes.
	 *
	 * @param int|null $time Time (tests).
	 */
	public static function litespeed_token( ?int $time = null ): string {
		$time = $time ?? time();
		return $time . '.' . hash_hmac( 'sha256', 'litespeed-purge|' . $time, wp_salt( 'auth' ) );
	}

	/**
	 * Whether a token is this site's and not older than two minutes.
	 *
	 * @param string $token Token.
	 */
	public static function valid_litespeed_token( string $token ): bool {
		$parts = explode( '.', $token, 2 );
		if ( 2 !== count( $parts ) || ! ctype_digit( $parts[0] ) || abs( time() - (int) $parts[0] ) > 2 * MINUTE_IN_SECONDS ) {
			return false;
		}
		return hash_equals( self::litespeed_token( (int) $parts[0] ), $token );
	}

	/**
	 * The signed address: answer with the purge header and nothing else. Anything else looks like
	 * any other page.
	 */
	public static function serve_litespeed_purge(): void {
		// phpcs:disable WordPress.Security.NonceVerification -- a signed token, checked below.
		if ( ! isset( $_GET[ self::LITESPEED_QUERY ] ) || ! is_string( $_GET[ self::LITESPEED_QUERY ] ) ) {
			return;
		}
		$token = sanitize_text_field( wp_unslash( $_GET[ self::LITESPEED_QUERY ] ) );
		// phpcs:enable WordPress.Security.NonceVerification
		if ( ! self::valid_litespeed_token( $token ) ) {
			return;
		}
		nocache_headers();
		header( 'X-LiteSpeed-Purge: *' );
		status_header( 204 );
		exit;
	}

	// ----- The look at the homepage ------------------------------------------------------------

	/**
	 * Once a day (daily maintenance): look at the homepage's cache headers, if it's time.
	 */
	public static function maintain(): void {
		$state = self::state()['header'];
		if ( $state['tried'] < time() - self::CHECK_EVERY ) {
			self::check();
		}
	}

	/**
	 * Ask for the homepage, anonymously, from this server, and keep what its headers and comments
	 * say about caches. A site that can't be reached from itself leaves what was known as it was.
	 */
	public static function check(): void {
		$state = self::state();
		self::save(
			array(
				'header' => array_merge( $state['header'], array( 'tried' => time() ) ),
			)
		);
		$response = wp_remote_get(
			home_url( '/' ),
			array(
				'timeout'             => 10,
				'redirection'         => 3,
				'sslverify'           => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's filter for requests to the site itself.
				'user-agent'          => 'Mozilla/5.0 (compatible; ArvaSEO-CacheCheck/' . ARVASEO_CONNECTOR_VERSION . '; +https://arvaseo.com/)',
				'headers'             => array( 'Accept' => 'text/html' ),
				'limit_response_size' => 3 * MB_IN_BYTES,
			)
		);
		if ( is_wp_error( $response ) ) {
			return;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 400 ) {
			return;
		}
		$headers = wp_remote_retrieve_headers( $response );
		$headers = is_object( $headers ) && is_callable( array( $headers, 'getAll' ) ) ? (array) $headers->getAll() : (array) $headers;
		$body    = (string) wp_remote_retrieve_body( $response );
		$read    = self::read( $headers, $body );
		$linked  = array();
		if ( preg_match_all( self::GENERATED, $body, $matches ) ) {
			$linked = array_slice( array_values( array_unique( $matches[1] ) ), 0, 200 );
		}
		$lifetimes   = $state['header']['lifetimes'];
		$lifetimes[] = array( time(), (int) $read['lifetime'] );
		self::save(
			array(
				'header' => array(
					'at'        => time(),
					'tried'     => time(),
					'code'      => $code,
					'seen'      => $read['seen'],
					'ids'       => $read['ids'],
					'comments'  => $read['comments'],
					'lifetimes' => array_slice( $lifetimes, -7 ),
					'linked'    => $linked,
				),
			)
		);
		self::schedule_registration();
	}

	/**
	 * What a homepage's headers and body say about caches. Only the headers on the list are read.
	 *
	 * @param array<string, mixed> $headers Response headers (any case).
	 * @param string               $body    The page.
	 * @return array{seen: array<string, string>, ids: string[], comments: string[], lifetime: int|null}
	 */
	public static function read( array $headers, string $body = '' ): array {
		$seen = array();
		foreach ( $headers as $name => $value ) {
			$name = strtolower( (string) $name );
			if ( ! in_array( $name, self::HEADERS, true ) ) {
				continue;
			}
			$value         = is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value;
			$seen[ $name ] = substr( sanitize_text_field( $value ), 0, 120 );
		}
		$ids   = array();
		$lower = static fn ( string $name ): string => strtolower( $seen[ $name ] ?? '' );
		$cf    = strtoupper( $seen['cf-cache-status'] ?? '' );
		$has   = static fn ( string $name ): bool => isset( $seen[ $name ] ) && '' !== $seen[ $name ];
		$via   = $lower( 'via' ) . ' ' . $lower( 'x-cache' );
		if ( in_array( $cf, self::CLOUDFLARE_KEEPS, true ) || $has( 'cf-apo-via' ) ) {
			$ids[] = 'cloudflare';
		}
		if ( $has( 'x-litespeed-cache' ) || $has( 'x-qc-cache' ) ) {
			$ids[] = 'litespeed';
		}
		if ( $has( 'x-hcdn-cache-status' ) ) {
			$ids[] = 'hostinger-cdn';
		}
		if ( str_contains( $lower( 'platform' ), 'hostinger' ) ) {
			$ids[] = 'hostinger';
		}
		if ( $has( 'x-proxy-cache' ) ) {
			// nginx's proxy_cache and other hosts send this header too: it is SiteGround's only with another sign of SiteGround.
			$ids[] = self::siteground_signs() ? 'siteground' : 'proxy-cache';
		}
		if ( $has( 'x-kinsta-cache' ) ) {
			$ids[] = 'kinsta';
		}
		if ( $has( 'x-varnish' ) || str_contains( $via, 'varnish' ) ) {
			$ids[] = 'varnish';
		}
		if ( $has( 'x-fastcgi-cache' ) || $has( 'x-cache-status' ) ) {
			$ids[] = 'nginx-cache';
		}
		if ( $has( 'x-ac' ) ) {
			$ids[] = 'wpcom-edge';
		}
		if ( str_contains( $via, 'cloudfront' ) ) {
			$ids[] = 'cloudfront';
		}
		$specific = array_diff( $ids, array( 'hostinger' ) );
		if ( array() === $specific && ( $has( 'x-cache' ) || ( is_numeric( $seen['age'] ?? null ) && (int) $seen['age'] > 0 ) ) ) {
			// Something kept this page and said so, and none of the above explains it.
			$ids[] = 'proxy-cache';
		}
		$comments = array();
		foreach ( self::COMMENTS as $id => $text ) {
			if ( 1 === preg_match( '~<!--[^>]{0,200}?' . preg_quote( $text, '~' ) . '~i', $body ) ) {
				$comments[] = $id;
			}
		}
		return array(
			'seen'     => $seen,
			'ids'      => array_values( array_unique( $ids ) ),
			'comments' => $comments,
			'lifetime' => self::lifetime( $seen ),
		);
	}

	/**
	 * Whether the site shows another sign of SiteGround than a header: its folder, its plugin or
	 * its purge function.
	 */
	private static function siteground_signs(): bool {
		return str_starts_with( self::path(), '/home/customer/www/' )
			|| in_array( 'sg-cachepress', Fixes::active_plugin_slugs(), true )
			|| function_exists( 'sg_cachepress_purge_cache' )
			|| (bool) apply_filters( 'arvaseo_connector_siteground', false );
	}

	/**
	 * How long a cache in front of the site may keep the page, from Cache-Control: s-maxage, else
	 * max-age; 0 when it must not be kept; null when it says nothing.
	 *
	 * @param array<string, string> $seen The headers read.
	 */
	private static function lifetime( array $seen ): ?int {
		$control = strtolower( $seen['cache-control'] ?? '' );
		if ( '' === $control ) {
			return null;
		}
		if ( 1 === preg_match( '/\b(?:no-store|private|no-cache)\b/', $control ) ) {
			return 0;
		}
		$longest = null;
		foreach ( array( 's-maxage', 'max-age' ) as $directive ) {
			if ( 1 === preg_match( '/\b' . $directive . '=(\d+)/', $control, $match ) ) {
				$longest = max( (int) $longest, (int) $match[1] );
			}
		}
		return null === $longest ? null : min( $longest, self::MAX_LIFETIME );
	}

	/**
	 * The generated files the live homepage links to, by file name (and a font copy's folder
	 * name), or null when the homepage can't be read: a file it uses is never deleted.
	 *
	 * @return string[]|null
	 */
	public static function homepage_files(): ?array {
		$response = wp_remote_get(
			home_url( '/' ),
			array(
				'timeout'             => 10,
				'redirection'         => 3,
				'sslverify'           => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's filter for requests to the site itself.
				'user-agent'          => 'Mozilla/5.0 (compatible; ArvaSEO-CacheCheck/' . ARVASEO_CONNECTOR_VERSION . '; +https://arvaseo.com/)',
				'limit_response_size' => 3 * MB_IN_BYTES,
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$names = array();
		if ( preg_match_all( self::GENERATED, (string) wp_remote_retrieve_body( $response ), $matches ) ) {
			foreach ( $matches[1] as $name ) {
				$names[] = $name;
				$names[] = (string) preg_replace( '/\.css$/', '', $name );
			}
		}
		return array_values( array_unique( $names ) );
	}

	// ----- Telling Arva SEO --------------------------------------------------------------------

	/**
	 * The caches found, as the site's details carry them (English, at most 15, those a person has to
	 * clear first):
	 * - id (lower-case letters, digits, - and _, at most 40), name (at most 80), kind (plugin, host,
	 *   cdn or server);
	 * - handling: cleared (Arva SEO clears it when pages change), by_hand (a person has to) or none
	 *   (found, but it keeps no pages);
	 * - how_to_clear (at most 300; by_hand only, else null);
	 * - last_cleared_at (cleared: when Arva SEO last cleared it; by_hand: when a person said "I've
	 *   cleared it"; null if never);
	 * - seen_at (when the homepage's headers were last looked at, null before the first look);
	 * - kept_files and kept_bytes (old generated files kept because this cache may still show pages
	 *   that use them, set only on by_hand caches while files are kept; the same numbers on each of
	 *   them: they are one set of files, not a sum).
	 *
	 * @return list<array{id: string, name: string, kind: string, handling: string, how_to_clear: string|null, last_cleared_at: string|null, seen_at: string|null, kept_files: int|null, kept_bytes: int|null}>
	 */
	public static function for_registration(): array {
		$switched = false;
		if ( function_exists( 'switch_to_locale' ) && 'en_US' !== determine_locale() ) {
			$switched = switch_to_locale( 'en_US' );
		}
		try {
			$state = self::state();
			$kept  = AssetCache::kept();
			$seen  = self::checked_at();
			$out   = array();
			foreach ( array_slice( self::build(), 0, self::MAX_REPORTED ) as $entry ) {
				$by_hand = 'by_hand' === $entry['clearing'];
				$at      = $by_hand ? ( $state['handled'][ $entry['id'] ] ?? null ) : ( $state['cleared_at'][ $entry['id'] ] ?? null );
				$out[]   = array(
					'id'              => $entry['id'],
					'name'            => mb_substr( $entry['name'], 0, 80 ),
					'kind'            => $entry['kind'],
					'handling'        => 'auto' === $entry['clearing'] ? 'cleared' : $entry['clearing'],
					'how_to_clear'    => $by_hand && null !== $entry['detail'] ? mb_substr( $entry['detail'], 0, 300 ) : null,
					'last_cleared_at' => null === $at ? null : Ids::iso( $at ),
					'seen_at'         => $seen,
					'kept_files'      => $entry['blocks'] && null !== $kept ? $kept['files'] : null,
					'kept_bytes'      => $entry['blocks'] && null !== $kept ? $kept['bytes'] : null,
				);
			}
		} finally {
			if ( $switched ) {
				restore_previous_locale();
			}
		}
		return $out;
	}

	/**
	 * A fingerprint of what the app is told, without what changes all the time: the times of
	 * automatic clears (every few minutes), the files kept, and the time of the look (a day at most).
	 */
	private static function signature(): string {
		$list = self::for_registration();
		foreach ( $list as $i => $entry ) {
			if ( 'cleared' === $entry['handling'] ) {
				$list[ $i ]['last_cleared_at'] = null;
			}
			$list[ $i ]['kept_files'] = null;
			$list[ $i ]['kept_bytes'] = null;
			$list[ $i ]['seen_at']    = null === $entry['seen_at'] ? null : substr( $entry['seen_at'], 0, 10 );
		}
		return md5( (string) wp_json_encode( $list ) );
	}

	/**
	 * The list changed (a check, a plugin switched on or off, a cache cleared by hand): the site's
	 * details are sent again soon, once.
	 */
	public static function schedule_registration(): void {
		if ( ! TokenStore::is_connected() ) {
			return;
		}
		$signature = self::signature();
		if ( self::state()['reported'] === $signature ) {
			return;
		}
		self::save( array( 'reported' => $signature ) );
		if ( ! wp_next_scheduled( 'arvaseo_connector_register' ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'arvaseo_connector_register' );
		}
	}

	/**
	 * A plugin was switched on or off. Not when it is Arva SEO itself being deactivated: its jobs
	 * were just unscheduled.
	 *
	 * @param mixed $plugin The plugin file.
	 */
	public static function plugins_changed( $plugin = '' ): void {
		if ( CronManager::leaving( $plugin ) ) {
			return;
		}
		self::schedule_registration();
	}

	/**
	 * Look at the homepage soon, once (after an update, or the first time).
	 */
	public static function check_soon(): void {
		if ( false === wp_next_scheduled( self::HOOK_CHECK ) ) {
			wp_schedule_single_event( time() + 2 * MINUTE_IN_SECONDS, self::HOOK_CHECK );
		}
	}
}
