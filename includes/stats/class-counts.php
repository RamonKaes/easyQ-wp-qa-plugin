<?php
/**
 * Site-wide counters.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Stats;

use EasyQ\Database\DB;
use EasyQ\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Caches site-wide counts such as the number of questions.
 *
 * Question2Answer updates these counts incrementally on every change. easyQ
 * invalidates them instead and recalculates a count the next time it is read,
 * which keeps them correct without bookkeeping in every code path.
 *
 * @since 0.1.0
 */
final class Counts {

	/**
	 * Option that stores the cached counts.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const OPTION = 'easyq_counts';

	/**
	 * Count of visible questions.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const QUESTIONS = 'questions';

	/**
	 * Count of visible answers.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ANSWERS = 'answers';

	/**
	 * Count of visible comments.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const COMMENTS = 'comments';

	/**
	 * Count of questions without answers.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const UNANSWERED = 'unanswered';

	/**
	 * Count of questions without a selected answer.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const UNSELECTED = 'unselected';

	/**
	 * Count of questions without upvoted answers.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const UNUPVOTED = 'unupvoted';

	/**
	 * Count of posts waiting for approval.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const QUEUED = 'queued';

	/**
	 * Count of flagged posts.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const FLAGGED = 'flagged';

	/**
	 * Count of tags used by visible questions.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const TAGS = 'tags';

	/**
	 * Count of users who took part in the Q&A area.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const USERS_WITH_POINTS = 'users_with_points';

	/**
	 * Counts that depend on visible questions.
	 *
	 * @since 0.1.0
	 *
	 * @var string[]
	 */
	public const QUESTION_COUNTS = array( self::QUESTIONS, self::UNANSWERED, self::UNSELECTED, self::UNUPVOTED, self::TAGS );

	/**
	 * Returns a count, calculating it if it is not cached.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name One of the class constants.
	 * @return int
	 */
	public static function get( string $name ): int {
		$counts = self::stored();

		if ( ! isset( $counts[ $name ] ) ) {
			$counts[ $name ] = self::calculate( $name );
			update_option( self::OPTION, $counts, true );
		}

		return $counts[ $name ];
	}

	/**
	 * Marks counts as outdated.
	 *
	 * @since 0.1.0
	 *
	 * @param string ...$names Count names; all counts if none are given.
	 */
	public static function invalidate( string ...$names ): void {
		$counts = self::stored();
		if ( array() === $counts ) {
			return;
		}

		$remaining = array() === $names ? array() : array_diff_key( $counts, array_flip( $names ) );
		if ( count( $remaining ) !== count( $counts ) ) {
			update_option( self::OPTION, $remaining, true );
		}
	}

	/**
	 * Calculates a count from the database.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name One of the class constants.
	 * @return int
	 */
	public static function calculate( string $name ): int {
		$wpdb  = DB::wpdb();
		$posts = Schema::table( 'posts' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables without a WordPress API.
		$count = match ( $name ) {
			self::QUESTIONS         => $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE type = 'Q'", $posts ) ),
			self::ANSWERS           => $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE type = 'A'", $posts ) ),
			self::COMMENTS          => $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE type = 'C'", $posts ) ),
			self::UNANSWERED        => $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE type = 'Q' AND answer_count = 0 AND closed_by_id IS NULL", $posts ) ),
			self::UNSELECTED        => $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE type = 'Q' AND selected_child_id IS NULL AND closed_by_id IS NULL", $posts ) ),
			self::UNUPVOTED         => $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE type = 'Q' AND answer_max_votes = 0 AND closed_by_id IS NULL", $posts ) ),
			self::QUEUED            => $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE type IN ('Q_QUEUED', 'A_QUEUED', 'C_QUEUED')", $posts ) ),
			self::FLAGGED           => $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE flag_count > 0 AND type IN ('Q', 'A', 'C')", $posts ) ),
			self::TAGS              => $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE tag_count > 0', Schema::table( 'words' ) ) ),
			self::USERS_WITH_POINTS => $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Schema::table( 'userpoints' ) ) ),
			default                 => null,
		};
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return (int) $count;
	}

	/**
	 * Returns the cached counts.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, int>
	 */
	private static function stored(): array {
		$stored = get_option( self::OPTION, array() );
		$counts = array();

		foreach ( is_array( $stored ) ? $stored : array() as $name => $count ) {
			if ( is_string( $name ) && is_numeric( $count ) ) {
				$counts[ $name ] = (int) $count;
			}
		}

		return $counts;
	}
}
