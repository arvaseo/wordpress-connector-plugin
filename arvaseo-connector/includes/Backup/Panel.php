<?php
/**
 * The Backups section of Settings: switch, status, "Back up now" and "Restore".
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Backup;

use ArvaSEO\Connector\Api\ApiClient;
use ArvaSEO\Connector\Auth\TokenStore;
use ArvaSEO\Connector\Install\Capabilities;
use ArvaSEO\Connector\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Backups are kept on Arva SEO's servers: one weekly copy that the next one replaces, and daily copies on plans that
 * include them. Restoring replaces this site's database and wp-content files with a stored copy, so it asks the
 * administrator to confirm after reading what happens, and saves a copy of the current database first.
 */
final class Panel {

	public const SUMMARY = 'arvaseo_connector_backups_summary';

	/**
	 * Hook the two buttons.
	 */
	public static function init(): void {
		add_action( 'admin_post_arvaseo_connector_backup_now', array( self::class, 'handle_backup_now' ) );
		add_action( 'admin_post_arvaseo_connector_restore', array( self::class, 'handle_restore' ) );
		add_action( 'admin_post_arvaseo_connector_backups_refresh', array( self::class, 'handle_refresh' ) );
	}

	/**
	 * What Arva SEO holds for this site (kept 10 minutes), or null.
	 *
	 * @param bool $fresh Fetch again.
	 * @return array<string, mixed>|null
	 */
	public static function summary( bool $fresh = false ): ?array {
		if ( ! TokenStore::is_connected() ) {
			return null;
		}
		$cached = get_transient( self::SUMMARY );
		if ( ! $fresh && is_array( $cached ) ) {
			return $cached;
		}
		$response = ApiClient::instance()->site( 'GET', '/backups', null, 8 );
		if ( ! $response->ok() ) {
			return is_array( $cached ) ? $cached : null;
		}
		$data = $response->data();
		set_transient( self::SUMMARY, $data, 10 * MINUTE_IN_SECONDS );
		return $data;
	}

