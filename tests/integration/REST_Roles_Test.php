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
		'POST /feedback'             => 'Visitors send feedback, as in Q2A (`qa-include/pages/feedback.php`); limited per hour.',
	);

	/**
	 * Sends emails through the mock mailer of the test suite with a valid sender, so that the
	 * routes that send one, such as feedback, can succeed.
	 */
	public function set_up(): void {
		parent::set_up();
		reset_phpmailer_instance();
		add_filter( 'wp_mail_from', array( $this, 'mail_from' ) );
	}

	public function tear_down(): void {
		remove_filter( 'wp_mail_from', array( $this, 'mail_from' ) );
		parent::tear_down();
	}

	/**
	 * Returns a valid sender address.
	 *
	 * @return string
	 */
	public function mail_from(): string {
		return 'wordpress@example.org';
	}

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
		$category = Services::categories()->create( null, 'Target' );
		$this->assertIsInt( $category );
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
					// Valid required arguments: without them core answers 400 before any check.
					foreach ( self::required_params( $handlers, $method, $category ) as $name => $value ) {
						$request->set_param( $name, $value );
					}
					$status = $server->dispatch( $request )->get_status();
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

	/**
	 * Returns the error code of every reading route, keyed by route. A route with a post takes
	 * the first of the posts it finds, so that each route gets a post of its type.
	 *
	 * @param int ...$posts Posts for the parameters of posts: a question, an answer, a comment.
	 * @return array<string, string> Error code, or an empty string for a success.
	 */
	private function reading_codes( int ...$posts ): array {
		$codes = array();
		foreach ( rest_get_server()->get_routes( 'easyq/v1' ) as $route => $handlers ) {
			$params = self::required_params( $handlers, 'GET', 0 );
			foreach ( $handlers as $handler ) {
				if ( empty( ( $handler['methods'] ?? array() )['GET'] ) ) {
					continue;
				}
				foreach ( $posts as $post ) {
					$codes[ $route ] = (string) $this->request( 'GET', $this->filled( substr( $route, strlen( '/easyq/v1' ) ), $post ), $params )->as_error()?->get_error_code();
					if ( 'easyq_not_found' !== $codes[ $route ] ) {
						break;
					}
				}
			}
		}
		$this->assertGreaterThan( 20, count( $codes ) );

		return $codes;
	}

	public function test_blocks_stop_writing_not_reading(): void {
		$author   = $this->user();
		$question = $this->ask( $author );
		$answer   = $this->answer( $this->user(), $question );
		$posts    = array( $question->id, $answer->id, $this->comment( $this->user(), $question, $answer )->id );
		wp_set_current_user( (int) $author->user_id );
		$own = $this->reading_codes( ...$posts );

		\EasyQ\Users\User_Status::block( \EasyQ\Actor::system(), (int) $author->user_id );
		$blocked = $this->reading_codes( ...$posts );

		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
		Options::update( 'spam', array( 'block_ips_write' => '203.0.113.*' ) );
		wp_set_current_user( 0 );
		$visitor = $this->reading_codes( ...$posts );
		unset( $_SERVER['REMOTE_ADDR'] );
		Options::update( 'spam', array( 'block_ips_write' => '' ) );
		$open = $this->reading_codes( ...$posts );

		$this->assertSame( $own, $blocked, 'A blocked user reads what they read before.' );
		$this->assertSame( $open, $visitor, 'A visitor from a blocked address reads what other visitors read.' );
		$this->assertContains( '', $blocked );
	}

	public function test_every_reading_route_follows_the_permission_to_view(): void {
		$question = $this->ask( $this->user() );
		$answer   = $this->answer( $this->user(), $question );
		$posts    = array( $question->id, $answer->id, $this->comment( $this->user(), $question, $answer )->id );
		Options::update( 'permissions', array( 'permit_view_q_page' => \EasyQ\Users\Permits::USERS ) );
		// Routes that show no Q&A content: the index of the namespace and the notices of the visitor.
		$own = array( '/easyq/v1', '/easyq/v1/notices' );

		wp_set_current_user( 0 );
		$shown = array();
		foreach ( $this->reading_codes( ...$posts ) as $route => $code ) {
			if ( 'easyq_login' !== $code && 'rest_forbidden' !== $code && ! in_array( $route, $own, true ) ) {
				$shown[] = "$route: $code";
			}
		}
		$this->assertSame( array(), $shown, 'Visitors read these although viewing needs a login.' );

		$reader = (int) $this->user()->user_id;
		wp_set_current_user( $reader );
		$this->assertSame( '', $this->reading_codes( ...$posts )['/easyq/v1/questions/(?P<id>\\d+)'] );
		$this->assertSame( 200, $this->request( 'GET', "/users/$reader/wall" )->get_status() );
		wp_set_current_user( 0 );
		$this->assertSame( 'easyq_login', $this->request( 'GET', "/users/$reader/wall" )->as_error()?->get_error_code() );
	}

	public function test_every_rate_limit_stops_the_action_through_the_rest_api(): void {
		$limits = (array) ( new \ReflectionClassConstant( \EasyQ\Limits\Limits::class, 'SETTINGS' ) )->getValue();
		// Limits of actions outside the REST API, held by the tests named.
		$elsewhere = array(
			\EasyQ\Limits\Limits::REGISTRATIONS => 'Account_Limits_Test',
			\EasyQ\Limits\Limits::LOGINS        => 'Account_Limits_Test',
			\EasyQ\Limits\Limits::UPLOADS       => 'Users_REST_Test::test_uploads_can_be_disabled_and_are_rate_limited',
		);
		$owner     = $this->take_part( (int) $this->user()->user_id );
		$question  = $this->ask( $this->user() );
		$answer    = $this->answer( $this->user(), $question );
		$recipes   = array(
			\EasyQ\Limits\Limits::QUESTIONS  => fn ( int $round ): array => array(
				'POST',
				'/questions',
				array(
					'title'   => "A question near the limit, round $round",
					'content' => 'Some text.',
				),
			),
			\EasyQ\Limits\Limits::ANSWERS    => fn ( int $round ): array => array( 'POST', '/questions/' . $this->ask( $this->user(), "Question to answer $round" )->id . '/answers', array( 'content' => "An answer near the limit, round $round." ) ),
			\EasyQ\Limits\Limits::COMMENTS   => fn ( int $round ): array => array( 'POST', '/posts/' . $answer->id . '/comments', array( 'content' => "A comment near the limit, round $round." ) ),
			\EasyQ\Limits\Limits::VOTES      => fn ( int $round ): array => array( 'POST', '/posts/' . $this->ask( $this->user(), "Question to vote on $round" )->id . '/vote', array( 'vote' => 1 ) ),
			\EasyQ\Limits\Limits::FLAGS      => fn ( int $round ): array => array( 'POST', '/posts/' . $this->ask( $this->user(), "Question to flag $round" )->id . '/actions/flag' ),
			\EasyQ\Limits\Limits::MESSAGES   => fn ( int $round ): array => array(
				'POST',
				'/messages',
				array(
					'to'      => $owner,
					'content' => "A message near the limit, round $round.",
				),
			),
			\EasyQ\Limits\Limits::WALL_POSTS => fn ( int $round ): array => array( 'POST', "/users/$owner/wall", array( 'content' => "A wall post near the limit, round $round." ) ),
			\EasyQ\Limits\Limits::FEEDBACK   => fn ( int $round ): array => array( 'POST', '/feedback', array( 'message' => "Feedback near the limit, round $round." ) ),
		);
		$this->assertSame( array(), array_diff( array_keys( $limits ), array_keys( $recipes ), array_keys( $elsewhere ) ), 'A limit without a request here.' );

		$settings = array( 'confirm_user_emails' => false );
		foreach ( $limits as $names ) {
			foreach ( array_filter( (array) $names ) as $name ) {
				$settings[ $name ] = 1;
			}
		}
		Options::update( 'spam', $settings );

		$wrong = array();
		foreach ( $recipes as $limit => $recipe ) {
			wp_set_current_user( (int) $this->user()->user_id );
			$_SERVER['REMOTE_ADDR'] = '198.51.100.' . ord( $limit );
			foreach ( array( 1, 2 ) as $round ) {
				[ $method, $path, $params ] = $recipe( $round ) + array( 2 => array() );
				$status                     = $this->request( $method, $path, $params )->get_status();
				if ( ( 1 === $round && 400 <= $status ) || ( 2 === $round && 429 !== $status ) ) {
					$wrong[] = "$limit, round $round: $method $path $status";
				}
			}
		}
		unset( $_SERVER['REMOTE_ADDR'] );

		$this->assertSame( array(), $wrong );
	}

	public function test_every_reading_route_takes_the_context_and_keeps_the_edit_context_to_editors(): void {
		$author   = $this->user();
		$question = $this->ask( $author );
		$answer   = $this->answer( $author, $question );
		$posts    = array( $question->id, $answer->id, $this->comment( $author, $question, $answer )->id );
		$missing  = array();
		$open     = array();

		foreach ( rest_get_server()->get_routes( 'easyq/v1' ) as $route => $handlers ) {
			foreach ( $handlers as $handler ) {
				if ( empty( ( $handler['methods'] ?? array() )['GET'] ) || '/easyq/v1' === $route ) {
					continue;
				}
				if ( ! isset( $handler['args']['context'] ) ) {
					$missing[] = $route;
				}
			}
		}
		$this->assertSame( array(), $missing, 'Reading routes without the argument `context`, which core declares on each.' );

		// Each route that answers a visitor must refuse the visitor the edit context.
		wp_set_current_user( 0 );
		foreach ( rest_get_server()->get_routes( 'easyq/v1' ) as $route => $handlers ) {
			foreach ( $handlers as $handler ) {
				if ( empty( ( $handler['methods'] ?? array() )['GET'] ) || '/easyq/v1' === $route ) {
					continue;
				}
				foreach ( $posts as $post ) {
					$path   = $this->filled( substr( $route, strlen( '/easyq/v1' ) ), $post );
					$params = self::required_params( $handlers, 'GET', 0 );
					if ( 200 !== $this->request( 'GET', $path, $params )->get_status() ) {
						continue;
					}
					if ( 400 > $this->request( 'GET', $path, $params + array( 'context' => 'edit' ) )->get_status() ) {
						$open[] = $route;
					}
					break;
				}
			}
		}
		$this->assertSame( array(), $open, 'Visitors read these in the edit context.' );

		// The author edits their question, and gets its raw content.
		wp_set_current_user( (int) $author->user_id );
		$this->assertSame( 200, $this->request( 'GET', '/questions/' . $question->id, array( 'context' => 'edit' ) )->get_status() );
		$this->assertSame( 'rest_forbidden_context', $this->request( 'GET', '/questions', array( 'context' => 'edit' ) )->as_error()?->get_error_code() );
		wp_set_current_user( $this->administrator() );
		$this->assertSame( 200, $this->request( 'GET', '/questions', array( 'context' => 'edit' ) )->get_status() );
	}

	/**
	 * Routes whose DELETE changes a state and deletes nothing for good, so they take no `force`,
	 * as core's application passwords and sessions take none.
	 *
	 * @var array<string, string>
	 */
	private const DELETE_STATES = array(
		'/notices/{}'         => 'Dismisses a notice.',
		'/favorites'          => 'Removes a favorite.',
		'/messages/{}'        => 'Hides a message for one side; it goes once both sides hid it.',
		'/email-templates/{}' => 'Goes back to the default text.',
	);

	public function test_routes_that_delete_for_good_ask_for_force(): void {
		$admin = $this->administrator();
		wp_set_current_user( $admin );
		$category = Services::categories()->create( null, 'To delete' );
		$field    = Services::user_fields()->create( 'to-delete', 'To delete', 0, null );
		$this->take_part( $admin );
		$this->assertSame( 201, $this->request( 'POST', "/users/$admin/wall", array( 'content' => 'A wall post to delete.' ) )->get_status() );
		$wall = (int) $this->data( $this->request( 'GET', "/users/$admin/wall" ) )[0]['id'];
		// An item for each route that deletes for good; a new route turns the test red until it has one.
		$targets = array(
			'/categories/{}'    => '/categories/' . $category,
			'/user-fields/{}'   => '/user-fields/' . (int) $field,
			'/users/{}/wall/{}' => "/users/$admin/wall/$wall",
		);
		$states  = array();
		$refused = array();
		$open    = array();
		foreach ( rest_get_server()->get_routes( 'easyq/v1' ) as $route => $handlers ) {
			foreach ( $handlers as $handler ) {
				if ( empty( ( $handler['methods'] ?? array() )['DELETE'] ) ) {
					continue;
				}
				$key = (string) preg_replace( array( '#^/easyq/v1#', '/\(\?P<\w+>(?:[^()]|\([^()]*\))*\)/' ), array( '', '{}' ), $route );
				if ( ! isset( $handler['args']['force'] ) ) {
					$states[] = $key;
					continue;
				}
				$this->assertArrayHasKey( $key, $targets, "No item to delete for $key." );
				$response = $this->request( 'DELETE', $targets[ $key ] );
				if ( 'rest_trash_not_supported' === $response->as_error()?->get_error_code() && 501 === $response->get_status() ) {
					$refused[] = $key;
				} else {
					$open[] = "$key: " . $response->get_status();
				}
			}
		}

		sort( $states );
		$expected = array_keys( self::DELETE_STATES );
		sort( $expected );
		$this->assertSame( $expected, $states, 'A DELETE without `force` that is not listed with its reason.' );
		$this->assertSame( array(), $open );
		$this->assertCount( 3, $refused );
	}

	public function test_every_paginated_list_pages_as_core_does(): void {
		$admin    = $this->administrator();
		$author   = $this->user();
		$question = $this->ask( $author, 'A question with many replies', 'Text.', array( 'one', 'two' ) );
		$this->ask( $author, 'Another question with tags', 'Text.', array( 'three' ) );
		$answer = $this->answer( $this->user(), $question );
		$this->answer( $this->user(), $question, 'Another answer here.' );
		$this->comment( $this->user(), $question, $answer );
		$this->comment( $this->user(), $question, $answer, 'Another comment here.' );
		Services::categories()->create( null, 'First' );
		Services::categories()->create( null, 'Second' );
		wp_set_current_user( $admin );

		$paged = array();
		$wrong = array();
		foreach ( rest_get_server()->get_routes( 'easyq/v1' ) as $route => $handlers ) {
			foreach ( $handlers as $handler ) {
				if ( empty( ( $handler['methods'] ?? array() )['GET'] ) || ! isset( $handler['args']['page'], $handler['args']['per_page'] ) ) {
					continue;
				}
				$path     = $this->filled( substr( $route, strlen( '/easyq/v1' ) ), str_contains( $route, '(?P<parent>' ) ? $answer->id : ( str_contains( $route, '/users/' ) ? $admin : $question->id ) );
				$params   = self::required_params( $handlers, 'GET', 0 ) + array( 'per_page' => 1 );
				$response = $this->request( 'GET', $path, $params );
				$headers  = $response->get_headers();
				if ( 200 !== $response->get_status() || ! isset( $headers['X-WP-Total'], $headers['X-WP-TotalPages'] ) ) {
					$wrong[] = "$route: no totals";
					continue;
				}
				$total = (int) $headers['X-WP-Total'];
				if ( $total < 2 ) {
					continue;
				}
				$paged[] = $route;
				if ( ! str_contains( (string) ( $headers['Link'] ?? '' ), 'rel="next"' ) ) {
					$wrong[] = "$route: no link to the next page";
				}
				$last = $this->request( 'GET', $path, array( 'page' => $total ) + $params )->get_headers();
				if ( ! str_contains( (string) ( $last['Link'] ?? '' ), 'rel="prev"' ) ) {
					$wrong[] = "$route: no link to the previous page";
				}
				if ( 'rest_invalid_page_number' !== $this->request( 'GET', $path, array( 'page' => $total + 1 ) + $params )->as_error()?->get_error_code() ) {
					$wrong[] = "$route: a page beyond the last is not refused";
				}
			}
		}

		$this->assertSame( array(), $wrong );
		$this->assertGreaterThanOrEqual( 6, count( $paged ), 'Too few lists had two pages: ' . implode( ', ', $paged ) );
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
