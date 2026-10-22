<?php
/**
 * Files that look like a hack but that well-known plugins put there on purpose: a security
 * plugin's firewall loading before WordPress, and log files that stop PHP on their first line.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Each check is strict, so a hacker can't pass for one by using the same file name: a firewall
 * loader may only load files from one of the plugins below, and a log may hold nothing PHP can
 * run. Anything else is still reported.
 */
final class KnownFiles {

	/** Plugins whose firewall loads before WordPress through PHP's auto_prepend_file setting. */
	private const FIREWALLS = array( 'wordfence', 'all-in-one-wp-security-and-firewall', 'malcare-security', 'ninjafirewall' );

	/** Calls a firewall loader may make. */
	private const LOADER_CALLS = array( 'define', 'defined', 'file_exists', 'is_file', 'is_readable', 'true', 'false', 'null' );

	/** The largest file read to the end for exits_first(). */
	private const MAX_LOG = 20000000;

	/**
	 * Whether an auto_prepend_file / auto_append_file line loads a security plugin's firewall:
	 * a file inside that plugin (NinjaFirewall), or a loader such as Wordfence's wordfence-waf.php.
	 *
	 * @param string $line The .htaccess line.
	 */
	public static function firewall_prepend( string $line ): bool {
		if ( 1 !== preg_match( '/auto_(?:prepend|append)_file\s*=?\s*(["\']?)([^"\'\s]+)\1/i', $line, $found ) ) {
			return false;
		}
		if ( 'none' === strtolower( $found[2] ) ) {
			return true; // Turns the setting off.
		}
		// Only a full path: a relative one is looked up from whichever folder the visitor's page is in.
		if ( 1 !== preg_match( '~^(?:/|[a-z]:[\\\\/])~i', $found[2] ) ) {
			return false;
		}
		$target = realpath( $found[2] );
		if ( false === $target ) {
			return false;
		}
		return null !== self::firewall_plugin( $target ) || self::firewall_loader( $target );
	}

