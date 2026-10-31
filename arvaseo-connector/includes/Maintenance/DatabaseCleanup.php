<?php
/**
 * Explicit, administrator-controlled maintenance. Remote tools can only read saved counts.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Maintenance;

use ArvaSEO\Connector\Install\Capabilities;
use ArvaSEO\Connector\Logging\AuditLogger;
use ArvaSEO\Connector\Support\Lock;

defined( 'ABSPATH' ) || exit;

/** No table rebuilds, media deletion or changes to published content. Weekly maintenance starts off. */
final class DatabaseCleanup {

	public const OPTION = 'arvaseo_connector_database_cleanup';
	public const COUNTS = 'arvaseo_connector_database_counts';
	public const HOOK   = 'arvaseo_connector_database_cleanup';
	public const WEEKLY = 'arvaseo_connector_database_cleanup_weekly';

	/** Register workers; an automatic run exists only after an administrator enables it. */
	public static function init(): void {
		add_action( self::HOOK, array( self::class, 'batch' ) );
		add_action( self::WEEKLY, array( self::class, 'weekly' ) );
		if ( self::state()['weekly'] && ! wp_next_scheduled( self::WEEKLY ) ) {
			wp_schedule_event( time() + WEEK_IN_SECONDS, 'weekly', self::WEEKLY );
		}
	}

	/**
	 * Local progress, without post titles, comment text, metadata values or transient names.
	 *
	 * @return array{running:bool,weekly:bool,started:int,finished:int,removed:array<string,int>,error:bool}
	 */
	public static function state(): array {
		$state = get_option( self::OPTION, array() );
		return array(
			'running'  => ! empty( $state['running'] ),
			'weekly'   => ! empty( $state['weekly'] ),
			'started'  => (int) ( $state['started'] ?? 0 ),
			'finished' => (int) ( $state['finished'] ?? 0 ),
			'removed'  => is_array( $state['removed'] ?? null ) ? array_map( 'intval', $state['removed'] ) : array(),
			'error'    => ! empty( $state['error'] ),
		);
	}

	/**
	 * Read worker control changes from other PHP processes, not this request's option cache.
	 *
	 * @return array{running:bool,weekly:bool,started:int,finished:int,removed:array<string,int>,error:bool}
	 */
	private static function fresh_state(): array {
		wp_cache_delete( self::OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		return self::state();
	}

	/**
	 * Counts are metadata only. Collection does not authorize cleanup.
	 *
	 * @param bool $refresh Refresh counts now (WordPress admin or scheduled health collection).
	 * @return array{at:int,items:array<string,int>,error:bool}
	 */
	public static function counts( bool $refresh = false ): array {
		global $wpdb;
		$saved = get_option( self::COUNTS, array() );
		if ( ! $refresh && is_array( $saved['items'] ?? null ) ) {
			return array(
				'at'    => (int) ( $saved['at'] ?? 0 ),
				'items' => array_map( 'intval', $saved['items'] ),
				'error' => ! empty( $saved['error'] ),
			);
		}
		if ( ! $refresh ) {
			return array(
				'at'    => 0,
				'items' => array(),
				'error' => false,
			);
		}
		$counts = array();
		$error  = false;
		foreach ( self::rules() as $key => $rule ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table names and fixed predicates only; no request data.
			$value          = $wpdb->get_var( "SELECT COUNT(*) FROM {$rule['table']} target WHERE {$rule['where']}" );
			$error          = $error || null === $value;
			$counts[ $key ] = (int) $value;
		}
		$result = array(
			'at'    => time(),
			'items' => $counts,
			'error' => $error,
		);
		update_option( self::COUNTS, $result, false );
		return $result;
	}

	/**
	 * Read-only health facts; no metadata values or deletion capability are sent to Arva SEO.
	 *
	 * @return array<string,mixed>
	 */
	public static function snapshot(): array {
		$counts = self::counts();
		if ( $counts['at'] < time() - DAY_IN_SECONDS ) {
			$counts = self::counts( true );
		}
		$state = self::state();
		$value = array(
			'checked_at'     => $counts['at'] > 0 ? gmdate( 'Y-m-d\TH:i:s\Z', $counts['at'] ) : null,
			'running'        => $state['running'],
			'weekly'         => $state['weekly'],
			'wordpress_only' => true,
		);
		foreach ( array_keys( self::rules() ) as $key ) {
			$value[ $key ] = $counts['error'] ? null : ( $counts['items'][ $key ] ?? null );
		}
		return array(
			'status' => $counts['error'] ? 'unknown' : 'good',
			'source' => 'local_check',
			'value'  => $value,
		);
	}

	/**
	 * WordPress administrator's acknowledged start. No REST or MCP action calls this method.
	 *
	 * @param bool $acknowledged Administrator accepted the irreversible cleanup notice.
	 */
	public static function start( bool $acknowledged ): bool {
		if ( ! $acknowledged || ! current_user_can( Capabilities::MANAGE ) || ! current_user_can( 'delete_others_posts' ) ) {
			return false;
		}
		return self::begin();
	}

	/** Begin only after the admin action or an already opted-in weekly event. */
	private static function begin(): bool {
		return true === Lock::with(
			'database-cleanup',
			60,
			static function (): bool {
				$state = self::state();
				if ( $state['running'] ) {
					return true;
				}
				$state = array_merge(
					$state,
					array(
						'running'  => true,
						'started'  => time(),
						'finished' => 0,
						'removed'  => array(),
						'error'    => false,
					)
				);
				if ( ! update_option( self::OPTION, $state, false ) ) {
					return false;
				}
				wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK );
				return true;
			}
		);
	}

