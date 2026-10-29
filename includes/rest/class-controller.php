<?php
/**
 * Base REST controller.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\REST;

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
 *
 * @since 0.1.0
 */
abstract class Controller extends WP_REST_Controller {

	/**
	 * Route namespace.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const NAMESPACE = 'easyq/v1';

	/**
	 * Sets up the controller.
	 *
	 * @since 0.1.0
	 */
	public function __construct() {
		$this->namespace = self::NAMESPACE;
	}

	/**
	 * Returns the schema of the items of the controller.
	 *
	 * Cached in `$this->schema`, as core caches it since WordPress 5.3, with the fields
	 * that other plugins add through `register_rest_field()`.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema(): array {
		if ( ! $this->schema ) {
			$this->schema = $this->item_schema();
		}

		/**
		 * Schema with the fields of `register_rest_field()`.
		 *
		 * @var array<string, mixed> $schema
		 */
		$schema = $this->add_additional_fields_schema( $this->schema );

		return $schema;
	}

	/**
	 * Builds the schema of the items of the controller.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	abstract protected function item_schema(): array;

	/**
	 * Turns the data of an item into a response, as core controllers do: with the fields the
	 * request asks for (`_fields`, also for requests inside WordPress, which the server does
	 * not filter), the fields of `register_rest_field()`, and without the fields the context
	 * does not include.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $data    Data of the item.
	 * @param WP_REST_Request      $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response
	 */
	protected function respond_with( array $data, WP_REST_Request $request ): WP_REST_Response {
		$fields = $this->get_fields_for_response( $request );
		$data   = array_filter( $data, static fn ( string $field ): bool => rest_is_field_included( $field, $fields ), ARRAY_FILTER_USE_KEY );
		$data   = $this->add_additional_fields_to_object( $data, $request );

		return new WP_REST_Response( $this->filter_response_by_context( $data, self::context( $request ) ) );
	}

	/**
	 * Prepares a post for the response; controllers of other items override this.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed           $item    Post.
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function prepare_item_for_response( $item, $request ): WP_REST_Response|WP_Error {
		if ( ! $item instanceof Post ) {
			return new WP_Error( 'easyq_invalid_item', __( 'The item cannot be shown.', 'easyq' ), array( 'status' => 500 ) );
		}

		return $this->respond_with_post( $item, $this->actor(), $request );
	}

	/**
	 * Returns the response for a post.
	 *
	 * @since 0.1.0
	 *
	 * @param Post                 $post     Post.
	 * @param Actor                $actor    Who requests the post.
	 * @param WP_REST_Request      $request  Request.
	 * @param Post[]               $children Children of the post, for the rules about deleting and answering.
	 * @param array<string, mixed> $extra    Further fields, e.g. `queued` after creating the post.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response
	 */
	protected function respond_with_post( Post $post, Actor $actor, WP_REST_Request $request, array $children = array(), array $extra = array() ): WP_REST_Response {
		return $this->respond_with( $this->formatter()->format( $post, $actor, self::context( $request ), $children ) + $extra, $request );
	}

	/**
	 * Returns the response for a created post: status 201, the `Location` header and the edit context, as core responds.
	 *
	 * @since 0.1.0
	 *
	 * @param Post            $post    Post.
	 * @param Actor           $actor   Author.
	 * @param bool            $queued  Whether the post waits for approval.
	 * @param string          $route   Route of the post below the namespace, e.g. `questions/5`.
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response
	 */
	protected function created_post( Post $post, Actor $actor, bool $queued, string $route, WP_REST_Request $request ): WP_REST_Response {
		$request->set_param( 'context', 'edit' );
		$response = $this->respond_with_post( $post, $actor, $request, array(), array( 'queued' => $queued ) );
		$response->set_status( 201 );
		$response->header( 'Location', rest_url( $this->namespace . '/' . $route ) );

		return $response;
	}

	/**
	 * Returns the prepared items of a list.
	 *
	 * @since 0.1.0
	 *
	 * @param array<mixed>    $items   Items.
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return list<mixed>
	 */
	protected function collection( array $items, WP_REST_Request $request ): array {
		$data = array();
		foreach ( $items as $item ) {
			$response = $this->prepare_item_for_response( $item, $request );
			if ( $response instanceof WP_REST_Response ) {
				$data[] = $this->prepare_response_for_collection( $response );
			}
		}

		return $data;
	}

	/**
	 * Returns the context of a request, `view` if it names none.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return string
	 */
	protected static function context( WP_REST_Request $request ): string {
		$context = $request->get_param( 'context' );

		return is_string( $context ) && '' !== $context ? $context : 'view';
	}

	/**
	 * Whether a request asks for items as they are embedded in other responses.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return bool
	 */
	protected static function embedded( WP_REST_Request $request ): bool {
		return 'embed' === self::context( $request );
	}

	/**
	 * Returns the arguments of a route that reads items: the context, as core adds it to such routes.
	 *
	 * @since 0.1.0
	 *
	 * @param string $default_context Default context.
	 * @return array<string, array<string, mixed>>
	 */
	protected function read_args( string $default_context = 'view' ): array {
		/**
		 * The argument as core describes it, with the contexts of the schema.
		 *
		 * @var array<string, mixed> $context
		 */
		$context = $this->get_context_param( array( 'default' => $default_context ) );

		return array( 'context' => $context );
	}

	/**
	 * Returns the actor of the request.
	 *
	 * @since 0.1.0
	 *
	 * @return Actor
	 */
	protected function actor(): Actor {
		return Actor::current( Visitor_Cookie::current() );
	}

	/**
	 * Returns the formatter for posts.
	 *
	 * @since 0.1.0
	 *
	 * @return Post_Formatter
	 */
	protected function formatter(): Post_Formatter {
		return new Post_Formatter( Services::content_renderer() );
	}

	/**
	 * Checks a permission and returns the error response if it is not granted.
	 *
	 * @since 0.1.0
	 *
	 * @param string|null $option       Permission setting.
	 * @param Actor       $actor        The actor.
	 * @param Post|null   $post         Related post.
	 * @param string|null $limit_action Rate limit to check.
	 * @return true|WP_Error
	 */
	protected function check( ?string $option, Actor $actor, ?Post $post = null, ?string $limit_action = null ): bool|WP_Error {
		$reason = Services::permissions()->error( $option, $actor, $post, $limit_action );

		return null === $reason ? true : REST_Errors::permission( $reason );
	}

	/**
	 * Refuses changes while the Q&A area is read-only, except for plugin administrators.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor $actor The actor.
	 * @return true|WP_Error
	 */
	protected function check_writable( Actor $actor ): bool|WP_Error {
		if ( Options::get_bool( 'general', 'read_only' ) && ( null === $actor->user_id || ! user_can( $actor->user_id, Capabilities::MANAGE ) ) ) {
			return REST_Errors::permission( 'read_only' );
		}

		return true;
	}

	/**
	 * Loads a post the actor may see.
	 *
	 * @since 0.1.0
	 *
	 * @param int         $id    Post ID.
	 * @param Actor       $actor The actor.
	 * @param string|null $type  Required base type, e.g. Post::QUESTION.
	 * @return Post|WP_Error
	 */
	protected function visible_post( int $id, Actor $actor, ?string $type = null ): Post|WP_Error {
		$post = Services::posts()->find( $id );

		if ( null === $post || ( null !== $type && $post->base_type() !== $type ) ) {
			return REST_Errors::not_found();
		}

		$question = Services::post_service()->question_of( $post );
		if ( null === $question ) {
			return REST_Errors::not_found();
		}

		$view = $this->check( 'permit_view_q_page', $actor, $question );
		if ( true !== $view ) {
			return $view;
		}

		$permissions = Services::permissions();
		foreach ( array_unique( array( $question->id, $post->id ) ) as $check_id ) {
			$check = $check_id === $post->id ? $post : $question;
			if ( ! $permissions->post_rules( $check, $actor )['viewable'] ) {
				return REST_Errors::not_found();
			}
		}

		return $post;
	}

	/**
	 * Returns a response with the pagination headers.
	 *
	 * @since 0.1.0
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
	 * @since 0.1.0
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
	 * @since 0.1.0
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
	 * @since 0.1.0
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
	 * @since 0.1.0
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
