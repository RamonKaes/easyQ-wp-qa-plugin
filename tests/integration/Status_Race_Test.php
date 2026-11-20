<?php
/**
 * Tests for two status changes of the same post at once.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Actor;
use EasyQ\Posts\Post;
use EasyQ\Services;

/**
 * Two moderators, or a double submission, read the post before either changes it: only
 * the first change goes through, so its events and emails happen once.
 *
 * @covers \EasyQ\Posts\Post_Service
 * @covers \EasyQ\Posts\Post_Repository
 * @covers \EasyQ\REST\Posts_Controller
 */
final class Status_Race_Test extends REST_Test_Case {

	public function test_a_queued_question_approved_twice_is_posted_once(): void {
		$question = $this->ask( $this->user(), queued: true );
		$service  = Services::post_service();

		// Both read the queued question, then approve it.
		$this->assertTrue( $service->set_question_status( Actor::system(), $question, Post::STATUS_NORMAL ) );
		$this->assertFalse( $service->set_question_status( Actor::system(), $question, Post::STATUS_NORMAL ) );

		$this->assertSame( 1, count( array_keys( $this->event_names(), 'q_post', true ) ) );
		$this->assertSame( 'Q', $this->post( $question->id )->type );
	}

	public function test_a_question_one_moderator_approves_and_another_rejects_stays_approved(): void {
		$question = $this->ask( $this->user(), queued: true );
		$service  = Services::post_service();

		$this->assertTrue( $service->set_question_status( Actor::system(), $question, Post::STATUS_NORMAL ) );
		$this->assertFalse( $service->set_question_status( Actor::system(), $question, Post::STATUS_HIDDEN ) );

		$this->assertSame( 'Q', $this->post( $question->id )->type );
		$this->assertNotContains( 'q_reject', $this->event_names() );
	}

	public function test_answers_and_comments_change_once(): void {
		$question = $this->ask( $this->user() );
		$answer   = $this->answer( $this->user(), $question );
		$comment  = $this->post( Services::post_service()->create_comment( $this->user(), $question, $answer, 'A comment that waits.', '', null, null, true ) );
		$service  = Services::post_service();

		$this->assertTrue( $service->set_answer_status( Actor::system(), $answer, $question, Post::STATUS_HIDDEN ) );
		$this->assertFalse( $service->set_answer_status( Actor::system(), $answer, $question, Post::STATUS_HIDDEN ) );
		$this->assertTrue( $service->set_comment_status( Actor::system(), $comment, $question, $answer, Post::STATUS_NORMAL ) );
		$this->assertFalse( $service->set_comment_status( Actor::system(), $comment, $question, $answer, Post::STATUS_NORMAL ) );

		$this->assertSame( 1, count( array_keys( $this->event_names(), 'a_hide', true ) ) );
		$this->assertSame( 1, count( array_keys( $this->event_names(), 'c_post', true ) ) );
	}

	public function test_the_rest_api_answers_409_to_the_second_moderator(): void {
		$question = $this->ask( $this->user(), queued: true );
		$this->login( 'administrator' );

		// The second request has read the question as queued before the first changed it.
		$stale = $question;
		$this->assertTrue( Services::post_service()->set_question_status( Actor::system(), $question, Post::STATUS_NORMAL ) );
		$response = ( new \ReflectionMethod( \EasyQ\REST\Posts_Controller::class, 'set_status' ) )->invoke( new \EasyQ\REST\Posts_Controller(), Actor::system(), $stale, $stale, $stale, Post::STATUS_NORMAL );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'easyq_changed', $response->get_error_code() );
		$this->assertSame( 409, $response->get_error_data()['status'] );
	}
}
