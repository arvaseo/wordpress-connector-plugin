<?php
/**
 * The privacy-minimised plugin and theme inventory.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Inventory;

use ArvaSEO\Connector\Api\ApiClient;
use ArvaSEO\Connector\Auth\TokenStore;
use ArvaSEO\Connector\Logging\AuditLogger;
use ArvaSEO\Connector\Privacy\PrivacyService;
use ArvaSEO\Connector\Support\Ids;
use ArvaSEO\Connector\Support\Lock;
use ArvaSEO\Connector\Sync\SyncState;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the inventory from WordPress's own APIs (get_plugins, get_mu_plugins, get_dropins,
 * wp_get_themes, the update transients and the auto-update options) and sends only the
 * documented fields. It never forces an update check: when WordPress's update data is missing
 * or older than two days, updates are reported as unknown or stale, never as "up to date".
 */
final class PluginInventoryService {

	/** Update data older than this is stale. */
	public const STALE_AFTER = 2 * DAY_IN_SECONDS;

	/**
	 * Build and upload the inventory when it changed (or when forced).
	 *
	 * @param bool $force Upload even if unchanged.
	 * @return array{status: string, inventory_id?: string}
	 */
	public static function sync( bool $force = false ): array {
		if ( ! TokenStore::is_connected() || ! TokenStore::has_scope( 'plugin-inventory:write' ) ) {
			return array( 'status' => 'not_connected' );
		}
		if ( ! PrivacyService::allows( 'inventory' ) ) {
			return array( 'status' => 'no_consent' );
		}
		$result = Lock::with(
			'inventory',
			120,
			static function () use ( $force ) {
				$payload = self::payload();
				$hash    = Ids::hash(
					array(
						'plugins' => $payload['plugins'],
						'themes'  => $payload['themes'],
					)
				);
				$last    = SyncState::get( 'inventory' );
				if ( ! $force && ( $last['hash'] ?? '' ) === $hash && ( $last['status'] ?? '' ) === 'uploaded' && ( (int) ( $last['at'] ?? 0 ) ) > time() - 12 * HOUR_IN_SECONDS ) {
					return array(
						'status'       => 'unchanged',
						'inventory_id' => $last['id'] ?? null,
					);
				}
				$response = ApiClient::instance()->site( 'POST', '/inventory', $payload );
				if ( $response->ok() ) {
					SyncState::success(
						'inventory',
						array(
							'id'      => $payload['inventory_id'],
							'hash'    => $hash,
							'plugins' => count( $payload['plugins'] ),
							'themes'  => count( $payload['themes'] ),
						)
					);
					AuditLogger::info(
						'inventory.uploaded',
						__( 'Plugin inventory sent to Arva SEO.', 'arvaseo-connector' ),
						array(
							'inventory_id' => $payload['inventory_id'],
							'count'        => count( $payload['plugins'] ),
						)
					);
					return array(
						'status'       => 'uploaded',
						'inventory_id' => $payload['inventory_id'],
					);
				}
				SyncState::failure( 'inventory', $response );
				AuditLogger::warning(
					'inventory.failed',
					__( 'Couldn’t send the plugin inventory.', 'arvaseo-connector' ),
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
	 * The upload payload.
	 *
	 * @return array<string, mixed>
	 */
	public static function payload(): array {
		return array(
			'schema_version'       => '1.0',
			'site_registration_id' => (string) TokenStore::site_id(),
			'inventory_id'         => Ids::uuid(),
			'generated_at'         => Ids::iso(),
			'plugins'              => self::plugins(),
			'themes'               => self::themes(),
		);
	}

	/**
	 * Standard, must-use and drop-in plugins with their state.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function plugins(): array {
		self::load_admin();
		$updates      = get_site_transient( 'update_plugins' );
		$update_state = self::update_state( $updates );
		$auto         = (array) get_site_option( 'auto_update_plugins', array() );
		$auto_on      = function_exists( 'wp_is_auto_update_enabled_for_type' ) && wp_is_auto_update_enabled_for_type( 'plugin' );
		$items        = array();

		foreach ( get_plugins() as $file => $data ) {
			$offered = is_object( $updates ) && isset( $updates->response[ $file ] ) ? $updates->response[ $file ] : null;
			$known   = is_object( $updates ) && ( isset( $updates->no_update[ $file ] ) || isset( $updates->response[ $file ] ) );
			$items[] = array(
				'id'                  => self::clean_id( $file ),
				'name'                => self::clean_text( (string) ( $data['Name'] ?? $file ) ),
				'version'             => self::clean_version( $data['Version'] ?? null ),
				'type'                => 'standard',
				'active'              => is_plugin_active( $file ),
				'network_active'      => is_multisite() && is_plugin_active_for_network( $file ),
				'update_available'    => self::availability( $update_state, $known, null !== $offered ),
				'update_check_status' => $update_state['status'],
				'available_version'   => null !== $offered && isset( $offered->new_version ) ? self::clean_version( $offered->new_version ) : null,
				'update_data_source'  => null === $updates ? 'none' : 'wordpress_update_transient',
				'last_checked'        => $update_state['last_checked'],
				'auto_update'         => $auto_on ? in_array( $file, $auto, true ) : false,
			);
		}
		foreach ( get_mu_plugins() as $file => $data ) {
			$items[] = self::special( $file, $data, 'must_use' );
		}
		foreach ( get_dropins() as $file => $data ) {
			$items[] = self::special( $file, $data, 'dropin' );
		}
		return array_values( array_filter( $items, static fn ( $item ) => '' !== $item['id'] ) );
	}

	/**
	 * Installed themes with their relationship to the active theme.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function themes(): array {
		$updates      = get_site_transient( 'update_themes' );
		$update_state = self::update_state( $updates );
		$auto         = (array) get_site_option( 'auto_update_themes', array() );
		$auto_on      = function_exists( 'wp_is_auto_update_enabled_for_type' ) && wp_is_auto_update_enabled_for_type( 'theme' );
		$active       = get_stylesheet();
		$parent       = get_template();
		$items        = array();

		foreach ( wp_get_themes() as $stylesheet => $theme ) {
			$offered  = is_object( $updates ) && isset( $updates->response[ $stylesheet ] ) ? $updates->response[ $stylesheet ] : null;
			$known    = is_object( $updates ) && ( isset( $updates->no_update[ $stylesheet ] ) || isset( $updates->response[ $stylesheet ] ) );
			$template = $theme->get_template();
			$role     = $template !== $stylesheet ? 'child' : ( $stylesheet === $parent && $parent !== $active ? 'parent' : 'standalone' );
			$items[]  = array(
				'stylesheet'          => self::clean_id( (string) $stylesheet ),
				'name'                => self::clean_text( (string) $theme->get( 'Name' ) ),
				'version'             => self::clean_version( $theme->get( 'Version' ) ),
				'active'              => $stylesheet === $active,
				'role'                => $role,
				'parent'              => 'child' === $role ? self::clean_id( $template ) : null,
				'update_available'    => self::availability( $update_state, $known, null !== $offered ),
				'update_check_status' => $update_state['status'],
				'available_version'   => is_array( $offered ) && isset( $offered['new_version'] ) ? self::clean_version( $offered['new_version'] ) : null,
				'update_data_source'  => null === $updates ? 'none' : 'wordpress_update_transient',
				'last_checked'        => $update_state['last_checked'],
				'auto_update'         => $auto_on ? in_array( $stylesheet, $auto, true ) : false,
			);
		}
		return $items;
	}

	/**
	 * Whether WordPress's update data is current, stale, missing or unreadable.
	 *
	 * @param mixed $transient Update transient.
	 * @return array{status: string, last_checked: ?string}
	 */
	public static function update_state( $transient ): array {
		if ( false === $transient || null === $transient ) {
			return array(
				'status'       => 'unavailable',
				'last_checked' => null,
			);
		}
		if ( ! is_object( $transient ) || ! isset( $transient->last_checked ) ) {
			return array(
				'status'       => 'error',
				'last_checked' => null,
			);
		}
		$checked = (int) $transient->last_checked;
		return array(
			'status'       => $checked < time() - self::STALE_AFTER ? 'stale' : 'current',
			'last_checked' => $checked > 0 ? Ids::iso( $checked ) : null,
		);
	}

	/**
	 * Update availability: true, false or null when unknown. Missing or stale data is never false.
	 *
	 * @param array{status: string, last_checked: ?string} $state   Update state.
	 * @param bool                                         $known   Listed in the transient.
	 * @param bool                                         $offered An update is listed.
	 */
	private static function availability( array $state, bool $known, bool $offered ): ?bool {
		if ( $offered ) {
			return true; // Evidence of an update, even from older data.
		}
		if ( 'current' !== $state['status'] || ! $known ) {
			return null;
		}
		return false;
	}

	/**
	 * A must-use plugin or drop-in (no update data from WordPress.org).
	 *
	 * @param string               $file File.
	 * @param array<string, mixed> $data Header data.
	 * @param string               $type must_use|dropin.
	 * @return array<string, mixed>
	 */
	private static function special( string $file, array $data, string $type ): array {
		return array(
			'id'                  => self::clean_id( $file ),
			'name'                => self::clean_text( (string) ( $data['Name'] ?? $file ) ?: $file ),
			'version'             => self::clean_version( $data['Version'] ?? null ),
			'type'                => $type,
			'active'              => true,
			'network_active'      => false,
			'update_available'    => null,
			'update_check_status' => 'unavailable',
			'available_version'   => null,
			'update_data_source'  => 'none',
			'last_checked'        => null,
			'auto_update'         => null,
		);
	}

	/**
	 * A relative identifier (folder/file.php), never a path.
	 *
	 * @param string $id Basename.
	 */
	private static function clean_id( string $id ): string {
		$id = ltrim( str_replace( '\\', '/', $id ), '/' );
		if ( str_contains( $id, '..' ) || ! preg_match( '#^[A-Za-z0-9][A-Za-z0-9_.\-/]{0,199}$#', $id ) ) {
			return '';
		}
		return $id;
	}

	/**
	 * Plain text without markup, bounded.
	 *
	 * @param string $text Text.
	 */
	private static function clean_text( string $text ): string {
		$text = trim( preg_replace( '/[\x00-\x1F\x7F<>]/u', '', html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES ) ) ?? '' );
		return mb_substr( $text, 0, 200 );
	}

	/**
	 * A version string, bounded (null when absent).
	 *
	 * @param mixed $version Version.
	 */
	private static function clean_version( $version ): ?string {
		if ( ! is_scalar( $version ) || '' === trim( (string) $version ) ) {
			return null;
		}
		return mb_substr( self::clean_text( (string) $version ), 0, 40 );
	}

	/**
	 * The admin includes the plugin APIs need (cron and REST contexts don't load them).
	 */
	private static function load_admin(): void {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
	}
}
