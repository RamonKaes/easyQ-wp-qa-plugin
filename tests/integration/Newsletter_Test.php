<?php
/**
 * Tests for the newsletter.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Newsletter\Newsletter;
use EasyQ\Privacy\Privacy;
use EasyQ\Services;
use EasyQ\Settings\Options;
use EasyQ\Users\Site_Meta;
use EasyQ\Users\User_Status;

/**
 * @covers \EasyQ\Newsletter\Newsletter
 * @covers \EasyQ\REST\Newsletter_Controller
 */
final class Newsletter_Test extends REST_Test_Case {

	public function set_up(): void {
		parent::set_up();
		reset_phpmailer_instance();
		add_filter( 'wp_mail_from', array( $this, 'mail_from' ) );
		Options::update(
			'newsletter',
			array(
				'mailing_enabled' => true,
				'mailing_subject' => 'News of the month',
				'mailing_body'    => 'Here is what happened.',
			)
		);
	}

	public function tear_down(): void {
		remove_filter( 'wp_mail_from', array( $this, 'mail_from' ) );
		Newsletter::cancel();
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

	/**
	 * Subscribes a user through the account and returns the key from the email.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	private function subscribe( int $user_id ): string {
		wp_set_current_user( $user_id );
		$this->assertSame( 200, $this->request( 'POST', '/users/me', array( 'newsletter' => true ) )->get_status() );
		$mails = tests_retrieve_phpmailer_instance()->mock_sent;
		$this->assertMatchesRegularExpression( '/key=([A-Za-z0-9]+)/', (string) end( $mails )['body'] );
		preg_match( '/key=([A-Za-z0-9]+)/', (string) end( $mails )['body'], $match );

		return $match[1];
	}

	public function test_subscribing_needs_the_confirmation_by_email(): void {
		$user = (int) $this->user()->user_id;
		$key  = $this->subscribe( $user );

		$this->assertSame( 'pending', Newsletter::state( $user ) );
		$this->assertSame( 'pending', $this->data( $this->request( 'GET', '/users/me' ) )['newsletter'] );
		$this->assertStringNotContainsString( $key, (string) wp_json_encode( Site_Meta::get( $user, Newsletter::KEY_META ) ), 'Only the hash is stored.' );

		wp_set_current_user( 0 );
		$wrong = array(
			'user' => $user,
			'key'  => 'wrong',
		);
		$this->assertSame( 403, $this->request( 'POST', '/newsletter/confirm', $wrong )->get_status() );
		$this->assertSame(
			200,
			$this->request(
				'POST',
				'/newsletter/confirm',
				array(
					'user' => $user,
					'key'  => $key,
				)
			)->get_status()
		);
		$this->assertSame( 'confirmed', Newsletter::state( $user ) );
		$this->assertIsNumeric( Site_Meta::get( $user, Newsletter::CONSENT_META ) );

		// The key is used up.
		$this->assertSame(
			403,
			$this->request(
				'POST',
				'/newsletter/confirm',
				array(
					'user' => $user,
					'key'  => $key,
				)
			)->get_status()
		);
	}

	public function test_turning_it_off_and_on_sends_no_flood_of_emails(): void {
		$user = (int) $this->user()->user_id;
		$this->subscribe( $user );
		$this->request( 'POST', '/users/me', array( 'newsletter' => false ) );

		$again = $this->request( 'POST', '/users/me', array( 'newsletter' => true ) );
		$this->assertSame( 429, $again->get_status() );
		$this->assertCount( 1, tests_retrieve_phpmailer_instance()->mock_sent );

		Site_Meta::update( $user, Newsletter::SENT_META, time() - Newsletter::CONFIRMATION_PAUSE - 1 );
		$this->subscribe( $user );
		$this->assertCount( 2, tests_retrieve_phpmailer_instance()->mock_sent );
	}

	public function test_confirmation_keys_expire(): void {
		$user = (int) $this->user()->user_id;
		$key  = $this->subscribe( $user );
		$meta = Site_Meta::get( $user, Newsletter::KEY_META );
		$this->assertIsArray( $meta );
		Site_Meta::update( $user, Newsletter::KEY_META, array( 'time' => time() - 2 * DAY_IN_SECONDS ) + $meta );

		$this->assertInstanceOf( \WP_Error::class, Services::newsletter()->confirm( $user, $key ) );
	}

	public function test_sends_in_batches_to_confirmed_users_only(): void {
		Options::update( 'newsletter', array( 'mailing_per_minute' => 2 ) );
		$confirmed = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$user = (int) $this->user()->user_id;
			$this->assertTrue( Services::newsletter()->confirm( $user, $this->subscribe( $user ) ) );
			$confirmed[] = get_userdata( $user )->user_email;
		}
		$pending = (int) $this->user()->user_id;
		$this->subscribe( $pending );
		$blocked = (int) $this->user()->user_id;
		Services::newsletter()->confirm( $blocked, $this->subscribe( $blocked ) );
		User_Status::block( \EasyQ\Actor::system(), $blocked );
		reset_phpmailer_instance();

		wp_set_current_user( $this->administrator() );
		$this->assertSame( 200, $this->request( 'POST', '/newsletter/start' )->get_status() );
		$this->assertNotFalse( wp_next_scheduled( Newsletter::CRON_HOOK ) );
		$this->assertSame( 3, $this->data( $this->request( 'GET', '/newsletter' ) )['total'] );

		$newsletter = Services::newsletter();
		$this->assertSame( 2, $newsletter->send_batch() );
		$this->assertSame( 1, $newsletter->send_batch() );
		$this->assertSame( 0, $newsletter->send_batch() );
		$this->assertFalse( Newsletter::progress()['running'] );
		$this->assertFalse( wp_next_scheduled( Newsletter::CRON_HOOK ) );

		$sent = tests_retrieve_phpmailer_instance()->mock_sent;
		$this->assertEqualsCanonicalizing( $confirmed, array_map( static fn ( array $mail ): string => $mail['to'][0][0], $sent ) );
		$this->assertSame( 'News of the month', $sent[0]['subject'] );
		$this->assertStringContainsString( 'List-Unsubscribe-Post: List-Unsubscribe=One-Click', $sent[0]['header'] );
		$this->assertStringContainsString( 'newsletter=stop', $sent[0]['body'] );
	}

	public function test_the_link_in_the_newsletter_stops_it(): void {
		$user = (int) $this->user()->user_id;
		Services::newsletter()->confirm( $user, $this->subscribe( $user ) );
		wp_set_current_user( 0 );

		$bad = $this->request(
			'POST',
			'/unsubscribe',
			array(
				'user'  => $user,
				'token' => 'wrong',
			)
		);
		$this->assertSame( 403, $bad->get_status() );

		$stopped = $this->data(
			$this->request(
				'POST',
				'/unsubscribe',
				array(
					'user'  => $user,
					'token' => Newsletter::token( $user ),
				)
			)
		);
		$this->assertFalse( $stopped['newsletter'] );
		$this->assertSame( '', Newsletter::state( $user ) );
	}

	public function test_only_managers_send_and_nothing_goes_out_while_off(): void {
		wp_set_current_user( (int) $this->user()->user_id );
		$this->assertSame( 403, $this->request( 'POST', '/newsletter/start' )->get_status() );

		wp_set_current_user( $this->administrator() );
		Options::update( 'newsletter', array( 'mailing_subject' => '' ) );
		$this->assertSame( 400, $this->request( 'POST', '/newsletter/start' )->get_status() );

		Options::update( 'newsletter', array( 'mailing_enabled' => false ) );
		$this->assertSame( 400, $this->request( 'POST', '/newsletter/test' )->get_status() );
		$this->assertSame( 404, $this->request( 'POST', '/users/me', array( 'newsletter' => true ) )->get_status() );
		$this->assertSame( array(), tests_retrieve_phpmailer_instance()->mock_sent );
	}

	public function test_privacy_tools_and_uninstall(): void {
		$user = (int) $this->user()->user_id;
		Services::newsletter()->confirm( $user, $this->subscribe( $user ) );
		$email = get_userdata( $user )->user_email;

		$this->assertStringContainsString( 'Confirmed', (string) wp_json_encode( Privacy::export_newsletter( $email )['data'] ) );
		$this->assertTrue( Privacy::erase_newsletter( $email )['items_removed'] );
		$this->assertSame( array(), Privacy::export_newsletter( $email )['data'] );

		wp_set_current_user( $this->administrator() );
		$this->assertSame( '', (string) Site_Meta::get( $user, Newsletter::SENT_META ), 'Erasing forgets the time of the last email.' );
		Services::newsletter()->confirm( $user, $this->subscribe( $user ) );
		wp_set_current_user( $this->administrator() );
		$this->request( 'POST', '/newsletter/start' );
		\EasyQ\Database\Installer::deactivate();
		$this->assertFalse( wp_next_scheduled( Newsletter::CRON_HOOK ) );
	}
}
