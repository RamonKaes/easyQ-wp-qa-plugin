<?php
/**
 * REST API registration.
 *
 * @package easyQ
 */

namespace EasyQ\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the controllers of the `easyq/v1` namespace.
 */
final class Rest_Api {

	/**
	 * Hooks the registration into WordPress.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers all routes.
	 */
	public function register_routes(): void {
		$controllers = array(
			new Questions_Controller(),
			new Answers_Controller(),
			new Comments_Controller(),
			new Actions_Controller(),
			new Taxonomy_Controller(),
			new Users_Controller(),
		);

		foreach ( $controllers as $controller ) {
			$controller->register_routes();
		}
	}
}
