<?php
/**
 * Existing Media Library compression, shown directly on the Speed card.
 *
 * @package ArvaSEO\Connector
 */

defined( 'ABSPATH' ) || exit;

return static function ( array $arvaseo_cmp, array $arvaseo_cmp_lib, string $arvaseo_state ): void {
	?>
<div class="arvaseo-fix-sub">
							<h4><?php esc_html_e( 'Compress existing Media Library images', 'arvaseo-connector' ); ?></h4>
							<p class="arvaseo-hint"><?php esc_html_e( 'Each JPEG, PNG and supported WebP in your media library, with its sizes, is saved smaller in the background, a few images at a time. A file is replaced only when it gets at least 5% smaller, and its original can be kept for restoration according to your backup setting below.', 'arvaseo-connector' ); ?></p>
							<?php if ( $arvaseo_cmp_lib['images'] > 0 ) : ?>
								<p>
									<progress max="<?php echo esc_attr( (string) $arvaseo_cmp_lib['images'] ); ?>" value="<?php echo esc_attr( (string) min( $arvaseo_cmp_lib['done'], $arvaseo_cmp_lib['images'] ) ); ?>"></progress>
									<?php
									/* translators: 1: images looked at, 2: images in the library. */
									echo esc_html( sprintf( __( '%1$s of %2$s images done.', 'arvaseo-connector' ), number_format_i18n( min( $arvaseo_cmp_lib['done'], $arvaseo_cmp_lib['images'] ) ), number_format_i18n( $arvaseo_cmp_lib['images'] ) ) );
									echo $arvaseo_cmp['running'] ? ' ' . esc_html__( 'Compressing…', 'arvaseo-connector' ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
									echo $arvaseo_cmp['restoring'] ? ' ' . esc_html__( 'Putting the originals back…', 'arvaseo-connector' ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
									?>
								</p>
							<?php endif; ?>
							<div class="arvaseo-row">
								<?php
								foreach ( array_filter(
									array(
										'start'   => ! $arvaseo_cmp['running'] && ! $arvaseo_cmp['restoring'] && 'on' === $arvaseo_state,
										'stop'    => $arvaseo_cmp['running'],
										'restore' => ! $arvaseo_cmp['restoring'] && $arvaseo_cmp_lib['done'] > 0,
									)
								) as $arvaseo_do => $arvaseo_show ) :
									?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="arvaseo-inline-form"<?php echo 'restore' === $arvaseo_do ? ' data-arvaseo-confirm="confirmCompressRestore"' : ''; ?>>
										<input type="hidden" name="action" value="arvaseo_connector_compress" />
										<input type="hidden" name="do" value="<?php echo esc_attr( $arvaseo_do ); ?>" />
										<?php wp_nonce_field( 'arvaseo_connector_compress' ); ?>
										<button type="submit" class="arvaseo-btn arvaseo-btn--sm">
											<?php
											$arvaseo_labels = array(
												'start'   => $arvaseo_cmp['done'] > 0 && null === $arvaseo_cmp['finished_at'] ? __( 'Continue', 'arvaseo-connector' ) : ( null !== $arvaseo_cmp['finished_at'] ? __( 'Compress images added since', 'arvaseo-connector' ) : __( 'Compress existing images', 'arvaseo-connector' ) ),
												'stop'    => __( 'Stop', 'arvaseo-connector' ),
												'restore' => __( 'Restore the originals', 'arvaseo-connector' ),
											);
											echo esc_html( $arvaseo_labels[ $arvaseo_do ] );
											?>
										</button>
									</form>
								<?php endforeach; ?>
								<?php if ( 'on' !== $arvaseo_state ) : ?>
									<span class="arvaseo-hint"><?php esc_html_e( 'Turn on Compress images first.', 'arvaseo-connector' ); ?></span>
								<?php endif; ?>
							</div>
						</div>
	<?php
};
