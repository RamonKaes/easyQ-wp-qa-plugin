<?php
/**
 * Tests for the registration form.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Newsletter\Newsletter;
use EasyQ\Services;
use EasyQ\Settings\Fields;
use EasyQ\Settings\Options;
use EasyQ\Users\Registration;
use EasyQ\Users\Site_Meta;
use EasyQ\Users\User_Field;

/**
 * @covers \EasyQ\Users\Registration
 */
final class Registration_Test extends Test_Case {

	public function tear_down(): void {
		unset( $_POST['easyq_fields'], $_POST['easyq_register'], $_POST['easyq_terms'], $_POST['easyq_newsletter'] );
		remove_all_filters( 'login_message' );
		parent::tear_down();
	}

	public function test_hooks_into_the_registration_of_core(): void {
		$registration = $this->registered_instance();

		$this->assertSame( 10, has_action( 'login_form_register', array( $registration, 'add_message' ) ) );
		$this->assertSame( 10, has_action( 'register_form', array( $registration, 'render' ) ) );
		$this->assertSame( 10, has_filter( 'registration_errors', array( $registration, 'validate' ) ) );
		$this->assertSame( 10, has_action( 'user_register', array( $registration, 'save' ) ) );
	}

	public function test_shows_the_text_above_the_form_only_on_the_registration_screen_and_when_on(): void {
		$registration = new Registration();
		Options::update( 'users', array( 'custom_register' => '<strong>Be nice.</strong><script>alert(1)</script>' ) );

		$registration->add_message();
		$this->assertSame( 10, has_filter( 'login_message', array( $registration, 'message' ) ) );
		$this->assertSame( 'Core', apply_filters( 'login_message', 'Core' ), 'The text needs its switch.' );

		Options::update( 'users', array( 'show_custom_register' => true ) );
		$message = apply_filters( 'login_message', 'Core' );
		$this->assertStringStartsWith( 'Core<div class="notice notice-info message">', $message );
		$this->assertStringContainsString( '<p><strong>Be nice.</strong>', $message );
		$this->assertStringNotContainsString( '<script', $message );

		Options::update( 'users', array( 'custom_register' => '  ' ) );
		$this->assertSame( 'Core', apply_filters( 'login_message', 'Core' ) );
	}

	public function test_asks_for_the_terms_with_a_link_to_the_privacy_policy(): void {
		$this->assertStringNotContainsString( 'easyq_terms', $this->render() );

		Options::update( 'users', array( 'show_register_terms' => true ) );
		$html = $this->render();
		$this->assertStringContainsString( 'name="easyq_terms" id="easyq-register-terms" value="1" required="required"', $html );
		$this->assertStringContainsString( '<label for="easyq-register-terms">I accept the terms of this site.</label>', $html );
		$this->assertStringContainsString( 'name="easyq_register"', $html );

		$page = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		update_option( 'wp_page_for_privacy_policy', $page );
		$this->assertStringContainsString( 'I accept the terms and the <a href="' . esc_url( (string) get_permalink( $page ) ) . '">privacy policy</a> of this site.', $this->render() );

		Options::update( 'users', array( 'register_terms' => 'I accept the <a href="https://example.org/terms">house rules</a>.' ) );
		$this->assertStringContainsString( '>I accept the <a href="https://example.org/terms">house rules</a>.</label>', $this->render() );
	}

	public function test_offers_the_newsletter_unticked_only_when_there_is_one(): void {
		$this->assertStringNotContainsString( 'easyq_newsletter', $this->render() );

		Options::update( 'newsletter', array( 'mailing_enabled' => true ) );
		$html = $this->render();
		$this->assertStringContainsString( '<input type="checkbox" name="easyq_newsletter" id="easyq-register-newsletter" value="1" />', $html );
		$this->assertStringNotContainsString( 'checked', $html );
	}

	public function test_requires_the_terms_and_stores_the_consent(): void {
		Options::update( 'users', array( 'show_register_terms' => true ) );

		$this->assertContains( 'easyq_terms', $this->errors_of( 'alice' ) );

		$_POST['easyq_terms']    = '1';
		$_POST['easyq_register'] = 'invalid';
		$this->assertContains( 'easyq_terms', $this->errors_of( 'alice' ), 'A form without a valid nonce stores no consent, so it is refused.' );

		$_POST['easyq_register'] = wp_create_nonce( 'easyq_register' );
		$before                  = time();
		$user_id                 = register_new_user( 'alice', 'alice@example.org' );
		$this->assertIsInt( $user_id );

		$terms = Registration::accepted_terms( $user_id );
		$this->assertNotNull( $terms );
		$this->assertGreaterThanOrEqual( $before, $terms['time'] );
		$this->assertSame( 'I accept the terms of this site.', $terms['text'] );
		$this->assertIsArray( Site_Meta::get( $user_id, Registration::TERMS_META ) );
	}

	public function test_stores_no_consent_when_the_terms_are_off(): void {
		$_POST['easyq_terms']    = '1';
		$_POST['easyq_register'] = wp_create_nonce( 'easyq_register' );

		$user_id = register_new_user( 'carol', 'carol@example.org' );
		$this->assertIsInt( $user_id );
		$this->assertNull( Registration::accepted_terms( $user_id ) );
	}

