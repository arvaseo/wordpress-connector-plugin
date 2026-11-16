<?php
/**
 * Packs wp-content into ZIP parts of bounded size.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Backup;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- a backup streams large files and dumps in bounded steps; WP_Filesystem reads whole files into memory.

/**
 * Step one writes a list of the files to keep (relative path and size, one per line). Step two turns the list into
 * ZIP files, each holding about SOURCE_BYTES of files, so every upload part stays under the limit even when the
 * files do not compress. A file larger than that is cut into slices stored as "path.arvapart.N" entries, put back
 * together by the restore. Symbolic links, unreadable files, caches and other backup plugins' folders are left out.
 */
final class FilePacker {

	/** Source bytes per ZIP (kept under the 16 MB part limit even when nothing compresses). */
	public const SOURCE_BYTES = 12000000;

	public const SLICE = '.arvapart.';

	/** Folders and files inside wp-content that are never backed up. */
	public const SKIP = array( 'cache', 'upgrade', 'upgrade-temp-backup', 'arvaseo-backups', 'backups', 'backup', 'backup-db', 'ai1wm-backups', 'updraft', 'wpvividbackups', 'backupwordpress', 'debug.log', 'error_log', 'object-cache.tmp' );

	/**
	 * Write the list of files to back up.
	 *
	 * @param string $root      The folder to back up (wp-content).
	 * @param string $list_path Where to write the list.
	 * @return array{files: int, bytes: int}
	 */
	public static function make_list( string $root, string $list_path ): array {
		$root  = rtrim( wp_normalize_path( $root ), '/' );
		$out   = fopen( $list_path, 'wb' );
		$files = 0;
		$bytes = 0;
		if ( false === $out ) {
			return array(
				'files' => 0,
				'bytes' => 0,
			);
		}
		$stack = array( '' );
		while ( array() !== $stack ) {
			$relative = array_pop( $stack );
			$dir      = '' === $relative ? $root : $root . '/' . $relative;
			$handle   = @opendir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an unreadable folder is skipped.
			if ( false === $handle ) {
				continue;
			}
			$names = array();
			while ( false !== ( $name = readdir( $handle ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- the standard readdir loop.
				if ( '.' !== $name && '..' !== $name ) {
					$names[] = $name;
				}
			}
			closedir( $handle );
			sort( $names );
			foreach ( $names as $name ) {
				$path  = '' === $relative ? $name : $relative . '/' . $name;
				$full  = $root . '/' . $path;
				$depth = '' === $relative;
				if ( ( $depth && in_array( $name, self::SKIP, true ) ) || is_link( $full ) || str_contains( $name, "\n" ) || str_contains( $name, "\t" ) ) {
					continue;
				}
				if ( is_dir( $full ) ) {
					$stack[] = $path;
				} elseif ( is_file( $full ) && is_readable( $full ) && ! str_ends_with( $name, '.log' ) ) {
					$size = (int) filesize( $full );
					fwrite( $out, $path . "\t" . $size . "\n" );
					++$files;
					$bytes += $size;
				}
			}
		}
		fclose( $out );
		return array(
			'files' => $files,
			'bytes' => $bytes,
		);
	}

	/**
	 * Pack the next ZIP from the list.
	 *
	 * @param string                                    $root      The folder the list is relative to.
	 * @param string                                    $list_path The list.
	 * @param array{line: int, offset: int, total: int} $cursor    The list line, the byte offset inside a large file and the number of lines (updated).
	 * @param string                                    $zip_path  The ZIP to write.
	 * @return array{done: bool, files: int}|null Null when the ZIP could not be written.
	 */
	public static function pack_next( string $root, string $list_path, array &$cursor, string $zip_path ): ?array {
		if ( ! class_exists( '\ZipArchive' ) ) {
			return null;
		}
		$root = rtrim( wp_normalize_path( $root ), '/' );
		$list = new \SplFileObject( $list_path, 'r' );
		$list->setFlags( \SplFileObject::DROP_NEW_LINE );
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			return null;
		}
		$budget = self::SOURCE_BYTES;
		$added  = 0;
		$list->seek( (int) $cursor['line'] );
		while ( ! $list->eof() && $budget > 0 ) {
			$line = (string) $list->current();
			if ( '' === $line ) {
				$list->next();
				continue;
			}
			[ $path, $size ] = array_pad( explode( "\t", $line, 2 ), 2, '0' );
			$size            = (int) $size;
			$full            = $root . '/' . $path;
			if ( ! is_file( $full ) || ! is_readable( $full ) ) {
				++$cursor['line'];
				$cursor['offset'] = 0;
				$list->next();
				continue;
			}
			$actual = (int) filesize( $full );
			if ( $actual <= $budget && 0 === (int) $cursor['offset'] ) {
				$zip->addFile( $full, $path );
				$budget -= max( 1, $actual );
				++$added;
				++$cursor['line'];
				$list->next();
				continue;
			}
			// A file that does not fit whole starts its own ZIPs, a slice each.
			if ( $added > 0 ) {
				break;
			}
			$length = min( $budget, $actual - (int) $cursor['offset'] );
			$handle = fopen( $full, 'rb' );
			if ( false !== $handle ) {
				fseek( $handle, (int) $cursor['offset'] );
				$chunk = (string) fread( $handle, max( 1, $length ) );
				fclose( $handle );
				$index = (int) floor( (int) $cursor['offset'] / self::SOURCE_BYTES );
				$zip->addFromString( $path . self::SLICE . $index, $chunk );
				++$added;
			}
			$budget           -= max( 1, $length );
			$cursor['offset'] += $length;
			if ( $cursor['offset'] >= $actual ) {
				++$cursor['line'];
				$cursor['offset'] = 0;
				$list->next();
			}
			break; // A slice fills its ZIP.
		}
		$closed = $zip->close();
		return $closed ? array(
			'done'  => (int) $cursor['line'] >= (int) $cursor['total'],
			'files' => $added,
		) : null;
	}
}
