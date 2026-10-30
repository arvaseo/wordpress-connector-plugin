<?php
/**
 * Bounded, administrator-requested asset inspection. Never changes page delivery.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Speed;

use ArvaSEO\Connector\Install\Capabilities;

defined( 'ABSPATH' ) || exit;

/** A private preview report; no anonymous visitor tracking or source-code retention. */
final class AssetDiagnostics {

	public const QUERY   = 'arvaseo_asset_report';
	private const PREFIX = 'arvaseo_asset_report_';

	/**
	 * Whether this request explicitly asked for a report.
	 *
	 * @var bool
	 */
	private static bool $collecting = false;

	/**
	 * Bundle members seen in this preview, queries removed.
	 *
	 * @var array<string,string[]>
	 */
	private static array $bundles = array();

	/**
	 * Record members only while an authorized preview is being rendered.
	 *
	 * @param string   $url Output URL.
	 * @param string[] $sources Original addresses.
	 */
	public static function bundle( string $url, array $sources ): void {
		if ( self::$collecting && count( self::$bundles ) < 40 ) {
			$remaining             = max( 0, 200 - array_sum( array_map( 'count', self::$bundles ) ) );
			self::$bundles[ $url ] = array_map( array( self::class, 'address' ), array_slice( $sources, 0, min( 80, $remaining ) ) );
		}
	}

