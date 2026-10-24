<?php
/**
 * Compress images: the media library's JPEG, PNG and WebP files saved smaller. On Arva SEO's servers
 * when the site is connected and an administrator allowed it (counted against the plan's monthly
 * image compressions), otherwise on this server. WebP files stay on this server.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Speed;

use ArvaSEO\Connector\Api\ApiClient;
use ArvaSEO\Connector\Auth\TokenStore;
use ArvaSEO\Connector\Logging\AuditLogger;
use ArvaSEO\Connector\Privacy\PrivacyService;
use ArvaSEO\Connector\Support\Lock;

defined( 'ABSPATH' ) || exit;

/**
 * New uploads are queued once WordPress has made their sizes; the library is done in batches on
 * WP-Cron, as the WebP conversion is. A file is replaced only when the result is at least 5%
 * smaller, and the original is kept in wp-content/arvaseo-originals (unless the administrator
 * turned that off) so "Restore the originals" can put it back.
 *
 * On this server: full-size JPEGs above quality 85 are saved at 82 with their metadata removed
 * (the colour profile is kept, and a photo that still needs turning upright is left alone), and,
 * with Imagick, PNGs are saved again without loss. WordPress already makes the smaller sizes at 82.
 */
final class ImageCompression {

	public const HOOK = 'arvaseo_connector_compress_batch';

	/** Post meta: the files done, by name, as [bytes before, bytes after, where]. */
	public const META = '_arvaseo_compressed';

	/** Progress, settings and the cloud's state (an option, not autoloaded). */
	public const OPTION = 'arvaseo_connector_compress';

	/** New uploads waiting (their own option, so a batch never loses one added meanwhile). */
	public const QUEUE = 'arvaseo_connector_compress_queue';

	/** Images done and bytes saved. */
	public const STATS = 'arvaseo_connector_compress_stats';

	/** Where originals are kept, inside wp-content. */
	public const FOLDER = 'arvaseo-originals';

	public const QUALITY = 82;

	/** A JPEG at this quality or lower isn't saved again here: it would only lose detail. */
	public const KEEP_QUALITY = 85;

	/** At least this much smaller, or the file stays as it was. */
	public const MIN_SAVING = 0.05;

	/** One request to Arva SEO: this many files and bytes at most. */
	public const CLOUD_FILES = 12;

	public const CLOUD_BYTES = 15000000;

	private const MAX_FILE_BYTES = 15000000;

	private const MAX_PIXELS = 40000000;

	private const SECONDS = 20;

	private const QUEUE_MAX = 1000;

	private const MIMES = array( 'image/jpeg', 'image/png', 'image/webp' );

	/**
	 * Hooks: the batches, new uploads, and deleted images.
	 */
	public static function init(): void {
		add_action( self::HOOK, array( self::class, 'batch' ) );
		add_filter( 'wp_update_attachment_metadata', array( self::class, 'uploaded' ), 20, 2 );
		add_action( 'delete_attachment', array( self::class, 'deleted' ) );
	}

	/**
	 * Progress and settings.
	 *
	 * @return array{running: bool, done: int, total: int, last_id: int, finished_at: string|null, restoring: bool, keep_originals: bool, cloud_paused_until: int, cloud_reason: string, allowance: array<string, mixed>|null}
	 */
	public static function state(): array {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		return array(
			'running'            => (bool) ( $saved['running'] ?? false ),
			'done'               => (int) ( $saved['done'] ?? 0 ),
			'total'              => (int) ( $saved['total'] ?? 0 ),
			'last_id'            => (int) ( $saved['last_id'] ?? 0 ),
			'finished_at'        => is_string( $saved['finished_at'] ?? null ) ? $saved['finished_at'] : null,
			'restoring'          => (bool) ( $saved['restoring'] ?? false ),
			'keep_originals'     => (bool) ( $saved['keep_originals'] ?? true ),
			'cloud_paused_until' => (int) ( $saved['cloud_paused_until'] ?? 0 ),
			'cloud_reason'       => (string) ( $saved['cloud_reason'] ?? '' ),
			'allowance'          => is_array( $saved['allowance'] ?? null ) ? $saved['allowance'] : null,
		);
	}

