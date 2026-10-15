<?php
/**
 * Local settings: the publishing ceiling and other choices that stay with WordPress.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector;

defined( 'ABSPATH' ) || exit;

/**
 * Stored in the arvaseo_connector_settings option (not autoloaded). Every value is sanitized
 * against a fixed list; nothing Arva SEO sends can change these.
 */
final class Settings {

	public const OPTION = 'arvaseo_connector_settings';

	public const MODES = array( 'draft_only', 'editorial', 'scheduling', 'full' );

	/** Post types that may receive content (allowlisted and tested). */
	public const SUPPORTED_POST_TYPES = array( 'post', 'page' );

	/** Which SEO plugin's fields to write: detect it, or a chosen one (when both are active). */
	public const SEO_PLUGINS = array( 'auto', 'yoast', 'rankmath' );

	/**
	 * Defaults: the least privileged choices.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'publishing_mode'          => 'draft_only',
			'downgrade_to_draft'       => false,
			'overwrite_local_edits'    => false,
			'default_author_id'        => 0,
			'allowed_post_types'       => array( 'post' ),
			'create_missing_terms'     => false,
			'media_import'             => false,
			'media_hosts'              => array(),
			'seo_plugin'               => 'auto',
			'seo_drafts'               => false,
			'seo_updates'              => false,
			'health_frequency'         => 'daily',
			'log_retention_days'       => 90,
			'delete_data_on_uninstall' => false,
		);
	}

	/**
	 * All settings, stored values over defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		// 1.0.0 had one choice for drafts, seo_adapter: none, yoast or rankmath.
		if ( isset( $stored['seo_adapter'] ) && ! isset( $stored['seo_plugin'] ) ) {
			$legacy               = in_array( $stored['seo_adapter'], array( 'yoast', 'rankmath' ), true ) ? $stored['seo_adapter'] : null;
			$stored['seo_plugin'] = $legacy ?? 'auto';
			$stored['seo_drafts'] = null !== $legacy;
		}
		return array_merge( self::defaults(), array_intersect_key( $stored, self::defaults() ) );
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting name.
	 * @return mixed
	 */
	public static function get( string $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	/**
	 * Sanitize input from the Settings page (Settings API callback).
	 *
	 * @param mixed $input Raw input.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ): array {
		$input = is_array( $input ) ? wp_unslash( $input ) : array();
		$old   = self::all();
		$clean = self::defaults();

		$clean['publishing_mode']          = in_array( $input['publishing_mode'] ?? '', self::MODES, true ) ? $input['publishing_mode'] : 'draft_only';
		$clean['downgrade_to_draft']       = ! empty( $input['downgrade_to_draft'] );
		$clean['overwrite_local_edits']    = ! empty( $input['overwrite_local_edits'] );
		$clean['create_missing_terms']     = ! empty( $input['create_missing_terms'] );
		$clean['media_import']             = ! empty( $input['media_import'] );
		$clean['delete_data_on_uninstall'] = ! empty( $input['delete_data_on_uninstall'] );
		$clean['seo_plugin']               = in_array( $input['seo_plugin'] ?? '', self::SEO_PLUGINS, true ) ? $input['seo_plugin'] : 'auto';
		$clean['seo_drafts']               = ! empty( $input['seo_drafts'] );
		$clean['seo_updates']              = ! empty( $input['seo_updates'] );
		$clean['health_frequency']         = in_array( $input['health_frequency'] ?? '', array( 'daily', 'twicedaily' ), true ) ? $input['health_frequency'] : 'daily';
		$clean['log_retention_days']       = in_array( (int) ( $input['log_retention_days'] ?? 90 ), array( 30, 90, 180, 365 ), true ) ? (int) $input['log_retention_days'] : 90;

		$types                       = array_values( array_intersect( self::SUPPORTED_POST_TYPES, (array) ( $input['allowed_post_types'] ?? array() ) ) );
		$clean['allowed_post_types'] = array() === $types ? array( 'post' ) : $types;

		$author                     = absint( $input['default_author_id'] ?? 0 );
		$clean['default_author_id'] = ( $author > 0 && user_can( $author, 'edit_posts' ) ) ? $author : 0;

		$hosts = array();
		foreach ( preg_split( '/[\s,]+/', (string) ( $input['media_hosts'] ?? '' ) ) as $host ) {
			$host = strtolower( trim( $host ) );
			if ( '' !== $host && preg_match( '/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host ) ) {
				$hosts[] = $host;
			}
		}
		$clean['media_hosts'] = array_slice( array_values( array_unique( $hosts ) ), 0, 20 );

		if ( $clean['publishing_mode'] !== $old['publishing_mode'] || $clean['downgrade_to_draft'] !== $old['downgrade_to_draft'] || $clean['allowed_post_types'] !== $old['allowed_post_types'] ) {
			// Arva SEO learns the new ceiling on the next registration (scheduled right away).
			wp_schedule_single_event( time(), 'arvaseo_connector_register' );
			Logging\AuditLogger::info(
				'settings.publishing_changed',
				__( 'Publishing settings changed.', 'arvaseo-connector' ),
				array(
					'from' => $old['publishing_mode'],
					'to'   => $clean['publishing_mode'],
				)
			);
		}
		if ( $clean['seo_updates'] !== $old['seo_updates'] || $clean['seo_plugin'] !== $old['seo_plugin'] ) {
			// Arva SEO learns whether it may update SEO titles and descriptions on the next registration.
			wp_schedule_single_event( time(), 'arvaseo_connector_register' );
			Logging\AuditLogger::info(
				'settings.seo_updates_changed',
				$clean['seo_updates'] ? __( 'Arva SEO may now update SEO titles and meta descriptions.', 'arvaseo-connector' ) : __( 'Arva SEO may no longer update SEO titles and meta descriptions.', 'arvaseo-connector' ),
				array(
					'allowed'    => $clean['seo_updates'],
					'seo_plugin' => $clean['seo_plugin'],
				)
			);
		}
		if ( $clean['health_frequency'] !== $old['health_frequency'] ) {
			wp_clear_scheduled_hook( 'arvaseo_connector_health' );
		}

		return $clean;
	}
}
