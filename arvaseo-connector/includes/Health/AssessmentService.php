<?php
/**
 * Arva SEO's assessment, shown read-only in WordPress.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Health;

use ArvaSEO\Connector\Api\ApiClient;
use ArvaSEO\Connector\Auth\TokenStore;
use ArvaSEO\Connector\Logging\AuditLogger;
use ArvaSEO\Connector\Sync\SyncState;

defined( 'ABSPATH' ) || exit;

/**
 * Fetches the assessment and keeps a sanitized copy. Everything in it is display data: plain
 * text only (no markup is kept), only known fields, enums checked, links only into the Arva SEO
 * app. Nothing in a finding or its guidance is ever run or changes this site. After a disconnect,
 * or when the evidence it rests on is old, it is shown as stale, never as current.
 */
final class AssessmentService {

	private const OPTION = 'arvaseo_connector_assessment';

	private const ENUMS = array(
		'category'          => array( 'core', 'extensions', 'runtime', 'security', 'reliability', 'performance', 'seo' ),
		'finding_status'    => array( 'open', 'needs_verification', 'in_progress', 'snoozed', 'dismissed', 'accepted_risk', 'resolved' ),
		'freshness_status'  => array( 'current', 'stale', 'expired', 'superseded' ),
		'assessed_severity' => array( 'critical', 'high', 'medium', 'low', 'informational' ),
		'confidence'        => array( 'high', 'medium', 'low' ),
		'workflow_priority' => array( 'urgent', 'high', 'normal', 'low', 'none' ),
	);

	/**
	 * Fetch the latest assessment.
	 */
	public static function refresh(): bool {
		if ( ! TokenStore::is_connected() || ! TokenStore::has_scope( 'health-assessment:read' ) ) {
			return false;
		}
		$response = ApiClient::instance()->site( 'GET', '/assessment' );
		if ( ! $response->ok() ) {
			SyncState::failure( 'assessment', $response );
			return false;
		}
		$data = $response->data();
		if ( ( $data['schema_version'] ?? '' ) !== '1.0' || ( $data['site_registration_id'] ?? '' ) !== TokenStore::site_id() ) {
			AuditLogger::warning( 'assessment.rejected', __( 'Ignored an assessment that didn’t match this site or schema.', 'arvaseo-connector' ) );
			return false;
		}
		$findings = array();
		foreach ( array_slice( (array) ( $data['findings'] ?? array() ), 0, 200 ) as $finding ) {
			$clean = is_array( $finding ) ? self::finding( $finding ) : null;
			if ( null !== $clean ) {
				$findings[] = $clean;
			}
		}
		update_option(
			self::OPTION,
			array(
				'fetched_at'         => time(),
				'snapshot_id'        => is_string( $data['snapshot_id'] ?? null ) ? sanitize_text_field( $data['snapshot_id'] ) : null,
				'rules_version'      => is_string( $data['rules_version'] ?? null ) ? sanitize_text_field( $data['rules_version'] ) : null,
				'assessed_at'        => is_string( $data['assessed_at'] ?? null ) ? sanitize_text_field( $data['assessed_at'] ) : null,
				'generated_at'       => is_string( $data['generated_at'] ?? null ) ? sanitize_text_field( $data['generated_at'] ) : null,
				'data_freshness'     => in_array( $data['data_freshness'] ?? '', array( 'current', 'stale', 'expired', 'none' ), true ) ? $data['data_freshness'] : 'none',
				'assessment_pending' => ! empty( $data['assessment_pending'] ),
				'vulnerability'      => self::text( $data['vulnerability_assessment']['reason'] ?? '', 300 ),
				'findings'           => $findings,
				'app_url'            => ApiClient::app_link( $data['app_url'] ?? null ),
				'stale'              => false,
			),
			false
		);
		SyncState::success( 'assessment', array( 'count' => count( $findings ) ) );
		return true;
	}

	/**
	 * The stored assessment with its display freshness.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get(): ?array {
		$value = get_option( self::OPTION );
		if ( ! is_array( $value ) ) {
			return null;
		}
		$latest_upload              = SyncState::get( 'health' )['id'] ?? null;
		$value['display_freshness'] = match ( true ) {
			! empty( $value['stale'] ) || ! TokenStore::is_connected() => 'stale',
			null !== $latest_upload && null !== $value['snapshot_id'] && $latest_upload !== $value['snapshot_id'] => 'superseded',
			(int) $value['fetched_at'] < time() - 2 * DAY_IN_SECONDS => 'stale',
			default => (string) $value['data_freshness'],
		};
		return $value;
	}

	/**
	 * After a disconnect: keep for reference, never show as current.
	 */
	public static function mark_stale(): void {
		$value = get_option( self::OPTION );
		if ( is_array( $value ) ) {
			$value['stale'] = true;
			update_option( self::OPTION, $value, false );
		}
	}

