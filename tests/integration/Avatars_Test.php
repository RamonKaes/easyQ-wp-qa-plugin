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
}
