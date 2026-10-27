<?php
/**
 * Rendering of post field blocks.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Blocks\Render;

use EasyQ\Blocks\Block_Context;
use EasyQ\Blocks\Render;
use EasyQ\Frontend\Routes;
use EasyQ\Frontend\URLs;
use EasyQ\Posts\Activity_Item;
use EasyQ\Posts\Post;
use EasyQ\Posts\Post_Service;
use EasyQ\Services;
use EasyQ\Settings\Options;
use EasyQ\Util\Text;
use WP_Block;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the parts of a question, answer or comment.
 *
 * Every block reads the post from the context `easyq/postId`, or uses the
 * question of the current page.
 *
 * @since 0.1.0
 */
final class Post_Fields {

	/**
	 * Renders the title of a question.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @param string               $content    Inner blocks.
	 * @param WP_Block             $block      Block.
	 * @return string
	 */
	public static function post_title( array $attributes, string $content, WP_Block $block ): string {
		$post = Block_Context::post( $block );
		if ( null === $post || Post::QUESTION !== $post->base_type() ) {
			return '';
		}

		$level = is_numeric( $attributes['level'] ?? null ) ? max( 1, min( 6, (int) $attributes['level'] ) ) : 2;
		$title = esc_html( Services::content_renderer()->text( (string) $post->title ) );

		if ( ! empty( $attributes['isLink'] ) ) {
			$title = sprintf( '<a href="%s">%s</a>', esc_url( URLs::question( $post ) ), $title );
		}

		return sprintf( '<h%1$d %2$s>%3$s</h%1$d>', $level, get_block_wrapper_attributes(), $title );
	}

