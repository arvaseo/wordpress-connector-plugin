<?php
/**
 * Plugin list (a page of Settings).
 *
 * @package ArvaSEO\Connector
 */

use ArvaSEO\Connector\Admin\AdminController;
use ArvaSEO\Connector\Auth\TokenStore;
use ArvaSEO\Connector\Inventory\PluginInventoryService;
use ArvaSEO\Connector\Privacy\PrivacyService;
use ArvaSEO\Connector\Sync\SyncState;

defined( 'ABSPATH' ) || exit;

// Scoped so the view's variables stay local.
( static function () {

	$arvaseo_plugins = PluginInventoryService::plugins();
	$arvaseo_themes  = PluginInventoryService::themes();
	$arvaseo_state   = SyncState::get( 'inventory' );
	$arvaseo_update  = static function ( $item ) {
		if ( true === $item['update_available'] ) {
			/* translators: %s: version number. */
			return AdminController::badge( 'warn', sprintf( __( 'Update available: %s', 'arvaseo-connector' ), (string) $item['available_version'] ) );
		}
		if ( false === $item['update_available'] ) {
			return AdminController::badge( 'good', __( 'Up to date', 'arvaseo-connector' ) );
		}
		$why = array(
			'stale'       => __( 'Unknown (update data is out of date)', 'arvaseo-connector' ),
			'unavailable' => __( 'Unknown (no update data)', 'arvaseo-connector' ),
			'error'       => __( 'Unknown (update data unreadable)', 'arvaseo-connector' ),
			'current'     => __( 'Unknown (not listed by WordPress.org)', 'arvaseo-connector' ),
		);
		return AdminController::badge( 'neutral', $why[ $item['update_check_status'] ] ?? __( 'Unknown', 'arvaseo-connector' ) );
	};
	$arvaseo_types   = array(
		'standard' => __( 'Plugin', 'arvaseo-connector' ),
		'must_use' => __( 'Must-use', 'arvaseo-connector' ),
		'dropin'   => __( 'Drop-in', 'arvaseo-connector' ),
	);
	?>
<div class="arvaseo-stack">
	<section class="arvaseo-card" aria-labelledby="arvaseo-inv-heading">
		<div class="arvaseo-between">
			<div>
				<p class="arvaseo-eyebrow"><?php esc_html_e( 'Shared with Arva SEO', 'arvaseo-connector' ); ?></p>
				<h2 class="arvaseo-h2" id="arvaseo-inv-heading"><?php echo PrivacyService::allows( 'inventory' ) ? esc_html__( 'Allowed by an administrator', 'arvaseo-connector' ) : esc_html__( 'Not allowed: nothing is sent', 'arvaseo-connector' ); ?></h2>
			</div>
			<?php
			if ( TokenStore::is_connected() && PrivacyService::allows( 'inventory' ) ) {
				AdminController::button( 'arvaseo_connector_sync_inventory', __( 'Send it now', 'arvaseo-connector' ), 'arvaseo-btn arvaseo-btn--sm arvaseo-btn--dark' );
			}
			?>
		</div>
		<dl class="arvaseo-facts">
			<div><dt><?php esc_html_e( 'Last sent to Arva SEO', 'arvaseo-connector' ); ?></dt><dd><?php echo esc_html( AdminController::when( $arvaseo_state['last_success_at'] ?? null ) ); ?></dd></div>
			<div><dt><?php esc_html_e( 'Last attempt', 'arvaseo-connector' ); ?></dt><dd>
				<?php echo esc_html( AdminController::when( $arvaseo_state['at'] ?? null ) ); ?>
				<?php if ( 'failed' === ( $arvaseo_state['status'] ?? '' ) ) : ?>
					<?php echo AdminController::badge( 'bad', __( 'Failed', 'arvaseo-connector' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes. ?> <code><?php echo esc_html( (string) ( $arvaseo_state['error']['code'] ?? '' ) ); ?></code>
				<?php endif; ?>
			</dd></div>
		</dl>
		<details class="arvaseo-more" style="margin-top: 16px">
			<summary><?php esc_html_e( 'Exactly which fields are shared', 'arvaseo-connector' ); ?></summary>
			<div class="arvaseo-more-in">
				<ul class="arvaseo-bullets" style="margin: 0">
					<?php foreach ( PrivacyService::field_set()['inventory'] as $arvaseo_field ) : ?>
						<li><?php echo esc_html( $arvaseo_field['field'] ); ?></li>
					<?php endforeach; ?>
				</ul>
				<p style="margin-top: 10px"><?php esc_html_e( 'Plugin settings, license keys and file paths are never sent. The list never forces an update check: it uses the update data WordPress already has.', 'arvaseo-connector' ); ?></p>
			</div>
		</details>
	</section>

	<section class="arvaseo-card" aria-labelledby="arvaseo-plugins-heading">
		<h2 class="arvaseo-h2" id="arvaseo-plugins-heading"><?php esc_html_e( 'Plugins', 'arvaseo-connector' ); ?></h2>
		<div class="arvaseo-tablebox">
			<table class="arvaseo-table">
				<caption class="screen-reader-text"><?php esc_html_e( 'Installed plugins', 'arvaseo-connector' ); ?></caption>
				<thead><tr>
					<th scope="col"><?php esc_html_e( 'Plugin', 'arvaseo-connector' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Type', 'arvaseo-connector' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Version', 'arvaseo-connector' ); ?></th>
					<th scope="col"><?php esc_html_e( 'State', 'arvaseo-connector' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Updates', 'arvaseo-connector' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Automatic updates', 'arvaseo-connector' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $arvaseo_plugins as $arvaseo_plugin ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $arvaseo_plugin['name'] ); ?></strong><br /><code><?php echo esc_html( $arvaseo_plugin['id'] ); ?></code></td>
						<td><?php echo esc_html( $arvaseo_types[ $arvaseo_plugin['type'] ] ?? $arvaseo_plugin['type'] ); ?></td>
						<td><?php echo esc_html( (string) ( $arvaseo_plugin['version'] ?? '—' ) ); ?></td>
						<td>
							<?php
							if ( $arvaseo_plugin['network_active'] ) {
								echo AdminController::badge( 'good', __( 'Network active', 'arvaseo-connector' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
							} elseif ( $arvaseo_plugin['active'] ) {
								echo AdminController::badge( 'good', __( 'Active', 'arvaseo-connector' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
							} else {
								echo AdminController::badge( 'neutral', __( 'Inactive', 'arvaseo-connector' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
							}
							?>
						</td>
						<td><?php echo $arvaseo_update( $arvaseo_plugin ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes. ?></td>
						<td><?php echo null === $arvaseo_plugin['auto_update'] ? esc_html__( 'Not applicable', 'arvaseo-connector' ) : ( $arvaseo_plugin['auto_update'] ? esc_html__( 'On', 'arvaseo-connector' ) : esc_html__( 'Off', 'arvaseo-connector' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</section>

	<section class="arvaseo-card" aria-labelledby="arvaseo-themes-heading">
		<h2 class="arvaseo-h2" id="arvaseo-themes-heading"><?php esc_html_e( 'Themes', 'arvaseo-connector' ); ?></h2>
		<div class="arvaseo-tablebox">
			<table class="arvaseo-table">
				<caption class="screen-reader-text"><?php esc_html_e( 'Installed themes', 'arvaseo-connector' ); ?></caption>
				<thead><tr>
					<th scope="col"><?php esc_html_e( 'Theme', 'arvaseo-connector' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Version', 'arvaseo-connector' ); ?></th>
					<th scope="col"><?php esc_html_e( 'State', 'arvaseo-connector' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Updates', 'arvaseo-connector' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $arvaseo_themes as $arvaseo_theme ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $arvaseo_theme['name'] ); ?></strong><br /><code><?php echo esc_html( $arvaseo_theme['stylesheet'] ); ?></code></td>
						<td><?php echo esc_html( (string) ( $arvaseo_theme['version'] ?? '—' ) ); ?></td>
						<td>
							<?php
							$arvaseo_role = array(
								'child'      => __( 'child theme', 'arvaseo-connector' ),
								'parent'     => __( 'parent of the active theme', 'arvaseo-connector' ),
								'standalone' => '',
							)[ $arvaseo_theme['role'] ];
							echo AdminController::badge( $arvaseo_theme['active'] ? 'good' : 'neutral', $arvaseo_theme['active'] ? __( 'Active', 'arvaseo-connector' ) : ( 'parent' === $arvaseo_theme['role'] ? __( 'In use as parent', 'arvaseo-connector' ) : __( 'Inactive', 'arvaseo-connector' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
							echo '' !== $arvaseo_role ? ' ' . esc_html( $arvaseo_role ) : '';
							?>
						</td>
						<td><?php echo $arvaseo_update( $arvaseo_theme ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes. ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</section>
</div>
	<?php
} )();
