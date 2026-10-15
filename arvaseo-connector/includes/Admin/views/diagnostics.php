<?php
/**
 * Diagnostics and Privacy page.
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
<div class="arvaseo-grid">
	<section class="card arvaseo-card" aria-labelledby="arvaseo-diag-heading">
		<h2 id="arvaseo-diag-heading"><?php esc_html_e( 'Diagnostics', 'arvaseo-connector' ); ?></h2>
		<p><?php esc_html_e( 'Share this with Arva SEO support if something isn’t working. It contains versions, connection state, sync results and settings only: no tokens, secrets, file paths, usernames, emails or content.', 'arvaseo-connector' ); ?></p>
		<label for="arvaseo-diagnostics" class="screen-reader-text"><?php esc_html_e( 'Diagnostics', 'arvaseo-connector' ); ?></label>
		<textarea id="arvaseo-diagnostics" class="large-text code" rows="16" readonly="readonly"><?php echo esc_textarea( DiagnosticsService::json() ); ?></textarea>
		<div class="arvaseo-actions">
			<button type="button" class="button" data-arvaseo-copy="arvaseo-diagnostics"><?php esc_html_e( 'Copy', 'arvaseo-connector' ); ?></button>
			<?php AdminController::button( 'arvaseo_connector_diagnostics', __( 'Download', 'arvaseo-connector' ) ); ?>
			<span class="arvaseo-copied" role="status" aria-live="polite"></span>
		</div>
	</section>

	<section class="card arvaseo-card" aria-labelledby="arvaseo-privacy-heading">
		<h2 id="arvaseo-privacy-heading"><?php esc_html_e( 'Data sharing', 'arvaseo-connector' ); ?></h2>
		<p>
			<?php
			if ( null === $arvaseo_consent ) {
				echo wp_kses_post( AdminController::badge( 'neutral', __( 'Not shared: no plugin inventory or health data is sent', 'arvaseo-connector' ) ) );
			} else {
				/* translators: %s: date and time. */
				echo wp_kses_post( AdminController::badge( 'good', sprintf( __( 'Shared since %s', 'arvaseo-connector' ), AdminController::when( $arvaseo_consent['consented_at'] ) ) ) );
			}
			?>
		</p>
		<?php foreach ( PrivacyService::field_set() as $arvaseo_group => $arvaseo_fields ) : ?>
			<h3><?php echo 'inventory' === $arvaseo_group ? esc_html__( 'Plugin and theme inventory', 'arvaseo-connector' ) : esc_html__( 'Website Health', 'arvaseo-connector' ); ?></h3>
			<ul class="arvaseo-list">
				<?php foreach ( $arvaseo_fields as $arvaseo_field ) : ?>
					<li><strong><?php echo esc_html( $arvaseo_field['field'] ); ?></strong> — <?php echo esc_html( $arvaseo_field['purpose'] ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endforeach; ?>
		<h3><?php esc_html_e( 'Never sent', 'arvaseo-connector' ); ?></h3>
		<ul class="arvaseo-list">
			<?php foreach ( PrivacyService::never_sent() as $arvaseo_never ) : ?>
				<li><?php echo esc_html( $arvaseo_never ); ?></li>
			<?php endforeach; ?>
		</ul>
		<h3><?php esc_html_e( 'Stored on this site', 'arvaseo-connector' ); ?></h3>
		<ul class="arvaseo-list">
			<li><?php esc_html_e( 'Encrypted connection credentials (removed on disconnect)', 'arvaseo-connector' ); ?></li>
			<li><?php esc_html_e( 'Connection details, settings and your consent record', 'arvaseo-connector' ); ?></li>
			<li><?php esc_html_e( 'Which posts came from Arva SEO, with delivery results (kept 180 days)', 'arvaseo-connector' ); ?></li>
			<li><?php esc_html_e( 'Received event IDs, to refuse replays (kept 7 days)', 'arvaseo-connector' ); ?></li>
			<li><?php esc_html_e( 'The activity log (kept for the period chosen in Settings)', 'arvaseo-connector' ); ?></li>
		</ul>
		<p><?php esc_html_e( 'To stop sharing, withdraw consent below or disconnect. To have Arva SEO delete what it holds, disconnect and contact Arva SEO support; see arvaseo.com/privacy/.', 'arvaseo-connector' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="arvaseo_connector_consent" />
			<?php wp_nonce_field( 'arvaseo_connector_consent' ); ?>
			<p><label><input type="checkbox" name="consent" value="1" <?php checked( null !== $arvaseo_consent ); ?> /> <?php esc_html_e( 'Share the plugin inventory and Website Health checks listed above with Arva SEO', 'arvaseo-connector' ); ?></label></p>
			<p><button type="submit" class="button"><?php esc_html_e( 'Save data-sharing choice', 'arvaseo-connector' ); ?></button></p>
		</form>
	</section>
</div>
	<?php
} )();