	/**
	 * Renders the content of a post, or its edit form when the viewer edits it.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @param string               $content    Inner blocks.
	 * @param WP_Block             $block      Block.
	 * @return string
	 */
	public static function post_content( array $attributes, string $content, WP_Block $block ): string {
		$post = Block_Context::post( $block );
		if ( null === $post ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only selects which post shows its edit form.
		$editing = isset( $_GET['easyq_edit'] ) && is_string( $_GET['easyq_edit'] ) && absint( $_GET['easyq_edit'] ) === $post->id;
		if ( $editing && Render::rules( $post )['editable'] ) {
			return sprintf( '<div %s>%s</div>', get_block_wrapper_attributes(), Forms::edit_form( $post ) );
		}

		return sprintf(
			'<div %s>%s</div>',
			get_block_wrapper_attributes(),
			Services::content_renderer()->html( (string) $post->content, $post->format )
		);
	}

	/**
	 * Renders a short plain-text excerpt.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @param string               $content    Inner blocks.
	 * @param WP_Block             $block      Block.
	 * @return string
	 */
	public static function post_excerpt( array $attributes, string $content, WP_Block $block ): string {
		$post = Block_Context::post( $block );
		if ( null === $post || '' === trim( $post->text() ) ) {
			return '';
		}

		$length = is_numeric( $attributes['length'] ?? null ) ? max( 20, (int) $attributes['length'] ) : 200;

		return sprintf(
			'<p %s>%s</p>',
			get_block_wrapper_attributes(),
			esc_html( Services::content_renderer()->text( Text::shorten_line( $post->text(), $length ) ) )
		);
	}

	/**
	 * Renders the vote count with buttons to vote up and down.
	 *
	 * The buttons are forms that work without JavaScript; with JavaScript the
	 * interactivity module sends the vote in the background.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @param string               $content    Inner blocks.
	 * @param WP_Block             $block      Block.
	 * @return string
	 */
	public static function post_votes( array $attributes, string $content, WP_Block $block ): string {
		$post = Block_Context::post( $block );
		if ( null === $post ) {
			return '';
		}

		$enabled = match ( $post->base_type() ) {
			Post::QUESTION => Options::get_bool( 'viewing', 'voting_on_qs' ),
			Post::ANSWER   => Options::get_bool( 'viewing', 'voting_on_as' ),
			Post::COMMENT  => Options::get_bool( 'viewing', 'voting_on_cs' ),
			default        => false,
		};
		if ( ! $enabled ) {
			return '';
		}

		$viewer   = Block_Context::viewer();
		$user     = null === $viewer->user_id ? 0 : Services::votes()->vote_of( $post->id, $viewer->user_id );
		$can_vote = null === $viewer->user_id || user_can( $viewer->user_id, 'easyq_vote_post', $post );
		$can_down = null === $viewer->user_id || user_can( $viewer->user_id, 'easyq_vote_down_post', $post );

		wp_interactivity_state(
			'easyq',
			array(
				'restUrl' => esc_url_raw( rest_url( 'easyq/v1/' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			)
		);

		$context = array(
			'postId' => $post->id,
			'net'    => $post->net_votes,
			'up'     => $post->upvotes,
			'down'   => $post->downvotes,
			'vote'   => $user,
		);

		$up   = $can_vote ? self::vote_button( $post->id, 1, 1 === $user, __( 'Vote up', 'easyq' ), '▲' ) : '';
		$down = $can_vote && $can_down ? self::vote_button( $post->id, -1, -1 === $user, __( 'Vote down', 'easyq' ), '▼' ) : '';

		// Up and down votes separately (Q2A `votes_separated`) or their difference; the block can override the site setting.
		$display   = is_string( $attributes['display'] ?? null ) ? $attributes['display'] : '';
		$separated = in_array( $display, array( 'net', 'separated' ), true ) ? 'separated' === $display : Options::get_bool( 'viewing', 'votes_separated' );
		// The label is for screen readers; ARIA does not allow aria-label on a plain span.
		$count = static fn ( string $key, int $value, string $label ): string => sprintf(
			'<span><span class="screen-reader-text">%s </span><span data-wp-text="context.%s">%s</span></span>',
			esc_html( $label ),
			$key,
			esc_html( number_format_i18n( $value ) )
		);

		$html = $separated
			? $up . $count( 'up', $post->upvotes, __( 'Upvotes:', 'easyq' ) ) . $down . $count( 'down', $post->downvotes, __( 'Downvotes:', 'easyq' ) )
			: $up . $count( 'net', $post->net_votes, __( 'Votes:', 'easyq' ) ) . $down;

		return sprintf(
			'<div %s data-wp-interactive="easyq" %s>%s</div>',
			get_block_wrapper_attributes(),
			wp_interactivity_data_wp_context( $context ),
			$html
		);
	}

	/**
	 * Renders the number of answers.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @param string               $content    Inner blocks.
	 * @param WP_Block             $block      Block.
	 * @return string
	 */
	public static function post_answer_count( array $attributes, string $content, WP_Block $block ): string {
		$post = Block_Context::post( $block );
		if ( null === $post || Post::QUESTION !== $post->base_type() ) {
			return '';
		}

		$text = sprintf(
			/* translators: %s: number of answers. */
			_n( '%s answer', '%s answers', $post->answer_count, 'easyq' ),
			Render::number( $post->answer_count )
		);
		if ( null !== $post->selected_child_id ) {
			$text .= ' ' . __( '(solved)', 'easyq' );
		}

		return sprintf( '<div %s>%s</div>', get_block_wrapper_attributes(), esc_html( $text ) );
	}

	/**
	 * Renders the number of views.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @param string               $content    Inner blocks.
	 * @param WP_Block             $block      Block.
	 * @return string
	 */
	public static function post_view_count( array $attributes, string $content, WP_Block $block ): string {
		$post = Block_Context::post( $block );
		if ( null === $post || Post::QUESTION !== $post->base_type() || ! Options::get_bool( 'viewing', 'do_count_q_views' ) ) {
			return '';
		}

		$text = sprintf(
			/* translators: %s: number of views. */
			_n( '%s view', '%s views', $post->views, 'easyq' ),
			Render::number( $post->views )
		);

		return sprintf( '<div %s>%s</div>', get_block_wrapper_attributes(), esc_html( $text ) );
	}

	/**
	 * Renders when a post was written, and when and by whom it was last changed.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @param string               $content    Inner blocks.
	 * @param WP_Block             $block      Block.
	 * @return string
	 */
	public static function post_date( array $attributes, string $content, WP_Block $block ): string {
		$post = Block_Context::post( $block );
		if ( null === $post ) {
			return '';
		}

		$display = is_string( $attributes['display'] ?? null ) ? $attributes['display'] : '';
		$html    = sprintf(
			'<time datetime="%s">%s</time>',
			esc_attr( mysql_to_rfc3339( $post->created ) ),
			esc_html( Render::date( $post->created, $display ) )
		);

		$show_modified = ( $attributes['showModified'] ?? true ) && Options::get_bool( 'viewing', 'show_post_update_meta' );
		if ( $show_modified && null !== $post->updated && Post::UPDATE_SELECTED !== $post->update_type ) {
			$editor = null === $post->last_user_id ? false : get_userdata( $post->last_user_id );
			$html  .= ' · ' . sprintf(
				/* translators: %s: time of the change. */
				esc_html__( 'edited %s', 'easyq' ),
				sprintf( '<time datetime="%s">%s</time>', esc_attr( mysql_to_rfc3339( $post->updated ) ), esc_html( Render::date( $post->updated, $display ) ) )
			);
			if ( false !== $editor && $editor->ID !== $post->user_id ) {
				/* translators: %s: name of the user who changed the post. */
				$html .= ' ' . esc_html( sprintf( __( 'by %s', 'easyq' ), $editor->display_name ) );
			}
		}

		return sprintf( '<div %s>%s</div>', get_block_wrapper_attributes(), $html );
	}

	/**
	 * Renders the author with avatar and points.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @param string               $content    Inner blocks.
	 * @param WP_Block             $block      Block.
	 * @return string
	 */
	public static function post_author( array $attributes, string $content, WP_Block $block ): string {
		$post = Block_Context::post( $block );
		if ( null === $post ) {
			return '';
		}

		$user = null === $post->user_id ? false : get_userdata( $post->user_id );
		$name = esc_html( Render::author_name( $post ) );
		$html = '';

		if ( $attributes['showAvatar'] ?? true ) {
			$size  = is_numeric( $attributes['avatarSize'] ?? null ) ? (int) $attributes['avatarSize'] : 24;
			$html .= get_avatar( false === $user ? '' : $user->ID, $size, '', '' );
		}

		$html .= false === $user ? sprintf( '<span>%s</span>', $name ) : sprintf( '<a href="%s">%s</a>', esc_url( URLs::user( $user ) ), $name );

		if ( false !== $user && ( $attributes['showPoints'] ?? true ) && Options::get_bool( 'viewing', 'show_user_points' ) ) {
			$points = Services::points()->get( $user->ID );
			$html  .= sprintf(
				'<span>%s</span>',
				/* translators: %s: number of points. */
				esc_html( sprintf( _n( '%s point', '%s points', $points, 'easyq' ), Render::number( $points ) ) )
			);
		}

		return sprintf( '<div %s>%s</div>', get_block_wrapper_attributes(), $html );
	}

	/**
	 * Renders what happened to a question, when and by whom, e.g. "answered 5 minutes ago by Alex".
	 *
	 * In activity lists the item comes from the context `easyq/activity`;
	 * otherwise the creation of the post is shown.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @param string               $content    Inner blocks.
	 * @param WP_Block             $block      Block.
	 * @return string
	 */
	public static function post_activity( array $attributes, string $content, WP_Block $block ): string {
		$post = Block_Context::post( $block );
		if ( null === $post ) {
			return '';
		}

		$item  = Activity_Item::from_context( $post->id, $block->context['easyq/activity'] ?? null ) ?? Activity_Item::created( $post, $post->id );
		$other = $item->post_id === $post->id ? $post : Services::posts()->find( $item->post_id );
		if ( null === $other ) {
			return '';
		}

		$what = esc_html( $item->label( $other ) );
		if ( $other->id !== $post->id ) {
			$what = sprintf( '<a href="%s">%s</a>', esc_url( URLs::post( $other ) ), $what );
		}

		$when = sprintf( '<time datetime="%s">%s</time>', esc_attr( mysql_to_rfc3339( $item->time ) ), esc_html( Render::date( $item->time ) ) );

		$who = '';
		if ( $attributes['showAuthor'] ?? true ) {
			$who = self::activity_user( $item, $other, (bool) ( $attributes['showPoints'] ?? true ) );
		}

		$html = '' === $who
			? $what . ' ' . $when
			/* translators: 1: what happened, e.g. "answered", 2: when it happened, e.g. "5 minutes ago", 3: who did it. */
			: sprintf( esc_html__( '%1$s %2$s by %3$s', 'easyq' ), $what, $when, $who );

		return sprintf( '<div %s>%s</div>', get_block_wrapper_attributes(), $html );
	}

	/**
	 * Returns who created or changed a post, linked to their profile.
	 *
	 * @since 0.1.0
	 *
	 * @param Activity_Item $item        Activity.
	 * @param Post          $post        The created or changed post.
	 * @param bool          $show_points Whether to show the points of users.
	 * @return string HTML; empty if the user of a change is unknown.
	 */
	private static function activity_user( Activity_Item $item, Post $post, bool $show_points ): string {
		$user = null === $item->user_id ? false : get_userdata( $item->user_id );
		if ( false === $user ) {
			return null === $item->update_type ? esc_html( Render::author_name( $post ) ) : '';
		}

		$html = sprintf( '<a href="%s">%s</a>', esc_url( URLs::user( $user ) ), esc_html( $user->display_name ) );
		if ( $show_points && Options::get_bool( 'viewing', 'show_user_points' ) ) {
			$points = Services::points()->get( $user->ID );
			/* translators: %s: number of points. */
			$html .= ' ' . esc_html( sprintf( _n( '(%s point)', '(%s points)', $points, 'easyq' ), Render::number( $points ) ) );
		}

		return $html;
	}

	/**
	 * Renders the tags of a question.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @param string               $content    Inner blocks.
	 * @param WP_Block             $block      Block.
	 * @return string
	 */
	public static function post_tags( array $attributes, string $content, WP_Block $block ): string {
		$post = Block_Context::post( $block );
		if ( null === $post || array() === $post->tag_list() ) {
			return '';
		}

		$renderer = Services::content_renderer();
		$links    = array_map(
			static fn ( string $tag ): string => sprintf( '<a href="%s" rel="tag">%s</a>', esc_url( URLs::tag( $tag ) ), esc_html( $renderer->text( $tag ) ) ),
			$post->tag_list()
		);

		$separator = is_string( $attributes['separator'] ?? null ) ? $attributes['separator'] : ', ';

		return sprintf(
			'<div %s>%s</div>',
			get_block_wrapper_attributes( array( 'class' => 'wp-block-post-terms' ) ),
			self::affixed( $attributes, implode( sprintf( '<span class="wp-block-post-terms__separator">%s</span>', esc_html( $separator ) ), $links ) )
		);
	}

	/**
	 * Renders the category of a question.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @param string               $content    Inner blocks.
	 * @param WP_Block             $block      Block.
	 * @return string
	 */
	public static function post_category( array $attributes, string $content, WP_Block $block ): string {
		$post     = Block_Context::post( $block );
		$category = null === $post || null === $post->category_id ? null : Services::categories()->find( $post->category_id );
		if ( null === $category ) {
			return '';
		}

		return sprintf(
			'<div %s>%s</div>',
			get_block_wrapper_attributes( array( 'class' => 'wp-block-post-terms' ) ),
			self::affixed( $attributes, sprintf( '<a href="%s">%s</a>', esc_url( URLs::category( $category ) ), esc_html( $category->title ) ) )
		);
	}

	/**
	 * Renders status notes: closed, best answer, hidden or waiting for approval.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @param string               $content    Inner blocks.
	 * @param WP_Block             $block      Block.
	 * @return string
	 */
	public static function post_status( array $attributes, string $content, WP_Block $block ): string {
		$post = Block_Context::post( $block );
		if ( null === $post ) {
			return '';
		}

		$notes = array();

		if ( $post->is_hidden() ) {
			$notes[] = esc_html__( 'Hidden', 'easyq' );
		} elseif ( $post->is_queued() ) {
			$notes[] = esc_html__( 'Waiting for approval', 'easyq' );
		}

		if ( Post::QUESTION === $post->base_type() && Post_Service::is_closed( $post ) ) {
			$closer = null === $post->closed_by_id ? null : Services::posts()->find( $post->closed_by_id );
			if ( null !== $closer && Post::QUESTION === $closer->base_type() ) {
				$notes[] = sprintf(
					/* translators: %s: link to the original question. */
					esc_html__( 'Closed as a duplicate of %s', 'easyq' ),
					sprintf( '<a href="%s">%s</a>', esc_url( URLs::question( $closer ) ), esc_html( (string) $closer->title ) )
				);
			} elseif ( null !== $closer && Post::NOTE === $closer->type ) {
				/* translators: %s: reason the question was closed. */
				$notes[] = esc_html( sprintf( __( 'Closed: %s', 'easyq' ), (string) $closer->content ) );
			} else {
				$notes[] = esc_html__( 'Closed', 'easyq' );
			}
		}

		if ( Post::ANSWER === $post->base_type() ) {
			$question = Services::post_service()->question_of( $post );
			if ( null !== $question && $question->selected_child_id === $post->id ) {
				$notes[] = esc_html__( 'Best answer', 'easyq' );
			}
		}

		if ( array() === $notes ) {
			return '';
		}

		return sprintf(
			'<div %s>%s</div>',
			get_block_wrapper_attributes(),
			implode( '', array_map( static fn ( string $note ): string => '<span>' . $note . '</span>', $notes ) )
		);
	}

	/**
	 * Renders buttons for the actions the viewer can perform on a post.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @param string               $content    Inner blocks.
	 * @param WP_Block             $block      Block.
	 * @return string
	 */
	public static function post_actions( array $attributes, string $content, WP_Block $block ): string {
		$post = Block_Context::post( $block );
		if ( null === $post ) {
			return '';
		}

		$rules    = Render::rules( $post );
		$question = Services::post_service()->question_of( $post );
		$buttons  = array();

		if ( $rules['editable'] ) {
			$buttons[] = self::link_button(
				add_query_arg( 'easyq_edit', $post->id, URLs::post( $post ) ) . '#easyq-post-' . $post->id,
				__( 'Edit', 'easyq' )
			);
		}

		if ( Post::ANSWER === $post->type && null !== $question && Post::QUESTION === $question->type && Render::rules( $question )['answer_selectable'] ) {
			$selected  = $question->selected_child_id === $post->id;
			$buttons[] = $selected
				? Render::action_button( 'action', $question->id, __( 'Unselect', 'easyq' ), array( 'easyq_action' => 'unselect' ) )
				: Render::action_button(
					'action',
					$question->id,
					__( 'Select as best answer', 'easyq' ),
					array(
						'easyq_action' => 'select',
						'answer'       => $post->id,
					)
				);
		}

		if ( $rules['followable'] ) {
			$buttons[] = self::link_button( Routes::url( 'ask', null, null, array( 'follow' => $post->id ) ), __( 'Ask a related question', 'easyq' ) );
		}

		$simple = array(
			'approve'     => array( 'moderatable', __( 'Approve', 'easyq' ) ),
			'reject'      => array( 'moderatable', __( 'Reject', 'easyq' ) ),
			'flag'        => array( 'flag_button', __( 'Flag', 'easyq' ) ),
			'unflag'      => array( 'unflaggable', __( 'Unflag', 'easyq' ) ),
			'clear-flags' => array( 'flags_clearable', __( 'Clear flags', 'easyq' ) ),
			'reopen'      => array( 'reopenable', __( 'Reopen', 'easyq' ) ),
			'hide'        => array( 'hideable', __( 'Hide', 'easyq' ) ),
			'reshow'      => array( 'reshowable', __( 'Show again', 'easyq' ) ),
			'delete'      => array( 'deletable', __( 'Delete', 'easyq' ) ),
			'claim'       => array( 'claimable', __( 'Claim as mine', 'easyq' ) ),
		);

		foreach ( $simple as $action => [ $rule, $label ] ) {
			if ( $rules[ $rule ] ?? false ) {
				$buttons[] = Render::action_button( 'action', $post->id, $label, array( 'easyq_action' => $action ) );
			}
		}

		if ( $rules['closeable'] ) {
			$buttons[] = Forms::close_form( $post );
		}

		if ( array() === $buttons ) {
			return '';
		}

		return sprintf( '<div %s>%s</div>', get_block_wrapper_attributes( array( 'class' => 'wp-block-buttons' ) ), implode( '', $buttons ) );
	}

	/**
	 * Returns a vote button form.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $post_id   Post ID.
	 * @param int    $direction Direction of the button: 1 for up, -1 for down.
	 * @param bool   $pressed   Whether the viewer's current vote is in this direction.
	 * @param string $label   Accessible label.
	 * @param string $symbol  Visible symbol.
	 * @return string
	 */
	private static function vote_button( int $post_id, int $direction, bool $pressed, string $label, string $symbol ): string {
		return str_replace(
			'<form method="post" class="wp-block-button is-style-outline">',
			sprintf( '<form method="post" class="wp-block-button is-style-outline" data-wp-on--submit="actions.vote" %s>', wp_interactivity_data_wp_context( array( 'direction' => $direction ) ) ),
			Render::action_button(
				'vote',
				$post_id,
				$symbol,
				array( 'vote' => $pressed ? 0 : $direction ),
				array(
					'aria-label'                 => $label,
					'aria-pressed'               => $pressed ? 'true' : 'false',
					'data-wp-bind--aria-pressed' => 'state.isPressed',
				)
			)
		);
	}

	/**
	 * Returns a link styled as button.
	 *
	 * @since 0.1.0
	 *
	 * @param string $url   Address.
	 * @param string $label Text.
	 * @return string
	 */
	private static function link_button( string $url, string $label ): string {
		return sprintf(
			'<div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button has-small-font-size" href="%s">%s</a></div>',
			esc_url( $url ),
			esc_html( $label )
		);
	}

	/**
	 * Adds the prefix and suffix of a block to its content, with the markup of the core Post Terms block.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $attributes Attributes with `prefix` and `suffix`.
	 * @param string               $html       Content, escaped HTML.
	 * @return string
	 */
	private static function affixed( array $attributes, string $html ): string {
		$prefix = is_string( $attributes['prefix'] ?? null ) ? $attributes['prefix'] : '';
		$suffix = is_string( $attributes['suffix'] ?? null ) ? $attributes['suffix'] : '';

		return ( '' === $prefix ? '' : sprintf( '<span class="wp-block-post-terms__prefix">%s</span>', wp_kses_post( $prefix ) ) )
			. $html
			. ( '' === $suffix ? '' : sprintf( '<span class="wp-block-post-terms__suffix">%s</span>', wp_kses_post( $suffix ) ) );
	}
}
