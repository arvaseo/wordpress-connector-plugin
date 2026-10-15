<?php
/**
 * The Website Health checks the connector runs (schema 1.0).
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Health;

use ArvaSEO\Connector\Install\Schema;
use ArvaSEO\Connector\Inventory\PluginInventoryService;
use ArvaSEO\Connector\Support\Ids;
use ArvaSEO\Connector\Sync\SyncState;

defined( 'ABSPATH' ) || exit;

/**
 * Each check has a stable ID and category and returns a WordPress-style result (good,
 * recommended, critical) with a minimal typed value, or null when it can't run (sent as
 * "unknown", never as passed). Results come from WordPress's public Site Health test methods
 * (WP_Site_Health::get_test_*; only their status is used, never their HTML), WordPress APIs,
 * the inventory, or a documented local check. Tests that WordPress designed to run inside a
 * logged-in browser request (REST availability, the Authorization header) are measured locally
 * instead, because they would report false problems from a background job.
 *
 * Slow checks make network requests (loopback, HTTPS, page cache, WordPress.org) and only run
 * in the background, within a time budget.
 */
final class HealthCheckRegistry {

	/** Seconds the whole collection may take. */
	public const TIME_BUDGET = 60;

	/**
	 * The checks: ID => [category, method, slow].
	 *
	 * @return array<string, array{0: string, 1: string, 2: bool}>
	 */
	public static function checks(): array {
		return array(
			'core.version_update'                   => array( 'core', 'core_version_update', false ),
			'core.background_updates'               => array( 'core', 'core_background_updates', true ),
			'core.file_mods'                        => array( 'core', 'core_file_mods', false ),
			'core.update_temp_backup'               => array( 'core', 'core_update_temp_backup', false ),
			'core.environment'                      => array( 'core', 'core_environment', false ),
			'core.debug'                            => array( 'core', 'core_debug', false ),
			'extensions.plugin_updates'             => array( 'extensions', 'plugin_updates', false ),
			'extensions.theme_updates'              => array( 'extensions', 'theme_updates', false ),
			'extensions.inactive_plugins'           => array( 'extensions', 'inactive_plugins', false ),
			'extensions.inactive_themes'            => array( 'extensions', 'inactive_themes', false ),
			'extensions.auto_updates'               => array( 'extensions', 'auto_updates', false ),
			'extensions.must_use'                   => array( 'extensions', 'must_use', false ),
			'runtime.php_version'                   => array( 'runtime', 'php_version', true ),
			'runtime.database'                      => array( 'runtime', 'database', false ),
			'runtime.php_extensions'                => array( 'runtime', 'php_extensions', false ),
			'runtime.php_timezone'                  => array( 'runtime', 'php_timezone', false ),
			'runtime.php_sessions'                  => array( 'runtime', 'php_sessions', false ),
			'runtime.utf8mb4'                       => array( 'runtime', 'utf8mb4', false ),
			'runtime.opcode_cache'                  => array( 'runtime', 'opcode_cache', false ),
			'runtime.file_uploads'                  => array( 'runtime', 'file_uploads', false ),
			'runtime.disk_space'                    => array( 'runtime', 'disk_space', false ),
			'security.https'                        => array( 'security', 'https', true ),
			'security.ssl_support'                  => array( 'security', 'ssl_support', false ),
			'security.registration'                 => array( 'security', 'registration', false ),
			'security.authorization_header'         => array( 'security', 'authorization_header', true ),
			'security.dotorg_communication'         => array( 'security', 'dotorg_communication', true ),
			'security.rest_api'                     => array( 'security', 'rest_api', true ),
			'security.loopback'                     => array( 'security', 'loopback', true ),
			'reliability.cron'                      => array( 'reliability', 'cron', false ),
			'reliability.background_update_failure' => array( 'reliability', 'background_update_failure', false ),
			'reliability.missed_schedule'           => array( 'reliability', 'missed_schedule', false ),
			'reliability.connector_sync'            => array( 'reliability', 'connector_sync', false ),
			'reliability.content_delivery'          => array( 'reliability', 'content_delivery', false ),
			'performance.page_cache'                => array( 'performance', 'page_cache', true ),
			'performance.object_cache'              => array( 'performance', 'object_cache', false ),
			'performance.autoloaded_options'        => array( 'performance', 'autoloaded_options', false ),
			'seo.search_visibility'                 => array( 'seo', 'search_visibility', false ),
			'seo.permalinks'                        => array( 'seo', 'permalinks', false ),
			'seo.core_sitemaps'                     => array( 'seo', 'core_sitemaps', false ),
			'seo.robots_txt'                        => array( 'seo', 'robots_txt', true ),
		);
	}

