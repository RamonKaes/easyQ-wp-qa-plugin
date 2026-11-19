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

			if ( ! self::may_send( $name, $recipient ) ) {
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
	 * The placeholders of the recipient (`###USERNAME###`, `###EMAIL###`) come from the
	 * recipient whenever one is given, so an email built outside `send()`, such as the ones
	 * that replace core's, offers the same placeholders as the template screen.
	 *
	 * @param string                $name         Template name.
	 * @param array<string, string> $placeholders Placeholders with their values, besides those of every email.
	 * @param Recipient|null        $recipient    Who gets the email; null for a preview with sample values.
	 * @return array{subject: string, message: string}
	 */
	public static function content( string $name, array $placeholders, ?Recipient $recipient = null ): array {
		// Not reachable: every caller names a template of Templates; the fallback keeps the type.
		$template = Templates::get( $name ) ?? array(
			'subject' => '',
			'body'    => '',
		);

		if ( null !== $recipient ) {
			$placeholders += array(
				'###USERNAME###' => self::recipient_name( $recipient ),
				'###EMAIL###'    => $recipient->email,
			);
		}
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
	 * Returns the name of a recipient as plain text: the given name, else the display name of
	 * the user with the address.
	 *
	 * Display names are stored escaped; plain text shows them as typed, as core decodes the site name.
	 *
	 * @since 0.1.0
	 *
	 * @param Recipient $recipient Recipient.
	 * @return string
	 */
	private static function recipient_name( Recipient $recipient ): string {
		$name = $recipient->name;
		if ( null === $recipient->name ) {
			$user = null === $recipient->user_id ? get_user_by( 'email', $recipient->email ) : get_userdata( $recipient->user_id );
			$name = false === $user ? '' : $user->display_name;
		}

		return wp_specialchars_decode( (string) $name, ENT_QUOTES );
	}

	/**
	 * Sends an email that is no template, such as the newsletter and the confirmations, with
	 * the filters every email of easyQ passes: `easyq_send_email` and `easyq_email_{name}`.
	 *
	 * @since 0.1.0
	 *
	 * @param string    $name      Name of the email, the dynamic part of `easyq_email_{name}`.
	 * @param Recipient $recipient Recipient.
	 * @param string    $subject   Subject.
	 * @param string    $message   Text.
	 * @param string    $headers   Headers.
	 * @return bool Whether `wp_mail()` accepted the email.
	 */
	public static function send_email( string $name, Recipient $recipient, string $subject, string $message, string $headers = '' ): bool {
		if ( ! self::may_send( $name, $recipient ) ) {
			return false;
		}

		return self::deliver(
			$name,
			$recipient,
			array(
				'to'      => $recipient->email,
				'subject' => $subject,
				'message' => $message,
				'headers' => $headers,
			)
		);
	}

	/**
	 * Asks the filter whether an email is sent.
	 *
	 * @since 0.1.0
	 *
	 * @param string    $name      Name of the email.
	 * @param Recipient $recipient Recipient.
	 * @return bool
	 */
	private static function may_send( string $name, Recipient $recipient ): bool {
		/**
		 * Filters whether an email is sent.
		 *
		 * @since 0.1.0
		 *
		 * @param bool      $send      Whether to send the email. Default true.
		 * @param string    $name      Template name, or the name of an email without template:
		 *                             `newsletter`, `newsletter_confirm` or `notify_confirm`.
		 * @param Recipient $recipient Recipient.
		 */
		$send = apply_filters( 'easyq_send_email', true, $name, $recipient );

		// Any value core would take as true in `if ( ! apply_filters( … ) )`.
		return ! empty( $send );
	}

	/**
	 * Filters an email and hands it to `wp_mail()`, the only place easyQ calls it.
	 *
	 * @since 0.1.0
	 *
	 * @param string       $name      Name of the email.
	 * @param Recipient    $recipient Recipient.
	 * @param array<mixed> $email     The email with `to`, `subject`, `message` and `headers`.
	 * @return bool Whether `wp_mail()` accepted the email.
	 */
	private static function deliver( string $name, Recipient $recipient, array $email ): bool {
		$email = self::filter_email( $name, $email );

		return wp_mail(
			is_string( $email['to'] ?? null ) ? $email['to'] : $recipient->email,
			wp_specialchars_decode( is_string( $email['subject'] ?? null ) ? $email['subject'] : '' ),
			is_string( $email['message'] ?? null ) ? $email['message'] : '',
			// wp_mail() takes the headers as text or as an array of lines; a filter may return either.
			is_array( $email['headers'] ?? null ) ? array_values( array_filter( $email['headers'], 'is_string' ) ) : ( is_string( $email['headers'] ?? null ) ? $email['headers'] : '' )
		);
	}

	/**
	 * Applies the filter of a template to an email.
	 *
	 * @since 0.1.0
	 *
	 * @param string       $name  Template name.
	 * @param array<mixed> $email The email with `to`, `subject`, `message` and `headers`.
	 * @return array<mixed>
	 */
	public static function filter_email( string $name, array $email ): array {
		/**
		 * Filters an email before it is sent.
		 *
		 * The dynamic part of the hook name is the template name, e.g. `easyq_email_q_answered`.
		 *
		 * @since 0.1.0
		 *
		 * @param array<mixed> $email {
		 *     Used to build wp_mail().
		 *
		 *     @type string $to      Email address of the recipient.
		 *     @type string $subject Subject.
		 *     @type string $message Text.
		 *     @type string|string[] $headers Headers, as text or as an array of lines, as wp_mail() takes them.
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

		// The salutation names only a recipient whose name was given, as before.
		$recipient_name = wp_specialchars_decode( (string) $recipient->name, ENT_QUOTES );
		$content        = self::content( $name, $placeholders(), $recipient );

		$salutation = '' === $recipient_name
			? ''
			/* translators: %s: name of the recipient. */
			: sprintf( __( '%s,', 'easyq' ), $recipient_name ) . "\n\n";

		$sent = self::deliver(
			$name,
			$recipient,
			array(
				'to'      => $recipient->email,
				'subject' => $content['subject'],
				'message' => $salutation . $content['message'] . $this->footer( $recipient ),
				'headers' => $this->headers( $recipient ),
			)
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
			// Emails about messages and the wall: the account turns them off.
			return null === $recipient->user_id
				? ''
				/* translators: %s: address of the account page. */
				: "\n\n-- \n" . sprintf( __( 'To stop these emails, change the settings of your account: %s', 'easyq' ), Routes::url( 'account' ) );
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
		} elseif ( null !== $recipient->user_id ) {
			// RFC 2369: the page where the user stops them.
			$headers .= 'List-Unsubscribe: <' . Routes::url( 'account' ) . ">\n";
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
