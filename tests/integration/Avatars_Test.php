<?php
/**
 * Tests for uploaded avatars.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Settings\Options;
use EasyQ\Users\Avatars;

/**
 * @covers \EasyQ\Users\Avatars
 */
final class Avatars_Test extends Test_Case {

	public function test_a_file_that_did_not_arrive_is_reported_with_its_reason(): void {
		$user = (int) $this->user()->user_id;
		// Every error PHP knows for an upload, as PHP fills the entry then.
		$errors = array(
			UPLOAD_ERR_INI_SIZE   => 'easyq_avatar_size',
			UPLOAD_ERR_FORM_SIZE  => 'easyq_avatar_size',
			UPLOAD_ERR_PARTIAL    => 'easyq_avatar_upload',
			UPLOAD_ERR_NO_TMP_DIR => 'easyq_avatar_upload',
			UPLOAD_ERR_CANT_WRITE => 'easyq_avatar_upload',
			UPLOAD_ERR_EXTENSION  => 'easyq_avatar_upload',
		);
		$this->assertCount( 8, array_filter( array_keys( get_defined_constants( true )['Core'] ), static fn ( string $name ): bool => str_starts_with( $name, 'UPLOAD_ERR_' ) ) );

		foreach ( $errors as $error => $code ) {
			$entry = Avatars::file_entry(
				array(
					'name'     => 'portrait.png',
					'type'     => '',
					'tmp_name' => '',
					'size'     => 0,
					'error'    => $error,
				)
			);
			$this->assertNotNull( $entry, "Error $error counts as no file." );
			$result = Avatars::upload( $user, $entry );
			$this->assertInstanceOf( \WP_Error::class, $result, "Error $error" );
			$this->assertSame( $code, $result->get_error_code(), "Error $error" );
			if ( UPLOAD_ERR_PARTIAL === $error ) {
				// A broken connection says to try again (F-231).
				$this->assertStringContainsString( 'arrived only in part', $result->get_error_message() );
			}
		}

		$this->assertStringContainsString(
			size_format( wp_max_upload_size() ),
			Avatars::upload(
				$user,
				(array) Avatars::file_entry(
					array(
						'name'  => 'a.png',
						'error' => UPLOAD_ERR_INI_SIZE,
					)
				)
			)->get_error_message()
		);
		// No file chosen: nothing to report.
		$this->assertNull(
			Avatars::file_entry(
				array(
					'name'     => '',
					'type'     => '',
					'tmp_name' => '',
					'size'     => 0,
					'error'    => UPLOAD_ERR_NO_FILE,
				)
			)
		);
		$this->assertNull( Avatars::file_entry( null ) );
		// A file without its parts did not arrive.
		$this->assertSame(
			UPLOAD_ERR_PARTIAL,
			Avatars::file_entry(
				array(
					'name'  => 'a.png',
					'error' => UPLOAD_ERR_OK,
				)
			)['error'] ?? null
		);
	}

	public function test_the_avatar_goes_with_a_deleted_user_and_not_to_the_new_owner_of_the_posts(): void {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		$user  = (int) $this->user()->user_id;
		$heir  = (int) $this->user()->user_id;
		$image = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		wp_update_post(
			array(
				'ID'          => $image,
				'post_author' => $user,
			)
		);
		update_post_meta( $image, Avatars::ATTACHMENT_META, $user );
		$this->assertTrue( Avatars::set( $user, $image ) );
		$kept = self::factory()->attachment->create( array( 'post_author' => $user ) );

		wp_delete_user( $user, $heir );

		$this->assertNull( get_post( $image ), 'The avatar went to the new owner.' );
		$this->assertSame( $heir, (int) get_post( $kept )->post_author, 'Other images go to the new owner as core gives them.' );
		$this->assertNull( Avatars::attachment_id( $heir ) );
	}

	/**
	 * @group ms-required
	 */
	public function test_the_avatar_goes_when_the_network_deletes_the_user(): void {
		require_once ABSPATH . 'wp-admin/includes/ms.php';
		$user  = (int) $this->user()->user_id;
		$image = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		update_post_meta( $image, Avatars::ATTACHMENT_META, $user );
		$this->assertTrue( Avatars::set( $user, $image ) );

		wpmu_delete_user( $user );

		$this->assertNull( get_post( $image ) );
	}

	public function test_uses_the_uploaded_avatar(): void {
		// Uploads are off by default; the site owner allows them.
		\EasyQ\Settings\Options::update( 'users', array( 'avatar_allow_upload' => true ) );
		$user          = self::factory()->user->create();
		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );

