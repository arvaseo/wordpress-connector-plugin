<?php
/**
 * Cloudflare: clear Cloudflare's copy of the site's pages when what visitors get changes.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Speed;

use ArvaSEO\Connector\Api\HttpTransport;
use ArvaSEO\Connector\Api\WpHttpTransport;
use ArvaSEO\Connector\Auth\Crypto;
use ArvaSEO\Connector\Cron\CronManager;
use ArvaSEO\Connector\Logging\AuditLogger;
use ArvaSEO\Connector\Support\Ids;

defined( 'ABSPATH' ) || exit;

/**
 * Optional, and only once an administrator enters a Cloudflare API token (Zone: Read and Cache
 * Purge; Zone Settings: Read lets it check Rocket Loader). Then every change that alters what
 * visitors get (a fix turned on or off, saved choices, new optimized CSS, WebP copies, local fonts:
 * the arvaseo_connector_pages_changed action) clears Cloudflare's copy of every page, and a post
 * that's published, updated or unpublished clears its own pages. Only page addresses are sent, to
 * api.cloudflare.com. The token is kept encrypted, like the Arva SEO credentials.
 */
final class Cloudflare {

	/** Zone, results and the queue (an option, not autoloaded). */
	public const OPTION = 'arvaseo_connector_cloudflare';

	/** The API token, encrypted. */
	public const TOKEN = 'arvaseo_connector_cloudflare_token';

	public const HOOK = 'arvaseo_connector_cloudflare_purge';

	private const API = 'https://api.cloudflare.com/client/v4';

	/** Cloudflare takes up to 30 addresses per request. */
	private const URLS_PER_REQUEST = 30;

	/** More addresses than this waiting, and everything is cleared instead. */
	private const MAX_QUEUE = 300;

