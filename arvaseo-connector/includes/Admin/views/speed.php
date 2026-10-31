<?php
/**
 * Speed: the PageSpeed fixes.
 *
 * @package ArvaSEO\Connector
 */

use ArvaSEO\Connector\Admin\AdminController;
use ArvaSEO\Connector\Auth\TokenStore;
use ArvaSEO\Connector\Health\AssessmentService;
use ArvaSEO\Connector\Speed\AssetCache;
use ArvaSEO\Connector\Speed\Changes;
use ArvaSEO\Connector\Speed\Cloudflare;
use ArvaSEO\Connector\Speed\Exclusions;
use ArvaSEO\Connector\Privacy\PrivacyService;
use ArvaSEO\Connector\Speed\Fixes;
use ArvaSEO\Connector\Speed\ImageCompression;
use ArvaSEO\Connector\Speed\OtherCaches;
use ArvaSEO\Connector\Speed\OtherPlugins;
use ArvaSEO\Connector\Speed\PageCache;
use ArvaSEO\Connector\Speed\ServerRules;

defined( 'ABSPATH' ) || exit;

// Scoped so the view's variables stay local.
( static function () {

	$arvaseo_fixes   = Fixes::display_fixes();
	$arvaseo_stored  = Fixes::stored();
	$arvaseo_test    = AssessmentService::pagespeed_result();
	$arvaseo_killed  = defined( Fixes::KILL_SWITCH ) && constant( Fixes::KILL_SWITCH );
	$arvaseo_plugins = Exclusions::plugin_choices();
	$arvaseo_others  = OtherPlugins::active();
	$arvaseo_keep    = static function ( bool $keep, string $label, string $css_class ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="arvaseo-inline-form">';
		echo '<input type="hidden" name="action" value="arvaseo_connector_keep_others" />';
		echo '<input type="hidden" name="keep" value="' . ( $keep ? '1' : '0' ) . '" />';
		wp_nonce_field( 'arvaseo_connector_keep_others' );
		echo '<button type="submit" class="' . esc_attr( $css_class ) . '">' . esc_html( $label ) . '</button>';
		echo '</form>';
	};
	$arvaseo_tones   = array(
		'off'         => 'neutral',
		'trying'      => 'warn',
		'on'          => 'good',
		'handled'     => 'neutral',
		'unavailable' => 'neutral',
	);
	$arvaseo_extras  = array(
		'emoji'          => array( __( 'The emoji script and styles', 'arvaseo-connector' ), __( 'Browsers show emoji by themselves.', 'arvaseo-connector' ) ),
		'oembed'         => array( __( 'The embed discovery script', 'arvaseo-connector' ), __( 'Lets other websites embed your posts as cards. Embedding videos and posts on your site still works.', 'arvaseo-connector' ) ),
		'dashicons'      => array( __( 'Dashicons for visitors', 'arvaseo-connector' ), __( 'The dashboard’s icon font. Leave it on if your theme shows these icons to visitors.', 'arvaseo-connector' ) ),
		'jquery_migrate' => array( __( 'jQuery Migrate', 'arvaseo-connector' ), __( 'Helps old plugins with new jQuery. Only remove it if you’ve checked your site works without it.', 'arvaseo-connector' ) ),
		'block_css'      => array( __( 'Block styles on pages without blocks', 'arvaseo-connector' ), __( 'Classic themes only. Leave it on if you use block widgets.', 'arvaseo-connector' ) ),
	);
	$arvaseo_subs    = array(
		'css'        => __( 'Generate, deliver and reduce styles in one place.', 'arvaseo-connector' ),
		'javascript' => __( 'Loading order, delays and script size. Check your interactions after changes.', 'arvaseo-connector' ),
		'images'     => __( 'Prioritize visible images and process the media library.', 'arvaseo-connector' ),
		'fonts'      => __( 'Font delivery and fallback text.', 'arvaseo-connector' ),
		'sooner'     => __( 'The main content appears faster.', 'arvaseo-connector' ),
		'still'      => __( 'Nothing jumps around while it loads.', 'arvaseo-connector' ),
		'nothing'    => __( 'Widgets and videos load when someone needs them.', 'arvaseo-connector' ),
		'smaller'    => __( 'Less to download on every visit.', 'arvaseo-connector' ),
	);

	$arvaseo_speed_group_tabs = array(
		'css'        => 'css',
		'javascript' => 'javascript',
		'images'     => 'images',
		'fonts'      => 'fonts',
		'sooner'     => 'caching',
		'still'      => 'css',
		'nothing'    => 'images',
		'smaller'    => 'caching',
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
	// Updates that wait for the administrator's check (visitors keep what was approved), and what changed.
	$arvaseo_waiting = Changes::waiting_fixes( true );
	$arvaseo_changed = Changes::notes_for_site();
	$arvaseo_day     = static fn ( int $at ): string => wp_date( get_option( 'date_format' ), $at );

	// What the CSS, WebP and image compression rows show, once for the page.
	$arvaseo_css       = \ArvaSEO\Connector\Speed\CssOptimizer::counts();
	$arvaseo_webp      = \ArvaSEO\Connector\Speed\WebpLibrary::progress();
	$arvaseo_cmp       = ImageCompression::state();
	$arvaseo_cmp_stats = ImageCompression::stats();
	$arvaseo_cmp_lib   = ImageCompression::library();
	$arvaseo_cmp_left  = $arvaseo_cmp['allowance'];
	$arvaseo_cmp_cloud = TokenStore::is_connected() && PrivacyService::allows( 'image_compression' );
	$arvaseo_pc        = PageCache::settings();
	$arvaseo_pc_host   = PageCache::host_cache();
	$arvaseo_backups   = ServerRules::backups();

	/**
	 * One Page caching button (clear, choose the host's cache or Arva SEO's).
	 *
	 * @param string $what    What it does.
	 * @param string $label   Button label.
	 * @param string $classes Button classes.
	 */
	$arvaseo_pc_button = static function ( string $what, string $label, string $classes = 'arvaseo-btn arvaseo-btn--sm' ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="arvaseo-inline-form">';
		echo '<input type="hidden" name="action" value="arvaseo_connector_page_cache" />';
		echo '<input type="hidden" name="do" value="' . esc_attr( $what ) . '" />';
		wp_nonce_field( 'arvaseo_connector_page_cache' );
		echo '<button type="submit" class="' . esc_attr( $classes ) . '">' . esc_html( $label ) . '</button>';
		echo '</form>';
	};

	/**
	 * Links to download the .htaccess copies kept before Arva SEO changed it.
	 */
	$arvaseo_backup_links = static function () use ( $arvaseo_backups ): void {
		if ( array() === $arvaseo_backups ) {
			return;
		}
		$date   = static fn ( string $at ): string => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) strtotime( $at ) );
		$latest = isset( $arvaseo_backups['latest'] ) && ( $arvaseo_backups['original']['at'] ?? '' ) !== $arvaseo_backups['latest']['at'];
		echo '<div class="arvaseo-fix-sub"><h4>' . esc_html__( 'Your .htaccess backup', 'arvaseo-connector' ) . '</h4>';
		echo '<p class="arvaseo-hint">';
		echo esc_html(
			$latest
				/* translators: 1: date and time of the original copy, 2: date and time of the latest copy. */
				? sprintf( __( 'Arva SEO keeps a copy of your .htaccess file from before it first changed it (%1$s), and from before its latest change (%2$s).', 'arvaseo-connector' ), $date( $arvaseo_backups['original']['at'] ?? '' ), $date( $arvaseo_backups['latest']['at'] ) )
				/* translators: %s: date and time. */
				: sprintf( __( 'Arva SEO keeps a copy of your .htaccess file from before it first changed it (%s).', 'arvaseo-connector' ), $date( (string) ( $arvaseo_backups['original']['at'] ?? $arvaseo_backups['latest']['at'] ?? '' ) ) )
		);
		echo '</p><div class="arvaseo-row" style="margin-top: 10px">';
		$labels = array(
			'original' => __( 'Download the original', 'arvaseo-connector' ),
			'latest'   => __( 'Download the latest copy', 'arvaseo-connector' ),
		);
		foreach ( $labels as $which => $label ) {
			if ( ! isset( $arvaseo_backups[ $which ] ) || ( 'latest' === $which && ! $latest ) ) {
				continue;
			}
			$url = wp_nonce_url(
				add_query_arg(
					array(
						'action' => 'arvaseo_connector_page_cache',
						'do'     => 'backup',
						'which'  => $which,
					),
					admin_url( 'admin-post.php' )
				),
				'arvaseo_connector_page_cache'
			);
			echo '<a class="arvaseo-btn arvaseo-btn--sm" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</div></div>';
	};

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
<div data-arvaseo-speed>
<div class="arvaseo-stack">
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
			<?php if ( TokenStore::is_connected() && '' !== AdminController::pagespeed_url() ) : ?>
				<a class="arvaseo-btn arvaseo-btn--sm" href="#arvaseo-pagespeed-tests"><?php esc_html_e( 'Run a new test', 'arvaseo-connector' ); ?></a>
			<?php endif; ?>
		</div>
		<?php if ( null !== $arvaseo_test ) : ?>
			<div class="arvaseo-stack arvaseo-speed-recommendations" style="margin-top: 16px; padding-top: 16px; border-top: 1px solid #e2ded3">
				<?php if ( array() === $arvaseo_needed ) : ?>
					<span class="arvaseo-muted"><?php esc_html_e( 'None of the problems it found are ones these fixes address. Arva SEO’s PageSpeed page has the steps for the rest.', 'arvaseo-connector' ); ?></span>
				<?php else : ?>
					<p class="arvaseo-muted"><?php esc_html_e( 'This test asks for:', 'arvaseo-connector' ); ?></p>
					<?php
					foreach ( Fixes::groups() as $arvaseo_needed_group => $arvaseo_needed_title ) :
						$arvaseo_group_ids = array_filter( array_keys( $arvaseo_needed ), static fn ( $id ) => $arvaseo_fixes[ $id ]['group'] === $arvaseo_needed_group );
						if ( array() === $arvaseo_group_ids ) {
							continue;
						}
						?>
						<div>
							<h3 class="arvaseo-eyebrow" id="arvaseo-needed-<?php echo esc_attr( $arvaseo_needed_group ); ?>"><?php echo esc_html( $arvaseo_needed_title ); ?></h3>
							<ul class="arvaseo-row" aria-labelledby="arvaseo-needed-<?php echo esc_attr( $arvaseo_needed_group ); ?>" style="list-style: none; margin: 8px 0 0; padding: 0">
								<?php foreach ( $arvaseo_group_ids as $arvaseo_id ) : ?>
									<?php $arvaseo_audits = $arvaseo_needed[ $arvaseo_id ]; ?>
									<li class="arvaseo-row" style="flex-wrap: nowrap; max-width: 100%; margin: 0">
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
									</li>
								<?php endforeach; ?>
							</ul>
						</div>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</section>
<div data-arvaseo-speed-section="testing">
	<?php require __DIR__ . '/speed-pagespeed.php'; ?>
</div>
<div data-arvaseo-speed-section="css">
	<?php require __DIR__ . '/speed-css-quota.php'; ?>
</div>
<div data-arvaseo-speed-section="testing">
	<?php require __DIR__ . '/speed-assets.php'; ?>
</div>
<div data-arvaseo-speed-section="testing">
	<?php require __DIR__ . '/speed-asset-trials.php'; ?>
</div>
	<?php if ( $arvaseo_killed ) : ?>
		<div class="notice notice-warning inline arvaseo-own"><p><?php esc_html_e( 'Every PageSpeed fix is switched off in wp-config.php (ARVASEO_CONNECTOR_DISABLE_SPEED_FIXES). Remove that line to use them again.', 'arvaseo-connector' ); ?></p></div>
	<?php endif; ?>



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
		<section id="arvaseo-other-plugins" class="arvaseo-card" data-arvaseo-speed-section="testing" aria-labelledby="arvaseo-others-heading">
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

	<?php
	$arvaseo_unchecked = array_diff_key( $arvaseo_waiting, array_flip( array_filter( array_keys( $arvaseo_waiting ), array( Changes::class, 'is_kept' ) ) ) );
	if ( array() !== $arvaseo_unchecked ) :
		?>
		<div class="arvaseo-trybar" role="status" id="arvaseo-updates">
			<?php echo AdminController::icon( 'eye', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
			<div>
				<strong>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of fixes. */
							_n( '%d fix you use has an update to check.', '%d fixes you use have an update to check.', count( $arvaseo_unchecked ), 'arvaseo-connector' ),
							count( $arvaseo_unchecked )
						)
					);
					?>
				</strong>
				<?php esc_html_e( 'Your visitors still get the versions you checked. While you’re signed in you see the new ones: open your important pages, then choose on each fix below.', 'arvaseo-connector' ); ?>
			</div>
			<a class="arvaseo-btn arvaseo-btn--sm" href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View your site', 'arvaseo-connector' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'arvaseo-connector' ); ?></span></a>
		</div>
	<?php endif; ?>

	<?php if ( array() !== $arvaseo_changed ) : ?>
		<section class="arvaseo-card" data-arvaseo-speed-section="testing" aria-labelledby="arvaseo-changed-heading">
		<details class="arvaseo-more" id="arvaseo-what-changed" <?php echo array() !== $arvaseo_unchecked ? 'open' : ''; ?>>
			<summary id="arvaseo-changed-heading"><?php esc_html_e( 'What changed when Arva SEO updated', 'arvaseo-connector' ); ?></summary>
			<div class="arvaseo-more-in">
				<?php foreach ( $arvaseo_changed as $arvaseo_change ) : ?>
					<p>
						<strong><?php echo esc_html( $arvaseo_change['version'] ); ?></strong>
						<?php
						$arvaseo_kinds = array(
							Changes::UPDATE => __( 'Update to check', 'arvaseo-connector' ),
							Changes::OPTION => __( 'New option', 'arvaseo-connector' ),
							Changes::BACK   => __( 'Back to trying', 'arvaseo-connector' ),
						);
						if ( isset( $arvaseo_kinds[ $arvaseo_change['kind'] ] ) ) {
							echo AdminController::badge( Changes::UPDATE === $arvaseo_change['kind'] ? 'warn' : 'acc', $arvaseo_kinds[ $arvaseo_change['kind'] ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
						}
						echo ' ' . esc_html( $arvaseo_change['note'] );
						if ( 'used' === $arvaseo_change['done'] ) {
							echo ' ' . esc_html__( 'Your visitors use it.', 'arvaseo-connector' );
						} elseif ( 'retired' === $arvaseo_change['done'] ) {
							echo ' ' . esc_html__( 'The earlier version is no longer available: the fix went back to trying.', 'arvaseo-connector' );
						}
						?>
					</p>
				<?php endforeach; ?>
			</div>
		</details>
		</section>
	<?php endif; ?>

	<?php
	$arvaseo_caches = OtherCaches::found();
	if ( array() !== $arvaseo_caches ) :
		$arvaseo_cache_state = OtherCaches::state();
		$arvaseo_kept        = AssetCache::kept();
		$arvaseo_waiting_ids = array_column( OtherCaches::waiting(), 'id' );
		?>
		<section id="arvaseo-other-caches" class="arvaseo-card" data-arvaseo-speed-section="caching" aria-labelledby="arvaseo-caches-heading">
			<p class="arvaseo-eyebrow"><?php esc_html_e( 'Other caches', 'arvaseo-connector' ); ?></p>
			<h2 class="arvaseo-h3" id="arvaseo-caches-heading"><?php esc_html_e( 'Other caches on this site', 'arvaseo-connector' ); ?></h2>
			<p class="arvaseo-muted" style="margin: 8px 0 0; max-width: 760px"><?php esc_html_e( 'When a change alters what visitors see, Arva SEO clears the caches below that it can reach, at most every 15 minutes. The ones it can’t reach are yours to clear: do it after changing a fix, or PageSpeed and your visitors can still get the old page.', 'arvaseo-connector' ); ?></p>
			<div class="arvaseo-rows" style="margin-top: 16px"><div class="arvaseo-rows-in">
				<?php foreach ( $arvaseo_caches as $arvaseo_cache ) : ?>
					<div class="arvaseo-fix">
						<div class="arvaseo-fix-body">
							<h3>
								<?php echo esc_html( $arvaseo_cache['name'] ); ?>
								<?php
								if ( 'auto' === $arvaseo_cache['clearing'] ) {
									echo AdminController::badge( 'good', __( 'Cleared by Arva SEO', 'arvaseo-connector' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
								} elseif ( 'by_hand' === $arvaseo_cache['clearing'] ) {
									echo in_array( $arvaseo_cache['id'], $arvaseo_waiting_ids, true )
										? AdminController::badge( 'warn', __( 'Clear it yourself', 'arvaseo-connector' ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
										: AdminController::badge( 'neutral', __( 'Yours to clear', 'arvaseo-connector' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
								} else {
									echo AdminController::badge( 'neutral', __( 'Nothing to clear', 'arvaseo-connector' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
								}
								?>
							</h3>
							<?php if ( 'auto' === $arvaseo_cache['clearing'] ) : ?>
								<p class="arvaseo-hint" style="margin: 6px 0 0">
									<?php
									$arvaseo_at = $arvaseo_cache_state['cleared_at'][ $arvaseo_cache['id'] ] ?? null;
									echo esc_html(
										null === $arvaseo_at
											? __( 'Cleared when your pages change.', 'arvaseo-connector' )
											/* translators: %s: how long ago, such as "3 minutes". */
											: sprintf( __( 'Last cleared %s ago.', 'arvaseo-connector' ), human_time_diff( $arvaseo_at ) )
									);
									?>
								</p>
							<?php elseif ( null !== $arvaseo_cache['detail'] ) : ?>
								<p class="arvaseo-hint" style="margin: 6px 0 0"><?php echo esc_html( $arvaseo_cache['detail'] ); ?></p>
							<?php endif; ?>
							<?php if ( 'by_hand' === $arvaseo_cache['clearing'] && isset( $arvaseo_cache_state['handled'][ $arvaseo_cache['id'] ] ) ) : ?>
								<p class="arvaseo-hint" style="margin: 6px 0 0">
									<?php
									/* translators: %s: how long ago, such as "3 minutes". */
									echo esc_html( sprintf( __( 'You said you cleared it %s ago.', 'arvaseo-connector' ), human_time_diff( $arvaseo_cache_state['handled'][ $arvaseo_cache['id'] ] ) ) );
									?>
								</p>
							<?php endif; ?>
						</div>
						<?php if ( 'by_hand' === $arvaseo_cache['clearing'] ) : ?>
							<div class="arvaseo-fix-acts">
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="arvaseo-inline-form">
									<input type="hidden" name="action" value="arvaseo_connector_other_caches" />
									<input type="hidden" name="do" value="handled" />
									<input type="hidden" name="cache" value="<?php echo esc_attr( $arvaseo_cache['id'] ); ?>" />
									<?php wp_nonce_field( 'arvaseo_connector_other_caches' ); ?>
									<button type="submit" class="arvaseo-btn arvaseo-btn--sm"><?php esc_html_e( 'I’ve cleared it', 'arvaseo-connector' ); ?></button>
								</form>
							</div>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div></div>
			<?php if ( null !== $arvaseo_kept ) : ?>
				<div class="arvaseo-risk arvaseo-risk--check" style="margin-top: 16px"><?php echo AdminController::icon( 'alert', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><span>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: number of files, 2: their size, such as "18 MB", 3: names of caches. */
							_n( 'Arva SEO is keeping %1$d old file (%2$s) because %3$s may still show pages that use it. After you clear it, press “I’ve cleared it”.', 'Arva SEO is keeping %1$d old files (%2$s) because %3$s may still show pages that use them. After you clear it, press “I’ve cleared it”.', $arvaseo_kept['files'], 'arvaseo-connector' ),
							$arvaseo_kept['files'],
							size_format( $arvaseo_kept['bytes'] ),
							$arvaseo_kept['names']
						)
					);
					?>
				</span></div>
			<?php endif; ?>
			<div class="arvaseo-row" style="margin-top: 16px">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="arvaseo-inline-form">
					<input type="hidden" name="action" value="arvaseo_connector_other_caches" />
					<input type="hidden" name="do" value="clear" />
					<?php wp_nonce_field( 'arvaseo_connector_other_caches' ); ?>
					<button type="submit" class="arvaseo-btn arvaseo-btn--sm arvaseo-btn--dark"><?php esc_html_e( 'Clear every cache now', 'arvaseo-connector' ); ?></button>
				</form>
				<span class="arvaseo-hint">
					<?php
					$arvaseo_looked = $arvaseo_cache_state['header']['at'];
					echo esc_html(
						null === $arvaseo_looked
							? __( 'Once a day, Arva SEO reads the cache headers of your own homepage to find caches in front of your site. It hasn’t yet.', 'arvaseo-connector' )
							/* translators: %s: how long ago, such as "3 hours". */
							: sprintf( __( 'Once a day, Arva SEO reads the cache headers of your own homepage to find caches in front of your site. Last time: %s ago.', 'arvaseo-connector' ), human_time_diff( $arvaseo_looked ) )
					);
					?>
				</span>
			</div>
		</section>
	<?php endif; ?>

	<p class="arvaseo-hint" id="arvaseo-background" data-arvaseo-speed-section="caching" style="margin: 0 0 20px; max-width: 760px"><?php esc_html_e( 'What runs in the background: once a day Arva SEO looks at your homepage’s cache headers. When a change alters what visitors see, it clears the caches it can reach, at most every 15 minutes. Old generated files are deleted a day after the caches were cleared, never one your homepage links to; while a cache Arva SEO can’t clear may keep pages, the folders may grow to twice their usual limit.', 'arvaseo-connector' ); ?></p>

	<div class="arvaseo-between" data-arvaseo-speed-filter-bar style="align-items: center">
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

	<?php require __DIR__ . '/speed-navigation.php'; ?>
	<?php
	$arvaseo_n = 0;
	foreach ( Fixes::groups() as $arvaseo_group => $arvaseo_group_title ) :
		++$arvaseo_n;
		?>
<div data-arvaseo-speed-section="<?php echo esc_attr( $arvaseo_speed_group_tabs[ $arvaseo_group ] ?? 'advanced' ); ?>">
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
		<div class="arvaseo-fix-head">
			<h3 id="<?php echo esc_attr( $arvaseo_heading ); ?>"><?php echo esc_html( $arvaseo_fix['title'] ); ?></h3>
			<?php
			if ( 'on' === $arvaseo_state || 'trying' === $arvaseo_state ) {
				echo '<span class="arvaseo-chip arvaseo-chip--' . esc_attr( $arvaseo_tones[ $arvaseo_state ] ) . '"><span class="arvaseo-dot"></span>' . esc_html( Fixes::state_label( $arvaseo_state ) ) . '</span>';
			} elseif ( 'page_cache' === $arvaseo_id && 'handled' === $arvaseo_state && null !== $arvaseo_pc_host && $arvaseo_handled === $arvaseo_pc_host['name'] ) {
				echo AdminController::badge( 'neutral', __( 'Done by your host', 'arvaseo-connector' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
			} else {
				echo AdminController::badge( $arvaseo_tones[ $arvaseo_state ] ?? 'neutral', Fixes::state_label( $arvaseo_state ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
			}
			if ( isset( $arvaseo_needed[ $arvaseo_id ] ) ) {
				echo AdminController::badge( 'acc', __( 'Asked for by your latest test', 'arvaseo-connector' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
			}
			if ( isset( $arvaseo_waiting[ $arvaseo_id ] ) && 'on' === $arvaseo_state ) {
				echo Changes::is_kept( $arvaseo_id )
					? AdminController::badge( 'neutral', __( 'Keeping the previous version', 'arvaseo-connector' ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
					: AdminController::badge( 'warn', __( 'Update to check', 'arvaseo-connector' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
			}
			?>
			<?php if ( 'handled' !== $arvaseo_state && 'unavailable' !== $arvaseo_state ) : ?>
				<span class="arvaseo-fix-risk arvaseo-fix-risk--<?php echo esc_attr( $arvaseo_fix['risk'] ); ?>">
					<?php echo AdminController::icon( 'check' === $arvaseo_fix['risk'] ? 'alert' : 'check', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
					<?php echo 'check' === $arvaseo_fix['risk'] ? esc_html__( 'Check your pages', 'arvaseo-connector' ) : esc_html__( 'Safe', 'arvaseo-connector' ); ?>
				</span>
			<?php endif; ?>
		</div>
		<p class="arvaseo-fix-desc"><?php echo esc_html( $arvaseo_fix['what'] ); ?></p>

			<?php if ( isset( $arvaseo_waiting[ $arvaseo_id ] ) && 'on' === $arvaseo_state ) : ?>
				<?php
				$arvaseo_notes  = Changes::notes();
				$arvaseo_kept   = Changes::is_kept( $arvaseo_id );
				$arvaseo_retire = (int) Changes::retire_at( $arvaseo_id );
				?>
			<div class="arvaseo-extra arvaseo-extra--warn" id="<?php echo esc_attr( 'arvaseo-update-' . $arvaseo_id ); ?>">
				<p>
					<strong>
						<?php
						/* translators: %s: plugin version. */
						echo esc_html( sprintf( __( 'Updated in %s:', 'arvaseo-connector' ), Changes::announced_in( $arvaseo_waiting[ $arvaseo_id ][0]['id'] ) ) );
						?>
					</strong>
					<?php echo esc_html( implode( ' ', array_map( static fn ( array $arvaseo_entry ): string => $arvaseo_notes[ $arvaseo_entry['id'] ] ?? '', $arvaseo_waiting[ $arvaseo_id ] ) ) ); ?>
				</p>
				<p>
					<?php
					if ( $arvaseo_kept ) {
						/* translators: %s: date. */
						echo esc_html( sprintf( __( 'You’re keeping the version you checked. Your visitors, and you while you’re signed in, get it until %s; after that this fix goes back to being tried so you can check the new one.', 'arvaseo-connector' ), $arvaseo_day( $arvaseo_retire ) ) );
					} elseif ( null !== $arvaseo_since ) {
						/* translators: %s: date. */
						echo esc_html( sprintf( __( 'Your visitors still get the version you checked on %s. While you’re signed in you see the new one: open your important pages, then choose.', 'arvaseo-connector' ), $arvaseo_day( (int) strtotime( $arvaseo_since ) ) ) );
					} else {
						esc_html_e( 'Your visitors still get the version you checked before. While you’re signed in you see the new one: open your important pages, then choose.', 'arvaseo-connector' );
					}
					?>
				</p>
				<?php if ( ! $arvaseo_kept ) : ?>
					<p class="arvaseo-hint">
						<?php
						/* translators: %s: date. */
						echo esc_html( sprintf( __( 'The earlier version stays available until %s, then this fix goes back to being tried.', 'arvaseo-connector' ), $arvaseo_day( $arvaseo_retire ) ) );
						?>
					</p>
				<?php endif; ?>
				<div class="arvaseo-row">
					<?php
					$arvaseo_button( $arvaseo_id, 'use_update', __( 'Use it for visitors', 'arvaseo-connector' ), 'arvaseo-btn arvaseo-btn--sm arvaseo-btn--pri' );
					if ( ! $arvaseo_kept ) {
						$arvaseo_button( $arvaseo_id, 'keep_previous', __( 'Keep the previous version', 'arvaseo-connector' ) );
					}
					$arvaseo_button( $arvaseo_id, 'off', __( 'Turn the fix off', 'arvaseo-connector' ), 'arvaseo-btn arvaseo-btn--sm arvaseo-btn--ghost' );
					?>
				</div>
			</div>
		<?php endif; ?>

			<?php // What needs attention stays in view; the rest waits under Details. ?>
			<?php if ( in_array( $arvaseo_id, array( 'defer_scripts', 'delay_scripts' ), true ) && 'on' === Cloudflare::details()['rocket_loader'] ) : ?>
			<div class="arvaseo-risk arvaseo-risk--check"><?php echo AdminController::icon( 'cloud', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><span><?php esc_html_e( 'Rocket Loader is on in Cloudflare and also changes how scripts load. Use one or the other.', 'arvaseo-connector' ); ?> <a href="#arvaseo-cloudflare"><?php esc_html_e( 'See Cloudflare', 'arvaseo-connector' ); ?></a></span></div>
		<?php endif; ?>
			<?php if ( 'handled' === $arvaseo_state && 'page_cache' === $arvaseo_id && null !== $arvaseo_pc_host && $arvaseo_handled === $arvaseo_pc_host['name'] ) : ?>
			<div class="arvaseo-extra">
				<p>
					<?php
					/* translators: %s: the host's cache, such as SiteGround's dynamic cache. */
					printf( esc_html__( '%s already caches your pages on your host’s servers, so Arva SEO leaves page caching to it and pages aren’t cached twice. Keeping it is usually best.', 'arvaseo-connector' ), esc_html( $arvaseo_pc_host['name'] ) );
					?>
				</p>
				<div class="arvaseo-row"><?php $arvaseo_pc_button( 'ours', __( 'Use Arva SEO’s page cache instead', 'arvaseo-connector' ) ); ?></div>
			</div>
			<?php elseif ( 'handled' === $arvaseo_state ) : ?>
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
			<div class="arvaseo-extra"><p><?php echo esc_html( (string) Fixes::unavailable( $arvaseo_id ) ); ?></p></div>
		<?php elseif ( 'trying' === $arvaseo_state ) : ?>
			<div class="arvaseo-extra arvaseo-extra--warn">
				<p>
					<?php esc_html_e( 'Trying it: only you see the change while you’re signed in. Visitors see your site as before.', 'arvaseo-connector' ); ?>
					<a href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open your homepage', 'arvaseo-connector' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'arvaseo-connector' ); ?></span></a>
				</p>
			</div>
		<?php endif; ?>
			<?php if ( 'page_cache' === $arvaseo_id && 'handled' !== $arvaseo_state && $arvaseo_pc['over_host'] && null !== $arvaseo_pc_host ) : ?>
			<div class="arvaseo-extra arvaseo-extra--warn">
				<p>
					<?php
					/* translators: 1: the host's cache, 2: how to turn it off. */
					printf( esc_html__( 'You chose Arva SEO’s page cache over %1$s. Turn the host’s cache off so pages aren’t cached twice. %2$s', 'arvaseo-connector' ), esc_html( $arvaseo_pc_host['name'] ), esc_html( $arvaseo_pc_host['off'] ) );
					?>
				</p>
				<div class="arvaseo-row">
					<?php
					/* translators: %s: the host's cache. */
					$arvaseo_pc_button( 'host', sprintf( __( 'Leave it to %s', 'arvaseo-connector' ), $arvaseo_pc_host['name'] ) );
					?>
				</div>
			</div>
		<?php endif; ?>
			<?php if ( 'page_cache' === $arvaseo_id && null !== PageCache::uncleared_host_cache() ) : ?>
			<div class="arvaseo-extra arvaseo-extra--warn">
				<p>
					<?php
					/* translators: %s: the host's cache, such as SiteGround's dynamic cache. */
					printf( esc_html__( '%s keeps its own copy of your pages, in front of WordPress, and nothing here can clear it when a page changes, so visitors can see an old copy for up to 12 hours. Install SiteGround’s Speed Optimizer plugin (you can turn its caching options off): Arva SEO then clears that copy whenever your pages change. Until then, clear it in Site Tools → Speed → Caching after a change.', 'arvaseo-connector' ), esc_html( (string) PageCache::uncleared_host_cache() ) );
					?>
				</p>
			</div>
		<?php endif; ?>
			<?php if ( 'page_cache' === $arvaseo_id && 'on' === $arvaseo_state && ( ! $arvaseo_pc['active'] || ! PageCache::dropin_is_ours() ) ) : ?>
			<div class="arvaseo-extra arvaseo-extra--warn"><p><?php esc_html_e( 'The page cache isn’t running: its advanced-cache.php file in wp-content was removed or replaced, perhaps by another plugin. Turn this off and on again to set it up again.', 'arvaseo-connector' ); ?></p></div>
		<?php endif; ?>
			<?php if ( 'browser_caching' === $arvaseo_id && 'on' === $arvaseo_state && ! ServerRules::present() ) : ?>
			<div class="arvaseo-extra arvaseo-extra--warn"><p><?php esc_html_e( 'The rules aren’t in your .htaccess file any more: another plugin or your host may have rewritten it. Turn this off and on again to add them back.', 'arvaseo-connector' ); ?></p></div>
		<?php endif; ?>
			<?php if ( in_array( $arvaseo_id, array( 'critical_css', 'unused_css' ), true ) && $arvaseo_css['no_allowance'] > 0 ) : ?>
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

			<?php
			// Details: closed unless something in it is running or being tried.
			$arvaseo_options = in_array( $arvaseo_id, array( 'page_cache', 'browser_caching', 'wp_extras', 'critical_css', 'unused_css', 'webp_uploads', 'compress_images', 'delay_scripts', 'google_fonts', 'defer_scripts', 'combine_css', 'combine_js', 'combine_inline_css', 'combine_inline_js', 'combine_external_css', 'combine_external_js' ), true ) && 'handled' !== $arvaseo_state;
			$arvaseo_open    = false;
			if ( 'webp_uploads' === $arvaseo_id ) {
				$arvaseo_open = $arvaseo_webp['running'];
			}
			if ( 'compress_images' === $arvaseo_id ) {
				$arvaseo_open = $arvaseo_cmp['running'] || $arvaseo_cmp['restoring'];
			}
			if ( 'defer_scripts' === $arvaseo_id && 'trying' === $arvaseo_state ) {
				$arvaseo_open = true;
			}
			$arvaseo_preload = 'page_cache' === $arvaseo_id ? PageCache::preload_state() : null;
			?>
			<?php if ( 'lazy_media' === $arvaseo_id ) : ?>
				<?php require __DIR__ . '/speed-background-media.php'; ?>
			<?php endif; ?>
		<div class="arvaseo-fix-acts">
			<?php
			if ( 'handled' !== $arvaseo_state && 'unavailable' !== $arvaseo_state ) {
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
			}
			?>
			<?php // Shown by admin.js, which hides the details until asked; without it they stay open. ?>
			<button type="button" class="arvaseo-fix-toggle" data-arvaseo-toggle aria-expanded="<?php echo $arvaseo_open ? 'true' : 'false'; ?>" aria-controls="<?php echo esc_attr( 'arvaseo-fix-more-' . $arvaseo_id ); ?>" hidden>
				<?php echo $arvaseo_options ? esc_html__( 'Details and options', 'arvaseo-connector' ) : esc_html__( 'Details', 'arvaseo-connector' ); ?><span class="screen-reader-text">: <?php echo esc_html( $arvaseo_fix['title'] ); ?></span>
			</button>
			<?php
			if ( 'on' === $arvaseo_state && $arvaseo_since ) {
				/* translators: %s: date and time. */
				echo '<span class="arvaseo-hint arvaseo-fix-acts-note">' . esc_html( sprintf( __( 'On since %s.', 'arvaseo-connector' ), AdminController::when( $arvaseo_since ) ) ) . '</span>';
			}
			?>
		</div>


			<?php if ( 'compress_images' === $arvaseo_id && ! in_array( $arvaseo_state, array( 'handled', 'unavailable' ), true ) ) : ?>
				<?php
				$arvaseo_render_library = include __DIR__ . '/speed-image-library.php';
				$arvaseo_render_library( $arvaseo_cmp, $arvaseo_cmp_lib, $arvaseo_state );
				?>
			<p class="arvaseo-hint"><a href="<?php echo esc_url( admin_url( 'upload.php?mode=list' ) ); ?>"><?php esc_html_e( 'Choose specific images in the Media Library', 'arvaseo-connector' ); ?></a> · <?php esc_html_e( 'Local compression uses no credits. Consented cloud compression uses your image allowance only when it makes an image smaller.', 'arvaseo-connector' ); ?></p>
				<?php if ( ! $arvaseo_cmp['keep_originals'] ) : ?>
				<p class="arvaseo-hint"><?php esc_html_e( 'Original backups are currently disabled. Enable Keep the originals below before compressing if you want to restore these images later.', 'arvaseo-connector' ); ?></p>
			<?php endif; ?>
		<?php endif; ?>

			<?php if ( 'defer_scripts' === $arvaseo_id && 'trying' === $arvaseo_state ) : ?>
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

		<div class="arvaseo-fix-more" id="<?php echo esc_attr( 'arvaseo-fix-more-' . $arvaseo_id ); ?>" data-arvaseo-more="<?php echo $arvaseo_open ? 'open' : 'closed'; ?>">
			<div class="arvaseo-fix-more-in">
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

				<?php if ( 'unavailable' === $arvaseo_state && 'page_cache' === $arvaseo_id && ! ( defined( 'WP_CACHE' ) && WP_CACHE ) && ! is_multisite() ) : ?>
					<div class="arvaseo-fix-sub">
						<h4><?php esc_html_e( 'The line for wp-config.php', 'arvaseo-connector' ); ?></h4>
						<p class="arvaseo-hint"><?php esc_html_e( 'Above the line that says “That’s all, stop editing!”:', 'arvaseo-connector' ); ?></p>
						<pre class="arvaseo-code">define( 'WP_CACHE', true );</pre>
					</div>
				<?php endif; ?>

				<?php if ( 'unavailable' === $arvaseo_state && 'browser_caching' === $arvaseo_id ) : ?>
					<div class="arvaseo-fix-sub">
						<h4><?php esc_html_e( 'The rules for your host', 'arvaseo-connector' ); ?></h4>
						<p class="arvaseo-hint"><?php esc_html_e( 'nginx:', 'arvaseo-connector' ); ?></p>
						<pre class="arvaseo-code"><?php echo esc_html( ServerRules::nginx() ); ?></pre>
						<p class="arvaseo-hint"><?php esc_html_e( 'Apache or LiteSpeed (.htaccess):', 'arvaseo-connector' ); ?></p>
						<pre class="arvaseo-code"><?php echo esc_html( implode( "\n", ServerRules::lines() ) ); ?></pre>
					</div>
				<?php endif; ?>

				<?php if ( 'handled' !== $arvaseo_state && 'unavailable' !== $arvaseo_state ) : ?>
					<?php
					$arvaseo_render_advanced = include __DIR__ . '/speed-advanced.php';
					$arvaseo_render_advanced( $arvaseo_id, $arvaseo_stored );
					?>
					<?php if ( 'page_cache' === $arvaseo_id ) : ?>
						<?php if ( 'on' === $arvaseo_state && $arvaseo_pc['active'] ) : ?>
							<?php $arvaseo_pc_stats = PageCache::stats(); ?>
							<ul class="arvaseo-bullets arvaseo-fix-sub">
								<?php if ( null !== $arvaseo_pc['check'] && null !== $arvaseo_pc['check']['before'] && null !== $arvaseo_pc['check']['after'] ) : ?>
									<li>
										<?php
										$arvaseo_secs = static fn ( float $s ): string => $s < 1
											/* translators: %s: milliseconds. */
											? sprintf( __( '%s ms', 'arvaseo-connector' ), number_format_i18n( max( 1, round( $s * 1000 ) ) ) )
											/* translators: %s: seconds. */
											: sprintf( __( '%s s', 'arvaseo-connector' ), number_format_i18n( $s, 1 ) );
										/* translators: 1: time to make the homepage, such as 640 ms, 2: time from the cache. */
										echo esc_html( sprintf( __( 'Checked: your homepage took %1$s to make, and %2$s from the cache (asked for from your server).', 'arvaseo-connector' ), $arvaseo_secs( $arvaseo_pc['check']['before'] ), $arvaseo_secs( $arvaseo_pc['check']['after'] ) ) );
										?>
									</li>
								<?php endif; ?>
								<li><?php echo esc_html( 'htaccess' === $arvaseo_pc['served_by'] ? __( 'Your server sends saved pages itself, through rules in .htaccess, without starting PHP.', 'arvaseo-connector' ) : __( 'Saved pages are sent before WordPress loads (advanced-cache.php in wp-content).', 'arvaseo-connector' ) ); ?></li>
								<li>
									<?php
									echo esc_html(
										sprintf(
											/* translators: 1: number of pages, 2: their size, such as 4 MB. */
											_n( '%1$s page in the cache (%2$s). Each is made again after 10 hours, or when it changes.', '%1$s pages in the cache (%2$s). Each is made again after 10 hours, or when it changes.', $arvaseo_pc_stats['pages'], 'arvaseo-connector' ),
											number_format_i18n( $arvaseo_pc_stats['pages'] ) . ( $arvaseo_pc_stats['capped'] ? '+' : '' ),
											size_format( $arvaseo_pc_stats['bytes'] )
										)
									);
									?>
								</li>
								<?php if ( null !== $arvaseo_preload && array() !== $arvaseo_preload['queue'] ) : ?>
									<li>
										<?php
										/* translators: 1: pages refilled so far, 2: pages to refill. */
										echo esc_html( sprintf( __( 'Refilling the cache in the background: %1$s of %2$s pages, a few at a time.', 'arvaseo-connector' ), number_format_i18n( $arvaseo_preload['done'] ), number_format_i18n( $arvaseo_preload['total'] ) ) );
										?>
									</li>
								<?php endif; ?>
							</ul>
							<div class="arvaseo-row arvaseo-fix-sub"><?php $arvaseo_pc_button( 'clear', __( 'Clear the cache', 'arvaseo-connector' ) ); ?><span class="arvaseo-hint"><?php esc_html_e( 'Also in the toolbar on your site’s pages. Cloudflare’s copy is cleared too, when it’s connected.', 'arvaseo-connector' ); ?></span></div>
						<?php endif; ?>
						<div class="arvaseo-fix-sub">
							<h4><?php esc_html_e( 'Never cached', 'arvaseo-connector' ); ?></h4>
							<p><?php esc_html_e( 'Signed-in users, visitors with a comment, a password or items in their cart, carts, checkouts and account pages, searches and addresses with a query (tracking ones like utm_source are fine), and any page that sets a cookie or asks not to be cached. Pages you leave alone below aren’t cached either.', 'arvaseo-connector' ); ?></p>
						</div>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="arvaseo-fix-sub">
							<h4><?php esc_html_e( 'Choices', 'arvaseo-connector' ); ?></h4>
							<input type="hidden" name="action" value="arvaseo_connector_page_cache" />
							<input type="hidden" name="do" value="options" />
							<?php wp_nonce_field( 'arvaseo_connector_page_cache' ); ?>
							<fieldset class="arvaseo-checks" style="flex-direction: column; margin: 0 0 12px">
								<legend class="screen-reader-text"><?php esc_html_e( 'Page caching choices', 'arvaseo-connector' ); ?></legend>
								<label><input type="checkbox" name="preload" value="1" <?php checked( $arvaseo_pc['preload'] ); ?> /> <span><?php esc_html_e( 'Refill the cache in the background', 'arvaseo-connector' ); ?><br /><span class="arvaseo-hint"><?php esc_html_e( 'After it’s cleared, your homepage, latest posts and pages, and main categories are visited in the background, a few at a time, so the first visitor gets a fast page too.', 'arvaseo-connector' ); ?></span></span></label>
								<label><input type="checkbox" name="phones" value="1" <?php checked( $arvaseo_pc['phones'] ); ?> /> <span><?php esc_html_e( 'A separate copy for phones', 'arvaseo-connector' ); ?><br /><span class="arvaseo-hint"><?php esc_html_e( 'Only for themes or plugins that send phones different HTML (a separate mobile menu or layout). Most themes send the same page and let the screen size decide, so one copy is right and fills twice as fast.', 'arvaseo-connector' ); ?></span></span></label>
							</fieldset>
							<button type="submit" class="arvaseo-btn arvaseo-btn--sm"><?php esc_html_e( 'Save choices', 'arvaseo-connector' ); ?></button>
						</form>
						<?php $arvaseo_backup_links(); ?>
					<?php endif; ?>

					<?php if ( 'browser_caching' === $arvaseo_id ) : ?>
						<?php $arvaseo_check = $arvaseo_stored['server_check']; ?>
						<?php if ( 'on' === $arvaseo_state && ServerRules::present() && null !== $arvaseo_check ) : ?>
							<ul class="arvaseo-bullets arvaseo-fix-sub">
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
						<div class="arvaseo-fix-sub">
							<h4><?php esc_html_e( 'The rules it adds', 'arvaseo-connector' ); ?></h4>
							<p><?php esc_html_e( 'If you edit your theme’s files by hand, visitors may see the old version for a while; WordPress and plugins add a version to their files, so their updates show straight away.', 'arvaseo-connector' ); ?></p>
							<pre class="arvaseo-code"><?php echo esc_html( implode( "\n", ServerRules::lines() ) ); ?></pre>
						</div>
						<?php $arvaseo_backup_links(); ?>
					<?php endif; ?>

					<?php if ( in_array( $arvaseo_id, array( 'dns_prefetch', 'preconnect' ), true ) ) : ?>
						<?php $arvaseo_origins_key = 'preconnect' === $arvaseo_id ? 'preconnect_origins' : 'dns_prefetch_origins'; ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="arvaseo-fix-sub">
							<input type="hidden" name="action" value="arvaseo_connector_speed_options" />
							<input type="hidden" name="fix" value="<?php echo esc_attr( $arvaseo_id ); ?>" />
							<?php wp_nonce_field( 'arvaseo_connector_speed_options' ); ?>
							<label for="<?php echo esc_attr( $arvaseo_origins_key ); ?>"><strong><?php esc_html_e( 'External service addresses, one per line', 'arvaseo-connector' ); ?></strong></label>
							<textarea id="<?php echo esc_attr( $arvaseo_origins_key ); ?>" name="<?php echo esc_attr( $arvaseo_origins_key ); ?>" rows="4" class="large-text code" placeholder="https://cdn.example.com"><?php echo esc_textarea( implode( "\n", $arvaseo_stored[ $arvaseo_origins_key ] ) ); ?></textarea>
							<p class="arvaseo-hint"><?php esc_html_e( 'Save the addresses, then try or turn on this fix. Only public HTTPS origins are kept. Paths, query strings and fragments are removed; credentials, local addresses and custom ports are rejected. An empty list adds no connections.', 'arvaseo-connector' ); ?></p>
							<?php if ( 'preconnect' === $arvaseo_id ) : ?>
								<label><input type="checkbox" name="preconnect_anonymous" value="1" <?php checked( $arvaseo_stored['preconnect_anonymous'] ); ?> /> <?php esc_html_e( 'Use anonymous connections for these origins (web fonts or CORS resources)', 'arvaseo-connector' ); ?></label>
								<p class="arvaseo-hint"><?php esc_html_e( 'Leave this unticked for ordinary third-party scripts. Google Fonts connections are already added by Faster Google Fonts when needed.', 'arvaseo-connector' ); ?></p>
							<?php endif; ?>
							<button type="submit" class="arvaseo-btn arvaseo-btn--sm"><?php esc_html_e( 'Save addresses', 'arvaseo-connector' ); ?></button>
						</form>
					<?php endif; ?>

					<?php if ( in_array( $arvaseo_id, array( 'minify_css', 'minify_js', 'combine_css', 'combine_js' ), true ) ) : ?>
						<?php $arvaseo_minify_key = in_array( $arvaseo_id, array( 'minify_css', 'combine_css' ), true ) ? 'css_exclude' : 'defer_exclude'; ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="arvaseo-fix-sub">
							<input type="hidden" name="action" value="arvaseo_connector_speed_options" />
							<input type="hidden" name="fix" value="<?php echo esc_attr( $arvaseo_id ); ?>" />
							<?php wp_nonce_field( 'arvaseo_connector_speed_options' ); ?>
							<?php if ( in_array( $arvaseo_id, array( 'minify_js', 'combine_js' ), true ) ) : ?>
								<input type="hidden" name="optimization_policy_shown" value="1" />
								<label><input type="checkbox" name="optimization_separate" value="1" <?php checked( $arvaseo_stored[ $arvaseo_id . '_separate' ] ); ?> /> <?php esc_html_e( 'Use a separate exclusion list for this fix', 'arvaseo-connector' ); ?></label>
								<p class="arvaseo-hint"><?php esc_html_e( 'Unticked: keep using the Defer scripts exclusions. Ticked: use only the list below for this fix. jQuery, consent tools and integration vetoes stay protected. Saving a changed list or mode returns an active fix to Trying; check your pages before enabling it for visitors.', 'arvaseo-connector' ); ?></p>
								<label for="<?php echo esc_attr( $arvaseo_id . '-exclude' ); ?>"><?php esc_html_e( 'Leave these scripts alone, one handle or file address per line', 'arvaseo-connector' ); ?></label>
								<textarea id="<?php echo esc_attr( $arvaseo_id . '-exclude' ); ?>" name="optimization_exclude" rows="3" class="large-text code"><?php echo esc_textarea( implode( "\n", $arvaseo_stored[ $arvaseo_id . '_exclude' ] ) ); ?></textarea>
							<?php else : ?>
							<label for="<?php echo esc_attr( $arvaseo_id . '-exclude' ); ?>"><strong><?php esc_html_e( 'Leave these files alone, one handle per line', 'arvaseo-connector' ); ?></strong></label>
							<textarea id="<?php echo esc_attr( $arvaseo_id . '-exclude' ); ?>" name="<?php echo esc_attr( $arvaseo_minify_key ); ?>" rows="3" class="large-text code"><?php echo esc_textarea( implode( "\n", $arvaseo_stored[ $arvaseo_minify_key ] ) ); ?></textarea>
							<p class="arvaseo-hint"><?php esc_html_e( 'CSS exclusions are shared with optimized CSS and CSS combining; script exclusions are shared with Defer scripts and JavaScript combining. Minification and combining are separate options. Unsupported files keep their original delivery.', 'arvaseo-connector' ); ?></p>
							<?php endif; ?>
							<button type="submit" class="arvaseo-btn arvaseo-btn--sm"><?php esc_html_e( 'Save exclusions', 'arvaseo-connector' ); ?></button>
						</form>
					<?php endif; ?>

					<?php if ( 'wp_extras' === $arvaseo_id ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="arvaseo-fix-sub">
							<h4><?php esc_html_e( 'Choose what to remove', 'arvaseo-connector' ); ?></h4>
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
					<?php endif; ?>

					<?php if ( in_array( $arvaseo_id, array( 'critical_css', 'unused_css' ), true ) ) : ?>
						<p class="arvaseo-fix-sub">
							<?php
							printf(
								/* translators: 1: page types with optimized CSS, 2: page types waiting. */
								esc_html__( 'Optimized CSS for %1$d page types; %2$d waiting.', 'arvaseo-connector' ),
								(int) $arvaseo_css['ready'],
								(int) $arvaseo_css['waiting']
							);
							if ( $arvaseo_css['failed'] > 0 ) {
								echo ' ';
								/* translators: %d: page types that couldn't be optimized. */
								printf( esc_html( _n( '%d couldn’t be optimized.', '%d couldn’t be optimized.', (int) $arvaseo_css['failed'], 'arvaseo-connector' ) ), (int) $arvaseo_css['failed'] );
							}
							echo ' <a href="#arvaseo-css-quota">' . esc_html__( 'View shared CSS quota', 'arvaseo-connector' ) . '</a>';
							?>
						</p>
						<div class="arvaseo-fix-sub">
							<h4><?php esc_html_e( 'Optimized CSS: work it out again, or leave stylesheets alone', 'arvaseo-connector' ); ?></h4>
							<p class="arvaseo-hint">
							<?php
							if ( $arvaseo_stored['ccss_per_url'] ) {
								esc_html_e( 'Each public page address and its stylesheets get separate critical and used CSS. New page results use page optimizations; clearing the page cache does not regenerate CSS.', 'arvaseo-connector' );
							} else {
								esc_html_e( 'Pages are grouped by type (homepage, page, post, product, category…) and by their stylesheets; each group uses one page optimization. A stylesheet that changes, after a theme or plugin update, makes a new group; clearing your cache costs nothing.', 'arvaseo-connector' );
							}
							?>
							</p>
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
							<?php
							$arvaseo_css_said = \ArvaSEO\Connector\Admin\AdminController::css_notice();
							if ( null !== $arvaseo_css_said ) :
								?>
								<p class="arvaseo-fix-note" role="status"><?php echo esc_html( $arvaseo_css_said ); ?></p>
							<?php endif; ?>
							<?php if ( $arvaseo_css['waiting'] > 0 ) : ?>
								<p class="arvaseo-hint" data-arvaseo-css-waiting="<?php echo esc_attr( (string) $arvaseo_css['waiting'] ); ?>" data-arvaseo-css-ready="<?php echo esc_attr( (string) $arvaseo_css['ready'] ); ?>">
									<?php
									/* translators: %d: page types being optimized. */
									printf( esc_html( _n( 'Waiting for matching CSS: %d page type. Open Pages waiting for optimized CSS above for local status details.', 'Waiting for matching CSS: %d page types. Open Pages waiting for optimized CSS above for local status details.', (int) $arvaseo_css['waiting'], 'arvaseo-connector' ) ), (int) $arvaseo_css['waiting'] );
									?>
								</p>
							<?php endif; ?>
							<?php $arvaseo_css_failed = \ArvaSEO\Connector\Speed\CssOptimizer::failures(); ?>
							<?php if ( array() !== $arvaseo_css_failed ) : ?>
								<div class="arvaseo-css-failed">
									<h5><?php esc_html_e( 'Couldn’t be optimized', 'arvaseo-connector' ); ?></h5>
									<ul>
										<?php foreach ( array_slice( $arvaseo_css_failed, 0, 10 ) as $arvaseo_failure ) : ?>
											<li>
												<strong><?php echo esc_html( $arvaseo_failure['label'] ); ?></strong>
												<a href="<?php echo esc_url( $arvaseo_failure['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( (string) wp_parse_url( $arvaseo_failure['url'], PHP_URL_PATH ) ); ?></a>
												<span class="arvaseo-hint"><?php echo esc_html( $arvaseo_failure['reason'] ); ?></span>
											</li>
										<?php endforeach; ?>
									</ul>
									<p class="arvaseo-hint"><?php esc_html_e( 'Optimize now tries these again. They load as before in the meantime.', 'arvaseo-connector' ); ?></p>
								</div>
							<?php endif; ?>
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
					<?php endif; ?>

					<?php if ( 'webp_uploads' === $arvaseo_id ) : ?>
						<div class="arvaseo-fix-sub">
							<h4><?php esc_html_e( 'Convert your existing images too', 'arvaseo-connector' ); ?></h4>
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
					<?php endif; ?>

					<?php if ( 'compress_images' === $arvaseo_id ) : ?>
						<div class="arvaseo-fix-sub">
							<p>
								<?php
								if ( ! TokenStore::is_connected() ) {
									esc_html_e( 'On this server. Connect to Arva SEO to have its servers compress images more strongly (100 a month on the Free plan).', 'arvaseo-connector' );
								} elseif ( ! $arvaseo_cmp_cloud ) {
									esc_html_e( 'On this server. Arva SEO’s servers compress images more strongly once you allow it below.', 'arvaseo-connector' );
								} elseif ( 'no_allowance' === $arvaseo_cmp['cloud_reason'] && time() < $arvaseo_cmp['cloud_paused_until'] ) {
									/* translators: %s: date, such as November 1. */
									echo esc_html( sprintf( __( 'No image compressions left this month on Arva SEO’s servers, so this server compresses images until %s.', 'arvaseo-connector' ), wp_date( get_option( 'date_format' ), $arvaseo_cmp['cloud_paused_until'] ) ) );
								} elseif ( '' !== $arvaseo_cmp['cloud_reason'] && time() < $arvaseo_cmp['cloud_paused_until'] ) {
									esc_html_e( 'Arva SEO’s servers didn’t answer a moment ago, so images wait a few minutes for them.', 'arvaseo-connector' );
								} elseif ( is_array( $arvaseo_cmp_left ) ) {
									echo esc_html(
										$arvaseo_cmp_left['packs'] > 0
											/* translators: 1: compressions left this month, 2: included a month, 3: compressions in top-ups. */
											? sprintf( __( 'On Arva SEO’s servers: %1$s of %2$s image compressions left this month, and %3$s in top-ups.', 'arvaseo-connector' ), number_format_i18n( max( 0, $arvaseo_cmp_left['remaining'] - $arvaseo_cmp_left['packs'] ) ), number_format_i18n( $arvaseo_cmp_left['included'] ), number_format_i18n( $arvaseo_cmp_left['packs'] ) )
											/* translators: 1: compressions left this month, 2: included a month. */
											: sprintf( __( 'On Arva SEO’s servers: %1$s of %2$s image compressions left this month.', 'arvaseo-connector' ), number_format_i18n( $arvaseo_cmp_left['remaining'] ), number_format_i18n( $arvaseo_cmp_left['included'] ) )
									);
								} else {
									esc_html_e( 'On Arva SEO’s servers. Each image counts once, with all its sizes, and only when it gets at least 5% smaller.', 'arvaseo-connector' );
								}
								?>
								<?php if ( is_array( $arvaseo_cmp_left ) && null !== ( $arvaseo_cmp_left['buy_url'] ?? null ) && $arvaseo_cmp_left['remaining'] < 50 ) : ?>
									<a href="<?php echo esc_url( (string) $arvaseo_cmp_left['buy_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Get more image compressions', 'arvaseo-connector' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'arvaseo-connector' ); ?></span></a>
								<?php endif; ?>
							</p>
							<?php if ( $arvaseo_cmp_stats['images'] > 0 ) : ?>
								<p class="arvaseo-hint">
									<?php
									/* translators: 1: number of images, 2: space saved, such as 12 MB. */
									echo esc_html( sprintf( _n( '%1$s image compressed so far, %2$s smaller.', '%1$s images compressed so far, %2$s smaller.', $arvaseo_cmp_stats['images'], 'arvaseo-connector' ), number_format_i18n( $arvaseo_cmp_stats['images'] ), (string) size_format( $arvaseo_cmp_stats['saved_bytes'] ) ) );
									?>
								</p>
							<?php endif; ?>
						</div>

						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="arvaseo-fix-sub">
							<h4><?php esc_html_e( 'Where images are compressed, and the originals', 'arvaseo-connector' ); ?></h4>
							<p><?php /* translators: %s: images made smaller in the cloud, a subset of the total. */ printf( esc_html__( 'Images made smaller on Arva SEO’s servers: %s. The compression total above includes local and cloud processing.', 'arvaseo-connector' ), esc_html( number_format_i18n( $arvaseo_cmp_stats['cloud'] ) ) ); ?></p>
							<p class="arvaseo-hint"><?php esc_html_e( 'JPEG and PNG files can use Arva SEO’s servers; WebP files are compressed locally. Checked images include files that could not safely get smaller. Compression does not resize oversized images. Cloud failures pause requests and preserve originals.', 'arvaseo-connector' ); ?></p>
							<input type="hidden" name="action" value="arvaseo_connector_compress" />
							<input type="hidden" name="do" value="options" />
							<?php wp_nonce_field( 'arvaseo_connector_compress' ); ?>
							<?php if ( TokenStore::is_connected() && null !== PrivacyService::current() ) : ?>
								<label class="arvaseo-toggle"><input type="checkbox" name="cloud" value="1" <?php checked( $arvaseo_cmp_cloud ); ?> /><span class="arvaseo-switch" aria-hidden="true"></span>
									<span><strong><?php esc_html_e( 'Use Arva SEO’s servers', 'arvaseo-connector' ); ?></strong><br /><span class="arvaseo-hint"><?php esc_html_e( 'Each image and its sizes are sent to Arva SEO, compressed more strongly than this server can, sent back and deleted from Arva SEO straight away. Each image counts once against your plan’s monthly image compressions, and only when it gets at least 5% smaller. When none are left, this server carries on.', 'arvaseo-connector' ); ?></span></span>
								</label>
							<?php elseif ( TokenStore::is_connected() ) : ?>
								<p class="arvaseo-hint"><?php esc_html_e( 'To use Arva SEO’s servers, share data with Arva SEO first (Settings → Diagnostics and data sharing).', 'arvaseo-connector' ); ?></p>
							<?php endif; ?>
							<label class="arvaseo-toggle" style="margin-top: 10px"><input type="checkbox" name="keep_originals" value="1" <?php checked( $arvaseo_cmp['keep_originals'] ); ?> /><span class="arvaseo-switch" aria-hidden="true"></span>
								<span><strong><?php esc_html_e( 'Keep the originals', 'arvaseo-connector' ); ?></strong><br /><span class="arvaseo-hint"><?php esc_html_e( 'A copy of each original is kept in wp-content/arvaseo-originals, so Restore can put it back. Turn this off to save disk space: images compressed after that can’t be put back.', 'arvaseo-connector' ); ?></span></span>
							</label>
							<div class="arvaseo-row" style="margin-top: 12px"><button type="submit" class="arvaseo-btn arvaseo-btn--sm"><?php esc_html_e( 'Save', 'arvaseo-connector' ); ?></button></div>
						</form>
					<?php endif; ?>

					<?php if ( 'delay_scripts' === $arvaseo_id ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="arvaseo-fix-sub">
							<h4><?php esc_html_e( 'The widgets it delays, and others to delay', 'arvaseo-connector' ); ?></h4>
							<input type="hidden" name="action" value="arvaseo_connector_speed_options" />
							<input type="hidden" name="fix" value="delay_scripts" />
							<?php wp_nonce_field( 'arvaseo_connector_speed_options' ); ?>
							<p class="arvaseo-hint"><?php esc_html_e( 'Tawk.to, Crisp, Intercom, LiveChat, Tidio, Zendesk, Drift and HubSpot chat; Hotjar and Microsoft Clarity; Facebook, X (Twitter) and Pinterest widgets; Elfsight widgets. Only scripts your theme or plugins add the WordPress way can be delayed.', 'arvaseo-connector' ); ?></p>
							<label class="arvaseo-label" for="arvaseo-delay-scripts" style="margin-top: 10px"><?php esc_html_e( 'Also delay these scripts', 'arvaseo-connector' ); ?></label>
							<p class="arvaseo-hint"><?php esc_html_e( 'One per line: a script name (handle) or part of its file address. A script that other scripts need is never delayed. To keep a widget loading straight away on some pages, leave those pages alone below.', 'arvaseo-connector' ); ?></p>
							<textarea id="arvaseo-delay-scripts" name="delay_scripts" rows="3" placeholder="reviews-widget&#10;widget.example.com"><?php echo esc_textarea( implode( "\n", $arvaseo_stored['delay_scripts'] ) ); ?></textarea>
							<div class="arvaseo-row" style="margin-top: 10px"><button type="submit" class="arvaseo-btn arvaseo-btn--sm"><?php esc_html_e( 'Save', 'arvaseo-connector' ); ?></button></div>
						</form>
					<?php endif; ?>

					<?php if ( 'google_fonts' === $arvaseo_id ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="arvaseo-fix-sub">
							<h4><?php esc_html_e( 'Serve them from your site', 'arvaseo-connector' ); ?></h4>
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
					<?php endif; ?>

					<?php if ( 'defer_scripts' === $arvaseo_id ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="arvaseo-fix-sub">
							<h4><?php esc_html_e( 'JavaScript loading mode', 'arvaseo-connector' ); ?></h4>
							<p class="arvaseo-hint"><?php esc_html_e( 'Deferred targets scripts registered for the head. WordPress can keep one blocking when inline code or other scripts depend on it. Footer scripts already load below the page content. An empty exclusion list does not remove jQuery or consent protection. Moving jQuery changes its position; it never adds defer to jQuery. The preview list reports emitted defer attributes, not just requested strategies.', 'arvaseo-connector' ); ?></p>
							<p class="arvaseo-hint"><?php esc_html_e( 'Deferred runs supported scripts after HTML is parsed. Delayed waits for the first interaction. Dependencies, consent tools, measurement scripts and your exclusions load as before. Changing a public mode returns this fix to preview until you check your pages again.', 'arvaseo-connector' ); ?></p>
							<fieldset class="arvaseo-checks">
								<legend class="screen-reader-text"><?php esc_html_e( 'When JavaScript loads', 'arvaseo-connector' ); ?></legend>
								<label><input type="radio" name="js_loading_mode" value="deferred" <?php checked( $arvaseo_stored['js_loading_mode'], 'deferred' ); ?> /> <?php esc_html_e( 'Deferred', 'arvaseo-connector' ); ?></label>
								<label><input type="radio" name="js_loading_mode" value="delayed" <?php checked( $arvaseo_stored['js_loading_mode'], 'delayed' ); ?> /> <?php esc_html_e( 'Delayed', 'arvaseo-connector' ); ?></label>
							</fieldset>
							<h4><?php esc_html_e( 'Move jQuery down the page', 'arvaseo-connector' ); ?></h4>
							<input type="hidden" name="move_jquery_shown" value="1" />
							<label class="arvaseo-toggle"><input type="checkbox" name="move_jquery" value="1" <?php checked( $arvaseo_stored['move_jquery'] ); ?> /><span class="arvaseo-switch" aria-hidden="true"></span>
								<span><strong><?php esc_html_e( 'Move jQuery down the page', 'arvaseo-connector' ); ?></strong><br /><span class="arvaseo-hint"><?php esc_html_e( 'jQuery stops holding up the first paint: it moves from the head to just before the first script that may use it. It stays in the head when something before it may run at any time and use jQuery: Google Tag Manager or another async script, a consent tool or tag manager loaded from another website, or a script a consent tool holds back until the visitor agrees. Turning this on returns this fix to preview until you check your pages again, including anything a tag manager adds.', 'arvaseo-connector' ); ?></span></span>
							</label>
							<h4><?php esc_html_e( 'Leave scripts alone', 'arvaseo-connector' ); ?></h4>
							<input type="hidden" name="action" value="arvaseo_connector_speed_options" />
							<input type="hidden" name="fix" value="defer_scripts" />
							<?php wp_nonce_field( 'arvaseo_connector_speed_options' ); ?>
							<div data-arvaseo-deferred-list data-refresh="<?php echo 'trying' === $arvaseo_state ? '1' : '0'; ?>" aria-live="polite">
								<?php require __DIR__ . '/deferred-scripts.php'; ?>
							</div>
							<label class="arvaseo-label" for="arvaseo-defer-exclude" style="margin-top: 10px"><?php esc_html_e( 'Leave these scripts alone', 'arvaseo-connector' ); ?></label>
							<p class="arvaseo-hint"><?php esc_html_e( 'One per line: a script name (handle), such as one from the list above, or part of its file address, such as booking.js or /plugins/contact-form-7/. Consent or cookie tools are always left alone, and jQuery is never deferred; while it moves down the page, list jquery-core to keep it in the head.', 'arvaseo-connector' ); ?></p>
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
					<?php endif; ?>
				<?php endif; ?>

				<?php if ( ! in_array( $arvaseo_id, array( 'webp_uploads', 'browser_caching' ), true ) ) : ?>
					<div class="arvaseo-fix-links">
						<a href="#arvaseo-speed-exclusions"><?php echo in_array( $arvaseo_id, Exclusions::ELEMENT_FIXES, true ) ? esc_html__( 'Leave a page, image or video alone', 'arvaseo-connector' ) : esc_html__( 'Leave a page alone', 'arvaseo-connector' ); ?></a>
					</div>
				<?php endif; ?>
			</div>
		</div>
	</section>
		<?php endforeach; ?>
</div></div>
</div>
	<?php endforeach; ?>

<div data-arvaseo-speed-section="caching">
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
		<p class="arvaseo-hint"><?php esc_html_e( 'Cloudflare can keep copies of your pages. Connect it here and those copies are cleared whenever a change here affects what visitors get, so visitors and your next PageSpeed test see a fix as soon as you turn it on. It works without an Arva SEO account. Only page addresses are sent, to Cloudflare.', 'arvaseo-connector' ); ?></p>
		<div class="arvaseo-row" style="margin-top: 14px">
			<button type="button" class="arvaseo-btn arvaseo-btn--sm arvaseo-btn--dark" data-arvaseo-toggle="once" aria-expanded="false" aria-controls="arvaseo-cf-connect" hidden><?php esc_html_e( 'Connect Cloudflare', 'arvaseo-connector' ); ?></button>
		</div>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="arvaseo-fix-more" id="arvaseo-cf-connect" data-arvaseo-more="closed" data-arvaseo-hash="arvaseo-cloudflare" style="margin-top: 16px">
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

</div>
<div data-arvaseo-speed-section="advanced">
<div class="arvaseo-grp" id="arvaseo-speed-exclusions">
	<span class="arvaseo-grp-n" aria-hidden="true"><?php echo esc_html( sprintf( '%02d', $arvaseo_n + 2 ) ); ?></span>
	<div><h2><?php esc_html_e( 'Leave things alone', 'arvaseo-connector' ); ?></h2><p><?php esc_html_e( 'If a fix stops something working on part of your site, leave that part alone instead of turning the fix off everywhere.', 'arvaseo-connector' ); ?></p></div>
</div>
	<?php
	$arvaseo_skipped = Exclusions::skipped_posts();
	$arvaseo_left    = array_filter(
		array(
			/* translators: %d: number of page addresses. */
			array() !== $arvaseo_stored['exclude_pages'] ? sprintf( _n( '%d page address', '%d page addresses', count( $arvaseo_stored['exclude_pages'] ), 'arvaseo-connector' ), count( $arvaseo_stored['exclude_pages'] ) ) : '',
			/* translators: %d: number of posts and pages. */
			$arvaseo_skipped > 0 ? sprintf( _n( '%d post or page by its tick box', '%d posts and pages by their tick box', $arvaseo_skipped, 'arvaseo-connector' ), $arvaseo_skipped ) : '',
			/* translators: %d: number of images, videos and maps. */
			array() !== $arvaseo_stored['exclude_elements'] ? sprintf( _n( '%d image, video or map', '%d images, videos or maps', count( $arvaseo_stored['exclude_elements'] ), 'arvaseo-connector' ), count( $arvaseo_stored['exclude_elements'] ) ) : '',
		)
	);
	?>
<div class="arvaseo-collapse-head">
	<span class="arvaseo-hint">
		<?php
		echo esc_html(
			array() === $arvaseo_left
				? __( 'Nothing is left alone: every fix that’s on runs on every page.', 'arvaseo-connector' )
				/* translators: %s: what is left alone, such as "2 page addresses and 3 images, videos or maps". */
				: sprintf( __( 'Left alone: %s.', 'arvaseo-connector' ), wp_sprintf_l( '%l', array_values( $arvaseo_left ) ) )
		);
		?>
	</span>
</div>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="arvaseo-fix-more" id="arvaseo-exclusions-form">
	<input type="hidden" name="action" value="arvaseo_connector_speed_options" />
	<input type="hidden" name="fix" value="exclusions" />
	<?php wp_nonce_field( 'arvaseo_connector_speed_options' ); ?>
	<div class="arvaseo-grid2">
		<section class="arvaseo-card">
			<label class="arvaseo-label" for="arvaseo-exclude-pages"><?php esc_html_e( 'Pages', 'arvaseo-connector' ); ?></label>
			<p class="arvaseo-hint"><?php esc_html_e( 'No fix runs on these. One address per line, the part after your domain, such as /contact/. A * matches anything: /shop/* is every page under /shop/. You can also tick “Leave this page alone” when you edit a post or page, or use Arva SEO in the toolbar while you view any page.', 'arvaseo-connector' ); ?></p>
			<textarea id="arvaseo-exclude-pages" name="exclude_pages" rows="4" placeholder="/checkout/&#10;/book-now/*"><?php echo esc_textarea( implode( "\n", $arvaseo_stored['exclude_pages'] ) ); ?></textarea>
			<label class="arvaseo-label" for="arvaseo-html-keep-comments"><?php esc_html_e( 'Keep these HTML comments', 'arvaseo-connector' ); ?></label>
			<p class="arvaseo-hint"><?php esc_html_e( 'One fragment per line. Minify HTML preserves any comment containing it, as well as known cache, builder and conditional comments.', 'arvaseo-connector' ); ?></p>
			<textarea id="arvaseo-html-keep-comments" name="html_keep_comments" rows="3"><?php echo esc_textarea( implode( "\n", $arvaseo_stored['html_keep_comments'] ) ); ?></textarea>
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
				<label class="arvaseo-check" style="margin-top: 12px"><input type="checkbox" name="shop_pages" value="1" <?php checked( $arvaseo_stored['shop_pages'] ); ?> /> <span><?php esc_html_e( 'Leave cart, checkout and account pages alone', 'arvaseo-connector' ); ?><br /><span class="arvaseo-hint"><?php esc_html_e( 'Recommended: payment and account forms often need their scripts loaded in order.', 'arvaseo-connector' ); ?></span></span></label>
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
			<li><code>arvaseo_connector_page_cache_ignored_params</code> <span class="arvaseo-hint"><?php esc_html_e( '(string[] $names): query parameters that never change a page, besides the tracking ones Page caching already ignores (utm_source, gclid, fbclid, gad_source and more).', 'arvaseo-connector' ); ?></span></li>
		</ul>
		<p class="arvaseo-hint" style="margin-top: 10px"><?php esc_html_e( 'Lazy-load also leaves alone images marked skip-lazy or no-lazy, as other speed plugins do, and every fix leaves alone an element with the data-arvaseo-skip attribute.', 'arvaseo-connector' ); ?></p>
	</div>
</details>

<p class="arvaseo-hint" style="margin-top: 20px; max-width: 820px"><?php esc_html_e( 'Arva SEO can cache pages and minify supported local CSS and JavaScript. Use one optimizer for each job; when another active plugin handles it, Arva SEO leaves that fix alone. Arva SEO clears the caches it can reach when a fix changes; a cache it can’t reach, such as a host’s CDN, needs you to clear it after each change. In an emergency, add define( \'ARVASEO_CONNECTOR_DISABLE_SPEED_FIXES\', true ); to wp-config.php to switch every fix off.', 'arvaseo-connector' ); ?></p>
</div>
</div>
	<?php
} )();
