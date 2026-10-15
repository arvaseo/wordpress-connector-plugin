<?php
/**
 * Consent, disclosure, privacy-policy text and personal-data export/erasure.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Privacy;

use ArvaSEO\Connector\Config;
use ArvaSEO\Connector\Install\Schema;
use ArvaSEO\Connector\Support\Ids;

defined( 'ABSPATH' ) || exit;

/**
 * Nothing about the site is sent until an administrator agrees to the exact field list below.
 * The consent record keeps the schema version, time, the hash of the approved field set, the
 * purposes and the recipient category; a new field, purpose or recipient needs new consent.
 */
final class PrivacyService {

	private const OPTION = 'arvaseo_connector_consent';

	/**
	 * Every field the plugin can send, with its purpose (the field-set hash covers this list).
	 *
	 * @return array<string, array<int, array{field: string, purpose: string}>>
	 */
	public static function field_set(): array {
		return array(
			'inventory' => array(
				array(
					'field'   => 'Plugin identifier (folder/file.php), name, installed version, type (standard, must-use, drop-in)',
					'purpose' => 'Identify components and their versions',
				),
				array(
					'field'   => 'Active and network-active state',
					'purpose' => 'Tell active code from code that is only installed',
				),
				array(
					'field'   => 'Update available (yes, no or unknown), offered version, update-check status, source and last-checked time',
					'purpose' => 'Find updates without treating missing data as up to date',
				),
				array(
					'field'   => 'Automatic updates on or off',
					'purpose' => 'Explain update posture',
				),
				array(
					'field'   => 'Theme stylesheet, name, version, active, parent or child, and the same update fields',
					'purpose' => 'The same checks for themes',
				),
			),
			'health'    => array(
				array(
					'field'   => 'WordPress, PHP and database server versions; database family; environment type; multisite yes or no',
					'purpose' => 'Context for every check (for example, debugging matters only in production)',
				),
				array(
					'field'   => 'For each documented check: its ID, result (good, recommended, critical, unknown), a minimal value (booleans, counts, versions), its source and time',
					'purpose' => 'Explain problems, why they matter and how to fix them',
				),
			),
		);
	}

	/**
	 * Data that is never sent.
	 *
	 * @return string[]
	 */
	public static function never_sent(): array {
		return array(
			__( 'Passwords, salts, access or refresh tokens, signing secrets, license keys or API keys', 'arvaseo-connector' ),
			__( 'Database credentials, file paths, configuration files, server or debug logs', 'arvaseo-connector' ),
			__( 'Usernames, email addresses, visitor data or form submissions', 'arvaseo-connector' ),
			__( 'Plugin or theme settings, uploaded files, and the content of your existing posts and pages (if you let Arva SEO update SEO titles and descriptions, only the previous title and description of the pages it updates)', 'arvaseo-connector' ),
		);
	}

	/**
	 * Hash of the field set the administrator approves.
	 */
	public static function field_set_hash(): string {
		return Ids::hash(
			array(
				'schema' => Config::CONSENT_SCHEMA_VERSION,
				'fields' => self::field_set(),
			)
		);
	}

	/**
	 * Record consent for the given purposes.
	 *
	 * @param string[] $purposes inventory, health.
	 */
	public static function consent( array $purposes ): void {
		$purposes = array_values( array_intersect( array( 'inventory', 'health' ), $purposes ) );
		if ( array() === $purposes ) {
			delete_option( self::OPTION );
			return;
		}
		update_option(
			self::OPTION,
			array(
				'schema_version'       => Config::CONSENT_SCHEMA_VERSION,
				'consented_at'         => Ids::iso(),
				'user_id'              => get_current_user_id(),
				'field_set_hash'       => self::field_set_hash(),
				'purposes'             => $purposes,
				'recipient_categories' => array( 'arvaseo' ),
			),
			false
		);
	}

	/**
	 * Withdraw consent.
	 */
	public static function withdraw(): void {
		delete_option( self::OPTION );
	}

	/**
	 * The current consent, if it still matches this version's field set.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function current(): ?array {
		$consent = get_option( self::OPTION );
		if ( ! is_array( $consent ) || ( $consent['schema_version'] ?? '' ) !== Config::CONSENT_SCHEMA_VERSION || ( $consent['field_set_hash'] ?? '' ) !== self::field_set_hash() ) {
			return null;
		}
		return $consent;
	}

	/**
	 * Whether a purpose is consented.
	 *
	 * @param string $purpose inventory|health.
	 */
	public static function allows( string $purpose ): bool {
		$consent = self::current();
		return null !== $consent && in_array( $purpose, (array) $consent['purposes'], true );
	}

