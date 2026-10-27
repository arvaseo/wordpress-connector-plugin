<?php
/**
 * Page caching (2.6.0): serves a saved page before WordPress loads, or starts the buffer that saves
 * the page WordPress makes. Loaded by wp-content/advanced-cache.php, which WordPress includes
 * early when WP_CACHE is on, so nothing here may use WordPress: only plain PHP.
 *
 * The plugin loads this file too (PageCache), so the address of a saved page is worked out by the
 * same code whether it's being saved or served.
 *
 * @package ArvaSEO\Connector
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'arvaseo_connector_page_cache_request' ) ) {

	/**
	 * Cookies that mean the visitor sees their own version of a page: signed in, a password for a
	 * protected post, their comment details, or a shop cart.
	 */
	define( 'ARVASEO_CONNECTOR_PAGE_CACHE_COOKIES', '/^(wordpress_logged_in_|wordpress_sec_|wp-postpass_|comment_author_|woocommerce_items_in_cart|wp_woocommerce_session_|edd_items_in_cart|arvaseo_nocache)/' );

	/** Phones, as WordPress's wp_is_mobile() sees them. */
	define( 'ARVASEO_CONNECTOR_PAGE_CACHE_PHONES', '/Mobile|Android|Silk\/|Kindle|BlackBerry|Opera Mini|Opera Mobi/' );

	/**
	 * Query parameters that only track where a visitor came from: the page is the same without them.
	 * The list is one place for the page cache and for the page addresses optimized CSS is noted
	 * under. Generic names such as campaignid and adgroupid are left out on purpose: sites use them
	 * for their own content (a developer adds more with arvaseo_connector_page_cache_ignored_params).
	 *
	 * @return string[]
	 */
	function arvaseo_connector_page_cache_tracking(): array {
		return explode(
			' ',
			// utm, and the ad networks' click and campaign IDs.
			'utm_source utm_medium utm_campaign utm_term utm_content utm_id utm_source_platform utm_creative_format utm_marketing_tactic utm_expid ' .
			'gclid gbraid wbraid gad_source gad_campaignid gclsrc dclid gadid srsltid ' .
			'fbclid fb_action_ids fb_action_types fb_source fbadid msclkid ttclid twclid li_fat_id igshid epik yclid ' .
			// Mailchimp, Klaviyo and HubSpot.
			'mc_cid mc_eid _ke _kx _hsenc _hsmi ' .
			'hsa_acc hsa_cam hsa_grp hsa_ad hsa_src hsa_tgt hsa_kw hsa_mt hsa_net hsa_ver ' .
			// Analytics: Google, Adobe, ShareASale, Matomo and Piwik PRO.
			'_ga _gl ef_id s_kwcid sscid ' .
			'mtm_campaign mtm_keyword mtm_source mtm_medium mtm_content mtm_cid mtm_group mtm_placement ' .
			'pk_campaign pk_kwd pk_keyword pk_source pk_medium pk_content pk_cid ' .
			// Kept from before: some sites tag their own links with it.
			'ref'
		);
	}

	/**
	 * The saved page this request would get, or null when it can't come from the cache: not a GET,
	 * a cookie or header that means a personal page, a query string that isn't only tracking, or
	 * an address that isn't a plain page.
	 *
	 * @param array<string, mixed>  $server  $_SERVER.
	 * @param array<string, string> $cookies $_COOKIE.
	 * @param string                $root    The cache folder.
	 * @param bool                  $phones  Phones get their own copy.
	 * @param bool                  $slash   The site's addresses end in a slash: the other form
	 *                                       is left to WordPress, which redirects it.
	 * @param string[]              $ignored More parameters that never change the page (the
	 *                                       developer filter's, written into the drop-in).
	 * @return array{dir: string, file: string, host: string, path: string}|null
	 */
	function arvaseo_connector_page_cache_request( array $server, array $cookies, string $root, bool $phones, bool $slash = true, array $ignored = array() ): ?array {
		$method = strtoupper( (string) ( $server['REQUEST_METHOD'] ?? 'GET' ) );
		if ( ( 'GET' !== $method && 'HEAD' !== $method ) || ! empty( $server['HTTP_AUTHORIZATION'] ) || ! empty( $server['PHP_AUTH_USER'] ) ) {
			return null;
		}
		foreach ( array_keys( $cookies ) as $name ) {
			if ( preg_match( ARVASEO_CONNECTOR_PAGE_CACHE_COOKIES, (string) $name ) ) {
				return null;
			}
		}
		$uri   = (string) ( $server['REQUEST_URI'] ?? '/' );
		$parts = explode( '?', $uri, 2 );
		$path  = $parts[0];
		if ( isset( $parts[1] ) && '' !== $parts[1] ) {
			parse_str( $parts[1], $query );
			if ( array() !== array_diff( array_keys( $query ), arvaseo_connector_page_cache_tracking(), $ignored ) ) {
				return null;
			}
		}
		if ( '/' !== $path && str_ends_with( $path, '/' ) !== $slash ) {
			return null;
		}
		$path = arvaseo_connector_page_cache_path( $path );
		$host = arvaseo_connector_page_cache_host( (string) ( $server['HTTP_HOST'] ?? '' ) );
		if ( null === $path || null === $host ) {
			return null;
		}
		$https  = 'on' === strtolower( (string) ( $server['HTTPS'] ?? '' ) ) || '1' === (string) ( $server['HTTPS'] ?? '' ) || '443' === (string) ( $server['SERVER_PORT'] ?? '' ) || 'https' === strtolower( (string) ( $server['HTTP_X_FORWARDED_PROTO'] ?? '' ) );
		$mobile = $phones && preg_match( ARVASEO_CONNECTOR_PAGE_CACHE_PHONES, (string) ( $server['HTTP_USER_AGENT'] ?? '' ) );
		$dir    = rtrim( $root, '/' ) . '/' . $host . $path;
		return array(
			'dir'  => $dir,
			'file' => $dir . arvaseo_connector_page_cache_name( $https, (bool) $mobile ),
			'host' => $host,
			'path' => $path,
		);
	}

	/**
	 * A page's file name: index.html for computers over HTTPS, with -http and -mobile for the rest.
	 * The .htaccess rules ask for these same names.
	 *
	 * @param bool $https  Over HTTPS.
	 * @param bool $mobile A phone, when phones get their own copy.
	 */
	function arvaseo_connector_page_cache_name( bool $https, bool $mobile ): string {
		return 'index' . ( $https ? '' : '-http' ) . ( $mobile ? '-mobile' : '' ) . '.html';
	}

	/**
	 * The path of a page that can be cached, ending in a slash, or null: never the dashboard, the
	 * REST API, PHP files, WordPress's own folders, or addresses with characters a folder can't hold.
	 *
	 * @param string $path The address's path.
	 */
	function arvaseo_connector_page_cache_path( string $path ): ?string {
		if ( '' === $path || '/' !== $path[0] || strlen( $path ) > 600 || str_contains( $path, '..' ) || str_contains( $path, '//' ) || ! preg_match( '#^[A-Za-z0-9/._~%@!$&\'()*+,;=:-]*$#', $path ) ) {
			return null;
		}
		if ( preg_match( '~(^|/)(wp-admin|wp-includes|wp-json|wp-content)(/|$)|\.php$|/wp-login|/xmlrpc|/feed/?$|/embed/?$|/trackback/?$~i', $path ) ) {
			return null;
		}
		// Files (robots.txt, sitemap.xml, favicon.ico): WordPress answers them, nothing to save.
		if ( preg_match( '~/[^/]+\.[A-Za-z0-9]{2,5}$~', $path ) && ! preg_match( '~\.html?$~i', $path ) ) {
			return null;
		}
		return str_ends_with( $path, '/' ) ? $path : $path . '/';
	}

	/**
	 * The site's name as the folder holds it (lower case, with its port if any), or null.
	 *
	 * @param string $host The Host header.
	 */
	function arvaseo_connector_page_cache_host( string $host ): ?string {
		$host = strtolower( trim( $host ) );
		return preg_match( '/^[a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?(:\d{1,5})?$/', $host ) ? $host : null;
	}

	/**
	 * Serve the saved page and stop, or start saving this one. Called by the drop-in.
	 *
	 * @param array{root: string, ttl: int, phones: bool, charset: string, slash?: bool, ignored?: string[]} $config Written into the drop-in by PageCache.
	 */
	function arvaseo_connector_page_cache_start( array $config ): void {
		if ( 'cli' === PHP_SAPI || ( defined( 'ARVASEO_CONNECTOR_DISABLE_SPEED_FIXES' ) && constant( 'ARVASEO_CONNECTOR_DISABLE_SPEED_FIXES' ) ) || ( defined( 'DONOTCACHEPAGE' ) && constant( 'DONOTCACHEPAGE' ) ) ) {
			return;
		}
		// Only requests to WordPress's own index.php: never wp-cron.php, wp-login.php or the dashboard.
		$scripts = array( basename( (string) ( $_SERVER['SCRIPT_FILENAME'] ?? '' ) ), basename( (string) ( $_SERVER['SCRIPT_NAME'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared only.
		if ( ! in_array( 'index.php', $scripts, true ) ) {
			return;
		}
		$request = arvaseo_connector_page_cache_request( $_SERVER, $_COOKIE, $config['root'], $config['phones'], $config['slash'] ?? true, array_map( 'strval', (array) ( $config['ignored'] ?? array() ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- read, checked and never output.
		if ( null === $request ) {
			return;
		}
		$file  = $request['file'];
		$saved = is_file( $file ) ? (int) filemtime( $file ) : 0;
		if ( $saved > 0 && $saved + $config['ttl'] > time() ) {
			arvaseo_connector_page_cache_send( $file, $saved, $config['charset'] );
			exit;
		}
		$GLOBALS['arvaseo_connector_page_cache_target'] = $request;
		ob_start( 'arvaseo_connector_page_cache_capture' );
	}

	/**
	 * Send a saved page, compressed when the browser takes it and the server isn't compressing.
	 *
	 * @param string $file    Saved page.
	 * @param int    $saved   When it was saved.
	 * @param string $charset The site's character set.
	 */
	function arvaseo_connector_page_cache_send( string $file, int $saved, string $charset ): void {
		$modified = gmdate( 'D, d M Y H:i:s', $saved ) . ' GMT';
		header( 'Content-Type: text/html; charset=' . $charset );
		header( 'Last-Modified: ' . $modified );
		header( 'X-Arva-Cache: hit' );
		header( 'Vary: Accept-Encoding' );
		$since = (string) ( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared only.
		if ( '' !== $since && strtotime( $since ) >= $saved ) {
			http_response_code( 304 );
			return;
		}
		$accepts = str_contains( strtolower( (string) ( $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '' ) ), 'gzip' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared only.
		if ( $accepts && is_file( $file . '.gz' ) && ! ini_get( 'zlib.output_compression' ) ) {
			$file .= '.gz';
			header( 'Content-Encoding: gzip' );
		}
		header( 'Content-Length: ' . (int) filesize( $file ) );
		if ( 'HEAD' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared only.
			readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- before WordPress loads.
		}
	}

	/**
	 * The page WordPress made, in one piece at the end: the plugin decides whether to save it. A
	 * page flushed in parts, or without the plugin running, goes out untouched and isn't saved.
	 *
	 * @param string $html  The page.
	 * @param int    $phase Output buffer phase flags.
	 */
	function arvaseo_connector_page_cache_capture( string $html, int $phase ): string {
		$whole = ( $phase & PHP_OUTPUT_HANDLER_START ) && ( $phase & PHP_OUTPUT_HANDLER_FINAL );
		$page  = $GLOBALS['arvaseo_connector_page_cache_target'] ?? null;
		if ( ! $whole || ! is_array( $page ) || ! class_exists( '\ArvaSEO\Connector\Speed\PageCache', false ) ) {
			return $html;
		}
		\ArvaSEO\Connector\Speed\PageCache::capture( $html, $page );
		return $html;
	}
}
