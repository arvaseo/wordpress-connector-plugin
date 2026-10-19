<?php
/**
 * PageSpeed fixes page.
 *
 * @package ArvaSEO\Connector
 */

use ArvaSEO\Connector\Admin\AdminController;
use ArvaSEO\Connector\Auth\TokenStore;
use ArvaSEO\Connector\Health\AssessmentService;
use ArvaSEO\Connector\Speed\Exclusions;
use ArvaSEO\Connector\Speed\Fixes;
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
<p class="arvaseo-lead"><?php esc_html_e( 'Switch on fixes for the speed problems Google’s PageSpeed test finds. Each one is off until you turn it on, most can be tried first (only you see them while you’re signed in), and turning one off puts your pages back as they were. Your theme files and content are never changed; the few fixes that add files say so on their card.', 'arvaseo-connector' ); ?></p>

	<?php if ( $arvaseo_killed ) : ?>
	<div class="notice notice-warning inline"><p><?php esc_html_e( 'Every PageSpeed fix is switched off in wp-config.php (ARVASEO_CONNECTOR_DISABLE_SPEED_FIXES). Remove that line to use them again.', 'arvaseo-connector' ); ?></p></div>
	<?php endif; ?>

	<?php if ( TokenStore::is_connected() ) : ?>
	<section id="arvaseo-speed-remote" class="card arvaseo-card arvaseo-speed-test" aria-labelledby="arvaseo-remote-heading">
		<h2 id="arvaseo-remote-heading"><?php esc_html_e( 'Switching from Arva SEO', 'arvaseo-connector' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="arvaseo_connector_speed_options" />
			<input type="hidden" name="fix" value="remote" />
			<?php wp_nonce_field( 'arvaseo_connector_speed_options' ); ?>
			<p>
				<label><input type="checkbox" name="remote" value="1" <?php checked( $arvaseo_stored['remote'] ); ?> /> <strong><?php esc_html_e( 'Let Arva SEO switch these fixes', 'arvaseo-connector' ); ?></strong></label><br />
				<span class="description"><?php esc_html_e( 'People who manage this website in Arva SEO can try, turn on and turn off these fixes from its PageSpeed results, by the same rules as this page: a fix that can be tried goes on for everyone only after it’s been tried, Defer scripts goes on only here, and turning a fix off always works. Each change is in the Activity Log with who asked.', 'arvaseo-connector' ); ?></span>
			</p>
			<button type="submit" class="button"><?php esc_html_e( 'Save', 'arvaseo-connector' ); ?></button>
		</form>
	</section>
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
					<p class="arvaseo-speed-risk"><span class="dashicons dashicons-warning" aria-hidden="true"></span>
						<?php
						echo esc_html(
							in_array( 'trying', $arvaseo_fix['states'], true )
								? __( 'Can affect how your site works. Try it first and check your important pages.', 'arvaseo-connector' )
								: __( 'Changes your server’s settings for every visitor at once. Check your important pages after turning it on.', 'arvaseo-connector' )
						);
						?>
					</p>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'Safe: it only changes how things load, not what visitors see.', 'arvaseo-connector' ); ?></p>
				<?php endif; ?>
				<?php if ( in_array( $arvaseo_id, Exclusions::ELEMENT_FIXES, true ) ) : ?>
					<p class="description"><a href="#arvaseo-speed-exclusions"><?php esc_html_e( 'Leave a page, image or video alone', 'arvaseo-connector' ); ?></a></p>
				<?php elseif ( ! in_array( $arvaseo_id, array( 'webp_uploads', 'defer_scripts', 'browser_caching' ), true ) ) : ?>
					<p class="description"><a href="#arvaseo-speed-exclusions"><?php esc_html_e( 'Leave a page alone', 'arvaseo-connector' ); ?></a></p>
				<?php endif; ?>

				<?php if ( 'handled' === $arvaseo_state ) : ?>
					<p>
						<?php
						/* translators: %s: plugin name. */
						printf( esc_html__( '%s can do this. Turn it on there instead, so your pages aren’t processed twice.', 'arvaseo-connector' ), esc_html( (string) $arvaseo_handled ) );
						?>
					</p>
					<?php if ( 'browser_caching' === $arvaseo_id && ServerRules::present() ) : ?>
						<div class="arvaseo-actions"><?php $arvaseo_button( $arvaseo_id, 'off', __( 'Remove Arva SEO’s rules', 'arvaseo-connector' ) ); ?></div>
					<?php endif; ?>
				<?php elseif ( 'unavailable' === $arvaseo_state ) : ?>
					<p><?php echo esc_html( (string) Fixes::unavailable( $arvaseo_id ) ); ?></p>
					<?php if ( 'browser_caching' === $arvaseo_id ) : ?>
						<details>
							<summary><?php esc_html_e( 'The rules for your host', 'arvaseo-connector' ); ?></summary>
							<p class="description"><?php esc_html_e( 'nginx:', 'arvaseo-connector' ); ?></p>
							<pre class="arvaseo-code"><?php echo esc_html( ServerRules::nginx() ); ?></pre>
							<p class="description"><?php esc_html_e( 'Apache or LiteSpeed (.htaccess):', 'arvaseo-connector' ); ?></p>
							<pre class="arvaseo-code"><?php echo esc_html( implode( "\n", ServerRules::lines() ) ); ?></pre>
						</details>
					<?php endif; ?>
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

					<?php if ( 'browser_caching' === $arvaseo_id && 'on' === $arvaseo_state ) : ?>
						<?php $arvaseo_check = $arvaseo_stored['server_check']; ?>
						<?php if ( ! ServerRules::present() ) : ?>
							<p class="arvaseo-speed-risk"><span class="dashicons dashicons-warning" aria-hidden="true"></span> <?php esc_html_e( 'The rules aren’t in your .htaccess file any more: another plugin or your host may have rewritten it. Turn this off and on again to add them back.', 'arvaseo-connector' ); ?></p>
						<?php elseif ( null !== $arvaseo_check ) : ?>
							<ul class="arvaseo-list">
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
						<?php endif; ?>
					<?php endif; ?>
					<?php if ( 'browser_caching' === $arvaseo_id ) : ?>
						<p class="description"><?php esc_html_e( 'If you edit your theme’s files by hand, visitors may see the old version for a while; WordPress and plugins add a version to their files, so their updates show straight away.', 'arvaseo-connector' ); ?></p>
						<details>
							<summary><?php esc_html_e( 'The rules it adds', 'arvaseo-connector' ); ?></summary>
							<pre class="arvaseo-code"><?php echo esc_html( implode( "\n", ServerRules::lines() ) ); ?></pre>
						</details>
					<?php endif; ?>

					<?php if ( 'wp_extras' === $arvaseo_id ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="arvaseo-speed-options">
							<input type="hidden" name="action" value="arvaseo_connector_speed_options" />
							<input type="hidden" name="fix" value="wp_extras" />
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

					<?php if ( in_array( $arvaseo_id, array( 'critical_css', 'unused_css' ), true ) ) : ?>
						<?php
						$arvaseo_css       = \ArvaSEO\Connector\Speed\CssOptimizer::counts();
						$arvaseo_allowance = get_option( 'arvaseo_connector_css_allowance', array() );
						$arvaseo_allowance = is_array( $arvaseo_allowance ) ? $arvaseo_allowance : array();
						?>
						<div class="arvaseo-speed-options">
							<p>
								<?php
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
								<br /><span class="description"><?php esc_html_e( 'Pages are grouped by type (homepage, page, post, product, category…) and by their stylesheets; each group uses one page optimization. A stylesheet that changes, after a theme or plugin update, makes a new group; clearing your cache costs nothing.', 'arvaseo-connector' ); ?></span>
							</p>
							<?php if ( $arvaseo_css['no_allowance'] > 0 ) : ?>
								<p class="arvaseo-speed-risk"><span class="dashicons dashicons-warning" aria-hidden="true"></span>
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
							<div class="arvaseo-actions">
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
										<button type="submit" class="button"><?php echo esc_html( $arvaseo_label ); ?></button>
									</form>
								<?php endforeach; ?>
							</div>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="arvaseo_connector_speed_options" />
								<input type="hidden" name="fix" value="<?php echo esc_attr( $arvaseo_id ); ?>" />
								<?php wp_nonce_field( 'arvaseo_connector_speed_options' ); ?>
								<p>
									<label for="arvaseo-css-exclude-<?php echo esc_attr( $arvaseo_id ); ?>"><strong><?php esc_html_e( 'Leave these stylesheets alone', 'arvaseo-connector' ); ?></strong></label>
									<textarea id="arvaseo-css-exclude-<?php echo esc_attr( $arvaseo_id ); ?>" name="css_exclude" rows="2" class="large-text code" placeholder="slider-styles"><?php echo esc_textarea( implode( "\n", $arvaseo_stored['css_exclude'] ) ); ?></textarea>
									<span class="description"><?php esc_html_e( 'One stylesheet name (handle) per line; they load as before. Both CSS fixes share this list.', 'arvaseo-connector' ); ?></span>
								</p>
								<button type="submit" class="button"><?php esc_html_e( 'Save', 'arvaseo-connector' ); ?></button>
							</form>
							<p class="description"><?php esc_html_e( 'Arva SEO loads your public pages like a visitor, as a phone and as a computer, to see which styles they use. Only page addresses are sent, never content or visitor data.', 'arvaseo-connector' ); ?></p>
						</div>
					<?php endif; ?>

					<?php if ( 'webp_uploads' === $arvaseo_id ) : ?>
						<?php $arvaseo_webp = \ArvaSEO\Connector\Speed\WebpLibrary::progress(); ?>
						<div class="arvaseo-speed-options">
							<p><strong><?php esc_html_e( 'Convert your existing images too', 'arvaseo-connector' ); ?></strong><br />
								<span class="description"><?php esc_html_e( 'Each JPEG and PNG in your media library, and each of its sizes, gets a WebP copy beside it, kept only when it’s smaller. Your originals stay as they are, and Undo removes every copy. It runs in the background, a few images at a time.', 'arvaseo-connector' ); ?></span>
							</p>
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
							<div class="arvaseo-actions">
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
										<button type="submit" class="button">
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
							</div>
						</div>
					<?php endif; ?>

					<?php if ( 'delay_scripts' === $arvaseo_id ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="arvaseo-speed-options">
							<input type="hidden" name="action" value="arvaseo_connector_speed_options" />
							<input type="hidden" name="fix" value="delay_scripts" />
							<?php wp_nonce_field( 'arvaseo_connector_speed_options' ); ?>
							<details>
								<summary><?php esc_html_e( 'The widgets it delays by itself', 'arvaseo-connector' ); ?></summary>
								<p class="description"><?php esc_html_e( 'Tawk.to, Crisp, Intercom, LiveChat, Tidio, Zendesk, Drift and HubSpot chat; Hotjar and Microsoft Clarity; Facebook, X (Twitter) and Pinterest widgets; Elfsight widgets. Only scripts your theme or plugins add the WordPress way can be delayed.', 'arvaseo-connector' ); ?></p>
							</details>
							<p>
								<label for="arvaseo-delay-scripts"><strong><?php esc_html_e( 'Also delay these scripts', 'arvaseo-connector' ); ?></strong></label>
								<textarea id="arvaseo-delay-scripts" name="delay_scripts" rows="3" class="large-text code" placeholder="reviews-widget&#10;widget.example.com"><?php echo esc_textarea( implode( "\n", $arvaseo_stored['delay_scripts'] ) ); ?></textarea>
								<span class="description"><?php esc_html_e( 'One per line: a script name (handle) or part of its file address. A script that other scripts need is never delayed. To keep a widget loading straight away on some pages, leave those pages alone below.', 'arvaseo-connector' ); ?></span>
							</p>
							<button type="submit" class="button"><?php esc_html_e( 'Save', 'arvaseo-connector' ); ?></button>
						</form>
					<?php endif; ?>

					<?php if ( 'google_fonts' === $arvaseo_id ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="arvaseo-speed-options">
							<input type="hidden" name="action" value="arvaseo_connector_speed_options" />
							<input type="hidden" name="fix" value="google_fonts" />
							<?php wp_nonce_field( 'arvaseo_connector_speed_options' ); ?>
							<p>
								<label><input type="checkbox" name="fonts_local" value="1" <?php checked( $arvaseo_stored['fonts_local'] ); ?> /> <strong><?php esc_html_e( 'Serve them from your site', 'arvaseo-connector' ); ?></strong></label><br />
								<span class="description"><?php esc_html_e( 'Copies of the fonts your pages use are kept in your uploads folder and used instead of Google’s, so browsers don’t connect to Google for them (better for privacy too). Each copy is made in the background the first time a page needs it; until then Google’s fonts are used.', 'arvaseo-connector' ); ?></span>
								<?php if ( $arvaseo_stored['fonts_local'] && \ArvaSEO\Connector\Speed\LocalFonts::copied() > 0 ) : ?>
									<br /><span class="description">
										<?php
										$arvaseo_copies = \ArvaSEO\Connector\Speed\LocalFonts::copied();
										/* translators: %d: number of Google Fonts stylesheets copied. */
										printf( esc_html( _n( '%d font stylesheet is served from your site.', '%d font stylesheets are served from your site.', $arvaseo_copies, 'arvaseo-connector' ) ), (int) $arvaseo_copies );
										?>
									</span>
								<?php endif; ?>
							</p>
							<button type="submit" class="button"><?php esc_html_e( 'Save', 'arvaseo-connector' ); ?></button>
						</form>
					<?php endif; ?>

					<?php if ( 'defer_scripts' === $arvaseo_id ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="arvaseo-speed-options">
							<input type="hidden" name="action" value="arvaseo_connector_speed_options" />
							<input type="hidden" name="fix" value="defer_scripts" />
							<?php wp_nonce_field( 'arvaseo_connector_speed_options' ); ?>
							<?php if ( array() === $arvaseo_deferred && 'trying' === $arvaseo_state ) : ?>
								<p class="description"><?php esc_html_e( 'Open your homepage while trying it: the scripts it defers are listed here, so you can leave one alone if something stops working.', 'arvaseo-connector' ); ?></p>
							<?php elseif ( array() !== $arvaseo_deferred ) : ?>
								<p><?php esc_html_e( 'Scripts it deferred on the last page you opened while trying it:', 'arvaseo-connector' ); ?></p>
								<ul class="arvaseo-list">
									<?php foreach ( $arvaseo_deferred as $arvaseo_handle => $arvaseo_host ) : ?>
										<li>
											<code><?php echo esc_html( (string) $arvaseo_handle ); ?></code> <span class="description"><?php echo esc_html( (string) $arvaseo_host ); ?></span>
											<?php if ( in_array( (string) $arvaseo_handle, $arvaseo_stored['defer_exclude'], true ) ) : ?>
												<span class="description">· <?php esc_html_e( 'Left alone', 'arvaseo-connector' ); ?></span>
											<?php else : ?>
												<button type="submit" name="leave_alone" value="<?php echo esc_attr( (string) $arvaseo_handle ); ?>" class="button-link">
													<?php esc_html_e( 'Leave alone', 'arvaseo-connector' ); ?><span class="screen-reader-text"> <?php echo esc_html( (string) $arvaseo_handle ); ?></span>
												</button>
											<?php endif; ?>
										</li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
							<p>
								<label for="arvaseo-defer-exclude"><strong><?php esc_html_e( 'Leave these scripts alone', 'arvaseo-connector' ); ?></strong></label><br />
								<textarea id="arvaseo-defer-exclude" name="defer_exclude" rows="3" class="large-text code"><?php echo esc_textarea( implode( "\n", $arvaseo_stored['defer_exclude'] ) ); ?></textarea>
								<span class="description"><?php esc_html_e( 'One per line: a script name (handle), such as one from the list above, or part of its file address, such as booking.js or /plugins/contact-form-7/. jQuery and consent or cookie tools are always left alone.', 'arvaseo-connector' ); ?></span>
							</p>
							<?php if ( array() !== $arvaseo_plugins ) : ?>
								<details class="arvaseo-speed-plugins" <?php echo array() !== $arvaseo_stored['defer_plugins'] ? 'open' : ''; ?>>
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
									<fieldset>
										<legend class="screen-reader-text"><?php esc_html_e( 'Plugins whose scripts Defer scripts leaves alone', 'arvaseo-connector' ); ?></legend>
										<?php foreach ( $arvaseo_plugins as $arvaseo_slug => $arvaseo_name ) : ?>
											<p><label><input type="checkbox" name="defer_plugins[]" value="<?php echo esc_attr( $arvaseo_slug ); ?>" <?php checked( in_array( $arvaseo_slug, $arvaseo_stored['defer_plugins'], true ) ); ?> /> <?php echo esc_html( $arvaseo_name ); ?></label></p>
										<?php endforeach; ?>
									</fieldset>
								</details>
							<?php endif; ?>
							<p class="description"><a href="#arvaseo-speed-exclusions"><?php esc_html_e( 'Leave a page alone', 'arvaseo-connector' ); ?></a></p>
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


<section id="arvaseo-speed-exclusions" class="card arvaseo-card arvaseo-speed-exclusions" aria-labelledby="arvaseo-exclusions-heading">
	<h2 id="arvaseo-exclusions-heading"><?php esc_html_e( 'Leave things alone', 'arvaseo-connector' ); ?></h2>
	<p><?php esc_html_e( 'If a fix stops something working on part of your site, leave that part alone instead of turning the fix off everywhere.', 'arvaseo-connector' ); ?></p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="arvaseo_connector_speed_options" />
		<input type="hidden" name="fix" value="exclusions" />
		<?php wp_nonce_field( 'arvaseo_connector_speed_options' ); ?>
		<p>
			<label for="arvaseo-exclude-pages"><strong><?php esc_html_e( 'Pages', 'arvaseo-connector' ); ?></strong></label><br />
			<textarea id="arvaseo-exclude-pages" name="exclude_pages" rows="4" class="large-text code" placeholder="/checkout/&#10;/book-now/*"><?php echo esc_textarea( implode( "\n", $arvaseo_stored['exclude_pages'] ) ); ?></textarea>
			<span class="description"><?php esc_html_e( 'One address per line, the part after your domain, such as /contact/. A * matches anything: /shop/* is every page under /shop/. No fix runs on these pages. You can also tick “Leave this page alone” when you edit a post or page, or use Arva SEO in the toolbar while you view any page.', 'arvaseo-connector' ); ?></span>
			<?php $arvaseo_skipped = Exclusions::skipped_posts(); ?>
			<?php if ( $arvaseo_skipped > 0 ) : ?>
				<br /><span class="description">
					<?php
					/* translators: %d: number of posts and pages. */
					printf( esc_html( _n( '%d post or page is left alone by its own tick box.', '%d posts and pages are left alone by their own tick box.', $arvaseo_skipped, 'arvaseo-connector' ) ), (int) $arvaseo_skipped );
					?>
				</span>
			<?php endif; ?>
		</p>
		<?php if ( Exclusions::has_shop() ) : ?>
			<input type="hidden" name="shop_pages_shown" value="1" />
			<p>
				<label><input type="checkbox" name="shop_pages" value="1" <?php checked( $arvaseo_stored['shop_pages'] ); ?> /> <?php esc_html_e( 'Defer scripts leaves the cart, checkout and account pages alone', 'arvaseo-connector' ); ?></label><br />
				<span class="description"><?php esc_html_e( 'Recommended: payment and account forms often need their scripts loaded in order.', 'arvaseo-connector' ); ?></span>
			</p>
		<?php endif; ?>
		<p>
			<label for="arvaseo-exclude-elements"><strong><?php esc_html_e( 'Images, videos and maps', 'arvaseo-connector' ); ?></strong></label><br />
			<textarea id="arvaseo-exclude-elements" name="exclude_elements" rows="4" class="large-text code" placeholder=".hero-slide&#10;#main-banner&#10;logo.svg"><?php echo esc_textarea( implode( "\n", $arvaseo_stored['exclude_elements'] ) ); ?></textarea>
			<span class="description"><?php esc_html_e( 'One per line: a CSS class starting with a dot (.hero-slide), an id starting with # (#main-banner), or part of the file’s address (logo.svg, vimeo.com). Lazy-load, Add missing image sizes and Click-to-play videos leave these alone. A block’s Additional CSS class counts for everything inside the block; in older content, put the class on the image itself.', 'arvaseo-connector' ); ?></span><br />
			<span class="description"><?php esc_html_e( 'Lazy-load also leaves alone images marked skip-lazy or no-lazy, as other speed plugins do, and every fix leaves alone an element with the data-arvaseo-skip attribute.', 'arvaseo-connector' ); ?></span>
		</p>
		<p>
			<label for="arvaseo-eager-images"><strong><?php esc_html_e( 'Images loaded straight away', 'arvaseo-connector' ); ?></strong></label><br />
			<input type="number" id="arvaseo-eager-images" name="eager_images" min="0" max="<?php echo esc_attr( (string) Fixes::MAX_EAGER_IMAGES ); ?>" value="<?php echo esc_attr( (string) $arvaseo_stored['eager_images'] ); ?>" class="small-text" />
			<span class="description"><?php esc_html_e( 'How many images at the top of each page Lazy-load never delays. 3 suits most sites; raise it if a slider at the top shows its slides late.', 'arvaseo-connector' ); ?></span>
		</p>
		<p class="description">
			<?php esc_html_e( 'Scripts are left alone on the Defer scripts card.', 'arvaseo-connector' ); ?>
			<a href="#<?php echo esc_attr( Fixes::anchor( 'defer_scripts' ) ); ?>"><?php esc_html_e( 'Go to Defer scripts', 'arvaseo-connector' ); ?></a>
		</p>
		<button type="submit" class="button"><?php esc_html_e( 'Save', 'arvaseo-connector' ); ?></button>
	</form>
	<details class="arvaseo-speed-dev">
		<summary><?php esc_html_e( 'For developers', 'arvaseo-connector' ); ?></summary>
		<ul class="arvaseo-list">
			<li><code>arvaseo_connector_speed_fix_applies</code> <span class="description"><?php esc_html_e( '(bool $applies, string $fix_id): turn a fix off for any page condition.', 'arvaseo-connector' ); ?></span></li>
			<li><code>arvaseo_connector_defer_script</code> <span class="description"><?php esc_html_e( '(bool $defer, string $handle, string $src): leave a script alone.', 'arvaseo-connector' ); ?></span></li>
			<li><code>arvaseo_connector_optimize_element</code> <span class="description"><?php esc_html_e( '(bool $optimize, string $fix_id, string $tag, array $attributes): leave an image or frame alone.', 'arvaseo-connector' ); ?></span></li>
		</ul>
	</details>
</section>

<p class="description arvaseo-speed-foot"><?php esc_html_e( 'Page caching, removing unused CSS and minifying files need a caching plugin, such as LiteSpeed Cache or WP Rocket. Arva SEO’s PageSpeed page says which setting to change in the one you have. If you use a page cache, clear it after changing a fix here. In an emergency, add define( \'ARVASEO_CONNECTOR_DISABLE_SPEED_FIXES\', true ); to wp-config.php to switch every fix off.', 'arvaseo-connector' ); ?></p>
	<?php
} )();
