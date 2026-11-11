<?php
/**
 * A single, polite request for a WordPress.org review.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Admin;

use ArvaSEO\Connector\Install\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Shown only on this plugin's own screens (never across the dashboard), only to administrators,
 * and only after something worked: the first security scan that finished, or a speed fix that has
 * been on for seven days. "Maybe later" hides it for 30 days (it can come back once; after a
 * second "Maybe later" it stays away), "No thanks / I already did" hides it for good. It keeps
 * its answer in one option on this site and makes no request to anyone: the review button is an
 * ordinary link that opens WordPress.org when it is clicked.
 */
final class ReviewPrompt {

	public const OPTION = 'arvaseo_connector_review_prompt';

	/** Where reviews are written. */
	public const REVIEW_URL = 'https://wordpress.org/support/plugin/arvaseo-connector/reviews/#new-post';

	/** How long "Maybe later" hides the request. */
	public const SNOOZE_DAYS = 30;

	/** How many times "Maybe later" works before the request stops for good. */
	public const MAX_SNOOZES = 2;

	/** A speed fix counts after being on this long. */
	public const FIX_DAYS = 7;

	/**
	 * Hooks (admin only).
	 */
	public static function init(): void {
		add_action( 'admin_notices', array( self::class, 'notice' ) );
		add_action( 'admin_post_arvaseo_connector_review', array( self::class, 'answer' ) );
	}

	/**
	 * What has been answered: status (open, done), snoozes, until (Unix time).
	 *
	 * @return array{status: string, snoozes: int, until: int}
	 */
	public static function state(): array {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		return array(
			'status'  => 'done' === ( $saved['status'] ?? '' ) ? 'done' : 'open',
			'snoozes' => max( 0, (int) ( $saved['snoozes'] ?? 0 ) ),
			'until'   => max( 0, (int) ( $saved['until'] ?? 0 ) ),
		);
	}

	/**
	 * Whether something has worked well enough to ask: a security scan finished, or a speed fix
	 * has been on for a week.
	 *
	 * @param int|null $now Unix time (tests).
	 */
	public static function earned( ?int $now = null ): bool {
		$now  = $now ?? time();
		$scan = \ArvaSEO\Connector\Security\Scanner::progress();
		if ( 'done' === ( $scan['status'] ?? '' ) && (int) ( $scan['finished_at'] ?? 0 ) > 0 ) {
			return true;
		}
		foreach ( \ArvaSEO\Connector\Speed\Fixes::stored()['fixes'] as $fix ) {
			$since = is_string( $fix['since'] ) ? strtotime( $fix['since'] ) : false;
			if ( 'on' === $fix['state'] && false !== $since && $since <= $now - self::FIX_DAYS * DAY_IN_SECONDS ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the request should show right now.
	 *
	 * @param string   $page The Arva SEO screen being shown ('' for any other screen).
	 * @param int|null $now  Unix time (tests).
	 */
	public static function due( string $page, ?int $now = null ): bool {
		$now = $now ?? time();
		if ( '' === $page || ! current_user_can( Capabilities::MANAGE ) || ! (bool) apply_filters( 'arvaseo_connector_show_review_prompt', true ) ) {
			return false;
		}
		$state = self::state();
		return 'done' !== $state['status'] && $state['until'] <= $now && self::earned( $now );
	}

	/**
	 * The notice.
	 */
	public static function notice(): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- page routing only.
		if ( 'arvaseo-connector' !== $page || ! self::due( $page ) ) { // Only on Home: one place, not on every screen.
			return;
		}
		$link = static function ( string $choice ): string {
			$url = add_query_arg(
				array(
					'action' => 'arvaseo_connector_review',
					'choice' => $choice,
				),
				admin_url( 'admin-post.php' )
			);
			return wp_nonce_url( $url, 'arvaseo_connector_review' );
		};
		printf(
			'<div class="notice notice-info arvaseo-own arvaseo-review"><p><strong>%1$s</strong> %2$s</p><p><a class="button button-primary" href="%3$s" rel="noopener noreferrer">%4$s<span class="screen-reader-text"> %5$s</span></a> <a class="button" href="%6$s">%7$s</a> <a class="button-link" href="%8$s">%9$s</a></p></div>',
			esc_html__( 'Is Arva SEO helping?', 'arvaseo-connector' ),
			esc_html__( 'A short, honest review on WordPress.org helps other site owners find it. It takes about a minute, and nothing is sent from this site.', 'arvaseo-connector' ),
			esc_url( $link( 'review' ) ),
			esc_html__( 'Write a review', 'arvaseo-connector' ),
			esc_html__( '(goes to WordPress.org)', 'arvaseo-connector' ),
			esc_url( $link( 'later' ) ),
			esc_html__( 'Maybe later', 'arvaseo-connector' ),
			esc_url( $link( 'never' ) ),
			esc_html__( 'No thanks / I already did', 'arvaseo-connector' )
		);
	}

	/**
	 * Save the answer, then go on: to the review page, or back to where the person was.
	 */
	public static function answer(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You don’t have permission to do that.', 'arvaseo-connector' ), 403 );
		}
		check_admin_referer( 'arvaseo_connector_review' );
		$choice = isset( $_GET['choice'] ) ? sanitize_key( wp_unslash( $_GET['choice'] ) ) : '';
		self::record( $choice );
		if ( 'review' === $choice ) {
			wp_redirect( self::REVIEW_URL, 302, 'Arva SEO' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- a fixed WordPress.org address, opened by the person's click.
			exit;
		}
		wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=arvaseo-connector' ) );
		exit;
	}

	/**
	 * Remember an answer: review (done), never (done), later (hidden for 30 days, at most twice).
	 *
	 * @param string $choice review, never or later.
	 * @param int    $now    Unix time (tests).
	 */
	public static function record( string $choice, ?int $now = null ): void {
		$now   = $now ?? time();
		$state = self::state();
		if ( 'later' === $choice && $state['snoozes'] + 1 < self::MAX_SNOOZES ) {
			$state['snoozes'] += 1;
			$state['until']    = $now + self::SNOOZE_DAYS * DAY_IN_SECONDS;
		} elseif ( in_array( $choice, array( 'review', 'never', 'later' ), true ) ) {
			// A second "Maybe later" is the same as "No thanks": it doesn't ask a third time.
			$state['status'] = 'done';
		} else {
			return;
		}
		update_option( self::OPTION, $state, false );
	}
}
