<?php
/**
 * User lists.
 *
 * @package easyQ
 */

namespace EasyQ\Users;

use EasyQ\Database\Db;
use EasyQ\Database\Schema;
use WP_User;
use WP_User_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the users of the Q&A area, like the Question2Answer users pages.
 *
 * - `points`: users who took part in the Q&A area, by points.
 * - `newest`: the newest users of the site.
 * - `special`: users with a level of expert or higher.
 */
final class User_Query {

	/**
	 * Sort orders and the permission option needed to see them.
	 *
	 * @var array<string, string|null>
	 */
	public const SORTS = array(
		'points'  => null,
		'newest'  => 'permit_view_new_users_page',
		'special' => 'permit_view_special_users_page',
	);

	/**
	 * Largest page size.
	 *
	 * @var int
	 */
	public const MAX_PER_PAGE = 100;

	/**
	 * Sets up the query.
	 *
	 * @param User_Levels $levels User levels.
	 */
	public function __construct( private readonly User_Levels $levels ) {
	}

	/**
	 * Lists users.
	 *
	 * @param string $sort     One of the SORTS keys.
	 * @param int    $page     Page number, starting at 1.
	 * @param int    $per_page Users per page.
	 * @return array{ids: int[], total: int}
	 */
	public function find( string $sort, int $page, int $per_page ): array {
		$per_page = max( 1, min( self::MAX_PER_PAGE, $per_page ) );
		$offset   = ( max( 1, $page ) - 1 ) * $per_page;

		return match ( $sort ) {
			'newest'  => $this->newest( $offset, $per_page ),
			'special' => $this->special( $offset, $per_page ),
			default   => $this->by_points( $offset, $per_page ),
		};
	}

	/**
	 * Checks whether a user belongs to the Q&A area of the current site.
	 *
	 * On a network, users of other sites only have a profile here once they took part.
	 *
	 * @param WP_User $user User.
	 * @return bool
	 */
	public function is_member( WP_User $user ): bool {
		if ( ! is_multisite() || is_user_member_of_blog( $user->ID ) ) {
			return true;
		}

		return null !== Db::get_var(
			Db::wpdb()->prepare( 'SELECT user_id FROM %i WHERE user_id = %d', Schema::table( 'userpoints' ), $user->ID )
		);
	}

	/**
	 * Lists users by points.
	 *
	 * @param int $offset Users to skip.
	 * @param int $limit  Maximum number of users.
	 * @return array{ids: int[], total: int}
	 */
	private function by_points( int $offset, int $limit ): array {
		$wpdb  = Db::wpdb();
		$table = Schema::table( 'userpoints' );

		return array(
			'ids'   => Db::get_int_col( $wpdb->prepare( 'SELECT user_id FROM %i ORDER BY points DESC, user_id ASC LIMIT %d, %d', $table, $offset, $limit ) ),
			'total' => Db::get_int( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ),
		);
	}

	/**
	 * Lists the newest users of the site.
	 *
	 * @param int $offset Users to skip.
	 * @param int $limit  Maximum number of users.
	 * @return array{ids: int[], total: int}
	 */
	private function newest( int $offset, int $limit ): array {
		$query = new WP_User_Query(
			array(
				'orderby'     => array(
					'registered' => 'DESC',
					'ID'         => 'DESC',
				),
				'offset'      => $offset,
				'number'      => $limit,
				'fields'      => 'ID',
				'count_total' => true,
			)
		);

		return array(
			'ids'   => self::ids( (array) $query->get_results() ),
			'total' => $query->get_total(),
		);
	}

	/**
	 * Returns the numeric user IDs of a query result.
	 *
	 * @param array<mixed> $results User IDs as returned by WordPress, usually strings.
	 * @return list<int>
	 */
	private static function ids( array $results ): array {
		$ids = array();
		foreach ( $results as $id ) {
			if ( is_numeric( $id ) ) {
				$ids[] = (int) $id;
			}
		}

		return $ids;
	}

	/**
	 * Lists users with a special level, highest level first.
	 *
	 * @param int $offset Users to skip.
	 * @param int $limit  Maximum number of users.
	 * @return array{ids: int[], total: int}
	 */
	private function special( int $offset, int $limit ): array {
		$ids   = $this->levels->special_user_ids();
		$roles = array_keys( array_filter( $this->levels->role_levels(), static fn ( int $level ): bool => $level >= Levels::EXPERT ) );

		if ( array() !== $roles ) {
			$by_role = get_users(
				array(
					'role__in' => $roles,
					'fields'   => 'ID',
				)
			);
			$ids     = array_merge( $ids, self::ids( $by_role ) );
		}

		$users = array();
		foreach ( array_unique( $ids ) as $id ) {
			$level = $this->levels->maximum_level( $id );
			$user  = get_userdata( $id );
			if ( false !== $user && null !== $level && $level >= Levels::EXPERT && $this->is_member( $user ) ) {
				$users[] = array( $level, $user->display_name, $id );
			}
		}

		usort( $users, static fn ( array $a, array $b ): int => array( $b[0], $a[1], $a[2] ) <=> array( $a[0], $b[1], $b[2] ) );

		return array(
			'ids'   => array_map( static fn ( array $user ): int => $user[2], array_slice( $users, $offset, $limit ) ),
			'total' => count( $users ),
		);
	}
}
