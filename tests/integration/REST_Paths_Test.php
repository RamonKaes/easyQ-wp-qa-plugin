<?php
/**
 * Tests for paths of the REST API that change rights or data and no other test runs.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Actor;
use EasyQ\Posts\Post;
use EasyQ\Services;
use EasyQ\Settings\Options;
use EasyQ\Users\Permits;

/**
 * @covers \EasyQ\REST\IPs_Controller
 * @covers \EasyQ\REST\Posts_Controller
 * @covers \EasyQ\REST\Questions_Controller
 * @covers \EasyQ\REST\Answers_Controller
 * @covers \EasyQ\REST\Comments_Controller
 */
final class REST_Paths_Test extends REST_Test_Case {

	/**
	 * Sets permissions as stored, past the sanitizer that holds the choices of the screen.
	 *
	 * @param array<string, int> $permits Permissions.
	 */
	private function permits( array $permits ): void {
		remove_all_filters( 'sanitize_option_' . Options::option_name( 'permissions' ) );
		Options::update( 'permissions', $permits );
	}

	public function test_hiding_all_posts_of_an_address_needs_the_right_to_hide_at_the_highest_level(): void {
		$this->permits( array( 'permit_hide_show' => Permits::ADMINS ) );
		$this->ask( new Actor( $this->user()->user_id, null, '192.0.2.44' ), 'A question from the address' );
		wp_set_current_user( (int) $this->user( 'editor' )->user_id );

		// A moderator may block the address, but not hide every post of it.
		$this->assertSame( 200, $this->request( 'POST', '/ips/192.0.2.44/block' )->get_status() );
		$this->assertSame( 403, $this->request( 'POST', '/ips/192.0.2.44/hide-all' )->get_status() );
	}

	public function test_voting_on_comments_follows_its_own_permission(): void {
		$this->permits( array( 'permit_vote_c' => Permits::EXPERTS ) );
		Options::update( 'viewing', array( 'voting_on_cs' => true ) );
		$question = $this->ask( $this->user() );
		$answer   = $this->answer( $this->user(), $question );
		$comment  = $this->post( Services::post_service()->create_comment( $this->user(), $question, $answer, 'A comment to vote on.' ) );
		$this->login();

		$this->assertSame( 403, $this->request( 'POST', '/posts/' . $comment->id . '/vote', array( 'vote' => 1 ) )->get_status() );
		$this->assertSame( 200, $this->request( 'POST', '/posts/' . $answer->id . '/vote', array( 'vote' => 1 ) )->get_status() );
	}

	public function test_moderators_clear_the_flags_of_a_post(): void {
		$question = $this->ask( $this->user() );
		$answer   = $this->answer( $this->user(), $question );
		Services::votes()->flag( $this->user(), $answer, $question );
		Services::votes()->flag( $this->user(), $answer, $question );
		$this->assertSame( 2, $this->post( $answer->id )->flag_count );

		wp_set_current_user( $this->administrator() );
		$this->assertSame( 200, $this->request( 'POST', '/posts/' . $answer->id . '/actions/clear-flags' )->get_status() );

		Services::posts()->forget( $answer->id );
		$this->assertSame( 0, $this->post( $answer->id )->flag_count );
	}

	public function test_moderators_hide_approve_and_delete_comments(): void {
		$question = $this->ask( $this->user() );
		$answer   = $this->answer( $this->user(), $question );
		$visible  = $this->post( Services::post_service()->create_comment( $this->user(), $question, $answer, 'A comment to hide.' ) );
		$queued   = $this->post( Services::post_service()->create_comment( $this->user(), $question, $answer, 'A comment that waits.', '', null, null, true ) );
		wp_set_current_user( $this->administrator() );

		$this->assertSame( 200, $this->request( 'POST', '/posts/' . $visible->id . '/actions/hide' )->get_status() );
		$this->assertTrue( $this->post( $visible->id )->is_hidden() );
		$this->assertSame( 200, $this->request( 'POST', '/posts/' . $queued->id . '/actions/approve' )->get_status() );
		$this->assertTrue( $this->post( $queued->id )->is_visible() );
		$this->assertSame( 200, $this->request( 'POST', '/posts/' . $visible->id . '/actions/delete' )->get_status() );
		Services::posts()->forget( $visible->id );
		$this->assertNull( Services::posts()->find( $visible->id ) );
	}

