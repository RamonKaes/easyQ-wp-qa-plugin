<?php
/**
 * Runs when the plugin is deleted.
 *
 * @package easyQ
 */

namespace easyQ;

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-autoloader.php';

Autoloader::register( __NAMESPACE__, __DIR__ . '/includes' );

Uninstaller::run();
