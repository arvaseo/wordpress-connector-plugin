<?php
/**
 * Database counts and explicit WordPress-only maintenance.
 *
 * @package ArvaSEO\Connector
 */

use ArvaSEO\Connector\Maintenance\DatabaseCleanup;
use ArvaSEO\Connector\Install\Capabilities;

defined( 'ABSPATH' ) || exit;
if ( ! current_user_can( Capabilities::MANAGE ) || ! current_user_can( 'delete_others_posts' ) ) {
	return;
}
$arvaseo_db_counts = DatabaseCleanup::counts();
$arvaseo_db_state  = DatabaseCleanup::state();
$arvaseo_db_names  = array(
	'revisions'          => __( 'Old post and page revisions (newest five kept)', 'arvaseo-connector' ),
	'auto_drafts'        => __( 'Post auto-drafts older than seven days', 'arvaseo-connector' ),
	'trash_posts'        => __( 'Posts in the trash for more than 30 days', 'arvaseo-connector' ),
	'comments'           => __( 'Spam and trashed comments older than 30 days', 'arvaseo-connector' ),
	'transients'         => __( 'Expired transient pairs', 'arvaseo-connector' ),
	'orphan_postmeta'    => __( 'Metadata whose post no longer exists', 'arvaseo-connector' ),
	'orphan_commentmeta' => __( 'Metadata whose comment no longer exists', 'arvaseo-connector' ),
	'orphan_termmeta'    => __( 'Metadata whose term no longer exists', 'arvaseo-connector' ),
);
?>
<section class="arvaseo-card" id="arvaseo-database" aria-labelledby="arvaseo-database-title">
	<h2 id="arvaseo-database-title"><?php esc_html_e( 'Database', 'arvaseo-connector' ); ?></h2>
	<p><?php esc_html_e( 'Check counts first. Cleanup keeps the newest five revisions of each post and page. It does not delete current published content, pages, products, orders or media, and does not rebuild tables.', 'arvaseo-connector' ); ?></p>
	<?php if ( $arvaseo_db_counts['at'] > 0 ) : ?>
		<p><?php /* translators: %s: date of saved counts. */ printf( esc_html__( 'Counts checked: %s.', 'arvaseo-connector' ), esc_html( wp_date( 'M j, Y H:i T', $arvaseo_db_counts['at'] ) ) ); ?></p>
		<dl class="arvaseo-kv">
		<?php foreach ( $arvaseo_db_names as $arvaseo_db_key => $arvaseo_db_label ) : ?>
			<dt><?php echo esc_html( $arvaseo_db_label ); ?></dt><dd><?php echo esc_html( number_format_i18n( (int) ( $arvaseo_db_counts['items'][ $arvaseo_db_key ] ?? 0 ) ) ); ?></dd>
		<?php endforeach; ?>
		</dl>
	<?php endif; ?>
	<?php if ( $arvaseo_db_state['running'] ) : ?>
		<p role="status"><?php esc_html_e( 'Cleanup is queued or running in batches of up to 500 candidates. Scheduled tasks need WordPress cron to run. Refresh this page to check progress.', 'arvaseo-connector' ); ?></p>
	<?php endif; ?>
	<?php if ( $arvaseo_db_counts['error'] || $arvaseo_db_state['error'] ) : ?>
		<p role="alert"><?php esc_html_e( 'A database operation could not finish, or a selected record changed. Cleanup stopped. Refresh the counts and check with your host if it happens again.', 'arvaseo-connector' ); ?></p>
	<?php endif; ?>
	<?php if ( $arvaseo_db_state['removed'] ) : ?>
		<p><?php /* translators: %d: removed candidates. */ printf( esc_html__( 'Removed in the last run: %d candidates.', 'arvaseo-connector' ), (int) array_sum( $arvaseo_db_state['removed'] ) ); ?></p>
	<?php endif; ?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="arvaseo_connector_database" />
		<?php wp_nonce_field( 'arvaseo_connector_database' ); ?>
		<button type="submit" name="database_action" value="count" formnovalidate class="arvaseo-btn"><?php esc_html_e( 'Refresh counts', 'arvaseo-connector' ); ?></button>
		<p><strong><?php esc_html_e( 'This can’t be undone. Make a backup first if you’re unsure.', 'arvaseo-connector' ); ?></strong></p>
		<p><label><input type="checkbox" name="acknowledged" value="1" required /> <?php esc_html_e( 'I reviewed the counts and understand that cleanup permanently deletes the eligible data.', 'arvaseo-connector' ); ?></label></p>
		<button type="submit" name="database_action" value="start" class="arvaseo-btn" <?php disabled( $arvaseo_db_counts['at'] <= 0 || $arvaseo_db_counts['error'] || $arvaseo_db_state['running'] ); ?>><?php esc_html_e( 'Clean up', 'arvaseo-connector' ); ?></button>
		<?php if ( $arvaseo_db_state['running'] ) : ?>
			<button type="submit" name="database_action" value="cancel" formnovalidate class="arvaseo-btn"><?php esc_html_e( 'Cancel remaining work', 'arvaseo-connector' ); ?></button>
		<?php endif; ?>
		<p><label><input type="checkbox" name="weekly" value="1" <?php checked( $arvaseo_db_state['weekly'] ); ?> /> <?php esc_html_e( 'Clean up eligible data weekly', 'arvaseo-connector' ); ?></label></p>
		<button type="submit" name="database_action" value="weekly" formnovalidate class="arvaseo-btn"><?php esc_html_e( 'Save weekly choice', 'arvaseo-connector' ); ?></button>
	</form>
</section>