	/**
	 * Images done and bytes saved, and how many of them Arva SEO's servers did.
	 *
	 * @return array{images: int, saved_bytes: int, cloud: int}
	 */
	public static function stats(): array {
		$saved = get_option( self::STATS, array() );
		$saved = is_array( $saved ) ? $saved : array();
		return array(
			'images'      => max( 0, (int) ( $saved['images'] ?? 0 ) ),
			'saved_bytes' => max( 0, (int) ( $saved['saved_bytes'] ?? 0 ) ),
			'cloud'       => max( 0, (int) ( $saved['cloud'] ?? 0 ) ),
		);
	}

	/**
	 * Current files checked and measured bytes saved, for native Media Library feedback.
	 * A file changed since compression is not reported as done or included in its savings.
	 *
	 * @param int $id Attachment ID.
	 * @return array{files: int, done: int, saved_bytes: int}
	 */
	public static function attachment_status( int $id ): array {
		$files  = self::files( $id );
		$record = self::record( $id );
		$status = array(
			'files'       => count( $files ),
			'done'        => 0,
			'saved_bytes' => 0,
		);
		foreach ( $files as $name => $file ) {
			$done = $record[ $name ] ?? null;
			clearstatcache( true, $file['path'] );
			if ( ! is_array( $done ) || ! isset( $done[0], $done[1] ) || (int) @filesize( $file['path'] ) !== (int) $done[1] ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a missing file is not done.
				continue;
			}
			++$status['done'];
			$status['saved_bytes'] += max( 0, (int) $done[0] - (int) $done[1] );
		}
		return $status;
	}

	/**
	 * Whether Arva SEO's servers compress the next image: connected, allowed, and not paused
	 * (no compressions left, or Arva SEO didn't answer a moment ago).
	 */
	public static function cloud_ready(): bool {
		return TokenStore::is_connected() && PrivacyService::allows( 'image_compression' ) && time() >= self::state()['cloud_paused_until'];
	}

	/**
	 * Keep the originals (true), or replace files without a copy (false).
	 *
	 * @param bool $keep Keep them.
	 */
	public static function keep_originals( bool $keep ): void {
		self::update( array( 'keep_originals' => $keep ) );
	}

	// ----- New uploads -------------------------------------------------------------------------

	/**
	 * After WordPress saves an image's sizes: queue it.
	 *
	 * @param mixed $meta Attachment metadata.
	 * @param int   $id   Attachment ID.
	 * @return mixed
	 */
	public static function uploaded( $meta, $id ) {
		if ( is_array( $meta ) && 'on' === Fixes::state( 'compress_images' ) && ! self::killed() && in_array( get_post_mime_type( (int) $id ), self::MIMES, true ) ) {
			self::enqueue( (int) $id );
		}
		return $meta;
	}

