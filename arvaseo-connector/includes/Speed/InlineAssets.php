<?php
/**
 * In-place inline minification and explicit approval of public static inline bundles.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Speed;

use ArvaSEO\Connector\Install\Capabilities;

defined( 'ABSPATH' ) || exit;

/** Inline contents never reach a public file without an exact administrator-approved hash. */
final class InlineAssets {

	private const MAX_BYTES = 1048576;

	/** Configuration identifiers that must never be copied into a public static artifact. */
	private const SENSITIVE = '/\b(?:[a-z0-9_]*(?:nonce|csrf|xsrf|password|secret|token|session|authorization)[a-z0-9_]*|user_id)\b/i';
	/**
	 * Exact approved printed IDs on this page.
	 *
	 * @var array<string, bool>
	 */
	private static array $approved = array();

	/**
	 * Inspect the original bodies before minification changes their approval hashes.
	 *
	 * @param string $html Complete HTML response.
	 */
	public static function prepare( string $html ): void {
		self::$approved = array();
		$position       = 0;
		$seen           = 0;
		for ( $start = strpos( $html, '<', $position ); false !== $start; $start = strpos( $html, '<', $position ) ) {
			$token = AssetCombiner::token( $html, $start );
			if ( null === $token || ++$seen > 10000 ) {
				return;
			}
			$inline = self::inspect( $token );
			if ( null !== $inline ) {
				self::note( $inline );
				if ( self::approved( $inline['id'], $inline['text'], $inline['kind'] ) ) {
					self::$approved[ $inline['id'] ] = true;
				}
			}
			$position = $token['end'];
		}
	}

	/**
	 * Ordinary inline CSS and classic scripts only, with inert/data/managed tags excluded.
	 *
	 * @param array<string, mixed> $token Complete HTML token.
	 * @return array{kind:string,id:string,text:string,open:string}|null
	 */
	public static function inspect( array $token ): ?array {
		$kind = 'style' === $token['tag'] ? 'css' : ( 'script' === $token['tag'] ? 'js' : '' );
		$text = $token['body'];
		if ( '' === $kind || $token['close'] || '' === trim( $text ) || strlen( $text ) > self::MAX_BYTES || str_contains( $text, "\0" ) || 1 !== preg_match( '//u', $text ) ) {
			return null;
		}
		$tags = new \WP_HTML_Tag_Processor( $token['open'] . '</' . $token['tag'] . '>' );
		if ( ! $tags->next_tag() || array_diff( $tags->get_attribute_names_with_prefix( '' ) ?? array(), 'css' === $kind ? array( 'id', 'type', 'media', 'nonce' ) : array( 'id', 'type', 'nonce' ) ) ) {
			return null;
		}
		$type = strtolower( (string) $tags->get_attribute( 'type' ) );
		$id   = (string) $tags->get_attribute( 'id' );
		if ( ! in_array( $type, 'css' === $kind ? array( '', 'text/css' ) : array( '', 'text/javascript', 'application/javascript' ), true ) || str_starts_with( $id, 'arvaseo-' ) || str_ends_with( $id, '-ucss' ) ) {
			return null;
		}
		$handle = (string) preg_replace( 'css' === $kind ? '/-inline-css$/' : '/-js-(?:before|after|extra)$/', '', $id );
		if ( ( 'css' === $kind && in_array( $handle, Fixes::stored()['css_exclude'], true ) ) || ( 'js' === $kind && Exclusions::leaves_script( $handle, '' ) ) ) {
			return null;
		}
		return array(
			'kind' => $kind,
			'id'   => $id,
			'text' => $text,
			'open' => $token['open'],
		);
	}

	/**
	 * Smaller body in its original tag, or null when it must remain byte-identical.
	 *
	 * @param array<string, mixed> $token HTML token.
	 */
	public static function minify( array $token ): ?string {
		$inline = self::inspect( $token );
		if ( null === $inline ) {
			return null;
		}
		$minify = Fixes::applies( 'minify_inline_' . $inline['kind'] );
		$swap   = 'css' === $inline['kind'] && Fixes::applies( 'font_display_swap' );
		if ( ! $minify && ! $swap ) {
			return null;
		}
		if ( 'css' === $inline['kind'] && preg_match( '/@import\b/i', $inline['text'] ) && preg_match( '/@layer\b/i', $inline['text'] ) ) {
			return null;
		}
		$text = $swap ? FontDisplay::swap( $inline['text'] ) : $inline['text'];
		try {
			if ( $minify ) {
				$minifier = 'css' === $inline['kind'] ? new InlineCssMinifier( $text, self::document_url() ) : new InlineJsMinifier( $text );
				$smaller  = $minifier->execute();
				if ( is_string( $smaller ) && '' !== $smaller && strlen( $smaller ) < strlen( $text ) ) {
					$text = $smaller;
				}
			}
		} catch ( \Throwable $error ) {
			return null;
		}
		if ( '' === $text || $text === $inline['text'] || false !== stripos( $text, '</' . $token['tag'] ) || str_contains( $text, "\0" ) || 1 !== preg_match( '//u', $text ) ) {
			return null;
		}
		return $token['open'] . $text . '</' . $token['tag'] . '>';
	}

