<?php
/**
 * Tests for the blocked usernames and display names.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Settings\Options;
use EasyQ\Users\Blocked_Names;
use WP_REST_Request;

/**
 * @covers \EasyQ\Users\Blocked_Names
 */
final class Blocked_Names_Test extends Test_Case {

	public function tear_down(): void {
		$_POST = array();
		parent::tear_down();
	}

	public function test_hooks_into_every_path_that_sets_a_shown_name(): void {
		$names = $this->registered_instance();

		$this->assertSame( 10, has_filter( 'registration_errors', array( $names, 'check_registration' ) ) );
		$this->assertSame( 10, has_filter( 'wpmu_validate_user_signup', array( $names, 'check_signup' ) ) );
		$this->assertSame( 10, has_action( 'user_profile_update_errors', array( $names, 'check_profile' ) ) );
		$this->assertSame( 10, has_filter( 'rest_request_before_callbacks', array( $names, 'check_rest' ) ) );
	}

	public function test_refuses_blocked_usernames_as_question2answer_does(): void {
		$this->assertSame( 'anonymous', Options::get_string( 'users', 'block_bad_usernames' ) );
		$this->assertTrue( Blocked_Names::is_blocked( 'Anonymous' ), 'Case does not matter; multisite refuses capitals before this check.' );

		foreach ( array( 'anonymous', 'anonymous.bob' ) as $login ) {
			$result = register_new_user( $login, 'new-' . md5( $login ) . '@example.org' );
			$this->assertWPError( $result, $login );
			$this->assertContains( 'easyq_blocked_username', $result->get_error_codes(), $login );
		}

		$this->assertIsInt( register_new_user( 'anonymousbob', 'bob@example.org' ), 'Only whole words count, as in Question2Answer.' );

		Options::update( 'users', array( 'block_bad_usernames' => 'admin*, root' ) );
		foreach ( array( 'administrator', 'root' ) as $login ) {
			$result = register_new_user( $login, $login . '@example.org' );
			$this->assertWPError( $result );
			$this->assertContains( 'easyq_blocked_username', $result->get_error_codes(), $login );
		}
		$this->assertIsInt( register_new_user( 'anonymous', 'anon@example.org' ) );
	}

	public function test_refuses_a_blocked_display_name_or_nickname_on_the_profile_screen(): void {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$result = $this->edit_profile( $user_id, array( 'display_name' => 'Anonymous' ) );
		$this->assertWPError( $result );
		$this->assertContains( 'display_name', $result->get_error_codes() );

		$result = $this->edit_profile( $user_id, array( 'nickname' => 'The anonymous one' ) );
		$this->assertWPError( $result );
		$this->assertContains( 'nickname', $result->get_error_codes() );

		$this->assertSame( $user_id, $this->edit_profile( $user_id, array( 'display_name' => 'Ada' ) ) );
		$this->assertSame( 'Ada', get_userdata( $user_id )->display_name );
	}

