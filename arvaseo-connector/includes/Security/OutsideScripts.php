<?php
/**
 * Scripts the site's pages load from other websites (2.7.0). The scan reads a few of the site's
 * own pages as a phone visitor would, lists each outside script's host, and looks for where the
 * unfamiliar ones come from. Arva SEO checks the hosts (how new the domain is, whether it hides
 * what it does, Google's list of unsafe sites); hosts its staff confirmed as bad are flagged here
 * straight away, from the security feed.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Only host names, script addresses and where they were found leave the site, never a file.
 */
final class OutsideScripts {

	/** Hosts kept from one scan, at most. */
	public const MAX_HOSTS = 50;

	/** The finding for a host Arva SEO's staff confirmed as bad. */
	public const BAD_HOST = 'script_bad_host';

	/** Pages read, at most. */
	private const MAX_PAGES = 3;

	/** A phone's browser: injected scripts often only show to phone visitors. */
	private const PHONE = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36';

	/** Services so common that the scan doesn't look for where they come from (Arva SEO's list adds more). */
	private const COMMON = array(
		'google.com',
		'googleapis.com',
		'gstatic.com',
		'googletagmanager.com',
		'google-analytics.com',
		'googlesyndication.com',
		'doubleclick.net',
		'googleadservices.com',
		'youtube.com',
		'ytimg.com',
		'recaptcha.net',
		'facebook.net',
		'facebook.com',
		'fbcdn.net',
		'cloudflare.com',
		'cloudflareinsights.com',
		'jsdelivr.net',
		'unpkg.com',
		'jquery.com',
		'bootstrapcdn.com',
		'fontawesome.com',
		'wp.com',
		'wordpress.com',
		'gravatar.com',
		'hubspot.com',
		'hs-scripts.com',
		'hsforms.net',
		'mailchimp.com',
		'list-manage.com',
		'chimpstatic.com',
		'vimeo.com',
		'vimeocdn.com',
		'stripe.com',
		'paypal.com',
		'paypalobjects.com',
		'shopify.com',
		'klaviyo.com',
		'hotjar.com',
		'clarity.ms',
		'bing.com',
		'linkedin.com',
		'licdn.com',
		'twitter.com',
		'twimg.com',
		'x.com',
		'tiktok.com',
		'pinterest.com',
		'pinimg.com',
		'cookielaw.org',
		'onetrust.com',
		'cookiebot.com',
		'cookieyes.com',
		'termly.io',
		'iubenda.com',
		'intercom.io',
		'intercomcdn.com',
		'tawk.to',
		'zdassets.com',
		'zopim.com',
		'calendly.com',
		'typekit.net',
		'adobe.com',
		'elfsight.com',
		'trustpilot.com',
		'addtoany.com',
		'sharethis.com',
		'cdnjs.com',
		'polyfill-fastly.io',
		'jsdelivr.com',
	);

	/**
	 * Hosts whose source is looked for in the files the walk reads: host => true.
	 *
	 * @var array<string, true>
	 */
	private static array $looking = array();

	/**
	 * Where they were found in this batch: host => list of [path, line].
	 *
	 * @var array<string, array<int, array{0: string, 1: int}>>
	 */
	private static array $found = array();

