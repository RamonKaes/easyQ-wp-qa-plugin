<?php
/**
 * Tests for the site-wide counts.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Stats\Counts;

/**
 * @covers \EasyQ\Stats\Counts
 */
final class Counts_Test extends Test_Case {

	public function test_caches_counts_until_invalidated(): void {
		$this->assertSame( 0, Counts::get( Counts::QUESTIONS ) );

		// Insert directly, bypassing the services that invalidate the count.
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'easyq_posts',
			array(
				'type'    => 'Q',
				'created' => current_time( 'mysql', true ),
			)
		);

		$this->assertSame( 0, Counts::get( Counts::QUESTIONS ) );

		Counts::invalidate( Counts::QUESTIONS );

		$this->assertSame( 1, Counts::get( Counts::QUESTIONS ) );
	}

	public function test_invalidating_without_names_clears_all_counts(): void {
		Counts::get( Counts::QUESTIONS );
		Counts::get( Counts::ANSWERS );

		Counts::invalidate();

		$this->assertSame( array(), get_option( Counts::OPTION ) );
	}

	public function test_unknown_counts_are_zero(): void {
		$this->assertSame( 0, Counts::calculate( 'unknown' ) );
	}
}
