<?php
/**
 * Tests that every admin screen of easyQ asks for the capability of its menu entry.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Services;
use ReflectionClass;
use WPDieException;

/**
 * @coversNothing
 */
final class Admin_Capabilities_Test extends Test_Case {

	/**
	 * The admin menu before the test, restored afterwards for the tests that follow.
	 *
	 * @var array<string, mixed>
	 */
	private array $saved = array();

	public function set_up(): void {
		parent::set_up();
		foreach ( array( 'submenu', 'menu', '_registered_pages' ) as $name ) {
			$this->saved[ $name ] = $GLOBALS[ $name ] ?? null;
		}
	}

	public function tear_down(): void {
		set_current_screen( 'front' );
		foreach ( $this->saved as $name => $value ) {
			$GLOBALS[ $name ] = $value; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restores the admin menu of the test before.
		}
		parent::tear_down();
	}

	/**
	 * Returns the screen classes of easyQ: the classes under `includes/admin/` with a `PAGE`
	 * constant and a `register()` method.
	 *
	 * @return array<string, class-string> Class by page slug.
	 */
	private static function screens(): array {
		$screens = array();
		foreach ( (array) glob( dirname( __DIR__, 2 ) . '/includes/admin/class-*.php' ) as $file ) {
			$name  = str_replace( ' ', '_', ucwords( str_replace( '-', ' ', substr( basename( (string) $file, '.php' ), strlen( 'class-' ) ) ) ) );
			$class = 'EasyQ\\Admin\\' . $name;
			if ( class_exists( $class ) && defined( $class . '::PAGE' ) && method_exists( $class, 'register' ) ) {
				$screens[ (string) constant( $class . '::PAGE' ) ] = $class;
			}
		}

		return $screens;
	}

	/**
	 * Registers the screens and returns the capability of each easyQ menu entry.
	 *
	 * @return array<string, string> Capability by page slug.
	 */
	private function menu(): array {
		global $submenu, $menu, $_registered_pages;

		wp_set_current_user( $this->administrator() );
		set_current_screen( 'dashboard' );
		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- A fresh admin menu for the test.
		$submenu           = array();
		$menu              = array();
		$_registered_pages = array();
		// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited
		foreach ( self::screens() as $class ) {
			( new $class() )->register();
		}
		do_action( 'admin_menu' );

		$capabilities = array();
		foreach ( (array) ( $submenu['easyq'] ?? array() ) as $entry ) {
			$capabilities[ (string) $entry[2] ] = (string) $entry[1];
		}

		return $capabilities;
	}

	public function test_every_menu_entry_asks_for_a_capability_of_easyq(): void {
		$capabilities = $this->menu();

		$this->assertGreaterThan( 7, count( $capabilities ) );
		foreach ( $capabilities as $page => $capability ) {
			$this->assertContains( $capability, array( 'easyq_manage', 'easyq_moderate' ), $page );
		}
	}

	public function test_the_menu_item_leads_moderators_to_a_screen_they_may_open(): void {
		global $submenu;
		$this->menu();
		$moderator = (int) $this->user( 'editor' )->user_id;
		$this->assertTrue( user_can( $moderator, 'easyq_moderate' ) );
		$this->assertFalse( user_can( $moderator, 'easyq_manage' ) );

		// Core links the menu item of a plugin to its first entry, whether the user may open it or not (wp-admin/menu-header.php).
		$first = array_values( (array) $submenu['easyq'] )[0];
		$this->assertTrue( user_can( $moderator, (string) $first[1] ), 'The first entry of the menu: ' . $first[2] );

		// The dashboard leaves out what only managers use.
		wp_set_current_user( $moderator );
		$GLOBALS['title'] = 'Q&A Dashboard'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- As wp-admin/admin.php sets it.
		ob_start();
		( new \EasyQ\Admin\Dashboard_Screen() )->render();
		$html = (string) ob_get_clean();
		unset( $GLOBALS['title'] );
		$this->assertStringContainsString( 'easyq-figures-posts', $html );
		$this->assertStringNotContainsString( 'easyq-figures-system', $html );
		$this->assertStringNotContainsString( 'page=easyq-settings', $html );
	}

