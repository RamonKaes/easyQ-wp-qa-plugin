<?php
/**
 * Tests for category management.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Categories\Categories;
use EasyQ\Search\Indexed_Post;
use EasyQ\Search\Search_Provider;
use EasyQ\Services;
use EasyQ\Settings\Options;
use WP_Error;

/**
 * @covers \EasyQ\Categories\Categories
 * @covers \EasyQ\Categories\Category
 */
final class Categories_Test extends Test_Case {

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

	public function test_new_categories_take_a_position_and_a_free_slug(): void {
		$a = $this->create( null, 'A' );
		$b = $this->create( null, 'B' );
		$c = $this->categories->create( null, 'C', '', '', 2 );
		$this->assertIsInt( $c );
		$this->assertSame( array( $a, $c, $b ), array_map( static fn ( $category ) => $category->id, $this->categories->children( null ) ) );

		// A name that gives a slug used by a sibling, or no slug at all, falls back as in Question2Answer.
		$this->create( null, 'Tools', 'tools-x' );
		$first  = $this->create( null, 'Tools x' );
		$second = $this->create( null, 'Tools-X' );
		$third  = $this->create( null, 'Tools  X' );
		$empty  = $this->create( null, '???' );
		$this->assertSame( 'category-tools-x', $this->categories->find( $first )?->slug );
		$this->assertSame( 'category-1', $this->categories->find( $second )?->slug );
		$this->assertSame( 'category-2', $this->categories->find( $third )?->slug );
		$this->assertSame( 'category', $this->categories->find( $empty )?->slug );

		// An explicit slug is never replaced.
		$this->assertInstanceOf( WP_Error::class, $this->categories->create( null, 'Other', 'tools-x' ) );
	}

	public function test_saving_checks_every_change_before_storing_any(): void {
		$parent = $this->create( null, 'Parent' );
		$child  = $this->create( $parent, 'Child' );
		$taken  = $this->create( null, 'Taken', 'child' );
		$a      = $this->create( $parent, 'A' );

		// The slug is free at the old place, but not at the new one.
		$result = $this->categories->save( $child, 'Renamed', 'child', 'New text', null );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotEmpty( $result->get_error_message( 'slug' ) );
		$child_now = $this->categories->find( $child );
		$this->assertSame( 'Child', $child_now?->title );
		$this->assertSame( $parent, $child_now->parent_id );

		$this->assertTrue( $this->categories->save( $child, 'Renamed', 'renamed', 'New text', $taken, 1 ) );
		$child_now = $this->categories->find( $child );
		$this->assertSame( 'Renamed', $child_now?->title );
		$this->assertSame( 'New text', $child_now->description );
		$this->assertSame( 'renamed/child', $child_now->backpath );
		$this->assertSame( 1, $this->categories->find( $a )?->position );

		$this->assertTrue( $this->categories->save( $a, 'A', 'a', '', $parent, 5 ) );
		$this->assertInstanceOf( WP_Error::class, $this->categories->save( $parent, 'Parent', 'parent', '', $a ) );
		$this->assertInstanceOf( WP_Error::class, $this->categories->save( 999, 'X', 'x', '', null ) );
	}

	public function test_lists_the_tree_and_the_possible_parents(): void {
		$l1    = $this->create( null, 'L1' );
		$l2    = $this->create( $l1, 'L2' );
		$l3    = $this->create( $l2, 'L3' );
		$other = $this->create( null, 'Other' );

		$tree = array_map( static fn ( array $item ): array => array( $item[0]->id, $item[1] ), $this->categories->tree() );
		$this->assertSame( array( array( $l1, 0 ), array( $l2, 1 ), array( $l3, 2 ), array( $other, 0 ) ), $tree );

		$ids = static fn ( array $items ): array => array_map( static fn ( array $item ): int => $item[0]->id, $items );
		// A new category can go below anything but the fourth level.
		$this->assertSame( array( $l1, $l2, $l3, $other ), $ids( $this->categories->possible_parents( null ) ) );
		// L2 has one level below it, so its parent must be on the first or second level, outside its own subtree.
		$this->assertSame( array( $l1, $other ), $ids( $this->categories->possible_parents( $l2 ) ) );
		$this->assertSame( array( $l1, $l2, $l3 ), $ids( $this->categories->possible_parents( $other ) ) );
	}

