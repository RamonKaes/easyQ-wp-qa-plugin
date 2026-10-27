<?php
/**
 * Runs when the plugin is deleted.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ;

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-autoloader.php';

Autoloader::register( __NAMESPACE__, __DIR__ . '/includes' );

Uninstaller::run();
