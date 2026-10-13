<?php
/**
 * Q&A user levels.
 *
 * @package easyQ
 */

namespace EasyQ\Users;

defined( 'ABSPATH' ) || exit;

/**
 * The Question2Answer user levels.
 *
 * A user's level comes from their WordPress roles (see the role mapping in the
 * settings), can be raised per user, and can be raised further within single
 * categories.
 */
final class Levels {

	public const BASIC     = 0;
	public const APPROVED  = 10;
	public const EXPERT    = 20;
	public const EDITOR    = 50;
	public const MODERATOR = 80;
	public const ADMIN     = 100;
	public const SUPER     = 120;

	/**
	 * Returns all levels with their names.
	 *
	 * @return array<int, string>
	 */
	public static function labels(): array {
		return array(
			self::BASIC     => __( 'Registered user', 'easyq' ),
			self::APPROVED  => __( 'Approved user', 'easyq' ),
			self::EXPERT    => __( 'Expert', 'easyq' ),
			self::EDITOR    => __( 'Editor', 'easyq' ),
			self::MODERATOR => __( 'Moderator', 'easyq' ),
			self::ADMIN     => __( 'Administrator', 'easyq' ),
			self::SUPER     => __( 'Super administrator', 'easyq' ),
		);
	}

	/**
	 * Checks whether a value is a valid level.
	 *
	 * @param int $level Level.
	 * @return bool
	 */
	public static function is_valid( int $level ): bool {
		return isset( self::labels()[ $level ] );
	}
}