	/**
	 * Remove it.
	 */
	public static function clear(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Keep only known fields, as plain text.
	 *
	 * @param array<string, mixed> $f Finding from the API.
	 * @return array<string, mixed>|null
	 */
	private static function finding( array $f ): ?array {
		foreach ( self::ENUMS as $key => $allowed ) {
			if ( ! in_array( $f[ $key ] ?? null, $allowed, true ) ) {
				return null;
			}
		}
		$fix      = is_array( $f['fix'] ?? null ) ? $f['fix'] : array();
		$evidence = array();
		foreach ( array_slice( (array) ( $f['evidence'] ?? array() ), 0, 10 ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$evidence[] = array(
				'check_id'        => self::text( $item['check_id'] ?? '', 80 ),
				'subject'         => self::text( $item['subject'] ?? '', 200 ),
				'check_status'    => in_array( $item['check_status'] ?? '', array( 'good', 'recommended', 'critical', 'unknown' ), true ) ? $item['check_status'] : null,
				'observed_value'  => self::text( wp_json_encode( $item['observed_value'] ?? null ), 600 ),
				'evidence_source' => self::text( $item['evidence_source'] ?? '', 40 ),
				'observed_at'     => self::text( $item['observed_at'] ?? '', 40 ),
			);
		}
		$source = is_array( $f['source'] ?? null ) ? $f['source'] : array();
		return array(
			'issue_id'          => self::text( $f['issue_id'] ?? '', 40 ),
			'category'          => $f['category'],
			'finding_status'    => $f['finding_status'],
			'freshness_status'  => $f['freshness_status'],
			'assessed_severity' => $f['assessed_severity'],
			'confidence'        => $f['confidence'],
			'workflow_priority' => $f['workflow_priority'],
			'title'             => self::text( $f['title'] ?? '', 200 ),
			'what_is_wrong'     => self::text( $f['what_is_wrong'] ?? '', 2000 ),
			'why_it_matters'    => self::text( $f['why_it_matters'] ?? '', 2000 ),
			'what_to_fix'       => self::text( $f['what_to_fix'] ?? '', 2000 ),
			'steps'             => self::texts( $fix['steps'] ?? array() ),
			'responsible_party' => in_array( $fix['responsible_party'] ?? '', array( 'site_admin', 'developer', 'host', 'arvaseo_specialist' ), true ) ? $fix['responsible_party'] : null,
			'prerequisites'     => self::texts( $fix['prerequisites'] ?? array() ),
			'risk_level'        => in_array( $fix['risk_level'] ?? '', array( 'low', 'medium', 'high' ), true ) ? $fix['risk_level'] : null,
			'backup_required'   => ! empty( $fix['backup_required'] ),
			'verification'      => self::texts( $fix['verification_steps'] ?? array() ),
			'rollback'          => self::texts( $fix['rollback_steps'] ?? array() ),
			'evidence'          => $evidence,
			'source_type'       => in_array( $source['type'] ?? '', array( 'wordpress_fact', 'arvaseo_rule', 'arvaseo_remote_audit', 'authoritative_advisory' ), true ) ? $source['type'] : null,
			'source_reference'  => self::text( $source['reference'] ?? '', 200 ),
			'rules_version'     => self::text( $source['rules_version'] ?? '', 40 ),
			'edited'            => ! empty( $f['edited'] ),
			'client_notes'      => self::text( $f['client_notes'] ?? '', 2000 ),
			'last_detected_at'  => self::text( $f['last_detected_at'] ?? '', 40 ),
			'assessed_at'       => self::text( $f['assessed_at'] ?? '', 40 ),
		);
	}

	/**
	 * Plain text, bounded.
	 *
	 * @param mixed $value Value.
	 * @param int   $max   Length.
	 */
	private static function text( $value, int $max ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		return mb_substr( sanitize_textarea_field( wp_strip_all_tags( (string) $value ) ), 0, $max );
	}

	/**
	 * A list of plain texts.
	 *
	 * @param mixed $values Values.
	 * @return string[]
	 */
	private static function texts( $values ): array {
		$out = array();
		foreach ( array_slice( (array) $values, 0, 15 ) as $value ) {
			$text = self::text( $value, 500 );
			if ( '' !== $text ) {
				$out[] = $text;
			}
		}
		return $out;
	}
}
