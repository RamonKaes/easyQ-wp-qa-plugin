<?php
/**
 * Tests that no avatar address goes out while the site shows no avatars.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

/**
 * Core leaves `avatar_urls` out of its REST answers while `show_avatars` is off; the address
 * of a Gravatar is a hash of the email address.
 *
 * @covers \EasyQ\Users\Avatars
 * @covers \EasyQ\REST\Post_Formatter
 * @covers \EasyQ\REST\Users_Controller
 * @covers \EasyQ\Blocks\Blocks
 */
final class Avatar_Option_Test extends REST_Test_Case {

	public function test_no_avatar_address_while_avatars_are_off(): void {
		$author   = $this->take_part( (int) $this->user()->user_id );
		$question = $this->ask( new \EasyQ\Actor( $author ) );
		$binding  = static function () use ( $author ): string {
			\EasyQ\Blocks\Block_Context::router()->set_current( new \EasyQ\Frontend\Route( 'user', get_userdata( $author ) ) );
			$value = get_block_bindings_source( 'easyq/user' )?->get_value( array( 'key' => 'avatar' ), new \WP_Block( array( 'blockName' => 'core/image' ) ), 'url' );
			\EasyQ\Blocks\Block_Context::router()->set_current( null );

			return (string) $value;
		};

		update_option( 'show_avatars', '1' );
		$this->assertStringContainsString( 'gravatar', (string) $this->data( $this->request( 'GET', '/questions/' . $question->id ) )['author']['avatar'] );
		$this->assertStringContainsString( 'gravatar', $binding() );

		// Off: false in the REST answers (F-227), as their schema allows, and nothing in the binding.
		update_option( 'show_avatars', '0' );
		$this->assertFalse( $this->data( $this->request( 'GET', '/questions/' . $question->id ) )['author']['avatar'] );
		$this->assertFalse( $this->data( $this->request( 'GET', '/users/' . $author ) )['avatar'] );
		$this->assertStringNotContainsString( 'gravatar', $binding() );
	}
}
