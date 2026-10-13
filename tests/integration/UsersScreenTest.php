<?php
/**
 * Tests for the users list additions.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Admin\Users_Screen;
use EasyQ\Users\User_Status;
use WP_User_Query;

/**
 * @covers \EasyQ\Admin\Users_Screen
 */
final class UsersScreenTest extends TestCase {

	/**
	 * Screen under test.
	 *
	 * @var Users_Screen
	 */
	private Users_Screen $screen;

	public function set_up(): void {
		parent::set_up();
		$this->screen = new Users_Screen();
		wp_set_current_user( $this->administrator() );
	}

	public function tear_down(): void {
		unset( $_GET[ Users_Screen::VIEW_ARG ], $_GET['easyq_done'], $_GET['easyq_count'] );
		parent::tear_down();
	}

	public function test_adds_level_and_points_columns(): void {
		$user = self::factory()->user->create();
		update_user_meta( $user, User_Status::UNAPPROVED_META, 1 );

		$this->assertArrayHasKey( 'easyq_points', $this->screen->columns( array() ) );
		$this->assertSame( '100', $this->screen->column_value( '', 'easyq_points', $user ) );
		$this->assertSame( 'Registered user, awaiting approval', $this->screen->column_value( '', 'easyq_level', $user ) );
		$this->assertSame( 'other', $this->screen->column_value( 'other', 'email', $user ) );
	}

	public function test_bulk_actions_approve_and_block(): void {
		$users = self::factory()->user->create_many( 2 );
		foreach ( $users as $user ) {
			update_user_meta( $user, User_Status::UNAPPROVED_META, 1 );
		}

		$redirect = $this->screen->handle_bulk_action( 'users.php', 'easyq_approve', $users );
		$this->screen->handle_bulk_action( 'users.php', 'easyq_block', array( $users[0] ) );

		$this->assertStringContainsString( 'easyq_count=2', $redirect );
		$this->assertFalse( User_Status::awaits_approval( $users[1] ) );
		$this->assertTrue( User_Status::is_blocked( $users[0] ) );
		$this->assertSame( 'users.php', $this->screen->handle_bulk_action( 'users.php', 'delete', $users ) );
	}

	public function test_subscribers_get_no_bulk_actions(): void {
		$user = self::factory()->user->create();
		wp_set_current_user( self::factory()->user->create() );

		$this->assertSame( array(), $this->screen->bulk_actions( array() ) );
		$this->screen->handle_bulk_action( 'users.php', 'easyq_block', array( $user ) );
		$this->assertFalse( User_Status::is_blocked( $user ) );
	}

	public function test_views_and_query_filter(): void {
		global $pagenow;

		$blocked = self::factory()->user->create();
		User_Status::block( \EasyQ\Actor::system(), $blocked );

		$views = $this->screen->views( array() );
		$this->assertArrayHasKey( 'easyq_blocked', $views );
		$this->assertArrayNotHasKey( 'easyq_unapproved', $views );

		set_current_screen( 'users' );
		$pagenow                        = 'users.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulates the users screen.
		$_GET[ Users_Screen::VIEW_ARG ] = 'blocked';

		$query = new WP_User_Query();
		$this->screen->filter_query( $query );

		$this->assertSame( User_Status::BLOCKED_META, $query->get( 'meta_key' ) );
	}

	public function test_shows_a_notice_after_bulk_actions(): void {
		$_GET['easyq_done']  = 'easyq_block';
		$_GET['easyq_count'] = '3';

		ob_start();
		$this->screen->notice();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '3 users blocked.', $html );
	}
}
