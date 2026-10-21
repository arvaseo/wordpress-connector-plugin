<?php
/**
 * The security scan's results: what each kind of finding means and how to fix it, the latest
 * results, and the findings marked "This is expected".
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Security;

defined( 'ABSPATH' ) || exit;

/**
 * A finding is [key, type, group, severity, path, signature, line, excerpt, file_hash, detail]. Its
 * key stays the same from scan to scan, so "This is expected" sticks; for a file it's tied to the
 * file's contents too, so a file that changes again is shown again. Wording never promises safety:
 * a scan finds known problems, it can't prove there are none.
 */
final class Findings {

	public const RESULTS = 'arvaseo_connector_security_results';

	public const IGNORED = 'arvaseo_connector_security_ignored';

	public const SEVERITIES = array( 'critical', 'high', 'medium', 'low' );

	/** The most findings kept from one scan (the most severe first). */
	public const MAX = 500;

	/**
	 * The groups, in the order Health shows them: [title, intro].
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function groups(): array {
		return array(
			'vulnerabilities' => array( __( 'Known vulnerabilities', 'arvaseo-connector' ), __( 'Installed versions with a published security hole.', 'arvaseo-connector' ) ),
			'deep'            => array( __( 'Found by the deep scan', 'arvaseo-connector' ), __( 'Known malware and hacking patterns found on Arva SEO’s servers in files that aren’t official copies.', 'arvaseo-connector' ) ),
			'code'            => array( __( 'Suspicious code', 'arvaseo-connector' ), __( 'Patterns hackers use, in files that aren’t official copies.', 'arvaseo-connector' ) ),
			'changed'         => array( __( 'Changed files', 'arvaseo-connector' ), __( 'WordPress and plugin files that differ from the official copies.', 'arvaseo-connector' ) ),
			'unexpected'      => array( __( 'Files that shouldn’t be there', 'arvaseo-connector' ), __( 'Code where WordPress never puts it.', 'arvaseo-connector' ) ),
			'exposed'         => array( __( 'Exposed files', 'arvaseo-connector' ), __( 'Private files anyone on the internet can download.', 'arvaseo-connector' ) ),
			'settings'        => array( __( 'Settings', 'arvaseo-connector' ), __( 'Settings that make an attack easier.', 'arvaseo-connector' ) ),
		);
	}

	/**
	 * A finding's group from its type.
	 *
	 * @param string $type Type.
	 */
	public static function group( string $type ): string {
		return match ( $type ) {
			'vulnerability'                                                                         => 'vulnerabilities',
			'deep_malware', 'deep_code'                                                             => 'deep',
			'code'                                                                                  => 'code',
			'core_changed', 'core_missing', 'plugin_changed'                                        => 'changed',
			'core_unexpected', 'root_unexpected', 'plugin_unexpected', 'uploads_php', 'uploads_disguised' => 'unexpected',
			'exposed_git', 'exposed_env', 'exposed_config_backup', 'exposed_debug_log', 'exposed_database', 'exposed_backup', 'directory_listing' => 'exposed',
			default                                                                                 => 'settings',
		};
	}

	/**
	 * Build a finding.
	 *
	 * @param string               $type     Type.
	 * @param string               $severity critical|high|medium|low.
	 * @param array<string, mixed> $details  path, signature, line, excerpt, file_hash, detail.
	 * @return array<string, mixed>
	 */
	public static function make( string $type, string $severity, array $details = array() ): array {
		$path = isset( $details['path'] ) ? (string) $details['path'] : null;
		$sig  = isset( $details['signature'] ) ? (string) $details['signature'] : null;
		return array(
			'key'       => substr( md5( $type . '|' . (string) $path . '|' . (string) $sig . '|' . (string) ( $details['subject'] ?? '' ) ), 0, 16 ),
			'type'      => $type,
			'group'     => self::group( $type ),
			'severity'  => in_array( $severity, self::SEVERITIES, true ) ? $severity : 'medium',
			'path'      => $path,
			'signature' => $sig,
			'title'     => isset( $details['title'] ) ? (string) $details['title'] : null,
			'line'      => isset( $details['line'] ) ? (int) $details['line'] : null,
			'excerpt'   => isset( $details['excerpt'] ) ? (string) $details['excerpt'] : null,
			'file_hash' => isset( $details['file_hash'] ) ? (string) $details['file_hash'] : null,
			'detail'    => isset( $details['detail'] ) ? (string) $details['detail'] : null,
		);
	}

