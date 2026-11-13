<?php
/**
 * The Arva SEO box on the WordPress Dashboard.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Admin;

use ArvaSEO\Connector\Api\ApiClient;
use ArvaSEO\Connector\Auth\TokenStore;
use ArvaSEO\Connector\Cron\CronManager;
use ArvaSEO\Connector\Install\Capabilities;
use ArvaSEO\Connector\Logging\AuditLogger;
use ArvaSEO\Connector\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Up to three things that need the administrator and up to three messages from Arva SEO. Shown only to people who
 * can manage the plugin, only when Settings allow it. Rendering never makes a network call: it reads a copy kept for
 * six hours, which a background job (or "Refresh") replaces. The copy holds only text and links; all of it is
 * escaped when shown, and links open in a new tab.
 */
final class DashboardWidget {

	public const ID = 'arvaseo_connector_dashboard';

	public const CACHE = 'arvaseo_connector_dashboard_cache';

	public const HOOK = 'arvaseo_connector_dashboard';

	public const FRESH = 6 * HOUR_IN_SECONDS;

	public const MAX_ITEMS = 3;

	public const DISMISSED = 'arvaseo_connector_dismissed_news';

	/**
	 * Hook the box, the background refresh and the two small actions.
	 */
	public static function init(): void {
		add_action( 'wp_dashboard_setup', array( self::class, 'register' ) );
		add_action( self::HOOK, array( self::class, 'refresh' ) );
		add_action( 'admin_post_arvaseo_connector_dashboard_refresh', array( self::class, 'handle_refresh' ) );
		add_action( 'admin_post_arvaseo_connector_dashboard_dismiss', array( self::class, 'handle_dismiss' ) );
	}

	/**
	 * Add the box for people who may see it.
	 */
	public static function register(): void {
		if ( ! Settings::get( 'dashboard_widget' ) || ! current_user_can( Capabilities::MANAGE ) ) {
			return;
		}
		wp_add_dashboard_widget( self::ID, __( 'Arva SEO', 'arvaseo-connector' ), array( self::class, 'render' ) );
	}

	/**
	 * The stored copy: array{fetched_at:int, needs_you:array, announcements:array} or null.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function cached(): ?array {
		$copy = get_option( self::CACHE );
		return is_array( $copy ) && isset( $copy['fetched_at'] ) ? $copy : null;
	}

	/**
	 * Fetch a fresh copy from Arva SEO (background job). A failure keeps the old copy.
	 */
	public static function refresh(): void {
		if ( ! TokenStore::is_connected() || ! Settings::get( 'dashboard_widget' ) ) {
			return;
		}
		$response = ApiClient::instance()->site( 'GET', '/dashboard', null, 10 );
		if ( ! $response->ok() ) {
			AuditLogger::warning( 'dashboard.refresh_failed', __( 'The Dashboard box could not be refreshed.', 'arvaseo-connector' ), array( 'http_status' => $response->status ) );
			return;
		}
		$data = $response->data();
		update_option(
			self::CACHE,
			array(
				'fetched_at'    => time(),
				'needs_you'     => self::clean_needs( (array) ( $data['needs_you'] ?? array() ) ),
				'announcements' => self::clean_announcements( (array) ( $data['announcements'] ?? array() ) ),
			),
			false
		);
	}

