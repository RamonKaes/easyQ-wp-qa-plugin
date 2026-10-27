<?php
/**
 * Tests for question lists.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Actor;
use EasyQ\Database\DB;
use EasyQ\Database\Schema;
use EasyQ\Services;

/**
 * @covers \EasyQ\Posts\Question_Query
 */
final class Question_Query_Test extends Test_Case {

	/**
	 * Returns the IDs of a question list.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return int[]
	 */
	private function ids( array $args ): array {
		return array_map( static fn ( $post ): int => $post->id, Services::question_query()->find( $args )['posts'] );
	}

	public function test_unanswered_lists_follow_their_conditions_and_leave_out_closed_questions(): void {
		$author    = $this->user();
		$no_answer = $this->ask( $author, 'Question without any answer' );
		$plain     = $this->ask( $author, 'Question with a plain answer' );
		$selected  = $this->ask( $author, 'Question with a selected answer' );
		$closed    = $this->ask( $author, 'Question that was closed' );
		$this->answer( $this->user(), $plain );
		$selected_a = $this->answer( $this->user(), $selected );
		Services::votes()->vote( $this->user(), $selected_a, 1 );
		Services::post_service()->select_answer( $author, $this->post( $selected->id ), $selected_a->id );
		Services::post_service()->close_with_note( Actor::system(), $closed, 'Closed.' );

		$this->assertSame( array( $no_answer->id ), $this->ids( array( 'source' => 'unanswered' ) ) );
		$this->assertEqualsCanonicalizing( array( $no_answer->id, $plain->id ), $this->ids( array( 'source' => 'unselected' ) ) );
		$this->assertEqualsCanonicalizing( array( $no_answer->id, $plain->id ), $this->ids( array( 'source' => 'unupvoted' ) ) );
		$this->assertContains( $closed->id, $this->ids( array( 'source' => 'recent' ) ) );
	}

	public function test_filters_by_author_and_by_parent_category(): void {
		$author = $this->user();
		$other  = $this->user();
		$parent = Services::categories()->create( null, 'Parent' );
		$this->assertIsInt( $parent );
		$child = Services::categories()->create( $parent, 'Child' );
		$this->assertIsInt( $child );
		$in_child = $this->ask( $author, 'Question in the child category', 'x', array(), $child );
		$by_other = $this->ask( $other, 'Question by another user' );

		$this->assertSame( array( $by_other->id ), $this->ids( array( 'user_id' => (int) $other->user_id ) ) );
		$this->assertSame( array( $in_child->id ), $this->ids( array( 'category_id' => $parent ) ) );
		$this->assertSame( array( $in_child->id ), $this->ids( array( 'category_id' => $child ) ) );
		$this->assertSame( array(), $this->ids( array( 'category_id' => 999999 ) ) );
	}

	public function test_sorts_by_hotness_and_views(): void {
		$author = $this->user();
		$first  = $this->ask( $author, 'First question asked' );
		$second = $this->ask( $author, 'Second question asked' );
		// Hotness values from the Question2Answer formula are large; fixed ones make the order clear.
		DB::wpdb()->update( Schema::table( 'posts' ), array( 'hotness' => 2.0 ), array( 'id' => $first->id ) );
		DB::wpdb()->update( Schema::table( 'posts' ), array( 'hotness' => 1.0 ), array( 'id' => $second->id ) );
		Services::posts()->increment_views( $first->id );
		Services::posts()->forget( $first->id );

		$this->assertSame( array( $second->id, $first->id ), $this->ids( array( 'source' => 'recent' ) ) );
		$this->assertSame( array( $first->id, $second->id ), $this->ids( array( 'source' => 'hot' ) ) );
		$this->assertSame( array( $first->id, $second->id ), $this->ids( array( 'source' => 'views' ) ) );
		$this->assertSame( 2, Services::question_query()->find( array( 'source' => 'views' ) )['total'] );
	}
}
