<?php
/**
 * Reading and writing SEO titles and meta descriptions in the SEO plugin a site uses.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Content;

use ArvaSEO\Connector\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Yoast SEO and Rank Math keep a post's SEO title and meta description in post meta, and the
 * homepage's (when it shows the latest posts) in their own options. This writes exactly those
 * fields, the ones their editors save, and nothing else in either plugin:
 *
 * - Yoast SEO: _yoast_wpseo_title and _yoast_wpseo_metadesc; the homepage's title-home-wpseo and
 *   metadesc-home-wpseo in wpseo_titles. Yoast serves pages from its own cache (indexables), which
 *   a meta write doesn't refresh, so the post's indexable is rebuilt through Yoast's own builder.
 * - Rank Math: rank_math_title and rank_math_description; the homepage's homepage_title and
 *   homepage_description in rank-math-options-titles.
 *
 * An empty value removes the post's own value, so the SEO plugin's default template applies again.
 * Page caches for the post are cleared afterwards so visitors and search engines see the change.
 */
final class SeoPlugins {

	public const SUPPORTED = array( 'yoast', 'rankmath' );

	public const NAMES = array(
		'yoast'    => 'Yoast SEO',
		'rankmath' => 'Rank Math',
	);

	private const POST_META = array(
		'yoast'    => array( '_yoast_wpseo_title', '_yoast_wpseo_metadesc' ),
		'rankmath' => array( 'rank_math_title', 'rank_math_description' ),
	);

	private const HOME_OPTIONS = array(
		'yoast'    => array( 'wpseo_titles', 'title-home-wpseo', 'metadesc-home-wpseo' ),
		'rankmath' => array( 'rank-math-options-titles', 'homepage_title', 'homepage_description' ),
	);

