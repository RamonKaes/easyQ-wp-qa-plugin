<?php
/**
 * Site-wide counters.
 *
 * @package easyQ
 */

namespace EasyQ\Stats;

use EasyQ\Database\Db;
use EasyQ\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Caches site-wide counts such as the number of questions.
 *
 * Question2Answer updates these counts incrementally on every change. easyQ
 * invalidates them instead and recalculates a count the next time it is read,
 * which keeps them correct without bookkeeping in every code path.
 */
final class Counts {

	/**
	 * Option that stores the cached counts.
	 *
	 * @var string
	 */
	public const OPTION = 'easyq_counts';

	public const QUESTIONS         = 'questions';
	public const ANSWERS           = 'answers';
	public const COMMENTS          = 'comments';
	public const UNANSWERED        = 'unanswered';
	public const UNSELECTED        = 'unselected';
	public const UNUPVOTED         = 'unupvoted';
	public const QUEUED            = 'queued';
	public const FLAGGED           = 'flagged';
	public const TAGS              = 'tags';
	public const USERS_WITH_POINTS = 'users_with_points';

	/**
	 * Counts that depend on visible questions.
	 *
	 * @var string[]
	 */
	public const QUESTION_COUNTS = array( self::QUESTIONS, self::UNANSWERED, self::UNSELECTED, self::UNUPVOTED, self::TAGS );

	/**
	 * Returns a count, calculating it if it is not cached.
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
	 * @param string $name One of the class constants.
	 * @return int
	 */
	public static function calculate( string $name ): int {
		$wpdb  = Db::wpdb();
		$posts = Schema::table( 'posts' );

		$sql = match ( $name ) {
			self::QUESTIONS         => $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE type = 'Q'", $posts ),
			self::ANSWERS           => $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE type = 'A'", $posts ),
			self::COMMENTS          => $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE type = 'C'", $posts ),
			self::UNANSWERED        => $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE type = 'Q' AND answer_count = 0 AND closed_by_id IS NULL", $posts ),
			self::UNSELECTED        => $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE type = 'Q' AND selected_child_id IS NULL AND closed_by_id IS NULL", $posts ),
			self::UNUPVOTED         => $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE type = 'Q' AND answer_max_votes = 0 AND closed_by_id IS NULL", $posts ),
			self::QUEUED            => $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE type IN ('Q_QUEUED', 'A_QUEUED', 'C_QUEUED')", $posts ),
			self::FLAGGED           => $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE flag_count > 0 AND type IN ('Q', 'A', 'C')", $posts ),
			self::TAGS              => $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE tag_count > 0', Schema::table( 'words' ) ),
			self::USERS_WITH_POINTS => $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Schema::table( 'userpoints' ) ),
			default                 => null,
		};

		return Db::get_int( $sql );
	}

	/**
	 * Returns the cached counts.
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
