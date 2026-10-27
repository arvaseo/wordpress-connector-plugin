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
 * an update that waits for its check go live, except for fixes that go on in WordPress only. Every
 * change is in the Activity Log with the name of the person who asked, and Arva SEO is told what
 * happened.
 */
final class RemoteFixes {

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
			$result = self::apply( (string) ( $request['fix'] ?? '' ), (string) ( $request['to'] ?? '' ), self::name( $request['requested_by'] ?? null ) );
			$client->site( 'POST', '/speed-fix-requests/' . $request['request_id'] . '/result', $result );
			++$count;
		}
		return $count;
	}

	/**
	 * Do one switch, by this page's rules.
	 *
	 * @param string $id   Fix ID.
	 * @param string $to   off, trying, on or use_update.
	 * @param string $name Who asked, in Arva SEO.
	 * @return array{outcome: string, state: string, code: string|null, rev?: int}
	 */
	public static function apply( string $id, string $to, string $name ): array {
		$fix = Fixes::all()[ $id ] ?? null;
		if ( 'use_update' === $to && null !== $fix ) {
			return self::use_update( $id, $fix, $name );
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
	 * @param string               $id   Fix ID.
	 * @param array<string, mixed> $fix  The fix.
	 * @param string               $name Who asked, in Arva SEO.
	 * @return array{outcome: string, state: string, code: string|null, rev?: int}
	 */
	private static function use_update( string $id, array $fix, string $name ): array {
		if ( in_array( $id, Changes::WORDPRESS_ONLY, true ) ) {
			return self::result( 'refused', $id, 'in_wordpress_only' );
		}
		$error = Fixes::accept_update( $id );
		if ( null !== $error ) {
			return self::result( 'refused', $id, $error );
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
	 * A person's name from Arva SEO, as plain text.
	 *
	 * @param mixed $name Name.
	 */
	private static function name( $name ): string {
		$name = is_string( $name ) ? trim( wp_strip_all_tags( $name ) ) : '';
		return '' !== $name ? mb_substr( $name, 0, 80 ) : __( 'someone on your team', 'arvaseo-connector' );
	}
}
