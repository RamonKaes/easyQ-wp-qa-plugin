<?php
/**
 * Tests for creating and changing posts.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Actor;
use EasyQ\Events\Activity_Tracker;
use EasyQ\Posts\Post;
use EasyQ\Posts\Post_Service;
use EasyQ\Services;
use EasyQ\Settings\Options;
use EasyQ\Stats\Counts;
use WP_Error;

/**
 * @covers \EasyQ\Posts\Post_Service
 * @covers \EasyQ\Posts\Post_Repository
 */
final class Post_Service_Test extends Test_Case {

	public function test_creates_a_visible_question(): void {
		$author   = $this->user();
		$category = Services::categories()->create( null, 'Software' );
		$this->assertIsInt( $category );

		$question = $this->ask( $author, 'Widget settings missing', 'Where are the widget settings?', array( 'widgets', 'settings' ), $category );

		$this->assertSame( Post::QUESTION, $question->type );
		$this->assertSame( 'Widget settings missing', $question->title );
		$this->assertSame( 'widgets,settings', $question->tags );
		$this->assertSame( $author->user_id, $question->user_id );
		$this->assertSame( $category, $question->category_path[0] );
		$this->assertNotNull( $question->hotness );
		$this->assertSame( '192.0.2.1', \EasyQ\Util\IP::from_binary( $question->created_ip ) );

		$this->assertSame( array( $question->id ), $this->search_ids( 'widget settings' ) );
		$this->assertSame( 1, Counts::get( Counts::QUESTIONS ) );
		$this->assertSame( 1, Counts::get( Counts::UNANSWERED ) );
		$this->assertSame( 1, Services::categories()->find( $category )?->question_count );
		$this->assertSame( 120, Services::points()->get( (int) $author->user_id ) );

		$params = $this->event_params( 'q_post' );
		$this->assertSame( $question->id, $params['post_id'] );
		$this->assertSame( 'widgets,settings', $params['tags'] );
		$this->assertNotEmpty( get_user_meta( (int) $author->user_id, Activity_Tracker::LAST_WRITE_META, true ) );
	}

	public function test_queued_question_is_not_public(): void {
		$author   = $this->user();
		$question = $this->ask( $author, 'Queued question title', 'Some content here.', array(), null, true );

		$this->assertSame( 'Q_QUEUED', $question->type );
		$this->assertSame( array(), $this->search_ids( 'queued' ) );
		$this->assertSame( 0, Counts::get( Counts::QUESTIONS ) );
		$this->assertSame( 1, Counts::get( Counts::QUEUED ) );
		$this->assertSame( 100, Services::points()->get( (int) $author->user_id ) );
		$this->assertContains( 'q_queue', $this->event_names() );
		$this->assertNotContains( 'q_post', $this->event_names() );
	}

	public function test_answer_updates_question_and_counts(): void {
		$question = $this->ask( $this->user() );
		$author   = $this->user();
		$before   = (float) $question->hotness;

		$answer = $this->answer( $author, $question, 'Use the configuration panel.' );

		$question = $this->post( $question->id );
		$this->assertSame( Post::ANSWER, $answer->type );
		$this->assertSame( $question->id, $answer->parent_id );
		$this->assertSame( 1, $question->answer_count );
		$this->assertGreaterThanOrEqual( $before, (float) $question->hotness );
		$this->assertSame( 1, Counts::get( Counts::ANSWERS ) );
		$this->assertSame( 0, Counts::get( Counts::UNANSWERED ) );
		$this->assertSame( 140, Services::points()->get( (int) $author->user_id ) );

		$results = Services::search()->search( 'configuration panel', 0, 10 );
		$this->assertCount( 1, $results );
		$this->assertSame( 'A', $results[0]->match_type );
		$this->assertSame( $answer->id, $results[0]->match_post_id );
	}

	public function test_answers_of_hidden_questions_are_not_indexed(): void {
		$question = $this->ask( $this->user() );
		Services::post_service()->set_question_status( Actor::system(), $question, Post::STATUS_HIDDEN );

		$this->answer( $this->user(), $this->post( $question->id ), 'Unique zebra words.' );

		$this->assertSame( array(), $this->search_ids( 'zebra' ) );
	}

	public function test_comment_reports_its_thread(): void {
		$question = $this->ask( $this->user() );
		$answer   = $this->answer( $this->user(), $question );
		$first    = $this->comment( $this->user(), $question, $answer, 'First remark here.' );
		$second   = $this->comment( $this->user(), $question, $answer, 'Second remark here.' );

		$this->assertSame( $answer->id, $second->parent_id );
		$this->assertSame( array( $first->id ), $this->event_params( 'c_post' )['thread'] );
		$this->assertSame( 2, Counts::get( Counts::COMMENTS ) );
		$this->assertContains( $question->id, $this->search_ids( 'remark' ) );
	}

