<?php
/**
 * REST error responses.
 *
 * @package easyQ
 */

namespace EasyQ\Rest;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the error responses of the REST API.
 */
final class Rest_Errors {

	/**
	 * Converts a permission error reason into an error response.
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
			'read_only' => array( 503, __( 'The Q&A area is read-only at the moment.', 'easyq' ) ),
			default     => array( 403, __( 'Sorry, you are not allowed to do this.', 'easyq' ) ),
		};

		return new WP_Error( 'easyq_' . $reason, $message, array( 'status' => $status ) );
	}

	/**
	 * Returns the error for a missing or invisible resource.
	 *
	 * @return WP_Error
	 */
	public static function not_found(): WP_Error {
		return new WP_Error( 'easyq_not_found', __( 'The requested item does not exist.', 'easyq' ), array( 'status' => 404 ) );
	}

	/**
	 * Adds the HTTP status and field details to validation errors.
	 *
	 * @param WP_Error $errors Errors keyed by field.
	 * @return WP_Error
	 */
	public static function invalid( WP_Error $errors ): WP_Error {
		$fields = array();
		foreach ( $errors->get_error_codes() as $field ) {
			$fields[ (string) $field ] = $errors->get_error_message( $field );
		}

		return new WP_Error(
			'easyq_invalid',
			implode( ' ', array_values( $fields ) ),
			array(
				'status' => 400,
				'fields' => $fields,
			)
		);
	}

	/**
	 * Adds an HTTP status to an error from a service.
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
