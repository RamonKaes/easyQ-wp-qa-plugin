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
final class MultisiteInstallerTest extends TestCase {

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
