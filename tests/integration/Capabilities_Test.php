<?php
/**
 * Tests for the capability mapping.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Actor;
use EasyQ\Capabilities;
use EasyQ\Posts\Post;
use EasyQ\Services;
use EasyQ\Users\Levels;
use EasyQ\Users\Site_Meta;
use EasyQ\Users\User_Status;

/**
 * @covers \EasyQ\Capabilities
 */
final class Capabilities_Test extends Test_Case {

	/**
	 * @dataProvider provide_role_capabilities
	 *
	 * @param string $role     Role of the user.
	 * @param bool   $manage   Expected result for easyq_manage.
	 * @param bool   $moderate Expected result for easyq_moderate.
	 */
	public function test_maps_admin_capabilities_by_role( string $role, bool $manage, bool $moderate ): void {
		$user_id = self::factory()->user->create( array( 'role' => $role ) );

		$this->assertSame( $manage, user_can( $user_id, Capabilities::MANAGE ) );
		$this->assertSame( $moderate, user_can( $user_id, Capabilities::MODERATE ) );
	}

	/**
	 * Provides the expected capabilities per core role.
	 *
	 * @return array<string, array{string, bool, bool}>
	 */
	public static function provide_role_capabilities(): array {
		return array(
			'administrator' => array( 'administrator', true, true ),
			'editor'        => array( 'editor', false, true ),
			'author'        => array( 'author', false, false ),
			'contributor'   => array( 'contributor', false, false ),
			'subscriber'    => array( 'subscriber', false, false ),
		);
	}

	public function test_assigned_levels_grant_admin_capabilities(): void {
		$user = $this->user();
		Services::user_levels()->set_level( Actor::system(), (int) $user->user_id, Levels::ADMIN );

		$this->assertTrue( user_can( (int) $user->user_id, Capabilities::MANAGE ) );
	}

	public function test_category_experts_may_moderate(): void {
		$user     = $this->user();
		$category = Services::categories()->create( null, 'Topic' );
		$this->assertIsInt( $category );
		Services::user_levels()->set_category_level( Actor::system(), (int) $user->user_id, $category, Levels::EXPERT );

		$this->assertTrue( user_can( (int) $user->user_id, Capabilities::MODERATE ) );
	}

	public function test_visitors_may_view_but_not_post(): void {
		$this->assertTrue( current_user_can( 'easyq_view_questions' ) );
		$this->assertFalse( current_user_can( 'easyq_post_question' ) );
		$this->assertFalse( current_user_can( Capabilities::MANAGE ) );
		$this->assertFalse( current_user_can( Capabilities::MODERATE ) );
	}

	public function test_post_capabilities_follow_the_post_rules(): void {
		$author   = $this->user();
		$other    = $this->user();
		$editor   = $this->user( 'editor' );
		$question = $this->ask( $author );

		$this->assertTrue( user_can( (int) $author->user_id, 'easyq_edit_post', $question->id ) );
		$this->assertFalse( user_can( (int) $other->user_id, 'easyq_edit_post', $question->id ) );
		$this->assertTrue( user_can( (int) $editor->user_id, 'easyq_edit_post', $question->id ) );

		$this->assertTrue( user_can( (int) $other->user_id, 'easyq_post_answer', $question->id ) );
		$this->assertTrue( user_can( (int) $author->user_id, 'easyq_select_answer', $question->id ) );
		$this->assertFalse( user_can( (int) $other->user_id, 'easyq_select_answer', $question->id ) );
		$this->assertTrue( user_can( (int) $author->user_id, 'easyq_close_question', $question->id ) );
		$this->assertTrue( user_can( (int) $other->user_id, 'easyq_flag_post', $question->id ) );
		$this->assertFalse( user_can( (int) $author->user_id, 'easyq_flag_post', $question->id ) );
		$this->assertFalse( user_can( (int) $editor->user_id, 'easyq_delete_post', $question->id ) );
	}

	public function test_votes_need_another_author_and_permission(): void {
		$author   = $this->user();
		$voter    = $this->user();
		$question = $this->ask( $author );

		$this->assertTrue( user_can( (int) $voter->user_id, 'easyq_vote_post', $question->id ) );
		$this->assertTrue( user_can( (int) $voter->user_id, 'easyq_vote_down_post', $question->id ) );
		$this->assertFalse( user_can( (int) $author->user_id, 'easyq_vote_post', $question->id ) );
		$this->assertFalse( user_can( 0, 'easyq_vote_post', $question->id ) );
		$this->assertFalse( user_can( (int) $voter->user_id, 'easyq_vote_post', 999999 ) );
	}

