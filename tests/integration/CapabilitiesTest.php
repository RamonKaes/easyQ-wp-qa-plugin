<?php
/**
 * Tests for the capability mapping.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Capabilities;

/**
 * @covers \EasyQ\Capabilities
 */
final class CapabilitiesTest extends TestCase {

	/**
	 * @dataProvider provide_role_capabilities
	 *
	 * @param string $role     Role of the user.
	 * @param bool   $manage   Expected result for easyq_manage.
	 * @param bool   $moderate Expected result for easyq_moderate.
	 */
	public function test_maps_capabilities_by_role( string $role, bool $manage, bool $moderate ): void {
		$user_id = self::factory()->user->create( array( 'role' => $role ) );

		$this->assertSame( $manage, user_can( $user_id, Capabilities::MANAGE ) );
		$this->assertSame( $moderate, user_can( $user_id, Capabilities::MODERATE ) );
	}

	/**
	 * Provides the expected capabilities per core role.
	 *
	 * @return array<string, array{string, bool, bool}>
	 */
	public static function provide_role_capabilities(): array {
		return array(
			'administrator' => array( 'administrator', true, true ),
			'editor'        => array( 'editor', false, true ),
			'author'        => array( 'author', false, false ),
			'contributor'   => array( 'contributor', false, false ),
			'subscriber'    => array( 'subscriber', false, false ),
		);
	}

	public function test_visitors_have_no_capabilities(): void {
		$this->assertFalse( current_user_can( Capabilities::MANAGE ) );
		$this->assertFalse( current_user_can( Capabilities::MODERATE ) );
	}

	public function test_leaves_other_capabilities_untouched(): void {
		$this->assertSame(
			array( 'edit_posts' ),
			( new Capabilities() )->map_meta_cap( array( 'edit_posts' ), 'edit_posts' )
		);
	}
}
