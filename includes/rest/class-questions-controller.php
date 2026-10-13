<?php
/**
 * REST endpoints for questions.
 *
 * @package easyQ
 */

namespace EasyQ\Rest;

use EasyQ\Limits\Limits;
use EasyQ\Posts\Post;
use EasyQ\Posts\Question_Query;
use EasyQ\Services;
use EasyQ\Settings\Options;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Lists, creates, reads and edits questions; finds similar questions for the ask form.
 *
 * Routes:
 *
 * - `GET  /easyq/v1/questions`
 * - `POST /easyq/v1/questions`
 * - `GET  /easyq/v1/questions/similar`
 * - `GET  /easyq/v1/questions/{id}`
 * - `PUT  /easyq/v1/questions/{id}`
 */
final class Questions_Controller extends Controller {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'questions';

	/**
	 * Registers the routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => $this->question_args( true ),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . $this->rest_base . '/similar',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_similar' ),
				'permission_callback' => array( $this, 'get_items_permissions_check' ),
				'args'                => array(
					'title' => array(
						'description' => __( 'Title of the question being asked.', 'easyq' ),
						'type'        => 'string',
						'required'    => true,
					),
				),
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
					'args'                => array(
						'context' => $this->get_context_param( array( 'default' => 'view' ) ),
					),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => $this->question_args( false ) + array(
						'silent' => array(
							'description' => __( 'Whether to hide that the question was edited.', 'easyq' ),
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
	 * Checks whether the request may list questions.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return true|WP_Error
	 */
	public function get_items_permissions_check( $request ): bool|WP_Error {
		return $this->check( 'permit_view_q_page', $this->actor() );
	}

	/**
	 * Lists questions.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response
	 */
	public function get_items( $request ): WP_REST_Response {
		$per_page = self::int_param( $request, 'per_page' ) ?? 20;
		$result   = Services::question_query()->find(
			array(
				'source'      => self::string_param( $request, 'source' ),
				'category_id' => self::int_param( $request, 'category' ),
				'tag'         => self::string_param( $request, 'tag' ),
				'user_id'     => self::int_param( $request, 'author' ),
				'page'        => self::int_param( $request, 'page' ),
				'per_page'    => $per_page,
			)
		);

		$actor = $this->actor();
		$items = array_map( fn ( Post $post ): array => $this->formatter()->format( $post, $actor ), $result['posts'] );

		return $this->paginated( $items, $result['total'], $per_page );
	}

	/**
	 * Returns a question.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ): WP_REST_Response|WP_Error {
		$actor    = $this->actor();
		$question = $this->visible_post( (int) self::int_param( $request, 'id' ), $actor, Post::QUESTION );
		if ( $question instanceof WP_Error ) {
			return $question;
		}

		$context = 'edit' === $request->get_param( 'context' ) ? 'edit' : 'view';

		return new WP_REST_Response( $this->formatter()->format( $question, $actor, $context, Services::posts()->children( $question->id ) ) );
	}

	/**
	 * Checks whether the request may ask a question.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return true|WP_Error
	 */
	public function create_item_permissions_check( $request ): bool|WP_Error {
		$actor    = $this->actor();
		$writable = $this->check_writable( $actor );

		return true === $writable ? $this->check( 'permit_post_q', $actor, null, Limits::QUESTIONS ) : $writable;
	}

