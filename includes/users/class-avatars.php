<?php
/**
 * Uploaded avatars.
 *
 * @package easyQ
 */

namespace EasyQ\Users;

use EasyQ\Settings\Options;
use WP_Comment;
use WP_Post;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Shows avatars that users uploaded instead of their Gravatar.
 *
 * Uploaded avatars are attachments in the Media Library; the attachment ID is
 * stored in user meta. Gravatar and the default avatar remain governed by the
 * WordPress discussion settings.
 */
final class Avatars {

	/**
	 * User meta key with the attachment ID of the uploaded avatar.
	 *
	 * @var string
	 */
	public const META = 'easyq_avatar_id';

	/**
	 * Hooks the avatar lookup into WordPress.
	 */
	public function register(): void {
		add_filter( 'pre_get_avatar_data', array( $this, 'avatar_data' ), 10, 2 );
	}

	/**
	 * Uses the uploaded avatar of a user, if there is one.
	 *
	 * @param array<string, mixed> $args        Avatar arguments.
	 * @param mixed                $id_or_email User ID, email, user, post or comment.
	 * @return array<string, mixed>
	 */
	public function avatar_data( array $args, mixed $id_or_email ): array {
		if ( ! Options::get_bool( 'users', 'avatar_allow_upload' ) ) {
			return $args;
		}

		$user_id = self::user_id( $id_or_email );
		if ( null === $user_id ) {
			return $args;
		}

		$stored        = get_user_meta( $user_id, self::META, true );
		$attachment_id = is_numeric( $stored ) ? (int) $stored : 0;
		if ( $attachment_id <= 0 ) {
			return $args;
		}

		$size = isset( $args['size'] ) && is_numeric( $args['size'] ) ? (int) $args['size'] : 96;
		$url  = wp_get_attachment_image_url( $attachment_id, array( $size, $size ) );

		if ( false !== $url ) {
			$args['url']          = $url;
			$args['found_avatar'] = true;
		}

		return $args;
	}

	/**
	 * Sets or removes the uploaded avatar of a user.
	 *
	 * @param int      $user_id       User ID.
	 * @param int|null $attachment_id Attachment ID of an image, or null to remove the avatar.
	 * @return bool Whether the avatar was changed.
	 */
	public static function set( int $user_id, ?int $attachment_id ): bool {
		if ( null === $attachment_id ) {
			return delete_user_meta( $user_id, self::META );
		}

		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return false;
		}

		return false !== update_user_meta( $user_id, self::META, $attachment_id );
	}

	/**
	 * Finds the user ID for the different ways get_avatar() identifies a user.
	 *
	 * @param mixed $id_or_email User ID, email, user, post or comment.
	 * @return int|null
	 */
	private static function user_id( mixed $id_or_email ): ?int {
		$user = match ( true ) {
			$id_or_email instanceof WP_User    => $id_or_email,
			$id_or_email instanceof WP_Post    => get_user_by( 'id', (int) $id_or_email->post_author ),
			$id_or_email instanceof WP_Comment => get_user_by( 'id', (int) $id_or_email->user_id ),
			is_numeric( $id_or_email )         => get_user_by( 'id', (int) $id_or_email ),
			is_string( $id_or_email )          => get_user_by( 'email', $id_or_email ),
			default                            => false,
		};

		return $user instanceof WP_User && $user->ID > 0 ? $user->ID : null;
	}
}
