<?php
/**
 * Tests for the email templates.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Admin\Email_Templates_Screen;
use EasyQ\Mail\Templates;
use EasyQ\Services;
use WP_REST_Request;

/**
 * @covers \EasyQ\Mail\Templates
 * @covers \EasyQ\Admin\Email_Templates_Screen
 * @covers \EasyQ\Admin\Email_Templates_List_Table
 * @covers \EasyQ\REST\Email_Templates_Controller
 */
final class Email_Templates_Test extends REST_Test_Case {

	public function set_up(): void {
		parent::set_up();
		reset_phpmailer_instance();
		// The test site runs on localhost, and PHPMailer refuses core's default sender wordpress@localhost.
		add_filter( 'wp_mail_from', static fn ( string $from ): string => str_ends_with( $from, '@localhost' ) ? 'wordpress@example.com' : $from );
	}

	public function tear_down(): void {
		reset_phpmailer_instance();
		unset( $GLOBALS['wp_settings_errors'] );
		parent::tear_down();
	}

	/**
	 * Returns the texts of the settings errors so far, keyed by code.
	 *
	 * @return array<string, string>
	 */
	private function settings_messages(): array {
		return array_column( get_settings_errors( Templates::OPTION ), 'message', 'code' );
	}

	/**
	 * Renders the screen.
	 *
	 * @param array<string, string> $query Query arguments.
	 * @return string
	 */
	private function render_screen( array $query = array() ): string {
		$_GET = $query;
		ob_start();
		( new Email_Templates_Screen() )->render();
		$html = (string) ob_get_clean();
		$_GET = array();

		return $html;
	}

	public function test_every_template_has_a_name_and_its_placeholders_are_known(): void {
		$this->assertSame( array_keys( Templates::defaults() ), array_keys( array_replace( Templates::defaults(), Templates::info() ) ) );
		$this->assertEqualsCanonicalizing( array_keys( Templates::defaults() ), array_keys( Templates::info() ) );

		foreach ( Templates::defaults() as $name => $template ) {
			preg_match_all( '/###[A-Z_]+###/', $template['subject'] . $template['body'], $used );
			$this->assertSame( array(), array_values( array_diff( $used[0], Templates::placeholders( $name ) ) ), $name );
			$this->assertSame( array(), array_values( array_diff( Templates::placeholders( $name ), array_keys( Templates::placeholder_descriptions() ) ) ), $name );
		}
	}

	public function test_an_edited_text_is_used_and_can_go_back_to_the_default(): void {
		$default = Templates::get( 'q_answered' );
		$this->assertFalse( Templates::is_edited( 'q_answered' ) );

		$this->assertTrue( Templates::save( 'q_answered', 'New <b>answer</b> on ###SITENAME###', "First line\r\n\r\nby ###AUTHOR###" ) );
		$this->assertTrue( Templates::is_edited( 'q_answered' ) );
		$this->assertSame(
			array(
				'subject' => 'New answer on ###SITENAME###',
				'body'    => "First line\n\nby ###AUTHOR###",
			),
			Templates::get( 'q_answered' )
		);
		$this->assertSame( $default, Templates::defaults()['q_answered'], 'The default stays.' );
		$this->assertFalse( Templates::is_edited( 'a_selected' ), 'Other templates stay.' );

		$asker    = $this->user();
		$question = $this->post( Services::post_service()->create_question( $asker, 'A question', 'Text.', '', array(), null, '@' ) );
		$this->answer( $this->user(), $question );
		$sent = tests_retrieve_phpmailer_instance()->get_sent();
		$this->assertStringStartsWith( 'New answer on ', $sent->subject );
		$this->assertStringContainsString( 'First line', $sent->body );

		Templates::reset( 'q_answered' );
		$this->assertFalse( Templates::is_edited( 'q_answered' ) );
		$this->assertSame( $default, Templates::get( 'q_answered' ) );
	}

	public function test_a_text_equal_to_the_default_is_not_stored(): void {
		$default = Templates::defaults()['moderate'];

		Templates::save( 'moderate', 'Changed', 'Changed' );
		$this->assertTrue( Templates::is_edited( 'moderate' ) );

		Templates::save( 'moderate', $default['subject'], $default['body'] );
		$this->assertFalse( Templates::is_edited( 'moderate' ) );
	}