	public function test_hidden_posts_can_be_deleted_by_moderators(): void {
		$question = $this->ask( $this->user() );
		Services::post_service()->set_question_status( Actor::system(), $question, Post::STATUS_HIDDEN );
		$moderator = $this->user( 'editor' );

		$this->assertTrue( user_can( (int) $moderator->user_id, 'easyq_delete_post', $question->id ) );
		$this->assertTrue( user_can( (int) $moderator->user_id, 'easyq_reshow_post', $question->id ) );
	}

	public function test_blocked_users_lose_their_capabilities(): void {
		$user     = $this->user();
		$question = $this->ask( $this->user() );
		User_Status::block( Actor::system(), (int) $user->user_id );

		$this->assertFalse( user_can( (int) $user->user_id, 'easyq_post_question' ) );
		$this->assertFalse( user_can( (int) $user->user_id, 'easyq_vote_post', $question->id ) );
	}

	public function test_moderators_cannot_be_blocked_and_blocked_users_lose_the_admin_level(): void {
		$admin = (int) $this->user()->user_id;
		Services::user_levels()->set_level( Actor::system(), $admin, Levels::ADMIN );
		$category = Services::categories()->create( null, 'Topic' );
		$this->assertIsInt( $category );
		$category_moderator = (int) $this->user()->user_id;
		Services::user_levels()->set_category_level( Actor::system(), $category_moderator, $category, Levels::MODERATOR );
		$editor = (int) $this->user()->user_id;
		Services::user_levels()->set_level( Actor::system(), $editor, Levels::EDITOR );

		// As Question2Answer offers the block: below Moderator only, counting category levels.
		foreach ( array( $admin, $category_moderator ) as $user_id ) {
			$this->assertFalse( User_Status::may_be_blocked( $user_id ) );
			User_Status::block( Actor::system(), $user_id );
			$this->assertFalse( User_Status::is_blocked( $user_id ) );
		}
		User_Status::block( Actor::system(), $editor );
		$this->assertTrue( User_Status::is_blocked( $editor ) );

		// A block stored before, or set beside the plugin, takes the Q&A administration away.
		$this->assertTrue( user_can( $admin, Capabilities::MANAGE ) );
		Site_Meta::update( $admin, User_Status::BLOCKED_META, 1 );
		$this->assertFalse( user_can( $admin, Capabilities::MANAGE ) );
		$this->assertFalse( user_can( $admin, Capabilities::MODERATE ) );
	}

	public function test_only_the_level_of_the_whole_site_manages_the_q_and_a_area(): void {
		$category = Services::categories()->create( null, 'Managed topic' );
		$this->assertIsInt( $category );
		$category_admin = (int) $this->user()->user_id;
		Services::user_levels()->set_category_level( Actor::system(), $category_admin, $category, Levels::ADMIN );
		$site_admin = (int) $this->user()->user_id;
		Services::user_levels()->set_level( Actor::system(), $site_admin, Levels::ADMIN );

		// As Question2Answer lets only the global level into the administration (`qa_admin_check_privileges()`);
		// a level in a category counts for the posts of that category.
		$this->assertFalse( user_can( $category_admin, Capabilities::MANAGE ) );
		$this->assertTrue( user_can( $site_admin, Capabilities::MANAGE ) );

		// Every route of the server that manages refuses the category administrator and lets the site's in.
		$managing = 0;
		foreach ( array( '/easyq/v1/email-templates', '/easyq/v1/user-fields', '/easyq/v1/newsletter' ) as $route ) {
			if ( ! isset( rest_get_server()->get_routes()[ $route ] ) ) {
				continue;
			}
			++$managing;
			wp_set_current_user( $category_admin );
			$this->assertContains( rest_do_request( new \WP_REST_Request( 'GET', $route ) )->get_status(), array( 401, 403 ), $route );
			wp_set_current_user( $site_admin );
			$this->assertSame( 200, rest_do_request( new \WP_REST_Request( 'GET', $route ) )->get_status(), $route );
		}
		$this->assertGreaterThan( 0, $managing );
	}

	public function test_leaves_other_capabilities_untouched(): void {
		$this->assertSame(
			array( 'edit_posts' ),
			( new Capabilities() )->map_meta_cap( array( 'edit_posts' ), 'edit_posts', 1, array() )
		);
		$this->assertSame(
			array( 'easyq_unknown' ),
			( new Capabilities() )->map_meta_cap( array( 'easyq_unknown' ), 'easyq_unknown', 1, array() )
		);
	}
}
