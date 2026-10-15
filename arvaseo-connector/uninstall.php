<?php
/**
 * Uninstall: always remove and revoke credentials; remove other data only if the administrator
 * chose "Also delete settings, logs and delivery records". Posts are never deleted.
 *
 * @package ArvaSEO\Connector
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/Autoloader.php';
\ArvaSEO\Connector\Autoloader::register( __DIR__ . '/includes' );
if ( ! defined( 'ARVASEO_CONNECTOR_VERSION' ) ) {
	define( 'ARVASEO_CONNECTOR_VERSION', '1.1.0' );
	define( 'ARVASEO_CONNECTOR_FILE', __DIR__ . '/arvaseo-connector.php' );
	define( 'ARVASEO_CONNECTOR_DIR', __DIR__ );
}

\ArvaSEO\Connector\Install\UninstallService::run();
