<?php
/**
 * Activity lists.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Posts;

use EasyQ\Categories\Categories;
use EasyQ\Database\DB;
use EasyQ\Database\Row;
use EasyQ\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Lists recent questions, answers, comments and changes, like the Question2Answer
 * activity, answers and comments pages and the activity sections of user profiles.
 *
 * Ported from the selectspecs in Question2Answer `qa-include/db/selects.php` and
 * `qa_any_sort_and_dedupe()` in `qa-include/app/format.php`.
 *
 * @since 0.1.0
 */
final class Activity_Query {

	/**
	 * Kinds of activity.
	 *
	 * @since 0.1.0
	 *
	 * @var string[]
	 */
	public const KINDS = array( 'questions', 'answers', 'comments', 'edits' );

	/**
	 * Events of the same post part that are this close (in seconds) count as one.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const CLOSE_EVENTS = 300;

	/**
	 * Sets up the query.
	 *
	 * @since 0.1.0
	 *
	 * @param Post_Repository $posts      Post storage.
	 * @param Categories      $categories Categories.
	 */
	public function __construct(
		private readonly Post_Repository $posts,
		private readonly Categories $categories
	) {
	}

	/**
	 * Lists the most recent activity, one item per question.
	 *
	 * @since 0.1.0
	 *
	 * @param string[]             $kinds   Kinds of activity to include, see KINDS.
	 * @param array<string, mixed> $filters Optional `category_id` and `user_id`.
	 * @param int                  $count   Maximum number of items.
	 * @return list<Activity_Item> Newest first.
	 */
	public function recent( array $kinds, array $filters, int $count ): array {
		$count = max( 1, min( Question_Query::MAX_PER_PAGE, $count ) );
		$items = array();

		foreach ( array_intersect( self::KINDS, $kinds ) as $kind ) {
			$items = array_merge( $items, $this->select( $kind, $filters, 0, $count ) );
		}

		return array_slice( self::sort_and_dedupe( $items ), 0, $count );
	}

	/**
	 * Lists answers or comments of a user page by page, one item per post.
	 *
	 * @since 0.1.0
	 *
	 * @param string $kind     `questions`, `answers` or `comments`.
	 * @param int    $user_id  Author.
	 * @param int    $page     Page number, starting at 1.
	 * @param int    $per_page Items per page.
	 * @return array{items: list<Activity_Item>, total: int}
	 */
	public function by_user( string $kind, int $user_id, int $page, int $per_page ): array {
		if ( ! in_array( $kind, array( 'questions', 'answers', 'comments' ), true ) ) {
			return array(
				'items' => array(),
				'total' => 0,
			);
		}

		$per_page = max( 1, min( Question_Query::MAX_PER_PAGE, $per_page ) );
		$filters  = array( 'user_id' => $user_id );

		return array(
			'items' => $this->select( $kind, $filters, ( max( 1, $page ) - 1 ) * $per_page, $per_page ),
			'total' => $this->count( $kind, $filters ),
		);
	}

	/**
	 * Sorts items newest first and keeps one item per question.
	 *
	 * Creating a post and editing it shortly after by the same user shows as the creation.
	 *
	 * @since 0.1.0
	 *
	 * @param Activity_Item[] $items Items.
	 * @return list<Activity_Item>
	 */
	public static function sort_and_dedupe( array $items ): array {
		usort( $items, static fn ( Activity_Item $a, Activity_Item $b ): int => array( $b->time, $b->post_id ) <=> array( $a->time, $a->post_id ) );

		$kept = array();
		foreach ( $items as $item ) {
			$later = $kept[ $item->question_id ] ?? null;
			if ( null === $later ) {
				$kept[ $item->question_id ] = $item;
				continue;
			}

			$close      = abs( strtotime( $later->time ) - strtotime( $item->time ) ) < self::CLOSE_EVENTS;
			$later_edit = null !== $later->update_type && null === $item->update_type
				&& $later->base_type === $item->base_type && $later->user_id === $item->user_id;

			if ( $close && $later_edit ) {
				$kept[ $item->question_id ] = $item;
			}
		}

		return array_values( $kept );
	}

