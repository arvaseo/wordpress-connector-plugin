<?php
/**
 * Creating and updating posts from Arva SEO deliveries.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Content;

use ArvaSEO\Connector\Api\ApiClient;
use ArvaSEO\Connector\Auth\TokenStore;
use ArvaSEO\Connector\Config;
use ArvaSEO\Connector\Install\Schema;
use ArvaSEO\Connector\Logging\AuditLogger;
use ArvaSEO\Connector\Settings;
use ArvaSEO\Connector\Support\Ids;

defined( 'ABSPATH' ) || exit;

/**
 * Processes one delivery, then reports exactly what happened:
 *
 * 1. Check the schema, sizes, the site and project, and the checksum.
 * 2. Idempotency: the same key and payload returns the stored result without doing anything
 *    again; the same key with a different payload is idempotency_conflict; a reused event ID is
 *    event_replay; an older revision than the one already applied is stale_revision.
 * 3. Apply the local publishing ceiling (refuse, or downgrade to draft only if allowed here).
 * 4. Map the author locally (never a user ID from Arva SEO) and check its capabilities.
 * 5. Sanitize; create or update through wp_insert_post()/wp_update_post(). An update makes a
 *    revision first, never overwrites local edits unless that option is on, and never takes a
 *    live post back to draft.
 * 6. Terms, featured image and SEO fields are applied after the post; their failure makes the
 *    result "partial" and is listed, without undoing the post.
 * 7. Read back the stored post (status, schedule) and acknowledge that.
 *
 * Content is never deleted remotely.
 */
final class ContentDeliveryService {

	/**
	 * Process a delivery and send its acknowledgement.
	 *
	 * @param array<string, mixed> $payload Delivery from Arva SEO.
	 * @return array<string, mixed> The acknowledgement.
	 */
	public static function handle( array $payload ): array {
		$ack = self::process( $payload );
		if ( null !== $ack ) {
			self::send_ack( $ack );
		}
		return $ack ?? array();
	}

