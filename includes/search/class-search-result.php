<?php
/**
 * Search result.
 *
 * @package easyQ
 */

namespace EasyQ\Search;

defined( 'ABSPATH' ) || exit;

/**
 * A question found by a search, with the part that matched best.
 */
final class Search_Result {

	/**
	 * Sets up the result.
	 *
	 * @param int    $question_id   ID of the question.
	 * @param float  $score         Relevance score.
	 * @param string $match_type    Base type of the best matching post: Q, A, C or NOTE.
	 * @param int    $match_post_id ID of the best matching post, to link directly to it.
	 */
	public function __construct(
		public readonly int $question_id,
		public readonly float $score,
		public readonly string $match_type,
		public readonly int $match_post_id
	) {
	}
}
