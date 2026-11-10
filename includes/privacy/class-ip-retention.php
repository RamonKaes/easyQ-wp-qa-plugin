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
 * days they are removed: from posts and visitor cookies by date, while the
 * counters of the rate limits go as soon as their hour is over. Scheduled like
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
	 * Removes the IP addresses stored longer than the retention period.
	 *
	 * @since 0.1.0
	 */
	public static function run(): void {
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 1, Options::get_int( 'spam', 'ip_retention_days' ) ) * DAY_IN_SECONDS );

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