	/**
	 * Change weekly consent only from an authenticated WordPress admin form.
	 *
	 * @param bool $enabled Weekly cleanup choice.
	 */
	public static function set_weekly( bool $enabled ): bool {
		if ( ! current_user_can( Capabilities::MANAGE ) || ! current_user_can( 'delete_others_posts' ) ) {
			return false;
		}
		$changed = Lock::with(
			'database-cleanup',
			60,
			static function () use ( $enabled ): bool {
				$state           = self::state();
				$state['weekly'] = $enabled;
				return get_option( self::OPTION ) === $state || update_option( self::OPTION, $state, false );
			}
		);
		if ( true !== $changed ) {
			return false;
		}
		wp_clear_scheduled_hook( self::WEEKLY );
		if ( $enabled ) {
			wp_schedule_event( time() + WEEK_IN_SECONDS, 'weekly', self::WEEKLY );
		}
		return true;
	}

	/** A retained WordPress-only opt-in is the authorization for scheduled runs. */
	public static function weekly(): void {
		if ( self::state()['weekly'] ) {
			self::begin();
		}
	}

	/** Cancel a run and stop scheduling. Existing records and weekly preference are retained. */
	public static function cancel(): void {
		$state            = self::state();
		$state['running'] = false;
		update_option( self::OPTION, $state, false );
		wp_clear_scheduled_hook( self::HOOK );
		wp_clear_scheduled_hook( self::WEEKLY );
	}

