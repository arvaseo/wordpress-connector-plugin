<?php
/**
 * Explicit, reversible trials for reviewed assets. No new behavior on upgrade.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Speed;

use ArvaSEO\Connector\Install\Capabilities;

defined( 'ABSPATH' ) || exit;

/** Per-file approvals expire when bytes change; promotion requires a matching preview. */
final class SafeAssetTrials {
	public const OPTION    = 'arvaseo_connector_asset_trials';
	public const FEATURES  = array( 'amd', 'body_css', 'jquery_migrate' );
	public const CSS_LIMIT = 32768;

	/**
	 * Applied source hashes for this request, never visitor telemetry.
	 *
	 * @var array<string,array<string,bool>>
	 */
	private static array $applied = array();

	/**
	 * Source reads shared by diagnostics and output transforms.
	 *
	 * @var array<string,array{url:string,text:string,hash:string}|null>
	 */
	private static array $files = array();

	/**
	 * Total source bytes read in this request.
	 *
	 * @var int
	 */
	private static int $bytes = 0;

	/** Reset request-local observations. */
	public static function reset(): void {
		self::$applied = array();
		self::$files   = array();
		self::$bytes   = 0;
	}

	/**
	 * Explicit settings; existing installations default to off.
	 *
	 * @return array<string,array{state:string,assets:array<array<string,string>>,token:string}>
	 */
	public static function settings(): array {
		$saved = get_option( self::OPTION, array() );
		$out   = array();
		foreach ( self::FEATURES as $feature ) {
			$row             = is_array( $saved ) && is_array( $saved[ $feature ] ?? null ) ? $saved[ $feature ] : array();
			$assets          = is_array( $row['assets'] ?? null ) ? array_slice( $row['assets'], 0, 20 ) : array();
			$assets          = array_values( array_filter( $assets, static fn ( $asset ): bool => is_array( $asset ) && is_string( $asset['url'] ?? null ) && is_string( $asset['hash'] ?? null ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $asset['hash'] ) ) );
			$out[ $feature ] = array(
				'state'  => in_array( $row['state'] ?? '', array( 'trying', 'on' ), true ) ? $row['state'] : 'off',
				'assets' => $assets,
				'token'  => is_string( $row['token'] ?? null ) ? $row['token'] : '',
			);
		}
		return $out;
	}

	/** Prevent a signed-in trial response from entering a shared page cache. */
	public static function private_preview(): void {
		foreach ( self::settings() as $feature => $row ) {
			if ( 'trying' === $row['state'] && self::active( $feature ) ) {
				nocache_headers();
				if ( ! defined( 'DONOTCACHEPAGE' ) ) {
					define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- standard cache opt-out.
				}
				return;
			}
		}
	}

	/** Persistent administrator reminder, including when the Speed screen is not open. */
	public static function notice(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) || ! array_filter( self::settings(), static fn ( array $row ): bool => 'off' !== $row['state'] ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'Arva SEO has reviewed asset trials configured. AMD minification, body CSS changes and removing jQuery Migrate can affect navigation, galleries, testimonials or older plugins. Review their status and warnings after theme or plugin updates.', 'arvaseo-connector' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=arvaseo-speed#arvaseo-asset-trials' ) ) . '">' . esc_html__( 'Review asset trials', 'arvaseo-connector' ) . '</a></p></div>';
	}

