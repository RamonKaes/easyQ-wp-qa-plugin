<?php
/**
 * User lists.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Users;

use EasyQ\Database\DB;
use EasyQ\Database\Schema;
use EasyQ\Services;
use WP_User;
use WP_User_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the users of the Q&A area, like the Question2Answer users pages.
 *
 * - `points`: users who took part in the Q&A area, by points.
 * - `newest`: the newest users of the site.
 * - `special`: users with a level of expert or higher.
 *
 * @since 0.1.0
 */
final class User_Query {

	/**
	 * Sort orders and the permission option needed to see them.
	 *
	 * @since 0.1.0
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
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const MAX_PER_PAGE = 100;

	/**
	 * Sets up the query.
	 *
	 * @since 0.1.0
	 *
	 * @param User_Levels $levels User levels.
	 */
	public function __construct( private readonly User_Levels $levels ) {
	}

	/**
	 * Lists users.
	 *
	 * @since 0.1.0
	 *
	 * The newest and the special users are the profiles the viewer may see (is_shown()): users
	 * who took part, or every user for those who may list users, as core sorts users by their
	 * registration only for them. The list by points has only users who took part anyway.
	 *
	 * @param string   $sort      One of the SORTS keys.
	 * @param int      $page      Page number, starting at 1.
	 * @param int      $per_page  Users per page.
	 * @param int|null $viewer_id Viewer, null for a visitor.
	 * @return array{ids: int[], total: int}
	 */
	public function find( string $sort, int $page, int $per_page, ?int $viewer_id = null ): array {
		$per_page = max( 1, min( self::MAX_PER_PAGE, $per_page ) );
		$offset   = ( max( 1, $page ) - 1 ) * $per_page;
		$all      = null !== $viewer_id && user_can( $viewer_id, 'list_users' );

		return match ( $sort ) {
			'newest'  => $this->newest( $offset, $per_page, $all ),
			'special' => $this->special( $offset, $per_page, $all ),
			default   => $this->by_points( $offset, $per_page ),
		};
	}

