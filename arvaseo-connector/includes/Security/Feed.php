<?php
/**
 * Arva SEO's security feed (2.5.0): what the scan learns between plugin releases, downloaded once
 * a day while the site is connected. Malware that deep scans found on other websites, by SHA-256;
 * files Arva SEO checked and found safe; the PHP versions with the latest security fixes; and new
 * patterns. Without a connection, or until the first download, the data that comes with the
 * plugin (data/signatures.json) is used.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Security;

use ArvaSEO\Connector\Api\ApiClient;
use ArvaSEO\Connector\Auth\TokenStore;
use ArvaSEO\Connector\Logging\AuditLogger;

defined( 'ABSPATH' ) || exit;

/**
 * Everything downloaded is checked before it's kept: fingerprints must be 64 hex characters,
 * patterns must compile and use the same fields as signatures.json, and names are plain text.
 * Nothing in it is ever run.
 */
final class Feed {

	/** The downloaded feed (not autoloaded: only scans need it). */
	public const OPTION = 'arvaseo_connector_security_feed';

	/** Downloaded again after a day. */
	public const MAX_AGE = DAY_IN_SECONDS;

	/** At most this many fingerprints of each kind are kept. */
	public const MAX_FILES = 5000;

	/** At most this many patterns. */
	public const MAX_PATTERNS = 200;

	/**
	 * The feed, once per request.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $data = null;

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( 'arvaseo_connector_maintenance', array( self::class, 'daily' ) );
	}

	/**
	 * Daily, with the plugin's maintenance.
	 */
	public static function daily(): void {
		self::refresh();
	}

	/**
	 * Download the feed when it's older than a day (or always, with $force).
	 *
	 * @param bool $force Download even when it's fresh.
	 * @return string updated|unchanged|fresh|not_connected|failed
	 */
	public static function refresh( bool $force = false ): string {
		if ( ! TokenStore::is_connected() ) {
			return 'not_connected';
		}
		$stored = self::stored();
		if ( ! $force && time() - (int) ( $stored['fetched_at'] ?? 0 ) < self::MAX_AGE ) {
			return 'fresh';
		}
		$have     = (string) ( $stored['version'] ?? '' );
		$response = ApiClient::instance()->site( 'GET', '/security/feed' . ( '' !== $have ? '?have=' . rawurlencode( $have ) : '' ), null, 10 );
		if ( ! $response->ok() ) {
			return 'failed';
		}
		$data = $response->data();
		if ( ! empty( $data['unchanged'] ) && '' !== $have && ( $data['version'] ?? null ) === $have ) {
			$stored['fetched_at'] = time();
			self::save( $stored );
			return 'unchanged';
		}
		$clean = self::clean( $data );
		if ( null === $clean ) {
			return 'failed';
		}
		self::save( $clean + array( 'fetched_at' => time() ) );
		AuditLogger::info(
			'security.feed_updated',
			/* translators: 1: feed version, 2: number of known malware files. */
			sprintf( __( 'Security feed updated to %1$s (%2$d known malware files).', 'arvaseo-connector' ), $clean['version'], count( $clean['malware'] ) ),
			array( 'version' => $clean['version'] )
		);
		return 'updated';
	}

	/**
	 * Download it before a scan when it's more than a few days old (the daily run may not have
	 * happened on a quiet site).
	 */
	public static function before_scan(): void {
		if ( time() - (int) ( self::stored()['fetched_at'] ?? 0 ) > 3 * DAY_IN_SECONDS ) {
			self::refresh( true );
		}
	}

	/**
	 * The feed's version, or '' before the first download.
	 */
	public static function version(): string {
		return (string) ( self::stored()['version'] ?? '' );
	}

	/**
	 * When it was last downloaded (0 if never).
	 */
	public static function fetched_at(): int {
		return (int) ( self::stored()['fetched_at'] ?? 0 );
	}

	/**
	 * What known malware a file is, by its SHA-256, or null.
	 *
	 * @param string $sha256 Lowercase hex.
	 */
	public static function malware( string $sha256 ): ?string {
		$name = self::stored()['malware'][ $sha256 ] ?? null;
		return is_string( $name ) ? $name : null;
	}

	/**
	 * Whether Arva SEO checked this exact file and found it safe.
	 *
	 * @param string $sha256 Lowercase hex.
	 */
	public static function is_safe( string $sha256 ): bool {
		return isset( self::stored()['safe'][ $sha256 ] );
	}

