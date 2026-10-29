<?php
/**
 * REST endpoints for categories and tags.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\REST;

use EasyQ\Capabilities;
use EasyQ\Categories\Category;
use EasyQ\Posts\Tags;
use EasyQ\Services;
use EasyQ\Settings\Options;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Lists categories and tags, and lets users who manage easyQ change the categories.
 *
 * Routes:
 *
 * - `GET /easyq/v1/categories`, optionally with `parent`
 * - `POST /easyq/v1/categories`
 * - `GET`, `PUT`/`PATCH` and `DELETE /easyq/v1/categories/{id}`
 * - `POST /easyq/v1/categories/{id}/reassign`, with the ID 0 for questions without a category
 * - `GET /easyq/v1/tags`, optionally with `search` for a prefix
 *
 * @since 0.1.0
 */
final class Taxonomy_Controller extends Controller {

	/**
	 * Registers the routes.
	 *
	 * @since 0.1.0
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/categories',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_categories' ),
					'permission_callback' => array( $this, 'view_permissions_check' ),
					'args'                => array(
						'parent' => array(
							'description' => __( 'Only children of this category; 0 for top-level categories. Omit for all categories.', 'easyq' ),
							'type'        => 'integer',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_category' ),
					'permission_callback' => array( $this, 'manage_permissions_check' ),
					'args'                => $this->category_args( true ),
				),
				'schema' => array( Schemas::class, 'category' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/categories/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_category' ),
					'permission_callback' => array( $this, 'view_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_category' ),
					'permission_callback' => array( $this, 'manage_permissions_check' ),
					'args'                => $this->category_args( false ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_category' ),
					'permission_callback' => array( $this, 'manage_permissions_check' ),
					'args'                => array(
						'reassign' => array(
							'description' => __( 'Category that receives the questions; 0 or omitted to leave them without a category.', 'easyq' ),
							'type'        => 'integer',
							'minimum'     => 0,
						),
					),
				),
				'schema' => array( Schemas::class, 'category' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/categories/(?P<id>\d+)/reassign',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'reassign_category' ),
					'permission_callback' => array( $this, 'manage_permissions_check' ),
					'args'                => array(
						'to' => array(
							'description' => __( 'Category that receives the questions directly in this category; 0 for no category.', 'easyq' ),
							'type'        => 'integer',
							'minimum'     => 0,
							'required'    => true,
						),
					),
				),
				'schema' => array( Schemas::class, 'category_reassign' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/tags',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_tags' ),
					'permission_callback' => array( $this, 'view_permissions_check' ),
					'args'                => array(
						'search' => array(
							'description' => __( 'Only tags starting with this text.', 'easyq' ),
							'type'        => 'string',
							'default'     => '',
						),
					) + $this->pagination_args( Options::get_int( 'lists', 'page_size_tags' ) ),
				),
				'schema' => array( Schemas::class, 'tag' ),
			)
		);
	}

	/**
	 * Checks whether the request may see the Q&A area.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return true|WP_Error
	 */
	public function view_permissions_check( $request ): bool|WP_Error {
		return $this->check( 'permit_view_q_page', $this->actor() );
	}

	/**
	 * Checks whether the current user may change categories.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return true|WP_Error
	 */
	public function manage_permissions_check( $request ): bool|WP_Error {
		if ( current_user_can( Capabilities::MANAGE ) ) {
			return true;
		}

		return REST_Errors::permission( is_user_logged_in() ? 'level' : 'login' );
	}

	/**
	 * Lists categories.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response
	 */
	public function get_categories( $request ): WP_REST_Response {
		$categories = Services::categories();

		if ( $request->has_param( 'parent' ) ) {
			$parent = self::int_param( $request, 'parent' );
			$list   = $categories->children( 0 === $parent ? null : $parent );
		} else {
			$list = array_values( $categories->all() );
		}

		return new WP_REST_Response( array_map( array( $this, 'format_category' ), $list ) );
	}

