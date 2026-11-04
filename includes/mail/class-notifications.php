<?php
/**
 * Notification emails.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Mail;

use EasyQ\Actor;
use EasyQ\Admin\Posts_Screen;
use EasyQ\Frontend\URLs;
use EasyQ\Posts\Post;
use EasyQ\Services;
use EasyQ\Settings\Options;
use EasyQ\Users\Levels;
use EasyQ\Users\Site_Meta;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Sends emails about events, ported from the Question2Answer module `qa-event-notify.php`.
 *
 * Registration emails change core's emails through its filters, as core sends them anyway.
 *
 * @since 0.1.0
 */
final class Notifications {

	/**
	 * User meta that turns off all emails about the posts of a user.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const OFF_META = 'easyq_notifications_off';

	/**
	 * Sets up the notifications.
	 *
	 * @since 0.1.0
	 *
	 * @param Mailer $mailer Sends the emails.
	 */
	public function __construct( private readonly Mailer $mailer = new Mailer() ) {
	}

	/**
	 * Hooks the notifications into WordPress.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_action( 'easyq_q_post', array( $this, 'question_posted' ), 10, 2 );
		add_action( 'easyq_a_post', array( $this, 'answer_posted' ), 10, 2 );
		add_action( 'easyq_c_post', array( $this, 'comment_posted' ), 10, 2 );
		add_action( 'easyq_a_select', array( $this, 'answer_selected' ), 10, 2 );
		add_action( 'easyq_u_level', array( $this, 'level_changed' ) );

		foreach ( array( 'q', 'a', 'c' ) as $type ) {
			add_action( "easyq_{$type}_queue", array( $this, 'post_queued' ), 10, 2 );
			add_action( "easyq_{$type}_requeue", array( $this, 'post_requeued' ), 10, 2 );
			add_action( "easyq_{$type}_flag", array( $this, 'post_flagged' ) );
		}

		add_filter( 'wp_send_new_user_notification_to_admin', array( $this, 'send_registration_to_admin' ) );
		add_filter( 'wp_new_user_notification_email_admin', array( $this, 'registration_email_to_admin' ), 10, 2 );
		add_filter( 'wp_new_user_notification_email', array( $this, 'welcome_email' ), 10, 2 );
	}

	/**
	 * Whether a user gets emails about their posts.
	 *
	 * @since 0.1.0
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function enabled( int $user_id ): bool {
		return empty( Site_Meta::get( $user_id, self::OFF_META ) );
	}

	/**
	 * Turns the emails about the posts of a user on or off.
	 *
	 * @since 0.1.0
	 *
	 * @param int  $user_id User ID.
	 * @param bool $enabled Whether the user gets the emails.
	 */
	public static function set_enabled( int $user_id, bool $enabled ): void {
		if ( $enabled ) {
			Site_Meta::delete( $user_id, self::OFF_META );
		} else {
			Site_Meta::update( $user_id, self::OFF_META, 1 );
		}
	}

	/**
	 * Notifies about a new question: the author of the answer it follows on from, and the site owner.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $params Event parameters.
	 * @param Actor                $actor  Author of the question.
	 */
	public function question_posted( array $params, Actor $actor ): void {
		$question = $this->post( $params['post_id'] ?? null );
		if ( null === $question ) {
			return;
		}

		$answer    = null === $question->parent_id ? null : Services::posts()->find( $question->parent_id );
		$recipient = null === $answer ? null : $this->author_of( $answer, $actor );
		if ( null !== $answer && null !== $recipient ) {
			$this->mailer->send(
				'a_followed',
				array( $recipient ),
				fn (): array => array(
					'###AUTHOR###'         => $this->name_of( $actor, $question ),
					'###QUESTION_TITLE###' => $this->censored( (string) $question->title ),
					'###ANSWER###'         => $this->censored( $answer->text() ),
					'###URL###'            => URLs::post( $question ),
				)
			);
		}

		if ( Options::get_bool( 'emails', 'notify_admin_q_post' ) ) {
			// Not censored, as the site owner should see bad words.
			$this->mailer->send(
				'q_posted',
				array( $this->site_owner() ),
				fn (): array => array(
					'###AUTHOR###'         => $this->name_of( $actor, $question ),
					'###QUESTION_TITLE###' => (string) $question->title,
					'###QUESTION###'       => $question->text(),
					'###URL###'            => URLs::post( $question ),
				)
			);
		}
	}