	/**
	 * Asks a question.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ): WP_REST_Response|WP_Error {
		$input       = Post_Input::content( $request );
		$title       = sanitize_text_field( (string) self::string_param( $request, 'title' ) );
		$tags        = Post_Input::uses_tags() ? Post_Input::tags( $request->get_param( 'tags' ) ) : array();
		$category_id = Post_Input::uses_categories() ? self::int_param( $request, 'category' ) : null;

		if ( null !== $category_id && null === Services::categories()->find( $category_id ) ) {
			return Rest_Errors::invalid( new WP_Error( 'category', __( 'The category does not exist.', 'easyq' ) ) );
		}

		$fields = array(
			'title'   => $title,
			'content' => $input['content'],
			'format'  => $input['format'],
		);
		if ( Post_Input::uses_tags() ) {
			$fields['tags'] = $tags;
		}

		$errors = Services::validator()->validate_question( Post_Input::validation_fields( $request, $fields ) );
		if ( $errors->has_errors() ) {
			return Rest_Errors::invalid( $errors );
		}

		$actor  = $this->actor();
		$queued = null !== Services::permissions()->moderation_reason( $actor );
		$author = Post_Input::author( $actor, $request );
		$extra  = self::string_param( $request, 'extra' );

		$id = Services::post_service()->create_question(
			$author['actor'],
			$title,
			$input['content'],
			$input['format'],
			$tags,
			$category_id,
			$author['notify'],
			$author['name'],
			null,
			$queued,
			null === $extra ? null : sanitize_textarea_field( $extra )
		);

		return $this->created( $id, $author['actor'], $queued );
	}

	/**
	 * Checks whether the request may edit a question.
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

		$question = $this->visible_post( (int) self::int_param( $request, 'id' ), $actor, Post::QUESTION );
		if ( $question instanceof WP_Error ) {
			return $question;
		}

		$rules        = Services::permissions()->post_rules( $question, $actor );
		$changes_text = $request->has_param( 'title' ) || $request->has_param( 'content' );
		$changes_meta = $request->has_param( 'tags' ) || $request->has_param( 'category' );

		if ( ( $changes_text && ! $rules['editable'] ) || ( $changes_meta && ! $rules['retaggable'] && ! $rules['editable'] ) ) {
			return Rest_Errors::permission( 'level' );
		}

		if ( self::bool_param( $request, 'silent' ) && ! user_can( (int) $actor->user_id, 'easyq_edit_silently' ) ) {
			return Rest_Errors::permission( 'level' );
		}

		return true;
	}

	/**
	 * Edits a question.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ): WP_REST_Response|WP_Error {
		$actor    = $this->actor();
		$question = Services::posts()->find( (int) self::int_param( $request, 'id' ) );
		if ( null === $question ) {
			return Rest_Errors::not_found();
		}

		$input   = Post_Input::content( $request );
		$title   = $request->has_param( 'title' ) ? sanitize_text_field( (string) self::string_param( $request, 'title' ) ) : (string) $question->title;
		$content = $request->has_param( 'content' ) ? $input['content'] : (string) $question->content;
		$format  = $request->has_param( 'content' ) ? $input['format'] : $question->format;
		$tags    = $request->has_param( 'tags' ) && Post_Input::uses_tags() ? Post_Input::tags( $request->get_param( 'tags' ) ) : $question->tag_list();

		$fields = array();
		if ( $request->has_param( 'title' ) ) {
			$fields['title'] = $title;
		}
		if ( $request->has_param( 'content' ) ) {
			$fields['content'] = $content;
			$fields['format']  = $format;
		}
		if ( $request->has_param( 'tags' ) ) {
			$fields['tags'] = $tags;
		}

		$errors = Services::validator()->validate_question( $fields, false );
		if ( $errors->has_errors() ) {
			return Rest_Errors::invalid( $errors );
		}

		$category_id = $question->category_id;
		if ( $request->has_param( 'category' ) && Post_Input::uses_categories() ) {
			$category_id = self::int_param( $request, 'category' );
			if ( null !== $category_id && null === Services::categories()->find( $category_id ) ) {
				return Rest_Errors::invalid( new WP_Error( 'category', __( 'The category does not exist.', 'easyq' ) ) );
			}
		}

		$silent     = self::bool_param( $request, 'silent' );
		$remoderate = Options::get_bool( 'spam', 'moderate_edited_again' ) && null !== Services::permissions()->moderation_reason( $actor );
		$notify     = $request->has_param( 'notify' ) ? Post_Input::author( $actor, $request )['notify'] : $question->notify;

		Services::post_service()->edit_question( $actor, $question, $title, $content, $format, $tags, $notify, null, $remoderate, $silent );

		if ( $category_id !== $question->category_id ) {
			$edited = Services::posts()->find( $question->id );
			if ( null !== $edited ) {
				Services::post_service()->set_category( $actor, $edited, $category_id, $silent );
			}
		}

		$updated = Services::posts()->find( $question->id );

		return null === $updated ? Rest_Errors::not_found() : new WP_REST_Response( $this->formatter()->format( $updated, $actor, 'edit' ) );
	}

	/**
	 * Finds questions similar to a title and suggests tags, for the ask form.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response
	 */
	public function get_similar( $request ): WP_REST_Response {
		$title        = (string) self::string_param( $request, 'title' );
		$check        = Options::get_bool( 'posting', 'do_ask_check_qs' );
		$example_tags = Post_Input::uses_tags() && Options::get_bool( 'posting', 'do_example_tags' );
		$questions    = array();
		$tags         = array();

		if ( ( $check || $example_tags ) && '' !== trim( $title ) ) {
			$count   = max( $example_tags ? 500 : 0, $check ? Options::get_int( 'posting', 'page_size_ask_check_qs' ) : 0 );
			$results = Services::search()->provider()->search( $title, 0, $count, $this->actor()->user_id );
			$posts   = Services::posts()->find_many( array_map( static fn ( $result ): int => $result->question_id, $results ) );

			if ( $example_tags ) {
				// Weigh the tags of related questions by how well the questions match.
				$weights = array();
				foreach ( $results as $result ) {
					foreach ( isset( $posts[ $result->question_id ] ) ? $posts[ $result->question_id ]->tag_list() : array() as $tag ) {
						$weights[ $tag ] = ( $weights[ $tag ] ?? 0 ) + exp( $result->score );
					}
				}
				arsort( $weights, SORT_NUMERIC );

				$min_weight = exp( self::min_score( Options::get_int( 'posting', 'match_example_tags' ) ) );
				foreach ( $weights as $tag => $weight ) {
					if ( $weight < $min_weight || count( $tags ) >= Options::get_int( 'posting', 'page_size_ask_tags' ) ) {
						break;
					}
					$tags[] = (string) $tag;
				}
			}

			if ( $check ) {
				$min_score = self::min_score( Options::get_int( 'posting', 'match_ask_check_qs' ) );
				$actor     = $this->actor();

				foreach ( array_slice( $results, 0, Options::get_int( 'posting', 'page_size_ask_check_qs' ) ) as $result ) {
					if ( $result->score < $min_score ) {
						break;
					}
					if ( isset( $posts[ $result->question_id ] ) ) {
						$questions[] = $this->formatter()->format( $posts[ $result->question_id ], $actor );
					}
				}
			}
		}

		return new WP_REST_Response(
			array(
				'questions' => $questions,
				'tags'      => $tags,
			)
		);
	}

