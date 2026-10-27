<?php
/**
 * REST endpoints for categories and tags.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\REST;

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
 * Lists categories and tags.
 *
 * Routes:
 *
 * - `GET /easyq/v1/categories`, optionally with `parent`
 * - `GET /easyq/v1/categories/{id}`
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
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_categories' ),
				'permission_callback' => array( $this, 'view_permissions_check' ),
				'args'                => array(
					'parent' => array(
						'description' => __( 'Only children of this category; 0 for top-level categories. Omit for all categories.', 'easyq' ),
						'type'        => 'integer',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/categories/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_category' ),
				'permission_callback' => array( $this, 'view_permissions_check' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/tags',
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
