<?php
/**
 * Category management.
 *
 * @package easyQ
 */

namespace EasyQ\Categories;

use EasyQ\Database\Db;
use EasyQ\Database\Schema;
use EasyQ\Posts\Post_Repository;
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
 */
final class Categories {

	/**
	 * Maximum depth of the category tree.
	 *
	 * @var int
	 */
	public const MAX_DEPTH = 4;

	/**
	 * Longest category name in characters.
	 *
	 * @var int
	 */
	public const MAX_TITLE_LENGTH = 80;

	/**
	 * Longest slug in characters.
	 *
	 * @var int
	 */
	public const MAX_SLUG_LENGTH = 200;

	/**
	 * Longest description in characters.
	 *
	 * @var int
	 */
	public const MAX_DESCRIPTION_LENGTH = 800;

	/**
	 * Object cache group.
	 *
	 * @var string
	 */
	private const CACHE_GROUP = 'easyq_categories';

	/**
	 * Sets up the service.
	 *
	 * @param Post_Repository $posts Post storage.
	 */
	public function __construct( private readonly Post_Repository $posts ) {
	}

	/**
	 * Returns a category.
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
	 * @return array<int, Category> Categories keyed by ID.
	 */
	public function all(): array {
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

		$rows = Db::get_results(
			Db::wpdb()->prepare( 'SELECT * FROM %i ORDER BY parent_id, position', Schema::table( 'categories' ) )
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
	 * @param int|null $parent_id Parent category ID, or null for the top level.
	 * @return Category[]
	 */
	public function children( ?int $parent_id ): array {
		$children = array_filter( $this->all(), static fn ( Category $category ): bool => $category->parent_id === $parent_id );
		usort( $children, static fn ( Category $a, Category $b ): int => $a->position <=> $b->position );

		return $children;
	}

	/**
	 * Returns a category and its ancestors, from the top level down.
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
	 * Creates a category at the end of its siblings.
	 *
	 * @param int|null $parent_id   Parent category ID, or null for the top level.
	 * @param string   $title       Name.
	 * @param string   $slug        Slug; derived from the name if empty.
	 * @param string   $description Description.
	 * @return int|WP_Error The new category ID, or the validation errors.
	 */
	public function create( ?int $parent_id, string $title, string $slug = '', string $description = '' ): int|WP_Error {
		$title = trim( $title );
		$slug  = '' === trim( $slug ) ? sanitize_title( $title ) : trim( $slug );

		$errors = $this->validate( null, $parent_id, $title, $slug, $description );
		if ( null !== $parent_id && count( $this->path( $parent_id ) ) >= self::MAX_DEPTH ) {
			$errors->add( 'parent', __( 'Categories can be nested up to four levels deep.', 'easyq' ) );
		}
		if ( $errors->has_errors() ) {
			return $errors;
		}

		$id = Db::insert(
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

		return $id;
	}

	/**
	 * Changes name, slug and description of a category.
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

		$title  = trim( $title );
		$slug   = '' === trim( $slug ) ? sanitize_title( $title ) : trim( $slug );
		$errors = $this->validate( $id, $category->parent_id, $title, $slug, $description );
		if ( $errors->has_errors() ) {
			return $errors;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table; the cache is cleared below.
		Db::wpdb()->update(
			Schema::table( 'categories' ),
			array(
				'title'       => $title,
				'slug'        => $slug,
				'description' => $description,
			),
			array( 'id' => $id )
		);

		$this->forget();
		if ( $slug !== $category->slug ) {
			$this->recalc_backpaths( $this->subtree_ids( $id ) );
		}

		return true;
	}

	/**
	 * Moves a category to another parent, at the end of its new siblings.
	 *
	 * Posts in the category and its subcategories get their new category path,
	 * and the question counts of all affected categories are recalculated.
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

		if ( $new_parent_id === $category->parent_id ) {
			return true;
		}

		$subtree = $this->subtree_ids( $id );
		if ( null !== $new_parent_id && ( in_array( $new_parent_id, $subtree, true ) || null === $this->find( $new_parent_id ) ) ) {
			return new WP_Error( 'parent', __( 'A category cannot be moved into itself or one of its subcategories.', 'easyq' ) );
		}

		$new_depth = ( null === $new_parent_id ? 0 : count( $this->path( $new_parent_id ) ) ) + $this->height( $id );
		if ( $new_depth > self::MAX_DEPTH ) {
			return new WP_Error( 'parent', __( 'Categories can be nested up to four levels deep.', 'easyq' ) );
		}

		if ( $this->slug_taken( $new_parent_id, $category->slug, $id ) ) {
			return new WP_Error( 'slug', __( 'Another category with this slug already exists at the new place.', 'easyq' ) );
		}

		$old_ancestors = array_map( static fn ( Category $ancestor ): int => $ancestor->id, $this->path( $id ) );

		$this->set_position( $id, PHP_INT_MAX );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table; the cache is cleared below.
		Db::wpdb()->update(
			Schema::table( 'categories' ),
			array(
				'parent_id' => $new_parent_id,
				'position'  => $this->last_position( $new_parent_id ) + 1,
			),
			array( 'id' => $id )
		);
		$this->forget();

		$this->recalc_backpaths( $subtree );
		$this->posts->calc_category_paths_in( $subtree );

		$new_ancestors = array_map( static fn ( Category $ancestor ): int => $ancestor->id, $this->path( $id ) );
		$this->recount( array_unique( array_merge( $old_ancestors, $new_ancestors, $subtree ) ) );

		return true;
	}

	/**
	 * Moves a category to a position among its siblings.
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
	 * @param int      $id          Category ID.
	 * @param int|null $reassign_to Category that receives the posts, or null to leave them without a category.
	 * @return true|WP_Error
	 */
	public function delete( int $id, ?int $reassign_to = null ): bool|WP_Error {
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
		Db::query( Db::wpdb()->prepare( 'DELETE FROM %i WHERE id = %d', Schema::table( 'categories' ), $id ) );
		$this->forget();

		$targets = null === $reassign_to ? array() : array_map( static fn ( Category $ancestor ): int => $ancestor->id, $this->path( $reassign_to ) );
		$this->recount( array_diff( array_unique( array_merge( $ancestors, $targets ) ), array( $id ) ) );

		return true;
	}

	/**
	 * Moves all posts of a category to another category.
	 *
	 * @param int      $id          Category ID.
	 * @param int|null $reassign_to Target category ID, or null for no category.
	 */
	public function reassign_posts( int $id, ?int $reassign_to ): void {
		$wpdb     = Db::wpdb();
		$table    = Schema::table( 'posts' );
		$post_ids = Db::get_int_col( $wpdb->prepare( 'SELECT id FROM %i WHERE category_id = %d', $table, $id ) );

		if ( null === $reassign_to ) {
			Db::query( $wpdb->prepare( 'UPDATE %i SET category_id = NULL WHERE category_id = %d', $table, $id ) );
		} else {
			Db::query( $wpdb->prepare( 'UPDATE %i SET category_id = %d WHERE category_id = %d', $table, $reassign_to, $id ) );
		}

		foreach ( $post_ids as $post_id ) {
			$this->posts->calc_category_path( $post_id );
		}
	}

	/**
	 * Recalculates the number of visible questions in categories, including their subcategories.
	 *
	 * @param int[] $ids Category IDs.
	 */
	public function recount( array $ids ): void {
		$wpdb       = Db::wpdb();
		$categories = Schema::table( 'categories' );
		$posts      = Schema::table( 'posts' );

		foreach ( array_unique( array_filter( $ids ) ) as $id ) {
			Db::query(
				$wpdb->prepare(
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
				)
			);
		}

		$this->forget();
	}

	/**
	 * Recalculates the backpaths of categories.
	 *
	 * @param int[] $ids Category IDs.
	 */
	public function recalc_backpaths( array $ids ): void {
		$wpdb  = Db::wpdb();
		$table = Schema::table( 'categories' );

		foreach ( $ids as $id ) {
			Db::query(
				$wpdb->prepare(
					"UPDATE %i AS x, (SELECT c1.id, CONCAT_WS('/', c1.slug, c2.slug, c3.slug, c4.slug) AS backpath FROM %i AS c1 LEFT JOIN %i AS c2 ON c1.parent_id = c2.id LEFT JOIN %i AS c3 ON c2.parent_id = c3.id LEFT JOIN %i AS c4 ON c3.parent_id = c4.id WHERE c1.id = %d) AS a SET x.backpath = a.backpath WHERE x.id = a.id",
					$table,
					$table,
					$table,
					$table,
					$table,
					$id
				)
			);
		}

		$this->forget();
	}

	/**
	 * Clears the category cache.
	 */
	public function forget(): void {
		wp_cache_delete( 'all', self::CACHE_GROUP );
	}

	/**
	 * Validates the fields of a category.
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

		if ( '' === $slug ) {
			$errors->add( 'slug', __( 'Please enter a slug.', 'easyq' ) );
		} elseif ( Text::length( $slug ) > self::MAX_SLUG_LENGTH ) {
			/* translators: %d: maximum number of characters. */
			$errors->add( 'slug', sprintf( __( 'The slug can have at most %d characters.', 'easyq' ), self::MAX_SLUG_LENGTH ) );
		} elseif ( preg_match( '/[\+\/]/', $slug ) ) {
			$errors->add( 'slug', __( 'The slug must not contain + or /.', 'easyq' ) );
		} elseif ( $this->slug_taken( $parent_id, $slug, $id ) ) {
			$errors->add( 'slug', __( 'Another category with this slug already exists here.', 'easyq' ) );
		}

		if ( Text::length( $description ) > self::MAX_DESCRIPTION_LENGTH ) {
			/* translators: %d: maximum number of characters. */
			$errors->add( 'description', sprintf( __( 'The description can have at most %d characters.', 'easyq' ), self::MAX_DESCRIPTION_LENGTH ) );
		}

		return $errors;
	}

	/**
	 * Checks whether a sibling already uses a slug.
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
	 * @param int[] $ids Category IDs in their new order.
	 */
	private function write_positions( array $ids ): void {
		$wpdb  = Db::wpdb();
		$table = Schema::table( 'categories' );

		foreach ( $ids as $index => $id ) {
			Db::query( $wpdb->prepare( 'UPDATE %i SET position = %d WHERE id = %d', $table, $index + 1, $id ) );
		}

		$this->forget();
	}
}
