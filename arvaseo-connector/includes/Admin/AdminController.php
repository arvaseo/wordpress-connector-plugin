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
use ArvaSEO\Connector\Cron\CronManager;
use ArvaSEO\Connector\Cron\PollingService;
use ArvaSEO\Connector\Diagnostics\DiagnosticsService;
use ArvaSEO\Connector\Install\Capabilities;
use ArvaSEO\Connector\Inventory\PluginInventoryService;
use ArvaSEO\Connector\Logging\AuditLogger;
use ArvaSEO\Connector\Privacy\PrivacyService;
use ArvaSEO\Connector\Settings;
use ArvaSEO\Connector\Speed\Fixes;
use ArvaSEO\Connector\Site\SiteRegistrationService;

defined( 'ABSPATH' ) || exit;

/**
 * Native admin pages under "Arva SEO". Every action is a POST through admin-post.php with a nonce
 * and a capability check (the connection callback is a GET that is protected by the pairing state
 * instead). Viewing guidance never changes the site.
 */
final class AdminController {

	public const PAGES = array(
		'arvaseo-connector'   => array( 'Connection', Capabilities::VIEW_HEALTH, 'connection' ),
		'arvaseo-content'     => array( 'Content Sync', Capabilities::PUBLISH_CONTENT, 'content' ),
		'arvaseo-inventory'   => array( 'Plugin Inventory', Capabilities::SYNC_INVENTORY, 'inventory' ),
		'arvaseo-health'      => array( 'Website Health', Capabilities::VIEW_HEALTH, 'health' ),
		'arvaseo-speed'       => array( 'PageSpeed fixes', Capabilities::MANAGE, 'speed' ),
		'arvaseo-activity'    => array( 'Activity Log', Capabilities::MANAGE, 'activity' ),
		'arvaseo-settings'    => array( 'Settings', Capabilities::MANAGE, 'settings' ),
		'arvaseo-diagnostics' => array( 'Diagnostics & Privacy', Capabilities::MANAGE, 'diagnostics' ),
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
		add_action( 'admin_post_arvaseo_connector_diagnostics', array( self::class, 'download_diagnostics' ) );
		add_action( 'admin_post_arvaseo_connector_speed', array( self::class, 'speed' ) );
		add_action( 'admin_post_arvaseo_connector_speed_options', array( self::class, 'speed_options' ) );
		add_action( 'admin_notices', array( self::class, 'defer_notice' ) );
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
		foreach ( self::PAGES as $slug => list( $title, $cap ) ) {
			add_submenu_page( 'arvaseo-connector', self::title( $slug ), self::title( $slug ), $cap, $slug, array( self::class, 'render' ) );
		}
	}

	/**
	 * Translated page titles.
	 *
	 * @param string $slug Page slug.
	 */
	public static function title( string $slug ): string {
		$titles = array(
			'arvaseo-connector'   => __( 'Connection', 'arvaseo-connector' ),
			'arvaseo-content'     => __( 'Content Sync', 'arvaseo-connector' ),
			'arvaseo-inventory'   => __( 'Plugin Inventory', 'arvaseo-connector' ),
			'arvaseo-health'      => __( 'Website Health', 'arvaseo-connector' ),
			'arvaseo-speed'       => __( 'PageSpeed fixes', 'arvaseo-connector' ),
			'arvaseo-activity'    => __( 'Activity Log', 'arvaseo-connector' ),
			'arvaseo-settings'    => __( 'Settings', 'arvaseo-connector' ),
			'arvaseo-diagnostics' => __( 'Diagnostics & Privacy', 'arvaseo-connector' ),
		);
		return $titles[ $slug ] ?? '';
	}

	/**
	 * Render the current page.
	 */
	public static function render(): void {
		$slug = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : 'arvaseo-connector'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- page routing only.
		if ( ! isset( self::PAGES[ $slug ] ) || ! current_user_can( self::PAGES[ $slug ][1] ) ) {
			wp_die( esc_html__( 'You don’t have permission to view this page.', 'arvaseo-connector' ), 403 );
		}
		$view = self::PAGES[ $slug ][2];
		echo '<div class="wrap arvaseo-wrap">';
		self::header( $slug );
		self::notice();
		include ARVASEO_CONNECTOR_DIR . '/includes/Admin/views/' . $view . '.php';
		echo '</div>';
	}