	public function test_empty_texts_and_unknown_templates_are_refused(): void {
		Templates::save( 'moderate', 'Kept subject', 'Kept text' );

		$this->assertFalse( Templates::save( 'moderate', '', 'A text' ) );
		$this->assertFalse( Templates::save( 'moderate', 'A subject', '<b></b>' ) );
		$this->assertSame( 'Kept subject', Templates::get( 'moderate' )['subject'] ?? '' );
		$this->assertArrayHasKey( 'empty', $this->settings_messages() );

		$this->assertFalse( Templates::save( 'no_such_template', 'A subject', 'A text' ) );
		$this->assertNull( Templates::get( 'no_such_template' ) );
		$this->assertArrayNotHasKey(
			'no_such_template',
			Templates::sanitize(
				array(
					'no_such_template' => array(
						'subject' => 'A',
						'body'    => 'B',
					),
				)
			)
		);
	}

	public function test_unknown_placeholders_are_saved_with_a_warning(): void {
		Templates::save( 'moderate', 'A subject', 'Hello ###AUTHOR### and ###TYPO###' );

		$this->assertTrue( Templates::is_edited( 'moderate' ) );
		$messages = $this->settings_messages();
		$this->assertCount( 1, get_settings_errors( Templates::OPTION ), 'Core sanitizes twice on the first save; the message appears once.' );
		$this->assertStringContainsString( '###TYPO###', $messages['unknown'] ?? '' );
		$this->assertStringNotContainsString( '###AUTHOR###', $messages['unknown'] ?? '' );
	}

	public function test_edited_texts_go_through_string_translation_plugins(): void {
		$registered = array();
		add_action(
			'wpml_register_single_string',
			static function ( string $context, string $key, string $text ) use ( &$registered ): void {
				$registered[ $key ] = $context . ':' . $text;
			},
			10,
			3
		);
		add_filter( 'wpml_translate_single_string', static fn ( string $text, string $context, string $key ): string => "$context/$key/$text", 10, 3 );

		Templates::save( 'moderate', 'A subject', 'A text' );

		$this->assertSame(
			array(
				'email_moderate_subject' => 'easyq:A subject',
				'email_moderate_body'    => 'easyq:A text',
			),
			$registered
		);
		$this->assertSame( 'easyq/email_moderate_subject/A subject', Templates::get( 'moderate' )['subject'] ?? '' );
		$this->assertSame( Templates::defaults()['flagged'], Templates::get( 'flagged' ), 'Defaults are translated as usual.' );
	}

	public function test_an_edited_welcome_email_keeps_the_link_to_set_the_password(): void {
		Templates::save( 'welcome', 'Hello', 'Welcome without a link.' );
		$user = self::factory()->user->create( array( 'user_email' => 'new@example.com' ) );
		reset_phpmailer_instance();

		wp_new_user_notification( $user, null, 'user' );

		$body = tests_retrieve_phpmailer_instance()->get_sent()->body;
		$this->assertStringContainsString( 'Welcome without a link.', $body );
		$this->assertStringContainsString( 'action=rp', str_replace( "=\n", '', $body ) );
	}

	public function test_the_screen_lists_the_emails_and_edits_one(): void {
		wp_set_current_user( $this->administrator() );
		set_current_screen( 'dashboard' );

		$list = $this->render_screen();
		$this->assertStringContainsString( 'Question answered', $list );
		$this->assertStringContainsString( 'page=easyq-email-templates&#038;template=q_answered', $list );
		$this->assertStringContainsString( 'Default', $list );
		$this->assertStringContainsString( 'label column-label has-row-actions column-primary', $list, 'The name is the primary column.' );

		Templates::save( 'q_answered', 'New answer on ###SITENAME###', 'By ###AUTHOR###' );
		$edit = $this->render_screen( array( 'template' => 'q_answered' ) );

		$this->assertStringContainsString( 'action="options.php"', $edit );
		$this->assertStringContainsString( "name='option_page' value='easyq_email_templates'", $edit );
		$this->assertStringContainsString( 'name="easyq_email_templates[q_answered][subject]"', $edit );
		$this->assertStringContainsString( 'name="easyq_email_templates[q_answered][body]"', $edit );
		$this->assertStringContainsString( '<code>###AUTHOR###</code>', $edit );
		$this->assertStringNotContainsString( '<code>###FLAGS###</code>', $edit, 'Only placeholders of this template.' );
		$this->assertStringContainsString( 'By Alex Example', $edit, 'The preview uses sample values.' );
		$this->assertStringContainsString( 'name="easyq_action" value="test"', $edit );
		$this->assertStringContainsString( 'name="easyq_action" value="reset"', $edit );

		$this->assertStringNotContainsString( 'name="easyq_action" value="reset"', $this->render_screen( array( 'template' => 'flagged' ) ), 'Nothing to reset.' );
	}

