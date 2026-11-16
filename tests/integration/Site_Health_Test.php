<?php
/**
 * Tests for the Site Health tests and the Info section of easyQ.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Database\Installer;
use EasyQ\Database\Schema;
use EasyQ\Health\Site_Health;
use EasyQ\Settings\Options;
use WP_Site_Health;

/**
 * @covers \EasyQ\Health\Site_Health
 */
final class Site_Health_Test extends Test_Case {

	/**
	 * Site Health of easyQ.
	 *
	 * @var Site_Health
	 */
	private Site_Health $health;

	public function set_up(): void {
		parent::set_up();
		$this->health = new Site_Health();
	}

	public function test_core_runs_the_tests_of_easyq(): void {
		require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';

		$tests = WP_Site_Health::get_tests()['direct'];

		foreach ( array( 'easyq_database', 'easyq_permalinks', 'easyq_read_only' ) as $name ) {
			$this->assertArrayHasKey( $name, $tests );
			$this->assertIsCallable( $tests[ $name ]['test'] );
			$this->assertSame( $name, call_user_func( $tests[ $name ]['test'] )['test'] );
		}
	}

	public function test_database_is_good_when_the_tables_match(): void {
		$result = $this->health->test_database();

		$this->assertSame( 'good', $result['status'] );
		$this->assertSame( '', $result['actions'] );
	}

	public function test_database_is_critical_with_an_outdated_version(): void {
		update_option( Installer::DB_VERSION_OPTION, Installer::DB_VERSION - 1 );

		$result = $this->health->test_database();

		$this->assertSame( 'critical', $result['status'] );
		$this->assertStringContainsString( admin_url( 'plugins.php' ), $result['actions'] );
	}

	public function test_database_is_critical_and_lists_missing_tables(): void {
		global $wpdb;

		$table = Schema::table( 'posts' );
		// dbDelta() takes a table that DESCRIBE cannot read for a missing one.
		$hide   = static fn ( string $query ): string => "DESCRIBE {$table};" === $query ? "DESCRIBE {$wpdb->prefix}easyq_none;" : $query;
		add_filter( 'query', $hide );
		$result = $this->health->test_database();
		remove_filter( 'query', $hide );

		$this->assertSame( 'critical', $result['status'] );
		// It says what is missing, not what the dry run of dbDelta() would have done.
		$this->assertStringContainsString( '<li>Missing table: ' . $table . '</li>', $result['description'] );
		$this->assertStringNotContainsString( 'Created table', $result['description'] );
		$this->assertStringContainsString( esc_url( is_multisite() && is_plugin_active_for_network( plugin_basename( \EasyQ\PLUGIN_FILE ) ) ? network_admin_url( 'plugins.php' ) : admin_url( 'plugins.php' ) ), $result['actions'] );
	}

	public function test_on_a_network_the_action_leads_to_the_plugins_of_the_network(): void {
		if ( ! is_multisite() ) {
			$this->assertTrue( true );
			return;
		}
		update_site_option( 'active_sitewide_plugins', array( plugin_basename( \EasyQ\PLUGIN_FILE ) => time() ) + (array) get_site_option( 'active_sitewide_plugins', array() ) );
		update_option( \EasyQ\Database\Installer::DB_VERSION_OPTION, 0 );

		$result = $this->health->test_database();

		$this->assertStringContainsString( esc_url( network_admin_url( 'plugins.php' ) ), $result['actions'] );
	}

	public function test_permalinks_are_recommended_without_a_structure(): void {
		$this->set_permalink_structure( '' );

		$result = $this->health->test_permalinks();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'easyq_route=home', $result['description'] );
		$this->assertStringContainsString( admin_url( 'options-permalink.php' ), $result['actions'] );
	}

	public function test_permalinks_are_good_with_a_structure(): void {
		$this->set_permalink_structure( '/%postname%/' );

		$result = $this->health->test_permalinks();

		$this->assertSame( 'good', $result['status'] );
		$this->assertStringContainsString( '<code>' . home_url( '/questions/' ) . '</code>', $result['description'] );
		$this->assertStringNotContainsString( 'easyq_route', $result['description'] );
		$this->assertSame( '', $result['actions'] );
	}

	public function test_read_only_mode_is_recommended_to_end(): void {
		$this->assertSame( 'good', $this->health->test_read_only()['status'] );

		Options::update( 'general', array( 'read_only' => true ) );
		$result = $this->health->test_read_only();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( esc_url( admin_url( 'admin.php?page=easyq-settings&tab=general' ) ) . '#easyq-general-read-only', $result['actions'] );
	}

	public function test_info_tab_describes_the_q_and_a_area(): void {
		$this->ask( $this->user() );
		Options::update(
			'general',
			array(
				'read_only'   => true,
				'erase_posts' => 'delete',
			)
		);

		$info = apply_filters( 'debug_information', array() );

		$this->assertArrayHasKey( 'easyq', $info );
		$fields = $info['easyq']['fields'];
		// As core marks `home_url` and `site_url`: the address is not copied with the site information.
		$this->assertTrue( $fields['address']['private'] ?? false );
		$this->assertSame( \EasyQ\VERSION, $fields['version']['value'] );
		$this->assertSame( Installer::DB_VERSION . ' (' . Installer::DB_VERSION . ')', $fields['database_version']['debug'] );
		$this->assertSame( 1, $fields['questions']['value'] );
		$this->assertSame( 'basic', $fields['search']['debug'] );
		$this->assertSame( 'Read-only mode', $fields['read_only']['label'] );
		$this->assertSame( 'Yes', $fields['read_only']['value'] );
		$this->assertSame( 'true', $fields['read_only']['debug'] );
		$this->assertStringStartsWith( 'Delete the posts nobody else has replied to', $fields['erase_posts']['value'] );
		$this->assertSame( 'delete', $fields['erase_posts']['debug'] );
		$this->assertSame( '30', $fields['ip_retention_days']['value'] );
		$this->assertSame( 'No', $fields['show_register_terms']['value'] );
	}
}
