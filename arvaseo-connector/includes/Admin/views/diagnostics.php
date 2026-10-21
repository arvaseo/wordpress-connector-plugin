<?php
/**
 * Diagnostics and data sharing (a page of Settings).
 *
 * @package ArvaSEO\Connector
 */

use ArvaSEO\Connector\Admin\AdminController;
use ArvaSEO\Connector\Diagnostics\DiagnosticsService;
use ArvaSEO\Connector\Privacy\PrivacyService;

defined( 'ABSPATH' ) || exit;

// Scoped so the view's variables stay local.
( static function () {

	$arvaseo_consent = PrivacyService::current();
	?>
<div class="arvaseo-grid2">
	<section class="arvaseo-card" aria-labelledby="arvaseo-diag-heading">
		<p class="arvaseo-eyebrow"><?php esc_html_e( 'For support', 'arvaseo-connector' ); ?></p>
		<h2 class="arvaseo-h2" id="arvaseo-diag-heading"><?php esc_html_e( 'Diagnostics', 'arvaseo-connector' ); ?></h2>
		<p class="arvaseo-hint"><?php esc_html_e( 'Share this with Arva SEO support if something isn’t working. It contains versions, connection state, sync results and settings only: no tokens, secrets, file paths, usernames, emails or content.', 'arvaseo-connector' ); ?></p>
		<label for="arvaseo-diagnostics" class="screen-reader-text"><?php esc_html_e( 'Diagnostics', 'arvaseo-connector' ); ?></label>
		<textarea id="arvaseo-diagnostics" rows="16" readonly="readonly" style="max-width: none"><?php echo esc_textarea( DiagnosticsService::json() ); ?></textarea>
		<div class="arvaseo-row" style="margin-top: 12px">
			<button type="button" class="arvaseo-btn arvaseo-btn--sm" data-arvaseo-copy="arvaseo-diagnostics"><?php esc_html_e( 'Copy', 'arvaseo-connector' ); ?></button>
			<?php AdminController::button( 'arvaseo_connector_diagnostics', __( 'Download', 'arvaseo-connector' ) ); ?>
			<span class="arvaseo-copied arvaseo-hint" role="status" aria-live="polite"></span>
		</div>
	</section>

	<section class="arvaseo-card" aria-labelledby="arvaseo-privacy-heading">
		<p class="arvaseo-eyebrow"><?php esc_html_e( 'Data sharing', 'arvaseo-connector' ); ?></p>
		<h2 class="arvaseo-h2" id="arvaseo-privacy-heading">
			<?php
			if ( null === $arvaseo_consent ) {
				esc_html_e( 'Not shared: no plugin list or health data is sent', 'arvaseo-connector' );
			} else {
				/* translators: %s: date and time. */
				echo esc_html( sprintf( __( 'Shared since %s', 'arvaseo-connector' ), AdminController::when( $arvaseo_consent['consented_at'] ) ) );
			}
			?>
		</h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top: 14px">
			<input type="hidden" name="action" value="arvaseo_connector_consent" />
			<?php wp_nonce_field( 'arvaseo_connector_consent' ); ?>
			<label class="arvaseo-toggle"><input type="checkbox" name="consent" value="1" <?php checked( null !== $arvaseo_consent ); ?> /><span class="arvaseo-switch" aria-hidden="true"></span><span><span class="arvaseo-label"><?php esc_html_e( 'Share the plugin list and Website Health checks listed below with Arva SEO', 'arvaseo-connector' ); ?></span></span></label>
			<div class="arvaseo-row" style="margin-top: 12px"><button type="submit" class="arvaseo-btn arvaseo-btn--sm arvaseo-btn--dark"><?php esc_html_e( 'Save data-sharing choice', 'arvaseo-connector' ); ?></button></div>
		</form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top: 14px">
			<input type="hidden" name="action" value="arvaseo_connector_consent_security" />
			<?php wp_nonce_field( 'arvaseo_connector_consent_security' ); ?>
			<?php $arvaseo_security = PrivacyService::security_consent(); ?>
			<label class="arvaseo-toggle"><input type="checkbox" name="consent" value="1" <?php checked( null !== $arvaseo_security ); ?> <?php disabled( null === $arvaseo_consent ); ?> /><span class="arvaseo-switch" aria-hidden="true"></span><span><span class="arvaseo-label"><?php esc_html_e( 'Also share the security scan’s results', 'arvaseo-connector' ); ?></span><span class="arvaseo-hint" style="display: block">
				<?php
				if ( null === $arvaseo_consent ) {
					esc_html_e( 'Share the plugin list and Website Health checks first.', 'arvaseo-connector' );
				} elseif ( null !== $arvaseo_security ) {
					/* translators: %s: date and time. */
					echo esc_html( sprintf( __( 'Shared since %s', 'arvaseo-connector' ), AdminController::when( $arvaseo_security['consented_at'] ) ) );
				} else {
					esc_html_e( 'What each problem is and the file’s path inside the site, never the files themselves.', 'arvaseo-connector' );
				}
				?>
			</span></span></label>
			<div class="arvaseo-row" style="margin-top: 12px"><button type="submit" class="arvaseo-btn arvaseo-btn--sm"><?php esc_html_e( 'Save this choice', 'arvaseo-connector' ); ?></button></div>
		</form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top: 14px">
			<input type="hidden" name="action" value="arvaseo_connector_consent_deep_scan" />
			<?php wp_nonce_field( 'arvaseo_connector_consent_deep_scan' ); ?>
			<?php $arvaseo_deep = PrivacyService::deep_scan_consent(); ?>
			<label class="arvaseo-toggle"><input type="checkbox" name="consent" value="1" <?php checked( null !== $arvaseo_deep && null !== $arvaseo_security ); ?> <?php disabled( null === $arvaseo_security ); ?> /><span class="arvaseo-switch" aria-hidden="true"></span><span><span class="arvaseo-label"><?php esc_html_e( 'Allow deep scans', 'arvaseo-connector' ); ?></span><span class="arvaseo-hint" style="display: block">
				<?php
				if ( null === $arvaseo_security ) {
					esc_html_e( 'Share the security scan’s results first.', 'arvaseo-connector' );
				} elseif ( null !== $arvaseo_deep ) {
					/* translators: %s: date and time. */
					echo esc_html( sprintf( __( 'Allowed since %s', 'arvaseo-connector' ), AdminController::when( $arvaseo_deep['consented_at'] ) ) );
				} else {
					esc_html_e( 'Only when an administrator starts one: the files that aren’t official copies go to Arva SEO to be checked for known malware, and are deleted after.', 'arvaseo-connector' );
				}
				?>
			</span></span></label>
			<div class="arvaseo-row" style="margin-top: 12px"><button type="submit" class="arvaseo-btn arvaseo-btn--sm"><?php esc_html_e( 'Save this choice', 'arvaseo-connector' ); ?></button></div>
		</form>
		<details class="arvaseo-more">
			<summary><?php esc_html_e( 'Deep scans: every field', 'arvaseo-connector' ); ?></summary>
			<div class="arvaseo-more-in">
				<ul class="arvaseo-bullets" style="margin: 0">
					<?php foreach ( PrivacyService::deep_scan_field_set() as $arvaseo_field ) : ?>
						<li><strong><?php echo esc_html( $arvaseo_field['field'] ); ?></strong>: <?php echo esc_html( $arvaseo_field['purpose'] ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
		</details>
		<details class="arvaseo-more">
			<summary><?php esc_html_e( 'Security scan results: every field', 'arvaseo-connector' ); ?></summary>
			<div class="arvaseo-more-in">
				<ul class="arvaseo-bullets" style="margin: 0">
					<?php foreach ( PrivacyService::security_field_set() as $arvaseo_field ) : ?>
						<li><strong><?php echo esc_html( $arvaseo_field['field'] ); ?></strong>: <?php echo esc_html( $arvaseo_field['purpose'] ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
		</details>
		<?php foreach ( PrivacyService::field_set() as $arvaseo_group => $arvaseo_fields ) : ?>
			<details class="arvaseo-more">
				<summary><?php echo 'inventory' === $arvaseo_group ? esc_html__( 'Plugin and theme list: every field', 'arvaseo-connector' ) : esc_html__( 'Website Health: every field', 'arvaseo-connector' ); ?></summary>
				<div class="arvaseo-more-in">
					<ul class="arvaseo-bullets" style="margin: 0">
						<?php foreach ( $arvaseo_fields as $arvaseo_field ) : ?>
							<li><strong><?php echo esc_html( $arvaseo_field['field'] ); ?></strong>: <?php echo esc_html( $arvaseo_field['purpose'] ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			</details>
		<?php endforeach; ?>
		<details class="arvaseo-more">
			<summary><?php esc_html_e( 'Never sent, and what’s stored on this site', 'arvaseo-connector' ); ?></summary>
			<div class="arvaseo-more-in">
				<p><strong><?php esc_html_e( 'Never sent', 'arvaseo-connector' ); ?></strong></p>
				<ul class="arvaseo-bullets">
					<?php foreach ( PrivacyService::never_sent() as $arvaseo_never ) : ?>
						<li><?php echo esc_html( $arvaseo_never ); ?></li>
					<?php endforeach; ?>
				</ul>
				<p style="margin-top: 10px"><strong><?php esc_html_e( 'Stored on this site', 'arvaseo-connector' ); ?></strong></p>
				<ul class="arvaseo-bullets">
					<li><?php esc_html_e( 'Encrypted connection credentials (removed on disconnect)', 'arvaseo-connector' ); ?></li>
					<li><?php esc_html_e( 'Connection details, settings and your consent record', 'arvaseo-connector' ); ?></li>
					<li><?php esc_html_e( 'Which posts came from Arva SEO, with delivery results (kept 180 days)', 'arvaseo-connector' ); ?></li>
					<li><?php esc_html_e( 'Received event IDs, to refuse replays (kept 7 days)', 'arvaseo-connector' ); ?></li>
					<li><?php esc_html_e( 'The activity log (kept for the period chosen in Settings)', 'arvaseo-connector' ); ?></li>
				</ul>
			</div>
		</details>
		<p class="arvaseo-hint" style="margin-top: 14px"><?php esc_html_e( 'To stop sharing, switch it off above or disconnect. To have Arva SEO delete what it holds, disconnect and contact Arva SEO support; see arvaseo.com/privacy/.', 'arvaseo-connector' ); ?></p>
	</section>
</div>
	<?php
} )();
