<?php
/**
 * Limits of registrations and logins.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Limits;

use EasyQ\Actor;
use EasyQ\Services;
use EasyQ\Util\IP;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Applies `max_rate_ip_registers`, `max_rate_ip_logins` and the blocked IP addresses to
 * the registration and login of WordPress.
 *
 * Question2Answer checks these on its own register and login pages (`pages/register.php`,
 * `pages/login.php`); easyQ uses the core hooks of `register_new_user()` and `wp_authenticate()`.
 *
 * @since 0.1.0
 */
final class Account_Limits {

	/**
	 * Error code of a login refused by the limit.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const LOGIN_LIMIT_ERROR = 'easyq_login_limit';

	/**
	 * Hooks the limits into WordPress.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_filter( 'registration_errors', array( $this, 'check_registration' ) );
		add_action( 'register_new_user', array( $this, 'count_registration' ) );
		// After the core authentication filters (20 and 30), so their result cannot replace the refusal.
		add_filter( 'authenticate', array( $this, 'check_login' ), 99, 3 );
		add_action( 'wp_login_failed', array( $this, 'count_failed_login' ), 10, 2 );
	}

	/**
	 * Refuses a registration from a blocked IP address or beyond the hourly limit, as
	 * `pages/register.php` of Question2Answer does.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Error $errors Errors of the registration.
	 * @return WP_Error
	 */
	public function check_registration( WP_Error $errors ): WP_Error {
		$actor = self::actor();

		if ( Services::limits()->is_ip_blocked( $actor ) ) {
			$errors->add( 'easyq_ip_blocked', __( '<strong>Error:</strong> Registration is not possible from your IP address.', 'easyq' ) );
		} elseif ( Services::limits()->remaining( Limits::REGISTRATIONS, $actor ) <= 0 ) {
			$errors->add( 'easyq_register_limit', __( '<strong>Error:</strong> Too many registrations. Please try again in an hour.', 'easyq' ) );
		}

		return $errors;
	}

	/**
	 * Counts a completed registration.
	 *
	 * @since 0.1.0
	 */
	public function count_registration(): void {
		Services::limits()->increment( Limits::REGISTRATIONS, self::actor() );
	}

	/**
	 * Refuses a login beyond the hourly limit, even with the right password.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $user     Result of the earlier filters: a user, an error or null.
	 * @param mixed $username Username or email address.
	 * @param mixed $password Password.
	 * @return mixed
	 */
	public function check_login( mixed $user, mixed $username, mixed $password ): mixed {
		if ( ( '' === $username || null === $username ) && ( '' === $password || null === $password ) ) {
			return $user;
		}

		if ( Services::limits()->remaining( Limits::LOGINS, self::actor() ) <= 0 ) {
			return new WP_Error( self::LOGIN_LIMIT_ERROR, __( '<strong>Error:</strong> Too many failed login attempts. Please try again in an hour.', 'easyq' ) );
		}

		return $user;
	}

	/**
	 * Counts a failed login.
	 *
	 * Unlike Question2Answer, which counts every attempt on its login page, only failed
	 * attempts count: apps and offices behind one address log in often with the right password.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $username Username or email address.
	 * @param mixed $error    Why the login failed.
	 */
	public function count_failed_login( mixed $username, mixed $error = null ): void {
		if ( $error instanceof WP_Error && self::LOGIN_LIMIT_ERROR === $error->get_error_code() ) {
			return;
		}

		Services::limits()->increment( Limits::LOGINS, self::actor() );
	}

	/**
	 * Returns the visitor of the request, known only by the IP address.
	 *
	 * @since 0.1.0
	 *
	 * @return Actor
	 */
	private static function actor(): Actor {
		return new Actor( null, null, IP::current() );
	}
}
