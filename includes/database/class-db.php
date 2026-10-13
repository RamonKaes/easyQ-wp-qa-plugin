<?php
/**
 * Database access.
 *
 * @package easyQ
 */

namespace EasyQ\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Typed access to the WordPress database object.
 */
final class Db {

	/**
	 * Returns the WordPress database object.
	 *
	 * @return \wpdb
	 */
	public static function wpdb(): \wpdb {
		/**
		 * The WordPress database object, set up by WordPress before any plugin loads.
		 *
		 * @var \wpdb $wpdb
		 */
		global $wpdb;

		return $wpdb;
	}
}
