<?php
/**
 * Uploaded avatars.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Users;

use EasyQ\Services;
use EasyQ\Settings\Options;
use EasyQ\Util\Number;
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
	 * Memory an image editor needs per pixel of an image, with a margin: GD holds a true colour
	 * image in about 7.1 bytes per pixel (measured with PHP 8.4 and a PNG of 4000 x 4000 pixels).
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const BYTES_PER_PIXEL = 8;

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
	 * @param mixed $args        Avatar arguments.
	 * @param mixed $id_or_email User ID, email, user, post or comment.
	 * @return mixed mixed>
	 */
	public function avatar_data( mixed $args, mixed $id_or_email ): mixed {
		// Another filter may hand on any type; it is passed on unchanged, as core does.
		if ( ! is_array( $args ) ) {
			return $args;
		}

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

		$size = Number::int_in_range( $args['size'] ?? null, PHP_INT_MIN ) ?? 96;
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
	 * The image is turned upright as its EXIF data says, cropped to a square of the
	 * configured size, stripped of its metadata (see Avatar_Editor_Imagick), and added
	 * to the Media Library.
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

		$path = $uploaded['file'];
		// Measured once core read the type from the first bytes. A GIF or WebP file without an
		// image counts no pixels, whatever its header claims, and the editor refuses it.
		$scale = self::fits_into_memory( $path, self::frames( $path ) );
		if ( $scale < 1 ) {
			wp_delete_file( $path );
			return new WP_Error(
				'easyq_avatar_size',
				/* translators: %d: percentage, such as 50. */
				sprintf( __( 'The image has too many pixels to be processed. Please scale it down to %d%% and try again.', 'easyq' ), (int) floor( $scale * 100 ) )
			);
		}

		$side = max( 16, Options::get_int( 'users', 'avatar_store_size' ) );
		add_filter( 'wp_image_editors', array( self::class, 'image_editors' ) );
		$editor = wp_get_image_editor( $path );
		remove_filter( 'wp_image_editors', array( self::class, 'image_editors' ) );
		if ( ! is_wp_error( $editor ) ) {
			$editor->maybe_exif_rotate();
		}
		// Not covered on the test site: its ImageMagick reads no image formats, so core takes GD;
		// Avatars_Test checks the stripping where ImageMagick handles PNG.
		$ready = ! is_wp_error( $editor ) && ! is_wp_error( self::crop( $editor, $side ) )
			&& ! ( $editor instanceof Avatar_Editor_Imagick && is_wp_error( $editor->strip_profiles() ) );
		$saved = $ready ? $editor->save( $path ) : null;
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

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $type,
				// No name: a title would keep the old one after a change, and show where the attachment is listed.
				'post_title'     => __( 'Avatar', 'easyq' ),
				// Private, so core's media routes and attachment pages do not show the name and ID of a
				// user whose profile is hidden; the image itself is served as before.
				'post_status'    => 'private',
				'post_author'    => $user_id,
			),
			$path,
			0,
			true
		);
		if ( is_wp_error( $attachment_id ) ) {
			// Not covered: needs wp_insert_attachment() to fail, which core offers no filter to force for attachments; the file left would be the cropped copy without metadata.
			wp_delete_file( $path );
			return $attachment_id;
		}

		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $path ) );
		update_post_meta( $attachment_id, self::ATTACHMENT_META, $user_id );

		self::set( $user_id, $attachment_id );
		// What the user points to now stays, whatever another upload at the same time did: every
		// other avatar of the user goes, so that none is left behind without a user pointing to it.
		self::delete_attachments( $user_id, (int) self::attachment_id( $user_id ) );

		return $attachment_id;
	}

	/**
	 * Tells whether an image can be decoded within the memory limit for images, before an
	 * image editor tries it and PHP stops with a fatal error, as `qa_image_file_too_big()` checks
	 * it in Question2Answer.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path   Image file.
	 * @param int    $frames Frames of the image, see frames().
	 * @return float 1 if it fits or cannot be measured, else the factor to scale both sides by.
	 */
	private static function fits_into_memory( string $path, int $frames ): float {
		$size = wp_getimagesize( $path );
		if ( ! is_array( $size ) ) {
			return 1.0;
		}

		// The editors of core raise the limit for images first; the check counts with that limit.
		wp_raise_memory_limit( 'image' );
		$limit = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		if ( $limit <= 0 ) {
			return 1.0;
		}

		// ImageMagick reads every frame of an animation, where the size is that of the first.
		$available = ( $limit - memory_get_usage() ) * 0.9;
		$needed    = (int) filesize( $path ) + $size[0] * $size[1] * self::BYTES_PER_PIXEL * $frames;

		return $needed <= $available ? 1.0 : sqrt( max( 0, $available ) / $needed );
	}

	/**
	 * Counts the frames of a GIF or WebP image without decoding it.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path Image file.
	 * @return int The frames; 0 for a GIF or WebP file without an image, 1 for other formats.
	 */
	private static function frames( string $path ): int {
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
		$data   = (string) ( new \WP_Filesystem_Direct( null ) )->get_contents( $path );
		$length = strlen( $data );
		$frames = 0;

		if ( str_starts_with( $data, 'GIF' ) && $length > 13 ) {
			// The blocks of the format: extensions (0x21), images (0x2C), each with sub-blocks, and the trailer.
			$skip_table = static fn ( int $flags ): int => ( $flags & 0x80 ) ? 3 * ( 2 << ( $flags & 7 ) ) : 0;
			$position   = 13 + $skip_table( ord( $data[10] ) );
			while ( $position < $length && 0x3B !== ord( $data[ $position ] ) ) {
				$block = ord( $data[ $position ] );
				if ( 0x2C === $block && $position + 10 < $length ) {
					++$frames;
					$position += 11 + $skip_table( ord( $data[ $position + 9 ] ) );
				} elseif ( 0x21 === $block ) {
					$position += 2;
				} else {
					break;
				}
				while ( $position < $length ) {
					$size      = ord( $data[ $position ] );
					$position += 1 + $size;
					if ( 0 === $size ) {
						break;
					}
				}
			}
			return $frames;
		}

		if ( str_starts_with( $data, 'RIFF' ) && 'WEBP' === substr( $data, 8, 4 ) ) {
			// The chunks of the format, each with its size: an animation has one ANMF chunk per
			// frame, a still image one VP8 or VP8L chunk.
			$still = false;
			for ( $position = 12; $position + 8 <= $length; ) {
				$chunk     = substr( $data, $position, 4 );
				$frames   += 'ANMF' === $chunk ? 1 : 0;
				$still     = $still || in_array( $chunk, array( 'VP8 ', 'VP8L' ), true );
				$unpacked  = unpack( 'V', substr( $data, $position + 4, 4 ) );
				$size      = is_array( $unpacked ) && is_int( $unpacked[1] ?? null ) ? $unpacked[1] : $length;
				$position += 8 + $size + ( $size % 2 );
			}

			return $frames > 0 ? $frames : (int) $still;
		}

		return 1;
	}

	/**
	 * Puts the avatar editor before the ImageMagick editor of core, for the upload of an avatar.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $editors Class names of the image editors, in the order core tries them.
	 * @return mixed
	 */
	public static function image_editors( mixed $editors ): mixed {
		// Another filter may hand on any type; it is passed on unchanged, as core does.
		if ( ! is_array( $editors ) ) {
			return $editors;
		}

		$position = array_search( 'WP_Image_Editor_Imagick', $editors, true );
		if ( false !== $position ) {
			array_splice( $editors, (int) $position, 0, array( Avatar_Editor_Imagick::class ) );
		}

		return $editors;
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
	 * Tells whether a user must confirm the email address before uploading an avatar, as
	 * Question2Answer hides the upload until then (`qa-include/pages/account.php`: while email
	 * addresses are confirmed, for users below the level of experts).
	 *
	 * @since 0.1.0
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function needs_confirmation( int $user_id ): bool {
		return Options::get_bool( 'spam', 'confirm_user_emails' )
			&& ( Services::user_levels()->level( $user_id ) ?? 0 ) < Levels::EXPERT
			&& ! User_Status::is_confirmed( $user_id );
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
		self::set( $user_id, null );
		self::delete_attachments( $user_id, 0 );
	}

	/**
	 * Deletes the uploaded avatars of a user, found by their mark, except one.
	 *
	 * @since 0.1.0
	 *
	 * @param int $user_id User ID.
	 * @param int $keep    Attachment to keep, 0 for none.
	 */
	private static function delete_attachments( int $user_id, int $keep ): void {
		$attachments = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'meta_key'       => self::ATTACHMENT_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Only the avatars of one user.
				'meta_value'     => (string) $user_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Only the avatars of one user.
			)
		);
		foreach ( $attachments as $attachment_id ) {
			if ( (int) $attachment_id !== $keep ) {
				wp_delete_attachment( (int) $attachment_id, true );
			}
		}
	}

	/**
	 * Returns the address of the avatar of a user, or false while the site shows no avatars,
	 * as core leaves `avatar_urls` out of its REST answers then: the address of a Gravatar is
	 * a hash of the email address.
	 *
	 * @since 0.1.0
	 *
	 * @param int $user_id User ID, 0 for an author without an account.
	 * @param int $size    Size in pixels.
	 * @return string|false
	 */
	public static function url( int $user_id, int $size ): string|false {
		return get_option( 'show_avatars' ) ? get_avatar_url( $user_id, array( 'size' => $size ) ) : false;
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