	/**
	 * A one-time message for the person who pressed a button.
	 *
	 * @param string $text Message.
	 */
	private static function notice( string $text ): void {
		set_transient( 'arvaseo_connector_backup_notice_' . get_current_user_id(), $text, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Print the section.
	 */
	public static function render(): void {
		$on        = (bool) Settings::get( 'backups' );
		$connected = TokenStore::is_connected();
		$notice    = get_transient( 'arvaseo_connector_backup_notice_' . get_current_user_id() );
		if ( is_string( $notice ) ) {
			delete_transient( 'arvaseo_connector_backup_notice_' . get_current_user_id() );
		}
		echo '<section class="arvaseo-card" id="arvaseo-settings-backups" aria-labelledby="arvaseo-h-backups">';
		echo '<h2 class="arvaseo-card-title" id="arvaseo-h-backups">' . esc_html__( 'Your backups', 'arvaseo-connector' ) . '</h2>';
		if ( is_string( $notice ) ) {
			echo '<p role="status"><strong>' . esc_html( $notice ) . '</strong></p>';
		}
		if ( ! $on || ! $connected ) {
			echo '<p class="arvaseo-hint">' . esc_html( $connected ? __( 'Backups are off. Turn on “Back up this site to Arva SEO” above and save.', 'arvaseo-connector' ) : __( 'Connect this site to Arva SEO to keep backups on its servers.', 'arvaseo-connector' ) ) . '</p></section>';
			return;
		}

		$job     = Job::current();
		$restore = Restorer::current();
		$last    = Job::last();
		if ( null !== $restore ) {
			echo '<p role="status">' . esc_html__( 'A restore is running. This can take a few minutes; the site may look unchanged until the database step is done. Do not close this page’s site or change content meanwhile.', 'arvaseo-connector' ) . '</p></section>';
			return;
		}
		if ( null !== $job ) {
			echo '<p role="status">' . esc_html__( 'A backup is running in the background. It carries on by itself.', 'arvaseo-connector' ) . '</p>';
		} elseif ( null !== $last ) {
			$when = human_time_diff( (int) $last['at'] );
			if ( 'complete' === $last['status'] ) {
				/* translators: 1: how long ago, 2: size, for example "3 days", "48 MB". */
				echo '<p>' . esc_html( sprintf( __( 'The last backup finished %1$s ago (%2$s).', 'arvaseo-connector' ), $when, size_format( (int) $last['bytes'] ) ) ) . ( 'database' === ( $last['contents'] ?? '' ) ? ' ' . esc_html__( 'It holds the database only, because the site’s files are over the 2 GB limit.', 'arvaseo-connector' ) : '' ) . '</p>';
			} else {
				/* translators: 1: how long ago, 2: why. */
				echo '<p>' . esc_html( sprintf( __( 'The last backup, %1$s ago, was not made. %2$s', 'arvaseo-connector' ), $when, (string) $last['message'] ) ) . '</p>';
			}
		}
		$last_restore = Restorer::last();
		if ( null !== $last_restore ) {
			/* translators: 1: how long ago, 2: result sentence. */
			echo '<p>' . esc_html( sprintf( __( 'The last restore, %1$s ago: %2$s', 'arvaseo-connector' ), human_time_diff( (int) $last_restore['at'] ), 'complete' === $last_restore['status'] ? __( 'finished.', 'arvaseo-connector' ) : (string) $last_restore['message'] ) ) . '</p>';
		}

		$summary = self::summary();
		$daily   = (int) ( $summary['daily']['remaining'] ?? 0 );
		printf(
			'<p class="arvaseo-hint">%s</p>',
			esc_html__( 'A backup of the database and of wp-content is made every week and kept on Arva SEO’s servers. Each new one replaces the one before, but only after it has arrived complete.', 'arvaseo-connector' )
		);
		if ( ! empty( $summary['daily']['buy_url'] ) && 0 === (int) ( $summary['daily']['included'] ?? 0 ) && 0 === (int) ( $summary['daily']['packs'] ?? 0 ) ) {
			printf( '<p class="arvaseo-hint">%1$s <a href="%2$s" target="_blank" rel="noopener">%3$s</a></p>', esc_html__( 'Daily backups come with Professional and Business, or from a top-up.', 'arvaseo-connector' ), esc_url( (string) $summary['daily']['buy_url'] ), esc_html__( 'Add daily backups', 'arvaseo-connector' ) );
		} elseif ( null !== $summary ) {
			/* translators: %d: backups left. */
			printf( '<p class="arvaseo-hint">%s</p>', esc_html( sprintf( _n( '%d daily backup left this month.', '%d daily backups left this month.', $daily, 'arvaseo-connector' ), $daily ) ) );
		}

		echo '<div style="display:flex;gap:8px;flex-wrap:wrap;margin:12px 0">';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'arvaseo_connector_backup_now' );
		echo '<input type="hidden" name="action" value="arvaseo_connector_backup_now" />';
		printf( '<button type="submit" class="arvaseo-btn arvaseo-btn--dark"%1$s>%2$s</button>', null !== $job ? ' disabled' : '', esc_html__( 'Back up now', 'arvaseo-connector' ) );
		echo '</form>';
		printf( '<a class="arvaseo-btn" href="%1$s">%2$s</a>', esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=arvaseo_connector_backups_refresh' ), 'arvaseo_connector_backups_refresh' ) ), esc_html__( 'Refresh the list', 'arvaseo-connector' ) );
		echo '</div>';

		$backups = (array) ( $summary['backups'] ?? array() );
		if ( array() === $backups ) {
			echo '<p>' . esc_html__( 'No backup is stored yet.', 'arvaseo-connector' ) . '</p></section>';
			return;
		}
		echo '<table class="widefat striped" style="margin-top:8px"><thead><tr><th>' . esc_html__( 'Backup', 'arvaseo-connector' ) . '</th><th>' . esc_html__( 'Size', 'arvaseo-connector' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $backups as $backup ) {
			$at   = isset( $backup['completed_at'] ) ? strtotime( (string) $backup['completed_at'] ) : false;
			$kind = 'daily' === ( $backup['kind'] ?? '' ) ? __( 'Daily', 'arvaseo-connector' ) : __( 'Weekly', 'arvaseo-connector' );
			echo '<tr><td>' . esc_html( $kind . ( false !== $at ? ' · ' . wp_date( 'j M Y, H:i', $at ) : '' ) ) . ( 'database' === ( $backup['contents'] ?? '' ) ? ' · ' . esc_html__( 'database only', 'arvaseo-connector' ) : '' ) . '</td><td>' . esc_html( size_format( (int) ( $backup['bytes'] ?? 0 ) ) ) . '</td><td>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">';
			wp_nonce_field( 'arvaseo_connector_restore' );
			echo '<input type="hidden" name="action" value="arvaseo_connector_restore" /><input type="hidden" name="backup" value="' . esc_attr( (string) ( $backup['id'] ?? '' ) ) . '" />';
			echo '<label><input type="checkbox" name="confirm" value="1" required /> ' . esc_html__( 'I understand: this replaces the site’s database and files with this backup, and anything changed since is lost. A copy of the current database is saved first.', 'arvaseo-connector' ) . '</label>';
			echo '<button type="submit" class="arvaseo-btn">' . esc_html__( 'Restore', 'arvaseo-connector' ) . '</button></form></td></tr>';
		}
		echo '</tbody></table>';
		/* translators: %d: restores left this month. */
		printf( '<p class="arvaseo-hint">%s</p>', esc_html( sprintf( _n( 'Restoring is free. %d restore left this month.', 'Restoring is free. %d restores left this month.', (int) ( $summary['restores_left'] ?? 0 ), 'arvaseo-connector' ), (int) ( $summary['restores_left'] ?? 0 ) ) ) );
		echo '</section>';
	}

	/**
	 * Back up now.
	 */
	public static function handle_backup_now(): void {
		check_admin_referer( 'arvaseo_connector_backup_now' );
		self::allow();
		$why = Job::start( 'weekly' );
		self::notice( null === $why ? __( 'The backup started. It runs in the background and can take a few minutes.', 'arvaseo-connector' ) : $why );
		self::back();
	}

	/**
	 * Restore a backup.
	 */
	public static function handle_restore(): void {
		check_admin_referer( 'arvaseo_connector_restore' );
		self::allow();
		$id = isset( $_POST['backup'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['backup'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		if ( empty( $_POST['confirm'] ) || ! preg_match( '/^[0-9a-z]{26}$/i', $id ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
			self::notice( __( 'Tick the box to confirm before restoring.', 'arvaseo-connector' ) );
			self::back();
		}
		$why = Restorer::begin( $id );
		self::notice( null === $why ? __( 'The restore started. It runs in the background.', 'arvaseo-connector' ) : $why );
		self::back();
	}

	/**
	 * Fetch the list again.
	 */
	public static function handle_refresh(): void {
		check_admin_referer( 'arvaseo_connector_backups_refresh' );
		self::allow();
		self::summary( true );
		self::back();
	}

	/**
	 * Only people who manage the plugin.
	 */
	private static function allow(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'arvaseo-connector' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Back to Settings.
	 */
	private static function back(): void {
		wp_safe_redirect( admin_url( 'admin.php?page=arvaseo-settings#arvaseo-settings-backups' ) );
		exit;
	}
}
