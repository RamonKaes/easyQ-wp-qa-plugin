<?php
/**
 * Rendering of list blocks.
 *
 * @package easyQ
 */

namespace EasyQ\Blocks\Render;

use EasyQ\Blocks\Block_Context;
use EasyQ\Blocks\Lists;
use EasyQ\Blocks\Render;
use EasyQ\Posts\Post;
use EasyQ\Services;
use EasyQ\Settings\Options;
use WP_Block;

defined( 'ABSPATH' ) || exit;

/**
 * Renders question lists, the question block and the answer and comment lists.
 */
final class Lists_Render {

	/**
	 * Renders the question list container.
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @param string               $content    Inner blocks.
	 * @return string
	 */
	public static function question_query( array $attributes, string $content ): string {
		return sprintf( '<div %s>%s</div>', get_block_wrapper_attributes(), $content );
	}

	/**
	 * Renders each question of the list with the inner blocks.
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @param string               $content    Inner blocks (unused; rendered per question).
	 * @param WP_Block             $block      Block.
	 * @return string
	 */
	public static function question_template( array $attributes, string $content, WP_Block $block ): string {
		$list_id = Block_Context::list_id( $block );
		$query   = is_array( $block->context['easyq/query'] ?? null ) ? $block->context['easyq/query'] : array();
		$result  = Query_Resolver::questions( $query, $list_id );
		$items   = array();

		foreach ( $result['ids'] as $index => $id ) {
			$activity = $result['activity'][ $index ] ?? null;
			$items[]  = array(
				'id'      => null === $activity ? $id : $activity->post_id,
				'context' => array(
					'easyq/postId'   => $id,
					'easyq/activity' => $activity?->to_context(),
				),
			);
		}

		return self::render_items( $block, $items );
	}

	/**
	 * Renders pagination links for the nearest list.
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @param string               $content    Inner blocks.
	 * @param WP_Block             $block      Block.
	 * @return string
	 */
	public static function query_pagination( array $attributes, string $content, WP_Block $block ): string {
		$result = Lists::get( Block_Context::list_id( $block ) );
		if ( null === $result || $result['total'] <= $result['per_page'] ) {
			return '';
		}

		$pages = (int) ceil( $result['total'] / $result['per_page'] );
		$links = paginate_links(
			array(
				'base'      => add_query_arg( $result['page_var'], '%#%' ),
				'format'    => '',
				'current'   => $result['page'],
				'total'     => $pages,
				'prev_next' => false,
				'type'      => 'plain',
			)
		);

		$previous = $result['page'] > 1
			? sprintf( '<a class="wp-block-query-pagination-previous" href="%s">%s</a>', esc_url( add_query_arg( $result['page_var'], $result['page'] - 1 ) ), esc_html__( 'Previous', 'easyq' ) )
			: '';
		$next     = $result['page'] < $pages
			? sprintf( '<a class="wp-block-query-pagination-next" href="%s">%s</a>', esc_url( add_query_arg( $result['page_var'], $result['page'] + 1 ) ), esc_html__( 'Next', 'easyq' ) )
			: '';

		return sprintf(
			'<nav %s aria-label="%s">%s<div class="wp-block-query-pagination-numbers">%s</div>%s</nav>',
			get_block_wrapper_attributes( array( 'class' => 'wp-block-query-pagination is-layout-flex' ) ),
			esc_attr__( 'Pages', 'easyq' ),
			$previous,
			is_string( $links ) ? $links : '',
			$next
		);
	}

	/**
	 * Renders the content shown for an empty list.
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @param string               $content    Inner blocks.
	 * @param WP_Block             $block      Block.
	 * @return string
	 */
	public static function query_no_results( array $attributes, string $content, WP_Block $block ): string {
		$result = Lists::get( Block_Context::list_id( $block ) );
		if ( null === $result || array() !== $result['ids'] ) {
			return '';
		}

		$message = is_string( $attributes['message'] ?? null ) && '' !== $attributes['message'] ? $attributes['message'] : __( 'No questions found.', 'easyq' );

		return sprintf( '<div %s><p>%s</p></div>', get_block_wrapper_attributes(), esc_html( $message ) );
	}

	/**
	 * Renders the question of the page with the inner blocks.
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @param string               $content    Inner blocks (unused; rendered with the question).
	 * @param WP_Block             $block      Block.
	 * @return string
	 */
	public static function question( array $attributes, string $content, WP_Block $block ): string {
		$id       = is_numeric( $attributes['questionId'] ?? null ) ? (int) $attributes['questionId'] : 0;
		$question = 0 < $id ? Services::posts()->find( $id ) : Block_Context::router()->current()?->question();

		if ( null === $question || ! Services::permissions()->post_rules( $question, Block_Context::viewer() )['viewable'] ) {
			return '';
		}

		return sprintf(
			'<article %s>%s</article>',
			get_block_wrapper_attributes( array( 'id' => 'easyq-post-' . $question->id ) ),
			Render::inner_blocks( $block, array( 'easyq/postId' => $question->id ) )
		);
	}

