<?php
/**
 * Speed screen navigation. Enhanced locally; all sections remain available without JavaScript.
 *
 * @package ArvaSEO\Connector
 */

defined( 'ABSPATH' ) || exit;
$arvaseo_speed_tabs = array(
	'css'        => __( 'CSS and page styling', 'arvaseo-connector' ),
	'javascript' => __( 'JavaScript and interactions', 'arvaseo-connector' ),
	'images'     => __( 'Images and media', 'arvaseo-connector' ),
	'fonts'      => __( 'Fonts', 'arvaseo-connector' ),
	'caching'    => __( 'Caching and delivery', 'arvaseo-connector' ),
	'testing'    => __( 'Testing and diagnostics', 'arvaseo-connector' ),
	'advanced'   => __( 'Exclusions and advanced', 'arvaseo-connector' ),
);

?>
<nav class="arvaseo-speed-nav" data-arvaseo-speed-nav data-empty-message="<?php esc_attr_e( 'No fixes in this section match the filter. Choose All to see them.', 'arvaseo-connector' ); ?>" aria-label="<?php esc_attr_e( 'Speed settings', 'arvaseo-connector' ); ?>" hidden>
	<div class="arvaseo-speed-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Speed sections', 'arvaseo-connector' ); ?>">
		<?php foreach ( $arvaseo_speed_tabs as $arvaseo_tab => $arvaseo_tab_label ) : ?>
			<button type="button" role="tab" id="arvaseo-speed-tab-<?php echo esc_attr( $arvaseo_tab ); ?>" aria-controls="arvaseo-speed-<?php echo esc_attr( $arvaseo_tab ); ?>" aria-selected="false" tabindex="-1" data-arvaseo-speed-tab="<?php echo esc_attr( $arvaseo_tab ); ?>"><?php echo esc_html( $arvaseo_tab_label ); ?></button>
		<?php endforeach; ?>
	</div>
	<div class="arvaseo-speed-select">
		<label class="arvaseo-label" for="arvaseo-speed-section"><?php esc_html_e( 'Speed section', 'arvaseo-connector' ); ?></label>
		<select id="arvaseo-speed-section" data-arvaseo-speed-select>
			<?php foreach ( $arvaseo_speed_tabs as $arvaseo_tab => $arvaseo_tab_label ) : ?>
				<option value="<?php echo esc_attr( $arvaseo_tab ); ?>"><?php echo esc_html( $arvaseo_tab_label ); ?></option>
			<?php endforeach; ?>
		</select>
	</div>
</nav>
<div data-arvaseo-speed-panels hidden>
	<?php foreach ( $arvaseo_speed_tabs as $arvaseo_tab => $arvaseo_tab_label ) : ?>
		<section id="arvaseo-speed-<?php echo esc_attr( $arvaseo_tab ); ?>" class="arvaseo-speed-panel" role="tabpanel" tabindex="0" aria-labelledby="arvaseo-speed-tab-<?php echo esc_attr( $arvaseo_tab ); ?>" data-arvaseo-speed-panel="<?php echo esc_attr( $arvaseo_tab ); ?>" hidden></section>
	<?php endforeach; ?>
</div>
