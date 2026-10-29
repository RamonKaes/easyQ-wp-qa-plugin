<?php
/**
 * Moderation list table.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Admin;

use EasyQ\Actor;
use EasyQ\Frontend\URLs;
use EasyQ\Posts\Post;
use EasyQ\Services;
use EasyQ\Util\IP;
use WP_List_Table;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( WP_List_Table::class ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Lists questions, answers and comments with views, filters, row and bulk
 * actions, like the core Comments screen.
 *
 * @since 0.1.0
 */
final class Posts_List_Table extends WP_List_Table {

	/**
	 * Row actions with their labels; the keys are the REST actions.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string> Post rule by action.
	 */
	private const RULES = array(
		'approve'     => 'moderatable',
		'reject'      => 'moderatable',
		'clear-flags' => 'flags_clearable',
		'hide'        => 'hideable',
		'reshow'      => 'reshowable',
		'reopen'      => 'reopenable',
		'delete'      => 'deletable',
	);

	/**
	 * The moderator.
	 *
	 * @since 0.1.0
	 *
	 * @var Actor
	 */
	private Actor $actor;

	/**
	 * Current filters.
	 *
	 * @since 0.1.0
	 *
	 * @var array{view: string, type: string, category_id: int|null, user_id: int|null, ip: string|null, search: string, month: string}
	 */
	private array $filters;

	/**
	 * Sets up the table.
	 *
	 * @since 0.1.0
	 *
	 * @param array{view: string, type: string, category_id: int|null, user_id: int|null, ip: string|null, search: string, month: string} $filters Current filters.
	 */
	public function __construct( array $filters ) {
		parent::__construct(
			array(
				'plural'   => 'easyq-posts',
				'singular' => 'easyq-post',
				'screen'   => Posts_Screen::PAGE,
			)
		);

		$this->actor   = Actor::current();
		$this->filters = $filters;
	}

	/**
	 * Returns the labels of the actions.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string>
	 */
	public static function action_labels(): array {
		return array(
			'approve'     => __( 'Approve', 'easyq' ),
			'reject'      => __( 'Reject', 'easyq' ),
			'clear-flags' => __( 'Clear flags', 'easyq' ),
			'hide'        => __( 'Hide', 'easyq' ),
			'reshow'      => __( 'Show again', 'easyq' ),
			'reopen'      => __( 'Reopen', 'easyq' ),
			'delete'      => __( 'Delete permanently', 'easyq' ),
		);
	}

