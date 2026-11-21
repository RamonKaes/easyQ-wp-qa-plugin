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

	test( 'a notice whose request fails is dismissed by its form', async ( {
		browser,
		requestUtils,
	} ) => {
		const context = await browser.newContext( {
			baseURL: requestUtils.baseURL,
			storageState: { cookies: [], origins: [] },
		} );
		const page = await context.newPage();
		await page.goto( '/?easyq_route=questions' );
		// The request of the store fails: the form takes over (F-134 for the favorites).
		await page.route( /notices/, ( request ) =>
			request.request().method() === 'DELETE'
				? request.fulfill( { status: 500, body: 'Server error' } )
				: request.continue()
		);

		const sent = page.waitForEvent( 'framenavigated' );
		await page
			.getByRole( 'button', { name: 'Dismiss this notice' } )
			.click();
		await sent;
		await page.waitForLoadState();
		await page.unroute( /notices/ );

		await page.goto( '/?easyq_route=questions' );
		await expect(
			page.getByText( 'Welcome to our questions.' )
		).toHaveCount( 0 );
		await context.close();
	} );

	test( 'a double click dismisses the notice once, in place', async ( {
		browser,
		requestUtils,
	} ) => {
		const context = await browser.newContext( {
			baseURL: requestUtils.baseURL,
			storageState: { cookies: [], origins: [] },
		} );
		const page = await context.newPage();
		await page.goto( '/?easyq_route=questions' );
		let deletes = 0;
		let posts = 0;
		await page.route( '**/*', async ( request ) => {
			if ( request.request().method() === 'DELETE' ) {
				deletes++;
				await new Promise( ( resolve ) => setTimeout( resolve, 800 ) );
			} else if ( request.request().method() === 'POST' ) {
				posts++;
			}
			await request.continue();
		} );

		const button = page.getByRole( 'button', {
			name: 'Dismiss this notice',
		} );
		await button.click();
		await button.click( { force: true } );
		await expect(
			page.getByText( 'Welcome to our questions.' )
		).toBeHidden();
		await page.waitForTimeout( 1000 );
		await page.unroute( '**/*' );

		// One request, and no fallback to the form (F-166).
		expect( deletes ).toBe( 1 );
		expect( posts ).toBe( 0 );
		await context.close();
	} );
} );
