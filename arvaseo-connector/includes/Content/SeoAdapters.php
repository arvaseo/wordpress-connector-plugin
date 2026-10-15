<?php
/**
 * Optional SEO title and description adapters.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Content;

use ArvaSEO\Connector\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * SEO title and meta description for content delivered from Arva SEO. Off by default: an
 * administrator turns it on in Settings ("Add the SEO title and description to drafts"), and the
 * values go into the fields of the SEO plugin in use (SeoPlugins: Yoast SEO or Rank Math), the ones
 * its editor saves; nothing else in third-party plugins is written. Other plugins can add or
 * replace adapters with the arvaseo_connector_seo_adapters filter:
 * name => callable( int $post_id, string $title, string $description ): bool, where name is what
 * SeoPlugins::resolve() returns (see the arvaseo_connector_active_seo_plugins filter).
 */
final class SeoAdapters {

	/**
	 * Apply SEO data with the SEO plugin in use.
	 *
	 * @param int                  $post_id Post.
	 * @param array<string, mixed> $seo     title, description.
	 * @return string none|applied|skipped
	 */
	public static function apply( int $post_id, ?array $seo ): string {
		if ( null === $seo || ! Settings::get( 'seo_drafts' ) ) {
			return 'none';
		}
		$title       = isset( $seo['title'] ) && is_string( $seo['title'] ) ? mb_substr( sanitize_text_field( $seo['title'] ), 0, 120 ) : '';
		$description = isset( $seo['description'] ) && is_string( $seo['description'] ) ? mb_substr( sanitize_textarea_field( $seo['description'] ), 0, 300 ) : '';
		$adapters    = apply_filters(
			'arvaseo_connector_seo_adapters',
			array(
				'yoast'    => array( self::class, 'yoast' ),
				'rankmath' => array( self::class, 'rankmath' ),
			)
		);
		$plugin      = SeoPlugins::resolve();
		if ( ! is_array( $adapters ) || ! isset( $adapters[ $plugin ] ) || ! is_callable( $adapters[ $plugin ] ) ) {
			return 'skipped';
		}
		return call_user_func( $adapters[ $plugin ], $post_id, $title, $description ) ? 'applied' : 'skipped';
	}

	/**
	 * Yoast SEO's title and meta description fields.
	 *
	 * @param int    $post_id     Post.
	 * @param string $title       SEO title.
	 * @param string $description Meta description.
	 */
	public static function yoast( int $post_id, string $title, string $description ): bool {
		return self::write( 'yoast', $post_id, $title, $description );
	}

	/**
	 * Rank Math's title and description fields.
	 *
	 * @param int    $post_id     Post.
	 * @param string $title       SEO title.
	 * @param string $description Meta description.
	 */
	public static function rankmath( int $post_id, string $title, string $description ): bool {
		return self::write( 'rankmath', $post_id, $title, $description );
	}

	/**
	 * Write the non-empty values when that plugin is active.
	 *
	 * @param string $plugin      yoast or rankmath.
	 * @param int    $post_id     Post.
	 * @param string $title       SEO title.
	 * @param string $description Meta description.
	 */
	private static function write( string $plugin, int $post_id, string $title, string $description ): bool {
		if ( ! in_array( $plugin, SeoPlugins::active(), true ) ) {
			return false;
		}
		SeoPlugins::write( $plugin, 'post', $post_id, '' !== $title ? $title : null, '' !== $description ? $description : null );
		return true;
	}
}
