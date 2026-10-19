<?php
/**
 * Leave things alone: the pages, scripts, images and frames the PageSpeed fixes skip.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Speed;

use ArvaSEO\Connector\Install\Capabilities;
use ArvaSEO\Connector\Logging\AuditLogger;

defined( 'ABSPATH' ) || exit;

/**
 * Pages: addresses with * wildcards, a tick box on a post or page, the toolbar on any page, and
 * shop pages for Defer scripts. Scripts: handles, parts of file addresses, or every script from a
 * plugin. Images and frames: a CSS class, an id or part of the file address, on the element or on
 * a block around it, plus the skip markers other speed plugins use. Developers get three filters.
 */
final class Exclusions {

	/** Post meta: this post or page is left alone. */
	public const META = '_arvaseo_speed_skip';

	/** At most this many entries in each list, of at most this many characters. */
	public const MAX_LINES = 100;

	public const MAX_LENGTH = 200;

	/** Classes and attributes other lazy-loading plugins use to leave an image alone. Lazy loading only. */
	public const SKIP_LAZY_CLASSES = array( 'skip-lazy', 'no-lazy', 'no-lazyload', 'lazyload-disabled' );

	public const SKIP_LAZY_ATTRIBUTES = array( 'data-skip-lazy', 'data-no-lazy' );

	/** Our own marker: every fix leaves the element alone. */
	public const SKIP_ATTRIBUTE = 'data-arvaseo-skip';

	/** The fixes that change images and frames. */
	public const ELEMENT_FIXES = array( 'lazy_media', 'image_dimensions', 'video_facades', 'webp_uploads' );

	/** The fixes shop pages are left alone by. */
	public const SHOP_FIXES = array( 'defer_scripts', 'delay_scripts' );

	/**
	 * Whether this page is left alone, by request: the request and the queried object.
	 *
	 * @var array<string, string|null>
	 */
	private static array $page_reasons = array();

	/**
	 * Blocks being rendered that leave everything inside them alone.
	 *
	 * @var int
	 */
	private static int $skip_depth = 0;

	/**
	 * Blocks being rendered that leave lazy loading alone (other plugins' markers).
	 *
	 * @var int
	 */
	private static int $skip_lazy_depth = 0;

	/**
	 * Hooks: the tick box on posts and pages, and the toolbar's "Leave this page alone".
	 */
	public static function init(): void {
		add_action( 'add_meta_boxes', array( self::class, 'add_meta_box' ) );
		add_action( 'save_post', array( self::class, 'save_meta_box' ) );
		add_action( 'admin_post_arvaseo_connector_speed_page', array( self::class, 'toolbar_toggle' ) );
	}

	/**
	 * A new page: forget what was worked out for the last one.
	 */
	public static function reset(): void {
		self::$page_reasons    = array();
		self::$skip_depth      = 0;
		self::$skip_lazy_depth = 0;
	}

	// ----- Lists -------------------------------------------------------------------------------

	/**
	 * Entries from a text box: one per line (or comma separated), trimmed, without duplicates,
	 * at most MAX_LINES of MAX_LENGTH characters.
	 *
	 * @param string $text The text box.
	 * @return string[]
	 */
	public static function lines( string $text ): array {
		$out = array();
		foreach ( preg_split( '/[\r\n,]+/', $text ) as $line ) {
			$line = trim( wp_strip_all_tags( (string) $line ), " \t\"'" );
			if ( '' === $line || strlen( $line ) > self::MAX_LENGTH ) {
				continue;
			}
			$out[] = $line;
		}
		return array_slice( array_values( array_unique( $out ) ), 0, self::MAX_LINES );
	}

