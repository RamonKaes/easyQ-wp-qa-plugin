<?php
/**
 * Tests for managing the profile fields through the REST API.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Services;
use EasyQ\Users\Permits;

/**
 * @covers \EasyQ\REST\User_Fields_Controller
 */
final class User_Fields_REST_Test extends REST_Test_Case {

	public function test_only_users_who_manage_easyq_manage_the_fields(): void {
		$this->assertSame( 401, $this->request( 'GET', '/user-fields' )->get_status() );

		$this->login( 'editor' );
		$this->assertSame( 403, $this->request( 'GET', '/user-fields' )->get_status() );
		$this->assertSame( 403, $this->request( 'POST', '/user-fields', array( 'label' => 'Sneaky' ) )->get_status() );
		$this->assertCount( 1, Services::user_fields()->all() );
	}

	public function test_adds_changes_and_deletes_fields(): void {
		wp_set_current_user( $this->administrator() );

		$response = $this->request(
			'POST',
			'/user-fields',
			array(
				'label'       => 'Favourite Book',
				'type'        => 'multi_line',
				'permit'      => Permits::USERS,
				'on_register' => true,
				'position'    => 1,
			)
		);
		$this->assertSame( 201, $response->get_status() );
		$field = $this->data( $response );
		$this->assertSame(
			array(
				'id'          => $field['id'],
				'name'        => 'favourite-book',
				'label'       => 'Favourite Book',
				'type'        => 'multi_line',
				'permit'      => Permits::USERS,
				'on_register' => true,
				'position'    => 1,
			),
			$field
		);
		$this->assertSame( array( 'favourite-book', 'location' ), array_column( $this->data( $this->request( 'GET', '/user-fields' ) ), 'name' ) );

		// Only what is passed changes; the name stays.
		$changed = $this->data(
			$this->request(
				'PATCH',
				'/user-fields/' . $field['id'],
				array(
					'type'     => 'link',
					'permit'   => Permits::ALL,
					'position' => 2,
				)
			)
		);
		$this->assertSame( 'link', $changed['type'] );
		$this->assertSame( Permits::ALL, $changed['permit'] );
		$this->assertNull( Services::user_fields()->find( $field['id'] )?->permit );
		$this->assertTrue( $changed['on_register'] );
		$this->assertSame( 'Favourite Book', $changed['label'] );
		$this->assertSame( 2, $changed['position'] );

		$duplicate = array(
			'label' => 'Another location',
			'name'  => 'location',
		);
		$this->assertSame( 400, $this->request( 'POST', '/user-fields', $duplicate )->get_status() );
		$this->assertSame( 400, $this->request( 'PATCH', '/user-fields/' . $field['id'], array( 'permit' => Permits::APPROVED ) )->get_status() );
		$this->assertSame( 404, $this->request( 'PATCH', '/user-fields/999', array( 'label' => 'x' ) )->get_status() );

		$deleted = $this->data( $this->request( 'DELETE', '/user-fields/' . $field['id'] ) );
		$this->assertTrue( $deleted['deleted'] );
		$this->assertSame( 'favourite-book', $deleted['previous']['name'] );
		$this->assertNull( Services::user_fields()->find( $field['id'] ) );
		$this->assertSame( 404, $this->request( 'GET', '/user-fields/' . $field['id'] )->get_status() );
	}
}
