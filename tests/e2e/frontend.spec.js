/**
 * Q&A pages in the browser: asking, voting and accessibility.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const AxeBuilder = require( '@axe-core/playwright' ).default;

const route = ( name, arg ) =>
	`/?easyq_route=${ name }${ arg ? `&easyq_arg=${ arg }` : '' }`;

test.describe( 'Q&A frontend', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		// The PHP integration tests reinstall the test site, which deactivates the plugin.
		await requestUtils.activatePlugin( 'easyq' );
		await requestUtils.activateTheme( 'twentytwentyfive' );
	} );

	test( 'asks a question with the form', async ( { page } ) => {
		const title = `Asked in the browser ${ Date.now() }`;

		await page.goto( route( 'ask' ) );
		await page.getByLabel( 'Your question in one sentence' ).fill( title );
		await page
			.getByLabel( 'More information for the question' )
			.fill( 'This question was asked by an end-to-end test.' );
		await page.getByRole( 'button', { name: 'Ask the question' } ).click();

		await expect(
			page.getByRole( 'heading', { level: 1, name: title } )
		).toBeVisible();
		await expect(
			page.getByText( 'This question was asked by an end-to-end test.' )
		).toBeVisible();
	} );

	test( 'votes without reloading the page', async ( {
		browser,
		requestUtils,
	} ) => {
		const question = await requestUtils.rest( {
			method: 'POST',
			path: '/easyq/v1/questions',
			data: {
				title: `Question to vote on ${ Date.now() }`,
				content: 'Please vote.',
			},
		} );

		const username = `voter${ Date.now() }`;
		await requestUtils.createUser( {
			username,
			email: `${ username }@example.com`,
			password: 'password',
			roles: [ 'subscriber' ],
		} );

		const context = await browser.newContext( {
			storageState: { cookies: [], origins: [] },
		} );
		const page = await context.newPage();
		await page.goto( `${ requestUtils.baseURL }/wp-login.php` );
		await page.locator( '#user_login' ).fill( username );
		await page.locator( '#user_pass' ).fill( 'password' );
		await page.locator( '#wp-submit' ).click();
		await page.waitForURL( /wp-admin/ );

		await page.goto(
			`${ requestUtils.baseURL }${ route( 'question', question.id ) }`
		);
		const votes = page.locator( '.wp-block-easyq-post-votes' ).first();
		const up = votes.getByRole( 'button', { name: 'Vote up' } );

		await expect( up ).toHaveAttribute( 'aria-pressed', 'false' );
		await up.click();
		await expect(
			votes.locator( '[data-wp-text="context.net"]' )
		).toHaveText( '1' );
		await expect( up ).toHaveAttribute( 'aria-pressed', 'true' );

		await page.reload();
		await expect(
			page
				.locator( '.wp-block-easyq-post-votes' )
				.first()
				.locator( '[data-wp-text="context.net"]' )
		).toHaveText( '1' );

		await context.close();
	} );

	test( 'question pages have no accessibility violations', async ( {
		page,
		requestUtils,
	} ) => {
		const question = await requestUtils.rest( {
			method: 'POST',
			path: '/easyq/v1/questions',
			data: {
				title: `Accessible question ${ Date.now() }`,
				content: 'Checked with axe.',
				tags: [ 'a11y' ],
			},
		} );

		await page.goto( route( 'question', question.id ) );

		const results = await new AxeBuilder( { page } )
			.include( '.wp-block-easyq-question' )
			.analyze();

		expect( results.violations ).toEqual( [] );
	} );

	test( 'lists questions for visitors', async ( {
		browser,
		requestUtils,
	} ) => {
		const title = `Listed question ${ Date.now() }`;
		await requestUtils.rest( {
			method: 'POST',
			path: '/easyq/v1/questions',
			data: { title, content: 'Visible to everybody.' },
		} );

		const context = await browser.newContext( {
			storageState: { cookies: [], origins: [] },
		} );
		const page = await context.newPage();
		await page.goto( `${ requestUtils.baseURL }${ route( 'home' ) }` );

		await expect(
			page.getByRole( 'heading', { name: 'Recent questions' } )
		).toBeVisible();
		await expect( page.getByRole( 'link', { name: title } ) ).toBeVisible();

		await context.close();
	} );
} );
