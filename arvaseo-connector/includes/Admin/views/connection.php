<?php
/**
 * Connection page.
 *
 * @package ArvaSEO\Connector
 */

use ArvaSEO\Connector\Admin\AdminController;
use ArvaSEO\Connector\Auth\TokenStore;
use ArvaSEO\Connector\Config;
use ArvaSEO\Connector\Install\Capabilities;
use ArvaSEO\Connector\Privacy\PrivacyService;

defined( 'ABSPATH' ) || exit;

// Scoped so the view's variables stay local.
( static function () {

	$arvaseo_connection  = TokenStore::connection();
	$arvaseo_credentials = TokenStore::credentials();
	$arvaseo_status      = $arvaseo_connection['status'] ?? 'disconnected';
	$arvaseo_can_manage  = current_user_can( Capabilities::MANAGE );
	$arvaseo_scope_text  = array(
		'site:register'          => __( 'Identify this site and keep the connection working', 'arvaseo-connector' ),
		'plugin-inventory:write' => __( 'Send the plugin and theme inventory', 'arvaseo-connector' ),
		'health-snapshot:write'  => __( 'Send Website Health checks', 'arvaseo-connector' ),
		'health-assessment:read' => __( 'Show Arva SEO’s findings here', 'arvaseo-connector' ),
		'content-delivery:read'  => __( 'Receive content sent from Arva SEO', 'arvaseo-connector' ),
		'content-status:write'   => __( 'Report what WordPress did with that content', 'arvaseo-connector' ),
	);
	$arvaseo_labels      = array(
		'connected'       => array( 'good', __( 'Connected', 'arvaseo-connector' ) ),
		'reauth_required' => array( 'bad', __( 'Reconnect required', 'arvaseo-connector' ) ),
		'disconnected'    => array( 'neutral', __( 'Not connected', 'arvaseo-connector' ) ),
	);
	if ( TokenStore::unreadable() ) {
		$arvaseo_status = 'reauth_required';
	}
	$arvaseo_label = $arvaseo_labels[ $arvaseo_status ] ?? $arvaseo_labels['disconnected'];
	?>
<div class="arvaseo-grid">
	<section class="card arvaseo-card" aria-labelledby="arvaseo-status-heading">
		<h2 id="arvaseo-status-heading"><?php esc_html_e( 'Connection status', 'arvaseo-connector' ); ?></h2>
		<p><?php echo wp_kses_post( AdminController::badge( $arvaseo_label[0], $arvaseo_label[1] ) ); ?></p>

		<?php if ( 'disconnected' !== $arvaseo_status && null !== TokenStore::site_id() ) : ?>
			<table class="form-table arvaseo-facts" role="presentation">
				<tr><th scope="row"><?php esc_html_e( 'Workspace', 'arvaseo-connector' ); ?></th><td><?php echo esc_html( $arvaseo_connection['workspace']['name'] ?? '—' ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Website in Arva SEO', 'arvaseo-connector' ); ?></th><td><?php echo esc_html( ( $arvaseo_connection['project']['name'] ?? '—' ) . ( empty( $arvaseo_connection['project']['domain'] ) ? '' : ' (' . $arvaseo_connection['project']['domain'] . ')' ) ); ?></td></tr>
				<?php if ( ! empty( $arvaseo_connection['client'] ) ) : ?>
					<tr><th scope="row"><?php esc_html_e( 'Client', 'arvaseo-connector' ); ?></th><td><?php echo esc_html( $arvaseo_connection['client'] ); ?></td></tr>
				<?php endif; ?>
				<tr><th scope="row"><?php esc_html_e( 'Site registration ID', 'arvaseo-connector' ); ?></th><td><code><?php echo esc_html( substr( (string) TokenStore::site_id(), 0, 6 ) . '…' . substr( (string) TokenStore::site_id(), -4 ) ); ?></code></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Permissions granted', 'arvaseo-connector' ); ?></th><td><ul class="arvaseo-list">
					<?php foreach ( (array) ( $arvaseo_connection['scopes'] ?? array() ) as $arvaseo_scope ) : ?>
						<li><?php echo esc_html( $arvaseo_scope_text[ $arvaseo_scope ] ?? $arvaseo_scope ); ?> <code><?php echo esc_html( $arvaseo_scope ); ?></code></li>
					<?php endforeach; ?>
				</ul></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Connected', 'arvaseo-connector' ); ?></th><td><?php echo esc_html( AdminController::when( $arvaseo_connection['connected_at'] ?? null ) ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Last successful communication', 'arvaseo-connector' ); ?></th><td><?php echo esc_html( AdminController::when( $arvaseo_connection['last_success_at'] ?? null ) ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Access renews', 'arvaseo-connector' ); ?></th><td><?php echo esc_html( null === $arvaseo_credentials ? __( 'Unavailable', 'arvaseo-connector' ) : wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $arvaseo_credentials['access_expires_at'] ) ); ?>
					<p class="description"><?php esc_html_e( 'Access is renewed automatically; tokens are never shown.', 'arvaseo-connector' ); ?></p></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Instant requests from Arva SEO', 'arvaseo-connector' ); ?></th><td>
					<?php
					$arvaseo_push = $arvaseo_connection['push_status'] ?? 'unverified';
					echo wp_kses_post( 'verified' === $arvaseo_push ? AdminController::badge( 'good', __( 'Working', 'arvaseo-connector' ) ) : AdminController::badge( 'neutral', __( 'Not available (checked every 5 minutes instead)', 'arvaseo-connector' ) ) );
					?>
				</td></tr>
				<?php if ( ! empty( $arvaseo_connection['last_error']['code'] ) ) : ?>
					<tr><th scope="row"><?php esc_html_e( 'Last error', 'arvaseo-connector' ); ?></th><td><code><?php echo esc_html( $arvaseo_connection['last_error']['code'] ); ?></code> — <?php echo esc_html( AdminController::when( $arvaseo_connection['last_error']['at'] ?? null ) ); ?></td></tr>
				<?php endif; ?>
			</table>
			<?php if ( $arvaseo_can_manage ) : ?>
				<div class="arvaseo-actions">
					<?php
					AdminController::button( 'arvaseo_connector_test', __( 'Test connection', 'arvaseo-connector' ) );
					AdminController::button( 'arvaseo_connector_disconnect', __( 'Disconnect', 'arvaseo-connector' ), 'button button-link-delete', 'confirmDisconnect' );
					if ( ! empty( $arvaseo_connection['app_url'] ) ) {
						printf( '<a class="button" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s<span class="screen-reader-text"> %3$s</span></a>', esc_url( $arvaseo_connection['app_url'] ), esc_html__( 'View in Arva SEO', 'arvaseo-connector' ), esc_html__( '(opens in a new tab)', 'arvaseo-connector' ) );
					}
					?>
				</div>
			<?php endif; ?>
		<?php endif; ?>
	</section>

	<?php if ( $arvaseo_can_manage && ( 'connected' !== $arvaseo_status ) ) : ?>
		<section class="card arvaseo-card" aria-labelledby="arvaseo-connect-heading">
			<h2 id="arvaseo-connect-heading"><?php echo 'reauth_required' === $arvaseo_status ? esc_html__( 'Reconnect to Arva SEO', 'arvaseo-connector' ) : esc_html__( 'Connect to Arva SEO', 'arvaseo-connector' ); ?></h2>
			<p><?php esc_html_e( 'You’ll sign in on app.arvaseo.com and choose the website this WordPress site belongs to. You never enter an Arva SEO password here.', 'arvaseo-connector' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="arvaseo_connector_connect" />
				<?php wp_nonce_field( 'arvaseo_connector_connect' ); ?>
				<fieldset>
					<legend class="screen-reader-text"><?php esc_html_e( 'What to allow', 'arvaseo-connector' ); ?></legend>
					<p><label><input type="checkbox" name="share_health" value="1" checked="checked" /> <?php esc_html_e( 'Share the plugin inventory and Website Health checks with Arva SEO', 'arvaseo-connector' ); ?></label></p>
					<details class="arvaseo-disclosure" open>
						<summary><?php esc_html_e( 'Exactly what is shared', 'arvaseo-connector' ); ?></summary>
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
						<p><?php esc_html_e( 'Recipient: Arva SEO (api.arvaseo.com). Purpose: explain this site’s health, updates and risks with fix guidance. No other telemetry is collected.', 'arvaseo-connector' ); ?></p>
					</details>
					<p><label><input type="checkbox" name="consent" value="1" /> <?php esc_html_e( 'I agree to share the data listed above with Arva SEO for these purposes.', 'arvaseo-connector' ); ?></label></p>
					<p><label><input type="checkbox" name="allow_content" value="1" checked="checked" /> <?php esc_html_e( 'Allow Arva SEO to send content to this site', 'arvaseo-connector' ); ?></label>
						<span class="description"><?php esc_html_e( '(only as drafts until you choose another publishing mode in Settings)', 'arvaseo-connector' ); ?></span></p>
				</fieldset>
				<p><button type="submit" class="button button-primary"><?php echo 'reauth_required' === $arvaseo_status ? esc_html__( 'Reconnect to Arva SEO', 'arvaseo-connector' ) : esc_html__( 'Connect to Arva SEO', 'arvaseo-connector' ); ?></button></p>
				<p class="description">
					<?php
					/* translators: %s: Arva SEO app host. */
					printf( esc_html__( 'You’ll be sent to %s and brought back here.', 'arvaseo-connector' ), esc_html( (string) wp_parse_url( Config::app_url(), PHP_URL_HOST ) ) );
					?>
				</p>
			</form>
		</section>
	<?php endif; ?>
</div>
	<?php
} )();
