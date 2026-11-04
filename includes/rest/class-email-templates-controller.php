<?php
/**
 * REST endpoints for email templates.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\REST;

use EasyQ\Admin\Email_Templates_Screen;
use EasyQ\Capabilities;
use EasyQ\Mail\Mailer;
use EasyQ\Mail\Templates;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and changes the texts of the emails, for users who manage easyQ.
 *
 * Routes:
 *
 * - `GET /easyq/v1/email-templates`
 * - `GET`, `PUT`/`PATCH` and `DELETE /easyq/v1/email-templates/{name}`; `DELETE` goes back to the default
 * - `POST /easyq/v1/email-templates/{name}/test` sends a test email to the current user
 *
 * @since 0.1.0
 */
final class Email_Templates_Controller extends Controller {

	/**
	 * Route base.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	protected $rest_base = 'email-templates';

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
					'args'                => $this->read_args(),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . $this->rest_base . '/(?P<name>[a-z_]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => $this->read_args(),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => array(
						'subject' => array(
							'description' => __( 'Subject of the email.', 'easyq' ),
							'type'        => 'string',
							'required'    => true,
						),
						'body'    => array(
							'description' => __( 'Text of the email.', 'easyq' ),
							'type'        => 'string',
							'required'    => true,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . $this->rest_base . '/(?P<name>[a-z_]+)/test',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'send_test' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
				),
				'schema' => array( $this, 'get_test_schema' ),
			)
		);
	}

	/**
	 * Checks whether the current user may manage easyQ.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return true|WP_Error
	 */
	public function get_items_permissions_check( $request ): bool|WP_Error {
		if ( current_user_can( Capabilities::MANAGE ) ) {
			return true;
		}

		return REST_Errors::permission( is_user_logged_in() ? 'level' : 'login' );
	}

	/**
	 * Lists the templates.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response
	 */
	public function get_items( $request ): WP_REST_Response {
		return new WP_REST_Response( $this->collection( array_keys( Templates::info() ), $request ) );
	}

	/**
	 * Returns a template.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ): WP_REST_Response|WP_Error {
		return $this->prepare_item_for_response( (string) self::string_param( $request, 'name' ), $request );
	}

	/**
	 * Changes a template.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ): WP_REST_Response|WP_Error {
		$name = (string) self::string_param( $request, 'name' );
		if ( null === Templates::get( $name ) ) {
			return REST_Errors::not_found();
		}

		if ( ! Templates::save( $name, (string) self::string_param( $request, 'subject' ), (string) self::string_param( $request, 'body' ) ) ) {
			return new WP_Error( 'easyq_invalid_template', __( 'An email needs a subject and a text.', 'easyq' ), array( 'status' => 400 ) );
		}

		return $this->prepare_item_for_response( $name, $request );
	}

	/**
	 * Goes back to the default of a template.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ): WP_REST_Response|WP_Error {
		$name     = (string) self::string_param( $request, 'name' );
		$previous = $this->prepare_item_for_response( $name, $request );
		if ( $previous instanceof WP_Error ) {
			return $previous;
		}

		Templates::reset( $name );

		return new WP_REST_Response(
			array(
				'deleted'  => true,
				'previous' => $previous->get_data(),
			)
		);
	}

	/**
	 * Sends a test email to the current user.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function send_test( $request ): WP_REST_Response|WP_Error {
		$name = (string) self::string_param( $request, 'name' );
		if ( null === Templates::get( $name ) ) {
			return REST_Errors::not_found();
		}

		return new WP_REST_Response(
			array(
				'sent' => Email_Templates_Screen::send_test( $name ),
				'to'   => wp_get_current_user()->user_email,
			)
		);
	}

	/**
	 * Prepares a template for the response.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed           $item    Template name.
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function prepare_item_for_response( $item, $request ): WP_REST_Response|WP_Error {
		$name     = is_string( $item ) ? $item : '';
		$template = Templates::get( $name );
		$info     = Templates::info()[ $name ] ?? null;
		if ( null === $template || null === $info ) {
			return REST_Errors::not_found();
		}

		return $this->respond_with(
			array(
				'name'         => $name,
				'label'        => $info['label'],
				'audience'     => $info['audience'],
				'subject'      => $template['subject'],
				'body'         => $template['body'],
				'edited'       => Templates::is_edited( $name ),
				'placeholders' => array_intersect_key( Templates::placeholder_descriptions(), array_flip( Templates::placeholders( $name ) ) ),
				'preview'      => Mailer::content( $name, Templates::samples() ),
			),
			$request
		);
	}

	/**
	 * Builds the schema of a template.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	protected function item_schema(): array {
		return Schemas::email_template();
	}

	/**
	 * Returns the schema of the response to a test email.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	public function get_test_schema(): array {
		return Schemas::email_test();
	}
}
