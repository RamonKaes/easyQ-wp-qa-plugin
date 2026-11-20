<?php
/**
 * Tests for approving and blocking users by moderators.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Blocks\Block_Context;
use EasyQ\Frontend\Route;
use EasyQ\Settings\Options;
use EasyQ\Users\Levels;
use EasyQ\Users\User_Status;

/**
 * Question2Answer lets moderators approve and block users below their level on the profile
 * page (`qa-include/pages/user-profile.php`); `readme.txt` promises user approval and
 * blocking. Moderators may not list or edit users in WordPress, so the profile page and the
 * REST API are their way.
 *
 * @covers \EasyQ\Users\User_Status
 * @covers \EasyQ\REST\Users_Controller
 * @covers \EasyQ\Blocks\Render\Users_Render
 */
final class User_Moderation_Test extends REST_Test_Case {

	public function tear_down(): void {
		Block_Context::router()->set_current( null );
		parent::tear_down();
	}

	public function test_moderators_block_and_unblock_users_below_their_level(): void {
		$user      = (int) $this->user()->user_id;
		$moderator = (int) $this->user( 'editor' )->user_id;
		wp_set_current_user( $moderator );
		$this->assertFalse( current_user_can( 'edit_user', $user ), 'Core lets editors edit no users.' );

		$blocked = $this->request( 'POST', '/users/' . $user . '/actions/block' );
		$this->assertSame( 200, $blocked->get_status() );
		$this->assertTrue( $this->data( $blocked )['blocked'] );
		$this->assertTrue( User_Status::is_blocked( $user ) );
		$this->assertContains( 'u_block', $this->event_names() );

		$this->assertSame( 200, $this->request( 'POST', '/users/' . $user . '/actions/unblock' )->get_status() );
		$this->assertFalse( User_Status::is_blocked( $user ) );
	}

	public function test_moderators_approve_users_while_users_are_moderated(): void {
		$user = (int) $this->user()->user_id;
		\EasyQ\Users\Site_Meta::update( $user, User_Status::UNAPPROVED_META, 1 );
		wp_set_current_user( (int) $this->user( 'editor' )->user_id );

		$this->assertSame( 400, $this->request( 'POST', '/users/' . $user . '/actions/approve' )->get_status() );

		Options::update( 'spam', array( 'moderate_users' => true ) );
		$this->assertSame( 200, $this->request( 'POST', '/users/' . $user . '/actions/approve' )->get_status() );
		$this->assertFalse( User_Status::awaits_approval( $user ) );
	}

	public function test_nobody_moderates_themselves_their_level_or_higher(): void {
		$moderator = (int) $this->user( 'editor' )->user_id;
		$other     = (int) $this->user( 'editor' )->user_id;
		$expert    = (int) $this->user()->user_id;
		\EasyQ\Services::user_levels()->set_level( \EasyQ\Actor::system(), $expert, Levels::EXPERT );

		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->request( 'POST', '/users/' . $expert . '/actions/block' )->get_status() );

		wp_set_current_user( (int) $this->user()->user_id );
		$this->assertSame( 403, $this->request( 'POST', '/users/' . $expert . '/actions/block' )->get_status(), 'A user without a level.' );

		// A moderator of one category moderates its posts, not users (the level of the whole site counts).
		$category = \EasyQ\Services::categories()->create( null, 'Moderated here' );
		$this->assertIsInt( $category );
		$in_category = (int) $this->user()->user_id;
		\EasyQ\Services::user_levels()->set_category_level( \EasyQ\Actor::system(), $in_category, $category, Levels::MODERATOR );
		wp_set_current_user( $in_category );
		$this->assertSame( 403, $this->request( 'POST', '/users/' . $this->user()->user_id . '/actions/block' )->get_status(), 'A level in a category.' );

		// An expert is above users without a level, but no moderator.
		wp_set_current_user( $expert );
		$this->assertSame( 403, $this->request( 'POST', '/users/' . $this->user()->user_id . '/actions/block' )->get_status(), 'Below the level of moderators.' );

		wp_set_current_user( $moderator );
		$this->assertSame( 403, $this->request( 'POST', '/users/' . $moderator . '/actions/unblock' )->get_status(), 'Themselves.' );
		$this->assertSame( 403, $this->request( 'POST', '/users/' . $other . '/actions/unblock' )->get_status(), 'Their own level.' );
		$this->assertSame( 200, $this->request( 'POST', '/users/' . $expert . '/actions/block' )->get_status(), 'A lower level.' );
		$this->assertSame( 404, $this->request( 'POST', '/users/999999/actions/block' )->get_status() );
	}

	public function test_the_profile_shows_moderators_the_buttons(): void {
		$user = get_userdata( (int) $this->user()->user_id );
		$this->assertInstanceOf( \WP_User::class, $user );
		Block_Context::router()->set_current( new Route( 'user', $user ) );
		$render = static fn (): string => do_blocks( '<!-- wp:easyq/user-navigation /-->' );

		wp_set_current_user( (int) $this->user()->user_id );
		$this->assertStringNotContainsString( 'Block user', $render() );

		wp_set_current_user( (int) $this->user( 'editor' )->user_id );
		$html = $render();
		$this->assertStringContainsString( 'value="block"', $html );
		$this->assertStringContainsString( '>Block user<', $html );

		User_Status::block( \EasyQ\Actor::system(), $user->ID );
		$this->assertStringContainsString( '>Unblock user<', $render() );
	}

	public function test_the_bulk_actions_of_the_users_list_follow_the_same_rule(): void {
		$user      = (int) $this->user()->user_id;
		$other     = (int) $this->user( 'editor' )->user_id;
		$moderator = new \EasyQ\Actor( (int) $this->user( 'editor' )->user_id );

		$this->assertSame( 1, ( new \EasyQ\Admin\Users_Screen() )->perform( 'easyq_block', array( $user, $other, (int) $moderator->user_id ), $moderator ) );
		$this->assertTrue( User_Status::is_blocked( $user ) );
		$this->assertFalse( User_Status::is_blocked( $other ) );
	}
}
