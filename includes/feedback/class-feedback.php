<?php
/**
 * Feedback to the site owner.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Feedback;

use EasyQ\Actor;
use EasyQ\Events\Events;
use EasyQ\Frontend\URLs;
use EasyQ\Limits\Limits;
use EasyQ\Mail\Mailer;
use EasyQ\Mail\Recipient;
use EasyQ\Services;
use EasyQ\Settings\Options;
use EasyQ\Util\IP;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Sends the feedback form to the site owner, as `pages/feedback.php` of Question2Answer.
 *
 * @since 0.1.0
 */
final class Feedback {

	/**
	 * Longest text of the message.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const MAX_LENGTH = 12000;

	/**
	 * Sets up the service.
	 *
	 * @since 0.1.0
	 *
	 * @param Mailer $mailer Sends the email.
	 */
	public function __construct( private readonly Mailer $mailer = new Mailer() ) {
	}

	/**
	 * Returns why an actor may not send feedback.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor $actor User or visitor.
	 * @return string|null Reason, see Permissions::error(), or `off`; null if allowed.
	 */
	public function error( Actor $actor ): ?string {
		if ( ! Options::get_bool( 'emails', 'feedback_enabled' ) ) {
			return 'off';
		}

		return Services::permissions()->error( null, $actor, null, Limits::FEEDBACK );
	}

	/**
	 * Returns the error for a reason why the actor may not send feedback, see error().
	 *
	 * @since 0.1.0
	 *
	 * @param string $reason Reason.
	 * @return WP_Error
	 */
	public static function refused( string $reason ): WP_Error {
		return new WP_Error(
			'easyq_' . $reason,
			'off' === $reason ? __( 'The feedback form is turned off.', 'easyq' ) : __( 'Sorry, you cannot send feedback at the moment.', 'easyq' ),
			array( 'status' => 'limit' === $reason ? 429 : ( 'off' === $reason ? 404 : 403 ) )
		);
	}

	/**
	 * Sends feedback.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor  $actor    Sender.
	 * @param string $message  Text.
	 * @param string $name     Name, optional.
	 * @param string $email    Email address to reply to, optional.
	 * @param string $previous Page the sender came from, optional.
	 * @return true|WP_Error
	 */
	public function send( Actor $actor, string $message, string $name = '', string $email = '', string $previous = '' ): bool|WP_Error {
		$reason = $this->error( $actor );
		if ( null !== $reason ) {
			return self::refused( $reason );
		}

		$fields = array();
		if ( '' === trim( $message ) ) {
			$fields['message'] = __( 'Please write your feedback.', 'easyq' );
		} elseif ( mb_strlen( $message ) > self::MAX_LENGTH ) {
			/* translators: %d: number of characters. */
			$fields['message'] = sprintf( __( 'Please shorten the message to %d characters.', 'easyq' ), self::MAX_LENGTH );
		}
		if ( '' !== $email && ! is_email( $email ) ) {
			$fields['email'] = __( 'Please enter a valid email address.', 'easyq' );
		}
		if ( array() !== $fields ) {
			return new WP_Error(
				'easyq_invalid',
				__( 'Please check the form.', 'easyq' ),
				array(
					'status' => 400,
					'fields' => $fields,
				)
			);
		}

		$user  = null === $actor->user_id ? false : get_userdata( $actor->user_id );
		$owner = Options::get_string( 'emails', 'feedback_email' );
		$admin = get_option( 'admin_email' );
		$sent  = $this->mailer->send(
			'feedback',
			array( new Recipient( '' === $owner && is_string( $admin ) ? $admin : $owner, null, null, null, '' === $email ? null : sanitize_email( $email ) ) ),
			static fn (): array => array(
				'###MESSAGE###'  => $message,
				'###NAME###'     => '' === $name ? '-' : $name,
				'###EMAIL###'    => '' === $email ? '-' : $email,
				'###PREVIOUS###' => '' === $previous ? '-' : $previous,
				'###URL###'      => false === $user ? '-' : URLs::user( $user ),
				'###IP###'       => (string) IP::current(),
			)
		);

		Events::dispatch(
			'feedback',
			array(
				'email'    => $email,
				'name'     => $name,
				'message'  => $message,
				'previous' => $previous,
			),
			$actor
		);

		return $sent > 0 ? true : new WP_Error( 'easyq_email_error', __( 'The feedback could not be sent. Please try again later.', 'easyq' ), array( 'status' => 500 ) );
	}
}
