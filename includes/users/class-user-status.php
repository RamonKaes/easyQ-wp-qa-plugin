<?php
/**
 * Confirmation, approval and blocking of users.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Users;

use EasyQ\Actor;
use EasyQ\Events\Events;
use EasyQ\Settings\Options;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Tracks whether users confirmed their email address, were approved and are blocked.
 *
 * WordPress itself has no such states, so they are stored as user meta. Users
 * without meta count as confirmed and need no approval; this keeps existing
 * users working when the plugin is installed or the settings change.
 *
 * - Users who register through the WordPress registration form are marked as
 *   unconfirmed if the site confirms email addresses, and as waiting for
 *   approval if the site approves new users.
 * - Setting the password through the link WordPress emails confirms the address.
 *
 * @since 0.1.0
 */
final class User_Status {

	/**
	 * User meta key set while the email address is not confirmed.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const UNCONFIRMED_META = 'easyq_unconfirmed';

	/**
	 * User meta key set while the user waits for approval.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const UNAPPROVED_META = 'easyq_unapproved';

	/**
	 * User meta key set once a moderator approved the user.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const APPROVED_META = 'easyq_approved';

	/**
	 * User meta key set while the user is blocked.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const BLOCKED_META = 'easyq_blocked';

	/**
	 * Hooks the status handling into WordPress.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_action( 'register_new_user', array( $this, 'mark_new_registration' ) );
		add_action( 'after_password_reset', array( $this, 'confirm_after_password_reset' ) );
	}

	/**
	 * Marks a user who registered through the registration form.
	 *
	 * @since 0.1.0
	 *
	 * @param int $user_id User ID.
	 */
	public function mark_new_registration( int $user_id ): void {
		if ( Options::get_bool( 'spam', 'confirm_user_emails' ) ) {
			update_user_meta( $user_id, self::UNCONFIRMED_META, 1 );
		}

		if ( Options::get_bool( 'spam', 'moderate_users' ) ) {
			Site_Meta::update( $user_id, self::UNAPPROVED_META, 1 );
		}
	}

	/**
	 * Confirms the email address of a user who set a password through the emailed link.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_User $user User.
	 */
	public function confirm_after_password_reset( WP_User $user ): void {
		self::confirm( new Actor( $user->ID ), $user->ID );
	}

	/**
	 * Checks whether the email address of a user is confirmed.
	 *
	 * @since 0.1.0
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_confirmed( int $user_id ): bool {
		return '' === get_user_meta( $user_id, self::UNCONFIRMED_META, true );
	}

	/**
	 * Checks whether a user waits for approval.
	 *
	 * @since 0.1.0
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function awaits_approval( int $user_id ): bool {
		return '' !== Site_Meta::get( $user_id, self::UNAPPROVED_META );
	}

	/**
	 * Checks whether a moderator approved a user.
	 *
	 * @since 0.1.0
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_approved( int $user_id ): bool {
		return '' !== Site_Meta::get( $user_id, self::APPROVED_META );
	}

	/**
	 * Checks whether a user is blocked.
	 *
	 * @since 0.1.0
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_blocked( int $user_id ): bool {
		return '' !== Site_Meta::get( $user_id, self::BLOCKED_META );
	}

	/**
	 * Marks the email address of a user as confirmed.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor $actor   Who confirms.
	 * @param int   $user_id User ID.
	 */
	public static function confirm( Actor $actor, int $user_id ): void {
		if ( self::is_confirmed( $user_id ) ) {
			return;
		}

		delete_user_meta( $user_id, self::UNCONFIRMED_META );
		Events::dispatch( 'u_confirmed', array( 'user_id' => $user_id ), $actor );
	}

	/**
	 * Marks the email address of a user as not confirmed, e.g. after it changed.
	 *
	 * @since 0.1.0
	 *
	 * @param int $user_id User ID.
	 */
	public static function unconfirm( int $user_id ): void {
		update_user_meta( $user_id, self::UNCONFIRMED_META, 1 );
	}

	/**
	 * Approves a user.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor $actor   Moderator.
	 * @param int   $user_id User ID.
	 */
	public static function approve( Actor $actor, int $user_id ): void {
		if ( self::is_approved( $user_id ) && ! self::awaits_approval( $user_id ) ) {
			return;
		}

		Site_Meta::delete( $user_id, self::UNAPPROVED_META );
		Site_Meta::update( $user_id, self::APPROVED_META, 1 );

		Events::dispatch(
			'u_level',
			array(
				'user_id' => $user_id,
				'level'   => Levels::APPROVED,
			),
			$actor
		);
	}

	/**
	 * Blocks a user from posting, voting and changing anything.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor $actor   Moderator.
	 * @param int   $user_id User ID.
	 */
	public static function block( Actor $actor, int $user_id ): void {
		if ( self::is_blocked( $user_id ) ) {
			return;
		}

		Site_Meta::update( $user_id, self::BLOCKED_META, 1 );
		Events::dispatch( 'u_block', array( 'user_id' => $user_id ), $actor );
	}

	/**
	 * Unblocks a user.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor $actor   Moderator.
	 * @param int   $user_id User ID.
	 */
	public static function unblock( Actor $actor, int $user_id ): void {
		if ( ! self::is_blocked( $user_id ) ) {
			return;
		}

		Site_Meta::delete( $user_id, self::BLOCKED_META );
		Events::dispatch( 'u_unblock', array( 'user_id' => $user_id ), $actor );
	}
}
