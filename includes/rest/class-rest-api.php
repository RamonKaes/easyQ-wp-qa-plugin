<?php
/**
 * REST API registration.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\REST;

use EasyQ\Services;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the controllers of the `easyq/v1` namespace.
 *
 * @since 0.1.0
 */
final class REST_API {

	/**
	 * Hooks the registration into WordPress.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'rest_request_before_callbacks', array( $this, 'refuse_while_read_only' ), 10, 3 );
	}

	/**
	 * Refuses every request of the easyQ API that changes something while the Q&A area is
	 * read-only, before the permission callback of the route runs.
	 *
	 * The forms of the Q&A pages send their requests through the same server, so they are
	 * refused here too.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed           $response Response so far, or an error.
	 * @param mixed           $handler  Handler of the route.
	 * @param WP_REST_Request $request  Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return mixed
	 */
	public function refuse_while_read_only( mixed $response, mixed $handler, WP_REST_Request $request ): mixed {
		if ( is_wp_error( $response ) || in_array( $request->get_method(), array( 'GET', 'HEAD', 'OPTIONS' ), true ) || ! str_starts_with( $request->get_route(), '/' . Controller::NAMESPACE . '/' ) ) {
			return $response;
		}

		$user_id = get_current_user_id();

		return Services::permissions()->is_read_only_for( 0 === $user_id ? null : $user_id ) ? REST_Errors::permission( 'read_only' ) : $response;
	}

	/**
	 * Registers all routes.
	 *
	 * @since 0.1.0
	 */
	public function register_routes(): void {
		$controllers = array(
			new Questions_Controller(),
			new Answers_Controller(),
			new Comments_Controller(),
			new Posts_Controller(),
			new IPs_Controller(),
			new User_Fields_Controller(),
			new Tools_Controller(),
			new Categories_Controller(),
			new Tags_Controller(),
			new Users_Controller(),
			new Unsubscribe_Controller(),
			new Favorites_Controller(),
			new Notices_Controller(),
			new Messages_Controller(),
			new Feedback_Controller(),
			new Newsletter_Controller(),
			new Email_Templates_Controller(),
		);

		foreach ( $controllers as $controller ) {
			$controller->register_routes();
		}
	}
}