	/**
	 * What a finding means and how to fix it: [title, why, fix].
	 *
	 * @param array<string, mixed> $finding Finding.
	 * @return array{0: string, 1: string, 2: string}
	 */
	public static function describe( array $finding ): array {
		$detail  = (string) ( $finding['detail'] ?? '' );
		$restore = __( 'If you didn’t change it yourself, reinstall the same version from WordPress.org (Dashboard → Updates → Re-install, or the plugin’s page) and change your passwords. Restore from a backup if more files are affected.', 'arvaseo-connector' );
		return match ( (string) $finding['type'] ) {
			'code'                  => array(
				(string) ( $finding['title'] ?? __( 'Suspicious code', 'arvaseo-connector' ) ),
				__( 'This file contains a pattern found in hacked websites. Some premium plugins and themes use similar tricks to hide their code, so check where the file comes from.', 'arvaseo-connector' ),
				__( 'If the file isn’t part of a plugin or theme you installed on purpose, it’s likely malware: restore the plugin or theme from a fresh copy, delete files you don’t recognise, then change your WordPress, hosting and database passwords. If it’s from a product you trust, mark it as expected.', 'arvaseo-connector' ),
			),
			'deep_malware'          => array(
				(string) ( $finding['title'] ?? __( 'Known malware', 'arvaseo-connector' ) ),
				__( 'ClamAV’s database of known malware matched this file: it’s code from a known hacking campaign or tool, not just a suspicious pattern, so the site has very likely been hacked.', 'arvaseo-connector' ),
				__( 'Restore the plugin or theme the file belongs to from a fresh copy, or delete the file if nothing you installed needs it. Then change your WordPress, hosting and database passwords, and look for how it got in (an outdated plugin, a nulled theme or a stolen password). Ask your host or a security specialist if more files are affected.', 'arvaseo-connector' ),
			),
			'deep_code'             => array(
				(string) ( $finding['title'] ?? __( 'Suspicious code', 'arvaseo-connector' ) ),
				__( 'The deep scan found a pattern from hacked websites in this file. A few premium plugins and themes hide their code in similar ways, so check where the file comes from.', 'arvaseo-connector' ),
				__( 'If the file isn’t part of a plugin or theme you installed on purpose, restore that plugin or theme from a fresh copy, delete files you don’t recognise and change your passwords. If it’s from a product you trust, mark it as expected.', 'arvaseo-connector' ),
			),
			'core_changed'          => array( __( 'A WordPress file was changed', 'arvaseo-connector' ), __( 'WordPress files are the same on every site. A changed one usually means a hack, or a developer edited WordPress itself (which updates undo).', 'arvaseo-connector' ), __( 'Dashboard → Updates → Re-install version puts back WordPress’s own files without touching your content. Then run the scan again.', 'arvaseo-connector' ) ),
			'core_missing'          => array( __( 'A WordPress file is missing', 'arvaseo-connector' ), __( 'A file that comes with WordPress isn’t there, so parts of the dashboard may fail, and it can be a sign that files were tampered with.', 'arvaseo-connector' ), __( 'Dashboard → Updates → Re-install version puts it back.', 'arvaseo-connector' ) ),
			'core_unexpected'       => array( __( 'An extra file among WordPress’s own', 'arvaseo-connector' ), __( 'WordPress never adds files to wp-admin or wp-includes outside updates, so hackers hide backdoors there.', 'arvaseo-connector' ), __( 'If nobody on your team put it there, delete it, then re-install WordPress from Dashboard → Updates and change your passwords.', 'arvaseo-connector' ) ),
			'root_unexpected'       => array( __( 'An extra PHP file in the site’s main folder', 'arvaseo-connector' ), __( 'Besides WordPress’s own files and wp-config.php, PHP files here are usually left-over tools or a hacker’s backdoor.', 'arvaseo-connector' ), __( 'If you don’t know what it’s for, ask your developer or host, then delete it.', 'arvaseo-connector' ) ),
			'plugin_changed'        => array( __( 'A plugin file was changed', 'arvaseo-connector' ), __( 'It differs from the plugin’s official copy on WordPress.org at this version.', 'arvaseo-connector' ), $restore ),
			'plugin_unexpected'     => array( __( 'An extra file inside a plugin', 'arvaseo-connector' ), __( 'This PHP file isn’t part of the plugin’s official copy on WordPress.org at this version.', 'arvaseo-connector' ), $restore ),
			'uploads_php'           => array( __( 'A PHP file in the uploads folder', 'arvaseo-connector' ), __( 'The uploads folder is for images and documents. PHP there is how most hacks keep a way back in.', 'arvaseo-connector' ), __( 'Delete it unless a plugin you trust put it there on purpose (a few caching and backup plugins do), then change your passwords.', 'arvaseo-connector' ) ),
			'uploads_disguised'     => array( __( 'Code disguised as an image', 'arvaseo-connector' ), __( 'This file has an image’s name but starts with PHP code: a common way to hide a backdoor.', 'arvaseo-connector' ), __( 'Delete it, look for the code that loads it (the scan may list it under Suspicious code), and change your passwords.', 'arvaseo-connector' ) ),
			'exposed_git'           => array( __( 'Your site’s .git folder can be downloaded', 'arvaseo-connector' ), __( 'Anyone can rebuild your site’s code from it, including passwords that were ever committed.', 'arvaseo-connector' ), __( 'Delete the .git folder from the web server, or ask your host to block access to it.', 'arvaseo-connector' ) ),
			'exposed_env'           => array( __( 'A .env file can be downloaded', 'arvaseo-connector' ), __( 'These files usually hold passwords and keys.', 'arvaseo-connector' ), __( 'Move it out of the public folder or delete it, then change every password and key that was in it.', 'arvaseo-connector' ) ),
			'exposed_config_backup' => array( __( 'A copy of wp-config.php can be downloaded', 'arvaseo-connector' ), __( 'It contains your database password and security keys, readable by anyone who finds it.', 'arvaseo-connector' ), __( 'Delete the copy, then change your database password and WordPress’s security keys.', 'arvaseo-connector' ) ),
			'exposed_debug_log'     => array( __( 'The debug log can be downloaded', 'arvaseo-connector' ), __( 'Error logs show file paths and sometimes personal or private data.', 'arvaseo-connector' ), __( 'Delete wp-content/debug.log and turn off WP_DEBUG_LOG on a live site, or log to a folder outside the website.', 'arvaseo-connector' ) ),
			'exposed_database'      => array( __( 'A database export can be downloaded', 'arvaseo-connector' ), __( 'It holds everything in your site, including users’ details.', 'arvaseo-connector' ), __( 'Delete it from the website’s folders and keep backups somewhere private.', 'arvaseo-connector' ) ),
			'exposed_backup'        => array( __( 'A backup file can be downloaded', 'arvaseo-connector' ), __( 'Backups in the website’s folder can include your configuration and code.', 'arvaseo-connector' ), __( 'Move it out of the website’s folders, or delete it.', 'arvaseo-connector' ) ),
			'directory_listing'     => array( __( 'Folder contents are listed', 'arvaseo-connector' ), __( 'Anyone can browse every file in your uploads folder, including ones you didn’t link to.', 'arvaseo-connector' ), __( 'Ask your host to turn off directory listing (Options -Indexes), or add an empty index.php to the folder.', 'arvaseo-connector' ) ),
			'file_editing'          => array( __( 'Plugins and themes can be edited in the dashboard', 'arvaseo-connector' ), __( 'Anyone who gets into an administrator account can add code to the site in a few clicks.', 'arvaseo-connector' ), __( 'Add define( \'DISALLOW_FILE_EDIT\', true ); to wp-config.php.', 'arvaseo-connector' ) ),
			'admin_username'        => array( __( 'An administrator is called “admin”', 'arvaseo-connector' ), __( 'It’s the first name every password-guessing attack tries.', 'arvaseo-connector' ), __( 'Create a new administrator with another name, sign in as it, and delete “admin”, giving its posts to the new user.', 'arvaseo-connector' ) ),
			'xmlrpc'                => array( __( 'XML-RPC answers', 'arvaseo-connector' ), __( 'Old apps use it, and attackers use it to try many passwords in one request. Jetpack and some apps still need it.', 'arvaseo-connector' ), __( 'If nothing you use needs it, turn it off with a security plugin or ask your host to block xmlrpc.php.', 'arvaseo-connector' ) ),
			'user_listing'          => array( __( 'Usernames can be listed', 'arvaseo-connector' ), __( 'Attackers collect usernames this way before guessing passwords.', 'arvaseo-connector' ), __( 'A security plugin can hide author pages and the users list in the REST API from visitors.', 'arvaseo-connector' ) ),
			'vulnerability'         => array( '' !== $detail ? $detail : __( 'A known vulnerability', 'arvaseo-connector' ), __( 'This version has a published security hole that attackers scan for.', 'arvaseo-connector' ), __( 'Update it now. If there’s no fixed version yet, deactivate it until there is.', 'arvaseo-connector' ) ),
			default                 => array( __( 'Security finding', 'arvaseo-connector' ), $detail, '' ),
		};
	}

