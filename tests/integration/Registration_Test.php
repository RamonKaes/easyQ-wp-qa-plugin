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
		unset( $_POST['easyq_fields'], $_POST['easyq_register'], $_POST['easyq_terms'], $_POST['easyq_newsletter'], $_POST['stage'] );
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

	/**
	 * @group ms-required
	 */
	public function test_the_sign_up_of_a_network_asks_and_keeps_the_input_until_the_account_is_activated(): void {
		global $wpdb;
		reset_phpmailer_instance();
		$field = Services::user_fields()->create( 'company', 'Company', User_Field::FLAG_ON_REGISTER, null );
		$this->assertIsInt( $field );
		Options::update( 'users', array( 'show_register_terms' => true ) );
		Options::update(
			'newsletter',
			array(
				'mailing_enabled' => true,
				'mailing_subject' => 'News',
				'mailing_body'    => 'Some news.',
			)
		);

		// The text above the form, as on the registration form of a single site.
		Options::update(
			'users',
			array(
				'show_custom_register' => true,
				'custom_register'      => 'Welcome to the questions.',
			)
		);
		ob_start();
		do_action( 'before_signup_form' );
		$this->assertStringContainsString( 'Welcome to the questions.', (string) ob_get_clean() );

		// The fields appear in `wp-signup.php`, with the error of the terms next to them.
		$errors = new \WP_Error( 'easyq_terms', 'Please accept the terms.' );
		ob_start();
		do_action( 'signup_extra_fields', $errors );
		$form = (string) ob_get_clean();
		$this->assertStringContainsString( 'name="easyq_fields[' . $field . ']"', $form );
		$this->assertStringContainsString( 'name="easyq_terms"', $form );
		$this->assertStringContainsString( 'name="easyq_newsletter"', $form );
		$this->assertStringContainsString( 'Please accept the terms.', $form );

		// Refused without the terms, accepted with them.
		$_POST = array(
			'stage'          => 'validate-user-signup',
			'easyq_register' => wp_create_nonce( 'easyq_register' ),
		);
		$this->assertContains( 'easyq_terms', wpmu_validate_user_signup( 'signupuser', 'signup@example.org' )['errors']->get_error_codes() );
		$_POST += array(
			'easyq_terms'      => '1',
			'easyq_newsletter' => '1',
			'easyq_fields'     => array( $field => 'Lovelace Ltd' ),
		);
		$this->assertNotContains( 'easyq_terms', wpmu_validate_user_signup( 'signupuser', 'signup@example.org' )['errors']->get_error_codes() );

		// Kept in the sign-up, saved when the account is activated. The confirmation of the
		// newsletter needs a sender PHPMailer accepts on localhost; a failed email asks nothing (F-82).
		add_filter( 'wp_mail_from', static fn (): string => 'wordpress@example.org' );
		wpmu_signup_user( 'signupuser', 'signup@example.org', apply_filters( 'add_signup_meta', array() ) );
		$_POST = array();
		$key   = (string) $wpdb->get_var( $wpdb->prepare( "SELECT activation_key FROM {$wpdb->signups} WHERE user_login = %s", 'signupuser' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The key core mails, in a test.
		$user  = wpmu_activate_signup( $key );
		$this->assertIsArray( $user );
		$user_id = (int) $user['user_id'];

		$this->assertSame( 'Lovelace Ltd', Services::user_fields()->value( $user_id, Services::user_fields()->find( $field ) ) );
		$this->assertIsArray( Site_Meta::get( $user_id, Registration::TERMS_META ) );
		$this->assertNotSame( Newsletter::state( $user_id ), Newsletter::state( (int) $this->user()->user_id ), 'The newsletter was asked for.' );
	}

	public function test_the_form_keeps_what_was_entered_after_core_refused_it(): void {
		$field = Services::user_fields()->create( 'company', 'Company', User_Field::FLAG_ON_REGISTER, null );
		$this->assertIsInt( $field );
		Options::update( 'users', array( 'show_register_terms' => true ) );
		Options::update( 'newsletter', array( 'mailing_enabled' => true ) );
		// As wp-login.php shows the form again after `registration_errors`.
		$_POST = wp_slash(
			array(
				'easyq_fields'     => array( $field => 'Ada & Co <ltd>' ),
				'easyq_newsletter' => '1',
				'easyq_terms'      => '1',
			)
		);

		$html  = $this->render();
		$_POST = array();

		$this->assertStringContainsString( 'value="Ada &amp; Co &lt;ltd&gt;"', $html );
		$this->assertMatchesRegularExpression( '/id="easyq-register-newsletter" value="1" checked=\'checked\'/', $html );
		$this->assertMatchesRegularExpression( '/id="easyq-register-terms" value="1" required="required" checked=\'checked\'/', $html );
		$this->assertStringNotContainsString( "checked='checked'", $this->render() );
	}

	public function test_asks_for_the_terms_with_a_link_to_the_privacy_policy(): void {
		$this->assertStringNotContainsString( 'easyq_terms', $this->render() );

		Options::update( 'users', array( 'show_register_terms' => true ) );
		$html = $this->render();
		$this->assertStringContainsString( 'name="easyq_terms" id="easyq-register-terms" value="1" required="required"', $html );
		$this->assertStringContainsString( '<label for="easyq-register-terms">I accept the terms of this site. ' . wp_required_field_indicator() . '</label>', $html );
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
		$this->assertStringContainsString( '>I accept the <a href="https://example.org/terms">house rules</a>. ' . wp_required_field_indicator() . '</label>', $this->render() );
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

	public function test_registration_stores_each_field_as_its_type_takes_it(): void {
		$link = Services::user_fields()->create( 'homepage', '', User_Field::FLAG_ON_REGISTER | User_Field::FLAG_LINK, null );
		$text = Services::user_fields()->create( 'about', '', User_Field::FLAG_ON_REGISTER | User_Field::FLAG_MULTI_LINE, null );
		$this->assertIsInt( $link );
		$this->assertIsInt( $text );

		// The same rule as the profile: a link keeps its percent encoding, as `esc_url_raw()` keeps it.
		$_POST['easyq_register'] = wp_create_nonce( 'easyq_register' );
		$_POST['easyq_fields']   = array(
			$link => 'https://example.org/K%C3%B6ln?a=1&b=2',
			$text => "Line one\nLine two <b>bold</b>",
		);
		$user_id                 = self::factory()->user->create();

		$this->assertSame( 'https://example.org/K%C3%B6ln?a=1&b=2', Services::user_fields()->value( $user_id, Services::user_fields()->find( $link ) ?? $this->fail() ) );
		$this->assertSame( "Line one\nLine two bold", Services::user_fields()->value( $user_id, Services::user_fields()->find( $text ) ?? $this->fail() ) );
	}

	public function test_registration_refuses_a_value_longer_than_its_field(): void {
		$field = Services::user_fields()->create( 'about', 'About', User_Field::FLAG_ON_REGISTER | User_Field::FLAG_MULTI_LINE, null );
		$this->assertIsInt( $field );
		$_POST['easyq_register'] = wp_create_nonce( 'easyq_register' );
		$_POST['easyq_fields']   = array( $field => str_repeat( 'x', \EasyQ\Users\User_Fields::MAX_VALUE_LENGTH + 1 ) );

		$this->assertContains( 'easyq_field_' . $field, $this->errors_of( 'longtext' ) );
		$this->assertStringContainsString( 'maxlength="' . \EasyQ\Users\User_Fields::MAX_VALUE_LENGTH . '"', $this->render() );
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

	public function test_only_the_sign_up_form_of_the_network_asks_for_the_terms(): void {
		Options::update( 'users', array( 'show_register_terms' => true ) );
		$check = static fn (): array => ( new Registration() )->validate_signup( array( 'errors' => new \WP_Error() ) )['errors']->get_error_codes();

		// A network administrator adding a user sends no stage of `wp-signup.php`.
		$this->assertSame( array(), $check() );

		$_POST['stage'] = 'validate-user-signup';
		$this->assertContains( 'easyq_terms', $check() );
	}

	public function test_the_sign_up_of_a_network_keeps_the_fields_sanitized_until_the_activation(): void {
		$field = Services::user_fields()->create( 'company', 'Company', User_Field::FLAG_ON_REGISTER, null );
		$this->assertIsInt( $field );
		$_POST['easyq_register'] = wp_create_nonce( 'easyq_register' );
		$_POST['easyq_fields']   = array( $field => 'Lovelace <script>alert(1)</script>Ltd' );

		// The table of sign-ups holds the input until the account is activated: sanitized as it arrives.
		$meta = ( new Registration() )->add_signup_meta( array() );

		$this->assertSame( 'Lovelace Ltd', $meta['easyq_registration']['fields'][ $field ] ?? null );
	}
}
