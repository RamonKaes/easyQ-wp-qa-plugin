<?php
/**
 * Sending emails.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Mail;

use EasyQ\Frontend\Routes;
use EasyQ\Settings\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Sends the emails of easyQ through `wp_mail()`, the way core sends comment notifications
 * in `wp_notify_postauthor()`.
 *
 * Unlike core, it sets no `Reply-To` with the address of the author, as addresses stay
 * private on a Q&A site.
 *
 * @since 0.1.0
 */
final class Mailer {

	/**
	 * Sends a template to recipients, each in their own language.
	 *
	 * @since 0.1.0
	 *
	 * @param string      $name         Template name.
	 * @param Recipient[] $recipients   Recipients.
	 * @param callable    $placeholders Returns the placeholders with their values. Called once per
	 *                                  recipient, in the language of the recipient.
	 * @phpstan-param callable(): array<string, string> $placeholders
	 * @return int Number of emails handed to `wp_mail()`.
	 */
	public function send( string $name, array $recipients, callable $placeholders ): int {
		/**
		 * Filters the recipients of an email.
		 *
		 * @since 0.1.0
		 *
		 * @param Recipient[] $recipients Recipients.
		 * @param string      $name       Template name.
		 */
		$recipients = apply_filters( 'easyq_email_recipients', $recipients, $name );

		$sent = 0;
		foreach ( is_array( $recipients ) ? $recipients : array() as $recipient ) {
			if ( ! $recipient instanceof Recipient || ! is_email( $recipient->email ) ) {
				continue;
			}

			/**
			 * Filters whether an email is sent.
			 *
			 * @since 0.1.0
			 *
			 * @param bool      $send      Whether to send the email. Default true.
			 * @param string    $name      Template name.
			 * @param Recipient $recipient Recipient.
			 */
			if ( ! apply_filters( 'easyq_send_email', true, $name, $recipient ) ) {
				continue;
			}

			$sent += $this->send_to( $name, $recipient, $placeholders ) ? 1 : 0;
		}

		return $sent;
	}

	/**
	 * Returns the subject and text of a template with its placeholders replaced.
	 *
	 * @since 0.1.0
	 *
	 * @param string                $name         Template name.
	 * @param array<string, string> $placeholders Placeholders with their values, besides those of every email.
	 * @return array{subject: string, message: string}
	 */
	public static function content( string $name, array $placeholders ): array {
		$template = Templates::get( $name ) ?? array(
			'subject' => '',
			'body'    => '',
		);

		$placeholders += array(
			'###SITENAME###' => self::site_name(),
			'###SITEURL###'  => Routes::url( 'home' ),
		);

		// One pass, so that text from users cannot bring in placeholders of its own.
		return array(
			'subject' => strtr( $template['subject'], $placeholders ),
			'message' => strtr( $template['body'], $placeholders ),
		);
	}

	/**
	 * Applies the filter of a template to an email.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $name  Template name.
	 * @param array<string, mixed> $email The email with `to`, `subject`, `message` and `headers`.
	 * @return array<string, mixed>
	 */
	public static function filter_email( string $name, array $email ): array {
		/**
		 * Filters an email before it is sent.
		 *
		 * The dynamic part of the hook name is the template name, e.g. `easyq_email_q_answered`.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string, mixed> $email {
		 *     Used to build wp_mail().
		 *
		 *     @type string $to      Email address of the recipient.
		 *     @type string $subject Subject.
		 *     @type string $message Text.
		 *     @type string $headers Headers.
		 * }
		 */
		$filtered = apply_filters( "easyq_email_{$name}", $email );

		return is_array( $filtered ) ? $filtered : $email;
	}

	/**
	 * Sends a template to one recipient.
	 *
	 * @since 0.1.0
	 *
	 * @param string    $name         Template name.
	 * @param Recipient $recipient    Recipient.
	 * @param callable  $placeholders Returns the placeholders with their values.
	 * @phpstan-param callable(): array<string, string> $placeholders
	 * @return bool Whether `wp_mail()` accepted the email.
	 */
	private function send_to( string $name, Recipient $recipient, callable $placeholders ): bool {
		$user            = null === $recipient->user_id ? get_user_by( 'email', $recipient->email ) : get_userdata( $recipient->user_id );
		$switched_locale = false === $user ? switch_to_locale( get_locale() ) : switch_to_user_locale( $user->ID );

		$content = self::content(
			$name,
			$placeholders() + array(
				'###USERNAME###' => (string) $recipient->name,
				'###EMAIL###'    => $recipient->email,
			)
		);

		$salutation = null === $recipient->name || '' === $recipient->name
			? ''
			/* translators: %s: name of the recipient. */
			: sprintf( __( '%s,', 'easyq' ), $recipient->name ) . "\n\n";

		$email = self::filter_email(
			$name,
			array(
				'to'      => $recipient->email,
				'subject' => $content['subject'],
				'message' => $salutation . $content['message'] . $this->footer( $recipient ),
				'headers' => $this->headers( $recipient ),
			)
		);

		$sent = wp_mail(
			is_string( $email['to'] ?? null ) ? $email['to'] : $recipient->email,
			wp_specialchars_decode( is_string( $email['subject'] ?? null ) ? $email['subject'] : '' ),
			is_string( $email['message'] ?? null ) ? $email['message'] : '',
			is_string( $email['headers'] ?? null ) ? $email['headers'] : ''
		);

		if ( $switched_locale ) {
			restore_previous_locale();
		}

		return $sent;
	}

	/**
	 * Returns the links to stop the emails, which are not part of the editable text, so that they cannot get lost.
	 *
	 * @since 0.1.0
	 *
	 * @param Recipient $recipient Recipient.
	 * @return string
	 */
	private function footer( Recipient $recipient ): string {
		if ( null === $recipient->post ) {
			return '';
		}

		/* translators: %s: address of the page. */
		$footer = "\n\n-- \n" . sprintf( __( 'To stop the emails about this post: %s', 'easyq' ), Unsubscribe::page_url( $recipient->post->id ) );
		if ( null !== $recipient->user_id ) {
			/* translators: %s: address of the page. */
			$footer .= "\n" . sprintf( __( 'To stop all emails about your posts, go to your account: %s', 'easyq' ), Routes::url( 'account' ) );
		}

		return $footer;
	}

	/**
	 * Returns the headers of an email.
	 *
	 * @since 0.1.0
	 *
	 * @param Recipient $recipient Recipient.
	 * @return string
	 */
	private function headers( Recipient $recipient ): string {
		$charset = get_option( 'blog_charset' );
		$headers = 'Content-Type: text/plain; charset="' . ( is_string( $charset ) ? $charset : 'UTF-8' ) . "\"\n";

		$from = Options::get_string( 'emails', 'from_email' );
		if ( '' !== $from ) {
			$headers = 'From: "' . self::site_name() . '" <' . $from . ">\n" . $headers;
		}

		if ( null !== $recipient->reply_to && is_email( $recipient->reply_to ) ) {
			$headers .= 'Reply-To: ' . $recipient->reply_to . "\n";
		}

		if ( null !== $recipient->post ) {
			$headers .= 'List-Unsubscribe: <' . Unsubscribe::one_click_url( $recipient->post->id ) . ">\n";
			$headers .= "List-Unsubscribe-Post: List-Unsubscribe=One-Click\n";
		}

		return $headers;
	}

	/**
	 * Returns the name of the site for plain text, as the option is stored escaped.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	private static function site_name(): string {
		$name = get_option( 'blogname' );

		return wp_specialchars_decode( is_string( $name ) ? $name : '', ENT_QUOTES );
	}
}
