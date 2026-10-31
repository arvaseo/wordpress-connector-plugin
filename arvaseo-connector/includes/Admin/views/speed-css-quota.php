<?php
/**
 * One workspace allowance shared by both CSS optimizations.
 *
 * @package ArvaSEO\Connector
 */

use ArvaSEO\Connector\Auth\TokenStore;
use ArvaSEO\Connector\Speed\CssAllowance;

defined( 'ABSPATH' ) || exit;
if ( ! current_user_can( \ArvaSEO\Connector\Install\Capabilities::MANAGE ) ) {
	return;
}
$arvaseo_css_quota        = CssAllowance::snapshot();
$arvaseo_css_quota_notice = get_transient( 'arvaseo_connector_css_quota_notice_' . get_current_user_id() );
?>
<section class="arvaseo-card" id="arvaseo-css-quota" aria-labelledby="arvaseo-css-quota-title">
	<h2 id="arvaseo-css-quota-title"><?php esc_html_e( 'Critical CSS and Unused CSS quota', 'arvaseo-connector' ); ?></h2>
	<?php require __DIR__ . '/speed-css-status.php'; ?>
	<?php
	$arvaseo_css_page_notice   = get_transient( 'arvaseo_css_page_notice_' . get_current_user_id() );
	$arvaseo_css_page_messages = array(
		'updated'   => __( 'Matching CSS downloaded and saved for this page. Clear any external page cache before measuring.', 'arvaseo-connector' ),
		'started'   => __( 'A render was requested for this page only. Existing results were kept; check for results shortly.', 'arvaseo-connector' ),
		'check'     => __( 'Acknowledge the possible page-optimization charge before requesting a new render.', 'arvaseo-connector' ),
		'not_ready' => __( 'No finished matching result was returned. Check the page’s styles before requesting a new render.', 'arvaseo-connector' ),
		'wait'      => __( 'This page was recently requested. Wait five minutes and check for results before requesting it again.', 'arvaseo-connector' ),
		'waiting'   => __( 'This page still needs attention. Its saved status below explains the next check.', 'arvaseo-connector' ),
	);
	?>
	<?php if ( $arvaseo_css_page_notice ) : ?>
		<p role="status"><?php echo esc_html( $arvaseo_css_page_messages[ $arvaseo_css_page_notice ] ?? __( 'This page could not be recovered. Check the connection and saved status; existing results were kept.', 'arvaseo-connector' ) ); ?></p>
	<?php endif; ?>
	<p><?php esc_html_e( 'Both features share your workspace’s page optimizations allowance across its connected websites. One generated result supplies both critical and used CSS; using both features does not charge twice for that result.', 'arvaseo-connector' ); ?></p>
	<p class="arvaseo-hint"><?php esc_html_e( 'When both CSS features are on, used CSS can provide the first-screen styles without a separate Critical CSS block. A page needs a result matching its current styles; original styles remain available until then.', 'arvaseo-connector' ); ?></p>
	<?php if ( ! TokenStore::is_connected() ) : ?>
		<p><?php esc_html_e( 'Connect this site to Arva SEO to check your CSS quota.', 'arvaseo-connector' ); ?></p>
	<?php else : ?>
		<?php if ( null === $arvaseo_css_quota ) : ?>
			<p><?php esc_html_e( 'Quota not checked yet. Refresh to get your workspace’s current allowance.', 'arvaseo-connector' ); ?></p>
		<?php else : ?>
			<p><strong>
			<?php
			if ( null === $arvaseo_css_quota['limit'] ) {
				/* translators: %s: page optimizations used. */
				printf( esc_html__( '%s used this month. Arva SEO reports no fixed limit for this workspace.', 'arvaseo-connector' ), esc_html( number_format_i18n( $arvaseo_css_quota['used'] ) ) );
			} else {
				/* translators: 1: used, 2: total allowance, 3: remaining page optimizations. */
				printf( esc_html__( '%1$s of %2$s used this month · %3$s remaining', 'arvaseo-connector' ), esc_html( number_format_i18n( $arvaseo_css_quota['used'] ) ), esc_html( number_format_i18n( $arvaseo_css_quota['limit'] ) ), esc_html( number_format_i18n( $arvaseo_css_quota['remaining'] ) ) );
			}
			?>
			</strong></p>
			<p class="arvaseo-hint">
			<?php
			/* translators: 1: quota reset date, 2: balance check date, in the WordPress timezone. */
			printf( esc_html__( 'Reset reported by Arva SEO: %1$s. Last checked: %2$s (site timezone).', 'arvaseo-connector' ), esc_html( wp_date( 'M j, Y H:i T', (int) strtotime( $arvaseo_css_quota['resets_at'] ) ) ), esc_html( wp_date( 'M j, Y H:i T', $arvaseo_css_quota['at'] ) ) );
			?>
			</p>
			<?php if ( $arvaseo_css_quota['stale'] ) : ?>
				<p class="arvaseo-risk arvaseo-risk--check"><?php esc_html_e( 'This is a saved balance and may have changed. Refresh before starting more optimizations.', 'arvaseo-connector' ); ?></p>
			<?php elseif ( 0 === $arvaseo_css_quota['remaining'] ) : ?>
				<div class="notice notice-warning inline" role="status"><p><?php esc_html_e( 'Your shared CSS quota is exhausted. New optimizations wait for allowance; existing CSS settings and saved results are unchanged. Pages without a usable result keep their normal styles.', 'arvaseo-connector' ); ?></p></div>
			<?php endif; ?>
		<?php endif; ?>
		<?php if ( 'unavailable' === $arvaseo_css_quota_notice ) : ?>
			<p role="status"><?php esc_html_e( 'Could not refresh the quota. Any balance shown is from the last successful check. Try again shortly.', 'arvaseo-connector' ); ?></p>
		<?php elseif ( 'wait' === $arvaseo_css_quota_notice ) : ?>
			<p role="status"><?php esc_html_e( 'Please wait a few seconds before refreshing the quota again.', 'arvaseo-connector' ); ?></p>
		<?php elseif ( 'updated' === $arvaseo_css_quota_notice ) : ?>
			<p role="status"><?php esc_html_e( 'CSS quota refreshed.', 'arvaseo-connector' ); ?></p>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="arvaseo_connector_css_quota" />
			<?php wp_nonce_field( 'arvaseo_connector_css_quota' ); ?>
			<button type="submit" class="arvaseo-btn arvaseo-btn--sm"><?php esc_html_e( 'Refresh CSS quota', 'arvaseo-connector' ); ?></button>
		</form>
		<p class="arvaseo-hint"><?php esc_html_e( 'Refreshing only checks usage. It does not generate CSS or use page optimizations. Arva SEO checks the actual balance when a new optimization is requested.', 'arvaseo-connector' ); ?></p>
	<?php endif; ?>
	<?php foreach ( CssAllowance::paused_pages() as $arvaseo_css_pause ) : ?>
		<div class="notice notice-warning inline"><p>
			<?php esc_html_e( 'Arva SEO paused new CSS generation because this page repeatedly sent different stylesheet signatures:', 'arvaseo-connector' ); ?>
			<code><?php echo esc_html( $arvaseo_css_pause['url'] ); ?></code>
			<?php echo esc_html( $arvaseo_css_pause['file'] ); ?>
		</p><p><?php esc_html_e( 'This is a change-protection pause, not an active render. Older plugin versions record it as a daily-cap wait. Check the changing stylesheet or cache-busting query strings before requesting more renders. The reported filename is a diagnostic clue; a changed address does not prove changed CSS content.', 'arvaseo-connector' ); ?></p></div>
	<?php endforeach; ?>
	<?php $arvaseo_css_excluded = \ArvaSEO\Connector\Speed\CssOptimizer::excluded_samples(); ?>
	<?php if ( $arvaseo_css_excluded ) : ?>
		<details>
			<summary><?php esc_html_e( 'Excluded URLs — possible automated probes', 'arvaseo-connector' ); ?></summary>
			<p><?php esc_html_e( 'These addresses contain traversal-like paths or control characters. They are retained for review, excluded from CSS requests and retries, and do not trigger new optimization charges here. A recorded request does not prove a successful attack or a published page. Check hosting and firewall logs if you are concerned.', 'arvaseo-connector' ); ?></p>
			<ul>
			<?php foreach ( array_slice( $arvaseo_css_excluded, 0, 50 ) as $arvaseo_css_excluded_row ) : ?>
				<li style="overflow-wrap:anywhere"><code><?php echo esc_html( $arvaseo_css_excluded_row['url'] ); ?></code></li>
			<?php endforeach; ?>
			</ul>
		</details>
	<?php endif; ?>
	<?php $arvaseo_css_pending = \ArvaSEO\Connector\Speed\CssOptimizer::pending_details(); ?>
	<?php if ( $arvaseo_css_pending ) : ?>
		<details>
			<summary><?php esc_html_e( 'Pages waiting for optimized CSS', 'arvaseo-connector' ); ?></summary>
			<p><?php esc_html_e( 'These are local request states. A saved server result must match the page’s current styles before it can be used. Stylesheet changes, failed downloads or local storage problems can keep a page here after rendering finishes. Original styles remain available.', 'arvaseo-connector' ); ?></p>
			<ul>
				<?php foreach ( array_slice( $arvaseo_css_pending, 0, 50 ) as $arvaseo_css_job ) : ?>
					<li style="overflow-wrap:anywhere">
						<code><?php echo esc_html( $arvaseo_css_job['url'] ); ?></code>
						<p><?php esc_html_e( 'Stylesheet signature:', 'arvaseo-connector' ); ?> <code><?php echo esc_html( $arvaseo_css_job['signature'] ); ?></code></p>
						<p><?php echo esc_html( $arvaseo_css_job['reason'] ); ?></p>
						<?php if ( $arvaseo_css_job['at'] > 0 ) : ?>
							<p><?php echo esc_html( wp_date( 'M j, Y H:i T', $arvaseo_css_job['at'] ) ); ?></p>
						<?php endif; ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="arvaseo_connector_css_page" />
							<input type="hidden" name="signature" value="<?php echo esc_attr( $arvaseo_css_job['signature'] ); ?>" />
							<input type="hidden" name="request_id" value="<?php echo esc_attr( wp_generate_uuid4() ); ?>" />
							<?php wp_nonce_field( 'arvaseo_connector_css_page' ); ?>
							<button type="submit" class="arvaseo-btn arvaseo-btn--sm" name="page_action" value="check"><?php esc_html_e( 'Fetch matching result — no charge', 'arvaseo-connector' ); ?></button>
							<p><label><input type="checkbox" name="checked" value="1" /> <?php esc_html_e( 'I checked that this page’s styles are stable. Regenerating this page may use one page optimization.', 'arvaseo-connector' ); ?></label></p>
							<button type="submit" class="arvaseo-btn arvaseo-btn--sm" name="page_action" value="regenerate"><?php esc_html_e( 'Regenerate this page only', 'arvaseo-connector' ); ?></button>
						</form>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php if ( count( $arvaseo_css_pending ) > 50 ) : ?>
				<p><?php esc_html_e( 'Showing the oldest 50 outstanding entries.', 'arvaseo-connector' ); ?></p>
			<?php endif; ?>
		</details>
	<?php endif; ?>
</section>
