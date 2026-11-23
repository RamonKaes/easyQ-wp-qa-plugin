<?php
/**
 * Tests for two writers on the same post.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Actor;
use EasyQ\Database\Schema;
use EasyQ\Posts\Post;
use EasyQ\Privacy\Post_Data;
use EasyQ\Services;
use EasyQ\Settings\Options;

/**
 * Two moderators, or a moderator and a privacy erasure, may act on the same post at once. A
 * change is made only if the post still has the state it was read with, and a post another
 * request deleted in between ends in an error result, never in an exception (F-252).
 *
 * The second request is played by the filter `query` of core: it deletes the post right
 * before this request reads it again after its change, which is the window of the race.
 *
 * @covers \EasyQ\Posts\Post_Service
 * @covers \EasyQ\Posts\Post_Repository
 * @covers \EasyQ\REST\Posts_Controller
 * @covers \EasyQ\REST\Questions_Controller
 * @covers \EasyQ\REST\Answers_Controller
 * @covers \EasyQ\REST\Comments_Controller
 * @covers \EasyQ\Privacy\Post_Data
 */
final class Concurrency_Test extends REST_Test_Case {

	/**
	 * Runs a step while another request deletes a post right before this one reads it again
	 * after its first write to the posts.
	 *
	 * @param int|null $id   Post to delete, or null for the post the first insert creates.
	 * @param callable $step Step.
	 * @return mixed What the step returns.
	 */
	private static function deleted_in_between( ?int $id, callable $step ): mixed {
		global $wpdb;
		$table  = Schema::table( 'posts' );
		$state  = array(
			'written' => false,
			'done'    => false,
		);
		$delete = static function ( string $query ) use ( $wpdb, $table, $id, &$state ): string {
			if ( $state['done'] ) {
				return $query;
			}
			if ( preg_match( '/^\s*(UPDATE|INSERT INTO) `' . preg_quote( $table, '/' ) . '`/', $query ) ) {
				$state['written'] = true;
			}
			$target = $id ?? (int) $wpdb->insert_id;
			if ( $state['written'] && $target > 0 && preg_match( '/^SELECT \* FROM `' . preg_quote( $table, '/' ) . '` WHERE id = ' . $target . '$/', $query ) ) {
				$state['done'] = true;
				$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id = %d', $table, $target ) );
			}

			return $query;
		};
		add_filter( 'query', $delete );
		try {
			return $step();
		} finally {
			remove_filter( 'query', $delete );
			self::assertTrue( $state['done'], 'The other request deleted the post.' );
		}
	}

	/**
	 * Counts the visible answers of a question.
	 *
	 * @param int $question_id Question ID.
	 * @return int
	 */
	private static function visible_answers( int $question_id ): int {
		return count( array_filter( Services::posts()->answers_of( $question_id ), static fn ( Post $answer ): bool => Post::ANSWER === $answer->type ) );
	}

	public function test_a_post_shown_again_is_not_deleted_by_a_request_that_read_it_hidden(): void {
		$service  = Services::post_service();
		$system   = Actor::system();
		$question = $this->ask( $this->user() );
		$answer   = $this->answer( $this->user(), $question );
		$comment  = $this->comment( $this->user(), $question, $question );
		$other    = $this->ask( $this->user(), 'Another question that is hidden and shown again' );

		$service->set_answer_status( $system, $answer, $question, Post::STATUS_HIDDEN );
		$service->set_comment_status( $system, $comment, $question, $question, Post::STATUS_HIDDEN );
		$service->set_question_status( $system, $other, Post::STATUS_HIDDEN );
		$stale = array(
			'answer'   => $this->post( $answer->id ),
			'comment'  => $this->post( $comment->id ),
			'question' => $this->post( $other->id ),
		);
		$service->set_answer_status( $system, $stale['answer'], $this->post( $question->id ), Post::STATUS_NORMAL );
		$service->set_comment_status( $system, $stale['comment'], $this->post( $question->id ), $this->post( $question->id ), Post::STATUS_NORMAL );
		$service->set_question_status( $system, $stale['question'], Post::STATUS_NORMAL );

		$results = array(
			'answer'   => $service->delete_answer( $system, $stale['answer'], $this->post( $question->id ) ),
			'comment'  => $service->delete_comment( $system, $stale['comment'], $this->post( $question->id ), $this->post( $question->id ) ),
			'question' => $service->delete_question( $system, $stale['question'] ),
		);

		foreach ( $results as $kind => $result ) {
			$this->assertWPError( $result, $kind );
			$this->assertSame( 'easyq_changed', $result->get_error_code(), $kind );
			$this->assertSame( Post::type_for( $stale[ $kind ]->base_type(), Post::STATUS_NORMAL ), $this->post( $stale[ $kind ]->id )->type, $kind );
		}
		$this->assertSame( 1, $this->post( $question->id )->answer_count );
		$this->assertSame( 1, self::visible_answers( $question->id ) );
	}

