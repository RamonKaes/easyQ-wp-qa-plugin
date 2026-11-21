<?php
/**
 * Tests for the uninstaller.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Database\Installer;
use EasyQ\Database\Schema;
use EasyQ\Events\Activity_Tracker;
use EasyQ\Mail\Templates;
use EasyQ\Settings\Options;
use EasyQ\Stats\Counts;
use EasyQ\Uninstaller;
use EasyQ\Users\Avatars;
use EasyQ\Users\Site_Meta;

/**
 * @covers \EasyQ\Uninstaller
 */
final class Uninstaller_Test extends Test_Case {

	public function test_sites_keep_their_data_by_default(): void {
		$this->assertFalse( Uninstaller::site_opted_in() );
	}

	public function test_opt_in_is_read_from_the_settings(): void {
		update_option( Options::option_name( 'general' ), array( 'delete_data_on_uninstall' => true ) );

		$this->assertTrue( Uninstaller::site_opted_in() );
	}

	/**
	 * @group ms-excluded
	 */
	public function test_a_single_site_that_asked_for_it_is_uninstalled(): void {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, 'easyq_points', 10 );
		$dropped = array();
		$record  = static function ( string $query ) use ( &$dropped ): string {
			if ( 1 === preg_match( '/^\s*DROP (?:TEMPORARY )?TABLE IF EXISTS `?(\w+)`?/i', $query, $match ) ) {
				$dropped[] = $match[1];
			}
			return $query;
		};

		// Without the setting, the data stays.
		Uninstaller::run();
		$this->assertSame( '10', get_user_meta( $user_id, 'easyq_points', true ) );

		update_option( Options::option_name( 'general' ), array( 'delete_data_on_uninstall' => true ) );
		add_filter( 'query', $record );
		Uninstaller::run();
		remove_filter( 'query', $record );

