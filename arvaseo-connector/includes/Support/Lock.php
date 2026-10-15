<?php
/**
 * A simple cross-request lock.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Uses add_option(), which inserts atomically (the option name is unique), so two requests
 * can't both take the lock. A lock older than its lifetime is considered abandoned.
 */
final class Lock {

	/**
	 * Try to take a lock.
	 *
	 * @param string $name    Lock name.
	 * @param int    $seconds Lifetime.
	 */
	public static function acquire( string $name, int $seconds ): bool {
		$option = 'arvaseo_connector_lock_' . $name;
		if ( add_option( $option, time() + $seconds, '', false ) ) {
			return true;
		}
		$expires = (int) get_option( $option, 0 );
		if ( $expires > 0 && $expires < time() ) {
			delete_option( $option );
			return add_option( $option, time() + $seconds, '', false );
		}
		return false;
	}

	/**
	 * Release a lock.
	 *
	 * @param string $name Lock name.
	 */
	public static function release( string $name ): void {
		delete_option( 'arvaseo_connector_lock_' . $name );
	}

	/**
	 * Run a callback under a lock; returns null when the lock is taken.
	 *
	 * @param string   $name     Lock name.
	 * @param int      $seconds  Lifetime.
	 * @param callable $callback Work.
	 * @return mixed
	 */
	public static function with( string $name, int $seconds, callable $callback ) {
		if ( ! self::acquire( $name, $seconds ) ) {
			return null;
		}
		try {
			return $callback();
		} finally {
			self::release( $name );
		}
	}
}
