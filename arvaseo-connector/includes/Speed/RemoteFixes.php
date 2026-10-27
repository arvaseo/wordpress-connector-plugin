<?php
/**
 * Switching PageSpeed fixes from Arva SEO, when an administrator allows it here.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Speed;

use ArvaSEO\Connector\Api\ApiClient;
use ArvaSEO\Connector\Auth\TokenStore;
use ArvaSEO\Connector\Logging\AuditLogger;

defined( 'ABSPATH' ) || exit;

/**
 * Off until an administrator ticks "Let Arva SEO switch these fixes". Switches asked for in Arva SEO
 * are picked up on the regular poll (a signed event makes it immediate) and follow the same rules as
 * this page: a fix that can be tried goes on for everyone only after it's been tried; Defer scripts
 * goes on only here, after its checklist; turning a fix off always works; "use_update" (2.11.0) lets
 * an update that waits for its check go live, except for fixes that go on in WordPress only. A
 * "use_update" gives visitors the update the person saw when they asked, and nothing else: the
 * request names the notes it was about (update), is good for an hour, and is refused when the
 * administrator kept the previous version after it was asked for. Every change is in the Activity
 * Log with the name of the person who asked, and Arva SEO is told what happened.
 */
final class RemoteFixes {

	/** A "use_update" request older than this is not carried out: the update may not be the one the person saw. */
	public const USE_UPDATE_MAX_AGE = HOUR_IN_SECONDS;

	/**
	 * Whether Arva SEO may switch the fixes.
	 */
	public static function allowed(): bool {
		return Fixes::stored()['remote'] && TokenStore::is_connected();
	}

	/**
	 * Fetch the switches waiting in Arva SEO and do them. Returns how many were handled.
	 */
	public static function poll(): int {
		if ( ! self::allowed() ) {
			return 0;
		}
		$client  = ApiClient::instance();
		$pending = $client->site( 'GET', '/speed-fix-requests/pending' );
		if ( ! $pending->ok() ) {
			return 0;
		}
		$count = 0;
		foreach ( array_slice( $pending->data(), 0, 20 ) as $request ) {
			if ( ! is_array( $request ) || ! is_string( $request['request_id'] ?? null ) || ! preg_match( '/^[0-9A-Za-z]{26}$/', $request['request_id'] ) ) {
				continue;
			}
			$update = is_array( $request['update'] ?? null ) ? array_values( array_filter( $request['update'], 'is_string' ) ) : null;
			$result = self::apply( (string) ( $request['fix'] ?? '' ), (string) ( $request['to'] ?? '' ), self::name( $request['requested_by'] ?? null ), $update, self::time( $request['requested_at'] ?? null ) );
			$client->site( 'POST', '/speed-fix-requests/' . $request['request_id'] . '/result', $result );
			++$count;
		}
		return $count;
	}

	/**
	 * Do one switch, by this page's rules.
	 *
	 * @param string        $id        Fix ID.
	 * @param string        $to        off, trying, on or use_update.
	 * @param string        $name      Who asked, in Arva SEO.
	 * @param string[]|null $update    The ids of the update notes the person saw (use_update); null when the request doesn't say.
	 * @param int|null      $requested When it was asked for (use_update); null when the request doesn't say.
	 * @return array{outcome: string, state: string, code: string|null, rev?: int}
	 */
	public static function apply( string $id, string $to, string $name, ?array $update = null, ?int $requested = null ): array {
		$fix = Fixes::all()[ $id ] ?? null;
		if ( 'use_update' === $to && null !== $fix ) {
			return self::use_update( $id, $fix, $name, $update, $requested );
		}
		if ( null === $fix || ! in_array( $to, Fixes::STATES, true ) ) {
			return self::result( 'refused', $id, 'invalid' );
		}
		$now = Fixes::state( $id );
		if ( 'on' === $to && 'defer_scripts' === $id ) {
			return self::result( 'refused', $id, 'in_wordpress_only' );
		}
		// An update waits for the administrator's check: "on" doesn't say they've seen it.
		if ( 'on' === $to && 'on' === $now && array() !== Changes::waiting( $id ) ) {
			return self::result( 'refused', $id, 'update_to_check' );
		}
		if ( 'on' === $to && 'check' === $fix['risk'] && in_array( 'trying', $fix['states'], true ) && 'trying' !== $now ) {
			return self::result( 'refused', $id, 'try_first' );
		}
		$error = Fixes::set( $id, $to );
		if ( null !== $error ) {
			return self::result( 'refused', $id, $error );
		}
		AuditLogger::info(
			'speed.fix_switched_remotely',
			sprintf(
				/* translators: 1: fix name, 2: new state, 3: person's name. */
				__( 'PageSpeed fix “%1$s”: %2$s, asked for in Arva SEO by %3$s.', 'arvaseo-connector' ),
				$fix['title'],
				Fixes::state_label( $to ),
				$name
			),
			array(
				'fix' => $id,
				'to'  => $to,
			)
		);
		return self::result( 'applied', $id, null );
	}

