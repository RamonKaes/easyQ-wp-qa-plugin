<?php
/**
 * Tests for stopping emails through the REST API.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Mail\Notifications;
use EasyQ\Mail\Unsubscribe;
use EasyQ\Services;

/**
 * @covers \EasyQ\REST\Unsubscribe_Controller
 * @covers \EasyQ\Mail\Unsubscribe
 */
final class Unsubscribe_REST_Test extends REST_Test_Case {

	public function test_the_token_stops_the_emails_without_logging_in(): void {
		$author   = $this->user();
		$question = $this->post( Services::post_service()->create_question( $author, 'A question with emails', 'Text.', '', array(), null, '@' ) );
		$this->assertSame( 0, get_current_user_id() );

		$wrong = $this->request(
			'POST',
			'/unsubscribe',
			array(
				'post'  => $question->id,
				'token' => 'wrong',
			)
		);
		$this->assertSame( 403, $wrong->get_status() );
		$this->assertSame( '@', $this->post( $question->id )->notify );

		$other = $this->request(
			'POST',
			'/unsubscribe',
			array(
				'post'  => $question->id,
				'token' => Unsubscribe::token( $question->id + 1 ),
			)
		);
		$this->assertSame( 403, $other->get_status(), 'A token belongs to one post.' );

		$params = array(
			'post'  => $question->id,
			'token' => Unsubscribe::token( $question->id ),
		);
		$this->assertSame(
			array(
				'post'   => $question->id,
				'emails' => false,
			),
			$this->data( $this->request( 'POST', '/unsubscribe', $params ) )
		);
		$this->assertNull( $this->post( $question->id )->notify );

		// Mail programs may post again.
		$this->assertSame( 200, $this->request( 'POST', '/unsubscribe', $params )->get_status() );
	}

	public function test_a_token_for_a_missing_post_is_refused(): void {
		$this->assertSame(
			403,
			$this->request(
				'POST',
				'/unsubscribe',
				array(
					'post'  => 999999,
					'token' => Unsubscribe::token( 999999 ),
				)
			)->get_status()
		);
	}

	public function test_users_turn_all_emails_about_their_posts_on_and_off(): void {
		$user = $this->login();
		$this->assertTrue( $this->data( $this->request( 'GET', '/users/me' ) )['notifications'] );

		$this->assertFalse( $this->data( $this->request( 'POST', '/users/me', array( 'notifications' => false ) ) )['notifications'] );
		$this->assertFalse( Notifications::enabled( $user ) );

		// Saving the profile without the field changes nothing.
		$this->assertFalse( $this->data( $this->request( 'POST', '/users/me', array( 'remove_avatar' => false ) ) )['notifications'] );

		$this->assertTrue( $this->data( $this->request( 'POST', '/users/me', array( 'notifications' => true ) ) )['notifications'] );
		$this->assertTrue( Notifications::enabled( $user ) );

		// Other users do not see it.
		$other = self::factory()->user->create();
		$this->assertArrayNotHasKey( 'notifications', $this->data( $this->request( 'GET', '/users/' . $other ) ) );
	}
}
