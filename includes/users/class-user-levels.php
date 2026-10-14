<?php
/**
 * Q&A levels of users.
 *
 * @package easyQ
 */

namespace EasyQ\Users;

use EasyQ\Actor;
use EasyQ\Database\Db;
use EasyQ\Database\Row;
use EasyQ\Database\Schema;
use EasyQ\Events\Events;
use EasyQ\Posts\Post;
use EasyQ\Settings\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Determines and changes the Q&A level of users.
 *
 * The level of a user is the highest of:
 *
 * - the level mapped to any of their WordPress roles,
 * - the level set for them in their profile,
 * - Approved, if a moderator approved them,
 * - Super administrator for network administrators on multisite.
 *
 * Within a category, a user can have a higher level, e.g. be an expert only
 * for one topic. Category levels also apply to all subcategories.
 */
final class User_Levels {

	/**
	 * User meta key of the level set for a user.
	 *
	 * @var string
	 */
	public const LEVEL_META = 'easyq_level';

	/**
	 * Entity type of category levels in the userlevels table.
	 *
	 * @var string
	 */
	private const ENTITY_CATEGORY = 'C';

	/**
	 * Returns the level of a user, without category levels.
	 *
	 * @param int|null $user_id User ID, or null for visitors.
	 * @return int|null The level, or null for visitors.
	 */
	public function level( ?int $user_id ): ?int {
		if ( null === $user_id || $user_id <= 0 ) {
			return null;
		}

		$user = get_userdata( $user_id );
		if ( false === $user ) {
			return null;
		}

		if ( is_multisite() && is_super_admin( $user_id ) ) {
			return Levels::SUPER;
		}

		$level    = Levels::BASIC;
		$mapping  = $this->role_levels();
		$assigned = Site_Meta::get( $user_id, self::LEVEL_META );

		foreach ( $user->roles as $role ) {
			$level = max( $level, $mapping[ $role ] ?? Levels::BASIC );
		}

		if ( is_numeric( $assigned ) ) {
			$level = max( $level, (int) $assigned );
		}

		if ( User_Status::is_approved( $user_id ) ) {
			$level = max( $level, Levels::APPROVED );
		}

		return $level;
	}

	/**
	 * Returns the level of a user within categories.
	 *
	 * @param int|null $user_id      User ID, or null for visitors.
	 * @param int[]    $category_ids Categories, usually a category and its ancestors.
	 * @return int|null The level, or null for visitors.
	 */
	public function level_for_categories( ?int $user_id, array $category_ids ): ?int {
		$level = $this->level( $user_id );
		if ( null === $level || null === $user_id || array() === $category_ids ) {
			return $level;
		}

		foreach ( $this->category_levels( $user_id ) as $category_id => $category_level ) {
			if ( in_array( $category_id, $category_ids, true ) ) {
				$level = max( $level, $category_level );
			}
		}

		return $level;
	}

	/**
	 * Returns the level of a user for a post, taking its category into account.
	 *
	 * @param int|null $user_id User ID, or null for visitors.
	 * @param Post     $post    Post.
	 * @return int|null
	 */
	public function level_for_post( ?int $user_id, Post $post ): ?int {
		$category_ids = array_values( array_filter( array_merge( array( $post->category_id ), $post->category_path ) ) );

		return $this->level_for_categories( $user_id, array_map( 'intval', $category_ids ) );
	}

	/**
	 * Returns the highest level a user has anywhere, including category levels.
	 *
	 * @param int|null $user_id User ID, or null for visitors.
	 * @return int|null
	 */
	public function maximum_level( ?int $user_id ): ?int {
		$level = $this->level( $user_id );
		if ( null === $level || null === $user_id ) {
			return $level;
		}

		$category_levels = $this->category_levels( $user_id );

		return array() === $category_levels ? $level : max( $level, max( $category_levels ) );
	}

	/**
	 * Returns the level set for a user in their profile.
	 *
	 * @param int $user_id User ID.
	 * @return int|null Null if no level is set.
	 */
	public function assigned_level( int $user_id ): ?int {
		$level = Site_Meta::get( $user_id, self::LEVEL_META );

		return is_numeric( $level ) ? (int) $level : null;
	}

