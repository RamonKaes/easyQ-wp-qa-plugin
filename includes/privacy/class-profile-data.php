<?php
/**
 * Personal data of Q&A profiles.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Privacy;

use EasyQ\Database\DB;
use EasyQ\Database\Schema;
use EasyQ\Events\Activity_Tracker;
use EasyQ\Mail\Notifications;
use EasyQ\Messages\Messages;
use EasyQ\Services;
use EasyQ\Users\Avatars;
use EasyQ\Users\Levels;
use EasyQ\Users\Site_Meta;
use EasyQ\Users\User_Status;

defined( 'ABSPATH' ) || exit;

/**
 * Exports and erases the Q&A profile of a user.
 *
 * Follows `wp_user_personal_data_exporter()`, which exports the profile of the account.
 * Erasing removes what the user entered or uploaded: the profile fields, the avatar
 * and the time of the last post. Points, the level, the approval or block and the
 * email settings belong to the account like its role and stay; core keeps the
 * account itself as well.
 *
 * @since 0.1.0
 */
final class Profile_Data {

	/**
	 * Exports the Q&A profile of a user.
	 *
	 * @since 0.1.0
	 *
	 * @param string $email Email address.
	 * @return array{data: list<array<string, mixed>>, done: bool}
	 */
	public static function export( string $email ): array {
		$user = '' === $email ? false : get_user_by( 'email', $email );
		if ( false === $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$fields = array();
		foreach ( Services::user_fields()->all() as $field ) {
			$value = Services::user_fields()->value( $user->ID, $field );
			if ( '' !== $value ) {
				$fields[] = array(
					'name'  => $field->display_label(),
					'value' => $value,
				);
			}
		}

		$avatar = Avatars::attachment_id( $user->ID );
		$url    = null === $avatar ? false : wp_get_attachment_url( $avatar );
		if ( is_string( $url ) ) {
			$fields[] = array(
				'name'  => __( 'Uploaded avatar', 'easyq' ),
				'value' => sprintf( '<a href="%s" target="_blank">%s</a>', esc_url( $url ), esc_html( $url ) ),
			);
		}

		$fields[] = array(
			'name'  => __( 'Points', 'easyq' ),
			'value' => (string) Services::points()->get( $user->ID ),
		);

		$level = Services::user_levels()->assigned_level( $user->ID );
		if ( null !== $level ) {
			$fields[] = array(
				'name'  => __( 'Q&A level', 'easyq' ),
				'value' => Levels::labels()[ $level ] ?? (string) $level,
			);
		}

		$status = match ( true ) {
			User_Status::is_blocked( $user->ID )       => __( 'Blocked', 'easyq' ),
			User_Status::awaits_approval( $user->ID )  => __( 'Awaiting approval', 'easyq' ),
			! User_Status::is_confirmed( $user->ID )   => __( 'Email address not confirmed', 'easyq' ),
			default                                    => '',
		};
		if ( '' !== $status ) {
			$fields[] = array(
				'name'  => __( 'Account status', 'easyq' ),
				'value' => $status,
			);
		}

		$settings = array_filter(
			array(
				Notifications::enabled( $user->ID ) ? '' : __( 'No emails about your posts', 'easyq' ),
				Messages::accepts_messages( $user->ID ) ? '' : __( 'No private messages', 'easyq' ),
				empty( Site_Meta::get( $user->ID, Messages::NO_WALL_META ) ) ? '' : __( 'No posts on your wall', 'easyq' ),
			)
		);
		if ( array() !== $settings ) {
			$fields[] = array(
				'name'  => __( 'Settings', 'easyq' ),
				'value' => implode( ', ', $settings ),
			);
		}

		$last_write = Site_Meta::get( $user->ID, Activity_Tracker::LAST_WRITE_META );
		if ( is_string( $last_write ) && '' !== $last_write ) {
			$fields[] = array(
				'name'  => __( 'Last post, vote or message', 'easyq' ),
				'value' => ( is_numeric( $last_write ) ? gmdate( 'Y-m-d H:i:s', (int) $last_write ) : $last_write ) . ' UTC',
			);
		}

		return array(
			'data' => array(
				array(
					'group_id'          => 'easyq-profile',
					'group_label'       => __( 'Q&A profile', 'easyq' ),
					'group_description' => __( 'Your profile on the question and answer pages.', 'easyq' ),
					'item_id'           => 'easyq-profile-' . $user->ID,
					'data'              => $fields,
				),
			),
			'done' => true,
		);
	}

	/**
	 * Erases the profile fields, the uploaded avatar and the activity times of a user.
	 *
	 * @since 0.1.0
	 *
	 * @param string $email Email address.
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public static function erase( string $email ): array {
		$user = '' === $email ? false : get_user_by( 'email', $email );
		if ( false === $user ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$removed = false;
		foreach ( Services::user_fields()->all() as $field ) {
			if ( '' !== Services::user_fields()->value( $user->ID, $field ) ) {
				Services::user_fields()->set_value( $user->ID, $field, '' );
				$removed = true;
			}
		}

		if ( null !== Avatars::attachment_id( $user->ID ) ) {
			Avatars::remove( $user->ID );
			$removed = true;
		}

		if ( '' !== Site_Meta::get( $user->ID, Activity_Tracker::LAST_WRITE_META ) ) {
			Site_Meta::delete( $user->ID, Activity_Tracker::LAST_WRITE_META );
			$removed = true;
		}

		$wpdb = DB::wpdb();
		$sql  = $wpdb->prepare( 'DELETE FROM %i WHERE user_id = %d', Schema::table( 'userlimits' ), $user->ID );
		if ( null !== $sql ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; custom tables without a WordPress API.
			$wpdb->query( $sql );
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => true,
			'messages'       => array( __( 'The Q&A points, level, approval or block and email settings stay with the user account.', 'easyq' ) ),
			'done'           => true,
		);
	}
}
