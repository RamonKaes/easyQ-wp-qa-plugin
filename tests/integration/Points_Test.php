<?php
/**
 * Tests for user points.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Points\Points;
use EasyQ\Services;
use EasyQ\Settings\Options;
use EasyQ\Stats\Counts;

/**
 * @covers \EasyQ\Points\Points
 */
final class Points_Test extends Test_Case {

	public function test_users_without_activity_have_the_base_points(): void {
		$this->assertSame( 100, Services::points()->get( self::factory()->user->create() ) );
	}

	public function test_total_uses_multipliers_and_bonus(): void {
		$points = new Points();

		$this->assertSame(
			100 + 5 + 2 * 20 + 1 * 40 + 3 * 10,
			$points->total(
				array(
					'bonus'                      => 5,
					Points::QUESTIONS            => 2,
					Points::ANSWERS              => 1,
					Points::QUESTION_VOTE_POINTS => 3,
					Points::COMMENTS             => 7,
				)
			)
		);
	}

	public function test_bonus_points(): void {
		$user = self::factory()->user->create();

		Services::points()->set_bonus( $user, 25 );

		$this->assertSame( 125, Services::points()->get( $user ) );
		$this->assertSame( 1, Counts::get( Counts::USERS_WITH_POINTS ) );
	}

	public function test_recalculates_all_statistics_with_changed_settings(): void {
		$author = $this->user();
		$this->ask( $author );
		Options::update( 'points', array( 'points_post_q' => 5 ) );

		Services::points()->update_all( (int) $author->user_id );

		$this->assertSame( 150, Services::points()->get( (int) $author->user_id ) );
	}

	public function test_answers_selected_by_their_own_author_do_not_count(): void {
		$author   = $this->user();
		$question = $this->ask( $author );
		$answer   = $this->answer( $author, $question );

		Services::post_service()->select_answer( $author, $question, $answer->id );

		$this->assertSame( 0, Services::points()->calculate( (int) $author->user_id, Points::ANSWERS_SELECTED ) );
		$this->assertSame( 1, Services::points()->calculate( (int) $author->user_id, Points::SELECTIONS ) );
	}

	public function test_anonymous_authors_get_no_points(): void {
		Services::points()->update( null, array( Points::QUESTIONS ) );

		$this->assertSame( 0, Counts::get( Counts::USERS_WITH_POINTS ) );
	}

	public function test_points_read_before_a_change_are_not_reused(): void {
		$user = self::factory()->user->create();
		$this->assertSame( 100, Services::points()->get( $user ) );

		Services::points()->set_bonus( $user, 25 );

		$this->assertSame( 125, Services::points()->get( $user ) );
	}
}
