<?php
/**
 * The wp-admin screens and actions.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Admin;

use ArvaSEO\Connector\Api\ApiClient;
use ArvaSEO\Connector\Auth\AuthService;
use ArvaSEO\Connector\Auth\TokenStore;
use ArvaSEO\Connector\Config;
use ArvaSEO\Connector\Cron\CronManager;
use ArvaSEO\Connector\Cron\PollingService;
use ArvaSEO\Connector\Diagnostics\DiagnosticsService;
use ArvaSEO\Connector\Health\AssessmentService;
use ArvaSEO\Connector\Health\HealthSnapshotService;
use ArvaSEO\Connector\Install\Capabilities;
use ArvaSEO\Connector\Inventory\PluginInventoryService;
use ArvaSEO\Connector\Logging\AuditLogger;
use ArvaSEO\Connector\Privacy\PrivacyService;
use ArvaSEO\Connector\Settings;
use ArvaSEO\Connector\Speed\Cloudflare;
use ArvaSEO\Connector\Speed\Fixes;
use ArvaSEO\Connector\Site\SiteRegistrationService;

defined( 'ABSPATH' ) || exit;

/**
 * Native admin pages under "Arva SEO". Every action is a POST through admin-post.php with a nonce
 * and a capability check (the connection callback is a GET that is protected by the pairing state
 * instead). Viewing guidance never changes the site.
 */
final class AdminController {

	/**
	 * Slug => [name, capability, view, tab]. The tab is the one shown as current: the plugin list
	 * and diagnostics are pages of Settings (1.8.0), reached from its Data and privacy section.
	 */
	public const PAGES = array(
		'arvaseo-connector'   => array( 'Overview', Capabilities::VIEW_HEALTH, 'connection', 'arvaseo-connector' ),
		'arvaseo-speed'       => array( 'Speed', Capabilities::MANAGE, 'speed', 'arvaseo-speed' ),
		'arvaseo-content'     => array( 'Content', Capabilities::PUBLISH_CONTENT, 'content', 'arvaseo-content' ),
		'arvaseo-health'      => array( 'Health', Capabilities::VIEW_HEALTH, 'health', 'arvaseo-health' ),
		'arvaseo-activity'    => array( 'Activity', Capabilities::MANAGE, 'activity', 'arvaseo-activity' ),
		'arvaseo-settings'    => array( 'Settings', Capabilities::MANAGE, 'settings', 'arvaseo-settings' ),
		'arvaseo-inventory'   => array( 'Plugin list', Capabilities::SYNC_INVENTORY, 'inventory', 'arvaseo-settings' ),
		'arvaseo-diagnostics' => array( 'Diagnostics and data sharing', Capabilities::MANAGE, 'diagnostics', 'arvaseo-settings' ),
	);

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_init', array( self::class, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
		add_filter( 'option_page_capability_arvaseo_connector', static fn () => Capabilities::MANAGE );
		add_filter( 'plugin_action_links_' . plugin_basename( ARVASEO_CONNECTOR_FILE ), array( self::class, 'action_links' ) );

