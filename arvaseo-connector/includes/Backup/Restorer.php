<?php
/**
 * Restores a backup from Arva SEO onto this site, in small steps.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Backup;

use ArvaSEO\Connector\Api\ApiClient;
use ArvaSEO\Connector\Auth\TokenStore;
use ArvaSEO\Connector\Cron\CronManager;
use ArvaSEO\Connector\Logging\AuditLogger;
use ArvaSEO\Connector\Settings;
use ArvaSEO\Connector\Support\Ids;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- streams large files in bounded steps.

/**
 * Only an administrator inside WordPress starts one, after reading what it replaces. Steps: the manifest, every part
 * (each checked against its SHA-256), a safety copy of the current database, the database, then the files. The state
 * lives in a file, not in an option, because restoring the database replaces the options table. This plugin's own
 * options are kept as they were before, so the site stays connected to Arva SEO, and the plugin's own files are never
 * overwritten while they run. Files that are not in the backup are left alone (a restore adds and replaces, it does not
 * delete). A failed database restore puts the safety copy back.
 */
final class Restorer {

	public const STEP = 'arvaseo_connector_restore_step';

	public const LAST = 'arvaseo_connector_restore_last';

	/** Where the running restore keeps its state, found again from this option (a path, not data). */
	public const POINTER = 'arvaseo_connector_restore_pointer';

	/**
	 * Hook the steps.
	 */
	public static function init(): void {
		add_action( self::STEP, array( self::class, 'step' ) );
	}

	/**
	 * Where a running restore is, in plain words: the step, how many steps there are, and what it is doing.
	 *
	 * @param array<string, mixed> $state The running restore.
	 * @return array{step:int, of:int, label:string}
	 */
	public static function progress( array $state ): array {
		$steps = array(
			'manifest' => __( 'Reading the backup', 'arvaseo-connector' ),
			'download' => __( 'Downloading the backup', 'arvaseo-connector' ),
			'safety'   => __( 'Saving a copy of the current database', 'arvaseo-connector' ),
			'db'       => __( 'Restoring the database', 'arvaseo-connector' ),
			'rollback' => __( 'Setting the current files aside', 'arvaseo-connector' ),
			'files'    => __( 'Restoring the files', 'arvaseo-connector' ),
		);
		$phase = (string) ( $state['phase'] ?? 'manifest' );
		$keys  = array_keys( $steps );
		$index = array_search( $phase, $keys, true );
		$index = false === $index ? count( $keys ) - 1 : (int) $index;
		return array(
			'step'  => $index + 1,
			'of'    => count( $keys ),
			'label' => $steps[ $keys[ $index ] ],
		);
	}

	/**
	 * The running restore's state, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function current(): ?array {
		$path = get_option( self::POINTER );
		if ( ! is_string( $path ) || ! is_file( $path ) ) {
			return null;
		}
		$state = json_decode( (string) file_get_contents( $path ), true );
		return is_array( $state ) ? $state : null;
	}

	/**
	 * How the last restore ended.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function last(): ?array {
		$last = get_option( self::LAST );
		return is_array( $last ) ? $last : null;
	}

	/**
	 * Start a restore of a backup. Returns why not, or null when started.
	 *
	 * @param string $remote_id The backup's ID on Arva SEO.
	 */
	public static function begin( string $remote_id ): ?string {
		if ( ! Settings::get( 'backups' ) || ! TokenStore::is_connected() ) {
			return __( 'Turn backups on and connect this site to Arva SEO first.', 'arvaseo-connector' );
		}
		if ( null !== self::current() || null !== Job::current() ) {
			return __( 'A backup or restore is already running.', 'arvaseo-connector' );
		}
		if ( ! class_exists( '\ZipArchive' ) || ! function_exists( 'gzopen' ) ) {
			return __( 'This server needs the PHP zip and zlib extensions to restore a backup.', 'arvaseo-connector' );
		}
		$response = ApiClient::instance()->site( 'POST', '/backups/' . rawurlencode( $remote_id ) . '/restore', array(), 30 );
		if ( ! $response->ok() ) {
			return (string) ( $response->message ?? __( 'Arva SEO could not start the restore.', 'arvaseo-connector' ) );
		}
		$received = (array) ( $response->body['received'] ?? array() );
		if ( array() === $received ) {
			return __( 'That backup has no parts to restore.', 'arvaseo-connector' );
		}
		$id    = Ids::uuid();
		$dir   = Job::folder( 'restore-' . $id );
		$state = array(
			'id'       => $id,
			'remote'   => $remote_id,
			'dir'      => $dir,
			'phase'    => 'manifest',
			'received' => $received,
			// Direct links to the parts in Arva SEO's storage (much faster than through the API), when it gives them.
			'urls'     => array_filter( (array) ( $response->body['urls'] ?? array() ), static fn( $u ) => is_string( $u ) && str_starts_with( $u, 'https://' ) ),
			'started'  => time(),
			'attempts' => 0,
		);
		if ( ! Job::make_folder( $dir ) ) {
			return __( 'Could not make a private folder for the restore inside wp-content.', 'arvaseo-connector' );
		}
		self::save( $state );
		AuditLogger::info( 'restore.started', __( 'A restore started.', 'arvaseo-connector' ) );
		CronManager::run_soon( self::STEP, 5 );
		return null;
	}