	public function test_lets_users_keep_an_older_name_and_administrators_choose_any(): void {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		$user_id = self::factory()->user->create(
			array(
				'role'         => 'subscriber',
				'display_name' => 'Anonymous',
				'nickname'     => 'Anonymous',
			)
		);
		wp_set_current_user( $user_id );
		$this->assertSame( $user_id, $this->edit_profile( $user_id, array( 'display_name' => 'Anonymous' ) ), 'An unchanged name does not stop saving the profile.' );

		$other = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $this->administrator() );
		$this->assertSame( $other, $this->edit_profile( $other, array( 'display_name' => 'Anonymous' ) ) );
	}

	public function test_refuses_a_blocked_name_sent_to_the_rest_api(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		// The REST server matches routes regardless of case.
		foreach ( array( '/wp/v2/users/me', '/wp/v2/users/' . $user_id, '/wp/v2/USERS/ME', '/WP/V2/Users/' . $user_id ) as $route ) {
			foreach ( array( 'name', 'nickname' ) as $param ) {
				$response = $this->rest( 'POST', $route, array( $param => 'Anonymous' ) );
				$this->assertSame( 400, $response->get_status(), $route . ' ' . $param );
				$this->assertSame( 'rest_invalid_param', $response->as_error()->get_error_code() );
				$this->assertArrayHasKey( $param, $response->get_data()['data']['params'] );
			}
		}

		$this->assertSame( 200, $this->rest( 'POST', '/wp/v2/users/me', array( 'name' => 'Ada' ) )->get_status() );
		$this->assertSame( 'Ada', get_userdata( $user_id )->display_name );
		$this->assertSame( 200, $this->rest( 'GET', '/wp/v2/users/me', array( 'name' => 'Anonymous' ) )->get_status() );

		wp_set_current_user( $this->administrator() );
		$this->assertSame( 200, $this->rest( 'POST', '/wp/v2/users/' . $user_id, array( 'name' => 'Anonymous' ) )->get_status() );
	}

	public function test_checks_new_users_of_the_rest_api_and_no_other_endpoints(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$names   = new Blocked_Names();
		$request = new WP_REST_Request( 'POST', '/wp/v2/users' );
		$request->set_param( 'name', 'Anonymous' );

		$refused = $names->check_rest( null, array( 'callback' => array( new \WP_REST_Users_Controller(), 'create_item' ) ), $request );
		$this->assertWPError( $refused );
		$this->assertSame( 400, $refused->get_error_data()['status'] );

		$this->assertNull( $names->check_rest( null, array( 'callback' => array( new \WP_REST_Terms_Controller( 'category' ), 'update_item' ) ), $request ), 'A category may be called anything.' );
		$this->assertNull( $names->check_rest( null, array( 'callback' => array( new \WP_REST_Users_Controller(), 'get_item' ) ), $request ) );
	}

	public function test_refuses_blocked_usernames_on_the_network_sign_up(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'The network sign-up exists on multisite only.' );
		}

		wp_set_current_user( 0 );
		$result = wpmu_validate_user_signup( 'anonymous', 'anonymous@example.org' );
		$this->assertContains( 'Sorry, that username is not allowed. Please choose another one.', $result['errors']->get_error_messages( 'user_name' ) );
		$this->assertSame( array(), wpmu_validate_user_signup( 'adalovelace', 'ada@example.org' )['errors']->get_error_messages() );

		wp_set_current_user( $this->administrator() );
		$this->assertNotContains( 'Sorry, that username is not allowed. Please choose another one.', wpmu_validate_user_signup( 'anonymous', 'anonymous@example.org' )['errors']->get_error_messages( 'user_name' ), 'Administrators adding users are not checked.' );
	}

	/**
	 * Saves the profile screen of a user with changed fields.
	 *
	 * @param int                   $user_id User ID.
	 * @param array<string, string> $fields  Changed fields.
	 * @return int|\WP_Error
	 */
	private function edit_profile( int $user_id, array $fields ): int|\WP_Error {
		$user  = get_userdata( $user_id );
		$_POST = $fields + array(
			'email'        => $user->user_email,
			'nickname'     => $user->nickname,
			'display_name' => $user->display_name,
		);

		return edit_user( $user_id );
	}

	/**
	 * Dispatches a request to the REST API.
	 *
	 * @param string               $method HTTP method.
	 * @param string               $route  Route.
	 * @param array<string, mixed> $params Parameters.
	 */
	private function rest( string $method, string $route, array $params ): \WP_REST_Response {
		$request = new WP_REST_Request( $method, $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Returns the instance hooked by the plugin.
	 */
	private function registered_instance(): Blocked_Names {
		global $wp_filter;

		foreach ( $wp_filter['user_profile_update_errors']->callbacks[10] ?? array() as $callback ) {
			if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof Blocked_Names ) {
				return $callback['function'][0];
			}
		}

		$this->fail( 'The name checks are not hooked.' );
	}
}
