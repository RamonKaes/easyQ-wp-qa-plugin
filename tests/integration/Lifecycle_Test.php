<?php
/**
 * Tests installing, upgrading and removing the plugin as core handles sites.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Database\Installer;
use EasyQ\Database\Schema;
use EasyQ\Services;
use EasyQ\Settings\Options;
use EasyQ\Uninstaller;
use EasyQ\Users\Site_Meta;

/**
 * @covers \EasyQ\Database\Installer
 * @covers \EasyQ\Uninstaller
 */
final class Lifecycle_Test extends Test_Case {

	public function test_the_version_is_saved_only_with_every_table(): void {
		global $wpdb;
		$prefix = $wpdb->prefix;
		$saved  = get_option( Installer::DB_VERSION_OPTION );
		// Tables under a new prefix, which do not exist yet.
		$wpdb->prefix = $prefix . 'lifecycle_';
		delete_option( Installer::DB_VERSION_OPTION );
		$broken = static fn ( array $queries ): array => array_filter( $queries, static fn ( string $query ): bool => ! str_contains( $query, 'userfields' ) );
		add_filter( 'dbdelta_create_queries', $broken );

		try {
			Installer::install_site();
			$this->assertSame( 0, Installer::installed_version(), 'A table could not be created.' );
			$this->assertSame( array( Schema::table( 'userfields' ) ), Schema::missing_tables() );

			remove_filter( 'dbdelta_create_queries', $broken );
			Installer::maybe_upgrade();
			$this->assertSame( Installer::DB_VERSION, Installer::installed_version(), 'The next request creates it.' );
			$this->assertSame( array(), Schema::missing_tables() );
		} finally {
			$wpdb->prefix = $prefix;
			update_option( Installer::DB_VERSION_OPTION, $saved );
		}
	}

	public function test_an_upgrade_keeps_the_fields_the_owner_deleted(): void {
		$fields = Services::user_fields();
		foreach ( $fields->all() as $field ) {
			$fields->delete( $field->id );
		}
		update_option( Installer::DB_VERSION_OPTION, Installer::DB_VERSION - 1 );

		Installer::maybe_upgrade();

		$this->assertSame( Installer::DB_VERSION, Installer::installed_version() );
		$this->assertSame( array(), Services::user_fields()->all() );
	}

	public function test_a_field_name_exists_once(): void {
		global $wpdb;
		$this->assertNotEmpty( Services::user_fields()->all() );
		$name = Services::user_fields()->all()[0]->name;

		$suppress = $wpdb->suppress_errors();
		$inserted = $wpdb->insert( Schema::table( 'userfields' ), array( 'name' => $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->suppress_errors( $suppress );

		$this->assertFalse( $inserted, 'Two installs at the same time cannot add the default field twice.' );
	}

	public function test_network_activation_installs_the_current_site_and_the_others_on_their_request(): void {
		if ( ! is_multisite() ) {
			$this->assertTrue( true );
			return;
		}
		global $wpdb;
		$sites = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$sites[] = self::factory()->blog->create();
		}
		foreach ( $sites as $site ) {
			switch_to_blog( $site );
			delete_option( Installer::DB_VERSION_OPTION );
			restore_current_blog();
		}

		$before = $wpdb->num_queries;
		Installer::activate( true );
		$queries = $wpdb->num_queries - $before;

		switch_to_blog( $sites[0] );
		$this->assertSame( 0, Installer::installed_version(), 'Other sites are not installed in the request.' );
		Installer::maybe_upgrade();
		$this->assertSame( Installer::DB_VERSION, Installer::installed_version(), 'A site installs itself on its next request.' );
		restore_current_blog();
		$this->assertLessThan( 150, $queries, 'The activation does not grow with the sites.' );
	}

	public function test_the_user_meta_of_deleted_sites_is_removed(): void {
		if ( ! is_multisite() ) {
			$this->assertTrue( true );
			return;
		}
		global $wpdb;
		$user = self::factory()->user->create();
		$site = self::factory()->blog->create();
		switch_to_blog( $site );
		Site_Meta::update( $user, 'easyq_level', 50 );
		$key = Site_Meta::key( 'easyq_level' );
		restore_current_blog();

		wpmu_delete_blog( $site, true );
		$this->assertSame( '', get_user_meta( $user, $key, true ) );

		// Meta of a site deleted before the plugin knew of it goes with the uninstall.
		$orphan = $wpdb->base_prefix . '9999_easyq_level';
		update_user_meta( $user, $orphan, 50 );
		Uninstaller::run();
		$this->assertSame( '', get_user_meta( $user, $orphan, true ) );
	}

	public function test_a_site_without_easyq_does_not_keep_the_meta_of_the_network(): void {
		if ( ! is_multisite() ) {
			$this->assertTrue( true );
			return;
		}
		$user = self::factory()->user->create();
		update_user_meta( $user, Uninstaller::USER_META_PREFIX . 'unconfirmed', 1 );
		Options::update( 'general', array( 'delete_data_on_uninstall' => true ) );
		$site = self::factory()->blog->create();
		switch_to_blog( $site );
		delete_option( Installer::DB_VERSION_OPTION );
		restore_current_blog();

		Uninstaller::run();

		$this->assertSame( '', get_user_meta( $user, Uninstaller::USER_META_PREFIX . 'unconfirmed', true ) );
	}
}
