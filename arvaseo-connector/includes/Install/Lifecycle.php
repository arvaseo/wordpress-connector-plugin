<?php
/**
 * Activation, deactivation and new Multisite sites.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Install;

use ArvaSEO\Connector\Cron\CronManager;

defined( 'ABSPATH' ) || exit;

/**
 * Activation creates tables and capabilities (for every site when network-activated);
 * deactivation stops scheduled jobs and keeps everything else, including the connection.
 */
final class Lifecycle {

	/**
	 * Activation hook.
	 *
	 * @param bool $network_wide Network activation on Multisite.
	 */
	public static function activate( $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			) as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::activate_site();
				restore_current_blog();
			}
			return;
		}
		self::activate_site();
	}

	/**
	 * Set up the current site.
	 */
	public static function activate_site(): void {
		Schema::migrate();
		Capabilities::grant();
		if ( false === get_option( 'arvaseo_connector_settings' ) ) {
			add_option( 'arvaseo_connector_settings', \ArvaSEO\Connector\Settings::defaults(), '', false );
		}
		CronManager::schedule_maintenance();
	}

	/**
	 * Deactivation hook: stop every scheduled job; keep settings, tables and the connection.
	 *
	 * @param bool $network_wide Network deactivation on Multisite.
	 */
	public static function deactivate( $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			) as $site_id ) {
				switch_to_blog( (int) $site_id );
				CronManager::clear_all();
				restore_current_blog();
			}
			return;
		}
		CronManager::clear_all();
		\ArvaSEO\Connector\Speed\ServerRules::remove_on_exit();
	}

	/**
	 * A site added to a network where the plugin is network-active.
	 *
	 * @param \WP_Site $site New site.
	 */
	public static function initialize_site( $site ): void {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! is_plugin_active_for_network( plugin_basename( ARVASEO_CONNECTOR_FILE ) ) ) {
			return;
		}
		switch_to_blog( (int) $site->blog_id );
		self::activate_site();
		restore_current_blog();
	}
}
