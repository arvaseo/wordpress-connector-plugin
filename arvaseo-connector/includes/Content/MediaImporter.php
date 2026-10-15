<?php
/**
 * Hardened featured-image import.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Content;

use ArvaSEO\Connector\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Downloads a featured image only when an administrator turned media import on and listed the
 * image's exact host. A signed delivery authenticates Arva SEO; it doesn't make every URL in it
 * safe, so each address is checked here:
 *
 * - https on port 443 only, no credentials in the URL, the host on the administrator's allowlist;
 * - every DNS answer must be public (no private, loopback, link-local, carrier-grade NAT, reserved
 *   or cloud metadata ranges), and the connection is pinned to the checked address;
 * - redirects are followed by hand (at most 3) and each hop is checked again;
 * - size is capped before (Content-Length) and during the download;
 * - the type comes from the file's content (JPEG, PNG, GIF, WebP only; never SVG);
 * - a file already imported (same SHA-256) is reused; temporary files are always removed.
 */
final class MediaImporter {

	public const MAX_BYTES = 8388608;

	private const TYPES = array(
		'image/jpeg' => 'jpg',
		'image/png'  => 'png',
		'image/gif'  => 'gif',
		'image/webp' => 'webp',
	);

	/**
	 * Pinned address for the request in progress.
	 *
	 * @var array{host: string, ip: string}|null
	 */
	private static ?array $pin = null;

