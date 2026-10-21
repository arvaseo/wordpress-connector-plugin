<?php
/**
 * New uploads as WebP, "Convert your existing images too": WebP copies of the media library's
 * JPEG and PNG images, made in the background and shown instead of the originals.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Speed;

use ArvaSEO\Connector\Logging\AuditLogger;

defined( 'ABSPATH' ) || exit;

/**
 * Each image and each of its sizes gets a copy next to it (photo-300x200.jpg.webp), kept only when
 * it's smaller than the original. Originals are never changed or deleted, so old links keep
 * working, and "Undo" removes every copy. Pages then show the copies in place of the originals
 * wherever WordPress outputs a media-library image, for every visitor (browsers have shown WebP
 * since 2020). Work runs in batches on WP-Cron, a few images at a time, until done or stopped.
 */
final class WebpLibrary {

	public const HOOK = 'arvaseo_connector_webp_batch';

	/** Post meta: the copies, by size ("full" for the uploaded image). */
	public const META = '_arvaseo_webp';

	/** Progress (an option, not autoloaded). */
	public const OPTION = 'arvaseo_connector_webp_progress';

	/** Images per batch, and the most time one batch takes. */
	private const BATCH = 5;

	private const SECONDS = 20;

	private const QUALITY = 82;

	/**
	 * Hooks: the batches, and the copies shown on pages.
	 */
	public static function init(): void {
		add_action( self::HOOK, array( self::class, 'batch' ) );
		add_action( 'delete_attachment', array( self::class, 'attachment_deleted' ) );
	}

	/**
	 * Progress: whether it runs, how many of how many are done, and when it finished.
	 *
	 * @return array{running: bool, done: int, total: int, saved_bytes: int, last_id: int, finished_at: string|null}
	 */
	public static function progress(): array {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		return array(
			'running'     => (bool) ( $saved['running'] ?? false ),
			'done'        => (int) ( $saved['done'] ?? 0 ),
			'total'       => (int) ( $saved['total'] ?? 0 ),
			'saved_bytes' => (int) ( $saved['saved_bytes'] ?? 0 ),
			'last_id'     => (int) ( $saved['last_id'] ?? 0 ),
			'finished_at' => is_string( $saved['finished_at'] ?? null ) ? $saved['finished_at'] : null,
		);
	}

	/**
	 * Start (or carry on from where it stopped).
	 */
	public static function start(): bool {
		if ( 'on' !== Fixes::state( 'webp_uploads' ) ) {
			return false;
		}
		$progress            = self::progress();
		$progress['running'] = true;
		$progress['total']   = self::count_images();
		if ( null !== $progress['finished_at'] ) {
			$progress = array_merge( $progress, array( 'done' => 0, 'last_id' => 0, 'saved_bytes' => 0, 'finished_at' => null ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- a short reset.
		}
		update_option( self::OPTION, $progress, false );
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time(), self::HOOK );
		}
		AuditLogger::info( 'speed.webp_started', __( 'PageSpeed fixes: converting existing images to WebP started.', 'arvaseo-connector' ) );
		return true;
	}

	/**
	 * Stop after the batch that's running.
	 */
	public static function stop(): void {
		$progress            = self::progress();
		$progress['running'] = false;
		update_option( self::OPTION, $progress, false );
		wp_clear_scheduled_hook( self::HOOK );
		AuditLogger::info( 'speed.webp_stopped', __( 'PageSpeed fixes: converting existing images to WebP stopped.', 'arvaseo-connector' ) );
	}

	/**
	 * One batch (WP-Cron): the next images by ID, then the next batch is scheduled.
	 */
	public static function batch(): void {
		$progress = self::progress();
		if ( ! $progress['running'] || 'on' !== Fixes::state( 'webp_uploads' ) ) {
			return;
		}
		$until = time() + self::SECONDS;
		$ids   = self::next_ids( $progress['last_id'], self::BATCH );
		foreach ( $ids as $id ) {
			$progress['saved_bytes'] += self::convert( $id );
			$progress['last_id']      = $id;
			++$progress['done'];
			if ( time() >= $until ) {
				break;
			}
		}
		if ( count( $ids ) < self::BATCH || array() === self::next_ids( $progress['last_id'], 1 ) ) {
			$progress['running']     = false;
			$progress['finished_at'] = gmdate( 'c' );
			Fixes::pages_changed( 'webp' );
			AuditLogger::info(
				'speed.webp_finished',
				/* translators: %d: number of images. */
				sprintf( _n( 'PageSpeed fixes: %d existing image was converted to WebP.', 'PageSpeed fixes: %d existing images were converted to WebP.', $progress['done'], 'arvaseo-connector' ), $progress['done'] ),
				array( 'saved_bytes' => $progress['saved_bytes'] )
			);
		} else {
			wp_schedule_single_event( time() + 5, self::HOOK );
		}
		update_option( self::OPTION, $progress, false );
	}

