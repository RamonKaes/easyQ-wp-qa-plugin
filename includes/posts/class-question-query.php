<?php
/**
 * Question lists.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Posts;

use EasyQ\Categories\Categories;
use EasyQ\Database\DB;
use EasyQ\Database\Schema;
use EasyQ\Search\Basic_Search;

defined( 'ABSPATH' ) || exit;

/**
 * Lists visible questions, sorted and filtered like the Question2Answer question lists.
 *
 * Ported from the selectspecs in Question2Answer `qa-include/db/selects.php`.
 *
 * @since 0.1.0
 */
final class Question_Query {

	/**
	 * Sort orders and the column they sort by; newer questions come first on equal values.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string>
	 */
	private const ORDERS = array(
		'recent'  => 'created',
		'hot'     => 'hotness',
		'votes'   => 'net_votes',
		'answers' => 'answer_count',
		'views'   => 'views',
	);

	/**
	 * Lists of questions without answers, without a selected answer or without upvoted answers;
	 * their conditions are part of the query in find().
	 *
	 * @since 0.1.0
	 *
	 * @var string[]
	 */
	private const UNANSWERED = array( 'unanswered', 'unselected', 'unupvoted' );

	/**
	 * Largest page size.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const MAX_PER_PAGE = 100;

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
	 * Returns the available list sources.
	 *
	 * @since 0.1.0
	 *
	 * @return string[]
	 */
	public static function sources(): array {
		return array_merge( array_keys( self::ORDERS ), self::UNANSWERED );
	}

	/**
	 * Returns the posts column that filters by a category and its subcategories.
	 *
	 * Each depth has its own indexed column; the deepest level uses the category itself.
	 *
	 * @since 0.1.0
	 *
	 * @param Categories $categories  Categories.
	 * @param int        $category_id Category ID.
	 * @return string|null Column name, or null if the category does not exist.
	 * @phpstan-return literal-string|null
	 */
	public static function category_column( Categories $categories, int $category_id ): ?string {
		return match ( count( $categories->path( $category_id ) ) ) {
			0       => null,
			1       => 'category_path_1',
			2       => 'category_path_2',
			3       => 'category_path_3',
			default => 'category_id',
		};
	}

	/**
	 * Lists questions.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $args {
	 *     Query arguments.
	 *
	 *     @type string   $source      One of sources(); default `recent`.
	 *     @type int|null $category_id Only questions in this category or its subcategories.
	 *     @type string   $tag         Only questions with this tag.
	 *     @type int|null $user_id     Only questions by this user.
	 *     @type int      $page        Page number, starting at 1.
	 *     @type int      $per_page    Questions per page, at most MAX_PER_PAGE.
	 * }
	 * @return array{posts: Post[], total: int}
	 */
	public function find( array $args ): array {
		$source   = is_string( $args['source'] ?? null ) && in_array( $args['source'], self::sources(), true ) ? $args['source'] : 'recent';
		$per_page = max( 1, min( self::MAX_PER_PAGE, is_numeric( $args['per_page'] ?? null ) ? (int) $args['per_page'] : 20 ) );
		$page     = max( 1, is_numeric( $args['page'] ?? null ) ? (int) $args['page'] : 1 );

		$category_id = is_numeric( $args['category_id'] ?? null ) ? (int) $args['category_id'] : 0;
		$column      = 'category_id';
		if ( 0 !== $category_id ) {
			$column = self::category_column( $this->categories, $category_id );
			if ( null === $column ) {
				return array(
					'posts' => array(),
					'total' => 0,
				);
			}
		}

		$tag     = is_string( $args['tag'] ?? null ) ? (string) $args['tag'] : '';
		$word_id = 0;
		if ( '' !== $tag ) {
			$word_ids = ( new Basic_Search() )->word_ids( array( $tag ), create: false );
			if ( ! isset( $word_ids[ $tag ] ) ) {
				return array(
					'posts' => array(),
					'total' => 0,
				);
			}
			$word_id = $word_ids[ $tag ];
		}

		$user_id = is_numeric( $args['user_id'] ?? null ) ? (int) $args['user_id'] : 0;

		// One fixed statement for every list: each filter only applies when its value is set,
		// i.e. a source of UNANSWERED, or a category, tag or user ID other than 0.
		$wpdb  = DB::wpdb();
		$posts = Schema::table( 'posts' );
		$tags  = Schema::table( 'posttags' );
		$ids   = DB::int_col(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables without a WordPress API.
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT p.id FROM %i AS p WHERE p.type = 'Q'
						AND ( %s NOT IN ('unanswered', 'unselected', 'unupvoted') OR p.closed_by_id IS NULL )
						AND ( %s <> 'unanswered' OR p.answer_count = 0 )
						AND ( %s <> 'unselected' OR p.selected_child_id IS NULL )
						AND ( %s <> 'unupvoted' OR p.answer_max_votes = 0 )
						AND ( %d = 0 OR p.%i = %d )
						AND ( %d = 0 OR p.id IN (SELECT post_id FROM %i WHERE word_id = %d) )
						AND ( %d = 0 OR p.user_id = %d )
					ORDER BY p.%i DESC, p.created DESC, p.id DESC LIMIT %d, %d",
					$posts,
					$source,
					$source,
					$source,
					$source,
					$category_id,
					$column,
					$category_id,
					$word_id,
					$tags,
					$word_id,
					$user_id,
					$user_id,
					self::ORDERS[ $source ] ?? self::ORDERS['recent'],
					( $page - 1 ) * $per_page,
					$per_page
				)
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables without a WordPress API.
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i AS p WHERE p.type = 'Q'
					AND ( %s NOT IN ('unanswered', 'unselected', 'unupvoted') OR p.closed_by_id IS NULL )
					AND ( %s <> 'unanswered' OR p.answer_count = 0 )
					AND ( %s <> 'unselected' OR p.selected_child_id IS NULL )
					AND ( %s <> 'unupvoted' OR p.answer_max_votes = 0 )
					AND ( %d = 0 OR p.%i = %d )
					AND ( %d = 0 OR p.id IN (SELECT post_id FROM %i WHERE word_id = %d) )
					AND ( %d = 0 OR p.user_id = %d )",
				$posts,
				$source,
				$source,
				$source,
				$source,
				$category_id,
				$column,
				$category_id,
				$word_id,
				$tags,
				$word_id,
				$user_id,
				$user_id
			)
		);

		$found = $this->posts->find_many( $ids );
		$posts = array();
		foreach ( $ids as $id ) {
			if ( isset( $found[ $id ] ) ) {
				$posts[] = $found[ $id ];
			}
		}

		return array(
			'posts' => $posts,
			'total' => $total,
		);
	}
}
