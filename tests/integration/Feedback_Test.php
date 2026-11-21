<?php
/**
 * Tests for the feedback form.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Blocks\Block_Context;
use EasyQ\Frontend\Route;
use EasyQ\Settings\Options;

/**
 * @covers \EasyQ\Feedback\Feedback
 * @covers \EasyQ\REST\Feedback_Controller
 */
final class Feedback_Test extends REST_Test_Case {

	public function set_up(): void {
		parent::set_up();
		reset_phpmailer_instance();
		add_filter( 'wp_mail_from', array( $this, 'mail_from' ) );
	}

	public function tear_down(): void {
		remove_filter( 'wp_mail_from', array( $this, 'mail_from' ) );
		parent::tear_down();
	}

	/**
	 * Gives test mails a sender that PHPMailer accepts on localhost.
	 *
	 * @return string
	 */
	public function mail_from(): string {
		return 'wordpress@example.org';
	}

	public function test_sends_feedback_to_the_site_owner_with_a_reply_address(): void {
		Options::update( 'emails', array( 'feedback_email' => 'owner@example.org' ) );
		wp_set_current_user( 0 );

		$response = $this->request(
			'POST',
			'/feedback',
			array(
				'message'  => 'The search could be better.',
				'name'     => 'Alex',
				'email'    => 'alex@example.org',
				'previous' => 'https://example.org/questions/',
			)
		);
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $this->data( $response )['sent'] );

		$mail = tests_retrieve_phpmailer_instance()->get_sent( 0 );
		$this->assertNotFalse( $mail );
		$this->assertSame( 'owner@example.org', $mail->to[0][0] );
		$this->assertStringContainsString( 'Reply-To: alex@example.org', $mail->header );
		$this->assertStringContainsString( 'The search could be better.', $mail->body );
		$this->assertStringContainsString( 'https://example.org/questions/', $mail->body );
		$this->assertContains( 'feedback', $this->event_names() );
	}

	public function test_the_address_in_the_email_is_shortened_as_stored_addresses_are(): void {
		Options::update( 'emails', array( 'feedback_email' => 'owner@example.org' ) );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.77';
		wp_set_current_user( 0 );

		$this->request( 'POST', '/feedback', array( 'message' => 'With the full address.' ) );
		Options::update( 'spam', array( 'ip_anonymize' => true ) );
		$this->request( 'POST', '/feedback', array( 'message' => 'With the shortened address.' ) );
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

		$this->assertStringContainsString( '203.0.113.77', tests_retrieve_phpmailer_instance()->get_sent( 0 )->body );
		$this->assertStringNotContainsString( '203.0.113.77', tests_retrieve_phpmailer_instance()->get_sent( 1 )->body );
		$this->assertStringContainsString( '203.0.113.0', tests_retrieve_phpmailer_instance()->get_sent( 1 )->body );
	}

	public function test_checks_the_fields(): void {
		$empty = $this->request( 'POST', '/feedback', array( 'message' => '  ' ) );
		$this->assertSame( 400, $empty->get_status() );
		$this->assertArrayHasKey( 'message', $empty->get_data()['data']['params'] );

		$email = $this->request(
			'POST',
			'/feedback',
			array(
				'message' => 'Fine text',
				'email'   => 'not an address',
			)
		);
		$this->assertSame( 400, $email->get_status() );
		$this->assertArrayHasKey( 'email', $email->get_data()['data']['params'] );
		$this->assertSame( array(), tests_retrieve_phpmailer_instance()->mock_sent );
	}

	public function test_is_limited_per_hour(): void {
		Options::update(
			'spam',
			array(
				'max_rate_ip_feedback'   => 2,
				'max_rate_user_feedback' => 2,
			)
		);
		wp_set_current_user( 0 );

		$statuses = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$statuses[] = $this->request( 'POST', '/feedback', array( 'message' => "Feedback number $i" ) )->get_status();
		}

		$this->assertSame( array( 200, 200, 429 ), $statuses );
		$this->assertCount( 2, tests_retrieve_phpmailer_instance()->mock_sent );
	}

	public function test_can_be_turned_off(): void {
		Options::update( 'emails', array( 'feedback_enabled' => false ) );

		$this->assertSame( 404, $this->request( 'POST', '/feedback', array( 'message' => 'Hello' ) )->get_status() );
		$this->assertSame( '', do_blocks( '<!-- wp:easyq/feedback-form /-->' ) );
	}

	public function test_the_service_itself_refuses_when_off_and_shortens_nothing(): void {
		$feedback = \EasyQ\Services::feedback();

		$long = $feedback->send( new \EasyQ\Actor( null, null, '192.0.2.3' ), str_repeat( 'a', \EasyQ\Feedback\Feedback::MAX_LENGTH + 1 ) );
		$this->assertInstanceOf( \WP_Error::class, $long );
		$this->assertSame( 'Please shorten the message to ' . \EasyQ\Feedback\Feedback::MAX_LENGTH . ' characters.', $long->get_error_data()['params']['message'] ?? null );

		Options::update( 'emails', array( 'feedback_enabled' => false ) );
		$off = $feedback->send( new \EasyQ\Actor( null, null, '192.0.2.3' ), 'Hello' );
		$this->assertInstanceOf( \WP_Error::class, $off );
		$this->assertSame( 'easyq_off', $off->get_error_code() );
		$this->assertSame( array(), tests_retrieve_phpmailer_instance()->mock_sent );
	}

	public function test_the_form_says_why_it_cannot_be_sent(): void {
		Options::update( 'spam', array( 'block_ips_write' => '192.0.2.9' ) );
		$_SERVER['REMOTE_ADDR'] = '192.0.2.9';
		try {
			$html = do_blocks( '<!-- wp:easyq/feedback-form /-->' );
		} finally {
			$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
		}

		$this->assertStringContainsString( \EasyQ\Blocks\Render\Forms::permission_message( 'ipblock' ), $html );
		$this->assertStringNotContainsString( '<textarea', $html );
	}

	public function test_the_form_fills_in_the_user_and_shows_the_privacy_note(): void {
		$user = get_userdata( (int) $this->user()->user_id );
		wp_set_current_user( $user->ID );
		Block_Context::router()->set_current( new Route( 'feedback' ) );

		$html = do_blocks( '<!-- wp:easyq/feedback-form /-->' );

		$this->assertStringContainsString( 'value="' . esc_attr( $user->user_email ) . '"', $html );
		$this->assertStringContainsString( 'Privacy: Your email address will not be shared', $html );
		$this->assertStringContainsString( 'name="message"', $html );
	}
}
