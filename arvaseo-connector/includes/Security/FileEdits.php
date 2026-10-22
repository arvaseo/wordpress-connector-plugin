<?php
/**
 * Files changed with WordPress's theme and plugin editors (2.7.0), recorded as they happen and
 * sent to Arva SEO at once, which emails the workspace's owners and admins.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Security;

use ArvaSEO\Connector\Api\ApiClient;
use ArvaSEO\Connector\Auth\TokenStore;
use ArvaSEO\Connector\Cron\CronManager;
use ArvaSEO\Connector\Logging\AuditLogger;
use ArvaSEO\Connector\Privacy\PrivacyService;

defined( 'ABSPATH' ) || exit;

/**
 * Both editors save through one request (admin-ajax.php's edit-theme-plugin-file, or the editor
 * page itself without JavaScript). Before WordPress handles it, the file's fingerprint is read;
 * when the request ends, a file that changed is recorded with who saved it, when and from which
 * address, and the lines that were added. A stolen administrator password plus the theme editor
 * is a common way in, so this goes out even when the change looks harmless.
 */
final class FileEdits {

	/** Edits not sent yet (no connection, or Arva SEO didn't answer). */
	public const OUTBOX = 'arvaseo_connector_file_edits_outbox';

	/** The cron job that sends them again. */
	public const HOOK = 'arvaseo_connector_file_edits';

	/** Edits kept in the outbox, at most. */
	private const MAX_OUTBOX = 20;

