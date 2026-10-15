<?php
/**
 * Website Health snapshots.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Health;

use ArvaSEO\Connector\Api\ApiClient;
use ArvaSEO\Connector\Auth\TokenStore;
use ArvaSEO\Connector\Inventory\PluginInventoryService;
use ArvaSEO\Connector\Logging\AuditLogger;
use ArvaSEO\Connector\Privacy\PrivacyService;
use ArvaSEO\Connector\Support\Ids;
use ArvaSEO\Connector\Support\Lock;
use ArvaSEO\Connector\Sync\SyncState;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the checks in the background, keeps the latest two results locally (so the Website Health
 * tab can show results and what changed even before Arva SEO's assessment arrives), and uploads
 * the snapshot when connected and consented. Arva SEO accepts it at once and assesses it later.
 */
final class HealthSnapshotService {

	private const LATEST = 'arvaseo_connector_health_latest';

	private const PREVIOUS = 'arvaseo_connector_health_previous';

	/**
	 * Collect, store and upload.
	 *
	 * @param bool $force Upload even when nothing changed.
	 * @return array{status: string, snapshot_id?: string}
	 */
	public static function run( bool $force = false ): array {
		$result = Lock::with(
			'health',
			300,
			static function () use ( $force ) {
				$inventory = PluginInventoryService::sync();
				$snapshot  = self::build( $inventory['inventory_id'] ?? ( SyncState::get( 'inventory' )['id'] ?? null ) );
				$hash      = Ids::hash(
					array(
						'wordpress' => $snapshot['wordpress'],
						'runtime'   => $snapshot['runtime'],
						'checks'    => array_map(
							static function ( $check ) {
								unset( $check['observed_at'] );
								return $check;
							},
							$snapshot['checks']
						),
					)
				);
				self::remember( $snapshot );
				AuditLogger::info(
					'health.collected',
					__( 'Website Health checks ran.', 'arvaseo-connector' ),
					array(
						'snapshot_id' => $snapshot['snapshot_id'],
						'checks'      => $snapshot['summary'],
					)
				);

				if ( ! TokenStore::is_connected() || ! TokenStore::has_scope( 'health-snapshot:write' ) ) {
					return array( 'status' => 'collected_locally' );
				}
				if ( ! PrivacyService::allows( 'health' ) ) {
					return array( 'status' => 'no_consent' );
				}
				$last = SyncState::get( 'health' );
				if ( ! $force && ( $last['hash'] ?? '' ) === $hash && ( $last['status'] ?? '' ) === 'uploaded' && (int) ( $last['at'] ?? 0 ) > time() - 20 * HOUR_IN_SECONDS ) {
					return array( 'status' => 'unchanged' );
				}
				$response = ApiClient::instance()->site( 'POST', '/health-snapshots', $snapshot );
				if ( $response->ok() ) {
					SyncState::success(
						'health',
						array(
							'id'   => $snapshot['snapshot_id'],
							'hash' => $hash,
						)
					);
					AuditLogger::info( 'health.uploaded', __( 'Website Health snapshot sent to Arva SEO for assessment.', 'arvaseo-connector' ), array( 'snapshot_id' => $snapshot['snapshot_id'] ) );
					// Fetch the assessment a little later; Arva SEO assesses in the background.
					wp_schedule_single_event( time() + 2 * MINUTE_IN_SECONDS, 'arvaseo_connector_assessment' );
					return array(
						'status'      => 'uploaded',
						'snapshot_id' => $snapshot['snapshot_id'],
					);
				}
				SyncState::failure( 'health', $response );
				AuditLogger::warning(
					'health.failed',
					__( 'Couldn’t send the Website Health snapshot.', 'arvaseo-connector' ),
					array(
						'http_status' => $response->status,
						'code'        => $response->error_code,
					)
				);
				return array( 'status' => 'failed' );
			}
		);
		return $result ?? array( 'status' => 'locked' );
	}

	/**
	 * The snapshot payload (only documented fields).
	 *
	 * @param string|null $inventory_id Inventory sent with it.
	 * @return array<string, mixed>
	 */
	public static function build( ?string $inventory_id = null ): array {
		global $wpdb;
		$checks  = HealthCheckRegistry::run( true );
		$summary = array(
			'critical'    => 0,
			'recommended' => 0,
			'good'        => 0,
			'unknown'     => 0,
		);
		foreach ( $checks as $check ) {
			++$summary[ $check['check_status'] ];
		}
		$database = array(
			'family'  => 'unknown',
			'version' => null,
		);
		foreach ( $checks as $check ) {
			if ( 'runtime.database' === $check['check_id'] && is_array( $check['observed_value'] ) ) {
				$database = $check['observed_value'];
			}
		}
		if ( 'unknown' === $database['family'] && isset( $wpdb ) ) {
			$info               = method_exists( $wpdb, 'db_server_info' ) ? (string) $wpdb->db_server_info() : '';
			$database['family'] = stripos( $info, 'mariadb' ) !== false ? 'mariadb' : ( '' !== $info ? 'mysql' : 'unknown' );
		}
		return array(
			'schema_version'               => '1.0',
			'site_registration_id'         => (string) TokenStore::site_id(),
			'snapshot_id'                  => Ids::uuid(),
			'generated_at'                 => Ids::iso(),
			'wordpress'                    => array(
				'version'          => preg_match( '/^\d+(\.\d+){0,3}/', (string) get_bloginfo( 'version' ), $m ) ? $m[0] : '0',
				'multisite'        => is_multisite(),
				'environment_type' => wp_get_environment_type(),
			),
			'runtime'                      => array(
				'php_version'             => preg_match( '/^\d+(\.\d+){0,3}/', PHP_VERSION, $m ) ? $m[0] : '0',
				'database_server_family'  => $database['family'],
				'database_server_version' => $database['version'],
			),
			'summary'                      => $summary,
			'checks'                       => $checks,
			'plugin_inventory_snapshot_id' => Ids::is_uuid( $inventory_id ) ? $inventory_id : null,
		);
	}

	/**
	 * The latest local result.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function latest(): ?array {
		$value = get_option( self::LATEST );
		return is_array( $value ) ? $value : null;
	}

	/**
	 * Checks whose result changed since the previous local result.
	 *
	 * @return array<int, array{check_id: string, from: ?string, to: ?string}>
	 */
	public static function changes(): array {
		$latest   = self::latest();
		$previous = get_option( self::PREVIOUS );
		if ( null === $latest || ! is_array( $previous ) ) {
			return array();
		}
		$before = array_column( $previous['checks'], 'check_status', 'check_id' );
		$after  = array_column( $latest['checks'], 'check_status', 'check_id' );
		$out    = array();
		foreach ( array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) ) as $id ) {
			if ( ( $before[ $id ] ?? null ) !== ( $after[ $id ] ?? null ) ) {
				$out[] = array(
					'check_id' => $id,
					'from'     => $before[ $id ] ?? null,
					'to'       => $after[ $id ] ?? null,
				);
			}
		}
		return $out;
	}

	/**
	 * Keep the latest and previous results.
	 *
	 * @param array<string, mixed> $snapshot Snapshot.
	 */
	private static function remember( array $snapshot ): void {
		$latest = self::latest();
		if ( null !== $latest ) {
			update_option( self::PREVIOUS, $latest, false );
		}
		update_option( self::LATEST, $snapshot, false );
	}

	/**
	 * Remove local results (uninstall).
	 */
	public static function clear(): void {
		delete_option( self::LATEST );
		delete_option( self::PREVIOUS );
	}
}
