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
 * request stores nothing. A successful one redirects with the name of the form in the
 * address of the next page, as core redirects with `updated=true` or `message=1`: the
 * message is one of a fixed set, so nothing is stored for it either.
 *
 * @since 0.1.0
 */
final class Flash {

	/**
	 * Query parameter with the form, and `-queued` for a post that waits for moderation.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const QUERY_VAR = 'easyq_notice';

	/**
	 * Message of a failed submission in this request.
	 *
	 * @since 0.1.0
	 *
	 * @var array{type: string, message: string, form: string, target: int, fields: array<string, string>, values: array<string, mixed>}|null
	 */
	private static ?array $now = null;

	/**
	 * Returns the value of the query parameter for a successful submission of a form.
	 *
	 * @since 0.1.0
	 *
	 * @param string $form   Form.
	 * @param bool   $queued Whether the post waits for moderation.
	 * @return string
	 */
	public static function code( string $form, bool $queued ): string {
		return $form . ( $queued ? '-queued' : '' );
	}

	/**
	 * Returns the message of a successful submission of a form.
	 *
	 * @since 0.1.0
	 *
	 * @param string $form   Form.
	 * @param bool   $queued Whether the post waits for moderation.
	 * @return string|null Null for a name that is no form.
	 */
	public static function success_message( string $form, bool $queued ): ?string {
		$message = match ( $form ) {
			'ask'                           => __( 'Your question has been published.', 'easyq' ),
			'answer'                        => __( 'Your answer has been published.', 'easyq' ),
			'comment'                       => __( 'Your comment has been published.', 'easyq' ),
			'edit'                          => __( 'Your changes have been saved.', 'easyq' ),
			'account'                       => __( 'Your profile has been saved.', 'easyq' ),
			'unsubscribe'                   => __( 'You will no longer get emails about this post.', 'easyq' ),
			'newsletter_confirm'            => __( 'Thank you! You will get the newsletter.', 'easyq' ),
			'favorite'                      => __( 'Your favorites have been saved.', 'easyq' ),
			'notice'                        => __( 'The notice was dismissed.', 'easyq' ),
			'wall'                          => __( 'Your post is on the wall.', 'easyq' ),
			'wall_delete', 'message_delete' => __( 'Deleted.', 'easyq' ),
			'message'                       => __( 'Your message has been sent.', 'easyq' ),
			'feedback'                      => __( 'Thank you for your feedback.', 'easyq' ),
			'vote', 'action'                => __( 'Done.', 'easyq' ),
			default                         => null,
		};

		return null !== $message && $queued ? __( 'Thank you! Your post will be published once a moderator approved it.', 'easyq' ) : $message;
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

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only picks one of the fixed messages; nothing is changed.
		$code = isset( $_GET[ self::QUERY_VAR ] ) && is_string( $_GET[ self::QUERY_VAR ] ) ? sanitize_key( wp_unslash( $_GET[ self::QUERY_VAR ] ) ) : '';
		$form = str_ends_with( $code, '-queued' ) ? substr( $code, 0, -7 ) : $code;
		$text = '' === $form ? null : self::success_message( $form, $form !== $code );
		if ( null === $text ) {
			return null;
		}

		return array(
			'type'    => 'success',
			'message' => $text,
			'form'    => $form,
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