	/**
	 * Renders the answer list container.
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @param string               $content    Inner blocks.
	 * @param WP_Block             $block      Block.
	 * @return string
	 */
	public static function answer_list( array $attributes, string $content, WP_Block $block ): string {
		$question = Block_Context::post( $block );
		if ( null === $question || Post::QUESTION !== $question->base_type() ) {
			return '';
		}

		$count = $question->answer_count;
		$title = sprintf(
			/* translators: %s: number of answers. */
			_n( '%s answer', '%s answers', $count, 'easyq' ),
			number_format_i18n( $count )
		);

		return sprintf( '<section %s><h2>%s</h2>%s</section>', get_block_wrapper_attributes(), esc_html( $title ), $content );
	}

	/**
	 * Renders each answer with the inner blocks.
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @param string               $content    Inner blocks (unused).
	 * @param WP_Block             $block      Block.
	 * @return string
	 */
	public static function answer_template( array $attributes, string $content, WP_Block $block ): string {
		$question = Block_Context::post( $block );
		if ( null === $question ) {
			return '';
		}

		$viewer      = Block_Context::viewer();
		$permissions = Services::permissions();
		$answers     = array_values(
			array_filter(
				Services::posts()->answers_of( $question->id ),
				static fn ( Post $answer ): bool => $permissions->post_rules( $answer, $viewer )['viewable']
			)
		);

		$by_votes = 'votes' === Options::get_string( 'viewing', 'sort_answers_by' );
		$selected = Options::get_bool( 'viewing', 'show_selected_first' ) ? $question->selected_child_id : null;
		usort(
			$answers,
			static function ( Post $a, Post $b ) use ( $by_votes, $selected ): int {
				if ( null !== $selected && ( $a->id === $selected || $b->id === $selected ) ) {
					return $a->id === $selected ? -1 : 1;
				}

				return $by_votes ? array( $b->net_votes, $a->created, $a->id ) <=> array( $a->net_votes, $b->created, $b->id ) : array( $a->created, $a->id ) <=> array( $b->created, $b->id );
			}
		);

		$per_page = max( 1, Options::get_int( 'viewing', 'page_size_q_as' ) );
		$page_var = 'easyq_answers';
		$page     = Lists::requested_page( $page_var );
		$ids      = array_map( static fn ( Post $answer ): int => $answer->id, array_slice( $answers, ( $page - 1 ) * $per_page, $per_page ) );

		Lists::store( Block_Context::list_id( $block ), $ids, count( $answers ), $page, $per_page, $page_var );

		return self::loop( $block, $ids, 'li' );
	}

	/**
	 * Renders the comment list container.
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @param string               $content    Inner blocks.
	 * @param WP_Block             $block      Block.
	 * @return string
	 */
	public static function comment_list( array $attributes, string $content, WP_Block $block ): string {
		return null === Block_Context::post( $block ) ? '' : sprintf( '<div %s>%s</div>', get_block_wrapper_attributes(), $content );
	}

	/**
	 * Renders each comment with the inner blocks.
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @param string               $content    Inner blocks (unused).
	 * @param WP_Block             $block      Block.
	 * @return string
	 */
	public static function comment_template( array $attributes, string $content, WP_Block $block ): string {
		$parent = Block_Context::post( $block );
		if ( null === $parent ) {
			return '';
		}

		$viewer      = Block_Context::viewer();
		$permissions = Services::permissions();
		$ids         = array();

		foreach ( Services::posts()->children( $parent->id ) as $child ) {
			if ( Post::COMMENT === $child->base_type() && $permissions->post_rules( $child, $viewer )['viewable'] ) {
				$ids[] = $child->id;
			}
		}

		return self::loop( $block, $ids, 'li' );
	}

	/**
	 * Renders the inner blocks once per post inside a list.
	 *
	 * @param WP_Block $block Template block.
	 * @param int[]    $ids   Post IDs.
	 * @param string   $tag   Element of each item.
	 * @return string
	 */
	private static function loop( WP_Block $block, array $ids, string $tag ): string {
		return self::render_items(
			$block,
			array_map(
				static fn ( int $id ): array => array(
					'id'      => $id,
					'context' => array( 'easyq/postId' => $id ),
				),
				$ids
			),
			$tag
		);
	}

	/**
	 * Renders the inner blocks once per item inside a list.
	 *
	 * @param WP_Block                                             $block Template block.
	 * @param array<array{id: int, context: array<string, mixed>}> $items Items with the post ID of the element and the block context.
	 * @param string                                               $tag   Element of each item.
	 * @return string
	 */
	private static function render_items( WP_Block $block, array $items, string $tag = 'li' ): string {
		if ( array() === $items ) {
			return '';
		}

		$html = '';
		foreach ( $items as $item ) {
			$html .= sprintf(
				'<%1$s id="easyq-post-%2$d">%3$s</%1$s>',
				$tag,
				$item['id'],
				Render::inner_blocks( $block, $item['context'] )
			);
		}

		return sprintf( '<ul %s>%s</ul>', get_block_wrapper_attributes( array( 'class' => 'wp-block-post-template' ) ), $html );
	}
}
