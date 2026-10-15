<?php
/**
 * The connector's capabilities.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Install;

defined( 'ABSPATH' ) || exit;

/**
 * Custom capabilities, given to administrators only by default. Site owners can delegate them
 * with a role editor (see docs/admin-guide.md); each one is checked separately.
 */
final class Capabilities {

	/** Connect, reconnect, disconnect, change settings and publishing limits, export diagnostics. */
	public const MANAGE = 'manage_arvaseo';

	/** Run a plugin inventory sync. */
	public const SYNC_INVENTORY = 'sync_arvaseo_inventory';

	/** See Website Health and fix guidance. */
	public const VIEW_HEALTH = 'view_arvaseo_health';

	/** Run a Website Health check. */
	public const SYNC_HEALTH = 'sync_arvaseo_health';

	/** See Content Sync and retry or review deliveries. */
	public const PUBLISH_CONTENT = 'publish_arvaseo_content';

	/**
	 * All capabilities.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array( self::MANAGE, self::SYNC_INVENTORY, self::VIEW_HEALTH, self::SYNC_HEALTH, self::PUBLISH_CONTENT );
	}

	/**
	 * Give administrators every capability (current site).
	 */
	public static function grant(): void {
		$role = get_role( 'administrator' );
		if ( null === $role ) {
			return;
		}
		foreach ( self::all() as $cap ) {
			$role->add_cap( $cap );
		}
	}

	/**
	 * Remove the capabilities from every role (uninstall with data removal).
	 */
	public static function revoke(): void {
		foreach ( wp_roles()->role_objects as $role ) {
			foreach ( self::all() as $cap ) {
				$role->remove_cap( $cap );
			}
		}
	}
}
