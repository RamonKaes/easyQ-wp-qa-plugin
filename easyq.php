<?php
/**
 * Plugin Name:       easyQ
 * Plugin URI:        https://easyq.info
 * Description:       A complete question and answer platform built from blocks, based on Question2Answer.
 * Version:           0.1.0
 * Requires at least: 6.7
 * Requires PHP:      8.1
 * Author:            easyQ
 * Author URI:        https://easyq.info
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       easyq
 * Domain Path:       /languages
 *
 * @package easyQ
 */

namespace EasyQ;

defined( 'ABSPATH' ) || exit;

const VERSION     = '0.1.0';
const PLUGIN_FILE = __FILE__;
const PLUGIN_DIR  = __DIR__;

require_once PLUGIN_DIR . '/includes/class-autoloader.php';

Autoloader::register( __NAMESPACE__, PLUGIN_DIR . '/includes' );

register_activation_hook( PLUGIN_FILE, array( Database\Installer::class, 'activate' ) );
register_deactivation_hook( PLUGIN_FILE, array( Database\Installer::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( new Plugin(), 'register' ) );
