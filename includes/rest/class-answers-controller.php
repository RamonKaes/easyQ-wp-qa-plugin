<?php
/**
 * REST endpoints for answers.
 *
 * @package easyQ
 */

namespace EasyQ\Rest;

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
 * Lists and creates the answers of a question, reads and edits answers.
 *
 * Routes:
 *
 * - `GET  /easyq/v1/questions/{question}/answers`
 * - `POST /easyq/v1/questions/{question}/answers`
 * - `GET  /easyq/v1/answers/{id}`
 * - `PUT  /easyq/v1/answers/{id}`
 */
final class Answers_Controller extends Controller {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'answers';

	/**
	 * Registers the routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/questions/(?P<question>\d+)/answers',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => '__return_true',
					'args'                => $this->pagination_args( Options::get_int( 'viewing', 'page_size_q_as' ) ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => Post_Input::content_args( true ),
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
					'args'                => Post_Input::content_args( false ) + array(
						'silent' => array(
							'description' => __( 'Whether to hide that the answer was edited.', 'easyq' ),
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
	 * Lists the answers of a question the current user may see.
	 *
	 * Answers are sorted by time or votes as configured, optionally with the selected answer first.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ): WP_REST_Response|WP_Error {
		$actor    = $this->actor();
		$question = $this->visible_post( (int) self::int_param( $request, 'question' ), $actor, Post::QUESTION );
		if ( $question instanceof WP_Error ) {
			return $question;
		}

		$permissions = Services::permissions();
		$answers     = array_values(
			array_filter(
				Services::posts()->answers_of( $question->id ),
				static fn ( Post $answer ): bool => $permissions->post_rules( $answer, $actor )['viewable']
			)
		);

		$by_votes = 'votes' === Options::get_string( 'viewing', 'sort_answers_by' );
		$selected = Options::get_bool( 'viewing', 'show_selected_first' ) ? $question->selected_child_id : null;

		usort(
			$answers,
			static function ( Post $a, Post $b ) use ( $by_votes, $selected ): int {
				if ( null !== $selected && ( $a->id === $selected || $b->id === $selected ) ) {
					return $a->id === $selected ? -1 : 1;
				}

				return $by_votes ? array( $b->net_votes, $a->created, $a->id ) <=> array( $a->net_votes, $b->created, $b->id ) : array( $a->created, $a->id ) <=> array( $b->created, $b->id );
			}
		);

		$per_page = self::int_param( $request, 'per_page' ) ?? 10;
		$page     = self::int_param( $request, 'page' ) ?? 1;
		$items    = array_map(
			fn ( Post $answer ): array => $this->formatter()->format( $answer, $actor ),
			array_slice( $answers, ( $page - 1 ) * $per_page, $per_page )
		);

		return $this->paginated( $items, count( $answers ), $per_page );
	}

	/**
	 * Returns an answer.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ): WP_REST_Response|WP_Error {
		$actor  = $this->actor();
		$answer = $this->visible_post( (int) self::int_param( $request, 'id' ), $actor, Post::ANSWER );
		if ( $answer instanceof WP_Error ) {
			return $answer;
		}

		$context = 'edit' === $request->get_param( 'context' ) ? 'edit' : 'view';

		return new WP_REST_Response( $this->formatter()->format( $answer, $actor, $context, Services::posts()->children( $answer->id ) ) );
	}

	/**
	 * Checks whether the request may answer the question.
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

		$question = $this->visible_post( (int) self::int_param( $request, 'question' ), $actor, Post::QUESTION );
		if ( $question instanceof WP_Error ) {
			return $question;
		}

		$allowed = $this->check( 'permit_post_a', $actor, $question, Limits::ANSWERS );
		if ( true !== $allowed ) {
			return $allowed;
		}

		$rules = Services::permissions()->post_rules( $question, $actor, null, array(), Services::posts()->children( $question->id ) );

		return $rules['answer_button'] ? true : new WP_Error( 'easyq_cannot_answer', __( 'This question cannot be answered.', 'easyq' ), array( 'status' => 403 ) );
	}

	/**
	 * Answers a question.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ): WP_REST_Response|WP_Error {
		$question = Services::posts()->find( (int) self::int_param( $request, 'question' ) );
		if ( null === $question ) {
			return Rest_Errors::not_found();
		}

		$input  = Post_Input::content( $request );
		$errors = Services::validator()->validate_answer( Post_Input::validation_fields( $request, $input ) );
		if ( $errors->has_errors() ) {
			return Rest_Errors::invalid( $errors );
		}

		$actor  = $this->actor();
		$queued = null !== Services::permissions()->moderation_reason( $actor );
		$author = Post_Input::author( $actor, $request );

		$id     = Services::post_service()->create_answer( $author['actor'], $question, $input['content'], $input['format'], $author['notify'], $author['name'], $queued );
		$answer = 0 === $id ? null : Services::posts()->find( $id );
		if ( null === $answer ) {
			return new WP_Error( 'easyq_not_created', __( 'The answer could not be saved.', 'easyq' ), array( 'status' => 500 ) );
		}

		$response = new WP_REST_Response( $this->formatter()->format( $answer, $author['actor'], 'edit' ) + array( 'queued' => $queued ), 201 );
		$response->header( 'Location', rest_url( sprintf( '%s/%s/%d', $this->namespace, $this->rest_base, $id ) ) );

		return $response;
	}

	/**
	 * Checks whether the request may edit the answer.
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

		$answer = $this->visible_post( (int) self::int_param( $request, 'id' ), $actor, Post::ANSWER );
		if ( $answer instanceof WP_Error ) {
			return $answer;
		}

		if ( ! Services::permissions()->post_rules( $answer, $actor )['editable'] ) {
			return Rest_Errors::permission( 'level' );
		}

		if ( self::bool_param( $request, 'silent' ) && ! user_can( (int) $actor->user_id, 'easyq_edit_silently' ) ) {
			return Rest_Errors::permission( 'level' );
		}

		return true;
	}

	/**
	 * Edits an answer.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ): WP_REST_Response|WP_Error {
		$actor    = $this->actor();
		$answer   = Services::posts()->find( (int) self::int_param( $request, 'id' ) );
		$question = null === $answer ? null : Services::post_service()->question_of( $answer );
		if ( null === $answer || null === $question ) {
			return Rest_Errors::not_found();
		}

		$input   = Post_Input::content( $request );
		$content = $request->has_param( 'content' ) ? $input['content'] : (string) $answer->content;
		$format  = $request->has_param( 'content' ) ? $input['format'] : $answer->format;

		$errors = Services::validator()->validate_answer(
			array(
				'content' => $content,
				'format'  => $format,
			)
		);
		if ( $errors->has_errors() ) {
			return Rest_Errors::invalid( $errors );
		}

		$remoderate = Options::get_bool( 'spam', 'moderate_edited_again' ) && null !== Services::permissions()->moderation_reason( $actor );
		$notify     = $request->has_param( 'notify' ) ? Post_Input::author( $actor, $request )['notify'] : $answer->notify;

		Services::post_service()->edit_answer( $actor, $answer, $question, $content, $format, $notify, null, $remoderate, self::bool_param( $request, 'silent' ) );

		$updated = Services::posts()->find( $answer->id );

		return null === $updated ? Rest_Errors::not_found() : new WP_REST_Response( $this->formatter()->format( $updated, $actor, 'edit' ) );
	}

	/**
	 * Returns the schema of an answer.
	 *
	 * @return array<mixed>
	 */
	public function get_item_schema(): array {
		if ( ! $this->schema ) {
			$this->schema = Post_Schema::get( 'answer' );
		}

		return $this->add_additional_fields_schema( $this->schema );
	}
}
