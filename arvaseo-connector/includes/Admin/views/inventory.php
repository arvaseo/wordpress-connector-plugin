<?php
/**
 * Plugin Inventory page.
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
<div class="arvaseo-grid">
	<section class="card arvaseo-card" aria-labelledby="arvaseo-inv-heading">
		<h2 id="arvaseo-inv-heading"><?php esc_html_e( 'Inventory sync', 'arvaseo-connector' ); ?></h2>
		<table class="form-table arvaseo-facts" role="presentation">
			<tr><th scope="row"><?php esc_html_e( 'Last sent to Arva SEO', 'arvaseo-connector' ); ?></th><td><?php echo esc_html( AdminController::when( $arvaseo_state['last_success_at'] ?? null ) ); ?></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Last attempt', 'arvaseo-connector' ); ?></th><td>
				<?php echo esc_html( AdminController::when( $arvaseo_state['at'] ?? null ) ); ?>
				<?php if ( 'failed' === ( $arvaseo_state['status'] ?? '' ) ) : ?>
					<?php echo wp_kses_post( AdminController::badge( 'bad', __( 'Failed', 'arvaseo-connector' ) ) ); ?> <code><?php echo esc_html( (string) ( $arvaseo_state['error']['code'] ?? '' ) ); ?></code>
				<?php endif; ?>
			</td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Sharing', 'arvaseo-connector' ); ?></th><td><?php echo PrivacyService::allows( 'inventory' ) ? esc_html__( 'Allowed by an administrator', 'arvaseo-connector' ) : wp_kses_post( AdminController::badge( 'neutral', __( 'Not allowed: nothing is sent', 'arvaseo-connector' ) ) ); ?></td></tr>
		</table>
		<?php
		if ( TokenStore::is_connected() && PrivacyService::allows( 'inventory' ) ) {
			AdminController::button( 'arvaseo_connector_sync_inventory', __( 'Sync inventory now', 'arvaseo-connector' ), 'button button-primary' );
		}
		?>
		<details class="arvaseo-disclosure">
			<summary><?php esc_html_e( 'Exactly which fields are shared', 'arvaseo-connector' ); ?></summary>
			<ul class="arvaseo-list">
				<?php foreach ( PrivacyService::field_set()['inventory'] as $arvaseo_field ) : ?>
					<li><?php echo esc_html( $arvaseo_field['field'] ); ?></li>
				<?php endforeach; ?>
			</ul>
			<p><?php esc_html_e( 'Plugin settings, license keys and file paths are never sent. The inventory never forces an update check: it uses the update data WordPress already has.', 'arvaseo-connector' ); ?></p>
		</details>
	</section>
</div>

<h2><?php esc_html_e( 'Plugins', 'arvaseo-connector' ); ?></h2>
<table class="widefat striped">
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
					echo wp_kses_post( AdminController::badge( 'good', __( 'Network active', 'arvaseo-connector' ) ) );
				} elseif ( $arvaseo_plugin['active'] ) {
					echo wp_kses_post( AdminController::badge( 'good', __( 'Active', 'arvaseo-connector' ) ) );
				} else {
					echo wp_kses_post( AdminController::badge( 'neutral', __( 'Inactive', 'arvaseo-connector' ) ) );
				}
				?>
			</td>
			<td><?php echo wp_kses_post( $arvaseo_update( $arvaseo_plugin ) ); ?></td>
			<td><?php echo null === $arvaseo_plugin['auto_update'] ? esc_html__( 'Not applicable', 'arvaseo-connector' ) : ( $arvaseo_plugin['auto_update'] ? esc_html__( 'On', 'arvaseo-connector' ) : esc_html__( 'Off', 'arvaseo-connector' ) ); ?></td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>

<h2><?php esc_html_e( 'Themes', 'arvaseo-connector' ); ?></h2>
<table class="widefat striped">
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
				echo wp_kses_post( AdminController::badge( $arvaseo_theme['active'] ? 'good' : 'neutral', $arvaseo_theme['active'] ? __( 'Active', 'arvaseo-connector' ) : ( 'parent' === $arvaseo_theme['role'] ? __( 'In use as parent', 'arvaseo-connector' ) : __( 'Inactive', 'arvaseo-connector' ) ) ) );
				echo '' !== $arvaseo_role ? ' ' . esc_html( $arvaseo_role ) : '';
				?>
			</td>
			<td><?php echo wp_kses_post( $arvaseo_update( $arvaseo_theme ) ); ?></td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
	<?php
} )();
