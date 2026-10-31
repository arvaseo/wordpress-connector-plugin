<?php
/**
 * Saved CSS delivery and animation diagnostics. This view never requests a render.
 *
 * @package ArvaSEO\Connector
 */

defined( 'ABSPATH' ) || exit;
$arvaseo_css_delivery = \ArvaSEO\Connector\Speed\CssDelivery::diagnostics();
?>
<details>
	<summary><?php esc_html_e( 'How Critical CSS, Unused CSS and combining work together', 'arvaseo-connector' ); ?></summary>
	<p><?php esc_html_e( 'Arva SEO renders the original page at phone and computer sizes. Critical CSS contains first-screen styles; Unused CSS removal keeps styles needed across the page, plus protected interaction states. Ready results are matched to each original stylesheet’s content and media, then assembled in source order for compatible bundles. Minifying or combining alone does not require another render.', 'arvaseo-connector' ); ?></p>
	<p><?php esc_html_e( 'With the updated combining behavior approved, files without a safe matching result stay separate so they cannot withhold ready neighboring CSS. Some files intentionally remain separate to preserve media, cascade, integrity and document policies. The saved checks below show observed coverage; fewer files alone do not prove a faster page.', 'arvaseo-connector' ); ?></p>
</details>
<?php $arvaseo_cleanup_notice = \ArvaSEO\Connector\Speed\Housekeeping::notice(); ?>
<?php if ( null !== $arvaseo_cleanup_notice ) : ?>
	<p role="status"><?php echo esc_html( $arvaseo_cleanup_notice ); ?></p>
<?php endif; ?>
<?php if ( $arvaseo_css_delivery['checked_at'] > 0 ) : ?>
	<p class="arvaseo-hint"><?php /* translators: %s: time since an authenticated CSS API check. */ printf( esc_html__( 'Last checked with Arva SEO: %s ago.', 'arvaseo-connector' ), esc_html( human_time_diff( $arvaseo_css_delivery['checked_at'] ) ) ); ?></p>
<?php endif; ?>
<?php if ( $arvaseo_css_delivery['earlier'] > 0 ) : ?>
	<p role="status"><?php /* translators: %d: page types with earlier results retained. */ printf( esc_html__( 'Earlier CSS is kept while new results are worked out: %d page types. Only matching stylesheets can use it.', 'arvaseo-connector' ), (int) $arvaseo_css_delivery['earlier'] ); ?></p>
<?php endif; ?>
<?php if ( false === $arvaseo_css_delivery['notify_ok'] ) : ?>
	<p class="arvaseo-hint"><?php esc_html_e( 'The last readiness message could not reach this site. Scheduled checks still fetch matching results. Keep the Speed page open to check for results while your host investigates the connection.', 'arvaseo-connector' ); ?></p>
<?php endif; ?>
<?php if ( $arvaseo_css_delivery['waiting_since'] > 0 && $arvaseo_css_delivery['waiting_since'] < time() - 30 * MINUTE_IN_SECONDS ) : ?>
	<p class="arvaseo-hint"><?php esc_html_e( 'CSS has been waiting for more than 30 minutes. If cached visits are not reaching WordPress, ask your host to run WordPress cron every five minutes, or keep the Speed page open so it can check for results. The saved status below can also show a download or rendering problem.', 'arvaseo-connector' ); ?></p>
<?php endif; ?>
<?php $arvaseo_animation_reports = \ArvaSEO\Connector\Speed\CssAnimations::reports(); ?>
<?php if ( $arvaseo_animation_reports ) : ?>
	<details>
		<summary><?php esc_html_e( 'First-screen animation waits', 'arvaseo-connector' ); ?></summary>
		<p><?php esc_html_e( 'Saved renderer measurements for Elementor entrance animations. These timings can help explain a late first screen; they are not an LCP measurement. Your animation settings are unchanged.', 'arvaseo-connector' ); ?></p>
		<?php foreach ( $arvaseo_animation_reports as $arvaseo_animation_report ) : ?>
			<p><code><?php echo esc_html( $arvaseo_animation_report['url'] ); ?></code></p>
			<?php if ( ! $arvaseo_animation_report['available'] ) : ?>
				<p><?php esc_html_e( 'Animation timing could not be measured for both screen sizes.', 'arvaseo-connector' ); ?></p>
			<?php elseif ( ! $arvaseo_animation_report['first_screen'] ) : ?>
				<p><?php esc_html_e( 'No first-screen Elementor entrance animations were measured.', 'arvaseo-connector' ); ?></p>
			<?php endif; ?>
			<ul>
			<?php foreach ( $arvaseo_animation_report['first_screen'] as $arvaseo_animation_row ) : ?>
				<li><?php /* translators: 1: element identifier, 2: animation, 3: screen size, 4: delay, 5: duration. */ printf( esc_html__( '%1$s · %2$s · %3$s: %4$d ms delay + %5$d ms duration.', 'arvaseo-connector' ), esc_html( $arvaseo_animation_row['id'] ), esc_html( $arvaseo_animation_row['name'] ), esc_html( 'phone' === $arvaseo_animation_row['viewport'] ? __( 'Phone', 'arvaseo-connector' ) : __( 'Computer', 'arvaseo-connector' ) ), (int) $arvaseo_animation_row['delay'], (int) $arvaseo_animation_row['duration'] ); ?></li>
			<?php endforeach; ?>
			</ul>
		<?php endforeach; ?>
	</details>
<?php endif; ?>

<?php $arvaseo_css_coverage = \ArvaSEO\Connector\Speed\CssCoverage::reports(); ?>
<?php if ( $arvaseo_css_coverage ) : ?>
	<details>
		<summary><?php esc_html_e( 'Stylesheets kept unchanged', 'arvaseo-connector' ); ?></summary>
		<p><?php esc_html_e( 'Saved checks of managed stylesheets in completed pages. Pages may share a result; cached pages and unvisited URLs can differ.', 'arvaseo-connector' ); ?></p>
		<?php
		$arvaseo_kept_reasons = array(
			'awaiting_approval'         => __( 'The retained visitor version does not adopt this bundle or uppercase identifier yet.', 'arvaseo-connector' ),
			'incomplete_bundle'         => __( 'At least one bundle member has no matching result, is unsupported, or cannot safely be assembled.', 'arvaseo-connector' ),
			'result_pending_or_changed' => __( 'No matching source result was available, or the printed file or its media changed.', 'arvaseo-connector' ),
		);
		?>
		<?php foreach ( $arvaseo_css_coverage as $arvaseo_coverage_row ) : ?>
			<p><code><?php echo esc_html( $arvaseo_coverage_row['url'] ); ?></code> · <?php echo esc_html( wp_date( 'M j, Y H:i T', $arvaseo_coverage_row['at'] ) ); ?></p>
			<p><?php /* translators: 1: replaced managed stylesheets, 2: original stylesheets kept. */ printf( esc_html__( '%1$d matched; %2$d kept unchanged.', 'arvaseo-connector' ), (int) $arvaseo_coverage_row['matched'], (int) $arvaseo_coverage_row['kept'] ); ?></p>
			<ul>
			<?php foreach ( $arvaseo_coverage_row['examples'] as $arvaseo_kept_sheet ) : ?>
				<li><code><?php echo esc_html( $arvaseo_kept_sheet['id'] ); ?></code>: <?php echo esc_html( $arvaseo_kept_reasons[ $arvaseo_kept_sheet['why'] ] ?? '' ); ?></li>
			<?php endforeach; ?>
			</ul>
		<?php endforeach; ?>
	</details>
<?php endif; ?>
