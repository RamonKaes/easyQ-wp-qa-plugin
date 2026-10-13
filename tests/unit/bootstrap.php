<?php
/**
 * Bootstrap for unit tests that run without WordPress.
 *
 * @package easyQ
 */

// The plugin files exit early without this constant.
define( 'ABSPATH', __DIR__ . '/' );

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-autoloader.php';

EasyQ\Autoloader::register( 'EasyQ', dirname( __DIR__, 2 ) . '/includes' );
