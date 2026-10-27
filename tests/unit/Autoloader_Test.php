<?php
/**
 * Tests for the autoloader.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Unit;

use EasyQ\Autoloader;
use PHPUnit\Framework\TestCase;

/**
 * @covers \EasyQ\Autoloader
 */
final class Autoloader_Test extends TestCase {

	/**
	 * Autoloader under test.
	 *
	 * @var Autoloader
	 */
	private Autoloader $autoloader;

	protected function setUp(): void {
		$this->autoloader = new Autoloader( 'EasyQ', '/base' );
	}

	public function test_maps_class_in_root_namespace(): void {
		$this->assertSame(
			array(
				'/base/class-plugin.php',
				'/base/interface-plugin.php',
				'/base/trait-plugin.php',
				'/base/enum-plugin.php',
			),
			$this->autoloader->candidate_files( 'EasyQ\Plugin' )
		);
	}

	public function test_maps_sub_namespaces_and_underscores_to_lowercase_hyphens(): void {
		$files = $this->autoloader->candidate_files( '\EasyQ\Database\Schema_Manager' );

		$this->assertSame( '/base/database/class-schema-manager.php', $files[0] );
	}

	public function test_maps_nested_namespace_with_underscores(): void {
		$files = $this->autoloader->candidate_files( 'EasyQ\REST_API\Post_Controller' );

		$this->assertSame( '/base/rest-api/class-post-controller.php', $files[0] );
	}

	public function test_ignores_symbols_outside_the_root_namespace(): void {
		$this->assertSame( array(), $this->autoloader->candidate_files( 'Other\Plugin' ) );
		$this->assertSame( array(), $this->autoloader->candidate_files( 'EasyQuery\Plugin' ) );
	}

	public function test_loads_existing_plugin_classes(): void {
		$this->assertTrue( class_exists( \EasyQ\Database\Schema::class ) );
		$this->assertTrue( class_exists( \EasyQ\Settings\Options::class ) );
	}
}