	/**
	 * Hosts of scripts Arva SEO knows as common, safe services (2.7.0): host => true.
	 *
	 * @return array<string, true>
	 */
	public static function known_hosts(): array {
		return (array) ( self::stored()['known_hosts'] ?? array() );
	}

	/**
	 * Lines Arva SEO's staff found safe in changed files (2.7.0), by the SHA-256 of the trimmed
	 * line: a change that only adds these isn't reported.
	 *
	 * @param string $line The added line.
	 */
	public static function is_safe_line( string $line ): bool {
		return isset( self::stored()['safe_lines'][ hash( 'sha256', trim( $line ) ) ] );
	}

	/**
	 * Hosts of scripts Arva SEO's staff confirmed as bad (2.7.0): host => what it is.
	 *
	 * @return array<string, string>
	 */
	public static function bad_hosts(): array {
		return (array) ( self::stored()['bad_hosts'] ?? array() );
	}

	/**
	 * New patterns, in signatures.json's format.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function patterns(): array {
		return (array) ( self::stored()['patterns'] ?? array() );
	}

	/**
	 * The PHP versions with the latest security fixes: the feed's, else the plugin's own.
	 *
	 * @param array<string, mixed>|null $bundled signatures.json's php block.
	 * @return array{released: string, branches: array<string, string>, oldest_supported: string}|null
	 */
	public static function php( ?array $bundled ): ?array {
		$php = self::stored()['php'] ?? null;
		return is_array( $php ) ? $php : self::clean_php( $bundled );
	}

	/**
	 * Forget the downloaded feed (disconnect, uninstall, tests).
	 */
	public static function clear(): void {
		delete_option( self::OPTION );
		self::clear_cache();
	}

	/**
	 * Read the stored feed again on next use (after it's changed outside this class: tests).
	 */
	public static function clear_cache(): void {
		self::$data = null;
		Signatures::forget();
	}

	/**
	 * The stored feed.
	 *
	 * @return array<string, mixed>
	 */
	private static function stored(): array {
		if ( null === self::$data ) {
			$value      = get_option( self::OPTION, array() );
			self::$data = is_array( $value ) ? $value : array();
		}
		return self::$data;
	}

	/**
	 * Keep it (fingerprints as keys, for quick lookups).
	 *
	 * @param array<string, mixed> $data Clean feed.
	 */
	private static function save( array $data ): void {
		update_option( self::OPTION, $data, false );
		self::$data = $data;
		Signatures::forget();
	}

	/**
	 * What's kept from a download, or null when it isn't a feed.
	 *
	 * @param array<string, mixed> $data Downloaded.
	 * @return array{version: string, php: array<string, mixed>|null, patterns: array<int, array<string, mixed>>, malware: array<string, string>, safe: array<string, true>, safe_lines: array<string, true>, known_hosts: array<string, true>, bad_hosts: array<string, string>}|null
	 */
	public static function clean( array $data ): ?array {
		$version = $data['version'] ?? null;
		if ( ! is_string( $version ) || 1 !== preg_match( '/^[A-Za-z0-9.\-]{1,40}$/', $version ) ) {
			return null;
		}
		$malware = array();
		foreach ( array_slice( (array) ( $data['malware'] ?? array() ), 0, self::MAX_FILES ) as $file ) {
			$sha = is_array( $file ) ? ( $file['sha256'] ?? null ) : null;
			if ( is_string( $sha ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $sha ) ) {
				$name            = trim( wp_strip_all_tags( (string) ( $file['name'] ?? '' ) ) );
				$malware[ $sha ] = '' !== $name ? mb_substr( $name, 0, 200 ) : __( 'Known malware', 'arvaseo-connector' );
			}
		}
		$safe = array();
		foreach ( array_slice( (array) ( $data['safe'] ?? array() ), 0, self::MAX_FILES ) as $sha ) {
			if ( is_string( $sha ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $sha ) ) {
				$safe[ $sha ] = true;
			}
		}
		$patterns = array();
		foreach ( array_slice( (array) ( $data['patterns'] ?? array() ), 0, self::MAX_PATTERNS ) as $raw ) {
			$pattern = is_array( $raw ) ? self::clean_pattern( $raw ) : null;
			if ( null !== $pattern ) {
				$patterns[] = $pattern;
			}
		}
		$host  = static fn ( $value ): ?string => is_string( $value ) && 1 === preg_match( '/^(?:[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}$/', $value ) ? $value : null;
		$known = array();
		foreach ( array_slice( (array) ( $data['known_hosts'] ?? array() ), 0, self::MAX_FILES ) as $value ) {
			if ( null !== $host( $value ) ) {
				$known[ $value ] = true;
			}
		}
		$bad = array();
		foreach ( array_slice( (array) ( $data['bad_hosts'] ?? array() ), 0, self::MAX_FILES ) as $entry ) {
			$name = is_array( $entry ) ? $host( $entry['host'] ?? null ) : null;
			if ( null !== $name ) {
				$what         = trim( wp_strip_all_tags( (string) ( $entry['name'] ?? '' ) ) );
				$bad[ $name ] = '' !== $what ? mb_substr( $what, 0, 100 ) : __( 'confirmed as bad by Arva SEO', 'arvaseo-connector' );
			}
		}
		$safe_lines = array();
		foreach ( array_slice( (array) ( $data['safe_lines'] ?? array() ), 0, self::MAX_FILES ) as $sha ) {
			if ( is_string( $sha ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $sha ) ) {
				$safe_lines[ $sha ] = true;
			}
		}
		return array(
			'version'     => $version,
			'safe_lines'  => $safe_lines,
			'php'         => self::clean_php( is_array( $data['php'] ?? null ) ? $data['php'] : null ),
			'patterns'    => $patterns,
			'malware'     => $malware,
			'safe'        => $safe,
			'known_hosts' => $known,
			'bad_hosts'   => $bad,
		);
	}

