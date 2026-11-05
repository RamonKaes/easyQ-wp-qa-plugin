<?php
/**
 * Tests that every route of the REST API holds for every role.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Services;
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
		'POST /unsubscribe'          => 'The token from the email is the permission.',
		'DELETE /notices/{}'         => 'First-time visitors dismiss their notice.',
		'POST /questions/{}'         => 'Without fields there is nothing to change; see the test with fields.',
		'PUT /questions/{}'          => 'Without fields there is nothing to change; see the test with fields.',
		'PATCH /questions/{}'        => 'Without fields there is nothing to change; see the test with fields.',
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

	public function test_visitors_cannot_change_questions(): void {
		$question = $this->ask( $this->user(), 'A question that stays the same' );
		wp_set_current_user( 0 );

		foreach ( array(
			'title'   => 'A title by a visitor here',
			'content' => 'Content by a visitor here.',
			'tags'    => array( 'visitor' ),
		) as $field => $value ) {
			$this->assertSame( 403, $this->request( 'PUT', '/questions/' . $question->id, array( $field => $value ) )->get_status(), $field );
		}
		$this->assertSame( 'A question that stays the same', $this->post( $question->id )->title );
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
