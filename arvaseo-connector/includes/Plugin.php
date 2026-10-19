<?php
/**
 * Bootstrap.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector;

use ArvaSEO\Connector\Admin\AdminController;
use ArvaSEO\Connector\Cron\CronManager;
use ArvaSEO\Connector\Install\Lifecycle;
use ArvaSEO\Connector\Install\Schema;
use ArvaSEO\Connector\Privacy\PrivacyService;
use ArvaSEO\Connector\Rest\WebhookController;
use ArvaSEO\Connector\Site\IndexNowKey;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin into WordPress.
 *
 * Extension points (documented in docs/developer-guide.md):
 * - arvaseo_connector_http_transport (filter): replace the HTTP transport (tests).
 * - arvaseo_connector_author_for_key (filter): map an author key to a local user ID.
 * - arvaseo_connector_seo_adapters (filter): add an SEO title/description adapter.
 */
final class Plugin {

	/**
	 * Boot once.
	 */
	public static function boot(): void {
		static $booted = false;
		if ( $booted ) {
			return;
		}
		$booted = true;

		add_action( 'init', array( self::class, 'init' ) );
		// Before WordPress resolves the request, so /{key}.txt never becomes a 404 page.
		add_action( 'init', array( IndexNowKey::class, 'maybe_serve' ), 0 );
		add_action( 'rest_api_init', array( WebhookController::class, 'register' ) );
		add_action( 'wp_initialize_site', array( Lifecycle::class, 'initialize_site' ), 20 );
		add_action( 'admin_init', array( PrivacyService::class, 'add_policy_content' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( PrivacyService::class, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( PrivacyService::class, 'register_eraser' ) );
		CronManager::init();
		\ArvaSEO\Connector\Speed\Runtime::init();
		if ( is_admin() ) {
			AdminController::init();
		}
	}

	/**
	 * Database upgrades. (WordPress.org loads translations for the text domain itself.)
	 */
	public static function init(): void {
		Schema::migrate();
	}
}
