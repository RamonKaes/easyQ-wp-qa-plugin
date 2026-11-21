<?php
/**
 * Tests for repeating queries of derived data after a deadlock.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Database\DB;

require_once __DIR__ . '/seams/class-deadlocking-wpdb.php';

/**
 * MySQL breaks a lock cycle by rolling one statement back; the counts and the hotness
 * stay right only if that statement runs again.
 *
 * @covers \EasyQ\Database\DB
 */
final class Deadlock_Retry_Test extends Test_Case {

	/**
	 * The database object of WordPress.
	 *
	 * @var \wpdb
	 */
	private \wpdb $real;

	public function set_up(): void {
		parent::set_up();
		$this->real = $GLOBALS['wpdb'];
	}

	public function tear_down(): void {
		$GLOBALS['wpdb'] = $this->real; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restores the database object of WordPress.
		parent::tear_down();
	}

	/**
	 * Replaces the database object with one whose first queries fail in a deadlock.
	 *
	 * @param int  $deadlocks   Number of queries that fail.
	 * @param bool $other_error Whether the query then fails with another error.
	 * @return object{calls: int, suppressed: bool[]}
	 */
	private function deadlocking( int $deadlocks, bool $other_error = false ): object {
		$log = (object) array(
			'calls'      => 0,
			'suppressed' => array(),
		);

		$fake = ( new \ReflectionClass( \EasyQ\Tests\Integration\Seams\Deadlocking_WPDB::class ) )->newInstanceWithoutConstructor();
		$fake->setup( $deadlocks, $log, $other_error );
		$GLOBALS['wpdb'] = $fake; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Replaced for this test only.

		return $log;
	}

	public function test_a_query_broken_off_in_a_deadlock_runs_again(): void {
		$log = $this->deadlocking( 1 );

		DB::query_derived( static fn (): int|bool => DB::wpdb()->query( 'UPDATE t SET x = 1' ) );

		$this->assertSame( 2, $log->calls );
		$this->assertSame( array( true, true ), $log->suppressed, 'The error of an attempt that is repeated is not logged.' );
	}

	public function test_it_gives_up_after_three_attempts_and_logs_the_last_error(): void {
		$log = $this->deadlocking( 5 );

		DB::query_derived( static fn (): int|bool => DB::wpdb()->query( 'UPDATE t SET x = 1' ) );

		$this->assertSame( 3, $log->calls );
		$this->assertSame( array( true, true, false ), $log->suppressed );
	}

	public function test_other_errors_are_not_repeated(): void {
		$log = $this->deadlocking( 0, true );

		DB::query_derived( static fn (): int|bool => DB::wpdb()->query( 'UPDATE t SET x = 1' ) );

		$this->assertSame( 1, $log->calls );
	}
}
