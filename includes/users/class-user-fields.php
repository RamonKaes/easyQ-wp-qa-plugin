<?php
/**
 * User profile fields.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Users;

use EasyQ\Database\DB;
use EasyQ\Database\Schema;
use EasyQ\Util\Text;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Manages the profile fields defined by the site owner and the values users enter.
 *
 * WordPress already has fields for the website and the biography, so the
 * Question2Answer fields "website" and "about" map to those; the only default
 * field is "location".
 *
 * @since 0.1.0
 */
final class User_Fields {

	/**
	 * Longest field value in characters.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const MAX_VALUE_LENGTH = 8000;

	/**
	 * Longest field name and label in characters.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const MAX_NAME_LENGTH = 40;

	/**
	 * Object cache key and group.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const CACHE_GROUP = 'easyq_userfields';

	/**
	 * Returns all fields in order.
	 *
	 * @since 0.1.0
	 *
	 * @return User_Field[]
	 */
	public function all(): array {
		$cached = wp_cache_get( 'all', self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return array_values( array_filter( $cached, static fn ( $field ): bool => $field instanceof User_Field ) );
		}

		$rows   = DB::get_results( DB::wpdb()->prepare( 'SELECT * FROM %i ORDER BY position, id', Schema::table( 'userfields' ) ) );
		$fields = array_map( array( User_Field::class, 'from_row' ), $rows );

		wp_cache_set( 'all', $fields, self::CACHE_GROUP );

		return $fields;
	}

	/**
	 * Returns a field.
	 *
	 * @since 0.1.0
	 *
	 * @param int $id Field ID.
	 * @return User_Field|null
	 */
	public function find( int $id ): ?User_Field {
		foreach ( $this->all() as $field ) {
			if ( $field->id === $id ) {
				return $field;
			}
		}

		return null;
	}

	/**
	 * Adds the default fields if no fields exist.
	 *
	 * @since 0.1.0
	 */
	public function install_defaults(): void {
		if ( array() === $this->all() ) {
			$this->create( 'location', '', 0, null );
		}
	}

	/**
	 * Creates a field at the end of the list.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $name   Internal name: lowercase letters, digits, hyphens and underscores.
	 * @param string   $label  Label; empty to use the name.
	 * @param int      $flags  Combination of the User_Field::FLAG_* constants.
	 * @param int|null $permit Who may see the field, or null for everybody.
	 * @return int|WP_Error The field ID, or the validation errors.
	 */
	public function create( string $name, string $label, int $flags, ?int $permit ): int|WP_Error {
		$name   = sanitize_key( $name );
		$errors = $this->validate( $name, $label, null );
		if ( $errors->has_errors() ) {
			return $errors;
		}

		$fields = $this->all();
		$id     = DB::insert(
			Schema::table( 'userfields' ),
			array(
				'name'     => $name,
				'label'    => '' === $label ? null : $label,
				'position' => count( $fields ) + 1,
				'flags'    => $flags,
				'permit'   => $permit,
			),
			array( '%s', '%s', '%d', '%d', '%d' )
		);

		$this->forget();

		return $id;
	}

