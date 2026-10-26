<?php
/**
 * Question lists.
 *
 * @package easyQ
 */

namespace EasyQ\Posts;

use EasyQ\Categories\Categories;
use EasyQ\Database\Db;
use EasyQ\Database\Schema;
use EasyQ\Search\Basic_Search;

defined( 'ABSPATH' ) || exit;

/**
 * Lists visible questions, sorted and filtered like the Question2Answer question lists.
 *
 * Ported from the selectspecs in Question2Answer `qa-include/db/selects.php`.
 */
final class Question_Query {

	/**
	 * Sort orders and their SQL.
	 *
	 * @var array<string, string>
	 */
	private const ORDERS = array(
		'recent'  => 'p.created DESC, p.id DESC',
		'hot'     => 'p.hotness DESC, p.id DESC',
		'votes'   => 'p.net_votes DESC, p.created DESC, p.id DESC',
		'answers' => 'p.answer_count DESC, p.created DESC, p.id DESC',
		'views'   => 'p.views DESC, p.created DESC, p.id DESC',
	);

	/**
	 * Conditions of the unanswered lists.
	 *
	 * @var array<string, string>
	 */
	private const UNANSWERED = array(
		'unanswered' => 'p.answer_count = 0',
		'unselected' => 'p.selected_child_id IS NULL',
		'unupvoted'  => 'p.answer_max_votes = 0',
	);

	/**
	 * Largest page size.
	 *
	 * @var int
	 */
	public const MAX_PER_PAGE = 100;

	/**
	 * Sets up the query.
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
	 * @return string[]
	 */
	public static function sources(): array {
		return array_merge( array_keys( self::ORDERS ), array_keys( self::UNANSWERED ) );
	}

	/**
	 * Returns the posts column that filters by a category and its subcategories.
	 *
	 * Each depth has its own indexed column; the deepest level uses the category itself.
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

		$wpdb   = Db::wpdb();
		$where  = array( "p.type = 'Q'" );
		$params = array( Schema::table( 'posts' ) );

		if ( isset( self::UNANSWERED[ $source ] ) ) {
			$where[] = self::UNANSWERED[ $source ];
			$where[] = 'p.closed_by_id IS NULL';
		}

		$category_id = is_numeric( $args['category_id'] ?? null ) ? (int) $args['category_id'] : null;
		if ( null !== $category_id ) {
			$column = self::category_column( $this->categories, $category_id );
			if ( null === $column ) {
				return array(
					'posts' => array(),
					'total' => 0,
				);
			}

			$where[]  = 'p.' . $column . ' = %d';
			$params[] = $category_id;
		}

		$tag = is_string( $args['tag'] ?? null ) ? (string) $args['tag'] : '';
		if ( '' !== $tag ) {
			$word_ids = ( new Basic_Search() )->word_ids( array( $tag ), false );
			if ( ! isset( $word_ids[ $tag ] ) ) {
				return array(
					'posts' => array(),
					'total' => 0,
				);
			}

			$where[]  = 'p.id IN (SELECT post_id FROM %i WHERE word_id = %d)';
			$params[] = Schema::table( 'posttags' );
			$params[] = $word_ids[ $tag ];
		}

		$user_id = is_numeric( $args['user_id'] ?? null ) ? (int) $args['user_id'] : null;
		if ( null !== $user_id ) {
			$where[]  = 'p.user_id = %d';
			$params[] = $user_id;
		}

		$conditions = implode( ' AND ', $where );
		$order      = self::ORDERS[ $source ] ?? self::ORDERS['recent'];

		// The conditions and the order consist of fixed SQL with placeholders for the values.
		$ids = Db::get_int_col(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Fixed SQL with placeholders; the values are passed as an array.
			$wpdb->prepare( 'SELECT p.id FROM %i AS p WHERE ' . $conditions . ' ORDER BY ' . $order . ' LIMIT %d, %d', array_merge( $params, array( ( $page - 1 ) * $per_page, $per_page ) ) )
		);

		$total = Db::get_int(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Fixed SQL with placeholders; the values are passed as an array.
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i AS p WHERE ' . $conditions, $params )
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