	/**
	 * The latest results.
	 *
	 * @return array{scanned_at: string|null, seconds: int, files: int, findings: array<int, array<string, mixed>>, coverage: array<string, mixed>, partial: bool, signatures: string}|null
	 */
	public static function latest(): ?array {
		$value = get_option( self::RESULTS );
		return is_array( $value ) && isset( $value['findings'] ) ? $value : null;
	}

	/**
	 * Save a finished scan's results.
	 *
	 * @param array<int, array<string, mixed>> $findings Findings.
	 * @param array<string, mixed>             $meta     scanned_at, seconds, files, coverage, partial.
	 */
	public static function save( array $findings, array $meta ): void {
		// What the last deep scan found stays while the file is there and unchanged.
		$findings = array_merge( $findings, DeepScan::carried() );
		$order    = array_flip( self::SEVERITIES );
		usort( $findings, static fn ( array $a, array $b ): int => ( $order[ $a['severity'] ] ?? 9 ) <=> ( $order[ $b['severity'] ] ?? 9 ) );
		update_option(
			self::RESULTS,
			array_merge(
				array(
					'scanned_at' => gmdate( 'c' ),
					'seconds'    => 0,
					'files'      => 0,
					'coverage'   => array(),
					'partial'    => false,
					'signatures' => Signatures::version(),
				),
				$meta,
				array( 'findings' => array_slice( $findings, 0, self::MAX ) )
			),
			false
		);
	}

