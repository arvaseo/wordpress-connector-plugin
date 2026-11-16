<?php
/**
 * A resumable dump of this site's database tables as SQL text.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Backup;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- a backup streams large files and dumps in bounded steps; WP_Filesystem reads whole files into memory.

/**
 * Writes "DROP TABLE / CREATE TABLE / INSERT" statements for every table whose name starts with this site's table
 * prefix, into a stream, a bounded number of rows at a time, so a large database never sits in memory and a slow host
 * can stop and carry on from the cursor in the next request. The cursor is {table index, row offset}. Nothing here
 * reads files or sends anything.
 */
final class DatabaseDump {

	/** Rows read per query. */
	public const BATCH = 500;

	/**
	 * Tables to dump: this site's own (prefix), most-recent-first order is not needed, so name order.
	 *
	 * @return string[]
	 */
	public static function tables(): array {
		global $wpdb;
		$like   = $wpdb->esc_like( $wpdb->prefix ) . '%';
		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- the list of this site's tables.
		$tables = array_values( array_filter( (array) $tables, 'is_string' ) );
		sort( $tables );
		// In a network the main site's prefix also matches the other sites' tables (wp_2_posts): leave those out.
		if ( is_multisite() && is_main_site() ) {
			$other  = '/^' . preg_quote( $wpdb->prefix, '/' ) . '\d+_/';
			$tables = array_values( array_filter( $tables, static fn( $t ) => 1 !== preg_match( $other, $t ) ) );
		}
		/**
		 * The tables a backup holds (this site's own by default).
		 *
		 * @param string[] $tables Table names.
		 */
		return array_values( array_filter( (array) apply_filters( 'arvaseo_connector_backup_tables', $tables ), 'is_string' ) );
	}

	/**
	 * Write more of the dump, within a time budget.
	 *
	 * @param resource                                     $out     A writable stream.
	 * @param array{table: int, offset: int, header: bool} $cursor  Where the last call stopped (updated).
	 * @param string[]                                     $tables  The tables, from tables().
	 * @param float                                        $seconds Time budget.
	 * @param int                                          $max_bytes Stop after about this many bytes written (one part).
	 * @return bool True when every table is written.
	 */
	public static function write( $out, array &$cursor, array $tables, float $seconds, int $max_bytes ): bool {
		global $wpdb;
		$started = microtime( true );
		$written = 0;
		if ( empty( $cursor['header'] ) ) {
			$written         += (int) fwrite( $out, "-- Arva SEO backup\n-- Tables: " . count( $tables ) . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n" );
			$cursor['header'] = true;
		}
		$count = count( $tables );
		while ( $cursor['table'] < $count ) {
			$table = $tables[ $cursor['table'] ];
			if ( 0 === $cursor['offset'] ) {
				$create = $wpdb->get_row( 'SHOW CREATE TABLE `' . str_replace( '`', '``', $table ) . '`', ARRAY_N ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared -- the table name comes from SHOW TABLES, backtick-escaped.
				if ( ! is_array( $create ) || empty( $create[1] ) ) {
					++$cursor['table'];
					continue;
				}
				$written += (int) fwrite( $out, 'DROP TABLE IF EXISTS `' . $table . "`;\n" . $create[1] . ";\n\n" );
			}
			$key_column = self::order_column( $table );
			while ( true ) {
				// The table name comes from SHOW TABLES and is backtick-escaped; the ORDER BY from the table's own keys; the numbers are placeholders.
				$sql  = 'SELECT * FROM `' . str_replace( '`', '``', $table ) . '`' . $key_column . ' LIMIT %d OFFSET %d';
				$rows = $wpdb->get_results( $wpdb->prepare( $sql, self::BATCH, (int) $cursor['offset'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( ! is_array( $rows ) || array() === $rows ) {
					break;
				}
				$written          += (int) fwrite( $out, self::insert( $table, $rows ) );
				$cursor['offset'] += count( $rows );
				if ( count( $rows ) < self::BATCH ) {
					break;
				}
				if ( microtime( true ) - $started > $seconds || $written >= $max_bytes ) {
					return false;
				}
			}
			fwrite( $out, "\n" );
			++$cursor['table'];
			$cursor['offset'] = 0;
			if ( microtime( true ) - $started > $seconds || $written >= $max_bytes ) {
				return $cursor['table'] >= $count;
			}
		}
		return true;
	}

	/**
	 * An ORDER BY on the primary key, so paging by offset sees each row once.
	 *
	 * @param string $table Table.
	 */
	private static function order_column( string $table ): string {
		global $wpdb;
		$keys = $wpdb->get_results( 'SHOW KEYS FROM `' . str_replace( '`', '``', $table ) . "` WHERE Key_name = 'PRIMARY'", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- the table name comes from SHOW TABLES.
		if ( ! is_array( $keys ) || array() === $keys ) {
			return '';
		}
		usort( $keys, static fn( $a, $b ) => (int) $a['Seq_in_index'] <=> (int) $b['Seq_in_index'] );
		return ' ORDER BY ' . implode( ', ', array_map( static fn( $k ) => '`' . str_replace( '`', '``', (string) $k['Column_name'] ) . '`', $keys ) );
	}

	/**
	 * One multi-row INSERT.
	 *
	 * @param string                           $table Table.
	 * @param array<int, array<string, mixed>> $rows  Rows.
	 */
	public static function insert( string $table, array $rows ): string {
		$columns = '`' . implode( '`, `', array_map( static fn( $c ) => str_replace( '`', '``', (string) $c ), array_keys( $rows[0] ) ) ) . '`';
		$values  = array();
		foreach ( $rows as $row ) {
			$values[] = '(' . implode( ', ', array_map( array( self::class, 'quote' ), array_values( $row ) ) ) . ')';
		}
		return 'INSERT INTO `' . $table . '` (' . $columns . ") VALUES\n" . implode( ",\n", $values ) . ";\n";
	}

	/**
	 * One value as SQL.
	 *
	 * @param mixed $value Value from the database (strings, or null).
	 */
	public static function quote( $value ): string {
		if ( null === $value ) {
			return 'NULL';
		}
		$value = (string) $value;
		return "'" . str_replace( array( '\\', "'", "\0", "\n", "\r", "\x1a" ), array( '\\\\', "''", '\\0', '\\n', '\\r', '\\Z' ), $value ) . "'";
	}
}
