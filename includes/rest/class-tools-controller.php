<?php
/**
 * REST endpoint for the maintenance tools.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\REST;

use EasyQ\Capabilities;
use EasyQ\Tools\Tools;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the maintenance tools in batches, for users who manage easyQ.
 *
 * Route: `POST /easyq/v1/tools/{tool}` with `step` and `after` from the previous
 * response; without them, the tool starts. Repeat until `complete` is true.
 *
 * @since 0.1.0
 */
final class Tools_Controller extends Controller {

	/**
	 * Registers the routes.
	 *
	 * @since 0.1.0
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/tools/(?P<tool>[a-z_]+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'run' ),
				'permission_callback' => array( $this, 'manage_permissions_check' ),
				'args'                => array(
					'tool'  => array(
						'description' => __( 'Tool to run.', 'easyq' ),
						'type'        => 'string',
						'enum'        => array_keys( Tools::STEPS ),
					),
					'step'  => array(
						'description' => __( 'Step from the previous response; omit to start.', 'easyq' ),
						'type'        => 'string',
						'default'     => '',
					),
					'after' => array(
						'description' => __( 'Last item handled, from the previous response.', 'easyq' ),
						'type'        => 'integer',
						'default'     => 0,
						'minimum'     => 0,
					),
				),
			)
		);
	}

	/**
	 * Checks whether the current user may run the tools.
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
	 * Runs one batch of a tool.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function run( $request ): WP_REST_Response|WP_Error {
		$result = ( new Tools() )->run(
			$this->actor(),
			(string) self::string_param( $request, 'tool' ),
			(string) self::string_param( $request, 'step' ),
			self::int_param( $request, 'after' ) ?? 0
		);

		return null === $result
			? REST_Errors::invalid( new WP_Error( 'step', __( 'The tool has no such step.', 'easyq' ) ) )
			: new WP_REST_Response( $result );
	}
}
