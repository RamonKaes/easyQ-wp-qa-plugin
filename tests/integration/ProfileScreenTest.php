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

/**
 * @covers \EasyQ\Admin\Profile_Screen
 */
final class ProfileScreenTest extends TestCase {

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
		$manager = self::factory()->user->create();
		$user    = self::factory()->user->create();
		Services::user_levels()->set_level( Actor::system(), $manager, Levels::ADMIN );

		$this->screen->save( $user, array( 'easyq_level' => (string) Levels::SUPER ), new Actor( $manager ) );

		$this->assertNull( Services::user_levels()->assigned_level( $user ) );
	}

	public function test_moderators_who_may_edit_users_change_the_status(): void {
		// Only roles that may edit other users reach this screen, e.g. administrators.
		$moderator = $this->administrator();
		$user      = self::factory()->user->create();
		update_user_meta( $user, User_Status::UNAPPROVED_META, 1 );

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