	/**
	 * Run every check.
	 *
	 * @param bool $include_slow Run network checks too (background only).
	 * @return array<int, array<string, mixed>>
	 */
	public static function run( bool $include_slow = true ): array {
		self::load_admin();
		$started = microtime( true );
		$rows    = array();
		foreach ( self::checks() as $id => list( $category, $method, $slow ) ) {
			$result = null;
			$source = 'local_check';
			if ( ( ! $slow || $include_slow ) && ( microtime( true ) - $started ) < self::TIME_BUDGET ) {
				try {
					$out = self::$method();
					if ( is_array( $out ) ) {
						$result = $out;
						$source = $out['source'] ?? 'local_check';
					}
				} catch ( \Throwable $e ) {
					$result = null;
				}
			}
			$status = is_array( $result ) && in_array( $result['status'] ?? '', array( 'good', 'recommended', 'critical' ), true ) ? $result['status'] : 'unknown';
			$rows[] = array(
				'check_id'        => $id,
				'category'        => $category,
				'check_status'    => $status,
				'observed_value'  => is_array( $result ) && array_key_exists( 'value', $result ) ? $result['value'] : null,
				'evidence_source' => $source,
				'observed_at'     => Ids::iso(),
			);
		}
		return $rows;
	}

	// ----- WordPress core -----------------------------------------------------------------

	/**
	 * Installed WordPress against WordPress's own update data (no forced check).
	 *
	 * @return array<string, mixed>
	 */
	private static function core_version_update(): array {
		$transient = get_site_transient( 'update_core' );
		$state     = PluginInventoryService::update_state( $transient );
		$installed = (string) get_bloginfo( 'version' );
		$offered   = null;
		if ( is_object( $transient ) && ! empty( $transient->updates ) ) {
			foreach ( (array) $transient->updates as $update ) {
				if ( isset( $update->response, $update->current ) && 'upgrade' === $update->response && version_compare( $update->current, $installed, '>' ) ) {
					$offered = (string) $update->current;
					break;
				}
			}
		}
		$type   = null;
		$status = 'unknown';
		if ( null !== $offered ) {
			$type   = implode( '.', array_slice( explode( '.', $offered ), 0, 2 ) ) === implode( '.', array_slice( explode( '.', $installed ), 0, 2 ) ) ? 'minor' : 'major';
			$status = 'minor' === $type ? 'critical' : 'recommended'; // As WordPress's own version test rates them.
		} elseif ( 'current' === $state['status'] ) {
			$type   = 'none';
			$status = 'good';
		}
		return array(
			'status' => $status,
			'source' => 'wordpress_api',
			'value'  => array(
				'installed'           => self::version( $installed ),
				'offered'             => null === $offered ? null : self::version( $offered ),
				'update_type'         => $type,
				'update_check_status' => $state['status'],
				'last_checked'        => $state['last_checked'],
			),
		);
	}

