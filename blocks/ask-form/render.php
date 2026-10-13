<?php
/**
 * Server-side rendering of the easyq/ask-form block.
 *
 * @package easyQ
 */

defined( 'ABSPATH' ) || exit;

echo EasyQ\Blocks\Renderer::render( 'ask-form', $attributes, $content, $block ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the renderer.