	/**
	 * Do the next piece.
	 */
	public static function step(): void {
		$state = self::current();
		if ( null === $state ) {
			return;
		}
		$started = microtime( true );
		try {
			do {
				$state = self::run_phase( $state );
			} while ( self::keep_going( $state, $started ) );
		} catch ( \Throwable $e ) {
			self::finish( $state, 'failed', __( 'The restore stopped because of an error on this site.', 'arvaseo-connector' ) . ' ' . wp_strip_all_tags( mb_substr( $e->getMessage(), 0, 160 ) ) );
			return;
		}
		if ( 'finished' === $state['phase'] ) {
			return;
		}
		unset( $state['wait'] );
		self::save( $state );
		CronManager::run_soon( self::STEP, 5 );
	}

	/**
	 * Save the state after each piece, and say whether there is time for another (see Job::budget()).
	 *
	 * @param array<string, mixed> $s       State.
	 * @param float                $started When the step began.
	 */
	private static function keep_going( array $s, float $started ): bool {
		if ( 'finished' === $s['phase'] ) {
			return false;
		}
		$wait = ! empty( $s['wait'] );
		unset( $s['wait'] );
		self::save( $s );
		return ! $wait && microtime( true ) - $started < Job::budget();
	}

	/**
	 * Run the current phase.
	 *
	 * @param array<string, mixed> $s State.
	 * @return array<string, mixed>
	 */
	private static function run_phase( array $s ): array {
		return match ( $s['phase'] ) {
			'manifest' => self::manifest( $s ),
			'download' => self::download( $s ),
			'safety'   => self::safety( $s ),
			'db'       => self::apply_db( $s ),
			'rollback' => self::rollback( $s ),
			default    => self::apply_files( $s ),
		};
	}

	/**
	 * Fetch one part, check it, write it to the folder. Returns an error message or null.
	 *
	 * @param array<string, mixed> $s    State.
	 * @param int                  $n    Part number.
	 * @param string               $name File name to write.
	 */
	private static function fetch( array &$s, int $n, string $name ): ?string {
		$expected = (string) ( $s['received'][ $n ]['sha256'] ?? '' );
		$got      = self::part( $s, $n );
		if ( ! $got->ok() ) {
			$s['attempts'] = (int) $s['attempts'] + 1;
			$s['wait']     = true;
			return $s['attempts'] >= Job::ATTEMPTS || ! $got->retryable() ? (string) ( $got->message ?? __( 'Arva SEO could not give a part of the backup.', 'arvaseo-connector' ) ) : null;
		}
		$bytes = (string) ( $got->body['bytes'] ?? '' );
		if ( '' === $expected || ! hash_equals( $expected, hash( 'sha256', $bytes ) ) ) {
			return __( 'A part of the backup arrived damaged. Nothing on this site was changed.', 'arvaseo-connector' );
		}
		file_put_contents( $s['dir'] . $name, $bytes );
		$s['attempts'] = 0;
		unset( $s['wait'] );
		return '';
	}

