<?php
/**
 * Content: what Arva SEO may send here, and what it sent.
 *
 * @package ArvaSEO\Connector
 */

use ArvaSEO\Connector\Admin\AdminController;
use ArvaSEO\Connector\Auth\TokenStore;
use ArvaSEO\Connector\Content\PublishingPolicy;
use ArvaSEO\Connector\Content\SeoPlugins;
use ArvaSEO\Connector\Install\Capabilities;
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
		'draft_only' => array( __( 'Draft only', 'arvaseo-connector' ), __( 'Content arrives as drafts.', 'arvaseo-connector' ) ),
		'editorial'  => array( __( 'Editorial review', 'arvaseo-connector' ), __( 'Drafts, or Pending Review.', 'arvaseo-connector' ) ),
		'scheduling' => array( __( 'And scheduling', 'arvaseo-connector' ), __( 'Also posts for a future time.', 'arvaseo-connector' ) ),
		'full'       => array( __( 'Full publishing', 'arvaseo-connector' ), __( 'Also publishes straight away.', 'arvaseo-connector' ) ),
	);
	$arvaseo_author   = $arvaseo_settings['default_author_id'] > 0 ? get_userdata( (int) $arvaseo_settings['default_author_id'] ) : false;
	$arvaseo_poll     = SyncState::get( 'poll' );
	$arvaseo_seo_rows = $wpdb->get_results( $wpdb->prepare( 'SELECT update_id, outcome, target, wordpress_post_id, seo_plugin, error_code, result_sent, received_at FROM %i ORDER BY id DESC LIMIT 50', Schema::table( 'seo_updates' ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
	$arvaseo_seo      = SeoPlugins::resolve();
	$arvaseo_manage   = current_user_can( Capabilities::MANAGE );
	?>
<div class="arvaseo-stack">
	<?php if ( ! $arvaseo_author ) : ?>
		<div class="arvaseo-alert" role="alert">
			<?php echo AdminController::icon( 'alert', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
			<div><h2><?php esc_html_e( 'Choose who posts content from Arva SEO', 'arvaseo-connector' ); ?></h2><p style="margin: 2px 0 0"><?php esc_html_e( 'Drafts from Arva SEO can’t be saved until you pick the WordPress user they’re created as.', 'arvaseo-connector' ); ?></p></div>
			<?php if ( $arvaseo_manage ) : ?>
				<a class="arvaseo-btn arvaseo-btn--sm arvaseo-btn--dark" href="<?php echo esc_url( admin_url( 'admin.php?page=arvaseo-settings#arvaseo-settings-publishing' ) ); ?>"><?php esc_html_e( 'Choose an author', 'arvaseo-connector' ); ?></a>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<section class="arvaseo-card" aria-labelledby="arvaseo-policy-heading">
		<div class="arvaseo-between">
			<div><p class="arvaseo-eyebrow"><?php esc_html_e( 'What Arva SEO may do here', 'arvaseo-connector' ); ?></p><h2 class="arvaseo-h2" id="arvaseo-policy-heading"><?php echo esc_html( $arvaseo_modes[ $arvaseo_settings['publishing_mode'] ][0] ); ?></h2></div>
			<div class="arvaseo-row">
				<?php
				if ( TokenStore::is_connected() ) {
					AdminController::button( 'arvaseo_connector_poll_now', __( 'Check for content now', 'arvaseo-connector' ) );
				}
				if ( $arvaseo_manage ) {
					printf( '<a class="arvaseo-btn arvaseo-btn--sm arvaseo-btn--ghost" href="%1$s">%2$s</a>', esc_url( admin_url( 'admin.php?page=arvaseo-settings#arvaseo-settings-publishing' ) ), esc_html__( 'Change in Settings', 'arvaseo-connector' ) );
				}
				?>
			</div>
		</div>
		<ol class="arvaseo-ladder" aria-label="<?php esc_attr_e( 'Publishing modes, from the safest', 'arvaseo-connector' ); ?>">
			<?php foreach ( $arvaseo_modes as $arvaseo_key => list( $arvaseo_label, $arvaseo_desc ) ) : ?>
				<li class="arvaseo-rung<?php echo $arvaseo_key === $arvaseo_settings['publishing_mode'] ? ' arvaseo-rung--on' : ''; ?>"<?php echo $arvaseo_key === $arvaseo_settings['publishing_mode'] ? ' aria-current="true"' : ''; ?>>
					<b><?php echo esc_html( $arvaseo_label ); ?></b><?php echo esc_html( $arvaseo_desc ); ?>
					<?php if ( $arvaseo_key === $arvaseo_settings['publishing_mode'] ) : ?>
						<br /><?php echo AdminController::badge( 'good', __( 'Current', 'arvaseo-connector' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes. ?>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ol>
		<p class="arvaseo-hint">
			<?php
			/* translators: %s: comma-separated WordPress statuses. */
			printf( esc_html__( 'Allowed statuses: %s. Arva SEO can never go beyond the mode you set: anything above it is refused.', 'arvaseo-connector' ), esc_html( implode( ', ', PublishingPolicy::ALLOWS[ $arvaseo_settings['publishing_mode'] ] ) ) );
			if ( $arvaseo_settings['downgrade_to_draft'] ) {
				echo ' ' . esc_html__( 'Requests above this mode are saved as drafts instead (and reported as downgraded).', 'arvaseo-connector' );
			}
			?>
		</p>
		<dl class="arvaseo-facts">
			<div><dt><?php esc_html_e( 'Default author', 'arvaseo-connector' ); ?></dt><dd><?php echo $arvaseo_author ? esc_html( $arvaseo_author->display_name ) : AdminController::badge( 'warn', __( 'Not chosen', 'arvaseo-connector' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped here or by badge(). ?></dd></div>
			<div><dt><?php esc_html_e( 'Content types', 'arvaseo-connector' ); ?></dt><dd><?php echo esc_html( implode( ', ', array_map( static fn ( string $type ): string => (string) ( get_post_type_object( $type )->labels->name ?? $type ), $arvaseo_settings['allowed_post_types'] ) ) ); ?></dd></div>
			<div><dt><?php esc_html_e( 'Edits made in WordPress', 'arvaseo-connector' ); ?></dt><dd><?php echo $arvaseo_settings['overwrite_local_edits'] ? esc_html__( 'Replaced by newer content from Arva SEO (a revision is kept)', 'arvaseo-connector' ) : esc_html__( 'Kept. A newer version from Arva SEO is reported as a conflict.', 'arvaseo-connector' ); ?></dd></div>
			<div><dt><?php esc_html_e( 'SEO titles and descriptions', 'arvaseo-connector' ); ?></dt><dd>
				<?php
				if ( ! $arvaseo_settings['seo_updates'] ) {
					echo AdminController::badge( 'neutral', __( 'Not allowed', 'arvaseo-connector' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
					if ( $arvaseo_manage ) {
						printf( ' <a href="%1$s">%2$s</a>', esc_url( admin_url( 'admin.php?page=arvaseo-settings#arvaseo-settings-seo' ) ), esc_html__( 'Allow in Settings', 'arvaseo-connector' ) );
					}
				} elseif ( isset( SeoPlugins::NAMES[ $arvaseo_seo ] ) ) {
					/* translators: %s: the SEO plugin's name. */
					printf( esc_html__( 'Written to %s when your team approves them', 'arvaseo-connector' ), esc_html( SeoPlugins::NAMES[ $arvaseo_seo ] ) );
				} else {
					echo AdminController::badge( 'bad', __( 'Allowed, but no supported SEO plugin is in use: see Settings', 'arvaseo-connector' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
				}
				?>
			</dd></div>
			<div><dt><?php esc_html_e( 'Last check for content', 'arvaseo-connector' ); ?></dt><dd><?php echo esc_html( AdminController::when( $arvaseo_poll['last_success_at'] ?? null ) ); ?></dd></div>
		</dl>
	</section>

	<section class="arvaseo-card" aria-labelledby="arvaseo-deliv-heading">
		<p class="arvaseo-eyebrow"><?php esc_html_e( 'Sent from Arva SEO', 'arvaseo-connector' ); ?></p>
		<h2 class="arvaseo-h2" id="arvaseo-deliv-heading"><?php esc_html_e( 'Recent deliveries', 'arvaseo-connector' ); ?></h2>
		<?php if ( empty( $arvaseo_rows ) ) : ?>
			<div class="arvaseo-empty">
				<span class="arvaseo-empty-ic" aria-hidden="true"><?php echo AdminController::icon( 'file', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></span>
				<div><h3><?php esc_html_e( 'Nothing sent yet', 'arvaseo-connector' ); ?></h3><p><?php esc_html_e( 'When your team sends a draft from Arva SEO’s Content section, it appears here and in Posts, for you to check and publish.', 'arvaseo-connector' ); ?></p></div>
			</div>
		<?php else : ?>
			<div class="arvaseo-tablebox">
				<table class="arvaseo-table">
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
							<td><time><?php echo esc_html( AdminController::when( $arvaseo_row['received_at'] . ' UTC' ) ); ?></time></td>
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
							<td><?php echo AdminController::badge( $arvaseo_tone, (string) ( $arvaseo_row['outcome'] ?? __( 'processing', 'arvaseo-connector' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes. ?>
								<?php if ( ! empty( $arvaseo_row['error_code'] ) ) : ?>
									<code><?php echo esc_html( $arvaseo_row['error_code'] ); ?></code>
								<?php endif; ?></td>
							<td><?php echo $arvaseo_row['ack_sent'] ? esc_html__( 'Yes', 'arvaseo-connector' ) : esc_html__( 'Pending, will retry', 'arvaseo-connector' ); ?></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	</section>

	<section class="arvaseo-card" aria-labelledby="arvaseo-seo-heading">
		<p class="arvaseo-eyebrow"><?php echo esc_html( SeoPlugins::NAMES[ $arvaseo_seo ] ?? __( 'Your SEO plugin', 'arvaseo-connector' ) ); ?></p>
		<h2 class="arvaseo-h2" id="arvaseo-seo-heading"><?php esc_html_e( 'SEO title and description updates', 'arvaseo-connector' ); ?></h2>
		<?php if ( empty( $arvaseo_seo_rows ) ) : ?>
			<div class="arvaseo-empty">
				<span class="arvaseo-empty-ic" aria-hidden="true"><?php echo AdminController::icon( 'pencil', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></span>
				<div>
					<?php if ( $arvaseo_settings['seo_updates'] ) : ?>
						<h3><?php esc_html_e( 'None yet', 'arvaseo-connector' ); ?></h3><p><?php esc_html_e( 'The titles and descriptions your team approves in Arva SEO appear here once they’re written.', 'arvaseo-connector' ); ?></p>
					<?php else : ?>
						<h3><?php esc_html_e( 'Not allowed yet', 'arvaseo-connector' ); ?></h3><p><?php esc_html_e( 'Turn on “Existing content” in Settings to let Arva SEO write the titles and descriptions your team approves. Each change can be undone from Arva SEO.', 'arvaseo-connector' ); ?></p>
					<?php endif; ?>
				</div>
			</div>
		<?php else : ?>
			<div class="arvaseo-tablebox">
				<table class="arvaseo-table">
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
							<td><time><?php echo esc_html( AdminController::when( $arvaseo_row['received_at'] . ' UTC' ) ); ?></time></td>
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
							<td><?php echo AdminController::badge( 'applied' === $arvaseo_row['outcome'] ? 'good' : ( 'failed' === $arvaseo_row['outcome'] ? 'bad' : 'neutral' ), (string) ( $arvaseo_row['outcome'] ?? __( 'processing', 'arvaseo-connector' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes. ?>
								<?php if ( ! empty( $arvaseo_row['error_code'] ) ) : ?>
									<code><?php echo esc_html( $arvaseo_row['error_code'] ); ?></code>
								<?php endif; ?></td>
							<td><?php echo $arvaseo_row['result_sent'] ? esc_html__( 'Yes', 'arvaseo-connector' ) : esc_html__( 'Pending, will retry', 'arvaseo-connector' ); ?></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	</section>

	<p class="arvaseo-hint" style="margin: 0"><?php esc_html_e( 'Scheduled posts rely on WP-Cron, which runs when the site gets visits. On quiet sites, set up a real server cron job so posts publish on time (see the administrator guide).', 'arvaseo-connector' ); ?></p>
</div>
	<?php
} )();
