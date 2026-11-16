<?php
/**
 * Reads the statements of a gzipped SQL dump and runs them, resumably.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Backup;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- streams a large dump in bounded steps.

/**
 * The dump writes one statement after another, each ending with ";" and a newline, with every newline inside a value
 * escaped, so a statement ends at the first ";" that is outside a quoted string and is followed by a newline. The
 * offset a call stops at is always a statement boundary in the uncompressed stream, so the next call starts clean.
 */
final class SqlReader {

	/** Bytes read from the file at a time. */
	public const CHUNK = 1048576;

	/**
	 * Run statements from a file, within a time budget.
	 *
	 * @param string   $path    A .sql.gz file.
	 * @param int      $offset  Uncompressed byte offset to start at (updated to the next statement boundary).
	 * @param float    $seconds Time budget.
	 * @param callable $run     Called with each statement; returns false to stop with an error.
	 * @return string "done", "more", or an error message.
	 */
	public static function run( string $path, int &$offset, float $seconds, callable $run ): string {
		$handle = gzopen( $path, 'rb' );
		if ( false === $handle ) {
			return 'Could not open the database file.';
		}
		if ( $offset > 0 && -1 === gzseek( $handle, $offset ) ) {
			gzclose( $handle );
			return 'Could not continue the database file.';
		}
		$started = microtime( true );
		$buffer  = '';
		$start   = 0;
		$eof     = false;
		$in      = false;
		$escaped = false;
		$i       = 0;
		while ( true ) {
			// Always see one byte ahead of the character looked at (a ";" is a statement end only before a newline).
			if ( $i + 1 >= strlen( $buffer ) && ! $eof ) {
				$more = gzread( $handle, self::CHUNK );
				if ( false === $more || '' === $more ) {
					$eof = true;
					continue;
				}
				// Keep only what the current statement needs.
				$buffer = substr( $buffer, $start ) . $more;
				$i     -= $start;
				$start  = 0;
				continue;
			}
			if ( $i >= strlen( $buffer ) ) {
				break;
			}
			$char = $buffer[ $i ];
			if ( $in ) {
				if ( $escaped ) {
					$escaped = false;
				} elseif ( '\\' === $char ) {
					$escaped = true;
				} elseif ( "'" === $char ) {
					$in = false;
				}
			} elseif ( "'" === $char ) {
				$in = true;
			} elseif ( ';' === $char && ( $i + 1 >= strlen( $buffer ) ? $eof : "\n" === $buffer[ $i + 1 ] ) ) {
				$statement = trim( (string) preg_replace( '/^(?:--[^\n]*\n)+/', '', substr( $buffer, $start, $i - $start + 1 ) ) );
				$consumed  = $i + 2;
				if ( '' !== $statement && false === $run( $statement ) ) {
					gzclose( $handle );
					return 'A statement in the backup could not be run.';
				}
				$offset += $consumed - $start;
				$start   = min( $consumed, strlen( $buffer ) );
				$i       = $start - 1;
				if ( microtime( true ) - $started > $seconds ) {
					gzclose( $handle );
					return 'more';
				}
			}
			++$i;
		}
		gzclose( $handle );
		return 'done';
	}
}