	public function test_edit_records_the_editor_and_reindexes(): void {
		$question = $this->ask( $this->user(), 'Original question title', 'Content.', array( 'oldtag' ) );
		$editor   = $this->user( 'editor' );

		Services::post_service()->edit_question( $editor, $question, 'Changed question title', 'Content.', '', array( 'newtag' ), null );

		$edited = $this->post( $question->id );
		$this->assertSame( 'Changed question title', $edited->title );
		$this->assertSame( Post::UPDATE_CONTENT, $edited->update_type );
		$this->assertSame( $editor->user_id, $edited->last_user_id );
		$this->assertNotNull( $edited->updated );
		$this->assertSame( array( $question->id ), $this->search_ids( 'newtag' ) );
		$this->assertSame( array(), $this->search_ids( 'oldtag' ) );
		$this->assertTrue( $this->event_params( 'q_edit' )['title_changed'] );
	}

	public function test_changing_only_tags_records_a_tag_edit(): void {
		$question = $this->ask( $this->user(), 'Original question title', 'Content.', array( 'a' ) );

		Services::post_service()->edit_question( $this->user(), $question, (string) $question->title, 'Content.', '', array( 'b' ), null );

		$this->assertSame( Post::UPDATE_TAGS, $this->post( $question->id )->update_type );
	}

	public function test_silent_edit_is_not_recorded(): void {
		$question = $this->ask( $this->user() );

		Services::post_service()->edit_question( $this->user( 'editor' ), $question, 'Silently changed title', 'x', '', array(), null, null, silent: true );

		$edited = $this->post( $question->id );
		$this->assertSame( 'Silently changed title', $edited->title );
		$this->assertNull( $edited->updated );
		$this->assertTrue( $this->event_params( 'q_edit' )['silent'] );
	}

	public function test_edit_with_remoderation_queues_the_question(): void {
		$author   = $this->user();
		$question = $this->ask( $author, 'Visible question title', 'Text.' );
		$this->answer( $this->user(), $question, 'Answer with kangaroo.' );

		Services::post_service()->edit_question( $author, $this->post( $question->id ), 'Edited visible question', 'Text.', '', array(), null, null, remoderate: true );

		$this->assertSame( 'Q_QUEUED', $this->post( $question->id )->type );
		$this->assertSame( array(), $this->search_ids( 'kangaroo' ) );
		$this->assertSame( 1, Counts::get( Counts::QUEUED ) );
		$this->assertContains( 'q_requeue', $this->event_names() );
	}

	public function test_hiding_and_reshowing_a_question(): void {
		$question = $this->ask( $this->user(), 'Hidden giraffe question', 'Text.' );
		$answer   = $this->answer( $this->user(), $question, 'Answer about elephants.' );
		$editor   = $this->user( 'editor' );

		Services::post_service()->set_question_status( $editor, $question, Post::STATUS_HIDDEN );

		$hidden = $this->post( $question->id );
		$this->assertSame( 'Q_HIDDEN', $hidden->type );
		$this->assertSame( Post::UPDATE_VISIBLE, $hidden->update_type );
		$this->assertSame( array(), $this->search_ids( 'giraffe' ) );
		$this->assertSame( array(), $this->search_ids( 'elephants' ) );
		$this->assertSame( 0, Counts::get( Counts::QUESTIONS ) );
		$this->assertContains( 'q_hide', $this->event_names() );

		Services::post_service()->set_question_status( $editor, $hidden, Post::STATUS_NORMAL );

		$this->assertSame( Post::QUESTION, $this->post( $question->id )->type );
		$this->assertSame( array( $question->id ), $this->search_ids( 'giraffe' ) );
		$this->assertSame( array( $question->id ), $this->search_ids( 'elephants' ) );
		$this->assertSame( 1, Counts::get( Counts::QUESTIONS ) );
		$this->assertContains( 'q_reshow', $this->event_names() );
		$this->assertSame( 'A', $this->post( $answer->id )->type );
	}