	/** At most 500 candidates and 15 seconds of work; each item is checked again before deletion. */
	public static function batch(): void {
		Lock::with(
			'database-cleanup',
			60,
			static function (): void {
				global $wpdb;
				$state = self::state();
				if ( ! $state['running'] ) {
					return;
				}
				$started = microtime( true );
				$left    = 500;
				$error   = false;
				foreach ( self::rules() as $key => $rule ) {
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal predicates, integer bounded limit, no user input.
					$ids = $wpdb->get_col( "SELECT target.{$rule['id']} FROM {$rule['table']} target WHERE {$rule['where']} ORDER BY target.{$rule['id']} LIMIT {$left}" );
					if ( '' !== $wpdb->last_error ) {
						$error = true;
						break;
					}
					foreach ( $ids as $id ) {
						if ( microtime( true ) - $started >= 15 || ! self::fresh_state()['running'] ) {
							break 2;
						}
						try {
							$removed = self::remove( $key, $rule, (int) $id );
						} catch ( \Throwable $exception ) {
							$error = true;
							break 2;
						}
						$state['removed'][ $key ] = ( $state['removed'][ $key ] ?? 0 ) + (int) $removed;
						if ( ! $removed ) {
							$error = true;
							break 2;
						}
						if ( --$left <= 0 ) {
							break 2;
						}
					}
				}
				// Never let a cancellation that arrived during the batch be overwritten by this worker.
				$state['running']  = self::fresh_state()['running'] && ! $error && ( $left <= 0 || microtime( true ) - $started >= 15 );
				$state['error']    = $error;
				$state['finished'] = $state['running'] ? 0 : time();
				update_option( self::OPTION, $state, false );
				delete_option( self::COUNTS );
				wp_clear_scheduled_hook( self::HOOK );
				if ( $state['running'] ) {
					wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK );
				} else {
					AuditLogger::info(
						'maintenance.database',
						__( 'Database cleanup finished or stopped. Current content and the newest five revisions were kept.', 'arvaseo-connector' ),
						array(
							'removed' => $state['removed'],
							'error'   => $error,
						)
					);
				}
			}
		);
	}

	/**
	 * Revalidate under a row lock before calling WordPress deletion hooks.
	 *
	 * @param string               $key  Category.
	 * @param array<string,string> $rule Internal table and predicate.
	 * @param int                  $id   Candidate ID.
	 */
	private static function remove( string $key, array $rule, int $id ): bool {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- trusted internal predicates, integer ID; WordPress APIs invalidate the affected caches.
		// A savepoint probe detects an existing transaction without committing another plugin's work.
		$savepoint = 'arva_cleanup_' . str_replace( '-', '', wp_generate_uuid4() );
		$quiet     = $wpdb->suppress_errors();
		$wpdb->query( "SAVEPOINT {$savepoint}" );
		$outer = false !== $wpdb->query( "RELEASE SAVEPOINT {$savepoint}" );
		$wpdb->suppress_errors( $quiet );
		if ( false === $wpdb->query( $outer ? "SAVEPOINT {$savepoint}" : 'START TRANSACTION' ) ) {
			return false;
		}
		$done = false;
		try {
			$exists = $wpdb->get_var( "SELECT target.{$rule['id']} FROM {$rule['table']} target WHERE target.{$rule['id']} = {$id} AND ({$rule['where']}) FOR UPDATE" );
			if ( null === $exists ) {
				return false;
			}
			if ( 'revisions' === $key ) {
				$done = wp_delete_post_revision( $id ) instanceof \WP_Post;
				return $done;
			}
			if ( in_array( $key, array( 'auto_drafts', 'trash_posts' ), true ) ) {
				$done = wp_delete_post( $id, true ) instanceof \WP_Post;
				return $done;
			}
			if ( 'comments' === $key ) {
				$done = wp_delete_comment( $id, true );
				return $done;
			}
			if ( 'transients' === $key ) {
				$name = $wpdb->get_var( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_id = %d", $id ) );
				if ( ! is_string( $name ) ) {
					return false;
				}
				// Delete expired database options even with an external object cache; never evict a renewed cache value.
				delete_option( '_transient_' . substr( $name, strlen( '_transient_timeout_' ) ) );
				$done = delete_option( $name );
				return $done;
			}
			$done = 1 === $wpdb->query( "DELETE target FROM {$rule['table']} target WHERE target.{$rule['id']} = {$id} AND ({$rule['where']})" );
			return $done;
		} finally {
			if ( ! $done ) {
				$wpdb->query( $outer ? "ROLLBACK TO SAVEPOINT {$savepoint}" : 'ROLLBACK' );
			}
			if ( $outer || $done ) {
				$wpdb->query( $outer ? "RELEASE SAVEPOINT {$savepoint}" : 'COMMIT' );
			}
		}
		// phpcs:enable
	}

	/**
	 * Fixed predicates, deliberately restricted to post/page revisions and ordinary posts.
	 * Pages, products, orders and attachments themselves are never candidates.
	 *
	 * @return array<string,array{table:string,id:string,where:string}>
	 */
	private static function rules(): array {
		global $wpdb;
		$week       = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );
		$month      = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
		$cutoff     = time() - 30 * DAY_IN_SECONDS;
		$now        = time();
		$local_week = get_date_from_gmt( $week );
		return array(
			'revisions'          => array(
				'table' => $wpdb->posts,
				'id'    => 'ID',
				'where' => "target.post_type='revision' AND EXISTS (SELECT 1 FROM {$wpdb->posts} parent WHERE parent.ID=target.post_parent AND parent.post_type IN ('post','page')) AND (SELECT COUNT(*) FROM {$wpdb->posts} newer WHERE newer.post_type='revision' AND newer.post_parent=target.post_parent AND newer.ID>target.ID)>=5",
			),
			'auto_drafts'        => array(
				'table' => $wpdb->posts,
				'id'    => 'ID',
				'where' => "target.post_type='post' AND target.post_status='auto-draft' AND ((target.post_modified_gmt > '1970-01-01 00:00:00' AND target.post_modified_gmt < '{$week}') OR (target.post_modified_gmt='0000-00-00 00:00:00' AND target.post_modified > '1970-01-01 00:00:00' AND target.post_modified < '{$local_week}'))",
			),
			'trash_posts'        => array(
				'table' => $wpdb->posts,
				'id'    => 'ID',
				'where' => "target.post_type='post' AND target.post_status='trash' AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} trash WHERE trash.post_id=target.ID AND trash.meta_key='_wp_trash_meta_time' AND trash.meta_value REGEXP '^[0-9]+$' AND CAST(trash.meta_value AS UNSIGNED)<{$cutoff})",
			),
			'comments'           => array(
				'table' => $wpdb->comments,
				'id'    => 'comment_ID',
				'where' => "((target.comment_approved='spam' AND target.comment_date_gmt > '1970-01-01 00:00:00' AND target.comment_date_gmt < '{$month}') OR (target.comment_approved='trash' AND EXISTS (SELECT 1 FROM {$wpdb->commentmeta} trash WHERE trash.comment_id=target.comment_ID AND trash.meta_key='wp_trash_meta_time' AND trash.meta_value REGEXP '^[0-9]+$' AND CAST(trash.meta_value AS UNSIGNED)<{$cutoff})))",
			),
			'transients'         => array(
				'table' => $wpdb->options,
				'id'    => 'option_id',
				'where' => "LEFT(target.option_name,19)='_transient_timeout_' AND target.option_value REGEXP '^[0-9]+$' AND CAST(target.option_value AS UNSIGNED)<{$now}",
			),
			'orphan_postmeta'    => array(
				'table' => $wpdb->postmeta,
				'id'    => 'meta_id',
				'where' => "NOT EXISTS (SELECT 1 FROM {$wpdb->posts} parent WHERE parent.ID=target.post_id)",
			),
			'orphan_commentmeta' => array(
				'table' => $wpdb->commentmeta,
				'id'    => 'meta_id',
				'where' => "NOT EXISTS (SELECT 1 FROM {$wpdb->comments} parent WHERE parent.comment_ID=target.comment_id)",
			),
			'orphan_termmeta'    => array(
				'table' => $wpdb->termmeta,
				'id'    => 'meta_id',
				'where' => "NOT EXISTS (SELECT 1 FROM {$wpdb->terms} parent WHERE parent.term_id=target.term_id)",
			),
		);
	}
}
