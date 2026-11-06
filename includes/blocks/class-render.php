<?php
/**
 * Rendering helpers of the blocks.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Blocks;

use EasyQ\Frontend\Form_Handler;
use EasyQ\Posts\Post;
use EasyQ\Services;
use EasyQ\Settings\Options;
use WP_Block;

defined( 'ABSPATH' ) || exit;

/**
 * Markup shared by several blocks.
 *
 * Markup reuses core class names (buttons, pagination, terms) so the active
 * theme styles it; easyQ ships no stylesheet.
 *
 * @since 0.1.0
 */
final class Render {

	/**
	 * Loads the authors of posts and their points at once, as `update_post_author_caches()` does in core.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, Post> $posts    Posts about to be shown.
	 * @param array<?int>      $user_ids Further users about to be shown.
	 */
	public static function prime_authors( array $posts, array $user_ids = array() ): void {
		$ids = $user_ids;
		foreach ( $posts as $post ) {
			$ids[] = $post->user_id;
			$ids[] = $post->last_user_id;
		}
		$ids = array_values( array_unique( array_filter( $ids ) ) );

		cache_users( $ids );
		Services::points()->prime( $ids );
		// The titles of the authors depend on their levels, see User_Titles::title().
		if ( Options::get_bool( 'viewing', 'show_user_titles' ) ) {
			Services::user_levels()->prime_category_levels( $ids );
		}
	}

	/**
	 * Renders the inner blocks of a block once with extra context, like core loops do.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Block             $block   Block whose inner blocks to render.
	 * @param array<string, mixed> $context Context added for the inner blocks.
	 * @return string
	 */
	public static function inner_blocks( WP_Block $block, array $context ): string {
		$parsed = array(
			'blockName'    => 'core/null',
			'attrs'        => array(),
			'innerBlocks'  => is_array( $block->parsed_block['innerBlocks'] ?? null ) ? $block->parsed_block['innerBlocks'] : array(),
			'innerHTML'    => is_string( $block->parsed_block['innerHTML'] ?? null ) ? $block->parsed_block['innerHTML'] : '',
			'innerContent' => is_array( $block->parsed_block['innerContent'] ?? null ) ? $block->parsed_block['innerContent'] : array(),
		);

		return ( new WP_Block( $parsed, array_merge( $block->context, $context ) ) )->render( array( 'dynamic' => false ) );
	}

	/**
	 * Returns a form with one button that submits an action without JavaScript.
	 *
	 * @since 0.1.0
	 *
	 * @param string                $form    Form name, see Form_Handler.
	 * @param int                   $post_id Post the action is about.
	 * @param string                $label   Button text.
	 * @param array<string, mixed>  $fields  Further hidden fields.
	 * @param array<string, string> $button  Further button attributes.
	 * @param string                $form_attributes Further form attributes, escaped.
	 * @return string
	 */
	public static function action_button( string $form, int $post_id, string $label, array $fields = array(), array $button = array(), string $form_attributes = '' ): string {
		$hidden = Form_Handler::hidden_fields( $form ) . sprintf( '<input type="hidden" name="easyq_post" value="%d" />', $post_id );
		foreach ( $fields as $name => $value ) {
			$hidden .= sprintf( '<input type="hidden" name="%s" value="%s" />', esc_attr( $name ), esc_attr( is_scalar( $value ) ? (string) $value : '' ) );
		}

		$attributes = '';
		foreach ( $button as $name => $value ) {
			$attributes .= sprintf( ' %s="%s"', esc_attr( $name ), esc_attr( $value ) );
		}

		$font = self::button_font_size();

		return self::outline_button(
			sprintf(
				'<form method="post" class="wp-block-button is-style-outline"%s>%s<button type="submit" class="wp-block-button__link wp-element-button%s"%s%s>%s</button></form>',
				'' === $form_attributes ? '' : ' ' . $form_attributes,
				$hidden,
				$font[0],
				$font[1],
				$attributes,
				esc_html( $label )
			)
		);
	}

