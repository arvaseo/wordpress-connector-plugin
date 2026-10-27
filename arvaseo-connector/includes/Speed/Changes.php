<?php
/**
 * What a plugin update changed in the PageSpeed fixes, and what the administrator has approved.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Speed;

use ArvaSEO\Connector\Install\Capabilities;
use ArvaSEO\Connector\Logging\AuditLogger;
use ArvaSEO\Connector\Support\Ids;
use ArvaSEO\Connector\Support\Lock;

defined( 'ABSPATH' ) || exit;

/**
 * Never change approved behaviour silently. Every release lists what it changed for a site where a
 * fix is on, in one of four kinds (docs/behaviour-changes.md in the Arva SEO repository):
 *
 * - notice: nothing a visitor could see differently, or only less of it. Applied at once, one note.
 * - option: a new choice that starts off.
 * - update: a visitor could see a page work differently, and the change can't be optional. Visitors
 *   keep the revision the administrator approved (the fix's "rev"); administrators who are signed
 *   in preview the newest; nothing changes for visitors until "Use it for visitors". At most one
 *   older revision is kept per fix, and the old code path stays until the entry's keep_until date.
 * - back: the old behaviour can't be kept (security, or broken pages). A fix with a Trying step goes
 *   back to Trying with a note; one without (page caching, browser caching, WebP, image
 *   compression) runs its own self-check and switches off only if that fails.
 *
 * Stored inside the arvaseo_connector_speed option: each fix's row has "rev" (the revision visitors
 * get) and "kept" (when the administrator chose to keep it), and "changes" lists the entries that
 * applied to this site, newest last (at most 20, ids only: the notes stay in code).
 *
 * New code paths sit behind Fixes::uses( 'fix', N ): true when the revision this request gets is N
 * or later. Visitors and Arva SEO's renderer get the approved revision; a signed-in administrator
 * gets the newest while an update waits.
 */
final class Changes {

	public const NOTICE = 'notice';

	public const OPTION = 'option';

	public const UPDATE = 'update';

	public const BACK = 'back';

	/** The oldest version whose behaviour the registry describes: sites older than this count as this one. */
	public const FIRST = '2.10.0';

	/** Where the version that last ran is kept. */
	public const VERSION_OPTION = 'arvaseo_connector_speed_version';

	/** The upgrade runs under this lock. */
	public const LOCK = 'speed_upgrade';

	/** A waiting update is kept at least this long after the site was told about it (D4). */
	public const KEEP_DAYS = 90;

	/** The entries listed in the option, at most. */
	public const MAX_RECORDED = 20;

	/** The rows of speed_changes in the site's details, at most (what Arva SEO accepts). */
	public const MAX_REPORTED = 20;

	/** Entries nobody waits on leave the list after this long. */
	public const RECORD_DAYS = 180;

	/** Revisions of the CSS fixes: 2.10.2 combines first, 2.10.3 reaches every stylesheet. */
	public const CSS_COMBINE_FIRST = 2;

	public const CSS_EVERY_STYLESHEET = 3;

	/** Combine CSS and Combine JavaScript from 2.10.5 combine around a file that can't be combined. */
	public const COMBINE_AROUND = 2;

	/**
	 * Fixes that share one set of worked-out CSS: an update moves them together, since a page can't
	 * be shown with the approved revision for one and the newest for the other.
	 */
	public const LINKED = array(
		array( 'critical_css', 'unused_css' ),
	);

	/** How Arva SEO names the kinds. */
	private const WIRE_KINDS = array(
		self::NOTICE => 'notice',
		self::OPTION => 'new_option',
		self::UPDATE => 'update_to_check',
		self::BACK   => 'back_to_trying',
	);

	/** Fixes that go on in WordPress only: "Use it for visitors" isn't offered from Arva SEO. */
	public const WORDPRESS_ONLY = array( 'defer_scripts', 'delay_google_tag' );

	/** The date old revisions of the 2.11.0 updates are retired at the earliest: 90 days after the planned release. */
	private const KEEP_2_11 = '2027-03-08';

	/**
	 * The defaults in effect from each version on, for every choice the Speed page stores (what
	 * Fixes::stored() answers for a key that isn't saved). Writing them at an upgrade means a later
	 * change of a default reaches fresh installs only. Taken from git history: 2.10.0 is main at
	 * 1217a5b, 2.10.5 added the two choices that start off. A new key must be false, empty or off.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private const DEFAULTS = array(
		'2.10.0' => array(
			'extras'                 => array(
				'emoji'          => true,
				'oembed'         => true,
				'dashicons'      => true,
				'jquery_migrate' => false,
				'block_css'      => false,
			),
			'defer_exclude'          => array(),
			'defer_plugins'          => array(),
			'exclude_pages'          => array(),
			'exclude_elements'       => array(),
			'shop_pages'             => true,
			'eager_images'           => 3,
			'fonts_local'            => false,
			'ucss_inline'            => true,
			'ccss_per_url'           => false,
			'css_async_inline'       => true,
			'js_loading_mode'        => 'deferred',
			'combine_inline_css_ids' => array(),
			'combine_inline_js_ids'  => array(),
			'external_asset_urls'    => array(),
			'delay_scripts'          => array(),
			'css_exclude'            => array(),
			'dns_prefetch_origins'   => array(),
			'preconnect_origins'     => array(),
			'preconnect_anonymous'   => false,
			'remote'                 => false,
		),
		'2.10.5' => array(
			'move_jquery' => false,
			'late_icons'  => false,
		),
	);

	/**
	 * The entries as read in this request.
	 *
	 * @var list<array{id: string, version: string, fixes: string[], kind: string, rev: int|null, with: string[], keep_until: string|null}>|null
	 */
	private static ?array $cache = null;

