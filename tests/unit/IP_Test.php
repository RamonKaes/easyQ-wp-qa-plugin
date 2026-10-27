<?php
/**
 * Tests for the IP address helpers.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Unit;

use EasyQ\Util\IP;
use PHPUnit\Framework\TestCase;

/**
 * @covers \EasyQ\Util\IP
 */
final class IP_Test extends TestCase {

	/**
	 * @dataProvider provide_rules
	 *
	 * @param string $ip       IP address.
	 * @param string $rule     Block rule.
	 * @param bool   $expected Whether the rule matches.
	 */
	public function test_matches_rules( string $ip, string $rule, bool $expected ): void {
		$this->assertSame( $expected, IP::matches( $ip, $rule ) );
	}

	/**
	 * Provides addresses and rules.
	 *
	 * @return array<string, array{string, string, bool}>
	 */
	public static function provide_rules(): array {
		return array(
			'exact IPv4'             => array( '1.2.3.4', '1.2.3.4', true ),
			'other IPv4'             => array( '1.2.3.5', '1.2.3.4', false ),
			'IPv4 wildcard'          => array( '1.2.3.4', '1.2.*.*', true ),
			'IPv4 wildcard mismatch' => array( '1.3.3.4', '1.2.*.*', false ),
			'IPv4 range'             => array( '10.0.0.5', '10.0.0.1-10.0.0.9', true ),
			'outside IPv4 range'     => array( '10.0.1.5', '10.0.0.1-10.0.0.9', false ),
			'IPv6 short and long'    => array( '2001:db8::1', '2001:0db8:0000:0000:0000:0000:0000:0001', true ),
			'IPv6 wildcard'          => array( '2001:db8::1', '2001:db8:*:*:*:*:*:*', true ),
			'IPv6 wildcard mismatch' => array( '2001:db9::1', '2001:db8:*:*:*:*:*:*', false ),
			'IPv6 range'             => array( '2001:db8::5', '2001:db8::1-2001:db8::9', true ),
			'mixed families'         => array( '1.2.3.4', '2001:db8::1', false ),
			'invalid address'        => array( 'nope', '1.2.3.4', false ),
			'invalid range'          => array( '1.2.3.4', 'a-b', false ),
		);
	}

	public function test_checks_block_lists(): void {
		$this->assertTrue( IP::is_blocked( '1.2.3.4', "5.6.7.8\n1.2.3.*" ) );
		$this->assertFalse( IP::is_blocked( '9.9.9.9', "5.6.7.8\n1.2.3.*" ) );
		$this->assertFalse( IP::is_blocked( null, '*' ) );
		$this->assertFalse( IP::is_blocked( '1.2.3.4', '' ) );
	}

	public function test_splits_rules_and_joins_ranges(): void {
		$this->assertSame( array( '1.1.1.1-1.1.1.9', '2.2.2.2' ), IP::explode_rules( '1.1.1.1 - 1.1.1.9, 2.2.2.2' ) );
	}

	public function test_expands_ipv6_addresses(): void {
		$this->assertSame( '0000:0000:0000:0000:0000:0000:ffff:0001', IP::expand_ipv6( '::ffff:1' ) );
		$this->assertSame( '2001:0db8:*:*:*:*:*:*', IP::expand_ipv6( '2001:db8:*:*:*:*:*:*' ) );
	}

	public function test_converts_to_and_from_binary(): void {
		$this->assertSame( '192.168.0.1', IP::from_binary( IP::to_binary( '192.168.0.1' ) ) );
		$this->assertSame( '2001:db8::1', IP::from_binary( IP::to_binary( '2001:db8::1' ) ) );
		$this->assertSame( 4, strlen( (string) IP::to_binary( '192.168.0.1' ) ) );
		$this->assertNull( IP::to_binary( 'not an ip' ) );
		$this->assertNull( IP::to_binary( null ) );
		$this->assertNull( IP::from_binary( '' ) );
	}
}
