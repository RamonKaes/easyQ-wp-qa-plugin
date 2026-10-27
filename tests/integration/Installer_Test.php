<?php
/**
 * Tests for the installer on the current site.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Database\Installer;
use EasyQ\Database\Schema;
use EasyQ\Settings\Options;

/**
 * @covers \EasyQ\Database\Installer
 * @covers \EasyQ\Database\Schema
 */
final class Installer_Test extends Test_Case {

	public function test_all_tables_exist(): void {
		global $wpdb;

		foreach ( Schema::table_names( $wpdb->prefix ) as $table ) {
			$this->assertTrue( $this->table_exists( $table ), "Table $table is missing." );
		}
	}

	public function test_installed_schema_matches_the_definitions(): void {
		$this->assertSame( array(), Schema::pending_changes() );
	}

	public function test_stores_the_schema_version(): void {
		$this->assertSame( Installer::DB_VERSION, (int) get_option( Installer::DB_VERSION_OPTION ) );
	}

	public function test_upgrades_when_the_stored_version_is_outdated(): void {
		update_option( Installer::DB_VERSION_OPTION, 0 );

		Installer::maybe_upgrade();

		$this->assertSame( Installer::DB_VERSION, (int) get_option( Installer::DB_VERSION_OPTION ) );
	}

	public function test_installs_default_settings(): void {
		$this->assertSame( Options::defaults()['general'], get_option( Options::option_name( 'general' ) ) );
	}

	public function test_reinstalling_keeps_existing_settings(): void {
		update_option( Options::option_name( 'general' ), array( 'base_slug' => 'fragen' ) );

		Installer::install_site();

		$this->assertSame( 'fragen', Options::get( 'general', 'base_slug' ) );
	}

	public function test_metadata_api_uses_the_custom_meta_tables(): void {
		global $wpdb;

		foreach ( Schema::META_TYPES as $meta_type ) {
			$this->assertNotFalse( add_metadata( $meta_type, 42, 'easyq_test', 'value' ) );
			$this->assertSame( 'value', get_metadata( $meta_type, 42, 'easyq_test', true ) );

			$stored = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT meta_value FROM %i WHERE %i = 42 AND meta_key = %s',
					$wpdb->prefix . $meta_type . 'meta',
					$meta_type . '_id',
					'easyq_test'
				)
			);
			$this->assertSame( 'value', $stored );
		}
	}

	public function test_adds_site_tables_to_the_drop_list(): void {
		global $wpdb;

		$tables = Installer::filter_drop_tables( array( 'core_table' ), get_current_blog_id() );

		$this->assertSame( 'core_table', $tables[0] );
		$this->assertContains( $wpdb->get_blog_prefix() . 'easyq_posts', $tables );
	}
}
