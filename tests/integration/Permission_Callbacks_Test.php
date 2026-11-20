<?php
/**
 * Tests that the routes of messages and favorites refuse in their permission callback.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Actor;
use EasyQ\Services;
use EasyQ\Settings\Options;
use EasyQ\Users\User_Status;
use WP_REST_Request;

/**
 * Core asks for the rights in the permission callback, so that `OPTIONS` and the target
 * hints offer a method only to those who may use it; the handler then only acts.
 *
 * @covers \EasyQ\REST\Messages_Controller
 * @covers \EasyQ\REST\Favorites_Controller
 * @covers \EasyQ\Favorites\Favorites
 */
final class Permission_Callbacks_Test extends REST_Test_Case {

	/**
	 * Runs the permission callback of a route and method, without the handler.
	 *
	 * @param string               $method Method.
	 * @param string               $route  Route below the namespace.
	 * @param array<string, mixed> $params Parameters.
	 * @return true|\WP_Error
	 */
	private function permission( string $method, string $route, array $params = array() ): bool|\WP_Error {
		$request = new WP_REST_Request( $method, '/easyq/v1' . $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		$server = rest_get_server();
		foreach ( $server->get_routes() as $pattern => $handlers ) {
			if ( 1 !== preg_match( '@^' . $pattern . '$@i', '/easyq/v1' . $route, $matches ) ) {
				continue;
			}
			foreach ( $handlers as $handler ) {
				if ( ! empty( $handler['methods'][ $method ] ) ) {
					$request->set_url_params( array_filter( $matches, 'is_string', ARRAY_FILTER_USE_KEY ) );

					return call_user_func( $handler['permission_callback'], $request );
				}
			}
		}

		$this->fail( "No route for $method $route" );
	}

	public function test_sending_a_message_is_refused_before_the_handler(): void {
		Options::update( 'users', array( 'allow_private_messages' => true ) );
		$to     = $this->take_part( (int) $this->user()->user_id );
		$sender = $this->take_part( $this->login() );

		$this->assertTrue( $this->permission( 'POST', '/messages', array( 'to' => $to ) ) );
		$this->assertSame( 'easyq_not_found', $this->permission( 'POST', '/messages', array( 'to' => 999999 ) )->get_error_code() );
		$this->assertSame( 'easyq_self', $this->permission( 'POST', '/messages', array( 'to' => $sender ) )->get_error_code() );

		User_Status::block( Actor::system(), $sender );
		$this->assertSame( 'easyq_userblock', $this->permission( 'POST', '/messages', array( 'to' => $to ) )->get_error_code() );

		Options::update( 'users', array( 'allow_private_messages' => false ) );
		$this->assertSame( 'easyq_messages_off', $this->permission( 'POST', '/messages', array( 'to' => $to ) )->get_error_code() );
	}

	public function test_deleting_a_message_is_refused_to_others_before_the_handler(): void {
		Options::update(
			'users',
			array(
				'allow_private_messages' => true,
				'show_message_history'   => true,
			)
		);
		$from = $this->user();
		$to   = get_userdata( (int) $this->user()->user_id );
		$this->assertInstanceOf( \WP_User::class, $to );
		$id = Services::messages()->send( $from, $to, 'Hello there.' );
		$this->assertIsInt( $id );

		$this->login();
		$this->assertSame( 'easyq_not_found', $this->permission( 'DELETE', '/messages/' . $id )->get_error_code() );

		wp_set_current_user( $to->ID );
		$this->assertTrue( $this->permission( 'DELETE', '/messages/' . $id ) );
	}

	public function test_favorites_are_refused_before_the_handler(): void {
		$question = $this->ask( $this->user() );
		$user_id  = $this->login();

		$this->assertTrue(
			$this->permission(
				'POST',
				'/favorites',
				array(
					'type' => 'question',
					'id'   => $question->id,
				)
			)
		);
		$this->assertSame(
			404,
			$this->permission(
				'POST',
				'/favorites',
				array(
					'type' => 'question',
					'id'   => 999999,
				)
			)->get_error_data()['status']
		);
		$self = $this->permission(
			'POST',
			'/favorites',
			array(
				'type' => 'user',
				'id'   => $user_id,
			)
		);
		$this->assertSame( array( 'easyq_favorite_self', 400 ), array( $self->get_error_code(), $self->get_error_data()['status'] ) );
		$this->assertSame(
			404,
			$this->permission(
				'DELETE',
				'/favorites',
				array(
					'type' => 'question',
					'id'   => 999999,
				)
			)->get_error_data()['status']
		);

		wp_set_current_user( 0 );
		$this->assertSame(
			401,
			$this->permission(
				'POST',
				'/favorites',
				array(
					'type' => 'question',
					'id'   => $question->id,
				)
			)->get_error_data()['status']
		);
	}
}
