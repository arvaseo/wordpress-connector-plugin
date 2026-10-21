<?php
/**
 * Overview (connected) or Get started (not connected yet).
 *
 * @package ArvaSEO\Connector
 */

use ArvaSEO\Connector\Admin\AdminController;
use ArvaSEO\Connector\Auth\TokenStore;
use ArvaSEO\Connector\Config;
use ArvaSEO\Connector\Content\PublishingPolicy;
use ArvaSEO\Connector\Content\SeoPlugins;
use ArvaSEO\Connector\Health\AssessmentService;
use ArvaSEO\Connector\Health\HealthSnapshotService;
use ArvaSEO\Connector\Install\Capabilities;
use ArvaSEO\Connector\Install\Schema;
use ArvaSEO\Connector\Privacy\PrivacyService;
use ArvaSEO\Connector\Settings;
use ArvaSEO\Connector\Speed\Fixes;

defined( 'ABSPATH' ) || exit;

// Scoped so the view's variables stay local.
( static function () {

	$arvaseo_connection  = TokenStore::connection();
	$arvaseo_credentials = TokenStore::credentials();
	$arvaseo_status      = TokenStore::unreadable() ? 'reauth_required' : ( $arvaseo_connection['status'] ?? 'disconnected' );
	$arvaseo_can_manage  = current_user_can( Capabilities::MANAGE );
	$arvaseo_tick        = AdminController::icon( 'check', 18 );
	$arvaseo_arrow       = AdminController::icon( 'arrow', 16 );

	if ( 'connected' !== $arvaseo_status ) :
		if ( ! $arvaseo_can_manage ) :
			?>
<section class="arvaseo-card">
	<h2 class="arvaseo-h2"><?php esc_html_e( 'This website isn’t connected to Arva SEO yet', 'arvaseo-connector' ); ?></h2>
	<p class="arvaseo-hint"><?php esc_html_e( 'An administrator can connect it from this page.', 'arvaseo-connector' ); ?></p>
</section>
			<?php
			return;
		endif;
		$arvaseo_reconnect = 'reauth_required' === $arvaseo_status;
		?>
<div class="arvaseo-hero">
	<p class="arvaseo-eyebrow"><?php echo $arvaseo_reconnect ? esc_html__( 'Reconnect', 'arvaseo-connector' ) : esc_html__( 'Welcome', 'arvaseo-connector' ); ?></p>
	<h1>
		<?php
		/* translators: the words in <span> are highlighted. */
		echo wp_kses( __( 'Make this website faster today. <span>Do your own SEO</span> step by step.', 'arvaseo-connector' ), array( 'span' => array() ) );
		?>
	</h1>
	<p><?php esc_html_e( 'Start with the speed fixes, no account needed. Connect to Arva SEO when you want help with the rest: what to fix first, the searches worth winning, and content sent straight to WordPress.', 'arvaseo-connector' ); ?></p>
</div>

<div class="arvaseo-grid2">
	<section class="arvaseo-card" aria-labelledby="arvaseo-start-speed">
		<div class="arvaseo-row"><?php echo AdminController::badge( 'good', __( 'No account needed', 'arvaseo-connector' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes. ?></div>
		<h2 class="arvaseo-h2-serif" id="arvaseo-start-speed"><?php esc_html_e( 'Speed up this website', 'arvaseo-connector' ); ?></h2>
		<p class="arvaseo-muted" style="margin: 8px 0 0">
			<?php
			/* translators: %d: number of speed fixes. */
			printf( esc_html__( '%d fixes for what Google’s PageSpeed test finds, each named after the problem it solves.', 'arvaseo-connector' ), count( Fixes::all() ) );
			?>
		</p>
		<ul class="arvaseo-ticks">
			<li><?php echo $arvaseo_tick; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><?php esc_html_e( 'Try a fix first. Only you see the change while you’re signed in.', 'arvaseo-connector' ); ?></li>
			<li><?php echo $arvaseo_tick; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><?php esc_html_e( 'Turn it on for everyone, or off again in one click.', 'arvaseo-connector' ); ?></li>
			<li><?php echo $arvaseo_tick; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><?php esc_html_e( 'No coding, and your theme files are never changed.', 'arvaseo-connector' ); ?></li>
		</ul>
		<a class="arvaseo-btn arvaseo-btn--dark" href="<?php echo esc_url( admin_url( 'admin.php?page=arvaseo-speed' ) ); ?>"><?php esc_html_e( 'Open the speed fixes', 'arvaseo-connector' ); ?></a>
	</section>

	<section class="arvaseo-card arvaseo-card--ink" id="arvaseo-connect" aria-labelledby="arvaseo-connect-heading">
		<div class="arvaseo-row"><?php echo AdminController::badge( 'lime', __( 'Your first website is free', 'arvaseo-connector' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes. ?></div>
		<h2 class="arvaseo-h2-serif" id="arvaseo-connect-heading"><?php echo $arvaseo_reconnect ? esc_html__( 'Reconnect to Arva SEO', 'arvaseo-connector' ) : esc_html__( 'Connect to Arva SEO', 'arvaseo-connector' ); ?></h2>
		<p class="arvaseo-muted" style="margin: 8px 0 0"><?php esc_html_e( 'The AI-powered SEO workspace for business owners.', 'arvaseo-connector' ); ?></p>
		<ul class="arvaseo-ticks">
			<li><?php echo $arvaseo_tick; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><?php esc_html_e( 'See which fixes your latest PageSpeed test asks for.', 'arvaseo-connector' ); ?></li>
			<li><?php echo $arvaseo_tick; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><?php esc_html_e( 'A health check of this website, with what to fix first.', 'arvaseo-connector' ); ?></li>
			<li><?php echo $arvaseo_tick; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><?php esc_html_e( 'Send the SEO titles and drafts you approve to WordPress, only as far as you allow.', 'arvaseo-connector' ); ?></li>
		</ul>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="arvaseo_connector_connect" />
			<?php wp_nonce_field( 'arvaseo_connector_connect' ); ?>
			<fieldset class="arvaseo-options">
				<legend class="screen-reader-text"><?php esc_html_e( 'What to allow', 'arvaseo-connector' ); ?></legend>
				<label class="arvaseo-check"><input type="checkbox" name="share_health" value="1" checked="checked" /> <span><?php esc_html_e( 'Share the plugin list and Website Health checks with Arva SEO', 'arvaseo-connector' ); ?></span></label>
				<label class="arvaseo-check"><input type="checkbox" name="share_security" value="1" /> <span><?php esc_html_e( 'Also share the security scan’s results (what it finds, and the files’ paths inside the site)', 'arvaseo-connector' ); ?></span></label>
				<label class="arvaseo-check"><input type="checkbox" name="consent" value="1" /> <span><?php esc_html_e( 'I agree to share the data listed under “Exactly what is shared” with Arva SEO for these purposes.', 'arvaseo-connector' ); ?></span></label>
				<label class="arvaseo-check"><input type="checkbox" name="allow_content" value="1" checked="checked" /> <span><?php esc_html_e( 'Allow Arva SEO to send content to this site (only as drafts until you choose another publishing mode in Settings)', 'arvaseo-connector' ); ?></span></label>
			</fieldset>
			<div class="arvaseo-row" style="gap: 14px">
				<button type="submit" class="arvaseo-btn arvaseo-btn--pri"><?php echo $arvaseo_reconnect ? esc_html__( 'Reconnect to Arva SEO', 'arvaseo-connector' ) : esc_html__( 'Connect to Arva SEO', 'arvaseo-connector' ); ?></button>
				<span class="arvaseo-hint">
					<?php
					/* translators: %s: Arva SEO app host. */
					printf( esc_html__( 'You’ll sign in or create a free account at %s, then come back here. You never enter an Arva SEO password here.', 'arvaseo-connector' ), esc_html( (string) wp_parse_url( Config::app_url(), PHP_URL_HOST ) ) );
					?>
				</span>
			</div>
		</form>
	</section>
</div>

<section class="arvaseo-card" style="margin-top: 16px" aria-labelledby="arvaseo-how-heading">
	<p class="arvaseo-eyebrow" id="arvaseo-how-heading"><?php esc_html_e( 'How connecting works', 'arvaseo-connector' ); ?></p>
	<div class="arvaseo-steps">
		<div class="arvaseo-step"><div class="arvaseo-step-n">1</div><h3><?php esc_html_e( 'Press Connect', 'arvaseo-connector' ); ?></h3><p><?php esc_html_e( 'Sign in to Arva SEO, or create a free account.', 'arvaseo-connector' ); ?></p></div>
		<div class="arvaseo-step"><div class="arvaseo-step-n">2</div><h3><?php esc_html_e( 'Choose this website', 'arvaseo-connector' ); ?></h3><p><?php esc_html_e( 'Pick the website this WordPress site belongs to. You can change what Arva SEO may do any time in Settings.', 'arvaseo-connector' ); ?></p></div>
		<div class="arvaseo-step"><div class="arvaseo-step-n">3</div><h3><?php esc_html_e( 'Come back here', 'arvaseo-connector' ); ?></h3><p><?php esc_html_e( 'Content arrives as drafts until you allow more. Nothing is published without you.', 'arvaseo-connector' ); ?></p></div>
	</div>
	<div class="arvaseo-privacy">
		<?php echo AdminController::icon( 'shield', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
		<div>
			<strong><?php esc_html_e( 'Exactly what is shared', 'arvaseo-connector' ); ?></strong>
			<span class="arvaseo-muted"><?php esc_html_e( 'Recipient: Arva SEO (api.arvaseo.com). Purpose: explain this site’s health, updates and risks with fix guidance. No other telemetry is collected.', 'arvaseo-connector' ); ?></span>
			<details class="arvaseo-more">
				<summary><?php esc_html_e( 'Every field, and what’s never sent', 'arvaseo-connector' ); ?></summary>
				<?php foreach ( PrivacyService::field_set() as $arvaseo_group => $arvaseo_fields ) : ?>
					<h3><?php echo 'inventory' === $arvaseo_group ? esc_html__( 'Plugin and theme list', 'arvaseo-connector' ) : esc_html__( 'Website Health', 'arvaseo-connector' ); ?></h3>
					<ul class="arvaseo-bullets">
						<?php foreach ( $arvaseo_fields as $arvaseo_field ) : ?>
							<li><strong><?php echo esc_html( $arvaseo_field['field'] ); ?></strong>: <?php echo esc_html( $arvaseo_field['purpose'] ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endforeach; ?>
				<h3><?php esc_html_e( 'Security scan results (only if you tick that box)', 'arvaseo-connector' ); ?></h3>
				<ul class="arvaseo-bullets">
					<?php foreach ( PrivacyService::security_field_set() as $arvaseo_field ) : ?>
						<li><strong><?php echo esc_html( $arvaseo_field['field'] ); ?></strong>: <?php echo esc_html( $arvaseo_field['purpose'] ); ?></li>
					<?php endforeach; ?>
				</ul>
				<h3><?php esc_html_e( 'Never sent', 'arvaseo-connector' ); ?></h3>
				<ul class="arvaseo-bullets">
					<?php foreach ( PrivacyService::never_sent() as $arvaseo_never ) : ?>
						<li><?php echo esc_html( $arvaseo_never ); ?></li>
					<?php endforeach; ?>
				</ul>
			</details>
		</div>
	</div>
</section>
		<?php
		return;
	endif;

	// ----- Connected: the overview ----------------------------------------------------------
	global $wpdb;
	$arvaseo_needs    = AdminController::needs();
	$arvaseo_settings = Settings::all();
	$arvaseo_test     = AssessmentService::pagespeed_result();
	$arvaseo_latest   = HealthSnapshotService::latest();
	$arvaseo_summary  = $arvaseo_latest['summary'] ?? array();
	$arvaseo_states   = array_map( array( Fixes::class, 'state' ), array_keys( Fixes::all() ) );
	$arvaseo_on       = count( array_keys( $arvaseo_states, 'on', true ) );
	$arvaseo_trying   = count( array_keys( $arvaseo_states, 'trying', true ) );
	$arvaseo_asked    = count( AdminController::asked_fixes() );
	$arvaseo_modes    = array(
		'draft_only' => __( 'Draft only', 'arvaseo-connector' ),
		'editorial'  => __( 'Editorial review', 'arvaseo-connector' ),
		'scheduling' => __( 'Review and scheduling', 'arvaseo-connector' ),
		'full'       => __( 'Full publishing', 'arvaseo-connector' ),
	);
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- own tables.
	$arvaseo_sent   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Schema::table( 'deliveries' ) ) );
	$arvaseo_recent = current_user_can( Capabilities::MANAGE ) ? (array) $wpdb->get_results( $wpdb->prepare( 'SELECT message, user_id, created_at FROM %i ORDER BY id DESC LIMIT 3', Schema::table( 'audit_log' ) ), ARRAY_A ) : array();
	// phpcs:enable
	$arvaseo_scope_text = array(
		'site:register'          => __( 'Identify this site and keep the connection working', 'arvaseo-connector' ),
		'plugin-inventory:write' => __( 'Receive the plugin and theme list', 'arvaseo-connector' ),
		'health-snapshot:write'  => __( 'Receive Website Health checks', 'arvaseo-connector' ),
		'health-assessment:read' => __( 'Show its findings here', 'arvaseo-connector' ),
		'content-delivery:read'  => __( 'Send content to this site', 'arvaseo-connector' ),
		'content-status:write'   => __( 'Hear what WordPress did with that content', 'arvaseo-connector' ),
	);
	$arvaseo_scopes     = (array) ( $arvaseo_connection['scopes'] ?? array() );
	?>
<div class="arvaseo-stack">
	<section class="arvaseo-card" aria-labelledby="arvaseo-needs-heading">
		<?php if ( array() === $arvaseo_needs ) : ?>
			<div class="arvaseo-need" style="padding: 0">
				<span class="arvaseo-need-ic arvaseo-need-ic--acc" aria-hidden="true"><?php echo AdminController::icon( 'check', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></span>
				<div class="arvaseo-need-txt"><h2 class="arvaseo-h2" id="arvaseo-needs-heading"><?php esc_html_e( 'Nothing needs you right now', 'arvaseo-connector' ); ?></h2><p><?php esc_html_e( 'Arva SEO checks this website every day. Anything worth your attention shows up here.', 'arvaseo-connector' ); ?></p></div>
			</div>
		<?php else : ?>
			<div class="arvaseo-between">
				<div><p class="arvaseo-eyebrow"><?php esc_html_e( 'Needs you', 'arvaseo-connector' ); ?></p><h2 class="arvaseo-h2" id="arvaseo-needs-heading">
					<?php
					/* translators: %d: number of things to look at. */
					echo esc_html( sprintf( _n( '%d thing to look at', '%d things to look at', count( $arvaseo_needs ), 'arvaseo-connector' ), count( $arvaseo_needs ) ) );
					?>
				</h2></div>
				<?php echo AdminController::badge( 'neutral', __( 'Most urgent first', 'arvaseo-connector' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes. ?>
			</div>
			<div style="margin-top: 12px">
				<?php foreach ( $arvaseo_needs as list( $arvaseo_tone, $arvaseo_icon, $arvaseo_title, $arvaseo_text, $arvaseo_url, $arvaseo_label ) ) : ?>
					<div class="arvaseo-need">
						<span class="arvaseo-need-ic arvaseo-need-ic--<?php echo esc_attr( $arvaseo_tone ); ?>" aria-hidden="true"><?php echo AdminController::icon( $arvaseo_icon, 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></span>
						<div class="arvaseo-need-txt"><h3><?php echo esc_html( $arvaseo_title ); ?></h3><p><?php echo esc_html( $arvaseo_text ); ?></p></div>
						<a class="arvaseo-btn arvaseo-btn--sm<?php echo 'warn' === $arvaseo_tone || 'bad' === $arvaseo_tone ? ' arvaseo-btn--dark' : ''; ?>" href="<?php echo esc_url( $arvaseo_url ); ?>"><?php echo esc_html( $arvaseo_label ); ?></a>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</section>

	<div class="arvaseo-grid3">
		<?php if ( current_user_can( Capabilities::MANAGE ) ) : ?>
			<section class="arvaseo-card" aria-labelledby="arvaseo-ov-speed">
				<p class="arvaseo-eyebrow" id="arvaseo-ov-speed"><?php esc_html_e( 'Speed', 'arvaseo-connector' ); ?></p>
				<?php if ( null !== $arvaseo_test && null !== $arvaseo_test['performance_score'] ) : ?>
					<div class="arvaseo-score" style="margin: 10px 0 14px">
						<?php echo AdminController::ring( (int) $arvaseo_test['performance_score'], true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from numbers. ?>
						<div><div style="font-weight: 600"><?php esc_html_e( 'Performance on phones', 'arvaseo-connector' ); ?></div><div class="arvaseo-hint"><?php echo esc_html( AdminController::when( $arvaseo_test['tested_at'] ) ); ?></div></div>
					</div>
				<?php else : ?>
					<h3 class="arvaseo-h3" style="margin: 6px 0 10px"><?php esc_html_e( 'No PageSpeed test yet', 'arvaseo-connector' ); ?></h3>
					<p class="arvaseo-hint"><?php esc_html_e( 'Test your homepage in Arva SEO to see which fixes it needs.', 'arvaseo-connector' ); ?></p>
				<?php endif; ?>
				<div class="arvaseo-row" style="margin-top: 10px">
					<?php
					/* translators: %d: number of speed fixes that are on. */
					echo AdminController::badge( $arvaseo_on > 0 ? 'good' : 'neutral', sprintf( _n( '%d fix on', '%d fixes on', $arvaseo_on, 'arvaseo-connector' ), $arvaseo_on ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
					if ( $arvaseo_trying > 0 ) {
						/* translators: %d: number of speed fixes being tried. */
						echo AdminController::badge( 'warn', sprintf( _n( '%d being tried', '%d being tried', $arvaseo_trying, 'arvaseo-connector' ), $arvaseo_trying ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
					}
					if ( $arvaseo_asked > 0 ) {
						/* translators: %d: number of speed fixes the latest test asks for. */
						echo AdminController::badge( 'acc', sprintf( _n( '%d asked for', '%d asked for', $arvaseo_asked, 'arvaseo-connector' ), $arvaseo_asked ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
					}
					if ( \ArvaSEO\Connector\Speed\Cloudflare::connected() ) {
						echo AdminController::badge( \ArvaSEO\Connector\Speed\Cloudflare::details()['refused'] ? 'bad' : 'neutral', __( 'Cloudflare connected', 'arvaseo-connector' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
					}
					?>
				</div>
				<a class="arvaseo-more-link" href="<?php echo esc_url( admin_url( 'admin.php?page=arvaseo-speed' ) ); ?>"><?php esc_html_e( 'Open Speed', 'arvaseo-connector' ); ?> <?php echo $arvaseo_arrow; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></a>
			</section>
		<?php endif; ?>

		<section class="arvaseo-card" aria-labelledby="arvaseo-ov-health">
			<p class="arvaseo-eyebrow" id="arvaseo-ov-health"><?php esc_html_e( 'Website health', 'arvaseo-connector' ); ?></p>
			<?php if ( null === $arvaseo_latest ) : ?>
				<h3 class="arvaseo-h3" style="margin: 6px 0 10px"><?php esc_html_e( 'No check yet', 'arvaseo-connector' ); ?></h3>
				<p class="arvaseo-hint"><?php esc_html_e( 'The first check runs in the background shortly after connecting.', 'arvaseo-connector' ); ?></p>
			<?php else : ?>
				<h3 class="arvaseo-h3" style="margin: 6px 0 14px">
					<?php
					$arvaseo_open = (int) ( $arvaseo_summary['critical'] ?? 0 ) + (int) ( $arvaseo_summary['recommended'] ?? 0 );
					/* translators: %d: number of results to look at. */
					echo esc_html( 0 === $arvaseo_open ? __( 'All checks passed', 'arvaseo-connector' ) : sprintf( _n( '%d to look at', '%d to look at', $arvaseo_open, 'arvaseo-connector' ), $arvaseo_open ) );
					?>
				</h3>
				<ul class="arvaseo-counts arvaseo-counts--inline" style="margin: 0">
					<li class="arvaseo-count"><b><?php echo (int) ( $arvaseo_summary['critical'] ?? 0 ); ?></b><?php echo AdminController::badge( 'bad', __( 'Critical', 'arvaseo-connector' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes. ?></li>
					<li class="arvaseo-count"><b><?php echo (int) ( $arvaseo_summary['recommended'] ?? 0 ); ?></b><?php echo AdminController::badge( 'warn', __( 'Recommended', 'arvaseo-connector' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes. ?></li>
					<li class="arvaseo-count"><b><?php echo (int) ( $arvaseo_summary['good'] ?? 0 ); ?></b><?php echo AdminController::badge( 'good', __( 'Passed', 'arvaseo-connector' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes. ?></li>
				</ul>
				<p class="arvaseo-hint" style="margin-top: 12px">
					<?php
					/* translators: 1: when the site was last checked, 2: number of checks that couldn't run. */
					printf( esc_html__( 'Checked %1$s. %2$d couldn’t run.', 'arvaseo-connector' ), esc_html( AdminController::when( $arvaseo_latest['generated_at'] ?? null ) ), (int) ( $arvaseo_summary['unknown'] ?? 0 ) );
					?>
				</p>
			<?php endif; ?>
			<a class="arvaseo-more-link" href="<?php echo esc_url( admin_url( 'admin.php?page=arvaseo-health' ) ); ?>"><?php esc_html_e( 'Open Health', 'arvaseo-connector' ); ?> <?php echo $arvaseo_arrow; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></a>
		</section>

		<?php if ( current_user_can( Capabilities::PUBLISH_CONTENT ) ) : ?>
			<section class="arvaseo-card" aria-labelledby="arvaseo-ov-content">
				<p class="arvaseo-eyebrow" id="arvaseo-ov-content"><?php esc_html_e( 'Content from Arva SEO', 'arvaseo-connector' ); ?></p>
				<h3 class="arvaseo-h3" style="margin: 6px 0 14px"><?php echo esc_html( $arvaseo_modes[ $arvaseo_settings['publishing_mode'] ] ?? '' ); ?></h3>
				<div class="arvaseo-stack" style="gap: 8px">
					<div class="arvaseo-kv"><span class="arvaseo-muted"><?php esc_html_e( 'Sent so far', 'arvaseo-connector' ); ?></span><span>
						<?php
						/* translators: %d: number of posts received. */
						echo esc_html( 0 === $arvaseo_sent ? __( 'None yet', 'arvaseo-connector' ) : sprintf( _n( '%d post', '%d posts', $arvaseo_sent, 'arvaseo-connector' ), $arvaseo_sent ) );
						?>
					</span></div>
					<div class="arvaseo-kv"><span class="arvaseo-muted"><?php esc_html_e( 'SEO titles and descriptions', 'arvaseo-connector' ); ?></span>
						<?php echo $arvaseo_settings['seo_updates'] && isset( SeoPlugins::NAMES[ SeoPlugins::resolve() ] ) ? AdminController::badge( 'good', __( 'Allowed', 'arvaseo-connector' ) ) : AdminController::badge( 'neutral', __( 'Not allowed', 'arvaseo-connector' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes. ?>
					</div>
					<div class="arvaseo-kv"><span class="arvaseo-muted"><?php esc_html_e( 'Default author', 'arvaseo-connector' ); ?></span>
						<?php
						$arvaseo_author = $arvaseo_settings['default_author_id'] > 0 ? get_userdata( (int) $arvaseo_settings['default_author_id'] ) : false;
						echo $arvaseo_author ? '<span>' . esc_html( $arvaseo_author->display_name ) . '</span>' : AdminController::badge( 'warn', __( 'Not chosen', 'arvaseo-connector' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped here or by badge().
						?>
					</div>
				</div>
				<a class="arvaseo-more-link" href="<?php echo esc_url( admin_url( 'admin.php?page=arvaseo-content' ) ); ?>"><?php esc_html_e( 'Open Content', 'arvaseo-connector' ); ?> <?php echo $arvaseo_arrow; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></a>
			</section>
		<?php endif; ?>
	</div>

	<section class="arvaseo-card" aria-labelledby="arvaseo-conn-heading">
		<div class="arvaseo-between">
			<div>
				<p class="arvaseo-eyebrow"><?php esc_html_e( 'Connection', 'arvaseo-connector' ); ?></p>
				<h2 class="arvaseo-h3" id="arvaseo-conn-heading"><?php echo esc_html( (string) ( $arvaseo_connection['project']['name'] ?? '—' ) ); ?></h2>
				<div class="arvaseo-muted">
					<?php
					/* translators: %s: Arva SEO workspace name. */
					printf( esc_html__( 'in the workspace %s', 'arvaseo-connector' ), esc_html( (string) ( $arvaseo_connection['workspace']['name'] ?? '—' ) ) );
					if ( ! empty( $arvaseo_connection['client'] ) ) {
						/* translators: %s: client name. */
						echo ' · ' . esc_html( sprintf( __( 'client: %s', 'arvaseo-connector' ), $arvaseo_connection['client'] ) );
					}
					?>
				</div>
			</div>
			<?php if ( $arvaseo_can_manage ) : ?>
				<div class="arvaseo-row">
					<?php
					AdminController::button( 'arvaseo_connector_test', __( 'Test connection', 'arvaseo-connector' ) );
					AdminController::button( 'arvaseo_connector_disconnect', __( 'Disconnect', 'arvaseo-connector' ), 'arvaseo-btn arvaseo-btn--sm arvaseo-btn--ghost', 'confirmDisconnect' );
					?>
				</div>
			<?php endif; ?>
		</div>
		<div class="arvaseo-row" style="margin-top: 14px">
			<span class="arvaseo-chip arvaseo-chip--good"><span class="arvaseo-dot"></span><?php esc_html_e( 'Connected', 'arvaseo-connector' ); ?></span>
			<?php
			if ( in_array( 'content-delivery:read', $arvaseo_scopes, true ) ) {
				echo AdminController::badge( 'neutral', $arvaseo_modes[ $arvaseo_settings['publishing_mode'] ] ?? '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
			}
			$arvaseo_push = $arvaseo_connection['push_status'] ?? 'unverified';
			echo 'verified' === $arvaseo_push ? AdminController::badge( 'good', __( 'Instant requests work', 'arvaseo-connector' ) ) : AdminController::badge( 'neutral', __( 'Checked every 5 minutes', 'arvaseo-connector' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
			?>
		</div>
		<dl class="arvaseo-facts">
			<div><dt><?php esc_html_e( 'Connected', 'arvaseo-connector' ); ?></dt><dd><?php echo esc_html( AdminController::when( $arvaseo_connection['connected_at'] ?? null ) ); ?></dd></div>
			<div><dt><?php esc_html_e( 'Last heard from', 'arvaseo-connector' ); ?></dt><dd><?php echo esc_html( AdminController::when( $arvaseo_connection['last_success_at'] ?? null ) ); ?></dd></div>
			<div><dt><?php esc_html_e( 'Access', 'arvaseo-connector' ); ?></dt><dd>
				<?php
				echo esc_html(
					null === $arvaseo_credentials
						? __( 'Unavailable', 'arvaseo-connector' )
						/* translators: %s: date and time. */
						: sprintf( __( 'Renews by itself (next %s). Keys are never shown.', 'arvaseo-connector' ), wp_date( get_option( 'time_format' ), (int) $arvaseo_credentials['access_expires_at'] ) )
				);
				?>
			</dd></div>
			<div><dt><?php esc_html_e( 'Site registration ID', 'arvaseo-connector' ); ?></dt><dd><code><?php echo esc_html( substr( (string) TokenStore::site_id(), 0, 6 ) . '…' . substr( (string) TokenStore::site_id(), -4 ) ); ?></code></dd></div>
			<?php if ( ! empty( $arvaseo_connection['last_error']['code'] ) ) : ?>
				<div><dt><?php esc_html_e( 'Last error', 'arvaseo-connector' ); ?></dt><dd><code><?php echo esc_html( $arvaseo_connection['last_error']['code'] ); ?></code> <?php echo esc_html( AdminController::when( $arvaseo_connection['last_error']['at'] ?? null ) ); ?></dd></div>
			<?php endif; ?>
		</dl>
		<details class="arvaseo-more" style="margin-top: 16px">
			<summary>
				<?php
				/* translators: %d: number of permissions. */
				echo esc_html( sprintf( _n( 'What Arva SEO is allowed to do (%d)', 'What Arva SEO is allowed to do (%d)', count( $arvaseo_scopes ), 'arvaseo-connector' ), count( $arvaseo_scopes ) ) );
				?>
			</summary>
			<ul class="arvaseo-bullets arvaseo-muted">
				<?php foreach ( $arvaseo_scopes as $arvaseo_scope ) : ?>
					<li><?php echo esc_html( $arvaseo_scope_text[ $arvaseo_scope ] ?? $arvaseo_scope ); ?> <code><?php echo esc_html( $arvaseo_scope ); ?></code></li>
				<?php endforeach; ?>
			</ul>
		</details>
	</section>

	<?php if ( current_user_can( Capabilities::MANAGE ) ) : ?>
		<section class="arvaseo-card" aria-labelledby="arvaseo-recent-heading">
			<div class="arvaseo-between">
				<div><p class="arvaseo-eyebrow"><?php esc_html_e( 'Activity', 'arvaseo-connector' ); ?></p><h2 class="arvaseo-h2" id="arvaseo-recent-heading"><?php esc_html_e( 'Lately', 'arvaseo-connector' ); ?></h2></div>
				<a class="arvaseo-btn arvaseo-btn--sm arvaseo-btn--ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=arvaseo-activity' ) ); ?>"><?php esc_html_e( 'See all activity', 'arvaseo-connector' ); ?></a>
			</div>
			<div style="margin-top: 8px">
				<?php if ( array() === $arvaseo_recent ) : ?>
					<p class="arvaseo-hint"><?php esc_html_e( 'Nothing yet.', 'arvaseo-connector' ); ?></p>
				<?php endif; ?>
				<?php foreach ( $arvaseo_recent as $arvaseo_row ) : ?>
					<?php $arvaseo_user = $arvaseo_row['user_id'] ? get_userdata( (int) $arvaseo_row['user_id'] ) : false; ?>
					<div class="arvaseo-act">
						<time><?php echo esc_html( AdminController::when( $arvaseo_row['created_at'] . ' UTC' ) ); ?></time>
						<span><?php echo esc_html( $arvaseo_row['message'] ); ?></span>
						<?php echo AdminController::badge( 'neutral', $arvaseo_user ? $arvaseo_user->display_name : __( 'Automatic', 'arvaseo-connector' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes. ?>
					</div>
				<?php endforeach; ?>
			</div>
		</section>
	<?php endif; ?>
</div>
	<?php
} )();
