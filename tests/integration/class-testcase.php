<?php
/**
 * Base class for integration tests.
 *
 * @package easyQ
 */

namespace easyQ\Tests\Integration;

use WP_UnitTestCase;

/**
 * Shared helpers for integration tests.
 */
abstract class TestCase extends WP_UnitTestCase {

	/**
	 * Checks whether a table exists.
	 *
	 * Works for temporary tables too, which the test suite creates instead of
	 * regular tables during a test and which SHOW TABLES does not list.
	 *
	 * @param string $table Full table name.
	 * @return bool
	 */
	protected function table_exists( string $table ): bool {
		global $wpdb;

		$suppress = $wpdb->suppress_errors();
		$result   = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
		$wpdb->suppress_errors( $suppress );

		return null !== $result;
	}

	/**
	 * Marks the plugin as network-activated for the current test.
	 */
	protected function activate_network_wide(): void {
		update_site_option(
			'active_sitewide_plugins',
			array( plugin_basename( \easyQ\PLUGIN_FILE ) => time() )
		);
	}
}
