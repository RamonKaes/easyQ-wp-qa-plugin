<?php
/**
 * Tests for the installer on multisite networks.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Database\Installer;
use EasyQ\Database\Schema;

/**
 * @group ms-required
 * @covers \EasyQ\Database\Installer
 */
final class Multisite_Installer_Test extends Test_Case {

	public function test_new_site_gets_tables_when_network_activated(): void {
		global $wpdb;

		$this->activate_network_wide();
		$site_id = self::factory()->blog->create();

		foreach ( Schema::table_names( $wpdb->get_blog_prefix( $site_id ) ) as $table ) {
			$this->assertTrue( $this->table_exists( $table ), "Table $table is missing." );
		}

		switch_to_blog( $site_id );
		$version = (int) get_option( Installer::DB_VERSION_OPTION );
		restore_current_blog();

		$this->assertSame( Installer::DB_VERSION, $version );
	}

	public function test_network_wide_deactivation_clears_the_scheduled_events_of_every_site(): void {
		$this->activate_network_wide();
		$site_id = self::factory()->blog->create();
		$hook    = \EasyQ\Newsletter\Newsletter::CRON_HOOK;

		switch_to_blog( $site_id );
		wp_schedule_single_event( time() + HOUR_IN_SECONDS, $hook );
		$this->assertNotFalse( wp_next_scheduled( $hook ) );
		restore_current_blog();

		Installer::deactivate( true );

		switch_to_blog( $site_id );
		$scheduled = wp_next_scheduled( $hook );
		restore_current_blog();
		$this->assertFalse( $scheduled, 'Network-wide deactivation reaches every site.' );
	}

	public function test_new_site_gets_no_tables_without_network_activation(): void {
		global $wpdb;

		update_site_option( 'active_sitewide_plugins', array() );
		$site_id = self::factory()->blog->create();

		$this->assertFalse( $this->table_exists( $wpdb->get_blog_prefix( $site_id ) . 'easyq_posts' ) );
	}

	public function test_deleting_a_site_drops_its_tables(): void {
		global $wpdb;

		$this->activate_network_wide();
		$site_id = self::factory()->blog->create();
		$prefix  = $wpdb->get_blog_prefix( $site_id );

		wp_delete_site( $site_id );

		foreach ( Schema::table_names( $prefix ) as $table ) {
			$this->assertFalse( $this->table_exists( $table ), "Table $table was not dropped." );
		}
	}

	public function test_tables_of_a_site_deleted_while_easyq_was_not_loaded_go_later(): void {
		global $wpdb;

		// easyQ active on one site only: the network admin that deletes the site does not load it
		// (`wp-includes/load.php` reads the plugins of the main site), so its hooks do not run.
		update_site_option( 'active_sitewide_plugins', array() );
		$site_id = self::factory()->blog->create();
		$prefix  = $wpdb->get_blog_prefix( $site_id );
		switch_to_blog( $site_id );
		Installer::install_site();
		restore_current_blog();
		remove_filter( 'wpmu_drop_tables', array( Installer::class, 'filter_drop_tables' ) );
		remove_action( 'wp_uninitialize_site', array( Installer::class, 'uninitialize_site' ) );
		wp_delete_site( $site_id );
		$this->assertTrue( $this->table_exists( $prefix . 'easyq_posts' ), 'Core drops only the tables it knows.' );

		// The next daily run of easyQ on any site of the network removes them, and nothing of an existing site.
		\EasyQ\Privacy\IP_Retention::run();
		foreach ( Schema::table_names( $prefix ) as $table ) {
			$this->assertFalse( $this->table_exists( $table ), "Table $table of the deleted site was kept." );
		}
		$this->assertTrue( $this->table_exists( $wpdb->get_blog_prefix( get_current_blog_id() ) . 'easyq_posts' ) );
	}

	/**
	 * Tables and meta whose names look like those of a deleted site stay if easyQ never
	 * installed that site: another install in the same database may use a prefix that starts
	 * with the base prefix, such as `wp_2024_` (F-253). Core drops by the list of a site's
	 * tables, never by a pattern (`wp_uninitialize_site()`).
	 */
	public function test_the_daily_run_leaves_tables_and_meta_of_another_install(): void {
		global $wpdb;

		$foreign = $wpdb->base_prefix . '2024_';
		$table   = $foreign . 'easyq_posts';
		$key     = $foreign . \EasyQ\Uninstaller::USER_META_PREFIX . 'level';
		$user_id = self::factory()->user->create();
		$site_id = self::factory()->blog->create();
		$kept    = $wpdb->get_blog_prefix( $site_id ) . 'easyq_posts';
		Installer::remember_site( $site_id );
		// Real tables, as another install has, not the temporary ones of the test suite, which
		// SHOW TABLES leaves out and a DROP of a real table does not reach.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$wpdb->query( $wpdb->prepare( 'CREATE TABLE IF NOT EXISTS %i LIKE %i', $table, $wpdb->prefix . 'easyq_posts' ) );
		$wpdb->query( $wpdb->prepare( 'CREATE TABLE IF NOT EXISTS %i LIKE %i', $kept, $wpdb->prefix . 'easyq_posts' ) );
		add_user_meta( $user_id, $key, '1' );

		try {
			\EasyQ\Privacy\IP_Retention::run();
			\EasyQ\Uninstaller::remove_data_of_deleted_sites();

			$this->assertTrue( $this->table_exists( $table ), 'The table of the other install was dropped.' );
			$this->assertSame( '1', get_user_meta( $user_id, $key, true ), 'The meta of the other install was deleted.' );
			$this->assertNotContains( 2024, Installer::installed_sites() );
			$this->assertTrue( $this->table_exists( $kept ), 'The table of a site that exists was dropped.' );
			$this->assertContains( $site_id, Installer::installed_sites() );
		} finally {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $kept ) );
			add_filter( 'query', array( $this, '_create_temporary_tables' ) );
			add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		}
	}

	public function test_the_list_of_sites_forgets_sites_whose_tables_are_gone(): void {
		$deleted = self::factory()->blog->create();
		$other   = self::factory()->blog->create();
		foreach ( array( $deleted, $other ) as $site_id ) {
			switch_to_blog( $site_id );
			Installer::install_site();
			restore_current_blog();
		}
		$this->assertContains( $deleted, Installer::installed_sites() );

		// Deleted with easyQ loaded: core drops the tables easyQ names, and the list lets the site go.
		wp_delete_site( $deleted );
		$this->assertNotContains( $deleted, Installer::installed_sites() );
		$this->assertContains( $other, Installer::installed_sites() );

		// Uninstalled with the data: no site keeps tables, so nothing of the list stays.
		foreach ( get_sites( array( 'fields' => 'ids' ) ) as $site_id ) {
			switch_to_blog( (int) $site_id );
			\EasyQ\Settings\Options::update( 'general', array( 'delete_data_on_uninstall' => true ) );
			restore_current_blog();
		}
		\EasyQ\Uninstaller::run();
		$this->assertFalse( get_network_option( get_main_network_id(), Installer::SITES_OPTION ) );
	}

	public function test_meta_tables_follow_site_switches(): void {
		global $wpdb;

		$this->activate_network_wide();
		$site_id = self::factory()->blog->create();

		switch_to_blog( $site_id );
		$switched = $wpdb->easyq_postmeta;
		restore_current_blog();

		$this->assertSame( $wpdb->get_blog_prefix( $site_id ) . 'easyq_postmeta', $switched );
		$this->assertSame( $wpdb->prefix . 'easyq_postmeta', $wpdb->easyq_postmeta );
	}
}
