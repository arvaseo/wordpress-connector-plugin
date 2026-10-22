<?php
/**
 * The plugin's own tables, created and upgraded with dbDelta() and a stored schema version.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Install;

defined( 'ABSPATH' ) || exit;

/**
 * Versioned database schema (per site: tables use the site's prefix on Multisite).
 *
 * Tables:
 * - arvaseo_content_map: one Arva SEO content ID to at most one post, with the last revision,
 *   checksum and the hash of what was stored (to detect local edits).
 * - arvaseo_deliveries: each delivery's idempotency record, the acknowledgement sent back (no
 *   content), and whether it still has to be sent (the acknowledgement outbox).
 * - arvaseo_events: IDs of signed events already accepted (replay protection), kept 7 days.
 * - arvaseo_audit_log: redacted activity history.
 * - arvaseo_seo_updates: each SEO title and description update from Arva SEO, what was there
 *   before (so it can be undone), and the result still to be sent back (its outbox). Since 1.1.0.
 * - arvaseo_file_prints: the security scan's fingerprint (SHA-256, size, modified time) of each
 *   theme, must-use plugin, drop-in and non-WordPress.org plugin file, and the last copy of the
 *   riskiest ones (a theme's functions.php, header.php, footer.php; must-use plugins). Since 2.7.0.
 */
final class Schema {

	public const VERSION = '3';

	public const OPTION = 'arvaseo_connector_db_version';

	/**
	 * Table name with this site's prefix.
	 *
	 * @param string $name Short name (content_map, deliveries, events, audit_log).
	 */
	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'arvaseo_' . $name;
	}

	/**
	 * Create or upgrade the tables for the current site when the stored version differs.
	 */
	public static function migrate(): void {
		if ( get_option( self::OPTION ) === self::VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$map     = self::table( 'content_map' );
		$del     = self::table( 'deliveries' );
		$events  = self::table( 'events' );
		$log     = self::table( 'audit_log' );
		$seo     = self::table( 'seo_updates' );
		$prints  = self::table( 'file_prints' );

		dbDelta(
			"CREATE TABLE {$map} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  external_content_id varchar(40) NOT NULL,
  wordpress_post_id bigint(20) unsigned NOT NULL,
  external_revision int(10) unsigned NOT NULL DEFAULT 0,
  last_synced_hash char(64) DEFAULT NULL,
  last_local_hash char(64) DEFAULT NULL,
  last_sync_status varchar(20) DEFAULT NULL,
  scheduled_at_utc datetime DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY external_content_id (external_content_id),
  KEY wordpress_post_id (wordpress_post_id)
) {$charset};
CREATE TABLE {$del} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  delivery_id varchar(40) NOT NULL,
  idempotency_key varchar(80) NOT NULL,
  event_id varchar(36) NOT NULL,
  external_content_id varchar(40) NOT NULL,
  external_revision int(10) unsigned NOT NULL DEFAULT 0,
  payload_hash char(64) NOT NULL,
  state varchar(20) NOT NULL,
  outcome varchar(20) DEFAULT NULL,
  requested_status varchar(20) DEFAULT NULL,
  applied_status varchar(20) DEFAULT NULL,
  wordpress_post_id bigint(20) unsigned DEFAULT NULL,
  ack longtext DEFAULT NULL,
  ack_sent tinyint(1) NOT NULL DEFAULT 0,
  ack_attempts smallint(5) unsigned NOT NULL DEFAULT 0,
  next_ack_at datetime DEFAULT NULL,
  error_code varchar(64) DEFAULT NULL,
  received_at datetime NOT NULL,
  processed_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY idempotency_key (idempotency_key),
  UNIQUE KEY delivery_id (delivery_id),
  KEY event_id (event_id),
  KEY ack_outbox (ack_sent,next_ack_at),
  KEY received_at (received_at)
) {$charset};
CREATE TABLE {$events} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  event_id varchar(36) NOT NULL,
  event_type varchar(40) NOT NULL,
  payload_hash char(64) NOT NULL,
  received_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY event_id (event_id),
  KEY received_at (received_at)
) {$charset};
CREATE TABLE {$log} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  level varchar(10) NOT NULL,
  event varchar(64) NOT NULL,
  message varchar(255) NOT NULL,
  context text DEFAULT NULL,
  user_id bigint(20) unsigned DEFAULT NULL,
  request_id varchar(36) DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY event (event),
  KEY user_id (user_id)
) {$charset};
CREATE TABLE {$seo} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  update_id varchar(40) NOT NULL,
  event_id varchar(36) NOT NULL,
  payload_hash char(64) NOT NULL,
  state varchar(20) NOT NULL,
  outcome varchar(20) DEFAULT NULL,
  target varchar(10) DEFAULT NULL,
  wordpress_post_id bigint(20) unsigned DEFAULT NULL,
  seo_plugin varchar(20) DEFAULT NULL,
  result longtext DEFAULT NULL,
  result_sent tinyint(1) NOT NULL DEFAULT 0,
  result_attempts smallint(5) unsigned NOT NULL DEFAULT 0,
  next_result_at datetime DEFAULT NULL,
  error_code varchar(64) DEFAULT NULL,
  received_at datetime NOT NULL,
  processed_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY update_id (update_id),
  KEY result_outbox (result_sent,next_result_at),
  KEY received_at (received_at)
) {$charset};
CREATE TABLE {$prints} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  path_hash char(32) NOT NULL,
  path varchar(1000) NOT NULL,
  component varchar(200) NOT NULL,
  sha256 char(64) NOT NULL,
  size bigint(20) unsigned NOT NULL DEFAULT 0,
  mtime bigint(20) unsigned NOT NULL DEFAULT 0,
  copy mediumtext DEFAULT NULL,
  recorded_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY path_hash (path_hash),
  KEY component (component(191))
) {$charset};"
		);
		update_option( self::OPTION, self::VERSION, true ); // Autoloaded: read on every request.
	}

	/**
	 * Drop the tables (uninstall with data removal only).
	 */
	public static function drop(): void {
		global $wpdb;
		foreach ( array( 'content_map', 'deliveries', 'events', 'audit_log', 'seo_updates', 'file_prints' ) as $name ) {
			$table = self::table( $name );
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- table name built from $wpdb->prefix and a fixed list.
		}
		delete_option( self::OPTION );
	}
}
