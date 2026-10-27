<?php
/**
 * Tests for activity lists.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Actor;
use EasyQ\Database\DB;
use EasyQ\Database\Schema;
use EasyQ\Posts\Activity_Item;
use EasyQ\Posts\Activity_Query;
use EasyQ\Posts\Post;
use EasyQ\Services;

/**
 * @covers \EasyQ\Posts\Activity_Query
 * @covers \EasyQ\Posts\Activity_Item
 */
final class Activity_Query_Test extends Test_Case {

	/**
	 * Query under test.
	 *
	 * @var Activity_Query
	 */
	private Activity_Query $query;

	public function set_up(): void {
		parent::set_up();
		$this->query = Services::activity_query();
	}

	/**
	 * Moves the creation time of a post into the past.
	 *
	 * @param Post $post    Post.
	 * @param int  $seconds Seconds ago.
	 */
	private function age( Post $post, int $seconds ): void {
		$wpdb = DB::wpdb();
		$time = gmdate( 'Y-m-d H:i:s', time() - $seconds );
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET created = %s WHERE id = %d', Schema::table( 'posts' ), $time, $post->id ) );
		Services::posts()->forget( $post->id );
	}

	public function test_lists_one_item_per_question_with_the_latest_activity(): void {
		$author   = $this->user();
		$first    = $this->ask( $author, 'The first question of the activity list' );
		$second   = $this->ask( $author, 'The second question of the activity list' );
		$answer   = $this->answer( $this->user(), $first );
		$question = $this->ask( $author, 'A question with a comment' );
		$comment  = $this->comment( $this->user(), $question, $question );
		$this->age( $first, 3000 );
		$this->age( $second, 2000 );
		$this->age( $answer, 1000 );
		$this->age( $question, 500 );

		$items = $this->query->recent( Activity_Query::KINDS, array(), 10 );

		$this->assertSame( array( $question->id, $first->id, $second->id ), array_map( static fn ( Activity_Item $item ): int => $item->question_id, $items ) );
		$this->assertSame( $comment->id, $items[0]->post_id );
		$this->assertSame( 'C', $items[0]->base_type );
		$this->assertSame( $answer->id, $items[1]->post_id );
		$this->assertSame( 'answered', $items[1]->label() );
		$this->assertSame( 'asked', $items[2]->label() );
	}

	public function test_lists_answers_or_comments_only(): void {
		$question = $this->ask( $this->user() );
		$answer   = $this->answer( $this->user(), $question );
		$this->comment( $this->user(), $question, $answer );

		$answers  = $this->query->recent( array( 'answers' ), array(), 10 );
		$comments = $this->query->recent( array( 'comments' ), array(), 10 );

		$this->assertSame( array( $answer->id ), array_map( static fn ( Activity_Item $item ): int => $item->post_id, $answers ) );
		$this->assertCount( 1, $comments );
		$this->assertSame( $question->id, $comments[0]->question_id );
	}

	public function test_hidden_posts_and_posts_of_hidden_questions_are_left_out(): void {
		$question = $this->ask( $this->user() );
		$answer   = $this->answer( $this->user(), $question );
		$visible  = $this->ask( $this->user(), 'A visible question stays in the list' );
		Services::post_service()->set_question_status( Actor::system(), $this->post( $question->id ), Post::STATUS_HIDDEN );

		$items = $this->query->recent( Activity_Query::KINDS, array(), 10 );

		$this->assertSame( array( $visible->id ), array_map( static fn ( Activity_Item $item ): int => $item->question_id, $items ) );
		$this->assertSame( array(), $this->query->recent( array( 'answers' ), array(), 10 ) );
		$this->assertNotNull( $answer );
	}

	public function test_edits_are_activity_of_the_editor(): void {
		$author   = $this->user();
		$editor   = $this->user( 'editor' );
		$question = $this->ask( $author );
		$this->age( $question, 3600 );
		Services::post_service()->edit_question( $editor, $this->post( $question->id ), 'An edited title for the activity', 'New content.', '', array( 'widgets' ), null );

		$items = $this->query->recent( Activity_Query::KINDS, array(), 10 );

		$this->assertSame( Post::UPDATE_CONTENT, $items[0]->update_type );
		$this->assertSame( $editor->user_id, $items[0]->user_id );
		$this->assertSame( 'edited', $items[0]->label() );
		$this->assertCount( 1, $this->query->recent( Activity_Query::KINDS, array( 'user_id' => $editor->user_id ), 10 ) );
	}

	public function test_a_quick_edit_by_the_author_shows_as_the_creation(): void {
		$author   = $this->user();
		$question = $this->ask( $author );
		Services::post_service()->edit_question( $author, $this->post( $question->id ), 'A title fixed right after asking', 'Content.', '', array( 'widgets' ), null );

		$items = $this->query->recent( Activity_Query::KINDS, array(), 10 );

		$this->assertCount( 1, $items );
		$this->assertNull( $items[0]->update_type );
	}

	public function test_filters_by_user_and_category(): void {
		$category = Services::categories()->create( null, 'Hardware' );
		$this->assertIsInt( $category );
		$author = $this->user();
		$in     = $this->ask( $author, 'A question inside the category', 'Content.', array(), $category );
		$this->ask( $this->user(), 'A question outside the category' );

		$by_category = $this->query->recent( Activity_Query::KINDS, array( 'category_id' => $category ), 10 );
		$by_user     = $this->query->recent( Activity_Query::KINDS, array( 'user_id' => $author->user_id ), 10 );

		$this->assertSame( array( $in->id ), array_map( static fn ( Activity_Item $item ): int => $item->question_id, $by_category ) );
		$this->assertSame( array( $in->id ), array_map( static fn ( Activity_Item $item ): int => $item->question_id, $by_user ) );
		$this->assertSame( array(), $this->query->recent( Activity_Query::KINDS, array( 'category_id' => 999999 ), 10 ) );
	}

	public function test_lists_the_answers_of_a_user_page_by_page(): void {
		$author   = $this->user();
		$question = $this->ask( $this->user() );
		$answers  = array();
		foreach ( array( 3000, 2000, 1000 ) as $age ) {
			$answer    = $this->answer( $author, $question, "An answer written $age seconds ago." );
			$answers[] = $answer->id;
			$this->age( $answer, $age );
		}

		$first  = $this->query->by_user( 'answers', (int) $author->user_id, 1, 2 );
		$second = $this->query->by_user( 'answers', (int) $author->user_id, 2, 2 );

		$this->assertSame( 3, $first['total'] );
		$this->assertSame( array( $answers[2], $answers[1] ), array_map( static fn ( Activity_Item $item ): int => $item->post_id, $first['items'] ) );
		$this->assertSame( array( $answers[0] ), array_map( static fn ( Activity_Item $item ): int => $item->post_id, $second['items'] ) );
		$this->assertSame( 0, $this->query->by_user( 'edits', (int) $author->user_id, 1, 2 )['total'] );
	}

	public function test_items_survive_the_block_context(): void {
		$item = new Activity_Item( 1, 2, 'A', '2026-10-14 10:00:00', Post::UPDATE_SELECTED, 3 );

		$copy = Activity_Item::from_context( 1, $item->to_context() );

		$this->assertEquals( $item, $copy );
		$this->assertSame( 'answer selected', $item->label() );
		$this->assertNull( Activity_Item::from_context( 1, 'invalid' ) );
	}
}
