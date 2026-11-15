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

		return new WP_Error( 'easyq_' . $reason, $message, array( 'status' => $status ) );
	}

	/**
	 * Returns the error for a missing or invisible resource.
	 *
	 * @since 0.1.0
	 *
	 * @return WP_Error
	 */
	public static function not_found(): WP_Error {
		return new WP_Error( 'easyq_not_found', __( 'The requested item does not exist.', 'easyq' ), array( 'status' => 404 ) );
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
			/* translators: %s: list of invalid parameters. */
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
