<?php
/**
 * Settings page.
 *
 * @package ArvaSEO\Connector
 */

use ArvaSEO\Connector\Content\SeoPlugins;
use ArvaSEO\Connector\Settings;

defined( 'ABSPATH' ) || exit;

// Scoped so the view's variables stay local.
( static function () {

	$arvaseo_s     = Settings::all();
	$arvaseo_name  = Settings::OPTION;
	$arvaseo_modes = array(
		'draft_only' => array( __( 'Draft only', 'arvaseo-connector' ), __( 'Content arrives as drafts. Nothing is published, scheduled or sent for review. (Default)', 'arvaseo-connector' ) ),
		'editorial'  => array( __( 'Editorial review', 'arvaseo-connector' ), __( 'Drafts, or Pending Review so an editor approves before publishing.', 'arvaseo-connector' ) ),
		'scheduling' => array( __( 'Editorial review and scheduling', 'arvaseo-connector' ), __( 'Also lets Arva SEO schedule posts for a future time.', 'arvaseo-connector' ) ),
		'full'       => array( __( 'Full publishing', 'arvaseo-connector' ), __( 'Also lets Arva SEO publish immediately.', 'arvaseo-connector' ) ),
	);
	?>
<form method="post" action="options.php" class="arvaseo-settings">
	<?php settings_fields( 'arvaseo_connector' ); ?>
	<h2><?php esc_html_e( 'Publishing', 'arvaseo-connector' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Publishing mode', 'arvaseo-connector' ); ?></th>
			<td><fieldset><legend class="screen-reader-text"><?php esc_html_e( 'Publishing mode', 'arvaseo-connector' ); ?></legend>
				<?php foreach ( $arvaseo_modes as $arvaseo_key => list( $arvaseo_label, $arvaseo_desc ) ) : ?>
					<p><label><input type="radio" name="<?php echo esc_attr( $arvaseo_name ); ?>[publishing_mode]" value="<?php echo esc_attr( $arvaseo_key ); ?>" <?php checked( $arvaseo_s['publishing_mode'], $arvaseo_key ); ?> <?php echo 'full' === $arvaseo_key ? 'data-arvaseo-confirm-check="confirmFull"' : ''; ?> /> <strong><?php echo esc_html( $arvaseo_label ); ?></strong></label><br /><span class="description"><?php echo esc_html( $arvaseo_desc ); ?></span></p>
				<?php endforeach; ?>
				<p class="description"><?php esc_html_e( 'Arva SEO can never go beyond this. Requests above it are refused (arvaseo_status_not_allowed).', 'arvaseo-connector' ); ?></p>
			</fieldset></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Requests above the mode', 'arvaseo-connector' ); ?></th>
			<td><label><input type="checkbox" name="<?php echo esc_attr( $arvaseo_name ); ?>[downgrade_to_draft]" value="1" <?php checked( $arvaseo_s['downgrade_to_draft'] ); ?> /> <?php esc_html_e( 'Downgrade disallowed requests to Draft instead of refusing them', 'arvaseo-connector' ); ?></label>
				<p class="description"><?php esc_html_e( 'Off by default. When on, Arva SEO is told what was requested and that a draft was created instead.', 'arvaseo-connector' ); ?></p></td>
		</tr>
		<tr>
			<th scope="row"><label for="arvaseo-author"><?php esc_html_e( 'Default author', 'arvaseo-connector' ); ?></label></th>
			<td>
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
				<p class="description"><?php esc_html_e( 'Posts from Arva SEO are created as this user. They must be able to publish for Scheduling or Full publishing. Arva SEO never chooses a user ID.', 'arvaseo-connector' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Content types', 'arvaseo-connector' ); ?></th>
			<td><fieldset><legend class="screen-reader-text"><?php esc_html_e( 'Content types', 'arvaseo-connector' ); ?></legend>
				<?php foreach ( Settings::SUPPORTED_POST_TYPES as $arvaseo_type ) : ?>
					<label><input type="checkbox" name="<?php echo esc_attr( $arvaseo_name ); ?>[allowed_post_types][]" value="<?php echo esc_attr( $arvaseo_type ); ?>" <?php checked( in_array( $arvaseo_type, $arvaseo_s['allowed_post_types'], true ) ); ?> /> <?php echo esc_html( get_post_type_object( $arvaseo_type )->labels->name ?? $arvaseo_type ); ?></label><br />
				<?php endforeach; ?>
			</fieldset></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Edits made in WordPress', 'arvaseo-connector' ); ?></th>
			<td><label><input type="checkbox" name="<?php echo esc_attr( $arvaseo_name ); ?>[overwrite_local_edits]" value="1" <?php checked( $arvaseo_s['overwrite_local_edits'] ); ?> data-arvaseo-confirm-check="confirmOverwrite" /> <?php esc_html_e( 'Let newer content from Arva SEO replace posts edited here', 'arvaseo-connector' ); ?></label>
				<p class="description"><?php esc_html_e( 'Off by default: an edited post is kept and Arva SEO is told there’s a conflict. When on, a revision is saved first. Only posts that came from Arva SEO are ever updated; nothing is deleted.', 'arvaseo-connector' ); ?></p></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Categories and tags', 'arvaseo-connector' ); ?></th>
			<td><label><input type="checkbox" name="<?php echo esc_attr( $arvaseo_name ); ?>[create_missing_terms]" value="1" <?php checked( $arvaseo_s['create_missing_terms'] ); ?> /> <?php esc_html_e( 'Create categories and tags that don’t exist yet', 'arvaseo-connector' ); ?></label>
				<p class="description"><?php esc_html_e( 'Off: only existing ones are used; others are skipped and reported.', 'arvaseo-connector' ); ?></p></td>
		</tr>
	</table>

	<h2><?php esc_html_e( 'SEO titles and descriptions', 'arvaseo-connector' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="arvaseo-seo-plugin"><?php esc_html_e( 'SEO plugin', 'arvaseo-connector' ); ?></label></th>
			<td><select id="arvaseo-seo-plugin" name="<?php echo esc_attr( $arvaseo_name ); ?>[seo_plugin]">
				<option value="auto" <?php selected( $arvaseo_s['seo_plugin'], 'auto' ); ?>><?php esc_html_e( 'Detect automatically', 'arvaseo-connector' ); ?></option>
				<option value="yoast" <?php selected( $arvaseo_s['seo_plugin'], 'yoast' ); ?>><?php esc_html_e( 'Yoast SEO', 'arvaseo-connector' ); ?></option>
				<option value="rankmath" <?php selected( $arvaseo_s['seo_plugin'], 'rankmath' ); ?>><?php esc_html_e( 'Rank Math', 'arvaseo-connector' ); ?></option>
			</select>
				<p class="description">
					<?php
					$arvaseo_seo = SeoPlugins::resolve();
					if ( isset( SeoPlugins::NAMES[ $arvaseo_seo ] ) ) {
						/* translators: %s: the SEO plugin's name, for example Yoast SEO. */
						printf( esc_html__( 'Writing to: %s. Only its SEO title and meta description fields are changed.', 'arvaseo-connector' ), esc_html( SeoPlugins::NAMES[ $arvaseo_seo ] ) );
					} elseif ( 'multiple' === $arvaseo_seo ) {
						esc_html_e( 'Both Yoast SEO and Rank Math are active: choose the one your site uses.', 'arvaseo-connector' );
					} else {
						esc_html_e( 'Neither Yoast SEO nor Rank Math is active (or the one chosen isn’t), so nothing is written.', 'arvaseo-connector' );
					}
					?>
				</p></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'New drafts', 'arvaseo-connector' ); ?></th>
			<td><label><input type="checkbox" name="<?php echo esc_attr( $arvaseo_name ); ?>[seo_drafts]" value="1" <?php checked( $arvaseo_s['seo_drafts'] ); ?> /> <?php esc_html_e( 'Add the SEO title and meta description to content sent from Arva SEO', 'arvaseo-connector' ); ?></label></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Existing content', 'arvaseo-connector' ); ?></th>
			<td><label><input type="checkbox" name="<?php echo esc_attr( $arvaseo_name ); ?>[seo_updates]" value="1" <?php checked( $arvaseo_s['seo_updates'] ); ?> data-arvaseo-confirm-check="confirmSeoUpdates" /> <?php esc_html_e( 'Let Arva SEO update the SEO titles and meta descriptions of published posts, pages and products', 'arvaseo-connector' ); ?></label>
				<p class="description"><?php esc_html_e( 'Off by default. When on, the titles and descriptions your team approves in Arva SEO are written to the SEO plugin above, for published content and the homepage (not category, tag or other archive pages). Nothing else in the post changes. The previous title and description of each changed page are sent to Arva SEO, so every change can be undone from there.', 'arvaseo-connector' ); ?></p></td>
		</tr>
	</table>

	<h2><?php esc_html_e( 'Featured images', 'arvaseo-connector' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Import', 'arvaseo-connector' ); ?></th>
			<td><label><input type="checkbox" name="<?php echo esc_attr( $arvaseo_name ); ?>[media_import]" value="1" <?php checked( $arvaseo_s['media_import'] ); ?> /> <?php esc_html_e( 'Download featured images sent with content', 'arvaseo-connector' ); ?></label></td>
		</tr>
		<tr>
			<th scope="row"><label for="arvaseo-hosts"><?php esc_html_e( 'Approved image hosts', 'arvaseo-connector' ); ?></label></th>
			<td><textarea id="arvaseo-hosts" class="large-text code" rows="3" name="<?php echo esc_attr( $arvaseo_name ); ?>[media_hosts]"><?php echo esc_textarea( implode( "\n", $arvaseo_s['media_hosts'] ) ); ?></textarea>
				<p class="description"><?php esc_html_e( 'One exact host per line (for example images.example.com). Images from any other host are skipped. HTTPS only; JPEG, PNG, GIF and WebP only, up to 8 MB.', 'arvaseo-connector' ); ?></p></td>
		</tr>
	</table>

	<h2><?php esc_html_e( 'Checks and records', 'arvaseo-connector' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Website Health checks', 'arvaseo-connector' ); ?></th>
			<td><select name="<?php echo esc_attr( $arvaseo_name ); ?>[health_frequency]" aria-label="<?php esc_attr_e( 'Website Health checks', 'arvaseo-connector' ); ?>">
				<option value="daily" <?php selected( $arvaseo_s['health_frequency'], 'daily' ); ?>><?php esc_html_e( 'Once a day', 'arvaseo-connector' ); ?></option>
				<option value="twicedaily" <?php selected( $arvaseo_s['health_frequency'], 'twicedaily' ); ?>><?php esc_html_e( 'Twice a day', 'arvaseo-connector' ); ?></option>
			</select></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Keep the activity log for', 'arvaseo-connector' ); ?></th>
			<td><select name="<?php echo esc_attr( $arvaseo_name ); ?>[log_retention_days]" aria-label="<?php esc_attr_e( 'Keep the activity log for', 'arvaseo-connector' ); ?>">
				<?php foreach ( array( 30, 90, 180, 365 ) as $arvaseo_days ) : ?>
					<option value="<?php echo esc_attr( (string) $arvaseo_days ); ?>" <?php selected( (int) $arvaseo_s['log_retention_days'], $arvaseo_days ); ?>>
						<?php
						/* translators: %d: number of days. */
						printf( esc_html__( '%d days', 'arvaseo-connector' ), (int) $arvaseo_days );
						?>
					</option>
				<?php endforeach; ?>
			</select></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'When the plugin is deleted', 'arvaseo-connector' ); ?></th>
			<td><label><input type="checkbox" name="<?php echo esc_attr( $arvaseo_name ); ?>[delete_data_on_uninstall]" value="1" <?php checked( $arvaseo_s['delete_data_on_uninstall'] ); ?> /> <?php esc_html_e( 'Also delete settings, logs and delivery records', 'arvaseo-connector' ); ?></label>
				<p class="description"><?php esc_html_e( 'Credentials are always removed and revoked on delete. Posts created from Arva SEO are never deleted.', 'arvaseo-connector' ); ?></p></td>
		</tr>
	</table>
	<?php submit_button(); ?>
</form>
	<?php
} )();