	/**
	 * Read the site's pages and list the scripts they load from other websites.
	 *
	 * @return array<int, array{host: string, urls: array<int, string>, handles: array<int, string>, pages: array<int, string>, found_in: array<int, array<string, mixed>>}>
	 */
	public static function collect(): array {
		$hosts = array();
		foreach ( self::pages() as $page ) {
			$response = wp_remote_get(
				$page,
				array(
					'timeout'             => 10,
					'redirection'         => 3,
					'user-agent'          => self::PHONE,
					'headers'             => array( 'Accept' => 'text/html' ),
					'limit_response_size' => 3000000,
					'sslverify'           => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own filter for requests to itself.
				)
			);
			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				continue;
			}
			$path = (string) wp_parse_url( $page, PHP_URL_PATH );
			foreach ( self::scripts( (string) wp_remote_retrieve_body( $response ) ) as list( $url, $handle ) ) {
				$host = self::host( $url );
				if ( null === $host ) {
					continue;
				}
				$entry = $hosts[ $host ] ?? array(
					'host'     => $host,
					'urls'     => array(),
					'handles'  => array(),
					'pages'    => array(),
					'found_in' => array(),
				);
				if ( count( $entry['urls'] ) < 3 && ! in_array( $url, $entry['urls'], true ) ) {
					$entry['urls'][] = mb_substr( $url, 0, 300 );
				}
				if ( null !== $handle && count( $entry['handles'] ) < 3 && ! in_array( $handle, $entry['handles'], true ) ) {
					$entry['handles'][] = $handle;
				}
				if ( count( $entry['pages'] ) < 3 && ! in_array( '' !== $path ? $path : '/', $entry['pages'], true ) ) {
					$entry['pages'][] = '' !== $path ? $path : '/';
				}
				$hosts[ $host ] = $entry;
			}
		}
		return array_slice( array_values( $hosts ), 0, self::MAX_HOSTS );
	}

	/**
	 * The script addresses in a page, with their WordPress handle when they were enqueued: from
	 * <script src>, and from inline code that adds a script (s.src = "…", "https://….js").
	 *
	 * @param string $html Page.
	 * @return array<int, array{0: string, 1: string|null}>
	 */
	public static function scripts( string $html ): array {
		$found = array();
		if ( preg_match_all( '~<script\b([^>]*)>(.*?)</script>~is', $html, $tags, PREG_SET_ORDER ) ) {
			foreach ( $tags as $tag ) {
				$attrs = $tag[1];
				if ( preg_match( '~\bsrc\s*=\s*(["\'])(.*?)\1~i', $attrs, $src ) ) {
					$handle  = preg_match( '~\bid\s*=\s*(["\'])([a-z0-9_.\-]+)-js\1~i', $attrs, $id ) ? $id[2] : null;
					$found[] = array( html_entity_decode( trim( $src[2] ), ENT_QUOTES ), $handle );
					continue;
				}
				if ( preg_match( '~\btype\s*=\s*(["\'])(?:application/(?:ld\+)?json|text/template|text/x-template)\1~i', $attrs ) ) {
					continue;
				}
				// Inline code, with JSON's escaped slashes (https:\/\/…) read as plain ones.
				if ( preg_match_all( '~(?:\.src\s*=\s*|["\'])((?:https?:)?//[a-z0-9][a-z0-9.\-]*\.[a-z]{2,}/[^"\'\s]*?\.m?js(?:\?[^"\'\s]*)?)["\']~i', str_replace( '\\/', '/', $tag[2] ), $inline ) ) {
					foreach ( $inline[1] as $url ) {
						$found[] = array( $url, null );
					}
				}
			}
		}
		return $found;
	}

	/**
	 * Before a batch of the walk: the hosts to look for in the files it reads (the scripts that
	 * aren't from common services, and every host the feed lists as bad).
	 *
	 * @param array<string, mixed> $state The scan's state.
	 */
	public static function begin( array $state ): void {
		self::$looking = array();
		self::$found   = array();
		$known         = Feed::known_hosts();
		foreach ( (array) ( $state['scripts'] ?? array() ) as $script ) {
			$host = (string) $script['host'];
			if ( ! self::is_common( $host, $known ) ) {
				self::$looking[ $host ] = true;
			}
		}
		foreach ( array_keys( Feed::bad_hosts() ) as $host ) {
			self::$looking[ $host ] = true;
		}
	}

	/**
	 * A file the walk read: note where the hosts appear in it.
	 *
	 * @param string $contents The file.
	 * @param string $relative Path from the main folder.
	 */
	public static function look( string $contents, string $relative ): void {
		if ( array() === self::$looking || ! preg_match_all( '~(?<![a-z0-9.\-])(?:[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}(?![a-z0-9\-])~i', $contents, $names, PREG_OFFSET_CAPTURE ) ) {
			return;
		}
		foreach ( $names[0] as list( $name, $at ) ) {
			// The name itself, or the domain it's a subdomain of (cdn.example.com for example.com).
			$labels = explode( '.', strtolower( $name ) );
			for ( $i = 0, $last = count( $labels ) - 1; $i < $last; $i++ ) {
				$host = implode( '.', array_slice( $labels, $i ) );
				if ( isset( self::$looking[ $host ] ) && count( self::$found[ $host ] ?? array() ) < 3 ) {
					self::$found[ $host ][] = array( $relative, substr_count( $contents, "\n", 0, $at ) + 1 );
					break;
				}
			}
		}
	}

	/**
	 * After a batch: what it found goes into the scan's state.
	 *
	 * @param array<string, mixed> $state The scan's state.
	 * @return array<string, mixed>
	 */
	public static function end( array $state ): array {
		foreach ( self::$found as $host => $places ) {
			foreach ( $places as list( $path, $line ) ) {
				$state = self::place(
					$state,
					$host,
					array(
						'path' => $path,
						'line' => $line,
					)
				);
			}
		}
		self::$found = array();
		return $state;
	}

	/**
	 * After the walk: the database places a script can be added from (settings such as a header
	 * and footer plugin's, and code snippet posts), then a finding for each bad host found.
	 *
	 * @param array<string, mixed> $state The scan's state.
	 * @return array<string, mixed>
	 */
	public static function finish( array $state ): array {
		global $wpdb;
		$known  = Feed::known_hosts();
		$loaded = array();
		foreach ( (array) ( $state['scripts'] ?? array() ) as $script ) {
			$loaded[ (string) $script['host'] ] = true;
		}
		// The database, for the unfamiliar scripts the pages load (each search reads whole tables).
		$search = array_filter( array_keys( $loaded ), static fn ( string $host ): bool => ! self::is_common( $host, $known ) );
		foreach ( array_slice( $search, 0, 15 ) as $host ) {
			$like    = '%' . $wpdb->esc_like( $host ) . '%';
			$options = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_value LIKE %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s LIMIT 3", $like, $wpdb->esc_like( '_transient' ) . '%', $wpdb->esc_like( '_site_transient' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- a once-a-scan search.
			foreach ( (array) $options as $name ) {
				$state = self::place( $state, $host, array( 'option' => mb_substr( (string) $name, 0, 191 ) ) );
			}
			$posts = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_type FROM {$wpdb->posts} WHERE post_content LIKE %s AND post_type NOT IN ('revision', 'attachment') AND post_status <> 'trash' LIMIT 3", $like ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- a once-a-scan search.
			foreach ( (array) $posts as $post ) {
				$state = self::place(
					$state,
					$host,
					array(
						'post_id'   => (int) $post['ID'],
						'post_type' => mb_substr( (string) $post['post_type'], 0, 40 ),
					)
				);
			}
		}
		$bad = Feed::bad_hosts();
		foreach ( array_keys( $bad ) as $host ) {
			$places = (array) ( $state['script_sources'][ $host ] ?? array() );
			if ( array() === $places && ! isset( $loaded[ $host ] ) ) {
				continue;
			}
			$first               = $places[0] ?? array();
			$state['findings'][] = Findings::make(
				self::BAD_HOST,
				'critical',
				array(
					'path'    => isset( $first['path'] ) ? (string) $first['path'] : null,
					'line'    => isset( $first['line'] ) ? (int) $first['line'] : null,
					/* translators: 1: a host name, 2: what it is, such as "Malware loader". */
					'title'   => sprintf( __( 'Loads a script from %1$s (%2$s)', 'arvaseo-connector' ), $host, $bad[ $host ] ),
					'excerpt' => isset( $first['option'] ) ? 'option: ' . $first['option'] : ( isset( $first['post_id'] ) ? 'post ' . $first['post_id'] . ' (' . $first['post_type'] . ')' : null ),
					'subject' => $host,
				)
			);
		}
		self::$looking = array();
		return $state;
	}

	/**
	 * The scan's scripts with where each was found, as the results keep them.
	 *
	 * @param array<string, mixed> $state The scan's state.
	 * @return array<int, array<string, mixed>>
	 */
	public static function results( array $state ): array {
		$scripts = array();
		foreach ( (array) ( $state['scripts'] ?? array() ) as $script ) {
			$script['found_in'] = array_values( (array) ( $state['script_sources'][ $script['host'] ] ?? array() ) );
			$scripts[]          = $script;
		}
		return $scripts;
	}

	/**
	 * Whether a host is a common service, or on Arva SEO's list of known ones.
	 *
	 * @param string              $host  Host.
	 * @param array<string, true> $known Arva SEO's list.
	 */
	public static function is_common( string $host, array $known ): bool {
		foreach ( array_merge( self::COMMON, array_keys( $known ) ) as $service ) {
			if ( $host === $service || str_ends_with( $host, '.' . $service ) ) {
				return true;
			}
		}
		return false;
	}

	// ----- Helpers --------------------------------------------------------------------------

	/**
	 * Note where a host's script comes from (three places at most).
	 *
	 * @param array<string, mixed> $state The scan's state.
	 * @param string               $host  Host.
	 * @param array<string, mixed> $place path and line, option, or post_id and post_type.
	 * @return array<string, mixed>
	 */
	private static function place( array $state, string $host, array $place ): array {
		$places = (array) ( $state['script_sources'][ $host ] ?? array() );
		if ( count( $places ) < 3 && ! in_array( $place, $places, true ) ) {
			$places[]                         = $place;
			$state['script_sources'][ $host ] = $places;
		}
		return $state;
	}

	/**
	 * The pages read: the homepage, the latest post and the latest page.
	 *
	 * @return array<int, string>
	 */
	private static function pages(): array {
		$pages = array( home_url( '/' ) );
		foreach ( array( 'post', 'page' ) as $type ) {
			$latest = get_posts(
				array(
					'post_type'      => $type,
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'has_password'   => false,
					'fields'         => 'ids',
				)
			);
			$link   = array() !== $latest ? get_permalink( (int) $latest[0] ) : false;
			if ( is_string( $link ) && ! in_array( $link, $pages, true ) ) {
				$pages[] = $link;
			}
		}
		return array_slice( $pages, 0, self::MAX_PAGES );
	}

	/**
	 * A script address's host when it's another website's, or null (this site and its subdomains,
	 * relative addresses).
	 *
	 * @param string $url Address.
	 */
	private static function host( string $url ): ?string {
		$url  = str_starts_with( $url, '//' ) ? 'https:' . $url : $url;
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $host || 1 !== preg_match( '/^[a-z0-9][a-z0-9.\-]*\.[a-z]{2,}$/', $host ) ) {
			return null;
		}
		$bare = (string) preg_replace( '/^www\./', '', $host );
		$own  = (string) preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ) );
		return $bare === $own || str_ends_with( $bare, '.' . $own ) ? null : $bare;
	}
}
