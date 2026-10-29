<?php
/**
 * REST endpoint for tags.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\REST;

use EasyQ\Posts\Tags;
use EasyQ\Settings\Options;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Lists tags by popularity.
 *
 * Routes:
 *
 * - `GET /easyq/v1/tags`, optionally with `search` for a prefix
 *
 * @since 0.1.0
 */
final class Tags_Controller extends Controller {

	/**
	 * Route base.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	protected $rest_base = 'tags';

	/**
	 * Registers the routes.
	 *
	 * @since 0.1.0
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
					'args'                => array(
						'search' => array(
							'description' => __( 'Only tags starting with this text.', 'easyq' ),
							'type'        => 'string',
							'default'     => '',
						),
					) + $this->pagination_args( Options::get_int( 'lists', 'page_size_tags' ) ) + $this->read_args(),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
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
	public function get_items_permissions_check( $request ): bool|WP_Error {
		return $this->check( 'permit_view_q_page', $this->actor() );
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
	public function get_items( $request ): WP_REST_Response {
		$per_page = self::int_param( $request, 'per_page' ) ?? 30;
		$result   = ( new Tags() )->popular( (string) self::string_param( $request, 'search' ), self::int_param( $request, 'page' ) ?? 1, $per_page );

		return $this->paginated( $this->collection( $result['tags'], $request ), $result['total'], $per_page );
	}

	/**
	 * Prepares a tag for the response.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed           $item    Tag with `name` and `count`.
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function prepare_item_for_response( $item, $request ): WP_REST_Response|WP_Error {
		if ( ! is_array( $item ) ) {
			return new WP_Error( 'easyq_invalid_item', __( 'The item cannot be shown.', 'easyq' ), array( 'status' => 500 ) );
		}

		/**
		 * Tag with `name` and `count`, see Tags::popular().
		 *
		 * @var array<string, mixed> $item
		 */
		return $this->respond_with( $item, $request );
	}

	/**
	 * Builds the schema of a tag.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	protected function item_schema(): array {
		return Schemas::tag();
	}
}
