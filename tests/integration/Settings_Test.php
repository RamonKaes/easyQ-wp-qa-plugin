<?php
/**
 * Tests for the settings fields, their registration and the settings screen.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Admin\Settings_Screen;
use EasyQ\Capabilities;
use EasyQ\Search\Indexed_Post;
use EasyQ\Search\Search_Provider;
use EasyQ\Services;
use EasyQ\Settings\Fields;
use EasyQ\Settings\Options;
use EasyQ\Users\Levels;
use EasyQ\Users\Permits;
use WP_REST_Request;
use WP_REST_Response;

/**
 * @covers \EasyQ\Settings\Fields
 * @covers \EasyQ\Settings\Registry
 * @covers \EasyQ\Admin\Settings_Screen
 */
final class Settings_Test extends Test_Case {

	public function set_up(): void {
		parent::set_up();

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/template.php';

		global $wp_rest_server;
		$wp_rest_server = new \Spy_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	public function tear_down(): void {
		global $wp_rest_server, $title;
		$wp_rest_server = null;
		$title          = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Admin screen context.
		unset( $_GET['tab'] );

		parent::tear_down();
	}

	/**
	 * Sends a request to the core settings endpoint.
	 *
	 * @param string               $method HTTP method.
	 * @param array<string, mixed> $params Parameters.
	 * @return WP_REST_Response
	 */
	private function settings_request( string $method, array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/wp/v2/settings' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Renders a tab of the settings screen.
	 *
	 * @param string $tab Tab.
	 * @return string
	 */
	private function render( string $tab ): string {
		global $title;
		// Set by the admin header from the menu entry.
		$title = 'Q&A Settings'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Admin screen context.
		wp_set_current_user( $this->administrator() );
		$_GET['tab'] = $tab;

		$screen = new Settings_Screen();
		$screen->add_fields();
		ob_start();
		$screen->render();

		return (string) ob_get_clean();
	}

	public function test_every_field_belongs_to_a_setting_and_accepts_its_default(): void {
		foreach ( array_keys( Fields::tabs() ) as $section ) {
			$defaults = Options::defaults()[ $section ];

			foreach ( Fields::section( $section ) as $key => $field ) {
				$this->assertArrayHasKey( $key, $defaults, "$section.$key" );
			}

			// The defaults are valid values, so saving them changes nothing.
			// Registering the setting leaves a missing option missing.
			delete_option( Options::option_name( $section ) );
			$this->assertFalse( get_option( Options::option_name( $section ) ), $section );
			$this->assertSame( $defaults, Fields::sanitize( $section, $defaults ), $section );
		}
	}

	public function test_sanitizes_numbers_switches_and_choices(): void {
		Options::update(
			'posting',
			array(
				'comment_on_qs'      => '1',
				'allow_self_answer'  => '0',
				'max_len_q_title'    => '5000',
				'min_len_a_content'  => '-3',
				'page_size_ask_tags' => 'many',
				'match_ask_check_qs' => '2',
				'block_bad_words'    => "<b>bad*</b>\nworse",
			)
		);
		Options::update(
			'general',
			array(
				'tags_or_categories' => 'x',
				'base_slug'          => 'Fragen und Antworten',
			)
		);

		$posting = Options::get_section( 'posting' );
		$this->assertTrue( $posting['comment_on_qs'] );
		$this->assertFalse( $posting['allow_self_answer'] );
		$this->assertSame( 800, $posting['max_len_q_title'] );
		$this->assertSame( 1, $posting['min_len_a_content'] );
		$this->assertSame( 5, $posting['page_size_ask_tags'] );
		$this->assertSame( 2, $posting['match_ask_check_qs'] );
		$this->assertSame( "bad*\nworse", $posting['block_bad_words'] );
		$this->assertSame( 'tc', Options::get_string( 'general', 'tags_or_categories' ) );
		$this->assertSame( 'fragen-und-antworten', Options::get_string( 'general', 'base_slug' ) );

		Options::update( 'general', array( 'base_slug' => '' ) );
		$this->assertSame( 'fragen-und-antworten', Options::get_string( 'general', 'base_slug' ) );
	}

	public function test_permissions_accept_only_the_thresholds_question2answer_offers(): void {
		Options::update(
			'permissions',
			array(
				'permit_post_q'        => Permits::POINTS,
				'permit_post_q_points' => '-10',
				'permit_close_q'       => Permits::ALL,
				'permit_view_q_page'   => Permits::POINTS,
				'permit_edit_silent'   => Permits::ADMINS,
			)
		);

		$this->assertSame( Permits::POINTS, Options::get_int( 'permissions', 'permit_post_q' ) );
		$this->assertSame( 0, Options::get_int( 'permissions', 'permit_post_q_points' ) );
		$this->assertSame( Permits::EDITORS, Options::get_int( 'permissions', 'permit_close_q' ) );
		$this->assertSame( Permits::ALL, Options::get_int( 'permissions', 'permit_view_q_page' ) );
		$this->assertSame( Permits::ADMINS, Options::get_int( 'permissions', 'permit_edit_silent' ) );
		$this->assertArrayNotHasKey( 'permit_view_q_page_points', Fields::section( 'permissions' ) );
	}

	public function test_role_levels_keep_only_known_roles_and_grantable_levels(): void {
		Options::update(
			'general',
			array(
				'role_levels' => array(
					'editor'      => (string) Levels::EXPERT,
					'author'      => Levels::BASIC,
					'contributor' => Levels::SUPER,
					'no_such'     => Levels::ADMIN,
				),
			)
		);

		$this->assertSame( array( 'editor' => Levels::EXPERT ), Options::get( 'general', 'role_levels' ) );
	}

	public function test_keeps_settings_that_are_not_passed_or_have_no_field(): void {
		Options::update( 'spam', array( 'ip_retention_days' => 7 ) );
		update_option( Options::option_name( 'spam' ), array( 'moderate_users' => true ) );

		$this->assertSame( 7, Options::get_int( 'spam', 'ip_retention_days' ) );
		$this->assertTrue( Options::get_bool( 'spam', 'moderate_users' ) );
		$this->assertTrue( Options::get_bool( 'spam', 'flagging_of_posts' ) );
	}

	public function test_search_provider_choice_appears_with_a_second_provider(): void {
		$this->assertArrayNotHasKey( 'search_provider', Fields::section( 'lists' ) );

		add_filter(
			'easyq_search_providers',
			static fn ( array $providers ): array => array_merge(
				$providers,
				array(
					new class() implements Search_Provider {
						public function id(): string {
							return 'other';
						}

						public function label(): string {
							return 'Other';
						}

						public function index_post( Indexed_Post $post ): void {}

						public function unindex_post( int $post_id ): void {}

						public function move_post( int $post_id, ?int $category_id ): void {}

						public function search( string $query, int $start, int $count, ?int $user_id ): array {
							return array();
						}
					},
				)
			)
		);
		Services::reset();

		$this->assertSame( array( 'basic', 'other' ), array_keys( Fields::section( 'lists' )['search_provider']['choices'] ?? array() ) );
	}

	public function test_rest_endpoint_reads_and_updates_sections(): void {
		wp_set_current_user( $this->administrator() );

		$data = $this->settings_request( 'GET' )->get_data();
		$this->assertIsArray( $data );
		$keys = array_keys( $data['easyq_settings_viewing'] );
		sort( $keys );
		$expected = array_keys( Fields::section( 'viewing' ) );
		sort( $expected );
		$this->assertSame( $expected, $keys );
		$this->assertArrayNotHasKey( 'ip_retention_days', $data['easyq_settings_spam'] );

		$response = $this->settings_request( 'POST', array( 'easyq_settings_viewing' => array( 'page_size_q_as' => 25 ) ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 25, Options::get_int( 'viewing', 'page_size_q_as' ) );
		$this->assertTrue( Options::get_bool( 'viewing', 'voting_on_qs' ) );

		$this->assertSame( 400, $this->settings_request( 'POST', array( 'easyq_settings_viewing' => array( 'page_size_q_as' => 500 ) ) )->get_status() );
		$this->assertSame( 400, $this->settings_request( 'POST', array( 'easyq_settings_spam' => array( 'ip_retention_days' => 1 ) ) )->get_status() );
		$this->assertSame( 25, Options::get_int( 'viewing', 'page_size_q_as' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 403, $this->settings_request( 'GET' )->get_status() );
	}

	public function test_users_who_manage_easyq_may_save_its_settings(): void {
		$this->assertSame( Capabilities::MANAGE, apply_filters( 'option_page_capability_easyq_posting', 'manage_options' ) );
		$this->assertSame( 'manage_options', apply_filters( 'option_page_capability_general', 'manage_options' ) );
	}

	public function test_screen_shows_tabs_and_the_fields_of_the_current_tab(): void {
		$html = $this->render( 'permissions' );

		$this->assertStringContainsString( '<h1>Q&amp;A Settings</h1>', $html );
		$this->assertStringContainsString( 'nav-tab nav-tab-active" aria-current="page">Permissions</a>', $html );
		$this->assertStringContainsString( "name='option_page' value='easyq_permissions'", $html );
		$this->assertStringContainsString( 'name="easyq_settings_permissions[permit_post_q]"', $html );
		$this->assertStringContainsString( 'name="easyq_settings_permissions[permit_post_q_points]"', $html );
		$this->assertStringNotContainsString( 'name="easyq_settings_permissions[permit_view_q_page_points]"', $html );
		$this->assertStringNotContainsString( 'easyq_settings_posting[', $html );
		// Only the thresholds offered for closing questions.
		$close = (string) strstr( explode( 'permit_close_q]', $html )[1] ?? '', '</select>', true );
		$this->assertStringContainsString( '<option value="' . Permits::EDITORS . '"', $close );
		$this->assertStringNotContainsString( '<option value="' . Permits::USERS . '"', $close );
	}

	public function test_screen_submits_unchecked_switches_and_falls_back_to_the_first_tab(): void {
		$html = $this->render( 'no-such-tab' );

		$this->assertStringContainsString( 'aria-current="page">General</a>', $html );
		$this->assertStringContainsString( '<input type="hidden" name="easyq_settings_general[read_only]" value="0"><input type="checkbox" id="easyq-general-read-only" name="easyq_settings_general[read_only]" value="1" aria-describedby="easyq-general-read-only-description">', $html );
		$this->assertStringContainsString( '<label for="easyq-general-role-levels-editor">Editor</label>', $html );
		$this->assertMatchesRegularExpression( '/<option value="' . Levels::MODERATOR . '"\s+selected=\'selected\'>Moderator<\/option>/', $html );
	}

	public function test_menu_is_shown_to_users_who_manage_easyq(): void {
		global $submenu;

		wp_set_current_user( $this->administrator() );
		( new Settings_Screen() )->add_menu();

		// Capability of each entry, keyed by page; other screens may have added entries before.
		$entries = array_column( $submenu[ Settings_Screen::PAGE ] ?? array(), 1, 2 );
		$this->assertSame( Capabilities::MANAGE, $entries[ Settings_Screen::PAGE ] ?? null );
	}
}
