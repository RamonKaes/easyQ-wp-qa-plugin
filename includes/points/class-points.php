<?php
/**
 * User points.
 *
 * @package easyQ
 */

namespace EasyQ\Points;

use EasyQ\Database\Db;
use EasyQ\Database\Row;
use EasyQ\Database\Schema;
use EasyQ\Settings\Options;
use EasyQ\Stats\Counts;

defined( 'ABSPATH' ) || exit;

/**
 * Calculates the statistics and points of users.
 *
 * Every statistic column of the userpoints table has a formula and a points
 * multiplier. The points column is the base points plus the sum of all columns
 * times their multiplier, plus the bonus set by an administrator.
 *
 * Ported from Question2Answer `qa-include/db/points.php`. Question2Answer builds
 * one statement with MySQL user variables; easyQ runs one prepared query per
 * column, because assigning user variables in expressions is deprecated in MySQL 8.
 */
final class Points {

	public const QUESTIONS            = 'questions';
	public const ANSWERS              = 'answers';
	public const COMMENTS             = 'comments';
	public const SELECTIONS           = 'selections';
	public const ANSWERS_SELECTED     = 'answers_selected';
	public const QUESTION_UPVOTES     = 'question_upvotes';
	public const QUESTION_DOWNVOTES   = 'question_downvotes';
	public const ANSWER_UPVOTES       = 'answer_upvotes';
	public const ANSWER_DOWNVOTES     = 'answer_downvotes';
	public const COMMENT_UPVOTES      = 'comment_upvotes';
	public const COMMENT_DOWNVOTES    = 'comment_downvotes';
	public const QUESTION_VOTE_POINTS = 'question_vote_points';
	public const ANSWER_VOTE_POINTS   = 'answer_vote_points';
	public const COMMENT_VOTE_POINTS  = 'comment_vote_points';
	public const UPVOTES_RECEIVED     = 'upvotes_received';
	public const DOWNVOTES_RECEIVED   = 'downvotes_received';