	/**
	 * Loads the posts of the current page.
	 *
	 * @since 0.1.0
	 */
	public function prepare_items(): void {
		$per_page = $this->get_items_per_page( 'easyq_posts_per_page', 20 );
		$result   = Services::moderation_query()->find(
			$this->actor,
			$this->filters + array(
				'page'     => $this->get_pagenum(),
				'per_page' => $per_page,
			)
		);

		$this->items           = $result['posts'];
		$this->_column_headers = array( $this->get_columns(), array(), array(), 'content' );
		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
			)
		);
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
			'cb'       => '<input type="checkbox" />',
			'author'   => __( 'Author', 'easyq' ),
			'content'  => __( 'Post', 'easyq' ),
			'response' => __( 'In response to', 'easyq' ),
			'category' => __( 'Category', 'easyq' ),
			'date'     => __( 'Date', 'easyq' ),
		);
	}

	/**
	 * Returns the classes of the table; without fixed column widths, so that author
	 * names fit, as core gives that column only a tenth of the width.
	 *
	 * @since 0.1.0
	 *
	 * @return string[]
	 */
	protected function get_table_classes(): array {
		return array_values( array_diff( parent::get_table_classes(), array( 'fixed' ) ) );
	}

	/**
	 * Returns the text shown when no post matches.
	 *
	 * @since 0.1.0
	 */
	public function no_items(): void {
		match ( $this->filters['view'] ) {
			'pending' => esc_html_e( 'No posts are awaiting approval.', 'easyq' ),
			'flagged' => esc_html_e( 'No posts are flagged.', 'easyq' ),
			'hidden'  => esc_html_e( 'No posts are hidden.', 'easyq' ),
			default   => esc_html_e( 'No posts found.', 'easyq' ),
		};
	}

	/**
	 * Returns the view links with their counts.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string>
	 */
	protected function get_views(): array {
		$counts = Services::moderation_query()->counts( $this->actor );
		$labels = array(
			/* translators: %s: number of posts. */
			'all'     => _nx_noop( 'All <span class="count">(%s)</span>', 'All <span class="count">(%s)</span>', 'posts', 'easyq' ),
			/* translators: %s: number of posts. */
			'pending' => _nx_noop( 'Pending <span class="count">(%s)</span>', 'Pending <span class="count">(%s)</span>', 'posts', 'easyq' ),
			/* translators: %s: number of posts. */
			'flagged' => _nx_noop( 'Flagged <span class="count">(%s)</span>', 'Flagged <span class="count">(%s)</span>', 'posts', 'easyq' ),
			/* translators: %s: number of posts. */
			'hidden'  => _nx_noop( 'Hidden <span class="count">(%s)</span>', 'Hidden <span class="count">(%s)</span>', 'posts', 'easyq' ),
			/* translators: %s: number of questions. */
			'closed'  => _nx_noop( 'Closed <span class="count">(%s)</span>', 'Closed <span class="count">(%s)</span>', 'questions', 'easyq' ),
		);

		$views = array();
		foreach ( $labels as $view => $label ) {
			$current        = $view === $this->filters['view'];
			$views[ $view ] = sprintf(
				'<a href="%1$s"%2$s>%3$s</a>',
				esc_url( Posts_Screen::url( 'all' === $view ? array() : array( 'view' => $view ) ) ),
				$current ? ' class="current" aria-current="page"' : '',
				sprintf( translate_nooped_plural( $label, $counts[ $view ], 'easyq' ), number_format_i18n( $counts[ $view ] ) )
			);
		}

		return $views;
	}

	/**
	 * Returns the bulk actions of the current view.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string>
	 */
	protected function get_bulk_actions(): array {
		$actions = match ( $this->filters['view'] ) {
			'pending' => array( 'approve', 'reject' ),
			'flagged' => array( 'clear-flags', 'hide' ),
			'hidden'  => array( 'reshow', 'delete' ),
			'closed'  => array( 'reopen', 'hide' ),
			default   => array( 'approve', 'reject', 'hide', 'reshow' ),
		};

		return array_intersect_key( self::action_labels(), array_flip( $actions ) );
	}

	/**
	 * Renders the filters above the table.
	 *
	 * @since 0.1.0
	 *
	 * @param string $which Above or below the table.
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}

		echo '<div class="alignleft actions">';

		printf( '<label for="easyq-filter-type" class="screen-reader-text">%s</label>', esc_html__( 'Filter by type', 'easyq' ) );
		echo '<select id="easyq-filter-type" name="type">';
		printf( '<option value="">%s</option>', esc_html__( 'All types', 'easyq' ) );
		foreach ( array(
			'question' => __( 'Questions', 'easyq' ),
			'answer'   => __( 'Answers', 'easyq' ),
			'comment'  => __( 'Comments', 'easyq' ),
		) as $type => $label ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $type ), selected( $this->filters['type'], $type, false ), esc_html( $label ) );
		}
		echo '</select>';

		$categories = Services::categories()->tree();
		if ( array() !== $categories ) {
			printf( '<label for="easyq-filter-category" class="screen-reader-text">%s</label>', esc_html__( 'Filter by category', 'easyq' ) );
			echo '<select id="easyq-filter-category" name="category">';
			printf( '<option value="">%s</option>', esc_html__( 'All categories', 'easyq' ) );
			foreach ( $categories as [ $category, $depth ] ) {
				printf(
					'<option value="%1$d"%2$s>%3$s%4$s</option>',
					(int) $category->id,
					selected( $this->filters['category_id'], $category->id, false ),
					esc_html( str_repeat( '— ', $depth ) ),
					esc_html( $category->title )
				);
			}
			echo '</select>';
		}

		printf( '<label for="easyq-filter-month" class="screen-reader-text">%s</label>', esc_html__( 'Filter by date', 'easyq' ) );
		echo '<select id="easyq-filter-month" name="month">';
		printf( '<option value="">%s</option>', esc_html__( 'All dates', 'easyq' ) );
		foreach ( Services::moderation_query()->months() as $month ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $month ),
				selected( $this->filters['month'], $month, false ),
				esc_html( date_i18n( _x( 'F Y', 'month filter', 'easyq' ), (int) gmmktime( 0, 0, 0, (int) substr( $month, 4, 2 ), 1, (int) substr( $month, 0, 4 ) ) ) )
			);
		}
		echo '</select>';

		submit_button( __( 'Filter', 'easyq' ), '', 'filter_action', false );
		echo '</div>';
	}

	/**
	 * Renders a row.
	 *
	 * @since 0.1.0
	 *
	 * @param Post $item Post.
	 */
	public function single_row( $item ): void {
		$classes = array( $item->is_queued() ? 'unapproved' : 'approved' );

		printf( '<tr id="easyq-post-%1$d" class="%2$s">', (int) $item->id, esc_attr( implode( ' ', $classes ) ) );
		$this->single_row_columns( $item );
		echo '</tr>';
	}

	/**
	 * Returns a short name for the row header, instead of its whole content with
	 * states, excerpt and row actions.
	 *
	 * @since 0.1.0
	 *
	 * @param Post $item Post.
	 * @return string
	 */
	protected function get_primary_column_aria_label( $item ): string {
		if ( null !== $item->title && '' !== $item->title ) {
			return $item->title;
		}

		$type = match ( $item->base_type() ) {
			Post::ANSWER => __( 'Answer', 'easyq' ),
			default      => __( 'Comment', 'easyq' ),
		};

		/* translators: 1: type of post, 2: start of its text. */
		return sprintf( __( '%1$s: %2$s', 'easyq' ), $type, wp_trim_words( wp_strip_all_tags( (string) $item->content ), 8 ) );
	}

	/**
	 * Renders the checkbox column.
	 *
	 * @since 0.1.0
	 *
	 * @param Post $item Post.
	 * @return string
	 */
	public function column_cb( $item ): string {
		return sprintf(
			'<label class="screen-reader-text" for="easyq-cb-%1$d">%2$s</label><input type="checkbox" id="easyq-cb-%1$d" name="post[]" value="%1$d" />',
			(int) $item->id,
			/* translators: %d: post ID. */
			esc_html( sprintf( __( 'Select post %d', 'easyq' ), $item->id ) )
		);
	}

	/**
	 * Renders the author: name and avatar, a link to their other posts, and the IP
	 * address of anonymous posts for users who may see it.
	 *
	 * @since 0.1.0
	 *
	 * @param Post $item Post.
	 * @return string
	 */
	public function column_author( Post $item ): string {
		$user = null === $item->user_id ? false : get_userdata( $item->user_id );
		if ( false !== $user ) {
			$html = sprintf(
				'<strong>%1$s %2$s</strong><br><a href="%3$s">%4$s</a>',
				get_avatar( $user->ID, 32 ),
				esc_html( $user->display_name ),
				esc_url( Posts_Screen::url( array( 'author' => $user->ID ) ) ),
				esc_html__( 'All posts by this user', 'easyq' )
			);
		} else {
			$html = sprintf( '<strong>%s</strong>', esc_html( '' === (string) $item->author_name ? __( 'Anonymous', 'easyq' ) : (string) $item->author_name ) );
		}

		$ip = IP::from_binary( $item->created_ip );
		if ( null === $item->user_id && null !== $ip && current_user_can( 'easyq_view_ips' ) ) {
			$html .= sprintf( '<br><a href="%1$s">%2$s</a>', esc_url( Posts_Screen::url( array( 'ip' => $ip ) ) ), esc_html( $ip ) );
		}

		return $html;
	}

	/**
	 * Renders the post: its states, title, an excerpt and the row actions.
	 *
	 * @since 0.1.0
	 *
	 * @param Post $item Post.
	 * @return string
	 */
	public function column_content( Post $item ): string {
		$states = array();
		if ( $item->is_queued() ) {
			$states[] = __( 'Pending', 'easyq' );
		}
		if ( $item->is_hidden() ) {
			$states[] = __( 'Hidden', 'easyq' );
		}
		if ( Post::QUESTION === $item->base_type() && null !== $item->closed_by_id ) {
			$states[] = __( 'Closed', 'easyq' );
		}
		if ( $item->flag_count > 0 ) {
			/* translators: %s: number of flags. */
			$states[] = sprintf( _n( '%s flag', '%s flags', $item->flag_count, 'easyq' ), number_format_i18n( $item->flag_count ) );
		}

		$type = match ( $item->base_type() ) {
			Post::QUESTION => __( 'Question', 'easyq' ),
			Post::ANSWER   => __( 'Answer', 'easyq' ),
			default        => __( 'Comment', 'easyq' ),
		};

		$html = sprintf( '<p><span class="post-state">%1$s</span>%2$s</p>', esc_html( $type ), array() === $states ? '' : ' — <strong>' . esc_html( implode( ', ', $states ) ) . '</strong>' );
		if ( null !== $item->title && '' !== $item->title ) {
			$html .= sprintf( '<p><strong>%s</strong></p>', esc_html( $item->title ) );
		}
		$html .= sprintf( '<p>%s</p>', esc_html( wp_trim_words( wp_strip_all_tags( (string) $item->content ), 40 ) ) );

		return $html . $this->post_actions( $item );
	}

	/**
	 * Renders the question an answer or comment belongs to.
	 *
	 * @since 0.1.0
	 *
	 * @param Post $item Post.
	 * @return string
	 */
	public function column_response( Post $item ): string {
		if ( Post::QUESTION === $item->base_type() ) {
			return '<span aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__( 'A question', 'easyq' ) . '</span>';
		}

		$question = Services::post_service()->question_of( $item );
		if ( null === $question ) {
			return '';
		}

		return sprintf( '<a href="%1$s">%2$s</a>', esc_url( URLs::question( $question ) ), esc_html( (string) $question->title ) );
	}

	/**
	 * Renders the category, with a link that filters by it.
	 *
	 * @since 0.1.0
	 *
	 * @param Post $item Post.
	 * @return string
	 */
	public function column_category( Post $item ): string {
		$category = null === $item->category_id ? null : Services::categories()->find( $item->category_id );
		if ( null === $category ) {
			return '<span aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__( 'No category', 'easyq' ) . '</span>';
		}

		return sprintf( '<a href="%1$s">%2$s</a>', esc_url( Posts_Screen::url( array( 'category' => $category->id ) ) ), esc_html( $category->title ) );
	}

	/**
	 * Renders the date the post was created.
	 *
	 * @since 0.1.0
	 *
	 * @param Post $item Post.
	 * @return string
	 */
	public function column_date( Post $item ): string {
		$date_format = get_option( 'date_format' );
		$time_format = get_option( 'time_format' );

		return esc_html(
			sprintf(
				/* translators: 1: date, 2: time. */
				__( '%1$s at %2$s', 'easyq' ),
				get_date_from_gmt( $item->created, is_string( $date_format ) ? $date_format : 'Y-m-d' ),
				get_date_from_gmt( $item->created, is_string( $time_format ) ? $time_format : 'H:i' )
			)
		);
	}

	/**
	 * Returns the row actions the moderator may perform on a post.
	 *
	 * @since 0.1.0
	 *
	 * @param Post $item Post.
	 * @return string
	 */
	private function post_actions( Post $item ): string {
		// Only hidden posts can be deleted, and only without answers or comments.
		$children = $item->is_hidden() ? Services::posts()->children( $item->id ) : array();
		$rules    = Services::permissions()->post_rules( $item, $this->actor, null, array(), $children );
		$labels   = self::action_labels();
		$actions  = array();

		foreach ( self::RULES as $action => $rule ) {
			if ( empty( $rules[ $rule ] ) ) {
				continue;
			}

			$actions[ $action ] = sprintf(
				'<a href="%1$s" class="%2$s" aria-label="%3$s">%4$s</a>',
				esc_url( Posts_Screen::action_url( $action, $item->id ) ),
				'delete' === $action || 'reject' === $action ? 'delete' : '',
				/* translators: 1: action, 2: post ID. */
				esc_attr( sprintf( __( '%1$s post %2$d', 'easyq' ), $labels[ $action ], $item->id ) ),
				esc_html( $labels[ $action ] )
			);
		}

		if ( $rules['viewable'] ) {
			$actions['view'] = sprintf(
				'<a href="%1$s" aria-label="%2$s">%3$s</a>',
				esc_url( URLs::post( $item ) ),
				/* translators: %d: post ID. */
				esc_attr( sprintf( __( 'View post %d', 'easyq' ), $item->id ) ),
				esc_html__( 'View', 'easyq' )
			);
		}

		return $this->row_actions( $actions );
	}
}