	/**
	 * Exact static body approval; sensitive configuration is never eligible for publication.
	 *
	 * @param string $id Printed element ID.
	 * @param string $text Original inline body.
	 * @param string $kind css or js.
	 */
	public static function approved( string $id, string $text, string $kind ): bool {
		if ( ! in_array( $kind, array( 'css', 'js' ), true ) || ! Fixes::applies( 'combine_inline_' . $kind ) || ! preg_match( '/\A[A-Za-z][A-Za-z0-9_:.-]{0,127}\z/', $id ) || strlen( $text ) > self::MAX_BYTES || preg_match( self::SENSITIVE, $text ) ) {
			return false;
		}
		return in_array( $id . '|' . hash( 'sha256', $text ), Fixes::stored()[ 'combine_inline_' . $kind . '_ids' ] ?? array(), true );
	}

	/**
	 * Registered inline extras remain boundaries unless all their printed bodies were approved.
	 *
	 * @param string           $handle Registered handle.
	 * @param string           $kind css or js.
	 * @param \WP_Dependencies $registry WordPress asset registry.
	 */
	public static function registered_safe( string $handle, string $kind, \WP_Dependencies $registry ): bool {
		if ( $registry->get_data( $handle, 'conditional' ) ) {
			return false;
		}
		foreach ( array( 'before', 'after', 'data' ) as $extra ) {
			if ( ! $registry->get_data( $handle, $extra ) ) {
				continue;
			}
			$id = 'css' === $kind ? ( 'after' === $extra ? $handle . '-inline-css' : '' ) : $handle . '-js-' . ( 'data' === $extra ? 'extra' : $extra );
			if ( '' === $id || empty( self::$approved[ $id ] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Metadata from the current administrator's Try preview; no code bodies are retained.
	 *
	 * @param string $kind css or js.
	 * @return array<int, array{id:string,hash:string,bytes:int}>
	 */
	public static function candidates( string $kind ): array {
		if ( ! in_array( $kind, array( 'css', 'js' ), true ) || ! current_user_can( Capabilities::MANAGE ) ) {
			return array();
		}
		$items = get_transient( self::candidate_key( $kind ) );
		return is_array( $items ) ? array_values( $items ) : array();
	}

	/**
	 * Record a bounded candidate's metadata, only for its administrator's Try preview.
	 *
	 * @param array{kind:string,id:string,text:string,open:string} $inline Inline candidate.
	 */
	private static function note( array $inline ): void {
		$kind = $inline['kind'];
		if ( 'trying' !== Fixes::state( 'combine_inline_' . $kind ) || ! Fixes::applies( 'combine_inline_' . $kind ) || ! current_user_can( Capabilities::MANAGE ) || ! preg_match( '/\A[A-Za-z][A-Za-z0-9_:.-]{0,127}\z/', $inline['id'] ) || preg_match( self::SENSITIVE, $inline['text'] ) ) {
			return;
		}
		$items = self::candidates( $kind );
		$hash  = hash( 'sha256', $inline['text'] );
		foreach ( $items as $item ) {
			if ( $item['id'] === $inline['id'] && $item['hash'] === $hash ) {
				return;
			}
		}
		if ( count( $items ) < 40 ) {
			$items[] = array(
				'id'    => $inline['id'],
				'hash'  => $hash,
				'bytes' => strlen( $inline['text'] ),
			);
			set_transient( self::candidate_key( $kind ), $items, DAY_IN_SECONDS );
		}
	}

	/**
	 * Per-administrator metadata key.
	 *
	 * @param string $kind css or js.
	 */
	private static function candidate_key( string $kind ): string {
		return 'arvaseo_connector_inline_candidates_' . get_current_user_id() . '_' . $kind;
	}

	/** Inline CSS resolves URLs against the current document, without request query secrets. */
	public static function document_url(): string {
		return home_url( Exclusions::current_path() );
	}
}
