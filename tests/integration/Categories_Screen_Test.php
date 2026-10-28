<?php
/**
 * Tests for the categories screen.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Admin\Categories_Screen;
use EasyQ\Admin\Settings_Screen;
use EasyQ\Capabilities;
use EasyQ\Services;
use EasyQ\Settings\Options;

/**
 * @covers \EasyQ\Admin\Categories_Screen
 * @covers \EasyQ\Admin\Categories_List_Table
 */
final class Categories_Screen_Test extends Test_Case {

	public function set_up(): void {
		parent::set_up();

		wp_set_current_user( $this->administrator() );
	}

	public function tear_down(): void {
		unset( $_GET['action'], $_GET['category'], $_GET['parent'], $_GET['message'] );

		parent::tear_down();
	}

	/**
	 * Renders the screen.
	 *
	 * @param Categories_Screen    $screen Screen.
	 * @param array<string, mixed> $query  Query arguments.
	 * @return string
	 */
	private function render( Categories_Screen $screen, array $query = array() ): string {
		foreach ( $query as $key => $value ) {
			$_GET[ $key ] = (string) $value;
		}

		ob_start();
		$screen->render();

		return (string) ob_get_clean();
	}

	/**
	 * Creates a category and fails the test if that does not work.
	 *
	 * @param int|null $parent_id Parent ID.
	 * @param string   $title     Name.
	 * @return int
	 */
	private function create( ?int $parent_id, string $title ): int {
		$id = Services::categories()->create( $parent_id, $title );
		$this->assertIsInt( $id );

		return $id;
	}

	public function test_menu_entry_needs_categories_in_use(): void {
		global $submenu;

		Options::update( 'general', array( 'tags_or_categories' => 't' ) );
		( new Categories_Screen() )->add_menu();
		$this->assertArrayNotHasKey( Categories_Screen::PAGE, array_column( $submenu[ Settings_Screen::PAGE ] ?? array(), 1, 2 ) );

		Options::update( 'general', array( 'tags_or_categories' => 'tc' ) );
		( new Categories_Screen() )->add_menu();
		$this->assertSame( Capabilities::MANAGE, array_column( $submenu[ Settings_Screen::PAGE ] ?? array(), 1, 2 )[ Categories_Screen::PAGE ] ?? null );
	}