	/**
	 * The supported SEO plugins that are active.
	 *
	 * @return array<int, string>
	 */
	public static function active(): array {
		$active = array();
		if ( defined( 'WPSEO_VERSION' ) ) {
			$active[] = 'yoast';
		}
		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
			$active[] = 'rankmath';
		}
		$filtered = apply_filters( 'arvaseo_connector_active_seo_plugins', $active );
		return array_values( array_intersect( self::SUPPORTED, is_array( $filtered ) ? $filtered : $active ) );
	}

	/**
	 * Which plugin's fields to write: yoast or rankmath; none when neither is active (or the one
	 * chosen in Settings isn't); multiple when both are active and Settings says to detect.
	 */
	public static function resolve(): string {
		$active = self::active();
		$chosen = (string) Settings::get( 'seo_plugin' );
		if ( in_array( $chosen, self::SUPPORTED, true ) ) {
			return in_array( $chosen, $active, true ) ? $chosen : 'none';
		}
		if ( count( $active ) > 1 ) {
			return 'multiple';
		}
		return $active[0] ?? 'none';
	}

	/**
	 * The stored title and description ('' when the plugin's default applies).
	 *
	 * @param string $plugin  yoast or rankmath.
	 * @param string $target  post or home.
	 * @param int    $post_id Post (0 for home).
	 * @return array{title: string, description: string}
	 */
	public static function read( string $plugin, string $target, int $post_id ): array {
		if ( 'home' === $target ) {
			list( $option, $title_key, $description_key ) = self::HOME_OPTIONS[ $plugin ];
			$values                                       = get_option( $option, array() );
			$values                                       = is_array( $values ) ? $values : array();
			return array(
				'title'       => is_string( $values[ $title_key ] ?? null ) ? $values[ $title_key ] : '',
				'description' => is_string( $values[ $description_key ] ?? null ) ? $values[ $description_key ] : '',
			);
		}
		list( $title_key, $description_key ) = self::POST_META[ $plugin ];
		return array(
			'title'       => (string) get_post_meta( $post_id, $title_key, true ),
			'description' => (string) get_post_meta( $post_id, $description_key, true ),
		);
	}

	/**
	 * Write the values given (null leaves a field as it is).
	 *
	 * @param string      $plugin      yoast or rankmath.
	 * @param string      $target      post or home.
	 * @param int         $post_id     Post (0 for home).
	 * @param string|null $title       New SEO title ('' for the plugin's default).
	 * @param string|null $description New meta description ('' for the plugin's default).
	 * @return array<int, string> Warnings.
	 */
	public static function write( string $plugin, string $target, int $post_id, ?string $title, ?string $description ): array {
		$warnings = array();
		if ( 'home' === $target ) {
			list( $option, $title_key, $description_key ) = self::HOME_OPTIONS[ $plugin ];
			$values                                       = get_option( $option, array() );
			$values                                       = is_array( $values ) ? $values : array();
			if ( null !== $title ) {
				$values[ $title_key ] = $title;
			}
			if ( null !== $description ) {
				$values[ $description_key ] = $description;
			}
			// update_option() fires the option watchers both plugins use to refresh their caches.
			update_option( $option, $values );
			self::purge_cache( 0 );
			return $warnings;
		}

		list( $title_key, $description_key ) = self::POST_META[ $plugin ];
		foreach ( array(
			$title_key       => $title,
			$description_key => $description,
		) as $key => $value ) {
			if ( null === $value ) {
				continue;
			}
			if ( '' === $value ) {
				delete_post_meta( $post_id, $key );
			} else {
				update_post_meta( $post_id, $key, wp_slash( $value ) );
			}
		}
		if ( 'yoast' === $plugin && ! self::rebuild_yoast_indexable( $post_id ) ) {
			$warnings[] = 'yoast_cache_not_refreshed';
		}
		self::purge_cache( $post_id );
		return $warnings;
	}

	/**
	 * Have Yoast rebuild its cached copy of the post from the meta just written, the way Yoast's own
	 * post SEO data updater does. True when done (or when this Yoast version has no indexables).
	 *
	 * @param int $post_id Post.
	 */
	private static function rebuild_yoast_indexable( int $post_id ): bool {
		$builder_class    = 'Yoast\\WP\\SEO\\Builders\\Indexable_Builder';
		$repository_class = 'Yoast\\WP\\SEO\\Repositories\\Indexable_Repository';
		if ( ! function_exists( 'YoastSEO' ) || ! class_exists( $builder_class ) || ! class_exists( $repository_class ) ) {
			return ! function_exists( 'YoastSEO' );
		}
		try {
			$classes    = YoastSEO()->classes;
			$repository = $classes->get( $repository_class );
			$builder    = $classes->get( $builder_class );
			$indexable  = $repository->find_by_id_and_type( $post_id, 'post', false );
			$builder->build_for_id_and_type( $post_id, 'post', $indexable );
			return true;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Clear page caches for the post (0: the homepage) in the caching plugins that offer it, and
	 * let others hook in.
	 *
	 * @param int $post_id Post (0 for the homepage).
	 */
	public static function purge_cache( int $post_id ): void {
		// SiteGround's dynamic cache, through its Speed Optimizer plugin (2.7.0).
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			$link = $post_id > 0 ? get_permalink( $post_id ) : home_url( '/' );
			if ( is_string( $link ) ) {
				sg_cachepress_purge_cache( $link );
			}
		}
		if ( $post_id > 0 ) {
			clean_post_cache( $post_id );
			do_action( 'litespeed_purge_post', $post_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's documented purge API.
			foreach ( array( 'rocket_clean_post', 'w3tc_flush_post', 'wpsc_delete_post_cache' ) as $function ) {
				if ( function_exists( $function ) ) {
					call_user_func( $function, $post_id );
				}
			}
		} else {
			do_action( 'litespeed_purge_url', home_url( '/' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's documented purge API.
			foreach ( array( 'rocket_clean_home', 'w3tc_flush_posts' ) as $function ) {
				if ( function_exists( $function ) ) {
					call_user_func( $function );
				}
			}
		}
		/**
		 * After Arva SEO changed a post's (or the homepage's) SEO title or description.
		 *
		 * @param int $post_id Post ID, 0 for the homepage showing the latest posts.
		 */
		do_action( 'arvaseo_connector_seo_updated', $post_id );
	}
}
