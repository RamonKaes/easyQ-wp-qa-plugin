<?php
/**
 * Category list table.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Admin;

use EasyQ\Categories\Categories;
use EasyQ\Categories\Category;
use EasyQ\Frontend\URLs;
use WP_List_Table;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( WP_List_Table::class ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Lists all categories as a tree, like the core category screen.
 *
 * @since 0.1.0
 */
final class Categories_List_Table extends WP_List_Table {

	/**
	 * Categories depth first, each with its depth.
	 *
	 * @since 0.1.0
	 *
	 * @var array<int, array{0: Category, 1: int}>
	 */
	private array $tree = array();

	/**
	 * Sets up the table.
	 *
	 * @since 0.1.0
	 *
	 * @param Categories $categories Category service.
	 */
	public function __construct( private readonly Categories $categories ) {
		parent::__construct(
			array(
				'plural'   => 'easyq-categories',
				'singular' => 'easyq-category',
				'screen'   => Categories_Screen::PAGE,
			)
		);
	}

	/**
	 * Loads the categories.
	 *
	 * @since 0.1.0
	 */
	public function prepare_items(): void {
		$this->tree            = $this->categories->tree();
		$this->items           = $this->tree;
		$this->_column_headers = array( $this->get_columns(), array(), array(), 'name' );
	}

	/**
	 * Returns the columns.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'name'        => __( 'Name', 'easyq' ),
			'description' => __( 'Description', 'easyq' ),
			'slug'        => __( 'Slug', 'easyq' ),
			'questions'   => __( 'Questions', 'easyq' ),
		);
	}

	/**
	 * Returns the text shown when there are no categories.
	 *
	 * @since 0.1.0
	 */
	public function no_items(): void {
		esc_html_e( 'No categories yet. To get started, add the first category.', 'easyq' );
	}

	/**
	 * Renders the table navigation. The list has no bulk actions and no pages.
	 *
	 * @since 0.1.0
	 *
	 * @param string $which Above or below the table.
	 */
	protected function display_tablenav( $which ): void {
	}

	/**
	 * Renders a row.
	 *
	 * @since 0.1.0
	 *
	 * @param array{0: Category, 1: int} $item Category and depth.
	 */
	public function single_row( $item ): void {
		echo '<tr id="easyq-category-' . esc_attr( (string) $item[0]->id ) . '">';
		$this->single_row_columns( $item );
		echo '</tr>';
	}

	/**
	 * Returns a short name for the row header: the name of the category.
	 *
	 * @since 0.1.0
	 *
	 * @param array{0: Category, 1: int} $item Category and depth.
	 * @return string
	 */
	protected function get_primary_column_aria_label( $item ): string {
		return $item[0]->title;
	}

	/**
	 * Renders the name column with the row actions.
	 *
	 * @since 0.1.0
	 *
	 * @param array{0: Category, 1: int} $item Category and depth.
	 * @return string
	 */
	public function column_name( array $item ): string {
		[ $category, $depth ] = $item;

		$name = sprintf(
			'<strong>%1$s<a class="row-title" href="%2$s">%3$s</a></strong>',
			esc_html( str_repeat( '— ', $depth ) ),
			esc_url( Categories_Screen::edit_url( $category->id ) ),
			esc_html( $category->title )
		);

		return $name . $this->category_actions( $category, $depth );
	}

	/**
	 * Renders the description column.
	 *
	 * @since 0.1.0
	 *
	 * @param array{0: Category, 1: int} $item Category and depth.
	 * @return string
	 */
	public function column_description( array $item ): string {
		return esc_html( $item[0]->description );
	}

	/**
	 * Renders the slug column.
	 *
	 * @since 0.1.0
	 *
	 * @param array{0: Category, 1: int} $item Category and depth.
	 * @return string
	 */
	public function column_slug( array $item ): string {
		return esc_html( $item[0]->slug );
	}

	/**
	 * Renders the question count, including subcategories, with a link to the category page.
	 *
	 * @since 0.1.0
	 *
	 * @param array{0: Category, 1: int} $item Category and depth.
	 * @return string
	 */
	public function column_questions( array $item ): string {
		return sprintf( '<a href="%s">%s</a>', esc_url( URLs::category( $item[0] ) ), esc_html( number_format_i18n( $item[0]->question_count ) ) );
	}

	/**
	 * Returns the row actions of a category.
	 *
	 * @since 0.1.0
	 *
	 * @param Category $category Category.
	 * @param int      $depth    Depth, 0 for the top level.
	 * @return string
	 */
	private function category_actions( Category $category, int $depth ): string {
		$actions = array(
			'edit' => sprintf(
				'<a href="%s" aria-label="%s">%s</a>',
				esc_url( Categories_Screen::edit_url( $category->id ) ),
				/* translators: %s: category name. */
				esc_attr( sprintf( __( 'Edit “%s”', 'easyq' ), $category->title ) ),
				esc_html__( 'Edit', 'easyq' )
			),
		);

		if ( $depth + 1 < Categories::MAX_DEPTH ) {
			$actions['add_sub'] = sprintf(
				'<a href="%s" aria-label="%s">%s</a>',
				esc_url( Categories_Screen::url( array( 'parent' => $category->id ) ) . '#easyq-category-add' ),
				/* translators: %s: category name. */
				esc_attr( sprintf( __( 'Add a subcategory to “%s”', 'easyq' ), $category->title ) ),
				esc_html__( 'Add subcategory', 'easyq' )
			);
		}

		$actions['view'] = sprintf(
			'<a href="%s" aria-label="%s">%s</a>',
			esc_url( URLs::category( $category ) ),
			/* translators: %s: category name. */
			esc_attr( sprintf( __( 'View “%s”', 'easyq' ), $category->title ) ),
			esc_html__( 'View', 'easyq' )
		);

		return $this->row_actions( $actions );
	}
}