	/**
	 * Notifies the author of a question about a new answer.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $params Event parameters.
	 * @param Actor                $actor  Author of the answer.
	 */
	public function answer_posted( array $params, Actor $actor ): void {
		$answer   = $this->post( $params['post_id'] ?? null );
		$question = null === $answer ? null : Services::post_service()->question_of( $answer );
		if ( null === $answer || null === $question ) {
			return;
		}

		$recipient = $this->author_of( $question, $actor );
		if ( null === $recipient ) {
			return;
		}

		$this->mailer->send(
			'q_answered',
			array( $recipient ),
			fn (): array => array(
				'###AUTHOR###'         => $this->name_of( $actor, $answer ),
				'###ANSWER###'         => $this->censored( $answer->text() ),
				'###QUESTION_TITLE###' => (string) $question->title,
				'###URL###'            => URLs::post( $answer ),
			)
		);
	}

	/**
	 * Notifies about a new comment: the author of the post it is on, and the authors of
	 * the other comments there, each person once.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $params Event parameters.
	 * @param Actor                $actor  Author of the comment.
	 */
	public function comment_posted( array $params, Actor $actor ): void {
		$comment = $this->post( $params['post_id'] ?? null );
		$parent  = null === $comment || null === $comment->parent_id ? null : Services::posts()->find( $comment->parent_id );
		if ( null === $comment || null === $parent ) {
			return;
		}

		$is_question  = Post::QUESTION === $parent->base_type();
		$placeholders = fn (): array => array(
			'###AUTHOR###'  => $this->name_of( $actor, $comment ),
			'###COMMENT###' => $this->censored( $comment->text() ),
			'###CONTEXT###' => $this->censored( $is_question ? (string) $parent->title : $parent->text() ),
			'###URL###'     => URLs::post( $comment ),
		);

		$notified  = array();
		$recipient = $this->author_of( $parent, $actor );
		if ( null !== $recipient ) {
			$notified[ strtolower( $recipient->email ) ] = true;
			$this->mailer->send( $is_question ? 'q_commented' : 'a_commented', array( $recipient ), $placeholders );
		}

		$thread = is_array( $params['thread'] ?? null ) ? $params['thread'] : array();
		foreach ( $thread as $id ) {
			$earlier   = $this->post( $id );
			$recipient = null === $earlier ? null : $this->author_of( $earlier, $actor );
			if ( null === $recipient || isset( $notified[ strtolower( $recipient->email ) ] ) ) {
				continue;
			}

			$notified[ strtolower( $recipient->email ) ] = true;
			$this->mailer->send( 'c_commented', array( $recipient ), $placeholders );
		}
	}

	/**
	 * Notifies the author of an answer that it was selected.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $params Event parameters.
	 * @param Actor                $actor  Who selected the answer.
	 */
	public function answer_selected( array $params, Actor $actor ): void {
		$answer   = $this->post( $params['post_id'] ?? null );
		$question = $this->post( $params['parent_id'] ?? null );
		if ( null === $answer || null === $question ) {
			return;
		}

		$recipient = $this->author_of( $answer, $actor );
		if ( null === $recipient ) {
			return;
		}

		$this->mailer->send(
			'a_selected',
			array( $recipient ),
			fn (): array => array(
				'###AUTHOR###'         => $this->name_of( $actor ),
				'###ANSWER###'         => $this->censored( $answer->text() ),
				'###QUESTION_TITLE###' => $this->censored( (string) $question->title ),
				'###URL###'            => URLs::post( $answer ),
			)
		);
	}

	/**
	 * Tells the site owner that a post waits for approval.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $params Event parameters.
	 * @param Actor                $actor  Author of the post.
	 */
	public function post_queued( array $params, Actor $actor ): void {
		$this->moderation_email( 'moderate', $params, $actor );
	}

	/**
	 * Tells the site owner that an edited post waits for approval again.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $params Event parameters.
	 * @param Actor                $actor  Who edited the post.
	 */
	public function post_requeued( array $params, Actor $actor ): void {
		$this->moderation_email( 'remoderate', $params, $actor );
	}

