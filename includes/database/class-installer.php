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
	 * Option with the time of the last upgrade dbDelta() could not finish.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const FAILED_OPTION = 'easyq_db_upgrade_failed';

	/**
	 * Seconds before a request tries a failed upgrade again; activating the plugin tries at once.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const RETRY_AFTER = HOUR_IN_SECONDS;

	/**
	 * Option of the main network with the sites of a network on which easyQ created its tables.
	 *
	 * Core drops the tables of a deleted site that it knows or a loaded plugin names
	 * (`wp_uninitialize_site()`, `wpmu_drop_tables`), never by the pattern of their names; a
	 * database may hold another install whose prefix starts with the same letters. When easyQ
	 * was not loaded as a site was deleted, this list tells which tables were its own.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const SITES_OPTION = 'easyq_sites';

	/**
	 * Hooks the installer into WordPress.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_action( 'wp_initialize_site', array( self::class, 'initialize_site' ), 200 );
		add_filter( 'wpmu_drop_tables', array( self::class, 'filter_drop_tables' ), 10, 2 );
		add_action( 'wp_uninitialize_site', array( self::class, 'uninitialize_site' ) );

		// The defaults of the settings are translated: not before `init`, where core loads
		// translations (`_load_textdomain_just_in_time()` warns before `after_setup_theme`).
		if ( did_action( 'init' ) ) {
			self::maybe_upgrade();
		} else {
			add_action( 'init', array( self::class, 'maybe_upgrade' ), 0 );
		}
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
		// The same on a network: the other sites install themselves, see above.
		unset( $network_wide );
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
	 * One request at a time: the first visits after an update arrive together, and two installs
	 * at once collide ("already exists", a duplicate default field). A request that finds the
	 * install running goes on with the schema as it is, and the next one checks again.
	 *
	 * @since 0.1.0
	 *
	 * @param bool $only_if_outdated Whether to stop if another request has finished the upgrade meanwhile.
	 */
	public static function install_site( bool $only_if_outdated = false ): void {
		$wpdb = DB::wpdb();
		$lock = $wpdb->prefix . 'easyq_install';
		// A lock of MySQL, released when the connection ends, so a request that dies leaves none behind.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A lock, not data.
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) {
			return;
		}

		try {
			if ( $only_if_outdated && self::stored_version() >= self::DB_VERSION ) {
				return;
			}

			$first = 0 === self::stored_version();
			// Noted before the tables are created, so that tables of a failed install are known as well.
			self::remember_site( get_current_blog_id() );
			Schema::install();
			Schema::register_meta_tables();

			// The version is saved only when every table is in place and dbDelta() has nothing left
			// to change, so that Site Health and a later request see the upgrade as not done.
			if ( array() !== Schema::missing_tables() || array() !== Schema::pending_changes() ) {
				update_option( self::FAILED_OPTION, time(), false );
				return;
			}

			Options::install();
			// The default field comes with the first install only; an upgrade keeps the owner's choice.
			if ( $first ) {
				( new User_Fields() )->install_defaults();
			}

			update_option( self::DB_VERSION_OPTION, self::DB_VERSION, true );
			delete_option( self::FAILED_OPTION );

			// Adds the Q&A routes to the rewrite rules on the next request.
			delete_option( \EasyQ\Frontend\Router::RULES_OPTION );
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A lock, not data.
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	/**
	 * Installs or upgrades the current site if its schema version is outdated.
	 *
	 * After an upgrade dbDelta() could not finish, requests wait an hour before they try again,
	 * so that not every request runs it; Site Health shows what is left meanwhile.
	 *
	 * @since 0.1.0
	 */
	public static function maybe_upgrade(): void {
		if ( self::installed_version() >= self::DB_VERSION ) {
			return;
		}

		$failed = get_option( self::FAILED_OPTION );
		if ( is_numeric( $failed ) && (int) $failed > time() - self::RETRY_AFTER ) {
			return;
		}

		self::install_site( true );
	}

	/**
	 * Returns the schema version as stored, past the object cache, which another request
	 * may have changed meanwhile.
	 *
	 * @since 0.1.0
	 *
	 * @return int
	 */
	private static function stored_version(): int {
		$wpdb = DB::wpdb();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read as stored, under the install lock.
		$version = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, self::DB_VERSION_OPTION ) );
		wp_cache_delete( self::DB_VERSION_OPTION, 'options' );

		return is_numeric( $version ) ? (int) $version : 0;
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
	 * Returns the sites of the network on which easyQ created its tables.
	 *
	 * @since 0.1.0
	 *
	 * @return int[] Site IDs, deleted sites whose tables are left included.
	 */
	public static function installed_sites(): array {
		if ( ! is_multisite() ) {
			return array();
		}

		$sites = get_network_option( get_main_network_id(), self::SITES_OPTION, array() );

		return is_array( $sites ) ? array_values( array_unique( array_map( 'intval', array_filter( $sites, 'is_numeric' ) ) ) ) : array();
	}

	/**
	 * Notes a site of the network on which easyQ creates or keeps its tables.
	 *
	 * @since 0.1.0
	 *
	 * @param int $site_id Site ID.
	 */
	public static function remember_site( int $site_id ): void {
		$sites = self::installed_sites();
		if ( is_multisite() && ! in_array( $site_id, $sites, true ) ) {
			$sites[] = $site_id;
			update_network_option( get_main_network_id(), self::SITES_OPTION, $sites );
		}
	}

	/**
	 * Forgets a site of the network whose tables of easyQ are gone.
	 *
	 * @since 0.1.0
	 *
	 * @param int $site_id Site ID.
	 */
	public static function forget_site( int $site_id ): void {
		$sites = self::installed_sites();
		if ( ! in_array( $site_id, $sites, true ) ) {
			return;
		}

		$sites = array_values( array_diff( $sites, array( $site_id ) ) );
		if ( array() === $sites ) {
			delete_network_option( get_main_network_id(), self::SITES_OPTION );
			return;
		}
		update_network_option( get_main_network_id(), self::SITES_OPTION, $sites );
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
		self::forget_site( (int) $site->blog_id );
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
			// Not covered: the test runner has loaded wp-admin/includes/plugin.php already, and a PHP function cannot be unloaded.
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active_for_network( plugin_basename( \EasyQ\PLUGIN_FILE ) );
	}
}
