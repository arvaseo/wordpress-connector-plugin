<?php
/**
 * Updating SEO titles and meta descriptions of existing content from Arva SEO.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Content;

use ArvaSEO\Connector\Api\ApiClient;
use ArvaSEO\Connector\Auth\TokenStore;
use ArvaSEO\Connector\Install\Schema;
use ArvaSEO\Connector\Logging\AuditLogger;
use ArvaSEO\Connector\Settings;
use ArvaSEO\Connector\Support\Ids;

defined( 'ABSPATH' ) || exit;

/**
 * Processes one SEO update, then reports exactly what happened:
 *
 * 1. Check the schema, the site and project, and the address (it must be on this site).
 * 2. Idempotency: the same update ID and payload returns the stored result without doing anything
 *    again; the same ID with a different payload is idempotency_conflict; a reused event ID is
 *    event_replay.
 * 3. Only when an administrator allowed it (Settings → "Let Arva SEO update SEO titles and
 *    descriptions"), and only into the fields of the SEO plugin in use (Yoast SEO or Rank Math).
 * 4. Find what the address is: a published post, page or product (any public post type except
 *    media), or the homepage. Category, tag and other archives aren't supported.
 * 5. Keep the previous values, write the new ones, read them back, clear the page caches.
 * 6. Report the result, with the previous values so Arva SEO can undo the change. An undo is
 *    simply another update with those values.
 *
 * Only the SEO title and meta description are ever changed; the post itself is never saved, so
 * its content, dates and revisions stay as they are.
 */
final class SeoUpdateService {

	public const MAX_TITLE = 200;

	public const MAX_DESCRIPTION = 400;

	/**
	 * Process an update and send its result.
	 *
	 * @param array<string, mixed> $payload Update from Arva SEO.
	 * @return array<string, mixed> The result.
	 */
	public static function handle( array $payload ): array {
		$result = self::process( $payload );
		if ( null !== $result ) {
			self::send_result( $result );
		}
		return $result ?? array();
	}

