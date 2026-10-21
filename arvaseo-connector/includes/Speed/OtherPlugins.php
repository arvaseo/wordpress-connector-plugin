<?php
/**
 * Other speed plugins doing some of the PageSpeed fixes' jobs: which are active, which fixes they
 * do, and what else they do that Arva SEO doesn't, so the Speed page can offer to deactivate them.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Speed;

use ArvaSEO\Connector\Logging\AuditLogger;

defined( 'ABSPATH' ) || exit;

/**
 * A plugin is only ever deactivated when an administrator clicks for it, never by itself
 * (WordPress.org's guidelines), and only one of the plugins listed here.
 */
final class OtherPlugins {

	/** The plugins an administrator chose to keep (Speed shows one line while they're the same). */
	public const KEPT = 'arvaseo_connector_other_plugins_kept';

	/** Set on activation: the next dashboard page names any other speed plugins, once. */
	public const AFTER_ACTIVATION = 'arvaseo_connector_other_plugins_notice';

	/** Hosts' own plugins: they run the host's server cache and clear it when pages change. */
	private const HOST_CACHE = array( 'sg-cachepress', 'litespeed-cache' );

	/** Plugins that also cache pages, which Arva SEO doesn't do. */
	private const PAGE_CACHE = array( 'wp-rocket', 'litespeed-cache', 'w3-total-cache', 'wp-fastest-cache', 'sg-cachepress', 'breeze', 'hummingbird-performance', 'wp-optimize', 'swift-performance-lite', 'nitropack', 'flying-press', 'jetpack-boost' );

	/** Image compression plugins: what they compressed stays compressed after they're deactivated. */
	private const IMAGES = array( 'wp-smushit', 'ewww-image-optimizer', 'imagify', 'shortpixel-image-optimiser', 'optimole-wp' );

	/**
	 * The active plugins that do some of the fixes, in the order they're first met.
	 *
	 * @return array<int, array{slug: string, file: string, name: string, fixes: string[], also: string|null, network: bool}>
	 */
	public static function active(): array {
		$active = Fixes::active_plugin_slugs();
		$fixes  = array();
		foreach ( Fixes::all() as $fix ) {
			foreach ( $fix['handled_by'] as $slug ) {
				if ( in_array( $slug, $active, true ) ) {
					$fixes[ $slug ][] = $fix['title'];
				}
			}
		}
		if ( array() === $fixes ) {
			return array();
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$installed = get_plugins();
		$network   = is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) : array();
		$files     = array_merge( (array) get_option( 'active_plugins', array() ), $network );
		$found     = array();
		foreach ( $fixes as $slug => $titles ) {
			foreach ( $files as $file ) {
				if ( dirname( (string) $file ) !== $slug ) {
					continue;
				}
				$found[] = array(
					'slug'    => $slug,
					'file'    => (string) $file,
					'name'    => (string) ( $installed[ $file ]['Name'] ?? Fixes::plugin_names()[ $slug ] ?? $slug ),
					'fixes'   => array_values( array_unique( $titles ) ),
					'also'    => in_array( $slug, self::HOST_CACHE, true ) ? 'host_cache' : ( in_array( $slug, self::PAGE_CACHE, true ) ? 'page_cache' : ( in_array( $slug, self::IMAGES, true ) ? 'images' : null ) ),
					'network' => in_array( $file, $network, true ),
				);
				break;
			}
		}
		return $found;
	}

	/**
	 * What a plugin does that Arva SEO doesn't, said before anyone deactivates it ('' when nothing).
	 *
	 * @param string|null $also host_cache, page_cache, images or null.
	 */
	public static function caveat( ?string $also ): string {
		return match ( $also ) {
			'host_cache' => __( 'It also runs your web host’s own page cache and clears it when your pages change, which Arva SEO doesn’t do. Keeping it is usually best: without it, visitors can get out-of-date pages, or slower ones.', 'arvaseo-connector' ),
			'page_cache' => __( 'It also caches your pages, which Arva SEO doesn’t do. Before you deactivate it, check that your host caches pages (many do), or your pages may load slower.', 'arvaseo-connector' ),
			'images'     => __( 'Images it already compressed stay that way after you deactivate it. Turn on Compress images here for the ones you upload next, and for the rest of your media library.', 'arvaseo-connector' ),
			default      => '',
		};
	}

	/**
	 * Whether the administrator chose to keep the plugins that are active now.
	 */
	public static function kept(): bool {
		$slugs = array_column( self::active(), 'slug' );
		return array() !== $slugs && array() === array_diff( $slugs, (array) get_option( self::KEPT, array() ) );
	}

	/**
	 * Keep the plugins that are active now (true), or show the choice again (false).
	 *
	 * @param bool $keep Keep them.
	 */
	public static function keep( bool $keep ): void {
		if ( $keep ) {
			update_option( self::KEPT, array_column( self::active(), 'slug' ), false );
		} else {
			delete_option( self::KEPT );
		}
	}

	/**
	 * Deactivate one of the plugins listed by active(), for an administrator who asked.
	 *
	 * @param string $slug The plugin's folder.
	 * @return array{ok: bool, message: string}
	 */
	public static function deactivate( string $slug ): array {
		$plugin = null;
		foreach ( self::active() as $candidate ) {
			if ( $slug === $candidate['slug'] ) {
				$plugin = $candidate;
			}
		}
		if ( null === $plugin ) {
			return array(
				'ok'      => false,
				'message' => __( 'That plugin isn’t one of the speed plugins Arva SEO found, or it’s already off. Nothing was changed.', 'arvaseo-connector' ),
			);
		}
		if ( $plugin['network'] ) {
			return array(
				'ok'      => false,
				/* translators: %s: plugin name. */
				'message' => sprintf( __( '%s is on for the whole network, so only a network administrator can deactivate it (Network Admin → Plugins).', 'arvaseo-connector' ), $plugin['name'] ),
			);
		}
		if ( ! current_user_can( 'deactivate_plugin', $plugin['file'] ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'Your account can’t deactivate plugins on this site. Nothing was changed.', 'arvaseo-connector' ),
			);
		}
		deactivate_plugins( $plugin['file'] ); // Runs the plugin's own deactivation, which removes its rules.
		if ( is_plugin_active( $plugin['file'] ) ) {
			return array(
				'ok'      => false,
				/* translators: %s: plugin name. */
				'message' => sprintf( __( '%s couldn’t be deactivated. Try from the Plugins page.', 'arvaseo-connector' ), $plugin['name'] ),
			);
		}
		AuditLogger::info(
			'speed.other_plugin_deactivated',
			/* translators: %s: plugin name. */
			sprintf( __( 'PageSpeed fixes: %s was deactivated from the Speed page.', 'arvaseo-connector' ), $plugin['name'] ),
			array( 'plugin' => $plugin['slug'] )
		);
		return array(
			'ok'      => true,
			/* translators: %s: plugin name. */
			'message' => sprintf( __( '%s was deactivated. The fixes it did are off now: turn on Arva SEO’s below, one at a time. If you use a page cache, clear it.', 'arvaseo-connector' ), $plugin['name'] ),
		);
	}

	/**
	 * "A", "A and B", "A, B and C".
	 *
	 * @param string[] $names Names.
	 */
	public static function names( array $names ): string {
		$last = array_pop( $names );
		if ( array() === $names ) {
			return (string) $last;
		}
		/* translators: 1: names separated by commas, 2: the last name. */
		return sprintf( __( '%1$s and %2$s', 'arvaseo-connector' ), implode( ', ', $names ), (string) $last );
	}
}
