<?php
/**
 * Choices for the SEO tools (broken-address log, redirects, SEO titles and descriptions).
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Seo;

defined( 'ABSPATH' ) || exit;

/**
 * One small option, kept apart from the connection settings so nothing here can reach what is
 * shared with Arva SEO. Everything starts off. Autoloaded, because the front end reads it on
 * every request to decide whether to do anything.
 */
final class Options {

	public const OPTION = 'arvaseo_connector_seo_tools';

	/** The longest SEO title or meta description kept (what is saved; the screens suggest less). */
	public const TITLE_MAX = 120;

	public const DESCRIPTION_MAX = 320;

	/**
	 * Defaults: everything off.
	 *
	 * @return array{log_404: bool, redirects: bool, essentials: bool, home_title: string, home_description: string, rules: int}
	 */
	public static function defaults(): array {
		return array(
			'log_404'          => false,
			'redirects'        => false,
			'essentials'       => false,
			'home_title'       => '',
			'home_description' => '',
			// How many redirect rules there are, so a request needs no query when there are none.
			'rules'            => 0,
		);
	}

	/**
	 * All choices, stored values over defaults and cleaned.
	 *
	 * @return array{log_404: bool, redirects: bool, essentials: bool, home_title: string, home_description: string, rules: int}
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$clean  = self::defaults();
		foreach ( array( 'log_404', 'redirects', 'essentials' ) as $key ) {
			$clean[ $key ] = ! empty( $stored[ $key ] );
		}
		$clean['home_title']       = self::text( is_string( $stored['home_title'] ?? null ) ? $stored['home_title'] : '', self::TITLE_MAX );
		$clean['home_description'] = self::text( is_string( $stored['home_description'] ?? null ) ? $stored['home_description'] : '', self::DESCRIPTION_MAX );
		$clean['rules']            = max( 0, (int) ( $stored['rules'] ?? 0 ) );
		return $clean;
	}

	/**
	 * One choice.
	 *
	 * @param string $key Choice name.
	 * @return mixed
	 */
	public static function get( string $key ) {
		return self::all()[ $key ] ?? null;
	}

	/**
	 * Change some choices (the others stay); values are cleaned on the way in.
	 *
	 * @param array<string, mixed> $changes New values.
	 */
	public static function update( array $changes ): void {
		$data = self::all();
		foreach ( array( 'log_404', 'redirects', 'essentials' ) as $key ) {
			if ( array_key_exists( $key, $changes ) ) {
				$data[ $key ] = (bool) $changes[ $key ];
			}
		}
		if ( array_key_exists( 'home_title', $changes ) ) {
			$data['home_title'] = self::text( (string) $changes['home_title'], self::TITLE_MAX );
		}
		if ( array_key_exists( 'home_description', $changes ) ) {
			$data['home_description'] = self::text( (string) $changes['home_description'], self::DESCRIPTION_MAX );
		}
		if ( array_key_exists( 'rules', $changes ) ) {
			$data['rules'] = max( 0, (int) $changes['rules'] );
		}
		update_option( self::OPTION, $data, true );
	}

	/**
	 * Plain one-line text of at most $max characters: no tags, line breaks or doubled spaces.
	 *
	 * @param string $value Raw text.
	 * @param int    $max   Longest allowed.
	 */
	public static function text( string $value, int $max ): string {
		$value = sanitize_text_field( $value );
		if ( mb_strlen( $value, 'UTF-8' ) > $max ) {
			$value = rtrim( mb_substr( $value, 0, $max, 'UTF-8' ) );
		}
		return $value;
	}
}