	/**
	 * Changes the label, flags and visibility of a field.
	 *
	 * @since 0.1.0
	 *
	 * @param int      $id     Field ID.
	 * @param string   $label  Label.
	 * @param int      $flags  Flags.
	 * @param int|null $permit Who may see the field, or null for everybody.
	 * @return true|WP_Error
	 */
	public function update( int $id, string $label, int $flags, ?int $permit ): bool|WP_Error {
		$field = $this->find( $id );
		if ( null === $field ) {
			return new WP_Error( 'not_found', __( 'The field does not exist.', 'easyq' ) );
		}

		$errors = $this->validate( $field->name, $label, $id );
		if ( $errors->has_errors() ) {
			return $errors;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table; the cache is cleared below.
		DB::wpdb()->update(
			Schema::table( 'userfields' ),
			array(
				'label'  => '' === $label ? null : $label,
				'flags'  => $flags,
				'permit' => $permit,
			),
			array( 'id' => $id )
		);

		$this->forget();

		return true;
	}

	/**
	 * Moves a field to a position.
	 *
	 * @since 0.1.0
	 *
	 * @param int $id       Field ID.
	 * @param int $position New position, starting at 1; values out of range are clamped.
	 */
	public function move( int $id, int $position ): void {
		$ids = array_values( array_filter( array_map( static fn ( User_Field $field ): int => $field->id, $this->all() ), static fn ( int $field_id ): bool => $field_id !== $id ) );
		if ( null === $this->find( $id ) ) {
			return;
		}

		array_splice( $ids, max( 0, min( $position - 1, count( $ids ) ) ), 0, array( $id ) );
		$this->write_positions( $ids );
	}

	/**
	 * Deletes a field and the values users entered.
	 *
	 * @since 0.1.0
	 *
	 * @param int $id Field ID.
	 */
	public function delete( int $id ): void {
		$field = $this->find( $id );
		if ( null === $field ) {
			return;
		}

		DB::query( DB::wpdb()->prepare( 'DELETE FROM %i WHERE id = %d', Schema::table( 'userfields' ), $id ) );
		delete_metadata( 'user', 0, $field->meta_key(), '', true );

		$this->forget();
		$this->write_positions( array_map( static fn ( User_Field $remaining ): int => $remaining->id, $this->all() ) );
	}

	/**
	 * Returns the value a user entered.
	 *
	 * @since 0.1.0
	 *
	 * @param int        $user_id User ID.
	 * @param User_Field $field   Field.
	 * @return string
	 */
	public function value( int $user_id, User_Field $field ): string {
		$value = get_user_meta( $user_id, $field->meta_key(), true );

		return is_string( $value ) ? $value : '';
	}

	/**
	 * Stores the value of a field for a user, sanitized for the kind of field.
	 *
	 * @since 0.1.0
	 *
	 * @param int        $user_id User ID.
	 * @param User_Field $field   Field.
	 * @param string     $value   Raw value.
	 */
	public function set_value( int $user_id, User_Field $field, string $value ): void {
		$value = match ( true ) {
			$field->is_link()       => esc_url_raw( trim( $value ) ),
			$field->is_multi_line() => sanitize_textarea_field( $value ),
			default                 => sanitize_text_field( $value ),
		};

		if ( Text::length( $value ) > self::MAX_VALUE_LENGTH ) {
			$value = mb_substr( $value, 0, self::MAX_VALUE_LENGTH );
		}

		if ( '' === $value ) {
			delete_user_meta( $user_id, $field->meta_key() );
		} else {
			update_user_meta( $user_id, $field->meta_key(), $value );
		}
	}

	/**
	 * Validates the name and label of a field.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $name  Name.
	 * @param string   $label Label.
	 * @param int|null $id    Field ID when editing.
	 * @return WP_Error
	 */
	private function validate( string $name, string $label, ?int $id ): WP_Error {
		$errors = new WP_Error();

		if ( '' === $name ) {
			$errors->add( 'name', __( 'Please enter a name using lowercase letters, digits, hyphens or underscores.', 'easyq' ) );
		} elseif ( Text::length( $name ) > self::MAX_NAME_LENGTH ) {
			/* translators: %d: maximum number of characters. */
			$errors->add( 'name', sprintf( __( 'The name can have at most %d characters.', 'easyq' ), self::MAX_NAME_LENGTH ) );
		} else {
			foreach ( $this->all() as $field ) {
				if ( $field->id !== $id && $field->name === $name ) {
					$errors->add( 'name', __( 'Another field with this name already exists.', 'easyq' ) );
				}
			}
		}

		if ( Text::length( $label ) > self::MAX_NAME_LENGTH ) {
			/* translators: %d: maximum number of characters. */
			$errors->add( 'label', sprintf( __( 'The label can have at most %d characters.', 'easyq' ), self::MAX_NAME_LENGTH ) );
		}

		return $errors;
	}

	/**
	 * Stores positions 1, 2, 3, … for fields in the given order.
	 *
	 * @since 0.1.0
	 *
	 * @param int[] $ids Field IDs in their new order.
	 */
	private function write_positions( array $ids ): void {
		$wpdb = DB::wpdb();

		foreach ( $ids as $index => $id ) {
			DB::query( $wpdb->prepare( 'UPDATE %i SET position = %d WHERE id = %d', Schema::table( 'userfields' ), $index + 1, $id ) );
		}

		$this->forget();
	}

	/**
	 * Clears the field cache.
	 *
	 * @since 0.1.0
	 */
	private function forget(): void {
		wp_cache_delete( 'all', self::CACHE_GROUP );
	}
}
