<?php
/**
 * Tests for the moderation list and IP address endpoints.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Actor;
use EasyQ\Capabilities;
use EasyQ\Services;
use EasyQ\Settings\Options;
use EasyQ\Users\Levels;
use EasyQ\Users\Permits;

/**
 * @covers \EasyQ\REST\Posts_Controller
 * @covers \EasyQ\REST\IPs_Controller
 * @covers \EasyQ\Limits\IP_Blocks
 */
final class Moderation_REST_Test extends REST_Test_Case {

	/**
	 * Logs in a new user with the Q&A level Editor, below Moderator.
	 *
	 * @return int User ID.
	 */
	private function login_editor_level(): int {
		$user_id = $this->login( 'author' );
		Services::user_levels()->set_level( Actor::system(), $user_id, Levels::EDITOR );

		return $user_id;
	}

	public function test_lists_posts_for_moderators_only(): void {
		$queued = $this->ask( $this->user(), 'A question awaiting approval', 'x', array(), null, true );
		$this->ask( $this->user(), 'A visible question here' );

		$this->assertSame( 401, $this->request( 'GET', '/posts' )->get_status() );
		$this->login();
		$this->assertSame( 403, $this->request( 'GET', '/posts' )->get_status() );

		$this->login( 'editor' );
		$response = $this->request(
			'GET',
			'/posts',
			array(
				'view'     => 'pending',
				'per_page' => 5,
			)
		);
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( $queued->id ), array_column( $this->data( $response ), 'id' ) );
		$this->assertSame( '1', $response->get_headers()['X-WP-Total'] );
		$this->assertSame( 2, count( $this->data( $this->request( 'GET', '/posts' ) ) ) );
		$this->assertSame( 400, $this->request( 'GET', '/posts', array( 'view' => 'deleted' ) )->get_status() );
	}

	public function test_users_who_may_delete_hidden_posts_reach_the_moderation_screens(): void {
		Options::update(
			'permissions',
			array(
				'permit_moderate'      => Permits::MODERATORS,
				'permit_hide_show'     => Permits::MODERATORS,
				'permit_delete_hidden' => Permits::EDITORS,
			)
		);
		$editor = $this->login_editor_level();

		$this->assertTrue( user_can( $editor, Capabilities::MODERATE ) );
		$this->assertSame( 200, $this->request( 'GET', '/posts', array( 'view' => 'hidden' ) )->get_status() );

		Options::update( 'permissions', array( 'permit_delete_hidden' => Permits::MODERATORS ) );
		$this->assertFalse( user_can( $editor, Capabilities::MODERATE ) );
	}

	public function test_moderators_block_and_unblock_ip_addresses(): void {
		$this->assertSame( 401, $this->request( 'GET', '/ips/192.0.2.44' )->get_status() );

		// Editors see IP addresses, but blocking needs a moderator, as in Question2Answer.
		$this->login_editor_level();
		$this->assertFalse( $this->data( $this->request( 'GET', '/ips/192.0.2.44' ) )['can_block'] );
		$this->assertSame( 403, $this->request( 'POST', '/ips/192.0.2.44/block' )->get_status() );
		$this->assertSame( '', Options::get_string( 'spam', 'block_ips_write' ) );

		$this->login( 'editor' );
		Options::update( 'spam', array( 'block_ips_write' => '198.51.100.1' ) );

		$data = $this->data( $this->request( 'POST', '/ips/192.0.2.44/block' ) );
		$this->assertTrue( $data['blocked'] );
		$this->assertSame( array( '192.0.2.44' ), $data['rules'] );
		$this->assertSame( '198.51.100.1, 192.0.2.44', Options::get_string( 'spam', 'block_ips_write' ) );
		$this->assertSame( array( 'ip' => '192.0.2.44' ), $this->event_params( 'ip_block' ) );

		// Blocking twice adds no second rule.
		$this->request( 'POST', '/ips/192.0.2.44/block' );
		$this->assertSame( '198.51.100.1, 192.0.2.44', Options::get_string( 'spam', 'block_ips_write' ) );

		// Unblocking removes every rule that matches, ranges included.
		Options::update( 'spam', array( 'block_ips_write' => '198.51.100.1, 192.0.2.44, 192.0.2.*' ) );
		$data = $this->data( $this->request( 'POST', '/ips/192.0.2.44/unblock' ) );
		$this->assertFalse( $data['blocked'] );
		$this->assertSame( array( '192.0.2.44', '192.0.2.*' ), $data['removed'] );
		$this->assertSame( '198.51.100.1', Options::get_string( 'spam', 'block_ips_write' ) );
		$this->assertSame( array( 'ip' => '192.0.2.44' ), $this->event_params( 'ip_unblock' ) );

		$this->assertSame( 404, $this->request( 'GET', '/ips/999.1.1.1' )->get_status() );
	}

	public function test_hides_all_visible_posts_from_an_ip_address(): void {
		$author   = new Actor( $this->user()->user_id, null, '192.0.2.44' );
		$question = $this->ask( $author, 'A question from the address' );
		$answer   = $this->answer( $author, $question, 'An answer from the same address.' );
		$queued   = $this->ask( $author, 'A queued question from it', 'x', array(), null, true );
		$other    = $this->ask( $this->user(), 'A question from elsewhere' );

		$this->login_editor_level();
		$this->assertSame( 403, $this->request( 'POST', '/ips/192.0.2.44/hide-all' )->get_status() );

		wp_set_current_user( $this->administrator() );
		$data = $this->data( $this->request( 'POST', '/ips/192.0.2.44/hide-all' ) );

		$this->assertSame( 2, $data['hidden'] );
		$this->assertTrue( $this->post( $question->id )->is_hidden() );
		$this->assertTrue( $this->post( $answer->id )->is_hidden() );
		$this->assertTrue( $this->post( $queued->id )->is_queued() );
		$this->assertTrue( $this->post( $other->id )->is_visible() );
	}

	public function test_lists_posts_from_an_ip_address_for_users_who_see_addresses(): void {
		$from_ip = $this->ask( new Actor( null, null, '192.0.2.44' ), 'A question by a visitor' );
		$this->ask( $this->user(), 'A question from elsewhere' );
		Options::update(
			'permissions',
			array(
				'permit_moderate'      => Permits::MODERATORS,
				'permit_hide_show'     => Permits::MODERATORS,
				'permit_anon_view_ips' => Permits::USERS,
			)
		);
		$this->login();

		$this->assertSame( 403, $this->request( 'GET', '/posts' )->get_status() );
		$data = $this->data( $this->request( 'GET', '/posts', array( 'ip' => '192.0.2.44' ) ) );
		$this->assertSame( array( $from_ip->id ), array_column( $data, 'id' ) );
		$this->assertSame( '192.0.2.44', $data[0]['ip'] );
	}
}
