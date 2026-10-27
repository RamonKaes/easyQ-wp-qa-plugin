<?php
/**
 * Q&A user levels.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Users;

defined( 'ABSPATH' ) || exit;

/**
 * The Question2Answer user levels.
 *
 * A user's level comes from their WordPress roles (see the role mapping in the
 * settings), can be raised per user, and can be raised further within single
 * categories.
 *
 * @since 0.1.0
 */
final class Levels {

	/**
	 * Level of registered users.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const BASIC = 0;

	/**
	 * Level of approved users.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const APPROVED = 10;

	/**
	 * Level of experts.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const EXPERT = 20;

	/**
	 * Level of editors.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const EDITOR = 50;

	/**
	 * Level of moderators.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const MODERATOR = 80;

	/**
	 * Level of administrators.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const ADMIN = 100;

	/**
	 * Level of super administrators.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const SUPER = 120;

	/**
	 * Returns all levels with their names.
	 *
	 * @since 0.1.0
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
	 * @since 0.1.0
	 *
	 * @param int $level Level.
	 * @return bool
	 */
	public static function is_valid( int $level ): bool {
		return isset( self::labels()[ $level ] );
	}
}
