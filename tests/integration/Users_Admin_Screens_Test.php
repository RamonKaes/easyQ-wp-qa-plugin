<?php
/**
 * Tests for the points, user titles and user fields screens.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Admin\Dashboard_Screen;
use EasyQ\Admin\Points_Screen;
use EasyQ\Admin\User_Fields_Screen;
use EasyQ\Services;
use EasyQ\Settings\Options;
use EasyQ\Users\Permits;
use EasyQ\Users\User_Field;

/**
 * @covers \EasyQ\Admin\Points_Screen
 * @covers \EasyQ\Admin\User_Fields_Screen
 * @covers \EasyQ\Admin\User_Fields_List_Table
 */
final class Users_Admin_Screens_Test extends Test_Case {

	public function set_up(): void {
		parent::set_up();

		require_once ABSPATH . 'wp-admin/includes/template.php';
		wp_set_current_user( $this->administrator() );
	}

	public function tear_down(): void {
		global $title;
		$title = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Admin screen context.
		$_GET  = array();

		parent::tear_down();
	}

	/**
	 * Renders a screen.
	 *
	 * @param callable             $render Renders the screen.
	 * @param array<string, mixed> $query  Query arguments.
	 * @param string               $name   Title of the screen, set by the admin header.
	 * @return string
	 */
	private function render( callable $render, array $query = array(), string $name = 'Points' ): string {
		global $title;
		$title = $name; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Admin screen context.
		$_GET  = $query;

		ob_start();
		$render();

		return (string) ob_get_clean();
	}

	public function test_points_screen_groups_the_settings_and_shows_defaults(): void {
		Options::update( 'points', array( 'points_post_q' => 7 ) );
		$screen = new Points_Screen();

		$_GET = array();
		$screen->add_fields();
		$html = $this->render( array( $screen, 'render' ) );
		$this->assertStringContainsString( "name='option_page' value='easyq_points'", $html );
		$this->assertStringContainsString( '>Questions</h2>', $html );
		$this->assertStringContainsString( '>All users</h2>', $html );
		$this->assertStringContainsString( 'id="easyq-points-points-post-q" name="easyq_settings_points[points_post_q]" value="7"', $html );
		$this->assertStringContainsString( '>Show Default Values</a>', $html );
		$this->assertStringNotContainsString( 'user_titles', $html );

		$query = array(
			'page'     => Points_Screen::PAGE,
			'defaults' => '1',
		);
		$_GET  = $query;
		$screen->add_fields();
		$html = $this->render( array( $screen, 'render' ), $query );
		$this->assertStringContainsString( 'The default values are shown.', $html );
		$this->assertStringContainsString( 'name="easyq_settings_points[points_post_q]" value="2"', $html );
		$this->assertSame( 7, Options::get_int( 'points', 'points_post_q' ) );
	}

	public function test_saving_points_reports_the_recalculation(): void {
		$screen = new Points_Screen();

		// Other tests may have left messages of their own.
		$codes = static fn (): array => array_column( get_settings_errors( 'easyq_points' ), 'code' );

		$screen->report_recalculation( array( 'points_post_q' => 2 ), array( 'points_post_q' => 2 ) );
		$this->assertNotContains( 'easyq-recalculated', $codes() );

		$screen->report_recalculation( array( 'points_post_q' => 2 ), array( 'points_post_q' => 3 ) );
		$this->assertContains( 'easyq-recalculated', $codes() );
	}

	public function test_user_titles_screen_lists_titles_with_rows_for_new_ones(): void {
		Options::update(
			'points',
			array(
				'user_titles' => array(
					100  => 'Newcomer',
					1000 => 'Regular',
				),
			)
		);

		$html = $this->render( array( new Points_Screen(), 'render_titles' ), array(), 'User Titles' );

		$this->assertStringContainsString( '<label class="screen-reader-text" for="easyq-title-points-1">Points needed for title 1</label>', $html );
		$this->assertStringContainsString( 'name="easyq_settings_points[user_titles][1][points]" value="1000"', $html );
		$this->assertStringContainsString( 'name="easyq_settings_points[user_titles][2][title]" value="Newcomer"', $html );
		$this->assertStringContainsString( 'name="easyq_settings_points[user_titles][5][title]" value=""', $html );
		$this->assertStringNotContainsString( '[user_titles][6]', $html );
	}

