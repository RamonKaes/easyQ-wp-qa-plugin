<?php
/**
 * Tests that every route of the REST API holds for every role.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Database\Schema;
use EasyQ\Services;
use EasyQ\Settings\Options;
use WP_REST_Request;

/**
 * @coversNothing
 */
final class REST_Roles_Test extends REST_Test_Case {

	/**
	 * Routes that visitors may use to write, with the reason.
	 *
	 * @var array<string, string>
	 */
	private const OPEN_TO_VISITORS = array(
		'POST /questions'            => 'Depends on the permission settings.',
		'POST /questions/{}/answers' => 'Depends on the permission settings.',
		'POST /posts/{}/comments'    => 'Depends on the permission settings.',
		'POST /posts/{}/vote'        => 'Answers with the reason in the check of the permission.',
		'POST /posts/{}/actions/{}'  => 'Answers with the reason in the check of the permission.',
		'DELETE /notices/{}'         => 'First-time visitors dismiss their notice.',
	);

	/**
	 * Returns a route with its parameters filled in.
	 *
	 * @param string   $route  Route.
	 * @param int      $post   Post for the parameters of posts.
	 * @param string[] $names  Receives the names of the parameters.
	 * @return string
	 */
	private function filled( string $route, int $post, array &$names = array() ): string {
		return (string) preg_replace_callback(
			'/\(\?P<(\w+)>(?:[^()]|\([^()]*\))*\)/',
			static function ( array $parts ) use ( $post, &$names ): string {
				$names[] = $parts[1];
				// A parameter that lists its values, such as `start|cancel|test`, takes the first.
				$pattern = substr( $parts[0], strlen( '(?P<' . $parts[1] . '>' ), -1 );
				if ( 1 === preg_match( '/^[a-z_-]+(\|[a-z_-]+)+$/', $pattern ) ) {
					return explode( '|', $pattern )[0];
				}

				return match ( $parts[1] ) {
					'ip'     => '198.51.100.9',
					'tool'   => 'recount_posts',
					'name'   => 'flagged',
					'action' => 'hide',
					default  => (string) $post,
				};
			},
			$route
		);
	}

	/**
	 * Returns the write methods of a route.
	 *
	 * @param array<int, array<string, mixed>> $handlers Handlers.
	 * @return string[]
	 */
	private static function write_methods( array $handlers ): array {
		$methods = array();
		foreach ( $handlers as $handler ) {
			$methods = array_merge( $methods, array_keys( array_filter( (array) ( $handler['methods'] ?? array() ) ) ) );
		}

		return array_values( array_diff( array_unique( $methods ), array( 'GET', 'HEAD' ) ) );
	}

	public function test_no_route_fails_and_visitors_cannot_write(): void {
		$question = $this->ask( $this->user() );
		$this->answer( $this->user(), $question );
		$subscriber = (int) $this->user()->user_id;
		$server     = rest_get_server();
		$broken     = array();
		$written    = array();
		$checked    = 0;

		foreach ( $server->get_routes( 'easyq/v1' ) as $route => $handlers ) {
			foreach ( array(
				'guest'      => 0,
				'subscriber' => $subscriber,
			) as $who => $user_id ) {
				wp_set_current_user( $user_id );
				foreach ( self::write_methods( $handlers ) as $method ) {
					// Each request on a post of its own would hide or delete it for the next route.
					$post    = $this->ask( $this->user(), "Target of $method" )->id;
					$request = new WP_REST_Request( $method, $this->filled( $route, $post ) );
					$status  = $server->dispatch( $request )->get_status();
					++$checked;

					if ( $status >= 500 ) {
						$broken[] = "$who $method $route: $status";
					}

					$key = $method . ' ' . (string) preg_replace( array( '#^/easyq/v1#', '/\(\?P<\w+>(?:[^()]|\([^()]*\))*\)/' ), array( '', '{}' ), $route );
					if ( 'guest' === $who && $status < 400 && ! isset( self::OPEN_TO_VISITORS[ $key ] ) ) {
						$written[] = "$key: $status";
					}
				}
			}
		}

		$this->assertGreaterThan( 30, $checked );
		$this->assertSame( array(), $broken );
		$this->assertSame( array(), $written, 'Visitors can write here.' );
	}

