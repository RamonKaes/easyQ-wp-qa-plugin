<?php
/**
 * Rendering of user blocks.
 *
 * @package easyQ
 */

namespace EasyQ\Blocks\Render;

use EasyQ\Blocks\Block_Context;
use EasyQ\Blocks\Lists;
use EasyQ\Blocks\Render;
use EasyQ\Frontend\Urls;
use EasyQ\Points\Points;
use EasyQ\Services;
use EasyQ\Settings\Options;
use EasyQ\Users\User_Query;
use WP_Block;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Renders user lists, profiles and their parts.
 *
 * Every part reads the user from the context `easyq/userId`, or uses the user
 * of the current profile page.
 */
final class Users_Render {

	/**
	 * Renders a user with the inner blocks.
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @param string               $content    Inner blocks (unused; rendered with the user).
	 * @param WP_Block             $block      Block.
	 * @return string
	 */
	public static function user( array $attributes, string $content, WP_Block $block ): string {
		$id   = is_numeric( $attributes['userId'] ?? null ) ? (int) $attributes['userId'] : 0;
		$user = 0 < $id ? get_userdata( $id ) : Block_Context::router()->current()?->user();

		if ( ! $user instanceof WP_User ) {
			return '';
		}

		return sprintf(
			'<div %s>%s</div>',
			get_block_wrapper_attributes(),
			Render::inner_blocks( $block, array( 'easyq/userId' => $user->ID ) )
		);
	}

	/**
	 * Renders the user list container.
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @param string               $content    Inner blocks.
	 * @return string
	 */
	public static function user_query( array $attributes, string $content ): string {
		$query  = is_array( $attributes['query'] ?? null ) ? $attributes['query'] : array();
		$reason = self::permission_error( self::sort( $query ) );

		return sprintf( '<div %s>%s</div>', get_block_wrapper_attributes(), null === $reason ? $content : Forms::permission_message( $reason ) );
	}

	/**
	 * Renders each user of the list with the inner blocks.
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @param string               $content    Inner blocks (unused; rendered per user).
	 * @param WP_Block             $block      Block.
	 * @return string
	 */
	public static function user_template( array $attributes, string $content, WP_Block $block ): string {
		$list_id  = Block_Context::list_id( $block );
		$query    = is_array( $block->context['easyq/userQuery'] ?? null ) ? $block->context['easyq/userQuery'] : array();
		$sort     = self::sort( $query );
		$page_var = ! empty( $query['inherit'] ) ? 'easyq_page' : 'easyq_page_' . $list_id;
		$page     = Lists::requested_page( $page_var );
		$per_page = is_numeric( $query['perPage'] ?? null ) && (int) $query['perPage'] > 0 ? (int) $query['perPage'] : Options::get_int( 'lists', 'page_size_users' );
		$per_page = max( 1, min( User_Query::MAX_PER_PAGE, $per_page ) );

		$found = null === self::permission_error( $sort )
			? Services::user_query()->find( $sort, $page, $per_page )
			: array(
				'ids'   => array(),
				'total' => 0,
			);

		Lists::store( $list_id, $found['ids'], $found['total'], $page, $per_page, $page_var );

		if ( array() === $found['ids'] ) {
			return '';
		}

		cache_users( $found['ids'] );

		$items = '';
		foreach ( $found['ids'] as $id ) {
			$items .= sprintf( '<li>%s</li>', Render::inner_blocks( $block, array( 'easyq/userId' => $id ) ) );
		}

		return sprintf( '<ul %s>%s</ul>', get_block_wrapper_attributes( array( 'class' => 'wp-block-post-template' ) ), $items );
	}

	/**
	 * Renders the name of a user.
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @param string               $content    Inner blocks.
	 * @param WP_Block             $block      Block.
	 * @return string
	 */
	public static function user_name( array $attributes, string $content, WP_Block $block ): string {
		$user = Block_Context::user( $block );
		if ( null === $user ) {
			return '';
		}

		$name = esc_html( $user->display_name );

		return sprintf(
			'<div %s>%s</div>',
			get_block_wrapper_attributes(),
			( $attributes['isLink'] ?? true ) ? sprintf( '<a href="%s">%s</a>', esc_url( Urls::user( $user ) ), $name ) : $name
		);
	}

	/**
	 * Renders the avatar of a user.
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @param string               $content    Inner blocks.
	 * @param WP_Block             $block      Block.
	 * @return string
	 */
	public static function user_avatar( array $attributes, string $content, WP_Block $block ): string {
		$user = Block_Context::user( $block );
		if ( null === $user ) {
			return '';
		}

		$size    = is_numeric( $attributes['avatarSize'] ?? null ) ? max( 16, (int) $attributes['avatarSize'] ) : 96;
		$is_link = (bool) ( $attributes['isLink'] ?? false );

		// A linked avatar needs a text alternative; otherwise the name is shown next to it.
		$avatar = (string) get_avatar( $user->ID, $size, '', $is_link ? $user->display_name : '' );

		return sprintf(
			'<div %s>%s</div>',
			get_block_wrapper_attributes(),
			$is_link ? sprintf( '<a href="%s">%s</a>', esc_url( Urls::user( $user ) ), $avatar ) : $avatar
		);
	}

