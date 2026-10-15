<?php
/**
 * Redacted activity history.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Logging;

use ArvaSEO\Connector\Install\Schema;
use ArvaSEO\Connector\Support\Ids;

defined( 'ABSPATH' ) || exit;

/**
 * Writes the Activity Log. Context is filtered through an allowlist of keys and every value is
 * truncated; anything that looks like a credential is dropped, whatever its key. Never log
 * tokens, codes, secrets, payloads or post content.
 */
final class AuditLogger {

	/** Keys that may appear in context. */
	private const ALLOWED_KEYS = array(
		'status',
		'code',
		'http_status',
		'from',
		'to',
		'count',
		'post_id',
		'delivery_id',
		'external_content_id',
		'external_revision',
		'requested_status',
		'applied_status',
		'outcome',
		'snapshot_id',
		'inventory_id',
		'event_type',
		'event_id',
		'reason',
		'scopes',
		'attempt',
		'retry_after',
		'duration_ms',
		'checks',
		'skipped',
		'mode',
		'host',
		'warnings',
		'version',
		'project',
		'workspace',
		'request_id',
	);

	/**
	 * The current request's correlation ID.
	 *
	 * @var string|null
	 */
	private static ?string $request_id = null;

	/**
	 * Correlation ID shared by every entry and API call in this request.
	 */
	public static function request_id(): string {
		if ( null === self::$request_id ) {
			self::$request_id = Ids::uuid();
		}
		return self::$request_id;
	}

	/**
	 * Informational entry.
	 *
	 * @param string               $event   Stable event code.
	 * @param string               $message Short, translated message.
	 * @param array<string, mixed> $context Allowlisted context.
	 */
	public static function info( string $event, string $message, array $context = array() ): void {
		self::write( 'info', $event, $message, $context );
	}

	/**
	 * Warning entry.
	 *
	 * @param string               $event   Stable event code.
	 * @param string               $message Short, translated message.
	 * @param array<string, mixed> $context Allowlisted context.
	 */
	public static function warning( string $event, string $message, array $context = array() ): void {
		self::write( 'warning', $event, $message, $context );
	}

	/**
	 * Error entry.
	 *
	 * @param string               $event   Stable event code.
	 * @param string               $message Short, translated message.
	 * @param array<string, mixed> $context Allowlisted context.
	 */
	public static function error( string $event, string $message, array $context = array() ): void {
		self::write( 'error', $event, $message, $context );
	}

	/**
	 * Keep only safe context.
	 *
	 * @param array<string, mixed> $context Raw context.
	 * @return array<string, mixed>
	 */
	public static function redact( array $context ): array {
		$clean = array();
		foreach ( $context as $key => $value ) {
			if ( ! in_array( $key, self::ALLOWED_KEYS, true ) ) {
				continue;
			}
			if ( is_array( $value ) ) {
				$value = array_slice( array_map( array( self::class, 'scalar' ), array_values( $value ) ), 0, 20 );
			} else {
				$value = self::scalar( $value );
			}
			$clean[ $key ] = $value;
		}
		return $clean;
	}

	/**
	 * A safe scalar: credentials and long values never survive.
	 *
	 * @param mixed $value Value.
	 * @return string|int|float|bool|null
	 */
	private static function scalar( $value ) {
		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
			return $value;
		}
		$value = (string) ( is_scalar( $value ) ? $value : wp_json_encode( $value ) );
		if ( preg_match( '/arvwp_|bearer\s|eyJ[a-z0-9_-]{10,}|[a-z0-9_\-]{40,}/i', $value ) ) {
			return '[redacted]';
		}
		return mb_substr( wp_strip_all_tags( $value ), 0, 200 );
	}

	/**
	 * Insert a row.
	 *
	 * @param string               $level   info|warning|error.
	 * @param string               $event   Event code.
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Context.
	 */
	private static function write( string $level, string $event, string $message, array $context ): void {
		global $wpdb;
		$table = Schema::table( 'audit_log' );
		$user  = get_current_user_id();
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- the plugin's own log table.
			$table,
			array(
				'created_at' => Ids::mysql(),
				'level'      => $level,
				'event'      => substr( sanitize_key( str_replace( '.', '_', $event ) ) === '' ? 'event' : $event, 0, 64 ),
				'message'    => mb_substr( wp_strip_all_tags( $message ), 0, 255 ),
				'context'    => wp_json_encode( self::redact( $context ) ),
				'user_id'    => $user > 0 ? $user : null,
				'request_id' => self::request_id(),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);
	}
}
