<?php
/**
 * Run and inspect PageSpeed inside WordPress.
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
?>
<section class="arvaseo-card" id="arvaseo-pagespeed-tests" aria-labelledby="arvaseo-pagespeed-title">
	<h2 id="arvaseo-pagespeed-title"><?php esc_html_e( 'Test PageSpeed', 'arvaseo-connector' ); ?></h2>
	<p><?php esc_html_e( 'Google loads your public page. Each selected device uses one workspace PageSpeed test. Three runs give a steadier comparison and still use one test per device; Google may limit a test to one run. Compare the same URL, device and cache state.', 'arvaseo-connector' ); ?></p>
	<?php if ( ! TokenStore::is_connected() ) : ?>
		<p><?php esc_html_e( 'Connect this site to Arva SEO to run tests.', 'arvaseo-connector' ); ?></p>
	<?php else : ?>
		<?php if ( $arvaseo_ps_notice ) : ?>
			<p role="status">
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
		<?php if ( isset( $arvaseo_ps['allowance']['remaining'] ) ) : ?>
			<p><?php /* translators: %s: remaining PageSpeed tests. */ printf( esc_html__( 'PageSpeed tests remaining: %s (last checked balance).', 'arvaseo-connector' ), esc_html( number_format_i18n( (int) $arvaseo_ps['allowance']['remaining'] ) ) ); ?></p>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="arvaseo_connector_pagespeed" />
			<input type="hidden" name="request_id" value="<?php echo esc_attr( wp_generate_uuid4() ); ?>" />
			<?php wp_nonce_field( 'arvaseo_connector_pagespeed' ); ?>
			<p><label><?php esc_html_e( 'Public page URL', 'arvaseo-connector' ); ?><br /><input type="url" name="test_url" value="<?php echo esc_url( home_url( '/' ) ); ?>" style="width:100%;max-width:600px" /></label></p>
			<fieldset><legend><?php esc_html_e( 'Devices', 'arvaseo-connector' ); ?></legend>
				<label><input type="checkbox" name="devices[]" value="mobile" checked /> <?php esc_html_e( 'Mobile — one test', 'arvaseo-connector' ); ?></label>
				<label><input type="checkbox" name="devices[]" value="desktop" /> <?php esc_html_e( 'Desktop — one test', 'arvaseo-connector' ); ?></label>
			</fieldset>
			<p><label><?php esc_html_e( 'Runs', 'arvaseo-connector' ); ?> <select name="runs"><option value="3"><?php esc_html_e( 'Three — steadier comparison', 'arvaseo-connector' ); ?></option><option value="1"><?php esc_html_e( 'One — quick check', 'arvaseo-connector' ); ?></option></select></label></p>
			<button type="submit" name="test_action" value="start" class="arvaseo-btn"><?php esc_html_e( 'Run PageSpeed test', 'arvaseo-connector' ); ?></button>
			<button type="submit" name="test_action" value="check" class="arvaseo-btn"><?php esc_html_e( 'Refresh results and allowance', 'arvaseo-connector' ); ?></button>
		</form>
		<?php if ( is_array( $arvaseo_ps ) ) : ?>
			<p><?php /* translators: %s: check date. */ printf( esc_html__( 'Last checked: %s. Scores are saved measurements, not a live monitor.', 'arvaseo-connector' ), esc_html( wp_date( 'M j, Y H:i T', (int) $arvaseo_ps['at'] ) ) ); ?></p>
			<ul>
			<?php foreach ( (array) $arvaseo_ps['data'] as $arvaseo_ps_run ) : ?>
				<?php
				if ( ! is_array( $arvaseo_ps_run ) ) {
					continue; }
				?>
				<li style="overflow-wrap:anywhere"><strong><?php echo esc_html( (string) ( $arvaseo_ps_run['device'] ?? '' ) ); ?></strong> — <?php echo esc_html( (string) ( $arvaseo_ps_run['url'] ?? '' ) ); ?>
				<p><?php echo esc_html( (string) ( $arvaseo_ps_run['status'] ?? 'unknown' ) ); ?>
				<?php if ( 'completed' === ( $arvaseo_ps_run['status'] ?? '' ) ) : ?>
					<?php /* translators: 1: score, 2: LCP milliseconds, 3: test date. */ printf( esc_html__( ' · Performance %1$s/100 · LCP %2$s ms · %3$s', 'arvaseo-connector' ), esc_html( (string) ( $arvaseo_ps_run['performance'] ?? '—' ) ), esc_html( (string) ( $arvaseo_ps_run['lcp_ms'] ?? '—' ) ), esc_html( (string) ( $arvaseo_ps_run['tested_at'] ?? '' ) ) ); ?>
				<?php endif; ?></p>
				<?php if ( 'completed' === ( $arvaseo_ps_run['status'] ?? '' ) && is_numeric( $arvaseo_ps_run['runs'] ?? null ) ) : ?>
					<p><?php /* translators: %s: completed Google runs. */ printf( esc_html__( 'Completed runs: %s.', 'arvaseo-connector' ), esc_html( (string) $arvaseo_ps_run['runs'] ) ); ?>
					<?php if ( isset( $arvaseo_ps_run['range'][0], $arvaseo_ps_run['range'][1] ) ) : ?>
						<?php /* translators: 1: lowest score, 2: highest score. */ printf( esc_html__( 'Score range: %1$s–%2$s.', 'arvaseo-connector' ), esc_html( (string) $arvaseo_ps_run['range'][0] ), esc_html( (string) $arvaseo_ps_run['range'][1] ) ); ?>
					<?php endif; ?></p>
				<?php endif; ?></li>
			<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	<?php endif; ?>
</section>
