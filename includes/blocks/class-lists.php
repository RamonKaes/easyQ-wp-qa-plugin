<?php
/**
 * Lists rendered on the current page.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Remembers the result of every list rendered on the page.
 *
 * A list block (question query, answer list, comment list) gives its children a
 * list ID through block context. The template block that loops over the items
 * stores the result here, so pagination and "no results" blocks can use it.
 *
 * @since 0.1.0
 */
final class Lists {

	/**
	 * Query parameter with the page number of the main list, the one that follows the route.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const MAIN_PAGE_VAR = 'easyq_page';

	/**
	 * Results keyed by list ID.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, array{ids: int[], total: int, page: int, per_page: int, page_var: string}>
	 */
	private static array $results = array();

	/**
	 * Stores the result of a list.
	 *
	 * @since 0.1.0
	 *
	 * @param string $list_id  List ID.
	 * @param int[]  $ids      IDs of the items on the current page.
	 * @param int    $total    Number of items on all pages.
	 * @param int    $page     Current page.
	 * @param int    $per_page Items per page.
	 * @param string $page_var Query parameter that holds the page number.
	 */
	public static function store( string $list_id, array $ids, int $total, int $page, int $per_page, string $page_var ): void {
		self::$results[ $list_id ] = array(
			'ids'      => $ids,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'page_var' => $page_var,
		);
	}

	/**
	 * Returns the result of a list.
	 *
	 * @since 0.1.0
	 *
	 * @param string $list_id List ID.
	 * @return array{ids: int[], total: int, page: int, per_page: int, page_var: string}|null
	 */
	public static function get( string $list_id ): ?array {
		return self::$results[ $list_id ] ?? null;
	}

	/**
	 * Checks whether the main list of the page, the one that follows the route, has no items.
	 *
	 * Also true for page numbers beyond the last page.
	 *
	 * @since 0.1.0
	 *
	 * @return bool False if the page has no main list or it has items.
	 */
	public static function main_list_is_empty(): bool {
		foreach ( self::$results as $result ) {
			if ( self::MAIN_PAGE_VAR === $result['page_var'] && array() === $result['ids'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns the page requested for a list.
	 *
	 * @since 0.1.0
	 *
	 * @param string $page_var Query parameter that holds the page number.
	 * @return int
	 */
	public static function requested_page( string $page_var ): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading the page number of a list.
		$page = isset( $_GET[ $page_var ] ) && is_string( $_GET[ $page_var ] ) ? absint( $_GET[ $page_var ] ) : 1;

		return max( 1, $page );
	}

	/**
	 * Forgets all results. Used by tests.
	 *
	 * @since 0.1.0
	 */
	public static function reset(): void {
		self::$results = array();
	}
}
