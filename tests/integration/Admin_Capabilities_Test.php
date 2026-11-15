<?php
/**
 * Tests that every admin screen of easyQ asks for the capability of its menu entry.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

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
}
