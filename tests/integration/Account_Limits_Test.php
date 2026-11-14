<?php
/**
 * Tests for the limits of registrations and logins.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Actor;
use EasyQ\Limits\Account_Limits;
use EasyQ\Limits\Limits;
use EasyQ\Services;
use EasyQ\Settings\Options;

/**
 * @covers \EasyQ\Limits\Account_Limits
 */
final class Account_Limits_Test extends Test_Case {

	/**
	 * IP address of the request.
	 *
	 * @var string
	 */
	private string $ip = '203.0.113.9';

	public function set_up(): void {
		parent::set_up();
		add_filter( 'easyq_client_ip', array( $this, 'client_ip' ) );
	}

	/**
	 * Returns the IP address of the request.
	 */
	public function client_ip(): string {
		return $this->ip;
	}

	public function test_hooks_after_the_authentication_of_core(): void {
		$limits = $this->registered_instance();

		$this->assertSame( 10, has_filter( 'registration_errors', array( $limits, 'check_registration' ) ) );
		$this->assertSame( 10, has_action( 'register_new_user', array( $limits, 'count_registration' ) ) );
		$this->assertSame( 10, has_action( 'wp_login_failed', array( $limits, 'count_failed_login' ) ) );
		$this->assertSame( 99, has_filter( 'authenticate', array( $limits, 'check_login' ) ) );
		$this->assertGreaterThan( has_filter( 'authenticate', 'wp_authenticate_cookie' ), 99 );
		$this->assertGreaterThan( has_filter( 'authenticate', 'wp_authenticate_username_password' ), 99 );
	}

	public function test_limits_registrations_per_ip_address_and_counts_only_completed_ones(): void {
		Options::update( 'spam', array( 'max_rate_ip_registers' => 1 ) );

		$this->assertWPError( register_new_user( 'first', '' ), 'A refused registration does not count.' );
		$this->assertIsInt( register_new_user( 'first', 'first@example.org' ) );

		$result = register_new_user( 'second', 'second@example.org' );
		$this->assertWPError( $result );
		$this->assertSame( array( 'easyq_register_limit' ), $result->get_error_codes() );

		$this->ip = '203.0.113.10';
		$this->assertIsInt( register_new_user( 'second', 'second@example.org' ), 'The limit is per address.' );
	}

	public function test_refuses_registrations_from_blocked_ip_addresses(): void {
		Options::update( 'spam', array( 'block_ips_write' => '203.0.113.*' ) );

		$result = register_new_user( 'blocked', 'blocked@example.org' );
		$this->assertWPError( $result );
		$this->assertSame( array( 'easyq_ip_blocked' ), $result->get_error_codes() );
	}

	public function test_limits_failed_logins_per_ip_address_even_with_the_right_password(): void {
		Options::update( 'spam', array( 'max_rate_ip_logins' => 2 ) );
		$user = self::factory()->user->create_and_get(
			array(
				'user_login' => 'grace',
				'user_pass'  => 'right-password',
			)
		);

		$this->assertInstanceOf( \WP_User::class, wp_authenticate( 'grace', 'right-password' ) );
		$this->assertInstanceOf( \WP_User::class, wp_authenticate( 'grace', 'right-password' ) );
		$this->assertInstanceOf( \WP_User::class, wp_authenticate( 'grace', 'right-password' ), 'Successful logins do not count.' );

		$this->assertSame( 'incorrect_password', $this->error_code( wp_authenticate( 'grace', 'wrong' ) ) );
		$this->assertSame( 'invalid_username', $this->error_code( wp_authenticate( 'nobody', 'wrong' ) ) );
		$this->assertSame( Account_Limits::LOGIN_LIMIT_ERROR, $this->error_code( wp_authenticate( 'grace', 'right-password' ) ) );
		$this->assertSame( Account_Limits::LOGIN_LIMIT_ERROR, $this->error_code( wp_authenticate( $user->user_email, 'right-password' ) ) );

		global $wpdb;
		$count = $wpdb->get_var( $wpdb->prepare( 'SELECT count FROM %i WHERE action = %s', $wpdb->prefix . 'easyq_iplimits', Limits::LOGINS ) );
		$this->assertSame( '2', $count, 'Refusals by the limit do not count again.' );

		$this->ip = '203.0.113.10';
		$this->assertInstanceOf( \WP_User::class, wp_authenticate( 'grace', 'right-password' ), 'The limit is per address.' );
	}

	public function test_ignores_empty_login_forms(): void {
		Options::update( 'spam', array( 'max_rate_ip_logins' => 1 ) );
		Services::limits()->increment( Limits::LOGINS, new Actor( null, null, '203.0.113.9' ) );

		$this->assertSame( 'empty_username', $this->error_code( wp_authenticate( '', '' ) ) );
		$this->assertSame( Account_Limits::LOGIN_LIMIT_ERROR, $this->error_code( wp_authenticate( 'someone', '' ) ) );
	}

	/**
	 * Returns the instance hooked by the plugin.
	 */
	private function registered_instance(): Account_Limits {
		global $wp_filter;

		foreach ( $wp_filter['authenticate']->callbacks[99] ?? array() as $callback ) {
			if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof Account_Limits ) {
				return $callback['function'][0];
			}
		}

		$this->fail( 'The login limit is not hooked.' );
	}

	/**
	 * Returns the error code of a login result.
	 *
	 * @param mixed $result Result of wp_authenticate().
	 */
	private function error_code( mixed $result ): string {
		$this->assertWPError( $result );

		return (string) $result->get_error_code();
	}
}
