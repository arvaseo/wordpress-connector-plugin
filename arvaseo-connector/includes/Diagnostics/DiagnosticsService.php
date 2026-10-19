<?php
/**
 * Redacted diagnostics.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Diagnostics;

use ArvaSEO\Connector\Auth\Crypto;
use ArvaSEO\Connector\Auth\TokenStore;
use ArvaSEO\Connector\Config;
use ArvaSEO\Connector\Install\Schema;
use ArvaSEO\Connector\Privacy\PrivacyService;
use ArvaSEO\Connector\Settings;
use ArvaSEO\Connector\Support\Ids;
use ArvaSEO\Connector\Sync\SyncState;

defined( 'ABSPATH' ) || exit;

/**
 * A support bundle built from an allowlist (nothing is dumped): versions, connection state,
 * sync results, schedules and settings. No tokens, secrets, codes, paths, usernames, emails,
 * content or server details beyond versions. The site registration ID is shortened.
 */
final class DiagnosticsService {

	/**
	 * The bundle.
	 *
	 * @return array<string, mixed>
	 */
	public static function bundle(): array {
		global $wpdb;
		$connection  = TokenStore::connection();
		$credentials = TokenStore::credentials();
		$site_id     = TokenStore::site_id();
		$settings    = Settings::all();
		$deliveries  = Schema::table( 'deliveries' );
		$log         = Schema::table( 'audit_log' );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- own tables.
		$outcomes = $wpdb->get_results( $wpdb->prepare( 'SELECT outcome, COUNT(*) AS n FROM %i GROUP BY outcome', $deliveries ), ARRAY_A );
		$pending  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE ack_sent = 0 AND ack IS NOT NULL', $deliveries ) );
		$recent   = $wpdb->get_results( $wpdb->prepare( "SELECT created_at, level, event FROM %i WHERE level <> 'info' ORDER BY id DESC LIMIT 20", $log ), ARRAY_A );
		// phpcs:enable
		$sync = array();
		foreach ( SyncState::all() as $kind => $state ) {
			$sync[ $kind ] = array(
				'status'               => $state['status'] ?? null,
				'at'                   => isset( $state['at'] ) ? Ids::iso( (int) $state['at'] ) : null,
				'consecutive_failures' => (int) ( $state['consecutive_failures'] ?? 0 ),
				'error_code'           => $state['error']['code'] ?? null,
				'error_http_status'    => $state['error']['http_status'] ?? null,
			);
		}
		$cron = array();
		foreach ( array( 'arvaseo_connector_poll', 'arvaseo_connector_inventory', 'arvaseo_connector_health', 'arvaseo_connector_maintenance' ) as $hook ) {
			$next          = wp_next_scheduled( $hook );
			$cron[ $hook ] = false === $next ? null : Ids::iso( (int) $next );
		}

		return array(
			'generated_at'    => Ids::iso(),
			'plugin'          => array(
				'version'          => ARVASEO_CONNECTOR_VERSION,
				'protocol'         => Config::PROTOCOL_VERSION,
				'db_schema'        => get_option( Schema::OPTION ),
				'api_host'         => Config::api_host(),
				'encryption_key'   => Crypto::key_source(),
				'sodium_extension' => extension_loaded( 'sodium' ),
			),
			'environment'     => array(
				'wordpress'        => get_bloginfo( 'version' ),
				'php'              => PHP_VERSION,
				'multisite'        => is_multisite(),
				'environment_type' => wp_get_environment_type(),
				'wp_cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
				'timezone'         => wp_timezone_string(),
			),
			'connection'      => array(
				'status'                  => $connection['status'] ?? 'disconnected',
				'site_registration_id'    => null === $site_id ? null : substr( $site_id, 0, 6 ) . '…' . substr( $site_id, -4 ),
				'scopes'                  => $connection['scopes'] ?? array(),
				'push_status'             => $connection['push_status'] ?? null,
				'credentials_readable'    => null !== $credentials,
				'access_token_expires_at' => null === $credentials ? null : Ids::iso( (int) $credentials['access_expires_at'] ),
				'refresh_expires_at'      => null === $credentials ? null : Ids::iso( (int) $credentials['refresh_expires_at'] ),
				'signing_keys'            => null === $credentials ? 0 : count( $credentials['signing_keys'] ),
				'last_success_at'         => isset( $connection['last_success_at'] ) ? Ids::iso( (int) $connection['last_success_at'] ) : null,
				'last_error_code'         => $connection['last_error']['code'] ?? null,
				'entitlements'            => $connection['entitlements'] ?? null,
			),
			'consent'         => null === PrivacyService::current() ? null : array(
				'schema_version' => PrivacyService::current()['schema_version'],
				'purposes'       => PrivacyService::current()['purposes'],
				'consented_at'   => PrivacyService::current()['consented_at'],
			),
			// The PageSpeed fixes and whether they're all switched off in wp-config.php.
			'speed_fixes'     => array(
				'fixes'       => \ArvaSEO\Connector\Speed\Fixes::for_registration(),
				'kill_switch' => defined( \ArvaSEO\Connector\Speed\Fixes::KILL_SWITCH ) && (bool) constant( \ArvaSEO\Connector\Speed\Fixes::KILL_SWITCH ),
			),
			'settings'        => array(
				'publishing_mode'       => $settings['publishing_mode'],
				'downgrade_to_draft'    => $settings['downgrade_to_draft'],
				'overwrite_local_edits' => $settings['overwrite_local_edits'],
				'default_author_set'    => $settings['default_author_id'] > 0,
				'allowed_post_types'    => $settings['allowed_post_types'],
				'create_missing_terms'  => $settings['create_missing_terms'],
				'media_import'          => $settings['media_import'],
				'media_hosts'           => count( $settings['media_hosts'] ),
				'seo_plugin'            => $settings['seo_plugin'],
				'seo_plugin_detected'   => \ArvaSEO\Connector\Content\SeoPlugins::resolve(),
				'seo_drafts'            => (bool) $settings['seo_drafts'],
				'seo_updates'           => (bool) $settings['seo_updates'],
				'health_frequency'      => $settings['health_frequency'],
				'log_retention_days'    => $settings['log_retention_days'],
			),
			'sync'            => $sync,
			'schedules'       => $cron,
			'deliveries'      => array(
				'by_outcome'               => array_column( $outcomes, 'n', 'outcome' ),
				'pending_acknowledgements' => $pending,
			),
			'recent_problems' => $recent,
		);
	}

	/**
	 * Pretty JSON.
	 */
	public static function json(): string {
		return (string) wp_json_encode( self::bundle(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}
}
