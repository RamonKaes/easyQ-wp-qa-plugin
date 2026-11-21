<?php
/**
 * Image editor for avatars with ImageMagick.
 *
 * @package easyQ
 */

namespace EasyQ\Users;

use Exception;
use WP_Error;
use WP_Image_Editor_Imagick;

defined( 'ABSPATH' ) || exit;

/**
 * The image editor of core, with one more step: removing every profile but the colour
 * profiles, the comment and the text chunks.
 *
 * Core keeps the EXIF, IPTC and XMP profiles in the sizes it makes, for the orientation and
 * the rights of the image (`WP_Image_Editor_Imagick::strip_meta()`). An avatar is the public
 * picture of a person, so it keeps none of them: the camera, the time and the place where a
 * photo was taken stay private. The orientation is applied to the pixels first
 * (`maybe_exif_rotate()`). GD writes no metadata, so the GD editor of core needs no such step.
 *
 * Only `Avatars::upload()` uses this editor, through `wp_image_editors`, after core has
 * loaded the class it extends.
 *
 * @since 0.1.0
 */
final class Avatar_Editor_Imagick extends WP_Image_Editor_Imagick {

	/**
	 * Profiles an avatar keeps: the colour profiles.
	 *
	 * @since 0.1.0
	 *
	 * @var string[]
	 */
	public const KEPT_PROFILES = array( 'icc', 'icm' );

	/**
	 * Removes every profile but the colour profiles, the comment and the text chunks of PNG.
	 *
	 * @since 0.1.0
	 *
	 * @return true|WP_Error
	 */
	public function strip_profiles(): bool|WP_Error {
		try {
			foreach ( array_keys( $this->image->getImageProfiles( '*', true ) ) as $profile ) {
				if ( ! in_array( $profile, self::KEPT_PROFILES, true ) ) {
					$this->image->removeImageProfile( $profile );
				}
			}
			// The comment is no profile: JPEG and GIF write it back, as a COM segment or an extension.
			$this->image->deleteImageProperty( 'comment' );
			// PNG writes properties as text chunks, and the times of the file; core leaves them out
			// only when it resizes (`thumbnail_image()`), not for an image already of its size.
			$this->image->setOption( 'png:exclude-chunk', 'tEXt,zTXt,iTXt,date,time' );
		} catch ( Exception $e ) {
			// Not covered: needs ImageMagick to throw while reading the profiles, which the test site cannot provoke.
			return new WP_Error( 'image_strip_meta_error', $e->getMessage() );
		}

		return true;
	}
}
