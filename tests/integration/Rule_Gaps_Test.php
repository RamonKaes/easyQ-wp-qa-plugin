<?php
/**
 * Tests for rules whose mutations stayed green on both suites in the cross-check.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Actor;
use EasyQ\Admin\Profile_Screen;
use EasyQ\Database\Schema;
use EasyQ\Limits\Limits;
use EasyQ\Posts\Post;
use EasyQ\Services;
use EasyQ\Settings\Options;
use EasyQ\Users\Blocked_Names;
use EasyQ\Users\Levels;
use EasyQ\Users\Permits;
use EasyQ\Users\User_Status;

/**
 * Each test holds one rule that the tests of its class pass over (F-231): they check the
 * common case, these the edge that decides.
 *
 * @covers \EasyQ\Limits\Limits
 * @covers \EasyQ\Users\Permissions
 * @covers \EasyQ\REST\Controller
 * @covers \EasyQ\REST\Posts_Controller
 * @covers \EasyQ\Users\Blocked_Names
 * @covers \EasyQ\Messages\Messages
 * @covers \EasyQ\Admin\Profile_Screen
 * @covers \EasyQ\Posts\Moderation_Query
 * @covers \EasyQ\Posts\Post_Repository
 * @covers \EasyQ\Privacy\IP_Retention
 */
final class Rule_Gaps_Test extends REST_Test_Case {

	/**
	 * Sets permissions as stored, past the sanitizer that holds the choices of the screen.
	 *
	 * @param array<string, int> $permits Permissions.
	 */
	private function permits( array $permits ): void {
		remove_all_filters( 'sanitize_option_' . Options::option_name( 'permissions' ) );
		Options::update( 'permissions', $permits );
	}

	public function test_flags_on_comments_count_toward_the_limit_of_flags(): void {
		Options::update( 'spam', array( 'max_rate_user_flags' => 1 ) );
		$question = $this->ask( $this->user() );
		$comment  = $this->comment( $this->user(), $question, $question, 'A comment to flag.' );
		$flagger  = $this->user();

		Services::votes()->flag( $flagger, $comment, $question );

		$this->assertSame( 0, Services::limits()->remaining( Limits::FLAGS, $flagger ) );
	}

	public function test_an_answer_turned_into_a_comment_counts_toward_the_limit_of_comments(): void {
		Options::update( 'spam', array( 'max_rate_user_cs' => 1 ) );
		$question = $this->ask( $this->user() );
		$author   = $this->user();
		$answer   = $this->answer( $author, $question, 'An answer that becomes a comment.' );

		Services::post_service()->answer_to_comment( $author, $answer, $question, $question->id, 'An answer that becomes a comment.', '', null );

		$this->assertSame( 0, Services::limits()->remaining( Limits::COMMENTS, $author ) );
	}

	public function test_a_page_has_at_most_100_items(): void {
		$this->assertSame( 400, $this->request( 'GET', '/questions', array( 'per_page' => 101 ) )->get_status() );
		$this->assertSame( 200, $this->request( 'GET', '/questions', array( 'per_page' => 100 ) )->get_status() );
	}

	public function test_hidden_posts_cannot_be_edited_flagged_unflagged_or_reopened(): void {
		Options::update( 'spam', array( 'flagging_of_posts' => true ) );
		$question = $this->ask( $this->user() );
		$answer   = $this->answer( $this->user(), $question );
		$flagger  = $this->user();
		Services::votes()->flag( $flagger, $answer, $question );
		Services::post_service()->close_with_note( Actor::system(), $question, 'Closed first.' );
		$admin = new Actor( $this->administrator() );

		Services::post_service()->set_answer_status( Actor::system(), $this->post( $answer->id ), $this->post( $question->id ), Post::STATUS_HIDDEN );
		Services::post_service()->set_question_status( Actor::system(), $this->post( $question->id ), Post::STATUS_HIDDEN );

		$answer_rules = Services::permissions()->post_rules( $this->post( $answer->id ), $flagger, $this->post( $question->id ) );
		$this->assertFalse( $answer_rules['unflaggable'], 'Unflag' );
		$this->assertFalse( Services::permissions()->post_rules( $this->post( $answer->id ), $admin, $this->post( $question->id ) )['edit_button'], 'Edit' );
		$this->assertFalse( Services::permissions()->post_rules( $this->post( $answer->id ), new Actor( (int) $this->user()->user_id ), $this->post( $question->id ) )['flag_button'], 'Flag' );
		$this->assertFalse( Services::permissions()->post_rules( $this->post( $question->id ), $admin )['reopenable'], 'Reopen' );
	}

	public function test_the_check_of_blocked_names_passes_on_what_is_no_list_of_errors(): void {
		$names = new Blocked_Names();

		$this->assertNull( $names->check_registration( null, 'anonymous' ) );
		$this->assertSame( 'other', $names->check_registration( 'other', 'anonymous' ) );
	}

