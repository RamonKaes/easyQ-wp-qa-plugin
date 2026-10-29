<?php
/**
 * Figures of the dashboard.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Stats;

use EasyQ\Database\DB;
use EasyQ\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Counts what the Question2Answer page `admin/stats` shows beyond the cached counts:
 * posts by visitors without an account, users who posted or voted, and the size of
 * the easyQ tables. These are counted when the dashboard is opened.
 *
 * @since 0.1.0
 */
final class Site_Stats {

	/**
	 * Counts the visible posts of a type by visitors without an account.
	 *
	 * @since 0.1.0
	 *
	 * @param string $type `Q`, `A` or `C`.
	 * @return int
	 */
	public static function anonymous( string $type ): int {
		$wpdb = DB::wpdb();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables without a WordPress API.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE type = %s AND user_id IS NULL', Schema::table( 'posts' ), $type ) );
	}

	/**
	 * Counts the users who wrote a question, answer or comment.
	 *
	 * @since 0.1.0
	 *
	 * @return int
	 */
	public static function users_who_posted(): int {
		$wpdb = DB::wpdb();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables without a WordPress API.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT user_id) FROM %i WHERE user_id IS NOT NULL', Schema::table( 'posts' ) ) );
	}

	/**
	 * Counts the users who voted.
	 *
	 * @since 0.1.0
	 *
	 * @return int
	 */
	public static function users_who_voted(): int {
		$wpdb = DB::wpdb();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables without a WordPress API.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT user_id) FROM %i WHERE vote <> 0', Schema::table( 'uservotes' ) ) );
	}

	/**
	 * Returns the size of the easyQ tables of the site, with their indexes.
	 *
	 * @since 0.1.0
	 *
	 * @return int Bytes.
	 */
	public static function tables_size(): int {
		$wpdb = DB::wpdb();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The size of tables has no WordPress API.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.TABLES WHERE table_schema = DATABASE() AND FIND_IN_SET(table_name, %s)',
				implode( ',', Schema::table_names( $wpdb->prefix ) )
			)
		);
	}
}
