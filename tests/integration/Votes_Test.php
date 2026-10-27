<?php
/**
 * Tests for votes and flags.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Actor;
use EasyQ\Posts\Post;
use EasyQ\Services;
use EasyQ\Settings\Options;
use EasyQ\Stats\Counts;
use WP_Error;

/**
 * @covers \EasyQ\Votes\Votes
 */
final class Votes_Test extends Test_Case {

	public function test_upvote_updates_post_and_points(): void {
		$author   = $this->user();
		$voter    = $this->user();
		$question = $this->ask( $author );

		$this->assertTrue( Services::votes()->vote( $voter, $question, 1 ) );

		$voted = $this->post( $question->id );
		$this->assertSame( 1, $voted->upvotes );
		$this->assertSame( 1, $voted->net_votes );
		$this->assertSame( 1, Services::votes()->vote_of( $question->id, (int) $voter->user_id ) );
		$this->assertSame( 110, Services::points()->get( (int) $voter->user_id ) );
		$this->assertSame( 130, Services::points()->get( (int) $author->user_id ) );
		$this->assertSame( 1, $this->event_params( 'q_vote_up' )['vote'] );
	}

	public function test_changing_and_removing_a_vote(): void {
		$question = $this->ask( $this->user() );
		$voter    = $this->user();

		Services::votes()->vote( $voter, $question, 1 );
		Services::votes()->vote( $voter, $this->post( $question->id ), -1 );

		$voted = $this->post( $question->id );
		$this->assertSame( 0, $voted->upvotes );
		$this->assertSame( 1, $voted->downvotes );
		$this->assertSame( -1, $voted->net_votes );
		$this->assertSame( 1, $this->event_params( 'q_vote_down' )['old_vote'] );

		Services::votes()->vote( $voter, $this->post( $question->id ), 0 );

		$this->assertSame( 0, $this->post( $question->id )->net_votes );
		$this->assertContains( 'q_vote_nil', $this->event_names() );
		$this->assertSame( 100, Services::points()->get( (int) $voter->user_id ) );
	}

	public function test_repeating_the_same_vote_changes_nothing(): void {
		$question = $this->ask( $this->user() );
		$voter    = $this->user();

		Services::votes()->vote( $voter, $question, 1 );
		$count = count( $this->events );
		Services::votes()->vote( $voter, $this->post( $question->id ), 1 );

		$this->assertCount( $count, $this->events );
		$this->assertSame( 1, $this->post( $question->id )->upvotes );
	}

	public function test_vote_points_are_capped(): void {
		Options::update( 'points', array( 'points_q_voted_max_gain' => 2 ) );
		$author   = $this->user();
		$question = $this->ask( $author );

		for ( $i = 0; $i < 3; $i++ ) {
			Services::votes()->vote( $this->user(), $this->post( $question->id ), 1 );
		}

		$this->assertSame( 100 + 20 + 20, Services::points()->get( (int) $author->user_id ) );
	}

	public function test_answer_votes_update_the_question(): void {
		$question = $this->ask( $this->user() );
		$answer   = $this->answer( $this->user(), $question );
		$this->assertSame( 1, Counts::get( Counts::UNUPVOTED ) );

		Services::votes()->vote( $this->user(), $answer, 1 );

		$this->assertSame( 1, $this->post( $question->id )->answer_max_votes );
		$this->assertSame( 0, Counts::get( Counts::UNUPVOTED ) );
	}

	/**
	 * @dataProvider provide_disallowed_votes
	 *
	 * @param string $scenario Which rule to test.
	 * @param string $code Expected error code.
	 */
	public function test_refuses_disallowed_votes( string $scenario, string $code ): void {
		$author   = $this->user();
		$question = $this->ask( $author );
		$voter    = $this->user();
		$post     = $question;

		switch ( $scenario ) {
			case 'anonymous':
				$voter = new Actor();
				break;
			case 'own':
				$voter = $author;
				break;
			case 'hidden':
				Services::post_service()->set_question_status( Actor::system(), $question, Post::STATUS_HIDDEN );
				$post = $this->post( $question->id );
				break;
			case 'queued':
				$post = $this->ask( $author, 'Queued question title', 'x', array(), null, true );
				break;
			case 'comment':
				$post = $this->comment( $author, $question, $question );
				break;
		}

		$result = Services::votes()->vote( $voter, $post, 1 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
	}

	/**
	 * Provides votes that are not allowed.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function provide_disallowed_votes(): array {
		return array(
			'anonymous visitor'               => array( 'anonymous', 'login_required' ),
			'own post'                        => array( 'own', 'vote_not_allowed' ),
			'hidden post'                     => array( 'hidden', 'post_hidden' ),
			'queued post'                     => array( 'queued', 'post_queued' ),
			'comment with comment voting off' => array( 'comment', 'vote_not_allowed' ),
		);
	}

	public function test_flagging_and_unflagging(): void {
		$question = $this->ask( $this->user() );
		$flagger  = $this->user();

		$this->assertFalse( Services::votes()->flag( $flagger, $question, $question ) );
		$this->assertSame( 1, $this->post( $question->id )->flag_count );
		$this->assertTrue( Services::votes()->has_flagged( $question->id, (int) $flagger->user_id ) );
		$this->assertSame( 1, Counts::get( Counts::FLAGGED ) );

		Services::votes()->flag( $flagger, $this->post( $question->id ), $question );
		$this->assertSame( 1, $this->post( $question->id )->flag_count );

		Services::votes()->unflag( $flagger, $this->post( $question->id ) );
		$this->assertSame( 0, $this->post( $question->id )->flag_count );
		$this->assertSame( array( 'q_flag', 'q_unflag' ), array_values( array_intersect( $this->event_names(), array( 'q_flag', 'q_unflag' ) ) ) );
	}

	public function test_enough_flags_hide_the_post(): void {
		Options::update( 'spam', array( 'flagging_hide_after' => 2 ) );
		$question = $this->ask( $this->user() );
		$answer   = $this->answer( $this->user(), $question );

		$this->assertFalse( Services::votes()->flag( $this->user(), $answer, $question ) );
		$this->assertTrue( Services::votes()->flag( $this->user(), $this->post( $answer->id ), $question ) );

		$this->assertSame( 'A_HIDDEN', $this->post( $answer->id )->type );
	}

	public function test_clearing_all_flags(): void {
		$question = $this->ask( $this->user() );
		Services::votes()->flag( $this->user(), $question, $question );
		Services::votes()->flag( $this->user(), $this->post( $question->id ), $question );

		Services::votes()->clear_flags( $this->user( 'editor' ), $this->post( $question->id ) );

		$this->assertSame( 0, $this->post( $question->id )->flag_count );
		$this->assertContains( 'q_clearflags', $this->event_names() );
	}

	public function test_own_posts_cannot_be_flagged(): void {
		$author   = $this->user();
		$question = $this->ask( $author );

		$this->assertInstanceOf( WP_Error::class, Services::votes()->flag( $author, $question, $question ) );
	}

	public function test_primed_votes_are_forgotten_after_voting(): void {
		$question = $this->ask( $this->user() );
		$voter    = $this->user();
		Services::votes()->prime( array( $question->id ), (int) $voter->user_id );
		$this->assertSame( 0, Services::votes()->vote_of( $question->id, (int) $voter->user_id ) );

		Services::votes()->vote( $voter, $question, 1 );

		$this->assertSame( 1, Services::votes()->vote_of( $question->id, (int) $voter->user_id ) );
	}
}
