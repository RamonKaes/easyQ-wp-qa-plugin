<?php
/**
 * Help sidebar of the screens of easyQ.
 *
 * @package easyQ
 */

namespace EasyQ\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Gives every screen of easyQ the help sidebar core gives every screen with help tabs: where
 * to read more and where to ask (e.g. `wp-admin/edit-comments.php`).
 *
 * @since 0.1.0
 */
final class Help {

	/**
	 * Address of the plugin, as in its header (`Plugin URI`).
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const PLUGIN_URI = 'https://github.com/RamonKaes/easyQ-wp-qa-plugin';

	/**
	 * Sets the help sidebar of the current screen.
	 *
	 * @since 0.1.0
	 */
	public static function sidebar(): void {
		get_current_screen()?->set_help_sidebar(
			'<p><strong>' . esc_html__( 'For more information:', 'easyq' ) . '</strong></p>'
			. '<p><a href="' . esc_url( self::PLUGIN_URI . '#readme' ) . '">' . esc_html__( 'Documentation of easyQ', 'easyq' ) . '</a></p>'
			. '<p><a href="' . esc_url( self::PLUGIN_URI . '/issues' ) . '">' . esc_html__( 'Questions and problems', 'easyq' ) . '</a></p>'
		);
	}
}
