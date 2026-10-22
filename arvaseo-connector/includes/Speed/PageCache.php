<?php
/**
 * Page caching (2.6.0): pages are saved as ready-made HTML and served without running WordPress.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Speed;

use ArvaSEO\Connector\Logging\AuditLogger;
use ArvaSEO\Connector\Support\Ids;
use ArvaSEO\Connector\Support\Lock;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/page-cache-serve.php';

/**
 * How it works:
 * - wp-content/advanced-cache.php (written here, with WP_CACHE in wp-config.php) loads
 *   page-cache-serve.php before WordPress: a saved page is sent straight away; otherwise the page
 *   WordPress makes is handed to capture() at the end, which saves it when anyone may see it.
 * - On Apache and LiteSpeed, rules at the top of .htaccess send saved pages without PHP at all.
 *   .htaccess is backed up first (ServerRules::backup()), and the backup can be downloaded.
 * - Pages are cleared when they change, and refilled in the background, a few a minute.
 * - Turning it on checks the homepage from the server: made once, then served from the cache.
 *   If it isn't, everything is taken out again and the site is as before.
 */
final class PageCache {

	/** What's been set up, its last check and the choices (not autoloaded: the front end doesn't read it). */
	public const OPTION = 'arvaseo_connector_page_cache';

	/** Pages waiting to be refilled. */
	public const PRELOAD = 'arvaseo_connector_page_cache_preload';

	/** The background jobs: refilling, and clearing out pages past their lifetime. */
	public const HOOK_PRELOAD = 'arvaseo_connector_page_cache_preload';

	public const HOOK_GC = 'arvaseo_connector_page_cache_gc';

	/** The .htaccess block, above WordPress's own. */
	public const MARKER = 'Arva SEO Page Cache';

	/** Found in the drop-in this plugin wrote, and nowhere else. */
	public const DROPIN_MARK = 'ARVASEO_CONNECTOR_PAGE_CACHE_DROPIN';

	/** The line added to wp-config.php when WP_CACHE isn't on. */
	public const CONFIG_LINE = "define( 'WP_CACHE', true ); // Added by Arva SEO (Page caching). Removed when you turn it off.";

	/** How long a saved page is kept before it's made again: 10 hours. */
	public const TTL = 36000;

	/** Pages refilled each minute, and at most this many in one go. */
	public const PRELOAD_BATCH = 5;

	public const PRELOAD_MAX = 400;

	/** Cache plugins that would serve pages too: their own page cache does the job. */
	public const CACHE_PLUGINS = array( 'wp-rocket', 'w3-total-cache', 'wp-super-cache', 'wp-fastest-cache', 'cache-enabler', 'comet-cache', 'hummingbird-performance', 'wp-optimize', 'swift-performance-lite', 'nitropack', 'flying-press', 'powered-cache', 'borlabs-cache', 'jetpack-boost', 'wp-cloudflare-page-cache' );

	/** Hosts' own plugins, which run the host's server cache: people may choose Arva SEO's instead. */
	public const HOST_PLUGINS = array( 'sg-cachepress', 'litespeed-cache', 'breeze' );

	/**
	 * Whether everything was cleared in this request already (a settings page saves several options).
	 *
	 * @var bool
	 */
	private static bool $cleared = false;

	/**
	 * Hooks. The clearing ones only while it's on.
	 */
	public static function init(): void {
		add_action( 'admin_init', array( self::class, 'kill_switch' ) );
		add_action( 'admin_init', array( self::class, 'step_aside' ) );
		add_action( self::HOOK_PRELOAD, array( self::class, 'run_preload' ) );
		add_action( self::HOOK_GC, array( self::class, 'gc' ) );
		// Whoever caches the pages: a change Arva SEO made to every page clears the host's copy too.
		add_action( 'arvaseo_connector_pages_changed', array( self::class, 'purge_host_cache' ) );
		if ( ! self::active() ) {
			return;
		}
		add_action( 'arvaseo_connector_pages_changed', array( self::class, 'pages_changed' ) );
		add_action( 'arvaseo_connector_seo_updated', array( self::class, 'seo_updated' ) );
		add_action( 'transition_post_status', array( self::class, 'post_changed' ), 10, 3 );
		add_action( 'wp_set_comment_status', array( self::class, 'comment_changed' ) );
		add_action( 'comment_post', array( self::class, 'comment_changed' ) );
		add_action( 'edit_comment', array( self::class, 'comment_changed' ) );
		add_action( 'woocommerce_product_set_stock', array( self::class, 'product_changed' ) );
		add_action( 'woocommerce_variation_set_stock', array( self::class, 'product_changed' ) );
		foreach ( array( 'switch_theme', 'customize_save_after', 'wp_update_nav_menu', 'wp_delete_nav_menu', 'activated_plugin', 'deactivated_plugin', 'upgrader_process_complete' ) as $hook ) {
			add_action( $hook, array( self::class, 'site_changed' ) );
		}
		add_action( 'update_option_permalink_structure', array( self::class, 'permalinks_changed' ) );
		foreach ( array( 'sidebars_widgets', 'blogname', 'blogdescription', 'show_on_front', 'page_on_front', 'page_for_posts', 'site_icon', 'WPLANG', 'date_format', 'time_format', 'theme_mods_' . get_option( 'stylesheet' ) ) as $option ) {
			add_action( 'update_option_' . $option, array( self::class, 'site_changed' ) );
		}
	}

	// ----- State -----------------------------------------------------------------------------

	/**
	 * What's stored, over the defaults.
	 *
	 * @return array{active: bool, phones: bool, preload: bool, over_host: bool, served_by: string|null, check: array{at: string, before: float|null, after: float|null}|null, wp_config: array{added: bool, original: string|null}|null, dropin_backup: bool}
	 */
	public static function settings(): array {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$check = is_array( $saved['check'] ?? null ) && is_string( $saved['check']['at'] ?? null ) ? $saved['check'] : null;
		$conf  = is_array( $saved['wp_config'] ?? null ) ? $saved['wp_config'] : null;
		return array(
			'active'        => (bool) ( $saved['active'] ?? false ),
			'phones'        => (bool) ( $saved['phones'] ?? false ),
			'preload'       => (bool) ( $saved['preload'] ?? true ),
			'over_host'     => (bool) ( $saved['over_host'] ?? false ),
			'served_by'     => in_array( $saved['served_by'] ?? null, array( 'htaccess', 'php' ), true ) ? $saved['served_by'] : null,
			'check'         => null === $check ? null : array(
				'at'     => $check['at'],
				'before' => is_numeric( $check['before'] ?? null ) ? (float) $check['before'] : null,
				'after'  => is_numeric( $check['after'] ?? null ) ? (float) $check['after'] : null,
			),
			'wp_config'     => null === $conf ? null : array(
				'added'    => (bool) ( $conf['added'] ?? false ),
				'original' => is_string( $conf['original'] ?? null ) ? $conf['original'] : null,
			),
			'dropin_backup' => (bool) ( $saved['dropin_backup'] ?? false ),
		);
	}

