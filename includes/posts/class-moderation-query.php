<?php
/**
 * Moderation lists.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Posts;

use EasyQ\Actor;
use EasyQ\Categories\Categories;
use EasyQ\Database\DB;
use EasyQ\Database\Schema;
use EasyQ\Users\Permissions;
use EasyQ\Util\IP;

defined( 'ABSPATH' ) || exit;

/**
 * Lists questions, answers and comments for moderators: all of them, or those
 * awaiting approval, flagged, hidden or closed.
 *
 * Replaces the Question2Answer pages `admin/moderate`, `admin/flagged` and
 * `admin/hidden`, and the post list of `ip/{ip}`. Posts awaiting approval and
 * hidden posts are only listed where the actor may handle them, as Question2Answer
 * checks for each post; with category levels, that is the categories where the
 * actor has a high enough level.
 *
 * @since 0.1.0
 */
final class Moderation_Query {

	/**
	 * Available views.
	 *
	 * @since 0.1.0
	 *
	 * @var string[]
	 */
	public const VIEWS = array( 'all', 'pending', 'flagged', 'hidden', 'closed' );

	/**
	 * Post types by name, each with its states.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string>
	 */
	public const TYPES = array(
		'question' => Post::QUESTION,
		'answer'   => Post::ANSWER,
		'comment'  => Post::COMMENT,
	);

	/**
	 * Most posts per page.
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
	 * @param Post_Repository $posts       Post storage.
	 * @param Categories      $categories  Categories.
	 * @param Permissions     $permissions Permission checks.
	 */
	public function __construct(
		private readonly Post_Repository $posts,
		private readonly Categories $categories,
		private readonly Permissions $permissions
	) {
	}

