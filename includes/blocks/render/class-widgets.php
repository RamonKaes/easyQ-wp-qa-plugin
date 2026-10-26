<?php
/**
 * Rendering of navigation and overview blocks.
 *
 * @package easyQ
 */

namespace EasyQ\Blocks\Render;

use EasyQ\Blocks\Block_Context;
use EasyQ\Blocks\Render;
use EasyQ\Categories\Category;
use EasyQ\Frontend\Flash;
use EasyQ\Frontend\Route;
use EasyQ\Frontend\Routes;
use EasyQ\Frontend\Urls;
use EasyQ\Posts\Post;
use EasyQ\Posts\Tags;
use EasyQ\Services;
use EasyQ\Settings\Options;
use EasyQ\Stats\Counts;
use WP_Block;

defined( 'ABSPATH' ) || exit;

/**
 * Renders page titles, sorting, tags, categories, related questions, statistics, breadcrumbs and notices.
 */
final class Widgets {

	/**
	 * Renders the title of the current Q&A page.
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @return string
	 */
	public static function page_title( array $attributes ): string {
		$title = self::title_of_page();
		if ( '' === $title ) {
			return '';
		}

		$level = is_numeric( $attributes['level'] ?? null ) ? max( 1, min( 6, (int) $attributes['level'] ) ) : 1;

		return sprintf( '<h%1$d %2$s>%3$s</h%1$d>', $level, get_block_wrapper_attributes(), esc_html( $title ) );
	}

