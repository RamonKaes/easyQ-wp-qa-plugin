<?php
/**
 * Email templates.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Mail;

use EasyQ\Capabilities;
use EasyQ\Frontend\Routes;

defined( 'ABSPATH' ) || exit;

/**
 * Holds the texts of the emails: the defaults, taken from the Question2Answer language
 * file `qa-lang-emails.php`, and the texts edited by the site owner.
 *
 * Placeholders use the format of core's own emails, e.g. `###SITENAME###`. The defaults
 * are translated when they are read, so read them in the language of the recipient.
 * Edited texts win; on multilingual sites they go through the string translation hooks.
 *
 * @since 0.1.0
 */
final class Templates {

	/**
	 * Option with the edited templates; also the settings group of the form.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const OPTION = 'easyq_email_templates';

	/**
	 * Placeholders of every email.
	 *
	 * @since 0.1.0
	 *
	 * @var string[]
	 */
	private const COMMON = array( '###SITENAME###', '###SITEURL###', '###USERNAME###', '###EMAIL###' );

	/**
	 * Further placeholders per template.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string[]>
	 */
	private const PLACEHOLDERS = array(
		'a_commented'  => array( '###AUTHOR###', '###COMMENT###', '###CONTEXT###', '###URL###' ),
		'a_followed'   => array( '###AUTHOR###', '###QUESTION_TITLE###', '###ANSWER###', '###URL###' ),
		'a_selected'   => array( '###AUTHOR###', '###ANSWER###', '###QUESTION_TITLE###', '###URL###' ),
		'c_commented'  => array( '###AUTHOR###', '###COMMENT###', '###CONTEXT###', '###URL###' ),
		'flagged'      => array( '###AUTHOR###', '###FLAGS###', '###POST###', '###URL###', '###ADMIN_URL###' ),
		'moderate'     => array( '###AUTHOR###', '###POST###', '###URL###', '###ADMIN_URL###' ),
		'q_answered'   => array( '###AUTHOR###', '###ANSWER###', '###QUESTION_TITLE###', '###URL###' ),
		'q_commented'  => array( '###AUTHOR###', '###COMMENT###', '###CONTEXT###', '###URL###' ),
		'q_posted'     => array( '###AUTHOR###', '###QUESTION_TITLE###', '###QUESTION###', '###URL###' ),
		'remoderate'   => array( '###AUTHOR###', '###POST###', '###URL###', '###ADMIN_URL###' ),
		'u_approved'   => array( '###URL###' ),
		'u_registered' => array( '###USER###', '###URL###' ),
		'u_to_approve' => array( '###USER###', '###URL###', '###ADMIN_URL###' ),
		'welcome'      => array( '###CUSTOM###', '###LOGIN_LINK###' ),
	);

	/**
	 * Registers the edited templates as a setting.
	 *
	 * @since 0.1.0
	 */
	public static function register_setting(): void {
		$template = array(
			'type'       => 'object',
			'properties' => array(
				'subject' => array( 'type' => 'string' ),
				'body'    => array( 'type' => 'string' ),
			),
		);

		register_setting(
			self::OPTION,
			self::OPTION,
			array(
				'type'              => 'object',
				'label'             => __( 'Email templates', 'easyq' ),
				'description'       => __( 'Edited texts of the emails, keyed by template.', 'easyq' ),
				'default'           => array(),
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'show_in_rest'      => array(
					'schema' => array(
						'type'       => 'object',
						'properties' => array_fill_keys( array_keys( self::PLACEHOLDERS ), $template ),
					),
				),
			)
		);

		add_filter( 'option_page_capability_' . self::OPTION, array( self::class, 'capability' ) );
	}