	/**
	 * Make the copies for one image. Returns the bytes saved.
	 *
	 * @param int $id Attachment ID.
	 */
	public static function convert( int $id ): int {
		$file = get_attached_file( $id );
		$meta = wp_get_attachment_metadata( $id );
		if ( ! is_string( $file ) || ! is_file( $file ) || ! is_array( $meta ) ) {
			return 0;
		}
		$dir    = dirname( $file );
		$copies = array();
		$saved  = 0;
		$files  = array( 'full' => basename( $file ) );
		foreach ( (array) ( $meta['sizes'] ?? array() ) as $size => $data ) {
			if ( is_array( $data ) && is_string( $data['file'] ?? null ) && in_array( $data['mime-type'] ?? 'image/jpeg', array( 'image/jpeg', 'image/png' ), true ) ) {
				$files[ (string) $size ] = $data['file'];
			}
		}
		foreach ( $files as $size => $name ) {
			$source = $dir . '/' . $name;
			$target = $source . '.webp';
			if ( ! is_file( $source ) ) {
				continue;
			}
			if ( ! is_file( $target ) ) {
				$editor = wp_get_image_editor( $source );
				if ( is_wp_error( $editor ) ) {
					continue;
				}
				$editor->set_quality( self::QUALITY );
				$result = $editor->save( $target, 'image/webp' );
				if ( is_wp_error( $result ) || ! is_file( $target ) ) {
					continue;
				}
			}
			$before = (int) filesize( $source );
			$after  = (int) filesize( $target );
			if ( $after <= 0 || $after >= $before ) {
				wp_delete_file( $target ); // A copy that isn't smaller isn't worth showing.
				continue;
			}
			$copies[ $size ] = basename( $target );
			$saved          += $before - $after;
		}
		if ( array() === $copies ) {
			delete_post_meta( $id, self::META );
			return 0;
		}
		update_post_meta( $id, self::META, $copies );
		return $saved;
	}

	/**
	 * Undo: remove every copy, so pages show the originals again.
	 */
	public static function undo(): int {
		self::stop();
		$removed = 0;
		foreach ( self::converted_ids() as $id ) {
			$removed += self::delete_copies( $id );
		}
		delete_option( self::OPTION );
		Fixes::pages_changed( 'webp' );
		AuditLogger::info( 'speed.webp_undone', __( 'PageSpeed fixes: the WebP copies of existing images were removed.', 'arvaseo-connector' ), array( 'files' => $removed ) );
		return $removed;
	}

	/**
	 * An image deleted from the media library takes its copies with it.
	 *
	 * @param int $id Attachment ID.
	 */
	public static function attachment_deleted( $id ): void {
		self::delete_copies( (int) $id );
	}

	/**
	 * Remove one image's copies. Returns how many files.
	 *
	 * @param int $id Attachment ID.
	 */
	public static function delete_copies( $id ): int {
		$copies = get_post_meta( (int) $id, self::META, true );
		$file   = get_attached_file( (int) $id );
		$count  = 0;
		if ( is_array( $copies ) && is_string( $file ) ) {
			foreach ( $copies as $name ) {
				$path = dirname( $file ) . '/' . basename( (string) $name );
				if ( str_ends_with( $path, '.webp' ) && is_file( $path ) ) {
					wp_delete_file( $path );
					++$count;
				}
			}
		}
		delete_post_meta( (int) $id, self::META );
		return $count;
	}

	// ----- Showing the copies ------------------------------------------------------------------

	/**
	 * An image address with a smaller WebP copy, as the copy's address; anything else unchanged.
	 *
	 * @param string $url Image address.
	 * @param int    $id  Attachment ID, when known.
	 */
	public static function swap_url( string $url, int $id ): string {
		if ( $id <= 0 || ! str_contains( $url, '/' ) ) {
			return $url;
		}
		$copies = get_post_meta( $id, self::META, true );
		if ( ! is_array( $copies ) ) {
			return $url;
		}
		$name = basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		return in_array( $name . '.webp', $copies, true ) ? (string) preg_replace( '~' . preg_quote( $name, '~' ) . '(?=$|\?)~', $name . '.webp', $url, 1 ) : $url;
	}

