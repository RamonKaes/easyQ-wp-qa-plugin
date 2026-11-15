<?php
/**
 * Uploaded avatars.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Users;

use EasyQ\Settings\Options;
use WP_Comment;
use WP_Error;
use WP_Image_Editor;
use WP_Post;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Shows avatars that users uploaded instead of their Gravatar.
 *
 * Uploaded avatars are attachments in the Media Library; the attachment ID is
 * stored in user meta. Gravatar and the default avatar remain governed by the
 * WordPress discussion settings.
 *
 * @since 0.1.0
 */
final class Avatars {

	/**
	 * User meta key with the attachment ID of the uploaded avatar.
	 *
	 * Attachments belong to a site, so the key is site-specific (see Site_Meta).
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const META = 'easyq_avatar_id';

	/**
	 * Post meta key that marks attachments uploaded as avatar, with the user ID.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ATTACHMENT_META = '_easyq_avatar_user';

	/**
	 * Image types accepted as avatar.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string>
	 */
	public const MIME_TYPES = array(
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'gif'          => 'image/gif',
		'webp'         => 'image/webp',
	);

	/**
	 * Hooks the avatar lookup into WordPress.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_filter( 'pre_get_avatar_data', array( $this, 'avatar_data' ), 10, 2 );
		// When a user is deleted, core gives their posts, the image among them, to the user named
		// to take them over; the avatar is personal and goes with its user. On a network core
		// switches to the site before it removes the user from it.
		add_action( 'delete_user', array( $this, 'remove_with_user' ) );
		add_action( 'remove_user_from_blog', array( $this, 'remove_with_user' ) );
	}

	/**
	 * Removes the avatar of a user who is deleted or removed from the site.
	 *
	 * @since 0.1.0
	 *
	 * @param int $user_id User ID.
	 */
	public function remove_with_user( int $user_id ): void {
		self::remove( $user_id );
	}

	/**
	 * Uses the uploaded avatar of a user, if there is one.
	 *
	 * @since 0.1.0
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

		$stored        = Site_Meta::get( $user_id, self::META );
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
	 * @since 0.1.0
	 *
	 * @param int      $user_id       User ID.
	 * @param int|null $attachment_id Attachment ID of an image, or null to remove the avatar.
	 * @return bool False if the attachment is not an image.
	 */
	public static function set( int $user_id, ?int $attachment_id ): bool {
		if ( null === $attachment_id ) {
			Site_Meta::delete( $user_id, self::META );
			return true;
		}

		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return false;
		}

		Site_Meta::update( $user_id, self::META, $attachment_id );

