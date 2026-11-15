<?php
/**
 * Tests for the Q&A section of the profile screen.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Actor;
use EasyQ\Admin\Profile_Screen;
use EasyQ\Services;
use EasyQ\Users\Levels;
use EasyQ\Users\User_Status;
use EasyQ\Users\Site_Meta;

/**
 * @covers \EasyQ\Admin\Profile_Screen
 */
final class Profile_Screen_Test extends Test_Case {

	/**
	 * Screen under test.
	 *
	 * @var Profile_Screen
	 */
	private Profile_Screen $screen;

	public function set_up(): void {
		parent::set_up();
		$this->screen = new Profile_Screen();
	}

	/**
	 * Renders the section for a user while another user is logged in.
	 *
	 * @param int $viewer  Logged-in user.
	 * @param int $user_id Edited user.
	 * @return string
	 */
	private function render( int $viewer, int $user_id ): string {
		wp_set_current_user( $viewer );
		ob_start();
		$this->screen->render( get_userdata( $user_id ) );

		return (string) ob_get_clean();
	}

	public function test_users_edit_their_profile_fields_but_not_their_level(): void {
		$user = self::factory()->user->create();

		$html = $this->render( $user, $user );

		$this->assertStringContainsString( 'easyq_fields[', $html );
		$this->assertStringNotContainsString( 'name="easyq_level"', $html );
		$this->assertStringNotContainsString( 'name="easyq_blocked"', $html );

		$location = Services::user_fields()->all()[0];
		$this->screen->save(
			$user,
			array(
				'easyq_fields' => array( $location->id => 'Hamburg' ),
				'easyq_level'  => (string) Levels::ADMIN,
			),
			new Actor( $user )
		);

		$this->assertSame( 'Hamburg', Services::user_fields()->value( $user, $location ) );
		$this->assertNull( Services::user_levels()->assigned_level( $user ) );
	}

	public function test_the_read_only_mode_keeps_the_section_as_it_is_except_for_managers(): void {
		$user     = self::factory()->user->create();
		$location = Services::user_fields()->all()[0];
		\EasyQ\Settings\Options::update( 'general', array( 'read_only' => true ) );

		$html = $this->render( $user, $user );
		$this->assertStringContainsString( '<fieldset disabled', $html );
		$this->assertStringContainsString( 'The Q&amp;A area is read-only', $html );
		$this->screen->save( $user, array( 'easyq_fields' => array( $location->id => 'Hamburg' ) ), new Actor( $user ) );
		$this->assertSame( '', Services::user_fields()->value( $user, $location ) );

		$manager = $this->administrator();
		$this->assertStringNotContainsString( '<fieldset disabled', $this->render( $manager, $user ) );
		$this->screen->save( $user, array( 'easyq_fields' => array( $location->id => 'Berlin' ) ), new Actor( $manager ) );
		$this->assertSame( 'Berlin', Services::user_fields()->value( $user, $location ) );
	}

	public function test_blocked_users_cannot_change_their_section_as_in_the_rest_api(): void {
		$user     = self::factory()->user->create();
		$location = Services::user_fields()->all()[0];
		User_Status::block( Actor::system(), $user );

		$html = $this->render( $user, $user );
		$this->assertStringContainsString( '<fieldset disabled', $html );
		$this->assertStringContainsString( 'Your account is blocked.', $html );
		$this->screen->save( $user, array( 'easyq_fields' => array( $location->id => 'Hamburg' ) ), new Actor( $user ) );
		$this->assertSame( '', Services::user_fields()->value( $user, $location ) );

		wp_set_current_user( $user );
		$this->assertSame( 403, rest_get_server()->dispatch( new \WP_REST_Request( 'POST', '/easyq/v1/users/me' ) )->get_status() );

		$manager = $this->administrator();
		$this->assertStringNotContainsString( '<fieldset disabled', $this->render( $manager, $user ) );
		$this->screen->save( $user, array( 'easyq_fields' => array( $location->id => 'Berlin' ) ), new Actor( $manager ) );
		$this->assertSame( 'Berlin', Services::user_fields()->value( $user, $location ) );

		// A block stops a user's own posting, not the managing of others, which follows the capability.
		User_Status::block( Actor::system(), $manager );
		$other = self::factory()->user->create();
		$this->screen->save( $other, array( 'easyq_fields' => array( $location->id => 'Bremen' ) ), new Actor( $manager ) );
		$this->assertSame( 'Bremen', Services::user_fields()->value( $other, $location ) );
	}

