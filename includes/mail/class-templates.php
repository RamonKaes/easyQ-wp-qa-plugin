<?php
/**
 * Email templates.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Mail;

defined( 'ABSPATH' ) || exit;

/**
 * Holds the texts of the emails, taken from the Question2Answer language file `qa-lang-emails.php`.
 *
 * Placeholders use the format of core's own emails, e.g. `###SITENAME###`. The texts
 * are translated when they are read, so read them in the language of the recipient.
 *
 * @since 0.1.0
 */
final class Templates {

	/**
	 * Returns the subject and body of a template.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name Template name.
	 * @return array{subject: string, body: string}|null The template, or null if there is none with this name.
	 */
	public static function get( string $name ): ?array {
		return self::defaults()[ $name ] ?? null;
	}

	/**
	 * Returns the templates.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{subject: string, body: string}>
	 */
	public static function defaults(): array {
		return array(
			'a_commented'  => array(
				'subject' => __( 'Your ###SITENAME### answer has a new comment', 'easyq' ),
				'body'    => __( "Your answer on ###SITENAME### has a new comment by ###AUTHOR###:\n\n###COMMENT###\n\nYour answer was:\n\n###CONTEXT###\n\nYou may respond by adding your own comment:\n\n###URL###\n\nThank you,\n\n###SITENAME###", 'easyq' ),
			),
			'a_followed'   => array(
				'subject' => __( 'Your ###SITENAME### answer has a related question', 'easyq' ),
				'body'    => __( "Your answer on ###SITENAME### has a new related question by ###AUTHOR###:\n\n###QUESTION_TITLE###\n\nYour answer was:\n\n###ANSWER###\n\nClick below to answer the new question:\n\n###URL###\n\nThank you,\n\n###SITENAME###", 'easyq' ),
			),
			'a_selected'   => array(
				'subject' => __( 'Your ###SITENAME### answer has been selected!', 'easyq' ),
				'body'    => __( "Congratulations! Your answer on ###SITENAME### has been selected as the best by ###AUTHOR###:\n\n###ANSWER###\n\nThe question was:\n\n###QUESTION_TITLE###\n\nClick below to see your answer:\n\n###URL###\n\nThank you,\n\n###SITENAME###", 'easyq' ),
			),
			'c_commented'  => array(
				'subject' => __( 'Your ###SITENAME### comment has been added to', 'easyq' ),
				'body'    => __( "A new comment by ###AUTHOR### has been added after your comment on ###SITENAME###:\n\n###COMMENT###\n\nThe discussion is following:\n\n###CONTEXT###\n\nYou may respond by adding another comment:\n\n###URL###\n\nThank you,\n\n###SITENAME###", 'easyq' ),
			),
			'flagged'      => array(
				'subject' => __( '###SITENAME### has a flagged post', 'easyq' ),
				'body'    => __( "A post by ###AUTHOR### has received ###FLAGS###:\n\n###POST###\n\nClick below to see the post:\n\n###URL###\n\nClick below to review all flagged posts:\n\n###ADMIN_URL###\n\nThank you,\n\n###SITENAME###", 'easyq' ),
			),
			'moderate'     => array(
				'subject' => __( '###SITENAME### moderation', 'easyq' ),
				'body'    => __( "A post by ###AUTHOR### requires your approval:\n\n###POST###\n\nClick below to approve or reject the post:\n\n###URL###\n\nClick below to review all queued posts:\n\n###ADMIN_URL###\n\nThank you,\n\n###SITENAME###", 'easyq' ),
			),
			'q_answered'   => array(
				'subject' => __( 'Your ###SITENAME### question was answered', 'easyq' ),
				'body'    => __( "Your question on ###SITENAME### has been answered by ###AUTHOR###:\n\n###ANSWER###\n\nYour question was:\n\n###QUESTION_TITLE###\n\nIf you like this answer, you may select it as the best:\n\n###URL###\n\nThank you,\n\n###SITENAME###", 'easyq' ),
			),
			'q_commented'  => array(
				'subject' => __( 'Your ###SITENAME### question has a new comment', 'easyq' ),
				'body'    => __( "Your question on ###SITENAME### has a new comment by ###AUTHOR###:\n\n###COMMENT###\n\nYour question was:\n\n###CONTEXT###\n\nYou may respond by adding your own comment:\n\n###URL###\n\nThank you,\n\n###SITENAME###", 'easyq' ),
			),
			'q_posted'     => array(
				'subject' => __( '###SITENAME### has a new question', 'easyq' ),
				'body'    => __( "A new question has been asked by ###AUTHOR###:\n\n###QUESTION_TITLE###\n\n###QUESTION###\n\nClick below to see the question:\n\n###URL###\n\nThank you,\n\n###SITENAME###", 'easyq' ),
			),
			'remoderate'   => array(
				'subject' => __( '###SITENAME### moderation', 'easyq' ),
				'body'    => __( "An edited post by ###AUTHOR### requires your reapproval:\n\n###POST###\n\nClick below to approve or hide the edited post:\n\n###URL###\n\nClick below to review all queued posts:\n\n###ADMIN_URL###\n\nThank you,\n\n###SITENAME###", 'easyq' ),
			),
			'u_approved'   => array(
				'subject' => __( 'Your ###SITENAME### user has been approved', 'easyq' ),
				'body'    => __( "You can see your new user profile here:\n\n###URL###\n\nThank you,\n\n###SITENAME###", 'easyq' ),
			),
			'u_registered' => array(
				'subject' => __( '###SITENAME### has a new registered user', 'easyq' ),
				'body'    => __( "A new user has registered as ###USER###.\n\nClick below to view the user profile:\n\n###URL###\n\nThank you,\n\n###SITENAME###", 'easyq' ),
			),
			'u_to_approve' => array(
				'subject' => __( '###SITENAME### has a new registered user', 'easyq' ),
				'body'    => __( "A new user has registered as ###USER###.\n\nClick below to approve the user:\n\n###URL###\n\nClick below to review all users waiting for approval:\n\n###ADMIN_URL###\n\nThank you,\n\n###SITENAME###", 'easyq' ),
			),
			'welcome'      => array(
				'subject' => __( 'Welcome to ###SITENAME###!', 'easyq' ),
				'body'    => __( "Thank you for registering for ###SITENAME###.\n\n###CUSTOM######LOGIN_LINK###\n\nYour login details are as follows:\n\nUsername: ###USERNAME###\nEmail: ###EMAIL###\n\nPlease keep this information safe for future reference.\n\nThank you,\n\n###SITENAME###\n###SITEURL###", 'easyq' ),
			),
		);
	}
}
