<?php
/**
 * Blocked usernames and display names.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Users;

use EasyQ\Settings\Options;
use EasyQ\Util\Text;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Applies `block_bad_usernames` of Question2Answer to the names that WordPress shows.
 *
 * Question2Answer checks the handle in `filter_handle()`, and the handle is the name it
 * shows. WordPress shows the display name, which users choose themselves, so the words are
 * checked in the username on registration (also the network sign-up of multisite) and in
 * the display name and nickname when users change them on their profile screen or through
 * the REST API. Users who may edit other users choose any name, as administrators create
 * users without the check too.
 *
 * @since 0.1.0
 */
final class Blocked_Names {

	/**
	 * Name fields of the profile, with the parameter of the REST API that sets them.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string>
	 */
	private const NAME_FIELDS = array(
		'display_name' => 'name',
		'nickname'     => 'nickname',
	);

	/**
	 * Hooks the checks into WordPress.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_filter( 'registration_errors', array( $this, 'check_registration' ), 10, 2 );
		add_filter( 'wpmu_validate_user_signup', array( $this, 'check_signup' ) );
		add_action( 'user_profile_update_errors', array( $this, 'check_profile' ), 10, 3 );
		add_filter( 'rest_request_before_callbacks', array( $this, 'check_rest' ), 10, 3 );
	}

	/**
	 * Whether a name contains a blocked word, as `filter_handle()` of Question2Answer checks it.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name Username or display name.
	 * @return bool
	 */
	public static function is_blocked( string $name ): bool {
		$regex = Text::block_words_to_regex( Options::get_string( 'users', 'block_bad_usernames' ) );

		return array() !== Text::block_words_match_all( $name, $regex );
	}

	/**
	 * Refuses blocked usernames on the registration form.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Error $errors Errors of the registration.
	 * @param mixed    $login  Sanitized username.
	 * @return WP_Error
	 */
	public function check_registration( WP_Error $errors, mixed $login ): WP_Error {
		if ( is_string( $login ) && self::is_blocked( $login ) ) {
			$errors->add( 'easyq_blocked_username', __( '<strong>Error:</strong> Sorry, that username is not allowed. Please choose another one.', 'easyq' ) );
		}

		return $errors;
	}

	/**
	 * Refuses blocked usernames on the network sign-up of multisite.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $result Result of `wpmu_validate_user_signup()`.
	 * @return mixed
	 */
	public function check_signup( mixed $result ): mixed {
		if ( ! is_array( $result ) || ! ( $result['errors'] ?? null ) instanceof WP_Error || ! is_string( $result['user_name'] ?? null ) || current_user_can( 'create_users' ) ) {
			return $result;
		}

		if ( self::is_blocked( $result['user_name'] ) ) {
			// Plain text, as the other messages of the sign-up form.
			$result['errors']->add( 'user_name', __( 'Sorry, that username is not allowed. Please choose another one.', 'easyq' ) );
		}

		return $result;
	}

	/**
	 * Refuses a blocked display name or nickname on the profile screen.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $errors Errors of the profile.
	 * @param mixed $update Whether an existing user is updated.
	 * @param mixed $user   Submitted user data.
	 */
	public function check_profile( mixed $errors, mixed $update, mixed $user ): void {
		if ( ! $errors instanceof WP_Error || ! is_object( $user ) || current_user_can( 'edit_users' ) ) {
			return;
		}

		$user_id = $update && isset( $user->ID ) && is_numeric( $user->ID ) ? (int) $user->ID : 0;
		foreach ( array_keys( self::NAME_FIELDS ) as $field ) {
			$name = $user->$field ?? null;
			if ( is_string( $name ) && self::is_new_blocked_name( $user_id, $field, $name ) ) {
				$errors->add( $field, __( '<strong>Error:</strong> Sorry, that name is not allowed. Please choose another one.', 'easyq' ), array( 'form-field' => $field ) );
			}
		}
	}

	/**
	 * Refuses a blocked display name or nickname sent to the users endpoint of the REST API.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed           $response Response so far; null unless an earlier filter answered.
	 * @param mixed           $handler  Route handler.
	 * @param WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public function check_rest( mixed $response, mixed $handler, WP_REST_Request $request ): mixed {
		if ( null !== $response || in_array( $request->get_method(), array( 'GET', 'HEAD', 'DELETE' ), true ) || current_user_can( 'edit_users' ) ) {
			return $response;
		}

		if ( 1 !== preg_match( '#^/wp/v2/users(?:/(\d+|me))?$#', $request->get_route(), $match ) ) {
			return $response;
		}

		$user_id = match ( $match[1] ?? '' ) {
			''      => 0,
			'me'    => get_current_user_id(),
			default => (int) $match[1],
		};

		foreach ( self::NAME_FIELDS as $field => $param ) {
			$name = $request->get_param( $param );
			if ( is_string( $name ) && self::is_new_blocked_name( $user_id, $field, $name ) ) {
				$message = __( 'Sorry, that name is not allowed. Please choose another one.', 'easyq' );

				return new WP_Error(
					'rest_invalid_param',
					/* translators: %s: List of invalid parameters. */
					sprintf( __( 'Invalid parameter(s): %s', 'easyq' ), $param ),
					array(
						'status'  => 400,
						'params'  => array( $param => $message ),
						'details' => array(
							$param => array(
								'code'    => 'easyq_blocked_name',
								'message' => $message,
							),
						),
					)
				);
			}
		}

		return $response;
	}

	/**
	 * Whether a name is blocked and differs from the one the user has, so that a user with
	 * an older name can still save the profile.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $user_id User ID, or 0 for a new user.
	 * @param string $field   `display_name` or `nickname`.
	 * @param string $name    Submitted name.
	 * @return bool
	 */
	private static function is_new_blocked_name( int $user_id, string $field, string $name ): bool {
		$current = $user_id > 0 ? get_userdata( $user_id ) : false;
		if ( false !== $current && $current->$field === $name ) {
			return false;
		}

		return self::is_blocked( $name );
	}
}