	/**
	 * Returns a category.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_category( $request ): WP_REST_Response|WP_Error {
		$category = Services::categories()->find( (int) self::int_param( $request, 'id' ) );

		return null === $category ? REST_Errors::not_found() : new WP_REST_Response( $this->format_category( $category ) );
	}

	/**
	 * Creates a category.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_category( $request ): WP_REST_Response|WP_Error {
		$categories = Services::categories();
		$result     = $categories->create(
			self::parent_param( $request, 'parent' ),
			sanitize_text_field( (string) self::string_param( $request, 'title' ) ),
			sanitize_title( (string) self::string_param( $request, 'slug' ) ),
			sanitize_textarea_field( (string) self::string_param( $request, 'description' ) ),
			self::int_param( $request, 'position' )
		);
		if ( $result instanceof WP_Error ) {
			return REST_Errors::invalid( $result );
		}

		$category = $categories->find( $result );
		if ( null === $category ) {
			return REST_Errors::not_found();
		}

		$response = new WP_REST_Response( $this->format_category( $category ), 201 );
		$response->header( 'Location', rest_url( self::NAMESPACE . '/categories/' . $result ) );

		return $response;
	}

	/**
	 * Changes a category. Parameters that are not passed keep their value.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_category( $request ): WP_REST_Response|WP_Error {
		$categories = Services::categories();
		$id         = (int) self::int_param( $request, 'id' );
		$category   = $categories->find( $id );
		if ( null === $category ) {
			return REST_Errors::not_found();
		}

		$result = $categories->save(
			$id,
			$request->has_param( 'title' ) ? sanitize_text_field( (string) self::string_param( $request, 'title' ) ) : $category->title,
			$request->has_param( 'slug' ) ? sanitize_title( (string) self::string_param( $request, 'slug' ) ) : $category->slug,
			$request->has_param( 'description' ) ? sanitize_textarea_field( (string) self::string_param( $request, 'description' ) ) : $category->description,
			$request->has_param( 'parent' ) ? self::parent_param( $request, 'parent' ) : $category->parent_id,
			self::int_param( $request, 'position' )
		);
		if ( $result instanceof WP_Error ) {
			return REST_Errors::invalid( $result );
		}

		$updated = $categories->find( $id );

		return null === $updated ? REST_Errors::not_found() : new WP_REST_Response( $this->format_category( $updated ) );
	}

	/**
	 * Deletes a category without subcategories.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_category( $request ): WP_REST_Response|WP_Error {
		$categories = Services::categories();
		$category   = $categories->find( (int) self::int_param( $request, 'id' ) );
		if ( null === $category ) {
			return REST_Errors::not_found();
		}

		$result = $categories->delete( $category->id, self::parent_param( $request, 'reassign' ) );
		if ( $result instanceof WP_Error ) {
			return REST_Errors::invalid( $result );
		}

		return new WP_REST_Response(
			array(
				'deleted'  => true,
				'previous' => $this->format_category( $category ),
			)
		);
	}

	/**
	 * Moves the questions directly in a category, or without a category, to another category.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function reassign_category( $request ): WP_REST_Response|WP_Error {
		$categories = Services::categories();
		$from       = self::parent_param( $request, 'id' );
		if ( null !== $from && null === $categories->find( $from ) ) {
			return REST_Errors::not_found();
		}

		$moved  = $categories->count_direct_questions( $from );
		$result = $categories->reassign( $from, self::parent_param( $request, 'to' ) );
		if ( $result instanceof WP_Error ) {
			return REST_Errors::invalid( $result );
		}

		return new WP_REST_Response( array( 'moved' => $moved ) );
	}

	/**
	 * Lists tags.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response
	 */
	public function get_tags( $request ): WP_REST_Response {
		$per_page = self::int_param( $request, 'per_page' ) ?? 30;
		$result   = ( new Tags() )->popular( (string) self::string_param( $request, 'search' ), self::int_param( $request, 'page' ) ?? 1, $per_page );

		return $this->paginated( $result['tags'], $result['total'], $per_page );
	}

	/**
	 * Returns the arguments for creating or changing a category.
	 *
	 * @since 0.1.0
	 *
	 * @param bool $create Whether the category is created, which requires a name.
	 * @return array<string, array<string, mixed>>
	 */
	private function category_args( bool $create ): array {
		return array(
			'title'       => array(
				'description' => __( 'Name.', 'easyq' ),
				'type'        => 'string',
				'required'    => $create,
			),
			'slug'        => array(
				'description' => $create ? __( 'Slug; derived from the name if omitted.', 'easyq' ) : __( 'Slug; derived from the name if empty.', 'easyq' ),
				'type'        => 'string',
			),
			'description' => array(
				'description' => __( 'Description.', 'easyq' ),
				'type'        => 'string',
			),
			'parent'      => array(
				'description' => $create ? __( 'Parent category; 0 or omitted for the top level.', 'easyq' ) : __( 'Parent category; 0 for the top level. A moved category goes to the end of its new siblings unless a position is given.', 'easyq' ),
				'type'        => 'integer',
				'minimum'     => 0,
			),
			'position'    => array(
				'description' => __( 'Position among the siblings, starting at 1; larger values put the category at the end.', 'easyq' ),
				'type'        => 'integer',
				'minimum'     => 1,
			),
		);
	}

	/**
	 * Returns a category ID parameter in which 0 stands for none.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @param string          $key     Parameter name.
	 * @return int|null
	 */
	private static function parent_param( WP_REST_Request $request, string $key ): ?int {
		$id = self::int_param( $request, $key );

		return null === $id || $id <= 0 ? null : $id;
	}

	/**
	 * Describes a category.
	 *
	 * @since 0.1.0
	 *
	 * @param Category $category Category.
	 * @return array<string, mixed>
	 */
	public function format_category( Category $category ): array {
		return array(
			'id'             => $category->id,
			'parent'         => $category->parent_id,
			'title'          => $category->title,
			'slug'           => $category->slug,
			'description'    => $category->description,
			'question_count' => $category->question_count,
			'position'       => $category->position,
			'path'           => $category->path_slugs(),
		);
	}
}