	/**
	 * The entries, oldest first. "version" is the plugin version whose code brought the behaviour,
	 * not the one that tells the site: a site that ran it already (2.10.6 and later for the 2.10.x
	 * entries) counts the entry as used. "with" lists fixes that must be in use too for the entry
	 * to matter. "rev" is the revision an update brings to every fix it names; "keep_until" the
	 * earliest day its old revision can be retired (D4: two minor releases or 90 days, whichever is
	 * later, from the release date).
	 *
	 * @return list<array{id: string, version: string, fixes: string[], kind: string, rev: int|null, with: string[], keep_until: string|null}>
	 */
	public static function entries(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$entries = array(
			array(
				'id'         => '2.11.0-image-purge',
				'version'    => '2.10.1',
				'fixes'      => array( 'compress_images', 'webp_uploads' ),
				'kind'       => self::NOTICE,
				'rev'        => null,
				'with'       => array(),
				'keep_until' => null,
			),
			array(
				'id'         => '2.11.0-combine-first',
				'version'    => '2.10.2',
				'fixes'      => array( 'critical_css', 'unused_css' ),
				'kind'       => self::UPDATE,
				'rev'        => self::CSS_COMBINE_FIRST,
				'with'       => array( 'combine_css' ),
				'keep_until' => self::KEEP_2_11,
			),
			array(
				'id'         => '2.11.0-css-every-stylesheet',
				'version'    => '2.10.3',
				'fixes'      => array( 'critical_css', 'unused_css' ),
				'kind'       => self::UPDATE,
				'rev'        => self::CSS_EVERY_STYLESHEET,
				'with'       => array(),
				'keep_until' => self::KEEP_2_11,
			),
			array(
				'id'         => '2.11.0-cleanups',
				'version'    => '2.10.3',
				'fixes'      => array( 'minify_css', 'minify_js', 'combine_css', 'combine_js', 'google_fonts', 'critical_css', 'unused_css' ),
				'kind'       => self::NOTICE,
				'rev'        => null,
				'with'       => array(),
				'keep_until' => null,
			),
			array(
				'id'         => '2.11.0-combine-around',
				'version'    => '2.10.5',
				'fixes'      => array( 'combine_css', 'combine_js' ),
				'kind'       => self::UPDATE,
				'rev'        => self::COMBINE_AROUND,
				'with'       => array(),
				'keep_until' => self::KEEP_2_11,
			),
			array(
				'id'         => '2.11.0-renderer-extras',
				'version'    => '2.10.5',
				'fixes'      => array( 'critical_css', 'unused_css' ),
				'kind'       => self::NOTICE,
				'rev'        => null,
				'with'       => array( 'combine_css', 'wp_extras' ),
				'keep_until' => null,
			),
			array(
				'id'         => '2.11.0-jquery-choice',
				'version'    => '2.10.5',
				'fixes'      => array( 'defer_scripts' ),
				'kind'       => self::OPTION,
				'rev'        => null,
				'with'       => array(),
				'keep_until' => null,
			),
			array(
				'id'         => '2.11.0-late-icons-choice',
				'version'    => '2.10.5',
				'fixes'      => array( 'critical_css', 'unused_css' ),
				'kind'       => self::OPTION,
				'rev'        => null,
				'with'       => array(),
				'keep_until' => null,
			),
			array(
				'id'         => '2.11.0-animations',
				'version'    => '2.10.6',
				'fixes'      => array( 'critical_css', 'unused_css' ),
				'kind'       => self::NOTICE,
				'rev'        => null,
				'with'       => array(),
				'keep_until' => null,
			),
			array(
				'id'         => '2.11.0-tracking',
				'version'    => '2.11.0',
				'fixes'      => array( 'page_cache' ),
				'kind'       => self::NOTICE,
				'rev'        => null,
				'with'       => array(),
				'keep_until' => null,
			),
			array(
				// Page caching and every fix that clears caches when it changes: any fix that is on.
				'id'         => '2.11.0-more-caches',
				'version'    => '2.11.0',
				'fixes'      => array( '*' ),
				'kind'       => self::NOTICE,
				'rev'        => null,
				'with'       => array(),
				'keep_until' => null,
			),
		);
		/**
		 * The change entries (tests add their own to try each kind).
		 *
		 * @param array<int, array<string, mixed>> $entries Entries, oldest first.
		 */
		$filtered    = apply_filters( 'arvaseo_connector_speed_changes', $entries );
		self::$cache = is_array( $filtered ) ? array_values( $filtered ) : $entries;
		return self::$cache;
	}

	/**
	 * Forget the entries read so far in this request (tests change them with the filter).
	 */
	public static function reset(): void {
		self::$cache = null;
	}

	/**
	 * The entries with the note each one shows.
	 *
	 * @return list<array{id: string, version: string, fixes: string[], kind: string, rev: int|null, with: string[], keep_until: string|null, note: string}>
	 */
	public static function all(): array {
		$notes = self::notes();
		$rows  = array();
		foreach ( self::entries() as $entry ) {
			$entry['note'] = $notes[ $entry['id'] ] ?? __( 'This version changed how a fix you use works.', 'arvaseo-connector' );
			$rows[]        = $entry;
		}
		return $rows;
	}

