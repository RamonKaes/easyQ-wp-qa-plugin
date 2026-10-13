<?php
/**
 * Question queries of blocks.
 *
 * @package easyQ
 */

namespace EasyQ\Blocks\Render;

use EasyQ\Blocks\Block_Context;
use EasyQ\Blocks\Lists;
use EasyQ\Posts\Question_Query;
use EasyQ\Services;
use EasyQ\Settings\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the query of a question list block, or the current page, into a list of questions.
 */
final class Query_Resolver {

	/**
	 * Sort orders of the start page, selected with the `sort` parameter.
	 *
	 * @var string[]
	 */
	public const SORTS = array( 'recent', 'hot', 'votes', 'answers', 'views' );

	/**
	 * Lists of the unanswered page, selected with the `by` parameter.
	 *
	 * @var string[]
	 */
	public const UNANSWERED = array( 'unanswered', 'unselected', 'unupvoted' );

	/**
	 * Lists the questions of a block and stores the result for pagination.
	 *
	 * @param array<mixed> $query   Block query: source, category, tag, perPage, inherit.
	 * @param string       $list_id List ID.
	 * @return array{ids: int[], total: int}
	 */
	public static function questions( array $query, string $list_id ): array {
		$args     = self::arguments( $query );
		$page_var = ! empty( $query['inherit'] ) ? 'easyq_page' : 'easyq_page_' . $list_id;
		$page     = Lists::requested_page( $page_var );
		$per_page = is_numeric( $query['perPage'] ?? null ) && (int) $query['perPage'] > 0 ? (int) $query['perPage'] : Options::get_int( 'lists', 'page_size_qs' );
		$per_page = max( 1, min( Question_Query::MAX_PER_PAGE, $per_page ) );

		if ( isset( $args['search'] ) && is_string( $args['search'] ) ) {
			$start   = ( $page - 1 ) * $per_page;
			$results = Services::search()->search( $args['search'], $start, $per_page + 1, Block_Context::viewer()->user_id );
			$ids     = array_slice( array_map( static fn ( $result ): int => $result->question_id, $results ), 0, $per_page );
			$total   = $start + count( $results );
		} else {
			$found = Services::question_query()->find( $args + compact( 'page', 'per_page' ) );
			$ids   = array_map( static fn ( $post ): int => $post->id, $found['posts'] );
			$total = $found['total'];
		}

		Lists::store( $list_id, $ids, $total, $page, $per_page, $page_var );

		return array(
			'ids'   => $ids,
			'total' => $total,
		);
	}

	/**
	 * Returns the query arguments of a block or of the current page.
	 *
	 * @param array<mixed> $query Block query.
	 * @return array<string, mixed>
	 */
	public static function arguments( array $query ): array {
		$route = Block_Context::router()->current();

		if ( ! empty( $query['inherit'] ) && null !== $route ) {
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Reading list filters.
			$sort = isset( $_GET['sort'] ) && is_string( $_GET['sort'] ) ? sanitize_key( $_GET['sort'] ) : '';
			$by   = isset( $_GET['by'] ) && is_string( $_GET['by'] ) ? sanitize_key( $_GET['by'] ) : '';
			$q    = isset( $_GET['q'] ) && is_string( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
			// phpcs:enable WordPress.Security.NonceVerification.Recommended

			return match ( $route->name ) {
				'hot'        => array( 'source' => 'hot' ),
				'unanswered' => array( 'source' => in_array( $by, self::UNANSWERED, true ) ? $by : 'unanswered' ),
				'tag'        => array(
					'source' => 'recent',
					'tag'    => $route->tag(),
				),
				'category'   => array(
					'source'      => in_array( $sort, self::SORTS, true ) ? $sort : 'recent',
					'category_id' => $route->category()?->id,
				),
				'search'     => array( 'search' => $q ),
				default      => array( 'source' => in_array( $sort, self::SORTS, true ) ? $sort : 'recent' ),
			};
		}

		$category = is_numeric( $query['category'] ?? null ) && (int) $query['category'] > 0 ? (int) $query['category'] : null;
		$tag      = is_string( $query['tag'] ?? null ) && '' !== $query['tag'] ? $query['tag'] : null;

		return array_filter(
			array(
				'source'      => is_string( $query['source'] ?? null ) ? $query['source'] : 'recent',
				'category_id' => $category,
				'tag'         => $tag,
			),
			static fn ( $value ): bool => null !== $value
		);
	}
}
