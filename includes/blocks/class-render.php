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

		return self::outline_button(
			sprintf(
				'<form method="post" class="wp-block-button is-style-outline"%s>%s<button type="submit" class="wp-block-button__link wp-element-button has-small-font-size"%s>%s</button></form>',
				'' === $form_attributes ? '' : ' ' . $form_attributes,
				$hidden,
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
	 * @return string
	 */
	public static function date( string $mysql_gmt, string $display = '' ): string {
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

		$format = get_option( 'date_format' );
		$date   = wp_date( is_string( $format ) && '' !== $format ? $format : 'Y-m-d', $timestamp );

		return false === $date ? '' : $date;
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
