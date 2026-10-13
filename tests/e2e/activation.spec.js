/**
 * Activation smoke test.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

test.describe( 'Plugin activation', () => {
	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.activatePlugin( 'easyq' );
	} );

	test( 'activates and deactivates from the Plugins screen without errors', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await requestUtils.deactivatePlugin( 'easyq' );
		await admin.visitAdminPage( 'plugins.php' );

		await page
			.getByRole( 'link', { name: 'Activate easyQ', exact: true } )
			.click();
		await expect( page.locator( '#message' ) ).toContainText(
			'Plugin activated.'
		);
		await expect( page.locator( 'body' ) ).not.toContainText(
			/Fatal error|Warning:|Notice:|Deprecated:/
		);

		await page
			.getByRole( 'link', { name: 'Deactivate easyQ', exact: true } )
			.click();
		await expect( page.locator( '#message' ) ).toContainText(
			'Plugin deactivated.'
		);
		await expect( page.locator( 'body' ) ).not.toContainText(
			/Fatal error|Warning:|Notice:|Deprecated:/
		);
	} );
} );