	/**
	 * Process without sending (null when the payload can't even be identified).
	 *
	 * @param array<string, mixed> $payload Update.
	 * @return array<string, mixed>|null
	 */
	public static function process( array $payload ): ?array {
		global $wpdb;
		$id = self::identity( $payload );
		if ( null === $id ) {
			AuditLogger::warning( 'seo.unidentifiable', __( 'Ignored an SEO update without valid identifiers.', 'arvaseo-connector' ) );
			return null;
		}
		$hash  = Ids::hash( $payload );
		$table = Schema::table( 'seo_updates' );

		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE update_id = %s', $table, $id['update_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		if ( is_array( $existing ) ) {
			if ( hash_equals( $existing['payload_hash'], $hash ) && 'processed' === $existing['state'] ) {
				$cached = json_decode( (string) $existing['result'], true );
				if ( is_array( $cached ) ) {
					return $cached;
				}
			}
			if ( ! hash_equals( $existing['payload_hash'], $hash ) ) {
				return self::failure( $id, 'idempotency_conflict', __( 'This update ID was already used for different values.', 'arvaseo-connector' ), false );
			}
			if ( 'processing' === $existing['state'] && strtotime( $existing['received_at'] . ' UTC' ) > time() - 5 * MINUTE_IN_SECONDS ) {
				return null; // Another request is processing it right now.
			}
		} else {
			$replayed = $wpdb->get_var( $wpdb->prepare( 'SELECT update_id FROM %i WHERE event_id = %s', $table, $id['event_id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
			if ( null !== $replayed ) {
				return self::failure( $id, 'event_replay', __( 'This event ID was already used.', 'arvaseo-connector' ), false );
			}
			$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- own table.
				$table,
				array(
					'update_id'    => $id['update_id'],
					'event_id'     => $id['event_id'],
					'payload_hash' => $hash,
					'state'        => 'processing',
					'received_at'  => Ids::mysql(),
				)
			);
			if ( false === $inserted ) {
				return null; // A concurrent request inserted it first.
			}
		}

		$problem = self::validate( $payload );
		if ( null !== $problem ) {
			return self::finish( $id, self::failure( $id, $problem[0], $problem[1] ) );
		}
		if ( ! Settings::get( 'seo_updates' ) ) {
			return self::finish( $id, self::failure( $id, 'seo_updates_not_allowed', __( 'An administrator hasn’t allowed Arva SEO to update SEO titles and descriptions on this site (Arva SEO → Settings in WordPress).', 'arvaseo-connector' ) ) );
		}
		$plugin = SeoPlugins::resolve();
		if ( 'none' === $plugin ) {
			return self::finish( $id, self::failure( $id, 'no_seo_plugin', __( 'Neither Yoast SEO nor Rank Math is active on this site (or the one chosen in Arva SEO → Settings isn’t).', 'arvaseo-connector' ) ) );
		}
		if ( 'multiple' === $plugin ) {
			return self::finish( $id, self::failure( $id, 'multiple_seo_plugins', __( 'Both Yoast SEO and Rank Math are active. Choose which one Arva SEO writes to in Arva SEO → Settings in WordPress.', 'arvaseo-connector' ) ) );
		}

		$target = self::target( (string) $payload['url'] );
		if ( is_string( $target ) ) {
			$messages = array(
				'page_not_found'        => __( 'WordPress has no post, page or product at this address. Category, tag and other archive pages can’t be updated yet.', 'arvaseo-connector' ),
				'not_published'         => __( 'This post isn’t published, so Arva SEO doesn’t change it.', 'arvaseo-connector' ),
				'unsupported_post_type' => __( 'This content type can’t be updated from Arva SEO.', 'arvaseo-connector' ),
			);
			return self::finish( $id, self::failure( $id, $target, $messages[ $target ] ) );
		}

		$title       = is_string( $payload['title'] ) ? self::clean( $payload['title'], self::MAX_TITLE ) : null;
		$description = is_string( $payload['description'] ) ? self::clean( $payload['description'], self::MAX_DESCRIPTION ) : null;
		$previous    = SeoPlugins::read( $plugin, $target['kind'], $target['post_id'] );
		$warnings    = SeoPlugins::write( $plugin, $target['kind'], $target['post_id'], $title, $description );
		$saved       = SeoPlugins::read( $plugin, $target['kind'], $target['post_id'] );
		if ( ( null !== $title && $saved['title'] !== $title ) || ( null !== $description && $saved['description'] !== $description ) ) {
			return self::finish( $id, self::failure( $id, 'not_saved', __( 'WordPress didn’t keep the new values (another plugin may have changed them).', 'arvaseo-connector' ) ) );
		}

		AuditLogger::info(
			'seo.updated',
			__( 'SEO title and description updated from Arva SEO.', 'arvaseo-connector' ),
			array(
				'post_id'    => $target['post_id'],
				'target'     => $target['kind'],
				'seo_plugin' => $plugin,
				'title'      => null !== $title,
				'desc'       => null !== $description,
			)
		);
		return self::finish(
			$id,
			self::result(
				$id,
				array(
					'outcome'    => 'applied',
					'target'     => $target['kind'],
					'post_id'    => $target['post_id'] > 0 ? $target['post_id'] : null,
					'post_type'  => $target['post_type'],
					'seo_plugin' => $plugin,
					'previous'   => $previous,
					'applied'    => array(
						'title'       => $title,
						'description' => $description,
					),
					'edit_url'   => $target['post_id'] > 0 ? admin_url( 'post.php?post=' . $target['post_id'] . '&action=edit' ) : null,
					'warnings'   => $warnings,
				)
			),
			$target,
			$plugin
		);
	}

	/**
	 * Send a result; on failure it stays in the outbox for the next poll.
	 *
	 * @param array<string, mixed> $result Result.
	 */
	public static function send_result( array $result ): bool {
		global $wpdb;
		$table    = Schema::table( 'seo_updates' );
		$response = ApiClient::instance()->site( 'POST', '/seo-updates/' . rawurlencode( (string) $result['update_id'] ) . '/result', $result );
		$sent     = $response->ok() || in_array( $response->status, array( 404, 409, 422 ), true ); // Settled (or never retryable).
		$row      = $wpdb->get_row( $wpdb->prepare( 'SELECT result_attempts FROM %i WHERE update_id = %s', $table, $result['update_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		$attempts = (int) ( $row['result_attempts'] ?? 0 ) + 1;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
			$table,
			array(
				'result_sent'     => $sent ? 1 : 0,
				'result_attempts' => $attempts,
				'next_result_at'  => $sent ? null : Ids::mysql( time() + min( HOUR_IN_SECONDS, 60 * ( 2 ** min( 6, $attempts ) ) ) + wp_rand( 0, 30 ) ),
			),
			array( 'update_id' => $result['update_id'] )
		);
		if ( ! $response->ok() ) {
			AuditLogger::warning(
				'seo.result_failed',
				__( 'Couldn’t report an SEO update result to Arva SEO; will retry.', 'arvaseo-connector' ),
				array(
					'update_id'   => $result['update_id'],
					'http_status' => $response->status,
					'code'        => $response->error_code,
				)
			);
		}
		return $response->ok();
	}

	/**
	 * Retry results that didn't get through.
	 */
	public static function flush_outbox(): int {
		global $wpdb;
		$table = Schema::table( 'seo_updates' );
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT result FROM %i WHERE result_sent = 0 AND result IS NOT NULL AND result_attempts < 20 AND (next_result_at IS NULL OR next_result_at <= %s) ORDER BY id LIMIT 50', $table, Ids::mysql() ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		$sent  = 0;
		foreach ( $rows as $row ) {
			$result = json_decode( (string) $row['result'], true );
			if ( is_array( $result ) && self::send_result( $result ) ) {
				++$sent;
			}
		}
		return $sent;
	}

	/**
	 * What the address is on this site: a published post (of a public type, not media) or the
	 * homepage; otherwise the reason it can't be updated.
	 *
	 * @param string $url Address.
	 * @return array{kind: string, post_id: int, post_type: string|null}|string
	 */
	public static function target( string $url ) {
		$path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
		$home = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( $path === $home && '' === (string) wp_parse_url( $url, PHP_URL_QUERY ) ) {
			$front = (int) get_option( 'page_on_front' );
			if ( 'page' !== get_option( 'show_on_front' ) || $front <= 0 ) {
				return array(
					'kind'      => 'home',
					'post_id'   => 0,
					'post_type' => null,
				);
			}
			$post_id = $front;
		} else {
			$post_id = url_to_postid( $url );
		}
		$post = $post_id > 0 ? get_post( $post_id ) : null;
		if ( ! $post instanceof \WP_Post ) {
			return 'page_not_found';
		}
		if ( 'attachment' === $post->post_type || ! is_post_type_viewable( $post->post_type ) ) {
			return 'unsupported_post_type';
		}
		if ( 'publish' !== $post->post_status ) {
			return 'not_published';
		}
		return array(
			'kind'      => 'post',
			'post_id'   => (int) $post->ID,
			'post_type' => $post->post_type,
		);
	}

	/**
	 * One line of plain text, cut to the limit.
	 *
	 * @param string $value Value.
	 * @param int    $max   Characters.
	 */
	private static function clean( string $value, int $max ): string {
		return mb_substr( trim( sanitize_text_field( $value ) ), 0, $max );
	}

	/**
	 * The identifiers needed to record and report anything.
	 *
	 * @param array<string, mixed> $p Payload.
	 * @return array{update_id: string, event_id: string}|null
	 */
	private static function identity( array $p ): ?array {
		$ok = isset( $p['update_id'], $p['event_id'] )
			&& is_string( $p['update_id'] ) && preg_match( '/^[0-9A-HJKMNP-TV-Z]{26}$/i', $p['update_id'] )
			&& Ids::is_uuid( $p['event_id'] );
		return $ok ? array(
			'update_id' => $p['update_id'],
			'event_id'  => $p['event_id'],
		) : null;
	}

	/**
	 * Check the whole payload; [code, message] when something is wrong.
	 *
	 * @param array<string, mixed> $p Payload.
	 * @return array{0: string, 1: string}|null
	 */
	private static function validate( array $p ): ?array {
		$keys = array( 'schema_version', 'update_id', 'event_id', 'site_registration_id', 'workspace_id', 'project_id', 'url', 'title', 'description', 'created_at' );
		if ( array_diff( array_keys( $p ), $keys ) !== array() || array_diff( $keys, array_keys( $p ) ) !== array() ) {
			return array( 'validation_failed', __( 'The update has undocumented or missing fields.', 'arvaseo-connector' ) );
		}
		if ( '1.0' !== $p['schema_version'] ) {
			return array( 'validation_failed', __( 'This update’s schema version isn’t supported. Update the Arva SEO Connector plugin.', 'arvaseo-connector' ) );
		}
		$connection = TokenStore::connection();
		if ( TokenStore::site_id() !== $p['site_registration_id'] || ( $connection['project']['id'] ?? null ) !== $p['project_id'] || ( $connection['workspace']['id'] ?? null ) !== $p['workspace_id'] ) {
			return array( 'validation_failed', __( 'This update is for another site, website or workspace.', 'arvaseo-connector' ) );
		}
		$checks = array(
			is_string( $p['url'] ) && strlen( $p['url'] ) <= 2048 && in_array( wp_parse_url( $p['url'], PHP_URL_SCHEME ), array( 'https', 'http' ), true ),
			null === $p['title'] || ( is_string( $p['title'] ) && mb_strlen( $p['title'] ) <= self::MAX_TITLE ),
			null === $p['description'] || ( is_string( $p['description'] ) && mb_strlen( $p['description'] ) <= self::MAX_DESCRIPTION ),
			null !== $p['title'] || null !== $p['description'],
		);
		if ( in_array( false, array_map( 'boolval', $checks ), true ) ) {
			return array( 'validation_failed', __( 'A field in the update isn’t valid.', 'arvaseo-connector' ) );
		}
		$host = static fn ( string $h ): string => preg_replace( '/^www\./', '', strtolower( $h ) );
		if ( $host( (string) wp_parse_url( $p['url'], PHP_URL_HOST ) ) !== $host( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ) ) {
			return array( 'other_site', __( 'This address isn’t on this WordPress site.', 'arvaseo-connector' ) );
		}
		return null;
	}

	/**
	 * Store the result as the idempotent answer for this update.
	 *
	 * @param array<string, mixed>                                           $id     Identity.
	 * @param array<string, mixed>                                           $result Result.
	 * @param array{kind: string, post_id: int, post_type: string|null}|null $target Target.
	 * @param string|null                                                    $plugin SEO plugin.
	 * @return array<string, mixed>
	 */
	private static function finish( array $id, array $result, ?array $target = null, ?string $plugin = null ): array {
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
			Schema::table( 'seo_updates' ),
			array(
				'state'             => 'processed',
				'outcome'           => $result['outcome'],
				'target'            => $target['kind'] ?? null,
				'wordpress_post_id' => isset( $target['post_id'] ) && $target['post_id'] > 0 ? $target['post_id'] : null,
				'seo_plugin'        => $plugin,
				'result'            => wp_json_encode( $result ),
				'result_sent'       => 0,
				'error_code'        => $result['error']['code'] ?? null,
				'processed_at'      => Ids::mysql(),
			),
			array( 'update_id' => $id['update_id'] )
		);
		return $result;
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
				'seo.failed',
				$message,
				array(
					'code'      => $code,
					'update_id' => $id['update_id'],
				)
			);
		}
		return self::result(
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
	 * The full result, every documented field present.
	 *
	 * @param array<string, mixed> $id     Identity.
	 * @param array<string, mixed> $fields Fields.
	 * @return array<string, mixed>
	 */
	private static function result( array $id, array $fields ): array {
		return array_merge(
			array(
				'schema_version' => '1.0',
				'update_id'      => $id['update_id'],
				'event_id'       => $id['event_id'],
				'outcome'        => 'failed',
				'target'         => null,
				'post_id'        => null,
				'post_type'      => null,
				'seo_plugin'     => null,
				'previous'       => null,
				'applied'        => null,
				'edit_url'       => null,
				'warnings'       => array(),
				'error'          => null,
				'processed_at'   => Ids::iso(),
			),
			$fields
		);
	}
}