	public function test_questions_follow_on_from_visible_answers_only(): void {
		$question = $this->ask( $this->user() );
		$answer   = $this->answer( $this->user(), $question );
		$this->login();
		$params = array(
			'title'   => 'A question that follows on from an answer',
			'content' => 'More about it.',
			'follow'  => $answer->id,
		);

		$created = $this->request( 'POST', '/questions', $params );
		$this->assertSame( 201, $created->get_status() );
		$this->assertSame( $answer->id, $this->post( (int) $this->data( $created )['id'] )->parent_id );

		Services::post_service()->set_answer_status( Actor::system(), $this->post( $answer->id ), $question, Post::STATUS_HIDDEN );
		$refused = $this->request( 'POST', '/questions', array( 'title' => 'Another question that follows on' ) + $params );
		$this->assertSame( 400, $refused->get_status() );
		$this->assertArrayHasKey( 'follow', $refused->as_error()->get_error_data()['params'] );
	}

	public function test_edited_answers_and_comments_are_validated(): void {
		$question = $this->ask( $this->user() );
		$author   = $this->user();
		$answer   = $this->answer( $author, $question );
		$comment  = $this->post( Services::post_service()->create_comment( $author, $question, $answer, 'A comment to edit later.' ) );
		wp_set_current_user( (int) $author->user_id );

		$this->assertSame( 400, $this->request( 'PUT', '/answers/' . $answer->id, array( 'content' => 'Short' ) )->get_status() );
		$this->assertSame( 400, $this->request( 'PUT', '/comments/' . $comment->id, array( 'content' => str_repeat( 'x', 20000 ) ) )->get_status() );
		$this->assertSame( 'A comment to edit later.', $this->post( $comment->id )->content );
	}

	public function test_errors_have_the_codes_of_core_where_core_has_the_case(): void {
		$code = fn ( string $method, string $route, array $params = array() ): string => (string) $this->request( $method, $route, $params )->as_error()?->get_error_code();

		// Missing items, as core's controllers of posts, users and terms answer.
		$this->assertSame( 'rest_post_invalid_id', $code( 'GET', '/questions/999999' ) );
		$this->assertSame( 'rest_user_invalid_id', $code( 'GET', '/users/999999' ) );
		$this->assertSame( 'rest_term_invalid', $code( 'GET', '/categories/999999' ) );

		// Visitors who have to log in, and users who may not, as core.
		wp_set_current_user( 0 );
		$this->assertSame( 'rest_not_logged_in', $code( 'POST', '/questions', array( 'title' => 'A question from a visitor' ) ) );
		wp_set_current_user( (int) $this->user()->user_id );
		$this->assertSame( 'rest_forbidden', $code( 'POST', '/categories', array( 'title' => 'A category' ) ) );
		$this->assertSame( 'rest_forbidden', \EasyQ\REST\REST_Errors::permission( 'level' )->get_error_code() );

		// What core does not have keeps the code of easyQ.
		wp_set_current_user( $this->administrator() );
		$this->assertSame( 'easyq_not_found', $code( 'DELETE', '/messages/999999' ) );
	}

	public function test_reading_a_post_is_checked_before_the_handler_as_core_checks(): void {
		$question = $this->ask( $this->user() );
		$answer   = $this->answer( $this->user(), $question );
		$comment  = $this->comment( $this->user(), $question, $answer );
		Services::post_service()->set_question_status( \EasyQ\Actor::system(), $this->post( $question->id ), \EasyQ\Posts\Post::STATUS_HIDDEN );
		wp_set_current_user( 0 );
		$routes = rest_get_server()->get_routes( 'easyq/v1' );

		// As `get_item_permissions_check()` of core: the permission callback refuses what the handler would.
		foreach ( array(
			'/easyq/v1/questions/(?P<id>\\d+)'          => array( 'id' => $question->id ),
			'/easyq/v1/questions/(?P<question>\\d+)/answers' => array( 'question' => $question->id ),
			'/easyq/v1/answers/(?P<id>\\d+)'            => array( 'id' => $answer->id ),
			'/easyq/v1/posts/(?P<parent>\\d+)/comments' => array( 'parent' => $question->id ),
			'/easyq/v1/comments/(?P<id>\\d+)'           => array( 'id' => $comment->id ),
			'/easyq/v1/posts/(?P<id>\\d+)'              => array( 'id' => $question->id ),
		) as $route => $params ) {
			$this->assertArrayHasKey( $route, $routes );
			$handler = $routes[ $route ][0];
			$this->assertNotSame( '__return_true', $handler['permission_callback'], $route );
			$request = new \WP_REST_Request( 'GET', $route );
			$request->set_url_params( $params );
			$checked = call_user_func( $handler['permission_callback'], $request );
			$this->assertInstanceOf( \WP_Error::class, $checked, $route );
			$this->assertSame( 404, $checked->get_error_data()['status'] ?? null, $route );
		}
	}
}