	public function test_a_post_deleted_between_change_and_reading_ends_in_an_error_result(): void {
		$service  = Services::post_service();
		$system   = Actor::system();
		$editor   = $this->user( 'editor' );
		$author   = $this->user();
		$category = Services::categories()->create( null, 'Target' );
		$this->assertIsInt( $category );

		$cases = array(
			'create_question'    => array( null, fn () => $service->create_question( $author, 'A question deleted at once', 'Content of it.' ), 0 ),
			'create_answer'      => array( null, fn ( Post $q ) => $service->create_answer( $author, $q, 'An answer deleted at once.' ), 0 ),
			'create_comment'     => array( null, fn ( Post $q ) => $service->create_comment( $author, $q, $q, 'A comment deleted at once.' ), 0 ),
			'edit_question'      => array( 'q', fn ( Post $q ) => $service->edit_question( $editor, $q, 'A changed title of the question', 'Changed content.', '', array(), null ), false ),
			'edit_answer'        => array( 'a', fn ( Post $q, Post $a ) => $service->edit_answer( $editor, $a, $q, 'A changed answer.', '', null ), false ),
			'edit_comment'       => array( 'c', fn ( Post $q, Post $a, Post $c ) => $service->edit_comment( $editor, $c, $q, $a, 'A changed comment.', '', null ), false ),
			'hide_question'      => array( 'q', fn ( Post $q ) => $service->set_question_status( $editor, $q, Post::STATUS_HIDDEN ), false ),
			'hide_answer'        => array( 'a', fn ( Post $q, Post $a ) => $service->set_answer_status( $editor, $a, $q, Post::STATUS_HIDDEN ), false ),
			'hide_comment'       => array( 'c', fn ( Post $q, Post $a, Post $c ) => $service->set_comment_status( $editor, $c, $q, $a, Post::STATUS_HIDDEN ), false ),
			'set_category'       => array( 'q', fn ( Post $q ) => $service->set_category( $editor, $q, $category ), false ),
			'answer_to_comment'  => array( 'a', fn ( Post $q, Post $a ) => $service->answer_to_comment( $editor, $a, $q, $q->id, (string) $a->content, '', null ), false ),
			'reopen'             => array( 'closed', fn ( Post $q ) => $service->reopen( $editor, $q ), null ),
			'close_as_duplicate' => array( 'closed', fn ( Post $q, Post $a, Post $c, Post $o ) => $service->close_as_duplicate( $editor, $q, $o->id ), 'easyq_changed' ),
			'close_with_note'    => array( null, fn ( Post $q ) => $service->close_with_note( $editor, $q, 'A note deleted at once.' ), 'easyq_changed' ),
		);

		foreach ( $cases as $case => [ $target, $step, $expected ] ) {
			$question = $this->ask( $this->user(), 'A question for the case ' . $case );
			$answer   = $this->answer( $this->user(), $question );
			$comment  = $this->comment( $this->user(), $question, $answer );
			$original = $this->ask( $this->user(), 'The original question of the case ' . $case );
			if ( 'closed' === $target ) {
				$service->close_as_duplicate( $system, $this->post( $question->id ), $original->id );
			}
			$posts = array( $this->post( $question->id ), $this->post( $answer->id ), $this->post( $comment->id ), $original );
			$id    = match ( $target ) {
				'q', 'closed' => $question->id,
				'a'           => $answer->id,
				'c'           => $comment->id,
				default       => null,
			};
			$result = self::deleted_in_between( $id, static fn () => $step( ...$posts ) );

			if ( is_string( $expected ) ) {
				$this->assertWPError( $result, $case );
				$this->assertSame( $expected, $result->get_error_code(), $case );
			} else {
				$this->assertSame( $expected, $result, $case );
			}
		}
	}

