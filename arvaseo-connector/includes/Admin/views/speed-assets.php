<?php
/**
 * Private asset preview report.
 *
 * @package ArvaSEO\Connector
 */

use ArvaSEO\Connector\Speed\AssetDiagnostics;
use ArvaSEO\Connector\Speed\Fixes;

defined( 'ABSPATH' ) || exit;
if ( ! current_user_can( \ArvaSEO\Connector\Install\Capabilities::MANAGE ) ) {
	return;
}
$arvaseo_asset_report = AssetDiagnostics::latest();
?>
<details class="arvaseo-card" id="arvaseo-asset-report">
	<summary><?php esc_html_e( 'Inspect CSS and JavaScript', 'arvaseo-connector' ); ?></summary>
	<p><?php esc_html_e( 'Open your homepage, then return and reload this screen. The report lasts 15 minutes and is visible only to you. It describes your signed-in preview, including Trying settings; visitors and cached pages may differ. No inline code or URL queries are saved.', 'arvaseo-connector' ); ?></p>
	<p><a class="arvaseo-btn arvaseo-btn--sm" target="_blank" rel="noopener noreferrer" href="<?php echo esc_url( AssetDiagnostics::preview_url() ); ?>"><?php esc_html_e( 'Inspect homepage', 'arvaseo-connector' ); ?></a></p>
	<p><?php esc_html_e( 'Separate files are often intentional: inline code and execution order split bundles. Combined critical CSS waits for matching generated results; Trying changes to CSS preparation cannot be reproduced by the anonymous renderer. Check mobile navigation on the first tap, hover menus, gallery, testimonials, forms and consent before enabling a change for visitors.', 'arvaseo-connector' ); ?></p>
	<?php if ( $arvaseo_asset_report ) : ?>
		<p><?php echo esc_html( wp_date( 'Y-m-d H:i:s', (int) $arvaseo_asset_report['at'] ) ); ?> — <?php echo esc_html( $arvaseo_asset_report['complete'] ? __( 'Complete HTML inventory', 'arvaseo-connector' ) : __( 'Partial or unsupported response; do not use this as a complete inventory.', 'arvaseo-connector' ) ); ?></p>
		<ul>
		<?php foreach ( $arvaseo_asset_report['states'] as $arvaseo_asset_fix => $arvaseo_asset_state ) : ?>
			<li><?php echo esc_html( ( Fixes::all()[ $arvaseo_asset_fix ]['title'] ?? $arvaseo_asset_fix ) . ': ' . Fixes::state_label( $arvaseo_asset_state ) ); ?></li>
		<?php endforeach; ?>
		</ul>
		<table class="widefat striped">
			<thead><tr><th><?php esc_html_e( 'File or inline block', 'arvaseo-connector' ); ?></th><th><?php esc_html_e( 'Location', 'arvaseo-connector' ); ?></th><th><?php esc_html_e( 'Observed delivery', 'arvaseo-connector' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $arvaseo_asset_report['rows'] as $arvaseo_asset_row ) : ?>
				<tr><td style="overflow-wrap:anywhere"><strong><?php echo esc_html( $arvaseo_asset_row['id'] ?: $arvaseo_asset_row['kind'] ); ?></strong><br /><?php echo esc_html( $arvaseo_asset_row['url'] ); ?>
					<?php if ( ! empty( $arvaseo_asset_row['members'] ) ) : ?>
						<details><summary><?php esc_html_e( 'Files in this bundle', 'arvaseo-connector' ); ?></summary><ul>
						<?php foreach ( $arvaseo_asset_row['members'] as $arvaseo_asset_member ) : ?>
							<li><?php echo esc_html( $arvaseo_asset_member ); ?></li>
						<?php endforeach; ?>
						</ul></details>
					<?php endif; ?>
				</td><td><?php echo esc_html( $arvaseo_asset_row['location'] ); ?></td><td><?php echo esc_html( implode( ' ', $arvaseo_asset_row['notes'] ) ); ?></td></tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</details>
