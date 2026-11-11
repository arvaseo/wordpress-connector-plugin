<?php
/**
 * The SEO checklist: ten basic checks of this site, made from what WordPress knows and one
 * request to the site's own home page. Read-only; needs no Arva SEO account; nothing leaves the site.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Seo;

defined( 'ABSPATH' ) || exit;

/**
 * Each row: id, status (pass, attention or unknown), title, why it matters, how to fix it in
 * WordPress, and a detail sentence about this site. "unknown" is only for the checks that need to
 * read the home page when the site could not be asked for it.
 */
final class Checklist {

	public const TRANSIENT = 'arvaseo_connector_seo_checklist';

	/** How long a result is kept before the screen asks the home page again. */
	public const KEEP = 15 * MINUTE_IN_SECONDS;

	/** A title this short or this long is flagged. */
	public const TITLE_MIN = 10;

	public const TITLE_MAX = 65;

	/** Images read from the home page (a sample, not every image). */
	public const IMAGE_SAMPLE = 100;

	/** The share of sampled images without alt text that still passes. */
	public const ALT_ALLOWED = 0.1;

	/** Where "See every fix in Arva SEO" goes (an ordinary link; nothing is sent by the plugin). */
	public const MORE_URL = 'https://arvaseo.com/start/?utm_source=wordpress-plugin&utm_medium=seo-checklist';

	/**
	 * The latest result, from the last 15 minutes or made now.
	 *
	 * @param bool $refresh Ask the home page again.
	 * @return array{at: int, rows: list<array<string, string>>}
	 */
	public static function results( bool $refresh = false ): array {
		$saved = $refresh ? false : get_transient( self::TRANSIENT );
		if ( is_array( $saved ) && isset( $saved['rows'], $saved['at'] ) ) {
			return $saved;
		}
		$result = array(
			'at'   => time(),
			'rows' => self::run(),
		);
		set_transient( self::TRANSIENT, $result, self::KEEP );
		return $result;
	}

	/**
	 * Make the checks now.
	 *
	 * @return list<array<string, string>>
	 */
	public static function run(): array {
		$home = self::fetch_home();
		$page = null === $home['error'] ? self::read_page( $home['body'], $home['headers'] ) : null;
		$rows = array(
			self::row_https(),
			self::row_visibility(),
			self::row_permalinks(),
			self::row_site_title(),
			self::row_home_title( $page, $home['error'] ),
			self::row_home_description( $page, $home['error'] ),
			self::row_noindex( $page, $home['error'] ),
			self::row_sitemap(),
			self::row_robots(),
			self::row_image_alt( $page, $home['error'] ),
		);
		return $rows;
	}

	/**
	 * Ask this site for its home page, once, the way a visitor would (no cookies, no login).
	 * The only request the checklist makes, and it goes to this site.
	 *
	 * @return array{error: string|null, body: string, headers: array<string, string>}
	 */
	public static function fetch_home(): array {
		$response = wp_remote_get(
			home_url( '/' ),
			array(
				'timeout'             => 10,
				'redirection'         => 3,
				'sslverify'           => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's filter for requests to the site itself.
				'user-agent'          => 'Mozilla/5.0 (compatible; ArvaSEO-SeoChecklist/' . ARVASEO_CONNECTOR_VERSION . '; +https://arvaseo.com/)',
				'headers'             => array( 'Accept' => 'text/html' ),
				'limit_response_size' => 2 * MB_IN_BYTES,
			)
		);
		if ( is_wp_error( $response ) ) {
			return self::failed( 'unreachable' );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return self::failed( 'status_' . $code );
		}
		$raw     = wp_remote_retrieve_headers( $response );
		$raw     = is_object( $raw ) && is_callable( array( $raw, 'getAll' ) ) ? (array) $raw->getAll() : (array) $raw;
		$headers = array();
		foreach ( $raw as $name => $value ) {
			$headers[ strtolower( (string) $name ) ] = implode( ', ', array_map( 'strval', (array) $value ) );
		}
		return array(
			'error'   => null,
			'body'    => (string) wp_remote_retrieve_body( $response ),
			'headers' => $headers,
		);
	}