	/**
	 * Logo, title and tabs.
	 *
	 * @param string $current Current page.
	 */
	private static function header( string $current ): void {
		printf(
			'<h1 class="arvaseo-title"><img src="%1$s" alt="" width="28" height="28" class="arvaseo-logo" /> %2$s <span class="arvaseo-title-page">%3$s</span></h1>',
			esc_url( plugins_url( 'assets/arvaseo-icon.svg', ARVASEO_CONNECTOR_FILE ) ),
			esc_html__( 'Arva SEO – Speed Booster & SEO Connector', 'arvaseo-connector' ),
			esc_html( self::title( $current ) )
		);
		echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Arva SEO Connector sections', 'arvaseo-connector' ) . '">';
		foreach ( self::PAGES as $slug => $page ) {
			if ( ! current_user_can( $page[1] ) ) {
				continue;
			}
			printf(
				'<a href="%1$s" class="nav-tab%2$s"%3$s>%4$s</a>',
				esc_url( admin_url( 'admin.php?page=' . $slug ) ),
				$slug === $current ? ' nav-tab-active' : '',
				$slug === $current ? ' aria-current="page"' : '',
				esc_html( self::title( $slug ) )
			);
		}
		echo '</nav>';
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
			'exchange_failed'                => array( 'error', __( 'Arva SEO didn’t accept the connection (the code may have expired or been used). Start again; check the Activity Log if it keeps happening.', 'arvaseo-connector' ) ),
			'disconnected'                   => array( 'success', __( 'Disconnected. The credentials were removed from this site and revoked at Arva SEO, and synchronisation stopped.', 'arvaseo-connector' ) ),
			'test_ok'                        => array( 'success', __( 'The connection works.', 'arvaseo-connector' ) ),
			'test_failed'                    => array( 'error', __( 'The connection test failed. See Connection health below and the Activity Log.', 'arvaseo-connector' ) ),
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
		);
		if ( isset( $map[ $code ] ) ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $map[ $code ][0] ), esc_html( $map[ $code ][1] ) );
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
		if ( $share && ! $agree ) {
			self::back( 'arvaseo-connector', 'consent_required' );
		}
		wp_redirect( AuthService::start( $share, $content ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- the fixed Arva SEO app address from Config.
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
	 * Save the extras to remove and the scripts Defer scripts leaves alone.
	 */
	public static function speed_options(): void {
		self::guard( 'arvaseo_connector_speed_options', Capabilities::MANAGE );
		$extras  = isset( $_POST['extras'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['extras'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
		$exclude = isset( $_POST['defer_exclude'] ) ? sanitize_textarea_field( wp_unslash( $_POST['defer_exclude'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
		Fixes::save_options( array_fill_keys( $extras, true ), $exclude );
		$fix = isset( $_POST['fix'] ) ? sanitize_key( wp_unslash( $_POST['fix'] ) ) : 'wp_extras'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
		self::back_to_fix( $fix, 'speed_options_saved' );
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
			esc_html__( 'PageSpeed fixes', 'arvaseo-connector' )
		);
	}

	/**
	 * Back to a fix's card with a notice code.
	 *
	 * @param string $fix    Fix ID.
	 * @param string $notice Notice code.
	 */
	private static function back_to_fix( string $fix, string $notice ): void {
		$anchor = isset( Fixes::all()[ $fix ] ) ? '#' . Fixes::anchor( $fix ) : '';
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
				'copied'            => __( 'Copied.', 'arvaseo-connector' ),
				'confirmDisconnect' => __( 'Disconnect this site from Arva SEO? Credentials are removed and revoked, and nothing more is sent or received until you connect again.', 'arvaseo-connector' ),
				'confirmFull'       => __( 'Full publishing lets Arva SEO users publish posts on this site immediately, within the default author’s permissions. Continue?', 'arvaseo-connector' ),
				'confirmOverwrite'  => __( 'With this on, content sent again from Arva SEO replaces edits made here in WordPress (a revision is kept). Continue?', 'arvaseo-connector' ),
				'confirmSeoUpdates' => __( 'With this on, Arva SEO users can change the SEO title and meta description of published posts, pages and products on this site. Each change can be undone from Arva SEO. Continue?', 'arvaseo-connector' ),
				'confirmDefer'      => __( 'Turn on Defer scripts for every visitor? It changes how scripts load, and some menus, sliders, forms, chat widgets or checkouts may stop working. If anything does, turn it off on this page or leave that script alone. Continue?', 'arvaseo-connector' ),
				'confirmRisky'      => __( 'This changes how parts of your pages load for every visitor. Check your important pages afterwards, and turn it off here if anything looks wrong. Continue?', 'arvaseo-connector' ),
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
			array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=arvaseo-connector' ) ) . '">' . esc_html__( 'Connection', 'arvaseo-connector' ) . '</a>' );
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
	public static function button( string $action, string $label, string $css_class = 'button', string $confirm = '' ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="arvaseo-inline-form"' . ( '' !== $confirm ? ' data-arvaseo-confirm="' . esc_attr( $confirm ) . '"' : '' ) . '>';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />';
		wp_nonce_field( $action );
		echo '<button type="submit" class="' . esc_attr( $css_class ) . '">' . esc_html( $label ) . '</button>';
		echo '</form>';
	}

	/**
	 * A status label (text, not colour alone).
	 *
	 * @param string $tone  good|warn|bad|neutral.
	 * @param string $label Text.
	 */
	public static function badge( string $tone, string $label ): string {
		return '<span class="arvaseo-badge arvaseo-badge--' . esc_attr( $tone ) . '">' . esc_html( $label ) . '</span>';
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