	/**
	 * A srcset with each candidate swapped.
	 *
	 * @param string $srcset Candidates.
	 * @param int    $id     Attachment ID.
	 */
	public static function swap_srcset( string $srcset, int $id ): string {
		$out = array();
		foreach ( preg_split( '/\s*,\s*/', trim( $srcset ) ) ?: array() as $candidate ) {
			$parts    = preg_split( '/\s+/', $candidate, 2 ) ?: array( $candidate );
			$parts[0] = self::swap_url( $parts[0], $id );
			$out[]    = implode( ' ', $parts );
		}
		return implode( ', ', $out );
	}

	/**
	 * Hooks for a page where the copies are shown.
	 */
	public static function on_page(): void {
		add_filter( 'wp_get_attachment_image_attributes', array( self::class, 'attachment_attributes' ), 30, 2 );
		add_filter( 'wp_calculate_image_srcset', array( self::class, 'srcset_sources' ), 30, 5 );
		add_filter( 'wp_content_img_tag', array( self::class, 'content_image' ), 30, 3 );
	}

	/**
	 * Images WordPress outputs for themes and blocks (wp_get_attachment_image()).
	 *
	 * @param array<string, string> $attr       Attributes.
	 * @param \WP_Post              $attachment The image.
	 * @return array<string, string>
	 */
	public static function attachment_attributes( $attr, $attachment ) {
		if ( ! is_array( $attr ) || ! $attachment instanceof \WP_Post || Exclusions::leaves_element( 'webp_uploads', 'IMG', array_intersect_key( $attr, array_flip( array( 'class', 'id', 'src' ) ) ) ) ) {
			return $attr;
		}
		if ( isset( $attr['src'] ) ) {
			$attr['src'] = self::swap_url( (string) $attr['src'], $attachment->ID );
		}
		if ( isset( $attr['srcset'] ) ) {
			$attr['srcset'] = self::swap_srcset( (string) $attr['srcset'], $attachment->ID );
		}
		return $attr;
	}

	/**
	 * The sizes WordPress lists in srcset.
	 *
	 * @param mixed $sources       Sources by width.
	 * @param mixed $size_array    Requested size.
	 * @param mixed $image_src     Image address.
	 * @param mixed $image_meta    Image metadata.
	 * @param int   $attachment_id Image ID.
	 * @return mixed
	 */
	public static function srcset_sources( $sources, $size_array, $image_src, $image_meta, $attachment_id = 0 ) {
		if ( ! is_array( $sources ) ) {
			return $sources;
		}
		foreach ( $sources as $width => $source ) {
			if ( is_array( $source ) && is_string( $source['url'] ?? null ) ) {
				$sources[ $width ]['url'] = self::swap_url( $source['url'], (int) $attachment_id );
			}
		}
		return $sources;
	}

	/**
	 * Images in post content (each one WordPress recognises as a media-library image).
	 *
	 * @param mixed  $html          The image tag.
	 * @param string $context       Where it's shown.
	 * @param int    $attachment_id Image ID.
	 * @return mixed
	 */
	public static function content_image( $html, $context, $attachment_id ) {
		if ( ! is_string( $html ) || (int) $attachment_id <= 0 ) {
			return $html;
		}
		$tags = new \WP_HTML_Tag_Processor( $html );
		if ( ! $tags->next_tag( array( 'tag_name' => 'img' ) ) || Exclusions::leaves_element( 'webp_uploads', 'IMG', Exclusions::attributes( $tags ) ) ) {
			return $html;
		}
		$src = $tags->get_attribute( 'src' );
		if ( is_string( $src ) ) {
			$tags->set_attribute( 'src', self::swap_url( $src, (int) $attachment_id ) );
		}
		$srcset = $tags->get_attribute( 'srcset' );
		if ( is_string( $srcset ) ) {
			$tags->set_attribute( 'srcset', self::swap_srcset( $srcset, (int) $attachment_id ) );
		}
		return $tags->get_updated_html();
	}

	// ----- Helpers -----------------------------------------------------------------------------

	/**
	 * JPEG and PNG images in the media library.
	 */
	private static function count_images(): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ('image/jpeg', 'image/png')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- progress count.
	}

	/**
	 * The next images after an ID.
	 *
	 * @param int $after  Last ID done.
	 * @param int $limit  How many.
	 * @return int[]
	 */
	private static function next_ids( int $after, int $limit ): array {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ('image/jpeg', 'image/png') AND ID > %d ORDER BY ID ASC LIMIT %d", $after, $limit ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- a batch.
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Images that have copies.
	 *
	 * @return int[]
	 */
	private static function converted_ids(): array {
		global $wpdb;
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", self::META ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- undo.
	}
}
