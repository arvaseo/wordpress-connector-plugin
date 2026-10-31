<?php
/**
 * Saved renderer measurements of first-screen animation waits, without changing the page.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Speed;

defined( 'ABSPATH' ) || exit;

/** Only identifiers and measured timings are kept; never DOM text, settings or markup. */
final class CssAnimations {

	/**
	 * Validate the small report independently of stylesheet contents.
	 *
	 * @param mixed $input API report.
	 * @return array<string,mixed>|null
	 */
	public static function clean( $input ): ?array {
		if ( ! is_array( $input ) || ! is_bool( $input['available'] ?? null ) ) {
			return null;
		}
		$rows  = array();
		$names = array();
		foreach ( array_slice( is_array( $input['names'] ?? null ) ? $input['names'] : array(), 0, 200 ) as $name ) {
			if ( is_string( $name ) && preg_match( '/^[a-z_-][a-z0-9_-]{0,99}$/iD', $name ) && ! in_array( $name, array( 'none', 'default' ), true ) ) {
				$names[ $name ] = $name;
			}
		}
		foreach ( array_slice( is_array( $input['first_screen'] ?? null ) ? $input['first_screen'] : array(), 0, 100 ) as $row ) {
			if ( ! is_array( $row ) || 'elementor' !== ( $row['builder'] ?? '' ) || ! in_array( $row['viewport'] ?? '', array( 'phone', 'computer' ), true ) || ! is_string( $row['id'] ?? null ) || ! preg_match( '/^[a-z0-9_-]{1,100}$/iD', $row['id'] ) || ! is_string( $row['name'] ?? null ) || ! preg_match( '/^[a-z_-][a-z0-9_-]{0,99}$/iD', $row['name'] ) ) {
				continue;
			}
			foreach ( array( 'delay', 'duration' ) as $field ) {
				if ( ( ! is_int( $row[ $field ] ?? null ) && ! is_float( $row[ $field ] ?? null ) ) || ! is_finite( (float) $row[ $field ] ) || $row[ $field ] < 0 || $row[ $field ] > 60000 ) {
					continue 2;
				}
			}
			$clean = array(
				'builder'  => 'elementor',
				'id'       => $row['id'],
				'name'     => $row['name'],
				'viewport' => $row['viewport'],
				'delay'    => (int) round( $row['delay'] ),
				'duration' => (int) round( $row['duration'] ),
			);
			$rows[ $row['viewport'] . '|' . $row['id'] . '|' . $row['name'] ] = $clean;
		}
		$first = array_values( $rows );
		return array(
			'available'       => $input['available'],
			'names'           => array_values( $names ),
			'first_screen'    => $first,
			'longest_wait_ms' => $input['available'] ? max( array_merge( array( 0 ), array_map( static fn ( array $row ): int => $row['delay'] + $row['duration'], $first ) ) ) : null,
		);
	}

	/**
	 * Latest reports per page, bounded for the admin screen and health snapshot.
	 *
	 * @return list<array<string,mixed>>
	 */
	public static function reports(): array {
		$index = CssOptimizer::index();
		uasort( $index, static fn ( array $a, array $b ): int => (int) ( $b['animation_at'] ?? 0 ) <=> (int) ( $a['animation_at'] ?? 0 ) );
		$out  = array();
		$seen = array();
		foreach ( $index as $entry ) {
			$url = (string) ( $entry['url'] ?? '' );
			if ( isset( $seen[ $url ] ) || 2 !== ( $entry['format'] ?? 1 ) ) {
				continue;
			}
			$report = self::clean( $entry['animations'] ?? null );
			if ( null === $report ) {
				continue;
			}
			$out[]        = array(
				'url' => $url,
				'at'  => (int) ( $entry['animation_at'] ?? 0 ),
			) + $report;
			$seen[ $url ] = true;
			if ( count( $out ) >= 10 ) {
				break;
			}
		}
		return $out;
	}
}
