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

		// Last of everything that hears about Arva SEO being deactivated: no job it scheduled meanwhile stays.
		add_action( 'deactivated_plugin', array( self::class, 'after_leaving' ), 99, 2 );

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
		$at   = time() + ( $delay <= 5 ? 0 : $delay );
		$next = wp_next_scheduled( $hook );
		if ( false === $next || $next > $at + 60 ) {
			$event = wp_get_scheduled_event( $hook );
			$moved = false !== $event && false === $event->schedule && true === wp_unschedule_event( $event->timestamp, $hook, $event->args );
			if ( ! wp_schedule_single_event( $at, $hook ) && $moved ) {
				// A rejected replacement must not lose the fallback we already had.
				wp_schedule_single_event( $event->timestamp, $hook, $event->args );
			}
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
	 * Every scheduled job the plugin has, with or without arguments: the ones above, the Speed
	 * jobs and the security scan's. wp_unschedule_hook() removes events scheduled with arguments
	 * too (Google Fonts copies, approved files), which wp_clear_scheduled_hook() without arguments
	 * leaves behind.
	 *
	 * @return string[]
	 */
	public static function all_hooks(): array {
		return array_merge(
			self::SYNC_HOOKS,
			array(
				'arvaseo_connector_maintenance',
				\ArvaSEO\Connector\Speed\Cloudflare::HOOK,
				\ArvaSEO\Connector\Speed\AssetCache::HOOK,
				\ArvaSEO\Connector\Speed\Housekeeping::HOOK,
				\ArvaSEO\Connector\Speed\AssetCache::HOOK_SWEEP,
				\ArvaSEO\Connector\Speed\OtherCaches::HOOK_CLEAR,
				\ArvaSEO\Connector\Speed\OtherCaches::HOOK_CHECK,
				\ArvaSEO\Connector\Speed\PageCache::HOOK_PRELOAD,
				\ArvaSEO\Connector\Speed\PageCache::HOOK_GC,
				\ArvaSEO\Connector\Speed\CssOptimizer::HOOK,
				\ArvaSEO\Connector\Maintenance\DatabaseCleanup::HOOK,
				\ArvaSEO\Connector\Maintenance\DatabaseCleanup::WEEKLY,
				\ArvaSEO\Connector\Speed\ImageCompression::HOOK,
				\ArvaSEO\Connector\Speed\WebpLibrary::HOOK,
				\ArvaSEO\Connector\Speed\LocalFonts::HOOK,
				\ArvaSEO\Connector\Speed\ExternalAssets::HOOK,
				\ArvaSEO\Connector\Security\Scanner::HOOK,
				\ArvaSEO\Connector\Security\Scanner::WEEKLY,
				\ArvaSEO\Connector\Security\DeepScan::HOOK,
				\ArvaSEO\Connector\Security\FileEdits::HOOK,
			)
		);
	}

	/**
	 * Stop everything (deactivation, uninstall): no event of the plugin stays in the cron list.
	 */
	public static function clear_all(): void {
		foreach ( self::all_hooks() as $hook ) {
			wp_unschedule_hook( $hook );
		}
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
	 * Whether this call is Arva SEO itself being deactivated or deleted. WordPress fires
	 * deactivated_plugin right after deactivate_{plugin} in the same request, and by then every job
	 * the plugin had was unscheduled: nothing may schedule another one.
	 *
	 * @param mixed $plugin The plugin file the hook passes (plugins only).
	 */
	public static function leaving( $plugin ): bool {
		return is_string( $plugin )
			&& plugin_basename( ARVASEO_CONNECTOR_FILE ) === $plugin
			&& in_array( current_action(), array( 'deactivated_plugin', 'deleted_plugin' ), true );
	}

	/**
	 * Arva SEO was deactivated: whatever other hooks of the same moment scheduled (a Cloudflare
	 * clear waiting for its gap, a registration) goes too, so no event of the plugin is left.
	 *
	 * @param mixed $plugin       The plugin file.
	 * @param mixed $network_wide Deactivated for the whole network.
	 */
	public static function after_leaving( $plugin, $network_wide = false ): void {
		if ( ! self::leaving( $plugin ) ) {
			return;
		}
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			) as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::clear_all();
				restore_current_blog();
			}
			return;
		}
		self::clear_all();
	}

	/**
	 * A plugin or theme changed.
	 *
	 * @param mixed $plugin The plugin file (plugin hooks), the new theme's name or the upgrader.
	 */
	public static function inventory_changed( $plugin = '' ): void {
		if ( self::leaving( $plugin ) ) {
			return;
		}
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
		// Optimized CSS of page types that are no longer tracked.
		\ArvaSEO\Connector\Speed\CssIndex::compact();
		\ArvaSEO\Connector\Speed\CssOptimizer::prune_results();
		// Generated files no page used for a week (Google Fonts copies, 30 days): the caches are
		// cleared first and the files deleted a day later (AssetCache), also while no folder is full,
		// but only when enough files can go or a folder is nearly full, and at most once a week.
		\ArvaSEO\Connector\Speed\AssetCache::rotate( true );
		// The caches in front of the site, from the homepage's headers.
		\ArvaSEO\Connector\Speed\OtherCaches::maintain();
		// Old versions of fixes whose day has come go back to Trying (D4), and old notes go.
		\ArvaSEO\Connector\Speed\Changes::maintain();
	}
}
