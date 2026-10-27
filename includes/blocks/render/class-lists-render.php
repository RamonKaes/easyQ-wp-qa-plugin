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
	 * Query parameter with the post whose comments are all shown.
	 *
	 * @var string
	 */
	public const SHOW_COMMENTS_VAR = 'easyq_comments';

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

		$pages  = (int) ceil( $result['total'] / $result['per_page'] );
		$arrows = array(
			'arrow'   => array( '←', '→' ),
			'chevron' => array( '«', '»' ),
		);
		$style  = is_string( $attributes['arrow'] ?? null ) && isset( $arrows[ $attributes['arrow'] ] ) ? $attributes['arrow'] : null;
		$arrow  = null === $style ? null : $arrows[ $style ];
		// Like the core Query Pagination block: without labels the arrows are shown and the text is for screen readers.
		$labels = ( $attributes['showLabel'] ?? true ) || null === $arrow;

		$link = static function ( string $direction, int $page, string $label, string $symbol ) use ( $result, $style, $labels ): string {
			$arrow_html = null === $style ? '' : sprintf( '<span class="wp-block-query-pagination-%1$s-arrow is-arrow-%2$s" aria-hidden="true">%3$s</span>', $direction, $style, $symbol );
			$text       = $labels ? esc_html( $label ) : sprintf( '<span class="screen-reader-text">%s</span>', esc_html( $label ) );

			return sprintf(
				'<a class="wp-block-query-pagination-%s" href="%s">%s</a>',
				$direction,
				esc_url( add_query_arg( $result['page_var'], $page ) ),
				'previous' === $direction ? $arrow_html . $text : $text . $arrow_html
			);
		};

		$previous = $result['page'] > 1 ? $link( 'previous', $result['page'] - 1, __( 'Previous', 'easyq' ), $arrow[0] ?? '' ) : '';
		$next     = $result['page'] < $pages ? $link( 'next', $result['page'] + 1, __( 'Next', 'easyq' ), $arrow[1] ?? '' ) : '';

		$numbers = '';
		if ( $attributes['showNumbers'] ?? true ) {
			$links   = paginate_links(
				array(
					'base'      => add_query_arg( $result['page_var'], '%#%' ),
					'format'    => '',
					'current'   => $result['page'],
					'total'     => $pages,
					'prev_next' => false,
					'type'      => 'plain',
				)
			);
			$numbers = sprintf( '<div class="wp-block-query-pagination-numbers">%s</div>', is_string( $links ) ? $links : '' );
		}

		return sprintf(
			'<nav %s aria-label="%s">%s%s%s</nav>',
			get_block_wrapper_attributes( array( 'class' => 'wp-block-query-pagination is-layout-flex' ) ),
			esc_attr__( 'Pages', 'easyq' ),
			$previous,
			$numbers,
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

		// A router region: answering and loading comments update it in place, see src/view.
		return sprintf(
			'<article %s data-wp-interactive="easyq" data-wp-router-region="%s">%s</article>',
			get_block_wrapper_attributes( array( 'id' => 'easyq-post-' . $question->id ) ),
			esc_attr( 'easyq/question-' . $question->id ),
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

		// The answer list block can override the site settings; empty values keep them.
		$order          = $block->context['easyq/answerOrder'] ?? '';
		$selected_first = $block->context['easyq/answerSelectedFirst'] ?? '';
		$by_votes       = 'votes' === ( in_array( $order, array( 'votes', 'created' ), true ) ? $order : Options::get_string( 'viewing', 'sort_answers_by' ) );
		$selected       = ( in_array( $selected_first, array( 'yes', 'no' ), true ) ? 'yes' === $selected_first : Options::get_bool( 'viewing', 'show_selected_first' ) ) ? $question->selected_child_id : null;
		usort(
			$answers,
			static function ( Post $a, Post $b ) use ( $by_votes, $selected ): int {
				if ( null !== $selected && ( $a->id === $selected || $b->id === $selected ) ) {
					return $a->id === $selected ? -1 : 1;
				}

				return $by_votes ? array( $b->net_votes, $a->created, $a->id ) <=> array( $a->net_votes, $b->created, $b->id ) : array( $a->created, $a->id ) <=> array( $b->created, $b->id );
			}
		);

		$per_page = self::positive_int( $block->context['easyq/answersPerPage'] ?? null ) ?? max( 1, Options::get_int( 'viewing', 'page_size_q_as' ) );
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
		$post = Block_Context::post( $block );

		return null === $post ? '' : sprintf( '<div %s>%s</div>', get_block_wrapper_attributes( array( 'id' => 'easyq-comments-' . $post->id ) ), $content );
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

		// Long discussions show only the latest comments, with a link to the earlier ones, as in Question2Answer.
		// The comment list block can override the site settings; 0 keeps them.
		$from    = self::positive_int( $block->context['easyq/commentsFewerFrom'] ?? null ) ?? max( 1, Options::get_int( 'viewing', 'show_fewer_cs_from' ) );
		$keep    = self::positive_int( $block->context['easyq/commentsFewerCount'] ?? null ) ?? max( 1, Options::get_int( 'viewing', 'show_fewer_cs_count' ) );
		$earlier = '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading which comments to show.
		$show_all = isset( $_GET[ self::SHOW_COMMENTS_VAR ] ) && is_string( $_GET[ self::SHOW_COMMENTS_VAR ] ) && absint( $_GET[ self::SHOW_COMMENTS_VAR ] ) === $parent->id;

		if ( count( $ids ) > $from && ! $show_all ) {
			$hidden  = count( $ids ) - $keep;
			$ids     = array_slice( $ids, -$keep );
			$earlier = sprintf(
				'<p><a href="%s" data-wp-on--click="actions.navigate">%s</a></p>',
				esc_url( add_query_arg( self::SHOW_COMMENTS_VAR, $parent->id ) . '#easyq-comments-' . $parent->id ),
				/* translators: %s: number of comments. */
				esc_html( sprintf( _n( 'Show %s previous comment', 'Show %s previous comments', $hidden, 'easyq' ), number_format_i18n( $hidden ) ) )
			);
		}

		return $earlier . self::loop( $block, $ids, 'li' );
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

	/**
	 * Returns a positive whole number from a block setting.
	 *
	 * @param mixed $value Setting.
	 * @return int|null The number, or null if the setting is empty, 0 or invalid.
	 */
	private static function positive_int( mixed $value ): ?int {
		return is_numeric( $value ) && (int) $value > 0 ? (int) $value : null;
	}
}