	/**
	 * Import and set as featured image.
	 *
	 * @param string $url     Image URL.
	 * @param string $alt     Alt text.
	 * @param int    $post_id Post.
	 * @return array{status: string, reason?: string, attachment_id?: int}
	 */
	public static function import( string $url, string $alt, int $post_id ): array {
		if ( ! Settings::get( 'media_import' ) ) {
			return array(
				'status' => 'skipped',
				'reason' => 'media_import_off',
			);
		}
		$check = self::check_url( $url );
		if ( true !== $check ) {
			return array(
				'status' => 'skipped',
				'reason' => $check,
			);
		}

		$tmp = wp_tempnam( 'arvaseo-media' );
		try {
			$result = self::download( $url, $tmp );
			if ( true !== $result ) {
				return array(
					'status' => 'failed',
					'reason' => $result,
				);
			}
			$mime = function_exists( 'wp_get_image_mime' ) ? wp_get_image_mime( $tmp ) : false;
			if ( ! is_string( $mime ) || ! isset( self::TYPES[ $mime ] ) ) {
				return array(
					'status' => 'failed',
					'reason' => 'unsupported_type',
				);
			}
			$hash     = hash_file( 'sha256', $tmp );
			$existing = self::existing( (string) $hash );
			if ( $existing > 0 ) {
				set_post_thumbnail( $post_id, $existing );
				return array(
					'status'        => 'reused',
					'attachment_id' => $existing,
				);
			}

			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$base       = sanitize_file_name( pathinfo( (string) wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_FILENAME ) );
			$file       = array(
				'name'     => ( '' !== $base ? mb_substr( $base, 0, 80 ) : 'arvaseo-image' ) . '.' . self::TYPES[ $mime ],
				'tmp_name' => $tmp,
			);
			$attachment = media_handle_sideload( $file, $post_id );
			if ( is_wp_error( $attachment ) ) {
				return array(
					'status' => 'failed',
					'reason' => 'media_api_error',
				);
			}
			$tmp = ''; // Moved into uploads by WordPress.
			update_post_meta( $attachment, '_arvaseo_source_hash', $hash );
			update_post_meta( $attachment, '_wp_attachment_image_alt', mb_substr( sanitize_text_field( $alt ), 0, 200 ) );
			set_post_thumbnail( $post_id, $attachment );
			return array(
				'status'        => 'imported',
				'attachment_id' => (int) $attachment,
			);
		} finally {
			if ( '' !== $tmp && file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
		}
	}

	/**
	 * Check a URL; true or a reason code.
	 *
	 * @param string $url URL.
	 * @return true|string
	 */
	public static function check_url( string $url ) {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== strtolower( $parts['scheme'] ?? '' ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return 'unsafe_url';
		}
		if ( isset( $parts['port'] ) && 443 !== (int) $parts['port'] ) {
			return 'unsafe_url';
		}
		$host = strtolower( $parts['host'] );
		if ( ! in_array( $host, (array) Settings::get( 'media_hosts' ), true ) ) {
			return 'host_not_allowed';
		}
		$ips = self::resolve( $host );
		if ( array() === $ips ) {
			return 'dns_failed';
		}
		foreach ( $ips as $ip ) {
			if ( ! self::is_public_ip( $ip ) ) {
				return 'private_address';
			}
		}
		return true;
	}

	/**
	 * Whether an address is public (rejects private, loopback, link-local, CGNAT, reserved,
	 * documentation, multicast, metadata and IPv4-mapped/6to4 forms of those).
	 *
	 * @param string $ip Address.
	 */
	public static function is_public_ip( string $ip ): bool {
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}
		if ( str_contains( $ip, ':' ) ) {
			$packed = inet_pton( $ip );
			if ( false === $packed ) {
				return false;
			}
			// IPv4-mapped (::ffff:a.b.c.d) and NAT64 (64:ff9b::a.b.c.d): check the embedded IPv4.
			if ( str_starts_with( $packed, str_repeat( "\0", 10 ) . "\xff\xff" ) || str_starts_with( $packed, "\x00\x64\xff\x9b" . str_repeat( "\0", 8 ) ) ) {
				return self::is_public_ip( (string) inet_ntop( substr( $packed, 12 ) ) );
			}
			// 6to4 (2002::/16) embeds an IPv4 address too.
			if ( str_starts_with( $packed, "\x20\x02" ) ) {
				return self::is_public_ip( (string) inet_ntop( substr( $packed, 2, 4 ) ) );
			}
			$first = ord( $packed[0] );
			// Only global unicast (2000::/3), minus documentation 2001:db8::/32.
			if ( 0x20 !== ( $first & 0xE0 ) || str_starts_with( $packed, "\x20\x01\x0d\xb8" ) ) {
				return false;
			}
			return false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		}
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return false;
		}
		$long = ip2long( $ip );
		foreach ( array( '100.64.0.0/10', '192.0.0.0/24', '192.0.2.0/24', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/3', '169.254.0.0/16', '0.0.0.0/8', '127.0.0.0/8' ) as $cidr ) {
			list( $net, $bits ) = explode( '/', $cidr );
			$mask               = -1 << ( 32 - (int) $bits );
			if ( ( $long & $mask ) === ( ip2long( $net ) & $mask ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Download to a file, following and re-checking up to three redirects.
	 *
	 * @param string $url  URL.
	 * @param string $file Destination.
	 * @return true|string
	 */
	private static function download( string $url, string $file ) {
		for ( $hop = 0; $hop <= 3; $hop++ ) {
			$check = self::check_url( $url );
			if ( true !== $check ) {
				return $check;
			}
			$host      = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			$ips       = self::resolve( $host );
			self::$pin = array(
				'host' => $host,
				'ip'   => $ips[0],
			);
			add_action( 'http_api_curl', array( self::class, 'pin_curl' ), 10, 3 );
			$response = wp_remote_get(
				$url,
				array(
					'timeout'             => 20,
					'redirection'         => 0,
					'sslverify'           => true,
					'stream'              => true,
					'filename'            => $file,
					'limit_response_size' => self::MAX_BYTES + 1,
					'headers'             => array( 'Accept' => 'image/jpeg,image/png,image/gif,image/webp' ),
					'cookies'             => array(),
				)
			);
			remove_action( 'http_api_curl', array( self::class, 'pin_curl' ), 10 );
			self::$pin = null;
			if ( is_wp_error( $response ) ) {
				return 'download_failed';
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( $code >= 300 && $code < 400 ) {
				$location = (string) wp_remote_retrieve_header( $response, 'location' );
				if ( '' === $location ) {
					return 'download_failed';
				}
				$url = \WP_Http::make_absolute_url( $location, $url );
				continue;
			}
			if ( 200 !== $code ) {
				return 'download_failed';
			}
			$length = (int) wp_remote_retrieve_header( $response, 'content-length' );
			clearstatcache( true, $file );
			if ( $length > self::MAX_BYTES || ! file_exists( $file ) || filesize( $file ) > self::MAX_BYTES || 0 === filesize( $file ) ) {
				return 'too_large';
			}
			return true;
		}
		return 'too_many_redirects';
	}

	/**
	 * Pin cURL to the checked address (no second DNS lookup, so no rebinding).
	 *
	 * @param resource|\CurlHandle $handle cURL handle.
	 * @param array<string, mixed> $args   Request arguments.
	 * @param string               $url    URL.
	 */
	public static function pin_curl( $handle, $args, $url ): void {
		if ( null === self::$pin || strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) !== self::$pin['host'] ) {
			return;
		}
		$ip = str_contains( self::$pin['ip'], ':' ) ? '[' . self::$pin['ip'] . ']' : self::$pin['ip'];
		curl_setopt( $handle, CURLOPT_RESOLVE, array( self::$pin['host'] . ':443:' . $ip ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- pinning the connection is the point.
		curl_setopt( $handle, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- https only.
	}

	/**
	 * All A and AAAA answers.
	 *
	 * @param string $host Host.
	 * @return string[]
	 */
	private static function resolve( string $host ): array {
		$ips = array();
		$v4  = gethostbynamel( $host );
		if ( is_array( $v4 ) ) {
			$ips = $v4;
		}
		$aaaa = function_exists( 'dns_get_record' ) ? @dns_get_record( $host, DNS_AAAA ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- DNS failures are expected.
		if ( is_array( $aaaa ) ) {
			foreach ( $aaaa as $record ) {
				if ( isset( $record['ipv6'] ) ) {
					$ips[] = $record['ipv6'];
				}
			}
		}
		return array_values( array_unique( $ips ) );
	}

	/**
	 * An attachment already imported with the same content.
	 *
	 * @param string $hash SHA-256.
	 */
	private static function existing( string $hash ): int {
		// phpcs:disable WordPress.DB.SlowDBQuery -- one indexed lookup per import, to reuse identical images.
		$found = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'meta_key'       => '_arvaseo_source_hash',
				'meta_value'     => $hash,
			)
		);
		// phpcs:enable
		return isset( $found[0] ) ? (int) $found[0] : 0;
	}
}