	public function test_writing_routes_check_in_their_permission_callback(): void {
		$open = array();
		foreach ( rest_get_server()->get_routes( 'easyq/v1' ) as $route => $handlers ) {
			foreach ( $handlers as $handler ) {
				// The REST API handbook keeps `__return_true` for public routes that only read.
				if ( array() !== self::write_methods( array( $handler ) ) && '__return_true' === ( $handler['permission_callback'] ?? null ) ) {
					$open[] = $route;
				}
			}
		}

		$this->assertSame( array(), $open );
	}

	/**
	 * Returns a valid value for an argument of a route, from its schema.
	 *
	 * @param string               $name     Argument.
	 * @param array<string, mixed> $schema   Schema of the argument.
	 * @param int                  $category Category for integer arguments.
	 * @return mixed
	 */
	private static function value_for( string $name, array $schema, int $category ): mixed {
		$types  = (array) ( $schema['type'] ?? 'string' );
		$format = $schema['format'] ?? '';

		return match ( true ) {
			isset( $schema['enum'] ) && is_array( $schema['enum'] ) => end( $schema['enum'] ),
			in_array( 'boolean', $types, true ) => false,
			in_array( 'array', $types, true ) => array( 'changed' ),
			in_array( 'integer', $types, true ) => $category,
			'email' === $format => 'changed@example.org',
			default => "Changed through $name by someone without the right.",
		};
	}