	/**
	 * Whether a PHP file does nothing but load a security plugin's firewall: comments, constants,
	 * file checks and includes of that plugin's own files (whose official copies the scan checks).
	 * A path may be written out, or start from the loader's own folder (__DIR__ . '/wp-content/…'),
	 * as Wordfence's newer loaders do.
	 *
	 * @param string $path Absolute path.
	 */
	public static function firewall_loader( string $path ): bool {
		$size = @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- unreadable files aren't loaders.
		if ( false === $size || $size > 4096 ) {
			return false;
		}
		$code   = (string) @file_get_contents( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file read.
		$tokens = self::tokens( $code );
		$loads  = 0;
		$count  = count( $tokens );
		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];
			if ( is_string( $token ) ) {
				if ( ! in_array( $token, array( '(', ')', ';', '{', '}', ',', '!', '.' ), true ) ) {
					return false;
				}
				continue;
			}
			list( $id, $text ) = $token;
			if ( in_array( $id, array( T_OPEN_TAG, T_CLOSE_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_LNUMBER, T_DIR, T_IF, T_ELSE, T_BOOLEAN_AND, T_BOOLEAN_OR, T_IS_EQUAL, T_IS_IDENTICAL, T_IS_NOT_EQUAL, T_IS_NOT_IDENTICAL ), true ) ) {
				continue;
			}
			if ( T_INLINE_HTML === $id && '' === trim( $text ) ) {
				continue;
			}
			if ( T_STRING === $id && in_array( strtolower( $text ), self::LOADER_CALLS, true ) ) {
				continue;
			}
			if ( ! in_array( $id, array( T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE ), true ) ) {
				return false;
			}
			// What's loaded must be one literal path, or __DIR__ and one literal, inside a firewall plugin.
			$next = self::next( $tokens, $i + 1 );
			if ( '(' === ( $tokens[ $next ] ?? null ) ) {
				$next = self::next( $tokens, $next + 1 );
			}
			$path_token = $tokens[ $next ] ?? null;
			$folder     = '';
			if ( is_array( $path_token ) && T_DIR === $path_token[0] ) {
				$dot = self::next( $tokens, $next + 1 );
				if ( '.' !== ( $tokens[ $dot ] ?? null ) ) {
					return false;
				}
				$next       = self::next( $tokens, $dot + 1 );
				$path_token = $tokens[ $next ] ?? null;
				$folder     = dirname( $path );
			}
			if ( ! is_array( $path_token ) || T_CONSTANT_ENCAPSED_STRING !== $path_token[0] ) {
				return false;
			}
			$after = $tokens[ self::next( $tokens, $next + 1 ) ] ?? null;
			if ( ! in_array( $after, array( ';', ')' ), true ) && ! ( is_array( $after ) && T_CLOSE_TAG === $after[0] ) ) {
				return false;
			}
			$loaded = realpath( $folder . substr( $path_token[1], 1, -1 ) );
			if ( false === $loaded || null === self::firewall_plugin( $loaded ) ) {
				return false;
			}
			++$loads;
			$i = $next;
		}
		return $loads > 0;
	}

	/**
	 * Whether a PHP file stops on its first line and holds nothing PHP could run: how plugins keep
	 * their logs private (Mailchimp for WordPress's debug-log.php starts with "<?php exit; ?>").
	 * The rest must be text with no further PHP tags, and nothing that makes it an archive PHP can
	 * open (phar, zip, tar).
	 *
	 * @param string $path Absolute path.
	 */
	public static function exits_first( string $path ): bool {
		$size = @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- unreadable files aren't logs.
		if ( false === $size || $size > self::MAX_LOG ) {
			return false;
		}
		$handle = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- reading a local file.
		if ( false === $handle ) {
			return false;
		}
		$head  = (string) fread( $handle, 4096 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- see above.
		$first = self::first_statement_exits( $head );
		$text  = $head; // Checked for archive markers, from the first byte.
		$code  = null !== $first ? substr( $head, $first ) : ''; // Checked for PHP tags, after the exit.
		$clean = null !== $first;
		while ( $clean ) {
			if ( str_contains( $text, "\0" ) || 1 === preg_match( '/__halt_compiler|ustar|PK\x03\x04|PK\x05\x06/i', $text ) || 1 === preg_match( '/<\?(?:php|=)/i', $code ) ) {
				$clean = false;
				break;
			}
			if ( feof( $handle ) ) {
				break;
			}
			// Keep the end of the last chunk, so a marker split between two reads is still seen.
			$chunk = (string) fread( $handle, 65536 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- see above.
			$text  = substr( $text, -20 ) . $chunk;
			$code  = substr( $code, -20 ) . $chunk;
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
		return $clean;
	}

	/**
	 * Where the code after a first "exit;" or "die;" ends (the offset after it), when that's the
	 * first thing PHP runs; null otherwise.
	 *
	 * @param string $head The file's first bytes.
	 */
	private static function first_statement_exits( string $head ): ?int {
		$tokens = self::tokens( $head );
		$offset = 0;
		$count  = count( $tokens );
		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];
			if ( is_array( $token ) && in_array( $token[0], array( T_INLINE_HTML, T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				$offset += strlen( $token[1] );
				continue;
			}
			if ( ! is_array( $token ) || T_EXIT !== $token[0] ) {
				return null;
			}
			$offset += strlen( $token[1] );
			// exit, exit(), or exit with one literal ('Access denied', 0): nothing that runs code.
			for ( $j = $i + 1; $j < $count; $j++ ) {
				$part    = $tokens[ $j ];
				$text    = is_array( $part ) ? $part[1] : $part;
				$offset += strlen( $text );
				if ( ';' === $part || ( is_array( $part ) && T_CLOSE_TAG === $part[0] ) ) {
					return $offset;
				}
				if ( ! in_array( $part, array( '(', ')' ), true ) && ! ( is_array( $part ) && in_array( $part[0], array( T_WHITESPACE, T_CONSTANT_ENCAPSED_STRING, T_LNUMBER ), true ) ) ) {
					return null;
				}
			}
			return null;
		}
		return null;
	}

	/**
	 * The firewall plugin a file belongs to, when it's inside one that's installed.
	 *
	 * @param string $real A resolved path.
	 */
	private static function firewall_plugin( string $real ): ?string {
		$plugins = realpath( WP_PLUGIN_DIR );
		if ( false === $plugins ) {
			return null;
		}
		foreach ( self::FIREWALLS as $slug ) {
			if ( str_starts_with( $real, $plugins . DIRECTORY_SEPARATOR . $slug . DIRECTORY_SEPARATOR ) && is_file( $real ) ) {
				return $slug;
			}
		}
		return null;
	}

	/**
	 * PHP's tokens for some code (an empty list when it can't be read).
	 *
	 * @param string $code PHP code.
	 * @return array<int, array{0: int, 1: string, 2: int}|string>
	 */
	private static function tokens( string $code ): array {
		try {
			return token_get_all( $code );
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	/**
	 * The index of the next token that isn't whitespace or a comment.
	 *
	 * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens Tokens.
	 * @param int                                                 $from   Where to start.
	 */
	private static function next( array $tokens, int $from ): int {
		$count = count( $tokens );
		while ( $from < $count && is_array( $tokens[ $from ] ) && in_array( $tokens[ $from ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
			++$from;
		}
		return $from;
	}
}
