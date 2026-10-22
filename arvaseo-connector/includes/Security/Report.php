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
		$findings = array_map( array( self::class, 'item' ), Findings::open() );
		$hash     = Ids::hash(
			array(
				'scanned_at' => $results['scanned_at'],
				'findings'   => $findings,
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
	 * @return array{key: string, type: string, severity: string, path: string|null, signature: string|null, title: string|null, line: int|null, excerpt: string|null}
	 */
	private static function item( array $finding ): array {
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
		);
	}
}
