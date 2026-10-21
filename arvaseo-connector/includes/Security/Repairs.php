<?php
/**
 * Repairs with undo (2.2.0): put back a WordPress or plugin file from its official copy on
 * WordPress.org, or move a file that shouldn't be there into quarantine. Only when an
 * administrator chooses it, only where WordPress allows file changes, and each one can be undone
 * for 30 days.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Security;

use ArvaSEO\Connector\Logging\AuditLogger;

defined( 'ABSPATH' ) || exit;

/**
 * The file being replaced or moved is kept in wp-content/arvaseo-quarantine under a random name
 * with no PHP extension (so it can't run), in a folder that refuses web requests. A restored
 * file is downloaded from core.svn.wordpress.org or plugins.svn.wordpress.org and used only when
 * it matches WordPress.org's checksum for the installed version.
 */
final class Repairs {

	public const OPTION = 'arvaseo_connector_repairs';

	public const FOLDER = 'arvaseo-quarantine';

	/** How long a repair can be undone; its kept copy is deleted after. */
	public const KEEP_DAYS = 30;

	/** Types an official copy can be put back for. */
	private const RESTORABLE = array( 'core_changed', 'core_missing', 'plugin_changed' );

	/** Types whose file can be moved to quarantine without breaking the site. */
	private const MOVABLE = array( 'uploads_php', 'uploads_disguised', 'core_unexpected', 'root_unexpected', 'plugin_unexpected' );

	/** The largest official file downloaded. */
	private const MAX_BYTES = 5000000;