		$this->assertTrue( Avatars::set( $user, $attachment_id ) );

		$url = (string) get_avatar_url( $user, array( 'size' => 64 ) );
		$this->assertStringContainsString( 'canola', $url );
		$this->assertStringContainsString( 'canola', (string) get_avatar_url( get_userdata( $user ) ) );
		$this->assertStringNotContainsString( 'gravatar', $url );
	}

	public function test_falls_back_to_gravatar(): void {
		$user          = self::factory()->user->create();
		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		Avatars::set( $user, $attachment_id );

		Options::update( 'users', array( 'avatar_allow_upload' => false ) );
		$this->assertStringContainsString( 'gravatar', (string) get_avatar_url( $user ) );

		Options::update( 'users', array( 'avatar_allow_upload' => true ) );
		Avatars::set( $user, null );
		$this->assertStringContainsString( 'gravatar', (string) get_avatar_url( $user ) );
	}

	public function test_only_images_can_be_avatars(): void {
		$user     = self::factory()->user->create();
		$document = self::factory()->attachment->create( array( 'post_mime_type' => 'application/pdf' ) );

		$this->assertFalse( Avatars::set( $user, $document ) );
	}

	public function test_an_uploaded_avatar_keeps_no_metadata_of_the_photo(): void {
		if ( ! \WP_Image_Editor_Imagick::test() || ! \WP_Image_Editor_Imagick::supports_mime_type( 'image/png' ) ) {
			// The ImageMagick of wp-env reads no image formats, so core takes GD, which keeps no profiles.
			$this->markTestSkipped( 'Needs ImageMagick with PNG, as the editor that removes the profiles.' );
		}
		Options::update( 'users', array( 'avatar_allow_upload' => true ) );
		// A photo with a camera profile, which the image editor of core keeps when it crops.
		$image = new \Imagick();
		$image->newImage( 64, 64, new \ImagickPixel( 'white' ) );
		$image->setImageFormat( 'png' );
		$image->setImageProfile( 'xmp', '<x:xmpmeta xmlns:x="adobe:ns:meta/"><x:location>home</x:location></x:xmpmeta>' );
		$tmp = wp_tempnam( 'avatar' );
		$image->writeImage( $tmp );
		$this->assertArrayHasKey( 'xmp', ( new \Imagick( $tmp ) )->getImageProfiles( '*', false ) );

		$id = Avatars::upload(
			(int) $this->user()->user_id,
			array(
				'name'     => 'portrait.png',
				'type'     => 'image/png',
				'tmp_name' => $tmp,
				'size'     => (int) filesize( $tmp ),
				'error'    => UPLOAD_ERR_OK,
			)
		);

		$this->assertIsInt( $id );
		$file = get_attached_file( $id );
		$this->assertIsString( $file );
		$this->assertSame( array(), array_keys( ( new \Imagick( $file ) )->getImageProfiles( '*', false ) ) );
		wp_delete_attachment( $id, true );
	}

	public function test_an_uploaded_avatar_is_found_by_the_email_address_as_core_passes_it(): void {
		Options::update( 'users', array( 'avatar_allow_upload' => true ) );
		$user = (int) $this->user()->user_id;
		$tmp  = wp_tempnam( 'avatar' );
		copy( DIR_TESTDATA . '/images/canola.jpg', $tmp );
		$id = Avatars::upload(
			$user,
			array(
				'name'     => 'canola.jpg',
				'type'     => 'image/jpeg',
				'tmp_name' => $tmp,
				'size'     => (int) filesize( $tmp ),
				'error'    => UPLOAD_ERR_OK,
			)
		);
		$this->assertIsInt( $id );

		// Comments of core pass the address of their author.
		$url = (string) get_avatar_url( get_userdata( $user )->user_email );
		$this->assertStringContainsString( wp_upload_dir()['baseurl'], $url );
		wp_delete_attachment( $id, true );
	}

	public function test_an_entry_php_never_sends_is_no_file_and_a_file_without_its_parts_did_not_arrive(): void {
		$entry = array(
			'name'     => 'portrait.png',
			'type'     => 'image/png',
			'tmp_name' => '/tmp/php123',
			'size'     => 10,
		);

		// No error, or one PHP does not know: no file.
		$this->assertNull( Avatars::file_entry( $entry ) );
		$this->assertNull( Avatars::file_entry( $entry + array( 'error' => -1 ) ) );
		$this->assertNull( Avatars::file_entry( $entry + array( 'error' => 9 ) ) );

		// No temporary file: the file did not arrive whole. No type: the client sent none, which
		// core reads from the file (F-158).
		$this->assertSame( UPLOAD_ERR_PARTIAL, Avatars::file_entry( array( 'tmp_name' => '' ) + $entry + array( 'error' => UPLOAD_ERR_OK ) )['error'] ?? null );
		$this->assertSame( UPLOAD_ERR_OK, Avatars::file_entry( array( 'type' => '' ) + $entry + array( 'error' => UPLOAD_ERR_OK ) )['error'] ?? null );
	}

	public function test_a_file_larger_than_the_site_takes_is_refused_for_its_size(): void {
		Options::update( 'users', array( 'avatar_allow_upload' => true ) );
		$tmp = wp_tempnam( 'avatar' );
		copy( DIR_TESTDATA . '/images/canola.jpg', $tmp );
		$limit = static fn (): int => 100;
		add_filter( 'upload_size_limit', $limit );

		$result = Avatars::upload(
			(int) $this->user()->user_id,
			array(
				'name'     => 'canola.jpg',
				'type'     => 'image/jpeg',
				'tmp_name' => $tmp,
				'size'     => (int) filesize( $tmp ),
				'error'    => UPLOAD_ERR_OK,
			)
		);
		remove_filter( 'upload_size_limit', $limit );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'easyq_avatar_size', $result->get_error_code() );
	}

	public function test_the_avatar_the_user_points_to_stays_when_two_uploads_meet(): void {
		Options::update( 'users', array( 'avatar_allow_upload' => true ) );
		$user   = (int) $this->user()->user_id;
		$upload = function () use ( $user ): int {
			$tmp = wp_tempnam( 'avatar' );
			copy( DIR_TESTDATA . '/images/canola.jpg', $tmp );
			$id = Avatars::upload(
				$user,
				array(
					'name'     => 'canola.jpg',
					'type'     => 'image/jpeg',
					'tmp_name' => $tmp,
					'size'     => (int) filesize( $tmp ),
					'error'    => UPLOAD_ERR_OK,
				)
			);
			$this->assertIsInt( $id );
			return $id;
		};
		$other  = $upload();
		// The other upload sets its avatar last, after this one set its own.
		$late = static function ( $meta_id, $object_id, $key ) use ( $user, $other, &$late ): void {
			if ( $user === $object_id && str_ends_with( (string) $key, Avatars::META ) ) {
				remove_action( 'updated_user_meta', $late );
				\EasyQ\Users\Site_Meta::update( $user, Avatars::META, $other );
			}
		};
		add_action( 'updated_user_meta', $late, 10, 3 );

		$mine = $upload();
		remove_action( 'updated_user_meta', $late );

		$this->assertSame( $other, Avatars::attachment_id( $user ) );
		$this->assertNotNull( get_post( $other ), 'The avatar the user points to stays.' );
		$this->assertNull( get_post( $mine ), 'The one nobody points to goes.' );
	}

	/**
	 * Writes a GIF of frames of a size, with image data no editor needs for these tests.
	 *
	 * @param int $frames Number of frames.
	 * @param int $side   Width and height.
	 * @return string Path of the file.
	 */
	private static function gif( int $frames, int $side ): string {
		$size = pack( 'vv', $side, $side );
		// A global colour table of two colours, which the count has to step over.
		$data = 'GIF89a' . $size . "\x80\x00\x00" . str_repeat( "\x00", 6 );
		for ( $i = 0; $i < $frames; $i++ ) {
			// A graphic control extension, then an image without a colour table.
			$data .= "\x21\xF9\x04\x00\x0A\x00\x00\x00\x2C\x00\x00\x00\x00" . $size . "\x00\x02\x02\x4C\x01\x00";
		}
		$path = wp_tempnam( 'avatar' );
		file_put_contents( $path, $data . "\x3B" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A file of the test.
		return $path;
	}

	/**
	 * Uploads a file as an avatar.
	 *
	 * @param string $path File.
	 * @param string $name Name.
	 * @return int|\WP_Error
	 */
	private function upload_file( string $path, string $name ): int|\WP_Error {
		Options::update( 'users', array( 'avatar_allow_upload' => true ) );
		return Avatars::upload(
			(int) $this->user()->user_id,
			array(
				'name'     => $name,
				'type'     => 'image/gif',
				'tmp_name' => $path,
				'size'     => (int) filesize( $path ),
				'error'    => UPLOAD_ERR_OK,
			)
		);
	}

	/**
	 * Runs a check with about 64 MB left for images, as on a small host. PHP accepts no limit
	 * below what it uses, and `wp_raise_memory_limit()` takes the filtered limit only above the
	 * current one.
	 *
	 * @param callable $run Check.
	 * @return mixed What the check returns.
	 */
	private static function with_little_memory( callable $run ): mixed {
		$limit = (string) ini_get( 'memory_limit' );
		$used  = (int) ceil( memory_get_usage() / MB_IN_BYTES );
		$small = static fn (): string => $used + 65 . 'M';
		ini_set( 'memory_limit', $used + 64 . 'M' ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- The limit the check reads.
		add_filter( 'image_memory_limit', $small );
		try {
			return $run();
		} finally {
			remove_filter( 'image_memory_limit', $small );
			ini_set( 'memory_limit', $limit ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- Restores the limit.
		}
	}

	public function test_every_frame_of_an_animation_counts_toward_the_pixels(): void {
		$frames = new \ReflectionMethod( Avatars::class, 'frames' );
		$this->assertSame( 300, $frames->invoke( null, self::gif( 300, 10 ) ) );
		$this->assertSame( 1, $frames->invoke( null, DIR_TESTDATA . '/images/canola.jpg' ) );
		$this->assertSame( 0, $frames->invoke( null, self::gif( 0, 10 ) ), 'A GIF without an image.' );
		$this->assertSame( 1, $frames->invoke( null, DIR_TESTDATA . '/images/webp-lossy.webp' ) );
		$this->assertSame( 1, $frames->invoke( null, DIR_TESTDATA . '/images/webp-lossless.webp' ) );
		$this->assertGreaterThan( 1, $frames->invoke( null, DIR_TESTDATA . '/images/webp-animated.webp' ) );
		$chunk = static fn ( string $name, string $body ): string => $name . pack( 'V', strlen( $body ) ) . $body . ( strlen( $body ) % 2 ? "\x00" : '' );
		$webp  = $chunk( 'VP8X', str_repeat( "\x00", 10 ) ) . $chunk( 'ANIM', str_repeat( "\x00", 6 ) ) . $chunk( 'ANMF', str_repeat( "\x00", 17 ) ) . $chunk( 'ANMF', str_repeat( "\x00", 17 ) );
		$path  = wp_tempnam( 'avatar' );
		file_put_contents( $path, 'RIFF' . pack( 'V', 4 + strlen( $webp ) ) . 'WEBP' . $webp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A file of the test.
		$this->assertSame( 2, $frames->invoke( null, $path ) );

		// One frame of 2000 x 2000 pixels fits into about 64 MB, three hundred do not, as
		// ImageMagick reads them all.
		$result = self::with_little_memory( fn () => $this->upload_file( self::gif( 300, 2000 ), 'animation.gif' ) );
		$single = self::with_little_memory( fn () => $this->upload_file( self::gif( 1, 2000 ), 'still.gif' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'easyq_avatar_size', $result->get_error_code() );
		$this->assertNotSame( 'easyq_avatar_size', $single instanceof \WP_Error ? $single->get_error_code() : '' );
	}

	public function test_a_file_that_only_starts_like_an_image_is_refused_for_its_type(): void {
		$path = wp_tempnam( 'avatar' );
		file_put_contents( $path, 'GIF89a<script>alert(1)</script>' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A file of the test.

		// Its header claims 29,500 x 29,283 pixels.
		$result = self::with_little_memory( fn () => $this->upload_file( $path, 'portrait.gif' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'easyq_avatar_type', $result->get_error_code(), 'Not "too many pixels".' );
	}

	public function test_a_whole_image_without_a_type_from_the_client_is_taken(): void {
		Options::update( 'users', array( 'avatar_allow_upload' => true ) );
		$tmp = wp_tempnam( 'avatar' );
		copy( DIR_TESTDATA . '/images/canola.jpg', $tmp );

		// A client may send a part without Content-Type; core reads the type from the file.
		$entry = Avatars::file_entry(
			array(
				'name'     => 'canola.jpg',
				'type'     => '',
				'tmp_name' => $tmp,
				'size'     => (int) filesize( $tmp ),
				'error'    => UPLOAD_ERR_OK,
			)
		);
		$this->assertNotNull( $entry );
		$this->assertIsInt( Avatars::upload( (int) $this->user()->user_id, $entry ) );
	}
}