	/**
	 * Page addresses as the site's own paths: "/checkout/", "/book-now/*". A full address on this
	 * site is cut to its path; another site's address is dropped.
	 *
	 * @param string $text The text box.
	 * @return string[]
	 */
	public static function page_lines( string $text ): array {
		$host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$out  = array();
		foreach ( self::lines( $text ) as $line ) {
			$line = (string) preg_replace( '/\s+/', '', $line );
			if ( preg_match( '~^(?:https?:)?//~i', $line ) ) {
				$url = wp_parse_url( $line );
				if ( ! is_array( $url ) || strtolower( (string) ( $url['host'] ?? '' ) ) !== $host ) {
					continue;
				}
				$line = self::relative_path( (string) ( $url['path'] ?? '/' ) );
			}
			$path  = explode( '#', explode( '?', $line )[0] )[0];
			$out[] = '/' . ltrim( (string) preg_replace( '#[^\p{L}\p{N}\-._~/%*+:@!$&()=;]#u', '', $path ), '/' );
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Script entries: a handle (lower case, as WordPress registers it) or part of a file address
	 * (anything with a / or a dot).
	 *
	 * @param string $text The text box.
	 * @return string[]
	 */
	public static function script_lines( string $text ): array {
		$out = array();
		foreach ( self::lines( $text ) as $line ) {
			$line  = (string) preg_replace( '/\s+/', '', $line );
			$out[] = self::is_file_part( $line ) ? $line : sanitize_key( $line );
		}
		return array_values( array_unique( array_filter( $out ) ) );
	}

	/**
	 * Image and frame entries: ".class", "#id" or part of a file address.
	 *
	 * @param string $text The text box.
	 * @return string[]
	 */
	public static function element_lines( string $text ): array {
		$out = array();
		foreach ( self::lines( $text ) as $line ) {
			if ( preg_match( '/^([.#])([A-Za-z0-9_-]+)$/', $line, $m ) ) {
				$out[] = $m[1] . $m[2];
			} elseif ( self::is_file_part( $line ) && ! preg_match( '/\s/', $line ) ) {
				$out[] = $line;
			} elseif ( preg_match( '/^[A-Za-z][A-Za-z0-9_-]*$/', $line ) ) {
				$out[] = '.' . $line; // A bare word is most likely a class.
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Whether an entry is part of a file address rather than a name.
	 *
	 * @param string $entry Entry.
	 */
	private static function is_file_part( string $entry ): bool {
		return str_contains( $entry, '/' ) || ( str_contains( $entry, '.' ) && ! str_starts_with( $entry, '.' ) );
	}

	// ----- Pages -------------------------------------------------------------------------------

	/**
	 * The page being viewed, as a path on the site ("/" for the homepage).
	 */
	public static function current_path(): string {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		return self::relative_path( (string) wp_parse_url( $uri, PHP_URL_PATH ) );
	}

	/**
	 * A path without the folder WordPress lives in, if it lives in one.
	 *
	 * @param string $path Path.
	 */
	private static function relative_path( string $path ): string {
		$base = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( '' !== $base && str_starts_with( $path, $base ) ) {
			$path = substr( $path, strlen( $base ) );
		}
		return '/' . ltrim( rawurldecode( $path ), '/' );
	}

	/**
	 * Whether a path matches an entry: the same page with or without the closing slash, or a
	 * wildcard match.
	 *
	 * @param string $path  Path.
	 * @param string $entry Entry.
	 */
	public static function path_matches( string $path, string $entry ): bool {
		$path  = strtolower( untrailingslashit( $path ) );
		$entry = strtolower( untrailingslashit( $entry ) );
		if ( ! str_contains( $entry, '*' ) ) {
			return $path === $entry;
		}
		$pattern = '~^' . str_replace( '\*', '.*', preg_quote( $entry, '~' ) ) . '$~';
		return 1 === preg_match( $pattern, $path ) || 1 === preg_match( $pattern, $path . '/' );
	}

	/**
	 * Why this page is left alone by a fix, or null when it isn't: "post" (its tick box),
	 * "address" (the list), "shop" (shop pages, for Defer scripts).
	 *
	 * @param string $fix Fix ID.
	 */
	public static function page_reason( string $fix ): ?string {
		$key = self::current_path() . '|' . get_queried_object_id();
		if ( ! array_key_exists( $key, self::$page_reasons ) ) {
			self::$page_reasons[ $key ] = self::own_reason();
		}
		if ( null !== self::$page_reasons[ $key ] ) {
			return self::$page_reasons[ $key ];
		}
		if ( in_array( $fix, self::SHOP_FIXES, true ) && Fixes::stored()['shop_pages'] && self::is_shop_page() ) {
			return 'shop';
		}
		return null;
	}

	/**
	 * Left alone by its tick box or the list of addresses.
	 */
	private static function own_reason(): ?string {
		$post_id = is_singular() ? get_queried_object_id() : 0;
		if ( $post_id > 0 && '1' === get_post_meta( $post_id, self::META, true ) ) {
			return 'post';
		}
		$path = self::current_path();
		foreach ( Fixes::stored()['exclude_pages'] as $entry ) {
			if ( self::path_matches( $path, $entry ) ) {
				return 'address';
			}
		}
		return null;
	}

	/**
	 * A cart, checkout or account page of WooCommerce or Easy Digital Downloads.
	 */
	public static function is_shop_page(): bool {
		if ( function_exists( 'is_cart' ) && function_exists( 'is_checkout' ) && function_exists( 'is_account_page' ) && ( is_cart() || is_checkout() || is_account_page() ) ) {
			return true;
		}
		return function_exists( 'edd_is_checkout' ) && edd_is_checkout();
	}

	/**
	 * Whether WooCommerce or Easy Digital Downloads is active.
	 */
	public static function has_shop(): bool {
		return class_exists( 'WooCommerce' ) || function_exists( 'EDD' ) || function_exists( 'edd_is_checkout' );
	}

	// ----- Scripts -----------------------------------------------------------------------------

	/**
	 * Whether Defer scripts leaves a script alone: always for jQuery and consent tools, and for
	 * the handles, file addresses and plugins the administrator listed.
	 *
	 * @param string $handle Script handle.
	 * @param string $src    Script address.
	 */
	public static function leaves_script( string $handle, string $src ): bool {
		if ( in_array( $handle, Fixes::DEFER_ALWAYS_EXCLUDED, true ) || preg_match( Fixes::DEFER_EXCLUDED_PATTERN, $handle ) ) {
			return true;
		}
		$stored = Fixes::stored();
		foreach ( $stored['defer_exclude'] as $entry ) {
			if ( $entry === $handle || ( self::is_file_part( $entry ) && false !== stripos( $src, $entry ) ) ) {
				return true;
			}
		}
		foreach ( $stored['defer_plugins'] as $slug ) {
			if ( str_contains( $src, '/plugins/' . $slug . '/' ) || str_contains( $src, '/mu-plugins/' . $slug . '/' ) ) {
				return true;
			}
		}
		/**
		 * Whether Defer scripts defers a script in the page's head.
		 *
		 * @param bool   $defer  True to defer it.
		 * @param string $handle Script handle.
		 * @param string $src    Script address.
		 */
		return ! (bool) apply_filters( 'arvaseo_connector_defer_script', true, $handle, $src );
	}

	/**
	 * The active plugins whose scripts can be left alone: folder => name, Arva SEO's own left out.
	 *
	 * @return array<string, string>
	 */
	public static function plugin_choices(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$active  = Fixes::active_plugin_slugs();
		$choices = array();
		foreach ( get_plugins() as $file => $data ) {
			$slug = dirname( (string) $file );
			if ( '.' === $slug || 'arvaseo-connector' === $slug || ! in_array( $slug, $active, true ) ) {
				continue;
			}
			$choices[ $slug ] = '' !== (string) ( $data['Name'] ?? '' ) ? (string) $data['Name'] : $slug;
		}
		asort( $choices, SORT_NATURAL | SORT_FLAG_CASE );
		return $choices;
	}

	// ----- Images and frames -------------------------------------------------------------------

	/**
	 * Whether a fix leaves an image or frame alone: our marker, the administrator's list, the
	 * other plugins' lazy-loading markers (lazy loading only), a block around it that's left
	 * alone, or the developer filter.
	 *
	 * @param string                $fix   Fix ID.
	 * @param string                $tag   Tag name (IMG, IFRAME).
	 * @param array<string, string> $attrs The element's class, id and src.
	 */
	public static function leaves_element( string $fix, string $tag, array $attrs ): bool {
		if ( self::$skip_depth > 0 || isset( $attrs[ self::SKIP_ATTRIBUTE ] ) || self::matches_list( $attrs ) ) {
			return true;
		}
		if ( 'lazy_media' === $fix ) {
			if ( self::$skip_lazy_depth > 0 ) {
				return true;
			}
			$classes = self::classes( $attrs['class'] ?? '' );
			if ( array() !== array_intersect( $classes, self::SKIP_LAZY_CLASSES ) || array() !== array_intersect_key( $attrs, array_flip( self::SKIP_LAZY_ATTRIBUTES ) ) ) {
				return true;
			}
		}
		/**
		 * Whether a PageSpeed fix changes an image or frame.
		 *
		 * @param bool                  $optimize True to change it.
		 * @param string                $fix      Fix ID: lazy_media, image_dimensions or video_facades.
		 * @param string                $tag      Tag name, such as IMG or IFRAME.
		 * @param array<string, string> $attrs    The element's class, id and src.
		 */
		return ! (bool) apply_filters( 'arvaseo_connector_optimize_element', true, $fix, $tag, $attrs );
	}

	/**
	 * The attributes leaves_element() looks at, from the tag processor's current tag.
	 *
	 * @param \WP_HTML_Tag_Processor $tags At an element.
	 * @return array<string, string>
	 */
	public static function attributes( \WP_HTML_Tag_Processor $tags ): array {
		$attrs = array();
		foreach ( array_merge( array( 'class', 'id', 'src', 'data-src', self::SKIP_ATTRIBUTE ), self::SKIP_LAZY_ATTRIBUTES ) as $name ) {
			$value = $tags->get_attribute( $name );
			if ( null !== $value ) {
				$attrs[ $name ] = true === $value ? '' : (string) $value;
			}
		}
		return $attrs;
	}

	/**
	 * Whether the element matches the administrator's list.
	 *
	 * @param array<string, string> $attrs The element's class, id and src.
	 */
	private static function matches_list( array $attrs ): bool {
		$entries = Fixes::stored()['exclude_elements'];
		if ( array() === $entries ) {
			return false;
		}
		$classes = self::classes( $attrs['class'] ?? '' );
		$id      = $attrs['id'] ?? '';
		$src     = ( $attrs['src'] ?? '' ) . ' ' . ( $attrs['data-src'] ?? '' );
		foreach ( $entries as $entry ) {
			if ( str_starts_with( $entry, '.' ) && in_array( strtolower( substr( $entry, 1 ) ), $classes, true ) ) {
				return true;
			}
			if ( str_starts_with( $entry, '#' ) && '' !== $id && substr( $entry, 1 ) === $id ) {
				return true;
			}
			if ( ! str_starts_with( $entry, '.' ) && ! str_starts_with( $entry, '#' ) && false !== stripos( $src, $entry ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Class names, lower case.
	 *
	 * @param string $attribute The class attribute.
	 * @return string[]
	 */
	private static function classes( string $attribute ): array {
		return array_map( 'strtolower', preg_split( '/\s+/', trim( $attribute ), -1, PREG_SPLIT_NO_EMPTY ) ?: array() );
	}

	/**
	 * Before a block renders: a block whose Additional CSS class is in the list (or one of the
	 * other plugins' markers) leaves everything inside it alone. Blocks render inside out, so
	 * this is counted on the way in and out.
	 *
	 * @param mixed                $pre   Short-circuit value.
	 * @param array<string, mixed> $block The block.
	 * @return mixed
	 */
	public static function block_start( $pre, $block ) {
		$alone = null === $pre && is_array( $block ) ? self::block_left_alone( $block ) : null;
		if ( 'all' === $alone ) {
			++self::$skip_depth;
		} elseif ( 'lazy' === $alone ) {
			++self::$skip_lazy_depth;
		}
		return $pre;
	}

	/**
	 * After a block rendered (and the fixes ran on it).
	 *
	 * @param mixed                $html  Block output.
	 * @param array<string, mixed> $block The block.
	 * @return mixed
	 */
	public static function block_end( $html, $block ) {
		$alone = is_array( $block ) ? self::block_left_alone( $block ) : null;
		if ( 'all' === $alone && self::$skip_depth > 0 ) {
			--self::$skip_depth;
		} elseif ( 'lazy' === $alone && self::$skip_lazy_depth > 0 ) {
			--self::$skip_lazy_depth;
		}
		return $html;
	}

	/**
	 * Whether a block's own classes leave what's inside it alone: "all" for a class in the list,
	 * "lazy" for another plugin's lazy-loading marker, null when they don't.
	 *
	 * @param array<string, mixed> $block The block.
	 */
	private static function block_left_alone( array $block ): ?string {
		$class = $block['attrs']['className'] ?? '';
		if ( ! is_string( $class ) || '' === $class ) {
			return null;
		}
		$classes = self::classes( $class );
		foreach ( Fixes::stored()['exclude_elements'] as $entry ) {
			if ( str_starts_with( $entry, '.' ) && in_array( strtolower( substr( $entry, 1 ) ), $classes, true ) ) {
				return 'all';
			}
		}
		return array() !== array_intersect( $classes, self::SKIP_LAZY_CLASSES ) ? 'lazy' : null;
	}

	// ----- The tick box on posts and pages -----------------------------------------------------

	/**
	 * "PageSpeed fixes" in the editor's sidebar, for administrators.
	 */
	public static function add_meta_box(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			return;
		}
		$types = array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) );
		add_meta_box( 'arvaseo-speed', __( 'PageSpeed fixes (Arva SEO)', 'arvaseo-connector' ), array( self::class, 'meta_box' ), array_values( $types ), 'side', 'low' );
	}

	/**
	 * The tick box.
	 *
	 * @param \WP_Post $post The post.
	 */
	public static function meta_box( $post ): void {
		wp_nonce_field( 'arvaseo_connector_speed_skip', 'arvaseo_speed_skip_nonce' );
		printf(
			'<p><label><input type="checkbox" name="arvaseo_speed_skip" value="1" %1$s /> %2$s</label></p><p class="description">%3$s</p>',
			checked( '1', get_post_meta( $post->ID, self::META, true ), false ),
			esc_html__( 'Leave this page alone', 'arvaseo-connector' ),
			esc_html__( 'No PageSpeed fixes run on it. Tick this if something on this page stops working with a fix on.', 'arvaseo-connector' )
		);
	}

	/**
	 * Save the tick box.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function save_meta_box( $post_id ): void {
		if ( ! isset( $_POST['arvaseo_speed_skip_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['arvaseo_speed_skip_nonce'] ) ), 'arvaseo_connector_speed_skip' ) ) {
			return;
		}
		if ( wp_is_post_autosave( (int) $post_id ) || wp_is_post_revision( (int) $post_id ) || ! current_user_can( Capabilities::MANAGE ) || ! current_user_can( 'edit_post', (int) $post_id ) ) {
			return;
		}
		self::set_post( (int) $post_id, ! empty( $_POST['arvaseo_speed_skip'] ) );
	}

	/**
	 * Leave a post or page alone, or not.
	 *
	 * @param int  $post_id Post ID.
	 * @param bool $skip    Leave it alone.
	 */
	public static function set_post( int $post_id, bool $skip ): void {
		$was = '1' === get_post_meta( $post_id, self::META, true );
		if ( $was === $skip ) {
			return;
		}
		if ( $skip ) {
			update_post_meta( $post_id, self::META, '1' );
		} else {
			delete_post_meta( $post_id, self::META );
		}
		self::reset();
		AuditLogger::info(
			$skip ? 'speed.page_left_alone' : 'speed.page_used_again',
			sprintf(
				/* translators: %s: post or page title. */
				$skip ? __( 'PageSpeed fixes: “%s” is left alone.', 'arvaseo-connector' ) : __( 'PageSpeed fixes: “%s” uses them again.', 'arvaseo-connector' ),
				get_the_title( $post_id )
			),
			array( 'post_id' => $post_id )
		);
	}

	/**
	 * Add an address to the list, or take it off.
	 *
	 * @param string $path Path on the site.
	 * @param bool   $skip Leave it alone.
	 */
	public static function set_path( string $path, bool $skip ): void {
		$path    = self::page_lines( $path )[0] ?? '';
		$entries = Fixes::stored()['exclude_pages'];
		if ( '' === $path ) {
			return;
		}
		$listed = array_values( array_filter( $entries, static fn ( string $entry ) => ! str_contains( $entry, '*' ) && self::path_matches( $path, $entry ) ) );
		if ( $skip && array() === $listed ) {
			$entries[] = $path;
		} elseif ( ! $skip && array() !== $listed ) {
			$entries = array_values( array_diff( $entries, $listed ) );
		} else {
			return;
		}
		Fixes::save_choices( array( 'exclude_pages' => implode( "\n", $entries ) ) );
		AuditLogger::info(
			$skip ? 'speed.page_left_alone' : 'speed.page_used_again',
			sprintf(
				/* translators: %s: page address. */
				$skip ? __( 'PageSpeed fixes: %s is left alone.', 'arvaseo-connector' ) : __( 'PageSpeed fixes: %s uses them again.', 'arvaseo-connector' ),
				$path
			),
			array( 'path' => $path )
		);
	}

	// ----- The toolbar -------------------------------------------------------------------------

	/**
	 * The toolbar items for the page being viewed, under the Arva SEO item.
	 *
	 * @param \WP_Admin_Bar $bar  The toolbar.
	 * @param string        $item The Arva SEO item's ID.
	 */
	public static function toolbar_items( $bar, string $item ): void {
		if ( is_admin() || ! Runtime::is_page_request() ) {
			return;
		}
		$reason  = self::page_reason( 'defer_scripts' );
		$post_id = is_singular() ? get_queried_object_id() : 0;
		$path    = self::current_path();
		$exact   = 'address' === $reason && array() !== array_filter( Fixes::stored()['exclude_pages'], static fn ( string $entry ) => ! str_contains( $entry, '*' ) && self::path_matches( $path, $entry ) );
		$back    = home_url( $path );
		if ( null === $reason ) {
			$title = __( 'Leave this page alone', 'arvaseo-connector' );
			$href  = self::toggle_url( 'skip', $post_id, $path, $back );
		} elseif ( 'post' === $reason || $exact ) {
			$title = __( 'Use PageSpeed fixes on this page again', 'arvaseo-connector' );
			$href  = self::toggle_url( 'use', $post_id, $path, $back );
		} else {
			$title = 'shop' === $reason ? __( 'Shop page: Defer scripts leaves it alone', 'arvaseo-connector' ) : __( 'Left alone by your list of pages', 'arvaseo-connector' );
			$href  = admin_url( 'admin.php?page=arvaseo-speed#arvaseo-speed-exclusions' );
		}
		$bar->add_node(
			array(
				'parent' => $item,
				'id'     => 'arvaseo-speed-page',
				'title'  => esc_html( $title ),
				'href'   => $href,
			)
		);
		$bar->add_node(
			array(
				'parent' => $item,
				'id'     => 'arvaseo-speed-settings',
				'title'  => esc_html__( 'PageSpeed fixes', 'arvaseo-connector' ),
				'href'   => admin_url( 'admin.php?page=arvaseo-speed' ),
			)
		);
	}

	/**
	 * The signed address of a toolbar toggle.
	 *
	 * @param string $action  skip or use.
	 * @param int    $post_id The post or page (0 for other pages).
	 * @param string $path    The page's path.
	 * @param string $back    Where to go after.
	 */
	private static function toggle_url( string $action, int $post_id, string $path, string $back ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'  => 'arvaseo_connector_speed_page',
					'do'      => $action,
					'post'    => $post_id,
					'path'    => rawurlencode( $path ),
					'back_to' => rawurlencode( $back ),
				),
				admin_url( 'admin-post.php' )
			),
			'arvaseo_connector_speed_page'
		);
	}

	/**
	 * The toolbar's toggle: a post or page by its tick box, any other page by its address.
	 */
	public static function toolbar_toggle(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You don’t have permission to do that.', 'arvaseo-connector' ), 403 );
		}
		check_admin_referer( 'arvaseo_connector_speed_page' );
		$skip    = isset( $_GET['do'] ) && 'skip' === sanitize_key( wp_unslash( $_GET['do'] ) );
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		$path    = isset( $_GET['path'] ) ? sanitize_text_field( wp_unslash( $_GET['path'] ) ) : '';
		$back    = isset( $_GET['back_to'] ) ? esc_url_raw( wp_unslash( $_GET['back_to'] ) ) : home_url( '/' );
		self::toggle( $skip, $post_id, $path );
		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * Leave a page alone or use the fixes on it again.
	 *
	 * @param bool   $skip    Leave it alone.
	 * @param int    $post_id The post or page, or 0.
	 * @param string $path    The page's path.
	 */
	public static function toggle( bool $skip, int $post_id, string $path ): void {
		if ( $post_id > 0 && current_user_can( 'edit_post', $post_id ) ) {
			self::set_post( $post_id, $skip );
			if ( ! $skip ) {
				self::set_path( $path, false );
			}
			return;
		}
		self::set_path( $path, $skip );
	}

	/**
	 * How many posts and pages are left alone by their tick box.
	 */
	public static function skipped_posts(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = '1'", self::META ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- a count for the settings page and diagnostics.
	}
}
