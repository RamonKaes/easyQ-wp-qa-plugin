<?php
/**
 * Tests for the write activity tracking.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Actor;
use EasyQ\Events\Activity_Tracker;
use EasyQ\Events\Events;

/**
 * @covers \EasyQ\Events\Activity_Tracker
 */
final class Activity_Tracker_Test extends Test_Case {

	public function test_records_the_last_write_of_users(): void {
		$actor = $this->user();

		Events::dispatch( 'a_vote_up', array(), $actor );

		$this->assertEqualsWithDelta( time(), (int) get_user_meta( (int) $actor->user_id, Activity_Tracker::LAST_WRITE_META, true ), 5 );
	}

	public function test_ignores_events_that_are_not_writes(): void {
		$actor = $this->user();

		Events::dispatch( 'search', array(), $actor );

		$this->assertSame( '', get_user_meta( (int) $actor->user_id, Activity_Tracker::LAST_WRITE_META, true ) );
	}

	public function test_records_the_last_write_of_anonymous_visitors(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'easyq_cookies';
		$wpdb->insert(
			$table,
			array(
				'id'      => 123456789,
				'created' => '2026-01-01 00:00:00',
			)
		);

		Events::dispatch( 'q_post', array(), new Actor( null, 123456789 ) );

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT written, written_ip FROM %i WHERE id = %d', $table, 123456789 ) );
		$this->assertNotNull( $row->written );
		$this->assertNull( $row->written_ip );
	}
}
