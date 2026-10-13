<?php
/**
 * User profile field model.
 *
 * @package easyQ
 */

namespace EasyQ\Users;

use EasyQ\Database\Row;

defined( 'ABSPATH' ) || exit;

/**
 * A profile field defined by the site owner, e.g. "Location".
 */
final class User_Field {

	public const FLAG_MULTI_LINE  = 1;
	public const FLAG_LINK        = 2;
	public const FLAG_ON_REGISTER = 4;

	/**
	 * Sets up the field.
	 *
	 * @param int      $id       Field ID.
	 * @param string   $name     Internal name, also used in the user meta key.
	 * @param string   $label    Label shown to users; empty to use the name.
	 * @param int      $position Position, starting at 1.
	 * @param int      $flags    Combination of the FLAG_* constants.
	 * @param int|null $permit   Who may see the field (see Permits), or null for everybody.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $name,
		public readonly string $label,
		public readonly int $position,
		public readonly int $flags,
		public readonly ?int $permit
	) {
	}

	/**
	 * Creates a field from a database row.
	 *
	 * @param object $row Row of the userfields table.
	 * @return self
	 */
	public static function from_row( object $row ): self {
		return new self(
			Row::int( $row, 'id' ),
			Row::string( $row, 'name' ),
			Row::string( $row, 'label' ),
			Row::int( $row, 'position' ),
			Row::int( $row, 'flags' ),
			Row::nullable_int( $row, 'permit' )
		);
	}

	/**
	 * Returns the label to show.
	 *
	 * @return string
	 */
	public function display_label(): string {
		return '' === $this->label ? $this->name : $this->label;
	}

	/**
	 * Returns the user meta key that stores the values.
	 *
	 * @return string
	 */
	public function meta_key(): string {
		return 'easyq_field_' . $this->name;
	}

	/**
	 * Checks whether the field holds several lines of text.
	 *
	 * @return bool
	 */
	public function is_multi_line(): bool {
		return 0 !== ( $this->flags & self::FLAG_MULTI_LINE );
	}

	/**
	 * Checks whether the field holds a web address.
	 *
	 * @return bool
	 */
	public function is_link(): bool {
		return 0 !== ( $this->flags & self::FLAG_LINK );
	}

	/**
	 * Checks whether the field is shown on the registration form.
	 *
	 * @return bool
	 */
	public function on_register(): bool {
		return 0 !== ( $this->flags & self::FLAG_ON_REGISTER );
	}
}
