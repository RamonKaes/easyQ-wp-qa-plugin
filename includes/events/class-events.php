<?php
/**
 * Event dispatching.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Events;

use EasyQ\Actor;

defined( 'ABSPATH' ) || exit;

/**
 * Reports events as WordPress actions.
 *
 * Every Question2Answer event `x` fires the action `easyq_x` and the generic
 * action `easyq_event`. See docs/hooks.md for the list of events.
 *
 * @since 0.1.0
 */
final class Events {

	/**
	 * Every event easyQ dispatches, as `docs/hooks.md` lists them.
	 *
	 * The method dispatch() refuses others with `_doing_it_wrong()`, so a new event cannot go
	 * undocumented: the test suite fails on it, and `Docs_Test` compares this list with the document.
	 *
	 * @since 0.1.0
	 *
	 * @var string[]
	 */
	public const NAMES = array(
		'q_post',
		'q_queue',
		'q_edit',
		'q_requeue',
		'q_approve',
		'q_reject',
		'q_hide',
		'q_reshow',
		'q_close',
		'q_reopen',
		'q_move',
		'q_claim',
		'q_delete_before',
		'q_delete',
		'q_vote_up',
		'q_vote_down',
		'q_vote_nil',
		'q_flag',
		'q_unflag',
		'q_clearflags',
		'a_post',
		'a_queue',
		'a_edit',
		'a_requeue',
		'a_approve',
		'a_reject',
		'a_hide',
		'a_reshow',
		'a_select',
		'a_unselect',
		'a_to_c',
		'a_claim',
		'a_delete_before',
		'a_delete',
		'a_vote_up',
		'a_vote_down',
		'a_vote_nil',
		'a_flag',
		'a_unflag',
		'a_clearflags',
		'c_post',
		'c_queue',
		'c_edit',
		'c_requeue',
		'c_approve',
		'c_reject',
		'c_hide',
		'c_reshow',
		'c_claim',
		'c_delete_before',
		'c_delete',
		'c_vote_up',
		'c_vote_down',
		'c_vote_nil',
		'c_flag',
		'c_unflag',
		'c_clearflags',
		'u_register',
		'u_login',
		'u_logout',
		'u_confirmed',
		'u_password',
		'u_reset',
		'u_save',
		'u_edit',
		'u_level',
		'u_block',
		'u_unblock',
		'u_delete_before',
		'u_delete',
		'q_favorite',
		'q_unfavorite',
		'u_favorite',
		'u_unfavorite',
		'tag_favorite',
		'tag_unfavorite',
		'cat_favorite',
		'cat_unfavorite',
		'u_wall_post',
		'u_wall_delete',
		'u_message',
		'feedback',
		'ip_block',
		'ip_unblock',
	);

	/**
	 * Fires an event.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $event  Event name, e.g. `q_post`.
	 * @param array<string, mixed> $params Event parameters.
	 * @param Actor                $actor  Who caused the event.
	 */
	public static function dispatch( string $event, array $params, Actor $actor ): void {
		if ( ! in_array( $event, self::NAMES, true ) ) {
			_doing_it_wrong( __METHOD__, esc_html( sprintf( 'The event "%s" is not listed in Events::NAMES.', $event ) ), '0.1.0' );
		}

		/**
		 * Fires when a specific easyQ event happens.
		 *
		 * The dynamic part of the hook name is the event name, e.g. `easyq_q_post`.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string, mixed> $params Event parameters.
		 * @param Actor                $actor  Who caused the event.
		 */
		do_action( "easyq_{$event}", $params, $actor );

		/**
		 * Fires for every easyQ event.
		 *
		 * @since 0.1.0
		 *
		 * @param string               $event  Event name.
		 * @param array<string, mixed> $params Event parameters.
		 * @param Actor                $actor  Who caused the event.
		 */
		do_action( 'easyq_event', $event, $params, $actor );
	}
}
