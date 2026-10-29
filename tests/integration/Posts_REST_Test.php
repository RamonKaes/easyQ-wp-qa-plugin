<?php
/**
 * Tests for voting and actions on posts.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Actor;
use EasyQ\Posts\Post;
use EasyQ\Services;
use EasyQ\Settings\Options;

/**
 * @covers \EasyQ\REST\Posts_Controller
 */
final class Posts_REST_Test extends REST_Test_Case {

	public function test_votes(): void {
		$question = $this->ask( $this->user() );
		$this->login();

		$response = $this->request( 'POST', '/posts/' . $question->id . '/vote', array( 'vote' => 1 ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $this->data( $response )['votes']['user'] );
		$this->assertSame( 1, $this->data( $response )['votes']['net'] );
		$this->assertSame( -1, $this->data( $this->request( 'POST', '/posts/' . $question->id . '/vote', array( 'vote' => -1 ) ) )['votes']['net'] );
		$this->assertSame( 400, $this->request( 'POST', '/posts/' . $question->id . '/vote', array( 'vote' => 2 ) )->get_status() );
	}

	public function test_refuses_votes_on_own_posts_and_from_visitors(): void {
		$author   = $this->user();
		$question = $this->ask( $author );

		$this->assertSame( 401, $this->request( 'POST', '/posts/' . $question->id . '/vote', array( 'vote' => 1 ) )->get_status() );

		wp_set_current_user( (int) $author->user_id );
		$this->assertSame( 403, $this->request( 'POST', '/posts/' . $question->id . '/vote', array( 'vote' => 1 ) )->get_status() );
	}

	public function test_selects_and_unselects_answers(): void {
		$asker    = $this->user();
		$question = $this->ask( $asker );
		$answer   = $this->answer( $this->user(), $question );

		$this->login();
		$this->assertSame( 403, $this->request( 'POST', '/posts/' . $question->id . '/actions/select', array( 'answer' => $answer->id ) )->get_status() );

		wp_set_current_user( (int) $asker->user_id );
		$data = $this->data( $this->request( 'POST', '/posts/' . $question->id . '/actions/select', array( 'answer' => $answer->id ) ) );
		$this->assertSame( $answer->id, $data['selected_answer'] );

		$this->assertSame( 400, $this->request( 'POST', '/posts/' . $question->id . '/actions/select', array( 'answer' => $question->id ) )->get_status() );

		$data = $this->data( $this->request( 'POST', '/posts/' . $question->id . '/actions/unselect' ) );
		$this->assertNull( $data['selected_answer'] );
	}

	public function test_flags_and_unflags(): void {
		Options::update( 'spam', array( 'confirm_user_emails' => false ) );
		$question = $this->ask( $this->user() );
		$this->login();

		$data = $this->data( $this->request( 'POST', '/posts/' . $question->id . '/actions/flag' ) );
		$this->assertTrue( $data['flags']['user'] );

		$data = $this->data( $this->request( 'POST', '/posts/' . $question->id . '/actions/unflag' ) );
		$this->assertFalse( $data['flags']['user'] );
	}

	public function test_moderators_hide_reshow_and_delete(): void {
		$question = $this->ask( $this->user() );
		$this->login( 'editor' );

		$this->assertSame( 'hidden', $this->data( $this->request( 'POST', '/posts/' . $question->id . '/actions/hide' ) )['status'] );
		$this->assertSame( 'normal', $this->data( $this->request( 'POST', '/posts/' . $question->id . '/actions/reshow' ) )['status'] );

		$this->request( 'POST', '/posts/' . $question->id . '/actions/hide' );
		// As core responds to deleting: `deleted` and the item as it was.
		$deleted = $this->data( $this->request( 'POST', '/posts/' . $question->id . '/actions/delete' ) );
		$this->assertSame( array( 'deleted', 'previous' ), array_keys( $deleted ) );
		$this->assertTrue( $deleted['deleted'] );
		$this->assertSame( $question->id, $deleted['previous']['id'] );
		$this->assertSame( 'question', $deleted['previous']['type'] );
		$this->assertSame( 'hidden', $deleted['previous']['status'] );
		$this->assertNull( Services::posts()->find( $question->id ) );
		$this->assertSame( 404, $this->request( 'POST', '/posts/' . $question->id . '/actions/delete' )->get_status() );
	}

	public function test_subscribers_cannot_moderate(): void {
		$question = $this->ask( $this->user() );
		$this->login();

		$this->assertSame( 403, $this->request( 'POST', '/posts/' . $question->id . '/actions/hide' )->get_status() );
		$this->assertSame( 400, $this->request( 'POST', '/posts/' . $question->id . '/actions/unknown' )->get_status() );
	}

	public function test_approves_and_rejects_queued_posts(): void {
		$queued = $this->ask( $this->user(), 'A queued question title', 'x', array(), null, true );
		$other  = $this->ask( $this->user(), 'Another queued question', 'x', array(), null, true );
		$this->login( 'editor' );

		$this->assertSame( 'normal', $this->data( $this->request( 'POST', '/posts/' . $queued->id . '/actions/approve' ) )['status'] );
		$this->assertSame( 'hidden', $this->data( $this->request( 'POST', '/posts/' . $other->id . '/actions/reject' ) )['status'] );
	}

	public function test_closes_and_reopens(): void {
		$original = $this->ask( $this->user(), 'The original question title' );
		$question = $this->ask( $this->user() );
		$this->login( 'editor' );

		$this->assertSame( 400, $this->request( 'POST', '/posts/' . $question->id . '/actions/close' )->get_status() );
		$this->assertSame( 400, $this->request( 'POST', '/posts/' . $question->id . '/actions/close', array( 'duplicate_of' => $question->id ) )->get_status() );

		$data = $this->data( $this->request( 'POST', '/posts/' . $question->id . '/actions/close', array( 'duplicate_of' => $original->id ) ) );
		$this->assertTrue( $data['closed'] );
		$this->assertSame( $original->id, $data['closed_by'] );

		$this->assertFalse( $this->data( $this->request( 'POST', '/posts/' . $question->id . '/actions/reopen' ) )['closed'] );

		$data = $this->data( $this->request( 'POST', '/posts/' . $question->id . '/actions/close', array( 'note' => 'Not a question.' ) ) );
		$this->assertTrue( $data['closed'] );
	}

	public function test_converts_answers_to_comments(): void {
		$question = $this->ask( $this->user() );
		$answer   = $this->answer( $this->user(), $question );
		$this->login( 'editor' );

		$data = $this->data( $this->request( 'POST', '/posts/' . $answer->id . '/actions/convert' ) );

		$this->assertSame( 'comment', $data['type'] );
		$this->assertSame( $question->id, $data['parent'] );
	}

	public function test_moves_questions(): void {
		$question = $this->ask( $this->user() );
		$category = Services::categories()->create( null, 'Target' );
		$this->assertIsInt( $category );
		$this->login( 'editor' );

		$this->assertSame( 400, $this->request( 'POST', '/posts/' . $question->id . '/actions/move', array( 'category' => 999 ) )->get_status() );
		$this->assertSame( $category, $this->data( $this->request( 'POST', '/posts/' . $question->id . '/actions/move', array( 'category' => $category ) ) )['category']['id'] );
	}

	public function test_claims_anonymous_posts_with_the_visitor_cookie(): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'easyq_cookies',
			array(
				'id'      => 4242,
				'created' => current_time( 'mysql', true ),
			)
		);
		$question = $this->post( Services::post_service()->create_question( new Actor( null, 4242 ), 'Anonymous question title', 'x', '', array() ) );
		$user_id  = $this->login();

		$this->assertSame( 403, $this->request( 'POST', '/posts/' . $question->id . '/actions/claim' )->get_status() );

		$_COOKIE['easyq_visitor'] = '4242';
		$data                     = $this->data( $this->request( 'POST', '/posts/' . $question->id . '/actions/claim' ) );

		$this->assertSame( $user_id, $data['author']['id'] );
	}

	public function test_hidden_posts_respond_with_not_found_to_others(): void {
		$question = $this->ask( $this->user() );
		Services::post_service()->set_question_status( Actor::system(), $question, Post::STATUS_HIDDEN );
		$this->login();

		$this->assertSame( 404, $this->request( 'POST', '/posts/' . $question->id . '/vote', array( 'vote' => 1 ) )->get_status() );
	}
}