	public function test_a_post_hidden_in_between_is_neither_converted_nor_queued_again(): void {
		$service  = Services::post_service();
		$system   = Actor::system();
		$editor   = $this->user( 'editor' );
		$question = $this->ask( $this->user() );
		$answer   = $this->answer( $this->user(), $question );
		$comment  = $this->comment( $this->user(), $question, $question );
		$other    = $this->ask( $this->user(), 'Another question that is hidden while edited' );
		$stale    = array(
			'answer'   => $this->post( $answer->id ),
			'comment'  => $this->post( $comment->id ),
			'question' => $this->post( $other->id ),
		);
		$service->set_answer_status( $system, $stale['answer'], $this->post( $question->id ), Post::STATUS_HIDDEN );
		$service->set_comment_status( $system, $stale['comment'], $this->post( $question->id ), $this->post( $question->id ), Post::STATUS_HIDDEN );
		$service->set_question_status( $system, $stale['question'], Post::STATUS_HIDDEN );

		$this->assertFalse( $service->answer_to_comment( $editor, $stale['answer'], $this->post( $question->id ), $question->id, (string) $answer->content, '', null ) );
		$this->assertSame( 'A_HIDDEN', $this->post( $answer->id )->type );

		// An edit that sends the post back to moderation leaves a post someone hid in the meantime hidden.
		$service->edit_answer( $editor, $stale['answer'], $this->post( $question->id ), 'A changed answer text.', '', null, null, true );
		$service->edit_comment( $editor, $stale['comment'], $this->post( $question->id ), $this->post( $question->id ), 'A changed comment text.', '', null, null, true );
		$service->edit_question( $editor, $stale['question'], 'A changed title of the other question', 'Changed.', '', array(), null, null, true );
		$this->assertSame( 'A_HIDDEN', $this->post( $answer->id )->type );
		$this->assertSame( 'C_HIDDEN', $this->post( $comment->id )->type );
		$this->assertSame( 'Q_HIDDEN', $this->post( $other->id )->type );
		$this->assertSame( 0, $this->post( $question->id )->answer_count );
	}

	public function test_the_rest_api_answers_409_to_a_post_deleted_in_between(): void {
		$this->login( 'editor' );
		$category = Services::categories()->create( null, 'Target' );
		$this->assertIsInt( $category );

		$routes = array(
			'PUT /questions' => fn ( Post $q ) => array( 'PUT', '/questions/' . $q->id, array( 'title' => 'A changed title of the question' ) ),
			'PUT /answers'   => fn ( Post $q, Post $a ) => array( 'PUT', '/answers/' . $a->id, array( 'content' => 'A changed answer text.' ) ),
			'PUT /comments'  => fn ( Post $q, Post $a, Post $c ) => array( 'PUT', '/comments/' . $c->id, array( 'content' => 'A changed comment text.' ) ),
			'hide'           => fn ( Post $q, Post $a ) => array( 'POST', '/posts/' . $a->id . '/actions/hide', array() ),
			'move'           => fn ( Post $q ) => array( 'POST', '/posts/' . $q->id . '/actions/move', array( 'category' => $category ) ),
			'convert'        => fn ( Post $q, Post $a ) => array( 'POST', '/posts/' . $a->id . '/actions/convert', array( 'parent' => $q->id ) ),
			'reopen'         => fn ( Post $q ) => array( 'POST', '/posts/' . $q->id . '/actions/reopen', array() ),
			'close'          => fn ( Post $q ) => array( 'POST', '/posts/' . $q->id . '/actions/close', array( 'note' => 'A note deleted at once.' ) ),
		);
		Options::update( 'posting', array( 'comment_on_qs' => true ) );

		foreach ( $routes as $route => $request ) {
			$question = $this->ask( $this->user(), 'A question for the route ' . $route );
			$answer   = $this->answer( $this->user(), $question );
			$comment  = $this->comment( $this->user(), $question, $answer );
			if ( 'reopen' === $route ) {
				Services::post_service()->close_as_duplicate( Actor::system(), $question, $this->ask( $this->user(), 'The original question' )->id );
			}
			[ $method, $path, $params ] = $request( $question, $answer, $comment );
			$id                         = match ( $route ) {
				'PUT /questions', 'move', 'reopen' => $question->id,
				'close'                  => null,
				'PUT /comments'          => $comment->id,
				default                  => $answer->id,
			};

			$response = self::deleted_in_between( $id, fn () => $this->request( $method, $path, $params ) );

			$this->assertSame( 409, $response->get_status(), $route );
			$this->assertSame( 'easyq_changed', $response->as_error()?->get_error_code(), $route );
		}
	}

	public function test_two_erasures_at_once_end_without_error(): void {
		Options::update( 'general', array( 'erase_posts' => 'delete' ) );
		$author   = $this->user();
		$question = $this->ask( $author );
		$email    = (string) get_userdata( (int) $author->user_id )->user_email;

		// The other erasure deletes the question while this one hides it.
		$result = self::deleted_in_between( $question->id, static fn () => Post_Data::erase_posts( $email ) );

		$this->assertIsArray( $result );
		Services::posts()->forget( $question->id );
		$this->assertNull( Services::posts()->find( $question->id ) );
	}
}