	/**
	 * Start outside the optimizer buffers, only for an explicit authorized preview.
	 */
	public static function start(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) || ! isset( $_GET[ self::QUERY ] ) || ! is_string( $_GET[ self::QUERY ] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET[ self::QUERY ] ) ), self::QUERY ) ) {
			return;
		}
		self::$collecting = true;
		self::$bundles    = array();
		nocache_headers();
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- standard cache-plugin opt-out.
		}
		ob_start( array( self::class, 'capture' ) );
	}

	/** Preview link, generated only inside the authorized Speed screen. */
	public static function preview_url(): string {
		return add_query_arg( self::QUERY, wp_create_nonce( self::QUERY ), home_url( '/' ) );
	}

	/**
	 * A flushed, compressed, partial or non-HTML response is never interpreted.
	 *
	 * @param string $html Final page.
	 * @param int    $phase Buffer phase.
	 */
	public static function capture( string $html, int $phase ): string {
		if ( ! current_user_can( Capabilities::MANAGE ) || ! ( $phase & PHP_OUTPUT_HANDLER_START ) || ! ( $phase & PHP_OUTPUT_HANDLER_FINAL ) || ( $phase & PHP_OUTPUT_HANDLER_CLEAN ) || http_response_code() >= 300 ) {
			return $html;
		}
		try {
			$report = self::inspect( $html );
		} catch ( \Throwable $error ) {
			// Diagnostics must never prevent the page from reaching its administrator.
			$report = array(
				'at'       => time(),
				'rows'     => array(),
				'states'   => array(),
				'complete' => false,
			);
		}

		self::$collecting = false;
		self::$bundles    = array();
		set_transient( self::PREFIX . get_current_user_id(), $report, 15 * MINUTE_IN_SECONDS );
		return $html;
	}

	/**
	 * Only the requesting administrator can retrieve their short-lived report.
	 *
	 * @return array<string,mixed>
	 */
	public static function latest(): array {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			return array();
		}
		$report = get_transient( self::PREFIX . get_current_user_id() );
		return is_array( $report ) ? $report : array();
	}

	/**
	 * Inspect output without fetching URLs, storing inline code or creating optimized files.
	 * Reasons describe observable output; absence of a marker is not a minification failure.
	 *
	 * @param string $html Complete page.
	 * @return array<string,mixed>
	 */
	public static function inspect( string $html ): array {
		$result = array(
			'at'       => time(),
			'rows'     => array(),
			'states'   => array(),
			'complete' => false,
		);
		if ( strlen( $html ) > 4194304 || ! preg_match( '~\A(?:\xEF\xBB\xBF)?\s*(?:<!doctype\s+html\b[^>]*>\s*)?<html\b~i', $html ) || false === stripos( $html, '</html>' ) ) {
			return $result;
		}
		foreach ( array( 'combine_css', 'combine_js', 'minify_css', 'minify_js', 'minify_inline_css', 'minify_inline_js', 'critical_css', 'unused_css' ) as $fix ) {
			$result['states'][ $fix ] = Fixes::state( $fix );
		}
		$head       = false;
		$seen       = array();
		$position   = 0;
		$visited    = 0;
		$bytes_read = 0;
		for ( $start = strpos( $html, '<' ); false !== $start; $start = strpos( $html, '<', $position ) ) {
			$token = AssetCombiner::token( $html, $start );
			if ( null === $token || ++$visited > 10000 || count( $result['rows'] ) >= 200 ) {
				return $result;
			}
			$position = $token['end'];
			if ( 'head' === $token['tag'] ) {
				$head = ! $token['close'];
			}
			if ( $token['close'] || ! in_array( $token['tag'], array( 'script', 'link', 'style' ), true ) ) {
				continue;
			}
			$tags = new \WP_HTML_Tag_Processor( $token['open'] . ( in_array( $token['tag'], array( 'script', 'style' ), true ) ? '</' . $token['tag'] . '>' : '' ) );
			if ( ! $tags->next_tag() || ( 'link' === $token['tag'] && 'stylesheet' !== $tags->get_attribute( 'rel' ) ) ) {
				continue;
			}
			$kind = 'script' === $token['tag'] ? 'js' : 'css';
			$id   = (string) $tags->get_attribute( 'id' );
			$url  = '';
			foreach ( array( 'data-arvaseo-src', 'src', 'data-arvaseo-href', 'data-arvaseo-late-href', 'href' ) as $attribute ) {
				if ( is_string( $tags->get_attribute( $attribute ) ) ) {
					$url = $tags->get_attribute( $attribute );
					break;
				}
			}
			$handle = (string) preg_replace( '/-(?:js|css)$/', '', $id );
			$notes  = array();
			if ( '' === $url ) {
				$notes[] = str_ends_with( $id, '-ucss' ) ? __( 'Generated used CSS, already placed in the page.', 'arvaseo-connector' ) : __( 'Inline content; separate inline settings apply.', 'arvaseo-connector' );
			} else {
				$key = hash( 'sha256', $kind . '|' . $url );
				if ( isset( $seen[ $key ] ) ) {
					$notes[] = __( 'Repeated URL; this does not prove a second download.', 'arvaseo-connector' );
				}
				$seen[ $key ] = true;
				if ( str_contains( $url, '/' . AssetBundles::FOLDER . '/' ) ) {
					$notes[] = __( 'Combined file.', 'arvaseo-connector' );
				} elseif ( str_contains( $url, '/' . AssetMinifier::FOLDER . '/' ) ) {
					$notes[] = __( 'Arva SEO cached copy.', 'arvaseo-connector' );
				} elseif ( preg_match( '/[.-]min\.(?:css|js)$/i', (string) wp_parse_url( $url, PHP_URL_PATH ) ) ) {
					$notes[] = __( 'Already-minified filename; ordinarily left as supplied.', 'arvaseo-connector' );
				}
				// Bounded local inspection only. A skipped read is inconclusive, never a failure.
				$local = 'js' === $kind ? AssetMinifier::source( $url, 'js' ) : null;
				if ( null !== $local ) {
					$size = filesize( $local['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_filesize -- validated local asset.
					if ( false !== $size && $size > 0 && $size <= 1048576 && $bytes_read + $size <= 4194304 ) {
						$bytes_read += $size;
						$text        = file_get_contents( $local['path'], false, null, 0, $size ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- bounded validated local asset; never HTTP.
						if ( is_string( $text ) ) {
							$obstacle = AssetMinifier::script_obstacle( $text );
							if ( null !== $obstacle ) {
								$notes[] = 'amd' === $obstacle ? __( 'AMD define() detected. Only a reviewed jQuery UMD wrapper qualifies for the separate-file trial; other AMD scripts stay unchanged.', 'arvaseo-connector' ) : __( 'Module or URL-sensitive syntax detected by the conservative minifier guard.', 'arvaseo-connector' );
							}
						}
					}
				}
				if ( 'js' === $kind ) {
					foreach ( array( 'minify_js', 'combine_js' ) as $operation ) {
						if ( Exclusions::leaves_optimization( $operation, $handle, $url ) ) {
							$notes[] = 'minify_js' === $operation ? __( 'Protected by minification exclusions.', 'arvaseo-connector' ) : __( 'Protected by combination exclusions.', 'arvaseo-connector' );
						}
					}
				}
				if ( ! AssetMinifier::same_origin( $url, home_url( '/' ) ) ) {
					$notes[] = __( 'External or relative address; local-file eligibility is not established by this report.', 'arvaseo-connector' );
				}
			}
			if ( null !== $tags->get_attribute( 'integrity' ) ) {
				$notes[] = __( 'Integrity-protected; preserve original bytes and delivery.', 'arvaseo-connector' );
			}
			if ( 'js' === $kind && ( null !== $tags->get_attribute( 'async' ) || in_array( $tags->get_attribute( 'type' ), array( 'module', DelayScripts::TYPE ), true ) ) ) {
				$notes[] = __( 'Async, module or delayed execution prevents ordinary combining.', 'arvaseo-connector' );
			}
			if ( 'css' === $kind && '' !== $url && ! $head ) {
				$notes[] = __( 'Body stylesheet; outside the head-CSS pass. Eligible small files can be reviewed for full-CSS inlining in place.', 'arvaseo-connector' );
			}
			if ( in_array( $handle, HeadScripts::HANDLES, true ) ) {
				$notes[] = $head ? __( 'jQuery remains in the head; moving it may be disabled or blocked by an earlier consumer.', 'arvaseo-connector' ) : __( 'jQuery is in the body; keep it before its consumers.', 'arvaseo-connector' );
			}
			$result['rows'][] = array(
				'id'        => substr( sanitize_text_field( $id ), 0, 160 ),
				'kind'      => $kind,
				'location'  => $head ? 'head' : 'body',
				'url'       => self::address( $url ),
				'candidate' => '' !== $url && null === $tags->get_attribute( 'integrity' ) ? SafeAssetTrials::candidate( $url, $kind, $id, $head ) : null,
				'members'   => self::$bundles[ $url ] ?? array(),
				'notes'     => $notes ?: array( __( 'No transformation marker. Order, registration, syntax or generation limits may keep this file separate.', 'arvaseo-connector' ) ),
			);
		}
		$result['trial_proofs'] = SafeAssetTrials::proofs();
		$result['complete']     = true;
		return $result;
	}

	/**
	 * Drop queries, credentials and fragments, including non-web payloads.
	 *
	 * @param string $url Asset address.
	 */
	private static function address( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), array( '', 'http', 'https' ), true ) ) {
			return '';
		}
		return substr( ( isset( $parts['host'] ) ? $parts['host'] : '' ) . ( $parts['path'] ?? '' ), 0, 500 );
	}
}
