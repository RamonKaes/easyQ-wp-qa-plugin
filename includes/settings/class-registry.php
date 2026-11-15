<?php
/**
 * Registration of the settings with WordPress.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Settings;

use EasyQ\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Registers each settings section with the Settings API, so it is sanitized on
 * every save and available through the core settings REST endpoint.
 *
 * @since 0.1.0
 */
final class Registry {

	/**
	 * Hooks the registration into WordPress.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_settings' ) );
		add_filter( 'rest_pre_get_setting', array( $this, 'rest_value' ), 10, 2 );
	}

	/**
	 * Returns the option group of a section, as used by the settings form.
	 *
	 * @since 0.1.0
	 *
	 * @param string $section Section name.
	 * @return string
	 */
	public static function group( string $section ): string {
		return 'easyq_' . $section;
	}

	/**
	 * Registers the sections that have a screen.
	 *
	 * @since 0.1.0
	 */
	public function register_settings(): void {
		foreach ( array_keys( Fields::sections() ) as $section ) {
			$group = self::group( $section );

			register_setting(
				$group,
				Options::option_name( $section ),
				array(
					'type'         => 'object',
					'label'        => Fields::sections()[ $section ],
					'default'      => Options::defaults()[ $section ] ?? array(),
					'show_in_rest' => array( 'schema' => Fields::schema( $section ) ),
				)
			);
			// Not as `sanitize_callback`, which core hooks with the value alone: the section comes
			// from the name of the option, which the filter passes second.
			add_filter( 'sanitize_option_' . Options::option_name( $section ), array( self::class, 'sanitize' ), 10, 2 );

			// Users who manage easyQ may save its settings without managing the whole site.
			add_filter( 'option_page_capability_' . $group, array( self::class, 'capability' ) );
		}
	}

	/**
	 * Sanitizes a section of the settings when it is saved.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed  $value  Submitted value.
	 * @param string $option Option name.
	 * @return array<string, mixed>
	 */
	public static function sanitize( mixed $value, string $option ): array {
		return Fields::sanitize( substr( $option, strlen( Options::option_name( '' ) ) ), $value );
	}

	/**
	 * Returns the capability needed to save the settings.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public static function capability(): string {
		return Capabilities::MANAGE;
	}

	/**
	 * Returns the value of a section for the settings REST endpoint.
	 *
	 * The stored option also holds settings without a field, which the schema does
	 * not describe; without this, the endpoint would report the whole section as null.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed  $value Value so far; null to read the option.
	 * @param string $name  Setting name.
	 * @return mixed
	 */
	public function rest_value( mixed $value, string $name ): mixed {
		if ( null !== $value || ! str_starts_with( $name, Options::OPTION_PREFIX ) ) {
			return $value;
		}

		$section = substr( $name, strlen( Options::OPTION_PREFIX ) );
		if ( ! isset( Fields::sections()[ $section ] ) ) {
			return $value;
		}

		return array_intersect_key( Options::get_section( $section ), Fields::section( $section ) );
	}
}