	/**
	 * WordPress's background updates test.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function core_background_updates(): ?array {
		return self::site_health( 'get_test_background_updates', array() );
	}

	/**
	 * File modification constants.
	 *
	 * @return array<string, mixed>
	 */
	private static function core_file_mods(): array {
		return array(
			'status' => 'good',
			'source' => 'wordpress_api',
			'value'  => array(
				'file_mods_disallowed' => ! wp_is_file_mod_allowed( 'arvaseo_connector_health_check' ),
				'file_edit_disallowed' => defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT,
			),
		);
	}

	/**
	 * WordPress's update backup folder test (6.3+).
	 *
	 * @return array<string, mixed>|null
	 */
	private static function core_update_temp_backup(): ?array {
		return self::site_health( 'get_test_update_temp_backup_writable', array() );
	}

	/**
	 * Environment type and whether it was set explicitly.
	 *
	 * @return array<string, mixed>
	 */
	private static function core_environment(): array {
		$explicit = defined( 'WP_ENVIRONMENT_TYPE' ) || false !== getenv( 'WP_ENVIRONMENT_TYPE' );
		return array(
			'status' => 'good',
			'source' => 'wordpress_api',
			'value'  => array(
				'environment_type' => wp_get_environment_type(),
				'explicitly_set'   => $explicit,
			),
		);
	}

	/**
	 * Debug settings (booleans only; no log paths).
	 *
	 * @return array<string, mixed>
	 */
	private static function core_debug(): array {
		$debug   = defined( 'WP_DEBUG' ) && WP_DEBUG;
		$display = defined( 'WP_DEBUG_DISPLAY' ) ? (bool) WP_DEBUG_DISPLAY : true;
		$errors  = ini_get( 'display_errors' );
		$errors  = false === $errors ? null : in_array( strtolower( (string) $errors ), array( '1', 'on', 'true', 'stdout', 'stderr' ), true );
		$queries = defined( 'SAVEQUERIES' ) && SAVEQUERIES;
		$prod    = 'production' === wp_get_environment_type();
		$status  = 'good';
		if ( $prod && ( ( $debug && $display ) || true === $errors ) ) {
			$status = 'critical';
		} elseif ( $prod && ( $debug || $queries ) ) {
			$status = 'recommended';
		}
		return array(
			'status' => $status,
			'source' => 'wordpress_api',
			'value'  => array(
				'wp_debug'       => $debug,
				'debug_display'  => $display,
				'debug_log'      => defined( 'WP_DEBUG_LOG' ) && false !== WP_DEBUG_LOG,
				'savequeries'    => $queries,
				'script_debug'   => defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG,
				'display_errors' => $errors,
			),
		);
	}

	// ----- Plugins and themes -------------------------------------------------------------

	/**
	 * Plugin updates from the inventory.
	 *
	 * @return array<string, mixed>
	 */
	private static function plugin_updates(): array {
		return self::component_updates( PluginInventoryService::plugins(), get_site_transient( 'update_plugins' ) );
	}

	/**
	 * Theme updates from the inventory.
	 *
	 * @return array<string, mixed>
	 */
	private static function theme_updates(): array {
		return self::component_updates( PluginInventoryService::themes(), get_site_transient( 'update_themes' ) );
	}

	/**
	 * Counts of components with updates.
	 *
	 * @param array<int, array<string, mixed>> $items     Inventory items.
	 * @param mixed                            $transient Update transient.
	 * @return array<string, mixed>
	 */
	private static function component_updates( array $items, $transient ): array {
		$state    = PluginInventoryService::update_state( $transient );
		$active   = 0;
		$inactive = 0;
		$unknown  = 0;
		foreach ( $items as $item ) {
			if ( ( $item['type'] ?? 'standard' ) !== 'standard' ) {
				continue;
			}
			if ( null === $item['update_available'] ) {
				++$unknown;
			} elseif ( true === $item['update_available'] ) {
				$item['active'] || ! empty( $item['network_active'] ) ? ++$active : ++$inactive;
			}
		}
		$status = 'good';
		if ( $active > 0 ) {
			$status = 'recommended';
		} elseif ( 'current' !== $state['status'] ) {
			$status = $inactive > 0 ? 'recommended' : 'unknown';
		} elseif ( $inactive > 0 ) {
			$status = 'recommended';
		}
		return array(
			'status' => $status,
			'source' => 'plugin_inventory',
			'value'  => array(
				'active_with_update'   => $active,
				'inactive_with_update' => $inactive,
				'unknown'              => $unknown,
				'update_check_status'  => $state['status'],
				'last_checked'         => $state['last_checked'],
			),
		);
	}

