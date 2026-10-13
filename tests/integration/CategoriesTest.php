<?php
/**
 * Tests for category management.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Categories\Categories;
use EasyQ\Services;
use WP_Error;

/**
 * @covers \EasyQ\Categories\Categories
 * @covers \EasyQ\Categories\Category
 */
final class CategoriesTest extends TestCase {

	/**
	 * Service under test.
	 *
	 * @var Categories
	 */
	private Categories $categories;

	public function set_up(): void {
		parent::set_up();
		$this->categories = Services::categories();
	}

	/**
	 * Creates a category and fails the test if that does not work.
	 *
	 * @param int|null $parent_id Parent ID.
	 * @param string   $title     Name.
	 * @param string   $slug      Slug.
	 * @return int
	 */
	private function create( ?int $parent_id, string $title, string $slug = '' ): int {
		$id = $this->categories->create( $parent_id, $title, $slug );
		$this->assertIsInt( $id );

		return $id;
	}

	public function test_creates_categories_with_slug_and_backpath(): void {
		$parent = $this->create( null, 'Software Tools' );
		$child  = $this->create( $parent, 'Block Themes' );

		$this->assertSame( 'software-tools', $this->categories->find( $parent )?->slug );
		$this->assertSame( 'block-themes/software-tools', $this->categories->find( $child )?->backpath );
		$this->assertSame( array( 'software-tools', 'block-themes' ), $this->categories->find( $child )?->path_slugs() );
		$this->assertSame( $child, $this->categories->find_by_slugs( array( 'software-tools', 'block-themes' ) )?->id );
		$this->assertSame( array( $parent, $child ), array_map( static fn ( $category ) => $category->id, $this->categories->path( $child ) ) );
	}

	public function test_positions_new_categories_at_the_end(): void {
		$first  = $this->create( null, 'First' );
		$second = $this->create( null, 'Second' );

		$this->assertSame( 1, $this->categories->find( $first )?->position );
		$this->assertSame( 2, $this->categories->find( $second )?->position );
	}

	/**
	 * @dataProvider provide_invalid_categories
	 *
	 * @param string $title Name.
	 * @param string $slug  Slug.
	 * @param string $field Field expected to have an error.
	 */
	public function test_rejects_invalid_categories( string $title, string $slug, string $field ): void {
		$this->create( null, 'Existing', 'existing' );

		$result = $this->categories->create( null, $title, $slug );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotEmpty( $result->get_error_message( $field ) );
	}

	/**
	 * Provides invalid category input.
	 *
	 * @return array<string, array{string, string, string}>
	 */
	public static function provide_invalid_categories(): array {
		return array(
			'empty name'     => array( '', 'slug', 'title' ),
			'long name'      => array( str_repeat( 'x', 81 ), '', 'title' ),
			'duplicate name' => array( 'EXISTING', 'other', 'title' ),
			'duplicate slug' => array( 'Other', 'existing', 'slug' ),
			'slash in slug'  => array( 'Other', 'a/b', 'slug' ),
		);
	}

	public function test_allows_four_levels(): void {
		$level1 = $this->create( null, 'L1' );
		$level2 = $this->create( $level1, 'L2' );
		$level3 = $this->create( $level2, 'L3' );
		$level4 = $this->create( $level3, 'L4' );

		$this->assertInstanceOf( WP_Error::class, $this->categories->create( $level4, 'L5' ) );
	}

	public function test_renaming_a_slug_updates_descendant_backpaths(): void {
		$parent = $this->create( null, 'Parent' );
		$child  = $this->create( $parent, 'Child' );

		$this->assertTrue( $this->categories->update( $parent, 'Parent', 'renamed' ) );

		$this->assertSame( 'child/renamed', $this->categories->find( $child )?->backpath );
	}

	public function test_moving_updates_posts_and_counts(): void {
		$source = $this->create( null, 'Source' );
		$moved  = $this->create( $source, 'Moved' );
		$target = $this->create( null, 'Target' );

		$question = $this->ask( $this->user(), 'Question in a category', 'Text.', array(), $moved );
		$this->assertSame( 1, $this->categories->find( $source )?->question_count );

		$this->assertTrue( $this->categories->move( $moved, $target ) );

		$this->assertSame( array( $target, $moved, null ), $this->post( $question->id )->category_path );
		$this->assertSame( 0, $this->categories->find( $source )?->question_count );
		$this->assertSame( 1, $this->categories->find( $target )?->question_count );
		$this->assertSame( 'moved/target', $this->categories->find( $moved )?->backpath );
	}

	public function test_refuses_moves_into_the_own_subtree_or_too_deep(): void {
		$level1 = $this->create( null, 'L1' );
		$level2 = $this->create( $level1, 'L2' );
		$other  = $this->create( null, 'Other' );
		$deep   = $this->create( $this->create( $this->create( $other, 'O2' ), 'O3' ), 'O4' );

		$this->assertInstanceOf( WP_Error::class, $this->categories->move( $level1, $level2 ) );
		$this->assertInstanceOf( WP_Error::class, $this->categories->move( $level1, $deep ) );
	}

	public function test_reorders_siblings(): void {
		$a = $this->create( null, 'A' );
		$b = $this->create( null, 'B' );
		$c = $this->create( null, 'C' );

		$this->categories->set_position( $c, 1 );

		$this->assertSame( array( $c, $a, $b ), array_map( static fn ( $category ) => $category->id, $this->categories->children( null ) ) );
	}

	public function test_deleting_reassigns_posts_and_closes_the_gap(): void {
		$a        = $this->create( null, 'A' );
		$b        = $this->create( null, 'B' );
		$c        = $this->create( null, 'C' );
		$question = $this->ask( $this->user(), 'Question in category A', 'Text.', array(), $a );

		$this->assertTrue( $this->categories->delete( $a, $c ) );

		$this->assertNull( $this->categories->find( $a ) );
		$this->assertSame( $c, $this->post( $question->id )->category_id );
		$this->assertSame( 1, $this->categories->find( $c )?->question_count );
		$this->assertSame( 1, $this->categories->find( $b )?->position );
		$this->assertSame( 2, $this->categories->find( $c )?->position );
	}

	public function test_categories_with_children_cannot_be_deleted(): void {
		$parent = $this->create( null, 'Parent' );
		$this->create( $parent, 'Child' );

		$this->assertInstanceOf( WP_Error::class, $this->categories->delete( $parent ) );
	}

	public function test_counts_include_subcategories(): void {
		$parent = $this->create( null, 'Parent' );
		$child  = $this->create( $parent, 'Child' );
		$this->ask( $this->user(), 'Question in the parent', 'x', array(), $parent );
		$this->ask( $this->user(), 'Question in the child one', 'x', array(), $child );

		$this->assertSame( 2, $this->categories->find( $parent )?->question_count );
		$this->assertSame( 1, $this->categories->find( $child )?->question_count );
	}
}