	/**
	 * Returns the title of the current Q&A page, also used for the document title.
	 *
	 * @return string
	 */
	public static function title_of_page(): string {
		$route = Block_Context::router()->current();
		if ( null === $route ) {
			return __( 'Questions', 'easyq' );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Reading list filters for the title.
		$sort = isset( $_GET['sort'] ) && is_string( $_GET['sort'] ) ? sanitize_key( $_GET['sort'] ) : '';
		$by   = isset( $_GET['by'] ) && is_string( $_GET['by'] ) ? sanitize_key( $_GET['by'] ) : '';
		$q    = isset( $_GET['q'] ) && is_string( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return match ( $route->name ) {
			'home'       => self::sort_labels()[ $sort ] ?? __( 'Recent questions', 'easyq' ),
			'hot'        => __( 'Hot questions', 'easyq' ),
			'unanswered' => self::unanswered_labels()[ $by ] ?? __( 'Questions without answers', 'easyq' ),
			/* translators: %s: tag. */
			'tag'        => sprintf( __( 'Questions tagged “%s”', 'easyq' ), (string) $route->tag() ),
			'category'   => (string) $route->category()?->title,
			/* translators: %s: search terms. */
			'search'     => '' === $q ? __( 'Search', 'easyq' ) : sprintf( __( 'Results for “%s”', 'easyq' ), $q ),
			'ask'        => __( 'Ask a question', 'easyq' ),
			'tags'       => __( 'Tags', 'easyq' ),
			'categories' => __( 'Categories', 'easyq' ),
			'question'   => (string) $route->question()?->title,
			'activity'   => __( 'Recent activity', 'easyq' ),
			'answers'    => __( 'Recent answers', 'easyq' ),
			'comments'   => __( 'Recent comments', 'easyq' ),
			'users'      => self::user_sort_labels()[ $sort ] ?? __( 'Top users', 'easyq' ),
			'user'       => self::user_title( $route ),
			'account'    => __( 'My account', 'easyq' ),
			'not_found'  => __( 'Page not found', 'easyq' ),
			default      => __( 'Questions', 'easyq' ),
		};
	}

	/**
	 * Renders links to sort the question list.
	 *
	 * @return string
	 */
	public static function sort_navigation(): string {
		$route = Block_Context::router()->current();

		if ( 'unanswered' === $route?->name ) {
			$param   = 'by';
			$labels  = self::unanswered_labels();
			$default = 'unanswered';
			$base    = Routes::url( 'unanswered' );
		} elseif ( 'users' === $route?->name ) {
			$param   = 'sort';
			$labels  = array_filter(
				self::user_sort_labels(),
				static fn ( string $sort ): bool => null === Users_Render::permission_error( $sort ),
				ARRAY_FILTER_USE_KEY
			);
			$default = 'points';
			$base    = Routes::url( 'users' );
		} else {
			$param   = 'sort';
			$labels  = self::sort_labels();
			$default = 'recent';
			$base    = 'category' === $route?->name && null !== $route->category() ? Urls::category( $route->category() ) : Routes::url( 'home' );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Marks the current sort order.
		$requested = isset( $_GET[ $param ] ) && is_string( $_GET[ $param ] ) ? sanitize_key( wp_unslash( $_GET[ $param ] ) ) : '';
		$current   = isset( $labels[ $requested ] ) ? $requested : $default;
		$items     = '';

		foreach ( $labels as $key => $label ) {
			$url    = $key === $default ? $base : add_query_arg( $param, $key, $base );
			$items .= sprintf(
				'<a href="%s"%s>%s</a>',
				esc_url( $url ),
				$key === $current ? ' aria-current="page"' : '',
				esc_html( $label )
			);
		}

		$label = 'users' === $route?->name ? __( 'Sort users', 'easyq' ) : __( 'Sort questions', 'easyq' );

		return sprintf( '<nav %s aria-label="%s">%s</nav>', get_block_wrapper_attributes(), esc_attr( $label ), $items );
	}

	/**
	 * Renders popular tags.
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @return string
	 */
	public static function tags( array $attributes ): string {
		$count  = is_numeric( $attributes['perPage'] ?? null ) && (int) $attributes['perPage'] > 0 ? (int) $attributes['perPage'] : Options::get_int( 'lists', 'page_size_tags' );
		$result = ( new Tags() )->popular( '', 1, $count );
		if ( array() === $result['tags'] ) {
			return '';
		}

		$show_counts = $attributes['showCounts'] ?? true;
		$items       = '';
		foreach ( $result['tags'] as $tag ) {
			$items .= sprintf(
				'<li><a href="%s" rel="tag">%s</a>%s</li>',
				esc_url( Urls::tag( $tag['name'] ) ),
				esc_html( Services::content_renderer()->text( $tag['name'] ) ),
				$show_counts ? ' <span>(' . esc_html( number_format_i18n( $tag['count'] ) ) . ')</span>' : ''
			);
		}

		return sprintf( '<ul %s>%s</ul>', get_block_wrapper_attributes(), $items );
	}

	/**
	 * Renders the categories as nested list.
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @return string
	 */
	public static function categories( array $attributes ): string {
		$parent_id = null;
		if ( ! empty( $attributes['showChildrenOfCurrent'] ) ) {
			$current = Block_Context::router()->current()?->category();
			if ( null === $current ) {
				return '';
			}
			$parent_id = $current->id;
		}

		$list = self::category_list( $parent_id, (bool) ( $attributes['showCounts'] ?? true ) );

		return '' === $list ? '' : sprintf( '<nav %s aria-label="%s">%s</nav>', get_block_wrapper_attributes(), esc_attr__( 'Categories', 'easyq' ), $list );
	}

	/**
	 * Renders questions similar to the current question.
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @param string               $content    Inner blocks.
	 * @param WP_Block             $block      Block.
	 * @return string
	 */
	public static function related_questions( array $attributes, string $content, WP_Block $block ): string {
		$question = Block_Context::post( $block );
		if ( null === $question || Post::QUESTION !== $question->base_type() ) {
			return '';
		}

		$count   = is_numeric( $attributes['count'] ?? null ) ? max( 1, (int) $attributes['count'] ) : 5;
		$results = Services::search()->search( (string) $question->title . ' ' . implode( ' ', $question->tag_list() ), 0, $count + 1 );
		$ids     = array_slice( array_values( array_diff( array_map( static fn ( $result ): int => $result->question_id, $results ), array( $question->id ) ) ), 0, $count );
		if ( array() === $ids ) {
			return '';
		}

		$posts = Services::posts()->find_many( $ids );
		$items = '';
		foreach ( $ids as $id ) {
			if ( isset( $posts[ $id ] ) ) {
				$items .= sprintf( '<li><a href="%s">%s</a></li>', esc_url( Urls::question( $posts[ $id ] ) ), esc_html( Services::content_renderer()->text( (string) $posts[ $id ]->title ) ) );
			}
		}

		return sprintf( '<section %s><h2>%s</h2><ul>%s</ul></section>', get_block_wrapper_attributes(), esc_html__( 'Related questions', 'easyq' ), $items );
	}

	/**
	 * Renders the numbers of questions, answers, comments and users.
	 *
	 * @return string
	 */
	public static function activity_count(): string {
		$numbers = array(
			/* translators: %s: number of questions. */
			_n( '%s question', '%s questions', Counts::get( Counts::QUESTIONS ), 'easyq' ) => Counts::get( Counts::QUESTIONS ),
			/* translators: %s: number of answers. */
			_n( '%s answer', '%s answers', Counts::get( Counts::ANSWERS ), 'easyq' )       => Counts::get( Counts::ANSWERS ),
			/* translators: %s: number of comments. */
			_n( '%s comment', '%s comments', Counts::get( Counts::COMMENTS ), 'easyq' )    => Counts::get( Counts::COMMENTS ),
			/* translators: %s: number of users. */
			_n( '%s user', '%s users', Counts::get( Counts::USERS_WITH_POINTS ), 'easyq' ) => Counts::get( Counts::USERS_WITH_POINTS ),
		);

		$items = '';
		foreach ( $numbers as $format => $number ) {
			$items .= sprintf( '<li>%s</li>', esc_html( sprintf( $format, Render::number( $number ) ) ) );
		}

		return sprintf( '<ul %s>%s</ul>', get_block_wrapper_attributes(), $items );
	}

	/**
	 * Renders the path to the current page.
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @param string               $content    Inner blocks.
	 * @param WP_Block             $block      Block.
	 * @return string
	 */
	public static function breadcrumbs( array $attributes, string $content, WP_Block $block ): string {
		$route = Block_Context::router()->current();
		$items = array( sprintf( '<a href="%s">%s</a>', esc_url( Routes::url( 'home' ) ), esc_html__( 'Questions', 'easyq' ) ) );

		$post     = Block_Context::post( $block );
		$category = null;
		if ( null !== $post && null !== $post->category_id ) {
			$category = Services::categories()->find( $post->category_id );
		} elseif ( null !== $route && null !== $route->category() ) {
			$category = $route->category();
		}

		if ( null !== $category ) {
			foreach ( Services::categories()->path( $category->id ) as $ancestor ) {
				$items[] = sprintf( '<a href="%s">%s</a>', esc_url( Urls::category( $ancestor ) ), esc_html( $ancestor->title ) );
			}
		}

		if ( count( $items ) < 2 ) {
			return '';
		}

		return sprintf(
			'<nav %s aria-label="%s">%s</nav>',
			get_block_wrapper_attributes(),
			esc_attr__( 'Breadcrumbs', 'easyq' ),
			implode( '<span aria-hidden="true">›</span>', $items )
		);
	}

	/**
	 * Renders the message after a form submission.
	 *
	 * @return string
	 */
	public static function notices(): string {
		$flash = Flash::current();

		// Forms show their own errors next to the fields.
		if ( null === $flash || ( 'error' === $flash['type'] && in_array( $flash['form'], Forms::INLINE_NOTICES, true ) ) ) {
			return '';
		}

		return sprintf(
			'<div %s role="%s"><p>%s</p></div>',
			get_block_wrapper_attributes(),
			'error' === $flash['type'] ? 'alert' : 'status',
			esc_html( $flash['message'] )
		);
	}

	/**
	 * Returns a nested list of categories below a parent.
	 *
	 * @param int|null $parent_id   Parent category.
	 * @param bool     $show_counts Whether to show question counts.
	 * @return string
	 */
	private static function category_list( ?int $parent_id, bool $show_counts ): string {
		$children = Services::categories()->children( $parent_id );
		if ( array() === $children ) {
			return '';
		}

		$items = '';
		foreach ( $children as $category ) {
			$items .= sprintf(
				'<li><a href="%s">%s</a>%s%s</li>',
				esc_url( Urls::category( $category ) ),
				esc_html( $category->title ),
				$show_counts ? ' <span>(' . esc_html( number_format_i18n( $category->question_count ) ) . ')</span>' : '',
				self::category_list( $category->id, $show_counts )
			);
		}

		return '<ul>' . $items . '</ul>';
	}

	/**
	 * Returns the sort orders of question lists with their names.
	 *
	 * @return array<string, string>
	 */
	private static function sort_labels(): array {
		return array(
			'recent'  => __( 'Recent questions', 'easyq' ),
			'hot'     => __( 'Hot', 'easyq' ),
			'votes'   => __( 'Most votes', 'easyq' ),
			'answers' => __( 'Most answers', 'easyq' ),
			'views'   => __( 'Most views', 'easyq' ),
		);
	}

	/**
	 * Returns the sort orders of user lists with their names.
	 *
	 * @return array<string, string>
	 */
	private static function user_sort_labels(): array {
		return array(
			'points'  => __( 'Top users', 'easyq' ),
			'newest'  => __( 'Newest users', 'easyq' ),
			'special' => __( 'Special users', 'easyq' ),
		);
	}

	/**
	 * Returns the title of a profile page.
	 *
	 * @param Route $route Route of a profile page.
	 * @return string
	 */
	private static function user_title( Route $route ): string {
		$name = (string) $route->user()?->display_name;

		return match ( $route->section ) {
			/* translators: %s: user name. */
			'questions' => sprintf( __( 'Questions by %s', 'easyq' ), $name ),
			/* translators: %s: user name. */
			'answers'   => sprintf( __( 'Answers by %s', 'easyq' ), $name ),
			/* translators: %s: user name. */
			'activity'  => sprintf( __( 'Recent activity by %s', 'easyq' ), $name ),
			default     => $name,
		};
	}

	/**
	 * Returns the kinds of unanswered lists with their names.
	 *
	 * @return array<string, string>
	 */
	private static function unanswered_labels(): array {
		return array(
			'unanswered' => __( 'Questions without answers', 'easyq' ),
			'unselected' => __( 'Questions without a selected answer', 'easyq' ),
			'unupvoted'  => __( 'Questions without upvoted answers', 'easyq' ),
		);
	}
}
