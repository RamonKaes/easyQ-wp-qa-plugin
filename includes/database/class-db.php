<?php
/**
 * Database access.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Typed access to the WordPress database object and to the custom tables.
 *
 * Queries on the plugin's own tables cannot use the WordPress query APIs. Callers
 * run them on the database object with wpdb::prepare() at the place of the call,
 * so the coding standards check every statement; these methods only give the
 * results their types.
 *
 * @since 0.1.0
 */
final class DB {

	/**
	 * Attempts of a query of derived data that MySQL breaks off in a deadlock.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const DEADLOCK_ATTEMPTS = 3;

	/**
	 * Returns the WordPress database object.
	 *
	 * @since 0.1.0
	 *
	 * @return \wpdb
	 */
	public static function wpdb(): \wpdb {
		/**
		 * The WordPress database object, set up by WordPress before any plugin loads.
		 *
		 * @var \wpdb $wpdb
		 */
		global $wpdb;

		return $wpdb;
	}

	/**
	 * Returns the current time in UTC, in MySQL format.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public static function now(): string {
		return current_time( 'mysql', true );
	}

	/**
	 * Returns the row of a wpdb::get_row() call.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $row Result of wpdb::get_row().
	 * @return object|null
	 */
	public static function row( mixed $row ): ?object {
		return is_object( $row ) ? $row : null;
	}

	/**
	 * Returns the rows of a wpdb::get_results() call.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $rows Result of wpdb::get_results().
	 * @return object[]
	 */
	public static function rows( mixed $rows ): array {
		return is_array( $rows ) ? array_filter( $rows, 'is_object' ) : array();
	}

	/**
	 * Returns the values of a wpdb::get_col() call as integers.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $values Result of wpdb::get_col().
	 * @return int[]
	 */
	public static function int_col( mixed $values ): array {
		return is_array( $values ) ? array_map( 'intval', array_filter( $values, 'is_numeric' ) ) : array();
	}

	/**
	 * Inserts a row.
	 *
	 * @since 0.1.0
	 *
	 * @param string                    $table  Full table name.
	 * @param array<string, mixed>      $data   Column values.
	 * @param array<int|string, string> $format Placeholders for the values, see wpdb::insert().
	 * @return int The ID generated for an auto-increment column, 0 if there is none or on failure.
	 */
	public static function insert( string $table, array $data, array $format ): int {
		$wpdb = self::wpdb();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table.
		if ( false === $wpdb->insert( $table, $data, $format ) ) {
			return 0;
		}

		return $wpdb->insert_id;
	}

	/**
	 * Runs a prepared query that only recalculates derived data, once more if MySQL chose it as
	 * the victim of a deadlock.
	 *
	 * Counts and the hotness are updated by many requests at once (a busy question); InnoDB then
	 * breaks a lock cycle by rolling one statement back. Repeating the statement is what MySQL
	 * advises for it (`Deadlock found when trying to get lock; try restarting transaction`).
	 *
	 * @since 0.1.0
	 *
	 * @param string|null $sql Prepared query, or null if preparing failed.
	 */
	public static function query_derived( ?string $sql ): void {
		if ( null === $sql ) {
			// Not reachable: the callers prepare with matching placeholders.
			return;
		}

		$wpdb = self::wpdb();
		for ( $attempt = 1; $attempt <= self::DEADLOCK_ATTEMPTS; $attempt++ ) {
			// The error of an attempt that is repeated goes unreported; the last one is logged as usual.
			$suppress = $wpdb->suppress_errors( $attempt < self::DEADLOCK_ATTEMPTS );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared by the caller; custom tables without a WordPress API.
			$result = $wpdb->query( $sql );
			$wpdb->suppress_errors( $suppress );

			if ( false !== $result || ! str_contains( $wpdb->last_error, 'Deadlock' ) ) {
				return;
			}
		}
	}
}
