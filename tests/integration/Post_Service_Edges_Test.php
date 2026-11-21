<?php
/**
 * Tests for the branches of the post service that the usual paths do not take.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Actor;
use EasyQ\Points\Points;
use EasyQ\Posts\Post;
use EasyQ\Services;
use EasyQ\Settings\Options;

/**
 * Deleting what may not be deleted, the votes of deleted or moved posts, a question
 * reopened when its selection goes, a close note that moves with its question (F-213).
 *
 * @covers \EasyQ\Posts\Post_Service
 */
final class Post_Service_Edges_Test extends Test_Case {

	public function test_only_hidden_posts_without_dependents_are_deleted(): void {
		$service  = Services::post_service();
		$question = $this->ask( $this->user() );
		$answer   = $this->answer( $this->user(), $question );
		$comment  = $this->comment( $this->user(), $question, $answer );

		$this->assertSame( 'not_hidden', $this->error_code( $service->delete_answer( Actor::system(), $answer, $question ) ) );
		$this->assertSame( 'not_hidden', $this->error_code( $service->delete_comment( Actor::system(), $comment, $question, $answer ) ) );

		$service->set_answer_status( Actor::system(), $answer, $question, Post::STATUS_HIDDEN );
		$this->assertSame( 'has_dependents', $this->error_code( $service->delete_answer( Actor::system(), $this->post( $answer->id ), $question ) ) );
		$this->assertNotNull( Services::posts()->find( $answer->id ) );
	}

	public function test_the_voters_of_a_deleted_post_lose_what_they_got_for_their_vote(): void {
		$service  = Services::post_service();
		$question = $this->ask( $this->user() );
		$answer   = $this->answer( $this->user(), $question );
		$comment  = $this->comment( $this->user(), $question, $answer );
		$voter    = $this->user();
		Options::update( 'viewing', array( 'voting_on_cs' => true ) );
		Services::votes()->vote( $voter, $this->post( $comment->id ), 1 );
		Services::votes()->vote( $voter, $this->post( $answer->id ), 1 );
		$this->assertSame( 1, Services::points()->stats( (int) $voter->user_id )[ Points::ANSWER_UPVOTES ] );
		$this->assertSame( 1, Services::points()->stats( (int) $voter->user_id )[ Points::COMMENT_UPVOTES ] );

		$service->set_comment_status( Actor::system(), $this->post( $comment->id ), $question, $answer, Post::STATUS_HIDDEN );
		$this->assertTrue( $service->delete_comment( Actor::system(), $this->post( $comment->id ), $question, $this->post( $answer->id ) ) );
		$this->assertSame( 0, Services::points()->stats( (int) $voter->user_id )[ Points::COMMENT_UPVOTES ] );

		$service->set_answer_status( Actor::system(), $this->post( $answer->id ), $question, Post::STATUS_HIDDEN );
		$this->assertTrue( $service->delete_answer( Actor::system(), $this->post( $answer->id ), $question ) );
		$this->assertSame( 0, Services::points()->stats( (int) $voter->user_id )[ Points::ANSWER_UPVOTES ] );
	}

	public function test_an_answer_turned_into_a_comment_moves_the_votes_of_its_voters(): void {
		$question = $this->ask( $this->user() );
		$answer   = $this->answer( $this->user(), $question );
		$voter    = $this->user();
		Services::votes()->vote( $voter, $this->post( $answer->id ), 1 );

		Services::post_service()->answer_to_comment( Actor::system(), $this->post( $answer->id ), $question, $question->id, 'Now a comment on the question.', '', null );

		$stats = Services::points()->stats( (int) $voter->user_id );
		$this->assertSame( 0, $stats[ Points::ANSWER_UPVOTES ] );
		$this->assertSame( 1, $stats[ Points::COMMENT_UPVOTES ] );
	}

	public function test_a_changed_answer_turned_into_a_comment_waits_for_approval_again(): void {
		$question = $this->ask( $this->user() );
		$answer   = $this->answer( $this->user(), $question );

		Services::post_service()->answer_to_comment( $this->user( 'editor' ), $this->post( $answer->id ), $question, $question->id, 'Changed while it moves.', '', null, null, true );

		$this->assertTrue( $this->post( $answer->id )->is_queued() );
		$this->assertContains( 'c_requeue', $this->event_names() );
	}

