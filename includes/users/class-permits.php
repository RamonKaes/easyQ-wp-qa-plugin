<?php
/**
 * Permission thresholds.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Users;

defined( 'ABSPATH' ) || exit;

/**
 * Who may perform an action, as stored in the `permit_*` settings.
 *
 * Lower values are stricter. The points variants require a minimum number of
 * points, stored in the setting with the suffix `_points`. Values are those of
 * Question2Answer.
 *
 * @since 0.1.0
 */
final class Permits {

	/**
	 * Everybody, including visitors.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const ALL = 150;

	/**
	 * Registered users.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const USERS = 120;

	/**
	 * Registered users with a confirmed email address.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const CONFIRMED = 110;

	/**
	 * Registered users with enough points.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const POINTS = 106;

	/**
	 * Registered users with enough points and a confirmed email address.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const POINTS_CONFIRMED = 104;

	/**
	 * Approved users.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const APPROVED = 103;

	/**
	 * Approved users, or users with enough points.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const APPROVED_POINTS = 102;

	/**
	 * Experts and above.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const EXPERTS = 100;

	/**
	 * Editors and above.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const EDITORS = 70;

	/**
	 * Moderators and above.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const MODERATORS = 40;

	/**
	 * Administrators and above.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const ADMINS = 20;

	/**
	 * Super administrators only.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const SUPERS = 0;

	/**
	 * Returns all thresholds with their names.
	 *
	 * @since 0.1.0
	 *
	 * @return array<int, string>
	 */
	public static function labels(): array {
		return array(
			self::ALL              => __( 'Anybody', 'easyq' ),
			self::USERS            => __( 'Registered users', 'easyq' ),
			self::CONFIRMED        => __( 'Registered users with confirmed email', 'easyq' ),
			self::POINTS           => __( 'Registered users with enough points', 'easyq' ),
			self::POINTS_CONFIRMED => __( 'Confirmed users with enough points', 'easyq' ),
			self::APPROVED         => __( 'Approved users', 'easyq' ),
			self::APPROVED_POINTS  => __( 'Approved users with enough points', 'easyq' ),
			self::EXPERTS          => __( 'Experts, editors, moderators and administrators', 'easyq' ),
			self::EDITORS          => __( 'Editors, moderators and administrators', 'easyq' ),
			self::MODERATORS       => __( 'Moderators and administrators', 'easyq' ),
			self::ADMINS           => __( 'Administrators', 'easyq' ),
			self::SUPERS           => __( 'Super administrators', 'easyq' ),
		);
	}

	/**
	 * Checks whether a threshold needs a minimum number of points.
	 *
	 * @since 0.1.0
	 *
	 * @param int $permit Threshold.
	 * @return bool
	 */
	public static function uses_points( int $permit ): bool {
		return in_array( $permit, array( self::POINTS, self::POINTS_CONFIRMED, self::APPROVED_POINTS ), true );
	}
}
