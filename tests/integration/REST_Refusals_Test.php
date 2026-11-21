<?php
/**
 * Tests for the refusals of the REST API that need a request past its permission checks.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Services;
use EasyQ\Settings\Options;
use EasyQ\Tools\Tools;
use EasyQ\Users\Site_Meta;
use EasyQ\Users\User_Status;

/**
 * Each request here passes the permission check of its route and is refused by the
 * callback, because what it names does not exist or the user may not post (F-213).
 *
 * @covers \EasyQ\REST\Email_Templates_Controller
 * @covers \EasyQ\REST\IPs_Controller
 * @covers \EasyQ\REST\Messages_Controller
 * @covers \EasyQ\REST\Tools_Controller
 * @covers \EasyQ\REST\User_Fields_Controller
 * @covers \EasyQ\REST\REST_Errors
 */
final class REST_Refusals_Test extends REST_Test_Case {

	/**
	 * Asserts that a response is a refusal with a code and status.
	 *
	 * @param string            $code     Error code.
	 * @param int               $status   HTTP status.
	 * @param \WP_REST_Response $response Response.
	 * @param string            $message  Message of the assertion.
	 */
	private function assertRefused( string $code, int $status, \WP_REST_Response $response, string $message = '' ): void {
		$this->assertSame( $status, $response->get_status(), $message );
		$this->assertSame( $code, $response->as_error()?->get_error_code(), $message );
	}

	public function test_what_does_not_exist_is_not_found_for_managers(): void {
		wp_set_current_user( $this->administrator() );

		foreach ( array(
			array(
				'PUT',
				'/email-templates/no_such_email',
				array(
					'subject' => 'S',
					'body'    => 'B',
				),
			),
			array( 'DELETE', '/email-templates/no_such_email', array() ),
			array( 'POST', '/email-templates/no_such_email/test', array() ),
			array( 'POST', '/ips/1.2.3/block', array() ),
			array( 'POST', '/ips/1.2.3/unblock', array() ),
			array( 'POST', '/ips/1.2.3/hide_all', array() ),
			array( 'DELETE', '/user-fields/99999', array( 'force' => true ) ),
		) as [ $method, $route, $params ] ) {
			$response = $this->request( $method, $route, $params );
			$this->assertSame( 404, $response->get_status(), "$method $route" );
		}
	}

	public function test_a_field_with_a_value_it_cannot_take_is_refused_as_invalid(): void {
		wp_set_current_user( $this->administrator() );
		Services::user_fields()->create( 'shoe-size', 'Shoe size', 0, null );
		[ $field, $other ] = Services::user_fields()->all();

		// Two fields with one label, which the validation of the service refuses.
		$response = $this->request( 'PUT', '/user-fields/' . $field->id, array( 'label' => $other->label ) );

		$this->assertRefused( 'rest_invalid_param', 400, $response );
	}

	public function test_a_wall_of_a_user_who_does_not_exist_and_a_post_of_another_wall_are_not_found(): void {
		Options::update( 'users', array( 'allow_user_walls' => true ) );
		$owner = $this->login();
		$this->take_part( $owner );
		$other = (int) $this->user()->user_id;
		$this->take_part( $other );

		$this->assertSame( 404, $this->request( 'POST', '/users/99999/wall', array( 'content' => 'Hello there.' ) )->get_status() );

		$post = $this->data( $this->request( 'POST', '/users/' . $owner . '/wall', array( 'content' => 'On my own wall.' ) ) );
		$this->assertIsInt( $post['id'] ?? null );
		$this->assertSame( 404, $this->request( 'DELETE', '/users/' . $other . '/wall/' . $post['id'] )->get_status() );
	}

	public function test_a_tool_waits_while_another_run_goes_on(): void {
		wp_set_current_user( $this->administrator() );
		add_option( Tools::RUN_OPTION, 'another-run|' . time(), '', false );

		$this->assertRefused( 'easyq_tools_running', 409, $this->request( 'POST', '/tools/recount_posts' ) );
	}

	public function test_users_awaiting_approval_and_blocked_addresses_are_told_why(): void {
		// Posting asks for approved users, as Question2Answer can.
		Options::update( 'spam', array( 'moderate_users' => true ) );
		Options::update( 'permissions', array( 'permit_post_q' => \EasyQ\Users\Permits::APPROVED ) );
		$user = $this->login();
		Site_Meta::update( $user, User_Status::UNAPPROVED_META, 1 );
		$question = array(
			'title'   => 'A question that is refused',
			'content' => 'Text of the question.',
		);

		$this->assertRefused( 'easyq_approve', 403, $this->request( 'POST', '/questions', $question ) );

		Options::update(
			'spam',
			array(
				'moderate_users'  => false,
				'block_ips_write' => '192.0.2.9',
			)
		);
		$_SERVER['REMOTE_ADDR'] = '192.0.2.9';
		try {
			$this->assertRefused( 'easyq_ipblock', 403, $this->request( 'POST', '/questions', $question ) );
		} finally {
			$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
		}
	}

	public function test_the_callbacks_check_again_what_their_permission_check_found(): void {
		// As the callbacks of core look the post up again (`WP_REST_Posts_Controller::get_item()`):
		// called directly, without the permission check, they refuse what does not exist.
		Options::update( 'users', array( 'allow_user_walls' => true ) );
		wp_set_current_user( $this->administrator() );
		$owner = $this->take_part( (int) $this->user()->user_id );
		$other = $this->take_part( (int) $this->user()->user_id );
		$post  = Services::messages()->post_on_wall( \EasyQ\Actor::current(), get_userdata( $owner ), 'On the wall of the owner.' );
		$this->assertIsInt( $post );
		$request = static function ( array $params ): \WP_REST_Request {
			$request = new \WP_REST_Request();
			foreach ( $params as $key => $value ) {
				$request->set_param( $key, $value );
			}

			return $request;
		};

		$this->assertSame( 404, ( new \EasyQ\REST\IPs_Controller() )->hide_all( $request( array( 'ip' => '1.2.3' ) ) )->get_error_data()['status'] ?? null );
		$this->assertSame(
			404,
			( new \EasyQ\REST\Messages_Controller() )->post_on_wall(
				$request(
					array(
						'id'      => 99999,
						'content' => 'Hello.',
					)
				)
			)->get_error_data()['status'] ?? null
		);
		$this->assertSame(
			404,
			( new \EasyQ\REST\Messages_Controller() )->delete_wall_post(
				$request(
					array(
						'id'      => $other,
						'message' => $post,
						'force'   => true,
					)
				)
			)->get_error_data()['status'] ?? null
		);
		$this->assertNotNull( Services::messages()->find( $post ) );

		// A question deleted between the permission check and the callback.
		$favorite = $request(
			array(
				'type' => 'question',
				'id'   => 99999,
			)
		);
		$this->assertSame( 404, ( new \EasyQ\REST\Favorites_Controller() )->delete_item( $favorite )->get_error_data()['status'] ?? null );
	}
}
