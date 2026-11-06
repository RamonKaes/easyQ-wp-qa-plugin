<?php
/**
 * REST endpoint to stop the emails about a post.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\REST;

use EasyQ\Mail\Unsubscribe;
use EasyQ\Newsletter\Newsletter;
use EasyQ\Services;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Stops the emails about a post with the signed token from such an email, without logging in.
 * Mail programs post to this route for a one-click unsubscribe (RFC 8058).
 *
 * Routes:
 *
 * - `POST /easyq/v1/unsubscribe` with `post` and `token`, or `user` and `token` for the newsletter
 *
 * @since 0.1.0
 */
final class Unsubscribe_Controller extends Controller {

	/**
	 * Route base.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	protected $rest_base = 'unsubscribe';

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
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					// The token in the request is the permission; see create_item().
					'permission_callback' => '__return_true',
					'args'                => array(
						'post'  => array(
							'description' => __( 'Post to stop the emails about.', 'easyq' ),
							'type'        => 'integer',
						),
						'user'  => array(
							'description' => __( 'User who stops the newsletter, instead of a post.', 'easyq' ),
							'type'        => 'integer',
						),
						'token' => array(
							'description' => __( 'Token from the email.', 'easyq' ),
							'type'        => 'string',
							'required'    => true,
						),
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Stops the emails about a post.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ): WP_REST_Response|WP_Error {
		$user = self::int_param( $request, 'user' );
		if ( null !== $user ) {
			if ( ! Newsletter::verify( $user, (string) self::string_param( $request, 'token' ) ) ) {
				return new WP_Error( 'easyq_invalid_token', __( 'This link is not valid.', 'easyq' ), array( 'status' => 403 ) );
			}

			Newsletter::unsubscribe( $user );

			return $this->respond_with(
				array(
					'user'       => $user,
					'newsletter' => false,
				),
				$request
			);
		}

		$id   = (int) self::int_param( $request, 'post' );
		$post = Unsubscribe::verify( $id, (string) self::string_param( $request, 'token' ) ) ? Services::posts()->find( $id ) : null;
		if ( null === $post ) {
			return new WP_Error( 'easyq_invalid_token', __( 'This link is not valid.', 'easyq' ), array( 'status' => 403 ) );
		}

		Unsubscribe::stop( $post );

		return $this->respond_with(
			array(
				'post'   => $id,
				'emails' => false,
			),
			$request
		);
	}

	/**
	 * Builds the schema of the response.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	protected function item_schema(): array {
		return Schemas::unsubscribe();
	}
}
