<?php
/**
 * Plugin settings storage.
 *
 * @package easyQ
 */

namespace EasyQ\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Stores settings grouped by section, one option per section.
 *
 * Stored values are merged with the defaults, so new settings work without an
 * upgrade routine. Unknown stored keys are ignored.
 */
final class Options {

	/**
	 * Prefix of the option names; the section name is appended.
	 *
	 * @var string
	 */
	public const OPTION_PREFIX = 'easyq_settings_';

	/**
	 * Returns the default values of all sections.
	 *
	 * @return array<string, array<string, scalar>>
	 */
	public static function defaults(): array {
		$defaults = array(
			'general' => array(
				'base_slug'                => 'questions',
				'tags_or_categories'       => 'tc',
				'read_only'                => false,
				'delete_data_on_uninstall' => false,
			),
		);

		/**
		 * Filters the default settings.
		 *
		 * @param array<string, array<string, scalar>> $defaults Default values keyed by section and setting.
		 */
		return (array) apply_filters( 'easyq_default_settings', $defaults );
	}

	/**
	 * Returns the names of all sections.
	 *
	 * @return string[]
	 */
	public static function sections(): array {
		return array_keys( self::defaults() );
	}

	/**
	 * Returns the option name of a section.
	 *
	 * @param string $section Section name.
	 * @return string
	 */
	public static function option_name( string $section ): string {
		return self::OPTION_PREFIX . $section;
	}

	/**
	 * Returns all settings of a section, merged with the defaults.
	 *
	 * @param string $section Section name.
	 * @return array<string, mixed>
	 */
	public static function get_section( string $section ): array {
		$values = self::defaults()[ $section ] ?? array();
		$stored = get_option( self::option_name( $section ), array() );

		if ( is_array( $stored ) ) {
			foreach ( array_keys( $values ) as $key ) {
				if ( array_key_exists( $key, $stored ) ) {
					$values[ $key ] = $stored[ $key ];
				}
			}
		}

		return $values;
	}

	/**
	 * Returns a single setting.
	 *
	 * @param string $section Section name.
	 * @param string $key     Setting name.
	 * @return mixed The value, or null if the setting does not exist.
	 */
	public static function get( string $section, string $key ): mixed {
		return self::get_section( $section )[ $key ] ?? null;
	}

	/**
	 * Adds the options of all sections with their defaults; existing values are kept.
	 */
	public static function install(): void {
		foreach ( self::defaults() as $section => $values ) {
			add_option( self::option_name( $section ), $values, '', true );
		}
	}

	/**
	 * Deletes the options of all sections.
	 */
	public static function delete_all(): void {
		foreach ( self::sections() as $section ) {
			delete_option( self::option_name( $section ) );
		}
	}
}
