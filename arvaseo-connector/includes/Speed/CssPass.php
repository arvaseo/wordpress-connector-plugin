<?php
/**
 * Let one cached request reach WordPress while CSS waits and ready notifications cannot reach it.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Speed;

use ArvaSEO\Connector\Auth\TokenStore;

defined( 'ABSPATH' ) || exit;

/** Uses a marker and atomic five-minute claims; cached HTML and optimized CSS files stay intact. */
final class CssPass {

	public const RULES = 'arvaseo_connector_css_pass_rules';

	/** Reconcile the marker after WordPress starts and after another cache was cleared. */
	public static function init(): void {
		add_action( 'init', array( self::class, 'reconcile' ), 20 );
		add_action( 'arvaseo_connector_pages_changed', array( self::class, 'reconcile' ), PHP_INT_MAX );
	}

	/** Hook adapter: reconciliation has no return value for WordPress actions. */
	public static function reconcile(): void {
		self::refresh();
	}

	/** Keep the marker only while a confirmed failed notification is holding up CSS delivery. */
	public static function refresh(): bool {
		$needed = TokenStore::is_connected() && PageCache::active() && false === CssDelivery::status()['notify_ok'] && CssOptimizer::wanted() && CssOptimizer::awaiting_delivery();
		if ( ! $needed ) {
			self::clear();
			return false;
		}
		$root = PageCache::dir();
		if ( is_link( $root ) || ! wp_mkdir_p( $root ) ) {
			return false;
		}
		$flag = $root . '/arvaseo-pass';
		if ( is_link( $flag ) ) {
			return false;
		}
		if ( ! is_file( $flag ) ) {
			$handle = @fopen( $flag, 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- atomic marker in our cache folder.
			if ( false === $handle ) {
				return false;
			}
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- same atomic marker.
			if ( ! doing_action( 'shutdown' ) && ! wp_doing_cron() ) {
				CssDelivery::send_soon();
			}
		}
		self::prune_claims( false );
		return true;
	}

	/** Remove only this delivery marker and its claims, never a cached page. */
	public static function clear(): void {
		$root = PageCache::dir();
		if ( is_link( $root ) ) {
			return;
		}
		$flag = $root . '/arvaseo-pass';
		if ( is_file( $flag ) && ! is_link( $flag ) ) {
			wp_delete_file( $flag );
		}
		self::prune_claims( true );
	}

	/**
	 * Claims have a bounded lifetime; other cache files and unknown names are left alone.
	 *
	 * @param bool $all Remove current claims as well.
	 */
	private static function prune_claims( bool $all ): void {
		foreach ( array_slice( glob( PageCache::dir() . '/arvaseo-pass-*' ) ?: array(), 0, 500 ) as $file ) {
			if ( ! is_link( $file ) && preg_match( '/^arvaseo-pass-(?:php-[0-9]+|[0-9]{10}-[0-5]-[05])$/D', basename( $file ) ) && ( $all || filemtime( $file ) < time() - HOUR_IN_SECONDS ) ) {
				wp_delete_file( $file );
			}
		}
	}

	/**
	 * Classic mod_rewrite conditions, including on servers that do not support ap_expr file().
	 * A claim made by the drop-in closes the window immediately, including concurrent requests.
	 *
	 * @param string $dir Absolute cache folder.
	 * @return string[]
	 */
	public static function apache_rules( string $dir ): array {
		$lines = array();
		foreach ( array( array( '[0-4]', '0' ), array( '[5-9]', '5' ) ) as list( $range, $floor ) ) {
			$lines[] = 'RewriteCond "' . $dir . '/arvaseo-pass" -f';
			$lines[] = 'RewriteCond %{TIME_MIN} ^([0-5])' . $range . '$';
			$lines[] = 'RewriteRule ^ - [E=ARVA_CSS_SLOT:%{TIME_YEAR}%{TIME_MON}%{TIME_DAY}%{TIME_HOUR}-%1-' . $floor . ']';
		}
		$lines[] = 'RewriteCond "' . $dir . '/arvaseo-pass" -f';
		$lines[] = 'RewriteCond %{ENV:ARVA_CSS_SLOT} ^[0-9]{10}-[0-5]-[05]$';
		$lines[] = 'RewriteCond "' . $dir . '/arvaseo-pass-%{ENV:ARVA_CSS_SLOT}" !-f';
		$lines[] = 'RewriteRule ^ - [E=ARVA_CSS_PASS:1]';
		return $lines;
	}
}