	public function test_administrators_set_levels_and_bonus_points(): void {
		$admin    = $this->administrator();
		$user     = self::factory()->user->create();
		$category = Services::categories()->create( null, 'Topic' );
		$this->assertIsInt( $category );

		$html = $this->render( $admin, $user );
		$this->assertStringContainsString( 'name="easyq_level"', $html );
		$this->assertStringContainsString( 'easyq_category_levels[' . $category . ']', $html );

		$this->screen->save(
			$user,
			array(
				'easyq_level'           => (string) Levels::EXPERT,
				'easyq_bonus'           => '15',
				'easyq_category_levels' => array( $category => (string) Levels::EDITOR ),
			),
			new Actor( $admin )
		);

		$this->assertSame( Levels::EXPERT, Services::user_levels()->assigned_level( $user ) );
		$this->assertSame( 115, Services::points()->get( $user ) );
		$this->assertSame( array( $category => Levels::EDITOR ), Services::user_levels()->category_levels( $user ) );
	}

	public function test_levels_above_the_own_level_cannot_be_assigned(): void {
		// A WordPress administrator, who may edit the user, counts as a Q&A administrator.
		$manager = $this->administrator();
		$user    = self::factory()->user->create();

		$html = $this->render( $manager, $user );
		$this->assertStringContainsString( '<option value="' . Levels::ADMIN . '"', $html );
		$this->assertStringNotContainsString( '<option value="' . Levels::SUPER . '"', $html );

		$this->screen->save( $user, array( 'easyq_level' => (string) Levels::SUPER ), new Actor( $manager ) );
		$this->assertNull( Services::user_levels()->assigned_level( $user ) );

		$this->screen->save( $user, array( 'easyq_level' => (string) Levels::ADMIN ), new Actor( $manager ) );
		$this->assertSame( Levels::ADMIN, Services::user_levels()->assigned_level( $user ) );
	}

	public function test_moderators_who_may_edit_users_change_the_status(): void {
		// Only roles that may edit other users reach this screen, e.g. administrators.
		$moderator = $this->administrator();
		$user      = self::factory()->user->create();
		Site_Meta::update( $user, User_Status::UNAPPROVED_META, 1 );

		$this->assertStringContainsString( 'name="easyq_blocked"', $this->render( $moderator, $user ) );

		$this->screen->save(
			$user,
			array(
				'easyq_approved' => '1',
				'easyq_blocked'  => '1',
			),
			new Actor( $moderator )
		);

		$this->assertFalse( User_Status::awaits_approval( $user ) );
		$this->assertTrue( User_Status::is_blocked( $user ) );
		$this->assertFalse( User_Status::is_confirmed( $user ) );
	}

	public function test_editors_cannot_change_other_users(): void {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$user   = self::factory()->user->create();

		$this->screen->save( $user, array( 'easyq_blocked' => '1' ), new Actor( $editor ) );

		$this->assertFalse( User_Status::is_blocked( $user ) );
	}

	public function test_saving_requires_a_valid_nonce(): void {
		$user = self::factory()->user->create();
		wp_set_current_user( $user );
		$location = Services::user_fields()->all()[0];

		$_POST['easyq_fields']        = array( $location->id => 'Bremen' );
		$_POST['easyq_profile_nonce'] = 'invalid';
		$this->screen->handle_save( $user );
		$this->assertSame( '', Services::user_fields()->value( $user, $location ) );

		$_POST['easyq_profile_nonce'] = wp_create_nonce( 'easyq_profile_' . $user );
		$this->screen->handle_save( $user );
		$this->assertSame( 'Bremen', Services::user_fields()->value( $user, $location ) );

		unset( $_POST['easyq_fields'], $_POST['easyq_profile_nonce'] );
	}
}