	/**
	 * Returns the capability to save the templates: users who manage easyQ, without managing the whole site.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public static function capability(): string {
		return Capabilities::MANAGE;
	}

	/**
	 * Sanitizes a change of the templates and merges it into the stored ones, as the form sends one template.
	 *
	 * A template of null, or equal to the default, is removed, so that the default applies.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $input Templates to change, keyed by name.
	 * @return array<string, array{subject: string, body: string}>
	 */
	public static function sanitize( mixed $input ): array {
		$stored = self::stored();
		if ( ! is_array( $input ) ) {
			return $stored;
		}

		$defaults = self::defaults();
		foreach ( array_intersect_key( $input, $defaults ) as $name => $template ) {
			if ( null === $template ) {
				unset( $stored[ $name ] );
				continue;
			}

			$subject = is_array( $template ) && is_string( $template['subject'] ?? null ) ? sanitize_text_field( $template['subject'] ) : '';
			$body    = is_array( $template ) && is_string( $template['body'] ?? null ) ? sanitize_textarea_field( str_replace( "\r\n", "\n", $template['body'] ) ) : '';
			if ( '' === $subject || '' === trim( $body ) ) {
				self::settings_error( 'empty', __( 'An email needs a subject and a text. The template was not changed.', 'easyq' ), 'error' );
				continue;
			}

			$unknown = array_diff( self::placeholders_in( $subject . ' ' . $body ), self::placeholders( $name ) );
			if ( array() !== $unknown ) {
				/* translators: %s: placeholders, e.g. ###NAME###. */
				self::settings_error( 'unknown', sprintf( __( 'The text was saved, but this template has no such placeholders, so they stay as they are in the email: %s', 'easyq' ), implode( ', ', $unknown ) ), 'warning' );
			}

			if ( $subject === $defaults[ $name ]['subject'] && $body === $defaults[ $name ]['body'] ) {
				unset( $stored[ $name ] );
				continue;
			}

			$stored[ $name ] = array(
				'subject' => $subject,
				'body'    => $body,
			);

			// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The public hook of WPML, also handled by Polylang.
			do_action( 'wpml_register_single_string', 'easyq', "email_{$name}_subject", $subject );
			do_action( 'wpml_register_single_string', 'easyq', "email_{$name}_body", $body );
			// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		}

		return $stored;
	}

	/**
	 * Returns the subject and body of a template.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name Template name.
	 * @return array{subject: string, body: string}|null The template, or null if there is none with this name.
	 */
	public static function get( string $name ): ?array {
		$stored = self::stored()[ $name ] ?? null;
		if ( null === $stored ) {
			return self::defaults()[ $name ] ?? null;
		}

		return array(
			'subject' => self::translated( $stored['subject'], "email_{$name}_subject" ),
			'body'    => self::translated( $stored['body'], "email_{$name}_body" ),
		);
	}

	/**
	 * Whether a template has been edited.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name Template name.
	 * @return bool
	 */
	public static function is_edited( string $name ): bool {
		return isset( self::stored()[ $name ] );
	}

	/**
	 * Saves a template.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name    Template name.
	 * @param string $subject Subject.
	 * @param string $body    Text.
	 * @return bool Whether the template has this text now; false if subject or text are empty.
	 */
	public static function save( string $name, string $subject, string $body ): bool {
		$before = self::get( $name );
		update_option(
			self::OPTION,
			array(
				$name => array(
					'subject' => $subject,
					'body'    => $body,
				),
			),
			false
		);

		return '' !== sanitize_text_field( $subject ) && '' !== trim( sanitize_textarea_field( $body ) ) && null !== $before;
	}

	/**
	 * Goes back to the default of a template.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name Template name.
	 */
	public static function reset( string $name ): void {
		update_option( self::OPTION, array( $name => null ), false );
	}

	/**
	 * Returns the name of each template and who gets it.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{label: string, audience: string}>
	 */
	public static function info(): array {
		$author = __( 'Author of the post', 'easyq' );
		$owner  = __( 'Site owner', 'easyq' );
		$user   = __( 'The user', 'easyq' );

		return array(
			'q_answered'   => array(
				'label'    => __( 'Question answered', 'easyq' ),
				'audience' => $author,
			),
			'q_commented'  => array(
				'label'    => __( 'Comment on a question', 'easyq' ),
				'audience' => $author,
			),
			'a_commented'  => array(
				'label'    => __( 'Comment on an answer', 'easyq' ),
				'audience' => $author,
			),
			'c_commented'  => array(
				'label'    => __( 'Comment after a comment', 'easyq' ),
				'audience' => $author,
			),
			'a_selected'   => array(
				'label'    => __( 'Answer selected as the best', 'easyq' ),
				'audience' => $author,
			),
			'a_followed'   => array(
				'label'    => __( 'Related question to an answer', 'easyq' ),
				'audience' => $author,
			),
			'q_posted'     => array(
				'label'    => __( 'New question', 'easyq' ),
				'audience' => $owner,
			),
			'moderate'     => array(
				'label'    => __( 'Post waits for approval', 'easyq' ),
				'audience' => $owner,
			),
			'remoderate'   => array(
				'label'    => __( 'Edited post waits for approval', 'easyq' ),
				'audience' => $owner,
			),
			'flagged'      => array(
				'label'    => __( 'Flagged post', 'easyq' ),
				'audience' => $owner,
			),
			'u_registered' => array(
				'label'    => __( 'New user', 'easyq' ),
				'audience' => $owner,
			),
			'u_to_approve' => array(
				'label'    => __( 'New user waits for approval', 'easyq' ),
				'audience' => $owner,
			),
			'welcome'      => array(
				'label'    => __( 'Welcome', 'easyq' ),
				'audience' => $user,
			),
			'u_approved'   => array(
				'label'    => __( 'User approved', 'easyq' ),
				'audience' => $user,
			),
		);
	}

	/**
	 * Returns the placeholders a template can use.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name Template name.
	 * @return string[]
	 */
	public static function placeholders( string $name ): array {
		return array_merge( self::PLACEHOLDERS[ $name ] ?? array(), self::COMMON );
	}

	/**
	 * Returns what each placeholder stands for.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string>
	 */
	public static function placeholder_descriptions(): array {
		return array(
			'###SITENAME###'       => __( 'Name of the site', 'easyq' ),
			'###SITEURL###'        => __( 'Address of the questions and answers', 'easyq' ),
			'###USERNAME###'       => __( 'Name of the recipient', 'easyq' ),
			'###EMAIL###'          => __( 'Email address of the recipient', 'easyq' ),
			'###AUTHOR###'         => __( 'Who wrote the post or selected the answer', 'easyq' ),
			'###QUESTION_TITLE###' => __( 'Title of the question', 'easyq' ),
			'###QUESTION###'       => __( 'Text of the question', 'easyq' ),
			'###ANSWER###'         => __( 'Text of the answer', 'easyq' ),
			'###COMMENT###'        => __( 'Text of the comment', 'easyq' ),
			'###CONTEXT###'        => __( 'Title of the question or text of the answer the comment is on', 'easyq' ),
			'###POST###'           => __( 'Title and text of the post', 'easyq' ),
			'###FLAGS###'          => __( 'Number of flags', 'easyq' ),
			'###USER###'           => __( 'Name of the new user', 'easyq' ),
			'###URL###'            => __( 'Address of the post or the profile', 'easyq' ),
			'###ADMIN_URL###'      => __( 'Address of the screen to review it', 'easyq' ),
			'###CUSTOM###'         => __( 'Own text from the settings', 'easyq' ),
			'###LOGIN_LINK###'     => __( 'Link to set the password; added at the end if left out', 'easyq' ),
		);
	}

	/**
	 * Returns sample values of the placeholders, for the preview and the test email.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string>
	 */
	public static function samples(): array {
		return array(
			'###AUTHOR###'         => __( 'Alex Example', 'easyq' ),
			'###QUESTION_TITLE###' => __( 'How do I build a block theme?', 'easyq' ),
			'###QUESTION###'       => __( 'I want to create my own block theme. Where do I start?', 'easyq' ),
			'###ANSWER###'         => __( 'Start with the theme.json file and a templates folder.', 'easyq' ),
			'###COMMENT###'        => __( 'Thanks, that helps a lot.', 'easyq' ),
			'###CONTEXT###'        => __( 'Start with the theme.json file and a templates folder.', 'easyq' ),
			'###POST###'           => __( 'How do I build a block theme?', 'easyq' ),
			/* translators: %s: number of flags. */
			'###FLAGS###'          => sprintf( _n( '%s flag', '%s flags', 2, 'easyq' ), number_format_i18n( 2 ) ),
			'###USER###'           => __( 'Alex Example', 'easyq' ),
			'###URL###'            => Routes::url( 'home' ),
			'###ADMIN_URL###'      => admin_url(),
			'###CUSTOM###'         => '',
			'###LOGIN_LINK###'     => __( 'To set your password, visit the following address:', 'easyq' ) . "\n\n" . wp_login_url(),
		);
	}

	/**
	 * Returns the edited templates.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{subject: string, body: string}>
	 */
	private static function stored(): array {
		$stored = get_option( self::OPTION, array() );
		$valid  = array();
		foreach ( is_array( $stored ) ? $stored : array() as $name => $template ) {
			if ( is_string( $name ) && is_array( $template ) && is_string( $template['subject'] ?? null ) && is_string( $template['body'] ?? null ) ) {
				$valid[ $name ] = array(
					'subject' => $template['subject'],
					'body'    => $template['body'],
				);
			}
		}

		return $valid;
	}

	/**
	 * Returns an edited text in the current language, on sites with a string translation plugin.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text Text.
	 * @param string $key  Key of the string.
	 * @return string
	 */
	private static function translated( string $text, string $key ): string {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The public hook of WPML, also handled by Polylang.
		$translated = apply_filters( 'wpml_translate_single_string', $text, 'easyq', $key );

		return is_string( $translated ) ? $translated : $text;
	}

	/**
	 * Returns the placeholders in a text.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	private static function placeholders_in( string $text ): array {
		preg_match_all( '/###[A-Z_]+###/', $text, $matches );

		return array_values( array_unique( $matches[0] ) );
	}

	/**
	 * Adds a message for the settings form; outside the admin area, as for core's settings, there is none.
	 *
	 * Core sanitizes twice when an option is first saved, as `update_option()` calls `add_option()`,
	 * so a message is only added once.
	 *
	 * @since 0.1.0
	 *
	 * @param string $code    Message code.
	 * @param string $message Message.
	 * @param string $type    Message type.
	 */
	private static function settings_error( string $code, string $message, string $type ): void {
		if ( ! function_exists( 'add_settings_error' ) ) {
			return;
		}

		foreach ( get_settings_errors( self::OPTION ) as $error ) {
			if ( $code === $error['code'] && $message === $error['message'] ) {
				return;
			}
		}

		add_settings_error( self::OPTION, $code, $message, $type );
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