	/**
	 * Renders the points of a user.
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @param string               $content    Inner blocks.
	 * @param WP_Block             $block      Block.
	 * @return string
	 */
	public static function user_points( array $attributes, string $content, WP_Block $block ): string {
		$user = Block_Context::user( $block );
		if ( null === $user ) {
			return '';
		}

		$points = Services::points()->get( $user->ID );
		/* translators: %s: number of points. */
		$html = esc_html( sprintf( _n( '%s point', '%s points', $points, 'easyq' ), Render::number( $points ) ) );

		$rank = ! empty( $attributes['showRank'] ) ? Services::points()->rank( $user->ID ) : null;
		if ( null !== $rank ) {
			/* translators: %s: rank of the user by points. */
			$html .= ' ' . esc_html( sprintf( __( '(ranked #%s)', 'easyq' ), number_format_i18n( $rank ) ) );
		}

		return sprintf( '<div %s>%s</div>', get_block_wrapper_attributes(), $html );
	}

	/**
	 * Renders the title of a user.
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @param string               $content    Inner blocks.
	 * @param WP_Block             $block      Block.
	 * @return string
	 */
	public static function user_title( array $attributes, string $content, WP_Block $block ): string {
		$user  = Block_Context::user( $block );
		$title = null === $user ? '' : Services::user_titles()->title( $user->ID );

		return '' === $title ? '' : sprintf( '<div %s>%s</div>', get_block_wrapper_attributes(), esc_html( $title ) );
	}

	/**
	 * Renders the public profile of a user: biography, website and the profile fields the viewer may see.
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @param string               $content    Inner blocks.
	 * @param WP_Block             $block      Block.
	 * @return string
	 */
	public static function user_fields( array $attributes, string $content, WP_Block $block ): string {
		$user = Block_Context::user( $block );
		if ( null === $user ) {
			return '';
		}

		$items = '';
		if ( '' !== $user->user_url ) {
			$items .= self::item( __( 'Website', 'easyq' ), sprintf( '<a href="%1$s" rel="nofollow ugc">%2$s</a>', esc_url( $user->user_url ), esc_html( $user->user_url ) ) );
		}
		if ( '' !== $user->description ) {
			$items .= self::item( __( 'About', 'easyq' ), wpautop( esc_html( $user->description ) ) );
		}

		$viewer = Block_Context::viewer();
		foreach ( Services::user_fields()->all() as $field ) {
			$value = Services::user_fields()->value( $user->ID, $field );
			if ( '' === $value || ( null !== $field->permit && ! Services::permissions()->meets( $field->permit, $viewer ) ) ) {
				continue;
			}

			$html = match ( true ) {
				$field->is_link()       => sprintf( '<a href="%1$s" rel="nofollow ugc">%2$s</a>', esc_url( $value ), esc_html( $value ) ),
				$field->is_multi_line() => wpautop( esc_html( $value ) ),
				default                 => esc_html( $value ),
			};

			$items .= self::item( $field->display_label(), $html );
		}

		return '' === $items ? '' : sprintf( '<dl %s>%s</dl>', get_block_wrapper_attributes(), $items );
	}

	/**
	 * Renders membership, points, posts and votes of a user.
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @param string               $content    Inner blocks.
	 * @param WP_Block             $block      Block.
	 * @return string
	 */
	public static function user_stats( array $attributes, string $content, WP_Block $block ): string {
		$user = Block_Context::user( $block );
		if ( null === $user ) {
			return '';
		}

		$stats  = Services::points()->stats( $user->ID );
		$rank   = Services::points()->rank( $user->ID );
		$number = static fn ( string $key ): string => Render::number( $stats[ $key ] ?? 0 );
		$items  = '';

		$registered = (int) strtotime( $user->user_registered . ' UTC' );
		if ( $registered > 0 ) {
			$items .= self::item( __( 'Member for', 'easyq' ), esc_html( human_time_diff( $registered ) ) );
		}

		$points = esc_html( $number( 'points' ) );
		if ( null !== $rank ) {
			/* translators: %s: rank of the user by points. */
			$points .= ' ' . esc_html( sprintf( __( '(ranked #%s)', 'easyq' ), number_format_i18n( $rank ) ) );
		}
		$items .= self::item( __( 'Points', 'easyq' ), $points );
		$items .= self::item( __( 'Questions', 'easyq' ), esc_html( $number( Points::QUESTIONS ) ) );
		$items .= self::item(
			__( 'Answers', 'easyq' ),
			esc_html(
				sprintf(
					/* translators: 1: number of answers, 2: number of answers selected as best answer. */
					__( '%1$s (%2$s selected as best)', 'easyq' ),
					$number( Points::ANSWERS ),
					$number( Points::ANSWERS_SELECTED )
				)
			)
		);
		$items .= self::item( __( 'Comments', 'easyq' ), esc_html( $number( Points::COMMENTS ) ) );

		$given_up   = ( $stats[ Points::QUESTION_UPVOTES ] ?? 0 ) + ( $stats[ Points::ANSWER_UPVOTES ] ?? 0 ) + ( $stats[ Points::COMMENT_UPVOTES ] ?? 0 );
		$given_down = ( $stats[ Points::QUESTION_DOWNVOTES ] ?? 0 ) + ( $stats[ Points::ANSWER_DOWNVOTES ] ?? 0 ) + ( $stats[ Points::COMMENT_DOWNVOTES ] ?? 0 );
		$items     .= self::item( __( 'Votes given', 'easyq' ), esc_html( self::votes( $given_up, $given_down ) ) );
		$items     .= self::item( __( 'Votes received', 'easyq' ), esc_html( self::votes( $stats[ Points::UPVOTES_RECEIVED ] ?? 0, $stats[ Points::DOWNVOTES_RECEIVED ] ?? 0 ) ) );

		return sprintf( '<dl %s>%s</dl>', get_block_wrapper_attributes(), $items );
	}

