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
 * goes on only here, after its checklist; turning a fix off always works. Every change is in the
 * Activity Log with the name of the person who asked, and Arva SEO is told what happened.
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
	 * @param string $to   off, trying or on.
	 * @param string $name Who asked, in Arva SEO.
	 * @return array{outcome: string, state: string, code: string|null}
	 */
	public static function apply( string $id, string $to, string $name ): array {
		$fix = Fixes::all()[ $id ] ?? null;
		if ( null === $fix || ! in_array( $to, Fixes::STATES, true ) ) {
			return self::result( 'refused', $id, 'invalid' );
		}
		$now = Fixes::state( $id );
		if ( 'on' === $to && 'defer_scripts' === $id ) {
			return self::result( 'refused', $id, 'in_wordpress_only' );
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
