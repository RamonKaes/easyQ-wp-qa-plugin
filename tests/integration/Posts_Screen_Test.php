<?php
/**
 * Tests for the moderation screen.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Actor;
use EasyQ\Admin\Dashboard_Screen;
use EasyQ\Admin\Posts_Screen;
use EasyQ\Posts\Post;
use EasyQ\Services;
use EasyQ\Settings\Options;
use WP_Admin_Bar;

/**
 * @covers \EasyQ\Admin\Posts_Screen
 * @covers \EasyQ\Admin\Posts_List_Table
 */
final class Posts_Screen_Test extends Test_Case {

	public function set_up(): void {
		parent::set_up();

		require_once ABSPATH . 'wp-admin/includes/template.php';
		require_once ABSPATH . 'wp-includes/class-wp-admin-bar.php';
		wp_set_current_user( $this->administrator() );

		global $wp_rest_server;
		$wp_rest_server = new \Spy_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		$_GET           = array();
		$_POST          = array();
		$_REQUEST       = array();

		parent::tear_down();
	}

	/**
	 * Sets the query of the request.
	 *
	 * @param array<string, mixed> $get  Query arguments.
	 * @param array<string, mixed> $post Form fields.
	 */
	private function request( array $get, array $post = array() ): void {
		$_GET     = $get;
		$_POST    = $post;
		$_REQUEST = $post + $get;
	}

	/**
	 * Renders the screen.
	 *
	 * @return string
	 */
	private function render(): string {
		ob_start();
		( new Posts_Screen() )->render();

		return (string) ob_get_clean();
	}

	public function test_row_actions_go_through_the_rest_api_with_a_nonce(): void {
		$queued = $this->ask( $this->user(), 'A question awaiting approval', 'x', array(), null, true );
		$this->request(
			array(
				'page'     => Posts_Screen::PAGE,
				'view'     => 'pending',
				'action'   => 'approve',
				'post'     => (string) $queued->id,
				'_wpnonce' => wp_create_nonce( 'easyq-post_' . $queued->id ),
			)
		);

		$redirect = ( new Posts_Screen() )->handle();

		$this->assertSame(
			add_query_arg(
				array(
					'did'    => 'approve',
					'done'   => 1,
					'failed' => 0,
				),
				Posts_Screen::url( array( 'view' => 'pending' ) )
			),
			$redirect
		);
		$this->assertTrue( $this->post( $queued->id )->is_visible() );
	}

	public function test_refuses_actions_without_a_valid_nonce(): void {
		$queued = $this->ask( $this->user(), 'A question awaiting approval', 'x', array(), null, true );
		$this->request(
			array(
				'action'   => 'approve',
				'post'     => (string) $queued->id,
				'_wpnonce' => wp_create_nonce( 'easyq-post_' . ( $queued->id + 1 ) ),
			)
		);

		try {
			( new Posts_Screen() )->handle();
			$this->fail( 'The action was carried out without a valid nonce.' );
		} catch ( \WPDieException $exception ) {
			$this->assertTrue( $this->post( $queued->id )->is_queued() );
		}
	}

	public function test_bulk_actions_count_what_could_not_be_done(): void {
		$visible = $this->ask( $this->user(), 'A visible question here' );
		$hidden  = $this->ask( $this->user(), 'An already hidden question' );
		Services::post_service()->set_question_status( Actor::current(), $hidden, Post::STATUS_HIDDEN );
		$this->request(
			array(
				'action'           => 'hide',
				'post'             => array( (string) $visible->id, (string) $hidden->id ),
				'_wpnonce'         => wp_create_nonce( 'bulk-easyq-posts' ),
				'_wp_http_referer' => '/wp-admin/admin.php?page=easyq-posts',
			)
		);

		$redirect = (string) ( new Posts_Screen() )->handle();

		$this->assertStringContainsString( 'did=hide&done=1&failed=1', $redirect );
		$this->assertTrue( $this->post( $visible->id )->is_hidden() );

		// Filters and searches without an action do nothing.
		$this->request( array( 'action' => '-1' ) );
		$this->assertNull( ( new Posts_Screen() )->handle() );
	}

