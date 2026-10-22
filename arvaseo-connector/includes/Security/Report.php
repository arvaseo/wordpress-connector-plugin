<?php
/**
 * The security scan's results, sent to Arva SEO when the site is connected and an administrator
 * agreed to share them (their own consent).
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Security;

use ArvaSEO\Connector\Api\ApiClient;
use ArvaSEO\Connector\Auth\TokenStore;
use ArvaSEO\Connector\Cron\CronManager;
use ArvaSEO\Connector\Logging\AuditLogger;
use ArvaSEO\Connector\Privacy\PrivacyService;
use ArvaSEO\Connector\Support\Ids;
use ArvaSEO\Connector\Sync\SyncState;

defined( 'ABSPATH' ) || exit;

/**
 * Only the open findings (not the ones marked "This is expected"), each with its kind, severity,
 * key and relative path, and for suspicious code the pattern's name, the line and its start. Sent
 * after each scan and when the choice to share changes; unchanged results aren't sent again.
 * Arva SEO turns them into Website Health findings and sends the assessment back.
 */
final class Report {

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( 'arvaseo_connector_security_scanned', array( self::class, 'after_scan' ) );
	}

	/**
	 * After a scan: send its results.
	 */
	public static function after_scan(): void {
		self::send();
	}

	/**
	 * Send the latest results, when allowed and changed.
	 *
	 * @return string sent|unchanged|not_allowed|no_results|failed
	 */
	public static function send(): string {
		if ( ! TokenStore::is_connected() || ! TokenStore::has_scope( 'health-snapshot:write' ) || ! PrivacyService::allows( 'security' ) ) {
			return 'not_allowed';
		}
		$results = Findings::latest();
		if ( null === $results ) {
			return 'no_results';
		}
		// What 2.7.0 adds (changed lines, outside scripts, expected marks) only with the consent to it.
		$details  = PrivacyService::allows( 'security_details' );
		$findings = array_map( static fn ( array $f ): array => self::item( $f, $details ), Findings::open() );
		$hash     = Ids::hash(
			array(
				'scanned_at' => $results['scanned_at'],
				'findings'   => $findings,
				'expected'   => $details ? array_column( Findings::hidden(), 'key' ) : array(),
			)
		);
		$last     = SyncState::get( 'security' );
		if ( ( $last['hash'] ?? '' ) === $hash && 'uploaded' === ( $last['status'] ?? '' ) ) {
			return 'unchanged';
		}
		$summary = array_fill_keys( Findings::SEVERITIES, 0 );
		foreach ( $findings as $finding ) {
			++$summary[ $finding['severity'] ];
		}
		$coverage = (array) $results['coverage'];
		$payload  = array(
			'schema_version'       => '1.0',
			'site_registration_id' => TokenStore::site_id(),
			// A new ID whenever what's sent changes ("This is expected" changes the list).
			'scan_id'              => wp_generate_uuid4(),
			'scanned_at'           => gmdate( 'Y-m-d\TH:i:s\Z', (int) strtotime( (string) $results['scanned_at'] ) ),
			'files'                => (int) $results['files'],
			'seconds'              => (int) $results['seconds'],
			'partial'              => (bool) $results['partial'],
			'signatures_version'   => substr( (string) ( $results['signatures'] ?? '' ), 0, 20 ),
			'coverage'             => array(
				'core'            => is_string( $coverage['core'] ?? null ) ? $coverage['core'] : null,
				'plugins'         => (int) ( $coverage['plugins'] ?? 0 ),
				'plugins_checked' => (int) ( $coverage['plugins_checked'] ?? 0 ),
				'exposure'        => (bool) ( $coverage['exposure'] ?? false ),
			),
			'summary'              => $summary,
			'findings'             => $findings,
		);
		if ( $details ) {
			// What people here marked as expected (2.7.0): fingerprints only, so Arva SEO can learn
			// what's normal on many sites (a host's own lines, a builder's generated files).
			$payload['expected'] = array_map( array( self::class, 'expected' ), array_slice( Findings::hidden(), 0, 100 ) );
			// The scripts the site's pages load from other websites, and where each was found (2.7.0).
			$payload['scripts'] = array_map( array( self::class, 'script' ), array_slice( (array) ( $results['scripts'] ?? array() ), 0, OutsideScripts::MAX_HOSTS ) );
		}
		$response = ApiClient::instance()->site( 'POST', '/security-scans', $payload );
		if ( ! $response->ok() ) {
			SyncState::failure( 'security', $response );
			return 'failed';
		}
		SyncState::success(
			'security',
			array(
				'hash'  => $hash,
				'count' => count( $findings ),
			)
		);
		AuditLogger::info(
			'security.sent',
			/* translators: %d: number of findings. */
			sprintf( _n( 'Security scan results sent to Arva SEO (%d finding).', 'Security scan results sent to Arva SEO (%d findings).', count( $findings ), 'arvaseo-connector' ), count( $findings ) ),
			array( 'count' => count( $findings ) )
		);
		// Arva SEO assesses them in the background; fetch the result a little later.
		CronManager::run_soon( 'arvaseo_connector_assessment', 3 * MINUTE_IN_SECONDS );
		return 'sent';
	}

	/**
	 * One finding as Arva SEO receives it.
	 *
	 * @param array<string, mixed> $finding Finding.
	 * @param bool                 $details With what 2.7.0 adds (the added lines).
	 * @return array<string, mixed>
	 */
	private static function item( array $finding, bool $details ): array {
		$cut = static fn ( $value, int $max ): ?string => is_string( $value ) && '' !== $value ? mb_substr( $value, 0, $max ) : null;
		return array(
			'key'       => (string) $finding['key'],
			'type'      => (string) $finding['type'],
			'severity'  => (string) $finding['severity'],
			'path'      => $cut( $finding['path'] ?? null, 500 ),
			'signature' => $cut( $finding['signature'] ?? null, 60 ),
			// Titles are plain text; patterns' titles never contain markup.
			'title'     => null !== ( $finding['title'] ?? null ) ? $cut( str_replace( array( '<', '>' ), '', (string) $finding['title'] ), 200 ) : null,
			'line'      => isset( $finding['line'] ) ? (int) $finding['line'] : null,
			'excerpt'   => $cut( $finding['excerpt'] ?? null, 200 ),
			// The file's fingerprint (2.5.0), which Arva SEO's security feed learns from.
			'sha256'    => is_string( $finding['sha256'] ?? null ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $finding['sha256'] ) ? $finding['sha256'] : null,
		) + ( $details && isset( $finding['added_lines'] ) ? array(
			// A new or changed file (2.7.0): at most 10 of the lines that were added, never the file.
			'added_lines' => array_values( array_filter( array_map( static fn ( $l ): ?string => $cut( $l, 200 ), array_slice( (array) $finding['added_lines'], 0, FilePrints::MAX_LINES ) ) ) ),
			'added_count' => (int) ( $finding['added_count'] ?? 0 ),
		) : array() );
	}

	/**
	 * One outside script host, as Arva SEO receives it.
	 *
	 * @param array<string, mixed> $script From OutsideScripts::results().
	 * @return array{host: string, urls: array<int, string>, handles: array<int, string>, pages: array<int, string>, found_in: array<int, array<string, mixed>>}
	 */
	private static function script( array $script ): array {
		$texts = static fn ( $values, int $max ): array => array_values( array_filter( array_map( static fn ( $v ): string => is_string( $v ) ? mb_substr( $v, 0, $max ) : '', array_slice( (array) $values, 0, 3 ) ) ) );
		$found = array();
		foreach ( array_slice( (array) ( $script['found_in'] ?? array() ), 0, 3 ) as $place ) {
			$found[] = array_filter(
				array(
					'path'      => isset( $place['path'] ) ? mb_substr( (string) $place['path'], 0, 500 ) : null,
					'line'      => isset( $place['line'] ) ? (int) $place['line'] : null,
					'option'    => isset( $place['option'] ) ? mb_substr( (string) $place['option'], 0, 191 ) : null,
					'post_id'   => isset( $place['post_id'] ) ? (int) $place['post_id'] : null,
					'post_type' => isset( $place['post_type'] ) ? mb_substr( (string) $place['post_type'], 0, 40 ) : null,
				),
				static fn ( $v ): bool => null !== $v
			);
		}
		return array(
			'host'     => mb_substr( (string) $script['host'], 0, 253 ),
			'urls'     => $texts( $script['urls'] ?? array(), 300 ),
			'handles'  => $texts( $script['handles'] ?? array(), 100 ),
			'pages'    => $texts( $script['pages'] ?? array(), 300 ),
			'found_in' => $found,
		);
	}

	/**
	 * A finding marked as expected, as Arva SEO receives it: what it is and the file's fingerprint.
	 *
	 * @param array<string, mixed> $finding Finding.
	 * @return array{type: string, signature: string|null, path: string|null, sha256: string|null}
	 */
	private static function expected( array $finding ): array {
		return array(
			'type'      => (string) $finding['type'],
			'signature' => is_string( $finding['signature'] ?? null ) ? mb_substr( $finding['signature'], 0, 60 ) : null,
			'path'      => is_string( $finding['path'] ?? null ) ? mb_substr( $finding['path'], 0, 500 ) : null,
			'sha256'    => is_string( $finding['sha256'] ?? null ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $finding['sha256'] ) ? $finding['sha256'] : null,
		);
	}
}
