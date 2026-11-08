<?php
/**
 * REST endpoints for the newsletter.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\REST;

use EasyQ\Capabilities;
use EasyQ\Newsletter\Newsletter;
use EasyQ\Services;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Sending the newsletter, and confirming a subscription.
 *
 * Routes:
 *
 * - `GET /easyq/v1/newsletter`
 * - `POST /easyq/v1/newsletter/{action}` with `start`, `cancel` or `test`
 * - `POST /easyq/v1/newsletter/confirm` with `user` and `key`
 *
 * @since 0.1.0
 */
final class Newsletter_Controller extends Controller {

	/**
	 * Route base.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	protected $rest_base = 'newsletter';

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
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . $this->rest_base . '/(?P<action>start|cancel|test)',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'run_action' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . $this->rest_base . '/confirm',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'confirm' ),
					'permission_callback' => array( $this, 'confirm_permissions_check' ),
					'args'                => array(
						'user' => array(
							'description' => __( 'The user who subscribes.', 'easyq' ),
							'type'        => 'integer',
							'required'    => true,
						),
						'key'  => array(
							'description' => __( 'Key from the confirmation email.', 'easyq' ),
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
	 * Checks that the user may send the newsletter.
	 *
	 * @since 0.1.0
	 *
	 * @return true|WP_Error
	 */
	public function can_manage(): bool|WP_Error {
		return current_user_can( Capabilities::MANAGE ) ? true : REST_Errors::permission( is_user_logged_in() ? 'level' : 'login' );
	}

	/**
	 * Returns the progress of the sending.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response
	 */
	public function get_item( $request ): WP_REST_Response {
		return $this->respond_with( Newsletter::progress(), $request );
	}

	/**
	 * Starts or cancels the sending, or sends a test to the current user.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function run_action( $request ): WP_REST_Response|WP_Error {
		$newsletter = Services::newsletter();
		switch ( self::string_param( $request, 'action' ) ) {
			case 'start':
				$result = $newsletter->start();
				break;
			case 'cancel':
				Newsletter::cancel();
				$result = true;
				break;
			default:
				$result = Newsletter::ready();
				$user   = wp_get_current_user();
				if ( true === $result && ! $newsletter->send_to( $user ) ) {
					$result = new WP_Error( 'easyq_email_error', __( 'The test email could not be sent.', 'easyq' ), array( 'status' => 500 ) );
				}
		}

		return $result instanceof WP_Error ? $result : $this->respond_with( Newsletter::progress(), $request );
	}

	/**
	 * Checks the key from the confirmation email, which stands in for a login.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return true|WP_Error
	 */
	public function confirm_permissions_check( $request ): bool|WP_Error {
		return Newsletter::check_key( (int) self::int_param( $request, 'user' ), (string) self::string_param( $request, 'key' ) );
	}

	/**
	 * Confirms a subscription.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function confirm( $request ): WP_REST_Response|WP_Error {
		$confirmed = Services::newsletter()->confirm( (int) self::int_param( $request, 'user' ), (string) self::string_param( $request, 'key' ) );

		return $confirmed instanceof WP_Error ? $confirmed : $this->respond_with( array( 'subscribed' => true ), $request );
	}

	/**
	 * Builds the schema of the response.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	protected function item_schema(): array {
		return Schemas::newsletter();
	}
}
