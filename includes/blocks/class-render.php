<?php
/**
 * Rendering helpers of the blocks.
 *
 * @package easyQ
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
 */
final class Render {

	/**
	 * Renders the inner blocks of a block once with extra context, like core loops do.
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
	 * @param string                $form    Form name, see Form_Handler.
	 * @param int                   $post_id Post the action is about.
	 * @param string                $label   Button text.
	 * @param array<string, mixed>  $fields  Further hidden fields.
	 * @param array<string, string> $button  Further button attributes.
	 * @return string
	 */
	public static function action_button( string $form, int $post_id, string $label, array $fields = array(), array $button = array() ): string {
		$hidden = Form_Handler::hidden_fields( $form ) . sprintf( '<input type="hidden" name="easyq_post" value="%d">', $post_id );
		foreach ( $fields as $name => $value ) {
			$hidden .= sprintf( '<input type="hidden" name="%s" value="%s">', esc_attr( $name ), esc_attr( is_scalar( $value ) ? (string) $value : '' ) );
		}

		$attributes = '';
		foreach ( $button as $name => $value ) {
			$attributes .= sprintf( ' %s="%s"', esc_attr( $name ), esc_attr( $value ) );
		}

		return sprintf(
			'<form method="post" class="wp-block-button is-style-outline">%s<button type="submit" class="wp-block-button__link wp-element-button has-small-font-size"%s>%s</button></form>',
			$hidden,
			$attributes,
			esc_html( $label )
		);
	}

	/**
	 * Returns the displayed name of a post's author.
	 *
	 * @param Post $post Post.
	 * @return string
	 */
	public static function author_name( Post $post ): string {
		$user = null === $post->user_id ? false : get_userdata( $post->user_id );
		if ( false !== $user ) {
			return $user->display_name;
		}

		return '' !== (string) $post->author_name ? (string) $post->author_name : __( 'Anonymous', 'easyq' );
	}

	/**
	 * Formats a time: relative within the configured number of days, otherwise as date.
	 *
	 * @param string $mysql_gmt Time in UTC, MySQL format.
	 * @return string
	 */
	public static function date( string $mysql_gmt ): string {
		$timestamp = (int) strtotime( $mysql_gmt . ' UTC' );
		$days      = Options::get_int( 'viewing', 'show_full_date_days' );

		if ( time() - $timestamp < $days * DAY_IN_SECONDS ) {
			/* translators: %s: time difference, e.g. "5 minutes". */
			return sprintf( __( '%s ago', 'easyq' ), human_time_diff( $timestamp ) );
		}

		$format = get_option( 'date_format' );
		$date   = wp_date( is_string( $format ) && '' !== $format ? $format : 'Y-m-d', $timestamp );

		return false === $date ? '' : $date;
	}

	/**
	 * Formats a count compactly if configured, e.g. 1.2k.
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
