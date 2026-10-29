<?php
/**
 * Tests for changing categories through the REST API.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Services;
use EasyQ\Settings\Options;

/**
 * @covers \EasyQ\REST\Categories_Controller
 */
final class Categories_REST_Test extends REST_Test_Case {

	public function test_only_users_who_manage_easyq_change_categories(): void {
		$this->assertSame( 401, $this->request( 'POST', '/categories', array( 'title' => 'Software' ) )->get_status() );

		$this->login( 'editor' );
		$this->assertSame( 403, $this->request( 'POST', '/categories', array( 'title' => 'Software' ) )->get_status() );
		$this->assertSame( array(), Services::categories()->all() );

		$category = Services::categories()->create( null, 'Software' );
		$this->assertIsInt( $category );
		$this->assertSame( 403, $this->request( 'PUT', '/categories/' . $category, array( 'title' => 'Hardware' ) )->get_status() );
		$this->assertSame( 403, $this->request( 'DELETE', '/categories/' . $category )->get_status() );
		$this->assertSame( 403, $this->request( 'POST', '/categories/0/reassign', array( 'to' => $category ) )->get_status() );
		$this->assertSame( 'Software', Services::categories()->find( $category )?->title );
	}

	public function test_creates_changes_and_deletes_categories(): void {
		wp_set_current_user( $this->administrator() );

		$response = $this->request(
			'POST',
			'/categories',
			array(
				'title'       => 'Software',
				'description' => 'Programs and apps',
			)
		);
		$this->assertSame( 201, $response->get_status() );
		$parent = $this->data( $response );
		$this->assertSame( 'software', $parent['slug'] );
		$this->assertSame( 'Programs and apps', $parent['description'] );
		$this->assertStringEndsWith( '/easyq/v1/categories/' . $parent['id'], $response->get_headers()['Location'] );

		$child = $this->data(
			$this->request(
				'POST',
				'/categories',
				array(
					'title'  => 'Block Themes',
					'slug'   => 'Themes',
					'parent' => $parent['id'],
				)
			)
		);
		$this->assertSame( $parent['id'], $child['parent'] );
		$this->assertSame( array( 'software', 'themes' ), $child['path'] );

		$first = $this->data(
			$this->request(
				'POST',
				'/categories',
				array(
					'title'    => 'Hardware',
					'position' => 1,
				)
			)
		);
		$this->assertSame( 1, $first['position'] );

		// Only what is passed changes; moving to the top level with a position.
		$moved = $this->data(
			$this->request(
				'PATCH',
				'/categories/' . $child['id'],
				array(
					'parent'   => 0,
					'position' => 1,
				)
			)
		);
		$this->assertNull( $moved['parent'] );
		$this->assertSame( 1, $moved['position'] );
		$this->assertSame( 'Block Themes', $moved['title'] );
		$this->assertSame( 'themes', $moved['slug'] );

		$response = $this->request( 'PUT', '/categories/' . $child['id'], array( 'title' => 'HARDWARE' ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertArrayHasKey( 'title', $response->as_error()->get_error_data()['fields'] );
		$this->assertSame( 400, $this->request( 'POST', '/categories', array( 'title' => '' ) )->get_status() );
		$this->assertSame( 404, $this->request( 'PUT', '/categories/999', array( 'title' => 'X' ) )->get_status() );

		$question = $this->ask( $this->user(), 'Question about themes', 'x', array(), $child['id'] );
		$deleted  = $this->data( $this->request( 'DELETE', '/categories/' . $child['id'], array( 'reassign' => $parent['id'] ) ) );
		$this->assertTrue( $deleted['deleted'] );
		$this->assertSame( 'Block Themes', $deleted['previous']['title'] );
		$this->assertNull( Services::categories()->find( $child['id'] ) );
		$this->assertSame( $parent['id'], $this->post( $question->id )->category_id );
		$this->assertSame( 404, $this->request( 'DELETE', '/categories/' . $child['id'] )->get_status() );
	}

	public function test_refuses_to_delete_categories_with_subcategories(): void {
		wp_set_current_user( $this->administrator() );
		$parent = Services::categories()->create( null, 'Parent' );
		$this->assertIsInt( $parent );
		Services::categories()->create( $parent, 'Child' );

		$response = $this->request( 'DELETE', '/categories/' . $parent );

		$this->assertSame( 400, $response->get_status() );
		$this->assertNotNull( Services::categories()->find( $parent ) );
	}

	public function test_reassigns_questions_without_a_category(): void {
		wp_set_current_user( $this->administrator() );
		$target = Services::categories()->create( null, 'Target' );
		$this->assertIsInt( $target );
		$first  = $this->ask( $this->user(), 'First question without category', 'x' );
		$second = $this->ask( $this->user(), 'Second question without category', 'x' );

		$this->assertSame( array( 'moved' => 2 ), $this->data( $this->request( 'POST', '/categories/0/reassign', array( 'to' => $target ) ) ) );
		$this->assertSame( $target, $this->post( $first->id )->category_id );
		$this->assertSame( $target, $this->post( $second->id )->category_id );

		$this->assertSame( 400, $this->request( 'POST', '/categories/' . $target . '/reassign', array( 'to' => $target ) )->get_status() );
		$this->assertSame( 404, $this->request( 'POST', '/categories/999/reassign', array( 'to' => $target ) )->get_status() );
		$this->assertSame( 400, $this->request( 'POST', '/categories/0/reassign' )->get_status() );
	}

	public function test_questions_follow_the_category_settings(): void {
		$parent = Services::categories()->create( null, 'Parent' );
		$this->assertIsInt( $parent );
		$child = Services::categories()->create( $parent, 'Child' );
		$this->assertIsInt( $child );
		$this->login();
		$question = array(
			'title'   => 'A question that needs a category',
			'content' => 'Some details for it.',
		);

		$response = $this->request( 'POST', '/questions', $question );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'Please choose a category.', $response->as_error()->get_error_data()['fields']['category'] );

		$response = $this->request( 'POST', '/questions', $question + array( 'category' => $parent ) );
		$this->assertSame( 'Please choose a subcategory.', $response->as_error()->get_error_data()['fields']['category'] );

		$created = $this->data( $this->request( 'POST', '/questions', $question + array( 'category' => $child ) ) );
		$this->assertSame( $child, $created['category']['id'] );

		// Editing keeps an existing choice, but cannot remove the category.
		$this->login( 'editor' );
		$this->assertSame( 200, $this->request( 'PUT', '/questions/' . $created['id'], array( 'title' => 'A question with a new title' ) )->get_status() );
		$this->assertSame( 400, $this->request( 'PUT', '/questions/' . $created['id'], array( 'category' => null ) )->get_status() );
		$this->assertSame( 400, $this->request( 'POST', '/posts/' . $created['id'] . '/actions/move', array( 'category' => $parent ) )->get_status() );
		$this->assertSame( $child, $this->post( $created['id'] )->category_id );

		Options::update(
			'posting',
			array(
				'allow_no_category'     => true,
				'allow_no_sub_category' => true,
			)
		);
		$this->assertSame( 200, $this->request( 'POST', '/posts/' . $created['id'] . '/actions/move', array( 'category' => $parent ) )->get_status() );
		$this->assertSame( 200, $this->request( 'PUT', '/questions/' . $created['id'], array( 'category' => null ) )->get_status() );
		$this->assertNull( $this->post( $created['id'] )->category_id );
	}
}
