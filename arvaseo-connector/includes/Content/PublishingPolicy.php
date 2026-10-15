<?php
/**
 * The local publishing ceiling.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Content;

use ArvaSEO\Connector\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress has the final say. A requested status outside the local mode is refused with
 * arvaseo_status_not_allowed; only if an administrator turned on "Downgrade disallowed requests to
 * Draft" does it become a draft, reported as a downgrade. Nothing is ever published, scheduled or
 * sent for review that the mode doesn't allow.
 */
final class PublishingPolicy {

	public const ALLOWS = array(
		'draft_only' => array( 'draft' ),
		'editorial'  => array( 'draft', 'pending' ),
		'scheduling' => array( 'draft', 'pending', 'future' ),
		'full'       => array( 'draft', 'pending', 'future', 'publish' ),
	);

	/**
	 * Decide what to apply.
	 *
	 * @param string      $requested Requested status.
	 * @param string|null $mode      Mode (current setting when null).
	 * @param bool|null   $downgrade Downgrade setting (current when null).
	 * @return array{allowed: bool, applied: ?string, downgraded: bool, reason: ?string}
	 */
	public static function decide( string $requested, ?string $mode = null, ?bool $downgrade = null ): array {
		$mode      = $mode ?? (string) Settings::get( 'publishing_mode' );
		$downgrade = $downgrade ?? (bool) Settings::get( 'downgrade_to_draft' );
		$allowed   = self::ALLOWS[ $mode ] ?? array( 'draft' );
		if ( in_array( $requested, $allowed, true ) ) {
			return array(
				'allowed'    => true,
				'applied'    => $requested,
				'downgraded' => false,
				'reason'     => null,
			);
		}
		if ( $downgrade ) {
			return array(
				'allowed'    => true,
				'applied'    => 'draft',
				'downgraded' => true,
				'reason'     => 'local_policy_downgrade',
			);
		}
		return array(
			'allowed'    => false,
			'applied'    => null,
			'downgraded' => false,
			'reason'     => 'arvaseo_status_not_allowed',
		);
	}

	/**
	 * Whether the mapped author may do this with the post type.
	 *
	 * @param int    $author_id Author.
	 * @param string $post_type Post type.
	 * @param string $status    Status to apply.
	 */
	public static function author_can( int $author_id, string $post_type, string $status ): bool {
		$type = get_post_type_object( $post_type );
		if ( ! $type || $author_id <= 0 || ! get_userdata( $author_id ) ) {
			return false;
		}
		$cap = in_array( $status, array( 'publish', 'future' ), true ) ? $type->cap->publish_posts : $type->cap->edit_posts;
		return user_can( $author_id, $cap );
	}
}