	/**
	 * Change some of what's stored.
	 *
	 * @param array<string, mixed> $changes Keys of settings().
	 */
	private static function update( array $changes ): void {
		update_option( self::OPTION, array_merge( self::settings(), $changes ), false );
	}

	/**
	 * Whether pages are being saved and served (set up and checked), and not switched off in
	 * wp-config.php.
	 */
	public static function active(): bool {
		return self::settings()['active'] && ! ( defined( Fixes::KILL_SWITCH ) && constant( Fixes::KILL_SWITCH ) );
	}

	/**
	 * The folder pages are saved in.
	 */
	public static function dir(): string {
		return WP_CONTENT_DIR . '/cache/arvaseo';
	}

	/**
	 * The drop-in WordPress loads when WP_CACHE is on.
	 */
	public static function dropin(): string {
		return WP_CONTENT_DIR . '/advanced-cache.php';
	}

	/**
	 * Whether the drop-in in place is this plugin's.
	 */
	public static function dropin_is_ours(): bool {
		$file = self::dropin();
		return is_readable( $file ) && str_contains( (string) file_get_contents( $file ), self::DROPIN_MARK ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file.
	}

	/**
	 * Whether WP_CACHE is on for this request.
	 */
	private static function wp_cache_on(): bool {
		return defined( 'WP_CACHE' ) && WP_CACHE;
	}

	/**
	 * Whether wp-config.php turns WP_CACHE on for the requests to come. The file decides, not this
	 * request's constant: Arva SEO may have just taken its own line out. When the file can't be
	 * read (kept elsewhere), the constant is all there is to go by.
	 */
	private static function config_sets_cache(): bool {
		$file = self::config_path();
		if ( ! is_readable( $file ) ) {
			return self::wp_cache_on();
		}
		$contents = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file.
		return 1 === preg_match( '/^[ \t]*define\(\s*[\'"]WP_CACHE[\'"]\s*,\s*(true|1|\'1\'|"1")\s*\)/mi', $contents );
	}

	// ----- Other caches ------------------------------------------------------------------------

	/**
	 * The web host's own page cache, when this site has one.
	 *
	 * @return array{id: string, name: string, off: string}|null
	 */
	public static function host_cache(): ?array {
		$active = Fixes::active_plugin_slugs();
		$server = isset( $_SERVER['SERVER_SOFTWARE'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) ) : '';
		$hosts  = array(
			'siteground' => in_array( 'sg-cachepress', $active, true ),
			'litespeed'  => in_array( 'litespeed-cache', $active, true ) && str_contains( $server, 'litespeed' ),
			'wpengine'   => function_exists( 'is_wpe' ) || defined( 'WPE_APIKEY' ) || class_exists( 'WpeCommon', false ),
			'kinsta'     => defined( 'KINSTAMU_VERSION' ),
			'cloudways'  => in_array( 'breeze', $active, true ) || isset( $_SERVER['cw_allowed_ip'] ),
		);
		foreach ( $hosts as $id => $found ) {
			if ( $found ) {
				return array(
					'id'   => $id,
					'name' => self::host_names()[ $id ][0],
					'off'  => self::host_names()[ $id ][1],
				);
			}
		}
		return null;
	}

	/**
	 * A change here altered every page (2.7.0): clear the host's own cache too, through the API
	 * its plugin offers (SiteGround's Speed Optimizer, LiteSpeed Cache, WP Engine, Breeze), so
	 * visitors don't get an old copy for hours.
	 */
	public static function purge_host_cache(): void {
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache();
		}
		do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's documented purge API.
		do_action( 'breeze_clear_all_cache' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Breeze's documented purge API.
		$wp_engine = array( 'WpeCommon', 'purge_varnish_cache' );
		if ( class_exists( 'WpeCommon', false ) && is_callable( $wp_engine ) ) {
			call_user_func( $wp_engine );
		}
	}

	/**
	 * A host's cache in front of the site that nothing here can clear, or null: SiteGround's
	 * dynamic cache is always on, and only its Speed Optimizer plugin clears it when pages change.
	 * Without the plugin, visitors can get an old copy of a page for up to 12 hours.
	 */
	public static function uncleared_host_cache(): ?string {
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			return null;
		}
		// SiteGround's accounts live in /home/customer/www/<domain>/public_html.
		$siteground = str_starts_with( wp_normalize_path( ABSPATH ), '/home/customer/www/' );
		/**
		 * Whether this site is behind SiteGround's dynamic cache, for setups the folder doesn't show.
		 *
		 * @param bool $siteground True on SiteGround.
		 */
		return (bool) apply_filters( 'arvaseo_connector_siteground', $siteground ) ? __( 'SiteGround’s dynamic cache', 'arvaseo-connector' ) : null;
	}

	/**
	 * Hosts' caches: their name, and how to turn them off.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	private static function host_names(): array {
		return array(
			'siteground' => array( __( 'SiteGround’s dynamic cache', 'arvaseo-connector' ), __( 'In Speed Optimizer → Caching, turn off Dynamic Caching. You can keep the plugin for its other settings.', 'arvaseo-connector' ) ),
			'litespeed'  => array( __( 'LiteSpeed Cache', 'arvaseo-connector' ), __( 'In LiteSpeed Cache → Cache, set Enable Cache to Off. You can keep the plugin for its other settings.', 'arvaseo-connector' ) ),
			'wpengine'   => array( __( 'WP Engine’s page cache', 'arvaseo-connector' ), __( 'WP Engine’s cache can’t be turned off from WordPress: ask WP Engine’s support to turn it off for this site. Until then it stays in front of Arva SEO’s.', 'arvaseo-connector' ) ),
			'kinsta'     => array( __( 'Kinsta’s page cache', 'arvaseo-connector' ), __( 'Kinsta’s cache can’t be turned off from WordPress: ask Kinsta’s support to turn it off for this site. Until then it stays in front of Arva SEO’s.', 'arvaseo-connector' ) ),
			'cloudways'  => array( __( 'Cloudways’ Varnish cache', 'arvaseo-connector' ), __( 'In Cloudways → your application → Application Settings, turn off Varnish, and turn off Breeze’s page cache.', 'arvaseo-connector' ) ),
		);
	}

	/**
	 * Who already caches pages, so Arva SEO doesn't: a cache plugin, or the host's cache unless the
	 * administrator chose Arva SEO's instead.
	 */
	public static function handled_by(): ?string {
		$active = Fixes::active_plugin_slugs();
		foreach ( self::CACHE_PLUGINS as $slug ) {
			if ( in_array( $slug, $active, true ) ) {
				return Fixes::plugin_names()[ $slug ] ?? $slug;
			}
		}
		$host = self::host_cache();
		return null !== $host && ! self::settings()['over_host'] ? $host['name'] : null;
	}