	/**
	 * A pattern from the feed, checked like the plugin's own, or null.
	 *
	 * @param array<string, mixed> $raw Pattern.
	 * @return array<string, mixed>|null
	 */
	private static function clean_pattern( array $raw ): ?array {
		$id       = $raw['id'] ?? null;
		$applies  = array_values( array_intersect( array_map( 'strval', (array) ( $raw['applies'] ?? array() ) ), array( 'php', 'js', 'htaccess', 'svg' ) ) );
		$severity = $raw['severity'] ?? null;
		$pattern  = $raw['pattern'] ?? null;
		$flags    = preg_replace( '/[^imsxu]/', '', (string) ( $raw['flags'] ?? 'i' ) );
		$also     = array_values( array_filter( (array) ( $raw['also'] ?? array() ), static fn ( $p ): bool => is_string( $p ) && strlen( $p ) <= 1000 ) );
		if ( ! is_string( $id ) || 1 !== preg_match( '/^[a-z0-9_.\-]{3,60}$/', $id ) || array() === $applies || ! in_array( $severity, Findings::SEVERITIES, true )
			|| ! is_string( $pattern ) || '' === $pattern || strlen( $pattern ) > 1000 || count( $also ) > 3 ) {
			return null;
		}
		foreach ( array_merge( array( $pattern ), $also ) as $one ) {
			if ( false === @preg_match( '~' . str_replace( '~', '\~', $one ) . '~' . $flags, '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a pattern that doesn't compile is dropped.
				return null;
			}
		}
		return array(
			'id'       => $id,
			'applies'  => $applies,
			'severity' => $severity,
			'title'    => mb_substr( trim( wp_strip_all_tags( (string) ( $raw['title'] ?? '' ) ) ), 0, 200 ),
			'pattern'  => $pattern,
			'flags'    => $flags,
			'also'     => $also,
		);
	}

	/**
	 * The PHP block, checked, or null.
	 *
	 * @param array<string, mixed>|null $php From the feed or signatures.json.
	 * @return array{released: string, branches: array<string, string>, oldest_supported: string}|null
	 */
	private static function clean_php( ?array $php ): ?array {
		if ( null === $php ) {
			return null;
		}
		$branches = array();
		foreach ( (array) ( $php['branches'] ?? array() ) as $branch => $version ) {
			if ( is_string( $version ) && 1 === preg_match( '/^\d+\.\d+$/', (string) $branch ) && str_starts_with( $version, $branch . '.' ) && 1 === preg_match( '/^\d+\.\d+\.\d+$/', $version ) ) {
				$branches[ (string) $branch ] = $version;
			}
		}
		$oldest   = $php['oldest_supported'] ?? null;
		$released = $php['released'] ?? null;
		if ( array() === $branches || ! is_string( $oldest ) || 1 !== preg_match( '/^\d+\.\d+$/', $oldest ) || ! is_string( $released ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $released ) ) {
			return null;
		}
		return array(
			'released'         => $released,
			'branches'         => $branches,
			'oldest_supported' => $oldest,
		);
	}
}