	/**
	 * Renders links to the sections of a profile.
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @param string               $content    Inner blocks.
	 * @param WP_Block             $block      Block.
	 * @return string
	 */
	public static function user_navigation( array $attributes, string $content, WP_Block $block ): string {
		$user = Block_Context::user( $block );
		if ( null === $user ) {
			return '';
		}

		$route   = Block_Context::router()->current();
		$current = null !== $route && 'user' === $route->name && $route->user()?->ID === $user->ID ? (string) $route->section : null;
		$stats   = Services::points()->stats( $user->ID );
		$links   = array(
			''          => __( 'Profile', 'easyq' ),
			/* translators: %s: number of questions. */
			'questions' => sprintf( __( 'Questions (%s)', 'easyq' ), Render::number( $stats[ Points::QUESTIONS ] ?? 0 ) ),
			/* translators: %s: number of answers. */
			'answers'   => sprintf( __( 'Answers (%s)', 'easyq' ), Render::number( $stats[ Points::ANSWERS ] ?? 0 ) ),
			'activity'  => __( 'Recent activity', 'easyq' ),
		);

		$items = '';
		foreach ( $links as $section => $label ) {
			$items .= sprintf(
				'<a href="%s"%s>%s</a>',
				esc_url( Urls::user( $user, '' === $section ? null : $section ) ),
				$current === $section ? ' aria-current="page"' : '',
				esc_html( $label )
			);
		}

		return sprintf(
			'<nav %s aria-label="%s">%s</nav>',
			get_block_wrapper_attributes(),
			/* translators: %s: user name. */
			esc_attr( sprintf( __( 'Profile of %s', 'easyq' ), $user->display_name ) ),
			$items
		);
	}

	/**
	 * Returns the sort order of a user list.
	 *
	 * @param array<mixed> $query Block query.
	 * @return string One of the User_Query::SORTS keys.
	 */
	public static function sort( array $query ): string {
		if ( ! empty( $query['inherit'] ) && 'users' === Block_Context::router()->current()?->name ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading the list sort order.
			$sort = isset( $_GET['sort'] ) && is_string( $_GET['sort'] ) ? sanitize_key( $_GET['sort'] ) : '';
		} else {
			$sort = is_string( $query['sort'] ?? null ) ? $query['sort'] : '';
		}

		return array_key_exists( $sort, User_Query::SORTS ) ? $sort : 'points';
	}

	/**
	 * Returns why the viewer may not see a user list.
	 *
	 * @param string $sort Sort order.
	 * @return string|null Permission error reason, or null if allowed.
	 */
	public static function permission_error( string $sort ): ?string {
		$option = User_Query::SORTS[ $sort ] ?? null;

		return Services::permissions()->error( $option ?? 'permit_view_q_page', Block_Context::viewer(), null, null, false, true );
	}

	/**
	 * Returns a term and its description.
	 *
	 * @param string $term Term, not escaped.
	 * @param string $html Description, escaped HTML.
	 * @return string
	 */
	private static function item( string $term, string $html ): string {
		return sprintf( '<dt>%s</dt><dd>%s</dd>', esc_html( $term ), $html );
	}

	/**
	 * Formats up and down votes.
	 *
	 * @param int $up   Upvotes.
	 * @param int $down Downvotes.
	 * @return string
	 */
	private static function votes( int $up, int $down ): string {
		/* translators: 1: number of upvotes, 2: number of downvotes. */
		return sprintf( __( '%1$s up, %2$s down', 'easyq' ), Render::number( $up ), Render::number( $down ) );
	}
}
