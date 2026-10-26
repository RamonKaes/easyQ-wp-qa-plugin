/**
 * Answering and loading comments without reloading the question page.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

const questionUrl = ( id ) => `/?easyq_route=question&easyq_arg=${ id }`;

test.describe( 'Question page in place', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		// The PHP integration tests reinstall the test site, which deactivates the plugin.
		await requestUtils.activatePlugin( 'easyq' );
		await requestUtils.activateTheme( 'twentytwentyfive' );
	} );

	const ask = ( requestUtils, title ) =>
		requestUtils.rest( {
			method: 'POST',
			path: '/easyq/v1/questions',
			data: { title: `${ title } ${ Date.now() }`, content: 'Body.' },
		} );

	test( 'publishes an answer without reloading the page', async ( {
		page,
		requestUtils,
	} ) => {
		const question = await ask( requestUtils, 'Answered in place' );
		await page.goto( questionUrl( question.id ) );
		await page.evaluate( () => ( window.easyqNoReload = true ) );

		const text = 'This answer was sent in the background.';
		await page.getByLabel( 'Your answer' ).fill( text );
		await page.getByRole( 'button', { name: 'Answer' } ).click();

		await expect(
			page.locator( '[data-wp-router-region="easyq/notices"]' )
		).toHaveText( 'Your answer has been published.' );
		// Announced to screen readers, too.
		await expect( page.locator( '#a11y-speak-polite' ) ).toContainText(
			'Your answer has been published.'
		);
		await expect(
			page.locator( 'article' ).getByText( text )
		).toBeVisible();
		await expect( page.getByLabel( 'Your answer' ) ).toHaveValue( '' );
		expect( await page.evaluate( () => window.easyqNoReload ) ).toBe(
			true
		);
	} );

	test( 'shows errors next to the field without reloading the page', async ( {
		page,
		requestUtils,
	} ) => {
		const question = await ask( requestUtils, 'Too short answer' );
		await page.goto( questionUrl( question.id ) );
		await page.evaluate( () => ( window.easyqNoReload = true ) );

		await page.getByLabel( 'Your answer' ).fill( 'x' );
		await page.getByRole( 'button', { name: 'Answer' } ).click();

		const field = page.getByLabel( 'Your answer' );
		await expect( field ).toHaveAttribute( 'aria-invalid', 'true' );
		await expect( field ).toBeFocused();
		await expect( field ).toHaveValue( 'x' );
		expect( await page.evaluate( () => window.easyqNoReload ) ).toBe(
			true
		);
	} );

	test( 'shows the latest comments and loads the earlier ones, with and without JavaScript', async ( {
		browser,
		page,
		requestUtils,
	} ) => {
		const question = await ask( requestUtils, 'Long discussion' );
		// Q2A allows comments on answers, not on questions, by default.
		const answer = await requestUtils.rest( {
			method: 'POST',
			path: `/easyq/v1/questions/${ question.id }/answers`,
			data: { content: 'An answer that gets many comments.' },
		} );
		for ( let i = 1; i <= 11; i++ ) {
			await requestUtils.rest( {
				method: 'POST',
				path: `/easyq/v1/posts/${ answer.id }/comments`,
				data: { content: `Comment number ${ i } of the discussion.` },
			} );
		}

		await page.goto( questionUrl( question.id ) );
		const comments = page.locator( `#easyq-comments-${ answer.id } li` );
		await expect( comments ).toHaveCount( 5 );
		await expect(
			page.getByText( 'Comment number 11 of the discussion.' )
		).toBeVisible();
		await expect(
			page.getByText( 'Comment number 1 of the discussion.' )
		).toHaveCount( 0 );

		await page.evaluate( () => ( window.easyqNoReload = true ) );
		await page
			.getByRole( 'link', { name: 'Show 6 previous comments' } )
			.click();

		await expect( comments ).toHaveCount( 11 );
		await expect(
			page.getByText( 'Comment number 1 of the discussion.' )
		).toBeVisible();
		expect( await page.evaluate( () => window.easyqNoReload ) ).toBe(
			true
		);

		const context = await browser.newContext( {
			javaScriptEnabled: false,
		} );
		const plain = await context.newPage();
		await plain.goto(
			`${ requestUtils.baseURL }${ questionUrl( question.id ) }`
		);
		await plain
			.getByRole( 'link', { name: 'Show 6 previous comments' } )
			.click();
		await expect(
			plain.locator( `#easyq-comments-${ answer.id } li` )
		).toHaveCount( 11 );
		await context.close();
	} );
} );