	/**
	 * Put a deep scan's findings in place of the previous deep scan's.
	 *
	 * @param array<int, array<string, mixed>> $deep Findings of the types deep_malware and deep_code.
	 */
	public static function replace_deep( array $deep ): void {
		$results = self::latest();
		if ( null === $results ) {
			return;
		}
		$kept  = array_filter( (array) $results['findings'], static fn ( array $f ): bool => 'deep' !== ( $f['group'] ?? '' ) );
		$order = array_flip( self::SEVERITIES );
		$all   = array_merge( $deep, array_values( $kept ) );
		usort( $all, static fn ( array $a, array $b ): int => ( $order[ $a['severity'] ] ?? 9 ) <=> ( $order[ $b['severity'] ] ?? 9 ) );
		$results['findings'] = array_slice( $all, 0, self::MAX );
		update_option( self::RESULTS, $results, false );
	}

	/**
	 * Take a finding out of the latest results (after a repair fixed it), and out of the last
	 * deep scan's so it isn't carried into the next scan.
	 *
	 * @param string $key Finding key.
	 */
	public static function forget( string $key ): void {
		$results = self::latest();
		if ( null !== $results ) {
			$results['findings'] = array_values( array_filter( (array) $results['findings'], static fn ( array $f ): bool => $key !== $f['key'] ) );
			update_option( self::RESULTS, $results, false );
		}
		$deep = DeepScan::results();
		if ( null !== $deep ) {
			$deep['findings'] = array_values( array_filter( (array) $deep['findings'], static fn ( array $f ): bool => $key !== $f['key'] ) );
			update_option( DeepScan::RESULTS, $deep, false );
		}
	}

