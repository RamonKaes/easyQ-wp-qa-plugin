<?php
/**
 * IP address helpers.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Reads, converts, anonymizes and matches IP addresses.
 *
 * Block rule matching is ported from Question2Answer `qa-include/app/limits.php`.
 *
 * @since 0.1.0
 */
final class IP {

	/**
	 * Returns the IP address of the current request.
	 *
	 * Only REMOTE_ADDR is trusted. Sites behind a proxy can use the filter to
	 * return the client address from a trusted header.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null The address, or null if it is not available or invalid.
	 */
	public static function current(): ?string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		/**
		 * Filters the IP address of the current request.
		 *
		 * @since 0.1.0
		 *
		 * @param string $ip IP address taken from REMOTE_ADDR.
		 */
		$ip = apply_filters( 'easyq_client_ip', $ip );

		return is_string( $ip ) && false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : null;
	}

	/**
	 * Converts an IP address into its binary form for storage.
	 *
	 * @since 0.1.0
	 *
	 * @param string|null $ip IP address.
	 * @return string|null Packed address, or null for an empty or invalid address.
	 */
	public static function to_binary( ?string $ip ): ?string {
		if ( null === $ip || false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return null;
		}

		$packed = inet_pton( $ip );

		return false === $packed ? null : $packed;
	}

	/**
	 * Converts a stored binary IP address back into its text form.
	 *
	 * @since 0.1.0
	 *
	 * @param string|null $binary Packed address.
	 * @return string|null
	 */
	public static function from_binary( ?string $binary ): ?string {
		if ( null === $binary || '' === $binary ) {
			return null;
		}

		// Only IPv4 (4 bytes) and IPv6 (16 bytes) addresses can be converted; other values are reported as null.
		if ( 4 !== strlen( $binary ) && 16 !== strlen( $binary ) ) {
			return null;
		}

		$ip = inet_ntop( $binary );

		return false === $ip ? null : $ip;
	}

	/**
	 * Removes the host part of an IP address, as WordPress does for privacy exports.
	 *
	 * @since 0.1.0
	 *
	 * @param string $ip IP address.
	 * @return string
	 */
	public static function anonymize( string $ip ): string {
		return function_exists( 'wp_privacy_anonymize_ip' ) ? wp_privacy_anonymize_ip( $ip ) : $ip;
	}

	/**
	 * Splits a block list into its rules.
	 *
	 * @since 0.1.0
	 *
	 * @param string $rules Rules separated by spaces, commas or new lines, e.g. `1.2.3.4`, `1.2.*.*` or `1.2.3.4 - 1.2.3.9`.
	 * @return string[]
	 */
	public static function explode_rules( string $rules ): array {
		$rules = (string) preg_replace( '/\s*\-\s*/', '-', $rules );
		$list  = preg_split( '/[^0-9A-Fa-f\.:\-\*]/', $rules, -1, PREG_SPLIT_NO_EMPTY );

		return false === $list ? array() : $list;
	}

	/**
	 * Checks whether an IP address is matched by any rule of a block list.
	 *
	 * @since 0.1.0
	 *
	 * @param string|null $ip    IP address.
	 * @param string      $rules Block list, see explode_rules().
	 * @return bool
	 */
	public static function is_blocked( ?string $ip, string $rules ): bool {
		if ( null === $ip ) {
			return false;
		}

		foreach ( self::explode_rules( $rules ) as $rule ) {
			if ( self::matches( $ip, $rule ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Checks whether an IP address is matched by a single rule with an optional range or wildcards.
	 *
	 * @since 0.1.0
	 *
	 * @param string $ip   IP address.
	 * @param string $rule Rule.
	 * @return bool
	 */
	public static function matches( string $ip, string $rule ): bool {
		$ip_is_v4   = false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 );
		$rule_is_v4 = false !== filter_var( $rule, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 );

		if ( $ip_is_v4 && $rule_is_v4 ) {
			return $ip === $rule;
		}

		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) || '' === $rule ) {
			return false;
		}

		if ( preg_match( '/^(.*)\-(.*)$/', $rule, $range ) ) {
			return false !== filter_var( $range[1], FILTER_VALIDATE_IP )
				&& false !== filter_var( $range[2], FILTER_VALIDATE_IP )
				&& self::between( $ip, $range[1], $range[2] );
		}

		if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$ip   = self::expand_ipv6( $ip );
			$rule = self::expand_ipv6( $rule );
		}

		return preg_match( '/^' . str_replace( '\\*', '([0-9A-Fa-f]+)', preg_quote( $rule, '/' ) ) . '$/', $ip ) > 0;
	}

	/**
	 * Checks whether an IP address lies within a range, byte by byte.
	 *
	 * @since 0.1.0
	 *
	 * @param string $ip    IP address.
	 * @param string $start First address of the range.
	 * @param string $end   Last address of the range.
	 * @return bool
	 */
	public static function between( string $ip, string $start, string $end ): bool {
		$bytes       = self::bytes( $ip );
		$start_bytes = self::bytes( $start );
		$end_bytes   = self::bytes( $end );

		if ( count( $bytes ) !== count( $start_bytes ) || count( $bytes ) !== count( $end_bytes ) || array() === $bytes ) {
			return false;
		}

		foreach ( $bytes as $index => $byte ) {
			if ( $byte < $start_bytes[ $index ] || $byte > $end_bytes[ $index ] ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Expands an IPv6 address, which may contain `*` wildcards, to eight groups of four digits.
	 *
	 * @since 0.1.0
	 *
	 * @param string $ip IPv6 address, e.g. `::ffff:1`.
	 * @return string Expanded address, e.g. `0000:0000:0000:0000:0000:0000:ffff:0001`.
	 */
	public static function expand_ipv6( string $ip ): string {
		$wildcards = array();
		$groups    = explode( ':', $ip );

		if ( str_contains( $ip, '*' ) ) {
			foreach ( $groups as $index => $group ) {
				if ( '*' === $group ) {
					// Remember positions from the end, since "::" may expand in front of them.
					$wildcards[]      = count( $groups ) - 1 - $index;
					$groups[ $index ] = '0';
				}
			}
		}

		// Invalid rules are kept as they are and simply do not match.
		$address = implode( ':', $groups );
		$packed  = false === filter_var( $address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ? false : inet_pton( $address );
		if ( false === $packed ) {
			return $ip;
		}

		$expanded = str_split( bin2hex( $packed ), 4 );

		foreach ( $wildcards as $from_end ) {
			$expanded[ count( $expanded ) - 1 - $from_end ] = '*';
		}

		return implode( ':', $expanded );
	}

	/**
	 * Returns the bytes of an IP address.
	 *
	 * @since 0.1.0
	 *
	 * @param string $ip IP address.
	 * @return int[]
	 */
	private static function bytes( string $ip ): array {
		// Invalid addresses yield no bytes.
		$packed = false === filter_var( $ip, FILTER_VALIDATE_IP ) ? false : inet_pton( $ip );
		if ( false === $packed ) {
			return array();
		}

		$bytes = unpack( 'C*', $packed );

		$result = array();
		foreach ( false === $bytes ? array() : $bytes as $byte ) {
			$result[] = is_int( $byte ) ? $byte : 0;
		}

		return $result;
	}
}
