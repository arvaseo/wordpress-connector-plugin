<?php
/**
 * Public homepage delivery proof, separate from a cache-purge request.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Speed;

defined( 'ABSPATH' ) || exit;

/** Keeps only a public cache generation and bounded delivery metadata, never HTML or cookies. */
final class PublicDelivery {
	public const OPTION     = 'arvaseo_connector_public_delivery';
	public const GENERATION = 'arvaseo_connector_public_generation';

	/** Register a non-identifying page marker and invalidate proof when output changes. */
	public static function init(): void {
		add_action( 'wp_head', array( self::class, 'marker' ), 0 );
		add_action( 'arvaseo_connector_pages_changed', array( self::class, 'changed' ), 0 );
	}

	/** A generation belongs to the site's published output, never an individual visitor. */
	public static function generation(): string {
		$value = get_option( self::GENERATION, '' );
		if ( ! is_string( $value ) || ! preg_match( '/^[a-f0-9]{32}$/D', $value ) ) {
			$value = str_replace( '-', '', wp_generate_uuid4() );
			update_option( self::GENERATION, $value, false );
		}
		return $value;
	}

	/** Print early, before the potentially large generated stylesheets. */
	public static function marker(): void {
		if ( ! is_admin() && is_front_page() && ! is_feed() && ! is_preview() && ! CssOptimizer::is_render_request() ) {
			printf( '<meta name="arvaseo-cache-generation" content="%s">' . "\n", esc_attr( self::generation() ) );
		}
	}

	/**
	 * Invalidate a previous observation, including explicit cache clears after outside edits.
	 *
	 * @param mixed $why Change reason.
	 */
	public static function changed( $why = '' ): void {
		if ( is_string( $why ) && OtherCaches::manages( $why ) ) {
			update_option( self::GENERATION, str_replace( '-', '', wp_generate_uuid4() ), false );
			delete_option( self::OPTION );
		}
	}

	/**
	 * Last observation; a proof for an earlier generation is never current.
	 *
	 * @return array<string,mixed>
	 */
	public static function status(): array {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) || ! is_string( $saved['generation'] ?? null ) ) {
			return array(
				'status' => 'unchecked',
				'at'     => null,
			);
		}
		if ( ! hash_equals( self::generation(), $saved['generation'] ) ) {
			$saved['status'] = 'stale';
		}
		return $saved;
	}

	/**
	 * Fetch exactly the configured public homepage anonymously. Redirects, failures and absent
	 * markers are inconclusive. No cache-busting URL or purge acknowledgment is treated as proof.
	 *
	 * @return array<string,mixed>
	 */
	public static function verify(): array {
		$expected = self::generation();
		$result   = array(
			'status'     => 'pending',
			'at'         => time(),
			'age'        => null,
			'cache'      => '',
			'generation' => $expected,
		);
		if ( false === wp_next_scheduled( OtherCaches::HOOK_CLEAR ) ) {
			$response         = wp_safe_remote_get(
				home_url( '/' ),
				array(
					'timeout'             => 5,
					'redirection'         => 0,
					'limit_response_size' => 65536,
					'cookies'             => array(),
					'headers'             => array( 'Accept' => 'text/html' ),
				)
			);
			$result['status'] = 'unavailable';
			if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
				$result['status'] = 'unconfirmed';
				$tags             = new \WP_HTML_Tag_Processor( wp_remote_retrieve_body( $response ) );
				while ( $tags->next_tag( array( 'tag_name' => 'meta' ) ) ) {
					if ( 'arvaseo-cache-generation' === $tags->get_attribute( 'name' ) ) {
						$seen             = $tags->get_attribute( 'content' );
						$result['status'] = is_string( $seen ) && hash_equals( $expected, $seen ) && hash_equals( $expected, self::generation() ) ? 'current' : 'stale';
						break;
					}
				}
				$age             = wp_remote_retrieve_header( $response, 'age' );
				$result['age']   = is_scalar( $age ) && ctype_digit( (string) $age ) ? min( 2592000, (int) $age ) : null;
				$result['cache'] = substr( sanitize_text_field( (string) wp_remote_retrieve_header( $response, 'cf-cache-status' ) ), 0, 24 );
			}
		}
		update_option( self::OPTION, $result, false );
		return self::status();
	}
}