	/**
	 * Tells the site owner about a flagged post, at the first and then every few flags.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $params Event parameters.
	 */
	public function post_flagged( array $params ): void {
		$post  = $this->post( $params['post_id'] ?? null );
		$flags = is_numeric( $params['flag_count'] ?? null ) ? (int) $params['flag_count'] : 0;
		$count = $flags - Options::get_int( 'emails', 'flagging_notify_first' );
		if ( null === $post || $count < 0 || 0 !== $count % max( 1, Options::get_int( 'emails', 'flagging_notify_every' ) ) ) {
			return;
		}

		$this->mailer->send(
			'flagged',
			array( $this->site_owner() ),
			fn (): array => array(
				'###AUTHOR###'    => $this->name_of_author( $post ),
				/* translators: %s: number of flags. */
				'###FLAGS###'     => sprintf( _n( '%s flag', '%s flags', $flags, 'easyq' ), number_format_i18n( $flags ) ),
				'###POST###'      => $this->post_text( $post ),
				'###URL###'       => URLs::post( $post ),
				'###ADMIN_URL###' => self::posts_screen_url( 'flagged' ),
			)
		);
	}

	/**
	 * Tells a user that they were approved.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $params Event parameters.
	 */
	public function level_changed( array $params ): void {
		$level = is_numeric( $params['level'] ?? null ) ? (int) $params['level'] : Levels::BASIC;
		$old   = is_numeric( $params['old_level'] ?? null ) ? (int) $params['old_level'] : Levels::BASIC;
		$user  = is_numeric( $params['user_id'] ?? null ) ? get_userdata( (int) $params['user_id'] ) : false;
		if ( false === $user || $level < Levels::APPROVED || $old >= Levels::APPROVED ) {
			return;
		}

		$this->mailer->send(
			'u_approved',
			array( new Recipient( $user->user_email, $user->ID, $user->display_name ) ),
			static fn (): array => array( '###URL###' => URLs::user( $user ) )
		);
	}

	/**
	 * Applies the setting to core's email about a new user to the site owner.
	 *
	 * @since 0.1.0
	 *
	 * @param bool $send Whether to send the email.
	 * @return bool
	 */
	public function send_registration_to_admin( bool $send ): bool {
		return $send && Options::get_bool( 'emails', 'register_notify_admin' );
	}

	/**
	 * Changes core's email about a new user to the site owner.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $email Core's email.
	 * @param WP_User              $user  New user.
	 * @return array<string, mixed>
	 */
	public function registration_email_to_admin( array $email, WP_User $user ): array {
		$to_approve = Options::get_bool( 'spam', 'moderate_users' );
		$name       = $to_approve ? 'u_to_approve' : 'u_registered';
		$content    = Mailer::content(
			$name,
			array(
				'###USER###'      => $user->display_name,
				'###URL###'       => URLs::user( $user ),
				'###ADMIN_URL###' => admin_url( 'users.php' ),
			)
		);

		return $this->core_email(
			$name,
			array(
				'to'      => $this->site_owner()->email,
				'subject' => $content['subject'],
				'message' => $content['message'],
			) + $email
		);
	}

	/**
	 * Changes core's email to a new user, which keeps core's link to set the password.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $email Core's email.
	 * @param WP_User              $user  New user.
	 * @return array<string, mixed>
	 */
	public function welcome_email( array $email, WP_User $user ): array {
		$custom  = Options::get_bool( 'users', 'show_custom_welcome' ) ? trim( Options::get_string( 'users', 'custom_welcome' ) ) : '';
		$content = Mailer::content(
			'welcome',
			array(
				'###CUSTOM###'     => '' === $custom ? '' : $custom . "\n\n",
				'###LOGIN_LINK###' => trim( is_string( $email['message'] ?? null ) ? $email['message'] : '' ),
				'###USERNAME###'   => $user->user_login,
				'###EMAIL###'      => $user->user_email,
			)
		);

		return $this->core_email(
			'welcome',
			array(
				'subject' => $content['subject'],
				'message' => $content['message'],
			) + $email
		);
	}

	/**
	 * Finishes an email that core sends: core runs the subject through `sprintf()`, so a percent sign is escaped.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $name  Template name.
	 * @param array<string, mixed> $email The email.
	 * @return array<string, mixed>
	 */
	private function core_email( string $name, array $email ): array {
		$email = Mailer::filter_email( $name, $email );

		$email['subject'] = str_replace( '%', '%%', is_string( $email['subject'] ?? null ) ? $email['subject'] : '' );

		return $email;
	}

