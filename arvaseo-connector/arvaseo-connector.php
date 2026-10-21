<?php
/**
 * Plugin Name:       Arva SEO – Speed Booster & SEO Connector
 * Description:       PageSpeed fixes you switch on one at a time, each for a named Lighthouse problem and tried first if you like. Connects this site to Arva SEO: shares a privacy-minimised plugin inventory and Website Health checks with your consent, shows evidence-backed fix guidance, and receives content you send from Arva SEO within the publishing limits you set here.
 * Version:           1.8.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Arva SEO
 * Author URI:        https://arvaseo.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       arvaseo-connector
 * Domain Path:       /languages
 *
 * @package ArvaSEO\Connector
 */

defined( 'ABSPATH' ) || exit;

define( 'ARVASEO_CONNECTOR_VERSION', '1.8.0' );
define( 'ARVASEO_CONNECTOR_FILE', __FILE__ );
define( 'ARVASEO_CONNECTOR_DIR', __DIR__ );

if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Arva SEO Connector needs PHP 8.1 or newer. Ask your host to update PHP; the plugin is not running.', 'arvaseo-connector' ) . '</p></div>';
		}
	);
	return;
}

require_once __DIR__ . '/includes/Autoloader.php';
\ArvaSEO\Connector\Autoloader::register( __DIR__ . '/includes' );

register_activation_hook( __FILE__, array( \ArvaSEO\Connector\Install\Lifecycle::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \ArvaSEO\Connector\Install\Lifecycle::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( \ArvaSEO\Connector\Plugin::class, 'boot' ) );
