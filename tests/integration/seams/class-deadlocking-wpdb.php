<?php
/**
 * A database object that fails like the victim of a deadlock, for Deadlock_Retry_Test.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration\Seams;

/**
 * A database object whose first queries fail as InnoDB fails the victim of a deadlock.
 */
final class Deadlocking_WPDB extends \wpdb {

	/**
	 * Queries left to fail.
	 *
	 * @var int
	 */
	private int $deadlocks = 0;

	/**
	 * What the test reads.
	 *
	 * @var object{calls: int, suppressed: bool[]}
	 */
	private object $log;

	/**
	 * Whether the query fails with another error once the deadlocks are over.
	 *
	 * @var bool
	 */
	private bool $other_error = false;

	/**
	 * Whether errors are suppressed now.
	 *
	 * @var bool
	 */
	private bool $quiet = false;

	/**
	 * Sets the queries to fail and the log.
	 *
	 * @param int    $deadlocks   Queries to fail.
	 * @param object $log Log with `calls` and `suppressed`.
	 * @param bool   $other_error Whether to fail with another error after the deadlocks.
	 */
	public function setup( int $deadlocks, object $log, bool $other_error ): void {
		$this->deadlocks   = $deadlocks;
		$this->log         = $log;
		$this->other_error = $other_error;
	}

	/**
	 * Records whether errors are suppressed.
	 *
	 * @param bool $suppress Whether to suppress.
	 * @return bool The previous value.
	 */
	public function suppress_errors( $suppress = true ) {
		$previous    = $this->quiet;
		$this->quiet = (bool) $suppress;

		return $previous;
	}

	/**
	 * Fails while deadlocks are left, then fails with another error or succeeds.
	 *
	 * @param string $query Query.
	 * @return int|bool
	 */
	public function query( $query ) {
		++$this->log->calls;
		$this->log->suppressed[] = $this->quiet;

		if ( $this->deadlocks > 0 ) {
			--$this->deadlocks;
			$this->last_error = 'Deadlock found when trying to get lock; try restarting transaction';

			return false;
		}

		if ( $this->other_error ) {
			$this->last_error = "Table 't' doesn't exist";

			return false;
		}

		$this->last_error = '';

		return 1;
	}
}
