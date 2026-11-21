<?php
/**
 * Tests for the users list additions.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Admin\Users_Screen;
use EasyQ\Users\User_Status;
use EasyQ\Settings\Options;
use EasyQ\Users\Site_Meta;

/**
 * @covers \EasyQ\Admin\Users_Screen
 */
final class Users_Screen_Test extends Test_Case {

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
		unset( $_GET[ Users_Screen::VIEW_ARG ], $_GET['easyq_done'], $_GET['easyq_count'], $_REQUEST['_wpnonce'] );
		parent::tear_down();
	}

	public function test_adds_level_and_points_columns(): void {
		$user = self::factory()->user->create();
		Site_Meta::update( $user, User_Status::UNAPPROVED_META, 1 );

		$this->assertArrayHasKey( 'easyq_points', $this->screen->columns( array() ) );
		$this->assertSame( '100', $this->screen->column_value( '', 'easyq_points', $user ) );
		$this->assertSame( 'Registered user, awaiting approval', $this->screen->column_value( '', 'easyq_level', $user ) );
		User_Status::block( \EasyQ\Actor::system(), $user );
		$this->assertSame( 'Registered user, awaiting approval, blocked', $this->screen->column_value( '', 'easyq_level', $user ) );
		$this->assertSame( 'other', $this->screen->column_value( 'other', 'email', $user ) );
	}

	/**
	 * Sends the form of the users list as the browser sends it: a GET request to `users.php`
	 * with the action, the users, the nonce and the referer field, and returns the address
	 * the screen redirects to, or null if it let the request go on to core.
	 *
	 * @param string       $action Bulk action.
	 * @param int[]        $users  Selected users.
	 * @param string|false $nonce  Nonce, or false for a form without one.
	 * @param bool         $below  Whether the action is chosen below the list (`action2`).
	 * @return string|null
	 */
	private function send_bulk_form( string $action, array $users, string|false $nonce, bool $below = false ): ?string {
		$_GET = array(
			'action'           => $below ? '-1' : $action,
			'action2'          => $below ? $action : '-1',
			'users'            => array_map( 'strval', $users ),
			'_wp_http_referer' => '/wp-admin/users.php?orderby=login',
		);
		if ( false !== $nonce ) {
			$_GET['_wpnonce'] = $nonce;
		}
		$_REQUEST                = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Builds the request of the test.
		$_SERVER['HTTP_REFERER'] = admin_url( 'users.php?orderby=login' );

		$redirect = static function ( string $location ): never {
			throw new \RuntimeException( $location ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Carries the address back to the test, not to output.
		};
		add_filter( 'wp_redirect', $redirect );
		try {
			$this->screen->handle_bulk_request();
			return null;
		} catch ( \RuntimeException $redirected ) {
			return $redirected->getMessage();
		} finally {
			remove_filter( 'wp_redirect', $redirect );
			$_GET     = array();
			$_REQUEST = array();
		}
	}

	public function test_no_notice_for_an_action_that_is_not_ours(): void {
		$_GET['easyq_done']  = 'delete';
		$_GET['easyq_count'] = '2';
		ob_start();
		$this->screen->notice();

		$this->assertSame( '', ob_get_clean() );
	}

	public function test_bulk_actions_approve_and_block_on_the_request_of_the_form(): void {
		$users = self::factory()->user->create_many( 2 );
		foreach ( $users as $user ) {
			Site_Meta::update( $user, User_Status::UNAPPROVED_META, 1 );
		}

		$redirect = $this->send_bulk_form( 'easyq_approve', $users, wp_create_nonce( 'bulk-users' ) );
		$this->send_bulk_form( 'easyq_block', array( $users[0] ), wp_create_nonce( 'bulk-users' ) );

		$this->assertStringContainsString( 'easyq_count=2', (string) $redirect );
		$this->assertStringContainsString( 'orderby=login', (string) $redirect );
		$this->assertStringNotContainsString( '_wpnonce', (string) $redirect );
		$this->assertFalse( User_Status::awaits_approval( $users[1] ) );
		$this->assertTrue( User_Status::is_blocked( $users[0] ) );
		// The actions of core go on to core.
		$this->assertNull( $this->send_bulk_form( 'delete', $users, wp_create_nonce( 'bulk-users' ) ) );
	}

	public function test_the_bulk_action_below_the_list_counts_as_the_one_above(): void {
		$user = self::factory()->user->create();

		$redirect = $this->send_bulk_form( 'easyq_block', array( $user ), wp_create_nonce( 'bulk-users' ), true );

		$this->assertStringContainsString( 'easyq_count=1', (string) $redirect );
		$this->assertTrue( User_Status::is_blocked( $user ) );
	}

	public function test_views_and_query_stay_as_core_made_them_for_users_who_do_not_moderate(): void {
		User_Status::block( \EasyQ\Actor::system(), self::factory()->user->create() );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$_GET[ Users_Screen::VIEW_ARG ] = 'blocked';

		$this->assertSame( array( 'all' => 'All' ), $this->screen->views( array( 'all' => 'All' ) ) );
		$this->assertSame( array( 'number' => 20 ), $this->screen->list_query_args( array( 'number' => 20 ) ) );
	}

	public function test_bulk_actions_need_the_nonce_of_the_users_list(): void {
		// A crafted link, e.g. users.php?action=easyq_block&users[]=5, must not change anything.
		$user = self::factory()->user->create();

		foreach ( array( false, 'invalid' ) as $nonce ) {
			try {
				$this->send_bulk_form( 'easyq_block', array( $user ), $nonce );
				$this->fail( 'A bulk action ran without the nonce of the users list.' );
			} catch ( \WPDieException $refused ) {
				$this->assertFalse( User_Status::is_blocked( $user ) );
			}
		}
	}

	public function test_only_moderators_get_and_use_bulk_actions(): void {
		$user = self::factory()->user->create();
		wp_set_current_user( self::factory()->user->create() );

		$this->assertSame( array(), $this->screen->bulk_actions( array() ) );
		try {
			$this->send_bulk_form( 'easyq_block', array( $user ), wp_create_nonce( 'bulk-users' ) );
			$this->fail( 'A subscriber ran a bulk action.' );
		} catch ( \WPDieException $refused ) {
			$this->assertFalse( User_Status::is_blocked( $user ) );
		}
	}

	public function test_bulk_actions_change_only_users_the_moderator_may_moderate_and_never_themselves(): void {
		$moderator = (int) $this->user( 'editor' )->user_id;
		$admin     = $this->administrator();
		$user      = self::factory()->user->create();

		// As on the profile page of Question2Answer: users below the level of the moderator (F-218).
		$count = $this->screen->perform( 'easyq_block', array( $moderator, $admin ), new \EasyQ\Actor( $moderator ) );

		$this->assertSame( 0, $count, 'Not themselves, nor a higher level.' );
		$this->assertFalse( User_Status::is_blocked( $admin ) );
		$this->assertSame( 1, $this->screen->perform( 'easyq_block', array( $admin, $user ), new \EasyQ\Actor( $admin ) ) );
		$this->assertTrue( User_Status::is_blocked( $user ) );
		$this->assertFalse( User_Status::is_blocked( $admin ) );
	}

	public function test_bulk_block_skips_moderators_and_counts_changes_only(): void {
		$admin     = $this->administrator();
		$moderator = self::factory()->user->create();
		\EasyQ\Services::user_levels()->set_level( new \EasyQ\Actor( $admin ), $moderator, \EasyQ\Users\Levels::MODERATOR );
		$user    = self::factory()->user->create();
		$blocked = self::factory()->user->create();
		User_Status::block( new \EasyQ\Actor( $admin ), $blocked );

		$this->assertSame( 1, $this->screen->perform( 'easyq_block', array( $moderator, $user, $blocked ), new \EasyQ\Actor( $admin ) ) );
		$this->assertFalse( User_Status::is_blocked( $moderator ) );
		$this->assertTrue( User_Status::is_blocked( $user ) );
	}

	public function test_views_and_query_filter(): void {
		$blocked = self::factory()->user->create();
		User_Status::block( \EasyQ\Actor::system(), $blocked );

		$views = $this->screen->views( array() );
		$this->assertArrayHasKey( 'easyq_blocked', $views );
		$this->assertArrayNotHasKey( 'easyq_unapproved', $views );

		Options::update( 'spam', array( 'moderate_users' => true ) );
		$waiting = array( self::factory()->user->create(), self::factory()->user->create() );
		foreach ( $waiting as $user_id ) {
			Site_Meta::update( $user_id, User_Status::UNAPPROVED_META, 1 );
		}
		set_current_screen( 'users' );
		add_filter( 'users_list_table_query_args', array( $this->screen, 'list_query_args' ) );

		foreach ( array(
			'blocked'    => array( $blocked ),
			'unapproved' => $waiting,
		) as $view => $expected ) {
			$_GET[ Users_Screen::VIEW_ARG ] = $view;

			// The list core builds shows the view only.
			$table = _get_list_table( 'WP_Users_List_Table', array( 'screen' => 'users' ) );
			$table->prepare_items();
			$ids = array_map( 'intval', array_keys( $table->items ) );
			sort( $ids );
			$this->assertSame( $expected, $ids, $view );

			// The counts of both views stay their own while one is selected.
			$views = $this->screen->views( array() );
			$this->assertStringContainsString( '<span class="count">(1)</span>', $views['easyq_blocked'], $view );
			$this->assertStringContainsString( '<span class="count">(2)</span>', $views['easyq_unapproved'], $view );
		}

		remove_filter( 'users_list_table_query_args', array( $this->screen, 'list_query_args' ) );
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