		$this->assertContains( Schema::table( 'posts' ), $dropped );
		$this->assertFalse( get_option( Options::option_name( 'general' ), false ) );
		$this->assertSame( '', get_user_meta( $user_id, 'easyq_points', true ) );
	}

	public function test_deletes_only_plugin_user_meta(): void {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, 'easyq_points', 10 );
		update_user_meta( $user_id, 'other_plugin_meta', 'keep' );
		// What core keeps for the screens of easyQ, as their screen options write it.
		$screens = array( 'manageeasyq_page_easyq-postscolumnshidden', 'managetoplevel_page_easyqcolumnshidden', 'closedpostboxes_toplevel_page_easyq', 'meta-box-order_easyq_page_easyq-settings' );
		foreach ( $screens as $key ) {
			update_user_meta( $user_id, $key, array( 'ip' ) );
		}
		update_user_meta( $user_id, 'manageeditcolumnshidden', array( 'author' ) );

		Uninstaller::delete_user_meta();

		$this->assertSame( '', get_user_meta( $user_id, 'easyq_points', true ) );
		$this->assertSame( 'keep', get_user_meta( $user_id, 'other_plugin_meta', true ) );
		foreach ( $screens as $key ) {
			$this->assertSame( '', get_user_meta( $user_id, $key, true ), $key );
		}
		$this->assertSame( array( 'author' ), get_user_meta( $user_id, 'manageeditcolumnshidden', true ), 'The screens of core keep theirs.' );
	}

	public function test_deletes_the_user_meta_of_the_site(): void {
		$user_id = self::factory()->user->create();
		Site_Meta::update( $user_id, 'easyq_level', 50 );
		Site_Meta::update( $user_id, 'easyq_field_location', 'Berlin' );
		Site_Meta::update( $user_id, Activity_Tracker::LAST_WRITE_META, time() );
		update_user_meta( $user_id, 'easyq_points', 10 );

		Uninstaller::delete_site_user_meta();

		$this->assertSame( '', Site_Meta::get( $user_id, 'easyq_level' ) );
		$this->assertSame( '', Site_Meta::get( $user_id, 'easyq_field_location' ) );
		$this->assertSame( '', Site_Meta::get( $user_id, Activity_Tracker::LAST_WRITE_META ) );
		// Meta without the site prefix belongs to the whole network.
		$this->assertSame( '10', get_user_meta( $user_id, 'easyq_points', true ) );
	}

	/**
	 * Runs on a separate site of a network, as uninstalling drops the tables of the site.
	 *
	 * @group ms-required
	 */
	public function test_uninstalling_a_site_removes_the_mark_of_avatar_images(): void {
		$this->activate_network_wide();
		$site_id = self::factory()->blog->create();

		switch_to_blog( $site_id );
		$attachment = self::factory()->attachment->create();
		$other      = self::factory()->attachment->create();
		update_post_meta( $attachment, Avatars::ATTACHMENT_META, 1 );
		update_post_meta( $other, '_other_plugin_meta', 'keep' );
		Uninstaller::uninstall_site();
		$mark  = get_post_meta( $attachment, Avatars::ATTACHMENT_META, true );
		$kept  = get_post_meta( $other, '_other_plugin_meta', true );
		$image = get_post( $attachment );
		restore_current_blog();

		$this->assertSame( '', $mark );
		$this->assertSame( 'keep', $kept );
		$this->assertNotNull( $image );
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
		Templates::save( 'flagged', 'A subject', 'A text' );
		$this->assertNotFalse( get_option( Templates::OPTION ) );
		set_transient( 'easyq_view_' . str_repeat( 'b', 32 ), 1, HOUR_IN_SECONDS );
		Counts::get( Counts::QUESTIONS );
		// Locks of runs that were stopped halfway: the tools in another tab, a batch of the newsletter.
		update_option( \EasyQ\Tools\Tools::RUN_OPTION, array( 'started' => time() ), false );
		add_option( 'easyq_newsletter_run_lock', (string) time(), '', false );
		$names   = fn (): array => $wpdb->get_col( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s', $wpdb->options, '%easyq%' ) );
		$options = $names();
		Uninstaller::uninstall_site();
		$left    = $names();
		$version = get_option( Installer::DB_VERSION_OPTION );
		// With a default, as the registered setting has one, a deleted option reads as that default.
		$settings = get_option( Options::option_name( 'general' ), false );
		// With a default, as the registered setting has one, a deleted option reads as that default.
		$templates = get_option( Templates::OPTION, false );
		restore_current_blog();

		$this->assertContains( '_transient_timeout_easyq_view_' . str_repeat( 'b', 32 ), $options );
		$this->assertContains( Counts::OPTION, $options );
		$this->assertContains( \EasyQ\Tools\Tools::RUN_OPTION, $options );
		$this->assertContains( 'easyq_newsletter_run_lock', $options );
		$this->assertSame( array(), $left );
		$this->assertFalse( $version );
		$this->assertFalse( $settings );
		$this->assertFalse( $templates );
		foreach ( Schema::table_names( $wpdb->get_blog_prefix( $site_id ) ) as $table ) {
			$this->assertFalse( $this->table_exists( $table ), "Table $table was not dropped." );
		}
	}

	/**
	 * @group ms-required
	 */
	public function test_the_user_meta_of_a_network_stays_while_a_site_keeps_its_data(): void {
		$this->activate_network_wide();
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, 'easyq_points', 10 );
		// The main site has easyQ and keeps its data; another site asked to delete it.
		$site_id = self::factory()->blog->create();
		switch_to_blog( $site_id );
		Installer::install_site();
		update_option( Options::option_name( 'general' ), array( 'delete_data_on_uninstall' => true ) );
		restore_current_blog();

		Uninstaller::run();

		// User meta is shared by the sites of a network (F-231).
		$this->assertSame( '10', get_user_meta( $user_id, 'easyq_points', true ) );
	}

	public function test_the_readme_names_the_setting_that_removes_the_data(): void {
		// The FAQ answers whether deleting the plugin deletes the data; the answer is this setting.
		$label  = \EasyQ\Settings\Fields::section( 'general' )['delete_data_on_uninstall']['label'] ?? '';
		$readme = (string) file_get_contents( dirname( __DIR__, 2 ) . '/readme.txt' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertNotSame( '', $label );
		$this->assertStringContainsString( '"' . html_entity_decode( $label ) . '"', $readme );
		$this->assertStringNotContainsString( 'stay in the database when you delete the plugin.', $readme );
	}
}
