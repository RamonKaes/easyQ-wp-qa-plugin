<?php
/**
 * Tests for the notification emails.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Actor;
use EasyQ\Blocks\Block_Context;
use EasyQ\Database\Schema;
use EasyQ\Frontend\Page_Meta;
use EasyQ\Frontend\Route;
use EasyQ\Frontend\Routes;
use EasyQ\Frontend\URLs;
use EasyQ\Mail\Mailer;
use EasyQ\Mail\Notifications;
use EasyQ\Mail\Recipient;
use EasyQ\Mail\Templates;
use EasyQ\Mail\Unsubscribe;
use EasyQ\Posts\Post;
use EasyQ\Services;
use EasyQ\Settings\Options;
use EasyQ\Users\Levels;
use WP_Locale_Switcher;

/**
 * @covers \EasyQ\Mail\Mailer
 * @covers \EasyQ\Mail\Notifications
 * @covers \EasyQ\Mail\Recipient
 * @covers \EasyQ\Mail\Templates
 * @covers \EasyQ\Mail\Unsubscribe
 */
final class Notifications_Test extends Test_Case {

	/**
	 * Value of a post whose author wants emails, see Post_Service::notify_value().
	 */
	private const WANTS_EMAILS = '@';

	public function set_up(): void {
		parent::set_up();
		reset_phpmailer_instance();
		update_option( 'admin_email', 'owner@example.com' );
		// The test site runs on localhost, and PHPMailer refuses core's default sender wordpress@localhost.
		add_filter( 'wp_mail_from', static fn ( string $from ): string => str_ends_with( $from, '@localhost' ) ? 'wordpress@example.com' : $from );
	}

	public function tear_down(): void {
		reset_phpmailer_instance();
		Block_Context::router()->set_current( null );
		unset( $_GET['post'], $_GET['token'] );
		parent::tear_down();
	}

	/**
	 * Returns the emails sent so far with `to`, `subject`, `body` and `header`.
	 *
	 * @return list<array{to: string, subject: string, body: string, header: string}>
	 */
	private function sent(): array {
		$emails = array();
		foreach ( tests_retrieve_phpmailer_instance()->mock_sent as $mail ) {
			$emails[] = array(
				'to'      => $mail['to'][0][0],
				'subject' => $mail['subject'],
				'body'    => $mail['body'],
				'header'  => $mail['header'],
			);
		}

		return $emails;
	}

	/**
	 * Returns the addresses that got an email so far.
	 *
	 * @return string[]
	 */
	private function addresses(): array {
		return array_column( $this->sent(), 'to' );
	}

	/**
	 * Creates a user who gets emails and returns them as actor with their address.
	 *
	 * @param string $name Login and start of the address.
	 * @return Actor
	 */
	private function member( string $name ): Actor {
		return new Actor(
			self::factory()->user->create(
				array(
					'user_login'   => $name,
					'display_name' => ucfirst( $name ),
					'user_email'   => $name . '@example.com',
				)
			),
			null,
			'192.0.2.1'
		);
	}

	/**
	 * Creates a question by a user who asked for emails.
	 *
	 * @param Actor  $author Author.
	 * @param string $title  Title.
	 * @return Post
	 */
	private function question_with_emails( Actor $author, string $title = 'How do I configure the widget?' ): Post {
		return $this->post( Services::post_service()->create_question( $author, $title, 'I tried everything.', '', array(), null, self::WANTS_EMAILS ) );
	}

	/**
	 * Creates an answer by a user who asked for emails.
	 *
	 * @param Actor  $author   Author.
	 * @param Post   $question Question.
	 * @param string $content  Content.
	 * @return Post
	 */
	private function answer_with_emails( Actor $author, Post $question, string $content = 'Open the settings screen.' ): Post {
		return $this->post( Services::post_service()->create_answer( $author, $question, $content, '', self::WANTS_EMAILS ) );
	}

	/**
	 * Creates a comment by a user who asked for emails.
	 *
	 * @param Actor $author Author.
	 * @param Post  $question Question.
	 * @param Post  $parent_post Post the comment is on.
	 * @return Post
	 */
	private function comment_with_emails( Actor $author, Post $question, Post $parent_post ): Post {
		return $this->post( Services::post_service()->create_comment( $author, $question, $parent_post, 'A comment on it.', '', self::WANTS_EMAILS ) );
	}