	/** Seconds between two "everything" clears; changes in between wait for one clear. */
	private const GAP = 20;

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( 'arvaseo_connector_pages_changed', array( self::class, 'pages_changed' ) );
		add_action( 'arvaseo_connector_seo_updated', array( self::class, 'seo_updated' ) );
		add_action( 'transition_post_status', array( self::class, 'post_changed' ), 10, 3 );
		add_action( self::HOOK, array( self::class, 'run_queue' ) );
		add_action( 'admin_init', array( self::class, 'remember_seen' ) );
	}

	/**
	 * The stored details.
	 *
	 * @return array{zone_id: string|null, zone_name: string|null, connected_at: string|null, seen_at: string|null, rocket_loader: string|null, checked_at: string|null, posts: bool, refused: bool, last: array{at: string, what: string, count: int, ok: bool, error: string|null}|null, queue: string[], everything: bool, everything_at: int}
	 */
	public static function details(): array {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$last  = is_array( $saved['last'] ?? null ) ? $saved['last'] : null;
		return array(
			'zone_id'       => is_string( $saved['zone_id'] ?? null ) ? $saved['zone_id'] : null,
			'zone_name'     => is_string( $saved['zone_name'] ?? null ) ? $saved['zone_name'] : null,
			'connected_at'  => is_string( $saved['connected_at'] ?? null ) ? $saved['connected_at'] : null,
			'seen_at'       => is_string( $saved['seen_at'] ?? null ) ? $saved['seen_at'] : null,
			'rocket_loader' => in_array( $saved['rocket_loader'] ?? null, array( 'on', 'off' ), true ) ? $saved['rocket_loader'] : null,
			'checked_at'    => is_string( $saved['checked_at'] ?? null ) ? $saved['checked_at'] : null,
			'posts'         => (bool) ( $saved['posts'] ?? true ),
			'refused'       => (bool) ( $saved['refused'] ?? false ),
			'last'          => null === $last ? null : array(
				'at'    => (string) ( $last['at'] ?? '' ),
				'what'  => 'pages' === ( $last['what'] ?? '' ) ? 'pages' : 'everything',
				'count' => (int) ( $last['count'] ?? 0 ),
				'ok'    => (bool) ( $last['ok'] ?? false ),
				'error' => is_string( $last['error'] ?? null ) ? $last['error'] : null,
			),
			'queue'         => array_values( array_filter( (array) ( $saved['queue'] ?? array() ), 'is_string' ) ),
			'everything'    => (bool) ( $saved['everything'] ?? false ),
			'everything_at' => (int) ( $saved['everything_at'] ?? 0 ),
		);
	}

	/**
	 * Merge changes into the details.
	 *
	 * @param array<string, mixed> $changes Changes.
	 */
	private static function update( array $changes ): void {
		update_option( self::OPTION, array_merge( self::details(), $changes ), false );
	}

	/**
	 * Whether a token and its zone are saved.
	 */
	public static function connected(): bool {
		return null !== self::details()['zone_id'] && null !== self::token();
	}

	/**
	 * Whether this site was opened through Cloudflare in the last 30 days.
	 */
	public static function seen(): bool {
		$seen = self::details()['seen_at'];
		return null !== $seen && strtotime( $seen ) > time() - 30 * DAY_IN_SECONDS;
	}

	/**
	 * Rocket Loader is on while Defer scripts or Delay chat widgets and trackers is on or being
	 * tried: both change how scripts load, and together they can break things.
	 */
	public static function rocket_loader_clash(): bool {
		if ( 'on' !== self::details()['rocket_loader'] ) {
			return false;
		}
		foreach ( array( 'defer_scripts', 'delay_scripts' ) as $id ) {
			if ( in_array( Fixes::state( $id ), array( 'on', 'trying' ), true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Remember that an administrator's request came through Cloudflare (at most once a day).
	 */
	public static function remember_seen(): void {
		if ( ! isset( $_SERVER['HTTP_CF_RAY'] ) ) {
			return;
		}
		$seen = self::details()['seen_at'];
		if ( null === $seen || strtotime( $seen ) < time() - DAY_IN_SECONDS ) {
			self::update( array( 'seen_at' => Ids::iso() ) );
		}
	}

	/**
	 * Save a token: find this site's zone with it, then check Rocket Loader.
	 *
	 * @param string $token The API token.
	 * @return string|null Why it wasn't saved (token_format, token_refused, zone_missing,
	 *                     unreachable), or null when it was.
	 */
	public static function connect( string $token ): ?string {
		$token = trim( $token );
		if ( 1 !== preg_match( '/^[A-Za-z0-9_-]{20,200}$/', $token ) ) {
			return 'token_format';
		}
		$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$zone = null;
		// The zone is the site's domain or one of its parents (blog.example.com is in example.com).
		$labels = explode( '.', $host );
		$last   = count( $labels ) - 1;
		for ( $i = 0; $i < $last && null === $zone; $i++ ) {
			$answer = self::request( 'GET', '/zones?' . http_build_query( array( 'name' => implode( '.', array_slice( $labels, $i ) ) ) ), null, $token );
			if ( null !== $answer['error'] && in_array( $answer['status'], array( 401, 403 ), true ) ) {
				return 'token_refused';
			}
			if ( null !== $answer['error'] ) {
				return 'unreachable';
			}
			foreach ( (array) ( $answer['body']['result'] ?? array() ) as $found ) {
				if ( is_array( $found ) && is_string( $found['id'] ?? null ) && is_string( $found['name'] ?? null ) ) {
					$zone = array( $found['id'], $found['name'] );
					break;
				}
			}
		}
		if ( null === $zone ) {
			return 'zone_missing';
		}
		update_option( self::TOKEN, Crypto::encrypt( $token ), false );
		self::update(
			array(
				'zone_id'      => $zone[0],
				'zone_name'    => $zone[1],
				'connected_at' => Ids::iso(),
				'refused'      => false,
				'posts'        => true,
				'last'         => null,
				'queue'        => array(),
				'everything'   => false,
			)
		);
		self::check();
		/* translators: %s: the Cloudflare zone (a domain). */
		AuditLogger::info( 'cloudflare.connected', sprintf( __( 'Cloudflare connected for %s.', 'arvaseo-connector' ), $zone[1] ), array( 'host' => $zone[1] ) );
		return null;
	}

	/**
	 * Forget the token and the zone.
	 */
	public static function disconnect(): void {
		delete_option( self::TOKEN );
		wp_clear_scheduled_hook( self::HOOK );
		self::update(
			array(
				'zone_id'       => null,
				'zone_name'     => null,
				'connected_at'  => null,
				'rocket_loader' => null,
				'checked_at'    => null,
				'refused'       => false,
				'last'          => null,
				'queue'         => array(),
				'everything'    => false,
			)
		);
		AuditLogger::info( 'cloudflare.disconnected', __( 'Cloudflare disconnected. Its token was removed from this site.', 'arvaseo-connector' ) );
	}

	/**
	 * Check that the token still works and whether Rocket Loader is on.
	 */
	public static function check(): void {
		$zone = self::details()['zone_id'];
		if ( null === $zone ) {
			return;
		}
		$answer  = self::request( 'GET', '/zones/' . rawurlencode( $zone ) . '/settings/rocket_loader' );
		$changes = array( 'checked_at' => Ids::iso() );
		if ( null === $answer['error'] ) {
			$changes['rocket_loader'] = 'on' === ( $answer['body']['result']['value'] ?? '' ) ? 'on' : 'off';
			$changes['refused']       = false;
		} elseif ( 403 === $answer['status'] ) {
			// Allowed to clear the cache but not to read settings: Rocket Loader stays unknown.
			$zone_answer              = self::request( 'GET', '/zones/' . rawurlencode( $zone ) );
			$changes['rocket_loader'] = null;
			$changes['refused']       = in_array( $zone_answer['status'], array( 401, 403 ), true );
		} elseif ( 401 === $answer['status'] ) {
			$changes['refused'] = true;
		}
		self::update( $changes );
	}

	/**
	 * Visitors get something new on every page: clear everything, at most once every GAP seconds.
	 *
	 * @param string $why What changed (fix, choices, css, webp, fonts, exclusions).
	 */
	public static function pages_changed( $why = '' ): void {
		if ( ! self::connected() ) {
			return;
		}
		$wait = self::details()['everything_at'] + self::GAP - time();
		if ( $wait > 0 ) {
			self::update( array( 'everything' => true ) );
			CronManager::run_soon( self::HOOK, $wait );
			return;
		}
		self::purge_everything( is_string( $why ) ? $why : '' );
	}

	/**
	 * Arva SEO changed a post's (or the homepage's) SEO title or description.
	 *
	 * @param int $post_id Post ID, 0 for the homepage.
	 */
	public static function seo_updated( $post_id ): void {
		$post_id = (int) $post_id;
		$url     = $post_id > 0 ? get_permalink( $post_id ) : home_url( '/' );
		if ( is_string( $url ) ) {
			self::queue( array( $url ) );
		}
	}

	/**
	 * A post was published, updated while published, or unpublished.
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       Post.
	 */
	public static function post_changed( $new_status, $old_status, $post ): void {
		if ( ( 'publish' !== $new_status && 'publish' !== $old_status ) || ! $post instanceof \WP_Post || ! self::connected() || ! self::details()['posts'] ) {
			return;
		}
		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) || ! is_post_type_viewable( $post->post_type ) ) {
			return;
		}
		if ( 'publish' !== $new_status ) {
			// Its address has already changed (trashed posts get a new slug), and lists linked to it.
			self::pages_changed( 'unpublished' );
			return;
		}
		self::queue( self::post_urls( $post ) );
	}

	/**
	 * The pages that show a post: its own, the homepage, the posts page, feeds and its archives.
	 *
	 * @param \WP_Post $post Post.
	 * @return string[]
	 */
	public static function post_urls( \WP_Post $post ): array {
		$urls = array( get_permalink( $post ), home_url( '/' ) );
		if ( 'post' === $post->post_type ) {
			$posts_page = (int) get_option( 'page_for_posts' );
			$urls[]     = $posts_page > 0 ? get_permalink( $posts_page ) : null;
			$urls[]     = get_feed_link();
			$urls[]     = get_author_posts_url( (int) $post->post_author );
		} else {
			$urls[] = get_post_type_archive_link( $post->post_type );
		}
		foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $taxonomy ) {
			if ( ! $taxonomy->public ) {
				continue;
			}
			$terms = get_the_terms( $post, $taxonomy->name );
			foreach ( is_array( $terms ) ? $terms : array() as $term ) {
				$link   = get_term_link( $term );
				$urls[] = is_string( $link ) ? $link : null;
			}
		}
		return array_slice( array_values( array_unique( array_filter( $urls, 'is_string' ) ) ), 0, self::URLS_PER_REQUEST );
	}

	/**
	 * Add pages to clear, soon (several saves in a row are cleared together).
	 *
	 * @param string[] $urls Absolute addresses on this site.
	 */
	public static function queue( array $urls ): void {
		if ( ! self::connected() ) {
			return;
		}
		$queue = array_values( array_unique( array_merge( self::details()['queue'], $urls ) ) );
		if ( count( $queue ) > self::MAX_QUEUE ) {
			self::update(
				array(
					'queue'      => array(),
					'everything' => true,
				)
			);
		} else {
			self::update( array( 'queue' => $queue ) );
		}
		CronManager::run_soon( self::HOOK, 5 );
	}

	/**
	 * The background job: clear what's waiting.
	 */
	public static function run_queue(): void {
		if ( ! self::connected() ) {
			return;
		}
		$details = self::details();
		if ( $details['everything'] ) {
			$wait = $details['everything_at'] + self::GAP - time();
			if ( $wait > 0 ) {
				wp_schedule_single_event( time() + $wait, self::HOOK );
				return;
			}
			self::purge_everything( 'waiting' );
			return;
		}
		if ( array() === $details['queue'] ) {
			return;
		}
		self::update( array( 'queue' => array() ) );
		self::purge_urls( $details['queue'] );
	}

	/**
	 * Clear Cloudflare's copy of every page now.
	 *
	 * @param string $why What changed, for the log.
	 * @return bool Whether Cloudflare cleared it.
	 */
	public static function purge_everything( string $why = '' ): bool {
		$zone = self::details()['zone_id'];
		if ( null === $zone ) {
			return false;
		}
		$answer = self::request( 'POST', '/zones/' . rawurlencode( $zone ) . '/purge_cache', array( 'purge_everything' => true ) );
		self::record( 'everything', 0, $answer, $why );
		return null === $answer['error'];
	}

	/**
	 * Clear Cloudflare's copy of some pages now.
	 *
	 * @param string[] $urls Absolute addresses.
	 * @return bool Whether Cloudflare cleared them all.
	 */
	public static function purge_urls( array $urls ): bool {
		$zone = self::details()['zone_id'];
		if ( null === $zone || array() === $urls ) {
			return false;
		}
		$answer = array(
			'status' => 200,
			'body'   => null,
			'error'  => null,
		);
		foreach ( array_chunk( array_values( $urls ), self::URLS_PER_REQUEST ) as $chunk ) {
			$answer = self::request( 'POST', '/zones/' . rawurlencode( $zone ) . '/purge_cache', array( 'files' => $chunk ) );
			if ( null !== $answer['error'] ) {
				break;
			}
		}
		self::record( 'pages', count( $urls ), $answer, 'posts' );
		return null === $answer['error'];
	}

	/**
	 * Keep and log the result of a clear.
	 *
	 * @param string                                                                  $what   everything|pages.
	 * @param int                                                                     $count  Pages cleared.
	 * @param array{status: int, body: array<string, mixed>|null, error: string|null} $answer Cloudflare's answer.
	 * @param string                                                                  $why    What changed.
	 */
	private static function record( string $what, int $count, array $answer, string $why ): void {
		$changes = array(
			'last' => array(
				'at'    => Ids::iso(),
				'what'  => $what,
				'count' => $count,
				'ok'    => null === $answer['error'],
				'error' => $answer['error'],
			),
		);
		if ( 'everything' === $what ) {
			$changes['everything']    = false;
			$changes['everything_at'] = time();
		}
		if ( in_array( $answer['status'], array( 401, 403 ), true ) ) {
			$changes['refused'] = true;
		}
		self::update( $changes );
		if ( null === $answer['error'] ) {
			AuditLogger::info(
				'cloudflare.cleared',
				'everything' === $what
					? __( 'Cloudflare: its copy of every page was cleared.', 'arvaseo-connector' )
					/* translators: %d: number of pages. */
					: sprintf( _n( 'Cloudflare: its copy of %d page was cleared.', 'Cloudflare: its copy of %d pages was cleared.', $count, 'arvaseo-connector' ), $count ),
				array(
					'reason' => $why,
					'count'  => $count,
				)
			);
			return;
		}
		AuditLogger::warning(
			'cloudflare.clear_failed',
			/* translators: %s: Cloudflare's error. */
			sprintf( __( 'Cloudflare didn’t clear its copy of your pages: %s', 'arvaseo-connector' ), $answer['error'] ),
			array(
				'reason'      => $why,
				'http_status' => $answer['status'],
			)
		);
	}

	/**
	 * One request to Cloudflare's API.
	 *
	 * @param string                    $method Method.
	 * @param string                    $path   Path after /client/v4.
	 * @param array<string, mixed>|null $body   JSON body.
	 * @param string|null               $token  A token to try (connect), else the saved one.
	 * @return array{status: int, body: array<string, mixed>|null, error: string|null}
	 */
	private static function request( string $method, string $path, ?array $body = null, ?string $token = null ): array {
		$token = $token ?? self::token();
		if ( null === $token ) {
			return array(
				'status' => 0,
				'body'   => null,
				'error'  => __( 'No Cloudflare token is saved.', 'arvaseo-connector' ),
			);
		}
		$transport = apply_filters( 'arvaseo_connector_http_transport', null );
		$transport = $transport instanceof HttpTransport ? $transport : new WpHttpTransport();
		$args      = array(
			'method'  => $method,
			'timeout' => 10,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = (string) wp_json_encode( $body );
		}
		$response = $transport->request( self::API . $path, $args );
		if ( is_wp_error( $response ) ) {
			return array(
				'status' => 0,
				'body'   => null,
				'error'  => __( 'Cloudflare couldn’t be reached from your server.', 'arvaseo-connector' ),
			);
		}
		$status  = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$decoded = is_array( $decoded ) ? $decoded : null;
		if ( $status >= 200 && $status < 300 && true === ( $decoded['success'] ?? false ) ) {
			return array(
				'status' => $status,
				'body'   => $decoded,
				'error'  => null,
			);
		}
		$message = (string) ( $decoded['errors'][0]['message'] ?? '' );
		return array(
			'status' => $status,
			'body'   => $decoded,
			/* translators: 1: HTTP status, 2: Cloudflare's message. */
			'error'  => sprintf( __( 'Cloudflare answered %1$d%2$s', 'arvaseo-connector' ), $status, '' !== $message ? ': ' . sanitize_text_field( mb_substr( $message, 0, 200 ) ) : '.' ),
		);
	}

	/**
	 * The saved token.
	 */
	private static function token(): ?string {
		$stored = get_option( self::TOKEN, '' );
		return is_string( $stored ) && '' !== $stored ? Crypto::decrypt( $stored ) : null;
	}

	/**
	 * Whether a post's pages are cleared when it changes.
	 *
	 * @param bool $on On.
	 */
	public static function set_posts( bool $on ): void {
		self::update( array( 'posts' => $on ) );
	}

	/**
	 * Remove the token (uninstall: secrets never outlive the plugin).
	 */
	public static function remove_on_exit(): void {
		delete_option( self::TOKEN );
		wp_clear_scheduled_hook( self::HOOK );
	}
}
