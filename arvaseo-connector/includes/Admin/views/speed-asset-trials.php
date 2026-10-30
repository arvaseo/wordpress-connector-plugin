<?php
/**
 * Reviewed trials and their persistent warnings. Nothing is enabled automatically.
 *
 * @package ArvaSEO\Connector
 */

use ArvaSEO\Connector\Speed\SafeAssetTrials;
use ArvaSEO\Connector\Speed\AssetDiagnostics;

defined( 'ABSPATH' ) || exit;
if ( ! current_user_can( \ArvaSEO\Connector\Install\Capabilities::MANAGE ) ) {
	return;
}
$arvaseo_trial_settings     = SafeAssetTrials::settings();
$arvaseo_trial_report       = AssetDiagnostics::latest();
$arvaseo_trial_notice       = get_transient( 'arvaseo_connector_asset_trial_notice_' . get_current_user_id() );
$arvaseo_trial_descriptions = array(
	'amd'            => array( __( 'Minify reviewed jQuery AMD files', 'arvaseo-connector' ), __( 'Requires Minify JavaScript to apply. Only the supported jQuery UMD wrapper is eligible. Each file stays separate; its wrapper and loading order stay intact. Module loaders and themes can still depend on its address. Test navigation, galleries and testimonials before enabling this for visitors. Changed source files lose approval automatically.', 'arvaseo-connector' ) ),
	'body_css'       => array( __( 'Inline reviewed small body stylesheets', 'arvaseo-connector' ), __( 'Keeps every CSS rule at the same position, up to 32 KB per file and 64 KB per page. Relative resource addresses are preserved. This can remove a request but grows the HTML and reduces shared caching. Scripts that look for a link element may stop working. CSP pages, integrity-protected files, imports and unsupported CSS stay unchanged. Test styling, icons, menus and forms at phone and desktop widths.', 'arvaseo-connector' ) ),
	'jquery_migrate' => array( __( 'Try without jQuery Migrate', 'arvaseo-connector' ), __( 'Older plugins may require deprecated jQuery APIs even when they do not declare a dependency. This removes Migrate only from the jQuery alias; an explicit queued dependency keeps it. jQuery itself keeps its current loading order. Check the browser console and every important interaction. No reported error is not proof that every page works. Turn this off to restore the original dependency. If another setting already removes Migrate, this trial has nothing to verify.', 'arvaseo-connector' ) ),
);
?>
<section class="arvaseo-card" id="arvaseo-asset-trials" aria-labelledby="arvaseo-asset-trials-title">
	<h2 id="arvaseo-asset-trials-title"><?php esc_html_e( 'Reviewed asset trials', 'arvaseo-connector' ); ?></h2>
	<div class="notice notice-warning inline"><p><?php esc_html_e( 'These options can change page behavior. They start off. First inspect the homepage above, select eligible files and start a signed-in trial. Visitors keep the current behavior until you preview a successful transformation and confirm your checks. Test more than the homepage. A higher PageSpeed score is not guaranteed.', 'arvaseo-connector' ); ?></p></div>
	<?php if ( is_string( $arvaseo_trial_notice ) ) : ?>
		<div class="notice notice-info inline" role="status"><p><?php echo esc_html( 'saved' === $arvaseo_trial_notice ? __( 'Trial setting saved. Clear any external cache before checking visitor output.', 'arvaseo-connector' ) : __( 'No change made. Select eligible files, acknowledge the warning, and inspect a matching trial before enabling it for visitors. Every selected file must transform in the same inspection before the trial can be enabled.', 'arvaseo-connector' ) ); ?></p></div>
	<?php endif; ?>
	<?php foreach ( $arvaseo_trial_descriptions as $arvaseo_trial_feature => $arvaseo_trial_copy ) : ?>
		<div class="arvaseo-fix-sub">
			<h3><?php echo esc_html( $arvaseo_trial_copy[0] ); ?></h3>
			<p><strong><?php echo esc_html( \ArvaSEO\Connector\Speed\Fixes::state_label( $arvaseo_trial_settings[ $arvaseo_trial_feature ]['state'] ) ); ?></strong></p>
			<p class="arvaseo-risk arvaseo-risk--check" role="note"><?php echo esc_html( $arvaseo_trial_copy[1] ); ?></p>
			<?php if ( $arvaseo_trial_settings[ $arvaseo_trial_feature ]['assets'] ) : ?>
				<p><?php esc_html_e( 'Reviewed source files (changed files revert to originals):', 'arvaseo-connector' ); ?></p>
				<ul style="overflow-wrap:anywhere">
					<?php foreach ( $arvaseo_trial_settings[ $arvaseo_trial_feature ]['assets'] as $arvaseo_trial_asset ) : ?>
						<li><?php echo esc_html( $arvaseo_trial_asset['url'] ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="arvaseo_connector_asset_trial" />
				<input type="hidden" name="feature" value="<?php echo esc_attr( $arvaseo_trial_feature ); ?>" />
				<?php wp_nonce_field( 'arvaseo_connector_asset_trial' ); ?>
				<?php if ( 'jquery_migrate' !== $arvaseo_trial_feature ) : ?>
					<p><?php esc_html_e( 'Eligible files from your latest inspection:', 'arvaseo-connector' ); ?></p>
					<?php $arvaseo_trial_candidates = 0; ?>
					<?php foreach ( $arvaseo_trial_report['rows'] ?? array() as $arvaseo_trial_index => $arvaseo_trial_row ) : ?>
						<?php
						if ( ( $arvaseo_trial_row['candidate']['feature'] ?? '' ) !== $arvaseo_trial_feature ) {
							continue; }
						?>
						<?php ++$arvaseo_trial_candidates; ?>
						<label style="display:block;overflow-wrap:anywhere"><input type="checkbox" name="assets[]" value="<?php echo esc_attr( (string) $arvaseo_trial_index ); ?>" /> <?php echo esc_html( $arvaseo_trial_row['id'] . ' — ' . $arvaseo_trial_row['url'] ); ?></label>
					<?php endforeach; ?>
					<?php
					if ( 0 === $arvaseo_trial_candidates ) :
						?>
						<p><?php esc_html_e( 'No eligible files in this report. Turn this trial off and inspect the page again to select original files; unsupported files remain unchanged.', 'arvaseo-connector' ); ?></p><?php endif; ?>
				<?php endif; ?>
				<p><label><input type="checkbox" name="checked" value="1" /> <?php esc_html_e( 'I understand the warning. Before enabling this for visitors, I checked first-tap and hover navigation, gallery/lightbox, testimonials, forms, chat, consent and the browser console on relevant mobile and desktop pages.', 'arvaseo-connector' ); ?></label></p>
				<button class="arvaseo-btn arvaseo-btn--sm" name="trial_action" value="try"><?php esc_html_e( 'Start or restart trial', 'arvaseo-connector' ); ?></button>
				<a class="arvaseo-btn arvaseo-btn--sm" href="<?php echo esc_url( AssetDiagnostics::preview_url() ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Inspect trial homepage', 'arvaseo-connector' ); ?></a>
				<?php if ( 'trying' === $arvaseo_trial_settings[ $arvaseo_trial_feature ]['state'] ) : ?>
					<button class="arvaseo-btn arvaseo-btn--sm" name="trial_action" value="enable"><?php esc_html_e( 'Enable checked trial for visitors', 'arvaseo-connector' ); ?></button>
				<?php endif; ?>
				<button class="arvaseo-btn arvaseo-btn--sm" name="trial_action" value="off"><?php esc_html_e( 'Turn off and restore originals', 'arvaseo-connector' ); ?></button>
			</form>
		</div>
	<?php endforeach; ?>
</section>
