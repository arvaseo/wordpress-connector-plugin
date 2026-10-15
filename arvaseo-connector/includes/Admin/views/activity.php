<?php
/**
 * Activity Log page.
 *
 * @package ArvaSEO\Connector
 */

use ArvaSEO\Connector\Admin\AdminController;
use ArvaSEO\Connector\Install\Schema;

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
	$arvaseo_tones = array(
		'info'    => 'neutral',
		'warning' => 'warn',
		'error'   => 'bad',
	);
	?>
<p><?php esc_html_e( 'What the connector did, without tokens, codes, content or personal data. Kept for the number of days set in Settings.', 'arvaseo-connector' ); ?></p>
<form method="get" class="arvaseo-filter">
	<input type="hidden" name="page" value="arvaseo-activity" />
	<label for="arvaseo-level"><?php esc_html_e( 'Show', 'arvaseo-connector' ); ?></label>
	<select id="arvaseo-level" name="level">
		<option value=""><?php esc_html_e( 'Everything', 'arvaseo-connector' ); ?></option>
		<option value="warning" <?php selected( $arvaseo_level, 'warning' ); ?>><?php esc_html_e( 'Warnings', 'arvaseo-connector' ); ?></option>
		<option value="error" <?php selected( $arvaseo_level, 'error' ); ?>><?php esc_html_e( 'Errors', 'arvaseo-connector' ); ?></option>
	</select>
	<button type="submit" class="button"><?php esc_html_e( 'Filter', 'arvaseo-connector' ); ?></button>
</form>
<table class="widefat striped">
	<caption class="screen-reader-text"><?php esc_html_e( 'Connector activity', 'arvaseo-connector' ); ?></caption>
	<thead><tr>
		<th scope="col"><?php esc_html_e( 'Time', 'arvaseo-connector' ); ?></th>
		<th scope="col"><?php esc_html_e( 'Level', 'arvaseo-connector' ); ?></th>
		<th scope="col"><?php esc_html_e( 'What happened', 'arvaseo-connector' ); ?></th>
		<th scope="col"><?php esc_html_e( 'Details', 'arvaseo-connector' ); ?></th>
		<th scope="col"><?php esc_html_e( 'By', 'arvaseo-connector' ); ?></th>
	</tr></thead>
	<tbody>
	<?php if ( empty( $arvaseo_rows ) ) : ?>
		<tr><td colspan="5"><?php esc_html_e( 'No activity yet.', 'arvaseo-connector' ); ?></td></tr>
	<?php endif; ?>
	<?php foreach ( (array) $arvaseo_rows as $arvaseo_row ) : ?>
		<?php $arvaseo_user = $arvaseo_row['user_id'] ? get_userdata( (int) $arvaseo_row['user_id'] ) : false; ?>
		<tr>
			<td><?php echo esc_html( AdminController::when( $arvaseo_row['created_at'] . ' UTC' ) ); ?></td>
			<td><?php echo wp_kses_post( AdminController::badge( $arvaseo_tones[ $arvaseo_row['level'] ] ?? 'neutral', ucfirst( $arvaseo_row['level'] ) ) ); ?></td>
			<td><?php echo esc_html( $arvaseo_row['message'] ); ?><br /><code><?php echo esc_html( $arvaseo_row['event'] ); ?></code></td>
			<td><code class="arvaseo-value"><?php echo esc_html( (string) $arvaseo_row['context'] ); ?></code></td>
			<td><?php echo $arvaseo_user ? esc_html( $arvaseo_user->display_name ) : esc_html__( 'Automatic', 'arvaseo-connector' ); ?></td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
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
} )();