		return true;
	}

	/**
	 * Stores an uploaded image as the avatar of a user, replacing the previous one.
	 *
	 * The image is cropped to a square of the configured size, which also drops
	 * the metadata of the original file, and added to the Media Library.
	 *
	 * @since 0.1.0
	 *
	 * @param int   $user_id User ID.
	 * @param array $file    Entry of the uploaded file, see file_entry().
	 * @phpstan-param array{name: string, type: string, tmp_name: string, size: int<0, max>, error: int<0, 8>} $file
	 * @return int|WP_Error Attachment ID, or the reason the image was refused.
	 */
	public static function upload( int $user_id, array $file ): int|WP_Error {
		$too_large = in_array( $file['error'], array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) || $file['size'] > wp_max_upload_size();
		if ( $too_large ) {
			/* translators: %s: largest size of an upload, such as "2 MB". */
			return new WP_Error( 'easyq_avatar_size', sprintf( __( 'The image is too large. Please choose one of at most %s.', 'easyq' ), (string) size_format( wp_max_upload_size() ) ) );
		}
		if ( UPLOAD_ERR_PARTIAL === $file['error'] ) {
			return new WP_Error( 'easyq_avatar_upload', __( 'The image arrived only in part. Please try again.', 'easyq' ) );
		}
		if ( UPLOAD_ERR_OK !== $file['error'] || $file['size'] <= 0 ) {
			return new WP_Error( 'easyq_avatar_upload', __( 'The image could not be uploaded.', 'easyq' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$overrides = array(
			'test_form' => false,
			'mimes'     => self::MIME_TYPES,
		);
		// Bypasses is_uploaded_file() when running unit tests, as the core attachments endpoint does.
		if ( defined( 'DIR_TESTDATA' ) && DIR_TESTDATA ) {
			$overrides['action'] = 'wp_handle_mock_upload';
		}

		// The file comes from `$_FILES`, so core checks that it was uploaded with this request.
		$uploaded = wp_handle_upload( $file, $overrides );
		if ( isset( $uploaded['error'] ) || ! isset( $uploaded['file'], $uploaded['type'] ) ) {
			return new WP_Error( 'easyq_avatar_type', __( 'Please upload a JPEG, PNG, GIF or WebP image.', 'easyq' ) );
		}

		$path   = $uploaded['file'];
		$side   = max( 16, Options::get_int( 'users', 'avatar_store_size' ) );
		$editor = wp_get_image_editor( $path );
		$saved  = is_wp_error( $editor ) || is_wp_error( self::crop( $editor, $side ) ) ? null : $editor->save( $path );
		if ( ! is_array( $saved ) || ! is_string( $saved['path'] ?? null ) || ! is_string( $saved['mime-type'] ?? null ) ) {
			wp_delete_file( $path );
			return new WP_Error( 'easyq_avatar_type', __( 'Please upload a JPEG, PNG, GIF or WebP image.', 'easyq' ) );
		}
		// The editor may write another file, for example with `image_editor_output_format`; the
		// attachment is the cropped one, and the original, with its metadata, goes.
		if ( $saved['path'] !== $path ) {
			wp_delete_file( $path );
		}
		$path = $saved['path'];
		$type = $saved['mime-type'];

		$user          = get_userdata( $user_id );
		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $type,
				/* translators: %s: user name. */
				'post_title'     => sprintf( __( 'Avatar of %s', 'easyq' ), false === $user ? $user_id : $user->display_name ),
				'post_status'    => 'inherit',
				'post_author'    => $user_id,
			),
			$path,
			0,
			true
		);
		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $path );
			return $attachment_id;
		}

		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $path ) );
		update_post_meta( $attachment_id, self::ATTACHMENT_META, $user_id );

		self::remove( $user_id );
		self::set( $user_id, $attachment_id );

		return $attachment_id;
	}

	/**
	 * Crops an image to a square, at most as large as the given side.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Image_Editor $editor Image.
	 * @param int             $side   Largest side length.
	 * @return true|WP_Error
	 */
	private static function crop( WP_Image_Editor $editor, int $side ): bool|WP_Error {
		$size   = $editor->get_size();
		$target = min( $side, $size['width'], $size['height'] );

		if ( $size['width'] === $target && $size['height'] === $target ) {
			return true;
		}

		return $editor->resize( $target, $target, true );
	}

	/**
	 * Checks the entry of an uploaded file, as in `$_FILES`.
	 *
	 * Only a form without a file gives null. A file that did not arrive keeps its error, so that
	 * `upload()` says why, as Question2Answer reports the size limit: for the errors of PHP the
	 * name stays but the type and the temporary file are empty.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $file Entry of an uploaded file.
	 * @return array|null The entry, or null if no file was sent.
	 * @phpstan-return array{name: string, type: string, tmp_name: string, size: int<0, max>, error: int<0, 8>}|null
	 */
	public static function file_entry( mixed $file ): ?array {
		if ( ! is_array( $file ) ) {
			return null;
		}

		$name     = is_string( $file['name'] ?? null ) ? sanitize_file_name( $file['name'] ) : '';
		$type     = is_string( $file['type'] ?? null ) ? sanitize_mime_type( $file['type'] ) : '';
		$tmp_name = is_string( $file['tmp_name'] ?? null ) ? $file['tmp_name'] : '';
		$size     = is_numeric( $file['size'] ?? null ) ? (int) $file['size'] : 0;
		$error    = is_numeric( $file['error'] ?? null ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;

		if ( UPLOAD_ERR_NO_FILE === $error || $error < 0 || $error > 8 ) {
			return null;
		}

		// A file without its parts did not arrive, whatever the error says.
		if ( UPLOAD_ERR_OK === $error && ( '' === $name || '' === $type || '' === $tmp_name ) ) {
			$error = UPLOAD_ERR_PARTIAL;
		}

		return array(
			'name'     => $name,
			'type'     => $type,
			'tmp_name' => $tmp_name,
			'size'     => max( 0, $size ),
			'error'    => $error,
		);
	}

	/**
	 * Removes the avatar of a user and deletes the image if it was uploaded as an avatar.
	 *
	 * @since 0.1.0
	 *
	 * @param int $user_id User ID.
	 */
	public static function remove( int $user_id ): void {
		$stored        = Site_Meta::get( $user_id, self::META );
		$attachment_id = is_numeric( $stored ) ? (int) $stored : 0;

		self::set( $user_id, null );

		$owner = $attachment_id > 0 ? get_post_meta( $attachment_id, self::ATTACHMENT_META, true ) : '';
		if ( is_numeric( $owner ) && (int) $owner === $user_id ) {
			wp_delete_attachment( $attachment_id, true );
		}
	}

	/**
	 * Returns the attachment ID of the uploaded avatar of a user.
	 *
	 * @since 0.1.0
	 *
	 * @param int $user_id User ID.
	 * @return int|null
	 */
	public static function attachment_id( int $user_id ): ?int {
		$stored = Site_Meta::get( $user_id, self::META );

		return is_numeric( $stored ) && (int) $stored > 0 ? (int) $stored : null;
	}

	/**
	 * Finds the user ID for the different ways get_avatar() identifies a user.
	 *
	 * @since 0.1.0
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
