<?php
/**
 * Integers read from requests and block attributes.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Reads IDs and page numbers that arrive as strings, floats or anything else.
 *
 * `absint()` returns a float for the smallest integer, and a cast of a float beyond the
 * integer range is a warning on PHP 8.5. Every integer that comes from an address, a form
 * or a block attribute goes through here, so a value out of range is refused instead of
 * ending in a `TypeError`.
 *
 * @since 0.1.0
 */
final class Number {

	/**
	 * Highest page number a list accepts.
	 *
	 * Large enough for any list, small enough that the offset of a page of a thousand items
	 * stays an integer.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const MAX_PAGE = 1000000000;

	/**
	 * Returns a whole number in a range, or null.
	 *
	 * Accepts integers, strings of digits with an optional sign, and floats without a
	 * fraction; everything outside `$min` to `$max` is null.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value Value to read.
	 * @param int   $min   Smallest value accepted.
	 * @param int   $max   Largest value accepted.
	 * @return int|null
	 */
	public static function int_in_range( mixed $value, int $min, int $max = PHP_INT_MAX ): ?int {
		if ( is_float( $value ) ) {
			// Only floats below 2^53 hold a whole number exactly; a cast of a larger one may leave the integer range.
			$value = is_finite( $value ) && abs( $value ) < 9007199254740992.0 && floor( $value ) === $value ? (int) $value : null;
		}

		if ( is_string( $value ) ) {
			// Leading zeros are allowed, as `absint()` allows them; `filter_var()` refuses anything beyond the integer range.
			$value = 1 === preg_match( '/^\s*([+-]?)0*(\d+)\s*$/', $value, $parts ) ? filter_var( $parts[1] . $parts[2], FILTER_VALIDATE_INT ) : null;
		}

		return is_int( $value ) && $value >= $min && $value <= $max ? $value : null;
	}

	/**
	 * Returns a whole number limited to a range, or the default for anything else.
	 *
	 * For block attributes and arguments: a value beyond the integer range, or no number at
	 * all, gives the default; a number outside `$min` to `$max` is moved to the nearest end.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value   Value to read.
	 * @param int   $fallback Value for anything that is not a whole number.
	 * @param int   $min     Smallest value.
	 * @param int   $max     Largest value.
	 * @return int
	 */
	public static function clamp( mixed $value, int $fallback, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX ): int {
		$number = self::int_in_range( $value, PHP_INT_MIN );

		return null === $number ? $fallback : max( $min, min( $max, $number ) );
	}

	/**
	 * Returns a positive ID, or 0.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value Value to read.
	 * @return int
	 */
	public static function id( mixed $value ): int {
		return self::int_in_range( $value, 1 ) ?? 0;
	}

	/**
	 * Returns a page number: 1 for anything that is not a page, `MAX_PAGE` for a larger one.
	 *
	 * A page beyond the last one shows an empty page, as in core; a page beyond `MAX_PAGE`
	 * has no items either.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value Value to read.
	 * @return int
	 */
	public static function page( mixed $value ): int {
		$page = self::int_in_range( $value, 1, self::MAX_PAGE );
		if ( null !== $page ) {
			return $page;
		}

		return is_string( $value ) && 1 === preg_match( '/^\s*\+?0*[1-9]\d*\s*$/', $value ) ? self::MAX_PAGE : 1;
	}
}
