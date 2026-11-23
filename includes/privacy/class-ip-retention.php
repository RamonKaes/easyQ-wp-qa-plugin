<?php
/**
 * Removal of stored IP addresses after the retention period.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Privacy;

use EasyQ\Database\DB;
use EasyQ\Database\Schema;
use EasyQ\Limits\Limits;
use EasyQ\Services;
use EasyQ\Settings\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Removes the IP addresses of posts, visitor cookies and rate limits once a day.
 *
 * The plugin stores IP addresses only to prevent abuse. After `ip_retention_days`
 * days they are removed: from posts and visitor cookies by date, and the counters
 * of the rate limits, which count the current hour only, with the next daily run once their
 * hour is over. Scheduled like
 * `wp_schedule_delete_old_privacy_export_files()`.
 *
 * @since 0.1.0
 */
final class IP_Retention {

	/**
	 * Hook of the scheduled event.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CRON_HOOK = 'easyq_delete_old_ips';

	/**
	 * Longest retention in days that the settings accept: a hundred years. More days than
	 * this do not fit into a timestamp once they are counted in seconds (`gmdate()` refuses
	 * the float), and the daily deletion would stop.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const MAX_DAYS = 36500;

	/**
	 * Hooks the event into WordPress.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_action( 'init', array( self::class, 'schedule' ) );
		add_action( self::CRON_HOOK, array( self::class, 'run' ) );
	}

	/**
	 * Schedules the daily event if it is not scheduled yet.
	 *
	 * @since 0.1.0
	 */
	public static function schedule(): void {
		if ( wp_installing() ) {
			return;
		}

		if ( false === wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Returns the retention period in days, within the limits of the setting also for a value
	 * stored before the limits existed.
	 *
	 * @since 0.1.0
	 *
	 * @return int
	 */
	public static function days(): int {
		return min( self::MAX_DAYS, max( 1, Options::get_int( 'spam', 'ip_retention_days' ) ) );
	}

	/**
	 * Removes the IP addresses stored longer than the retention period.
	 *
	 * @since 0.1.0
	 */
	public static function run(): void {
		// The data of sites deleted from the network while easyQ was not loaded goes too, see Uninstaller;
		// this site, which runs easyQ, is known as one of them, also if it was installed before the list.
		\EasyQ\Database\Installer::remember_site( get_current_blog_id() );
		\EasyQ\Uninstaller::remove_data_of_deleted_sites();

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::days() * DAY_IN_SECONDS );

		Services::posts()->clear_ips_before( $cutoff );

		$wpdb    = DB::wpdb();
		$cookies = Schema::table( 'cookies' );
		$sql     = $wpdb->prepare( 'UPDATE %i SET created_ip = NULL WHERE created_ip IS NOT NULL AND created < %s', $cookies, $cutoff );
		if ( null !== $sql ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; custom tables without a WordPress API.
			$wpdb->query( $sql );
		}
		$sql = $wpdb->prepare( 'UPDATE %i SET written_ip = NULL WHERE written_ip IS NOT NULL AND COALESCE(written, created) < %s', $cookies, $cutoff );
		if ( null !== $sql ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; custom tables without a WordPress API.
			$wpdb->query( $sql );
		}
		// A counter counts only within its hour, see Limits::used().
		$sql = $wpdb->prepare( 'DELETE FROM %i WHERE period < %d', Schema::table( 'iplimits' ), Limits::period() );
		if ( null !== $sql ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; custom tables without a WordPress API.
			$wpdb->query( $sql );
		}
	}
}
