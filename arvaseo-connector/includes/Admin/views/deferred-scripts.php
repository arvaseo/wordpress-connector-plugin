<?php
/**
 * The script list, shared by the Speed page and its refresh request.
 *
 * @package ArvaSEOConnector
 */

defined( 'ABSPATH' ) || exit;

$arvaseo_connector_deferred = get_transient( 'arvaseo_connector_deferred' );
$arvaseo_connector_stored   = \ArvaSEO\Connector\Speed\Fixes::stored();
$arvaseo_connector_state    = \ArvaSEO\Connector\Speed\Fixes::state( 'defer_scripts' );
?>
<?php if ( false === $arvaseo_connector_deferred && 'trying' === $arvaseo_connector_state ) : ?>
	<p class="arvaseo-hint"><?php esc_html_e( 'Open your homepage while trying it: the scripts it defers or delays appear here when you return, so you can leave one alone if something stops working.', 'arvaseo-connector' ); ?></p>
<?php elseif ( array() === $arvaseo_connector_deferred && 'trying' === $arvaseo_connector_state ) : ?>
	<p class="arvaseo-hint"><?php esc_html_e( 'No scripts were deferred or delayed on the last page you opened. Scripts that are excluded or needed by other scripts keep loading as before.', 'arvaseo-connector' ); ?></p>
<?php elseif ( is_array( $arvaseo_connector_deferred ) && array() !== $arvaseo_connector_deferred ) : ?>
	<p><?php esc_html_e( 'Scripts it deferred or delayed on the last page you opened while trying it:', 'arvaseo-connector' ); ?></p>
	<ul class="arvaseo-defer-list">
		<?php foreach ( $arvaseo_connector_deferred as $arvaseo_connector_handle => $arvaseo_connector_host ) : ?>
			<li>
				<code><?php echo esc_html( (string) $arvaseo_connector_handle ); ?></code> <span class="arvaseo-hint"><?php echo esc_html( (string) $arvaseo_connector_host ); ?></span>
				<?php if ( in_array( (string) $arvaseo_connector_handle, $arvaseo_connector_stored['defer_exclude'], true ) ) : ?>
					<span class="arvaseo-hint">· <?php esc_html_e( 'Left alone', 'arvaseo-connector' ); ?></span>
				<?php else : ?>
					<button type="submit" name="leave_alone" value="<?php echo esc_attr( (string) $arvaseo_connector_handle ); ?>" class="arvaseo-link-btn">
						<?php esc_html_e( 'Leave alone', 'arvaseo-connector' ); ?><span class="screen-reader-text"> <?php echo esc_html( (string) $arvaseo_connector_handle ); ?></span>
					</button>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>
<?php endif; ?>