	/**
	 * "Use it for visitors": an update the administrator has checked in WordPress goes live. Never
	 * for Defer scripts or the Google tag fix, which are turned on in WordPress only.
	 *
	 * @param string               $id        Fix ID.
	 * @param array<string, mixed> $fix       The fix.
	 * @param string               $name      Who asked, in Arva SEO.
	 * @param string[]|null        $update    The update notes the person saw.
	 * @param int|null             $requested When it was asked for.
	 * @return array{outcome: string, state: string, code: string|null, rev?: int}
	 */
	private static function use_update( string $id, array $fix, string $name, ?array $update, ?int $requested ): array {
		if ( in_array( $id, Changes::WORDPRESS_ONLY, true ) ) {
			return self::result( 'refused', $id, 'in_wordpress_only' );
		}
		if ( array() !== Changes::waiting( $id ) ) {
			if ( null !== $requested && $requested < time() - self::USE_UPDATE_MAX_AGE ) {
				return self::result( 'refused', $id, 'request_expired' );
			}
			// What waits now must be what the person saw, and the administrator must not have kept the previous version since.
			if ( null !== $requested && Changes::kept_at( $id ) >= $requested ) {
				return self::result( 'refused', $id, 'update_kept' );
			}
			$waiting = array_column( Changes::waiting( $id ), 'id' );
			if ( null !== $update ) {
				sort( $update );
				sort( $waiting );
				if ( $update !== $waiting ) {
					return self::result( 'refused', $id, 'update_changed' );
				}
			}
		}
		$error = Fixes::accept_update( $id );
		if ( null !== $error ) {
			// Arva SEO's name for it, the same as its own answer when it can tell beforehand.
			return self::result( 'refused', $id, 'no_update' === $error ? 'no_update_waiting' : $error );
		}
		AuditLogger::info(
			'speed.update_used_remotely',
			sprintf(
				/* translators: 1: fix name, 2: person's name. */
				__( 'PageSpeed fix “%1$s”: the update goes live for visitors, asked for in Arva SEO by %2$s.', 'arvaseo-connector' ),
				(string) $fix['title'],
				$name
			),
			array( 'to' => 'use_update' )
		);
		return self::result( 'applied', $id, null ) + array( 'rev' => Changes::approved_rev( $id ) );
	}

	/**
	 * What Arva SEO is told.
	 *
	 * @param string      $outcome applied or refused.
	 * @param string      $id      Fix ID.
	 * @param string|null $code    Why it was refused.
	 * @return array{outcome: string, state: string, code: string|null}
	 */
	private static function result( string $outcome, string $id, ?string $code ): array {
		$state = isset( Fixes::all()[ $id ] ) ? Fixes::state( $id ) : 'off';
		return array(
			'outcome' => $outcome,
			'state'   => in_array( $state, array( 'off', 'trying', 'on', 'handled' ), true ) ? $state : 'off',
			'code'    => null === $code ? null : substr( (string) preg_replace( '/[^a-z_]/', '', $code ), 0, 40 ),
		);
	}

	/**
	 * A time from Arva SEO (ISO 8601), or null.
	 *
	 * @param mixed $time The time.
	 */
	private static function time( $time ): ?int {
		$at = is_string( $time ) ? strtotime( $time ) : false;
		return false === $at ? null : $at;
	}

	/**
	 * A person's name from Arva SEO, as plain text.
	 *
	 * @param mixed $name Name.
	 */
	private static function name( $name ): string {
		$name = is_string( $name ) ? trim( wp_strip_all_tags( $name ) ) : '';
		return '' !== $name ? mb_substr( $name, 0, 80 ) : __( 'someone on your team', 'arvaseo-connector' );
	}
}