	/**
	 * Use Arva SEO's page cache although the host has its own (true), or leave it to the host (false,
	 * which turns Arva SEO's off).
	 *
	 * @param bool $ours Use Arva SEO's.
	 */
	public static function use_over_host( bool $ours ): void {
		if ( ! $ours && 'on' === ( Fixes::stored()['fixes']['page_cache']['state'] ?? 'off' ) ) {
			Fixes::set( 'page_cache', 'off' );
		}
		self::update( array( 'over_host' => $ours ) );
		$host = self::host_cache();
		AuditLogger::info(
			'speed.page_cache_host',
			$ours
				/* translators: %s: the host's cache, such as SiteGround's dynamic cache. */
				? sprintf( __( 'Page caching: Arva SEO’s page cache may be used instead of %s.', 'arvaseo-connector' ), $host['name'] ?? __( 'the host’s cache', 'arvaseo-connector' ) )
				/* translators: %s: the host's cache. */
				: sprintf( __( 'Page caching: left to %s.', 'arvaseo-connector' ), $host['name'] ?? __( 'the host’s cache', 'arvaseo-connector' ) )
		);
	}

	/**
	 * Why pages can't be cached here, or null when they can.
	 */
	public static function unavailable(): ?string {
		if ( is_multisite() ) {
			return __( 'Not on a multisite network yet: its sites share one advanced-cache.php file.', 'arvaseo-connector' );
		}
		$dropin = self::dropin();
		if ( ! wp_is_writable( WP_CONTENT_DIR ) || ( file_exists( $dropin ) && ! wp_is_writable( $dropin ) ) ) {
			return __( 'WordPress can’t write to your wp-content folder, where saved pages go. Ask your host to make it writable.', 'arvaseo-connector' );
		}
		if ( ! self::config_sets_cache() && null === self::config_file() ) {
			return __( 'WordPress can’t change your wp-config.php file. Add the line below near the top of it, then turn this on.', 'arvaseo-connector' );
		}
		return null;
	}

	/**
	 * The wp-config.php file, when it can be changed (the security scan's "Turn off the file
	 * editor" writes to it too).
	 */
	public static function config_file(): ?string {
		$file = self::config_path();
		return is_file( $file ) && wp_is_writable( $file ) ? $file : null;
	}

	/**
	 * Where wp-config.php is, as WordPress finds it: in its folder or the one above.
	 */
	private static function config_path(): string {
		$file = file_exists( ABSPATH . 'wp-config.php' ) ? ABSPATH . 'wp-config.php' : dirname( ABSPATH ) . '/wp-config.php';
		/**
		 * The wp-config.php file Page caching adds WP_CACHE to, for setups that keep it elsewhere.
		 *
		 * @param string $file Path, as WordPress finds it.
		 */
		return (string) apply_filters( 'arvaseo_connector_page_cache_config_file', $file );
	}

	// ----- On and off ----------------------------------------------------------------------------

	/**
	 * Set everything up, then check the homepage is made once and served from the cache the
	 * second time. Takes everything out again when it isn't.
	 *
	 * @return string|null Why it didn't go on, or null when it did.
	 */
	public static function enable(): ?string {
		if ( null !== self::unavailable() ) {
			return 'unavailable';
		}
		self::remove_tree( self::dir() );
		if ( ! self::write_folder() || ! self::write_dropin() ) {
			self::disable();
			return 'dropin_not_written';
		}
		if ( ! self::config_sets_cache() && ! self::add_config() ) {
			self::disable();
			return 'dropin_not_written';
		}
		$rules = self::rules_possible() && self::write_rules( self::rules() );
		self::update(
			array(
				'active'    => true,
				'served_by' => null,
				'check'     => null,
			)
		);
		$check = self::check();
		if ( null !== $check['error'] && $rules && 'cache_server_error' === $check['error'] ) {
			// The server didn't take the rules: PHP serves the pages instead.
			self::write_rules( array() );
			self::remove_tree( self::dir() );
			self::write_folder();
			$check = self::check();
		}
		if ( null !== $check['error'] ) {
			self::disable();
			return $check['error'];
		}
		self::update(
			array(
				'served_by' => $check['served_by'],
				'check'     => array(
					'at'     => Ids::iso(),
					'before' => $check['before'],
					'after'  => $check['after'],
				),
			)
		);
		if ( ! wp_next_scheduled( self::HOOK_GC ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::HOOK_GC );
		}
		self::queue_all();
		return null;
	}

	/**
	 * Take everything out: the .htaccess rules, the drop-in, the WP_CACHE line Arva SEO added and
	 * the saved pages. Another plugin's drop-in is never touched.
	 */
	public static function disable(): void {
		if ( self::rules_present() ) {
			self::write_rules( array() );
		}
		if ( self::dropin_is_ours() ) {
			wp_delete_file( self::dropin() );
		}
		if ( ! file_exists( self::dropin() ) ) {
			// Another cache plugin's drop-in in its place needs WP_CACHE too: the line stays then.
			self::remove_config();
		}
		self::remove_tree( self::dir() );
		wp_clear_scheduled_hook( self::HOOK_PRELOAD );
		wp_clear_scheduled_hook( self::HOOK_GC );
		delete_option( self::PRELOAD );
		self::update(
			array(
				'active'    => false,
				'served_by' => null,
				'check'     => null,
				'wp_config' => null,
			)
		);
	}

	/**
	 * The plugin is deactivated or deleted: nothing of the page cache stays behind, and the fix is
	 * off, so it starts off when the plugin comes back.
	 */
	public static function remove_on_exit(): void {
		if ( is_multisite() ) {
			return;
		}
		self::disable();
		$saved = get_option( Fixes::OPTION, array() );
		if ( is_array( $saved ) && 'on' === ( $saved['fixes']['page_cache']['state'] ?? null ) ) {
			$saved['fixes']['page_cache'] = array(
				'state' => 'off',
				'since' => null,
			);
			update_option( Fixes::OPTION, $saved, true );
		}
	}

	/**
	 * Another cache plugin (or the host's, unless Arva SEO's was chosen) became active while this
	 * one was on: Arva SEO's turns off, so pages aren't cached twice.
	 */
	public static function step_aside(): void {
		$other = self::settings()['active'] ? self::handled_by() : null;
		if ( null === $other ) {
			return;
		}
		Fixes::set( 'page_cache', 'off' );
		AuditLogger::info(
			'speed.page_cache_stepped_aside',
			/* translators: %s: the other cache, such as WP Rocket. */
			sprintf( __( 'Page caching was turned off: %s caches pages now.', 'arvaseo-connector' ), $other )
		);
	}

	/**
	 * Every fix switched off in wp-config.php: the drop-in already stops serving, and the
	 * .htaccess rules come out too.
	 */
	public static function kill_switch(): void {
		if ( defined( Fixes::KILL_SWITCH ) && constant( Fixes::KILL_SWITCH ) && self::rules_present() ) {
			self::write_rules( array() );
		}
	}

