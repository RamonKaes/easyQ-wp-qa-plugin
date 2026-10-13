<?php
/**
 * Typed access to database rows.
 *
 * @package easyQ
 */

namespace EasyQ\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Reads typed values from rows returned by wpdb.
 *
 * The wpdb object returns every column as a string or null; these helpers convert them
 * and fall back to a default for missing or unexpected values.
 */
final class Row {

	/**
	 * Returns a column as an integer.
	 *
	 * @param object $row Database row.
	 * @param string $key Column name.
	 * @return int 0 if the column is missing or not numeric.
	 */
	public static function int( object $row, string $key ): int {
		return self::nullable_int( $row, $key ) ?? 0;
	}

	/**
	 * Returns a column as an integer or null.
	 *
	 * @param object $row Database row.
	 * @param string $key Column name.
	 * @return int|null Null if the column is missing, null or not numeric.
	 */
	public static function nullable_int( object $row, string $key ): ?int {
		$value = get_object_vars( $row )[ $key ] ?? null;

		return is_numeric( $value ) ? (int) $value : null;
	}

	/**
	 * Returns a column as a float or null.
	 *
	 * @param object $row Database row.
	 * @param string $key Column name.
	 * @return float|null Null if the column is missing, null or not numeric.
	 */
	public static function nullable_float( object $row, string $key ): ?float {
		$value = get_object_vars( $row )[ $key ] ?? null;

		return is_numeric( $value ) ? (float) $value : null;
	}

	/**
	 * Returns a column as a string.
	 *
	 * @param object $row Database row.
	 * @param string $key Column name.
	 * @return string Empty if the column is missing or null.
	 */
	public static function string( object $row, string $key ): string {
		return self::nullable_string( $row, $key ) ?? '';
	}

	/**
	 * Returns a column as a string or null.
	 *
	 * @param object $row Database row.
	 * @param string $key Column name.
	 * @return string|null Null if the column is missing or null.
	 */
	public static function nullable_string( object $row, string $key ): ?string {
		$value = get_object_vars( $row )[ $key ] ?? null;

		return is_scalar( $value ) ? (string) $value : null;
	}
}
