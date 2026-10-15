<?php
/**
 * Sanitizing delivered content.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Content;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps only allowlisted core blocks and a small set of HTML tags and attributes. Scripts, event
 * handlers, iframes, embeds, shortcodes, forms and unknown blocks are removed; links may only be
 * http, https or mailto. WordPress's own kses filters run again when the post is saved.
 */
final class ContentSanitizer {

	/** Core blocks Arva SEO may send. */
	public const BLOCKS = array( 'core/paragraph', 'core/heading', 'core/list', 'core/list-item', 'core/quote', 'core/table', 'core/separator', 'core/code' );

	/**
	 * Allowed HTML.
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function allowed_html(): array {
		$text = array();
		$cls  = array( 'class' => true );
		return array(
			'p'          => $text,
			'h2'         => $cls,
			'h3'         => $cls,
			'h4'         => $cls,
			'h5'         => $cls,
			'h6'         => $cls,
			'ul'         => $cls,
			'ol'         => $cls,
			'li'         => $text,
			'a'          => array(
				'href'  => true,
				'title' => true,
			),
			'strong'     => $text,
			'em'         => $text,
			'b'          => $text,
			'i'          => $text,
			'code'       => $text,
			'pre'        => $cls,
			'blockquote' => $cls,
			'figure'     => $cls,
			'table'      => $text,
			'thead'      => $text,
			'tbody'      => $text,
			'tr'         => $text,
			'th'         => $text,
			'td'         => $text,
			'hr'         => $cls,
			'br'         => $text,
			'sup'        => $text,
			'sub'        => $text,
			'del'        => $text,
		);
	}

	/**
	 * Clean block markup.
	 *
	 * @param string   $content  Block markup.
	 * @param string[] $warnings Collects warning codes.
	 */
	public static function content( string $content, array &$warnings ): string {
		$blocks = parse_blocks( $content );
		$kept   = self::filter_blocks( $blocks, $warnings );
		$markup = serialize_blocks( $kept );
		// Shortcodes are text here, never executed: neutralise their brackets.
		$markup = preg_replace( '/\[(\/?[a-zA-Z0-9_-]+)([^\]]*)\]/', '&#91;$1$2&#93;', $markup ) ?? '';
		if ( preg_match( '/<\s*(script|iframe|style|object|embed|form|input|svg|math)\b|\son[a-z]+\s*=|javascript:/i', $markup ) ) {
			$warnings[] = 'markup_removed';
		}
		return wp_kses( $markup, self::allowed_html(), array( 'http', 'https', 'mailto' ) );
	}

	/**
	 * Keep only allowed blocks (recursively). Freeform (classic) content that is only whitespace
	 * is kept; anything else unrecognised is dropped with a warning.
	 *
	 * @param array<int, array<string, mixed>> $blocks   Parsed blocks.
	 * @param string[]                         $warnings Warnings.
	 * @return array<int, array<string, mixed>>
	 */
	private static function filter_blocks( array $blocks, array &$warnings ): array {
		$kept = array();
		foreach ( $blocks as $block ) {
			$name = $block['blockName'] ?? null;
			if ( null === $name ) {
				if ( '' === trim( (string) ( $block['innerHTML'] ?? '' ) ) ) {
					$kept[] = $block;
				} else {
					$warnings[] = 'freeform_removed';
				}
				continue;
			}
			if ( ! in_array( $name, self::BLOCKS, true ) ) {
				$warnings[] = 'blocks_removed';
				continue;
			}
			$block['attrs'] = self::attrs( $name, (array) ( $block['attrs'] ?? array() ) );
			if ( ! empty( $block['innerBlocks'] ) ) {
				// Keep innerContent's placeholders (null) in step with the inner blocks that remain.
				$inner   = array();
				$content = array();
				$index   = 0;
				foreach ( (array) $block['innerContent'] as $chunk ) {
					if ( null !== $chunk ) {
						$content[] = $chunk;
						continue;
					}
					$child = self::filter_blocks( array( $block['innerBlocks'][ $index++ ] ?? array() ), $warnings );
					if ( array() !== $child ) {
						$inner[]   = $child[0];
						$content[] = null;
					}
				}
				$block['innerBlocks']  = $inner;
				$block['innerContent'] = $content;
			}
			$kept[] = $block;
		}
		return $kept;
	}

	/**
	 * Only the attributes these blocks need.
	 *
	 * @param string               $name  Block name.
	 * @param array<string, mixed> $attrs Attributes.
	 * @return array<string, mixed>
	 */
	private static function attrs( string $name, array $attrs ): array {
		if ( 'core/heading' === $name ) {
			$level = (int) ( $attrs['level'] ?? 2 );
			return 2 === $level ? array() : array( 'level' => max( 2, min( 6, $level ) ) );
		}
		if ( 'core/list' === $name && ! empty( $attrs['ordered'] ) ) {
			return array( 'ordered' => true );
		}
		return array();
	}

	/**
	 * A plain-text title.
	 *
	 * @param string $title Title.
	 */
	public static function title( string $title ): string {
		return mb_substr( sanitize_text_field( $title ), 0, 200 );
	}

	/**
	 * A plain-text excerpt.
	 *
	 * @param string|null $excerpt Excerpt.
	 */
	public static function excerpt( ?string $excerpt ): string {
		return mb_substr( sanitize_textarea_field( (string) $excerpt ), 0, 300 );
	}
}
