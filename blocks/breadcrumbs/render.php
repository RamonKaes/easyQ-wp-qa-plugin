<?php
/**
 * Server-side rendering of the easyq/breadcrumbs block.
 *
 * @package easyQ
 * @since 0.1.0
 */

defined( 'ABSPATH' ) || exit;

echo EasyQ\Blocks\Renderer::render( 'breadcrumbs', $attributes, $content, $block ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the renderer.
