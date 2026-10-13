<?php
/**
 * Tests for user events and user deletion.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Actor;
use EasyQ\Services;
use EasyQ\Users\Levels;

/**
 * @covers \EasyQ\Users\User_Events
 */
final class UserEventsTest extends TestCase {

	public function test_reports_registration_login_and_profile_changes(): void {
		$user_id = self::factory()->user->create();
		$this->assertSame( $user_id, $this->event_params( 'u_register' )['user_id'] );

		do_action( 'wp_login', 'login', get_userdata( $user_id ) );
		$this->assertSame( $user_id, $this->event_params( 'u_login' )['user_id'] );

		wp_set_current_user( $user_id );
		wp_update_user(
			array(
				'ID'         => $user_id,
				'first_name' => 'Ada',
			)
		);
		$this->assertContains( 'u_save', $this->event_names() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		wp_update_user(
			array(
				'ID'        => $user_id,
				'last_name' => 'Lovelace',
			)
		);
		$this->assertContains( 'u_edit', $this->event_names() );
	}

	/**
	 * @group ms-excluded
	 */
	public function test_deleting_a_user_keeps_posts_without_author(): void {
		require_once ABSPATH . 'wp-admin/includes/user.php';

		$author   = $this->user();
		$voter    = $this->user();
		$question = $this->ask( $author );
		$answer   = $this->answer( $voter, $question );
		Services::votes()->vote( $voter, $question, 1 );
		Services::user_levels()->set_category_level( Actor::system(), (int) $voter->user_id, 1, Levels::EXPERT );

		wp_delete_user( (int) $voter->user_id );

		$this->assertNull( $this->post( $answer->id )->user_id );
		$this->assertSame( 0, $this->post( $question->id )->upvotes );
		$this->assertSame( 120, Services::points()->get( (int) $author->user_id ) );
		$this->assertSame( array(), Services::user_levels()->category_levels( (int) $voter->user_id ) );
		$this->assertSame( array( 'u_delete_before', 'u_delete' ), array_values( array_intersect( $this->event_names(), array( 'u_delete_before', 'u_delete' ) ) ) );
	}

	/**
	 * @group ms-excluded
	 */
	public function test_deleting_a_user_can_reassign_posts(): void {
		require_once ABSPATH . 'wp-admin/includes/user.php';

		$author   = $this->user();
		$heir     = $this->user();
		$question = $this->ask( $author );

		wp_delete_user( (int) $author->user_id, (int) $heir->user_id );

		$this->assertSame( $heir->user_id, $this->post( $question->id )->user_id );
		$this->assertSame( 120, Services::points()->get( (int) $heir->user_id ) );
	}

	/**
	 * @group ms-required
	 */
	public function test_deleting_a_user_from_the_network_removes_their_data(): void {
		require_once ABSPATH . 'wp-admin/includes/ms.php';

		$author   = $this->user();
		$question = $this->ask( $author );

		wpmu_delete_user( (int) $author->user_id );

		$this->assertNull( $this->post( $question->id )->user_id );
	}
}
