<?php
/**
 * What the SEO and Redirects screens do when a button is pressed. Every action is a POST to
 * admin-post.php with a nonce and an administrator capability check.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Seo;

use ArvaSEO\Connector\Install\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Result notices travel as codes in the address (never data), and are worded in AdminController.
 */
final class Actions {

	/**
	 * Hooks (admin only).
	 */
	public static function init(): void {
		add_action( 'admin_post_arvaseo_connector_seo_check', array( self::class, 'check_again' ) );
		add_action( 'admin_post_arvaseo_connector_seo_essentials', array( self::class, 'save_essentials' ) );
		add_action( 'admin_post_arvaseo_connector_redirect_settings', array( self::class, 'save_redirect_settings' ) );
		add_action( 'admin_post_arvaseo_connector_redirect_add', array( self::class, 'add_redirect' ) );
		add_action( 'admin_post_arvaseo_connector_redirect_delete', array( self::class, 'delete_redirect' ) );
		add_action( 'admin_post_arvaseo_connector_redirect_export', array( self::class, 'export_redirects' ) );
		add_action( 'admin_post_arvaseo_connector_404_remove', array( self::class, 'remove_missing' ) );
		add_action( 'admin_post_arvaseo_connector_404_clear', array( self::class, 'clear_missing' ) );
	}

	/**
	 * Ask the home page again.
	 */
	public static function check_again(): void {
		self::guard( 'arvaseo_connector_seo_check' );
		Checklist::results( true );
		self::back( 'arvaseo-seo', 'seo_checked' );
	}

	/**
	 * Turn SEO titles and descriptions on or off, and save the home page's.
	 */
	public static function save_essentials(): void {
		self::guard( 'arvaseo_connector_seo_essentials' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- guard() checked the nonce.
		$changes = array(
			'home_title'       => isset( $_POST['home_title'] ) ? (string) wp_unslash( $_POST['home_title'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Options::update() sanitizes.
			'home_description' => isset( $_POST['home_description'] ) ? (string) wp_unslash( $_POST['home_description'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Options::update() sanitizes.
		);
		if ( Essentials::available() ) {
			$changes['essentials'] = ! empty( $_POST['essentials'] );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		Options::update( $changes );
		\ArvaSEO\Connector\Speed\Fixes::pages_changed( 'site' );
		self::back( 'arvaseo-seo', 'seo_saved', 'arvaseo-seo-fields' );
	}

	/**
	 * Turn the broken-address log and redirects on or off.
	 */
	public static function save_redirect_settings(): void {
		self::guard( 'arvaseo_connector_redirect_settings' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- guard() checked the nonce.
		Options::update(
			array(
				'log_404'   => ! empty( $_POST['log_404'] ),
				'redirects' => ! empty( $_POST['redirects'] ),
			)
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		\ArvaSEO\Connector\Speed\Fixes::pages_changed( 'site' );
		self::back( 'arvaseo-redirects', 'redirect_settings_saved' );
	}

	/**
	 * Add a redirect.
	 */
	public static function add_redirect(): void {
		self::guard( 'arvaseo_connector_redirect_add' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- guard() checked the nonce; Redirects::add() validates and cleans both addresses.
		$from   = isset( $_POST['from'] ) ? (string) wp_unslash( $_POST['from'] ) : '';
		$target = isset( $_POST['target'] ) ? (string) wp_unslash( $_POST['target'] ) : '';
		$code   = isset( $_POST['code'] ) ? absint( $_POST['code'] ) : 301;
		// phpcs:enable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$result = Redirects::add( $from, $target, $code );
		self::back( 'arvaseo-redirects', $result['ok'] ? 'redirect_added' : 'redirect_' . ( $result['error'] ?? 'failed' ) );
	}

	/**
	 * Delete a redirect.
	 */
	public static function delete_redirect(): void {
		self::guard( 'arvaseo_connector_redirect_delete' );
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() checked the nonce.
		self::back( 'arvaseo-redirects', $id > 0 && Redirects::delete( $id ) ? 'redirect_deleted' : 'redirect_failed' );
	}

	/**
	 * Download the redirects as a CSV file.
	 */
	public static function export_redirects(): void {
		self::guard( 'arvaseo_connector_redirect_export' );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="arvaseo-redirects-' . gmdate( 'Y-m-d' ) . '.csv"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo Redirects::csv(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV download, built from validated rules with formula cells defused.
		exit;
	}

	/**
	 * Take one address out of the broken-address list.
	 */
	public static function remove_missing(): void {
		self::guard( 'arvaseo_connector_404_remove' );
		NotFoundLog::remove( isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() checked the nonce.
		self::back( 'arvaseo-redirects', 'missing_removed', 'arvaseo-missing' );
	}

	/**
	 * Empty the broken-address list.
	 */
	public static function clear_missing(): void {
		self::guard( 'arvaseo_connector_404_clear' );
		NotFoundLog::clear();
		self::back( 'arvaseo-redirects', 'missing_cleared', 'arvaseo-missing' );
	}

	/**
	 * Nonce and capability.
	 *
	 * @param string $action Nonce action.
	 */
	private static function guard( string $action ): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You don’t have permission to do that.', 'arvaseo-connector' ), 403 );
		}
		check_admin_referer( $action );
	}

	/**
	 * Back to a screen with a notice code.
	 *
	 * @param string $page   Page slug.
	 * @param string $notice Notice code.
	 * @param string $anchor Element ID to scroll to.
	 */
	private static function back( string $page, string $notice, string $anchor = '' ): void {
		wp_safe_redirect( add_query_arg( 'arvaseo_notice', $notice, admin_url( 'admin.php?page=' . $page ) ) . ( '' !== $anchor ? '#' . $anchor : '' ) );
		exit;
	}
}
