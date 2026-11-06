/**
 * Subscribing to the newsletter and confirming it, and the settings tab that sends it.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { activatePlugin } = require( './plugin' );
const AxeBuilder = require( '@axe-core/playwright' ).default;

test.describe( 'Newsletter', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		await activatePlugin( requestUtils );
		await requestUtils.activateTheme( 'twentytwentyfive' );
		await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/settings',
			data: {
				easyq_settings_newsletter: {
					mailing_enabled: true,
					mailing_subject: 'News of the month',
					mailing_body: 'Here is what happened.',
				},
			},
		} );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.rest( {
			method: 'POST',
			path: '/easyq/v1/users/me',
			data: { newsletter: false },
		} );
		await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/settings',
			data: { easyq_settings_newsletter: { mailing_enabled: false } },
		} );
	} );

	test( 'users ask for it on their account page', async ( { page } ) => {
		await page.goto( '/?easyq_route=account' );
		await page.getByLabel( 'Send me the newsletter' ).check();
		await page.getByRole( 'button', { name: 'Save profile' } ).click();
		await expect(
			page.getByText( '(waiting for your confirmation by email)' )
		).toBeVisible();
	} );

	test( 'the confirmation link asks before it subscribes', async ( {
		page,
	} ) => {
		await page.goto(
			'/?easyq_route=unsubscribe&newsletter=confirm&user=1&key=wrong'
		);
		const results = await new AxeBuilder( { page } )
			.include( '[class*="wp-block-easyq-"]' )
			.analyze();
		expect( results.violations ).toEqual( [] );

		await page
			.getByRole( 'button', { name: 'Confirm the newsletter' } )
			.click();
		await expect(
			page.getByText( 'This link is not valid.' )
		).toBeVisible();
	} );

	test( 'the settings tab sends it', async ( { admin, page } ) => {
		await admin.visitAdminPage(
			'admin.php',
			'page=easyq-settings&tab=newsletter'
		);
		await page.getByRole( 'button', { name: 'Send a test to me' } ).click();
		await expect(
			page.getByText( 'The test email was sent to you.' )
		).toBeVisible();
		await page.getByRole( 'button', { name: 'Start sending' } ).click();
		// WP-Cron may send the few emails of the test site at once, so the button to cancel can be gone already.
		await expect(
			page.getByText( 'The newsletter is being sent.' )
		).toBeVisible();
	} );
} );
