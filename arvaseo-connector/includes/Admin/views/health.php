<?php
/**
 * Health (read-only).
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
		'needs_verification' => __( 'Needs checking', 'arvaseo-connector' ),
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
<div class="arvaseo-stack">
	<?php require __DIR__ . '/security.php'; ?>

	<section class="arvaseo-card" aria-labelledby="arvaseo-hs-heading">
		<div class="arvaseo-between">
			<div>
				<p class="arvaseo-eyebrow"><?php esc_html_e( 'Last check', 'arvaseo-connector' ); ?></p>
				<h2 class="arvaseo-h3" id="arvaseo-hs-heading">
					<?php
					$arvaseo_checked_at = $arvaseo_latest['generated_at'] ?? null;
					/* translators: %s: relative time such as "5 hours". */
					echo esc_html( null === $arvaseo_checked_at ? __( 'Not checked yet', 'arvaseo-connector' ) : sprintf( __( '%s ago', 'arvaseo-connector' ), human_time_diff( is_numeric( $arvaseo_checked_at ) ? (int) $arvaseo_checked_at : (int) strtotime( (string) $arvaseo_checked_at ) ) ) );
					?>
				</h2>
				<div class="arvaseo-row" style="margin-top: 6px">
					<span class="arvaseo-muted">
						<?php
						if ( ! empty( $arvaseo_assessment['rules_version'] ) ) {
							/* translators: %s: rules version. */
							printf( esc_html__( 'Assessed with rules %s', 'arvaseo-connector' ), esc_html( $arvaseo_assessment['rules_version'] ) );
						} else {
							esc_html_e( 'Assessed by Arva SEO', 'arvaseo-connector' );
						}
						?>
					</span>
					<?php echo AdminController::badge( $arvaseo_fresh_ui[ $arvaseo_freshness ][0] ?? 'neutral', $arvaseo_fresh_ui[ $arvaseo_freshness ][1] ?? $arvaseo_freshness ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes. ?>
					<?php if ( ! empty( $arvaseo_health['error']['code'] ) && 'failed' === ( $arvaseo_health['status'] ?? '' ) ) : ?>
						<?php echo AdminController::badge( 'bad', __( 'Last upload failed', 'arvaseo-connector' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes. ?> <code><?php echo esc_html( $arvaseo_health['error']['code'] ); ?></code>
					<?php endif; ?>
				</div>
			</div>
			<div class="arvaseo-row">
				<?php
				if ( current_user_can( Capabilities::SYNC_HEALTH ) ) {
					AdminController::button( 'arvaseo_connector_recheck', __( 'Check again now', 'arvaseo-connector' ), 'arvaseo-btn arvaseo-btn--sm arvaseo-btn--dark' );
				}
				if ( ! empty( $arvaseo_assessment['app_url'] ) ) {
					printf( '<a class="arvaseo-btn arvaseo-btn--sm" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s<span class="screen-reader-text"> %3$s</span></a>', esc_url( $arvaseo_assessment['app_url'] ), esc_html__( 'Open in Arva SEO', 'arvaseo-connector' ), esc_html__( '(opens in a new tab)', 'arvaseo-connector' ) );
				}
				?>
			</div>
		</div>
		<?php if ( null !== $arvaseo_latest ) : ?>
			<ul class="arvaseo-counts" aria-label="<?php esc_attr_e( 'Check results', 'arvaseo-connector' ); ?>">
				<?php foreach ( array( 'critical', 'recommended', 'good', 'unknown' ) as $arvaseo_key ) : ?>
					<li class="arvaseo-count"><b><?php echo esc_html( (string) (int) ( $arvaseo_latest['summary'][ $arvaseo_key ] ?? 0 ) ); ?></b><?php echo AdminController::badge( $arvaseo_status_ui[ $arvaseo_key ][0], $arvaseo_status_ui[ $arvaseo_key ][1] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes. ?></li>
				<?php endforeach; ?>
			</ul>
			<p class="arvaseo-hint" style="margin-top: 14px"><?php esc_html_e( '“Couldn’t check” never counts as passed, and there’s no overall score.', 'arvaseo-connector' ); ?></p>
		<?php endif; ?>
	</section>

	<?php if ( ! empty( $arvaseo_assessment['vulnerability'] ) ) : ?>
		<p class="arvaseo-note"><?php echo esc_html( (string) $arvaseo_assessment['vulnerability'] ); ?></p>
	<?php endif; ?>

	<?php if ( array() !== $arvaseo_changes ) : ?>
		<section class="arvaseo-card" aria-labelledby="arvaseo-changes-heading">
			<h2 class="arvaseo-h2" id="arvaseo-changes-heading"><?php esc_html_e( 'Changed since the previous check', 'arvaseo-connector' ); ?></h2>
			<ul class="arvaseo-bullets">
				<?php foreach ( $arvaseo_changes as $arvaseo_change ) : ?>
					<li><code><?php echo esc_html( $arvaseo_change['check_id'] ); ?></code>:
						<?php echo esc_html( $arvaseo_status_ui[ $arvaseo_change['from'] ][1] ?? __( 'not checked', 'arvaseo-connector' ) ); ?> →
						<?php echo esc_html( $arvaseo_status_ui[ $arvaseo_change['to'] ][1] ?? __( 'not checked', 'arvaseo-connector' ) ); ?></li>
				<?php endforeach; ?>
			</ul>
		</section>
	<?php endif; ?>

	<?php if ( ! TokenStore::is_connected() && null === $arvaseo_assessment ) : ?>
		<section class="arvaseo-card">
			<h2 class="arvaseo-h2"><?php esc_html_e( 'Findings and fix guidance', 'arvaseo-connector' ); ?></h2>
			<p class="arvaseo-hint"><?php esc_html_e( 'Connect to Arva SEO to get findings with evidence and step-by-step fix guidance. The checks below run here either way.', 'arvaseo-connector' ); ?></p>
		</section>
	<?php elseif ( empty( $arvaseo_assessment['findings'] ) ) : ?>
		<section class="arvaseo-card">
			<div class="arvaseo-empty" style="padding: 0">
				<span class="arvaseo-empty-ic" aria-hidden="true"><?php echo AdminController::icon( 'check', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></span>
				<div><h3><?php echo ! empty( $arvaseo_assessment['assessment_pending'] ) ? esc_html__( 'Being assessed', 'arvaseo-connector' ) : esc_html__( 'No open findings', 'arvaseo-connector' ); ?></h3>
					<p><?php echo ! empty( $arvaseo_assessment['assessment_pending'] ) ? esc_html__( 'Arva SEO is assessing the latest check. Refresh in a few minutes.', 'arvaseo-connector' ) : esc_html__( 'Nothing to fix from the latest check.', 'arvaseo-connector' ); ?></p></div>
			</div>
		</section>
	<?php else : ?>
		<?php if ( 'current' !== $arvaseo_freshness ) : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'These findings are not current. They describe the site as it was at the last assessment.', 'arvaseo-connector' ); ?></p></div>
		<?php endif; ?>
		<?php
		$arvaseo_grouped = array();
		foreach ( $arvaseo_assessment['findings'] as $arvaseo_finding ) {
			$arvaseo_grouped[ $arvaseo_finding['category'] ][] = $arvaseo_finding;
		}
		foreach ( $arvaseo_grouped as $arvaseo_category => $arvaseo_items ) :
			?>
			<div class="arvaseo-grp-title"><h2><?php echo esc_html( $arvaseo_categories[ $arvaseo_category ] ?? $arvaseo_category ); ?></h2>
				<?php
				/* translators: %d: number of findings. */
				echo AdminController::badge( 'neutral', sprintf( _n( '%d finding', '%d findings', count( $arvaseo_items ), 'arvaseo-connector' ), count( $arvaseo_items ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
				?>
			</div>
			<?php foreach ( $arvaseo_items as $arvaseo_finding ) : ?>
				<?php $arvaseo_serious = in_array( $arvaseo_finding['assessed_severity'], array( 'critical', 'high' ), true ); ?>
				<article class="arvaseo-finding">
					<div class="arvaseo-row">
						<?php
						/* translators: %s: severity. */
						echo AdminController::badge( $arvaseo_serious ? 'bad' : 'warn', sprintf( __( 'Severity: %s', 'arvaseo-connector' ), $arvaseo_finding['assessed_severity'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
						/* translators: %s: confidence. */
						echo AdminController::badge( 'neutral', sprintf( __( 'Confidence: %s', 'arvaseo-connector' ), $arvaseo_finding['confidence'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
						echo AdminController::badge( 'neutral', $arvaseo_lifecycle[ $arvaseo_finding['finding_status'] ] ?? $arvaseo_finding['finding_status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
						if ( 'current' !== $arvaseo_finding['freshness_status'] ) {
							echo AdminController::badge( 'warn', $arvaseo_fresh_ui[ $arvaseo_finding['freshness_status'] ][1] ?? $arvaseo_finding['freshness_status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
						}
						if ( $arvaseo_finding['edited'] ) {
							echo AdminController::badge( 'neutral', __( 'Wording edited in Arva SEO', 'arvaseo-connector' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
						}
						?>
					</div>
					<h3><?php echo esc_html( $arvaseo_finding['title'] ); ?></h3>
					<div class="arvaseo-two">
						<div class="arvaseo-box"><span class="arvaseo-box-lbl"><?php esc_html_e( 'What’s wrong', 'arvaseo-connector' ); ?></span><?php echo esc_html( $arvaseo_finding['what_is_wrong'] ); ?></div>
						<div class="arvaseo-box"><span class="arvaseo-box-lbl"><?php esc_html_e( 'What to fix', 'arvaseo-connector' ); ?></span><?php echo esc_html( $arvaseo_finding['what_to_fix'] ); ?></div>
					</div>
					<div class="arvaseo-row" style="gap: 6px 20px">
						<details class="arvaseo-more">
							<summary><?php esc_html_e( 'See the evidence', 'arvaseo-connector' ); ?></summary>
							<div class="arvaseo-more-in">
								<p><?php echo esc_html( $arvaseo_source[ $arvaseo_finding['source_type'] ] ?? '' ); ?>: <?php echo esc_html( $arvaseo_finding['source_reference'] ); ?></p>
								<ul class="arvaseo-bullets">
									<?php foreach ( $arvaseo_finding['evidence'] as $arvaseo_ev ) : ?>
										<li><code><?php echo esc_html( $arvaseo_ev['check_id'] . ( '' !== $arvaseo_ev['subject'] ? ' · ' . $arvaseo_ev['subject'] : '' ) ); ?></code>
											<?php echo esc_html( $arvaseo_ev['observed_value'] ); ?>
											<span class="arvaseo-hint">(<?php echo esc_html( $arvaseo_ev['evidence_source'] . ', ' . AdminController::when( $arvaseo_ev['observed_at'] ) ); ?>)</span></li>
									<?php endforeach; ?>
								</ul>
							</div>
						</details>
						<details class="arvaseo-more">
							<summary><?php esc_html_e( 'How to fix it, step by step', 'arvaseo-connector' ); ?></summary>
							<div class="arvaseo-more-in">
								<p><strong><?php esc_html_e( 'Why it matters:', 'arvaseo-connector' ); ?></strong> <?php echo esc_html( $arvaseo_finding['why_it_matters'] ); ?></p>
								<p><strong><?php esc_html_e( 'Who should do it:', 'arvaseo-connector' ); ?></strong> <?php echo esc_html( $arvaseo_party[ $arvaseo_finding['responsible_party'] ] ?? '—' ); ?>
									· <strong><?php esc_html_e( 'Risk:', 'arvaseo-connector' ); ?></strong> <?php echo esc_html( (string) $arvaseo_finding['risk_level'] ); ?>
									· <strong><?php esc_html_e( 'Backup first:', 'arvaseo-connector' ); ?></strong> <?php echo $arvaseo_finding['backup_required'] ? esc_html__( 'Yes', 'arvaseo-connector' ) : esc_html__( 'No', 'arvaseo-connector' ); ?></p>
								<?php if ( array() !== $arvaseo_finding['prerequisites'] ) : ?>
									<p><strong><?php esc_html_e( 'Before you start:', 'arvaseo-connector' ); ?></strong></p>
									<ul class="arvaseo-bullets">
										<?php foreach ( $arvaseo_finding['prerequisites'] as $arvaseo_line ) : ?>
											<li><?php echo esc_html( $arvaseo_line ); ?></li>
										<?php endforeach; ?>
									</ul>
								<?php endif; ?>
								<p><strong><?php esc_html_e( 'Steps:', 'arvaseo-connector' ); ?></strong></p>
								<ol>
									<?php foreach ( $arvaseo_finding['steps'] as $arvaseo_line ) : ?>
										<li><?php echo esc_html( $arvaseo_line ); ?></li>
									<?php endforeach; ?>
								</ol>
								<p><strong><?php esc_html_e( 'How to verify:', 'arvaseo-connector' ); ?></strong></p>
								<ul class="arvaseo-bullets">
									<?php foreach ( $arvaseo_finding['verification'] as $arvaseo_line ) : ?>
										<li><?php echo esc_html( $arvaseo_line ); ?></li>
									<?php endforeach; ?>
								</ul>
								<p><strong><?php esc_html_e( 'How to undo:', 'arvaseo-connector' ); ?></strong></p>
								<ul class="arvaseo-bullets">
									<?php foreach ( $arvaseo_finding['rollback'] as $arvaseo_line ) : ?>
										<li><?php echo esc_html( $arvaseo_line ); ?></li>
									<?php endforeach; ?>
								</ul>
								<?php if ( '' !== $arvaseo_finding['client_notes'] ) : ?>
									<p><strong><?php esc_html_e( 'Note from your SEO team:', 'arvaseo-connector' ); ?></strong> <?php echo esc_html( $arvaseo_finding['client_notes'] ); ?></p>
								<?php endif; ?>
							</div>
						</details>
					</div>
				</article>
			<?php endforeach; ?>
		<?php endforeach; ?>
	<?php endif; ?>

	<?php if ( null !== $arvaseo_latest ) : ?>
		<details class="arvaseo-card arvaseo-more" style="margin-top: 0">
			<summary>
				<?php
				/* translators: %d: number of checks. */
				echo esc_html( sprintf( _n( 'Show every check (%d)', 'Show every check (%d)', count( $arvaseo_latest['checks'] ), 'arvaseo-connector' ), count( $arvaseo_latest['checks'] ) ) );
				?>
			</summary>
			<div class="arvaseo-tablebox">
				<table class="arvaseo-table">
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
							<td><?php echo AdminController::badge( $arvaseo_status_ui[ $arvaseo_check['check_status'] ][0], $arvaseo_status_ui[ $arvaseo_check['check_status'] ][1] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes. ?></td>
							<td><code class="arvaseo-value"><?php echo esc_html( (string) wp_json_encode( $arvaseo_check['observed_value'] ) ); ?></code></td>
							<td><?php echo esc_html( $arvaseo_check['evidence_source'] ); ?></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</details>
	<?php endif; ?>
</div>
	<?php
} )();
