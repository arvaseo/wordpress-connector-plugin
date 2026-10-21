<?php
/**
 * Health → Security: the latest security scan, run on this server.
 *
 * @package ArvaSEO\Connector
 */

use ArvaSEO\Connector\Admin\AdminController;
use ArvaSEO\Connector\Install\Capabilities;
use ArvaSEO\Connector\Security\Findings;
use ArvaSEO\Connector\Security\Scanner;

defined( 'ABSPATH' ) || exit;

// Scoped so the view's variables stay local.
( static function () {

	$arvaseo_results  = Findings::latest();
	$arvaseo_progress = Scanner::progress();
	$arvaseo_running  = Scanner::running();
	$arvaseo_open     = Findings::open();
	$arvaseo_hidden   = Findings::hidden();
	$arvaseo_counts   = Findings::counts();
	$arvaseo_manage   = current_user_can( Capabilities::MANAGE );
	$arvaseo_coverage = (array) ( $arvaseo_results['coverage'] ?? array() );
	$arvaseo_severity = array(
		'critical' => array( 'bad', __( 'Critical', 'arvaseo-connector' ) ),
		'high'     => array( 'bad', __( 'High', 'arvaseo-connector' ) ),
		'medium'   => array( 'warn', __( 'Medium', 'arvaseo-connector' ) ),
		'low'      => array( 'neutral', __( 'Low', 'arvaseo-connector' ) ),
	);
	$arvaseo_form     = static function ( string $key, bool $show, string $label ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="arvaseo-inline-form">';
		echo '<input type="hidden" name="action" value="arvaseo_connector_security_ignore" />';
		echo '<input type="hidden" name="finding" value="' . esc_attr( $key ) . '" />';
		if ( $show ) {
			echo '<input type="hidden" name="show" value="1" />';
		}
		wp_nonce_field( 'arvaseo_connector_security_ignore' );
		echo '<button type="submit" class="arvaseo-btn arvaseo-btn--sm">' . esc_html( $label ) . '</button>';
		echo '</form>';
	};
	?>
	<section class="arvaseo-card" id="arvaseo-security" aria-labelledby="arvaseo-sec-heading">
		<div class="arvaseo-between">
			<div>
				<p class="arvaseo-eyebrow"><?php esc_html_e( 'Security scan', 'arvaseo-connector' ); ?></p>
				<h2 class="arvaseo-h3" id="arvaseo-sec-heading">
					<?php
					if ( $arvaseo_running ) {
						esc_html_e( 'Scanning now', 'arvaseo-connector' );
					} elseif ( null === $arvaseo_results ) {
						esc_html_e( 'Not scanned yet', 'arvaseo-connector' );
					} else {
						/* translators: %s: relative time such as "5 hours". */
						echo esc_html( sprintf( __( 'Scanned %s ago', 'arvaseo-connector' ), human_time_diff( (int) strtotime( (string) $arvaseo_results['scanned_at'] ) ) ) );
					}
					?>
				</h2>
				<?php if ( $arvaseo_running ) : ?>
					<p class="arvaseo-muted" style="margin-top: 6px" data-arvaseo-security-running aria-live="polite">
						<?php
						/* translators: 1: what the scan is checking, 2: number of files. */
						echo esc_html( sprintf( __( '%1$s · %2$s files checked so far', 'arvaseo-connector' ), Scanner::step_label( (string) ( $arvaseo_progress['step'] ?? '' ) ), number_format_i18n( (int) ( $arvaseo_progress['files'] ?? 0 ) ) ) );
						?>
					</p>
				<?php elseif ( null !== $arvaseo_results ) : ?>
					<p class="arvaseo-muted" style="margin-top: 6px">
						<?php
						/* translators: 1: number of files, 2: duration such as "3 mins". */
						echo esc_html( sprintf( __( '%1$s files checked in %2$s', 'arvaseo-connector' ), number_format_i18n( (int) $arvaseo_results['files'] ), human_time_diff( 0, max( 1, (int) $arvaseo_results['seconds'] ) ) ) );
						?>
					</p>
				<?php endif; ?>
			</div>
			<?php if ( $arvaseo_manage ) : ?>
				<div class="arvaseo-row">
					<?php
					if ( $arvaseo_running ) {
						AdminController::button( 'arvaseo_connector_security_stop', __( 'Stop', 'arvaseo-connector' ) );
					} else {
						AdminController::button( 'arvaseo_connector_security_scan', null === $arvaseo_results ? __( 'Scan now', 'arvaseo-connector' ) : __( 'Scan again', 'arvaseo-connector' ), 'arvaseo-btn arvaseo-btn--sm arvaseo-btn--dark' );
					}
					?>
				</div>
			<?php endif; ?>
		</div>

		<?php if ( null === $arvaseo_results && ! $arvaseo_running ) : ?>
			<p class="arvaseo-hint" style="margin-top: 14px"><?php esc_html_e( 'The scan compares WordPress and your WordPress.org plugins with their official copies, looks for code where it shouldn’t be and for patterns hackers use, and checks for files anyone can download. It runs on this server and only reports what it finds.', 'arvaseo-connector' ); ?></p>
		<?php elseif ( null !== $arvaseo_results ) : ?>
			<ul class="arvaseo-counts" aria-label="<?php esc_attr_e( 'Security findings by severity', 'arvaseo-connector' ); ?>">
				<?php foreach ( $arvaseo_severity as $arvaseo_key => $arvaseo_ui ) : ?>
					<li class="arvaseo-count"><b><?php echo esc_html( (string) $arvaseo_counts[ $arvaseo_key ] ); ?></b><?php echo AdminController::badge( $arvaseo_ui[0], $arvaseo_ui[1] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes. ?></li>
				<?php endforeach; ?>
			</ul>
			<p class="arvaseo-hint" style="margin-top: 14px">
				<?php
				$arvaseo_lines = array();
				if ( null !== ( $arvaseo_coverage['core'] ?? null ) ) {
					/* translators: %s: WordPress version. */
					$arvaseo_lines[] = sprintf( __( 'WordPress %s matched against WordPress.org’s checksums.', 'arvaseo-connector' ), (string) $arvaseo_coverage['core'] );
				} else {
					$arvaseo_lines[] = __( 'WordPress.org’s checksums couldn’t be fetched, so WordPress’s own files were checked by pattern only.', 'arvaseo-connector' );
				}
				$arvaseo_lines[] = sprintf(
					/* translators: 1: plugins checked against WordPress.org, 2: plugins in a folder. */
					_n( '%1$d of %2$d plugin matched against its official copy; the others and your themes were checked by pattern.', '%1$d of %2$d plugins matched against their official copies; the others and your themes were checked by pattern.', (int) ( $arvaseo_coverage['plugins'] ?? 0 ), 'arvaseo-connector' ),
					(int) ( $arvaseo_coverage['plugins_checked'] ?? 0 ),
					(int) ( $arvaseo_coverage['plugins'] ?? 0 )
				);
				if ( ! empty( $arvaseo_results['partial'] ) ) {
					$arvaseo_lines[] = __( 'The site has more files than one scan covers here, so the scan stopped after the first 150,000.', 'arvaseo-connector' );
				}
				if ( empty( $arvaseo_coverage['exposure'] ) ) {
					$arvaseo_lines[] = __( 'The site couldn’t reach itself, so some exposed-file checks were skipped (Site Health’s loopback check says more).', 'arvaseo-connector' );
				}
				echo esc_html( implode( ' ', $arvaseo_lines ) );
				?>
			</p>
			<?php if ( array() === $arvaseo_open ) : ?>
				<div class="arvaseo-empty" style="padding: 16px 0 0">
					<span class="arvaseo-empty-ic" aria-hidden="true"><?php echo AdminController::icon( 'check', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></span>
					<div><h3><?php esc_html_e( 'No known problems found in the last scan', 'arvaseo-connector' ); ?></h3>
						<p><?php esc_html_e( 'A scan finds known signs of trouble; it can’t prove a site is clean. Keep WordPress, plugins and themes updated, and keep backups.', 'arvaseo-connector' ); ?></p></div>
				</div>
			<?php endif; ?>
		<?php endif; ?>
	</section>

	<?php
	if ( array() !== $arvaseo_open ) :
		$arvaseo_grouped = array();
		foreach ( $arvaseo_open as $arvaseo_finding ) {
			$arvaseo_grouped[ $arvaseo_finding['group'] ][] = $arvaseo_finding;
		}
		foreach ( Findings::groups() as $arvaseo_group => list( $arvaseo_title, $arvaseo_intro ) ) :
			if ( empty( $arvaseo_grouped[ $arvaseo_group ] ) ) {
				continue;
			}
			?>
			<div class="arvaseo-grp-title"><h2><?php echo esc_html( $arvaseo_title ); ?></h2>
				<?php
				/* translators: %d: number of findings. */
				echo AdminController::badge( 'neutral', sprintf( _n( '%d finding', '%d findings', count( $arvaseo_grouped[ $arvaseo_group ] ), 'arvaseo-connector' ), count( $arvaseo_grouped[ $arvaseo_group ] ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
				?>
			</div>
			<p class="arvaseo-hint" style="margin: -6px 0 4px"><?php echo esc_html( $arvaseo_intro ); ?></p>
			<?php
			foreach ( $arvaseo_grouped[ $arvaseo_group ] as $arvaseo_finding ) :
				list( $arvaseo_name, $arvaseo_why, $arvaseo_fix ) = Findings::describe( $arvaseo_finding );
				?>
				<article class="arvaseo-finding">
					<div class="arvaseo-row">
						<?php echo AdminController::badge( $arvaseo_severity[ $arvaseo_finding['severity'] ][0], $arvaseo_severity[ $arvaseo_finding['severity'] ][1] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes. ?>
					</div>
					<h3><?php echo esc_html( $arvaseo_name ); ?></h3>
					<?php if ( null !== $arvaseo_finding['path'] ) : ?>
						<p style="margin: 6px 0 0"><code class="arvaseo-value"><?php echo esc_html( (string) $arvaseo_finding['path'] . ( null !== $arvaseo_finding['line'] ? ':' . $arvaseo_finding['line'] : '' ) ); ?></code></p>
					<?php endif; ?>
					<div class="arvaseo-two">
						<div class="arvaseo-box"><span class="arvaseo-box-lbl"><?php esc_html_e( 'What it means', 'arvaseo-connector' ); ?></span><?php echo esc_html( $arvaseo_why ); ?></div>
						<div class="arvaseo-box"><span class="arvaseo-box-lbl"><?php esc_html_e( 'What to do', 'arvaseo-connector' ); ?></span><?php echo esc_html( $arvaseo_fix ); ?></div>
					</div>
					<div class="arvaseo-row" style="gap: 6px 20px">
						<?php if ( null !== $arvaseo_finding['excerpt'] && '' !== $arvaseo_finding['excerpt'] ) : ?>
							<details class="arvaseo-more">
								<summary><?php esc_html_e( 'See the code it matched', 'arvaseo-connector' ); ?></summary>
								<div class="arvaseo-more-in"><pre class="arvaseo-code"><?php echo esc_html( (string) $arvaseo_finding['excerpt'] ); ?></pre></div>
							</details>
						<?php endif; ?>
						<?php
						if ( $arvaseo_manage ) {
							$arvaseo_form( (string) $arvaseo_finding['key'], false, __( 'This is expected', 'arvaseo-connector' ) );
						}
						?>
					</div>
				</article>
			<?php endforeach; ?>
		<?php endforeach; ?>
	<?php endif; ?>

	<?php if ( array() !== $arvaseo_hidden ) : ?>
		<details class="arvaseo-card arvaseo-more" style="margin-top: 0">
			<summary>
				<?php
				/* translators: %d: number of findings. */
				echo esc_html( sprintf( _n( '%d finding marked as expected', '%d findings marked as expected', count( $arvaseo_hidden ), 'arvaseo-connector' ), count( $arvaseo_hidden ) ) );
				?>
			</summary>
			<ul class="arvaseo-bullets">
				<?php foreach ( $arvaseo_hidden as $arvaseo_finding ) : ?>
					<li class="arvaseo-row" style="justify-content: space-between">
						<span><?php echo esc_html( Findings::describe( $arvaseo_finding )[0] ); ?><?php echo null !== $arvaseo_finding['path'] ? ' · <code>' . esc_html( (string) $arvaseo_finding['path'] ) . '</code>' : ''; ?></span>
						<?php
						if ( $arvaseo_manage ) {
							$arvaseo_form( (string) $arvaseo_finding['key'], true, __( 'Show again', 'arvaseo-connector' ) );
						}
						?>
					</li>
				<?php endforeach; ?>
			</ul>
		</details>
	<?php endif; ?>
	<?php
} )();
