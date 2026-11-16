<?php
/**
 * WP-CLI commands.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\CLI;

use EasyQ\Actor;
use EasyQ\Tools\Tools;
use WP_CLI;
use WP_CLI\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * Manages easyQ from the command line.
 *
 * @since 0.1.0
 */
final class Command {

	/**
	 * Tool that deletes posts, which the command runs only when it is named.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const DELETING_TOOL = 'delete_hidden';

	/**
	 * Registers the commands with WP-CLI.
	 *
	 * @since 0.1.0
	 */
	public static function register(): void {
		WP_CLI::add_command( 'easyq', self::class );
	}

	/**
	 * Recalculates stored values and cleans up the database, like the tools screen of easyQ.
	 *
	 * The tools run in batches, in the order of the tools screen, whatever order they are given in. On multisite, choose the site with `--url`.
	 *
	 * ## OPTIONS
	 *
	 * [<tool>...]
	 * : Tools to run.
	 * ---
	 * options:
	 *   - recount-posts
	 *   - reindex
	 *   - recalc-points
	 *   - recalc-categories
	 *   - refill-events
	 *   - delete-hidden
	 * ---
	 *
	 * [--all]
	 * : Run every tool except delete-hidden.
	 *
	 * [--yes]
	 * : Delete hidden posts without asking.
	 *
	 * ## EXAMPLES
	 *
	 *     # Recount the posts and recalculate the points.
	 *     $ wp easyq recalc recount-posts recalc-points
	 *
	 *     # Run every tool except delete-hidden on each site of a network.
	 *     $ wp site list --field=url | xargs -I % wp easyq recalc --all --url=%
	 *
	 *     # Delete hidden posts without answers or comments, without asking.
	 *     $ wp easyq recalc delete-hidden --yes
	 *
	 * @since 0.1.0
	 *
	 * @param string[]                   $args       Tools, with hyphens.
	 * @param array<string, bool|string> $assoc_args Flags.
	 */
	public function recalc( array $args, array $assoc_args ): void {
		$all    = (bool) Utils\get_flag_value( $assoc_args, 'all', false );
		$listed = array() !== $args;
		if ( $all === $listed ) {
			WP_CLI::error( __( 'Name the tools to run, or use --all.', 'easyq' ) );
		}

		// WP-CLI has checked the names against the synopsis.
		$named = array_map( static fn ( string $arg ): string => str_replace( '-', '_', $arg ), $args );
		$tools = array();
		foreach ( array_keys( Tools::STEPS ) as $tool ) {
			if ( $all ? self::DELETING_TOOL !== $tool : in_array( $tool, $named, true ) ) {
				$tools[] = $tool;
			}
		}

		if ( in_array( self::DELETING_TOOL, $tools, true ) ) {
			WP_CLI::confirm( __( 'Delete all hidden questions, answers and comments without answers or comments of their own? This cannot be undone.', 'easyq' ), $assoc_args );
		}

		$labels = Tools::labels();
		$runner = new Tools();
		$actor  = Actor::current();
		foreach ( $tools as $tool ) {
			$this->run( $runner, $actor, $tool, $labels[ $tool ][0] );
		}

		WP_CLI::success(
			/* translators: %d: number of tools. */
			sprintf( _n( 'Ran %d tool.', 'Ran %d tools.', count( $tools ), 'easyq' ), count( $tools ) )
		);
	}

	/**
	 * Runs a tool to the end, with a progress bar for each step that has items.
	 *
	 * @since 0.1.0
	 *
	 * @param Tools  $runner Tools.
	 * @param Actor  $actor  Who runs the tool.
	 * @param string $tool   A key of Tools::STEPS.
	 * @param string $label  Name of the tool.
	 */
	private function run( Tools $runner, Actor $actor, string $tool, string $label ): void {
		$step  = (string) array_key_first( Tools::STEPS[ $tool ] );
		$after = 0;
		$total = null;
		$done  = 0;
		$token = '';
		$bar   = null;
		$shown = 0;

		do {
			$result = $runner->run( $actor, $tool, $step, $after, $total, $done, $token );
			if ( $result instanceof \WP_Error ) {
				WP_CLI::error( $result->get_error_message() );
			}
			if ( null === $result ) {
				WP_CLI::error( __( 'The tool has no such step.', 'easyq' ) );
			}

			// The result describes the next step once this one is finished.
			$finished = $result['complete'] || $result['step'] !== $step;
			if ( Tools::STEPS[ $tool ][ $step ] > 0 && $result['step'] === $step && $result['total'] > 0 ) {
				if ( null === $bar ) {
					$bar = Utils\make_progress_bar(
						/* translators: 1: name of the tool, 2: number of the step, 3: number of steps. */
						sprintf( __( '%1$s, step %2$d of %3$d', 'easyq' ), $label, $result['step_number'], $result['steps'] ),
						$result['total']
					);
				}
				// Deleted posts leave the count, so the total can shrink during a run.
				$bar->tick( max( 0, $result['done'] - $shown ) );
				$shown = max( $shown, $result['done'] );
			}
			if ( $finished && null !== $bar ) {
				$bar->finish();
				$bar   = null;
				$shown = 0;
			}

			// Long runs would otherwise keep every post and user they read in memory.
			Utils\wp_clear_object_cache();

			$step  = $result['step'];
			$after = $result['after'];
			$total = 0 === $after ? null : $result['total'];
			$done  = $result['done'];
			$token = $result['run'];
		} while ( ! $result['complete'] );

		/* translators: %s: name of the tool. */
		WP_CLI::log( sprintf( __( '%s: done.', 'easyq' ), $label ) );
	}
}