	public function test_every_form_handler_refuses_users_without_the_capability_of_its_menu_entry(): void {
		$capabilities = $this->menu();
		$users        = array(
			// Moderates, but does not manage easyQ.
			'easyq_manage'   => (int) $this->user( 'editor' )->user_id,
			'easyq_moderate' => (int) $this->user()->user_id,
		);
		$this->assertTrue( user_can( $users['easyq_manage'], 'easyq_moderate' ) );
		$this->assertFalse( user_can( $users['easyq_manage'], 'easyq_manage' ) );

		$checked = array();
		foreach ( self::screens() as $page => $class ) {
			$reflection = new ReflectionClass( $class );
			if ( ! $reflection->hasMethod( 'handle' ) ) {
				continue;
			}
			// Core loads a screen only for its capability; a handler that checks one itself, for
			// the case it is reached another way, must check that one. One that relies on core and
			// on the REST API, such as the posts screen, checks none.
			$method = $reflection->getMethod( 'handle' );
			$source = implode( '', array_slice( (array) file( (string) $method->getFileName() ), $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1 ) );
			if ( ! str_contains( $source, 'current_user_can(' ) ) {
				continue;
			}
			$this->assertArrayHasKey( $page, $capabilities, "$class has no menu entry." );
			wp_set_current_user( $users[ $capabilities[ $page ] ] );

			try {
				0 === $method->getNumberOfParameters() ? ( new $class() )->handle() : ( new $class() )->handle( array() );
				$this->fail( "$class carried out a form for a user without " . $capabilities[ $page ] . '.' );
			} catch ( WPDieException $refused ) {
				$checked[] = $class;
			}
		}

		$this->assertGreaterThanOrEqual( 4, count( $checked ) );
	}

	public function test_every_list_table_lets_screen_options_choose_its_columns(): void {
		wp_set_current_user( $this->administrator() );
		$checked = array();
		foreach ( self::screens() as $page => $class ) {
			if ( ! ( new ReflectionClass( $class ) )->hasProperty( 'table' ) ) {
				continue;
			}
			set_current_screen( 'easyq_page_' . $page );
			$screen = get_current_screen();
			( new $class() )->load();

			// The columns Screen Options lists, from the filter core's list table adds for the screen.
			$columns = get_column_headers( $screen );
			$this->assertGreaterThan( 1, count( $columns ), $class );
			// Texts of its own, not the defaults of core ("Items list").
			foreach ( array(
				'heading_views'      => 'Filter items list',
				'heading_pagination' => 'Items list navigation',
				'heading_list'       => 'Items list',
			) as $key => $default ) {
				$this->assertNotSame( $default, (string) $screen?->get_screen_reader_text( $key ), "$class: $key" );
			}
			$checked[] = $class;
		}

		$this->assertCount( 4, $checked );
	}

