<?php
/**
 * Site-specific user meta.
 *
 * @package easyQ
 */

namespace EasyQ\Users;

defined( 'ABSPATH' ) || exit;

/**
 * Stores user data that belongs to one site, such as Q&A levels and blocks.
 *
 * Users are shared by all sites of a network, but each site has its own Q&A
 * community. Like user roles, these values are stored under a meta key with the
 * table prefix of the site, e.g. `wp_2_easyq_level`.
 */
final class Site_Meta {

	/**
	 * Returns the meta key of a value on the current site.
	 *
	 * @param string $key Key without the site prefix, e.g. `easyq_level`.
	 * @return string
	 */
	public static function key( string $key ): string {
		return $GLOBALS['wpdb']->get_blog_prefix() . $key;
	}

	/**
	 * Returns a value.
	 *
	 * @param int    $user_id User ID.
	 * @param string $key     Key without the site prefix.
	 * @return mixed The value, or an empty string if there is none.
	 */
	public static function get( int $user_id, string $key ): mixed {
		return get_user_meta( $user_id, self::key( $key ), true );
	}

	/**
	 * Sets a value.
	 *
	 * @param int    $user_id User ID.
	 * @param string $key     Key without the site prefix.
	 * @param mixed  $value   Value.
	 */
	public static function update( int $user_id, string $key, mixed $value ): void {
		update_user_meta( $user_id, self::key( $key ), $value );
	}

	/**
	 * Removes a value.
	 *
	 * @param int    $user_id User ID.
	 * @param string $key     Key without the site prefix.
	 */
	public static function delete( int $user_id, string $key ): void {
		delete_user_meta( $user_id, self::key( $key ) );
	}
}
