<?php
/**
 * Records the cookies the plugin sets; the command line sends no headers.
 *
 * Loaded by Cookies_IP_Test before any test runs, so the unqualified calls in
 * EasyQ\Util resolve to these functions.
 *
 * @package easyQ
 */

namespace EasyQ\Util;

/**
 * Records the cookies the plugin sets; the command line sends no headers.
 *
 * @param string               $name    Name.
 * @param string               $value   Value.
 * @param array<string, mixed> $options Options.
 * @return bool
 */
function setcookie( string $name, string $value = '', array $options = array() ): bool {
	$GLOBALS['easyq_test_cookies'][] = array(
		'name'  => $name,
		'value' => $value,
	) + $options; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	return true;
}

/**
 * Lets the plugin set cookies under the command line.
 *
 * @return bool
 */
function headers_sent(): bool {
	return false;
}
