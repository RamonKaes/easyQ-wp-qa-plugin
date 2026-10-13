<?php
/**
 * Messages after form submissions.
 *
 * @package easyQ
 */

namespace EasyQ\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Carries a message, field errors and the submitted values across the redirect after a form submission.
 *
 * The data is stored for a few minutes under a random token that is passed in
 * the address of the page the visitor is redirected to.
 */
final class Flash {

	/**
	 * Query parameter with the token.
	 *
	 * @var string
	 */
	public const QUERY_VAR = 'easyq_notice';

	/**
	 * Stores a message and returns its token.
	 *
	 * @param string                $type    `success` or `error`.
	 * @param string                $message Message.
	 * @param string                $form    Form the message belongs to.
	 * @param array<string, string> $fields  Errors keyed by field.
	 * @param array<string, mixed>  $values  Submitted values, to fill the form again.
	 * @return string
	 */
	public static function store( string $type, string $message, string $form = '', array $fields = array(), array $values = array() ): string {
		$token = wp_generate_password( 20, false );

		set_transient(
			'easyq_flash_' . $token,
			array(
				'type'    => $type,
				'message' => $message,
				'form'    => $form,
				'fields'  => $fields,
				'values'  => $values,
			),
			5 * MINUTE_IN_SECONDS
		);

		return $token;
	}

	/**
	 * Returns the message of the current request.
	 *
	 * @return array{type: string, message: string, form: string, fields: array<string, string>, values: array<string, mixed>}|null
	 */
	public static function current(): ?array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The token only reads a message stored for this visitor.
		$token = isset( $_GET[ self::QUERY_VAR ] ) && is_string( $_GET[ self::QUERY_VAR ] ) ? sanitize_key( wp_unslash( $_GET[ self::QUERY_VAR ] ) ) : '';
		if ( '' === $token ) {
			return null;
		}

		$data = get_transient( 'easyq_flash_' . $token );
		if ( ! is_array( $data ) || ! is_string( $data['type'] ?? null ) || ! is_string( $data['message'] ?? null ) ) {
			return null;
		}

		$fields = array();
		foreach ( is_array( $data['fields'] ?? null ) ? $data['fields'] : array() as $field => $error ) {
			if ( is_string( $field ) && is_string( $error ) ) {
				$fields[ $field ] = $error;
			}
		}

		$values = array();
		foreach ( is_array( $data['values'] ?? null ) ? $data['values'] : array() as $field => $value ) {
			if ( is_string( $field ) ) {
				$values[ $field ] = $value;
			}
		}

		return array(
			'type'    => $data['type'],
			'message' => $data['message'],
			'form'    => is_string( $data['form'] ?? null ) ? $data['form'] : '',
			'fields'  => $fields,
			'values'  => $values,
		);
	}

	/**
	 * Returns the submitted value of a field of a form after a failed submission.
	 *
	 * @param string $form  Form.
	 * @param string $field Field.
	 * @return string
	 */
	public static function value( string $form, string $field ): string {
		$flash = self::current();
		if ( null === $flash || $flash['form'] !== $form ) {
			return '';
		}

		$value = $flash['values'][ $field ] ?? '';

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Returns the error of a field of a form after a failed submission.
	 *
	 * @param string $form  Form.
	 * @param string $field Field.
	 * @return string
	 */
	public static function error( string $form, string $field ): string {
		$flash = self::current();

		return null !== $flash && $flash['form'] === $form ? ( $flash['fields'][ $field ] ?? '' ) : '';
	}
}