	/**
	 * What each entry says to a site owner, in plain words.
	 *
	 * @return array<string, string>
	 */
	public static function notes(): array {
		return array(
			'2.11.0-image-purge'          => __( 'Caches are cleared when compression changes an image.', 'arvaseo-connector' ),
			'2.11.0-combine-first'        => __( 'With Combine CSS on, optimized styles are now worked out from the combined files your visitors get. Each page type is worked out once again, free.', 'arvaseo-connector' ),
			'2.11.0-css-every-stylesheet' => __( 'Optimized styles now also reach stylesheets whose names use capital letters (Elementor’s entrance animations, some Google Fonts) and stylesheets a theme prints more than once, so fewer of them hold the page up.', 'arvaseo-connector' ),
			'2.11.0-cleanups'             => __( 'Unused generated files are cleaned up after a week unused (Google Fonts copies after 30 days), never while another cache may still use them.', 'arvaseo-connector' ),
			'2.11.0-combine-around'       => __( 'Files next to one that can’t be combined are now combined around it, in the same order. This applies to stylesheets and scripts.', 'arvaseo-connector' ),
			'2.11.0-renderer-extras'      => __( 'Arva SEO’s renderer now gets your pages without the WordPress extras you removed, as visitors do.', 'arvaseo-connector' ),
			'2.11.0-jquery-choice'        => __( 'New option under Defer scripts: Move jQuery down the page. It’s off until you turn it on.', 'arvaseo-connector' ),
			'2.11.0-late-icons-choice'    => __( 'New option under Load styles faster: Load icon fonts after the page. It’s off until you turn it on.', 'arvaseo-connector' ),
			'2.11.0-animations'           => __( 'Entrance animations no longer play a second time when the rest of the styles arrive.', 'arvaseo-connector' ),
			'2.11.0-tracking'             => __( 'Visitors arriving from Google Ads, Facebook, Mailchimp and similar links get the saved page too.', 'arvaseo-connector' ),
			'2.11.0-more-caches'          => __( 'Arva SEO now also clears WP-Optimize’s, NitroPack’s and Cloudflare’s (official plugin) caches when your pages change, at most every 15 minutes.', 'arvaseo-connector' ),
		);
	}

	/**
	 * The version a note belongs to: the release that told the site ("2.11.0-combine-first").
	 *
	 * @param string $id Entry ID.
	 */
	public static function announced_in( string $id ): string {
		return (string) strtok( $id, '-' );
	}

	// ----- Revisions -------------------------------------------------------------------------

	/**
	 * The newest revision of a fix: 1 until an update entry names it.
	 *
	 * @param string $fix Fix ID.
	 */
	public static function latest_rev( string $fix ): int {
		return self::rev_at( $fix, null );
	}

	/**
	 * The revision a fix had in a version: the newest update entry that version already had.
	 *
	 * @param string      $fix     Fix ID.
	 * @param string|null $version A plugin version; null for the newest.
	 */
	public static function rev_at( string $fix, ?string $version ): int {
		$rev = 1;
		foreach ( self::entries() as $entry ) {
			if ( self::UPDATE !== $entry['kind'] || ! in_array( $fix, $entry['fixes'], true ) ) {
				continue;
			}
			if ( null === $version || version_compare( $entry['version'], $version, '<=' ) ) {
				$rev = max( $rev, (int) $entry['rev'] );
			}
		}
		return $rev;
	}

	/**
	 * The revision of a fix its administrator approved, the one visitors get. A row without one
	 * means the revision of the version that last ran, read from the version option, which the
	 * upgrade overwrites only after it has written every row.
	 *
	 * @param string $fix Fix ID.
	 */
	public static function approved_rev( string $fix ): int {
		$row    = self::row( $fix );
		$latest = self::latest_rev( $fix );
		if ( is_int( $row['rev'] ?? null ) ) {
			return max( 1, min( $latest, $row['rev'] ) );
		}
		return self::rev_at( $fix, self::version() );
	}

	/**
	 * The revision this request gets: the approved one for visitors and Arva SEO's renderer, the
	 * newest for a fix that isn't on (it's being tried or off, so nothing is approved yet) and for
	 * a signed-in administrator while an update waits, unless they chose to keep the previous one.
	 *
	 * @param string $fix Fix ID.
	 */
	public static function effective_rev( string $fix ): int {
		$latest = self::latest_rev( $fix );
		if ( $latest <= 1 || 'on' !== self::raw_state( $fix ) ) {
			return $latest;
		}
		$approved = self::approved_rev( $fix );
		if ( $approved >= $latest ) {
			return $latest;
		}
		return self::previewing() && ! self::is_kept( $fix ) ? $latest : $approved;
	}

