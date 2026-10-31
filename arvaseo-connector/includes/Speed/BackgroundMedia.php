<?php
/**
 * Detect the builder's own background loader without changing its settings or adding a second one.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Speed;

defined( 'ABSPATH' ) || exit;

/** Image-tag loading and CSS background loading are separate capabilities. */
final class BackgroundMedia {

	/** Native Elementor state; an absent or unfamiliar value stays unknown. */
	public static function status(): string {
		if ( ! did_action( 'elementor/loaded' ) || ! class_exists( '\Elementor\Modules\LazyLoad\Module' ) ) {
			return 'unavailable';
		}
		$value = get_option( 'elementor_lazy_load_background_images', null );
		return '1' === $value ? 'enabled' : ( '0' === $value ? 'disabled' : 'unknown' );
	}
}
