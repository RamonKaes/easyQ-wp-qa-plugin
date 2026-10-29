<?php
/**
 * REST endpoints for actions on posts.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\REST;

use EasyQ\Actor;
use EasyQ\Limits\Limits;
use EasyQ\Posts\Post;
use EasyQ\Services;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Votes and performs moderation and other actions on posts.
 *
 * Routes:
 *
 * - `POST /easyq/v1/posts/{id}/vote` with `vote` = 1, 0 or -1
 * - `POST /easyq/v1/posts/{id}/actions/{action}`
 *
 * Every action responds with the changed post, or with the question if the post was deleted.
 *
 * @since 0.1.0
 */
final class Actions_Controller extends Controller {

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
			'/posts/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_post' ),
					'permission_callback' => '__return_true',
				),
				'schema' => array( Schemas::class, 'post' ),
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
				'schema' => array( Schemas::class, 'post' ),
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
							'type' => 'string',
							'enum' => array_keys( self::ACTIONS ),
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
				'schema' => array( Schemas::class, 'post' ),
			)
		);
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
	public function get_post( $request ): WP_REST_Response|WP_Error {
		$actor = $this->actor();
		$post  = $this->visible_post( (int) self::int_param( $request, 'id' ), $actor );

		return $post instanceof WP_Error ? $post : new WP_REST_Response( $this->formatter()->format( $post, $actor ) );
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
		$actor    = $this->actor();
		$writable = $this->check_writable( $actor );
		if ( true !== $writable ) {
			return $writable;
		}

		$post = $this->visible_post( (int) self::int_param( $request, 'id' ), $actor );
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
			return REST_Errors::not_found();
		}

		$result = Services::votes()->vote( $actor, $post, self::int_param( $request, 'vote' ) ?? 0 );
		if ( $result instanceof WP_Error ) {
			return REST_Errors::with_status( $result, 403 );
		}

		return $this->respond( $post->id, $actor );
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
		$actor    = Actor::current( \EasyQ\Users\Visitor_Cookie::current() );
		$writable = $this->check_writable( $actor );
		if ( true !== $writable ) {
			return $writable;
		}

		$post = $this->visible_post( (int) self::int_param( $request, 'id' ), $actor );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		$action      = (string) self::string_param( $request, 'action' );
		$parent_post = null === $post->parent_id ? null : Services::posts()->find( $post->parent_id );
		$rules       = Services::permissions()->post_rules( $post, $actor, $parent_post, $parent_post ? Services::posts()->children( $parent_post->id ) : array(), Services::posts()->children( $post->id ) );

		if ( ! ( $rules[ self::ACTIONS[ $action ] ?? '' ] ?? false ) ) {
			return REST_Errors::permission( null === $actor->user_id && 'claim' !== $action ? 'login' : 'level' );
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
			return REST_Errors::not_found();
		}

		$parent_post = null === $post->parent_id ? $question : ( Services::posts()->find( $post->parent_id ) ?? $question );
		$action      = (string) self::string_param( $request, 'action' );

		$result = match ( $action ) {
			'select', 'unselect' => $this->select( $actor, $post, 'select' === $action ? self::int_param( $request, 'answer' ) : null ),
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
			default              => REST_Errors::not_found(),
		};

		if ( $result instanceof WP_Error ) {
			return $result;
		}

		// A deleted question leaves nothing to respond with, as core does for deleted items.
		if ( 'delete' === $action && $post->id === $question->id ) {
			return new WP_REST_Response(
				array(
					'deleted' => true,
					'id'      => $post->id,
				)
			);
		}

		return $this->respond( null === Services::posts()->find( $post->id ) ? $question->id : $post->id, $actor );
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
	 * @return true
	 */
	private function set_status( Actor $actor, Post $post, Post $question, Post $parent_post, string $status ): bool {
		$service = Services::post_service();

		match ( $post->base_type() ) {
			Post::QUESTION => $service->set_question_status( $actor, $post, $status ),
			Post::ANSWER   => $service->set_answer_status( $actor, $post, $question, $status ),
			default        => $service->set_comment_status( $actor, $post, $question, $parent_post, $status ),
		};

		return true;
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
	 * @return true
	 */
	private function reshow( Actor $actor, Post $post, Post $question, Post $parent_post ): bool {
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
		if ( $parent_id === $answer->id || ( $parent_id !== $question->id && ! isset( $answers[ $parent_id ] ) ) ) {
			return $this->invalid( 'parent', __( 'The comment must belong to the question or another of its answers.', 'easyq' ) );
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
	 * @param int   $id    Post ID.
	 * @param Actor $actor The actor.
	 * @return WP_REST_Response|WP_Error
	 */
	private function respond( int $id, Actor $actor ): WP_REST_Response|WP_Error {
		Services::posts()->forget( $id );
		$post = Services::posts()->find( $id );

		return null === $post ? REST_Errors::not_found() : new WP_REST_Response( $this->formatter()->format( $post, $actor ) );
	}
}