	/**
	 * Process without sending (null when the payload can't even be identified).
	 *
	 * @param array<string, mixed> $payload Delivery.
	 * @return array<string, mixed>|null
	 */
	public static function process( array $payload ): ?array {
		global $wpdb;
		$size = strlen( (string) wp_json_encode( $payload ) );
		$id   = self::identity( $payload );
		if ( null === $id ) {
			AuditLogger::warning( 'content.unidentifiable', __( 'Ignored a delivery without valid identifiers.', 'arvaseo-connector' ) );
			return null;
		}
		$hash  = Ids::hash( $payload );
		$table = Schema::table( 'deliveries' );

		// Idempotency and replay.
		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE idempotency_key = %s', $table, $id['idempotency_key'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		if ( is_array( $existing ) ) {
			if ( hash_equals( $existing['payload_hash'], $hash ) && 'processed' === $existing['state'] ) {
				$cached = json_decode( (string) $existing['ack'], true );
				if ( is_array( $cached ) ) {
					return $cached;
				}
			}
			if ( ! hash_equals( $existing['payload_hash'], $hash ) ) {
				return self::failure( $id, 'idempotency_conflict', __( 'This delivery key was already used for different content.', 'arvaseo-connector' ), false );
			}
			if ( 'processing' === $existing['state'] && strtotime( $existing['received_at'] . ' UTC' ) > time() - 5 * MINUTE_IN_SECONDS ) {
				return null; // Another request is processing it right now.
			}
		} else {
			$replayed = $wpdb->get_var( $wpdb->prepare( 'SELECT idempotency_key FROM %i WHERE event_id = %s', $table, $id['event_id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
			if ( null !== $replayed ) {
				return self::failure( $id, 'event_replay', __( 'This event ID was already used.', 'arvaseo-connector' ), false );
			}
			$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- own table.
				$table,
				array(
					'delivery_id'         => $id['delivery_id'],
					'idempotency_key'     => $id['idempotency_key'],
					'event_id'            => $id['event_id'],
					'external_content_id' => $id['external_content_id'],
					'external_revision'   => $id['external_revision'],
					'payload_hash'        => $hash,
					'state'               => 'processing',
					'requested_status'    => $id['requested_status'],
					'received_at'         => Ids::mysql(),
				)
			);
			if ( false === $inserted ) {
				return null; // A concurrent request inserted it first.
			}
		}

		if ( $size > Config::MAX_DELIVERY_BYTES ) {
			return self::finish( $id, self::failure( $id, 'payload_too_large', __( 'The content is larger than this site accepts.', 'arvaseo-connector' ) ) );
		}
		$problem = self::validate( $payload );
		if ( null !== $problem ) {
			return self::finish( $id, self::failure( $id, 'validation_failed', $problem ) );
		}
		$post = $payload['post'];

		// Stale revisions.
		$map = self::mapping( $id['external_content_id'] );
		if ( null !== $map && (int) $map['external_revision'] >= $id['external_revision'] ) {
			return self::finish( $id, self::failure( $id, 'stale_revision', __( 'A newer version of this content was already applied.', 'arvaseo-connector' ) ) );
		}

		// Local publishing ceiling.
		$decision = PublishingPolicy::decide( $post['requested_status'] );
		if ( ! $decision['allowed'] ) {
			AuditLogger::warning(
				'content.status_refused',
				__( 'Refused content: the requested status is above this site’s publishing setting.', 'arvaseo-connector' ),
				array(
					'requested_status' => $post['requested_status'],
					'mode'             => Settings::get( 'publishing_mode' ),
				)
			);
			return self::finish( $id, self::failure( $id, 'arvaseo_status_not_allowed', __( 'This site’s Arva SEO Connector settings don’t allow that publishing status.', 'arvaseo-connector' ) ) );
		}
		$status = (string) $decision['applied'];
		if ( ! in_array( $post['post_type'], (array) Settings::get( 'allowed_post_types' ), true ) || ! post_type_exists( $post['post_type'] ) ) {
			return self::finish( $id, self::failure( $id, 'unsupported_post_type', __( 'This site doesn’t accept that content type from Arva SEO.', 'arvaseo-connector' ) ) );
		}
		$author = (int) apply_filters( 'arvaseo_connector_author_for_key', (int) Settings::get( 'default_author_id' ), $post['author_key'] );
		if ( ! PublishingPolicy::author_can( $author, $post['post_type'], $status ) ) {
			return self::finish( $id, self::failure( $id, 'author_not_allowed', __( 'Choose a default author who can do this in Arva SEO → Settings.', 'arvaseo-connector' ) ) );
		}

		// Schedule.
		$date_gmt = null;
		if ( 'future' === $status ) {
			$when = strtotime( (string) $post['scheduled_at_utc'] );
			if ( false === $when || $when < time() + MINUTE_IN_SECONDS ) {
				return self::finish( $id, self::failure( $id, 'invalid_schedule', __( 'The publication time has already passed. Choose a new time in Arva SEO.', 'arvaseo-connector' ) ) );
			}
			$date_gmt = gmdate( 'Y-m-d H:i:s', $when );
		}

		$warnings = array();
		$postarr  = array(
			'post_type'    => $post['post_type'],
			'post_title'   => ContentSanitizer::title( $post['title'] ),
			'post_content' => ContentSanitizer::content( $post['content'], $warnings ),
			'post_excerpt' => ContentSanitizer::excerpt( $post['excerpt'] ),
			'post_status'  => $status,
			'post_author'  => $author,
		);
		if ( is_string( $post['slug'] ) && '' !== $post['slug'] ) {
			$postarr['post_name'] = sanitize_title( $post['slug'] );
		}
		if ( null !== $date_gmt ) {
			$postarr['post_date_gmt'] = $date_gmt;
			$postarr['post_date']     = get_date_from_gmt( $date_gmt );
			$postarr['edit_date']     = true;
		}

		// Updates of content delivered before.
		$revision_created = false;
		$post_id          = 0;
		if ( null !== $map ) {
			$current = get_post( (int) $map['wordpress_post_id'] );
			if ( ! $current || 'trash' === $current->post_status ) {
				return self::finish( $id, self::conflict( $id, 'mapped_post_missing', __( 'The post this content was sent to before is missing or in the trash. Restore it, or review it in WordPress.', 'arvaseo-connector' ), $current ) );
			}
			if ( ! $post['update_existing'] ) {
				return self::finish( $id, self::conflict( $id, 'already_synced', __( 'This content was already sent to this site. Send it as an update.', 'arvaseo-connector' ), $current ) );
			}
			if ( self::local_hash( $current ) !== $map['last_local_hash'] && ! Settings::get( 'overwrite_local_edits' ) ) {
				AuditLogger::warning( 'content.local_edit_conflict', __( 'Kept the WordPress version: the post was edited here since Arva SEO last sent it.', 'arvaseo-connector' ), array( 'post_id' => $current->ID ) );
				return self::finish( $id, self::conflict( $id, 'local_edit_conflict', __( 'The post was edited in WordPress since Arva SEO last sent it, so it wasn’t overwritten.', 'arvaseo-connector' ), $current ) );
			}
			if ( in_array( $current->post_status, array( 'publish', 'future' ), true ) && ! in_array( $status, array( 'publish', 'future' ), true ) ) {
				return self::finish( $id, self::conflict( $id, 'live_post_status_change', __( 'This post is already live or scheduled; an update must keep it published (Full publishing) rather than turn it back into a draft.', 'arvaseo-connector' ), $current ) );
			}
			if ( wp_revisions_enabled( $current ) ) {
				$revision_created = (bool) wp_save_post_revision( $current->ID );
			}
			$postarr['ID'] = $current->ID;
			$post_id       = wp_update_post( wp_slash( $postarr ), true );
		} else {
			$post_id = wp_insert_post( wp_slash( $postarr ), true );
		}
		if ( is_wp_error( $post_id ) ) {
			AuditLogger::error( 'content.insert_failed', __( 'WordPress couldn’t save delivered content.', 'arvaseo-connector' ), array( 'code' => $post_id->get_error_code() ) );
			return self::finish( $id, self::failure( $id, 'insert_failed', __( 'WordPress couldn’t save the post.', 'arvaseo-connector' ) ) );
		}
		$post_id = (int) $post_id;

		// Terms, image and SEO: independent of the post; failures are reported, not rolled back.
		$terms = self::terms( $post_id, $post['post_type'], $post['categories'], $post['tags'], $warnings );
		$image = 'none';
		if ( is_array( $post['featured_image'] ) ) {
			if ( post_type_supports( $post['post_type'], 'thumbnail' ) ) {
				$imported = MediaImporter::import( (string) $post['featured_image']['url'], (string) ( $post['featured_image']['alt'] ?? '' ), $post_id );
				$image    = $imported['status'];
				if ( 'failed' === $image || 'skipped' === $image ) {
					$warnings[] = 'featured_image_' . ( $imported['reason'] ?? $image );
				}
			} else {
				$image      = 'skipped';
				$warnings[] = 'featured_image_unsupported';
			}
		}
		$seo = SeoAdapters::apply( $post_id, is_array( $post['seo'] ) ? $post['seo'] : null );

		// Read back what WordPress stored.
		clean_post_cache( $post_id );
		$saved  = get_post( $post_id );
		$actual = $saved ? $saved->post_status : null;
		if ( 'future' === $status && ( 'future' !== $actual || $saved->post_date_gmt !== $date_gmt ) ) {
			$warnings[] = 'schedule_not_confirmed';
		}
		self::save_mapping( $id, $post_id, $payload['content_checksum'], $saved, $date_gmt );

		$warnings = array_values( array_unique( $warnings ) );
		$partial  = array_intersect( $warnings, array( 'schedule_not_confirmed', 'terms_skipped' ) ) !== array() || in_array( $image, array( 'failed' ), true ) || array_filter( $warnings, static fn ( $w ) => str_starts_with( $w, 'featured_image_' ) ) !== array();
		$ack      = self::ack(
			$id,
			array(
				'outcome'          => $partial ? 'partial' : 'success',
				'applied_status'   => $status,
				'downgraded'       => $decision['downgraded'],
				'downgrade_reason' => $decision['downgraded'] ? 'local_policy_downgrade' : null,
				'post_id'          => $post_id,
				'post_status'      => $actual,
				'permalink'        => 'publish' === $actual ? ( get_permalink( $post_id ) ?: null ) : null,
				'edit_url'         => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
				'scheduled_at_utc' => 'future' === $actual && $saved ? Ids::iso( (int) strtotime( $saved->post_date_gmt . ' UTC' ) ) : null,
				'warnings'         => array_slice( $warnings, 0, 20 ),
				'details'          => array(
					'categories_applied' => $terms['categories'],
					'tags_applied'       => $terms['tags'],
					'terms_skipped'      => $terms['skipped'],
					'featured_image'     => $image,
					'seo'                => $seo,
					'revision_created'   => $revision_created,
				),
			)
		);
		AuditLogger::info(
			'content.applied',
			$decision['downgraded'] ? __( 'Content from Arva SEO saved as a draft (downgraded by local settings).', 'arvaseo-connector' ) : __( 'Content from Arva SEO saved.', 'arvaseo-connector' ),
			array(
				'post_id'           => $post_id,
				'requested_status'  => $post['requested_status'],
				'applied_status'    => $status,
				'external_revision' => $id['external_revision'],
				'warnings'          => $warnings,
			)
		);
		return self::finish( $id, $ack, $post_id );
	}

	/**
	 * Send an acknowledgement; on failure it stays in the outbox for the next poll.
	 *
	 * @param array<string, mixed> $ack Acknowledgement.
	 */
	public static function send_ack( array $ack ): bool {
		global $wpdb;
		$table    = Schema::table( 'deliveries' );
		$response = ApiClient::instance()->site( 'POST', '/deliveries/' . rawurlencode( (string) $ack['delivery_id'] ) . '/acknowledgement', $ack );
		$sent     = $response->ok() || in_array( $response->status, array( 404, 409, 422 ), true ); // Settled (or never retryable).
		$row      = $wpdb->get_row( $wpdb->prepare( 'SELECT ack_attempts FROM %i WHERE delivery_id = %s', $table, $ack['delivery_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		$attempts = (int) ( $row['ack_attempts'] ?? 0 ) + 1;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
			$table,
			array(
				'ack_sent'     => $sent ? 1 : 0,
				'ack_attempts' => $attempts,
				'next_ack_at'  => $sent ? null : Ids::mysql( time() + min( HOUR_IN_SECONDS, 60 * ( 2 ** min( 6, $attempts ) ) ) + wp_rand( 0, 30 ) ),
			),
			array( 'delivery_id' => $ack['delivery_id'] )
		);
		if ( ! $response->ok() ) {
			AuditLogger::warning(
				'content.ack_failed',
				__( 'Couldn’t report a delivery result to Arva SEO; will retry.', 'arvaseo-connector' ),
				array(
					'delivery_id' => $ack['delivery_id'],
					'http_status' => $response->status,
					'code'        => $response->error_code,
				)
			);
		}
		return $response->ok();
	}

	/**
	 * Retry acknowledgements that didn't get through.
	 */
	public static function flush_outbox(): int {
		global $wpdb;
		$table = Schema::table( 'deliveries' );
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT ack FROM %i WHERE ack_sent = 0 AND ack IS NOT NULL AND ack_attempts < 20 AND (next_ack_at IS NULL OR next_ack_at <= %s) ORDER BY id LIMIT 10', $table, Ids::mysql() ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		$sent  = 0;
		foreach ( $rows as $row ) {
			$ack = json_decode( (string) $row['ack'], true );
			if ( is_array( $ack ) && self::send_ack( $ack ) ) {
				++$sent;
			}
		}
		return $sent;
	}

	/**
	 * The identifiers needed to record and acknowledge anything.
	 *
	 * @param array<string, mixed> $p Payload.
	 * @return array<string, mixed>|null
	 */
	private static function identity( array $p ): ?array {
		$ok = isset( $p['delivery_id'], $p['idempotency_key'], $p['event_id'], $p['external_content_id'], $p['external_revision'], $p['post']['requested_status'] )
			&& is_string( $p['delivery_id'] ) && preg_match( '/^[0-9A-HJKMNP-TV-Z]{26}$/i', $p['delivery_id'] )
			&& is_string( $p['idempotency_key'] ) && preg_match( '/^[A-Za-z0-9:_\-]{1,80}$/', $p['idempotency_key'] )
			&& Ids::is_uuid( $p['event_id'] )
			&& is_string( $p['external_content_id'] ) && preg_match( '/^[0-9A-HJKMNP-TV-Z]{26}$/i', $p['external_content_id'] )
			&& is_int( $p['external_revision'] ) && $p['external_revision'] > 0
			&& in_array( $p['post']['requested_status'], array( 'draft', 'pending', 'future', 'publish' ), true );
		if ( ! $ok ) {
			return null;
		}
		return array(
			'delivery_id'         => $p['delivery_id'],
			'idempotency_key'     => $p['idempotency_key'],
			'event_id'            => $p['event_id'],
			'external_content_id' => $p['external_content_id'],
			'external_revision'   => $p['external_revision'],
			'requested_status'    => $p['post']['requested_status'],
		);
	}

	/**
	 * Check the whole payload; a message when something is wrong.
	 *
	 * @param array<string, mixed> $p Payload.
	 */
	private static function validate( array $p ): ?string {
		$top = array( 'schema_version', 'event_id', 'idempotency_key', 'delivery_id', 'external_content_id', 'external_revision', 'site_registration_id', 'workspace_id', 'project_id', 'post', 'content_checksum', 'created_at', 'updated_at' );
		if ( array_diff( array_keys( $p ), $top ) !== array() || array_diff( $top, array_keys( $p ) ) !== array() ) {
			return __( 'The delivery has undocumented or missing fields.', 'arvaseo-connector' );
		}
		if ( '1.0' !== $p['schema_version'] ) {
			return __( 'This delivery’s schema version isn’t supported. Update the Arva SEO Connector plugin.', 'arvaseo-connector' );
		}
		$connection = TokenStore::connection();
		if ( TokenStore::site_id() !== $p['site_registration_id'] || ( $connection['project']['id'] ?? null ) !== $p['project_id'] || ( $connection['workspace']['id'] ?? null ) !== $p['workspace_id'] ) {
			return __( 'This delivery is for another site, website or workspace.', 'arvaseo-connector' );
		}
		$post = $p['post'];
		$keys = array( 'post_type', 'title', 'content', 'content_format', 'excerpt', 'slug', 'requested_status', 'scheduled_at_utc', 'author_key', 'categories', 'tags', 'featured_image', 'seo', 'update_existing' );
		if ( ! is_array( $post ) || array_diff( array_keys( $post ), $keys ) !== array() || array_diff( $keys, array_keys( $post ) ) !== array() ) {
			return __( 'The post has undocumented or missing fields.', 'arvaseo-connector' );
		}
		$checks = array(
			is_string( $post['post_type'] ) && preg_match( '/^[a-z0-9_-]{1,20}$/', $post['post_type'] ),
			is_string( $post['title'] ) && '' !== trim( $post['title'] ) && mb_strlen( $post['title'] ) <= 200,
			is_string( $post['content'] ) && strlen( $post['content'] ) <= Config::MAX_DELIVERY_BYTES,
			'block_markup' === $post['content_format'],
			null === $post['excerpt'] || ( is_string( $post['excerpt'] ) && mb_strlen( $post['excerpt'] ) <= 300 ),
			null === $post['slug'] || ( is_string( $post['slug'] ) && preg_match( '/^[a-z0-9-]{1,200}$/', $post['slug'] ) ),
			null === $post['scheduled_at_utc'] || ( is_string( $post['scheduled_at_utc'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $post['scheduled_at_utc'] ) ),
			'future' !== $post['requested_status'] || is_string( $post['scheduled_at_utc'] ),
			is_string( $post['author_key'] ) && preg_match( '/^[a-z0-9_]{1,40}$/', $post['author_key'] ),
			self::term_list( $post['categories'] ) && self::term_list( $post['tags'] ),
			null === $post['featured_image'] || ( is_array( $post['featured_image'] ) && array_keys( $post['featured_image'] ) === array( 'url', 'alt' ) && is_string( $post['featured_image']['url'] ) && strlen( $post['featured_image']['url'] ) <= 2048 && is_string( $post['featured_image']['alt'] ) ),
			null === $post['seo'] || ( is_array( $post['seo'] ) && array_diff( array_keys( $post['seo'] ), array( 'title', 'description' ) ) === array() ),
			is_bool( $post['update_existing'] ),
			is_string( $p['content_checksum'] ) && preg_match( '/^[a-f0-9]{64}$/', $p['content_checksum'] ),
		);
		if ( in_array( false, array_map( 'boolval', $checks ), true ) ) {
			return __( 'A field in the delivery isn’t valid.', 'arvaseo-connector' );
		}
		$expected = hash( 'sha256', implode( "\n", array( $post['title'], $post['content'], (string) $post['excerpt'], (string) $post['slug'] ) ) );
		if ( ! hash_equals( $expected, $p['content_checksum'] ) ) {
			return __( 'The content checksum doesn’t match.', 'arvaseo-connector' );
		}
		return null;
	}

	/**
	 * A list of up to 10 short term names.
	 *
	 * @param mixed $names Value.
	 */
	private static function term_list( $names ): bool {
		if ( ! is_array( $names ) || count( $names ) > 10 || ! array_is_list( $names ) ) {
			return false;
		}
		foreach ( $names as $name ) {
			if ( ! is_string( $name ) || '' === trim( $name ) || mb_strlen( $name ) > 60 ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Categories and tags by name; missing terms are created only if allowed here.
	 *
	 * @param int      $post_id    Post.
	 * @param string   $post_type  Post type.
	 * @param string[] $categories Category names.
	 * @param string[] $tags       Tag names.
	 * @param string[] $warnings   Warnings.
	 * @return array{categories: int, tags: int, skipped: int}
	 */
	private static function terms( int $post_id, string $post_type, array $categories, array $tags, array &$warnings ): array {
		$out    = array(
			'categories' => 0,
			'tags'       => 0,
			'skipped'    => 0,
		);
		$create = (bool) Settings::get( 'create_missing_terms' );
		foreach ( array(
			'category' => array( $categories, 'categories' ),
			'post_tag' => array( $tags, 'tags' ),
		) as $taxonomy => list( $names, $key ) ) {
			if ( array() === $names ) {
				continue;
			}
			if ( ! is_object_in_taxonomy( $post_type, $taxonomy ) ) {
				$out['skipped'] += count( $names );
				continue;
			}
			$ids = array();
			foreach ( $names as $name ) {
				$name = sanitize_text_field( $name );
				$term = term_exists( $name, $taxonomy );
				if ( ! $term && $create ) {
					$term = wp_insert_term( $name, $taxonomy );
				}
				if ( is_array( $term ) ) { // Errors from wp_insert_term() are WP_Error objects, not arrays.
					$ids[] = (int) $term['term_id'];
				} else {
					++$out['skipped'];
				}
			}
			if ( array() !== $ids ) {
				$set = wp_set_post_terms( $post_id, $ids, $taxonomy );
				if ( ! is_array( $set ) ) {
					$out['skipped'] += count( $ids );
				} else {
					$out[ $key ] = count( $ids );
				}
			}
		}
		if ( $out['skipped'] > 0 ) {
			$warnings[] = 'terms_skipped';
		}
		return $out;
	}

	/**
	 * The mapping for a content ID.
	 *
	 * @param string $external_id Arva SEO content ID.
	 * @return array<string, mixed>|null
	 */
	public static function mapping( string $external_id ): ?array {
		global $wpdb;
		$table = Schema::table( 'content_map' );
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE external_content_id = %s', $table, $external_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Hash of what WordPress stored, to notice later local edits.
	 *
	 * @param \WP_Post $post Post.
	 */
	public static function local_hash( \WP_Post $post ): string {
		return hash( 'sha256', implode( "\n", array( $post->post_title, $post->post_content, $post->post_excerpt ) ) );
	}

	/**
	 * Record the mapping after a successful save.
	 *
	 * @param array<string, mixed> $id       Identity.
	 * @param int                  $post_id  Post.
	 * @param string               $checksum Content checksum.
	 * @param \WP_Post|null        $saved    Stored post.
	 * @param string|null          $date_gmt Schedule.
	 */
	private static function save_mapping( array $id, int $post_id, string $checksum, ?\WP_Post $saved, ?string $date_gmt ): void {
		global $wpdb;
		$table = Schema::table( 'content_map' );
		$data  = array(
			'external_content_id' => $id['external_content_id'],
			'wordpress_post_id'   => $post_id,
			'external_revision'   => $id['external_revision'],
			'last_synced_hash'    => $checksum,
			'last_local_hash'     => $saved ? self::local_hash( $saved ) : null,
			'last_sync_status'    => $saved ? $saved->post_status : null,
			'scheduled_at_utc'    => $date_gmt,
			'updated_at'          => Ids::mysql(),
		);
		if ( null === self::mapping( $id['external_content_id'] ) ) {
			$wpdb->insert( $table, array_merge( $data, array( 'created_at' => Ids::mysql() ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- own table.
		} else {
			$wpdb->update( $table, $data, array( 'external_content_id' => $id['external_content_id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		}
	}

	/**
	 * Store the result as the idempotent answer for this delivery.
	 *
	 * @param array<string, mixed> $id      Identity.
	 * @param array<string, mixed> $ack     Acknowledgement.
	 * @param int|null             $post_id Post.
	 * @return array<string, mixed>
	 */
	private static function finish( array $id, array $ack, ?int $post_id = null ): array {
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
			Schema::table( 'deliveries' ),
			array(
				'state'             => 'processed',
				'outcome'           => $ack['outcome'],
				'applied_status'    => $ack['applied_status'],
				'wordpress_post_id' => $post_id ?? $ack['post_id'],
				'ack'               => wp_json_encode( $ack ),
				'ack_sent'          => 0,
				'error_code'        => $ack['error']['code'] ?? null,
				'processed_at'      => Ids::mysql(),
			),
			array( 'idempotency_key' => $id['idempotency_key'] )
		);
		return $ack;
	}

	/**
	 * A failed result.
	 *
	 * @param array<string, mixed> $id      Identity.
	 * @param string               $code    Error code.
	 * @param string               $message Message.
	 * @param bool                 $log     Write to the activity log.
	 * @return array<string, mixed>
	 */
	private static function failure( array $id, string $code, string $message, bool $log = true ): array {
		if ( $log ) {
			AuditLogger::warning(
				'content.failed',
				$message,
				array(
					'code'        => $code,
					'delivery_id' => $id['delivery_id'],
				)
			);
		}
		return self::ack(
			$id,
			array(
				'outcome' => 'failed',
				'error'   => array(
					'code'    => $code,
					'message' => $message,
				),
			)
		);
	}

	/**
	 * A conflict: WordPress kept its version.
	 *
	 * @param array<string, mixed> $id      Identity.
	 * @param string               $code    Code.
	 * @param string               $message Message.
	 * @param \WP_Post|null        $current Current post.
	 * @return array<string, mixed>
	 */
	private static function conflict( array $id, string $code, string $message, $current ): array {
		return self::ack(
			$id,
			array(
				'outcome'     => 'conflict',
				'post_id'     => $current instanceof \WP_Post ? $current->ID : null,
				'post_status' => $current instanceof \WP_Post ? $current->post_status : null,
				'edit_url'    => $current instanceof \WP_Post ? admin_url( 'post.php?post=' . $current->ID . '&action=edit' ) : null,
				'error'       => array(
					'code'    => $code,
					'message' => $message,
				),
			)
		);
	}

	/**
	 * The full acknowledgement, every documented field present.
	 *
	 * @param array<string, mixed> $id     Identity.
	 * @param array<string, mixed> $fields Fields.
	 * @return array<string, mixed>
	 */
	private static function ack( array $id, array $fields ): array {
		return array_merge(
			array(
				'schema_version'      => '1.0',
				'delivery_id'         => $id['delivery_id'],
				'idempotency_key'     => $id['idempotency_key'],
				'event_id'            => $id['event_id'],
				'external_content_id' => $id['external_content_id'],
				'external_revision'   => $id['external_revision'],
				'outcome'             => 'failed',
				'requested_status'    => $id['requested_status'],
				'applied_status'      => null,
				'downgraded'          => false,
				'downgrade_reason'    => null,
				'post_id'             => null,
				'post_status'         => null,
				'permalink'           => null,
				'edit_url'            => null,
				'scheduled_at_utc'    => null,
				'processed_at'        => Ids::iso(),
				'error'               => null,
				'warnings'            => array(),
				'details'             => array(
					'categories_applied' => 0,
					'tags_applied'       => 0,
					'terms_skipped'      => 0,
					'featured_image'     => 'none',
					'seo'                => 'none',
					'revision_created'   => false,
				),
			),
			$fields
		);
	}
}
