<?php
/**
 * Tests for user profile fields.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Services;
use EasyQ\Users\Permits;
use EasyQ\Users\User_Field;
use EasyQ\Users\User_Fields;
use WP_Error;

/**
 * @covers \EasyQ\Users\User_Fields
 * @covers \EasyQ\Users\User_Field
 */
final class UserFieldsTest extends TestCase {

	/**
	 * Service under test.
	 *
	 * @var User_Fields
	 */
	private User_Fields $fields;

	public function set_up(): void {
		parent::set_up();
		$this->fields = Services::user_fields();
	}

	/**
	 * Returns a field that must exist.
	 *
	 * @param int $id Field ID.
	 * @return User_Field
	 */
	private function field( int $id ): User_Field {
		$field = $this->fields->find( $id );
		$this->assertNotNull( $field );

		return $field;
	}

	public function test_installs_the_location_field(): void {
		$names = array_map( static fn ( User_Field $field ): string => $field->name, $this->fields->all() );

		$this->assertSame( array( 'location' ), $names );
	}

	public function test_creates_updates_and_orders_fields(): void {
		$id = $this->fields->create( 'twitter', 'Twitter', User_Field::FLAG_LINK | User_Field::FLAG_ON_REGISTER, Permits::USERS );
		$this->assertIsInt( $id );

		$field = $this->field( $id );
		$this->assertSame( 2, $field->position );
		$this->assertTrue( $field->is_link() );
		$this->assertTrue( $field->on_register() );
		$this->assertFalse( $field->is_multi_line() );
		$this->assertSame( Permits::USERS, $field->permit );

		$this->assertTrue( $this->fields->update( $id, '', User_Field::FLAG_MULTI_LINE, null ) );
		$this->assertSame( 'twitter', $this->field( $id )->display_label() );
		$this->assertTrue( $this->field( $id )->is_multi_line() );

		$this->fields->move( $id, 1 );
		$this->assertSame( array( 'twitter', 'location' ), array_map( static fn ( User_Field $field ): string => $field->name, $this->fields->all() ) );
	}

	public function test_rejects_invalid_or_duplicate_names(): void {
		$this->assertInstanceOf( WP_Error::class, $this->fields->create( 'location', '', 0, null ) );
		$this->assertInstanceOf( WP_Error::class, $this->fields->create( '!!!', '', 0, null ) );
		$this->assertInstanceOf( WP_Error::class, $this->fields->create( 'ok', str_repeat( 'x', 41 ), 0, null ) );
		$this->assertInstanceOf( WP_Error::class, $this->fields->update( 999, 'x', 0, null ) );
	}

	public function test_sanitizes_values_by_kind(): void {
		$user  = self::factory()->user->create();
		$link  = $this->field( (int) $this->fields->create( 'homepage', '', User_Field::FLAG_LINK, null ) );
		$about = $this->field( (int) $this->fields->create( 'about', '', User_Field::FLAG_MULTI_LINE, null ) );
		$plain = $this->field( (int) $this->fields->create( 'city', '', 0, null ) );

		$this->fields->set_value( $user, $link, 'javascript:alert(1)' );
		$this->fields->set_value( $user, $about, "Line one\n<script>x</script>Line two" );
		$this->fields->set_value( $user, $plain, "Berlin\n<b>Mitte</b>" );

		$this->assertSame( '', $this->fields->value( $user, $link ) );
		$this->assertSame( "Line one\nLine two", $this->fields->value( $user, $about ) );
		$this->assertSame( 'Berlin Mitte', $this->fields->value( $user, $plain ) );

		$this->fields->set_value( $user, $link, 'https://example.com/me' );
		$this->assertSame( 'https://example.com/me', $this->fields->value( $user, $link ) );
	}

	public function test_deleting_a_field_removes_its_values(): void {
		$user  = self::factory()->user->create();
		$field = $this->field( (int) $this->fields->create( 'temporary', '', 0, null ) );
		$this->fields->set_value( $user, $field, 'value' );

		$this->fields->delete( $field->id );

		$this->assertNull( $this->fields->find( $field->id ) );
		$this->assertSame( '', get_user_meta( $user, $field->meta_key(), true ) );
		$this->assertSame( 1, $this->fields->all()[0]->position );
	}
}
