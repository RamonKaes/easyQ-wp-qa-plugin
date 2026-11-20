<?php
/**
 * Tests that no PHP file of the plugin runs when it is requested directly.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

/**
 * Reads every PHP file of the plugin and expects the check the Plugin Handbook asks for
 * before any code: `ABSPATH` for the plugin, `WP_UNINSTALL_PLUGIN` for `uninstall.php`.
 *
 * @coversNothing
 */
final class Direct_Access_Test extends Test_Case {

	public function test_every_php_file_stops_when_requested_directly(): void {
		$root  = dirname( __DIR__, 2 );
		$files = array( $root . '/easyq.php', $root . '/uninstall.php' );
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/includes', \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$files[] = $file->getPathname();
			}
		}
		$this->assertGreaterThan( 100, count( $files ) );

		$open = array();
		foreach ( $files as $file ) {
			$guard  = 'uninstall.php' === basename( $file ) ? 'WP_UNINSTALL_PLUGIN' : 'ABSPATH';
			$source = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			// The check comes before the first declaration or statement that does something.
			$check = strpos( $source, "defined( '$guard' ) || exit;" );
			$first = preg_match( '/^\s*(final |abstract )?(class|interface|trait|enum|function)\s|^\s*(require|include)(_once)?\b|^\s*add_(action|filter)\(/m', $source, $match, PREG_OFFSET_CAPTURE ) ? $match[0][1] : PHP_INT_MAX;
			if ( false === $check || $check > $first ) {
				$open[] = substr( $file, strlen( $root ) + 1 );
			}
		}
		$this->assertSame( array(), $open );
	}
}
