<?php
/**
 * Tests for the moderation lists.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Actor;
use EasyQ\Posts\Post;
use EasyQ\Services;
use EasyQ\Users\Levels;

/**
 * @covers \EasyQ\Posts\Moderation_Query
 * @covers \EasyQ\Users\Permissions::category_scope
 */
final class Moderation_Query_Test extends Test_Case {

	/**
	 * Returns the IDs of a view.
	 *
	 * @param Actor                $actor Moderator.
	 * @param array<string, mixed> $args  Query arguments.
	 * @return int[]
	 */
	private function ids( Actor $actor, array $args = array() ): array {
		return array_map( static fn ( Post $post ): int => $post->id, Services::moderation_query()->find( $actor, $args + array( 'per_page' => 100 ) )['posts'] );
	}

	/**
	 * Creates a category and fails the test if that does not work.
	 *
	 * @param int|null $parent_id Parent ID.
	 * @param string   $title     Name.
	 * @return int
	 */
	private function category( ?int $parent_id, string $title ): int {
		$id = Services::categories()->create( $parent_id, $title );
		$this->assertIsInt( $id );

		return $id;
	}

	public function test_lists_each_view_for_moderators_everywhere(): void {
		$admin    = Actor::user( $this->administrator() );
		$visible  = $this->ask( $this->user(), 'A visible question here' );
		$answer   = $this->answer( $this->user(), $visible );
		$queued   = $this->ask( $this->user(), 'A question awaiting approval', 'x', array(), null, true );
		$hidden   = $this->ask( $this->user(), 'A question that gets hidden' );
		$flagged  = $this->ask( $this->user(), 'A question with one flag' );
		$flagged2 = $this->ask( $this->user(), 'A question with two flags' );
		$closed   = $this->ask( $this->user(), 'A question that gets closed' );

		Services::post_service()->set_question_status( $admin, $hidden, Post::STATUS_HIDDEN );
		Services::votes()->flag( $this->user(), $flagged, $flagged );
		Services::votes()->flag( $this->user(), $flagged2, $flagged2 );
		Services::votes()->flag( $this->user(), $flagged2, $flagged2 );
		Services::post_service()->close_with_note( $admin, $closed, 'Off topic.' );

		$this->assertSame( array( $closed->id, $flagged2->id, $flagged->id, $hidden->id, $queued->id, $answer->id, $visible->id ), $this->ids( $admin ) );
		$this->assertSame( array( $queued->id ), $this->ids( $admin, array( 'view' => 'pending' ) ) );
		$this->assertSame( array( $flagged2->id, $flagged->id ), $this->ids( $admin, array( 'view' => 'flagged' ) ) );
		$this->assertSame( array( $hidden->id ), $this->ids( $admin, array( 'view' => 'hidden' ) ) );
		$this->assertSame( array( $closed->id ), $this->ids( $admin, array( 'view' => 'closed' ) ) );

		$this->assertSame(
			array(
				'all'     => 7,
				'pending' => 1,
				'flagged' => 2,
				'hidden'  => 1,
				'closed'  => 1,
			),
			Services::moderation_query()->counts( $admin )
		);
	}

	public function test_category_moderators_see_waiting_and_hidden_posts_only_in_their_categories(): void {
		$own      = $this->category( null, 'Own' );
		$own_sub  = $this->category( $own, 'Own sub' );
		$other    = $this->category( null, 'Other' );
		$author   = $this->user();
		$in_sub   = $this->ask( $author, 'Waiting in the subcategory', 'x', array(), $own_sub, true );
		$in_other = $this->ask( $author, 'Waiting in another category', 'x', array(), $other, true );
		$visible  = $this->ask( $author, 'Visible in another category', 'x', array(), $other );

		$moderator = $this->user();
		Services::user_levels()->set_category_level( Actor::system(), (int) $moderator->user_id, $own, Levels::MODERATOR );

		$this->assertSame( array( $in_sub->id ), $this->ids( $moderator, array( 'view' => 'pending' ) ) );
		// Visible posts are listed everywhere; others may see them on the site anyway.
		$this->assertSame( array( $visible->id, $in_sub->id ), $this->ids( $moderator ) );
		$this->assertSame( 1, Services::moderation_query()->count( $moderator, 'pending' ) );

		// Users without any moderation level see no waiting posts.
		$this->assertSame( array(), $this->ids( $this->user(), array( 'view' => 'pending' ) ) );
		$this->assertSame( array( $in_other->id, $in_sub->id ), $this->ids( Actor::user( $this->administrator() ), array( 'view' => 'pending' ) ) );
	}

	public function test_filters_and_pages(): void {
		$admin    = Actor::user( $this->administrator() );
		$parent   = $this->category( null, 'Parent' );
		$child    = $this->category( $parent, 'Child' );
		$author   = $this->user();
		$from_ip  = new Actor( $this->user()->user_id, null, '198.51.100.7' );
		$in_child = $this->ask( $author, 'A question about widgets', 'x', array(), $child );
		$answer   = $this->answer( $from_ip, $in_child, 'An answer about gadgets.' );
		$other    = $this->ask( $this->user(), 'Something else entirely' );

		$this->assertSame(
			array( $in_child->id ),
			$this->ids(
				$admin,
				array(
					'type'        => 'question',
					'category_id' => $parent,
				)
			)
		);
		$this->assertSame( array( $answer->id ), $this->ids( $admin, array( 'type' => 'answer' ) ) );
		$this->assertSame( array( $answer->id, $in_child->id ), $this->ids( $admin, array( 'category_id' => $child ) ) );
		$this->assertSame( array( $in_child->id ), $this->ids( $admin, array( 'user_id' => $author->user_id ) ) );
		$this->assertSame( array( $answer->id ), $this->ids( $admin, array( 'ip' => '198.51.100.7' ) ) );
		$this->assertSame( array(), $this->ids( $admin, array( 'ip' => 'not an address' ) ) );
		$this->assertSame( array( $answer->id ), $this->ids( $admin, array( 'search' => 'gadget' ) ) );
		$this->assertSame( array( $other->id ), $this->ids( $admin, array( 'search' => 'ELSE' ) ) );
		$this->assertSame( array( $other->id, $answer->id, $in_child->id ), $this->ids( $admin, array( 'month' => gmdate( 'Ym' ) ) ) );
		$this->assertSame( array(), $this->ids( $admin, array( 'month' => '200001' ) ) );
		$this->assertSame( array( gmdate( 'Ym' ) ), Services::moderation_query()->months() );

		$page = Services::moderation_query()->find(
			$admin,
			array(
				'page'     => 2,
				'per_page' => 2,
			)
		);
		$this->assertSame( 3, $page['total'] );
		$this->assertSame( array( $in_child->id ), array_map( static fn ( Post $post ): int => $post->id, $page['posts'] ) );
	}
}