	/**
	 * The choices: phones get their own copy; refill the cache after it's cleared.
	 *
	 * @param bool $phones  Separate copies for phones.
	 * @param bool $preload Refill in the background.
	 */
	public static function save_choices( bool $phones, bool $preload ): void {
		$was = self::settings();
		self::update(
			array(
				'phones'  => $phones,
				'preload' => $preload,
			)
		);
		if ( $was['active'] && $was['phones'] !== $phones ) {
			// The drop-in and the rules know which copy a phone gets.
			self::write_dropin();
			if ( self::rules_present() ) {
				self::write_rules( self::rules() );
			}
			self::clear_all( 'choices' );
		}
		if ( ! $preload ) {
			wp_clear_scheduled_hook( self::HOOK_PRELOAD );
			delete_option( self::PRELOAD );
		}
		AuditLogger::info(
			'speed.page_cache_options',
			__( 'Page caching choices saved.', 'arvaseo-connector' ),
			array(
				'phones'  => $phones,
				'preload' => $preload,
			)
		);
	}

	/**
	 * The saved-pages folder, with an empty page so it can't be listed and, on Apache, headers for
	 * the pages it sends.
	 */
	private static function write_folder(): bool {
		$dir = self::dir();
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- small files in the plugin's own cache folder.
		file_put_contents( $dir . '/index.html', '' );
		file_put_contents(
			$dir . '/.htaccess',
			implode(
				"\n",
				array(
					'# Arva SEO page cache: pages saved by Page caching. Browsers check back before reusing one.',
					'<IfModule mod_headers.c>',
					'	Header set X-Arva-Cache "hit-file"',
					'	Header set Cache-Control "no-cache"',
					'</IfModule>',
					'',
				)
			)
		);
		// phpcs:enable
		return is_dir( $dir ) && wp_is_writable( $dir );
	}

	/**
	 * The drop-in: it loads page-cache-serve.php from this plugin, with the settings it needs.
	 */
	private static function dropin_contents(): string {
		$config = array(
			'root'    => self::dir(),
			'ttl'     => self::ttl(),
			'phones'  => self::settings()['phones'],
			'charset' => (string) get_option( 'blog_charset', 'UTF-8' ),
			'slash'   => self::trailing_slash(),
		);
		return "<?php\n"
			. "/**\n"
			. " * Arva SEO page cache. Written by the Arva SEO – Speed Booster & Security Scanner plugin; turn off\n"
			. ' * Page caching on its Speed page to remove it. ' . self::DROPIN_MARK . "\n"
			. " */\n\n"
			. "defined( 'ABSPATH' ) || exit;\n\n"
			. '$arvaseo_connector_page_cache = ' . var_export( __DIR__ . '/page-cache-serve.php', true ) . ";\n" // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- writes PHP code.
			. "if ( is_readable( \$arvaseo_connector_page_cache ) ) {\n"
			. "\tinclude_once \$arvaseo_connector_page_cache;\n"
			. "\tif ( function_exists( 'arvaseo_connector_page_cache_start' ) ) {\n"
			. "\t\tarvaseo_connector_page_cache_start( " . var_export( $config, true ) . " );\n" // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- writes PHP code.
			. "\t}\n"
			. "}\n"
			. "unset( \$arvaseo_connector_page_cache );\n";
	}

	/**
	 * PHP's opcode cache can keep the compiled copy of a file for seconds after it changes (or until
	 * it's told, on hosts that never check): tell it, so the next request runs the new wp-config.php
	 * or drop-in. The check right after turning this on depends on it.
	 *
	 * @param string $file File just written.
	 */
	public static function fresh( string $file ): void {
		if ( function_exists( 'wp_opcache_invalidate' ) ) {
			wp_opcache_invalidate( $file, true );
		} elseif ( function_exists( 'opcache_invalidate' ) ) {
			opcache_invalidate( $file, true );
		}
	}

	/**
	 * Whether the site's addresses end in a slash (/about/ rather than /about).
	 */
	private static function trailing_slash(): bool {
		$structure = (string) get_option( 'permalink_structure' );
		return '' === $structure || str_ends_with( $structure, '/' );
	}

	/**
	 * The permalink settings changed: the drop-in learns whether addresses end in a slash, and
	 * every page is cleared.
	 */
	public static function permalinks_changed(): void {
		self::write_dropin();
		self::site_changed();
	}