	public function test_asks_to_confirm_the_newsletter_when_ticked(): void {
		reset_phpmailer_instance();
		// The test site runs on localhost, and PHPMailer refuses core's default sender wordpress@localhost.
		add_filter( 'wp_mail_from', static fn ( string $from ): string => str_ends_with( $from, '@localhost' ) ? 'wordpress@example.com' : $from );
		$_POST['easyq_register']   = wp_create_nonce( 'easyq_register' );
		$_POST['easyq_newsletter'] = '1';

		$off = register_new_user( 'dave', 'dave@example.org' );
		$this->assertIsInt( $off );
		$this->assertSame( '', Newsletter::state( $off ), 'There is no newsletter.' );

		Options::update( 'newsletter', array( 'mailing_enabled' => true ) );
		$on = register_new_user( 'erin', 'erin@example.org' );
		$this->assertIsInt( $on );
		$this->assertSame( 'pending', Newsletter::state( $on ), 'Double opt-in: the subscription waits for the confirmation.' );
		$subjects = array_column( tests_retrieve_phpmailer_instance()->mock_sent, 'subject' );
		$this->assertContains( '[' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) . '] Confirm the newsletter', $subjects );

		unset( $_POST['easyq_newsletter'] );
		$unticked = register_new_user( 'frank', 'frank@example.org' );
		$this->assertIsInt( $unticked );
		$this->assertSame( '', Newsletter::state( $unticked ) );
	}

	public function test_has_the_registration_settings_of_question2answer(): void {
		$fields = Fields::section( 'users' );

		$this->assertSame( 'bool', $fields['show_custom_register']['type'] );
		$this->assertSame( 'html', $fields['custom_register']['type'] );
		$this->assertSame( 'bool', $fields['show_register_terms']['type'] );
		$this->assertSame( 'html', $fields['register_terms']['type'] );
		$this->assertSame( 'lines', $fields['block_bad_usernames']['type'] );
		$this->assertFalse( Options::get_bool( 'users', 'show_custom_register' ) );
		$this->assertFalse( Options::get_bool( 'users', 'show_register_terms' ) );
	}

	public function test_shows_only_fields_marked_for_registration(): void {
		$field_id = Services::user_fields()->create( 'company', 'Company', User_Field::FLAG_ON_REGISTER, null );
		$this->assertIsInt( $field_id );

		ob_start();
		( new Registration() )->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'easyq_fields[' . $field_id . ']', $html );
		$this->assertStringContainsString( 'Company', $html );
		$this->assertStringNotContainsString( 'location', $html );
	}

	public function test_renders_nothing_without_registration_fields(): void {
		ob_start();
		( new Registration() )->render();

		$this->assertSame( '', ob_get_clean() );
	}

	public function test_stores_submitted_values_of_new_users(): void {
		$field_id = Services::user_fields()->create( 'company', '', User_Field::FLAG_ON_REGISTER, null );
		$this->assertIsInt( $field_id );
		$location = Services::user_fields()->all()[0];

		$_POST['easyq_register'] = wp_create_nonce( 'easyq_register' );
		$_POST['easyq_fields']   = array(
			$field_id     => 'ACME',
			$location->id => 'Not shown on registration',
		);

		$user_id = self::factory()->user->create();
		$field   = Services::user_fields()->find( $field_id );
		$this->assertNotNull( $field );

		$this->assertSame( 'ACME', Services::user_fields()->value( $user_id, $field ) );
		$this->assertSame( '', Services::user_fields()->value( $user_id, $location ) );
	}

	public function test_ignores_submissions_without_a_valid_nonce(): void {
		$field_id = Services::user_fields()->create( 'company', '', User_Field::FLAG_ON_REGISTER, null );
		$this->assertIsInt( $field_id );
		$_POST['easyq_register'] = 'invalid';
		$_POST['easyq_fields']   = array( $field_id => 'ACME' );

		$user_id = self::factory()->user->create();
		$field   = Services::user_fields()->find( $field_id );
		$this->assertNotNull( $field );

		$this->assertSame( '', Services::user_fields()->value( $user_id, $field ) );
	}

	/**
	 * Returns the instance hooked by the plugin.
	 */
	private function registered_instance(): Registration {
		global $wp_filter;

		foreach ( $wp_filter['register_form']->callbacks[10] ?? array() as $callback ) {
			if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof Registration ) {
				return $callback['function'][0];
			}
		}

		$this->fail( 'The registration form is not hooked.' );
	}

	/**
	 * Renders the parts of the registration form.
	 */
	private function render(): string {
		ob_start();
		( new Registration() )->render();

		return (string) ob_get_clean();
	}

	/**
	 * Returns the error codes of a registration.
	 *
	 * @param string $login Username.
	 * @return string[]
	 */
	private function errors_of( string $login ): array {
		$result = register_new_user( $login, $login . '@example.org' );
		$this->assertWPError( $result );

		return array_map( 'strval', $result->get_error_codes() );
	}
}
