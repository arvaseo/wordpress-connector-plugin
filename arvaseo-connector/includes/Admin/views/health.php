<?php
/**
 * Website Health page (read-only).
 *
 * @package ArvaSEO\Connector
 */

use ArvaSEO\Connector\Admin\AdminController;
use ArvaSEO\Connector\Auth\TokenStore;
use ArvaSEO\Connector\Health\AssessmentService;
use ArvaSEO\Connector\Health\HealthSnapshotService;
use ArvaSEO\Connector\Install\Capabilities;
use ArvaSEO\Connector\Sync\SyncState;

defined( 'ABSPATH' ) || exit;

// Scoped so the view's variables stay local.
( static function () {

	$arvaseo_latest     = HealthSnapshotService::latest();
	$arvaseo_assessment = AssessmentService::get();
	$arvaseo_changes    = HealthSnapshotService::changes();
	$arvaseo_health     = SyncState::get( 'health' );
	$arvaseo_status_ui  = array(
		'critical'    => array( 'bad', __( 'Critical', 'arvaseo-connector' ) ),
		'recommended' => array( 'warn', __( 'Recommended', 'arvaseo-connector' ) ),
		'good'        => array( 'good', __( 'Passed', 'arvaseo-connector' ) ),
		'unknown'     => array( 'neutral', __( 'Couldn’t check', 'arvaseo-connector' ) ),
	);
	$arvaseo_fresh_ui   = array(
		'current'    => array( 'good', __( 'Current', 'arvaseo-connector' ) ),
		'stale'      => array( 'warn', __( 'Stale: may no longer describe the site', 'arvaseo-connector' ) ),
		'expired'    => array( 'bad', __( 'Expired', 'arvaseo-connector' ) ),
		'superseded' => array( 'warn', __( 'Superseded by a newer check (assessment pending)', 'arvaseo-connector' ) ),
		'none'       => array( 'neutral', __( 'No assessment yet', 'arvaseo-connector' ) ),
	);
	$arvaseo_lifecycle  = array(
		'open'               => __( 'Open', 'arvaseo-connector' ),
		'needs_verification' => __( 'Needs verification', 'arvaseo-connector' ),
		'in_progress'        => __( 'In progress', 'arvaseo-connector' ),
		'snoozed'            => __( 'Snoozed', 'arvaseo-connector' ),
		'dismissed'          => __( 'Dismissed', 'arvaseo-connector' ),
		'accepted_risk'      => __( 'Accepted risk', 'arvaseo-connector' ),
		'resolved'           => __( 'Resolved', 'arvaseo-connector' ),
	);
	$arvaseo_party      = array(
		'site_admin'         => __( 'Site administrator', 'arvaseo-connector' ),
		'developer'          => __( 'Developer', 'arvaseo-connector' ),
		'host'               => __( 'Hosting provider', 'arvaseo-connector' ),
		'arvaseo_specialist' => __( 'Your SEO specialist', 'arvaseo-connector' ),
	);
	$arvaseo_source     = array(
		'wordpress_fact'         => __( 'Measured by WordPress', 'arvaseo-connector' ),
		'arvaseo_rule'           => __( 'Assessed by an Arva SEO rule', 'arvaseo-connector' ),
		'arvaseo_remote_audit'   => __( 'Detected by an Arva SEO audit', 'arvaseo-connector' ),
		'authoritative_advisory' => __( 'From an advisory provider', 'arvaseo-connector' ),
	);
	$arvaseo_categories = array(
		'core'        => __( 'WordPress core', 'arvaseo-connector' ),
		'extensions'  => __( 'Plugins and themes', 'arvaseo-connector' ),
		'runtime'     => __( 'Runtime and hosting', 'arvaseo-connector' ),
		'security'    => __( 'Security and configuration', 'arvaseo-connector' ),
		'reliability' => __( 'Reliability', 'arvaseo-connector' ),
		'performance' => __( 'Performance', 'arvaseo-connector' ),
		'seo'         => __( 'SEO and indexability', 'arvaseo-connector' ),
	);
	$arvaseo_freshness  = $arvaseo_assessment['display_freshness'] ?? 'none';
	?>
<div class="arvaseo-grid">
	<section class="card arvaseo-card" aria-labelledby="arvaseo-hs-heading">
		<h2 id="arvaseo-hs-heading"><?php esc_html_e( 'Overview', 'arvaseo-connector' ); ?></h2>
		<table class="form-table arvaseo-facts" role="presentation">
			<tr><th scope="row"><?php esc_html_e( 'Last checked', 'arvaseo-connector' ); ?></th><td><?php echo esc_html( AdminController::when( $arvaseo_latest['generated_at'] ?? null ) ); ?></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Assessed by Arva SEO', 'arvaseo-connector' ); ?></th><td>
				<?php echo esc_html( AdminController::when( $arvaseo_assessment['assessed_at'] ?? null ) ); ?>
				<?php echo wp_kses_post( AdminController::badge( $arvaseo_fresh_ui[ $arvaseo_freshness ][0] ?? 'neutral', $arvaseo_fresh_ui[ $arvaseo_freshness ][1] ?? $arvaseo_freshness ) ); ?>
				<?php if ( ! empty( $arvaseo_assessment['rules_version'] ) ) : ?>
					<p class="description">
						<?php
						/* translators: %s: rules version. */
						printf( esc_html__( 'Rules version %s', 'arvaseo-connector' ), esc_html( $arvaseo_assessment['rules_version'] ) );
						?>
					</p>
				<?php endif; ?>
			</td></tr>
			<?php if ( ! empty( $arvaseo_health['error']['code'] ) && 'failed' === ( $arvaseo_health['status'] ?? '' ) ) : ?>
				<tr><th scope="row"><?php esc_html_e( 'Last upload', 'arvaseo-connector' ); ?></th><td><?php echo wp_kses_post( AdminController::badge( 'bad', __( 'Failed', 'arvaseo-connector' ) ) ); ?> <code><?php echo esc_html( $arvaseo_health['error']['code'] ); ?></code></td></tr>
			<?php endif; ?>
		</table>
		<?php if ( null !== $arvaseo_latest ) : ?>
			<ul class="arvaseo-counts" aria-label="<?php esc_attr_e( 'Check results', 'arvaseo-connector' ); ?>">
				<?php foreach ( array( 'critical', 'recommended', 'good', 'unknown' ) as $arvaseo_key ) : ?>
					<li class="arvaseo-count arvaseo-count--<?php echo esc_attr( $arvaseo_status_ui[ $arvaseo_key ][0] ); ?>"><span class="arvaseo-count-n"><?php echo esc_html( (string) (int) ( $arvaseo_latest['summary'][ $arvaseo_key ] ?? 0 ) ); ?></span> <?php echo esc_html( $arvaseo_status_ui[ $arvaseo_key ][1] ); ?></li>
				<?php endforeach; ?>
			</ul>
			<p class="description"><?php esc_html_e( '“Couldn’t check” is never counted as passed. There is no overall score.', 'arvaseo-connector' ); ?></p>
		<?php endif; ?>
		<div class="arvaseo-actions">
			<?php
			if ( current_user_can( Capabilities::SYNC_HEALTH ) ) {
				AdminController::button( 'arvaseo_connector_recheck', __( 'Recheck website', 'arvaseo-connector' ), 'button button-primary' );
			}
			if ( ! empty( $arvaseo_assessment['app_url'] ) ) {
				printf( '<a class="button" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s<span class="screen-reader-text"> %3$s</span></a>', esc_url( $arvaseo_assessment['app_url'] ), esc_html__( 'View in Arva SEO', 'arvaseo-connector' ), esc_html__( '(opens in a new tab)', 'arvaseo-connector' ) );
			}
			?>
		</div>
		<p class="description"><?php esc_html_e( 'This view is read-only. Snoozing, dismissing, accepting a risk or assigning a finding happens in Arva SEO. Nothing here changes your site.', 'arvaseo-connector' ); ?></p>
	</section>

	<?php if ( array() !== $arvaseo_changes ) : ?>
		<section class="card arvaseo-card" aria-labelledby="arvaseo-changes-heading">
			<h2 id="arvaseo-changes-heading"><?php esc_html_e( 'Changed since the previous check', 'arvaseo-connector' ); ?></h2>
			<ul class="arvaseo-list">
				<?php foreach ( $arvaseo_changes as $arvaseo_change ) : ?>
					<li><code><?php echo esc_html( $arvaseo_change['check_id'] ); ?></code>:
						<?php echo esc_html( $arvaseo_status_ui[ $arvaseo_change['from'] ][1] ?? __( 'not checked', 'arvaseo-connector' ) ); ?> →
						<?php echo esc_html( $arvaseo_status_ui[ $arvaseo_change['to'] ][1] ?? __( 'not checked', 'arvaseo-connector' ) ); ?></li>
				<?php endforeach; ?>
			</ul>
		</section>
	<?php endif; ?>
</div>

<h2><?php esc_html_e( 'Findings and fix guidance', 'arvaseo-connector' ); ?></h2>
	<?php if ( ! TokenStore::is_connected() && null === $arvaseo_assessment ) : ?>
	<p><?php esc_html_e( 'Connect to Arva SEO to get findings with evidence and step-by-step fix guidance. The checks below run locally either way.', 'arvaseo-connector' ); ?></p>
	<?php elseif ( empty( $arvaseo_assessment['findings'] ) ) : ?>
	<p><?php echo ! empty( $arvaseo_assessment['assessment_pending'] ) ? esc_html__( 'Arva SEO is assessing the latest check. Refresh in a few minutes.', 'arvaseo-connector' ) : esc_html__( 'No open findings.', 'arvaseo-connector' ); ?></p>
	<?php else : ?>
		<?php if ( 'current' !== $arvaseo_freshness ) : ?>
		<div class="notice notice-warning inline"><p><?php esc_html_e( 'These findings are not current. They describe the site as it was at the last assessment.', 'arvaseo-connector' ); ?></p></div>
	<?php endif; ?>
	<p class="description"><?php echo esc_html( (string) ( $arvaseo_assessment['vulnerability'] ?? '' ) ); ?></p>
		<?php
		$arvaseo_grouped = array();
		foreach ( $arvaseo_assessment['findings'] as $arvaseo_finding ) {
			$arvaseo_grouped[ $arvaseo_finding['category'] ][] = $arvaseo_finding;
		}
		foreach ( $arvaseo_grouped as $arvaseo_category => $arvaseo_items ) :
			?>
		<h3><?php echo esc_html( $arvaseo_categories[ $arvaseo_category ] ?? $arvaseo_category ); ?></h3>
			<?php foreach ( $arvaseo_items as $arvaseo_finding ) : ?>
			<article class="arvaseo-finding arvaseo-finding--<?php echo esc_attr( $arvaseo_finding['assessed_severity'] ); ?>">
				<h4><?php echo esc_html( $arvaseo_finding['title'] ); ?></h4>
				<p class="arvaseo-badges">
					<?php
					/* translators: %s: severity. */
					echo wp_kses_post( AdminController::badge( in_array( $arvaseo_finding['assessed_severity'], array( 'critical', 'high' ), true ) ? 'bad' : 'warn', sprintf( __( 'Severity: %s', 'arvaseo-connector' ), $arvaseo_finding['assessed_severity'] ) ) );
					/* translators: %s: confidence. */
					echo ' ' . wp_kses_post( AdminController::badge( 'neutral', sprintf( __( 'Confidence: %s', 'arvaseo-connector' ), $arvaseo_finding['confidence'] ) ) );
					echo ' ' . wp_kses_post( AdminController::badge( 'neutral', $arvaseo_lifecycle[ $arvaseo_finding['finding_status'] ] ?? $arvaseo_finding['finding_status'] ) );
					if ( 'current' !== $arvaseo_finding['freshness_status'] ) {
						echo ' ' . wp_kses_post( AdminController::badge( 'warn', $arvaseo_fresh_ui[ $arvaseo_finding['freshness_status'] ][1] ?? $arvaseo_finding['freshness_status'] ) );
					}
					if ( $arvaseo_finding['edited'] ) {
						echo ' ' . wp_kses_post( AdminController::badge( 'neutral', __( 'Wording edited in Arva SEO', 'arvaseo-connector' ) ) );
					}
					?>
				</p>
				<p><strong><?php esc_html_e( 'What’s wrong:', 'arvaseo-connector' ); ?></strong> <?php echo esc_html( $arvaseo_finding['what_is_wrong'] ); ?></p>
				<p><strong><?php esc_html_e( 'What to fix:', 'arvaseo-connector' ); ?></strong> <?php echo esc_html( $arvaseo_finding['what_to_fix'] ); ?></p>
				<details>
					<summary><?php esc_html_e( 'View evidence', 'arvaseo-connector' ); ?></summary>
					<p><?php echo esc_html( $arvaseo_source[ $arvaseo_finding['source_type'] ] ?? '' ); ?> — <?php echo esc_html( $arvaseo_finding['source_reference'] ); ?></p>
					<ul class="arvaseo-list">
						<?php foreach ( $arvaseo_finding['evidence'] as $arvaseo_ev ) : ?>
							<li><code><?php echo esc_html( $arvaseo_ev['check_id'] . ( '' !== $arvaseo_ev['subject'] ? ' · ' . $arvaseo_ev['subject'] : '' ) ); ?></code>
								<?php echo esc_html( $arvaseo_ev['observed_value'] ); ?>
								<span class="description">(<?php echo esc_html( $arvaseo_ev['evidence_source'] . ', ' . AdminController::when( $arvaseo_ev['observed_at'] ) ); ?>)</span></li>
						<?php endforeach; ?>
					</ul>
				</details>
				<details>
					<summary><?php esc_html_e( 'View fix guidance', 'arvaseo-connector' ); ?></summary>
					<p><strong><?php esc_html_e( 'Why it matters:', 'arvaseo-connector' ); ?></strong> <?php echo esc_html( $arvaseo_finding['why_it_matters'] ); ?></p>
					<p><strong><?php esc_html_e( 'Who should do it:', 'arvaseo-connector' ); ?></strong> <?php echo esc_html( $arvaseo_party[ $arvaseo_finding['responsible_party'] ] ?? '—' ); ?>
						· <strong><?php esc_html_e( 'Risk:', 'arvaseo-connector' ); ?></strong> <?php echo esc_html( (string) $arvaseo_finding['risk_level'] ); ?>
						· <strong><?php esc_html_e( 'Backup first:', 'arvaseo-connector' ); ?></strong> <?php echo $arvaseo_finding['backup_required'] ? esc_html__( 'Yes', 'arvaseo-connector' ) : esc_html__( 'No', 'arvaseo-connector' ); ?></p>
					<?php if ( array() !== $arvaseo_finding['prerequisites'] ) : ?>
						<p><strong><?php esc_html_e( 'Before you start:', 'arvaseo-connector' ); ?></strong></p>
						<ul class="arvaseo-list">
						<?php
						foreach ( $arvaseo_finding['prerequisites'] as $arvaseo_line ) :
							?>
							<li><?php echo esc_html( $arvaseo_line ); ?></li><?php endforeach; ?></ul>
					<?php endif; ?>
					<p><strong><?php esc_html_e( 'Steps:', 'arvaseo-connector' ); ?></strong></p>
					<ol>
					<?php
					foreach ( $arvaseo_finding['steps'] as $arvaseo_line ) :
						?>
						<li><?php echo esc_html( $arvaseo_line ); ?></li><?php endforeach; ?></ol>
					<p><strong><?php esc_html_e( 'How to verify:', 'arvaseo-connector' ); ?></strong></p>
					<ul class="arvaseo-list">
					<?php
					foreach ( $arvaseo_finding['verification'] as $arvaseo_line ) :
						?>
						<li><?php echo esc_html( $arvaseo_line ); ?></li><?php endforeach; ?></ul>
					<p><strong><?php esc_html_e( 'How to undo:', 'arvaseo-connector' ); ?></strong></p>
					<ul class="arvaseo-list">
					<?php
					foreach ( $arvaseo_finding['rollback'] as $arvaseo_line ) :
						?>
						<li><?php echo esc_html( $arvaseo_line ); ?></li><?php endforeach; ?></ul>
					<?php if ( '' !== $arvaseo_finding['client_notes'] ) : ?>
						<p><strong><?php esc_html_e( 'Note from your SEO team:', 'arvaseo-connector' ); ?></strong> <?php echo esc_html( $arvaseo_finding['client_notes'] ); ?></p>
					<?php endif; ?>
				</details>
			</article>
		<?php endforeach; ?>
		<?php endforeach; ?>
	<?php endif; ?>

	<?php if ( null !== $arvaseo_latest ) : ?>
	<h2><?php esc_html_e( 'All checks', 'arvaseo-connector' ); ?></h2>
	<table class="widefat striped">
		<caption class="screen-reader-text"><?php esc_html_e( 'Website Health checks and results', 'arvaseo-connector' ); ?></caption>
		<thead><tr>
			<th scope="col"><?php esc_html_e( 'Check', 'arvaseo-connector' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Category', 'arvaseo-connector' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Result', 'arvaseo-connector' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Measured value', 'arvaseo-connector' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Source', 'arvaseo-connector' ); ?></th>
		</tr></thead>
		<tbody>
			<?php foreach ( $arvaseo_latest['checks'] as $arvaseo_check ) : ?>
			<tr>
				<td><code><?php echo esc_html( $arvaseo_check['check_id'] ); ?></code></td>
				<td><?php echo esc_html( $arvaseo_categories[ $arvaseo_check['category'] ] ?? $arvaseo_check['category'] ); ?></td>
				<td><?php echo wp_kses_post( AdminController::badge( $arvaseo_status_ui[ $arvaseo_check['check_status'] ][0], $arvaseo_status_ui[ $arvaseo_check['check_status'] ][1] ) ); ?></td>
				<td><code class="arvaseo-value"><?php echo esc_html( (string) wp_json_encode( $arvaseo_check['observed_value'] ) ); ?></code></td>
				<td><?php echo esc_html( $arvaseo_check['evidence_source'] ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
		<?php
	endif;
} )();