	/**
	 * Updates statistic columns and the total points of a user.
	 *
	 * @param int|null $user_id User ID; nothing happens for null (anonymous authors).
	 * @param string[] $columns Columns to recalculate; the total is always updated.
	 */
	public function update( ?int $user_id, array $columns ): void {
		if ( null === $user_id || $user_id <= 0 ) {
			return;
		}

		$wpdb   = Db::wpdb();
		$table  = Schema::table( 'userpoints' );
		$values = array();

		foreach ( array_unique( $columns ) as $column ) {
			$values[ $column ] = $this->calculate( $user_id, $column );
		}

		$row     = Db::get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE user_id = %d', $table, $user_id ) );
		$current = null === $row ? array() : get_object_vars( $row );
		$merged  = array_merge( $current, $values );

		$total = $this->total( $merged );

		if ( null === $row ) {
			Db::insert(
				$table,
				array_merge(
					$values,
					array(
						'user_id' => $user_id,
						'points'  => $total,
					)
				),
				array()
			);
			Counts::invalidate( Counts::USERS_WITH_POINTS );
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$wpdb->update( $table, array_merge( $values, array( 'points' => $total ) ), array( 'user_id' => $user_id ) );
	}

	/**
	 * Recalculates all statistics and the total points of a user.
	 *
	 * @param int $user_id User ID.
	 */
	public function update_all( int $user_id ): void {
		$this->update( $user_id, array_keys( $this->formulas() ) );
	}

	/**
	 * Sets the bonus points of a user and updates the total.
	 *
	 * @param int $user_id User ID.
	 * @param int $bonus   Bonus points, may be negative.
	 */
	public function set_bonus( int $user_id, int $bonus ): void {
		$wpdb  = Db::wpdb();
		$table = Schema::table( 'userpoints' );

		Db::query(
			$wpdb->prepare(
				'INSERT INTO %i (user_id, bonus) VALUES (%d, %d) ON DUPLICATE KEY UPDATE bonus = %d',
				$table,
				$user_id,
				$bonus,
				$bonus
			)
		);

		$this->update( $user_id, array() );
	}

	/**
	 * Returns the stored points of a user.
	 *
	 * @param int $user_id User ID.
	 * @return int Points; the base points if the user has none stored.
	 */
	public function get( int $user_id ): int {
		$points = Db::get_var(
			Db::wpdb()->prepare( 'SELECT points FROM %i WHERE user_id = %d', Schema::table( 'userpoints' ), $user_id )
		);

		return null === $points ? Options::get_int( 'points', 'points_base' ) : (int) $points;
	}

	/**
	 * Returns the stored statistics of a user.
	 *
	 * @param int $user_id User ID.
	 * @return array<string, int> Points, bonus and every statistic column; zero for users without activity.
	 */
	public function stats( int $user_id ): array {
		$row   = Db::get_row( Db::wpdb()->prepare( 'SELECT * FROM %i WHERE user_id = %d', Schema::table( 'userpoints' ), $user_id ) );
		$stats = array(
			'points' => null === $row ? Options::get_int( 'points', 'points_base' ) : Row::int( $row, 'points' ),
			'bonus'  => null === $row ? 0 : Row::int( $row, 'bonus' ),
		);

		foreach ( array_keys( $this->multipliers() ) as $column ) {
			$stats[ $column ] = null === $row ? 0 : Row::int( $row, $column );
		}

		return $stats;
	}

	/**
	 * Returns the rank of a user by points.
	 *
	 * @param int $user_id User ID.
	 * @return int|null Rank starting at 1, or null if the user has no points stored.
	 */
	public function rank( int $user_id ): ?int {
		$wpdb   = Db::wpdb();
		$table  = Schema::table( 'userpoints' );
		$points = Db::get_var( $wpdb->prepare( 'SELECT points FROM %i WHERE user_id = %d', $table, $user_id ) );
		if ( null === $points ) {
			return null;
		}

		return 1 + Db::get_int( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE points > %d', $table, (int) $points ) );
	}

	/**
	 * Returns the multiplier of every statistic column.
	 *
	 * @return array<string, int>
	 */
	public function multipliers(): array {
		$multiple = Options::get_int( 'points', 'points_multiple' );
		$option   = static fn ( string $name ): int => $multiple * Options::get_int( 'points', $name );

		return array(
			self::QUESTIONS            => $option( 'points_post_q' ),
			self::ANSWERS              => $option( 'points_post_a' ),
			self::COMMENTS             => 0,
			self::SELECTIONS           => $option( 'points_select_a' ),
			self::ANSWERS_SELECTED     => $option( 'points_a_selected' ),
			self::QUESTION_UPVOTES     => $option( 'points_vote_up_q' ),
			self::QUESTION_DOWNVOTES   => $option( 'points_vote_down_q' ),
			self::ANSWER_UPVOTES       => $option( 'points_vote_up_a' ),
			self::ANSWER_DOWNVOTES     => $option( 'points_vote_down_a' ),
			self::COMMENT_UPVOTES      => 0,
			self::COMMENT_DOWNVOTES    => 0,
			self::QUESTION_VOTE_POINTS => $multiple,
			self::ANSWER_VOTE_POINTS   => $multiple,
			self::COMMENT_VOTE_POINTS  => $multiple,
			self::UPVOTES_RECEIVED     => 0,
			self::DOWNVOTES_RECEIVED   => 0,
		);
	}

	/**
	 * Calculates the total points from statistic values.
	 *
	 * @param array<int|string, mixed> $values Statistic values keyed by column; missing columns count as 0.
	 * @return int
	 */
	public function total( array $values ): int { // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint
		$number = static fn ( string $key ): int => isset( $values[ $key ] ) && is_numeric( $values[ $key ] ) ? (int) $values[ $key ] : 0;
		$total  = Options::get_int( 'points', 'points_base' ) + $number( 'bonus' );

		foreach ( $this->multipliers() as $column => $multiplier ) {
			$total += $multiplier * $number( $column );
		}

		return $total;
	}

	/**
	 * Calculates one statistic column for a user.
	 *
	 * @param int    $user_id User ID.
	 * @param string $column  Column name.
	 * @return int
	 */
	public function calculate( int $user_id, string $column ): int {
		$formulas = $this->formulas();
		if ( ! isset( $formulas[ $column ] ) ) {
			return 0;
		}

		return Db::get_int( $formulas[ $column ]( $user_id ) );
	}

	/**
	 * Returns the prepared query builders for every statistic column.
	 *
	 * @return array<string, callable(int): (string|null)>
	 */
	private function formulas(): array {
		$wpdb  = Db::wpdb();
		$posts = Schema::table( 'posts' );
		$votes = Schema::table( 'uservotes' );

		$count_posts = static fn ( string $type ): callable => static fn ( int $user_id ): ?string => $wpdb->prepare(
			'SELECT COUNT(*) FROM %i WHERE user_id = %d AND type = %s',
			$posts,
			$user_id,
			$type
		);

		$count_votes = static fn ( string $base_type, string $direction ): callable => static fn ( int $user_id ): ?string => $wpdb->prepare(
			'SELECT COUNT(*) FROM %i AS v JOIN %i AS p ON v.post_id = p.id WHERE v.user_id = %d AND LEFT(p.type, 1) = %s AND v.vote * %d > 0',
			$votes,
			$posts,
			$user_id,
			$base_type,
			'up' === $direction ? 1 : -1
		);

		$vote_points = static function ( string $base_type, string $prefix ) use ( $wpdb, $posts ): callable {
			return static fn ( int $user_id ): ?string => $wpdb->prepare(
				'SELECT COALESCE(SUM(LEAST(%d * CAST(upvotes AS SIGNED), %d) - LEAST(%d * CAST(downvotes AS SIGNED), %d)), 0) FROM %i WHERE LEFT(type, 1) = %s AND user_id = %d',
				Options::get_int( 'points', "points_per_{$prefix}_voted_up" ),
				Options::get_int( 'points', "points_{$prefix}_voted_max_gain" ),
				Options::get_int( 'points', "points_per_{$prefix}_voted_down" ),
				Options::get_int( 'points', "points_{$prefix}_voted_max_loss" ),
				$posts,
				$base_type,
				$user_id
			);
		};

		return array(
			self::QUESTIONS            => $count_posts( 'Q' ),
			self::ANSWERS              => $count_posts( 'A' ),
			self::COMMENTS             => $count_posts( 'C' ),
			self::SELECTIONS           => static fn ( int $user_id ): ?string => $wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE user_id = %d AND type = 'Q' AND selected_child_id IS NOT NULL",
				$posts,
				$user_id
			),
			// Answers selected by someone other than the answer's author.
			self::ANSWERS_SELECTED     => static fn ( int $user_id ): ?string => $wpdb->prepare(
				"SELECT COUNT(*) FROM %i AS a JOIN %i AS q ON q.selected_child_id = a.id WHERE a.user_id = %d AND a.type = 'A' AND NOT (q.user_id <=> a.user_id)",
				$posts,
				$posts,
				$user_id
			),
			self::QUESTION_UPVOTES     => $count_votes( 'Q', 'up' ),
			self::QUESTION_DOWNVOTES   => $count_votes( 'Q', 'down' ),
			self::ANSWER_UPVOTES       => $count_votes( 'A', 'up' ),
			self::ANSWER_DOWNVOTES     => $count_votes( 'A', 'down' ),
			self::COMMENT_UPVOTES      => $count_votes( 'C', 'up' ),
			self::COMMENT_DOWNVOTES    => $count_votes( 'C', 'down' ),
			self::QUESTION_VOTE_POINTS => $vote_points( 'Q', 'q' ),
			self::ANSWER_VOTE_POINTS   => $vote_points( 'A', 'a' ),
			self::COMMENT_VOTE_POINTS  => $vote_points( 'C', 'c' ),
			self::UPVOTES_RECEIVED     => static fn ( int $user_id ): ?string => $wpdb->prepare(
				'SELECT COALESCE(SUM(upvotes), 0) FROM %i WHERE user_id = %d',
				$posts,
				$user_id
			),
			self::DOWNVOTES_RECEIVED   => static fn ( int $user_id ): ?string => $wpdb->prepare(
				'SELECT COALESCE(SUM(downvotes), 0) FROM %i WHERE user_id = %d',
				$posts,
				$user_id
			),
		);
	}
}
