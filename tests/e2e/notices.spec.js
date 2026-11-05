/**
 * Notices for first-time visitors and new users.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { activatePlugin } = require( './plugin' );

test.describe( 'Notices', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		await activatePlugin( requestUtils );
		await requestUtils.activateTheme( 'twentytwentyfive' );
		await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/settings',
			data: {
				easyq_settings_users: {
					show_notice_visitor: true,
					notice_visitor:
						'Welcome to our <strong>questions</strong>.',
				},
			},
		} );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/settings',
			data: {
				easyq_settings_users: {
					show_notice_visitor: false,
					notice_visitor: '',
				},
			},
		} );
	} );

	for ( const javaScriptEnabled of [ true, false ] ) {
		test( `visitors dismiss their notice ${
			javaScriptEnabled ? 'in place' : 'without JavaScript'
		}`, async ( { browser, requestUtils } ) => {
			const context = await browser.newContext( {
				javaScriptEnabled,
				baseURL: requestUtils.baseURL,
				storageState: { cookies: [], origins: [] },
			} );
			const page = await context.newPage();
			await page.goto( '/?easyq_route=questions' );

			const notice = page.getByText( 'Welcome to our questions.' );
			await expect( notice ).toBeVisible();
			await page
				.getByRole( 'button', { name: 'Dismiss this notice' } )
				.click();
			await expect( notice ).toBeHidden();

			await page.goto( '/?easyq_route=questions' );
			await expect(
				page.getByText( 'Welcome to our questions.' )
			).toHaveCount( 0 );
			await context.close();
		} );
	}
} );