	/**
	 * Installed but inactive plugins.
	 *
	 * @return array<string, mixed>
	 */
	private static function inactive_plugins(): array {
		$count = 0;
		foreach ( PluginInventoryService::plugins() as $plugin ) {
			if ( 'standard' === $plugin['type'] && ! $plugin['active'] && ! $plugin['network_active'] ) {
				++$count;
			}
		}
		return array(
			'status' => $count > 0 ? 'recommended' : 'good',
			'source' => 'plugin_inventory',
			'value'  => array( 'count' => $count ),
		);
	}

	/**
	 * Inactive themes, not counting the active theme, its parent and one default theme.
	 *
	 * @return array<string, mixed>
	 */
	private static function inactive_themes(): array {
		$keep  = array( get_stylesheet(), get_template() );
		$count = 0;
		$spare = false;
		foreach ( wp_get_themes() as $stylesheet => $theme ) {
			if ( in_array( $stylesheet, $keep, true ) ) {
				continue;
			}
			if ( ! $spare && str_starts_with( (string) $stylesheet, 'twenty' ) ) {
				$spare = true; // WordPress recommends keeping one default theme as a fallback.
				continue;
			}
			++$count;
		}
		return array(
			'status' => $count > 0 ? 'recommended' : 'good',
			'source' => 'wordpress_api',
			'value'  => array( 'count' => $count ),
		);
	}

	/**
	 * Automatic update settings.
	 *
	 * @return array<string, mixed>
	 */
	private static function auto_updates(): array {
		$plugins  = PluginInventoryService::plugins();
		$themes   = PluginInventoryService::themes();
		$standard = array_filter( $plugins, static fn ( $p ) => 'standard' === $p['type'] );
		return array(
			'status' => 'good',
			'source' => 'wordpress_api',
			'value'  => array(
				'plugins_enabled' => count( array_filter( $standard, static fn ( $p ) => true === $p['auto_update'] ) ),
				'plugins_total'   => count( $standard ),
				'themes_enabled'  => count( array_filter( $themes, static fn ( $t ) => true === $t['auto_update'] ) ),
				'themes_total'    => count( $themes ),
			),
		);
	}

	/**
	 * Must-use plugins and drop-ins.
	 *
	 * @return array<string, mixed>
	 */
	private static function must_use(): array {
		return array(
			'status' => 'good',
			'source' => 'wordpress_api',
			'value'  => array(
				'must_use' => count( get_mu_plugins() ),
				'dropins'  => count( get_dropins() ),
			),
		);
	}

	// ----- Runtime and hosting ------------------------------------------------------------

	/**
	 * WordPress's PHP version test (uses WordPress.org's recommendation).
	 *
	 * @return array<string, mixed>|null
	 */
	private static function php_version(): ?array {
		return self::site_health( 'get_test_php_version', array( 'version' => self::version( PHP_VERSION ) ) );
	}

