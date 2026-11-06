<?php
/**
 * Plugin Name: easyQ end-to-end test mail
 * Description: Takes the emails of the end-to-end test site as sent, as the site cannot deliver mail. Loaded by wp-env for the tests environment only.
 *
 * @package easyQ
 */

// The PHP integration tests run on the same site and use the mock mailer of WordPress.
if ( function_exists( 'tests_add_filter' ) ) {
	return;
}

add_filter( 'pre_wp_mail', 'easyq_e2e_skip_mail' );

/**
 * Reports every email as sent without sending it.
 *
 * @return bool
 */
function easyq_e2e_skip_mail() {
	return true;
}