	/**
	 * Lists posts, newest first; flagged posts with the most flags first.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor                $actor The moderator.
	 * @param array<string, mixed> $args {
	 *     Query arguments.
	 *
	 *     @type string      $view        One of VIEWS; default `all`.
	 *     @type string      $type        A key of TYPES, or empty for all types.
	 *     @type int|null    $category_id Only posts in this category or its subcategories.
	 *     @type int|null    $user_id     Only posts by this user.
	 *     @type string|null $ip          Only posts created from this IP address.
	 *     @type string      $search      Only posts whose title or text contains this.
	 *     @type string      $month       Only posts created in this month, as `YYYYMM`.
	 *     @type int         $page        Page number, starting at 1.
	 *     @type int         $per_page    Posts per page, at most MAX_PER_PAGE.
	 * }
	 * @return array{posts: Post[], total: int}
	 */
	public function find( Actor $actor, array $args ): array {
		$wpdb     = DB::wpdb();
		$view     = self::view( $args );
		$page     = max( 1, is_int( $args['page'] ?? null ) ? $args['page'] : 1 );
		$per_page = max( 1, min( self::MAX_PER_PAGE, is_int( $args['per_page'] ?? null ) ? $args['per_page'] : 20 ) );
		$v        = $this->values( $actor, $view, $args );

		$ids = DB::int_col(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables without a WordPress API.
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT id FROM %i
					WHERE (
						( %d = 1 AND type IN ('Q', 'A', 'C') AND ( %d = 0 OR flag_count > 0 ) AND ( %s = '*' OR FIND_IN_SET(category_id, %s) ) )
						OR ( %d = 1 AND type IN ('Q_QUEUED', 'A_QUEUED', 'C_QUEUED') AND ( %s = '*' OR FIND_IN_SET(category_id, %s) ) )
						OR ( %d = 1 AND type IN ('Q_HIDDEN', 'A_HIDDEN', 'C_HIDDEN') AND ( %s = '*' OR FIND_IN_SET(category_id, %s) ) )
						OR ( %d = 1 AND type = 'Q' AND closed_by_id IS NOT NULL )
					)
					AND ( %s = '' OR LEFT(type, 1) = %s )
					AND ( %d = 0 OR %d IN (category_id, category_path_1, category_path_2, category_path_3) )
					AND ( %d = 0 OR user_id = %d )
					AND ( %d = 0 OR created_ip = %s )
					AND ( %s = '' OR title LIKE %s OR content LIKE %s )
					AND ( %s = '' OR DATE_FORMAT(created, '%%Y%%m') = %s )
					ORDER BY CASE WHEN %d = 1 THEN flag_count ELSE 0 END DESC, created DESC, id DESC LIMIT %d, %d",
					Schema::table( 'posts' ),
					$v['visible'],
					$v['flagged'],
					$v['visible_scope'],
					$v['visible_scope'],
					$v['queued'],
					$v['queued_scope'],
					$v['queued_scope'],
					$v['hidden'],
					$v['hidden_scope'],
					$v['hidden_scope'],
					$v['closed'],
					$v['type'],
					$v['type'],
					$v['category'],
					$v['category'],
					$v['user'],
					$v['user'],
					$v['ip_set'],
					$v['ip'],
					$v['search'],
					$v['like'],
					$v['like'],
					$v['month'],
					$v['month'],
					$v['flagged'],
					( $page - 1 ) * $per_page,
					$per_page
				)
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
			'total' => $this->total( $v ),
		);
	}

	/**
	 * Counts the posts in each view that the actor may see, without further filters.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor $actor The moderator.
	 * @return array<string, int> Count by view.
	 */
	public function counts( Actor $actor ): array {
		$counts = array();
		foreach ( self::VIEWS as $view ) {
			$counts[ $view ] = $this->count( $actor, $view );
		}

		return $counts;
	}

	/**
	 * Counts the posts in a view that the actor may see.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor  $actor The moderator.
	 * @param string $view  One of VIEWS.
	 * @return int
	 */
	public function count( Actor $actor, string $view ): int {
		return $this->total( $this->values( $actor, self::view( array( 'view' => $view ) ), array() ) );
	}

	/**
	 * Returns the months in which posts were created, newest first.
	 *
	 * @since 0.1.0
	 *
	 * @return string[] Months as `YYYYMM`.
	 */
	public function months(): array {
		$wpdb = DB::wpdb();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables without a WordPress API.
		$months = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT DATE_FORMAT(created, '%%Y%%m') AS month FROM %i WHERE type <> %s ORDER BY month DESC", Schema::table( 'posts' ), Post::NOTE ) );

		return array_values( array_filter( $months, static fn ( mixed $month ): bool => is_string( $month ) ) );
	}

	/**
	 * Returns the view of the query arguments.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return string One of VIEWS.
	 */
	private static function view( array $args ): string {
		return is_string( $args['view'] ?? null ) && in_array( $args['view'], self::VIEWS, true ) ? $args['view'] : 'all';
	}

	/**
	 * Counts the posts that match the conditions.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, int|string> $v Values of the conditions, see values().
	 * @return int
	 */
	private function total( array $v ): int {
		$wpdb = DB::wpdb();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables without a WordPress API.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i
				WHERE (
					( %d = 1 AND type IN ('Q', 'A', 'C') AND ( %d = 0 OR flag_count > 0 ) AND ( %s = '*' OR FIND_IN_SET(category_id, %s) ) )
					OR ( %d = 1 AND type IN ('Q_QUEUED', 'A_QUEUED', 'C_QUEUED') AND ( %s = '*' OR FIND_IN_SET(category_id, %s) ) )
					OR ( %d = 1 AND type IN ('Q_HIDDEN', 'A_HIDDEN', 'C_HIDDEN') AND ( %s = '*' OR FIND_IN_SET(category_id, %s) ) )
					OR ( %d = 1 AND type = 'Q' AND closed_by_id IS NOT NULL )
				)
				AND ( %s = '' OR LEFT(type, 1) = %s )
				AND ( %d = 0 OR %d IN (category_id, category_path_1, category_path_2, category_path_3) )
				AND ( %d = 0 OR user_id = %d )
				AND ( %d = 0 OR created_ip = %s )
				AND ( %s = '' OR title LIKE %s OR content LIKE %s )
				AND ( %s = '' OR DATE_FORMAT(created, '%%Y%%m') = %s )",
				Schema::table( 'posts' ),
				$v['visible'],
				$v['flagged'],
				$v['visible_scope'],
				$v['visible_scope'],
				$v['queued'],
				$v['queued_scope'],
				$v['queued_scope'],
				$v['hidden'],
				$v['hidden_scope'],
				$v['hidden_scope'],
				$v['closed'],
				$v['type'],
				$v['type'],
				$v['category'],
				$v['category'],
				$v['user'],
				$v['user'],
				$v['ip_set'],
				$v['ip'],
				$v['search'],
				$v['like'],
				$v['like'],
				$v['month'],
				$v['month']
			)
		);
	}

	/**
	 * Returns the values of the query conditions.
	 *
	 * Each state has a switch and the categories it is limited to, as category IDs
	 * separated by commas, or `*` for all; each filter is skipped if its value is empty.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor                $actor The moderator.
	 * @param string               $view  One of VIEWS.
	 * @param array<string, mixed> $args  Filters, see find().
	 * @return array<string, int|string>
	 */
	private function values( Actor $actor, string $view, array $args ): array {
		$ip_arg  = is_string( $args['ip'] ?? null ) ? $args['ip'] : null;
		$ip      = null === $ip_arg ? null : IP::to_binary( $ip_arg );
		$search  = is_string( $args['search'] ?? null ) ? trim( $args['search'] ) : '';
		$month   = is_string( $args['month'] ?? null ) && 1 === preg_match( '/^\d{6}$/', $args['month'] ) ? $args['month'] : '';
		$type    = is_string( $args['type'] ?? null ) ? ( self::TYPES[ $args['type'] ] ?? '' ) : '';
		$flagged = 'flagged' === $view;

		return array(
			// Visible posts: all of them, or the flagged ones where the actor may hide them.
			'visible'       => 'all' === $view || $flagged ? 1 : 0,
			'flagged'       => $flagged ? 1 : 0,
			'visible_scope' => $flagged ? $this->scope( $actor, array( 'permit_hide_show' ) ) : '*',
			'queued'        => 'all' === $view || 'pending' === $view ? 1 : 0,
			'queued_scope'  => $this->scope( $actor, array( 'permit_moderate' ) ),
			'hidden'        => 'all' === $view || 'hidden' === $view ? 1 : 0,
			'hidden_scope'  => $this->scope( $actor, array( 'permit_hide_show', 'permit_delete_hidden' ) ),
			'closed'        => 'closed' === $view ? 1 : 0,
			'type'          => $type,
			'category'      => is_int( $args['category_id'] ?? null ) ? $args['category_id'] : 0,
			'user'          => is_int( $args['user_id'] ?? null ) ? $args['user_id'] : 0,
			// An IP address that cannot be stored matches nothing.
			'ip_set'        => null === $ip_arg ? 0 : 1,
			'ip'            => (string) $ip,
			'search'        => $search,
			'like'          => '%' . DB::wpdb()->esc_like( $search ) . '%',
			'month'         => $month,
		);
	}

	/**
	 * Returns where an actor may act on posts with one of several permissions.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor    $actor   The actor.
	 * @param string[] $options Permission settings; any of them suffices.
	 * @return string `*` for everywhere; otherwise the IDs of the categories and their subcategories, separated by commas, possibly none.
	 */
	private function scope( Actor $actor, array $options ): string {
		$category_ids = array();
		foreach ( $options as $option ) {
			$scope = $this->permissions->category_scope( $option, $actor );
			if ( null === $scope ) {
				return '*';
			}
			foreach ( $scope as $category_id ) {
				$category_ids = array_merge( $category_ids, $this->categories->subtree_ids( $category_id ) );
			}
		}

		return implode( ',', array_unique( $category_ids ) );
	}
}
