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
use EasyQ\Settings\Options;
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
		$wpdb   = DB::wpdb();
		$cached = wp_cache_get( 'all', self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return array_values( array_filter( $cached, static fn ( $field ): bool => $field instanceof User_Field ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables without a WordPress API.
		$rows   = DB::rows( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY position, id', Schema::table( 'userfields' ) ) ) );
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
	 * @param string   $name   Internal name: lowercase letters, digits, hyphens and underscores; derived from the label if empty, as in Question2Answer.
	 * @param string   $label  Label; empty to use the name.
	 * @param int      $flags  Combination of the User_Field::FLAG_* constants.
	 * @param int|null $permit Who may see the field, or null for everybody.
	 * @return int|WP_Error The field ID, or the validation errors.
	 */
	public function create( string $name, string $label, int $flags, ?int $permit ): int|WP_Error {
		$name   = '' === trim( $name ) ? $this->free_name( $label ) : sanitize_key( $name );
		$errors = $this->validate( $name, $label, null, $permit );
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

		$errors = $this->validate( $field->name, $label, $id, $permit );
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
		$wpdb  = DB::wpdb();
		$field = $this->find( $id );
		if ( null === $field ) {
			return;
		}

		$sql = $wpdb->prepare( 'DELETE FROM %i WHERE id = %d', Schema::table( 'userfields' ), $id );
		if ( null !== $sql ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; custom tables without a WordPress API.
			$wpdb->query( $sql );
		}
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
	 * Validates the name, label and visibility of a field.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $name   Name.
	 * @param string   $label  Label.
	 * @param int|null $id     Field ID when editing.
	 * @param int|null $permit Who may see the field, or null for everybody.
	 * @return WP_Error
	 */
	private function validate( string $name, string $label, ?int $id, ?int $permit = null ): WP_Error {
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

		if ( null !== $permit && ! isset( self::permit_choices()[ $permit ] ) ) {
			$errors->add( 'permit', __( 'Please choose who may see the field.', 'easyq' ) );
		}

		return $errors;
	}

	/**
	 * Returns who may be allowed to see a field, as Question2Answer offers it: from
	 * anybody to administrators, without points, and approved users only if new users
	 * need approval.
	 *
	 * @since 0.1.0
	 *
	 * @return array<int, string> Names keyed by Permits threshold.
	 */
	public static function permit_choices(): array {
		$keys = array( Permits::ALL, Permits::USERS, Permits::EXPERTS, Permits::EDITORS, Permits::MODERATORS, Permits::ADMINS );
		if ( Options::get_bool( 'spam', 'moderate_users' ) ) {
			$keys[] = Permits::APPROVED;
		}

		$choices = array_intersect_key( Permits::labels(), array_flip( $keys ) );
		krsort( $choices );

		return $choices;
	}

	/**
	 * Returns an unused name derived from a label: `website`, `website-2`, `website-3`, …
	 *
	 * @since 0.1.0
	 *
	 * @param string $label Label.
	 * @return string An empty string if the label gives no name, so that validation reports it.
	 */
	private function free_name( string $label ): string {
		$base = substr( sanitize_key( sanitize_title( $label ) ), 0, self::MAX_NAME_LENGTH - 4 );
		if ( '' === $base ) {
			return '';
		}

		$names = array_map( static fn ( User_Field $field ): string => $field->name, $this->all() );
		for ( $number = 1; $number < 1000; $number++ ) {
			$name = 1 === $number ? $base : $base . '-' . $number;
			if ( ! in_array( $name, $names, true ) ) {
				return $name;
			}
		}

		return '';
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
			$sql = $wpdb->prepare( 'UPDATE %i SET position = %d WHERE id = %d', Schema::table( 'userfields' ), $index + 1, $id );
			if ( null !== $sql ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; custom tables without a WordPress API.
				$wpdb->query( $sql );
			}
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
