<?php
/**
 * Tests for the details of the personal data tools that no other test holds.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Actor;
use EasyQ\Database\Schema;
use EasyQ\Limits\Limits;
use EasyQ\Notices\Notices;
use EasyQ\Posts\Post;
use EasyQ\Privacy\Post_Data;
use EasyQ\Privacy\Privacy;
use EasyQ\Privacy\Profile_Data;
use EasyQ\Services;
use EasyQ\Settings\Options;
use EasyQ\Users\Site_Meta;

/**
 * @covers \EasyQ\Privacy\Privacy
 * @covers \EasyQ\Privacy\Profile_Data
 * @covers \EasyQ\Privacy\Post_Data
 */
final class Privacy_Details_Test extends Test_Case {

	/**
	 * Returns the email address of a user.
	 *
	 * @param Actor $user User.
	 * @return string
	 */
	private function email( Actor $user ): string {
		return (string) get_userdata( (int) $user->user_id )->user_email;
	}

	public function test_erasing_the_notices_removes_the_welcome_notice(): void {
		$user = $this->user();
		Site_Meta::update( (int) $user->user_id, Notices::WELCOME_META, 1 );

		Privacy::erase_notices( $this->email( $user ) );

		$this->assertSame( '', Site_Meta::get( (int) $user->user_id, Notices::WELCOME_META ) );
	}

	public function test_erasing_the_profile_removes_the_counts_of_the_limits_of_this_user_only(): void {
		global $wpdb;
		$user  = $this->user();
		$other = $this->user();
		Services::limits()->increment( Limits::QUESTIONS, $user );
		Services::limits()->increment( Limits::QUESTIONS, $other );
		$rows = static fn ( Actor $actor ): int => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d', Schema::table( 'userlimits' ), $actor->user_id ) );
		$this->assertSame( 1, $rows( $user ) );

		Profile_Data::erase( $this->email( $user ) );

		$this->assertSame( 0, $rows( $user ) );
		$this->assertSame( 1, $rows( $other ) );
	}

	public function test_the_export_of_updates_leaves_out_hidden_questions(): void {
		$asker    = $this->user();
		$question = $this->ask( $asker, 'A question that will be hidden' );
		$this->answer( $this->user(), $question );
		$this->assertStringContainsString( 'A question that will be hidden', (string) wp_json_encode( Privacy::export_updates( $this->email( $asker ) )['data'] ) );

		Services::post_service()->set_question_status( Actor::system(), $this->post( $question->id ), Post::STATUS_HIDDEN );

		$this->assertStringNotContainsString( 'A question that will be hidden', (string) wp_json_encode( Privacy::export_updates( $this->email( $asker ) )['data'] ) );
	}

	public function test_erasing_posts_keeps_the_closing_notes_of_the_user_with_their_question(): void {
		Options::update( 'general', array( 'erase_posts' => 'delete' ) );
		$moderator = $this->user( 'editor' );
		$question  = $this->ask( $this->user() );
		Services::post_service()->close_with_note( $moderator, $question, 'Closed as it is off topic.' );
		$note_id = (int) $this->post( $question->id )->closed_by_id;
		$this->assertGreaterThan( 0, $note_id );

		Post_Data::erase_posts( $this->email( $moderator ) );

		// The note is part of the question: anonymized, not deleted, and the question stays closed.
		Services::posts()->forget( $note_id );
		$note = Services::posts()->find( $note_id );
		$this->assertNotNull( $note );
		$this->assertNull( $note->user_id );
		$this->assertTrue( $this->post( $question->id )->is_closed() );
	}

	public function test_the_export_of_the_profile_has_the_levels_the_status_and_the_settings(): void {
		$user     = $this->user();
		$id       = (int) $user->user_id;
		$category = Services::categories()->create( null, 'Gardening' );
		$this->assertIsInt( $category );
		// Blocked first: users with a level cannot be blocked, as in Question2Answer.
		\EasyQ\Users\User_Status::block( Actor::system(), $id );
		Services::user_levels()->set_level( Actor::system(), $id, \EasyQ\Users\Levels::EXPERT );
		Services::user_levels()->set_category_level( Actor::system(), $id, $category, \EasyQ\Users\Levels::MODERATOR );
		Site_Meta::update( $id, \EasyQ\Messages\Messages::NO_WALL_META, 1 );

		$text = (string) wp_json_encode( Profile_Data::export( $this->email( $user ) )['data'] );

		$this->assertStringContainsString( 'Q&A level', $text );
		$this->assertStringContainsString( 'Q&A level in Gardening', $text );
		$this->assertStringContainsString( 'Blocked', $text );
		$this->assertStringContainsString( 'No posts on your wall', $text );
	}
}
