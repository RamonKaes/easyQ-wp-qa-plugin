<?php
/**
 * REST endpoints for notices.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\REST;

use EasyQ\Services;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * The notices of the current user or visitor.
 *
 * Routes:
 *
 * - `GET /easyq/v1/notices`
 * - `DELETE /easyq/v1/notices/{id}`
 *
 * @since 0.1.0
 */
final class Notices_Controller extends Controller {

	/**
	 * Route base.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	protected $rest_base = 'notices';

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
					'permission_callback' => '__return_true',
					'args'                => $this->read_args(),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . $this->rest_base . '/(?P<id>[a-z0-9]+)',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					// The notice decides: only your own can be dismissed; see delete_item().
					'permission_callback' => '__return_true',
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Lists the notices.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response
	 */
	public function get_items( $request ): WP_REST_Response {
		return new WP_REST_Response( $this->collection( Services::notices()->for_actor( $this->actor() ), $request ) );
	}

	/**
	 * Dismisses a notice.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ): WP_REST_Response|WP_Error {
		if ( ! Services::notices()->dismiss( $this->actor(), (string) self::string_param( $request, 'id' ) ) ) {
			return REST_Errors::not_found();
		}

		return new WP_REST_Response( array( 'deleted' => true ) );
	}

	/**
	 * Prepares a notice for a response.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed           $item    Notice.
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response
	 */
	public function prepare_item_for_response( $item, $request ): WP_REST_Response {
		$data = array();
		foreach ( is_array( $item ) ? $item : array() as $key => $value ) {
			$data[ (string) $key ] = $value;
		}

		return $this->respond_with( $data, $request );
	}

	/**
	 * Builds the schema of the response.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	protected function item_schema(): array {
		return Schemas::notice();
	}
}
