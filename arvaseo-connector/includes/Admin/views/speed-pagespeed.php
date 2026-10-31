<?php
/**
 * Explicit PageSpeed testing and saved results, without running tests while rendering.
 *
 * @package ArvaSEO\Connector
 */

use ArvaSEO\Connector\Speed\PageSpeed;
use ArvaSEO\Connector\Auth\TokenStore;

defined( 'ABSPATH' ) || exit;
if ( ! current_user_can( \ArvaSEO\Connector\Install\Capabilities::MANAGE ) ) {
	return;
}
$arvaseo_ps        = PageSpeed::snapshot();
$arvaseo_ps_notice = get_transient( 'arvaseo_pagespeed_notice_' . get_current_user_id() );
$arvaseo_ps_runs   = array_values( array_filter( (array) ( $arvaseo_ps['data'] ?? array() ), 'is_array' ) );
?>
<section class="arvaseo-card arvaseo-ps" id="arvaseo-pagespeed-tests" aria-labelledby="arvaseo-pagespeed-title">
	<header class="arvaseo-between arvaseo-ps-head">
		<div>
			<p class="arvaseo-eyebrow"><?php esc_html_e( 'Measure your progress', 'arvaseo-connector' ); ?></p>
			<h2 class="arvaseo-h2" id="arvaseo-pagespeed-title"><?php esc_html_e( 'Test PageSpeed', 'arvaseo-connector' ); ?></h2>
			<p class="arvaseo-muted"><?php esc_html_e( 'Check a public page on mobile, desktop or both.', 'arvaseo-connector' ); ?></p>
		</div>
		<?php if ( isset( $arvaseo_ps['allowance']['remaining'] ) ) : ?>
			<div class="arvaseo-ps-balance">
				<strong><?php /* translators: %s: remaining workspace PageSpeed tests. */ printf( esc_html__( '%s tests left', 'arvaseo-connector' ), esc_html( number_format_i18n( (int) $arvaseo_ps['allowance']['remaining'] ) ) ); ?></strong>
				<span class="arvaseo-hint"><?php esc_html_e( 'Last checked workspace balance', 'arvaseo-connector' ); ?></span>
			</div>
		<?php endif; ?>
	</header>
	<?php if ( ! TokenStore::is_connected() ) : ?>
		<p><?php esc_html_e( 'Connect this site to Arva SEO to run tests.', 'arvaseo-connector' ); ?></p>
	<?php else : ?>
		<?php if ( $arvaseo_ps_notice ) : ?>
			<p class="arvaseo-ps-notice" role="status">
			<?php
			$arvaseo_ps_messages = array(
				'started'      => __( 'Test requested. Check results shortly; queued tests are not finished scores.', 'arvaseo-connector' ),
				'updated'      => __( 'Results refreshed without starting a test.', 'arvaseo-connector' ),
				'check_first'  => __( 'The last request may have started a test. Refresh results before requesting another test.', 'arvaseo-connector' ),
				'no_allowance' => __( 'No PageSpeed allowance is available for this request. Refresh the balance or check the plan in Arva SEO.', 'arvaseo-connector' ),
				'wait'         => __( 'Please wait before making another request.', 'arvaseo-connector' ),
				'invalid'      => __( 'Choose a public page on this website, a device and one or three runs. URLs with query parameters are not accepted here.', 'arvaseo-connector' ),
			);
			echo esc_html( $arvaseo_ps_messages[ $arvaseo_ps_notice ] ?? __( 'Could not complete this request. Check your connection, allowance and running tests in Arva SEO. The server may need an update; saved results remain available.', 'arvaseo-connector' ) );
			?>
			</p>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="arvaseo-ps-form" data-arvaseo-pagespeed-form>
			<input type="hidden" name="action" value="arvaseo_connector_pagespeed" />
			<input type="hidden" name="request_id" value="<?php echo esc_attr( wp_generate_uuid4() ); ?>" />
			<?php wp_nonce_field( 'arvaseo_connector_pagespeed' ); ?>
			<div class="arvaseo-ps-url">
				<label class="arvaseo-label" for="arvaseo-ps-url"><?php esc_html_e( 'Public page URL', 'arvaseo-connector' ); ?></label>
				<input type="url" id="arvaseo-ps-url" name="test_url" value="<?php echo esc_url( home_url( '/' ) ); ?>" aria-describedby="arvaseo-ps-url-help" required />
				<p class="arvaseo-hint" id="arvaseo-ps-url-help"><?php esc_html_e( 'Use a page on this website, without query parameters.', 'arvaseo-connector' ); ?></p>
			</div>
			<fieldset class="arvaseo-ps-devices">
				<legend class="arvaseo-label"><?php esc_html_e( 'Devices to test', 'arvaseo-connector' ); ?></legend>
				<div class="arvaseo-ps-device-grid">
					<label class="arvaseo-ps-device"><input type="checkbox" name="devices[]" value="mobile" checked /><span><strong><?php esc_html_e( 'Mobile', 'arvaseo-connector' ); ?></strong><span><?php esc_html_e( 'Phone performance · 1 test', 'arvaseo-connector' ); ?></span></span></label>
					<label class="arvaseo-ps-device"><input type="checkbox" name="devices[]" value="desktop" /><span><strong><?php esc_html_e( 'Desktop', 'arvaseo-connector' ); ?></strong><span><?php esc_html_e( 'Computer performance · 1 test', 'arvaseo-connector' ); ?></span></span></label>
				</div>
			</fieldset>
			<div class="arvaseo-ps-comparison">
				<label class="arvaseo-label" for="arvaseo-ps-runs"><?php esc_html_e( 'Comparison mode', 'arvaseo-connector' ); ?></label>
				<select id="arvaseo-ps-runs" name="runs" aria-describedby="arvaseo-ps-runs-help"><option value="3"><?php esc_html_e( 'Three runs · recommended', 'arvaseo-connector' ); ?></option><option value="1"><?php esc_html_e( 'One run · quick check', 'arvaseo-connector' ); ?></option></select>
				<p class="arvaseo-hint" id="arvaseo-ps-runs-help"><?php esc_html_e( 'Three runs give a steadier comparison at the same test cost. Google may return only one run.', 'arvaseo-connector' ); ?></p>
			</div>
			<div class="arvaseo-ps-submit">
				<div><strong><?php esc_html_e( '1 workspace test per selected device', 'arvaseo-connector' ); ?></strong><p class="arvaseo-hint"><?php esc_html_e( 'Google loads your public page. Compare the same URL, device and cache state.', 'arvaseo-connector' ); ?></p></div>
				<button type="submit" name="test_action" value="start" class="arvaseo-btn arvaseo-btn--dark"><?php esc_html_e( 'Run PageSpeed test', 'arvaseo-connector' ); ?></button>
			</div>
		</form>
		<section class="arvaseo-ps-history" aria-labelledby="arvaseo-ps-history-title">
			<header class="arvaseo-between arvaseo-ps-history-head">
				<div><h3 id="arvaseo-ps-history-title"><?php esc_html_e( 'Recent results', 'arvaseo-connector' ); ?></h3><p class="arvaseo-hint"><?php esc_html_e( 'Saved measurements, not a live monitor.', 'arvaseo-connector' ); ?></p></div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="arvaseo_connector_pagespeed" />
					<?php wp_nonce_field( 'arvaseo_connector_pagespeed' ); ?>
					<button type="submit" name="test_action" value="check" class="arvaseo-btn arvaseo-btn--sm"><?php esc_html_e( 'Refresh results and allowance', 'arvaseo-connector' ); ?></button>
					<span class="arvaseo-hint"><?php esc_html_e( 'Uses no tests', 'arvaseo-connector' ); ?></span>
				</form>
			</header>
			<?php if ( $arvaseo_ps_runs ) : ?>
				<div class="arvaseo-tablebox arvaseo-ps-results" tabindex="0" role="region" aria-label="<?php esc_attr_e( 'Saved PageSpeed results. Scroll to see all columns and tests.', 'arvaseo-connector' ); ?>">
					<table class="arvaseo-table"><caption class="screen-reader-text"><?php esc_html_e( 'Recent PageSpeed tests, newest first', 'arvaseo-connector' ); ?></caption>
						<thead><tr><th scope="col"><?php esc_html_e( 'Device / page', 'arvaseo-connector' ); ?></th><th scope="col"><?php esc_html_e( 'Performance', 'arvaseo-connector' ); ?></th><th scope="col"><?php esc_html_e( 'LCP', 'arvaseo-connector' ); ?></th><th scope="col"><?php esc_html_e( 'Runs / range', 'arvaseo-connector' ); ?></th><th scope="col"><?php esc_html_e( 'Tested', 'arvaseo-connector' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( $arvaseo_ps_runs as $arvaseo_ps_run ) : ?>
							<?php
							$arvaseo_ps_complete = 'completed' === ( $arvaseo_ps_run['status'] ?? '' );
							$arvaseo_ps_device   = 'mobile' === ( $arvaseo_ps_run['device'] ?? '' ) ? __( 'Mobile', 'arvaseo-connector' ) : ( 'desktop' === ( $arvaseo_ps_run['device'] ?? '' ) ? __( 'Desktop', 'arvaseo-connector' ) : __( 'Unknown device', 'arvaseo-connector' ) );
							$arvaseo_ps_at       = ! empty( $arvaseo_ps_run['tested_at'] ) ? strtotime( (string) $arvaseo_ps_run['tested_at'] ) : false;
							$arvaseo_ps_statuses = array(
								'queued'    => __( 'Queued', 'arvaseo-connector' ),
								'running'   => __( 'Running', 'arvaseo-connector' ),
								'failed'    => __( 'Failed', 'arvaseo-connector' ),
								'completed' => __( 'Completed', 'arvaseo-connector' ),
							);
							?>
							<tr>
								<th scope="row"><strong><?php echo esc_html( $arvaseo_ps_device ); ?></strong><span class="arvaseo-ps-page"><?php echo esc_html( (string) ( $arvaseo_ps_run['url'] ?? '' ) ); ?></span></th>
								<td>
								<?php
								if ( $arvaseo_ps_complete && is_numeric( $arvaseo_ps_run['performance'] ?? null ) ) :
									?>
									<span class="arvaseo-ps-score"><?php echo esc_html( (string) $arvaseo_ps_run['performance'] ); ?><small>/100</small></span>
									<?php
else :
	?>
									<?php echo esc_html( $arvaseo_ps_statuses[ $arvaseo_ps_run['status'] ?? '' ] ?? __( 'Unknown', 'arvaseo-connector' ) ); ?><?php endif; ?></td>
								<td>
								<?php
								if ( $arvaseo_ps_complete && is_numeric( $arvaseo_ps_run['lcp_ms'] ?? null ) ) :
									?>
									<?php /* translators: %s: LCP duration in seconds. */ printf( esc_html__( '%s s', 'arvaseo-connector' ), esc_html( number_format_i18n( (float) $arvaseo_ps_run['lcp_ms'] / 1000, 2 ) ) ); ?>
									<?php
else :
	?>
									—<?php endif; ?></td>
								<td><?php echo $arvaseo_ps_complete && is_numeric( $arvaseo_ps_run['runs'] ?? null ) ? esc_html( (string) $arvaseo_ps_run['runs'] ) : '—'; ?>
								<?php
								if ( $arvaseo_ps_complete && isset( $arvaseo_ps_run['range'][0], $arvaseo_ps_run['range'][1] ) ) :
									?>
									<span class="arvaseo-hint arvaseo-ps-range"><?php /* translators: 1: lowest score, 2: highest score. */ printf( esc_html__( '%1$s–%2$s score', 'arvaseo-connector' ), esc_html( (string) $arvaseo_ps_run['range'][0] ), esc_html( (string) $arvaseo_ps_run['range'][1] ) ); ?></span><?php endif; ?></td>
								<td><?php echo false !== $arvaseo_ps_at ? esc_html( wp_date( 'M j, Y · g:i a', $arvaseo_ps_at ) ) : '—'; ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php else : ?>
				<p class="arvaseo-ps-empty"><?php esc_html_e( 'No saved results yet. Run a test above, or refresh to retrieve existing tests from Arva SEO.', 'arvaseo-connector' ); ?></p>
			<?php endif; ?>
			<?php if ( is_array( $arvaseo_ps ) ) : ?>
				<p class="arvaseo-hint arvaseo-ps-checked"><?php /* translators: %s: last successful result check. */ printf( esc_html__( 'Last checked %s · dates use your WordPress timezone.', 'arvaseo-connector' ), esc_html( wp_date( 'M j, Y · g:i a', (int) $arvaseo_ps['at'] ) ) ); ?></p>
			<?php endif; ?>
		</section>
	<?php endif; ?>
</section>
