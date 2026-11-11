<?php
/**
 * The broken-address log: which missing pages visitors ask for, and how often.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Seo;

use ArvaSEO\Connector\Install\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Off until an administrator turns on "Log broken addresses". For each missing page it keeps the
 * path (no query string), how many times it was asked for, and the first and last time. It never
 * keeps IP addresses, browsers, referring pages, cookies or user IDs, and sends nothing anywhere.
 * At most MAX_ROWS paths are kept: the one not asked for the longest goes first.
 */
final class NotFoundLog {

	/** The most paths kept. */
	public const MAX_ROWS = 1000;

	/**
	 * Hooks.
	 */
	public static function init(): void {
		// After WordPress has tried to guess the right page and redirect (priority 10), so only real dead ends are left.
		add_action( 'template_redirect', array( self::class, 'maybe_log' ), 99 );
	}

	/**
	 * Note this request when it is a missing page.
	 */
	public static function maybe_log(): void {
		if ( ! is_404() || Redirects::disabled() || ! Options::get( 'log_404' ) || is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'GET' !== $method ) {
			return;
		}
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Paths::normalize() cleans it before any use.
		$path = Paths::normalize( is_string( $uri ) ? $uri : '' );
		if ( null === $path || Paths::is_noise( $path ) ) {
			return;
		}
		/**
		 * Leave a missing address out of the broken-address log.
		 *
		 * @param bool   $ignore Whether to leave it out.
		 * @param string $path   The path (no query string, lower case).
		 */
		if ( apply_filters( 'arvaseo_connector_404_ignore', false, $path ) ) {
			return;
		}
		self::record( $path );
	}

	/**
	 * Count one more request for a path.
	 *
	 * @param string $path Normalized path.
	 */
	public static function record( string $path ): void {
		global $wpdb;
		$table = Schema::table( 'not_found' );
		$now   = gmdate( 'Y-m-d H:i:s' );
		$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (path_hash, path, hits, first_seen, last_seen) VALUES (%s, %s, 1, %s, %s) ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = %s', $table, md5( $path ), $path, $now, $now, $now ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		// MySQL reports 1 affected row for a new path and 2 for one that was counted again.
		if ( 1 === (int) $wpdb->rows_affected ) {
			self::prune();
		}
	}

	/**
	 * Keep at most MAX_ROWS paths: drop the ones asked for longest ago.
	 */
	public static function prune(): void {
		global $wpdb;
		$table = Schema::table( 'not_found' );
		$extra = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ) - self::MAX_ROWS; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		if ( $extra > 0 ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i ORDER BY last_seen ASC, id ASC LIMIT %d', $table, $extra ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		}
	}

	/**
	 * The most asked-for missing addresses.
	 *
	 * @param int $limit How many.
	 * @return list<array{id: int, path: string, hits: int, first_seen: string, last_seen: string}>
	 */
	public static function top( int $limit = 50 ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, path, hits, first_seen, last_seen FROM %i ORDER BY hits DESC, last_seen DESC, id DESC LIMIT %d', Schema::table( 'not_found' ), max( 1, min( self::MAX_ROWS, $limit ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		$list = array();
		foreach ( (array) $rows as $row ) {
			$list[] = array(
				'id'         => (int) $row['id'],
				'path'       => (string) $row['path'],
				'hits'       => (int) $row['hits'],
				'first_seen' => (string) $row['first_seen'],
				'last_seen'  => (string) $row['last_seen'],
			);
		}
		return $list;
	}

	/**
	 * How many paths are logged.
	 */
	public static function count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Schema::table( 'not_found' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
	}

	/**
	 * Take one path out of the log (it has a redirect now, or the administrator removed it).
	 *
	 * @param string $path Normalized path.
	 */
	public static function forget( string $path ): void {
		global $wpdb;
		$wpdb->delete( Schema::table( 'not_found' ), array( 'path_hash' => md5( $path ) ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
	}

	/**
	 * Take one row out of the log.
	 *
	 * @param int $id Row ID.
	 */
	public static function remove( int $id ): void {
		global $wpdb;
		$wpdb->delete( Schema::table( 'not_found' ), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
	}

	/**
	 * Empty the log.
	 */
	public static function clear(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Schema::table( 'not_found' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
	}
}
