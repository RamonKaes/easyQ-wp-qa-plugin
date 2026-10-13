<?php
/**
 * Tests for the registration fields.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Services;
use EasyQ\Users\Registration;
use EasyQ\Users\User_Field;

/**
 * @covers \EasyQ\Users\Registration
 */
final class RegistrationTest extends TestCase {

	public function tear_down(): void {
		unset( $_POST['easyq_fields'], $_POST['easyq_register'] );
		parent::tear_down();
	}

	public function test_shows_only_fields_marked_for_registration(): void {
		$field_id = Services::user_fields()->create( 'company', 'Company', User_Field::FLAG_ON_REGISTER, null );
		$this->assertIsInt( $field_id );

		ob_start();
		( new Registration() )->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'easyq_fields[' . $field_id . ']', $html );
		$this->assertStringContainsString( 'Company', $html );
		$this->assertStringNotContainsString( 'location', $html );
	}

	public function test_renders_nothing_without_registration_fields(): void {
		ob_start();
		( new Registration() )->render();

		$this->assertSame( '', ob_get_clean() );
	}

	public function test_stores_submitted_values_of_new_users(): void {
		$field_id = Services::user_fields()->create( 'company', '', User_Field::FLAG_ON_REGISTER, null );
		$this->assertIsInt( $field_id );
		$location = Services::user_fields()->all()[0];

		$_POST['easyq_register'] = wp_create_nonce( 'easyq_register' );
		$_POST['easyq_fields']   = array(
			$field_id     => 'ACME',
			$location->id => 'Not shown on registration',
		);

		$user_id = self::factory()->user->create();
		$field   = Services::user_fields()->find( $field_id );
		$this->assertNotNull( $field );

		$this->assertSame( 'ACME', Services::user_fields()->value( $user_id, $field ) );
		$this->assertSame( '', Services::user_fields()->value( $user_id, $location ) );
	}

	public function test_ignores_submissions_without_a_valid_nonce(): void {
		$field_id = Services::user_fields()->create( 'company', '', User_Field::FLAG_ON_REGISTER, null );
		$this->assertIsInt( $field_id );
		$_POST['easyq_register'] = 'invalid';
		$_POST['easyq_fields']   = array( $field_id => 'ACME' );

		$user_id = self::factory()->user->create();
		$field   = Services::user_fields()->find( $field_id );
		$this->assertNotNull( $field );

		$this->assertSame( '', Services::user_fields()->value( $user_id, $field ) );
	}
}
