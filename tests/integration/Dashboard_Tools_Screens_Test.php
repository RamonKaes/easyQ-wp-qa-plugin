<?php
/**
 * Tests for the dashboard, the dashboard widget, the tools screen and the tools endpoint.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Actor;
use EasyQ\Admin\Dashboard_Screen;
use EasyQ\Admin\Tools_Screen;
use EasyQ\Capabilities;
use EasyQ\Posts\Post;
use EasyQ\Services;
use WP_REST_Request;

/**
 * @covers \EasyQ\Admin\Dashboard_Screen
 * @covers \EasyQ\Admin\Tools_Screen
 * @covers \EasyQ\REST\Tools_Controller
 * @covers \EasyQ\Stats\Site_Stats
 */
final class Dashboard_Tools_Screens_Test extends Test_Case {

	public function set_up(): void {
		parent::set_up();

		require_once ABSPATH . 'wp-admin/includes/template.php';
		require_once ABSPATH . 'wp-admin/includes/dashboard.php';
		wp_set_current_user( $this->administrator() );

		global $wp_rest_server;
		$wp_rest_server = new \Spy_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	public function tear_down(): void {
		global $wp_rest_server, $title;
		$wp_rest_server = null;
		$title          = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Admin screen context.
		$_GET           = array();

		parent::tear_down();
	}

	/**
	 * Renders output of a callback.
	 *
	 * @param callable $render Renders.
	 * @param string   $name   Title of the screen, set by the admin header.
	 * @return string
	 */
	private function render( callable $render, string $name = 'Q&A Dashboard' ): string {
		global $title;
		$title = $name; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Admin screen context.

		ob_start();
		$render();

		return (string) ob_get_clean();
	}

	public function test_dashboard_shows_the_figures_and_the_queues(): void {
		$this->ask( $this->user(), 'A question by a user' );
		$this->ask( new Actor( null, null, '192.0.2.44' ), 'A question by a visitor' );
		$this->ask( $this->user(), 'A question awaiting approval', 'x', array(), null, true );

		$html = $this->render( array( new Dashboard_Screen(), 'render' ) );

		$this->assertStringContainsString( '<tr><th scope="row">Questions</th><td>2</td></tr>', $html );
		$this->assertStringContainsString( '<tr><th scope="row">Questions by visitors without account</th><td>1</td></tr>', $html );
		$this->assertStringContainsString( '<tr><th scope="row">Users who posted</th><td>2</td></tr>', $html );
		$this->assertStringContainsString( '>1 post awaiting approval</a>', $html );
		$this->assertStringContainsString( '<tr><th scope="row">easyQ version</th><td>' . \EasyQ\VERSION . '</td></tr>', $html );
		$this->assertMatchesRegularExpression( '/<th scope="row">Size of the Q&amp;A tables<\/th><td>\d/', $html );
		$this->assertStringContainsString( 'site-health.php?tab=debug', $html );
	}

	public function test_menu_leads_moderators_past_the_dashboard(): void {
		global $menu, $submenu;

		( new Dashboard_Screen() )->add_menu();
		$top = array_column( $menu ?? array(), 1, 2 );
		$this->assertSame( Capabilities::MODERATE, $top[ Dashboard_Screen::PAGE ] ?? null );
		$this->assertSame( Capabilities::MANAGE, array_column( $submenu[ Dashboard_Screen::PAGE ] ?? array(), 1, 2 )[ Dashboard_Screen::PAGE ] ?? null );
	}

	public function test_widget_is_for_moderators_only(): void {
		global $wp_meta_boxes;

		set_current_screen( 'dashboard' );
		( new Dashboard_Screen() )->add_widget();
		$this->assertArrayHasKey( 'easyq_dashboard', $wp_meta_boxes['dashboard']['normal']['core'] ?? array() );
		$this->ask( $this->user() );
		$this->assertStringContainsString( '<li>1 question</li>', $this->render( array( new Dashboard_Screen(), 'render_widget' ) ) );

		$wp_meta_boxes = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Dashboard context.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		( new Dashboard_Screen() )->add_widget();
		$this->assertArrayNotHasKey( 'easyq_dashboard', $wp_meta_boxes['dashboard']['normal']['core'] ?? array() );
	}

	public function test_tools_screen_runs_one_batch_per_click_without_javascript(): void {
		for ( $i = 0; $i < 3; $i++ ) {
			$this->ask( $this->user(), 'Question number ' . $i . ' to index' );
		}
		$screen = new Tools_Screen();

		$this->assertNull(
			$screen->handle(
				array(
					'easyq_tool' => 'reindex',
					'step'       => '',
					'after'      => '0',
				)
			)
		);
		$html = $this->render( array( $screen, 'render' ), 'Q&A Tools' );
		$this->assertStringContainsString( 'Step 1 of 1: 3 of 3 done.', $html );
		$this->assertMatchesRegularExpression( '/data-easyq-tool="reindex".*?name="step" value="questions".*?>Continue<\/button>/s', $html );

		preg_match( '/data-easyq-tool="reindex".*?name="after" value="(\d+)"/s', $html, $matches );
		$this->assertSame(
			add_query_arg(
				array(
					'page' => Tools_Screen::PAGE,
					'done' => 'reindex',
				),
				admin_url( 'admin.php' )
			),
			$screen->handle(
				array(
					'easyq_tool' => 'reindex',
					'step'       => 'questions',
					'after'      => $matches[1] ?? '0',
				)
			)
		);
	}

	public function test_deleting_hidden_posts_needs_a_confirmation(): void {
		$question = $this->ask( $this->user() );
		Services::post_service()->set_question_status( Actor::system(), $question, Post::STATUS_HIDDEN );
		$screen = new Tools_Screen();

		$this->assertNull( $screen->handle( array( 'easyq_tool' => 'delete_hidden' ) ) );
		$this->assertNotNull( Services::posts()->find( $question->id ) );
		$this->assertStringContainsString( 'Please confirm first.', $this->render( array( $screen, 'render' ), 'Q&A Tools' ) );

		$state = array(
			'easyq_tool' => 'delete_hidden',
			'confirm'    => '1',
		);
		// Without JavaScript, each click runs one batch until the screen redirects.
		$calls = 0;
		do {
			$redirect = $screen->handle( $state );
			$html     = $this->render( array( $screen, 'render' ), 'Q&A Tools' );
			preg_match( '/data-easyq-tool="delete_hidden".*?name="step" value="([a-z]*)".*?name="after" value="(\d+)"/s', $html, $matches );
			$state['step']  = $matches[1] ?? '';
			$state['after'] = $matches[2] ?? '0';
			++$calls;
		} while ( null === $redirect && $calls < 10 );
		$this->assertNotNull( $redirect );
		$this->assertNull( Services::posts()->find( $question->id ) );
	}

	public function test_tools_endpoint_runs_batches_for_users_who_manage_easyq(): void {
		$request = new WP_REST_Request( 'POST', '/easyq/v1/tools/recount_posts' );

		// Without posts, the first step has nothing to do, so the response points to the second.
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'answers', $response->get_data()['step'] ?? null );
		$this->assertSame( 2, $response->get_data()['step_number'] ?? null );
		$this->assertSame( 3, $response->get_data()['steps'] ?? null );

		$request->set_param( 'step', 'no_such_step' );
		$this->assertSame( 400, rest_get_server()->dispatch( $request )->get_status() );
		$this->assertSame( 404, rest_get_server()->dispatch( new WP_REST_Request( 'POST', '/easyq/v1/tools/no-such-tool' ) )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 403, rest_get_server()->dispatch( new WP_REST_Request( 'POST', '/easyq/v1/tools/recount_posts' ) )->get_status() );
	}

	public function test_only_users_who_manage_easyq_run_tools_on_the_screen(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->expectException( \WPDieException::class );
		( new Tools_Screen() )->handle( array( 'easyq_tool' => 'reindex' ) );
	}
}
