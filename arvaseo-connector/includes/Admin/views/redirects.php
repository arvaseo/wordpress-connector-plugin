<?php
/**
 * Redirects: the broken-address log and the redirect rules.
 *
 * @package ArvaSEO\Connector
 */

use ArvaSEO\Connector\Admin\AdminController;
use ArvaSEO\Connector\Seo\NotFoundLog;
use ArvaSEO\Connector\Seo\Options;
use ArvaSEO\Connector\Seo\Redirects;

defined( 'ABSPATH' ) || exit;

// Scoped so the view's variables stay local.
( static function () {

	$arvaseo_options = Options::all();
	$arvaseo_rules   = Redirects::all();
	$arvaseo_missing = NotFoundLog::top( 50 );
	$arvaseo_utc     = static fn ( string $mysql ): int => (int) strtotime( $mysql . ' UTC' );
	?>
<div class="arvaseo-stack">
	<?php if ( Redirects::disabled() ) : ?>
		<div class="notice notice-warning inline arvaseo-own"><p><?php esc_html_e( 'Redirects and the broken-address log are switched off in wp-config.php (ARVASEO_CONNECTOR_DISABLE_REDIRECTS). Remove that line to use them.', 'arvaseo-connector' ); ?></p></div>
	<?php endif; ?>

	<section class="arvaseo-card" id="arvaseo-redirect-settings" aria-labelledby="arvaseo-redirect-settings-title">
		<h2 class="arvaseo-card-title" id="arvaseo-redirect-settings-title"><?php esc_html_e( 'What to turn on', 'arvaseo-connector' ); ?></h2>
		<p class="arvaseo-card-sub"><?php esc_html_e( 'Both start off. The log keeps only the address that was missing, how many times it was asked for, and when: never an IP address, browser, referring page, cookie or user. Nothing is sent anywhere, and nothing is written to .htaccess.', 'arvaseo-connector' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="arvaseo-stack">
			<input type="hidden" name="action" value="arvaseo_connector_redirect_settings" />
			<?php wp_nonce_field( 'arvaseo_connector_redirect_settings' ); ?>
			<div class="arvaseo-field">
				<label class="arvaseo-toggle"><input type="checkbox" name="log_404" value="1" <?php checked( $arvaseo_options['log_404'] ); ?> /><span class="arvaseo-switch" aria-hidden="true"></span><span><span class="arvaseo-label"><?php esc_html_e( 'Log broken addresses', 'arvaseo-connector' ); ?></span><span class="arvaseo-hint" style="display: block"><?php esc_html_e( 'Keeps up to 1,000 missing addresses. Files such as images and scripts, and addresses that look like hackers probing, are left out.', 'arvaseo-connector' ); ?></span></span></label>
			</div>
			<div class="arvaseo-field">
				<label class="arvaseo-toggle"><input type="checkbox" name="redirects" value="1" <?php checked( $arvaseo_options['redirects'] ); ?> /><span class="arvaseo-switch" aria-hidden="true"></span><span><span class="arvaseo-label"><?php esc_html_e( 'Use redirects', 'arvaseo-connector' ); ?></span><span class="arvaseo-hint" style="display: block"><?php esc_html_e( 'Sends visitors to the new address for each redirect below. Turn it off and every address goes back to how it was.', 'arvaseo-connector' ); ?></span></span></label>
			</div>
			<div class="arvaseo-savebar"><button type="submit" class="arvaseo-btn arvaseo-btn--dark"><?php esc_html_e( 'Save', 'arvaseo-connector' ); ?></button></div>
		</form>
	</section>

	<section class="arvaseo-card" id="arvaseo-missing" aria-labelledby="arvaseo-missing-title">
		<div class="arvaseo-between">
			<div>
				<h2 class="arvaseo-card-title" id="arvaseo-missing-title"><?php esc_html_e( 'Broken addresses', 'arvaseo-connector' ); ?></h2>
				<p class="arvaseo-card-sub"><?php esc_html_e( 'The missing pages visitors asked for most. Choose where each should go.', 'arvaseo-connector' ); ?></p>
			</div>
			<?php if ( array() !== $arvaseo_missing ) : ?>
				<?php AdminController::button( 'arvaseo_connector_404_clear', __( 'Clear the list', 'arvaseo-connector' ), 'arvaseo-btn arvaseo-btn--sm', 'confirmClearMissing' ); ?>
			<?php endif; ?>
		</div>
		<?php if ( array() === $arvaseo_missing ) : ?>
			<div class="arvaseo-empty">
				<span class="arvaseo-empty-ic" aria-hidden="true"><?php echo AdminController::icon( 'check', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></span>
				<div><h3><?php esc_html_e( 'Nothing to show', 'arvaseo-connector' ); ?></h3><p><?php echo $arvaseo_options['log_404'] ? esc_html__( 'No broken addresses have been logged yet.', 'arvaseo-connector' ) : esc_html__( 'Turn on “Log broken addresses” above to start.', 'arvaseo-connector' ); ?></p></div>
			</div>
		<?php else : ?>
			<div class="arvaseo-tablebox">
				<table class="arvaseo-table">
					<caption class="screen-reader-text"><?php esc_html_e( 'Broken addresses', 'arvaseo-connector' ); ?></caption>
					<thead><tr>
						<th scope="col"><?php esc_html_e( 'Address', 'arvaseo-connector' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Times', 'arvaseo-connector' ); ?></th>
						<th scope="col"><?php esc_html_e( 'First and last seen', 'arvaseo-connector' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Redirect this', 'arvaseo-connector' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $arvaseo_missing as $arvaseo_miss ) : ?>
						<tr>
							<td><code><?php echo esc_html( $arvaseo_miss['path'] ); ?></code></td>
							<td><?php echo esc_html( number_format_i18n( $arvaseo_miss['hits'] ) ); ?></td>
							<td>
								<?php
								/* translators: 1: date of the first request, 2: date of the latest request. */
								echo esc_html( sprintf( __( '%1$s to %2$s', 'arvaseo-connector' ), wp_date( get_option( 'date_format' ), $arvaseo_utc( $arvaseo_miss['first_seen'] ) ), wp_date( get_option( 'date_format' ), $arvaseo_utc( $arvaseo_miss['last_seen'] ) ) ) );
								?>
							</td>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="arvaseo-inline-form">
									<input type="hidden" name="action" value="arvaseo_connector_redirect_add" />
									<input type="hidden" name="from" value="<?php echo esc_attr( $arvaseo_miss['path'] ); ?>" />
									<input type="hidden" name="code" value="301" />
									<?php wp_nonce_field( 'arvaseo_connector_redirect_add' ); ?>
									<label class="screen-reader-text" for="arvaseo-miss-<?php echo (int) $arvaseo_miss['id']; ?>"><?php esc_html_e( 'Send visitors to', 'arvaseo-connector' ); ?></label>
									<input type="text" id="arvaseo-miss-<?php echo (int) $arvaseo_miss['id']; ?>" name="target" placeholder="/new-page/" size="22" required />
									<button type="submit" class="arvaseo-btn arvaseo-btn--sm"><?php esc_html_e( 'Redirect this', 'arvaseo-connector' ); ?></button>
								</form>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="arvaseo-inline-form">
									<input type="hidden" name="action" value="arvaseo_connector_404_remove" />
									<input type="hidden" name="id" value="<?php echo (int) $arvaseo_miss['id']; ?>" />
									<?php wp_nonce_field( 'arvaseo_connector_404_remove' ); ?>
									<button type="submit" class="arvaseo-link-btn"><?php esc_html_e( 'Remove', 'arvaseo-connector' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	</section>

	<section class="arvaseo-card" id="arvaseo-rules" aria-labelledby="arvaseo-rules-title">
		<div class="arvaseo-between">
			<div>
				<h2 class="arvaseo-card-title" id="arvaseo-rules-title"><?php esc_html_e( 'Redirects', 'arvaseo-connector' ); ?></h2>
				<p class="arvaseo-card-sub">
					<?php
					/* translators: 1: number of redirects, 2: the most allowed. */
					echo esc_html( sprintf( __( '%1$d of %2$d. Each one sends one exact address (without anything after a question mark) somewhere else.', 'arvaseo-connector' ), count( $arvaseo_rules ), Redirects::MAX_RULES ) );
					?>
				</p>
			</div>
			<?php if ( array() !== $arvaseo_rules ) : ?>
				<?php AdminController::button( 'arvaseo_connector_redirect_export', __( 'Export as CSV', 'arvaseo-connector' ) ); ?>
			<?php endif; ?>
		</div>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="arvaseo-row" style="align-items: flex-end; flex-wrap: wrap; gap: 12px">
			<input type="hidden" name="action" value="arvaseo_connector_redirect_add" />
			<?php wp_nonce_field( 'arvaseo_connector_redirect_add' ); ?>
			<div>
				<label class="arvaseo-label" for="arvaseo-rule-from"><?php esc_html_e( 'From (on this site)', 'arvaseo-connector' ); ?></label><br />
				<input type="text" id="arvaseo-rule-from" name="from" placeholder="/old-page/" size="26" required />
			</div>
			<div>
				<label class="arvaseo-label" for="arvaseo-rule-target"><?php esc_html_e( 'To (a path here, or a full https:// address)', 'arvaseo-connector' ); ?></label><br />
				<input type="text" id="arvaseo-rule-target" name="target" placeholder="/new-page/" size="34" required />
			</div>
			<div>
				<label class="arvaseo-label" for="arvaseo-rule-code"><?php esc_html_e( 'Type', 'arvaseo-connector' ); ?></label><br />
				<select id="arvaseo-rule-code" name="code">
					<option value="301"><?php esc_html_e( 'Permanent (301)', 'arvaseo-connector' ); ?></option>
					<option value="302"><?php esc_html_e( 'Temporary (302)', 'arvaseo-connector' ); ?></option>
				</select>
			</div>
			<button type="submit" class="arvaseo-btn arvaseo-btn--dark"><?php esc_html_e( 'Add redirect', 'arvaseo-connector' ); ?></button>
		</form>
		<?php if ( array() === $arvaseo_rules ) : ?>
			<p class="arvaseo-hint" style="margin-top: 16px"><?php esc_html_e( 'No redirects yet.', 'arvaseo-connector' ); ?></p>
		<?php else : ?>
			<div class="arvaseo-tablebox" style="margin-top: 16px">
				<table class="arvaseo-table">
					<caption class="screen-reader-text"><?php esc_html_e( 'Redirects', 'arvaseo-connector' ); ?></caption>
					<thead><tr>
						<th scope="col"><?php esc_html_e( 'From', 'arvaseo-connector' ); ?></th>
						<th scope="col"><?php esc_html_e( 'To', 'arvaseo-connector' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Type', 'arvaseo-connector' ); ?></th>
						<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Delete', 'arvaseo-connector' ); ?></span></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $arvaseo_rules as $arvaseo_rule ) : ?>
						<tr>
							<td><code><?php echo esc_html( $arvaseo_rule['from_path'] ); ?></code></td>
							<td><code><?php echo esc_html( $arvaseo_rule['target'] ); ?></code></td>
							<td><?php echo (int) $arvaseo_rule['code']; ?></td>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="arvaseo-inline-form" data-arvaseo-confirm="confirmRedirectDelete">
									<input type="hidden" name="action" value="arvaseo_connector_redirect_delete" />
									<input type="hidden" name="id" value="<?php echo (int) $arvaseo_rule['id']; ?>" />
									<?php wp_nonce_field( 'arvaseo_connector_redirect_delete' ); ?>
									<button type="submit" class="arvaseo-link-btn"><?php esc_html_e( 'Delete', 'arvaseo-connector' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	</section>
</div>
	<?php
} )();
