<?php
/**
 * REST endpoints for posts of any type: the moderation list, single posts, votes and actions.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\REST;

use EasyQ\Actor;
use EasyQ\Capabilities;
use EasyQ\Limits\Limits;
use EasyQ\Posts\Moderation_Query;
use EasyQ\Posts\Post;
use EasyQ\Services;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Lists posts for moderators, returns single posts, votes and performs moderation and other actions.
 *
 * Routes:
 *
 * - `GET /easyq/v1/posts` with `view` and filters
 * - `GET /easyq/v1/posts/{id}`
 * - `POST /easyq/v1/posts/{id}/vote` with `vote` = 1, 0 or -1
 * - `POST /easyq/v1/posts/{id}/actions/{action}`
 *
 * Every action responds with the changed post, with its question if an answer or comment was
 * converted or moved away, or, after deleting, with `deleted` and the `previous` post, as core does.
 *
 * @since 0.1.0
 */
final class Posts_Controller extends Controller {

	/**
	 * Route base.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	protected $rest_base = 'posts';

	/**
	 * Base types that can be voted on or acted on. The closing note of a question is read with
	 * it and changes only when the question is closed or reopened.
	 *
	 * @since 0.1.0
	 *
	 * @var string[]
	 */
	private const TARGETS = array( Post::QUESTION, Post::ANSWER, Post::COMMENT );

	/**
	 * Actions and the post rule that allows each.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string>
	 */
	private const ACTIONS = array(
		'select'      => 'answer_selectable',
		'unselect'    => 'answer_selectable',
		'flag'        => 'flag_button',
		'unflag'      => 'unflaggable',
		'clear-flags' => 'flags_clearable',
		'hide'        => 'hideable',
		'reshow'      => 'reshowable',
		'approve'     => 'moderatable',
		'reject'      => 'moderatable',
		'delete'      => 'deletable',
		'close'       => 'closeable',
		'reopen'      => 'reopenable',
		'claim'       => 'claimable',
		'convert'     => 'editable',
		'move'        => 'retaggable',
	);

	/**
	 * Registers the routes.
	 *
	 * @since 0.1.0
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/posts',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'list_permissions_check' ),
					'args'                => array(
						'view'     => array(
							'description' => __( 'All posts, or those awaiting approval, flagged, hidden or closed.', 'easyq' ),
							'type'        => 'string',
							'enum'        => Moderation_Query::VIEWS,
							'default'     => 'all',
						),
						'type'     => array(
							'description' => __( 'Only questions, answers or comments.', 'easyq' ),
							'type'        => 'string',
							'enum'        => array_keys( Moderation_Query::TYPES ),
						),
						'category' => array(
							'description' => __( 'Only posts in this category and its subcategories.', 'easyq' ),
							'type'        => 'integer',
						),
						'author'   => array(
							'description' => __( 'Only posts by this user.', 'easyq' ),
							'type'        => 'integer',
						),
						'ip'       => array(
							'description' => __( 'Only posts created from this IP address.', 'easyq' ),
							'type'        => 'string',
						),
						'search'   => array(
							'description' => __( 'Only posts whose title or text contains this.', 'easyq' ),
							'type'        => 'string',
						),
						'month'    => array(
							'description' => __( 'Only posts created in this month, as YYYYMM.', 'easyq' ),
							'type'        => 'string',
							'pattern'     => '^\d{6}$',
						),
					) + $this->pagination_args( 20 ) + $this->read_args(),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/posts/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => $this->read_args(),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/posts/(?P<id>\d+)/vote',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'vote' ),
					'permission_callback' => array( $this, 'vote_permissions_check' ),
					'args'                => array(
						'vote' => array(
							'description' => __( 'The vote: 1 for up, -1 for down, 0 to remove the vote.', 'easyq' ),
							'type'        => 'integer',
							'enum'        => array( -1, 0, 1 ),
							'required'    => true,
						),
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/posts/(?P<id>\d+)/actions/(?P<action>[a-z-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'perform' ),
					'permission_callback' => array( $this, 'perform_permissions_check' ),
					'args'                => array(
						'action'       => array(
							'description' => __( 'Name of the action.', 'easyq' ),
							'type'        => 'string',
							'enum'        => array_keys( self::ACTIONS ),
						),
						'answer'       => array(
							'description' => __( 'Answer to select (select).', 'easyq' ),
							'type'        => 'integer',
						),
						'duplicate_of' => array(
							'description' => __( 'Original question when closing as duplicate (close).', 'easyq' ),
							'type'        => 'integer',
						),
						'note'         => array(
							'description' => __( 'Reason when closing for another reason (close).', 'easyq' ),
							'type'        => 'string',
						),
						'parent'       => array(
							'description' => __( 'New parent when converting an answer into a comment (convert).', 'easyq' ),
							'type'        => 'integer',
						),
						'category'     => array(
							'description' => __( 'New category (move).', 'easyq' ),
							'type'        => array( 'integer', 'null' ),
						),
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Checks whether the current user may list posts: moderators, and users who may
	 * see IP addresses when the list is limited to one address.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return true|WP_Error
	 */
	public function list_permissions_check( $request ): bool|WP_Error {
		if ( current_user_can( Capabilities::MODERATE ) || ( $request->has_param( 'ip' ) && current_user_can( 'easyq_view_ips' ) ) ) {
			return true;
		}

		return REST_Errors::permission( is_user_logged_in() ? 'level' : 'login' );
	}

