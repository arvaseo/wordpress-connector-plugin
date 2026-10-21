<?php
/**
 * WordPress.org's checksums of the official files: WordPress itself, and each plugin from the
 * WordPress.org directory at the installed version.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Fetched once per version and kept for a week (they never change for a version). A plugin that
 * isn't in the directory (premium, custom or renamed) has no checksums: its files are checked by
 * pattern only, and listed as such.
 */
final class Checksums {

	/** Kept this long; a version's checksums never change. */
	private const KEEP = WEEK_IN_SECONDS;

	/**
	 * WordPress's files at the installed version, path => md5, without wp-content (the default
	 * plugins and themes there are checked as plugins and themes). Null when WordPress.org
	 * couldn't be reached or doesn't know the version (a development build).
	 *
	 * @return array<string, string>|null
	 */
	public static function core(): ?array {
		$version = (string) get_bloginfo( 'version' );
		$locale  = get_locale();
		$key     = 'arvaseo_connector_core_sums_' . md5( $version . '|' . $locale );
		$cached  = get_transient( $key );
		if ( is_array( $cached ) ) {
			return array() === $cached ? null : $cached;
		}
		$sums = self::fetch_core( $version, $locale );
		if ( null === $sums && 'en_US' !== $locale ) {
			$sums = self::fetch_core( $version, 'en_US' );
		}
		if ( null === $sums ) {
			// Not cached long: WordPress.org may only have been unreachable for a moment.
			set_transient( $key, array(), HOUR_IN_SECONDS );
			return null;
		}
		set_transient( $key, $sums, self::KEEP );
		return $sums;
	}

	/**
	 * A plugin's files at a version, path inside the plugin's folder => list of md5 values. Null
	 * when the plugin isn't in the WordPress.org directory (or WordPress.org couldn't be reached:
	 * then $reachable is false).
	 *
	 * @param string $slug      Folder name.
	 * @param string $version   Installed version.
	 * @param bool   $reachable Set to false when WordPress.org couldn't be asked.
	 * @return array<string, string[]>|null
	 */
	public static function plugin( string $slug, string $version, bool &$reachable = true ): ?array {
		$reachable = true;
		if ( '' === $slug || '' === $version || ! preg_match( '/^[a-z0-9][a-z0-9._-]*$/i', $slug ) ) {
			return null;
		}
		$key    = 'arvaseo_connector_plugin_sums_' . md5( $slug . '|' . $version );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return array() === $cached ? null : $cached;
		}
		$response = wp_remote_get(
			'https://downloads.wordpress.org/plugin-checksums/' . rawurlencode( $slug ) . '/' . rawurlencode( $version ) . '.json',
			array(
				'timeout'     => 10,
				'redirection' => 2,
			)
		);
		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) >= 500 ) {
			$reachable = false;
			return null;
		}
		$data  = 200 === wp_remote_retrieve_response_code( $response ) ? json_decode( (string) wp_remote_retrieve_body( $response ), true ) : null;
		$files = is_array( $data ) && is_array( $data['files'] ?? null ) ? $data['files'] : null;
		if ( null === $files ) {
			set_transient( $key, array(), self::KEEP );
			return null;
		}
		$sums = array();
		foreach ( $files as $path => $hashes ) {
			$md5 = is_array( $hashes ) ? ( $hashes['md5'] ?? null ) : null;
			$md5 = array_values( array_filter( (array) $md5, static fn ( $h ): bool => is_string( $h ) && 1 === preg_match( '/^[a-f0-9]{32}$/', $h ) ) );
			if ( is_string( $path ) && array() !== $md5 ) {
				$sums[ $path ] = $md5;
			}
		}
		set_transient( $key, $sums, self::KEEP );
		return array() === $sums ? null : $sums;
	}

	/**
	 * Fetch WordPress's checksums for a version and language.
	 *
	 * @param string $version Version.
	 * @param string $locale  Locale.
	 * @return array<string, string>|null
	 */
	private static function fetch_core( string $version, string $locale ): ?array {
		$response = wp_remote_get(
			add_query_arg(
				array(
					'version' => $version,
					'locale'  => $locale,
				),
				'https://api.wordpress.org/core/checksums/1.0/'
			),
			array( 'timeout' => 10 )
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || ! is_array( $data['checksums'] ?? null ) ) {
			return null;
		}
		$sums = array();
		foreach ( $data['checksums'] as $path => $md5 ) {
			if ( is_string( $path ) && is_string( $md5 ) && ! str_starts_with( $path, 'wp-content/' ) && 1 === preg_match( '/^[a-f0-9]{32}$/', $md5 ) ) {
				$sums[ $path ] = $md5;
			}
		}
		return array() === $sums ? null : $sums;
	}
}
