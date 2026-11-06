/**
 * User walls and private messages in the browser.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { activatePlugin } = require( './plugin' );
const AxeBuilder = require( '@axe-core/playwright' ).default;

test.describe( 'Walls and private messages', () => {
	let other;

	test.beforeAll( async ( { requestUtils } ) => {
		await activatePlugin( requestUtils );
		await requestUtils.activateTheme( 'twentytwentyfive' );
		other = await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/users',
			data: {
				username: `pen${ Date.now() }`,
				email: `pen${ Date.now() }@example.org`,
				password: 'a-test-password',
			},
		} );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.rest( {
			method: 'DELETE',
			path: `/wp/v2/users/${ other.id }`,
			params: { force: true, reassign: 1 },
		} );
	} );

	test( 'posts on a wall and deletes the post', async ( { page } ) => {
		await page.goto(
			`/?easyq_route=user&easyq_arg=${ other.slug }&easyq_section=wall`
		);
		const text = `Hello from the wall ${ Date.now() }`;
		await page.getByLabel( 'Your post' ).fill( text );
		await page.getByRole( 'button', { name: 'Post on the wall' } ).click();
		await expect( page.getByText( text ) ).toBeVisible();

		const results = await new AxeBuilder( { page } )
			.include( '[class*="wp-block-easyq-"]' )
			.analyze();
		expect( results.violations ).toEqual( [] );

		await page
			.getByRole( 'listitem' )
			.filter( { hasText: text } )
			.getByRole( 'button', { name: 'Delete' } )
			.click();
		await expect( page.getByText( text ) ).toHaveCount( 0 );
	} );

	test( 'sends a private message and finds it among the sent ones', async ( {
		page,
	} ) => {
		await page.goto( `/?easyq_route=user&easyq_arg=${ other.slug }` );
		await page
			.getByRole( 'link', { name: 'Send a private message' } )
			.click();

		const text = `A private hello ${ Date.now() }`;
		await page.getByLabel( /^Message for/ ).fill( text );
		await page.getByRole( 'button', { name: 'Send' } ).click();
		await expect(
			page.getByText( 'Your message has been sent.' )
		).toBeVisible();
		await expect( page.getByText( text ) ).toBeVisible();

		await page.goto( '/?easyq_route=messages&box=sent' );
		await expect( page.getByText( text ) ).toBeVisible();
		const results = await new AxeBuilder( { page } )
			.include( '[class*="wp-block-easyq-"]' )
			.analyze();
		expect( results.violations ).toEqual( [] );
	} );
} );
