<?php
/**
 * Deep scans (2.1.0): the files the security scan couldn't match to an official copy are sent to
 * Arva SEO, checked there with ClamAV's database of known malware and Arva SEO's own rules, and
 * deleted straight after. Started by an administrator, within the website's deep scans a month,
 * and only with its own consent.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Security;

use ArvaSEO\Connector\Api\ApiClient;
use ArvaSEO\Connector\Api\ApiResponse;
use ArvaSEO\Connector\Auth\TokenStore;
use ArvaSEO\Connector\Logging\AuditLogger;
use ArvaSEO\Connector\Privacy\PrivacyService;
use ArvaSEO\Connector\Support\Lock;

defined( 'ABSPATH' ) || exit;

/**
 * Steps, a batch at a time on WP-Cron: local (a fresh security scan first, when the last one is
 * more than a day old) → manifest (each file's SHA-256 and size) → register (Arva SEO answers
 * which files it needs: files it checked in the last few days aren't sent again) → upload (about
 * 2 MB a request) → finish → waiting (the result is fetched every 30 seconds) → done. What a deep
 * scan finds joins the security scan's findings, so "This is expected", the Overview and the
 * results sent to Arva SEO work the same way.
 */
final class DeepScan {

	public const HOOK = 'arvaseo_connector_deep_scan_batch';

	public const OPTION = 'arvaseo_connector_deep_scan';

	/** What the last finished deep scan found (carried into later security scans while the file is unchanged). */
	public const RESULTS = 'arvaseo_connector_deep_scan_results';

	/** The files the last security scan couldn't match to an official copy. */
	public const CANDIDATES = 'arvaseo_connector_security_candidates';

	/** This website's deep scans this month, as Arva SEO last said. */
	private const ALLOWANCE = 'arvaseo_connector_deep_scan_allowance';

	/** The most files the security scan remembers for a deep scan. */
	public const MAX_CANDIDATES = 20000;

	public const MAX_FILES = 5000;

	public const MAX_FILE_BYTES = 2000000;

	public const MAX_BYTES = 50000000;

	/** Files per upload request, by size (base64 makes it a third larger). */
	private const UPLOAD_BYTES = 2000000;

	private const SECONDS = 20;

	/** How long to wait for Arva SEO's result before giving up. */
	private const WAIT = 2 * HOUR_IN_SECONDS;

