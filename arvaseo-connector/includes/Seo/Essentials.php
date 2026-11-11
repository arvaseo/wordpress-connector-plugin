<?php
/**
 * SEO titles and meta descriptions, for sites with no SEO plugin.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Seo;

defined( 'ABSPATH' ) || exit;

/**
 * Off by default, and offered only when no SEO plugin (Yoast SEO, Rank Math, All in One SEO,
 * SEOPress, The SEO Framework) is active. When on, posts, pages and products get an "SEO title"
 * and "Meta description" box, and the home page gets two fields on the SEO screen. The values live
 * in this plugin's own post meta and option, and are printed (a meta description tag, and the
 * document title) only for pages that have a value. Nothing here is sent to Arva SEO or changes
 * what Arva SEO is told about the site.
 */
final class Essentials {

	public const TITLE_META = '_arvaseo_seo_title';

	public const DESCRIPTION_META = '_arvaseo_seo_description';

	private const NONCE_ACTION = 'arvaseo_connector_seo_essentials_box';

	private const NONCE_FIELD = 'arvaseo_seo_essentials_nonce';

	/** The sizes the screens suggest (search results cut longer text off). */
	public const TITLE_ADVICE = 60;

	public const DESCRIPTION_ADVICE = 160;

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( 'wp_head', array( self::class, 'print_description' ), 1 );
		add_filter( 'pre_get_document_title', array( self::class, 'document_title' ), 20 );
		if ( is_admin() ) {
			add_action( 'add_meta_boxes', array( self::class, 'add_meta_box' ) );
			add_action( 'save_post', array( self::class, 'save' ), 10, 2 );
		}
	}

	/**
	 * Whether SEO titles and descriptions can be offered: no SEO plugin is doing the job.
	 */
	public static function available(): bool {
		return ! Detector::any();
	}

	/**
	 * Whether they are on: chosen by an administrator, and still no SEO plugin.
	 */
	public static function enabled(): bool {
		return (bool) Options::get( 'essentials' ) && self::available();
	}

	/**
	 * The post types that get the box: posts, pages and products (when there are any).
	 *
	 * @return string[]
	 */
	public static function post_types(): array {
		$types = array_values( array_filter( array( 'post', 'page', 'product' ), 'post_type_exists' ) );
		$types = apply_filters( 'arvaseo_connector_seo_essentials_post_types', $types );
		return is_array( $types ) ? array_values( array_filter( array_map( 'strval', $types ), 'post_type_exists' ) ) : array();
	}

	// ----- Editor box ---------------------------------------------------------------------

	/**
	 * Add the box (classic and block editors) when the feature is on.
	 */
	public static function add_meta_box(): void {
		if ( ! self::enabled() ) {
			return;
		}
		foreach ( self::post_types() as $type ) {
			add_meta_box( 'arvaseo-seo-essentials', __( 'SEO title and description (Arva SEO)', 'arvaseo-connector' ), array( self::class, 'render_box' ), $type, 'normal', 'default' );
		}
	}

	/**
	 * The box.
	 *
	 * @param \WP_Post $post Post being edited.
	 */
	public static function render_box( $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		$title       = (string) get_post_meta( $post->ID, self::TITLE_META, true );
		$description = (string) get_post_meta( $post->ID, self::DESCRIPTION_META, true );
		?>
		<p>
			<label for="arvaseo-seo-title"><strong><?php esc_html_e( 'SEO title', 'arvaseo-connector' ); ?></strong></label><br />
			<input type="text" id="arvaseo-seo-title" name="arvaseo_seo_title" value="<?php echo esc_attr( $title ); ?>" maxlength="<?php echo (int) Options::TITLE_MAX; ?>" class="widefat" />
			<span class="description">
				<?php
				/* translators: %d: suggested number of characters. */
				printf( esc_html__( 'The title people see in search results. About %d characters or fewer works best. Leave it empty to use the post’s title.', 'arvaseo-connector' ), (int) self::TITLE_ADVICE );
				?>
			</span>
		</p>
		<p>
			<label for="arvaseo-seo-description"><strong><?php esc_html_e( 'Meta description', 'arvaseo-connector' ); ?></strong></label><br />
			<textarea id="arvaseo-seo-description" name="arvaseo_seo_description" rows="3" maxlength="<?php echo (int) Options::DESCRIPTION_MAX; ?>" class="widefat"><?php echo esc_textarea( $description ); ?></textarea>
			<span class="description">
				<?php
				/* translators: %d: suggested number of characters. */
				printf( esc_html__( 'The short text under the title in search results. About %d characters or fewer works best. Leave it empty and search engines choose their own.', 'arvaseo-connector' ), (int) self::DESCRIPTION_ADVICE );
				?>
			</span>
		</p>
		<?php
	}

	/**
	 * Save the box. Only from the editor form, with its nonce, for someone who may edit the post.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post.
	 */
	public static function save( $post_id, $post ): void {
		$post_id = (int) $post_id;
		if ( ! self::enabled() || ! $post instanceof \WP_Post || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}
		if ( ! in_array( $post->post_type, self::post_types(), true ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$title       = Options::text( isset( $_POST['arvaseo_seo_title'] ) ? (string) wp_unslash( $_POST['arvaseo_seo_title'] ) : '', Options::TITLE_MAX ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Options::text() sanitizes.
		$description = Options::text( isset( $_POST['arvaseo_seo_description'] ) ? (string) wp_unslash( $_POST['arvaseo_seo_description'] ) : '', Options::DESCRIPTION_MAX ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Options::text() sanitizes.
		foreach ( array(
			self::TITLE_META       => $title,
			self::DESCRIPTION_META => $description,
		) as $key => $value ) {
			if ( '' === $value ) {
				delete_post_meta( $post_id, $key );
			} else {
				update_post_meta( $post_id, $key, wp_slash( $value ) );
			}
		}
	}

	// ----- On the page --------------------------------------------------------------------

	/**
	 * The SEO title for the page being shown, or '' when there is none (or the feature is off).
	 */
	public static function title_for_request(): string {
		return self::value_for_request( self::TITLE_META, 'home_title' );
	}

	/**
	 * The meta description for the page being shown, or ''.
	 */
	public static function description_for_request(): string {
		return self::value_for_request( self::DESCRIPTION_META, 'home_description' );
	}

	/**
	 * One value for the page being shown: the post's own, then (on the home page) the setting.
	 *
	 * @param string $meta   Post meta key.
	 * @param string $option Choice name for the home page.
	 */
	private static function value_for_request( string $meta, string $option ): string {
		if ( is_admin() || ! self::enabled() ) {
			return '';
		}
		$value = '';
		$types = self::post_types();
		if ( array() !== $types && is_singular( $types ) ) {
			$value = (string) get_post_meta( (int) get_queried_object_id(), $meta, true );
		}
		if ( '' === $value && is_front_page() ) {
			$value = (string) Options::get( $option );
		}
		return trim( $value );
	}

	/**
	 * Print the meta description tag for pages that have one.
	 */
	public static function print_description(): void {
		$description = self::description_for_request();
		if ( '' === $description ) {
			return;
		}
		echo '<meta name="description" content="' . esc_attr( $description ) . '" />' . "\n";
	}

	/**
	 * Use the SEO title as the document title for pages that have one.
	 *
	 * @param mixed $title What an earlier filter chose ('' for "work it out").
	 * @return mixed
	 */
	public static function document_title( $title ) {
		if ( ! empty( $title ) && is_string( $title ) ) {
			return $title;
		}
		$own = self::title_for_request();
		return '' !== $own ? $own : $title;
	}

	/**
	 * What was saved on one post.
	 *
	 * @param int $post_id Post ID.
	 * @return array{title: string, description: string}
	 */
	public static function for_post( int $post_id ): array {
		return array(
			'title'       => (string) get_post_meta( $post_id, self::TITLE_META, true ),
			'description' => (string) get_post_meta( $post_id, self::DESCRIPTION_META, true ),
		);
	}
}
