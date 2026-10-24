<?php
/**
 * Conservative font-face descriptor rewriting, without fetching or changing source files.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Speed;

defined( 'ABSPATH' ) || exit;

/** CSS comments, quoted strings and nested blocks are never mistaken for font descriptors. */
final class FontDisplay {

	/**
	 * Add or replace font-display in each complete font-face block. Unsupported or malformed CSS
	 * is returned intact, so a failed optimization never publishes partly rewritten styles.
	 *
	 * @param string $css Stylesheet text.
	 */
	public static function swap( string $css ): string {
		if ( ! str_contains( strtolower( $css ), '@font-face' ) || strlen( $css ) > 2097152 ) {
			return $css;
		}
		$blocks    = array();
		$stack     = array();
		$statement = 0;
		$round     = 0;
		$square    = 0;
		$length    = strlen( $css );
		for ( $i = 0; $i < $length; ++$i ) {
			$next = self::literal_end( $css, $i );
			if ( null === $next ) {
				return $css;
			}
			if ( $next !== $i ) {
				$i = $next;
				continue;
			}
			$char = $css[ $i ];
			if ( '\\' === $char ) {
				return $css; // Escaped identifiers and unquoted escapes need a full CSS parser.
			}
			if ( '(' === $char ) {
				++$round;
			} elseif ( ')' === $char ) {
				if ( --$round < 0 ) {
					return $css;
				}
			} elseif ( '[' === $char ) {
				++$square;
			} elseif ( ']' === $char ) {
				if ( --$square < 0 ) {
					return $css;
				}
			} elseif ( 0 === $round && 0 === $square ) {
				if ( '{' === $char ) {
					if ( ! empty( $stack ) && end( $stack )['font'] ) {
						return $css;
					}
					$header    = preg_replace( '~/\*.*?\*/~s', '', substr( $css, $statement, $i - $statement ) );
					$stack[]   = array(
						'font'  => 1 === preg_match( '/^\s*@font-face\s*$/i', (string) $header ),
						'start' => $i + 1,
					);
					$statement = $i + 1;
				} elseif ( '}' === $char ) {
					if ( empty( $stack ) ) {
						return $css;
					}
					$block = array_pop( $stack );
					if ( $block['font'] ) {
						$blocks[] = array( $block['start'], $i );
					}
					$statement = $i + 1;
				} elseif ( ';' === $char ) {
					$statement = $i + 1;
				}
			}
		}
		if ( ! empty( $stack ) || 0 !== $round || 0 !== $square ) {
			return $css;
		}
		$out = $css;
		foreach ( array_reverse( $blocks ) as $block ) {
			$body = self::descriptors( substr( $css, $block[0], $block[1] - $block[0] ) );
			if ( null === $body ) {
				return $css;
			}
			$out = substr_replace( $out, $body, $block[0], $block[1] - $block[0] );
		}
		return $out;
	}

	/**
	 * The last byte of a string/comment, the original byte for ordinary syntax, or null if broken.
	 *
	 * @param string $text CSS.
	 * @param int    $at   Start offset.
	 */
	private static function literal_end( string $text, int $at ): ?int {
		if ( '/*' === substr( $text, $at, 2 ) ) {
			$end = strpos( $text, '*/', $at + 2 );
			return false === $end ? null : $end + 1;
		}
		if ( '"' !== $text[ $at ] && "'" !== $text[ $at ] ) {
			return $at;
		}
		for ( $i = $at + 1, $length = strlen( $text ); $i < $length; ++$i ) {
			if ( '\\' === $text[ $i ] ) {
				++$i;
			} elseif ( $text[ $i ] === $text[ $at ] ) {
				return $i;
			} elseif ( str_contains( "\r\n\f", $text[ $i ] ) ) {
				return null;
			}
		}
		return null;
	}

	/**
	 * Font-face declarations only; preserve every unrelated descriptor byte for byte.
	 *
	 * @param string $body Block contents.
	 */
	private static function descriptors( string $body ): ?string {
		$out    = '';
		$start  = 0;
		$round  = 0;
		$square = 0;
		$found  = false;
		$length = strlen( $body );
		for ( $i = 0; $i <= $length; ++$i ) {
			if ( $i < $length ) {
				$next = self::literal_end( $body, $i );
				if ( null === $next ) {
					return null;
				}
				if ( $next !== $i ) {
					$i = $next;
					continue;
				}
				$round  += '(' === $body[ $i ] ? 1 : ( ')' === $body[ $i ] ? -1 : 0 );
				$square += '[' === $body[ $i ] ? 1 : ( ']' === $body[ $i ] ? -1 : 0 );
			}
			if ( $i < $length && ( ';' !== $body[ $i ] || 0 !== $round || 0 !== $square ) ) {
				continue;
			}
			$part  = substr( $body, $start, $i - $start );
			$plain = preg_replace( '~/\*.*?\*/~s', '', $part );
			if ( '' !== trim( (string) $plain ) ) {
				if ( ! preg_match( '/^\s*([a-z_-][a-z0-9_-]*)\s*:/i', (string) $plain, $property ) ) {
					return null;
				}
				if ( 'font-display' === strtolower( $property[1] ) ) {
					$part  = 'font-display:swap';
					$found = true;
				}
			}
			$out  .= $part . ( $i < $length ? ';' : '' );
			$start = $i + 1;
		}
		if ( ! $found ) {
			$out  = rtrim( $out );
			$out .= '' !== $out && ! str_ends_with( $out, ';' ) ? ';' : '';
			$out .= 'font-display:swap;';
		}
		return $out;
	}
}