	public function test_approving_a_queued_question_reports_it_as_posted(): void {
		$author   = $this->user();
		$question = $this->ask( $author, 'Waiting question title', 'Text.', array(), null, true );

		Services::post_service()->set_question_status( $this->user( 'editor' ), $question, Post::STATUS_NORMAL );

		$this->assertContains( 'q_approve', $this->event_names() );
		$params = $this->event_params( 'q_post' );
		$this->assertSame( $question->created, $params['delayed'] );
		$this->assertSame( $author->user_id, end( $this->events )['actor']->user_id );
		$this->assertSame( 120, Services::points()->get( (int) $author->user_id ) );
		$this->assertSame( 0, Counts::get( Counts::QUEUED ) );
	}

	public function test_rejecting_a_queued_answer(): void {
		$question = $this->ask( $this->user() );
		$answer   = $this->answer( $this->user(), $question, 'Queued answer text.', true );

		Services::post_service()->set_answer_status( $this->user( 'editor' ), $answer, $question, Post::STATUS_HIDDEN );

		$this->assertSame( 'A_HIDDEN', $this->post( $answer->id )->type );
		$this->assertContains( 'a_reject', $this->event_names() );
		$this->assertSame( 0, $this->post( $question->id )->answer_count );
	}

	public function test_hiding_the_selected_answer_clears_the_selection(): void {
		$asker    = $this->user();
		$question = $this->ask( $asker );
		$answer   = $this->answer( $this->user(), $question );
		Services::post_service()->select_answer( $asker, $question, $answer->id );

		Services::post_service()->set_answer_status( $this->user( 'editor' ), $answer, $this->post( $question->id ), Post::STATUS_HIDDEN );

		$question = $this->post( $question->id );
		$this->assertNull( $question->selected_child_id );
		$this->assertSame( 0, $question->answer_count );
		$this->assertContains( 'a_unselect', $this->event_names() );
	}

	public function test_hiding_a_comment(): void {
		$question = $this->ask( $this->user() );
		$comment  = $this->comment( $this->user(), $question, $question, 'Comment about walrus.' );

		Services::post_service()->set_comment_status( $this->user( 'editor' ), $comment, $question, $question, Post::STATUS_HIDDEN );

		$this->assertSame( 'C_HIDDEN', $this->post( $comment->id )->type );
		$this->assertSame( array(), $this->search_ids( 'walrus' ) );
		$this->assertSame( 0, Counts::get( Counts::COMMENTS ) );
	}

	public function test_selecting_an_answer_awards_points(): void {
		$asker    = $this->user();
		$answerer = $this->user();
		$question = $this->ask( $asker );
		$answer   = $this->answer( $answerer, $question );

		Services::post_service()->select_answer( $asker, $question, $answer->id );

		$this->assertSame( $answer->id, $this->post( $question->id )->selected_child_id );
		$this->assertSame( Post::UPDATE_SELECTED, $this->post( $answer->id )->update_type );
		$this->assertSame( 100 + 20 + 30, Services::points()->get( (int) $asker->user_id ) );
		$this->assertSame( 100 + 40 + 300, Services::points()->get( (int) $answerer->user_id ) );
		$this->assertSame( 0, Counts::get( Counts::UNSELECTED ) );
	}

	public function test_selecting_another_answer_moves_the_selection(): void {
		$asker    = $this->user();
		$question = $this->ask( $asker );
		$first    = $this->answer( $this->user(), $question );
		$second   = $this->answer( $this->user(), $question );

		Services::post_service()->select_answer( $asker, $question, $first->id );
		Services::post_service()->select_answer( $asker, $this->post( $question->id ), $second->id );

		$this->assertSame( $second->id, $this->post( $question->id )->selected_child_id );
		$this->assertNull( $this->post( $first->id )->update_type );
		$this->assertSame( 100 + 40, Services::points()->get( (int) $first->user_id ) );
	}

	public function test_selecting_closes_the_question_when_configured(): void {
		Options::update( 'posting', array( 'do_close_on_select' => true ) );
		$asker    = $this->user();
		$question = $this->ask( $asker );
		$answer   = $this->answer( $this->user(), $question );

		Services::post_service()->select_answer( $asker, $question, $answer->id );

		$this->assertTrue( Post_Service::is_closed( $this->post( $question->id ) ) );
		$this->assertSame( 'answer-selected', $this->event_params( 'q_close' )['reason'] );
	}

	public function test_closing_with_a_note_and_reopening(): void {
		$question = $this->ask( $this->user() );
		$editor   = $this->user( 'editor' );

		Services::post_service()->close_with_note( $editor, $question, 'Off topic for this site.' );

		$closed = $this->post( $question->id );
		$this->assertTrue( $closed->is_closed() );
		$note = $this->post( (int) $closed->closed_by_id );
		$this->assertSame( Post::NOTE, $note->type );
		$this->assertSame( 'Off topic for this site.', $note->content );
		$this->assertSame( 0, Counts::get( Counts::UNANSWERED ) );

		Services::post_service()->reopen( $editor, $closed );

		$this->assertFalse( $this->post( $question->id )->is_closed() );
		$this->assertNull( Services::posts()->find( $note->id ) );
		$this->assertSame( 1, Counts::get( Counts::UNANSWERED ) );
		$this->assertContains( 'q_reopen', $this->event_names() );
	}

