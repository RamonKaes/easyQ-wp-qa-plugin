<?php
/**
 * Event dispatching.
 *
 * @package easyQ
 */

namespace EasyQ\Events;

use EasyQ\Actor;

defined( 'ABSPATH' ) || exit;

/**
 * Reports events as WordPress actions.
 *
 * Every Question2Answer event `x` fires the action `easyq_x` and the generic
 * action `easyq_event`. See docs/hooks.md for the list of events.
 */
final class Events {

	/**
	 * Fires an event.
	 *
	 * @param string               $event  Event name, e.g. `q_post`.
	 * @param array<string, mixed> $params Event parameters.
	 * @param Actor                $actor  Who caused the event.
	 */
	public static function dispatch( string $event, array $params, Actor $actor ): void {
		/**
		 * Fires when a specific easyQ event happens.
		 *
		 * The dynamic part of the hook name is the event name, e.g. `easyq_q_post`.
		 *
		 * @param array<string, mixed> $params Event parameters.
		 * @param Actor                $actor  Who caused the event.
		 */
		do_action( "easyq_{$event}", $params, $actor );

		/**
		 * Fires for every easyQ event.
		 *
		 * @param string               $event  Event name.
		 * @param array<string, mixed> $params Event parameters.
		 * @param Actor                $actor  Who caused the event.
		 */
		do_action( 'easyq_event', $event, $params, $actor );
	}
}