		add_action( 'admin_post_arvaseo_connector_connect', array( self::class, 'connect' ) );
		add_action( 'admin_post_arvaseo_connector_callback', array( self::class, 'callback' ) );
		add_action( 'admin_post_arvaseo_connector_disconnect', array( self::class, 'disconnect' ) );
		add_action( 'admin_post_arvaseo_connector_test', array( self::class, 'test' ) );
		add_action( 'admin_post_arvaseo_connector_sync_inventory', array( self::class, 'sync_inventory' ) );
		add_action( 'admin_post_arvaseo_connector_recheck', array( self::class, 'recheck' ) );
		add_action( 'admin_post_arvaseo_connector_poll_now', array( self::class, 'poll_now' ) );
		add_action( 'admin_post_arvaseo_connector_consent', array( self::class, 'consent' ) );
		add_action( 'admin_post_arvaseo_connector_consent_security', array( self::class, 'consent_security' ) );
		add_action( 'admin_post_arvaseo_connector_diagnostics', array( self::class, 'download_diagnostics' ) );
		add_action( 'admin_post_arvaseo_connector_speed', array( self::class, 'speed' ) );
		add_action( 'admin_post_arvaseo_connector_speed_options', array( self::class, 'speed_options' ) );
		add_action( 'admin_post_arvaseo_connector_webp', array( self::class, 'webp' ) );
		add_action( 'admin_post_arvaseo_connector_css', array( self::class, 'css' ) );
		add_action( 'admin_post_arvaseo_connector_cloudflare', array( self::class, 'cloudflare' ) );
		add_action( 'admin_post_arvaseo_connector_security_scan', array( self::class, 'security_scan' ) );
		add_action( 'admin_post_arvaseo_connector_security_stop', array( self::class, 'security_stop' ) );
		add_action( 'admin_post_arvaseo_connector_security_ignore', array( self::class, 'security_ignore' ) );
		add_action( 'wp_ajax_arvaseo_connector_security_progress', array( self::class, 'security_progress' ) );
		add_action( 'admin_notices', array( self::class, 'defer_notice' ) );
		add_filter( 'admin_body_class', array( self::class, 'body_class' ) );
		add_filter( 'submenu_file', array( self::class, 'submenu_file' ) );
	}

	/**
	 * The current Arva SEO page, or '' on other admin screens.
	 */
	private static function current_page(): string {
		$slug = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- page routing only.
		return isset( self::PAGES[ $slug ] ) ? $slug : '';
	}

	/**
	 * The app's ground on the plugin's pages.
	 *
	 * @param string $classes Body classes.
	 */
	public static function body_class( $classes ): string {
		return '' === self::current_page() ? (string) $classes : $classes . ' arvaseo-admin';
	}

	/**
	 * The plugin list and diagnostics highlight Settings in the menu.
	 *
	 * @param string|null $file Submenu file.
	 * @return string|null
	 */
	public static function submenu_file( $file ) {
		$page = self::current_page();
		return '' !== $page && self::PAGES[ $page ][3] !== $page ? self::PAGES[ $page ][3] : $file;
	}

	/**
	 * The menu.
	 */
	public static function menu(): void {
		add_menu_page(
			__( 'Arva SEO', 'arvaseo-connector' ),
			__( 'Arva SEO', 'arvaseo-connector' ),
			Capabilities::VIEW_HEALTH,
			'arvaseo-connector',
			array( self::class, 'render' ),
			self::menu_icon(),
			81
		);
		foreach ( self::PAGES as $slug => list( $title, $cap, $view, $tab ) ) {
			add_submenu_page( 'arvaseo-connector', self::title( $slug ), self::title( $slug ), $cap, $slug, array( self::class, 'render' ) );
		}
		add_filter( 'parent_file', static fn ( $file ) => '' !== self::current_page() ? 'arvaseo-connector' : $file );
		add_action( 'admin_head', array( self::class, 'hide_settings_pages' ) );
	}

	/**
	 * The plugin list and diagnostics have no menu item of their own: Settings links to them.
	 * They stay registered under Arva SEO until the page title is worked out (a page with no
	 * parent gets no title or open menu), and leave the menu before it's drawn.
	 */
	public static function hide_settings_pages(): void {
		foreach ( self::PAGES as $slug => $page ) {
			if ( $page[3] !== $slug ) {
				remove_submenu_page( 'arvaseo-connector', $slug );
			}
		}
	}

	/**
	 * Translated page titles.
	 *
	 * @param string $slug Page slug.
	 */
	public static function title( string $slug ): string {
		$titles = array(
			'arvaseo-connector'   => TokenStore::is_connected() ? __( 'Overview', 'arvaseo-connector' ) : __( 'Get started', 'arvaseo-connector' ),
			'arvaseo-speed'       => __( 'Speed', 'arvaseo-connector' ),
			'arvaseo-content'     => __( 'Content', 'arvaseo-connector' ),
			'arvaseo-health'      => __( 'Health', 'arvaseo-connector' ),
			'arvaseo-activity'    => __( 'Activity', 'arvaseo-connector' ),
			'arvaseo-settings'    => __( 'Settings', 'arvaseo-connector' ),
			'arvaseo-inventory'   => __( 'Plugin list', 'arvaseo-connector' ),
			'arvaseo-diagnostics' => __( 'Diagnostics and data sharing', 'arvaseo-connector' ),
		);
		return $titles[ $slug ] ?? '';
	}

	/**
	 * Render the current page.
	 */
	public static function render(): void {
		$slug = '' !== self::current_page() ? self::current_page() : 'arvaseo-connector';
		if ( ! current_user_can( self::PAGES[ $slug ][1] ) ) {
			wp_die( esc_html__( 'You don’t have permission to view this page.', 'arvaseo-connector' ), 403 );
		}
		$view = self::PAGES[ $slug ][2];
		echo '<div class="wrap arvaseo-wrap"><div class="arvaseo-app">';
		self::header( $slug );
		// WordPress puts other plugins' notices here, under the header.
		echo '<hr class="wp-header-end" hidden />';
		echo '<div class="arvaseo-page">';
		self::notice();
		self::page_head( $slug );
		include ARVASEO_CONNECTOR_DIR . '/includes/Admin/views/' . $view . '.php';
		echo '</div></div></div>';
	}

	/**
	 * The brand bar and the tabs.
	 *
	 * @param string $current Current page.
	 */
	private static function header( string $current ): void {
		$connection = TokenStore::connection();
		$connected  = TokenStore::is_connected();
		$app_url    = ! empty( $connection['app_url'] ) ? (string) $connection['app_url'] : Config::app_url();
		echo '<div class="arvaseo-top">';
		printf( '<img src="%s" alt="" width="28" height="28" />', esc_url( plugins_url( 'assets/arvaseo-icon.svg', ARVASEO_CONNECTOR_FILE ) ) );
		echo '<span class="arvaseo-brand">' . esc_html__( 'Arva SEO', 'arvaseo-connector' ) . '</span>';
		echo '<span class="arvaseo-prod">' . esc_html__( 'Speed Booster & Security Scanner', 'arvaseo-connector' ) . '</span>';
		echo '<span class="arvaseo-grow"></span>';
		if ( TokenStore::unreadable() || 'reauth_required' === ( $connection['status'] ?? '' ) ) {
			echo self::badge( 'bad', __( 'Reconnect needed', 'arvaseo-connector' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
		} elseif ( $connected ) {
			echo '<span class="arvaseo-chip arvaseo-chip--good"><span class="arvaseo-dot"></span>' . esc_html__( 'Connected to Arva SEO', 'arvaseo-connector' ) . '</span>';
		} else {
			echo self::badge( 'neutral', __( 'Not connected', 'arvaseo-connector' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
		}
		if ( $connected ) {
			printf(
				'<a class="arvaseo-btn arvaseo-btn--sm" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s %3$s<span class="screen-reader-text"> %4$s</span></a>',
				esc_url( $app_url ),
				esc_html__( 'Open Arva SEO', 'arvaseo-connector' ),
				self::icon( 'external', 14 ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG.
				esc_html__( '(opens in a new tab)', 'arvaseo-connector' )
			);
		} elseif ( current_user_can( Capabilities::MANAGE ) && 'arvaseo-connector' !== $current ) {
			printf( '<a class="arvaseo-btn arvaseo-btn--sm arvaseo-btn--pri" href="%1$s">%2$s</a>', esc_url( admin_url( 'admin.php?page=arvaseo-connector#arvaseo-connect' ) ), esc_html__( 'Connect to Arva SEO', 'arvaseo-connector' ) );
		}
		echo '</div>';

		$tab   = self::PAGES[ $current ][3];
		$needs = count( self::needs() );
		echo '<nav class="arvaseo-tabs" aria-label="' . esc_attr__( 'Arva SEO sections', 'arvaseo-connector' ) . '">';
		foreach ( self::PAGES as $slug => $page ) {
			// The tabs: each section once; Content only once content can arrive.
			if ( $page[3] !== $slug || ! current_user_can( $page[1] ) || ( 'arvaseo-content' === $slug && ! $connected ) ) {
				continue;
			}
			printf(
				'<a href="%1$s"%2$s>%3$s%4$s</a>',
				esc_url( admin_url( 'admin.php?page=' . $slug ) ),
				$slug === $tab ? ' aria-current="page"' : '',
				esc_html( self::title( $slug ) ),
				'arvaseo-connector' === $slug && $connected && $needs > 0
					/* translators: %d: number of things that need the administrator. */
					? ' <span class="arvaseo-badge-count" aria-label="' . esc_attr( sprintf( _n( '%d thing needs you', '%d things need you', $needs, 'arvaseo-connector' ), $needs ) ) . '">' . (int) $needs . '</span>'
					: ''
			);
		}
		echo '</nav>';
	}

	/**
	 * The page's icon, name and what it's for.
	 *
	 * @param string $slug Page slug.
	 */
	private static function page_head( string $slug ): void {
		if ( 'arvaseo-connector' === $slug && ! TokenStore::is_connected() && current_user_can( Capabilities::MANAGE ) ) {
			return; // Get started has its own welcome.
		}
		$heads = array(
			'arvaseo-connector'   => array( 'overview', __( 'How this website is doing, and what needs you. Nothing here changes your site until you choose to.', 'arvaseo-connector' ) ),
			'arvaseo-speed'       => array( 'gauge', __( 'Fix what Google’s PageSpeed test finds, one switch at a time. Every fix is off until you turn it on, most can be tried first where only you see them, and turning one off puts your pages back as they were. Your theme files and content are never changed; the few fixes that add files say so.', 'arvaseo-connector' ) ),
			'arvaseo-content'     => array( 'file', __( 'Posts sent from Arva SEO arrive here, and the SEO titles your team approves are written to your SEO plugin, only as far as you allow.', 'arvaseo-connector' ) ),
			'arvaseo-health'      => array( 'shield', __( 'What the security scan and the daily check found on this website, and how to fix it. Nothing here changes your site; snoozing, assigning or accepting a finding happens in Arva SEO.', 'arvaseo-connector' ) ),
			'arvaseo-activity'    => array( 'list', __( 'Everything the plugin did, and who asked. No keys, codes, content or personal data are kept.', 'arvaseo-connector' ) ),
			'arvaseo-settings'    => array( 'sliders', __( 'You decide how far Arva SEO may go on this website. Every setting starts at the safest choice.', 'arvaseo-connector' ) ),
			'arvaseo-inventory'   => array( 'list', __( 'The plugins and themes on this site, and exactly what is shared about them with Arva SEO.', 'arvaseo-connector' ) ),
			'arvaseo-diagnostics' => array( 'shield', __( 'What to share with support if something isn’t working, and what this site shares with Arva SEO.', 'arvaseo-connector' ) ),
		);
		if ( self::PAGES[ $slug ][3] !== $slug ) {
			// A page of Settings: the way back.
			printf( '<a class="arvaseo-back" href="%1$s">← %2$s</a>', esc_url( admin_url( 'admin.php?page=arvaseo-settings#arvaseo-settings-privacy' ) ), esc_html__( 'Settings', 'arvaseo-connector' ) );
		}
		printf(
			'<div class="arvaseo-head"><span class="arvaseo-tile" aria-hidden="true">%1$s</span><div><h1 class="arvaseo-h1">%2$s</h1><p class="arvaseo-lede">%3$s</p></div></div>',
			self::icon( $heads[ $slug ][0], 22 ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG.
			esc_html( self::title( $slug ) ),
			esc_html( $heads[ $slug ][1] )
		);
	}

	/**
	 * What needs the administrator, most urgent first: [tone, icon, title, text, url, link label].
	 *
	 * @return array<int, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}>
	 */
	public static function needs(): array {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			return array();
		}
		$needs    = array();
		$security = \ArvaSEO\Connector\Security\Findings::counts();
		foreach ( (array) ( AssessmentService::get()['findings'] ?? array() ) as $finding ) {
			// Known vulnerabilities from Arva SEO count with the scan's own results.
			if ( 'known_vulnerability' === ( $finding['rule_id'] ?? '' ) && 'resolved' !== $finding['finding_status'] && isset( $security[ $finding['assessed_severity'] ] ) ) {
				++$security[ $finding['assessed_severity'] ];
			}
		}
		if ( $security['critical'] > 0 || $security['high'] > 0 ) {
			$serious = $security['critical'] + $security['high'];
			$needs[] = array(
				$security['critical'] > 0 ? 'bad' : 'warn',
				'shield',
				$security['critical'] > 0
					/* translators: %d: number of security problems. */
					? sprintf( _n( '%d serious security problem', '%d serious security problems', $serious, 'arvaseo-connector' ), $serious )
					/* translators: %d: number of security problems. */
					: sprintf( _n( '%d security problem to look at', '%d security problems to look at', $serious, 'arvaseo-connector' ), $serious ),
				__( 'See what it found, why it matters and how to fix it.', 'arvaseo-connector' ),
				admin_url( 'admin.php?page=arvaseo-health#arvaseo-security' ),
				__( 'Open the results', 'arvaseo-connector' ),
			);
		}
		$connection = TokenStore::connection();
		if ( TokenStore::unreadable() || 'reauth_required' === ( $connection['status'] ?? '' ) ) {
			$needs[] = array( 'bad', 'alert', __( 'Reconnect to Arva SEO', 'arvaseo-connector' ), __( 'The connection stopped working, so nothing is sent or received until you connect again.', 'arvaseo-connector' ), admin_url( 'admin.php?page=arvaseo-connector#arvaseo-connect' ), __( 'Reconnect', 'arvaseo-connector' ) );
		}
		$cloudflare = Cloudflare::details();
		if ( Cloudflare::connected() && $cloudflare['refused'] ) {
			$needs[] = array( 'bad', 'cloud', __( 'Cloudflare refused the saved token', 'arvaseo-connector' ), __( 'Its copy of your pages isn’t cleared any more, so visitors may get old pages after a change. Connect it again with a working token.', 'arvaseo-connector' ), admin_url( 'admin.php?page=arvaseo-speed#arvaseo-cloudflare' ), __( 'Fix Cloudflare', 'arvaseo-connector' ) );
		} elseif ( Cloudflare::rocket_loader_clash() ) {
			$needs[] = array( 'warn', 'cloud', __( 'Rocket Loader is on in Cloudflare', 'arvaseo-connector' ), __( 'It changes how scripts load, as Defer scripts and Delay chat widgets do here. Use one or the other, or menus and forms may stop working.', 'arvaseo-connector' ), admin_url( 'admin.php?page=arvaseo-speed#arvaseo-cloudflare' ), __( 'Open Speed', 'arvaseo-connector' ) );
		} elseif ( Cloudflare::seen() && ! Cloudflare::connected() ) {
			$needs[] = array( 'acc', 'cloud', __( 'This website is behind Cloudflare', 'arvaseo-connector' ), __( 'Connect Cloudflare, so visitors and your next PageSpeed test get a fix as soon as you turn it on.', 'arvaseo-connector' ), admin_url( 'admin.php?page=arvaseo-speed#arvaseo-cloudflare' ), __( 'Connect Cloudflare', 'arvaseo-connector' ) );
		}
		if ( ! TokenStore::is_connected() ) {
			return $needs;
		}
		if ( Settings::all()['default_author_id'] <= 0 ) {
			$needs[] = array( 'warn', 'alert', __( 'Choose who posts content from Arva SEO', 'arvaseo-connector' ), __( 'Drafts sent from Arva SEO can’t be saved until you pick the WordPress user they’re created as.', 'arvaseo-connector' ), admin_url( 'admin.php?page=arvaseo-settings#arvaseo-settings-publishing' ), __( 'Choose an author', 'arvaseo-connector' ) );
		}
		$trying = array_filter( array_keys( Fixes::all() ), static fn ( string $id ): bool => 'trying' === Fixes::state( $id ) );
		if ( array() !== $trying ) {
			$needs[] = array( 'warn', 'eye', _n( 'You’re trying a speed fix', 'You’re trying speed fixes', count( $trying ), 'arvaseo-connector' ), implode( ', ', array_map( static fn ( string $id ): string => Fixes::all()[ $id ]['title'], $trying ) ) . '. ' . __( 'Only you see it. Check your pages, then turn it on for everyone or stop trying.', 'arvaseo-connector' ), admin_url( 'admin.php?page=arvaseo-speed' ), __( 'Open Speed', 'arvaseo-connector' ) );
		}
		$asked = self::asked_fixes();
		if ( array() !== $asked ) {
			$needs[] = array(
				'acc',
				'zap',
				/* translators: %d: number of speed fixes. */
				sprintf( _n( 'Your latest PageSpeed test asks for %d fix', 'Your latest PageSpeed test asks for %d fixes', count( $asked ), 'arvaseo-connector' ), count( $asked ) ),
				implode( ', ', array_map( static fn ( string $id ): string => Fixes::all()[ $id ]['title'], $asked ) ) . '.',
				admin_url( 'admin.php?page=arvaseo-speed#' . Fixes::anchor( $asked[0] ) ),
				_n( 'Review the fix', 'Review the fixes', count( $asked ), 'arvaseo-connector' ),
			);
		}
		$summary = HealthSnapshotService::latest()['summary'] ?? array();
		if ( (int) ( $summary['critical'] ?? 0 ) > 0 ) {
			$needs[] = array(
				'bad',
				'shield',
				/* translators: %d: number of critical Website Health results. */
				sprintf( _n( '%d critical Website Health result', '%d critical Website Health results', (int) $summary['critical'], 'arvaseo-connector' ), (int) $summary['critical'] ),
				__( 'See what’s wrong and how to fix it.', 'arvaseo-connector' ),
				admin_url( 'admin.php?page=arvaseo-health' ),
				__( 'Open Health', 'arvaseo-connector' ),
			);
		}
		return $needs;
	}

	/**
	 * The fixes the latest PageSpeed test asks for that are still off, in the test's order.
	 *
	 * @return string[]
	 */
	public static function asked_fixes(): array {
		$test  = AssessmentService::pagespeed_result();
		$asked = array();
		foreach ( (array) ( $test['audits'] ?? array() ) as $audit ) {
			foreach ( Fixes::for_audit( (string) ( $audit['id'] ?? '' ) ) as $id ) {
				if ( 'off' === Fixes::state( $id ) && ! in_array( $id, $asked, true ) ) {
					$asked[] = $id;
				}
			}
		}
		return $asked;
	}

	/**
	 * One of the interface's icons (stroke SVG, decorative).
	 *
	 * @param string $name Icon name.
	 * @param int    $size Pixels.
	 */
	public static function icon( string $name, int $size = 20 ): string {
		$paths = array(
			'overview' => '<rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/>',
			'gauge'    => '<path d="m12 14 4-4"/><path d="M3.34 19a10 10 0 1 1 17.32 0"/>',
			'file'     => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/>',
			'shield'   => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>',
			'list'     => '<path d="M3 12h.01"/><path d="M3 18h.01"/><path d="M3 6h.01"/><path d="M8 12h13"/><path d="M8 18h13"/><path d="M8 6h13"/>',
			'sliders'  => '<path d="M21 4h-7"/><path d="M10 4H3"/><path d="M21 12h-9"/><path d="M8 12H3"/><path d="M21 20h-5"/><path d="M12 20H3"/><path d="M14 2v4"/><path d="M8 10v4"/><path d="M16 18v4"/>',
			'external' => '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
			'alert'    => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 2-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
			'zap'      => '<path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/>',
			'eye'      => '<path d="M2.06 12.35a1 1 0 0 1 0-.7 10.75 10.75 0 0 1 19.88 0 1 1 0 0 1 0 .7 10.75 10.75 0 0 1-19.88 0"/><circle cx="12" cy="12" r="3"/>',
			'check'    => '<path d="M20 6 9 17l-5-5"/>',
			'arrow'    => '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
			'cloud'    => '<path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"/>',
			'pencil'   => '<path d="M12 20h9"/><path d="M16.38 3.62a1 1 0 0 1 3 3L7.37 18.64a2 2 0 0 1-.85.5l-2.87.84a.5.5 0 0 1-.62-.62l.84-2.87a2 2 0 0 1 .5-.85z"/>',
		);
		return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="' . ( $size <= 16 ? '2.25' : '1.85' ) . '" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . ( $paths[ $name ] ?? '' ) . '</svg>';
	}

	/**
	 * A PageSpeed score ring (decorative; the score is also in the text beside it).
	 *
	 * @param int  $score 0–100.
	 * @param bool $small The smaller ring.
	 */
	public static function ring( int $score, bool $small = false ): string {
		$size  = $small ? 76 : 84;
		$r     = $small ? 32 : 35;
		$width = $small ? 8 : 9;
		$c     = 2 * M_PI * $r;
		$fill  = $c * max( 0, min( 100, $score ) ) / 100;
		$color = $score >= 90 ? '#5e7f23' : ( $score >= 50 ? '#c78b00' : '#b42318' );
		return sprintf(
			'<span class="arvaseo-ring%1$s"><svg width="%2$d" height="%2$d" viewBox="0 0 %2$d %2$d" aria-hidden="true" focusable="false"><circle cx="%3$d" cy="%3$d" r="%4$d" fill="none" stroke="#efece3" stroke-width="%5$d"/><circle cx="%3$d" cy="%3$d" r="%4$d" fill="none" stroke="%6$s" stroke-width="%5$d" stroke-linecap="round" stroke-dasharray="%7$.1f %8$.1f" transform="rotate(-90 %3$d %3$d)"/></svg><b>%9$d</b></span>',
			$small ? ' arvaseo-ring--sm' : '',
			$size,
			$size / 2,
			$r,
			$width,
			$color,
			$fill,
			$c,
			$score
		);
	}

	/**
	 * Result notices after actions (codes only in the URL, never data).
	 */
	private static function notice(): void {
		$code = isset( $_GET['arvaseo_notice'] ) ? sanitize_key( wp_unslash( $_GET['arvaseo_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$map  = array(
			'connected'                      => array( 'success', __( 'Connected to Arva SEO. The first plugin inventory and health check are queued and will run in the background.', 'arvaseo-connector' ) ),
			'connected_registration_pending' => array( 'warning', __( 'Connected to Arva SEO, but the site’s details couldn’t be sent yet. They will be retried automatically.', 'arvaseo-connector' ) ),
			'cancelled'                      => array( 'info', __( 'Connecting was cancelled. Nothing was changed.', 'arvaseo-connector' ) ),
			'state_mismatch'                 => array( 'error', __( 'That connection reply didn’t match a connection started from this site by you, or it expired after 10 minutes. Start again.', 'arvaseo-connector' ) ),
			'invalid_code'                   => array( 'error', __( 'Arva SEO returned an invalid connection code. Start again.', 'arvaseo-connector' ) ),
			'exchange_failed'                => array( 'error', __( 'Arva SEO didn’t accept the connection (the code may have expired or been used). Start again; if it keeps happening, Activity shows what went wrong.', 'arvaseo-connector' ) ),
			'disconnected'                   => array( 'success', __( 'Disconnected. The credentials were removed from this site and revoked at Arva SEO, and synchronisation stopped.', 'arvaseo-connector' ) ),
			'test_ok'                        => array( 'success', __( 'The connection works.', 'arvaseo-connector' ) ),
			'test_failed'                    => array( 'error', __( 'The connection test failed. Activity shows what went wrong.', 'arvaseo-connector' ) ),
			'inventory_queued'               => array( 'success', __( 'Plugin inventory sync finished. See the result below.', 'arvaseo-connector' ) ),
			'recheck_queued'                 => array( 'success', __( 'Website recheck queued. Checks run in the background and can take a minute; refresh this page to see the results.', 'arvaseo-connector' ) ),
			'poll_done'                      => array( 'success', __( 'Checked Arva SEO for content.', 'arvaseo-connector' ) ),
			'consent_saved'                  => array( 'success', __( 'Your data-sharing choice was saved.', 'arvaseo-connector' ) ),
			'consent_required'               => array( 'error', __( 'Tick the box to agree to share this data, or connect without it.', 'arvaseo-connector' ) ),
			'speed_trying'                   => array( 'success', __( 'Trying it: only you see the change while you’re signed in. Open your website to check it, then turn it on.', 'arvaseo-connector' ) ),
			'speed_on'                       => array( 'success', __( 'Turned on for every visitor. Run a new PageSpeed test in Arva SEO to see the change. If you use a page cache, clear it.', 'arvaseo-connector' ) ),
			'speed_off'                      => array( 'success', __( 'Turned off. Your pages are back to how they were. If you use a page cache, clear it.', 'arvaseo-connector' ) ),
			'speed_options_saved'            => array( 'success', __( 'Your choices were saved.', 'arvaseo-connector' ) ),
			'speed_invalid'                  => array( 'error', __( 'That fix or setting doesn’t exist.', 'arvaseo-connector' ) ),
			'speed_handled'                  => array( 'error', __( 'Another speed plugin already does this. Use its setting instead.', 'arvaseo-connector' ) ),
			'speed_unavailable'              => array( 'error', __( 'This fix isn’t available on this server.', 'arvaseo-connector' ) ),
			'speed_try_first'                => array( 'error', __( 'Try Defer scripts first, check your important pages, then turn it on.', 'arvaseo-connector' ) ),
			'speed_check_pages'              => array( 'error', __( 'Tick each page you checked while trying Defer scripts before you turn it on.', 'arvaseo-connector' ) ),
			'speed_css_queued'               => array( 'success', __( 'Your homepage and main page types were sent to Arva SEO. Their optimized CSS is usually ready within a few minutes; other page types follow as they’re visited.', 'arvaseo-connector' ) ),
			'speed_css_regenerate'           => array( 'success', __( 'The optimized CSS will be worked out again, each page type using a page optimization.', 'arvaseo-connector' ) ),
			'speed_css_ready'                => array( 'success', __( 'New optimized CSS is ready.', 'arvaseo-connector' ) ),
			'speed_css_waiting'              => array( 'success', __( 'Nothing new yet. Arva SEO is still working on it; check again in a minute.', 'arvaseo-connector' ) ),
			'speed_webp_started'             => array( 'success', __( 'Converting your existing images in the background. You can leave this page; come back to see how far it got.', 'arvaseo-connector' ) ),
			'speed_webp_stopped'             => array( 'success', __( 'Stopped. Images converted so far keep their WebP copies; Continue picks up where it stopped.', 'arvaseo-connector' ) ),
			'speed_webp_undone'              => array( 'success', __( 'The WebP copies of your existing images were removed. Pages show the originals again.', 'arvaseo-connector' ) ),
			'speed_webp_off'                 => array( 'error', __( 'Turn on New uploads as WebP first.', 'arvaseo-connector' ) ),
			'speed_not_written'              => array( 'error', __( 'WordPress couldn’t write to your .htaccess file, so nothing changed. Ask your host to make it writable.', 'arvaseo-connector' ) ),
			'speed_unchecked'                => array( 'error', __( 'Your site couldn’t be checked from your server (some hosts block this), so the rules were taken out again to be safe. Nothing changed. Your host can add them for you: they’re listed on the card.', 'arvaseo-connector' ) ),
			'speed_server_error'             => array( 'error', __( 'Your server answered with an error once the rules were in, so they were taken out straight away. Your site is as before. Your host can say which rule it doesn’t allow.', 'arvaseo-connector' ) ),
			'cloudflare_connected'           => array( 'success', __( 'Cloudflare is connected. From now on its copy of your pages is cleared whenever a change here affects what visitors get.', 'arvaseo-connector' ) ),
			'cloudflare_token_format'        => array( 'error', __( 'That doesn’t look like a Cloudflare API token. Copy the token Cloudflare showed when you created it (not the Global API Key).', 'arvaseo-connector' ) ),
			'cloudflare_token_refused'       => array( 'error', __( 'Cloudflare didn’t accept that token. Check that it’s active and has Zone: Read and Cache Purge for this website’s domain.', 'arvaseo-connector' ) ),
			'cloudflare_zone_missing'        => array( 'error', __( 'The token works, but it can’t see this website’s domain. Give it access to the zone (Zone Resources) for this domain, or check that the domain is on Cloudflare.', 'arvaseo-connector' ) ),
			'cloudflare_unreachable'         => array( 'error', __( 'Cloudflare couldn’t be reached from your server. Try again in a minute; if it keeps happening, your host may block outgoing connections.', 'arvaseo-connector' ) ),
			'cloudflare_cleared'             => array( 'success', __( 'Cloudflare’s copy of every page was cleared. Visitors get the current pages from now on.', 'arvaseo-connector' ) ),
			'cloudflare_clear_failed'        => array( 'error', __( 'Cloudflare didn’t clear its copy. The card below says what it answered.', 'arvaseo-connector' ) ),
			'cloudflare_checked'             => array( 'success', __( 'Checked with Cloudflare.', 'arvaseo-connector' ) ),
			'cloudflare_saved'               => array( 'success', __( 'Your Cloudflare choice was saved.', 'arvaseo-connector' ) ),
			'cloudflare_disconnected'        => array( 'success', __( 'Cloudflare is disconnected and its token was removed from this site. Nothing is cleared from now on.', 'arvaseo-connector' ) ),
			'security_started'               => array( 'success', __( 'The security scan started. It runs in the background in small batches; you can leave this page and come back.', 'arvaseo-connector' ) ),
			'security_running'               => array( 'info', __( 'A security scan is already running.', 'arvaseo-connector' ) ),
			'security_stopped'               => array( 'success', __( 'The security scan stopped. The results of the previous scan are still shown.', 'arvaseo-connector' ) ),
			'security_ignored'               => array( 'success', __( 'Marked as expected. It’s hidden from now on, unless the file changes.', 'arvaseo-connector' ) ),
			'security_unignored'             => array( 'success', __( 'It’s shown again.', 'arvaseo-connector' ) ),
		);
		if ( isset( $map[ $code ] ) ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $map[ $code ][0] ), esc_html( $map[ $code ][1] ) );
		}
		if ( in_array( $code, array( 'speed_on', 'speed_off', 'speed_options_saved', 'speed_css_ready', 'speed_css_regenerate', 'speed_webp_undone' ), true ) ) {
			self::cloudflare_notice();
		}
		if ( TokenStore::unreadable() ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The saved Arva SEO credentials can’t be read (the site’s security keys or ARVASEO_CONNECTOR_ENCRYPTION_KEY changed). Disconnect and connect again.', 'arvaseo-connector' ) . '</p></div>';
		}
	}

	// ----- Actions ------------------------------------------------------------------------

	/**
	 * Start connecting.
	 */
	public static function connect(): void {
		self::guard( 'arvaseo_connector_connect', Capabilities::MANAGE );
		$share   = ! empty( $_POST['share_health'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
		$agree   = ! empty( $_POST['consent'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
		$content = ! empty( $_POST['allow_content'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
		$secure  = ! empty( $_POST['share_security'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
		if ( ( $share || $secure ) && ! $agree ) {
			self::back( 'arvaseo-connector', 'consent_required' );
		}
		wp_redirect( AuthService::start( $share, $content, $secure ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- the fixed Arva SEO app address from Config.
		exit;
	}

	/**
	 * Back from Arva SEO.
	 */
	public static function callback(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You don’t have permission to connect this site.', 'arvaseo-connector' ), 403 );
		}
		// The pairing state (single-use, bound to this administrator) protects this request.
		$query = array();
		foreach ( array( 'code', 'state', 'error' ) as $key ) {
			if ( isset( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- protected by the pairing state.
				$query[ $key ] = sanitize_text_field( wp_unslash( $_GET[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- protected by the pairing state.
			}
		}
		$result = AuthService::callback( $query );
		self::back( 'arvaseo-connector', $result['notice'] );
	}

	/**
	 * Disconnect.
	 */
	public static function disconnect(): void {
		self::guard( 'arvaseo_connector_disconnect', Capabilities::MANAGE );
		AuthService::disconnect();
		self::back( 'arvaseo-connector', 'disconnected' );
	}

	/**
	 * Test the connection.
	 */
	public static function test(): void {
		self::guard( 'arvaseo_connector_test', Capabilities::MANAGE );
		$response = ApiClient::instance()->site( 'GET', '' );
		if ( $response->ok() ) {
			SiteRegistrationService::register();
		}
		AuditLogger::info(
			'connection.tested',
			__( 'Connection tested.', 'arvaseo-connector' ),
			array(
				'http_status' => $response->status,
				'code'        => $response->error_code,
			)
		);
		self::back( 'arvaseo-connector', $response->ok() ? 'test_ok' : 'test_failed' );
	}

	/**
	 * Sync the inventory now.
	 */
	public static function sync_inventory(): void {
		self::guard( 'arvaseo_connector_sync_inventory', Capabilities::SYNC_INVENTORY );
		PluginInventoryService::sync( true );
		self::back( 'arvaseo-inventory', 'inventory_queued' );
	}

	/**
	 * Start a security scan.
	 */
	public static function security_scan(): void {
		self::guard( 'arvaseo_connector_security_scan', Capabilities::MANAGE );
		self::back( 'arvaseo-health', \ArvaSEO\Connector\Security\Scanner::start( 'manual' ) ? 'security_started' : 'security_running' );
	}

	/**
	 * Stop the security scan.
	 */
	public static function security_stop(): void {
		self::guard( 'arvaseo_connector_security_stop', Capabilities::MANAGE );
		\ArvaSEO\Connector\Security\Scanner::stop();
		self::back( 'arvaseo-health', 'security_stopped' );
	}

	/**
	 * Mark a security finding as expected, or show it again.
	 */
	public static function security_ignore(): void {
		self::guard( 'arvaseo_connector_security_ignore', Capabilities::MANAGE );
		$key    = isset( $_POST['finding'] ) ? sanitize_key( wp_unslash( $_POST['finding'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
		$ignore = empty( $_POST['show'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
		\ArvaSEO\Connector\Security\Findings::ignore( $key, $ignore );
		\ArvaSEO\Connector\Security\Report::send();
		AuditLogger::info( $ignore ? 'security.finding_ignored' : 'security.finding_shown', $ignore ? __( 'A security finding was marked as expected.', 'arvaseo-connector' ) : __( 'A security finding is shown again.', 'arvaseo-connector' ) );
		self::back( 'arvaseo-health', $ignore ? 'security_ignored' : 'security_unignored' );
	}

	/**
	 * The scan's progress for the Health page (it also runs a batch when WP-Cron is slow).
	 */
	public static function security_progress(): void {
		check_ajax_referer( 'arvaseo_connector_security_progress' );
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_send_json_error( null, 403 );
		}
		\ArvaSEO\Connector\Security\Scanner::tick();
		$progress = \ArvaSEO\Connector\Security\Scanner::progress();
		wp_send_json_success(
			array(
				'running' => 'running' === ( $progress['status'] ?? '' ),
				'files'   => (int) ( $progress['files'] ?? 0 ),
				'step'    => \ArvaSEO\Connector\Security\Scanner::step_label( (string) ( $progress['step'] ?? '' ) ),
			)
		);
	}

	/**
	 * Recheck the website (runs in the background).
	 */
	public static function recheck(): void {
		self::guard( 'arvaseo_connector_recheck', Capabilities::SYNC_HEALTH );
		CronManager::run_soon( 'arvaseo_connector_health', 0 );
		AuditLogger::info( 'health.recheck_requested', __( 'Website recheck requested.', 'arvaseo-connector' ) );
		self::back( 'arvaseo-health', 'recheck_queued' );
	}

	/**
	 * Check for content now.
	 */
	public static function poll_now(): void {
		self::guard( 'arvaseo_connector_poll_now', Capabilities::PUBLISH_CONTENT );
		PollingService::poll();
		self::back( 'arvaseo-content', 'poll_done' );
	}

	/**
	 * Give or withdraw consent.
	 */
	public static function consent(): void {
		self::guard( 'arvaseo_connector_consent', Capabilities::MANAGE );
		$give = ! empty( $_POST['consent'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
		if ( $give ) {
			PrivacyService::consent( array( 'inventory', 'health' ) );
			AuditLogger::info( 'consent.given', __( 'Consent to share plugin inventory and Website Health given.', 'arvaseo-connector' ) );
		} else {
			PrivacyService::withdraw();
			AuditLogger::info( 'consent.withdrawn', __( 'Consent withdrawn: no inventory or health data will be sent.', 'arvaseo-connector' ) );
		}
		SiteRegistrationService::register();
		self::back( 'arvaseo-diagnostics', 'consent_saved' );
	}

	/**
	 * Agree (or stop agreeing) to share the security scan's results.
	 */
	public static function consent_security(): void {
		self::guard( 'arvaseo_connector_consent_security', Capabilities::MANAGE );
		$give = ! empty( $_POST['consent'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
		$back = isset( $_POST['back'] ) && 'health' === $_POST['back'] ? 'arvaseo-health' : 'arvaseo-diagnostics'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
		if ( $give && null === PrivacyService::current() ) {
			self::back( $back, 'consent_required' );
		}
		PrivacyService::consent_security( $give );
		AuditLogger::info( $give ? 'consent.security_given' : 'consent.security_withdrawn', $give ? __( 'Consent to share the security scan’s results given.', 'arvaseo-connector' ) : __( 'Consent to share the security scan’s results withdrawn.', 'arvaseo-connector' ) );
		SiteRegistrationService::register();
		if ( $give ) {
			\ArvaSEO\Connector\Security\Report::send();
		}
		self::back( $back, 'consent_saved' );
	}

	/**
	 * Download diagnostics.
	 */
	public static function download_diagnostics(): void {
		self::guard( 'arvaseo_connector_diagnostics', Capabilities::MANAGE );
		AuditLogger::info( 'diagnostics.exported', __( 'Diagnostics downloaded.', 'arvaseo-connector' ) );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="arvaseo-connector-diagnostics-' . gmdate( 'Ymd-His' ) . '.json"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo DiagnosticsService::json(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download, built from an allowlist.
		exit;
	}

	// ----- PageSpeed fixes -------------------------------------------------------------------

	/**
	 * Try, turn on or turn off a PageSpeed fix.
	 */
	public static function speed(): void {
		self::guard( 'arvaseo_connector_speed', Capabilities::MANAGE );
		$fix     = isset( $_POST['fix'] ) ? sanitize_key( wp_unslash( $_POST['fix'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
		$to      = isset( $_POST['to'] ) ? sanitize_key( wp_unslash( $_POST['to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
		$pages   = isset( $_POST['checked'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['checked'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
		$checked = array() === array_diff( array( 'home', 'forms', 'menus' ), $pages );
		$error   = Fixes::set( $fix, $to, $checked );
		self::back_to_fix( $fix, null === $error ? 'speed_' . $to : 'speed_' . $error );
	}

	/**
	 * Save a card's choices: the extras to remove, what Defer scripts leaves alone, or the
	 * pages, images and frames every fix leaves alone.
	 */
	public static function speed_options(): void {
		self::guard( 'arvaseo_connector_speed_options', Capabilities::MANAGE );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in guard().
		$fix  = isset( $_POST['fix'] ) ? sanitize_key( wp_unslash( $_POST['fix'] ) ) : 'wp_extras';
		$list = static fn ( string $key ): array => isset( $_POST[ $key ] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST[ $key ] ) ) : array();
		$text = static fn ( string $key ): string => isset( $_POST[ $key ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) ) : '';
		if ( 'defer_scripts' === $fix ) {
			$exclude = $text( 'defer_exclude' );
			if ( isset( $_POST['leave_alone'] ) ) {
				// "Leave alone" next to a script it deferred: that one is added to the list.
				$exclude .= "\n" . sanitize_key( wp_unslash( $_POST['leave_alone'] ) );
			}
			$choices = array(
				'defer_exclude' => $exclude,
				'defer_plugins' => $list( 'defer_plugins' ),
			);
		} elseif ( 'remote' === $fix ) {
			$choices = array( 'remote' => ! empty( $_POST['remote'] ) );
		} elseif ( 'critical_css' === $fix || 'unused_css' === $fix ) {
			$choices = array( 'css_exclude' => $text( 'css_exclude' ) );
		} elseif ( 'delay_scripts' === $fix ) {
			$choices = array( 'delay_scripts' => $text( 'delay_scripts' ) );
		} elseif ( 'google_fonts' === $fix ) {
			$choices = array( 'fonts_local' => ! empty( $_POST['fonts_local'] ) );
		} elseif ( 'exclusions' === $fix ) {
			$choices = array(
				'exclude_pages'    => $text( 'exclude_pages' ),
				'exclude_elements' => $text( 'exclude_elements' ),
				'eager_images'     => isset( $_POST['eager_images'] ) ? absint( $_POST['eager_images'] ) : Fixes::EAGER_IMAGES,
			);
			if ( isset( $_POST['shop_pages_shown'] ) ) {
				$choices['shop_pages'] = ! empty( $_POST['shop_pages'] );
			}
		} else {
			$fix     = 'wp_extras';
			$choices = array( 'extras' => array_fill_keys( $list( 'extras' ), true ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		Fixes::save_choices( $choices );
		self::back_to_fix( $fix, 'speed_options_saved' );
	}

	/**
	 * Optimized CSS: work out the main page types now, regenerate every one, or check for results.
	 */
	public static function css(): void {
		self::guard( 'arvaseo_connector_css', Capabilities::MANAGE );
		$do  = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
		$fix = isset( $_POST['fix'] ) ? sanitize_key( wp_unslash( $_POST['fix'] ) ) : 'unused_css'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
		if ( 'regenerate' === $do ) {
			\ArvaSEO\Connector\Speed\CssOptimizer::forget_all();
			\ArvaSEO\Connector\Speed\CssOptimizer::queue_samples();
			$notice = 'speed_css_regenerate';
		} elseif ( 'check' === $do ) {
			$ready  = \ArvaSEO\Connector\Speed\CssOptimizer::sync()['ready'];
			$notice = $ready > 0 ? 'speed_css_ready' : 'speed_css_waiting';
		} else {
			\ArvaSEO\Connector\Speed\CssOptimizer::queue_samples();
			\ArvaSEO\Connector\Speed\CssOptimizer::sync();
			$notice = 'speed_css_queued';
		}
		self::back_to_fix( $fix, $notice );
	}

	/**
	 * Convert existing images to WebP: start or continue, stop, or undo.
	 */
	public static function webp(): void {
		self::guard( 'arvaseo_connector_webp', Capabilities::MANAGE );
		$do = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
		if ( 'stop' === $do ) {
			\ArvaSEO\Connector\Speed\WebpLibrary::stop();
			$notice = 'speed_webp_stopped';
		} elseif ( 'undo' === $do ) {
			\ArvaSEO\Connector\Speed\WebpLibrary::undo();
			$notice = 'speed_webp_undone';
		} else {
			$notice = \ArvaSEO\Connector\Speed\WebpLibrary::start() ? 'speed_webp_started' : 'speed_webp_off';
		}
		self::back_to_fix( 'webp_uploads', $notice );
	}

	/**
	 * Cloudflare: connect with a token, clear its copy, check again, choose, disconnect.
	 */
	public static function cloudflare(): void {
		self::guard( 'arvaseo_connector_cloudflare', Capabilities::MANAGE );
		$do = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
		if ( 'connect' === $do ) {
			$token  = isset( $_POST['cloudflare_token'] ) ? sanitize_text_field( wp_unslash( $_POST['cloudflare_token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
			$error  = Cloudflare::connect( $token );
			$notice = null === $error ? 'cloudflare_connected' : 'cloudflare_' . $error;
		} elseif ( 'purge' === $do ) {
			$notice = Cloudflare::purge_everything( 'manual' ) ? 'cloudflare_cleared' : 'cloudflare_clear_failed';
		} elseif ( 'check' === $do ) {
			Cloudflare::check();
			$notice = 'cloudflare_checked';
		} elseif ( 'options' === $do ) {
			Cloudflare::set_posts( ! empty( $_POST['clear_posts'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
			$notice = 'cloudflare_saved';
		} elseif ( 'disconnect' === $do ) {
			Cloudflare::disconnect();
			$notice = 'cloudflare_disconnected';
		} else {
			$notice = 'speed_invalid';
		}
		wp_safe_redirect( add_query_arg( 'arvaseo_notice', $notice, admin_url( 'admin.php?page=arvaseo-speed' ) ) . '#arvaseo-cloudflare' );
		exit;
	}

	/**
	 * After a change on Speed: what Cloudflare did with its copy of the pages.
	 */
	private static function cloudflare_notice(): void {
		if ( ! Cloudflare::connected() ) {
			return;
		}
		$details = Cloudflare::details();
		$last    = $details['last'];
		if ( $details['everything'] ) {
			$message = array( 'info', __( 'Cloudflare’s copy of your pages is cleared within a minute.', 'arvaseo-connector' ) );
		} elseif ( null !== $last && 'everything' === $last['what'] && strtotime( $last['at'] ) > time() - 2 * MINUTE_IN_SECONDS ) {
			$message = $last['ok']
				? array( 'success', __( 'Cloudflare’s copy of your pages was cleared too, so visitors get the change straight away.', 'arvaseo-connector' ) )
				: array( 'error', __( 'Cloudflare’s copy of your pages couldn’t be cleared. The Cloudflare card below says why; you can clear it there.', 'arvaseo-connector' ) );
		} else {
			return;
		}
		printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $message[0] ), esc_html( $message[1] ) );
	}

	/**
	 * For a week after Defer scripts goes on: where to turn it off if something stopped working.
	 */
	public static function defer_notice(): void {
		$on_at = Fixes::stored()['defer_on_at'];
		if ( ! current_user_can( Capabilities::MANAGE ) || 'on' !== Fixes::state( 'defer_scripts' ) || null === $on_at || $on_at < time() - WEEK_IN_SECONDS ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
			esc_html__( 'Arva SEO: Defer scripts is on. If something stopped working on your site, such as a menu, slider, form or checkout, turn it off or leave that script alone.', 'arvaseo-connector' ),
			esc_url( admin_url( 'admin.php?page=arvaseo-speed#' . Fixes::anchor( 'defer_scripts' ) ) ),
			esc_html__( 'Speed', 'arvaseo-connector' )
		);
	}

	/**
	 * Back to a fix's card with a notice code.
	 *
	 * @param string $fix    Fix ID.
	 * @param string $notice Notice code.
	 */
	private static function back_to_fix( string $fix, string $notice ): void {
		$anchor = isset( Fixes::all()[ $fix ] ) ? '#' . Fixes::anchor( $fix ) : ( 'exclusions' === $fix ? '#arvaseo-speed-exclusions' : ( 'remote' === $fix ? '#arvaseo-speed-remote' : '' ) );
		wp_safe_redirect( add_query_arg( 'arvaseo_notice', $notice, admin_url( 'admin.php?page=arvaseo-speed' ) ) . $anchor );
		exit;
	}

	// ----- Settings -----------------------------------------------------------------------

	/**
	 * Settings API registration.
	 */
	public static function register_settings(): void {
		register_setting(
			'arvaseo_connector',
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
				'default'           => Settings::defaults(),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Styles and the small script (only on our pages).
	 *
	 * @param string $hook Screen hook.
	 */
	public static function assets( string $hook ): void {
		if ( ! str_contains( $hook, 'arvaseo' ) ) {
			return;
		}
		wp_enqueue_style( 'arvaseo-connector-admin', plugins_url( 'assets/admin.css', ARVASEO_CONNECTOR_FILE ), array(), ARVASEO_CONNECTOR_VERSION );
		wp_enqueue_script( 'arvaseo-connector-admin', plugins_url( 'assets/admin.js', ARVASEO_CONNECTOR_FILE ), array(), ARVASEO_CONNECTOR_VERSION, true );
		wp_localize_script(
			'arvaseo-connector-admin',
			'arvaseoConnector',
			array(
				'copied'               => __( 'Copied.', 'arvaseo-connector' ),
				'confirmDisconnect'    => __( 'Disconnect this site from Arva SEO? Credentials are removed and revoked, and nothing more is sent or received until you connect again.', 'arvaseo-connector' ),
				'confirmFull'          => __( 'Full publishing lets Arva SEO users publish posts on this site immediately, within the default author’s permissions. Continue?', 'arvaseo-connector' ),
				'confirmOverwrite'     => __( 'With this on, content sent again from Arva SEO replaces edits made here in WordPress (a revision is kept). Continue?', 'arvaseo-connector' ),
				'confirmSeoUpdates'    => __( 'With this on, Arva SEO users can change the SEO title and meta description of published posts, pages and products on this site. Each change can be undone from Arva SEO. Continue?', 'arvaseo-connector' ),
				'confirmDefer'         => __( 'Turn on Defer scripts for every visitor? It changes how scripts load, and some menus, sliders, forms, chat widgets or checkouts may stop working. If anything does, turn it off on this page or leave that script alone. Continue?', 'arvaseo-connector' ),
				'confirmRisky'         => __( 'This changes how parts of your pages load for every visitor. Check your important pages afterwards, and turn it off here if anything looks wrong. Continue?', 'arvaseo-connector' ),
				'confirmCssRegenerate' => __( 'Work out the optimized CSS of every page type again? Each one uses a page optimization of this month’s allowance.', 'arvaseo-connector' ),
				'confirmWebpUndo'      => __( 'Remove the WebP copies of your existing images? Pages show the originals again; you can convert them again later.', 'arvaseo-connector' ),
				'confirmCloudflareOff' => __( 'Disconnect Cloudflare? Its token is removed from this site, and its copy of your pages is no longer cleared when something changes here.', 'arvaseo-connector' ),
				'ajaxUrl'              => admin_url( 'admin-ajax.php' ),
				'securityNonce'        => wp_create_nonce( 'arvaseo_connector_security_progress' ),
				/* translators: 1: what the scan is checking, 2: number of files. */
				'securityProgress'     => __( '%1$s · %2$s files checked so far', 'arvaseo-connector' ),
			)
		);
	}

	/**
	 * "Settings" link on the Plugins screen.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public static function action_links( array $links ): array {
		if ( current_user_can( Capabilities::MANAGE ) ) {
			array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=arvaseo-speed' ) ) . '">' . esc_html__( 'Speed', 'arvaseo-connector' ) . '</a>' );
			array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=arvaseo-connector' ) ) . '">' . esc_html( self::title( 'arvaseo-connector' ) ) . '</a>' );
		}
		return $links;
	}

	// ----- Helpers ------------------------------------------------------------------------

	/**
	 * A nonce'd POST form button.
	 *
	 * @param string $action  admin-post action.
	 * @param string $label   Button label.
	 * @param string $css_class Button classes.
	 * @param string $confirm data-confirm key.
	 */
	public static function button( string $action, string $label, string $css_class = 'arvaseo-btn arvaseo-btn--sm', string $confirm = '' ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="arvaseo-inline-form"' . ( '' !== $confirm ? ' data-arvaseo-confirm="' . esc_attr( $confirm ) . '"' : '' ) . '>';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />';
		wp_nonce_field( $action );
		echo '<button type="submit" class="' . esc_attr( $css_class ) . '">' . esc_html( $label ) . '</button>';
		echo '</form>';
	}

	/**
	 * A status label (text, not colour alone).
	 *
	 * @param string $tone  good|warn|bad|neutral|acc.
	 * @param string $label Text.
	 */
	public static function badge( string $tone, string $label ): string {
		$tone = in_array( $tone, array( 'good', 'warn', 'bad', 'neutral', 'acc', 'lime' ), true ) ? $tone : 'neutral';
		return '<span class="arvaseo-chip arvaseo-chip--' . esc_attr( $tone ) . '">' . esc_html( $label ) . '</span>';
	}

	/**
	 * Date and time in the site's time zone.
	 *
	 * @param int|string|null $time Unix time or ISO string.
	 */
	public static function when( $time ): string {
		if ( null === $time || '' === $time || 0 === $time ) {
			return __( 'Never', 'arvaseo-connector' );
		}
		$ts = is_numeric( $time ) ? (int) $time : strtotime( (string) $time );
		if ( ! $ts ) {
			return __( 'Unknown', 'arvaseo-connector' );
		}
		/* translators: 1: date and time, 2: relative time such as "5 mins". */
		return sprintf( __( '%1$s (%2$s ago)', 'arvaseo-connector' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ), human_time_diff( $ts ) );
	}

	/**
	 * Nonce and capability.
	 *
	 * @param string $action Nonce action.
	 * @param string $cap    Capability.
	 */
	private static function guard( string $action, string $cap ): void {
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'You don’t have permission to do that.', 'arvaseo-connector' ), 403 );
		}
		check_admin_referer( $action );
	}

	/**
	 * Redirect back to a page with a notice code.
	 *
	 * @param string $page   Page slug.
	 * @param string $notice Notice code.
	 */
	private static function back( string $page, string $notice ): void {
		wp_safe_redirect( add_query_arg( 'arvaseo_notice', $notice, admin_url( 'admin.php?page=' . $page ) ) );
		exit;
	}

	/**
	 * Menu icon (the Arva SEO mark, single colour so WordPress can tint it).
	 */
	private static function menu_icon(): string {
		// The brand's single-colour mark (brand/arvaseo-icon-mono-black.svg), tinted by WordPress.
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><path d="M8 0H24A8 8 0 0 1 32 8V24A8 8 0 0 1 24 32H8A8 8 0 0 1 0 24V8A8 8 0 0 1 8 0ZM9 22.5 15.2 9h1.6L23 22.5h-3.1l-1.2-2.8h-5.4l-1.2 2.8H9Zm5.1-5.3h3.8L16 12.6l-1.9 4.6ZM21 9.5a2.5 2.5 0 1 0 5 0a2.5 2.5 0 1 0-5 0Z" fill="black" fill-rule="evenodd"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- data URI for the menu icon.
	}
}
