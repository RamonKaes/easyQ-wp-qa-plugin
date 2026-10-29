<?php
/**
 * REST endpoints for comments.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\REST;

use EasyQ\Limits\Limits;
use EasyQ\Posts\Post;
use EasyQ\Services;
use EasyQ\Settings\Options;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Lists and creates the comments on a question or answer, reads and edits comments.
 *
 * Routes:
 *
 * - `GET  /easyq/v1/posts/{parent}/comments`
 * - `POST /easyq/v1/posts/{parent}/comments`
 * - `GET  /easyq/v1/comments/{id}`
 * - `PUT  /easyq/v1/comments/{id}`
 *
 * @since 0.1.0
 */
final class Comments_Controller extends Controller {

	/**
	 * Route base.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	protected $rest_base = 'comments';

	/**
	 * Registers the routes.
	 *
	 * @since 0.1.0
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/posts/(?P<parent>\d+)/comments',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => Post_Input::content_args( required: true ),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . $this->rest_base . '/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => '__return_true',
					'args'                => array( 'context' => $this->get_context_param( array( 'default' => 'view' ) ) ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => Post_Input::content_args( required: false ) + array(
						'silent' => array(
							'description' => __( 'Whether to hide that the comment was edited.', 'easyq' ),
							'type'        => 'boolean',
							'default'     => false,
						),
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Lists the comments on a post that the current user may see, oldest first.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ): WP_REST_Response|WP_Error {
		$actor  = $this->actor();
		$parent = $this->visible_post( (int) self::int_param( $request, 'parent' ), $actor );
		if ( $parent instanceof WP_Error ) {
			return $parent;
		}

		$permissions = Services::permissions();
		$comments    = array_filter(
			Services::posts()->children( $parent->id ),
			static fn ( Post $child ): bool => Post::COMMENT === $child->base_type() && $permissions->post_rules( $child, $actor )['viewable']
		);

		return new WP_REST_Response( $this->collection( $comments, $request ) );
	}

	/**
	 * Returns a comment.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ): WP_REST_Response|WP_Error {
		$actor   = $this->actor();
		$comment = $this->visible_post( (int) self::int_param( $request, 'id' ), $actor, Post::COMMENT );
		if ( $comment instanceof WP_Error ) {
			return $comment;
		}

		return $this->respond_with_post( $comment, $actor, $request );
	}

	/**
	 * Checks whether the request may comment on the post.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return true|WP_Error
	 */
	public function create_item_permissions_check( $request ): bool|WP_Error {
		$actor    = $this->actor();
		$writable = $this->check_writable( $actor );
		if ( true !== $writable ) {
			return $writable;
		}

		$parent = $this->visible_post( (int) self::int_param( $request, 'parent' ), $actor );
		if ( $parent instanceof WP_Error ) {
			return $parent;
		}

		$allowed = $this->check( 'permit_post_c', $actor, $parent, Limits::COMMENTS );
		if ( true !== $allowed ) {
			return $allowed;
		}

		return Services::permissions()->post_rules( $parent, $actor )['commentable']
			? true
			: new WP_Error( 'easyq_cannot_comment', __( 'Comments are not possible on this post.', 'easyq' ), array( 'status' => 403 ) );
	}

	/**
	 * Comments on a question or answer.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ): WP_REST_Response|WP_Error {
		$parent   = Services::posts()->find( (int) self::int_param( $request, 'parent' ) );
		$question = null === $parent ? null : Services::post_service()->question_of( $parent );
		if ( null === $parent || null === $question ) {
			return REST_Errors::not_found();
		}

		$input  = Post_Input::content( $request );
		$errors = Services::validator()->validate_comment( Post_Input::validation_fields( $request, $input ) );
		if ( $errors->has_errors() ) {
			return REST_Errors::invalid( $errors );
		}

		$actor  = $this->actor();
		$queued = null !== Services::permissions()->moderation_reason( $actor );
		$author = Post_Input::author( $actor, $request );

		$id      = Services::post_service()->create_comment( $author['actor'], $question, $parent, $input['content'], $input['format'], $author['notify'], $author['name'], $queued );
		$comment = 0 === $id ? null : Services::posts()->find( $id );
		if ( null === $comment ) {
			return new WP_Error( 'easyq_not_created', __( 'The comment could not be saved.', 'easyq' ), array( 'status' => 500 ) );
		}

		return $this->created_post( $comment, $author['actor'], $queued, $this->rest_base . '/' . $id, $request );
	}

	/**
	 * Checks whether the request may edit the comment.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return true|WP_Error
	 */
	public function update_item_permissions_check( $request ): bool|WP_Error {
		$actor    = $this->actor();
		$writable = $this->check_writable( $actor );
		if ( true !== $writable ) {
			return $writable;
		}

		$comment = $this->visible_post( (int) self::int_param( $request, 'id' ), $actor, Post::COMMENT );
		if ( $comment instanceof WP_Error ) {
			return $comment;
		}

		if ( ! Services::permissions()->post_rules( $comment, $actor )['editable'] ) {
			return REST_Errors::permission( 'level' );
		}

		if ( self::bool_param( $request, 'silent' ) && ! user_can( (int) $actor->user_id, 'easyq_edit_silently' ) ) {
			return REST_Errors::permission( 'level' );
		}

		return true;
	}

	/**
	 * Edits a comment.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ): WP_REST_Response|WP_Error {
		$actor    = $this->actor();
		$comment  = Services::posts()->find( (int) self::int_param( $request, 'id' ) );
		$parent   = null === $comment || null === $comment->parent_id ? null : Services::posts()->find( $comment->parent_id );
		$question = null === $parent ? null : Services::post_service()->question_of( $parent );
		if ( null === $comment || null === $parent || null === $question ) {
			return REST_Errors::not_found();
		}

		$input   = Post_Input::content( $request );
		$content = $request->has_param( 'content' ) ? $input['content'] : (string) $comment->content;
		$format  = $request->has_param( 'content' ) ? $input['format'] : $comment->format;

		$errors = Services::validator()->validate_comment(
			array(
				'content' => $content,
				'format'  => $format,
			)
		);
		if ( $errors->has_errors() ) {
			return REST_Errors::invalid( $errors );
		}

		$remoderate = Options::get_bool( 'spam', 'moderate_edited_again' ) && null !== Services::permissions()->moderation_reason( $actor );
		$notify     = $request->has_param( 'notify' ) ? Post_Input::author( $actor, $request )['notify'] : $comment->notify;

		Services::post_service()->edit_comment( $actor, $comment, $question, $parent, $content, $format, $notify, null, $remoderate, self::bool_param( $request, 'silent' ) );

		$updated = Services::posts()->find( $comment->id );
		$request->set_param( 'context', 'edit' );

		return null === $updated ? REST_Errors::not_found() : $this->respond_with_post( $updated, $actor, $request );
	}

	/**
	 * Builds the schema of a comment.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	protected function item_schema(): array {
		return Post_Schema::get( 'comment' );
	}
}