	/**
	 * A home page that could not be read.
	 *
	 * @param string $error Why.
	 * @return array{error: string, body: string, headers: array<string, string>}
	 */
	private static function failed( string $error ): array {
		return array(
			'error'   => $error,
			'body'    => '',
			'headers' => array(),
		);
	}

	/**
	 * What the home page says: its title tags, meta description, robots rules and images.
	 *
	 * @param string                $html    The home page.
	 * @param array<string, string> $headers Response headers, lower-case names.
	 * @return array{title_count: int, title: string, description: string, noindex: bool, images: int, images_without_alt: int}
	 */
	public static function read_page( string $html, array $headers ): array {
		$end  = stripos( $html, '</head' );
		$head = false === $end ? $html : substr( $html, 0, $end );
		$out  = array(
			'title_count'        => (int) preg_match_all( '~<title\b[^>]*>~i', $head ),
			'title'              => '',
			'description'        => '',
			'noindex'            => false,
			'images'             => 0,
			'images_without_alt' => 0,
		);
		if ( preg_match( '~<title\b[^>]*>(.*?)</title>~is', $head, $m ) ) {
			$out['title'] = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ) );
		}
		if ( isset( $headers['x-robots-tag'] ) && preg_match( '/(?:^|[\s,:])noindex\b/i', $headers['x-robots-tag'] ) ) {
			$out['noindex'] = true;
		}
		$tags = new \WP_HTML_Tag_Processor( self::lower_attribute_names( $head ) );
		while ( $tags->next_tag( array( 'tag_name' => 'meta' ) ) ) {
			$name    = strtolower( (string) $tags->get_attribute( 'name' ) );
			$content = $tags->get_attribute( 'content' );
			$content = is_string( $content ) ? trim( $content ) : '';
			if ( 'description' === $name && '' === $out['description'] ) {
				$out['description'] = $content;
			}
			if ( in_array( $name, array( 'robots', 'googlebot' ), true ) && preg_match( '/(?:^|[\s,])noindex\b/i', $content ) ) {
				$out['noindex'] = true;
			}
		}
		$images = new \WP_HTML_Tag_Processor( self::lower_attribute_names( $html ) );
		while ( $out['images'] < self::IMAGE_SAMPLE && $images->next_tag( array( 'tag_name' => 'img' ) ) ) {
			if ( ( '1' === (string) $images->get_attribute( 'width' ) && '1' === (string) $images->get_attribute( 'height' ) )
				|| 'presentation' === $images->get_attribute( 'role' )
				|| 'true' === $images->get_attribute( 'aria-hidden' ) ) {
				continue; // Tracking pixels and images marked as decoration need no description.
			}
			++$out['images'];
			$alt = $images->get_attribute( 'alt' );
			if ( null === $alt || true === $alt || '' === trim( $alt ) ) {
				++$out['images_without_alt'];
			}
		}
		return $out;
	}

	/**
	 * Write the names and attribute names of meta and img tags in lower case (their values stay as they are):
	 * HTML doesn't care, but WordPress 6.5's tag processor does.
	 *
	 * @param string $html HTML.
	 */
	private static function lower_attribute_names( string $html ): string {
		return (string) preg_replace_callback(
			'~<(meta|img)\b([^>]*)>~i',
			static function ( array $tag ): string {
				$attributes = (string) preg_replace_callback(
					'~([^\s"\'<>/=]+)(\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'>]+))?~',
					static fn ( array $attribute ): string => strtolower( $attribute[1] ) . ( $attribute[2] ?? '' ),
					$tag[2]
				);
				return '<' . strtolower( $tag[1] ) . $attributes . '>';
			},
			$html
		);
	}

	// ----- The checks ---------------------------------------------------------------------

	/**
	 * Build a row.
	 *
	 * @param string $id     Check ID.
	 * @param string $status pass, attention or unknown.
	 * @param string $title  What is checked.
	 * @param string $why    Why it matters.
	 * @param string $fix    How to fix it in WordPress.
	 * @param string $detail About this site.
	 * @return array<string, string>
	 */
	private static function row( string $id, string $status, string $title, string $why, string $fix, string $detail = '' ): array {
		return array(
			'id'     => $id,
			'status' => $status,
			'title'  => $title,
			'why'    => $why,
			'fix'    => $fix,
			'detail' => $detail,
		);
	}

	/**
	 * A status from a yes or no.
	 *
	 * @param bool $ok Passed.
	 */
	private static function status( bool $ok ): string {
		return $ok ? 'pass' : 'attention';
	}

	/**
	 * The detail for a check that needs the home page, when it could not be read.
	 *
	 * @param string $error Why not.
	 */
	private static function unreadable( string $error ): string {
		if ( str_starts_with( $error, 'status_' ) ) {
			/* translators: %s: an HTTP status number such as 403. */
			return sprintf( __( 'Your site answered its own home page with status %s, so this couldn’t be read. A security plugin, a login wall or a maintenance page can cause that.', 'arvaseo-connector' ), substr( $error, 7 ) );
		}
		return __( 'Your server couldn’t open your own home page, so this couldn’t be read. Some hosts block a site from visiting itself.', 'arvaseo-connector' );
	}

	/**
	 * Is the site on HTTPS?
	 *
	 * @return array<string, string>
	 */
	private static function row_https(): array {
		$ok = str_starts_with( strtolower( home_url() ), 'https://' );
		return self::row(
			'https',
			self::status( $ok ),
			__( 'Your site uses HTTPS', 'arvaseo-connector' ),
			__( 'Browsers warn visitors about sites without HTTPS, and Google prefers secure pages.', 'arvaseo-connector' ),
			__( 'Ask your host to switch on the free SSL certificate, then start both addresses in Settings → General with https://.', 'arvaseo-connector' ),
			$ok ? __( 'Your site address starts with https://.', 'arvaseo-connector' ) : __( 'Your site address starts with http://.', 'arvaseo-connector' )
		);
	}

	/**
	 * Is "Discourage search engines" off?
	 *
	 * @return array<string, string>
	 */
	private static function row_visibility(): array {
		$ok = '0' !== (string) get_option( 'blog_public', '1' );
		return self::row(
			'visibility',
			self::status( $ok ),
			__( 'Search engines are allowed in', 'arvaseo-connector' ),
			__( 'While “Discourage search engines” is on, WordPress asks Google to stay away, so your pages may never show up in search.', 'arvaseo-connector' ),
			__( 'Go to Settings → Reading and untick “Discourage search engines from indexing this site”.', 'arvaseo-connector' ),
			$ok ? __( '“Discourage search engines” is off.', 'arvaseo-connector' ) : __( '“Discourage search engines” is on.', 'arvaseo-connector' )
		);
	}

	/**
	 * Are the permalinks readable?
	 *
	 * @return array<string, string>
	 */
	private static function row_permalinks(): array {
		$ok = '' !== (string) get_option( 'permalink_structure', '' );
		return self::row(
			'permalinks',
			self::status( $ok ),
			__( 'Page addresses are readable', 'arvaseo-connector' ),
			__( 'Addresses like ?p=123 say nothing about the page; readable ones help visitors and search engines.', 'arvaseo-connector' ),
			__( 'On a new site, go to Settings → Permalinks and choose “Post name”; on an older site, set up redirects first so old links keep working.', 'arvaseo-connector' ),
			$ok ? __( 'Your addresses use readable words.', 'arvaseo-connector' ) : __( 'Your addresses are plain (?p=123).', 'arvaseo-connector' )
		);
	}

	/**
	 * Does the site have a title?
	 *
	 * @return array<string, string>
	 */
	private static function row_site_title(): array {
		$title = trim( (string) get_option( 'blogname', '' ) );
		$ok    = '' !== $title;
		return self::row(
			'site_title',
			self::status( $ok ),
			__( 'Your site has a title', 'arvaseo-connector' ),
			__( 'The site title is the name people see in browser tabs, search results and when your site is shared.', 'arvaseo-connector' ),
			__( 'Go to Settings → General and type your Site Title.', 'arvaseo-connector' ),
			$ok ? __( 'Your site has a title.', 'arvaseo-connector' ) : __( 'Your Site Title is empty.', 'arvaseo-connector' )
		);
	}

	/**
	 * One title tag, of a sensible length.
	 *
	 * @param array<string, mixed>|null $page  What the home page says.
	 * @param string|null               $error Why it couldn't be read.
	 * @return array<string, string>
	 */
	private static function row_home_title( ?array $page, ?string $error ): array {
		$title = __( 'Your home page has one title of a sensible length', 'arvaseo-connector' );
		$why   = __( 'The title is the main line people read in Google results, so it should appear once and fit on the line.', 'arvaseo-connector' );
		$fix   = Essentials::available()
			? __( 'Set your Site Title and Tagline in Settings → General, or turn on SEO titles and descriptions further down this page.', 'arvaseo-connector' )
			: __( 'Set your Site Title and Tagline in Settings → General, or change the home page title in your SEO plugin.', 'arvaseo-connector' );
		if ( null === $page ) {
			return self::row( 'home_title', 'unknown', $title, $why, $fix, self::unreadable( (string) $error ) );
		}
		$count  = (int) $page['title_count'];
		$length = mb_strlen( (string) $page['title'], 'UTF-8' );
		if ( 1 !== $count ) {
			$detail = 0 === $count
				? __( 'Your home page has no title tag.', 'arvaseo-connector' )
				/* translators: %d: number of title tags. */
				: sprintf( __( 'Your home page has %d title tags; it should have one.', 'arvaseo-connector' ), $count );
			return self::row( 'home_title', 'attention', $title, $why, $fix, $detail );
		}
		if ( $length < self::TITLE_MIN || $length > self::TITLE_MAX ) {
			/* translators: 1: number of characters, 2: shortest sensible length, 3: longest sensible length. */
			$detail = sprintf( __( 'Your home page title is %1$d characters. Between %2$d and %3$d works best.', 'arvaseo-connector' ), $length, self::TITLE_MIN, self::TITLE_MAX );
			return self::row( 'home_title', 'attention', $title, $why, $fix, $detail );
		}
		/* translators: %d: number of characters. */
		return self::row( 'home_title', 'pass', $title, $why, $fix, sprintf( __( 'Your home page title is %d characters.', 'arvaseo-connector' ), $length ) );
	}

	/**
	 * A meta description.
	 *
	 * @param array<string, mixed>|null $page  What the home page says.
	 * @param string|null               $error Why it couldn't be read.
	 * @return array<string, string>
	 */
	private static function row_home_description( ?array $page, ?string $error ): array {
		$title = __( 'Your home page has a meta description', 'arvaseo-connector' );
		$why   = __( 'The description is the text under your title in Google results, and without one Google picks something from the page at random.', 'arvaseo-connector' );
		$fix   = Essentials::available()
			? __( 'Turn on SEO titles and descriptions further down this page and write one for your home page.', 'arvaseo-connector' )
			: __( 'Write one for the home page in your SEO plugin’s settings.', 'arvaseo-connector' );
		if ( null === $page ) {
			return self::row( 'home_description', 'unknown', $title, $why, $fix, self::unreadable( (string) $error ) );
		}
		$ok = '' !== $page['description'];
		return self::row( 'home_description', self::status( $ok ), $title, $why, $fix, $ok ? __( 'Your home page has a meta description.', 'arvaseo-connector' ) : __( 'Your home page has no meta description.', 'arvaseo-connector' ) );
	}

	/**
	 * No accidental noindex on the home page.
	 *
	 * @param array<string, mixed>|null $page  What the home page says.
	 * @param string|null               $error Why it couldn't be read.
	 * @return array<string, string>
	 */
	private static function row_noindex( ?array $page, ?string $error ): array {
		$title = __( 'Your home page isn’t hidden from search', 'arvaseo-connector' );
		$why   = __( 'A “noindex” tag tells Google to leave the page out of search results, which is rarely what you want for a home page.', 'arvaseo-connector' );
		$fix   = __( 'Check Settings → Reading, then your SEO plugin or the home page’s own settings for a “noindex” or “hide from search results” option.', 'arvaseo-connector' );
		if ( null === $page ) {
			return self::row( 'noindex', 'unknown', $title, $why, $fix, self::unreadable( (string) $error ) );
		}
		if ( ! $page['noindex'] ) {
			return self::row( 'noindex', 'pass', $title, $why, $fix, __( 'No noindex was found on your home page.', 'arvaseo-connector' ) );
		}
		$detail = '0' === (string) get_option( 'blog_public', '1' )
			? __( 'Your home page says noindex, which WordPress adds while “Discourage search engines” is on.', 'arvaseo-connector' )
			: __( 'Your home page says noindex.', 'arvaseo-connector' );
		return self::row( 'noindex', 'attention', $title, $why, $fix, $detail );
	}

	/**
	 * Is there a sitemap? Worked out from what WordPress and the files on this site say.
	 *
	 * @return array<string, string>
	 */
	private static function row_sitemap(): array {
		$title = __( 'Your site has a sitemap', 'arvaseo-connector' );
		$why   = __( 'A sitemap lists your pages so search engines find new ones sooner.', 'arvaseo-connector' );
		$fix   = __( 'WordPress makes one at /wp-sitemap.xml unless a plugin or setting turned it off; turn it back on, or use an SEO plugin to make one.', 'arvaseo-connector' );
		$found = self::sitemap_url();
		return self::row(
			'sitemap',
			self::status( null !== $found ),
			$title,
			$why,
			$fix,
			null !== $found
				/* translators: %s: the sitemap's address. */
				? sprintf( __( 'Found a sitemap at %s.', 'arvaseo-connector' ), $found )
				: __( 'No sitemap was found.', 'arvaseo-connector' )
		);
	}

	/**
	 * The sitemap's address, or null. Looks at WordPress's own sitemaps, at sitemap files in the
	 * site's folder and at a Sitemap line in robots.txt; makes no request.
	 */
	public static function sitemap_url(): ?string {
		if ( function_exists( 'wp_sitemaps_get_server' ) && '0' !== (string) get_option( 'blog_public', '1' ) ) {
			$server = wp_sitemaps_get_server();
			if ( $server->sitemaps_enabled() ) {
				return home_url( '/wp-sitemap.xml' );
			}
		}
		// Yoast and Rank Math serve their sitemap without a file, and turn WordPress's own off.
		$active = Detector::active();
		$yoast  = get_option( 'wpseo' );
		if ( isset( $active['yoast'] ) && is_array( $yoast ) && ! empty( $yoast['enable_xml_sitemap'] ) ) {
			return home_url( '/sitemap_index.xml' );
		}
		$rank_math = get_option( 'rank_math_modules' );
		if ( isset( $active['rankmath'] ) && is_array( $rank_math ) && in_array( 'sitemap', $rank_math, true ) ) {
			return home_url( '/sitemap_index.xml' );
		}
		foreach ( array( 'sitemap.xml', 'sitemap_index.xml' ) as $file ) {
			if ( is_readable( ABSPATH . $file ) ) {
				return home_url( '/' . $file );
			}
		}
		// WordPress lists its own sitemap in robots.txt even when something has turned it off, so that line proves nothing.
		if ( preg_match_all( '/^\s*sitemap:\s*(\S+)/im', self::robots_text(), $lines ) ) {
			foreach ( $lines[1] as $line ) {
				$url = esc_url_raw( $line, array( 'http', 'https' ) );
				if ( '' !== $url && untrailingslashit( $url ) !== untrailingslashit( home_url( '/wp-sitemap.xml' ) ) ) {
					return $url;
				}
			}
		}
		return null;
	}

	/**
	 * Does robots.txt let search engines in?
	 *
	 * @return array<string, string>
	 */
	private static function row_robots(): array {
		$blocks = self::robots_blocks_everything( self::robots_text() );
		return self::row(
			'robots',
			self::status( ! $blocks ),
			__( 'Your robots.txt doesn’t block everything', 'arvaseo-connector' ),
			__( 'A robots.txt with “Disallow: /” for every visitor keeps search engines out of the whole site.', 'arvaseo-connector' ),
			__( 'Remove the “Disallow: /” line from the robots.txt file in your site’s folder, and check Settings → Reading as well.', 'arvaseo-connector' ),
			$blocks ? __( 'Your robots.txt blocks every search engine from the whole site.', 'arvaseo-connector' ) : __( 'Your robots.txt doesn’t block the whole site.', 'arvaseo-connector' )
		);
	}

	/**
	 * The robots.txt this site gives out: the file in its folder, or the one WordPress makes.
	 */
	public static function robots_text(): string {
		$file = ABSPATH . 'robots.txt';
		if ( is_readable( $file ) ) {
			$text = file_get_contents( $file, false, null, 0, 65536 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file in the site's folder.
			return is_string( $text ) ? $text : '';
		}
		$public = get_option( 'blog_public', '1' );
		$text   = "User-agent: *\n";
		$text  .= '0' === (string) $public ? "Disallow: /\n" : "Disallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n";
		/** This filter is documented in wp-includes/functions.php */
		return (string) apply_filters( 'robots_txt', $text, $public ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's filter.
	}

	/**
	 * Whether a robots.txt tells every search engine to stay out of the whole site: a group for
	 * "*" with "Disallow: /" and no "Allow: /" beside it.
	 *
	 * @param string $robots The file's text.
	 */
	public static function robots_blocks_everything( string $robots ): bool {
		$groups = array();
		$agents = array();
		$in_run = false;
		foreach ( preg_split( '/\R/', $robots ) ?: array() as $line ) {
			$line = trim( (string) preg_replace( '/#.*$/', '', $line ) );
			if ( '' === $line || ! str_contains( $line, ':' ) ) {
				continue;
			}
			list( $field, $value ) = array_map( 'trim', explode( ':', $line, 2 ) );
			$field                 = strtolower( $field );
			if ( 'user-agent' === $field ) {
				if ( ! $in_run ) {
					$agents = array();
				}
				$agents[] = strtolower( $value );
				$in_run   = true;
				continue;
			}
			$in_run = false;
			if ( in_array( $field, array( 'disallow', 'allow' ), true ) ) {
				foreach ( $agents as $agent ) {
					$groups[ $agent ][ $field ][] = $value;
				}
			}
		}
		$all = $groups['*'] ?? array();
		return in_array( '/', $all['disallow'] ?? array(), true ) && ! in_array( '/', $all['allow'] ?? array(), true );
	}

	/**
	 * Images without alt text, in a sample from the home page.
	 *
	 * @param array<string, mixed>|null $page  What the home page says.
	 * @param string|null               $error Why it couldn't be read.
	 * @return array<string, string>
	 */
	private static function row_image_alt( ?array $page, ?string $error ): array {
		$title = __( 'Most images on your home page have alt text', 'arvaseo-connector' );
		$why   = __( 'Alt text describes an image for people using a screen reader, and helps Google understand it.', 'arvaseo-connector' );
		$fix   = __( 'In Media → Library, open an image and fill in “Alternative Text”, or select it in the editor and use the sidebar.', 'arvaseo-connector' );
		if ( null === $page ) {
			return self::row( 'image_alt', 'unknown', $title, $why, $fix, self::unreadable( (string) $error ) );
		}
		$total = (int) $page['images'];
		if ( 0 === $total ) {
			return self::row( 'image_alt', 'pass', $title, $why, $fix, __( 'No images were found on your home page.', 'arvaseo-connector' ) );
		}
		$without = (int) $page['images_without_alt'];
		$share   = $without / $total;
		/* translators: 1: number of images without alt text, 2: number of images looked at. */
		$detail = sprintf( __( '%1$d of the %2$d images looked at have no alt text (a sample from the home page).', 'arvaseo-connector' ), $without, $total );
		return self::row( 'image_alt', self::status( $share <= self::ALT_ALLOWED ), $title, $why, $fix, $detail );
	}

	/**
	 * How many checks passed, and how many were made.
	 *
	 * @param list<array<string, string>> $rows Rows.
	 * @return array{passed: int, attention: int, unknown: int, total: int}
	 */
	public static function summary( array $rows ): array {
		$counts = array(
			'passed'    => 0,
			'attention' => 0,
			'unknown'   => 0,
			'total'     => count( $rows ),
		);
		foreach ( $rows as $row ) {
			if ( 'pass' === $row['status'] ) {
				++$counts['passed'];
			} elseif ( 'attention' === $row['status'] ) {
				++$counts['attention'];
			} else {
				++$counts['unknown'];
			}
		}
		return $counts;
	}
}
