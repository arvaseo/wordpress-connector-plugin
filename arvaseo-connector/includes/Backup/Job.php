<?php
/**
 * One backup, from start to upload, in small steps.
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

// phpcs:disable WordPress.WP.AlternativeFunctions -- a backup streams large files and dumps in bounded steps; WP_Filesystem reads whole files into memory.

/**
 * The state of the running backup lives in one option. Each call of step() does one piece within a time budget (the
 * database in parts, the files in ZIPs, the manifest, then the uploads one part at a time) and schedules the next, so
 * a slow shared host never runs into its time limit and a stopped backup carries on where it was. Parts are kept in a
 * private folder under wp-content until Arva SEO has them all, then removed. A backup is only ever started when the
 * administrator switched backups on in Settings, and the weekly one is replaced on Arva SEO only after the new one is
 * complete, so a failed backup never costs the old one.
 */
final class Job {

	public const OPTION = 'arvaseo_connector_backup_job';

	public const LAST = 'arvaseo_connector_backup_last';

	public const STEP = 'arvaseo_connector_backup_step';

	public const WEEKLY = 'arvaseo_connector_backup_weekly';

	public const DAILY = 'arvaseo_connector_backup_daily';

	/** Seconds one step may work. */
	public const BUDGET = 20.0;

	/** Failed tries on one part or call before the backup is given up. */
	public const ATTEMPTS = 5;

	/** Source bytes of SQL per database part (the gzip of it stays far under the 16 MB part limit). */
	public const DB_PART_BYTES = 12000000;

	/**
	 * Seconds one step may work: well inside the host's PHP time limit (30 s is common), since one part can still
	 * take several seconds to send after the check. A step stopped by the limit would redo work it had not saved.
	 */
	public static function budget(): float {
		$max = (int) ini_get( 'max_execution_time' );
		return $max > 0 ? (float) max( 4, min( self::BUDGET, $max - 16 ) ) : self::BUDGET;
	}

	/**
	 * Hook the steps and keep the schedules in line with Settings.
	 */
	public static function init(): void {
		add_action( self::STEP, array( self::class, 'step' ) );
		add_action( self::WEEKLY, array( self::class, 'scheduled_weekly' ) );
		add_action( self::DAILY, array( self::class, 'scheduled_daily' ) );
		add_action( 'init', array( self::class, 'schedule' ) );
		add_action( 'arvaseo_connector_maintenance', array( self::class, 'sweep' ) );
	}

	/**
	 * Weekly always, daily when asked for; both only while backups are on and the site is connected.
	 */
	public static function schedule(): void {
		$on = (bool) Settings::get( 'backups' ) && TokenStore::is_connected();
		foreach ( array(
			self::WEEKLY => array( 'weekly', true ),
			self::DAILY  => array( 'daily', (bool) Settings::get( 'backup_daily' ) ),
		) as $hook => $rule ) {
			$next = wp_next_scheduled( $hook );
			if ( $on && $rule[1] && false === $next ) {
				// A random hour in the first night, so many sites do not all start together.
				wp_schedule_event( time() + wp_rand( 600, 6 * HOUR_IN_SECONDS ), $rule[0], $hook );
			} elseif ( ( ! $on || ! $rule[1] ) && false !== $next ) {
				wp_clear_scheduled_hook( $hook );
			}
		}
	}

	/** The weekly job. */
	public static function scheduled_weekly(): void {
		self::start( 'weekly' );
	}

	/** The daily job. */
	public static function scheduled_daily(): void {
		self::start( 'daily' );
	}

