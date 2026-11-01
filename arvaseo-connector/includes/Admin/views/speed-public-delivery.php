<?php
/**
 * Public homepage delivery proof, independent of a purge acknowledgement.
 *
 * @package ArvaSEO\Connector
 */

use ArvaSEO\Connector\Speed\PublicDelivery;
use ArvaSEO\Connector\Speed\OtherCaches;

defined( 'ABSPATH' ) || exit;
$arvaseo_delivery        = PublicDelivery::status();
$arvaseo_delivery_labels = array(
	'unchecked'   => __( 'Not checked yet', 'arvaseo-connector' ),
	'current'     => __( 'Current Arva SEO generation received', 'arvaseo-connector' ),
	'stale'       => __( 'An older homepage was received', 'arvaseo-connector' ),
	'pending'     => __( 'A cache clear is still waiting', 'arvaseo-connector' ),
	'unconfirmed' => __( 'The homepage could not be confirmed', 'arvaseo-connector' ),
	'unavailable' => __( 'The public homepage could not be reached', 'arvaseo-connector' ),
);
$arvaseo_delivery_failed = OtherCaches::state()['cleared']['failed'] ?? array();
?>
<section class="arvaseo-card" id="arvaseo-public-delivery" data-arvaseo-speed-section="caching" aria-labelledby="arvaseo-delivery-title">
	<p class="arvaseo-eyebrow"><?php esc_html_e( 'Before comparing PageSpeed', 'arvaseo-connector' ); ?></p>
	<h2 id="arvaseo-delivery-title"><?php esc_html_e( 'Check what visitors receive', 'arvaseo-connector' ); ?></h2>
	<p><?php esc_html_e( 'Clear the local caches first, then the CDN or host cache. A successful purge request does not prove that the next visitor receives the changed page.', 'arvaseo-connector' ); ?></p>
	<p role="status"><strong><?php echo esc_html( $arvaseo_delivery_labels[ $arvaseo_delivery['status'] ] ?? $arvaseo_delivery_labels['unconfirmed'] ); ?></strong></p>
	<?php if ( ! empty( $arvaseo_delivery['at'] ) ) : ?>
		<p class="arvaseo-hint"><?php echo esc_html( sprintf( /* translators: %s: observation time. */ __( 'Last checked: %s. This is a saved observation, not a live monitor.', 'arvaseo-connector' ), wp_date( 'M j, Y H:i', $arvaseo_delivery['at'] ) ) ); ?></p>
	<?php endif; ?>
	<?php if ( $arvaseo_delivery_failed ) : ?>
		<div class="notice notice-warning inline"><p><?php echo esc_html( sprintf( /* translators: %s: cache identifiers. */ __( 'A cache clear failed or was withheld after an earlier failure: %s. Retry the cache clear before comparing scores.', 'arvaseo-connector' ), implode( ', ', $arvaseo_delivery_failed ) ) ); ?></p></div>
	<?php endif; ?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="arvaseo_connector_public_delivery" />
		<?php wp_nonce_field( 'arvaseo_connector_public_delivery' ); ?>
		<button type="submit" class="arvaseo-btn arvaseo-btn--sm"><?php esc_html_e( 'Check public homepage', 'arvaseo-connector' ); ?></button>
	</form>
	<p class="arvaseo-hint"><?php esc_html_e( 'Uses no PageSpeed tests. Fetches your homepage without sign-in cookies or a cache-busting address. It checks this Arva SEO generation in one response, not every CDN location, page or device. After editing a theme, header script or another plugin, clear every cache before checking. Missing markers remain unconfirmed.', 'arvaseo-connector' ); ?></p>
</section>