	/**
	 * Whether this site lets the plugin change files: WordPress allows file changes, and it can
	 * write them directly (no FTP details needed).
	 */
	public static function available(): bool {
		if ( ! wp_is_file_mod_allowed( 'arvaseo_connector_repair' ) ) {
			return false;
		}
		if ( ! function_exists( 'get_filesystem_method' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		return 'direct' === get_filesystem_method( array(), WP_CONTENT_DIR, true );
	}

	/**
	 * The repair a finding offers: restore, quarantine or none.
	 *
	 * @param array<string, mixed> $finding Finding.
	 */
	public static function offer( array $finding ): ?string {
		$type = (string) ( $finding['type'] ?? '' );
		$path = (string) ( $finding['path'] ?? '' );
		if ( '' === $path || ! self::inside( $path ) ) {
			return null;
		}
		if ( in_array( $type, self::RESTORABLE, true ) ) {
			return 'restore';
		}
		$uploads    = self::uploads_relative();
		$in_uploads = null !== $uploads && str_starts_with( $path, $uploads . '/' );
		if ( in_array( $type, self::MOVABLE, true ) || ( $in_uploads && in_array( $type, array( 'code', 'deep_malware', 'deep_code' ), true ) ) ) {
			return 'quarantine';
		}
		return null;
	}

	/**
	 * Put back the official copy of a changed or missing WordPress or plugin file.
	 *
	 * @param string $key Finding key.
	 * @return array{ok: bool, message: string}
	 */
	public static function restore( string $key ): array {
		$finding = self::finding( $key );
		if ( null === $finding || 'restore' !== self::offer( $finding ) || ! self::available() ) {
			return self::no( __( 'This file can’t be restored from here.', 'arvaseo-connector' ) );
		}
		$path   = (string) $finding['path'];
		$source = self::official( $finding );
		if ( null === $source ) {
			return self::no( __( 'WordPress.org has no official copy of this file for the installed version.', 'arvaseo-connector' ) );
		}
		$body = null;
		foreach ( $source['urls'] as $url ) {
			$response = wp_remote_get(
				$url,
				array(
					'timeout'             => 20,
					'redirection'         => 2,
					'limit_response_size' => self::MAX_BYTES,
				)
			);
			$got      = is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ? null : (string) wp_remote_retrieve_body( $response );
			if ( null !== $got && in_array( md5( $got ), $source['md5'], true ) ) {
				$body = $got;
				break;
			}
		}
		if ( null === $body ) {
			return self::no( __( 'The official copy couldn’t be downloaded, or didn’t match WordPress.org’s checksum, so nothing was changed.', 'arvaseo-connector' ) );
		}
		$fs   = self::filesystem();
		$file = wp_normalize_path( ABSPATH . $path );
		if ( null === $fs ) {
			return self::no( __( 'WordPress can’t write files here directly, so nothing was changed.', 'arvaseo-connector' ) );
		}
		$backup = null;
		if ( is_file( $file ) ) {
			$backup = self::keep( $fs, $file );
			if ( null === $backup ) {
				return self::no( __( 'A copy of the current file couldn’t be kept, so nothing was changed.', 'arvaseo-connector' ) );
			}
		} elseif ( ! is_dir( dirname( $file ) ) && ! wp_mkdir_p( dirname( $file ) ) ) {
			return self::no( __( 'The file’s folder couldn’t be created, so nothing was changed.', 'arvaseo-connector' ) );
		}
		if ( ! $fs->put_contents( $file, $body, FS_CHMOD_FILE ) ) {
			return self::no( __( 'The official copy couldn’t be written, so nothing was changed.', 'arvaseo-connector' ) );
		}
		self::record( 'restore', $path, $backup, md5( $body ), (string) ( $finding['type'] ?? '' ) );
		Findings::forget( $key );
		Report::send();
		AuditLogger::info(
			'security.file_restored',
			/* translators: %s: file path. */
			sprintf( __( 'Restored the official copy of %s.', 'arvaseo-connector' ), $path ),
			array( 'status' => 'restore' )
		);
		return array(
			'ok'      => true,
			'message' => __( 'The official copy is back. The previous file is kept for 30 days in case you need to undo it.', 'arvaseo-connector' ),
		);
	}

	/**
	 * Move a file that shouldn't be there into quarantine.
	 *
	 * @param string $key Finding key.
	 * @return array{ok: bool, message: string}
	 */
	public static function quarantine( string $key ): array {
		$finding = self::finding( $key );
		if ( null === $finding || 'quarantine' !== self::offer( $finding ) || ! self::available() ) {
			return self::no( __( 'This file can’t be moved from here.', 'arvaseo-connector' ) );
		}
		$path = (string) $finding['path'];
		$file = wp_normalize_path( ABSPATH . $path );
		$fs   = self::filesystem();
		if ( null === $fs || ! is_file( $file ) || is_link( $file ) ) {
			return self::no( __( 'The file isn’t there any more, or WordPress can’t move it directly.', 'arvaseo-connector' ) );
		}
		$md5    = (string) md5_file( $file );
		$backup = self::keep( $fs, $file, true );
		if ( null === $backup ) {
			return self::no( __( 'The file couldn’t be moved, so nothing was changed.', 'arvaseo-connector' ) );
		}
		self::record( 'quarantine', $path, $backup, $md5, (string) ( $finding['type'] ?? '' ) );
		Findings::forget( $key );
		Report::send();
		AuditLogger::info(
			'security.file_quarantined',
			/* translators: %s: file path. */
			sprintf( __( 'Moved %s to quarantine.', 'arvaseo-connector' ), $path ),
			array( 'status' => 'quarantine' )
		);
		return array(
			'ok'      => true,
			'message' => __( 'The file is in quarantine, where it can’t run. It’s kept for 30 days in case you need to undo it.', 'arvaseo-connector' ),
		);
	}

	/**
	 * Undo a repair: the previous file goes back, unless the file changed again since.
	 *
	 * @param string $id Repair ID.
	 * @return array{ok: bool, message: string}
	 */
	public static function undo( string $id ): array {
		$repairs = self::all();
		$repair  = $repairs[ $id ] ?? null;
		$fs      = self::filesystem();
		if ( null === $repair || ! empty( $repair['undone_at'] ) || null === $fs || ! self::available() ) {
			return self::no( __( 'This change can’t be undone from here.', 'arvaseo-connector' ) );
		}
		$file = wp_normalize_path( ABSPATH . $repair['path'] );
		$kept = null !== $repair['backup'] ? self::dir() . '/' . $repair['backup'] : null;
		if ( 'restore' === $repair['type'] ) {
			if ( is_file( $file ) && md5_file( $file ) !== $repair['md5'] ) {
				return self::no( __( 'The file changed again since, so it was left as it is.', 'arvaseo-connector' ) );
			}
			$done = null === $kept ? $fs->delete( $file ) : ( is_file( (string) $kept ) && $fs->move( (string) $kept, $file, true ) );
		} else {
			if ( is_file( $file ) || null === $kept || ! is_file( $kept ) ) {
				return self::no( __( 'Another file is in its place now, or the kept copy is gone, so nothing was changed.', 'arvaseo-connector' ) );
			}
			$done = $fs->move( $kept, $file );
		}
		if ( ! $done ) {
			return self::no( __( 'The previous file couldn’t be put back.', 'arvaseo-connector' ) );
		}
		$repairs[ $id ]['undone_at'] = time();
		update_option( self::OPTION, $repairs, false );
		AuditLogger::info(
			'security.repair_undone',
			/* translators: %s: file path. */
			sprintf( __( 'Undid the change to %s.', 'arvaseo-connector' ), $repair['path'] ),
			array( 'status' => $repair['type'] )
		);
		return array(
			'ok'      => true,
			'message' => __( 'Undone: the previous file is back. Run the scan again to see it in the results.', 'arvaseo-connector' ),
		);
	}

	/**
	 * Repairs, newest first.
	 *
	 * @return array<string, array{type: string, path: string, backup: string|null, md5: string, finding: string, at: int, by: int, undone_at?: int}>
	 */
	public static function all(): array {
		$value = get_option( self::OPTION, array() );
		if ( ! is_array( $value ) ) {
			return array();
		}
		uasort( $value, static fn ( array $a, array $b ): int => (int) $b['at'] <=> (int) $a['at'] );
		return $value;
	}

	/**
	 * Forget repairs older than 30 days and delete their kept copies (daily).
	 */
	public static function purge(): void {
		$repairs = self::all();
		$fs      = self::filesystem();
		foreach ( $repairs as $id => $repair ) {
			if ( (int) $repair['at'] > time() - self::KEEP_DAYS * DAY_IN_SECONDS ) {
				continue;
			}
			if ( null !== $repair['backup'] && null !== $fs && is_file( self::dir() . '/' . $repair['backup'] ) ) {
				$fs->delete( self::dir() . '/' . $repair['backup'] );
			}
			unset( $repairs[ $id ] );
		}
		update_option( self::OPTION, $repairs, false );
	}

	// ----- Helpers ------------------------------------------------------------------------

	/**
	 * The official copy of a file: its address on WordPress.org and the checksums it must match.
	 *
	 * @param array<string, mixed> $finding Finding.
	 * @return array{urls: string[], md5: string[]}|null
	 */
	private static function official( array $finding ): ?array {
		$path = (string) $finding['path'];
		if ( 'plugin_changed' !== $finding['type'] ) {
			$sums = Checksums::core();
			$md5  = $sums[ $path ] ?? null;
			return null === $md5 ? null : array(
				'urls' => array( 'https://core.svn.wordpress.org/tags/' . rawurlencode( (string) get_bloginfo( 'version' ) ) . '/' . self::encode( $path ) ),
				'md5'  => array( $md5 ),
			);
		}
		$plugins = wp_normalize_path( WP_PLUGIN_DIR ) . '/';
		$inside  = substr( wp_normalize_path( ABSPATH . $path ), strlen( $plugins ) );
		$slug    = (string) strtok( $inside, '/' );
		$rest    = substr( $inside, strlen( $slug ) + 1 );
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$version = null;
		foreach ( get_plugins() as $file => $data ) {
			if ( str_starts_with( $file, $slug . '/' ) ) {
				$version = (string) ( $data['Version'] ?? '' );
				break;
			}
		}
		$sums = null !== $version && '' !== $version ? Checksums::plugin( $slug, $version ) : null;
		if ( null === $sums || ! isset( $sums[ $rest ] ) ) {
			return null;
		}
		// The version's tag, or trunk for a plugin released from it: only a copy matching the checksum is used.
		$base = 'https://plugins.svn.wordpress.org/' . rawurlencode( $slug );
		return array(
			'urls' => array( $base . '/tags/' . rawurlencode( (string) $version ) . '/' . self::encode( $rest ), $base . '/trunk/' . self::encode( $rest ) ),
			'md5'  => (array) $sums[ $rest ],
		);
	}

	/**
	 * Keep a copy of a file in quarantine (or move it there), under a random name.
	 *
	 * @param \WP_Filesystem_Base $fs   Filesystem.
	 * @param string              $file Absolute path.
	 * @param bool                $move Move rather than copy.
	 * @return string|null The kept copy's name.
	 */
	private static function keep( \WP_Filesystem_Base $fs, string $file, bool $move = false ): ?string {
		$dir = self::dir();
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return null;
		}
		// Refuse web requests, and never list or run anything here.
		if ( ! is_file( $dir . '/.htaccess' ) ) {
			$fs->put_contents( $dir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n", FS_CHMOD_FILE );
		}
		if ( ! is_file( $dir . '/index.php' ) ) {
			$fs->put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n", FS_CHMOD_FILE );
		}
		$name = wp_generate_password( 24, false ) . '.quarantined';
		$done = $move ? $fs->move( $file, $dir . '/' . $name ) : $fs->copy( $file, $dir . '/' . $name );
		return $done ? $name : null;
	}

	/**
	 * Remember a repair.
	 *
	 * @param string      $type    restore|quarantine.
	 * @param string      $path    Path from the main folder.
	 * @param string|null $backup  The kept copy's name.
	 * @param string      $md5     The file's md5 after a restore, or before a quarantine.
	 * @param string      $finding The finding's type.
	 */
	private static function record( string $type, string $path, ?string $backup, string $md5, string $finding ): void {
		$repairs                        = self::all();
		$repairs[ wp_generate_uuid4() ] = array(
			'type'    => $type,
			'path'    => $path,
			'backup'  => $backup,
			'md5'     => $md5,
			'finding' => $finding,
			'at'      => time(),
			'by'      => get_current_user_id(),
		);
		update_option( self::OPTION, $repairs, false );
	}

	/**
	 * A finding of the latest results, by key.
	 *
	 * @param string $key Key.
	 * @return array<string, mixed>|null
	 */
	private static function finding( string $key ): ?array {
		foreach ( (array) ( Findings::latest()['findings'] ?? array() ) as $finding ) {
			if ( $key === $finding['key'] ) {
				return $finding;
			}
		}
		return null;
	}

	/**
	 * Whether a path stays inside the site and isn't the configuration.
	 *
	 * @param string $path Path from the main folder.
	 */
	private static function inside( string $path ): bool {
		return ! str_starts_with( $path, '/' ) && ! str_contains( $path, '..' ) && ! str_contains( $path, "\0" )
			&& 'wp-config.php' !== $path && ! str_starts_with( $path, 'wp-content/' . self::FOLDER . '/' );
	}

	/**
	 * Each part of a path, URL-encoded.
	 *
	 * @param string $path Path.
	 */
	private static function encode( string $path ): string {
		return implode( '/', array_map( 'rawurlencode', explode( '/', $path ) ) );
	}

	/**
	 * The uploads folder, from the main folder.
	 */
	private static function uploads_relative(): ?string {
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) ) {
			return null;
		}
		$root = wp_normalize_path( ABSPATH );
		$base = wp_normalize_path( (string) $uploads['basedir'] );
		return str_starts_with( $base, $root ) ? substr( $base, strlen( $root ) ) : null;
	}

	/**
	 * The quarantine folder.
	 */
	private static function dir(): string {
		return wp_normalize_path( WP_CONTENT_DIR ) . '/' . self::FOLDER;
	}

	/**
	 * WordPress's file functions, writing directly.
	 */
	private static function filesystem(): ?\WP_Filesystem_Base {
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( 'direct' !== get_filesystem_method( array(), WP_CONTENT_DIR, true ) || ! WP_Filesystem( false, WP_CONTENT_DIR, true ) ) {
			return null;
		}
		global $wp_filesystem;
		return $wp_filesystem instanceof \WP_Filesystem_Base ? $wp_filesystem : null;
	}

	/**
	 * A failed repair.
	 *
	 * @param string $message Why.
	 * @return array{ok: bool, message: string}
	 */
	private static function no( string $message ): array {
		return array(
			'ok'      => false,
			'message' => $message,
		);
	}
}