	public function test_menu_has_the_three_screens(): void {
		global $submenu;

		( new Points_Screen() )->add_menu();
		( new User_Fields_Screen() )->add_menu();
		$entries = array_column( $submenu[ Dashboard_Screen::PAGE ] ?? array(), 1, 2 );

		foreach ( array( Points_Screen::PAGE, Points_Screen::TITLES_PAGE, User_Fields_Screen::PAGE ) as $page ) {
			$this->assertSame( 'easyq_manage', $entries[ $page ] ?? null, $page );
		}
	}

	public function test_adds_edits_and_deletes_user_fields(): void {
		$screen = new User_Fields_Screen();

		$this->assertSame(
			User_Fields_Screen::url( array( 'message' => 'added' ) ),
			$screen->handle(
				array(
					'easyq_action' => 'add',
					'label'        => 'Favourite Book',
					'type'         => 'link',
					'permit'       => (string) Permits::USERS,
					'on_register'  => '1',
				)
			)
		);
		$fields = Services::user_fields()->all();
		$field  = end( $fields );
		$this->assertInstanceOf( User_Field::class, $field );
		$this->assertSame( 'favourite-book', $field->name );
		$this->assertTrue( $field->is_link() );
		$this->assertTrue( $field->on_register() );
		$this->assertSame( Permits::USERS, $field->permit );

		$html = $this->render( array( $screen, 'render' ), array(), 'User Fields' );
		$this->assertStringContainsString( 'href="' . esc_url( User_Fields_Screen::edit_url( $field->id ) ) . '">Favourite Book</a>', $html );
		$this->assertStringContainsString( '<code>favourite-book</code>', $html );

		$this->assertSame(
			add_query_arg( 'message', 'saved', User_Fields_Screen::edit_url( $field->id ) ),
			$screen->handle(
				array(
					'easyq_action' => 'save',
					'field'        => (string) $field->id,
					'label'        => 'Book',
					'type'         => 'multi_line',
					'permit'       => (string) Permits::ALL,
					'position'     => '1',
				)
			)
		);
		$saved = Services::user_fields()->find( $field->id );
		$this->assertSame( 'Book', $saved?->label );
		$this->assertTrue( $saved->is_multi_line() );
		$this->assertFalse( $saved->on_register() );
		$this->assertNull( $saved->permit );
		$this->assertSame( 1, $saved->position );

		$html = $this->render(
			array( $screen, 'render' ),
			array(
				'action' => 'edit',
				'field'  => (string) $field->id,
			),
			'User Fields'
		);
		$this->assertStringContainsString( '<h1>Edit User Field</h1>', $html );
		$this->assertMatchesRegularExpression( '/<option value="2">After “location”<\/option>/', $html );

		$this->assertSame(
			User_Fields_Screen::url( array( 'message' => 'deleted' ) ),
			$screen->handle(
				array(
					'easyq_action' => 'delete',
					'field'        => (string) $field->id,
				)
			)
		);
		$this->assertNull( Services::user_fields()->find( $field->id ) );
	}

	public function test_shows_field_errors_with_the_input(): void {
		$screen = new User_Fields_Screen();

		$this->assertNull(
			$screen->handle(
				array(
					'easyq_action' => 'add',
					'label'        => 'Where I live',
					'name'         => 'location',
				)
			)
		);

		$html = $this->render( array( $screen, 'render' ), array(), 'User Fields' );
		$this->assertStringContainsString( 'value="Where I live"', $html );
		$this->assertStringContainsString( 'aria-invalid="true" aria-describedby="easyq-user-field-name-error easyq-user-field-name-description"', $html );
		$this->assertStringContainsString( 'Another field with this name already exists.', $html );
		$this->assertCount( 1, Services::user_fields()->all() );
	}

	public function test_only_users_who_manage_easyq_may_change_fields(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->expectException( \WPDieException::class );
		( new User_Fields_Screen() )->handle(
			array(
				'easyq_action' => 'add',
				'label'        => 'Sneaky',
			)
		);
	}
}