	/**
	 * Write the drop-in. Another plugin's, left from a cache plugin that isn't active, is kept
	 * beside it as advanced-cache.php.arvaseo-backup.
	 */
	private static function write_dropin(): bool {
		$file = self::dropin();
		if ( file_exists( $file ) && ! self::dropin_is_ours() ) {
			if ( ! copy( $file, $file . '.arvaseo-backup' ) ) {
				return false;
			}
			self::update( array( 'dropin_backup' => true ) );
		}
		$tmp = $file . '.' . wp_generate_password( 8, false ) . '.tmp';
		if ( false === file_put_contents( $tmp, self::dropin_contents() ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- the drop-in WordPress loads.
			return false;
		}
		if ( ! rename( $tmp, $file ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- replaced in one step, so WordPress never loads half a file.
			wp_delete_file( $tmp );
			return false;
		}
		self::fresh( $file );
		return self::dropin_is_ours();
	}

	/**
	 * How long a saved page is kept, in seconds.
	 */
	public static function ttl(): int {
		/**
		 * How long Page caching keeps a saved page before it's made again, in seconds.
		 *
		 * @param int $seconds 36000 (10 hours).
		 */
		return max( 300, (int) apply_filters( 'arvaseo_connector_page_cache_ttl', self::TTL ) );
	}

	// ----- wp-config.php -------------------------------------------------------------------------

	/**
	 * Turn WP_CACHE on in wp-config.php: Arva SEO's line goes right after the opening <?php, or in
	 * place of a line that sets it to false (which is put back when it's turned off).
	 */
	private static function add_config(): bool {
		$file = self::config_file();
		if ( null === $file ) {
			return false;
		}
		$contents = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file.
		if ( str_contains( $contents, self::CONFIG_LINE ) ) {
			return true;
		}
		$original = null;
		if ( preg_match( '/^[ \t]*define\(\s*[\'"]WP_CACHE[\'"]\s*,[^;]*\)\s*;[^\r\n]*/m', $contents, $m ) ) {
			$original = $m[0];
			$updated  = str_replace( $original, self::CONFIG_LINE, $contents );
		} elseif ( preg_match( '/^<\?php[^\r\n]*\R/', $contents, $m ) ) {
			$updated = $m[0] . self::CONFIG_LINE . "\n" . substr( $contents, strlen( $m[0] ) );
		} else {
			return false;
		}
		if ( false === file_put_contents( $file, $updated, LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- wp-config.php, as WordPress's installer writes it.
			return false;
		}
		self::fresh( $file );
		self::update(
			array(
				'wp_config' => array(
					'added'    => true,
					'original' => $original,
				),
			)
		);
		AuditLogger::info( 'speed.page_cache_config', __( 'Page caching: WP_CACHE was turned on in wp-config.php.', 'arvaseo-connector' ) );
		return true;
	}

	/**
	 * Take out the WP_CACHE line Arva SEO added (putting back the one it replaced). Lines anyone
	 * else wrote stay.
	 */
	private static function remove_config(): void {
		$file = self::config_file();
		if ( null === $file ) {
			return;
		}
		$contents = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file.
		if ( ! str_contains( $contents, self::CONFIG_LINE ) ) {
			return;
		}
		$original = self::settings()['wp_config']['original'] ?? null;
		$updated  = null !== $original
			? str_replace( self::CONFIG_LINE, $original, $contents )
			: (string) preg_replace( '/^' . preg_quote( self::CONFIG_LINE, '/' ) . '\R?/m', '', $contents );
		file_put_contents( $file, $updated, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- wp-config.php.
		self::fresh( $file );
	}

	// ----- .htaccess -----------------------------------------------------------------------------

	/**
	 * Whether saved pages can be sent by the server itself: Apache or LiteSpeed with mod_rewrite, a
	 * writable .htaccess, and the cache folder under the site's address.
	 */
	public static function rules_possible(): bool {
		if ( null !== ServerRules::unavailable() ) {
			return false;
		}
		if ( ! function_exists( 'got_mod_rewrite' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}
		return got_mod_rewrite() && null !== self::cache_url_path();
	}

	/**
	 * The cache folder's address path (/wp-content/cache/arvaseo), when it's on the site's host.
	 */
	private static function cache_url_path(): ?string {
		$content = wp_parse_url( content_url() );
		$home    = wp_parse_url( home_url() );
		if ( ( $content['host'] ?? '' ) !== ( $home['host'] ?? '' ) ) {
			return null;
		}
		return rtrim( (string) ( $content['path'] ?? '' ), '/' ) . '/cache/arvaseo';
	}

	/**
	 * The rules: a GET without a query string, from someone without a personal cookie, for an
	 * address ending in a slash whose saved page exists, gets that page.
	 *
	 * @return string[]
	 */
	public static function rules(): array {
		$url      = (string) self::cache_url_path();
		$dir      = self::dir();
		$phones   = self::settings()['phones'];
		$cookies  = 'wordpress_logged_in_|wordpress_sec_|wp-postpass_|comment_author_|woocommerce_items_in_cart|wp_woocommerce_session_|edd_items_in_cart|arvaseo_nocache';
		$lines    = array(
			'# Saved pages, sent without starting WordPress. Added by Arva SEO → PageSpeed fixes;',
			'# turn off "Page caching" there to remove them.',
			'<IfModule mod_rewrite.c>',
			'RewriteEngine On',
		);
		$variants = array();
		foreach ( array( true, false ) as $https ) {
			if ( $phones ) {
				$variants[] = array( $https, true );
			}
			$variants[] = array( $https, false );
		}
		foreach ( $variants as list( $https, $mobile ) ) {
			$name    = arvaseo_connector_page_cache_name( $https, $mobile );
			$lines[] = 'RewriteCond %{REQUEST_METHOD} ^(GET|HEAD)$';
			$lines[] = 'RewriteCond %{QUERY_STRING} ^$';
			$lines[] = 'RewriteCond %{HTTP:Cookie} !(' . $cookies . ') [NC]';
			$lines[] = 'RewriteCond %{HTTP:Authorization} ^$';
			$lines[] = 'RewriteCond %{HTTP_HOST} ^[a-zA-Z0-9-]+(\\.[a-zA-Z0-9-]+)*(:[0-9]+)?$';
			if ( $phones ) {
				$lines[] = 'RewriteCond %{HTTP_USER_AGENT} ' . ( $mobile ? '' : '!' ) . '(Mobile|Android|Silk/|Kindle|BlackBerry|Opera\sMini|Opera\sMobi)';
			}
			$lines[] = 'RewriteCond %{HTTPS} ' . ( $https ? '=on' : '!=on' );
			$lines[] = 'RewriteCond %{REQUEST_URI} /$';
			$lines[] = 'RewriteCond "' . $dir . '/%{HTTP_HOST}%{REQUEST_URI}' . $name . '" -f';
			$lines[] = 'RewriteRule ^ "' . $url . '/%{HTTP_HOST}%{REQUEST_URI}' . $name . '" [L]';
		}
		$lines[] = '</IfModule>';
		return $lines;
	}

	/**
	 * Whether the rules are in .htaccess.
	 */
	public static function rules_present(): bool {
		$file = ServerRules::file();
		return is_readable( $file ) && str_contains( (string) file_get_contents( $file ), '# BEGIN ' . self::MARKER ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file.
	}

	/**
	 * Write the block at the top of .htaccess (empty to take it out), after backing the file up.
	 * WordPress's own rules send every address to index.php, so these have to come first.
	 *
	 * @param string[] $lines Rules.
	 */
	private static function write_rules( array $lines ): bool {
		$file     = ServerRules::file();
		$contents = is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file.
		ServerRules::backup();
		$contents = (string) preg_replace( '/# BEGIN ' . preg_quote( self::MARKER, '/' ) . '.*?# END ' . preg_quote( self::MARKER, '/' ) . '\R*/s', '', $contents );
		if ( array() !== $lines ) {
			$contents = '# BEGIN ' . self::MARKER . "\n" . implode( "\n", $lines ) . "\n# END " . self::MARKER . "\n\n" . ltrim( $contents );
		}
		return false !== file_put_contents( $file, $contents, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- .htaccess, as insert_with_markers() writes it.
	}

	// ----- The check -----------------------------------------------------------------------------

	/**
	 * Ask for the homepage twice from the server, like a visitor: the first answer is made by
	 * WordPress and saved, the second must come from the cache and look the same.
	 *
	 * @return array{error: string|null, served_by: string|null, before: float|null, after: float|null}
	 */
	public static function check(): array {
		delete_transient( 'arvaseo_connector_page_cache_refused' );
		$url   = home_url( '/' );
		$first = self::fetch( $url );
		if ( null !== $first['error'] ) {
			return self::check_result( $first['error'] );
		}
		if ( 200 !== $first['code'] ) {
			return self::check_result( 'cache_not_saved_status' );
		}
		$second = self::fetch( $url );
		if ( null !== $second['error'] ) {
			return self::check_result( $second['error'] );
		}
		if ( ! $second['hit'] && $second['host_hit'] ) {
			// The host's own cache sent the page before WordPress (and Arva SEO's) could.
			return self::check_result( 'cache_host_first' );
		}
		if ( ! $second['hit'] ) {
			$refused = get_transient( 'arvaseo_connector_page_cache_refused' );
			return self::check_result(
				match ( $refused ) {
					'cookie'     => 'cache_not_saved_cookie',
					'no_cache'   => 'cache_not_saved_header',
					false        => 'cache_not_loaded',
					default      => 'cache_not_saved',
				}
			);
		}
		// The same page: about the same size, and complete.
		$a = strlen( $first['body'] );
		$b = strlen( $second['body'] );
		if ( $a < 200 || abs( $b - $a ) > max( 600, (int) ( $a * 0.1 ) ) || false === stripos( $second['body'], '</html>' ) ) {
			return self::check_result( 'cache_not_saved' );
		}
		return array(
			'error'     => null,
			'served_by' => 'hit' === $second['header'] ? 'php' : 'htaccess',
			'before'    => round( $first['seconds'], 3 ),
			'after'     => round( $second['seconds'], 3 ),
		);
	}

	/**
	 * A failed check.
	 *
	 * @param string $error Why.
	 * @return array{error: string|null, served_by: string|null, before: float|null, after: float|null}
	 */
	private static function check_result( string $error ): array {
		return array(
			'error'     => $error,
			'served_by' => null,
			'before'    => null,
			'after'     => null,
		);
	}

	/**
	 * One request to the site, with no cookies.
	 *
	 * @param string $url        Address.
	 * @param string $user_agent The browser it says it is.
	 * @return array{error: string|null, code: int, body: string, hit: bool, host_hit: bool, header: string, seconds: float}
	 */
	private static function fetch( string $url, string $user_agent = '' ): array {
		$start    = microtime( true );
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 20,
				'redirection' => 0,
				'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's filter for requests to the site itself.
				'user-agent'  => '' !== $user_agent ? $user_agent : 'Mozilla/5.0 (compatible; ArvaSEO-PageCache/' . ARVASEO_CONNECTOR_VERSION . '; +https://arvaseo.com/)',
				'headers'     => array( 'Accept' => 'text/html' ),
			)
		);
		$seconds  = microtime( true ) - $start;
		if ( is_wp_error( $response ) ) {
			return array(
				'error'    => 'cache_unchecked',
				'code'     => 0,
				'body'     => '',
				'hit'      => false,
				'host_hit' => false,
				'header'   => '',
				'seconds'  => $seconds,
			);
		}
		$code   = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );
		$header = strtolower( (string) wp_remote_retrieve_header( $response, 'x-arva-cache' ) );
		// Hosts' caches say so: SiteGround (x-proxy-cache), LiteSpeed, Kinsta, Varnish and WP Engine (x-cache).
		$host_hit = false;
		foreach ( array( 'x-proxy-cache', 'x-litespeed-cache', 'x-kinsta-cache', 'x-cache', 'x-varnish-cache', 'cf-cache-status' ) as $name ) {
			$host_hit = $host_hit || str_contains( strtolower( (string) wp_remote_retrieve_header( $response, $name ) ), 'hit' );
		}
		return array(
			'error'    => $code >= 500 || 0 === $code ? 'cache_server_error' : null,
			'code'     => $code,
			'body'     => $body,
			'hit'      => '' !== $header || str_contains( $body, '<!-- Arva SEO page cache' ),
			'host_hit' => $host_hit,
			'header'   => $header,
			'seconds'  => $seconds,
		);
	}

	// ----- Saving a page -----------------------------------------------------------------------

	/**
	 * The page WordPress made, at the end of the request: saved when anyone may see it.
	 *
	 * @param string                                                       $html The page.
	 * @param array{dir: string, file: string, host: string, path: string} $page Where it goes.
	 */
	public static function capture( string $html, array $page ): void {
		$refused = self::refusal( $html, $page );
		if ( null !== $refused ) {
			if ( trailingslashit( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) ) === $page['path'] ) {
				// Read by check(): why the homepage wasn't saved.
				set_transient( 'arvaseo_connector_page_cache_refused', $refused, 5 * MINUTE_IN_SECONDS );
			}
			return;
		}
		if ( ! wp_mkdir_p( $page['dir'] ) ) {
			return;
		}
		$html .= "\n<!-- Arva SEO page cache: saved " . gmdate( 'Y-m-d H:i:s' ) . " UTC -->\n";
		self::put( $page['file'], $html );
		$gzip = function_exists( 'gzencode' ) ? gzencode( $html, 6 ) : false;
		if ( is_string( $gzip ) ) {
			self::put( $page['file'] . '.gz', $gzip );
		}
	}

	/**
	 * Write a file in one step, so a visitor never gets half of it.
	 *
	 * @param string $file     File.
	 * @param string $contents Contents.
	 */
	private static function put( string $file, string $contents ): void {
		$tmp = $file . '.' . wp_generate_password( 8, false ) . '.tmp';
		// phpcs:disable WordPress.WP.AlternativeFunctions -- the plugin's own cache files.
		if ( false !== @file_put_contents( $tmp, $contents ) && ! @rename( $tmp, $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- at the end of a page: a warning would land in it.
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- as above.
		}
		// phpcs:enable
	}

	/**
	 * Why this page isn't saved, or null when it is.
	 *
	 * @param string                                                       $html The page.
	 * @param array{dir: string, file: string, host: string, path: string} $page Where it would go.
	 */
	public static function refusal( string $html, array $page ): ?string {
		if ( ! self::active() ) {
			return 'off';
		}
		if ( ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) || is_user_logged_in() ) {
			return 'personal';
		}
		$code = http_response_code();
		// On the command line there is no response code (false): only a real one other than 200 counts.
		if ( is_int( $code ) && 200 !== $code ) {
			return 'status';
		}
		foreach ( headers_list() as $header ) {
			$header = strtolower( $header );
			if ( str_starts_with( $header, 'set-cookie:' ) ) {
				return 'cookie';
			}
			if ( str_starts_with( $header, 'cache-control:' ) && preg_match( '/no-cache|no-store|private/', $header ) ) {
				return 'no_cache';
			}
			if ( str_starts_with( $header, 'content-type:' ) && ! str_contains( $header, 'text/html' ) ) {
				return 'not_html';
			}
		}
		if ( ! Runtime::is_page_request() || is_404() || is_search() || is_preview() || is_trackback() ) {
			return 'not_page';
		}
		if ( is_singular() && post_password_required( get_queried_object_id() ) ) {
			return 'personal';
		}
		if ( null !== Exclusions::page_reason( 'page_cache' ) || Exclusions::is_shop_page() ) {
			return 'excluded';
		}
		if ( self::site_host() !== $page['host'] ) {
			return 'other_host';
		}
		if ( strlen( $html ) < 200 || strlen( $html ) > 8 * MB_IN_BYTES || false === stripos( $html, '</html>' ) ) {
			return 'incomplete';
		}
		return null;
	}

	/**
	 * The site's name as the cache folder holds it (with its port, if it has one).
	 */
	private static function site_host(): ?string {
		$parts = wp_parse_url( home_url() );
		return arvaseo_connector_page_cache_host( (string) ( $parts['host'] ?? '' ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) );
	}

	// ----- Clearing ----------------------------------------------------------------------------

	/**
	 * Something visitors see on every page changed.
	 *
	 * @param string $why What changed.
	 */
	public static function pages_changed( $why = '' ): void {
		self::clear_all( is_string( $why ) ? $why : '' );
	}

	/**
	 * Something every page shows changed (theme, menus, widgets, settings): clear this cache and
	 * Cloudflare's, once in this request.
	 */
	public static function site_changed(): void {
		if ( ! self::$cleared ) {
			Fixes::pages_changed( 'site' );
		}
	}

	/**
	 * Allow clearing everything again in this request (tests, and long-running processes).
	 */
	public static function reset(): void {
		self::$cleared = false;
	}

	/**
	 * Clear every saved page, then refill them in the background.
	 *
	 * @param string $why What changed, for the log.
	 */
	public static function clear_all( string $why = '' ): void {
		if ( self::$cleared ) {
			return;
		}
		self::$cleared = true;
		foreach ( (array) glob( self::dir() . '/*', GLOB_ONLYDIR ) as $host ) {
			// Moved aside first, so no visitor gets half a folder.
			$trash = self::dir() . '/.trash-' . wp_generate_password( 8, false );
			if ( @rename( (string) $host, $trash ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- a folder moved aside.
				self::remove_tree( $trash );
			}
		}
		AuditLogger::info( 'speed.page_cache_cleared', __( 'Page caching: every saved page was cleared.', 'arvaseo-connector' ), array( 'why' => $why ) );
		self::queue_all();
	}

	/**
	 * Clear one page's saved copies. Lists also lose their next pages (/page/2/ and on).
	 *
	 * @param string $url   Address on this site.
	 * @param bool   $paged A list with pages after the first.
	 */
	public static function clear_url( string $url, bool $paged = false ): void {
		$parts = wp_parse_url( $url );
		$host  = arvaseo_connector_page_cache_host( (string) ( $parts['host'] ?? '' ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) );
		$path  = arvaseo_connector_page_cache_path( (string) ( $parts['path'] ?? '/' ) );
		if ( null === $host || null === $path ) {
			return;
		}
		$dir = self::dir() . '/' . $host . $path;
		foreach ( (array) glob( $dir . 'index*.html*' ) as $file ) {
			wp_delete_file( (string) $file );
		}
		if ( $paged ) {
			self::remove_tree( $dir . 'page' );
		}
	}

	/**
	 * A post was published, updated while published, or unpublished.
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       Post.
	 */
	public static function post_changed( $new_status, $old_status, $post ): void {
		if ( ( 'publish' !== $new_status && 'publish' !== $old_status ) || ! $post instanceof \WP_Post || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}
		if ( in_array( $post->post_type, array( 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'wp_block' ), true ) ) {
			// The Site Editor: templates, styles, menus and synced patterns show on many pages.
			self::site_changed();
			return;
		}
		if ( ! is_post_type_viewable( $post->post_type ) ) {
			return;
		}
		if ( 'publish' !== $new_status ) {
			self::clear_all( 'unpublished' );
			return;
		}
		self::clear_post( $post );
	}

	/**
	 * Clear the pages that show a post (its own, the homepage, the posts page and its archives),
	 * then refill them.
	 *
	 * @param \WP_Post $post Post.
	 */
	public static function clear_post( \WP_Post $post ): void {
		$urls = Cloudflare::post_urls( $post );
		foreach ( $urls as $url ) {
			self::clear_url( $url, get_permalink( $post ) !== $url );
		}
		self::queue_urls( $urls );
	}

	/**
	 * A comment was added, approved, edited or removed: its post's page.
	 *
	 * @param int|string $comment_id Comment ID.
	 */
	public static function comment_changed( $comment_id ): void {
		$comment = get_comment( (int) $comment_id );
		$post    = $comment instanceof \WP_Comment ? get_post( (int) $comment->comment_post_ID ) : null;
		if ( $post instanceof \WP_Post && 'publish' === $post->post_status ) {
			$url = get_permalink( $post );
			if ( is_string( $url ) ) {
				self::clear_url( $url );
				self::queue_urls( array( $url ) );
			}
		}
	}

	/**
	 * A product's stock changed (WooCommerce): its page.
	 *
	 * @param mixed $product The product.
	 */
	public static function product_changed( $product ): void {
		$id  = is_object( $product ) && method_exists( $product, 'get_id' ) ? (int) $product->get_id() : 0;
		$url = $id > 0 ? get_permalink( $id ) : false;
		if ( is_string( $url ) ) {
			self::clear_url( $url );
		}
	}

	/**
	 * Arva SEO changed a post's (or the homepage's) SEO title or description.
	 *
	 * @param int $post_id Post ID, 0 for the homepage.
	 */
	public static function seo_updated( $post_id ): void {
		$url = (int) $post_id > 0 ? get_permalink( (int) $post_id ) : home_url( '/' );
		if ( is_string( $url ) ) {
			self::clear_url( $url );
			self::queue_urls( array( $url ) );
		}
	}

	/**
	 * Delete a folder and everything in it, only ever inside the cache folder.
	 *
	 * @param string $dir Folder.
	 */
	private static function remove_tree( string $dir ): void {
		$root = realpath( self::dir() );
		$real = realpath( $dir );
		if ( false === $root || false === $real || ! str_starts_with( $real . '/', $root . '/' ) || ! is_dir( $real ) ) {
			return;
		}
		$items = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $real, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		// phpcs:disable WordPress.WP.AlternativeFunctions -- the plugin's own cache folder.
		foreach ( $items as $item ) {
			if ( $item->isDir() && ! $item->isLink() ) {
				@rmdir( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a visitor's request may be writing to it.
			} else {
				@unlink( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- as above.
			}
		}
		@rmdir( $real ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- as above.
		// phpcs:enable
	}

	// ----- Refilling -------------------------------------------------------------------------

	/**
	 * After everything was cleared: the homepage, the posts page, the latest posts and pages, and
	 * the main categories, to visit in the background.
	 */
	public static function queue_all(): void {
		if ( ! self::active() || ! self::settings()['preload'] ) {
			return;
		}
		$urls       = array( home_url( '/' ) );
		$posts_page = (int) get_option( 'page_for_posts' );
		if ( $posts_page > 0 ) {
			$urls[] = get_permalink( $posts_page );
		}
		$types = array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) );
		$ids   = get_posts(
			array(
				'post_type'    => array_values( $types ),
				'post_status'  => 'publish',
				'has_password' => false,
				'orderby'      => 'modified',
				'order'        => 'DESC',
				'numberposts'  => self::PRELOAD_MAX,
				'fields'       => 'ids',
				'meta_query'   => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- once, after the cache is cleared.
					array(
						'key'     => Exclusions::META,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);
		foreach ( $ids as $id ) {
			$urls[] = get_permalink( (int) $id );
		}
		foreach ( get_taxonomies( array( 'public' => true ), 'names' ) as $taxonomy ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'orderby'    => 'count',
					'order'      => 'DESC',
					'number'     => 20,
					'hide_empty' => true,
				)
			);
			foreach ( is_array( $terms ) ? $terms : array() as $term ) {
				$link   = get_term_link( $term );
				$urls[] = is_string( $link ) ? $link : null;
			}
		}
		update_option(
			self::PRELOAD,
			array(
				'queue' => array(),
				'total' => 0,
				'done'  => 0,
			),
			false
		);
		self::queue_urls( array_filter( $urls, 'is_string' ), false );
	}

	/**
	 * Add pages to refill, first in line unless $later.
	 *
	 * @param array<int, mixed> $urls  Addresses on this site.
	 * @param bool              $first Ahead of the others.
	 */
	public static function queue_urls( array $urls, bool $first = true ): void {
		if ( ! self::active() || ! self::settings()['preload'] ) {
			return;
		}
		$host  = wp_parse_url( home_url(), PHP_URL_HOST );
		$urls  = array_values( array_filter( $urls, static fn ( $url ) => is_string( $url ) && wp_parse_url( $url, PHP_URL_HOST ) === $host && null === wp_parse_url( $url, PHP_URL_QUERY ) ) );
		$state = self::preload_state();
		$queue = $first ? array_merge( $urls, $state['queue'] ) : array_merge( $state['queue'], $urls );
		$queue = array_slice( array_values( array_unique( $queue ) ), 0, self::PRELOAD_MAX );
		update_option(
			self::PRELOAD,
			array(
				'queue' => $queue,
				'total' => max( $state['total'], $state['done'] + count( $queue ) ),
				'done'  => $state['done'],
			),
			false
		);
		if ( array() !== $queue ) {
			\ArvaSEO\Connector\Cron\CronManager::run_soon( self::HOOK_PRELOAD, 30 );
		}
	}

	/**
	 * What's waiting to be refilled.
	 *
	 * @return array{queue: string[], total: int, done: int}
	 */
	public static function preload_state(): array {
		$saved = get_option( self::PRELOAD, array() );
		$saved = is_array( $saved ) ? $saved : array();
		return array(
			'queue' => array_values( array_filter( (array) ( $saved['queue'] ?? array() ), 'is_string' ) ),
			'total' => (int) ( $saved['total'] ?? 0 ),
			'done'  => (int) ( $saved['done'] ?? 0 ),
		);
	}

	/**
	 * The background job: visit the next few pages like a visitor (and like a phone, when phones
	 * have their own copy), so they're saved before anyone asks for them. Runs again in a minute
	 * while pages are waiting.
	 */
	public static function run_preload(): void {
		if ( ! self::active() || ! self::settings()['preload'] ) {
			return;
		}
		if ( ! Lock::acquire( 'page_cache_preload', 5 * MINUTE_IN_SECONDS ) ) {
			return;
		}
		$state  = self::preload_state();
		$batch  = array_slice( $state['queue'], 0, self::PRELOAD_BATCH );
		$phones = self::settings()['phones'];
		foreach ( $batch as $url ) {
			$https = 'https' === wp_parse_url( $url, PHP_URL_SCHEME );
			foreach ( $phones ? array( false, true ) : array( false ) as $mobile ) {
				$parts = wp_parse_url( $url );
				$host  = arvaseo_connector_page_cache_host( (string) ( $parts['host'] ?? '' ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) );
				$path  = arvaseo_connector_page_cache_path( (string) ( $parts['path'] ?? '/' ) );
				if ( null === $host || null === $path || is_file( self::dir() . '/' . $host . $path . arvaseo_connector_page_cache_name( $https, $mobile ) ) ) {
					continue;
				}
				self::fetch( $url, $mobile ? 'Mozilla/5.0 (Linux; Android 14; Mobile) ArvaSEO-PageCache/' . ARVASEO_CONNECTOR_VERSION : '' );
			}
		}
		$left = array_slice( $state['queue'], count( $batch ) );
		update_option(
			self::PRELOAD,
			array(
				'queue' => $left,
				'total' => $state['total'],
				'done'  => $state['done'] + count( $batch ),
			),
			false
		);
		Lock::release( 'page_cache_preload' );
		if ( array() !== $left ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK_PRELOAD );
		}
	}

	/**
	 * Hourly: pages past their lifetime are deleted (the server would otherwise keep sending them
	 * from .htaccess), and refilled.
	 */
	public static function gc(): void {
		if ( ! self::active() ) {
			wp_clear_scheduled_hook( self::HOOK_GC );
			return;
		}
		$dir = realpath( self::dir() );
		if ( false === $dir ) {
			return;
		}
		$expired = array();
		$scheme  = (string) wp_parse_url( home_url(), PHP_URL_SCHEME );
		$items   = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $items as $item ) {
			$name = $item->getFilename();
			$old  = $item->getMTime() + self::ttl() < time();
			if ( str_ends_with( $name, '.tmp' ) && $item->getMTime() < time() - HOUR_IN_SECONDS ) {
				wp_delete_file( $item->getPathname() );
			} elseif ( $old && preg_match( '/^index(-http)?(-mobile)?\.html(\.gz)?$/', $name ) ) {
				wp_delete_file( $item->getPathname() );
				if ( 'index.html' === $name || 'index-http.html' === $name ) {
					$relative  = substr( $item->getPath(), strlen( $dir ) + 1 );
					$expired[] = $scheme . '://' . $relative . '/';
				}
			}
		}
		self::queue_urls( array_slice( $expired, 0, self::PRELOAD_MAX ), false );
	}

	// ----- What the Speed page shows ---------------------------------------------------------

	/**
	 * Saved pages and their size (counting stops at 20,000 files).
	 *
	 * @return array{pages: int, bytes: int, capped: bool}
	 */
	public static function stats(): array {
		$dir   = realpath( self::dir() );
		$stats = array(
			'pages'  => 0,
			'bytes'  => 0,
			'capped' => false,
		);
		if ( false === $dir ) {
			return $stats;
		}
		$seen  = 0;
		$items = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $items as $item ) {
			if ( ++$seen > 20000 ) {
				$stats['capped'] = true;
				break;
			}
			if ( preg_match( '/^index(-http)?(-mobile)?\.html(\.gz)?$/', $item->getFilename() ) ) {
				$stats['bytes'] += (int) $item->getSize();
				$stats['pages'] += str_ends_with( $item->getFilename(), '.gz' ) ? 0 : 1;
			}
		}
		return $stats;
	}

	/**
	 * For diagnostics: how it's set up, without addresses or file paths.
	 *
	 * @return array<string, mixed>
	 */
	public static function diagnostics(): array {
		$settings = self::settings();
		$host     = self::host_cache();
		return array(
			'active'      => self::active(),
			'served_by'   => $settings['served_by'],
			'phones'      => $settings['phones'],
			'preload'     => $settings['preload'],
			'host_cache'  => null === $host ? null : $host['id'],
			'over_host'   => $settings['over_host'],
			'dropin_ours' => self::dropin_is_ours(),
			'wp_cache'    => self::wp_cache_on(),
			'rules'       => self::rules_present(),
		);
	}
}