	/**
	 * Checks whether a user belongs to the Q&A area of the current site.
	 *
	 * On a network, users of other sites only have a profile here once they took part.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_User $user User.
	 * @return bool
	 */
	public function is_member( WP_User $user ): bool {
		$wpdb = DB::wpdb();
		if ( ! is_multisite() || is_user_member_of_blog( $user->ID ) ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables without a WordPress API.
		return null !== $wpdb->get_var(
			$wpdb->prepare( 'SELECT user_id FROM %i WHERE user_id = %d', Schema::table( 'userpoints' ), $user->ID )
		);
	}

	/**
	 * Checks whether a viewer may see the profile of a user, as core decides for its users
	 * endpoint (`WP_REST_Users_Controller::get_item_permissions_check()`): the profile of a user
	 * who took part in the Q&A area, the own profile, and every profile for those who may list
	 * or edit users. Taking part means having a row of points, which only an action in the Q&A
	 * area writes.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_User  $user      User.
	 * @param int|null $viewer_id Viewer, null for a visitor.
	 * @return bool
	 */
	public function is_shown( WP_User $user, ?int $viewer_id ): bool {
		return array() !== $this->shown_ids( array( $user->ID ), $viewer_id );
	}

	/**
	 * Loads users who are about to be shown, with their points and category levels, at once,
	 * as `update_post_author_caches()` does in core.
	 *
	 * @since 0.1.0
	 *
	 * @param array<?int> $user_ids User IDs; empty values are skipped.
	 */
	public function prime( array $user_ids ): void {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $user_ids ) ) ) );

		cache_users( $ids );
		Services::points()->prime( $ids );
		// Levels and titles depend on the category levels, see User_Titles::title().
		Services::user_levels()->prime_category_levels( $ids );
	}

	/**
	 * Returns those of several users whose profiles a viewer may see, as `is_shown()` decides
	 * for one, with one query for the whole list instead of one per user.
	 *
	 * @since 0.1.0
	 *
	 * @param int[]    $user_ids  User IDs.
	 * @param int|null $viewer_id Viewer, null for a visitor.
	 * @return int[] The shown users, in the given order.
	 */
	public function shown_ids( array $user_ids, ?int $viewer_id ): array {
		$user_ids = array_values( array_unique( array_map( 'intval', $user_ids ) ) );
		if ( array() === $user_ids ) {
			return array();
		}

		cache_users( $user_ids );
		$took_part = array_flip( Services::points()->with_row( $user_ids ) );

		return array_values(
			array_filter(
				$user_ids,
				function ( int $id ) use ( $took_part, $viewer_id ): bool {
					$user = get_userdata( $id );
					if ( false === $user ) {
						return false;
					}

					if ( isset( $took_part[ $id ] ) ) {
						return true;
					}

					// Without a row of points: members only, and only to themselves and to those who may list or edit users.
					return ( ! is_multisite() || is_user_member_of_blog( $id ) )
						&& null !== $viewer_id && ( $viewer_id === $id || user_can( $viewer_id, 'list_users' ) || user_can( $viewer_id, 'edit_user', $id ) );
				}
			)
		);
	}

	/**
	 * Checks whether a viewer may see when a user registered: core shows it in the edit context
	 * only, to the user and to those who may list or edit users.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_User  $user      User.
	 * @param int|null $viewer_id Viewer, null for a visitor.
	 * @return bool
	 */
	public function shows_registration( WP_User $user, ?int $viewer_id ): bool {
		return null !== $viewer_id && ( $viewer_id === $user->ID || user_can( $viewer_id, 'list_users' ) || user_can( $viewer_id, 'edit_user', $user->ID ) );
	}

	/**
	 * Lists users by points.
	 *
	 * @since 0.1.0
	 *
	 * @param int $offset Users to skip.
	 * @param int $limit  Maximum number of users.
	 * @return array{ids: int[], total: int}
	 */
	private function by_points( int $offset, int $limit ): array {
		$wpdb  = DB::wpdb();
		$table = Schema::table( 'userpoints' );

		return array(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables without a WordPress API.
			'ids'   => DB::int_col( $wpdb->get_col( $wpdb->prepare( 'SELECT user_id FROM %i ORDER BY points DESC, user_id ASC LIMIT %d, %d', $table, $offset, $limit ) ) ),
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables without a WordPress API.
			'total' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ),
		);
	}

	/**
	 * Lists the newest users of the site.
	 *
	 * @since 0.1.0
	 *
	 * @param int  $offset Users to skip.
	 * @param int  $limit  Maximum number of users.
	 * @param bool $all    Whether the viewer may list every user; otherwise only users who took part.
	 * @return array{ids: int[], total: int}
	 */
	private function newest( int $offset, int $limit, bool $all ): array {
		if ( ! $all ) {
			$wpdb  = DB::wpdb();
			$table = Schema::table( 'userpoints' );

			return array(
				'ids'   => DB::int_col(
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables without a WordPress API.
					$wpdb->get_col( $wpdb->prepare( 'SELECT u.ID FROM %i AS u INNER JOIN %i AS p ON p.user_id = u.ID ORDER BY u.user_registered DESC, u.ID DESC LIMIT %d OFFSET %d', $wpdb->users, $table, $limit, $offset ) )
				),
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables without a WordPress API.
				'total' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i AS u INNER JOIN %i AS p ON p.user_id = u.ID', $wpdb->users, $table ) ),
			);
		}

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
	 * @since 0.1.0
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
	 * @since 0.1.0
	 *
	 * @param int  $offset Users to skip.
	 * @param int  $limit  Maximum number of users.
	 * @param bool $all    Whether the viewer may list every user; otherwise only users who took part.
	 * @return array{ids: int[], total: int}
	 */
	private function special( int $offset, int $limit, bool $all ): array {
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

		$ids   = array_values( array_unique( $ids ) );
		$ids   = $all ? $ids : Services::points()->with_row( $ids );
		$users = array();
		foreach ( $ids as $id ) {
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
