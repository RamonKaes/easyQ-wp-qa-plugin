<?php
/**
 * Installation and upgrades.
 *
 * @package easyQ
 */

namespace EasyQ\Database;

use EasyQ\Settings\Options;
use EasyQ\Users\User_Fields;
use WP_Site;

defined( 'ABSPATH' ) || exit;

/**
 * Installs and upgrades the plugin data on single sites and multisite networks.
 */
final class Installer {

	/**
	 * Current database schema version. Increase it with every schema change.
	 *
	 * @var int
	 */
	public const DB_VERSION = 1;

	/**
	 * Option that stores the installed schema version of a site.
	 *
	 * @var string
	 */
	public const DB_VERSION_OPTION = 'easyq_db_version';

	/**
	 * Hooks the installer into WordPress.
	 */
	public function register(): void {
		add_action( 'wp_initialize_site', array( self::class, 'initialize_site' ), 200 );
		add_filter( 'wpmu_drop_tables', array( self::class, 'filter_drop_tables' ), 10, 2 );

		self::maybe_upgrade();
	}

	/**
	 * Runs on plugin activation.
	 *
	 * On network activation the sites are installed right away unless the network
	 * is large; remaining sites are installed on their next request by maybe_upgrade().
	 *
	 * @param bool $network_wide Whether the plugin is being activated for the whole network.
	 */
	public static function activate( bool $network_wide = false ): void {
		if ( ! is_multisite() || ! $network_wide ) {
			self::install_site();
			return;
		}

		if ( wp_is_large_network() ) {
			return;
		}

		$site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);

		foreach ( $site_ids as $site_id ) {
			switch_to_blog( (int) $site_id );
			self::install_site();
			restore_current_blog();
		}
	}

	/**
	 * Runs on plugin deactivation.
	 *
	 * Data is kept; it is only removed on uninstall when the site owner asked for it.
	 */
	public static function deactivate(): void {
		// Rewrite rules and scheduled events are cleaned up here once they exist.
	}

	/**
	 * Creates or upgrades tables and default options of the current site.
	 */
	public static function install_site(): void {
		Schema::install();
		Schema::register_meta_tables();
		Options::install();
		( new User_Fields() )->install_defaults();

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, true );
	}

	/**
	 * Installs or upgrades the current site if its schema version is outdated.
	 */
	public static function maybe_upgrade(): void {
		if ( self::installed_version() < self::DB_VERSION ) {
			self::install_site();
		}
	}

	/**
	 * Returns the schema version installed on the current site.
	 *
	 * @return int The version, or 0 if the plugin has not been installed.
	 */
	public static function installed_version(): int {
		$version = get_option( self::DB_VERSION_OPTION, 0 );

		return is_numeric( $version ) ? (int) $version : 0;
	}

	/**
	 * Installs a new site of the network if the plugin is network-activated.
	 *
	 * @param WP_Site $site The new site.
	 */
	public static function initialize_site( WP_Site $site ): void {
		if ( ! self::is_network_active() ) {
			return;
		}

		switch_to_blog( (int) $site->blog_id );
		self::install_site();
		restore_current_blog();
	}

	/**
	 * Adds the tables of a site that is being deleted to the list of tables to drop.
	 *
	 * @param string[] $tables  Tables WordPress drops.
	 * @param int      $site_id ID of the deleted site.
	 * @return string[]
	 */
	public static function filter_drop_tables( array $tables, int $site_id ): array {
		$wpdb = Db::wpdb();

		return array_merge( $tables, Schema::table_names( $wpdb->get_blog_prefix( $site_id ) ) );
	}

	/**
	 * Checks whether the plugin is activated for the whole network.
	 *
	 * @return bool
	 */
	private static function is_network_active(): bool {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active_for_network( plugin_basename( \EasyQ\PLUGIN_FILE ) );
	}
}
