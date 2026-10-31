<?php
/**
 * Native background loading guidance; reading this card never changes the builder's options.
 *
 * @package ArvaSEO\Connector
 */

defined( 'ABSPATH' ) || exit;
$arvaseo_background_state = \ArvaSEO\Connector\Speed\BackgroundMedia::status();
?>
<details class="arvaseo-background-media">
	<summary><?php esc_html_e( 'CSS background images', 'arvaseo-connector' ); ?></summary>
	<p><?php esc_html_e( 'This fix adds lazy loading to image and iframe tags. CSS backgrounds need support from your theme or page builder. Arva SEO does not add a second background loader or change your builder settings.', 'arvaseo-connector' ); ?></p>
	<?php if ( 'enabled' === $arvaseo_background_state ) : ?>
		<p role="status"><?php esc_html_e( 'Elementor’s native background lazy loading is enabled. Keep one loader in charge; check that backgrounds appear when scrolling and that the hero loads immediately.', 'arvaseo-connector' ); ?></p>
	<?php elseif ( 'disabled' === $arvaseo_background_state ) : ?>
		<p role="status"><?php esc_html_e( 'Elementor’s native background lazy loading is disabled. Below-screen backgrounds can compete with your main image. Consider testing Lazy Load Background Images in Elementor’s performance settings.', 'arvaseo-connector' ); ?></p>
	<?php elseif ( 'unknown' === $arvaseo_background_state ) : ?>
		<p><?php esc_html_e( 'Elementor controls the default for this site. Check its Lazy Load Background Images setting before adding another loader.', 'arvaseo-connector' ); ?></p>
	<?php else : ?>
		<p><?php esc_html_e( 'No supported native background loader was detected. Ask your theme or builder for background loading support; image-tag lazy loading does not delay CSS backgrounds.', 'arvaseo-connector' ); ?></p>
	<?php endif; ?>
	<p><?php esc_html_e( 'Never delay a hero or other first-screen background. A preload does not override a background hidden by lazy-loading CSS. Test short and tall mobile screens, navigation, galleries, testimonials and JavaScript-disabled pages. Elementor’s loader depends on JavaScript; its exclusions are separate from Arva SEO’s image exclusions.', 'arvaseo-connector' ); ?></p>
	<?php if ( 'unavailable' !== $arvaseo_background_state ) : ?>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=elementor-settings' ) ); ?>"><?php esc_html_e( 'Open Elementor settings', 'arvaseo-connector' ); ?></a>
	<?php endif; ?>
</details>
