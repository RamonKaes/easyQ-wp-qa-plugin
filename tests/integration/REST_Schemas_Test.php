<?php
/**
 * Tests for the response schemas of the REST API.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Mail\Unsubscribe;
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
				// The links and embedded items wrap an item, also one listed inside another response, as
				// in core, whose schemas do not describe them.
				if ( in_array( $key, array( '_links', '_embedded' ), true ) ) {
					continue;
				}
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
		$this->assert_matches( $this->request( 'GET', '/email-templates' ), '/email-templates', true );
		$this->assert_matches( $this->request( 'GET', '/email-templates/flagged' ), '/email-templates/flagged' );
		$this->assert_matches( $this->request( 'POST', '/email-templates/flagged/test' ), '/email-templates/flagged/test' );
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
		$this->assert_matches(
			$this->request(
				'POST',
				'/unsubscribe',
				array(
					'post'  => $answer->id,
					'token' => Unsubscribe::token( $answer->id ),
				)
			),
			'/unsubscribe'
		);

		$favorite = array(
			'type' => 'user',
			'id'   => (int) $author->user_id,
		);
		$this->assert_matches( $this->request( 'POST', '/favorites', $favorite ), '/favorites' );
		$this->assert_matches( $this->request( 'GET', '/favorites', array( 'type' => 'user' ) ), '/favorites', true );

		Options::update( 'posting', array( 'do_ask_check_qs' => true ) );
		$similar = $this->request( 'GET', '/questions/similar', array( 'title' => 'Question for the schemas' ) );
		$this->assertNotSame( array(), $this->data( $similar )['questions'] );
		$this->assert_matches( $similar, '/questions/similar' );
	}

	/**
	 * Returns the object type of the schema of the route that answers a path.
	 *
	 * @param string $path Path below easyq/v1.
	 * @return string
	 */
	private static function object_type( string $path ): string {
		foreach ( rest_get_server()->get_routes( 'easyq/v1' ) as $route => $handlers ) {
			$callback = $handlers[0]['callback'] ?? null;
			if ( 1 === preg_match( '@^' . $route . '$@', '/easyq/v1' . $path ) && is_array( $callback ) && is_object( $callback[0] ) && method_exists( $callback[0], 'get_item_schema' ) ) {
				return (string) ( $callback[0]->get_item_schema()['title'] ?? '' );
			}
		}

		return '';
	}

	public function test_what_a_route_accepts_is_not_read_only_and_every_path_part_is_described(): void {
		$readonly    = array();
		$undescribed = array();
		foreach ( rest_get_server()->get_routes( 'easyq/v1' ) as $route => $handlers ) {
			$schema = rest_get_server()->get_route_options( $route )['schema'] ?? null;
			$schema = is_callable( $schema ) ? (array) call_user_func( $schema ) : array();
			preg_match_all( '/\(\?P<(\w+)>/', $route, $parts );
			foreach ( $handlers as $handler ) {
				$methods = array_keys( array_filter( (array) ( $handler['methods'] ?? array() ) ) );
				foreach ( $parts[1] as $part ) {
					// As core describes `id` on each route that takes it.
					if ( empty( $handler['args'][ $part ]['description'] ) || empty( $handler['args'][ $part ]['type'] ) ) {
						$undescribed[] = "$route: $part";
					}
				}
				// The routes that create or change the item itself, as core's schemas mean read-only.
				$callback = $handler['callback'] ?? null;
				if ( ! is_array( $callback ) || ! in_array( $callback[1] ?? '', array( 'create_item', 'update_item' ), true ) ) {
					continue;
				}
				foreach ( array_keys( (array) ( $handler['args'] ?? array() ) ) as $arg ) {
					if ( ! empty( $schema['properties'][ $arg ]['readonly'] ) && ! in_array( $arg, $parts[1], true ) ) {
						$readonly[] = "$route: $arg";
					}
				}
			}
		}

		$this->assertSame( array(), array_values( array_unique( $readonly ) ), 'Properties that a route accepts but the schema calls read-only.' );
		$this->assertSame( array(), array_values( array_unique( $undescribed ) ), 'Parts of the path without a description and a type.' );
	}

	public function test_every_item_links_itself_and_can_be_changed_by_its_filter_as_in_core(): void {
		$author   = $this->user();
		$question = $this->ask( $author );
		$answer   = $this->answer( $this->user(), $question );
		$category = Services::categories()->create( null, 'Linked' );
		$field    = Services::user_fields()->create( 'linked', 'Linked', 0, null );
		$this->take_part( (int) $author->user_id );
		wp_set_current_user( $this->administrator() );
		// Every route that reads one item; the test of the routes checks that this list is complete.
		$items   = array(
			'/questions/' . $question->id,
			'/answers/' . $answer->id,
			'/comments/' . $this->comment( $author, $question, $answer )->id,
			'/posts/' . $answer->id,
			'/categories/' . $category,
			'/user-fields/' . (int) $field,
			'/users/' . $author->user_id,
			'/email-templates/flagged',
			'/ips/192.0.2.1',
		);
		$wrong   = array();
		$touched = array();
		$mark    = static function ( \WP_REST_Response $response ) use ( &$touched ): \WP_REST_Response {
			$touched[] = current_filter();
			$response->header( 'X-Filtered', '1' );
			return $response;
		};

		foreach ( $items as $path ) {
			$response = $this->request( 'GET', $path );
			if ( 200 !== $response->get_status() ) {
				$wrong[] = "$path: " . $response->get_status();
				continue;
			}
			// The canonical address: a post read through `/posts/{id}` links to the route of its type.
			$canonical = str_starts_with( $path, '/posts/' ) ? '/answers/' . $answer->id : $path;
			if ( rest_url( 'easyq/v1' . $canonical ) !== ( $response->get_links()['self'][0]['href'] ?? null ) ) {
				$wrong[] = "$path: no link to itself";
			}
			$filter = 'easyq_rest_prepare_' . substr( self::object_type( $path ), strlen( 'easyq-' ) );
			add_filter( $filter, $mark );
			$filtered = $this->request( 'GET', $path );
			remove_filter( $filter, $mark );
			if ( '1' !== ( $filtered->get_headers()['X-Filtered'] ?? null ) ) {
				$wrong[] = "$path: `$filter` did not run";
			}
		}

		$links = $this->request( 'GET', '/answers/' . $answer->id )->get_links();
		$this->assertSame( rest_url( 'easyq/v1/users/' . $answer->user_id ), $links['author'][0]['href'] ?? null );
		$this->assertTrue( $links['author'][0]['attributes']['embeddable'] ?? false );
		$this->assertSame( rest_url( 'easyq/v1/posts/' . $question->id ), $links['up'][0]['href'] ?? null );
		$this->assertSame( array(), $wrong );
		$this->assertCount( count( $items ), $touched );
	}

	public function test_the_list_of_single_items_names_every_route_that_reads_one(): void {
		$single = array();
		foreach ( rest_get_server()->get_routes( 'easyq/v1' ) as $route => $handlers ) {
			foreach ( $handlers as $handler ) {
				if ( ! empty( ( $handler['methods'] ?? array() )['GET'] ) && 1 === preg_match( '/\(\?P<(id|ip|name)>[^)]*\)$/', $route ) ) {
					$single[] = (string) preg_replace( '/^\/easyq\/v1\/([a-z-]+)\/.*$/', '$1', $route );
				}
			}
		}
		sort( $single );

		$this->assertSame( array( 'answers', 'categories', 'comments', 'email-templates', 'ips', 'posts', 'questions', 'user-fields', 'users' ), array_values( array_unique( $single ) ) );
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
		// Others are refused the edit context, as core refuses it (`rest_forbidden_context`).
		wp_set_current_user( (int) $this->user()->user_id );
		$this->assertSame( 'rest_forbidden_context', $this->request( 'GET', '/questions/' . $question->id, array( 'context' => 'edit' ) )->as_error()?->get_error_code() );

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

		$deleted = $this->data( $this->request( 'DELETE', '/categories/' . $category, array( 'force' => true ) ) );

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
