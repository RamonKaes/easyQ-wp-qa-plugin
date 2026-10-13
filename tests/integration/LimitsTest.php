<?php
/**
 * Tests for rate limits.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Actor;
use EasyQ\Limits\Limits;
use EasyQ\Services;
use EasyQ\Settings\Options;

/**
 * @covers \EasyQ\Limits\Limits
 */
final class LimitsTest extends TestCase {

	public function test_posting_counts_towards_the_limits(): void {
		$author = $this->user();
		$limits = Services::limits();

		$this->assertSame( 10, $limits->remaining( Limits::QUESTIONS, $author ) );

		$this->ask( $author );

		$this->assertSame( 9, $limits->remaining( Limits::QUESTIONS, $author ) );
	}

	public function test_the_lower_of_user_and_ip_limit_applies(): void {
		Options::update(
			'spam',
			array(
				'max_rate_user_as' => 5,
				'max_rate_ip_as'   => 2,
			)
		);
		$limits = Services::limits();
		$first  = new Actor( self::factory()->user->create(), null, '198.51.100.7' );
		$second = new Actor( self::factory()->user->create(), null, '198.51.100.7' );

		$limits->increment( Limits::ANSWERS, $first );
		$limits->increment( Limits::ANSWERS, $first );

		$this->assertSame( 0, $limits->remaining( Limits::ANSWERS, $second ) );
		$this->assertSame( 2, $limits->remaining( Limits::ANSWERS, new Actor( (int) $second->user_id, null, '198.51.100.8' ) ) );
	}

	public function test_counts_restart_every_hour(): void {
		global $wpdb;

		$actor  = $this->user();
		$limits = Services::limits();
		$limits->increment( Limits::VOTES, $actor );

		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET period = period - 1', $wpdb->prefix . 'easyq_userlimits' ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET period = period - 1', $wpdb->prefix . 'easyq_iplimits' ) );

		$this->assertSame( 300, $limits->remaining( Limits::VOTES, $actor ) );

		$limits->increment( Limits::VOTES, $actor );
		$this->assertSame( 299, $limits->remaining( Limits::VOTES, $actor ) );
	}

	public function test_delayed_events_are_not_counted_again(): void {
		$actor  = $this->user();
		$limits = Services::limits();

		$limits->count_event( 'q_post', array( 'delayed' => '2026-01-01 00:00:00' ), $actor );
		$limits->count_event( 'unknown_event', array(), $actor );

		$this->assertSame( 10, $limits->remaining( Limits::QUESTIONS, $actor ) );
	}

	public function test_blocks_ip_addresses(): void {
		Options::update( 'spam', array( 'block_ips_write' => '203.0.113.*' ) );

		$this->assertTrue( Services::limits()->is_ip_blocked( new Actor( null, null, '203.0.113.50' ) ) );
		$this->assertFalse( Services::limits()->is_ip_blocked( new Actor( null, null, '198.51.100.1' ) ) );
	}

	public function test_anonymized_addresses_share_a_limit(): void {
		Options::update(
			'spam',
			array(
				'ip_anonymize'      => true,
				'max_rate_ip_flags' => 3,
			)
		);
		$limits = Services::limits();

		$limits->increment( Limits::FLAGS, new Actor( null, null, '192.0.2.10' ) );

		$this->assertSame( 2, $limits->remaining( Limits::FLAGS, new Actor( null, null, '192.0.2.99' ) ) );
		$this->assertSame( 3, $limits->remaining( Limits::FLAGS, new Actor( null, null, '198.51.100.99' ) ) );
	}
}
