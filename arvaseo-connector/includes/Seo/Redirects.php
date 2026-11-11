<?php
/**
 * Redirects: exact-path rules an administrator makes, run early on the front end.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Seo;

use ArvaSEO\Connector\Install\Capabilities;
use ArvaSEO\Connector\Install\Schema;
use ArvaSEO\Connector\Logging\AuditLogger;

defined( 'ABSPATH' ) || exit;

/**
 * A rule sends one exact path (no query string) to a path on this site or to an http(s) address
 * on another website, with a 301 (default) or a 302. Off until an administrator turns on
 * "Use redirects". Nothing is written to .htaccess. A wp-config.php constant switches all of it off.
 */
final class Redirects {

	/** Switches redirects and the broken-address log off, whatever the settings say. */
	public const KILL_SWITCH = 'ARVASEO_CONNECTOR_DISABLE_REDIRECTS';

	/** The most rules kept. */
	public const MAX_RULES = 500;

	/** The most redirects a visitor may be sent through in a row. */
	public const MAX_CHAIN = 3;

	public const CODES = array( 301, 302 );

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( 'template_redirect', array( self::class, 'maybe_redirect' ), 1 );
	}

	/**
	 * Whether the wp-config.php emergency switch is on.
	 */
	public static function disabled(): bool {
		return defined( self::KILL_SWITCH ) && (bool) constant( self::KILL_SWITCH );
	}

	/**
	 * Whether redirects run on the front end right now.
	 */
	public static function active(): bool {
		return ! self::disabled() && (bool) Options::get( 'redirects' ) && (int) Options::get( 'rules' ) > 0;
	}

	/**
	 * Run a matching rule for this request, if any.
	 */
	public static function maybe_redirect(): void {
		if ( ! self::active() || is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
			return;
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			return;
		}
		// An administrator previewing a draft or the Customizer sees the page, not where it is sent.
		if ( ( is_preview() || is_customize_preview() ) && current_user_can( Capabilities::MANAGE ) ) {
			return;
		}
		$uri      = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Paths::normalize() cleans it before any use.
		$redirect = self::resolve( is_string( $uri ) ? $uri : '' );
		if ( null === $redirect ) {
			return;
		}
		// An address an administrator typed and this class checked (again) just now.
		if ( wp_redirect( $redirect['url'], $redirect['code'], 'Arva SEO' ) ) { // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- explicit target, validated when saved and again here.
			exit;
		}
	}

	/**
	 * Where a request should be sent: [url, code], or null.
	 *
	 * @param string $request_uri The request address.
	 * @return array{url: string, code: int}|null
	 */
	public static function resolve( string $request_uri ): ?array {
		$path = Paths::normalize( $request_uri );
		if ( null === $path || Paths::is_protected( $path ) ) {
			return null;
		}
		$rule = self::find( $path );
		if ( null === $rule ) {
			return null;
		}
		$target = Paths::sanitize_target( $rule['target'] );
		if ( null === $target ) {
			return null;
		}
		$url = Paths::is_external( $target ) ? $target : home_url( $target );
		return array(
			'url'  => $url,
			'code' => in_array( $rule['code'], self::CODES, true ) ? $rule['code'] : 301,
		);
	}

	/**
	 * The rule for a normalized path.
	 *
	 * @param string $path Normalized path.
	 * @return array{id: int, from_path: string, target: string, code: int, created_at: string}|null
	 */
	public static function find( string $path ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id, from_path, target, code, created_at FROM %i WHERE from_hash = %s LIMIT 1', Schema::table( 'redirects' ), md5( $path ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- own table, one indexed lookup per request.
		if ( ! is_array( $row ) || $row['from_path'] !== $path ) {
			return null;
		}
		return array(
			'id'         => (int) $row['id'],
			'from_path'  => (string) $row['from_path'],
			'target'     => (string) $row['target'],
			'code'       => (int) $row['code'],
			'created_at' => (string) $row['created_at'],
		);
	}

	/**
	 * Every rule, oldest first.
	 *
	 * @return list<array{id: int, from_path: string, target: string, code: int, created_at: string}>
	 */
	public static function all(): array {
		global $wpdb;
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT id, from_path, target, code, created_at FROM %i ORDER BY id ASC LIMIT %d', Schema::table( 'redirects' ), self::MAX_RULES ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		$rules = array();
		foreach ( (array) $rows as $row ) {
			$rules[] = array(
				'id'         => (int) $row['id'],
				'from_path'  => (string) $row['from_path'],
				'target'     => (string) $row['target'],
				'code'       => (int) $row['code'],
				'created_at' => (string) $row['created_at'],
			);
		}
		return $rules;
	}

	/**
	 * How many rules there are.
	 */
	public static function count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Schema::table( 'redirects' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
	}

	/**
	 * Check a new rule against the rules there are, and add it.
	 *
	 * Refused (with a code, see error_text()): invalid_from, protected_from, invalid_target,
	 * bad_code, self, duplicate, too_many, loop, chain.
	 *
	 * @param string $from   Where visitors come from (a path on this site).
	 * @param string $target Where they go (a path on this site, or an http or https address).
	 * @param int    $code   301 or 302.
	 * @return array{ok: bool, error?: string, id?: int}
	 */
	public static function add( string $from, string $target, int $code = 301 ): array {
		$checked = self::check( $from, $target, $code );
		if ( isset( $checked['error'] ) ) {
			return array(
				'ok'    => false,
				'error' => $checked['error'],
			);
		}
		global $wpdb;
		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
			Schema::table( 'redirects' ),
			array(
				'from_hash'  => md5( $checked['from'] ),
				'from_path'  => $checked['from'],
				'target'     => $checked['target'],
				'code'       => $code,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%s', '%s', '%d', '%s' )
		);
		if ( 1 !== $inserted ) {
			return array(
				'ok'    => false,
				'error' => 'duplicate',
			);
		}
		$id = (int) $wpdb->insert_id;
		self::counted();
		NotFoundLog::forget( $checked['from'] );
		AuditLogger::info(
			'seo.redirect_added',
			__( 'A redirect was added.', 'arvaseo-connector' ),
			array(
				'code' => $code,
			)
		);
		\ArvaSEO\Connector\Speed\Fixes::pages_changed( 'site' );
		return array(
			'ok' => true,
			'id' => $id,
		);
	}

	/**
	 * Check a rule before it is saved.
	 *
	 * @param string $from   Typed source.
	 * @param string $target Typed target.
	 * @param int    $code   Status code.
	 * @return array{from: string, target: string}|array{error: string}
	 */
	public static function check( string $from, string $target, int $code ): array {
		$path = Paths::normalize( $from );
		if ( null === $path ) {
			return array( 'error' => 'invalid_from' );
		}
		if ( Paths::is_protected( $path ) ) {
			return array( 'error' => 'protected_from' );
		}
		$clean = Paths::sanitize_target( $target );
		if ( null === $clean ) {
			return array( 'error' => 'invalid_target' );
		}
		if ( ! in_array( $code, self::CODES, true ) ) {
			return array( 'error' => 'bad_code' );
		}
		$end = Paths::is_external( $clean ) ? null : Paths::normalize( $clean );
		if ( $end === $path ) {
			return array( 'error' => 'self' );
		}
		if ( null !== self::find( $path ) ) {
			return array( 'error' => 'duplicate' );
		}
		if ( self::count() >= self::MAX_RULES ) {
			return array( 'error' => 'too_many' );
		}
		$map = array( $path => $clean );
		foreach ( self::all() as $rule ) {
			$map[ $rule['from_path'] ] = $rule['target'];
		}
		$problem = self::chain_problem( $map );
		if ( null !== $problem ) {
			return array( 'error' => $problem );
		}
		return array(
			'from'   => $path,
			'target' => $clean,
		);
	}

	/**
	 * Whether following the rules from any path loops, or goes through more than MAX_CHAIN redirects.
	 *
	 * @param array<string, string> $map From path => target.
	 * @return string|null loop, chain, or null when fine.
	 */
	public static function chain_problem( array $map ): ?string {
		foreach ( array_keys( $map ) as $start ) {
			$seen = array( $start => true );
			$cur  = $start;
			$hops = 0;
			while ( isset( $map[ $cur ] ) ) {
				++$hops;
				if ( $hops > self::MAX_CHAIN ) {
					return 'chain';
				}
				if ( Paths::is_external( $map[ $cur ] ) ) {
					break;
				}
				$next = Paths::normalize( $map[ $cur ] );
				if ( null === $next ) {
					break;
				}
				if ( isset( $seen[ $next ] ) ) {
					return 'loop';
				}
				$seen[ $next ] = true;
				$cur           = $next;
			}
		}
		return null;
	}

	/**
	 * Delete a rule.
	 *
	 * @param int $id Rule ID.
	 */
	public static function delete( int $id ): bool {
		global $wpdb;
		$deleted = $wpdb->delete( Schema::table( 'redirects' ), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		if ( 1 !== $deleted ) {
			return false;
		}
		self::counted();
		AuditLogger::info( 'seo.redirect_deleted', __( 'A redirect was deleted.', 'arvaseo-connector' ) );
		\ArvaSEO\Connector\Speed\Fixes::pages_changed( 'site' );
		return true;
	}

	/**
	 * Keep the stored rule count in step with the table.
	 */
	public static function counted(): void {
		Options::update( array( 'rules' => self::count() ) );
	}

	/**
	 * The rules as CSV (from, to, status, added). Cells that a spreadsheet could read as a formula
	 * start with an apostrophe.
	 */
	public static function csv(): string {
		$lines = array( self::line( array( 'from', 'to', 'status', 'added' ) ) );
		foreach ( self::all() as $rule ) {
			$lines[] = self::line( array( self::cell( $rule['from_path'] ), self::cell( $rule['target'] ), (string) $rule['code'], $rule['created_at'] ) );
		}
		return implode( "\r\n", $lines ) . "\r\n";
	}

	/**
	 * One CSV line: every cell in double quotes.
	 *
	 * @param string[] $cells Cells.
	 */
	private static function line( array $cells ): string {
		return implode( ',', array_map( static fn ( string $cell ): string => '"' . str_replace( '"', '""', $cell ) . '"', $cells ) );
	}

	/**
	 * A CSV cell a spreadsheet will not run as a formula.
	 *
	 * @param string $value Cell text.
	 */
	private static function cell( string $value ): string {
		return 1 === preg_match( '/^[=+\-@\t\r]/', $value ) ? "'" . $value : $value;
	}

	/**
	 * A refusal in a sentence.
	 *
	 * @param string $code Error code from add().
	 */
	public static function error_text( string $code ): string {
		$texts = array(
			'invalid_from'   => __( 'That isn’t an address on this site. Type a path such as /old-page/.', 'arvaseo-connector' ),
			'protected_from' => __( 'The home page and WordPress’s own addresses (the dashboard, log-in and the like) can’t be redirected.', 'arvaseo-connector' ),
			'invalid_target' => __( 'Send visitors to a path on this site (starting with one /) or to a full address starting with http:// or https://.', 'arvaseo-connector' ),
			'bad_code'       => __( 'Choose a permanent (301) or temporary (302) redirect.', 'arvaseo-connector' ),
			'self'           => __( 'That redirect would send the address to itself.', 'arvaseo-connector' ),
			'duplicate'      => __( 'That address already has a redirect. Delete it first to make a new one.', 'arvaseo-connector' ),
			/* translators: %d: the most redirects allowed. */
			'too_many'       => sprintf( __( 'There are already %d redirects, the most this screen keeps. Delete some you no longer need.', 'arvaseo-connector' ), self::MAX_RULES ),
			'loop'           => __( 'That redirect would send visitors round in a circle with the redirects you already have.', 'arvaseo-connector' ),
			/* translators: %d: the most redirects in a row. */
			'chain'          => sprintf( __( 'That would make visitors go through more than %d redirects in a row. Point the older redirects straight at the final address instead.', 'arvaseo-connector' ), self::MAX_CHAIN ),
		);
		return $texts[ $code ] ?? __( 'The redirect wasn’t saved.', 'arvaseo-connector' );
	}
}
