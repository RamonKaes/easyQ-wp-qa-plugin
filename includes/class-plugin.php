<?php
/**
 * Plugin bootstrap.
 *
 * @package easyQ
 */

namespace EasyQ;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin components into WordPress.
 */
final class Plugin {

	/**
	 * Registers all components. Runs on `plugins_loaded`.
	 */
	public function register(): void {
		Database\Schema::register_meta_tables();
		add_action( 'switch_blog', array( Database\Schema::class, 'register_meta_tables' ) );

		( new Database\Installer() )->register();
		( new Capabilities() )->register();
		( new Events\Activity_Tracker() )->register();
		Services::limits()->register();
		( new Users\User_Status() )->register();
		( new Users\User_Events( Services::posts(), Services::points() ) )->register();
		( new Users\Avatars() )->register();
		( new Users\Registration() )->register();
		( new Rest\Rest_Api() )->register();

		$router = new Frontend\Router();
		Blocks\Block_Context::set_router( $router );
		$router->register();
		( new Frontend\Templates( $router ) )->register();
		( new Frontend\Page_Meta( $router ) )->register();
		( new Feeds\Feeds( $router ) )->register();
		Frontend\Sitemap_Provider::register();
		( new Frontend\Form_Handler() )->register();
		( new Blocks\Blocks() )->register();

		if ( is_admin() ) {
			( new Admin\Profile_Screen() )->register();
			( new Admin\Users_Screen() )->register();
		}
	}
}
