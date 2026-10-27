<?php
/**
 * Server-side rendering of the easyq/user-navigation block.
 *
 * @package easyQ
 * @since 0.1.0
 */

defined( 'ABSPATH' ) || exit;

echo EasyQ\Blocks\Renderer::render( 'user-navigation', $attributes, $content, $block ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the renderer.