	public function test_an_answer_notifies_the_author_of_the_question(): void {
		update_option( 'blogname', 'Tom &amp; Jerry' );
		$author   = $this->member( 'asker' );
		$question = $this->question_with_emails( $author );
		$answer   = $this->answer_with_emails( $this->member( 'helper' ), $question );

		$this->assertSame( array( 'asker@example.com' ), $this->addresses() );
		$mail = $this->sent()[0];

		// The site name is decoded, as core decodes it for plain text.
		$this->assertSame( 'Your Tom & Jerry question was answered', $mail['subject'] );
		$this->assertStringContainsString( 'Your question on Tom & Jerry has been answered', $mail['body'] );
		$this->assertStringStartsWith( 'Asker,', $mail['body'] );
		$this->assertStringContainsString( 'has been answered by Helper', $mail['body'] );
		$this->assertStringContainsString( 'Open the settings screen.', $mail['body'] );
		$this->assertStringContainsString( 'How do I configure the widget?', $mail['body'] );
		$this->assertStringContainsString( URLs::post( $answer ), $mail['body'] );
		$this->assertStringNotContainsString( '###', $mail['body'] );
		$this->assertStringContainsString( 'text/plain', $mail['header'] );
		// Addresses stay private, unlike the Reply-To of core's comment emails.
		$this->assertStringNotContainsString( 'Reply-To', $mail['header'] );
	}

	public function test_nobody_is_notified_about_their_own_post_or_without_asking(): void {
		$author   = $this->member( 'asker' );
		$question = $this->question_with_emails( $author );

		$this->answer_with_emails( $author, $question );
		$this->assertSame( array(), $this->addresses(), 'Own answer.' );

		$silent = $this->ask( $author, 'A question without emails' );
		$this->answer( $this->member( 'helper' ), $silent );
		$this->assertSame( array(), $this->addresses(), 'The author did not ask for emails.' );

		Notifications::set_enabled( (int) $author->user_id, false );
		$this->answer_with_emails( $this->member( 'other' ), $question );
		$this->assertSame( array(), $this->addresses(), 'The author turned off the emails.' );

		Notifications::set_enabled( (int) $author->user_id, true );
		$this->answer_with_emails( $this->member( 'third' ), $question );
		$this->assertSame( array( 'asker@example.com' ), $this->addresses() );
	}

	/**
	 * @group ms-required
	 */
	public function test_people_who_left_the_site_are_not_notified(): void {
		$this->skipWithoutMultisite();
		$author   = $this->member( 'asker' );
		$question = $this->question_with_emails( $author );
		remove_user_from_blog( (int) $author->user_id );
		$this->assertTrue( Services::user_query()->is_member( get_userdata( (int) $author->user_id ) ), 'Counts as a member through their points.' );
		global $wpdb;
		$wpdb->delete( Schema::table( 'userpoints' ), array( 'user_id' => $author->user_id ) );
		wp_cache_flush();

		$this->answer_with_emails( $this->member( 'helper' ), $question );

		$this->assertSame( array(), $this->addresses() );
	}

	public function test_a_comment_notifies_each_person_of_the_thread_once(): void {
		$asker    = $this->member( 'asker' );
		$first    = $this->member( 'first' );
		$question = $this->question_with_emails( $asker );
		$answer   = $this->answer_with_emails( $this->member( 'helper' ), $question );
		$this->comment_with_emails( $first, $question, $answer );
		$this->comment_with_emails( $first, $question, $answer );
		reset_phpmailer_instance();

		$this->comment_with_emails( $this->member( 'late' ), $question, $answer );

		// The author of the answer, and the earlier commenter once despite two comments.
		$this->assertSame( array( 'helper@example.com', 'first@example.com' ), $this->addresses() );
		$this->assertSame( Templates::defaults()['a_commented']['subject'], strtr( $this->sent()[0]['subject'], array( get_option( 'blogname' ) => '###SITENAME###' ) ) );
		$this->assertSame( Templates::defaults()['c_commented']['subject'], strtr( $this->sent()[1]['subject'], array( get_option( 'blogname' ) => '###SITENAME###' ) ) );

		reset_phpmailer_instance();
		$this->comment_with_emails( $this->member( 'visitor' ), $question, $question );
		$this->assertSame( array( 'asker@example.com' ), $this->addresses() );
		$this->assertStringContainsString( 'Your question on', $this->sent()[0]['body'] );
	}

