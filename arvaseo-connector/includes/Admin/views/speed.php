<?php
/**
 * Speed: the PageSpeed fixes.
 *
 * @package ArvaSEO\Connector
 */

use ArvaSEO\Connector\Admin\AdminController;
use ArvaSEO\Connector\Auth\TokenStore;
use ArvaSEO\Connector\Health\AssessmentService;
use ArvaSEO\Connector\Speed\Cloudflare;
use ArvaSEO\Connector\Speed\Exclusions;
use ArvaSEO\Connector\Speed\Fixes;
use ArvaSEO\Connector\Speed\OtherPlugins;
use ArvaSEO\Connector\Speed\ServerRules;

defined( 'ABSPATH' ) || exit;

// Scoped so the view's variables stay local.
( static function () {

	$arvaseo_fixes    = Fixes::all();
	$arvaseo_stored   = Fixes::stored();
	$arvaseo_test     = AssessmentService::pagespeed_result();
	$arvaseo_killed   = defined( Fixes::KILL_SWITCH ) && constant( Fixes::KILL_SWITCH );
	$arvaseo_deferred = get_transient( 'arvaseo_connector_deferred' );
	$arvaseo_deferred = is_array( $arvaseo_deferred ) ? $arvaseo_deferred : array();
	$arvaseo_plugins  = Exclusions::plugin_choices();
	$arvaseo_others   = OtherPlugins::active();
	$arvaseo_keep     = static function ( bool $keep, string $label, string $css_class ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="arvaseo-inline-form">';
		echo '<input type="hidden" name="action" value="arvaseo_connector_keep_others" />';
		echo '<input type="hidden" name="keep" value="' . ( $keep ? '1' : '0' ) . '" />';
		wp_nonce_field( 'arvaseo_connector_keep_others' );
		echo '<button type="submit" class="' . esc_attr( $css_class ) . '">' . esc_html( $label ) . '</button>';
		echo '</form>';
	};
	$arvaseo_tones    = array(
		'off'         => 'neutral',
		'trying'      => 'warn',
		'on'          => 'good',
		'handled'     => 'neutral',
		'unavailable' => 'neutral',
	);
	$arvaseo_extras   = array(
		'emoji'          => array( __( 'The emoji script and styles', 'arvaseo-connector' ), __( 'Browsers show emoji by themselves.', 'arvaseo-connector' ) ),
		'oembed'         => array( __( 'The embed discovery script', 'arvaseo-connector' ), __( 'Lets other websites embed your posts as cards. Embedding videos and posts on your site still works.', 'arvaseo-connector' ) ),
		'dashicons'      => array( __( 'Dashicons for visitors', 'arvaseo-connector' ), __( 'The dashboard’s icon font. Leave it on if your theme shows these icons to visitors.', 'arvaseo-connector' ) ),
		'jquery_migrate' => array( __( 'jQuery Migrate', 'arvaseo-connector' ), __( 'Helps old plugins with new jQuery. Only remove it if you’ve checked your site works without it.', 'arvaseo-connector' ) ),
		'block_css'      => array( __( 'Block styles on pages without blocks', 'arvaseo-connector' ), __( 'Classic themes only. Leave it on if you use block widgets.', 'arvaseo-connector' ) ),
	);
	$arvaseo_subs     = array(
		'sooner'  => __( 'The main content appears faster.', 'arvaseo-connector' ),
		'still'   => __( 'Nothing jumps around while it loads.', 'arvaseo-connector' ),
		'nothing' => __( 'Widgets and videos load when someone needs them.', 'arvaseo-connector' ),
		'smaller' => __( 'Less to download on every visit.', 'arvaseo-connector' ),
	);

	// The fixes the latest test asks for, in the test's order.
	$arvaseo_needed = array();
	foreach ( (array) ( $arvaseo_test['audits'] ?? array() ) as $arvaseo_audit ) {
		foreach ( Fixes::for_audit( $arvaseo_audit['id'] ) as $arvaseo_id ) {
			$arvaseo_needed[ $arvaseo_id ][] = $arvaseo_audit;
		}
	}
	$arvaseo_states = array();
	foreach ( array_keys( $arvaseo_fixes ) as $arvaseo_id ) {
		$arvaseo_states[ $arvaseo_id ] = Fixes::state( $arvaseo_id );
	}
	$arvaseo_trying = array_keys( $arvaseo_states, 'trying', true );

	/**
	 * One state-change button.
	 *
	 * @param string $id      Fix ID.
	 * @param string $to      New state.
	 * @param string $label   Button label.
	 * @param string $classes Button classes.
	 * @param string $confirm Confirmation string key.
	 */
	$arvaseo_button = static function ( string $id, string $to, string $label, string $classes = 'arvaseo-btn arvaseo-btn--sm', string $confirm = '' ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="arvaseo-inline-form"' . ( '' !== $confirm ? ' data-arvaseo-confirm="' . esc_attr( $confirm ) . '"' : '' ) . '>';
		echo '<input type="hidden" name="action" value="arvaseo_connector_speed" />';
		echo '<input type="hidden" name="fix" value="' . esc_attr( $id ) . '" />';
		echo '<input type="hidden" name="to" value="' . esc_attr( $to ) . '" />';
		wp_nonce_field( 'arvaseo_connector_speed' );
		echo '<button type="submit" class="' . esc_attr( $classes ) . '">' . esc_html( $label ) . '</button>';
		echo '</form>';
	};
	?>
<div class="arvaseo-stack">
	<?php if ( $arvaseo_killed ) : ?>
		<div class="notice notice-warning inline arvaseo-own"><p><?php esc_html_e( 'Every PageSpeed fix is switched off in wp-config.php (ARVASEO_CONNECTOR_DISABLE_SPEED_FIXES). Remove that line to use them again.', 'arvaseo-connector' ); ?></p></div>
	<?php endif; ?>

	<section class="arvaseo-card" aria-labelledby="arvaseo-test-heading">
		<div class="arvaseo-between" style="align-items: center">
			<?php if ( null === $arvaseo_test ) : ?>
				<div>
					<p class="arvaseo-eyebrow"><?php esc_html_e( 'Your latest PageSpeed test', 'arvaseo-connector' ); ?></p>
					<h2 class="arvaseo-h2" id="arvaseo-test-heading"><?php esc_html_e( 'No test yet', 'arvaseo-connector' ); ?></h2>
					<p class="arvaseo-hint" style="max-width: 640px">
						<?php
						echo esc_html(
							TokenStore::is_connected()
								? __( 'Run a PageSpeed test of your homepage in Arva SEO (PageSpeed, on phones). This page then shows which of these fixes it needs. The fixes work either way.', 'arvaseo-connector' )
								: __( 'Connect to Arva SEO and test your homepage there to see which of these fixes it needs. The fixes work without connecting.', 'arvaseo-connector' )
						);
						?>
					</p>
				</div>
			<?php else : ?>
				<div class="arvaseo-score">
					<?php echo null === $arvaseo_test['performance_score'] ? '' : AdminController::ring( (int) $arvaseo_test['performance_score'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from numbers. ?>
					<div>
						<p class="arvaseo-eyebrow"><?php esc_html_e( 'Your latest PageSpeed test', 'arvaseo-connector' ); ?></p>
						<h2 class="arvaseo-h2" id="arvaseo-test-heading">
							<?php
							/* translators: %s: PageSpeed score. */
							printf( esc_html__( 'Performance %s out of 100 on phones', 'arvaseo-connector' ), esc_html( null === $arvaseo_test['performance_score'] ? '—' : (string) $arvaseo_test['performance_score'] ) );
							?>
						</h2>
						<div class="arvaseo-muted">
							<?php
							/* translators: %s: date and time. */
							printf( esc_html__( 'Tested %s in Arva SEO', 'arvaseo-connector' ), esc_html( AdminController::when( $arvaseo_test['tested_at'] ) ) );
							?>
						</div>
					</div>
				</div>
			<?php endif; ?>
			<?php if ( TokenStore::is_connected() ) : ?>
				<a class="arvaseo-btn arvaseo-btn--sm" href="<?php echo esc_url( (string) ( TokenStore::connection()['app_url'] ?? '' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Run a new test in Arva SEO', 'arvaseo-connector' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'arvaseo-connector' ); ?></span></a>
			<?php endif; ?>
		</div>
		<?php if ( null !== $arvaseo_test ) : ?>
			<div class="arvaseo-row" style="margin-top: 16px; padding-top: 16px; border-top: 1px solid #e2ded3">
				<?php if ( array() === $arvaseo_needed ) : ?>
					<span class="arvaseo-muted"><?php esc_html_e( 'None of the problems it found are ones these fixes address. Arva SEO’s PageSpeed page has the steps for the rest.', 'arvaseo-connector' ); ?></span>
				<?php else : ?>
					<span class="arvaseo-muted"><?php esc_html_e( 'This test asks for:', 'arvaseo-connector' ); ?></span>
					<?php foreach ( $arvaseo_needed as $arvaseo_id => $arvaseo_audits ) : ?>
						<a class="arvaseo-chip arvaseo-chip--acc" href="#<?php echo esc_attr( Fixes::anchor( $arvaseo_id ) ); ?>">
							<?php echo esc_html( $arvaseo_fixes[ $arvaseo_id ]['title'] ); ?>
							<span class="screen-reader-text">
								<?php
								/* translators: 1: fix state, 2: Lighthouse audit names. */
								echo esc_html( sprintf( __( '(%1$s; fixes %2$s)', 'arvaseo-connector' ), Fixes::state_label( $arvaseo_states[ $arvaseo_id ] ), implode( ', ', array_unique( array_column( $arvaseo_audits, 'title' ) ) ) ) );
								?>
							</span>
						</a>
						<?php if ( 'handled' === $arvaseo_states[ $arvaseo_id ] ) : ?>
							<a class="arvaseo-badge-link" href="#arvaseo-other-plugins"><?php echo AdminController::badge( 'neutral', Fixes::state_label( 'handled' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes. ?></a>
						<?php else : ?>
							<?php echo 'off' === $arvaseo_states[ $arvaseo_id ] ? '' : AdminController::badge( $arvaseo_tones[ $arvaseo_states[ $arvaseo_id ] ] ?? 'neutral', Fixes::state_label( $arvaseo_states[ $arvaseo_id ] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes. ?>
						<?php endif; ?>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</section>

	<?php if ( array() !== $arvaseo_others && OtherPlugins::kept() ) : ?>
		<div id="arvaseo-other-plugins" class="arvaseo-row">
			<span class="arvaseo-hint">
				<?php
				/* translators: %s: plugin names. */
				echo esc_html( sprintf( __( 'Arva SEO leaves the fixes %s can do to them.', 'arvaseo-connector' ), OtherPlugins::names( array_column( $arvaseo_others, 'name' ) ) ) );
				?>
			</span>
			<?php $arvaseo_keep( false, __( 'Review', 'arvaseo-connector' ), 'arvaseo-btn arvaseo-btn--sm arvaseo-btn--ghost' ); ?>
		</div>
	<?php elseif ( array() !== $arvaseo_others ) : ?>
		<section id="arvaseo-other-plugins" class="arvaseo-card" aria-labelledby="arvaseo-others-heading">
			<p class="arvaseo-eyebrow"><?php esc_html_e( 'Other speed plugins', 'arvaseo-connector' ); ?></p>
			<h2 class="arvaseo-h3" id="arvaseo-others-heading">
				<?php
				echo esc_html(
					1 === count( $arvaseo_others )
						/* translators: %s: plugin name. */
						? sprintf( __( '%s does some of the same jobs', 'arvaseo-connector' ), $arvaseo_others[0]['name'] )
						/* translators: %d: number of plugins. */
						: sprintf( __( '%d other speed plugins do some of the same jobs', 'arvaseo-connector' ), count( $arvaseo_others ) )
				);
				?>
			</h2>
			<p class="arvaseo-muted" style="margin: 8px 0 0; max-width: 760px"><?php esc_html_e( 'Two plugins changing how the same things load can break pages or undo each other’s work. Deactivate one to use Arva SEO’s fixes instead, or keep it and Arva SEO leaves those fixes to it.', 'arvaseo-connector' ); ?></p>
			<div class="arvaseo-rows" style="margin-top: 16px"><div class="arvaseo-rows-in">
				<?php foreach ( $arvaseo_others as $arvaseo_other ) : ?>
					<div class="arvaseo-fix">
						<div class="arvaseo-fix-body">
							<h3><?php echo esc_html( $arvaseo_other['name'] ); ?></h3>
							<div class="arvaseo-warns">
								<span class="arvaseo-warns-lbl"><?php esc_html_e( 'Does the same as:', 'arvaseo-connector' ); ?></span>
								<?php foreach ( $arvaseo_other['fixes'] as $arvaseo_title ) : ?>
									<span class="arvaseo-warn-item"><?php echo esc_html( $arvaseo_title ); ?></span>
								<?php endforeach; ?>
							</div>
							<?php if ( '' !== OtherPlugins::caveat( $arvaseo_other['also'] ) ) : ?>
								<div class="arvaseo-risk arvaseo-risk--check"><?php echo AdminController::icon( 'alert', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><span><?php echo esc_html( OtherPlugins::caveat( $arvaseo_other['also'] ) ); ?></span></div>
							<?php endif; ?>
						</div>
						<div class="arvaseo-fix-acts">
							<?php if ( $arvaseo_other['network'] ) : ?>
								<span class="arvaseo-hint arvaseo-fix-acts-note"><?php esc_html_e( 'On for the whole network: a network administrator can deactivate it.', 'arvaseo-connector' ); ?></span>
							<?php else : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="arvaseo-inline-form" data-arvaseo-confirm="confirmDeactivate">
									<input type="hidden" name="action" value="arvaseo_connector_deactivate_other" />
									<input type="hidden" name="plugin" value="<?php echo esc_attr( $arvaseo_other['slug'] ); ?>" />
									<?php wp_nonce_field( 'arvaseo_connector_deactivate_other' ); ?>
									<button type="submit" class="arvaseo-btn arvaseo-btn--sm arvaseo-btn--dark">
										<?php
										/* translators: %s: plugin name. */
										echo esc_html( sprintf( __( 'Deactivate %s', 'arvaseo-connector' ), $arvaseo_other['name'] ) );
										?>
									</button>
								</form>
							<?php endif; ?>
						</div>
					</div>
				<?php endforeach; ?>
			</div></div>
			<div class="arvaseo-row" style="margin-top: 16px">
				<?php $arvaseo_keep( true, 1 === count( $arvaseo_others ) ? __( 'Keep it', 'arvaseo-connector' ) : __( 'Keep them', 'arvaseo-connector' ), 'arvaseo-btn arvaseo-btn--sm' ); ?>
				<span class="arvaseo-hint"><?php esc_html_e( 'Deactivating keeps the plugin’s settings, so you can activate it again from Plugins.', 'arvaseo-connector' ); ?></span>
			</div>
		</section>
	<?php endif; ?>

	<?php if ( array() !== $arvaseo_trying ) : ?>
		<div class="arvaseo-trybar" role="status">
			<?php echo AdminController::icon( 'eye', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
			<div>
				<strong>
					<?php
					/* translators: %s: names of the fixes being tried. */
					echo esc_html( sprintf( __( 'You’re trying %s.', 'arvaseo-connector' ), implode( ', ', array_map( static fn ( string $id ): string => $arvaseo_fixes[ $id ]['title'], $arvaseo_trying ) ) ) );
					?>
				</strong>
				<?php esc_html_e( 'Only you see the change while you’re signed in. Visit a few pages, then turn it on for everyone or stop trying.', 'arvaseo-connector' ); ?>
			</div>
			<a class="arvaseo-btn arvaseo-btn--sm" href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View your site', 'arvaseo-connector' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'arvaseo-connector' ); ?></span></a>
		</div>
	<?php endif; ?>

	<div class="arvaseo-between" style="align-items: center">
		<div class="arvaseo-seg" role="group" aria-label="<?php esc_attr_e( 'Show fixes', 'arvaseo-connector' ); ?>" data-arvaseo-filter hidden>
			<?php
			$arvaseo_filters = array(
				'all'    => array( __( 'All', 'arvaseo-connector' ), count( $arvaseo_fixes ) ),
				'asked'  => array( __( 'Asked for by the test', 'arvaseo-connector' ), count( $arvaseo_needed ) ),
				'on'     => array( __( 'On', 'arvaseo-connector' ), count( array_keys( $arvaseo_states, 'on', true ) ) ),
				'trying' => array( __( 'Trying', 'arvaseo-connector' ), count( $arvaseo_trying ) ),
				'off'    => array( __( 'Off', 'arvaseo-connector' ), count( array_keys( $arvaseo_states, 'off', true ) ) ),
			);
			foreach ( $arvaseo_filters as $arvaseo_key => list( $arvaseo_label, $arvaseo_n ) ) :
				if ( 'all' !== $arvaseo_key && 0 === $arvaseo_n ) {
					continue;
				}
				?>
				<button type="button" data-filter="<?php echo esc_attr( $arvaseo_key ); ?>" aria-pressed="<?php echo 'all' === $arvaseo_key ? 'true' : 'false'; ?>"><?php echo esc_html( $arvaseo_label ); ?> <span class="arvaseo-seg-n"><?php echo (int) $arvaseo_n; ?></span></button>
			<?php endforeach; ?>
		</div>
		<span class="arvaseo-hint" style="max-width: 520px"><?php esc_html_e( 'Safe fixes only change how things load. Fixes marked “Check your pages” can affect how your site works, so try them first.', 'arvaseo-connector' ); ?></span>
	</div>
</div>

	<?php
	$arvaseo_n = 0;
	foreach ( Fixes::groups() as $arvaseo_group => $arvaseo_group_title ) :
		++$arvaseo_n;
		?>
<div class="arvaseo-grp" data-arvaseo-group-head="<?php echo esc_attr( $arvaseo_group ); ?>">
	<span class="arvaseo-grp-n" aria-hidden="true"><?php echo esc_html( sprintf( '%02d', $arvaseo_n ) ); ?></span>
	<div><h2><?php echo esc_html( $arvaseo_group_title ); ?></h2><p><?php echo esc_html( $arvaseo_subs[ $arvaseo_group ] ?? '' ); ?></p></div>
</div>
<div class="arvaseo-rows" data-arvaseo-group="<?php echo esc_attr( $arvaseo_group ); ?>"><div class="arvaseo-rows-in">
		<?php
		foreach ( $arvaseo_fixes as $arvaseo_id => $arvaseo_fix ) :
			if ( $arvaseo_fix['group'] !== $arvaseo_group ) {
				continue;
			}
			$arvaseo_state   = $arvaseo_states[ $arvaseo_id ];
			$arvaseo_handled = Fixes::handled_by( $arvaseo_id );
			$arvaseo_since   = $arvaseo_stored['fixes'][ $arvaseo_id ]['since'];
			$arvaseo_heading = 'arvaseo-fix-heading-' . $arvaseo_id;
			$arvaseo_tryable = in_array( 'trying', $arvaseo_fix['states'], true );
			?>
	<section id="<?php echo esc_attr( Fixes::anchor( $arvaseo_id ) ); ?>" class="arvaseo-fix" data-state="<?php echo esc_attr( $arvaseo_state ); ?>" data-asked="<?php echo isset( $arvaseo_needed[ $arvaseo_id ] ) ? '1' : '0'; ?>" aria-labelledby="<?php echo esc_attr( $arvaseo_heading ); ?>">
		<div class="arvaseo-fix-body">
			<div class="arvaseo-row">
				<h3 id="<?php echo esc_attr( $arvaseo_heading ); ?>"><?php echo esc_html( $arvaseo_fix['title'] ); ?></h3>
				<?php
				if ( 'on' === $arvaseo_state || 'trying' === $arvaseo_state ) {
					echo '<span class="arvaseo-chip arvaseo-chip--' . esc_attr( $arvaseo_tones[ $arvaseo_state ] ) . '"><span class="arvaseo-dot"></span>' . esc_html( Fixes::state_label( $arvaseo_state ) ) . '</span>';
				} else {
					echo AdminController::badge( $arvaseo_tones[ $arvaseo_state ] ?? 'neutral', Fixes::state_label( $arvaseo_state ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
				}
				if ( isset( $arvaseo_needed[ $arvaseo_id ] ) ) {
					echo AdminController::badge( 'acc', __( 'Asked for by your latest test', 'arvaseo-connector' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
				}
				?>
			</div>
			<p class="arvaseo-fix-desc"><?php echo esc_html( $arvaseo_fix['what'] ); ?></p>
			<div class="arvaseo-warns">
				<span class="arvaseo-warns-lbl"><?php esc_html_e( 'Fixes in PageSpeed:', 'arvaseo-connector' ); ?></span>
				<?php foreach ( array_values( $arvaseo_fix['audits'] ) as $arvaseo_audit_title ) : ?>
					<span class="arvaseo-warn-item"><?php echo esc_html( $arvaseo_audit_title ); ?></span>
				<?php endforeach; ?>
			</div>
			<?php if ( 'check' === $arvaseo_fix['risk'] ) : ?>
				<div class="arvaseo-risk arvaseo-risk--check"><?php echo AdminController::icon( 'alert', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
					<span>
					<?php
					echo esc_html(
						$arvaseo_tryable
							? __( 'Check your pages: it can affect how parts of your site work. Try it first.', 'arvaseo-connector' )
							: __( 'Changes your server’s settings for every visitor at once. Check your important pages after turning it on.', 'arvaseo-connector' )
					);
					?>
					</span>
				</div>
			<?php else : ?>
				<div class="arvaseo-risk arvaseo-risk--safe"><?php echo AdminController::icon( 'check', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><span><?php esc_html_e( 'Safe: it only changes how things load, not what visitors see.', 'arvaseo-connector' ); ?></span></div>
			<?php endif; ?>
			<?php if ( in_array( $arvaseo_id, array( 'defer_scripts', 'delay_scripts' ), true ) && 'on' === Cloudflare::details()['rocket_loader'] ) : ?>
				<div class="arvaseo-risk arvaseo-risk--check"><?php echo AdminController::icon( 'cloud', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><span><?php esc_html_e( 'Rocket Loader is on in Cloudflare and also changes how scripts load. Use one or the other.', 'arvaseo-connector' ); ?> <a href="#arvaseo-cloudflare"><?php esc_html_e( 'See Cloudflare', 'arvaseo-connector' ); ?></a></span></div>
			<?php endif; ?>

			<?php if ( 'handled' === $arvaseo_state ) : ?>
				<div class="arvaseo-extra">
					<p>
						<?php
						/* translators: %s: plugin name. */
						printf( esc_html__( '%s can do this, so Arva SEO leaves it to that plugin and your pages aren’t processed twice.', 'arvaseo-connector' ), esc_html( (string) $arvaseo_handled ) );
						?>
						<a href="#arvaseo-other-plugins"><?php esc_html_e( 'Use Arva SEO’s instead', 'arvaseo-connector' ); ?></a>
					</p>
					<?php if ( 'browser_caching' === $arvaseo_id && ServerRules::present() ) : ?>
						<div class="arvaseo-row"><?php $arvaseo_button( $arvaseo_id, 'off', __( 'Remove Arva SEO’s rules', 'arvaseo-connector' ) ); ?></div>
					<?php endif; ?>
				</div>
			<?php elseif ( 'unavailable' === $arvaseo_state ) : ?>
				<div class="arvaseo-extra">
					<p><?php echo esc_html( (string) Fixes::unavailable( $arvaseo_id ) ); ?></p>
					<?php if ( 'browser_caching' === $arvaseo_id ) : ?>
						<details class="arvaseo-more">
							<summary><?php esc_html_e( 'The rules for your host', 'arvaseo-connector' ); ?></summary>
							<p class="arvaseo-hint"><?php esc_html_e( 'nginx:', 'arvaseo-connector' ); ?></p>
							<pre class="arvaseo-code"><?php echo esc_html( ServerRules::nginx() ); ?></pre>
							<p class="arvaseo-hint"><?php esc_html_e( 'Apache or LiteSpeed (.htaccess):', 'arvaseo-connector' ); ?></p>
							<pre class="arvaseo-code"><?php echo esc_html( implode( "\n", ServerRules::lines() ) ); ?></pre>
						</details>
					<?php endif; ?>
				</div>
			<?php else : ?>
				<?php if ( 'trying' === $arvaseo_state ) : ?>
					<div class="arvaseo-extra arvaseo-extra--warn">
						<p>
							<?php esc_html_e( 'Trying it: only you see the change while you’re signed in. Visitors see your site as before.', 'arvaseo-connector' ); ?>
							<a href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open your homepage', 'arvaseo-connector' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'arvaseo-connector' ); ?></span></a>
						</p>
					</div>
				<?php elseif ( 'on' === $arvaseo_state && $arvaseo_since && ! in_array( $arvaseo_id, array( 'critical_css', 'unused_css' ), true ) ) : ?>
					<p class="arvaseo-hint" style="margin-top: 10px">
						<?php
						/* translators: %s: date and time. */
						printf( esc_html__( 'On since %s.', 'arvaseo-connector' ), esc_html( AdminController::when( $arvaseo_since ) ) );
						?>
					</p>
				<?php endif; ?>

				<?php if ( 'browser_caching' === $arvaseo_id && 'on' === $arvaseo_state ) : ?>
					<?php $arvaseo_check = $arvaseo_stored['server_check']; ?>
					<?php if ( ! ServerRules::present() ) : ?>
						<div class="arvaseo-extra arvaseo-extra--warn"><p><?php esc_html_e( 'The rules aren’t in your .htaccess file any more: another plugin or your host may have rewritten it. Turn this off and on again to add them back.', 'arvaseo-connector' ); ?></p></div>
					<?php elseif ( null !== $arvaseo_check ) : ?>
						<div class="arvaseo-extra">
							<ul class="arvaseo-bullets" style="margin: 0">
								<li><?php echo esc_html( $arvaseo_check['cache'] ? __( 'Checked: images, fonts, styles and scripts now stay in browsers for a year.', 'arvaseo-connector' ) : __( 'Your server didn’t add the cache lifetimes. Ask your host to turn on mod_expires.', 'arvaseo-connector' ) ); ?></li>
								<li>
									<?php
									echo esc_html(
										null !== $arvaseo_check['compression']
											/* translators: %s: compression method, such as br or gzip. */
											? sprintf( __( 'Checked: pages are sent compressed (%s).', 'arvaseo-connector' ), $arvaseo_check['compression'] )
											: __( 'Pages weren’t compressed when checked from your server. Your host may compress them further along (a CDN, for example); if PageSpeed still asks for it, ask your host to turn on mod_deflate or Brotli.', 'arvaseo-connector' )
									);
									?>
								</li>
							</ul>
						</div>
					<?php endif; ?>
				<?php endif; ?>
				<?php if ( 'browser_caching' === $arvaseo_id ) : ?>
					<details class="arvaseo-more">
						<summary><?php esc_html_e( 'The rules it adds', 'arvaseo-connector' ); ?></summary>
						<div class="arvaseo-more-in">
							<p><?php esc_html_e( 'If you edit your theme’s files by hand, visitors may see the old version for a while; WordPress and plugins add a version to their files, so their updates show straight away.', 'arvaseo-connector' ); ?></p>
							<pre class="arvaseo-code"><?php echo esc_html( implode( "\n", ServerRules::lines() ) ); ?></pre>
						</div>
					</details>
				<?php endif; ?>

				<?php if ( 'wp_extras' === $arvaseo_id ) : ?>
					<details class="arvaseo-more">
						<summary><?php esc_html_e( 'Choose what to remove', 'arvaseo-connector' ); ?></summary>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="arvaseo-more-in">
							<input type="hidden" name="action" value="arvaseo_connector_speed_options" />
							<input type="hidden" name="fix" value="wp_extras" />
							<?php wp_nonce_field( 'arvaseo_connector_speed_options' ); ?>
							<fieldset class="arvaseo-checks" style="flex-direction: column; margin: 0 0 12px">
								<legend class="screen-reader-text"><?php esc_html_e( 'Remove these', 'arvaseo-connector' ); ?></legend>
								<?php foreach ( $arvaseo_extras as $arvaseo_key => list( $arvaseo_label, $arvaseo_desc ) ) : ?>
									<label><input type="checkbox" name="extras[]" value="<?php echo esc_attr( $arvaseo_key ); ?>" <?php checked( $arvaseo_stored['extras'][ $arvaseo_key ] ); ?> /> <span><?php echo esc_html( $arvaseo_label ); ?><br /><span class="arvaseo-hint"><?php echo esc_html( $arvaseo_desc ); ?></span></span></label>
								<?php endforeach; ?>
							</fieldset>
							<button type="submit" class="arvaseo-btn arvaseo-btn--sm"><?php esc_html_e( 'Save choices', 'arvaseo-connector' ); ?></button>
						</form>
					</details>
				<?php endif; ?>

				<?php if ( in_array( $arvaseo_id, array( 'critical_css', 'unused_css' ), true ) ) : ?>
					<?php
					$arvaseo_css       = \ArvaSEO\Connector\Speed\CssOptimizer::counts();
					$arvaseo_allowance = get_option( 'arvaseo_connector_css_allowance', array() );
					$arvaseo_allowance = is_array( $arvaseo_allowance ) ? $arvaseo_allowance : array();
					?>
					<div class="arvaseo-extra">
						<p>
							<?php
							if ( 'on' === $arvaseo_state && $arvaseo_since ) {
								/* translators: %s: date and time. */
								printf( esc_html__( 'On since %s.', 'arvaseo-connector' ), esc_html( AdminController::when( $arvaseo_since ) ) );
								echo ' ';
							}
							printf(
								/* translators: 1: page types with optimized CSS, 2: page types waiting. */
								esc_html__( 'Optimized CSS for %1$d page types; %2$d waiting.', 'arvaseo-connector' ),
								(int) $arvaseo_css['ready'],
								(int) $arvaseo_css['waiting']
							);
							if ( isset( $arvaseo_allowance['used'], $arvaseo_allowance['limit'] ) ) {
								echo ' ';
								printf(
									/* translators: 1: page optimizations used, 2: page optimizations included this month. */
									esc_html__( 'Page optimizations this month: %1$d of %2$d.', 'arvaseo-connector' ),
									(int) $arvaseo_allowance['used'],
									(int) $arvaseo_allowance['limit']
								);
							}
							?>
						</p>
						<?php if ( $arvaseo_css['no_allowance'] > 0 ) : ?>
							<p class="arvaseo-risk arvaseo-risk--check">
								<?php
								printf(
									/* translators: %d: page types waiting for next month's page optimizations. */
									esc_html( _n( '%d page type waits for next month’s page optimizations; it loads its normal styles until then.', '%d page types wait for next month’s page optimizations; they load their normal styles until then.', $arvaseo_css['no_allowance'], 'arvaseo-connector' ) ),
									(int) $arvaseo_css['no_allowance']
								);
								?>
								<a href="https://app.arvaseo.com/billing" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'More page optimizations', 'arvaseo-connector' ); ?></a>
							</p>
						<?php endif; ?>
					</div>
					<details class="arvaseo-more">
						<summary><?php esc_html_e( 'Optimized CSS: work it out again, or leave stylesheets alone', 'arvaseo-connector' ); ?></summary>
						<div class="arvaseo-more-in">
							<p class="arvaseo-hint"><?php esc_html_e( 'Pages are grouped by type (homepage, page, post, product, category…) and by their stylesheets; each group uses one page optimization. A stylesheet that changes, after a theme or plugin update, makes a new group; clearing your cache costs nothing.', 'arvaseo-connector' ); ?></p>
							<div class="arvaseo-row" style="margin: 12px 0">
								<?php
								foreach ( array(
									'optimize'   => __( 'Optimize now', 'arvaseo-connector' ),
									'check'      => __( 'Check for results', 'arvaseo-connector' ),
									'regenerate' => __( 'Regenerate', 'arvaseo-connector' ),
								) as $arvaseo_do => $arvaseo_label ) :
									?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="arvaseo-inline-form"<?php echo 'regenerate' === $arvaseo_do ? ' data-arvaseo-confirm="confirmCssRegenerate"' : ''; ?>>
										<input type="hidden" name="action" value="arvaseo_connector_css" />
										<input type="hidden" name="do" value="<?php echo esc_attr( $arvaseo_do ); ?>" />
										<input type="hidden" name="fix" value="<?php echo esc_attr( $arvaseo_id ); ?>" />
										<?php wp_nonce_field( 'arvaseo_connector_css' ); ?>
										<button type="submit" class="arvaseo-btn arvaseo-btn--sm"><?php echo esc_html( $arvaseo_label ); ?></button>
									</form>
								<?php endforeach; ?>
							</div>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="arvaseo_connector_speed_options" />
								<input type="hidden" name="fix" value="<?php echo esc_attr( $arvaseo_id ); ?>" />
								<?php wp_nonce_field( 'arvaseo_connector_speed_options' ); ?>
								<label class="arvaseo-label" for="arvaseo-css-exclude-<?php echo esc_attr( $arvaseo_id ); ?>"><?php esc_html_e( 'Leave these stylesheets alone', 'arvaseo-connector' ); ?></label>
								<p class="arvaseo-hint"><?php esc_html_e( 'One stylesheet name (handle) per line; they load as before. Both CSS fixes share this list.', 'arvaseo-connector' ); ?></p>
								<textarea id="arvaseo-css-exclude-<?php echo esc_attr( $arvaseo_id ); ?>" name="css_exclude" rows="2" placeholder="slider-styles"><?php echo esc_textarea( implode( "\n", $arvaseo_stored['css_exclude'] ) ); ?></textarea>
								<div class="arvaseo-row" style="margin-top: 10px"><button type="submit" class="arvaseo-btn arvaseo-btn--sm"><?php esc_html_e( 'Save', 'arvaseo-connector' ); ?></button></div>
							</form>
							<p class="arvaseo-hint" style="margin-top: 10px"><?php esc_html_e( 'Arva SEO loads your public pages like a visitor, as a phone and as a computer, to see which styles they use. Only page addresses are sent, never content or visitor data.', 'arvaseo-connector' ); ?></p>
						</div>
					</details>
				<?php endif; ?>

				<?php if ( 'webp_uploads' === $arvaseo_id ) : ?>
					<?php $arvaseo_webp = \ArvaSEO\Connector\Speed\WebpLibrary::progress(); ?>
					<details class="arvaseo-more" <?php echo $arvaseo_webp['running'] ? 'open' : ''; ?>>
						<summary><?php esc_html_e( 'Convert your existing images too', 'arvaseo-connector' ); ?></summary>
						<div class="arvaseo-more-in">
							<p class="arvaseo-hint"><?php esc_html_e( 'Each JPEG and PNG in your media library, and each of its sizes, gets a WebP copy beside it, kept only when it’s smaller. Your originals stay as they are, and Undo removes every copy. It runs in the background, a few images at a time.', 'arvaseo-connector' ); ?></p>
							<?php if ( $arvaseo_webp['total'] > 0 ) : ?>
								<p>
									<progress max="<?php echo esc_attr( (string) $arvaseo_webp['total'] ); ?>" value="<?php echo esc_attr( (string) $arvaseo_webp['done'] ); ?>"></progress>
									<?php
									printf(
										/* translators: 1: images done, 2: all images, 3: space saved, such as 2 MB. */
										esc_html__( '%1$d of %2$d images, %3$s smaller so far.', 'arvaseo-connector' ),
										(int) $arvaseo_webp['done'],
										(int) $arvaseo_webp['total'],
										esc_html( size_format( $arvaseo_webp['saved_bytes'] ) ? (string) size_format( $arvaseo_webp['saved_bytes'] ) : '0 B' )
									);
									echo $arvaseo_webp['running'] ? ' ' . esc_html__( 'Converting…', 'arvaseo-connector' ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
									?>
								</p>
							<?php endif; ?>
							<div class="arvaseo-row">
								<?php
								foreach ( array_filter(
									array(
										'start' => ! $arvaseo_webp['running'] && 'on' === $arvaseo_state,
										'stop'  => $arvaseo_webp['running'],
										'undo'  => $arvaseo_webp['done'] > 0,
									)
								) as $arvaseo_do => $arvaseo_show ) :
									?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="arvaseo-inline-form"<?php echo 'undo' === $arvaseo_do ? ' data-arvaseo-confirm="confirmWebpUndo"' : ''; ?>>
										<input type="hidden" name="action" value="arvaseo_connector_webp" />
										<input type="hidden" name="do" value="<?php echo esc_attr( $arvaseo_do ); ?>" />
										<?php wp_nonce_field( 'arvaseo_connector_webp' ); ?>
										<button type="submit" class="arvaseo-btn arvaseo-btn--sm">
											<?php
											$arvaseo_labels = array(
												'start' => $arvaseo_webp['done'] > 0 && null === $arvaseo_webp['finished_at'] ? __( 'Continue', 'arvaseo-connector' ) : ( null !== $arvaseo_webp['finished_at'] ? __( 'Convert images added since', 'arvaseo-connector' ) : __( 'Convert existing images', 'arvaseo-connector' ) ),
												'stop'  => __( 'Stop', 'arvaseo-connector' ),
												'undo'  => __( 'Undo: remove the copies', 'arvaseo-connector' ),
											);
											echo esc_html( $arvaseo_labels[ $arvaseo_do ] );
											?>
										</button>
									</form>
								<?php endforeach; ?>
								<?php if ( 'on' !== $arvaseo_state ) : ?>
									<span class="arvaseo-hint"><?php esc_html_e( 'Turn on New uploads as WebP first.', 'arvaseo-connector' ); ?></span>
								<?php endif; ?>
							</div>
						</div>
					</details>
				<?php endif; ?>

				<?php if ( 'delay_scripts' === $arvaseo_id ) : ?>
					<details class="arvaseo-more">
						<summary><?php esc_html_e( 'The widgets it delays, and others to delay', 'arvaseo-connector' ); ?></summary>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="arvaseo-more-in">
							<input type="hidden" name="action" value="arvaseo_connector_speed_options" />
							<input type="hidden" name="fix" value="delay_scripts" />
							<?php wp_nonce_field( 'arvaseo_connector_speed_options' ); ?>
							<p class="arvaseo-hint"><?php esc_html_e( 'Tawk.to, Crisp, Intercom, LiveChat, Tidio, Zendesk, Drift and HubSpot chat; Hotjar and Microsoft Clarity; Facebook, X (Twitter) and Pinterest widgets; Elfsight widgets. Only scripts your theme or plugins add the WordPress way can be delayed.', 'arvaseo-connector' ); ?></p>
							<label class="arvaseo-label" for="arvaseo-delay-scripts" style="margin-top: 10px"><?php esc_html_e( 'Also delay these scripts', 'arvaseo-connector' ); ?></label>
							<p class="arvaseo-hint"><?php esc_html_e( 'One per line: a script name (handle) or part of its file address. A script that other scripts need is never delayed. To keep a widget loading straight away on some pages, leave those pages alone below.', 'arvaseo-connector' ); ?></p>
							<textarea id="arvaseo-delay-scripts" name="delay_scripts" rows="3" placeholder="reviews-widget&#10;widget.example.com"><?php echo esc_textarea( implode( "\n", $arvaseo_stored['delay_scripts'] ) ); ?></textarea>
							<div class="arvaseo-row" style="margin-top: 10px"><button type="submit" class="arvaseo-btn arvaseo-btn--sm"><?php esc_html_e( 'Save', 'arvaseo-connector' ); ?></button></div>
						</form>
					</details>
				<?php endif; ?>

				<?php if ( 'google_fonts' === $arvaseo_id ) : ?>
					<details class="arvaseo-more" <?php echo $arvaseo_stored['fonts_local'] ? 'open' : ''; ?>>
						<summary><?php esc_html_e( 'Serve them from your site', 'arvaseo-connector' ); ?></summary>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="arvaseo-more-in">
							<input type="hidden" name="action" value="arvaseo_connector_speed_options" />
							<input type="hidden" name="fix" value="google_fonts" />
							<?php wp_nonce_field( 'arvaseo_connector_speed_options' ); ?>
							<label class="arvaseo-toggle"><input type="checkbox" name="fonts_local" value="1" <?php checked( $arvaseo_stored['fonts_local'] ); ?> /><span class="arvaseo-switch" aria-hidden="true"></span>
								<span><strong><?php esc_html_e( 'Serve them from your site', 'arvaseo-connector' ); ?></strong><br /><span class="arvaseo-hint"><?php esc_html_e( 'Copies of the fonts your pages use are kept in your uploads folder and used instead of Google’s, so browsers don’t connect to Google for them (better for privacy too). Each copy is made in the background the first time a page needs it; until then Google’s fonts are used.', 'arvaseo-connector' ); ?></span>
								<?php if ( $arvaseo_stored['fonts_local'] && \ArvaSEO\Connector\Speed\LocalFonts::copied() > 0 ) : ?>
									<br /><span class="arvaseo-hint">
										<?php
										$arvaseo_copies = \ArvaSEO\Connector\Speed\LocalFonts::copied();
										/* translators: %d: number of Google Fonts stylesheets copied. */
										printf( esc_html( _n( '%d font stylesheet is served from your site.', '%d font stylesheets are served from your site.', $arvaseo_copies, 'arvaseo-connector' ) ), (int) $arvaseo_copies );
										?>
									</span>
								<?php endif; ?>
								</span>
							</label>
							<div class="arvaseo-row" style="margin-top: 12px"><button type="submit" class="arvaseo-btn arvaseo-btn--sm"><?php esc_html_e( 'Save', 'arvaseo-connector' ); ?></button></div>
						</form>
					</details>
				<?php endif; ?>

				<?php if ( 'defer_scripts' === $arvaseo_id ) : ?>
					<details class="arvaseo-more" <?php echo 'trying' === $arvaseo_state ? 'open' : ''; ?>>
						<summary><?php esc_html_e( 'Leave scripts alone', 'arvaseo-connector' ); ?></summary>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="arvaseo-more-in">
							<input type="hidden" name="action" value="arvaseo_connector_speed_options" />
							<input type="hidden" name="fix" value="defer_scripts" />
							<?php wp_nonce_field( 'arvaseo_connector_speed_options' ); ?>
							<?php if ( array() === $arvaseo_deferred && 'trying' === $arvaseo_state ) : ?>
								<p class="arvaseo-hint"><?php esc_html_e( 'Open your homepage while trying it: the scripts it defers are listed here, so you can leave one alone if something stops working.', 'arvaseo-connector' ); ?></p>
							<?php elseif ( array() !== $arvaseo_deferred ) : ?>
								<p><?php esc_html_e( 'Scripts it deferred on the last page you opened while trying it:', 'arvaseo-connector' ); ?></p>
								<ul class="arvaseo-defer-list">
									<?php foreach ( $arvaseo_deferred as $arvaseo_handle => $arvaseo_host ) : ?>
										<li>
											<code><?php echo esc_html( (string) $arvaseo_handle ); ?></code> <span class="arvaseo-hint"><?php echo esc_html( (string) $arvaseo_host ); ?></span>
											<?php if ( in_array( (string) $arvaseo_handle, $arvaseo_stored['defer_exclude'], true ) ) : ?>
												<span class="arvaseo-hint">· <?php esc_html_e( 'Left alone', 'arvaseo-connector' ); ?></span>
											<?php else : ?>
												<button type="submit" name="leave_alone" value="<?php echo esc_attr( (string) $arvaseo_handle ); ?>" class="arvaseo-link-btn">
													<?php esc_html_e( 'Leave alone', 'arvaseo-connector' ); ?><span class="screen-reader-text"> <?php echo esc_html( (string) $arvaseo_handle ); ?></span>
												</button>
											<?php endif; ?>
										</li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
							<label class="arvaseo-label" for="arvaseo-defer-exclude" style="margin-top: 10px"><?php esc_html_e( 'Leave these scripts alone', 'arvaseo-connector' ); ?></label>
							<p class="arvaseo-hint"><?php esc_html_e( 'One per line: a script name (handle), such as one from the list above, or part of its file address, such as booking.js or /plugins/contact-form-7/. jQuery and consent or cookie tools are always left alone.', 'arvaseo-connector' ); ?></p>
							<textarea id="arvaseo-defer-exclude" name="defer_exclude" rows="3"><?php echo esc_textarea( implode( "\n", $arvaseo_stored['defer_exclude'] ) ); ?></textarea>
							<?php if ( array() !== $arvaseo_plugins ) : ?>
								<details class="arvaseo-more" <?php echo array() !== $arvaseo_stored['defer_plugins'] ? 'open' : ''; ?>>
									<summary>
										<?php
										echo esc_html(
											array() === $arvaseo_stored['defer_plugins']
												? __( 'Leave every script from a plugin alone', 'arvaseo-connector' )
												/* translators: %d: number of plugins chosen. */
												: sprintf( _n( 'Leave every script from a plugin alone (%d chosen)', 'Leave every script from a plugin alone (%d chosen)', count( $arvaseo_stored['defer_plugins'] ), 'arvaseo-connector' ), count( $arvaseo_stored['defer_plugins'] ) )
										);
										?>
									</summary>
									<fieldset class="arvaseo-plugins-list arvaseo-checks" style="flex-direction: column">
										<legend class="screen-reader-text"><?php esc_html_e( 'Plugins whose scripts Defer scripts leaves alone', 'arvaseo-connector' ); ?></legend>
										<?php foreach ( $arvaseo_plugins as $arvaseo_slug => $arvaseo_name ) : ?>
											<label><input type="checkbox" name="defer_plugins[]" value="<?php echo esc_attr( $arvaseo_slug ); ?>" <?php checked( in_array( $arvaseo_slug, $arvaseo_stored['defer_plugins'], true ) ); ?> /> <span><?php echo esc_html( $arvaseo_name ); ?></span></label>
										<?php endforeach; ?>
									</fieldset>
								</details>
							<?php endif; ?>
							<div class="arvaseo-row" style="margin-top: 10px"><button type="submit" class="arvaseo-btn arvaseo-btn--sm"><?php esc_html_e( 'Save', 'arvaseo-connector' ); ?></button></div>
						</form>
					</details>
					<?php if ( 'trying' === $arvaseo_state ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="arvaseo-checklist" data-arvaseo-confirm="confirmDefer">
							<input type="hidden" name="action" value="arvaseo_connector_speed" />
							<input type="hidden" name="fix" value="defer_scripts" />
							<input type="hidden" name="to" value="on" />
							<?php wp_nonce_field( 'arvaseo_connector_speed' ); ?>
							<fieldset>
								<legend><?php esc_html_e( 'Before turning it on for everyone, check these while trying it:', 'arvaseo-connector' ); ?></legend>
								<label class="arvaseo-check"><input type="checkbox" name="checked[]" value="home" required /> <span><?php esc_html_e( 'My homepage looks and works as before', 'arvaseo-connector' ); ?></span></label>
								<label class="arvaseo-check"><input type="checkbox" name="checked[]" value="forms" required /> <span><?php esc_html_e( 'Contact or booking forms, and the checkout if I sell online, still work', 'arvaseo-connector' ); ?></span></label>
								<label class="arvaseo-check"><input type="checkbox" name="checked[]" value="menus" required /> <span><?php esc_html_e( 'Menus, sliders, pop-ups and chat still work, on a phone too', 'arvaseo-connector' ); ?></span></label>
							</fieldset>
							<button type="submit" class="arvaseo-btn arvaseo-btn--sm arvaseo-btn--pri"><?php esc_html_e( 'Turn on for everyone', 'arvaseo-connector' ); ?></button>
						</form>
					<?php endif; ?>
				<?php endif; ?>
			<?php endif; ?>

			<?php if ( ! in_array( $arvaseo_id, array( 'webp_uploads', 'browser_caching' ), true ) ) : ?>
				<div class="arvaseo-fix-links">
					<a href="#arvaseo-speed-exclusions"><?php echo in_array( $arvaseo_id, Exclusions::ELEMENT_FIXES, true ) ? esc_html__( 'Leave a page, image or video alone', 'arvaseo-connector' ) : esc_html__( 'Leave a page alone', 'arvaseo-connector' ); ?></a>
				</div>
			<?php endif; ?>
		</div>

			<?php if ( 'handled' !== $arvaseo_state && 'unavailable' !== $arvaseo_state ) : ?>
			<div class="arvaseo-fix-acts">
				<?php
				if ( 'off' === $arvaseo_state && $arvaseo_tryable ) {
					$arvaseo_button( $arvaseo_id, 'trying', __( 'Try it', 'arvaseo-connector' ), 'arvaseo-btn arvaseo-btn--sm arvaseo-btn--dark' );
				}
				if ( 'defer_scripts' === $arvaseo_id && 'trying' === $arvaseo_state ) {
					echo '<span class="arvaseo-hint arvaseo-fix-acts-note">' . esc_html__( 'Turn it on after the checks below.', 'arvaseo-connector' ) . '</span>';
				} elseif ( 'on' !== $arvaseo_state ) {
					$arvaseo_button(
						$arvaseo_id,
						'on',
						'trying' === $arvaseo_state || ! $arvaseo_tryable ? __( 'Turn on for everyone', 'arvaseo-connector' ) : __( 'Turn on', 'arvaseo-connector' ),
						'trying' === $arvaseo_state ? 'arvaseo-btn arvaseo-btn--sm arvaseo-btn--pri' : ( $arvaseo_tryable ? 'arvaseo-btn arvaseo-btn--sm' : 'arvaseo-btn arvaseo-btn--sm arvaseo-btn--dark' ),
						'check' === $arvaseo_fix['risk'] ? 'confirmRisky' : ''
					);
				}
				if ( 'off' !== $arvaseo_state ) {
					$arvaseo_button( $arvaseo_id, 'off', 'trying' === $arvaseo_state ? __( 'Stop trying', 'arvaseo-connector' ) : __( 'Turn off', 'arvaseo-connector' ), 'trying' === $arvaseo_state ? 'arvaseo-btn arvaseo-btn--sm arvaseo-btn--ghost' : 'arvaseo-btn arvaseo-btn--sm' );
				}
				?>
			</div>
		<?php endif; ?>
	</section>
		<?php endforeach; ?>
</div></div>
	<?php endforeach; ?>

<div class="arvaseo-grp" id="arvaseo-cloudflare">
	<span class="arvaseo-grp-n" aria-hidden="true"><?php echo esc_html( sprintf( '%02d', $arvaseo_n + 1 ) ); ?></span>
	<div><h2><?php esc_html_e( 'Cloudflare', 'arvaseo-connector' ); ?></h2><p><?php esc_html_e( 'Visitors get your changes straight away.', 'arvaseo-connector' ); ?></p></div>
</div>
	<?php
	$arvaseo_cf      = Cloudflare::details();
	$arvaseo_cf_form = static function ( string $step, string $label, string $classes, string $confirm = '' ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="arvaseo-inline-form"' . ( '' !== $confirm ? ' data-arvaseo-confirm="' . esc_attr( $confirm ) . '"' : '' ) . '>';
		echo '<input type="hidden" name="action" value="arvaseo_connector_cloudflare" />';
		echo '<input type="hidden" name="do" value="' . esc_attr( $step ) . '" />';
		wp_nonce_field( 'arvaseo_connector_cloudflare' );
		echo '<button type="submit" class="' . esc_attr( $classes ) . '">' . esc_html( $label ) . '</button>';
		echo '</form>';
	};
	?>
<section class="arvaseo-card" aria-labelledby="arvaseo-cf-heading">
	<?php if ( Cloudflare::connected() ) : ?>
		<div class="arvaseo-between">
			<div style="flex: 999 1 420px">
				<p class="arvaseo-eyebrow"><?php esc_html_e( 'Cloudflare is connected', 'arvaseo-connector' ); ?></p>
				<h2 class="arvaseo-h2" id="arvaseo-cf-heading"><?php echo esc_html( (string) $arvaseo_cf['zone_name'] ); ?></h2>
				<p class="arvaseo-hint" style="max-width: 680px"><?php esc_html_e( 'Cloudflare’s copy of every page is cleared when a fix goes on or off for visitors, when your choices here change, and when new optimized CSS, WebP copies or local fonts are ready.', 'arvaseo-connector' ); ?></p>
			</div>
			<?php $arvaseo_cf_form( 'purge', __( 'Clear Cloudflare’s copy now', 'arvaseo-connector' ), 'arvaseo-btn arvaseo-btn--sm arvaseo-btn--dark' ); ?>
		</div>
		<?php if ( $arvaseo_cf['refused'] ) : ?>
			<div class="arvaseo-alert" role="alert" style="margin-top: 16px">
				<div>
					<h2><?php esc_html_e( 'Cloudflare refused the saved token', 'arvaseo-connector' ); ?></h2>
					<p style="margin: 4px 0 0"><?php esc_html_e( 'It may have been deleted, have expired or lost a permission. Disconnect, then connect again with a new token.', 'arvaseo-connector' ); ?></p>
				</div>
			</div>
		<?php endif; ?>
		<dl class="arvaseo-facts">
			<div><dt><?php esc_html_e( 'Last cleared', 'arvaseo-connector' ); ?></dt><dd>
				<?php
				if ( null === $arvaseo_cf['last'] ) {
					esc_html_e( 'Not yet', 'arvaseo-connector' );
				} else {
					/* translators: %d: number of pages. */
					$arvaseo_cf_what = 'everything' === $arvaseo_cf['last']['what'] ? __( 'Every page', 'arvaseo-connector' ) : sprintf( _n( '%d page', '%d pages', $arvaseo_cf['last']['count'], 'arvaseo-connector' ), $arvaseo_cf['last']['count'] );
					echo esc_html( AdminController::when( $arvaseo_cf['last']['at'] ) ) . ' ';
					echo AdminController::badge( $arvaseo_cf['last']['ok'] ? 'good' : 'bad', $arvaseo_cf['last']['ok'] ? $arvaseo_cf_what : __( 'Failed', 'arvaseo-connector' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
					if ( null !== $arvaseo_cf['last']['error'] ) {
						echo '<br /><span class="arvaseo-hint">' . esc_html( $arvaseo_cf['last']['error'] ) . '</span>';
					}
				}
				?>
			</dd></div>
			<div><dt><?php esc_html_e( 'Rocket Loader', 'arvaseo-connector' ); ?></dt><dd>
				<?php
				if ( 'on' === $arvaseo_cf['rocket_loader'] ) {
					echo AdminController::badge( Cloudflare::rocket_loader_clash() ? 'warn' : 'neutral', __( 'On', 'arvaseo-connector' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
				} elseif ( 'off' === $arvaseo_cf['rocket_loader'] ) {
					echo AdminController::badge( 'good', __( 'Off', 'arvaseo-connector' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
				} else {
					echo AdminController::badge( 'neutral', __( 'Unknown', 'arvaseo-connector' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
					echo '<br /><span class="arvaseo-hint">' . esc_html__( 'Give the token Zone Settings: Read to check it.', 'arvaseo-connector' ) . '</span>';
				}
				?>
			</dd></div>
			<div><dt><?php esc_html_e( 'Checked', 'arvaseo-connector' ); ?></dt><dd><?php echo esc_html( AdminController::when( $arvaseo_cf['checked_at'] ) ); ?></dd></div>
		</dl>
		<?php if ( Cloudflare::rocket_loader_clash() ) : ?>
			<p class="arvaseo-note" style="margin-top: 16px; background: var(--a-warn-soft); color: #4d3800"><?php esc_html_e( 'Rocket Loader changes how scripts load, as Defer scripts and Delay chat widgets and trackers do here. Using both can stop menus, forms or checkouts working. Keep the fixes here and turn Rocket Loader off in your Cloudflare dashboard, or turn these fixes off.', 'arvaseo-connector' ); ?></p>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="arvaseo-between" style="align-items: center; margin-top: 18px; padding-top: 18px; border-top: 1px solid var(--a-border)">
			<input type="hidden" name="action" value="arvaseo_connector_cloudflare" />
			<input type="hidden" name="do" value="options" />
			<?php wp_nonce_field( 'arvaseo_connector_cloudflare' ); ?>
			<label class="arvaseo-toggle" style="flex: 999 1 420px">
				<input type="checkbox" name="clear_posts" value="1" <?php checked( $arvaseo_cf['posts'] ); ?> />
				<span class="arvaseo-switch" aria-hidden="true"></span>
				<span><strong><?php esc_html_e( 'Also clear a post’s pages when it’s published, updated or unpublished', 'arvaseo-connector' ); ?></strong><br /><span class="arvaseo-hint"><?php esc_html_e( 'The post, the homepage, the blog page, the feed and its category and tag pages. Leave this off if Cloudflare’s own plugin already does it.', 'arvaseo-connector' ); ?></span></span>
			</label>
			<button type="submit" class="arvaseo-btn arvaseo-btn--sm"><?php esc_html_e( 'Save', 'arvaseo-connector' ); ?></button>
		</form>
		<div class="arvaseo-row" style="margin-top: 16px">
			<?php
			$arvaseo_cf_form( 'check', __( 'Check again', 'arvaseo-connector' ), 'arvaseo-btn arvaseo-btn--sm' );
			$arvaseo_cf_form( 'disconnect', __( 'Disconnect Cloudflare', 'arvaseo-connector' ), 'arvaseo-btn arvaseo-btn--sm arvaseo-btn--ghost', 'confirmCloudflareOff' );
			?>
		</div>
	<?php else : ?>
		<p class="arvaseo-eyebrow"><?php echo Cloudflare::seen() ? esc_html__( 'Seen in front of this website', 'arvaseo-connector' ) : esc_html__( 'Optional', 'arvaseo-connector' ); ?></p>
		<h2 class="arvaseo-h2" id="arvaseo-cf-heading"><?php echo Cloudflare::seen() ? esc_html__( 'This website is behind Cloudflare', 'arvaseo-connector' ) : esc_html__( 'Using Cloudflare?', 'arvaseo-connector' ); ?></h2>
		<p class="arvaseo-hint" style="max-width: 720px"><?php esc_html_e( 'Cloudflare can keep copies of your pages. Connect it here and those copies are cleared whenever a change here affects what visitors get, so visitors and your next PageSpeed test see a fix as soon as you turn it on. It works without an Arva SEO account. Only page addresses are sent, to Cloudflare.', 'arvaseo-connector' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top: 16px">
			<input type="hidden" name="action" value="arvaseo_connector_cloudflare" />
			<input type="hidden" name="do" value="connect" />
			<?php wp_nonce_field( 'arvaseo_connector_cloudflare' ); ?>
			<ol>
				<li><?php esc_html_e( 'In your Cloudflare dashboard, open My Profile → API Tokens → Create Token → Create Custom Token.', 'arvaseo-connector' ); ?></li>
				<li><?php esc_html_e( 'Permissions: Zone → Zone → Read, Zone → Cache Purge → Purge, and Zone → Zone Settings → Read (so Rocket Loader can be checked).', 'arvaseo-connector' ); ?></li>
				<li>
					<?php
					/* translators: %s: this website's domain. */
					printf( esc_html__( 'Zone Resources: Include → Specific zone → %s. Create the token and paste it below.', 'arvaseo-connector' ), esc_html( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
					?>
				</li>
			</ol>
			<label class="arvaseo-label" for="arvaseo-cf-token"><?php esc_html_e( 'Cloudflare API token', 'arvaseo-connector' ); ?> <span class="arvaseo-req" aria-hidden="true">*</span></label>
			<input type="password" id="arvaseo-cf-token" name="cloudflare_token" required="required" autocomplete="off" spellcheck="false" aria-describedby="arvaseo-cf-token-hint" />
			<p class="arvaseo-hint" id="arvaseo-cf-token-hint" style="margin-top: 6px"><?php esc_html_e( 'Kept encrypted on this site and only ever sent to Cloudflare. A token, not the Global API Key.', 'arvaseo-connector' ); ?></p>
			<div class="arvaseo-row" style="margin-top: 12px"><button type="submit" class="arvaseo-btn arvaseo-btn--dark"><?php esc_html_e( 'Connect Cloudflare', 'arvaseo-connector' ); ?></button></div>
		</form>
	<?php endif; ?>
</section>

<div class="arvaseo-grp" id="arvaseo-speed-exclusions">
	<span class="arvaseo-grp-n" aria-hidden="true"><?php echo esc_html( sprintf( '%02d', $arvaseo_n + 2 ) ); ?></span>
	<div><h2><?php esc_html_e( 'Leave things alone', 'arvaseo-connector' ); ?></h2><p><?php esc_html_e( 'If a fix stops something working on part of your site, leave that part alone instead of turning the fix off everywhere.', 'arvaseo-connector' ); ?></p></div>
</div>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<input type="hidden" name="action" value="arvaseo_connector_speed_options" />
	<input type="hidden" name="fix" value="exclusions" />
	<?php wp_nonce_field( 'arvaseo_connector_speed_options' ); ?>
	<div class="arvaseo-grid2">
		<section class="arvaseo-card">
			<label class="arvaseo-label" for="arvaseo-exclude-pages"><?php esc_html_e( 'Pages', 'arvaseo-connector' ); ?></label>
			<p class="arvaseo-hint"><?php esc_html_e( 'No fix runs on these. One address per line, the part after your domain, such as /contact/. A * matches anything: /shop/* is every page under /shop/. You can also tick “Leave this page alone” when you edit a post or page, or use Arva SEO in the toolbar while you view any page.', 'arvaseo-connector' ); ?></p>
			<textarea id="arvaseo-exclude-pages" name="exclude_pages" rows="4" placeholder="/checkout/&#10;/book-now/*"><?php echo esc_textarea( implode( "\n", $arvaseo_stored['exclude_pages'] ) ); ?></textarea>
			<?php $arvaseo_skipped = Exclusions::skipped_posts(); ?>
			<?php if ( $arvaseo_skipped > 0 ) : ?>
				<p class="arvaseo-hint" style="margin-top: 8px">
					<?php
					/* translators: %d: number of posts and pages. */
					printf( esc_html( _n( '%d post or page is left alone by its own tick box.', '%d posts and pages are left alone by their own tick box.', $arvaseo_skipped, 'arvaseo-connector' ) ), (int) $arvaseo_skipped );
					?>
				</p>
			<?php endif; ?>
			<?php if ( Exclusions::has_shop() ) : ?>
				<input type="hidden" name="shop_pages_shown" value="1" />
				<label class="arvaseo-check" style="margin-top: 12px"><input type="checkbox" name="shop_pages" value="1" <?php checked( $arvaseo_stored['shop_pages'] ); ?> /> <span><?php esc_html_e( 'Defer scripts leaves the cart, checkout and account pages alone', 'arvaseo-connector' ); ?><br /><span class="arvaseo-hint"><?php esc_html_e( 'Recommended: payment and account forms often need their scripts loaded in order.', 'arvaseo-connector' ); ?></span></span></label>
			<?php endif; ?>
		</section>
		<section class="arvaseo-card">
			<label class="arvaseo-label" for="arvaseo-exclude-elements"><?php esc_html_e( 'Images, videos and maps', 'arvaseo-connector' ); ?></label>
			<p class="arvaseo-hint"><?php esc_html_e( 'One per line: a CSS class starting with a dot (.hero-slide), an id starting with # (#main-banner), or part of the file’s address (logo.svg, vimeo.com). Lazy-load, Add missing image sizes and Click-to-play videos leave these alone. A block’s Additional CSS class counts for everything inside the block; in older content, put the class on the image itself.', 'arvaseo-connector' ); ?></p>
			<textarea id="arvaseo-exclude-elements" name="exclude_elements" rows="4" placeholder=".hero-slide&#10;#main-banner&#10;logo.svg"><?php echo esc_textarea( implode( "\n", $arvaseo_stored['exclude_elements'] ) ); ?></textarea>
			<label class="arvaseo-label" for="arvaseo-eager-images" style="margin-top: 14px"><?php esc_html_e( 'Images loaded straight away', 'arvaseo-connector' ); ?></label>
			<input type="number" id="arvaseo-eager-images" name="eager_images" min="0" max="<?php echo esc_attr( (string) Fixes::MAX_EAGER_IMAGES ); ?>" value="<?php echo esc_attr( (string) $arvaseo_stored['eager_images'] ); ?>" />
			<p class="arvaseo-hint"><?php esc_html_e( 'How many images at the top of each page Lazy-load never delays. 3 suits most sites; raise it if a slider at the top shows its slides late.', 'arvaseo-connector' ); ?></p>
		</section>
	</div>
	<div class="arvaseo-row" style="margin-top: 12px; gap: 16px">
		<button type="submit" class="arvaseo-btn arvaseo-btn--dark"><?php esc_html_e( 'Save what to leave alone', 'arvaseo-connector' ); ?></button>
		<span class="arvaseo-hint">
			<?php esc_html_e( 'Scripts are left alone on the Defer scripts row.', 'arvaseo-connector' ); ?>
			<a href="#<?php echo esc_attr( Fixes::anchor( 'defer_scripts' ) ); ?>"><?php esc_html_e( 'Go to Defer scripts', 'arvaseo-connector' ); ?></a>
		</span>
	</div>
</form>

	<?php if ( TokenStore::is_connected() ) : ?>
<section id="arvaseo-speed-remote" class="arvaseo-card" style="margin-top: 28px" aria-label="<?php esc_attr_e( 'Switching from Arva SEO', 'arvaseo-connector' ); ?>">
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="arvaseo-between" style="align-items: center">
		<input type="hidden" name="action" value="arvaseo_connector_speed_options" />
		<input type="hidden" name="fix" value="remote" />
		<?php wp_nonce_field( 'arvaseo_connector_speed_options' ); ?>
		<label class="arvaseo-toggle" style="flex: 999 1 480px">
			<input type="checkbox" name="remote" value="1" <?php checked( $arvaseo_stored['remote'] ); ?> />
			<span class="arvaseo-switch" aria-hidden="true"></span>
			<span><strong><?php esc_html_e( 'Let Arva SEO switch these fixes', 'arvaseo-connector' ); ?></strong><br /><span class="arvaseo-hint"><?php esc_html_e( 'People who manage this website in Arva SEO can try, turn on and turn off these fixes from its PageSpeed results, by the same rules as this page: a fix that can be tried goes on for everyone only after it’s been tried, Defer scripts goes on only here, and turning a fix off always works. Every change is in Activity with who asked.', 'arvaseo-connector' ); ?></span></span>
		</label>
		<button type="submit" class="arvaseo-btn arvaseo-btn--sm"><?php esc_html_e( 'Save', 'arvaseo-connector' ); ?></button>
	</form>
</section>
	<?php endif; ?>

<details class="arvaseo-more" style="margin-top: 20px">
	<summary><?php esc_html_e( 'For developers', 'arvaseo-connector' ); ?></summary>
	<div class="arvaseo-more-in">
		<ul class="arvaseo-bullets" style="margin: 0">
			<li><code>arvaseo_connector_speed_fix_applies</code> <span class="arvaseo-hint"><?php esc_html_e( '(bool $applies, string $fix_id): turn a fix off for any page condition.', 'arvaseo-connector' ); ?></span></li>
			<li><code>arvaseo_connector_defer_script</code> <span class="arvaseo-hint"><?php esc_html_e( '(bool $defer, string $handle, string $src): leave a script alone.', 'arvaseo-connector' ); ?></span></li>
			<li><code>arvaseo_connector_optimize_element</code> <span class="arvaseo-hint"><?php esc_html_e( '(bool $optimize, string $fix_id, string $tag, array $attributes): leave an image or frame alone.', 'arvaseo-connector' ); ?></span></li>
			<li><code>arvaseo_connector_pages_changed</code> <span class="arvaseo-hint"><?php esc_html_e( '(string $why), an action: a change here altered what visitors get on every page, so a page cache can clear its copies.', 'arvaseo-connector' ); ?></span></li>
		</ul>
		<p class="arvaseo-hint" style="margin-top: 10px"><?php esc_html_e( 'Lazy-load also leaves alone images marked skip-lazy or no-lazy, as other speed plugins do, and every fix leaves alone an element with the data-arvaseo-skip attribute.', 'arvaseo-connector' ); ?></p>
	</div>
</details>

<p class="arvaseo-hint" style="margin-top: 20px; max-width: 820px"><?php esc_html_e( 'Page caching and minifying files need a caching plugin, such as LiteSpeed Cache or WP Rocket. Arva SEO’s PageSpeed page says which setting to change in the one you have. If you use a page cache other than Cloudflare, clear it after changing a fix here. In an emergency, add define( \'ARVASEO_CONNECTOR_DISABLE_SPEED_FIXES\', true ); to wp-config.php to switch every fix off.', 'arvaseo-connector' ); ?></p>
	<?php
} )();
