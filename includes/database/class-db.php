<?php
/**
 * Database access.
 *
 * @package easyQ
 */

namespace EasyQ\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Typed access to the WordPress database object and to the custom tables.
 *
 * Queries on the plugin's own tables cannot use the WordPress query APIs, so they
 * run through these methods. Callers build every statement with wpdb::prepare();
 * the methods accept null, which wpdb::prepare() returns for invalid statements,
 * and then do nothing.
 */
final class Db {

	/**
	 * Returns the WordPress database object.
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
	 * @return string
	 */
	public static function now(): string {
		return current_time( 'mysql', true );
	}

	/**
	 * Runs a prepared statement that returns no rows.
	 *
	 * @param string|null $sql Prepared statement.
	 * @return int Number of affected rows, 0 on failure.
	 */
	public static function query( ?string $sql ): int {
		if ( null === $sql ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared by the caller; custom tables.
		$result = self::wpdb()->query( $sql );

		return is_int( $result ) ? $result : 0;
	}

	/**
	 * Returns a single value.
	 *
	 * @param string|null $sql Prepared statement.
	 * @return string|null
	 */
	public static function get_var( ?string $sql ): ?string {
		if ( null === $sql ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared by the caller; custom tables.
		$value = self::wpdb()->get_var( $sql );

		return is_scalar( $value ) ? (string) $value : null;
	}

	/**
	 * Returns a single value as an integer.
	 *
	 * @param string|null $sql Prepared statement.
	 * @return int
	 */
	public static function get_int( ?string $sql ): int {
		return (int) self::get_var( $sql );
	}

	/**
	 * Returns a single row.
	 *
	 * @param string|null $sql Prepared statement.
	 * @return object|null
	 */
	public static function get_row( ?string $sql ): ?object {
		if ( null === $sql ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared by the caller; custom tables.
		$row = self::wpdb()->get_row( $sql );

		return is_object( $row ) ? $row : null;
	}

	/**
	 * Returns all rows.
	 *
	 * @param string|null $sql Prepared statement.
	 * @return object[]
	 */
	public static function get_results( ?string $sql ): array {
		if ( null === $sql ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared by the caller; custom tables.
		$rows = self::wpdb()->get_results( $sql );

		return is_array( $rows ) ? array_filter( $rows, 'is_object' ) : array();
	}

	/**
	 * Returns the first column of all rows as integers.
	 *
	 * @param string|null $sql Prepared statement.
	 * @return int[]
	 */
	public static function get_int_col( ?string $sql ): array {
		if ( null === $sql ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared by the caller; custom tables.
		$values = self::wpdb()->get_col( $sql );

		return array_map( 'intval', array_filter( $values, 'is_numeric' ) );
	}

	/**
	 * Inserts a row.
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
}
