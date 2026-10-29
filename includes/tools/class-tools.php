<?php
/**
 * Maintenance tools.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Tools;

use EasyQ\Actor;
use EasyQ\Database\DB;
use EasyQ\Database\Schema;
use EasyQ\Posts\Post;
use EasyQ\Services;
use EasyQ\Stats\Counts;

defined( 'ABSPATH' ) || exit;

/**
 * Recalculates stored values and cleans up the database in batches, like the
 * database clean-up of the Question2Answer page `admin/stats` (`admin/recalc`).
 *
 * Each call handles one batch of one step and returns where to go on, so a long
 * run is split into short requests and needs no stored state.
 *
 * @since 0.1.0
 */
final class Tools {

	/**
	 * Steps of each tool, in order, with the number of items in one batch.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, array<string, int>>
	 */
	public const STEPS = array(
		// Votes come first: the answers step reads the votes of the answers.
		'recount_posts'     => array(
			'votes'   => 500,
			'answers' => 500,
			'counts'  => 0,
		),
		'reindex'           => array(
			'questions' => 100,
		),
		'recalc_points'     => array(
			'users'  => 200,
			'counts' => 0,
		),
		'recalc_categories' => array(
			'backpaths' => 0,
			'posts'     => 1000,
			'counts'    => 0,
		),
		'delete_hidden'     => array(
			'comments'  => 100,
			'answers'   => 100,
			'questions' => 100,
		),
	);

	/**
	 * Returns the names and descriptions of the tools.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function labels(): array {
		return array(
			'recount_posts'     => array( __( 'Recount posts', 'easyq' ), __( 'The number of answers, votes and flags and the hotness of each post, and the counts of the site.', 'easyq' ) ),
			'reindex'           => array( __( 'Rebuild the search index', 'easyq' ), __( 'For searching and for related questions.', 'easyq' ) ),
			'recalc_points'     => array( __( 'Recalculate points', 'easyq' ), __( 'The points and statistics of every user, for ranks and titles.', 'easyq' ) ),
			'recalc_categories' => array( __( 'Recalculate categories', 'easyq' ), __( 'The category of each post and the number of questions in each category.', 'easyq' ) ),
			'delete_hidden'     => array( __( 'Delete hidden posts', 'easyq' ), __( 'Deletes all hidden questions, answers and comments without answers or comments of their own. This cannot be undone.', 'easyq' ) ),
		);
	}

	/**
	 * Runs one batch of a tool.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor  $actor Who runs the tool.
	 * @param string $tool  A key of STEPS.
	 * @param string $step  Step to run; empty for the first step.
	 * @param int    $after Last item handled in this step; 0 to start the step.
	 * @return array{tool: string, step: string, step_number: int, steps: int, after: int, done: int, total: int, complete: bool}|null Where to go on, or null for an unknown tool or step.
	 */
	public function run( Actor $actor, string $tool, string $step = '', int $after = 0 ): ?array {
		$steps = self::STEPS[ $tool ] ?? null;
		if ( null === $steps ) {
			return null;
		}

		$names = array_keys( $steps );
		$step  = '' === $step ? $names[0] : $step;
		$index = array_search( $step, $names, true );
		if ( false === $index ) {
			return null;
		}

		$ids  = $steps[ $step ] > 0 ? $this->items( $tool, $step, $after, $steps[ $step ] ) : array();
		$last = array() === $ids ? $after : max( $ids );
		if ( array() !== $ids || 0 === $steps[ $step ] ) {
			$this->handle( $actor, $tool, $step, $ids );
		}

		// A step ends when a batch finds nothing more; steps without items take one call.
		$finished = 0 === $steps[ $step ] || array() === $ids;
		$total    = $steps[ $step ] > 0 ? $this->count( $tool, $step, 0 ) : 1;
		$done     = $steps[ $step ] > 0 ? $total - $this->count( $tool, $step, $last ) : 1;
		$next     = $names[ $index + 1 ] ?? null;

		return array(
			'tool'        => $tool,
			'step'        => $finished && null !== $next ? $next : $step,
			'step_number' => ( $finished && null !== $next ? $index + 1 : $index ) + 1,
			'steps'       => count( $names ),
			'after'       => $finished ? 0 : $last,
			'done'        => $finished && null !== $next ? 0 : $done,
			'total'       => $finished && null !== $next ? 0 : $total,
			'complete'    => $finished && null === $next,
		);
	}

	/**
	 * Returns the items of the next batch of a step.
	 *
	 * @since 0.1.0
	 *
	 * @param string $tool  Tool.
	 * @param string $step  Step.
	 * @param int    $after Last item handled.
	 * @param int    $limit Batch size.
	 * @return int[] IDs in ascending order.
	 */
	private function items( string $tool, string $step, int $after, int $limit ): array {
		$wpdb = DB::wpdb();

		[ $kind, $types ] = $this->kind( $tool, $step );
		if ( 'users' === $kind ) {
			return DB::int_col(
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables without a WordPress API.
				$wpdb->get_col(
					$wpdb->prepare(
						'SELECT user_id FROM (SELECT user_id FROM %i WHERE user_id IS NOT NULL UNION SELECT user_id FROM %i UNION SELECT user_id FROM %i) AS u WHERE user_id > %d ORDER BY user_id LIMIT %d',
						Schema::table( 'posts' ),
						Schema::table( 'uservotes' ),
						Schema::table( 'userpoints' ),
						$after,
						$limit
					)
				)
			);
		}

		return DB::int_col(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables without a WordPress API.
			$wpdb->get_col(
				$wpdb->prepare(
					'SELECT id FROM %i WHERE id > %d AND FIND_IN_SET(type, %s) ORDER BY id LIMIT %d',
					Schema::table( 'posts' ),
					$after,
					$types,
					$limit
				)
			)
		);
	}

