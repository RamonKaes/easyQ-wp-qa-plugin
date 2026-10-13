<?php
/**
 * Server-side rendering of the easyq/comment-list block.
 *
 * @package easyQ
 */

defined( 'ABSPATH' ) || exit;

echo EasyQ\Blocks\Renderer::render( 'comment-list', $attributes, $content, $block ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the renderer.