	/**
	 * Sends an email about a post that waits for approval.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $name   Template name.
	 * @param array<string, mixed> $params Event parameters.
	 * @param Actor                $actor  Who caused the event.
	 */
	private function moderation_email( string $name, array $params, Actor $actor ): void {
		$post = $this->post( $params['post_id'] ?? null );
		if ( null === $post || ! Options::get_bool( 'emails', 'moderate_notify_admin' ) ) {
			return;
		}

		$this->mailer->send(
			$name,
			array( $this->site_owner() ),
			fn (): array => array(
				'###AUTHOR###'    => $this->name_of( $actor, $post ),
				'###POST###'      => $this->post_text( $post ),
				'###URL###'       => URLs::post( $post ),
				'###ADMIN_URL###' => self::posts_screen_url( 'pending' ),
			)
		);
	}

	/**
	 * Returns the author of a post as a recipient, if they asked for emails and did not cause the event.
	 *
	 * @since 0.1.0
	 *
	 * @param Post  $post  Post.
	 * @param Actor $actor Who caused the event.
	 * @return Recipient|null
	 */
	private function author_of( Post $post, Actor $actor ): ?Recipient {
		if ( null === $post->notify || $this->caused_by_author( $post, $actor ) ) {
			return null;
		}

		// Anonymous authors leave an address; users only a mark that they want emails.
		if ( null === $post->user_id ) {
			return is_email( $post->notify ) ? new Recipient( $post->notify, null, null, $post ) : null;
		}

		$user = get_userdata( $post->user_id );
		if ( false === $user || ! Services::user_query()->is_member( $user ) || ! self::enabled( $user->ID ) ) {
			return null;
		}

		return new Recipient( $user->user_email, $user->ID, $user->display_name, $post );
	}

	/**
	 * Whether the author of a post caused an event themselves.
	 *
	 * @since 0.1.0
	 *
	 * @param Post  $post  Post.
	 * @param Actor $actor Who caused the event.
	 * @return bool
	 */
	private function caused_by_author( Post $post, Actor $actor ): bool {
		if ( null !== $post->user_id ) {
			return $post->user_id === $actor->user_id;
		}

		return null !== $post->cookie_id && $post->cookie_id === $actor->cookie_id;
	}

	/**
	 * Returns the site owner as a recipient: the address from the settings, otherwise the admin email of the site.
	 *
	 * @since 0.1.0
	 *
	 * @return Recipient
	 */
	private function site_owner(): Recipient {
		$email = Options::get_string( 'emails', 'feedback_email' );
		$admin = get_option( 'admin_email' );

		return new Recipient( '' === $email && is_string( $admin ) ? $admin : $email );
	}

	/**
	 * Returns the name of who caused an event.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor     $actor Who caused the event.
	 * @param Post|null $post  Post with the name of an anonymous author.
	 * @return string
	 */
	private function name_of( Actor $actor, ?Post $post = null ): string {
		$user = null === $actor->user_id ? false : get_userdata( $actor->user_id );
		if ( false !== $user ) {
			return $user->display_name;
		}

		return null === $post ? __( 'anonymous', 'easyq' ) : $this->name_of_author( $post );
	}

	/**
	 * Returns the name of the author of a post.
	 *
	 * @since 0.1.0
	 *
	 * @param Post $post Post.
	 * @return string
	 */
	private function name_of_author( Post $post ): string {
		$user = null === $post->user_id ? false : get_userdata( $post->user_id );
		if ( false !== $user ) {
			return $user->display_name;
		}

		return null === $post->author_name || '' === $post->author_name ? __( 'anonymous', 'easyq' ) : $post->author_name;
	}

	/**
	 * Returns the uncensored text of a post with its title, for the site owner.
	 *
	 * @since 0.1.0
	 *
	 * @param Post $post Post.
	 * @return string
	 */
	private function post_text( Post $post ): string {
		return trim( (string) $post->title . "\n\n" . $post->text() );
	}

	/**
	 * Returns text with block words censored.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private function censored( string $text ): string {
		return Services::content_renderer()->text( $text );
	}

	/**
	 * Returns a post from an event parameter.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $id Post ID.
	 * @return Post|null
	 */
	private function post( mixed $id ): ?Post {
		return is_numeric( $id ) ? Services::posts()->find( (int) $id ) : null;
	}

	/**
	 * Returns the address of a view of the posts screen.
	 *
	 * @since 0.1.0
	 *
	 * @param string $view View.
	 * @return string
	 */
	private static function posts_screen_url( string $view ): string {
		return add_query_arg(
			array(
				'page' => Posts_Screen::PAGE,
				'view' => $view,
			),
			admin_url( 'admin.php' )
		);
	}
}
