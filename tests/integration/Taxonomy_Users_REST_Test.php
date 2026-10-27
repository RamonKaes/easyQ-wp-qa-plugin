<?php
/**
 * Tests for the category, tag and user endpoints.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Services;
use EasyQ\Users\Permits;
use EasyQ\Users\User_Field;

/**
 * @covers \EasyQ\REST\Taxonomy_Controller
 * @covers \EasyQ\REST\Users_Controller
 * @covers \EasyQ\Posts\Tags
 */
final class Taxonomy_Users_REST_Test extends REST_Test_Case {

	public function test_lists_categories(): void {
		$parent = Services::categories()->create( null, 'Parent' );
		$this->assertIsInt( $parent );
		$child = Services::categories()->create( $parent, 'Child' );
		$this->assertIsInt( $child );

		$this->assertSame( array( $parent, $child ), array_column( $this->data( $this->request( 'GET', '/categories' ) ), 'id' ) );
		$this->assertSame( array( $parent ), array_column( $this->data( $this->request( 'GET', '/categories', array( 'parent' => 0 ) ) ), 'id' ) );
		$this->assertSame( array( $child ), array_column( $this->data( $this->request( 'GET', '/categories', array( 'parent' => $parent ) ) ), 'id' ) );

		$data = $this->data( $this->request( 'GET', '/categories/' . $child ) );
		$this->assertSame( array( 'parent', 'child' ), $data['path'] );
		$this->assertSame( 404, $this->request( 'GET', '/categories/999' )->get_status() );
	}

	public function test_lists_and_completes_tags(): void {
		$author = $this->user();
		$this->ask( $author, 'First tagged question', 'x', array( 'wordpress', 'php' ) );
		$this->ask( $author, 'Second tagged question', 'x', array( 'wordpress' ) );

		$data = $this->data( $this->request( 'GET', '/tags' ) );
		$this->assertSame(
			array(
				array(
					'name'  => 'wordpress',
					'count' => 2,
				),
				array(
					'name'  => 'php',
					'count' => 1,
				),
			),
			$data
		);

		$this->assertSame( array( 'php' ), array_column( $this->data( $this->request( 'GET', '/tags', array( 'search' => 'P' ) ) ), 'name' ) );
	}

	public function test_returns_public_profiles(): void {
		$user       = self::factory()->user->create( array( 'display_name' => 'Ada' ) );
		$open       = Services::user_fields()->all()[0];
		$private_id = Services::user_fields()->create( 'phone', '', 0, Permits::EDITORS );
		$this->assertIsInt( $private_id );
		$private = Services::user_fields()->find( $private_id );
		$this->assertInstanceOf( User_Field::class, $private );
		Services::user_fields()->set_value( $user, $open, 'Berlin' );
		Services::user_fields()->set_value( $user, $private, '0123' );
		$this->ask( new \EasyQ\Actor( $user ) );

		$data = $this->data( $this->request( 'GET', '/users/' . $user ) );

		$this->assertSame( 'Ada', $data['name'] );
		$this->assertSame( 120, $data['points'] );
		$this->assertSame( 1, $data['counts']['questions'] );
		$this->assertSame( array( 'location' ), array_column( $data['fields'], 'name' ) );
		$this->assertArrayNotHasKey( 'email', $data );

		$this->login( 'editor' );
		$this->assertSame( array( 'location', 'phone' ), array_column( $this->data( $this->request( 'GET', '/users/' . $user ) )['fields'], 'name' ) );
		$this->assertSame( 404, $this->request( 'GET', '/users/999999' )->get_status() );
	}
}
