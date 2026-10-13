<?php
/**
 * Data removal on uninstall.
 *
 * @package easyQ
 */

namespace EasyQ;

use EasyQ\Database\Db;
use EasyQ\Database\Installer;
use EasyQ\Database\Schema;
use EasyQ\Settings\Options;
use EasyQ\Stats\Counts;

defined( 'ABSPATH' ) || exit;

/**
 * Removes the plugin data when the plugin is deleted.
 *
 * Data is only removed from sites whose owner enabled "Delete data on uninstall".
 */
final class Uninstaller {

	/**
	 * Prefix of all user meta keys written by the plugin.
	 *
	 * @var string
	 */
	public const USER_META_PREFIX = 'easyq_';

	/**
	 * Removes the data of every site that opted in.
	 */
	public static function run(): void {
		if ( ! is_multisite() ) {
			if ( self::site_opted_in() ) {
				self::uninstall_site();
				self::delete_user_meta();
			}
			return;
		}

		$site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);

		$all_opted_in = true;
		foreach ( $site_ids as $site_id ) {
			switch_to_blog( (int) $site_id );
			if ( self::site_opted_in() ) {
				self::uninstall_site();
			} else {
				$all_opted_in = false;
			}
			restore_current_blog();
		}

		// User meta is shared by all sites of a network; keep it while any site keeps its data.
		if ( $all_opted_in ) {
			self::delete_user_meta();
		}
	}

	/**
	 * Checks whether the current site asked for its data to be removed.
	 *
	 * @return bool
	 */
	public static function site_opted_in(): bool {
		return (bool) Options::get( 'general', 'delete_data_on_uninstall' );
	}

	/**
	 * Removes tables and options of the current site.
	 */
	public static function uninstall_site(): void {
		Schema::drop();
		Options::delete_all();
		delete_option( Installer::DB_VERSION_OPTION );
		delete_option( Counts::OPTION );
	}

	/**
	 * Removes all user meta written by the plugin.
	 */
	public static function delete_user_meta(): void {
		$wpdb = Db::wpdb();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off cleanup on uninstall; no API deletes meta by key prefix.
		$keys = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT meta_key FROM %i WHERE meta_key LIKE %s',
				$wpdb->usermeta,
				$wpdb->esc_like( self::USER_META_PREFIX ) . '%'
			)
		);

		foreach ( array_filter( $keys, 'is_string' ) as $key ) {
			delete_metadata( 'user', 0, $key, '', true );
		}
	}
}
