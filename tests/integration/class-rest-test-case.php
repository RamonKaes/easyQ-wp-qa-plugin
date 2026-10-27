<?php
/**
 * Base class for REST API tests.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use WP_REST_Request;
use WP_REST_Response;

/**
 * Sends requests to the easyQ REST API.
 */
abstract class REST_Test_Case extends Test_Case {

	/**
	 * Sets up the REST server.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new \Spy_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	/**
	 * Removes the REST server.
	 */
	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		unset( $_COOKIE['easyq_visitor'] );

		parent::tear_down();
	}

	/**
	 * Sends a request.
	 *
	 * @param string               $method HTTP method.
	 * @param string               $route  Route below easyq/v1.
	 * @param array<string, mixed> $params Parameters.
	 * @return WP_REST_Response
	 */
	protected function request( string $method, string $route, array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/easyq/v1' . $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Returns the data of a response as an array.
	 *
	 * @param WP_REST_Response $response Response.
	 * @return array<mixed>
	 */
	protected function data( WP_REST_Response $response ): array {
		$data = $response->get_data();
		$this->assertIsArray( $data );

		return $data;
	}

	/**
	 * Logs in a new user and returns the user ID.
	 *
	 * @param string $role Role.
	 * @return int
	 */
	protected function login( string $role = 'subscriber' ): int {
		$user_id = self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $user_id );

		return $user_id;
	}
}