	/**
	 * WordPress's database server test, with family and version.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function database(): ?array {
		global $wpdb;
		$info    = method_exists( $wpdb, 'db_server_info' ) ? (string) $wpdb->db_server_info() : '';
		$family  = stripos( $info, 'mariadb' ) !== false ? 'mariadb' : ( defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE ? 'sqlite' : ( '' !== $info ? 'mysql' : 'unknown' ) );
		$version = null;
		// MariaDB may report "5.5.5-10.6.18-MariaDB": the real version follows the 5.5.5- prefix.
		if ( preg_match( '/(\d+\.\d+\.\d+)/', preg_replace( '/^5\.5\.5-/', '', $info ) ?? '', $m ) ) {
			$version = $m[1];
		}
		return self::site_health(
			'get_test_sql_server',
			array(
				'family'  => $family,
				'version' => $version,
			)
		);
	}

	/**
	 * WordPress's PHP extensions test, with the names of missing extensions.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function php_extensions(): ?array {
		$missing = array();
		foreach ( array( 'curl', 'dom', 'exif', 'fileinfo', 'hash', 'intl', 'json', 'mbstring', 'mysqli', 'openssl', 'pcre', 'xml', 'zip' ) as $ext ) {
			if ( ! extension_loaded( $ext ) ) {
				$missing[] = $ext;
			}
		}
		if ( ! extension_loaded( 'imagick' ) && ! extension_loaded( 'gd' ) ) {
			$missing[] = 'gd_or_imagick';
		}
		return self::site_health( 'get_test_php_extensions', array( 'missing' => $missing ) );
	}

	/**
	 * WordPress's PHP default time zone test.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function php_timezone(): ?array {
		return self::site_health( 'get_test_php_default_timezone', array( 'is_utc' => 'UTC' === date_default_timezone_get() ) );
	}

	/**
	 * WordPress's PHP sessions test.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function php_sessions(): ?array {
		return self::site_health( 'get_test_php_sessions', array() );
	}

	/**
	 * WordPress's utf8mb4 test.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function utf8mb4(): ?array {
		return self::site_health( 'get_test_utf8mb4_support', array() );
	}

	/**
	 * OPcache availability.
	 *
	 * @return array<string, mixed>
	 */
	private static function opcode_cache(): array {
		if ( ! function_exists( 'ini_get' ) ) {
			return array(
				'status' => 'unknown',
				'value'  => array( 'enabled' => null ),
			);
		}
		$enabled = extension_loaded( 'Zend OPcache' ) && (bool) ini_get( 'opcache.enable' );
		return array(
			'status' => $enabled ? 'good' : 'recommended',
			'source' => 'local_check',
			'value'  => array( 'enabled' => $enabled ),
		);
	}

	/**
	 * WordPress's file uploads test, with whether uploads are on and the size limit.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function file_uploads(): ?array {
		return self::site_health(
			'get_test_file_uploads',
			array(
				'enabled'          => (bool) ini_get( 'file_uploads' ),
				'max_upload_bytes' => function_exists( 'wp_max_upload_size' ) ? (int) wp_max_upload_size() : null,
			)
		);
	}

	/**
	 * WordPress's disk space for updates test (6.3+).
	 *
	 * @return array<string, mixed>|null
	 */
	private static function disk_space(): ?array {
		return self::site_health( 'get_test_available_updates_disk_space', array() );
	}

	// ----- Security and configuration -----------------------------------------------------

	/**
	 * WordPress's HTTPS test.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function https(): ?array {
		return self::site_health(
			'get_test_https_status',
			array(
				'home_https' => function_exists( 'wp_is_home_url_using_https' ) ? wp_is_home_url_using_https() : str_starts_with( home_url(), 'https://' ),
				'site_https' => function_exists( 'wp_is_site_url_using_https' ) ? wp_is_site_url_using_https() : str_starts_with( site_url(), 'https://' ),
			)
		);
	}

	/**
	 * WordPress's TLS support test.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function ssl_support(): ?array {
		return self::site_health( 'get_test_ssl_support', array() );
	}

	/**
	 * Open registration with a privileged default role.
	 *
	 * @return array<string, mixed>
	 */
	private static function registration(): array {
		// On Multisite, sign-ups are the network's "registration" setting, not users_can_register.
		$open       = is_multisite() ? in_array( get_site_option( 'registration' ), array( 'user', 'all' ), true ) : (bool) get_option( 'users_can_register' );
		$role       = (string) get_option( 'default_role', 'subscriber' );
		$role_obj   = get_role( $role );
		$capable    = $role_obj ? array_keys( array_filter( $role_obj->capabilities ) ) : array();
		$privileged = array() !== array_diff( $capable, array( 'read', 'level_0' ) );
		return array(
			'status' => $open && $privileged ? 'critical' : 'good',
			'source' => 'wordpress_api',
			'value'  => array(
				'open'                    => $open,
				'default_role'            => mb_substr( sanitize_key( $role ), 0, 40 ),
				'default_role_privileged' => $privileged,
			),
		);
	}

