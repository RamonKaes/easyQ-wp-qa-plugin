<?php
/**
 * REST endpoints for favorites.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\REST;

use EasyQ\Favorites\Favorites;
use EasyQ\Frontend\URLs;
use EasyQ\Services;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * The favorites of the logged in user.
 *
 * Routes:
 *
 * - `GET /easyq/v1/favorites` with `type`
 * - `POST`, `DELETE /easyq/v1/favorites` with `type` and `id`
 *
 * @since 0.1.0
 */
final class Favorites_Controller extends Controller {

	/**
	 * Route base.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	protected $rest_base = 'favorites';

	/**
	 * Registers the routes.
	 *
	 * @since 0.1.0
	 */
	public function register_routes(): void {
		$type = array(
			'description' => __( 'Type of the favorite.', 'easyq' ),
			'type'        => 'string',
			'enum'        => array_keys( Favorites::TYPES ),
			'required'    => true,
		);
		$item = array(
			'type' => $type,
			'id'   => array(
				'description' => __( 'ID of the question, user or category, or the name of the tag.', 'easyq' ),
				'type'        => array( 'integer', 'string' ),
				'required'    => true,
			),
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'logged_in' ),
					'args'                => $this->read_args() + array(
						'type' => array( 'default' => 'question' ) + array_diff_key( $type, array( 'required' => true ) ),
					) + $this->pagination_args( 20 ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'logged_in' ),
					'args'                => $item,
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'logged_in' ),
					'args'                => $item,
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Checks that the request comes from a logged in user.
	 *
	 * @since 0.1.0
	 *
	 * @return true|WP_Error
	 */
	public function logged_in(): bool|WP_Error {
		return is_user_logged_in() ? true : REST_Errors::permission( 'login' );
	}

	/**
	 * Lists the favorites of a type.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response
	 */
	public function get_items( $request ): WP_REST_Response {
		$type    = (string) self::string_param( $request, 'type' );
		$user_id = get_current_user_id();
		$items   = array();

		if ( 'tag' === $type ) {
			foreach ( Services::favorites()->tags( $user_id ) as $tag ) {
				$items[] = $this->item( 'tag', $tag['name'] );
			}
		} else {
			foreach ( Services::favorites()->ids( $user_id, $type ) as $id ) {
				$items[] = $this->item( $type, $id );
			}
		}

		$items    = array_values( array_filter( $items ) );
		$per_page = max( 1, (int) self::int_param( $request, 'per_page' ) );
		$page     = max( 1, (int) self::int_param( $request, 'page' ) );

		return $this->paginated(
			$this->collection( array_slice( $items, ( $page - 1 ) * $per_page, $per_page ), $request ),
			count( $items ),
			$per_page
		);
	}

	/**
	 * Adds a favorite.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ): WP_REST_Response|WP_Error {
		[ $type, $id ] = $this->target( $request );

		$result = Services::favorites()->set( $this->actor(), $type, $id, true );
		if ( $result instanceof WP_Error ) {
			return REST_Errors::with_status( $result, 'easyq_favorite_self' === $result->get_error_code() ? 400 : 404 );
		}

		return $this->respond_with( (array) $this->item( $type, $id ), $request );
	}

	/**
	 * Removes a favorite and responds with it, as core does for deleted items.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ): WP_REST_Response|WP_Error {
		[ $type, $id ] = $this->target( $request );

		$previous = $this->respond_with( (array) $this->item( $type, $id ), $request )->get_data();
		$result   = Services::favorites()->set( $this->actor(), $type, $id, false );
		if ( $result instanceof WP_Error ) {
			return REST_Errors::with_status( $result, 404 );
		}

		return new WP_REST_Response(
			array(
				'deleted'  => true,
				'previous' => $previous,
			)
		);
	}

	/**
	 * Returns the type and ID of a request; numbers for everything but tags.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return array{0: string, 1: int|string}
	 */
	private function target( WP_REST_Request $request ): array {
		$type = (string) self::string_param( $request, 'type' );
		$id   = $request->get_param( 'id' );

		return array( $type, 'tag' === $type ? ( is_scalar( $id ) ? (string) $id : '' ) : ( is_numeric( $id ) ? (int) $id : 0 ) );
	}

	/**
	 * Returns the data of a favorite.
	 *
	 * @since 0.1.0
	 *
	 * @param string     $type Type name.
	 * @param int|string $id   ID, for tags the name.
	 * @return array{type: string, id: int|string, title: string, link: string}|null Null if it no longer exists.
	 */
	private function item( string $type, int|string $id ): ?array {
		$title = '';
		$link  = '';
		switch ( $type ) {
			case 'question':
				$question = Services::posts()->find( (int) $id );
				if ( null !== $question && Services::permissions()->post_rules( $question, $this->actor() )['viewable'] ) {
					$title = Services::content_renderer()->text( (string) $question->title );
					$link  = URLs::question( $question );
				}
				break;
			case 'user':
				$user = get_userdata( (int) $id );
				if ( false !== $user ) {
					$title = $user->display_name;
					$link  = URLs::user( $user );
				}
				break;
			case 'category':
				$category = Services::categories()->find( (int) $id );
				if ( null !== $category ) {
					$title = $category->title;
					$link  = URLs::category( $category );
				}
				break;
			default:
				$title = (string) $id;
				$link  = URLs::tag( (string) $id );
		}

		return '' === $link ? null : array(
			'type'  => $type,
			'id'    => $id,
			'title' => $title,
			'link'  => $link,
		);
	}

	/**
	 * Prepares a favorite for a response.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed           $item    Data of the favorite.
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
		return Schemas::favorite();
	}
}
