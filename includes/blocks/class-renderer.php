<?php
/**
 * Block render dispatcher.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Blocks;

use EasyQ\Blocks\Render\Forms;
use EasyQ\Blocks\Render\Lists_Render;
use EasyQ\Blocks\Render\Post_Fields;
use EasyQ\Blocks\Render\Users_Render;
use EasyQ\Blocks\Render\Widgets;
use WP_Block;

defined( 'ABSPATH' ) || exit;

/**
 * Routes the render callback of every block to the method that renders it.
 *
 * @since 0.1.0
 */
final class Renderer {

	/**
	 * Renders a block; the render callback of every easyQ block.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string               $content    Rendered inner blocks.
	 * @param WP_Block             $block      Block instance.
	 * @return string HTML.
	 */
	public static function render_callback( array $attributes, string $content, WP_Block $block ): string {
		return self::render( substr( $block->name ?? '', strlen( 'easyq/' ) ), $attributes, $content, $block );
	}

	/**
	 * Renders a block.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $name       Block name without namespace.
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string               $content    Rendered inner blocks.
	 * @param WP_Block             $block      Block instance.
	 * @return string HTML.
	 */
	public static function render( string $name, array $attributes, string $content, WP_Block $block ): string {
		$method = str_replace( '-', '_', $name );

		foreach ( array( Lists_Render::class, Post_Fields::class, Users_Render::class, Forms::class, Widgets::class ) as $class ) {
			if ( method_exists( $class, $method ) ) {
				$html = $class::$method( $attributes, $content, $block );

				return is_string( $html ) ? $html : '';
			}
		}

		return '';
	}
}
