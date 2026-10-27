/**
 * Users page, profile pages and the account page in the browser.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { activatePlugin } = require( './plugin' );
const AxeBuilder = require( '@axe-core/playwright' ).default;

const route = ( name, arg, section ) =>
	`/?easyq_route=${ name }${ arg ? `&easyq_arg=${ arg }` : '' }${
		section ? `&easyq_section=${ section }` : ''
	}`;

test.describe( 'Q&A users, profiles and account', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		// The PHP integration tests reinstall the test site, which deactivates the plugin.
		await activatePlugin( requestUtils );
		await requestUtils.activateTheme( 'twentytwentyfive' );
	} );

	test( 'saves profile fields on the account page and shows them on the profile', async ( {
		page,
	} ) => {
		const location = `Hamburg ${ Date.now() }`;

		await page.goto( route( 'account' ) );
		await page.getByLabel( /location/i ).fill( location );
		await page.getByRole( 'button', { name: 'Save profile' } ).click();

		await expect(
			page.getByText( 'Your profile has been saved.' )
		).toBeVisible();
		await expect( page.getByLabel( /location/i ) ).toHaveValue( location );

		await page.goto( route( 'user', 'admin' ) );
		await expect( page.getByText( location ) ).toBeVisible();
	} );

	test( 'asks visitors to log in on the account page', async ( {
		browser,
		requestUtils,
	} ) => {
		const context = await browser.newContext( {
			storageState: { cookies: [], origins: [] },
		} );
		const page = await context.newPage();

		await page.goto( `${ requestUtils.baseURL }${ route( 'account' ) }` );

		await expect(
			page.getByRole( 'button', { name: 'Save profile' } )
		).toHaveCount( 0 );
		await expect(
			page.locator( 'main' ).getByRole( 'link', { name: 'Log in' } )
		).toBeVisible();

		await context.close();
	} );

	test( 'switches between the sections of a profile', async ( { page } ) => {
		await page.goto( route( 'user', 'admin' ) );

		const navigation = page.getByRole( 'navigation', {
			name: /^Profile of/,
		} );
		await expect(
			navigation.getByRole( 'link', { name: 'Profile' } )
		).toHaveAttribute( 'aria-current', 'page' );

		await navigation.getByRole( 'link', { name: /^Answers/ } ).click();
		await expect(
			navigation.getByRole( 'link', { name: /^Answers/ } )
		).toHaveAttribute( 'aria-current', 'page' );
	} );

	test( 'lists users and links to their profiles', async ( {
		page,
		requestUtils,
	} ) => {
		// Top users are those who took part, as in Question2Answer.
		await requestUtils.rest( {
			method: 'POST',
			path: '/easyq/v1/questions',
			data: {
				title: `Question for the users list ${ Date.now() }`,
				content: 'Makes the author a top user.',
			},
		} );

		await page.goto( route( 'users' ) );

		const list = page.locator( '.wp-block-easyq-user-template' );
		await expect( list ).toBeVisible();
		await list.getByRole( 'link' ).first().click();

		await expect(
			page.getByRole( 'navigation', { name: /^Profile of/ } )
		).toBeVisible();
	} );

	test( 'has no accessibility violations on profile and account pages', async ( {
		page,
	} ) => {
		for ( const url of [
			route( 'users' ),
			route( 'user', 'admin' ),
			route( 'user', 'admin', 'activity' ),
			route( 'account' ),
		] ) {
			await page.goto( url );
			const results = await new AxeBuilder( { page } )
				.include( 'main' )
				.analyze();
			expect( results.violations, url ).toEqual( [] );
		}
	} );
} );
