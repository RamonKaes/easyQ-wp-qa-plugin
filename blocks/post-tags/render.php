<?php
/**
 * Server-side rendering of the easyq/post-tags block.
 *
 * @package easyQ
 * @since 0.1.0
 */

defined( 'ABSPATH' ) || exit;

echo EasyQ\Blocks\Renderer::render( 'post-tags', $attributes, $content, $block ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the renderer.