	/**
	 * Add an image to the queue (once), and run it in a minute: WordPress may still be saving sizes.
	 *
	 * @param int $id Attachment ID.
	 */
	public static function enqueue( int $id ): void {
		$queue = self::queue();
		if ( ! in_array( $id, $queue, true ) && count( $queue ) < self::QUEUE_MAX ) {
			$queue[] = $id;
			update_option( self::QUEUE, $queue, false );
		}
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK );
		}
	}

	/**
	 * The images waiting.
	 *
	 * @return int[]
	 */
	public static function queue(): array {
		return array_values( array_unique( array_map( 'intval', (array) get_option( self::QUEUE, array() ) ) ) );
	}

	// ----- The library -------------------------------------------------------------------------

	/**
	 * Images in the media library that compression could work on, and how many are done.
	 *
	 * @return array{images: int, done: int}
	 */
	public static function library(): array {
		global $wpdb;
		return array(
			'images' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ('image/jpeg', 'image/png', 'image/webp')" ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- a count for the Speed page.
			'done'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", self::META ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- see above.
		);
	}

	/**
	 * Start compressing the library (or carry on from where it stopped).
	 */
	public static function start(): bool {
		if ( 'on' !== Fixes::state( 'compress_images' ) || self::killed() ) {
			return false;
		}
		$state   = self::state();
		$changes = array(
			'running'   => true,
			'restoring' => false,
			'total'     => self::library()['images'],
		);
		if ( null !== $state['finished_at'] ) {
			$changes += array(
				'done'        => 0,
				'last_id'     => 0,
				'finished_at' => null,
			);
		}
		self::update( $changes );
		self::schedule( 0 );
		AuditLogger::info( 'speed.compress_started', __( 'PageSpeed fixes: compressing the media library started.', 'arvaseo-connector' ) );
		return true;
	}

	/**
	 * Stop after the batch that's running (the queue of new uploads carries on).
	 */
	public static function stop(): void {
		self::update(
			array(
				'running'   => false,
				'restoring' => false,
			)
		);
		AuditLogger::info( 'speed.compress_stopped', __( 'PageSpeed fixes: compressing the media library stopped.', 'arvaseo-connector' ) );
	}

	/**
	 * Put every kept original back, in batches.
	 */
	public static function restore_all(): void {
		self::update(
			array(
				'running'   => false,
				'restoring' => true,
			)
		);
		update_option( self::QUEUE, array(), false );
		self::schedule( 0 );
		AuditLogger::info( 'speed.compress_restoring', __( 'PageSpeed fixes: putting the original images back.', 'arvaseo-connector' ) );
	}

	/**
	 * One batch (WP-Cron): originals being put back, else new uploads, then the library.
	 */
	public static function batch(): void {
		if ( ! Lock::acquire( 'compress', 120 ) ) {
			return;
		}
		$until = microtime( true ) + self::SECONDS;
		$state = self::state();
		$again = null;
		if ( $state['restoring'] ) {
			$again = self::restore_batch( $until );
		} elseif ( 'on' === Fixes::state( 'compress_images' ) && ! self::killed() ) {
			$again = self::queue_batch( $until );
			if ( null === $again && $state['running'] ) {
				$again = self::library_batch( $until );
			}
		}
		Lock::release( 'compress' );
		if ( null !== $again ) {
			self::schedule( $again );
		}
	}

	/**
	 * New uploads. Returns seconds until the next batch, or null when the queue is empty.
	 *
	 * @param float $until Deadline.
	 */
	private static function queue_batch( float $until ): ?int {
		foreach ( self::queue() as $id ) {
			if ( microtime( true ) >= $until ) {
				return 5;
			}
			if ( null === self::compress( $id ) ) {
				return 5 * MINUTE_IN_SECONDS; // Arva SEO didn't answer: this one waits.
			}
			update_option( self::QUEUE, array_values( array_diff( self::queue(), array( $id ) ) ), false );
		}
		return array() === self::queue() ? null : 5;
	}

	/**
	 * The library, a few images at a time. Returns seconds until the next batch, or null when done.
	 *
	 * @param float $until Deadline.
	 */
	private static function library_batch( float $until ): ?int {
		global $wpdb;
		$state = self::state();
		$ids   = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ('image/jpeg', 'image/png', 'image/webp') AND ID > %d ORDER BY ID ASC LIMIT 10", $state['last_id'] ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- a batch.
		foreach ( $ids as $id ) {
			if ( microtime( true ) >= $until ) {
				return 5;
			}
			if ( null === self::compress( $id ) ) {
				return 5 * MINUTE_IN_SECONDS;
			}
			$state = self::state();
			self::update(
				array(
					'last_id' => $id,
					'done'    => $state['done'] + 1,
				)
			);
		}
		if ( count( $ids ) < 10 ) {
			self::update(
				array(
					'running'     => false,
					'finished_at' => gmdate( 'c' ),
				)
			);
			$stats = self::stats();
			AuditLogger::info(
				'speed.compress_finished',
				/* translators: %d: number of images. */
				sprintf( _n( 'PageSpeed fixes: the media library is compressed (%d image smaller so far).', 'PageSpeed fixes: the media library is compressed (%d images smaller so far).', $stats['images'], 'arvaseo-connector' ), $stats['images'] ),
				array( 'saved_bytes' => $stats['saved_bytes'] )
			);
			return null;
		}
		return 5;
	}

	/**
	 * Originals back, a few images at a time. Returns seconds until the next batch, or null.
	 *
	 * @param float $until Deadline.
	 */
	private static function restore_batch( float $until ): ?int {
		global $wpdb;
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT 20", self::META ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- a batch.
		foreach ( $ids as $id ) {
			if ( microtime( true ) >= $until ) {
				return 5;
			}
			self::restore( $id );
		}
		if ( count( $ids ) < 20 ) {
			self::update(
				array(
					'restoring'   => false,
					'done'        => 0,
					'last_id'     => 0,
					'finished_at' => null,
				)
			);
			update_option( self::STATS, array(), false );
			Fixes::pages_changed( 'compress' );
			AuditLogger::info( 'speed.compress_restored', __( 'PageSpeed fixes: the original images are back.', 'arvaseo-connector' ) );
			return null;
		}
		return 5;
	}

	// ----- One image ---------------------------------------------------------------------------

	/**
	 * Compress one image and its sizes. Returns the bytes saved, or null when Arva SEO's servers
	 * didn't answer and the image should wait for them.
	 *
	 * @param int $id Attachment ID.
	 */
	public static function compress( int $id ): ?int {
		$files = self::files( $id );
		if ( array() === $files ) {
			return 0;
		}
		$record = self::record( $id );
		$todo   = array();
		foreach ( $files as $name => $file ) {
			$done = $record[ $name ] ?? null;
			// Done already, unless the file changed since (an edit, or new sizes made again).
			if ( ! is_array( $done ) || (int) @filesize( $file['path'] ) !== (int) $done[1] ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a missing file is skipped below.
				$todo[ $name ] = $file;
			}
		}
		if ( array() === $todo ) {
			return 0;
		}

		$results = array(); // name => [temporary file, where].
		$sent    = array(); // Names Arva SEO's servers looked at: not tried again here.
		// The cloud endpoint accepts JPEG/PNG; WebP stays on this server.
		$cloud_files = array_filter( $todo, static fn ( $file ) => 'image/webp' !== $file['mime'] );
		if ( array() !== $cloud_files && self::cloud_ready() ) {
			$cloud = self::cloud( $id, $cloud_files );
			if ( 'later' === $cloud ) {
				return null;
			}
			if ( is_array( $cloud ) ) {
				$sent = $cloud['sent'];
				foreach ( $cloud['out'] as $name => $tmp ) {
					$results[ $name ] = array( $tmp, 'cloud' );
				}
			}
		}
		foreach ( $todo as $name => $file ) {
			if ( ! isset( $results[ $name ] ) && ! in_array( $name, $sent, true ) && ( 'full' === $file['size'] || 'image/jpeg' !== $file['mime'] ) ) {
				$tmp = self::local( $file['path'], $file['mime'] );
				if ( null !== $tmp ) {
					$results[ $name ] = array( $tmp, 'local' );
				}
			}
		}

		$keep  = self::state()['keep_originals'];
		$saved = 0;
		$cloud = false;
		foreach ( $results as $name => list( $tmp, $where ) ) {
			$path   = $todo[ $name ]['path'];
			$before = (int) @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
			$after  = (int) @filesize( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
			if ( $before <= 0 || $after <= 0 || $after > $before * ( 1 - self::MIN_SAVING ) || ! self::replace( $path, $tmp, $keep, isset( $record[ $name ] ) ) ) {
				wp_delete_file( $tmp );
				continue;
			}
			$record[ $name ] = array( $before, $after, $where );
			$saved          += $before - $after;
			$cloud           = $cloud || 'cloud' === $where;
			self::drop_bigger_webp( $id, $path );
		}
		// Files that couldn't get smaller count as done too, so they aren't tried again.
		foreach ( $todo as $name => $file ) {
			$size = (int) @filesize( $file['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
			if ( ! isset( $record[ $name ] ) || (int) $record[ $name ][1] !== $size ) {
				$record[ $name ] = array( $size, $size, 'unchanged' );
			}
		}
		update_post_meta( $id, self::META, $record );
		if ( $saved > 0 ) {
			$stats = self::stats();
			update_option(
				self::STATS,
				array(
					'images'      => $stats['images'] + 1,
					'saved_bytes' => $stats['saved_bytes'] + $saved,
					'cloud'       => $stats['cloud'] + ( $cloud ? 1 : 0 ),
				),
				false
			);
			Fixes::pages_changed( 'compress' );
		}
		return $saved;
	}

	/**
	 * Put one image's originals back (only files that haven't changed since they were compressed).
	 * Returns how many files.
	 *
	 * @param int $id Attachment ID.
	 */
	public static function restore( int $id ): int {
		$record = self::record( $id );
		$files  = self::files( $id );
		$count  = 0;
		foreach ( $record as $name => $done ) {
			$path   = $files[ $name ]['path'] ?? null;
			$backup = null !== $path ? self::backup_path( $path ) : null;
			if ( null === $path || null === $backup || ! is_file( $backup ) || 'unchanged' === ( $done[2] ?? '' ) ) {
				continue;
			}
			clearstatcache( true, $path );
			if ( (int) @filesize( $path ) === (int) $done[1] && @rename( $backup, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename -- putting a kept original back in place.
				++$count;
			}
		}
		delete_post_meta( $id, self::META );
		return $count;
	}

	/**
	 * An image deleted from the media library takes its kept originals with it.
	 *
	 * @param int $id Attachment ID.
	 */
	public static function deleted( $id ): void {
		foreach ( self::files( (int) $id ) as $file ) {
			$backup = self::backup_path( $file['path'] );
			if ( null !== $backup && is_file( $backup ) ) {
				wp_delete_file( $backup );
			}
		}
		delete_post_meta( (int) $id, self::META );
		update_option( self::QUEUE, array_values( array_diff( self::queue(), array( (int) $id ) ) ), false );
	}

	// ----- On this server ----------------------------------------------------------------------

	/**
	 * Save a file smaller here. Returns a temporary file at least 5% smaller, or null.
	 *
	 * @param string $path Absolute path.
	 * @param string $mime image/jpeg, image/png or image/webp.
	 */
	public static function local( string $path, string $mime ): ?string {
		$size = (int) @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- unreadable files are skipped.
		$dims = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
		if ( $size < 1024 || $size > self::MAX_FILE_BYTES || ! is_array( $dims ) || $dims[0] * $dims[1] > self::MAX_PIXELS ) {
			return null;
		}
		$tmp = $path . '.arvaseo-tmp';
		if ( self::imagick_supports( $mime ) ) {
			$made = self::with_imagick( $path, $tmp, $mime );
		} elseif ( 'image/jpeg' === $mime ) {
			$made = self::with_editor( $path, $tmp );
		} else {
			$made = false; // GD can't save a PNG smaller without loss.
		}
		clearstatcache( true, $tmp );
		$after = $made ? (int) @filesize( $tmp ) : 0; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
		if ( $after <= 0 || $after > $size * ( 1 - self::MIN_SAVING ) ) {
			if ( is_file( $tmp ) ) {
				wp_delete_file( $tmp );
			}
			return null;
		}
		return $tmp;
	}

	/**
	 * The extension can be present while its JPEG/PNG/WebP decoder is unavailable.
	 * A working Imagick decoder still owns its quality, orientation and animation safeguards.
	 *
	 * @param string $mime Image MIME type.
	 */
	private static function imagick_supports( string $mime ): bool {
		if ( ! extension_loaded( 'imagick' ) || ! class_exists( '\\Imagick' ) ) {
			return false;
		}
		$formats = array(
			'image/jpeg' => 'JPEG',
			'image/png'  => 'PNG',
			'image/webp' => 'WEBP',
		);
		try {
			return isset( $formats[ $mime ] ) && array() !== \Imagick::queryFormats( $formats[ $mime ] );
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	/**
	 * With Imagick: metadata out (the colour profile stays), JPEG at quality 82, PNG/WebP without loss.
	 *
	 * @param string $path Source.
	 * @param string $tmp  Target.
	 * @param string $mime Type.
	 */
	private static function with_imagick( string $path, string $tmp, string $mime ): bool {
		try {
			$image = new \Imagick( $path );
			if ( $image->getNumberImages() > 1 || ! in_array( $image->getImageOrientation(), array( \Imagick::ORIENTATION_UNDEFINED, \Imagick::ORIENTATION_TOPLEFT ), true ) ) {
				return false; // Animated, or a photo that still needs turning upright: left as it is.
			}
			$quality = (int) $image->getImageCompressionQuality();
			if ( 'image/jpeg' === $mime && $quality > 0 && $quality <= self::KEEP_QUALITY ) {
				return false;
			}
			$icc = $image->getImageProfiles( 'icc', true );
			$image->stripImage();
			if ( isset( $icc['icc'] ) ) {
				$image->profileImage( 'icc', $icc['icc'] );
			}
			if ( 'image/jpeg' === $mime ) {
				$image->setImageCompressionQuality( self::QUALITY );
				$image->setInterlaceScheme( \Imagick::INTERLACE_PLANE );
				$written = $image->writeImage( 'jpeg:' . $tmp );
			} elseif ( 'image/webp' === $mime ) {
				// Lossless rewriting preserves the existing WebP pixels and transparency.
				$image->setOption( 'webp:lossless', 'true' );
				$image->setOption( 'webp:method', '6' );
				$written = $image->writeImage( 'webp:' . $tmp );
			} else {
				$image->setOption( 'png:compression-level', '9' );
				$image->setOption( 'png:compression-filter', '5' );
				$written = $image->writeImage( 'png:' . $tmp );
			}
			$image->clear();
			return (bool) $written;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * With WordPress's image editor (GD): JPEGs only, and only plain upright photos, because GD
	 * drops colour profiles and doesn't turn photos upright.
	 *
	 * @param string $path Source.
	 * @param string $tmp  Target.
	 */
	private static function with_editor( string $path, string $tmp ): bool {
		$head = (string) @file_get_contents( $path, false, null, 0, 131072 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the start of a local image.
		if ( str_contains( $head, 'ICC_PROFILE' ) ) {
			return false;
		}
		if ( function_exists( 'exif_read_data' ) ) {
			$exif = @exif_read_data( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- photos without EXIF are fine.
			if ( is_array( $exif ) && (int) ( $exif['Orientation'] ?? 1 ) > 1 ) {
				return false;
			}
		}
		$editor = wp_get_image_editor( $path );
		if ( is_wp_error( $editor ) ) {
			return false;
		}
		$editor->set_quality( self::QUALITY );
		// Upload-format conversion must not put WebP bytes inside an existing JPEG filename.
		$keep_jpeg = static function ( array $formats ): array {
			$formats['image/jpeg'] = 'image/jpeg';
			return $formats;
		};
		add_filter( 'image_editor_output_format', $keep_jpeg, PHP_INT_MAX );
		try {
			$saved = $editor->save( $tmp . '.jpg', 'image/jpeg' );
		} finally {
			remove_filter( 'image_editor_output_format', $keep_jpeg, PHP_INT_MAX );
		}
		if ( is_wp_error( $saved ) || ( $saved['path'] ?? '' ) !== $tmp . '.jpg' || ! is_file( $tmp . '.jpg' ) ) {
			return false;
		}
		if ( 'image/jpeg' !== ( $saved['mime-type'] ?? '' ) || 'image/jpeg' !== wp_get_image_mime( $tmp . '.jpg' ) ) {
			wp_delete_file( $tmp . '.jpg' );
			return false;
		}
		return @rename( $tmp . '.jpg', $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename -- the editor's file, renamed to the temporary name.
	}

	// ----- On Arva SEO's servers ---------------------------------------------------------------

	/**
	 * Send an image's files to Arva SEO and save the smaller ones it sends back. Returns the
	 * names sent and name => temporary file for the smaller ones, 'later' when Arva SEO didn't
	 * answer, or null when it can't do this one (the files are compressed here instead).
	 *
	 * @param int                                                            $id    Attachment ID.
	 * @param array<string, array{path: string, mime: string, size: string}> $files Files to do.
	 * @return array{sent: string[], out: array<string, string>}|string|null
	 */
	private static function cloud( int $id, array $files ) {
		$send  = array();
		$bytes = 0;
		foreach ( $files as $name => $file ) {
			$size = (int) @filesize( $file['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- unreadable files are skipped.
			if ( $size < 1 || count( $send ) >= self::CLOUD_FILES || $bytes + $size > self::CLOUD_BYTES ) {
				continue;
			}
			$contents = @file_get_contents( $file['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- an image the administrator chose to compress.
			if ( false === $contents ) {
				continue;
			}
			$send[] = array(
				'name'    => (string) $name,
				'mime'    => $file['mime'],
				'content' => base64_encode( $contents ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- images travel as base64 in JSON.
			);
			$bytes += $size;
		}
		if ( array() === $send ) {
			return null;
		}
		$response = ApiClient::instance()->site(
			'POST',
			'/images',
			array(
				'attachment' => $id,
				'files'      => $send,
			),
			90
		);
		self::remember_allowance( (array) ( $response->body['allowance'] ?? array() ) );
		if ( ! $response->ok() ) {
			return self::cloud_failed( $response );
		}
		if ( '' !== self::state()['cloud_reason'] ) {
			self::update(
				array(
					'cloud_paused_until' => 0,
					'cloud_reason'       => '',
				)
			);
		}
		$out = array();
		foreach ( (array) ( $response->data()['files'] ?? array() ) as $file ) {
			$name     = is_array( $file ) ? (string) ( $file['name'] ?? '' ) : '';
			$contents = isset( $file['content'] ) && is_string( $file['content'] ) ? base64_decode( $file['content'], true ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- the smaller image from Arva SEO.
			if ( ! isset( $files[ $name ] ) || false === $contents || '' === $contents || ! self::same_type( $contents, $files[ $name ]['mime'] ) ) {
				continue;
			}
			$tmp = $files[ $name ]['path'] . '.arvaseo-tmp';
			if ( false !== file_put_contents( $tmp, $contents ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- the smaller copy, next to its file.
				$out[ $name ] = $tmp;
			}
		}
		return array(
			'sent' => array_column( $send, 'name' ),
			'out'  => $out,
		);
	}

	/**
	 * When Arva SEO refused or didn't answer: wait, or compress here for a while.
	 *
	 * @param \ArvaSEO\Connector\Api\ApiResponse $response Response.
	 * @return string|null 'later', or null to compress here.
	 */
	private static function cloud_failed( $response ): ?string {
		if ( 402 === $response->status ) {
			// None left this month: here until the allowance resets (or a top-up is bought).
			$resets = strtotime( (string) ( self::state()['allowance']['resets_at'] ?? '' ) );
			self::pause( false !== $resets && $resets > time() ? min( $resets, time() + DAY_IN_SECONDS ) : time() + HOUR_IN_SECONDS, 'no_allowance' );
			return null;
		}
		if ( in_array( $response->status, array( 401, 403, 404, 405, 409, 413, 422 ), true ) ) {
			self::pause( time() + HOUR_IN_SECONDS, (string) ( $response->error_code ?? 'refused' ) );
			return null;
		}
		self::pause( time() + max( 5 * MINUTE_IN_SECONDS, (int) $response->retry_after ), 'unavailable' );
		return 'later';
	}

	/**
	 * Use this server until a time.
	 *
	 * @param int    $until  Timestamp.
	 * @param string $reason Why.
	 */
	private static function pause( int $until, string $reason ): void {
		self::update(
			array(
				'cloud_paused_until' => $until,
				'cloud_reason'       => $reason,
			)
		);
	}

	/**
	 * The plan's image compressions this month, as Arva SEO last said.
	 *
	 * @param array<string, mixed> $allowance From Arva SEO.
	 */
	private static function remember_allowance( array $allowance ): void {
		if ( ! isset( $allowance['remaining'] ) ) {
			return;
		}
		self::update(
			array(
				'allowance' => array(
					'included'  => (int) ( $allowance['included'] ?? 0 ),
					'used'      => (int) ( $allowance['used'] ?? 0 ),
					'packs'     => (int) ( $allowance['packs'] ?? 0 ),
					'remaining' => (int) $allowance['remaining'],
					'resets_at' => (string) ( $allowance['resets_at'] ?? '' ),
					// Only a link into the Arva SEO app.
					'buy_url'   => ApiClient::app_link( $allowance['buy_url'] ?? null ),
					'at'        => time(),
				),
			)
		);
	}

	/**
	 * Whether bytes are the image type they should be (JPEG or PNG), by their first bytes.
	 *
	 * @param string $contents Bytes.
	 * @param string $mime     Expected type.
	 */
	private static function same_type( string $contents, string $mime ): bool {
		return 'image/jpeg' === $mime ? str_starts_with( $contents, "\xFF\xD8\xFF" ) : str_starts_with( $contents, "\x89PNG\r\n\x1A\n" );
	}

	// ----- Files -------------------------------------------------------------------------------

	/**
	 * An image's JPEG, PNG and WebP files, by name: the full size first, then the sizes WordPress made.
	 *
	 * @param int $id Attachment ID.
	 * @return array<string, array{path: string, mime: string, size: string}>
	 */
	private static function files( int $id ): array {
		$file = get_attached_file( $id );
		$meta = wp_get_attachment_metadata( $id );
		$mime = (string) get_post_mime_type( $id );
		if ( ! is_string( $file ) || ! is_file( $file ) || ! is_array( $meta ) || ! in_array( $mime, self::MIMES, true ) ) {
			return array();
		}
		$dir   = dirname( $file );
		$files = array(
			basename( $file ) => array(
				'path' => $file,
				'mime' => $mime,
				'size' => 'full',
			),
		);
		foreach ( (array) ( $meta['sizes'] ?? array() ) as $size => $data ) {
			$name = is_array( $data ) && is_string( $data['file'] ?? null ) ? basename( $data['file'] ) : '';
			$type = is_array( $data ) ? (string) ( $data['mime-type'] ?? $mime ) : '';
			if ( '' !== $name && in_array( $type, self::MIMES, true ) && is_file( $dir . '/' . $name ) && ! isset( $files[ $name ] ) ) {
				$files[ $name ] = array(
					'path' => $dir . '/' . $name,
					'mime' => $type,
					'size' => (string) $size,
				);
			}
		}
		return $files;
	}

	/**
	 * What was done to an image's files.
	 *
	 * @param int $id Attachment ID.
	 * @return array<string, array{0: int, 1: int, 2: string}>
	 */
	private static function record( int $id ): array {
		$record = get_post_meta( $id, self::META, true );
		return is_array( $record ) ? $record : array();
	}

	/**
	 * Swap in the smaller file, keeping the original first (unless the administrator turned that off).
	 *
	 * @param string $path    The file.
	 * @param string $tmp     The smaller copy, in the same folder.
	 * @param bool   $keep    Keep the original.
	 * @param bool   $changed The file changed since an earlier compression: its kept copy is old.
	 */
	private static function replace( string $path, string $tmp, bool $keep, bool $changed ): bool {
		if ( $keep ) {
			$backup = self::backup_path( $path );
			if ( null === $backup ) {
				return false;
			}
			if ( $changed || ! is_file( $backup ) ) {
				self::protect_folder();
				wp_mkdir_p( dirname( $backup ) );
				if ( ! @copy( $path, $backup ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failed copy stops the swap.
					return false;
				}
			}
		}
		$perms = (int) @fileperms( $path ) & 0777; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the file's permissions, kept.
		if ( ! @rename( $tmp, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename -- the smaller copy takes the file's place in one step.
			return false;
		}
		@chmod( $path, $perms > 0 ? $perms : 0644 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- see above.
		clearstatcache( true, $path );
		return true;
	}

	/**
	 * Where a file's original is kept: the same path under wp-content/arvaseo-originals, from the
	 * uploads folder. Null for a file outside the uploads folder.
	 *
	 * @param string $path The file.
	 */
	public static function backup_path( string $path ): ?string {
		$uploads = wp_normalize_path( (string) wp_upload_dir( null, false )['basedir'] );
		$path    = wp_normalize_path( $path );
		if ( '' === $uploads || ! str_starts_with( $path, trailingslashit( $uploads ) ) ) {
			return null;
		}
		return wp_normalize_path( WP_CONTENT_DIR ) . '/' . self::FOLDER . '/' . substr( $path, strlen( trailingslashit( $uploads ) ) );
	}

	/**
	 * The originals folder doesn't list its files or answer web requests.
	 */
	private static function protect_folder(): void {
		$dir = wp_normalize_path( WP_CONTENT_DIR ) . '/' . self::FOLDER;
		if ( is_file( $dir . '/index.php' ) ) {
			return;
		}
		wp_mkdir_p( $dir );
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		global $wp_filesystem;
		if ( 'direct' !== get_filesystem_method( array(), $dir, true ) || ! WP_Filesystem( false, $dir, true ) || ! $wp_filesystem instanceof \WP_Filesystem_Base ) {
			return; // The folder still works; it just isn't closed to web requests.
		}
		$wp_filesystem->put_contents( $dir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n", FS_CHMOD_FILE );
		$wp_filesystem->put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n", FS_CHMOD_FILE );
	}

	/**
	 * A WebP copy that isn't smaller than its newly compressed file any more is dropped, so pages
	 * show the smaller one.
	 *
	 * @param int    $id   Attachment ID.
	 * @param string $path The compressed file.
	 */
	private static function drop_bigger_webp( int $id, string $path ): void {
		$copies = get_post_meta( $id, WebpLibrary::META, true );
		$copy   = $path . '.webp';
		if ( ! is_array( $copies ) || ! is_file( $copy ) ) {
			return;
		}
		clearstatcache( true, $path );
		if ( (int) filesize( $copy ) < (int) filesize( $path ) ) {
			return;
		}
		wp_delete_file( $copy );
		$copies = array_filter( $copies, static fn ( $name ): bool => basename( $copy ) !== (string) $name );
		array() === $copies ? delete_post_meta( $id, WebpLibrary::META ) : update_post_meta( $id, WebpLibrary::META, $copies );
	}

	// ----- Helpers -----------------------------------------------------------------------------

	/**
	 * Whether every PageSpeed fix is switched off in wp-config.php.
	 */
	private static function killed(): bool {
		return defined( Fixes::KILL_SWITCH ) && constant( Fixes::KILL_SWITCH );
	}

	/**
	 * Change some of the saved state (read again first, so a batch and a click don't undo each other).
	 *
	 * @param array<string, mixed> $changes Keys to change.
	 */
	private static function update( array $changes ): void {
		$saved = get_option( self::OPTION, array() );
		update_option( self::OPTION, array_merge( is_array( $saved ) ? $saved : array(), $changes ), false );
	}

	/**
	 * The next batch, in a few seconds.
	 *
	 * @param int $seconds Delay.
	 */
	private static function schedule( int $seconds ): void {
		wp_clear_scheduled_hook( self::HOOK );
		wp_schedule_single_event( time() + $seconds, self::HOOK );
	}
}