	/**
	 * A feature applies only on eligible public pages; Trying is administrator-only.
	 *
	 * @param string $feature Feature key.
	 */
	public static function active( string $feature ): bool {
		$state = self::settings()[ $feature ]['state'] ?? 'off';
		return ! ( defined( Fixes::KILL_SWITCH ) && constant( Fixes::KILL_SWITCH ) )
			&& ! is_admin() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) && ! CssOptimizer::is_render_request()
			&& null === Exclusions::page_reason( 'amd' === $feature ? 'minify_js' : ( 'body_css' === $feature ? 'critical_css' : 'wp_extras' ) )
			&& ( 'on' === $state || ( 'trying' === $state && current_user_can( Capabilities::MANAGE ) ) );
	}

	/**
	 * A proof is tied to the chosen files and this exact trial, not a prior preview.
	 *
	 * @param string $feature Feature key.
	 */
	public static function fingerprint( string $feature ): string {
		return hash( 'sha256', (string) wp_json_encode( array( self::settings()[ $feature ] ?? array(), Fixes::stored() ) ) );
	}

	/**
	 * Proof only when every selected source was transformed.
	 *
	 * @return array<string,string> Successful transformations in this request.
	 */
	public static function proofs(): array {
		$out = array();
		foreach ( self::FEATURES as $feature ) {
			if ( 'jquery_migrate' === $feature && in_array( 'jquery-migrate', wp_scripts()->done, true ) ) {
				continue;
			}
			$required = 'jquery_migrate' === $feature ? array( 'migrate' ) : array_map( static fn ( array $asset ): string => $asset['url'] . '|' . $asset['hash'], self::settings()[ $feature ]['assets'] );
			if ( self::active( $feature ) && $required && ! array_diff( $required, array_keys( self::$applied[ $feature ] ?? array() ) ) ) {
				$out[ $feature ] = self::fingerprint( $feature );
			}
		}
		return $out;
	}

	/**
	 * Change a trial from a capability- and nonce-checked admin action.
	 * No direct off-to-on transition, stale preview or unchecked warning can activate it.
	 *
	 * @param string $feature Feature key.
	 * @param string $action try, enable or off.
	 * @param int[]  $selected Candidate row numbers from this user's report.
	 * @param bool   $checked Administrator checked the interaction checklist.
	 * @return string Result code for the admin notice.
	 */
	public static function change( string $feature, string $action, array $selected, bool $checked ): string {
		if ( ! current_user_can( Capabilities::MANAGE ) || ! in_array( $feature, self::FEATURES, true ) ) {
			return 'invalid';
		}
		$data   = self::settings();
		$report = AssetDiagnostics::latest();
		if ( 'off' === $action ) {
			$data[ $feature ] = array(
				'state'  => 'off',
				'assets' => array(),
				'token'  => '',
			);
		} elseif ( 'try' === $action && $checked ) {
			$assets = array();
			foreach ( array_slice( $selected, 0, 20 ) as $index ) {
				$candidate = $report['rows'][ $index ]['candidate'] ?? null;
				if ( is_array( $candidate ) && ( $candidate['feature'] ?? '' ) === $feature ) {
					$assets[ $candidate['url'] . '|' . $candidate['hash'] ] = $candidate;
				}
			}
			if ( 'jquery_migrate' !== $feature && array() === $assets ) {
				return 'select';
			}
			$data[ $feature ] = array(
				'state'  => 'trying',
				'assets' => array_values( $assets ),
				'token'  => wp_generate_password( 24, false ),
			);
		} elseif ( 'enable' === $action && $checked && 'trying' === $data[ $feature ]['state']
			&& ! empty( $report['complete'] ) && isset( $report['trial_proofs'][ $feature ] ) && hash_equals( self::fingerprint( $feature ), $report['trial_proofs'][ $feature ] ) ) {
			$data[ $feature ]['state'] = 'on';
		} else {
			return 'check';
		}
		update_option( self::OPTION, $data, false );
		Fixes::pages_changed( 'choices' );
		return 'saved';
	}

	/**
	 * Read at most 1 MB per file, 4 MB and 60 distinct addresses per request, never HTTP.
	 *
	 * @param string $url Local asset URL.
	 * @param string $kind css or js.
	 * @return array{url:string,text:string,hash:string}|null
	 */
	public static function file( string $url, string $kind ): ?array {
		$key = $kind . '|' . $url;
		if ( array_key_exists( $key, self::$files ) ) {
			return self::$files[ $key ];
		}
		if ( count( self::$files ) >= 60 ) {
			return null;
		}
		self::$files[ $key ] = null;
		$asset               = AssetMinifier::source( $url, $kind, true );
		if ( null === $asset ) {
			return null;
		}
		$size = filesize( $asset['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_filesize -- canonical local asset.
		if ( false === $size || $size < 1 || $size > 1048576 || self::$bytes + $size > 4194304 ) {
			return null;
		}
		self::$bytes += $size;
		$text         = file_get_contents( $asset['path'], false, null, 0, 1048577 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- bounded local read only.
		if ( ! is_string( $text ) || strlen( $text ) > 1048576 || str_contains( $text, "\0" ) || 1 !== preg_match( '//u', $text ) ) {
			return null;
		}
		self::$files[ $key ] = array(
			'url'  => $asset['url'],
			'text' => $text,
			'hash' => hash( 'sha256', $text ),
		);
		return self::$files[ $key ];
	}

	/**
	 * Recognize the jQuery-only UMD wrapper used by SmartMenus. No rewriting of the wrapper.
	 * Only its one static AMD call is exempted; every other relocation guard still applies.
	 *
	 * @param string $text Original source.
	 */
	public static function supported_amd( string $text ): bool {
		$prefix = '~\A\s*(?:/\*.*?\*/\s*)*\(function\(factory\)\s*\{\s*if\s*\(typeof define === [\'\"]function[\'\"] && define\.amd\)\s*\{\s*(?://[^\r\n]*[\r\n]+\s*)?define\(\[[\'\"]jquery[\'\"]\], factory\);\s*\} else if \(typeof module === [\'\"]object[\'\"] && typeof module\.exports === [\'\"]object[\'\"]\) \{\s*(?://[^\r\n]*[\r\n]+\s*)?module\.exports = factory\(require\([\'\"]jquery[\'\"]\)\);\s*\} else \{\s*(?://[^\r\n]*[\r\n]+\s*)?factory\(jQuery\);\s*\}\s*\} \(function\(\$\) \{~s';
		if ( ! preg_match( $prefix, $text ) || 1 !== preg_match_all( '/\bdefine\s*\(/', $text ) || 1 !== preg_match_all( '/\brequire\s*\(/', $text ) ) {
			return false;
		}
		$rest = preg_replace( '/\bdefine\(\[[\'\"]jquery[\'\"]\], factory\);/', '', $text, 1 );
		return is_string( $rest ) && null === AssetMinifier::script_obstacle( $rest )
			&& ! preg_match( '/\b(?:eval|Function|fetch|importScripts)\s*\(|\bdocument\s*\[|\brequire\s*\(\s*[\'\"]\./', $rest );
	}

	/**
	 * Only the exact reviewed bytes at the reviewed address qualify.
	 *
	 * @param string $feature Feature key.
	 * @param string $url Canonical source URL.
	 * @param string $text Source bytes.
	 */
	public static function approved( string $feature, string $url, string $text ): bool {
		if ( ! self::active( $feature ) ) {
			return false;
		}
		foreach ( self::settings()[ $feature ]['assets'] as $asset ) {
			if ( is_array( $asset ) && ( $asset['url'] ?? '' ) === $url && hash_equals( (string) ( $asset['hash'] ?? '' ), hash( 'sha256', $text ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Mark an actual output transformation, not just eligibility.
	 *
	 * @param string $feature Feature key.
	 * @param string $hash Reviewed source hash, or migrate for the dependency trial.
	 * @param string $url Canonical source address.
	 */
	public static function applied( string $feature, string $hash, string $url = '' ): void {
		self::$applied[ $feature ][ '' === $url ? $hash : $url . '|' . $hash ] = true;
	}

	/**
	 * A selectable report candidate, without source bytes or query strings.
	 *
	 * @param string $url Source URL.
	 * @param string $kind css or js.
	 * @param string $id Tag ID.
	 * @param bool   $head Whether in head.
	 * @return array<string,mixed>|null
	 */
	public static function candidate( string $url, string $kind, string $id, bool $head ): ?array {
		$file = self::file( $url, $kind );
		if ( null === $file || '' === $id ) {
			return null;
		}
		$feature = 'js' === $kind && self::supported_amd( $file['text'] ) ? 'amd' : ( 'css' === $kind && ! $head && self::body_css( $file['text'], $file['url'] ) !== null ? 'body_css' : '' );
		if ( '' === $feature ) {
			return null;
		}
		return array(
			'feature' => $feature,
			'url'     => $file['url'],
			'hash'    => $file['hash'],
			'id'      => substr( sanitize_text_field( $id ), 0, 160 ),
		);
	}

	/**
	 * Whole CSS in the same position, with absolute resource addresses. Never remove rules.
	 *
	 * @param string $text Original CSS.
	 * @param string $source Original URL.
	 */
	public static function body_css( string $text, string $source ): ?string {
		if ( strlen( $text ) > 131072 || preg_match( '~@(?:import|charset|namespace)\b|</style|url\(\s*[\'\"]?\s*#~i', $text ) ) {
			return null;
		}
		$relocated = AssetBundles::relocate_css( $text, $source );
		if ( null === $relocated ) {
			return null;
		}
		try {
			$css = ( new InlineCssMinifier( $relocated, $source ) )->execute();
		} catch ( \Throwable $error ) {
			return null;
		}
		return is_string( $css ) && '' !== $css && strlen( $css ) <= self::CSS_LIMIT ? $css : null;
	}

	/**
	 * Inline selected small body styles in place. All unsupported tags remain unchanged.
	 * Called inside the combiner's existing HTML and CSP guards.
	 *
	 * @param string $html Final HTML.
	 */
	public static function inline_body_css( string $html ): string {
		if ( ! self::active( 'body_css' ) ) {
			return $html;
		}
		$position = 0;
		$visited  = 0;
		$body     = false;
		$edits    = array();
		$bytes    = 0;
		for ( $start = strpos( $html, '<' ); false !== $start; $start = strpos( $html, '<', $position ) ) {
			$token = AssetCombiner::token( $html, $start );
			if ( null === $token || ++$visited > 10000 ) {
				return $html;
			}
			$position = $token['end'];
			if ( 'body' === $token['tag'] ) {
				$body = ! $token['close'];
			}
			if ( ! $body || 'link' !== $token['tag'] || $token['close'] ) {
				continue;
			}
			$tag = new \WP_HTML_Tag_Processor( $token['open'] );
			if ( ! $tag->next_tag() || 'stylesheet' !== $tag->get_attribute( 'rel' ) || array_diff( $tag->get_attribute_names_with_prefix( '' ) ?? array(), array( 'id', 'href', 'rel', 'media', 'type', 'data-arvaseo-combined', 'data-arvaseo-minified' ) ) ) {
				continue;
			}
			if ( true === $tag->get_attribute( 'media' ) || ! in_array( strtolower( (string) $tag->get_attribute( 'type' ) ), array( '', 'text/css' ), true ) ) {
				continue;
			}
			$id     = (string) $tag->get_attribute( 'id' );
			$handle = (string) preg_replace( '/-css$/', '', $id );
			if ( '' === $id || ! str_ends_with( $id, '-css' ) || in_array( $handle, Fixes::stored()['css_exclude'], true ) ) {
				continue;
			}
			$file = self::file( (string) $tag->get_attribute( 'href' ), 'css' );
			if ( null === $file || ! self::approved( 'body_css', $file['url'], $file['text'] ) ) {
				continue;
			}
			$css = self::body_css( $file['text'], $file['url'] );
			if ( null === $css || $bytes + strlen( $css ) > 65536 ) {
				continue;
			}
			$bytes      += strlen( $css );
			$replacement = '<style id="' . esc_attr( $id ) . '" media="' . esc_attr( (string) ( $tag->get_attribute( 'media' ) ?? 'all' ) ) . '" data-arvaseo-body-css="1">' . $css . '</style>';
			$edits[]     = array( $start, $token['end'] - $start, $replacement, $file['hash'], $file['url'] );
		}
		foreach ( array_reverse( $edits ) as $edit ) {
			$html = substr_replace( $html, $edit[2], $edit[0], $edit[1] );
			self::applied( 'body_css', $edit[3], $edit[4] );
		}
		return $html;
	}

	/** Remove the compatibility shim only when no other queued dependency asks for it. */
	public static function remove_migrate(): void {
		if ( ! self::active( 'jquery_migrate' ) ) {
			return;
		}
		$scripts = wp_scripts();
		$seen    = array();
		$queued  = false;
		$needs   = static function ( string $handle ) use ( &$needs, &$seen, &$queued, $scripts ): bool {
			if ( 'jquery-migrate' === $handle ) {
				return true;
			}
			if ( 'jquery' === $handle ) {
				$queued = true;
				return false;
			}
			if ( isset( $seen[ $handle ] ) || ! isset( $scripts->registered[ $handle ] ) ) {
				return false;
			}
			$seen[ $handle ] = true;
			foreach ( $scripts->registered[ $handle ]->deps as $dependency ) {
				if ( $needs( $dependency ) ) {
					return true;
				}
			}
			return false;
		};
		foreach ( $scripts->queue as $handle ) {
			if ( $needs( $handle ) ) {
				return;
			}
		}
		$jquery = $scripts->registered['jquery'] ?? null;
		if ( $queued && ! in_array( 'jquery-migrate', $scripts->done, true ) && $jquery && in_array( 'jquery-migrate', $jquery->deps, true ) ) {
			$jquery->deps = array_values( array_diff( $jquery->deps, array( 'jquery-migrate' ) ) );
			self::applied( 'jquery_migrate', 'migrate' );
		}
	}
}
