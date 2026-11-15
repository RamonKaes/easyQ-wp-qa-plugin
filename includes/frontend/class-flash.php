<?php
/**
 * Messages after form submissions.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Holds the message, the field errors and the submitted values of a form submission.
 *
 * A failed submission is answered with the page itself, as `wp-login.php` answers a failed
 * login: the message and what the user typed live in this request only, so a refused
 * request stores nothing. A successful one redirects, and its message is stored for a few
 * minutes under a random token in the address of the next page.
 *
 * @since 0.1.0
 */
final class Flash {

	/**
	 * Query parameter with the token.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const QUERY_VAR = 'easyq_notice';

	/**
	 * Length of a token: letters and digits from `wp_generate_password()`.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const TOKEN_LENGTH = 20;

	/**
	 * Message of a failed submission in this request.
	 *
	 * @since 0.1.0
	 *
	 * @var array{type: string, message: string, form: string, target: int, fields: array<string, string>, values: array<string, mixed>}|null
	 */
	private static ?array $now = null;

	/**
	 * Stores the message of a successful submission for the next page and returns its token.
	 *
	 * @since 0.1.0
	 *
	 * @param string $type    `success` or `error`.
	 * @param string $message Message.
	 * @param string $form    Form the message belongs to.
	 * @return string
	 */
	public static function store( string $type, string $message, string $form = '' ): string {
		$token = wp_generate_password( self::TOKEN_LENGTH, false );

		set_transient(
			'easyq_flash_' . $token,
			array(
				'type'    => $type,
				'message' => $message,
				'form'    => $form,
			),
			5 * MINUTE_IN_SECONDS
		);

		return $token;
	}

	/**
	 * Keeps the message of a failed submission for the page this request shows.
	 *
	 * @since 0.1.0
	 *
	 * @param string                $message Message.
	 * @param string                $form    Form the message belongs to.
	 * @param int                   $target  Post the form was for, 0 for none.
	 * @param array<string, string> $fields  Errors keyed by field.
	 * @param array<string, mixed>  $values  Submitted values, to fill the form again.
	 */
	public static function keep( string $message, string $form, int $target, array $fields = array(), array $values = array() ): void {
		self::$now = array(
			'type'    => 'error',
			'message' => $message,
			'form'    => $form,
			'target'  => $target,
			'fields'  => $fields,
			'values'  => $values,
		);
	}

	/**
	 * Returns the message of the current request.
	 *
	 * @since 0.1.0
	 *
	 * @return array{type: string, message: string, form: string, target: int, fields: array<string, string>, values: array<string, mixed>}|null
	 */
	public static function current(): ?array {
		if ( null !== self::$now ) {
			return self::$now;
		}

		// The token is read as it was made: `sanitize_key()` would lower-case it, and only a store
		// that compares without case, such as the options table, would find it again.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The token only reads a message stored for this visitor, and is checked against the form of a token below.
		$token = isset( $_GET[ self::QUERY_VAR ] ) && is_string( $_GET[ self::QUERY_VAR ] ) ? wp_unslash( $_GET[ self::QUERY_VAR ] ) : '';
		if ( 1 !== preg_match( '/^[A-Za-z0-9]{' . self::TOKEN_LENGTH . '}$/D', $token ) ) {
			return null;
		}

		$data = get_transient( 'easyq_flash_' . $token );
		if ( ! is_array( $data ) || ! is_string( $data['type'] ?? null ) || ! is_string( $data['message'] ?? null ) ) {
			return null;
		}

		return array(
			'type'    => $data['type'],
			'message' => $data['message'],
			'form'    => is_string( $data['form'] ?? null ) ? $data['form'] : '',
			'target'  => 0,
			'fields'  => array(),
			'values'  => array(),
		);
	}

	/**
	 * Returns the message of the current request if it belongs to a form.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $form   Form.
	 * @param int|null $target Post the form is for, or null for a form that a page shows once.
	 * @return array{type: string, message: string, form: string, target: int, fields: array<string, string>, values: array<string, mixed>}|null
	 */
	public static function of( string $form, ?int $target = null ): ?array {
		$flash = self::current();

		return null !== $flash && $flash['form'] === $form && ( null === $target || $flash['target'] === $target ) ? $flash : null;
	}

	/**
	 * Returns what the user typed into a field of a failed submission, or null if the form
	 * was not sent in this request. A field the user emptied gives an empty string.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $form   Form.
	 * @param string   $field  Field.
	 * @param int|null $target Post the form is for, or null for a form that a page shows once.
	 * @return string|null
	 */
	public static function kept( string $form, string $field, ?int $target = null ): ?string {
		$flash = self::of( $form, $target );
		if ( null === $flash ) {
			return null;
		}

		$value = $flash['values'][ $field ] ?? '';

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Returns the submitted value of a field of a form after a failed submission.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $form   Form.
	 * @param string   $field  Field.
	 * @param int|null $target Post the form is for, or null for a form that a page shows once.
	 * @return string
	 */
	public static function value( string $form, string $field, ?int $target = null ): string {
		return self::kept( $form, $field, $target ) ?? '';
	}

	/**
	 * Returns the error of a field of a form after a failed submission.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $form   Form.
	 * @param string   $field  Field.
	 * @param int|null $target Post the form is for, or null for a form that a page shows once.
	 * @return string
	 */
	public static function error( string $form, string $field, ?int $target = null ): string {
		return self::of( $form, $target )['fields'][ $field ] ?? '';
	}
}