	public function test_a_question_closed_by_its_selection_reopens_when_the_selection_goes(): void {
		Options::update( 'posting', array( 'do_close_on_select' => true ) );
		$question = $this->ask( $this->user() );
		$answer   = $this->answer( $this->user(), $question );
		Services::post_service()->select_answer( Actor::system(), $question, $answer->id );
		$this->assertTrue( \EasyQ\Posts\Post_Service::is_closed( $this->post( $question->id ) ) );

		$editor = $this->user( 'editor' );
		Services::post_service()->select_answer( $editor, $this->post( $question->id ), null );

		$this->assertFalse( \EasyQ\Posts\Post_Service::is_closed( $this->post( $question->id ) ) );
		$reopened = $this->post( $question->id );
		$this->assertSame( array( Post::UPDATE_CLOSED, $editor->user_id ), array( $reopened->update_type, $reopened->last_user_id ), 'The reopening is the last change of the question, by who did it.' );
		$this->assertContains( 'q_reopen', $this->event_names() );
	}

	public function test_the_close_note_moves_with_its_question_to_another_category(): void {
		Options::update( 'general', array( 'tags_or_categories' => 'c' ) );
		$first  = Services::categories()->create( null, 'First' );
		$second = Services::categories()->create( null, 'Second' );
		$this->assertIsInt( $first );
		$this->assertIsInt( $second );
		$question = $this->ask( $this->user(), 'A question that moves', 'Text.', array(), $first );
		Services::post_service()->close_with_note( Actor::system(), $question, 'Closed with a note.' );

		Services::post_service()->set_category( Actor::system(), $this->post( $question->id ), $second );

		$note = Services::posts()->close_note_of( $this->post( $question->id ) );
		$this->assertNotNull( $note );
		$this->assertSame( $second, $note->category_id );
	}

	public function test_visitors_claim_nothing_and_views_are_counted_only_while_on(): void {
		$question = $this->ask( new Actor( null, null, '192.0.2.7' ) );
		Services::post_service()->claim( new Actor( null, null, '192.0.2.7' ), $question );
		$this->assertNull( $this->post( $question->id )->user_id );

		Options::update( 'viewing', array( 'do_count_q_views' => false ) );
		Services::post_service()->record_view( $question );
		Services::posts()->forget( $question->id );
		$this->assertSame( 0, $this->post( $question->id )->views );
	}

	public function test_a_queued_comment_is_counted_at_once(): void {
		$question = $this->ask( $this->user() );
		$this->assertSame( 0, \EasyQ\Stats\Counts::get( \EasyQ\Stats\Counts::QUEUED ), 'Counted and kept before.' );

		Services::post_service()->create_comment( $this->user(), $question, $question, 'A comment that waits.', '', null, null, true );

		$this->assertSame( 1, \EasyQ\Stats\Counts::get( \EasyQ\Stats\Counts::QUEUED ) );
	}

	public function test_approved_answers_and_comments_take_the_time_of_their_approval(): void {
		global $wpdb;
		$question = $this->ask( $this->user() );
		$answer   = $this->answer( $this->user(), $question, 'An answer that waited.', true );
		$comment  = Services::post_service()->create_comment( $this->user(), $question, $question, 'A comment that waited.', '', null, null, true );
		$this->assertIsInt( $comment );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Dates of the test.
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET created = %s WHERE id IN (%d, %d)', \EasyQ\Database\Schema::table( 'posts' ), '2020-01-01 00:00:00', $answer->id, $comment ) );
		Services::posts()->forget( $answer->id );
		Services::posts()->forget( $comment );

		Services::post_service()->set_answer_status( Actor::system(), $this->post( $answer->id ), $question, Post::STATUS_NORMAL );
		Services::post_service()->set_comment_status( Actor::system(), $this->post( $comment ), $question, $question, Post::STATUS_NORMAL );

		$this->assertNotSame( '2020-01-01 00:00:00', $this->post( $answer->id )->created, 'As moderate_update_time of Question2Answer.' );
		$this->assertNotSame( '2020-01-01 00:00:00', $this->post( $comment )->created );
	}

	public function test_recalculating_many_hot_questions_empties_the_cache_of_posts_at_once(): void {
		global $wpdb;
		$question = $this->ask( $this->user() );
		$before   = Services::posts()->find( $question->id )?->hotness;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Views of the test.
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET views = 5000 WHERE id = %d', \EasyQ\Database\Schema::table( 'posts' ), $question->id ) );

		Services::posts()->update_hotness( $question->id, $question->id + 200 );

		$this->assertNotSame( $before, Services::posts()->find( $question->id )?->hotness );
	}

	/**
	 * Returns the code of an error, or null for another result.
	 *
	 * @param mixed $result Result.
	 * @return string|null
	 */
	private function error_code( mixed $result ): ?string {
		return $result instanceof \WP_Error ? (string) $result->get_error_code() : null;
	}
}
