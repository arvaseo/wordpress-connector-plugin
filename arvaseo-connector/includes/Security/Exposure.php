<?php
/**
 * Exposed files and risky settings: a few requests from the site to itself, as WordPress's own
 * Site Health does, and a look at its settings.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Security;

defined( 'ABSPATH' ) || exit;

/**
 * A file only counts as downloadable when the response really is that file (its first bytes
 * look like it), so a site that answers every address with a page isn't flagged. Only files
 * that exist on the server are asked for, and each answer is read no further than 4 KB.
 */
final class Exposure {

	/** Backup and export files in the site's main folder, by name. */
	private const BACKUP_NAME = '/^(?:.+\.(?:sql|sql\.gz|sql\.zip|zip|tar|tar\.gz|tgz|gz|bak|old|orig|save|swp)|wp-config\.php.+|\.?wp-config\.php\.\w+)$/i';

	/**
	 * Run the checks.
	 *
	 * @return array{findings: array<int, array<string, mixed>>, checked: bool}
	 */
	public static function run(): array {
		$findings = array();
		$checked  = true;

		// Files that exist here and must not be reachable from the internet.
		$candidates = array();
		if ( is_file( ABSPATH . '.git/HEAD' ) ) {
			$candidates[] = array( '.git/HEAD', 'exposed_git', 'critical', '/^ref:\s*refs\//' );
		}
		foreach ( array( ABSPATH . '.env', dirname( ABSPATH ) . '/.env' ) as $env ) {
			if ( is_file( $env ) && str_starts_with( $env, ABSPATH ) ) {
				$candidates[] = array( '.env', 'exposed_env', 'critical', '/^\s*[A-Z][A-Z0-9_]*\s*=/m' );
			}
		}
		$log = WP_CONTENT_DIR . '/debug.log';
		if ( is_file( $log ) && filesize( $log ) > 0 && str_starts_with( $log, ABSPATH ) ) {
			$candidates[] = array( self::relative( $log ), 'exposed_debug_log', 'high', '/PHP (?:Warning|Notice|Fatal|Deprecated|Parse)|^\[\d{2}-\w{3}-\d{4}/m' );
		}
		foreach ( self::backups() as $name ) {
			$type         = str_contains( strtolower( $name ), 'wp-config' ) ? 'exposed_config_backup' : ( preg_match( '/\.sql(?:\.gz|\.zip)?$/i', $name ) ? 'exposed_database' : 'exposed_backup' );
			$candidates[] = array( $name, $type, 'exposed_backup' === $type ? 'high' : 'critical', null );
		}

		foreach ( $candidates as list( $path, $type, $severity, $looks_like ) ) {
			$body = self::fetch( $path );
			if ( false === $body ) {
				$checked = false;
				continue;
			}
			if ( null !== $body && self::is_the_file( $path, $body, $looks_like ) ) {
				$findings[] = Findings::make(
					$type,
					$severity,
					array(
						'path'    => $path,
						'subject' => $path,
					)
				);
			}
		}

		// Folder listing of uploads.
		$uploads = wp_upload_dir( null, false );
		if ( empty( $uploads['error'] ) && is_string( $uploads['baseurl'] ?? null ) && ! is_file( (string) $uploads['basedir'] . '/index.php' ) && ! is_file( (string) $uploads['basedir'] . '/index.html' ) ) {
			$body = self::get( trailingslashit( (string) $uploads['baseurl'] ) );
			if ( false === $body ) {
				$checked = false;
			} elseif ( is_string( $body ) && 1 === preg_match( '/<title>\s*Index of \//i', $body ) ) {
				$findings[] = Findings::make( 'directory_listing', 'medium', array( 'path' => self::relative( (string) $uploads['basedir'] ) . '/' ) );
			}
		}

		// Settings.
		$php = self::php();
		if ( null !== $php ) {
			$findings[] = $php;
		}
		if ( ( ! defined( 'DISALLOW_FILE_EDIT' ) || ! DISALLOW_FILE_EDIT ) && wp_is_file_mod_allowed( 'capability_edit_themes' ) ) {
			$findings[] = Findings::make( 'file_editing', 'medium' );
		}
		$admin = get_user_by( 'login', 'admin' );
		if ( $admin instanceof \WP_User && user_can( $admin, 'manage_options' ) ) {
			$findings[] = Findings::make( 'admin_username', 'medium' );
		}
		$xmlrpc = self::post_xmlrpc();
		if ( null === $xmlrpc ) {
			$checked = false;
		} elseif ( $xmlrpc ) {
			$findings[] = Findings::make( 'xmlrpc', 'low' );
		}
		$users = self::get( rest_url( 'wp/v2/users' ) );
		if ( false === $users ) {
			$checked = false;
		} elseif ( is_string( $users ) && 1 === preg_match( '/^\s*\[\s*\{[^}]*"slug"\s*:/', $users ) ) {
			$findings[] = Findings::make( 'user_listing', 'low' );
		}

		return array(
			'findings' => $findings,
			'checked'  => $checked,
		);
	}

