<?php
/**
 * Tests for rules of the source code that the code standards do not know.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @coversNothing
 */
final class Source_Test extends TestCase {

	/**
	 * Lists the places in PHP source that add a closure as a hook callback.
	 *
	 * Others can remove a hook only if they can name its callback.
	 *
	 * @param string $source PHP source.
	 * @return int[] Line numbers.
	 */
	private function closure_hooks( string $source ): array {
		preg_match_all( '/\badd_(?:action|filter)\(\s*[^,;]+,\s*(?:static\s+)?(?:function|fn)\b/', $source, $matches, PREG_OFFSET_CAPTURE );

		return array_map(
			static fn ( array $found ): int => substr_count( substr( $source, 0, $found[1] ), "\n" ) + 1,
			$matches[0]
		);
	}

	/**
	 * Returns the PHP files of the plugin.
	 *
	 * @return string[]
	 */
	private function sources(): array {
		$root  = dirname( __DIR__, 2 );
		$files = array( $root . '/easyq.php', $root . '/uninstall.php' );
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/includes', RecursiveDirectoryIterator::SKIP_DOTS ) ) as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$files[] = $file->getPathname();
			}
		}

		return $files;
	}

	public function test_hooks_have_named_callbacks(): void {
		$found = array();
		$files = $this->sources();
		$this->assertGreaterThan( 100, count( $files ) );

		foreach ( $files as $file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file in a test without WordPress.
			foreach ( $this->closure_hooks( (string) file_get_contents( $file ) ) as $line ) {
				$found[] = "$file:$line";
			}
		}

		$this->assertSame( array(), $found );
	}

	public function test_the_check_finds_closures_in_every_form(): void {
		$this->assertSame( array( 1 ), $this->closure_hooks( "add_action( 'init', function () {} );" ) );
		$this->assertSame( array( 1 ), $this->closure_hooks( "add_filter( 'a' . \$b, static fn (): int => 1 );" ) );
		$this->assertSame( array( 2 ), $this->closure_hooks( "\nadd_action(\n\t'init',\n\tstatic function (): void {}\n);" ) );
		$this->assertSame( array(), $this->closure_hooks( "add_action( 'init', array( \$this, 'load' ) );" ) );
		$this->assertSame( array(), $this->closure_hooks( "add_action( 'init', 'easyq_load' ); \$a = fn () => 1;" ) );
	}
}
