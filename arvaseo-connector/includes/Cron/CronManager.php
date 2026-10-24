<?php
/**
 * Scheduled work.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Cron;

use ArvaSEO\Connector\Auth\TokenStore;
use ArvaSEO\Connector\Health\AssessmentService;
use ArvaSEO\Connector\Health\HealthSnapshotService;
use ArvaSEO\Connector\Install\Schema;
use ArvaSEO\Connector\Inventory\PluginInventoryService;
use ArvaSEO\Connector\Settings;
use ArvaSEO\Connector\Site\SiteRegistrationService;
use ArvaSEO\Connector\Support\Ids;
use ArvaSEO\Connector\Sync\SyncState;

defined( 'ABSPATH' ) || exit;

/**
 * WP-Cron jobs. WP-Cron runs when the site gets visits, so timing is best effort: low-traffic
 * sites should run wp-cron.php from a real server scheduler (see docs/admin-guide.md). Each job
 * respects its backoff after failures and never runs the full health suite on a page load.
 *
 * - arvaseo_connector_poll         every 5 minutes: site status, pending content, acknowledgement outbox
 * - arvaseo_connector_inventory    twice daily (and 2 minutes after plugin or theme changes)
 * - arvaseo_connector_health       daily or twice daily (setting), and on "Recheck website"
 * - arvaseo_connector_assessment   once, a few minutes after a health upload
 * - arvaseo_connector_register     once, after publishing settings change
 * - arvaseo_connector_maintenance  daily: retention clean-up
 */
final class CronManager {

	public const SYNC_HOOKS = array( 'arvaseo_connector_poll', 'arvaseo_connector_inventory', 'arvaseo_connector_health', 'arvaseo_connector_assessment', 'arvaseo_connector_register' );

	/**
	 * Hook the jobs.
	 */
	public static function init(): void {
		add_filter( 'cron_schedules', array( self::class, 'schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- a light poll that recovers missed deliveries; documented.
		add_action(
			'arvaseo_connector_poll',
			static function (): void {
				PollingService::poll();
			}
		);
		add_action( 'arvaseo_connector_inventory', array( self::class, 'inventory' ) );
		add_action( 'arvaseo_connector_health', array( self::class, 'health' ) );
		add_action(
			'arvaseo_connector_assessment',
			static function (): void {
				AssessmentService::refresh();
			}
		);
		add_action(
			'arvaseo_connector_register',
			static function (): void {
				SiteRegistrationService::register();
			}
		);
		add_action( 'arvaseo_connector_maintenance', array( self::class, 'maintenance' ) );

		// Plugin and theme changes: refresh the inventory soon (debounced).
		foreach ( array( 'activated_plugin', 'deactivated_plugin', 'deleted_plugin', 'switch_theme', 'upgrader_process_complete' ) as $hook ) {
			add_action( $hook, array( self::class, 'inventory_changed' ) );
		}
		if ( TokenStore::is_connected() ) {
			self::schedule_sync();
		}
	}

	/**
	 * The five-minute schedule.
	 *
	 * @param array<string, array<string, mixed>> $schedules Schedules.
	 * @return array<string, array<string, mixed>>
	 */
	public static function schedules( array $schedules ): array {
		$schedules['arvaseo_five_minutes'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 5 minutes (Arva SEO Connector)', 'arvaseo-connector' ),
		);
		return $schedules;
	}

	/**
	 * Make sure recurring sync jobs exist.
	 */
	public static function schedule_sync(): void {
		if ( ! wp_next_scheduled( 'arvaseo_connector_poll' ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'arvaseo_five_minutes', 'arvaseo_connector_poll' );
		}
		if ( ! wp_next_scheduled( 'arvaseo_connector_inventory' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'twicedaily', 'arvaseo_connector_inventory' );
		}
		if ( ! wp_next_scheduled( 'arvaseo_connector_health' ) ) {
			wp_schedule_event( time() + 2 * HOUR_IN_SECONDS, (string) Settings::get( 'health_frequency' ), 'arvaseo_connector_health' );
		}
		self::schedule_maintenance();
	}

	/**
	 * Daily maintenance (also while disconnected: it applies retention).
	 */
	public static function schedule_maintenance(): void {
		if ( ! wp_next_scheduled( 'arvaseo_connector_maintenance' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'arvaseo_connector_maintenance' );
		}
	}

	/**
	 * Run a job soon, once, and nudge WP-Cron.
	 *
	 * @param string $hook  Hook.
	 * @param int    $delay Seconds from now.
	 */
	public static function run_soon( string $hook, int $delay = 5 ): void {
		$next = wp_next_scheduled( $hook );
		if ( false === $next || $next > time() + $delay + 60 ) {
			wp_schedule_single_event( time() + $delay, $hook );
		}
		if ( function_exists( 'spawn_cron' ) && ! wp_doing_cron() ) {
			spawn_cron();
		}
	}

	/**
	 * Stop syncing (disconnect).
	 */
	public static function clear_sync(): void {
		foreach ( self::SYNC_HOOKS as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	/**
	 * Stop everything (deactivation, uninstall).
	 */
	public static function clear_all(): void {
		self::clear_sync();
		wp_clear_scheduled_hook( 'arvaseo_connector_maintenance' );
		wp_clear_scheduled_hook( \ArvaSEO\Connector\Speed\Cloudflare::HOOK );
		wp_unschedule_hook( \ArvaSEO\Connector\Speed\ExternalAssets::HOOK );
		wp_clear_scheduled_hook( \ArvaSEO\Connector\Security\Scanner::HOOK );
		wp_clear_scheduled_hook( \ArvaSEO\Connector\Security\Scanner::WEEKLY );
	}

	/**
	 * Inventory job.
	 */
	public static function inventory(): void {
		if ( SyncState::may_attempt( 'inventory' ) ) {
			PluginInventoryService::sync();
		}
	}

	/**
	 * Health job.
	 */
	public static function health(): void {
		if ( SyncState::may_attempt( 'health' ) ) {
			HealthSnapshotService::run();
		}
	}

	/**
	 * A plugin or theme changed.
	 */
	public static function inventory_changed(): void {
		if ( TokenStore::is_connected() ) {
			$next = wp_next_scheduled( 'arvaseo_connector_inventory' );
			if ( false === $next || $next > time() + 5 * MINUTE_IN_SECONDS ) {
				wp_schedule_single_event( time() + 2 * MINUTE_IN_SECONDS, 'arvaseo_connector_inventory' );
			}
		}
	}

	/**
	 * Retention: replay records after 7 days, acknowledged deliveries and reported SEO title
	 * and description updates after 180 days, the activity log after the configured number of days.
	 */
	public static function maintenance(): void {
		global $wpdb;
		$events = Schema::table( 'events' );
		$del    = Schema::table( 'deliveries' );
		$log    = Schema::table( 'audit_log' );
		$seo    = Schema::table( 'seo_updates' );
		$days   = (int) Settings::get( 'log_retention_days' );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- own tables.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE received_at < %s', $events, Ids::mysql( time() - 7 * DAY_IN_SECONDS ) ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE ack_sent = 1 AND received_at < %s', $del, Ids::mysql( time() - 180 * DAY_IN_SECONDS ) ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE result_sent = 1 AND received_at < %s', $seo, Ids::mysql( time() - 180 * DAY_IN_SECONDS ) ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s', $log, Ids::mysql( time() - $days * DAY_IN_SECONDS ) ) );
		// phpcs:enable
		// Repairs can be undone for 30 days; after that their kept copies are deleted.
		\ArvaSEO\Connector\Security\Repairs::purge();
	}
}