	/**
	 * Lists posts.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ): WP_REST_Response|WP_Error {
		$actor    = $this->actor();
		$per_page = self::int_param( $request, 'per_page' ) ?? 20;
		$result   = Services::moderation_query()->find(
			$actor,
			array(
				'view'        => self::string_param( $request, 'view' ),
				'type'        => self::string_param( $request, 'type' ),
				'category_id' => self::int_param( $request, 'category' ),
				'user_id'     => self::int_param( $request, 'author' ),
				'ip'          => self::string_param( $request, 'ip' ),
				'search'      => self::string_param( $request, 'search' ),
				'month'       => self::string_param( $request, 'month' ),
				'page'        => self::int_param( $request, 'page' ),
				'per_page'    => $per_page,
			)
		);

		return $this->paginated( $this->collection( $result['posts'], $request ), $result['total'], $per_page, $request );
	}

	/**
	 * Checks whether the request may read the post.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return true|WP_Error
	 */
	public function get_item_permissions_check( $request ): bool|WP_Error {
		return $this->read_permissions_check( $request, 'id', null );
	}

	/**
	 * Returns any post the viewer may see, whatever its type.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ): WP_REST_Response|WP_Error {
		$actor = $this->actor();
		$post  = $this->visible_post( (int) self::int_param( $request, 'id' ), $actor );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		return $this->edit_context_error( $request, $actor, $post ) ?? $this->respond_with_post( $post, $actor, $request );
	}

	/**
	 * Checks whether the request may vote on the post.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return true|WP_Error
	 */
	public function vote_permissions_check( $request ): bool|WP_Error {
		$actor = $this->actor();
		$post  = $this->visible_post( (int) self::int_param( $request, 'id' ), $actor, self::TARGETS );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		$option = match ( $post->base_type() ) {
			Post::QUESTION => 'permit_vote_q',
			Post::ANSWER   => 'permit_vote_a',
			default        => 'permit_vote_c',
		};

		$allowed = $this->check( $option, $actor, $post, Limits::VOTES );
		if ( true === $allowed && ( self::int_param( $request, 'vote' ) ?? 0 ) < 0 ) {
			$allowed = $this->check( 'permit_vote_down', $actor, $post );
		}

		return $allowed;
	}