	public function test_reassigns_questions_without_a_category_or_subcategory(): void {
		$moved = new \ArrayObject();
		add_filter(
			'easyq_search_providers',
			static fn ( array $providers ): array => array_merge(
				$providers,
				array(
					new class( $moved ) implements Search_Provider {
						/**
						 * @param \ArrayObject<int, array{int, int|null}> $moved Moves seen.
						 */
						public function __construct( private \ArrayObject $moved ) {}

						public function id(): string {
							return 'spy';
						}

						public function label(): string {
							return 'Spy';
						}

						public function index_post( Indexed_Post $post ): void {}

						public function unindex_post( int $post_id ): void {}

						public function move_post( int $post_id, ?int $category_id ): void {
							$this->moved[] = array( $post_id, $category_id );
						}

						public function search( string $query, int $start, int $count, ?int $user_id ): array {
							return array();
						}
					},
				)
			)
		);
		Services::reset();
		$categories = Services::categories();

		$parent   = $this->create( null, 'Parent' );
		$child    = $this->create( $parent, 'Child' );
		$loose    = $this->ask( $this->user(), 'Question without category', 'x' );
		$answer   = $this->answer( $this->user(), $loose );
		$in_child = $this->ask( $this->user(), 'Question in the subcategory', 'x', array(), $child );
		$direct   = $this->ask( $this->user(), 'Question in the parent only', 'x', array(), $parent );

		$this->assertSame( 1, $categories->count_direct_questions( null ) );
		$this->assertSame( 1, $categories->count_direct_questions( $parent ) );

		$this->assertTrue( $categories->reassign( null, $parent ) );
		$this->assertSame( $parent, $this->post( $loose->id )->category_id );
		$this->assertSame( $parent, $this->post( $answer->id )->category_id );
		$this->assertSame( array( array( $loose->id, $parent ), array( $answer->id, $parent ) ), $moved->getArrayCopy() );
		$this->assertSame( 3, $categories->find( $parent )?->question_count );

		// Only the questions directly in the parent move.
		$this->assertTrue( $categories->reassign( $parent, $child ) );
		$this->assertSame( array( $parent, $child, null ), $this->post( $direct->id )->category_path );
		$this->assertSame( $child, $this->post( $in_child->id )->category_id );
		$this->assertSame( 0, $categories->count_direct_questions( $parent ) );
		$this->assertSame( 3, $categories->find( $child )?->question_count );
		$this->assertSame( 3, $categories->find( $parent )?->question_count );

		$this->assertInstanceOf( WP_Error::class, $categories->reassign( $child, $child ) );
		$this->assertInstanceOf( WP_Error::class, $categories->reassign( $child, 999 ) );
		$this->assertInstanceOf( WP_Error::class, $categories->reassign( 999, $child ) );
	}

	public function test_checks_the_category_of_a_question_against_the_settings(): void {
		// Without categories, none is needed.
		$this->assertNull( $this->categories->check_choice( null ) );

		$parent = $this->create( null, 'Parent' );
		$child  = $this->create( $parent, 'Child' );
		$other  = $this->create( null, 'Other' );

		$this->assertSame( 'category', $this->categories->check_choice( null )?->get_error_code() );
		$this->assertSame( 'category', $this->categories->check_choice( $parent )?->get_error_code() );
		$this->assertSame( 'category', $this->categories->check_choice( 999 )?->get_error_code() );
		$this->assertNull( $this->categories->check_choice( $child ) );
		$this->assertNull( $this->categories->check_choice( $other ) );

		// Edits may keep what a question has, but not change to an invalid choice.
		$loose    = $this->ask( $this->user(), 'Question without category', 'x' );
		$in_other = $this->ask( $this->user(), 'Question in another category', 'x', array(), $other );
		$this->assertNull( $this->categories->check_choice( null, $loose ) );
		$this->assertNull( $this->categories->check_choice( $other, $in_other ) );
		$this->assertNotNull( $this->categories->check_choice( null, $in_other ) );
		$this->assertNotNull( $this->categories->check_choice( $parent, $loose ) );

		Options::update(
			'posting',
			array(
				'allow_no_category'     => true,
				'allow_no_sub_category' => true,
			)
		);
		$this->assertNull( $this->categories->check_choice( null ) );
		$this->assertNull( $this->categories->check_choice( $parent ) );
		$this->assertNotNull( $this->categories->check_choice( 999 ) );
	}
}