	public function test_a_selected_answer_and_a_related_question_notify_the_author_of_the_answer(): void {
		$asker    = $this->member( 'asker' );
		$helper   = $this->member( 'helper' );
		$question = $this->ask( $asker );
		$answer   = $this->answer_with_emails( $helper, $question );
		reset_phpmailer_instance();

		Services::post_service()->select_answer( $asker, $question, $answer->id );
		$this->assertSame( array( 'helper@example.com' ), $this->addresses() );
		$this->assertStringContainsString( 'selected as the best by Asker', $this->sent()[0]['body'] );

		reset_phpmailer_instance();
		$id = Services::post_service()->create_question( $this->member( 'curious' ), 'A related question', 'More.', '', array(), null, null, null, $answer );
		$this->assertSame( array( 'helper@example.com' ), $this->addresses() );
		$this->assertStringContainsString( 'A related question', $this->sent()[0]['body'] );
		$this->assertStringContainsString( URLs::post( $this->post( $id ) ), $this->sent()[0]['body'] );
	}

	public function test_only_authors_get_censored_text(): void {
		Options::update( 'posting', array( 'block_bad_words' => 'darn' ) );
		Options::update( 'emails', array( 'notify_admin_q_post' => true ) );
		$question = $this->question_with_emails( $this->member( 'asker' ), 'A darn question' );

		$this->assertSame( array( 'owner@example.com' ), $this->addresses() );
		$this->assertStringContainsString( 'A darn question', $this->sent()[0]['body'], 'The site owner sees bad words.' );

		reset_phpmailer_instance();
		$this->answer_with_emails( $this->member( 'helper' ), $question, 'A darn answer' );
		$this->assertStringContainsString( 'A **** answer', $this->sent()[0]['body'] );
		$this->assertStringNotContainsString( 'darn answer', $this->sent()[0]['body'] );
	}

	public function test_text_from_users_cannot_bring_in_placeholders(): void {
		$question = $this->question_with_emails( $this->member( 'asker' ) );

		$this->answer_with_emails( $this->member( 'helper' ), $question, 'Do not replace ###SITENAME### here.' );

		$this->assertStringContainsString( 'Do not replace ###SITENAME### here.', $this->sent()[0]['body'] );
	}

	public function test_the_site_owner_hears_about_posts_that_wait_for_approval(): void {
		$author = $this->member( 'writer' );

		Services::post_service()->create_question( $author, 'A queued question', 'Text.', '', array(), null, null, null, null, true );
		$this->assertSame( array( 'owner@example.com' ), $this->addresses(), 'The administration email address is the default.' );
		$this->assertStringContainsString( 'requires your approval', $this->sent()[0]['body'] );
		$this->assertStringContainsString( 'page=easyq-posts&view=pending', $this->sent()[0]['body'] );

		reset_phpmailer_instance();
		Options::update( 'emails', array( 'feedback_email' => 'desk@example.com' ) );
		Services::post_service()->create_question( $author, 'Another queued question', 'Text.', '', array(), null, null, null, null, true );
		$this->assertSame( array( 'desk@example.com' ), $this->addresses() );

		reset_phpmailer_instance();
		Options::update( 'emails', array( 'moderate_notify_admin' => false ) );
		Services::post_service()->create_question( $author, 'A third queued question', 'Text.', '', array(), null, null, null, null, true );
		$this->assertSame( array(), $this->addresses() );
	}

	public function test_flags_notify_at_the_first_and_then_every_few(): void {
		Options::update(
			'emails',
			array(
				'flagging_notify_first' => 2,
				'flagging_notify_every' => 2,
			)
		);
		Options::update( 'spam', array( 'flagging_hide_after' => 100 ) );
		$question = $this->ask( $this->member( 'writer' ) );

		$counts = array();
		for ( $flags = 1; $flags <= 5; $flags++ ) {
			reset_phpmailer_instance();
			Services::votes()->flag( $this->user(), $this->post( $question->id ), $question );
			$counts[ $flags ] = count( $this->sent() );
		}

		$this->assertSame(
			array(
				1 => 0,
				2 => 1,
				3 => 0,
				4 => 1,
				5 => 0,
			),
			$counts
		);
	}

