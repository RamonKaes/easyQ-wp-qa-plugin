<?php
/**
 * Class autoloader following the WordPress file naming conventions.
 *
 * @package easyQ
 */

namespace easyQ;

defined( 'ABSPATH' ) || exit;

/**
 * Maps namespaced class names to files named after the WordPress conventions.
 *
 * `easyQ\Database\Schema_Manager` resolves to `includes/database/class-schema-manager.php`.
 * Interfaces, traits and enums use the `interface-`, `trait-` and `enum-` prefixes.
 */
final class Autoloader {

	/**
	 * File name prefixes tried for every symbol, in order.
	 *
	 * @var string[]
	 */
	private const FILE_PREFIXES = array( 'class-', 'interface-', 'trait-', 'enum-' );

	/**
	 * Root namespace without trailing separator.
	 *
	 * @var string
	 */
	private string $root_namespace;

	/**
	 * Base directory without trailing slash.
	 *
	 * @var string
	 */
	private string $base_dir;

	/**
	 * Sets up the autoloader.
	 *
	 * @param string $root_namespace Root namespace handled by this autoloader.
	 * @param string $base_dir       Directory that holds the root namespace.
	 */
	public function __construct( string $root_namespace, string $base_dir ) {
		$this->root_namespace = trim( $root_namespace, '\\' );
		$this->base_dir       = rtrim( $base_dir, '/\\' );
	}

	/**
	 * Creates an autoloader and registers it with PHP.
	 *
	 * @param string $root_namespace Root namespace handled by the autoloader.
	 * @param string $base_dir       Directory that holds the root namespace.
	 * @return self
	 */
	public static function register( string $root_namespace, string $base_dir ): self {
		$autoloader = new self( $root_namespace, $base_dir );
		spl_autoload_register( array( $autoloader, 'load' ) );

		return $autoloader;
	}

	/**
	 * Loads the file for a symbol if it belongs to the root namespace.
	 *
	 * @param string $symbol Fully qualified class, interface, trait or enum name.
	 */
	public function load( string $symbol ): void {
		foreach ( $this->candidate_files( $symbol ) as $file ) {
			if ( is_readable( $file ) ) {
				require_once $file;
				return;
			}
		}
	}

	/**
	 * Lists the files that may contain a symbol.
	 *
	 * @param string $symbol Fully qualified class, interface, trait or enum name.
	 * @return string[] Absolute file paths; empty if the symbol is outside the root namespace.
	 */
	public function candidate_files( string $symbol ): array {
		$symbol = ltrim( $symbol, '\\' );
		$prefix = $this->root_namespace . '\\';

		if ( 0 !== strncasecmp( $symbol, $prefix, strlen( $prefix ) ) ) {
			return array();
		}

		$parts = explode( '\\', substr( $symbol, strlen( $prefix ) ) );
		$name  = self::to_file_slug( (string) array_pop( $parts ) );
		$dir   = $this->base_dir;

		foreach ( $parts as $part ) {
			$dir .= '/' . self::to_file_slug( $part );
		}

		$files = array();
		foreach ( self::FILE_PREFIXES as $file_prefix ) {
			$files[] = $dir . '/' . $file_prefix . $name . '.php';
		}

		return $files;
	}

	/**
	 * Converts a namespace segment or class name into a file name slug.
	 *
	 * @param string $name Segment such as `Schema_Manager`.
	 * @return string Slug such as `schema-manager`.
	 */
	private static function to_file_slug( string $name ): string {
		return strtolower( str_replace( '_', '-', $name ) );
	}
}
