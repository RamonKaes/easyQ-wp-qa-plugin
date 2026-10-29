<?php
/**
 * Tests for the response schemas of the REST API.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\REST\Schemas;
use EasyQ\Services;
use EasyQ\Settings\Options;
use WP_REST_Request;
use WP_REST_Response;

/**
 * @covers \EasyQ\REST\Schemas
 * @covers \EasyQ\REST\Post_Schema
 */
final class REST_Schemas_Test extends REST_Test_Case {

	/**
	 * Returns the schema a route announces in its `OPTIONS` response.
	 *
	 * @param string $route Route below easyq/v1.
	 * @return array<string, mixed>
	 */
	private function schema( string $route ): array {
		$data = $this->data( rest_get_server()->dispatch( new WP_REST_Request( 'OPTIONS', '/easyq/v1' . $route ) ) );
		$this->assertArrayHasKey( 'schema', $data, $route );
		$this->assertIsArray( $data['schema'] );

		return $data['schema'];
	}

	/**
	 * Lists the problems of a value against a schema: wrong types and properties the schema does not describe.
	 *
	 * @param mixed                $value  Value.
	 * @param array<string, mixed> $schema Schema.
	 * @param string               $path   Path of the value, for the messages.
	 * @return string[]
	 */
	private function problems( mixed $value, array $schema, string $path = '' ): array {
		$valid = rest_validate_value_from_schema( $value, $schema, '' === $path ? 'response' : $path );
		if ( is_wp_error( $valid ) ) {
			return array( $valid->get_error_message() );
		}

		$problems = array();
		if ( is_array( $value ) && isset( $schema['properties'] ) && is_array( $schema['properties'] ) && ! array_is_list( $value ) ) {
			foreach ( $value as $key => $item ) {
				$property = $schema['properties'][ $key ] ?? null;
				if ( ! is_array( $property ) ) {
					$problems[] = "$path.$key is not in the schema";
					continue;
				}
				$problems = array_merge( $problems, $this->problems( $item, $property, "$path.$key" ) );
			}
		}
		if ( is_array( $value ) && isset( $schema['items'] ) && is_array( $schema['items'] ) && array_is_list( $value ) ) {
			foreach ( $value as $index => $item ) {
				$problems = array_merge( $problems, $this->problems( $item, $schema['items'], "{$path}[$index]" ) );
			}
		}

		return $problems;
	}

	/**
	 * Checks that a successful response matches the schema of its route.
	 *
	 * @param WP_REST_Response $response Response.
	 * @param string           $route    Route below easyq/v1 whose schema applies.
	 * @param bool             $is_list  Whether the response lists items.
	 */
	private function assert_matches( WP_REST_Response $response, string $route, bool $is_list = false ): void {
		$this->assertLessThan( 300, $response->get_status(), $route . ': ' . wp_json_encode( $response->get_data() ) );
		$data   = $this->data( $response );
		$schema = $this->schema( $route );
		$items  = $is_list ? $data : array( $data );
		$this->assertNotSame( array(), $items, $route );

		foreach ( $items as $item ) {
			$this->assertSame( array(), $this->problems( $item, $schema ), $route );
		}
	}

	public function test_every_route_announces_a_response_schema(): void {
		$routes = array_filter(
			array_keys( rest_get_server()->get_routes( 'easyq/v1' ) ),
			static fn ( string $route ): bool => '/easyq/v1' !== $route
		);
		$this->assertGreaterThan( 20, count( $routes ) );

		foreach ( $routes as $route ) {
			$options = rest_get_server()->get_route_options( $route );
			$this->assertIsArray( $options, $route );
			$this->assertIsCallable( $options['schema'] ?? null, $route );
		}
	}

