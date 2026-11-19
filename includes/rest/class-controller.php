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
use EasyQ\Messages\Message;
use EasyQ\Posts\Post;
use EasyQ\Services;
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
	 * Route of a post by the `type` of its response.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string>
	 */
	private const POST_ROUTES = array(
		'question' => 'questions',
		'answer'   => 'answers',
		'comment'  => 'comments',
		'note'     => 'posts',
	);

	/**
	 * Whether the items of the controller can be read one by one at `{rest_base}/{id}`, so
	 * that their responses link to it.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	protected bool $item_route = false;

	/**
	 * Field of a response that names the item in its route.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	protected string $item_key = 'id';

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
	 * @param mixed                $item    The item the data describes, for the filter of the response.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response
	 */
	protected function respond_with( array $data, WP_REST_Request $request, mixed $item = null ): WP_REST_Response {
		$links  = $this->item_links( $data );
		$fields = $this->get_fields_for_response( $request );
		$data   = array_filter( $data, static fn ( string $field ): bool => rest_is_field_included( $field, $fields ), ARRAY_FILTER_USE_KEY );
		$data   = $this->add_additional_fields_to_object( $data, $request );

		$response = new WP_REST_Response( $this->filter_response_by_context( $data, self::context( $request ) ) );
		// Only when `_fields` asks for links or embeds, as core's controllers add them (`prepare_links()`).
		if ( rest_is_field_included( '_links', $fields ) || rest_is_field_included( '_embedded', $fields ) ) {
			$response->add_links( $links );
		}

		$type = $this->get_item_schema()['title'] ?? '';
		// Not reachable today: every schema of easyQ has a title easyq-…; a controller without one gets no filter.
		if ( ! is_string( $type ) || ! str_starts_with( $type, 'easyq-' ) ) {
			return $response;
		}
		$object = substr( $type, strlen( 'easyq-' ) );

		/**
		 * Filters the response of an item of easyQ, as core's `rest_prepare_{$post_type}` does for posts.
		 *
		 * The dynamic part is the object type of the schema without `easyq-`, for example `question`
		 * for `easyq-question`.
		 *
		 * @since 0.1.0
		 *
		 * @param WP_REST_Response $response The response.
		 * @param mixed            $item     The item, such as an `EasyQ\Posts\Post` or a `WP_User`; its
		 *                                   data where the item is no object.
		 * @param WP_REST_Request  $request  The request.
		 */
		$filtered = apply_filters( "easyq_rest_prepare_{$object}", $response, $item ?? $data, $request );

		return $filtered instanceof WP_REST_Response ? $filtered : $response;
	}

	/**
	 * Returns the links of an item, as core's controllers prepare them (`prepare_links()`): the
	 * item itself and its collection, its author and the post it belongs to, both embeddable.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $data Data of the item.
	 * @return array<string, array<int|string, mixed>>
	 */
	protected function item_links( array $data ): array {
		$base  = '/' . self::NAMESPACE . '/';
		$id    = $data[ $this->item_key ] ?? null;
		$links = array();

		$type  = is_string( $data['type'] ?? null ) ? $data['type'] : '';
		$route = self::POST_ROUTES[ $type ] ?? ( $this->item_route ? (string) $this->rest_base : '' );
		if ( '' !== $route && ( is_int( $id ) || ( is_string( $id ) && '' !== $id ) ) ) {
			$links['self']       = array( 'href' => rest_url( $base . $route . '/' . rawurlencode( (string) $id ) ) );
			$links['collection'] = array( 'href' => rest_url( $base . ( isset( self::POST_ROUTES[ $type ] ) ? 'questions' : $route ) ) );
		}

		$author = is_array( $data['author'] ?? null ) ? ( $data['author']['id'] ?? null ) : null;
		if ( is_int( $author ) && $author > 0 ) {
			$links['author'] = array(
				'href'       => rest_url( $base . 'users/' . $author ),
				'embeddable' => true,
			);
		}

		$parent = $data['parent'] ?? null;
		if ( isset( self::POST_ROUTES[ $type ] ) && is_int( $parent ) && $parent > 0 ) {
			$links['up'] = array(
				'href'       => rest_url( $base . 'posts/' . $parent ),
				'embeddable' => true,
			);
		}

		return $links;
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
		// Not reachable from the plugin: every caller passes a Post; the signature of WP_REST_Controller takes any item.
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
		return $this->respond_with( $this->formatter()->format( $post, $actor, self::context( $request ), $children ) + $extra, $request, $post );
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
	 * Returns the posts of a list the actor may see, with the actor's votes and flags, which the
	 * rules read, loaded for the whole list at once.
	 *
	 * @since 0.1.0
	 *
	 * @param Post[] $posts Posts.
	 * @param Actor  $actor The actor.
	 * @return list<Post>
	 */
	protected function viewable( array $posts, Actor $actor ): array {
		if ( null !== $actor->user_id && array() !== $posts ) {
			Services::votes()->prime( array_map( static fn ( Post $post ): int => $post->id, $posts ), $actor->user_id );
		}
		$permissions = Services::permissions();

		return array_values( array_filter( $posts, static fn ( Post $post ): bool => $permissions->post_rules( $post, $actor )['viewable'] ) );
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
		// Load the extra fields of the questions at once, as WP_Query primes the post meta of a list.
		$questions = array_filter( $items, static fn ( $item ): bool => $item instanceof Post && Post::QUESTION === $item->base_type() );
		if ( array() !== $questions && Post_Input::uses_extra_field() ) {
			update_meta_cache( 'easyq_post', array_map( static fn ( Post $question ): int => $question->id, $questions ) );
		}

		// Load the authors and the viewer's votes of posts, and both sides of messages, at once, so
		// that a longer list costs no more queries, as core primes the caches of its collections.
		$posts   = array_filter( $items, static fn ( $item ): bool => $item instanceof Post );
		$viewer  = $this->actor()->user_id;
		$authors = array();
		foreach ( $posts as $post ) {
			$authors[] = $post->user_id;
			$authors[] = $post->last_user_id;
		}
		foreach ( $items as $item ) {
			if ( $item instanceof Message ) {
				$authors[] = $item->from_user_id;
				$authors[] = $item->to_user_id;
			}
		}
		Services::user_query()->prime( $authors );
		if ( array() !== $posts && null !== $viewer ) {
			Services::votes()->prime( array_map( static fn ( Post $post ): int => $post->id, $posts ), $viewer );
		}

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
	 * Refuses the edit context to users who may not edit, as core answers
	 * `rest_forbidden_context` (`WP_REST_Posts_Controller::get_item_permissions_check()` and
	 * `get_items_permissions_check()`) instead of leaving fields out.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @param Actor           $actor   The actor.
	 * @param Post|null       $post    The post of a single item; null for a list, whose edit
	 *                                 context needs the right to moderate, as core's needs
	 *                                 `edit_posts`.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_Error|null
	 */
	protected function edit_context_error( WP_REST_Request $request, Actor $actor, ?Post $post = null ): ?WP_Error {
		if ( 'edit' !== self::context( $request ) ) {
			return null;
		}

		$allowed = null === $post
			? null !== $actor->user_id && user_can( $actor->user_id, Capabilities::MODERATE )
			: Services::permissions()->post_rules( $post, $actor )['editable'];

		return $allowed ? null : new WP_Error( 'rest_forbidden_context', __( 'Sorry, you are not allowed to edit this post.', 'easyq' ), array( 'status' => rest_authorization_required_code() ) );
	}

	/**
	 * Returns the argument `force` of a route that deletes for good, as core declares it for
	 * terms (`WP_REST_Terms_Controller`).
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function force_args(): array {
		return array(
			'force' => array(
				'description' => __( 'Required to be true, as the item does not support trashing.', 'easyq' ),
				'type'        => 'boolean',
				'default'     => false,
			),
		);
	}

	/**
	 * Refuses to delete for good without `force`, as core refuses for terms with
	 * `rest_trash_not_supported` (501).
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_Error|null
	 */
	protected static function force_error( WP_REST_Request $request ): ?WP_Error {
		return self::bool_param( $request, 'force' )
			? null
			/* translators: %s: force=true */
			: new WP_Error( 'rest_trash_not_supported', sprintf( __( "This item does not support trashing. Set '%s' to delete.", 'easyq' ), 'force=true' ), array( 'status' => 501 ) );
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
	 * Checks a permission to view, which blocked users and blocked addresses keep, as in
	 * Question2Answer (`qa_user_post_permit_error( 'permit_view_q_page', $question, null, false )`
	 * in `qa-include/pages/question.php`): a block stops writing, not reading.
	 *
	 * @since 0.1.0
	 *
	 * @param string    $option        Permission setting.
	 * @param Actor     $actor         The actor.
	 * @param Post|null $post          Post whose category levels apply.
	 * @param bool      $maximum_level Whether the highest level of the user counts, as on the
	 *                                 user pages of Question2Answer.
	 * @return true|WP_Error
	 */
	protected function check_view( string $option, Actor $actor, ?Post $post = null, bool $maximum_level = false ): bool|WP_Error {
		$reason = Services::permissions()->error( $option, $actor, $post, check_blocks: false, maximum_level: $maximum_level );

		return null === $reason ? true : REST_Errors::permission( $reason );
	}

	/**
	 * Returns the arguments of the route that the client sent, in the query or the body.
	 *
	 * Unlike `WP_REST_Request::has_param()`, this leaves out the path parameters and the
	 * defaults of the route, so it tells what the client asks to change.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return string[] Names of the arguments.
	 */
	protected static function sent_args( WP_REST_Request $request ): array {
		$args = $request->get_attributes()['args'] ?? array();
		$sent = array_merge(
			(array) $request->get_query_params(),
			(array) $request->get_body_params(),
			(array) $request->get_json_params(),
			(array) $request->get_file_params()
		);

		return array_values( array_intersect( array_map( 'strval', array_keys( is_array( $args ) ? $args : array() ) ), array_map( 'strval', array_keys( $sent ) ) ) );
	}

	/**
	 * Checks that the actor may change every field of a post the request sends.
	 *
	 * Core checks `edit_post` before any field is read (`WP_REST_Posts_Controller::update_item_permissions_check()`),
	 * so a request without fields needs a right as well. Fields that only change the
	 * tags or the category also pass with the right to retag; the notification settings
	 * belong to the author alone, as in Question2Answer, where only the form of a new post
	 * sets them.
	 *
	 * @since 0.1.0
	 *
	 * @param Post            $post         Post to change.
	 * @param Actor           $actor        The actor.
	 * @param WP_REST_Request $request      Request.
	 * @param string[]        $retag_fields Fields that the right to retag allows.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return true|WP_Error
	 */
	protected function check_edit( Post $post, Actor $actor, WP_REST_Request $request, array $retag_fields = array() ): bool|WP_Error {
		$rules  = Services::permissions()->post_rules( $post, $actor );
		$fields = array_diff( self::sent_args( $request ), array( 'silent' ) );

		if ( array() !== array_intersect( $fields, array( 'notify', 'email' ) ) && ! $rules['by_user'] ) {
			return REST_Errors::permission( 'author' );
		}

		$needs_edit = array_diff( $fields, $retag_fields );
		$allowed    = array() === $needs_edit ? $rules['editable'] || ( array() !== $retag_fields && $rules['retaggable'] ) : $rules['editable'];
		if ( ! $allowed ) {
			return REST_Errors::permission( 'level' );
		}

		if ( self::bool_param( $request, 'silent' ) && ! user_can( (int) $actor->user_id, 'easyq_edit_silently' ) ) {
			return REST_Errors::permission( 'level' );
		}

		return true;
	}

	/**
	 * Loads a post the actor may see.
	 *
	 * The post and every post above it must be visible: the question page shows a comment
	 * only below an answer it shows, as in Question2Answer (`qa-include/pages/question.php`).
	 *
	 * @since 0.1.0
	 *
	 * @param int                  $id    Post ID.
	 * @param Actor                $actor The actor.
	 * @param string|string[]|null $type  Allowed base types, e.g. Post::QUESTION; null for all,
	 *                                    the closing notes of questions included.
	 * @return Post|WP_Error
	 */
	protected function visible_post( int $id, Actor $actor, string|array|null $type = null ): Post|WP_Error {
		$post = Services::posts()->find( $id );

		if ( null === $post || ( null !== $type && ! in_array( $post->base_type(), (array) $type, true ) ) ) {
			return REST_Errors::not_found();
		}

		// A comment hangs below an answer or a question, a note or an answer below a question.
		$chain = array( $post );
		while ( Post::QUESTION !== end( $chain )->base_type() ) {
			// Not covered: a post always has its parents; this guards a broken thread in the database.
			$parent_id = end( $chain )->parent_id;
			$parent    = null === $parent_id || count( $chain ) > 2 ? null : Services::posts()->find( $parent_id );
			if ( null === $parent ) {
				return REST_Errors::not_found();
			}
			$chain[] = $parent;
		}

		$view = $this->check_view( 'permit_view_q_page', $actor, end( $chain ) );
		if ( true !== $view ) {
			return $view;
		}

		$permissions = Services::permissions();
		foreach ( array_reverse( $chain ) as $check ) {
			if ( ! $permissions->post_rules( $check, $actor )['viewable'] ) {
				return REST_Errors::not_found();
			}
		}

		return $post;
	}

	/**
	 * Returns a page of a collection as core returns one (`WP_REST_Posts_Controller::get_items()`):
	 * the totals in `X-WP-Total` and `X-WP-TotalPages`, the neighbouring pages in the `Link`
	 * header, and a page beyond the last refused with 400.
	 *
	 * @since 0.1.0
	 *
	 * @param array<mixed>    $items    Items of the page.
	 * @param int             $total    Number of items on all pages.
	 * @param int             $per_page Items per page.
	 * @param WP_REST_Request $request  Request, for the page and the addresses of the others.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	protected function paginated( array $items, int $total, int $per_page, WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$pages = (int) ceil( $total / max( 1, $per_page ) );
		$page  = max( 1, self::int_param( $request, 'page' ) ?? 1 );
		if ( $page > $pages && $total > 0 ) {
			return new WP_Error( 'rest_invalid_page_number', __( 'The page number requested is larger than the number of pages available.', 'easyq' ), array( 'status' => 400 ) );
		}

		$response = new WP_REST_Response( $items );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) $pages );

		$base = add_query_arg( urlencode_deep( $request->get_query_params() ), rest_url( $request->get_route() ) );
		if ( $page > 1 ) {
			$response->link_header( 'prev', add_query_arg( 'page', $page - 1, $base ) );
		}
		if ( $page < $pages ) {
			$response->link_header( 'next', add_query_arg( 'page', $page + 1, $base ) );
		}

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
