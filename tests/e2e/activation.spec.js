/**
 * Activation smoke test.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { PLUGIN_NAME, activatePlugin, deactivatePlugin } = require( './plugin' );

test.describe( 'Plugin activation', () => {
	test.afterAll( async ( { requestUtils } ) => {
		await activatePlugin( requestUtils );
	} );

	test( 'activates and deactivates from the Plugins screen without errors', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await deactivatePlugin( requestUtils );
		await admin.visitAdminPage( 'plugins.php' );

		await page
			.getByRole( 'link', {
				name: `Activate ${ PLUGIN_NAME }`,
				exact: true,
			} )
			.click();
		await expect( page.locator( '#message' ) ).toContainText(
			'Plugin activated.'
		);
		await expect( page.locator( 'body' ) ).not.toContainText(
			/Fatal error|Warning:|Notice:|Deprecated:/
		);

		await page
			.getByRole( 'link', {
				name: `Deactivate ${ PLUGIN_NAME }`,
				exact: true,
			} )
			.click();
		await expect( page.locator( '#message' ) ).toContainText(
			'Plugin deactivated.'
		);
		await expect( page.locator( 'body' ) ).not.toContainText(
			/Fatal error|Warning:|Notice:|Deprecated:/
		);
	} );
} );