	public function test_adds_categories_and_shows_errors_with_the_input(): void {
		$parent = $this->create( null, 'Software' );
		$screen = new Categories_Screen();

		$redirect = $screen->handle(
			array(
				'easyq_action' => 'add',
				'title'        => 'Block Themes',
				'slug'         => '',
				'description'  => 'Themes made of blocks',
				'parent'       => (string) $parent,
			)
		);
		$this->assertSame(
			Categories_Screen::url(
				array(
					'message' => 'added',
					'parent'  => $parent,
				)
			),
			$redirect
		);
		$child = Services::categories()->children( $parent )[0] ?? null;
		$this->assertSame( 'Block Themes', $child?->title );
		$this->assertSame( 'Themes made of blocks', $child->description );

		$this->assertNull(
			$screen->handle(
				array(
					'easyq_action' => 'add',
					'title'        => 'block themes',
					'parent'       => (string) $parent,
				)
			)
		);
		$html = $this->render( $screen );
		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'value="block themes" required aria-invalid="true" aria-describedby="easyq-category-title-error"', $html );
		$this->assertStringContainsString( 'id="easyq-category-title-error"><strong>Another category with this name already exists here.', $html );
		$this->assertMatchesRegularExpression( '/<option value="' . $parent . '"\s+selected=\'selected\'>Software<\/option>/', $html );
		$this->assertCount( 1, Services::categories()->children( $parent ) );
	}

	public function test_lists_categories_as_a_tree_with_their_actions(): void {
		$parent = $this->create( null, 'Software' );
		$child  = $this->create( $parent, 'Block Themes' );
		$this->create( $this->create( $child, 'Level 3' ), 'Level 4' );

		$html = $this->render( new Categories_Screen(), array( 'parent' => $parent ) );

		$this->assertStringContainsString( '<h1>Categories</h1>', $html );
		$this->assertStringContainsString( '<strong>— <a class="row-title" href="' . esc_url( Categories_Screen::edit_url( $child ) ) . '">Block Themes</a></strong>', $html );
		$this->assertSame( 3, substr_count( $html, '>Add subcategory</a>' ) );
		// The fourth level is not offered as a parent.
		$this->assertStringNotContainsString( '>— — — Level 4</option>', $html );
		$this->assertMatchesRegularExpression( '/<option value="' . $parent . '"\s+selected=\'selected\'>Software<\/option>/', $html );
	}

	public function test_edits_orders_and_moves_categories(): void {
		$software = $this->create( null, 'Software' );
		$hardware = $this->create( null, 'Hardware' );
		$tools    = $this->create( null, 'Tools' );
		$screen   = new Categories_Screen();

		$html = $this->render(
			$screen,
			array(
				'action'   => 'edit',
				'category' => $tools,
			)
		);
		$this->assertStringContainsString( '<h1>Edit Category</h1>', $html );
		$this->assertStringContainsString( 'value="Tools"', $html );
		$this->assertMatchesRegularExpression( '/<option value="3"\s+selected=\'selected\'>After “Hardware”<\/option>/', $html );

		$base = array(
			'easyq_action' => 'save',
			'category'     => (string) $tools,
			'title'        => 'Tools',
			'slug'         => 'tools',
			'description'  => '',
			'parent'       => '0',
		);
		$this->assertSame(
			Categories_Screen::url(
				array(
					'action'   => 'edit',
					'category' => $tools,
					'message'  => 'saved',
				)
			),
			$screen->handle( array( 'position' => '1' ) + $base )
		);
		$this->assertSame( array( $tools, $software, $hardware ), array_map( static fn ( $category ) => $category->id, Services::categories()->children( null ) ) );

		// A new parent puts the category at the end of its new siblings, whatever the position says.
		$this->create( $software, 'Editors' );
		$this->assertNotNull(
			$screen->handle(
				array(
					'parent'   => (string) $software,
					'position' => '1',
				) + $base
			)
		);
		$this->assertSame( 2, Services::categories()->find( $tools )?->position );
		$this->assertSame( $software, Services::categories()->find( $tools )?->parent_id );

		$this->assertNull( $screen->handle( array( 'title' => '' ) + $base ) );
		$this->assertStringContainsString(
			'Please enter a name.',
			$this->render(
				$screen,
				array(
					'action'   => 'edit',
					'category' => $tools,
				)
			)
		);
	}

	public function test_deletes_categories_and_moves_their_questions(): void {
		$parent   = $this->create( null, 'Parent' );
		$child    = $this->create( $parent, 'Child' );
		$question = $this->ask( $this->user(), 'Question in the child', 'x', array(), $child );
		$screen   = new Categories_Screen();

		$html = $this->render(
			$screen,
			array(
				'action'   => 'edit',
				'category' => $parent,
			)
		);
		$this->assertStringContainsString( 'This category cannot be deleted because it has subcategories.', $html );
		$this->assertStringNotContainsString( 'value="delete"', $html );

		$html = $this->render(
			$screen,
			array(
				'action'   => 'edit',
				'category' => $child,
			)
		);
		$this->assertStringContainsString( 'value="delete"', $html );
		// The parent is preselected to receive the questions, as in Question2Answer.
		$this->assertMatchesRegularExpression( '/name="reassign">.*<option value="' . $parent . '"\s+selected=\'selected\'>Parent<\/option>/s', $html );

		$this->assertNull(
			$screen->handle(
				array(
					'easyq_action' => 'delete',
					'category'     => (string) $parent,
				)
			)
		);
		$this->assertNotNull( Services::categories()->find( $parent ) );

		$this->assertSame(
			Categories_Screen::url( array( 'message' => 'deleted' ) ),
			$screen->handle(
				array(
					'easyq_action' => 'delete',
					'category'     => (string) $child,
					'reassign'     => (string) $parent,
				)
			)
		);
		$this->assertNull( Services::categories()->find( $child ) );
		$this->assertSame( $parent, $this->post( $question->id )->category_id );
		$this->assertSame(
			Categories_Screen::url( array( 'message' => 'missing' ) ),
			$screen->handle(
				array(
					'easyq_action' => 'delete',
					'category'     => (string) $child,
				)
			)
		);
	}

	public function test_warns_about_questions_without_a_category_or_subcategory_and_moves_them(): void {
		$parent = $this->create( null, 'Parent' );
		$child  = $this->create( $parent, 'Child' );
		$loose  = $this->ask( $this->user(), 'Question without a category', 'x' );
		$direct = $this->ask( $this->user(), 'Question only in the parent', 'x', array(), $parent );
		$screen = new Categories_Screen();

		$this->assertStringContainsString(
			'1 question has no category. <a href="' . esc_url(
				Categories_Screen::url(
					array(
						'action'   => 'reassign',
						'category' => 0,
					)
				)
			) . '">',
			$this->render( $screen )
		);
		$this->assertStringContainsString(
			'1 question in this category has no subcategory.',
			$this->render(
				$screen,
				array(
					'action'   => 'edit',
					'category' => $parent,
				)
			)
		);

		// Moving to a subcategory offers only the subcategories.
		$html = $this->render(
			$screen,
			array(
				'action'   => 'reassign',
				'category' => $parent,
			)
		);
		$this->assertStringContainsString( '<label for="easyq-category-reassign">Move questions in “Parent” without a subcategory to</label>', $html );
		$this->assertStringContainsString( '>— Child</option>', $html );
		$this->assertStringNotContainsString( '>Parent</option>', $html );

		$this->assertSame(
			Categories_Screen::url(
				array(
					'action'   => 'edit',
					'category' => $parent,
					'message'  => 'moved',
				)
			),
			$screen->handle(
				array(
					'easyq_action' => 'reassign',
					'category'     => (string) $parent,
					'reassign'     => (string) $child,
				)
			)
		);
		$this->assertSame( $child, $this->post( $direct->id )->category_id );
		$this->assertSame(
			Categories_Screen::url( array( 'message' => 'moved' ) ),
			$screen->handle(
				array(
					'easyq_action' => 'reassign',
					'category'     => '0',
					'reassign'     => (string) $child,
				)
			)
		);
		$this->assertSame( $child, $this->post( $loose->id )->category_id );

		$html = $this->render( $screen );
		$this->assertStringNotContainsString( 'have no category', $html );
		$this->assertStringNotContainsString( 'has no category', $html );

		// No warnings once questions may go without a category or subcategory.
		$this->ask( $this->user(), 'Another question without one', 'x' );
		Options::update(
			'posting',
			array(
				'allow_no_category'     => true,
				'allow_no_sub_category' => true,
			)
		);
		$this->assertStringNotContainsString( 'notice-warning', $this->render( $screen ) );
	}

	public function test_only_users_who_manage_easyq_may_submit(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->expectException( \WPDieException::class );
		( new Categories_Screen() )->handle(
			array(
				'easyq_action' => 'add',
				'title'        => 'Sneaky',
			)
		);
	}
}
