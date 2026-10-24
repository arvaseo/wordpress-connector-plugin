<?php
/**
 * Compress selected existing images from WordPress's native Media Library list.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Speed;

use ArvaSEO\Connector\Install\Capabilities;

defined( 'ABSPATH' ) || exit;

/** Native bulk actions keep WordPress's bulk-media nonce and attachment selection handling. */
final class MediaLibraryCompression {

	public const ACTION = 'arvaseo_compress_images';

	public const COLUMN = 'arvaseo_compression';

	private const MIMES = array( 'image/jpeg', 'image/png', 'image/webp' );

	/** Register the list action, feedback and per-image status. */
	public static function init(): void {
		add_filter( 'bulk_actions-upload', array( self::class, 'actions' ) );
		add_filter( 'handle_bulk_actions-upload', array( self::class, 'handle' ), 10, 3 );
		add_filter( 'manage_media_columns', array( self::class, 'columns' ) );
		add_action( 'manage_media_custom_column', array( self::class, 'column' ), 10, 2 );
		add_action( 'admin_notices', array( self::class, 'notice' ) );
	}

	/**
	 * Add the action even when compression is off, so its feedback can explain how to enable it.
	 *
	 * @param array<string, string> $actions Native bulk actions.
	 * @return array<string, string>
	 */
	public static function actions( array $actions ): array {
		if ( current_user_can( Capabilities::MANAGE ) ) {
			$actions[ self::ACTION ] = __( 'Compress with Arva SEO', 'arvaseo-connector' );
		}
		return $actions;
	}

	/**
	 * Queue selected local images. WordPress checks bulk-media before invoking this filter.
	 * Compression uses the existing cron engine, originals choice, consent and plan allowance.
	 *
	 * @param string $redirect Native redirect.
	 * @param string $action   Native selected action.
	 * @param int[]  $ids      Selected attachment IDs.
	 */
	public static function handle( string $redirect, string $action, array $ids ): string {
		if ( self::ACTION !== $action || ! current_user_can( Capabilities::MANAGE ) ) {
			return $redirect;
		}
		$ids     = array_values( array_unique( array_map( 'absint', $ids ) ) );
		$reason  = self::paused();
		$queued  = 0;
		$skipped = 0;
		foreach ( $ids as $id ) {
			if ( '' !== $reason || ! current_user_can( 'edit_post', $id ) || ! self::eligible( $id ) || in_array( $id, ImageCompression::queue(), true ) ) {
				++$skipped;
				continue;
			}
			$status = ImageCompression::attachment_status( $id );
			if ( $status['files'] > 0 && $status['done'] === $status['files'] ) {
				++$skipped;
				continue;
			}
			ImageCompression::enqueue( $id );
			if ( in_array( $id, ImageCompression::queue(), true ) ) {
				++$queued;
			} else {
				++$skipped; // The bounded queue is full; do not report work that was not saved.
			}
		}
		return add_query_arg(
			array(
				'arvaseo_compress_notice'  => '' !== $reason ? $reason : 'queued',
				'arvaseo_compress_queued'  => $queued,
				'arvaseo_compress_skipped' => $skipped,
			),
			$redirect
		);
	}

	/**
	 * A local file in this site's uploads directory, with supported attachment metadata.
	 * Offloaded, missing, outside-upload and unsupported files are left alone.
	 *
	 * @param int $id Attachment ID.
	 */
	private static function eligible( int $id ): bool {
		if ( 'attachment' !== get_post_type( $id ) || ! in_array( get_post_mime_type( $id ), self::MIMES, true ) || ! is_array( wp_get_attachment_metadata( $id ) ) ) {
			return false;
		}
		$file    = get_attached_file( $id, true );
		$uploads = wp_upload_dir( null, false );
		$base    = realpath( (string) $uploads['basedir'] );
		$path    = is_string( $file ) ? realpath( $file ) : false;
		return false !== $base && false !== $path && is_file( $path ) && is_readable( $path )
			&& str_starts_with( wp_normalize_path( $path ), trailingslashit( wp_normalize_path( $base ) ) );
	}

	/** Why new work cannot be queued, without changing any setting. */
	private static function paused(): string {
		if ( defined( Fixes::KILL_SWITCH ) && constant( Fixes::KILL_SWITCH ) ) {
			return 'disabled';
		}
		if ( ImageCompression::state()['restoring'] ) {
			return 'restoring';
		}
		return 'on' === Fixes::state( 'compress_images' ) ? '' : 'off';
	}

	/**
	 * Status column for managers in the native list view.
	 *
	 * @param array<string, string> $columns Native columns.
	 * @return array<string, string>
	 */
	public static function columns( array $columns ): array {
		if ( current_user_can( Capabilities::MANAGE ) ) {
			$columns[ self::COLUMN ] = __( 'Arva SEO compression', 'arvaseo-connector' );
		}
		return $columns;
	}

