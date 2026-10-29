<?php
/**
 * Blocked IP addresses.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Limits;

use EasyQ\Actor;
use EasyQ\Database\DB;
use EasyQ\Database\Schema;
use EasyQ\Events\Events;
use EasyQ\Settings\Options;
use EasyQ\Util\IP;

defined( 'ABSPATH' ) || exit;

/**
 * Blocks and unblocks single IP addresses through the block list of the spam
 * settings, as the Question2Answer page `ip/{ip}` does.
 *
 * @since 0.1.0
 */
final class IP_Blocks {

	/**
	 * Returns the rules of the block list that match an IP address.
	 *
	 * @since 0.1.0
	 *
	 * @param string $ip IP address.
	 * @return string[]
	 */
	public function matching_rules( string $ip ): array {
		return array_values( array_filter( IP::explode_rules( Options::get_string( 'spam', 'block_ips_write' ) ), static fn ( string $rule ): bool => IP::matches( $ip, $rule ) ) );
	}

	/**
	 * Adds an IP address to the block list, unless a rule already matches it.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor  $actor Who blocks the address.
	 * @param string $ip    IP address.
	 */
	public function block( Actor $actor, string $ip ): void {
		if ( array() !== $this->matching_rules( $ip ) ) {
			return;
		}

		$rules   = IP::explode_rules( Options::get_string( 'spam', 'block_ips_write' ) );
		$rules[] = $ip;
		Options::update( 'spam', array( 'block_ips_write' => implode( ', ', $rules ) ) );

		Events::dispatch( 'ip_block', array( 'ip' => $ip ), $actor );
	}

	/**
	 * Removes every rule that matches an IP address from the block list.
	 *
	 * As in Question2Answer, a range or wildcard rule that matches the address is
	 * removed as a whole, which unblocks the other addresses it covers too.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor  $actor Who unblocks the address.
	 * @param string $ip    IP address.
	 * @return string[] The removed rules.
	 */
	public function unblock( Actor $actor, string $ip ): array {
		$removed = $this->matching_rules( $ip );
		if ( array() === $removed ) {
			return array();
		}

		$rules = array_diff( IP::explode_rules( Options::get_string( 'spam', 'block_ips_write' ) ), $removed );
		Options::update( 'spam', array( 'block_ips_write' => implode( ', ', $rules ) ) );

		Events::dispatch( 'ip_unblock', array( 'ip' => $ip ), $actor );

		return $removed;
	}

	/**
	 * Returns the visible questions, answers and comments created from an IP address.
	 *
	 * @since 0.1.0
	 *
	 * @param string $ip IP address.
	 * @return int[] Post IDs, comments and answers before their questions.
	 */
	public function visible_post_ids( string $ip ): array {
		$wpdb   = DB::wpdb();
		$binary = IP::to_binary( $ip );
		if ( null === $binary ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables without a WordPress API.
		return DB::int_col( $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE created_ip = %s AND type IN ('Q', 'A', 'C') ORDER BY FIELD(type, 'C', 'A', 'Q'), id", Schema::table( 'posts' ), $binary ) ) );
	}
}