	/**
	 * Sets the vote of the current user.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function vote( $request ): WP_REST_Response|WP_Error {
		$actor = $this->actor();
		$post  = Services::posts()->find( (int) self::int_param( $request, 'id' ) );
		if ( null === $post ) {
			// Not covered: the permission check found the post; this guards its deletion in between.
			return REST_Errors::not_found();
		}

		$result = Services::votes()->vote( $actor, $post, self::int_param( $request, 'vote' ) ?? 0 );
		if ( $result instanceof WP_Error ) {
			return REST_Errors::with_status( $result, 403 );
		}

		return $this->respond( $post->id, $actor, $request );
	}

	/**
	 * Checks whether the request may perform the action.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return true|WP_Error
	 */
	public function perform_permissions_check( $request ): bool|WP_Error {
		$actor = Actor::current( \EasyQ\Users\Visitor_Cookie::current() );
		$post  = $this->visible_post( (int) self::int_param( $request, 'id' ), $actor, self::TARGETS );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		$action      = (string) self::string_param( $request, 'action' );
		$parent_post = null === $post->parent_id ? null : Services::posts()->find( $post->parent_id );
		$rules       = Services::permissions()->post_rules( $post, $actor, $parent_post, $parent_post ? Services::posts()->children( $parent_post->id ) : array(), Services::posts()->children( $post->id ) );

		if ( ! ( $rules[ self::ACTIONS[ $action ] ?? '' ] ?? false ) ) {
			return REST_Errors::permission( $this->refusal( $post, $actor, $rules, null === $actor->user_id && 'claim' !== $action ? 'login' : 'level' ) );
		}

		return match ( $action ) {
			'flag'    => $this->check( 'permit_flag', $actor, $post, Limits::FLAGS ),
			'convert' => Post::ANSWER === $post->type ? $this->check( 'permit_post_c', $actor, $post ) : REST_Errors::permission( 'level' ),
			default   => true,
		};
	}

	/**
	 * Performs an action.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function perform( $request ): WP_REST_Response|WP_Error {
		$actor    = $this->actor();
		$service  = Services::post_service();
		$post     = Services::posts()->find( (int) self::int_param( $request, 'id' ) );
		$question = null === $post ? null : $service->question_of( $post );
		if ( null === $post || null === $question ) {
			// Not covered: the permission check found the post; this guards its deletion in between.
			return REST_Errors::not_found();
		}

		$parent_post = null === $post->parent_id ? $question : ( Services::posts()->find( $post->parent_id ) ?? $question );
		$action      = (string) self::string_param( $request, 'action' );
		// Core responds to deleting with the item as it was.
		$previous = 'delete' === $action ? $this->respond_with_post( $post, $actor, $request )->get_data() : null;

		$result = match ( $action ) {
			// Selecting needs the answer; clearing the selection is `unselect`.
			'select'             => null === self::int_param( $request, 'answer' ) ? $this->invalid( 'answer', __( 'Choose the answer to select.', 'easyq' ) ) : $this->select( $actor, $post, self::int_param( $request, 'answer' ) ),
			'unselect'           => $this->select( $actor, $post, null ),
			'flag'               => $this->flag( $actor, $post, $question ),
			'unflag'             => $this->run( static fn () => Services::votes()->unflag( $actor, $post ) ),
			'clear-flags'        => $this->run( static fn () => Services::votes()->clear_flags( $actor, $post ) ),
			'hide', 'reject'     => $this->set_status( $actor, $post, $question, $parent_post, Post::STATUS_HIDDEN ),
			'approve'            => $this->set_status( $actor, $post, $question, $parent_post, Post::STATUS_NORMAL ),
			'reshow'             => $this->reshow( $actor, $post, $question, $parent_post ),
			'delete'             => $this->delete( $actor, $post, $question, $parent_post ),
			'close'              => $this->close( $actor, $post, $request ),
			'reopen'             => $this->run( static fn () => $service->reopen( $actor, $post ) ),
			'claim'              => $this->run( static fn () => $service->claim( $actor, $post ) ),
			'convert'            => $this->convert( $actor, $post, $question, self::int_param( $request, 'parent' ) ?? $question->id ),
			'move'               => $this->move( $actor, $post, $request ),
			// Not reachable: the enum of the argument lets only these actions through; the arm keeps match() exhaustive.
			default              => REST_Errors::not_found(),
		};

		if ( $result instanceof WP_Error ) {
			return $result;
		}

		if ( null !== $previous ) {
			return new WP_REST_Response(
				array(
					'deleted'  => true,
					'previous' => $previous,
				)
			);
		}

		return $this->respond( null === Services::posts()->find( $post->id ) ? $question->id : $post->id, $actor, $request );
	}

	/**
	 * Selects an answer of a question, or clears the selection.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor    $actor     The actor.
	 * @param Post     $question  Question.
	 * @param int|null $answer_id Answer, or null to clear the selection.
	 * @return true|WP_Error
	 */
	private function select( Actor $actor, Post $question, ?int $answer_id ): bool|WP_Error {
		if ( Post::QUESTION !== $question->type ) {
			// Not covered: the permission check allows selecting on visible questions only; this guards a change of the question in between.
			return $this->invalid( 'answer', __( 'Answers can only be selected on visible questions.', 'easyq' ) );
		}

		if ( null !== $answer_id ) {
			$answer = Services::posts()->find( $answer_id );
			if ( null === $answer || Post::ANSWER !== $answer->type || $answer->parent_id !== $question->id ) {
				return $this->invalid( 'answer', __( 'This is not a visible answer of the question.', 'easyq' ) );
			}
		}

		Services::post_service()->select_answer( $actor, $question, $answer_id );

		return true;
	}

