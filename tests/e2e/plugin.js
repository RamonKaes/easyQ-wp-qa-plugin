/**
 * Activates and deactivates easyQ in the end-to-end tests.
 *
 * The plugin is addressed by its file, `easyq/easyq`: the test utilities derive a plugin's
 * slug from its HTML-encoded name instead, which changes with the name.
 */

/**
 * Name of the plugin on the Plugins screen.
 */
const PLUGIN_NAME = 'easyQ Q&A';

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
	activatePlugin: ( requestUtils ) => setStatus( requestUtils, 'active' ),
	deactivatePlugin: ( requestUtils ) => setStatus( requestUtils, 'inactive' ),
};