	/**
	 * Whether every fix that applies to this request, among those a shared code path serves, has
	 * this revision. Fixes that don't apply (off, or being tried by someone else) don't hold it back.
	 *
	 * @param string[] $fixes Fix IDs.
	 * @param int      $rev   Revision.
	 */
	public static function pipeline_uses( array $fixes, int $rev ): bool {
		foreach ( $fixes as $fix ) {
			$state    = self::raw_state( $fix );
			$applying = 'on' === $state || ( 'trying' === $state && current_user_can( Capabilities::MANAGE ) );
			if ( $applying && self::effective_rev( $fix ) < $rev ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * A signed-in administrator looking at the site, as opposed to a visitor or the renderer.
	 */
	private static function previewing(): bool {
		return current_user_can( Capabilities::MANAGE ) && ! CssOptimizer::is_render_request();
	}

	/**
	 * A fix's row as saved, without the defaults.
	 *
	 * @param string $fix Fix ID.
	 * @return array<string, mixed>
	 */
	private static function row( string $fix ): array {
		$row = self::saved()['fixes'][ $fix ] ?? array();
		return is_array( $row ) ? $row : array();
	}

	/**
	 * A fix's saved state, as it was saved: off, trying or on.
	 *
	 * @param string $fix Fix ID.
	 */
	private static function raw_state( string $fix ): string {
		$state = self::row( $fix )['state'] ?? 'off';
		return is_string( $state ) ? $state : 'off';
	}

	/**
	 * The option as saved.
	 *
	 * @return array<string, mixed>
	 */
	private static function saved(): array {
		$saved = get_option( Fixes::OPTION, array() );
		return is_array( $saved ) ? $saved : array();
	}

	/**
	 * The version that last ran, or null on a site that has never run one.
	 */
	public static function version(): ?string {
		$version = get_option( self::VERSION_OPTION );
		return is_string( $version ) && '' !== $version ? $version : null;
	}

	// ----- Updates that wait -----------------------------------------------------------------

	/**
	 * The update entries a fix has waiting: newer than its approved revision, while it is on.
	 *
	 * @param string $fix Fix ID.
	 * @return list<array{id: string, version: string, fixes: string[], kind: string, rev: int|null, with: string[], keep_until: string|null}>
	 */
	public static function waiting( string $fix ): array {
		if ( 'on' !== self::raw_state( $fix ) ) {
			return array();
		}
		$approved = self::approved_rev( $fix );
		$waiting  = array();
		foreach ( self::entries() as $entry ) {
			if ( self::UPDATE === $entry['kind'] && in_array( $fix, $entry['fixes'], true ) && (int) $entry['rev'] > $approved ) {
				$waiting[] = $entry;
			}
		}
		return $waiting;
	}

	/**
	 * Every fix with an update waiting, with its entries. Fixes whose previous version the
	 * administrator chose to keep are left out unless asked for.
	 *
	 * @param bool $with_kept Include the kept ones.
	 * @return array<string, list<array{id: string, version: string, fixes: string[], kind: string, rev: int|null, with: string[], keep_until: string|null}>>
	 */
	public static function waiting_fixes( bool $with_kept = false ): array {
		$fixes = array();
		foreach ( self::entries() as $entry ) {
			if ( self::UPDATE === $entry['kind'] ) {
				$fixes = array_merge( $fixes, $entry['fixes'] );
			}
		}
		$out = array();
		foreach ( array_values( array_unique( $fixes ) ) as $fix ) {
			$entries = self::waiting( $fix );
			// A fix another speed plugin does isn't applied to visitors: there is nothing to check.
			if ( array() !== $entries && ( $with_kept || ! self::is_kept( $fix ) ) && null === Fixes::handled_by( $fix ) ) {
				$out[ $fix ] = $entries;
			}
		}
		return $out;
	}

	/**
	 * How many fixes have an update to check (the toolbar and the notice).
	 */
	public static function waiting_count(): int {
		return count( self::waiting_fixes() );
	}

	/**
	 * Whether the administrator chose to keep this fix's previous version.
	 *
	 * @param string $fix Fix ID.
	 */
	public static function is_kept( string $fix ): bool {
		return (int) ( self::row( $fix )['kept'] ?? 0 ) > 0;
	}

	/**
	 * The fixes that move together with a fix: itself, and those sharing its worked-out CSS.
	 *
	 * @param string $fix Fix ID.
	 * @return string[]
	 */
	public static function linked( string $fix ): array {
		foreach ( self::LINKED as $group ) {
			if ( in_array( $fix, $group, true ) ) {
				return $group;
			}
		}
		return array( $fix );
	}

	/**
	 * When a fix's approved revision is retired (D4): the earliest of its waiting entries' days,
	 * since the approved revision is the oldest one. Null when nothing waits.
	 *
	 * @param string $fix Fix ID.
	 * @return int|null Unix time.
	 */
	public static function retire_at( string $fix ): ?int {
		$times = array();
		foreach ( self::waiting( $fix ) as $entry ) {
			$times[] = self::entry_retire_at( $entry );
		}
		return array() === $times ? null : min( $times );
	}

	/**
	 * The day an update entry's previous revision is retired (D4): the later of its date and 90
	 * days after the site was told about it.
	 *
	 * @param array{id: string, keep_until: string|null} $entry Entry.
	 * @return int Unix time.
	 */
	private static function entry_retire_at( array $entry ): int {
		$told = self::recorded_at( $entry['id'] ) ?? time();
		return max( (int) strtotime( (string) $entry['keep_until'] . ' 00:00:00 UTC' ), $told + self::KEEP_DAYS * DAY_IN_SECONDS );
	}

	// ----- What was recorded -----------------------------------------------------------------

	/**
	 * The entries that applied to this site.
	 *
	 * @param mixed $stored The saved list.
	 * @return list<array{id: string, at: int, done?: string}>
	 */
	public static function clean( $stored ): array {
		$list = array();
		foreach ( is_array( $stored ) ? $stored : array() as $item ) {
			if ( ! is_array( $item ) || ! is_string( $item['id'] ?? null ) || 1 !== preg_match( '/^[a-z0-9._-]{1,64}$/', $item['id'] ) || ! is_int( $item['at'] ?? null ) ) {
				continue;
			}
			$row = array(
				'id' => $item['id'],
				'at' => $item['at'],
			);
			if ( in_array( $item['done'] ?? null, array( 'used', 'retired' ), true ) ) {
				$row['done'] = $item['done'];
			}
			$list[ $item['id'] ] = $row;
		}
		return array_slice( array_values( $list ), -self::MAX_RECORDED );
	}

	/**
	 * The entries that applied to this site, oldest first.
	 *
	 * @return list<array{id: string, at: int, done?: string}>
	 */
	public static function recorded(): array {
		return self::clean( self::saved()['changes'] ?? array() );
	}

	/**
	 * When the site was told about an entry.
	 *
	 * @param string $id Entry ID.
	 */
	public static function recorded_at( string $id ): ?int {
		foreach ( self::recorded() as $item ) {
			if ( $item['id'] === $id ) {
				return $item['at'];
			}
		}
		return null;
	}

	/**
	 * The changes as Arva SEO receives them with the site's details (speed_changes): at most 20
	 * rows, ids only (Arva SEO keeps the notes). A row says where one change stands for the fixes
	 * it names, so one change can come twice, once per status:
	 *
	 * - id: the entry's id (2.11.0-combine-first);
	 * - status: waiting (visitors still get the version the administrator approved), kept (the
	 *   administrator chose to keep it), used (visitors get the update) or applied (a notice, a new
	 *   option or a fix sent back to Trying: nothing to decide);
	 * - kind: notice, new_option, update_to_check or back_to_trying;
	 * - fixes: the fixes the status is for (empty: any fix that is on);
	 * - since: when the site was told about it;
	 * - keep_until (waiting and kept): the day the previous version is retired, as a time.
	 *
	 * What waits comes first, then what was used, then notices (the newest of those when over 20).
	 *
	 * @return list<array{id: string, status: string, kind: string, fixes: string[], since: string, keep_until?: string}>
	 */
	public static function for_registration(): array {
		$known = array_column( self::entries(), null, 'id' );
		$rows  = array(
			'waiting' => array(),
			'kept'    => array(),
			'used'    => array(),
			'applied' => array(),
		);
		foreach ( self::recorded() as $item ) {
			$entry = $known[ $item['id'] ] ?? null;
			$since = Ids::iso( $item['at'] );
			if ( null === $entry || self::UPDATE !== $entry['kind'] ) {
				$rows['applied'][] = array(
					'id'     => $item['id'],
					'status' => 'applied',
					'kind'   => self::WIRE_KINDS[ $entry['kind'] ?? self::NOTICE ],
					'fixes'  => null === $entry || array( '*' ) === $entry['fixes'] ? array() : array_values( $entry['fixes'] ),
					'since'  => $since,
				);
				continue;
			}
			$stands = array(
				'waiting' => array(),
				'kept'    => array(),
				'used'    => array(),
			);
			foreach ( $entry['fixes'] as $fix ) {
				$status = self::standing( $entry, (string) $fix, $item['at'] );
				if ( null !== $status ) {
					$stands[ $status ][] = $fix;
				}
			}
			foreach ( $stands as $status => $fixes ) {
				if ( array() === $fixes ) {
					continue;
				}
				$row = array(
					'id'     => $item['id'],
					'status' => $status,
					'kind'   => self::WIRE_KINDS[ self::UPDATE ],
					'fixes'  => $fixes,
					'since'  => $since,
				);
				if ( 'used' !== $status ) {
					$row['keep_until'] = Ids::iso( self::entry_retire_at( $entry ) );
				}
				$rows[ $status ][] = $row;
			}
		}
		$head = array_slice( array_merge( $rows['waiting'], $rows['kept'] ), 0, self::MAX_REPORTED );
		$room = self::MAX_REPORTED - count( $head );
		$used = self::newest( $rows['used'], $room );
		return array_merge( $head, $used, self::newest( $rows['applied'], $room - count( $used ) ) );
	}

	/**
	 * Where an update entry stands for a fix: waiting or kept while visitors don't get it yet,
	 * used once they do, and null when it never concerned the fix (off, tried, done by another
	 * plugin, or switched on after the site was told).
	 *
	 * @param array{id: string, rev: int|null} $entry Update entry.
	 * @param string                           $fix   Fix ID.
	 * @param int                              $told  When the site was told about the entry.
	 * @return string|null waiting, kept or used.
	 */
	private static function standing( array $entry, string $fix, int $told ): ?string {
		if ( 'on' !== self::raw_state( $fix ) || null !== Fixes::handled_by( $fix ) ) {
			return null;
		}
		if ( (int) $entry['rev'] > self::approved_rev( $fix ) ) {
			return self::is_kept( $fix ) ? 'kept' : 'waiting';
		}
		$since = strtotime( (string) ( self::row( $fix )['since'] ?? '' ) );
		return false !== $since && $since > $told ? null : 'used';
	}

	/**
	 * The last rows of a list, at most $room of them.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows, oldest first.
	 * @param int                              $room How many fit.
	 * @return array<int, array<string, mixed>>
	 */
	private static function newest( array $rows, int $room ): array {
		return $room <= 0 ? array() : array_slice( $rows, -$room );
	}

	/**
	 * The notes to show: what applied to this site since it last ran an older version, newest
	 * first, with the fixes it names and whether it's an update still waiting.
	 *
	 * @return array<int, array{id: string, kind: string, note: string, version: string, fixes: string[], at: int, done: string|null}>
	 */
	public static function notes_for_site(): array {
		$known = array_column( self::all(), null, 'id' );
		$rows  = array();
		foreach ( array_reverse( self::recorded() ) as $item ) {
			$entry = $known[ $item['id'] ] ?? null;
			if ( null === $entry ) {
				continue;
			}
			$rows[] = array(
				'id'      => $item['id'],
				'kind'    => $entry['kind'],
				'note'    => $entry['note'],
				'version' => self::announced_in( $item['id'] ),
				'fixes'   => array( '*' ) === $entry['fixes'] ? array() : $entry['fixes'],
				'at'      => $item['at'],
				'done'    => $item['done'] ?? null,
			);
		}
		return $rows;
	}

	// ----- Choosing -------------------------------------------------------------------------

	/**
	 * "Use it for visitors": the fix, and any that share its CSS, go to the newest revision. Defer
	 * scripts still needs its checklist.
	 *
	 * @param string $fix     Fix ID.
	 * @param bool   $checked The checklist was ticked (Defer scripts).
	 * @return string|null Why not (invalid, no_update, handled, check_pages), or null when it moved.
	 */
	public static function accept( string $fix, bool $checked = false ): ?string {
		$all = Fixes::all();
		if ( ! isset( $all[ $fix ] ) ) {
			return 'invalid';
		}
		if ( array() === self::waiting( $fix ) ) {
			return 'no_update';
		}
		if ( null !== Fixes::handled_by( $fix ) ) {
			return 'handled';
		}
		if ( 'defer_scripts' === $fix && ! $checked ) {
			return 'check_pages';
		}
		$data  = Fixes::stored();
		$moved = array();
		$used  = array();
		foreach ( self::linked( $fix ) as $id ) {
			$waiting = self::waiting( $id );
			if ( array() === $waiting ) {
				continue;
			}
			$data['fixes'][ $id ]['rev']  = self::latest_rev( $id );
			$data['fixes'][ $id ]['kept'] = 0;
			$moved[]                      = $all[ $id ]['title'];
			$used                         = array_merge( $used, array_column( $waiting, 'id' ) );
		}
		$data['changes'] = self::mark( $data['changes'], $used, 'used' );
		Fixes::save( $data );
		// What visitors get changes: caches clear, and the new revision's own results are asked for.
		Fixes::pages_changed( 'fix' );
		AuditLogger::info(
			'speed.update_used',
			/* translators: %s: names of the fixes. */
			sprintf( __( 'PageSpeed fixes updated for visitors: %s.', 'arvaseo-connector' ), implode( ', ', $moved ) ),
			array( 'count' => count( $moved ) )
		);
		return null;
	}

	/**
	 * "Keep the previous version": visitors (and from now on this administrator too) stay on the
	 * approved revision until the day it is retired.
	 *
	 * @param string $fix Fix ID.
	 * @return string|null Why not (invalid or no_update), or null when it was kept.
	 */
	public static function keep( string $fix ): ?string {
		$all = Fixes::all();
		if ( ! isset( $all[ $fix ] ) ) {
			return 'invalid';
		}
		if ( array() === self::waiting( $fix ) ) {
			return 'no_update';
		}
		$data  = Fixes::stored();
		$names = array();
		foreach ( self::linked( $fix ) as $id ) {
			if ( array() !== self::waiting( $id ) ) {
				$data['fixes'][ $id ]['kept'] = time();
				$names[]                      = $all[ $id ]['title'];
			}
		}
		Fixes::save( $data );
		AuditLogger::info(
			'speed.update_kept',
			/* translators: %s: names of the fixes. */
			sprintf( __( 'PageSpeed fixes keep the version you checked: %s.', 'arvaseo-connector' ), implode( ', ', $names ) ),
			array( 'count' => count( $names ) )
		);
		return null;
	}

	/**
	 * Add "done" to recorded entries.
	 *
	 * @param list<array{id: string, at: int, done?: string}> $recorded Recorded entries.
	 * @param string[]                                        $ids      Entry IDs.
	 * @param string                                          $done     used or retired.
	 * @return list<array{id: string, at: int, done?: string}>
	 */
	private static function mark( array $recorded, array $ids, string $done ): array {
		foreach ( $recorded as $i => $item ) {
			if ( in_array( $item['id'], $ids, true ) ) {
				$recorded[ $i ]['done'] = $done;
			}
		}
		return $recorded;
	}

	// ----- Upgrading ------------------------------------------------------------------------

	/**
	 * Run once per version change, under a lock, before the version that last ran is overwritten:
	 * write the defaults that were in effect in it for choices the site never saved, then apply
	 * each entry newer than it, in order. A site that already ran an entry's behaviour counts it
	 * as used. A fresh install gets the defaults of this version and no notes.
	 *
	 * @param mixed $previous The version option before this upgrade.
	 */
	public static function upgrade( $previous ): void {
		$saved = self::saved();
		$fresh = ! is_string( $previous ) && array() === $saved;
		$from  = $fresh ? ARVASEO_CONNECTOR_VERSION : ( is_string( $previous ) && version_compare( $previous, self::FIRST, '>' ) ? $previous : self::FIRST );
		$rows  = is_array( $saved['fixes'] ?? null ) ? $saved['fixes'] : array();
		$now   = time();

		foreach ( self::defaults( $from ) as $key => $value ) {
			if ( 'extras' === $key ) {
				$extras = is_array( $saved['extras'] ?? null ) ? $saved['extras'] : array();
				foreach ( (array) $value as $name => $default ) {
					if ( ! array_key_exists( $name, $extras ) ) {
						$extras[ $name ] = $default;
					}
				}
				$saved['extras'] = $extras;
			} elseif ( ! array_key_exists( $key, $saved ) ) {
				$saved[ $key ] = $value;
			}
		}

		$changes = self::clean( $saved['changes'] ?? array() );
		$known   = array_column( $changes, 'id' );
		$all     = null;
		foreach ( $rows as $id => $row ) {
			if ( ! is_array( $row ) ) {
				unset( $rows[ $id ] );
				continue;
			}
			$rows[ $id ]['rev']  = is_int( $row['rev'] ?? null ) ? $row['rev'] : self::rev_at( (string) $id, $from );
			$rows[ $id ]['kept'] = is_int( $row['kept'] ?? null ) ? $row['kept'] : 0;
		}
		$told = array();
		foreach ( self::entries() as $entry ) {
			if ( version_compare( $entry['version'], $from, '<=' ) || $fresh ) {
				continue;
			}
			$fixes    = array( '*' ) === $entry['fixes'] ? array_keys( $rows ) : $entry['fixes'];
			$relevant = false;
			foreach ( $fixes as $fix ) {
				if ( ! isset( $rows[ $fix ] ) || ! self::concerns( $entry, $rows, (string) $fix ) ) {
					if ( self::UPDATE === $entry['kind'] && isset( $rows[ $fix ] ) && (int) $rows[ $fix ]['rev'] === (int) $entry['rev'] - 1 ) {
						$rows[ $fix ]['rev'] = (int) $entry['rev']; // Nothing a visitor sees: used from the start.
					}
					continue;
				}
				$relevant = true;
				if ( self::BACK === $entry['kind'] ) {
					$all        ??= Fixes::all();
					$rows[ $fix ] = self::back( $rows[ $fix ], (string) $fix, $all, $now );
					if ( 'defer_scripts' === $fix && 'trying' === $rows[ $fix ]['state'] ) {
						$saved['defer_checked_at'] = null; // Its checklist is done again.
						$saved['defer_on_at']      = null;
					}
				}
			}
			if ( $relevant && ! in_array( $entry['id'], $known, true ) ) {
				$told[] = $entry['id'];
			}
		}
		foreach ( $rows as $id => $row ) {
			if ( 'on' !== ( $row['state'] ?? 'off' ) ) {
				$rows[ $id ]['rev']  = self::latest_rev( (string) $id );
				$rows[ $id ]['kept'] = 0;
			}
		}
		foreach ( $told as $id ) {
			$changes[] = array(
				'id' => $id,
				'at' => $now,
			);
		}
		$saved['fixes']   = $rows;
		$saved['changes'] = self::trim( $changes, $rows, $now );
		update_option( Fixes::OPTION, $saved, true );

		$waiting = self::waiting_count();
		if ( $waiting > 0 ) {
			AuditLogger::info(
				'speed.updates_waiting',
				/* translators: %d: number of fixes. */
				sprintf( _n( 'Arva SEO changed %d PageSpeed fix you use. Visitors keep the version you checked until you choose.', 'Arva SEO changed %d PageSpeed fixes you use. Visitors keep the versions you checked until you choose.', $waiting, 'arvaseo-connector' ), $waiting ),
				array( 'count' => $waiting )
			);
		}
		if ( array() !== $told && ! wp_next_scheduled( 'arvaseo_connector_register' ) ) {
			wp_schedule_single_event( time(), 'arvaseo_connector_register' );
		}
	}

	/**
	 * Whether an entry matters for a fix, as saved: an update only while the fix is on (and what it
	 * is combined with), the other kinds while it is on or being tried.
	 *
	 * @param array{id: string, version: string, fixes: string[], kind: string, rev: int|null, with: string[], keep_until: string|null} $entry Entry.
	 * @param array<string, array<string, mixed>>                                                                                       $rows  Saved rows.
	 * @param string                                                                                                                    $fix   Fix ID.
	 */
	private static function concerns( array $entry, array $rows, string $fix ): bool {
		$states = self::UPDATE === $entry['kind'] ? array( 'on' ) : array( 'on', 'trying' );
		foreach ( array_merge( array( $fix ), $entry['with'] ) as $id ) {
			if ( ! in_array( $rows[ $id ]['state'] ?? 'off', $states, true ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * A change the old behaviour can't survive: the fix goes back to Trying, or, with no Trying
	 * step, runs its own self-check and goes off only if that fails.
	 *
	 * @param array<string, mixed>                $row   The fix's row.
	 * @param string                              $fix   Fix ID.
	 * @param array<string, array<string, mixed>> $all   Fixes::all().
	 * @param int                                 $now   Now.
	 * @return array<string, mixed>
	 */
	private static function back( array $row, string $fix, array $all, int $now ): array {
		if ( in_array( 'trying', (array) ( $all[ $fix ]['states'] ?? array() ), true ) ) {
			$row['state'] = 'trying';
			$row['since'] = Ids::iso( $now );
			$row['rev']   = self::latest_rev( $fix );
			$row['kept']  = 0;
			return $row;
		}
		$row['rev']  = self::latest_rev( $fix );
		$row['kept'] = 0;
		if ( null !== self::recheck( $fix ) ) {
			$row['state'] = 'off';
			$row['since'] = null;
		}
		return $row;
	}

	/**
	 * A fix without a Trying step checks itself again.
	 *
	 * @param string $fix Fix ID.
	 * @return string|null Why it failed, or null when it still works (or has nothing to check).
	 */
	private static function recheck( string $fix ): ?string {
		/**
		 * A fix without a Trying step checks that it still works after an update (page caching and
		 * browser caching do their own check when they're turned on; the others have none).
		 *
		 * @param string|null $error Why it fails, or null.
		 * @param string      $fix   Fix ID.
		 */
		$error = apply_filters( 'arvaseo_connector_speed_recheck', null, $fix );
		return is_string( $error ) ? $error : null;
	}

	/**
	 * The defaults in effect in a version: the newest table row at or before it, over the older ones.
	 *
	 * @param string $version Plugin version.
	 * @return array<string, mixed>
	 */
	public static function defaults( string $version ): array {
		$defaults = array();
		foreach ( self::DEFAULTS as $from => $values ) {
			if ( version_compare( $from, $version, '<=' ) ) {
				$defaults = array_merge( $defaults, $values );
			}
		}
		return $defaults;
	}

	/**
	 * The list at most MAX_RECORDED long: what's still waiting stays, then the newest.
	 *
	 * @param list<array{id: string, at: int, done?: string}> $changes Recorded entries, oldest first.
	 * @param array<string, array<string, mixed>>             $rows    Saved rows.
	 * @param int                                             $now     Now.
	 * @return list<array{id: string, at: int, done?: string}>
	 */
	private static function trim( array $changes, array $rows, int $now ): array {
		$waiting = array();
		foreach ( self::entries() as $entry ) {
			if ( self::UPDATE !== $entry['kind'] ) {
				continue;
			}
			foreach ( $entry['fixes'] as $fix ) {
				if ( 'on' === ( $rows[ $fix ]['state'] ?? 'off' ) && (int) ( $rows[ $fix ]['rev'] ?? 1 ) < (int) $entry['rev'] ) {
					$waiting[ $entry['id'] ] = true;
				}
			}
		}
		$changes = array_values(
			array_filter(
				$changes,
				static fn ( array $item ): bool => isset( $waiting[ $item['id'] ] ) || $item['at'] > $now - self::RECORD_DAYS * DAY_IN_SECONDS
			)
		);
		// Over the limit: the oldest that nobody waits on go first.
		$excess = count( $changes ) - self::MAX_RECORDED;
		foreach ( $changes as $i => $item ) {
			if ( $excess <= 0 ) {
				break;
			}
			if ( ! isset( $waiting[ $item['id'] ] ) ) {
				unset( $changes[ $i ] );
				--$excess;
			}
		}
		return array_values( $changes );
	}

	// ----- Retiring -------------------------------------------------------------------------

	/**
	 * Daily: retire old revisions whose day has come (D4), and forget entries nothing waits on.
	 */
	public static function maintain(): void {
		$now = time();
		$due = array();
		foreach ( array_keys( self::waiting_fixes( true ) ) as $fix ) {
			$when = self::retire_at( $fix );
			if ( null !== $when && $when <= $now ) {
				$due[] = $fix;
			}
		}
		self::retire( $due );
		$data    = Fixes::stored();
		$trimmed = self::trim( $data['changes'], $data['fixes'], $now );
		if ( $trimmed !== $data['changes'] ) {
			$data['changes'] = $trimmed;
			Fixes::save( $data );
		}
	}

	/**
	 * The approved revision is no longer available: each fix, and any that share its CSS, go back
	 * to Trying with a note. A fix without a Trying step runs its own self-check and moves to the
	 * newest revision instead. Caches clear once.
	 *
	 * @param string[] $fixes Fix IDs.
	 */
	public static function retire( array $fixes ): void {
		$all   = Fixes::all();
		$data  = Fixes::stored();
		$tried = array();
		$moved = array();
		$gone  = array();
		$now   = time();
		foreach ( $fixes as $fix ) {
			foreach ( self::linked( (string) $fix ) as $id ) {
				$waiting = self::waiting( $id );
				if ( array() === $waiting || ! isset( $data['fixes'][ $id ] ) ) {
					continue;
				}
				$gone                 = array_merge( $gone, array_column( $waiting, 'id' ) );
				$row                  = self::back( $data['fixes'][ $id ], $id, $all, $now );
				$data['fixes'][ $id ] = array_merge( $data['fixes'][ $id ], $row );
				if ( 'trying' === $row['state'] ) {
					$tried[] = $all[ $id ]['title'];
					if ( 'defer_scripts' === $id ) {
						$data['defer_checked_at'] = null;
						$data['defer_on_at']      = null;
					}
				} else {
					$moved[] = $all[ $id ]['title'];
				}
			}
		}
		if ( array() === $tried && array() === $moved ) {
			return;
		}
		$data['changes'] = self::mark( $data['changes'], $gone, 'retired' );
		Fixes::save( $data );
		Fixes::pages_changed( 'fix' );
		if ( array() !== $tried ) {
			AuditLogger::warning(
				'speed.update_retired',
				/* translators: %s: names of the fixes. */
				sprintf( __( 'PageSpeed fixes went back to being tried because the earlier version of them is no longer available: %s. Check your pages, then turn them on again.', 'arvaseo-connector' ), implode( ', ', array_unique( $tried ) ) ),
				array( 'count' => count( $tried ) )
			);
		}
		if ( array() !== $moved ) {
			AuditLogger::info(
				'speed.update_retired_on',
				/* translators: %s: names of the fixes. */
				sprintf( __( 'PageSpeed fixes now use their newest version, because the earlier version of them is no longer available: %s.', 'arvaseo-connector' ), implode( ', ', array_unique( $moved ) ) ),
				array( 'count' => count( $moved ) )
			);
		}
	}
}
