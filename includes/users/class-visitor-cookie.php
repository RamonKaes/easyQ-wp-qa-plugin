<?php
/**
 * Cookie of anonymous authors.
 *
 * @package easyQ
 */

namespace EasyQ\Users;

use EasyQ\Actor;
use EasyQ\Database\Db;
use EasyQ\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Identifies visitors who posted without an account, so they can edit and later claim their posts.
 *
 * The cookie is set only when a visitor posts anonymously, never for visitors
 * who only read. It contains a random number and is listed in the privacy
 * policy text of the plugin.
 */
final class Visitor_Cookie {

	/**
	 * Cookie name.
	 *
	 * @var string
	 */
	public const NAME = 'easyq_visitor';

	/**
	 * Returns the ID of the visitor cookie of the current request.
	 *
	 * @return int|null Null if the visitor has no valid cookie.
	 */
	public static function current(): ?int {
		$value = isset( $_COOKIE[ self::NAME ] ) && is_string( $_COOKIE[ self::NAME ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::NAME ] ) ) : '';
		if ( ! ctype_digit( $value ) ) {
			return null;
		}

		$exists = Db::get_var( Db::wpdb()->prepare( 'SELECT id FROM %i WHERE id = %d', Schema::table( 'cookies' ), $value ) );

		return null === $exists ? null : (int) $value;
	}

	/**
	 * Returns the cookie ID of the current visitor, creating the cookie if needed.
	 *
	 * @param Actor $actor The visitor.
	 * @return int
	 */
	public static function ensure( Actor $actor ): int {
		$existing = self::current();
		if ( null !== $existing ) {
			return $existing;
		}

		$id = random_int( 1, PHP_INT_MAX );
		Db::insert(
			Schema::table( 'cookies' ),
			array(
				'id'         => $id,
				'created'    => Db::now(),
				'created_ip' => $actor->stored_ip(),
			),
			array( '%d', '%s', '%s' )
		);

		if ( ! headers_sent() ) {
			setcookie(
				self::NAME,
				(string) $id,
				array(
					'expires'  => time() + YEAR_IN_SECONDS,
					'path'     => (string) COOKIEPATH,
					'domain'   => (string) COOKIE_DOMAIN,
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		}

		$_COOKIE[ self::NAME ] = (string) $id;

		return $id;
	}
}
