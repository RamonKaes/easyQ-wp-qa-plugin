<?php
/**
 * Category model.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Categories;

use EasyQ\Database\Row;

defined( 'ABSPATH' ) || exit;

/**
 * A question category.
 *
 * @since 0.1.0
 */
final class Category {

	/**
	 * Sets up the category.
	 *
	 * @since 0.1.0
	 *
	 * @param int      $id             Category ID.
	 * @param int|null $parent_id      Parent category ID, or null for a top-level category.
	 * @param string   $title          Name.
	 * @param string   $slug           URL slug, unique among its siblings.
	 * @param string   $description    Description.
	 * @param int      $question_count Visible questions in the category and its subcategories.
	 * @param int      $position       Position among its siblings, starting at 1.
	 * @param string   $backpath       Slugs from the category up to the top level, separated by slashes.
	 */
	public function __construct(
		public readonly int $id,
		public readonly ?int $parent_id,
		public readonly string $title,
		public readonly string $slug,
		public readonly string $description,
		public readonly int $question_count,
		public readonly int $position,
		public readonly string $backpath
	) {
	}

	/**
	 * Creates a category from a database row.
	 *
	 * @since 0.1.0
	 *
	 * @param object $row Row of the categories table.
	 * @return self
	 */
	public static function from_row( object $row ): self {
		return new self(
			Row::int( $row, 'id' ),
			Row::nullable_int( $row, 'parent_id' ),
			Row::string( $row, 'title' ),
			Row::string( $row, 'slug' ),
			Row::string( $row, 'description' ),
			Row::int( $row, 'question_count' ),
			Row::int( $row, 'position' ),
			Row::string( $row, 'backpath' )
		);
	}

	/**
	 * Returns the slugs from the top level down to this category.
	 *
	 * @since 0.1.0
	 *
	 * @return string[]
	 */
	public function path_slugs(): array {
		return array_reverse( explode( '/', $this->backpath ) );
	}
}
