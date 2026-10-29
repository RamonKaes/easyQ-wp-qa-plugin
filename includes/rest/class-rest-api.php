<?php
/**
 * REST API registration.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\REST;

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
		);

		foreach ( $controllers as $controller ) {
			$controller->register_routes();
		}
	}
}
