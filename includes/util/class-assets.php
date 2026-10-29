<?php
/**
 * Built scripts.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the asset files that `wp-scripts build` writes next to each script.
 *
 * @since 0.1.0
 */
final class Assets {

	/**
	 * Returns the dependencies and version of a built script.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name Name of the entry, e.g. `editor`.
	 * @return array{dependencies: array<int, mixed>, version: string}
	 */
	public static function get( string $name ): array {
		$file  = \EasyQ\PLUGIN_DIR . '/build/' . $name . '.asset.php';
		$asset = is_readable( $file ) ? require $file : array();

		return array(
			'dependencies' => is_array( $asset ) && is_array( $asset['dependencies'] ?? null ) ? array_values( $asset['dependencies'] ) : array(),
			'version'      => is_array( $asset ) && is_string( $asset['version'] ?? null ) ? $asset['version'] : \EasyQ\VERSION,
		);
	}
}