	/**
	 * One part's bytes: straight from storage when Arva SEO gave a signed link, else through the API.
	 *
	 * @param array<string, mixed> $s State.
	 * @param int                  $n Part number.
	 */
	private static function part( array $s, int $n ): \ArvaSEO\Connector\Api\ApiResponse {
		$url = (string) ( $s['urls'][ $n ] ?? '' );
		if ( '' === $url ) {
			return ApiClient::instance()->site_raw( 'GET', '/backups/' . rawurlencode( (string) $s['remote'] ) . '/parts/' . $n, null, array(), 120 );
		}
		$result = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 120,
				'redirection'         => 0,
				'limit_response_size' => 16100000,
			)
		);
		$status = is_wp_error( $result ) ? 0 : (int) wp_remote_retrieve_response_code( $result );
		if ( 200 !== $status ) {
			return new \ArvaSEO\Connector\Api\ApiResponse( $status, array(), 'download_failed', __( 'The backup could not be downloaded from storage.', 'arvaseo-connector' ) );
		}
		// The part's own SHA-256 from the list Arva SEO gave is checked by the caller, as for every part.
		return new \ArvaSEO\Connector\Api\ApiResponse( 200, array( 'bytes' => (string) wp_remote_retrieve_body( $result ) ) );
	}

	/**
	 * The manifest first: it names the other parts, and tells whether this backup belongs to this site.
	 *
	 * @param array<string, mixed> $s State.
	 * @return array<string, mixed>
	 */
	private static function manifest( array $s ): array {
		$last   = (int) max( array_keys( $s['received'] ) );
		$result = self::fetch( $s, $last, 'manifest.json' );
		if ( null !== $result && '' !== $result ) {
			return self::finish( $s, 'failed', $result );
		}
		if ( null === $result ) {
			return $s;
		}
		$manifest = json_decode( (string) file_get_contents( $s['dir'] . 'manifest.json' ), true );
		global $wpdb;
		if ( ! is_array( $manifest ) || 1 !== (int) ( $manifest['format'] ?? 0 ) || ! is_array( $manifest['parts'] ?? null ) ) {
			return self::finish( $s, 'failed', __( 'This backup is not in a format this version can restore.', 'arvaseo-connector' ) );
		}
		if ( untrailingslashit( (string) $manifest['site_url'] ) !== untrailingslashit( home_url() ) ) {
			return self::finish( $s, 'failed', __( 'This backup was made for a different website address, so it was not restored. Nothing was changed.', 'arvaseo-connector' ) );
		}
		if ( (string) ( $manifest['table_prefix'] ?? '' ) !== $wpdb->prefix ) {
			return self::finish( $s, 'failed', __( 'This backup uses a different database table prefix, so it was not restored. Nothing was changed.', 'arvaseo-connector' ) );
		}
		$s['manifest'] = $manifest;
		$s['phase']    = 'download';
		$s['next']     = 0;
		return $s;
	}

	/**
	 * Every other part, one at a time.
	 *
	 * @param array<string, mixed> $s State.
	 * @return array<string, mixed>
	 */
	private static function download( array $s ): array {
		$parts = $s['manifest']['parts'];
		if ( $s['next'] >= count( $parts ) ) {
			$s['phase']  = 'safety';
			$s['safety'] = array(
				'table'  => 0,
				'offset' => 0,
				'header' => false,
				'parts'  => 0,
			);
			return $s;
		}
		$part   = $parts[ $s['next'] ];
		$result = self::fetch( $s, (int) $part['n'], basename( (string) $part['name'] ) );
		if ( null !== $result && '' !== $result ) {
			return self::finish( $s, 'failed', $result );
		}
		if ( '' === $result ) {
			++$s['next'];
		}
		return $s;
	}

	/**
	 * A copy of the current database, in case the restore fails or the person changes their mind.
	 *
	 * @param array<string, mixed> $s State.
	 * @return array<string, mixed>
	 */
	private static function safety( array $s ): array {
		$safety_dir = $s['dir'] . 'safety/';
		wp_mkdir_p( $safety_dir );
		if ( empty( $s['tables'] ) ) {
			$s['tables'] = DatabaseDump::tables();
		}
		$cursor = array(
			'table'  => (int) $s['safety']['table'],
			'offset' => (int) $s['safety']['offset'],
			'header' => (bool) $s['safety']['header'],
		);
		$name   = sprintf( 'part-%03d.sql.gz', (int) $s['safety']['parts'] + 1 );
		$out    = fopen( 'compress.zlib://' . $safety_dir . $name, 'wb' );
		if ( false === $out ) {
			return self::finish( $s, 'failed', __( 'Could not save a safety copy of the database, so nothing was changed.', 'arvaseo-connector' ) );
		}
		$done = DatabaseDump::write( $out, $cursor, $s['tables'], max( 2.0, Job::budget() - 4 ), Job::DB_PART_BYTES );
		fclose( $out );
		$s['safety'] = array_merge( $cursor, array( 'parts' => (int) $s['safety']['parts'] + 1 ) );
		if ( $done ) {
			$s['phase']   = 'db';
			$s['db']      = array(
				'part'   => 0,
				'offset' => 0,
			);
			$s['options'] = self::own_options();
		}
		return $s;
	}

	/**
	 * This plugin's own options, to put back after the database is replaced.
	 *
	 * @return array<string, string>
	 */
	private static function own_options(): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'arvaseo_connector_' ) . '%' ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- this plugin's options, read raw so they come back exactly as they were.
		return (array) array_column( (array) $rows, 'option_value', 'option_name' );
	}

	/**
	 * The database parts, statement by statement.
	 *
	 * @param array<string, mixed> $s State.
	 * @return array<string, mixed>
	 */
	private static function apply_db( array $s ): array {
		global $wpdb;
		$db_parts = array_values( array_filter( $s['manifest']['parts'], static fn( $p ) => 'db' === $p['type'] ) );
		if ( $s['db']['part'] >= count( $db_parts ) ) {
			self::put_back_options( (array) $s['options'] );
			wp_cache_flush();
			$s['phase'] = 'files';
			$s['file']  = array(
				'part' => 0,
			);
			return $s;
		}
		$path   = $s['dir'] . basename( (string) $db_parts[ $s['db']['part'] ]['name'] );
		$offset = (int) $s['db']['offset'];
		$wpdb->suppress_errors( true );
		$result = SqlReader::run(
			$path,
			$offset,
			max( 2.0, Job::budget() - 4 ),
			static function ( string $statement ) use ( $wpdb ): bool {
				return false !== $wpdb->query( $statement ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- a statement from this site's own backup, checked by SHA-256.
			}
		);
		$wpdb->suppress_errors( false );
		$s['db']['offset'] = $offset;
		if ( 'done' === $result ) {
			++$s['db']['part'];
			$s['db']['offset'] = 0;
		} elseif ( 'more' !== $result ) {
			// A statement failed: put the safety copy back.
			$s['error']    = (string) $result;
			$s['phase']    = 'rollback';
			$s['rollback'] = array(
				'part'   => 0,
				'offset' => 0,
			);
		}
		return $s;
	}

	/**
	 * Re-apply the safety copy after a failed database restore.
	 *
	 * @param array<string, mixed> $s State.
	 * @return array<string, mixed>
	 */
	private static function rollback( array $s ): array {
		global $wpdb;
		$files = glob( $s['dir'] . 'safety/part-*.sql.gz' );
		sort( $files );
		if ( $s['rollback']['part'] >= count( $files ) ) {
			self::put_back_options( (array) $s['options'] );
			wp_cache_flush();
			return self::finish( $s, 'failed', __( 'The restore failed part-way, so the database was put back as it was before. Your files were not changed.', 'arvaseo-connector' ) );
		}
		$offset = (int) $s['rollback']['offset'];
		$wpdb->suppress_errors( true );
		$result = SqlReader::run(
			$files[ $s['rollback']['part'] ],
			$offset,
			max( 2.0, Job::budget() - 4 ),
			static function ( string $statement ) use ( $wpdb ): bool {
				return false !== $wpdb->query( $statement ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- the safety copy this restore made.
			}
		);
		$wpdb->suppress_errors( false );
		$s['rollback']['offset'] = $offset;
		if ( 'done' === $result ) {
			++$s['rollback']['part'];
			$s['rollback']['offset'] = 0;
		} elseif ( 'more' !== $result ) {
			return self::finish( $s, 'failed', __( 'The restore failed and the safety copy could not be put back completely. The safety copy is in wp-content/arvaseo-backups: ask your host or Arva SEO support for help before doing anything else.', 'arvaseo-connector' ) );
		}
		return $s;
	}

	/**
	 * Make this plugin's own options exactly what they were before the restore: the ones the restored database brought
	 * with it (a backup that was running when the database was copied, an old connection) are removed, and the saved
	 * ones are written back.
	 *
	 * @param array<string, string> $options Name => raw value, taken before the restore.
	 */
	public static function put_back_options( array $options ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( 'arvaseo_connector_' ) . '%', $wpdb->esc_like( '_transient_arvaseo_connector_' ) . '%', $wpdb->esc_like( '_transient_timeout_arvaseo_connector_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- this plugin's own options, removed so none of the backup's copies stay.
		foreach ( $options as $name => $value ) {
			$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own options, restored exactly.
				$wpdb->options,
				array(
					'option_name'  => $name,
					'option_value' => $value,
					'autoload'     => 'no',
				)
			);
		}
	}

	/**
	 * The files, one ZIP at a time. This plugin's own folder and the backup folder are never written.
	 *
	 * @param array<string, mixed> $s State.
	 * @return array<string, mixed>
	 */
	private static function apply_files( array $s ): array {
		$zips = array_values( array_filter( $s['manifest']['parts'], static fn( $p ) => 'files' === $p['type'] ) );
		if ( $s['file']['part'] >= count( $zips ) ) {
			return self::finish( $s, 'complete', '' );
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $s['dir'] . basename( (string) $zips[ $s['file']['part'] ]['name'] ) ) ) {
			return self::finish( $s, 'failed', __( 'A files part of the backup could not be opened. The database was restored; some files were not.', 'arvaseo-connector' ) );
		}
		$root  = untrailingslashit( WP_CONTENT_DIR );
		$count = (int) $zip->count();
		for ( $i = 0; $i < $count; $i++ ) {
			$entry = (string) $zip->getNameIndex( $i );
			$slice = null;
			if ( str_contains( $entry, FilePacker::SLICE ) ) {
				[ $entry, $slice ] = explode( FilePacker::SLICE, $entry, 2 );
			}
			if ( ! self::allowed_path( $entry ) ) {
				continue;
			}
			$target = $root . '/' . $entry;
			wp_mkdir_p( dirname( $target ) );
			$in  = $zip->getStream( (string) $zip->getNameIndex( $i ) );
			$out = false === $in ? false : fopen( $target, null !== $slice && (int) $slice > 0 ? 'ab' : 'wb' );
			if ( false !== $in && false !== $out ) {
				stream_copy_to_stream( $in, $out );
			}
			if ( false !== $in ) {
				fclose( $in );
			}
			if ( false !== $out ) {
				fclose( $out );
			}
		}
		$zip->close();
		++$s['file']['part'];
		return $s;
	}

	/**
	 * Whether a path from a backup may be written: relative, inside wp-content, not this plugin, not the backup folder.
	 *
	 * @param string $path Path inside wp-content.
	 */
	public static function allowed_path( string $path ): bool {
		if ( '' === $path || str_starts_with( $path, '/' ) || str_contains( $path, '\\' ) || str_contains( $path, "\0" ) || 1 === preg_match( '#(^|/)\.\.(/|$)#', $path ) || 1 === preg_match( '#^[A-Za-z]:#', $path ) ) {
			return false;
		}
		$top = explode( '/', $path, 2 )[0];
		if ( in_array( $top, FilePacker::SKIP, true ) ) {
			return false;
		}
		return ! str_starts_with( $path, 'plugins/arvaseo-connector/' ) && 'plugins/arvaseo-connector' !== $path;
	}

	/**
	 * Save the state to its file (and remember where it is).
	 *
	 * @param array<string, mixed> $s State.
	 */
	private static function save( array $s ): void {
		$path = $s['dir'] . 'state.json';
		file_put_contents( $path, (string) wp_json_encode( $s ) );
		update_option( self::POINTER, $path, false );
	}

	/**
	 * End the restore: forget the state, remove the downloaded parts (the safety copy stays), record the result.
	 *
	 * @param array<string, mixed> $s       State.
	 * @param string               $status  complete or failed.
	 * @param string               $message A plain sentence.
	 * @return array<string, mixed>
	 */
	private static function finish( array $s, string $status, string $message ): array {
		$dir = (string) $s['dir'];
		foreach ( (array) glob( $dir . '*' ) as $path ) {
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}
		delete_option( self::POINTER );
		Panel::forget(); // The stored list and the restores left have changed.
		update_option(
			self::LAST,
			array(
				'at'      => time(),
				'status'  => $status,
				'message' => $message,
				'safety'  => 'complete' === $status || is_dir( $dir . 'safety' ) ? 'wp-content/arvaseo-backups/' . basename( rtrim( $dir, '/' ) ) . '/safety' : '',
			),
			false
		);
		if ( 'failed' === $status ) {
			AuditLogger::error( 'restore.failed', __( 'A restore failed.', 'arvaseo-connector' ), array( 'reason' => mb_substr( $message, 0, 200 ) ) );
		} else {
			AuditLogger::info( 'restore.complete', __( 'A restore finished.', 'arvaseo-connector' ) );
		}
		$s['phase'] = 'finished';
		return $s;
	}
}
