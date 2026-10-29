<?php
/**
 * Tests for the settings fields, their registration and the settings screen.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Admin\Dashboard_Screen;
use EasyQ\Admin\Settings_Layout;
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

	public function test_only_the_site_owner_changes_role_levels_and_data_deletion(): void {
		Options::update( 'general', array( 'role_levels' => array( 'editor' => Levels::ADMIN ) ) );
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );
		$this->assertTrue( current_user_can( Capabilities::MANAGE ) );
		$this->assertFalse( current_user_can( 'manage_options' ) );

		update_option(
			Options::option_name( 'general' ),
			array(
				'role_levels'              => array(
					'editor'     => Levels::ADMIN,
					'subscriber' => Levels::ADMIN,
				),
				'delete_data_on_uninstall' => true,
				'read_only'                => true,
			)
		);
		$this->assertSame( array( 'editor' => Levels::ADMIN ), Options::get( 'general', 'role_levels' ) );
		$this->assertFalse( Options::get_bool( 'general', 'delete_data_on_uninstall' ) );
		$this->assertTrue( Options::get_bool( 'general', 'read_only' ) );

		global $title, $wp_settings_fields;
		$title = 'Q&A Settings'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Admin screen context.
		// Earlier tests registered the fields for an administrator; a request registers them once for its user.
		unset( $wp_settings_fields[ Settings_Screen::page( 'general' ) ] );
		$screen = new Settings_Screen();
		$screen->add_fields();
		ob_start();
		$screen->render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'easyq_settings_general[read_only]', $html );
		$this->assertStringNotContainsString( 'easyq_settings_general[role_levels]', $html );
		$this->assertStringNotContainsString( 'easyq_settings_general[delete_data_on_uninstall]', $html );

		wp_set_current_user( $this->administrator() );
		Options::update( 'general', array( 'delete_data_on_uninstall' => true ) );
		$this->assertTrue( Options::get_bool( 'general', 'delete_data_on_uninstall' ) );
	}

	public function test_menu_is_shown_to_users_who_manage_easyq(): void {
		global $submenu;

		wp_set_current_user( $this->administrator() );
		( new Settings_Screen() )->add_menu();

		// Capability of each entry, keyed by page; other screens may have added entries before.
		$entries = array_column( $submenu[ Dashboard_Screen::PAGE ] ?? array(), 1, 2 );
		$this->assertSame( Capabilities::MANAGE, $entries[ Settings_Screen::PAGE ] ?? null );
	}

	public function test_points_and_user_titles_are_a_settings_section(): void {
		wp_set_current_user( $this->administrator() );

		$data = $this->settings_request( 'GET' )->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( 100, $data['easyq_settings_points']['points_base'] );
		$this->assertSame( array(), $data['easyq_settings_points']['user_titles'] );

		$response = $this->settings_request(
			'POST',
			array(
				'easyq_settings_points' => array(
					'user_titles' => array(
						'100'  => 'Newcomer',
						'1000' => 'Regular',
					),
				),
			)
		);
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				1000 => 'Regular',
				100  => 'Newcomer',
			),
			Options::get( 'points', 'user_titles' )
		);
		$this->assertSame( 'Regular', \EasyQ\Users\User_Titles::thresholds()[1000] ?? null );

		$this->assertSame( 400, $this->settings_request( 'POST', array( 'easyq_settings_points' => array( 'user_titles' => array( 'many' => 'Invalid' ) ) ) )->get_status() );
		$this->assertSame( 400, $this->settings_request( 'POST', array( 'easyq_settings_points' => array( 'points_multiple' => 0 ) ) )->get_status() );
		$this->assertSame( Capabilities::MANAGE, apply_filters( 'option_page_capability_easyq_points', 'manage_options' ) );
	}

	public function test_user_titles_from_the_form_keep_one_title_per_points(): void {
		Options::update(
			'points',
			array(
				'user_titles' => array(
					array(
						'points' => '50',
						'title'  => '<b>Helper</b>',
					),
					array(
						'points' => '500',
						'title'  => 'Expert helper',
					),
					array(
						'points' => '50',
						'title'  => 'Second for 50',
					),
					array(
						'points' => '700',
						'title'  => '',
					),
					array(
						'points' => '-5',
						'title'  => 'Negative',
					),
					array(
						'points' => '',
						'title'  => '',
					),
				),
			)
		);

		$this->assertSame(
			array(
				500 => 'Expert helper',
				50  => 'Helper',
			),
			Options::get( 'points', 'user_titles' )
		);
		$this->assertContains( 'easyq-title-points', array_column( get_settings_errors( 'easyq_points' ), 'code' ) );
	}

	public function test_layout_shows_every_field_once(): void {
		foreach ( array_keys( Fields::tabs() ) as $tab ) {
			$shown = array();
			foreach ( Settings_Layout::sections( $tab ) as $section ) {
				foreach ( $section['rows'] as $row ) {
					$shown = array_merge( $shown, is_string( $row ) ? array( $row ) : $row['keys'] );
				}
			}

			// Switches share a row with a short title, so that their text stands beside them.
			foreach ( Settings_Layout::sections( $tab ) as $section ) {
				foreach ( $section['rows'] as $row ) {
					if ( is_string( $row ) ) {
						$this->assertNotSame( 'bool', Fields::section( $tab )[ $row ]['type'] ?? null, "$tab.$row" );
					}
				}
			}

			// Points minimums are shown with their permission.
			$expected = array_keys( array_filter( Fields::section( $tab ), static fn ( array $field ): bool => ! isset( $field['parent'] ) ) );
			sort( $expected );
			$this->assertSame( count( $shown ), count( array_unique( $shown ) ), $tab );
			sort( $shown );
			$this->assertSame( $expected, $shown, $tab );
		}
	}

	public function test_tabs_have_sections_and_rows_like_core_screens(): void {
		$html = $this->render( 'posting' );
		$this->assertMatchesRegularExpression( '/<h2[^>]*>Questions, answers and comments<\/h2>/', $html );
		$this->assertStringContainsString( '<th scope="row">Closing questions</th><td><fieldset><legend class="screen-reader-text"><span>Closing questions</span></legend>', $html );
		$this->assertStringContainsString( '<label for="easyq-posting-allow-close-questions"><input type="hidden" name="easyq_settings_posting[allow_close_questions]" value="0"><input type="checkbox" id="easyq-posting-allow-close-questions"', $html );
		$this->assertStringContainsString( '> Allow questions to be closed</label>', $html );
		$this->assertStringContainsString( 'name="easyq_settings_posting[min_len_q_title]" value="12" min="1"> <label for="easyq-posting-min-len-q-title">Minimum length of question titles, in characters</label>', $html );

		// A description shared by the settings of a row is shown once, after the controls.
		$html = $this->render( 'lists' );
		$row  = (string) strstr( (string) strstr( $html, '<span>Weights</span>' ), '</fieldset>', true );
		$this->assertSame( 1, substr_count( $row, '<p class="description"' ) );
		$this->assertSame( 5, substr_count( $row, 'aria-describedby="easyq-lists-hot-weight-q-age-description"' ) );
		$this->assertStringContainsString( '> <label for="easyq-lists-hot-weight-views">Many views</label><p class="description"', $row );

		// What all rows of a section share is said once below its heading.
		$html = $this->render( 'spam' );
		$this->assertMatchesRegularExpression( '/>Rate limits<\/h2>\s*<p>How often each IP address or user may do something, per hour; 0 blocks it.<\/p>/', $html );
		$row = (string) strstr( (string) strstr( $html, '<span>Questions</span>' ), '</fieldset>', true );
		$this->assertStringNotContainsString( '<p class="description"', $row );
		$this->assertStringContainsString( '> <label for="easyq-spam-max-rate-user-qs">per user</label>', $row );

		// Each role has a row of its own.
		$html = $this->render( 'general' );
		$this->assertStringContainsString( '<th scope="row"><label for="easyq-general-role-levels-author">Author</label></th><td><select id="easyq-general-role-levels-author" name="easyq_settings_general[role_levels][author]" class="regular-text">', $html );
		$this->assertStringContainsString( '<th scope="row">Maintenance</th>', $html );
	}

	public function test_permissions_line_up_and_keep_their_points_in_place(): void {
		$html = $this->render( 'permissions' );

		$this->assertStringContainsString( '<select id="easyq-permissions-permit-post-q" name="easyq_settings_permissions[permit_post_q]" class="regular-text" data-easyq-points="easyq-permissions-permit-post-q-points">', $html );
		$this->assertMatchesRegularExpression( '/<option value="' . Permits::POINTS . '" data-points>/', $html );
		$this->assertStringNotContainsString( '<option value="' . Permits::USERS . '" data-points', $html );
		// The minimum is always there and enabled; only the script disables it, as core does under Settings → Reading.
		$this->assertStringContainsString( '</select> <label for="easyq-permissions-permit-post-q-points">Points needed</label> <input type="number" class="small-text" id="easyq-permissions-permit-post-q-points" name="easyq_settings_permissions[permit_post_q_points]" value="0" min="0">', $html );
		$this->assertStringNotContainsString( 'hidden', (string) strstr( (string) strstr( $html, 'id="easyq-permissions-permit-post-q"' ), '</td>', true ) );
		// A permission without points has no minimum to show.
		$this->assertStringContainsString( '<select id="easyq-permissions-permit-view-q-page" name="easyq_settings_permissions[permit_view_q_page]" class="regular-text">', $html );
	}
}
