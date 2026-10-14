<?php
/**
 * Tests for confirmation, approval and blocking.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Actor;
use EasyQ\Settings\Options;
use EasyQ\Users\User_Status;
use EasyQ\Users\Site_Meta;

/**
 * @covers \EasyQ\Users\User_Status
 */
final class UserStatusTest extends TestCase {

	public function test_existing_users_are_confirmed_and_need_no_approval(): void {
		$user = self::factory()->user->create();

		$this->assertTrue( User_Status::is_confirmed( $user ) );
		$this->assertFalse( User_Status::awaits_approval( $user ) );
		$this->assertFalse( User_Status::is_approved( $user ) );
		$this->assertFalse( User_Status::is_blocked( $user ) );
	}

	public function test_new_registrations_are_marked_according_to_the_settings(): void {
		Options::update( 'spam', array( 'moderate_users' => true ) );
		$user = self::factory()->user->create();

		do_action( 'register_new_user', $user );

		$this->assertFalse( User_Status::is_confirmed( $user ) );
		$this->assertTrue( User_Status::awaits_approval( $user ) );
	}

	public function test_registrations_need_no_confirmation_if_disabled(): void {
		Options::update( 'spam', array( 'confirm_user_emails' => false ) );
		$user = self::factory()->user->create();

		do_action( 'register_new_user', $user );

		$this->assertTrue( User_Status::is_confirmed( $user ) );
	}

	public function test_setting_the_password_by_link_confirms_the_address(): void {
		$user = self::factory()->user->create_and_get();
		User_Status::unconfirm( $user->ID );

		do_action( 'after_password_reset', $user, 'new password' );

		$this->assertTrue( User_Status::is_confirmed( $user->ID ) );
		$this->assertContains( 'u_confirmed', $this->event_names() );
	}

	public function test_approving_blocking_and_unblocking(): void {
		$user = self::factory()->user->create();
		Site_Meta::update( $user, User_Status::UNAPPROVED_META, 1 );

		User_Status::approve( Actor::system(), $user );
		User_Status::block( Actor::system(), $user );
		User_Status::block( Actor::system(), $user );
		User_Status::unblock( Actor::system(), $user );

		$this->assertFalse( User_Status::awaits_approval( $user ) );
		$this->assertTrue( User_Status::is_approved( $user ) );
		$this->assertFalse( User_Status::is_blocked( $user ) );
		$this->assertSame( array( 'u_level', 'u_block', 'u_unblock' ), array_values( array_intersect( $this->event_names(), array( 'u_level', 'u_block', 'u_unblock' ) ) ) );
	}
}
