<?php
/**
 * REST error responses.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\REST;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the error responses of the REST API.
 *
 * @since 0.1.0
 */
final class REST_Errors {

	/**
	 * Converts a permission error reason into an error response.
	 *
	 * @since 0.1.0
	 *
	 * @param string $reason Reason from Permissions::error().
	 * @return WP_Error
	 */
	public static function permission( string $reason ): WP_Error {
		[ $status, $message ] = match ( $reason ) {
			'login'     => array( 401, __( 'Please log in to do this.', 'easyq' ) ),
			'confirm'   => array( 403, __( 'Please confirm your email address first.', 'easyq' ) ),
			'approve'   => array( 403, __( 'Your account must be approved first.', 'easyq' ) ),
			'userblock' => array( 403, __( 'Your account is blocked.', 'easyq' ) ),
			'ipblock'   => array( 403, __( 'Your IP address is blocked.', 'easyq' ) ),
			'limit'     => array( 429, __( 'You have reached the limit for this hour. Please try again later.', 'easyq' ) ),
			'author'    => array( 403, __( 'Only the author can change the notification settings of a post.', 'easyq' ) ),
			'read_only' => array( 503, __( 'The Q&A area is read-only at the moment.', 'easyq' ) ),
			// As core: 401 for visitors, who may be allowed once logged in, 403 for users.
			default     => array( rest_authorization_required_code(), __( 'Sorry, you are not allowed to do this.', 'easyq' ) ),
		};

		// The codes of core where core has the case: `rest_not_logged_in` (as `/wp/v2/users/me`)
		// and `rest_forbidden`; the reasons only easyQ has keep its prefix.
		$code = match ( $reason ) {
			'login'                                                                     => 'rest_not_logged_in',
			'confirm', 'approve', 'userblock', 'ipblock', 'limit', 'author', 'read_only' => 'easyq_' . $reason,
			default                                                                     => 'rest_forbidden',
		};

		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}

	/**
	 * Returns the error for a missing or invisible resource, with the code and message core
	 * gives for its kind (`WP_REST_Posts_Controller::get_post()`, `WP_REST_Users_Controller::get_user()`,
	 * `WP_REST_Terms_Controller::get_term()`). Kinds core does not have keep the code of easyQ.
	 *
	 * @since 0.1.0
	 *
	 * @param string $kind `post` (questions, answers, comments), `user`, `category`, or `other`.
	 * @return WP_Error
	 */
	public static function not_found( string $kind = 'post' ): WP_Error {
		[ $code, $message ] = match ( $kind ) {
			'post'     => array( 'rest_post_invalid_id', __( 'Invalid post ID.', 'easyq' ) ),
			'user'     => array( 'rest_user_invalid_id', __( 'Invalid user ID.', 'easyq' ) ),
			'category' => array( 'rest_term_invalid', __( 'Term does not exist.', 'easyq' ) ),
			default    => array( 'easyq_not_found', __( 'The requested item does not exist.', 'easyq' ) ),
		};

		return new WP_Error( $code, $message, array( 'status' => 404 ) );
	}

	/**
	 * Returns validation errors as core returns invalid parameters (`rest_invalid_param` in
	 * `WP_REST_Request::has_valid_params()`): the names in the message, the message of each
	 * parameter in `params` and its error in `details`.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Error $errors Errors keyed by field.
	 * @return WP_Error
	 */
	public static function invalid( WP_Error $errors ): WP_Error {
		$params  = array();
		$details = array();
		foreach ( $errors->get_error_codes() as $field ) {
			$params[ (string) $field ]  = $errors->get_error_message( $field );
			$details[ (string) $field ] = array(
				'code'    => 'easyq_invalid',
				'message' => $params[ (string) $field ],
				'data'    => null,
			);
		}

		return new WP_Error(
			'rest_invalid_param',
			/* translators: %s: List of invalid parameters. */
			sprintf( __( 'Invalid parameter(s): %s', 'easyq' ), implode( ', ', array_keys( $params ) ) ),
			array(
				'status'  => 400,
				'params'  => $params,
				'details' => $details,
			)
		);
	}

	/**
	 * Adds an HTTP status to an error from a service.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Error $error  Error.
	 * @param int      $status HTTP status.
	 * @return WP_Error
	 */
	public static function with_status( WP_Error $error, int $status ): WP_Error {
		$error->add_data( array( 'status' => $status ) );

		return $error;
	}
}