	public function test_a_user_hears_about_being_approved_once(): void {
		$user = $this->member( 'newbie' );

		Services::user_levels()->set_level( new Actor(), (int) $user->user_id, Levels::APPROVED );
		$this->assertSame( array( 'newbie@example.com' ), $this->addresses() );
		$this->assertStringContainsString( 'has been approved', $this->sent()[0]['subject'] );

		reset_phpmailer_instance();
		Services::user_levels()->set_level( new Actor(), (int) $user->user_id, Levels::EXPERT );
		$this->assertSame( array(), $this->addresses(), 'Already approved.' );
	}

	public function test_registration_emails_change_those_of_core(): void {
		Options::update(
			'users',
			array(
				'show_custom_welcome' => true,
				'custom_welcome'      => 'Please read our guide.',
			)
		);
		update_option( 'blogname', '100% Answers' );
		$user = self::factory()->user->create(
			array(
				'user_login' => 'newcomer',
				'user_email' => 'newcomer@example.com',
			)
		);
		reset_phpmailer_instance();

		wp_new_user_notification( $user, null, 'both' );

		$this->assertSame( array( 'owner@example.com', 'newcomer@example.com' ), $this->addresses() );
		[ $owner, $welcome ] = $this->sent();

		$this->assertStringContainsString( 'A new user has registered', $owner['body'] );
		// Core runs the subject through sprintf(), which must leave the percent sign alone.
		$this->assertSame( '100% Answers has a new registered user', $owner['subject'] );
		$this->assertSame( 'Welcome to 100% Answers!', $welcome['subject'] );
		$this->assertStringContainsString( 'Please read our guide.', $welcome['body'] );
		$this->assertStringContainsString( 'Username: newcomer', $welcome['body'] );
		// Core's link to set the password stays.
		$this->assertStringContainsString( 'action=rp', $welcome['body'] );

		reset_phpmailer_instance();
		Options::update( 'emails', array( 'register_notify_admin' => false ) );
		wp_new_user_notification( $user, null, 'both' );
		$this->assertSame( array( 'newcomer@example.com' ), $this->addresses() );
	}

	public function test_emails_are_written_in_the_language_of_the_recipient(): void {
		$add_language                  = static fn (): array => array( 'de_DE' );
		add_filter( 'get_available_languages', $add_language );
		$switcher                      = $GLOBALS['wp_locale_switcher'];
		$GLOBALS['wp_locale_switcher'] = new WP_Locale_Switcher();
		$GLOBALS['wp_locale_switcher']->init();
		$tag_text = static fn ( string $translation ): string => get_locale() . ': ' . $translation;
		add_filter( 'gettext_easyq', $tag_text );

		$author = $this->member( 'asker' );
		update_user_meta( (int) $author->user_id, 'locale', 'de_DE' );
		$question = $this->question_with_emails( $author );
		$this->answer_with_emails( $this->member( 'helper' ), $question );

		remove_filter( 'gettext_easyq', $tag_text );
		$locale_after = get_locale();
		$switched     = is_locale_switched();
		remove_filter( 'get_available_languages', $add_language );
		$GLOBALS['wp_locale_switcher'] = $switcher;

		$this->assertStringStartsWith( 'de_DE: ', $this->sent()[0]['subject'] );
		$this->assertStringContainsString( 'de_DE: ', $this->sent()[0]['body'] );
		$this->assertFalse( $switched, 'The language is restored.' );
		$this->assertNotSame( 'de_DE', $locale_after );
	}

	public function test_emails_can_be_filtered_as_core_emails_can(): void {
		$question = $this->question_with_emails( $this->member( 'asker' ) );

		$change = static function ( array $email ): array {
			$email['subject'] = 'Changed subject';

			return $email;
		};
		add_filter( 'easyq_email_q_answered', $change );
		$this->answer_with_emails( $this->member( 'one' ), $question );
		$this->assertSame( 'Changed subject', $this->sent()[0]['subject'] );
		remove_filter( 'easyq_email_q_answered', $change );

		reset_phpmailer_instance();
		$add = static fn ( array $recipients ): array => array_merge( $recipients, array( new Recipient( 'copy@example.com' ) ) );
		add_filter( 'easyq_email_recipients', $add );
		$this->answer_with_emails( $this->member( 'two' ), $question );
		$this->assertSame( array( 'asker@example.com', 'copy@example.com' ), $this->addresses() );
		remove_filter( 'easyq_email_recipients', $add );

		reset_phpmailer_instance();
		add_filter( 'easyq_send_email', '__return_false' );
		$this->answer_with_emails( $this->member( 'three' ), $question );
		$this->assertSame( array(), $this->addresses() );
	}