	/**
	 * Returns the arguments for listing questions.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_collection_params(): array {
		return array(
			'source'   => array(
				'description' => __( 'Which questions to list and how to sort them.', 'easyq' ),
				'type'        => 'string',
				'enum'        => Question_Query::sources(),
				'default'     => 'recent',
			),
			'category' => array(
				'description' => __( 'Only questions in this category and its subcategories.', 'easyq' ),
				'type'        => 'integer',
			),
			'tag'      => array(
				'description' => __( 'Only questions with this tag.', 'easyq' ),
				'type'        => 'string',
			),
			'author'   => array(
				'description' => __( 'Only questions by this user.', 'easyq' ),
				'type'        => 'integer',
			),
		) + $this->pagination_args( Options::get_int( 'lists', 'page_size_qs' ) );
	}

	/**
	 * Returns the schema of a question.
	 *
	 * @return array<mixed>
	 */
	public function get_item_schema(): array {
		if ( $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}

		$this->schema = Post_Schema::get( 'question' );

		return $this->add_additional_fields_schema( $this->schema );
	}

	/**
	 * Returns the arguments of new and edited questions.
	 *
	 * @param bool $is_new Whether the question is new.
	 * @return array<string, array<string, mixed>>
	 */
	private function question_args( bool $is_new ): array {
		return array(
			'title'    => array(
				'description' => __( 'Title of the question.', 'easyq' ),
				'type'        => 'string',
				'required'    => $is_new,
			),
			'tags'     => array(
				'description' => __( 'Tags, as an array or as text entered by a user.', 'easyq' ),
				'type'        => array( 'array', 'string' ),
				'items'       => array( 'type' => 'string' ),
			),
			'category' => array(
				'description' => __( 'Category ID.', 'easyq' ),
				'type'        => array( 'integer', 'null' ),
			),
			'extra'    => array(
				'description' => __( 'Value of the extra question field.', 'easyq' ),
				'type'        => 'string',
			),
		) + Post_Input::content_args( false );
	}

	/**
	 * Returns the response for a new post.
	 *
	 * @param int          $id     Post ID, 0 on failure.
	 * @param \EasyQ\Actor $actor  Author.
	 * @param bool         $queued Whether the post waits for moderation.
	 * @return WP_REST_Response|WP_Error
	 */
	private function created( int $id, \EasyQ\Actor $actor, bool $queued ): WP_REST_Response|WP_Error {
		$post = 0 === $id ? null : Services::posts()->find( $id );
		if ( null === $post ) {
			return new WP_Error( 'easyq_not_created', __( 'The question could not be saved.', 'easyq' ), array( 'status' => 500 ) );
		}

		$response = new WP_REST_Response( $this->formatter()->format( $post, $actor, 'edit' ) + array( 'queued' => $queued ), 201 );
		$response->header( 'Location', rest_url( sprintf( '%s/%s/%d', $this->namespace, $this->rest_base, $id ) ) );

		return $response;
	}

	/**
	 * Converts the "match" setting (1–5) into the minimum search score, as in Question2Answer.
	 *
	 * @param int $setting Setting value.
	 * @return float
	 */
	private static function min_score( int $setting ): float {
		return 10 - 2 * $setting;
	}
}
