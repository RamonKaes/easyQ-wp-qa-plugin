<?php
/**
 * Plugin Name:       easyQ
 * Plugin URI:        https://easyq.info
 * Description:       A complete question and answer platform built from blocks, based on Question2Answer.
 * Version:           0.1.0
 * Requires at least: 6.7
 * Requires PHP:      8.4
 * Author:            easyQ
 * Author URI:        https://easyq.info
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       easyq
 * Domain Path:       /languages
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ;

defined( 'ABSPATH' ) || exit;

/**
 * Version of the plugin.
 *
 * @since 0.1.0
 *
 * @var string
 */
const VERSION = '0.1.0';

/**
 * Path of the main plugin file.
 *
 * @since 0.1.0
 *
 * @var string
 */
const PLUGIN_FILE = __FILE__;

/**
 * Directory of the plugin.
 *
 * @since 0.1.0
 *
 * @var string
 */
const PLUGIN_DIR = __DIR__;

require_once PLUGIN_DIR . '/includes/class-autoloader.php';

Autoloader::register( __NAMESPACE__, PLUGIN_DIR . '/includes' );

register_activation_hook( PLUGIN_FILE, array( Database\Installer::class, 'activate' ) );
register_deactivation_hook( PLUGIN_FILE, array( Database\Installer::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( new Plugin(), 'register' ) );
