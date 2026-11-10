<?php
/**
 * Data removal on uninstall.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ;

use EasyQ\Database\DB;
use EasyQ\Database\Installer;
use EasyQ\Database\Schema;
use EasyQ\Frontend\Router;
use EasyQ\Mail\Templates;
use EasyQ\Newsletter\Newsletter;
use EasyQ\Privacy\IP_Retention;
use EasyQ\Settings\Options;
use EasyQ\Stats\Counts;
use EasyQ\Updates\Updates;
use EasyQ\Users\Avatars;
use EasyQ\Users\Site_Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Removes the plugin data when the plugin is deleted.
 *
 * Data is only removed from sites whose owner enabled "Delete data on uninstall".
 *
 * @since 0.1.0
 */
final class Uninstaller {

	/**
	 * Prefix of all user meta keys written by the plugin.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const USER_META_PREFIX = 'easyq_';

	/**
	 * Prefix of the names of all transients.
	 *
	 * @var string
	 */
	private const TRANSIENT_PREFIX = 'easyq_';

	/**
	 * Removes the data of every site that opted in.
	 *
	 * @since 0.1.0
	 */
	public static function run(): void {
		if ( ! is_multisite() ) {
			self::clear_scheduled_events();
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
			self::clear_scheduled_events();
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
	 * Removes the scheduled events of the current site, whether or not it keeps its data.
	 *
	 * Deactivation removes them too, but skips the sites of a large network.
	 *
	 * @since 0.1.0
	 */
	private static function clear_scheduled_events(): void {
		wp_clear_scheduled_hook( Newsletter::CRON_HOOK );
		wp_clear_scheduled_hook( IP_Retention::CRON_HOOK );
	}

	/**
	 * Checks whether the current site asked for its data to be removed.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public static function site_opted_in(): bool {
		return (bool) Options::get( 'general', 'delete_data_on_uninstall' );
	}

	/**
	 * Removes tables and options of the current site.
	 *
	 * @since 0.1.0
	 */
	public static function uninstall_site(): void {
		Schema::drop();
		Options::delete_all();
		delete_option( Templates::OPTION );
		delete_option( Installer::DB_VERSION_OPTION );
		delete_option( Counts::OPTION );
		delete_option( Router::RULES_OPTION );
		delete_option( Updates::THRESHOLD_OPTION );
		Newsletter::cancel();
		self::delete_transients();
		self::delete_site_user_meta();
		// The mark of uploaded avatars; the images stay in the Media Library, like other uploads.
		delete_post_meta_by_key( Avatars::ATTACHMENT_META );
	}

	/**
	 * Removes the transients of the current site: messages after redirects and counted views.
	 *
	 * Their names hold tokens, so `delete_transient()` cannot find them; see `delete_expired_transients()`.
	 *
	 * @since 0.1.0
	 */
	private static function delete_transients(): void {
		$wpdb = DB::wpdb();

		$sql = $wpdb->prepare(
			'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s',
			$wpdb->options,
			$wpdb->esc_like( '_transient_' . self::TRANSIENT_PREFIX ) . '%',
			$wpdb->esc_like( '_transient_timeout_' . self::TRANSIENT_PREFIX ) . '%'
		);
		if ( null !== $sql ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- One-off cleanup on uninstall; no API deletes transients by prefix. Prepared above.
			$wpdb->query( $sql );
		}
		wp_cache_delete( 'alloptions', 'options' );
	}

	/**
	 * Removes the user meta of the current site, such as levels and blocks.
	 *
	 * @since 0.1.0
	 */
	public static function delete_site_user_meta(): void {
		self::delete_meta_with_prefix( Site_Meta::key( self::USER_META_PREFIX ) );
	}

	/**
	 * Removes all user meta written by the plugin.
	 *
	 * @since 0.1.0
	 */
	public static function delete_user_meta(): void {
		self::delete_meta_with_prefix( self::USER_META_PREFIX );
	}

	/**
	 * Deletes the user meta of all users whose key starts with a prefix.
	 *
	 * @since 0.1.0
	 *
	 * @param string $prefix Meta key prefix.
	 */
	private static function delete_meta_with_prefix( string $prefix ): void {
		$wpdb = DB::wpdb();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off cleanup on uninstall; no API deletes meta by key prefix.
		$keys = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT meta_key FROM %i WHERE meta_key LIKE %s',
				$wpdb->usermeta,
				$wpdb->esc_like( $prefix ) . '%'
			)
		);

		foreach ( array_filter( $keys, 'is_string' ) as $key ) {
			delete_metadata( 'user', 0, $key, '', true );
		}
	}
}
