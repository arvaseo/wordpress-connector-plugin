<?php
/**
 * Settings.
 *
 * @package ArvaSEO\Connector
 */

use ArvaSEO\Connector\Admin\AdminController;
use ArvaSEO\Connector\Auth\TokenStore;
use ArvaSEO\Connector\Content\SeoPlugins;
use ArvaSEO\Connector\Install\Capabilities;
use ArvaSEO\Connector\Privacy\PrivacyService;
use ArvaSEO\Connector\Settings;
use ArvaSEO\Connector\Sync\SyncState;

defined( 'ABSPATH' ) || exit;

// Scoped so the view's variables stay local.
( static function () {

	$arvaseo_s     = Settings::all();
	$arvaseo_name  = Settings::OPTION;
	$arvaseo_modes = array(
		'draft_only' => array( __( 'Draft only', 'arvaseo-connector' ), __( 'Content arrives as drafts. Nothing is published, scheduled or sent for review. Recommended.', 'arvaseo-connector' ) ),
		'editorial'  => array( __( 'Editorial review', 'arvaseo-connector' ), __( 'Drafts, or Pending Review so an editor approves before publishing.', 'arvaseo-connector' ) ),
		'scheduling' => array( __( 'Review and scheduling', 'arvaseo-connector' ), __( 'Also lets Arva SEO schedule posts for a future time.', 'arvaseo-connector' ) ),
		'full'       => array( __( 'Full publishing', 'arvaseo-connector' ), __( 'Also lets Arva SEO publish straight away.', 'arvaseo-connector' ) ),
	);
	$arvaseo_save  = static function ( string $label ): void {
		echo '<div class="arvaseo-savebar"><button type="submit" class="arvaseo-btn arvaseo-btn--dark">' . esc_html( $label ) . '</button></div>';
	};
	/**
	 * A setting drawn as a switch.
	 *
	 * @param string $key     Setting key.
	 * @param string $label   Label.
	 * @param string $hint    Explanation.
	 * @param string $confirm data-arvaseo-confirm-check key.
	 */
	$arvaseo_toggle = static function ( string $key, string $label, string $hint = '', string $confirm = '' ) use ( $arvaseo_s, $arvaseo_name ): void {
		printf(
			'<label class="arvaseo-toggle"><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s%4$s /><span class="arvaseo-switch" aria-hidden="true"></span><span><span class="arvaseo-label">%5$s</span>%6$s</span></label>',
			esc_attr( $arvaseo_name ),
			esc_attr( $key ),
			checked( ! empty( $arvaseo_s[ $key ] ), true, false ),
			'' !== $confirm ? ' data-arvaseo-confirm-check="' . esc_attr( $confirm ) . '"' : '',
			esc_html( $label ),
			'' !== $hint ? '<span class="arvaseo-hint" style="display: block">' . esc_html( $hint ) . '</span>' : ''
		);
	};
	?>
<div class="arvaseo-layout">
	<nav class="arvaseo-side" aria-label="<?php esc_attr_e( 'Settings sections', 'arvaseo-connector' ); ?>">
		<a href="#arvaseo-settings-publishing"><?php esc_html_e( 'Publishing', 'arvaseo-connector' ); ?></a>
		<a href="#arvaseo-settings-seo"><?php esc_html_e( 'SEO titles', 'arvaseo-connector' ); ?></a>
		<a href="#arvaseo-settings-images"><?php esc_html_e( 'Featured images', 'arvaseo-connector' ); ?></a>
		<a href="#arvaseo-settings-records"><?php esc_html_e( 'Checks and records', 'arvaseo-connector' ); ?></a>
		<a href="#arvaseo-settings-privacy"><?php esc_html_e( 'Data and privacy', 'arvaseo-connector' ); ?></a>
		<a href="#arvaseo-settings-connection"><?php esc_html_e( 'Connection', 'arvaseo-connector' ); ?></a>
	</nav>
	<div class="arvaseo-content">
		<?php // One form, so saving any section keeps the others as they are. ?>
		<form method="post" action="options.php" class="arvaseo-stack">
			<?php settings_fields( 'arvaseo_connector' ); ?>
			<section class="arvaseo-card" id="arvaseo-settings-publishing" aria-labelledby="arvaseo-h-pub">
				<h2 class="arvaseo-card-title" id="arvaseo-h-pub"><?php esc_html_e( 'Publishing', 'arvaseo-connector' ); ?></h2>
				<p class="arvaseo-card-sub"><?php esc_html_e( 'What happens to content sent from Arva SEO.', 'arvaseo-connector' ); ?></p>
				<div class="arvaseo-field">
					<fieldset>
						<legend class="arvaseo-label"><?php esc_html_e( 'Publishing mode', 'arvaseo-connector' ); ?></legend>
						<div class="arvaseo-radios">
							<?php foreach ( $arvaseo_modes as $arvaseo_key => list( $arvaseo_label, $arvaseo_desc ) ) : ?>
								<label class="arvaseo-radio"><input type="radio" name="<?php echo esc_attr( $arvaseo_name ); ?>[publishing_mode]" value="<?php echo esc_attr( $arvaseo_key ); ?>" <?php checked( $arvaseo_s['publishing_mode'], $arvaseo_key ); ?> <?php echo 'full' === $arvaseo_key ? 'data-arvaseo-confirm-check="confirmFull"' : ''; ?> /><div><b><?php echo esc_html( $arvaseo_label ); ?></b><span><?php echo esc_html( $arvaseo_desc ); ?></span></div></label>
							<?php endforeach; ?>
						</div>
					</fieldset>
					<p class="arvaseo-hint" style="margin-top: 10px"><?php esc_html_e( 'Arva SEO can never go beyond this. Requests above it are refused (arvaseo_status_not_allowed).', 'arvaseo-connector' ); ?></p>
					<div style="margin-top: 14px"><?php $arvaseo_toggle( 'downgrade_to_draft', __( 'Save a draft instead of refusing', 'arvaseo-connector' ), __( 'Off by default. When on, a request above this mode is saved as a draft, and Arva SEO is told what was asked for and what was done.', 'arvaseo-connector' ) ); ?></div>
				</div>
				<div class="arvaseo-field">
					<label class="arvaseo-label" for="arvaseo-author"><?php esc_html_e( 'Default author', 'arvaseo-connector' ); ?> <span class="arvaseo-req" aria-hidden="true">*</span></label>
					<p class="arvaseo-hint"><?php esc_html_e( 'Posts from Arva SEO are created as this user. They must be able to publish for Scheduling or Full publishing. Arva SEO never chooses a user ID.', 'arvaseo-connector' ); ?></p>
					<?php
					wp_dropdown_users(
						array(
							'name'              => $arvaseo_name . '[default_author_id]',
							'id'                => 'arvaseo-author',
							'selected'          => (int) $arvaseo_s['default_author_id'],
							'capability'        => array( 'edit_posts' ),
							'show_option_none'  => __( '— Choose an author —', 'arvaseo-connector' ),
							'option_none_value' => 0,
						)
					);
					?>
					<?php if ( $arvaseo_s['default_author_id'] <= 0 ) : ?>
						<div class="arvaseo-row" style="margin-top: 10px"><?php echo AdminController::badge( 'warn', __( 'Needed before drafts from Arva SEO can be saved', 'arvaseo-connector' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes. ?></div>
					<?php endif; ?>
				</div>
				<div class="arvaseo-field">
					<fieldset>
						<legend class="arvaseo-label"><?php esc_html_e( 'Content types', 'arvaseo-connector' ); ?></legend>
						<div class="arvaseo-checks">
							<?php foreach ( Settings::SUPPORTED_POST_TYPES as $arvaseo_type ) : ?>
								<label><input type="checkbox" name="<?php echo esc_attr( $arvaseo_name ); ?>[allowed_post_types][]" value="<?php echo esc_attr( $arvaseo_type ); ?>" <?php checked( in_array( $arvaseo_type, $arvaseo_s['allowed_post_types'], true ) ); ?> /> <span><?php echo esc_html( get_post_type_object( $arvaseo_type )->labels->name ?? $arvaseo_type ); ?></span></label>
							<?php endforeach; ?>
						</div>
					</fieldset>
				</div>
				<div class="arvaseo-field"><?php $arvaseo_toggle( 'overwrite_local_edits', __( 'Let newer content from Arva SEO replace posts edited here', 'arvaseo-connector' ), __( 'Off by default: an edited post is kept and Arva SEO is told there’s a conflict. When on, a revision is saved first. Only posts that came from Arva SEO are ever updated; nothing is deleted.', 'arvaseo-connector' ), 'confirmOverwrite' ); ?></div>
				<div class="arvaseo-field"><?php $arvaseo_toggle( 'create_missing_terms', __( 'Create categories and tags that don’t exist yet', 'arvaseo-connector' ), __( 'Off: only existing ones are used; others are skipped and reported.', 'arvaseo-connector' ) ); ?></div>
				<?php $arvaseo_save( __( 'Save settings', 'arvaseo-connector' ) ); ?>
			</section>

			<section class="arvaseo-card" id="arvaseo-settings-seo" aria-labelledby="arvaseo-h-seo">
				<h2 class="arvaseo-card-title" id="arvaseo-h-seo"><?php esc_html_e( 'SEO titles and descriptions', 'arvaseo-connector' ); ?></h2>
				<p class="arvaseo-card-sub"><?php esc_html_e( 'Written to your SEO plugin. Only its SEO title and meta description fields change.', 'arvaseo-connector' ); ?></p>
				<div class="arvaseo-field">
					<label class="arvaseo-label" for="arvaseo-seo-plugin"><?php esc_html_e( 'SEO plugin', 'arvaseo-connector' ); ?></label>
					<select id="arvaseo-seo-plugin" name="<?php echo esc_attr( $arvaseo_name ); ?>[seo_plugin]">
						<option value="auto" <?php selected( $arvaseo_s['seo_plugin'], 'auto' ); ?>><?php esc_html_e( 'Detect automatically', 'arvaseo-connector' ); ?></option>
						<option value="yoast" <?php selected( $arvaseo_s['seo_plugin'], 'yoast' ); ?>><?php esc_html_e( 'Yoast SEO', 'arvaseo-connector' ); ?></option>
						<option value="rankmath" <?php selected( $arvaseo_s['seo_plugin'], 'rankmath' ); ?>><?php esc_html_e( 'Rank Math', 'arvaseo-connector' ); ?></option>
					</select>
					<p class="arvaseo-hint" style="margin-top: 6px">
						<?php
						$arvaseo_seo = SeoPlugins::resolve();
						if ( isset( SeoPlugins::NAMES[ $arvaseo_seo ] ) ) {
							/* translators: %s: the SEO plugin's name, for example Yoast SEO. */
							printf( esc_html__( 'Writing to %s.', 'arvaseo-connector' ), esc_html( SeoPlugins::NAMES[ $arvaseo_seo ] ) );
						} elseif ( 'multiple' === $arvaseo_seo ) {
							esc_html_e( 'Both Yoast SEO and Rank Math are active: choose the one your site uses.', 'arvaseo-connector' );
						} else {
							esc_html_e( 'Neither Yoast SEO nor Rank Math is active (or the one chosen isn’t), so nothing is written.', 'arvaseo-connector' );
						}
						?>
					</p>
				</div>
				<div class="arvaseo-field"><?php $arvaseo_toggle( 'seo_drafts', __( 'New drafts', 'arvaseo-connector' ), __( 'Add the SEO title and meta description to content sent from Arva SEO.', 'arvaseo-connector' ) ); ?></div>
				<div class="arvaseo-field"><?php $arvaseo_toggle( 'seo_updates', __( 'Existing content', 'arvaseo-connector' ), __( 'Let Arva SEO update the SEO titles and meta descriptions of published posts, pages and products, and the homepage (not category, tag or other archive pages). Nothing else in the post changes. The previous title and description of each changed page are sent to Arva SEO, so every change can be undone from there.', 'arvaseo-connector' ), 'confirmSeoUpdates' ); ?></div>
				<?php $arvaseo_save( __( 'Save settings', 'arvaseo-connector' ) ); ?>
			</section>

			<section class="arvaseo-card" id="arvaseo-settings-images" aria-labelledby="arvaseo-h-img">
				<h2 class="arvaseo-card-title" id="arvaseo-h-img"><?php esc_html_e( 'Featured images', 'arvaseo-connector' ); ?></h2>
				<div class="arvaseo-field" style="margin-top: 8px">
					<?php $arvaseo_toggle( 'media_import', __( 'Download featured images sent with content', 'arvaseo-connector' ), __( 'HTTPS only; JPEG, PNG, GIF and WebP only, up to 8 MB.', 'arvaseo-connector' ) ); ?>
					<label class="arvaseo-label" for="arvaseo-hosts" style="margin-top: 16px"><?php esc_html_e( 'Approved image hosts', 'arvaseo-connector' ); ?></label>
					<p class="arvaseo-hint"><?php esc_html_e( 'One exact host per line, such as images.example.com. Images from any other host are skipped.', 'arvaseo-connector' ); ?></p>
					<textarea id="arvaseo-hosts" rows="3" name="<?php echo esc_attr( $arvaseo_name ); ?>[media_hosts]"><?php echo esc_textarea( implode( "\n", $arvaseo_s['media_hosts'] ) ); ?></textarea>
				</div>
				<?php $arvaseo_save( __( 'Save settings', 'arvaseo-connector' ) ); ?>
			</section>

			<section class="arvaseo-card" id="arvaseo-settings-records" aria-labelledby="arvaseo-h-rec">
				<h2 class="arvaseo-card-title" id="arvaseo-h-rec"><?php esc_html_e( 'Checks and records', 'arvaseo-connector' ); ?></h2>
				<div class="arvaseo-field" style="margin-top: 8px">
					<label class="arvaseo-label" for="arvaseo-health-frequency"><?php esc_html_e( 'Website Health checks', 'arvaseo-connector' ); ?></label>
					<select id="arvaseo-health-frequency" name="<?php echo esc_attr( $arvaseo_name ); ?>[health_frequency]">
						<option value="daily" <?php selected( $arvaseo_s['health_frequency'], 'daily' ); ?>><?php esc_html_e( 'Once a day', 'arvaseo-connector' ); ?></option>
						<option value="twicedaily" <?php selected( $arvaseo_s['health_frequency'], 'twicedaily' ); ?>><?php esc_html_e( 'Twice a day', 'arvaseo-connector' ); ?></option>
					</select>
				</div>
				<?php $arvaseo_toggle( 'security_weekly', __( 'Scan for security problems every week', 'arvaseo-connector' ), __( 'The scan runs on this server in small batches and only reports what it finds. You can always run one from Health.', 'arvaseo-connector' ) ); ?>
				<div class="arvaseo-field">
					<label class="arvaseo-label" for="arvaseo-log-days"><?php esc_html_e( 'Keep the activity log for', 'arvaseo-connector' ); ?></label>
					<select id="arvaseo-log-days" name="<?php echo esc_attr( $arvaseo_name ); ?>[log_retention_days]">
						<?php foreach ( array( 30, 90, 180, 365 ) as $arvaseo_days ) : ?>
							<option value="<?php echo esc_attr( (string) $arvaseo_days ); ?>" <?php selected( (int) $arvaseo_s['log_retention_days'], $arvaseo_days ); ?>>
								<?php
								/* translators: %d: number of days. */
								printf( esc_html__( '%d days', 'arvaseo-connector' ), (int) $arvaseo_days );
								?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="arvaseo-field"><?php $arvaseo_toggle( 'delete_data_on_uninstall', __( 'When the plugin is deleted, also delete its settings, logs and delivery records', 'arvaseo-connector' ), __( 'The connection is always removed and revoked on delete. Posts created from Arva SEO are never deleted.', 'arvaseo-connector' ) ); ?></div>
				<?php $arvaseo_save( __( 'Save settings', 'arvaseo-connector' ) ); ?>
			</section>
		</form>

		<section class="arvaseo-card" id="arvaseo-settings-privacy" aria-labelledby="arvaseo-h-priv">
			<h2 class="arvaseo-card-title" id="arvaseo-h-priv"><?php esc_html_e( 'Data and privacy', 'arvaseo-connector' ); ?></h2>
			<p class="arvaseo-card-sub"><?php esc_html_e( 'What leaves this website, and what support can see.', 'arvaseo-connector' ); ?></p>
			<div class="arvaseo-field">
				<div class="arvaseo-between" style="align-items: center">
					<div>
						<span class="arvaseo-label"><?php esc_html_e( 'Plugin and theme list', 'arvaseo-connector' ); ?></span>
						<p class="arvaseo-hint">
							<?php
							$arvaseo_inv = SyncState::get( 'inventory' );
							if ( PrivacyService::allows( 'inventory' ) ) {
								/* translators: %s: date and time. */
								printf( esc_html__( 'Shared with Arva SEO. Last sent %s.', 'arvaseo-connector' ), esc_html( AdminController::when( $arvaseo_inv['last_success_at'] ?? null ) ) );
							} else {
								esc_html_e( 'Not shared: nothing is sent.', 'arvaseo-connector' );
							}
							?>
						</p>
					</div>
					<div class="arvaseo-row">
						<?php
						if ( TokenStore::is_connected() && PrivacyService::allows( 'inventory' ) && current_user_can( Capabilities::SYNC_INVENTORY ) ) {
							AdminController::button( 'arvaseo_connector_sync_inventory', __( 'Send it now', 'arvaseo-connector' ) );
						}
						if ( current_user_can( Capabilities::SYNC_INVENTORY ) ) {
							printf( '<a class="arvaseo-btn arvaseo-btn--sm" href="%1$s">%2$s</a>', esc_url( admin_url( 'admin.php?page=arvaseo-inventory' ) ), esc_html__( 'See the list', 'arvaseo-connector' ) );
						}
						?>
					</div>
				</div>
			</div>
			<div class="arvaseo-field">
				<div class="arvaseo-between" style="align-items: center">
					<div>
						<span class="arvaseo-label"><?php esc_html_e( 'Diagnostics and data sharing', 'arvaseo-connector' ); ?></span>
						<p class="arvaseo-hint"><?php esc_html_e( 'Diagnostics to copy for support (versions, connection state and settings only: no keys, file paths, usernames, emails or content), every field that’s shared, and your choice to share it.', 'arvaseo-connector' ); ?></p>
					</div>
					<a class="arvaseo-btn arvaseo-btn--sm" href="<?php echo esc_url( admin_url( 'admin.php?page=arvaseo-diagnostics' ) ); ?>"><?php esc_html_e( 'Open', 'arvaseo-connector' ); ?></a>
				</div>
			</div>
		</section>

		<section class="arvaseo-card" id="arvaseo-settings-connection" aria-labelledby="arvaseo-h-conn">
			<h2 class="arvaseo-card-title" id="arvaseo-h-conn"><?php esc_html_e( 'Connection', 'arvaseo-connector' ); ?></h2>
			<div class="arvaseo-field">
				<?php if ( TokenStore::is_connected() ) : ?>
					<?php $arvaseo_connection = TokenStore::connection(); ?>
					<div class="arvaseo-between" style="align-items: center">
						<div>
							<span class="arvaseo-label">
								<?php
								/* translators: 1: website name in Arva SEO, 2: workspace name. */
								printf( esc_html__( '%1$s, in %2$s', 'arvaseo-connector' ), esc_html( (string) ( $arvaseo_connection['project']['name'] ?? '—' ) ), esc_html( (string) ( $arvaseo_connection['workspace']['name'] ?? '—' ) ) );
								?>
							</span>
							<p class="arvaseo-hint">
								<?php
								/* translators: %s: date and time. */
								printf( esc_html__( 'Connected %s. Access renews by itself.', 'arvaseo-connector' ), esc_html( AdminController::when( $arvaseo_connection['connected_at'] ?? null ) ) );
								?>
							</p>
						</div>
						<div class="arvaseo-row">
							<?php
							AdminController::button( 'arvaseo_connector_test', __( 'Test connection', 'arvaseo-connector' ) );
							AdminController::button( 'arvaseo_connector_disconnect', __( 'Disconnect', 'arvaseo-connector' ), 'arvaseo-btn arvaseo-btn--sm arvaseo-btn--danger', 'confirmDisconnect' );
							?>
						</div>
					</div>
				<?php else : ?>
					<div class="arvaseo-between" style="align-items: center">
						<p class="arvaseo-hint" style="margin: 0"><?php esc_html_e( 'Not connected to Arva SEO. The speed fixes work either way.', 'arvaseo-connector' ); ?></p>
						<a class="arvaseo-btn arvaseo-btn--sm arvaseo-btn--pri" href="<?php echo esc_url( admin_url( 'admin.php?page=arvaseo-connector#arvaseo-connect' ) ); ?>"><?php esc_html_e( 'Connect to Arva SEO', 'arvaseo-connector' ); ?></a>
					</div>
				<?php endif; ?>
			</div>
		</section>
	</div>
</div>
	<?php
} )();