	public function test_closing_as_duplicate(): void {
		$original  = $this->ask( $this->user(), 'Original question title' );
		$duplicate = $this->ask( $this->user(), 'Duplicate question title' );

		Services::post_service()->close_as_duplicate( $this->user( 'editor' ), $duplicate, $original->id );

		$this->assertSame( $original->id, $this->post( $duplicate->id )->closed_by_id );
		$this->assertSame( 'duplicate', $this->event_params( 'q_close' )['reason'] );
	}

	public function test_moving_a_question_moves_its_posts(): void {
		$categories = Services::categories();
		$old        = $categories->create( null, 'Old category' );
		$parent_id  = $categories->create( null, 'New parent' );
		$this->assertIsInt( $old );
		$this->assertIsInt( $parent_id );
		$new = $categories->create( $parent_id, 'New child' );
		$this->assertIsInt( $new );

		$question = $this->ask( $this->user(), 'Question to be moved', 'Text.', array(), $old );
		$answer   = $this->answer( $this->user(), $question );
		$comment  = $this->comment( $this->user(), $question, $answer );

		Services::post_service()->set_category( $this->user( 'editor' ), $question, $new );

		$this->assertSame( $new, $this->post( $question->id )->category_id );
		$this->assertSame( array( $parent_id, $new, null ), $this->post( $answer->id )->category_path );
		$this->assertSame( $new, $this->post( $comment->id )->category_id );
		$this->assertSame( 0, $categories->find( $old )?->question_count );
		$this->assertSame( 1, $categories->find( $new )?->question_count );
		$this->assertSame( 1, $categories->find( $parent_id )?->question_count );
		$this->assertSame( $old, $this->event_params( 'q_move' )['old_category_id'] );
	}

	public function test_converting_an_answer_to_a_comment(): void {
		$question = $this->ask( $this->user() );
		$target   = $this->answer( $this->user(), $question, 'The better answer.' );
		$answer   = $this->answer( $this->user(), $question, 'Should be a comment.' );
		$child    = $this->comment( $this->user(), $question, $answer, 'Reply to the answer.' );

		Services::post_service()->answer_to_comment( $this->user( 'editor' ), $answer, $question, $target->id, 'Should be a comment.', '', null );

		$converted = $this->post( $answer->id );
		$this->assertSame( Post::COMMENT, $converted->type );
		$this->assertSame( $target->id, $converted->parent_id );
		$this->assertSame( $target->id, $this->post( $child->id )->parent_id );
		$this->assertSame( 1, $this->post( $question->id )->answer_count );
		$this->assertContains( 'a_to_c', $this->event_names() );
	}

