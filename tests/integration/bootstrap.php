<?php
/**
 * Bootstrap for integration tests with the WordPress test suite.
 *
 * @package easyQ
 */

$easyq_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $easyq_tests_dir ) {
	$easyq_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $easyq_tests_dir . '/includes/functions.php' ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- WordPress is not loaded yet.
	fwrite( STDERR, "WordPress test suite not found in $easyq_tests_dir. Run the tests through wp-env: npm run test:php\n" );
	exit( 1 );
}

define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills' );

require_once $easyq_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__, 2 ) . '/easyq.php';

		// Start every run with a fresh schema; the plugin installs it on plugins_loaded.
		EasyQ\Database\Schema::drop();
		delete_option( EasyQ\Database\Installer::DB_VERSION_OPTION );
		delete_option( EasyQ\Stats\Counts::OPTION );
	}
);

require $easyq_tests_dir . '/includes/bootstrap.php';
require_once __DIR__ . '/class-testcase.php';
