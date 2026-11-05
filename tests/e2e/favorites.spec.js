/**
 * Adding favorites without reloading the page, and the favorites page.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { activatePlugin } = require( './plugin' );
const AxeBuilder = require( '@axe-core/playwright' ).default;

test.describe( 'Favorites', () => {
	let question;

	test.beforeAll( async ( { requestUtils } ) => {
		await activatePlugin( requestUtils );
		await requestUtils.activateTheme( 'twentytwentyfive' );
		question = await requestUtils.rest( {
			method: 'POST',
			path: '/easyq/v1/questions',
			data: {
				title: `Question to follow ${ Date.now() }`,
				content: 'A question to add to the favorites.',
			},
		} );
	} );

	test( 'adds a question in place and lists it', async ( { page } ) => {
		await page.goto( `/?easyq_route=question&easyq_arg=${ question.id }` );
		await page.evaluate( () => ( window.easyqNoReload = true ) );

		await page.getByRole( 'button', { name: 'Add to favorites' } ).click();
		const remove = page.getByRole( 'button', {
			name: 'Remove from favorites',
		} );
		await expect( remove ).toBeVisible();
		expect( await page.evaluate( () => window.easyqNoReload ) ).toBe(
			true
		);

		await page.goto( '/?easyq_route=favorites' );
		await expect(
			page.getByRole( 'link', { name: question.title.rendered } )
		).toBeVisible();
		const results = await new AxeBuilder( { page } )
			.include( '[class*="wp-block-easyq-"]' )
			.analyze();
		expect( results.violations ).toEqual( [] );

		await page.goto( `/?easyq_route=question&easyq_arg=${ question.id }` );
		await remove.click();
		await expect(
			page.getByRole( 'button', { name: 'Add to favorites' } )
		).toBeVisible();
	} );

	test( 'works without JavaScript', async ( { browser, requestUtils } ) => {
		const context = await browser.newContext( {
			javaScriptEnabled: false,
			storageState: requestUtils.storageStatePath,
			baseURL: requestUtils.baseURL,
		} );
		const page = await context.newPage();
		await page.goto( `/?easyq_route=question&easyq_arg=${ question.id }` );
		await page.getByRole( 'button', { name: 'Add to favorites' } ).click();
		await expect(
			page.getByRole( 'button', { name: 'Remove from favorites' } )
		).toBeVisible();
		await page
			.getByRole( 'button', { name: 'Remove from favorites' } )
			.click();
		await expect(
			page.getByRole( 'button', { name: 'Add to favorites' } )
		).toBeVisible();
		await context.close();
	} );

	test( 'tells of updates to favorites', async ( { page, requestUtils } ) => {
		const followed = await requestUtils.rest( {
			method: 'POST',
			path: '/easyq/v1/questions',
			data: {
				title: `Question with updates ${ Date.now() }`,
				content: 'A question that gets an answer.',
			},
		} );
		await requestUtils.rest( {
			method: 'POST',
			path: '/easyq/v1/favorites',
			data: { type: 'question', id: followed.id },
		} );
		await requestUtils.rest( {
			method: 'POST',
			path: `/easyq/v1/questions/${ followed.id }/answers`,
			data: { content: 'An answer that the updates page shows.' },
		} );

		await page.goto( '/?easyq_route=updates' );
		await expect(
			page.getByRole( 'link', { name: followed.title.rendered } )
		).toBeVisible();
		await page
			.getByRole( 'link', { name: 'My favorites', exact: true } )
			.first()
			.click();
		await expect(
			page.getByRole( 'link', { name: followed.title.rendered } )
		).toBeVisible();
		const results = await new AxeBuilder( { page } )
			.include( '[class*="wp-block-easyq-"]' )
			.analyze();
		expect( results.violations ).toEqual( [] );
	} );

	test( 'sends visitors to the login form', async ( {
		browser,
		requestUtils,
	} ) => {
		const context = await browser.newContext( {
			baseURL: requestUtils.baseURL,
			storageState: { cookies: [], origins: [] },
		} );
		const page = await context.newPage();
		await page.goto( '/?easyq_route=favorites' );
		await expect( page ).toHaveURL( /wp-login\.php/ );
		await context.close();
	} );
} );
