<?php
/**
 * Plugin Name: easyQ end-to-end test limits
 * Description: Raises the easyQ rate limits on the end-to-end test site, where one administrator creates all test content. Loaded by wp-env for the tests environment only.
 *
 * @package easyQ
 */

// The PHP integration tests run on the same site and test the limits themselves.
if ( function_exists( 'tests_add_filter' ) ) {
	return;
}

add_filter(
	'option_easyq_settings_spam',
	static function ( $settings ) {
		if ( ! is_array( $settings ) ) {
			return $settings;
		}

		foreach ( array_keys( $settings ) as $key ) {
			if ( is_string( $key ) && str_starts_with( $key, 'max_rate_' ) ) {
				$settings[ $key ] = 1000;
			}
		}

		return $settings;
	}
);
