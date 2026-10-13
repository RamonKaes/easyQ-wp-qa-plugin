<?php
/**
 * Tests for the answer and comment endpoints.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Actor;
use EasyQ\Posts\Post;
use EasyQ\Services;
use EasyQ\Settings\Options;

/**
 * @covers \EasyQ\Rest\Answers_Controller
 * @covers \EasyQ\Rest\Comments_Controller
 */
final class AnswersCommentsRestTest extends Rest_TestCase {

	public function test_lists_answers_with_the_selected_answer_first(): void {
		$asker    = $this->user();
		$question = $this->ask( $asker );
		$first    = $this->answer( $this->user(), $question, 'The first answer here.' );
		$second   = $this->answer( $this->user(), $question, 'The second answer here.' );
		$hidden   = $this->answer( $this->user(), $question, 'The hidden answer here.' );
		Services::post_service()->select_answer( $asker, $question, $second->id );
		Services::post_service()->set_answer_status( Actor::system(), $hidden, $this->post( $question->id ), Post::STATUS_HIDDEN );

		$response = $this->request( 'GET', '/questions/' . $question->id . '/answers' );

		$this->assertSame( array( $second->id, $first->id ), array_column( $this->data( $response ), 'id' ) );
		$this->assertSame( '2', $response->get_headers()['X-WP-Total'] );
	}

	public function test_sorts_answers_by_votes_when_configured(): void {
		Options::update(
			'viewing',
			array(
				'sort_answers_by'     => 'votes',
				'show_selected_first' => false,
			)
		);
		$question = $this->ask( $this->user() );
		$low      = $this->answer( $this->user(), $question, 'Answer with few votes.' );
		$high     = $this->answer( $this->user(), $question, 'Answer with more votes.' );
		Services::votes()->vote( $this->user(), $high, 1 );

		$this->assertSame( array( $high->id, $low->id ), array_column( $this->data( $this->request( 'GET', '/questions/' . $question->id . '/answers' ) ), 'id' ) );
	}

	public function test_answers_a_question(): void {
		$question = $this->ask( $this->user() );
		$this->login();

		$response = $this->request( 'POST', '/questions/' . $question->id . '/answers', array( 'content' => 'This is how it works.' ) );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'answer', $this->data( $response )['type'] );
		$this->assertSame( 1, $this->post( $question->id )->answer_count );
		$this->assertSame( 400, $this->request( 'POST', '/questions/' . $question->id . '/answers', array( 'content' => 'Short' ) )->get_status() );
	}

	public function test_closed_questions_cannot_be_answered(): void {
		$question = $this->ask( $this->user() );
		Services::post_service()->close_with_note( Actor::system(), $question, 'Closed.' );
		$this->login();

		$this->assertSame( 403, $this->request( 'POST', '/questions/' . $question->id . '/answers', array( 'content' => 'This is how it works.' ) )->get_status() );
	}

	public function test_edits_answers(): void {
		$author = $this->user();
		$answer = $this->answer( $author, $this->ask( $this->user() ) );
		wp_set_current_user( (int) $author->user_id );

		$response = $this->request( 'PUT', '/answers/' . $answer->id, array( 'content' => 'An improved answer text.' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'An improved answer text.', $this->post( $answer->id )->content );
		$this->assertSame( 200, $this->request( 'GET', '/answers/' . $answer->id )->get_status() );
		$this->assertSame( 404, $this->request( 'GET', '/answers/' . $answer->parent_id )->get_status() );
	}

	public function test_comments_on_answers_but_not_on_questions_by_default(): void {
		$question = $this->ask( $this->user() );
		$answer   = $this->answer( $this->user(), $question );
		$this->login();

		$response = $this->request( 'POST', '/posts/' . $answer->id . '/comments', array( 'content' => 'A helpful comment.' ) );
		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 403, $this->request( 'POST', '/posts/' . $question->id . '/comments', array( 'content' => 'A helpful comment.' ) )->get_status() );

		$comments = $this->data( $this->request( 'GET', '/posts/' . $answer->id . '/comments' ) );
		$this->assertSame( array( $this->data( $response )['id'] ), array_column( $comments, 'id' ) );
	}

	public function test_edits_comments(): void {
		$author   = $this->user();
		$question = $this->ask( $this->user() );
		$comment  = $this->comment( $author, $question, $this->answer( $this->user(), $question ) );
		wp_set_current_user( (int) $author->user_id );

		$response = $this->request( 'PUT', '/comments/' . $comment->id, array( 'content' => 'An edited comment text.' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'An edited comment text.', $this->post( $comment->id )->content );
		$this->assertSame( 200, $this->request( 'GET', '/comments/' . $comment->id )->get_status() );
	}
}