	/**
	 * Counts the items of a step after an item.
	 *
	 * @since 0.1.0
	 *
	 * @param string $tool  Tool.
	 * @param string $step  Step.
	 * @param int    $after Last item handled; 0 for all items.
	 * @return int
	 */
	private function count( string $tool, string $step, int $after ): int {
		$wpdb = DB::wpdb();

		[ $kind, $types ] = $this->kind( $tool, $step );
		if ( 'users' === $kind ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables without a WordPress API.
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM (SELECT user_id FROM %i WHERE user_id IS NOT NULL UNION SELECT user_id FROM %i UNION SELECT user_id FROM %i) AS u WHERE user_id > %d',
					Schema::table( 'posts' ),
					Schema::table( 'uservotes' ),
					Schema::table( 'userpoints' ),
					$after
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables without a WordPress API.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE id > %d AND FIND_IN_SET(type, %s)',
				Schema::table( 'posts' ),
				$after,
				$types
			)
		);
	}

	/**
	 * Returns what the items of a step are: users, or posts of some types.
	 *
	 * @since 0.1.0
	 *
	 * @param string $tool Tool.
	 * @param string $step Step.
	 * @return array{0: string, 1: string} `users` or `posts`, and the post types separated by commas.
	 */
	private function kind( string $tool, string $step ): array {
		$all = 'Q,A,C,Q_QUEUED,A_QUEUED,C_QUEUED,Q_HIDDEN,A_HIDDEN,C_HIDDEN';

		return match ( true ) {
			'users' === $step                                => array( 'users', '' ),
			'recount_posts' === $tool && 'answers' === $step => array( 'posts', 'Q,Q_QUEUED,Q_HIDDEN' ),
			'reindex' === $tool                              => array( 'posts', 'Q,Q_QUEUED,Q_HIDDEN' ),
			'delete_hidden' === $tool && 'comments' === $step => array( 'posts', 'C_HIDDEN' ),
			'delete_hidden' === $tool && 'answers' === $step  => array( 'posts', 'A_HIDDEN' ),
			'delete_hidden' === $tool                        => array( 'posts', 'Q_HIDDEN' ),
			'recalc_categories' === $tool                    => array( 'posts', $all . ',' . Post::NOTE ),
			default                                          => array( 'posts', $all ),
		};
	}

	/**
	 * Handles the items of a batch.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor  $actor Who runs the tool.
	 * @param string $tool  Tool.
	 * @param string $step  Step.
	 * @param int[]  $ids   Items of the batch; empty for steps without items.
	 */
	private function handle( Actor $actor, string $tool, string $step, array $ids ): void {
		$posts      = Services::posts();
		$categories = Services::categories();

		switch ( $tool . '/' . $step ) {
			case 'recount_posts/votes':
				foreach ( $ids as $id ) {
					$posts->recount_votes( $id );
					$posts->recount_flags( $id );
				}
				break;

			case 'recount_posts/answers':
				if ( array() === $ids ) {
					break;
				}
				foreach ( $ids as $id ) {
					$posts->recount_answers( $id );
				}
				// Hotness uses the answers and votes counted before.
				$posts->update_hotness( min( $ids ), max( $ids ) );
				break;

			case 'recount_posts/counts':
				Counts::invalidate( Counts::QUESTIONS, Counts::ANSWERS, Counts::COMMENTS, Counts::UNANSWERED, Counts::UNSELECTED, Counts::UNUPVOTED, Counts::QUEUED, Counts::FLAGGED, Counts::TAGS );
				break;

			case 'reindex/questions':
				foreach ( $posts->find_many( $ids ) as $question ) {
					Services::post_service()->reindex_question( $question );
				}
				break;

			case 'recalc_points/users':
				foreach ( $ids as $user_id ) {
					Services::points()->update_all( $user_id );
				}
				break;

			case 'recalc_points/counts':
				Counts::invalidate( Counts::USERS_WITH_POINTS );
				break;

			case 'recalc_categories/backpaths':
				$categories->recalc_backpaths( array_keys( $categories->all() ) );
				break;

			case 'recalc_categories/posts':
				if ( array() !== $ids ) {
					$posts->calc_category_path( min( $ids ), max( $ids ) );
				}
				break;

			case 'recalc_categories/counts':
				$categories->recount( array_keys( $categories->all() ) );
				break;

			case 'delete_hidden/comments':
			case 'delete_hidden/answers':
			case 'delete_hidden/questions':
				$this->delete_hidden( $actor, $ids );
				break;
		}
	}

	/**
	 * Deletes hidden posts that have no answers or comments of their own; others stay.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor $actor Who deletes the posts.
	 * @param int[] $ids   Post IDs.
	 */
	private function delete_hidden( Actor $actor, array $ids ): void {
		$service = Services::post_service();

		foreach ( Services::posts()->find_many( $ids ) as $post ) {
			$question = $service->question_of( $post );
			if ( null === $question ) {
				continue;
			}

			match ( $post->base_type() ) {
				Post::QUESTION => $service->delete_question( $actor, $post ),
				Post::ANSWER   => $service->delete_answer( $actor, $post, $question ),
				default        => $service->delete_comment( $actor, $post, $question, Services::posts()->find( (int) $post->parent_id ) ?? $question ),
			};
		}
	}
}
