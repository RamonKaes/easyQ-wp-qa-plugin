<?php
/**
 * Write activity tracking.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Events;

use EasyQ\Actor;
use EasyQ\Database\DB;
use EasyQ\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Records when users and anonymous visitors last changed something.
 *
 * Ported from Question2Answer `qa-event-limits.php`, which stores the time and
 * IP address of the last write action. easyQ stores the time for users (no IP
 * address) and both for anonymous visitor cookies.
 *
 * @since 0.1.0
 */
final class Activity_Tracker {

	/**
	 * User meta key with the time of the last write action (Unix timestamp).
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const LAST_WRITE_META = 'easyq_last_write';

	/**
	 * Event name suffixes and names that count as write actions.
	 *
	 * @since 0.1.0
	 *
	 * @var string[]
	 */
	private const WRITE_ACTIONS = array(
		'_approve',
		'_claim',
		'_clearflags',
		'_delete',
		'_edit',
		'_favorite',
		'_flag',
		'_hide',
		'_post',
		'_queue',
		'_reject',
		'_reshow',
		'_unfavorite',
		'_unflag',
		'_vote_down',
		'_vote_nil',
		'_vote_up',
		'a_select',
		'a_to_c',
		'a_unselect',
		'q_close',
		'q_move',
		'q_reopen',
		'u_block',
		'u_edit',
		'u_level',
		'u_message',
		'u_password',
		'u_save',
		'u_unblock',
	);

	/**
	 * Hooks the tracker into the easyQ events.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_action( 'easyq_event', array( $this, 'track' ), 10, 3 );
	}

	/**
	 * Records a write action.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $event  Event name.
	 * @param array<string, mixed> $params Event parameters.
	 * @param Actor                $actor  Who caused the event.
	 */
	public function track( string $event, array $params, Actor $actor ): void {
		$suffix = strstr( $event, '_' );
		if ( ! in_array( $event, self::WRITE_ACTIONS, true ) && ! in_array( $suffix, self::WRITE_ACTIONS, true ) ) {
			return;
		}

		if ( null !== $actor->user_id ) {
			update_user_meta( $actor->user_id, self::LAST_WRITE_META, time() );
			return;
		}

		if ( null !== $actor->cookie_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
			DB::wpdb()->update(
				Schema::table( 'cookies' ),
				array(
					'written'    => DB::now(),
					'written_ip' => $actor->stored_ip(),
				),
				array( 'id' => $actor->cookie_id )
			);
		}
	}
}
