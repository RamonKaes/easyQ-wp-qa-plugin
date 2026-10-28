<?php
/**
 * Category management.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Categories;

use EasyQ\Database\DB;
use EasyQ\Database\Schema;
use EasyQ\Posts\Post;
use EasyQ\Posts\Post_Repository;
use EasyQ\Search\Search;
use EasyQ\Settings\Options;
use EasyQ\Util\Text;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Creates, changes, orders and deletes categories and keeps the related cached values up to date.
 *
 * Categories form a tree of up to four levels. Every post stores its category and
 * the ancestors of that category, and every category caches the number of visible
 * questions in it and its subcategories.
 *
 * Ported from Question2Answer `qa-include/db/admin.php` and `pages/admin/admin-categories.php`.
 * Unlike Question2Answer, posts and counts are updated right away instead of by
 * a separate recalculation step.
 *
 * @since 0.1.0
 */
final class Categories {

	/**
	 * Maximum depth of the category tree.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const MAX_DEPTH = 4;

	/**
	 * Longest category name in characters.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const MAX_TITLE_LENGTH = 80;

	/**
	 * Longest slug in characters.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const MAX_SLUG_LENGTH = 200;

	/**
	 * Longest description in characters.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const MAX_DESCRIPTION_LENGTH = 800;

	/**
	 * Object cache group.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const CACHE_GROUP = 'easyq_categories';

	/**
	 * Sets up the service.
	 *
	 * @since 0.1.0
	 *
	 * @param Post_Repository $posts  Post storage.
	 * @param Search          $search Search providers, told when posts change category.
	 */
	public function __construct( private readonly Post_Repository $posts, private readonly Search $search ) {
	}

	/**
	 * Returns a category.
	 *
	 * @since 0.1.0
	 *
	 * @param int $id Category ID.
	 * @return Category|null
	 */
	public function find( int $id ): ?Category {
		return $this->all()[ $id ] ?? null;
	}

	/**
	 * Returns all categories ordered by parent and position.
	 *
	 * @since 0.1.0
	 *
	 * @return array<int, Category> Categories keyed by ID.
	 */
	public function all(): array {
		$wpdb   = DB::wpdb();
		$cached = wp_cache_get( 'all', self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			$categories = array();
			foreach ( $cached as $category ) {
				if ( $category instanceof Category ) {
					$categories[ $category->id ] = $category;
				}
			}

			return $categories;
		}

		$rows = DB::rows(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables without a WordPress API.
			$wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM %i ORDER BY parent_id, position', Schema::table( 'categories' ) )
			)
		);

		$categories = array();
		foreach ( $rows as $row ) {
			$category                    = Category::from_row( $row );
			$categories[ $category->id ] = $category;
		}

		wp_cache_set( 'all', $categories, self::CACHE_GROUP );