	public function test_the_sender_address_is_optional(): void {
		$mailer = new Mailer();
		$list   = array( new Recipient( 'someone@example.com' ) );

		$mailer->send( 'u_approved', $list, static fn (): array => array( '###URL###' => 'https://example.com' ) );
		$this->assertStringNotContainsString( 'from@example.com', $this->sent()[0]['header'] );

		reset_phpmailer_instance();
		Options::update( 'emails', array( 'from_email' => 'from@example.com' ) );
		$mailer->send( 'u_approved', $list, static fn (): array => array( '###URL###' => 'https://example.com' ) );
		$this->assertStringContainsString( 'from@example.com', $this->sent()[0]['header'] );

		reset_phpmailer_instance();
		$mailer->send( 'u_approved', array( new Recipient( 'not an address' ) ), static fn (): array => array() );
		$this->assertSame( array(), $this->addresses() );
	}

	public function test_anonymous_authors_get_emails_at_their_address(): void {
		$question = $this->post( Services::post_service()->create_question( new Actor( null, null, '192.0.2.9' ), 'An anonymous question', 'Text.', '', array(), null, 'guest@example.com', 'Guest' ) );

		$this->answer_with_emails( $this->member( 'helper' ), $question );

		$this->assertSame( array( 'guest@example.com' ), $this->addresses() );
		$mail = $this->sent()[0];
		$this->assertStringStartsWith( 'Your question on', $mail['body'] );
		$this->assertStringContainsString( Unsubscribe::page_url( $question->id ), $this->decoded( $mail['body'] ) );
		$this->assertStringNotContainsString( Routes::url( 'account' ), $mail['body'], 'Without an account there is no link to it.' );
	}

	/**
	 * Returns the text of an email as readers see it.
	 *
	 * @param string $body Text.
	 * @return string
	 */
	private function decoded( string $body ): string {
		return str_replace( "=\n", '', $body );
	}

	public function test_emails_about_a_post_can_be_stopped_with_their_links(): void {
		$author   = $this->member( 'asker' );
		$question = $this->question_with_emails( $author );
		$this->answer_with_emails( $this->member( 'one' ), $question );
		$mail = $this->sent()[0];

		$this->assertStringContainsString( Unsubscribe::page_url( $question->id ), $this->decoded( $mail['body'] ) );
		$this->assertStringContainsString( Routes::url( 'account' ), $mail['body'] );
		$this->assertStringContainsString( 'List-Unsubscribe-Post: List-Unsubscribe=One-Click', $mail['header'] );
		$this->assertStringContainsString( 'List-Unsubscribe:', $mail['header'] );

		$this->assertFalse( Unsubscribe::verify( $question->id, 'wrong' ) );
		$this->assertFalse( Unsubscribe::verify( $question->id + 1, Unsubscribe::token( $question->id ) ) );
		$this->assertTrue( Unsubscribe::verify( $question->id, Unsubscribe::token( $question->id ) ) );

		Unsubscribe::stop( $question );
		reset_phpmailer_instance();
		$this->answer_with_emails( $this->member( 'two' ), $this->post( $question->id ) );
		$this->assertSame( array(), $this->addresses() );
	}

	public function test_the_page_to_stop_emails_changes_nothing_when_opened(): void {
		$question = $this->question_with_emails( $this->member( 'asker' ) );
		Block_Context::router()->set_current( new Route( 'unsubscribe' ) );

		$this->assertStringContainsString( 'This link is not valid.', do_blocks( '<!-- wp:easyq/unsubscribe /-->' ) );

		$_GET['post']  = (string) $question->id;
		$_GET['token'] = 'wrong';
		$this->assertStringContainsString( 'This link is not valid.', do_blocks( '<!-- wp:easyq/unsubscribe /-->' ) );

		$_GET['token'] = Unsubscribe::token( $question->id );
		$html          = do_blocks( '<!-- wp:easyq/unsubscribe /-->' );
		$this->assertStringContainsString( 'name="easyq_form" value="unsubscribe"', $html );
		$this->assertStringContainsString( 'Stop the emails', $html );
		$this->assertSame( '@', $this->post( $question->id )->notify, 'Mail scanners open links, so opening the page changes nothing.' );

		$this->assertTrue( ( new Page_Meta( Block_Context::router() ) )->robots( array() )['noindex'] );
	}
}
