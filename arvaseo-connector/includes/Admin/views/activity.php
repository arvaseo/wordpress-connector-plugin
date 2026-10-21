<?php
/**
 * Activity.
 *
 * @package ArvaSEO\Connector
 */

use ArvaSEO\Connector\Admin\AdminController;
use ArvaSEO\Connector\Install\Schema;
use ArvaSEO\Connector\Settings;

defined( 'ABSPATH' ) || exit;

// Scoped so the view's variables stay local.
( static function () {

	global $wpdb;
	$arvaseo_table = Schema::table( 'audit_log' );
	$arvaseo_level = isset( $_GET['level'] ) ? sanitize_key( wp_unslash( $_GET['level'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
	$arvaseo_level = in_array( $arvaseo_level, array( 'info', 'warning', 'error' ), true ) ? $arvaseo_level : '';
	$arvaseo_page  = max( 1, absint( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- pagination.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- own table.
	if ( '' === $arvaseo_level ) {
		$arvaseo_total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $arvaseo_table ) );
		$arvaseo_rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT 50 OFFSET %d', $arvaseo_table, ( $arvaseo_page - 1 ) * 50 ), ARRAY_A );
	} else {
		$arvaseo_total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE level = %s', $arvaseo_table, $arvaseo_level ) );
		$arvaseo_rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE level = %s ORDER BY id DESC LIMIT 50 OFFSET %d', $arvaseo_table, $arvaseo_level, ( $arvaseo_page - 1 ) * 50 ), ARRAY_A );
	}
// phpcs:enable
	$arvaseo_tones  = array(
		'info'    => 'neutral',
		'warning' => 'warn',
		'error'   => 'bad',
	);
	$arvaseo_levels = array(
		''        => __( 'Everything', 'arvaseo-connector' ),
		'warning' => __( 'Warnings', 'arvaseo-connector' ),
		'error'   => __( 'Errors', 'arvaseo-connector' ),
	);
	?>
<section class="arvaseo-card" aria-label="<?php esc_attr_e( 'Connector activity', 'arvaseo-connector' ); ?>">
	<div class="arvaseo-between" style="align-items: center">
		<nav class="arvaseo-seg" aria-label="<?php esc_attr_e( 'Show', 'arvaseo-connector' ); ?>">
			<?php foreach ( $arvaseo_levels as $arvaseo_key => $arvaseo_label ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=arvaseo-activity' . ( '' === $arvaseo_key ? '' : '&level=' . $arvaseo_key ) ) ); ?>"<?php echo $arvaseo_key === $arvaseo_level ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $arvaseo_label ); ?></a>
			<?php endforeach; ?>
		</nav>
		<span class="arvaseo-hint">
			<?php
			/* translators: 1: number of days, 2: site time zone. */
			printf( esc_html__( 'Kept for %1$d days (Settings). Times are in your site’s time zone, %2$s.', 'arvaseo-connector' ), (int) Settings::all()['log_retention_days'], esc_html( wp_timezone_string() ) );
			?>
		</span>
	</div>
	<?php if ( empty( $arvaseo_rows ) ) : ?>
		<div class="arvaseo-empty" style="padding-top: 24px">
			<span class="arvaseo-empty-ic" aria-hidden="true"><?php echo AdminController::icon( 'check', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></span>
			<div><h3><?php esc_html_e( 'Nothing to look at', 'arvaseo-connector' ); ?></h3><p><?php echo '' === $arvaseo_level ? esc_html__( 'No activity yet.', 'arvaseo-connector' ) : esc_html__( 'Nothing of this kind in the log.', 'arvaseo-connector' ); ?></p></div>
		</div>
	<?php else : ?>
		<div class="arvaseo-tablebox">
			<table class="arvaseo-table">
				<caption class="screen-reader-text"><?php esc_html_e( 'Connector activity', 'arvaseo-connector' ); ?></caption>
				<thead><tr>
					<th scope="col"><?php esc_html_e( 'When', 'arvaseo-connector' ); ?></th>
					<th scope="col"><?php esc_html_e( 'What happened', 'arvaseo-connector' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Details', 'arvaseo-connector' ); ?></th>
					<th scope="col"><?php esc_html_e( 'By', 'arvaseo-connector' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( (array) $arvaseo_rows as $arvaseo_row ) : ?>
					<?php $arvaseo_user = $arvaseo_row['user_id'] ? get_userdata( (int) $arvaseo_row['user_id'] ) : false; ?>
					<tr>
						<td><time><?php echo esc_html( AdminController::when( $arvaseo_row['created_at'] . ' UTC' ) ); ?></time></td>
						<td>
							<div style="margin-bottom: 6px"><?php echo AdminController::badge( $arvaseo_tones[ $arvaseo_row['level'] ] ?? 'neutral', ucfirst( $arvaseo_row['level'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes. ?></div>
							<?php echo esc_html( $arvaseo_row['message'] ); ?><br /><code><?php echo esc_html( $arvaseo_row['event'] ); ?></code>
						</td>
						<td>
							<?php if ( '' !== (string) $arvaseo_row['context'] && '[]' !== (string) $arvaseo_row['context'] ) : ?>
								<details class="arvaseo-more" style="margin-top: 0"><summary><?php esc_html_e( 'Show', 'arvaseo-connector' ); ?></summary><code class="arvaseo-value"><?php echo esc_html( (string) $arvaseo_row['context'] ); ?></code></details>
							<?php else : ?>
								—
							<?php endif; ?>
						</td>
						<td><?php echo $arvaseo_user ? esc_html( $arvaseo_user->display_name ) : esc_html__( 'Automatic', 'arvaseo-connector' ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<div class="arvaseo-pages">
			<?php
			echo wp_kses_post(
				(string) paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'current' => $arvaseo_page,
						'total'   => max( 1, (int) ceil( $arvaseo_total / 50 ) ),
					)
				)
			);
			?>
		</div>
	<?php endif; ?>
</section>
	<?php
} )();