	public function test_approved_users_and_above_need_no_confirmed_address(): void {
		$this->permits( array( 'permit_post_q' => Permits::CONFIRMED ) );
		Options::update( 'spam', array( 'confirm_user_emails' => true ) );
		$basic  = $this->user();
		$expert = $this->user();
		foreach ( array( $basic, $expert ) as $user ) {
			update_user_meta( (int) $user->user_id, User_Status::UNCONFIRMED_META, 1 );
		}
		Services::user_levels()->set_level( Actor::system(), (int) $expert->user_id, Levels::EXPERT );

		$this->assertSame( 'confirm', Services::permissions()->error( 'permit_post_q', $basic ) );
		$this->assertNull( Services::permissions()->error( 'permit_post_q', $expert ) );
	}

	public function test_deleting_the_wall_posts_of_others_needs_both_permissions(): void {
		$this->permits(
			array(
				'permit_hide_show'     => Permits::MODERATORS,
				'permit_delete_hidden' => Permits::ADMINS,
			)
		);
		Options::update( 'users', array( 'allow_user_walls' => true ) );
		$owner = get_userdata( $this->take_part( (int) $this->user()->user_id ) );
		$this->assertInstanceOf( \WP_User::class, $owner );
		$id = Services::messages()->post_on_wall( $this->user(), $owner, 'A post on the wall.' );
		$this->assertIsInt( $id );
		$post = Services::messages()->find( $id );
		$this->assertNotNull( $post );

		// A moderator who may hide but not delete hidden posts, as Question2Answer asks for both.
		$this->assertFalse( Services::messages()->can_delete_wall_post( new Actor( (int) $this->user( 'editor' )->user_id ), $post ) );
		$this->assertTrue( Services::messages()->can_delete_wall_post( new Actor( $this->administrator() ), $post ) );
	}

	public function test_managers_set_the_levels_of_users_of_their_own_level(): void {
		$manager = self::factory()->user->create();
		$other   = self::factory()->user->create();
		foreach ( array( $manager, $other ) as $user ) {
			Services::user_levels()->set_level( Actor::system(), $user, Levels::ADMIN );
		}
		$can = new \ReflectionMethod( Profile_Screen::class, 'can_set_levels' );

		$this->assertTrue( $can->invoke( new Profile_Screen(), new Actor( $manager ), $other ) );
	}

	public function test_an_author_shows_a_post_they_hid_again_only_without_flags(): void {
		Options::update( 'spam', array( 'flagging_of_posts' => true ) );
		$question = $this->ask( $this->user() );
		$author   = $this->user();
		$answer   = $this->answer( $author, $question );

		Services::post_service()->set_answer_status( $author, $answer, $question, Post::STATUS_HIDDEN );
		$this->assertTrue( Services::permissions()->post_rules( $this->post( $answer->id ), $author, $question )['reshowable'] );

		// With a flag, as Question2Answer leaves flagged posts to the moderators.
		$GLOBALS['wpdb']->update( Schema::table( 'posts' ), array( 'flag_count' => 1 ), array( 'id' => $answer->id ) );
		Services::posts()->forget( $answer->id );
		$this->assertFalse( Services::permissions()->post_rules( $this->post( $answer->id ), $author, $question )['reshowable'] );
	}

	public function test_only_an_answer_of_the_question_can_be_selected(): void {
		$asker    = $this->user();
		$question = $this->ask( $asker );
		$other    = $this->answer( $this->user(), $this->ask( $this->user(), 'Another question here' ) );
		wp_set_current_user( (int) $asker->user_id );

		$this->assertGreaterThanOrEqual( 400, $this->request( 'POST', '/posts/' . $question->id . '/actions/select', array( 'answer' => $other->id ) )->get_status() );
		$this->assertNull( $this->post( $question->id )->selected_child_id );
	}

	public function test_the_search_of_the_posts_screen_takes_wildcards_literally(): void {
		$this->ask( $this->user(), 'A question without any special character' );

		$found = Services::moderation_query()->find(
			new Actor( $this->administrator() ),
			array(
				'view'   => 'all',
				'search' => '%',
			)
		);

		$this->assertSame( 0, $found['total'] );
	}

	public function test_the_address_of_the_last_change_goes_with_the_age_of_a_post_never_changed(): void {
		global $wpdb;
		$question = $this->ask( new Actor( (int) $this->user()->user_id, null, '192.0.2.5' ) );
		$wpdb->update(
			Schema::table( 'posts' ),
			array(
				'created' => '2020-01-01 00:00:00',
				'updated' => null,
				'last_ip' => \EasyQ\Util\IP::to_binary( '192.0.2.5' ),
			),
			array( 'id' => $question->id )
		);

		Services::posts()->clear_ips_before( '2021-01-01 00:00:00' );

		$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SELECT last_ip FROM %i WHERE id = %d', Schema::table( 'posts' ), $question->id ) ) );
	}

	public function test_the_address_a_cookie_last_wrote_from_goes_with_its_age_if_it_never_wrote(): void {
		global $wpdb;
		$wpdb->insert(
			Schema::table( 'cookies' ),
			array(
				'id'         => 4242,
				'created'    => '2020-01-01 00:00:00',
				'written'    => null,
				'written_ip' => \EasyQ\Util\IP::to_binary( '192.0.2.6' ),
			)
		);

		\EasyQ\Privacy\IP_Retention::run();

		$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SELECT written_ip FROM %i WHERE id = %d', Schema::table( 'cookies' ), 4242 ) ) );
	}
}