	/**
	 * Tells the script how to reach the REST API, for blocks that send requests from the browser.
	 *
	 * @since 0.1.0
	 */
	public static function rest_state(): void {
		wp_interactivity_state(
			'easyq',
			array(
				'restUrl' => esc_url_raw( rest_url( 'easyq/v1/' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	/**
	 * Returns a link with the outline style, as a core button block.
	 *
	 * @since 0.1.0
	 *
	 * @param string $url      Address.
	 * @param string $label    Text.
	 * @param bool   $nofollow Whether the link opens a form in place, which core links with `rel="nofollow"`, as the reply links of comments.
	 * @param string $extra    Further attributes of the link, escaped.
	 * @return string
	 */
	public static function link_button( string $url, string $label, bool $nofollow = false, string $extra = '' ): string {
		$font = self::button_font_size();

		return self::outline_button(
			sprintf(
				'<div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button%s"%s%s href="%s"%s>%s</a></div>',
				$font[0],
				$font[1],
				$nofollow ? ' rel="nofollow"' : '',
				esc_url( $url ),
				'' === $extra ? '' : ' ' . $extra,
				esc_html( $label )
			)
		);
	}

	/**
	 * Wraps buttons in a core buttons block, so that its layout and the theme's gap apply.
	 *
	 * @since 0.1.0
	 *
	 * @param string $buttons    Buttons, escaped HTML.
	 * @param string $classes    Further class names of the wrapper.
	 * @param string $attributes Further attributes of the wrapper, escaped.
	 * @return string
	 */
	public static function buttons( string $buttons, string $classes = '', string $attributes = '' ): string {
		$html = sprintf( '<div class="%s"%s>%s</div>', esc_attr( trim( 'wp-block-buttons ' . $classes ) ), '' === $attributes ? '' : ' ' . $attributes, $buttons );

		return render_block(
			array(
				'blockName'    => 'core/buttons',
				'attrs'        => array(),
				'innerBlocks'  => array(),
				'innerHTML'    => $html,
				'innerContent' => array( $html ),
			)
		);
	}

	/**
	 * Returns the font size of the block being rendered as attributes of its buttons.
	 *
	 * Themes give buttons a font size of their own, so the size of the block does not reach its
	 * buttons by inheritance. The preset becomes its class and a custom size an inline style, as
	 * the typography support of core does for a button block; without a size the theme's applies.
	 *
	 * @since 0.1.0
	 *
	 * @return array{0: string, 1: string} Class names and a style attribute, each escaped and with a leading space, or empty.
	 */
	public static function button_font_size(): array {
		$block      = \WP_Block_Supports::$block_to_render;
		$attributes = is_array( $block ) && is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		$style      = is_array( $attributes['style'] ?? null ) && is_array( $attributes['style']['typography'] ?? null ) ? $attributes['style']['typography'] : array();
		$custom     = $style['fontSize'] ?? null;
		$preset     = $attributes['fontSize'] ?? null;

		if ( is_string( $preset ) && '' !== $preset ) {
			$styles = wp_style_engine_get_styles( array( 'typography' => array( 'fontSize' => 'var:preset|font-size|' . $preset ) ) );
		} elseif ( is_string( $custom ) && '' !== $custom ) {
			$size   = wp_get_typography_font_size_value( array( 'size' => $custom ) );
			$styles = wp_style_engine_get_styles( array( 'typography' => array( 'fontSize' => is_string( $size ) ? $size : $custom ) ) );
		} else {
			return array( '', '' );
		}

		return array(
			is_string( $styles['classnames'] ?? null ) && '' !== $styles['classnames'] ? ' ' . esc_attr( $styles['classnames'] ) : '',
			is_string( $styles['css'] ?? null ) && '' !== $styles['css'] ? sprintf( ' style="%s"', esc_attr( $styles['css'] ) ) : '',
		);
	}

	/**
	 * Renders the markup of a button with the outline style as a core button block.
	 *
	 * Core gives the block a class and styles of its own for the variation of the theme, see
	 * `wp_render_block_style_variation_support_styles()`; without them the fill of the theme wins.
	 *
	 * @since 0.1.0
	 *
	 * @param string $html Markup of the button with its wrapper, escaped.
	 * @return string
	 */
	public static function outline_button( string $html ): string {
		return render_block(
			array(
				'blockName'    => 'core/button',
				'attrs'        => array( 'className' => 'is-style-outline' ),
				'innerBlocks'  => array(),
				'innerHTML'    => $html,
				'innerContent' => array( $html ),
			)
		);
	}

	/**
	 * Returns the displayed name of a post's author.
	 *
	 * @since 0.1.0
	 *
	 * @param Post $post Post.
	 * @return string
	 */
	public static function author_name( Post $post ): string {
		$user = null === $post->user_id ? false : get_userdata( $post->user_id );
		if ( false !== $user ) {
			return $user->display_name;
		}

		return '' === (string) $post->author_name ? __( 'Anonymous', 'easyq' ) : (string) $post->author_name;
	}

	/**
	 * Formats a time: relative within the configured number of days, otherwise as date.
	 *
	 * @since 0.1.0
	 *
	 * @param string $mysql_gmt Time in UTC, MySQL format.
	 * @param string $display   `relative` ("5 minutes ago"), `absolute`, or empty for the site setting (relative for recent dates).
	 * @param string $format    PHP date format of absolute dates, empty for the site's date format.
	 * @return string
	 */
	public static function date( string $mysql_gmt, string $display = '', string $format = '' ): string {
		$timestamp = (int) strtotime( $mysql_gmt . ' UTC' );
		$days      = Options::get_int( 'viewing', 'show_full_date_days' );
		$relative  = match ( $display ) {
			'relative' => true,
			'absolute' => false,
			default    => time() - $timestamp < $days * DAY_IN_SECONDS,
		};

		if ( $relative ) {
			/* translators: %s: time difference, e.g. "5 minutes". */
			return sprintf( __( '%s ago', 'easyq' ), human_time_diff( $timestamp ) );
		}

		if ( '' === $format ) {
			$site   = get_option( 'date_format' );
			$format = is_string( $site ) && '' !== $site ? $site : 'Y-m-d';
		}
		$date = wp_date( $format, $timestamp );

		return false === $date ? '' : $date;
	}

	/**
	 * Returns the heading of a block, or nothing when the block hides it.
	 *
	 * Blocks with a heading have the attributes `showTitle` and `level`, as the core Comments Title block.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string               $text       Heading, not escaped.
	 * @param string               $extra      Further attributes of the heading, escaped.
	 * @param int                  $level      Level without the attribute.
	 * @return string
	 */
	public static function heading( array $attributes, string $text, string $extra = '', int $level = 2 ): string {
		if ( ! ( $attributes['showTitle'] ?? true ) ) {
			return '';
		}

		$level = is_numeric( $attributes['level'] ?? null ) ? max( 1, min( 6, (int) $attributes['level'] ) ) : $level;

		return sprintf( '<h%1$d%2$s>%3$s</h%1$d>', $level, '' === $extra ? '' : ' ' . $extra, esc_html( $text ) );
	}

	/**
	 * Returns the rows a block shows, from its attribute `rows`, in the order of the block.
	 *
	 * Without the attribute every row is shown; unknown keys are ignored.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string[]             $keys       Keys of all rows, in order.
	 * @return string[]
	 */
	public static function rows( array $attributes, array $keys ): array {
		if ( ! is_array( $attributes['rows'] ?? null ) ) {
			return $keys;
		}

		return array_values( array_intersect( $keys, array_filter( $attributes['rows'], 'is_string' ) ) );
	}

	/**
	 * Returns the `target` and `rel` attributes of a link, as the core blocks with a link target.
	 *
	 * `rel` comes from the attribute of the same name, see the core Post Title block; core adds
	 * nothing for `_blank`, as browsers imply `noopener` for it.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string Escaped attributes with a leading space, or empty.
	 */
	public static function link_attributes( array $attributes ): string {
		$html = '_blank' === ( $attributes['linkTarget'] ?? '_self' ) ? ' target="_blank"' : '';
		$rel  = is_string( $attributes['rel'] ?? null ) ? trim( $attributes['rel'] ) : '';

		return '' === $rel ? $html : $html . sprintf( ' rel="%s"', esc_attr( $rel ) );
	}

	/**
	 * Returns the avatar image of a user with the class of the core Avatar block,
	 * so the theme's avatar styles (e.g. the border radius) apply.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $user_id User ID; 0 for an anonymous author.
	 * @param int    $size    Size in pixels.
	 * @param string $alt     Alternative text; empty when the name is shown next to the avatar.
	 * @return string
	 */
	public static function avatar( int $user_id, int $size, string $alt ): string {
		$avatar = get_avatar( 0 === $user_id ? '' : $user_id, $size, '', $alt, array( 'class' => 'wp-block-avatar__image' ) );

		return false === $avatar ? '' : $avatar;
	}

	/**
	 * Formats a count compactly if configured, e.g. 1.2k.
	 *
	 * @since 0.1.0
	 *
	 * @param int $count Count.
	 * @return string
	 */
	public static function number( int $count ): string {
		if ( Options::get_bool( 'viewing', 'show_compact_numbers' ) && $count >= 1000 ) {
			/* translators: %s: number of thousands, e.g. 1.2. */
			return sprintf( __( '%sk', 'easyq' ), number_format_i18n( $count / 1000, 1 ) );
		}

		return number_format_i18n( $count );
	}

	/**
	 * Returns the post rules of the viewer for a post.
	 *
	 * @since 0.1.0
	 *
	 * @param Post $post Post.
	 * @return array<string, bool>
	 */
	public static function rules( Post $post ): array {
		$parent = null === $post->parent_id ? null : Services::posts()->find( $post->parent_id );

		return Services::permissions()->post_rules(
			$post,
			Block_Context::viewer(),
			$parent,
			null === $parent ? array() : Services::posts()->children( $parent->id ),
			Services::posts()->children( $post->id )
		);
	}
}
