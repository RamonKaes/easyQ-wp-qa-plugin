<?php
/**
 * User points.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Points;

use EasyQ\Database\DB;
use EasyQ\Database\Row;
use EasyQ\Database\Schema;
use EasyQ\Services;
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
 *
 * @since 0.1.0
 */
final class Points {

	/**
	 * Points of users loaded during the current request, keyed by user ID.
	 *
	 * Pages show the points of the same authors many times. Every write forgets them.
	 *
	 * @since 0.1.0
	 *
	 * @var array<int, int>
	 */
	private array $loaded = array();

	/**
	 * Statistic column: questions the user asked.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const QUESTIONS = 'questions';

	/**
	 * Statistic column: answers the user wrote.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ANSWERS = 'answers';

	/**
	 * Statistic column: comments the user wrote.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const COMMENTS = 'comments';

	/**
	 * Statistic column: best answers the user selected for own questions.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const SELECTIONS = 'selections';

	/**
	 * Statistic column: answers of the user selected as best answer.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ANSWERS_SELECTED = 'answers_selected';

	/**
	 * Statistic column: upvotes the user gave to questions.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const QUESTION_UPVOTES = 'question_upvotes';

	/**
	 * Statistic column: downvotes the user gave to questions.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const QUESTION_DOWNVOTES = 'question_downvotes';

	/**
	 * Statistic column: upvotes the user gave to answers.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ANSWER_UPVOTES = 'answer_upvotes';

	/**
	 * Statistic column: downvotes the user gave to answers.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ANSWER_DOWNVOTES = 'answer_downvotes';

	/**
	 * Statistic column: upvotes the user gave to comments.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const COMMENT_UPVOTES = 'comment_upvotes';

	/**
	 * Statistic column: downvotes the user gave to comments.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const COMMENT_DOWNVOTES = 'comment_downvotes';

	/**
	 * Statistic column: net votes the questions of the user received.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const QUESTION_VOTE_POINTS = 'question_vote_points';

	/**
	 * Statistic column: net votes the answers of the user received.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ANSWER_VOTE_POINTS = 'answer_vote_points';

	/**
	 * Statistic column: net votes the comments of the user received.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const COMMENT_VOTE_POINTS = 'comment_vote_points';

	/**
	 * Statistic column: upvotes the posts of the user received.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const UPVOTES_RECEIVED = 'upvotes_received';

	/**
	 * Statistic column: downvotes the posts of the user received.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const DOWNVOTES_RECEIVED = 'downvotes_received';

	/**
	 * Settings that change how votes on each kind of post count, by the prefix of the vote points.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string> Post type by prefix.
	 */
	private const VOTE_PREFIXES = array(
		'q' => 'Q',
		'a' => 'A',
		'c' => 'C',
	);

	/**
	 * Recalculates the points of all users when the points settings change.
	 *
	 * The hooks use the current service, whose loaded points must be forgotten.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		$option = Options::option_name( 'points' );

		add_action(
			"update_option_{$option}",
			static function ( mixed $old, mixed $value ): void {
				Services::points()->apply_settings( is_array( $old ) ? $old : array(), is_array( $value ) ? $value : array() );
			},
			10,
			2
		);
		add_action(
			"add_option_{$option}",
			static function ( string $name, mixed $value ): void {
				Services::points()->apply_settings( Options::defaults()['points'], is_array( $value ) ? $value : array() );
			},
			10,
			2
		);
	}

	/**
	 * Recalculates the points of all users after the points settings changed.
	 *
	 * Question2Answer asks for a separate recalculation; here the stored points
	 * follow the settings right away, with one statement for each changed part.
	 *
	 * @since 0.1.0
	 *
	 * @param array<mixed> $old   Settings before the change.
	 * @param array<mixed> $value Settings after the change.
	 * @return bool Whether anything was recalculated.
	 */
	public function apply_settings( array $old, array $value ): bool {
		$changed = self::changed_settings( $old, $value );
		if ( array() === $changed ) {
			return false;
		}

		foreach ( self::VOTE_PREFIXES as $prefix => $type ) {
			$keys = array( "points_per_{$prefix}_voted_up", "points_per_{$prefix}_voted_down", "points_{$prefix}_voted_max_gain", "points_{$prefix}_voted_max_loss" );
			if ( array() !== array_intersect( $keys, $changed ) ) {
				$this->recalculate_vote_points( $prefix );
			}
		}

		$this->recalculate_totals();

		return true;
	}

