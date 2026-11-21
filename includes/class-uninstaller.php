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
	 * @since 0.1.0
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
			} elseif ( Installer::installed_version() > 0 ) {
				// A site that never had easyQ keeps nothing, so it does not keep the meta of the network.
				$all_opted_in = false;
			}
			restore_current_blog();
		}

		// Sites deleted before keep no data of their own: their tables and meta go in any case.
		self::remove_data_of_deleted_sites();

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
		Newsletter::cancel();
		self::delete_options();
		self::delete_transients();
		self::delete_site_user_meta();
		// The mark of uploaded avatars; the images stay in the Media Library, like other uploads.
		delete_post_meta_by_key( Avatars::ATTACHMENT_META );
	}

	/**
	 * Removes every other option of the current site whose name starts with the prefix of the
	 * plugin, such as the locks a stopped run of the tools or of the newsletter leaves behind.
	 *
	 * @since 0.1.0
	 */
	private static function delete_options(): void {
		$wpdb = DB::wpdb();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off cleanup on uninstall; no API lists options by prefix.
		$names = $wpdb->get_col( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like( self::TRANSIENT_PREFIX ) . '%' ) );
		foreach ( $names as $name ) {
			delete_option( (string) $name );
		}
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

		// What core keeps per user for the screens of easyQ, named after the screens
		// (`get_plugin_page_hookname()`): hidden columns (`wp_ajax_hidden_columns()`), closed,
		// hidden and ordered boxes, and the layout.
		foreach ( array( 'manage', 'closedpostboxes_', 'metaboxhidden_', 'meta-box-order_', 'screen_layout_' ) as $setting ) {
			self::delete_meta_with_prefix( $setting . 'easyq_page_' );
			delete_metadata( 'user', 0, $setting . 'toplevel_page_easyq' . ( 'manage' === $setting ? 'columnshidden' : '' ), '', true );
		}
	}

	/**
	 * Deletes the user meta of all users whose key starts with a prefix.
	 *
	 * @since 0.1.0
	 *
	 * @param string $prefix Meta key prefix.
	 */
	public static function delete_meta_with_prefix( string $prefix ): void {
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

	/**
	 * Removes the tables and the user meta of easyQ that sites deleted from the network left
	 * behind.
	 *
	 * Core drops only the tables it knows and those a loaded plugin names
	 * (`wp_uninitialize_site()`, `wpmu_drop_tables`). When easyQ is active on some sites only,
	 * the network admin that deletes one does not load it, so its tables, with posts, email
	 * and IP addresses, would stay for good. The daily run of every site with easyQ and the
	 * uninstall remove them.
	 *
	 * @since 0.1.0
	 */
	public static function remove_data_of_deleted_sites(): void {
		if ( ! is_multisite() ) {
			return;
		}

		$wpdb     = DB::wpdb();
		$site_ids = array_map(
			'intval',
			get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			)
		);
		$own      = array_flip( Schema::table_names( '' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- No API lists the tables of deleted sites.
		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->base_prefix ) . '%' ) );
		foreach ( array_filter( $tables, 'is_string' ) as $table ) {
			if ( 1 !== preg_match( '/^' . preg_quote( $wpdb->base_prefix, '/' ) . '(\d+)_(.+)$/', $table, $match ) || ! isset( $own[ $match[2] ] ) || in_array( (int) $match[1], $site_ids, true ) ) {
				continue;
			}
			$sql = $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table );
			if ( null !== $sql ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; the tables of easyQ of a site that no longer exists.
				$wpdb->query( $sql );
			}
		}

		self::delete_meta_of_deleted_sites( $site_ids );
	}

	/**
	 * Deletes the user meta of sites of the network that no longer exist.
	 *
	 * @since 0.1.0
	 *
	 * @param int[] $site_ids Sites that exist.
	 */
	private static function delete_meta_of_deleted_sites( array $site_ids ): void {
		$wpdb = DB::wpdb();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off cleanup on uninstall; no API lists meta keys.
		$keys = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT meta_key FROM %i WHERE meta_key LIKE %s', $wpdb->usermeta, $wpdb->esc_like( $wpdb->base_prefix ) . '%' . $wpdb->esc_like( '_' . self::USER_META_PREFIX ) . '%' ) );
		foreach ( array_filter( $keys, 'is_string' ) as $key ) {
			if ( 1 === preg_match( '/^' . preg_quote( $wpdb->base_prefix, '/' ) . '(\d+)_' . preg_quote( self::USER_META_PREFIX, '/' ) . '/', $key, $site ) && ! in_array( (int) $site[1], $site_ids, true ) ) {
				delete_metadata( 'user', 0, $key, '', true );
			}
		}
	}
}
