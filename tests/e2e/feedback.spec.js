/**
 * The feedback form, for visitors and without JavaScript as well.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { activatePlugin } = require( './plugin' );
const AxeBuilder = require( '@axe-core/playwright' ).default;

test.describe( 'Feedback', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		await activatePlugin( requestUtils );
		await requestUtils.activateTheme( 'twentytwentyfive' );
	} );

	test( 'visitors send feedback', async ( { browser, requestUtils } ) => {
		const context = await browser.newContext( {
			baseURL: requestUtils.baseURL,
			storageState: { cookies: [], origins: [] },
		} );
		const page = await context.newPage();
		await page.goto( '/?easyq_route=feedback' );

		const results = await new AxeBuilder( { page } )
			.include( '[class*="wp-block-easyq-"]' )
			.analyze();
		expect( results.violations ).toEqual( [] );

		await page
			.getByLabel( /^Your feedback or suggestion/ )
			.fill( 'The search could be better.' );
		await page
			.getByLabel( 'Your email address (optional)' )
			.fill( 'not an address' );
		// The browser checks the address before sending, as the field is of the type email.
		await page.getByRole( 'button', { name: 'Send feedback' } ).click();
		expect(
			await page
				.getByLabel( 'Your email address (optional)' )
				.evaluate( ( field ) => field.validity.valid )
		).toBe( false );

		await page
			.getByLabel( 'Your email address (optional)' )
			.fill( 'alex@example.org' );
		await page.getByRole( 'button', { name: 'Send feedback' } ).click();
		await expect(
			page.getByText( 'Thank you for your feedback.' )
		).toBeVisible();
		await context.close();
	} );
} );
