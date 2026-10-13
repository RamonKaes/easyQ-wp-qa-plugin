<?php
/**
 * Data of a post to index.
 *
 * @package easyQ
 */

namespace EasyQ\Search;

defined( 'ABSPATH' ) || exit;

/**
 * Everything a search provider needs to index a post.
 */
final class Indexed_Post {

	/**
	 * Sets up the data.
	 *
	 * @param int         $post_id     Post ID.
	 * @param string      $type        Base type: Q, A, C or NOTE.
	 * @param int         $question_id ID of the question the post belongs to.
	 * @param int|null    $parent_id   Parent post ID.
	 * @param string|null $title       Title (questions only).
	 * @param string      $text        Plain text of the content.
	 * @param string|null $tags        Comma-separated tags (questions only).
	 * @param int|null    $category_id Category ID.
	 */
	public function __construct(
		public readonly int $post_id,
		public readonly string $type,
		public readonly int $question_id,
		public readonly ?int $parent_id,
		public readonly ?string $title,
		public readonly string $text,
		public readonly ?string $tags,
		public readonly ?int $category_id
	) {
	}
}