	public function test_the_screen_resets_and_sends_test_emails(): void {
		wp_set_current_user(
			$this->administrator()
		);
		$screen = new Email_Templates_Screen();
		Templates::save( 'flagged', 'A subject', 'A post by ###AUTHOR###' );

		$url = $screen->handle(
			array(
				'easyq_action' => 'test',
				'template'     => 'flagged',
			)
		);
		$this->assertStringContainsString( 'message=sent', $url );
		$sent = tests_retrieve_phpmailer_instance()->get_sent();
		$this->assertSame( wp_get_current_user()->user_email, $sent->to[0][0] );
		$this->assertStringContainsString( 'A post by Alex Example', $sent->body );

		$url = $screen->handle(
			array(
				'easyq_action' => 'reset',
				'template'     => 'flagged',
			)
		);
		$this->assertStringContainsString( 'message=reset', $url );
		$this->assertFalse( Templates::is_edited( 'flagged' ) );

		$this->assertSame(
			Email_Templates_Screen::url(),
			$screen->handle(
				array(
					'easyq_action' => 'reset',
					'template'     => 'no_such_template',
				)
			)
		);
	}

	public function test_only_managers_use_the_screen_and_the_settings_form(): void {
		$this->assertSame( 'easyq_manage', apply_filters( 'option_page_capability_' . Templates::OPTION, 'manage_options' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->expectException( \WPDieException::class );
		( new Email_Templates_Screen() )->handle(
			array(
				'easyq_action' => 'reset',
				'template'     => 'flagged',
			)
		);
	}

	public function test_the_setting_is_registered_with_the_settings_api(): void {
		$settings = get_registered_settings();

		$this->assertArrayHasKey( Templates::OPTION, $settings );
		$this->assertSame( Templates::OPTION, $settings[ Templates::OPTION ]['group'] );
		$this->assertArrayHasKey( 'q_answered', $settings[ Templates::OPTION ]['show_in_rest']['schema']['properties'] );

		wp_set_current_user( $this->administrator() );
		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/wp/v2/settings' ) );
		$this->assertArrayHasKey( Templates::OPTION, $this->data( $response ) );
	}

	public function test_the_rest_api_reads_changes_tests_and_resets_templates(): void {
		$this->assertSame( 401, $this->request( 'GET', '/email-templates' )->get_status() );
		$this->login();
		$this->assertSame( 403, $this->request( 'GET', '/email-templates/flagged' )->get_status() );
		$this->assertSame( 403, $this->request( 'POST', '/email-templates/flagged/test' )->get_status() );

		wp_set_current_user( $this->administrator() );
		$list = $this->data( $this->request( 'GET', '/email-templates' ) );
		$this->assertCount( count( Templates::defaults() ), $list );

		$this->assertSame( 404, $this->request( 'GET', '/email-templates/no_such_template' )->get_status() );

		$changed = $this->data(
			$this->request(
				'PUT',
				'/email-templates/flagged',
				array(
					'subject' => 'A subject',
					'body'    => 'A post by ###AUTHOR###',
				)
			)
		);
		$this->assertTrue( $changed['edited'] );
		$this->assertSame( 'A post by Alex Example', $changed['preview']['message'] );
		$this->assertArrayHasKey( '###FLAGS###', $changed['placeholders'] );

		$this->assertSame(
			400,
			$this->request(
				'PUT',
				'/email-templates/flagged',
				array(
					'subject' => ' ',
					'body'    => 'A text',
				)
			)->get_status()
		);

		$test = $this->data( $this->request( 'POST', '/email-templates/flagged/test' ) );
		$this->assertTrue( $test['sent'] );
		$this->assertSame( wp_get_current_user()->user_email, $test['to'] );

		$deleted = $this->data( $this->request( 'DELETE', '/email-templates/flagged' ) );
		$this->assertTrue( $deleted['deleted'] );
		$this->assertSame( 'A subject', $deleted['previous']['subject'] );
		$this->assertFalse( Templates::is_edited( 'flagged' ) );
	}
}