		return $categories;
	}

	/**
	 * Returns the children of a category in order.
	 *
	 * @since 0.1.0
	 *
	 * @param int|null $parent_id Parent category ID, or null for the top level.
	 * @return Category[]
	 */
	public function children( ?int $parent_id ): array {
		$children = array_filter( $this->all(), static fn ( Category $category ): bool => $category->parent_id === $parent_id );
		usort( $children, static fn ( Category $a, Category $b ): int => $a->position <=> $b->position );

		return $children;
	}

	/**
	 * Returns all categories depth first, each with its depth.
	 *
	 * @since 0.1.0
	 *
	 * @param int|null $parent_id Category whose descendants to return, or null for all categories.
	 * @param int      $depth     Depth of the children of the parent; 0 for the top level.
	 * @return array<int, array{0: Category, 1: int}>
	 */
	public function tree( ?int $parent_id = null, int $depth = 0 ): array {
		$tree = array();
		foreach ( $this->children( $parent_id ) as $category ) {
			$tree[] = array( $category, $depth );
			$tree   = array_merge( $tree, $this->tree( $category->id, $depth + 1 ) );
		}

		return $tree;
	}

	/**
	 * Returns the categories that can be the parent of a category.
	 *
	 * Leaves out the category itself, its subcategories, and categories so deep that
	 * the category or one of its subcategories would be more than four levels down.
	 *
	 * @since 0.1.0
	 *
	 * @param int|null $id Category ID, or null for a new category.
	 * @return array<int, array{0: Category, 1: int}> Categories depth first, each with its depth.
	 */
	public function possible_parents( ?int $id ): array {
		$excluded = null === $id ? array() : $this->subtree_ids( $id );
		$height   = null === $id ? 1 : $this->height( $id );

		return array_values(
			array_filter(
				$this->tree(),
				static fn ( array $item ): bool => ! in_array( $item[0]->id, $excluded, true ) && $item[1] + 1 + $height <= self::MAX_DEPTH
			)
		);
	}

	/**
	 * Returns a category and its ancestors, from the top level down.
	 *
	 * @since 0.1.0
	 *
	 * @param int $id Category ID.
	 * @return Category[] Empty if the category does not exist.
	 */
	public function path( int $id ): array {
		$all  = $this->all();
		$path = array();

		$depth = 0;

		while ( isset( $all[ $id ] ) && $depth < self::MAX_DEPTH ) {
			array_unshift( $path, $all[ $id ] );
			++$depth;
			if ( null === $all[ $id ]->parent_id ) {
				break;
			}
			$id = $all[ $id ]->parent_id;
		}

		return $path;
	}

	/**
	 * Returns the IDs of a category and all categories below it.
	 *
	 * @since 0.1.0
	 *
	 * @param int $id Category ID.
	 * @return int[]
	 */
	public function subtree_ids( int $id ): array {
		$ids = array( $id );
		foreach ( $this->children( $id ) as $child ) {
			$ids = array_merge( $ids, $this->subtree_ids( $child->id ) );
		}

		return $ids;
	}

	/**
	 * Returns the category with the given slugs from the top level down.
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $slugs Slugs, e.g. `array( 'software', 'WordPress' )`.
	 * @return Category|null
	 */
	public function find_by_slugs( array $slugs ): ?Category {
		$backpath = implode( '/', array_reverse( $slugs ) );

		foreach ( $this->all() as $category ) {
			if ( $category->backpath === $backpath ) {
				return $category;
			}
		}

		return null;
	}

	/**
	 * Creates a category.
	 *
	 * Without a slug, the slug is derived from the name; if that one cannot be used,
	 * `category-{slug}` and then `category-1`, `category-2`, … are tried, as in Question2Answer.
	 *
	 * @since 0.1.0
	 *
	 * @param int|null $parent_id   Parent category ID, or null for the top level.
	 * @param string   $title       Name.
	 * @param string   $slug        Slug; derived from the name if empty.
	 * @param string   $description Description.
	 * @param int|null $position    Position among the siblings, starting at 1; null for the end.
	 * @return int|WP_Error The new category ID, or the validation errors.
	 */
	public function create( ?int $parent_id, string $title, string $slug = '', string $description = '', ?int $position = null ): int|WP_Error {
		$title = trim( $title );
		$slug  = trim( $slug );
		if ( '' === $slug ) {
			$slug = $this->free_slug( $parent_id, sanitize_title( $title ) );
		}

		$errors = $this->validate( null, $parent_id, $title, $slug, $description );
		if ( null !== $parent_id && count( $this->path( $parent_id ) ) >= self::MAX_DEPTH ) {
			$errors->add( 'parent', __( 'Categories can be nested up to four levels deep.', 'easyq' ) );
		}
		if ( $errors->has_errors() ) {
			return $errors;
		}

		$id = DB::insert(
			Schema::table( 'categories' ),
			array(
				'parent_id'   => $parent_id,
				'title'       => $title,
				'slug'        => $slug,
				'description' => $description,
				'position'    => $this->last_position( $parent_id ) + 1,
			),
			array( '%d', '%s', '%s', '%s', '%d' )
		);

		$this->recalc_backpaths( array( $id ) );
		$this->forget();
		if ( null !== $position ) {
			$this->set_position( $id, $position );
		}

		return $id;
	}

	/**
	 * Changes name, slug and description of a category.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $id          Category ID.
	 * @param string $title       Name.
	 * @param string $slug        Slug; derived from the name if empty.
	 * @param string $description Description.
	 * @return true|WP_Error
	 */
	public function update( int $id, string $title, string $slug = '', string $description = '' ): bool|WP_Error {
		$category = $this->find( $id );
		if ( null === $category ) {
			return new WP_Error( 'not_found', __( 'The category does not exist.', 'easyq' ) );
		}

		return $this->save( $id, $title, $slug, $description, $category->parent_id );
	}

	/**
	 * Moves a category to another parent, at the end of its new siblings.
	 *
	 * @since 0.1.0
	 *
	 * @param int      $id            Category ID.
	 * @param int|null $new_parent_id New parent category ID, or null for the top level.
	 * @return true|WP_Error
	 */
	public function move( int $id, ?int $new_parent_id ): bool|WP_Error {
		$category = $this->find( $id );
		if ( null === $category ) {
			return new WP_Error( 'not_found', __( 'The category does not exist.', 'easyq' ) );
		}

		return $this->save( $id, $category->title, $category->slug, $category->description, $new_parent_id );
	}

	/**
	 * Changes a category: name, slug, description, parent and position.
	 *
	 * All changes are checked before any is stored. A category that gets another
	 * parent goes to the end of its new siblings unless a position is given; posts in
	 * it and its subcategories get their new category path, and the question counts
	 * of all affected categories are recalculated.
	 *
	 * @since 0.1.0
	 *
	 * @param int      $id          Category ID.
	 * @param string   $title       Name.
	 * @param string   $slug        Slug; derived from the name if empty.
	 * @param string   $description Description.
	 * @param int|null $parent_id   Parent category ID, or null for the top level.
	 * @param int|null $position    Position among the siblings, starting at 1; null to keep it.
	 * @return true|WP_Error
	 */
	public function save( int $id, string $title, string $slug, string $description, ?int $parent_id, ?int $position = null ): bool|WP_Error {
		$category = $this->find( $id );
		if ( null === $category ) {
			return new WP_Error( 'not_found', __( 'The category does not exist.', 'easyq' ) );
		}

		$title  = trim( $title );
		$slug   = '' === trim( $slug ) ? sanitize_title( $title ) : trim( $slug );
		$moves  = $parent_id !== $category->parent_id;
		$errors = $this->validate( $id, $parent_id, $title, $slug, $description );
		if ( $moves && ! $errors->get_error_message( 'parent' ) ) {
			if ( null !== $parent_id && in_array( $parent_id, $this->subtree_ids( $id ), true ) ) {
				$errors->add( 'parent', __( 'A category cannot be moved into itself or one of its subcategories.', 'easyq' ) );
			} elseif ( ( null === $parent_id ? 0 : count( $this->path( $parent_id ) ) ) + $this->height( $id ) > self::MAX_DEPTH ) {
				$errors->add( 'parent', __( 'Categories can be nested up to four levels deep.', 'easyq' ) );
			}
		}
		if ( $errors->has_errors() ) {
			return $errors;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table; the cache is cleared below.
		DB::wpdb()->update(
			Schema::table( 'categories' ),
			array(
				'title'       => $title,
				'slug'        => $slug,
				'description' => $description,
			),
			array( 'id' => $id )
		);
		$this->forget();

		if ( $moves ) {
			$this->change_parent( $id, $parent_id );
		} elseif ( $slug !== $category->slug ) {
			$this->recalc_backpaths( $this->subtree_ids( $id ) );
		}

		if ( null !== $position ) {
			$this->set_position( $id, $position );
		}

		return true;
	}

	/**
	 * Moves a category to a position among its siblings.
	 *
	 * @since 0.1.0
	 *
	 * @param int $id       Category ID.
	 * @param int $position New position, starting at 1; values out of range are clamped.
	 */
	public function set_position( int $id, int $position ): void {
		$category = $this->find( $id );
		if ( null === $category ) {
			return;
		}

		$siblings = $this->children( $category->parent_id );
		$ids      = array_values( array_filter( array_map( static fn ( Category $sibling ): int => $sibling->id, $siblings ), static fn ( int $sibling_id ): bool => $sibling_id !== $id ) );
		$position = max( 1, min( $position, count( $siblings ) ) );

		array_splice( $ids, $position - 1, 0, array( $id ) );
		$this->write_positions( $ids );
	}

	/**
	 * Deletes a category without subcategories.
	 *
	 * @since 0.1.0
	 *
	 * @param int      $id          Category ID.
	 * @param int|null $reassign_to Category that receives the posts, or null to leave them without a category.
	 * @return true|WP_Error
	 */
	public function delete( int $id, ?int $reassign_to = null ): bool|WP_Error {
		$wpdb     = DB::wpdb();
		$category = $this->find( $id );
		if ( null === $category ) {
			return new WP_Error( 'not_found', __( 'The category does not exist.', 'easyq' ) );
		}

		if ( array() !== $this->children( $id ) ) {
			return new WP_Error( 'children', __( 'Delete or move the subcategories first.', 'easyq' ) );
		}

		if ( null !== $reassign_to && ( $reassign_to === $id || null === $this->find( $reassign_to ) ) ) {
			return new WP_Error( 'reassign', __( 'The posts cannot be moved to this category.', 'easyq' ) );
		}

		$ancestors = array_map( static fn ( Category $ancestor ): int => $ancestor->id, $this->path( $id ) );
		$this->reassign_posts( $id, $reassign_to );

		$this->set_position( $id, PHP_INT_MAX );
		Schema::delete_all_meta( 'easyq_category', $id );
		$sql = $wpdb->prepare( 'DELETE FROM %i WHERE id = %d', Schema::table( 'categories' ), $id );
		if ( null !== $sql ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; custom tables without a WordPress API.
			$wpdb->query( $sql );
		}
		$this->forget();

		$targets = null === $reassign_to ? array() : array_map( static fn ( Category $ancestor ): int => $ancestor->id, $this->path( $reassign_to ) );
		$this->recount( array_diff( array_unique( array_merge( $ancestors, $targets ) ), array( $id ) ) );

		return true;
	}

	/**
	 * Moves the questions of a category, with their answers and comments, to another category.
	 *
	 * Only posts directly in the category move, not those in its subcategories, as in
	 * Question2Answer. Used to give questions a category or subcategory they lack.
	 *
	 * @since 0.1.0
	 *
	 * @param int|null $id          Category ID, or null for posts without a category.
	 * @param int|null $reassign_to Target category ID, or null for no category.
	 * @return true|WP_Error
	 */
	public function reassign( ?int $id, ?int $reassign_to ): bool|WP_Error {
		if ( null !== $id && null === $this->find( $id ) ) {
			return new WP_Error( 'not_found', __( 'The category does not exist.', 'easyq' ) );
		}

		if ( $reassign_to === $id || ( null !== $reassign_to && null === $this->find( $reassign_to ) ) ) {
			return new WP_Error( 'reassign', __( 'The posts cannot be moved to this category.', 'easyq' ) );
		}

		$this->reassign_posts( $id, $reassign_to );
		$this->recount( array_merge( $this->path_ids( $id ), $this->path_ids( $reassign_to ) ) );

		return true;
	}

	/**
	 * Moves all posts of a category to another category, without recounting.
	 *
	 * @since 0.1.0
	 *
	 * @param int|null $id          Category ID, or null for posts without a category.
	 * @param int|null $reassign_to Target category ID, or null for no category.
	 */
	public function reassign_posts( ?int $id, ?int $reassign_to ): void {
		$wpdb  = DB::wpdb();
		$table = Schema::table( 'posts' );

		if ( null === $id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables without a WordPress API.
			$post_ids = DB::int_col( $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE category_id IS NULL ORDER BY id', $table ) ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables without a WordPress API.
			$post_ids = DB::int_col( $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE category_id = %d ORDER BY id', $table, $id ) ) );
		}

		foreach ( $post_ids as $post_id ) {
			$this->posts->update( $post_id, array( 'category_id' => $reassign_to ) );
			$this->posts->calc_category_path( $post_id );
		}

		$this->search->move( $post_ids, $reassign_to );
	}

	/**
	 * Returns the number of questions directly in a category, not counting its subcategories.
	 *
	 * @since 0.1.0
	 *
	 * @param int|null $id Category ID, or null for questions without a category.
	 * @return int
	 */
	public function count_direct_questions( ?int $id ): int {
		$wpdb  = DB::wpdb();
		$table = Schema::table( 'posts' );

		if ( null === $id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables without a WordPress API.
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE category_id IS NULL AND type = 'Q'", $table ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables without a WordPress API.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE category_id = %d AND type = 'Q'", $table, $id ) );
	}

	/**
	 * Checks the category chosen for a question against the category settings.
	 *
	 * Unless allowed, a question needs a category once categories exist, and that
	 * category must not have subcategories. A question that keeps its category is
	 * always accepted, as is a question without a category that stays without one.
	 *
	 * @since 0.1.0
	 *
	 * @param int|null  $category_id Chosen category ID, or null for none.
	 * @param Post|null $question    Question being edited, or null for a new question.
	 * @return WP_Error|null Error with the code `category`, or null if the choice is valid.
	 */
	public function check_choice( ?int $category_id, ?Post $question = null ): ?WP_Error {
		if ( null !== $question && $category_id === $question->category_id ) {
			return null;
		}

		if ( null === $category_id ) {
			if ( array() !== $this->all() && ! Options::get_bool( 'posting', 'allow_no_category' ) ) {
				return new WP_Error( 'category', __( 'Please choose a category.', 'easyq' ) );
			}

			return null;
		}

		if ( null === $this->find( $category_id ) ) {
			return new WP_Error( 'category', __( 'The category does not exist.', 'easyq' ) );
		}

		if ( array() !== $this->children( $category_id ) && ! Options::get_bool( 'posting', 'allow_no_sub_category' ) ) {
			return new WP_Error( 'category', __( 'Please choose a subcategory.', 'easyq' ) );
		}

		return null;
	}

	/**
	 * Recalculates the number of visible questions in categories, including their subcategories.
	 *
	 * @since 0.1.0
	 *
	 * @param int[] $ids Category IDs.
	 */
	public function recount( array $ids ): void {
		$wpdb       = DB::wpdb();
		$categories = Schema::table( 'categories' );
		$posts      = Schema::table( 'posts' );

		foreach ( array_unique( array_filter( $ids ) ) as $id ) {
			$sql = $wpdb->prepare(
				"UPDATE %i SET question_count = GREATEST((SELECT COUNT(*) FROM %i WHERE category_id = %d AND type = 'Q'), (SELECT COUNT(*) FROM %i WHERE category_path_1 = %d AND type = 'Q'), (SELECT COUNT(*) FROM %i WHERE category_path_2 = %d AND type = 'Q'), (SELECT COUNT(*) FROM %i WHERE category_path_3 = %d AND type = 'Q')) WHERE id = %d",
				$categories,
				$posts,
				$id,
				$posts,
				$id,
				$posts,
				$id,
				$posts,
				$id,
				$id
			);
			if ( null !== $sql ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; custom tables without a WordPress API.
				$wpdb->query( $sql );
			}
		}

		$this->forget();
	}

	/**
	 * Recalculates the backpaths of categories.
	 *
	 * @since 0.1.0
	 *
	 * @param int[] $ids Category IDs.
	 */
	public function recalc_backpaths( array $ids ): void {
		$wpdb  = DB::wpdb();
		$table = Schema::table( 'categories' );

		foreach ( $ids as $id ) {
			$sql = $wpdb->prepare(
				"UPDATE %i AS x, (SELECT c1.id, CONCAT_WS('/', c1.slug, c2.slug, c3.slug, c4.slug) AS backpath FROM %i AS c1 LEFT JOIN %i AS c2 ON c1.parent_id = c2.id LEFT JOIN %i AS c3 ON c2.parent_id = c3.id LEFT JOIN %i AS c4 ON c3.parent_id = c4.id WHERE c1.id = %d) AS a SET x.backpath = a.backpath WHERE x.id = a.id",
				$table,
				$table,
				$table,
				$table,
				$table,
				$id
			);
			if ( null !== $sql ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; custom tables without a WordPress API.
				$wpdb->query( $sql );
			}
		}

		$this->forget();
	}

	/**
	 * Clears the category cache.
	 *
	 * @since 0.1.0
	 */
	public function forget(): void {
		wp_cache_delete( 'all', self::CACHE_GROUP );
	}

	/**
	 * Validates the fields of a category.
	 *
	 * @since 0.1.0
	 *
	 * @param int|null $id          Category ID, or null for a new category.
	 * @param int|null $parent_id   Parent category ID.
	 * @param string   $title       Name.
	 * @param string   $slug        Slug.
	 * @param string   $description Description.
	 * @return WP_Error Errors keyed by field; empty if the fields are valid.
	 */
	private function validate( ?int $id, ?int $parent_id, string $title, string $slug, string $description ): WP_Error {
		$errors = new WP_Error();

		if ( null !== $parent_id && null === $this->find( $parent_id ) ) {
			$errors->add( 'parent', __( 'The parent category does not exist.', 'easyq' ) );
		}

		if ( '' === $title ) {
			$errors->add( 'title', __( 'Please enter a name.', 'easyq' ) );
		} elseif ( Text::length( $title ) > self::MAX_TITLE_LENGTH ) {
			/* translators: %d: maximum number of characters. */
			$errors->add( 'title', sprintf( __( 'The name can have at most %d characters.', 'easyq' ), self::MAX_TITLE_LENGTH ) );
		} else {
			foreach ( $this->children( $parent_id ) as $sibling ) {
				if ( $sibling->id !== $id && Text::lowercase( $sibling->title ) === Text::lowercase( $title ) ) {
					$errors->add( 'title', __( 'Another category with this name already exists here.', 'easyq' ) );
				}
			}
		}

		$slug_error = $this->slug_error( $id, $parent_id, $slug );
		if ( null !== $slug_error ) {
			$errors->add( 'slug', $slug_error );
		}

		if ( Text::length( $description ) > self::MAX_DESCRIPTION_LENGTH ) {
			/* translators: %d: maximum number of characters. */
			$errors->add( 'description', sprintf( __( 'The description can have at most %d characters.', 'easyq' ), self::MAX_DESCRIPTION_LENGTH ) );
		}

		return $errors;
	}

	/**
	 * Returns the IDs of a category and its ancestors.
	 *
	 * @since 0.1.0
	 *
	 * @param int|null $id Category ID, or null for none.
	 * @return int[]
	 */
	private function path_ids( ?int $id ): array {
		return null === $id ? array() : array_map( static fn ( Category $ancestor ): int => $ancestor->id, $this->path( $id ) );
	}

	/**
	 * Checks a slug.
	 *
	 * @since 0.1.0
	 *
	 * @param int|null $id        Category ID, or null for a new category.
	 * @param int|null $parent_id Parent category ID.
	 * @param string   $slug      Slug.
	 * @return string|null Error message, or null if the slug can be used.
	 */
	private function slug_error( ?int $id, ?int $parent_id, string $slug ): ?string {
		if ( '' === $slug ) {
			return __( 'Please enter a slug.', 'easyq' );
		}

		if ( Text::length( $slug ) > self::MAX_SLUG_LENGTH ) {
			/* translators: %d: maximum number of characters. */
			return sprintf( __( 'The slug can have at most %d characters.', 'easyq' ), self::MAX_SLUG_LENGTH );
		}

		if ( preg_match( '/[\+\/]/', $slug ) ) {
			return __( 'The slug must not contain + or /.', 'easyq' );
		}

		if ( $this->slug_taken( $parent_id, $slug, $id ) ) {
			return __( 'Another category with this slug already exists here.', 'easyq' );
		}

		return null;
	}

	/**
	 * Returns a slug for a new category that no sibling uses.
	 *
	 * Tries the given slug, then `category-{slug}`, then `category-1`, `category-2`, …
	 *
	 * @since 0.1.0
	 *
	 * @param int|null $parent_id Parent category ID.
	 * @param string   $slug      Slug derived from the name.
	 * @return string The first usable slug, or the given slug if none is, so that validation reports why.
	 */
	private function free_slug( ?int $parent_id, string $slug ): string {
		/* translators: %s: slug or number. Used as the slug of a category whose name gives no usable slug. */
		$pattern    = _x( 'category-%s', 'category slug', 'easyq' );
		$candidates = array( $slug, sprintf( $pattern, $slug ) );
		for ( $number = 1; $number < 99; $number++ ) {
			$candidates[] = sprintf( $pattern, $number );
		}

		foreach ( $candidates as $candidate ) {
			$candidate = sanitize_title( $candidate );
			if ( null === $this->slug_error( null, $parent_id, $candidate ) ) {
				return $candidate;
			}
		}

		return $slug;
	}

	/**
	 * Moves a category to another parent, at the end of its new siblings, and updates
	 * the posts and counts that depend on it.
	 *
	 * @since 0.1.0
	 *
	 * @param int      $id        Category ID.
	 * @param int|null $parent_id New parent category ID, or null for the top level.
	 */
	private function change_parent( int $id, ?int $parent_id ): void {
		$subtree       = $this->subtree_ids( $id );
		$old_ancestors = array_map( static fn ( Category $ancestor ): int => $ancestor->id, $this->path( $id ) );

		$this->set_position( $id, PHP_INT_MAX );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table; the cache is cleared below.
		DB::wpdb()->update(
			Schema::table( 'categories' ),
			array(
				'parent_id' => $parent_id,
				'position'  => $this->last_position( $parent_id ) + 1,
			),
			array( 'id' => $id )
		);
		$this->forget();

		$this->recalc_backpaths( $subtree );
		$this->posts->calc_category_paths_in( $subtree );

		$new_ancestors = array_map( static fn ( Category $ancestor ): int => $ancestor->id, $this->path( $id ) );
		$this->recount( array_unique( array_merge( $old_ancestors, $new_ancestors, $subtree ) ) );
	}

	/**
	 * Checks whether a sibling already uses a slug.
	 *
	 * @since 0.1.0
	 *
	 * @param int|null $parent_id Parent category ID.
	 * @param string   $slug      Slug.
	 * @param int|null $except_id Category to ignore.
	 * @return bool
	 */
	private function slug_taken( ?int $parent_id, string $slug, ?int $except_id ): bool {
		foreach ( $this->children( $parent_id ) as $sibling ) {
			if ( $sibling->id !== $except_id && Text::lowercase( $sibling->slug ) === Text::lowercase( $slug ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns the number of levels of a category and its subcategories.
	 *
	 * @since 0.1.0
	 *
	 * @param int $id Category ID.
	 * @return int 1 for a category without subcategories.
	 */
	private function height( int $id ): int {
		$height = 1;
		foreach ( $this->children( $id ) as $child ) {
			$height = max( $height, 1 + $this->height( $child->id ) );
		}

		return $height;
	}

	/**
	 * Returns the highest position among the children of a category.
	 *
	 * @since 0.1.0
	 *
	 * @param int|null $parent_id Parent category ID.
	 * @return int 0 if there are no children.
	 */
	private function last_position( ?int $parent_id ): int {
		$children = $this->children( $parent_id );

		return array() === $children ? 0 : max( array_map( static fn ( Category $child ): int => $child->position, $children ) );
	}

	/**
	 * Stores positions 1, 2, 3, … for categories in the given order.
	 *
	 * @since 0.1.0
	 *
	 * @param int[] $ids Category IDs in their new order.
	 */
	private function write_positions( array $ids ): void {
		$wpdb  = DB::wpdb();
		$table = Schema::table( 'categories' );

		foreach ( $ids as $index => $id ) {
			$sql = $wpdb->prepare( 'UPDATE %i SET position = %d WHERE id = %d', $table, $index + 1, $id );
			if ( null !== $sql ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; custom tables without a WordPress API.
				$wpdb->query( $sql );
			}
		}

		$this->forget();
	}
}
