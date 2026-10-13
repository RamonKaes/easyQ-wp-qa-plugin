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
	}
}