	/**
	 * Keep only what the box shows, as plain text and https links.
	 *
	 * @param array<int, mixed> $items Items from Arva SEO.
	 * @return array<int, array<string, string>>
	 */
	public static function clean_needs( array $items ): array {
		$out = array();
		foreach ( array_slice( $items, 0, self::MAX_ITEMS ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$out[] = array(
				'id'   => sanitize_key( (string) ( $item['id'] ?? '' ) ),
				'text' => mb_substr( sanitize_text_field( (string) ( $item['text'] ?? '' ) ), 0, 200 ),
				'url'  => self::https( (string) ( $item['url'] ?? '' ) ),
			);
		}
		return array_values( array_filter( $out, static fn( $i ) => '' !== $i['text'] ) );
	}

	/**
	 * Same for announcements.
	 *
	 * @param array<int, mixed> $items Items from Arva SEO.
	 * @return array<int, array<string, string>>
	 */
	public static function clean_announcements( array $items ): array {
		$out = array();
		foreach ( array_slice( $items, 0, self::MAX_ITEMS ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$out[] = array(
				'id'           => sanitize_key( (string) ( $item['id'] ?? '' ) ),
				'kind'         => 'service' === ( $item['kind'] ?? '' ) ? 'service' : 'product_news',
				'title'        => mb_substr( sanitize_text_field( (string) ( $item['title'] ?? '' ) ), 0, 120 ),
				'summary'      => mb_substr( sanitize_text_field( (string) ( $item['summary'] ?? '' ) ), 0, 200 ),
				'button_label' => mb_substr( sanitize_text_field( (string) ( $item['button_label'] ?? '' ) ), 0, 40 ),
				'url'          => self::https( (string) ( $item['url'] ?? '' ) ),
			);
		}
		return array_values( array_filter( $out, static fn( $i ) => '' !== $i['title'] && '' !== $i['id'] ) );
	}

	/**
	 * An https address, or an empty string.
	 *
	 * @param string $url Address.
	 */
	private static function https( string $url ): string {
		$url = esc_url_raw( trim( $url ), array( 'https' ) );
		return $url;
	}

	/**
	 * What needs the administrator: this site's own list first, then what Arva SEO adds that the site cannot see.
	 *
	 * @param array<string, mixed>|null $copy The stored copy.
	 * @return array<int, array{text: string, url: string}>
	 */
	public static function needs( ?array $copy ): array {
		$items = array();
		foreach ( AdminController::needs() as $need ) {
			$items[] = array(
				'text' => $need[2],
				'url'  => $need[4],
			);
		}
		$have = count( $items );
		foreach ( (array) ( $copy['needs_you'] ?? array() ) as $item ) {
			// Serious findings are already in this site's own list.
			if ( $have > 0 && 'findings' === $item['id'] ) {
				continue;
			}
			$items[] = array(
				'text' => $item['text'],
				'url'  => $item['url'],
			);
		}
		return array_slice( $items, 0, self::MAX_ITEMS );
	}

	/**
	 * Print the box.
	 */
	public static function render(): void {
		$connected = TokenStore::is_connected();
		$copy      = $connected ? self::cached() : null;
		if ( $connected && ( null === $copy || (int) $copy['fetched_at'] < time() - self::FRESH ) && ! wp_next_scheduled( self::HOOK ) ) {
			CronManager::run_soon( self::HOOK, 5 );
		}

		echo '<div class="arvaseo-dash">';
		$needs = self::needs( $copy );
		echo '<h3 style="margin:0 0 6px;font-size:13px">' . esc_html__( 'Needs you', 'arvaseo-connector' ) . '</h3>';
		if ( array() === $needs ) {
			echo '<p style="margin:0 0 12px">' . esc_html__( 'Nothing needs you right now.', 'arvaseo-connector' ) . '</p>';
		} else {
			echo '<ul style="margin:0 0 12px;list-style:disc;padding-left:18px">';
			foreach ( $needs as $need ) {
				echo '<li>' . esc_html( $need['text'] );
				if ( '' !== $need['url'] ) {
					printf( ' <a href="%1$s"%2$s>%3$s</a>', esc_url( $need['url'] ), str_starts_with( $need['url'], admin_url() ) ? '' : ' target="_blank" rel="noopener"', esc_html__( 'Look', 'arvaseo-connector' ) );
				}
				echo '</li>';
			}
			echo '</ul>';
		}

		if ( ! $connected ) {
			printf(
				'<p style="margin:0 0 12px"><a href="%1$s">%2$s</a> %3$s</p>',
				esc_url( admin_url( 'admin.php?page=arvaseo-connector#arvaseo-connect' ) ),
				esc_html__( 'Connect to Arva SEO', 'arvaseo-connector' ),
				esc_html__( 'to see messages from Arva SEO here.', 'arvaseo-connector' )
			);
		} else {
			self::render_announcements( (array) ( $copy['announcements'] ?? array() ) );
			$age = null === $copy ? null : (int) $copy['fetched_at'];
			echo '<p style="margin:12px 0 0;color:#646970;font-size:12px">';
			echo null === $age ? esc_html__( 'Loading from Arva SEO…', 'arvaseo-connector' ) : esc_html(
				/* translators: %s: how long ago, for example "2 hours". */
				sprintf( __( 'Updated %s ago.', 'arvaseo-connector' ), human_time_diff( $age ) )
			);
			printf(
				' <a href="%1$s">%2$s</a>',
				esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=arvaseo_connector_dashboard_refresh' ), 'arvaseo_connector_dashboard_refresh' ) ),
				esc_html__( 'Refresh', 'arvaseo-connector' )
			);
			echo '</p>';
		}
		echo '</div>';
	}

	/**
	 * The messages: service ones always, product news unless this person hid it.
	 *
	 * @param array<int, array<string, string>> $items Announcements.
	 */
	private static function render_announcements( array $items ): void {
		$hidden = (array) get_user_meta( get_current_user_id(), self::DISMISSED, true );
		$shown  = array_filter( $items, static fn( $i ) => 'service' === $i['kind'] || ! in_array( $i['id'], $hidden, true ) );
		if ( array() === $shown ) {
			return;
		}
		echo '<h3 style="margin:0 0 6px;font-size:13px">' . esc_html__( 'From Arva SEO', 'arvaseo-connector' ) . '</h3>';
		foreach ( $shown as $item ) {
			echo '<div style="margin:0 0 10px"><strong>' . esc_html( $item['title'] ) . '</strong>';
			if ( '' !== $item['summary'] ) {
				echo '<br />' . esc_html( $item['summary'] );
			}
			echo '<br />';
			if ( '' !== $item['url'] ) {
				printf( '<a href="%1$s" target="_blank" rel="noopener">%2$s</a>', esc_url( $item['url'] ), esc_html( '' !== $item['button_label'] ? $item['button_label'] : __( 'Read more', 'arvaseo-connector' ) ) );
			}
			if ( 'product_news' === $item['kind'] ) {
				printf(
					' &middot; <a href="%1$s">%2$s</a>',
					esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=arvaseo_connector_dashboard_dismiss&news=' . rawurlencode( $item['id'] ) ), 'arvaseo_connector_dashboard_dismiss' ) ),
					esc_html__( 'Hide', 'arvaseo-connector' )
				);
			}
			echo '</div>';
		}
	}

	/**
	 * "Refresh": fetch now, then go back to the Dashboard.
	 */
	public static function handle_refresh(): void {
		check_admin_referer( 'arvaseo_connector_dashboard_refresh' );
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'arvaseo-connector' ), '', array( 'response' => 403 ) );
		}
		self::refresh();
		wp_safe_redirect( admin_url( 'index.php' ) );
		exit;
	}

	/**
	 * "Hide": this person no longer sees that news item.
	 */
	public static function handle_dismiss(): void {
		check_admin_referer( 'arvaseo_connector_dashboard_dismiss' );
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'arvaseo-connector' ), '', array( 'response' => 403 ) );
		}
		$id = isset( $_GET['news'] ) ? sanitize_key( wp_unslash( (string) $_GET['news'] ) ) : '';
		if ( '' !== $id ) {
			$hidden   = array_values( array_filter( (array) get_user_meta( get_current_user_id(), self::DISMISSED, true ) ) );
			$hidden[] = $id;
			update_user_meta( get_current_user_id(), self::DISMISSED, array_slice( array_values( array_unique( $hidden ) ), -50 ) );
		}
		wp_safe_redirect( admin_url( 'index.php' ) );
		exit;
	}
}
