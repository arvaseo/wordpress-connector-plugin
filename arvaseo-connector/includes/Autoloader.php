<?php
/**
 * PSR-4 style autoloader for the ArvaSEO\Connector namespace (no Composer at runtime).
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector;

defined( 'ABSPATH' ) || exit;

/**
 * Maps ArvaSEO\Connector\Foo\Bar to includes/Foo/Bar.php.
 */
final class Autoloader {

	/**
	 * Base directory for class files.
	 *
	 * @var string
	 */
	private static string $base = '';

	/**
	 * Register the autoloader.
	 *
	 * @param string $base Directory holding the namespace root.
	 */
	public static function register( string $base ): void {
		self::$base = rtrim( $base, '/\\' );
		spl_autoload_register( array( self::class, 'load' ) );
	}

	/**
	 * Load one class.
	 *
	 * @param string $class_name Fully qualified class name.
	 */
	public static function load( string $class_name ): void {
		$prefix = __NAMESPACE__ . '\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$relative = substr( $class_name, strlen( $prefix ) );
		if ( ! preg_match( '/^[A-Za-z0-9_\\\\]+$/', $relative ) ) {
			return;
		}
		$file = self::$base . '/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
}