	/**
	 * Whether an Authorization header reaches PHP: a loopback request with a one-time probe
	 * token to the plugin's own probe route (WordPress's test only works from the browser).
	 *
	 * @return array<string, mixed>|null
	 */
	private static function authorization_header(): ?array {
		$probe = Ids::token( 24 );
		set_transient( 'arvaseo_connector_auth_probe', hash( 'sha256', $probe ), 60 );
		$response = wp_remote_get(
			rest_url( 'arvaseo/v1/auth-header-probe' ),
			array(
				'timeout'     => 10,
				'redirection' => 0,
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.
				'headers'     => array(
					'Authorization'   => 'Basic ' . base64_encode( 'arvaseo:probe' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- fixed test credentials.
					'X-ArvaSEO-Probe' => $probe,
				),
			)
		);
		delete_transient( 'arvaseo_connector_auth_probe' );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || ! isset( $body['authorization_header'] ) ) {
			return null;
		}
		return array(
			'status' => $body['authorization_header'] ? 'good' : 'recommended',
			'source' => 'local_check',
			'value'  => array(),
		);
	}

	/**
	 * WordPress's WordPress.org communication test.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function dotorg_communication(): ?array {
		return self::site_health( 'get_test_dotorg_communication', array() );
	}

	/**
	 * The public REST API index answers JSON (WordPress's own test needs a logged-in browser).
	 *
	 * @return array<string, mixed>
	 */
	private static function rest_api(): array {
		$response = wp_remote_get(
			rest_url(),
			array(
				'timeout'             => 10,
				'sslverify'           => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.
				'limit_response_size' => 262144,
			)
		);
		if ( is_wp_error( $response ) ) {
			$status = 'critical';
		} else {
			$body   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			$status = 200 === (int) wp_remote_retrieve_response_code( $response ) && is_array( $body ) && isset( $body['namespaces'] ) ? 'good' : 'recommended';
		}
		return array(
			'status' => $status,
			'source' => 'local_check',
			'value'  => array(),
		);
	}

	/**
	 * WordPress's loopback test.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function loopback(): ?array {
		return self::site_health( 'get_test_loopback_requests', array() );
	}

	// ----- Reliability --------------------------------------------------------------------

	/**
	 * Overdue scheduled events (no hook names or arguments are sent).
	 *
	 * @return array<string, mixed>|null
	 */
	private static function cron(): ?array {
		if ( ! function_exists( '_get_cron_array' ) ) {
			return null;
		}
		$crons = _get_cron_array();
		if ( ! is_array( $crons ) ) {
			return null;
		}
		$now     = time();
		$overdue = 0;
		$late    = 0;
		foreach ( $crons as $timestamp => $hooks ) {
			if ( (int) $timestamp < $now - 60 ) {
				$overdue += count( (array) $hooks );
				$late     = max( $late, (int) floor( ( $now - (int) $timestamp ) / 60 ) );
			}
		}
		$status = 'good';
		if ( $late > 12 * 60 ) {
			$status = 'critical';
		} elseif ( $late > 15 ) {
			$status = 'recommended';
		}
		return array(
			'status' => $status,
			'source' => 'wordpress_api',
			'value'  => array(
				'disabled'         => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
				'alternate'        => defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON,
				'overdue_events'   => $overdue,
				'late_minutes_max' => $late,
			),
		);
	}

