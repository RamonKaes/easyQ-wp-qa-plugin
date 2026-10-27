<?php
/**
 * Search provider interface.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Search;

defined( 'ABSPATH' ) || exit;

/**
 * A search engine for questions, answers and comments.
 *
 * Every registered provider is told about indexing changes; the provider selected
 * in the settings answers search queries. Corresponds to the Question2Answer
 * `search` module type.
 *
 * @since 0.1.0
 */
interface Search_Provider {

	/**
	 * Returns the unique ID of the provider.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * Returns the name shown in the settings.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function label(): string;

	/**
	 * Adds a visible post to the index.
	 *
	 * @since 0.1.0
	 *
	 * @param Indexed_Post $post Post data to index.
	 */
	public function index_post( Indexed_Post $post ): void;

	/**
	 * Removes a post from the index.
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Post ID.
	 */
	public function unindex_post( int $post_id ): void;

	/**
	 * Records that a post moved to another category.
	 *
	 * @since 0.1.0
	 *
	 * @param int      $post_id     Post ID.
	 * @param int|null $category_id New category ID.
	 */
	public function move_post( int $post_id, ?int $category_id ): void;

	/**
	 * Searches questions.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $query   Search query as entered by the user.
	 * @param int      $start   Offset of the first result.
	 * @param int      $count   Maximum number of results.
	 * @param int|null $user_id User searching, or null for visitors.
	 * @return Search_Result[] Results ordered by relevance.
	 */
	public function search( string $query, int $start, int $count, ?int $user_id ): array;
}