	/**
	 * The file being saved in this request: [absolute path, relative path, sha256, contents].
	 *
	 * @var array{0: string, 1: string, 2: string|null, 3: string|null}|null
	 */
	private static ?array $watching = null;

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( 'wp_ajax_edit-theme-plugin-file', array( self::class, 'before' ), 0 );
		add_action( 'load-theme-editor.php', array( self::class, 'before_page' ) );
		add_action( 'load-plugin-editor.php', array( self::class, 'before_page' ) );
		add_action( self::HOOK, array( self::class, 'retry' ) );
	}

	/**
	 * The editor page saving without JavaScript.
	 */
	public static function before_page(): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- only reads which file is being saved; WordPress checks the nonce.
		if ( 'post' === $method && 'update' === ( isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '' ) ) {
			self::before();
		}
	}

	/**
	 * Before WordPress saves: which file, and its fingerprint now.
	 */
	public static function before(): void {
		$file = self::target();
		if ( null === $file ) {
			return;
		}
		$exists         = is_file( $file );
		$size           = $exists ? (int) filesize( $file ) : 0;
		$contents       = $exists && $size <= Scanner::MAX_READ ? (string) file_get_contents( $file ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file read.
		self::$watching = array( $file, Exposure::relative( $file ), $exists ? (string) hash_file( 'sha256', $file ) : null, $contents );
		add_action( 'shutdown', array( self::class, 'after' ), 0 );
	}

	/**
	 * When the request ends: record the change, if the file did change.
	 */
	public static function after(): void {
		if ( null === self::$watching ) {
			return;
		}
		[ $file, $relative, $before, $old ] = self::$watching;
		self::$watching                     = null;
		clearstatcache( true, $file );
		$now = is_file( $file ) ? (string) hash_file( 'sha256', $file ) : null;
		if ( null === $now || $now === $before ) {
			return; // Not saved (wrong nonce, no permission, or WordPress put it back after an error).
		}
		$contents = (int) filesize( $file ) <= Scanner::MAX_READ ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file read.
		$lines    = null !== $old ? FilePrints::added_lines( $old, $contents ) : array();
		$user     = wp_get_current_user();
		$edit     = array(
			'edit_id'     => wp_generate_uuid4(),
			'path'        => mb_substr( $relative, 0, 500 ),
			'editor'      => mb_substr( (string) $user->user_login, 0, 60 ),
			'editor_name' => mb_substr( (string) $user->display_name, 0, 100 ),
			'ip'          => self::ip( 'REMOTE_ADDR' ),
			// What a proxy in front of the site (Cloudflare, the host's) says the visitor's address was.
			'proxy_ip'    => self::ip( 'HTTP_CF_CONNECTING_IP' ) ?? self::ip( 'HTTP_X_FORWARDED_FOR' ),
			'edited_at'   => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'sha256'      => $now,
			'added_lines' => array_map( static fn ( array $l ): string => mb_substr( $l[1], 0, 200 ), array_slice( $lines, 0, FilePrints::MAX_LINES ) ),
			'added_count' => count( $lines ),
		);
		AuditLogger::warning(
			'security.file_edited',
			/* translators: 1: file path, 2: username. */
			sprintf( __( '%1$s was changed in the dashboard’s file editor by %2$s.', 'arvaseo-connector' ), $relative, (string) $user->user_login ),
			array( 'status' => 'file_editor' )
		);
		self::queue( $edit );
		self::flush();
	}

	/**
	 * Send what's waiting, when connected and allowed to share 2.7.0's security details.
	 *
	 * @return int How many were sent.
	 */
	public static function flush(): int {
		$waiting = self::waiting();
		if ( array() === $waiting || ! TokenStore::is_connected() || ! TokenStore::has_scope( 'health-snapshot:write' ) || ! PrivacyService::allows( 'security_details' ) ) {
			return 0;
		}
		$sent = 0;
		foreach ( $waiting as $id => $edit ) {
			$response = ApiClient::instance()->site( 'POST', '/file-edits', array( 'schema_version' => '1.0' ) + $edit, 8 );
			if ( ! $response->ok() && ! in_array( $response->status, array( 409, 422 ), true ) ) {
				CronManager::run_soon( self::HOOK, 10 * MINUTE_IN_SECONDS );
				break;
			}
			unset( $waiting[ $id ] );
			++$sent;
		}
		update_option( self::OUTBOX, $waiting, false );
		return $sent;
	}

	/**
	 * The cron job: send what's still waiting.
	 */
	public static function retry(): void {
		self::flush();
	}

	/**
	 * Edits waiting to be sent: edit_id => edit.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function waiting(): array {
		$value = get_option( self::OUTBOX, array() );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Remove everything (uninstall, tests).
	 */
	public static function clear(): void {
		delete_option( self::OUTBOX );
		wp_clear_scheduled_hook( self::HOOK );
		self::$watching = null;
	}

	// ----- Helpers --------------------------------------------------------------------------

	/**
	 * Keep an edit to send (the newest ones when there are too many).
	 *
	 * @param array<string, mixed> $edit Edit.
	 */
	private static function queue( array $edit ): void {
		$waiting                     = self::waiting();
		$waiting[ $edit['edit_id'] ] = $edit;
		update_option( self::OUTBOX, array_slice( $waiting, -self::MAX_OUTBOX, null, true ), false );
	}

	/**
	 * The file the editor is saving, as WordPress resolves it, or null.
	 */
	private static function target(): ?string {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- only reads which file is being saved; WordPress checks the nonce and permissions itself.
		$file = isset( $_POST['file'] ) ? wp_unslash( (string) $_POST['file'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a path, checked below.
		if ( '' === $file || str_contains( $file, '..' ) || 0 !== validate_file( $file ) ) {
			return null;
		}
		if ( ! empty( $_POST['plugin'] ) ) {
			$path = wp_normalize_path( WP_PLUGIN_DIR . '/' . $file );
			$root = wp_normalize_path( WP_PLUGIN_DIR ) . '/';
		} elseif ( ! empty( $_POST['theme'] ) ) {
			$theme = wp_get_theme( sanitize_text_field( wp_unslash( $_POST['theme'] ) ) );
			if ( ! $theme->exists() ) {
				return null;
			}
			$root = trailingslashit( wp_normalize_path( $theme->get_stylesheet_directory() ) );
			$path = $root . $file;
		} else {
			return null;
		}
		// phpcs:enable
		return str_starts_with( $path, $root ) ? $path : null;
	}

	/**
	 * An IP address, or null. REMOTE_ADDR is where the request came from; a forwarded header is
	 * kept apart, since anyone can send one.
	 *
	 * @param string $header REMOTE_ADDR, or a forwarded header's $_SERVER name (its first address).
	 */
	private static function ip( string $header ): ?string {
		$value = isset( $_SERVER[ $header ] ) ? sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) : '';
		$ip    = trim( (string) strtok( $value, ',' ) );
		return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : null;
	}
}