	/**
	 * WordPress's record of a failed automatic core update.
	 *
	 * @return array<string, mixed>
	 */
	private static function background_update_failure(): array {
		$failed = get_site_option( 'auto_core_update_failed' );
		return array(
			'status' => empty( $failed ) ? 'good' : ( ! empty( $failed['critical'] ) ? 'critical' : 'recommended' ),
			'source' => 'wordpress_api',
			'value'  => array(
				'failed'   => ! empty( $failed ),
				'critical' => empty( $failed ) ? null : ! empty( $failed['critical'] ),
			),
		);
	}

	/**
	 * Scheduled posts whose time has passed (a count only).
	 *
	 * @return array<string, mixed>
	 */
	private static function missed_schedule(): array {
		global $wpdb;
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'future' AND post_date_gmt < %s", gmdate( 'Y-m-d H:i:s', time() - 5 * MINUTE_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- a count, run once per health check.
		return array(
			'status' => $count > 0 ? 'recommended' : 'good',
			'source' => 'wordpress_api',
			'value'  => array( 'missed_posts' => $count ),
		);
	}

	/**
	 * The connector's own sync failures.
	 *
	 * @return array<string, mixed>
	 */
	private static function connector_sync(): array {
		$failures = 0;
		$last     = 0;
		foreach ( array( 'inventory', 'health', 'poll' ) as $kind ) {
			$state    = SyncState::get( $kind );
			$failures = max( $failures, (int) ( $state['consecutive_failures'] ?? 0 ) );
			$last     = max( $last, (int) ( $state['last_success_at'] ?? 0 ) );
		}
		return array(
			'status' => $failures >= 3 ? 'recommended' : 'good',
			'source' => 'local_check',
			'value'  => array(
				'consecutive_failures' => $failures,
				'last_success_hours'   => $last > 0 ? (int) floor( ( time() - $last ) / HOUR_IN_SECONDS ) : null,
			),
		);
	}

	/**
	 * Content deliveries that failed in the last week.
	 *
	 * @return array<string, mixed>
	 */
	private static function content_delivery(): array {
		global $wpdb;
		$table = Schema::table( 'deliveries' );
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE outcome IN ('failed','conflict') AND received_at > %s", $table, Ids::mysql( time() - WEEK_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
		return array(
			'status' => $count > 0 ? 'recommended' : 'good',
			'source' => 'local_check',
			'value'  => array( 'failed_last_7_days' => $count ),
		);
	}

	// ----- Performance indicators ---------------------------------------------------------

	/**
	 * WordPress's page cache test (6.1+).
	 *
	 * @return array<string, mixed>|null
	 */
	private static function page_cache(): ?array {
		return self::site_health( 'get_test_page_cache', array() );
	}

	/**
	 * WordPress's persistent object cache test (6.1+).
	 *
	 * @return array<string, mixed>|null
	 */
	private static function object_cache(): ?array {
		return self::site_health( 'get_test_persistent_object_cache', array( 'persistent' => (bool) wp_using_ext_object_cache() ) );
	}

	/**
	 * WordPress's autoloaded options test (6.6+), with the measured size.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function autoloaded_options(): ?array {
		$health = self::health();
		$bytes  = null !== $health && version_compare( (string) get_bloginfo( 'version' ), '6.6', '>=' ) ? (int) $health->get_autoloaded_options_size() : null;
		return self::site_health( 'get_test_autoloaded_options', array( 'bytes' => $bytes ) );
	}

	// ----- SEO and indexability -----------------------------------------------------------

	/**
	 * "Discourage search engines" setting.
	 *
	 * @return array<string, mixed>
	 */
	private static function search_visibility(): array {
		$discouraged = ! (bool) get_option( 'blog_public' );
		return array(
			'status' => $discouraged ? 'recommended' : 'good',
			'source' => 'wordpress_api',
			'value'  => array( 'discouraged' => $discouraged ),
		);
	}

