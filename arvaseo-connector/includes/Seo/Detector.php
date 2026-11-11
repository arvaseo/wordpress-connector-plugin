<?php
/**
 * Which SEO plugins are active.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Seo;

defined( 'ABSPATH' ) || exit;

/**
 * The SEO plugins whose job it is to write titles, descriptions and sitemaps. When one is active,
 * Arva SEO's own SEO titles and descriptions are not offered, so a page never gets two.
 */
final class Detector {

	/**
	 * Active SEO plugins: key => name.
	 *
	 * @return array<string, string>
	 */
	public static function active(): array {
		$found = array();
		if ( defined( 'WPSEO_VERSION' ) ) {
			$found['yoast'] = 'Yoast SEO';
		}
		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
			$found['rankmath'] = 'Rank Math';
		}
		if ( defined( 'AIOSEO_VERSION' ) || function_exists( 'aioseo' ) ) {
			$found['aioseo'] = 'All in One SEO';
		}
		if ( defined( 'SEOPRESS_VERSION' ) || function_exists( 'seopress_init' ) ) {
			$found['seopress'] = 'SEOPress';
		}
		if ( defined( 'THE_SEO_FRAMEWORK_VERSION' ) || function_exists( 'the_seo_framework' ) ) {
			$found['seoframework'] = 'The SEO Framework';
		}
		/**
		 * The SEO plugins found (key => name). Add or remove one for a plugin not listed.
		 *
		 * @param array<string, string> $found Active SEO plugins.
		 */
		$filtered = apply_filters( 'arvaseo_connector_detected_seo_plugins', $found );
		return is_array( $filtered ) ? array_map( 'strval', $filtered ) : $found;
	}

	/**
	 * Whether any of them is active.
	 */
	public static function any(): bool {
		return array() !== self::active();
	}
}