	public function test_responses_match_their_schema(): void {
		wp_set_current_user( $this->administrator() );
		$author   = $this->user();
		$question = $this->ask( $author, 'Question for the schemas', 'Text.', array( 'schemas' ) );
		$answer   = $this->answer( $author, $question );
		$parent   = Services::categories()->create( null, 'Software' );
		$this->assertIsInt( $parent );
		$child = Services::categories()->create( $parent, 'Plugins' );
		$this->assertIsInt( $child );
		$field = $this->data( $this->request( 'GET', '/user-fields' ) )[0]['id'] ?? null;
		$this->assertIsInt( $field );

		$this->assert_matches( $this->request( 'GET', '/categories' ), '/categories', true );
		$this->assert_matches( $this->request( 'GET', '/categories/' . $child ), '/categories/' . $child );
		$this->assert_matches( $this->request( 'POST', '/categories/' . $parent . '/reassign', array( 'to' => $child ) ), '/categories/' . $parent . '/reassign' );
		$this->assert_matches( $this->request( 'GET', '/tags' ), '/tags', true );
		$this->assert_matches( $this->request( 'GET', '/users' ), '/users', true );
		$this->assert_matches( $this->request( 'GET', '/users/me' ), '/users/me' );
		$this->assert_matches( $this->request( 'GET', '/users/' . $author->user_id ), '/users/' . $author->user_id );
		$this->assert_matches( $this->request( 'GET', '/user-fields' ), '/user-fields', true );
		$this->assert_matches( $this->request( 'GET', '/user-fields/' . $field ), '/user-fields/' . $field );
		$this->assert_matches( $this->request( 'GET', '/posts' ), '/posts', true );
		$this->assert_matches( $this->request( 'GET', '/posts/' . $question->id ), '/posts/' . $question->id );
		$this->assert_matches( $this->request( 'GET', '/posts/' . $answer->id ), '/posts/' . $answer->id );
		$this->assert_matches( $this->request( 'POST', '/posts/' . $answer->id . '/vote', array( 'vote' => 1 ) ), '/posts/' . $answer->id . '/vote' );
		$this->assert_matches( $this->request( 'POST', '/posts/' . $question->id . '/actions/flag' ), '/posts/' . $question->id . '/actions/flag' );
		$this->assert_matches( $this->request( 'GET', '/ips/198.51.100.7' ), '/ips/198.51.100.7' );
		$this->assert_matches( $this->request( 'POST', '/ips/198.51.100.7/block' ), '/ips/198.51.100.7/block' );
		$this->assert_matches( $this->request( 'POST', '/ips/198.51.100.7/unblock' ), '/ips/198.51.100.7/unblock' );
		$this->assert_matches( $this->request( 'POST', '/ips/198.51.100.7/hide-all' ), '/ips/198.51.100.7/hide-all' );
		$this->assert_matches( $this->request( 'POST', '/tools/recount_posts' ), '/tools/recount_posts' );

		Options::update( 'posting', array( 'do_ask_check_qs' => true ) );
		$similar = $this->request( 'GET', '/questions/similar', array( 'title' => 'Question for the schemas' ) );
		$this->assertNotSame( array(), $this->data( $similar )['questions'] );
		$this->assert_matches( $similar, '/questions/similar' );
	}

