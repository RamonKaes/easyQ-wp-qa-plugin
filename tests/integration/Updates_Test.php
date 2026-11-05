<?php
/**
 * Tests for the event streams behind the updates page.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Actor;
use EasyQ\Blocks\Block_Context;
use EasyQ\Blocks\Lists;
use EasyQ\Database\Schema;
use EasyQ\Frontend\Route;
use EasyQ\Frontend\Templates;
use EasyQ\Posts\Activity_Item;
use EasyQ\Posts\Post;
use EasyQ\Privacy\Privacy;
use EasyQ\Services;
use EasyQ\Tools\Tools;
use EasyQ\Updates\Updates;

/**
 * @covers \EasyQ\Updates\Updates
 */
final class Updates_Test extends Test_Case {

	/**
	 * Returns the updates of a user.
	 *
	 * @param Actor $actor User.
	 * @param bool  $favorites Whether to include favorites.
	 * @param bool  $content   Whether to include own content.
	 * @return list<Activity_Item>
	 */
	private function items( Actor $actor, bool $favorites = true, bool $content = true ): array {
		return Services::updates()->items( (int) $actor->user_id, $favorites, $content );
	}

	/**
	 * Counts rows of a stream table.
	 *
	 * @param string $table Table.
	 * @param string $where Condition.
	 * @return int
	 */
	private function count_rows( string $table, string $where = '1 = 1' ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed conditions of this test.
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( $table ) . " WHERE $where" );
	}

	public function test_tells_the_author_of_answers_comments_and_selections(): void {
		$asker    = $this->user();
		$helper   = $this->user();
		$question = $this->ask( $asker );
		$answer   = $this->answer( $helper, $question );

		$item = $this->items( $asker )[0];
		$this->assertSame( $answer->id, $item->post_id );
		$this->assertTrue( $item->personal );
		$this->assertSame( 'your question answered', $item->label( $this->post( $answer->id ) ) );

		$this->comment( $asker, $this->post( $question->id ), $this->post( $answer->id ) );
		$this->assertSame( 'your answer commented', $this->items( $helper )[0]->label() );

		Services::post_service()->select_answer( $asker, $this->post( $question->id ), $answer->id );
		$this->assertSame( 'your answer selected', $this->items( $helper )[0]->label() );

		// The one who did it hears nothing of it.
		$this->assertSame( array(), $this->items( $this->user() ) );
	}

	public function test_followers_of_every_entity_hear_of_new_questions(): void {
		$author   = $this->user();
		$category = Services::categories()->create( null, 'Parent' );
		$this->assertIsInt( $category );
		$child = Services::categories()->create( $category, 'Child' );
		$this->assertIsInt( $child );
		$this->ask( $author, 'Seed the tag', 'Text.', array( 'followed' ) );

		$of_user     = $this->user();
		$of_tag      = $this->user();
		$of_category = $this->user();
		Services::favorites()->set( $of_user, 'user', (int) $author->user_id, true );
		Services::favorites()->set( $of_tag, 'tag', 'followed', true );
		Services::favorites()->set( $of_category, 'category', $category, true );
		foreach ( array( $of_user, $of_tag ) as $follower ) {
			$this->assertCount( 1, $this->items( $follower ), 'Recent events are copied on following.' );
		}
		$this->assertSame( array(), $this->items( $of_category ) );

		$new = $this->ask( $author, 'A new question to hear of', 'Text.', array( 'followed' ), $child );

		foreach ( array( $of_user, $of_tag, $of_category ) as $follower ) {
			$items = $this->items( $follower, content: false );
			$this->assertSame( $new->id, $items[0]->question_id );
			$this->assertFalse( $items[0]->personal );
			$this->assertSame( array(), $this->items( $follower, favorites: false ) );
		}
	}

	public function test_unfollowing_removes_the_events(): void {
		$question = $this->ask( $this->user() );
		$fan      = $this->user();
		Services::favorites()->set( $fan, 'question', $question->id, true );
		$this->answer( $this->user(), $this->post( $question->id ) );
		$this->assertNotSame( array(), $this->items( $fan ) );

		Services::favorites()->set( $fan, 'question', $question->id, false );
		$this->assertSame( array(), $this->items( $fan ) );
	}

	public function test_popular_entities_move_to_the_shared_stream(): void {
		update_option( Updates::THRESHOLD_OPTION, 2, false );
		$question = $this->ask( $this->user() );
		$fans     = array( $this->user(), $this->user(), $this->user() );
		foreach ( $fans as $fan ) {
			Services::favorites()->set( $fan, 'question', $question->id, true );
		}
		$this->assertSame( 3, $this->count_rows( 'userfavorites', "entity_type = 'Q' AND no_user_events = 1" ) );

		$before = $this->count_rows( 'userevents' );
		$answer = $this->answer( $this->user(), $this->post( $question->id ) );

		$this->assertSame( $before + 1, $this->count_rows( 'userevents' ), 'Only the author gets a copy.' );
		foreach ( $fans as $fan ) {
			$this->assertSame( $answer->id, $this->items( $fan )[0]->post_id );
		}
	}

	public function test_streams_keep_their_limits(): void {
		$author   = $this->user();
		$question = $this->ask( $author );
		$answer   = $this->answer( $this->user(), $question );
		$fan      = $this->user();
		Services::favorites()->set( $fan, 'question', $question->id, true );

		for ( $i = 0; $i < 8; $i++ ) {
			$this->comment( $this->user(), $this->post( $question->id ), $this->post( $answer->id ), "Comment number $i" );
		}

		$this->assertSame( 5, $this->count_rows( 'sharedevents', "entity_type = 'Q' AND entity_id = " . $question->id ) );
		$this->assertSame( 5, $this->count_rows( 'userevents', 'user_id = ' . (int) $fan->user_id ) );
	}

	public function test_hidden_posts_and_deleted_posts_leave_the_list(): void {
		$asker    = $this->user();
		$question = $this->ask( $asker );
		$answer   = $this->answer( $this->user(), $question );
		$fan      = $this->user();
		Services::favorites()->set( $fan, 'question', $question->id, true );

		Services::post_service()->set_answer_status( Actor::system(), $this->post( $answer->id ), $this->post( $question->id ), Post::STATUS_HIDDEN );
		$this->assertSame( $question->id, $this->items( $fan )[0]->post_id, 'Followers no longer see the hidden answer.' );

		Services::post_service()->delete_answer( Actor::system(), $this->post( $answer->id ), $this->post( $question->id ) );
		$this->assertSame( 0, $this->count_rows( 'userevents', 'last_post_id = ' . $answer->id ) );
		$this->assertSame( 0, $this->count_rows( 'sharedevents', 'last_post_id = ' . $answer->id ) );

		Services::post_service()->set_question_status( Actor::system(), $this->post( $question->id ), Post::STATUS_HIDDEN );
		Services::post_service()->delete_question( Actor::system(), $this->post( $question->id ) );
		$this->assertSame( 0, $this->count_rows( 'sharedevents', 'question_id = ' . $question->id ) );
	}

	public function test_the_page_shows_what_the_viewer_may_see_by_filter(): void {
		$asker    = $this->user();
		$question = $this->ask( $asker, 'My own question' );
		$this->answer( $this->user(), $question );
		$other = $this->ask( $this->user(), 'A followed question' );
		Services::favorites()->set( $asker, 'question', $other->id, true );
		wp_set_current_user( (int) $asker->user_id );

		$page = function ( string $show = '' ): string {
			$_GET['show'] = $show;
			Lists::reset();
			Block_Context::router()->set_current( new Route( 'updates' ) );
			$html = do_blocks( ( new Templates( Block_Context::router() ) )->definitions()['easyq-updates']['content'] );
			unset( $_GET['show'] );

			return $html;
		};

		$all = $page();
		$this->assertStringContainsString( 'My own question', $all );
		$this->assertStringContainsString( 'A followed question', $all );
		$this->assertStringContainsString( 'your question answered', $all );
		$this->assertStringNotContainsString( 'A followed question', $page( 'content' ) );
		$this->assertStringNotContainsString( 'My own question', $page( 'favorites' ) );
	}

	public function test_the_tool_refills_the_streams(): void {
		$asker    = $this->user();
		$helper   = $this->user();
		$question = $this->ask( $asker, 'Refilled question', 'Text.', array( 'refilled' ) );
		$answer   = $this->answer( $helper, $question );
		$first    = $this->comment( $this->user(), $this->post( $question->id ), $this->post( $answer->id ) );
		$this->comment( $helper, $this->post( $question->id ), $this->post( $answer->id ), 'A later comment here.' );
		$fan = $this->user();
		Services::favorites()->set( $fan, 'tag', 'refilled', true );
		$author_of_first = $this->post( $first->id )->user_id;

		$expected = array(
			$this->items( $asker )[0]->question_id,
			$this->items( $fan )[0]->question_id,
		);

		Services::updates()->truncate();
		$this->assertSame( array(), $this->items( $asker ) );

		$tools = new Tools();
		$step  = '';
		$after = 0;
		do {
			$result = $tools->run( Actor::system(), 'refill_events', $step, $after );
			$this->assertIsArray( $result );
			$step  = $result['step'];
			$after = $result['after'];
		} while ( ! $result['complete'] );

		$this->assertSame( $expected, array( $this->items( $asker )[0]->question_id, $this->items( $fan )[0]->question_id ) );
		$this->assertTrue( $this->items( $helper )[0]->personal || array() !== $this->items( $helper ) );
		$this->assertSame( 'your comment followed', Services::updates()->items( (int) $author_of_first )[0]->label() );
	}

	public function test_privacy_tools_export_and_erase_the_user(): void {
		$asker    = $this->user();
		$helper   = $this->user();
		$question = $this->ask( $asker, 'Question with personal events' );
		$this->answer( $helper, $question );
		$email = get_userdata( (int) $asker->user_id )->user_email;

		$export = Privacy::export_updates( $email );
		$this->assertStringContainsString( 'Question with personal events', (string) wp_json_encode( $export['data'] ) );

		$other = get_userdata( (int) $helper->user_id )->user_email;
		$this->assertTrue( Privacy::erase_updates( $other )['items_removed'] );
		$this->assertSame( 0, $this->count_rows( 'sharedevents', 'last_user_id = ' . (int) $helper->user_id ) );
		$this->assertSame( 0, $this->count_rows( 'userevents', 'last_user_id = ' . (int) $helper->user_id ) );
		$this->assertTrue( Privacy::erase_updates( $email )['items_removed'] );
		$this->assertSame( array(), Privacy::export_updates( $email )['data'] );
	}

	public function test_deleted_users_leave_the_streams(): void {
		$asker    = $this->user();
		$helper   = $this->user();
		$question = $this->ask( $asker );
		$this->answer( $helper, $question );

		do_action( 'easyq_event', 'u_delete', array( 'user_id' => (int) $helper->user_id ), Actor::system() );

		$this->assertSame( 0, $this->count_rows( 'sharedevents', 'last_user_id = ' . (int) $helper->user_id ) );
		$this->assertNotSame( array(), $this->items( $asker ) );
	}
}