	public function test_a_hidden_column_stays_hidden_and_the_list_address_loses_the_form_fields(): void {
		$admin = $this->administrator();
		wp_set_current_user( $admin );
		set_current_screen( 'easyq_page_' . \EasyQ\Admin\Posts_Screen::PAGE );
		update_user_option( $admin, 'manage' . get_current_screen()?->id . 'columnshidden', array( 'author' ) );
		$table = new \EasyQ\Admin\Posts_List_Table( \EasyQ\Admin\Posts_Screen::filters() );
		$table->prepare_items();

		$this->assertContains( 'author', $table->get_column_info()[1] );

		$_GET                   = array(
			'page'             => \EasyQ\Admin\Posts_Screen::PAGE,
			's'                => 'widget',
			'_wpnonce'         => 'abc',
			'_wp_http_referer' => '/wp-admin/admin.php?page=easyq-posts',
		);
		$uri                    = $_SERVER['REQUEST_URI'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Restored after the test, never used.
		$_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?' . http_build_query( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Builds the request of the test.
		$address                = ( new \EasyQ\Admin\Posts_Screen() )->without_form_fields();
		$_GET                   = array();
		$_SERVER['REQUEST_URI'] = $uri;

		$this->assertSame( '/wp-admin/admin.php?page=easyq-posts&s=widget', $address );
		$this->assertNull( ( new \EasyQ\Admin\Posts_Screen() )->without_form_fields() );
	}

	public function test_every_screen_has_help_and_cleans_its_results_from_the_address_as_core_does(): void {
		wp_set_current_user( $this->administrator() );
		$helped = array();
		foreach ( self::screens() as $page => $class ) {
			if ( ! method_exists( $class, 'load' ) ) {
				continue;
			}
			set_current_screen( 'easyq_page_' . $page );
			$screen = new $class();
			$screen->register();
			$screen->load();
			$this->assertNotNull( get_current_screen()?->get_help_tab( 'easyq-overview' ), $class );
			$helped[] = $class;
		}
		$this->assertGreaterThanOrEqual( 6, count( $helped ) );

		// Every entry of the easyQ menu, read from the menu, gives help when its screen loads.
		$pages = array_keys( $this->menu() );
		$this->assertGreaterThanOrEqual( 9, count( $pages ) );
		foreach ( $pages as $page ) {
			$hook = (string) get_plugin_page_hookname( $page, 'easyq' );
			set_current_screen( $hook );
			do_action( 'load-' . $hook ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- The hook of core's admin screens (wp-admin/admin.php).
			$this->assertNotNull( get_current_screen()?->get_help_tab( 'easyq-overview' ), $page );
			// With the sidebar core gives every screen with help tabs.
			$this->assertStringContainsString( 'For more information:', (string) get_current_screen()?->get_help_sidebar(), $page );
		}

		// The users list of core, to which easyQ adds its bulk actions.
		( new \EasyQ\Admin\Users_Screen() )->register();
		$removed = apply_filters( 'removable_query_args', array() );
		foreach ( array( 'easyq_did', 'easyq_done', 'easyq_failed', 'easyq_count' ) as $arg ) {
			$this->assertContains( $arg, $removed );
		}
	}

	public function test_the_admin_markup_brings_no_style_of_its_own(): void {
		$styled = array();
		foreach ( (array) glob( dirname( __DIR__, 2 ) . '/includes/admin/*.php' ) as $file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file in a test.
			if ( 1 === preg_match( '/\sstyle=[\'"\\\\]/', (string) file_get_contents( (string) $file ) ) ) {
				$styled[] = basename( (string) $file );
			}
		}

		$this->assertSame( array(), $styled );
	}

	public function test_no_rendered_admin_screen_brings_a_style_of_its_own(): void {
		// What the screens print, so that an attribute built from an argument of printf() is seen too.
		require_once ABSPATH . 'wp-admin/includes/template.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		$question = $this->ask( $this->user(), 'A question listed on the screens' );
		$this->answer( $this->user(), $question );
		$this->assertIsInt( Services::categories()->create( null, 'A category of the screens' ) );
		$styled   = array();
		$rendered = 0;
		$pagenow  = $GLOBALS['pagenow'] ?? null;

		foreach ( array_keys( $this->menu() ) as $page ) {
			$hook = (string) get_plugin_page_hookname( $page, 'easyq' );
			// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- As wp-admin/admin.php sets them for a plugin page.
			$GLOBALS['plugin_page'] = $page;
			$GLOBALS['pagenow']     = 'admin.php';
			$GLOBALS['title']       = null;
			// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited
			set_current_screen( $hook );
			do_action( 'load-' . $hook ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- The hook of core's admin screens (wp-admin/admin.php).
			ob_start();
			do_action( $hook ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- The page hook of core (wp-admin/admin.php).
			$html = (string) ob_get_clean();
			++$rendered;
			if ( 1 === preg_match( '/<[^>]+\sstyle\s*=/i', $html, $match ) ) {
				$styled[] = $page . ': ' . $match[0];
			}
		}

		unset( $GLOBALS['plugin_page'], $GLOBALS['title'] );
		$GLOBALS['pagenow'] = $pagenow; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restored as it was.

		$this->assertGreaterThanOrEqual( 9, $rendered );
		$this->assertSame( array(), $styled );
	}
}
