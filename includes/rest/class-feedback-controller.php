<?php
/**
 * REST endpoint for feedback.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\REST;

use EasyQ\Feedback\Feedback;
use EasyQ\Services;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Sends feedback to the site owner.
 *
 * Routes:
 *
 * - `POST /easyq/v1/feedback` with `message`, `name`, `email` and `previous`
 *
 * @since 0.1.0
 */
final class Feedback_Controller extends Controller {

	/**
	 * Route base.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	protected $rest_base = 'feedback';

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
					// Visitors may send feedback, unless the form is off, they are blocked or reached the limit.
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => array(
						'message'  => array(
							'description' => __( 'The feedback.', 'easyq' ),
							'type'        => 'string',
							'required'    => true,
						),
						'name'     => array(
							'description' => __( 'Name of the sender.', 'easyq' ),
							'type'        => 'string',
							'default'     => '',
						),
						'email'    => array(
							'description' => __( 'Email address to reply to.', 'easyq' ),
							'type'        => 'string',
							'default'     => '',
						),
						'previous' => array(
							'description' => __( 'Address of the page the sender came from.', 'easyq' ),
							'type'        => 'string',
							'default'     => '',
						),
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Checks whether the actor may send feedback.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return true|WP_Error
	 */
	public function create_item_permissions_check( $request ): bool|WP_Error {
		$reason = Services::feedback()->error( $this->actor() );

		return null === $reason ? true : Feedback::refused( $reason );
	}

	/**
	 * Sends feedback to the site owner.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ): WP_REST_Response|WP_Error {
		$sent = Services::feedback()->send(
			$this->actor(),
			sanitize_textarea_field( (string) self::string_param( $request, 'message' ) ),
			sanitize_text_field( (string) self::string_param( $request, 'name' ) ),
			trim( (string) self::string_param( $request, 'email' ) ),
			esc_url_raw( (string) self::string_param( $request, 'previous' ) )
		);
		if ( $sent instanceof WP_Error ) {
			return $sent;
		}

		return $this->respond_with( array( 'sent' => true ), $request );
	}

	/**
	 * Builds the schema of the response.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	protected function item_schema(): array {
		return Schemas::feedback();
	}
}