	/**
	 * Flags a post.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor $actor    The actor.
	 * @param Post  $post     Post.
	 * @param Post  $question Question of the post.
	 * @return true|WP_Error
	 */
	private function flag( Actor $actor, Post $post, Post $question ): bool|WP_Error {
		$result = Services::votes()->flag( $actor, $post, $question );

		return $result instanceof WP_Error ? REST_Errors::with_status( $result, 403 ) : true;
	}

	/**
	 * Changes the status of any kind of post.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor  $actor    The actor.
	 * @param Post   $post     Post.
	 * @param Post   $question Question of the post.
	 * @param Post   $parent_post   Parent of the post.
	 * @param string $status   New status.
	 * @return true|WP_Error The error `easyq_changed` with status 409 if someone else changed the post first.
	 */
	private function set_status( Actor $actor, Post $post, Post $question, Post $parent_post, string $status ): bool|WP_Error {
		$service = Services::post_service();

		$changed = match ( $post->base_type() ) {
			Post::QUESTION => $service->set_question_status( $actor, $post, $status ),
			Post::ANSWER   => $service->set_answer_status( $actor, $post, $question, $status ),
			default        => $service->set_comment_status( $actor, $post, $question, $parent_post, $status ),
		};

		return $changed ? true : new WP_Error( 'easyq_changed', __( 'Someone else changed this post in the meantime. Reload the page to see it.', 'easyq' ), array( 'status' => 409 ) );
	}

	/**
	 * Shows a hidden post again; it goes to moderation if the actor's posts are moderated.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor $actor    The actor.
	 * @param Post  $post     Post.
	 * @param Post  $question Question of the post.
	 * @param Post  $parent_post   Parent of the post.
	 * @return true|WP_Error
	 */
	private function reshow( Actor $actor, Post $post, Post $question, Post $parent_post ): bool|WP_Error {
		$rules  = Services::permissions()->post_rules( $post, $actor );
		$queued = ! $rules['reshow_at_once'] && null !== Services::permissions()->moderation_reason( $actor );

		return $this->set_status( $actor, $post, $question, $parent_post, $queued ? Post::STATUS_QUEUED : Post::STATUS_NORMAL );
	}

	/**
	 * Deletes a hidden post.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor $actor    The actor.
	 * @param Post  $post     Post.
	 * @param Post  $question Question of the post.
	 * @param Post  $parent_post   Parent of the post.
	 * @return true|WP_Error
	 */
	private function delete( Actor $actor, Post $post, Post $question, Post $parent_post ): bool|WP_Error {
		$service = Services::post_service();

		$result = match ( $post->base_type() ) {
			Post::QUESTION => $service->delete_question( $actor, $post ),
			Post::ANSWER   => $service->delete_answer( $actor, $post, $question ),
			default        => $service->delete_comment( $actor, $post, $question, $parent_post ),
		};

		return $result instanceof WP_Error ? REST_Errors::with_status( $result, 409 ) : true;
	}

