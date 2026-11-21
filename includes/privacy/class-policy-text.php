<?php
/**
 * Suggested text for the privacy policy.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Privacy;

use EasyQ\Settings\Options;
use EasyQ\Users\Permits;
use EasyQ\Users\Registration;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the data handling of the plugin to the privacy policy guide of WordPress.
 *
 * Follows `WP_Privacy_Policy_Content::get_default_content()`: one heading per topic,
 * the text to copy marked as suggested. The text describes the current settings, so
 * WordPress asks the site owner to review the policy when they change.
 *
 * @since 0.1.0
 */
final class Policy_Text {

	/**
	 * Hooks the text into WordPress.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_action( 'admin_init', array( self::class, 'add' ) );
	}

	/**
	 * Adds the text to the privacy policy guide.
	 *
	 * @since 0.1.0
	 */
	public static function add(): void {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content( __( 'easyQ Questions and Answers', 'easyq' ), self::content() );
		}
	}

	/**
	 * Returns the suggested text for the current settings.
	 *
	 * @since 0.1.0
	 *
	 * @return string HTML.
	 */
	public static function content(): string {
		$suggested = '<strong class="privacy-policy-tutorial">' . __( 'Suggested text:', 'easyq' ) . ' </strong>';
		$days      = IP_Retention::days();
		$anonymous = self::anonymous_posting();
		$strings   = array();

		$strings[] = '<h2 class="wp-block-heading">' . __( 'Questions and answers', 'easyq' ) . '</h2>';
		$strings[] = '<p class="privacy-policy-tutorial">' . __( 'This section covers the question and answer pages of the site. Review it after changing the settings of easyQ, as it describes them.', 'easyq' ) . '</p>';
		$strings[] = '<p>' . $suggested . __( 'When you ask a question, write an answer or a comment, vote, or flag a post, we store it with your user account. Questions, answers and comments are public and show your user name and profile picture. Votes and flags are stored so that each counts only once; who cast them is visible only to the moderators of the site.', 'easyq' ) . '</p>';
		$strings[] = '<p>' . ( Options::get_bool( 'spam', 'ip_anonymize' )
			? __( 'To prevent abuse, we also store the IP address from which a post was written or last changed, shortened by its last part.', 'easyq' )
			: __( 'To prevent abuse, we also store the IP address from which a post was written or last changed.', 'easyq' ) ) . '</p>';

		if ( $anonymous ) {
			$strings[] = '<p>' . __( 'If you post without an account, we store the name you enter, if any, and your email address only if you ask to be notified of answers or comments.', 'easyq' ) . '</p>';
		}

		$profile = __( 'If you have an account, your profile on the question and answer pages shows your user name, your points, your activity and the profile fields you fill in.', 'easyq' );
		if ( Options::get_bool( 'users', 'avatar_allow_upload' ) ) {
			$profile .= ' ' . __( 'A profile picture you upload is stored in the media library of the site.', 'easyq' );
		}
		$strings[] = '<p>' . $profile . '</p>';

		// The pictures come from Gravatar while the site shows avatars, as core says it for comments.
		if ( get_option( 'show_avatars' ) ) {
			$strings[] = '<p>' . __( 'Unless a profile picture was uploaded, the question and answer pages show the picture of the Gravatar service: an anonymized string created from the email address (also called a hash) is part of the address of the picture, and your browser loads it from Gravatar, which receives your IP address. The Gravatar service privacy policy is available here: https://automattic.com/privacy/.', 'easyq' ) . '</p>';
		}

		if ( Registration::asks_for_terms() ) {
			$strings[] = '<p>' . __( 'When you register, we store the time at which you accepted our terms and the text you accepted, as proof of your consent. This record stays with your user account as long as it exists.', 'easyq' ) . '</p>';
		}

		if ( Options::get_bool( 'users', 'allow_private_messages' ) ) {
			$strings[] = '<p>' . ( Options::get_bool( 'users', 'show_message_history' )
				? __( 'Private messages are sent to the recipient by email and stored until the sender and the recipient have deleted them; only they can see them. You can choose in your account not to receive private messages.', 'easyq' )
				: __( 'Private messages are sent to the recipient by email and not stored on the site. You can choose in your account not to receive private messages.', 'easyq' ) ) . '</p>';
		}
		if ( Options::get_bool( 'users', 'allow_user_walls' ) ) {
			$strings[] = '<p>' . __( 'Posts on the wall of a profile are public.', 'easyq' ) . '</p>';
		}

		$strings[] = '<p>' . __( 'We send you emails about answers and comments on your posts only if you ask for them; each of these emails contains a link to turn them off.', 'easyq' ) . '</p>';

		if ( Options::get_bool( 'newsletter', 'mailing_enabled' ) ) {
			$strings[] = '<p>' . __( 'If you subscribe to our newsletter, you confirm the subscription with a link we send to your email address, and we store the time of your consent. You can unsubscribe at any time with the link in each newsletter.', 'easyq' ) . '</p>';
		}
		if ( Options::get_bool( 'emails', 'feedback_enabled' ) ) {
			$strings[] = '<p>' . __( 'If you send us a message with the feedback form, we receive it by email together with your name, your email address and your IP address. It is not stored on the site.', 'easyq' ) . '</p>';
		}

		$strings[] = '<h3 class="wp-block-heading">' . __( 'Cookies on the question and answer pages', 'easyq' ) . '</h3>';
		if ( $anonymous ) {
			$strings[] = '<p>' . $suggested . ( get_option( 'show_comments_cookies_opt_in' )
				? __( 'If you post without an account and agree to it, we set a cookie that lets you edit your posts later. It contains a random number and lasts for one year from your last post.', 'easyq' )
				: __( 'If you post without an account, we set a cookie that lets you edit your posts later. It contains a random number and lasts for one year from your last post.', 'easyq' ) ) . '</p>';
		}
		// The label goes before the first paragraph of the section, as core marks suggested text.
		$notice = Options::get_bool( 'users', 'show_notice_visitor' );
		if ( $notice ) {
			$strings[] = '<p>' . ( $anonymous ? '' : $suggested ) . __( 'If you log in on a question and answer page or close the notice for new visitors, we set a cookie so that the notice is not shown again. It contains no personal data and lasts for ten years.', 'easyq' ) . '</p>';
		}
		$strings[] = '<p>' . ( $anonymous || $notice ? '' : $suggested ) . __( 'Views of questions are counted without cookies. So that a second view in a row does not count, a hash of your IP address is kept with the question for at most one hour; the address itself is not stored.', 'easyq' ) . '</p>';

		$strings[] = '<h3 class="wp-block-heading">' . __( 'How long we keep the data of the question and answer pages', 'easyq' ) . '</h3>';
		$strings[] = '<p>' . $suggested . sprintf(
			/* translators: %d: Number of days. */
			_n(
				'Questions, answers, comments, votes and profiles are kept until they are deleted. IP addresses are deleted after %d day; those counted to limit how often an action can be performed, by the daily clean-up after the hour they count for.',
				'Questions, answers, comments, votes and profiles are kept until they are deleted. IP addresses are deleted after %d days; those counted to limit how often an action can be performed, by the daily clean-up after the hour they count for.',
				$days,
				'easyq'
			),
			$days
		) . '</p>';

		$strings[] = '<h3 class="wp-block-heading">' . __( 'Your data on the question and answer pages', 'easyq' ) . '</h3>';
		// The groups of the exporters and erasers of Privacy, of the features that are on.
		$messages = Options::get_bool( 'users', 'allow_private_messages' );
		$walls    = Options::get_bool( 'users', 'allow_user_walls' );
		$letter   = Options::get_bool( 'newsletter', 'mailing_enabled' );
		$exported = array_merge(
			array( __( 'your questions, answers and comments', 'easyq' ), __( 'votes and flags', 'easyq' ), __( 'your profile', 'easyq' ), __( 'favorites', 'easyq' ), __( 'updates', 'easyq' ), __( 'notices', 'easyq' ) ),
			$messages ? array( __( 'private messages', 'easyq' ) ) : array(),
			$walls ? array( __( 'posts on walls', 'easyq' ) ) : array(),
			$letter ? array( __( 'your newsletter subscription', 'easyq' ) ) : array()
		);
		$erased   = array_merge(
			array( __( 'your votes, flags, profile fields and uploaded profile picture', 'easyq' ), __( 'your favorites, updates and notices', 'easyq' ) ),
			$messages ? array( __( 'the private messages you wrote or received', 'easyq' ) ) : array(),
			$walls ? array( __( 'the posts on your wall', 'easyq' ) ) : array(),
			$letter ? array( __( 'your newsletter subscription', 'easyq' ) ) : array()
		);
		/* translators: %l: list of kinds of data, such as "your questions, answers and comments, votes and flags, and your profile". */
		$strings[] = '<p>' . $suggested . wp_sprintf( __( 'If you request an export of your personal data, it includes %l on the question and answer pages.', 'easyq' ), $exported ) . '</p>';
		$strings[] = '<p>' . ( 'delete' === Options::get_string( 'general', 'erase_posts' )
			? __( 'If you request the erasure of your personal data, your questions, answers and comments are deleted; those others have replied to stay without your name and with shortened IP addresses.', 'easyq' )
			: __( 'If you request the erasure of your personal data, your questions, answers and comments stay without your name and with shortened IP addresses.', 'easyq' ) )
			/* translators: %l: list of kinds of data, such as "your votes, flags, profile fields and uploaded profile picture, and your favorites". */
			. ' ' . wp_sprintf( __( 'We delete %l.', 'easyq' ), $erased ) . '</p>';

		return implode( "\n", $strings );
	}

	/**
	 * Checks whether visitors may post questions, answers or comments without an account.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	private static function anonymous_posting(): bool {
		foreach ( array( 'permit_post_q', 'permit_post_a', 'permit_post_c' ) as $permit ) {
			if ( Permits::ALL === Options::get_int( 'permissions', $permit ) ) {
				return true;
			}
		}

		return false;
	}
}