	public function test_the_context_selects_the_fields_as_in_core(): void {
		$author   = $this->user();
		$question = $this->ask( $author, 'Question in contexts', 'Text in contexts.' );

		// Lists show users as they are embedded; single users with their profile.
		$listed = $this->data( $this->request( 'GET', '/users' ) )[0];
		$this->assertArrayHasKey( 'name', $listed );
		$this->assertArrayNotHasKey( 'counts', $listed );
		$this->assertArrayHasKey( 'counts', $this->data( $this->request( 'GET', '/users/' . $author->user_id ) ) );
		$this->assertArrayNotHasKey( 'counts', $this->data( $this->request( 'GET', '/users/' . $author->user_id, array( 'context' => 'embed' ) ) ) );

		$embedded = $this->data( $this->request( 'GET', '/questions/' . $question->id, array( 'context' => 'embed' ) ) );
		$this->assertArrayHasKey( 'title', $embedded );
		$this->assertArrayNotHasKey( 'votes', $embedded );
		$this->assertArrayNotHasKey( 'content', $embedded );

		// The raw text only in the edit context, and only for users who may edit.
		wp_set_current_user( (int) $author->user_id );
		$this->assertArrayNotHasKey( 'raw', $this->data( $this->request( 'GET', '/questions/' . $question->id ) )['content'] );
		$this->assertSame( 'Text in contexts.', $this->data( $this->request( 'GET', '/questions/' . $question->id, array( 'context' => 'edit' ) ) )['content']['raw'] );
		wp_set_current_user( (int) $this->user()->user_id );
		$this->assertArrayNotHasKey( 'raw', $this->data( $this->request( 'GET', '/questions/' . $question->id, array( 'context' => 'edit' ) ) )['content'] );

		// Contexts the schema does not name are refused, as core refuses them.
		$this->assertSame( 400, $this->request( 'GET', '/categories', array( 'context' => 'edit' ) )->get_status() );
	}

	public function test_fields_of_register_rest_field_and_fields_parameter_work_as_in_core(): void {
		$category = Services::categories()->create( null, 'Software' );
		$this->assertIsInt( $category );
		register_rest_field(
			'easyq-category',
			'color',
			array(
				'get_callback' => static fn ( array $item ): string => 'red-' . $item['id'],
				'schema'       => array(
					'description' => 'Color of the category.',
					'type'        => 'string',
					'context'     => array( 'view' ),
				),
			)
		);

		$this->assertSame( 'red-' . $category, $this->data( $this->request( 'GET', '/categories/' . $category ) )['color'] );
		$this->assertArrayHasKey( 'color', $this->schema( '/categories/' . $category )['properties'] );
		$this->assertSame( array( 'id', 'title' ), array_keys( $this->data( rest_get_server()->dispatch( self::with_fields( '/categories/' . $category, 'id,title' ) ) ) ) );
	}

	public function test_deleting_responds_with_the_previous_item_as_in_core(): void {
		wp_set_current_user( $this->administrator() );
		$category = Services::categories()->create( null, 'Gone soon' );
		$this->assertIsInt( $category );
		$schema = $this->schema( '/categories/' . $category );

		$deleted = $this->data( $this->request( 'DELETE', '/categories/' . $category ) );

		$this->assertTrue( $deleted['deleted'] );
		$this->assertIsArray( $deleted['previous'] );
		$this->assertSame( 'Gone soon', $deleted['previous']['title'] );
		$this->assertSame( array(), $this->problems( $deleted['previous'], $schema ) );
	}

	/**
	 * Returns a GET request with the `_fields` parameter.
	 *
	 * @param string $route  Route below easyq/v1.
	 * @param string $fields Fields, separated by commas.
	 * @return WP_REST_Request<array<string, mixed>>
	 */
	private static function with_fields( string $route, string $fields ): WP_REST_Request {
		$request = new WP_REST_Request( 'GET', '/easyq/v1' . $route );
		$request->set_param( '_fields', $fields );

		return $request;
	}

	public function test_the_check_finds_undescribed_and_mistyped_values(): void {
		$schema = Schemas::tag();

		$this->assertSame(
			array(),
			$this->problems(
				array(
					'name'  => 'php',
					'count' => 2,
				),
				$schema
			)
		);
		$this->assertSame(
			array( '.extra is not in the schema' ),
			$this->problems(
				array(
					'name'  => 'php',
					'extra' => true,
				),
				$schema
			)
		);
		$this->assertNotSame( array(), $this->problems( array( 'count' => 'two' ), $schema ) );
		$this->assertNotSame( array(), $this->problems( array( 'path' => array( 1 ) ), Schemas::category() ) );
	}
}
