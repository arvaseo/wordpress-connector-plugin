<?php
/**
 * The security scan's patterns: data shipped with the plugin (data/signatures.json), updated with
 * plugin releases. Each one is matched with preg_match; nothing in the file is ever run.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Security;

defined( 'ABSPATH' ) || exit;

/**
 * A signature has an id, the kinds of file it applies to (php, js, htaccess, svg), a severity, a
 * plain-words title, a pattern and optionally "also" patterns that must all match too (a long
 * encoded string is only suspicious when the same file also runs something). The patterns are
 * written for files that aren't official WordPress.org copies (those are checked against their
 * checksums instead), and tested against WordPress and popular plugins and themes for false alarms.
 */
final class Signatures {

	/**
	 * The signatures, compiled once per request.
	 *
	 * @var array<int, array{id: string, applies: string[], severity: string, title: string, regex: string, also: string[]}>|null
	 */
	private static ?array $compiled = null;

	/**
	 * The data file's version (shown as "patterns from 2026.10.21").
	 */
	public static function version(): string {
		$data = self::data();
		return is_string( $data['version'] ?? null ) ? $data['version'] : '';
	}

	/**
	 * The kind of file the patterns treat a path as, or null when no pattern applies.
	 *
	 * @param string $path File path or name.
	 */
	public static function kind( string $path ): ?string {
		$name = strtolower( basename( $path ) );
		if ( '.htaccess' === $name || '.user.ini' === $name ) {
			return 'htaccess';
		}
		$ext = pathinfo( $name, PATHINFO_EXTENSION );
		return match ( $ext ) {
			'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'pht', 'phar', 'inc' => 'php',
			'js', 'mjs'                                                                        => 'js',
			'svg'                                                                              => 'svg',
			default                                                                            => null,
		};
	}

	/**
	 * The first (most severe) signature a file's contents match.
	 *
	 * @param string $contents File contents.
	 * @param string $kind     php, js, htaccess or svg.
	 * @return array{id: string, severity: string, title: string, line: int, excerpt: string}|null
	 */
	public static function match( string $contents, string $kind ): ?array {
		foreach ( self::compiled() as $signature ) {
			if ( ! in_array( $kind, $signature['applies'], true ) ) {
				continue;
			}
			if ( 1 !== preg_match( $signature['regex'], $contents, $found, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}
			foreach ( $signature['also'] as $also ) {
				if ( 1 !== preg_match( $also, $contents ) ) {
					continue 2;
				}
			}
			$offset = (int) $found[0][1];
			return array(
				'id'       => $signature['id'],
				'severity' => $signature['severity'],
				'title'    => $signature['title'],
				'line'     => substr_count( $contents, "\n", 0, $offset ) + 1,
				'excerpt'  => self::excerpt( $contents, $offset ),
			);
		}
		return null;
	}

	/**
	 * The start of the matched line, single-line and short (shown escaped).
	 *
	 * @param string $contents File contents.
	 * @param int    $offset   Where the match starts.
	 */
	private static function excerpt( string $contents, int $offset ): string {
		$start = strrpos( substr( $contents, 0, $offset ), "\n" );
		$start = false === $start ? 0 : $start + 1;
		$line  = substr( $contents, $start, 400 );
		$line  = trim( (string) preg_replace( '/\s+/', ' ', strtok( $line, "\n" ) ?: $line ) );
		if ( 1 !== preg_match( '//u', $line ) ) {
			$line = (string) preg_replace( '/[^\x20-\x7E]/', '?', $line );
		}
		return mb_strlen( $line ) > 140 ? mb_substr( $line, 0, 139 ) . '…' : $line;
	}

	/**
	 * The signatures, most severe first, compiled with a delimiter.
	 *
	 * @return array<int, array{id: string, applies: string[], severity: string, title: string, regex: string, also: string[]}>
	 */
	private static function compiled(): array {
		if ( null !== self::$compiled ) {
			return self::$compiled;
		}
		$order = array(
			'critical' => 0,
			'high'     => 1,
			'medium'   => 2,
			'low'      => 3,
		);
		$list  = array();
		foreach ( (array) ( self::data()['signatures'] ?? array() ) as $raw ) {
			if ( ! is_array( $raw ) || ! is_string( $raw['pattern'] ?? null ) || ! isset( $order[ $raw['severity'] ?? '' ] ) ) {
				continue;
			}
			$flags  = preg_replace( '/[^imsxu]/', '', (string) ( $raw['flags'] ?? 'i' ) );
			$regex  = self::delimit( $raw['pattern'], $flags );
			$also   = array_map( static fn ( $p ): string => self::delimit( (string) $p, $flags ), (array) ( $raw['also'] ?? array() ) );
			$valid  = false !== @preg_match( $regex, '' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a pattern that doesn't compile is skipped, never fatal.
			$list[] = $valid ? array(
				'id'       => (string) ( $raw['id'] ?? '' ),
				'applies'  => array_map( 'strval', (array) ( $raw['applies'] ?? array() ) ),
				'severity' => (string) $raw['severity'],
				'title'    => (string) ( $raw['title'] ?? '' ),
				'regex'    => $regex,
				'also'     => $also,
			) : null;
		}
		$list = array_values( array_filter( $list ) );
		usort( $list, static fn ( array $a, array $b ): int => $order[ $a['severity'] ] <=> $order[ $b['severity'] ] );
		self::$compiled = $list;
		return $list;
	}

	/**
	 * A pattern with a delimiter and its flags.
	 *
	 * @param string $pattern Pattern without delimiters.
	 * @param string $flags   Flags.
	 */
	private static function delimit( string $pattern, string $flags ): string {
		return '~' . str_replace( '~', '\~', $pattern ) . '~' . $flags;
	}

	/**
	 * The data file.
	 *
	 * @return array<string, mixed>
	 */
	private static function data(): array {
		static $data = null;
		if ( null === $data ) {
			$raw  = file_get_contents( ARVASEO_CONNECTOR_DIR . '/data/signatures.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the plugin's own data file.
			$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
			$data = is_array( $data ) ? $data : array();
		}
		return $data;
	}
}