	/** Never sent, whatever the scan found: configuration, secrets and anything that isn't code. */
	private const REFUSED = '~(?:^|/)(?:wp-config(?:-[^/]*)?\.php|\.env(?:\.[^/]*)?|\.git/.*|[^/]*\.(?:log|sql|bak|old|orig|swp|zip|gz|tgz|tar|rar|7z))$~i';

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( self::HOOK, array( self::class, 'batch' ) );
		add_action( 'arvaseo_connector_security_scanned', array( self::class, 'after_local_scan' ) );
	}

	/**
	 * The deep scan's state.
	 *
	 * @return array<string, mixed>
	 */
	public static function state(): array {
		$state = get_option( self::OPTION );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * Whether one is under way.
	 */
	public static function running(): bool {
		return in_array( self::state()['step'] ?? '', array( 'local', 'manifest', 'register', 'upload', 'finish', 'waiting' ), true );
	}

	/**
	 * What the step is doing, for the Health page.
	 *
	 * @param string $step Step.
	 */
	public static function step_label( string $step ): string {
		return match ( $step ) {
			'local'    => __( 'Running the security scan first', 'arvaseo-connector' ),
			'manifest' => __( 'Listing the files to check', 'arvaseo-connector' ),
			'register', 'upload', 'finish' => __( 'Sending the files to Arva SEO', 'arvaseo-connector' ),
			default    => __( 'Scanning on Arva SEO’s servers', 'arvaseo-connector' ),
		};
	}

	/**
	 * Whether deep scans can be started here: connected, and an administrator allowed them.
	 */
	public static function allowed(): bool {
		return TokenStore::is_connected() && PrivacyService::allows( 'deep_scan' );
	}

	/**
	 * This website's deep scans this month (included, used, top-ups, left), as Arva SEO last said;
	 * asked again when older than ten minutes (a short request).
	 *
	 * @param bool $refresh Ask Arva SEO now.
	 * @return array{included: int, used: int, packs: int, remaining: int, resets_at: string, buy_url: string|null}|null
	 */
	public static function allowance( bool $refresh = false ): ?array {
		$cached = get_transient( self::ALLOWANCE );
		if ( ! $refresh && is_array( $cached ) ) {
			return $cached;
		}
		if ( ! TokenStore::is_connected() ) {
			return null;
		}
		$response = ApiClient::instance()->site( 'GET', '/deep-scans', null, 5 );
		if ( ! $response->ok() ) {
			return is_array( $cached ) ? $cached : null;
		}
		return self::remember_allowance( (array) ( $response->data()['allowance'] ?? array() ) );
	}

	/**
	 * Start a deep scan. A security scan runs first when the last one is more than a day old.
	 */
	public static function start(): bool {
		if ( self::running() || ! self::allowed() ) {
			return false;
		}
		$candidates = get_option( self::CANDIDATES );
		$fresh      = is_array( $candidates ) && (int) ( $candidates['scanned_at'] ?? 0 ) > time() - DAY_IN_SECONDS;
		self::save(
			array(
				'step'       => $fresh ? 'manifest' : 'local',
				'started_at' => time(),
				'scan_id'    => wp_generate_uuid4(),
				'index'      => 0,
				'files'      => array(),
				'bytes'      => 0,
				'partial'    => false,
				'attempts'   => 0,
			)
		);
		if ( ! $fresh && ! Scanner::running() ) {
			Scanner::start( 'deep' );
		}
		self::schedule( 0 );
		AuditLogger::info( 'security.deep_scan_started', __( 'Deep scan started.', 'arvaseo-connector' ) );
		return true;
	}

	/**
	 * Stop (nothing more is sent; a scan already on Arva SEO's servers isn't charged unless it finishes).
	 */
	public static function stop(): void {
		if ( ! self::running() ) {
			return;
		}
		$state = self::state();
		self::save( array_merge( $state, array( 'step' => 'stopped' ) ) );
		wp_clear_scheduled_hook( self::HOOK );
		AuditLogger::info( 'security.deep_scan_stopped', __( 'Deep scan stopped.', 'arvaseo-connector' ) );
	}

	/**
	 * After a security scan: carry on with a deep scan that was waiting for it.
	 */
	public static function after_local_scan(): void {
		$state = self::state();
		if ( 'local' === ( $state['step'] ?? '' ) ) {
			$state['step'] = 'manifest';
			self::save( $state );
			self::schedule( 0 );
		}
	}

	/**
	 * Run a batch when the last one was a while ago (the Health page's progress check).
	 */
	public static function tick(): void {
		$state = self::state();
		if ( self::running() && 'local' !== $state['step'] && (int) ( $state['last_batch_at'] ?? 0 ) < time() - 15 ) {
			self::batch( 8 );
		}
	}

	/**
	 * One batch: as many steps as fit in the time.
	 *
	 * @param float $seconds Seconds of work.
	 */
	public static function batch( float $seconds = self::SECONDS ): void {
		Lock::with(
			'deep_scan',
			(int) ceil( $seconds ) + 120,
			static function () use ( $seconds ): void {
				$until = microtime( true ) + $seconds;
				$state = self::state();
				do {
					$before = $state['step'] ?? '';
					$state  = match ( $before ) {
						'manifest' => self::manifest( $state, $until ),
						'register' => self::register( $state ),
						'upload'   => self::upload( $state, $until ),
						'finish'   => self::finish( $state ),
						'waiting'  => self::check( $state ),
						default    => $state,
					};
					// A step that has to wait (for the security scan, for Arva SEO, after an error) ends the batch.
				} while ( ( $state['step'] ?? '' ) !== $before && self::is_working( $state ) && microtime( true ) < $until );
				$state['last_batch_at'] = time();
				self::save( $state );
				if ( self::is_working( $state ) && 'local' !== $state['step'] ) {
					self::schedule( max( 5, (int) ( $state['next_at'] ?? 0 ) - time() ) );
				}
			}
		);
	}

	// ----- Steps --------------------------------------------------------------------------

	/**
	 * Each file's SHA-256 and size, from the security scan's list.
	 *
	 * @param array<string, mixed> $state State.
	 * @param float                $until Deadline.
	 * @return array<string, mixed>
	 */
	private static function manifest( array $state, float $until ): array {
		$candidates = get_option( self::CANDIDATES );
		$paths      = is_array( $candidates ) ? array_values( (array) ( $candidates['paths'] ?? array() ) ) : array();
		$count      = count( $paths );
		$root       = wp_normalize_path( ABSPATH );
		$first      = (int) $state['index'];
		for ( $i = $first; $i < $count; $i++ ) {
			if ( $i > $first && microtime( true ) >= $until ) {
				$state['index'] = $i;
				return $state;
			}
			$path = (string) $paths[ $i ];
			if ( ! self::sendable( $path ) ) {
				continue;
			}
			$file = $root . $path;
			$size = @filesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a file deleted since the scan is skipped.
			if ( false === $size || $size < 1 || $size > self::MAX_FILE_BYTES || ! is_readable( $file ) ) {
				continue;
			}
			if ( count( $state['files'] ) >= self::MAX_FILES || (int) $state['bytes'] + $size > self::MAX_BYTES ) {
				$state['partial'] = true;
				break;
			}
			$sha = hash_file( 'sha256', $file );
			if ( false === $sha ) {
				continue;
			}
			$state['files'][] = array(
				'path'   => $path,
				'sha256' => $sha,
				'size'   => (int) $size,
			);
			$state['bytes']  += (int) $size;
		}
		if ( array() === $state['files'] ) {
			return self::end( $state, 'nothing', __( 'Every file the security scan looked at is an official copy, so there was nothing to send for a deep scan.', 'arvaseo-connector' ) );
		}
		$state['partial'] = $state['partial'] || ! empty( $candidates['partial'] );
		$state['step']    = 'register';
		return $state;
	}

	/**
	 * Tell Arva SEO what there is; it answers which files it needs.
	 *
	 * @param array<string, mixed> $state State.
	 * @return array<string, mixed>
	 */
	private static function register( array $state ): array {
		$response = ApiClient::instance()->site(
			'POST',
			'/deep-scans',
			array(
				'schema_version'       => '1.0',
				'site_registration_id' => TokenStore::site_id(),
				'scan_id'              => (string) $state['scan_id'],
				'partial'              => (bool) $state['partial'],
				'files'                => (array) $state['files'],
			),
			30
		);
		if ( ! $response->ok() ) {
			return self::failed( $state, $response );
		}
		$data = $response->data();
		self::remember_allowance( (array) ( $response->body['allowance'] ?? array() ) );
		$state['deep_scan_id'] = (string) ( $data['deep_scan_id'] ?? '' );
		$state['needed']       = array_values( array_map( 'strval', (array) ( $data['needed'] ?? array() ) ) );
		$state['index']        = 0;
		$state['attempts']     = 0;
		$state['step']         = 'queued' === ( $data['status'] ?? '' ) ? 'waiting' : 'upload';
		$state['next_at']      = time() + 20;
		return $state;
	}

	/**
	 * Send the files asked for, about 2 MB a request.
	 *
	 * @param array<string, mixed> $state State.
	 * @param float                $until Deadline.
	 * @return array<string, mixed>
	 */
	private static function upload( array $state, float $until ): array {
		$needed = (array) $state['needed'];
		$paths  = array_column( (array) $state['files'], 'path', 'sha256' );
		$root   = wp_normalize_path( ABSPATH );
		$count  = count( $needed );
		$sent   = false;
		while ( (int) $state['index'] < $count ) {
			if ( $sent && microtime( true ) >= $until ) {
				return $state;
			}
			$batch = array();
			$bytes = 0;
			$i     = (int) $state['index'];
			while ( $i < $count && ( array() === $batch || $bytes + (int) @filesize( $root . ( $paths[ $needed[ $i ] ] ?? '' ) ) <= self::UPLOAD_BYTES ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a missing file is caught below.
				$sha      = (string) $needed[ $i ];
				$contents = isset( $paths[ $sha ] ) ? @file_get_contents( $root . $paths[ $sha ] ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file read for the deep scan the administrator started.
				if ( false === $contents || hash( 'sha256', $contents ) !== $sha ) {
					return self::end( $state, 'changed', __( 'Some files changed while the deep scan was sending them. Run it again.', 'arvaseo-connector' ) );
				}
				$batch[] = array(
					'sha256'  => $sha,
					'content' => base64_encode( $contents ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- files travel as base64 in JSON to Arva SEO's deep scan.
				);
				$bytes  += strlen( $contents );
				++$i;
			}
			$response = ApiClient::instance()->site( 'POST', '/deep-scans/' . rawurlencode( (string) $state['deep_scan_id'] ) . '/files', array( 'files' => $batch ), 60 );
			if ( ! $response->ok() ) {
				return self::failed( $state, $response );
			}
			$sent              = true;
			$state['index']    = $i;
			$state['attempts'] = 0;
		}
		$state['step'] = 'finish';
		return $state;
	}

	/**
	 * Every file is sent: Arva SEO scans them.
	 *
	 * @param array<string, mixed> $state State.
	 * @return array<string, mixed>
	 */
	private static function finish( array $state ): array {
		$response = ApiClient::instance()->site( 'POST', '/deep-scans/' . rawurlencode( (string) $state['deep_scan_id'] ) . '/finish', array() );
		if ( ! $response->ok() ) {
			return self::failed( $state, $response );
		}
		$state['step']    = 'waiting';
		$state['next_at'] = time() + 20;
		return $state;
	}

	/**
	 * Ask for the result.
	 *
	 * @param array<string, mixed> $state State.
	 * @return array<string, mixed>
	 */
	private static function check( array $state ): array {
		if ( time() < (int) ( $state['next_at'] ?? 0 ) ) {
			return $state;
		}
		if ( time() > (int) $state['started_at'] + self::WAIT ) {
			return self::end( $state, 'timeout', __( 'Arva SEO didn’t finish the deep scan in time, so it wasn’t counted. Try again later.', 'arvaseo-connector' ) );
		}
		$response = ApiClient::instance()->site( 'GET', '/deep-scans/' . rawurlencode( (string) $state['deep_scan_id'] ) );
		if ( ! $response->ok() ) {
			return self::failed( $state, $response );
		}
		$data = $response->data();
		self::remember_allowance( (array) ( $response->body['allowance'] ?? array() ) );
		$status = (string) ( $data['status'] ?? '' );
		if ( 'done' === $status ) {
			self::save_results( $data, $state );
			$state['step']        = 'done';
			$state['finished_at'] = time();
			$state['files']       = array();
			$state['needed']      = array();
			return $state;
		}
		if ( in_array( $status, array( 'failed', 'expired' ), true ) ) {
			return self::end( $state, $status, __( 'Arva SEO couldn’t finish the deep scan, so it wasn’t counted. Try again later.', 'arvaseo-connector' ) );
		}
		$state['next_at'] = time() + 30;
		return $state;
	}

	// ----- Results ------------------------------------------------------------------------

	/**
	 * Keep what the deep scan found, add it to the security scan's findings and send them.
	 *
	 * @param array<string, mixed> $data  The deep scan from Arva SEO.
	 * @param array<string, mixed> $state State.
	 */
	private static function save_results( array $data, array $state ): void {
		$paths    = array_column( (array) $state['files'], 'path' );
		$root     = wp_normalize_path( ABSPATH );
		$findings = array();
		foreach ( (array) ( $data['findings'] ?? array() ) as $item ) {
			$path = is_array( $item ) ? (string) ( $item['path'] ?? '' ) : '';
			$type = is_array( $item ) ? (string) ( $item['type'] ?? '' ) : '';
			// Only files this site sent, and only the two kinds a deep scan reports.
			if ( ! in_array( $path, $paths, true ) || ! in_array( $type, array( 'deep_malware', 'deep_code' ), true ) ) {
				continue;
			}
			$md5        = @md5_file( $root . $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a file deleted since has no hash.
			$findings[] = Findings::make(
				$type,
				(string) ( $item['severity'] ?? 'high' ),
				array(
					'path'      => $path,
					'signature' => self::text( $item['signature'] ?? null, 60 ),
					'title'     => self::text( $item['title'] ?? null, 200 ),
					'line'      => isset( $item['line'] ) ? (int) $item['line'] : null,
					'excerpt'   => self::text( $item['excerpt'] ?? null, 200 ),
					'file_hash' => false !== $md5 ? $md5 : null,
				)
			);
		}
		update_option(
			self::RESULTS,
			array(
				'finished_at' => gmdate( 'c' ),
				'files'       => (int) ( $data['files'] ?? 0 ),
				'scanned'     => (int) ( $data['scanned'] ?? 0 ),
				'partial'     => (bool) ( $data['partial'] ?? false ),
				'engine'      => array(
					'clamav' => self::text( $data['engine']['clamav'] ?? null, 80 ),
					'rules'  => self::text( $data['engine']['rules'] ?? null, 20 ),
				),
				'findings'    => $findings,
			),
			false
		);
		Findings::replace_deep( $findings );
		AuditLogger::info(
			'security.deep_scan_finished',
			/* translators: 1: number of files, 2: number of findings. */
			sprintf( __( 'Deep scan finished: %1$d files checked, %2$d findings.', 'arvaseo-connector' ), (int) ( $data['scanned'] ?? 0 ), count( $findings ) ),
			array( 'count' => count( $findings ) )
		);
		Report::send();
	}

	/**
	 * The last deep scan's results.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function results(): ?array {
		$value = get_option( self::RESULTS );
		return is_array( $value ) && isset( $value['findings'] ) ? $value : null;
	}

	/**
	 * What the last deep scan found that still applies: the file is there and unchanged. A
	 * security scan keeps these with its own findings.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function carried(): array {
		$root = wp_normalize_path( ABSPATH );
		return array_values(
			array_filter(
				(array) ( self::results()['findings'] ?? array() ),
				static function ( $finding ) use ( $root ): bool {
					if ( ! is_array( $finding ) || null === ( $finding['path'] ?? null ) ) {
						return false;
					}
					$md5 = @md5_file( $root . $finding['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a deleted file no longer has the problem.
					return false !== $md5 && ( $finding['file_hash'] ?? null ) === $md5;
				}
			)
		);
	}

	// ----- Helpers ------------------------------------------------------------------------

	/**
	 * Whether a file may be sent: inside the site, code, and not configuration or a secret.
	 *
	 * @param string $path Path from the main folder.
	 */
	public static function sendable( string $path ): bool {
		return '' !== $path && ! str_starts_with( $path, '/' ) && ! str_contains( $path, '..' ) && null !== Signatures::kind( $path ) && 1 !== preg_match( self::REFUSED, $path );
	}

	/**
	 * An answer from Arva SEO that stops the deep scan, or one worth trying again later.
	 *
	 * @param array<string, mixed> $state    State.
	 * @param ApiResponse          $response Response.
	 * @return array<string, mixed>
	 */
	private static function failed( array $state, ApiResponse $response ): array {
		if ( $response->retryable() && (int) $state['attempts'] < 6 ) {
			++$state['attempts'];
			$state['next_at'] = time() + max( 30, (int) $response->retry_after, 60 * (int) $state['attempts'] );
			return $state;
		}
		if ( 402 === $response->status ) {
			self::allowance( true );
			return self::end( $state, 'no_allowance', (string) ( $response->message ?? __( 'This website has no deep scans left this month.', 'arvaseo-connector' ) ) );
		}
		return self::end( $state, (string) ( $response->error_code ?? 'failed' ), (string) ( $response->message ?? __( 'The deep scan couldn’t finish. Try again later.', 'arvaseo-connector' ) ) );
	}

	/**
	 * Stop with a reason the Health page shows.
	 *
	 * @param array<string, mixed> $state   State.
	 * @param string               $code    Why.
	 * @param string               $message For the administrator.
	 * @return array<string, mixed>
	 */
	private static function end( array $state, string $code, string $message ): array {
		AuditLogger::warning( 'security.deep_scan_ended', $message, array( 'code' => $code ) );
		return array_merge(
			$state,
			array(
				'step'        => 'ended',
				'code'        => $code,
				'message'     => $message,
				'finished_at' => time(),
				'files'       => array(),
				'needed'      => array(),
			)
		);
	}

	/**
	 * Whether a step still has work.
	 *
	 * @param array<string, mixed> $state State.
	 */
	private static function is_working( array $state ): bool {
		return in_array( $state['step'] ?? '', array( 'local', 'manifest', 'register', 'upload', 'finish', 'waiting' ), true );
	}

	/**
	 * Keep the allowance Arva SEO sent.
	 *
	 * @param array<string, mixed> $allowance Allowance.
	 * @return array{included: int, used: int, packs: int, remaining: int, resets_at: string, buy_url: string|null}|null
	 */
	private static function remember_allowance( array $allowance ): ?array {
		if ( ! isset( $allowance['remaining'] ) ) {
			return null;
		}
		$clean = array(
			'included'  => (int) ( $allowance['included'] ?? 0 ),
			'used'      => (int) ( $allowance['used'] ?? 0 ),
			'packs'     => (int) ( $allowance['packs'] ?? 0 ),
			'remaining' => (int) $allowance['remaining'],
			'resets_at' => (string) ( $allowance['resets_at'] ?? '' ),
			// Only a link into the Arva SEO app.
			'buy_url'   => ApiClient::app_link( $allowance['buy_url'] ?? null ),
		);
		set_transient( self::ALLOWANCE, $clean, 10 * MINUTE_IN_SECONDS );
		return $clean;
	}

	/**
	 * Plain text from Arva SEO, cut to a length.
	 *
	 * @param mixed $value Value.
	 * @param int   $max   Most characters.
	 */
	private static function text( $value, int $max ): ?string {
		return is_string( $value ) && '' !== $value ? mb_substr( wp_strip_all_tags( $value ), 0, $max ) : null;
	}

	/**
	 * Save the state.
	 *
	 * @param array<string, mixed> $state State.
	 */
	private static function save( array $state ): void {
		update_option( self::OPTION, $state, false );
	}

	/**
	 * The next batch.
	 *
	 * @param int $delay Seconds.
	 */
	private static function schedule( int $delay ): void {
		wp_clear_scheduled_hook( self::HOOK );
		wp_schedule_single_event( time() + $delay, self::HOOK );
		if ( 0 === $delay && function_exists( 'spawn_cron' ) && ! wp_doing_cron() ) {
			spawn_cron();
		}
	}

	/**
	 * Remove everything (uninstall, tests).
	 */
	public static function clear(): void {
		wp_clear_scheduled_hook( self::HOOK );
		delete_option( self::OPTION );
		delete_option( self::RESULTS );
		delete_option( self::CANDIDATES );
		delete_transient( self::ALLOWANCE );
	}
}
