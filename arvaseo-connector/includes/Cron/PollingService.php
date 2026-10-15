<?php
/**
 * Polling Arva SEO.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Cron;

use ArvaSEO\Connector\Api\ApiClient;
use ArvaSEO\Connector\Auth\AuthService;
use ArvaSEO\Connector\Auth\TokenStore;
use ArvaSEO\Connector\Content\ContentDeliveryService;
use ArvaSEO\Connector\Content\SeoUpdateService;
use ArvaSEO\Connector\Logging\AuditLogger;
use ArvaSEO\Connector\Settings;
use ArvaSEO\Connector\Site\SiteRegistrationService;
use ArvaSEO\Connector\Support\Lock;
use ArvaSEO\Connector\Sync\SyncState;

defined( 'ABSPATH' ) || exit;

/**
 * Recovers anything a pushed event missed: reads the connection status (a requested recheck,
 * entitlements, a revocation), collects content and SEO title and description updates waiting
 * for this site, and retries results that didn't get through.
 */
final class PollingService {

	/**
	 * One poll.
	 *
	 * @return array{status: string, deliveries?: int, seo_updates?: int}
	 */
	public static function poll(): array {
		if ( ! TokenStore::is_connected() ) {
			return array( 'status' => 'not_connected' );
		}
		if ( ! SyncState::may_attempt( 'poll' ) ) {
			return array( 'status' => 'backoff' );
		}
		$result = Lock::with( 'poll', 240, array( self::class, 'run' ) );
		return $result ?? array( 'status' => 'locked' );
	}

	/**
	 * The work, under the lock.
	 *
	 * @return array{status: string, deliveries?: int, seo_updates?: int}
	 */
	public static function run(): array {
		$client = ApiClient::instance();
		$site   = $client->site( 'GET', '' );
		if ( ! $site->ok() ) {
			if ( 401 !== $site->status ) {
				SyncState::failure( 'poll', $site );
			}
			return array( 'status' => 'failed' );
		}
		$data = $site->data();
		if ( 'revoked' === ( $data['connection_status'] ?? '' ) ) {
			AuthService::forget( 'revoked_in_arvaseo' );
			return array( 'status' => 'revoked' );
		}
		TokenStore::update_connection(
			array(
				'push_status'  => in_array( $data['push_status'] ?? '', array( 'unverified', 'verified', 'unreachable' ), true ) ? $data['push_status'] : 'unverified',
				'entitlements' => array_map( 'boolval', array_intersect_key( (array) ( $data['entitlements'] ?? array() ), array_flip( array( 'connector', 'health_analysis', 'vulnerability_intelligence', 'publishing' ) ) ) ),
				'app_url'      => ApiClient::app_link( $data['app_url'] ?? null ),
			)
		);
		if ( ! empty( $data['refresh_requested'] ) && ( (int) ( SyncState::get( 'health' )['at'] ?? 0 ) ) < time() - 10 * MINUTE_IN_SECONDS ) {
			CronManager::run_soon( 'arvaseo_connector_health', 10 );
		}
		// Re-register when the publishing ceiling changed, or the SEO plugin in use (activated or
		// deactivated since) no longer matches what Arva SEO was told.
		$connection = TokenStore::connection();
		if ( ( $connection['registered_policy'] ?? null ) !== Settings::get( 'publishing_mode' ) || ( $connection['registered_seo'] ?? null ) !== SiteRegistrationService::seo() ) {
			SiteRegistrationService::register();
		}

		$processed  = 0;
		$publishing = (bool) ( TokenStore::connection()['entitlements']['publishing'] ?? true );
		if ( $publishing && TokenStore::has_scope( 'content-delivery:read' ) ) {
			$pending = $client->site( 'GET', '/deliveries/pending' );
			if ( $pending->ok() ) {
				foreach ( array_slice( $pending->data(), 0, 5 ) as $payload ) {
					if ( is_array( $payload ) ) {
						ContentDeliveryService::handle( $payload );
						++$processed;
					}
				}
			} elseif ( 403 !== $pending->status ) {
				SyncState::failure( 'poll', $pending );
				return array( 'status' => 'failed' );
			}
		}
		$seo = 0;
		if ( $publishing && TokenStore::has_scope( 'content-delivery:read' ) ) {
			$pending = $client->site( 'GET', '/seo-updates/pending' );
			if ( $pending->ok() ) {
				foreach ( array_slice( $pending->data(), 0, 50 ) as $payload ) {
					if ( is_array( $payload ) ) {
						SeoUpdateService::handle( $payload );
						++$seo;
					}
				}
				if ( $seo >= 50 ) {
					CronManager::run_soon( 'arvaseo_connector_poll', 30 ); // More are waiting.
				}
			} elseif ( ! in_array( $pending->status, array( 403, 404 ), true ) ) {
				SyncState::failure( 'poll', $pending );
				return array( 'status' => 'failed' );
			}
		}
		$acks = ContentDeliveryService::flush_outbox() + SeoUpdateService::flush_outbox();
		SyncState::success( 'poll', array( 'deliveries' => $processed ) );
		if ( $processed > 0 || $seo > 0 || $acks > 0 ) {
			AuditLogger::info(
				'poll.completed',
				__( 'Checked Arva SEO for content.', 'arvaseo-connector' ),
				array(
					'count'       => $processed,
					'seo_updates' => $seo,
				)
			);
		}
		return array(
			'status'      => 'ok',
			'deliveries'  => $processed,
			'seo_updates' => $seo,
		);
	}
}
