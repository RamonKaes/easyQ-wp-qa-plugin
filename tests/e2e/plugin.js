/**
 * Activates and deactivates easyQ in the end-to-end tests.
 *
 * The plugin is addressed by its file, `easyq/easyq`: the test utilities derive a plugin's
 * slug from its HTML-encoded name instead, which changes with the name.
 */

/**
 * Name of the plugin on the Plugins screen.
 */
const PLUGIN_NAME = 'easyQ Questions and Answers';

/**
 * Runs PHP in the test site through WP-CLI, for data no route of the REST API writes.
 *
 * @param {string} code PHP code.
 * @return {string} Output.
 */
const evalPhp = ( code ) =>
	require( 'child_process' ).spawnSync(
		'npx',
		[
			'wp-env',
			'run',
			'--config=.wp-env.test.json',
			'cli',
			'wp',
			'eval',
			code,
		],
		{ encoding: 'utf8' }
	).stdout;

/**
 * Sets the status of the plugin.
 *
 * @param {Object} requestUtils Request utilities of the test.
 * @param {string} status       `active` or `inactive`.
 */
const setStatus = ( requestUtils, status ) =>
	requestUtils.rest( {
		method: 'PUT',
		path: '/wp/v2/plugins/easyq/easyq',
		data: { status },
	} );

module.exports = {
	PLUGIN_NAME,
	evalPhp,
	activatePlugin: ( requestUtils ) => setStatus( requestUtils, 'active' ),
	deactivatePlugin: ( requestUtils ) => setStatus( requestUtils, 'inactive' ),
};
