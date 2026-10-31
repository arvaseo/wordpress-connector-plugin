<?php
/**
 * Additional delivery choices inside the existing Speed cards.
 *
 * @package ArvaSEO\Connector
 */

use ArvaSEO\Connector\Speed\InlineAssets;

defined( 'ABSPATH' ) || exit;

return static function ( string $arvaseo_id, array $arvaseo_stored ): void {
	if ( in_array( $arvaseo_id, array( 'critical_css', 'unused_css' ), true ) ) :
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="arvaseo-fix-sub">
			<h4><?php esc_html_e( 'CSS delivery options', 'arvaseo-connector' ); ?></h4>
			<input type="hidden" name="action" value="arvaseo_connector_speed_options" />
			<input type="hidden" name="fix" value="css_delivery" />
			<?php wp_nonce_field( 'arvaseo_connector_speed_options' ); ?>
			<fieldset class="arvaseo-checks" style="flex-direction: column">
				<legend class="screen-reader-text"><?php esc_html_e( 'Choose how optimized styles load', 'arvaseo-connector' ); ?></legend>
				<label><input type="checkbox" name="ucss_inline" value="1" <?php checked( $arvaseo_stored['ucss_inline'] ); ?> /> <span><strong><?php esc_html_e( 'Inline used CSS (UCSS Inline)', 'arvaseo-connector' ); ?></strong><br /><span class="arvaseo-hint"><?php esc_html_e( 'Put used CSS directly in the page. Turn this off to serve a cached CSS file instead. Requires Remove unused CSS.', 'arvaseo-connector' ); ?></span></span></label>
				<label><input type="checkbox" name="ccss_per_url" value="1" <?php checked( $arvaseo_stored['ccss_per_url'] ); ?> /> <span><strong><?php esc_html_e( 'Generate CSS per URL (CCSS Per URL)', 'arvaseo-connector' ); ?></strong><br /><span class="arvaseo-hint"><?php esc_html_e( 'Generate both critical and used CSS separately for each page address instead of sharing by page type. This may use more page optimizations; original styles load until new results are ready.', 'arvaseo-connector' ); ?></span></span></label>
				<label><input type="checkbox" name="css_async_inline" value="1" <?php checked( $arvaseo_stored['css_async_inline'] ); ?> /> <span><strong><?php esc_html_e( 'Inline the asynchronous CSS loader', 'arvaseo-connector' ); ?></strong><br /><span class="arvaseo-hint"><?php esc_html_e( 'Include the small loader in the page. Turn this off to load the same library from the plugin as a cached JavaScript file.', 'arvaseo-connector' ); ?></span></span></label>
				<label><input type="checkbox" name="late_icons" value="1" <?php checked( $arvaseo_stored['late_icons'] ); ?> /> <span><strong><?php esc_html_e( 'Load icon fonts after the page', 'arvaseo-connector' ); ?></strong><br /><span class="arvaseo-hint"><?php esc_html_e( 'Icon libraries a theme or widget prints into the page itself (Font Awesome and similar) load once the page has loaded, or at the first scroll or tap. Until then those icons don’t show, and text next to them can move a little when they appear: check your menu, buttons and social icons.', 'arvaseo-connector' ); ?></span></span></label>
			</fieldset>
			<button type="submit" class="arvaseo-btn arvaseo-btn--sm"><?php esc_html_e( 'Save CSS delivery', 'arvaseo-connector' ); ?></button>
		</form>
	<?php elseif ( in_array( $arvaseo_id, array( 'combine_inline_css', 'combine_inline_js' ), true ) ) : ?>
		<?php
		$arvaseo_kind    = 'combine_inline_css' === $arvaseo_id ? 'css' : 'js';
		$arvaseo_field   = $arvaseo_id . '_ids';
		$arvaseo_choices = array();
		foreach ( InlineAssets::candidates( $arvaseo_kind ) as $arvaseo_candidate ) {
			$arvaseo_choices[ $arvaseo_candidate['id'] . '|' . $arvaseo_candidate['hash'] ] = $arvaseo_candidate['id'] . ' (' . size_format( $arvaseo_candidate['bytes'] ) . ')';
		}
		foreach ( $arvaseo_stored[ $arvaseo_field ] as $arvaseo_approval ) {
			if ( ! isset( $arvaseo_choices[ $arvaseo_approval ] ) ) {
				$arvaseo_choices[ $arvaseo_approval ] = explode( '|', $arvaseo_approval )[0];
			}
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="arvaseo-fix-sub">
			<h4><?php esc_html_e( 'Approve public static snippets', 'arvaseo-connector' ); ?></h4>
			<?php $arvaseo_changing_ids = \ArvaSEO\Connector\Speed\InlineChanges::warnings( $arvaseo_kind ); ?>
			<?php if ( $arvaseo_changing_ids ) : ?>
				<p class="arvaseo-fix-note" role="status"><?php /* translators: %s: printed snippet identifiers. */ printf( esc_html__( 'These snippets had three different bodies on the same page within a day and stay inline there: %s. A saved approval does not override this protection; it resets as the observations expire.', 'arvaseo-connector' ), esc_html( implode( ', ', $arvaseo_changing_ids ) ) ); ?></p>
			<?php endif; ?>
			<p class="arvaseo-hint"><?php esc_html_e( 'Try this option and its parent Combine option, open your homepage, then return here. Choose only snippets you have checked contain public static code: approved content is copied into a public bundle. A content change automatically makes that approval stop applying.', 'arvaseo-connector' ); ?></p>
			<input type="hidden" name="action" value="arvaseo_connector_speed_options" />
			<input type="hidden" name="fix" value="<?php echo esc_attr( $arvaseo_id ); ?>" />
			<?php wp_nonce_field( 'arvaseo_connector_speed_options' ); ?>
			<?php if ( array() === $arvaseo_choices ) : ?>
				<p><?php esc_html_e( 'No eligible inline snippets found in your preview yet.', 'arvaseo-connector' ); ?></p>
			<?php else : ?>
				<fieldset class="arvaseo-checks" style="flex-direction: column">
					<legend class="screen-reader-text"><?php esc_html_e( 'Public static snippets to combine', 'arvaseo-connector' ); ?></legend>
					<?php foreach ( $arvaseo_choices as $arvaseo_value => $arvaseo_label ) : ?>
						<label><input type="checkbox" name="<?php echo esc_attr( $arvaseo_field ); ?>[]" value="<?php echo esc_attr( $arvaseo_value ); ?>" <?php checked( in_array( $arvaseo_value, $arvaseo_stored[ $arvaseo_field ], true ) ); ?> /> <span><?php echo esc_html( $arvaseo_label ); ?></span></label>
					<?php endforeach; ?>
				</fieldset>
			<?php endif; ?>
			<button type="submit" class="arvaseo-btn arvaseo-btn--sm"><?php esc_html_e( 'Save approved snippets', 'arvaseo-connector' ); ?></button>
		</form>
	<?php elseif ( in_array( $arvaseo_id, array( 'combine_external_css', 'combine_external_js' ), true ) ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="arvaseo-fix-sub">
			<h4><?php esc_html_e( 'Approved external asset URLs', 'arvaseo-connector' ); ?></h4>
			<p class="arvaseo-hint"><?php esc_html_e( 'One exact public HTTPS CSS or JavaScript address per line, up to 20. This shared list permits background caching for combining; it does not load files your page does not already use. Personalized or signed URLs, redirects and unsupported files keep their original delivery.', 'arvaseo-connector' ); ?></p>
			<input type="hidden" name="action" value="arvaseo_connector_speed_options" />
			<input type="hidden" name="fix" value="<?php echo esc_attr( $arvaseo_id ); ?>" />
			<?php wp_nonce_field( 'arvaseo_connector_speed_options' ); ?>
			<label><span class="screen-reader-text"><?php esc_html_e( 'Exact external CSS and JavaScript URLs', 'arvaseo-connector' ); ?></span><textarea name="external_asset_urls" rows="5" class="large-text code"><?php echo esc_textarea( implode( "\n", $arvaseo_stored['external_asset_urls'] ) ); ?></textarea></label>
			<button type="submit" class="arvaseo-btn arvaseo-btn--sm"><?php esc_html_e( 'Save approved URLs', 'arvaseo-connector' ); ?></button>
		</form>
		<?php
	endif;
};
