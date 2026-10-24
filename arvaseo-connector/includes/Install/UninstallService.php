<?php
/**
 * Uninstall.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Install;

use ArvaSEO\Connector\Api\ApiClient;
use ArvaSEO\Connector\Auth\TokenStore;
use ArvaSEO\Connector\Cron\CronManager;
use ArvaSEO\Connector\Health\AssessmentService;
use ArvaSEO\Connector\Health\HealthSnapshotService;
use ArvaSEO\Connector\Privacy\PrivacyService;
use ArvaSEO\Connector\Settings;
use ArvaSEO\Connector\Sync\SyncState;

defined( 'ABSPATH' ) || exit;

/**
 * For every site (Multisite) or the single site: stop jobs, revoke and delete credentials, and,
 * when chosen, delete the plugin's tables, options and capabilities. Posts and media stay.
 */
final class UninstallService {

	/**
	 * Run.
	 */
	public static function run(): void {
		if ( is_multisite() ) {
			foreach ( get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			) as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::site();
				restore_current_blog();
			}
			return;
		}
		self::site();
	}

	/**
	 * One site.
	 */
	private static function site(): void {
		CronManager::clear_all();
		$credentials = TokenStore::credentials();
		if ( null !== $credentials ) {
			// Best effort; the plugin is going away either way.
			ApiClient::instance()->public_post( '/api/v1/wordpress/revoke', array( 'refresh_token' => $credentials['refresh_token'] ) );
		}
		TokenStore::clear();
		TokenStore::clear_connection();
		delete_transient( 'arvaseo_connector_auth_probe' );
		// Server rules, the page cache and the Cloudflare token never outlive the plugin, whatever else is kept.
		\ArvaSEO\Connector\Speed\PageCache::remove_on_exit();
		\ArvaSEO\Connector\Speed\ServerRules::remove_on_exit();
		\ArvaSEO\Connector\Speed\Cloudflare::remove_on_exit();

		if ( ! Settings::get( 'delete_data_on_uninstall' ) ) {
			return;
		}
		// Undo can log activity and invalidate caches; finish it before removing tables or options.
		\ArvaSEO\Connector\Speed\LocalFonts::delete_all();
		\ArvaSEO\Connector\Speed\AssetMinifier::delete_all();
		\ArvaSEO\Connector\Speed\WebpLibrary::undo();
		\ArvaSEO\Connector\Speed\CssOptimizer::delete_all();
		Schema::drop();
		Capabilities::revoke();
		SyncState::clear();
		AssessmentService::clear();
		HealthSnapshotService::clear();
		\ArvaSEO\Connector\Security\Scanner::clear();
		\ArvaSEO\Connector\Security\Findings::clear();
		\ArvaSEO\Connector\Security\Feed::clear();
		\ArvaSEO\Connector\Security\DeepScan::clear();
		\ArvaSEO\Connector\Security\FileEdits::clear();
		PrivacyService::withdraw();
		delete_option( Settings::OPTION );
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'arvaseo\\_connector\\_%' OR option_name LIKE '\\_transient\\_arvaseo\\_connector\\_%' OR option_name LIKE '\\_transient\\_timeout\\_arvaseo\\_connector\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall clean-up of the plugin's own options.
		delete_post_meta_by_key( '_arvaseo_source_hash' );
		delete_post_meta_by_key( \ArvaSEO\Connector\Speed\Exclusions::META );
	}
}