	/**
	 * Selects activity of one kind. Only visible posts of visible questions are included.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $kind    Kind of activity, see KINDS.
	 * @param array<string, mixed> $filters Optional `category_id` and `user_id`.
	 * @param int                  $offset  Rows to skip.
	 * @param int                  $limit   Maximum number of rows.
	 * @return list<Activity_Item>
	 */
	private function select( string $kind, array $filters, int $offset, int $limit ): array {
		$values = $this->filter_values( $filters );
		if ( null === $values ) {
			return array();
		}

		[ $category_id, $column, $user_id ] = $values;

		$wpdb  = DB::wpdb();
		$posts = Schema::table( 'posts' );

		// One fixed statement per kind; the filters only apply to a category or user ID other than 0.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables without a WordPress API.
		$rows = DB::rows(
			match ( $kind ) {
				'questions' => $wpdb->get_results(
					$wpdb->prepare(
						"SELECT p.id AS post_id, p.id AS question_id, p.type, p.created AS time, NULL AS update_type, p.user_id FROM %i AS p WHERE p.type = 'Q'
							AND ( %d = 0 OR p.%i = %d ) AND ( %d = 0 OR p.user_id = %d )
						ORDER BY time DESC, post_id DESC LIMIT %d, %d",
						$posts,
						$category_id,
						$column,
						$category_id,
						$user_id,
						$user_id,
						$offset,
						$limit
					)
				),
				'answers'   => $wpdb->get_results(
					$wpdb->prepare(
						"SELECT p.id AS post_id, q.id AS question_id, p.type, p.created AS time, NULL AS update_type, p.user_id FROM %i AS p JOIN %i AS q ON q.id = p.parent_id WHERE p.type = 'A' AND q.type = 'Q'
							AND ( %d = 0 OR p.%i = %d ) AND ( %d = 0 OR p.user_id = %d )
						ORDER BY time DESC, post_id DESC LIMIT %d, %d",
						$posts,
						$posts,
						$category_id,
						$column,
						$category_id,
						$user_id,
						$user_id,
						$offset,
						$limit
					)
				),
				'comments'  => $wpdb->get_results(
					$wpdb->prepare(
						"SELECT p.id AS post_id, q.id AS question_id, p.type, p.created AS time, NULL AS update_type, p.user_id FROM %i AS p JOIN %i AS parent ON parent.id = p.parent_id JOIN %i AS q ON q.id = IF(parent.type = 'Q', parent.id, parent.parent_id) WHERE p.type = 'C' AND parent.type IN ('Q', 'A') AND q.type = 'Q'
							AND ( %d = 0 OR p.%i = %d ) AND ( %d = 0 OR p.user_id = %d )
						ORDER BY time DESC, post_id DESC LIMIT %d, %d",
						$posts,
						$posts,
						$posts,
						$category_id,
						$column,
						$category_id,
						$user_id,
						$user_id,
						$offset,
						$limit
					)
				),
				default     => $wpdb->get_results(
					$wpdb->prepare(
						"SELECT p.id AS post_id, q.id AS question_id, p.type, p.updated AS time, p.update_type, p.last_user_id AS user_id FROM %i AS p LEFT JOIN %i AS parent ON parent.id = p.parent_id JOIN %i AS q ON q.id = IF(p.type = 'Q', p.id, IF(parent.type = 'Q', parent.id, parent.parent_id)) WHERE p.updated IS NOT NULL AND p.type IN ('Q', 'A', 'C') AND (p.type = 'Q' OR parent.type IN ('Q', 'A')) AND q.type = 'Q'
							AND ( %d = 0 OR p.%i = %d ) AND ( %d = 0 OR p.last_user_id = %d )
						ORDER BY time DESC, post_id DESC LIMIT %d, %d",
						$posts,
						$posts,
						$posts,
						$category_id,
						$column,
						$category_id,
						$user_id,
						$user_id,
						$offset,
						$limit
					)
				),
			}
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$items = array();
		foreach ( $rows as $row ) {
			$items[] = new Activity_Item(
				Row::int( $row, 'question_id' ),
				Row::int( $row, 'post_id' ),
				substr( Row::string( $row, 'type' ), 0, 1 ),
				Row::string( $row, 'time' ),
				Row::nullable_string( $row, 'update_type' ),
				Row::nullable_int( $row, 'user_id' )
			);
		}

		return $items;
	}

	/**
	 * Counts activity of one kind, like select().
	 *
	 * @since 0.1.0
	 *
	 * @param string               $kind    Kind of activity, see KINDS.
	 * @param array<string, mixed> $filters Optional `category_id` and `user_id`.
	 * @return int
	 */
	private function count( string $kind, array $filters ): int {
		$values = $this->filter_values( $filters );
		if ( null === $values ) {
			return 0;
		}

		[ $category_id, $column, $user_id ] = $values;

		$wpdb  = DB::wpdb();
		$posts = Schema::table( 'posts' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables without a WordPress API.
		$count = match ( $kind ) {
			'questions' => $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM %i AS p WHERE p.type = 'Q'
						AND ( %d = 0 OR p.%i = %d ) AND ( %d = 0 OR p.user_id = %d )",
					$posts,
					$category_id,
					$column,
					$category_id,
					$user_id,
					$user_id
				)
			),
			'answers'   => $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM %i AS p JOIN %i AS q ON q.id = p.parent_id WHERE p.type = 'A' AND q.type = 'Q'
						AND ( %d = 0 OR p.%i = %d ) AND ( %d = 0 OR p.user_id = %d )",
					$posts,
					$posts,
					$category_id,
					$column,
					$category_id,
					$user_id,
					$user_id
				)
			),
			'comments'  => $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM %i AS p JOIN %i AS parent ON parent.id = p.parent_id JOIN %i AS q ON q.id = IF(parent.type = 'Q', parent.id, parent.parent_id) WHERE p.type = 'C' AND parent.type IN ('Q', 'A') AND q.type = 'Q'
						AND ( %d = 0 OR p.%i = %d ) AND ( %d = 0 OR p.user_id = %d )",
					$posts,
					$posts,
					$posts,
					$category_id,
					$column,
					$category_id,
					$user_id,
					$user_id
				)
			),
			default     => $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM %i AS p LEFT JOIN %i AS parent ON parent.id = p.parent_id JOIN %i AS q ON q.id = IF(p.type = 'Q', p.id, IF(parent.type = 'Q', parent.id, parent.parent_id)) WHERE p.updated IS NOT NULL AND p.type IN ('Q', 'A', 'C') AND (p.type = 'Q' OR parent.type IN ('Q', 'A')) AND q.type = 'Q'
						AND ( %d = 0 OR p.%i = %d ) AND ( %d = 0 OR p.last_user_id = %d )",
					$posts,
					$posts,
					$posts,
					$category_id,
					$column,
					$category_id,
					$user_id,
					$user_id
				)
			),
		};
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return (int) $count;
	}

	/**
	 * Returns the values of the filters for the queries: category ID, its posts column and user
	 * ID, with 0 for no filter.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $filters Optional `category_id` and `user_id`.
	 * @return array{0: int, 1: string, 2: int}|null Null if the category does not exist, so nothing matches.
	 */
	private function filter_values( array $filters ): ?array {
		$category_id = is_numeric( $filters['category_id'] ?? null ) ? (int) $filters['category_id'] : 0;
		$column      = 0 === $category_id ? 'category_id' : Question_Query::category_column( $this->categories, $category_id );
		if ( null === $column ) {
			return null;
		}

		return array( $category_id, $column, is_numeric( $filters['user_id'] ?? null ) ? (int) $filters['user_id'] : 0 );
	}

	/**
	 * Loads the questions and posts of activity items.
	 *
	 * @since 0.1.0
	 *
	 * @param Activity_Item[] $items Items.
	 * @return array<int, Post> Posts keyed by ID.
	 */
	public function posts_of( array $items ): array {
		$ids = array();
		foreach ( $items as $item ) {
			$ids[] = $item->question_id;
			$ids[] = $item->post_id;
		}

		return $this->posts->find_many( array_values( array_unique( $ids ) ) );
	}
}
