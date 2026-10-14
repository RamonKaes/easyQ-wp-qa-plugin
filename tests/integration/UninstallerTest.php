<?php
/**
 * Tests for the uninstaller.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Database\Installer;
use EasyQ\Database\Schema;
use EasyQ\Settings\Options;
use EasyQ\Uninstaller;
use EasyQ\Users\Site_Meta;

/**
 * @covers \EasyQ\Uninstaller
 */
final class UninstallerTest extends TestCase {

	public function test_sites_keep_their_data_by_default(): void {
		$this->assertFalse( Uninstaller::site_opted_in() );
	}

	public function test_opt_in_is_read_from_the_settings(): void {
		update_option( Options::option_name( 'general' ), array( 'delete_data_on_uninstall' => true ) );

		$this->assertTrue( Uninstaller::site_opted_in() );
	}

	public function test_deletes_only_plugin_user_meta(): void {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, 'easyq_points', 10 );
		update_user_meta( $user_id, 'other_plugin_meta', 'keep' );

		Uninstaller::delete_user_meta();

		$this->assertSame( '', get_user_meta( $user_id, 'easyq_points', true ) );
		$this->assertSame( 'keep', get_user_meta( $user_id, 'other_plugin_meta', true ) );
	}

	public function test_deletes_the_user_meta_of_the_site(): void {
		$user_id = self::factory()->user->create();
		Site_Meta::update( $user_id, 'easyq_level', 50 );
		update_user_meta( $user_id, 'easyq_field_location', 'Berlin' );

		Uninstaller::delete_site_user_meta();

		$this->assertSame( '', Site_Meta::get( $user_id, 'easyq_level' ) );
		$this->assertSame( 'Berlin', get_user_meta( $user_id, 'easyq_field_location', true ) );
	}

	/**
	 * Runs on a separate site of a network, whose tables are temporary in tests.
	 *
	 * @group ms-required
	 */
	public function test_uninstalling_a_site_removes_tables_and_options(): void {
		global $wpdb;

		$this->activate_network_wide();
		$site_id = self::factory()->blog->create();

		switch_to_blog( $site_id );
		Uninstaller::uninstall_site();
		$version  = get_option( Installer::DB_VERSION_OPTION );
		$settings = get_option( Options::option_name( 'general' ) );
		restore_current_blog();

		$this->assertFalse( $version );
		$this->assertFalse( $settings );
		foreach ( Schema::table_names( $wpdb->get_blog_prefix( $site_id ) ) as $table ) {
			$this->assertFalse( $this->table_exists( $table ), "Table $table was not dropped." );
		}
	}
}
