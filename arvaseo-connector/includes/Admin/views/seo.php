<?php
/**
 * SEO: the checklist, and SEO titles and descriptions for sites with no SEO plugin.
 *
 * @package ArvaSEO\Connector
 */

use ArvaSEO\Connector\Admin\AdminController;
use ArvaSEO\Connector\Install\Capabilities;
use ArvaSEO\Connector\Seo\Checklist;
use ArvaSEO\Connector\Seo\Detector;
use ArvaSEO\Connector\Seo\Essentials;
use ArvaSEO\Connector\Seo\Options;

defined( 'ABSPATH' ) || exit;

// Scoped so the view's variables stay local.
( static function () {

	$arvaseo_result  = Checklist::results();
	$arvaseo_sum     = Checklist::summary( $arvaseo_result['rows'] );
	$arvaseo_tones   = array(
		'pass'      => array( 'good', __( 'Passed', 'arvaseo-connector' ) ),
		'attention' => array( 'warn', __( 'Needs attention', 'arvaseo-connector' ) ),
		'unknown'   => array( 'neutral', __( 'Couldn’t check', 'arvaseo-connector' ) ),
	);
	$arvaseo_options = Options::all();
	$arvaseo_others  = Detector::active();
	?>
<div class="arvaseo-stack">
	<section class="arvaseo-card" id="arvaseo-seo-checklist" aria-labelledby="arvaseo-seo-checklist-title">
		<div class="arvaseo-between">
			<div>
				<h2 class="arvaseo-card-title" id="arvaseo-seo-checklist-title"><?php esc_html_e( 'SEO checklist', 'arvaseo-connector' ); ?></h2>
				<p class="arvaseo-card-sub">
					<?php
					/* translators: 1: number of checks passed, 2: number of checks. */
					echo esc_html( sprintf( __( '%1$d of %2$d checks passed.', 'arvaseo-connector' ), $arvaseo_sum['passed'], $arvaseo_sum['total'] ) );
					echo ' ';
					/* translators: %s: how long ago the checks were made. */
					echo esc_html( sprintf( __( 'Checked %s ago.', 'arvaseo-connector' ), human_time_diff( (int) $arvaseo_result['at'] ) ) );
					?>
				</p>
			</div>
			<?php AdminController::button( 'arvaseo_connector_seo_check', __( 'Check again', 'arvaseo-connector' ) ); ?>
		</div>
		<p class="arvaseo-hint"><?php esc_html_e( 'These checks use what WordPress knows about your site and one visit to your own home page, made from your server. No account is needed and nothing is sent anywhere.', 'arvaseo-connector' ); ?></p>
		<ul class="arvaseo-rows arvaseo-seo-checks" style="list-style: none; margin: 16px 0 0; padding: 0">
			<?php foreach ( $arvaseo_result['rows'] as $arvaseo_row ) : ?>
				<?php list( $arvaseo_tone, $arvaseo_label ) = $arvaseo_tones[ $arvaseo_row['status'] ] ?? $arvaseo_tones['unknown']; ?>
				<li id="arvaseo-check-<?php echo esc_attr( $arvaseo_row['id'] ); ?>">
					<div class="arvaseo-between" style="align-items: center">
						<strong><?php echo esc_html( $arvaseo_row['title'] ); ?></strong>
						<?php echo AdminController::badge( $arvaseo_tone, $arvaseo_label ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes. ?>
					</div>
					<?php if ( '' !== $arvaseo_row['detail'] ) : ?>
						<p style="margin: 6px 0 0"><?php echo esc_html( $arvaseo_row['detail'] ); ?></p>
					<?php endif; ?>
					<p class="arvaseo-hint" style="margin: 6px 0 0"><b><?php esc_html_e( 'Why it matters:', 'arvaseo-connector' ); ?></b> <?php echo esc_html( $arvaseo_row['why'] ); ?></p>
					<?php if ( 'pass' !== $arvaseo_row['status'] ) : ?>
						<p class="arvaseo-hint" style="margin: 4px 0 0"><b><?php esc_html_e( 'How to fix it:', 'arvaseo-connector' ); ?></b> <?php echo esc_html( $arvaseo_row['fix'] ); ?></p>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
		<p style="margin: 16px 0 0">
			<a href="<?php echo esc_url( Checklist::MORE_URL ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'See every fix in Arva SEO', 'arvaseo-connector' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'arvaseo-connector' ); ?></span></a>
			<span class="arvaseo-hint"> <?php esc_html_e( '— an ordinary link, if you want it. Nothing is sent from this site.', 'arvaseo-connector' ); ?></span>
		</p>
	</section>

	<section class="arvaseo-card" id="arvaseo-seo-fields" aria-labelledby="arvaseo-seo-fields-title">
		<h2 class="arvaseo-card-title" id="arvaseo-seo-fields-title"><?php esc_html_e( 'SEO titles and descriptions', 'arvaseo-connector' ); ?></h2>
		<?php if ( array() !== $arvaseo_others ) : ?>
			<p class="arvaseo-card-sub">
				<?php
				/* translators: %s: names of the SEO plugins that are active. */
				echo esc_html( sprintf( __( '%s is active, so titles and descriptions are left to it. This plugin doesn’t add a second set.', 'arvaseo-connector' ), implode( ', ', $arvaseo_others ) ) );
				?>
			</p>
		<?php else : ?>
			<p class="arvaseo-card-sub"><?php esc_html_e( 'You don’t use an SEO plugin, so you can write a title and a short description for each post, page and product here. They are saved on your site, and Google may or may not use them. Off until you turn it on.', 'arvaseo-connector' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="arvaseo-stack">
				<input type="hidden" name="action" value="arvaseo_connector_seo_essentials" />
				<?php wp_nonce_field( 'arvaseo_connector_seo_essentials' ); ?>
				<div class="arvaseo-field">
					<label class="arvaseo-toggle"><input type="checkbox" name="essentials" value="1" <?php checked( $arvaseo_options['essentials'] ); ?> /><span class="arvaseo-switch" aria-hidden="true"></span><span><span class="arvaseo-label"><?php esc_html_e( 'Add SEO title and meta description boxes', 'arvaseo-connector' ); ?></span><span class="arvaseo-hint" style="display: block"><?php esc_html_e( 'Adds a box to the editor for posts, pages and products. A page prints a description or title only when you filled one in. Turn it off and your pages go back to how they were.', 'arvaseo-connector' ); ?></span></span></label>
				</div>
				<div class="arvaseo-field">
					<label class="arvaseo-label" for="arvaseo-home-title"><?php esc_html_e( 'Home page title', 'arvaseo-connector' ); ?></label>
					<p class="arvaseo-hint">
						<?php
						/* translators: %d: suggested number of characters. */
						echo esc_html( sprintf( __( 'About %d characters or fewer works best. Leave it empty to keep your theme’s title.', 'arvaseo-connector' ), Essentials::TITLE_ADVICE ) );
						?>
					</p>
					<input type="text" class="regular-text" style="max-width: 100%" id="arvaseo-home-title" name="home_title" value="<?php echo esc_attr( $arvaseo_options['home_title'] ); ?>" maxlength="<?php echo (int) Options::TITLE_MAX; ?>" />
				</div>
				<div class="arvaseo-field">
					<label class="arvaseo-label" for="arvaseo-home-description"><?php esc_html_e( 'Home page description', 'arvaseo-connector' ); ?></label>
					<p class="arvaseo-hint">
						<?php
						/* translators: %d: suggested number of characters. */
						echo esc_html( sprintf( __( 'About %d characters or fewer works best. Leave it empty and search engines choose their own.', 'arvaseo-connector' ), Essentials::DESCRIPTION_ADVICE ) );
						?>
					</p>
					<textarea class="large-text" rows="3" id="arvaseo-home-description" name="home_description" maxlength="<?php echo (int) Options::DESCRIPTION_MAX; ?>"><?php echo esc_textarea( $arvaseo_options['home_description'] ); ?></textarea>
				</div>
				<?php if ( current_user_can( Capabilities::MANAGE ) ) : ?>
					<div class="arvaseo-savebar"><button type="submit" class="arvaseo-btn arvaseo-btn--dark"><?php esc_html_e( 'Save', 'arvaseo-connector' ); ?></button></div>
				<?php endif; ?>
			</form>
		<?php endif; ?>
	</section>
</div>
	<?php
} )();
