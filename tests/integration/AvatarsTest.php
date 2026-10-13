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
final class AvatarsTest extends TestCase {

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