	/**
	 * Consent as Arva SEO receives it (no user ID).
	 *
	 * @return array<string, mixed>|null
	 */
	public static function for_registration(): ?array {
		$consent = self::current();
		if ( null === $consent ) {
			return null;
		}
		return array(
			'schema_version'       => $consent['schema_version'],
			'consented_at'         => $consent['consented_at'],
			'field_set_hash'       => $consent['field_set_hash'],
			'purposes'             => array_values( $consent['purposes'] ),
			'recipient_categories' => array( 'arvaseo' ),
		);
	}

	/**
	 * Suggested text for Settings → Privacy.
	 */
	public static function add_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$content  = '<p>' . esc_html__( 'This site uses the Arva SEO Connector to work with Arva SEO, an SEO service used by the site owner or their agency.', 'arvaseo-connector' ) . '</p>';
		$content .= '<p>' . esc_html__( 'With an administrator’s consent, the connector sends Arva SEO technical information about this website: the installed plugins and themes with their versions and update state, and the results of WordPress health checks (software versions and configuration facts such as whether HTTPS is used). It does not send visitor information, comments, form submissions, user accounts or email addresses.', 'arvaseo-connector' ) . '</p>';
		$content .= '<p>' . esc_html__( 'Content written in Arva SEO may be sent to this site as posts or pages. If an administrator allows it, Arva SEO may also update the SEO titles and meta descriptions of published content; the previous title and description of each changed page are sent to Arva SEO so the change can be undone. The connector keeps a log of its own activity, which may record which administrator performed an action (by user ID), for the retention period set in the plugin.', 'arvaseo-connector' ) . '</p>';
		wp_add_privacy_policy_content( 'Arva SEO Connector', wp_kses_post( $content ) );
	}

	/**
	 * Register exporter and eraser for the Activity Log's references to users.
	 *
	 * @param array<string, mixed> $exporters Exporters.
	 * @return array<string, mixed>
	 */
	public static function register_exporter( array $exporters ): array {
		$exporters['arvaseo-connector'] = array(
			'exporter_friendly_name' => __( 'Arva SEO Connector activity log', 'arvaseo-connector' ),
			'callback'               => array( self::class, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Register the eraser.
	 *
	 * @param array<string, mixed> $erasers Erasers.
	 * @return array<string, mixed>
	 */
	public static function register_eraser( array $erasers ): array {
		$erasers['arvaseo-connector'] = array(
			'eraser_friendly_name' => __( 'Arva SEO Connector activity log', 'arvaseo-connector' ),
			'callback'             => array( self::class, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Export activity entries made by the user with this email.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page.
	 * @return array<string, mixed>
	 */
	public static function export( string $email, int $page = 1 ): array {
		global $wpdb;
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}
		$table = Schema::table( 'audit_log' );
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT id, created_at, event, message FROM %i WHERE user_id = %d ORDER BY id LIMIT 100 OFFSET %d', $table, $user->ID, ( $page - 1 ) * 100 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		$data  = array();
		foreach ( $rows as $row ) {
			$data[] = array(
				'group_id'    => 'arvaseo-connector',
				'group_label' => __( 'Arva SEO Connector activity', 'arvaseo-connector' ),
				'item_id'     => 'arvaseo-log-' . $row->id,
				'data'        => array(
					array(
						'name'  => __( 'Time (UTC)', 'arvaseo-connector' ),
						'value' => $row->created_at,
					),
					array(
						'name'  => __( 'Action', 'arvaseo-connector' ),
						'value' => $row->message,
					),
				),
			);
		}
		return array(
			'data' => $data,
			'done' => count( $rows ) < 100,
		);
	}

	/**
	 * Remove the user reference from activity entries (the entries stay, anonymised).
	 *
	 * @param string $email Email address.
	 * @return array<string, mixed>
	 */
	public static function erase( string $email ): array {
		global $wpdb;
		$user    = get_user_by( 'email', $email );
		$removed = 0;
		if ( $user ) {
			$removed = (int) $wpdb->update( Schema::table( 'audit_log' ), array( 'user_id' => null ), array( 'user_id' => $user->ID ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		}
		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
