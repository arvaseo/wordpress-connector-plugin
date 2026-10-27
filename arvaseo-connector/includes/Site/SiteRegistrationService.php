<?php
/**
 * Describing this site to Arva SEO.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Site;

use ArvaSEO\Connector\Api\ApiClient;
use ArvaSEO\Connector\Auth\AuthService;
use ArvaSEO\Connector\Auth\TokenStore;
use ArvaSEO\Connector\Logging\AuditLogger;
use ArvaSEO\Connector\Privacy\PrivacyService;
use ArvaSEO\Connector\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Sends the registration: the site address and its event endpoint, versions, time zone,
 * environment, the local publishing ceiling (so Arva SEO can warn before sending something this
 * site will refuse), whether SEO titles and descriptions may be updated and in which SEO plugin,
 * and the consent record. Sent after connecting and whenever these change.
 */
final class SiteRegistrationService {

	/**
	 * Register (or re-register) the site.
	 */
	public static function register(): bool {
		if ( ! TokenStore::is_connected() ) {
			return false;
		}
		$response = ApiClient::instance()->site( 'PUT', '/registration', self::payload() );
		if ( ! $response->ok() ) {
			AuditLogger::warning(
				'registration.failed',
				__( 'Couldn’t update this site’s details in Arva SEO.', 'arvaseo-connector' ),
				array(
					'http_status' => $response->status,
					'code'        => $response->error_code,
				)
			);
			return false;
		}
		$data = $response->data();
		TokenStore::update_connection(
			array(
				'push_status'       => in_array( $data['push_status'] ?? '', array( 'unverified', 'verified', 'unreachable' ), true ) ? $data['push_status'] : 'unverified',
				'registered_at'     => time(),
				'registered_policy' => Settings::get( 'publishing_mode' ),
				'registered_seo'    => self::seo(),
			)
		);
		return true;
	}

	/**
	 * The registration payload (only these fields).
	 *
	 * @return array<string, mixed>
	 */
	public static function payload(): array {
		$settings = Settings::all();
		return array(
			'schema_version'     => '1.0',
			'site_url'           => AuthService::site_url(),
			'site_name'          => mb_substr( wp_strip_all_tags( get_bloginfo( 'name' ) ), 0, 200 ),
			'events_url'         => rest_url( 'arvaseo/v1/events' ),
			'plugin_version'     => ARVASEO_CONNECTOR_VERSION,
			'wp_version'         => (string) get_bloginfo( 'version' ),
			'timezone'           => self::timezone(),
			'environment_type'   => wp_get_environment_type(),
			'multisite'          => is_multisite(),
			'publishing'         => array(
				'mode'               => $settings['publishing_mode'],
				'downgrade_to_draft' => (bool) $settings['downgrade_to_draft'],
				'allowed_post_types' => array_values( $settings['allowed_post_types'] ),
			),
			'seo'                => self::seo(),
			// The Connector's own PageSpeed fixes and their state (1.3.0+).
			'speed_fixes'        => \ArvaSEO\Connector\Speed\Fixes::for_registration(),
			// 1.7.0: an administrator lets Arva SEO switch the fixes.
			'speed_fixes_remote' => \ArvaSEO\Connector\Speed\Fixes::stored()['remote'],
			// 2.11.0: what updates changed in the fixes this site uses (ids; the app has the notes).
			'speed_changes'      => \ArvaSEO\Connector\Speed\Changes::for_registration(),
			'consent'            => PrivacyService::for_registration(),
		);
	}

	/**
	 * Whether Arva SEO may update SEO titles and descriptions here, and the SEO plugin they go to:
	 * yoast, rankmath, none (neither active) or multiple (both active, none chosen).
	 *
	 * @return array{updates_allowed: bool, plugin: string}
	 */
	public static function seo(): array {
		return array(
			'updates_allowed' => (bool) Settings::get( 'seo_updates' ),
			'plugin'          => \ArvaSEO\Connector\Content\SeoPlugins::resolve(),
		);
	}

	/**
	 * The site's time zone: an IANA name, or UTC±HH:MM for a manual offset.
	 */
	public static function timezone(): string {
		$zone = wp_timezone_string();
		if ( preg_match( '/^[+-]\d{2}:\d{2}$/', $zone ) ) {
			return 'UTC' . $zone;
		}
		return $zone;
	}
}
