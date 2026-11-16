<?php
/**
 * Installation and upgrades.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Database;

use EasyQ\Settings\Options;
use EasyQ\Users\User_Fields;
use WP_Site;

defined( 'ABSPATH' ) || exit;

/**
 * Installs and upgrades the plugin data on single sites and multisite networks.
 *
 * @since 0.1.0
 */
final class Installer {

	/**
	 * Current database schema version. Increase it with every schema change.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const DB_VERSION = 2;

	/**
	 * Option that stores the installed schema version of a site.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const DB_VERSION_OPTION = 'easyq_db_version';

	/**
	 * Hooks the installer into WordPress.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_action( 'wp_initialize_site', array( self::class, 'initialize_site' ), 200 );
		add_filter( 'wpmu_drop_tables', array( self::class, 'filter_drop_tables' ), 10, 2 );
		add_action( 'wp_uninitialize_site', array( self::class, 'uninitialize_site' ) );

		self::maybe_upgrade();
	}

	/**
	 * Runs on plugin activation.
	 *
	 * On network activation only the current site is installed in the request; each other
	 * site installs itself on its next request (maybe_upgrade()), as core upgrades the sites
	 * of a network one by one instead of in one request (`network/upgrade.php`), and spam,
	 * deleted and archived sites, which serve no requests, are left alone.
	 *
	 * @since 0.1.0
	 *
	 * @param bool $network_wide Whether the plugin is being activated for the whole network.
	 */
	public static function activate( bool $network_wide = false ): void {
		if ( ! is_multisite() || ! $network_wide ) {
			self::install_site();
			return;
		}

		self::install_site();
	}

	/**
	 * Runs on plugin deactivation.
	 *
	 * Data is kept; it is only removed on uninstall when the site owner asked for it.
	 *
	 * @since 0.1.0
	 *
	 * @param bool $network_wide Whether the plugin is being deactivated for the whole network.
	 */
	public static function deactivate( bool $network_wide = false ): void {
		if ( ! is_multisite() || ! $network_wide ) {
			self::deactivate_site();
			return;
		}

		// Core skips large networks in network-wide actions; the current site is cleaned at least.
		if ( wp_is_large_network() ) {
			self::deactivate_site();
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
			self::deactivate_site();
			restore_current_blog();
		}
	}

	/**
	 * Removes the temporary data of the current site: rewrite rules and scheduled events.
	 *
	 * @since 0.1.0
	 */
	private static function deactivate_site(): void {
		// WordPress rebuilds the rewrite rules without the Q&A routes on the next request.
		delete_option( 'rewrite_rules' );
		delete_option( \EasyQ\Frontend\Router::RULES_OPTION );
		// A sending stops with its event, so the admin shows no sending that never moves.
		\EasyQ\Newsletter\Newsletter::cancel();
		wp_clear_scheduled_hook( \EasyQ\Privacy\IP_Retention::CRON_HOOK );
	}

	/**
	 * Creates or upgrades tables and default options of the current site.
	 *
	 * @since 0.1.0
	 */
	public static function install_site(): void {
		$first = 0 === self::installed_version();
		Schema::install();
		Schema::register_meta_tables();

		// The version is saved only when every table is in place, so a later request tries again.
		if ( array() !== Schema::missing_tables() ) {
			return;
		}

		Options::install();
		// The default field comes with the first install only; an upgrade keeps the owner's choice.
		if ( $first ) {
			( new User_Fields() )->install_defaults();
		}

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, true );

		// Adds the Q&A routes to the rewrite rules on the next request.
		delete_option( \EasyQ\Frontend\Router::RULES_OPTION );
	}

	/**
	 * Installs or upgrades the current site if its schema version is outdated.
	 *
	 * @since 0.1.0
	 */
	public static function maybe_upgrade(): void {
		if ( self::installed_version() < self::DB_VERSION ) {
			self::install_site();
		}
	}

	/**
	 * Returns the schema version installed on the current site.
	 *
	 * @since 0.1.0
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
	 * @since 0.1.0
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
	 * @since 0.1.0
	 *
	 * @param mixed $tables  Tables WordPress drops.
	 * @param int   $site_id ID of the deleted site.
	 * @return mixed
	 */
	public static function filter_drop_tables( mixed $tables, int $site_id ): mixed {
		// Another filter may hand on any type; it is passed on unchanged, as core does.
		if ( ! is_array( $tables ) ) {
			return $tables;
		}

		$wpdb = DB::wpdb();

		return array_merge( $tables, Schema::table_names( $wpdb->get_blog_prefix( $site_id ) ) );
	}

	/**
	 * Removes the user meta of a site that is deleted, with its tables.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Site $site The deleted site.
	 */
	public static function uninitialize_site( WP_Site $site ): void {
		\EasyQ\Uninstaller::delete_meta_with_prefix( DB::wpdb()->get_blog_prefix( (int) $site->blog_id ) . \EasyQ\Uninstaller::USER_META_PREFIX );
	}

	/**
	 * Checks whether the plugin is activated for the whole network.
	 *
	 * @since 0.1.0
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