	/**
	 * Returns the stored row of a post.
	 *
	 * @param int $id Post ID.
	 * @return array<string, mixed>
	 */
	private static function row( int $id ): array {
		global $wpdb;

		$table = Schema::table( 'posts' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The row as stored, in a test.
		return (array) $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
	}

	public function test_posts_can_only_be_edited_field_by_field_with_the_right(): void {
		$author   = $this->user();
		$service  = Services::post_service();
		$question = $this->post( $service->create_question( $author, 'How do I configure the widget?', 'I tried everything.', '', array( 'widgets' ), null, '@' ) );
		$answer   = $this->post( $service->create_answer( $author, $question, 'Open the settings screen.', '', '@' ) );
		// Every field holds a value that a request could change, the notifications included.
		$posts    = array( $question->id, $answer->id, $service->create_comment( $author, $question, $answer, 'Thanks, that helped.', '', '@' ) );
		$category = Services::categories()->create( null, 'Changed' );
		$this->assertIsInt( $category );
		$others  = array(
			'visitor'    => 0,
			'subscriber' => (int) $this->user()->user_id,
		);
		$checked = array();
		$changed = array();

		foreach ( rest_get_server()->get_routes( 'easyq/v1' ) as $route => $handlers ) {
			foreach ( $handlers as $handler ) {
				if ( ! isset( ( $handler['methods'] ?? array() )['PUT'] ) || ! str_contains( $route, '(?P<id>' ) ) {
					continue;
				}

				// The post the route edits: the one an administrator can change without fields.
				wp_set_current_user( $this->administrator() );
				$target = null;
				foreach ( $posts as $post ) {
					if ( 200 === $this->request( 'PUT', $this->filled( substr( $route, strlen( '/easyq/v1' ) ), $post ) )->get_status() ) {
						$target = $post;
					}
				}
				if ( null === $target ) {
					continue;
				}

				$path   = $this->filled( substr( $route, strlen( '/easyq/v1' ) ), $target );
				$fields = array_diff( array_keys( (array) ( $handler['args'] ?? array() ) ), array( 'silent' ) );
				$before = self::row( $target );
				foreach ( $others as $who => $user_id ) {
					wp_set_current_user( $user_id );
					$requests = array( 'no field' => array() );
					foreach ( $fields as $field ) {
						$requests[ $field ] = array( $field => self::value_for( $field, (array) $handler['args'][ $field ], $category ) );
					}
					foreach ( $requests as $label => $params ) {
						$status    = $this->request( 'PUT', $path, $params )->get_status();
						$checked[] = "$who PUT $path $label";
						if ( 400 > $status || self::row( $target ) !== $before ) {
							$changed[] = "$who PUT $route $label: $status";
						}
					}
				}
			}
		}

		$this->assertGreaterThanOrEqual( 3 * 2 * 6, count( $checked ), 'The routes that edit questions, answers and comments were not found.' );
		$this->assertSame( array(), $changed );
	}

	public function test_only_the_author_changes_the_notifications_of_a_post(): void {
		$author   = $this->user();
		$service  = Services::post_service();
		$question = $this->post( $service->create_question( $author, 'How do I configure the widget?', 'I tried everything.', '', array( 'widgets' ), null, '@' ) );
		$answer   = $this->post( $service->create_answer( $author, $question, 'Open the settings screen.', '', '@' ) );
		$comment  = $this->post( $service->create_comment( $author, $question, $answer, 'Thanks, that helped.', '', '@' ) );

		foreach ( array(
			'/questions/' => $question->id,
			'/answers/'   => $answer->id,
			'/comments/'  => $comment->id,
		) as $route => $id ) {
			wp_set_current_user( $this->administrator() );
			$response = $this->request( 'PUT', $route . $id, array( 'notify' => false ) );
			$this->assertSame( 'easyq_author', $response->as_error()->get_error_code(), $route );
			$this->assertSame( 403, $response->get_status(), $route );
			$this->assertSame( 200, $this->request( 'PUT', $route . $id, array( 'content' => 'Edited by the administrator, long enough.' ) )->get_status(), $route );
			$this->assertSame( '@', $this->post( $id )->notify, $route );

			wp_set_current_user( (int) $author->user_id );
			$this->assertSame( 200, $this->request( 'PUT', $route . $id, array( 'notify' => false ) )->get_status(), $route );
			$this->assertNull( $this->post( $id )->notify, $route );
		}
	}

	public function test_the_right_to_retag_allows_tags_and_category_only(): void {
		$question = $this->ask( $this->user() );
		$retagger = $this->user();
		Options::update( 'permissions', array( 'permit_retag_cat' => \EasyQ\Users\Permits::USERS ) );
		wp_set_current_user( (int) $retagger->user_id );

		$this->assertSame( 200, $this->request( 'PUT', '/questions/' . $question->id, array( 'tags' => array( 'retagged' ) ) )->get_status() );
		$this->assertSame( array( 'retagged' ), $this->post( $question->id )->tag_list() );
		$this->assertSame( 200, $this->request( 'PUT', '/questions/' . $question->id )->get_status() );
		$this->assertSame( 403, $this->request( 'PUT', '/questions/' . $question->id, array( 'title' => 'A title by the retagger here' ) )->get_status() );
		$this->assertSame(
			403,
			$this->request(
				'PUT',
				'/questions/' . $question->id,
				array(
					'tags'    => array( 'both' ),
					'content' => 'Content by the retagger.',
				)
			)->get_status()
		);
		$this->assertSame( array( 'retagged' ), $this->post( $question->id )->tag_list() );
	}

	/**
	 * Sends a request to every route that takes a post, on every method, with the given post,
	 * and to the actions route with every action.
	 *
	 * @param int $post Post ID.
	 * @return array<string, int> Status by method and route.
	 */
	private function every_route_with( int $post ): array {
		$actions  = array_keys( (array) ( new \ReflectionClassConstant( \EasyQ\REST\Posts_Controller::class, 'ACTIONS' ) )->getValue() );
		$statuses = array();
		$routes   = array();
		foreach ( rest_get_server()->get_routes( 'easyq/v1' ) as $route => $handlers ) {
			// The routes that take a post: their controller loads it with `visible_post()`.
			$callback = $handlers[0]['callback'] ?? null;
			$file     = is_array( $callback ) && is_object( $callback[0] ) ? (string) ( new \ReflectionClass( $callback[0] ) )->getFileName() : '';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file in a test.
			if ( 1 !== preg_match( '/\(\?P<\w+>/', $route ) || '' === $file || ! str_contains( (string) file_get_contents( $file ), '->visible_post(' ) ) {
				continue;
			}
			$routes[] = $route;
			$methods  = array();
			foreach ( $handlers as $handler ) {
				$methods = array_merge( $methods, array_keys( array_filter( (array) ( $handler['methods'] ?? array() ) ) ) );
			}
			foreach ( array_diff( array_unique( $methods ), array( 'HEAD' ) ) as $method ) {
				foreach ( str_contains( $route, '(?P<action>' ) ? $actions : array( 'hide' ) as $action ) {
					$path                        = (string) preg_replace( '/\(\?P<action>[^)]*\)/', $action, substr( $route, strlen( '/easyq/v1' ) ) );
					$path                        = $this->filled( $path, $post );
					$statuses[ "$method $path" ] = $this->request(
						$method,
						$path,
						array(
							'content' => 'A comment on what nobody may see.',
							'answer'  => $post,
						)
					)->get_status();
				}
			}
		}

		$this->assertGreaterThanOrEqual( 8, count( $routes ), 'The routes that take a post were not found.' );

		return $statuses;
	}

	public function test_posts_below_a_hidden_post_are_hidden_on_every_route(): void {
		$question = $this->ask( $this->user() );
		$answer   = $this->answer( $this->user(), $question );
		$comment  = $this->comment( $this->user(), $question, $answer );
		Services::post_service()->set_answer_status( \EasyQ\Actor::system(), $this->post( $answer->id ), $this->post( $question->id ), \EasyQ\Posts\Post::STATUS_HIDDEN );
		$before = array(
			$answer->id  => self::row( $answer->id ),
			$comment->id => self::row( $comment->id ),
		);
		$shown  = array();

		foreach ( array( 0, (int) $this->user()->user_id ) as $user_id ) {
			foreach ( array( $answer->id, $comment->id ) as $post ) {
				wp_set_current_user( $user_id );
				foreach ( $this->every_route_with( $post ) as $request => $status ) {
					if ( 400 > $status ) {
						$shown[] = "user $user_id, post $post: $request $status";
					}
				}
			}
		}

		$this->assertSame( array(), $shown );
		$this->assertSame( $before[ $answer->id ], self::row( $answer->id ) );
		$this->assertSame( $before[ $comment->id ], self::row( $comment->id ) );

		wp_set_current_user( $this->administrator() );
		$this->assertSame( 200, $this->request( 'GET', '/comments/' . $comment->id )->get_status(), 'Moderators still see it.' );
	}

	public function test_the_closing_note_is_read_with_its_question_and_changed_by_nothing_else(): void {
		$question = $this->ask( $this->user() );
		Services::post_service()->close_with_note( \EasyQ\Actor::system(), $question, 'Closed because it is answered elsewhere.' );
		$note = (int) $this->post( $question->id )->closed_by_id;
		$this->assertGreaterThan( 0, $note );
		$before = self::row( $note );

		wp_set_current_user( $this->administrator() );
		$changed = array();
		foreach ( $this->every_route_with( $note ) as $request => $status ) {
			if ( 400 > $status && ! str_starts_with( $request, 'GET ' ) ) {
				$changed[] = "$request $status";
			}
		}

		$this->assertSame( array(), $changed );
		$this->assertSame( $before, self::row( $note ) );
		$this->assertSame( 200, $this->request( 'GET', '/posts/' . $note )->get_status() );
		$this->assertSame( $note, $this->post( $question->id )->closed_by_id );
	}

	/**
	 * Returns valid values for the required arguments of the handlers of a route and method, so
	 * that the request passes the validation of core, which runs before any other check.
	 *
	 * @param array<int, array<string, mixed>> $handlers Handlers of the route.
	 * @param string                           $method   HTTP method.
	 * @param int                              $category Category for integer arguments.
	 * @return array<string, mixed>
	 */
	private static function required_params( array $handlers, string $method, int $category ): array {
		$params = array();
		foreach ( $handlers as $handler ) {
			if ( empty( ( $handler['methods'] ?? array() )[ $method ] ) ) {
				continue;
			}
			foreach ( (array) ( $handler['args'] ?? array() ) as $name => $schema ) {
				if ( is_array( $schema ) && ! empty( $schema['required'] ) ) {
					$params[ (string) $name ] = self::value_for( (string) $name, $schema, $category );
				}
			}
		}

		return $params;
	}

	public function test_the_read_only_mode_refuses_every_change_except_for_managers(): void {
		$question = $this->ask( $this->user() );
		$category = Services::categories()->create( null, 'Read only' );
		$this->assertIsInt( $category );
		Options::update( 'general', array( 'read_only' => true ) );
		$users   = array(
			'visitor'    => 0,
			'subscriber' => (int) $this->user()->user_id,
			'editor'     => (int) $this->user( 'editor' )->user_id,
		);
		$open    = array();
		$checked = 0;

		foreach ( rest_get_server()->get_routes( 'easyq/v1' ) as $route => $handlers ) {
			foreach ( self::write_methods( $handlers ) as $method ) {
				$path   = $this->filled( substr( $route, strlen( '/easyq/v1' ) ), $question->id );
				$params = self::required_params( $handlers, $method, $category );
				foreach ( $users as $who => $user_id ) {
					wp_set_current_user( $user_id );
					$code = $this->request( $method, $path, $params )->as_error()?->get_error_code();
					if ( 'easyq_read_only' !== $code ) {
						$open[] = "$who $method $route: " . (string) $code;
					}
					++$checked;
				}

				wp_set_current_user( $this->administrator() );
				if ( 'easyq_read_only' === $this->request( $method, $path, $params )->as_error()?->get_error_code() ) {
					$open[] = "administrator $method $route: refused";
				}
			}
		}

		// Reading stays open to everybody.
		wp_set_current_user( 0 );
		$read = 0;
		foreach ( rest_get_server()->get_routes( 'easyq/v1' ) as $route => $handlers ) {
			foreach ( $handlers as $handler ) {
				if ( ! empty( ( $handler['methods'] ?? array() )['GET'] ) ) {
					$path = $this->filled( substr( $route, strlen( '/easyq/v1' ) ), $question->id );
					if ( 'easyq_read_only' === $this->request( 'GET', $path )->as_error()?->get_error_code() ) {
						$open[] = "visitor GET $route: refused";
					}
					++$read;
				}
			}
		}

		$this->assertGreaterThan( 3 * 40, $checked );
		$this->assertGreaterThan( 20, $read );
		$this->assertSame( array(), $open );
	}

	public function test_subscribers_cannot_use_what_needs_more_rights(): void {
		$question = $this->ask( $this->user() );
		wp_set_current_user( (int) $this->user()->user_id );

		$category = Services::categories()->create( null, 'Protected' );
		$this->assertIsInt( $category );

		foreach (
			array(
				array( 'POST', '/categories' ),
				array( 'DELETE', '/categories/' . $category ),
				array( 'POST', '/tools/recount_posts' ),
				array( 'POST', '/ips/198.51.100.9/block' ),
				array( 'POST', '/email-templates/flagged/test' ),
				array( 'PUT', '/email-templates/flagged' ),
				array( 'POST', '/posts/' . $question->id . '/actions/hide' ),
				array( 'DELETE', '/user-fields/1' ),
			) as [ $method, $route ]
		) {
			$status = $this->request( $method, $route )->get_status();
			$this->assertGreaterThanOrEqual( 400, $status, "$method $route" );
			$this->assertLessThan( 500, $status, "$method $route" );
		}
	}
}