	/**
	 * Sets the level of a user, or removes it.
	 *
	 * @param Actor    $actor   Who changes the level.
	 * @param int      $user_id User ID.
	 * @param int|null $level   New level, or null to use the role mapping only.
	 */
	public function set_level( Actor $actor, int $user_id, ?int $level ): void {
		$old = $this->assigned_level( $user_id );
		if ( $old === $level || ( null !== $level && ! Levels::is_valid( $level ) ) ) {
			return;
		}

		if ( null === $level ) {
			Site_Meta::delete( $user_id, self::LEVEL_META );
		} else {
			Site_Meta::update( $user_id, self::LEVEL_META, $level );
		}

		Events::dispatch(
			'u_level',
			array(
				'user_id'   => $user_id,
				'level'     => $level,
				'old_level' => $old,
			),
			$actor
		);
	}

	/**
	 * Returns the category levels of a user.
	 *
	 * @param int $user_id User ID.
	 * @return array<int, int> Levels keyed by category ID.
	 */
	public function category_levels( int $user_id ): array {
		$cached = wp_cache_get( $user_id, 'easyq_userlevels' );
		if ( is_array( $cached ) ) {
			$levels = array();
			foreach ( $cached as $category_id => $level ) {
				if ( is_int( $category_id ) && is_int( $level ) ) {
					$levels[ $category_id ] = $level;
				}
			}

			return $levels;
		}

		$rows = Db::get_results(
			Db::wpdb()->prepare(
				'SELECT entity_id, level FROM %i WHERE user_id = %d AND entity_type = %s AND level IS NOT NULL',
				Schema::table( 'userlevels' ),
				$user_id,
				self::ENTITY_CATEGORY
			)
		);

		$levels = array();
		foreach ( $rows as $row ) {
			$levels[ Row::int( $row, 'entity_id' ) ] = Row::int( $row, 'level' );
		}

		wp_cache_set( $user_id, $levels, 'easyq_userlevels' );

		return $levels;
	}

	/**
	 * Sets the level of a user within a category, or removes it.
	 *
	 * @param Actor    $actor       Who changes the level.
	 * @param int      $user_id     User ID.
	 * @param int      $category_id Category ID.
	 * @param int|null $level       New level, or null to remove it.
	 */
	public function set_category_level( Actor $actor, int $user_id, int $category_id, ?int $level ): void {
		$wpdb  = Db::wpdb();
		$table = Schema::table( 'userlevels' );

		if ( null === $level || ! Levels::is_valid( $level ) ) {
			Db::query( $wpdb->prepare( 'DELETE FROM %i WHERE user_id = %d AND entity_type = %s AND entity_id = %d', $table, $user_id, self::ENTITY_CATEGORY, $category_id ) );
		} else {
			Db::query(
				$wpdb->prepare(
					'INSERT INTO %i (user_id, entity_type, entity_id, level) VALUES (%d, %s, %d, %d) ON DUPLICATE KEY UPDATE level = %d',
					$table,
					$user_id,
					self::ENTITY_CATEGORY,
					$category_id,
					$level,
					$level
				)
			);
		}

		wp_cache_delete( $user_id, 'easyq_userlevels' );

		Events::dispatch(
			'u_level',
			array(
				'user_id'     => $user_id,
				'category_id' => $category_id,
				'level'       => $level,
			),
			$actor
		);
	}

	/**
	 * Returns the users with a special level: an assigned level of expert or higher, or a category level.
	 *
	 * @return int[] User IDs.
	 */
	public function special_user_ids(): array {
		$wpdb = Db::wpdb();

		$assigned = Db::get_int_col(
			$wpdb->prepare( 'SELECT user_id FROM %i WHERE meta_key = %s AND CAST(meta_value AS UNSIGNED) >= %d', $wpdb->usermeta, Site_Meta::key( self::LEVEL_META ), Levels::EXPERT )
		);
		$category = Db::get_int_col(
			$wpdb->prepare( 'SELECT DISTINCT user_id FROM %i WHERE level IS NOT NULL', Schema::table( 'userlevels' ) )
		);

		return array_values( array_unique( array_merge( $assigned, $category ) ) );
	}

	/**
	 * Returns the level of each role from the settings.
	 *
	 * @return array<string, int>
	 */
	public function role_levels(): array {
		$stored = Options::get( 'general', 'role_levels' );
		$levels = array();

		foreach ( is_array( $stored ) ? $stored : array() as $role => $level ) {
			if ( is_string( $role ) && is_numeric( $level ) && Levels::is_valid( (int) $level ) ) {
				$levels[ $role ] = (int) $level;
			}
		}

		return $levels;
	}
}
