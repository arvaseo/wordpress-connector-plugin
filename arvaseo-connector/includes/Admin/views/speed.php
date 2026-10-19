<?php
/**
 * PageSpeed fixes page.
 *
 * @package ArvaSEO\Connector
 */

use ArvaSEO\Connector\Admin\AdminController;
use ArvaSEO\Connector\Auth\TokenStore;
use ArvaSEO\Connector\Health\AssessmentService;
use ArvaSEO\Connector\Speed\Fixes;

defined( 'ABSPATH' ) || exit;

// Scoped so the view's variables stay local.
( static function () {

	$arvaseo_fixes    = Fixes::all();
	$arvaseo_stored   = Fixes::stored();
	$arvaseo_test     = AssessmentService::pagespeed_result();
	$arvaseo_killed   = defined( Fixes::KILL_SWITCH ) && constant( Fixes::KILL_SWITCH );
	$arvaseo_deferred = get_transient( 'arvaseo_connector_deferred' );
	$arvaseo_deferred = is_array( $arvaseo_deferred ) ? $arvaseo_deferred : array();
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

	// The fixes the latest test asks for, in the test's order.
	$arvaseo_needed = array();
	foreach ( (array) ( $arvaseo_test['audits'] ?? array() ) as $arvaseo_audit ) {
		foreach ( Fixes::for_audit( $arvaseo_audit['id'] ) as $arvaseo_id ) {
			$arvaseo_needed[ $arvaseo_id ][] = $arvaseo_audit;
		}
	}

	/**
	 * One state-change button.
	 *
	 * @param string $id      Fix ID.
	 * @param string $to      New state.
	 * @param string $label   Button label.
	 * @param string $classes Button classes.
	 * @param string $confirm Confirmation string key.
	 */
	$arvaseo_button = static function ( string $id, string $to, string $label, string $classes = 'button', string $confirm = '' ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="arvaseo-inline-form"' . ( '' !== $confirm ? ' data-arvaseo-confirm="' . esc_attr( $confirm ) . '"' : '' ) . '>';
		echo '<input type="hidden" name="action" value="arvaseo_connector_speed" />';
		echo '<input type="hidden" name="fix" value="' . esc_attr( $id ) . '" />';
		echo '<input type="hidden" name="to" value="' . esc_attr( $to ) . '" />';
		wp_nonce_field( 'arvaseo_connector_speed' );
		echo '<button type="submit" class="' . esc_attr( $classes ) . '">' . esc_html( $label ) . '</button>';
		echo '</form>';
	};
	?>
<p class="arvaseo-lead"><?php esc_html_e( 'Switch on fixes for the speed problems Google’s PageSpeed test finds. Each one is off until you turn it on, you can try it first (only you see it while you’re signed in), and turning it off puts your pages back as they were. Nothing changes in your database or theme files.', 'arvaseo-connector' ); ?></p>

	<?php if ( $arvaseo_killed ) : ?>
	<div class="notice notice-warning inline"><p><?php esc_html_e( 'Every PageSpeed fix is switched off in wp-config.php (ARVASEO_CONNECTOR_DISABLE_SPEED_FIXES). Remove that line to use them again.', 'arvaseo-connector' ); ?></p></div>
	<?php endif; ?>

<section class="card arvaseo-card arvaseo-speed-test" aria-labelledby="arvaseo-test-heading">
	<h2 id="arvaseo-test-heading"><?php esc_html_e( 'From your latest PageSpeed test', 'arvaseo-connector' ); ?></h2>
	<?php if ( null === $arvaseo_test ) : ?>
		<p>
			<?php
			echo esc_html(
				TokenStore::is_connected()
					? __( 'Run a PageSpeed test of your homepage in Arva SEO (PageSpeed, on phones). This page then shows which of these fixes it needs. The fixes work either way.', 'arvaseo-connector' )
					: __( 'Connect to Arva SEO and test your homepage there to see which of these fixes it needs. The fixes work without connecting.', 'arvaseo-connector' )
			);
			?>
		</p>
	<?php else : ?>
		<p>
			<?php
			printf(
				/* translators: 1: date, 2: PageSpeed score. */
				esc_html__( 'Tested %1$s on phones: performance score %2$s out of 100.', 'arvaseo-connector' ),
				esc_html( AdminController::when( $arvaseo_test['tested_at'] ) ),
				esc_html( null === $arvaseo_test['performance_score'] ? '—' : (string) $arvaseo_test['performance_score'] )
			);
			?>
		</p>
		<?php if ( array() === $arvaseo_needed ) : ?>
			<p><?php esc_html_e( 'None of the problems it found are ones these fixes address. Arva SEO’s PageSpeed page has the steps for the rest.', 'arvaseo-connector' ); ?></p>
		<?php else : ?>
			<ul class="arvaseo-speed-needed">
				<?php foreach ( $arvaseo_needed as $arvaseo_id => $arvaseo_audits ) : ?>
					<?php $arvaseo_state = Fixes::state( $arvaseo_id ); ?>
					<li>
						<a href="#<?php echo esc_attr( Fixes::anchor( $arvaseo_id ) ); ?>"><strong><?php echo esc_html( $arvaseo_fixes[ $arvaseo_id ]['title'] ); ?></strong></a>
						<?php echo wp_kses_post( AdminController::badge( $arvaseo_tones[ $arvaseo_state ] ?? 'neutral', Fixes::state_label( $arvaseo_state ) ) ); ?>
						<span class="description">
							<?php
							/* translators: %s: Lighthouse audit names, separated by commas. */
							printf( esc_html__( 'Fixes: %s', 'arvaseo-connector' ), esc_html( implode( ', ', array_unique( array_column( $arvaseo_audits, 'title' ) ) ) ) );
							?>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	<?php endif; ?>
</section>

	<?php foreach ( Fixes::groups() as $arvaseo_group => $arvaseo_group_title ) : ?>
	<h2 class="arvaseo-speed-group"><?php echo esc_html( $arvaseo_group_title ); ?></h2>
	<div class="arvaseo-grid">
			<?php
			foreach ( $arvaseo_fixes as $arvaseo_id => $arvaseo_fix ) :
				if ( $arvaseo_fix['group'] !== $arvaseo_group ) {
					continue;
				}
				$arvaseo_state   = Fixes::state( $arvaseo_id );
				$arvaseo_handled = Fixes::handled_by( $arvaseo_id );
				$arvaseo_since   = $arvaseo_stored['fixes'][ $arvaseo_id ]['since'];
				$arvaseo_heading = 'arvaseo-fix-heading-' . $arvaseo_id;
				?>
			<section id="<?php echo esc_attr( Fixes::anchor( $arvaseo_id ) ); ?>" class="card arvaseo-card arvaseo-speed-fix arvaseo-speed-fix--<?php echo esc_attr( $arvaseo_state ); ?>" aria-labelledby="<?php echo esc_attr( $arvaseo_heading ); ?>">
				<h3 id="<?php echo esc_attr( $arvaseo_heading ); ?>" class="arvaseo-speed-title">
					<?php echo esc_html( $arvaseo_fix['title'] ); ?>
					<?php echo wp_kses_post( AdminController::badge( $arvaseo_tones[ $arvaseo_state ] ?? 'neutral', Fixes::state_label( $arvaseo_state ) ) ); ?>
				</h3>
				<p><?php echo esc_html( $arvaseo_fix['what'] ); ?></p>
				<p class="arvaseo-speed-audits">
					<strong><?php esc_html_e( 'Fixes in PageSpeed:', 'arvaseo-connector' ); ?></strong>
					<?php echo esc_html( implode( ' · ', array_map( static fn ( string $title ) => '“' . $title . '”', array_values( $arvaseo_fix['audits'] ) ) ) ); ?>
				</p>
				<?php if ( 'check' === $arvaseo_fix['risk'] ) : ?>
					<p class="arvaseo-speed-risk"><span class="dashicons dashicons-warning" aria-hidden="true"></span> <?php esc_html_e( 'Can affect how your site works. Try it first and check your important pages.', 'arvaseo-connector' ); ?></p>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'Safe: it only changes how things load, not what visitors see.', 'arvaseo-connector' ); ?></p>
				<?php endif; ?>

				<?php if ( 'handled' === $arvaseo_state ) : ?>
					<p>
						<?php
						/* translators: %s: plugin name. */
						printf( esc_html__( '%s can do this. Turn it on there instead, so your pages aren’t processed twice.', 'arvaseo-connector' ), esc_html( (string) $arvaseo_handled ) );
						?>
					</p>
				<?php elseif ( 'unavailable' === $arvaseo_state ) : ?>
					<p><?php echo esc_html( (string) Fixes::unavailable( $arvaseo_id ) ); ?></p>
				<?php else : ?>
					<?php if ( 'trying' === $arvaseo_state ) : ?>
						<p>
							<?php esc_html_e( 'Trying it: only you see the change while you’re signed in. Visitors see your site as before.', 'arvaseo-connector' ); ?>
							<a href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open your homepage', 'arvaseo-connector' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'arvaseo-connector' ); ?></span></a>
						</p>
					<?php elseif ( 'on' === $arvaseo_state && $arvaseo_since ) : ?>
						<p class="description">
							<?php
							/* translators: %s: date and time. */
							printf( esc_html__( 'On since %s.', 'arvaseo-connector' ), esc_html( AdminController::when( $arvaseo_since ) ) );
							?>
						</p>
					<?php endif; ?>

					<?php if ( 'wp_extras' === $arvaseo_id ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="arvaseo-speed-options">
							<input type="hidden" name="action" value="arvaseo_connector_speed_options" />
							<input type="hidden" name="fix" value="wp_extras" />
							<input type="hidden" name="defer_exclude" value="<?php echo esc_attr( implode( "\n", $arvaseo_stored['defer_exclude'] ) ); ?>" />
							<?php wp_nonce_field( 'arvaseo_connector_speed_options' ); ?>
							<fieldset>
								<legend><?php esc_html_e( 'Remove these', 'arvaseo-connector' ); ?></legend>
								<?php foreach ( $arvaseo_extras as $arvaseo_key => list( $arvaseo_label, $arvaseo_desc ) ) : ?>
									<p><label><input type="checkbox" name="extras[]" value="<?php echo esc_attr( $arvaseo_key ); ?>" <?php checked( $arvaseo_stored['extras'][ $arvaseo_key ] ); ?> /> <?php echo esc_html( $arvaseo_label ); ?></label><br /><span class="description"><?php echo esc_html( $arvaseo_desc ); ?></span></p>
								<?php endforeach; ?>
							</fieldset>
							<button type="submit" class="button"><?php esc_html_e( 'Save choices', 'arvaseo-connector' ); ?></button>
						</form>
					<?php endif; ?>

					<?php if ( 'defer_scripts' === $arvaseo_id ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="arvaseo-speed-options">
							<input type="hidden" name="action" value="arvaseo_connector_speed_options" />
							<input type="hidden" name="fix" value="defer_scripts" />
							<?php foreach ( $arvaseo_stored['extras'] as $arvaseo_key => $arvaseo_on ) : ?>
								<?php if ( $arvaseo_on ) : ?>
									<input type="hidden" name="extras[]" value="<?php echo esc_attr( $arvaseo_key ); ?>" />
								<?php endif; ?>
							<?php endforeach; ?>
							<?php wp_nonce_field( 'arvaseo_connector_speed_options' ); ?>
							<?php if ( array() === $arvaseo_deferred && 'trying' === $arvaseo_state ) : ?>
								<p class="description"><?php esc_html_e( 'Open your homepage while trying it: the scripts it defers are listed here, so you can leave one alone if something stops working.', 'arvaseo-connector' ); ?></p>
							<?php elseif ( array() !== $arvaseo_deferred ) : ?>
								<p><?php esc_html_e( 'Scripts it deferred on the last page you opened while trying it:', 'arvaseo-connector' ); ?></p>
								<ul class="arvaseo-list">
									<?php foreach ( $arvaseo_deferred as $arvaseo_handle => $arvaseo_host ) : ?>
										<li><code><?php echo esc_html( (string) $arvaseo_handle ); ?></code> <span class="description"><?php echo esc_html( (string) $arvaseo_host ); ?></span></li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
							<p>
								<label for="arvaseo-defer-exclude"><strong><?php esc_html_e( 'Leave these scripts alone', 'arvaseo-connector' ); ?></strong></label><br />
								<textarea id="arvaseo-defer-exclude" name="defer_exclude" rows="3" class="large-text code"><?php echo esc_textarea( implode( "\n", $arvaseo_stored['defer_exclude'] ) ); ?></textarea>
								<span class="description"><?php esc_html_e( 'One script name (handle) per line, such as one from the list above. jQuery and consent or cookie tools are always left alone.', 'arvaseo-connector' ); ?></span>
							</p>
							<button type="submit" class="button"><?php esc_html_e( 'Save', 'arvaseo-connector' ); ?></button>
						</form>
					<?php endif; ?>

					<div class="arvaseo-actions">
						<?php
						if ( 'off' === $arvaseo_state && in_array( 'trying', $arvaseo_fix['states'], true ) ) {
							$arvaseo_button( $arvaseo_id, 'trying', __( 'Try it', 'arvaseo-connector' ), 'button button-primary' );
						}
						if ( 'defer_scripts' === $arvaseo_id && 'trying' === $arvaseo_state ) :
							?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="arvaseo-speed-checklist" data-arvaseo-confirm="confirmDefer">
								<input type="hidden" name="action" value="arvaseo_connector_speed" />
								<input type="hidden" name="fix" value="defer_scripts" />
								<input type="hidden" name="to" value="on" />
								<?php wp_nonce_field( 'arvaseo_connector_speed' ); ?>
								<fieldset>
									<legend><strong><?php esc_html_e( 'Before turning it on for everyone, check these while trying it:', 'arvaseo-connector' ); ?></strong></legend>
									<p><label><input type="checkbox" name="checked[]" value="home" required /> <?php esc_html_e( 'My homepage looks and works as before', 'arvaseo-connector' ); ?></label></p>
									<p><label><input type="checkbox" name="checked[]" value="forms" required /> <?php esc_html_e( 'Contact or booking forms, and the checkout if I sell online, still work', 'arvaseo-connector' ); ?></label></p>
									<p><label><input type="checkbox" name="checked[]" value="menus" required /> <?php esc_html_e( 'Menus, sliders, pop-ups and chat still work, on a phone too', 'arvaseo-connector' ); ?></label></p>
								</fieldset>
								<button type="submit" class="button button-primary"><?php esc_html_e( 'Turn on for everyone', 'arvaseo-connector' ); ?></button>
							</form>
							<?php
						elseif ( 'on' !== $arvaseo_state ) :
							$arvaseo_button( $arvaseo_id, 'on', 'trying' === $arvaseo_state ? __( 'Turn on for everyone', 'arvaseo-connector' ) : __( 'Turn on', 'arvaseo-connector' ), 'trying' === $arvaseo_state ? 'button button-primary' : 'button', 'check' === $arvaseo_fix['risk'] ? 'confirmRisky' : '' );
						endif;
						if ( 'off' !== $arvaseo_state ) {
							$arvaseo_button( $arvaseo_id, 'off', 'trying' === $arvaseo_state ? __( 'Stop trying', 'arvaseo-connector' ) : __( 'Turn off', 'arvaseo-connector' ) );
						}
						?>
					</div>
				<?php endif; ?>
			</section>
			<?php endforeach; ?>
	</div>
	<?php endforeach; ?>

<p class="description arvaseo-speed-foot"><?php esc_html_e( 'Page caching, removing unused CSS and minifying files need a caching plugin, such as LiteSpeed Cache or WP Rocket. Arva SEO’s PageSpeed page says which setting to change in the one you have. If you use a page cache, clear it after changing a fix here. In an emergency, add define( \'ARVASEO_CONNECTOR_DISABLE_SPEED_FIXES\', true ); to wp-config.php to switch every fix off.', 'arvaseo-connector' ); ?></p>
	<?php
} )();
