<?php
/**
 * Cookies of the plugin.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Sets the cookies of the plugin as core sets the comment cookies (`wp_set_comment_cookies()`).
 *
 * @since 0.1.0
 */
final class Cookies {

	/**
	 * Returns the name of a cookie for the current site.
	 *
	 * The cookies point to rows of the tables of one site, and on a network every site shares
	 * the path; each site has a name of its own, so a visit to another site keeps the cookie.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name Name of the cookie.
	 * @return string
	 */
	public static function name( string $name ): string {
		return is_multisite() ? $name . '_' . get_current_blog_id() : $name;
	}

	/**
	 * Returns the value of a cookie of the current site.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name Name of the cookie, see name().
	 * @return string
	 */
	public static function get( string $name ): string {
		$name = self::name( $name );

		return isset( $_COOKIE[ $name ] ) && is_string( $_COOKIE[ $name ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) ) : '';
	}

	/**
	 * Sets a cookie of the current site: with the path and domain of core, `secure` from the
	 * scheme of the home address, and readable by the server only.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name     Name of the cookie, see name().
	 * @param string $value    Value.
	 * @param int    $lifetime Seconds until it expires.
	 */
	public static function set( string $name, string $value, int $lifetime ): void {
		$name = self::name( $name );
		if ( ! headers_sent() ) {
			setcookie(
				$name,
				$value,
				array(
					'expires'  => time() + $lifetime,
					'path'     => (string) COOKIEPATH,
					'domain'   => (string) COOKIE_DOMAIN,
					'secure'   => 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME ),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		}

		$_COOKIE[ $name ] = $value;
	}

	/**
	 * Tells whether the visitor agreed to be remembered in this browser, as core asks before it
	 * sets the comment cookies: without the setting `show_comments_cookies_opt_in` the cookie
	 * needs no consent.
	 *
	 * @since 0.1.0
	 *
	 * @param bool $consent Whether the visitor ticked the box.
	 * @return bool
	 */
	public static function may_remember( bool $consent ): bool {
		return $consent || ! get_option( 'show_comments_cookies_opt_in' );
	}
}