	/**
	 * The running backup, if any.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function current(): ?array {
		$job = get_option( self::OPTION );
		return is_array( $job ) && ! empty( $job['id'] ) ? $job : null;
	}

	/**
	 * Where a running backup is, in plain words: the step, how many steps there are, and what it is doing.
	 *
	 * @param array<string, mixed> $job The running job.
	 * @return array{step:int, of:int, label:string}
	 */
	public static function progress( array $job ): array {
		$steps = array(
			'prepare'  => __( 'Checking what to back up', 'arvaseo-connector' ),
			'db'       => __( 'Copying the database', 'arvaseo-connector' ),
			'files'    => __( 'Packing your files', 'arvaseo-connector' ),
			'manifest' => __( 'Preparing the upload', 'arvaseo-connector' ),
			'upload'   => __( 'Sending it to Arva SEO', 'arvaseo-connector' ),
		);
		$phase = (string) ( $job['phase'] ?? 'prepare' );
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
	 * How the last backup ended: array{at:int, kind:string, status:string, bytes:int, message:string}.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function last(): ?array {
		$last = get_option( self::LAST );
		return is_array( $last ) ? $last : null;
	}

	/**
	 * Start a backup. Returns an error message when it cannot start now.
	 *
	 * @param string $kind weekly or daily.
	 * @return string|null Null when started, else why not.
	 */
	public static function start( string $kind ): ?string {
		if ( ! in_array( $kind, array( 'weekly', 'daily' ), true ) ) {
			return __( 'Unknown kind of backup.', 'arvaseo-connector' );
		}
		if ( ! Settings::get( 'backups' ) ) {
			return __( 'Backups are off. Turn them on in Settings first.', 'arvaseo-connector' );
		}
		if ( ! TokenStore::is_connected() ) {
			return __( 'Connect this site to Arva SEO first: backups are kept on Arva SEO’s servers.', 'arvaseo-connector' );
		}
		if ( ! class_exists( '\ZipArchive' ) || ! function_exists( 'gzopen' ) ) {
			return __( 'This server needs the PHP zip and zlib extensions to make backups. Ask your host to turn them on.', 'arvaseo-connector' );
		}
		if ( null !== self::current() ) {
			return __( 'A backup is already running.', 'arvaseo-connector' );
		}
		$id  = Ids::uuid();
		$dir = self::folder( $id );
		if ( ! self::make_folder( $dir ) ) {
			return __( 'Could not make a private folder for the backup inside wp-content. Check that the folder is writable.', 'arvaseo-connector' );
		}
		update_option(
			self::OPTION,
			array(
				'id'       => $id,
				'kind'     => $kind,
				'phase'    => 'prepare',
				'dir'      => $dir,
				'started'  => time(),
				'attempts' => 0,
				'parts'    => array(),
				'contents' => 'full',
			),
			false
		);
		AuditLogger::info( 'backup.started', __( 'A backup started.', 'arvaseo-connector' ), array( 'kind' => $kind ) );
		CronManager::run_soon( self::STEP, 5 );
		return null;
	}

	/**
	 * Do the next piece of the running backup.
	 */
	public static function step(): void {
		$job = self::current();
		if ( null === $job ) {
			return;
		}
		$started = microtime( true );
		unset( $job['phase_wait'] );
		try {
			do {
				$job = self::run_phase( $job );
			} while ( self::keep_going( $job, $started ) );
		} catch ( \Throwable $e ) {
			self::finish( $job, 'failed', __( 'The backup stopped because of an error on this site.', 'arvaseo-connector' ) . ' ' . wp_strip_all_tags( mb_substr( $e->getMessage(), 0, 160 ) ) );
			return;
		}
		if ( 'finished' === ( $job['phase'] ?? '' ) ) {
			return;
		}
		update_option( self::OPTION, $job, false );
		CronManager::run_soon( self::STEP, 5 );
	}

	/**
	 * Save the progress after each piece (a step the host stops loses at most one piece), and say whether there is time for another.
	 *
	 * @param array<string, mixed> $job     Job.
	 * @param float                $started When the step began.
	 */
	private static function keep_going( array $job, float $started ): bool {
		if ( 'finished' === $job['phase'] ) {
			return false;
		}
		update_option( self::OPTION, $job, false );
		return empty( $job['phase_wait'] ) && microtime( true ) - $started < self::budget();
	}

	/**
	 * Run the phase the job is in; returns the job with its cursor moved.
	 *
	 * @param array<string, mixed> $job Job.
	 * @return array<string, mixed>
	 */
	private static function run_phase( array $job ): array {
		switch ( $job['phase'] ) {
			case 'prepare':
				return self::prepare( $job );
			case 'db':
				return self::dump( $job );
			case 'files':
				return self::pack( $job );
			case 'manifest':
				return self::manifest( $job );
			default:
				return self::upload( $job );
		}
	}

	/**
	 * Ask Arva SEO what is allowed, then list what there is to back up.
	 *
	 * @param array<string, mixed> $job Job.
	 * @return array<string, mixed>
	 */
	private static function prepare( array $job ): array {
		$response = ApiClient::instance()->site( 'GET', '/backups', null, 15 );
		if ( ! $response->ok() ) {
			return self::retry_or_fail( $job, $response->message ?? __( 'Arva SEO could not be reached.', 'arvaseo-connector' ), $response->retryable() );
		}
		$data = $response->data();
		if ( empty( $data['enabled'] ) ) {
			return self::finish( $job, 'skipped', __( 'Backups on Arva SEO are not available yet.', 'arvaseo-connector' ) );
		}
		if ( 'daily' === $job['kind'] && (int) ( $data['daily']['remaining'] ?? 0 ) < 1 ) {
			return self::finish( $job, 'skipped', __( 'No daily backups are left for this website this month. A top-up adds more.', 'arvaseo-connector' ) );
		}
		$job['max_bytes'] = max( 1000000, (int) ( $data['max_bytes'] ?? 2000000000 ) );
		$job['tables']    = DatabaseDump::tables();
		$job['db']        = array(
			'table'  => 0,
			'offset' => 0,
			'header' => false,
		);
		$job['phase']     = 'db';
		$job['attempts']  = 0;
		return $job;
	}

	/**
	 * One database part.
	 *
	 * @param array<string, mixed> $job Job.
	 * @return array<string, mixed>
	 */
	private static function dump( array $job ): array {
		$number = count( $job['parts'] ) + 1;
		$name   = sprintf( 'part-%03d.sql.gz', $number );
		$out    = fopen( 'compress.zlib://' . $job['dir'] . $name, 'wb' );
		if ( false === $out ) {
			return self::finish( $job, 'failed', __( 'Could not write the backup’s database file.', 'arvaseo-connector' ) );
		}
		$cursor = $job['db'];
		$done   = DatabaseDump::write( $out, $cursor, $job['tables'], max( 2.0, self::budget() - 4 ), self::DB_PART_BYTES );
		fclose( $out );
		$job['db'] = $cursor;
		$job       = self::add_part( $job, 'db', $name );
		if ( $job['parts'][ count( $job['parts'] ) - 1 ]['bytes'] > 16000000 ) {
			return self::finish( $job, 'failed', __( 'A part of the database was too large to send. Tell Arva SEO support.', 'arvaseo-connector' ) );
		}
		if ( $done ) {
			$job['phase'] = 'files';
			$job['files'] = array();
		}
		return $job;
	}

	/**
	 * The file list on the first call, then one ZIP per call.
	 *
	 * @param array<string, mixed> $job Job.
	 * @return array<string, mixed>
	 */
	private static function pack( array $job ): array {
		$root = untrailingslashit( WP_CONTENT_DIR );
		if ( empty( $job['files'] ) ) {
			$list  = $job['dir'] . 'files.list';
			$found = FilePacker::make_list( $root, $list );
			// Files that already add up to more than the limit do not fit even if every one compressed to nothing much: database only.
			if ( 0 === $found['files'] || $found['bytes'] > $job['max_bytes'] ) {
				$job['contents'] = 0 === $found['files'] ? 'full' : 'database';
				$job['phase']    = 'manifest';
				return $job;
			}
			$job['files'] = array(
				'list'   => $list,
				'line'   => 0,
				'offset' => 0,
				'total'  => $found['files'],
			);
		}
		$cursor = array(
			'line'   => (int) $job['files']['line'],
			'offset' => (int) $job['files']['offset'],
			'total'  => (int) $job['files']['total'],
		);
		$name   = sprintf( 'part-%03d.zip', count( $job['parts'] ) + 1 );
		$result = FilePacker::pack_next( $root, $job['files']['list'], $cursor, $job['dir'] . $name );
		if ( null === $result ) {
			return self::finish( $job, 'failed', __( 'Could not write the backup’s files.', 'arvaseo-connector' ) );
		}
		$job['files']['line']   = $cursor['line'];
		$job['files']['offset'] = $cursor['offset'];
		$job                    = self::add_part( $job, 'files', $name );
		if ( $job['parts'][ count( $job['parts'] ) - 1 ]['bytes'] > 16000000 ) {
			return self::finish( $job, 'failed', __( 'A part of the files was too large to send. Tell Arva SEO support.', 'arvaseo-connector' ) );
		}
		if ( $result['done'] ) {
			$total = array_sum( array_column( $job['parts'], 'bytes' ) );
			if ( $total > $job['max_bytes'] ) {
				// Too big once packed: keep the database only.
				foreach ( $job['parts'] as $i => $part ) {
					if ( 'files' === $part['type'] ) {
						wp_delete_file( $job['dir'] . $part['name'] );
						unset( $job['parts'][ $i ] );
					}
				}
				$job['parts']    = array_values( $job['parts'] );
				$job['contents'] = 'database';
			}
			$job['phase'] = 'manifest';
		}
		return $job;
	}

	/**
	 * The manifest is the last part: what the parts are and where they came from.
	 *
	 * @param array<string, mixed> $job Job.
	 * @return array<string, mixed>
	 */
	private static function manifest( array $job ): array {
		global $wpdb;
		$name     = sprintf( 'part-%03d.json', count( $job['parts'] ) + 1 );
		$manifest = array(
			'format'       => 1,
			'created_at'   => Ids::iso(),
			'plugin'       => ARVASEO_CONNECTOR_VERSION,
			'site_url'     => home_url(),
			'wp_version'   => get_bloginfo( 'version' ),
			'php_version'  => PHP_VERSION,
			'table_prefix' => $wpdb->prefix,
			'contents'     => $job['contents'],
			'kind'         => $job['kind'],
			'parts'        => array_map( static fn( $p ) => array_intersect_key( $p, array_flip( array( 'n', 'type', 'name', 'bytes', 'sha256' ) ) ), $job['parts'] ),
		);
		file_put_contents( $job['dir'] . $name, (string) wp_json_encode( $manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		$job          = self::add_part( $job, 'manifest', $name );
		$job['phase'] = 'upload';
		$job['sent']  = 0;
		if ( array_sum( array_column( $job['parts'], 'bytes' ) ) > $job['max_bytes'] ) {
			return self::finish( $job, 'failed', __( 'This site’s database is over the 2 GB backup limit, so it could not be backed up.', 'arvaseo-connector' ) );
		}
		return $job;
	}

	/**
	 * Start the backup on Arva SEO (once), send the next part, or finish.
	 *
	 * @param array<string, mixed> $job Job.
	 * @return array<string, mixed>
	 */
	private static function upload( array $job ): array {
		$api   = ApiClient::instance();
		$parts = $job['parts'];
		if ( empty( $job['remote'] ) ) {
			$response = $api->site(
				'POST',
				'/backups',
				array(
					'backup_id' => $job['id'],
					'kind'      => $job['kind'],
					'contents'  => $job['contents'],
					'parts'     => count( $parts ),
					'bytes'     => (int) array_sum( array_column( $parts, 'bytes' ) ),
				),
				30
			);
			if ( ! $response->ok() ) {
				// Too soon after the last one, or not allowed: not an error, just not today.
				if ( in_array( $response->error_code, array( 'backup_recent', 'backups_unavailable' ), true ) ) {
					return self::finish( $job, 'skipped', (string) $response->message );
				}
				return self::retry_or_fail( $job, (string) ( $response->message ?? '' ), $response->retryable() );
			}
			$job['remote']   = (string) ( $response->data()['id'] ?? '' );
			$job['attempts'] = 0;
			if ( '' === $job['remote'] ) {
				return self::finish( $job, 'failed', __( 'Arva SEO did not accept the backup.', 'arvaseo-connector' ) );
			}
			return $job;
		}
		$sent = (int) $job['sent'];
		if ( $sent < count( $parts ) ) {
			$part  = $parts[ $sent ];
			$bytes = (string) file_get_contents( $job['dir'] . $part['name'] );
			$put   = $api->site_raw( 'PUT', '/backups/' . rawurlencode( $job['remote'] ) . '/parts/' . (int) $part['n'], $bytes, array( 'X-ArvaSEO-Part-SHA256' => $part['sha256'] ), 120 );
			if ( ! $put->ok() ) {
				return self::retry_or_fail( $job, (string) ( $put->message ?? '' ), $put->retryable() );
			}
			$job['sent']     = $sent + 1;
			$job['attempts'] = 0;
			return $job;
		}
		$done = $api->site( 'POST', '/backups/' . rawurlencode( $job['remote'] ) . '/complete', array(), 60 );
		if ( ! $done->ok() ) {
			return self::retry_or_fail( $job, (string) ( $done->message ?? '' ), $done->retryable() );
		}
		return self::finish( $job, 'complete', '' );
	}

	/**
	 * Record a part: its size and SHA-256.
	 *
	 * @param array<string, mixed> $job  Job.
	 * @param string               $type db, files or manifest.
	 * @param string               $name File name inside the job's folder.
	 * @return array<string, mixed>
	 */
	private static function add_part( array $job, string $type, string $name ): array {
		$path           = $job['dir'] . $name;
		$job['parts'][] = array(
			'n'      => count( $job['parts'] ) + 1,
			'type'   => $type,
			'name'   => $name,
			'bytes'  => (int) filesize( $path ),
			'sha256' => (string) hash_file( 'sha256', $path ),
		);
		return $job;
	}

	/**
	 * A failed call is tried again at the next step, a few times; then the backup is given up.
	 *
	 * @param array<string, mixed> $job       Job.
	 * @param string               $message   What Arva SEO said.
	 * @param bool                 $retryable Whether another try can help.
	 * @return array<string, mixed>
	 */
	private static function retry_or_fail( array $job, string $message, bool $retryable ): array {
		$job['attempts'] = (int) $job['attempts'] + 1;
		if ( ! $retryable || $job['attempts'] >= self::ATTEMPTS ) {
			return self::finish( $job, 'failed', '' !== $message ? $message : __( 'Arva SEO could not take the backup.', 'arvaseo-connector' ) );
		}
		// Yield: the next step tries again after the usual short wait.
		$job['phase_wait'] = true;
		return $job;
	}

	/**
	 * End the backup: tell Arva SEO if it was started there, remove the parts, remember how it went.
	 *
	 * @param array<string, mixed> $job     Job.
	 * @param string               $status  complete, skipped or failed.
	 * @param string               $message A plain sentence for the administrator.
	 * @return array<string, mixed>
	 */
	private static function finish( array $job, string $status, string $message ): array {
		if ( 'complete' !== $status && ! empty( $job['remote'] ) ) {
			ApiClient::instance()->site( 'POST', '/backups/' . rawurlencode( (string) $job['remote'] ) . '/fail', array( 'code' => 'plugin_' . $status ), 15 );
		}
		$bytes = (int) array_sum( array_column( (array) $job['parts'], 'bytes' ) );
		self::remove_folder( (string) $job['dir'] );
		delete_option( self::OPTION );
		update_option(
			self::LAST,
			array(
				'at'       => time(),
				'kind'     => (string) $job['kind'],
				'status'   => $status,
				'bytes'    => $bytes,
				'contents' => (string) $job['contents'],
				'message'  => $message,
			),
			false
		);
		// The list under the status comes from Arva SEO and is kept 10 minutes: drop it so a finished backup shows at once.
		Panel::forget();
		$text = match ( $status ) {
			'complete' => __( 'A backup was sent to Arva SEO.', 'arvaseo-connector' ),
			'skipped'  => __( 'A backup was not made.', 'arvaseo-connector' ),
			default    => __( 'A backup failed.', 'arvaseo-connector' ),
		};
		$context = array(
			'kind'     => (string) $job['kind'],
			'bytes'    => $bytes,
			'contents' => (string) $job['contents'],
			'reason'   => mb_substr( $message, 0, 200 ),
		);
		if ( 'failed' === $status ) {
			AuditLogger::error( 'backup.failed', $text, $context );
		} else {
			AuditLogger::info( 'backup.' . $status, $text, $context );
		}
		$job['phase'] = 'finished';
		return $job;
	}

	/**
	 * The folder for one backup's parts.
	 *
	 * @param string $id Backup ID.
	 */
	public static function folder( string $id ): string {
		return trailingslashit( WP_CONTENT_DIR ) . FilePacker::SKIP[3] . '/' . sanitize_file_name( $id ) . '/';
	}

	/**
	 * Make the folder, closed to visitors.
	 *
	 * @param string $dir Folder.
	 */
	public static function make_folder( string $dir ): bool {
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		$base = dirname( rtrim( $dir, '/' ) ) . '/';
		file_put_contents( $base . 'index.php', "<?php\n// Silence is golden.\n" );
		file_put_contents( $base . '.htaccess', "Require all denied\nDeny from all\n" );
		file_put_contents( $base . 'web.config', '<?xml version="1.0"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>' );
		return true;
	}

	/**
	 * Daily: remove what an old backup or restore left behind. A restore's folder holds the safety copy of the
	 * database from just before it, kept 7 days; a backup's parts that were never finished are removed after 2 days.
	 * Folders of a running backup or restore are never touched.
	 */
	public static function sweep(): void {
		$base = untrailingslashit( WP_CONTENT_DIR ) . '/' . FilePacker::SKIP[3];
		if ( ! is_dir( $base ) ) {
			return;
		}
		$in_use = array(
			rtrim( (string) ( self::current()['dir'] ?? '' ), '/' ),
			rtrim( (string) ( Restorer::current()['dir'] ?? '' ), '/' ),
		);
		foreach ( (array) glob( $base . '/*', GLOB_ONLYDIR ) as $dir ) {
			$limit = str_starts_with( basename( $dir ), 'restore-' ) ? 7 * DAY_IN_SECONDS : 2 * DAY_IN_SECONDS;
			if ( in_array( $dir, $in_use, true ) || time() - (int) filemtime( $dir ) < $limit ) {
				continue;
			}
			self::remove_tree( $dir );
		}
	}

	/**
	 * Remove a folder and everything in it.
	 *
	 * @param string $dir Folder.
	 */
	private static function remove_tree( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $items as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : wp_delete_file( $item->getPathname() );
		}
		rmdir( $dir );
	}

	/**
	 * Remove every backup folder (uninstall): parts, restore downloads and safety copies hold the site's data.
	 */
	public static function delete_all_folders(): void {
		self::remove_tree( untrailingslashit( WP_CONTENT_DIR ) . '/' . FilePacker::SKIP[3] );
	}

	/**
	 * Remove one backup's folder (not the closed base folder).
	 *
	 * @param string $dir Folder.
	 */
	private static function remove_folder( string $dir ): void {
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $name ) {
			if ( '.' !== $name && '..' !== $name ) {
				wp_delete_file( $dir . $name );
			}
		}
		rmdir( $dir );
	}
}
