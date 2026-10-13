<?php
/**
 * Base REST controller.
 *
 * @package easyQ
 */

namespace EasyQ\Rest;

use EasyQ\Actor;
use EasyQ\Capabilities;
use EasyQ\Posts\Post;
use EasyQ\Services;
use EasyQ\Settings\Options;
use EasyQ\Users\Visitor_Cookie;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Shared helpers of the easyQ REST controllers.
 *
 * Authentication uses the WordPress REST API: cookies with the `wp_rest` nonce
 * for the blocks, application passwords for other clients.
 */
abstract class Controller extends WP_REST_Controller {

	/**
	 * Route namespace.
	 *
	 * @var string
	 */
	public const NAMESPACE = 'easyq/v1';

	/**
	 * Sets up the controller.
	 */
	public function __construct() {
		$this->namespace = self::NAMESPACE;
	}

	/**
	 * Returns the actor of the request.
	 *
	 * @return Actor
	 */
	protected function actor(): Actor {
		return Actor::current( Visitor_Cookie::current() );
	}

	/**
	 * Returns the formatter for posts.
	 *
	 * @return Post_Formatter
	 */
	protected function formatter(): Post_Formatter {
		return new Post_Formatter( Services::content_renderer() );
	}

	/**
	 * Checks a permission and returns the error response if it is not granted.
	 *
	 * @param string|null $option       Permission setting.
	 * @param Actor       $actor        The actor.
	 * @param Post|null   $post         Related post.
	 * @param string|null $limit_action Rate limit to check.
	 * @return true|WP_Error
	 */
	protected function check( ?string $option, Actor $actor, ?Post $post = null, ?string $limit_action = null ): bool|WP_Error {
		$reason = Services::permissions()->error( $option, $actor, $post, $limit_action );

		return null === $reason ? true : Rest_Errors::permission( $reason );
	}

	/**
	 * Refuses changes while the Q&A area is read-only, except for plugin administrators.
	 *
	 * @param Actor $actor The actor.
	 * @return true|WP_Error
	 */
	protected function check_writable( Actor $actor ): bool|WP_Error {
		if ( Options::get_bool( 'general', 'read_only' ) && ( null === $actor->user_id || ! user_can( $actor->user_id, Capabilities::MANAGE ) ) ) {
			return Rest_Errors::permission( 'read_only' );
		}

		return true;
	}

	/**
	 * Loads a post the actor may see.
	 *
	 * @param int         $id    Post ID.
	 * @param Actor       $actor The actor.
	 * @param string|null $type  Required base type, e.g. Post::QUESTION.
	 * @return Post|WP_Error
	 */
	protected function visible_post( int $id, Actor $actor, ?string $type = null ): Post|WP_Error {
		$post = Services::posts()->find( $id );

		if ( null === $post || ( null !== $type && $post->base_type() !== $type ) ) {
			return Rest_Errors::not_found();
		}

		$question = Services::post_service()->question_of( $post );
		if ( null === $question ) {
			return Rest_Errors::not_found();
		}

		$view = $this->check( 'permit_view_q_page', $actor, $question );
		if ( true !== $view ) {
			return $view;
		}

		$permissions = Services::permissions();
		foreach ( array_unique( array( $question->id, $post->id ) ) as $check_id ) {
			$check = $check_id === $post->id ? $post : $question;
			if ( ! $permissions->post_rules( $check, $actor )['viewable'] ) {
				return Rest_Errors::not_found();
			}
		}

		return $post;
	}

	/**
	 * Returns a response with the pagination headers.
	 *
	 * @param array<mixed> $items    Items of the page.
	 * @param int          $total    Number of items on all pages.
	 * @param int          $per_page Items per page.
	 * @return WP_REST_Response
	 */
	protected function paginated( array $items, int $total, int $per_page ): WP_REST_Response {
		$response = new WP_REST_Response( $items );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) (int) ceil( $total / max( 1, $per_page ) ) );

		return $response;
	}

	/**
	 * Returns the arguments for page and page size.
	 *
	 * @param int $default_per_page Default page size.
	 * @return array<string, array<string, mixed>>
	 */
	protected function pagination_args( int $default_per_page ): array {
		return array(
			'page'     => array(
				'description' => __( 'Page of the list.', 'easyq' ),
				'type'        => 'integer',
				'default'     => 1,
				'minimum'     => 1,
			),
			'per_page' => array(
				'description' => __( 'Items per page.', 'easyq' ),
				'type'        => 'integer',
				'default'     => $default_per_page,
				'minimum'     => 1,
				'maximum'     => 100,
			),
		);
	}

	/**
	 * Returns an integer request parameter.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @param string          $key     Parameter name.
	 * @return int|null
	 */
	protected static function int_param( WP_REST_Request $request, string $key ): ?int {
		$value = $request->get_param( $key );

		return is_numeric( $value ) ? (int) $value : null;
	}

	/**
	 * Returns a string request parameter.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @param string          $key     Parameter name.
	 * @return string|null
	 */
	protected static function string_param( WP_REST_Request $request, string $key ): ?string {
		$value = $request->get_param( $key );

		return is_scalar( $value ) ? (string) $value : null;
	}

	/**
	 * Returns a boolean request parameter.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @param string          $key     Parameter name.
	 * @return bool
	 */
	protected static function bool_param( WP_REST_Request $request, string $key ): bool {
		$value = $request->get_param( $key );

		return ( is_bool( $value ) || is_int( $value ) || is_string( $value ) ) && rest_sanitize_boolean( $value );
	}
}