	/**
	 * Returns the points settings that differ between two versions of the settings.
	 *
	 * @since 0.1.0
	 *
	 * @param array<mixed> $old   Settings before the change.
	 * @param array<mixed> $value Settings after the change.
	 * @return string[] Names of the changed settings; user titles do not count.
	 */
	public static function changed_settings( array $old, array $value ): array {
		$defaults = Options::defaults()['points'];
		// Stored settings may be numbers or numeric strings.
		$number  = static fn ( mixed $setting ): int => is_numeric( $setting ) ? (int) $setting : 0;
		$changed = array();
		foreach ( array_keys( $defaults ) as $key ) {
			if ( str_starts_with( $key, 'points_' ) && $number( $old[ $key ] ?? $defaults[ $key ] ) !== $number( $value[ $key ] ?? $defaults[ $key ] ) ) {
				$changed[] = $key;
			}
		}

		return $changed;
	}

	/**
	 * Recalculates the points that all users received for votes on one kind of post.
	 *
	 * @since 0.1.0
	 *
	 * @param string $prefix `q`, `a` or `c`.
	 */
	public function recalculate_vote_points( string $prefix ): void {
		$wpdb   = DB::wpdb();
		$column = match ( $prefix ) {
			'q'     => self::QUESTION_VOTE_POINTS,
			'a'     => self::ANSWER_VOTE_POINTS,
			default => self::COMMENT_VOTE_POINTS,
		};

		$sql = $wpdb->prepare(
			'UPDATE %i AS u LEFT JOIN (
				SELECT user_id, SUM(LEAST(%d * CAST(upvotes AS SIGNED), %d) - LEAST(%d * CAST(downvotes AS SIGNED), %d)) AS total
				FROM %i WHERE LEFT(type, 1) = %s AND user_id IS NOT NULL GROUP BY user_id
			) AS v ON v.user_id = u.user_id
			SET u.%i = COALESCE(v.total, 0)',
			Schema::table( 'userpoints' ),
			Options::get_int( 'points', "points_per_{$prefix}_voted_up" ),
			Options::get_int( 'points', "points_{$prefix}_voted_max_gain" ),
			Options::get_int( 'points', "points_per_{$prefix}_voted_down" ),
			Options::get_int( 'points', "points_{$prefix}_voted_max_loss" ),
			Schema::table( 'posts' ),
			self::VOTE_PREFIXES[ $prefix ] ?? 'C',
			$column
		);
		if ( null !== $sql ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; custom tables without a WordPress API.
			$wpdb->query( $sql );
		}
		$this->loaded = array();
	}

	/**
	 * Recalculates the total points of all users from their stored statistics.
	 *
	 * @since 0.1.0
	 */
	public function recalculate_totals(): void {
		$wpdb = DB::wpdb();
		$m    = $this->multipliers();

		$sql = $wpdb->prepare(
			'UPDATE %i SET points = %d + bonus
				+ %d * questions + %d * answers + %d * comments + %d * selections + %d * answers_selected
				+ %d * question_upvotes + %d * question_downvotes + %d * answer_upvotes + %d * answer_downvotes
				+ %d * comment_upvotes + %d * comment_downvotes
				+ %d * question_vote_points + %d * answer_vote_points + %d * comment_vote_points
				+ %d * upvotes_received + %d * downvotes_received',
			Schema::table( 'userpoints' ),
			Options::get_int( 'points', 'points_base' ),
			$m[ self::QUESTIONS ],
			$m[ self::ANSWERS ],
			$m[ self::COMMENTS ],
			$m[ self::SELECTIONS ],
			$m[ self::ANSWERS_SELECTED ],
			$m[ self::QUESTION_UPVOTES ],
			$m[ self::QUESTION_DOWNVOTES ],
			$m[ self::ANSWER_UPVOTES ],
			$m[ self::ANSWER_DOWNVOTES ],
			$m[ self::COMMENT_UPVOTES ],
			$m[ self::COMMENT_DOWNVOTES ],
			$m[ self::QUESTION_VOTE_POINTS ],
			$m[ self::ANSWER_VOTE_POINTS ],
			$m[ self::COMMENT_VOTE_POINTS ],
			$m[ self::UPVOTES_RECEIVED ],
			$m[ self::DOWNVOTES_RECEIVED ]
		);
		if ( null !== $sql ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; custom tables without a WordPress API.
			$wpdb->query( $sql );
		}
		$this->loaded = array();
	}

	/**
	 * Updates statistic columns and the total points of a user.
	 *
	 * @since 0.1.0
	 *
	 * @param int|null $user_id User ID; nothing happens for null (anonymous authors).
	 * @param string[] $columns Columns to recalculate; the total is always updated.
	 */
	public function update( ?int $user_id, array $columns ): void {
		if ( null === $user_id || $user_id <= 0 ) {
			return;
		}

		$this->forget( $user_id );

		$wpdb   = DB::wpdb();
		$table  = Schema::table( 'userpoints' );
		$values = array();

		foreach ( array_unique( $columns ) as $column ) {
			$values[ $column ] = $this->calculate( $user_id, $column );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables without a WordPress API.
		$row     = DB::row( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE user_id = %d', $table, $user_id ) ) );
		$current = null === $row ? array() : get_object_vars( $row );
		$merged  = array_merge( $current, $values );

		$total = $this->total( $merged );

		if ( null === $row ) {
			DB::insert(
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
	 * @since 0.1.0
	 *
	 * @param int $user_id User ID.
	 */
	public function update_all( int $user_id ): void {
		$this->update( $user_id, array_keys( $this->formulas() ) );
	}

	/**
	 * Sets the bonus points of a user and updates the total.
	 *
	 * @since 0.1.0
	 *
	 * @param int $user_id User ID.
	 * @param int $bonus   Bonus points, may be negative.
	 */
	public function set_bonus( int $user_id, int $bonus ): void {
		$wpdb  = DB::wpdb();
		$table = Schema::table( 'userpoints' );

		$sql = $wpdb->prepare(
			'INSERT INTO %i (user_id, bonus) VALUES (%d, %d) ON DUPLICATE KEY UPDATE bonus = %d',
			$table,
			$user_id,
			$bonus,
			$bonus
		);
		if ( null !== $sql ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; custom tables without a WordPress API.
			$wpdb->query( $sql );
		}

		$this->update( $user_id, array() );
	}

	/**
	 * Returns the stored points of a user.
	 *
	 * @since 0.1.0
	 *
	 * @param int $user_id User ID.
	 * @return int Points; the base points if the user has none stored.
	 */
	public function get( int $user_id ): int {
		$wpdb = DB::wpdb();
		if ( ! isset( $this->loaded[ $user_id ] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables without a WordPress API.
			$points = $wpdb->get_var(
				$wpdb->prepare( 'SELECT points FROM %i WHERE user_id = %d', Schema::table( 'userpoints' ), $user_id )
			);

			$this->loaded[ $user_id ] = null === $points ? Options::get_int( 'points', 'points_base' ) : (int) $points;
		}

		return $this->loaded[ $user_id ];
	}

	/**
	 * Forgets the points of a user loaded during the current request.
	 *
	 * Call it after changing the userpoints table outside this class.
	 *
	 * @since 0.1.0
	 *
	 * @param int $user_id User ID.
	 */
	public function forget( int $user_id ): void {
		unset( $this->loaded[ $user_id ] );
	}

	/**
	 * Returns the stored statistics of a user.
	 *
	 * @since 0.1.0
	 *
	 * @param int $user_id User ID.
	 * @return array<string, int> Points, bonus and every statistic column; zero for users without activity.
	 */
	public function stats( int $user_id ): array {
		$wpdb = DB::wpdb();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables without a WordPress API.
		$row   = DB::row( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE user_id = %d', Schema::table( 'userpoints' ), $user_id ) ) );
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
	 * @since 0.1.0
	 *
	 * @param int $user_id User ID.
	 * @return int|null Rank starting at 1, or null if the user has no points stored.
	 */
	public function rank( int $user_id ): ?int {
		$wpdb  = DB::wpdb();
		$table = Schema::table( 'userpoints' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables without a WordPress API.
		$points = $wpdb->get_var( $wpdb->prepare( 'SELECT points FROM %i WHERE user_id = %d', $table, $user_id ) );
		if ( null === $points ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables without a WordPress API.
		return 1 + (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE points > %d', $table, (int) $points ) );
	}

	/**
	 * Returns the multiplier of every statistic column.
	 *
	 * @since 0.1.0
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
	 * @since 0.1.0
	 *
	 * @param array<mixed> $values Statistic values keyed by column; missing columns count as 0.
	 * @return int
	 */
	public function total( array $values ): int {
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
	 * @since 0.1.0
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

		return (int) $formulas[ $column ]( $user_id );
	}

	/**
	 * Returns the database queries that calculate every statistic column.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, callable(int): (string|null)>
	 */
	private function formulas(): array {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables without a WordPress API.
		$wpdb  = DB::wpdb();
		$posts = Schema::table( 'posts' );
		$votes = Schema::table( 'uservotes' );

		$count_posts = static fn ( string $type ): callable => static fn ( int $user_id ): ?string => $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE user_id = %d AND type = %s',
				$posts,
				$user_id,
				$type
			)
);

		$count_votes = static fn ( string $base_type, string $direction ): callable => static fn ( int $user_id ): ?string => $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i AS v JOIN %i AS p ON v.post_id = p.id WHERE v.user_id = %d AND LEFT(p.type, 1) = %s AND v.vote * %d > 0',
				$votes,
				$posts,
				$user_id,
				$base_type,
				'up' === $direction ? 1 : -1
			)
);

		$vote_points = static function ( string $base_type, string $prefix ) use ( $wpdb, $posts ): callable {
			return static fn ( int $user_id ): ?string => $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COALESCE(SUM(LEAST(%d * CAST(upvotes AS SIGNED), %d) - LEAST(%d * CAST(downvotes AS SIGNED), %d)), 0) FROM %i WHERE LEFT(type, 1) = %s AND user_id = %d',
					Options::get_int( 'points', "points_per_{$prefix}_voted_up" ),
					Options::get_int( 'points', "points_{$prefix}_voted_max_gain" ),
					Options::get_int( 'points', "points_per_{$prefix}_voted_down" ),
					Options::get_int( 'points', "points_{$prefix}_voted_max_loss" ),
					$posts,
					$base_type,
					$user_id
				)
);
		};

		return array(
			self::QUESTIONS            => $count_posts( 'Q' ),
			self::ANSWERS              => $count_posts( 'A' ),
			self::COMMENTS             => $count_posts( 'C' ),
			self::SELECTIONS           => static fn ( int $user_id ): ?string => $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM %i WHERE user_id = %d AND type = 'Q' AND selected_child_id IS NOT NULL",
					$posts,
					$user_id
				)
			),
			// Answers selected by someone other than the answer's author.
			self::ANSWERS_SELECTED     => static fn ( int $user_id ): ?string => $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM %i AS a JOIN %i AS q ON q.selected_child_id = a.id WHERE a.user_id = %d AND a.type = 'A' AND NOT (q.user_id <=> a.user_id)",
					$posts,
					$posts,
					$user_id
				)
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
			self::UPVOTES_RECEIVED     => static fn ( int $user_id ): ?string => $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COALESCE(SUM(upvotes), 0) FROM %i WHERE user_id = %d',
					$posts,
					$user_id
				)
			),
			self::DOWNVOTES_RECEIVED   => static fn ( int $user_id ): ?string => $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COALESCE(SUM(downvotes), 0) FROM %i WHERE user_id = %d',
					$posts,
					$user_id
				)
			),
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}
}
