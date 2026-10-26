<?php
/**
 * User titles.
 *
 * @package easyQ
 */

namespace EasyQ\Users;

use EasyQ\Points\Points;
use EasyQ\Settings\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Returns the title shown with a user, such as "Expert" or a title earned with points.
 *
 * Users with a level of expert or higher are shown with their level. Everybody
 * else gets the title of the highest points threshold they reached, as set in
 * the points settings (Question2Answer "User titles").
 */
final class User_Titles {

	/**
	 * Sets up the titles.
	 *
	 * @param User_Levels $levels User levels.
	 * @param Points      $points Points.
	 */
	public function __construct(
		private readonly User_Levels $levels,
		private readonly Points $points
	) {
	}

	/**
	 * Returns the title of a user.
	 *
	 * @param int $user_id User ID.
	 * @return string Title, or an empty string if the user has none.
	 */
	public function title( int $user_id ): string {
		$level = $this->levels->maximum_level( $user_id );
		if ( null !== $level && $level >= Levels::EXPERT ) {
			return Levels::labels()[ $level ] ?? '';
		}

		return $this->points_title( $this->points->get( $user_id ) );
	}

	/**
	 * Returns the title earned with a number of points.
	 *
	 * @param int $points Points.
	 * @return string Title, or an empty string if no threshold is reached.
	 */
	public function points_title( int $points ): string {
		$title     = '';
		$threshold = PHP_INT_MIN;

		foreach ( self::thresholds() as $minimum => $name ) {
			if ( $points >= $minimum && $minimum >= $threshold ) {
				$title     = $name;
				$threshold = $minimum;
			}
		}

		return $title;
	}

	/**
	 * Returns the titles from the settings.
	 *
	 * @return array<int, string> Titles keyed by the points needed.
	 */
	public static function thresholds(): array {
		$stored = Options::get( 'points', 'user_titles' );
		$titles = array();

		foreach ( is_array( $stored ) ? $stored : array() as $minimum => $name ) {
			if ( is_numeric( $minimum ) && is_string( $name ) && '' !== $name ) {
				$titles[ (int) $minimum ] = $name;
			}
		}

		return $titles;
	}
}
