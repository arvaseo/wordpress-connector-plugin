<?php
/**
 * Content Sync page.
 *
 * @package ArvaSEO\Connector
 */

use ArvaSEO\Connector\Admin\AdminController;
use ArvaSEO\Connector\Auth\TokenStore;
use ArvaSEO\Connector\Content\PublishingPolicy;
use ArvaSEO\Connector\Content\SeoPlugins;
use ArvaSEO\Connector\Install\Schema;
use ArvaSEO\Connector\Settings;
use ArvaSEO\Connector\Sync\SyncState;

defined( 'ABSPATH' ) || exit;

// Scoped so the view's variables stay local.
( static function () {

	global $wpdb;
	$arvaseo_settings = Settings::all();
	$arvaseo_table    = Schema::table( 'deliveries' );
	$arvaseo_rows     = $wpdb->get_results( $wpdb->prepare( 'SELECT delivery_id, external_content_id, external_revision, requested_status, applied_status, outcome, wordpress_post_id, error_code, ack_sent, received_at FROM %i ORDER BY id DESC LIMIT 50', $arvaseo_table ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
	$arvaseo_modes    = array(
		'draft_only' => __( 'Draft only', 'arvaseo-connector' ),
		'editorial'  => __( 'Editorial review (draft, pending review)', 'arvaseo-connector' ),
		'scheduling' => __( 'Editorial review and scheduling', 'arvaseo-connector' ),
		'full'       => __( 'Full publishing', 'arvaseo-connector' ),
	);
	$arvaseo_author   = $arvaseo_settings['default_author_id'] > 0 ? get_userdata( (int) $arvaseo_settings['default_author_id'] ) : false;
	$arvaseo_poll     = SyncState::get( 'poll' );
	$arvaseo_seo_rows = $wpdb->get_results( $wpdb->prepare( 'SELECT update_id, outcome, target, wordpress_post_id, seo_plugin, error_code, result_sent, received_at FROM %i ORDER BY id DESC LIMIT 50', Schema::table( 'seo_updates' ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
	$arvaseo_seo      = SeoPlugins::resolve();
	?>
<div class="arvaseo-grid">
	<section class="card arvaseo-card" aria-labelledby="arvaseo-policy-heading">
		<h2 id="arvaseo-policy-heading"><?php esc_html_e( 'What Arva SEO may do here', 'arvaseo-connector' ); ?></h2>
		<table class="form-table arvaseo-facts" role="presentation">
			<tr><th scope="row"><?php esc_html_e( 'Publishing mode', 'arvaseo-connector' ); ?></th><td><strong><?php echo esc_html( $arvaseo_modes[ $arvaseo_settings['publishing_mode'] ] ); ?></strong>
				<p class="description">
					<?php
					/* translators: %s: comma-separated WordPress statuses. */
					printf( esc_html__( 'Allowed statuses: %s. Anything else is refused.', 'arvaseo-connector' ), esc_html( implode( ', ', PublishingPolicy::ALLOWS[ $arvaseo_settings['publishing_mode'] ] ) ) );
					if ( $arvaseo_settings['downgrade_to_draft'] ) {
						echo ' ' . esc_html__( 'Requests above this mode are saved as drafts instead (and reported as downgraded).', 'arvaseo-connector' );
					}
					?>
				</p></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Default author', 'arvaseo-connector' ); ?></th><td><?php echo $arvaseo_author ? esc_html( $arvaseo_author->display_name ) : wp_kses_post( AdminController::badge( 'bad', __( 'Not set: content can’t be saved until you choose one in Settings', 'arvaseo-connector' ) ) ); ?></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Allowed content types', 'arvaseo-connector' ); ?></th><td><?php echo esc_html( implode( ', ', $arvaseo_settings['allowed_post_types'] ) ); ?></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Edits made in WordPress', 'arvaseo-connector' ); ?></th><td><?php echo $arvaseo_settings['overwrite_local_edits'] ? esc_html__( 'Overwritten by newer content from Arva SEO (a revision is kept)', 'arvaseo-connector' ) : esc_html__( 'Kept: a newer version from Arva SEO is refused as a conflict', 'arvaseo-connector' ); ?></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'SEO titles and descriptions', 'arvaseo-connector' ); ?></th><td>
				<?php
				if ( ! $arvaseo_settings['seo_updates'] ) {
					esc_html_e( 'Not changed: Arva SEO may not update existing content (Settings)', 'arvaseo-connector' );
				} elseif ( isset( SeoPlugins::NAMES[ $arvaseo_seo ] ) ) {
					/* translators: %s: the SEO plugin's name. */
					printf( esc_html__( 'Updated in %s when your team approves changes in Arva SEO', 'arvaseo-connector' ), esc_html( SeoPlugins::NAMES[ $arvaseo_seo ] ) );
				} else {
					echo wp_kses_post( AdminController::badge( 'bad', __( 'Allowed, but no supported SEO plugin is in use: see Settings', 'arvaseo-connector' ) ) );
				}
				?>
			</td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Last check for content', 'arvaseo-connector' ); ?></th><td><?php echo esc_html( AdminController::when( $arvaseo_poll['last_success_at'] ?? null ) ); ?></td></tr>
		</table>
		<div class="arvaseo-actions">
			<?php
			if ( TokenStore::is_connected() ) {
				AdminController::button( 'arvaseo_connector_poll_now', __( 'Check for content now', 'arvaseo-connector' ) );
			}
			if ( current_user_can( \ArvaSEO\Connector\Install\Capabilities::MANAGE ) ) {
				printf( '<a class="button" href="%1$s">%2$s</a>', esc_url( admin_url( 'admin.php?page=arvaseo-settings' ) ), esc_html__( 'Change settings', 'arvaseo-connector' ) );
			}
			?>
		</div>
		<p class="description"><?php esc_html_e( 'Scheduled posts rely on WP-Cron, which runs when the site gets visits. On quiet sites, set up a real server cron job so posts publish on time (see the administrator guide).', 'arvaseo-connector' ); ?></p>
	</section>
</div>

<h2><?php esc_html_e( 'Recent deliveries', 'arvaseo-connector' ); ?></h2>
	<?php if ( empty( $arvaseo_rows ) ) : ?>
	<p><?php esc_html_e( 'Nothing has been sent from Arva SEO yet.', 'arvaseo-connector' ); ?></p>
	<?php else : ?>
	<table class="widefat striped">
		<caption class="screen-reader-text"><?php esc_html_e( 'Content received from Arva SEO', 'arvaseo-connector' ); ?></caption>
		<thead><tr>
			<th scope="col"><?php esc_html_e( 'Received', 'arvaseo-connector' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Post', 'arvaseo-connector' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Requested', 'arvaseo-connector' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Applied', 'arvaseo-connector' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Result', 'arvaseo-connector' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Reported to Arva SEO', 'arvaseo-connector' ); ?></th>
		</tr></thead>
		<tbody>
			<?php foreach ( $arvaseo_rows as $arvaseo_row ) : ?>
				<?php
				$arvaseo_tone = array(
					'success'  => 'good',
					'partial'  => 'warn',
					'conflict' => 'warn',
					'failed'   => 'bad',
				)[ $arvaseo_row['outcome'] ?? '' ] ?? 'neutral';
				$arvaseo_post = $arvaseo_row['wordpress_post_id'] ? get_post( (int) $arvaseo_row['wordpress_post_id'] ) : null;
				?>
			<tr>
				<td><?php echo esc_html( AdminController::when( $arvaseo_row['received_at'] . ' UTC' ) ); ?></td>
				<td>
					<?php
					if ( $arvaseo_post && current_user_can( 'edit_post', $arvaseo_post->ID ) ) {
						printf( '<a href="%1$s">%2$s</a>', esc_url( (string) get_edit_post_link( $arvaseo_post->ID ) ), esc_html( get_the_title( $arvaseo_post ) ?: __( '(no title)', 'arvaseo-connector' ) ) );
						if ( 'publish' !== $arvaseo_post->post_status ) {
							printf( ' · <a href="%1$s">%2$s</a>', esc_url( (string) get_preview_post_link( $arvaseo_post ) ), esc_html__( 'Preview', 'arvaseo-connector' ) );
						}
					} else {
						echo esc_html( $arvaseo_row['external_content_id'] . ' r' . $arvaseo_row['external_revision'] );
					}
					?>
				</td>
				<td><?php echo esc_html( (string) $arvaseo_row['requested_status'] ); ?></td>
				<td><?php echo esc_html( (string) ( $arvaseo_row['applied_status'] ?? '—' ) ); ?></td>
				<td><?php echo wp_kses_post( AdminController::badge( $arvaseo_tone, (string) ( $arvaseo_row['outcome'] ?? __( 'processing', 'arvaseo-connector' ) ) ) ); ?>
					<?php if ( ! empty( $arvaseo_row['error_code'] ) ) : ?>
						<code><?php echo esc_html( $arvaseo_row['error_code'] ); ?></code>
					<?php endif; ?></td>
				<td><?php echo $arvaseo_row['ack_sent'] ? esc_html__( 'Yes', 'arvaseo-connector' ) : esc_html__( 'Pending, will retry', 'arvaseo-connector' ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
		<?php
	endif;
	?>

<h2><?php esc_html_e( 'Recent SEO title and description updates', 'arvaseo-connector' ); ?></h2>
	<?php if ( empty( $arvaseo_seo_rows ) ) : ?>
	<p><?php esc_html_e( 'No SEO titles or descriptions have been changed from Arva SEO yet.', 'arvaseo-connector' ); ?></p>
	<?php else : ?>
	<table class="widefat striped">
		<caption class="screen-reader-text"><?php esc_html_e( 'SEO title and description updates from Arva SEO', 'arvaseo-connector' ); ?></caption>
		<thead><tr>
			<th scope="col"><?php esc_html_e( 'Received', 'arvaseo-connector' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Page', 'arvaseo-connector' ); ?></th>
			<th scope="col"><?php esc_html_e( 'SEO plugin', 'arvaseo-connector' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Result', 'arvaseo-connector' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Reported to Arva SEO', 'arvaseo-connector' ); ?></th>
		</tr></thead>
		<tbody>
			<?php foreach ( $arvaseo_seo_rows as $arvaseo_row ) : ?>
				<?php $arvaseo_post = $arvaseo_row['wordpress_post_id'] ? get_post( (int) $arvaseo_row['wordpress_post_id'] ) : null; ?>
			<tr>
				<td><?php echo esc_html( AdminController::when( $arvaseo_row['received_at'] . ' UTC' ) ); ?></td>
				<td>
					<?php
					if ( 'home' === $arvaseo_row['target'] ) {
						esc_html_e( 'Homepage', 'arvaseo-connector' );
					} elseif ( $arvaseo_post && current_user_can( 'edit_post', $arvaseo_post->ID ) ) {
						printf( '<a href="%1$s">%2$s</a>', esc_url( (string) get_edit_post_link( $arvaseo_post->ID ) ), esc_html( get_the_title( $arvaseo_post ) ?: __( '(no title)', 'arvaseo-connector' ) ) );
					} else {
						echo '—';
					}
					?>
				</td>
				<td><?php echo esc_html( SeoPlugins::NAMES[ (string) $arvaseo_row['seo_plugin'] ] ?? '—' ); ?></td>
				<td><?php echo wp_kses_post( AdminController::badge( 'applied' === $arvaseo_row['outcome'] ? 'good' : ( 'failed' === $arvaseo_row['outcome'] ? 'bad' : 'neutral' ), (string) ( $arvaseo_row['outcome'] ?? __( 'processing', 'arvaseo-connector' ) ) ) ); ?>
					<?php if ( ! empty( $arvaseo_row['error_code'] ) ) : ?>
						<code><?php echo esc_html( $arvaseo_row['error_code'] ); ?></code>
					<?php endif; ?></td>
				<td><?php echo $arvaseo_row['result_sent'] ? esc_html__( 'Yes', 'arvaseo-connector' ) : esc_html__( 'Pending, will retry', 'arvaseo-connector' ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
		<?php
	endif;
} )();
