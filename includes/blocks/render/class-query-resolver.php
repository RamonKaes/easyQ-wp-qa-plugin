<?php
/**
 * Question queries of blocks.
 *
 * @package easyQ
 */

namespace EasyQ\Blocks\Render;

use EasyQ\Blocks\Block_Context;
use EasyQ\Blocks\Lists;
use EasyQ\Posts\Activity_Item;
use EasyQ\Posts\Activity_Query;
use EasyQ\Posts\Question_Query;
use EasyQ\Services;
use EasyQ\Settings\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the query of a question list block, or the current page, into a list of questions.
 */
final class Query_Resolver {

	/**
	 * Sort orders of the start page, selected with the `sort` parameter.
	 *
	 * @var string[]
	 */
	public const SORTS = array( 'recent', 'hot', 'votes', 'answers', 'views' );

	/**
	 * Lists of the unanswered page, selected with the `by` parameter.
	 *
	 * @var string[]
	 */
	public const UNANSWERED = array( 'unanswered', 'unselected', 'unupvoted' );

	/**
	 * Sources that list activity instead of questions, with the kinds of activity they include.
	 *
	 * @var array<string, string[]>
	 */
	public const ACTIVITY = array(
		'activity'        => Activity_Query::KINDS,
		'recent_answers'  => array( 'answers' ),
		'recent_comments' => array( 'comments' ),
	);

	/**
	 * Lists the questions of a block and stores the result for pagination.
	 *
	 * @param array<mixed> $query   Block query: source, category, tag, perPage, inherit.
	 * @param string       $list_id List ID.
	 * @return array{ids: list<int>, total: int, activity: list<Activity_Item|null>} Question IDs, the
	 *         number of items on all pages, and the activity shown for each item, in list order.
	 */
	public static function questions( array $query, string $list_id ): array {
		$args     = self::arguments( $query );
		$source   = is_string( $args['source'] ?? null ) ? $args['source'] : 'recent';
		$page_var = ! empty( $query['inherit'] ) ? 'easyq_page' : 'easyq_page_' . $list_id;
		$page     = Lists::requested_page( $page_var );
		$default  = isset( self::ACTIVITY[ $source ] ) ? Options::get_int( 'lists', 'page_size_activity' ) : Options::get_int( 'lists', 'page_size_qs' );
		$per_page = is_numeric( $query['perPage'] ?? null ) && (int) $query['perPage'] > 0 ? (int) $query['perPage'] : $default;
		$per_page = max( 1, min( Question_Query::MAX_PER_PAGE, $per_page ) );
		$activity = array();

		if ( isset( $args['search'] ) && is_string( $args['search'] ) ) {
			$start   = ( $page - 1 ) * $per_page;
			$results = Services::search()->search( $args['search'], $start, $per_page + 1, Block_Context::viewer()->user_id );
			$ids     = array_slice( array_map( static fn ( $result ): int => $result->question_id, $results ), 0, $per_page );
			$total   = $start + count( $results );
		} elseif ( isset( self::ACTIVITY[ $source ] ) ) {
			[ $activity, $total, $page ] = self::activity( $source, $args, $page, $per_page );
			$ids                         = array_map( static fn ( Activity_Item $item ): int => $item->question_id, $activity );
		} else {
			$found = Services::question_query()->find( $args + compact( 'page', 'per_page' ) );
			$ids   = array_map( static fn ( $post ): int => $post->id, $found['posts'] );
			$total = $found['total'];
		}

		Lists::store( $list_id, $ids, $total, $page, $per_page, $page_var );

		$ids = array_values( $ids );

		return array(
			'ids'      => $ids,
			'total'    => $total,
			'activity' => array() === $activity ? array_map( static fn (): ?Activity_Item => null, $ids ) : $activity,
		);
	}

	/**
	 * Returns the query arguments of a block or of the current page.
	 *
	 * @param array<mixed> $query Block query.
	 * @return array<string, mixed>
	 */
	public static function arguments( array $query ): array {
		$route = Block_Context::router()->current();

		if ( ! empty( $query['inherit'] ) && null !== $route ) {
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Reading list filters.
			$sort = isset( $_GET['sort'] ) && is_string( $_GET['sort'] ) ? sanitize_key( $_GET['sort'] ) : '';
			$by   = isset( $_GET['by'] ) && is_string( $_GET['by'] ) ? sanitize_key( $_GET['by'] ) : '';
			$q    = isset( $_GET['q'] ) && is_string( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
			// phpcs:enable WordPress.Security.NonceVerification.Recommended

			return match ( $route->name ) {
				'hot'        => array( 'source' => 'hot' ),
				'unanswered' => array( 'source' => in_array( $by, self::UNANSWERED, true ) ? $by : 'unanswered' ),
				'activity'   => array( 'source' => 'activity' ),
				'answers'    => array( 'source' => 'recent_answers' ),
				'comments'   => array( 'source' => 'recent_comments' ),
				'user'       => array(
					'source'  => match ( $route->section ) {
						'questions' => 'recent',
						'answers'   => 'recent_answers',
						default     => 'activity',
					},
					'user_id' => $route->user()?->ID,
				),
				'tag'        => array(
					'source' => 'recent',
					'tag'    => $route->tag(),
				),
				'category'   => array(
					'source'      => in_array( $sort, self::SORTS, true ) ? $sort : 'recent',
					'category_id' => $route->category()?->id,
				),
				'search'     => array( 'search' => $q ),
				default      => array( 'source' => in_array( $sort, self::SORTS, true ) ? $sort : 'recent' ),
			};
		}

		$category = is_numeric( $query['category'] ?? null ) && (int) $query['category'] > 0 ? (int) $query['category'] : null;
		$tag      = is_string( $query['tag'] ?? null ) && '' !== $query['tag'] ? $query['tag'] : null;

		return array_filter(
			array(
				'source'      => is_string( $query['source'] ?? null ) ? $query['source'] : 'recent',
				'category_id' => $category,
				'tag'         => $tag,
			),
			static fn ( $value ): bool => null !== $value
		);
	}

	/**
	 * Lists activity.
	 *
	 * Answers of a user are listed page by page, one item per answer, like on
	 * Question2Answer user pages; all other activity lists show the most recent
	 * items, one per question, without further pages.
	 *
	 * @param string               $source   Activity source, see ACTIVITY.
	 * @param array<string, mixed> $args     Query arguments.
	 * @param int                  $page     Requested page.
	 * @param int                  $per_page Items per page.
	 * @return array{0: list<Activity_Item>, 1: int, 2: int} Items, total and page.
	 */
	private static function activity( string $source, array $args, int $page, int $per_page ): array {
		$query   = Services::activity_query();
		$user_id = is_numeric( $args['user_id'] ?? null ) ? (int) $args['user_id'] : null;

		if ( 'recent_answers' === $source && null !== $user_id ) {
			$found = $query->by_user( 'answers', $user_id, $page, $per_page );
			$items = $found['items'];
			$total = $found['total'];
		} else {
			$filters = array_filter(
				array(
					'category_id' => $args['category_id'] ?? null,
					'user_id'     => $user_id,
				),
				static fn ( $value ): bool => null !== $value
			);

			$items = $query->recent( self::ACTIVITY[ $source ], $filters, $per_page );
			$total = count( $items );
			$page  = 1;
		}

		// Load all posts of the list at once; the blocks of each item read them from the cache.
		$query->posts_of( $items );

		return array( $items, $total, $page );
	}
}