	/**
	 * Queued status and measured savings for the image's current files.
	 *
	 * @param string $column Column name.
	 * @param int    $id     Attachment ID.
	 */
	public static function column( string $column, int $id ): void {
		if ( self::COLUMN !== $column || ! current_user_can( Capabilities::MANAGE ) || ! current_user_can( 'edit_post', $id ) ) {
			return;
		}
		if ( ! in_array( get_post_mime_type( $id ), self::MIMES, true ) ) {
			echo '&mdash;';
			return;
		}
		if ( in_array( $id, ImageCompression::queue(), true ) ) {
			echo esc_html( '' === self::paused() ? __( 'Queued — runs in the background', 'arvaseo-connector' ) : __( 'Queued — paused', 'arvaseo-connector' ) );
			return;
		}
		$status = ImageCompression::attachment_status( $id );
		if ( $status['done'] > 0 && ImageCompression::state()['restoring'] ) {
			esc_html_e( 'Restoring originals', 'arvaseo-connector' );
		} elseif ( $status['files'] > 0 && $status['done'] === $status['files'] ) {
			if ( $status['saved_bytes'] > 0 ) {
				/* translators: %s: measured space saved, such as 12 KB. */
				echo esc_html( sprintf( __( 'Compressed — %s saved', 'arvaseo-connector' ), (string) size_format( $status['saved_bytes'] ) ) );
			} else {
				esc_html_e( 'Checked — no smaller copy', 'arvaseo-connector' );
			}
		} else {
			esc_html_e( 'Not compressed', 'arvaseo-connector' );
		}
	}

	/** Media Library guidance, queue feedback and the whole-library control. */
	public static function notice(): void {
		$screen = get_current_screen();
		if ( null === $screen || 'upload' !== $screen->id || ! current_user_can( Capabilities::MANAGE ) ) {
			return;
		}
		// Read-only feedback from the native bulk-action redirect; no action is performed here.
		$notice  = isset( $_GET['arvaseo_compress_notice'] ) && is_string( $_GET['arvaseo_compress_notice'] ) ? sanitize_key( wp_unslash( $_GET['arvaseo_compress_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$queued  = isset( $_GET['arvaseo_compress_queued'] ) && is_scalar( $_GET['arvaseo_compress_queued'] ) ? absint( $_GET['arvaseo_compress_queued'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$skipped = isset( $_GET['arvaseo_compress_skipped'] ) && is_scalar( $_GET['arvaseo_compress_skipped'] ) ? absint( $_GET['arvaseo_compress_skipped'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="notice notice-info"><p>';
		if ( in_array( $notice, array( 'queued', 'off', 'restoring', 'disabled' ), true ) ) {
			/* translators: 1: selected images queued, 2: selected images skipped. */
			echo esc_html( sprintf( _n( '%1$s image queued; %2$s skipped.', '%1$s images queued; %2$s skipped.', $queued, 'arvaseo-connector' ), number_format_i18n( $queued ), number_format_i18n( $skipped ) ) ) . ' ';
		}
		if ( 'queued' === $notice ) {
			esc_html_e( 'Compression runs in the background, even after you leave this page. Refresh the list to see each image’s status. Skipped items may already be queued or checked, unsupported, unavailable, or beyond the queue limit.', 'arvaseo-connector' );
		} elseif ( 'off' === $notice || ( '' === $notice && 'off' === self::paused() ) ) {
			esc_html_e( 'Turn on Compress images in Arva SEO → Speed, then select your images and choose Compress with Arva SEO in the Media Library’s List view.', 'arvaseo-connector' );
		} elseif ( 'restoring' === $notice || ( '' === $notice && 'restoring' === self::paused() ) ) {
			esc_html_e( 'Originals are being restored. Wait for restoration to finish before compressing selected images.', 'arvaseo-connector' );
		} elseif ( 'disabled' === $notice || ( '' === $notice && 'disabled' === self::paused() ) ) {
			esc_html_e( 'PageSpeed fixes are disabled in wp-config.php. No images were queued.', 'arvaseo-connector' );
		} else {
			esc_html_e( 'In List view, select existing JPEG, PNG or WebP images and choose Compress with Arva SEO. WebP compression runs on this server.', 'arvaseo-connector' );
		}
		$waiting = count( ImageCompression::queue() );
		if ( $waiting > 0 ) {
			/* translators: %s: images waiting in the background queue. */
			echo ' ' . esc_html( sprintf( _n( '%s image waiting.', '%s images waiting.', $waiting, 'arvaseo-connector' ), number_format_i18n( $waiting ) ) );
		}
		echo ' <a href="' . esc_url( admin_url( 'admin.php?page=arvaseo-speed' ) . '#' . Fixes::anchor( 'compress_images' ) ) . '">' . esc_html__( 'Compress the whole library, view progress or restore originals', 'arvaseo-connector' ) . '</a>';
		echo '</p></div>';
	}
}
