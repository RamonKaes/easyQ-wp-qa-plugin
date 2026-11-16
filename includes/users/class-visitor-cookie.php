<?php
/**
 * Cookie of anonymous authors.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Users;

use EasyQ\Actor;
use EasyQ\Database\DB;
use EasyQ\Database\Schema;
use EasyQ\Util\Cookies;

defined( 'ABSPATH' ) || exit;

/**
 * Identifies visitors who posted without an account, so they can edit and later claim their posts.
 *
 * The cookie is set only when a visitor posts anonymously, never for visitors
 * who only read. It contains a random number and is listed in the privacy
 * policy text of the plugin.
 *
 * @since 0.1.0
 */
final class Visitor_Cookie {

	/**
	 * Cookie name.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const NAME = 'easyq_visitor';

	/**
	 * Returns the ID of the visitor cookie of the current request.
	 *
	 * @since 0.1.0
	 *
	 * @return int|null Null if the visitor has no valid cookie.
	 */
	public static function current(): ?int {
		$wpdb  = DB::wpdb();
		$value = Cookies::get( self::NAME );
		if ( ! ctype_digit( $value ) ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables without a WordPress API.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id = %d', Schema::table( 'cookies' ), $value ) );

		return null === $exists ? null : (int) $value;
	}

	/**
	 * Returns the cookie ID of the current visitor, creating or renewing the cookie when the
	 * visitor may be remembered.
	 *
	 * As core with the comment cookies: renewed with every post, and only with consent when
	 * the site asks for it (`show_comments_cookies_opt_in`). Without consent a known cookie is
	 * still used, but not renewed, and no new one is set.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor $actor   The visitor.
	 * @param bool  $consent Whether the visitor agreed to be remembered in this browser.
	 * @return int|null The cookie ID, or null without one.
	 */
	public static function ensure( Actor $actor, bool $consent = true ): ?int {
		$existing = self::current();
		$remember = Cookies::may_remember( $consent );
		if ( null !== $existing ) {
			if ( $remember ) {
				Cookies::set( self::NAME, (string) $existing, YEAR_IN_SECONDS );
			}

			return $existing;
		}

		if ( ! $remember ) {
			return null;
		}

		$id = random_int( 1, PHP_INT_MAX );
		DB::insert(
			Schema::table( 'cookies' ),
			array(
				'id'         => $id,
				'created'    => DB::now(),
				'created_ip' => $actor->stored_ip(),
			),
			array( '%d', '%s', '%s' )
		);
		Cookies::set( self::NAME, (string) $id, YEAR_IN_SECONDS );

		return $id;
	}
}