	/**
	 * Findings from the latest scan that aren't marked as expected.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function open(): array {
		$ignored = self::ignored();
		return array_values(
			array_filter(
				(array) ( self::latest()['findings'] ?? array() ),
				static fn ( array $f ): bool => ! self::is_ignored( $f, $ignored )
			)
		);
	}

	/**
	 * Findings marked as expected (still in the latest scan).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function hidden(): array {
		$ignored = self::ignored();
		return array_values(
			array_filter(
				(array) ( self::latest()['findings'] ?? array() ),
				static fn ( array $f ): bool => self::is_ignored( $f, $ignored )
			)
		);
	}

	/**
	 * Open findings by severity.
	 *
	 * @return array<string, int>
	 */
	public static function counts(): array {
		$counts = array_fill_keys( self::SEVERITIES, 0 );
		foreach ( self::open() as $finding ) {
			++$counts[ $finding['severity'] ];
		}
		return $counts;
	}

	/**
	 * Mark a finding as expected, or show it again.
	 *
	 * @param string $key    Finding key.
	 * @param bool   $ignore Hide it (true) or show it again.
	 */
	public static function ignore( string $key, bool $ignore = true ): bool {
		$finding = null;
		foreach ( (array) ( self::latest()['findings'] ?? array() ) as $f ) {
			if ( $f['key'] === $key ) {
				$finding = $f;
				break;
			}
		}
		$ignored = self::ignored();
		if ( $ignore ) {
			if ( null === $finding ) {
				return false;
			}
			$ignored[ $key ] = array(
				'file_hash' => $finding['file_hash'],
				'at'        => time(),
				'by'        => get_current_user_id(),
			);
		} else {
			unset( $ignored[ $key ] );
		}
		update_option( self::IGNORED, $ignored, false );
		return true;
	}

	/**
	 * Findings marked as expected: key => [file_hash, at, by].
	 *
	 * @return array<string, array{file_hash: string|null, at: int, by: int}>
	 */
	public static function ignored(): array {
		$value = get_option( self::IGNORED, array() );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Whether a finding is marked as expected (for a file, only while the file is unchanged).
	 *
	 * @param array<string, mixed>                                           $finding Finding.
	 * @param array<string, array{file_hash: string|null, at: int, by: int}> $ignored Ignored list.
	 */
	private static function is_ignored( array $finding, array $ignored ): bool {
		$entry = $ignored[ (string) $finding['key'] ] ?? null;
		return null !== $entry && ( null === $finding['file_hash'] || $entry['file_hash'] === $finding['file_hash'] );
	}

	/**
	 * Remove everything (uninstall, tests).
	 */
	public static function clear(): void {
		delete_option( self::RESULTS );
		delete_option( self::IGNORED );
	}
}