	public function test_deleting_requires_a_hidden_post_without_dependents(): void {
		$question = $this->ask( $this->user() );
		$this->answer( $this->user(), $question );

		$this->assertInstanceOf( WP_Error::class, Services::post_service()->delete_question( Actor::system(), $question ) );

		Services::post_service()->set_question_status( Actor::system(), $question, Post::STATUS_HIDDEN );
		$result = Services::post_service()->delete_question( Actor::system(), $this->post( $question->id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'has_dependents', $result->get_error_code() );
	}

	public function test_deleting_a_question_removes_votes_meta_and_close_note(): void {
		global $wpdb;

		$author   = $this->user();
		$voter    = $this->user();
		$question = $this->ask( $author );
		Services::votes()->vote( $voter, $question, 1 );
		update_metadata( 'easyq_post', $question->id, 'custom', 'value' );
		Services::post_service()->close_with_note( Actor::system(), $this->post( $question->id ), 'Closed.' );
		$note_id = (int) $this->post( $question->id )->closed_by_id;
		Services::post_service()->set_question_status( Actor::system(), $this->post( $question->id ), Post::STATUS_HIDDEN );

		$result = Services::post_service()->delete_question( Actor::system(), $this->post( $question->id ) );

		$this->assertTrue( $result );
		$this->assertNull( Services::posts()->find( $question->id ) );
		$this->assertNull( Services::posts()->find( $note_id ) );
		$this->assertSame( '', get_metadata( 'easyq_post', $question->id, 'custom', true ) );
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE post_id = %d', $wpdb->prefix . 'easyq_uservotes', $question->id ) ) );
		$this->assertSame( 100, Services::points()->get( (int) $author->user_id ) );
		$this->assertSame( 100, Services::points()->get( (int) $voter->user_id ) );
		$this->assertSame( array( 'q_delete_before', 'q_delete' ), array_values( array_intersect( $this->event_names(), array( 'q_delete_before', 'q_delete' ) ) ) );
	}

	public function test_deleting_the_selected_answer(): void {
		$asker    = $this->user();
		$question = $this->ask( $asker );
		$answer   = $this->answer( $this->user(), $question );
		Services::post_service()->select_answer( $asker, $question, $answer->id );
		Services::post_service()->set_answer_status( Actor::system(), $answer, $this->post( $question->id ), Post::STATUS_HIDDEN );

		$this->assertTrue( Services::post_service()->delete_answer( Actor::system(), $this->post( $answer->id ), $this->post( $question->id ) ) );

		$this->assertNull( Services::posts()->find( $answer->id ) );
		$this->assertNull( $this->post( $question->id )->selected_child_id );
		$this->assertSame( 120, Services::points()->get( (int) $asker->user_id ) );
	}

	public function test_deleting_a_comment(): void {
		$author   = $this->user();
		$question = $this->ask( $this->user() );
		$comment  = $this->comment( $author, $question, $question );
		Services::post_service()->set_comment_status( Actor::system(), $comment, $question, $question, Post::STATUS_HIDDEN );

		$this->assertTrue( Services::post_service()->delete_comment( Actor::system(), $this->post( $comment->id ), $question, $question ) );
		$this->assertNull( Services::posts()->find( $comment->id ) );
		$this->assertContains( 'c_delete', $this->event_names() );
	}

	public function test_claiming_an_anonymous_post_removes_own_votes(): void {
		$question = $this->post( Services::post_service()->create_question( new Actor( null, 4711, '192.0.2.9' ), 'Anonymous question title', 'Text.', '', array(), null, null, 'Guest' ) );
		$user     = $this->user();
		Services::votes()->vote( $user, $question, 1 );

		Services::post_service()->claim( $user, $this->post( $question->id ) );

		$claimed = $this->post( $question->id );
		$this->assertSame( $user->user_id, $claimed->user_id );
		$this->assertSame( 0, $claimed->upvotes );
		$this->assertSame( 120, Services::points()->get( (int) $user->user_id ) );
		$this->assertContains( 'q_claim', $this->event_names() );
	}

	public function test_counts_views(): void {
		$question = $this->ask( $this->user() );

		Services::post_service()->record_view( $question );
		Services::post_service()->record_view( $question );

		$this->assertSame( 2, $this->post( $question->id )->views );
	}

	public function test_notify_value(): void {
		$this->assertNull( Post_Service::notify_value( new Actor( 1 ), notify: false, email: 'a@example.com' ) );
		$this->assertSame( '@', Post_Service::notify_value( new Actor( 1 ), notify: true, email: null ) );
		$this->assertSame( 'a@example.com', Post_Service::notify_value( new Actor(), notify: true, email: 'a@example.com' ) );
		$this->assertNull( Post_Service::notify_value( new Actor(), notify: true, email: '' ) );
	}

	public function test_finds_the_question_of_any_post(): void {
		$question = $this->ask( $this->user() );
		$answer   = $this->answer( $this->user(), $question );
		$comment  = $this->comment( $this->user(), $question, $answer );

		$service = Services::post_service();
		$this->assertSame( $question->id, $service->question_of( $question )?->id );
		$this->assertSame( $question->id, $service->question_of( $answer )?->id );
		$this->assertSame( $question->id, $service->question_of( $comment )?->id );
	}

	public function test_primed_children_match_the_children_loaded_one_by_one(): void {
		$author   = $this->user();
		$question = $this->ask( $author );
		$answer   = $this->answer( $author, $question );
		$comment  = $this->comment( $author, $question, $answer );
		$this->comment( $author, $question, $question );

		$posts = Services::posts();
		$posts->prime_children( $question->id );
		$primed = array(
			$posts->children( $question->id ),
			$posts->children( $answer->id ),
			$posts->children( $comment->id ),
		);
		$posts->forget( $question->id );

		$this->assertSame( array( $comment->id ), array_keys( $primed[1] ) );
		$this->assertSame( array(), $primed[2] );
		$this->assertEquals( array( $posts->children( $question->id ), $posts->children( $answer->id ), $posts->children( $comment->id ) ), $primed );
	}
}