	/**
	 * Closes a question as duplicate or with a note.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor           $actor    The actor.
	 * @param Post            $question Question.
	 * @param WP_REST_Request $request  Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return true|WP_Error
	 */
	private function close( Actor $actor, Post $question, WP_REST_Request $request ): bool|WP_Error {
		$duplicate_of = self::int_param( $request, 'duplicate_of' );
		$note         = trim( sanitize_textarea_field( (string) self::string_param( $request, 'note' ) ) );

		if ( null !== $duplicate_of ) {
			$original = Services::posts()->find( $duplicate_of );
			if ( null === $original || Post::QUESTION !== $original->type || $original->id === $question->id ) {
				return $this->invalid( 'duplicate_of', __( 'The original question does not exist.', 'easyq' ) );
			}

			Services::post_service()->close_as_duplicate( $actor, $question, $original->id );

			return true;
		}

		if ( '' === $note ) {
			return $this->invalid( 'note', __( 'Please give a reason or the original question.', 'easyq' ) );
		}

		Services::post_service()->close_with_note( $actor, $question, $note );

		return true;
	}

	/**
	 * Converts an answer into a comment.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor $actor     The actor.
	 * @param Post  $answer    Answer.
	 * @param Post  $question  Question of the answer.
	 * @param int   $parent_id New parent: the question or another answer of it.
	 * @return true|WP_Error
	 */
	private function convert( Actor $actor, Post $answer, Post $question, int $parent_id ): bool|WP_Error {
		$answers = Services::posts()->answers_of( $question->id );
		$target  = $parent_id === $question->id ? $question : ( $parent_id !== $answer->id ? ( $answers[ $parent_id ] ?? null ) : null );
		// Question2Answer offers only targets that may get comments and are not hidden (`qa-include/pages/question-post.php`,
		// `qa_page_q_edit_a_form()`); the rule `commentable` is false for hidden posts as well.
		if ( null === $target || ! Services::permissions()->post_rules( $target, $actor, $target === $question ? null : $question )['commentable'] ) {
			return $this->invalid( 'parent', __( 'The comment must belong to the question or another of its answers that may get comments.', 'easyq' ) );
		}

		Services::post_service()->answer_to_comment( $actor, $answer, $question, $parent_id, (string) $answer->content, $answer->format, $answer->notify );

		return true;
	}

	/**
	 * Moves a question to another category.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor           $actor    The actor.
	 * @param Post            $question Question.
	 * @param WP_REST_Request $request  Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return true|WP_Error
	 */
	private function move( Actor $actor, Post $question, WP_REST_Request $request ): bool|WP_Error {
		$category_id = self::int_param( $request, 'category' );
		$error       = Services::categories()->check_choice( $category_id, $question );
		if ( null !== $error ) {
			return REST_Errors::invalid( $error );
		}

		Services::post_service()->set_category( $actor, $question, $category_id );

		return true;
	}

	/**
	 * Runs an action without result.
	 *
	 * @since 0.1.0
	 *
	 * @param callable $action Action.
	 * @return true
	 */
	private function run( callable $action ): bool {
		$action();

		return true;
	}

	/**
	 * Returns a validation error for one field.
	 *
	 * @since 0.1.0
	 *
	 * @param string $field   Field.
	 * @param string $message Message.
	 * @return WP_Error
	 */
	private function invalid( string $field, string $message ): WP_Error {
		return REST_Errors::invalid( new WP_Error( $field, $message ) );
	}

	/**
	 * Responds with a post as the actor sees it now.
	 *
	 * @since 0.1.0
	 *
	 * @param int             $id      Post ID.
	 * @param Actor           $actor   The actor.
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	private function respond( int $id, Actor $actor, WP_REST_Request $request ): WP_REST_Response|WP_Error {
		Services::posts()->forget( $id );
		$post = Services::posts()->find( $id );

		return null === $post ? REST_Errors::not_found() : $this->respond_with_post( $post, $actor, $request );
	}

	/**
	 * Builds the schema of a post of any type.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	protected function item_schema(): array {
		return Post_Schema::get( 'post' );
	}
}
