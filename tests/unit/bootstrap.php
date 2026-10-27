<?php
/**
 * Bootstrap for unit tests that run without WordPress.
 *
 * @package easyQ
 */

// The plugin files exit early without this constant.
define( 'ABSPATH', __DIR__ . '/' );

/**
 * Stands in for the WordPress function of the same name, which unit tests do not load.
 *
 * @param string $text Text with HTML tags.
 * @return string
 */
function wp_strip_all_tags( string $text ): string {
	$text = (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $text );

	return trim( strip_tags( $text ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- This is the stand-in for wp_strip_all_tags().
}

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-autoloader.php';

EasyQ\Autoloader::register( 'EasyQ', dirname( __DIR__, 2 ) . '/includes' );
