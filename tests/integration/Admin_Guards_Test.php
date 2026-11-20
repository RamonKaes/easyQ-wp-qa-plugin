<?php
/**
 * Tests for the lines of the admin screens that keep data from going.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Admin\Categories_Screen;
use EasyQ\Admin\Email_Templates_Screen;
use EasyQ\Admin\Posts_Screen;
use EasyQ\Admin\Tools_Screen;
use EasyQ\Admin\User_Fields_Screen;
use EasyQ\Services;
use EasyQ\Settings\Options;
use EasyQ\Users\Permits;
use WPDieException;

/**
 * @covers \EasyQ\Admin\Categories_Screen
 * @covers \EasyQ\Admin\Email_Templates_Screen
 * @covers \EasyQ\Admin\Tools_Screen
 * @covers \EasyQ\Admin\User_Fields_Screen
 * @covers \EasyQ\Admin\Posts_Screen
 */
final class Admin_Guards_Test extends Test_Case {

	public function set_up(): void {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/template.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		wp_set_current_user( $this->administrator() );
	}

	public function tear_down(): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		parent::tear_down();
	}

	/**
	 * Provides each screen with a form, and the field that marks a submission.
	 *
	 * @return array<string, array{class-string, string, array<string, string>}>
	 */
	public static function provide_forms(): array {
		return array(
			'categories'      => array( Categories_Screen::class, 'easyq_action', array( 'title' => 'Added without a nonce' ) ),
			'email templates' => array( Email_Templates_Screen::class, 'easyq_action', array() ),
			'tools'           => array( Tools_Screen::class, 'easyq_tool', array() ),
			'user fields'     => array( User_Fields_Screen::class, 'easyq_action', array( 'label' => 'Added without a nonce' ) ),
		);
	}

	/**
	 * @dataProvider provide_forms
	 *
	 * @param class-string          $screen Screen.
	 * @param string                $field  Field of a submission.
	 * @param array<string, string> $values Other values.
	 */
	public function test_a_form_without_its_nonce_is_refused( string $screen, string $field, array $values ): void {
		$categories = count( Services::categories()->all() );
		$fields     = count( Services::user_fields()->all() );
		$_POST      = array( $field => 'add' ) + $values; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The test sends a form without its nonce.
		$_REQUEST   = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- As above.

		try {
			( new $screen() )->load();
			$this->fail( 'The submission went through.' );
		} catch ( WPDieException $e ) {
			unset( $e );
		}

		$this->assertSame( $categories, count( Services::categories()->all() ) );
		$this->assertSame( $fields, count( Services::user_fields()->all() ) );
	}

	/**
	 * Returns the error codes a screen keeps for its form.
	 *
	 * @param object $screen Screen.
	 * @return string[]
	 */
	private static function errors( object $screen ): array {
		$errors = ( new \ReflectionProperty( $screen, 'errors' ) )->getValue( $screen );

		return $errors instanceof \WP_Error ? array_map( 'strval', $errors->get_error_codes() ) : array();
	}

	public function test_a_failed_save_of_a_category_does_not_go_on_to_delete_it(): void {
		$id = Services::categories()->create( null, 'Kept' );
		$this->assertIsInt( $id );
		$screen = new Categories_Screen();

		// The form of the deletion would send `confirm`; the save form does not, but the switch must stop anyway.
		$this->assertNull(
			$screen->handle(
				array(
					'easyq_action' => 'save',
					'category'     => (string) $id,
					'title'        => '',
					'confirm'      => '1',
				)
			)
		);

		$this->assertNotNull( Services::categories()->find( $id ) );
		$this->assertContains( 'title', self::errors( $screen ) );
	}

	public function test_a_failed_save_of_a_user_field_does_not_go_on_to_delete_it(): void {
		$id = Services::user_fields()->create( 'kept', 'Kept', 0, null );
		$this->assertIsInt( $id );
		$screen = new User_Fields_Screen();

		$this->assertNull(
			$screen->handle(
				array(
					'easyq_action' => 'save',
					'field'        => (string) $id,
					'label'        => str_repeat( 'x', 300 ),
					'confirm'      => '1',
				)
			)
		);

		$this->assertNotNull( Services::user_fields()->find( $id ) );
		$this->assertNotContains( 'confirm', self::errors( $screen ) );
		$this->assertNotSame( array(), self::errors( $screen ) );
	}

	public function test_the_ip_panel_shows_only_to_those_who_may_see_addresses(): void {
		// The sanitizer of the setting holds the choices of the screen; the test sets the value as stored.
		remove_all_filters( 'sanitize_option_' . Options::option_name( 'permissions' ) );
		Options::update( 'permissions', array( 'permit_anon_view_ips' => Permits::ADMINS ) );
		$_GET   = array( 'ip' => '192.0.2.44' );
		$render = static function (): string {
			set_current_screen( 'easyq_page_' . Posts_Screen::PAGE );
			ob_start();
			( new Posts_Screen() )->render();

			return (string) ob_get_clean();
		};

		$this->assertStringContainsString( '<h2>IP address 192.0.2.44</h2>', $render() );

		// A moderator who may not see addresses gets the list, not the panel with its block rules.
		wp_set_current_user( (int) $this->user( 'editor' )->user_id );
		$this->assertTrue( current_user_can( 'easyq_moderate' ) );
		$this->assertFalse( current_user_can( 'easyq_view_ips' ) );
		$html = $render();
		$this->assertStringNotContainsString( 'IP address 192.0.2.44', $html );
		$this->assertStringNotContainsString( 'Block this IP address', $html );
	}
}
