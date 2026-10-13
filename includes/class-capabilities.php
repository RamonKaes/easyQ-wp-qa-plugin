<?php
/**
 * Capabilities.
 *
 * @package easyQ
 */

namespace easyQ;

defined( 'ABSPATH' ) || exit;

/**
 * Maps the easyQ capabilities onto core capabilities.
 *
 * The plugin adds no roles and stores no capabilities in the database. Every easyQ
 * capability is a meta capability that resolves to core capabilities, so it
 * follows the site's roles and any role editor plugin automatically.
 */
final class Capabilities {

	/**
	 * Manage the plugin: settings, categories, points, tools.
	 *
	 * @var string
	 */
	public const MANAGE = 'easyq_manage';

	/**
	 * Moderate posts and users.
	 *
	 * @var string
	 */
	public const MODERATE = 'easyq_moderate';

	/**
	 * Hooks the capability mapping into WordPress.
	 */
	public function register(): void {
		add_filter( 'map_meta_cap', array( $this, 'map_meta_cap' ), 10, 2 );
	}

	/**
	 * Resolves easyQ capabilities to core capabilities.
	 *
	 * @param string[] $caps Primitive capabilities required so far.
	 * @param string   $cap  Capability being checked.
	 * @return string[]
	 */
	public function map_meta_cap( array $caps, string $cap ): array {
		return match ( $cap ) {
			self::MANAGE   => array( 'manage_options' ),
			// Replaced by the Q&A level system in phase 3.
			self::MODERATE => array( 'moderate_comments' ),
			default        => $caps,
		};
	}
}