	public function test_lists_posts_with_views_filters_and_actions(): void {
		$queued = $this->ask( $this->user(), 'A question awaiting approval', 'x', array(), null, true );
		$this->ask( new Actor( null, null, '192.0.2.44' ), 'A question by a visitor' );
		$category = Services::categories()->create( null, 'Software' );
		$this->assertIsInt( $category );
		$this->request( array( 'view' => 'pending' ) );

		$html = $this->render();
		$this->assertStringContainsString( '<h1 class="wp-heading-inline">Q&amp;A Posts</h1>', $html );
		$this->assertStringContainsString( 'class="current" aria-current="page">Pending <span class="count">(1)</span>', $html );
		$this->assertStringContainsString( 'All <span class="count">(2)</span>', $html );
		$this->assertStringContainsString( 'A question awaiting approval', $html );
		$this->assertStringNotContainsString( 'A question by a visitor', $html );
		$this->assertStringContainsString( esc_url( Posts_Screen::action_url( 'approve', $queued->id ) ), $html );
		$this->assertStringContainsString( '<option value="approve">Approve</option>', $html );
		$this->assertStringNotContainsString( '<option value="delete">', $html );
		$this->assertStringContainsString( '>Software</option>', $html );

		// The IP address of an anonymous post links to its posts and its panel.
		$this->request( array() );
		$this->assertStringContainsString( '>192.0.2.44</a>', $this->render() );

		$this->request( array( 'ip' => '192.0.2.44' ) );
		$html = $this->render();
		$this->assertStringContainsString( '<h2>IP address 192.0.2.44</h2>', $html );
		$this->assertStringContainsString( 'value="block">Block this IP address</button>', $html );
		$this->assertStringContainsString( 'A question by a visitor', $html );
		$this->assertStringNotContainsString( 'A question awaiting approval', $html );
	}

	public function test_blocks_ip_addresses_from_the_panel(): void {
		$this->request(
			array( 'ip' => '192.0.2.44' ),
			array(
				'easyq_ip_action' => 'block',
				'_wpnonce'        => wp_create_nonce( 'easyq-ip_192.0.2.44' ),
			)
		);

		$redirect = (string) ( new Posts_Screen() )->handle();

		$this->assertStringContainsString( 'did=block', $redirect );
		$this->assertSame( '192.0.2.44', Options::get_string( 'spam', 'block_ips_write' ) );
		$this->assertStringContainsString( 'value="unblock">Unblock this IP address</button>', $this->render() );
	}

	public function test_counts_posts_awaiting_approval_in_the_menu_and_admin_bar(): void {
		global $submenu;

		$this->ask( $this->user(), 'A question awaiting approval', 'x', array(), null, true );
		$this->ask( $this->user(), 'Another question awaiting it', 'x', array(), null, true );
		$screen = new Posts_Screen();

		$screen->add_menu();
		$labels = array_column( $submenu[ Dashboard_Screen::PAGE ] ?? array(), 0, 2 );
		$this->assertStringContainsString( 'Posts <span class="awaiting-mod count-2"><span class="pending-count" aria-hidden="true">2</span><span class="screen-reader-text">2 posts awaiting approval</span></span>', $labels[ Posts_Screen::PAGE ] ?? '' );

		$admin_bar = new WP_Admin_Bar();
		$screen->add_admin_bar_node( $admin_bar );
		$node = $admin_bar->get_node( 'easyq-pending' );
		$this->assertNotNull( $node );
		$this->assertStringContainsString( '2 Q&amp;A posts awaiting approval', (string) $node->title );

		// Users who do not moderate see neither.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$admin_bar = new WP_Admin_Bar();
		$screen->add_admin_bar_node( $admin_bar );
		$this->assertNull( $admin_bar->get_node( 'easyq-pending' ) );
	}

	public function test_row_headers_have_short_names(): void {
		$question = $this->ask( $this->user(), 'A question with a short name' );
		$answer   = $this->answer( $this->user(), $question, 'An answer that is longer than the eight words of its row header name.' );
		$table    = new \EasyQ\Admin\Posts_List_Table( Posts_Screen::filters() );
		$label    = new \ReflectionMethod( $table, 'get_primary_column_aria_label' );

		$this->assertSame( 'A question with a short name', $label->invoke( $table, $question ) );
		$this->assertSame( 'Answer: An answer that is longer than the eight&hellip;', $label->invoke( $table, $answer ) );

		// Core uses the name for the row header since WordPress 7.1.
		if ( method_exists( \WP_List_Table::class, 'get_primary_column_aria_label' ) ) {
			$this->assertStringContainsString( 'scope="row" aria-label="A question with a short name"', $this->render() );
		}
	}
}
