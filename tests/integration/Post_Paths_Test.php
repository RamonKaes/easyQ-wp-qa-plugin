<?php
/**
 * Tests for paths of the posts, the search index and the points no other test runs.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Actor;
use EasyQ\Posts\Post;
use EasyQ\Services;

/**
 * @covers \EasyQ\Posts\Post_Service
 */
final class Post_Paths_Test extends Test_Case {

	/**
	 * Returns the posts a search finds, by the post that matched.
	 *
	 * @param string $words Words.
	 * @return int[]
	 */
	private function matched( string $words ): array {
		return array_map( static fn ( $result ): int => (int) $result->match_post_id, Services::search()->search( $words, 0, 50 ) );
	}

	/**
	 * Returns the number of rows of a post in the index of the content.
	 *
	 * @param int $post_id Post ID.
	 * @return int
	 */
	private function indexed( int $post_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE post_id = %d', \EasyQ\Database\Schema::table( 'contentwords' ), $post_id ) );
	}

	public function test_an_edited_answer_goes_back_to_moderation_with_its_comments_out_of_the_index(): void {
		$question = $this->ask( $this->user() );
		$author   = $this->user();
		$answer   = $this->answer( $author, $question, 'An answer about walruses here.' );
		$comment  = $this->comment( $this->user(), $question, $answer, 'A comment about narwhals.' );
		$this->assertSame( array( $comment->id ), $this->matched( 'narwhals' ) );

		Services::post_service()->edit_answer( $author, $this->post( $answer->id ), $this->post( $question->id ), 'An edited answer about walruses.', '', null, null, true );

		$this->assertSame( 'A_QUEUED', $this->post( $answer->id )->type );
		$this->assertSame( array(), $this->matched( 'narwhals' ) );
		$this->assertContains( 'a_requeue', $this->event_names() );
	}

	public function test_an_edited_comment_goes_back_to_moderation(): void {
		$question = $this->ask( $this->user() );
		$author   = $this->user();
		$comment  = $this->comment( $author, $question, $question, 'A comment before the edit.' );

		Services::post_service()->edit_comment( $author, $comment, $this->post( $question->id ), $this->post( $question->id ), 'A comment after the edit.', '', null, null, true );

		$this->assertSame( 'C_QUEUED', $this->post( $comment->id )->type );
		$this->assertContains( 'c_requeue', $this->event_names() );
	}

	public function test_an_approved_answer_comes_into_the_index_with_its_comments_and_is_posted(): void {
		$question = $this->ask( $this->user() );
		$answer   = $this->post( Services::post_service()->create_answer( $this->user(), $question, 'A waiting answer about otters.', '', null, null, true ) );
		$comment  = $this->comment( $this->user(), $question, $answer, 'A comment about beavers.' );
		$this->assertSame( array(), $this->matched( 'otters' ) );

		Services::post_service()->set_answer_status( Actor::system(), $answer, $this->post( $question->id ), Post::STATUS_NORMAL );

		$this->assertSame( array( $answer->id ), $this->matched( 'otters' ) );
		$this->assertSame( array( $comment->id ), $this->matched( 'beavers' ) );
		$this->assertSame( $answer->id, $this->event_params( 'a_post' )['post_id'] ?? null );
	}

	public function test_an_approved_comment_comes_into_the_index_and_is_posted(): void {
		$question = $this->ask( $this->user() );
		$comment  = $this->post( Services::post_service()->create_comment( $this->user(), $question, $question, 'A waiting comment about badgers.', '', null, null, true ) );
		$this->assertSame( array(), $this->matched( 'badgers' ) );

		Services::post_service()->set_comment_status( Actor::system(), $comment, $this->post( $question->id ), $this->post( $question->id ), Post::STATUS_NORMAL );

		$this->assertSame( array( $comment->id ), $this->matched( 'badgers' ) );
		$this->assertSame( $comment->id, $this->event_params( 'c_post' )['post_id'] ?? null );
	}

	public function test_the_selection_goes_when_the_selected_answer_becomes_a_comment(): void {
		$asker    = $this->user();
		$question = $this->ask( $asker );
		$answer   = $this->answer( $this->user(), $question, 'The selected answer here.' );
		Services::post_service()->select_answer( $asker, $question, $answer->id );
		$question = $this->post( $question->id );
		$this->assertSame( $answer->id, $question->selected_child_id );

		Services::post_service()->answer_to_comment( Actor::system(), $this->post( $answer->id ), $question, $question->id, 'The selected answer here.', '', null );

		Services::posts()->forget( $question->id );
		$this->assertNull( $this->post( $question->id )->selected_child_id );
	}

	public function test_hiding_and_reshowing_a_question_takes_its_comments_and_note_out_of_and_into_the_index(): void {
		$question = $this->ask( $this->user() );
		$answer   = $this->answer( $this->user(), $question );
		$on_q     = $this->comment( $this->user(), $question, $question, 'A comment about herons.' );
		$on_a     = $this->comment( $this->user(), $question, $answer, 'A comment about storks.' );
		Services::post_service()->close_with_note( Actor::system(), $this->post( $question->id ), 'Closed because of pelicans.' );
		$note = (int) $this->post( $question->id )->closed_by_id;
		$this->assertSame( array( $note ), $this->matched( 'pelicans' ) );

		Services::post_service()->set_question_status( Actor::system(), $this->post( $question->id ), Post::STATUS_HIDDEN );
		$this->assertSame( array(), array_merge( $this->matched( 'herons' ), $this->matched( 'storks' ), $this->matched( 'pelicans' ) ) );
		// Out of the index itself, not only left out of the results: the word counts follow.
		foreach ( array( $on_q->id, $on_a->id, $note ) as $id ) {
			$this->assertSame( 0, $this->indexed( $id ), "Post $id" );
		}

		Services::post_service()->set_question_status( Actor::system(), $this->post( $question->id ), Post::STATUS_NORMAL );
		$this->assertSame( array( $on_q->id ), $this->matched( 'herons' ) );
		$this->assertSame( array( $on_a->id ), $this->matched( 'storks' ) );
		$this->assertSame( array( $note ), $this->matched( 'pelicans' ) );
	}
}