	/**
	 * Plain permalinks.
	 *
	 * @return array<string, mixed>
	 */
	private static function permalinks(): array {
		$plain = '' === (string) get_option( 'permalink_structure' );
		return array(
			'status' => $plain ? 'recommended' : 'good',
			'source' => 'wordpress_api',
			'value'  => array( 'plain' => $plain ),
		);
	}

	/**
	 * WordPress's built-in sitemaps (another plugin may provide sitemaps instead: unknown then).
	 *
	 * @return array<string, mixed>
	 */
	private static function core_sitemaps(): array {
		$enabled = function_exists( 'wp_sitemaps_get_server' ) && wp_sitemaps_get_server()->sitemaps_enabled();
		return array(
			'status' => $enabled ? 'good' : 'unknown',
			'source' => 'wordpress_api',
			'value'  => array( 'enabled' => $enabled ),
		);
	}

	/**
	 * The robots.txt file answers at the site root (only checked when WordPress is at the root).
	 *
	 * @return array<string, mixed>|null
	 */
	private static function robots_txt(): ?array {
		$path = (string) wp_parse_url( home_url(), PHP_URL_PATH );
		if ( '' !== trim( $path, '/' ) ) {
			return null;
		}
		$response = wp_remote_get(
			home_url( '/robots.txt' ),
			array(
				'timeout'             => 8,
				'redirection'         => 2,
				'limit_response_size' => 65536,
				'sslverify'           => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.
			)
		);
		if ( is_wp_error( $response ) ) {
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		return array(
			'status' => 200 === $code ? 'good' : 'recommended',
			'source' => 'local_check',
			'value'  => array(
				'http_status'   => $code,
				'physical_file' => file_exists( ABSPATH . 'robots.txt' ),
			),
		);
	}

	// ----- Helpers ------------------------------------------------------------------------

	/**
	 * The status of one public WP_Site_Health test, with our own minimal value.
	 *
	 * @param string               $method Test method.
	 * @param array<string, mixed> $value  Observed value to send.
	 * @return array<string, mixed>|null
	 */
	private static function site_health( string $method, array $value ): ?array {
		$health = self::health();
		if ( null === $health || ! method_exists( $health, $method ) ) {
			return null;
		}
		$result = $health->$method();
		if ( ! is_array( $result ) || ! in_array( $result['status'] ?? '', array( 'good', 'recommended', 'critical' ), true ) ) {
			return null;
		}
		return array(
			'status' => $result['status'],
			'source' => 'site_health',
			'value'  => $value,
		);
	}

	/**
	 * WordPress's Site Health class.
	 */
	private static function health(): ?\WP_Site_Health {
		if ( ! class_exists( 'WP_Site_Health' ) ) {
			return null;
		}
		return \WP_Site_Health::get_instance();
	}

	/**
	 * A version as sent ("8.3.14", no build suffixes beyond the schema).
	 *
	 * @param string $version Version.
	 */
	private static function version( string $version ): string {
		return preg_match( '/^\d+(\.\d+){0,3}/', $version, $m ) ? $m[0] : '0';
	}

	/**
	 * Admin includes needed outside wp-admin.
	 */
	private static function load_admin(): void {
		foreach ( array( 'plugin.php', 'update.php', 'misc.php', 'file.php', 'class-wp-automatic-updater.php', 'class-wp-site-health.php', 'class-wp-site-health-auto-updates.php' ) as $file ) {
			if ( file_exists( ABSPATH . 'wp-admin/includes/' . $file ) ) {
				require_once ABSPATH . 'wp-admin/includes/' . $file;
			}
		}
	}
}