	/**
	 * PHP without its branch's latest security fixes, or a branch that no longer gets them (2.5.0),
	 * from the security feed's list (else the plugin's own). Linux distributions (Ubuntu, Debian,
	 * Red Hat) add fixes to an older version number, so there it's only Medium: the host can tell.
	 *
	 * @param string $version PHP_VERSION.
	 * @param string $extra   PHP_EXTRA_VERSION, e.g. "-1ubuntu1.2".
	 * @return array<string, mixed>|null
	 */
	public static function php( string $version = PHP_VERSION, string $extra = PHP_EXTRA_VERSION ): ?array {
		$data = Feed::php( Signatures::php() );
		if ( null === $data || 1 !== preg_match( '/^(\d+)\.(\d+)\.(\d+)/', $version, $m ) ) {
			return null;
		}
		$branch  = $m[1] . '.' . $m[2];
		$release = $branch . '.' . $m[3];
		if ( version_compare( $branch, $data['oldest_supported'], '<' ) ) {
			return Findings::make(
				'php_unsupported',
				'high',
				array(
					'subject' => $branch,
					/* translators: %s: PHP version, such as 8.1. */
					'title'   => sprintf( __( 'PHP %s no longer gets security fixes', 'arvaseo-connector' ), $branch ),
				)
			);
		}
		$needed = $data['branches'][ $branch ] ?? null;
		if ( null === $needed || version_compare( $release, $needed, '>=' ) ) {
			return null; // Up to date, or a newer branch than the list knows.
		}
		$distro = '' !== $extra && 1 === preg_match( '/ubuntu|deb|\.el\d|amzn|fc\d|suse/i', $extra ) && false === stripos( $extra, 'sury' );
		return Findings::make(
			'php_outdated',
			$distro ? 'medium' : 'high',
			array(
				'subject' => $release,
				/* translators: 1: PHP version, such as 8.3.30, 2: the date PHP released the fixes. */
				'title'   => sprintf( __( 'PHP %1$s is missing security fixes from %2$s', 'arvaseo-connector' ), $release, date_i18n( (string) get_option( 'date_format' ), (int) strtotime( $data['released'] . ' 12:00:00' ) ) ),
				'detail'  => $needed,
			)
		);
	}

	/**
	 * Backup and export files in the site's main folder.
	 *
	 * @return string[]
	 */
	private static function backups(): array {
		$names = @scandir( ABSPATH ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an unreadable folder just has no backups to report.
		$found = array();
		foreach ( is_array( $names ) ? $names : array() as $name ) {
			if ( 'wp-config.php' !== $name && is_file( ABSPATH . $name ) && 1 === preg_match( self::BACKUP_NAME, $name ) ) {
				$found[] = $name;
			}
			if ( count( $found ) >= 20 ) {
				break;
			}
		}
		return $found;
	}

	/**
	 * Whether a response is the file itself.
	 *
	 * @param string      $path       Path.
	 * @param string      $body       First bytes of the response.
	 * @param string|null $looks_like Pattern its contents match, or null to judge by type.
	 */
	private static function is_the_file( string $path, string $body, ?string $looks_like ): bool {
		if ( null !== $looks_like ) {
			return 1 === preg_match( $looks_like, $body );
		}
		$lower = strtolower( $path );
		return match ( true ) {
			str_contains( $lower, 'wp-config' )                     => str_contains( $body, 'DB_' ) || str_contains( $body, '<?php' ),
			(bool) preg_match( '/\.sql$/', $lower )                 => (bool) preg_match( '/CREATE TABLE|INSERT INTO|-- MySQL dump|-- MariaDB dump/i', $body ),
			(bool) preg_match( '/\.(?:zip|sql\.zip)$/', $lower )    => str_starts_with( $body, "PK\x03\x04" ),
			(bool) preg_match( '/\.(?:gz|tgz|tar\.gz)$/', $lower )  => str_starts_with( $body, "\x1f\x8b" ),
			(bool) preg_match( '/\.tar$/', $lower )                 => 'ustar' === substr( $body, 257, 5 ),
			default                                                 => ! preg_match( '/<html|<!doctype/i', substr( $body, 0, 300 ) ),
		};
	}

	/**
	 * Ask the site for one of its files.
	 *
	 * @param string $path Path from the site's main folder.
	 * @return string|null|false The first bytes when it answers 200, null when it doesn't serve it, false when the site couldn't be reached.
	 */
	private static function fetch( string $path ) {
		return self::get( site_url( '/' . implode( '/', array_map( 'rawurlencode', explode( '/', $path ) ) ) ) );
	}

	/**
	 * GET without following redirects.
	 *
	 * @param string $url URL.
	 * @return string|null|false Body on 200, null on anything else, false when unreachable.
	 */
	private static function get( string $url ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => 5,
				'redirection'         => 0,
				'limit_response_size' => 4096,
				'sslverify'           => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own filter for requests to itself.
				'headers'             => array( 'Cache-Control' => 'no-cache' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return false;
		}
		return 200 === wp_remote_retrieve_response_code( $response ) ? (string) wp_remote_retrieve_body( $response ) : null;
	}

	/**
	 * Whether XML-RPC answers method calls: true, false, or null when the site couldn't be reached.
	 */
	private static function post_xmlrpc(): ?bool {
		$response = wp_remote_post(
			site_url( '/xmlrpc.php' ),
			array(
				'timeout'             => 5,
				'redirection'         => 0,
				'limit_response_size' => 8192,
				'sslverify'           => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own filter for requests to itself.
				'headers'             => array( 'Content-Type' => 'text/xml' ),
				'body'                => '<?xml version="1.0"?><methodCall><methodName>system.listMethods</methodName><params></params></methodCall>',
			)
		);
		if ( is_wp_error( $response ) ) {
			return null;
		}
		$body = (string) wp_remote_retrieve_body( $response );
		return 200 === wp_remote_retrieve_response_code( $response ) && str_contains( $body, '<methodResponse>' ) && str_contains( $body, 'wp.getUsersBlogs' );
	}

	/**
	 * A path relative to the site's main folder.
	 *
	 * @param string $path Absolute path.
	 */
	public static function relative( string $path ): string {
		$path = wp_normalize_path( $path );
		$root = wp_normalize_path( ABSPATH );
		return str_starts_with( $path, $root ) ? substr( $path, strlen( $root ) ) : $path;
	}
}
